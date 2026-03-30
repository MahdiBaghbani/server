<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Service;

use OC\OCM\OCMSignatoryManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\Signature\ISignatureManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * OCM authorization-code token exchange with structured outcome classification.
 */
class TokenExchangeHelper {
	public function __construct(
		private readonly IOCMDiscoveryService $discoveryService,
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly ISignatureManager $signatureManager,
		private readonly OCMSignatoryManager $signatoryManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function exchange(string $remote, #[SensitiveParameter] string $shareToken): ExchangeResult {
		$remote = rtrim($remote, '/');

		try {
			$ocmProvider = $this->discoveryService->discover($remote);
		} catch (\Throwable $e) {
			$this->logger->debug('Discovery failed during token exchange', [
				'remote' => $remote,
				'exception' => $e,
			]);
			return ExchangeResult::failure(ExchangeOutcome::TransientFailure);
		}

		$tokenEndpoint = $ocmProvider->getTokenEndPoint();
		if ($tokenEndpoint === '') {
			$this->logger->debug('Remote lacks token endpoint', ['remote' => $remote]);
			return ExchangeResult::failure(ExchangeOutcome::DefinitiveNoCapability);
		}

		$client = $this->clientService->newClient();
		$clientId = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);

		$payload = [
			'grant_type' => 'authorization_code',
			'client_id' => $clientId,
			'code' => $shareToken,
		];

		$options = [
			'body' => http_build_query($payload),
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'timeout' => 10,
			'connect_timeout' => 10,
		];

		try {
			$options = $this->signatureManager->signOutgoingRequestIClientPayload(
				$this->signatoryManager,
				$options,
				'post',
				$tokenEndpoint
			);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to sign token exchange request', [
				'remote' => $remote,
				'exception' => $e,
			]);
			return ExchangeResult::failure(ExchangeOutcome::TransientFailure);
		}

		try {
			$response = $client->post($tokenEndpoint, $options);
			return $this->classifyResponse($response, $remote);
		} catch (\Throwable $e) {
			return $this->classifyException($client, $e, $remote);
		}
	}

	private function classifyResponse(IResponse $response, string $remote): ExchangeResult {
		$statusCode = $response->getStatusCode();

		if ($statusCode >= 500) {
			$this->logger->warning('Token exchange got 5xx', [
				'remote' => $remote,
				'status' => $statusCode,
			]);
			return ExchangeResult::failure(ExchangeOutcome::TransientFailure);
		}

		if ($statusCode >= 400 && $statusCode < 500) {
			return $this->classifyErrorResponse($response, $remote);
		}

		if ($statusCode !== 200) {
			$this->logger->warning('Token exchange returned unexpected HTTP status', [
				'remote' => $remote,
				'status' => $statusCode,
			]);
			return ExchangeResult::failure(ExchangeOutcome::TransientFailure);
		}

		return $this->parseSuccessResponse($response, $remote);
	}

	private function classifyErrorResponse(IResponse $response, string $remote): ExchangeResult {
		$body = $response->getBody();
		$data = json_decode($body, true);

		if (!is_array($data) || !isset($data['error'])) {
			$this->logger->warning('Token exchange 4xx with unparseable or missing error field', [
				'remote' => $remote,
				'status' => $response->getStatusCode(),
			]);
			return ExchangeResult::failure(ExchangeOutcome::TransientFailure);
		}

		$error = $data['error'];

		if ($error === 'invalid_grant') {
			$this->logger->debug('Token exchange: invalid_grant', ['remote' => $remote]);
			return ExchangeResult::failure(ExchangeOutcome::DefinitiveInvalidGrant);
		}

		if ($error === 'invalid_request') {
			$this->logger->debug('Token exchange: invalid_request', ['remote' => $remote]);
			return ExchangeResult::failure(ExchangeOutcome::ExplicitInvalidRequest);
		}

		$this->logger->warning('Token exchange 4xx with unrecognized error value', [
			'remote' => $remote,
			'error' => $error,
			'status' => $response->getStatusCode(),
		]);
		return ExchangeResult::failure(ExchangeOutcome::TransientFailure);
	}

	private function parseSuccessResponse(IResponse $response, string $remote): ExchangeResult {
		$data = json_decode($response->getBody(), true);

		if (!is_array($data)) {
			$this->logger->warning('Token exchange response is not valid JSON', ['remote' => $remote]);
			return ExchangeResult::failure(ExchangeOutcome::MalformedResponse);
		}

		$accessToken = $data['access_token'] ?? null;
		$tokenType = $data['token_type'] ?? null;

		if (!is_string($accessToken) || $accessToken === '') {
			$this->logger->warning('Token exchange response missing or invalid access_token', ['remote' => $remote]);
			return ExchangeResult::failure(ExchangeOutcome::MalformedResponse);
		}

		if (!is_string($tokenType) || strtolower($tokenType) !== 'bearer') {
			$this->logger->warning('Token exchange response has unexpected token_type', [
				'remote' => $remote,
				'token_type' => $tokenType,
			]);
			return ExchangeResult::failure(ExchangeOutcome::MalformedResponse);
		}

		$expiresIn = $data['expires_in'] ?? null;
		$expiresAt = null;
		if (is_numeric($expiresIn) && (int)$expiresIn > 0) {
			$expiresAt = time() + (int)$expiresIn;
		}

		$this->logger->debug('Token exchange succeeded', [
			'remote' => $remote,
			'has_expiry' => $expiresAt !== null,
		]);

		return ExchangeResult::success($accessToken, $expiresAt);
	}

	/**
	 * Guzzle ClientException/ServerException carry an HTTP response; extract
	 * it and classify. Pure connect/TLS/timeout errors have no response and
	 * are classified as transient.
	 */
	private function classifyException(
		\OCP\Http\Client\IClient $client,
		\Throwable $e,
		string $remote,
	): ExchangeResult {
		try {
			$response = $client->getResponseFromThrowable($e);
			return $this->classifyResponse($response, $remote);
		} catch (\Throwable) {
			$this->logger->debug('Token exchange failed with non-HTTP error', [
				'remote' => $remote,
				'exception' => $e,
			]);
			return ExchangeResult::failure(ExchangeOutcome::TransientFailure);
		}
	}
}
