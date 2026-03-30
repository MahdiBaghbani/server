<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Files_Sharing\Tests\Service;

use OC\OCM\OCMSignatoryManager;
use OCA\Files_Sharing\Service\ExchangeOutcome;
use OCA\Files_Sharing\Service\TokenExchangeHelper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use OCP\Security\Signature\ISignatureManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class TokenExchangeHelperTest extends TestCase {
	private IOCMDiscoveryService&MockObject $discoveryService;
	private IClientService&MockObject $clientService;
	private IURLGenerator&MockObject $urlGenerator;
	private ISignatureManager&MockObject $signatureManager;
	private OCMSignatoryManager&MockObject $signatoryManager;
	private LoggerInterface&MockObject $logger;
	private IClient&MockObject $client;
	private TokenExchangeHelper $helper;

	protected function setUp(): void {
		parent::setUp();

		$this->discoveryService = $this->createMock(IOCMDiscoveryService::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->signatureManager = $this->createMock(ISignatureManager::class);
		$this->signatoryManager = $this->createMock(OCMSignatoryManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->client = $this->createMock(IClient::class);

		$this->clientService->method('newClient')->willReturn($this->client);
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://local.example/');
		$this->signatureManager->method('signOutgoingRequestIClientPayload')
			->willReturnArgument(1);

		$this->helper = new TokenExchangeHelper(
			$this->discoveryService,
			$this->clientService,
			$this->urlGenerator,
			$this->signatureManager,
			$this->signatoryManager,
			$this->logger,
		);
	}

	private function mockDiscovery(string $tokenEndpoint = 'https://remote.example/ocm/token'): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('getTokenEndPoint')->willReturn($tokenEndpoint);
		$this->discoveryService->method('discover')->willReturn($provider);
	}

	private function mockPostResponse(int $statusCode, string $body): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);
		$this->client->method('post')->willReturn($response);
	}

	public function testMissingTokenEndpointReturnsDefinitiveNoCapability(): void {
		$this->mockDiscovery('');

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::DefinitiveNoCapability, $result->outcome);
		$this->assertNull($result->accessToken);
	}

	public function testDiscoveryFailureReturnsTransientFailure(): void {
		$this->discoveryService->method('discover')
			->willThrowException(new \RuntimeException('discovery unavailable'));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
	}

	public function testSuccessfulExchangeReturnsAccessToken(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(200, json_encode([
			'access_token' => 'fresh-token',
			'token_type' => 'Bearer',
			'expires_in' => 3600,
		]));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::Success, $result->outcome);
		$this->assertSame('fresh-token', $result->accessToken);
		$this->assertNotNull($result->accessTokenExpires);
		$this->assertGreaterThan(time(), $result->accessTokenExpires);
	}

	public function testSuccessWithoutExpiryReturnsNullExpires(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(200, json_encode([
			'access_token' => 'token-no-expiry',
			'token_type' => 'Bearer',
		]));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::Success, $result->outcome);
		$this->assertSame('token-no-expiry', $result->accessToken);
		$this->assertNull($result->accessTokenExpires);
	}

	public function testInvalidGrantReturnsDefinitiveInvalidGrant(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(400, json_encode(['error' => 'invalid_grant']));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::DefinitiveInvalidGrant, $result->outcome);
	}

	public function testInvalidRequestReturnsExplicitInvalidRequest(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(400, json_encode(['error' => 'invalid_request']));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::ExplicitInvalidRequest, $result->outcome);
	}

	public function testUnrecognized4xxErrorReturnsTransientFailure(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(400, json_encode(['error' => 'server_error']));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
	}

	public function testNonJsonBody4xxReturnsTransientFailure(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(400, 'not json');

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
	}

	public function testMissingErrorField4xxReturnsTransientFailure(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(400, json_encode(['message' => 'something']));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
	}

	public function test5xxReturnsTransientFailure(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(500, 'internal error');

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
	}

	public function testMalformedSuccessResponseMissingAccessToken(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(200, json_encode([
			'token_type' => 'Bearer',
			'expires_in' => 3600,
		]));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::MalformedResponse, $result->outcome);
	}

	public function testMalformedSuccessResponseMissingTokenType(): void {
		$this->mockDiscovery();
		$this->mockPostResponse(200, json_encode([
			'access_token' => 'token-value',
			'expires_in' => 3600,
		]));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::MalformedResponse, $result->outcome);
	}

	public function testNonHttpThrowableReturnsTransientFailure(): void {
		$this->mockDiscovery();

		$this->client->method('post')
			->willThrowException(new \RuntimeException('connect failure'));
		$this->client->method('getResponseFromThrowable')
			->willThrowException(new \RuntimeException('no response'));

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
	}

	public function testExceptionWithRecoveredResponseClassifiesFromResponse(): void {
		$this->mockDiscovery();

		$this->client->method('post')
			->willThrowException(new \RuntimeException('request failed'));

		$recoveredResponse = $this->createMock(IResponse::class);
		$recoveredResponse->method('getStatusCode')->willReturn(400);
		$recoveredResponse->method('getBody')->willReturn(json_encode(['error' => 'invalid_grant']));

		$this->client->method('getResponseFromThrowable')
			->willReturn($recoveredResponse);

		$result = $this->helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::DefinitiveInvalidGrant, $result->outcome);
	}

	public function testRemoteUrlNormalizesTrailingSlash(): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('getTokenEndPoint')
			->willReturn('https://remote.example/ocm/token');

		$this->discoveryService->expects($this->once())
			->method('discover')
			->with('https://remote.example')
			->willReturn($provider);

		$this->mockPostResponse(200, json_encode([
			'access_token' => 'tok',
			'token_type' => 'Bearer',
		]));

		$this->helper->exchange('https://remote.example/', 'share-token');
	}

	public function testSigningFailureReturnsTransientFailure(): void {
		$this->mockDiscovery();

		$this->signatureManager = $this->createMock(ISignatureManager::class);
		$this->signatureManager->method('signOutgoingRequestIClientPayload')
			->willThrowException(new \RuntimeException('signing key unavailable'));

		$helper = new TokenExchangeHelper(
			$this->discoveryService,
			$this->clientService,
			$this->urlGenerator,
			$this->signatureManager,
			$this->signatoryManager,
			$this->logger,
		);

		$result = $helper->exchange('https://remote.example', 'share-token');

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
	}
}
