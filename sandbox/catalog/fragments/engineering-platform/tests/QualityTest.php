<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Quality;
use PHPUnit\Framework\TestCase;

final class QualityTest extends TestCase
{
    public function testShellRatchetIsExplicitAndNonempty(): void
    {
        $root = dirname(__DIR__, 5);
        self::assertSame(
            ['sandbox/tests/check_guide_commands.sh'],
            (new Quality($root))->governedShellPaths(),
        );
    }
}
