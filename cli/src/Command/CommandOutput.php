<?php
declare(strict_types=1);

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
        $hint = self::redactedRefusalEvidenceHint($result);
        if ($hint !== null) {
            fwrite(STDERR, $hint . "\n");
        }
    }

    /**
     * Name where a redacted agent refusal's sentence went.
     *
     * The host runs the agent in --format=json wherever it needs a
     * structured receipt (a rehearsal's promotion apply, deploy preflight,
     * assess), so an unclassified Throwable arrives as `details_redacted:
     * true` and nothing else (DUO-3404) — the operator never ran a human-mode
     * command they could reread. The agent writes that sentence privately
     * under the target repository's `.duo/refusals/` (agent/src/Command/
     * Cli.php record_private_refusal_evidence); this one stderr line, emitted
     * only when a stream is such an envelope, tells the operator so. The
     * envelope on stdout is untouched.
     */
    public static function redactedRefusalEvidenceHint(array $result): ?string {
        foreach ([$result['stdout'] ?? '', $result['stderr'] ?? ''] as $stream) {
            $decoded = json_decode(trim((string) $stream), true);
            if (!is_array($decoded)
                || ($decoded['format'] ?? null) !== 'duo-command-refusal/v1'
                || ($decoded['details_redacted'] ?? null) !== true) {
                continue;
            }
            $command = is_string($decoded['command'] ?? null) && $decoded['command'] !== ''
                ? $decoded['command']
                : 'agent';
            return "duo: the target's $command refusal was redacted for machine output;"
                . " its private operator evidence is under the target site repository's .duo/refusals/"
                . ' (a JSON record per redacted refusal: reason code, throwable class, message, cause chain)';
        }
        return null;
    }

    /**
     * Commands whose host preflight can emit the agent refusal envelope.
     *
     * `assess` and `contract` join the list (round-3 MUP §2.1) because both
     * publish a canonical document under `--format=json` and both can be
     * refused by the host before the target is contacted — an unresolvable
     * environment, a driver missing a required capability. A caller parsing
     * this command's stdout must get one envelope for every outcome, or the
     * first host-side refusal becomes an unparseable line in a pipeline.
     *
     * `release`, `verify`, `recover` and `rehearse` join for the same reason
     * (round-3 MUP §2.2-§2.5), and for one sharper one: every refusal those
     * four can raise *before* the target is contacted is a decision an
     * operator or a pipeline must act on — a missing contract, a surface
     * that is not releasable, a ref that does not match the target HEAD, a
     * recovery profile the target cannot prove. A refusal printed as bare
     * stderr while the success path prints a canonical document would make
     * the machine caller treat "no JSON" as "no answer".
     *
     * `merge-check` joins with the sharpest version of that reason: it is the
     * one verb whose deliverable IS a machine contract (four exit codes a
     * customer's CI binds to, MergeCheckCommand.php:14-31), and every refusal
     * it can raise fires on this host before anything compiles. A pipeline
     * that got a `duo-merge-check/v1` document for exit 3 and an unparseable
     * stderr line for exit 1 could not tell "a human owes me a decision" from
     * "my checkout is broken", which is precisely the distinction the verb
     * exists to publish.
     */
    public static function wantsAgentRefusalJson(string $verb, array $extra): bool {
        if (!in_array(
            $verb,
            [
                'adapter-observe', 'assess', 'capture', 'contract', 'lint', 'merge-check', 'plan',
                'explain', 'apply', 'recover', 'refresh', 'rehearse', 'release', 'verify',
            ],
            true
        )) {
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
