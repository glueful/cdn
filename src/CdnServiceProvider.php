<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn;

use Glueful\Bootstrap\ApplicationContext;

final class CdnServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
    }

    public function boot(ApplicationContext $context): void
    {
    }
}
