<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';
require_once __DIR__ . '/DatabaseQueryIsolation.php';
require_once __DIR__ . '/DatabaseTablePresence.php';
require_once __DIR__ . '/NativeCoreCache.php';

/** Exact current native post types; not an arbitrary native-callback witness. */
final class NativePostTypes {
    private const MAX_IDS = 128;
    private const MAX_CELL_BYTES = 1048576;
    private const MAX_RAW_BYTES = 8388608;
    // get_instance() executes SELECT *, even when its caller wants only type.
    // A nonstandard column cannot be omitted from that allocation preflight.
    private const COLUMNS = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
        'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password',
        'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
        'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
        'post_mime_type', 'comment_count',
    ];

    /** @param list<int> $ids @return list<string|false> */
    public static function read(array $ids, string $context): array {
        try {
            return self::admitted_read($ids, $context);
        } catch (\Throwable $failure) {
            DatabaseQueryIsolation::poison();
            if ($failure instanceof DatabaseQueryIsolationViolationException) throw $failure;
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context native post type input admission failed", 0, $failure
            );
        }
    }

    private static function admitted_read(array $ids, string $context): array {
        global $wpdb;
        DatabaseQueryIsolation::assert_original_all_hook_absent($context);
        DatabaseQueryIsolation::assert_profile_contains([$wpdb->posts], false, $context);
        if (!array_is_list($ids) || $ids === [] || count($ids) > self::MAX_IDS) {
            self::refuse($context, 'requires one bounded nonempty ID list');
        }
        $seen = [];
        foreach ($ids as $id) {
            // Zero/false are not absence probes: get_post() can use global $post.
            if (!is_int($id) || $id < 1 || isset($seen[$id])) {
                self::refuse($context, 'requires unique positive native integer IDs');
            }
            $seen[$id] = true;
        }
        NativeCoreCache::assert_environment($context, 'native post type inputs');
        $core = rtrim(ABSPATH, '/\\') . '/' . WPINC . '/';
        foreach (['get_post_type', 'get_post', 'sanitize_post', 'sanitize_post_field'] as $function) {
            if (!function_exists($function) || realpath($core . 'post.php') === false
                || (new \ReflectionFunction($function))->getFileName() !== realpath($core . 'post.php')) {
                self::refuse($context, 'requires standard core post getters');
            }
        }
        if (!class_exists('WP_Post', false) || realpath($core . 'class-wp-post.php') === false
            || !(new \ReflectionClass('WP_Post'))->isFinal()
            || (new \ReflectionClass('WP_Post'))->getFileName() !== realpath($core . 'class-wp-post.php')) {
            self::refuse($context, 'requires the standard final core post class');
        }
        $cache = new NativeCoreCache('posts', $context, 'native post type inputs');
        $cache->assert_current();
        if (!DatabaseTablePresence::base_table_exists($wpdb->posts)) {
            self::refuse($context, 'requires a resolved plain posts table');
        }
        $schemaSql = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s '
            . 'ORDER BY ORDINAL_POSITION LIMIT 24';
        $schema = self::query($schemaSql, [$wpdb->posts], $context);
        $expectedSchema = array_map(static fn(string $column): array => ['COLUMN_NAME' => $column], self::COLUMNS);
        if ($schema !== $expectedSchema) self::refuse($context, 'requires the closed standard posts column roster');

        $ordered = $ids;
        sort($ordered, SORT_NUMERIC);
        $placeholders = implode(', ', array_fill(0, count($ordered), '%d'));
        $tail = " FROM `{$wpdb->posts}` WHERE ID IN ($placeholders) ORDER BY ID ASC LIMIT " . (count($ordered) + 1);
        $columns = ['LEFT(BINARY ID, 21) AS _wprism_id'];
        foreach (self::COLUMNS as $offset => $column) $columns[] = "OCTET_LENGTH(`$column`) AS _wprism_size_$offset";
        $sizeSql = 'SELECT ' . implode(', ', $columns) . $tail;
        $sizes = self::query($sizeSql, $ordered, $context);
        $sizeKeys = array_merge(['_wprism_id'], array_map(static fn(int $offset): string => '_wprism_size_' . $offset, array_keys(self::COLUMNS)));
        $present = [];
        $total = 0;
        $previous = 0;
        foreach ($sizes as $row) {
            $id = is_array($row) ? self::id($row['_wprism_id'] ?? null) : null;
            if ($id === null || $id <= $previous || !isset($seen[$id]) || array_keys($row) !== $sizeKeys) {
                self::refuse($context, 'found a malformed or duplicate physical ID roster');
            }
            $previous = $id;
            foreach (self::COLUMNS as $offset => $column) {
                $size = $row['_wprism_size_' . $offset];
                if (!is_string($size) || preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $size) !== 1
                    || (int) $size > self::MAX_CELL_BYTES || (int) $size > self::MAX_RAW_BYTES - $total
                    || ($column === 'ID' && (int) $size !== strlen((string) $id))
                    || ($column === 'post_type' && (int) $size > 20)) {
                    self::refuse($context, 'exceeds the full native-row allocation frontier');
                }
                $total += (int) $size;
            }
            $present[$id] = $row['_wprism_size_20'];
        }
        $typeSql = 'SELECT LEFT(BINARY ID, 21) AS _wprism_id, LEFT(BINARY post_type, 21) AS _wprism_type' . $tail;
        $rows = self::query($typeSql, $ordered, $context);
        $types = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? self::id($row['_wprism_id'] ?? null) : null;
            if ($id === null || array_keys($row) !== ['_wprism_id', '_wprism_type'] || !isset($present[$id])
                || isset($types[$id]) || !is_string($row['_wprism_type'])
                || strlen($row['_wprism_type']) !== (int) $present[$id]) {
                self::refuse($context, 'physical types disagree with the admitted ID roster');
            }
            $types[$id] = $row['_wprism_type'];
        }
        if (array_keys($types) !== array_keys($present)) self::refuse($context, 'physical type roster changed after size admission');
        self::assert_cache($cache, $ids, $types, false, $context);
        $results = [];
        foreach ($ids as $id) {
            self::assert_cache($cache, $ids, $types, false, $context);
            $actual = get_post_type($id);
            if ($actual !== ($types[$id] ?? false)) self::refuse($context, 'native type disagrees with its exact physical input');
            $results[] = $actual;
        }
        self::assert_cache($cache, $ids, $types, true, $context);
        if (self::query($sizeSql, $ordered, $context) !== $sizes || self::query($typeSql, $ordered, $context) !== $rows
            || self::query($schemaSql, [$wpdb->posts], $context) !== $schema) {
            self::refuse($context, 'physical post inputs changed during native admission');
        }
        return $results;
    }

    private static function assert_cache(NativeCoreCache $cache, array $ids, array $types, bool $after, string $context): void {
        $entries = $cache->entries();
        $bytes = 0;
        foreach ($ids as $id) {
            $key = $cache->key($id);
            if (!array_key_exists($key, $entries)) {
                if ($after && isset($types[$id])) self::refuse($context, 'native post cache did not retain the admitted row');
                continue;
            }
            $post = $entries[$key];
            if (!isset($types[$id]) || !is_object($post) || !in_array(get_class($post), ['stdClass', 'WP_Post'], true)) {
                self::refuse($context, 'found an unsafe or presence-inconsistent cached post');
            }
            // Core clones the object and then copies every public property
            // into WP_Post, even though get_post_type consumes only one. An
            // inert exact class alone does not bound that allocation frontier.
            $fields = 0;
            foreach ($post as $name => $value) {
                if (++$fields > count(self::COLUMNS) + 1 || (!in_array($name, self::COLUMNS, true) && $name !== 'filter')
                    || (!is_string($value) && !is_int($value))) {
                    self::refuse($context, 'cached post exceeds the closed plain-field frontier');
                }
                $size = is_string($value) ? strlen($value) : strlen((string) $value);
                if ($size > self::MAX_CELL_BYTES || $size > self::MAX_RAW_BYTES - $bytes) {
                    self::refuse($context, 'cached post exceeds the native allocation frontier');
                }
                $bytes += $size;
            }
            $raw = get_object_vars($post);
            if (self::id($raw['ID'] ?? null) !== $id || ($raw['post_type'] ?? null) !== $types[$id]
                || ($raw['filter'] ?? null) !== 'raw') {
                self::refuse($context, 'cached post identity, type or filter disagrees with its physical input');
            }
        }
    }

    private static function id(mixed $value): ?int {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }

    private static function query(string $sql, array $args, string $context): array {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        $wpdb->last_error = '';
        try {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            if (!is_array($rows) || !array_is_list($rows) || (string) ($wpdb->last_error ?? '') !== '') {
                self::refuse($context, 'checked physical input read failed');
            }
            return $rows;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function refuse(string $context, string $reason): never {
        DatabaseQueryIsolation::violation("wprism: $context native post type inputs $reason");
    }
}
