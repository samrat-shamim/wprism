<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
wprism_test_define_agent_versions();
$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
require_once $root . '/agent/src/Command/Cli.php';
require_once $root . '/cli/src/Command/CommandOutput.php';
require_once __DIR__ . '/../../fixtures/private-refusal-evidence.php';

use WPrism\AdapterLibrary;
use WPrism\Cli;
use WPrism\LifecyclePlanner;
use WPrism\Orchestrator\CommandOutput;
use WPrism\Policy;
use WPrismTest\ShellProbe;

final class RankRangeHalt extends RuntimeException {}
final class WP_CLI {
    public static array $lines = [];
    public static function add_command(string $name, string $class): void {}
    public static function line(string $line): void { self::$lines[] = $line; }
    public static function halt(int $status): never { throw new RankRangeHalt((string) $status); }
}

$policy = Policy::load(null, ['rank-math'], adapterLibrary: AdapterLibrary::fromSourceTree($root));
$plugin = 'seo-by-rank-math/rank-math.php';
$desired = ['active_plugins' => [$plugin]];
$observation = ['active_plugins' => [], 'plugins' => [$plugin => '1.0.276'],
    'plugin_exists' => [$plugin => true], 'template' => 'fixture', 'stylesheet' => 'fixture',
    'themes' => ['fixture' => '1.0'], 'theme_exists' => ['fixture' => true], 'recorded_raw' => null];
$preflight = static function (string $version, bool $exists = true) use ($policy, $desired, $observation, $plugin): array|Throwable {
    $facts = array_replace($observation, ['plugins' => [$plugin => $version], 'plugin_exists' => [$plugin => $exists]]);
    try {
        return LifecyclePlanner::deployment_status_from_observation($policy, $desired, false, $facts);
    } catch (Throwable $failure) {
        return $failure;
    }
};

// The shell probe substitutes only transport/native observations. The host
// failure itself uses the shipped policy and actual lifecycle preflight,
// CLI redaction/private recorder and host transport-detail renderer. A canned
// public envelope would miss exactly the boundary the 40ea live run exposed.
if (($argv[1] ?? '') === '--emit-host') {
    $repo = $argv[2];
    $mode = $argv[3];
    file_put_contents(dirname($repo) . '/attempted', 'yes');
    $failure = $preflight($mode === 'wrong-cause' ? '1.0.275' : '1.0.276', $mode !== 'missing-code');
    if (!$failure instanceof Throwable) {
        throw new LogicException('negative host fixture did not reach the actual lifecycle refusal');
    }
    try {
        (new ReflectionMethod(Cli::class, 'halt_json_failure'))->invoke(null, $failure,
            $mode === 'stale' ? ['format' => 'json'] : ['format' => 'json', 'repo' => $repo], 'lifecycle-status');
    } catch (RankRangeHalt $halt) {
        if ($halt->getMessage() !== '1') {
            throw $halt;
        }
    }
    if (count(WP_CLI::$lines) !== 1) {
        throw new LogicException('the real CLI did not emit one refusal');
    }
    $answer = WP_CLI::$lines[0];
    $records = glob($repo . '/.wprism/refusals/*-lifecycle-status-*.json') ?: [];
    if (in_array($mode, ['multiple-records', 'incomplete-record'], true)) {
        $new = array_values(array_filter($records, static fn(string $path): bool => !str_contains($path, '20000101-000000-')));
        if (count($new) !== 1) {
            throw new LogicException('record mutation requires the exact one new CLI record');
        }
        if ($mode === 'multiple-records') {
            $extra = $repo . '/.wprism/refusals/20990101-000000-lifecycle-status-' . str_repeat('f', 24) . '.json';
            copy($new[0], $extra);
            chmod($extra, 0600);
        } else {
            $record = json_decode((string) file_get_contents($new[0]), true, 32, JSON_THROW_ON_ERROR);
            $record['traversal']['record_complete'] = false;
            file_put_contents($new[0], json_encode($record, JSON_THROW_ON_ERROR));
        }
    }
    echo "deploy phase: compile\ndeploy phase: lifecycle-status\n";
    if ($mode === 'extra-phase') echo "deploy phase: promotion-begin\n";
    if ($mode === 'stdout-diagnostic') echo "PHP Warning: PRIVATE_RANGE_CANARY in Unknown on line 0\n";
    if ($mode === 'stderr-diagnostic') fwrite(STDERR, "PHP Warning: PRIVATE_RANGE_CANARY in Unknown on line 0\n");
    if ($mode === 'unknown-prefix') echo "PRIVATE_RANGE_CANARY\n";
    fwrite(STDERR, "wprism: deploy: lifecycle preflight failed; no target mutation occurred\n");
    if ($mode === 'wrong-envelope') {
        $decoded = json_decode($answer, true, 32, JSON_THROW_ON_ERROR);
        $decoded['reason_code'] = 'unrelated_refusal';
        $answer = json_encode($decoded, JSON_THROW_ON_ERROR);
    }
    if ($mode === 'multiple-answers') $answer .= "\n" . $answer;
    if ($mode === 'empty-answer') $answer = '';
    if ($mode === 'leaked-cause') $answer .= "\n" . $failure->getMessage();
    CommandOutput::renderTransportDetail([
        'stdout' => $answer,
        'stderr' => " Container wprism-rmrangeprobe-cli1-run-abcd Creating \n Container wprism-rmrangeprobe-cli1-run-abcd Created \n",
    ]);
    exit($mode === 'zero-host-status' ? 0 : 1);
}

$expectedFailure = $preflight('1.0.276');
wprism_check($expectedFailure instanceof RuntimeException, 'the shipped policy reaches a real below-range lifecycle refusal');
wprism_check_same(rank_math_private_refusal_profile('below-range')['message'], $expectedFailure->getMessage(),
    'the declared private profile matches the real lifecycle planner, not the observed live record');
foreach (['1.0.277', '1.0.277.1', '1.0.277.2'] as $version) {
    $healthy = $preflight($version);
    wprism_check(is_array($healthy) && $healthy['required'] === true && $healthy['reasons'] === ['inactive_in_environment'],
        "$version has a healthy lifecycle-activation counterpart instead of a range refusal");
}

$current = (string) file_get_contents(__DIR__ . '/../certify/version-matrix.sh');
$selectedRoot = $argv[1] ?? $root;
$selected = (string) file_get_contents($selectedRoot . '/adapter-packages/rank-math/tests/certify/version-matrix.sh');
$definitions = '';
foreach (['rank_math_private_evidence', 'rank_math_assert_inactive_release', 'assert_rank_math_below_range_refusal'] as $name) {
    if (preg_match('/^' . $name . '\(\).*?^}/ms', $current, $match) !== 1) {
        throw new LogicException('the actual range evidence helper is unavailable');
    }
    $definitions .= $match[0] . "\n";
}
$start = strpos($selected, "\nrank_math_assert_inactive_release ");
if ($start === false) $start = strpos($selected, "\nINSTALLED_OOR=");
$end = $start === false ? false : strpos($selected, "\n}", $start);
if ($start === false || $end === false) throw new LogicException('the actual negative matrix command block is unavailable');
$block = substr($selected, $start, $end - $start);

$scratch = sys_get_temp_dir() . '/wprism-rank-range-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path);
    return; }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    }
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$probe = <<<'SH'
set -euo pipefail
PAIR_SOURCE_ROOT="$1" fixture_root="$2" fixture_case="$3" fixture_producer="$4"
PAIR=rmrangeprobe
PAIR_COMPOSE=(fixture_compose)
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'MATRIX_RANGE_PASSED\n'; }
. "$PAIR_SOURCE_ROOT/sandbox/conformance/asserts.sh"
wp1() {
  [ "$1" = plugin ] && [ "$2" = get ] && [ "$3" = seo-by-rank-math ] || return 81
  if [ "$#" -eq 4 ] && [ "$4" = --field=version ]; then printf '1.0.276\n'; return; fi
  [ "$#" -eq 5 ] && [ "$4" = --fields=name,status,version ] && [ "$5" = --format=json ] || return 82
  local phase=before status=inactive version=1.0.276
  [ ! -e "$fixture_root/attempted" ] || phase=after
  [ "$fixture_case" != "$phase-active" ] || status=active
  [ "$fixture_case" != "$phase-version" ] || version=1.0.277
  [ "$fixture_case" != "$phase-empty" ] || return 0
  [ "$fixture_case" != "$phase-diagnostic" ] || printf 'PHP Warning: PRIVATE_RANGE_CANARY in Unknown on line 0\n' >&2
  printf ' Container wprism-rmrangeprobe-cli1-run-abcd Creating \n' >&2
  printf '{"name":"seo-by-rank-math","status":"%s","version":"%s"}\n' "$status" "$version"
  [ "$fixture_case" != "$phase-nonzero" ] || return 7
}
rank_math_native_state_hash() {
  [ "$#" -eq 1 ] && [ "$1" = wp1 ] || return 83
  local phase=before
  [ ! -e "$fixture_root/attempted" ] || phase=after
  [ "$fixture_case" != "$phase-hash-failed" ] || return 7
  if [ "$fixture_case" = native-drift ] && [ "$phase" = after ]; then
    printf '%064d\n' 2
  else
    printf '%064d\n' 1
  fi
}
host_wprism() {
  [ "$#" -eq 2 ] && [ "$1" = conf1 ] && [ "$2" = deploy ] || return 84
  php "$fixture_producer" --emit-host "$fixture_root/cli1" "$fixture_case"
}
fixture_compose() {
  [ "$#" -ge 13 ] && [ "$1" = run ] && [ "$2" = --rm ] && [ "$3" = -T ] \
    && [ "$4" = --volume ] \
    && [ "$5" = "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" ] \
    && [ "$6" = --entrypoint ] && [ "$7" = php ] && [ "$8" = cli1 ] \
    && [ "$9" = /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php ] \
    && [ "${10}" = /wprism-test/PrivateRefusalReceipt.php ] \
    && [ "${12}" = below-range ] && [ "${13}" = /siterepo/.wprism/refusals ] || return 85
  local service=cli1
  [ "$fixture_case" != wrong-site ] || service=cli2
  php "$PAIR_SOURCE_ROOT/adapter-packages/rank-math/fixtures/private-refusal-evidence.php" \
    "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php" "${11}" "${12}" \
    "$fixture_root/$service/.wprism/refusals" "${@:14}"
  [ "$fixture_case" != "${11}-nonzero" ] || return 7
}
SH;
$cases = ['ready', 'wrong-cause', 'missing-code', 'stale', 'multiple-records', 'incomplete-record',
    'wrong-envelope', 'multiple-answers', 'empty-answer', 'leaked-cause', 'extra-phase', 'unknown-prefix',
    'stdout-diagnostic', 'stderr-diagnostic', 'zero-host-status', 'wrong-site', 'snapshot-nonzero', 'verify-nonzero',
    'before-active', 'after-active', 'before-version', 'after-version', 'before-empty', 'after-empty',
    'before-nonzero', 'after-nonzero', 'before-diagnostic', 'after-diagnostic', 'before-hash-failed', 'after-hash-failed', 'native-drift'];
foreach ($cases as $case) {
    $caseRoot = $scratch . '/' . $case;
    foreach (['cli1', 'cli2'] as $service) {
        mkdir($caseRoot . '/' . $service . '/.wprism/refusals', 0700, true);
        file_put_contents($caseRoot . '/' . $service . '/site.wprism.json', "{}\n");
        // A matching OLD record on both sites makes freshness and site
        // binding non-vacuous; it can never prove this host invocation.
        $old = ['format' => 'wprism-private-refusal-evidence/v2', 'recorded_at' => '2000-01-01T00:00:00Z',
            'command' => 'lifecycle-status', 'reason_code' => 'lifecycle_status_failed',
            ...WPrism\PrivateRefusalEvidence::graph($expectedFailure)];
        $oldPath = $caseRoot . '/' . $service . '/.wprism/refusals/20000101-000000-lifecycle-status-' . str_repeat('a', 24) . '.json';
        file_put_contents($oldPath, json_encode($old, JSON_THROW_ON_ERROR));
        chmod($oldPath, 0600);
    }
    [$status, $stdout, $stderr] = ShellProbe::run($probe . "\n" . $definitions . "\n" . $block,
        [$root, $caseRoot, $case, __FILE__], $root);
    if ($case === 'ready') {
        wprism_check_same('', $stderr, 'healthy range evidence has no unexpected diagnostic');
    }
    wprism_check($case === 'ready' ? $status === 0 && $stdout === "MATRIX_RANGE_PASSED\n"
        : $status !== 0 && !str_contains($stdout, 'MATRIX_RANGE_PASSED'),
        "actual below-range matrix command block truthfully classifies $case");
    wprism_check(!str_contains($stdout . $stderr, 'PRIVATE_RANGE_CANARY')
        && !str_contains($stdout . $stderr, $expectedFailure->getMessage()),
        "$case never publishes the private range cause or rejected raw host/native values");
    $shouldAttempt = !str_starts_with($case, 'before-') && $case !== 'snapshot-nonzero';
    wprism_check_same($shouldAttempt, is_file($caseRoot . '/attempted'), "$case reaches host deploy only after checked native and private baselines");
}
wprism_check_summary('Rank Math version refusal evidence');
