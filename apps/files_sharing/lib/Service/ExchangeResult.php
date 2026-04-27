<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Service;

/** Structured outcome of a single token-exchange POST. */
readonly class ExchangeResult {
	public function __construct(
		public ExchangeOutcome $outcome,
		public ?string $accessToken = null,
		public ?int $accessTokenExpires = null,
	) {
	}

	public static function success(string $accessToken, ?int $expiresAt): self {
		return new self(ExchangeOutcome::Success, $accessToken, $expiresAt);
	}

	public static function failure(ExchangeOutcome $outcome): self {
		return new self($outcome);
	}
}
