<?php
namespace Duo\Orchestrator;

/**
 * Host command output and refusal-contract primitives.
 *
 * The executable remains the compatibility/router facade; this class owns
 * the shared argument detection and stable JSON refusal envelope so future
 * command handlers do not each grow a subtly different error channel.
 */
final class CommandOutput {
    /** Emit both distinct streams of a captured transport failure. */
    public static function renderTransportDetail(array $result): void {
        // Docker Compose writes lifecycle chatter to stderr while the command
        // diagnostic is often on stdout; retain both without duplicating text.
        $seen = [];
        foreach ([$result['stderr'] ?? '', $result['stdout'] ?? ''] as $stream) {
            $detail = trim((string) $stream);
            if ($detail === '' || isset($seen[$detail])) {
                continue;
            }
            $seen[$detail] = true;
            fwrite(STDERR, $detail . "\n");
        }
    }

    /** Commands whose host preflight can emit the agent refusal envelope. */
    public static function wantsAgentRefusalJson(string $verb, array $extra): bool {
        if (!in_array($verb, ['adapter-observe', 'capture', 'plan', 'explain', 'apply', 'refresh'], true)) {
            return false;
        }
        foreach ($extra as $index => $arg) {
            if ($arg === '--json' || $arg === '--format=json'
                || ($arg === '--format' && ($extra[$index + 1] ?? null) === 'json')) {
                return true;
            }
        }
        return false;
    }

    /** Emit the stable host-side refusal envelope and return its failure code. */
    public static function renderRefusalJson(
        string $command,
        string $reasonCode,
        string $message,
        string $remediation,
        array $diagnostics = []
    ): int {
        $payload = [
            'format' => 'duo-command-refusal/v1',
            'ok' => false,
            'command' => $command,
            'error' => $reasonCode,
            'reason_code' => $reasonCode,
            'message' => $message,
            'remediation' => $remediation,
        ];
        if ($diagnostics !== []) {
            $payload['diagnostics'] = $diagnostics;
        }
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encoded === false) {
            $encoded = '{"format":"duo-command-refusal/v1","ok":false,"command":"host",'
                . '"error":"refusal_serialization_failed","reason_code":"refusal_serialization_failed",'
                . '"message":"structured refusal serialization failed",'
                . '"remediation":"inspect private operator evidence before another attempt","details_redacted":true}';
        }
        echo $encoded . "\n";
        return 1;
    }

    /** @return never */
    public static function fail(string $message, int $code = 1): void {
        fwrite(STDERR, "duo: $message\n");
        exit($code);
    }
}
