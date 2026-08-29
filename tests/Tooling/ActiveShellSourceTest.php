<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\ActiveShellSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/ActiveShellSource.php';

final class ActiveShellSourceTest extends TestCase
{
    public function testCaseArgumentInsideCommandSubstitutionIsNotGrammar(): void
    {
        $source = <<<'SH'
label=$(printf "%s" case)
value=$(printf "%s" "${case}")
SH;

        self::assertSame($source, ActiveShellSource::source($source));
    }

    public function testCaseGrammarAtACommandPositionStillRefuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported case grammar inside command substitution');

        ActiveShellSource::source('label=$(printf x; case "$x" in x) printf x ;; esac)');
    }

    public function testPremiseAndParticipantInsideAFunctionAreNotActiveEvidence(): void
    {
        $source = <<<'SH'
never_runs() {
WPRISM_CERTIFICATION_MANIFESTS_JSON='["acf"]'
require_observed_nonempty "owned assertion" "$out" # wprism-premise-owner: acf
}
SH;

        self::assertSame([], ActiveShellSource::manifestParticipants($source));
        self::assertNull(ActiveShellSource::statement(
            $source,
            'require_observed_nonempty "owned assertion"'
        ));
    }

    public function testTopLevelParticipantAndLoopPremiseRemainActive(): void
    {
        $source = <<<'SH'
WPRISM_CERTIFICATION_MANIFESTS_JSON='["acf"]'
for value in one; do
  require_observed_nonempty "owned assertion" "$value" # wprism-premise-owner: acf
done
SH;

        self::assertSame(['acf'], ActiveShellSource::manifestParticipants($source));
        self::assertNotNull(ActiveShellSource::statement(
            $source,
            'require_observed_nonempty "owned assertion"'
        ));
    }
}
