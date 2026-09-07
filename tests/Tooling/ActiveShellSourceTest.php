<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\ActiveShellSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/ActiveShellSource.php';

final class ActiveShellSourceTest extends TestCase
{
    public function testPluralCensusRetainsRepeatedReachableStatementsWithoutRelaxingUniquePremises(): void
    {
        $source = <<<'SH'
# pair_live_ownership_acquire mariadb
printf '%s' 'pair_live_ownership_acquire mariadb'
cat <<'FIXTURE'
pair_live_ownership_acquire mariadb
FIXTURE
unused() {
  pair_live_ownership_acquire mariadb
}
again() {
  pair_live_ownership_acquire mysql
}
pair_live_ownership_acquire mariadb
again
for engine in mariadb mysql; do
  pair_live_ownership_acquire "$engine"
done
pair_live_ownership_acquire_unowned mariadb
SH;
        self::assertNull(ActiveShellSource::statement($source, 'pair_live_ownership_acquire'));
        self::assertSame([
            'pair_live_ownership_acquire mysql',
            'pair_live_ownership_acquire mariadb',
            'pair_live_ownership_acquire "$engine"',
        ], array_map(static fn(array $line): string => trim($line['code']),
            ActiveShellSource::statements($source, 'pair_live_ownership_acquire')));
        self::assertSame([10, 12, 15], array_column(ActiveShellSource::statements($source, 'pair_live_ownership_acquire'), 'line'));
    }

    public function testPluralAndUniqueQueriesAgreeOnEmptyAndSingleStatements(): void
    {
        $source = "pair_db_select_engine\n";
        self::assertSame([], ActiveShellSource::statements($source, ''));
        self::assertNull(ActiveShellSource::statement($source, ''));
        self::assertSame([], ActiveShellSource::statements($source, 'pair_db_select'));
        self::assertSame([ActiveShellSource::statement($source, 'pair_db_select_engine')],
            ActiveShellSource::statements($source, 'pair_db_select_engine'));
    }

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
