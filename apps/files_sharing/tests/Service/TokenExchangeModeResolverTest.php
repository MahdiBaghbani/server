<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Files_Sharing\Tests\Service;

use OCA\Files_Sharing\External\ExternalShare;
use OCA\Files_Sharing\External\ExternalShareMapper;
use OCA\Files_Sharing\Service\ExchangeOutcome;
use OCA\Files_Sharing\Service\ExchangeResult;
use OCA\Files_Sharing\Service\TokenExchangeHelper;
use OCA\Files_Sharing\Service\TokenExchangeMode;
use OCA\Files_Sharing\Service\TokenExchangeModeResolver;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class TokenExchangeModeResolverTest extends TestCase {
	private TokenExchangeHelper&MockObject $exchangeHelper;
	private ExternalShareMapper&MockObject $mapper;
	private IOCMDiscoveryService&MockObject $discoveryService;
	private LoggerInterface&MockObject $logger;
	private TokenExchangeModeResolver $resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->exchangeHelper = $this->createMock(TokenExchangeHelper::class);
		$this->mapper = $this->createMock(ExternalShareMapper::class);
		$this->discoveryService = $this->createMock(IOCMDiscoveryService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->resolver = new TokenExchangeModeResolver(
			$this->exchangeHelper,
			$this->mapper,
			$this->discoveryService,
			$this->logger,
		);
	}

	private function createShare(
		?string $mode = null,
		?string $accessToken = null,
		?int $accessTokenExpires = null,
		string $shareToken = 'share-tok',
		string $remote = 'https://remote.example',
	): ExternalShare {
		$share = new ExternalShare();
		$share->setTokenExchangeMode($mode);
		if ($accessToken !== null) {
			$share->setAccessToken($accessToken);
		}
		if ($accessTokenExpires !== null) {
			$share->setAccessTokenExpires($accessTokenExpires);
		}
		$share->setShareToken($shareToken);
		$share->setRemote($remote);
		return $share;
	}

	private function mockDiscoveryWithEndpoint(string $endpoint = 'https://remote.example/token'): void {
		$provider = $this->createMock(IOCMProvider::class);
		$provider->method('getTokenEndPoint')->willReturn($endpoint);
		$this->discoveryService->method('discover')->willReturn($provider);
	}

	public function testNonNullModeReturnsNoop(): void {
		$share = $this->createShare(TokenExchangeMode::LEGACY);

		$this->mapper->expects($this->never())->method($this->anything());
		$this->exchangeHelper->expects($this->never())->method('exchange');

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame(ExchangeOutcome::Success, $result->outcome);
		$this->assertNull($result->resolvedMode);
	}

	public function testNullRowWithStoredBearerResolvesWithoutProbe(): void {
		$expiresAt = time() + 3600;
		$share = $this->createShare(null, 'stored-bearer-token', $expiresAt);

		$this->exchangeHelper->expects($this->never())->method('exchange');
		$this->discoveryService->expects($this->never())->method('discover');

		$this->mapper->expects($this->once())
			->method('updateModeAndAccessTokenByShareToken')
			->with(
				'share-tok',
				TokenExchangeMode::EXCHANGE_OPTIONAL,
				'stored-bearer-token',
				$expiresAt,
			);

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame(ExchangeOutcome::Success, $result->outcome);
		$this->assertSame(TokenExchangeMode::EXCHANGE_OPTIONAL, $result->resolvedMode);
		$this->assertSame('stored-bearer-token', $result->accessToken);
		$this->assertSame($expiresAt, $result->accessTokenExpires);
	}

	public function testDiscoveryLacksTokenEndpointPersistsLegacy(): void {
		$share = $this->createShare();
		$this->mockDiscoveryWithEndpoint('');

		$this->mapper->expects($this->once())
			->method('updateModeByShareToken')
			->with('share-tok', TokenExchangeMode::LEGACY);

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame(ExchangeOutcome::DefinitiveNoCapability, $result->outcome);
		$this->assertSame(TokenExchangeMode::LEGACY, $result->resolvedMode);
	}

	public function testProbeSuccessPersistsExchangeOptionalWithBearer(): void {
		$share = $this->createShare();
		$this->mockDiscoveryWithEndpoint();

		$expiresAt = time() + 3600;
		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('new-bearer', $expiresAt));

		$this->mapper->expects($this->once())
			->method('updateModeAndAccessTokenByShareToken')
			->with('share-tok', TokenExchangeMode::EXCHANGE_OPTIONAL, 'new-bearer', $expiresAt);

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame(ExchangeOutcome::Success, $result->outcome);
		$this->assertSame(TokenExchangeMode::EXCHANGE_OPTIONAL, $result->resolvedMode);
		$this->assertSame('new-bearer', $result->accessToken);
	}

	public function testDefinitiveInvalidGrantPersistsLegacy(): void {
		$share = $this->createShare();
		$this->mockDiscoveryWithEndpoint();

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure(ExchangeOutcome::DefinitiveInvalidGrant));

		$this->mapper->expects($this->once())
			->method('updateModeByShareToken')
			->with('share-tok', TokenExchangeMode::LEGACY);

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame(ExchangeOutcome::DefinitiveInvalidGrant, $result->outcome);
		$this->assertSame(TokenExchangeMode::LEGACY, $result->resolvedMode);
	}

	public static function nonDefinitiveOutcomeProvider(): array {
		return [
			'explicit-invalid-request' => [ExchangeOutcome::ExplicitInvalidRequest],
			'malformed-response' => [ExchangeOutcome::MalformedResponse],
			'transient-failure' => [ExchangeOutcome::TransientFailure],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider(methodName: 'nonDefinitiveOutcomeProvider')]
	public function testNonDefinitiveOutcomeLeavesNullNoPersist(ExchangeOutcome $outcome): void {
		$share = $this->createShare();
		$this->mockDiscoveryWithEndpoint();

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure($outcome));

		$this->mapper->expects($this->never())->method('updateModeByShareToken');
		$this->mapper->expects($this->never())->method('updateModeAndClearBearerByShareToken');
		$this->mapper->expects($this->never())->method('updateModeAndAccessTokenByShareToken');

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame($outcome, $result->outcome);
		$this->assertNull($result->resolvedMode);
	}

	public function testDiscoveryTransientFailureLeavesNull(): void {
		$share = $this->createShare();

		$this->discoveryService->method('discover')
			->willThrowException(new \RuntimeException('discovery down'));

		$this->mapper->expects($this->never())->method($this->anything());
		$this->exchangeHelper->expects($this->never())->method('exchange');

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame(ExchangeOutcome::TransientFailure, $result->outcome);
		$this->assertNull($result->resolvedMode);
	}

	public function testNeverPersistsExchangeRequired(): void {
		$share = $this->createShare();
		$this->mockDiscoveryWithEndpoint();

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('bearer', time() + 3600));

		$this->mapper->expects($this->once())
			->method('updateModeAndAccessTokenByShareToken')
			->with(
				'share-tok',
				$this->logicalNot($this->equalTo(TokenExchangeMode::EXCHANGE_REQUIRED)),
				$this->anything(),
				$this->anything(),
			);

		$this->resolver->ensureModeResolved($share);
	}

	public function testExchangeOptionalModeIsUsedForAllLegitimateResolvedRows(): void {
		$share = $this->createShare();
		$this->mockDiscoveryWithEndpoint();

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('bearer', time() + 3600));

		$this->mapper->expects($this->once())
			->method('updateModeAndAccessTokenByShareToken')
			->with('share-tok', TokenExchangeMode::EXCHANGE_OPTIONAL, $this->anything(), $this->anything());

		$result = $this->resolver->ensureModeResolved($share);

		$this->assertSame(TokenExchangeMode::EXCHANGE_OPTIONAL, $result->resolvedMode);
	}
}
