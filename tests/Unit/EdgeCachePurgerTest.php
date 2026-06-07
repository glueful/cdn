<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Cdn\Adapters\AbstractCDNAdapter;
use Glueful\Extensions\Cdn\EdgeCachePurger;
use PHPUnit\Framework\TestCase;

/**
 * Recording stub adapter used to assert delegation from the purger.
 */
final class StubAdapter extends AbstractCDNAdapter
{
    /** @var list<string> */
    public array $purgedUrls = [];
    /** @var list<string> */
    public array $purgedTags = [];
    public int $purgeAllCalls = 0;

    public function getProviderName(): string
    {
        return 'stub';
    }

    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        return ['X-Stub' => $route . '|' . (string) $contentType];
    }

    public function purgeUrl(string $url): bool
    {
        $this->purgedUrls[] = $url;
        return true;
    }

    public function purgeByTag(string $tag): bool
    {
        $this->purgedTags[] = $tag;
        return true;
    }

    public function purgeAll(): bool
    {
        $this->purgeAllCalls++;
        return true;
    }
}

final class EdgeCachePurgerTest extends TestCase
{
    private function context(): ApplicationContext
    {
        return new ApplicationContext(sys_get_temp_dir(), 'testing');
    }

    public function testEnabledPurgerResolvesAdapterAndReportsProvider(): void
    {
        $purger = new EdgeCachePurger($this->context(), [
            'enabled' => true,
            'provider' => 'stub',
            'adapters' => ['stub' => StubAdapter::class],
        ]);

        self::assertTrue($purger->isEnabled());
        self::assertSame('stub', $purger->getProvider());
        self::assertInstanceOf(StubAdapter::class, $purger->getCDNAdapter());
    }

    public function testEnabledPurgerDelegatesHeaderGeneration(): void
    {
        $purger = new EdgeCachePurger($this->context(), [
            'enabled' => true,
            'provider' => 'stub',
            'adapters' => ['stub' => StubAdapter::class],
        ]);

        self::assertSame(
            ['X-Stub' => 'home|text/html'],
            $purger->generateCacheHeaders('home', 'text/html')
        );
    }

    public function testEnabledPurgerDelegatesPurges(): void
    {
        $purger = new EdgeCachePurger($this->context(), [
            'enabled' => true,
            'provider' => 'stub',
            'adapters' => ['stub' => StubAdapter::class],
        ]);

        /** @var StubAdapter $adapter */
        $adapter = $purger->getCDNAdapter();

        self::assertTrue($purger->purgeUrl('https://example.com/a'));
        self::assertTrue($purger->purgeByTag('posts'));
        self::assertTrue($purger->purgeAll());

        self::assertSame(['https://example.com/a'], $adapter->purgedUrls);
        self::assertSame(['posts'], $adapter->purgedTags);
        self::assertSame(1, $adapter->purgeAllCalls);
    }

    public function testDisabledPurgerIsInert(): void
    {
        $purger = new EdgeCachePurger($this->context(), ['enabled' => false]);

        self::assertFalse($purger->isEnabled());
        self::assertNull($purger->getProvider());
        self::assertSame([], $purger->generateCacheHeaders('home', 'text/html'));
        self::assertFalse($purger->purgeUrl('https://example.com/a'));
        self::assertFalse($purger->purgeByTag('posts'));
        self::assertFalse($purger->purgeAll());
    }
}
