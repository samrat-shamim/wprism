<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Command/CommandOutput.php';

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

echo $failures === 0 ? "PASS: command output contract\n" : "FAIL: $failures command output assertions\n";
exit($failures === 0 ? 0 : 1);
