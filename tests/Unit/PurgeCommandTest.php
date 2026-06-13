<?php

declare(strict_types=1);

namespace Glueful\Extensions\Cdn\Tests\Unit;

use Glueful\Extensions\Cdn\Console\PurgeCommand;
use PHPUnit\Framework\TestCase;

final class PurgeCommandTest extends TestCase
{
    public function testCommandDoesNotExposeUnimplementedProviderOrTimeoutOptions(): void
    {
        $definition = (new PurgeCommand())->getDefinition();

        self::assertTrue($definition->hasOption('batch-file'));
        self::assertTrue($definition->hasOption('dry-run'));
        self::assertTrue($definition->hasOption('force'));
        self::assertFalse($definition->hasOption('provider'));
        self::assertFalse($definition->hasOption('timeout'));
    }
}
