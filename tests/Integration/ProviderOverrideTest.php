<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Cache\NullEdgeCache;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Extensions\Cdn\Adapters\AbstractCDNAdapter;
use Glueful\Extensions\Cdn\CdnServiceProvider;
use Glueful\Extensions\Cdn\EdgeCachePurger;
use PHPUnit\Framework\TestCase;

/**
 * A working adapter so the override resolves to an *enabled* purger.
 */
final class OverrideStubAdapter extends AbstractCDNAdapter
{
    public function getProviderName(): string
    {
        return 'stub';
    }

    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        return ['X-Stub' => $route];
    }

    public function purgeUrl(string $url): bool
    {
        return true;
    }

    public function purgeByTag(string $tag): bool
    {
        return true;
    }

    public function purgeAll(): bool
    {
        return true;
    }
}

/**
 * Proves the CDN extension's provider definitions win over core's default
 * EdgeCacheInterface => NullEdgeCache binding (last-provider-wins), exactly as
 * they would when `glueful/cdn` is installed and its provider is applied after
 * CoreProvider.
 *
 * Harness: build the real framework Container, load core's null binding FIRST,
 * then load CdnServiceProvider::defs() AFTER. Container::load() overwrites
 * by id, mirroring provider-registration order.
 */
final class ProviderOverrideTest extends TestCase
{
    private function context(): ApplicationContext
    {
        $context = new ApplicationContext(sys_get_temp_dir(), 'testing');
        // Seed a working `cdn` config so the factory builds an enabled purger.
        $context->mergeConfigDefaults('cdn', [
            'enabled' => true,
            'provider' => 'stub',
            'adapters' => ['stub' => OverrideStubAdapter::class],
        ]);
        return $context;
    }

    private function container(ApplicationContext $context): Container
    {
        // 1. Core binding: EdgeCacheInterface => NullEdgeCache (the no-op default).
        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            EdgeCacheInterface::class => new ValueDefinition(
                EdgeCacheInterface::class,
                new NullEdgeCache()
            ),
        ]);
        $context->setContainer($container);

        // 2. CDN provider definitions applied AFTER core (last-provider-wins).
        $container->load(CdnServiceProvider::defs());

        return $container;
    }

    public function testCdnProviderOverridesCoreNullBinding(): void
    {
        $context = $this->context();
        $container = $this->container($context);

        $resolved = $container->get(EdgeCacheInterface::class);

        self::assertInstanceOf(
            EdgeCachePurger::class,
            $resolved,
            'CDN provider applied last must override core NullEdgeCache'
        );
        self::assertNotInstanceOf(NullEdgeCache::class, $resolved);
        // The override resolved a live, enabled purger off the seeded config.
        self::assertTrue($resolved->isEnabled());
        self::assertSame('stub', $resolved->getProvider());
    }

    public function testInterfaceAndConcreteShareTheSameInstance(): void
    {
        $context = $this->context();
        $container = $this->container($context);

        $viaInterface = $container->get(EdgeCacheInterface::class);
        $viaConcrete = $container->get(EdgeCachePurger::class);

        // FactoryDefinition is shared + EdgeCachePurger is an alias to the interface,
        // so both ids must resolve to the identical instance.
        self::assertSame(
            $viaInterface,
            $viaConcrete,
            'EdgeCachePurger alias + shared factory must yield one shared instance'
        );
    }
}
