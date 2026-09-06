<?php
/** Replay the actual recapture acceptance/teardown caller; only WP-CLI is a fixture seam. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';

use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$relative = '/integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh';
$live = (string) file_get_contents($root . $relative);
$prior = is_dir($argv[1] ?? '') ? $argv[1] : $root;
$caller = (string) file_get_contents($prior . $relative);
$slice = static function (string $bytes, string $start, string $end): string {
    $a = strpos($bytes, $start);
    $b = $a === false ? false : strpos($bytes, $end, $a + strlen($start));
    if ($a === false || $b === false) throw new RuntimeException('the canonical evidence caller is absent');
    return substr($bytes, $a, $b - $a);
};
$assertions = $slice($live, 'assert_rmcombo_warning_free_capture() {', "\nassert_rmcombo_default_apply_ready() {");
$definitions = $slice($live, 'rmcombo_canonical_observation() {', 'run_leg() {');
$window = ShellProbe::captureBlock($caller, 'TARGET_RECAPTURE', 'capture_rmcombo_native_state TARGET_FINAL');
$scratch = sys_get_temp_dir() . '/wprism-canonical-recapture-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$raw = ['posts/product/en.md' => "---\ntitle: Portable\n---\nbody\0\xff\r\nwithout final newline", 'state.json' => "{\"b\":2,\"a\":1}\n"];
$probe = <<<'SH'
set -euo pipefail
root="$1" ROOT="$2" fault="$3" PAIR=rmcombocanonical
R1="$ROOT/sandbox/siterepo/${PAIR}1" R2="$ROOT/sandbox/siterepo/${PAIR}2"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$root/sandbox/conformance/asserts.sh"
. "$root/sandbox/tests/lib/private_command_capture.sh"
wp2() {
  [ "$*" = 'wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-final --format=json' ] || return 55
  : >"$ROOT/command-called"
  case "$fault" in
    missing-target) : ;;
    linked-target) ln -s "$ROOT/fixture-recapture" "$R2/.tmp-rmcombo-final" ;;
    *) cp -R "$ROOT/fixture-recapture" "$R2/.tmp-rmcombo-final" ;;
  esac
  [ "$fault" != source-drift ] || printf 'source changed during capture' >>"$R1/state/state.json"
  [ "$fault" != command-compose ] || printf ' Container wprism-%s-cli2-run-abcd %s \n' "$PAIR" Creating "$PAIR" Created >&2
  [ "$fault" != command-wrong-site ] || printf ' Container wprism-%s-cli1-run-abcd Created \n' "$PAIR" >&2
  [ "$fault" != command-noise ] || printf 'unrecognized command diagnostic\n' >&2
  [ "$fault" != command-pointer ] || printf 'private command diagnostics (unverified): %s\n' "${__wprism_private_capture_directory:-/absent}" >&2
  [ "$fault" != command-warning ] || printf 'PHP Warning: command transport in /fixture.php on line 1\n' >&2
  [ "$fault" != command-empty ] || return 0
  if [ "$fault" = command-json-warning ]; then
    printf '{"status":"ok","command":"capture","warnings":["fixture warning"]}\n'
  else
    printf '{"status":"ok","command":"capture","warnings":[]}\n'
  fi
  [ "$fault" != command-duplicate ] || printf '{"status":"ok","command":"capture","warnings":[]}\n'
  [ "$fault" != command-nonzero ] || return 7
}
SH;
$sequence = 0;
$setup = static function (string $fault) use ($scratch, $root, $raw, &$sequence): string {
    $case = $scratch . '/case-' . ++$sequence;
    foreach (['sandbox/tmp', 'sandbox/tests', 'sandbox/siterepo/rmcombocanonical1/state/posts/product',
        'sandbox/siterepo/rmcombocanonical2', 'fixture-recapture/posts/product', 'fixture-recapture/empty',
        'sandbox/siterepo/rmcombocanonical1/state/empty'] as $directory) mkdir($case . '/' . $directory, 0700, true);
    symlink($root . '/sandbox/tests/lib', $case . '/sandbox/tests/lib');
    foreach ($raw as $name => $bytes) {
        file_put_contents($case . '/sandbox/siterepo/rmcombocanonical1/state/' . $name, $bytes);
        file_put_contents($case . '/fixture-recapture/' . $name, $bytes);
    }
    if ($fault === 'mismatch') {
        file_put_contents($case . '/fixture-recapture/posts/product/en.md', "different managed body\0\xff");
        file_put_contents($case . '/fixture-recapture/posts/product/neighbor.md', 'target-only authored entity');
    }
    if ($fault === 'large-target') file_put_contents($case . '/fixture-recapture/large', str_repeat('x', FilesystemTreeEvidence::MAX_BYTES + 1));
    $source = $case . '/sandbox/siterepo/rmcombocanonical1/state';
    if ($fault === 'large-source') file_put_contents($source . '/large', str_repeat('x', FilesystemTreeEvidence::MAX_BYTES + 1));
    if ($fault === 'linked-source') { rename($source, $case . '/source-tree'); symlink($case . '/source-tree', $source); }
    if ($fault === 'missing-source') rename($source, $case . '/source-tree');
    return $case;
};
$readySink = null;
foreach (['ready', 'mismatch', 'source-drift', 'command-compose', 'command-wrong-site', 'command-noise', 'command-pointer',
    'command-warning', 'command-json-warning', 'command-empty',
    'command-duplicate', 'command-nonzero', 'missing-target', 'linked-target', 'large-target',
    'missing-source', 'linked-source', 'large-source'] as $fault) {
    $case = $setup($fault);
    [$status, $stdout, $stderr] = ShellProbe::run($probe . "\n" . $assertions . "\n" . $definitions . "\n" . $window . "\nprintf 'CANONICAL_READY\\n'\n",
        [$root, $case, $fault], $root);
    $healthy = in_array($fault, ['ready', 'command-compose'], true);
    wprism_check($healthy ? $status === 0 && str_contains($stdout, 'CANONICAL_READY') : $status !== 0 && !str_contains($stdout, 'CANONICAL_READY'),
        "actual canonical caller classifies $fault without weakening complete equality (exit $status)");
    if ($healthy && $status !== 0) fwrite(STDERR, substr($stderr, 0, 2048));
    if (in_array($fault, ['missing-source', 'linked-source', 'large-source'], true)) {
        wprism_check(!file_exists($case . '/command-called'), "$fault baseline failure prevents the product command");
    } else {
        wprism_check(file_exists($case . '/command-called'), "$fault exercises the exact selected product command");
    }
    wprism_check(!str_contains($stdout . $stderr, base64_encode($raw['posts/product/en.md']))
        && !str_contains($stdout . $stderr, 'body' . "\0\xff"), "$fault never exposes retained canonical contents publicly");
    $sinks = glob($case . '/sandbox/tmp/wprism-rmcombo-canonical.rmcombocanonical.*') ?: [];
    wprism_check(count($sinks) === 1, "$fault allocates one diagnostic lifecycle outside the site repository");
    if (count($sinks) !== 1) continue;
    $sink = $sinks[0];
    $files = glob($sink . '/*') ?: [];
    wprism_check_same(15, count($files), "$fault retains all five stages and their three streams");
    wprism_check_same(0700, fileperms($sink) & 07777, "$fault sink stays private");
    foreach ($files as $file) wprism_check_same(0600, fileperms($file) & 07777, "$fault private stream " . basename($file));
    $remove($case . '/sandbox/siterepo');
    if (!in_array($fault, ['ready', 'mismatch', 'source-drift', 'command-compose', 'command-wrong-site', 'command-noise', 'command-pointer',
        'command-warning', 'command-json-warning',
        'command-empty', 'command-duplicate', 'command-nonzero'], true)) continue;
    $beforeBytes = PrivateCommandOutput::readObject($sink . '/baseline');
    $afterBytes = PrivateCommandOutput::readObject($sink . '/private');
    $before = json_decode($beforeBytes, true, 32, JSON_THROW_ON_ERROR);
    $after = json_decode($afterBytes, true, 32, JSON_THROW_ON_ERROR);
    FilesystemTreeEvidence::assertRecord($before['source'], 'state');
    FilesystemTreeEvidence::assertRecord($after['source'], 'state');
    FilesystemTreeEvidence::assertRecord($after['recapture'], '.tmp-rmcombo-final');
    wprism_check_same(hash('sha256', $beforeBytes), $after['baseline_sha256'], "$fault retains its exact source-before binding after site teardown");
    $decode = static fn(array $tree): array => array_column(array_map(static fn(array $row): array =>
        ['path' => $row['path'], 'value' => base64_decode($row['contents_base64'], true)], $tree['files']), 'value', 'path');
    wprism_check_same($raw, $decode($before['source']), "$fault preserves every original source byte and pathname");
    $expected = $raw;
    if ($fault === 'source-drift') $expected['state.json'] .= 'source changed during capture';
    wprism_check_same($expected, $decode($after['source']), "$fault preserves the separate source-after observation");
    $expected = $raw;
    if ($fault === 'mismatch') {
        $expected['posts/product/en.md'] = "different managed body\0\xff";
        $expected['posts/product/neighbor.md'] = 'target-only authored entity';
        ksort($expected, SORT_STRING);
    }
    wprism_check_same($expected, $decode($after['recapture']), "$fault preserves target-only entities and exact managed recapture bytes after teardown");
    wprism_check(in_array('empty', $after['recapture']['directories'], true), "$fault retains empty directory topology too");
    if ($fault === 'ready') $readySink = [$case, $sink, $beforeBytes, $afterBytes];
}

if (is_array($readySink)) {
    [$case, $sink, $beforeBytes, $afterBytes] = $readySink;
    foreach (['ready', 'pair', 'phase', 'authority', 'format', 'extra', 'binding', 'source-root', 'recapture-root',
        'source-content', 'recapture-content', 'file-root', 'baseline-bytes'] as $fault) {
        $record = json_decode($afterBytes, true, 32, JSON_THROW_ON_ERROR);
        switch ($fault) {
            case 'pair': $record['pair'] = 'foreignpair'; break;
            case 'phase': $record['phase'] = 'baseline'; break;
            case 'authority': $record['authority'] = true; break;
            case 'format': $record['format'] = 'unknown'; break;
            case 'extra': $record['verified'] = true; break;
            case 'binding': $record['baseline_sha256'] = str_repeat('a', 64); break;
            case 'source-root': $record['source']['root'] = 'other'; break;
            case 'recapture-root': $record['recapture']['root'] = 'other'; break;
            case 'source-content': $record['source']['files'][0]['contents_base64'] = base64_encode('different'); break;
            case 'recapture-content': $record['recapture']['files'][0]['contents_base64'] = base64_encode('different'); break;
            case 'file-root': $record['recapture']['directories'] = []; break;
        }
        file_put_contents($sink . '/private.stdout', json_encode($record, JSON_THROW_ON_ERROR));
        file_put_contents($sink . '/baseline.stdout', $beforeBytes . ($fault === 'baseline-bytes' ? "\n" : ''));
        [$status, $stdout, $stderr] = ShellProbe::run($probe . "\n" . $definitions . "\n" . 'rmcombo_validate_canonical_observation "$4"',
            [$root, $case, 'ready', $sink . '/private'], $root);
        wprism_check($fault === 'ready' ? $status === 0 && $stdout === '' && $stderr === '' : $status !== 0,
            "actual post-teardown canonical validator classifies $fault");
    }
}
wprism_check_summary('combined canonical recapture retains full private bytes without waiving equality');
