<?php
/** Offline product-path proof for WooCommerce's deferred DB-update settlement gate. */
declare(strict_types=1);

namespace {
    $duoRoot = dirname(__DIR__, 4);
    require_once $duoRoot . '/sandbox/tests/lib/check.php';
    require_once $duoRoot . '/sandbox/tests/support/wp_cli_child_process_fake.php';

    define('WC_VERSION', '11.0.1');
    define('ARRAY_A', 'ARRAY_A');

    $GLOBALS['woo_lifecycle_options'] = [];
    $GLOBALS['woo_lifecycle_rows'] = [];
    $GLOBALS['woo_lifecycle_commands'] = [];
    $GLOBALS['woo_lifecycle_result'] = null;
    $GLOBALS['woo_lifecycle_after_command'] = null;

    function get_option(string $name, mixed $default = false): mixed {
        return $GLOBALS['woo_lifecycle_options'][$name] ?? $default;
    }

    final class WP_CLI {
        use \DuoTest\WpCliChildRuntime;

        public static function runcommand(string $command, array $options): mixed {
            $GLOBALS['woo_lifecycle_commands'][] = [$command, $options];
            if (is_callable($GLOBALS['woo_lifecycle_after_command'])) {
                ($GLOBALS['woo_lifecycle_after_command'])();
            }
            return $GLOBALS['woo_lifecycle_result'];
        }
    }

    final class WooLifecycleWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';

        /** @var list<mixed> */
        public array $preparedArgs = [];

        public function prepare(string $query, mixed ...$args): string {
            $this->preparedArgs = $args;
            foreach ($args as $arg) {
                $query = preg_replace('/%s/', "'" . str_replace("'", "''", (string) $arg) . "'", $query, 1) ?? $query;
            }
            return $query;
        }

        /** @return list<array{status:string,n:string}> */
        public function get_results(string $query, string $format): array {
            if ($format !== ARRAY_A
                || !str_contains($query, 'FROM `wp_actionscheduler_actions`')
                || !str_contains($query, 'GROUP BY status ORDER BY status')) {
                throw new RuntimeException('fixture observed a non-associative or unbounded lifecycle queue query');
            }
            $this->last_error = '';
            return $GLOBALS['woo_lifecycle_rows'];
        }
    }
}

namespace Duo {
    final class Policy {}
}

namespace {
    require_once $duoRoot . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once $duoRoot . '/agent/src/Kernel/WpCliChildProcess.php';
    require_once $duoRoot . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-lifecycle-migrations.php';

    use Duo\Providers\WoocommerceLifecycleMigrations;

    /** @return array<string,mixed> */
    function woo_lifecycle_declaration(): array {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/package/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($manifest['providers'] as $provider) {
            if (($provider['id'] ?? null) === 'woocommerce-lifecycle-migrations') {
                return $provider;
            }
        }
        throw new RuntimeException('fixture could not find lifecycle provider declaration');
    }

    function woo_lifecycle_reset(array $rows, string $databaseVersion, string $pluginVersion): void {
        $GLOBALS['woo_lifecycle_rows'] = $rows;
        $GLOBALS['woo_lifecycle_options'] = [
            'woocommerce_db_version' => $databaseVersion,
            'woocommerce_version' => $pluginVersion,
        ];
        $GLOBALS['woo_lifecycle_commands'] = [];
        $GLOBALS['woo_lifecycle_after_command'] = null;
        $GLOBALS['woo_lifecycle_result'] = (object) ['return_code' => 0, 'stdout' => '', 'stderr' => ''];
    }

    /** @return array{status:string,n:string} */
    function woo_lifecycle_row(string $status, int $count): array {
        return ['status' => $status, 'n' => (string) $count];
    }

    $GLOBALS['wpdb'] = new WooLifecycleWpdb();
    $provider = new WoocommerceLifecycleMigrations(woo_lifecycle_declaration());

    woo_lifecycle_reset([woo_lifecycle_row('complete', 4)], WC_VERSION, WC_VERSION);
    $already = $provider->invoke('settle_lifecycle_migrations', []);
    duo_check_same([], $GLOBALS['woo_lifecycle_commands'], 'an already-settled target starts no child process');
    duo_check_same($already['before'], $already['after'], 'the no-op receipt proves the same closed projection twice');
    duo_check_same(true, $already['verified'], 'the no-op settlement receipt is verified');

    woo_lifecycle_reset(
        [woo_lifecycle_row('complete', 3), woo_lifecycle_row('pending', 2)],
        '11.0.0',
        '11.0.0'
    );
    $GLOBALS['woo_lifecycle_after_command'] = static function (): void {
        $GLOBALS['woo_lifecycle_rows'] = [woo_lifecycle_row('complete', 5)];
        $GLOBALS['woo_lifecycle_options']['woocommerce_db_version'] = WC_VERSION;
        $GLOBALS['woo_lifecycle_options']['woocommerce_version'] = WC_VERSION;
    };
    $settled = $provider->invoke('settle_lifecycle_migrations', []);
    duo_check_same(1, count($GLOBALS['woo_lifecycle_commands']), 'an unsettled target starts exactly one bounded fresh child');
    $command = (string) $GLOBALS['woo_lifecycle_commands'][0][0];
    duo_check(
        str_contains($command, 'action-scheduler run')
            && str_contains($command, '--hooks=woocommerce_run_update_callback,woocommerce_update_db_to_current_version')
            && str_contains($command, '--group=woocommerce-db-updates')
            && str_contains($command, '--batch-size=25')
            && str_contains($command, '--batches=0')
            && str_contains($command, '--force'),
        'the child is restricted to WooCommerce DB-update hooks/group and drains every bounded batch'
    );
    duo_check_same(
        ['woocommerce_run_update_callback', 'woocommerce_update_db_to_current_version'],
        $GLOBALS['wpdb']->preparedArgs,
        'both queue projections bind the exact reviewed WooCommerce update hooks'
    );
    duo_check_same(2, $settled['before']['queue']['pending'], 'the receipt preserves the pre-run pending count');
    duo_check_same(0, $settled['after']['queue']['pending'], 'the receipt proves no pending update remains');
    duo_check_same(WC_VERSION, $settled['after']['database_version'], 'the database marker equals the activated code version');
    duo_check_same(true, $settled['verified'], 'the completed migration receipt is verified');

    woo_lifecycle_reset([woo_lifecycle_row('failed', 1)], WC_VERSION, WC_VERSION);
    try {
        $provider->invoke('settle_lifecycle_migrations', []);
        duo_check(false, 'a child that leaves a failed update behind must refuse');
    } catch (RuntimeException $failure) {
        duo_check(
            str_contains($failure->getMessage(), 'remain pending, running, failed, or version-incomplete')
                && str_contains($failure->getMessage(), 'recovery_required'),
            'post-child queue/version incompleteness is an explicit recovery-required refusal'
        );
    }

    woo_lifecycle_reset([woo_lifecycle_row('pending', 1)], '11.0.0', '11.0.0');
    $GLOBALS['woo_lifecycle_result'] = (object) ['return_code' => 17, 'stdout' => 'private', 'stderr' => 'private'];
    try {
        $provider->invoke('settle_lifecycle_migrations', []);
        duo_check(false, 'a failed scheduler child must refuse');
    } catch (RuntimeException $failure) {
        duo_check(
            $failure->getMessage() === 'duo: WooCommerce migration queue did not complete cleanly in the bounded fresh process; recovery_required',
            'child output stays private while the provider returns one safe recovery-required refusal'
        );
    }

    try {
        $provider->invoke('settle_lifecycle_migrations', ['forged' => true]);
        duo_check(false, 'settlement must reject caller-defined provider arguments');
    } catch (RuntimeException $failure) {
        duo_check(
            $failure->getMessage() === 'duo: WooCommerce lifecycle settlement accepts no arguments',
            'the manifest-fixed settlement scope cannot be widened by an artifact argument'
        );
    }

    echo "PASS: WooCommerce lifecycle migrations (13 assertions)\n";
}
