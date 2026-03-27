<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OC\Federation;

use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ICloudIdManager;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use Psr\Log\LoggerInterface;

class CloudFederationFactory implements ICloudFederationFactory {
	public function __construct(
		private IOCMDiscoveryService $ocmDiscoveryService,
		private ICloudIdManager $cloudIdManager,
		private LoggerInterface $logger,
	) {
	}
	/**
	 * get a CloudFederationShare Object to prepare a share you want to send
	 *
	 * @param string $shareWith
	 * @param string $name resource name (e.g. document.odt)
	 * @param string $description share description (optional)
	 * @param string $providerId resource UID on the provider side
	 * @param string $owner provider specific UID of the user who owns the resource
	 * @param string $ownerDisplayName display name of the user who shared the item
	 * @param string $sharedBy provider specific UID of the user who shared the resource
	 * @param string $sharedByDisplayName display name of the user who shared the resource
	 * @param string $sharedSecret used to authenticate requests across servers
	 * @param string $shareType ('group' or 'user' share)
	 * @param $resourceType ('file', 'calendar',...)
	 * @param int|null $permissions share permission bitmask for outgoing shares
	 * @return ICloudFederationShare
	 *
	 * @since 14.0.0
	 */
	public function getCloudFederationShare($shareWith, $name, $description, $providerId, $owner, $ownerDisplayName, $sharedBy, $sharedByDisplayName, $sharedSecret, $shareType, $resourceType, $permissions = null) {
		$useExchangeToken = false;
		$remoteDomain = null;
		// OCM 1.1 exchange-token shares advertise the sender's own DAV endpoint.
		// Keep that URL sender-owned instead of reconstructing it from the remote.
		$senderWebdavUri = $this->getLocalProtocolEndpoint('file', 'webdav');

		try {
			$cloudId = $this->cloudIdManager->resolveCloudId($shareWith);
			$remoteDomain = $cloudId->getRemote();

			try {
				$remoteProvider = $this->ocmDiscoveryService->discover($remoteDomain);
				$capabilities = $remoteProvider->getCapabilities();

				$useExchangeToken = in_array('exchange-token', $capabilities, true);
				// Exchange-token shares need a concrete sender DAV endpoint in the
				// outgoing payload. If local discovery cannot provide one, keep the
				// legacy share flow instead of sending a broken OCM 1.1 payload.
				if ($useExchangeToken && $senderWebdavUri === null) {
					$useExchangeToken = false;
					$this->logger->warning('Missing local webdav endpoint, falling back to legacy share method', [
						'remote' => $remoteDomain,
					]);
				}

				$this->logger->debug('OCM provider capabilities discovered', [
					'remote' => $remoteDomain,
					'capabilities' => $capabilities,
					'useExchangeToken' => $useExchangeToken,
				]);
			} catch (OCMProviderException $e) {
				$this->logger->warning('Failed to discover OCM provider, using legacy share method', [
					'remote' => $remoteDomain,
					'exception' => $e->getMessage(),
				]);
			}
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning('Invalid cloud ID format, using legacy share method', [
				'shareWith' => $shareWith,
				'exception' => $e->getMessage(),
			]);
		}

		return new CloudFederationShare(
			$shareWith,
			$name,
			$description,
			$providerId,
			$owner,
			$ownerDisplayName,
			$sharedBy,
			$sharedByDisplayName,
			$shareType,
			$resourceType,
			$sharedSecret,
			$useExchangeToken,
			$permissions,
			$senderWebdavUri
		);
	}

	/**
	 * get a Cloud FederationNotification object to prepare a notification you
	 * want to send
	 *
	 * @return ICloudFederationNotification
	 *
	 * @since 14.0.0
	 */
	public function getCloudFederationNotification() {
		return new CloudFederationNotification();
	}

	/**
	 * Resolve a locally advertised OCM protocol path into the absolute URL that
	 * remote receivers should use.
	 */
	private function getLocalProtocolEndpoint(string $resourceType, string $protocolName): ?string {
		$provider = $this->ocmDiscoveryService->getLocalOCMProvider(false);
		foreach ($provider->getResourceTypes() as $resource) {
			if ($resource->getName() !== $resourceType) {
				continue;
			}

			$protocols = $resource->getProtocols();
			$protocolPath = $protocols[$protocolName] ?? null;
			if (!is_string($protocolPath) || $protocolPath === '') {
				continue;
			}

			return $this->buildAbsoluteProtocolUrl($provider->getEndPoint(), $protocolPath);
		}

		return null;
	}

	/**
	 * Local discovery advertises protocol roots as relative paths. Preserve the
	 * app prefix from the discovered endpoint so subdirectory installs keep
	 * `/nextcloud/...` style paths when those roots are sent to other servers.
	 */
	private function buildAbsoluteProtocolUrl(string $endpoint, string $protocolPath): string {
		if (preg_match('/^https?:\/\//i', $protocolPath) === 1) {
			return $protocolPath;
		}

		$parts = parse_url($endpoint);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return $protocolPath;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if (isset($parts['port'])) {
			$origin .= ':' . $parts['port'];
		}

		$endpointPath = $parts['path'] ?? '';
		if ($endpointPath === '' || $endpointPath === '/') {
			$appPath = '';
		} else {
			$normalizedEndpointPath = rtrim($endpointPath, '/');
			$lastSeparator = strrpos($normalizedEndpointPath, '/');
			$appPath = $lastSeparator > 0 ? substr($normalizedEndpointPath, 0, $lastSeparator) : '';
		}

		return $origin . $appPath . '/' . ltrim($protocolPath, '/');
	}
}
