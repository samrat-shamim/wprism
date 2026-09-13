<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once dirname(__DIR__, 2) . '/fixtures/deletion-evidence.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\Apply;
use WPrism\Canon;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryAuthorizationException;
use WPrism\RepositoryCompiler;
use WPrismTest\FakeWpdb;
use WPrismTest\ShellProbe;

// This is the WP-CLI output transport only. The real refusal formatter and
// authorization exception still choose every byte of the public envelope.
final class MapDeletionCliExit extends RuntimeException {}
final class WP_CLI {
    public static string $output = '';
    public static function add_command(string $name, string $handler): void {}
    public static function line(string $line): void { self::$output .= $line . "\n"; }
    public static function halt(int $status): never { throw new MapDeletionCliExit((string) $status); }
}

$scratch = sys_get_temp_dir() . '/map-delete-boundary-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$library = AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg');
Canon::write_file($scratch . '/site.wprism.json', Canon::encode(['spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core', 'map-block-gutenberg']]));
$policy = Policy::load($scratch, adapterLibrary: $library);
$records = [];
foreach (array_keys($policy->authored_options() + $policy->sub_keyed_options()) as $name) $records[$name] = OptionState::absent();
foreach (['active_plugins', 'template', 'stylesheet'] as $name) $records[$name] = OptionState::absent();
$document = OptionState::document($records);
$optionPath = $scratch . '/state/options/core.json';
Canon::write_file($optionPath, Canon::encode($document));
$positive = RepositoryCompiler::compile($scratch, $policy);
$artifactPath = $scratch . '/retained-artifact.json';
$positive->write($artifactPath);
$artifactBefore = file_get_contents($artifactPath);
wprism_check(!function_exists('get_option'), 'credential deletion compilation has no WordPress target API');
$mutant = MapDeletionEvidence::inject($document, OptionState::present('map-fixture-target-key', 'off'));
Canon::write_file($optionPath, Canon::encode($mutant));
$inputBefore = file_get_contents($optionPath);
wprism_check(!str_contains($inputBefore, 'map-fixture-target-key'), 'unsupported tombstone carries only the previous-record hash, not the credential');
$refuses = static function (callable $command, string $label) use ($inputBefore, $optionPath, $artifactPath, $artifactBefore): void {
    try {
        $command();
        wprism_check(false, "$label must refuse");
    } catch (RepositoryAuthorizationException $failure) {
        wprism_check_same(MapDeletionEvidence::diagnostics(), $failure->diagnostics, "$label reaches the exact environment-owned deletion gate");
        wprism_check(!str_contains(Canon::encode($failure->payload()), 'map-fixture-target-key'), "$label public refusal contains no credential");
    }
    wprism_check_same($inputBefore, file_get_contents($optionPath), "$label preserves the rejected source intent");
    wprism_check_same($artifactBefore, file_get_contents($artifactPath), "$label cannot replace the previously published artifact");
};
$refuses(static fn() => RepositoryCompiler::compile($scratch, $policy)->write($artifactPath), 'immutable compiler');

require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Apply/Apply.php';
$db = FakeWpdb::install();
$db->seedTable('wp_options', [['option_id' => 1, 'option_name' => 'gmw-map-block-key', 'option_value' => 'map-fixture-target-key', 'autoload' => 'off']]);
$before = $db->rows('wp_options');
foreach ([[], ['with_deletes' => true], ['with_deletes' => true, 'force_theirs' => true, 'force_delete_referenced' => true]] as $index => $flags) {
    $db->resetLog();
    $refuses(static fn() => Apply::apply($scratch, $flags + ['adapter_library' => $library]), "apply flags $index");
    wprism_check_same($before, $db->rows('wp_options'), "apply flags $index preserves the native credential row");
    wprism_check_same([], $db->queryLog(), "apply flags $index refuses before any target query or ledger mutation");
}
Canon::write_file($optionPath, Canon::encode($document));
wprism_check_same($positive->artifact_hash(), RepositoryCompiler::compile($scratch, $policy)->artifact_hash(), 'removing the unsupported intent restores the exact artifact');
wprism_check_throws(static fn() => MapDeletionEvidence::inject($mutant, OptionState::present('private', 'off')), RuntimeException::class, 'fixture cannot overwrite an existing credential record');

require_once $root . '/agent/src/Command/Cli.php';
$answers = [];
foreach (['compile', 'apply'] as $command) {
    WP_CLI::$output = '';
    try {
        (new ReflectionMethod(\WPrism\Cli::class, 'halt_json_failure'))->invoke(null,
            new RepositoryAuthorizationException(MapDeletionEvidence::diagnostics()), ['format' => 'json'], $command);
        wprism_check(false, "$command formatter must halt");
    } catch (MapDeletionCliExit $exit) {
        wprism_check_same('1', $exit->getMessage(), "$command real formatter preserves its refusal exit");
    }
    $answers[$command] = json_decode(WP_CLI::$output, true, 32, JSON_THROW_ON_ERROR);
    MapDeletionEvidence::assertRefusal($answers[$command], $command);
    wprism_check(true, "$command predicate accepts the complete real public envelope");
    foreach (['extra-field', 'missing-diagnostic', 'wrong-gate', 'leaked-diagnostic'] as $mutation) {
        $answer = $answers[$command];
        if ($mutation === 'extra-field') $answer['private'] = 'map-fixture-target-key';
        if ($mutation === 'missing-diagnostic') $answer['diagnostics'] = [];
        if ($mutation === 'wrong-gate') $answer['diagnostics'][0]['classification'] = 'authored';
        if ($mutation === 'leaked-diagnostic') $answer['diagnostics'][0]['credential'] = 'map-fixture-target-key';
        wprism_check_throws(static fn() => MapDeletionEvidence::assertRefusal($answer, $command), RuntimeException::class,
            "$command $mutation public refusal cannot count as deletion evidence");
    }
}
$observation = ['format' => 'wprism-map-deletion-observation/v1', 'key_preserved' => true, 'intent_preserved' => true,
    'deleted' => true, 'artifact_sha256' => str_repeat('a', 64), 'intent_sha256' => str_repeat('b', 64), 'options_sha256' => str_repeat('c', 64)];
MapDeletionEvidence::assertObservation($observation);
wprism_check(true, 'native deletion observation admits all credential and nonempty file premises');
foreach (array_keys($observation) as $key) {
    $candidate = $observation;
    unset($candidate[$key]);
    wprism_check_throws(static fn() => MapDeletionEvidence::assertObservation($candidate), RuntimeException::class, "missing $key observation cannot pass");
}

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/conformance/check.sh');
$start = strpos($source, "map_delete_refused() {");
$end = strpos($source, "\nmap_delete_observe observe", $start);
if ($start === false || $end === false) throw new RuntimeException('actual deletion refusal block is missing');
$function = substr($source, $start, $end - $start);
$setup = <<<'SH'
set -euo pipefail
MAP_CAPSULE="$1/adapter-packages/map-block-gutenberg"
MAP_ROOT="$6" CONF_PAIR=fixture CONF_REPO2="$6/repo"
mkdir -p "$MAP_ROOT/sandbox/tmp" "$CONF_REPO2/state" "$CONF_REPO2/.tmp-map-deletion/state-rejected"
printf '%s\n' 'retained canonical bytes' >"$CONF_REPO2/state/fixture"
cp "$CONF_REPO2/state/fixture" "$CONF_REPO2/.tmp-map-deletion/state-rejected/fixture"
if [ "$7" = source-changed ]; then printf '%s\n' changed >"$CONF_REPO2/state/fixture"; fi
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/tests/lib/private_command_capture.sh"
answer="$2" command_exit="$3" command_stderr="$4" MAP_DELETE_OBSERVATION="$5"
MAP_DELETE_PRESERVED=$(jq -Sc '[.artifact_sha256,.intent_sha256]' <<<"$8")
MAP_DELETE_INPUT=$(jq -r .options_sha256 <<<"$8")
native_state="$7"
wp_conf2() {
  [ "$1 $2" = 'wprism compile' ] || return 64
  printf '%s\n' "$answer"
  [ -z "$command_stderr" ] || printf '%s\n' "$command_stderr" >&2
  return "$command_exit"
}
map_delete_observe() { :; }
map_preserved() { [ "$native_state" != native-changed ]; }
SH;
foreach (['valid', 'empty', 'zero-exit', 'other-exit', 'stdout-leak', 'stderr-leak', 'artifact-changed', 'intent-changed', 'source-changed', 'native-changed'] as $mutation) {
    $answer = json_encode($answers['compile'], JSON_THROW_ON_ERROR);
    $candidate = $observation;
    if ($mutation === 'empty') $answer = '';
    if ($mutation === 'stdout-leak') $answer = "map-fixture-target-key\n" . $answer;
    if ($mutation === 'artifact-changed') $candidate['artifact_sha256'] = str_repeat('d', 64);
    if ($mutation === 'intent-changed') $candidate['intent_sha256'] = str_repeat('e', 64);
    [$status, $output] = ShellProbe::run($setup . "\n" . $function . "\nmap_delete_refused compile --out=/siterepo/.tmp-map-deletion/artifact.json\nprintf 'DELETE_REFUSAL_ACCEPTED\\n'\n", [
        $root, $answer, $mutation === 'zero-exit' ? '0' : ($mutation === 'other-exit' ? '7' : '1'),
        $mutation === 'stderr-leak' ? 'map-fixture-target-key' : '', json_encode($candidate, JSON_THROW_ON_ERROR), $scratch,
        $mutation, json_encode($observation, JSON_THROW_ON_ERROR),
    ], $root);
    wprism_check($mutation === 'valid' ? $status === 0 && str_contains($output, 'DELETE_REFUSAL_ACCEPTED') : $status !== 0 && !str_contains($output, 'DELETE_REFUSAL_ACCEPTED'),
        "$mutation actual native deletion refusal acceptance has the expected outcome");
    wprism_check(!str_contains($output, 'map-fixture-target-key'), "$mutation cannot replay private command bytes on stdout");
}
wprism_check_summary('map-block-gutenberg unsupported credential deletion');
