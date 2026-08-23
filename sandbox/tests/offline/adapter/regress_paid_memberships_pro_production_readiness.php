<?php
declare(strict_types=1);

namespace Duo {
    final class Policy {
    }

    final class Providers {
        public const SCOPED_OPERATION_FORMAT = 'duo.scoped-operation.v1';
    }
}

namespace {
    use Duo\Policy;
    use Duo\Providers\PaidMembershipsProCache;
    use DuoTest\FakeWpdb;
    use DuoTest\WpStore;

    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../lib/wp_stubs.php';
    require_once __DIR__ . '/../../lib/FakeWpdb.php';
    require_once __DIR__ . '/../../../../manifests/providers/paid-memberships-pro-cache.php';

    /** Mutable refusal seam; wp_stubs.php deliberately defaults to false. */
    function is_multisite(): bool {
        return (bool) ($GLOBALS['pmpro_multisite'] ?? false);
    }

    /**
     * Persistent-cache failure seam backed by the shared WpStore. A false
     * return alone is not failure in WordPress, so sticky mode deliberately
     * leaves the entry present for the provider's independent read-back.
     */
    function wp_cache_delete(string|int $key, string $group = ''): bool {
        $store = WpStore::instance();
        $group = $group === '' ? 'default' : $group;
        $key = (string) $key;
        $store->cacheEvents[] = ['op' => 'delete', 'group' => $group, 'key' => $key];
        if (($GLOBALS['pmpro_sticky_cache'] ?? false) === true) {
            return false;
        }
        if (!isset($store->cache[$group]) || !array_key_exists($key, $store->cache[$group])) {
            return false;
        }
        unset($store->cache[$group][$key]);
        return true;
    }

    /**
     * Faithful PMPro 3.8.x level-meta read: one cache entry per level in the
     * exact group used by get_metadata(), with SQL bytes unserialized only at
     * the public API boundary.
     */
    function get_pmpro_membership_level_meta(int $levelId, string $key, bool $single = false): mixed {
        $found = false;
        $cache = wp_cache_get($levelId, 'pmpro_membership_level_meta', false, $found);
        if (!$found) {
            global $wpdb;
            $cache = [];
            foreach ($wpdb->rows('pmpro_membership_levelmeta') as $row) {
                if ((int) ($row['pmpro_membership_level_id'] ?? 0) !== $levelId) {
                    continue;
                }
                $metaKey = (string) ($row['meta_key'] ?? '');
                $cache[$metaKey][] = maybe_unserialize((string) ($row['meta_value'] ?? ''));
            }
            wp_cache_set($levelId, $cache, 'pmpro_membership_level_meta');
        }

        $slot = $levelId . ':' . $key;
        if (array_key_exists($slot, $GLOBALS['pmpro_api_overrides'] ?? [])) {
            return $GLOBALS['pmpro_api_overrides'][$slot];
        }
        $values = is_array($cache) && isset($cache[$key]) && is_array($cache[$key])
            ? $cache[$key]
            : [];
        return $single ? ($values[0] ?? '') : $values;
    }

    /** @return array{FakeWpdb,PaidMembershipsProCache} */
    function pmpro_fixture(?array $levels = null, ?array $meta = null): array {
        WpStore::reset();
        $GLOBALS['pmpro_multisite'] = false;
        $GLOBALS['pmpro_sticky_cache'] = false;
        $GLOBALS['pmpro_api_overrides'] = [];
        $levels ??= [
            ['id' => '7', 'name' => 'Builder'],
            ['id' => '23', 'name' => 'Agency'],
        ];
        $meta ??= [
            [
                'meta_id' => '31',
                'pmpro_membership_level_id' => '7',
                'meta_key' => 'confirmation_in_email',
                'meta_value' => '1',
            ],
            [
                'meta_id' => '32',
                'pmpro_membership_level_id' => '7',
                'meta_key' => 'membership_account_message',
                'meta_value' => "Members only — 東京\n" . str_repeat('x', 131072),
            ],
            [
                'meta_id' => '33',
                'pmpro_membership_level_id' => '23',
                'meta_key' => 'enable_avatars',
                'meta_value' => '1',
            ],
            [
                'meta_id' => '34',
                'pmpro_membership_level_id' => '23',
                'meta_key' => 'membership_account_message',
                'meta_value' => serialize(['tier' => 'agency', 'enabled' => true]),
            ],
        ];

        $db = FakeWpdb::install();
        $db->seedTable('pmpro_membership_levels', $levels)
            ->setColumns('pmpro_membership_levels', ['id' => 'bigint unsigned', 'name' => 'varchar(255)'])
            ->seedTable('pmpro_membership_levelmeta', $meta)
            ->setColumns('pmpro_membership_levelmeta', [
                'meta_id' => 'bigint unsigned',
                'pmpro_membership_level_id' => 'bigint unsigned',
                'meta_key' => 'varchar(255)',
                'meta_value' => 'longtext',
            ]);
        return [$db, new PaidMembershipsProCache(new Policy())];
    }

    $manifestPath = __DIR__ . '/../../../../manifests/paid-memberships-pro.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $matrix = (string) file_get_contents(__DIR__ . '/../../certify/certify_version_matrix.sh');
    duo_check(
        str_contains($matrix, 'wp_delete_user((int) $user_id, (int) $admin->ID)'),
        'the exact matrix removes retained conformance users before each PMPro boundary'
    );
    duo_check(
        preg_match('/check_pmpro_content\(\) \{.*?wp_conf1\(\).*?wp_conf2\(\)/s', $matrix) === 1,
        'the exact matrix binds both PMPro conformance environments to its dedicated pair'
    );
    duo_check(
        preg_match('/if \[ "\$VMATRIX_MANIFEST" = paid-memberships-pro \]; then.*?wp2 duo apply --repo=\/siterepo --adopt-by-slug=terms,posts.*?2>&1 \| tee "\$VMATRIX_APPLY_LOG"/s', $matrix) === 1,
        'the exact matrix retains PMPro provider receipts emitted on stderr'
    );
    duo_check_same(
        ['min' => '3.8.2', 'max' => '3.8.4'],
        $manifest['version_range'] ?? null,
        'the admitted interval is exactly the two schema-audited PMPro tags'
    );
    duo_check_same(
        [[
            'kind' => 'provider',
            'provider' => 'paid-memberships-pro-cache',
            'capability' => 'clear_level_meta_caches',
            'args' => [],
        ]],
        $manifest['actions'] ?? null,
        'the manifest invokes the narrowly-scoped native cache verifier'
    );
    duo_check_same(
        [['match' => '^pmpro_']],
        $manifest['option_namespaces'] ?? null,
        'the complete PMPro option namespace is discoverable so an add-on or future core key refuses loudly'
    );

    $expectedModes = [
        'pmpro_discount_codes' => 'natural_key',
        'pmpro_discount_codes_levels' => 'composite_ref',
        'pmpro_groups' => 'mapped',
        'pmpro_membership_levels' => 'mapped',
        'pmpro_membership_levels_groups' => 'mapped',
        'pmpro_memberships_categories' => 'composite_ref',
        'pmpro_memberships_pages' => 'composite_ref',
    ];
    foreach ($expectedModes as $table => $mode) {
        $decl = $manifest['tables'][$table] ?? [];
        $actual = (string) ($decl['identity']['mode'] ?? 'mapped');
        duo_check_same($mode, $actual, "$table has its reviewed production identity mode");
        duo_check_same('authored_snapshot', $decl['class'] ?? null, "$table is a real typed snapshot declaration");
    }
    duo_check_same(
        [
            'table:pmpro_discount_codes_levels',
            'table:pmpro_membership_levels_groups',
            'table:pmpro_memberships_categories',
            'table:pmpro_memberships_pages',
        ],
        array_keys($manifest['deletions'] ?? []),
        'deletion authority is limited to pure authored relationship rows'
    );
    duo_check_same(
        'runtime',
        $manifest['tables']['pmpro_membership_levelmeta']['default_class'] ?? null,
        'unknown core or add-on level metadata cannot inherit authored ownership'
    );
    duo_check_same(
        [
            'confirmation_in_email',
            'enable_avatars',
            'membership_account_message',
            'stripe_product_id',
            'stripe_product_id_sandbox',
        ],
        $manifest['tables']['pmpro_membership_levelmeta']['keyspace']['keys'] ?? null,
        'the free-plugin levelmeta keyspace is closed and exact'
    );
    $tableClasses = array_map(
        static fn(array $decl): string => (string) ($decl['class'] ?? ''),
        $manifest['tables'] ?? []
    );
    duo_check(
        !in_array('authored_typed_snapshot_post_v1', $tableClasses, true),
        'no intent-only table marker remains hidden in the certified contract'
    );

    [$db, $provider] = pmpro_fixture();
    wp_cache_set(7, ['membership_account_message' => ['stale-private-value']], 'pmpro_membership_level_meta');
    wp_cache_set('unrelated', 'keep', 'unrelated-group');
    $receipt = $provider->invoke('clear_level_meta_caches', []);
    duo_check_same(true, $receipt['verified'] ?? null, 'the provider issues a verified receipt');
    duo_check_same([7, 23], $receipt['before']['level_ids'] ?? null, 'level ids are normalized and sorted');
    duo_check_same([7], $receipt['before']['cached_level_ids'] ?? null, 'stale persistent cache inventory is observed before repair');
    duo_check_same([7, 23], $receipt['after']['cleared_level_ids'] ?? null, 'every current level cache is invalidated');
    duo_check_same([7, 23], $receipt['after']['refreshed_cached_level_ids'] ?? null, 'native verification repopulates only fresh level caches');
    duo_check_same(4, $receipt['after']['meta_row_count'] ?? null, 'all supported authored meta rows enter verification');
    duo_check_same(
        $receipt['after']['database_hash'] ?? null,
        $receipt['after']['api_hash'] ?? null,
        'fresh PMPro API bytes agree with an independent database projection'
    );
    duo_check_same(
        "Members only — 東京\n" . str_repeat('x', 131072),
        get_pmpro_membership_level_meta(7, 'membership_account_message', true),
        'large UTF-8 metadata survives native cache reconstruction byte-for-byte'
    );
    duo_check_same(
        ['tier' => 'agency', 'enabled' => true],
        get_pmpro_membership_level_meta(23, 'membership_account_message', true),
        'serialized plain data is verified through PMPro serialization semantics'
    );
    duo_check_same('keep', wp_cache_get('unrelated', 'unrelated-group'), 'unrelated cache groups are never flushed');
    duo_check(
        !in_array('flush', array_column(WpStore::instance()->cacheEvents, 'op'), true),
        'repair never falls back to a site-wide cache flush'
    );

    $retry = $provider->invoke('clear_level_meta_caches', []);
    duo_check_same(
        $receipt['after']['database_hash'] ?? null,
        $retry['after']['database_hash'] ?? null,
        'an immediate retry is idempotent over the same committed projection'
    );
    $operation = ['format' => 'duo.scoped-operation.v1', 'session_id' => 'pmpro-retry'];
    $scoped = $provider->invoke_scoped('clear_level_meta_caches', [], $operation);
    duo_check_same($operation, $scoped['operation'] ?? null, 'scoped invocation preserves the engine operation envelope');
    $reconciled = $provider->reconcile_scoped('clear_level_meta_caches', [], $operation);
    duo_check_same(true, $reconciled['verified'] ?? null, 'recovery reconciliation reruns verification instead of trusting a prior receipt');
    duo_check_throws(
        static fn() => $provider->invoke('flush_everything', []),
        RuntimeException::class,
        'unknown provider capabilities refuse loudly',
        'does not implement capability'
    );

    [, $stickyProvider] = pmpro_fixture();
    wp_cache_set(7, ['membership_account_message' => ['stale']], 'pmpro_membership_level_meta');
    $GLOBALS['pmpro_sticky_cache'] = true;
    duo_check_throws(
        static fn() => $stickyProvider->invoke('clear_level_meta_caches', []),
        RuntimeException::class,
        'a persistent cache that refuses deletion blocks the receipt',
        'left cached membership level id(s): 7'
    );

    [$failedDb, $failedProvider] = pmpro_fixture();
    $failedDb->failNextQuery('credential sk_live_do_not_echo', 'SELECT id');
    try {
        $failedProvider->invoke('clear_level_meta_caches', []);
        duo_check(false, 'inventory database failure refuses without a false receipt');
    } catch (RuntimeException $e) {
        duo_check(str_contains($e->getMessage(), 'inventory query failed'), 'inventory database failure refuses without a false receipt');
        duo_check(!str_contains($e->getMessage(), 'sk_live_do_not_echo'), 'driver secrets are redacted from provider failures');
    }

    [, $invalidIdProvider] = pmpro_fixture([['id' => '01', 'name' => 'Malformed']], []);
    duo_check_throws(
        static fn() => $invalidIdProvider->invoke('clear_level_meta_caches', []),
        RuntimeException::class,
        'non-canonical membership ids refuse before cache mutation',
        "invalid id '01'"
    );

    $duplicateMeta = [
        ['meta_id' => '1', 'pmpro_membership_level_id' => '7', 'meta_key' => 'enable_avatars', 'meta_value' => '1'],
        ['meta_id' => '2', 'pmpro_membership_level_id' => '7', 'meta_key' => 'enable_avatars', 'meta_value' => '0'],
    ];
    [, $duplicateProvider] = pmpro_fixture([['id' => '7', 'name' => 'Duplicate']], $duplicateMeta);
    duo_check_throws(
        static fn() => $duplicateProvider->invoke('clear_level_meta_caches', []),
        RuntimeException::class,
        'duplicate authored levelmeta rows block an ambiguous native projection',
        'has multiple rows for level 7'
    );

    [, $divergentProvider] = pmpro_fixture();
    $GLOBALS['pmpro_api_overrides']['7:confirmation_in_email'] = '0';
    duo_check_throws(
        static fn() => $divergentProvider->invoke('clear_level_meta_caches', []),
        RuntimeException::class,
        'a native API/database disagreement blocks convergence',
        'did not converge'
    );

    [, $multisiteProvider] = pmpro_fixture();
    $GLOBALS['pmpro_multisite'] = true;
    duo_check_throws(
        static fn() => $multisiteProvider->invoke('clear_level_meta_caches', []),
        RuntimeException::class,
        'the provider independently refuses multisite table ambiguity',
        'single-site tables only'
    );

    [, $emptyProvider] = pmpro_fixture([], []);
    $empty = $emptyProvider->invoke('clear_level_meta_caches', []);
    duo_check_same([], $empty['after']['level_ids'] ?? null, 'an empty configured site converges without invented entities');
    duo_check_same(0, $empty['after']['meta_row_count'] ?? null, 'an empty configured site has an honest zero-row receipt');

    duo_check_summary('Paid Memberships Pro production readiness');
}
