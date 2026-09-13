<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Byte-exact quarantine for runtime machinery that predates the current SDK.
 *
 * The inventory is shared by the authoring validator and the installed
 * runtime. A package cannot gain authority by copying a legacy spelling: the
 * one temporary runtime permission below also requires the exact provider
 * object loaded from the recorded source digest and an engine-selected
 * capability. Migration deletes a row; hashes are never refreshed in place.
 */
final class LegacyRuntimeExecutionDebt {
    public const PROVIDER_MYSQL_NAMED_MUTEX = 'provider-mysql-named-mutex/v1';

    /** @var array<string,array{sha256:string,findings:list<string>,migration:string}> */
    public const ROWS = [
        'adapter-packages/acf/package/runtime/interpreters/acf.php' => [
            'sha256' => 'bb59d5ce09030a0cbcbaaabfe3630bd2cd1f56823e9a4bd9817ee5849496ef85',
            'findings' => ['raw-database-transport'],
            'migration' => 'manifest provider plus ProviderSdk checked reads',
        ],
        'adapter-packages/elementor/package/runtime/providers/elementor-css.php' => [
            'sha256' => 'ede9dc943b95bbd03c1313d7cf251654d0abdf57c6bd3b422f730a6640dcd788',
            'findings' => ['direct-include', 'raw-database-transport', 'wp-cli-child-process'],
            'migration' => 'manifest-provider-fresh-process/v1 plus ProviderSdk checked reads',
        ],
        'adapter-packages/ninja-forms/package/runtime/providers/ninja-forms-form-cache.php' => [
            'sha256' => '7af7ece751c68503d4efb9b9ba7ce3a9a138860b83b5d80dcf7187ffc2717988',
            'findings' => ['direct-include', 'raw-database-transport', 'wp-cli-child-process'],
            'migration' => 'manifest-provider-fresh-process/v1 plus capability-scoped typed DML',
        ],
        'adapter-packages/polylang/package/runtime/providers/polylang-nav-menus.php' => [
            'sha256' => 'af578dfc9a6b39b67af664c2980f00bd305cb7c127cc325c56e671dc693ad6dc',
            'findings' => ['direct-include', 'wp-cli-child-process'],
            'migration' => 'manifest-provider-fresh-process/v1',
        ],
        'adapter-packages/redirection/package/runtime/providers/redirection-state.php' => [
            'sha256' => '02b3e6bd93267bb3f3e20ece8bbf181cca0ba918f58b17397b15548fc01765ed',
            'findings' => ['raw-database-transport'],
            'migration' => 'ProviderSdk checked reads under a manifest-declared database profile',
        ],
        'adapter-packages/the-events-calendar/package/runtime/regenerators/the-events-calendar.php' => [
            'sha256' => '29b145ffaa7006d9daf9e50425153d479207724ede67f36d8ced99dbcd30047a',
            'findings' => ['raw-database-transport', 'transaction-control'],
            'migration' => 'entity-scoped manifest provider plus ProviderDatabaseSession',
        ],
        'adapter-packages/woocommerce/package/runtime/providers/woocommerce-hierarchy-lookups.php' => [
            'sha256' => '0e41fd276142b48bca0275e96936096ec21d167ea10f5061e328c51b7c3c67ff',
            'findings' => ['direct-include', 'direct-self-include', 'wp-cli-child-process'],
            'migration' => 'manifest-provider-fresh-process/v1',
        ],
        'adapter-packages/woocommerce/package/runtime/providers/woocommerce-lifecycle-migrations.php' => [
            'sha256' => 'd27316478d5a7d2ed58efb5a1006c8f899d27ae97f95136f39f59252cb5eb5a1',
            'findings' => ['direct-include', 'raw-database-transport', 'wp-cli-child-process'],
            'migration' => 'manifest-provider-fresh-process/v1 plus ProviderSdk checked reads',
        ],
        'adapter-packages/woocommerce/package/runtime/providers/woocommerce-product-lookups.php' => [
            'sha256' => 'c60571ddc48f4358665b085001573afc78dff2ef30bb49d9f9acd6e76775ec6c',
            'findings' => ['raw-database-transport', 'transaction-control'],
            'migration' => 'capability-scoped ProviderDatabaseSession and typed DML',
        ],
        'adapter-packages/woocommerce/package/runtime/providers/woocommerce-scheduler-settings.php' => [
            'sha256' => 'a64b08d7b7ea192ce2e80705ee5eab860824800251254a5e6d70aaaf3c19efdb',
            'findings' => ['raw-database-transport', 'transaction-control'],
            'migration' => 'ProviderDatabaseSession plus an engine-owned named mutex',
        ],
        'adapter-packages/yoast/package/runtime/providers/yoast-index.php' => [
            'sha256' => 'f69d754f39ab8b14a6d38e159e1f46bea7ed11da8acfb27dedc3110b88fb9417',
            'findings' => ['direct-include', 'raw-database-transport', 'wp-cli-child-process'],
            'migration' => 'manifest-provider-fresh-process/v1 plus ProviderSdk checked reads',
        ],
    ];

    /**
     * The only runtime exception is narrower than the legacy file: one
     * provider, two engine-selected capabilities, and one named-mutex API.
     *
     * @var array<string,array<string,list<string>>>
     */
    private const RUNTIME_PERMISSIONS = [
        'adapter-packages/woocommerce/package/runtime/providers/woocommerce-scheduler-settings.php' => [
            self::PROVIDER_MYSQL_NAMED_MUTEX => [
                'reconcile_analytics_import_schedule',
                'reconcile_stock_notification_retention',
            ],
        ],
    ];

    /**
     * @param array{adapter:string,id:string,sha256:string,capability:string} $identity
     */
    public static function permits_provider(array $identity, string $permission): bool {
        $keys = array_keys($identity);
        sort($keys, SORT_STRING);
        if ($keys !== ['adapter', 'capability', 'id', 'sha256']
            || !is_string($identity['adapter'])
            || !is_string($identity['id'])
            || !is_string($identity['sha256'])
            || !is_string($identity['capability'])
            || preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $identity['adapter']) !== 1
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $identity['id']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $identity['sha256']) !== 1
            || preg_match('/^[a-z0-9_]{1,64}$/D', $identity['capability']) !== 1) {
            return false;
        }
        $path = 'adapter-packages/' . $identity['adapter'] . '/package/runtime/providers/'
            . $identity['id'] . '.php';
        $row = self::ROWS[$path] ?? null;
        $capabilities = self::RUNTIME_PERMISSIONS[$path][$permission] ?? null;
        return is_array($row)
            && is_array($capabilities)
            && hash_equals($row['sha256'], $identity['sha256'])
            && in_array($identity['capability'], $capabilities, true);
    }
}
