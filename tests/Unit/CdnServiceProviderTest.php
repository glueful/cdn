<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Extensions\Cdn\CdnServiceProvider;
use Glueful\Extensions\Cdn\EdgeCachePurger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class CdnServiceProviderTest extends TestCase
{
    public function testServicesRegistersEdgeCacheInterfaceFactory(): void
    {
        $services = CdnServiceProvider::services();

        self::assertArrayHasKey(EdgeCacheInterface::class, $services);
        self::assertInstanceOf(FactoryDefinition::class, $services[EdgeCacheInterface::class]);
    }

    public function testServicesAliasesConcretePurgerToInterface(): void
    {
        $services = CdnServiceProvider::services();

        self::assertArrayHasKey(EdgeCachePurger::class, $services);

        $alias = $services[EdgeCachePurger::class];
        self::assertInstanceOf(AliasDefinition::class, $alias);
        self::assertSame(EdgeCacheInterface::class, $alias->getTarget());
    }

    public function testFactoryDefinitionResolvesToEdgeCachePurger(): void
    {
        $services = CdnServiceProvider::services();
        /** @var FactoryDefinition $factory */
        $factory = $services[EdgeCacheInterface::class];

        $context = new ApplicationContext(sys_get_temp_dir(), 'testing');
        $container = $this->containerWith([ApplicationContext::class => $context]);

        $resolved = $factory->resolve($container);

        self::assertInstanceOf(EdgeCachePurger::class, $resolved);
        self::assertInstanceOf(EdgeCacheInterface::class, $resolved);
    }

    /**
     * Minimal PSR-11 container backed by an id => instance map.
     *
     * @param array<string, object> $entries
     */
    private function containerWith(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /** @param array<string, object> $entries */
            public function __construct(private array $entries)
            {
            }

            public function get(string $id): mixed
            {
                return $this->entries[$id] ?? throw new class ('Not found: ' . $id) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
    }
}
