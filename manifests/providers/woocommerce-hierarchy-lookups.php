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
    private const PERMALINK_CAPABILITY = 'rebuild_product_permalink_routes';
    private const CHILD_FORMAT = 'duo-woocommerce-hierarchy-child/v1';
    private const CATEGORY_TABLE = 'wc_category_lookup';
    private const TAXONOMIES = ['product_cat', 'product_brand'];
    private const MAX_OPTION_BYTES = 16777216;
    private const MAX_TERMS = 200000;
    private const MAX_CATEGORY_LOOKUP_ROWS = 200000;
    private const MAX_REWRITE_RULES = 200000;
    private const MAX_REWRITE_PART_BYTES = 8192;
    private const MAX_PERMALINK_OPTION_BYTES = 16384;
    private const MAX_PERMALINK_PART_BYTES = 2048;
    private const MAX_TABLE_COLUMNS = 4096;
    /** Mirrors Ledger::TABLE_IDENTIFIER_WIDTH and the journal SQL grammar. */
    private const TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string,plugin:string,version:string} */
    public function identity(): array {
        return [
            'id' => 'woocommerce-hierarchy-lookups',
            'plugin' => 'woocommerce/woocommerce.php',
            'version' => '2.0.0',
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
            self::PERMALINK_CAPABILITY => [
                'args' => [],
                'reads' => [
                    'option:permalink_structure',
                    'option:rewrite_rules',
                    'option:woocommerce_brand_permalink',
                    'option:woocommerce_permalinks',
                    'table:options',
                ],
                'writes' => [
                    'option:rewrite_rules',
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
        if ($capability === self::PERMALINK_CAPABILITY) {
            if ($args !== []) {
                throw new \RuntimeException(
                    'duo: WooCommerce product permalink repair accepts no arguments'
                );
            }
            return $this->repair_product_permalinks();
        }
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
        if ($capability === self::PERMALINK_CAPABILITY) {
            if ($args !== []) {
                throw new \RuntimeException(
                    'duo: WooCommerce product permalink reconciliation accepts no arguments'
                );
            }
            return [
                'operation' => $operation,
                'after' => self::product_permalink_projection(true, true, true),
                'verified' => true,
            ];
        }
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
            'after' => self::projection_snapshot(true, (bool) $args['flush_rewrite'], (bool) $args['flush_rewrite']),
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
        $before = self::projection_snapshot(false, $flushRewrite);
        $childAfter = $this->launch_child($flushRewrite);
        $after = self::projection_snapshot(true, $flushRewrite, $flushRewrite);
        self::assert_authored_source_unchanged($before, $after, $flushRewrite);
        if ($childAfter !== $after) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy child receipt disagrees with checked parent-process state; '
                . 'recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array{before:array,after:array,verified:true} */
    private function repair_product_permalinks(): array {
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                'duo: WooCommerce product permalink repair requires a fresh WP-CLI child process'
            );
        }

        // Unlike the hierarchy tables, the permalink record is authored
        // input. It must already be the exact native five-field storage
        // shape before Core's reviewed fresh-process rewrite action may touch
        // derived state. NativeActions owns the extension interpreter: its
        // child verifies both sanitized durable bytes and the effective
        // option-filtered projection (Yoast/Polylang/TEC included) without
        // this Woo adapter guessing a closed set of plugin callbacks.
        $before = self::product_permalink_projection(true, false);
        \Duo\NativeActions::execute('rewrite.flush', []);
        $after = self::product_permalink_projection(true, true, true);
        self::assert_product_permalink_source_unchanged($before, $after);
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function assert_product_permalink_source_unchanged(array $before, array $after): void {
        $keys = [
            'product_permalink_fields',
            'product_permalink_valid',
            'product_permalink_sha256',
            'product_permalink_raw_sha256',
            'product_permalink_option_id',
            'product_permalink_autoload',
            'brand_permalink_present',
            'brand_permalink_sha256',
            'brand_permalink_raw_sha256',
            'brand_permalink_canonical_sha256',
            'brand_permalink_option_id',
            'brand_permalink_autoload',
            'permalink_structure_present',
            'permalink_structure_sha256',
            'permalink_structure_option_id',
            'permalink_structure_autoload',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $before) || !array_key_exists($key, $after)
                || $before[$key] !== $after[$key]) {
                throw new \RuntimeException(
                    'duo: WooCommerce authored product permalink state changed during native repair; '
                    . 'recovery_required'
                );
            }
        }
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
            $keys[] = 'permalink_structure_present';
            $keys[] = 'permalink_structure_sha256';
            $keys[] = 'permalink_structure_option_id';
            $keys[] = 'permalink_structure_autoload';
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
        self::projection_snapshot(false, $flushRewrite);

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
            \Duo\NativeActions::execute('rewrite.flush', []);
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
        if (self::option_witness($option) !== null) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy option deletion did not persist; recovery_required'
            );
        }
    }

    /** @return array<string,int|string|bool> */
    private static function projection_snapshot(
        bool $verify,
        bool $includeRewrite,
        bool $includeNativeRewriteEvidence = false
    ): array {
        self::assert_database_identity();
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
            'product_cat_children_canonical_sha256' => $catHierarchy['canonical_sha256'],
            'product_brand_terms' => count($parents['product_brand']),
            'product_brand_parent_sha256' => self::fingerprint(self::parent_rows($parents['product_brand'])),
            'product_brand_children' => $brandHierarchy['links'],
            'product_brand_children_valid' => $brandHierarchy['valid'],
            'product_brand_children_raw_sha256' => $brandHierarchy['raw_sha256'],
            'product_brand_children_canonical_sha256' => $brandHierarchy['canonical_sha256'],
            'category_lookup_rows' => $category['rows'],
            'category_lookup_valid' => $category['valid'],
            'category_lookup_sha256' => $category['sha256'],
        ];
        if ($includeRewrite) {
            $summary['brand_permalink_sha256'] = self::brand_permalink_state()['brand_permalink_sha256'];
            $summary = array_merge($summary, self::permalink_structure_state());
            $summary = array_merge($summary, self::rewrite_rules_state($verify));
            if ($includeNativeRewriteEvidence) {
                $summary = array_merge($summary, self::native_rewrite_evidence());
            }
        }
        return $summary;
    }

    /** @return array<string,int|string|bool> */
    private static function product_permalink_projection(
        bool $verifyPermalink,
        bool $verifyRewrite,
        bool $includeNativeRewriteEvidence = false
    ): array {
        self::assert_options_database_identity();
        $projection = array_merge(
            self::product_permalink_state($verifyPermalink),
            self::brand_permalink_state(),
            self::permalink_structure_state(),
            self::rewrite_rules_state($verifyRewrite)
        );
        if ($includeNativeRewriteEvidence) {
            $projection = array_merge($projection, self::native_rewrite_evidence());
        }
        return $projection;
    }

    private static function assert_category_lookup_schema(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::CATEGORY_TABLE;
        if (isset($wpdb->wc_category_lookup) && (string) $wpdb->wc_category_lookup !== $table) {
            throw new \RuntimeException(
                'duo: WooCommerce category lookup table identity is outside the adapter contract'
            );
        }
        $columnCount = self::strict_uint(\Duo\ProviderSdk::checked_get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s',
            $table
        ), 'WooCommerce category lookup schema cardinality witness'), true, 'category lookup column count');
        if ($columnCount > self::MAX_TABLE_COLUMNS) {
            throw new \RuntimeException(
                'duo: WooCommerce category lookup schema returned an oversized column inventory'
            );
        }
        $columns = \Duo\ProviderSdk::checked_get_results(
            "SHOW COLUMNS FROM `$table`",
            'WooCommerce category lookup schema'
        );
        if (count($columns) !== $columnCount) {
            throw new \RuntimeException(
                'duo: WooCommerce category lookup schema changed after its cardinality witness'
            );
        }
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
     * @return array{links:int,valid:bool,raw_sha256:string,canonical_sha256:string}
     */
    private static function hierarchy_option_state(string $taxonomy, array $expected, bool $verify): array {
        $name = $taxonomy . '_children';
        $raw = self::raw_option($name, $verify);
        if ($raw === null) {
            return [
                'links' => 0,
                'valid' => false,
                'raw_sha256' => hash('sha256', 'missing:' . $name),
                'canonical_sha256' => hash('sha256', 'invalid:' . $name),
            ];
        }
        $rawHash = hash('sha256', $raw);
        try {
            $decoded = PlainData::decode_serialized($raw, "$name option");
            $normalized = self::normalize_children($decoded, $name);
        } catch (\Throwable $exception) {
            if ($verify) {
                throw $exception;
            }
            return [
                'links' => 0,
                'valid' => false,
                'raw_sha256' => $rawHash,
                'canonical_sha256' => hash('sha256', 'invalid:' . $name),
            ];
        }
        $valid = $normalized === $expected;
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
            // A raw canonical projection deliberately replaces the previous
            // hook-bearing "effective" read. get_option() crosses arbitrary
            // pre_option/default/option/alloptions callbacks; matching its
            // return value would neither prove those callbacks inert nor
            // contain their effects. Child-native writes still run their own
            // WordPress hooks; this parent receipt binds only persisted bytes.
            'canonical_sha256' => self::fingerprint($normalized),
        ];
    }

    private static function raw_option(
        string $name,
        bool $verify,
        int $maxBytes = self::MAX_OPTION_BYTES,
        bool $allowMissing = false
    ): ?string {
        $state = self::raw_option_state($name, $verify, $maxBytes, $allowMissing);
        return $state['raw'] ?? null;
    }

    /**
     * @return null|array{id:int,bytes:int,autoload:string,raw:string,raw_sha256:string}
     */
    private static function raw_option_state(
        string $name,
        bool $verify,
        int $maxBytes = self::MAX_OPTION_BYTES,
        bool $allowMissing = false
    ): ?array {
        $witness = self::option_witness($name);
        if ($witness === null) {
            if ($verify && !$allowMissing) {
                throw new \RuntimeException(
                    "duo: WooCommerce $name option is missing; recovery_required"
                );
            }
            return null;
        }
        if ($witness['bytes'] > $maxBytes) {
            if ($verify) {
                throw new \RuntimeException(
                    "duo: WooCommerce $name option exceeds the bounded plain-data contract; recovery_required"
                );
            }
            return null;
        }

        // The witness prevents a dirty 16 MiB+ LONGTEXT value from crossing
        // the PHP boundary. Two exact-id/length reads bind the payload bytes
        // too, so a same-length concurrent rewrite cannot inherit the first
        // witness and become a falsely stable receipt.
        $value = self::option_payload($name, $witness);
        if ($value === null) {
            if ($verify) {
                throw new \RuntimeException(
                    "duo: WooCommerce $name option changed during bounded readback; recovery_required"
                );
            }
            return null;
        }
        $valueHash = hash('sha256', $value);
        unset($value);
        $confirmed = self::option_payload($name, $witness);
        if ($confirmed === null || !hash_equals($valueHash, hash('sha256', $confirmed))) {
            if ($verify) {
                throw new \RuntimeException(
                    "duo: WooCommerce $name option changed during bounded readback; recovery_required"
                );
            }
            return null;
        }
        return [
            'id' => $witness['id'],
            'bytes' => $witness['bytes'],
            'autoload' => $witness['autoload'],
            'raw' => $confirmed,
            'raw_sha256' => hash('sha256', $confirmed),
        ];
    }

    /** @param array{id:int,bytes:int,autoload:string} $witness */
    private static function option_payload(string $name, array $witness): ?string {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, option_value, autoload, "
            . "LENGTH(option_value) AS option_bytes FROM {$wpdb->options} "
            . 'WHERE option_id = %d AND BINARY option_name = BINARY %s '
            . 'AND LENGTH(option_value) = %d ORDER BY option_id ASC LIMIT 2',
            $witness['id'],
            $name,
            $witness['bytes']
        ), "WooCommerce $name bounded raw option readback");
        if (count($rows) !== 1
            || ($rows[0]['option_name'] ?? null) !== $name
            || ($rows[0]['option_id'] ?? null) !== (string) $witness['id']
            || ($rows[0]['option_bytes'] ?? null) !== (string) $witness['bytes']
            || ($rows[0]['autoload'] ?? null) !== $witness['autoload']
            || !is_string($rows[0]['option_value'] ?? null)
            || strlen($rows[0]['option_value']) !== $witness['bytes']) {
            return null;
        }
        return $rows[0]['option_value'];
    }

    /** @return null|array{id:int,bytes:int,autoload:string} */
    private static function option_witness(string $name): ?array {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, autoload, "
            . "LENGTH(option_value) AS option_bytes FROM {$wpdb->options} "
            . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
            $name
        ), "WooCommerce $name raw option witness");
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1 || ($rows[0]['option_name'] ?? null) !== $name) {
            throw new \RuntimeException(
                "duo: WooCommerce $name option is missing, aliased, or duplicated; recovery_required"
            );
        }
        $autoload = $rows[0]['autoload'] ?? null;
        if (!is_string($autoload)
            || !in_array($autoload, ['yes', 'no', 'auto', 'on', 'off', 'auto-on', 'auto-off'], true)) {
            throw new \RuntimeException(
                "duo: WooCommerce $name option carries an invalid autoload wire; recovery_required"
            );
        }
        return [
            'id' => self::strict_uint($rows[0]['option_id'] ?? null, false, "$name option_id"),
            'bytes' => self::strict_uint($rows[0]['option_bytes'] ?? null, true, "$name option bytes"),
            'autoload' => $autoload,
        ];
    }

    private static function assert_database_identity(): void {
        global $wpdb;
        self::assert_options_database_identity();
        if (!isset($wpdb->term_taxonomy)
            || !is_string($wpdb->term_taxonomy)
            || $wpdb->term_taxonomy !== $wpdb->prefix . 'term_taxonomy'
            || preg_match(self::TABLE_IDENTIFIER_PATTERN, $wpdb->term_taxonomy) !== 1
            || preg_match(
                self::TABLE_IDENTIFIER_PATTERN,
                $wpdb->prefix . self::CATEGORY_TABLE
            ) !== 1) {
            throw new \RuntimeException(
                'duo: WooCommerce hierarchy verification requires the exact site database identity'
            );
        }
    }

    private static function assert_options_database_identity(): void {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->prefix, $wpdb->options)
            || !is_string($wpdb->prefix)
            || !is_string($wpdb->options)
            || $wpdb->options !== $wpdb->prefix . 'options'
            || preg_match(self::TABLE_IDENTIFIER_PATTERN, $wpdb->options) !== 1) {
            throw new \RuntimeException(
                'duo: WooCommerce permalink verification requires the exact site options-table identity'
            );
        }
    }

    /** The Woo sanitizer crosses two URL filters; no extension may run there. */
    private static function assert_closed_hook(string $hook): void {
        global $wp_filter;
        if (isset($wp_filter) && !is_array($wp_filter)) {
            throw new \RuntimeException(
                'duo: WooCommerce native hook registry is unreadable; recovery_required'
            );
        }
        $registered = $wp_filter[$hook] ?? null;
        if ($registered === null) {
            return;
        }
        if (!class_exists('\\WP_Hook') || !$registered instanceof \WP_Hook
            || !is_array($registered->callbacks ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce native hook topology is unreadable or extension-owned; recovery_required'
            );
        }
        foreach ($registered->callbacks as $priority => $callbacks) {
            if (!is_int($priority) || !is_array($callbacks)) {
                throw new \RuntimeException(
                    'duo: WooCommerce native hook topology is malformed; recovery_required'
                );
            }
            foreach ($callbacks as $callback) {
                if (!is_array($callback) || array_keys($callback) !== ['function', 'accepted_args']
                    || !is_int($callback['accepted_args'])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce native hook topology has an extension callback; recovery_required'
                    );
                }
                throw new \RuntimeException(
                    'duo: WooCommerce native hook topology has an extension callback; recovery_required'
                );
            }
        }
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
    private static function product_permalink_state(bool $verify): array {
        $name = 'woocommerce_permalinks';
        $rawState = self::raw_option_state($name, $verify, self::MAX_PERMALINK_OPTION_BYTES);
        if ($rawState === null) {
            return [
                'product_permalink_fields' => 0,
                'product_permalink_valid' => false,
                'product_permalink_sha256' => hash('sha256', 'missing:' . $name),
                'product_permalink_raw_sha256' => hash('sha256', 'missing:' . $name),
                'product_permalink_option_id' => 0,
                'product_permalink_autoload' => 'missing',
            ];
        }
        $raw = $rawState['raw'];
        $rawHash = $rawState['raw_sha256'];
        try {
            $stored = self::normalize_product_permalinks(
                PlainData::decode_serialized($raw, "$name option"),
                "$name option"
            );
            $confirmedState = self::raw_option_state($name, true, self::MAX_PERMALINK_OPTION_BYTES);
            if ($confirmedState === null
                || $confirmedState['id'] !== $rawState['id']
                || $confirmedState['autoload'] !== $rawState['autoload']
                || !hash_equals($rawHash, $confirmedState['raw_sha256'])) {
                throw new \RuntimeException(
                    'duo: WooCommerce product permalink validation changed authored storage; recovery_required'
                );
            }
        } catch (\Throwable $exception) {
            if ($verify) {
                throw $exception;
            }
            return [
                'product_permalink_fields' => 0,
                'product_permalink_valid' => false,
                'product_permalink_sha256' => $rawHash,
                'product_permalink_raw_sha256' => $rawHash,
                'product_permalink_option_id' => $rawState['id'],
                'product_permalink_autoload' => $rawState['autoload'],
            ];
        }
        return [
            'product_permalink_fields' => count($stored),
            'product_permalink_valid' => true,
            'product_permalink_sha256' => self::fingerprint($stored),
            'product_permalink_raw_sha256' => $rawHash,
            'product_permalink_option_id' => $rawState['id'],
            'product_permalink_autoload' => $rawState['autoload'],
        ];
    }

    /** @return array{product_base:string,category_base:string,tag_base:string,attribute_base:string,use_verbose_page_rules:bool} */
    private static function normalize_product_permalinks(mixed $value, string $context): array {
        $fields = [
            'product_base',
            'category_base',
            'tag_base',
            'attribute_base',
            'use_verbose_page_rules',
        ];
        if (!is_array($value) || count($value) !== count($fields)) {
            throw new \RuntimeException("duo: $context is not the exact native five-field record");
        }
        $actualFields = array_keys($value);
        sort($actualFields, SORT_STRING);
        $expectedFields = $fields;
        sort($expectedFields, SORT_STRING);
        if ($actualFields !== $expectedFields) {
            throw new \RuntimeException("duo: $context is not the exact native five-field record");
        }
        if (!function_exists('wc_sanitize_permalink')) {
            throw new \RuntimeException(
                'duo: WooCommerce native permalink sanitizer is unavailable; recovery_required'
            );
        }
        // Woo 11.0.x calls $wpdb->strip_invalid_text_for_column() then
        // esc_url_raw() here. The latter reaches both kses_allowed_protocols
        // and clean_url. Equality of a returned string is not evidence that a
        // callback was inert, so admit only the empty exact callback topology
        // before the native sanitizer is allowed to execute.
        self::assert_closed_hook('kses_allowed_protocols');
        self::assert_closed_hook('clean_url');
        foreach (array_slice($fields, 0, 4) as $field) {
            $part = $value[$field] ?? null;
            if (!is_string($part)
                || strlen($part) > self::MAX_PERMALINK_PART_BYTES
                || preg_match('//u', $part) !== 1
                || ($field !== 'attribute_base' && $part === '')) {
                throw new \RuntimeException("duo: $context contains an invalid bounded permalink part");
            }
            $canonical = wc_sanitize_permalink($part);
            if (!is_string($canonical) || !hash_equals($part, $canonical)) {
                throw new \RuntimeException("duo: $context contains noncanonical native permalink bytes");
            }
        }
        if (!is_bool($value['use_verbose_page_rules'])) {
            throw new \RuntimeException("duo: $context contains a non-boolean verbose-rule flag");
        }
        if (rtrim($value['product_base'], "/\\") . '/' === '/%product_brand%/') {
            throw new \RuntimeException(
                "duo: $context uses the reserved sole product-brand base rejected by WooCommerce"
            );
        }
        // Native Woo writers have more than one key order: migrations write
        // product/category/attribute/tag, Settings updates existing positions,
        // and wc_get_permalink_structure() appends defaults. Raw option bytes
        // (and their ID/autoload witness) retain that authored order; this
        // receipt projects only the five field meanings in one fixed order.
        return [
            'product_base' => $value['product_base'],
            'category_base' => $value['category_base'],
            'tag_base' => $value['tag_base'],
            'attribute_base' => $value['attribute_base'],
            'use_verbose_page_rules' => $value['use_verbose_page_rules'],
        ];
    }

    /** @return array<string,int|string|bool> */
    private static function permalink_structure_state(): array {
        $name = 'permalink_structure';
        $state = self::raw_option_state($name, true, self::MAX_PERMALINK_PART_BYTES, true);
        if ($state === null) {
            return [
                'permalink_structure_present' => false,
                'permalink_structure_sha256' => hash('sha256', 'missing:' . $name),
                'permalink_structure_option_id' => 0,
                'permalink_structure_autoload' => 'missing',
            ];
        }
        $decoded = PlainData::decode($state['raw'], "$name raw option");
        if (!is_string($decoded) || !hash_equals($state['raw'], $decoded)
            || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            throw new \RuntimeException(
                'duo: WordPress permalink_structure is not an exact bounded native raw string; recovery_required'
            );
        }
        return [
            'permalink_structure_present' => true,
            'permalink_structure_sha256' => $state['raw_sha256'],
            'permalink_structure_option_id' => $state['id'],
            'permalink_structure_autoload' => $state['autoload'],
        ];
    }

    /** @return array<string,int|string|bool> */
    private static function rewrite_rules_state(bool $verify): array {
        $raw = self::raw_option('rewrite_rules', $verify);
        if ($raw === null) {
            return [
                'rewrite_rules' => 0,
                'rewrite_rules_valid' => false,
                'rewrite_rules_raw_sha256' => hash('sha256', 'missing:rewrite_rules'),
                'rewrite_rules_canonical_sha256' => hash('sha256', 'invalid:rewrite_rules'),
            ];
        }
        $rawHash = hash('sha256', $raw);
        try {
            if ($raw === '') {
                $stored = null;
            } else {
                $decoded = PlainData::decode_serialized($raw, 'rewrite_rules option');
                $stored = self::normalize_rewrite_rules($decoded, 'rewrite_rules option');
            }
        } catch (\Throwable $exception) {
            if ($verify) {
                throw $exception;
            }
            return [
                'rewrite_rules' => 0,
                'rewrite_rules_valid' => false,
                'rewrite_rules_raw_sha256' => $rawHash,
                'rewrite_rules_canonical_sha256' => hash('sha256', 'invalid:rewrite_rules'),
            ];
        }
        return [
            'rewrite_rules' => $stored === null ? 0 : count($stored),
            'rewrite_rules_valid' => true,
            'rewrite_rules_raw_sha256' => $rawHash,
            'rewrite_rules_canonical_sha256' => $stored === null
                ? self::fingerprint(['plain'])
                : self::fingerprint($stored),
        ];
    }

    /**
     * Bind Core's reviewed two-projection receipt to Woo's independently-read
     * raw authored witnesses. NativeActions owns the extension interpreter:
     * its strict, read-only accessor checks the persisted sanitized map and
     * effective runtime map without another rewrite generation. Keeping these
     * names prefixed prevents a native semantic projection from masquerading
     * as a Woo raw option witness.
     *
     * @return array<string,int|string|bool>
     */
    private static function native_rewrite_evidence(): array {
        if (!is_callable(['\Duo\NativeActions', 'rewrite_evidence'])) {
            throw new \RuntimeException(
                'duo: WooCommerce product permalink repair requires Core\'s strict rewrite evidence accessor; '
                . 'recovery_required'
            );
        }
        $evidence = \Duo\NativeActions::rewrite_evidence();
        $keys = [
            'permalink_present',
            'permalink_hash',
            'runtime_permalink_matches',
            'rules_present',
            'rules_type',
            'rules_count',
            'rules_hash',
            'runtime_rules_type',
            'runtime_rules_count',
            'runtime_rules_hash',
        ];
        if (!is_array($evidence) || array_keys($evidence) !== $keys
            || !is_bool($evidence['permalink_present'])
            || !is_string($evidence['permalink_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $evidence['permalink_hash']) !== 1
            || $evidence['runtime_permalink_matches'] !== true
            || $evidence['rules_present'] !== true
            || !in_array($evidence['rules_type'], ['array', 'string'], true)
            || !is_int($evidence['rules_count']) || $evidence['rules_count'] < 0
            || !is_string($evidence['rules_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $evidence['rules_hash']) !== 1
            || !in_array($evidence['runtime_rules_type'], ['array', 'string'], true)
            || !is_int($evidence['runtime_rules_count']) || $evidence['runtime_rules_count'] < 0
            || !is_string($evidence['runtime_rules_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $evidence['runtime_rules_hash']) !== 1
            || ($evidence['rules_type'] === 'string' && $evidence['rules_count'] !== 0)
            || ($evidence['runtime_rules_type'] === 'string' && $evidence['runtime_rules_count'] !== 0)) {
            throw new \RuntimeException(
                'duo: Core rewrite evidence is outside the reviewed native projection; recovery_required'
            );
        }
        return [
            'native_rewrite_permalink_present' => $evidence['permalink_present'],
            'native_rewrite_permalink_sha256' => $evidence['permalink_hash'],
            'native_rewrite_runtime_permalink_matches' => $evidence['runtime_permalink_matches'],
            'native_rewrite_rules_present' => $evidence['rules_present'],
            'native_rewrite_rules_type' => $evidence['rules_type'],
            'native_rewrite_rules_count' => $evidence['rules_count'],
            'native_rewrite_rules_sha256' => $evidence['rules_hash'],
            'native_rewrite_runtime_rules_type' => $evidence['runtime_rules_type'],
            'native_rewrite_runtime_rules_count' => $evidence['runtime_rules_count'],
            'native_rewrite_runtime_rules_sha256' => $evidence['runtime_rules_hash'],
        ];
    }

    /** @return array<string,int|string|bool> */
    private static function brand_permalink_state(): array {
        $name = 'woocommerce_brand_permalink';
        $rawState = self::raw_option_state($name, true, 200, true);
        $raw = $rawState['raw'] ?? null;
        // Absence is WooCommerce's documented empty/default brand base, not
        // a missing dependency. Raw bytes are the authored witness: using
        // get_option() here would cross pre_option/default/option and the
        // alloptions cache filters without proving that their callbacks are
        // side-effect free.
        $value = '';
        if ($raw !== null) {
            $decoded = PlainData::decode($raw, 'woocommerce_brand_permalink raw option');
            if (!is_string($decoded) || !hash_equals($raw, $decoded)) {
                throw new \RuntimeException(
                    'duo: WooCommerce brand permalink is not an exact native raw string; recovery_required'
                );
            }
            $value = $decoded;
        }
        if (strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \RuntimeException(
                'duo: WooCommerce brand permalink is outside the bounded string contract'
            );
        }
        $effectiveHash = hash('sha256', $value);
        return [
            'brand_permalink_present' => $rawState !== null,
            'brand_permalink_sha256' => $effectiveHash,
            'brand_permalink_raw_sha256' => $rawState['raw_sha256']
                ?? hash('sha256', 'missing:' . $name),
            'brand_permalink_canonical_sha256' => $effectiveHash,
            'brand_permalink_option_id' => $rawState['id'] ?? 0,
            'brand_permalink_autoload' => $rawState['autoload'] ?? 'missing',
        ];
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
