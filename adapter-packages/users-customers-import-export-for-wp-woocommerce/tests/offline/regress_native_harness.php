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
$seed = $common . <<<'SH'

CONF_PAIR="importerprobe$(php -r 'echo bin2hex(random_bytes(8));')"
trap 'rm -rf -- "$REPO_ROOT/sandbox/tmp/importer-roundtrip-$CONF_PAIR"' EXIT
cd "$REPO_ROOT/sandbox"
. "$PACKAGE_ROOT/tests/conformance/seed.sh"
SH;
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
$refusal = str_replace('capture probe fixture_command', 'capture_expected probe 1 fixture_command', $capture);
foreach (['expected' => [1, ''], 'success' => [0, ''], 'wrong-exit' => [2, ''], 'diagnostic' => [1, 'PHP Warning: fixture warning']] as $case => [$exit, $stderr]) {
    [$status] = ShellProbe::run($refusal, [$root, $capsule, '{"ok":false}', $stderr, (string) $exit], $root);
    wprism_check_same($case === 'expected', $status === 0, 'actual expected-refusal shell admits only the declared diagnostic-free exit: ' . $case);
}
require_once "$capsule/fixtures/settings-evidence.php";
require_once "$root/agent/src/Kernel/PrivateRefusalEvidence.php";
$repository = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'compile',
    'reason_code' => 'repository_authorization_failed', 'error' => 'repository_authorization_failed',
    'message' => 'repository authorization refused this command', 'diagnostics' => [[
        'code' => 'repository_scalar_value_invalid', 'uuid' => 'options/core', 'surface' => 'option_sub_key',
        'field' => 'wt_iew_advanced_settings.wt_iew_maximum_execution_time', 'classification' => 'malformed',
    ]]];
ImporterSettingsEvidence::repositoryRefusal($repository, 'compile');
wprism_check(true, 'reader admits the exact repository scalar diagnostic');
foreach (['wrong-code', 'wrong-field', 'extra-finding', 'wrong-command', 'success'] as $case) {
    $bad = $repository;
    if ($case === 'wrong-code') $bad['diagnostics'][0]['code'] = 'repository_pii_not_allowed';
    if ($case === 'wrong-field') $bad['diagnostics'][0]['field'] = 'other';
    if ($case === 'extra-finding') $bad['diagnostics'][] = $bad['diagnostics'][0];
    if ($case === 'wrong-command') $bad['command'] = 'plan';
    if ($case === 'success') $bad['ok'] = true;
    wprism_check_throws(static fn() => ImporterSettingsEvidence::repositoryRefusal($bad, 'compile'), RuntimeException::class,
        'reader refuses misleading repository evidence: ' . $case);
}
$public = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'capture',
    'reason_code' => 'capture_failed', 'details_redacted' => true, 'message' => 'capture refused at an unclassified safety gate'];
$baseline = ['command' => 'capture', 'baseline' => '[]'];
$message = 'wprism: option wt_iew_advanced_settings.wt_iew_maximum_execution_time scalar value constraint requires an integer within its declared inclusive bounds';
$diagnostic = static function (Throwable $failure): array {
    $bytes = json_encode(['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'capture', 'reason_code' => 'capture_failed']
        + WPrism\PrivateRefusalEvidence::graph($failure), JSON_THROW_ON_ERROR);
    return ['format' => 'wprism-private-refusal-diagnostic/v1', 'command' => 'capture', 'new_records' => 1,
        'purpose' => 'diagnostic_only', 'verified' => false, 'records' => [[
            'name' => '20260913-010203-capture-0123456789abcdef01234567.json', 'bytes' => strlen($bytes),
            'contents_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes),
        ]]];
};
$validDiagnostic = $diagnostic(new RuntimeException($message));
ImporterSettingsEvidence::nativeRefusal($public, $baseline, $validDiagnostic);
wprism_check(true, 'reader admits one fresh exact private scalar cause under the public redaction envelope');
foreach ([new RuntimeException('unrelated failure'), new LogicException($message), new RuntimeException($message, 0, new RuntimeException('extra cause'))] as $fault) {
    wprism_check_throws(static fn() => ImporterSettingsEvidence::nativeRefusal($public, $baseline, $diagnostic($fault)), RuntimeException::class,
        'matching public failure cannot hide a different private cause or extra graph edge');
}
$stale = ['command' => 'capture', 'baseline' => json_encode([$validDiagnostic['records'][0]['name']], JSON_THROW_ON_ERROR)];
wprism_check_throws(static fn() => ImporterSettingsEvidence::nativeRefusal($public, $stale, $validDiagnostic), RuntimeException::class,
    'a preexisting correct scalar cause cannot prove this invocation');
wprism_check_summary('importer native harness');
