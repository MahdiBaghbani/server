<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Service;

/** Outcome of TokenExchangeModeResolver::ensureModeResolved(). */
readonly class ResolutionResult {
	public function __construct(
		public ExchangeOutcome $outcome,
		public ?string $resolvedMode = null,
		public ?string $accessToken = null,
		public ?int $accessTokenExpires = null,
	) {
	}

	public static function noop(): self {
		return new self(ExchangeOutcome::Success);
	}
}
