<?php

namespace Glueful\Extensions\Cdn;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Cdn\Adapters\CDNAdapterInterface;
use Glueful\Helpers\CacheHelper;

/**
 * Edge caching service for the CDN extension.
 *
 * Implements the framework's {@see \Glueful\Cache\Contracts\EdgeCacheInterface}
 * core seam. Provides integration with Content Delivery Networks (CDNs)
 * through a pluggable adapter system. CDN adapters are resolved from the
 * extension's injected configuration (provider name + adapter map).
 *
 * @phpstan-type EdgeCacheConfig array{
 *   enabled?: bool,
 *   provider?: string,
 *   default_ttl?: int,
 *   headers?: array<string, string>,
 *   adapters?: array<string, class-string>
 * }
 *
 * @package Glueful\Extensions\Cdn
 */
final class EdgeCachePurger implements \Glueful\Cache\Contracts\EdgeCacheInterface
{
    /**
     * The cache store instance
     *
     * @var CacheStore<mixed>|null
     */
    private ?CacheStore $cacheStore = null;

    /**
     * The CDN adapter instance
     *
     * @var CDNAdapterInterface|null
     */
    private $cdnAdapter;

    /**
     * The edge cache configuration
     *
     * @var array<string, mixed>
     * @phpstan-var EdgeCacheConfig
     */
    private $config;

    /**
     * Constructor for the Edge Cache Purger
     *
     * @param ApplicationContext $context The application context (reserved for adapter resolution)
     * @param array<string, mixed> $config The `cdn` config block
     * @phpstan-param EdgeCacheConfig $config
     */
    public function __construct(ApplicationContext $context, array $config)
    {
        unset($context);
        $this->config = $config;

        // Resolve the cache store via the helper fallback.
        $this->cacheStore = CacheHelper::createCacheInstance();
        if ($this->cacheStore === null) {
            // Log but continue - edge cache service might work without local cache
            error_log('EdgeCachePurger: Could not create CacheStore instance');
        }

        // Resolve the CDN adapter from configuration.
        $this->cdnAdapter = $this->resolveAdapter();
    }

    /**
     * Generate cache control headers for edge caching
     *
     * @param string $route The route name
     * @param string|null $contentType The content type of the response
     * @return array<string, string> The cache headers
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return [];
        }

        return $this->cdnAdapter->generateCacheHeaders($route, $contentType);
    }

    /**
     * Purge a specific URL from the CDN cache
     *
     * @param string $url The URL to purge
     * @return bool True if the purge was successful, false otherwise
     */
    public function purgeUrl(string $url): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->purgeUrl($url);
    }

    /**
     * Purge content by cache tag
     *
     * @param string $tag The cache tag to purge
     * @return bool True if the purge was successful, false otherwise
     */
    public function purgeByTag(string $tag): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->purgeByTag($tag);
    }

    /**
     * Purge all content from the CDN cache
     *
     * @return bool True if the purge was successful, false otherwise
     */
    public function purgeAll(): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->purgeAll();
    }

    /**
     * Get cache statistics from the CDN
     *
     * @return array<string, mixed> The cache statistics
     */
    public function getStats(): array
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return [
                'enabled' => false,
                'provider' => null
            ];
        }

        return $this->cdnAdapter->getStats();
    }

    /**
     * Check if content is cacheable based on request and response
     *
     * @param object $request The request object
     * @param object $response The response object
     * @return bool True if the content is cacheable, false otherwise
     */
    public function isCacheable(object $request, object $response): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->isCacheable($request, $response);
    }

    /**
     * Get the configured CDN provider
     *
     * @return string|null The name of the CDN provider, or null if none is configured
     */
    public function getProvider(): ?string
    {
        if ($this->cdnAdapter === null) {
            return null;
        }

        return $this->cdnAdapter->getProviderName();
    }

    /**
     * Check if edge caching is enabled
     *
     * @return bool True if edge caching is enabled, false otherwise
     */
    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? false) && $this->cdnAdapter !== null;
    }

    /**
     * Get the current CDN adapter
     *
     * @return CDNAdapterInterface|null The current CDN adapter, or null if none is configured
     */
    public function getCDNAdapter(): ?CDNAdapterInterface
    {
        return $this->cdnAdapter;
    }

    /**
     * Set a CDN adapter
     *
     * @param CDNAdapterInterface $adapter The CDN adapter to use
     * @return self
     */
    public function setCDNAdapter(CDNAdapterInterface $adapter): self
    {
        $this->cdnAdapter = $adapter;
        return $this;
    }

    /**
     * Resolve a CDN adapter from the injected configuration.
     *
     * Basic resolution: read the configured provider name, look it up in the
     * `adapters` name->class map, and instantiate the class when it exists.
     * A missing provider, missing map entry, or missing class yields null
     * (never throws). Hardened resolution (try/catch, instanceof checks, the
     * degrade-to-disabled failure modes and logging) is handled in a later task.
     *
     * @return CDNAdapterInterface|null The resolved CDN adapter, or null if none could be resolved
     */
    private function resolveAdapter(): ?CDNAdapterInterface
    {
        $provider = $this->config['provider'] ?? '';
        if ($provider === '') {
            return null;
        }

        $adapters = $this->config['adapters'] ?? [];
        if (!isset($adapters[$provider])) {
            return null;
        }

        $adapterClass = $adapters[$provider];
        if (!class_exists($adapterClass)) {
            return null;
        }

        /** @var CDNAdapterInterface $adapter */
        $adapter = new $adapterClass($this->config);

        return $adapter;
    }
}
