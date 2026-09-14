<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 2) . '/lib/ShellProbe.php';
require_once dirname(__DIR__, 2) . '/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 2) . '/lib/PrivateRefusalReceipt.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PrivateRefusalEvidence.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$scratch = sys_get_temp_dir() . '/wprism-private-command-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$probe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_CASE="$2" PROBE_ROOT="$3"
. "$ROOT/sandbox/tests/lib/private_command_capture.sh"
umask 022
snapshot() {
  [ "$#" -eq 2 ] && [ "$1" = '' ] && [ "$2" = 'quotes " and spaces $literal' ] || return 81
  printf 'snapshot\n' >>"$PROBE_ROOT/trace"
  printf 'private-baseline-canary\000bytes\n'
  case "$PROBE_CASE" in
    baseline-failed) printf 'private-reader-canary\n' >&2; return 7 ;;
    baseline-exit) exit 23 ;;
    baseline-noisy) printf 'private-reader-canary\n' >&2 ;;
    baseline-invalid) printf 'invalid\n' ;;
  esac
}
collect() {
  [ "$#" -eq 2 ] && [ "$1" = 'collector arg' ] || return 82
  cmp -s "$PROBE_ROOT/expected-baseline" "$2" || return 83
  printf 'collect\n' >>"$PROBE_ROOT/trace"
  printf 'private-delta-canary\000bytes\n'
  case "$PROBE_CASE" in
    private-failed) printf 'private-reader-canary\n' >&2; return 9 ;;
    private-exit) exit 24 ;;
    private-noisy) printf 'private-reader-canary\n' >&2 ;;
    private-invalid) printf 'invalid\n' ;;
  esac
}
validate() {
  [ "$#" -eq 2 ] && [ "$1" = 'validator arg' ] || return 84
  local stage="${2##*/}"
  printf 'validate-%s\n' "$stage" >>"$PROBE_ROOT/trace"
  cmp -s "$PROBE_ROOT/expected-$stage" "$2.stdout" && [ ! -s "$2.stderr" ] || return 10
  case "$PROBE_CASE:$stage" in
    baseline-validator-failed:baseline|private-validator-failed:private) printf 'private-validator-canary\n' >&2; return 11 ;;
    baseline-validator-noisy:baseline|private-validator-noisy:private) printf 'private-validator-canary\n' ;;
  esac
}
command_body() {
  [ "$#" -eq 3 ] && [ "$1" = '' ] && [ "$2" = 'two words' ] && [ "$3" = '$not-expanded;literal' ] || return 85
  printf 'command\n' >>"$PROBE_ROOT/trace"
  : >"$PROBE_ROOT/host-publication"
  printf 'public-stderr\n' >&2
  printf '{"ok":true}\n'
  case "$PROBE_CASE" in command-failed) return 7 ;; command-exit) exit 25 ;; esac
}
snapshot_args=(snapshot '' 'quotes " and spaces $literal')
collect_args=(collect 'collector arg')
validate_args=(validate 'validator arg')
prefix="$PROBE_ROOT/diagnostic"
case "$PROBE_CASE" in
  empty-callback) collect_args=() ;;
  scalar-callback) unset collect_args; collect_args=collect ;;
  relative-parent) prefix=relative/diagnostic ;;
  missing-parent) prefix="$PROBE_ROOT/missing/diagnostic" ;;
  unsafe-name) prefix="$PROBE_ROOT/unsafe name" ;;
esac
wprism_private_command_capture "$prefix" snapshot_args collect_args validate_args -- \
  command_body '' 'two words' '$not-expanded;literal'
SH;
$cases = ['ready', 'command-failed', 'command-exit', 'baseline-failed', 'baseline-exit', 'baseline-noisy',
    'baseline-invalid', 'baseline-validator-failed', 'baseline-validator-noisy', 'private-failed',
    'private-exit', 'private-noisy', 'private-invalid', 'private-validator-failed', 'private-validator-noisy',
    'empty-callback', 'scalar-callback', 'relative-parent', 'missing-parent', 'unsafe-name'];
foreach ($cases as $case) {
    $directory = $scratch . '/quoted " parent ' . $case;
    mkdir($directory, 0700);
    file_put_contents($directory . '/expected-baseline', "private-baseline-canary\0bytes\n");
    file_put_contents($directory . '/expected-private', "private-delta-canary\0bytes\n");
    [$status, $stdout, $stderr] = ShellProbe::run($probe, [$root, $case, $directory], $root);
    $invalid = in_array($case, ['empty-callback', 'scalar-callback', 'relative-parent', 'missing-parent', 'unsafe-name'], true);
    $baselineFailure = str_starts_with($case, 'baseline-');
    $privateFailure = str_starts_with($case, 'private-');
    $expectedStatus = match ($case) { 'ready' => 0, 'command-failed' => 7, 'command-exit' => 25, default => 1 };
    wprism_check_same($expectedStatus, $status, "$case returns the original command status only after a complete diagnostic lifecycle");
    wprism_check(!str_contains($stdout . $stderr, 'private-baseline-canary')
        && !str_contains($stdout . $stderr, 'private-delta-canary')
        && !str_contains($stdout . $stderr, 'private-reader-canary')
        && !str_contains($stdout . $stderr, 'private-validator-canary'),
        "$case cannot disclose native private-reader or validator bytes");
    $sinks = glob($directory . '/diagnostic.*') ?: [];
    $trace = is_file($directory . '/trace') ? file_get_contents($directory . '/trace') : '';
    if ($invalid) {
        wprism_check($sinks === [] && $trace === '' && !is_file($directory . '/host-publication'),
            "$case refuses malformed callback/sink configuration before allocation or command execution");
    } else {
        wprism_check_same(1, count($sinks), "$case owns exactly one durable diagnostic sink");
        $sink = $sinks[0];
        $files = glob($sink . '/*') ?: [];
        wprism_check(count($files) === 15 && (fileperms($sink) & 0777) === 0700
            && count(array_filter($files, static fn(string $file): bool => is_file($file) && !is_link($file)
                && (fileperms($file) & 0777) === 0600)) === 15,
            "$case retains all five complete private stage transports/statuses with exact modes");
        $baselineStatus = match ($case) { 'baseline-failed' => 7, 'baseline-exit' => 23, default => 0 };
        wprism_check_same("$baselineStatus\n", file_get_contents($sink . '/baseline.exit'), "$case retains the native baseline status before validation");
        if ($baselineFailure) {
            wprism_check(!str_contains($trace, "command\n") && !is_file($directory . '/host-publication')
                && file_get_contents($sink . '/command.exit') === '', "$case never executes or invents a status for the protected command");
        } else {
            wprism_check(str_starts_with($trace, "snapshot\nvalidate-baseline\ncommand\ncollect\n"),
                "$case collects after the exact command and before any caller acceptance");
            $commandStatus = match ($case) { 'command-failed' => 7, 'command-exit' => 25, default => 0 };
            wprism_check_same("$commandStatus\n", file_get_contents($sink . '/command.exit'), "$case retains the original command exit even if collection fails");
            wprism_check_same("{\"ok\":true}\n", file_get_contents($sink . '/command.stdout'), "$case preserves the complete original public stdout bytes");
            wprism_check_same("public-stderr\n", file_get_contents($sink . '/command.stderr'), "$case preserves the complete original public stderr bytes");
            wprism_check((fileperms($directory . '/host-publication') & 0044) === 0044,
                "$case private allocation cannot make cross-uid host publication unreadable");
            $privateStatus = match ($case) { 'private-failed' => 9, 'private-exit' => 24, default => 0 };
            wprism_check_same("$privateStatus\n", file_get_contents($sink . '/private.exit'), "$case retains the exact native collector status");
        }
        wprism_check($baselineFailure || $privateFailure ? $stdout === ''
            : $stdout === "{\"ok\":true}\n" && str_ends_with($stderr, "public-stderr\n"),
            "$case publishes no public success before private validation and otherwise preserves each public stream");
        foreach ($files as $file) unlink($file);
        rmdir($sink);
    }
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
$mergedRoot = $scratch . '/merged';
mkdir($mergedRoot, 0700);
file_put_contents($mergedRoot . '/expected-baseline', "private-baseline-canary\0bytes\n");
file_put_contents($mergedRoot . '/expected-private', "private-delta-canary\0bytes\n");
$mergedProbe = str_replace(
    '. "$ROOT/sandbox/tests/lib/private_command_capture.sh"',
    '. "$ROOT/sandbox/tests/lib/private_command_capture.sh"' . "\n"
        . 'fail() { printf "%s\\n" "$*" >&2; exit 1; }' . "\n"
        . '. "$ROOT/sandbox/conformance/asserts.sh"',
    $probe
);
$mergedProbe = str_replace("\nwprism_private_command_capture ", "\ncapture_wprism_json_success answer 'shared private JSON caller' wprism_private_command_capture ", $mergedProbe);
[$status, $stdout, $stderr] = ShellProbe::run($mergedProbe . "\nprintf '%s\\n' \"\$answer\"\n", [$root, 'ready', $mergedRoot], $root);
wprism_check_same(0, $status, 'the real shared JSON caller completes the privately captured command');
wprism_check_same("{\"ok\":true}\n", $stdout, 'the real shared JSON caller publishes exactly the command JSON');
wprism_check($status === 0 && $stdout === "{\"ok\":true}\n"
    && str_contains($stderr, 'private command diagnostics (unverified): ')
    && str_contains($stderr, 'public-stderr') && !str_contains($stdout . $stderr, 'private-delta-canary'),
    'the real shared JSON caller accepts the final public answer without mistaking a private diagnostic pointer for JSON');
foreach (glob($mergedRoot . '/diagnostic.*') ?: [] as $sink) {
    foreach (glob($sink . '/*') ?: [] as $file) unlink($file);
    rmdir($sink);
}
foreach (glob($mergedRoot . '/*') ?: [] as $file) unlink($file);
rmdir($mergedRoot);

// Execute the actual initial-Apply call site and shared native PHP reader;
// only Docker transport and the protected command are controlled offline.
// The previous unwrapped call must lose its private record on simulated pair
// teardown, even though it still correctly refuses the public command.
$source = (string) file_get_contents($root . '/sandbox/conformance/run.sh');
$start = strpos($source, "capture_wprism_json_checked \\\n  APPLY_JSON");
$end = strpos($source, 'export APPLY_JSON', $start ?: 0);
if ($start === false || $end === false) throw new RuntimeException('missing initial conformance Apply call site');
$block = substr($source, $start, $end - $start);
$nativeProbe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_ROOT="$2" PROBE_CASE="$3"
PROBE_SERVICE="${4:-cli2}" PROBE_COMMAND="${5:-apply}"
WPRISM_ARTIFACT_LIBRARY_ROOT="$PROBE_ROOT" CONF_PAIR=conformanceprobe
ADOPT_BY_SLUG=terms,posts,menus REV=fixture-revision
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
. "$ROOT/sandbox/tests/lib/conformance_private_command.sh"
compose_fixture() {
  [ "$1" = 'quoted " compose argument' ] || return 80
  shift
  [ "$#" -eq 14 ] && [ "$1 $2 $3 $4" = 'run --rm -T --volume' ] \
    && [ "$5" = "$PROBE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" ] \
    && [ "$6" = --volume ] \
    && [ "$7" = "$PROBE_ROOT/sandbox/tests/lib/conformance_private_command.php:/wprism-test/conformance_private_command.php:ro" ] \
    && [ "$8 $9 ${10} ${11}" = "--entrypoint php $PROBE_SERVICE /wprism-test/conformance_private_command.php" ] \
    && [ "${13} ${14}" = "$PROBE_COMMAND /siterepo/.wprism/refusals" ] || return 81
  local mode="${12}" status=0
  printf '%s\n' "$mode" >>"$PROBE_ROOT/trace"
  case "$PROBE_CASE:$mode" in
    baseline-invalid:snapshot|collector-invalid:collect) printf '{}\n'; return ;;
    baseline-foreign:snapshot) printf '{"command":"plan","baseline":"[]"}\n'; return ;;
    collector-empty:collect) return ;;
    baseline-failed:snapshot|collector-failed:collect) printf 'private-reader-canary\n' >&2; return 7 ;;
    baseline-noisy:snapshot|collector-noisy:collect) printf 'PHP Warning: private-reader-canary\n' >&2 ;;
    wrong-site:collect) printf ' Container wprism-foreign-cli2-run-0123456789ab Created \n' >&2 ;;
    *) printf ' Container wprism-conformanceprobe-%s-run-0123456789ab Creating \n Container wprism-conformanceprobe-%s-run-0123456789ab Created \n' "$PROBE_SERVICE" "$PROBE_SERVICE" >&2 ;;
  esac
  php "$ROOT/sandbox/tests/lib/conformance_private_command.php" "$mode" "$PROBE_COMMAND" "$PROBE_ROOT/refusals" || status=$?
  if [ "$PROBE_CASE:$mode" = collector-extra-json:collect ]; then printf '{}\n'; fi
  return "$status"
}
PAIR_COMPOSE=(compose_fixture 'quoted " compose argument')
wp_conf2() {
  [ "$#" -eq 7 ] && [ "$1 $2 $3 $4 $5 $6 $7" = 'wprism apply --repo=/siterepo --adopt-by-slug=terms,posts,menus --default-author=admin --revision=fixture-revision --json' ] || return 82
  printf 'command\n' >>"$PROBE_ROOT/trace"
  if [ "$PROBE_CASE" != ready ] && [ "$PROBE_CASE" != stale ]; then
    cp "$PROBE_ROOT/new-record" "$PROBE_ROOT/refusals/20260907-120001-apply-bbbbbbbbbbbbbbbbbbbbbbbb.json"
    chmod 600 "$PROBE_ROOT/refusals/20260907-120001-apply-bbbbbbbbbbbbbbbbbbbbbbbb.json"
    if [ "$PROBE_CASE" = maximum-records ]; then
      local name
      for name in cccccccccccccccccccccccc dddddddddddddddddddddddd eeeeeeeeeeeeeeeeeeeeeeee; do
        cp "$PROBE_ROOT/new-record" "$PROBE_ROOT/refusals/20260907-120001-apply-$name.json"
        chmod 600 "$PROBE_ROOT/refusals/20260907-120001-apply-$name.json"
      done
    fi
  fi
  case "$PROBE_CASE" in
    ready) printf '{"ok":true,"canary":"clean","verification":{"result":"pass"},"warnings":[]}\n'; return 0 ;;
    public-noisy) printf 'PHP Warning: public diagnostic\n' >&2; printf '{"ok":true,"canary":"clean","verification":{"result":"pass"},"warnings":[]}\n'; return 0 ;;
    *) printf '{"ok":false,"error":"apply_failed"}\n'; return 1 ;;
  esac
}
SH;
$nativeRecord = json_encode(['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'apply', 'reason_code' => 'apply_failed',
    ...\WPrism\PrivateRefusalEvidence::graph(new RuntimeException('retained initial Apply private canary'))], JSON_THROW_ON_ERROR);
$nativeCases = ['ready', 'refusal', 'stale', 'malformed-record', 'maximum-records', 'public-noisy', 'baseline-invalid', 'baseline-foreign',
    'baseline-failed', 'baseline-noisy', 'collector-invalid', 'collector-empty', 'collector-extra-json', 'collector-failed',
    'collector-noisy', 'wrong-site', 'prior-unwrapped'];
foreach ($nativeCases as $case) {
    $directory = $scratch . '/conformance " ' . $case;
    mkdir($directory . '/sandbox/tests', 0700, true);
    mkdir($directory . '/sandbox/tmp', 0700);
    symlink($root . '/sandbox/tests/lib', $directory . '/sandbox/tests/lib');
    mkdir($directory . '/refusals', 0700);
    file_put_contents($directory . '/new-record', match ($case) {
        'malformed-record' => "broken JSON\0private canary", 'maximum-records' => str_pad($nativeRecord, 262144), default => $nativeRecord,
    });
    file_put_contents($directory . '/refusals/20260907-120000-apply-aaaaaaaaaaaaaaaaaaaaaaaa.json', $nativeRecord);
    chmod($directory . '/refusals/20260907-120000-apply-aaaaaaaaaaaaaaaaaaaaaaaa.json', 0600);
    $actualBlock = $case === 'prior-unwrapped' ? str_replace("  conformance_private_command cli2 apply \\\n", '', $block) : $block;
    [$status, $stdout, $stderr] = ShellProbe::run($nativeProbe . "\n" . $actualBlock . "\nprintf 'APPLY_ACCEPTED\\n'\n", [$root, $directory, $case], $root);
    wprism_check($case === 'ready' ? $status === 0 && str_contains($stdout, 'APPLY_ACCEPTED')
        : $status !== 0 && !str_contains($stdout, 'APPLY_ACCEPTED'), "$case: the real conformance initial Apply preserves its success/refusal gate");
    wprism_check(!str_contains($stdout . $stderr, 'private canary') && !str_contains($stdout . $stderr, 'private-reader-canary'),
        "$case: actual conformance never publishes private record or reader bytes");
    $sinks = glob($directory . '/sandbox/tmp/wprism-conformance-apply.conformanceprobe.*') ?: [];
    $trace = is_file($directory . '/trace') ? file_get_contents($directory . '/trace') : '';
    if ($case === 'prior-unwrapped') {
        wprism_check($sinks === [] && $trace === "command\n", 'prior unwrapped call loses private evidence despite correctly refusing the command');
    } else {
        wprism_check(count($sinks) === 1 && $trace === (str_starts_with($case, 'baseline-') ? "snapshot\n" : "snapshot\ncommand\ncollect\n"),
            "$case: exact shared lifecycle validates baseline first and collects after every executed initial Apply");
    }
    // Model owned-pair teardown before decoding host records: no subsequent
    // native read can manufacture or repair the retained cause.
    foreach (glob($directory . '/refusals/*') ?: [] as $file) unlink($file);
    rmdir($directory . '/refusals');
    if (in_array($case, ['ready', 'refusal', 'stale', 'malformed-record', 'maximum-records', 'public-noisy'], true) && count($sinks) === 1) {
        $diagnostic = json_decode(\WPrismTest\PrivateCommandOutput::readObject($sinks[0] . '/private',
            '/\A Container wprism-conformanceprobe-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/',
            \WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
        \WPrismTest\PrivateRefusalReceipt::assertDiagnostic($diagnostic, 'apply');
        wprism_check_same(in_array($case, ['ready', 'stale'], true) ? []
            : array_fill(0, $case === 'maximum-records' ? 4 : 1, (string) file_get_contents($directory . '/new-record')),
            array_map(static fn(array $row): string => base64_decode($row['contents_base64'], true), $diagnostic['records']),
            "$case: exact fresh private bytes survive disposable cleanup without copying the old matching cause");
    }
    foreach ($sinks as $sink) {
        $files = glob($sink . '/*') ?: [];
        wprism_check(count($files) === 15 && (fileperms($sink) & 0777) === 0700
            && count(array_filter($files, static fn(string $file): bool => (fileperms($file) & 0777) === 0600)) === 15,
            "$case: actual conformance retains all fifteen private transport/status files");
        foreach ($files as $file) unlink($file);
        rmdir($sink);
    }
    unlink($directory . '/sandbox/tests/lib');
    rmdir($directory . '/sandbox/tests');
    rmdir($directory . '/sandbox/tmp');
    rmdir($directory . '/sandbox');
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}

// Execute all three real Capture sites with the same transport/collector as
// initial Apply. The trap removes native records before the host checks them.
$captureProbe = $nativeProbe . <<<'SH'

wp_capture_probe() {
  [ "$1 $2 $3" = 'wprism capture --repo=/siterepo' ] || return 82
  [ "$#" -eq 3 ] || { [ "$#" -eq 4 ] && { [ "$4" = --out=/siterepo/.tmp-state2 ] || [ "$4" = --out=/siterepo/.tmp-conf2state ]; }; } || return 83
  printf 'command\n' >>"$PROBE_ROOT/trace"
  if [ "$PROBE_CASE" = refusal ]; then
    cp "$PROBE_ROOT/new-record" "$PROBE_ROOT/refusals/20260907-120001-capture-bbbbbbbbbbbbbbbbbbbbbbbb.json"
    chmod 600 "$PROBE_ROOT/refusals/20260907-120001-capture-bbbbbbbbbbbbbbbbbbbbbbbb.json"
    printf 'Error: capture recovery refused\n' >&2
    return 1
  fi
  printf 'Captured fixture state\n'
}
wp_conf1() { [ "$PROBE_SERVICE" = cli1 ] && wp_capture_probe "$@"; }
wp_conf2() { [ "$PROBE_SERVICE" = cli2 ] && wp_capture_probe "$@"; }
trap 'rm -f "$PROBE_ROOT/refusals/"*; rmdir "$PROBE_ROOT/refusals"' EXIT
SH;
preg_match_all('/^(?:conformance_private_command cli[12] capture )?wp_conf([12]) wprism capture --repo=\/siterepo[^\n]*$/m', $source, $captures, PREG_SET_ORDER);
wprism_check_same(3, count($captures), 'initial/source-repeat/target-recapture all have a concrete shared call site');
foreach ($captures as $index => $match) {
    foreach (['ready', 'refusal'] as $case) {
        $directory = $scratch . '/capture-' . $index . '-' . $case;
        mkdir($directory . '/sandbox/tests', 0700, true);
        mkdir($directory . '/sandbox/tmp', 0700);
        symlink($root . '/sandbox/tests/lib', $directory . '/sandbox/tests/lib');
        mkdir($directory . '/refusals', 0700);
        $record = json_decode($nativeRecord, true);
        $record['command'] = 'capture';
        $record['reason_code'] = 'capture_recovery_ambiguous';
        $bytes = json_encode($record, JSON_THROW_ON_ERROR);
        file_put_contents($directory . '/new-record', $bytes);
        file_put_contents($directory . '/refusals/20260907-120000-capture-aaaaaaaaaaaaaaaaaaaaaaaa.json', $bytes);
        chmod($directory . '/refusals/20260907-120000-capture-aaaaaaaaaaaaaaaaaaaaaaaa.json', 0600);
        $service = 'cli' . $match[1];
        [$status, $stdout, $stderr] = ShellProbe::run($captureProbe . "\n" . $match[0] . "\nprintf 'CAPTURE_ACCEPTED\\n'\n",
            [$root, $directory, $case, $service, 'capture'], $root);
        wprism_check($case === 'ready' ? $status === 0 && str_contains($stdout, 'CAPTURE_ACCEPTED')
            : $status === 1 && !str_contains($stdout, 'CAPTURE_ACCEPTED'), "$index/$case: actual Capture call preserves success/refusal status");
        wprism_check(!str_contains($stdout . $stderr, 'private canary') && !is_dir($directory . '/refusals'),
            "$index/$case: private native bytes are not replayed and disposable source is gone");
        $sinks = glob($directory . '/sandbox/tmp/wprism-conformance-capture.conformanceprobe.*') ?: [];
        wprism_check(count($sinks) === 1 && file_get_contents($directory . '/trace') === "snapshot\ncommand\ncollect\n",
            "$index/$case: Capture collects its own fresh diagnostic delta before teardown");
        if (count($sinks) === 1) {
            $diagnostic = json_decode(WPrismTest\PrivateCommandOutput::readObject($sinks[0] . '/private',
                '/\A Container wprism-conformanceprobe-' . $service . '-run-[a-f0-9]{12} (?:Creating|Created) \z/',
                WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
            WPrismTest\PrivateRefusalReceipt::assertDiagnostic($diagnostic, 'capture');
            wprism_check_same($case === 'ready' ? [] : [$bytes], array_map(static fn(array $row): string => base64_decode($row['contents_base64'], true), $diagnostic['records']),
                "$index/$case: only this invocation's exact private evidence survives source cleanup");
            foreach (glob($sinks[0] . '/*') as $file) unlink($file);
            rmdir($sinks[0]);
        }
        unlink($directory . '/sandbox/tests/lib');
        rmdir($directory . '/sandbox/tests');
        rmdir($directory . '/sandbox/tmp');
        rmdir($directory . '/sandbox');
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}

rmdir($scratch);
wprism_check_summary('regress_private_command_capture');
