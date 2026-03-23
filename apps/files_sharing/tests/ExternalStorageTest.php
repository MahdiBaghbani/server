<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Files_Sharing\Tests;

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
		$response = $this->createMock(IResponse::class);
		$client
			->expects($this->any())
			->method('get')
			->willReturn($response);
		$client
			->expects($this->any())
			->method('post')
			->willReturn($response);
		$httpClientService
			->expects($this->any())
			->method('newClient')
			->willReturn($client);

		return new TestSharingExternalStorage(
			[
				'cloudId' => new CloudId('testOwner@' . $uri, 'testOwner', $uri),
				'remote' => $uri,
				'owner' => 'testOwner',
				'mountpoint' => 'remoteshare',
				'token' => 'abcdef',
				'password' => '',
				'manager' => $manager,
				'certificateManager' => $certificateManager,
				'HttpClientService' => $httpClientService,
			]
		);
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

	protected function exchangeRefreshTokenResponse(): array {
		return $this->exchangeResponse;
	}
}
