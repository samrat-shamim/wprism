<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/HostProcess.php';
require_once __DIR__ . '/PassthroughCommand.php';

use WPrism\Canon;

/** Host command handler for capture's local --scope-contract flag handling. */
final class CaptureCommand {
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        ?string $envsFileOverride = null,
        ?callable $currentBranch = null
    ): int {
        $forward = [];
        $contractPath = null;
        $targetBranch = null;
        $writesRepository = true;
        $json = CommandOutput::wantsAgentRefusalJson('capture', $extra);
        foreach ($extra as $arg) {
            if (!is_string($arg) || PassthroughCommand::isHostOwnedTargetFlag($arg)) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received a host-owned target binding argument',
                    'remove --repo/--path; the selected environment supplies both bindings'
                );
            }
            if ($arg === '--orchestrator-environment'
                || str_starts_with($arg, '--orchestrator-environment=')) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received an orchestrator-reserved presentation argument',
                    'remove --orchestrator-environment and retry capture'
                );
            }
            if ($arg === '--expected-repository-branch'
                || str_starts_with($arg, '--expected-repository-branch=')) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received an orchestrator-reserved branch binding',
                    'remove --expected-repository-branch and use --target-branch=<name> when an explicit destination is required'
                );
            }
            if ($arg === '--target-branch' || (str_starts_with($arg, '--target-branch')
                && !str_starts_with($arg, '--target-branch='))) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received a malformed target branch flag',
                    'supply exactly --target-branch=<named-branch>'
                );
            }
            if (str_starts_with($arg, '--target-branch=')) {
                if ($targetBranch !== null) {
                    return self::scopeRefusal(
                        $extra,
                        'invalid_arguments',
                        'capture received more than one target branch',
                        'supply exactly one --target-branch=<named-branch>'
                    );
                }
                $targetBranch = substr($arg, strlen('--target-branch='));
                continue;
            }
            if (str_starts_with($arg, '--scope-request-b64')) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received an orchestrator-reserved scope argument',
                    'remove the internal scope argument and supply only --scope-contract=<local-path>'
                );
            }
            if ($arg === '--scope-contract' || (str_starts_with($arg, '--scope-contract')
                && !str_starts_with($arg, '--scope-contract='))) {
                return self::scopeRefusal(
                    $extra,
                    'invalid_arguments',
                    'capture received a malformed scope contract flag',
                    'supply exactly --scope-contract=<local-path>'
                );
            }
            if (str_starts_with($arg, '--scope-contract=')) {
                if ($contractPath !== null) {
                    return self::scopeRefusal(
                        $extra,
                        'invalid_arguments',
                        'capture received more than one scope contract',
                        'supply exactly one --scope-contract=<local-path>'
                    );
                }
                $contractPath = substr($arg, strlen('--scope-contract='));
                if ($contractPath === '') {
                    return self::scopeRefusal(
                        $extra,
                        'invalid_arguments',
                        'capture received an empty scope contract path',
                        'supply one readable canonical contract with --scope-contract=<local-path>'
                    );
                }
                continue;
            }
            if ($arg === '--out' || str_starts_with($arg, '--out=')) {
                $writesRepository = false;
            }
            $forward[] = $arg;
        }
        if ($contractPath !== null) {
            try {
                $input = PassthroughCommand::readScopeContractInput($contractPath);
                $forward[] = '--scope-request-b64=' . $input['request_b64'];
            } catch (\Throwable $e) {
                return self::scopeRefusal(
                    $extra,
                    'scope_contract_invalid',
                    'the local scope contract is malformed, tampered, or unsupported',
                    'generate a fresh contract with wprism scope --contract and retry capture'
                );
            }
        }
        // An explicit registry path is private host state. Keep it out of the
        // target command and render the exact follow-up on the host instead.
        if ($envsFileOverride === null) {
            $forward[] = '--orchestrator-environment=' . $driver->name();
        }
        if ($writesRepository) {
            if ($targetBranch === null) {
                $currentBranch ??= static function (): ?string {
                    $cwd = getcwd();
                    if (!is_string($cwd) || $cwd === '') return null;
                    $result = HostProcess::run(['git', '-C', $cwd, 'symbolic-ref', '--quiet', '--short', 'HEAD']);
                    return $result['exit'] === 0 ? trim($result['stdout']) : null;
                };
                $targetBranch = $currentBranch();
            }
            if (!is_string($targetBranch) || !self::validBranch($targetBranch)) {
                return self::scopeRefusal(
                    $extra,
                    'capture_branch_unresolved',
                    'capture cannot bind its repository write to a valid named branch',
                    'run from the intended named checkout or supply --target-branch=<named-branch>'
                );
            }
            $forward[] = '--expected-repository-branch=' . $targetBranch;
        }
        $wpArgs = array_merge(['wprism', 'capture', '--repo=' . $driver->repoPath()], $forward);
        // Export-only capture has its own destination and retains the agent's
        // established output. A repository-writing JSON capture crosses a
        // host/orchestrator boundary, so publish one closed, digest-bound
        // receipt rather than asking that caller to interpret an agent
        // implementation summary.
        if (!$json || !$writesRepository) {
            return $driver->streamWp($wpArgs);
        }
        $result = $driver->captureWp($wpArgs);
        $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
        if (($result['exit'] ?? 1) !== 0) {
            if (is_array($decoded) && !array_is_list($decoded)
                && ($decoded['format'] ?? null) === 'wprism-command-refusal/v1') {
                echo Canon::encode($decoded);
                return 1;
            }
            return CommandOutput::renderRefusalJson(
                'capture',
                'capture_failed',
                'the target refused capture without a supported machine refusal',
                'inspect the target private refusal evidence, repair the condition, and retry capture'
            );
        }
        try {
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \InvalidArgumentException('capture summary is not an object');
            }
            echo Canon::encode(self::receipt($driver->name(), (string) $targetBranch, $decoded));
            return 0;
        } catch (\Throwable) {
            return CommandOutput::renderRefusalJson(
                'capture',
                'capture_result_invalid',
                'the target returned a successful capture result outside the reviewed machine contract',
                'upgrade the target agent to the pinned WPrism distribution and retry capture'
            );
        }
    }

    /**
     * Reduce the agent's implementation summary to the public host receipt.
     * Counts are copied as facts; this boundary never reclassifies entities.
     *
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private static function receipt(string $environment, string $branch, array $summary): array {
        $counts = $summary['counts'] ?? null;
        $notes = $summary['notes'] ?? null;
        $warnings = $summary['warnings'] ?? null;
        $media = $summary['media'] ?? null;
        $revision = $summary['revision_hash'] ?? null;
        if (!is_array($counts) || array_is_list($counts) || count($counts) > 128
            || !is_array($notes) || !array_is_list($notes)
            || !is_array($warnings) || !array_is_list($warnings)
            || count($notes) > 10_000 || count($warnings) > 10_000
            || !is_int($media) || $media < 0
            || !is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('capture summary has an invalid shape');
        }
        foreach ($counts as $kind => $count) {
            if (!is_string($kind) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $kind) !== 1
                || !is_int($count) || $count < 0) {
                throw new \InvalidArgumentException('capture summary counts are invalid');
            }
        }
        $receipt = [
            'branch' => $branch,
            'capture' => [
                'counts' => $counts,
                'media_count' => $media,
                'notes_count' => count($notes),
                'state_revision' => $revision,
                'warnings_count' => count($warnings),
            ],
            'environment' => $environment,
            'format' => 'wprism-capture-result/v1',
            'next_action' => 'review_and_commit',
        ];
        $receipt['receipt_sha256'] = 'sha256:' . hash('sha256', Canon::encode($receipt));

        return Canon::normalize($receipt);
    }

    private static function validBranch(string $branch): bool {
        if ($branch === '' || strlen($branch) > 255 || str_contains($branch, "\0")
            || str_contains($branch, "\n") || str_contains($branch, "\r")) {
            return false;
        }
        $result = HostProcess::run(['git', 'check-ref-format', '--branch', $branch]);
        return $result['exit'] === 0 && trim($result['stdout']) === $branch;
    }

    private static function scopeRefusal(
        array $extra,
        string $reasonCode,
        string $message,
        string $remediation
    ): int {
        if (CommandOutput::wantsAgentRefusalJson('capture', $extra)) {
            return CommandOutput::renderRefusalJson('capture', $reasonCode, $message, $remediation);
        }
        fwrite(STDERR, "wprism: capture: $message; $remediation\n");
        return 2;
    }
}
