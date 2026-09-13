<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
require_once $root . '/agent/src/Apply/ApplyPreparationCoordinator.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once $capsule . '/fixtures/lifecycle-evidence.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\AdapterRegistry;
use WPrism\ApplyPreparationCoordinator;
use WPrism\LifecyclePlanner;
use WPrism\ManifestDispositions;
use WPrism\Policy;
use WPrism\PrivateRefusalEvidence;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\ShellProbe;

// Turn accidental malformed-input warnings into failures, not successful
// Throwable refusals accompanied by a PHP diagnostic in the native stream.
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
$library = AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg');
$policy = Policy::load(null, ['core', 'map-block-gutenberg'], adapterLibrary: $library);
$dispositions = ManifestDispositions::load_library($library);
$plugin = MapLifecycleEvidence::PLUGIN;
$reports = [];
foreach ([
    'exact' => [true, '1.35', true], 'inactive' => [false, '1.35', true],
    '1.34' => [true, '1.34', true], '1.35.1' => [true, '1.35.1', true],
    'missing' => [true, '', true], 'absent' => [false, null, false],
    'wrong-basename' => [false, null, false],
] as $case => [$active, $version, $exists]) {
    $plugins = $exists ? [$plugin => $version] : [];
    $activePlugins = $active ? [$plugin] : [];
    if ($case === 'wrong-basename') $activePlugins[] = 'map-block-gutenberg/map-fixture-wrong.php';
    $target = ['plugins' => $plugins, 'active_plugins' => $activePlugins];
    $reports[$case] = AdapterRegistry::report($dispositions, $policy->manifests, ['operation' => 'apply'], $target,
        platformBoundary: $policy->adapter_platform_boundary());
    MapLifecycleEvidence::assertCapability($reports[$case], $active, $version === '' ? null : $version);
    wprism_check(true, "$case native predicate admits the actual capability gate's answer");
    // Isolate interpretation of observed native facts; the separate live leg
    // supplies those facts using actual WordPress installation/lifecycle APIs.
    $mismatches = (new ReflectionMethod(LifecyclePlanner::class, 'code_mismatch_from_observation'))->invoke(
        null, $policy, ['active_plugins' => [$plugin]], $target + ['plugin_exists' => [$plugin => $exists]]
    );
    try {
        $result = ApplyPreparationCoordinator::enforce_code_mismatch_gate($mismatches, []);
        wprism_check($case === 'exact' && $result === [], "$case reaches the expected apply gate outcome");
    } catch (RuntimeException $failure) {
        if ($case === 'exact') throw $failure;
        $profile = MapLifecycleEvidence::refusalProfile($case);
        PrivateRefusalReceipt::assertGraph(PrivateRefusalEvidence::graph($failure), $profile['nodes']);
        wprism_check(true, "$case closed expected private graph agrees with the real lifecycle/apply gate");
        wprism_check_throws(static fn() => PrivateRefusalReceipt::assertGraph(
            PrivateRefusalEvidence::graph(new RuntimeException('unrelated failure')), $profile['nodes']
        ), RuntimeException::class, "$case cannot accept an unrelated generic apply failure");
    }
}
wprism_check_throws(static fn() => MapLifecycleEvidence::refusalProfile('exact'), RuntimeException::class, 'supported state cannot select a refusal profile');
$tables = [];
foreach (MapLifecycleEvidence::TABLES as $name) $tables[$name] = [['id' => '1', 'value' => 'one'], ['id' => '2', 'value' => 'two']];
$observation = ['format' => 'wprism-map-lifecycle-observation/v1', 'post' => 7001, 'created' => 7002,
    'key_preserved' => true, 'runtime_preserved' => true, 'maps_bound' => true,
    'installed' => '1.35', 'active' => true, 'state' => MapLifecycleEvidence::witness($tables)];
MapLifecycleEvidence::assertObservation($observation);
wprism_check(true, 'native observation has nonempty bounded table and target premises');
foreach (MapLifecycleEvidence::TABLES as $table) {
    $candidate = $tables;
    $candidate[$table][0]['value'] = 'changed';
    wprism_check(MapLifecycleEvidence::witness($candidate)[$table] !== $observation['state'][$table], "$table witness detects an arbitrary row-value mutation");
    unset($candidate[$table]);
    wprism_check_throws(static fn() => MapLifecycleEvidence::witness($candidate), RuntimeException::class, "$table cannot disappear from observation");
}
foreach (['empty', 'state-scalar', 'state-missing', 'created-string', 'key-lost', 'runtime-lost', 'map-unbound', 'empty-options', 'bad-hash'] as $mutation) {
    $candidate = $observation;
    switch ($mutation) {
        case 'empty': $candidate = []; break;
        case 'state-scalar': $candidate['state'] = 'invalid'; break;
        case 'state-missing': unset($candidate['state']['map']); break;
        case 'created-string': $candidate['created'] = '7002'; break;
        case 'key-lost': $candidate['key_preserved'] = false; break;
        case 'runtime-lost': $candidate['runtime_preserved'] = false; break;
        case 'map-unbound': $candidate['maps_bound'] = false; break;
        case 'empty-options': $candidate['state']['options']['count'] = 0; break;
        case 'bad-hash': $candidate['state']['posts']['sha256'] = 'not-a-digest'; break;
    }
    wprism_check_throws(static fn() => MapLifecycleEvidence::assertObservation($candidate), RuntimeException::class, "$mutation observation refuses without a runtime diagnostic");
}
$report = $reports['1.34'];
$mapIndex = array_search('map-block-gutenberg', array_column($report['manifests'], 'name'), true);
foreach (['empty', 'rows-scalar', 'row-scalar', 'reasons-missing', 'reason-scalar', 'reason-lost', 'wrong-observed', 'blocker-lost', 'foreign-blocker', 'wrong-active'] as $mutation) {
    $candidate = $report;
    switch ($mutation) {
        case 'empty': $candidate = []; break;
        case 'rows-scalar': $candidate['manifests'] = 'invalid'; break;
        case 'row-scalar': $candidate['manifests'][] = 'invalid'; break;
        case 'reasons-missing': unset($candidate['manifests'][$mapIndex]['verdict']['reasons']); break;
        case 'reason-scalar': $candidate['manifests'][$mapIndex]['verdict']['reasons'][] = 'invalid'; break;
        case 'reason-lost': array_pop($candidate['manifests'][$mapIndex]['verdict']['reasons']); break;
        case 'wrong-observed':
            foreach ($candidate['manifests'][$mapIndex]['verdict']['reasons'] as &$reason) {
                if ($reason['code'] === 'plugin_version_mismatch') $reason['observed'] = '2.0';
            }
            unset($reason);
            break;
        case 'blocker-lost': array_pop($candidate['blockers']); break;
        case 'foreign-blocker': $candidate['blockers'][] = ['name' => 'core', 'code' => 'platform_php_unsupported']; break;
        case 'wrong-active': $candidate['target']['active_plugins'] = []; break;
    }
    wprism_check_throws(static fn() => MapLifecycleEvidence::assertCapability($candidate, true, '1.34'), RuntimeException::class, "$mutation capability refuses without a runtime diagnostic");
}

// Exercise the actual host validator against private transport files. The
// native run separately proves freshness with the shared snapshot/collector.
$privateDirectory = sys_get_temp_dir() . '/map-lifecycle-evidence-' . bin2hex(random_bytes(12));
mkdir($privateDirectory, 0700);
$public = MapLifecycleEvidence::publicRefusal();
$profile = MapLifecycleEvidence::refusalProfile('inactive');
$record = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'apply', 'reason_code' => 'apply_failed']
    + PrivateRefusalEvidence::graph(new RuntimeException($profile['nodes'][0]['message']));
try {
    foreach (['valid', 'unrelated', 'other-case', 'extra-cause', 'zero-records', 'wrong-exit', 'php-stderr', 'other-pair', 'wrong-public',
        'diagnostic-leak', 'remediation-leak', 'extra-field-leak', 'missing-diagnostics'] as $mutation) {
        $candidate = $record;
        if ($mutation === 'unrelated') $candidate = array_replace($record, PrivateRefusalEvidence::graph(new RuntimeException('unrelated gate')));
        if ($mutation === 'other-case') $candidate = array_replace($record, PrivateRefusalEvidence::graph(new RuntimeException(MapLifecycleEvidence::refusalProfile('absent')['nodes'][0]['message'])));
        if ($mutation === 'extra-cause') $candidate = array_replace($record, PrivateRefusalEvidence::graph(new RuntimeException($profile['nodes'][0]['message'], 0, new RuntimeException('extra'))));
        $bytes = json_encode($candidate, JSON_THROW_ON_ERROR);
        $raw = ['name' => '20260913-120000-apply-' . str_repeat('a', 24) . '.json', 'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'contents_base64' => base64_encode($bytes)];
        $diagnostic = ['command' => 'apply', 'format' => 'wprism-private-refusal-diagnostic/v1', 'purpose' => 'diagnostic_only', 'verified' => false,
            'new_records' => $mutation === 'zero-records' ? 0 : 1, 'records' => $mutation === 'zero-records' ? [] : [$raw]];
        $answer = $public;
        if ($mutation === 'wrong-public') $answer['command'] = 'capture';
        if ($mutation === 'diagnostic-leak') $answer['diagnostics'][0]['message'] = 'map-fixture-target-key';
        if ($mutation === 'remediation-leak') $answer['remediation'] = 'map-fixture-target-key';
        if ($mutation === 'extra-field-leak') $answer['private'] = ['credential' => 'map-fixture-target-key'];
        if ($mutation === 'missing-diagnostics') unset($answer['diagnostics']);
        $streams = ['command.stdout' => json_encode($answer, JSON_THROW_ON_ERROR), 'command.stderr' => '',
            'command.exit' => $mutation === 'wrong-exit' ? "0\n" : "1\n", 'private.stdout' => json_encode($diagnostic, JSON_THROW_ON_ERROR),
            'private.stderr' => ' Container wprism-fixture-cli2-run-' . str_repeat('a', 12) . " Created \n", 'private.exit' => "0\n"];
        if ($mutation === 'php-stderr') $streams['command.stderr'] = "PHP Warning: failed\n";
        foreach ($streams as $file => $contents) {
            file_put_contents($privateDirectory . '/' . $file, $contents);
            chmod($privateDirectory . '/' . $file, 0600);
            clearstatcache(true, $privateDirectory . '/' . $file);
        }
        $check = static fn() => MapLifecycleEvidence::assertPrivate('inactive', $mutation === 'other-pair' ? 'foreign' : 'fixture', $privateDirectory . '/private');
        if ($mutation === 'valid') {
            $check();
            wprism_check(true, 'private lifecycle validator admits exact transport/public/cause evidence');
        } else {
            wprism_check_throws($check, RuntimeException::class, "$mutation private lifecycle evidence refuses");
        }
    }
} finally {
    foreach (['command', 'private'] as $stage) {
        foreach (['stdout', 'stderr', 'exit'] as $suffix) {
            $path = $privateDirectory . '/' . $stage . '.' . $suffix;
            if (is_file($path)) unlink($path);
        }
    }
    rmdir($privateDirectory);
}

$source = (string) file_get_contents($capsule . '/tests/conformance/check.sh');
$archiveBlock = ShellProbe::captureBlock($source, 'MAP_CACHE', 'map_native() {');
$archiveSetup = <<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
MAP_EXACT_SHA="$2" MAP_EXACT="/artifacts-cache/plugin-map-block-gutenberg-1.35-$2.zip"
answer="$3" command_exit="$4" command_stderr="$5"
wp_conf2() {
  # WP-CLI eval accepts only its expression. eval-file exposes the remaining
  # positional operands through $args and is the fixture's required ABI.
  [ "$#" = 5 ] && [ "$1" = eval-file ] && [ "$2" = /siterepo/.tmp-map-lifecycle/lifecycle-native.php ] \
    && [ "$3" = archive ] && [ "$4" = "$MAP_EXACT" ] && [ "$5" = --use-include ] || return 64
  printf '%s\n' "$answer"
  [ -z "$command_stderr" ] || printf '%s\n' "$command_stderr" >&2
  return "$command_exit"
}
SH;
foreach (['valid', 'empty', 'wrong-digest', 'missing', 'nonzero', 'php-stdout', 'php-stderr'] as $mutation) {
    $digest = str_repeat('a', 64);
    $answer = json_encode(['sha256' => $mutation === 'missing' ? null : ($mutation === 'wrong-digest' ? str_repeat('b', 64) : $digest)], JSON_THROW_ON_ERROR);
    if ($mutation === 'empty') $answer = '';
    if ($mutation === 'php-stdout') $answer = "PHP Warning: fixture failure\n" . $answer;
    [$status, $output] = ShellProbe::run($archiveSetup . "\n" . $archiveBlock . "\nprintf 'ARCHIVE_READY\\n'\n", [
        $root, $digest, $answer, $mutation === 'nonzero' ? '1' : '0', $mutation === 'php-stderr' ? 'PHP Warning: fixture failure' : '',
    ], $root);
    wprism_check($mutation === 'valid' ? $status === 0 && str_contains($output, 'ARCHIVE_READY') : $status !== 0 && !str_contains($output, 'ARCHIVE_READY'),
        "$mutation actual archive probe enforces its WP-CLI argv, digest and transport");
}
$start = strpos($source, "map_native() {");
$end = strpos($source, "\nmap_native backup", $start);
if ($start === false || $end === false) throw new RuntimeException('actual lifecycle shell functions missing');
$functions = substr($source, $start, $end - $start);
$setup = <<<'SH'
set -euo pipefail
MAP_CAPSULE="$1/adapter-packages/map-block-gutenberg"
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
capability="$2" observation="$3" capability_rc="$4" extra="$5"
MAP_LIFECYCLE_BASELINE=$(jq -Sc .state <<<"$6")
wp_conf2() {
  if [ "$1 $2" = 'wprism capabilities' ]; then
    [ -z "$extra" ] || printf '%s\n' "$extra" >&2
    printf '%s\n' "$capability"
    return "$capability_rc"
  elif [ "$1" = eval-file ]; then
    printf '%s\n' "$observation"
  else
    return 99
  fi
}
SH;
foreach (['valid', 'empty', 'malformed', 'zero-exit', 'other-exit', 'php-stdout', 'php-stderr', 'observation-empty', 'post-changed', 'option-changed'] as $mutation) {
    $answer = json_encode($reports['exact'], JSON_THROW_ON_ERROR);
    $native = $observation;
    if ($mutation === 'post-changed') $native['state']['posts']['sha256'] = str_repeat('1', 64);
    if ($mutation === 'option-changed') $native['state']['options']['sha256'] = str_repeat('2', 64);
    if ($mutation === 'empty') $answer = '';
    if ($mutation === 'malformed') $answer = '{';
    if ($mutation === 'php-stdout') $answer = "PHP Warning: fixture failure\n" . $answer;
    [$status, $output, $diagnostic] = ShellProbe::run($setup . "\n" . $functions . "\nmap_capability active 1.35\nprintf 'ACCEPTED\\n'\n", [
        $root, $answer, $mutation === 'observation-empty' ? '' : json_encode($native, JSON_THROW_ON_ERROR),
        $mutation === 'zero-exit' ? '0' : ($mutation === 'other-exit' ? '7' : '3'),
        $mutation === 'php-stderr' ? 'PHP Warning: fixture failure' : '', json_encode($observation, JSON_THROW_ON_ERROR),
    ], $root);
    if ($mutation === 'valid' && $status !== 0) fwrite(STDERR, $diagnostic . $output);
    wprism_check($mutation === 'valid' ? $status === 0 && str_contains($output, 'ACCEPTED') : $status !== 0 && !str_contains($output, 'ACCEPTED'),
        "$mutation actual native command acceptance has the expected outcome");
}
wprism_check_summary('map-block-gutenberg lifecycle evidence');
