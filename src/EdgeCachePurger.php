<?php

namespace Glueful\Extensions\Cdn;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Cdn\Adapters\CDNAdapterInterface;
use Psr\Log\LoggerInterface;

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
     * The application context (used for logger resolution on failures)
     */
    private ApplicationContext $context;

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
     * @param ApplicationContext $context The application context (used for logging on adapter-resolution failures)
     * @param array<string, mixed> $config The `cdn` config block
     * @phpstan-param EdgeCacheConfig $config
     */
    public function __construct(ApplicationContext $context, array $config)
    {
        $this->context = $context;
        $this->config = $config;

        // No eager cache-store creation here: the purger never uses a local cache
        // store, and creating one in the constructor reached the framework's
        // CacheFactory and could fatal (e.g. `Class "Redis" not found`) when the
        // configured driver is unavailable — crashing container build. Boot must
        // stay safe; the only construction-time work is resolving the CDN adapter,
        // which itself degrades to disabled/no-op on any failure (never throws).
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
     * Implements the degrade-to-disabled contract: this method NEVER throws and
     * returns null on any of the following failure modes, leaving the purger
     * disabled (so every method no-ops exactly like a null edge cache):
     *
     *   (a) the configured provider is unset or empty;
     *   (b) the provider names a key absent from the `adapters` map;
     *   (c) the mapped class does not exist or is not a CDNAdapterInterface;
     *   (d) the adapter constructor throws.
     *
     * Failure modes (c) and (d) are logged as warnings; a missing/empty
     * provider (a) and an unknown provider (b) are normal "disabled"
     * configurations and are not logged.
     *
     * @return CDNAdapterInterface|null The resolved CDN adapter, or null if none could be resolved
     */
    private function resolveAdapter(): ?CDNAdapterInterface
    {
        // (a) No provider configured -> disabled (not an error).
        $provider = $this->config['provider'] ?? '';
        if ($provider === '') {
            return null;
        }

        // (b) Provider not present in the adapters map -> disabled (not an error).
        $adapters = $this->config['adapters'] ?? [];
        if (!isset($adapters[$provider])) {
            return null;
        }

        $adapterClass = $adapters[$provider];

        // (c) Mapped class missing -> degrade to disabled, log a warning.
        if (!class_exists($adapterClass)) {
            $this->logResolutionFailure(sprintf(
                "CDN adapter class '%s' for provider '%s' does not exist.",
                $adapterClass,
                $provider
            ));
            return null;
        }

        try {
            $adapter = new $adapterClass($this->config);
        } catch (\Throwable $e) {
            // (d) Constructor threw -> degrade to disabled, log a warning.
            $this->logResolutionFailure(sprintf(
                "CDN adapter '%s' for provider '%s' failed to construct: %s",
                $adapterClass,
                $provider,
                $e->getMessage()
            ));
            return null;
        }

        // (c) Constructed object is not a CDN adapter -> degrade to disabled, log.
        if (!$adapter instanceof CDNAdapterInterface) {
            $this->logResolutionFailure(sprintf(
                "CDN adapter class '%s' for provider '%s' does not implement %s.",
                $adapterClass,
                $provider,
                CDNAdapterInterface::class
            ));
            return null;
        }

        return $adapter;
    }

    /**
     * Log an adapter-resolution failure as a warning.
     *
     * Prefers a PSR-3 logger resolved from the container; falls back to
     * error_log when no logger is available (mirrors the framework's
     * EdgeCacheService failure-logging style).
     *
     * @param string $message The failure message
     */
    private function logResolutionFailure(string $message): void
    {
        try {
            $container = container($this->context);
            if ($container->has(LoggerInterface::class)) {
                $logger = $container->get(LoggerInterface::class);
                if ($logger instanceof LoggerInterface) {
                    $logger->warning('EdgeCachePurger: ' . $message);
                    return;
                }
            }
        } catch (\Throwable) {
            // Fall through to error_log below.
        }

        error_log('EdgeCachePurger: ' . $message);
    }
}
