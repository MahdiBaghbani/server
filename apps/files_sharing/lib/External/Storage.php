<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Files_Sharing\External;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use OC\Files\Storage\BearerAuthAwareSabreClient;
use OC\Files\Storage\DAV;
use OC\ForbiddenException;
use OCA\Files_Sharing\External\Manager as ExternalShareManager;
use OCA\Files_Sharing\ISharedStorage;
use OCA\Files_Sharing\Service\ExchangeOutcome;
use OCA\Files_Sharing\Service\TokenExchangeHelper;
use OCA\Files_Sharing\Service\TokenExchangeMode;
use OCA\Files_Sharing\Service\TokenExchangeModeResolver;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\Federation\ICloudId;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\IScanner;
use OCP\Files\Cache\IWatcher;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IDisableEncryptionStorage;
use OCP\Files\Storage\IReliableEtagStorage;
use OCP\Files\Storage\IStorage;
use OCP\Files\StorageInvalidException;
use OCP\Files\StorageNotAvailableException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\OCM\Exceptions\OCMArgumentException;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

class Storage extends DAV implements ISharedStorage, IDisableEncryptionStorage, IReliableEtagStorage {
	private ICloudId $cloudId;
	private string $mountPoint;
	private string $token;
	private string $shareId;
	private string $legacyPassword;
	private ?string $tokenExchangeMode;
	private ICacheFactory $memcacheFactory;
	private IClientService $httpClient;
	private bool $updateChecked = false;
	private ExternalShareManager $manager;
	private IConfig $config;
	protected IAppConfig $appConfig;
	private IShareManager $shareManager;
	private TokenExchangeModeResolver $resolver;
	private TokenExchangeHelper $exchangeHelper;
	private bool $resolvingMode = false;
	private int $tokenExpiresAt = 0;
	private int $refreshBackoffUntil = 0;

	private const REFRESH_BACKOFF_SECONDS = 5;

	/**
	 * @param array{HttpClientService: IClientService, manager: ExternalShareManager, cloudId: ICloudId, mountpoint: string, token: string, access_token: ?string, access_token_expires: ?int, token_exchange_mode: ?string, share_id: ?string}|array $options
	 */
	public function __construct($options) {
		$this->memcacheFactory = Server::get(ICacheFactory::class);
		$this->httpClient = $options['HttpClientService'];
		$this->manager = $options['manager'];
		$this->cloudId = $options['cloudId'];
		$this->logger = Server::get(LoggerInterface::class);
		$discoveryService = Server::get(IOCMDiscoveryService::class);
		$this->config = Server::get(IConfig::class);
		$this->appConfig = Server::get(IAppConfig::class);
		$this->shareManager = Server::get(IShareManager::class);
		$this->resolver = $this->createResolver();
		$this->exchangeHelper = $this->createExchangeHelper();

		$this->legacyPassword = (string)($options['password'] ?? '');
		$this->tokenExchangeMode = $options['token_exchange_mode'] ?? null;
		$this->shareId = (string)($options['share_id'] ?? '');

		try {
			$ocmProvider = $discoveryService->discover($this->cloudId->getRemote());
			$webDavEndpoint = $ocmProvider->extractProtocolEntry('file', 'webdav');
			$remote = $ocmProvider->getEndPoint();
		} catch (OCMProviderException|OCMArgumentException $e) {
			$this->logger->notice('exception while retrieving webdav endpoint', ['exception' => $e]);
			$webDavEndpoint = '/public.php/webdav';
			$remote = $this->cloudId->getRemote();
		}

		$authType = match ($this->tokenExchangeMode) {
			TokenExchangeMode::EXCHANGE_REQUIRED,
			TokenExchangeMode::EXCHANGE_OPTIONAL => BearerAuthAwareSabreClient::AUTH_BEARER,
			default => \Sabre\DAV\Client::AUTH_BASIC,
		};

		$host = parse_url($remote, PHP_URL_HOST)
			?? parse_url($this->cloudId->getRemote(), PHP_URL_HOST)
			?? $this->cloudId->getRemote();
		$port = parse_url($remote, PHP_URL_PORT)
			?? parse_url($this->cloudId->getRemote(), PHP_URL_PORT);
		if ($port !== null) {
			$host .= ':' . $port;
		}
		$scheme = parse_url($remote, PHP_URL_SCHEME)
			?? parse_url($this->cloudId->getRemote(), PHP_URL_SCHEME)
			?? 'https';

		$tmpPath = rtrim(parse_url($this->cloudId->getRemote(), PHP_URL_PATH) ?? '', '/');
		if (!str_starts_with($webDavEndpoint, $tmpPath)) {
			$webDavEndpoint = $tmpPath . $webDavEndpoint;
		}

		$this->mountPoint = $options['mountpoint'];
		$this->token = $options['token'];
		$this->tokenExpiresAt = (int)($options['access_token_expires'] ?? 0);

		parent::__construct(
			[
				'secure' => ($scheme === 'https'),
				'verify' => !$this->config->getSystemValueBool('sharing.federation.allowSelfSignedCertificates', false),
				'host' => $host,
				'root' => $webDavEndpoint,
				'user' => $options['token'],
				'authType' => $authType,
				'password' => $authType === BearerAuthAwareSabreClient::AUTH_BEARER
					? (string)($options['access_token'] ?? '')
					: $this->legacyPassword,
				'discoveryService' => $discoveryService,
			]
		);
	}

	protected function createResolver(): TokenExchangeModeResolver {
		return Server::get(TokenExchangeModeResolver::class);
	}

	protected function createExchangeHelper(): TokenExchangeHelper {
		return Server::get(TokenExchangeHelper::class);
	}

	protected function init(): void {
		if ($this->ready) {
			return;
		}

		if ($this->resolvingMode) {
			parent::init();
			return;
		}

		// NULL resolution: load the persisted row and resolve mode lazily.
		if ($this->shareId !== '' && $this->tokenExchangeMode === null) {
			$share = $this->manager->getShareByIdInternal($this->shareId);
			if ($share === false) {
				throw new StorageNotAvailableException('Mounted share row no longer exists');
			}

			$this->resolvingMode = true;
			try {
				$result = $this->resolver->ensureModeResolved($share);
			} finally {
				$this->resolvingMode = false;
			}

			if ($result->resolvedMode !== null) {
				$this->tokenExchangeMode = $result->resolvedMode;
			}

			if ($result->resolvedMode === TokenExchangeMode::LEGACY) {
				$this->authType = \Sabre\DAV\Client::AUTH_BASIC;
				$this->password = $this->legacyPassword;
				$this->tokenExpiresAt = 0;
			} elseif ($result->resolvedMode === TokenExchangeMode::EXCHANGE_OPTIONAL
				&& $result->accessToken !== null && $result->accessToken !== '') {
				$this->authType = BearerAuthAwareSabreClient::AUTH_BEARER;
				$this->password = $result->accessToken;
				$this->tokenExpiresAt = $result->accessTokenExpires ?? 0;
			} else {
				// Unresolved or exchange-optional without bearer: safe basic default.
				$this->authType = \Sabre\DAV\Client::AUTH_BASIC;
				$this->password = $this->legacyPassword;
				$this->tokenExpiresAt = 0;
			}
		}

		// Opportunistic exchange for exchange-optional with no stored bearer.
		if ($this->tokenExchangeMode === TokenExchangeMode::EXCHANGE_OPTIONAL
			&& empty($this->password)) {
			$exchangeResult = $this->exchangeHelper->exchange(
				$this->cloudId->getRemote(),
				$this->token,
			);
			if ($exchangeResult->outcome === ExchangeOutcome::Success) {
				$this->authType = BearerAuthAwareSabreClient::AUTH_BEARER;
				$this->password = $exchangeResult->accessToken;
				$this->tokenExpiresAt = $exchangeResult->accessTokenExpires ?? 0;
				$this->manager->updateAccessToken(
					$this->token,
					$exchangeResult->accessToken,
					$exchangeResult->accessTokenExpires ?? 0,
				);
			} else {
				$this->authType = \Sabre\DAV\Client::AUTH_BASIC;
				$this->password = $this->legacyPassword;
			}
		}

		// Bearer-empty guard: prevents parent::init() from entering its own
		// exchangeRefreshToken() path, which this class no longer uses.
		if ($this->authType === BearerAuthAwareSabreClient::AUTH_BEARER
			&& empty($this->password)) {
			throw new StorageNotAvailableException(
				'Bearer auth selected but no access token available'
			);
		}

		parent::init();
	}

	/**
	 * Reset DAV client state so the immediate retry closure sees fresh auth.
	 * Every refreshBearerToken() branch that returns true must call this.
	 */
	private function reinitializeAuthForRetry(): void {
		$this->ready = false;
		$this->client = null;
		$this->bearerToken = null;
		parent::init();
	}

	protected function refreshBearerToken(): bool {
		$now = time();

		if (!$this->forceTokenRefresh && $this->tokenExpiresAt > $now) {
			return false;
		}

		// Concurrent-refresh check: reuse a fresher DB token if available.
		$share = $this->manager->getShareByToken($this->token);
		if ($share !== false) {
			$dbExpiry = $share->getAccessTokenExpires();
			$dbToken = $share->getAccessToken();
			if ($dbExpiry !== null && $dbExpiry > $now && $dbToken !== null && $dbToken !== $this->password) {
				$this->password = $dbToken;
				$this->tokenExpiresAt = $dbExpiry;
				$this->refreshBackoffUntil = 0;
				$this->reinitializeAuthForRetry();
				$this->logger->debug('Reused access token refreshed by another process', ['app' => 'files_sharing']);
				return true;
			}
		}

		// Mode-aware backoff window.
		if ($this->refreshBackoffUntil > $now) {
			if ($this->tokenExchangeMode === TokenExchangeMode::EXCHANGE_REQUIRED) {
				throw new StorageNotAvailableException(
					'exchange-required share in backoff window after transient failure'
				);
			}
			$this->authType = \Sabre\DAV\Client::AUTH_BASIC;
			$this->password = $this->legacyPassword;
			$this->reinitializeAuthForRetry();
			return true;
		}

		$result = $this->exchangeHelper->exchange(
			$this->cloudId->getRemote(),
			$this->token,
		);

		// exchange-required: any non-success is fatal, no fallback.
		if ($this->tokenExchangeMode === TokenExchangeMode::EXCHANGE_REQUIRED) {
			if ($result->outcome !== ExchangeOutcome::Success) {
				throw new StorageNotAvailableException(
					'exchange-required share: token exchange failed (' . $result->outcome->value . ')'
				);
			}
			$this->password = $result->accessToken;
			$this->tokenExpiresAt = $result->accessTokenExpires ?? 0;
			$this->refreshBackoffUntil = 0;
			$this->manager->updateAccessToken($this->token, $result->accessToken, $result->accessTokenExpires ?? 0);
			$this->reinitializeAuthForRetry();
			$this->logger->debug('Refreshed bearer token for exchange-required share', ['app' => 'files_sharing']);
			return true;
		}

		// exchange-optional (and legacy/NULL if they somehow reach here).
		if ($result->outcome === ExchangeOutcome::Success) {
			$this->password = $result->accessToken;
			$this->tokenExpiresAt = $result->accessTokenExpires ?? 0;
			$this->refreshBackoffUntil = 0;
			$this->manager->updateAccessToken($this->token, $result->accessToken, $result->accessTokenExpires ?? 0);
			$this->reinitializeAuthForRetry();
			$this->logger->debug('Refreshed bearer token', ['app' => 'files_sharing']);
			return true;
		}

		if ($result->outcome === ExchangeOutcome::DefinitiveInvalidGrant
			|| $result->outcome === ExchangeOutcome::DefinitiveNoCapability) {
			$this->manager->updateModeAndClearBearer($this->token, TokenExchangeMode::LEGACY);
			$this->tokenExchangeMode = TokenExchangeMode::LEGACY;
			$this->authType = \Sabre\DAV\Client::AUTH_BASIC;
			$this->password = $this->legacyPassword;
			$this->tokenExpiresAt = 0;
			$this->reinitializeAuthForRetry();
			$this->logger->info('Downgraded to legacy after ' . $result->outcome->value, ['app' => 'files_sharing']);
			return true;
		}

		// Non-definitive outcomes: request-local basic fallback, no DB write.
		if ($result->outcome === ExchangeOutcome::TransientFailure
			|| $result->outcome === ExchangeOutcome::MalformedResponse) {
			$this->refreshBackoffUntil = $now + self::REFRESH_BACKOFF_SECONDS;
		}
		$this->authType = \Sabre\DAV\Client::AUTH_BASIC;
		$this->password = $this->legacyPassword;
		$this->reinitializeAuthForRetry();
		$this->logger->warning('Request-local basic fallback after ' . $result->outcome->value, ['app' => 'files_sharing']);
		return true;
	}

	public function getWatcher(string $path = '', ?IStorage $storage = null): IWatcher {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->watcher)) {
			$this->watcher = new Watcher($storage);
			$this->watcher->setPolicy(\OC\Files\Cache\Watcher::CHECK_ONCE);
		}
		return $this->watcher;
	}

	public function getRemoteUser(): string {
		return $this->cloudId->getUser();
	}

	public function getRemote(): string {
		return $this->cloudId->getRemote();
	}

	public function getMountPoint(): string {
		return $this->mountPoint;
	}

	public function getToken(): string {
		return $this->token;
	}

	public function getPassword(): ?string {
		return $this->password;
	}

	public function getId(): string {
		return 'shared::' . md5($this->token . '@' . $this->getRemote());
	}

	public function getCache(string $path = '', ?IStorage $storage = null): ICache {
		if (is_null($this->cache)) {
			$this->cache = new Cache($this, $this->cloudId);
		}
		return $this->cache;
	}

	public function getScanner(string $path = '', ?IStorage $storage = null): IScanner {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->scanner)) {
			$this->scanner = new Scanner($storage);
		}
		/** @var Scanner */
		return $this->scanner;
	}

	public function hasUpdated(string $path, int $time): bool {
		// since for owncloud webdav servers we can rely on etag propagation we only need to check the root of the storage
		// because of that we only do one check for the entire storage per request
		if ($this->updateChecked) {
			return false;
		}
		$this->updateChecked = true;
		try {
			return parent::hasUpdated('', $time);
		} catch (StorageInvalidException $e) {
			// check if it needs to be removed
			$this->checkStorageAvailability();
			throw $e;
		} catch (StorageNotAvailableException $e) {
			// check if it needs to be removed or just temp unavailable
			$this->checkStorageAvailability();
			throw $e;
		}
	}

	public function test(): bool {
		try {
			return parent::test();
		} catch (StorageInvalidException $e) {
			// check if it needs to be removed
			$this->checkStorageAvailability();
			throw $e;
		} catch (StorageNotAvailableException $e) {
			// check if it needs to be removed or just temp unavailable
			$this->checkStorageAvailability();
			throw $e;
		}
	}

	/**
	 * Check whether this storage is permanently or temporarily
	 * unavailable
	 *
	 * @throws StorageNotAvailableException
	 * @throws StorageInvalidException
	 */
	public function checkStorageAvailability(): void {
		// see if we can find out why the share is unavailable
		try {
			$this->getShareInfo(0);
		} catch (NotFoundException $e) {
			// a 404 can either mean that the share no longer exists or there is no Nextcloud on the remote
			if ($this->testRemote()) {
				// valid Nextcloud instance means that the public share no longer exists
				// since this is permanent (re-sharing the file will create a new token)
				// we remove the invalid storage
				$this->manager->removeShare($this->mountPoint);
				$this->manager->getMountManager()->removeMount($this->mountPoint);
				throw new StorageInvalidException('Remote share not found', 0, $e);
			} else {
				// Nextcloud instance is gone, likely to be a temporary server configuration error
				throw new StorageNotAvailableException('No nextcloud instance found at remote', 0, $e);
			}
		} catch (ForbiddenException $e) {
			// auth error, remove share for now (provide a dialog in the future)
			$this->manager->removeShare($this->mountPoint);
			$this->manager->getMountManager()->removeMount($this->mountPoint);
			throw new StorageInvalidException('Auth error when getting remote share');
		} catch (\GuzzleHttp\Exception\ConnectException $e) {
			throw new StorageNotAvailableException('Failed to connect to remote instance', 0, $e);
		} catch (\GuzzleHttp\Exception\RequestException $e) {
			throw new StorageNotAvailableException('Error while sending request to remote instance', 0, $e);
		}
	}

	public function file_exists(string $path): bool {
		if ($path === '') {
			return true;
		} else {
			return parent::file_exists($path);
		}
	}

	/**
	 * Check if the configured remote is a valid-federated share provider
	 */
	protected function testRemote(): bool {
		try {
			// OCM discovery and ownCloud/Nextcloud status probing answer different
			// questions, so validate them separately instead of treating any JSON
			// payload with a version field as a generic "remote is good" signal.
			return $this->testOCMRemoteUrl($this->getRemote() . '/ocm-provider/index.php')
				   || $this->testOCMRemoteUrl($this->getRemote() . '/ocm-provider/')
				   || $this->testOCMRemoteUrl($this->getRemote() . '/.well-known/ocm')
				   || $this->testOwnCloudStatusUrl($this->getRemote() . '/status.php');
		} catch (\Exception $e) {
			return false;
		}
	}

	private function testRemoteUrl(string $url, string $cacheKeyPrefix, \Closure $validator): bool {
		$cache = $this->memcacheFactory->createDistributed('files_sharing_remote_url');
		$cacheKey = $cacheKeyPrefix . ':' . $url;
		$cached = $cache->get($cacheKey);
		if ($cached !== null) {
			return (bool)$cached;
		}

		$client = $this->httpClient->newClient();
		try {
			$result = $client->get($url, $this->getDefaultRequestOptions())->getBody();
			$data = json_decode($result);
			$returnValue = $validator($data);
		} catch (ConnectException|ClientException|RequestException $e) {
			$returnValue = false;
			$this->logger->warning('Failed to test remote URL', ['exception' => $e]);
		}

		$cache->set($cacheKey, $returnValue, 60 * 60 * 24);
		return $returnValue;
	}

	private function testOCMRemoteUrl(string $url): bool {
		return $this->testRemoteUrl(
			$url,
			'ocm',
			static function (mixed $data): bool {
				// enabled:false still means the discovery document exists, but it must
				// not count as an active OCM endpoint for this remote.
				return is_object($data)
					&& (($data->enabled ?? null) === true)
					&& !empty($data->endPoint)
					&& is_array($data->resourceTypes ?? null);
			}
		);
	}

	private function testOwnCloudStatusUrl(string $url): bool {
		return $this->testRemoteUrl(
			$url,
			'status',
			static function (mixed $data): bool {
				if (!is_object($data) || empty($data->version)) {
					return false;
				}

				// shareinfo is a Nextcloud/ownCloud-specific branch. Reva exposes
				// status.php too, but should stay on the OCM path instead of being
				// treated as a shareinfo-capable ownCloud remote.
				$product = strtolower((string)($data->productname ?? $data->product ?? ''));
				if ($product === '') {
					// Keep backward compatibility with older ownCloud-style payloads
					// that only expose a version field.
					return true;
				}

				return in_array($product, ['nextcloud', 'owncloud'], true);
			}
		);
	}

	/**
	 * Check whether the remote is an ownCloud/Nextcloud. This is needed since some sharing
	 * features are not standardized.
	 *
	 * @throws LocalServerException
	 */
	public function remoteIsOwnCloud(): bool {
		if (defined('PHPUNIT_RUN') || !$this->testOwnCloudStatusUrl($this->getRemote() . '/status.php')) {
			return false;
		}
		return true;
	}

	/**
	 * @return mixed
	 * @throws ForbiddenException
	 * @throws NotFoundException
	 * @throws \Exception
	 */
	public function getShareInfo(int $depth = -1) {
		$remote = $this->getRemote();
		$token = $this->getToken();
		$password = $this->getPassword();

		try {
			// If remote is not an ownCloud do not try to get any share info
			if (!$this->remoteIsOwnCloud()) {
				return ['status' => 'unsupported'];
			}
		} catch (LocalServerException $e) {
			// throw this to be on the safe side: the share will still be visible
			// in the UI in case the failure is intermittent, and the user will
			// be able to decide whether to remove it if it's really gone
			throw new StorageNotAvailableException();
		}

		$url = rtrim($remote, '/') . '/index.php/apps/files_sharing/shareinfo?t=' . $token;

		// TODO: DI
		$client = Server::get(IClientService::class)->newClient();
		try {
			$response = $client->post($url, array_merge($this->getDefaultRequestOptions(), [
				'body' => ['password' => $password, 'depth' => $depth],
			]));
		} catch (\GuzzleHttp\Exception\RequestException $e) {
			$this->logger->warning('Failed to fetch share info', ['exception' => $e]);
			if ($e->getCode() === Http::STATUS_UNAUTHORIZED || $e->getCode() === Http::STATUS_FORBIDDEN) {
				throw new ForbiddenException();
			}
			if ($e->getCode() === Http::STATUS_NOT_FOUND) {
				throw new NotFoundException();
			}
			// throw this to be on the safe side: the share will still be visible
			// in the UI in case the failure is intermittent, and the user will
			// be able to decide whether to remove it if it's really gone
			throw new StorageNotAvailableException();
		}

		return json_decode($response->getBody(), true);
	}

	public function getOwner(string $path): string|false {
		return $this->cloudId->getDisplayId();
	}

	public function isSharable(string $path): bool {
		if ($this->shareManager->sharingDisabledForUser(Server::get(IUserSession::class)->getUser()?->getUID())
			|| !$this->appConfig->getValueBool('core', 'shareapi_allow_resharing', true)) {
			return false;
		}
		return (bool)($this->getPermissions($path) & Constants::PERMISSION_SHARE);
	}

	public function getPermissions(string $path): int {
		$response = $this->propfind($path);
		if ($response === false) {
			return 0;
		}

		$ocsPermissions = $response['{http://open-collaboration-services.org/ns}share-permissions'] ?? null;
		$ocmPermissions = $response['{http://open-cloud-mesh.org/ns}share-permissions'] ?? null;
		$ocPermissions = $response['{http://owncloud.org/ns}permissions'] ?? null;
		// old federated sharing permissions
		if ($ocsPermissions !== null) {
			$permissions = (int)$ocsPermissions;
		} elseif ($ocmPermissions !== null) {
			// permissions provided by the OCM API
			$permissions = $this->ocmPermissions2ncPermissions($ocmPermissions, $path);
		} elseif ($ocPermissions !== null) {
			return $this->parsePermissions($ocPermissions);
		} else {
			// use default permission if remote server doesn't provide the share permissions
			$permissions = $this->getDefaultPermissions($path);
		}

		return $permissions;
	}

	public function needsPartFile(): bool {
		return false;
	}

	/**
	 * Translate OCM Permissions to Nextcloud permissions
	 *
	 * @param string $ocmPermissions json encoded OCM permissions
	 * @param string $path path to file
	 * @return int
	 */
	protected function ocmPermissions2ncPermissions(string $ocmPermissions, string $path): int {
		try {
			$ocmPermissions = json_decode($ocmPermissions);
			$ncPermissions = 0;
			foreach ($ocmPermissions as $permission) {
				switch (strtolower($permission)) {
					case 'read':
						$ncPermissions += Constants::PERMISSION_READ;
						break;
					case 'write':
						$ncPermissions += Constants::PERMISSION_CREATE + Constants::PERMISSION_UPDATE;
						break;
					case 'share':
						$ncPermissions += Constants::PERMISSION_SHARE;
						break;
					default:
						throw new \Exception();
				}
			}
		} catch (\Exception $e) {
			$ncPermissions = $this->getDefaultPermissions($path);
		}

		return $ncPermissions;
	}

	/**
	 * Calculate the default permissions in case no permissions are provided
	 */
	protected function getDefaultPermissions(string $path): int {
		if ($this->is_dir($path)) {
			$permissions = Constants::PERMISSION_ALL;
		} else {
			$permissions = Constants::PERMISSION_ALL & ~Constants::PERMISSION_CREATE;
		}

		return $permissions;
	}

	public function free_space(string $path): int|float|false {
		return parent::free_space('');
	}

	private function getDefaultRequestOptions(): array {
		$options = [
			'timeout' => 10,
			'connect_timeout' => 10,
		];
		if ($this->config->getSystemValueBool('sharing.federation.allowSelfSignedCertificates')) {
			$options['verify'] = false;
		}
		return $options;
	}
}
