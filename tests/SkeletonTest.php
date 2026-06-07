<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn\Tests;

use Glueful\Extensions\Cdn\CdnServiceProvider;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;

final class SkeletonTest extends TestCase
{
    public function testProviderExistsAndIsAServiceProvider(): void
    {
        self::assertTrue(class_exists(CdnServiceProvider::class));
        self::assertTrue(is_subclass_of(CdnServiceProvider::class, ServiceProvider::class));
    }
}
