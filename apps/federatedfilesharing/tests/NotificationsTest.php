<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\FederatedFileSharing\Tests;

use OC\Federation\CloudFederationFactory;
use OC\Federation\CloudFederationShare;
use OCA\FederatedFileSharing\AddressHandler;
use OCA\FederatedFileSharing\BackgroundJob\RetryJob;
use OCA\FederatedFileSharing\Events\FederatedShareAddedEvent;
use OCA\FederatedFileSharing\Notifications;
use OCP\BackgroundJob\IJobList;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use OCP\OCM\IOCMResource;
use OCP\OCS\IDiscoveryService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class NotificationsTest extends \Test\TestCase {
	private AddressHandler&MockObject $addressHandler;
	private IClientService&MockObject $httpClientService;
	private IDiscoveryService&MockObject $discoveryService;
	private IJobList&MockObject $jobList;
	private ICloudFederationProviderManager&MockObject $cloudFederationProviderManager;
	private ICloudFederationFactory&MockObject $cloudFederationFactory;
	private IEventDispatcher&MockObject $eventDispatcher;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->jobList = $this->createMock(IJobList::class);
		$this->discoveryService = $this->createMock(IDiscoveryService::class);
		$this->httpClientService = $this->createMock(IClientService::class);
		$this->addressHandler = $this->createMock(AddressHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cloudFederationProviderManager = $this->createMock(ICloudFederationProviderManager::class);
		$this->cloudFederationFactory = $this->createMock(ICloudFederationFactory::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
	}

	/**
	 * @return Notifications|MockObject
	 */
	private function getInstance(array $mockedMethods = []) {
		if (empty($mockedMethods)) {
			return new Notifications(
				$this->addressHandler,
				$this->httpClientService,
				$this->discoveryService,
				$this->jobList,
				$this->cloudFederationProviderManager,
				$this->cloudFederationFactory,
				$this->eventDispatcher,
				$this->logger,
			);
		}

		return $this->getMockBuilder(Notifications::class)
			->setConstructorArgs(
				[
					$this->addressHandler,
					$this->httpClientService,
					$this->discoveryService,
					$this->jobList,
					$this->cloudFederationProviderManager,
					$this->cloudFederationFactory,
					$this->eventDispatcher,
					$this->logger,
				]
			)
			->onlyMethods($mockedMethods)
			->getMock();
	}


	#[\PHPUnit\Framework\Attributes\DataProvider(methodName: 'dataTestSendUpdateToRemote')]
	public function testSendUpdateToRemote(int $try, array $httpRequestResult, bool $expected): void {
		$remote = 'http://remote';
		$id = 42;
		$timestamp = 63576;
		$token = 'token';
		$action = 'unshare';
		$instance = $this->getInstance(['tryHttpPostToShareEndpoint', 'getTimestamp']);

		$instance->expects($this->any())->method('getTimestamp')->willReturn($timestamp);

		$instance->expects($this->once())->method('tryHttpPostToShareEndpoint')
			->with($remote, '/' . $id . '/unshare', ['token' => $token, 'data1Key' => 'data1Value', 'remoteId' => $id], $action)
			->willReturn($httpRequestResult);

		// only add background job on first try
		if ($try === 0 && $expected === false) {
			$this->jobList->expects($this->once())->method('add')
				->with(
					RetryJob::class,
					[
						'remote' => $remote,
						'remoteId' => $id,
						'action' => 'unshare',
						'data' => json_encode(['data1Key' => 'data1Value']),
						'token' => $token,
						'try' => $try,
						'lastRun' => $timestamp
					]
				);
		} else {
			$this->jobList->expects($this->never())->method('add');
		}

		$this->assertSame($expected,
			$instance->sendUpdateToRemote($remote, $id, $token, $action, ['data1Key' => 'data1Value'], $try)
		);
	}


	public static function dataTestSendUpdateToRemote(): array {
		return [
			// test if background job is added correctly
			[0, ['success' => true, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 200]]])], true],
			[1, ['success' => true, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 200]]])], true],
			[0, ['success' => false, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 200]]])], false],
			[1, ['success' => false, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 200]]])], false],
			// test all combinations of 'statuscode' and 'success'
			[0, ['success' => true, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 200]]])], true],
			[0, ['success' => true, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 100]]])], true],
			[0, ['success' => true, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 400]]])], false],
			[0, ['success' => false, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 200]]])], false],
			[0, ['success' => false, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 100]]])], false],
			[0, ['success' => false, 'result' => json_encode(['ocs' => ['meta' => ['statuscode' => 400]]])], false],
		];
	}

	public static function dataExchangeTokenPayloadEndpoints(): array {
		return [
			'root mounted endpoint' => [
				'https://local.example/ocm',
				'https://local.example/public.php/dav/files/sender',
			],
			'subdirectory endpoint' => [
				'https://local.example/nextcloud/ocm-provider',
				'https://local.example/nextcloud/public.php/dav/files/sender',
			],
			'nested trailing slash endpoint' => [
				'https://local.example/a/b/ocm-provider/',
				'https://local.example/a/b/public.php/dav/files/sender',
			],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider(methodName: 'dataExchangeTokenPayloadEndpoints')]
	public function testSendRemoteShareUsesPermissionsInExchangeTokenPayload(
		string $localEndpoint,
		string $expectedWebdavUri,
	): void {
		$this->addressHandler->expects(self::once())
			->method('splitUserRemote')
			->with('remoteuser@remote.example')
			->willReturn(['remoteuser', 'remote.example']);
		$this->addressHandler->expects(self::once())
			->method('generateRemoteURL')
			->willReturn('https://local.example/index.php/apps/federation');
		$this->addressHandler->expects(self::once())
			->method('urlContainProtocol')
			->with('remote.example')
			->willReturn(false);

		$remoteCloudId = $this->createMock(ICloudId::class);
		$remoteCloudId->method('getRemote')->willReturn('remote.example');

		$cloudIdManager = $this->createMock(ICloudIdManager::class);
		$cloudIdManager->expects(self::once())
			->method('resolveCloudId')
			->with('remoteuser@https://remote.example')
			->willReturn($remoteCloudId);

		$localResource = $this->createMock(IOCMResource::class);
		$localResource->method('getName')->willReturn('file');
		$localResource->method('getProtocols')->willReturn([
			'webdav' => '/public.php/dav/files/sender',
		]);

		$localProvider = $this->createMock(IOCMProvider::class);
		$localProvider->method('getResourceTypes')->willReturn([$localResource]);
		$localProvider->method('getEndPoint')->willReturn($localEndpoint);

		$remoteProvider = $this->createMock(IOCMProvider::class);
		$remoteProvider->method('getCapabilities')->willReturn(['exchange-token']);

		$ocmDiscoveryService = $this->createMock(IOCMDiscoveryService::class);
		$ocmDiscoveryService->expects(self::once())
			->method('getLocalOCMProvider')
			->with(false)
			->willReturn($localProvider);
		$ocmDiscoveryService->expects(self::once())
			->method('discover')
			->with('remote.example')
			->willReturn($remoteProvider);

		$cloudFederationFactory = new CloudFederationFactory(
			$ocmDiscoveryService,
			$cloudIdManager,
			$this->logger,
		);

		$instance = new Notifications(
			$this->addressHandler,
			$this->httpClientService,
			$this->discoveryService,
			$this->jobList,
			$this->cloudFederationProviderManager,
			$cloudFederationFactory,
			$this->eventDispatcher,
			$this->logger,
		);

		$permissions = Constants::PERMISSION_READ
			| Constants::PERMISSION_UPDATE
			| Constants::PERMISSION_SHARE;

		$this->cloudFederationProviderManager->expects(self::once())
			->method('sendShare')
			->with(self::callback(static function (ICloudFederationShare $share) use ($expectedWebdavUri): bool {
				self::assertInstanceOf(CloudFederationShare::class, $share);

				$protocol = $share->getProtocol();
				self::assertSame('webdav', $protocol['name']);
				self::assertSame(
					$expectedWebdavUri,
					$protocol['webdav']['uri']
				);
				self::assertSame(
					['share', 'read', 'write'],
					$protocol['webdav']['permissions']
				);
				self::assertStringNotContainsString(
					'https://https://',
					$protocol['webdav']['uri']
				);

				return true;
			}))
			->willReturn([
				'token' => 'generated-token',
				'providerId' => 'remote-provider-id',
			]);

		$this->eventDispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(static function ($event): bool {
				return $event instanceof FederatedShareAddedEvent;
			}));

		$this->assertTrue($instance->sendRemoteShare(
			'refresh-token-abc',
			'remoteuser@remote.example',
			'Shared folder',
			'remote-provider-id',
			'Owner',
			'owner@example.org',
			'Sender',
			'sender@example.org',
			0,
			$permissions,
		));
	}
}
