<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once $root . '/adapter-packages/woocommerce/fixtures/missing-code-evidence.php';

$failure = null;
try {
    WPrism\LifecyclePlanner::deployment_status_from_observation(new WPrism\Policy(),
        ['active_plugins' => ['woocommerce/woocommerce.php']], false,
        ['recorded_raw' => null, 'plugins' => [], 'active_plugins' => [], 'plugin_exists' => []]);
} catch (RuntimeException $caught) { $failure = $caught; }
wprism_check($failure instanceof RuntimeException, 'actual lifecycle preflight refuses absent declared Woo code');
$profile = WooCommerceMissingCodeEvidence::profile();
WPrismTest\PrivateRefusalReceipt::assertGraph(WPrism\PrivateRefusalEvidence::graph($failure), $profile['nodes']);
wprism_check(true, 'independently declared cause matches the actual product refusal graph');
$directory = sys_get_temp_dir() . '/woo-missing-code-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$write = static function (string $stage, array $record, string $exit = "0\n", string $stderr = '') use ($directory): void {
    foreach (['stdout' => json_encode($record, JSON_THROW_ON_ERROR), 'stderr' => $stderr, 'exit' => $exit] as $suffix => $bytes) {
        file_put_contents("$directory/$stage.$suffix", $bytes);
        chmod("$directory/$stage.$suffix", 0600);
    }
};
$record = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'lifecycle-status',
    'reason_code' => 'lifecycle_status_failed', ...WPrism\PrivateRefusalEvidence::graph($failure)];
$name = '20260915-090001-lifecycle-status-' . str_repeat('b', 24) . '.json';
$diagnostic = static function (array $record) use ($name): array {
    $bytes = json_encode($record, JSON_THROW_ON_ERROR);
    return ['command' => 'lifecycle-status', 'format' => 'wprism-private-refusal-diagnostic/v1', 'new_records' => 1,
        'purpose' => 'diagnostic_only', 'verified' => false, 'records' => [[
            'bytes' => strlen($bytes), 'contents_base64' => base64_encode($bytes), 'name' => $name, 'sha256' => hash('sha256', $bytes),
        ]]];
};
$baseline = ['command' => 'lifecycle-status', 'baseline' => '[]'];
try {
    $write('baseline', $baseline);
    $write('private', $diagnostic($record));
    WooCommerceMissingCodeEvidence::verify($directory, 'woorefusal');
    wprism_check(true, 'fresh complete private evidence for the product cause passes');
    foreach (['wrong-cause', 'wrong-command', 'incomplete', 'stale', 'missing', 'multiple', 'collector-failed', 'diagnostics'] as $fault) {
        $bad = $record;
        $base = $baseline;
        if ($fault === 'wrong-cause') $bad = array_replace($bad, WPrism\PrivateRefusalEvidence::graph(new RuntimeException('unrelated safety gate')));
        if ($fault === 'wrong-command') $bad['command'] = 'apply';
        if ($fault === 'incomplete') $bad['traversal']['record_complete'] = false;
        if ($fault === 'stale') $base['baseline'] = json_encode([$name]);
        $captured = $diagnostic($bad);
        if ($fault === 'missing') { $captured['new_records'] = 0;
        $captured['records'] = []; }
        if ($fault === 'multiple') { $captured['new_records'] = 2;
        $captured['records'][] = $captured['records'][0]; }
        $write('baseline', $base);
        $write('private', $captured, $fault === 'collector-failed' ? "1\n" : "0\n", $fault === 'diagnostics' ? "PHP Warning: diagnostic\n" : '');
        wprism_check_throws(static fn() => WooCommerceMissingCodeEvidence::verify($directory, 'woorefusal'), RuntimeException::class,
            "missing-code evidence rejects $fault");
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
// Execute the real shell block and shared capture callbacks: a failed validator
// must never publish the diagnostic pointer that admits the expected refusal.
$source = file_get_contents($root . '/adapter-packages/woocommerce/tests/conformance/check.sh');
$start = strpos($source, 'read -r -a PAIR_COMPOSE');
if ($start === false) throw new RuntimeException('missing real lifecycle acceptance block');
$end = strpos($source, 'WOO_SHA=', $start);
$block = substr($source, $start, $end - $start);
$dir = sys_get_temp_dir() . '/woo-shell-' . bin2hex(random_bytes(5));
mkdir($dir, 0700);
$pair = 'wooshell' . bin2hex(random_bytes(5));
$script = <<<'SH'
set -euo pipefail
export WPRISM_ARTIFACT_LIBRARY_ROOT="$1" CONF_PAIR="$4" COMPOSE="docker compose"
case_name="$2"; probe="$3"
cd "$1/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
docker() {
  case "$*" in *'--entrypoint php cli2 '*) ;; *) return 97 ;; esac
  mode="${@: -3:1}"
  if [ "$mode" = snapshot ]; then cat "$probe/baseline.json"; return; fi
  [ "$mode" = collect ] || return 98
  [ "$case_name" != collector-failed ] || return 1
  cat "$probe/diagnostic.json"
}
host_wprism() {
  [ "$*" = 'conf2 deploy' ] || return 97
  printf '%s\n' 'wprism: deploy: lifecycle preflight failed; no target mutation occurred' >&2
  printf '%s\n' '{"format":"wprism-command-refusal/v1","ok":false,"reason_code":"lifecycle_status_failed","details_redacted":true}'
  [ "$case_name" != success ] || return 0
  return 1
}
LIFECYCLE_BEFORE=stable
woocommerce_storage_hash() { [ "$case_name" != mutation ] && printf stable || printf changed; }
SH;
file_put_contents($dir.'/probe.sh', $script."\n".$block);
$profile = WooCommerceMissingCodeEvidence::profile();
try {
foreach (['valid','wrong-cause','stale','collector-failed','success','mutation'] as $case) {
 $name = '20260915-090001-lifecycle-status-'.str_repeat('b', 24).'.json';
 $record = ['format' => 'wprism-private-refusal-evidence/v2','command' => 'lifecycle-status','reason_code' => 'lifecycle_status_failed',...WPrism\PrivateRefusalEvidence::graph(new RuntimeException($case === 'wrong-cause' ? 'unrelated' : $profile['nodes'][0]['message']))];
 $bytes = json_encode($record);
 file_put_contents($dir.'/baseline.json', json_encode(['command' => 'lifecycle-status','baseline' => $case === 'stale' ? json_encode([$name]) : '[]']));
 file_put_contents($dir.'/diagnostic.json', json_encode(['command' => 'lifecycle-status','format' => 'wprism-private-refusal-diagnostic/v1','new_records' => 1,'purpose' => 'diagnostic_only','verified' => false,'records' => [['name' => $name,'bytes' => strlen($bytes),'contents_base64' => base64_encode($bytes),'sha256' => hash('sha256', $bytes)]]]));
 $p = proc_open(['/bin/bash',$dir.'/probe.sh',$root,$case,$dir,$pair], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
 fclose($pipes[0]);
 $out = stream_get_contents($pipes[1]);
 $err = stream_get_contents($pipes[2]);
 fclose($pipes[1]);
 fclose($pipes[2]);
 $rc = proc_close($p);
 wprism_check(($rc === 0) === ($case === 'valid'), "actual missing-code shell admission: $case");
}
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    rmdir($dir);
    // This random pair belongs only to this test invocation; preserve other captures.
    foreach (glob($root . '/sandbox/tmp/woocommerce-missing-code.' . $pair . '.*') ?: [] as $capture) {
        foreach (glob($capture . '/*') ?: [] as $file) unlink($file);
        rmdir($capture);
    }
}

wprism_check_summary('WooCommerce missing-code lifecycle evidence');
