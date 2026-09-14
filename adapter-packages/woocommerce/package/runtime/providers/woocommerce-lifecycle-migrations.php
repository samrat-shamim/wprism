<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;

/** Bounded completion gate for WooCommerce's Action Scheduler DB updates. */
final class WoocommerceLifecycleMigrations extends ManifestProviderRuntime {
    private const UPDATE_HOOKS = [
        'woocommerce_run_update_callback',
        'woocommerce_update_db_to_current_version',
    ];

    private const UPDATE_GROUP = 'woocommerce-db-updates';

    /** The retired `--batch-size=25` from the WP-CLI command this replaced. */
    private const BATCH_SIZE = 25;

    /**
     * The retired command passed `--batches=0`, meaning "loop until the queue is
     * empty". An engine child is not the place for an unbounded loop, so the
     * budget is explicit: 240 claims of 25 is 6000 update actions, against the 69
     * update keys official 11.1.0 declares (includes/class-wc-install.php
     * $db_updates). Exhausting it is a refusal, never a silent partial settle.
     */
    private const MAX_BATCHES = 240;

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_settle_lifecycle_migrations(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle settlement accepts no arguments');
        }
        $before = self::snapshot();
        if (!self::settled($before)) {
            self::drain_update_queue();
        }
        $after = self::snapshot();
        if (!self::settled($after)) {
            throw new \RuntimeException(
                'wprism: WooCommerce lifecycle migrations remain pending, running, failed, or version-incomplete; recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /**
     * Independent postimage readback, run by the engine in its own second child.
     *
     * It must observe only, never settle: if the mutation child left the queue
     * unfinished, this readback has to be able to say so rather than quietly
     * finishing the work and reporting success.
     *
     * @return array<string,mixed>
     */
    protected function observe_fresh_postimage_settle_lifecycle_migrations(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle settlement accepts no arguments');
        }
        return self::snapshot();
    }

    /**
     * Canonical projection compared across the engine's two children.
     *
     * The raw snapshot carries queue counts, and 'complete'/'canceled' legitimately
     * move between two independent observations as Action Scheduler trims finished
     * rows. Projecting those would make the comparison flaky in exactly the case it
     * exists to police, so the stable facts are projected instead: the three version
     * markers, WooCommerce's own pending verdict, and one unfinished total that must
     * be zero.
     *
     * @return array<string,bool|int|string>
     */
    protected function project_fresh_postimage_settle_lifecycle_migrations(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['active_version', 'database_update_pending', 'database_version', 'plugin_version', 'queue']
            || !is_string($value['active_version'])
            || !is_string($value['database_version'])
            || !is_string($value['plugin_version'])
            || !is_bool($value['database_update_pending'])
            || !is_array($value['queue'])) {
            throw new \RuntimeException(
                'wprism: WooCommerce fresh-process lifecycle projection is malformed; recovery_required'
            );
        }
        $unfinished = 0;
        foreach (['failed', 'in-progress', 'pending'] as $status) {
            $count = $value['queue'][$status] ?? null;
            if (!is_int($count) || $count < 0) {
                throw new \RuntimeException(
                    'wprism: WooCommerce fresh-process lifecycle projection carries a malformed queue total; recovery_required'
                );
            }
            $unfinished += $count;
        }
        if (!self::settled($value)) {
            throw new \RuntimeException(
                'wprism: WooCommerce lifecycle migrations remain pending, running, failed, or version-incomplete; recovery_required'
            );
        }
        return [
            'active_version' => $value['active_version'],
            'database_update_pending' => $value['database_update_pending'],
            'database_version' => $value['database_version'],
            'plugin_version' => $value['plugin_version'],
            'unfinished' => $unfinished,
        ];
    }

    /**
     * Run exactly the update actions the retired WP-CLI command addressed.
     *
     * This capability declares manifest-provider-fresh-process/v1, so the ENGINE
     * already owns a bounded child here; launching a second one from the adapter
     * was the execution debt this migration removes. Scope is unchanged from the
     * retired `action-scheduler run --hooks=<UPDATE_HOOKS>
     * --group=woocommerce-db-updates --batch-size=25 --batches=0`: the same two
     * hooks, the same group, the same batch size, claimed through Action
     * Scheduler's own store and run through its own runner
     * (packages/action-scheduler/classes/abstracts/ActionScheduler.php:47,68).
     */
    private static function drain_update_queue(): void {
        foreach (['ActionScheduler', 'ActionScheduler_ActionClaim'] as $authority) {
            if (!class_exists($authority, false)) {
                throw new \RuntimeException(
                    "wprism: WooCommerce lifecycle settlement requires the $authority authority the plugin itself loaded"
                );
            }
        }
        $store = \ActionScheduler::store();
        $runner = \ActionScheduler::runner();
        if (!is_object($store) || !method_exists($store, 'stake_claim') || !method_exists($store, 'release_claim')
            || !is_object($runner) || !method_exists($runner, 'process_action')) {
            throw new \RuntimeException(
                'wprism: WooCommerce lifecycle settlement requires the exact Action Scheduler store and runner surface'
            );
        }
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            try {
                $claim = $store->stake_claim(self::BATCH_SIZE, null, self::UPDATE_HOOKS, self::UPDATE_GROUP);
            } catch (\Throwable $failure) {
                // ActionScheduler_DBStore::stake_claim() raises InvalidArgumentException
                // 'The group "%s" does not exist.' when nothing was ever scheduled in it
                // (ActionScheduler_DBStore.php:983). Reaching that means the settlement
                // predicate disagreed with the queue, so refuse rather than guess.
                throw new \RuntimeException(
                    'wprism: WooCommerce migration queue could not be claimed in the bounded fresh process; recovery_required',
                    0,
                    $failure
                );
            }
            $actions = $claim->get_actions();
            if (!is_array($actions)) {
                $store->release_claim($claim);
                throw new \RuntimeException(
                    'wprism: WooCommerce migration queue returned a malformed claim; recovery_required'
                );
            }
            try {
                foreach ($actions as $actionId) {
                    if (!is_scalar($actionId) || preg_match('/^[1-9][0-9]{0,18}$/D', (string) $actionId) !== 1) {
                        throw new \RuntimeException(
                            'wprism: WooCommerce migration queue claimed a malformed action identity; recovery_required'
                        );
                    }
                    // process_action() records its own failures against the action row
                    // rather than throwing, so the post-run settled() check below is what
                    // turns a failed update into a refusal.
                    $runner->process_action((int) $actionId, 'WPrism');
                }
            } finally {
                $store->release_claim($claim);
            }
            if ($actions === []) {
                return;
            }
        }
        throw new \RuntimeException(
            'wprism: WooCommerce migration queue did not drain within its bounded batch budget; recovery_required'
        );
    }

    /** @return array<string,mixed> */
    private static function snapshot(): array {
        global $wpdb;
        if (!defined('WC_VERSION') || !is_string(WC_VERSION)
            || preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:[-+][A-Za-z0-9.-]+)?$/D', WC_VERSION) !== 1
            || !is_object($wpdb) || !is_string($wpdb->prefix ?? null)
            || preg_match('/^[A-Za-z0-9_]{0,48}$/D', $wpdb->prefix) !== 1) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle settlement requires the exact active plugin/database identity');
        }
        $table = $wpdb->prefix . 'actionscheduler_actions';
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle settlement derived an invalid scheduler table identity');
        }
        $placeholders = implode(',', array_fill(0, count(self::UPDATE_HOOKS), '%s'));
        $sql = $wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM `$table` WHERE hook IN ($placeholders) GROUP BY status ORDER BY status",
            ...self::UPDATE_HOOKS
        );
        $rows = ProviderSdk::checked_get_results(
            $sql,
            'WooCommerce lifecycle update-queue projection',
            $wpdb,
            'wprism: WooCommerce lifecycle settlement could not read its bounded queue projection'
        );
        if (count($rows) > 5) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle settlement could not read its bounded queue projection');
        }
        $counts = ['canceled' => 0, 'complete' => 0, 'failed' => 0, 'in-progress' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $status = is_array($row) ? ($row['status'] ?? null) : null;
            $count = is_array($row) ? ($row['n'] ?? null) : null;
            if (!is_string($status) || !array_key_exists($status, $counts)
                || !is_string($count) || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $count) !== 1) {
                throw new \RuntimeException('wprism: WooCommerce lifecycle settlement read a malformed queue projection');
            }
            $counts[$status] = (int) $count;
        }
        $dbVersion = get_option('woocommerce_db_version', null);
        $pluginVersion = get_option('woocommerce_version', null);
        if (!is_string($dbVersion) || strlen($dbVersion) > 64
            || !is_string($pluginVersion) || strlen($pluginVersion) > 64) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle version markers are absent or malformed');
        }
        // woocommerce_db_version is NOT the plugin version. WC_Install::update_db_version()
        // stores max(WC()->version, array_key_last(self::$db_updates)) by version_compare
        // (includes/class-wc-install.php:1080-1087, byte-identical in 11.0.1 and 11.1.0), and
        // 11.1.0 is the first release in the admitted range whose last update key carries a
        // suffix: '11.1.0-1'. version_compare('11.1.0', '11.1.0-1', '>') is false, so a fresh
        // 11.1.0 install records '11.1.0-1' against WC_VERSION '11.1.0'. Comparing those two
        // strings therefore reports "never settled" forever on 11.1.0, with an empty queue and
        // no 'woocommerce-db-updates' group for the runner to address. Ask WooCommerce instead:
        // needs_db_update() is public, byte-identical across both admitted versions, and is the
        // same array_key_last comparison the writer uses (:890-894).
        if (!class_exists('WC_Install', false)) {
            throw new \RuntimeException(
                'wprism: WooCommerce lifecycle settlement requires the WC_Install authority the plugin itself loaded'
            );
        }
        $needsUpdate = \WC_Install::needs_db_update();
        if (!is_bool($needsUpdate)) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle settlement read a malformed database-update verdict');
        }
        return [
            'active_version' => WC_VERSION,
            'database_version' => $dbVersion,
            'database_update_pending' => $needsUpdate,
            'plugin_version' => $pluginVersion,
            'queue' => $counts,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private static function settled(array $snapshot): bool {
        $queue = (array) $snapshot['queue'];
        // database_version stays in the receipt as evidence, but the verdict is
        // WooCommerce's own: see snapshot() for why equality against WC_VERSION is
        // not a settlement test on 11.1.0.
        return $snapshot['database_update_pending'] === false
            && hash_equals((string) $snapshot['active_version'], (string) $snapshot['plugin_version'])
            && (int) ($queue['pending'] ?? -1) === 0
            && (int) ($queue['in-progress'] ?? -1) === 0
            && (int) ($queue['failed'] ?? -1) === 0;
    }
}
