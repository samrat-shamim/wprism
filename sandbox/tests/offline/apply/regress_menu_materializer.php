<?php
/**
 * Offline regression for MenuMaterializer (DUO-3347 slice 4: the menu entity
 * materializer extracted from Apply.php). Deliberately narrow: the full
 * behavioral proof of finalize_menu()/assign_locations() -- exact serialized
 * merge semantics, scalar/object rejection, menu-item field/meta
 * reconciliation -- already lives in
 * sandbox/tests/offline/code-half/regress_lifecycle_options_snapshot.php, a Reflection-based
 * test against Apply's own facade with a complete $wpdb stub (confirmed
 * still green, unchanged, through this extraction), and in this project's
 * live conformance sweeps (every fixture with a menu exercises
 * finalize_menu() end to end through a real WordPress + MySQL target). This
 * file does not re-implement or re-assert that behavior -- doing so from a
 * hand-copied twin of the merge logic would only add a second copy that
 * could silently drift from the real one, exactly the anti-pattern
 * regress_conflict_view.php was fixed to stop doing earlier in this same
 * decomposition effort. It proves the one thing genuinely new here instead:
 * MenuMaterializer is a real, directly constructible, standalone public API.
 */
declare(strict_types=1);

/** @var array<string,object> exact runtime taxonomy registrations for one fixture pass */
$GLOBALS['duo_menu_materializer_taxonomies'] = [];
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object|false {
        return $GLOBALS['duo_menu_materializer_taxonomies'][$taxonomy] ?? false;
    }
}

/**
 * Each extracted materializer must be loadable without relying on duo.php's
 * bootstrap order.  Run the probes in fresh PHP processes so the classes
 * loaded by this test's own fixture setup cannot mask a missing require_once.
 */
$standaloneProbes = [
    [
        'label' => 'ApplyFieldMaterializer self-requires Policy and Tokens',
        'file' => __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php',
        'classes' => ['Duo\\Policy', 'Duo\\Tokens'],
    ],
    [
        'label' => 'MenuMaterializer self-requires its constructor dependencies',
        'file' => __DIR__ . '/../../../../agent/src/Apply/MenuMaterializer.php',
        'classes' => [
            'Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer', 'Duo\\RelationshipMaterializer',
        ],
    ],
];
$standaloneFailures = [];
$standaloneResults = [];
foreach ($standaloneProbes as $probe) {
    $classLiterals = implode(', ', array_map(
        static fn(string $class): string => var_export($class, true),
        $probe['classes']
    ));
    $code = 'require_once ' . var_export($probe['file'], true) . ';'
        . 'foreach ([' . $classLiterals . '] as $class) {'
        . ' if (!class_exists($class, false)) { fwrite(STDERR, "missing:" . $class . "\\n"); exit(1); }'
        . '}';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        $standaloneResults[$probe['label']] = false;
        $standaloneFailures[] = $probe['label'] . ' (could not start PHP subprocess)';
        continue;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $standaloneResults[$probe['label']] = $exitCode === 0;
    if ($exitCode !== 0) {
        $standaloneFailures[] = $probe['label'] . ' (exit ' . $exitCode . ': ' . trim($stderr . $stdout) . ')';
    }
}

$standaloneCheck = static function (bool $ok, string $message) use (&$standaloneFailures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $standaloneFailures[] = $message;
    }
};
foreach ($standaloneProbes as $probe) {
    $standaloneCheck(
        $standaloneResults[$probe['label']] ?? false,
        $probe['label']
    );
}
if ($standaloneFailures) {
    echo "\n" . count($standaloneFailures) . " standalone-load failure(s):\n";
    foreach ($standaloneFailures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/LockingFakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Uuid.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/MenuMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\MenuMaterializer;
use Duo\Policy;
use Duo\Tokens;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// newInstanceWithoutConstructor(): this is a wiring/shape test, not a
// behavioral one (see the file docblock) -- a real Tokens needs a live
// WordPress runtime (untrailingslashit(), site options) to construct, which
// this offline suite deliberately does not stand up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
$menuMaterializer = new MenuMaterializer($policy, $tokens, $fieldMaterializer);

$check($menuMaterializer instanceof MenuMaterializer, 'MenuMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer)');

$finalizeMenu = new ReflectionMethod(MenuMaterializer::class, 'finalize_menu');
$assignLocations = new ReflectionMethod(MenuMaterializer::class, 'assign_locations');
$check($finalizeMenu->isPublic(), 'finalize_menu() is public on MenuMaterializer (was private on Apply)');
$check($assignLocations->isPublic(), 'assign_locations() is public on MenuMaterializer (was private on Apply)');
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $assignLocations->getParameters()) === ['menuTermId', 'locations'],
    'assign_locations() keeps its exact original parameter names and order'
);
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $finalizeMenu->getParameters()) === ['front'],
    'finalize_menu() keeps its exact original parameter name'
);

// The constructor takes exactly these three collaborators, in this order --
// a narrow, explicit data contract rather than a whole Apply instance.
$constructorParams = (new ReflectionClass(MenuMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    'constructor depends on exactly Policy, Tokens, and ApplyFieldMaterializer -- no Apply instance'
);

// Core dirty-target conformance found that an explicitly adopted menu kept a
// target-only custom item because the item had no _duo_uuid and the cleanup
// projection indexed UUID-bearing rows only. Exercise the real private
// projection used by finalize_menu(): every physical id is retained, while
// only UUID-bearing rows enter the canonical lookup.
$indexEnvironmentItems = new ReflectionMethod(MenuMaterializer::class, 'index_environment_items');
$obsoleteEnvironmentItems = new ReflectionMethod(MenuMaterializer::class, 'obsolete_environment_items');
$environment = $indexEnvironmentItems->invoke($menuMaterializer, [
    ['ID' => '11', 'uuid' => '11111111-1111-4111-8111-111111111111'],
    ['ID' => '12', 'uuid' => '22222222-2222-4222-8222-222222222222'],
    ['ID' => '13', 'uuid' => null],
    ['ID' => '14', 'uuid' => ''],
], 'main');
$check(
    $environment === [
        'by_uuid' => [
            '11111111-1111-4111-8111-111111111111' => 11,
            '22222222-2222-4222-8222-222222222222' => 12,
        ],
        'by_id' => [11 => '11111111-1111-4111-8111-111111111111', 12 => '22222222-2222-4222-8222-222222222222', 13 => null, 14 => null],
    ],
    'menu observation retains sidecarless target items by physical id'
);
$obsolete = $obsoleteEnvironmentItems->invoke($menuMaterializer, $environment['by_id'], [
    '11111111-1111-4111-8111-111111111111' => 11,
]);
$check(
    $obsolete === [12 => '22222222-2222-4222-8222-222222222222', 13 => null, 14 => null],
    'menu-scoped cleanup removes stale canonical and sidecarless target items while keeping the desired item'
);

foreach ([
    'one identity on multiple target items' => [
        ['ID' => '21', 'uuid' => '33333333-3333-4333-8333-333333333333'],
        ['ID' => '22', 'uuid' => '33333333-3333-4333-8333-333333333333'],
    ],
    'contradictory identities on one target item' => [
        ['ID' => '23', 'uuid' => '44444444-4444-4444-8444-444444444444'],
        ['ID' => '23', 'uuid' => '55555555-5555-4555-8555-555555555555'],
    ],
] as $label => $rows) {
    try {
        $indexEnvironmentItems->invoke($menuMaterializer, $rows, 'main');
        $check(false, "$label refuses before choosing an owner");
    } catch (RuntimeException $failure) {
        $check(
            str_contains($failure->getMessage(), 'duo: menu main:'),
            "$label refuses before choosing an owner"
        );
    }
}

// The extracted seam now owns physical concurrency/identity safety, so drive
// finalize_menu() itself through the shared row-backed wpdb plus the shared
// explicit locking-fact adapter. A stale ledger row absent from the current
// menu must be validated and either attached as a proven orphan or refused;
// it can never be updated silently while desired membership stays absent.
$menuUuid = '11111111-1111-4111-8111-111111111111';
$itemUuid = '22222222-2222-4222-8222-222222222222';
$front = [
    'uuid' => $menuUuid,
    'name' => 'Primary menu',
    'slug' => 'primary-menu',
    'locations' => [],
    'items' => [[
        'uuid' => $itemUuid,
        'title' => 'Portable link',
        'description' => '',
        'attr_title' => '',
        'position' => 1,
        'type' => 'custom',
        'ref' => 'https://source.test/portable',
        'object' => 'custom',
        'target' => '',
        'classes' => [],
        'xfn' => '',
        'parent' => null,
        'meta' => ['_menu_badges' => ['first', 'second']],
    ]],
];
$runtimePolicy = new Policy();
$runtimePolicy->site = ['policy' => [
    'post_types' => [],
    'taxonomies' => [],
    'post_meta' => [
        '_menu_badges' => [
            'class' => 'authored',
            'repeated_rows' => [
                'cardinality' => 'one_or_more',
                'duplicates' => 'forbid',
                'order' => 'preserve',
            ],
        ],
    ],
    'menu_fields' => ['locations' => ['class' => 'derived']],
]];
$runtimePolicy->manifests = [[
    'name' => 'polylang-fixture',
    'taxonomies' => [
        'term_language' => ['object_keyspace' => 'term'],
        'term_translations' => ['object_keyspace' => 'term'],
    ],
]];
$runtimeTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');

$menuDb = static function (
    array $posts,
    array $postmeta,
    array $relationships,
    bool $mapItem = true,
    array $extraTerms = [],
    array $extraTaxonomies = []
) use ($menuUuid, $itemUuid): \DuoTest\LockingFakeWpdb {
    $inner = (new \DuoTest\FakeWpdb())->enableRelationshipOwnershipJoin();
    $db = new \DuoTest\LockingFakeWpdb($inner);
    $db->setColumns('terms', ['term_id' => 'bigint unsigned', 'name' => 'varchar(200)', 'slug' => 'varchar(200)']);
    $db->setColumns('term_taxonomy', [
        'term_taxonomy_id' => 'bigint unsigned', 'term_id' => 'bigint unsigned',
        'taxonomy' => 'varchar(32)', 'description' => 'longtext', 'parent' => 'bigint unsigned', 'count' => 'bigint',
    ]);
    $db->setColumns('term_relationships', [
        'object_id' => 'bigint unsigned', 'term_taxonomy_id' => 'bigint unsigned', 'term_order' => 'int',
    ]);
    $db->setColumns('posts', ['ID' => 'bigint unsigned', 'post_type' => 'varchar(20)']);
    $db->setColumns('postmeta', [
        'meta_id' => 'bigint unsigned', 'post_id' => 'bigint unsigned',
        'meta_key' => 'varchar(255)', 'meta_value' => 'longtext',
    ]);
    $db->setColumns('duo_map', [
        'uuid' => 'char(36)', 'entity_type' => 'varchar(64)',
        'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
    ]);
    $db->seedTable('terms', array_merge([[
        'term_id' => 10, 'name' => 'Old menu', 'slug' => 'old-menu',
    ]], $extraTerms));
    $db->seedTable('term_taxonomy', array_merge([[
        'term_taxonomy_id' => 20, 'term_id' => 10, 'taxonomy' => 'nav_menu',
        'description' => '', 'parent' => 0, 'count' => count($relationships),
    ]], $extraTaxonomies));
    $db->seedTable('term_relationships', $relationships);
    $db->seedTable('posts', $posts);
    $db->seedTable('postmeta', $postmeta);
    $map = [
        ['uuid' => $menuUuid, 'entity_type' => 'menu', 'id_kind' => 'term', 'local_id' => 10],
        ['uuid' => $menuUuid, 'entity_type' => 'menu', 'id_kind' => 'term_taxonomy', 'local_id' => 20],
    ];
    if ($mapItem) {
        $map[] = ['uuid' => $itemUuid, 'entity_type' => 'menu_item', 'id_kind' => 'post', 'local_id' => 30];
    }
    $db->seedTable('duo_map', $map)
        ->setUniqueKey('duo_map', ['uuid', 'id_kind'])
        ->setUniqueKey('duo_map', ['id_kind', 'local_id']);
    foreach ([$db->terms, $db->term_taxonomy, $db->term_relationships, $db->posts, $db->postmeta] as $table) {
        $db->addInnoDbTable($table);
    }
    $db->addIndex($db->terms, 'PRIMARY', 'term_id', true)
        ->addIndex($db->term_taxonomy, 'PRIMARY', 'term_taxonomy_id', true)
        ->addIndex($db->term_relationships, 'term_taxonomy_id', 'term_taxonomy_id')
        ->addIndex($db->term_relationships, 'PRIMARY', 'object_id')
        ->addIndex($db->posts, 'PRIMARY', 'ID', true)
        ->addIndex($db->postmeta, 'post_id', 'post_id');
    return $db;
};

$runMenu = static function (\DuoTest\LockingFakeWpdb $db, array $taxonomies = []) use (
    $runtimePolicy,
    $runtimeTokens,
    $front
): array {
    $GLOBALS['wpdb'] = $db;
    $GLOBALS['duo_menu_materializer_taxonomies'] = $taxonomies + [
        'nav_menu' => (object) ['object_type' => ['nav_menu_item']],
    ];
    \DuoTest\WpStore::reset();
    $field = new ApplyFieldMaterializer($runtimePolicy, $runtimeTokens);
    $subject = new MenuMaterializer($runtimePolicy, $runtimeTokens, $field);
    \Duo\Db::start_repeatable_read('menu fixture transaction');
    $field->begin_authored_transaction();
    \Duo\CacheInvalidationTransaction::begin();
    \Duo\CacheInvalidationTransaction::prepare_term_hierarchy_options(['nav_menu' => false]);
    try {
        $subject->finalize_menu($front);
        return ['failure' => null, 'rows' => [
            'terms' => $db->rows('terms'),
            'posts' => $db->rows('posts'),
            'postmeta' => $db->rows('postmeta'),
            'relationships' => $db->rows('term_relationships'),
            'map' => $db->rows('duo_map'),
        ]];
    } catch (Throwable $failure) {
        return ['failure' => $failure, 'rows' => [
            'terms' => $db->rows('terms'),
            'posts' => $db->rows('posts'),
            'postmeta' => $db->rows('postmeta'),
            'relationships' => $db->rows('term_relationships'),
            'map' => $db->rows('duo_map'),
        ]];
    } finally {
        \Duo\Db::rollback('menu fixture rollback');
        $field->end_authored_transaction();
        \Duo\CacheInvalidationTransaction::end();
    }
};

$orphanDb = $menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [
        ['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid],
        ['meta_id' => 2, 'post_id' => 30, 'meta_key' => '_MENU_BADGES', 'meta_value' => 'target-alias'],
        ['meta_id' => 3, 'post_id' => 30, 'meta_key' => '_menu_badges', 'meta_value' => 'stale'],
    ],
    []
);
$orphanResult = $runMenu($orphanDb);
$managedKeys = array_values(array_filter(
    $orphanResult['rows']['postmeta'],
    static fn(array $row): bool => (int) $row['post_id'] === 30 && str_starts_with((string) $row['meta_key'], '_menu_item_')
));
$orphanOk = $orphanResult['failure'] === null
    && $orphanResult['rows']['relationships'] === [[
        'object_id' => 30, 'term_taxonomy_id' => 20, 'term_order' => 0,
    ]]
    && count($managedKeys) === 8;
if (!$orphanOk && $orphanResult['failure'] instanceof Throwable) {
    fwrite(STDERR, '    orphan materialization refusal: ' . $orphanResult['failure']->getMessage() . "\n");
}
$check(
    $orphanOk,
    'a ledger-resolved exact unattached nav_menu_item is locked, attached, fully materialized, and read back'
);
$menuBadgeRows = array_values(array_filter(
    $orphanResult['rows']['postmeta'],
    static fn(array $row): bool => (int) $row['post_id'] === 30 && $row['meta_key'] === '_menu_badges'
));
$menuBadgeAliasRows = array_values(array_filter(
    $orphanResult['rows']['postmeta'],
    static fn(array $row): bool => (int) $row['post_id'] === 30 && $row['meta_key'] === '_MENU_BADGES'
));
$check(
    array_column($menuBadgeRows, 'meta_value') === ['first', 'second']
        && array_column($menuBadgeAliasRows, 'meta_value') === ['target-alias'],
    'the real nav-menu-item product path applies ordered post_meta rows while preserving a byte-distinct key alias'
);

$missingResult = $runMenu($menuDb([], [], []));
$check(
    $missingResult['failure'] instanceof Throwable
        && str_contains($missingResult['failure']->getMessage(), 'post lock/read failed')
        && ($missingResult['rows']['terms'][0]['name'] ?? null) === 'Old menu'
        && $missingResult['rows']['relationships'] === [],
    'a stale ledger mapping to a missing post refuses before the first menu mutation'
);

$wrongTypeResult = $runMenu($menuDb(
    [['ID' => 30, 'post_type' => 'post']],
    [['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid]],
    []
));
$check(
    $wrongTypeResult['failure'] instanceof Throwable
        && str_contains($wrongTypeResult['failure']->getMessage(), 'not one exact nav_menu_item')
        && ($wrongTypeResult['rows']['terms'][0]['name'] ?? null) === 'Old menu',
    'a ledger mapping to the wrong post type refuses before menu mutation'
);

$aliasResult = $runMenu($menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_DUO_UUID', 'meta_value' => $itemUuid]],
    []
));
$check(
    $aliasResult['failure'] instanceof Throwable
        && str_contains($aliasResult['failure']->getMessage(), 'collation-equal non-byte-exact')
        && ($aliasResult['rows']['terms'][0]['name'] ?? null) === 'Old menu',
    'a ledger-resolved menu item identity alias refuses before mutation'
);

$duplicateIdentityResult = $runMenu($menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [
        ['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid],
        ['meta_id' => 2, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid],
    ],
    []
));
$check(
    $duplicateIdentityResult['failure'] instanceof Throwable
        && str_contains($duplicateIdentityResult['failure']->getMessage(), 'duplicate')
        && ($duplicateIdentityResult['rows']['terms'][0]['name'] ?? null) === 'Old menu',
    'duplicate exact menu-item identity sidecars refuse before mutation'
);

$wrongIdentityResult = $runMenu($menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [[
        'meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid',
        'meta_value' => '33333333-3333-4333-8333-333333333333',
    ]],
    []
));
$check(
    $wrongIdentityResult['failure'] instanceof Throwable
        && str_contains($wrongIdentityResult['failure']->getMessage(), 'contradictory identity')
        && ($wrongIdentityResult['rows']['terms'][0]['name'] ?? null) === 'Old menu',
    'a stale ledger mapping with a different exact sidecar refuses before mutation'
);

$crossMenuResult = $runMenu($menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid]],
    [['object_id' => 30, 'term_taxonomy_id' => 21, 'term_order' => 0]],
    true,
    [],
    [[
        'term_taxonomy_id' => 21, 'term_id' => 11, 'taxonomy' => 'nav_menu',
        'description' => '', 'parent' => 0, 'count' => 1,
    ]]
));
$check(
    $crossMenuResult['failure'] instanceof Throwable
        && str_contains($crossMenuResult['failure']->getMessage(), 'cross-menu takeover')
        && ($crossMenuResult['rows']['terms'][0]['name'] ?? null) === 'Old menu'
        && $crossMenuResult['rows']['relationships'][0]['term_taxonomy_id'] === 21,
    'a stale/two-menu ledger item already attached elsewhere refuses without stealing ownership'
);

$currentResult = $runMenu($menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid]],
    [['object_id' => 30, 'term_taxonomy_id' => 20, 'term_order' => 0]]
));
$check(
    $currentResult['failure'] === null
        && count($currentResult['rows']['relationships']) === 1,
    'an exact item already owned by the current menu remains idempotent without duplicate attachment'
);

$newResult = $runMenu($menuDb([], [], [], false));
$newIdentityRows = array_values(array_filter(
    $newResult['rows']['postmeta'],
    static fn(array $row): bool => ($row['meta_key'] ?? null) === '_duo_uuid'
));
$check(
    $newResult['failure'] === null
        && count($newResult['rows']['posts']) === 1
        && count($newIdentityRows) === 1
        && ($newIdentityRows[0]['meta_value'] ?? null) === $itemUuid
        && count($newResult['rows']['relationships']) === 1,
    'a new menu item persists exact post/identity/membership readback before its ledger mapping'
);

// Polylang stores a category TERM's language/translation memberships in the
// same wp_term_relationships.object_id column a nav menu uses for POST ids.
// Force both independent allocators to mint 1: the prior raw menu read saw
// these rows after adding nav_menu(20), then falsely rejected [20,22,23] as
// not the exact desired [20]. The product path must retain both term rows.
$polylangCollisionDb = $menuDb(
    [],
    [],
    [
        ['object_id' => 1, 'term_taxonomy_id' => 22, 'term_order' => 0],
        ['object_id' => 1, 'term_taxonomy_id' => 23, 'term_order' => 0],
    ],
    false,
    [
        ['term_id' => 1, 'name' => 'Translated category', 'slug' => 'translated-category'],
        ['term_id' => 2, 'name' => 'English', 'slug' => 'en'],
        ['term_id' => 3, 'name' => 'Translation set', 'slug' => 'translation-set'],
    ],
    [
        [
            'term_taxonomy_id' => 22, 'term_id' => 2, 'taxonomy' => 'term_language',
            'description' => '', 'parent' => 0, 'count' => 1,
        ],
        [
            'term_taxonomy_id' => 23, 'term_id' => 3, 'taxonomy' => 'term_translations',
            'description' => '', 'parent' => 0, 'count' => 1,
        ],
    ]
);
$polylangCollisionResult = $runMenu($polylangCollisionDb, [
    'term_language' => (object) ['object_type' => ['term']],
    'term_translations' => (object) ['object_type' => ['term']],
]);
$polylangCollisionRelationships = $polylangCollisionResult['rows']['relationships'];
$polylangCollisionTts = array_map(
    static fn(array $row): int => (int) $row['term_taxonomy_id'],
    $polylangCollisionRelationships
);
sort($polylangCollisionTts, SORT_NUMERIC);
$polylangOwnershipJoinSeen = count(array_filter(
    $polylangCollisionResult['failure'] === null ? $polylangCollisionDb->inner()->queries() : [],
    static fn(string $sql): bool => str_contains(
        $sql,
        'SELECT tr.term_taxonomy_id, tr.term_order, tt.taxonomy FROM wp_term_relationships tr LEFT JOIN wp_term_taxonomy tt'
    ) && str_contains($sql, 'WHERE tr.object_id = 1')
)) > 0;
$check(
    $polylangCollisionResult['failure'] === null
        && array_column($polylangCollisionResult['rows']['posts'], 'ID') === [1]
        && $polylangCollisionTts === [20, 22, 23]
        && array_unique(array_column($polylangCollisionRelationships, 'object_id')) === [1]
        && $polylangOwnershipJoinSeen,
    'Polylang term_language/term_translations rows sharing a new nav_menu_item id stay intact while exact menu membership succeeds'
);

$postKeyspaceResult = $runMenu($menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid]],
    [['object_id' => 30, 'term_taxonomy_id' => 24, 'term_order' => 0]],
    true,
    [],
    [[
        'term_taxonomy_id' => 24, 'term_id' => 12, 'taxonomy' => 'category',
        'description' => '', 'parent' => 0, 'count' => 1,
    ]]
), [
    'category' => (object) ['object_type' => ['post']],
]);
$check(
    $postKeyspaceResult['failure'] instanceof Throwable
        && str_contains($postKeyspaceResult['failure']->getMessage(), 'cross-menu takeover')
        && ($postKeyspaceResult['rows']['terms'][0]['name'] ?? null) === 'Old menu'
        && ($postKeyspaceResult['rows']['relationships'][0]['term_taxonomy_id'] ?? null) === 24,
    'an extra registered post-keyspace taxonomy relationship still refuses menu ownership takeover'
);

foreach ([
    'unregistered' => [
        'taxonomy' => 'unknown_relationship_owner',
        'registrations' => [],
    ],
    'malformed registration' => [
        'taxonomy' => 'malformed_relationship_owner',
        'registrations' => ['malformed_relationship_owner' => (object) ['object_type' => 'post']],
    ],
] as $label => $case) {
    $taxonomy = $case['taxonomy'];
    $invalidTaxonomyResult = $runMenu($menuDb(
        [['ID' => 30, 'post_type' => 'nav_menu_item']],
        [['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid]],
        [['object_id' => 30, 'term_taxonomy_id' => 25, 'term_order' => 0]],
        true,
        [],
        [[
            'term_taxonomy_id' => 25, 'term_id' => 13, 'taxonomy' => $taxonomy,
            'description' => '', 'parent' => 0, 'count' => 1,
        ]]
    ), $case['registrations']);
    $check(
        $invalidTaxonomyResult['failure'] instanceof Throwable
            && str_contains($invalidTaxonomyResult['failure']->getMessage(), 'is unregistered or malformed')
            && ($invalidTaxonomyResult['rows']['terms'][0]['name'] ?? null) === 'Old menu'
            && ($invalidTaxonomyResult['rows']['relationships'][0]['term_taxonomy_id'] ?? null) === 25,
        "$label relationship taxonomy refuses before a menu mutation"
    );
}

$driftDb = $menuDb(
    [['ID' => 30, 'post_type' => 'nav_menu_item']],
    [['meta_id' => 1, 'post_id' => 30, 'meta_key' => '_duo_uuid', 'meta_value' => $itemUuid]],
    []
);
$relationshipReads = 0;
$driftDb->inner()->onQuery(static function (string $sql, string $method, \DuoTest\FakeWpdb $db) use (&$relationshipReads): mixed {
    if ($method === 'get_results'
        && str_contains($sql, 'FROM wp_term_relationships')
        && str_contains($sql, 'WHERE tr.object_id = 30')) {
        ++$relationshipReads;
        if ($relationshipReads === 2) {
            $db->seedTable('term_relationships', []);
        }
    }
    return null;
});
$driftResult = $runMenu($driftDb);
$driftOk = $driftResult['failure'] instanceof Throwable
    && str_contains($driftResult['failure']->getMessage(), 'relationship readback disagrees')
    && ($driftResult['rows']['terms'][0]['name'] ?? null) === 'Primary menu'
    && ($driftDb->rows('terms')[0]['name'] ?? null) === 'Old menu'
    && $driftDb->rows('term_relationships') === [];
if (!$driftOk && $driftResult['failure'] instanceof Throwable) {
    fwrite(STDERR, '    relationship drift refusal: ' . $driftResult['failure']->getMessage() . "\n");
}
$check(
    $driftOk,
    'post-attach same-transaction relationship drift is rejected and the authored rollback restores prior bytes'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall MenuMaterializer checks passed\n";
exit(0);
