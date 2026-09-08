<?php
declare(strict_types=1);

/** Dependency-order model for hostile inputs, not a native conformance oracle. */
function home_url(string $path = '', ?string $scheme = null): string { return get_home_url(null, $path, $scheme); }
function get_home_url(?int $blogId = null, string $path = '', ?string $scheme = null): string {
    $home = get_option('home');
    $url = set_url_scheme($home, $scheme ?? (is_ssl() ? 'https' : parse_url($home, PHP_URL_SCHEME)));
    if ($path !== '') $url .= '/' . ltrim($path, '/');
    return apply_filters('home_url', $url, $path, $scheme, $blogId);
}
function set_url_scheme(string $url, ?string $scheme = null): string {
    return apply_filters('set_url_scheme', preg_replace('#^\w+://#', ($scheme ?? 'http') . '://', $url), $scheme, $scheme);
}
function user_trailingslashit(string $url, string $type = ''): string {
    $url = $GLOBALS['wp_rewrite']->use_trailing_slashes ? trailingslashit($url) : untrailingslashit($url);
    return apply_filters('user_trailingslashit', $url, $type);
}
function wp_force_plain_post_permalink(mixed $post, ?bool $sample = null): bool {
    $post = get_post($post);
    if (!$post) return true;
    $status = get_post_status_object(get_post_status($post));
    if (!$status || !get_post_type_object(get_post_type($post))) return true;
    return !(is_post_status_viewable($status) || ($status->private && current_user_can('read_post', $post->ID))
        || ($status->protected && $sample));
}
function get_permalink(mixed $post = 0, bool $leave = false): string|false {
    $post = get_post($post);
    if (!$post) return false;
    if ($post->post_type === 'page') return get_page_link($post, $leave);
    if (in_array($post->post_type, get_post_types(['_builtin' => false]), true)) return get_post_permalink($post, $leave);
    $structure = apply_filters('pre_post_link', get_option('permalink_structure'), $post, $leave);
    if ($structure && !wp_force_plain_post_permalink($post)) {
        $date = explode(' ', str_replace(['-', ':'], ' ', $post->post_date));
        $url = home_url(str_replace(['%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%postname%', '%post_id%'],
            array_merge($date, [$post->post_name, $post->ID]), $structure));
        $url = user_trailingslashit($url, 'single');
    } else $url = home_url('?p=' . $post->ID);
    return apply_filters('post_link', $url, $post, $leave);
}
function get_post_permalink(mixed $post = 0, bool $leave = false, bool $sample = false): string|false {
    $post = get_post($post);
    if (!$post) return false;
    $structure = $GLOBALS['wp_rewrite']->get_extra_permastruct($post->post_type);
    $plain = wp_force_plain_post_permalink($post);
    $type = get_post_type_object($post->post_type);
    $slug = $type->hierarchical ? get_page_uri($post) : $post->post_name;
    if ($structure && (!$plain || $sample)) $url = home_url(user_trailingslashit(str_replace('%' . $post->post_type . '%', $slug, $structure)));
    else $url = home_url($type->query_var && !$plain ? add_query_arg($type->query_var, $slug, '')
        : add_query_arg(['post_type' => $post->post_type, 'p' => $post->ID], ''));
    return apply_filters('post_type_link', $url, $post, $leave, $sample);
}
function get_page_link(mixed $post = 0, bool $leave = false, bool $sample = false): string {
    $post = get_post($post);
    $url = get_option('show_on_front') === 'page' && $post->ID === (int) get_option('page_on_front')
        ? home_url('/') : _get_page_link($post, $leave, $sample);
    return apply_filters('page_link', $url, $post->ID, $sample);
}
function _get_page_link(mixed $post = 0, bool $leave = false, bool $sample = false): string {
    $post = get_post($post);
    $plain = wp_force_plain_post_permalink($post);
    $structure = $GLOBALS['wp_rewrite']->get_page_permastruct();
    $url = $structure && (!$plain || $sample)
        ? user_trailingslashit(home_url(str_replace('%pagename%', get_page_uri($post), $structure)), 'page')
        : home_url('?page_id=' . $post->ID);
    return apply_filters('_get_page_link', $url, $post->ID);
}
