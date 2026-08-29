<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\WpCliChildProcess;

if (!class_exists(WpCliChildProcess::class, false)) {
    $wprismLayoutRoot = dirname(__DIR__, 5);
    $wprismAgentRoot = is_dir($wprismLayoutRoot . '/agent/src')
        ? $wprismLayoutRoot . '/agent'
        : (basename($wprismLayoutRoot) === 'agent' && is_dir($wprismLayoutRoot . '/src') ? $wprismLayoutRoot : null);
    if ($wprismAgentRoot === null) {
        throw new \RuntimeException('wprism: WooCommerce lifecycle provider cannot resolve the explicit source or embedded agent layout');
    }
    require_once $wprismAgentRoot . '/src/Kernel/WpCliChildProcess.php';
    unset($wprismLayoutRoot, $wprismAgentRoot);
}

/** Bounded completion gate for WooCommerce's Action Scheduler DB updates. */
final class WoocommerceLifecycleMigrations extends ManifestProviderRuntime {
    private const UPDATE_HOOKS = [
        'woocommerce_run_update_callback',
        'woocommerce_update_db_to_current_version',
    ];

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_settle_lifecycle_migrations(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: WooCommerce lifecycle settlement accepts no arguments');
        }
        $before = self::snapshot();
        if (!self::settled($before)) {
            try {
                $result = WpCliChildProcess::capture(
                    'action-scheduler run --hooks=' . implode(',', self::UPDATE_HOOKS)
                        . ' --group=woocommerce-db-updates --batch-size=25 --batches=0 --force',
                    570,
                    786432,
                    262144
                );
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    'wprism: WooCommerce migration queue could not be run in a bounded fresh process; recovery_required',
                    0,
                    $failure
                );
            }
            if ($result['return_code'] !== 0 || trim($result['stderr']) !== '') {
                throw new \RuntimeException(
                    'wprism: WooCommerce migration queue did not complete cleanly in the bounded fresh process; recovery_required'
                );
            }
        }
        $after = self::snapshot();
        if (!self::settled($after)) {
            throw new \RuntimeException(
                'wprism: WooCommerce lifecycle migrations remain pending, running, failed, or version-incomplete; recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
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
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '' || count($rows) > 5) {
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
        return [
            'active_version' => WC_VERSION,
            'database_version' => $dbVersion,
            'plugin_version' => $pluginVersion,
            'queue' => $counts,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private static function settled(array $snapshot): bool {
        $queue = (array) $snapshot['queue'];
        return hash_equals((string) $snapshot['active_version'], (string) $snapshot['database_version'])
            && hash_equals((string) $snapshot['active_version'], (string) $snapshot['plugin_version'])
            && (int) ($queue['pending'] ?? -1) === 0
            && (int) ($queue['in-progress'] ?? -1) === 0
            && (int) ($queue['failed'] ?? -1) === 0;
    }
}
