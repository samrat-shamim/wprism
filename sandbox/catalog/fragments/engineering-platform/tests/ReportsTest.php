<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Reports;
use PHPUnit\Framework\TestCase;

final class ReportsTest extends TestCase
{
    public function testJUnitDistinguishesFailureInfrastructureAndSkip(): void
    {
        $junit = Reports::junit($this->results());

        self::assertStringContainsString('tests="4" failures="1" errors="1" skipped="1"', $junit);
        self::assertStringContainsString('<failure message="assertion &lt;failed&gt;"/>', $junit);
        self::assertStringContainsString('<error message="runner unavailable"/>', $junit);
        self::assertStringContainsString('<skipped message="out of scope"/>', $junit);
    }

    public function testTapUsesSkipOnlyForNotApplicable(): void
    {
        $tap = Reports::tap($this->results());

        self::assertStringContainsString("1..4\n", $tap);
        self::assertStringContainsString('ok 1 - pass', $tap);
        self::assertStringContainsString('not ok 2 - fail', $tap);
        self::assertStringContainsString('not ok 3 - infra', $tap);
        self::assertStringContainsString('ok 4 - na # SKIP selector proved not applicable', $tap);
    }

    /** @return list<array{id:string,state:string,duration_ms:int,message:?string}> */
    private function results(): array
    {
        return [
            ['id' => 'pass', 'state' => 'pass', 'duration_ms' => 10, 'message' => null],
            ['id' => 'fail', 'state' => 'fail', 'duration_ms' => 20, 'message' => 'assertion <failed>'],
            ['id' => 'infra', 'state' => 'infra_error', 'duration_ms' => 30, 'message' => 'runner unavailable'],
            ['id' => 'na', 'state' => 'not_applicable', 'duration_ms' => 0, 'message' => 'out of scope'],
        ];
    }
}
