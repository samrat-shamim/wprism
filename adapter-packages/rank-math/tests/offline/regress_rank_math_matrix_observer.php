<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$sourceRoot = $argv[1] ?? $root;
$matrix = (string) file_get_contents($sourceRoot . '/adapter-packages/rank-math/tests/certify/version-matrix.sh');
$check = (string) file_get_contents($sourceRoot . '/adapter-packages/rank-math/tests/conformance/check.sh');
$legacyObserver = '';
// The explicit prior-source argument replays the real pre-extraction observer.
// A missing helper in the current tree is still a source/load failure, not a
// compatibility path for either live driver.
if ($sourceRoot !== $root && str_contains($check, 'observe_rank_math() {')) {
    $start = strpos($check, 'observe_rank_math() {');
    $end = strpos($check, "\n# One exact, value-redacted database oracle", $start);
    if ($end === false) {
        throw new RuntimeException('prior source has no complete observation boundary');
    }
    $legacyObserver = substr($check, $start, $end - $start);
}

$setup = <<<'SH'
set -euo pipefail
fail() { printf 'OBSERVER_REFUSED:%s\n' "$1" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
. "$2/adapter-packages/rank-math/tests/certify/version-matrix.sh"
probe_mode="$3" probe_context="$4"
probe_root=$(mktemp -d "${TMPDIR:-/tmp}/wprism-rank-matrix-observer.XXXXXX")
PAIR=rmmatrixunit
mkdir -p "$probe_root/siterepo/${PAIR}1" "$probe_root/siterepo/${PAIR}2"
cleanup() {
  rm -f -- "$probe_root/siterepo/${PAIR}1/.tmp-rank-math-observe.php" \
    "$probe_root/siterepo/${PAIR}2/.tmp-rank-math-observe.php" "$probe_root/calls"
  rmdir -- "$probe_root/siterepo/${PAIR}1" "$probe_root/siterepo/${PAIR}2" \
    "$probe_root/siterepo" "$probe_root"
}
trap cleanup EXIT
cd "$probe_root"
native_transport() {
  local side="$1"
  shift
  [ "$#" -eq 2 ] && [ "$1" = eval-file ] \
    && [ "$2" = /siterepo/.tmp-rank-math-observe.php ] \
    || fail 'native observer changed its exact WP argv'
  [ -f "siterepo/${PAIR}${side#wp}/.tmp-rank-math-observe.php" ] \
    || fail 'native observer did not publish its program into the selected site'
  grep -Fq 'global $wpdb;' "siterepo/${PAIR}${side#wp}/.tmp-rank-math-observe.php" \
    || fail 'native observer did not write the actual PHP program'
  printf '%s\n' "$side" >>"$probe_root/calls"
  case "$probe_mode" in
    empty) return 0 ;;
    malformed) printf 'not-json\n'; return 0 ;;
    php-stdout) printf 'PHP Warning: observer fixture in /fixture.php on line 1\n' ;;
    php-stderr) printf 'PHP Warning: observer fixture in /fixture.php on line 1\n' >&2 ;;
  esac
  printf '{"site":"%s","observed":true}\n' "$side"
  [ "$probe_mode" != nonzero ] || return 23
}
wp1() { native_transport wp1 "$@"; }
wp2() { native_transport wp2 "$@"; }
CONF_REPO1="$probe_root/not-the-source"
CONF_REPO2="$probe_root/not-the-target"
if [ "$probe_context" = conformance ]; then
  CONF_REPO1="siterepo/${PAIR}1"
  CONF_REPO2="siterepo/${PAIR}2"
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
elif [ "$probe_context" = poisoned ]; then
  wp_conf1() { fail 'expired conformance source transport was used'; }
  wp_conf2() { fail 'expired conformance target transport was used'; }
else
  unset -f wp_conf1 wp_conf2
fi
SH;

$run = static function (string $assignment, string $variable, string $side, string $mode, string $context) use (
    $root, $sourceRoot, $setup, $legacyObserver
): array {
    $acceptance = "\n" . 'printf \'OBSERVATION_READY:%s\\n\' "${' . $variable . '}"' . "\n"
        . '[ "$(cat "$probe_root/calls")" = ' . escapeshellarg($side) . ' ]' . "\n"
        . '[ ! -e "siterepo/${PAIR}1/.tmp-rank-math-observe.php" ]' . "\n"
        . '[ ! -e "siterepo/${PAIR}2/.tmp-rank-math-observe.php" ]' . "\n";
    return ShellProbe::run($setup . "\n" . $legacyObserver . "\n" . $assignment . $acceptance,
        [$root, $sourceRoot, $mode, $context], $root);
};

preg_match('/^SOURCE=\$\(observe_rank_math conf1[^\r\n]*\)$/m', $check, $conformance);
wprism_check(isset($conformance[0]), 'the actual conformance source observation is selected');
if (isset($conformance[0])) {
    [$status, $stdout, $stderr] = $run($conformance[0], 'SOURCE', 'wp1', 'ready', 'conformance');
    wprism_check($status === 0 && $stderr === ''
        && trim($stdout) === 'OBSERVATION_READY:{"site":"wp1","observed":true}',
        'the real observer has a healthy conformance-context counterpart');
    foreach (['php-stdout', 'php-stderr', 'nonzero', 'empty', 'malformed'] as $mode) {
        [$status, $stdout] = $run($conformance[0], 'SOURCE', 'wp1', $mode, 'conformance');
        wprism_check($status !== 0 && !str_contains($stdout, 'OBSERVATION_READY:'),
            "the healthy-context conformance observer rejects $mode before publishing its native answer");
    }
}

preg_match_all('/^[\t ]*((?:UPGRADE|DOWNGRADE)_(?:SOURCE|TARGET)_TRANSITION(?:_BEFORE)?)=\$\(observe_rank_math conf([12])[^\r\n]*\)$/m',
    $matrix, $observations, PREG_SET_ORDER);
wprism_check(count($observations) === 6, 'all six upgrade/downgrade observations are selected from the actual matrix');
foreach ($observations as $observation) {
    [$assignment, $variable, $sideNumber] = $observation;
    foreach (['expired', 'poisoned'] as $context) {
        [$status, $stdout, $stderr] = $run($assignment, $variable, 'wp' . $sideNumber, 'ready', $context);
        wprism_check($status === 0 && $stderr === ''
            && trim($stdout) === 'OBSERVATION_READY:{"site":"wp' . $sideNumber . '","observed":true}',
            "$variable binds its own repository and transport with $context conformance context");
    }
    foreach (['php-stdout', 'php-stderr', 'nonzero', 'empty', 'malformed'] as $mode) {
        [$status, $stdout] = $run($assignment, $variable, 'wp' . $sideNumber, $mode, 'expired');
        wprism_check($status !== 0 && !str_contains($stdout, 'OBSERVATION_READY:'),
            "$variable rejects $mode before publishing a transition witness");
    }
}

wprism_check_summary('regress_rank_math_matrix_observer');
