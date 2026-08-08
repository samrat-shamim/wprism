<?php
/**
 * Offline engine seam for manifest batch regeneration.
 *
 * This deliberately exercises Apply's private dispatch boundary with a fake
 * wpdb and manifest-shipped regenerators.  It proves candidate scoping,
 * stale-row refresh dispatch, pending retry/marker lifetime, delete-only
 * cleanup, no-op/unrelated isolation, callback heartbeats, and the unchanged
 * legacy single-id path without loading WordPress or WooCommerce.
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

final class WooEngineFakeWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $last_error = '';
    public string $failReadContaining = '';
    public int $catalogScanCalls = 0;
    public array $map = [];
    public array $postsRows = [];
    public array $lookupRows = [];
    public array $kv = [];

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
        if (preg_match("/FROM wp_posts WHERE post_parent = (\\d+) AND post_type = 'product_variation'/", $query, $m)) {
            $parentId = (int) $m[1];
            $ids = [];
            foreach ($this->postsRows as $id => $row) {
                if ((int) ($row['post_parent'] ?? 0) === $parentId
                    && (string) ($row['post_type'] ?? '') === 'product_variation') {
                    $ids[] = (int) $id;
                }
            }
            sort($ids, SORT_NUMERIC);
            return $ids;
        }
        return [];
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
];
$wpdb->postsRows[204] = ['post_type' => 'product_variation', 'post_parent' => 201];
$wpdb->postsRows[205] = ['post_type' => 'product_variation', 'post_parent' => 201];
$wpdb->postsRows[101] = ['post_type' => 'product', 'post_parent' => 0];
$wpdb->postsRows[102] = ['post_type' => 'product_variation', 'post_parent' => 100];
$wpdb->lookupRows = [101 => true, 102 => true, 103 => true, 104 => true, 105 => true];

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Db.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Apply.php';

$policy = \Duo\Policy::load(null, ['batch', 'legacy']);
$applyReflection = new \ReflectionClass(\Duo\Apply::class);
$apply = $applyReflection->newInstanceWithoutConstructor();
$policyProperty = $applyReflection->getProperty('policy');
$policyProperty->setAccessible(true);
$policyProperty->setValue($apply, $policy);
$regen = $applyReflection->getMethod('regen_dependencies');
$regen->setAccessible(true);
$captureReparent = $applyReflection->getMethod('capture_regen_reparent_context');
$captureReparent->setAccessible(true);
$captureDelete = $applyReflection->getMethod('capture_regen_delete_context');
$captureDelete->setAccessible(true);

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
$invoke = static function (array $work, array $tree, array $deletions = []) use ($regen, $apply): void {
    $regen->invoke($apply, $work, $tree, $deletions);
};

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

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
