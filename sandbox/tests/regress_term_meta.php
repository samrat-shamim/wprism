<?php
/**
 * Offline DUO-3261 regression. Drives the real private capture/apply termmeta
 * product paths against a deterministic fake wpdb: WooCommerce's real
 * product_cat thumbnail_id captures as a post token, applies to a different
 * local attachment id, deletes a missing still-authored key, preserves every
 * non-authored/undeclared target row byte-for-byte, then recaptures to the
 * identical canonical value.
 */

define('ARRAY_A', 'ARRAY_A');
define('DUO_SPEC_VERSION', 2);

$duo_test_home = 'https://source.example.test';
function get_option(string $name) { global $duo_test_home; return $name === 'home' ? $duo_test_home : null; }
function wp_upload_dir($time = null, bool $create = false): array { global $duo_test_home; return ['baseurl' => $duo_test_home . '/wp-content/uploads']; }
function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
function is_serialized($value, $strict = true): bool {
    if (!is_string($value)) return false;
    $value = trim($value);
    if ($value === 'N;') return true;
    if (strlen($value) < 4 || $value[1] !== ':') return false;
    if ($strict) {
        $last = $value[strlen($value) - 1];
        if ($last !== ';' && $last !== '}') return false;
    }
    $token = $value[0];
    if ($token === 's') return !$strict || (($value[2] ?? '') === '"');
    return in_array($token, ['a', 'O', 'C', 'E', 'd', 'i', 'b'], true);
}
function maybe_unserialize($value) {
    if (!is_serialized($value)) return $value;
    $decoded = @unserialize(trim($value), ['allowed_classes' => false]);
    return $decoded === false && trim($value) !== 'b:0;' ? $value : $decoded;
}
function maybe_serialize($value) {
    return is_array($value) || is_object($value) || $value === null || is_bool($value)
        ? serialize($value)
        : $value;
}

require __DIR__ . '/../../agent/src/TransientDbException.php';
require __DIR__ . '/../../agent/src/Db.php';
require __DIR__ . '/../../agent/src/Uuid.php';
require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OrderPreserved.php';
require __DIR__ . '/../../agent/src/Secrets.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Tokens.php';
require __DIR__ . '/../../agent/src/EntityMetaCapture.php';
require __DIR__ . '/../../agent/src/Apply.php';

use Duo\Apply;
use Duo\EntityMetaCapture;
use Duo\Policy;
use Duo\Tokens;

final class TermMetaFakeWpdb {
    public string $prefix = 'wp_';
    public string $termmeta = 'wp_termmeta';
    public string $last_error = '';
    public int $insert_id = 0;
    /** @var array<int,array{meta_id:int,term_id:int,meta_key:string,meta_value:mixed}> */
    public array $rows = [];
    /** @var array<int,string> */
    public array $uuidById = [];
    /** @var array<string,int> */
    public array $idByUuid = [];

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function get_results(string $sql, $format = null): array {
        if (str_contains($sql, 'FROM wp_termmeta')) {
            preg_match('/term_id = ([0-9]+)/', $sql, $m);
            $termId = (int) ($m[1] ?? 0);
            $rows = array_values(array_filter($this->rows, fn(array $row): bool => $row['term_id'] === $termId));
            usort($rows, fn(array $a, array $b): int => str_contains($sql, 'meta_key ASC')
                ? [$a['meta_key'], $a['meta_id']] <=> [$b['meta_key'], $b['meta_id']]
                : $a['meta_id'] <=> $b['meta_id']);
            return array_map(fn(array $row): array => array_intersect_key($row, array_flip(['meta_id','meta_key','meta_value'])), $rows);
        }
        throw new RuntimeException("unrecognized get_results query: $sql");
    }

    public function get_var(string $sql) {
        if (str_contains($sql, 'SELECT uuid FROM wp_duo_map')) {
            preg_match("/local_id = ([0-9]+)/", $sql, $m);
            return $this->uuidById[(int) ($m[1] ?? 0)] ?? null;
        }
        if (str_contains($sql, 'SELECT local_id FROM wp_duo_map')) {
            preg_match("/uuid = '([^']+)'/", $sql, $m);
            return $this->idByUuid[$m[1] ?? ''] ?? null;
        }
        if (str_contains($sql, 'SELECT meta_id FROM wp_termmeta')) {
            preg_match('/term_id = ([0-9]+)/', $sql, $tm);
            preg_match("/meta_key = '([^']+)'/", $sql, $km);
            foreach ($this->rows as $row) {
                if ($row['term_id'] === (int) ($tm[1] ?? 0) && $row['meta_key'] === ($km[1] ?? '')) return $row['meta_id'];
            }
            return null;
        }
        throw new RuntimeException("unrecognized get_var query: $sql");
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null): int {
        foreach ($this->rows as &$row) {
            if (isset($where['meta_id']) && $row['meta_id'] === (int) $where['meta_id']) {
                $row = array_merge($row, $data);
                return 1;
            }
        }
        return 0;
    }

    public function delete(string $table, array $where, $whereFormat = null): int {
        $before = count($this->rows);
        $this->rows = array_values(array_filter($this->rows, function (array $row) use ($where): bool {
            foreach ($where as $key => $value) if ($row[$key] != $value) return true;
            return false;
        }));
        return $before - count($this->rows);
    }

    public function insert(string $table, array $data, $format = null): int {
        $this->insert_id = $this->rows ? max(array_column($this->rows, 'meta_id')) + 1 : 1;
        $this->rows[] = ['meta_id' => $this->insert_id] + $data;
        return 1;
    }
}

function set_private(object $object, string $property, $value): void {
    $rp = new ReflectionProperty($object, $property);
    $rp->setValue($object, $value);
}
function invoke_private(object $object, string $method, ...$args) {
    $rm = new ReflectionMethod($object, $method);
    return $rm->invoke($object, ...$args);
}
function assertion(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException("FAIL: $message");
    echo "ok: $message\n";
}
function capture_instance(Policy $policy, Tokens $tokens): EntityMetaCapture {
    return new EntityMetaCapture(
        $policy,
        $tokens,
        static function (): void {},
        static function (): void {},
        static function (string $finding): void {
            $GLOBALS['term_meta_unclassified'][] = $finding;
        }
    );
}

$uuid = '018f0000-0000-7000-8000-000000000001';
$GLOBALS['term_meta_unclassified'] = [];
$policy = new Policy();
$policy->site = ['policy' => ['term_meta' => [
    'thumbnail_id' => ['class' => 'authored', 'ref' => 'post'],
    'old_authored' => ['class' => 'authored'],
    'runtime_counter' => ['class' => 'runtime'],
]]];

$wpdb = new TermMetaFakeWpdb();
$wpdb->uuidById = [41 => $uuid];
$wpdb->idByUuid = [$uuid => 41];
$wpdb->rows = [
    ['meta_id' => 1, 'term_id' => 7, 'meta_key' => 'thumbnail_id', 'meta_value' => '41'],
];

$sourceTokens = new Tokens();
$capture = capture_instance($policy, $sourceTokens);
[$store, $canonical] = invoke_private(
    $capture, 'classifyValue', 'thumbnail_id', ['41'], ['thumbnail_id' => '41'],
    'term product_cat:widgets', 'term_meta', true
);
assertion($store && $canonical === '{{post:' . $uuid . '}}', 'capture tokenizes WooCommerce thumbnail_id as a canonical post ref');

$duo_test_home = 'https://target.example.test';
$targetTokens = new Tokens();
$wpdb->uuidById = [88 => $uuid];
$wpdb->idByUuid = [$uuid => 88];
$wpdb->rows = [
    ['meta_id' => 10, 'term_id' => 9, 'meta_key' => 'thumbnail_id', 'meta_value' => '999'],
    ['meta_id' => 11, 'term_id' => 9, 'meta_key' => 'old_authored', 'meta_value' => 'remove-me'],
    ['meta_id' => 12, 'term_id' => 9, 'meta_key' => 'runtime_counter', 'meta_value' => 'runtime-bytes'],
    ['meta_id' => 13, 'term_id' => 9, 'meta_key' => 'undeclared_plugin_key', 'meta_value' => "opaque\0bytes"],
];
$apply = new \Duo\ApplyFieldMaterializer($policy, $targetTokens);
$apply->reconcile_authored_term_meta(9, ['thumbnail_id' => $canonical]);

$byKey = [];
foreach ($wpdb->rows as $row) $byKey[$row['meta_key']] = $row['meta_value'];
assertion(($byKey['thumbnail_id'] ?? null) === '88', 'apply resolves the token to the target attachment id');
assertion(!array_key_exists('old_authored', $byKey), 'apply deletes an authored key omitted from canonical term meta');
assertion(($byKey['runtime_counter'] ?? null) === 'runtime-bytes', 'apply preserves runtime termmeta byte-for-byte');
assertion(($byKey['undeclared_plugin_key'] ?? null) === "opaque\0bytes", 'apply preserves undeclared termmeta byte-for-byte');

$recapture = capture_instance($policy, $targetTokens);
[$storedAgain, $canonicalAgain] = invoke_private(
    $recapture, 'classifyValue', 'thumbnail_id', [(string) $byKey['thumbnail_id']],
    ['thumbnail_id' => (string) $byKey['thumbnail_id']], 'term product_cat:widgets', 'term_meta', true
);
assertion($storedAgain && $canonicalAgain === $canonical, 'target recapture is byte-identical to source canonical term meta');

[$unknownStored] = invoke_private(
    $recapture, 'classifyValue', 'unknown_term_key', ['x'], ['unknown_term_key' => 'x'],
    'term product_cat:widgets', 'term_meta', true
);
$unclassified = $GLOBALS['term_meta_unclassified'];
assertion(!$unknownStored && $unclassified === ['term_meta:unknown_term_key'], 'only genuinely unclassified termmeta enters the loud blocker list');

echo "ALL PASSED\n";
