<?php
/**
 * Offline boundary regression: only a fake SELECT-capable wpdb is present.
 * Any mutation query throws, so this proves the strict ledger/export helpers
 * are observers rather than capture repair paths.
 */
$root = realpath(__DIR__ . '/../..');
if ($root === false) throw new RuntimeException('FAIL: root missing');
define('ARRAY_A', 'ARRAY_A');
require_once $root . '/agent/src/Uuid.php';
require_once $root . '/agent/src/Db.php';
require_once $root . '/agent/src/Ledger.php';
require_once $root . '/agent/src/RefreshExport.php';
require_once $root . '/agent/src/Snapshot.php';
require_once $root . '/agent/src/Policy.php';
require_once $root . '/agent/src/Tokens.php';
require_once $root . '/agent/src/Capture.php';

use Duo\Capture;
use Duo\Canon;
use Duo\Ledger;
use Duo\OptionState;
use Duo\Policy;
use Duo\RefreshExport;
use Duo\Snapshot;
use Duo\Tokens;
use Duo\Uuid;

function fail_re(string $message): never { throw new RuntimeException("FAIL: $message"); }
function check_re(bool $ok, string $message): void { if (!$ok) fail_re($message); }
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $_taxonomy): false { return false; }
}

final class RefreshExportReadOnlyWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var list<array<string,mixed>> */
    public array $maps = [];
    public int $queries = 0;

    public function prepare(string $sql, mixed ...$args): string {
        foreach ($args as $arg) {
            $value = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $value, $sql, 1) ?? $sql;
        }
        return $sql;
    }
    public function query(string $sql): never {
        $this->queries++;
        throw new RuntimeException("FAIL: unexpected mutation query $sql");
    }
    /** @return list<array<string,mixed>> */
    public function get_results(string $sql, mixed $_output = null): array {
        if (str_contains($sql, 'information_schema.COLUMNS')) return $this->columns();
        if (str_contains($sql, 'information_schema.STATISTICS')) return $this->indexes();
        if (str_contains($sql, 'SELECT uuid, entity_type, id_kind, local_id FROM wp_duo_map')) return $this->maps;
        throw new RuntimeException("FAIL: unexpected inventory query $sql");
    }
    public function get_var(string $sql): mixed {
        if (preg_match("/WHERE id_kind = '([^']+)' AND local_id = ([0-9]+)/", $sql, $m) === 1) {
            foreach ($this->maps as $row) {
                if ($row['id_kind'] === $m[1] && (int) $row['local_id'] === (int) $m[2]) {
                    return $row['uuid'];
                }
            }
            return null;
        }
        throw new RuntimeException("FAIL: unexpected scalar query $sql");
    }
    /** @return ?array<string,mixed> */
    public function get_row(string $sql, mixed $_output = null): ?array {
        if (preg_match("/WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $sql, $m) === 1) {
            foreach ($this->maps as $row) if ($row['uuid'] === $m[1] && $row['id_kind'] === $m[2]) {
                return ['entity_type' => $row['entity_type'], 'local_id' => $row['local_id']];
            }
            return null;
        }
        if (preg_match("/WHERE id_kind = '([^']+)' AND local_id = ([0-9]+)/", $sql, $m) === 1) {
            foreach ($this->maps as $row) if ($row['id_kind'] === $m[1] && (int) $row['local_id'] === (int) $m[2]) {
                return ['uuid' => $row['uuid'], 'entity_type' => $row['entity_type']];
            }
            return null;
        }
        throw new RuntimeException("FAIL: unexpected row query $sql");
    }
    /** @return list<array<string,mixed>> */
    private function columns(): array {
        $out = [];
        $add = static function (string $table, string $name, string $type, int $length) use (&$out): void {
            $out[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $name, 'DATA_TYPE' => $type,
                'COLUMN_TYPE' => $type, 'CHARACTER_MAXIMUM_LENGTH' => $length];
        };
        $add('wp_duo_map', 'uuid', 'char(36)', 36);
        $add('wp_duo_map', 'entity_type', 'varchar(64)', 64);
        $add('wp_duo_map', 'id_kind', 'varchar(32)', 32);
        $add('wp_duo_map', 'local_id', 'bigint(20) unsigned', 0);
        $add('wp_duo_state', 'uuid', 'varchar(64)', 64);
        $add('wp_duo_state', 'entity_type', 'varchar(64)', 64);
        $add('wp_duo_state', 'content_hash', 'char(64)', 64);
        $add('wp_duo_kv', 'k', 'varchar(191)', 191);
        $add('wp_duo_kv', 'v', 'longtext', PHP_INT_MAX);
        return $out;
    }
    /** @return list<array<string,mixed>> */
    private function indexes(): array {
        return [
            ['TABLE_NAME'=>'wp_duo_map','INDEX_NAME'=>'PRIMARY','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'uuid'],
            ['TABLE_NAME'=>'wp_duo_map','INDEX_NAME'=>'PRIMARY','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>2,'COLUMN_NAME'=>'id_kind'],
            ['TABLE_NAME'=>'wp_duo_map','INDEX_NAME'=>'kind_local','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'id_kind'],
            ['TABLE_NAME'=>'wp_duo_map','INDEX_NAME'=>'kind_local','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>2,'COLUMN_NAME'=>'local_id'],
            ['TABLE_NAME'=>'wp_duo_state','INDEX_NAME'=>'PRIMARY','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'uuid'],
            ['TABLE_NAME'=>'wp_duo_kv','INDEX_NAME'=>'PRIMARY','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'COLUMN_NAME'=>'k'],
        ];
    }
}

$uuid = '123e4567-e89b-42d3-a456-426614174000';
$renamedNaturalUuid = Uuid::v5(
    Uuid::NAMESPACE_DUO,
    'woocommerce_attribute_taxonomies:original-name'
);
$wpdb = new RefreshExportReadOnlyWpdb();
$wpdb->maps = [
    ['uuid'=>$uuid, 'entity_type'=>'post', 'id_kind'=>'post', 'local_id'=>7],
    [
        'uuid'=>$renamedNaturalUuid,
        'entity_type'=>'woocommerce_attribute_taxonomies',
        'id_kind'=>'attr_taxonomy',
        'local_id'=>42,
    ],
];
$GLOBALS['wpdb'] = $wpdb;
Ledger::assert_read_only_schema();
Ledger::require_read_only_mapping($uuid, 'post', 'post', 7, 'fixture post');
check_re($wpdb->queries === 0, 'read-only ledger helper attempted a mutation query');

$identify = new ReflectionMethod(Snapshot::class, 'identify_row');
$identify->setAccessible(true);
// DUO-3318: identify_row() takes the capture-direction tokenizer, because a
// parent-scoped natural key's ref component derives from the REFERENCED row's
// uuid. The strict read-only branch under test returns before touching it —
// asserted below by the unchanged zero-mutation-query check — so an
// uninitialized instance is exactly the right fixture: it proves that path
// never reaches for one.
$identifyTokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$retained = $identify->invoke(null, 'woocommerce_attribute_taxonomies', [
    'id_kind' => 'attr_taxonomy',
    'identity' => ['mode' => 'natural_key', 'column' => 'attribute_name'],
], ['attribute_name' => 'renamed-value'], 42, $identifyTokens, false, true);
check_re($retained === $renamedNaturalUuid,
    'strict export did not preserve durable natural-key identity across an authored rename');
check_re($wpdb->queries === 0, 'natural-key continuity check attempted a mutation query');

// The isolated control bootstrap deliberately skips user plugins. An exact
// plugin taxonomy can therefore be in policy scope without being registered.
// Production truth must refuse that unknown relationship ownership rather
// than returning a warning plus a silently incomplete P snapshot.
$captureClass = new ReflectionClass(Capture::class);
$capture = $captureClass->newInstanceWithoutConstructor();
$policy = new Policy();
$tokensClass = new ReflectionClass(Tokens::class);
$tokens = $tokensClass->newInstanceWithoutConstructor();
foreach (['policy' => $policy, 'tokens' => $tokens] as $property => $value) {
    $slot = $captureClass->getProperty($property);
    $slot->setAccessible(true);
    $slot->setValue($capture, $value);
}
$taxonomies = $captureClass->getMethod('taxes_by_object_type');
$taxonomies->setAccessible(true);
try {
    $taxonomies->invoke($capture, ['plugin_exact_taxonomy'], ['post'], true);
    fail_re('strict export silently accepted an unregistered scoped plugin taxonomy');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'refresh export refused')
        && str_contains($e->getMessage(), 'plugin-owned'),
        'unregistered scoped taxonomy refusal was not actionable');
}
check_re($tokens->warnings === [], 'strict taxonomy refusal degraded to a warning');

try {
    Ledger::require_read_only_mapping($uuid, 'term', 'post', 7, 'contradictory fixture');
    fail_re('contradictory durable identity was accepted');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'contradicts'), 'contradiction did not fail loudly');
}

$source = file_get_contents($root . '/agent/src/RefreshExport.php');
if ($source === false) fail_re('cannot read exporter source');
$code = '';
foreach (token_get_all($source) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
    $code .= is_array($token) ? $token[1] : $token;
}
check_re(str_contains($source, 'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT'), 'missing read-only consistent snapshot');
check_re(preg_match('/Ledger::(?:ensure|set|forget|prune_state|prune_dead_map|prune_dead_table_map|kv_set|kv_delete)\(/', $code) !== 1, 'exporter calls a forbidden ledger mutation');
check_re(preg_match('/Snapshot::(?:repair_truncated_entity_types|prune_dead_map|prune_option_name_ref_map)\(/', $code) !== 1, 'exporter calls a forbidden snapshot repair');
check_re(!str_contains($code, 'Canon::write_file('), 'exporter writes filesystem state');

$records = new ReflectionMethod(RefreshExport::class, 'records');
$records->setAccessible(true);
$result = $records->invoke(null, [[
    'uuid'=>'options/core', 'type'=>'options', 'path'=>'options/core.json', 'content'=>"{}\n",
], [
    'uuid'=>$uuid, 'type'=>'post', 'path'=>"posts/post/$uuid--fixture.md", 'content'=>"visible\n", 'hash_basis'=>"semantic\n",
]]);
check_re(array_keys($result) === [$uuid, 'options/core'], 'records are not keyed by semantic identity');
check_re($result[$uuid]['hash'] === hash('sha256', "semantic\n"), 'post semantic hash basis was not retained');
check_re($result['options/core']['content'] === "{}\n", 'canonical content was not preserved');
try {
    $records->invoke(null, [[
        'uuid' => 'options/core', 'type' => 'options', 'path' => '../options/core.json', 'content' => "{}\n",
    ]]);
    fail_re('unsafe export record path was accepted');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'invalid'), 'unsafe path refusal was unclear');
}

// A per-option root is a virtual state identity. The real exporter helper
// must emit a minimal options/core carrier rather than accidentally sending
// every sibling option through the scoped-refresh wire envelope.
$optionContent = Canon::encode(OptionState::document([
    'blogdescription' => OptionState::present('excluded sibling', 'yes'),
    'blogname' => OptionState::present('selected title', 'yes'),
]));
$scopedLive = new ReflectionMethod(RefreshExport::class, 'scoped_live_entities');
$scopedLive->setAccessible(true);
$projected = $scopedLive->invoke(null, [
    ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
    ['uuid' => $uuid, 'type' => 'post', 'path' => "posts/post/$uuid--fixture.md", 'content' => "visible\n"],
], [$uuid, 'option:blogname'], ['blogname']);
$projectedOptions = OptionState::records(Canon::decode((string) $projected['options/core']['content']));
$projectedKeys = array_keys($projected);
sort($projectedKeys, SORT_STRING);
check_re($projectedKeys === [$uuid, 'options/core']
    && array_keys($projectedOptions) === ['blogname']
    && ($projectedOptions['blogname']['value'] ?? null) === 'selected title'
    && ($projected['options/core']['hash_basis'] ?? null) === (string) $projected['options/core']['content'],
    'option-root refresh projection emits only the selected record with matching carrier hash basis');
try {
    $scopedLive->invoke(null, [
        ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
    ], ['option:missing'], ['missing']);
    fail_re('option-root refresh projection accepted a missing selected record');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), "lost selected option 'missing'"),
        'missing selected option did not fail closed at the export boundary');
}
echo "REGRESS_REFRESH_EXPORT_UNIT PASSED\n";
