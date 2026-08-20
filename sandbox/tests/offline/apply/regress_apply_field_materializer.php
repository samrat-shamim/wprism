<?php
/**
 * Offline characterization for DUO-3347's shared authored-field layer.
 *
 * The low-level meta/option writes are driven through the extracted
 * ApplyFieldMaterializer against a deterministic fake wpdb. The source checks
 * keep Apply's compatibility methods as delegates, while the existing
 * regress_term_meta.php continues to exercise policy-aware reconciliation
 * through the real Apply facade.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!function_exists('maybe_serialize')) {
    function maybe_serialize($value) {
        return is_array($value) || is_object($value) || $value === null || is_bool($value)
            ? serialize($value)
            : $value;
    }
}

$cacheEvents = [];
function wp_cache_delete($key, $group = ''): bool {
    global $cacheEvents;
    $cacheEvents[] = [(string) $group, (string) $key];
    return true;
}

require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';

use Duo\ApplyFieldMaterializer;

final class ApplyFieldMaterializerFakeWpdb {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';
    public string $options = 'wp_options';
    public string $last_error = '';
    public int $insert_id = 0;
    /** @var list<array<string,mixed>> */
    public array $postMetaRows = [];
    /** @var list<array<string,mixed>> */
    public array $termMetaRows = [];
    /** @var list<array<string,mixed>> */
    public array $optionRows = [];

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function get_var(string $sql) {
        if (str_contains($sql, 'FROM wp_postmeta')) {
            return $this->findMetaId($this->postMetaRows, $sql, 'post_id');
        }
        if (str_contains($sql, 'FROM wp_termmeta')) {
            return $this->findMetaId($this->termMetaRows, $sql, 'term_id');
        }
        if (str_contains($sql, 'FROM wp_options')) {
            preg_match("/option_name = '((?:''|[^'])*)'/", $sql, $match);
            $name = str_replace("''", "'", (string) ($match[1] ?? ''));
            foreach ($this->optionRows as $row) {
                if ((string) $row['option_name'] === $name) {
                    return $row['option_id'];
                }
            }
            return null;
        }
        throw new RuntimeException("unrecognized get_var query: $sql");
    }

    private function findMetaId(array $rows, string $sql, string $foreignKey): ?int {
        preg_match("/$foreignKey = ([0-9]+)/", $sql, $foreignMatch);
        preg_match("/meta_key = '((?:''|[^'])*)'/", $sql, $keyMatch);
        $foreignId = (int) ($foreignMatch[1] ?? 0);
        $key = str_replace("''", "'", (string) ($keyMatch[1] ?? ''));
        foreach ($rows as $row) {
            if ((int) $row[$foreignKey] === $foreignId && (string) $row['meta_key'] === $key) {
                return (int) $row['meta_id'];
            }
        }
        return null;
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null): int {
        $rows =& $this->rowsFor($table);
        foreach ($rows as &$row) {
            $matches = true;
            foreach ($where as $key => $value) {
                if (($row[$key] ?? null) != $value) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $row = array_merge($row, $data);
                return 1;
            }
        }
        return 0;
    }

    public function insert(string $table, array $data, $format = null): int {
        $rows =& $this->rowsFor($table);
        if (str_contains($table, 'meta')) {
            $ids = array_map('intval', array_column($rows, 'meta_id'));
            $this->insert_id = $ids ? max($ids) + 1 : 1;
            $rows[] = ['meta_id' => $this->insert_id] + $data;
        } else {
            $ids = array_map('intval', array_column($rows, 'option_id'));
            $this->insert_id = $ids ? max($ids) + 1 : 1;
            $rows[] = ['option_id' => $this->insert_id] + $data;
        }
        return 1;
    }

    /** @return list<array<string,mixed>> */
    private function &rowsFor(string $table): array {
        if ($table === $this->postmeta) {
            return $this->postMetaRows;
        }
        if ($table === $this->termmeta) {
            return $this->termMetaRows;
        }
        if ($table === $this->options) {
            return $this->optionRows;
        }
        throw new RuntimeException("unrecognized table: $table");
    }
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$applySource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/Apply.php');
$materializerSource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php');
$check($applySource !== false && $materializerSource !== false, 'source files are readable');
$check(str_contains($materializerSource, 'final class ApplyFieldMaterializer'), 'new collaborator owns the field layer');
$check(substr_count($materializerSource, 'function reconcile_authored_meta(') === 1,
    'postmeta reconciliation has one implementation in the collaborator');
$check(substr_count($materializerSource, 'function reconcile_authored_term_meta(') === 1,
    'termmeta reconciliation has one implementation in the collaborator');
// reconcile_authored_meta()'s only caller, finalize_post(), itself moved to
// PostMaterializer in DUO-3347 slice 11 -- the new
// PostMaterializer::finalize_post() calls
// ApplyFieldMaterializer::reconcile_authored_meta() directly (calling back
// through Apply's own facade would be circular), so Apply's own facade is
// now genuinely dead code and was removed entirely rather than kept, the
// same "no other caller, no facade needed" treatment TermMaterializer's
// encode_description()/reconcile_term_relationships() already established
// (slice 6).
$check(!str_contains($applySource, 'private function reconcile_authored_meta('),
    'Apply no longer defines reconcile_authored_meta() at all (moved to PostMaterializer\'s own call site, no facade needed -- it had no other caller)');
$check(!str_contains($applySource, 'function reconcile_authored_term_meta('),
    'Apply no longer keeps a dead termmeta compatibility facade');

$wpdb = new ApplyFieldMaterializerFakeWpdb();
$materializer = (new ReflectionClass(ApplyFieldMaterializer::class))->newInstanceWithoutConstructor();

$wpdb->postMetaRows = [[
    'meta_id' => 7, 'post_id' => 19, 'meta_key' => 'owned', 'meta_value' => 'before',
]];
$materializer->upsert_meta($wpdb->postmeta, 'post_id', 19, 'owned', 'after', 'test update');
$check($wpdb->postMetaRows[0]['meta_value'] === 'after', 'upsert_meta updates the existing row');
$materializer->upsert_meta($wpdb->postmeta, 'post_id', 19, 'nullable', null, 'test insert');
$inserted = array_values(array_filter($wpdb->postMetaRows, static fn(array $row): bool => $row['meta_key'] === 'nullable'));
$check(count($inserted) === 1 && $inserted[0]['meta_value'] === null, 'upsert_meta preserves a real SQL NULL on insert');

$wpdb->optionRows = [[
    'option_id' => 3, 'option_name' => 'theme_mods_demo', 'option_value' => 'old', 'autoload' => 'yes',
]];
$materializer->upsert_option('theme_mods_demo', 'new', 'no');
$check($wpdb->optionRows[0]['option_value'] === 'new' && $wpdb->optionRows[0]['autoload'] === 'no',
    'upsert_option updates the existing option and autoload flag');
$materializer->upsert_option('new_option', 'value', 'yes');
$newOptions = array_values(array_filter($wpdb->optionRows, static fn(array $row): bool => $row['option_name'] === 'new_option'));
$check(count($newOptions) === 1 && $newOptions[0]['option_value'] === 'value', 'upsert_option inserts a missing option');
$check($cacheEvents === [
    ['options', 'theme_mods_demo'], ['options', 'alloptions'],
    ['options', 'new_option'], ['options', 'alloptions'],
], 'upsert_option invalidates the named and alloptions caches for both paths');

$check($materializer->option_wire_value(null) === 'N;', 'option_wire_value preserves null');
$check($materializer->option_wire_value(false) === 'b:0;', 'option_wire_value preserves false');
$check($materializer->option_wire_value(['x' => 1]) === 'a:1:{s:1:"x";i:1;}',
    'option_wire_value retains WordPress serialized array bytes');
$check($materializer->option_wire_value('') === '', 'option_wire_value leaves an empty string empty');

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall ApplyFieldMaterializer checks passed\n";
