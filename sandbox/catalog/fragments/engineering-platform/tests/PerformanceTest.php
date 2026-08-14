<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Performance;
use PHPUnit\Framework\TestCase;

final class PerformanceTest extends TestCase
{
    public function testSmokeMeasuresEveryRequiredScenarioWithoutClaimingAuthority(): void
    {
        $root = dirname(__DIR__, 5);
        $receipt = (new Performance($root))->smoke();

        self::assertSame('pass', $receipt['state']);
        self::assertSame('non_authorizing_harness_health', $receipt['authority']);
        $scenarios = $receipt['scenarios'] ?? null;
        self::assertIsArray($scenarios);
        self::assertSame([
            'ordinary-wordpress-disabled',
            'ordinary-wordpress-enabled',
            'journal-enabled',
            'agent-command-cold',
            'agent-command-warm',
            'host-cli-cold',
            'host-cli-warm',
            'closure-hashing',
            'targeted-test-feedback',
            'complete-test-feedback',
        ], array_keys($scenarios));
        foreach ($scenarios as $scenario) {
            self::assertIsArray($scenario);
            $samples = $scenario['samples'] ?? null;
            self::assertIsArray($samples);
            self::assertCount(3, $samples);
        }
    }

    public function testBudgetFailsClosedOutsideTheControlledRunner(): void
    {
        $root = dirname(__DIR__, 5);
        $receipt = (new Performance($root))->budget();

        self::assertSame('infra_error', $receipt['state']);
        self::assertSame([], $receipt['attempts']);
        $message = $receipt['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('controlled performance prerequisite mismatch', $message);
    }
}
