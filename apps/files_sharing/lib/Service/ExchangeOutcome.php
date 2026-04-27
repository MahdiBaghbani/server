<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Service;

enum ExchangeOutcome: string {
	case Success = 'success';
	case DefinitiveNoCapability = 'definitive-no-capability';
	case DefinitiveInvalidGrant = 'definitive-invalid-grant';
	case ExplicitInvalidRequest = 'explicit-invalid-request';
	case MalformedResponse = 'malformed-response';
	case TransientFailure = 'transient-failure';
}
