<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\Attributes\ColumnType;
use OCP\Migration\Attributes\ModifyColumn;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

#[ModifyColumn(table: 'share_external', name: 'access_token', type: ColumnType::STRING, description: 'Increase access_token length to fit OCM bearer tokens')]
class Version33000Date20260323143000 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('share_external')) {
			return null;
		}

		$table = $schema->getTable('share_external');
		if (!$table->hasColumn('access_token')) {
			return null;
		}

		$column = $table->getColumn('access_token');
		if ($column->getLength() !== null && $column->getLength() < 4000) {
			$column->setLength(4000);
			return $schema;
		}

		return null;
	}
}
