<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Files_Sharing\Tests\External;

use OCA\Files_Sharing\External\ExternalShare;
use OCA\Files_Sharing\External\ExternalShareMapper;
use OCA\Files_Sharing\External\Manager;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\Files\Storage\IStorageFactory;
use OCP\Http\Client\IClientService;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\OCS\IDiscoveryService;
use OCP\Server;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class ManagerUpdateAccessTokenTest extends TestCase {
	private ExternalShareMapper&MockObject $externalShareMapper;
	private LoggerInterface&MockObject $logger;
	private Manager $manager;
	private bool $usesDatabase = false;

	protected function setUp(): void {
		parent::setUp();

		$this->externalShareMapper = $this->createMock(ExternalShareMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->manager = $this->createManager($this->externalShareMapper, $this->logger);
	}

	protected function tearDown(): void {
		if ($this->usesDatabase) {
			Server::get(IDBConnection::class)->getQueryBuilder()
				->delete('share_external')
				->executeStatement();
		}

		parent::tearDown();
	}

	private function createManager(ExternalShareMapper $externalShareMapper, LoggerInterface $logger): Manager {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new Manager(
			$this->createMock(IDBConnection::class),
			$this->createMock(\OC\Files\Mount\Manager::class),
			$this->createMock(IStorageFactory::class),
			$this->createMock(IClientService::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(IDiscoveryService::class),
			$this->createMock(ICloudFederationProviderManager::class),
			$this->createMock(ICloudFederationFactory::class),
			$this->createMock(IGroupManager::class),
			$userSession,
			$this->createMock(IEventDispatcher::class),
			$logger,
			$this->createMock(IRootFolder::class),
			$this->createMock(ISetupManager::class),
			$this->createMock(ICertificateManager::class),
			$externalShareMapper,
			$this->createMock(IConfig::class),
		);
	}

	public function testUpdateAccessTokenUpdatesMatchingRowsInDb(): void {
		$this->externalShareMapper->expects($this->once())
			->method('updateAccessTokenByShareToken')
			->with('refresh-token', 'new-access-token', 9999)
			->willReturn(1);

		$this->manager->updateAccessToken('refresh-token', 'new-access-token', 9999);
	}

	public function testUpdateAccessTokenLogsWarningWhenShareNotFound(): void {
		$this->externalShareMapper->expects($this->once())
			->method('updateAccessTokenByShareToken')
			->with('missing-token', 'access', 0)
			->willReturn(0);

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Could not find share'));

		$this->manager->updateAccessToken('missing-token', 'access', 0);
	}

	public function testUpdateAccessTokenLogsErrorOnDbException(): void {
		$this->externalShareMapper->method('updateAccessTokenByShareToken')
			->willThrowException(new Exception('db error'));

		$this->logger->expects($this->once())
			->method('error')
			->with($this->stringContains('Failed to update access token'));

		$this->manager->updateAccessToken('some-token', 'access', 0);
	}

	#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
	public function testUpdateAccessTokenFansOutAcrossAcceptedGroupShareRows(): void {
		$this->usesDatabase = true;
		$realMapper = new ExternalShareMapper(
			Server::get(IDBConnection::class),
			$this->createMock(IGroupManager::class),
		);
		$logger = $this->createMock(LoggerInterface::class);
		$manager = $this->createManager($realMapper, $logger);

		$parentShare = $this->createGroupShareRow('group1', 'refresh-token', '{{TemporaryMountPointName#/SharedFolder}}');
		$realMapper->insert($parentShare);

		$subShare = $this->createGroupShareRow('user1', 'refresh-token', '/SharedFolder', (string)$parentShare->getId(), IShare::STATUS_ACCEPTED);
		$realMapper->insert($subShare);

		$manager->updateAccessToken('refresh-token', 'fanout-access-token', 9999);

		$updatedParent = $realMapper->getById((string)$parentShare->getId());
		$updatedSubShare = $realMapper->getById((string)$subShare->getId());

		$this->assertSame('fanout-access-token', $updatedParent->getAccessToken());
		$this->assertSame(9999, $updatedParent->getAccessTokenExpires());
		$this->assertSame('fanout-access-token', $updatedSubShare->getAccessToken());
		$this->assertSame(9999, $updatedSubShare->getAccessTokenExpires());
	}

	private function createGroupShareRow(
		string $user,
		string $shareToken,
		string $mountPoint,
		string $parent = '-1',
		int $accepted = IShare::STATUS_PENDING,
	): ExternalShare {
		$share = new ExternalShare();
		$share->generateId();
		$share->setParent($parent);
		$share->setRemote('https://remote.example/');
		$share->setRemoteId('remote-' . substr(md5($user . $mountPoint), 0, 8));
		$share->setShareToken($shareToken);
		$share->setPassword('');
		$share->setName('/SharedFolder');
		$share->setOwner('owner@example.com');
		$share->setUser($user);
		$share->setMountpoint($mountPoint);
		$share->setShareType(IShare::TYPE_GROUP);
		$share->setAccepted($accepted);

		return $share;
	}
}
