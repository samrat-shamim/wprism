<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/TransientDbException.php';
require_once __DIR__ . '/../Kernel/Db.php';
require_once __DIR__ . '/../Kernel/TableSchema.php';
require_once __DIR__ . '/../Publication/Publish.php';
require_once __DIR__ . '/../Policy/Policy.php';

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
        if ($optionsOnly) {
            self::assert_options_engine_support($policy);
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = [
            $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy,
            $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options, $wpdb->users,
            $wpdb->usermeta,
            $prefix . 'duo_map', $prefix . 'duo_state', $prefix . 'duo_kv',
        ];
        foreach (array_keys($policy->declared_tables()) as $name) {
            $tables[] = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', $name);
        }
        $tables = array_values(array_unique($tables));

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
                    'duo: capture storage-engine inventory could not be read; refusing to assume snapshot support'
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
            $operatorMessage = 'duo: capture refused — consistent-snapshot isolation requires InnoDB, but the following table(s) '
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
        $prefix = $wpdb->prefix;
        $tables = [
            $wpdb->postmeta, $wpdb->termmeta, $wpdb->posts, $wpdb->terms,
            $wpdb->term_taxonomy, $wpdb->options,
            $prefix . 'duo_map', $prefix . 'duo_state', $prefix . 'duo_kv',
        ];
        $refKinds = array_fill_keys(array_map(
            static fn(array $rule): string => (string) ($rule['id_kind'] ?? ''),
            $policy->option_name_ref_rules()
        ), true);
        foreach ($policy->declared_tables() as $name => $declaration) {
            if (!isset($refKinds[(string) ($declaration['id_kind'] ?? '')])) {
                continue;
            }
            $tables[] = $prefix . preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
        }
        $tables = array_values(array_unique(array_filter(
            $tables,
            static fn($table): bool => (string) $table !== ''
        )));
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
                    'duo: lifecycle options storage-engine inventory could not be read; refusing to assume snapshot support'
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
            'duo: lifecycle options snapshot refused — consistent-snapshot isolation requires InnoDB, but '
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
     * The callback may report its publication phase through the by-reference
     * array. Once `filesystem_swapped` is true, this method never replays it.
     *
     * Storage-engine validation is deliberately repeated here even when a
     * higher-level Capture entry point performed an earlier preflight. That
     * makes this public class safe on its own and closes the time-of-check gap
     * immediately before the transaction begins.
     *
     * @param callable():mixed $fn
     * @param ?array<string,mixed> $phase
     */
    public static function run(
        Policy $policy,
        callable $fn,
        ?array &$phase = null,
        bool $optionsOnly = false
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
                // WITH CONSISTENT SNAPSHOT does not itself upgrade a session
                // configured for READ COMMITTED. Control the immediately
                // following transaction explicitly; ordinary WP accounts can
                // execute this one-shot SET without PROCESS privileges.
                Db::query(
                    'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                    'capture transaction isolation'
                );
                self::check_transient_db_error('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                Db::query('START TRANSACTION WITH CONSISTENT SNAPSHOT', 'capture transaction start');
                // Mark the transaction open immediately after query() returns:
                // the following checkpoint can still report a transient
                // driver error even though START succeeded and therefore
                // needs a rollback before retrying.
                $transactionOpen = true;
                self::check_transient_db_error('START TRANSACTION WITH CONSISTENT SNAPSHOT');
                $result = $fn();
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
                Db::commit('capture transaction commit');
                // A successful COMMIT closes the transaction even if its
                // post-query error checkpoint reports a stale driver message;
                // never issue ROLLBACK after that commit. If the checkpoint
                // does report an error, the commit outcome is ambiguous: the
                // callback must not be retried because the database may have
                // accepted its writes already.
                $transactionOpen = false;
                try {
                    self::check_transient_db_error('COMMIT');
                } catch (\Throwable $commitCheck) {
                    throw self::commit_outcome_uncertain(
                        'duo: capture commit outcome uncertain — COMMIT returned, but its database error '
                        . 'checkpoint failed; refusing to retry because the candidate may already be durable',
                        $commitCheck
                    );
                }
                return $result;
            } catch (TransientDbException $e) {
                if ($commitAttempted) {
                    // Db::commit() can throw when the server accepted or
                    // rejected COMMIT; the client cannot distinguish those
                    // outcomes. Never retry or issue a compensating
                    // rollback after crossing that boundary.
                    throw self::commit_outcome_uncertain(
                        'duo: capture commit outcome uncertain — COMMIT did not return a definitive success; '
                        . 'refusing to retry because candidate DML may already be durable',
                        $e
                    );
                }
                if ($transactionOpen) {
                    Db::rollback('capture transaction rollback');
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
                        'duo: capture failed after filesystem publication began — refusing to retry the candidate; '
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
                        "duo: capture failed after $attempt attempt(s) — repeated transient database contention "
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
                        'duo: capture commit outcome uncertain — COMMIT returned no definitive success; '
                        . 'refusing to retry because candidate DML may already be durable',
                        $t
                    );
                }
                if ($transactionOpen) {
                    Db::rollback('capture transaction rollback');
                }
                throw $t;
            }
        }
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
        if (stripos($err, 'Deadlock found') !== false || stripos($err, 'Lock wait timeout') !== false) {
            throw new TransientDbException("duo: transient DB contention at $where");
        }
        throw new \RuntimeException("duo: unexpected SQL error at $where");
    }
}
