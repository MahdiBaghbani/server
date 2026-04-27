<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Files_Sharing\Tests;

use OC\Federation\CloudId;
use OCA\Files_Sharing\External\ExternalShare;
use OCA\Files_Sharing\External\Manager as ExternalShareManager;
use OCA\Files_Sharing\External\Storage;
use OCA\Files_Sharing\Service\ExchangeOutcome;
use OCA\Files_Sharing\Service\ExchangeResult;
use OCA\Files_Sharing\Service\ResolutionResult;
use OCA\Files_Sharing\Service\TokenExchangeHelper;
use OCA\Files_Sharing\Service\TokenExchangeMode;
use OCA\Files_Sharing\Service\TokenExchangeModeResolver;
use OCP\Files\StorageNotAvailableException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICertificateManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for the external Storage class for remote shares.
 */
#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class ExternalStorageTest extends \Test\TestCase {
	private TokenExchangeModeResolver&MockObject $mockResolver;
	private TokenExchangeHelper&MockObject $mockExchangeHelper;

	protected function setUp(): void {
		parent::setUp();

		$this->mockResolver = $this->createMock(TokenExchangeModeResolver::class);
		$this->mockExchangeHelper = $this->createMock(TokenExchangeHelper::class);

		TestSharingExternalStorage::setTestResolver($this->mockResolver);
		TestSharingExternalStorage::setTestExchangeHelper($this->mockExchangeHelper);
	}

	protected function tearDown(): void {
		TestSharingExternalStorage::setTestResolver(null);
		TestSharingExternalStorage::setTestExchangeHelper(null);
		parent::tearDown();
	}

	public static function optionsProvider(): array {
		return [
			[
				'http://remoteserver:8080/owncloud',
				'http://remoteserver:8080/owncloud/public.php/webdav/',
			],
			[
				'http://remoteserver:8080/owncloud/',
				'http://remoteserver:8080/owncloud/public.php/webdav/',
			],
			[
				'http://remoteserver:8080/myservices/owncloud/',
				'http://remoteserver:8080/myservices/owncloud/public.php/webdav/',
			],
			[
				'http://remoteserver:8080/',
				'http://remoteserver:8080/public.php/webdav/',
			],
			[
				'http://remoteserver/oc.test',
				'http://remoteserver/oc.test/public.php/webdav/',
			],
			[
				'https://remoteserver/',
				'https://remoteserver/public.php/webdav/',
			],
		];
	}

	private function getTestStorage($uri, ?ExternalShareManager $manager = null) {
		$certificateManager = Server::get(ICertificateManager::class);
		$httpClientService = $this->createMock(IClientService::class);
		$manager ??= $this->createMock(ExternalShareManager::class);
		$client = $this->createMock(IClient::class);
		$client
			->expects($this->any())
			->method('get')
			->willReturnCallback(function (...$_args): IResponse {
				$response = $this->createMock(IResponse::class);
				$response
					->method('getBody')
					->willReturn(json_encode([
						'enabled' => true,
						'endPoint' => 'https://remoteserver/ocm',
						'resourceTypes' => [],
					]));
				return $response;
			});
		$client
			->expects($this->any())
			->method('post')
			->willReturnCallback(function (...$_args): IResponse {
				$response = $this->createMock(IResponse::class);
				$response
					->method('getBody')
					->willReturn('{}');
				return $response;
			});
		$httpClientService
			->expects($this->any())
			->method('newClient')
			->willReturn($client);

		return new TestSharingExternalStorage($this->buildStorageOptions($uri, $manager, $httpClientService, $certificateManager));
	}

	private function getTestStorageWithGetBodies(
		string $uri,
		array $bodies,
		?ExternalShareManager $manager = null,
		array $storageOverrides = [],
	): TestSharingExternalStorage {
		$certificateManager = Server::get(ICertificateManager::class);
		$httpClientService = $this->createMock(IClientService::class);
		$manager ??= $this->createMock(ExternalShareManager::class);
		$client = $this->createMock(IClient::class);
		$defaultOcmDiscoveryBody = json_encode([
			'enabled' => true,
			'endPoint' => rtrim($uri, '/') . '/ocm',
			'resourceTypes' => [],
		]);

		$client
			->expects($this->any())
			->method('get')
			->willReturnCallback(function (string $url, ...$_args) use ($bodies, $defaultOcmDiscoveryBody): IResponse {
				$body = $bodies[$url] ?? $defaultOcmDiscoveryBody;
				$response = $this->createMock(IResponse::class);
				$response
					->method('getBody')
					->willReturn($body);
				return $response;
			});
		$client
			->expects($this->any())
			->method('post')
			->willReturnCallback(function (...$_args): IResponse {
				$response = $this->createMock(IResponse::class);
				$response
					->method('getBody')
					->willReturn('{}');
				return $response;
			});
		$httpClientService
			->expects($this->any())
			->method('newClient')
			->willReturn($client);

		return new TestSharingExternalStorage(
			$this->buildStorageOptions($uri, $manager, $httpClientService, $certificateManager, $storageOverrides)
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function buildStorageOptions(
		string $uri,
		ExternalShareManager $manager,
		IClientService $httpClientService,
		ICertificateManager $certificateManager,
		array $overrides = [],
	): array {
		return array_merge([
			'cloudId' => new CloudId('testOwner@' . $uri, 'testOwner', $uri),
			'remote' => $uri,
			'owner' => 'testOwner',
			'mountpoint' => 'remoteshare',
			'token' => 'abcdef',
			'password' => '',
			'manager' => $manager,
			'certificateManager' => $certificateManager,
			'HttpClientService' => $httpClientService,
		], $overrides);
	}

	/**
	 * @return array<string, string>
	 */
	private function getDisabledOcmProbeBodies(string $remote): array {
		$disabledBody = json_encode([
			'enabled' => false,
			'endPoint' => $remote . '/ocm',
			'resourceTypes' => [['name' => 'file']],
		]);

		return [
			$remote . '/ocm-provider/index.php' => $disabledBody,
			$remote . '/ocm-provider/' => $disabledBody,
			$remote . '/.well-known/ocm' => $disabledBody,
		];
	}

	// ---------------------------------------------------------------
	// Basic tests (unchanged)
	// ---------------------------------------------------------------

	#[\PHPUnit\Framework\Attributes\DataProvider(methodName: 'optionsProvider')]
	public function testStorageMountOptions($inputUri, $baseUri): void {
		$storage = $this->getTestStorage($inputUri);
		$this->assertEquals($baseUri, $storage->getBaseUri());
	}

	public function testIfTestReturnsTheValue(): void {
		$storage = $this->getTestStorage('https://remoteserver');
		$result = $storage->test();
		$this->assertSame(true, $result);
	}

	// ---------------------------------------------------------------
	// Mode-driven auth selection (replaces heuristic tests)
	// ---------------------------------------------------------------

	public function testLegacyModeUsesBasicAuth(): void {
		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			null,
			['token_exchange_mode' => TokenExchangeMode::LEGACY],
		);

		$this->assertFalse($storage->usesBearerAuth());
	}

	public function testExchangeOptionalWithAccessTokenUsesBearer(): void {
		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			null,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'access_token' => 'stored-bearer',
				'access_token_expires' => time() + 3600,
			],
		);

		$this->assertTrue($storage->usesBearerAuth());
		$this->assertSame('stored-bearer', $storage->getBearerPassword());
	}

	public function testNullModeDefaultsToBasicAuth(): void {
		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
		);

		$this->assertFalse($storage->usesBearerAuth());
	}

	public function testExchangeRequiredUsesBearer(): void {
		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			null,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_REQUIRED,
				'access_token' => 'required-bearer',
				'access_token_expires' => time() + 3600,
			],
		);

		$this->assertTrue($storage->usesBearerAuth());
	}

	// ---------------------------------------------------------------
	// Constructor guards
	// ---------------------------------------------------------------

	public function testConstructorDoesNotTriggerLazyResolutionOrTokenProbe(): void {
		$this->mockResolver->expects($this->never())->method('ensureModeResolved');
		$this->mockExchangeHelper->expects($this->never())->method('exchange');

		$this->getTestStorageWithGetBodies('https://remote.example', []);
	}

	public function testLegacyPasswordRetainedRegardlessOfAuthType(): void {
		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			null,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'password' => 'shared-secret',
				'access_token' => 'bearer-tok',
				'access_token_expires' => time() + 3600,
			],
		);

		$this->assertSame('shared-secret', $storage->getLegacyPassword());
		$this->assertSame('bearer-tok', $storage->getBearerPassword());
	}

	// ---------------------------------------------------------------
	// init() lazy resolution
	// ---------------------------------------------------------------

	public function testInitTriggersLazyResolutionForNullMode(): void {
		$share = new ExternalShare();
		$share->setTokenExchangeMode(null);

		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByIdInternal')
			->with('42')
			->willReturn($share);

		$this->mockResolver->expects($this->once())
			->method('ensureModeResolved')
			->with($share)
			->willReturn(new ResolutionResult(
				ExchangeOutcome::DefinitiveNoCapability,
				TokenExchangeMode::LEGACY,
			));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			['share_id' => '42'],
		);

		$storage->runInit();

		$this->assertFalse($storage->usesBearerAuth());
	}

	public function testInitDeletedRowThrowsStorageNotAvailable(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByIdInternal')
			->with('42')
			->willReturn(false);

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			['share_id' => '42'],
		);

		$this->expectException(StorageNotAvailableException::class);
		$storage->runInit();
	}

	public function testInitResolutionWithBearerSetsAuthToBearer(): void {
		$share = new ExternalShare();
		$share->setTokenExchangeMode(null);

		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByIdInternal')->willReturn($share);

		$this->mockResolver->method('ensureModeResolved')
			->willReturn(new ResolutionResult(
				ExchangeOutcome::Success,
				TokenExchangeMode::EXCHANGE_OPTIONAL,
				'resolved-bearer',
				time() + 3600,
			));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			['share_id' => '42'],
		);

		$storage->runInit();

		$this->assertTrue($storage->usesBearerAuth());
		$this->assertSame('resolved-bearer', $storage->getBearerPassword());
	}

	// ---------------------------------------------------------------
	// init() opportunistic exchange gate
	// ---------------------------------------------------------------

	public function testInitOpportunisticExchangeSuccess(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->once())
			->method('updateAccessToken')
			->with('abcdef', 'opp-bearer', $this->greaterThan(0));

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('opp-bearer', time() + 3600));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'password' => 'legacy-pw',
			],
		);

		$storage->runInit();

		$this->assertTrue($storage->usesBearerAuth());
		$this->assertSame('opp-bearer', $storage->getBearerPassword());
	}

	public function testInitOpportunisticExchangeFailureFallsBackToBasic(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->never())->method('updateAccessToken');

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure(ExchangeOutcome::TransientFailure));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'password' => 'legacy-pw',
			],
		);

		$storage->runInit();

		$this->assertFalse($storage->usesBearerAuth());
		$this->assertSame('legacy-pw', $storage->getBearerPassword());
	}

	// ---------------------------------------------------------------
	// init() bearer-empty guard
	// ---------------------------------------------------------------

	public function testInitBearerEmptyGuardThrowsForExchangeRequired(): void {
		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			null,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_REQUIRED,
			],
		);

		$this->expectException(StorageNotAvailableException::class);
		$storage->runInit();
	}

	// ---------------------------------------------------------------
	// refreshBearerToken -- success
	// ---------------------------------------------------------------

	public function testRefreshBearerTokenSuccessStoresTokenAndExpiry(): void {
		$now = time();
		$expiresAt = $now + 86400;
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->with('abcdef')->willReturn(false);
		$manager->expects($this->once())
			->method('updateAccessToken')
			->with('abcdef', 'fresh-access-token', $expiresAt);

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('fresh-access-token', $expiresAt));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'access_token' => 'stale-token',
				'access_token_expires' => 0,
			],
		);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame('fresh-access-token', $storage->getBearerPassword());
		$this->assertSame($expiresAt, $storage->getTokenExpiry());
	}

	public function testRefreshBearerTokenSuccessWithNullExpiryDefaultsToZero(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->willReturn(false);
		$manager->expects($this->once())
			->method('updateAccessToken')
			->with('abcdef', 'fresh-token', 0);

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('fresh-token', null));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'access_token' => 'stale',
				'access_token_expires' => 0,
			],
		);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame(0, $storage->getTokenExpiry());
	}

	// ---------------------------------------------------------------
	// refreshBearerToken -- exchange-required fatal
	// ---------------------------------------------------------------

	public static function nonSuccessOutcomeProvider(): array {
		return [
			'definitive-invalid-grant' => [ExchangeOutcome::DefinitiveInvalidGrant],
			'definitive-no-capability' => [ExchangeOutcome::DefinitiveNoCapability],
			'explicit-invalid-request' => [ExchangeOutcome::ExplicitInvalidRequest],
			'malformed-response' => [ExchangeOutcome::MalformedResponse],
			'transient-failure' => [ExchangeOutcome::TransientFailure],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider(methodName: 'nonSuccessOutcomeProvider')]
	public function testRefreshExchangeRequiredThrowsOnNonSuccess(ExchangeOutcome $outcome): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->willReturn(false);
		$manager->expects($this->never())->method('updateModeAndClearBearer');

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure($outcome));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_REQUIRED,
				'access_token' => 'stale',
				'access_token_expires' => 0,
			],
		);

		$this->expectException(StorageNotAvailableException::class);
		$storage->runRefreshBearerToken();
	}

	// ---------------------------------------------------------------
	// refreshBearerToken -- exchange-optional definitive downgrade
	// ---------------------------------------------------------------

	public function testRefreshOptionalDefinitiveInvalidGrantPersistsLegacy(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->willReturn(false);
		$manager->expects($this->once())
			->method('updateModeAndClearBearer')
			->with('abcdef', TokenExchangeMode::LEGACY);

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure(ExchangeOutcome::DefinitiveInvalidGrant));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'password' => 'legacy-pw',
				'access_token' => 'stale',
				'access_token_expires' => 0,
			],
		);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame('legacy-pw', $storage->getBearerPassword());
		$this->assertSame(TokenExchangeMode::LEGACY, $storage->getStoredTokenExchangeMode());
	}

	public function testRefreshOptionalDefinitiveNoCapabilityPersistsLegacy(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->willReturn(false);
		$manager->expects($this->once())
			->method('updateModeAndClearBearer')
			->with('abcdef', TokenExchangeMode::LEGACY);

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure(ExchangeOutcome::DefinitiveNoCapability));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'password' => 'legacy-pw',
				'access_token' => 'stale',
				'access_token_expires' => 0,
			],
		);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame('legacy-pw', $storage->getBearerPassword());
	}

	// ---------------------------------------------------------------
	// refreshBearerToken -- exchange-optional non-definitive fallback
	// ---------------------------------------------------------------

	public static function nonDefinitiveOutcomeProvider(): array {
		return [
			'explicit-invalid-request' => [ExchangeOutcome::ExplicitInvalidRequest],
			'malformed-response' => [ExchangeOutcome::MalformedResponse],
			'transient-failure' => [ExchangeOutcome::TransientFailure],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider(methodName: 'nonDefinitiveOutcomeProvider')]
	public function testRefreshOptionalNonDefinitiveDoesNotPersistDowngrade(ExchangeOutcome $outcome): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->willReturn(false);
		$manager->expects($this->never())->method('updateModeAndClearBearer');

		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::failure($outcome));

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'password' => 'legacy-pw',
				'access_token' => 'stale',
				'access_token_expires' => 0,
			],
		);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame('legacy-pw', $storage->getBearerPassword());
	}

	// ---------------------------------------------------------------
	// refreshBearerToken -- backoff window
	// ---------------------------------------------------------------

	public function testBackoffWindowExchangeOptionalReturnsTrueWithBasicFallback(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->willReturn(false);

		$this->mockExchangeHelper->expects($this->never())->method('exchange');

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'password' => 'legacy-pw',
				'access_token' => 'stale',
				'access_token_expires' => 0,
			],
		);

		$storage->setRefreshBackoffUntil(time() + 60);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame('legacy-pw', $storage->getBearerPassword());
	}

	public function testBackoffWindowExchangeRequiredThrows(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->method('getShareByToken')->willReturn(false);

		$storage = $this->getTestStorageWithGetBodies(
			'https://remote.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_REQUIRED,
				'access_token' => 'stale',
				'access_token_expires' => 0,
			],
		);

		$storage->setRefreshBackoffUntil(time() + 60);

		$this->expectException(StorageNotAvailableException::class);
		$storage->runRefreshBearerToken();
	}

	// ---------------------------------------------------------------
	// Concurrent refresh from DB
	// ---------------------------------------------------------------

	public function testForcedRefreshDoesNotReuseSameDbToken(): void {
		$now = time();
		$share = new ExternalShare();
		$share->setAccessToken('stale-access-token');
		$share->setAccessTokenExpires($now + 1800);

		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->once())
			->method('getShareByToken')
			->with('abcdef')
			->willReturn($share);
		$manager->expects($this->once())
			->method('updateAccessToken');

		$expiresAt = $now + 1200;
		$this->mockExchangeHelper->method('exchange')
			->willReturn(ExchangeResult::success('fresh-access-token', $expiresAt));

		$storage = $this->getTestStorageWithGetBodies(
			'https://db-refresh.example',
			[],
			$manager,
			[
				'token_exchange_mode' => TokenExchangeMode::EXCHANGE_OPTIONAL,
				'access_token' => 'stale-access-token',
				'access_token_expires' => $now + 3600,
			],
		);

		$storage->setForceTokenRefresh(true);
		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame('fresh-access-token', $storage->getBearerPassword());
	}

	// ---------------------------------------------------------------
	// Remote check tests (unchanged)
	// ---------------------------------------------------------------

	public function testOwnCloudStatusDetectionRejectsRevaProduct(): void {
		$remote = 'https://remoteserver';
		$storage = $this->getTestStorageWithGetBodies($remote, [
			$remote . '/status.php' => json_encode([
				'version' => '10.0.11',
				'productname' => 'reva',
			]),
		]);

		$this->assertFalse($storage->runOwnCloudStatusCheck());
	}

	public function testOwnCloudStatusDetectionAcceptsNextcloudProduct(): void {
		$remote = 'https://remoteserver';
		$storage = $this->getTestStorageWithGetBodies($remote, [
			$remote . '/status.php' => json_encode([
				'version' => '33.0.0',
				'productname' => 'Nextcloud',
			]),
		]);

		$this->assertTrue($storage->runOwnCloudStatusCheck());
	}

	public function testRemoteCheckAcceptsOcmDiscoveryWithoutStatusVersion(): void {
		$remote = 'https://remoteserver';
		$storage = $this->getTestStorageWithGetBodies($remote, [
			$remote . '/.well-known/ocm' => json_encode([
				'enabled' => true,
				'endPoint' => $remote . '/ocm',
				'resourceTypes' => [['name' => 'file', 'shareTypes' => ['user'], 'protocols' => ['webdav' => '/remote.php/dav/ocm/']]],
				'capabilities' => ['shares'],
			]),
			$remote . '/status.php' => json_encode([
				'version' => '10.0.11',
				'productname' => 'reva',
			]),
		]);

		$this->assertTrue($storage->runRemoteCheck());
	}

	public function testRemoteCheckRejectsOcmDisabledForEndpoint(): void {
		$remote = 'https://disabled-ocm.example';
		$storage = $this->getTestStorageWithGetBodies($remote, array_merge(
			$this->getDisabledOcmProbeBodies($remote),
			[
				$remote . '/status.php' => json_encode([
					'version' => '10.0.11',
					'productname' => 'reva',
				]),
			]
		));

		$this->assertFalse($storage->runRemoteCheck());
	}

	public function testRemoteCheckFallsBackToStatusWhenOcmDisabledForEndpoint(): void {
		$remote = 'https://status-fallback.example';
		$storage = $this->getTestStorageWithGetBodies($remote, array_merge(
			$this->getDisabledOcmProbeBodies($remote),
			[
				$remote . '/status.php' => json_encode([
					'version' => '33.0.0',
					'productname' => 'Nextcloud',
				]),
			]
		));

		$this->assertTrue($storage->runRemoteCheck());
	}

	// ---------------------------------------------------------------
	// Static guard: no token-length heuristics in Storage.php
	// ---------------------------------------------------------------

	public function testStorageSourceDoesNotContainTokenLengthHeuristic(): void {
		$source = file_get_contents(__DIR__ . '/../lib/External/Storage.php');
		$this->assertStringNotContainsString('shareTokenSupportsExchange', $source);
		$this->assertStringNotContainsString('REFRESH_MAX_ATTEMPTS', $source);
		$this->assertFalse(
			(bool)preg_match('/strlen\s*\(\s*\$.*token/i', $source),
			'Storage.php must not use strlen on token for auth policy',
		);
	}
}

/**
 * Test subclass exposing internal Storage state and providing mock injection seams.
 */
class TestSharingExternalStorage extends Storage {
	private static ?TokenExchangeModeResolver $testResolver = null;
	private static ?TokenExchangeHelper $testExchangeHelper = null;

	public static function setTestResolver(?TokenExchangeModeResolver $resolver): void {
		self::$testResolver = $resolver;
	}

	public static function setTestExchangeHelper(?TokenExchangeHelper $helper): void {
		self::$testExchangeHelper = $helper;
	}

	protected function createResolver(): TokenExchangeModeResolver {
		return self::$testResolver ?? parent::createResolver();
	}

	protected function createExchangeHelper(): TokenExchangeHelper {
		return self::$testExchangeHelper ?? parent::createExchangeHelper();
	}

	public function getBaseUri() {
		return $this->createBaseUri();
	}

	public function stat(string $path): array|false {
		if ($path === '') {
			return ['key' => 'value'];
		}
		return parent::stat($path);
	}

	public function runInit(): void {
		$this->init();
	}

	public function runRefreshBearerToken(): bool {
		return $this->refreshBearerToken();
	}

	public function getBearerPassword(): string {
		return $this->password;
	}

	public function getLegacyPassword(): string {
		return \Closure::bind(fn (): string => $this->legacyPassword, $this, Storage::class)();
	}

	public function getTokenExpiry(): int {
		return \Closure::bind(fn (): int => $this->tokenExpiresAt, $this, Storage::class)();
	}

	public function getStoredTokenExchangeMode(): ?string {
		return \Closure::bind(fn (): ?string => $this->tokenExchangeMode, $this, Storage::class)();
	}

	public function setRefreshBackoffUntil(int $timestamp): void {
		\Closure::bind(function () use ($timestamp): void {
			$this->refreshBackoffUntil = $timestamp;
		}, $this, Storage::class)();
	}

	public function setForceTokenRefresh(bool $force): void {
		$this->forceTokenRefresh = $force;
	}

	public function runOwnCloudStatusCheck(): bool {
		return \Closure::bind(
			fn (): bool => $this->testOwnCloudStatusUrl($this->getRemote() . '/status.php'),
			$this,
			Storage::class
		)();
	}

	public function runRemoteCheck(): bool {
		return \Closure::bind(fn (): bool => $this->testRemote(), $this, Storage::class)();
	}

	public function runWithAuthRetry(callable $operation): mixed {
		return $this->withAuthRetry($operation);
	}

	public function usesBearerAuth(): bool {
		return $this->isBearerAuth();
	}
}
