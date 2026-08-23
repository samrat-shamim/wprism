<?php
namespace Duo;

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
        $connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
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
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((string) $acquired !== '1') {
            throw new \RuntimeException(
                'duo: promotion lock held by another live target process; concurrent target mutation refused'
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
        $connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        if ($connection !== self::$connection) {
            return false;
        }
        $holder = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', self::$name));
        return $holder !== null && (int) $holder === $connection;
    }

    public static function assertHeld(): void {
        if (!self::isContinuous()) {
            throw new \RuntimeException(
                'duo: promotion process fence is not continuously held by this database connection'
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
        return 'duo:' . substr(hash('sha256', $database . '|' . $wpdb->prefix), 0, 59);
    }
}
