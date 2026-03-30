<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FederatedFileSharing\Tests\OCM;

use OCA\FederatedFileSharing\AddressHandler;
use OCA\FederatedFileSharing\FederatedShareProvider;
use OCA\FederatedFileSharing\OCM\CloudFederationProviderFiles;
use OCA\Files_Sharing\External\ExternalShare;
use OCA\Files_Sharing\External\ExternalShareMapper;
use OCA\Files_Sharing\External\Manager;
use OCA\Files_Sharing\Service\ExchangeOutcome;
use OCA\Files_Sharing\Service\ExchangeResult;
use OCA\Files_Sharing\Service\TokenExchangeHelper;
use OCP\Activity\IManager as IActivityManager;
use OCP\App\IAppManager;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ICloudIdManager;
use OCP\Files\IFilenameValidator;
use OCP\Files\ISetupManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager;
use OCP\Share\IProviderFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class CloudFederationProviderFilesTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private FederatedShareProvider&MockObject $federatedShareProvider;
	private AddressHandler&MockObject $addressHandler;
	private IUserManager&MockObject $userManager;
	private IManager&MockObject $shareManager;
	private ICloudIdManager&MockObject $cloudIdManager;
	private IActivityManager&MockObject $activityManager;
	private INotificationManager&MockObject $notificationManager;
	private IURLGenerator&MockObject $urlGenerator;
	private ICloudFederationFactory&MockObject $cloudFederationFactory;
	private ICloudFederationProviderManager&MockObject $cloudFederationProviderManager;
	private IGroupManager&MockObject $groupManager;
	private IConfig&MockObject $config;
	private Manager&MockObject $externalShareManager;
	private LoggerInterface&MockObject $logger;
	private IFilenameValidator&MockObject $filenameValidator;
	private IProviderFactory&MockObject $shareProviderFactory;
	private ISetupManager&MockObject $setupManager;
	private ExternalShareMapper&MockObject $externalShareMapper;
	private IAppConfig&MockObject $appConfig;
	private TokenExchangeHelper&MockObject $exchangeHelper;

	private CloudFederationProviderFiles $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->federatedShareProvider = $this->createMock(FederatedShareProvider::class);
		$this->addressHandler = $this->createMock(AddressHandler::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
		$this->activityManager = $this->createMock(IActivityManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->cloudFederationFactory = $this->createMock(ICloudFederationFactory::class);
		$this->cloudFederationProviderManager = $this->createMock(ICloudFederationProviderManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->externalShareManager = $this->createMock(Manager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->filenameValidator = $this->createMock(IFilenameValidator::class);
		$this->shareProviderFactory = $this->createMock(IProviderFactory::class);
		$this->setupManager = $this->createMock(ISetupManager::class);
		$this->externalShareMapper = $this->createMock(ExternalShareMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->exchangeHelper = $this->createMock(TokenExchangeHelper::class);
		$this->overwriteService(TokenExchangeHelper::class, $this->exchangeHelper);

		$this->provider = new CloudFederationProviderFiles(
			$this->appManager,
			$this->federatedShareProvider,
			$this->addressHandler,
			$this->userManager,
			$this->shareManager,
			$this->cloudIdManager,
			$this->activityManager,
			$this->notificationManager,
			$this->urlGenerator,
			$this->cloudFederationFactory,
			$this->cloudFederationProviderManager,
			$this->groupManager,
			$this->config,
			$this->externalShareManager,
			$this->logger,
			$this->filenameValidator,
			$this->shareProviderFactory,
			$this->setupManager,
			$this->externalShareMapper,
			$this->appConfig,
		);
	}

	private function enableS2S(): void {
		$this->appManager->method('isEnabledForUser')
			->with('files_sharing')
			->willReturn(true);
		$this->federatedShareProvider->method('isIncomingServer2serverShareEnabled')
			->willReturn(true);
	}

	private function buildShare(array $requirements = []): ICloudFederationShare&MockObject {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn([
			'name' => 'webdav',
			'webdav' => [
				'requirements' => $requirements,
			],
		]);
		$share->method('getOwner')->willReturn('owner@example.com');
		$share->method('getOwnerDisplayName')->willReturn('Owner');
		$share->method('getShareSecret')->willReturn('refresh-token-abc');
		$share->method('getResourceName')->willReturn('/SharedFolder');
		$share->method('getShareWith')->willReturn('localuser');
		$share->method('getProviderId')->willReturn('42');
		$share->method('getSharedBy')->willReturn('owner@example.com');
		$share->method('getShareType')->willReturn('user');
		return $share;
	}

	private function expectIncomingShareStoresAccessToken(string $expectedToken, ?callable $assertExpiry = null): void {
		$user = $this->createMock(IUser::class);

		$this->userManager->method('get')->with('localuser')->willReturn($user);
		$this->filenameValidator->method('isFilenameValid')->willReturn(true);
		$this->externalShareManager->expects($this->once())
			->method('addShare')
			->with(
				$this->callback(function (ExternalShare $externalShare) use ($expectedToken, $assertExpiry) {
					$expiresAt = $externalShare->getAccessTokenExpires();

					if ($externalShare->getAccessToken() !== $expectedToken || $externalShare->getPassword() !== null) {
						return false;
					}

					if ($assertExpiry !== null) {
						return $assertExpiry($expiresAt);
					}

					$now = time();
					return is_int($expiresAt)
						&& $expiresAt >= $now + 3590
						&& $expiresAt <= $now + 3605;
				}),
				$user,
			)
			->willThrowException(new \RuntimeException('stop after access token assertion'));

		$this->expectException(ProviderCouldNotAddShareException::class);
		$this->expectExceptionMessage('internal server error, was not able to add share from https://example.com/');
	}

	private function expectIncomingShareWithoutAccessToken(): void {
		$user = $this->createMock(IUser::class);

		$this->userManager->method('get')->with('localuser')->willReturn($user);
		$this->filenameValidator->method('isFilenameValid')->willReturn(true);
		$this->externalShareManager->expects($this->once())
			->method('addShare')
			->with(
				$this->callback(function (ExternalShare $externalShare): bool {
					return $externalShare->getAccessToken() === null
						&& $externalShare->getAccessTokenExpires() === null;
				}),
				$user,
			)
			->willThrowException(new \RuntimeException('stop after null access token assertion'));

		$this->expectException(ProviderCouldNotAddShareException::class);
		$this->expectExceptionMessage('internal server error, was not able to add share from https://example.com/');
	}

	public function testShareReceivedMustExchangeTokenThrowsWhenExchangeFails(): void {
		$this->enableS2S();

		$this->addressHandler->method('splitUserRemote')
			->with('owner@example.com')
			->willReturn(['owner', 'https://example.com/']);

		$share = $this->buildShare(['must-exchange-token']);

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure(ExchangeOutcome::DefinitiveNoCapability));

		$this->expectException(ProviderCouldNotAddShareException::class);

		$this->provider->shareReceived($share);
	}

	public function testShareReceivedMustExchangeTokenStoresAccessToken(): void {
		$this->enableS2S();

		$this->addressHandler->method('splitUserRemote')
			->with('owner@example.com')
			->willReturn(['owner', 'https://example.com/']);

		$share = $this->buildShare(['must-exchange-token']);

		$expiresAt = time() + 3600;
		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('access-token-xyz', $expiresAt));

		$this->expectIncomingShareStoresAccessToken('access-token-xyz');

		$this->provider->shareReceived($share);
	}

	public function testShareReceivedOptionalExchangeGracefulOnDiscoveryFailure(): void {
		$this->enableS2S();

		$this->addressHandler->method('splitUserRemote')
			->with('owner@example.com')
			->willReturn(['owner', 'https://example.com/']);

		$share = $this->buildShare();

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure(ExchangeOutcome::TransientFailure));

		$this->userManager->method('get')->with('localuser')->willReturn(null);
		$this->filenameValidator->method('isFilenameValid')->willReturn(true);

		$this->expectException(ProviderCouldNotAddShareException::class);
		$this->expectExceptionMessage('User does not exists');

		$this->provider->shareReceived($share);
	}

	public function testShareReceivedOptionalExchangeStoresAccessTokenOnSuccess(): void {
		$this->enableS2S();

		$this->addressHandler->method('splitUserRemote')
			->with('owner@example.com')
			->willReturn(['owner', 'https://example.com/']);

		$share = $this->buildShare();

		$expiresAt = time() + 3600;
		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('access-token-xyz', $expiresAt));

		$this->expectIncomingShareStoresAccessToken('access-token-xyz');

		$this->provider->shareReceived($share);
	}

	public function testShareReceivedOptionalExchangeContinuesWhenExchangeFails(): void {
		$this->enableS2S();

		$this->addressHandler->method('splitUserRemote')
			->with('owner@example.com')
			->willReturn(['owner', 'https://example.com/']);

		$share = $this->buildShare();

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure(ExchangeOutcome::DefinitiveNoCapability));

		$this->expectIncomingShareWithoutAccessToken();

		$this->provider->shareReceived($share);
	}

	public function testShareReceivedStoresAccessTokenWithoutExpiry(): void {
		$this->enableS2S();

		$this->addressHandler->method('splitUserRemote')
			->with('owner@example.com')
			->willReturn(['owner', 'https://example.com/']);

		$share = $this->buildShare(['must-exchange-token']);

		$this->exchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('access-token-no-expiry', null));

		$this->expectIncomingShareStoresAccessToken(
			'access-token-no-expiry',
			static fn ($expiresAt): bool => $expiresAt === null,
		);

		$this->provider->shareReceived($share);
	}
}
