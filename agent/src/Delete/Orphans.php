<?php
namespace Duo;

if (!class_exists(Canary::class, false)) {
    require_once __DIR__ . '/../Kernel/Canary.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/../Repository/Snapshot.php';
}

/**
 * Enumerate and repair structural refs whose target identity no longer
 * exists. This is deliberately derived from authored_snapshot refs[]: it is
 * the same contract Snapshot capture enforces, not a second FK declaration.
 */
final class Orphans {
    /**
     * @return array{table:string,identity:string,rows:array,action:?string,canary:string}
     */
    public static function run(string $repo, string $table, array $opts = []): array {
        $policy = Policy::load($repo);
        Ledger::ensure();
        $tables = Snapshot::row_tables($policy);
        $decl = $tables[$table] ?? null;
        if ($decl === null) {
            throw new \RuntimeException(
                "duo: orphans requires a declared authored_snapshot table; '$table' is not one in this repository"
            );
        }
        Snapshot::assert_row_schema($table, $decl);

        $rows = self::scan($table, $decl);
        $delete = !empty($opts['delete']);
        $reparent = trim((string) ($opts['reparent'] ?? ''));
        if (!$delete && $reparent === '') {
            return [
                'table' => $table,
                'identity' => self::identity_help($decl),
                'rows' => $rows,
                'action' => null,
                'canary' => 'not-run',
            ];
        }
        if ($delete && $reparent !== '') {
            throw new \RuntimeException('duo: orphans accepts exactly one of --delete or --reparent=<column>=<target-local-id>');
        }
        if (($decl['identity']['mode'] ?? 'mapped') === 'composite_ref') {
            throw new \RuntimeException(
                "duo: mutation of composite_ref orphan rows in '$table' is not supported by scalar --row selection; "
                . 'delete/recreate the canonical join fact instead'
            );
        }

        $rowArg = trim((string) ($opts['row'] ?? ''));
        if (!preg_match('/^[1-9][0-9]*$/', $rowArg)) {
            throw new \RuntimeException("duo: --row=<positive-local-id> is required for '$table' repair");
        }
        $localId = (int) $rowArg;
        $selected = null;
        foreach ($rows as $row) {
            if (($row['local_id'] ?? null) === $localId) {
                $selected = $row;
                break;
            }
        }
        if ($selected === null) {
            throw new \RuntimeException("duo: $table {$decl['pk']}=$localId is not currently orphaned; no mutation attempted");
        }

        $action = $delete ? 'delete' : 'reparent';
        $transactionStarted = false;
        Canary::arm();
        try {
            Db::start('orphans transaction start');
            $transactionStarted = true;
            if ($delete) {
                $uuid = Ledger::uuid_for($localId, (string) $decl['id_kind']);
                Snapshot::delete_local_row($policy, $table, $localId);
                Snapshot::assert_row_deleted($policy, $table, $localId);
                if ($uuid !== null) {
                    Ledger::forget($uuid);
                }
            } else {
                [$column, $targetId] = self::parse_reparent($reparent);
                $kind = self::ref_kind($decl, $column);
                if ($kind === null) {
                    throw new \RuntimeException("duo: '$column' is not a declared structural ref column of '$table'");
                }
                if (Ledger::uuid_for($targetId, $kind) === null
                    || !self::target_exists($tables, $kind, $targetId)) {
                    throw new \RuntimeException(
                        "duo: cannot reparent $table {$decl['pk']}=$localId: target $kind local id $targetId is unmanaged or absent"
                    );
                }
                Snapshot::reparent_local_row($policy, $table, $localId, $column, $targetId);
                foreach (self::scan($table, $decl) as $remaining) {
                    if (($remaining['local_id'] ?? null) === $localId) {
                        throw new \RuntimeException(
                            "duo: reparent left $table {$decl['pk']}=$localId with unresolved structural refs; transaction rolled back"
                        );
                    }
                }
            }
            $violations = Canary::violations();
            if ($violations) {
                throw new \RuntimeException('duo: orphans canary violation: ' . implode('; ', $violations));
            }
            Db::commit('orphans transaction commit');
            $transactionStarted = false;
        } catch (\Throwable $t) {
            if ($transactionStarted) {
                try {
                    Db::rollback_after_failure($t, 'orphans transaction rollback');
                } catch (\Throwable $recoveryFailure) {
                    Canary::disarm();
                    throw $recoveryFailure;
                }
            }
            Canary::disarm();
            throw $t;
        }
        Canary::disarm();

        return [
            'table' => $table,
            'identity' => self::identity_help($decl),
            'rows' => self::scan($table, $decl),
            'action' => $action . ' ' . $table . '.' . $decl['pk'] . '=' . $localId,
            'canary' => 'clean',
        ];
    }

    private static function scan(string $table, array $decl): array {
        global $wpdb;
        $refs = $decl['refs'] ?? [];
        if (!$refs) {
            return [];
        }
        $identityCols = ($decl['identity']['mode'] ?? 'mapped') === 'composite_ref'
            ? array_values($decl['identity']['columns'])
            : [(string) $decl['pk']];
        $selectCols = array_values(array_unique(array_merge($identityCols, array_column($refs, 'column'))));
        $select = implode(', ', array_map(fn(string $c): string => '`' . self::name($c) . '`', $selectCols));
        $order = implode(', ', array_map(fn(string $c): string => '`' . self::name($c) . '` ASC', $identityCols));
        $prefixed = $wpdb->prefix . self::name($table);
        $live = $wpdb->get_results("SELECT $select FROM `$prefixed` ORDER BY $order", ARRAY_A) ?: [];
        if ($wpdb->last_error) {
            throw new \RuntimeException("duo: orphan scan failed for '$table'");
        }

        $out = [];
        foreach ($live as $liveRow) {
            $missing = [];
            foreach ($refs as $ref) {
                $raw = (int) ($liveRow[$ref['column']] ?? 0);
                if ($raw > 0 && Ledger::uuid_for($raw, (string) $ref['kind']) === null) {
                    $missing[] = [
                        'column' => (string) $ref['column'],
                        'kind' => (string) $ref['kind'],
                        'local_id' => $raw,
                    ];
                }
            }
            if (!$missing) {
                continue;
            }
            $identity = [];
            foreach ($identityCols as $column) {
                $identity[$column] = (int) $liveRow[$column];
            }
            $row = ['identity' => $identity, 'orphan_refs' => $missing];
            if (count($identityCols) === 1) {
                $row['local_id'] = (int) $liveRow[$identityCols[0]];
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function parse_reparent(string $value): array {
        if (!preg_match('/^([A-Za-z0-9_]+)=([1-9][0-9]*)$/', $value, $m)) {
            throw new \RuntimeException('duo: --reparent must be <column>=<positive-target-local-id>');
        }
        return [$m[1], (int) $m[2]];
    }

    private static function ref_kind(array $decl, string $column): ?string {
        foreach ($decl['refs'] ?? [] as $ref) {
            if (($ref['column'] ?? '') === $column) {
                return (string) $ref['kind'];
            }
        }
        return null;
    }

    private static function identity_help(array $decl): string {
        return ($decl['identity']['mode'] ?? 'mapped') === 'composite_ref'
            ? implode(',', $decl['identity']['columns'])
            : (string) $decl['pk'];
    }

    /** A ledger row alone is not proof that its plugin/core target survived. */
    private static function target_exists(array $tables, string $kind, int $localId): bool {
        global $wpdb;
        if ($kind === Ledger::KIND_POST) {
            $table = $wpdb->posts;
            $pk = 'ID';
        } elseif ($kind === Ledger::KIND_TERM) {
            $table = $wpdb->terms;
            $pk = 'term_id';
        } elseif ($kind === Ledger::KIND_TT) {
            $table = $wpdb->term_taxonomy;
            $pk = 'term_taxonomy_id';
        } else {
            $targetDecl = null;
            $targetTable = null;
            foreach ($tables as $candidateTable => $candidateDecl) {
                if (($candidateDecl['id_kind'] ?? null) === $kind) {
                    $targetTable = $candidateTable;
                    $targetDecl = $candidateDecl;
                    break;
                }
            }
            if ($targetDecl === null || !isset($targetDecl['pk'])) {
                return false;
            }
            $table = $wpdb->prefix . self::name($targetTable);
            $pk = self::name((string) $targetDecl['pk']);
        }
        return $wpdb->get_var($wpdb->prepare(
            "SELECT `$pk` FROM `$table` WHERE `$pk` = %d LIMIT 1",
            $localId
        )) !== null;
    }

    private static function name(string $name): string {
        return preg_replace('/[^A-Za-z0-9_]/', '', $name);
    }
}
