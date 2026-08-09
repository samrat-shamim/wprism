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

use Duo\Ledger;
use Duo\RefreshExport;

function fail_re(string $message): never { throw new RuntimeException("FAIL: $message"); }
function check_re(bool $ok, string $message): void { if (!$ok) fail_re($message); }

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
$wpdb = new RefreshExportReadOnlyWpdb();
$wpdb->maps = [['uuid'=>$uuid, 'entity_type'=>'post', 'id_kind'=>'post', 'local_id'=>7]];
$GLOBALS['wpdb'] = $wpdb;
Ledger::assert_read_only_schema();
Ledger::require_read_only_mapping($uuid, 'post', 'post', 7, 'fixture post');
check_re($wpdb->queries === 0, 'read-only ledger helper attempted a mutation query');
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
echo "REGRESS_REFRESH_EXPORT_UNIT PASSED\n";
