<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once "$root/sandbox/tests/lib/ShellProbe.php";
use WPrismTest\ShellProbe;

$source = (string) file_get_contents($capsule . '/tests/live/regress_settings_apply.sh');
$allocationStart = strpos($source, "\nhost_apply() ");
if ($allocationStart === false) throw new RuntimeException('native allocation predecessor unavailable');
$allocationStart = strpos($source, "\n", $allocationStart + 1) + 1;
$allocationEnd = strpos($source, "\ncapture() ", $allocationStart);
if ($allocationEnd === false) throw new RuntimeException('native allocation boundary unavailable');
$allocation = substr($source, $allocationStart, $allocationEnd - $allocationStart);
foreach (['missing', 'file'] as $parent) {
    $probe = <<<'SH'
set -euo pipefail
REPO_ROOT=$(mktemp -d)
EXPECTED_SHA=fixture
trap 'rm -rf -- "$REPO_ROOT"' EXIT
if [ "$1" = file ]; then mkdir -p "$REPO_ROOT/sandbox"; : > "$REPO_ROOT/sandbox/tmp"; fi
SH;
    $probe .= "\n" . $allocation . <<<'SH'

php -r 'exit(is_dir($argv[1]) && (fileperms($argv[1]) & 0777) === 0700 ? 0 : 1);' "$sink"
SH;
    [$status] = ShellProbe::run($probe, [$parent], $root);
    wprism_check_same($parent === 'missing', $status === 0,
        "actual native allocation creates a private directory from a fresh checkout and refuses a file obstruction ($parent)");
}
preg_match_all('/^(?:fail|pass)\(\) \{[^\n]+$/m', $source, $definitions);
$common = "set -euo pipefail\n" . implode("\n", $definitions[0]) . <<<'SH'

REPO_ROOT="$1" PACKAGE_ROOT="$2" PAIR=importerprobe
. "$REPO_ROOT/sandbox/conformance/asserts.sh"
fixture_stdout="$3" fixture_stderr="$4" fixture_exit="$5"
fixture_command() {
  printf '%s\n' "$fixture_stdout"
  [ -z "$fixture_stderr" ] || printf '%s\n' "$fixture_stderr" >&2
  return "$fixture_exit"
}
wp_conf1() { fixture_command "$@"; }
SH;
$start = strpos($source, 'capture() {');
$end = strpos($source, 'zip=', $start);
if ($start === false || $end === false) throw new RuntimeException('native capture boundary unavailable');
$captureFunctions = substr($source, $start, $end - $start);
$capture = $common . <<<'SH'

. "$REPO_ROOT/sandbox/tests/lib/private_command_capture.sh"
sink=$(umask 077; mktemp -d)
trap 'rm -rf -- "$sink"' EXIT
SH;
$capture .= "\n" . $captureFunctions . "\ncapture probe fixture_command\n";
$seed = $common . "\n. \"\$PACKAGE_ROOT/tests/conformance/seed.sh\"\n";
foreach (['seed' => $seed, 'capture' => $capture] as $owner => $script) {
    foreach (['ready', 'php-stdout', 'php-stderr', 'nonzero'] as $fault) {
        $stdout = '{"status":true}';
        $stderr = '';
        $exit = 0;
        if ($fault === 'php-stdout') $stdout = "PHP Warning: fixture warning\n" . $stdout;
        if ($fault === 'php-stderr') $stderr = 'PHP Warning: fixture warning';
        if ($fault === 'nonzero') $exit = 1;
        [$status, $out, $err] = ShellProbe::run($script, [$root, $capsule, $stdout, $stderr, (string) $exit], $root);
        wprism_check_same($fault === 'ready', $status === 0, "$owner actual shell accepts only successful diagnostic-free output ($fault)");
        if ($fault === 'ready') wprism_check(str_contains($out, 'ok: '), "$owner reaches its real positive completion helper");
    }
}
[$status] = ShellProbe::run($seed, [$root, $capsule, '{"status":false}', '', '0'], $root);
wprism_check($status !== 0, 'native Save false status cannot satisfy seed despite process success');
wprism_check_summary('importer native harness');
