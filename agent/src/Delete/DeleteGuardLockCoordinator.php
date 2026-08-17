<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/DeleteGuardReferenceScanner.php';
require_once __DIR__ . '/DeleteGuardEvaluator.php';
require_once __DIR__ . '/Deletion.php';

/** Owns the transactional lock-and-recheck boundary for deletion guards. */
final class DeleteGuardLockCoordinator {
    public function __construct(
        private readonly Policy $policy,
        private readonly DeleteGuardReferenceScanner $scanner,
        private readonly array $snapshotRowTables
    ) {}

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
        $this->assert_lock_isolation();
        $this->assert_guard_engines($deleteWork);
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
                $tables[(string) $wpdb->prefix . $declared] = true;
            }
        }
        if ($invalidGuards) {
            sort($invalidGuards, SORT_STRING);
            throw new \RuntimeException(
                'duo: deletion guard locking refused — guard declaration has no usable table name: '
                . implode(', ', $invalidGuards)
            );
        }
        if ($tables) {
            DeleteGuardEvaluator::assert_innodb_tables(array_keys($tables));
        }
    }

    public function assert_lock_isolation(): void {
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
                $message .= "wp duo plan/capture on $table will refuse until resolved (wp duo orphans $table). ";
            } else {
                $message .= "$table is not a declared authored-snapshot table and must be resolved through its owning content workflow. ";
            }
            $warnings[] = $message . 'Surviving rows: ' . implode(', ', $rows);
        }
    }
}
