<?php
declare(strict_types=1);

use Duo\TypedTableMaterializer;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

$repoRoot = dirname(__DIR__, 4);
require_once $repoRoot . '/sandbox/tests/lib/check.php';
require_once $repoRoot . '/sandbox/tests/lib/wp_stubs.php';
require_once $repoRoot . '/sandbox/tests/lib/FakeWpdb.php';
require_once $repoRoot . '/agent/src/Apply/TypedTableMaterializer.php';

/**
 * Persistent-cache failure seam backed by the shared WpStore. A false return
 * alone is not failure in WordPress, so sticky mode deliberately leaves the
 * entry present for the engine's independent read-back.
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
 * Faithful PMPro 3.8.x level-meta read: one cache entry per level in the exact
 * group used by get_metadata(), with SQL bytes unserialized at the public API
 * boundary. This is the plugin-owned behavior the engine postcondition must
 * preserve; the adapter no longer reimplements it in a provider.
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

    $values = is_array($cache) && isset($cache[$key]) && is_array($cache[$key])
        ? $cache[$key]
        : [];
    return $single ? ($values[0] ?? '') : $values;
}

/** @return array{FakeWpdb,TypedTableMaterializer,ReflectionMethod} */
function pmpro_fixture(): array {
    $store = WpStore::reset();
    $GLOBALS['pmpro_sticky_cache'] = false;
    $db = FakeWpdb::install();
    $db->seedTable('pmpro_membership_levelmeta', [
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
    ]);

    $materializer = new TypedTableMaterializer(
        static fn(): array => [],
        static fn(): array => [],
        static fn(string $uuid, string $kind): ?int => null,
        static function (): void {},
        static fn(string $table, array $components): int => 0,
        static fn(int $packed): array => [0, 0],
        static fn(array $decl, string $key): bool => true,
        static fn(mixed $value): mixed => is_string($value) ? $value : serialize($value),
        static fn(string|int $key, string $group): bool => wp_cache_delete($key, $group),
        static fn(string $table): array => []
    );

    // setAccessible() has been a no-op since PHP 8.1 and is deprecated in 8.5.
    $run = (new ReflectionClass(TypedTableMaterializer::class))->getMethod('runInvalidation');
    $store->cache['unrelated-group']['unrelated'] = 'keep';
    return [$db, $materializer, $run];
}

$manifestPath = dirname(__DIR__, 2) . '/package/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$disposition = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/package/disposition.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
duo_check(
    ($disposition['status'] ?? null) === 'certified'
        && ($disposition['capabilities']['lifecycle_phases'] ?? null) === ['retire', 'activate', 'verify']
        && in_array('deploy', $disposition['capabilities']['operations'] ?? [], true)
        && in_array('render-api', $disposition['capabilities']['operations'] ?? [], true),
    'the reviewed disposition certifies the exact lifecycle and native product paths'
);
$unsupported = array_fill_keys(array_column($disposition['unsupported'] ?? [], 'surface'), true);
duo_check(
    isset($unsupported['runtime.action-scheduler-deactivation-cleanup']),
    'the pinned upstream deactivation defect remains an explicit unsupported runtime surface'
);

$readiness = json_decode(
    (string) file_get_contents($repoRoot . '/sandbox/conformance/production-readiness.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$readiness = $readiness['adapters']['paid-memberships-pro'] ?? [];
duo_check(
    ($readiness['readiness'] ?? null) === 'ready'
        && ($readiness['gaps'] ?? null) === []
        && ($readiness['blocked'] ?? null) === []
        && count($readiness['covered'] ?? []) === 12,
    'the readiness ledger closes all twelve scenario families without hiding a gap'
);

$matrix = (string) file_get_contents($repoRoot . '/sandbox/tests/certify/certify_version_matrix.sh');
duo_check(
    str_contains($matrix, 'wp_delete_user((int) $user_id, (int) $admin->ID)'),
    'the exact matrix removes retained conformance users before each PMPro boundary'
);
$pmproMatrixFile = (string) file_get_contents(dirname(__DIR__) . '/certify/version-matrix.sh');
duo_check(
    preg_match('/check_pmpro_content\(\) \{.*?wp_conf1\(\).*?wp_conf2\(\)/s', $pmproMatrixFile) === 1,
    'the exact matrix binds both PMPro conformance environments to its dedicated pair'
);
duo_check(
    preg_match('/if \[ "\$VMATRIX_MANIFEST" = paid-memberships-pro \]; then.*?wp2 duo apply --repo=\/siterepo --adopt-by-slug=terms,posts.*?2>&1 \| tee "\$VMATRIX_APPLY_LOG"/s', $matrix) === 1,
    'the exact matrix retains PMPro apply output for the declared cache postcondition boundary'
);

duo_check_same(
    ['min' => '3.8.2', 'max' => '3.8.4'],
    $manifest['version_range'] ?? null,
    'the admitted interval is exactly the two schema-audited PMPro tags'
);
duo_check_same(
    ['invalidate-vocabulary/v1', 'spec-window/v1'],
    $manifest['engine_features'] ?? null,
    'the adapter negotiates the engine-owned invalidation vocabulary'
);
duo_check_same(3, $manifest['spec_version'] ?? null, 'the feature-gated declaration is carried by spec v3');
duo_check(
    !isset($manifest['providers']) && !isset($manifest['actions']),
    'PMPro ships no executable provider or action after the engine absorbs cache invalidation'
);
duo_check_same(
    [['cache_group' => 'pmpro_membership_level_meta', 'cache_key' => '{id}']],
    $manifest['tables']['pmpro_membership_levels']['invalidate'] ?? null,
    'each materialized membership level declares the exact PMPro cache entry to drop'
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
    duo_check_same($mode, $decl['identity']['mode'] ?? 'mapped', "$table has its reviewed production identity mode");
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

// The regression fails against the old product: that manifest had no
// invalidate declaration and reached this behavior only through a 233-line
// plugin-specific provider. The engine now owns the primitive while PMPro's
// native getter remains the independent observation boundary.
[, $materializer, $run] = pmpro_fixture();
$invalidation = $manifest['tables']['pmpro_membership_levels']['invalidate'][0];
wp_cache_set(7, ['membership_account_message' => ['stale-private-value']], 'pmpro_membership_level_meta');
$run->invoke($materializer, $invalidation, 7);
duo_check_same(
    "Members only — 東京\n" . str_repeat('x', 131072),
    get_pmpro_membership_level_meta(7, 'membership_account_message', true),
    'the declared engine invalidation makes PMPro rebuild large UTF-8 metadata from committed rows'
);
duo_check_same('keep', wp_cache_get('unrelated', 'unrelated-group'), 'unrelated cache groups are never flushed');
duo_check(
    !in_array('flush', array_column(WpStore::instance()->cacheEvents, 'op'), true),
    'the generic primitive never falls back to a site-wide cache flush'
);

$run->invoke($materializer, $invalidation, 7);
duo_check_same(
    "Members only — 東京\n" . str_repeat('x', 131072),
    get_pmpro_membership_level_meta(7, 'membership_account_message', true),
    'an immediate invalidation retry is idempotent and still serves the native fresh value'
);

[, $stickyMaterializer, $stickyRun] = pmpro_fixture();
wp_cache_set(7, ['membership_account_message' => ['stale']], 'pmpro_membership_level_meta');
$GLOBALS['pmpro_sticky_cache'] = true;
duo_check_throws(
    static fn() => $stickyRun->invoke($stickyMaterializer, $invalidation, 7),
    RuntimeException::class,
    'a persistent cache that refuses deletion blocks the generic engine postcondition',
    "left '7' cached in group 'pmpro_membership_level_meta'"
);

duo_check_summary('Paid Memberships Pro production readiness');
