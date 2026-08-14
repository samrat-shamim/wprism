<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Doctor;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    public function testDoctorChecksExecutableToolchainAndLockPlatform(): void
    {
        $root = dirname(__DIR__, 5);
        $checks = (new Doctor())->inspect($root);
        $byName = [];
        foreach ($checks as $check) {
            $byName[$check['name']] = $check;
        }

        foreach ([
            'version:php',
            'version:composer',
            'composer-lock-platform',
            'php-function:proc_open',
            'php-function:posix_kill',
            'php-function:posix_getpgid',
            'php-function:pcntl_signal',
            'tool:setsid',
        ] as $required) {
            self::assertArrayHasKey($required, $byName);
            self::assertSame('pass', $byName[$required]['state'], $required);
        }
    }
}
