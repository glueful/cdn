<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Psr\Container\ContainerInterface;

final class CdnServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    /**
     * @return array<string, mixed>
     */
    public static function defs(): array
    {
        return [
            EdgeCacheInterface::class => new FactoryDefinition(
                EdgeCacheInterface::class,
                static function (ContainerInterface $c): EdgeCachePurger {
                    $context = $c->get(ApplicationContext::class);
                    return new EdgeCachePurger($context, (array) config($context, 'cdn', []));
                },
                true,
            ),
            EdgeCachePurger::class => new AliasDefinition(
                EdgeCachePurger::class,
                EdgeCacheInterface::class,
            ),
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('cdn', require __DIR__ . '/../config/cdn.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $this->discoverCommands('Glueful\\Extensions\\Cdn\\Console', __DIR__ . '/Console');
    }
}
