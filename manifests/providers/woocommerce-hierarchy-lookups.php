<?php
namespace Duo\Providers;

use Duo\PlainData;
use Duo\Policy;

/**
 * WooCommerce 11.0.x category/brand hierarchy projection provider.
 *
 * Duo materializes term_taxonomy rows and options with SQL, so neither
 * WordPress's hierarchy-option maintenance nor WooCommerce's category lookup
 * callbacks run. Exact WooCommerce 11.0.0 and 11.0.1 expose the same public
 * CategoryLookup::regenerate() whole-taxonomy boundary, not a supported
 * row-level writer. Run that boundary in a clean WP-CLI process so persistent
 * caches populated before Apply cannot feed it stale parents, then compare the
 * child's bounded fingerprints with independently checked parent-process DB
 * bytes. Brand permalink rewrites share the child only when that exact option
 * changed; ordinary hierarchy writes do not acquire rewrite authority.
 */
final class WoocommerceHierarchyLookups {
    private Policy $policy;

    private const CAPABILITY = 'rebuild_hierarchy_lookups';
    private const CHILD_FORMAT = 'duo-woocommerce-hierarchy-child/v1';
    private const CATEGORY_TABLE = 'wc_category_lookup';
    private const TAXONOMIES = ['product_cat', 'product_brand'];
    private const MISSING_OPTION = 'duo-woocommerce-hierarchy-option-missing/v1';
    private const MAX_OPTION_BYTES = 16777216;
    private const MAX_TERMS = 200000;
    private const MAX_CATEGORY_LOOKUP_ROWS = 200000;
    private const MAX_REWRITE_RULES = 200000;
    private const MAX_REWRITE_PART_BYTES = 8192;

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string,plugin:string,version:string} */
    public function identity(): array {
        return [
            'id' => 'woocommerce-hierarchy-lookups',
            'plugin' => 'woocommerce/woocommerce.php',
            'version' => '1.0.0',
        ];
    }

    public function capabilities(): array {
        return [
            self::CAPABILITY => [
                'args' => [
                    'flush_rewrite' => ['type' => 'bool', 'required' => true],
                ],
                'reads' => [
                    'option:product_brand_children',
                    'option:product_cat_children',
                    'option:rewrite_rules',
                    'table:options',
                    'table:term_taxonomy',
                    'table:wc_category_lookup',
                ],
                'writes' => [
                    'option:product_brand_children',
                    'option:product_cat_children',
                    'option:rewrite_rules',
                    'table:wc_category_lookup',
                ],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 300,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        if ($capability !== self::CAPABILITY) {
            throw new \RuntimeException(
                "duo: WooCommerce hierarchy provider does not implement capability '$capability'"
            );
        }
        if (!array_key_exists('flush_rewrite', $args) || !is_bool($args['flush_rewrite'])) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy provider requires the exact boolean flush_rewrite argument'
            );
        }
        return $this->repair((bool) $args['flush_rewrite']);
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== self::CAPABILITY) {
            throw new \RuntimeException(
                "duo: WooCommerce hierarchy provider does not implement capability '$capability'"
            );
        }
        if (!array_key_exists('flush_rewrite', $args) || !is_bool($args['flush_rewrite'])) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy reconciliation requires the exact boolean flush_rewrite argument'
            );
        }
        return [
            'operation' => $operation,
            'after' => self::projection_snapshot(true, (bool) $args['flush_rewrite'], false),
            'verified' => true,
        ];
    }

    /** @return array{before:array,after:array,verified:true} */
    private function repair(bool $flushRewrite): array {
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy repair requires a fresh WP-CLI child process'
            );
        }

        // Validate authored parent graphs and exact table shape before the
        // child can truncate anything. Dirty derived rows/options stay
        // observable and repairable; malformed authored hierarchy is not.
        $before = self::projection_snapshot(false, $flushRewrite, false);
        $childAfter = $this->launch_child($flushRewrite);
        $after = self::projection_snapshot(true, $flushRewrite, false);
        self::assert_authored_source_unchanged($before, $after, $flushRewrite);
        if ($childAfter !== $after) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child receipt disagrees with checked parent-process state; '
                . 'recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function assert_authored_source_unchanged(array $before, array $after, bool $includeRewrite): void {
        $keys = [
            'product_cat_terms',
            'product_cat_parent_sha256',
            'product_brand_terms',
            'product_brand_parent_sha256',
        ];
        if ($includeRewrite) {
            $keys[] = 'brand_permalink_sha256';
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $before) || !array_key_exists($key, $after)
                || $before[$key] !== $after[$key]) {
                throw new \RuntimeException(
                    'duo: WooCommerce authored hierarchy source changed during native repair; recovery_required'
                );
            }
        }
    }

    /** @return array<string,int|string|bool> */
    private function launch_child(bool $flushRewrite): array {
        $code = 'require_once ' . var_export(__FILE__, true) . '; '
            . '\\Duo\\Providers\\WoocommerceHierarchyLookups::run_child('
            . ($flushRewrite ? 'true' : 'false') . ');';
        try {
            $result = \WP_CLI::runcommand('eval ' . escapeshellarg($code), [
                'launch' => true,
                'return' => 'all',
                'exit_error' => false,
            ]);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child process could not start; recovery_required',
                0,
                $exception
            );
        }
        if (!is_object($result) || !isset($result->return_code) || !is_int($result->return_code)) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child returned an unreadable process result; recovery_required'
            );
        }
        if ($result->return_code !== 0) {
            // Child output can contain plugin paths, SQL diagnostics, or
            // merchant-shaped filter output. The exit code is sufficient to
            // keep the failure actionable without copying those bytes into a
            // promotion receipt or operator log.
            throw new \RuntimeException(
                "duo: WooCommerce hierarchy child exited {$result->return_code}; recovery_required"
            );
        }
        $stderr = (string) ($result->stderr ?? '');
        if ($stderr !== '') {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child emitted stderr despite exit 0; recovery_required'
            );
        }
        $stdout = (string) ($result->stdout ?? '');
        if ($stdout === '' || strlen($stdout) > 16384) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child returned a missing or oversized receipt; recovery_required'
            );
        }
        try {
            $decoded = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            $canonical = json_encode(
                $decoded,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child returned malformed receipt data; recovery_required',
                0,
                $exception
            );
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['format', 'after']
            || ($decoded['format'] ?? null) !== self::CHILD_FORMAT
            || !is_array($decoded['after']) || $canonical !== $stdout) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child returned noncanonical or trailing output; recovery_required'
            );
        }
        /** @var array<string,int|string|bool> $after */
        $after = $decoded['after'];
        return $after;
    }

    /**
     * Fresh-process entrypoint reached only by launch_child(). The child owns
     * native mutation; the parent owns independent checked readback.
     */
    public static function run_child(bool $flushRewrite): void {
        self::projection_snapshot(false, $flushRewrite, false);

        foreach (self::TAXONOMIES as $taxonomy) {
            self::invalidate_taxonomy_caches($taxonomy);
            $option = $taxonomy . '_children';
            delete_option($option);
            self::assert_option_absent($option);
            self::invalidate_taxonomy_caches($taxonomy);
        }

        global $wpdb;
        $lookupClass = '\\Automattic\\WooCommerce\\Internal\\Admin\\CategoryLookup';
        if (!is_callable([$lookupClass, 'instance'])) {
            throw new \RuntimeException(
                'duo: WooCommerce public category lookup regenerator is unavailable; recovery_required'
            );
        }
        $lookup = $lookupClass::instance();
        if (!is_object($lookup) || !is_callable([$lookup, 'regenerate'])) {
            throw new \RuntimeException(
                'duo: WooCommerce public category lookup instance is unreadable; recovery_required'
            );
        }
        $wpdb->last_error = '';
        $lookup->regenerate();
        if ((string) $wpdb->last_error !== '') {
            throw new \RuntimeException(
                'duo: WooCommerce native category lookup regeneration reported a database failure; '
                . 'recovery_required'
            );
        }

        foreach (self::TAXONOMIES as $taxonomy) {
            self::invalidate_taxonomy_caches($taxonomy);
            $hierarchy = _get_term_hierarchy($taxonomy);
            if (!is_array($hierarchy)) {
                throw new \RuntimeException(
                    'duo: WordPress native hierarchy regeneration returned unreadable state; recovery_required'
                );
            }
        }

        if ($flushRewrite) {
            flush_rewrite_rules(false);
        }
        $after = self::projection_snapshot(true, $flushRewrite, $flushRewrite);
        echo json_encode(
            ['format' => self::CHILD_FORMAT, 'after' => $after],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function invalidate_taxonomy_caches(string $taxonomy): void {
        clean_taxonomy_cache($taxonomy);
        wp_cache_delete('get', 'term-queries');
        wp_cache_delete($taxonomy . '_children', 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_set_terms_last_changed();
    }

    private static function assert_option_absent(string $option): void {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 2",
            $option
        ), 'WooCommerce hierarchy option deletion readback');
        if ($rows !== []) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy option deletion did not persist; recovery_required'
            );
        }
    }

    /** @return array<string,int|string|bool> */
    private static function projection_snapshot(bool $verify, bool $includeRewrite, bool $verifyFreshRewrite): array {
        self::assert_category_lookup_schema();
        $parents = [];
        foreach (self::TAXONOMIES as $taxonomy) {
            $parents[$taxonomy] = self::term_parent_map($taxonomy);
        }
        $expectedCategory = self::category_closure($parents['product_cat']);
        $category = self::category_lookup_state($expectedCategory, $verify);
        $catHierarchy = self::hierarchy_option_state(
            'product_cat',
            self::expected_children($parents['product_cat']),
            $verify
        );
        $brandHierarchy = self::hierarchy_option_state(
            'product_brand',
            self::expected_children($parents['product_brand']),
            $verify
        );

        $summary = [
            'product_cat_terms' => count($parents['product_cat']),
            'product_cat_parent_sha256' => self::fingerprint(self::parent_rows($parents['product_cat'])),
            'product_cat_children' => $catHierarchy['links'],
            'product_cat_children_valid' => $catHierarchy['valid'],
            'product_cat_children_raw_sha256' => $catHierarchy['raw_sha256'],
            'product_cat_children_effective_sha256' => $catHierarchy['effective_sha256'],
            'product_brand_terms' => count($parents['product_brand']),
            'product_brand_parent_sha256' => self::fingerprint(self::parent_rows($parents['product_brand'])),
            'product_brand_children' => $brandHierarchy['links'],
            'product_brand_children_valid' => $brandHierarchy['valid'],
            'product_brand_children_raw_sha256' => $brandHierarchy['raw_sha256'],
            'product_brand_children_effective_sha256' => $brandHierarchy['effective_sha256'],
            'category_lookup_rows' => $category['rows'],
            'category_lookup_valid' => $category['valid'],
            'category_lookup_sha256' => $category['sha256'],
        ];
        if ($includeRewrite) {
            $summary = array_merge($summary, self::rewrite_state($verify, $verifyFreshRewrite));
        }
        return $summary;
    }

    private static function assert_category_lookup_schema(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::CATEGORY_TABLE;
        if (isset($wpdb->wc_category_lookup) && (string) $wpdb->wc_category_lookup !== $table) {
            throw new \RuntimeException(
                'duo: WooCommerce category lookup table identity is outside the adapter contract'
            );
        }
        $columns = \Duo\ProviderSdk::checked_get_results(
            "SHOW COLUMNS FROM `$table`",
            'WooCommerce category lookup schema'
        );
        $actual = [];
        foreach ($columns as $column) {
            $field = $column['Field'] ?? null;
            $type = $column['Type'] ?? null;
            if (!is_string($field) || !is_string($type)) {
                throw new \RuntimeException(
                    'duo: WooCommerce category lookup schema returned an unreadable column'
                );
            }
            $actual[$field] = strtolower($type);
        }
        ksort($actual, SORT_STRING);
        if (array_keys($actual) !== ['category_id', 'category_tree_id']) {
            throw new \RuntimeException(
                'duo: WooCommerce category lookup schema has unknown or missing columns'
            );
        }
        foreach ($actual as $type) {
            if (preg_match('/^bigint(?:\(20\))? unsigned$/D', $type) !== 1) {
                throw new \RuntimeException(
                    'duo: WooCommerce category lookup schema has an incompatible column type'
                );
            }
        }
    }

    /** @return array<int,int> */
    private static function term_parent_map(string $taxonomy): array {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT term_id, parent FROM {$wpdb->term_taxonomy} "
            . 'WHERE taxonomy = %s ORDER BY term_id ASC LIMIT ' . (self::MAX_TERMS + 1),
            $taxonomy
        ), "WooCommerce $taxonomy parent projection");
        if (count($rows) > self::MAX_TERMS) {
            throw new \RuntimeException(
                "duo: WooCommerce $taxonomy exceeds the bounded hierarchy contract"
            );
        }
        $map = [];
        foreach ($rows as $row) {
            $id = self::strict_uint($row['term_id'] ?? null, false, "$taxonomy term_id");
            $parent = self::strict_uint($row['parent'] ?? null, true, "$taxonomy parent");
            if (isset($map[$id])) {
                throw new \RuntimeException(
                    "duo: WooCommerce $taxonomy parent projection contains duplicate term identities"
                );
            }
            $map[$id] = $parent;
        }
        ksort($map, SORT_NUMERIC);
        self::assert_acyclic_parent_map($map, $taxonomy);
        return $map;
    }

    /** @param array<int,int> $map */
    private static function assert_acyclic_parent_map(array $map, string $taxonomy): void {
        foreach ($map as $id => $parent) {
            if ($parent > 0 && !isset($map[$parent])) {
                throw new \RuntimeException(
                    "duo: WooCommerce $taxonomy term hierarchy contains a dangling parent"
                );
            }
        }
        // Every node has at most one parent, so three-state iterative DFS is
        // linear in terms plus edges and does not consume the PHP call stack.
        // A per-origin seen set would revisit a 200k-deep chain quadratically.
        $state = [];
        foreach (array_keys($map) as $id) {
            if (($state[$id] ?? 0) === 2) {
                continue;
            }
            $path = [];
            $cursor = (int) $id;
            while ($cursor > 0 && ($state[$cursor] ?? 0) !== 2) {
                if (($state[$cursor] ?? 0) === 1) {
                    throw new \RuntimeException(
                        "duo: WooCommerce $taxonomy term hierarchy contains a cycle"
                    );
                }
                $state[$cursor] = 1;
                $path[] = $cursor;
                $cursor = $map[$cursor] ?? 0;
            }
            foreach ($path as $visited) {
                $state[$visited] = 2;
            }
        }
    }

    /** @param array<int,int> $map @return list<array{0:int,1:int}> */
    private static function parent_rows(array $map): array {
        $rows = [];
        foreach ($map as $id => $parent) {
            $rows[] = [(int) $id, (int) $parent];
        }
        return $rows;
    }

    /** @param array<int,int> $map @return list<array{0:int,1:list<int>}> */
    private static function expected_children(array $map): array {
        $children = [];
        foreach ($map as $id => $parent) {
            if ($parent > 0) {
                $children[$parent][] = (int) $id;
            }
        }
        ksort($children, SORT_NUMERIC);
        $normalized = [];
        foreach ($children as $parent => $ids) {
            sort($ids, SORT_NUMERIC);
            $normalized[] = [(int) $parent, array_values(array_unique($ids))];
        }
        return $normalized;
    }

    /** @param array<int,int> $map @return list<array{0:int,1:int}> */
    private static function category_closure(array $map): array {
        $depths = [];
        $closureRows = 0;
        foreach (array_keys($map) as $id) {
            if (!isset($depths[$id])) {
                $path = [];
                $cursor = (int) $id;
                while ($cursor > 0 && !isset($depths[$cursor])) {
                    $path[] = $cursor;
                    $cursor = $map[$cursor] ?? 0;
                }
                $depth = $cursor > 0 ? $depths[$cursor] : 0;
                for ($index = count($path) - 1; $index >= 0; --$index) {
                    $depths[$path[$index]] = ++$depth;
                }
            }
            $closureRows += $depths[$id];
            if ($closureRows > self::MAX_CATEGORY_LOOKUP_ROWS) {
                throw new \RuntimeException(
                    'duo: WooCommerce category lookup projection exceeds the bounded row contract'
                );
            }
        }

        $rows = [];
        foreach ($map as $id => $_parent) {
            $cursor = (int) $id;
            while ($cursor > 0) {
                $rows[] = [$cursor, (int) $id];
                $cursor = $map[$cursor] ?? 0;
            }
        }
        usort($rows, static fn(array $left, array $right): int => $left <=> $right);
        return $rows;
    }

    /**
     * @param list<array{0:int,1:int}> $expected
     * @return array{rows:int,valid:bool,sha256:string}
     */
    private static function category_lookup_state(array $expected, bool $verify): array {
        global $wpdb;
        $table = $wpdb->prefix . self::CATEGORY_TABLE;
        $stored = \Duo\ProviderSdk::checked_get_results(
            "SELECT category_tree_id, category_id FROM `$table` "
            . 'ORDER BY category_tree_id ASC, category_id ASC LIMIT '
            . (self::MAX_CATEGORY_LOOKUP_ROWS + 1),
            'WooCommerce category lookup readback'
        );
        if (count($stored) > self::MAX_CATEGORY_LOOKUP_ROWS) {
            if ($verify) {
                throw new \RuntimeException(
                    'duo: WooCommerce category lookup exceeds the bounded row contract; recovery_required'
                );
            }
            return [
                'rows' => self::MAX_CATEGORY_LOOKUP_ROWS + 1,
                'valid' => false,
                'sha256' => self::fingerprint(['oversized', self::MAX_CATEGORY_LOOKUP_ROWS + 1]),
            ];
        }
        $rows = [];
        $seen = [];
        $valid = true;
        foreach ($stored as $row) {
            try {
                $normalized = [
                    self::strict_uint($row['category_tree_id'] ?? null, false, 'category_tree_id'),
                    self::strict_uint($row['category_id'] ?? null, false, 'category_id'),
                ];
                $key = $normalized[0] . ':' . $normalized[1];
                if (isset($seen[$key])) {
                    throw new \RuntimeException('duplicate category lookup row');
                }
                $seen[$key] = true;
                $rows[] = $normalized;
            } catch (\Throwable $exception) {
                if ($verify) {
                    throw new \RuntimeException(
                        'duo: WooCommerce category lookup contains malformed or duplicate rows; recovery_required',
                        0,
                        $exception
                    );
                }
                $valid = false;
            }
        }
        usort($rows, static fn(array $left, array $right): int => $left <=> $right);
        if ($verify && $rows !== $expected) {
            throw new \RuntimeException(
                'duo: WooCommerce category lookup exact row projection mismatch (expected '
                . count($expected) . ', observed ' . count($rows) . '); recovery_required'
            );
        }
        return [
            'rows' => count($stored),
            'valid' => $valid && $rows === $expected,
            'sha256' => self::fingerprint($valid ? $rows : ['invalid', count($stored)]),
        ];
    }

    /**
     * @param list<array{0:int,1:list<int>}> $expected
     * @return array{links:int,valid:bool,raw_sha256:string,effective_sha256:string}
     */
    private static function hierarchy_option_state(string $taxonomy, array $expected, bool $verify): array {
        $name = $taxonomy . '_children';
        $raw = self::raw_option($name, $verify);
        if ($raw === null) {
            return [
                'links' => 0,
                'valid' => false,
                'raw_sha256' => hash('sha256', 'missing:' . $name),
                'effective_sha256' => hash('sha256', 'invalid:' . $name),
            ];
        }
        $rawHash = hash('sha256', $raw);
        try {
            $decoded = PlainData::decode_serialized($raw, "$name option");
            $normalized = self::normalize_children($decoded, $name);
            $effective = self::fresh_option($name);
            $effectiveNormalized = self::normalize_children($effective, "$name effective option");
        } catch (\Throwable $exception) {
            if ($verify) {
                throw $exception;
            }
            return [
                'links' => 0,
                'valid' => false,
                'raw_sha256' => $rawHash,
                'effective_sha256' => hash('sha256', 'invalid:' . $name),
            ];
        }
        $valid = $normalized === $expected && $effectiveNormalized === $expected;
        if ($verify && !$valid) {
            throw new \RuntimeException(
                "duo: WooCommerce $taxonomy hierarchy option disagrees with exact term parents; recovery_required"
            );
        }
        $links = 0;
        foreach ($normalized as $row) {
            $links += count($row[1]);
        }
        return [
            'links' => $links,
            'valid' => $valid,
            'raw_sha256' => $rawHash,
            'effective_sha256' => self::fingerprint($effectiveNormalized),
        ];
    }

    private static function raw_option(string $name, bool $verify): ?string {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 2",
            $name
        ), "WooCommerce $name raw option readback");
        if (count($rows) !== 1 || !is_string($rows[0]['option_value'] ?? null)) {
            if ($verify) {
                throw new \RuntimeException(
                    "duo: WooCommerce $name option is missing or duplicated; recovery_required"
                );
            }
            return null;
        }
        $raw = $rows[0]['option_value'];
        if (strlen($raw) > self::MAX_OPTION_BYTES) {
            if ($verify) {
                throw new \RuntimeException(
                    "duo: WooCommerce $name option exceeds the bounded plain-data contract; recovery_required"
                );
            }
            return null;
        }
        return $raw;
    }

    private static function fresh_option(string $name): mixed {
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        $value = get_option($name, self::MISSING_OPTION);
        if ($value === self::MISSING_OPTION) {
            throw new \RuntimeException(
                "duo: WooCommerce $name effective option is missing; recovery_required"
            );
        }
        PlainData::assert($value, "$name effective option");
        return $value;
    }

    /** @return list<array{0:int,1:list<int>}> */
    private static function normalize_children(mixed $value, string $context): array {
        if (!is_array($value) || count($value) > self::MAX_TERMS) {
            throw new \RuntimeException("duo: $context has an invalid hierarchy shape");
        }
        $normalized = [];
        foreach ($value as $parent => $children) {
            $parent = self::strict_uint($parent, false, "$context parent");
            if (!is_array($children) || !array_is_list($children) || count($children) > self::MAX_TERMS) {
                throw new \RuntimeException("duo: $context has an invalid child list");
            }
            $ids = [];
            foreach ($children as $child) {
                $id = self::strict_uint($child, false, "$context child");
                if (isset($ids[$id])) {
                    throw new \RuntimeException("duo: $context repeats a child identity");
                }
                $ids[$id] = $id;
            }
            $ids = array_values($ids);
            sort($ids, SORT_NUMERIC);
            $normalized[] = [$parent, $ids];
        }
        usort($normalized, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        return $normalized;
    }

    /** @return array<string,int|string|bool> */
    private static function rewrite_state(bool $verify, bool $verifyFresh): array {
        $raw = self::raw_option('rewrite_rules', $verify);
        if ($raw === null) {
            return [
                'brand_permalink_sha256' => self::brand_permalink_fingerprint(),
                'rewrite_rules' => 0,
                'rewrite_rules_valid' => false,
                'rewrite_rules_raw_sha256' => hash('sha256', 'missing:rewrite_rules'),
                'rewrite_rules_effective_sha256' => hash('sha256', 'invalid:rewrite_rules'),
            ];
        }
        $rawHash = hash('sha256', $raw);
        try {
            $decoded = PlainData::decode_serialized($raw, 'rewrite_rules option');
            $stored = self::normalize_rewrite_rules($decoded, 'rewrite_rules option');
            $effective = self::normalize_rewrite_rules(
                self::fresh_option('rewrite_rules'),
                'rewrite_rules effective option'
            );
            if ($stored !== $effective) {
                throw new \RuntimeException(
                    'duo: WooCommerce stored and effective rewrite rules disagree; recovery_required'
                );
            }
            if ($verifyFresh) {
                global $wp_rewrite;
                if (!is_object($wp_rewrite) || !is_callable([$wp_rewrite, 'rewrite_rules'])) {
                    throw new \RuntimeException(
                        'duo: WordPress native rewrite generator is unavailable; recovery_required'
                    );
                }
                $fresh = self::normalize_rewrite_rules(
                    $wp_rewrite->rewrite_rules(),
                    'fresh native rewrite projection'
                );
                if ($fresh !== $stored) {
                    throw new \RuntimeException(
                        'duo: WooCommerce stored rewrite rules disagree with fresh native generation; '
                        . 'recovery_required'
                    );
                }
            }
        } catch (\Throwable $exception) {
            if ($verify) {
                throw $exception;
            }
            return [
                'brand_permalink_sha256' => self::brand_permalink_fingerprint(),
                'rewrite_rules' => 0,
                'rewrite_rules_valid' => false,
                'rewrite_rules_raw_sha256' => $rawHash,
                'rewrite_rules_effective_sha256' => hash('sha256', 'invalid:rewrite_rules'),
            ];
        }
        return [
            'brand_permalink_sha256' => self::brand_permalink_fingerprint(),
            'rewrite_rules' => count($stored),
            'rewrite_rules_valid' => true,
            'rewrite_rules_raw_sha256' => $rawHash,
            'rewrite_rules_effective_sha256' => self::fingerprint($effective),
        ];
    }

    private static function brand_permalink_fingerprint(): string {
        $name = 'woocommerce_brand_permalink';
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        // Absence is WooCommerce's documented empty/default brand base, not
        // a missing dependency. It must remain distinguishable from an
        // authored non-empty slug while still producing a bounded receipt.
        $value = get_option($name, '');
        PlainData::assert($value, 'woocommerce_brand_permalink effective option');
        if (!is_string($value) || strlen($value) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \RuntimeException(
                'duo: WooCommerce brand permalink is outside the bounded string contract'
            );
        }
        return hash('sha256', $value);
    }

    /** @return list<array{0:string,1:string}> */
    private static function normalize_rewrite_rules(mixed $value, string $context): array {
        if (!is_array($value) || count($value) > self::MAX_REWRITE_RULES) {
            throw new \RuntimeException("duo: $context has an invalid or oversized rule map");
        }
        $rules = [];
        foreach ($value as $pattern => $query) {
            if (!is_string($pattern) || !is_string($query)
                || $pattern === '' || strlen($pattern) > self::MAX_REWRITE_PART_BYTES
                || strlen($query) > self::MAX_REWRITE_PART_BYTES) {
                throw new \RuntimeException("duo: $context contains an invalid bounded rule");
            }
            $rules[] = [$pattern, $query];
        }
        return $rules;
    }

    private static function strict_uint(mixed $value, bool $allowZero, string $context): int {
        if ((!is_int($value) && !is_string($value))
            || preg_match($allowZero ? '/^(?:0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            throw new \RuntimeException("duo: $context is not a canonical unsigned integer");
        }
        $number = (int) $value;
        if ($number < ($allowZero ? 0 : 1) || (string) $number !== (string) $value) {
            throw new \RuntimeException("duo: $context exceeds the supported integer boundary");
        }
        return $number;
    }

    private static function fingerprint(array $value): string {
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }
}
