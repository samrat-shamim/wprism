<?php
/**
 * Offline lifecycle-options snapshot regression.
 *
 * This executes the real Capture::snapshot_options_core() and compares its
 * canonical bytes/hash with a full Capture::snapshot() where the ordinary
 * full path is available. A second policy declares WooCommerce's typed table
 * while the fake target deliberately has no such table: the lifecycle path
 * must still succeed and must not issue a typed-table query. The final checks
 * exercise Deploy's existing authored-option hook safety gate.
 */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_filter(...$args): void {}
function add_action(...$args): void {}
function untrailingslashit($value): string { return rtrim((string) $value, '/\\'); }
function wp_upload_dir($time = null, $create = true, $refresh = false): array {
    return ['baseurl' => 'https://example.test/wp-content/uploads', 'basedir' => '/tmp/duo-uploads'];
}
function get_post_types($args = [], $output = 'names'): array { return []; }
function get_taxonomies($args = [], $output = 'names'): array { return []; }
function get_taxonomy($name) { return false; }
function esc_sql($value): string { return addslashes((string) $value); }
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_SLASHES); }
function is_serialized($value, $strict = true): bool {
    if (!is_string($value)) return false;
    if ($value === 'b:0;') return true;
    $length = strlen($value);
    if ($length < 4 || $value[1] !== ':') return false;
    $last = $value[$length - 1];
    if ($last !== ';' && $last !== '}') return false;
    $token = $value[0];
    if ($token === 's') return !$strict || $value[2] === '"';
    return in_array($token, ['a', 'O', 'C', 'E', 'd', 'i', 'b', 'N'], true);
}
function maybe_unserialize($value) {
    if (!is_serialized($value)) return $value;
    $decoded = @unserialize($value, ['allowed_classes' => false]);
    return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
}

final class LifecycleOptionsFakeWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $termmeta = 'wp_termmeta';
    public string $term_relationships = 'wp_term_relationships';
    public string $usermeta = 'wp_usermeta';
    public string $options = 'wp_options';
    public string $users = 'wp_users';
    public string $last_error = '';
    /** @var array<string,array{option_value:string,autoload:string}> */
    public array $optionRows = [];
    /** @var array<string,bool> */
    public array $existingTables = [];
    /** @var array<string,array<string,string>> */
    public array $tableColumns = [];
    /** @var list<array{uuid:string,entity_type:string,kind:string,id:int}> */
    public array $map = [];
    /** @var list<string> */
    public array $queries = [];

    public function get_charset_collate(): string { return ''; }

    public function prepare($query, ...$args): array {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return ['sql' => (string) $query, 'args' => $args];
    }

    public function query($sql) {
        [$sql, $args] = $this->unwrap($sql);
        $this->queries[] = $sql;
        if (preg_match('/DELETE m FROM wp_duo_map m LEFT JOIN `wp_([A-Za-z0-9_]+)` src ON src.`([A-Za-z0-9_]+)` = m\.local_id/', $sql, $m)) {
            $kind = (string) ($args[0] ?? '');
            $table = (string) $m[1];
            $pk = (string) $m[2];
            $live = [];
            foreach ($this->tableColumns[$table]['rows'] ?? [] as $row) {
                $live[(int) ($row[$pk] ?? 0)] = true;
            }
            $this->map = array_values(array_filter(
                $this->map,
                static fn(array $entry): bool => $entry['kind'] !== $kind
                    || isset($live[(int) $entry['id']])
            ));
        }
        return true;
    }

    public function get_results($query, $output = ARRAY_A): array {
        [$sql, $args] = $this->unwrap($query);
        $this->queries[] = $sql;
        if (str_contains($sql, 'option_name, option_value') && str_contains($sql, $this->options)) {
            if (str_contains($sql, "option_name LIKE 'widget\\_%'")) return [];
            $rows = [];
            foreach ($this->optionRows as $name => $row) {
                $rows[] = ['option_name' => $name, 'option_value' => $row['option_value']];
            }
            return $rows;
        }
        if (str_starts_with($sql, 'SHOW COLUMNS FROM `')) {
            preg_match('/SHOW COLUMNS FROM `wp_([A-Za-z0-9_]+)`/', $sql, $m);
            $table = (string) ($m[1] ?? '');
            return array_map(
                static fn(string $field, string $type): array => ['Field' => $field, 'Type' => $type],
                array_keys($this->tableColumns[$table]['columns'] ?? []),
                array_values($this->tableColumns[$table]['columns'] ?? [])
            );
        }
        if (str_starts_with($sql, 'SELECT * FROM `')) {
            preg_match('/SELECT \* FROM `wp_([A-Za-z0-9_]+)`/', $sql, $m);
            $table = (string) ($m[1] ?? '');
            return $this->tableColumns[$table]['rows'] ?? [];
        }
        // Identity, scope, menu, table-engine, and all other reads are empty
        // in this intentionally content-free fixture.
        return [];
    }

    public function get_row($query, $output = ARRAY_A) {
        [$sql, $args] = $this->unwrap($query);
        $this->queries[] = $sql;
        if (str_contains($sql, 'option_value, autoload') && $args !== []) {
            $name = (string) $args[0];
            $row = $this->optionRows[$name] ?? null;
            return $row === null ? null : [
                'option_value' => $row['option_value'],
                'autoload' => $row['autoload'],
            ];
        }
        if (str_contains($sql, 'entity_type, local_id') && count($args) >= 2) {
            foreach ($this->map as $entry) {
                if ($entry['uuid'] === (string) $args[0] && $entry['kind'] === (string) $args[1]) {
                    return ['entity_type' => $entry['entity_type'], 'local_id' => $entry['id']];
                }
            }
        }
        if (str_contains($sql, 'uuid, entity_type') && count($args) >= 2) {
            foreach ($this->map as $entry) {
                if ($entry['kind'] === (string) $args[0] && (int) $entry['id'] === (int) $args[1]) {
                    return ['uuid' => $entry['uuid'], 'entity_type' => $entry['entity_type']];
                }
            }
        }
        return null;
    }

    public function get_var($query) {
        [$sql, $args] = $this->unwrap($query);
        $this->queries[] = $sql;
        // Ledger migration checks see an already-current schema; all ordinary
        // identity/reference lookups are intentionally empty.
        if (str_contains($sql, 'CHARACTER_MAXIMUM_LENGTH')) return '64';
        if (str_contains($sql, 'SHOW TABLES LIKE')) {
            return isset($this->existingTables[(string) ($args[0] ?? '')])
                ? (string) ($args[0] ?? '')
                : null;
        }
        if (str_contains($sql, 'SELECT uuid FROM wp_duo_map') && count($args) >= 2) {
            foreach ($this->map as $entry) {
                if ($entry['kind'] === (string) $args[0] && (int) $entry['id'] === (int) $args[1]) {
                    return $entry['uuid'];
                }
            }
            return null;
        }
        if (str_contains($sql, 'SELECT local_id FROM wp_duo_map') && count($args) >= 2) {
            foreach ($this->map as $entry) {
                if ($entry['uuid'] === (string) $args[0] && $entry['kind'] === (string) $args[1]) {
                    return $entry['id'];
                }
            }
            return null;
        }
        if (preg_match('/SELECT 1 FROM `wp_([A-Za-z0-9_]+)` WHERE `([A-Za-z0-9_]+)` = %d/', $sql, $m)) {
            $table = (string) $m[1];
            $pk = (string) $m[2];
            $id = (int) ($args[0] ?? 0);
            foreach ($this->tableColumns[$table]['rows'] ?? [] as $row) {
                if ((int) ($row[$pk] ?? 0) === $id) return 1;
            }
            return null;
        }
        return null;
    }

    public function get_col($query): array {
        [$sql] = $this->unwrap($query);
        $this->queries[] = $sql;
        return [];
    }

    public function insert($table, $data, $format = null): int { return 1; }
    public function update($table, $data, $where, $format = null, $whereFormat = null): int { return 1; }
    public function delete($table, $where, $whereFormat = null): int { return 1; }

    private function unwrap($query): array {
        return is_array($query) && isset($query['sql'])
            ? [$query['sql'], $query['args'] ?? []]
            : [(string) $query, []];
    }
}

$wpdb = new LifecycleOptionsFakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
function get_option($name, $default = false) {
    global $wpdb;
    if ($name === 'home') return 'https://example.test';
    $row = $wpdb->optionRows[(string) $name] ?? null;
    return $row === null ? $default : maybe_unserialize($row['option_value']);
}

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Secrets.php';
require_once __DIR__ . '/../../agent/src/Uuid.php';
require_once __DIR__ . '/../../agent/src/Db.php';
require_once __DIR__ . '/../../agent/src/TransientDbException.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Identity.php';
require_once __DIR__ . '/../../agent/src/Canary.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/RepositoryCompiler.php';
require_once __DIR__ . '/../../agent/src/Snapshot.php';
require_once __DIR__ . '/../../agent/src/SidebarState.php';
require_once __DIR__ . '/../../agent/src/Tokens.php';
require_once __DIR__ . '/../../agent/src/Capture.php';
require_once __DIR__ . '/../../agent/src/Deploy.php';

use Duo\Canon;
use Duo\Capture;
use Duo\CompiledRepository;
use Duo\Deploy;
use Duo\OptionState;
use Duo\Policy;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
};

$present = static fn($value, string $autoload = 'yes'): array => OptionState::present($value, $autoload);

$wpdb->optionRows = [
    'authored_setting' => ['option_value' => 'site-value', 'autoload' => 'yes'],
    'active_plugins' => ['option_value' => serialize(['fixture/fixture.php']), 'autoload' => 'yes'],
    'template' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'stylesheet' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
];

$STALE_ZONE_METHOD_UUID = '22222222-2222-4222-8222-222222222222';

/** @return Policy */
$policy = static function (bool $declaresWooTable): Policy {
    $p = new Policy();
    $p->site = [
        'policy' => [
            'post_types' => [],
            'taxonomies' => [],
            'options' => [
                'authored_setting' => ['class' => 'authored', 'autoload' => 'yes'],
                'active_plugins' => ['class' => 'managed', 'autoload' => 'yes'],
                'template' => ['class' => 'managed', 'autoload' => 'yes'],
                'stylesheet' => ['class' => 'managed', 'autoload' => 'yes'],
            ],
        ],
    ];
    $p->manifests = [[
        'name' => 'fixture',
        'tables' => $declaresWooTable ? [
            'woocommerce_attribute_taxonomies' => [
                'class' => 'authored_snapshot', 'id_kind' => 'woo_attribute', 'pk' => 'attribute_id',
            ],
        ] : [],
    ]];
    return $p;
};

$desired = OptionState::document([
    'authored_setting' => $present('site-value'),
    'active_plugins' => $present(['fixture/fixture.php']),
    'template' => $present('fixture-theme'),
    'stylesheet' => $present('fixture-theme'),
]);
$compiled = CompiledRepository::create([
    'tree' => ['options/core' => ['type' => 'options', 'path' => 'options/core.json', 'data' => $desired]],
    'deletions' => [],
    'revision_hash' => str_repeat('a', 64),
    'manifest_hash' => str_repeat('b', 64),
]);

// 1. The clean-install lifecycle boundary does not enter Snapshot::capture,
// even though the frozen manifest declares a plugin-owned table absent here.
$wpdb->queries = [];
$lifecycle = Capture::snapshot_options_core('/unused', false, $compiled, $policy(true));
$check(isset($lifecycle['options/core']), 'lifecycle options snapshot succeeds with a declared missing typed table');
$check(
    !array_filter($wpdb->queries, static fn(string $sql): bool => str_contains($sql, 'woocommerce_attribute_taxonomies')),
    'lifecycle options snapshot does not query/validate the absent typed table'
);

// 2. Where ordinary full snapshot is available, its options/core bytes/hash
// must be exactly the same canonical result, not merely semantically equal.
$full = Capture::snapshot('/unused', false, $compiled, $policy(false));
$check(
    $lifecycle['options/core']['content'] === $full['options/core']['content'],
    'lifecycle and full snapshot options/core bytes are identical'
);
$check(
    $lifecycle['options/core']['hash'] === $full['options/core']['hash'],
    'lifecycle and full snapshot options/core hashes are identical'
);

// 2b. A stale custom identity used by option_name_refs must be pruned before
// tokenization. The table exists, but local id 42 was deleted; the lifecycle
// path must match the full path without giving Apply a token that resolves to
// a nonexistent row. This is deliberately separate from the clean-install
// absent-table case above: the targeted prune must skip that case while still
// handling a present table's dead mapping.
$refPolicy = $policy(false);
$refPolicy->manifests[0]['option_name_refs'] = [[
    'class' => 'authored',
    'id_kind' => 'wc_zone_method',
    'match' => '^woocommerce_[a-z0-9_]+_(?<id>[0-9]+)_settings$',
]];
$refPolicy->manifests[0]['tables'] = [
    'woocommerce_shipping_zone_methods' => [
        'class' => 'authored_snapshot',
        'id_kind' => 'wc_zone_method',
        'pk' => 'instance_id',
        'columns' => [
            'method_id' => ['class' => 'authored'],
            'method_order' => ['class' => 'authored', 'lint_ok' => true],
            'is_enabled' => ['class' => 'authored', 'lint_ok' => true],
        ],
        'refs' => [],
    ],
];
$wpdb->existingTables = ['wp_woocommerce_shipping_zone_methods' => true];
$wpdb->tableColumns['woocommerce_shipping_zone_methods'] = [
    'columns' => [
        'instance_id' => 'bigint(20)',
        'method_id' => 'varchar(200)',
        'method_order' => 'int(11)',
        'is_enabled' => 'tinyint(1)',
    ],
    'rows' => [],
];
$wpdb->optionRows['woocommerce_flat_rate_42_settings'] = [
    'option_value' => serialize(['title' => 'Flat rate']),
    'autoload' => 'yes',
];
$wpdb->map = [[
    'uuid' => $STALE_ZONE_METHOD_UUID,
    'entity_type' => 'woocommerce_shipping_zone_methods',
    'kind' => 'wc_zone_method',
    'id' => 42,
]];
$narrowWithStaleMap = Capture::snapshot_options_core('/unused', false, $compiled, $refPolicy);
$check($wpdb->map === [], 'lifecycle options snapshot prunes a stale option-name custom identity');
$check(
    $narrowWithStaleMap['options/core']['content'] === $lifecycle['options/core']['content'],
    'stale option-name identity cannot change narrow canonical options bytes'
);
$check(
    $narrowWithStaleMap['options/core']['hash'] === $lifecycle['options/core']['hash'],
    'stale option-name identity cannot change narrow canonical options hash'
);
$tokens = new Duo\Tokens();
$check($tokens->id_to_token(42, 'wc_zone_method') === null, 'deleted custom row cannot mint an orphan option token');
$orphanRejected = false;
try {
    $tokens->token_to_id('{{wc_zone_method:' . $STALE_ZONE_METHOD_UUID . '}}');
} catch (RuntimeException $e) {
    $orphanRejected = str_contains($e->getMessage(), 'unresolvable ref');
}
$check($orphanRejected, 'apply-direction token resolution rejects the pruned custom identity');
$wpdb->map = [[
    'uuid' => $STALE_ZONE_METHOD_UUID,
    'entity_type' => 'woocommerce_shipping_zone_methods',
    'kind' => 'wc_zone_method',
    'id' => 42,
]];
$fullWithStaleMap = Capture::snapshot('/unused', false, $compiled, $refPolicy);
$check($wpdb->map === [], 'full snapshot prunes the same stale option-name custom identity');
$check(
    $narrowWithStaleMap['options/core']['content'] === $fullWithStaleMap['options/core']['content'],
    'narrow/full snapshots remain canonical-byte identical with a stale option-name identity'
);
$check(
    $narrowWithStaleMap['options/core']['hash'] === $fullWithStaleMap['options/core']['hash'],
    'narrow/full snapshots remain canonical-hash identical with a stale option-name identity'
);

// 3. The existing lifecycle record gate still rejects an authored hook
// mutation that is neither managed nor exactly the frozen desired value.
$recordGate = (new ReflectionClass(Deploy::class))->getMethod('unexpected_lifecycle_state_changes');
$before = $desired;
$after = OptionState::document([
    'authored_setting' => $present('hook-mutated'),
    'active_plugins' => $present(['fixture/fixture.php']),
    'template' => $present('fixture-theme'),
    'stylesheet' => $present('fixture-theme'),
]);
$unexpected = $recordGate->invoke(null, $before, $after, $desired);
$check($unexpected === ['authored_setting'], 'unexpected authored option hook changes remain fail-closed');

// A namespace-owned option with no classification still trips the shared
// discovery gate on the narrow lifecycle path (not only during full capture).
$namespacePolicy = $policy(false);
$namespacePolicy->manifests[0]['option_namespaces'] = [['match' => '^fixture_', 'owner' => 'ignored']];
$wpdb->optionRows['fixture_unclassified'] = ['option_value' => 'x', 'autoload' => 'yes'];
$caught = false;
try {
    Capture::snapshot_options_core('/unused', false, $compiled, $namespacePolicy);
} catch (RuntimeException $e) {
    $caught = str_contains($e->getMessage(), 'incomplete state discovery');
}
$check($caught, 'lifecycle options snapshot retains option namespace discovery gate');

echo $failures === 0 ? "ALL PASSED\n" : "FAIL: $failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
