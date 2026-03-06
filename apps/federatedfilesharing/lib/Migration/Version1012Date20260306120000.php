<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FederatedFileSharing\Migration;

use Closure;
use OC\Authentication\Token\IToken;
use OC\Authentication\Token\PublicKeyTokenProvider;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ISecureRandom;
use OCP\Server;
use OCP\Share\IShare;

/**
 * Ensure all existing federated share tokens are registered in oc_authtoken
 * as permanent tokens, which is required for the OCM token exchange flow.
 *
 * Shares created before this fork used TokenHandler (15-char tokens) and never
 * registered in oc_authtoken. Those tokens are replaced with new 32-char tokens.
 * Note: the remote's copy of a replaced token becomes stale; affected shares will
 * need to be re-created.
 *
 * Shares created by this fork (32-char tokens) that are somehow missing from
 * oc_authtoken are silently repaired.
 */
class Version1012Date20260306120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$db = Server::get(IDBConnection::class);
		$tokenProvider = Server::get(PublicKeyTokenProvider::class);
		$random = Server::get(ISecureRandom::class);
		$userManager = Server::get(IUserManager::class);

		$qb = $db->getQueryBuilder();
		$result = $qb->select('id', 'token', 'uid_initiator')
			->from('share')
			->where($qb->expr()->in(
				'share_type',
				$qb->createNamedParameter(
					[IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP],
					IQueryBuilder::PARAM_INT_ARRAY
				)
			))
			->executeQuery();

		$replaced = 0;
		$registered = 0;
		$skipped = 0;

		while ($row = $result->fetchAssociative()) {
			$shareId = (int)$row['id'];
			$token = (string)$row['token'];
			$uid = (string)$row['uid_initiator'];

			if (strlen($token) < PublicKeyTokenProvider::TOKEN_MIN_LENGTH) {
				// Old short token from TokenHandler — cannot register in oc_authtoken.
				// Generate a new 32-char token and update oc_share.
				$newToken = $random->generate(
					32,
					ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
				);

				$updateQb = $db->getQueryBuilder();
				$updateQb->update('share')
					->set('token', $updateQb->createNamedParameter($newToken))
					->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));
				$updateQb->executeStatement();

				$token = $newToken;
				$replaced++;
			} else {
				// Long token — check if it's already in oc_authtoken.
				try {
					$tokenProvider->getToken($token);
					$skipped++;
					continue;
				} catch (InvalidTokenException) {
					// Not registered yet — fall through to create it.
				}
			}

			$user = $userManager->get($uid);
			$name = $user?->getDisplayName() ?? $uid;

			try {
				$tokenProvider->generateToken(
					$token,
					$uid,
					$uid,
					null,
					$name,
					IToken::PERMANENT_TOKEN,
				);
				$registered++;
			} catch (\Exception $e) {
				$output->warning(sprintf(
					'Could not register auth token for share %d (uid=%s): %s',
					$shareId,
					$uid,
					$e->getMessage()
				));
			}
		}

		$result->closeCursor();

		$output->info(sprintf(
			'Federated share token migration: %d replaced (short tokens), %d registered, %d already up-to-date.',
			$replaced,
			$registered,
			$skipped
		));

		if ($replaced > 0) {
			$output->warning(sprintf(
				'%d federated share(s) had their token replaced. The remote side\'s copy of the '
				. 'old token is now stale — those shares will need to be re-created.',
				$replaced
			));
		}
	}
}
