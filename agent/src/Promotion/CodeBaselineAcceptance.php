<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';

require_once __DIR__ . '/../Kernel/CheckpointRecoveryIntent.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
require_once __DIR__ . '/../Kernel/NativeDatabaseProfile.php';
require_once __DIR__ . '/../Kernel/ProviderSettlementIntent.php';
require_once __DIR__ . '/../Kernel/SiteTopology.php';
require_once __DIR__ . '/../Kernel/TransientDbException.php';
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';
require_once __DIR__ . '/../Policy/AdapterLibrary.php';
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
require_once __DIR__ . '/Deploy.php';
require_once __DIR__ . '/CodeBaselineTransaction.php';
require_once __DIR__ . '/LifecyclePlanner.php';
require_once __DIR__ . '/PromotionLease.php';

/**
 * Isolated, receipt-bearing acceptance of already-installed code versions.
 *
 * This phase is intentionally not lifecycle work: it fires no plugin/theme
 * hook and publishes no promotion session. The target process fence excludes
 * another WPrism writer, both external recovery-intent files are checked
 * beneath that same fence, and the ledger transaction binds the replacement
 * baseline to the exact host-observed artifact/version snapshot. A retry with
 * the same operation id replays the committed receipt after response loss.
 */
final class CodeBaselineAcceptance {
    public const FORMAT = 'wprism-code-baseline-acceptance/v2';

    /** @return array<string,mixed> */
    public static function run(
        string $repo,
        string $compiledPath,
        string $artifactHash,
        array $opts = []
    ): array {
        if (!defined('WPRISM_CONTROL_PLANE') || WPRISM_CONTROL_PLANE !== true) {
            throw new \RuntimeException(
                "wprism: code-baseline-accept is an internal host phase; run 'wprism deploy <env>'"
            );
        }
        SiteTopology::assert_single_site();
        self::assert_artifact_hash($artifactHash);
        $expectedState = (string) ($opts['expected_baseline_state'] ?? '');
        $expectedObservation = (string) ($opts['expected_observation_sha256'] ?? '');
        $operationId = (string) ($opts['operation_id'] ?? '');
        if (!in_array($expectedState, ['absent', 'drift'], true)) {
            throw new \InvalidArgumentException('code-baseline-accept expected baseline state is malformed');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedObservation) !== 1) {
            throw new \InvalidArgumentException('code-baseline-accept observation hash is malformed');
        }
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $operationId) !== 1) {
            throw new \InvalidArgumentException('code-baseline-accept operation id is malformed');
        }

        $repo = rtrim($repo, '/');
        $adapterLibrary = $opts['adapter_library'] ?? null;
        if ($adapterLibrary !== null && !$adapterLibrary instanceof AdapterLibrary) {
            throw new \InvalidArgumentException('adapter_library must be a WPrism\\AdapterLibrary');
        }
        $policy = Policy::load($repo, adapterLibrary: $adapterLibrary);
        $compiled = RepositoryCompiler::read_artifact($compiledPath, $policy);
        if (!hash_equals($artifactHash, $compiled->artifact_hash())) {
            throw new \RuntimeException(
                'wprism: code-baseline-accept artifact does not match the host-compiled artifact hash'
            );
        }

        $owner = PromotionLease::owner(['promotion_owner' => $operationId]);
        PromotionLease::acquire_deploy_preflight_with_external_fence(
            $owner,
            $artifactHash,
            static function () use ($repo): void {
                CheckpointRecoveryIntent::assert_clear($repo);
                ProviderSettlementIntent::assert_clear($repo);
            }
        );
        try {
            $lockedPolicy = Policy::load($repo, adapterLibrary: $adapterLibrary);
            $lockedCompiled = RepositoryCompiler::read_artifact($compiledPath, $lockedPolicy);
            if (!hash_equals($artifactHash, $lockedCompiled->artifact_hash())) {
                throw new \RuntimeException(
                    'wprism: compiled artifact changed before locked code-baseline acceptance'
                );
            }
            $revisionMismatch = LifecyclePlanner::code_revision_mismatch($lockedCompiled);
            if ($revisionMismatch !== []) {
                $list = implode("\n\n", array_map(
                    static fn(array $row): string => '  - ' . (string) ($row['message'] ?? 'unknown code revision'),
                    $revisionMismatch
                ));
                throw new \RuntimeException(
                    "wprism: code-baseline acceptance refused — code_revision_stale:\n\n$list\n\n"
                    . "Run the host 'wprism deploy <env>' workflow so it materializes and verifies the exact payload."
                );
            }
            $tree = $lockedCompiled->tree();
            $desired = isset($tree['options/core'])
                ? Deploy::extract_desired((array) ($tree['options/core']['data'] ?? []))
                : [];

            PromotionLease::assert_process_fence();
            $receipt = self::accept_or_replay(
                $lockedPolicy,
                $desired,
                ($opts['force_code_drift'] ?? false) === true,
                ($opts['force_code_mismatch'] ?? false) === true,
                $expectedState,
                $expectedObservation,
                $operationId,
                $artifactHash
            );
            PromotionLease::release($owner, $artifactHash);
            return $receipt;
        } catch (\Throwable $failure) {
            try {
                PromotionLease::release_after_failure($owner, $artifactHash);
            } catch (\Throwable $_releaseFailure) {
                // The primary refusal is useful evidence. This transient lease
                // is TTL-bounded and publishes no recovery session.
            }
            throw $failure;
        }
    }

    private static function assert_artifact_hash(string $artifactHash): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
            throw new \InvalidArgumentException('code-baseline-accept artifact hash is malformed');
        }
    }

    /** @return array<string,mixed> */
    private static function accept_or_replay(
        Policy $policy,
        array $desired,
        bool $forceCodeDrift,
        bool $forceCodeMismatch,
        string $expectedState,
        string $expectedObservation,
        string $operationId,
        string $artifactHash
    ): array {
        global $wpdb;
        $transactionOpen = false;
        $terminalControlAttempted = false;
        try {
            $ledgerTable = $wpdb->prefix . 'wprism_kv';
            Db::start_repeatable_read(
                'code baseline acceptance transaction start',
                new NativeDatabaseProfile([$wpdb->options, $ledgerTable], [$ledgerTable])
            );
            $transactionOpen = true;
            $authority = Db::transaction_authority(
                'code baseline acceptance initial transaction proof'
            );
            self::assert_transaction_authority($authority, 'after transaction start');

            $locked = CodeBaselineTransaction::lock_current(
                $desired,
                $authority,
                'code baseline acceptance'
            );
            $receiptRaw = $locked->receipt_raw();
            self::assert_transaction_authority($authority, 'after receipt lock');
            $baselineRaw = $locked->baseline_raw();
            self::assert_transaction_authority($authority, 'after baseline lock');
            $observation = $locked->observation();

            if ($expectedState === 'drift' && !$forceCodeDrift) {
                throw new \RuntimeException(
                    'wprism: deploy refused — code_drift requires --force-code-drift acceptance authority'
                );
            }

            // Replay is classified beneath the same locks as a fresh write.
            // Its original observation is expected to differ from the current
            // one because the committed baseline is part of that digest.
            if ($receiptRaw !== null) {
                $prior = self::decode_receipt($receiptRaw);
                if (hash_equals($operationId, $prior['operation_id'])) {
                    if (!hash_equals($artifactHash, $prior['artifact_hash'])
                        || !hash_equals($expectedObservation, $prior['observation_sha256'])
                        || $prior['outcome'] !== ($expectedState === 'absent' ? 'initialized' : 'accepted')) {
                        throw new \RuntimeException(
                            'wprism: code-baseline operation id was reused for different acceptance authority'
                        );
                    }
                    $current = LifecyclePlanner::code_baseline_acceptance_snapshot(
                        $policy,
                        $desired,
                        $forceCodeMismatch,
                        $observation
                    );
                    self::assert_transaction_authority($authority, 'after replay snapshot');
                    if ($current['baseline_state'] !== 'exact'
                        || $current['code_drift'] !== []
                        || !is_string($current['before_baseline_sha256'])
                        || !hash_equals($prior['baseline_sha256'], $current['before_baseline_sha256'])
                        || !hash_equals($prior['baseline_sha256'], hash('sha256', $current['baseline_bytes']))) {
                        throw new \RuntimeException(
                            'wprism: committed code-baseline acceptance no longer matches the exact live environment'
                        );
                    }
                    LifecyclePlanner::assert_code_baseline_publication(
                        $desired,
                        $current['live_facts_sha256'],
                        $current['baseline_bytes'],
                        $locked->reread_observation($baselineRaw, 'code baseline replay proof')
                    );
                    self::assert_transaction_authority($authority, 'before replay rollback');
                    self::rollback(
                        $transactionOpen,
                        $terminalControlAttempted,
                        'code baseline replay transaction'
                    );
                    $prior['replayed'] = true;
                    return $prior;
                }
            }

            $snapshot = LifecyclePlanner::code_baseline_acceptance_snapshot(
                $policy,
                $desired,
                $forceCodeMismatch,
                $observation
            );
            self::assert_transaction_authority($authority, 'after acceptance snapshot');
            if (!hash_equals($expectedObservation, $snapshot['observation_sha256'])) {
                throw new \RuntimeException(
                    'wprism: code-baseline observation changed before locked acceptance; retry host deploy'
                );
            }
            if ($snapshot['baseline_state'] !== $expectedState) {
                throw new \RuntimeException('wprism: code-baseline state changed before locked acceptance');
            }
            $baseline = $snapshot['baseline_bytes'];
            $receipt = [
                'format' => self::FORMAT,
                'operation_id' => $operationId,
                'artifact_hash' => $artifactHash,
                'observation_sha256' => $expectedObservation,
                'outcome' => $expectedState === 'absent' ? 'initialized' : 'accepted',
                'replayed' => false,
                'before_baseline_sha256' => $snapshot['before_baseline_sha256'],
                'baseline_sha256' => hash('sha256', $baseline),
                'code_drift' => $snapshot['code_drift'],
            ];
            $receiptBytes = self::json_bytes($receipt);
            self::assert_transaction_authority($authority, 'before baseline and receipt publication');
            $locked->publish_acceptance($baseline, $receiptBytes);
            self::assert_transaction_authority($authority, 'after baseline and receipt publication');
            $readback = $locked->readback();
            $storedReceipt = $readback['receipt'];
            $storedBaseline = $readback['baseline'];
            if (!hash_equals($baseline, (string) ($storedBaseline ?? ''))
                || !hash_equals($receiptBytes, (string) ($storedReceipt ?? ''))) {
                throw new \RuntimeException(
                    'wprism: code-baseline acceptance ledger readback disagrees with authored bytes'
                );
            }
            LifecyclePlanner::assert_code_baseline_publication(
                $desired,
                $snapshot['live_facts_sha256'],
                $baseline,
                $locked->reread_observation(
                    $storedBaseline,
                    'code baseline publication proof'
                )
            );
            self::assert_transaction_authority($authority, 'after baseline publication proof');
            self::assert_transaction_authority($authority, 'before baseline acceptance commit');
            self::commit(
                $transactionOpen,
                $terminalControlAttempted,
                'code baseline acceptance transaction'
            );
            return $receipt;
        } catch (\Throwable $failure) {
            if ($transactionOpen && !$terminalControlAttempted) {
                Db::rollback_after_failure($failure, 'code baseline acceptance transaction rollback');
            }
            throw $failure;
        }
    }

    private static function assert_transaction_authority(
        TransactionAuthority $authority,
        string $where
    ): void {
        PromotionLease::assert_process_fence();
        if (!$authority->equals(Db::transaction_authority('code baseline acceptance ' . $where))) {
            throw new DatabaseTransactionOutcomeException(
                'code baseline acceptance changed database session authority ' . $where
            );
        }
    }

    private static function commit(bool &$open, bool &$attempted, string $context): void {
        $attempted = true;
        try {
            Db::commit($context . ' commit');
        } catch (DatabaseMutationException|TransientDbException $notCommitted) {
            Db::rollback_after_failure($notCommitted, $context . ' rollback after refused commit');
            $open = false;
            $attempted = false;
            throw $notCommitted;
        }
        $open = false;
    }

    private static function rollback(bool &$open, bool &$attempted, string $context): void {
        $attempted = true;
        try {
            Db::rollback($context . ' rollback');
        } catch (DatabaseMutationException|TransientDbException $notRolledBack) {
            Db::rollback_after_failure(
                $notRolledBack,
                $context . ' rollback after refused rollback'
            );
            $open = false;
            $attempted = false;
            throw $notRolledBack;
        }
        $open = false;
    }

    /** @return array<string,mixed> */
    private static function decode_receipt(string $raw): array {
        try {
            $receipt = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: code-baseline acceptance receipt is malformed', 0, $failure);
        }
        $keys = is_array($receipt) ? array_keys($receipt) : [];
        sort($keys, SORT_STRING);
        $before = is_array($receipt) ? ($receipt['before_baseline_sha256'] ?? null) : null;
        $drift = is_array($receipt) ? ($receipt['code_drift'] ?? null) : null;
        if ($keys !== [
            'artifact_hash', 'baseline_sha256', 'before_baseline_sha256', 'code_drift', 'format',
            'observation_sha256', 'operation_id', 'outcome', 'replayed',
        ]
            || ($receipt['format'] ?? null) !== self::FORMAT
            || ($receipt['replayed'] ?? null) !== false
            || !in_array($receipt['outcome'] ?? null, ['accepted', 'initialized'], true)
            || !is_string($receipt['operation_id'] ?? null)
            || preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', (string) ($receipt['operation_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['artifact_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['observation_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['baseline_sha256'] ?? '')) !== 1
            || ($before !== null && preg_match('/^[a-f0-9]{64}$/D', (string) $before) !== 1)
            || !is_array($drift)
            || !array_is_list($drift)
            || !self::valid_code_drift_rows($drift)
            || (($receipt['outcome'] ?? null) === 'initialized' && ($before !== null || $drift !== []))
            || (($receipt['outcome'] ?? null) === 'accepted' && (!is_string($before) || $drift === []))) {
            throw new \RuntimeException('wprism: code-baseline acceptance receipt is malformed');
        }
        return $receipt;
    }

    /** Persisted replay authority uses the same closed row shape as the host wire. */
    private static function valid_code_drift_rows(array $rows): bool {
        if (count($rows) > 4096) {
            return false;
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return false;
            }
            $kind = $row['kind'] ?? null;
            $issue = $row['issue'] ?? null;
            $identityKey = $kind === 'plugin' ? 'plugin' : ($kind === 'theme' ? 'theme' : null);
            $identity = $identityKey === null ? null : ($row[$identityKey] ?? null);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $expectedKeys = [
                'installed_version', 'issue', 'kind', 'message', 'recorded_version', (string) $identityKey,
            ];
            sort($expectedKeys, SORT_STRING);
            if ($identityKey === null
                || !in_array($issue, ['code_drift', 'code_baseline_missing'], true)
                || $keys !== $expectedKeys
                || !is_string($identity)
                || $identity === ''
                || strlen($identity) > 512
                || self::has_controls($identity)
                || !is_string($row['installed_version'] ?? null)
                || !is_string($row['recorded_version'] ?? null)
                || strlen($row['installed_version']) > 512
                || strlen($row['recorded_version']) > 512
                || self::has_controls($row['installed_version'])
                || self::has_controls($row['recorded_version'])
                || !is_string($row['message'] ?? null)
                || $row['message'] === ''
                || strlen($row['message']) > 8192
                || self::has_controls($row['message'])
                || ($issue === 'code_baseline_missing'
                    && ($row['installed_version'] === '' || $row['recorded_version'] !== ''))
                || ($issue === 'code_drift'
                    && ($row['installed_version'] === ''
                        || $row['recorded_version'] === ''
                        || hash_equals($row['installed_version'], $row['recorded_version'])))) {
                return false;
            }
            $identity = $kind . ':' . $identity;
            if (isset($seen[$identity])) {
                return false;
            }
            $seen[$identity] = true;
        }
        return true;
    }

    private static function has_controls(string $value): bool {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private static function json_bytes(array $value): string {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
