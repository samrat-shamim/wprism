<?php
namespace WPrism;

require_once __DIR__ . '/../Adapter/StoragePrerequisites.php';
require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/TransientDbException.php';
require_once __DIR__ . '/../Kernel/Db.php';
require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';
require_once __DIR__ . '/../Kernel/NativeDatabaseProfile.php';
require_once __DIR__ . '/../Kernel/TableSchema.php';
require_once __DIR__ . '/../Publication/Publish.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/SchemaSettlementIntent.php';

/**
 * Owns capture's consistent-read transaction and replay-safety boundary.
 *
 * Candidate discovery and entity extraction live in CaptureCandidateBuilder,
 * while durable filesystem publication and recovery live in their dedicated
 * collaborators. This service owns the database snapshot around that work:
 * path-specific storage-engine
 * validation, bounded retries before publication, the durable pre-COMMIT
 * phase transition, rollback, and fail-closed handling once COMMIT or the
 * filesystem swap makes replay unsafe.
 */
final class CaptureTransaction {
    /**
     * One initial attempt plus two retries for brief database contention.
     * Sustained contention is surfaced as a finite operator refusal.
     */
    private const MAX_DB_ATTEMPTS = 3;

    /**
     * Refuse a transaction whose complete read set cannot provide MVCC.
     *
     * The lifecycle-options path deliberately checks a narrower set: plugin
     * lifecycle hooks may run before or after plugin-owned typed tables exist,
     * and that path reads only options, reference-triage rows, and the ledger.
     */
    public static function assert_engine_support(Policy $policy, bool $optionsOnly = false): void {
        SchemaSettlementIntent::assert_no_incomplete();
        if ($optionsOnly) {
            self::assert_options_engine_support($policy);
            return;
        }

        global $wpdb;
        $tables = self::database_profile($policy)->readable_tables();

        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
            $tables
        ), ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw self::schema_refusal(
                new \RuntimeException(
                    'wprism: capture storage-engine inventory could not be read; refusing to assume snapshot support'
                )
            );
        }

        $bad = [];
        foreach ($rows as $row) {
            $engine = strtoupper((string) ($row['ENGINE'] ?? ''));
            if ($engine !== '' && $engine !== 'INNODB') {
                $bad[] = "{$row['TABLE_NAME']} (engine: $engine)";
            }
        }
        if ($bad !== []) {
            sort($bad);
            $operatorMessage = 'wprism: capture refused — consistent-snapshot isolation requires InnoDB, but the following table(s) '
                . 'capture reads from use a different storage engine (no MVCC/undo log, so a consistent-snapshot '
                . "transaction gives no real point-in-time guarantee for them):\n  - " . implode("\n  - ", $bad)
                . "\nConvert the table(s) to InnoDB (e.g. ALTER TABLE <table> ENGINE=InnoDB) and re-run capture.";
            $diagnostics = array_map(static fn(string $table): array => [
                'code' => 'unsupported_storage_engine',
                'table' => $table,
                'message' => 'capture cannot prove a coherent snapshot for this table',
                'remediation' => 'convert the table to InnoDB before another capture',
            ], $bad);
            throw new CommandRefusalException(
                'capture_snapshot_unsupported',
                'capture refused because one or more tables cannot provide a coherent snapshot',
                'resolve every storage-engine diagnostic before another capture',
                $diagnostics,
                $operatorMessage
            );
        }

        try {
            TableSchema::assert_core_capture_schema();
        } catch (\Throwable $failure) {
            throw self::schema_refusal($failure);
        }
    }

    /** Validate only the tables read by the lifecycle options handoff. */
    private static function assert_options_engine_support(Policy $policy): void {
        global $wpdb;
        $tables = self::database_profile($policy, true)->readable_tables();
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
            $tables
        ), ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw self::schema_refusal(
                new \RuntimeException(
                    'wprism: lifecycle options storage-engine inventory could not be read; refusing to assume snapshot support'
                )
            );
        }
        $bad = [];
        foreach ($rows as $row) {
            $engine = strtoupper((string) ($row['ENGINE'] ?? ''));
            if ($engine !== '' && $engine !== 'INNODB') {
                $bad[] = "{$row['TABLE_NAME']} (engine: $engine)";
            }
        }
        if ($bad === []) {
            try {
                TableSchema::assert_core_capture_schema();
            } catch (\Throwable $failure) {
                throw self::schema_refusal($failure);
            }
            return;
        }

        sort($bad);
        throw new \RuntimeException(
            'wprism: lifecycle options snapshot refused — consistent-snapshot isolation requires InnoDB, but '
            . "the following lifecycle-read table(s) use a different storage engine:\n  - "
            . implode("\n  - ", $bad)
            . "\nConvert the table(s) to InnoDB and re-run deploy."
        );
    }

    private static function schema_refusal(\Throwable $failure): CommandRefusalException {
        $diagnostic = [
            'code' => 'core_schema_drift',
            'message' => 'required WordPress core table or column evidence is unavailable',
            'remediation' => 'repair the WordPress database schema and retry from an unchanged repository revision',
        ];
        if ($failure instanceof CoreCaptureSchemaException) {
            // Logical wpdb binding names and fixed core column names are safe,
            // actionable contract locations. Never publish the physical table
            // prefix or the database driver's arbitrary error text.
            $diagnostic['missing'] = $failure->missing;
        }
        return new CommandRefusalException(
            'capture_schema_unsupported',
            'capture refused because the WordPress core schema cannot satisfy the adapter contract',
            'restore the required WordPress core table schema before another capture, plan, or apply',
            [$diagnostic],
            $failure->getMessage(),
            $failure
        );
    }

    /**
     * Run one candidate build inside a coherent InnoDB snapshot.
     *
     * This callback is an engine orchestration boundary, never a provider or
     * native hook: its exact work authority is passed only to core semantic
     * readers/publishers. They do not forward it to their native callbacks.
     *
     * The callback may report its publication phase through the by-reference
     * array. Once `filesystem_swapped` is true, this method never replays it.
     *
     * Storage-engine validation is deliberately repeated here even when a
     * higher-level Capture entry point performed an earlier preflight. That
     * makes this public class safe on its own and closes the time-of-check gap
     * immediately before the transaction begins.
     *
     * @param callable(DatabaseWorkAuthority):mixed $fn
     * @param ?array<string,mixed> $phase
     */
    public static function run(
        Policy $policy,
        callable $fn,
        ?array &$phase = null,
        bool $optionsOnly = false,
        bool $readOnly = false
    ) {
        $phase ??= [];
        $attempt = 0;
        while (true) {
            $attempt++;
            $transactionOpen = false;
            $commitAttempted = false;
            // A retry is safe only before the filesystem swap boundary. The
            // callback sets this immediately before swap(); once true, a
            // transient DB error must fail closed instead of rebuilding or
            // replaying a candidate against a tree that may already be new.
            $phase['filesystem_swapped'] = false;
            try {
                // Every retry starts from a fresh database state, so its
                // engine premise must be fresh too. A table can be altered
                // between attempts; never let a prior check authorize a new
                // snapshot whose read set no longer provides MVCC.
                self::assert_engine_support($policy, $optionsOnly);
                // Db binds the one-shot REPEATABLE READ control, START
                // outcome, connection identity, and later COMMIT/ROLLBACK to
                // one exact server transaction. A false client result after
                // an applied START is safe only because that active state is
                // positively proven on the same connection.
                $profile = self::database_profile($policy, $optionsOnly, $readOnly);
                if ($profile->is_read_only()) {
                    $workAuthority = Db::start_read_only_consistent_snapshot(
                        'capture transaction start',
                        $profile
                    );
                } else {
                    $workAuthority = Db::start_consistent_snapshot('capture transaction start', $profile);
                }
                $transactionOpen = true;
                $result = DatabaseQueryIsolation::with_engine_work_units(
                    $workAuthority,
                    static function () use ($policy, $optionsOnly, $fn, $workAuthority): mixed {
                        if (!$optionsOnly) StoragePrerequisites::assert_ready($policy);
                        return $fn($workAuthority);
                    }
                );
                if (isset($phase['state_dir'], $phase['intent'])
                    && is_string($phase['state_dir']) && is_array($phase['intent'])) {
                    // The durable `committing` marker is written before the
                    // client issues COMMIT. Recovery may therefore restore a
                    // swapped tree in `ready`/`swapped` states, but must
                    // refuse to guess once this marker exists.
                    $phase['intent'] = Publish::mark_committing(
                        $phase['state_dir'],
                        $phase['intent'],
                        ($phase['initial_baseline'] ?? false) === true
                    );
                }
                $commitAttempted = true;
                try {
                    Db::commit('capture transaction commit');
                } catch (DatabaseTransactionOutcomeException $outcome) {
                    throw self::commit_outcome_uncertain(
                        'wprism: capture commit outcome uncertain — the exact transaction or connection '
                            . 'could not be proven after COMMIT; refusing to retry because candidate DML '
                            . 'may already be durable',
                        $outcome
                    );
                } catch (DatabaseMutationException|TransientDbException $notCommitted) {
                    // Db throws these terminal classes only while the same
                    // transaction is still positively active. Roll it back
                    // before the outer retry/refusal branch; a reconnect or
                    // applied-but-false COMMIT takes the outcome exception
                    // above instead.
                    Db::rollback_after_failure(
                        $notCommitted,
                        'capture transaction rollback after refused commit'
                    );
                    $transactionOpen = false;
                    $commitAttempted = false;
                    throw $notCommitted;
                }
                $transactionOpen = false;
                return $result;
            } catch (TransientDbException $e) {
                if ($commitAttempted) {
                    // Db::commit() can throw when the server accepted or
                    // rejected COMMIT; the client cannot distinguish those
                    // outcomes. Never retry or issue a compensating
                    // rollback after crossing that boundary.
                    throw self::commit_outcome_uncertain(
                        'wprism: capture commit outcome uncertain — COMMIT did not return a definitive success; '
                        . 'refusing to retry because candidate DML may already be durable',
                        $e
                    );
                }
                if ($transactionOpen) {
                    Db::rollback_after_failure($e, 'capture transaction rollback');
                }
                if (!empty($phase['filesystem_swapped'])) {
                    throw new CommandRefusalException(
                        'capture_recovery_required',
                        'capture stopped after filesystem publication began',
                        'do not replay the candidate; inspect the durable intent and retained backup, then run exact capture recovery',
                        [[
                            'code' => 'capture_recovery_required',
                            'message' => 'filesystem publication crossed its replay-safe boundary',
                            'remediation' => 'preserve the intent and backup and reconcile the recorded publication before another capture',
                        ]],
                        'wprism: capture failed after filesystem publication began — refusing to retry the candidate; '
                            . 'the next run must reconcile its durable intent/backup artifacts',
                        $e
                    );
                }
                if ($attempt >= self::MAX_DB_ATTEMPTS) {
                    throw new CommandRefusalException(
                        'capture_contention_exhausted',
                        'capture exhausted its bounded database-contention retries',
                        'wait for the competing WordPress writer to finish, then start a new capture',
                        [[
                            'code' => 'capture_contention_exhausted',
                            'attempts' => $attempt,
                            'message' => 'transient database contention persisted through the bounded retry window',
                            'remediation' => 'wait for the competing writer to finish before another capture',
                        ]],
                        "wprism: capture failed after $attempt attempt(s) — repeated transient database contention "
                            . "(a concurrent WordPress write kept colliding with capture's own identity-minting "
                            . 'writes): ' . $e->getMessage(),
                        $e
                    );
                }
                usleep(200_000 * $attempt); // 200ms, 400ms, ... — brief contention, not a sustained outage
                continue;
            } catch (\Throwable $t) {
                if ($commitAttempted) {
                    if ($t instanceof CommandRefusalException && $t->reasonCode === 'capture_commit_uncertain') {
                        throw $t;
                    }
                    throw self::commit_outcome_uncertain(
                        'wprism: capture commit outcome uncertain — COMMIT returned no definitive success; '
                        . 'refusing to retry because candidate DML may already be durable',
                        $t
                    );
                }
                if ($transactionOpen) {
                    Db::rollback_after_failure($t, 'capture transaction rollback');
                }
                throw $t;
            }
        }
    }

    /**
     * Bind the complete physical query surface before capture code or plugin
     * filters run. Options-only and strict observation cannot mint identity;
     * ordinary capture may repair the two embedded identities and ledger.
     */
    public static function database_profile(
        Policy $policy,
        bool $optionsOnly = false,
        bool $readOnly = false
    ): NativeDatabaseProfile {
        global $wpdb;
        $prefix = $wpdb->prefix;
        if ($optionsOnly) {
            $tables = [
                $wpdb->postmeta,
                $wpdb->termmeta,
                $wpdb->posts,
                $wpdb->terms,
                $wpdb->term_taxonomy,
                $wpdb->options,
                $prefix . 'wprism_map',
                $prefix . 'wprism_state',
                $prefix . 'wprism_kv',
            ];
            $refKinds = array_fill_keys(array_map(
                static fn(array $rule): string => (string) ($rule['id_kind'] ?? ''),
                $policy->option_name_ref_rules()
            ), true);
            $presenceTables = [];
            foreach ($policy->declared_tables() as $name => $declaration) {
                if (isset($refKinds[(string) ($declaration['id_kind'] ?? '')])) {
                    $table = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
                    $presenceTables[] = $table;
                    if (self::declared_table_exists($table)) {
                        $tables[] = $table;
                    }
                }
            }
            $tables = array_values(array_unique(array_filter(
                $tables,
                static fn($table): bool => is_string($table) && $table !== ''
            )));
            return NativeDatabaseProfile::schema_read_only($tables, array_values(array_unique($presenceTables)));
        }

        $tables = [
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->terms,
            $wpdb->term_taxonomy,
            $wpdb->term_relationships,
            $wpdb->termmeta,
            $wpdb->options,
            $wpdb->users,
            $wpdb->usermeta,
            $prefix . 'wprism_map',
            $prefix . 'wprism_state',
            $prefix . 'wprism_kv',
        ];
        // Snapshot repeats each declared-table probe after START before it
        // reads schema. An absent preimage therefore needs presence-only
        // authority; adding it to $tables would instead grant row reads for a
        // physical table this boundary never proved exists or uses InnoDB.
        $presenceReads = [];
        foreach (array_keys($policy->declared_tables()) as $name) {
            $table = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            $presenceReads[] = $table;
            if (self::declared_table_exists($table)) {
                $tables[] = $table;
            }
        }
        $tables = array_values(array_unique($tables));
        if ($readOnly) {
            return NativeDatabaseProfile::schema_read_only($tables, $presenceReads);
        }
        return new NativeDatabaseProfile($tables, [
            $wpdb->postmeta,
            $wpdb->termmeta,
            $prefix . 'wprism_map',
            $prefix . 'wprism_state',
            $prefix . 'wprism_kv',
        ], $presenceReads);
    }

    /** Match Snapshot's optional-table behavior without authorizing an alias. */
    private static function declared_table_exists(string $table): bool {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($table)
        ));
        return is_string($found) && hash_equals($table, $found);
    }

    /** Build the stable machine-readable refusal for every uncertain COMMIT. */
    public static function commit_outcome_uncertain(
        string $operatorMessage,
        \Throwable $previous
    ): CommandRefusalException {
        return new CommandRefusalException(
            'capture_commit_uncertain',
            'capture commit outcome is uncertain',
            'do not retry or discard recovery artifacts; inspect the durable intent, receipt, and database commit proof, then reconcile that exact publication',
            [[
                'code' => 'capture_commit_uncertain',
                'message' => 'the database may have committed the candidate, so replay is unsafe',
                'remediation' => 'preserve all recovery evidence and determine the exact commit outcome before continuing',
            ]],
            $operatorMessage,
            $previous
        );
    }

    /**
     * Promote wpdb's string-only error checkpoint into a typed failure.
     *
     * MySQL/MariaDB errno 1213 and 1205 have stable message fragments. Only
     * those contention failures are retryable; every other SQL error fails
     * immediately so capture cannot continue from an unproven mutation.
     */
    public static function check_transient_db_error(string $where): void {
        global $wpdb;
        $err = (string) $wpdb->last_error;
        if ($err === '') {
            return;
        }
        if (stripos($err, 'Deadlock found') !== false) {
            throw new DeadlockTransactionAbortedException(
                "wprism: database deadlock aborted the transaction at $where"
            );
        }
        if (stripos($err, 'Lock wait timeout') !== false) {
            throw new TransientDbException("wprism: transient DB contention at $where");
        }
        throw new \RuntimeException("wprism: unexpected SQL error at $where");
    }
}
