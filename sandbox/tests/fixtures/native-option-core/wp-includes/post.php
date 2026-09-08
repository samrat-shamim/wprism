<?php
declare(strict_types=1);

// The native branch matters: integer IDs reach cache cloning and SELECT *,
// unlike an already-raw WP_Post passed directly by an audited caller.
function get_post(mixed $post = null, string $output = 'OBJECT', string $filter = 'raw'): WP_Post|false|null {
    if (empty($post)) $post = $GLOBALS['post'] ?? null;
    if ($post instanceof WP_Post) $resolved = $post;
    elseif (is_object($post)) $resolved = new WP_Post(sanitize_post($post, 'raw'));
    else $resolved = WP_Post::get_instance((int) $post);
    return $resolved ? $resolved->filter($filter) : null;
}

function get_post_type(mixed $post = null): mixed {
    $resolved = get_post($post);
    return $resolved ? $resolved->post_type : false;
}

function sanitize_post(object $post, string $context): object {
    foreach (get_object_vars($post) as $column => $value) $post->$column = sanitize_post_field($column, $value, (int) $post->ID, $context);
    $post->filter = $context;
    return $post;
}

function sanitize_post_field(string $field, mixed $value, int $id, string $context): mixed {
    return in_array($field, ['ID', 'post_author', 'post_parent', 'menu_order'], true) ? (int) $value : $value;
}

if (defined('WPRISM_TEST_NATIVE_PERMALINKS')) {
    function get_post_types(array $args = [], string $output = 'names', string $operator = 'and'): array {
        return wp_filter_object_list($GLOBALS['wp_post_types'], $args, $operator, $output === 'names' ? 'name' : false);
    }
    function get_post_type_object(string $name): object|false { return $GLOBALS['wp_post_types'][$name] ?? false; }
    function get_post_status(mixed $post = null): mixed {
        $post = get_post($post);
        return $post ? apply_filters('get_post_status', $post->post_status, $post) : false;
    }
    function get_post_status_object(string $name): object|false { return $GLOBALS['wp_post_statuses'][$name] ?? false; }
    function is_post_status_viewable(object $status): bool {
        if ($status->internal || $status->protected) return false;
        return true === apply_filters('is_post_status_viewable', $status->publicly_queryable || ($status->_builtin && $status->public), $status);
    }
    function get_post_ancestors(mixed $post): array {
        $post = get_post($post);
        if (!$post || !$post->post_parent || $post->post_parent === $post->ID) return [];
        $ids = [$post->post_parent];
        while ($ancestor = get_post(end($ids))) {
            if (!$ancestor->post_parent || $ancestor->post_parent === $post->ID || in_array($ancestor->post_parent, $ids, true)) break;
            $ids[] = $ancestor->post_parent;
        }
        return $ids;
    }
    function get_page_uri(mixed $page = 0): string|false {
        $page = get_post($page);
        if (!$page) return false;
        $uri = $page->post_name;
        foreach ($page->ancestors as $id) {
            $parent = get_post($id);
            if ($parent && $parent->post_name) $uri = $parent->post_name . '/' . $uri;
        }
        return apply_filters('get_page_uri', $uri, $page);
    }
}
