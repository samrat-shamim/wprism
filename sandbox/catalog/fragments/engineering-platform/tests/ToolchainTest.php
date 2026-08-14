<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Toolchain;
use PHPUnit\Framework\TestCase;

final class ToolchainTest extends TestCase
{
    public function testLockAndHostPlatformAreExplicit(): void
    {
        $root = dirname(__DIR__, 5);
        $toolchain = new Toolchain($root);

        self::assertMatchesRegularExpression('/^(?:linux|darwin)-(?:amd64|arm64)$/D', $toolchain->platformId());
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $toolchain->lockDigest());
    }
}
