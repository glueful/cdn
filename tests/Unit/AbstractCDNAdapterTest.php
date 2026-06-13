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

final class HeaderBag
{
    /** @param array<string, string> $headers */
    public function __construct(private array $headers = [])
    {
    }

    public function get(string $key, string $default = ''): string
    {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, $key) === 0) {
                return $value;
            }
        }

        return $default;
    }
}

final class CacheRequest
{
    public HeaderBag $headers;

    /** @param array<string, string> $headers */
    public function __construct(private string $method = 'GET', array $headers = [])
    {
        $this->headers = new HeaderBag($headers);
    }

    public function method(): string
    {
        return $this->method;
    }
}

final class CacheResponse
{
    public HeaderBag $headers;

    /** @param array<string, string> $headers */
    public function __construct(private int $statusCode = 200, array $headers = [])
    {
        $this->headers = new HeaderBag($headers);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
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

    public function testAuthenticatedRequestsAreNotCacheableByDefault(): void
    {
        $adapter = new RouteRuleAdapter();

        self::assertFalse($adapter->isCacheable(
            new CacheRequest(headers: ['Authorization' => 'Bearer token']),
            new CacheResponse()
        ));
        self::assertFalse($adapter->isCacheable(
            new CacheRequest(headers: ['Cookie' => 'session=abc']),
            new CacheResponse()
        ));
    }

    public function testCookieSettingResponsesAreNotCacheableByDefault(): void
    {
        $adapter = new RouteRuleAdapter();

        self::assertFalse($adapter->isCacheable(
            new CacheRequest(),
            new CacheResponse(headers: ['Set-Cookie' => 'session=abc; HttpOnly'])
        ));
    }
}
