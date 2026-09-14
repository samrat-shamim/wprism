<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once "$root/sandbox/tests/lib/ShellProbe.php";
$source = file_get_contents($capsule . '/tests/live/regress_dependency_apply.sh');
$start = strpos($source, 'capture() {');
$end = strpos($source, 'WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list', $start);
if ($start === false || $end === false) throw new RuntimeException('native dependency capture boundary unavailable');
$script = <<<'SH'
set -euo pipefail
ROOT="$1" PACKAGE_ROOT="$2" PAIR=dependencyprobe
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/tests/lib/private_command_capture.sh"
sink=$(umask 077; mktemp -d)
trap 'rm -rf -- "$sink"' EXIT
fixture_exit="$3"
candidate() { printf '{"fixture":true}\n'; return "$fixture_exit"; }
SH;
$script .= "\n" . substr($source, $start, $end - $start);
$script .= <<<'SH'
snapshot() { printf 'snapshot:%s\n' "$1"; }
refusal probe apply
SH;
foreach ([0, 1, 2] as $exit) {
    [$status, $out] = WPrismTest\ShellProbe::run($script, [$root, $capsule, (string) $exit], $root);
    wprism_check($status !== 0, 'wrong result or absent private cause cannot pass dependency evidence: ' . $exit);
    wprism_check(str_contains($out, "snapshot:probe-before\nsnapshot:probe-after\n"),
        'native refusal runner retains both postimages before admitting the result: ' . $exit);
}
wprism_check_summary('importer dependency harness');
