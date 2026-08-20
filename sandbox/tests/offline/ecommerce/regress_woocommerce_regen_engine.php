<?php
/**
 * Offline engine seam for manifest derived-state regeneration — BOTH
 * dispatchers, over the same scenarios.
 *
 * This deliberately exercises Apply's private dispatch boundary with a fake
 * wpdb and manifest-shipped adapters.  It proves candidate scoping,
 * stale-row refresh dispatch, pending retry/marker lifetime, delete-only
 * cleanup, no-op/unrelated isolation, callback heartbeats, and the unchanged
 * legacy single-id path without loading WordPress or WooCommerce.
 *
 * DUO-3342 migrated the shipped WooCommerce lookup repair from the batch
 * regen_dependency channel to the provider contract, so the file grew a second
 * half that re-expresses every behavioural claim above against
 * Apply::rebuild()'s provider dispatch. The batch half is deliberately KEPT
 * rather than rewritten: it is the parity oracle, and a migration claim proved
 * only against the new path proves nothing about the old one it is claiming
 * parity with. The batch channel also remains a live engine grammar any
 * manifest may declare (the-events-calendar.json still declares its non-batch
 * sibling), so retiring its coverage would retire a validator's evidence as a
 * side effect of moving one adapter.
 *
 * Neither half names a plugin: the fixtures use unrelated CPT names on purpose,
 * so a product/variation branch in engine code could not satisfy either.
 */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$fixtureDir = sys_get_temp_dir() . '/duo_regress_woo_engine_' . bin2hex(random_bytes(4));
mkdir($fixtureDir . '/regenerators', 0777, true);
register_shutdown_function(static function () use ($fixtureDir): void {
    if (!is_dir($fixtureDir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($fixtureDir);
});

file_put_contents($fixtureDir . '/regenerators/fake-batch.php', <<<'PHP'
<?php
namespace Duo\Regenerators;
final class FakeBatch {
    public static int $calls = 0;
    public static int $heartbeats = 0;
    public static bool $fail = false;
    public static array $ids = [];
    public static array $deletions = [];

    public function __construct($policy) {}

    // Policy keeps the original single-id method as the load-time trust
    // boundary for every regenerator; the engine uses regenerate_batch() for
    // this opt-in declaration.
    public function regenerate(int $localId): void {}

    public function regenerate_batch(array $liveIds, array $deletionContext, ?callable $heartbeat = null): void {
        self::$calls++;
        self::$ids[] = array_values(array_map('intval', $liveIds));
        self::$deletions[] = $deletionContext;
        if ($heartbeat !== null) {
            self::$heartbeats++;
            $heartbeat();
        }
        if (self::$fail) {
            throw new \RuntimeException('injected batch failure');
        }
        global $wpdb;
        foreach ($liveIds as $id) {
            $wpdb->lookupRows[(int) $id] = true;
        }
        foreach ($deletionContext as $context) {
            if (($context['kind'] ?? 'delete') === 'reparent') {
                continue;
            }
            unset($wpdb->lookupRows[(int) ($context['id'] ?? 0)]);
            foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                unset($wpdb->lookupRows[(int) $childId]);
            }
        }
    }
}
PHP
);

file_put_contents($fixtureDir . '/regenerators/fake-single.php', <<<'PHP'
<?php
namespace Duo\Regenerators;
final class FakeSingle {
    public static array $calls = [];
    public function __construct($policy) {}
    public function regenerate(int $localId): void {
        self::$calls[] = $localId;
        global $wpdb;
        $wpdb->lookupRows[$localId] = true;
    }
}
PHP
);

file_put_contents($fixtureDir . '/batch.json', json_encode([
    'name' => 'batch',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'product' => [
            'children' => ['product_variation'],
            'regen_dependency' => [
                'regenerator' => 'fake-batch',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
                'batch' => ['enabled' => true, 'always_on_write' => true],
            ],
        ],
        'product_variation' => [
            'regen_dependency' => [
                'regenerator' => 'fake-batch',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
                'batch' => ['enabled' => true, 'always_on_write' => true],
            ],
        ],
        'conditional_product' => [
            'regen_dependency' => [
                'regenerator' => 'fake-batch',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
                'batch' => ['enabled' => true, 'always_on_write' => false],
            ],
        ],
        // Deliberately unrelated CPT names: the deletion-receipt mechanism
        // must follow this declaration rather than a product convention.
        'duo_story' => [
            'children' => ['duo_chapter'],
            'regen_dependency' => [
                'regenerator' => 'fake-batch',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
                'batch' => ['enabled' => true, 'always_on_write' => true],
            ],
        ],
        'duo_chapter' => [
            'regen_dependency' => [
                'regenerator' => 'fake-batch',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
                'batch' => ['enabled' => true, 'always_on_write' => true],
            ],
        ],
        // This pair intentionally has no children declaration. Its matching
        // post_parent rows below must not be inferred into a parent receipt.
        'duo_unrelated_parent' => [
            'regen_dependency' => [
                'regenerator' => 'fake-batch',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
                'batch' => ['enabled' => true, 'always_on_write' => true],
            ],
        ],
        'duo_unrelated_child' => [
            'regen_dependency' => [
                'regenerator' => 'fake-batch',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
                'batch' => ['enabled' => true, 'always_on_write' => true],
            ],
        ],
    ],
], JSON_PRETTY_PRINT));
// The provider-dispatch half's fixture (DUO-3342). Deliberately declares NO
// regen_dependency: the two dispatchers may not both claim a post type, and
// negotiation refuses a channel-declaring capability on one that a batch
// declaration owns. `children` is what the engine's pre-delete inventory reads
// to bound a parent receipt, so the parent/child scenarios below exercise the
// same declared relation the batch half does, under different CPT names.
file_put_contents($fixtureDir . '/provider.json', json_encode([
    'name' => 'provider',
    'spec_version' => DUO_SPEC_VERSION,
    'plugin' => 'fake-dispatch/fake-dispatch.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'providers' => [[
        'id' => 'fake-dispatch',
        'version' => '1.0.0',
        'source' => 'manifest',
        'plugin' => 'fake-dispatch/fake-dispatch.php',
        'capabilities' => ['rebuild'],
    ]],
    'actions' => [[
        'kind' => 'provider',
        'provider' => 'fake-dispatch',
        'capability' => 'rebuild',
        'args' => new stdClass(),
        'triggers' => ['post:duo_widget', 'post:duo_widget_part'],
    ]],
    'post_types' => [
        'duo_widget' => ['class' => 'authored', 'children' => ['duo_widget_part']],
        'duo_widget_part' => ['class' => 'authored'],
        'duo_widget_unrelated' => ['class' => 'authored'],
    ],
], JSON_PRETTY_PRINT));
file_put_contents($fixtureDir . '/legacy.json', json_encode([
    'name' => 'legacy',
    'spec_version' => DUO_SPEC_VERSION,
    'post_types' => [
        'widget' => [
            'regen_dependency' => [
                'regenerator' => 'fake-single',
                'verify' => ['table' => 'lookup', 'column' => 'post_id'],
            ],
        ],
    ],
], JSON_PRETTY_PRINT));
putenv('DUO_MANIFESTS_DIR=' . $fixtureDir);

// The narrow WordPress surface Apply::rebuild() touches on the provider-dispatch
// drive below: an object-cache flush either side of the action loop (hard-fails
// on false) and the future-post cron reschedule for every post-kind work row.
function wp_cache_flush(): bool {
    $GLOBALS['woo_engine_cache_flushes'] = ($GLOBALS['woo_engine_cache_flushes'] ?? 0) + 1;
    return true;
}
function wp_clear_scheduled_hook(string $hook, array $args = []): int {
    return 0;
}
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool {
    return true;
}
function wp_next_scheduled(string $hook, array $args = []): int|false {
    return false;
}

final class WooEngineFakeWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    // Apply::rebuild()'s term-recount step names this table; get_col() below
    // matches no query against it, so the recount loop walks past an empty set.
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $last_error = '';
    public string $failReadContaining = '';
    public int $catalogScanCalls = 0;
    public array $map = [];
    public array $postsRows = [];
    public array $lookupRows = [];
    public array $kv = [];
    /** @var array<int,array{parent_id:int,post_types:array<int,string>}> */
    public array $childInventoryQueries = [];

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $value = is_int($arg) || is_float($arg)
                ? (string) $arg
                : "'" . addslashes((string) $arg) . "'";
            $query = preg_replace('/%[dsif]/', $value, $query, 1);
        }
        return $query;
    }

    public function query(string $query): int|false {
        $this->last_error = '';
        if (preg_match("/INSERT INTO wp_duo_kv .*VALUES \\('((?:[^'\\\\]|\\\\.)*)', '((?:[^'\\\\]|\\\\.)*)'\\)/", $query, $m)) {
            $this->kv[stripslashes($m[1])] = stripslashes($m[2]);
            return 1;
        }
        if (preg_match("/DELETE FROM wp_duo_kv WHERE k = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
            unset($this->kv[stripslashes($m[1])]);
            return 1;
        }
        return 1;
    }

    private function readFails(string $query): bool {
        if ($this->failReadContaining === '' || !str_contains($query, $this->failReadContaining)) {
            return false;
        }
        $this->last_error = 'injected bookkeeping read failure';
        return true;
    }

    public function get_results(string $query, $output = null): array {
        if ($this->readFails($query)) {
            return [];
        }
        if (str_contains($query, 'INNER JOIN wp_posts') || str_contains($query, 'duo_map m')) {
            $this->catalogScanCalls++;
        }
        if (str_contains($query, 'SELECT k, v FROM wp_duo_kv')) {
            return array_map(
                static fn(string $k, string $v): array => ['k' => $k, 'v' => $v],
                array_keys($this->kv),
                array_values($this->kv)
            );
        }
        return [];
    }

    public function get_row(string $query, $output = null): ?array {
        if ($this->readFails($query)) {
            return null;
        }
        if (preg_match('/FROM wp_posts WHERE ID = (\d+)/', $query, $m)) {
            return $this->postsRows[(int) $m[1]] ?? null;
        }
        return null;
    }

    public function get_col(string $query): array {
        if ($this->readFails($query)) {
            return [];
        }
        if (!preg_match('/FROM wp_posts WHERE post_parent = (\\d+)/', $query, $m)) {
            return [];
        }
        $parentId = (int) $m[1];
        $postTypes = [];
        if (preg_match("/AND post_type = '((?:[^'\\\\]|\\\\.)*)'/", $query, $typeMatch)) {
            $postTypes[] = stripslashes($typeMatch[1]);
        } elseif (preg_match('/AND post_type IN \\(([^)]*)\\)/', $query, $typesMatch)) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $typesMatch[1], $quotedTypes);
            $postTypes = array_map('stripslashes', $quotedTypes[1] ?? []);
        }
        sort($postTypes, SORT_STRING);
        if ($postTypes === []) {
            return [];
        }
        $this->childInventoryQueries[] = [
            'parent_id' => $parentId,
            'post_types' => $postTypes,
        ];
        $ids = [];
        foreach ($this->postsRows as $id => $row) {
            if ((int) ($row['post_parent'] ?? 0) === $parentId
                && in_array((string) ($row['post_type'] ?? ''), $postTypes, true)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public function get_var(string $query): mixed {
        if ($this->readFails($query)) {
            return null;
        }
        if (preg_match("/SELECT v FROM wp_duo_kv WHERE k = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
            return $this->kv[stripslashes($m[1])] ?? null;
        }
        if (preg_match("/SELECT local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $query, $m)) {
            foreach ($this->map as $row) {
                if ($row['uuid'] === $m[1] && $row['kind'] === $m[2]) {
                    return $row['id'];
                }
            }
            return null;
        }
        if (preg_match("/SELECT uuid FROM wp_duo_map WHERE id_kind = '([^']+)' AND local_id = (\\d+)/", $query, $m)) {
            foreach ($this->map as $row) {
                if ($row['kind'] === $m[1] && (int) $row['id'] === (int) $m[2]) {
                    return $row['uuid'];
                }
            }
            return null;
        }
        if (str_contains($query, 'SHOW TABLES LIKE')) {
            return 'wp_lookup';
        }
        if (preg_match('/FROM `wp_lookup` WHERE `post_id` = (\d+)/', $query, $m)) {
            return isset($this->lookupRows[(int) $m[1]]) ? 1 : null;
        }
        return null;
    }
}

$wpdb = new WooEngineFakeWpdb();
$u1 = '11111111-1111-4111-8111-111111111111';
$u2 = '22222222-2222-4222-8222-222222222222';
$u3 = '33333333-3333-4333-8333-333333333333';
$u4 = '44444444-4444-4444-8444-444444444444';
$u5 = '77777777-7777-4777-8777-777777777777';
$parentA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$parentB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$parentC = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$captureVariation = '55555555-5555-4555-8555-555555555555';
$adoptVariation = '66666666-6666-4666-8666-666666666666';
$storyParent = '88888888-8888-4888-8888-888888888888';
$storyDeletedChapter = '99999999-9999-4999-8999-999999999999';
$storyLiveChapter = '12121212-1212-4121-8121-121212121212';
$storyUnrelatedChild = '13131313-1313-4131-8131-131313131313';
$undeclaredParent = '14141414-1414-4141-8141-141414141414';
$undeclaredDeletedChild = '15151515-1515-4151-8151-151515151515';
$undeclaredLiveChild = '16161616-1616-4161-8161-161616161616';
$wpdb->map = [
    ['uuid' => $u1, 'kind' => 'post', 'id' => 101],
    ['uuid' => $u2, 'kind' => 'post', 'id' => 102],
    ['uuid' => $u3, 'kind' => 'post', 'id' => 103],
    ['uuid' => $u4, 'kind' => 'post', 'id' => 104],
    ['uuid' => $u5, 'kind' => 'post', 'id' => 105],
    ['uuid' => $parentA, 'kind' => 'post', 'id' => 201],
    ['uuid' => $parentB, 'kind' => 'post', 'id' => 202],
    ['uuid' => $parentC, 'kind' => 'post', 'id' => 203],
    ['uuid' => $captureVariation, 'kind' => 'post', 'id' => 204],
    ['uuid' => $storyParent, 'kind' => 'post', 'id' => 301],
    ['uuid' => $storyDeletedChapter, 'kind' => 'post', 'id' => 302],
    ['uuid' => $storyLiveChapter, 'kind' => 'post', 'id' => 303],
    ['uuid' => $storyUnrelatedChild, 'kind' => 'post', 'id' => 304],
    ['uuid' => $undeclaredParent, 'kind' => 'post', 'id' => 305],
    ['uuid' => $undeclaredDeletedChild, 'kind' => 'post', 'id' => 306],
    ['uuid' => $undeclaredLiveChild, 'kind' => 'post', 'id' => 307],
];
$wpdb->postsRows[204] = ['post_type' => 'product_variation', 'post_parent' => 201];
$wpdb->postsRows[205] = ['post_type' => 'product_variation', 'post_parent' => 201];
$wpdb->postsRows[101] = ['post_type' => 'product', 'post_parent' => 0];
$wpdb->postsRows[102] = ['post_type' => 'product_variation', 'post_parent' => 100];
$wpdb->postsRows[301] = ['post_type' => 'duo_story', 'post_parent' => 0];
$wpdb->postsRows[302] = ['post_type' => 'duo_chapter', 'post_parent' => 301];
$wpdb->postsRows[303] = ['post_type' => 'duo_chapter', 'post_parent' => 301];
$wpdb->postsRows[304] = ['post_type' => 'duo_unrelated_child', 'post_parent' => 301];
$wpdb->postsRows[305] = ['post_type' => 'duo_unrelated_parent', 'post_parent' => 0];
$wpdb->postsRows[306] = ['post_type' => 'duo_unrelated_child', 'post_parent' => 305];
$wpdb->postsRows[307] = ['post_type' => 'duo_unrelated_child', 'post_parent' => 305];
$wpdb->lookupRows = [
    101 => true, 102 => true, 103 => true, 104 => true, 105 => true,
    301 => true, 302 => true, 303 => true, 304 => true,
    305 => true, 306 => true, 307 => true,
];

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Apply/Apply.php';

$policy = \Duo\Policy::load(null, ['batch', 'legacy']);
$apply = new \Duo\RegenerationContextStore(
    $policy,
    static fn(string $channel, string $surface): bool => false
);
$regen = new \Duo\DependencyRegenerator(
    $policy,
    $apply,
    static fn(string $surface): bool => false,
    static fn(string $channel, string $surface): bool => false,
    static fn(string $surface): bool => false,
    static fn(string $surface): bool => false,
    static function (): void {}
);
$captureReparent = new \ReflectionMethod(\Duo\RegenerationContextStore::class, 'capture_reparents');
$captureDelete = new \ReflectionMethod(\Duo\RegenerationContextStore::class, 'capture_deletions');
$captureDeleteSource = implode("\n", array_slice(
    (array) file($captureDelete->getFileName(), FILE_IGNORE_NEW_LINES),
    $captureDelete->getStartLine() - 1,
    $captureDelete->getEndLine() - $captureDelete->getStartLine() + 1
));

$tree = [
    $u1 => ['type' => 'post', 'data' => ['type' => 'product']],
    $u2 => ['type' => 'post', 'data' => ['type' => 'product_variation']],
    $u3 => ['type' => 'post', 'data' => ['type' => 'widget']],
    $u4 => ['type' => 'post', 'data' => ['type' => 'conditional_product']],
    $u5 => ['type' => 'post', 'data' => ['type' => 'product_variation']],
];
$work = static function (string $uuid): array {
    return [['uuid' => $uuid]];
};
$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
};
$invoke = static function (array $work, array $tree, array $deletions = []) use ($regen): void {
    $warnings = [];
    $regen->run($work, $tree, $deletions, $warnings);
};

// This is a generic engine boundary. The declaration below uses unrelated
// names so a product/product_variation branch cannot satisfy the behavior.
$check(!preg_match('/if\\s*\\(\\s*\\$postType\\s*={2,3}\\s*[\'\"]product[\'\"]/', $captureDeleteSource)
    && !preg_match('/post_type\\s*=\\s*[\'\"]product_variation[\'\"]/', $captureDeleteSource),
    'deletion receipt inventory has no product/variation literal decision');

// A declared duo_story -> duo_chapter relation uses wp_posts.post_parent.
// The fixture carries one explicit child tombstone, one live declared child,
// and one explicit but unrelated child CPT. Only the declared, explicit child
// belongs in the parent receipt; no implicit cascade is authorized.
$storyDeleteContexts = $captureDelete->invoke($apply, [
    ['type' => 'post', 'uuid' => $storyParent],
    ['type' => 'post', 'uuid' => $storyDeletedChapter],
    ['type' => 'post', 'uuid' => $storyUnrelatedChild],
]);
$storyContextsByUuid = [];
foreach ($storyDeleteContexts as $context) {
    $storyContextsByUuid[(string) ($context['uuid'] ?? '')] = $context;
}
$storyParentContext = $storyContextsByUuid[$storyParent] ?? null;
$check(is_array($storyParentContext) && ($storyParentContext['child_ids'] ?? null) === [302],
    'unrelated parent receipt contains only its explicitly tombstoned declared child');
$check(isset($storyContextsByUuid[$storyDeletedChapter]) && isset($storyContextsByUuid[$storyUnrelatedChild]),
    'explicit child tombstones keep their own receipts instead of granting parent cascade authority');
$storyInventory = $wpdb->childInventoryQueries[count($wpdb->childInventoryQueries) - 1] ?? null;
$check($storyInventory === ['parent_id' => 301, 'post_types' => ['duo_chapter']],
    'generic inventory query is driven by the declared unrelated child CPT');

// The existing batch-rebuild consumer must suppress only receipt ids. It must
// not turn a parent receipt into a cascade over the still-live chapter.
// No earlier dispatch has loaded the fixture regenerator yet; retain that
// zero baseline without loading it merely to inspect a static property.
$storyCallsBefore = 0;
$invoke([], [], $storyDeleteContexts);
$storyBatchCall = count(\Duo\Regenerators\FakeBatch::$deletions) - 1;
$storyBatchParentContext = null;
foreach (\Duo\Regenerators\FakeBatch::$deletions[$storyBatchCall] as $context) {
    if (($context['uuid'] ?? '') === $storyParent) {
        $storyBatchParentContext = $context;
        break;
    }
}
$check(\Duo\Regenerators\FakeBatch::$calls === $storyCallsBefore + 1
    && \Duo\Regenerators\FakeBatch::$ids[$storyBatchCall] === [],
    'parent/child tombstone receipts dispatch no invented live ids');
$check(is_array($storyBatchParentContext) && ($storyBatchParentContext['child_ids'] ?? null) === [302],
    'batch rebuild receives the filtered generic parent receipt');
$check(!isset($wpdb->lookupRows[301]) && !isset($wpdb->lookupRows[302]) && !isset($wpdb->lookupRows[304])
    && isset($wpdb->lookupRows[303]),
    'rebuild removes only explicit receipts and retains the live declared child lookup row');

// Matching post_parent values alone must not create a relation. An undeclared
// parent remains safe even when both it and a child type have batch contracts.
$inventoryQueriesBefore = count($wpdb->childInventoryQueries);
$undeclaredDeleteContexts = $captureDelete->invoke($apply, [
    ['type' => 'post', 'uuid' => $undeclaredParent],
    ['type' => 'post', 'uuid' => $undeclaredDeletedChild],
]);
$undeclaredContextsByUuid = [];
foreach ($undeclaredDeleteContexts as $context) {
    $undeclaredContextsByUuid[(string) ($context['uuid'] ?? '')] = $context;
}
$undeclaredParentContext = $undeclaredContextsByUuid[$undeclaredParent] ?? null;
$check(is_array($undeclaredParentContext) && ($undeclaredParentContext['child_ids'] ?? null) === [],
    'undeclared parent has no inferred child receipt');
$check(count($wpdb->childInventoryQueries) === $inventoryQueriesBefore,
    'undeclared parent performs no child inventory query');
$invoke([], [], $undeclaredDeleteContexts);
$check(!isset($wpdb->lookupRows[305]) && !isset($wpdb->lookupRows[306]) && isset($wpdb->lookupRows[307]),
    'undeclared parent rebuild removes only explicit tombstones and retains its live child');

// Keep the long-standing batch scenarios below independent: this new receipt
// seam has already asserted its own call trace and uses disjoint fixture ids.
\Duo\Regenerators\FakeBatch::$calls = 0;
\Duo\Regenerators\FakeBatch::$heartbeats = 0;
\Duo\Regenerators\FakeBatch::$ids = [];
\Duo\Regenerators\FakeBatch::$deletions = [];

// The pre-mutation receipt itself accumulates roots across chained moves. This
// reflection seam stands in for two authored transactions: A->B is captured,
// then the live row is at B and B->C is captured before the first rebuild ever
// gets a chance to clear the marker.
$captureTree = [
    $captureVariation => [
        'type' => 'post',
        'data' => ['type' => 'product_variation', 'parent' => '{{post:' . $parentB . '}}'],
    ],
];
$wpdb->kv['regen_reparent_context:' . $captureVariation] = json_encode([
    'kind' => 'reparent',
    'uuid' => $captureVariation,
    'id' => 204,
    'post_type' => 'product_variation',
    'old_parent_id' => 201,
    'new_parent_id' => 202,
    'parent_id' => 201,
    'root_ids' => [201, 202],
    'child_ids' => [],
]);
$wpdb->postsRows[204]['post_parent'] = 202;
$captureTree[$captureVariation]['data']['parent'] = '{{post:' . $parentC . '}}';
$captureReparent->invoke($apply, [['uuid' => $captureVariation]], $captureTree);
$capturedChain = json_decode($wpdb->kv['regen_reparent_context:' . $captureVariation], true);
$capturedRoots = array_map('intval', (array) ($capturedChain['root_ids'] ?? []));
sort($capturedRoots, SORT_NUMERIC);
$check($capturedRoots === [201, 202, 203], 'pre-mutation reparent receipt accumulates A/B/C roots');
unset($wpdb->kv['regen_reparent_context:' . $captureVariation]);

// Database errors while capturing pre-mutation receipts must refuse the
// transaction before a missing row can be mistaken for a harmless no-op.
$wpdb->failReadContaining = 'FROM wp_posts WHERE ID = 204';
try {
    $captureReparent->invoke($apply, [['uuid' => $captureVariation]], $captureTree);
    $check(false, 'reparent source-row read failure is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'regeneration bookkeeping read failed: reparent source post 204'),
        'reparent source-row read failure is surfaced');
}
$check(!isset($wpdb->kv['regen_reparent_context:' . $captureVariation]),
    'failed reparent source read creates no durable receipt');
$wpdb->failReadContaining = '';
$wpdb->last_error = '';

$wpdb->failReadContaining = 'FROM wp_posts WHERE ID = 102';
try {
    $captureDelete->invoke($apply, [['type' => 'post', 'uuid' => $u2]]);
    $check(false, 'delete source-row read failure is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'regeneration bookkeeping read failed: delete source post 102'),
        'delete source-row read failure is surfaced');
}
$check(!isset($wpdb->kv['regen_delete_context:' . $u2]),
    'failed delete source read creates no durable receipt');
$wpdb->failReadContaining = '';
$wpdb->last_error = '';

// Adopted rows have no Ledger mapping until the transaction's adopt phase.
// A plan-validated env_id is the only safe pre-mutation identity fallback;
// arbitrary authored ids must never enter this path.
$adoptTree = [
    $adoptVariation => [
        'type' => 'post',
        'data' => ['type' => 'product_variation', 'parent' => '{{post:' . $parentB . '}}'],
    ],
];
$captureReparent->invoke($apply, [['uuid' => $adoptVariation, 'env_id' => 205]], $adoptTree);
$adoptContext = json_decode($wpdb->kv['regen_reparent_context:' . $adoptVariation], true);
$check((int) ($adoptContext['id'] ?? 0) === 205 && ($adoptContext['root_ids'] ?? []) === [201, 202],
    'validated adopt env_id preserves the old parent before Ledger::set');
unset($wpdb->kv['regen_reparent_context:' . $adoptVariation]);

// An existing row does not suppress a changed write candidate, and no
// unrelated mapped product is discovered through a catalog-wide scan.
$invoke($work($u1), $tree);
$check(\Duo\Regenerators\FakeBatch::$calls === 1, 'changed product dispatches the batch adapter despite an existing row');
$check(\Duo\Regenerators\FakeBatch::$ids[0] === [101], 'batch receives only this apply write candidate');
$check($wpdb->catalogScanCalls === 0, 'candidate dispatch performs no whole-catalog mapped-post scan');

// Empty/no-op work and a non-batch post do not load/call the Woo batch path.
$before = \Duo\Regenerators\FakeBatch::$calls;
$invoke([], [], []);
$check(\Duo\Regenerators\FakeBatch::$calls === $before, 'no-op apply skips batch regeneration');
unset($wpdb->lookupRows[103]);
$invoke($work($u3), $tree);
$check(\Duo\Regenerators\FakeBatch::$calls === $before, 'unrelated legacy post skips the batch adapter');
$check(\Duo\Regenerators\FakeSingle::$calls === [103], 'legacy missing-row dependency retains single-id behavior');

// A batch declaration that opts out of always-on-write keeps existence-gated
// changed-work behavior; this flag is not silently ignored.  Pending markers
// and deletion receipts remain unconditional below.
$before = \Duo\Regenerators\FakeBatch::$calls;
$invoke($work($u4), $tree);
$check(\Duo\Regenerators\FakeBatch::$calls === $before, 'always_on_write=false skips an existing changed-row candidate');
unset($wpdb->lookupRows[104]);
$invoke($work($u4), $tree);
$check(\Duo\Regenerators\FakeBatch::$calls === $before + 1, 'always_on_write=false dispatches when verification is missing');

// Read errors at the declarative verification boundary are not equivalent to
// a missing row: the adapter must not run on an uncertain database view.
$before = \Duo\Regenerators\FakeBatch::$calls;
$wpdb->failReadContaining = 'SHOW TABLES LIKE';
try {
    $invoke($work($u4), $tree);
    $check(false, 'verification-table read failure is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'regeneration bookkeeping read failed: verify table lookup'),
        'verification-table read failure is surfaced');
}
$check(\Duo\Regenerators\FakeBatch::$calls === $before,
    'verification-table read failure does not dispatch a regenerator');
$wpdb->failReadContaining = '';
$wpdb->last_error = '';

// Marker inventory is durable retry authority. A failed inventory read must
// retain the marker and refuse the pass instead of treating it as empty.
$markerReadKey = 'regen_pending:' . $u1;
$wpdb->kv[$markerReadKey] = 'product';
$before = \Duo\Regenerators\FakeBatch::$calls;
$wpdb->failReadContaining = 'SELECT k, v FROM wp_duo_kv';
try {
    $invoke([], [], []);
    $check(false, 'pending-marker inventory read failure is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'ledger read failed: key/value prefix inventory'),
        'pending-marker inventory read failure is surfaced');
}
$check(isset($wpdb->kv[$markerReadKey]), 'failed marker inventory read retains the durable retry marker');
$check(\Duo\Regenerators\FakeBatch::$calls === $before,
    'failed marker inventory read dispatches no regenerator');
$wpdb->failReadContaining = '';
$wpdb->last_error = '';
unset($wpdb->kv[$markerReadKey]);

// Batch-owned orphan markers must not sit forever after their ledger mapping
// disappears; the engine drops them and records the reason in warnings.
$orphanKey = 'regen_pending:orphan-batch-uuid';
$wpdb->kv[$orphanKey] = 'product';
$before = \Duo\Regenerators\FakeBatch::$calls;
$invoke([], [], []);
$check(!isset($wpdb->kv[$orphanKey]), 'orphan batch regen_pending marker is swept');
$check(\Duo\Regenerators\FakeBatch::$calls === $before, 'orphan marker sweep does not invoke a plugin adapter');

// Reparent receipts survive a failed batch together with the live variation's
// pending marker. If the variation moves again A->B->C before retry, the
// durable A/B receipt and the current B/C context merge to A/B/C; a
// receipt-only retry then replays every root and clears both markers only
// after success, without treating the variation as deleted.
$reparentKey = 'regen_reparent_context:' . $u2;
$reparentContextAB = [
    'kind' => 'reparent',
    'uuid' => $u2,
    'id' => 102,
    'post_type' => 'product_variation',
    'old_parent_id' => 100,
    'new_parent_id' => 200,
    'parent_id' => 100,
    'root_ids' => [100, 200],
    'child_ids' => [],
];
$reparentContextBC = [
    'kind' => 'reparent',
    'uuid' => $u2,
    'id' => 102,
    'post_type' => 'product_variation',
    'old_parent_id' => 200,
    'new_parent_id' => 300,
    'parent_id' => 200,
    'root_ids' => [200, 300],
    'child_ids' => [],
];
$wpdb->kv[$reparentKey] = json_encode($reparentContextAB);
\Duo\Regenerators\FakeBatch::$fail = true;
try {
    $invoke($work($u2), $tree, [$reparentContextAB]);
    $check(false, 'injected reparent batch failure is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'injected batch failure'), 'reparent batch failure is surfaced');
}
$check(isset($wpdb->kv[$reparentKey]), 'failed reparent retains durable context');
$check(isset($wpdb->kv['regen_pending:' . $u2]), 'failed reparent retains live pending marker');
$firstReparentCall = count(\Duo\Regenerators\FakeBatch::$deletions) - 1;
$check(\Duo\Regenerators\FakeBatch::$deletions[$firstReparentCall][0]['kind'] === 'reparent', 'failed A->B dispatch carries reparent context');

// This is the second authored move before retry. The engine must merge the
// durable A/B marker with the B/C current context rather than letting the
// latter overwrite the original root A.
try {
    $invoke($work($u2), $tree, [$reparentContextBC]);
    $check(false, 'chained B->C batch failure is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'injected batch failure'), 'chained B->C batch failure is surfaced');
}
$chainedFailureCall = count(\Duo\Regenerators\FakeBatch::$deletions) - 1;
$chainedRoots = array_map('intval', (array) (\Duo\Regenerators\FakeBatch::$deletions[$chainedFailureCall][0]['root_ids'] ?? []));
sort($chainedRoots, SORT_NUMERIC);
$check($chainedRoots === [100, 200, 300], 'failed chained reparent merges all A/B/C root ids');
$check(isset($wpdb->kv[$reparentKey]) && isset($wpdb->kv['regen_pending:' . $u2]), 'failed chained reparent retains both durable and pending markers');
// Apply's pre-mutation capture writes this merged receipt before the B->C
// raw mutation commits; model that durable write before the receipt-only run.
$mergedReparentContext = $reparentContextBC;
$mergedReparentContext['root_ids'] = [100, 200, 300];
$wpdb->kv[$reparentKey] = json_encode($mergedReparentContext);

\Duo\Regenerators\FakeBatch::$fail = false;
$invoke([], [], []);
$receiptOnlyCall = count(\Duo\Regenerators\FakeBatch::$deletions) - 1;
$receiptOnlyRoots = array_map('intval', (array) (\Duo\Regenerators\FakeBatch::$deletions[$receiptOnlyCall][0]['root_ids'] ?? []));
sort($receiptOnlyRoots, SORT_NUMERIC);
$check($receiptOnlyRoots === [100, 200, 300], 'receipt-only retry replays every accumulated root');
$check(!isset($wpdb->kv[$reparentKey]) && !isset($wpdb->kv['regen_pending:' . $u2]), 'successful receipt-only retry clears both markers');

// If that failed reparented variation is deleted before retry, the stale
// Ledger id remains useful for context discovery but must not be sent as a
// live id. The adapter still receives the accumulated roots plus deletion
// cleanup, and successful exact absence clears all three marker families.
$deletedAfterReparentKey = 'regen_reparent_context:' . $u5;
$deletedAfterReparentContext = [
    'kind' => 'reparent',
    'uuid' => $u5,
    'id' => 105,
    'post_type' => 'product_variation',
    'old_parent_id' => 100,
    'new_parent_id' => 200,
    'parent_id' => 100,
    'root_ids' => [100, 200],
    'child_ids' => [],
];
$deletedAfterReparent = [
    'kind' => 'delete',
    'uuid' => $u5,
    'id' => 105,
    'post_type' => 'product_variation',
    'parent_id' => 200,
    'child_ids' => [],
];
$wpdb->kv[$deletedAfterReparentKey] = json_encode($deletedAfterReparentContext);
$wpdb->kv['regen_pending:' . $u5] = 'product_variation';
$wpdb->lookupRows[105] = true;
\Duo\Regenerators\FakeBatch::$fail = true;
try {
    $invoke($work($u5), $tree, [$deletedAfterReparentContext]);
    $check(false, 'failed reparent before deletion is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'injected batch failure'), 'failed reparent before deletion is surfaced');
}
\Duo\Regenerators\FakeBatch::$fail = false;
$deletedAfterDeleteKey = 'regen_delete_context:' . $u5;
$wpdb->kv[$deletedAfterDeleteKey] = json_encode($deletedAfterReparent);
$invoke([], [], [$deletedAfterReparent]);
$deleteAfterReparentCall = count(\Duo\Regenerators\FakeBatch::$deletions) - 1;
$deleteAfterReparentIds = \Duo\Regenerators\FakeBatch::$ids[$deleteAfterReparentCall];
$deleteAfterReparentKinds = array_map(
    static fn(array $context): string => (string) ($context['kind'] ?? 'delete'),
    \Duo\Regenerators\FakeBatch::$deletions[$deleteAfterReparentCall]
);
$reparentRetryContext = null;
foreach (\Duo\Regenerators\FakeBatch::$deletions[$deleteAfterReparentCall] as $context) {
    if (($context['kind'] ?? 'delete') === 'reparent') {
        $reparentRetryContext = $context;
        break;
    }
}
$deleteAfterReparentRoots = array_map(
    'intval',
    (array) ($reparentRetryContext['root_ids'] ?? [])
);
sort($deleteAfterReparentRoots, SORT_NUMERIC);
$check($deleteAfterReparentIds === [], 'deleted reparent variation is suppressed from live batch ids');
$check(count(array_filter($deleteAfterReparentKinds, static fn(string $kind): bool => $kind === 'reparent')) === 1
    && count(array_filter($deleteAfterReparentKinds, static fn(string $kind): bool => $kind === 'delete')) === 1,
    'deleted retry retains reparent and deletion contexts');
$check($deleteAfterReparentRoots === [100, 200], 'deleted retry retains both old/new reparent roots');
$check(!isset($wpdb->lookupRows[105]), 'deleted retry removes the variation lookup row');
$check(!isset($wpdb->kv[$deletedAfterReparentKey])
    && !isset($wpdb->kv[$deletedAfterDeleteKey])
    && !isset($wpdb->kv['regen_pending:' . $u5]),
    'successful deleted retry clears reparent, deletion, and pending markers');

// A failed write leaves a durable pending marker; a later marker-only retry
// naturally replays the same live id and clears it only after verification.
\Duo\Regenerators\FakeBatch::$fail = true;
try {
    $invoke($work($u1), $tree);
    $check(false, 'injected batch failure is surfaced');
} catch (\Throwable $e) {
    $check(str_contains($e->getMessage(), 'injected batch failure'), 'batch failure is surfaced to apply');
}
$failedDispatchCalls = \Duo\Regenerators\FakeBatch::$calls;
$check(isset($wpdb->kv['regen_pending:' . $u1]), 'failed batch retains regen_pending marker');
\Duo\Regenerators\FakeBatch::$fail = false;
$invoke([], [], []);
$check(\Duo\Regenerators\FakeBatch::$calls === $failedDispatchCalls + 1, 'pending-only retry dispatches the failed product');
$check(!isset($wpdb->kv['regen_pending:' . $u1]), 'successful retry clears regen_pending marker');

// A delete can be the only candidate.  Its context is forwarded and cleared
// after the batch succeeds, even when there are no live ids.
$deleteKey = 'regen_delete_context:' . $u2;
$wpdb->kv[$deleteKey] = json_encode([
    'uuid' => $u2,
    'id' => 102,
    'post_type' => 'product_variation',
    'parent_id' => 100,
    'child_ids' => [],
]);
$invoke([], [], [[
    'uuid' => $u2,
    'id' => 102,
    'post_type' => 'product_variation',
    'parent_id' => 100,
    'child_ids' => [],
]]);
$last = count(\Duo\Regenerators\FakeBatch::$deletions) - 1;
$check(\Duo\Regenerators\FakeBatch::$ids[$last] === [], 'delete-only dispatch has no invented live ids');
$check(\Duo\Regenerators\FakeBatch::$deletions[$last][0]['parent_id'] === 100, 'delete context preserves parent id');
$check(!isset($wpdb->kv[$deleteKey]), 'successful delete-only batch clears durable context');

// The generic engine supplies and the adapter consumes a heartbeat callback.
$check(\Duo\Regenerators\FakeBatch::$heartbeats > 0, 'batch engine passes an invoked heartbeat callback');

// ======================================================================
// DUO-3342: the SAME semantics, driven through the provider dispatch.
//
// Every behavioural claim the batch scenarios above make is re-expressed
// here against Apply::rebuild()'s provider path: candidate scoping, the
// pre-delete parent/child inventory, deleted-id suppression, durable
// deletion/reparent receipts as retry authority, pending-marker lifetime,
// and marker clearing gated on a verified receipt. The batch half stays
// because it is the ORACLE — a claim about parity is worth nothing if only
// one side of it still runs.
//
// The fake provider stands in exactly where FakeBatch stands above, and the
// engine's own contract does the rest: Providers::invoke() refuses a
// malformed or unverified receipt, so "the adapter reported success" is a
// property of this drive rather than an assumption in it.
// ======================================================================

final class FakeDispatchProvider {
    public static int $calls = 0;
    public static bool $fail = false;
    public static bool $unverified = false;
    /** @var array<int,array<int,int>> */
    public static array $ids = [];
    /** @var array<int,array<int,array<string,mixed>>> */
    public static array $deletions = [];
    /** @var array<int,array<string,mixed>> */
    public static array $envelopes = [];

    public function identity(): array {
        return ['id' => 'fake-dispatch', 'plugin' => 'fake-dispatch/fake-dispatch.php', 'version' => '1.0.0'];
    }

    public function capabilities(): array {
        return ['rebuild' => FakeDispatchProvider::declaration()];
    }

    /** @return array<string,mixed> */
    public static function declaration(): array {
        return [
            'args' => [],
            'reads' => ['post:duo_widget', 'post:duo_widget_part'],
            'writes' => ['table:lookup'],
            'scope' => 'entity',
            'idempotent' => true,
            'timeout_seconds' => 60,
            'context' => ['always_on_write', 'deletions', 'reparents', 'retry'],
        ];
    }

    public function invoke(string $capability, array $args): array {
        global $wpdb;
        self::$calls++;
        $envelope = (array) ($args['entities'] ?? []);
        self::$envelopes[] = $envelope;
        $liveIds = [];
        foreach ((array) ($envelope['entities'] ?? []) as $entity) {
            $liveIds[] = (int) $entity['id'];
        }
        self::$ids[] = $liveIds;
        // The same regrouping the shipped Woo provider does: one row per root
        // back into one context per moved entity, so the accumulated root set
        // an interrupted chain left behind is what the repair sees.
        $context = [];
        foreach ((array) ($envelope['deletions'] ?? []) as $row) {
            $context[] = [
                'kind' => 'delete',
                'uuid' => (string) $row['uuid'],
                'id' => (int) $row['id'],
                'post_type' => (string) $row['post_type'],
                'parent_id' => (int) $row['parent_id'],
                'child_ids' => array_map('intval', (array) $row['child_ids']),
            ];
        }
        $moves = [];
        foreach ((array) ($envelope['reparents'] ?? []) as $row) {
            $uuid = (string) $row['uuid'];
            $moves[$uuid] ??= [
                'kind' => 'reparent',
                'uuid' => $uuid,
                'id' => (int) $row['id'],
                'old_parent_id' => (int) $row['old_parent_id'],
                'new_parent_id' => (int) $row['new_parent_id'],
                'root_ids' => [],
            ];
            $rootId = (int) $row['root_id'];
            if ($rootId > 0) {
                $moves[$uuid]['root_ids'][$rootId] = $rootId;
            }
        }
        foreach ($moves as $move) {
            $move['root_ids'] = array_values($move['root_ids']);
            sort($move['root_ids'], SORT_NUMERIC);
            $context[] = $move;
        }
        self::$deletions[] = $context;

        if (self::$fail) {
            throw new \RuntimeException('injected provider failure');
        }
        foreach ($liveIds as $id) {
            $wpdb->lookupRows[$id] = true;
        }
        foreach ($context as $row) {
            if ($row['kind'] === 'reparent') {
                continue;
            }
            unset($wpdb->lookupRows[(int) $row['id']]);
            foreach ((array) $row['child_ids'] as $childId) {
                unset($wpdb->lookupRows[(int) $childId]);
            }
        }
        return [
            'before' => ['live_ids' => $liveIds],
            'after' => ['live_ids' => $liveIds],
            'verified' => !self::$unverified,
        ];
    }
}

echo "\n== the same semantics through the provider dispatch (DUO-3342) ==\n";


$providerPolicy = \Duo\Policy::load(null, ['provider']);
$providerAction = $providerPolicy->actions_for(['post:duo_widget'])[0];
$check(($providerAction['capability'] ?? null) === 'rebuild'
    && ($providerAction['triggers'] ?? null) === ['post:duo_widget', 'post:duo_widget_part'],
    'the provider fixture action is selected off its own declared surfaces');
$check($providerPolicy->regen_batch('duo_widget') === null
    && $providerPolicy->regen_batch('duo_widget_part') === null,
    'and its post types are claimed by no batch regenerator, which is what lets one dispatcher own their markers');

$providerSelection = new \Duo\RebuildSelection($providerPolicy);
$providerSelection->set_selected_actions([$providerAction]);
$providerNegotiation = [
    'providers' => ['fake-dispatch' => new FakeDispatchProvider()],
    'capabilities' => ['fake-dispatch' => ['rebuild' => FakeDispatchProvider::declaration()]],
];
$providerSelection->set_negotiated_providers($providerNegotiation);
$providerApply = new \Duo\RegenerationContextStore(
    $providerPolicy,
    fn(string $channel, string $surface): bool =>
        $providerSelection->declares_channel_for($channel, $surface)
);
$providerDispatcher = new \Duo\RebuildActionDispatcher(
    $providerPolicy,
    new \Duo\ProviderActionBatchBuilder($providerPolicy, \Duo\Snapshot::row_tables($providerPolicy)),
    static function (): void {}
);
$captureDeleteProvider = new \ReflectionMethod(\Duo\RegenerationContextStore::class, 'capture_deletions');
$captureReparentProvider = new \ReflectionMethod(\Duo\RegenerationContextStore::class, 'capture_reparents');

/**
 * One rebuild() pass. Returns the pass's own warnings/receipts plus whether it
 * threw, so a broken edge reads as a FAIL line rather than exit 255.
 */
$driveProvider = static function (
    array $work = [],
    array $tree = [],
    array $regenContext = [],
    array $deleteWork = [],
    bool $withDeletes = false
) use ($providerDispatcher, $providerApply, $providerSelection, $providerNegotiation): array {
    $warnings = [];
    $receipts = [];
    $error = '';
    try {
        $providerDispatcher->dispatch(
            $providerSelection->selected_actions(),
            $providerNegotiation,
            $work,
            $tree,
            $withDeletes ? $deleteWork : [],
            $regenContext,
            $providerApply->durable_reparents(),
            $providerApply->durable_deletions(),
            false,
            false,
            null,
            null,
            $warnings,
            $receipts
        );
    } catch (\Throwable $t) {
        $error = $t->getMessage();
    }
    return [
        'error' => $error,
        'warnings' => implode("\n", $warnings),
        'receipts' => $receipts,
    ];
};

$widget = 'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1';
$widgetPart = 'b2b2b2b2-b2b2-4b2b-8b2b-b2b2b2b2b2b2';
$widgetOtherPart = 'c3c3c3c3-c3c3-4c3c-8c3c-c3c3c3c3c3c3';
$widgetUnrelated = 'd4d4d4d4-d4d4-4d4d-8d4d-d4d4d4d4d4d4';
$widgetMoved = 'e5e5e5e5-e5e5-4e5e-8e5e-e5e5e5e5e5e5';
$wpdb->map[] = ['uuid' => $widget, 'kind' => 'post', 'id' => 401];
$wpdb->map[] = ['uuid' => $widgetPart, 'kind' => 'post', 'id' => 402];
$wpdb->map[] = ['uuid' => $widgetOtherPart, 'kind' => 'post', 'id' => 403];
$wpdb->map[] = ['uuid' => $widgetUnrelated, 'kind' => 'post', 'id' => 404];
$wpdb->map[] = ['uuid' => $widgetMoved, 'kind' => 'post', 'id' => 405];
$wpdb->postsRows[401] = ['post_type' => 'duo_widget', 'post_parent' => 0];
$wpdb->postsRows[402] = ['post_type' => 'duo_widget_part', 'post_parent' => 401];
$wpdb->postsRows[403] = ['post_type' => 'duo_widget_part', 'post_parent' => 401];
$wpdb->postsRows[404] = ['post_type' => 'duo_widget_unrelated', 'post_parent' => 401];
$wpdb->postsRows[405] = ['post_type' => 'duo_widget_part', 'post_parent' => 401];
foreach ([401, 402, 403, 404, 405] as $lookupId) {
    $wpdb->lookupRows[$lookupId] = true;
}
$providerTree = [
    $widget => ['type' => 'post', 'data' => ['type' => 'duo_widget']],
    $widgetPart => ['type' => 'post', 'data' => ['type' => 'duo_widget_part']],
    $widgetMoved => ['type' => 'post', 'data' => ['type' => 'duo_widget_part', 'parent' => '{{post:' . $widget . '}}']],
];
$providerWork = static fn(string $uuid): array => [['uuid' => $uuid]];
$deleteRow = static fn(string $uuid, string $postType): array => [
    'uuid' => $uuid, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => $postType,
];
$lastEnvelope = static fn(): array => FakeDispatchProvider::$envelopes[count(FakeDispatchProvider::$envelopes) - 1] ?? [];
$lastIds = static fn(): array => FakeDispatchProvider::$ids[count(FakeDispatchProvider::$ids) - 1] ?? [];
$lastContext = static fn(): array => FakeDispatchProvider::$deletions[count(FakeDispatchProvider::$deletions) - 1] ?? [];

// --- candidate scoping: this run's write candidates, no catalog scan ---
$scansBefore = $wpdb->catalogScanCalls;
$run = $driveProvider($providerWork($widget), $providerTree);
$check($run['error'] === '' && FakeDispatchProvider::$calls === 1 && $lastIds() === [401],
    'a changed post dispatches the provider with only this run\'s write candidate');
$check($wpdb->catalogScanCalls === $scansBefore,
    'provider candidate dispatch performs no whole-catalog mapped-post scan either');
$check(($lastEnvelope()['always_on_write'] ?? null) === true
    && ($lastEnvelope()['retry'] ?? null) === false,
    'the flags the retired batch declaration carried ride in the envelope instead of being implied');

$before = FakeDispatchProvider::$calls;
$run = $driveProvider();
$check(FakeDispatchProvider::$calls === $before && $run['error'] === '',
    'a no-op apply dispatches nothing at all');
$check(str_contains((string) ($run['receipts'][0]['skipped'] ?? ''), 'empty entity batch'),
    'and says so in an explicit skip receipt rather than firing on an empty batch');

$run = $driveProvider($providerWork($u1), $tree);
$check(FakeDispatchProvider::$calls === $before,
    "a post type this action does not trigger on is not this dispatcher's work");

// --- the pre-delete inventory: declared children only, no cascade ---
$wpdb->kv = [];
$inventoryBefore = count($wpdb->childInventoryQueries);
$providerDeleteWork = [
    ['type' => 'post', 'uuid' => $widget],
    ['type' => 'post', 'uuid' => $widgetPart],
    ['type' => 'post', 'uuid' => $widgetUnrelated],
];
$captured = (array) $captureDeleteProvider->invoke($providerApply, $providerDeleteWork);
$capturedByUuid = [];
foreach ($captured as $context) {
    $capturedByUuid[(string) $context['uuid']] = $context;
}
$check(($capturedByUuid[$widget]['child_ids'] ?? null) === [402],
    'the provider-declared parent receipt contains only its explicitly tombstoned declared child');
$check(isset($capturedByUuid[$widgetPart]) && !isset($capturedByUuid[$widgetUnrelated]),
    'the declared child keeps its own receipt; an undeclared sibling CPT gets none');
$check(($wpdb->childInventoryQueries[count($wpdb->childInventoryQueries) - 1] ?? null)
    === ['parent_id' => 401, 'post_types' => ['duo_widget_part']],
    'and the inventory query is driven by the declared child CPT, not a product convention');
$check(count($wpdb->childInventoryQueries) === $inventoryBefore + 1,
    'the undeclared post type performs no child inventory query of its own');

// --- delivery, deleted-id suppression, and clear-on-verified ---
$run = $driveProvider(
    $providerWork($widgetOtherPart),
    $providerTree + [$widgetOtherPart => ['type' => 'post', 'data' => ['type' => 'duo_widget_part']]],
    [],
    [$deleteRow($widget, 'duo_widget'), $deleteRow($widgetPart, 'duo_widget_part')],
    true
);
$deliveredDeletes = array_values(array_filter($lastContext(), static fn(array $r): bool => $r['kind'] === 'delete'));
$check($run['error'] === '' && count($deliveredDeletes) === 2,
    'a --with-deletes run delivers both tombstones on the deletions channel');
$parentRow = null;
foreach ($deliveredDeletes as $row) {
    if ($row['uuid'] === $widget) {
        $parentRow = $row;
    }
}
$check(($parentRow['child_ids'] ?? null) === [402] && ($parentRow['parent_id'] ?? null) === 0
    && ($parentRow['post_type'] ?? null) === 'duo_widget',
    'carrying the captured inventory the regenerator channel carried — the documented parity gap is closed');
$check($lastIds() === [403],
    'the still-live sibling is live work, and the deleted ids never appear among it');
$check(!isset($wpdb->lookupRows[401]) && !isset($wpdb->lookupRows[402]) && isset($wpdb->lookupRows[403]),
    'the repair removed exactly the explicit receipts and retained the live declared child');
$check(!isset($wpdb->kv['regen_delete_context:' . $widget])
    && !isset($wpdb->kv['regen_delete_context:' . $widgetPart]),
    'and a verified receipt cleared both durable delete markers');

// --- failure retains every marker family; the retry replays them ---
$wpdb->kv = [];
$captureDeleteProvider->invoke($providerApply, [['type' => 'post', 'uuid' => $widget]]);
$check(isset($wpdb->kv['regen_delete_context:' . $widget]), 'a fresh capture arms the durable delete marker');
FakeDispatchProvider::$fail = true;
$run = $driveProvider($providerWork($widgetOtherPart), $providerTree + [
    $widgetOtherPart => ['type' => 'post', 'data' => ['type' => 'duo_widget_part']],
]);
// One asymmetry with the batch path, observed rather than asserted away: the
// batch dispatcher re-throws the adapter's own message inline, while
// Providers::invoke() carries it as $previous under a fixed wrapper. Both are
// hard apply failures; only the rendered text differs, and the operator-facing
// half of that is DUO-3338's posture, not this migration's.
$check(str_contains($run['error'], "duo: required manifest action 'provider:fake-dispatch/rebuild' failed")
    && str_contains($run['error'], "provider 'fake-dispatch' capability 'rebuild' failed"),
    'a provider failure is a hard apply failure naming the declaration, not a warning');
$check(isset($wpdb->kv['regen_delete_context:' . $widget]),
    'THE RETRY AUTHORITY: the durable delete receipt survives the failure');
$check(($wpdb->kv['regen_pending:' . $widgetOtherPart] ?? null) === 'duo_widget_part',
    'and the live candidate keeps a pending marker, exactly as the batch path leaves one');
FakeDispatchProvider::$fail = false;
$run = $driveProvider();
$check($run['error'] === '' && $lastIds() === [403],
    'a marker-only retry replays the same live id with no authored work at all');
$check(count(array_filter($lastContext(), static fn(array $r): bool => $r['kind'] === 'delete')) === 1,
    'and replays the outstanding deletion receipt in the same call');
$check(!isset($wpdb->kv['regen_pending:' . $widgetOtherPart])
    && !isset($wpdb->kv['regen_delete_context:' . $widget]),
    'the successful retry clears both marker families');

// --- an unverified receipt is not success ---
$wpdb->kv = [];
$captureDeleteProvider->invoke($providerApply, [['type' => 'post', 'uuid' => $widget]]);
FakeDispatchProvider::$unverified = true;
$run = $driveProvider();
FakeDispatchProvider::$unverified = false;
$check(str_contains($run['error'], 'no value-level verification'),
    'a receipt that proves nothing is refused by the engine contract itself');
$check(isset($wpdb->kv['regen_delete_context:' . $widget]),
    'and an unverified invocation clears nothing — convergence is the receipt, not the call');
$run = $driveProvider();
$check($run['error'] === '' && !isset($wpdb->kv['regen_delete_context:' . $widget]),
    'the next apply re-delivers and clears it');

// --- chained reparents: every accumulated root survives to the retry ---
$wpdb->kv = [];
$wpdb->kv['regen_reparent_context:' . $widgetMoved] = json_encode([
    'kind' => 'reparent',
    'uuid' => $widgetMoved,
    'id' => 405,
    'post_type' => 'duo_widget_part',
    'old_parent_id' => 401,
    'new_parent_id' => 406,
    'parent_id' => 401,
    'root_ids' => [401, 406],
    'child_ids' => [],
]);
$wpdb->postsRows[405]['post_parent'] = 406;
$providerTree[$widgetMoved]['data']['parent'] = '{{post:' . $widgetOtherPart . '}}';
$freshMove = (array) $captureReparentProvider->invoke($providerApply, [['uuid' => $widgetMoved]], $providerTree);
$mergedRoots = array_map('intval', (array) (json_decode(
    (string) $wpdb->kv['regen_reparent_context:' . $widgetMoved],
    true
)['root_ids'] ?? []));
sort($mergedRoots, SORT_NUMERIC);
$check($mergedRoots === [401, 403, 406],
    'the pre-mutation capture accumulates A/B/C roots for a provider-declared post type too');
FakeDispatchProvider::$fail = true;
$run = $driveProvider([], [], $freshMove);
FakeDispatchProvider::$fail = false;
$check($run['error'] !== '' && isset($wpdb->kv['regen_reparent_context:' . $widgetMoved]),
    'a failed reparent repair retains the durable receipt');
$run = $driveProvider();
$replayed = null;
foreach ($lastContext() as $row) {
    if ($row['kind'] === 'reparent') {
        $replayed = $row;
    }
}
$check(($replayed['root_ids'] ?? null) === [401, 403, 406],
    'the receipt-only retry replays every accumulated root — no root is lost to the one-row-per-root delivery');
$check(!isset($wpdb->kv['regen_reparent_context:' . $widgetMoved]),
    'and the verified retry clears the reparent marker');

// --- deleted after a failed reparent: stale id discovers the job, never live work ---
$wpdb->kv = [];
$wpdb->kv['regen_reparent_context:' . $widgetMoved] = json_encode([
    'kind' => 'reparent',
    'uuid' => $widgetMoved,
    'id' => 405,
    'post_type' => 'duo_widget_part',
    'old_parent_id' => 401,
    'new_parent_id' => 406,
    'parent_id' => 401,
    'root_ids' => [401, 406],
    'child_ids' => [],
]);
$wpdb->kv['regen_pending:' . $widgetMoved] = 'duo_widget_part';
$wpdb->kv['regen_delete_context:' . $widgetMoved] = json_encode([
    'kind' => 'delete',
    'uuid' => $widgetMoved,
    'id' => 405,
    'post_type' => 'duo_widget_part',
    'parent_id' => 406,
    'child_ids' => [],
]);
$wpdb->lookupRows[405] = true;
$run = $driveProvider();
$kinds = array_map(static fn(array $r): string => $r['kind'], $lastContext());
sort($kinds, SORT_STRING);
$check($run['error'] === '' && $lastIds() === [],
    'a deleted entity whose stale mapping still resolves is suppressed from the live batch');
$check($kinds === ['delete', 'reparent'],
    'while both of its outstanding receipts are delivered in the same call');
$check(!isset($wpdb->lookupRows[405]), 'the repair removed its lookup row');
$check(!isset($wpdb->kv['regen_reparent_context:' . $widgetMoved])
    && !isset($wpdb->kv['regen_delete_context:' . $widgetMoved])
    && !isset($wpdb->kv['regen_pending:' . $widgetMoved]),
    'and a verified receipt clears reparent, deletion, and pending markers together');

// --- the lease bracket that replaces the heartbeat callback ---
// The batch channel hands its adapter a heartbeat callable; the provider
// contract has no such parameter (invoke() takes a capability name and typed
// args), so the engine brackets the whole invocation instead. Asserted against
// the source because an offline Apply carries no lease identity, which is the
// same idiom this suite uses for run()'s own threading.
$rebuildSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Rebuild/RebuildActionDispatcher.php');
$check((bool) preg_match(
    '/\(\$this->renewLease\)\(\);\s*\$receipt = Providers::invoke\(/',
    $rebuildSource
) && (bool) preg_match('/\);\s*\(\$this->renewLease\)\(\);/', $rebuildSource),
    'the promotion lease is renewed immediately before and after the opaque provider call');
$check(($GLOBALS['woo_engine_cache_flushes'] ?? 0) > 0,
    'and the drive above is the real rebuild() pass, object-cache flush included');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
