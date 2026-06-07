<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Cdn\Adapters\AbstractCDNAdapter;
use Glueful\Extensions\Cdn\EdgeCachePurger;
use PHPUnit\Framework\TestCase;

/**
 * Adapter whose constructor always throws (failure mode d).
 */
final class ThrowingAdapter extends AbstractCDNAdapter
{
    /**
     * @param array<string, mixed> $config
     */
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
 * A class that does NOT implement CDNAdapterInterface (failure mode c).
 */
final class NotAnAdapter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        unset($config);
    }
}

/**
 * Working adapter used for the happy path.
 */
final class ResolvingStubAdapter extends AbstractCDNAdapter
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
 * Exercises the degrade-to-disabled contract of EdgeCachePurger::resolveAdapter().
 *
 * Each failure mode (a-d) must yield an inert, disabled purger that never throws.
 */
final class AdapterResolutionTest extends TestCase
{
    private function context(): ApplicationContext
    {
        return new ApplicationContext(sys_get_temp_dir(), 'testing');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertInertAndDisabled(array $config): void
    {
        $purger = new EdgeCachePurger($this->context(), $config);

        self::assertFalse($purger->isEnabled());
        self::assertNull($purger->getCDNAdapter());
        self::assertNull($purger->getProvider());
        self::assertSame([], $purger->generateCacheHeaders('home', 'text/html'));
        self::assertFalse($purger->purgeUrl('https://example.com/a'));
        self::assertFalse($purger->purgeByTag('posts'));
        self::assertFalse($purger->purgeAll());
    }

    /**
     * Mode (a): provider unset/empty.
     */
    public function testModeAEmptyProviderDegradesToDisabled(): void
    {
        // Empty string provider.
        $this->assertInertAndDisabled([
            'enabled' => true,
            'provider' => '',
            'adapters' => ['stub' => ResolvingStubAdapter::class],
        ]);

        // Provider key entirely absent.
        $this->assertInertAndDisabled([
            'enabled' => true,
            'adapters' => ['stub' => ResolvingStubAdapter::class],
        ]);
    }

    /**
     * Mode (b): provider names a key absent from the adapters map.
     */
    public function testModeBUnknownProviderDegradesToDisabled(): void
    {
        $this->assertInertAndDisabled([
            'enabled' => true,
            'provider' => 'cloudflare',
            'adapters' => ['stub' => ResolvingStubAdapter::class],
        ]);

        // Empty adapters map.
        $this->assertInertAndDisabled([
            'enabled' => true,
            'provider' => 'cloudflare',
            'adapters' => [],
        ]);
    }

    /**
     * Mode (c): mapped class is missing or not a CDNAdapterInterface.
     */
    public function testModeCInvalidAdapterClassDegradesToDisabled(): void
    {
        // Class does not exist.
        $this->assertInertAndDisabled([
            'enabled' => true,
            'provider' => 'ghost',
            'adapters' => ['ghost' => 'Glueful\\Extensions\\Cdn\\Tests\\Unit\\NoSuchAdapterClass'],
        ]);

        // Class exists but is not a CDNAdapterInterface.
        $this->assertInertAndDisabled([
            'enabled' => true,
            'provider' => 'notadapter',
            'adapters' => ['notadapter' => NotAnAdapter::class],
        ]);
    }

    /**
     * Mode (d): adapter constructor throws.
     */
    public function testModeDThrowingConstructorDegradesToDisabled(): void
    {
        $this->assertInertAndDisabled([
            'enabled' => true,
            'provider' => 'throwing',
            'adapters' => ['throwing' => ThrowingAdapter::class],
        ]);
    }

    /**
     * Happy path: a valid provider + adapters map yields a working adapter.
     */
    public function testHappyPathResolvesWorkingAdapter(): void
    {
        $purger = new EdgeCachePurger($this->context(), [
            'enabled' => true,
            'provider' => 'stub',
            'adapters' => ['stub' => ResolvingStubAdapter::class],
        ]);

        self::assertTrue($purger->isEnabled());
        self::assertSame('stub', $purger->getProvider());
        self::assertInstanceOf(ResolvingStubAdapter::class, $purger->getCDNAdapter());
        self::assertSame(['X-Stub' => 'home'], $purger->generateCacheHeaders('home', 'text/html'));
        self::assertTrue($purger->purgeUrl('https://example.com/a'));
        self::assertTrue($purger->purgeByTag('posts'));
        self::assertTrue($purger->purgeAll());
    }
}
