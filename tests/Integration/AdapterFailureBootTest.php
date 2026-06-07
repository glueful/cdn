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
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Adapter whose constructor always throws (failure mode d).
 */
final class BootThrowingAdapter extends AbstractCDNAdapter
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        unset($config);
        throw new \RuntimeException('boom: adapter construction failed');
    }

    public function getProviderName(): string
    {
        return 'throwing';
    }
}

/**
 * A class that does NOT implement CDNAdapterInterface (failure mode d, variant).
 */
final class BootNotAnAdapter
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        unset($config);
    }
}

/**
 * In-memory PSR-3 logger that records warning messages.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }

    public function hasWarning(): bool
    {
        foreach ($this->records as $r) {
            if ($r['level'] === 'warning') {
                return true;
            }
        }
        return false;
    }
}

/**
 * Re-proves the four adapter-resolution failure modes at the container/provider
 * level (they are unit-covered directly on EdgeCachePurger in
 * tests/Unit/AdapterResolutionTest.php).
 *
 * Each mode is driven by seeding `cdn` config and resolving EdgeCacheInterface
 * through CdnServiceProvider's factory. The container must boot/resolve without
 * throwing and yield an inert, disabled purger.
 */
final class AdapterFailureBootTest extends TestCase
{
    /**
     * Build a container with core's null binding + CDN provider defs applied
     * after, an ApplicationContext seeded with the given `cdn` config, and a
     * spy logger so resolution-failure warnings can be asserted.
     *
     * @param array<string, mixed> $cdnConfig
     * @return array{0: Container, 1: SpyLogger}
     */
    private function boot(array $cdnConfig): array
    {
        $context = new ApplicationContext(sys_get_temp_dir(), 'testing');
        $context->mergeConfigDefaults('cdn', $cdnConfig);

        $logger = new SpyLogger();

        $container = new Container([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
            LoggerInterface::class => new ValueDefinition(LoggerInterface::class, $logger),
            EdgeCacheInterface::class => new ValueDefinition(
                EdgeCacheInterface::class,
                new NullEdgeCache()
            ),
        ]);
        $context->setContainer($container);

        // CDN provider applied last (last-provider-wins).
        $container->load(CdnServiceProvider::services());

        return [$container, $logger];
    }

    /**
     * @param array<string, mixed> $cdnConfig
     */
    private function assertBootsInertAndDisabled(array $cdnConfig): EdgeCachePurger
    {
        [$container] = $this->boot($cdnConfig);

        // Must not throw on resolve.
        $purger = $container->get(EdgeCacheInterface::class);

        self::assertInstanceOf(EdgeCachePurger::class, $purger);
        self::assertFalse($purger->isEnabled(), 'failed adapter resolution must leave the purger disabled');
        self::assertSame([], $purger->generateCacheHeaders('home', 'text/html'));
        self::assertFalse($purger->purgeUrl('https://example.com/a'));
        self::assertFalse($purger->purgeByTag('posts'));
        self::assertFalse($purger->purgeAll());

        return $purger;
    }

    /** (a) provider unset/empty. */
    public function testModeAEmptyProviderBootsDisabled(): void
    {
        $this->assertBootsInertAndDisabled([
            'enabled' => true,
            'provider' => '',
            'adapters' => [],
        ]);

        // Provider key absent entirely.
        $this->assertBootsInertAndDisabled([
            'enabled' => true,
            'adapters' => [],
        ]);
    }

    /** (b) unknown provider key. */
    public function testModeBUnknownProviderBootsDisabled(): void
    {
        $this->assertBootsInertAndDisabled([
            'enabled' => true,
            'provider' => 'cloudflare',
            'adapters' => [],
        ]);
    }

    /** (c) provider mapped to a missing class -> disabled + warning. */
    public function testModeCMissingClassBootsDisabledAndWarns(): void
    {
        $config = [
            'enabled' => true,
            'provider' => 'ghost',
            'adapters' => ['ghost' => 'Glueful\\Extensions\\Cdn\\Tests\\Integration\\NoSuchAdapterClass'],
        ];

        [$container, $logger] = $this->boot($config);
        $purger = $container->get(EdgeCacheInterface::class);

        self::assertInstanceOf(EdgeCachePurger::class, $purger);
        self::assertFalse($purger->isEnabled());
        self::assertSame([], $purger->generateCacheHeaders('home', 'text/html'));
        self::assertFalse($purger->purgeUrl('https://example.com/a'));
        self::assertFalse($purger->purgeByTag('posts'));
        self::assertFalse($purger->purgeAll());
        self::assertTrue($logger->hasWarning(), 'missing adapter class must log a warning via the container logger');
    }

    /** (d) provider mapped to a non-adapter / throwing-ctor class -> disabled + warning. */
    public function testModeDInvalidAdapterBootsDisabledAndWarns(): void
    {
        // Non-CDNAdapterInterface class.
        [$container, $logger] = $this->boot([
            'enabled' => true,
            'provider' => 'notadapter',
            'adapters' => ['notadapter' => BootNotAnAdapter::class],
        ]);
        $purger = $container->get(EdgeCacheInterface::class);
        self::assertInstanceOf(EdgeCachePurger::class, $purger);
        self::assertFalse($purger->isEnabled());
        self::assertFalse($purger->purgeAll());
        self::assertTrue($logger->hasWarning(), 'non-adapter class must log a warning');

        // Throwing constructor.
        [$container2, $logger2] = $this->boot([
            'enabled' => true,
            'provider' => 'throwing',
            'adapters' => ['throwing' => BootThrowingAdapter::class],
        ]);
        $purger2 = $container2->get(EdgeCacheInterface::class);
        self::assertInstanceOf(EdgeCachePurger::class, $purger2);
        self::assertFalse($purger2->isEnabled());
        self::assertFalse($purger2->purgeAll());
        self::assertTrue($logger2->hasWarning(), 'throwing adapter ctor must log a warning');
    }
}
