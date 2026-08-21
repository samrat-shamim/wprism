<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/CommandOutput.php';

use Duo\Orchestrator\CommandOutput;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    fwrite(STDERR, "FAIL: $message\n");
    $failures++;
};

$check(CommandOutput::wantsAgentRefusalJson('capture', ['--format=json']), 'compact JSON flag is recognized for agent commands');
$check(CommandOutput::wantsAgentRefusalJson('lint', ['--format=json']), 'lint JSON flag selects the agent refusal envelope');
$check(CommandOutput::wantsAgentRefusalJson('plan', ['--format', 'json']), 'spaced JSON flag is recognized for agent commands');
$check(!CommandOutput::wantsAgentRefusalJson('doctor', ['--format=json']), 'unlisted commands do not claim the agent refusal envelope');
$check(!CommandOutput::wantsAgentRefusalJson('capture', ['--format=human']), 'human format does not select the JSON envelope');

$before = ob_get_level();
ob_start();
$status = CommandOutput::renderRefusalJson(
    'capture',
    'host_preflight_failed',
    'the host could not resolve a trusted environment driver for this command',
    'check the environment name and trusted registry, then retry the command'
);
$bytes = ob_get_clean();
while (ob_get_level() > $before) {
    ob_end_clean();
}
$payload = json_decode((string) $bytes, true);
$check($status === 1, 'JSON refusal returns the stable failure status');
$check(is_array($payload) && ($payload['format'] ?? null) === 'duo-command-refusal/v1', 'JSON refusal has the versioned format');
$check(is_array($payload) && ($payload['ok'] ?? null) === false && ($payload['command'] ?? null) === 'capture', 'JSON refusal preserves command and failure state');
$check(is_array($payload) && ($payload['remediation'] ?? null) !== '', 'JSON refusal includes actionable remediation');
$check(substr_count(trim((string) $bytes), "\n") === 0, 'JSON refusal emits exactly one line');

// A redacted agent envelope on a captured transport names the private
// evidence the agent left under the target repository's .duo/refusals/
// (grind_adoption A6: a rehearsal's promotion apply is run in JSON mode, so
// `apply_failed` reached the operator with no sentence and no pointer).
$redacted = '{"format":"duo-command-refusal/v1","ok":false,"command":"apply","error":"apply_failed",'
    . '"reason_code":"apply_failed","message":"apply refused at an unclassified safety gate",'
    . '"remediation":"inspect apply_in_progress and recovery evidence","details_redacted":true}';
$hint = CommandOutput::redactedRefusalEvidenceHint(['exit' => 1, 'stdout' => $redacted . "\n", 'stderr' => "Container x Creating\n"]);
$check(is_string($hint) && str_contains($hint, "the target's apply refusal was redacted") && str_contains($hint, '.duo/refusals/'), 'a redacted agent envelope yields the private-evidence hint naming the command and .duo/refusals/');
$check(CommandOutput::redactedRefusalEvidenceHint(['exit' => 1, 'stdout' => str_replace(',"details_redacted":true', '', $redacted), 'stderr' => '']) === null, 'an unredacted envelope yields no hint');
$check(CommandOutput::redactedRefusalEvidenceHint(['exit' => 1, 'stdout' => "Error: plain failure\n", 'stderr' => '']) === null, 'a non-envelope failure yields no hint');
// renderTransportDetail writes to STDERR; prove the hint rides that channel by capturing the streams it emits.
$capture = static function (array $result): string {
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $script = 'require ' . var_export(__DIR__ . '/../../../../cli/src/Command/CommandOutput.php', true) . ';'
        . ' \\Duo\\Orchestrator\\CommandOutput::renderTransportDetail(json_decode(' . var_export(json_encode($result), true) . ', true));';
    $proc = proc_open([PHP_BINARY, '-r', $script], $spec, $pipes);
    fclose($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    return $err;
};
$rendered = $capture(['exit' => 1, 'stdout' => $redacted . "\n", 'stderr' => "Container x Creating\n"]);
$check(str_contains($rendered, $redacted) && str_contains($rendered, "Container x Creating"), 'renderTransportDetail still emits both distinct streams');
$check(substr_count($rendered, '.duo/refusals/') === 1 && strpos($rendered, '.duo/refusals/') > strpos($rendered, $redacted), 'renderTransportDetail appends the private-evidence hint once, after the streams');
$check(!str_contains($capture(['exit' => 1, 'stdout' => "Error: plain failure\n", 'stderr' => '']), '.duo/refusals/'), 'renderTransportDetail adds no hint to ordinary failures');

// The OTHER redaction witness: cli/duo's render_command_refusal_human(), the
// path a human-mode command takes. It printed "details: redacted from machine
// output; inspect private operator evidence" and stopped there — an
// instruction to inspect evidence whose location the operator was never told
// (DUO-3496). The pointer names WHERE, never WHAT: the sentence itself stays
// out of stdout and stderr exactly as before.
//
// The host shell runs main() at the bottom, so this loads its body without
// that block — the same seam regress_plan_view.php uses — inside a subprocess,
// because the renderer writes to STDERR.
$renderHumanRefusal = static function (array $refusal): string {
    $duoPath = __DIR__ . '/../../../../cli/duo';
    $script = '$source = (string) file_get_contents(' . var_export($duoPath, true) . ');'
        . '$at = strpos($source, "\ntry {\n    exit(main(\$argv));");'
        . '$php = strpos($source, "<?php");'
        . 'if ($at === false || $php === false) { fwrite(STDERR, "host shell main guard moved\n"); exit(2); }'
        . '$body = substr($source, $php + 5, $at - ($php + 5));'
        . '$body = str_replace("__DIR__", ' . var_export(var_export(dirname(realpath($duoPath) ?: $duoPath), true), true) . ', $body);'
        . '$body = (string) preg_replace("/^require /m", "require_once ", $body);'
        . 'eval($body);'
        . 'render_command_refusal_human(json_decode(' . var_export(json_encode($refusal), true) . ', true));';
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-r', $script], $spec, $pipes);
    fclose($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    return $err;
};
$humanRedacted = $renderHumanRefusal([
    'format' => 'duo-command-refusal/v1',
    'ok' => false,
    'command' => 'apply',
    'reason_code' => 'apply_failed',
    'message' => 'apply refused at an unclassified safety gate',
    'remediation' => 'inspect apply_in_progress and recovery evidence',
    'details_redacted' => true,
]);
$check(
    str_contains($humanRedacted, 'details: redacted from machine output; inspect private operator evidence'),
    'the human renderer still states the redaction in the words it always used'
);
$check(
    substr_count($humanRedacted, ".duo/refusals/") === 1
        && str_contains($humanRedacted, "under the target site repository's .duo/refusals/"),
    'and now names the directory the sentence went to, once'
);
$check(
    !str_contains($renderHumanRefusal([
        'format' => 'duo-command-refusal/v1',
        'ok' => false,
        'reason_code' => 'invalid_arguments',
        'message' => '--repo is required for capture',
    ]), '.duo/refusals/'),
    'an unredacted refusal gets no pointer: nothing private was written for it'
);

echo $failures === 0 ? "PASS: command output contract\n" : "FAIL: $failures command output assertions\n";
exit($failures === 0 ? 0 : 1);
