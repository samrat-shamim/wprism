<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 2) . '/lib/ShellProbe.php';

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
rmdir($scratch);
wprism_check_summary('regress_private_command_capture');
