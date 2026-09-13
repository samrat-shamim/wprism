<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/agent/src/Capture/Capture.php';
require_once $capsule . '/fixtures/concurrency-evidence.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\Capture;
use WPrism\CommandRefusalException;
use WPrism\ProcessFence;
use WPrism\Publish;
use WPrismTest\FakeWpdb;
use WPrismTest\ShellProbe;

final class MapConcurrencyCliExit extends RuntimeException {}
final class WP_CLI {
    public static string $output = '';
    public static function add_command(string $name, string $handler): void {}
    public static function line(string $line): void { self::$output .= $line . "\n"; }
    public static function halt(int $status): never { throw new MapConcurrencyCliExit((string) $status); }
}
require_once $root . '/agent/src/Command/Cli.php';
set_error_handler(static function (int $severity, string $message): bool {
    if ((error_reporting() & $severity) === 0) return false;
    throw new ErrorException($message, 0, $severity);
});
$scratch = sys_get_temp_dir() . '/map-concurrency-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$library = AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg');
Canon::write_file($scratch . '/site.wprism.json', Canon::encode(['spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core', 'map-block-gutenberg']]));
Canon::write_file($scratch . '/state/retained', 'untouched canonical state');
$before = Publish::tree_digest($scratch . '/state');
$db = FakeWpdb::install();
$db->seedTable('options', [['option_id' => 1, 'option_name' => 'gmw-map-block-key', 'option_value' => 'map-fixture-target-key', 'autoload' => 'off']]);
$options = $db->rows('options');
$out = $scratch . '/output';
$holder = Publish::lock($out);
$failures = [];
try {
    try { Capture::run($scratch, $out, adapterLibrary: $library); }
    catch (CommandRefusalException $failure) { $failures['same'] = $failure; }
} finally { Publish::unlock($holder); }
$db->setLockResult(0);
try { Capture::run($scratch, $scratch . '/other', adapterLibrary: $library); }
catch (CommandRefusalException $failure) { $failures['other'] = $failure; }
// Apply consumes the same target fence. Its complete native command is
// exercised in check.sh; this pin drives that actual shared guard and CLI.
try { ProcessFence::acquire(); }
catch (CommandRefusalException $failure) { $failures['apply'] = $failure; }
wprism_check_same(['same', 'other', 'apply'], array_keys($failures), 'every real contention guard refuses with its typed command exception');
wprism_check_same($before, Publish::tree_digest($scratch . '/state'), 'capture contention cannot change prior canonical bytes');
wprism_check_same($options, $db->rows('options'), 'contention preserves the complete target credential row');
foreach ([$out, $scratch . '/other'] as $destination) {
    wprism_check(!file_exists($destination) && !is_dir(Publish::stage_dir($destination)) && !is_dir(Publish::backup_dir($destination))
        && !file_exists(Publish::intent_path($destination)), 'refused capture cannot partially publish or stage');
    $retryLock = Publish::lock($destination);
    Publish::unlock($retryLock);
    wprism_check(true, 'a refused destination releases its own publication lock');
}
$answers = [];
foreach ($failures as $case => $failure) {
    WP_CLI::$output = '';
    try {
        (new ReflectionMethod(\WPrism\Cli::class, 'halt_json_failure'))->invoke(null, $failure, ['format' => 'json'], $case === 'apply' ? 'apply' : 'capture');
        throw new LogicException('contention formatter did not halt');
    } catch (MapConcurrencyCliExit $exit) { wprism_check_same('1', $exit->getMessage(), "$case actual formatter exits one"); }
    $answers[$case] = json_decode(WP_CLI::$output, true, 32, JSON_THROW_ON_ERROR);
    wprism_check_same(MapConcurrencyEvidence::refusal($case), $answers[$case], "$case complete expected public envelope matches the real guard and serializer");
}

$transport = $scratch . '/transport';
mkdir($transport, 0700);
foreach ($answers as $case => $answer) {
    foreach (['valid', 'empty', 'extra-field', 'missing-message', 'wrong-code', 'wrong-exit', 'stdout-leak', 'stderr-leak', 'wrong-pair'] as $mutation) {
        $candidate = $answer;
        if ($mutation === 'extra-field') $candidate['private'] = 'map-fixture-target-key';
        if ($mutation === 'missing-message') unset($candidate['message']);
        if ($mutation === 'wrong-code') $candidate['reason_code'] = 'apply_failed';
        $bytes = $mutation === 'empty' ? '' : json_encode($candidate, JSON_THROW_ON_ERROR);
        if ($mutation === 'stdout-leak') $bytes = "map-fixture-target-key\n" . $bytes;
        $streams = ['stdout' => $bytes, 'stderr' => $mutation === 'stderr-leak' ? 'map-fixture-target-key' : " Container wprism-fixture-cli2-run-aaaaaaaaaaaa Created \n",
            'exit' => $mutation === 'wrong-exit' ? "0\n" : "1\n"];
        foreach ($streams as $suffix => $contents) {
            file_put_contents("$transport/command.$suffix", $contents);
            chmod("$transport/command.$suffix", 0600);
            clearstatcache(true, "$transport/command.$suffix");
        }
        $check = static fn() => MapConcurrencyEvidence::assertTransport($case, $mutation === 'wrong-pair' ? 'foreign' : 'fixture', "$transport/command");
        if ($mutation === 'valid') { $check(); wprism_check(true, "$case complete private transport admitted"); }
        else wprism_check_throws($check, Throwable::class, "$case $mutation cannot substitute for contention evidence");
    }
}
foreach (['', 'locked', 'release'] as $phase) {
    MapConcurrencyEvidence::assertPhase(['format' => 'wprism-map-concurrency-phase/v1', 'phase' => $phase], $phase);
    wprism_check(true, "$phase native phase has an exact nonempty envelope");
    foreach ([[], ['phase' => $phase], ['format' => 'wprism-map-concurrency-phase/v1', 'phase' => null],
        ['format' => 'wprism-map-concurrency-phase/v1', 'phase' => 'stale']] as $bad) {
        wprism_check_throws(static fn() => MapConcurrencyEvidence::assertPhase($bad, $phase), RuntimeException::class, "$phase malformed phase cannot prove overlap");
    }
}
$capture = ['counts' => ['post' => 2, 'term' => 1, 'menu' => 0, 'sidebar' => 0, 'options' => 1, 'deletion' => 0],
    'media' => 0, 'notes' => [], 'warnings' => [], 'state_dir' => '/siterepo/.tmp-map-concurrency/holder',
    'revision_hash' => null, 'initial_code_baseline' => null, 'initial_publication_cleanup' => 'not-applicable'];
MapConcurrencyEvidence::assertCapture($capture, 'holder');
wprism_check(true, 'output-only holder capture carries the expected complete summary');
foreach (['empty', 'no-posts', 'no-options', 'warning', 'wrong-output', 'missing-cleanup', 'wrong-cleanup'] as $mutation) {
    $candidate = $capture;
    if ($mutation === 'empty') $candidate = [];
    if ($mutation === 'no-posts') $candidate['counts']['post'] = 0;
    if ($mutation === 'no-options') $candidate['counts']['options'] = 0;
    if ($mutation === 'warning') $candidate['warnings'] = ['partial'];
    if ($mutation === 'wrong-output') $candidate['state_dir'] = '/siterepo/state';
    if ($mutation === 'missing-cleanup') unset($candidate['initial_publication_cleanup']);
    if ($mutation === 'wrong-cleanup') $candidate['initial_publication_cleanup'] = 'retained';
    wprism_check_throws(static fn() => MapConcurrencyEvidence::assertCapture($candidate, 'holder'), RuntimeException::class, "$mutation cannot count as holder publication");
}

$source = (string) file_get_contents($capsule . '/tests/conformance/check.sh');
$start = strpos($source, 'map_concurrent_refused() {');
$end = strpos($source, 'map_concurrent_clean() {', $start);
if ($start === false || $end === false) throw new RuntimeException('actual concurrent refusal function missing');
$function = substr($source, $start, $end - $start);
$setup = <<<'SH'
set -euo pipefail
MAP_CAPSULE="$1/adapter-packages/map-block-gutenberg" MAP_CONCURRENT_SINK="$2" CONF_PAIR=fixture
answer="$3" fixture_exit="$4" diagnostic="$5" premise="$6"
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/tests/lib/private_command_capture.sh"
for suffix in stdout stderr exit; do (umask 077; : >"$MAP_CONCURRENT_SINK/same.$suffix"); done
wp_conf2() { printf '%s\n' "$answer"; [ -z "$diagnostic" ] || printf '%s\n' "$diagnostic" >&2; return "$fixture_exit"; }
map_concurrent_phase() { [ "$1" = locked ] && [ "$premise" != released ]; }
map_concurrent_preserved() { [ "$premise" != changed ]; }
SH;
foreach (['valid', 'empty', 'zero-exit', 'stdout-leak', 'stderr-leak', 'released', 'changed'] as $mutation) {
    $answer = $mutation === 'empty' ? '' : json_encode($answers['same'], JSON_THROW_ON_ERROR);
    if ($mutation === 'stdout-leak') $answer = "map-fixture-target-key\n" . $answer;
    [$status, $stdout, $stderr] = ShellProbe::run($setup . "\n" . $function . "\nmap_concurrent_refused same wprism capture\nprintf 'CONTENTION_VERIFIED\\n'\n", [
        $root, $transport, $answer, $mutation === 'zero-exit' ? '0' : '1', $mutation === 'stderr-leak' ? 'map-fixture-target-key' : '', $mutation,
    ], $root);
    wprism_check($mutation === 'valid' ? $status === 0 && str_contains($stdout, 'CONTENTION_VERIFIED') : $status !== 0 && !str_contains($stdout, 'CONTENTION_VERIFIED'),
        "$mutation actual contender acceptance has the expected outcome");
    wprism_check(!str_contains($stdout . $stderr, 'map-fixture-target-key'), "$mutation retains command bytes privately on both streams");
}
wprism_check_summary('map-block-gutenberg concurrency evidence');
