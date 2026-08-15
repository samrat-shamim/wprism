<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\CommandResult;
use PHPUnit\Framework\TestCase;

final class CommandResultTest extends TestCase
{
    public function testPassAndInfrastructureOutcomesAreCandidateBound(): void
    {
        $root = dirname(__DIR__, 5);
        $runner = new CommandResult($root);
        $pass = $runner->run('quality', [PHP_BINARY, '-r', 'exit(0);']);
        $unavailable = $runner->run('external', [PHP_BINARY, '-r', 'exit(69);']);

        self::assertSame(0, $pass['exit']);
        self::assertSame('pass', $pass['receipt']['state'] ?? null);
        $candidateSha = $pass['receipt']['candidate_sha'] ?? null;
        self::assertIsString($candidateSha);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $candidateSha);
        self::assertSame(69, $unavailable['exit']);
        self::assertSame('infra_error', $unavailable['receipt']['state'] ?? null);
    }
}
