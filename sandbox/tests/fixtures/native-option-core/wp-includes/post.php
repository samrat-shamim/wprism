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
