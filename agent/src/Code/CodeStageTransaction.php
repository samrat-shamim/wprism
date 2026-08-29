<?php
namespace WPrism;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}

/**
 * Atomically publishes the staged code receipt.
 *
 * Materialization and lease ownership stay in Code. This collaborator owns
 * only the durable handoff from completed payload bytes to the five staged
 * ledger markers. Keeping that boundary explicit makes a marker/COMMIT
 * failure retryable without allowing a partial receipt to grant ownership.
 */
final class CodeStageTransaction {
    public const HISTORY_KEY = 'code_stage_history';
    public const DESCRIPTOR_KEY = 'code_stage_descriptor';
    public const ARTIFACT_KEY = 'code_stage_artifact';
    public const CREATED_PATHS_KEY = 'code_stage_created_paths';
    public const REVISION_KEY = 'code_stage_revision';

    /**
     * Publish one complete staged receipt or restore the exact prior ledger.
     *
     * @param array<string,array<string,mixed>> $history
     * @param array<string,mixed> $descriptor
     * @param list<string> $createdPaths
     */
    public static function publish(
        array $history,
        array $descriptor,
        string $artifact,
        array $createdPaths
    ): void {
        $transactionStarted = false;
        try {
            Db::start('code stage ledger transaction start');
            $transactionStarted = true;
            Ledger::kv_set(self::HISTORY_KEY, Canon::encode(array_values($history)));
            Ledger::kv_set(self::DESCRIPTOR_KEY, Canon::encode($descriptor));
            Ledger::kv_set(self::ARTIFACT_KEY, $artifact);
            Ledger::kv_set(self::CREATED_PATHS_KEY, Canon::encode($createdPaths));
            Ledger::kv_set(self::REVISION_KEY, $descriptor['code_revision']);
            Db::commit('code stage ledger transaction commit');
            $transactionStarted = false;
        } catch (\Throwable $t) {
            if ($transactionStarted) {
                Db::rollback_after_failure($t, 'code stage ledger transaction rollback');
            }
            throw $t;
        }
    }
}
