<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Service;

/**
 * Allowed values for the token_exchange_mode column on share_external.
 *
 * NULL means the row predates the column and has not been lazily resolved yet.
 * New rows created by receiver-side code must always persist one of the three
 * named constants below; they must not intentionally store NULL.
 */
final class TokenExchangeMode {
	public const string LEGACY = 'legacy';
	public const string EXCHANGE_OPTIONAL = 'exchange-optional';
	public const string EXCHANGE_REQUIRED = 'exchange-required';
}
