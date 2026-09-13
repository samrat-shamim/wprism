<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once $capsule . '/fixtures/version-matrix-evidence.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';

use WPrism\PrivateRefusalEvidence;
use WPrism\Tooling\AdapterPackageValidator;
use WPrismTest\ShellProbe;

set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
$workflow = $capsule . '/tests/certify/version-matrix.sh';
(new ReflectionMethod(AdapterPackageValidator::class, 'validateVersionMatrixPremises'))->invoke(
    null, $capsule, 'map-block-gutenberg', $workflow, 'map-block-gutenberg'
);
(new ReflectionMethod(AdapterPackageValidator::class, 'assertShellSourceDependenciesUseRecognizedFiles'))->invoke(
    null, $root, $capsule, $workflow, file_get_contents($workflow), 'map-block-gutenberg'
);
wprism_check(true, 'actual package validator admits the matrix declarations and closed shared imports');
$scratch = sys_get_temp_dir() . '/map-matrix-evidence-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$write = static function (string $path, string $bytes): void {
    file_put_contents($path, $bytes);
    chmod($path, 0600);
    clearstatcache(true, $path);
};
$json = static fn(array $record): string => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$tables = [];
foreach (MapLifecycleEvidence::TABLES as $table) $tables[$table] = [['id' => 1], ['id' => 2]];
$native = ['format' => 'wprism-map-lifecycle-observation/v1', 'post' => 7001, 'created' => 7002,
    'key_preserved' => true, 'runtime_preserved' => true, 'maps_bound' => true,
    'installed' => '1.35', 'active' => true, 'wrong_active' => false, 'native_loaded' => true,
    'state' => MapLifecycleEvidence::witness($tables)];
$credentials = ['format' => 'wprism-map-deletion-observation/v1', 'key_preserved' => true,
    'intent_preserved' => true, 'deleted' => false, 'artifact_sha256' => str_repeat('a', 64),
    'intent_sha256' => str_repeat('b', 64), 'options_sha256' => str_repeat('c', 64)];
$capability = static function (string $version): array {
    $ready = $version === '1.35';
    $reasons = $ready ? [] : [['code' => 'plugin_version_mismatch', 'subject' => MapLifecycleEvidence::PLUGIN, 'observed' => $version]];
    return ['schema_version' => 'wprism-capability-report/v1', 'query' => ['operation' => 'apply', 'surface' => null],
        'ready' => $ready, 'target' => ['active_plugins' => [MapLifecycleEvidence::PLUGIN]],
        'manifests' => [['name' => 'map-block-gutenberg', 'status' => 'certified',
            'verdict' => ['status' => $ready ? 'certified' : 'blocked', 'reasons' => $reasons]]],
        'blockers' => $ready ? [] : [['name' => 'map-block-gutenberg', 'code' => 'plugin_version_mismatch']]];
};
$positive = "fixture host deployment completed\n✔ CONFORMANCE PASSED (map-block-gutenberg)\n";
$install = "Unpacking the package...\nInstalling the plugin...\nRemoving the old version of the plugin...\nPlugin updated successfully.\nSuccess: Installed 1 of 1 plugins.\n";
$prelude = " Container wprism-fixture-cli2-run-aaaaaaaaaaaa Created \n";
$stream = static function (string $kind, string $bytes, string $stderr = '', int $exit = 0) use ($scratch, $write): string {
    $stem = $scratch . '/' . $kind;
    foreach (['stdout' => $bytes, 'stderr' => $stderr, 'exit' => $exit . "\n"] as $suffix => $value) $write($stem . '.' . $suffix, $value);
    return $stem;
};
foreach ([
    ['positive', '1.35', $positive, '', 0], ['install', '1.34', $install, $prelude, 0],
    ['native', '1.35', $json($native), $prelude, 0],
    ['native', '1.34', $json(array_replace($native, ['installed' => '1.34'])), $prelude, 0],
    ['credentials', '1.34', $json($credentials), $prelude, 0],
    ['capability', '1.35', $json($capability('1.35')), $prelude, 0],
    ['capability', '1.34', $json($capability('1.34')), $prelude, 3],
] as [$kind, $version, $bytes, $stderr, $exit]) {
    $stem = $stream($kind, $bytes, $stderr, $exit);
    MapVersionMatrixEvidence::validate($kind, 'fixture', $version, $stem);
    wprism_check(true, "$kind $version admits its complete transport and exact premise");
    foreach (['empty', 'malformed', 'stdout-warning', 'stderr-warning', 'wrong-exit', 'foreign-pair'] as $fault) {
        $candidate = match ($fault) { 'empty' => '', 'malformed' => '{', 'stdout-warning' => "Warning: fixture\n" . $bytes, default => $bytes };
        $err = match ($fault) { 'stderr-warning' => "Warning: fixture\n", 'foreign-pair' => str_replace('fixture-cli2', 'foreign-cli2', $prelude), default => $stderr };
        $stem = $stream($kind, $candidate, $err, $fault === 'wrong-exit' ? 7 : $exit);
        wprism_check_throws(static fn() => MapVersionMatrixEvidence::validate($kind, 'fixture', $version, $stem),
            Throwable::class, "$kind $version rejects $fault");
    }
}
foreach (['agent-only', 'duplicate-terminal', 'trailing-output', 'wrong-subject'] as $fault) {
    $bytes = match ($fault) {
        'agent-only' => "✔ AGENT ROUNDTRIP PASSED (map-block-gutenberg; production promotion withheld)\n",
        'duplicate-terminal' => $positive . $positive,
        'trailing-output' => $positive . "unfinished work\n",
        default => str_replace('map-block-gutenberg', 'other', $positive),
    };
    $stem = $stream('positive', $bytes);
    wprism_check_throws(static fn() => MapVersionMatrixEvidence::validate('positive', 'fixture', '1.35', $stem), RuntimeException::class, "$fault cannot qualify the host roundtrip");
}
foreach (['installed' => '1.35', 'active' => false, 'native_loaded' => false, 'wrong_active' => true, 'key_preserved' => false] as $field => $value) {
    $record = array_replace($native, ['installed' => '1.34'], [$field => $value]);
    $stem = $stream('native', $json($record));
    wprism_check_throws(static fn() => MapVersionMatrixEvidence::validate('native', 'fixture', '1.34', $stem), RuntimeException::class, "old artifact cannot lose the $field premise");
}
$stem = $stream('credentials', $json(array_replace($credentials, ['deleted' => true])));
wprism_check_throws(static fn() => MapVersionMatrixEvidence::validate('credentials', 'fixture', '1.34', $stem), RuntimeException::class, 'an existing credential tombstone invalidates the preservation baseline');

// Execute the actual workflow and shared private snapshot/collector against
// filesystem fixtures. Only the external conformance and WP/Compose calls are
// simulated; no live process or native success is asserted by this suite.
$profile = MapLifecycleEvidence::refusalProfile('1.34');
$private = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'apply', 'reason_code' => $profile['reason_code']]
    + PrivateRefusalEvidence::graph(new RuntimeException($profile['nodes'][0]['message']));
$shell = <<<'SH'
set -euo pipefail
root="$1" capsule="$2" fixture="$3" fault="$4"
fail() { printf '%s\n' "$*" >&2; exit 1; }
say() { :; }
pass() { :; }
. "$root/sandbox/conformance/asserts.sh"
cd "$root/sandbox"
. "$capsule/tests/certify/version-matrix.sh"
cd "$fixture"
PAIR=fixture PORT1=8996 PORT2=8997 VMATRIX_CASES=0
WPRISM_EXPECTED_SOURCE_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export WPRISM_ARTIFACT_LIBRARY_ROOT="$root" WPRISM_SOURCE_ROOT="$root"
mktemp() { command mktemp -d "$fixture/sink.XXXXXX"; }
bash() {
  [ "$#" = 2 ] && [ "$1" = "$root/sandbox/conformance/run.sh" ] && [ "$2" = map-block-gutenberg ] || return 90
  [ "$CONF_PAIR" = "$PAIR" ] && [ "$CONF1_PORT" = "$PORT1" ] && [ "$CONF2_PORT" = "$PORT2" ] \
    && [ "$CONF_EXPECTED_SOURCE_SHA" = "$WPRISM_EXPECTED_SOURCE_SHA" ] || return 91
  printf 'positive\n' >> "$fixture/calls"
  command cat "$fixture/positive"
  [ "$fault" != positive-exit ] || return 7
}
artifact_library_jq() { printf '%064d\n' 0; }
fetch_artifact() {
  [ "$*" = 'map-block-gutenberg 1.34 cli2' ] || return 92
  printf 'fetch\n' >> "$fixture/calls"
  if [ "$fault" = wrong-artifact ]; then printf '/unverified.zip\n'; return; fi
  printf '/artifacts-cache/plugin-map-block-gutenberg-1.34-%064d.zip\n' 0
}
conformance_private_command_native() {
  command php "$root/sandbox/tests/lib/conformance_private_command.php" "$3" "$2" "$fixture/refusals" <"${4:-/dev/null}"
}
wp2() {
  case "$1/$2" in
    plugin/install)
      [ "$#" = 4 ] && [ "$3" = "/artifacts-cache/plugin-map-block-gutenberg-1.34-$(printf '%064d' 0).zip" ] && [ "$4" = --force ] || return 93
      printf 'install\n' >> "$fixture/calls"
      printf '1.34\n' > "$fixture/version"
      command cat "$fixture/install"
      ;;
    eval-file//siterepo/.tmp-map-lifecycle/lifecycle-native.php)
      local version
      version=$(<"$fixture/version")
      if [ "$version" = 1.34 ] && [ "$fault" = mutated-rows ]; then command cat "$fixture/native-mutant";
      else command cat "$fixture/native-$version"; fi
      ;;
    eval-file//siterepo/.tmp-map-deletion/deletion-native.php)
      if [ "$(<"$fixture/version")" = 1.34 ] && [ "$fault" = mutated-intent ]; then command cat "$fixture/credentials-mutant";
      else command cat "$fixture/credentials"; fi
      ;;
    wprism/capabilities)
      local version
      version=$(<"$fixture/version")
      command cat "$fixture/capability-$version"
      [ "$version" = 1.35 ] || return 3
      ;;
    wprism/apply)
      [ "$*" = 'wprism apply --repo=/siterepo --default-author=admin --format=json' ] || return 94
      printf 'refusal\n' >> "$fixture/calls"
      if [ "$fault" != absent-private ]; then
        cp "$fixture/private" "$fixture/refusals/20260914-120000-apply-aaaaaaaaaaaaaaaaaaaaaaaa.json"
      fi
      [ "$fault" != mutated-tree ] || printf 'changed\n' > "$fixture/siterepo/fixture2/state/post.md"
      command cat "$fixture/public"
      return 1
      ;;
    *) return 95 ;;
  esac
}
version_matrix_workflow
[ "$VMATRIX_CASES" = 2 ] || fail 'matrix did not finish exactly two cases'
printf 'MATRIX_READY\n'
SH;
foreach (['valid', 'positive-exit', 'positive-empty', 'positive-warning', 'wrong-artifact', 'install-warning',
    'inactive-old', 'mutated-rows', 'mutated-intent', 'mutated-tree', 'absent-private', 'wrong-private', 'public-extra'] as $fault) {
    $dir = $scratch . '/' . $fault;
    mkdir($dir, 0700);
    mkdir($dir . '/refusals', 0700);
    mkdir($dir . '/siterepo/fixture2/state', 0700, true);
    $old = array_replace($native, ['installed' => '1.34']);
    if ($fault === 'inactive-old') $old['active'] = false;
    $mutant = $old;
    $mutant['state']['posts']['sha256'] = str_repeat('d', 64);
    $privateCandidate = $private;
    if ($fault === 'wrong-private') $privateCandidate = array_replace($private, PrivateRefusalEvidence::graph(new RuntimeException('unrelated refusal')));
    $public = MapLifecycleEvidence::publicRefusal('1.34');
    if ($fault === 'public-extra') $public['unexpected'] = 'fixture-private-value';
    foreach ([
        'positive' => $fault === 'positive-empty' ? '' : ($fault === 'positive-warning' ? "Warning: fixture\n" : '') . $positive,
        'install' => ($fault === 'install-warning' ? "Warning: already active\n" : '') . $install,
        'native-1.35' => $json($native), 'native-1.34' => $json($old), 'native-mutant' => $json($mutant),
        'credentials' => $json($credentials), 'credentials-mutant' => $json(array_replace($credentials, ['intent_sha256' => str_repeat('d', 64)])),
        'capability-1.35' => $json($capability('1.35')), 'capability-1.34' => $json($capability('1.34')),
        'private' => $json($privateCandidate), 'public' => $json($public), 'version' => "1.35\n",
        'siterepo/fixture2/state/post.md' => "fixture canonical content\n", 'calls' => '',
    ] as $file => $bytes) $write($dir . '/' . $file, $bytes);
    [$status, $output, $diagnostic] = ShellProbe::run($shell, [$root, $capsule, $dir, $fault], $dir);
    wprism_check($fault === 'valid' ? $status === 0 && str_contains($output, 'MATRIX_READY') : $status !== 0 && !str_contains($output, 'MATRIX_READY'),
        "actual matrix workflow classifies $fault (exit $status)");
    if ($fault === 'valid' && $status !== 0) wprism_check_detail($diagnostic);
    $calls = file_get_contents($dir . '/calls');
    wprism_check_same(1, substr_count($calls, "positive\n"), "$fault invokes the full positive run only once");
    if ($fault === 'valid') wprism_check_same("positive\nfetch\ninstall\nrefusal\n", $calls, 'one pair runs the positive before actual-artifact install and private refusal');
    if (str_starts_with($fault, 'positive-')) wprism_check_same("positive\n", $calls, "$fault prevents every old-artifact action");
    if ($fault === 'wrong-artifact') wprism_check_same("positive\nfetch\n", $calls, 'an unpinned path cannot reach the installer');
    if (in_array($fault, ['wrong-private', 'public-extra', 'absent-private'], true)) {
        wprism_check(!str_contains($output . $diagnostic, 'fixture-private-value') && !str_contains($output, '"reason_code"'), "$fault cannot replay unvalidated refusal bytes");
    }
}

$preflight = <<<'SH'
set -euo pipefail
fail() { exit 1; }
cd "$1/../../sandbox"
. "$1/tests/certify/version-matrix.sh"
WPRISM_EXPECTED_SOURCE_SHA="$2"
version_matrix_preflight
[ "$WPRISM_SOURCE_ROOT" = "$MAP_MATRIX_ROOT" ] && [ "$WPRISM_ARTIFACT_PACKAGE" = map-block-gutenberg ]
printf 'PREFLIGHT_READY\n'
SH;
foreach (['', 'not-a-sha', str_repeat('a', 41), str_repeat('a', 40)] as $sha) {
    [$status, $output] = ShellProbe::run($preflight, [$capsule, $sha], $root);
    wprism_check(strlen($sha) === 40 ? $status === 0 && str_contains($output, 'PREFLIGHT_READY') : $status !== 0,
        'preflight requires a bounded candidate SHA and the certified exact host entry');
}
wprism_check_summary('map-block-gutenberg exact version matrix evidence');
