<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once "$root/sandbox/tests/lib/ShellProbe.php";
$source = file_get_contents($capsule . '/tests/live/regress_templates_apply.sh');
$start = strpos($source, 'capture() {');
$end = strpos($source, 'zip=', $start);
if ($start === false || $end === false) throw new RuntimeException('native template capture boundary unavailable');
$capture = substr($source, $start, $end - $start);
$script = <<<'SH'
set -euo pipefail
REPO_ROOT="$1" PACKAGE_ROOT="$2" PAIR=templatesprobe
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$REPO_ROOT/sandbox/conformance/asserts.sh"
. "$REPO_ROOT/sandbox/tests/lib/private_command_capture.sh"
fixture_stdout="$3" fixture_stderr="$4" fixture_exit="$5"
fixture_command() { printf '%s\n' "$fixture_stdout"; printf '%s' "$fixture_stderr" >&2; return "$fixture_exit"; }
sink=$(umask 077; mktemp -d)
trap 'rm -rf -- "$sink"' EXIT
SH;
$script .= "\n" . $capture . "\ncapture probe fixture_command\n";
foreach (['ready', 'php-stdout', 'php-stderr', 'unexpected-stderr', 'nonzero'] as $fault) {
    $stdout = '{"status":true}';
    $stderr = '';
    $exit = 0;
    if ($fault === 'php-stdout') $stdout = "PHP Warning: fixture warning\n" . $stdout;
    if ($fault === 'php-stderr') $stderr = 'PHP Warning: fixture warning';
    if ($fault === 'unexpected-stderr') $stderr = 'unadmitted native diagnostic';
    if ($fault === 'nonzero') $exit = 1;
    [$status, $out] = WPrismTest\ShellProbe::run($script, [$root, $capsule, $stdout, $stderr, (string) $exit], $root);
    wprism_check_same($fault === 'ready', $status === 0, "actual template command capture admits only expected exit and clean streams: $fault");
    if ($fault === 'ready') wprism_check(str_contains($out, 'ok: probe'), 'native template capture reaches its positive completion helper');
}
wprism_check_summary('importer templates harness');
