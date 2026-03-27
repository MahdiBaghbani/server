<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Files_Sharing\Tests;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use OC\Federation\CloudId;
use OCA\Files_Sharing\External\Manager as ExternalShareManager;
use OCA\Files_Sharing\External\Storage;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICertificateManager;
use OCP\Server;

/**
 * Tests for the external Storage class for remote shares.
 */
#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class ExternalStorageTest extends \Test\TestCase {
	public static function optionsProvider(): array {
		return [
			[
				'http://remoteserver:8080/owncloud',
				'http://remoteserver:8080/owncloud/public.php/webdav/',
			],
			// extra slash
			[
				'http://remoteserver:8080/owncloud/',
				'http://remoteserver:8080/owncloud/public.php/webdav/',
			],
			// extra path
			[
				'http://remoteserver:8080/myservices/owncloud/',
				'http://remoteserver:8080/myservices/owncloud/public.php/webdav/',
			],
			// root path
			[
				'http://remoteserver:8080/',
				'http://remoteserver:8080/public.php/webdav/',
			],
			// without port
			[
				'http://remoteserver/oc.test',
				'http://remoteserver/oc.test/public.php/webdav/',
			],
			// https
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

	public function testRefreshBearerTokenUsesServerExpiry(): void {
		$now = time();
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->once())
			->method('getShareByToken')
			->with('abcdef')
			->willReturn(false);
		$manager->expects($this->once())
			->method('updateAccessToken')
			->with(
				'abcdef',
				'fresh-access-token',
				$this->callback(static fn (int $expiresAt): bool => $expiresAt >= $now + 86390 && $expiresAt <= $now + 86410)
			);

		$storage = $this->getTestStorage('https://remoteserver', $manager);
		$storage->setExchangeRefreshTokenResponse([
			'accessToken' => 'fresh-access-token',
			'expiresIn' => 86400,
		]);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertSame('fresh-access-token', $storage->getBearerPassword());
		$this->assertGreaterThanOrEqual($now + 86390, $storage->getTokenExpiry());
	}

	public function testRefreshBearerTokenFallsBackWhenExpiryMissing(): void {
		$now = time();
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->once())
			->method('getShareByToken')
			->with('abcdef')
			->willReturn(false);
		$manager->expects($this->once())
			->method('updateAccessToken')
			->with(
				'abcdef',
				'fresh-access-token',
				$this->callback(static fn (int $expiresAt): bool => $expiresAt >= $now + 3590 && $expiresAt <= $now + 3610)
			);

		$storage = $this->getTestStorage('https://remoteserver', $manager);
		$storage->setExchangeRefreshTokenResponse([
			'accessToken' => 'fresh-access-token',
			'expiresIn' => null,
		]);

		$this->assertTrue($storage->runRefreshBearerToken());
		$this->assertGreaterThanOrEqual($now + 3590, $storage->getTokenExpiry());
	}

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

	public function testWithAuthRetryForcesRefreshAfter401WithLocallyValidToken(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->once())
			->method('getShareByToken')
			->with('abcdef')
			->willReturn(false);
		$manager->expects($this->once())
			->method('updateAccessToken')
			->with(
				'abcdef',
				'fresh-access-token',
				$this->greaterThan(time())
			);

		$storage = $this->getTestStorageWithGetBodies(
			'https://forced-refresh.example',
			[],
			$manager,
			[
				'access_token' => 'stale-access-token',
				'access_token_expires' => time() + 3600,
			]
		);
		$storage->setExchangeRefreshTokenResponse([
			'accessToken' => 'fresh-access-token',
			'expiresIn' => 1200,
		]);

		$attempts = 0;
		$result = $storage->runWithAuthRetry(function () use (&$attempts) {
			$attempts++;
			if ($attempts === 1) {
				throw new ClientException(
					'unauthorized',
					new Request('GET', 'https://forced-refresh.example/remote.php/dav/files/test'),
					new Psr7Response(401)
				);
			}

			return 'retried-ok';
		});

		$this->assertSame('retried-ok', $result);
		$this->assertSame(2, $attempts);
		$this->assertSame('fresh-access-token', $storage->getBearerPassword());
	}

	public function testForcedRefreshDoesNotReuseSameDbToken(): void {
		$now = time();
		$share = new \OCA\Files_Sharing\External\ExternalShare();
		$share->setAccessToken('stale-access-token');
		$share->setAccessTokenExpires($now + 1800);

		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->once())
			->method('getShareByToken')
			->with('abcdef')
			->willReturn($share);
		$manager->expects($this->once())
			->method('updateAccessToken')
			->with(
				'abcdef',
				'fresh-access-token',
				$this->callback(static fn (int $expiresAt): bool => $expiresAt >= $now + 1190 && $expiresAt <= $now + 1210)
			);

		$storage = $this->getTestStorageWithGetBodies(
			'https://db-refresh.example',
			[],
			$manager,
			[
				'access_token' => 'stale-access-token',
				'access_token_expires' => $now + 3600,
			]
		);
		$storage->setExchangeRefreshTokenResponse([
			'accessToken' => 'fresh-access-token',
			'expiresIn' => 1200,
		]);

		$this->assertSame('retried-ok', $storage->runWithAuthRetry(function () {
			static $attempt = 0;
			$attempt++;
			if ($attempt === 1) {
				throw new ClientException(
					'unauthorized',
					new Request('GET', 'https://db-refresh.example/remote.php/dav/files/test'),
					new Psr7Response(401)
				);
			}

			return 'retried-ok';
		}));
		$this->assertSame('fresh-access-token', $storage->getBearerPassword());
	}

	public function testRefreshBearerTokenRejectsMissingAccessTokenInResponse(): void {
		$manager = $this->createMock(ExternalShareManager::class);
		$manager->expects($this->once())
			->method('getShareByToken')
			->with('abcdef')
			->willReturn(false);
		$manager->expects($this->never())->method('updateAccessToken');

		$storage = $this->getTestStorage('https://malformed-response.example', $manager);
		$storage->setExchangeRefreshTokenResponse([
			'expiresIn' => 3600,
		]);

		$this->assertFalse($storage->runRefreshBearerToken());
	}
}

/**
 * Dummy subclass to make it possible to access private members
 */
class TestSharingExternalStorage extends Storage {
	/** @var array{accessToken: string, expiresIn: ?int} */
	private array $exchangeResponse = [
		'accessToken' => 'fresh-access-token',
		'expiresIn' => null,
	];

	public function getBaseUri() {
		return $this->createBaseUri();
	}

	public function stat(string $path): array|false {
		if ($path === '') {
			return ['key' => 'value'];
		}
		return parent::stat($path);
	}

	public function setExchangeRefreshTokenResponse(array $response): void {
		$this->exchangeResponse = $response;
	}

	public function runRefreshBearerToken(): bool {
		return $this->refreshBearerToken();
	}

	public function getBearerPassword(): string {
		return $this->password;
	}

	public function getTokenExpiry(): int {
		return \Closure::bind(fn (): int => $this->tokenExpiresAt, $this, Storage::class)();
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

	protected function exchangeRefreshTokenResponse(): array {
		return $this->exchangeResponse;
	}
}
