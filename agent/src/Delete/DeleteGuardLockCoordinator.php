<?php
namespace WPrism;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/DeleteGuardReferenceScanner.php';
require_once __DIR__ . '/DeleteGuardEvaluator.php';
require_once __DIR__ . '/Deletion.php';
require_once __DIR__ . '/ExecutableOwnerBoundary.php';
require_once __DIR__ . '/DeletionWriterExclusion.php';

/** Owns the transactional lock-and-recheck boundary for deletion guards. */
final class DeleteGuardLockCoordinator {
    private bool $guardTableTouched = false;
    private ExecutableOwnerBoundary $executableOwnerBoundary;
    private DeletionWriterExclusion $writerExclusion;

    public function __construct(
        private readonly Policy $policy,
        private readonly DeleteGuardReferenceScanner $scanner,
        private readonly array $snapshotRowTables,
        ?\Closure $verifyWriterExclusion = null
    ) {
        $this->executableOwnerBoundary = new ExecutableOwnerBoundary($policy);
        $this->writerExclusion = new DeletionWriterExclusion($verifyWriterExclusion);
    }

    /** Bind the exact scoped-promotion witness before any target mutation. */
    public function bind_writer_exclusion(array $witness): void {
        $this->writerExclusion->bind($witness);
    }

    /** Refuse delete intent whose signed external exclusion is absent or lost. */
    public function assert_writer_exclusion_plan_authority(): void {
        $this->writerExclusion->assert_plan_authority();
    }

    /** @return array{count:int,error:?string,rows:string[],witness?:string} */
    public function count(
        array $guard,
        string $targetUuid,
        array $deleteUuids,
        array $deletions,
        array $tree = [],
        array $guardRepairUuids = [],
        bool $forUpdate = false
    ): array {
        return $this->scanner->count(
            $guard,
            $targetUuid,
            $deleteUuids,
            $deletions,
            $tree,
            $guardRepairUuids,
            $forUpdate
        );
    }

    public function lock_and_revalidate(
        array $deleteWork,
        array $deleteUuids,
        array $deletions,
        array $tree,
        array $guardRepairUuids
    ): void {
        // Only the external recovery provider covers web/cron/CLI lifecycle
        // and filesystem writers. Verify that exact signed generation inside
        // this transaction before binding any activation/code owner fact.
        $this->writerExclusion->begin_authored_transaction();
        // Activation options are mutable authored rows. Bind their raw bytes
        // under the same transaction as guard rows before any delete can run;
        // get_option() would only attest an unversioned request-cache value.
        $this->executableOwnerBoundary->bind($deleteWork);
        $this->assert_guard_engines($deleteWork);
        $this->assert_lock_isolation();
        DeleteGuardEvaluator::assert_revalidated_witnesses(
            $deleteWork,
            function (array $row): array {
                $capability = Deletion::capability(
                    $this->policy,
                    (string) $row['deletion_kind'],
                    (string) $row['deletion_type']
                );
                return (array) ($capability['guards'] ?? []);
            },
            function (array $guard, string $targetUuid, bool $lock) use (
                $deleteUuids,
                $deletions,
                $tree,
                $guardRepairUuids
            ): array {
                return $this->count(
                    $guard,
                    $targetUuid,
                    $deleteUuids,
                    $deletions,
                    $tree,
                    $guardRepairUuids,
                    $lock
                );
            }
        );
    }

    public function assert_guard_engines(array $deleteWork): void {
        global $wpdb;

        $this->guardTableTouched = false;
        $tables = [];
        $invalidGuards = [];
        foreach ($deleteWork as $row) {
            $capability = Deletion::capability(
                $this->policy,
                (string) ($row['deletion_kind'] ?? ''),
                (string) ($row['deletion_type'] ?? '')
            );
            foreach ($capability['guards'] ?? [] as $guard) {
                $declared = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($guard['table'] ?? ''));
                if ($declared === '') {
                    $invalidGuards[] = (string) ($guard['table'] ?? '');
                    continue;
                }
                $table = (string) $wpdb->prefix . $declared;
                if (($guard['optional_table'] ?? null) === true) {
                    // The signed all-database-writer exclusion is already
                    // bound for this transaction. An exact absence probe can
                    // therefore omit a table that this reviewed adapter says
                    // the supported version may not install, while a present
                    // table still enters the ordinary MDL/InnoDB lock proof.
                    $wpdb->last_error = '';
                    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                    $error = trim((string) ($wpdb->last_error ?? ''));
                    if ($error !== '') {
                        throw new \RuntimeException(
                            "wprism: deletion guard locking refused — optional guard table existence probe failed: $error"
                        );
                    }
                    if (!$exists) {
                        continue;
                    }
                }
                $tables[$table] = true;
            }
        }
        if ($invalidGuards) {
            sort($invalidGuards, SORT_STRING);
            throw new \RuntimeException(
                'wprism: deletion guard locking refused — guard declaration has no usable table name: '
                . implode(', ', $invalidGuards)
            );
        }
        if ($tables) {
            DeleteGuardEvaluator::assert_innodb_tables(array_keys($tables));
            $this->guardTableTouched = true;
        }
    }

    public function assert_lock_isolation(): void {
        if (!$this->guardTableTouched) {
            return;
        }
        DeleteGuardEvaluator::assert_transaction_isolation();
    }

    public function recheck(
        array $row,
        array $deleteUuids,
        array $deletions,
        bool $forced,
        array $tree,
        array $guardRepairUuids,
        bool $forUpdate,
        array &$warnings
    ): void {
        $this->executableOwnerBoundary->assert_unchanged();
        $capability = Deletion::capability(
            $this->policy,
            (string) $row['deletion_kind'],
            (string) $row['deletion_type']
        );
        $findings = DeleteGuardEvaluator::final_recheck_findings(
            $row,
            (array) ($capability['guards'] ?? []),
            function (array $guard, bool $lock) use (
                $row,
                $deleteUuids,
                $deletions,
                $tree,
                $guardRepairUuids
            ): array {
                return $this->count(
                    $guard,
                    (string) $row['uuid'],
                    $deleteUuids,
                    $deletions,
                    $tree,
                    $guardRepairUuids,
                    $lock
                );
            },
            fn(string $table): bool => isset($this->snapshotRowTables[$table]),
            $forced,
            $forUpdate
        );
        if (!$findings['blocks']) {
            return;
        }
        $row['blocked'] = implode('; ', $findings['blocks']);
        $row['guard_refs'] = $findings['guard_refs'];
        self::append_forced_warnings($warnings, $row, 'FORCED delete after final guard recheck');
    }

    /** Re-sample filesystem owners at the actual row-delete boundary. */
    public function assert_executable_owner_boundary(): void {
        $this->executableOwnerBoundary->assert_unchanged();
    }

    /** Verify external exclusion + executable identity and arm one delete. */
    public function authorize_destructive_unit(): void {
        $this->writerExclusion->authorize_next_delete();
        try {
            $this->executableOwnerBoundary->assert_unchanged();
        } catch (\Throwable $failure) {
            $this->writerExclusion->cancel_next_delete();
            throw $failure;
        }
    }

    /** Same-module callback target consumed by DeleteExecutor before lookup. */
    public function consume_destructive_unit(): void {
        $this->writerExclusion->consume_delete_authority();
    }

    /** Last authored-transaction operation before the database COMMIT. */
    public function assert_writer_exclusion_commit_boundary(): void {
        $this->writerExclusion->assert_commit_boundary();
    }

    /** Clear the transaction-local token after either commit or rollback. */
    public function end_writer_exclusion_transaction(): void {
        $this->writerExclusion->end_authored_transaction();
    }

    /** Refuse runtime-owned semantic guards before force can authorize work. */
    public static function assert_no_non_forceable_delete_guards(array $deleteWork): void {
        $blocked = array_filter(
            $deleteWork,
            static fn(array $row): bool => isset($row['blocked'], $row['non_forceable_guard'])
        );
        if ($blocked === []) {
            return;
        }
        $list = implode("\n  - ", array_map(
            static fn(array $row): string => "{$row['type']} {$row['uuid']}: {$row['non_forceable_guard']}",
            $blocked
        ));
        throw CommandRefusalException::applyRefused(
            'deletion is blocked by runtime state whose owning WooCommerce lifecycle has no safe generic cascade',
            'remove or expire the named runtime rows through WooCommerce, then rebuild the plan',
            "wprism: deletes blocked by non-forceable semantic guards; no target mutation attempted:\n  - $list"
        );
    }

    public static function append_forced_warnings(array &$warnings, array $row, string $prefix): void {
        if (empty($row['guard_refs'])) {
            $warnings[] = "$prefix {$row['type']} {$row['uuid']} ({$row['blocked']})";
            return;
        }
        foreach ($row['guard_refs'] as $finding) {
            $table = (string) $finding['table'];
            $rows = (array) $finding['rows'];
            $message = "$prefix {$row['type']} {$row['uuid']}: " . count($rows)
                . " rows in $table will be orphaned; ";
            if (!empty($finding['option_name_ref'])) {
                $message .= 'the matching options/core tombstone is the supported repair; ';
            } elseif (!empty($finding['repairable'])) {
                $message .= "wp wprism plan/capture on $table will refuse until resolved (wp wprism orphans $table). ";
            } else {
                $message .= "$table is not a declared authored-snapshot table and must be resolved through its owning content workflow. ";
            }
            $warnings[] = $message . 'Surviving rows: ' . implode(', ', $rows);
        }
    }
}
