<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/EnvironmentValues.php';
require_once __DIR__ . '/../Kernel/InputFileBinding.php';
require_once __DIR__ . '/../Kernel/PathSafety.php';
require_once __DIR__ . '/../Kernel/ColumnValueCases.php';
require_once __DIR__ . '/../Kernel/TableRowScope.php';
require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
require_once __DIR__ . '/../Policy/Policy.php';

/** Target-local file observation and intent, composed outside the pure column grammar. */
final class ColumnInputFiles {
    /** @var resource|null */
    private $lock = null;
    private array $values = [];

    private function __construct(private readonly string $repo, private readonly Policy $policy, private readonly array $tree) {}

    public static function lock_work(string $repo, Policy $policy, array $tree): ?self {
        if (self::declarations($policy, $tree) === []) return null;
        $context = new self($repo, $policy, $tree);
        $context->lock = EnvironmentValues::lock($repo);
        try {
            $context->values = self::resolve_work($repo, $policy, $tree);
            $context->assert_current();
            return $context;
        } catch (\Throwable $failure) {
            $context->release();
            throw $failure;
        }
    }

    public function values(): array {
        return $this->values;
    }

    public function assert_current(): void {
        EnvironmentValues::assert_lock($this->repo, $this->lock);
        if (self::resolve_work($this->repo, $this->policy, $this->tree) !== $this->values) {
            throw new \RuntimeException('wprism: target-local input intent changed during authored apply');
        }
    }

    public function release(): void {
        if (is_resource($this->lock)) fclose($this->lock);
        $this->lock = null;
    }

    public function __destruct() { $this->release(); }

    /** The immutable tree and its selected contract own every operator-visible binding name. */
    public static function declarations(Policy $policy, array $tree): array {
        $out = [];
        foreach ($tree as $uuid => $entity) {
            if (!is_array($entity)) continue;
            $table = (string) ($entity['type'] ?? '');
            $codecs = array_filter($policy->column_codec_rules($table), ColumnCodecGrammar::has_input_files(...));
            if ($codecs === []) continue;
            $front = $entity['data'] ?? null;
            if (!is_array($front) || ($front['uuid'] ?? null) !== $uuid || ($front['table'] ?? null) !== $table) {
                throw new \RuntimeException('wprism: column input declaration requires canonical table identity');
            }
            foreach ($codecs as $column => $codec) {
                $codec = ColumnValueCases::resolve($codec, (array) ($front['columns'] ?? []), 'column input declaration');
                if (!isset($codec['value'])) continue;
                $value = ColumnCodecGrammar::decode_canonical_value($front['columns'][$column] ?? null, $codec, 'column input declaration');
                foreach (InputFileBinding::bindings($value, $codec['value']) as $binding) {
                    $name = InputFileBinding::name($uuid, $column, $binding['path']);
                    $out[$name] = $binding + ['uuid' => $uuid, 'table' => $table, 'column' => $column, 'codec' => $codec];
                }
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** env-set publishes intent; Apply remains the only writer of authored typed rows. */
    public static function provision(string $repo, Policy $policy, array $tree, string $name, string $filename): array {
        $declaration = self::declarations($policy, $tree)[$name] ?? null;
        if ($declaration === null) {
            throw new \RuntimeException('wprism: env-set name does not identify an input file in the compiled repository');
        }
        self::resolve_file($filename, $declaration['spec']);
        $previouslySet = isset(EnvironmentValues::read($repo)[$name]);
        EnvironmentValues::set($repo, $name, $filename);
        return ['name' => $name, 'previously_set' => $previouslySet];
    }

    /** No file contents are opened, copied or hashed by binding resolution. */
    public static function resolve_file(string $filename, array $spec): string {
        InputFileBinding::assert_filename($filename, $spec);
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === ''
            || !defined('WP_CONTENT_URL') || !is_string(WP_CONTENT_URL) || WP_CONTENT_URL === '') {
            throw new \RuntimeException('wprism: column input file requires native content path and URL roots');
        }
        $relative = $spec['directory'] . '/' . $filename;
        clearstatcache();
        try {
            PathSafety::assert_no_symlinked_target_path($relative, true, 'input file binding');
        } catch (\RuntimeException $failure) {
            throw new \RuntimeException('wprism: input binding refuses a symbolic-link or unsafe file path');
        }
        $root = realpath(WP_CONTENT_DIR);
        $path = realpath(PathSafety::safe_join(WP_CONTENT_DIR, $relative));
        if ($root === false || $path === false || $path !== rtrim($root, '/') . '/' . $relative
            || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('wprism: input binding requires an available readable regular file in its declared directory');
        }
        return InputFileBinding::native_url($filename, $spec, WP_CONTENT_URL);
    }

    /** Missing availability is a checklist item; pointer differences are ordinary planned row updates. */
    public static function projection(string $repo, Policy $policy, array $tree): array {
        $declarations = self::declarations($policy, $tree);
        $result = ['env_missing' => [], 'warnings' => [], 'input_rebinds' => []];
        if ($declarations === []) return $result;
        $intended = EnvironmentValues::read($repo);
        $observations = [];
        foreach ($declarations as $name => $declaration) {
            try {
                $filename = $intended[$name] ?? null;
                if (!is_string($filename)) throw new \RuntimeException('input binding not provisioned');
                $url = self::resolve_file($filename, $declaration['spec']);
            } catch (\RuntimeException) {
                $result['env_missing'][] = ['name' => $name, 'required' => true];
                $result['warnings'][] = "env_missing: input file binding '$name' is required and unprovisioned or unavailable — "
                    . "use 'wp wprism env-set --name=$name --stdin' with a filename in its declared content directory";
                continue;
            }
            $key = $declaration['uuid'] . ':' . $declaration['column'];
            if (!array_key_exists($key, $observations)) {
                $observations[$key] = self::observe($policy, $declaration);
            }
            $observed = $observations[$key];
            if ($observed === null) continue;
            $native = ColumnCodecGrammar::decode_for_clearance($observed['value'], $declaration['codec'], 'input binding observation', 'captured');
            foreach ($declaration['path'] as $field) {
                $native = is_array($native) ? ($native[$field] ?? null) : null;
            }
            if (!is_string($native) || !hash_equals($url, $native)) $result['input_rebinds'][$declaration['uuid']] = true;
        }
        return $result;
    }

    /** Only the transaction's selected work receives private native URLs. */
    public static function resolve_work(string $repo, Policy $policy, array $tree): array {
        $declarations = self::declarations($policy, $tree);
        if ($declarations === []) return [];
        $intended = EnvironmentValues::read($repo);
        $out = [];
        foreach ($declarations as $name => $declaration) {
            $filename = $intended[$name] ?? null;
            if (!is_string($filename)) throw new \RuntimeException("wprism: input file binding '$name' is not provisioned");
            $out[$name] = self::resolve_file($filename, $declaration['spec']);
        }
        return $out;
    }

    /** One statement observes physical membership and the matching ledger tuple together. */
    private static function observe(Policy $policy, array $declaration): ?array {
        global $wpdb;
        $table = $declaration['table'];
        $decl = $policy->table_rule($table);
        if (!is_array($decl) || ($decl['identity']['mode'] ?? '') === 'composite_ref') {
            throw new \RuntimeException('wprism: column input observation requires an ordinary typed-table owner');
        }
        $prefixed = $wpdb->prefix . $table;
        $map = $wpdb->prefix . 'wprism_map';
        $column = $declaration['column'];
        $pk = $decl['pk'];
        $fields = ['m.uuid AS binding_uuid', 'm.entity_type AS binding_type', 'm.local_id AS binding_id',
            "r.`$pk` AS row_id", "r.`$column` AS native_value"];
        $scopeColumns = array_keys($decl['row_scope'] ?? []);
        foreach ($scopeColumns as $index => $scopeColumn) $fields[] = "r.`$scopeColumn` AS scope_$index";
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ' . implode(', ', $fields)
            . " FROM `$map` m LEFT JOIN `$prefixed` r ON r.`$pk` = m.local_id"
            . ' WHERE m.uuid = %s AND m.id_kind = %s LIMIT 2', $declaration['uuid'], $decl['id_kind']), ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '' || count($rows) > 1) {
            throw new \RuntimeException('wprism: input binding identity observation failed');
        }
        if ($rows === []) return null;
        $row = $rows[0];
        if (($row['binding_uuid'] ?? null) !== $declaration['uuid'] || ($row['binding_type'] ?? null) !== $table
            || preg_match('/^[1-9][0-9]*$/D', (string) ($row['binding_id'] ?? '')) !== 1) {
            throw new \RuntimeException('wprism: input binding ledger identity disagrees with its canonical owner');
        }
        if (($row['row_id'] ?? null) === null) return null;
        if ((string) $row['row_id'] !== (string) $row['binding_id']) {
            throw new \RuntimeException('wprism: input binding physical identity disagrees with its ledger');
        }
        $scope = [];
        foreach ($scopeColumns as $index => $scopeColumn) $scope[$scopeColumn] = $row['scope_' . $index] ?? null;
        TableRowScope::assert_matches($table, $decl, $scope);
        return ['value' => $row['native_value']];
    }
}
