<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Service;

use OCA\Files_Sharing\External\ExternalShare;
use OCA\Files_Sharing\External\ExternalShareMapper;
use OCP\OCM\IOCMDiscoveryService;
use Psr\Log\LoggerInterface;

/**
 * Resolves NULL token_exchange_mode rows on first auth-relevant access.
 *
 * Never infers exchange-required; the lazy path only persists legacy or
 * exchange-optional when the evidence is definitive.
 */
class TokenExchangeModeResolver {
	public function __construct(
		private readonly TokenExchangeHelper $exchangeHelper,
		private readonly ExternalShareMapper $externalShareMapper,
		private readonly IOCMDiscoveryService $discoveryService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Non-null rows are a no-op. The caller loads the row beforehand.
	 */
	public function ensureModeResolved(ExternalShare $share): ResolutionResult {
		if ($share->getTokenExchangeMode() !== null) {
			return ResolutionResult::noop();
		}

		$shareToken = $share->getShareToken();
		$remote = rtrim($share->getRemote(), '/');

		$storedAccessToken = $share->getAccessToken();
		if ($storedAccessToken !== null && $storedAccessToken !== '') {
			$this->externalShareMapper->updateModeAndAccessTokenByShareToken(
				$shareToken,
				TokenExchangeMode::EXCHANGE_OPTIONAL,
				$storedAccessToken,
				$share->getAccessTokenExpires(),
			);
			$this->logger->debug('Resolved NULL row with stored bearer to exchange-optional', [
				'share_token_prefix' => substr($shareToken, 0, 8),
			]);
			return new ResolutionResult(
				ExchangeOutcome::Success,
				TokenExchangeMode::EXCHANGE_OPTIONAL,
				$storedAccessToken,
				$share->getAccessTokenExpires(),
			);
		}

		try {
			$ocmProvider = $this->discoveryService->discover($remote);
			$tokenEndpoint = $ocmProvider->getTokenEndPoint();
		} catch (\Throwable $e) {
			$this->logger->debug('Lazy resolver: discovery failed, leaving NULL', [
				'remote' => $remote,
				'exception' => $e,
			]);
			return new ResolutionResult(ExchangeOutcome::TransientFailure);
		}

		if ($tokenEndpoint === '') {
			$this->externalShareMapper->updateModeByShareToken(
				$shareToken,
				TokenExchangeMode::LEGACY,
			);
			$this->logger->debug('Resolved NULL row to legacy: no token endpoint', [
				'remote' => $remote,
			]);
			return new ResolutionResult(
				ExchangeOutcome::DefinitiveNoCapability,
				TokenExchangeMode::LEGACY,
			);
		}

		$result = $this->exchangeHelper->exchange($remote, $shareToken);

		if ($result->outcome === ExchangeOutcome::Success) {
			$this->externalShareMapper->updateModeAndAccessTokenByShareToken(
				$shareToken,
				TokenExchangeMode::EXCHANGE_OPTIONAL,
				$result->accessToken,
				$result->accessTokenExpires,
			);
			$this->logger->debug('Resolved NULL row to exchange-optional with bearer', [
				'remote' => $remote,
			]);
			return new ResolutionResult(
				ExchangeOutcome::Success,
				TokenExchangeMode::EXCHANGE_OPTIONAL,
				$result->accessToken,
				$result->accessTokenExpires,
			);
		}

		if ($result->outcome === ExchangeOutcome::DefinitiveInvalidGrant) {
			$this->externalShareMapper->updateModeByShareToken(
				$shareToken,
				TokenExchangeMode::LEGACY,
			);
			$this->logger->debug('Resolved NULL row to legacy: invalid_grant', [
				'remote' => $remote,
			]);
			return new ResolutionResult(
				ExchangeOutcome::DefinitiveInvalidGrant,
				TokenExchangeMode::LEGACY,
			);
		}

		// Non-definitive outcomes: do not persist, leave NULL for later retry.
		$this->logger->debug('Lazy resolver: leaving NULL after non-definitive outcome', [
			'remote' => $remote,
			'outcome' => $result->outcome->value,
		]);

		return new ResolutionResult($result->outcome);
	}
}
