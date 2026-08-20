<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/EnvironmentCommandPreflight.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';

/** Controller boundary for the production site's outbound-only Cloud origin. */
final class OriginCommand {
    private const FORMAT = 'duo-cloud-origin-command/v1';

    /**
     * Run one outbound export cycle through the same isolated target boundary
     * as the public command and return only its schema-closed progress view.
     *
     * Preview orchestration must consume the result as data: capturing the
     * human/JSON renderer would make stdout an authority boundary and could
     * mistake adjacent output for a committed origin receipt.
     *
     * @return array<string,mixed>
     */
    public static function exportOnce(
        string $environment,
        ?string $envsFileOverride,
        string $startDirectory
    ): array {
        if ($environment === '' || str_starts_with($environment, '--')) {
            throw new \RuntimeException('origin export requires one production environment');
        }
        [$driver, $report] = self::readyDriver(
            $environment,
            $envsFileOverride,
            $startDirectory
        );
        if (!$report->ready()) {
            $missing = array_map(
                static fn(array $blocker): string => (string) $blocker['capability'],
                $report->blockers()
            );
            throw new \RuntimeException(
                'the production environment driver cannot run isolated WP-CLI commands'
                    . ($missing === [] ? '' : ': ' . implode(', ', $missing))
            );
        }

        $captured = self::captureDocument($driver, 'export', false);
        $response = $captured['response'];
        if (($response['exit'] ?? 1) !== 0) {
            throw new \RuntimeException('origin export target refused');
        }
        $document = $captured['document'];
        if ($document === null) {
            throw new \RuntimeException('origin export target returned an invalid command document');
        }
        return $document['result'];
    }

    public static function run(
        array $args,
        ?string $envsFileOverride,
        string $startDirectory
    ): int {
        $parsed = self::parse($args);
        if (is_int($parsed)) {
            return $parsed;
        }

        try {
            [$driver, $report] = self::readyDriver(
                $parsed['environment'],
                $envsFileOverride,
                $startDirectory
            );
        } catch (\Throwable $failure) {
            if ($parsed['json']) {
                return CommandOutput::renderRefusalJson(
                    'origin-' . $parsed['operation'],
                    'host_preflight_failed',
                    'the host could not resolve a trusted WP-CLI origin driver',
                    'check the production environment and trusted registry, then retry the origin command'
                );
            }
            fwrite(STDERR, 'duo: origin: host preflight failed: ' . $failure->getMessage() . "\n");
            return 1;
        }
        if (!$report->ready()) {
            if ($parsed['json']) {
                $diagnostics = array_map(
                    static fn(array $blocker): array => [
                        'capability' => (string) $blocker['capability'],
                        'code' => 'driver_capability_missing',
                        'message' => 'the production driver lacks a required capability',
                        'remediation' => (string) $blocker['remediation'],
                        'state' => (string) $blocker['state'],
                    ],
                    $report->blockers()
                );
                return CommandOutput::renderRefusalJson(
                    'origin-' . $parsed['operation'],
                    'driver_unsupported',
                    'the production environment driver cannot run the isolated WP-CLI command',
                    'select a driver with explicit attach and WP-CLI control capabilities',
                    $diagnostics
                );
            }
            fwrite(STDERR, "duo: origin: the production driver cannot run isolated WP-CLI commands\n");
            foreach ($report->blockers() as $blocker) {
                fwrite(STDERR, "  {$blocker['capability']}: {$blocker['reason']}\n");
            }
            return 1;
        }

        if ($parsed['operation'] === 'pair' && !$parsed['poll']) {
            $command = [
                'duo',
                'origin-pair',
                '--repo=' . $driver->repoPath(),
            ];
            $command[] = '--device-code-stdin';
            // The target always returns the closed machine document. The
            // controller owns the only human renderer for every origin
            // operation, so transport chatter cannot become operator output.
            $command[] = '--format=json';
            // The child inherits stdin, so the controller never reads,
            // copies, logs, or places the device code in argv. Unlike the old
            // streamWp path, target output is captured and must pass the same
            // closed public-document validation as every other origin action.
            $response = $driver->captureWpWithInheritedStdin(
                CodeDeploy::controlArgs($command)
            );
            $document = self::documentFromResponse($response, 'pair');
        } else {
            $captured = self::captureDocument($driver, $parsed['operation'], $parsed['poll']);
            $response = $captured['response'];
            $document = $captured['document'];
        }
        if ($response['exit'] !== 0) {
            return self::renderTargetFailure(
                $response,
                $parsed['operation'],
                $parsed['json']
            );
        }
        if ($document === null) {
            if ($parsed['json']) {
                return CommandOutput::renderRefusalJson(
                    'origin-' . $parsed['operation'],
                    'origin_response_invalid',
                    'the target returned an invalid origin command document',
                    'repair or update the protected Duo agent, then retry the same origin command'
                );
            }
            fwrite(STDERR, "duo: origin: target response was incomplete or malformed; refusing it\n");
            return 1;
        }

        if ($parsed['json']) {
            $encoded = json_encode(
                $document,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if (!is_string($encoded)) {
                fwrite(STDERR, "duo: origin: validated response could not be encoded\n");
                return 1;
            }
            echo $encoded . "\n";
            return 0;
        }
        self::renderHuman($document, $parsed['environment']);
        return 0;
    }

    /** @return array{0:Transport,1:DriverCapabilityReport} */
    private static function readyDriver(
        string $environment,
        ?string $envsFileOverride,
        string $startDirectory
    ): array {
        $driver = EnvironmentCommandPreflight::resolveTransport(
            $envsFileOverride,
            $startDirectory,
            $environment
        );
        return [$driver, $driver->capabilityReport('status')];
    }

    /**
     * @return array{
     *   document:?array<string,mixed>,
     *   response:array{exit:int,stdout:string,stderr:string}
     * }
     */
    private static function captureDocument(
        EnvironmentDriver $driver,
        string $operation,
        bool $poll
    ): array {
        // Literal pairs are the auditable host-to-agent command authority;
        // do not rebuild this closed surface with string concatenation.
        $command = match ($operation) {
            'pair' => ['duo', 'origin-pair'],
            'export' => ['duo', 'origin-export'],
            'status' => ['duo', 'origin-status'],
            'rotate' => ['duo', 'origin-rotate'],
            'revoke' => ['duo', 'origin-revoke'],
            'uninstall' => ['duo', 'origin-uninstall'],
            default => throw new \LogicException('origin operation is outside the closed set'),
        };
        $command[] = '--repo=' . $driver->repoPath();
        if ($poll) {
            $command[] = '--poll';
        }
        $command[] = '--format=json';
        $response = $driver->captureWp(CodeDeploy::controlArgs($command));
        $document = self::documentFromResponse($response, $operation);
        return ['document' => $document, 'response' => $response];
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string} $response
     * @return ?array<string,mixed>
     */
    private static function documentFromResponse(array $response, string $operation): ?array {
        if (($response['exit'] ?? 1) !== 0) {
            return null;
        }
        $decoded = json_decode(trim((string) ($response['stdout'] ?? '')), true);
        return is_array($decoded) && self::validDocument($decoded, $operation)
            ? $decoded
            : null;
    }

    /** @return array{environment:string,json:bool,operation:string,poll:bool}|int */
    private static function parse(array $args): array|int {
        $jsonRequested = in_array('--format=json', $args, true);
        $operation = array_shift($args);
        $environment = array_shift($args);
        if (!is_string($operation)
            || !in_array($operation, [
                'pair', 'export', 'status', 'rotate', 'revoke', 'uninstall',
            ], true)
            || !is_string($environment) || $environment === '' || str_starts_with($environment, '--')) {
            if ($jsonRequested) {
                return CommandOutput::renderRefusalJson(
                    'origin',
                    'invalid_arguments',
                    'origin requires pair, export, status, rotate, revoke, or uninstall and one production environment',
                    'use `duo origin <operation> <production-env> [--format=json]`'
                );
            }
            fwrite(
                STDERR,
                "duo: origin requires pair|export|status|rotate|revoke|uninstall and one production <env>\n"
            );
            return 1;
        }
        $json = false;
        $poll = false;
        foreach ($args as $argument) {
            if ($argument === '--format=json' && !$json) {
                $json = true;
                continue;
            }
            if ($argument === '--poll' && $operation === 'pair' && !$poll) {
                $poll = true;
                continue;
            }
            if ($jsonRequested) {
                return CommandOutput::renderRefusalJson(
                    'origin-' . $operation,
                    'invalid_arguments',
                    "origin-$operation received an unsupported or duplicate argument",
                    'pass only --format=json, plus --poll for a pairing resume'
                );
            }
            fwrite(STDERR, "duo: origin $operation received an unsupported or duplicate argument\n");
            return 1;
        }
        return [
            'environment' => $environment,
            'json' => $json,
            'operation' => $operation,
            'poll' => $poll,
        ];
    }

    /** @param array{exit:int,stdout:string,stderr:string} $response */
    private static function renderTargetFailure(
        array $response,
        string $operation,
        bool $json
    ): int {
        // A transport, wrapper, or target can fabricate a syntactically valid
        // refusal and place private bytes in any target-owned field. The host
        // therefore publishes none of that document: only this fixed local
        // classification crosses the public stdout/stderr boundary.
        if ($json) {
            CommandOutput::renderRefusalJson(
                'origin-' . $operation,
                'origin_target_refused',
                'the target refused the origin command',
                'inspect private target evidence, correct the blocker, then retry the same command'
            );
        } else {
            fwrite(
                STDERR,
                "duo: origin $operation: target refused; inspect private target evidence\n"
            );
        }
        return $response['exit'] !== 0 ? $response['exit'] : 1;
    }

    /** @param array<string,mixed> $document */
    private static function validDocument(array $document, string $operation): bool {
        if (!self::exactKeys($document, ['format', 'operation', 'result'])
            || ($document['format'] ?? null) !== self::FORMAT
            || ($document['operation'] ?? null) !== $operation
            || !is_array($document['result'] ?? null)
            || array_is_list($document['result'])) {
            return false;
        }
        return match ($operation) {
            'pair' => self::validPairingStatus($document['result']),
            'rotate' => self::validPairingStatus($document['result'])
                && ($document['result']['phase'] ?? null) === 'paired',
            'revoke', 'uninstall' => self::validPairingStatus($document['result'])
                && in_array(
                    $document['result']['phase'] ?? null,
                    ['remote_inactive', 'revoked'],
                    true
                ),
            'export' => self::validUploadStatus($document['result']),
            'status' => self::exactKeys($document['result'], ['pairing', 'upload'])
                && is_array($document['result']['pairing'] ?? null)
                && is_array($document['result']['upload'] ?? null)
                && self::validPairingStatus($document['result']['pairing'])
                && self::validUploadStatus($document['result']['upload']),
            default => false,
        };
    }

    /** @param array<string,mixed> $status */
    private static function validPairingStatus(array $status): bool {
        if (!self::exactKeys($status, [
            'origin_key_id', 'pair_attempt_id', 'pairing', 'pairing_id', 'phase',
        ])
            || !in_array($status['phase'] ?? null, [
                'beginning', 'denied', 'expired', 'paired', 'pending', 'polling',
                'rejected', 'remote_inactive', 'revoked', 'revoking', 'rotating',
                'unpaired',
            ], true)
            || !self::nullableSha256($status['origin_key_id'] ?? null)
            || !self::nullablePattern($status['pair_attempt_id'] ?? null, '/\A[a-f0-9]{32}\z/D')
            || !self::nullableIdentifier($status['pairing_id'] ?? null)) {
            return false;
        }
        $paired = in_array($status['phase'], [
            'paired', 'remote_inactive', 'revoked', 'revoking', 'rotating',
        ], true);
        if ($paired !== is_array($status['pairing'])) {
            return false;
        }
        if (!$paired) {
            return $status['pairing'] === null;
        }
        $authority = $status['pairing'];
        return !array_is_list($authority)
            && self::exactKeys($authority, [
                'demand_generation', 'origin_generation', 'service_key_id',
                'service_public_key_sha256', 'site_id', 'tenant_id',
            ])
            && self::nonNegativeInteger($authority['demand_generation'] ?? null)
            && self::positiveInteger($authority['origin_generation'] ?? null)
            && self::identifier($authority['service_key_id'] ?? null)
            && self::sha256($authority['service_public_key_sha256'] ?? null)
            && self::identifier($authority['site_id'] ?? null)
            && self::identifier($authority['tenant_id'] ?? null);
    }

    /** @param array<string,mixed> $status */
    private static function validUploadStatus(array $status): bool {
        if (!self::exactKeys($status, [
            'active_demand_generation', 'after_demand_generation', 'last_commit',
            'origin_generation', 'phase', 'poll_after_seconds', 'poll_sequence',
            'remote_state', 'site_id', 'tenant_id',
        ])
            || !in_array($status['phase'] ?? null, [
                'accepted', 'announced', 'announcing', 'cleaning', 'committing',
                'demand_polling', 'idle', 'missing_polling', 'sealed', 'uninitialized',
                'uploading',
            ], true)
            || !self::nonNegativeInteger($status['after_demand_generation'] ?? null)
            || !self::nonNegativeInteger($status['poll_sequence'] ?? null)
            || (($status['active_demand_generation'] ?? null) !== null
                && !self::positiveInteger($status['active_demand_generation']))
            || (($status['origin_generation'] ?? null) !== null
                && !self::positiveInteger($status['origin_generation']))
            || !self::validUploadPollAfter(
                $status['remote_state'] ?? null,
                $status['poll_after_seconds'] ?? null
            )
            || (($status['remote_state'] ?? null) !== null
                && !in_array($status['remote_state'], [
                    'committed', 'demand_expired', 'demanded', 'idle', 'origin_revoked',
                    'revoked', 'stale_demand_generation', 'stale_origin_generation',
                ], true))
            || !self::nullableIdentifier($status['site_id'] ?? null)
            || !self::nullableIdentifier($status['tenant_id'] ?? null)) {
            return false;
        }
        if ($status['phase'] === 'uninitialized') {
            return $status['active_demand_generation'] === null
                && $status['last_commit'] === null
                && $status['origin_generation'] === null
                && $status['site_id'] === null
                && $status['tenant_id'] === null;
        }
        if (!self::positiveInteger($status['origin_generation'])
            || !self::identifier($status['site_id'])
            || !self::identifier($status['tenant_id'])) {
            return false;
        }
        return $status['last_commit'] === null
            || self::validCommitReceipt($status['last_commit']);
    }

    private static function validUploadPollAfter(mixed $remoteState, mixed $value): bool {
        if ($value === null) {
            return true;
        }
        if (!is_int($value) || $value < 0 || $value > 300) {
            return false;
        }
        return $remoteState === 'idle' ? $value >= 1 : $remoteState === 'revoked';
    }

    private static function validCommitReceipt(mixed $receipt): bool {
        return is_array($receipt) && !array_is_list($receipt)
            && self::exactKeys($receipt, [
                'commit_receipt_sha256', 'demand_generation', 'demand_id',
                'export_id', 'manifest_sha256', 'retention_deadline', 'snapshot_hash',
            ])
            && self::sha256($receipt['commit_receipt_sha256'] ?? null)
            && self::positiveInteger($receipt['demand_generation'] ?? null)
            && self::sha256($receipt['demand_id'] ?? null)
            && self::sha256($receipt['export_id'] ?? null)
            && self::sha256($receipt['manifest_sha256'] ?? null)
            && self::positiveInteger($receipt['retention_deadline'] ?? null)
            && self::sha256($receipt['snapshot_hash'] ?? null);
    }

    /** @param array<string,mixed> $document */
    private static function renderHuman(array $document, string $environment): void {
        $operation = (string) $document['operation'];
        $result = $document['result'];
        if ($operation === 'status') {
            echo "origin $environment pairing: {$result['pairing']['phase']}\n";
            echo "origin $environment upload: {$result['upload']['phase']}\n";
            self::renderAuthority($result['pairing']);
            self::renderCommit($result['upload']);
            return;
        }
        if ($operation === 'export') {
            echo "origin $environment upload: {$result['phase']}\n";
            if (is_string($result['remote_state'])) {
                echo "cloud demand: {$result['remote_state']}\n";
            }
            self::renderCommit($result);
            return;
        }
        echo "origin $environment pairing: {$result['phase']}\n";
        self::renderAuthority($result);
        if ($operation === 'pair' && in_array($result['phase'], ['pending', 'polling'], true)) {
            echo "next: duo origin pair $environment --poll\n";
        }
    }

    /** @param array<string,mixed> $status */
    private static function renderAuthority(array $status): void {
        $authority = $status['pairing'] ?? null;
        if (!is_array($authority)) {
            return;
        }
        echo "tenant: {$authority['tenant_id']}\n";
        echo "site: {$authority['site_id']}\n";
        echo "origin generation: {$authority['origin_generation']}\n";
    }

    /** @param array<string,mixed> $status */
    private static function renderCommit(array $status): void {
        $receipt = $status['last_commit'] ?? null;
        if (!is_array($receipt)) {
            return;
        }
        echo "snapshot: {$receipt['snapshot_hash']}\n";
        echo "retained until: {$receipt['retention_deadline']}\n";
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): bool {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected;
    }

    private static function nullableSha256(mixed $value): bool {
        return $value === null || self::sha256($value);
    }

    private static function nullableIdentifier(mixed $value): bool {
        return $value === null || self::identifier($value);
    }

    private static function nullablePattern(mixed $value, string $pattern): bool {
        return $value === null || (is_string($value) && preg_match($pattern, $value) === 1);
    }

    private static function sha256(mixed $value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function identifier(mixed $value): bool {
        return is_string($value)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) === 1;
    }

    private static function nonNegativeInteger(mixed $value): bool {
        return is_int($value) && $value >= 0 && $value <= 9007199254740991;
    }

    private static function positiveInteger(mixed $value): bool {
        return is_int($value) && $value >= 1 && $value <= 9007199254740991;
    }
}
