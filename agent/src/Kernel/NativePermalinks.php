<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';
require_once __DIR__ . '/DatabaseQueryIsolation.php';
require_once __DIR__ . '/DatabaseTablePresence.php';
require_once __DIR__ . '/ExactOptionReader.php';
require_once __DIR__ . '/NativeCoreCache.php';
require_once __DIR__ . '/PhysicalTableRows.php';

/**
 * Owned core permalink reads, never a caller-supplied callback or URL builder.
 *
 * A physical primary WP_Post alone does not admit get_permalink(): pages read
 * ancestor caches and WP_Rewrite, while all paths read options and filters.
 * This scope proves its current inputs before the first native call and does
 * not clear, replace or prime caches to make an inconsistent premise pass.
 * Additional native branches must close their dependencies before admission.
 */
final class NativePermalinks {
    private const MAX_IDS = 128;
    private const MAX_ROWS = 8192;
    private const MAX_BYTES = 16777216;
    private const MAX_URL_BYTES = 16384;
    private const POST_COLUMNS = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
        'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password',
        'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
        'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
        'post_mime_type', 'comment_count',
    ];
    private const OPTIONS = ['home', 'permalink_structure', 'show_on_front', 'page_on_front'];
    private const CLOSED_HOOKS = [
        'home_url', 'set_url_scheme', 'pre_post_link', 'post_link', 'post_type_link',
        'page_link', '_get_page_link', 'get_page_uri', 'user_trailingslashit', 'wp_parse_str',
        'get_post_status', 'is_post_status_viewable',
        'pre_option', 'pre_wp_load_alloptions', 'pre_cache_alloptions',
        'alloptions', 'wp_autoload_values_to_autoload',
    ];
    private array $posts = [];
    private array $selected = [];
    private array $requested = [];
    private array $options = [];
    private array $hooks = [];
    private array $runtime = [];
    private bool $privatePosts = false;
    private ?NativeCoreCache $postCache = null;
    private ?NativeCoreCache $optionCache = null;
    private ?object $rewrite = null;
    private string|false $pageStructure = false;

    private function __construct(private readonly string $context) {
    }

    /** @param list<int> $ids @return array{home:string,permalinks:list<string|false>} */
    public static function read(array $ids, string $context): array {
        try {
            return (new self($context))->admitted_read($ids);
        } catch (\Throwable $failure) {
            DatabaseQueryIsolation::poison();
            if ($failure instanceof DatabaseQueryIsolationViolationException) throw $failure;
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context native permalink input admission failed", 0, $failure
            );
        }
    }

    private function admitted_read(array $ids): array {
        global $wpdb;
        DatabaseQueryIsolation::assert_original_all_hook_absent($this->context);
        DatabaseQueryIsolation::assert_profile_contains([$wpdb->posts, $wpdb->options], false, $this->context);
        if (!array_is_list($ids) || count($ids) > self::MAX_IDS) $this->refuse('requires a bounded ID list');
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || isset($this->selected[$id])) {
                $this->refuse('requires unique positive native integer IDs');
            }
            $this->selected[$id] = true;
        }
        $this->requested = $ids;
        $this->assert_core();
        $physical = $this->physical_posts();
        $optionPhysical = $this->physical_options();
        foreach ($physical['rows'] as $row) $this->posts[(int) $row['ID']] = $this->normalize_post($row);
        foreach (self::OPTIONS as $name) {
            $this->options[$name] = ExactOptionReader::read_row($name, $this->context, $wpdb, self::MAX_URL_BYTES);
        }
        // get_option(home) can recurse to siteurl and trim raw bytes. Admit the
        // ordinary nonempty spelling explicitly; never silently substitute it.
        $home = $this->options['home']['value'] ?? null;
        $structure = $this->options['permalink_structure']['value'] ?? null;
        if (!is_string($home) || $home === '' || rtrim($home, '/') !== $home
            || $this->options['home']['raw'] !== $home || !is_string($structure)
            || $this->options['permalink_structure']['raw'] !== $structure) {
            $this->refuse('requires ordinary nonempty home and plain permalink options');
        }
        $this->assert_home_input($home);
        foreach (['show_on_front', 'page_on_front'] as $name) {
            if ($this->options[$name] !== null && (!is_string($this->options[$name]['value'])
                || $this->options[$name]['raw'] !== $this->options[$name]['value'])) {
                $this->refuse('found a nonplain front-page option');
            }
        }
        $this->postCache = new NativeCoreCache('posts', $this->context, 'native permalink inputs');
        $this->optionCache = new NativeCoreCache('options', $this->context, 'native permalink inputs');
        $this->admit_post_graph();
        $this->admit_hooks();
        $this->assert_caches();
        $this->runtime = $this->runtime_state();
        $this->admit_rewrite($structure);
        $this->assert_current();
        $result = ['home' => home_url(), 'permalinks' => []];
        $this->assert_url($result['home']);
        foreach ($ids as $id) {
            $this->assert_current();
            // The integer call exercises the admitted real cache/cold getter,
            // not an injected object that conceals native secondary reads.
            $url = get_permalink($id);
            if ($url !== false) $this->assert_url($url);
            $result['permalinks'][] = $url;
        }
        $this->assert_current();
        if ($this->physical_posts() !== $physical) $this->refuse('physical posts changed during native reads');
        if ($this->physical_options() !== $optionPhysical) $this->refuse('physical option allocation inputs changed during native reads');
        foreach ($this->options as $name => $row) {
            if (ExactOptionReader::read_row($name, $this->context, $wpdb, self::MAX_URL_BYTES) !== $row) {
                $this->refuse('physical options changed during native reads');
            }
        }
        return $result;
    }

    private function assert_core(): void {
        if (!defined('ABSPATH') || !defined('WPINC')) $this->refuse('require ordinary WordPress with its request-local core cache');
        $files = [
            'post.php' => ['get_post', 'get_post_type', 'get_post_types', 'get_post_type_object',
                'get_post_status', 'get_post_status_object', 'is_post_status_viewable',
                'get_post_ancestors', 'get_page_uri', 'sanitize_post', 'sanitize_post_field'],
            'link-template.php' => ['get_permalink', 'get_post_permalink', 'get_page_link', '_get_page_link',
                'wp_force_plain_post_permalink', 'home_url', 'get_home_url', 'set_url_scheme', 'user_trailingslashit'],
            'option.php' => ['get_option', 'wp_load_alloptions'],
            'functions.php' => ['_config_wp_home', 'add_query_arg', 'wp_parse_args', 'wp_filter_object_list'],
            'load.php' => ['is_ssl', 'is_multisite', 'wp_installing', 'wp_using_ext_object_cache'],
            'plugin.php' => ['apply_filters'],
            'formatting.php' => ['trailingslashit', 'untrailingslashit', 'wp_parse_str'],
        ];
        foreach ($files as $file => $functions) {
            foreach ($functions as $function) {
                if (!function_exists($function) || (new \ReflectionFunction($function))->getFileName() !== $this->core_file($file)) {
                    $this->refuse('require standard core permalink functions');
                }
            }
        }
        foreach (['WP_Post' => 'class-wp-post.php', 'WP_Post_Type' => 'class-wp-post-type.php',
            'WP_Rewrite' => 'class-wp-rewrite.php'] as $class => $file) {
            if (!class_exists($class, false) || (new \ReflectionClass($class))->getFileName() !== $this->core_file($file)
                || ($class === 'WP_Post' && !(new \ReflectionClass($class))->isFinal())) {
                $this->refuse('require standard core permalink classes');
            }
        }
        NativeCoreCache::assert_environment($this->context, 'native permalink inputs');
        if (is_multisite()) $this->refuse('require single-site WordPress');
    }

    private function core_file(string $file): string {
        $path = realpath(rtrim(ABSPATH, '/\\') . '/' . WPINC . '/' . $file);
        if ($path === false) $this->refuse('cannot resolve a required core source');
        return $path;
    }

    private function admit_hooks(): void {
        $names = self::CLOSED_HOOKS;
        if ($this->privatePosts) array_push($names, 'map_meta_cap', 'user_has_cap');
        foreach (self::OPTIONS as $name) {
            foreach (['pre_option_', 'option_', 'default_option_'] as $prefix) $names[] = $prefix . $name;
        }
        foreach ($names as $name) {
            $hook = $GLOBALS['wp_filter'][$name] ?? null;
            if ($hook === null) {
                if (array_key_exists($name, $GLOBALS['wp_filter'])) $this->refuse('found malformed native hook storage');
                $this->hooks[$name] = null;
                continue;
            }
            if (!is_object($hook) || get_class($hook) !== 'WP_Hook'
                || (new \ReflectionClass($hook))->getFileName() !== $this->core_file('class-wp-hook.php')
                || !is_array($hook->callbacks)) $this->refuse('found substituted native hook storage');
            // This stock participant has a complete, immutable dependency:
            // _config_wp_home consumes only its value and the WP_HOME constant.
            $expected = match ($name) {
                'option_home' => [10 => ['_config_wp_home' => ['function' => '_config_wp_home', 'accepted_args' => 1]]],
                'user_has_cap' => [1 => [
                    'wp_maybe_grant_install_languages_cap' => ['function' => 'wp_maybe_grant_install_languages_cap', 'accepted_args' => 1],
                    'wp_maybe_grant_resume_extensions_caps' => ['function' => 'wp_maybe_grant_resume_extensions_caps', 'accepted_args' => 1],
                    'wp_maybe_grant_site_health_caps' => ['function' => 'wp_maybe_grant_site_health_caps', 'accepted_args' => 4],
                ]],
                default => [],
            };
            if ($hook->callbacks !== $expected) $this->refuse('found an unreviewed native permalink hook participant');
            $this->hooks[$name] = ['object' => $hook, 'callbacks' => $hook->callbacks];
        }
        if (defined('WP_HOME') && (!is_string(WP_HOME) || strlen(WP_HOME) > self::MAX_URL_BYTES || WP_HOME === '')) {
            $this->refuse('found a malformed constant-home participant input');
        }
        if (defined('WP_HOME')) $this->assert_home_input(WP_HOME);
    }

    private function physical_posts(): array {
        global $wpdb;
        if (!DatabaseTablePresence::base_table_exists($wpdb->posts)) $this->refuse('require a resolved plain posts table');
        $previous = $wpdb->suppress_errors(true);
        $wpdb->last_error = '';
        try {
            $schema = $wpdb->get_results($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s '
                . 'ORDER BY ORDINAL_POSITION LIMIT 24', $wpdb->posts), ARRAY_A);
            if ((string) ($wpdb->last_error ?? '') !== ''
                || $schema !== array_map(static fn(string $column): array => ['COLUMN_NAME' => $column], self::POST_COLUMNS)) {
                $this->refuse('require the closed standard posts column roster');
            }
        } finally {
            $wpdb->suppress_errors($previous);
        }
        return PhysicalTableRows::observe(['table' => $wpdb->posts, 'columns' => self::POST_COLUMNS,
            'identity' => ['ID'], 'max_rows' => self::MAX_ROWS, 'max_raw_bytes' => self::MAX_BYTES,
            'mode' => 'rows'], $this->context . ' complete permalink post inputs');
    }

    private function normalize_post(array $row): array {
        if (array_keys($row) !== self::POST_COLUMNS) $this->refuse('found a malformed physical post');
        foreach ($row as $name => $value) {
            if (!is_string($value)) $this->refuse('found a nonstring physical post field');
            if (in_array($name, ['ID', 'post_author', 'post_parent', 'menu_order'], true)) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if (!is_int($integer) || (string) $integer !== $value
                    || ($name === 'ID' && $integer < 1) || ($name !== 'menu_order' && $integer < 0)) {
                    $this->refuse('found a noncanonical native post integer');
                }
                $row[$name] = $integer;
            }
        }
        return $row + ['filter' => 'raw'];
    }

    private function physical_options(): array {
        global $wpdb;
        // A cold wp_load_alloptions can allocate the complete options table,
        // not merely the selected semantic keys. Reuse the generic physical
        // size-first witness to bound that otherwise hidden allocation input.
        return PhysicalTableRows::observe(['table' => $wpdb->options,
            'columns' => ['option_id', 'option_name', 'option_value', 'autoload'],
            'identity' => ['option_id'], 'max_rows' => self::MAX_ROWS, 'max_raw_bytes' => self::MAX_BYTES,
            'mode' => 'digest'], $this->context . ' complete permalink option allocation inputs');
    }

    private function admit_post_graph(): void {
        $structure = $this->options['permalink_structure']['value'];
        foreach (array_keys($this->selected) as $id) {
            $row = $this->posts[$id] ?? null;
            if ($row === null) continue;
            $type = $GLOBALS['wp_post_types'][$row['post_type']] ?? null;
            if (in_array($row['post_type'], ['attachment', 'revision'], true)
                || !is_object($type) || get_class($type) !== 'WP_Post_Type') {
                $this->refuse('post type reaches an unclosed native permalink branch');
            }
            if (!in_array($row['post_status'], ['publish', 'draft', 'pending', 'future', 'private'], true)) {
                $this->refuse('post status reaches an unclosed native capability branch');
            }
            if ($row['post_status'] === 'private') $this->privatePosts = true;
            $typeFields = get_object_vars($type);
            if (!is_bool($typeFields['_builtin'] ?? null)) $this->refuse('found a malformed native post-type flag');
            if ($typeFields['_builtin'] && $row['post_type'] !== 'page' && $row['post_status'] === 'publish' && $structure !== '') {
                if (str_contains($structure, '%category%') || str_contains($structure, '%author%')) {
                    $this->refuse('permalink structure reaches an unclosed native term or user branch');
                }
                if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $row['post_date']) !== 1) {
                    $this->refuse('found a malformed native permalink date');
                }
            }
            // Core detects cycles, but a bounded ancestor graph also closes
            // query work before get_page_uri begins its two native walks.
            $ancestors = [$id => true];
            $parent = $row['post_parent'];
            while ($parent !== 0 && !isset($ancestors[$parent])) {
                if (count($ancestors) >= self::MAX_IDS) $this->refuse('ancestor graph exceeds the bounded frontier');
                $ancestors[$parent] = true;
                $this->selected[$parent] = true;
                $parent = $this->posts[$parent]['post_parent'] ?? 0;
            }
        }
    }

    private function assert_caches(): void {
        $entries = $this->postCache->entries();
        foreach ($this->selected as $id => $_) {
            $key = $this->postCache->key($id);
            if (!array_key_exists($key, $entries)) continue;
            $post = $entries[$key];
            if (!isset($this->posts[$id]) || !is_object($post) || !in_array(get_class($post), ['stdClass', 'WP_Post'], true)) {
                $this->refuse('found an unsafe or ghost cached post');
            }
            $actual = get_object_vars($post);
            // Validate inert scalars before any cast or native cache getter;
            // WP_Object_Cache::get clones objects before callers see them.
            if (count($actual) !== count(self::POST_COLUMNS) + 1) $this->refuse('found an incomplete cached post');
            foreach ($this->posts[$id] as $name => $value) {
                if (!array_key_exists($name, $actual) || (!is_string($actual[$name]) && !is_int($actual[$name]))
                    || (string) $actual[$name] !== (string) $value
                    || (in_array($name, ['ID', 'post_parent', 'menu_order'], true) && $actual[$name] !== $value)) {
                    $this->refuse('cached post fields disagree with complete current physical inputs');
                }
            }
        }
        $entries = $this->optionCache->entries();
        $allKey = $this->optionCache->key('alloptions');
        $notKey = $this->optionCache->key('notoptions');
        $all = $entries[$allKey] ?? [];
        $not = $entries[$notKey] ?? [];
        if (!is_array($all) || !is_array($not)
            || (array_key_exists($allKey, $entries) && $entries[$allKey] === null)
            || (array_key_exists($notKey, $entries) && $entries[$notKey] === null)) {
            $this->refuse('found malformed native option cache storage');
        }
        if (count($all) > self::MAX_ROWS || count($not) > self::MAX_ROWS) $this->refuse('native option cache exceeds the entry frontier');
        $bytes = 0;
        foreach ($all as $name => $value) {
            if (!is_string($name) || strlen($name) > 764 || !is_string($value)
                || strlen($value) > PhysicalTableRows::MAX_CELL_BYTES
                || ($bytes += strlen($name) + strlen($value)) > self::MAX_BYTES) {
                $this->refuse('native alloptions cache exceeds the plain allocation frontier');
            }
        }
        foreach ($this->options as $name => $row) {
            foreach ([[$all, $name], [$entries, $this->optionCache->key($name)]] as [$values, $key]) {
                if (array_key_exists($key, $values) && ($row === null || $values[$key] !== $row['raw'])) {
                    $this->refuse('cached options disagree with current physical inputs');
                }
            }
            if (array_key_exists($name, $not) && ($not[$name] !== true || $row !== null)) {
                $this->refuse('negative option cache disagrees with physical presence');
            }
        }
    }

    private function runtime_state(): array {
        $types = $GLOBALS['wp_post_types'] ?? null;
        $statuses = $GLOBALS['wp_post_statuses'] ?? null;
        if (!is_array($types) || count($types) > 256 || !is_array($statuses) || count($statuses) > 64) {
            $this->refuse('found an unbounded or malformed native registry');
        }
        $result = ['types' => [], 'statuses' => [], 'request' => [],
            'home_constant' => defined('WP_HOME') ? ['value' => WP_HOME] : null];
        // get_post_types iterates every registered object, even for a core
        // post. Only consumed plain fields are runtime inputs, not callbacks
        // hanging off otherwise unrelated registered post-type objects.
        foreach ($types as $name => $object) {
            if (!is_string($name) || !is_object($object) || get_class($object) !== 'WP_Post_Type') {
                $this->refuse('found a substituted native post-type registry');
            }
            $fields = get_object_vars($object);
            if (!is_bool($fields['_builtin'] ?? null) || ($fields['name'] ?? null) !== $name || strlen($name) > 32) {
                $this->refuse('found a malformed native post-type identity or flag');
            }
            // get_post_types(..., names) plucks each object's name, not its
            // registry key. A foreign object named "post" could otherwise
            // redirect a core post into get_post_permalink's CPT branch.
            $result['types'][$name] = ['object' => $object, 'name' => $name, '_builtin' => $fields['_builtin']];
        }
        foreach ($this->requested as $id) {
            $row = $this->posts[$id] ?? null;
            if ($row === null) continue;
            $type = $row['post_type'];
            $status = $row['post_status'];
            if (!isset($result['types'][$type])) $this->refuse('selected native post type disappeared');
            if (in_array($type, ['post', 'page'], true) && ($result['types'][$type]['_builtin'] ?? null) !== true) {
                $this->refuse('found a missing or reclassified core post type');
            }
            if (!$result['types'][$type]['_builtin']) {
                $typeFields = get_object_vars($types[$type]);
                if (($typeFields['name'] ?? null) !== $type || !is_bool($typeFields['hierarchical'] ?? null)
                    || !array_key_exists('query_var', $typeFields)
                    || ($typeFields['query_var'] !== false && (!is_string($typeFields['query_var'])
                        || preg_match('/^[a-z0-9_-]{1,128}$/D', $typeFields['query_var']) !== 1))) {
                    $this->refuse('found malformed native custom-type route inputs');
                }
                $result['types'][$type] += ['name' => $type, 'hierarchical' => $typeFields['hierarchical'],
                    'query_var' => $typeFields['query_var']];
                // get_extra_permastruct returns false before consulting any
                // entry on plain sites; rewrite may legitimately still be true.
                if ($this->options['permalink_structure']['value'] !== '') {
                    $rewrite = $typeFields['rewrite'] ?? null;
                    if ($rewrite === false) $result['types'][$type]['rewrite'] = false;
                    else {
                        if (!is_array($rewrite) || !is_string($rewrite['slug'] ?? null) || $rewrite['slug'] === ''
                            || strlen($rewrite['slug']) > self::MAX_URL_BYTES || !is_bool($rewrite['with_front'] ?? null)
                            || !is_bool($rewrite['feeds'] ?? null)) $this->refuse('found malformed native custom-type rewrite inputs');
                        $args = ['with_front' => $rewrite['with_front'], 'feed' => $rewrite['feeds']];
                        foreach (['ep_mask', 'paged', 'forcomments', 'walk_dirs', 'endpoints'] as $name) {
                            if (!array_key_exists($name, $rewrite)) continue;
                            if ($name === 'ep_mask' ? !is_int($rewrite[$name]) : !is_bool($rewrite[$name])) {
                                $this->refuse('found nonplain native custom-type permastruct arguments');
                            }
                            $args[$name] = $rewrite[$name];
                        }
                        $result['types'][$type]['rewrite'] = ['slug' => $rewrite['slug'], 'args' => $args];
                    }
                }
            }
            $object = $statuses[$status] ?? null;
            if (!is_object($object) || get_class($object) !== 'stdClass') $this->refuse('found a substituted native post status');
            $fields = get_object_vars($object);
            $result['statuses'][$status] = ['object' => $object];
            foreach (['internal', 'protected', 'private', 'publicly_queryable', '_builtin', 'public'] as $name) {
                if (!is_bool($fields[$name] ?? null)) $this->refuse('found a malformed native post-status flag');
                $result['statuses'][$status][$name] = $fields[$name];
            }
            $expectedStatus = ['internal' => false, 'protected' => in_array($status, ['draft', 'pending', 'future'], true),
                'private' => $status === 'private', 'publicly_queryable' => $status === 'publish',
                '_builtin' => true, 'public' => $status === 'publish'];
            foreach ($expectedStatus as $name => $value) {
                // A familiar status spelling cannot secretly select another
                // dependency branch, e.g. a draft with public runtime flags.
                if ($fields[$name] !== $value) $this->refuse('native status flags disagree with the admitted core status');
            }
            if ($status === 'private') {
                $typeFields = get_object_vars($types[$type]);
                $cap = $typeFields['cap'] ?? null;
                if (!is_bool($typeFields['map_meta_cap'] ?? null) || !is_object($cap) || get_class($cap) !== 'stdClass') {
                    $this->refuse('found malformed native private-post capabilities');
                }
                $capFields = get_object_vars($cap);
                $capName = $typeFields['map_meta_cap'] ? 'read_private_posts' : 'read_post';
                if (!is_string($capFields[$capName] ?? null)
                    || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $capFields[$capName]) !== 1 || $capFields[$capName] === 'exist') {
                    $this->refuse('private-post capability is outside the anonymous native frontier');
                }
                $result['types'][$type] += ['map_meta_cap' => $typeFields['map_meta_cap'],
                    'cap' => $cap, $capName => $capFields[$capName]];
            }
        }
        if ($this->privatePosts) $result['user'] = $this->anonymous_user();
        foreach (['HTTPS', 'SERVER_PORT'] as $name) {
            $present = array_key_exists($name, $_SERVER);
            $value = $present ? $_SERVER[$name] : null;
            if ($present && !is_string($value) && !is_int($value)) $this->refuse('found a nonplain native scheme input');
            if (is_string($value) && strlen($value) > 64) $this->refuse('native scheme input exceeds its bounded frontier');
            $result['request'][$name] = ['present' => $present, 'value' => $value];
        }
        return $result;
    }

    private function anonymous_user(): array {
        $user = $GLOBALS['current_user'] ?? null;
        if (!is_object($user) || get_class($user) !== 'WP_User'
            || (new \ReflectionClass($user))->getFileName() !== $this->core_file('class-wp-user.php')) {
            $this->refuse('private permalinks require an already initialized anonymous core user');
        }
        $fields = get_object_vars($user);
        if (($fields['ID'] ?? null) !== 0 || ($fields['allcaps'] ?? null) !== []) {
            $this->refuse('private permalinks require the anonymous zero-capability context');
        }
        foreach (['pluggable.php' => ['wp_get_current_user'], 'user.php' => ['_wp_get_current_user'],
            'capabilities.php' => ['current_user_can', 'user_can', 'map_meta_cap',
                'wp_maybe_grant_install_languages_cap', 'wp_maybe_grant_resume_extensions_caps', 'wp_maybe_grant_site_health_caps']] as $file => $functions) {
            foreach ($functions as $function) {
                if (!function_exists($function) || (new \ReflectionFunction($function))->getFileName() !== $this->core_file($file)) {
                    $this->refuse('private permalinks require standard core capability functions');
                }
            }
        }
        if (wp_get_current_user() !== $user) $this->refuse('native current-user getter did not retain the admitted object');
        // _wp_get_current_user returns this exact existing object without
        // dispatching authentication hooks. The stock cap trio is inert for
        // empty allcaps; read_post on a private, non-revision post reaches no
        // user metadata, role loading or edit-post option branch.
        return ['object' => $user, 'ID' => $fields['ID'], 'allcaps' => $fields['allcaps']];
    }

    private function admit_rewrite(string $structure): void {
        $rewrite = $GLOBALS['wp_rewrite'] ?? null;
        if (!is_object($rewrite) || get_class($rewrite) !== 'WP_Rewrite') $this->refuse('found a substituted native rewrite object');
        $this->rewrite = $rewrite;
        // A new native instance is an independent comparator only. Never put
        // it in the global slot or call init() on the target's existing object.
        $fresh = new \WP_Rewrite();
        $fields = get_object_vars($rewrite);
        foreach (['permalink_structure', 'front', 'root', 'index', 'use_trailing_slashes'] as $name) {
            if (!array_key_exists($name, $fields) || $fields[$name] !== $fresh->$name) {
                $this->refuse('native rewrite state disagrees with current permalink inputs');
            }
        }
        if ($fresh->permalink_structure !== $structure) $this->refuse('native rewrite constructor consumed different physical options');
        $this->pageStructure = $fresh->get_page_permastruct();
        $this->runtime['extra'] = [];
        if (!is_array($fields['extra_permastructs'] ?? null)) $this->refuse('found malformed native extra permastruct storage');
        foreach ($this->runtime['types'] as $name => $type) {
            if (!array_key_exists('rewrite', $type)) continue;
            $entry = $type['rewrite'];
            if ($entry === false) {
                if (array_key_exists($name, $fields['extra_permastructs'])) $this->refuse('native extra permastruct exists for a rewrite-disabled type');
                $this->runtime['extra'][$name] = null;
                continue;
            }
            // WP_Post_Type::add_rewrite_rules passes this slug/token and aliases
            // feeds to feed. Native add_permastruct performs the derivation on
            // the private comparator only; global registration is never replayed.
            $fresh->add_permastruct($name, $entry['slug'] . '/%' . $name . '%', $entry['args']);
            if (($fields['extra_permastructs'][$name] ?? null) !== $fresh->extra_permastructs[$name]) {
                $this->refuse('native extra permastruct disagrees with current registered rewrite inputs');
            }
            $this->runtime['extra'][$name] = $fresh->extra_permastructs[$name];
        }
        $this->assert_rewrite();
        $this->runtime['rewrite'] = [$rewrite->permalink_structure, $rewrite->front, $rewrite->root,
            $rewrite->index, $rewrite->use_trailing_slashes];
    }

    private function assert_rewrite(): void {
        if (($GLOBALS['wp_rewrite'] ?? null) !== $this->rewrite) $this->refuse('native rewrite object changed');
        $fields = get_object_vars($this->rewrite);
        foreach (['permalink_structure', 'front', 'root', 'index', 'use_trailing_slashes'] as $name) {
            if (!array_key_exists($name, $fields)) $this->refuse('native rewrite field disappeared');
        }
        if (array_key_exists('page_structure', $fields) && $fields['page_structure'] !== null
            && $fields['page_structure'] !== ($this->pageStructure === false ? '' : $this->pageStructure)) {
            $this->refuse('cached native page structure disagrees with current rewrite inputs');
        }
    }

    private function assert_current(): void {
        DatabaseQueryIsolation::assert_original_all_hook_absent($this->context);
        foreach ($this->hooks as $name => $saved) {
            if ($saved === null) {
                if (array_key_exists($name, $GLOBALS['wp_filter'])) $this->refuse('native hook topology changed');
            } elseif (($GLOBALS['wp_filter'][$name] ?? null) !== $saved['object']
                || $saved['object']->callbacks !== $saved['callbacks']) {
                $this->refuse('native hook topology changed');
            }
        }
        $this->assert_caches();
        $this->assert_rewrite();
        $actual = $this->runtime_state();
        $extra = get_object_vars($this->rewrite)['extra_permastructs'] ?? null;
        if (!is_array($extra)) $this->refuse('native extra permastruct storage changed');
        $actual['extra'] = [];
        foreach ($this->runtime['extra'] as $name => $entry) {
            if ($entry === null && array_key_exists($name, $extra)) $this->refuse('native extra permastruct presence changed');
            $actual['extra'][$name] = $extra[$name] ?? null;
        }
        $actual['rewrite'] = [$this->rewrite->permalink_structure, $this->rewrite->front, $this->rewrite->root,
            $this->rewrite->index, $this->rewrite->use_trailing_slashes];
        if ($actual !== $this->runtime) $this->refuse('native registry, request or rewrite inputs changed');
    }

    private function assert_url(mixed $url): void {
        if (!is_string($url) || $url === '' || strlen($url) > self::MAX_URL_BYTES || preg_match('//u', $url) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) $this->refuse('native URL is malformed or over the bounded frontier');
    }

    private function assert_home_input(string $home): void {
        $this->assert_url($home);
        $parts = parse_url($home);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === '' || str_contains($home, '\\')
            || isset($parts['query']) || isset($parts['fragment'])) {
            $this->refuse('home authority is not an ordinary absolute HTTP URL');
        }
    }

    private function refuse(string $reason): never {
        DatabaseQueryIsolation::violation("wprism: {$this->context} native permalink inputs $reason");
    }
}
