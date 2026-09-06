<?php
namespace WPrism;

// The typed refusal both gates below throw. Required here rather than left to
// agent/wprism.php's bootstrap order: this file declares no other dependency, the
// offline lock suites load it directly, and the direct-require contract
// (sandbox/tests/offline/guards/regress_agent_src_requires.php) wants every
// engine class this file names to be loaded by it.
require_once __DIR__ . '/CommandRefusal.php';

/**
 * Connection-scoped target process fence.
 *
 * This service owns only MySQL GET_LOCK/IS_USED_LOCK/RELEASE_LOCK state.
 * PromotionLease owns owner/artifact policy and the durable lease row;
 * capture uses the same target-wide fence while it mutates identity and
 * state. A caller cannot release this fence through the read-only assertion
 * surface.
 */
final class ProcessFence {
    private const NAME_PREFIX = 'wprism:';
    private const MAX_NAME_BYTES = 64;

    private static ?string $name = null;
    private static ?int $connection = null;

    /**
     * Acquire the connection-scoped advisory fence.
     *
     * A connection change or a lost advisory lock is a continuity break, not
     * merely a fresh way to obtain the same fence.  The lease coordinator may
     * supply an invalidation callback so its process-local owner/artifact
     * witnesses are cleared before a replacement fence is attempted.  The
     * filesystem/SQL fence service remains independent of that policy.
     */
    public static function acquire(?callable $onDiscontinuity = null): void {
        global $wpdb;
        $name = self::name();
        $connection = self::connectionId();
        $continuous = self::$name === $name
            && self::$connection === $connection
            && self::isContinuous();
        if ($continuous) {
            return;
        }
        if ($onDiscontinuity !== null) {
            $onDiscontinuity();
        }
        self::$name = null;
        self::$connection = null;
        $acquired = self::databaseScalar($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if (!in_array($acquired, [0, '0', 1, '1'], true)) {
            // GET_LOCK returns NULL on error, never on contention. A failed
            // or malformed transport response cannot identify another writer.
            throw self::databaseUnavailable();
        }
        if ($acquired === 0 || $acquired === '0') {
            // TYPED, not a bare \RuntimeException: this is the single most
            // common refusal an orchestrator meets on capture/apply/deploy, and
            // `Cli::halt_json_failure()` collapsed every bare Throwable to
            // `<command>_failed` / "refused at an unclassified safety gate" with
            // `details_redacted: true` (agent/src/Command/Cli.php:83-98), so a
            // `--format=json` caller could not tell "someone else is running"
            // from a corrupt repository. The typed branch at Cli.php:60-64
            // publishes `payload()` verbatim instead.
            //
            // The 5th argument is the operator message: CommandRefusal.php:52
            // passes `$operatorMessage ?? $publicMessage` to
            // parent::__construct, so `getMessage()` -- and therefore
            // `WP_CLI::error($t->getMessage())` -- stays byte-identical to the
            // sentence these two live pins grep on a real pair
            // (sandbox/tests/live/regress_capture_concurrency.sh:500,656 and
            // sandbox/tests/live/regress_promotion_lock.sh:194). The public half
            // is a reviewed constant with no fence name, database, or prefix in
            // it; `ProcessFence::name()` is a hash of exactly those.
            throw new CommandRefusalException(
                'process_fence_held',
                'another live process on this target holds the promotion fence; concurrent target mutation was refused',
                'wait for the capture, apply, or promotion already running on this target to finish or release its fence, then retry this command',
                [],
                'wprism: promotion lock held by another live target process; concurrent target mutation refused'
            );
        }
        self::$name = $name;
        self::$connection = $connection;
    }

    public static function isContinuous(): bool {
        global $wpdb;
        if (self::$name === null || self::$connection === null) {
            return false;
        }
        $connection = self::connectionId();
        if ($connection !== self::$connection) {
            return false;
        }
        $holder = self::databaseScalar($wpdb->prepare('SELECT IS_USED_LOCK(%s)', self::$name));
        return $holder !== null && self::positiveConnectionId($holder) === $connection;
    }

    public static function assertHeld(): void {
        if (!self::isContinuous()) {
            // Same typed-refusal reasoning as acquire() above. A continuity
            // break is a DIFFERENT operator answer from contention -- the
            // connection changed or the advisory lock was lost under a mutation
            // that believed it held the fence -- so it carries its own reason
            // code rather than being folded into process_fence_held.
            throw new CommandRefusalException(
                'process_fence_not_held',
                'the promotion process fence is no longer continuously held by this command\'s database connection; target mutation was refused',
                'do not retry in place: rerun the command so it acquires a fresh fence, and inspect the recorded apply, promotion, and recovery evidence first if a mutation was already in flight',
                [],
                'wprism: promotion process fence is not continuously held by this database connection'
            );
        }
    }

    public static function release(): void {
        global $wpdb;
        $name = self::$name;
        self::$name = null;
        self::$connection = null;
        if ($name !== null) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public static function name(): string {
        global $wpdb;
        $database = is_string($wpdb->dbname ?? null) ? $wpdb->dbname : '';
        // MySQL permits at most 64 characters. All bytes here are ASCII;
        // deriving the hash budget from the prefix prevents another branding
        // edit from repeating the measured 7 + 59 = 66 byte refusal.
        return self::NAME_PREFIX . substr(
            hash('sha256', $database . '|' . $wpdb->prefix),
            0,
            self::MAX_NAME_BYTES - strlen(self::NAME_PREFIX)
        );
    }

    private static function connectionId(): int {
        return self::positiveConnectionId(self::databaseScalar('SELECT CONNECTION_ID()'));
    }

    private static function positiveConnectionId(mixed $value): int {
        if ((!is_string($value) && !is_int($value))
            || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1
            || (string) (int) $value !== (string) $value) {
            throw self::databaseUnavailable();
        }
        return (int) $value;
    }

    /** Preserve NULL for IS_USED_LOCK's valid unowned result, not query errors. */
    private static function databaseScalar(string $sql): mixed {
        global $wpdb;
        $previousSuppression = method_exists($wpdb, 'suppress_errors')
            ? $wpdb->suppress_errors(true)
            : null;
        try {
            $wpdb->last_error = '';
            try {
                $result = $wpdb->get_var($sql);
            } catch (\Throwable $failure) {
                throw self::databaseUnavailable($failure);
            }
            if ($result === false || trim((string) ($wpdb->last_error ?? '')) !== '') {
                throw self::databaseUnavailable();
            }
            return $result;
        } finally {
            if ($previousSuppression !== null) {
                $wpdb->suppress_errors((bool) $previousSuppression);
            }
        }
    }

    private static function databaseUnavailable(?\Throwable $previous = null): CommandRefusalException {
        return new CommandRefusalException(
            'process_fence_unavailable',
            'the target database could not establish or verify the process fence; target mutation was refused',
            'restore database connectivity and advisory-lock support, then rerun the command; inspect recorded apply, promotion, and recovery evidence first if a mutation was already in flight',
            [],
            'wprism: the target process fence is unavailable because its database lock state could not be established or verified',
            $previous
        );
    }
}
