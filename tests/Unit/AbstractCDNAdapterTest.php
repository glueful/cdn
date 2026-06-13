<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn\Tests\Unit;

use Glueful\Extensions\Cdn\Adapters\AbstractCDNAdapter;
use PHPUnit\Framework\TestCase;

final class RouteRuleAdapter extends AbstractCDNAdapter
{
    public function getProviderName(): string
    {
        return 'route-rule';
    }
}

final class AbstractCDNAdapterTest extends TestCase
{
    public function testWildcardRouteRulesEscapeRegexMetacharacters(): void
    {
        $adapter = new RouteRuleAdapter([
            'default_ttl' => 60,
            'rules' => [
                'admin.*' => ['ttl' => 600],
            ],
        ]);

        self::assertSame(
            'public, max-age=600',
            $adapter->generateCacheHeaders('admin.v1')['Cache-Control']
        );
        self::assertSame(
            'public, max-age=60',
            $adapter->generateCacheHeaders('adminXv1')['Cache-Control']
        );
    }
}
