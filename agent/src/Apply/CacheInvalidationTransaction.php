<?php
namespace Duo;

require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}

/**
 * Transaction-scoped cache invalidation for raw WordPress writes.
 *
 * The in-process object cache is not transactional. Deleting an option key
 * before COMMIT is insufficient because a later read in the same request can
 * repopulate the pre-commit value. A persistent external option cache is even
 * less tractable: a competing request can publish old database bytes after
 * our post-commit delete, and core exposes no generation/CAS fence for the
 * options/alloptions/notoptions groups. Raw transactional option writes must
 * therefore refuse that topology. For WordPress's request-local cache, purge
 * both immediately and after the database outcome; a process crash discards
 * the cache with the process.
 */
final class CacheInvalidationTransaction {
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_AUTOLOAD_BYTES = 64;
    private static bool $active = false;
    /** @var array<string,array{key:int|string,group:string}> */
    private static array $cacheKeys = [];
    /** @var array<string,true> */
    private static array $generationGroups = [];
    private static int $generationSequence = 0;
    private static ?string $optionLockIndex = null;
    /** @var array<string,?array{option_name:string,option_value:string,autoload:string}> */
    private static array $hierarchyOptionRows = [];
    /** @var array<string,true> */
    private static array $invalidatedHierarchyOptions = [];
    /** @var array<string,bool> */
    private static array $termTaxonomyHierarchy = [];

    public static function begin(): void {
        if (self::$active) {
            throw new \RuntimeException('duo: cache invalidation transaction was already active');
        }
        self::$active = true;
        self::$cacheKeys = [];
        self::$generationGroups = [];
        self::$optionLockIndex = null;
        self::$hierarchyOptionRows = [];
        self::$invalidatedHierarchyOptions = [];
        self::$termTaxonomyHierarchy = [];
    }

    public static function is_active(): bool {
        return self::$active;
    }

    public static function assert_local_cache(string $purpose): void {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            throw new \RuntimeException(
                "duo: $purpose refuses raw authored transaction mutation while a persistent external "
                . 'object cache is active; WordPress exposes no backend-independent version/CAS fence across '
                . 'options, sidebars/widgets, terms, term metadata, taxonomy relationships, and user/post metadata'
            );
        }
    }

    /** Narrow compatibility name for option call sites; the gate is transaction-wide. */
    public static function assert_local_option_cache(string $purpose): void {
        self::assert_local_cache($purpose);
    }

    public static function queue_option(string $name, string $purpose): void {
        self::queue_roster([
            [$name, 'options'],
            ['alloptions', 'options'],
            ['notoptions', 'options'],
        ], [], $purpose);
    }

    public static function queue(int|string $key, string $group, string $purpose): void {
        self::queue_roster([[$key, $group]], [], $purpose);
    }

    /** Advance a cache query generation without invoking WordPress hooks or SQL. */
    public static function queue_generation(string $group, string $purpose): void {
        self::queue_roster([], [$group], $purpose);
    }

    public static function queue_post(int $postId, string $postType, string $purpose): void {
        self::assert_positive_id($postId, $purpose);
        if (preg_match('/^[a-z0-9_-]{1,20}$/D', $postType) !== 1) {
            throw new \RuntimeException("duo: $purpose requested post cache invalidation for a malformed post type");
        }
        $entries = [
            [$postId, 'posts'],
            [$postId, 'post_meta'],
            ['post_parent:' . $postId, 'posts'],
            ['wp_get_archives', 'general'],
        ];
        if ($postType === 'page') {
            $entries[] = ['all_page_ids', 'posts'];
        }
        self::queue_roster($entries, ['posts'], $purpose);
    }

    public static function queue_user_meta(int $userId, string $purpose): void {
        self::assert_positive_id($userId, $purpose);
        self::queue_roster(
            [[$userId, 'user_meta']],
            ['users'],
            $purpose
        );
    }

    public static function queue_term(int $termId, string $taxonomy, string $purpose): void {
        self::assert_positive_id($termId, $purpose);
        $entries = [[$termId, 'terms'], [$termId, 'term_meta']];
        if ($taxonomy !== '') {
            self::assert_taxonomy($taxonomy, $purpose);
            self::assert_term_taxonomy_prepared($taxonomy, $purpose);
            $entries[] = ['all_ids', $taxonomy];
            $entries[] = ['get', $taxonomy];
            if (self::$termTaxonomyHierarchy[$taxonomy]) {
                $entries[] = [$taxonomy . '_children', 'options'];
                $entries[] = ['alloptions', 'options'];
                $entries[] = ['notoptions', 'options'];
            }
        }
        self::queue_roster($entries, ['terms'], $purpose);
        if ($taxonomy !== '' && self::$termTaxonomyHierarchy[$taxonomy]) {
            self::invalidate_term_hierarchy($taxonomy, $purpose);
        }
    }

    /**
     * Lock every potentially touched taxonomy hierarchy option before the
     * first authored row mutation. WordPress's clean_taxonomy_cache() deletes
     * these rows but also fires hooks and runs undeclared SQL, so Duo performs
     * only the exact row delete under its own transaction and cache receipt.
     *
     * @param array<string,bool> $taxonomies taxonomy => native hierarchical flag
     */
    public static function prepare_term_hierarchy_options(array $taxonomies): void {
        if (!self::$active) {
            throw new \RuntimeException('duo: term hierarchy option preparation requires the authored transaction');
        }
        if (array_is_list($taxonomies)) {
            throw new \RuntimeException('duo: term hierarchy option preparation requires an explicit taxonomy=>bool roster');
        }
        $names = [];
        foreach ($taxonomies as $taxonomy => $hierarchical) {
            self::assert_taxonomy($taxonomy, 'term hierarchy option preparation');
            if (!is_bool($hierarchical) || array_key_exists($taxonomy, self::$termTaxonomyHierarchy)) {
                throw new \RuntimeException('duo: term hierarchy option preparation received a malformed/duplicate roster');
            }
            self::$termTaxonomyHierarchy[$taxonomy] = $hierarchical;
            if ($hierarchical) {
                $names[$taxonomy . '_children'] = true;
            }
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if (!array_key_exists($name, self::$hierarchyOptionRows)) {
                self::$hierarchyOptionRows[$name] = self::lock_option_row(
                    $name,
                    'term hierarchy option preparation'
                );
            }
        }
    }

    public static function assert_term_taxonomy_prepared(string $taxonomy, string $purpose): void {
        self::assert_taxonomy($taxonomy, $purpose);
        if (!array_key_exists($taxonomy, self::$termTaxonomyHierarchy)) {
            throw new \RuntimeException("duo: $purpose lacks the pre-mutation taxonomy hierarchy classification");
        }
        if (self::$termTaxonomyHierarchy[$taxonomy]
            && !array_key_exists($taxonomy . '_children', self::$hierarchyOptionRows)) {
            throw new \RuntimeException("duo: $purpose lacks the pre-mutation hierarchical taxonomy option lock");
        }
    }

    public static function queue_relationship(int $objectId, string $taxonomy, string $purpose): void {
        self::assert_positive_id($objectId, $purpose);
        self::assert_taxonomy($taxonomy, $purpose);
        self::queue_roster(
            [[$objectId, $taxonomy . '_relationships']],
            ['terms'],
            $purpose
        );
    }

    /**
     * Exact wp_options row/gap lock shared by every whole-blob transactional
     * writer. Compact hashes and lengths cross the driver before LONGTEXT;
     * the full row is then bound to that witness under the same next-key lock.
     *
     * @return ?array{option_name:string,option_value:string,autoload:string}
     */
    public static function lock_option_row(string $name, string $purpose): ?array {
        global $wpdb;
        if (!self::$active) {
            throw new \RuntimeException("duo: $purpose attempted option row locking outside the authored transaction");
        }
        self::assert_local_cache($purpose);
        self::assert_option_name($name, $purpose);
        DeleteGuardEvaluator::assert_table_identifiers([$wpdb->options], $purpose);
        DeleteGuardEvaluator::assert_active_transaction($purpose);
        DeleteGuardEvaluator::assert_transaction_isolation($purpose);
        if (self::$optionLockIndex === null) {
            DeleteGuardEvaluator::assert_innodb_tables([$wpdb->options], $purpose);
            self::$optionLockIndex = DeleteGuardEvaluator::full_width_lock_index(
                $wpdb->options,
                'option_name',
                $purpose,
                true
            );
        }
        $index = self::$optionLockIndex;
        $predicate = "FROM {$wpdb->options} FORCE INDEX (`$index`) WHERE option_name = %s "
            . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE';
        $wpdb->last_error = '';
        $sizes = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
            . "OCTET_LENGTH(autoload) AS autoload_bytes $predicate",
            $name
        ), ARRAY_A);
        if (!is_array($sizes)
            || !array_is_list($sizes)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose compact option lock read failed");
        }
        if (count($sizes) > 1) {
            throw new \RuntimeException("duo: $purpose found ambiguous collation-equal option rows");
        }
        if ($sizes === []) return null;
        $size = $sizes[0];
        $valueBytes = is_array($size) ? self::canonical_size($size['option_value_bytes'] ?? null) : null;
        $autoloadBytes = is_array($size) ? self::canonical_size($size['autoload_bytes'] ?? null) : null;
        if (!is_array($size)
            || array_keys($size) !== ['option_name', 'option_value_bytes', 'autoload_bytes']
            || !is_string($size['option_name'] ?? null)
            || !hash_equals($name, $size['option_name'])
            || $valueBytes === null
            || $autoloadBytes === null
            || $valueBytes > self::MAX_OPTION_VALUE_BYTES
            || $autoloadBytes > self::MAX_AUTOLOAD_BYTES) {
            throw new \RuntimeException("duo: $purpose compact option lock row is malformed, aliased, or oversized");
        }
        $wpdb->last_error = '';
        $hashRows = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, SHA2(option_value, 256) AS option_value_sha256, '
            . "SHA2(autoload, 256) AS autoload_sha256 $predicate",
            $name
        ), ARRAY_A);
        if (!is_array($hashRows)
            || !array_is_list($hashRows)
            || count($hashRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose bounded option hash witness failed or changed");
        }
        $hashRow = $hashRows[0];
        $valueHash = is_array($hashRow)
            ? self::canonical_sha256($hashRow['option_value_sha256'] ?? null)
            : null;
        $autoloadHash = is_array($hashRow)
            ? self::canonical_sha256($hashRow['autoload_sha256'] ?? null)
            : null;
        if (!is_array($hashRow)
            || array_keys($hashRow) !== ['option_name', 'option_value_sha256', 'autoload_sha256']
            || !is_string($hashRow['option_name'] ?? null)
            || !hash_equals($name, $hashRow['option_name'])
            || $valueHash === null
            || $autoloadHash === null) {
            throw new \RuntimeException("duo: $purpose bounded option hash witness is malformed or aliased");
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value, autoload $predicate",
            $name
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || count($rows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose bounded option payload read failed");
        }
        $row = $rows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value', 'autoload']
            || !is_string($row['option_name'] ?? null)
            || !is_string($row['option_value'] ?? null)
            || !is_string($row['autoload'] ?? null)
            || !hash_equals($name, $row['option_name'])
            || strlen($row['option_value']) !== $valueBytes
            || strlen($row['autoload']) !== $autoloadBytes
            || !hash_equals($valueHash, hash('sha256', $row['option_value']))
            || !hash_equals($autoloadHash, hash('sha256', $row['autoload']))) {
            throw new \RuntimeException("duo: $purpose option payload disagrees with its locked compact witness");
        }
        return $row;
    }

    public static function assert_option_row(
        string $name,
        string $value,
        string $autoload,
        string $purpose
    ): void {
        $row = self::lock_option_row($name, $purpose);
        if ($row === null
            || !hash_equals($value, $row['option_value'])
            || !hash_equals($autoload, $row['autoload'])) {
            throw new \RuntimeException("duo: $purpose did not persist the exact option row");
        }
    }

    /** Run only after COMMIT or ROLLBACK has returned. */
    public static function finish(): void {
        if (!self::$active) {
            return;
        }
        $first = self::attempt_pending();
        $second = $first === [] ? [] : self::attempt_pending();
        if ($second !== []) {
            throw new \RuntimeException(
                'duo: authored cache invalidation remained incomplete after exhaustive post-outcome retry; '
                . implode('; ', $second)
            );
        }
        self::$cacheKeys = [];
        self::$generationGroups = [];
    }

    public static function end(): void {
        self::$cacheKeys = [];
        self::$generationGroups = [];
        self::$optionLockIndex = null;
        self::$hierarchyOptionRows = [];
        self::$invalidatedHierarchyOptions = [];
        self::$termTaxonomyHierarchy = [];
        self::$active = false;
    }

    private static function purge(int|string $key, string $group): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete($key, $group);
    }

    private static function refresh_generation(string $group): void {
        if (!function_exists('wp_cache_set')) {
            return;
        }
        ++self::$generationSequence;
        wp_cache_set(
            'last_changed',
            hrtime(true) . ':' . self::$generationSequence,
            $group
        );
    }

    /**
     * Register the entire composite before attempting any primitive. A first
     * cache-backend failure must not prevent later keys from being known to
     * finish(), which retries every registered primitive after DB outcome.
     *
     * @param list<array{0:int|string,1:string}> $entries
     * @param list<string> $generations
     */
    private static function queue_roster(array $entries, array $generations, string $purpose): void {
        if (!self::$active) {
            throw new \RuntimeException("duo: $purpose attempted cache invalidation outside the authored transaction");
        }
        self::assert_local_cache($purpose);
        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) !== 2) {
                throw new \RuntimeException("duo: $purpose requested a malformed cache invalidation roster");
            }
            [$key, $group] = $entry;
            self::assert_cache_group($group, $purpose);
            $identity = get_debug_type($key) . ':' . (string) $key . "\0" . $group;
            self::$cacheKeys[$identity] = ['key' => $key, 'group' => $group];
        }
        foreach ($generations as $group) {
            self::assert_cache_group($group, $purpose);
            self::$generationGroups[$group] = true;
        }
        $failures = self::attempt_roster($entries, $generations);
        if ($failures !== []) {
            throw new \RuntimeException(
                "duo: $purpose cache invalidation failed after attempting the complete composite; "
                . implode('; ', $failures)
            );
        }
    }

    /** @return list<string> */
    private static function attempt_pending(): array {
        return self::attempt_roster(
            array_map(
                static fn(array $entry): array => [$entry['key'], $entry['group']],
                array_values(self::$cacheKeys)
            ),
            array_keys(self::$generationGroups)
        );
    }

    /**
     * @param list<array{0:int|string,1:string}> $entries
     * @param list<string> $generations
     * @return list<string>
     */
    private static function attempt_roster(array $entries, array $generations): array {
        $failures = [];
        foreach ($entries as [$key, $group]) {
            try {
                self::purge($key, $group);
            } catch (\Throwable $failure) {
                $failures[] = 'delete=' . self::failure_fingerprint($failure);
            }
        }
        foreach ($generations as $group) {
            try {
                self::refresh_generation($group);
            } catch (\Throwable $failure) {
                $failures[] = 'generation=' . self::failure_fingerprint($failure);
            }
        }
        return $failures;
    }

    private static function assert_cache_group(mixed $group, string $purpose): void {
        if (!is_string($group)
            || $group === ''
            || strlen($group) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $group) === 1) {
            throw new \RuntimeException("duo: $purpose requested a malformed cache group");
        }
    }

    private static function failure_fingerprint(\Throwable $failure): string {
        $message = $failure->getMessage();
        return get_class($failure) . ':' . strlen($message) . ':'
            . substr(hash('sha256', $message), 0, 16);
    }

    private static function assert_option_name(string $name, string $purpose): void {
        $characters = preg_match_all('/./us', $name, $unused);
        if ($name === ''
            || strlen($name) > 764
            || !is_int($characters)
            || $characters < 1
            || $characters > 191
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \RuntimeException("duo: $purpose requested a malformed option name");
        }
    }

    private static function canonical_size(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) return null;
        $size = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($size) ? $size : null;
    }

    private static function canonical_sha256(mixed $value): ?string {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1 ? $value : null;
    }

    private static function assert_positive_id(int $id, string $purpose): void {
        if ($id <= 0) {
            throw new \RuntimeException("duo: $purpose requested cache invalidation for a nonpositive identity");
        }
    }

    private static function invalidate_term_hierarchy(string $taxonomy, string $purpose): void {
        self::assert_term_taxonomy_prepared($taxonomy, $purpose);
        if (!self::$termTaxonomyHierarchy[$taxonomy]) {
            throw new \LogicException("duo: $purpose attempted hierarchy invalidation for a non-hierarchical taxonomy");
        }
        $name = $taxonomy . '_children';
        if (isset(self::$invalidatedHierarchyOptions[$name])) {
            return;
        }
        $row = self::$hierarchyOptionRows[$name];
        if ($row !== null) {
            global $wpdb;
            Db::delete(
                $wpdb->options,
                ['option_name' => $name],
                null,
                "$purpose invalidate taxonomy hierarchy option"
            );
        }
        self::queue_option($name, "$purpose invalidate taxonomy hierarchy option");
        if (self::lock_option_row($name, "$purpose hierarchy option readback") !== null) {
            throw new \RuntimeException("duo: $purpose failed to remove the exact taxonomy hierarchy option");
        }
        self::$invalidatedHierarchyOptions[$name] = true;
    }

    private static function assert_taxonomy(mixed $taxonomy, string $purpose): void {
        if (!is_string($taxonomy) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $taxonomy) !== 1) {
            throw new \RuntimeException("duo: $purpose received a malformed taxonomy identity");
        }
    }
}
