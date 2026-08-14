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
$GLOBALS['lifecycle_cache_deletes'] = [];
function wp_cache_delete(...$args): bool {
    $GLOBALS['lifecycle_cache_deletes'][] = $args;
    return true;
}
function maybe_serialize($value) {
    return is_array($value) || is_object($value) ? serialize($value) : $value;
}
function is_serialized($value, $strict = true): bool {
    if (!is_string($value)) return false;
    $value = trim($value);
    if ($value === 'N;') return true;
    if ($value === 'b:0;') return true;
    $length = strlen($value);
    if ($length < 4 || $value[1] !== ':') return false;
    if ($strict) {
        $last = $value[$length - 1];
        if ($last !== ';' && $last !== '}') return false;
    }
    $token = $value[0];
    if ($token === 's') return !$strict || $value[2] === '"';
    return in_array($token, ['a', 'O', 'C', 'E', 'd', 'i', 'b'], true);
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
    public string $schemaProbeErrorColumn = '';
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
    /** @var list<array{sql:string,args:array}> */
    public array $queryCalls = [];
    /** @var list<array{table:string,data:array,where?:array}> */
    public array $writes = [];
    /** @var list<object> */
    public array $menuTerms = [];
    /** @var array<int,string> */
    public array $termUuidById = [];

    public function get_charset_collate(): string { return ''; }

    public function prepare($query, ...$args): array {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return ['sql' => (string) $query, 'args' => $args];
    }

    public function query($sql) {
        [$sql, $args] = $this->unwrap($sql);
        $this->queries[] = $sql;
        $this->queryCalls[] = ['sql' => $sql, 'args' => $args];
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
        if (str_contains($sql, "tt.taxonomy = 'nav_menu'")) {
            return $this->menuTerms;
        }
        if (str_contains($sql, 'option_name, option_value') && str_contains($sql, $this->options)) {
            if (str_contains($sql, "option_name LIKE 'widget\\_%'")) {
                $rows = [];
                foreach ($this->optionRows as $name => $row) {
                    if (str_starts_with($name, 'widget_')) {
                        $rows[] = ['option_name' => $name, 'option_value' => $row['option_value']];
                    }
                }
                return $rows;
            }
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
        if (str_contains($sql, 'SELECT option_value FROM') && $args !== []) {
            $name = (string) $args[0];
            return $this->optionRows[$name]['option_value'] ?? null;
        }
        if (str_contains($sql, 'SELECT meta_value FROM wp_termmeta') && $args !== []) {
            return $this->termUuidById[(int) $args[0]] ?? null;
        }
        // Ledger migration checks see an already-current schema; all ordinary
        // identity/reference lookups are intentionally empty.
        if (str_contains($sql, 'CHARACTER_MAXIMUM_LENGTH')) {
            if ($this->schemaProbeErrorColumn !== ''
                && str_contains($sql, "COLUMN_NAME = '{$this->schemaProbeErrorColumn}'")) {
                $this->last_error = 'simulated schema-width probe failure';
                return null;
            }
            return '64';
        }
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

    public function insert($table, $data, $format = null): int {
        $this->writes[] = ['table' => (string) $table, 'data' => $data];
        return 1;
    }
    public function update($table, $data, $where, $format = null, $whereFormat = null): int {
        $this->writes[] = ['table' => (string) $table, 'data' => $data, 'where' => $where];
        return 1;
    }
    public function delete($table, $where, $whereFormat = null): int { return 1; }

    private function unwrap($query): array {
        return is_array($query) && isset($query['sql'])
            ? [$query['sql'], $query['args'] ?? []]
            : [(string) $query, []];
    }
}

final class CaptureSerializedWakeupProbe {
    public static bool $woke = false;
    public static bool $unserialized = false;

    public function __wakeup(): void {
        self::$woke = true;
    }

    public function __unserialize(array $data): void {
        self::$unserialized = true;
    }
}

final class SidebarSerializedWakeupProbe {
    public static bool $woke = false;

    public function __wakeup(): void {
        self::$woke = true;
    }
}

final class ApplySerializedWakeupProbe {
    public static bool $woke = false;
    public static bool $unserialized = false;

    public function __wakeup(): void {
        self::$woke = true;
    }

    public function __unserialize(array $data): void {
        self::$unserialized = true;
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
require_once __DIR__ . '/../../agent/src/Apply.php';

use Duo\Canon;
use Duo\Capture;
use Duo\CompiledRepository;
use Duo\Deploy;
use Duo\Apply;
use Duo\OptionState;
use Duo\Policy;
use Duo\PlainData;
use Duo\SidebarState;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
};

// An uncertain INFORMATION_SCHEMA read cannot be interpreted as an
// already-current ledger. Long Woo table names require the widened schema,
// so ensure() must stop before it can leave a legacy VARCHAR(32) in service.
foreach ([
    'entity_type' => 'schema width lookup for duo_map.entity_type',
    'id_kind' => 'schema width lookup for duo_map.id_kind',
] as $column => $context) {
    $wpdb->queries = [];
    $wpdb->schemaProbeErrorColumn = $column;
    $schemaProbeRefused = false;
    try {
        \Duo\Ledger::ensure();
    } catch (Throwable $e) {
        $schemaProbeRefused = str_contains($e->getMessage(), 'ledger read failed: ' . $context);
    }
    $check($schemaProbeRefused, "ledger schema migration refuses a failed $column width probe");
    $check(
        !array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'ALTER TABLE')),
        "failed $column width probe cannot issue a guessed schema migration"
    );
    $wpdb->last_error = '';
}
$wpdb->schemaProbeErrorColumn = '';

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

// 0. Apply's target-owned option readers use the same strict plain-data
// boundary as Capture. Invoke both private consumers directly so this
// regression cannot pass merely because the source contains a safer helper:
// assign_locations() reads theme_mods_<stylesheet>, while
// apply_option_sub_keys() reads a manifest-owned live option blob.
$applyReflection = new ReflectionClass(Apply::class);
$applyConstructor = $applyReflection->getConstructor();
$apply = $applyReflection->newInstanceWithoutConstructor();
$applyConstructor->invoke($apply, '/unused', $policy(false), $compiled);
$assignLocations = new ReflectionMethod(Apply::class, 'assign_locations');
// DUO-3347 slice 7: apply_option_sub_keys() moved from Apply onto
// OptionsMaterializer (Apply keeps only apply_options() as a facade). Fetched
// via Apply's own options_materializer() factory rather than hand-built, so
// this test's OptionsMaterializer is wired with the exact same Policy/Tokens/
// ApplyFieldMaterializer instances the real facade would use.
$applyOptionSubKeys = new ReflectionMethod(\Duo\OptionsMaterializer::class, 'apply_option_sub_keys');
$optionsMaterializerAccessor = new ReflectionMethod(Apply::class, 'options_materializer');
$optionsMaterializer = $optionsMaterializerAccessor->invoke($apply);

$wpdb->optionRows = [
    'stylesheet' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'theme_mods_fixture-theme' => [
        'option_value' => serialize(['nav_menu_locations' => ['primary' => 7], 'unmanaged' => 'keep']),
        'autoload' => 'yes',
    ],
];
$wpdb->writes = [];
$assignLocations->invoke($apply, 42, ['footer']);
$assignWrite = $wpdb->writes[array_key_last($wpdb->writes)] ?? null;
$check(
    is_array($assignWrite)
        && PlainData::decode((string) ($assignWrite['data']['option_value'] ?? ''), 'Apply assign_locations test output')
            === [
                'nav_menu_locations' => ['primary' => 7, 'footer' => 42],
                'unmanaged' => 'keep',
            ],
    'Apply assign_locations preserves exact serialized array semantics while merging locations'
);

$wpdb->optionRows['theme_mods_fixture-theme']['option_value'] = serialize([
    'nav_menu_locations' => ['primary' => 42, 'footer' => 7],
    'unmanaged' => 'keep',
]);
$wpdb->writes = [];
$assignLocations->invoke($apply, 42, []);
$deleteLocationWrite = $wpdb->writes[array_key_last($wpdb->writes)] ?? null;
// DUO-3347 slice 12 moved delete_entity() itself off Apply onto
// DeleteExecutor, which calls assign_locations() on its own
// constructor-injected MenuMaterializer directly rather than through
// Apply's facade (the same "calling back through Apply would be circular"
// reasoning every sibling extraction in this series applies) -- so the
// exact call-site text this sanity check looks for now lives in
// DeleteExecutor.php, not Apply.php.
$deleteExecutorSourceForMenuDelete = file_get_contents(__DIR__ . '/../../agent/src/DeleteExecutor.php');
$check(
    is_array($deleteLocationWrite)
        && PlainData::decode(
            (string) ($deleteLocationWrite['data']['option_value'] ?? ''),
            'Apply menu deletion location output'
        ) === [
            'nav_menu_locations' => ['footer' => 7],
            'unmanaged' => 'keep',
        ]
        && is_string($deleteExecutorSourceForMenuDelete)
        && str_contains($deleteExecutorSourceForMenuDelete, '$this->menuMaterializer->assign_locations($termId, []);'),
    'menu deletion removes only the selected term locations and preserves other menu assignments'
);

$wpdb->optionRows['theme_mods_fixture-theme']['option_value'] = 'ordinary scalar string';
$wpdb->writes = [];
$assignLocations->invoke($apply, 42, ['footer']);
$assignScalarWrite = $wpdb->writes[array_key_last($wpdb->writes)] ?? null;
$check(
    is_array($assignScalarWrite)
        && PlainData::decode((string) ($assignScalarWrite['data']['option_value'] ?? ''), 'Apply scalar test output')
            === ['nav_menu_locations' => ['footer' => 42]],
    'Apply assign_locations preserves ordinary nonserialized-string semantics'
);

$wpdb->optionRows['theme_mods_fixture-theme']['option_value'] = serialize([
    'nav_menu_locations' => ['primary' => 7],
    'unmanaged' => 'keep',
]);

$wpdb->optionRows['owned_blob'] = [
    'option_value' => serialize(['owned' => 'old', 'runtime' => 'keep']),
    'autoload' => 'yes',
];
$subKeysWarnings = [];
$applyOptionSubKeys->invokeArgs($optionsMaterializer, [
    'owned_blob',
    ['owned' => 'new'],
    ['owned' => ['class' => 'authored'], 'runtime' => ['class' => 'runtime']],
    'yes',
    &$subKeysWarnings,
]);
$subKeysWrite = $wpdb->writes[array_key_last($wpdb->writes)] ?? null;
$check(
    is_array($subKeysWrite)
        && PlainData::decode((string) ($subKeysWrite['data']['option_value'] ?? ''), 'Apply sub-key test output')
            === ['owned' => 'new', 'runtime' => 'keep'],
    'Apply apply_option_sub_keys preserves exact serialized array semantics while merging authored keys'
);

$wpdb->optionRows['theme_mods_fixture-theme']['option_value'] = serialize(new ApplySerializedWakeupProbe());
ApplySerializedWakeupProbe::$woke = false;
ApplySerializedWakeupProbe::$unserialized = false;
$applyAssignObjectRejected = false;
try {
    $assignLocations->invoke($apply, 42, ['footer']);
} catch (Throwable $e) {
    $applyAssignObjectRejected = str_contains($e->getMessage(), 'PHP object');
}
$check($applyAssignObjectRejected && !ApplySerializedWakeupProbe::$woke && !ApplySerializedWakeupProbe::$unserialized,
    'Apply assign_locations rejects serialized objects without invoking __wakeup/__unserialize');

$wpdb->optionRows['owned_blob']['option_value'] = serialize(new ApplySerializedWakeupProbe());
ApplySerializedWakeupProbe::$woke = false;
ApplySerializedWakeupProbe::$unserialized = false;
$applySubKeysObjectRejected = false;
try {
    $objectRejectWarnings = [];
    $applyOptionSubKeys->invokeArgs($optionsMaterializer, [
        'owned_blob',
        ['owned' => 'new'],
        ['owned' => ['class' => 'authored']],
        'yes',
        &$objectRejectWarnings,
    ]);
} catch (Throwable $e) {
    $applySubKeysObjectRejected = str_contains($e->getMessage(), 'PHP object');
}
$check($applySubKeysObjectRejected && !ApplySerializedWakeupProbe::$woke && !ApplySerializedWakeupProbe::$unserialized,
    'Apply apply_option_sub_keys rejects serialized objects without invoking __wakeup/__unserialize');

// Restore the lifecycle fixture's baseline after the direct Apply probes;
// the following snapshot/hash comparisons intentionally start from this exact
// four-option target state.
$wpdb->optionRows = [
    'authored_setting' => ['option_value' => 'site-value', 'autoload' => 'yes'],
    'active_plugins' => ['option_value' => serialize(['fixture/fixture.php']), 'autoload' => 'yes'],
    'template' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'stylesheet' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
];

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

// 2b. Capture's own options-only builder must reject a malformed local-id
// spelling even when a legacy manifest regex is broad. Keep the owning table
// out of this synthetic policy so Snapshot's preservation prune is skipped;
// this isolates the OptionsCapture consumer rather than merely
// re-testing Snapshot's live scan.
$captureMalformedPolicy = $policy(false);
$captureMalformedPolicy->manifests[0]['option_name_refs'] = [[
    'class' => 'authored',
    'id_kind' => 'wc_zone_method',
    'match' => '^woocommerce_[a-z0-9_]+_(?<id>[0-9]+)_settings$',
    'autoload' => 'yes',
]];
$wpdb->optionRows['woocommerce_flat_rate_0003_settings'] = [
    'option_value' => serialize(['title' => 'Malformed']),
    'autoload' => 'yes',
];
$captureMalformedRejected = false;
try {
    Capture::snapshot_options_core('/unused', false, $compiled, $captureMalformedPolicy);
} catch (Throwable $e) {
    $captureMalformedRejected = str_contains($e->getMessage(), 'invalid local id');
}
$check($captureMalformedRejected,
    'Capture options discovery refuses a leading-zero local id instead of silently dropping the live option');
unset($wpdb->optionRows['woocommerce_flat_rate_0003_settings']);

// 2c. Capture's option-name-ref values cross an untrusted PHP-serialization
// boundary. A canonical Woo settings array is accepted, while a trailing
// serialized value and a supplied object are rejected before either can be
// captured. The wakeup probe proves allowed_classes=false is active at the
// decode boundary, rather than merely relying on a later plain-data shape gate.
$CAPTURE_ZONE_METHOD_UUID = '33333333-3333-4333-8333-333333333333';
$wpdb->optionRows = [
    'authored_setting' => ['option_value' => 'site-value', 'autoload' => 'yes'],
    'active_plugins' => ['option_value' => serialize(['fixture/fixture.php']), 'autoload' => 'yes'],
    'template' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'stylesheet' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'woocommerce_flat_rate_3_settings' => [
        'option_value' => serialize([
            'title' => 'Flat rate',
            'cost' => '5.99',
            'tax_status' => 'none',
        ]),
        'autoload' => 'yes',
    ],
];
$wpdb->map = [[
    'uuid' => $CAPTURE_ZONE_METHOD_UUID,
    'entity_type' => 'woocommerce_shipping_zone_methods',
    'kind' => 'wc_zone_method',
    'id' => 3,
]];
$captureValueSnapshot = Capture::snapshot_options_core('/unused', false, $compiled, $captureMalformedPolicy);
$captureValueRecords = OptionState::records(Canon::decode($captureValueSnapshot['options/core']['content']));
$captureValueName = 'woocommerce_flat_rate_{{wc_zone_method:' . $CAPTURE_ZONE_METHOD_UUID . '}}_settings';
$check(
    ($captureValueRecords[$captureValueName]['state'] ?? null) === 'present'
        && ($captureValueRecords[$captureValueName]['value']['title'] ?? null) === 'Flat rate'
        && ($captureValueRecords[$captureValueName]['value']['cost'] ?? null) === '5.99',
    'Capture accepts canonical serialized WooCommerce settings'
);

$wpdb->optionRows['woocommerce_flat_rate_3_settings']['option_value'] =
    serialize(['title' => 'Flat rate']) . 'i:42;';
$captureTrailingRejected = false;
try {
    Capture::snapshot_options_core('/unused', false, $compiled, $captureMalformedPolicy);
} catch (Throwable $e) {
    $captureTrailingRejected = str_contains($e->getMessage(), 'trailing or noncanonical');
}
$check($captureTrailingRejected,
    'Capture refuses option-name-ref serialized values with trailing payload bytes');

CaptureSerializedWakeupProbe::$woke = false;
CaptureSerializedWakeupProbe::$unserialized = false;
$wpdb->optionRows['woocommerce_flat_rate_3_settings']['option_value'] =
    serialize(new CaptureSerializedWakeupProbe());
$captureObjectRejected = false;
try {
    Capture::snapshot_options_core('/unused', false, $compiled, $captureMalformedPolicy);
} catch (Throwable $e) {
    $captureObjectRejected = str_contains($e->getMessage(), 'PHP object');
}
$check(
    $captureObjectRejected
        && !CaptureSerializedWakeupProbe::$woke
        && !CaptureSerializedWakeupProbe::$unserialized,
    'Capture refuses serialized option objects without invoking __wakeup/__unserialize'
);

// 2c-menu. scope_menus()'s active-theme location reader is a separate raw
// wp_options consumer from the option-name-ref path above. Invoke the real
// private method with one menu term so this regression proves the exact SQL,
// native array/scalar behavior, and the no-hook object boundary at the point
// where locations are actually consumed.
$captureReflection = new ReflectionClass(Capture::class);
$captureConstructor = $captureReflection->getConstructor();
$captureForMenus = $captureReflection->newInstanceWithoutConstructor();
$captureConstructor->invoke($captureForMenus, '/unused', $policy(false));
$scopeMenus = new ReflectionMethod(Capture::class, 'scope_menus');
$captureOptionRowsBeforeMenus = $wpdb->optionRows;
$wpdb->menuTerms = [(object) [
    'term_id' => 7,
    'name' => 'Primary Menu',
    'slug' => 'primary-menu',
    'term_taxonomy_id' => 70,
    'taxonomy' => 'nav_menu',
    'description' => '',
    'parent' => 0,
]];
$wpdb->termUuidById = [7 => '44444444-4444-4444-8444-444444444444'];
$wpdb->optionRows = [
    'stylesheet' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'theme_mods_fixture-theme' => [
        'option_value' => serialize(['nav_menu_locations' => ['primary' => 7]]),
        'autoload' => 'yes',
    ],
];
$wpdb->queries = [];
$menuArraySnapshot = $scopeMenus->invoke($captureForMenus, false);
$rawThemeModsQueries = array_values(array_filter(
    $wpdb->queries,
    static fn(string $sql): bool => str_contains(
        $sql,
        'SELECT option_value FROM wp_options WHERE option_name = %s LIMIT 1'
    )
));
$check(
    ($menuArraySnapshot[0]['front']['locations'] ?? null) === ['primary']
        && count($rawThemeModsQueries) === 1,
    'Capture menu locations reads the exact theme_mods row and preserves serialized arrays'
);

$wpdb->optionRows['theme_mods_fixture-theme']['option_value'] = 'ordinary scalar string';
$menuScalarSnapshot = $scopeMenus->invoke($captureForMenus, false);
$check(
    ($menuScalarSnapshot[0]['front']['locations'] ?? null) === [],
    'Capture menu locations preserves WordPress ordinary-scalar semantics'
);

$wpdb->optionRows['theme_mods_fixture-theme']['option_value'] = serialize(7);
$menuSerializedScalarSnapshot = $scopeMenus->invoke($captureForMenus, false);
$check(
    ($menuSerializedScalarSnapshot[0]['front']['locations'] ?? null) === [],
    'Capture menu locations preserves serialized-scalar semantics'
);

unset($wpdb->optionRows['theme_mods_fixture-theme']);
$menuMissingSnapshot = $scopeMenus->invoke($captureForMenus, false);
$check(
    ($menuMissingSnapshot[0]['front']['locations'] ?? null) === [],
    'Capture menu locations preserves get_option false-default semantics for a missing row'
);

CaptureSerializedWakeupProbe::$woke = false;
CaptureSerializedWakeupProbe::$unserialized = false;
$wpdb->optionRows['theme_mods_fixture-theme']['option_value'] =
    serialize(new CaptureSerializedWakeupProbe());
$menuObjectRejected = false;
try {
    $scopeMenus->invoke($captureForMenus, false);
} catch (Throwable $e) {
    $menuObjectRejected = str_contains($e->getMessage(), 'PHP object');
}
$check(
    $menuObjectRejected
        && !CaptureSerializedWakeupProbe::$woke
        && !CaptureSerializedWakeupProbe::$unserialized,
    'Capture menu locations rejects serialized objects without invoking __wakeup/__unserialize'
);
$wpdb->optionRows = $captureOptionRowsBeforeMenus;

$wpdb->optionRows['woocommerce_flat_rate_3_settings']['option_value'] =
    'a:1:{i:0;a:1:{i:0;R:2;}}';
$captureReferenceRejected = false;
try {
    Capture::snapshot_options_core('/unused', false, $compiled, $captureMalformedPolicy);
} catch (Throwable $e) {
    $captureReferenceRejected = str_contains($e->getMessage(), 'reference or recursive array');
}
$check($captureReferenceRejected,
    'Capture refuses canonical recursive PHP references before plain-data traversal');

// 2d. SidebarState's three raw option readers use the same strict boundary:
// the widget-family loader, sidebars_widgets assignment loader, and widget
// witness. Exercise each reader with the same canonical values and then with
// every hostile shape the boundary must refuse. This is deliberately an
// actual SidebarState call (the loader methods are private only because they
// are implementation details, so this offline fixture invokes them through
// Reflection rather than reimplementing their SQL or parsing).
$sidebarPolicy = $policy(false);
$sidebarPolicy->manifests[0]['widgets'] = [
    'text' => ['settings' => ['title' => ['class' => 'authored']]],
];
$sidebarDeclared = $sidebarPolicy->widget_types();
$sidebarLoadWidgets = new ReflectionMethod(SidebarState::class, 'load_widget_options');
$sidebarLoadSidebars = new ReflectionMethod(SidebarState::class, 'load_sidebars_option');
$sidebarWidgetValue = [3 => ['title' => 'Hello'], '_multiwidget' => 1];
$sidebarAssignmentsValue = ['sidebar-1' => ['text-3'], 'array_version' => 3];
$wpdb->optionRows = [
    'widget_text' => ['option_value' => serialize($sidebarWidgetValue), 'autoload' => 'yes'],
    'sidebars_widgets' => ['option_value' => serialize($sidebarAssignmentsValue), 'autoload' => 'yes'],
];
$loadedSidebarWidgets = $sidebarLoadWidgets->invoke(null, $sidebarPolicy, $sidebarDeclared, true);
$loadedSidebarAssignments = $sidebarLoadSidebars->invoke(null);
$check(
    ($loadedSidebarWidgets['text'][3]['title'] ?? null) === 'Hello',
    'SidebarState widget loader accepts canonical serialized widget settings'
);
$check(
    ($loadedSidebarAssignments['sidebar-1'] ?? null) === ['text-3'],
    'SidebarState sidebars_widgets loader accepts canonical serialized assignments'
);
$expectedSidebarWitness = hash('sha256', Canon::encode([
    'kind' => SidebarState::kind('text'), 'local_id' => 3, 'settings' => $sidebarWidgetValue[3],
]));
$check(
    SidebarState::witness('text', 3) === $expectedSidebarWitness,
    'SidebarState witness accepts canonical serialized widget settings'
);

// Scoped sidebar mutation is bounded twice: Apply gives the allocator only
// selected sidebar rows, and the finalizer writes only widget families that
// selected rows actually touch. An unrelated desired source sidebar whose
// widget is absent from the target must not acquire a map row or have its
// option family rewritten merely because selected A has work.
$sidebarPolicy->manifests[0]['widgets']['block'] = [
    'settings' => ['content' => ['class' => 'authored']],
];
$selectedSidebarWidget = '00000000-0000-4000-8000-000000000701';
$protectedSidebarWidget = '00000000-0000-4000-8000-000000000702';
$selectedSidebarTree = [
    'sidebar/selected' => [
        'type' => SidebarState::ENTITY_TYPE,
        'data' => ['widgets' => [[
            'uuid' => $selectedSidebarWidget,
            'type' => 'text',
            'settings' => ['title' => 'Selected'],
        ]]],
    ],
];
$completeSidebarTree = $selectedSidebarTree + [
    'sidebar/protected' => [
        'type' => SidebarState::ENTITY_TYPE,
        'data' => ['widgets' => [[
            'uuid' => $protectedSidebarWidget,
            'type' => 'block',
            'settings' => ['content' => 'Protected'],
        ]]],
    ],
];
$wpdb->map = [];
$wpdb->queryCalls = [];
$wpdb->optionRows = [
    'widget_text' => ['option_value' => serialize(['_multiwidget' => 1]), 'autoload' => 'yes'],
    'widget_block' => ['option_value' => serialize([9 => ['content' => 'Protected'], '_multiwidget' => 1]), 'autoload' => 'yes'],
    'sidebars_widgets' => ['option_value' => serialize([
        'selected' => [], 'protected' => ['block-9'], 'array_version' => 3,
    ]), 'autoload' => 'yes'],
];
SidebarState::ensure_widgets($sidebarPolicy, $selectedSidebarTree);
$allocatedWidgetUuids = [];
foreach ($wpdb->queryCalls as $call) {
    if (str_contains($call['sql'], 'INSERT INTO wp_duo_map')) {
        $allocatedWidgetUuids[] = (string) ($call['args'][0] ?? '');
    }
}
$authoredExecutorSource = file_get_contents(__DIR__ . '/../../agent/src/AuthoredTransactionExecutor.php');
$normalizedAuthoredExecutorSource = is_string($authoredExecutorSource)
    ? preg_replace('/\s+/', ' ', $authoredExecutorSource)
    : null;
$check(
    is_string($normalizedAuthoredExecutorSource)
        && str_contains(
            $normalizedAuthoredExecutorSource,
            '$this->optionsMaterializer->apply_options( $document, $withDeletes, $warnings, $classificationDocument );'
        )
        && str_contains(
            $normalizedAuthoredExecutorSource,
            '($this->recheckDeleteGuard)( $row, $deleteUuids, $compiledDeletions, $forceDeleteReferenced, $tree, $guardRepairUuids, true );'
        ),
    'authored transaction keeps option tombstone authority separate from forced reference deletion'
);
$check(
    $allocatedWidgetUuids === [$selectedSidebarWidget]
        && !in_array($protectedSidebarWidget, $allocatedWidgetUuids, true)
        && is_string($authoredExecutorSource)
        && str_contains($authoredExecutorSource, 'array_intersect_key($tree, ScopedApply::selected_set($scopeContract))'),
    'scoped sidebar allocation receives the selected projection and never maps an unselected desired widget'
);

$wpdb->map = [
    ['uuid' => $selectedSidebarWidget, 'entity_type' => 'widget', 'kind' => SidebarState::kind('text'), 'id' => 1],
    ['uuid' => $protectedSidebarWidget, 'entity_type' => 'widget', 'kind' => SidebarState::kind('block'), 'id' => 9],
];
$wpdb->queryCalls = [];
$GLOBALS['lifecycle_cache_deletes'] = [];
SidebarState::finalize_sidebar(
    $sidebarPolicy,
    $tokens,
    $selectedSidebarTree['sidebar/selected']['data'],
    'selected',
    $completeSidebarTree,
    true
);
$widgetOptionWrites = [];
$storedSidebarAssignments = null;
foreach ($wpdb->queryCalls as $call) {
    if (str_contains($call['sql'], 'INSERT INTO wp_options')
        && isset($call['args'][0]) && is_string($call['args'][0])) {
        if (str_starts_with($call['args'][0], 'widget_')) {
            $widgetOptionWrites[] = $call['args'][0];
        } elseif (str_contains($call['sql'], "VALUES ('sidebars_widgets'")) {
            $storedSidebarAssignments = maybe_unserialize($call['args'][0]);
        }
    }
}
$cacheDeleteKeys = array_map(
    static fn(array $args): string => (string) ($args[0] ?? ''),
    $GLOBALS['lifecycle_cache_deletes']
);
$check(
    $widgetOptionWrites === ['widget_text']
        && !in_array('widget_block', $cacheDeleteKeys, true)
        && is_array($storedSidebarAssignments)
        && ($storedSidebarAssignments['protected'] ?? null) === ['block-9'],
    'scoped sidebar finalization writes only selected touched families and preserves protected assignments/cache state'
);

// Restore the canonical loader fixture used by the hostile-value matrix.
$wpdb->optionRows = [
    'widget_text' => ['option_value' => serialize($sidebarWidgetValue), 'autoload' => 'yes'],
    'sidebars_widgets' => ['option_value' => serialize($sidebarAssignmentsValue), 'autoload' => 'yes'],
];
$check(
    PlainData::decode('i:7;', 'scalar') === 7
        && PlainData::decode('b:0;', 'false') === false
        && PlainData::decode('N;', 'null') === null
        && PlainData::decode('42', 'plain') === '42',
    'shared boundary preserves WordPress scalar and ordinary-string semantics'
);
$sidebarExpectReject = static function (callable $fn, string $needle, string $message) use ($check): void {
    try {
        $fn();
        $check(false, "$message (accepted hostile value)");
    } catch (Throwable $e) {
        $check(str_contains($e->getMessage(), $needle), "$message ({$e->getMessage()})");
    }
};

SidebarSerializedWakeupProbe::$woke = false;
$wpdb->optionRows['widget_text']['option_value'] = serialize(new SidebarSerializedWakeupProbe());
$sidebarExpectReject(
    fn() => $sidebarLoadWidgets->invoke(null, $sidebarPolicy, $sidebarDeclared, true),
    'PHP object',
    'SidebarState widget loader rejects serialized objects before __wakeup'
);
$check(!SidebarSerializedWakeupProbe::$woke, 'SidebarState widget loader never invokes serialized object __wakeup');

SidebarSerializedWakeupProbe::$woke = false;
$wpdb->optionRows['sidebars_widgets']['option_value'] = serialize(new SidebarSerializedWakeupProbe());
$sidebarExpectReject(
    fn() => $sidebarLoadSidebars->invoke(null),
    'PHP object',
    'SidebarState sidebars_widgets loader rejects serialized objects before __wakeup'
);
$check(!SidebarSerializedWakeupProbe::$woke, 'SidebarState sidebars_widgets loader never invokes serialized object __wakeup');

SidebarSerializedWakeupProbe::$woke = false;
$wpdb->optionRows['widget_text']['option_value'] = serialize(new SidebarSerializedWakeupProbe());
$sidebarExpectReject(
    fn() => SidebarState::witness('text', 3),
    'PHP object',
    'SidebarState witness rejects serialized objects before __wakeup'
);
$check(!SidebarSerializedWakeupProbe::$woke, 'SidebarState witness never invokes serialized object __wakeup');

$wpdb->optionRows['widget_text']['option_value'] = serialize($sidebarWidgetValue) . 'i:42;';
$sidebarExpectReject(
    fn() => $sidebarLoadWidgets->invoke(null, $sidebarPolicy, $sidebarDeclared, true),
    'trailing or noncanonical',
    'SidebarState widget loader rejects trailing serialized payloads'
);
$wpdb->optionRows['sidebars_widgets']['option_value'] = serialize($sidebarAssignmentsValue) . 'i:42;';
$sidebarExpectReject(
    fn() => $sidebarLoadSidebars->invoke(null),
    'trailing or noncanonical',
    'SidebarState sidebars_widgets loader rejects trailing serialized payloads'
);
$wpdb->optionRows['widget_text']['option_value'] = serialize($sidebarWidgetValue) . 'i:42;';
$sidebarExpectReject(
    fn() => SidebarState::witness('text', 3),
    'trailing or noncanonical',
    'SidebarState witness rejects trailing serialized payloads'
);

$sidebarRecursive = 'a:1:{i:0;a:1:{i:0;R:2;}}';
$wpdb->optionRows['widget_text']['option_value'] = $sidebarRecursive;
$sidebarExpectReject(
    fn() => $sidebarLoadWidgets->invoke(null, $sidebarPolicy, $sidebarDeclared, true),
    'reference or recursive array',
    'SidebarState widget loader rejects canonical recursive/reference arrays'
);
$wpdb->optionRows['sidebars_widgets']['option_value'] = $sidebarRecursive;
$sidebarExpectReject(
    fn() => $sidebarLoadSidebars->invoke(null),
    'reference or recursive array',
    'SidebarState sidebars_widgets loader rejects canonical recursive/reference arrays'
);
$wpdb->optionRows['widget_text']['option_value'] = $sidebarRecursive;
$sidebarExpectReject(
    fn() => SidebarState::witness('text', 3),
    'reference or recursive array',
    'SidebarState witness rejects canonical recursive/reference arrays'
);

$sidebarDeep = 'i:1;';
for ($i = 0; $i <= PlainData::MAX_DEPTH; $i++) {
    $sidebarDeep = 'a:1:{i:0;' . $sidebarDeep . '}';
}
$wpdb->optionRows['widget_text']['option_value'] = $sidebarDeep;
$sidebarExpectReject(
    fn() => $sidebarLoadWidgets->invoke(null, $sidebarPolicy, $sidebarDeclared, true),
    'nested too deeply',
    'SidebarState widget loader rejects excessively deep serialized arrays'
);
$wpdb->optionRows['sidebars_widgets']['option_value'] = $sidebarDeep;
$sidebarExpectReject(
    fn() => $sidebarLoadSidebars->invoke(null),
    'nested too deeply',
    'SidebarState sidebars_widgets loader rejects excessively deep serialized arrays'
);
$wpdb->optionRows['widget_text']['option_value'] = $sidebarDeep;
$sidebarExpectReject(
    fn() => SidebarState::witness('text', 3),
    'nested too deeply',
    'SidebarState witness rejects excessively deep serialized arrays'
);

$wpdb->optionRows = [
    'authored_setting' => ['option_value' => 'site-value', 'autoload' => 'yes'],
    'active_plugins' => ['option_value' => serialize(['fixture/fixture.php']), 'autoload' => 'yes'],
    'template' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'stylesheet' => ['option_value' => 'fixture-theme', 'autoload' => 'yes'],
    'woocommerce_flat_rate_42_settings' => [
        'option_value' => serialize(['title' => 'Flat rate']),
        'autoload' => 'yes',
    ],
];
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

// 2c. A lifecycle snapshot spans the switch from the target's currently
// active theme to the artifact's desired theme. The dynamic option resolver
// must remain pinned to that frozen desired stylesheet on BOTH sides of the
// hook window; otherwise the canonical name itself changes mid-handoff.
$dynamicPolicy = $policy(false);
$dynamicPolicy->manifests[0]['dynamic_options'] = [
    'theme_mods' => [
        'prefix' => 'theme_mods_',
        'resolver' => 'active_stylesheet',
        'autoload' => 'preserve',
        'sub_keys' => [
            'background_color' => ['class' => 'authored'],
            'sidebars_widgets' => ['class' => 'runtime'],
        ],
    ],
];
$desiredThemeMods = $present(['background_color' => 'desired-green']);
$dynamicDesired = OptionState::document([
    'authored_setting' => $present('site-value'),
    'active_plugins' => $present(['fixture/fixture.php']),
    'template' => $present('target-theme'),
    'stylesheet' => $present('target-theme'),
    'theme_mods_target-theme' => $desiredThemeMods,
]);
$dynamicCompiled = CompiledRepository::create([
    'tree' => ['options/core' => ['type' => 'options', 'path' => 'options/core.json', 'data' => $dynamicDesired]],
    'deletions' => [],
    'revision_hash' => str_repeat('c', 64),
    'manifest_hash' => str_repeat('d', 64),
]);
$wpdb->optionRows = [
    'authored_setting' => ['option_value' => 'site-value', 'autoload' => 'yes'],
    'active_plugins' => ['option_value' => serialize(['fixture/fixture.php']), 'autoload' => 'yes'],
    'template' => ['option_value' => 'source-theme', 'autoload' => 'yes'],
    'stylesheet' => ['option_value' => 'source-theme', 'autoload' => 'yes'],
    'theme_mods_source-theme' => [
        'option_value' => serialize(['background_color' => 'source-blue']),
        'autoload' => 'yes',
    ],
    'theme_mods_target-theme' => [
        'option_value' => serialize([
            'background_color' => 'stale-red',
            'sidebars_widgets' => ['time' => 1234],
        ]),
        'autoload' => 'yes',
    ],
];
$dynamicSnapshot = Capture::snapshot_options_core('/unused', false, $dynamicCompiled, $dynamicPolicy);
$dynamicRecords = OptionState::records(Canon::decode($dynamicSnapshot['options/core']['content']));
$check(
    ($dynamicRecords['theme_mods_target-theme']['value']['background_color'] ?? null) === 'stale-red',
    'lifecycle snapshot resolves theme_mods from the frozen desired stylesheet before switch_theme'
);
$check(
    !array_key_exists('theme_mods_source-theme', $dynamicRecords),
    'lifecycle snapshot excludes the live source-theme residue from its fixed handoff domain'
);

unset($wpdb->optionRows['theme_mods_target-theme']);
$missingDynamicSnapshot = Capture::snapshot_options_core('/unused', false, $dynamicCompiled, $dynamicPolicy);
$missingDynamicRecords = OptionState::records(Canon::decode($missingDynamicSnapshot['options/core']['content']));
$check(
    ($missingDynamicRecords['theme_mods_target-theme']['state'] ?? null) === 'deleted'
        && hash_equals(
            (string) ($missingDynamicRecords['theme_mods_target-theme']['expected_hash'] ?? ''),
            OptionState::record_hash($desiredThemeMods)
        ),
    'a missing desired theme_mods row is cryptographically bound to frozen desired state'
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

$themeHookAfter = OptionState::document([
    'theme_mods_target-theme' => $present(['background_color' => 'hook-default']),
]);
$themeDesired = OptionState::document([
    'theme_mods_target-theme' => $desiredThemeMods,
]);
$check(
    $recordGate->invoke(
        null,
        OptionState::document(['theme_mods_target-theme' => $missingDynamicRecords['theme_mods_target-theme']]),
        $themeHookAfter,
        $themeDesired
    ) === [],
    'bound first-switch theme_mods initialization is accepted for later exact apply reconciliation'
);
$check(
    $recordGate->invoke(
        null,
        OptionState::document(['theme_mods_target-theme' => $present(['background_color' => 'stale-red'])]),
        OptionState::document(['theme_mods_target-theme' => $present(['background_color' => 'unrelated-edit'])]),
        $themeDesired
    ) === ['theme_mods_target-theme'],
    'an unrelated mutation of an existing authored theme_mods value remains fail-closed'
);

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
