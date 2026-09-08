<?php
declare(strict_types=1);

function current_user_can(string $cap, int $id): bool { return user_can(wp_get_current_user(), $cap, $id); }
function user_can(WP_User $user, string $cap, int $id): bool { return $user->has_cap($cap, $id); }
function map_meta_cap(string $cap, int $userId, int $id): array {
    $post = get_post($id);
    $type = get_post_type_object($post->post_type);
    $required = $type->map_meta_cap ? $type->cap->read_private_posts : $type->cap->read_post;
    return apply_filters('map_meta_cap', [$required], $cap, $userId, [$id]);
}
function wp_maybe_grant_install_languages_cap(array $all): array { return $all; }
function wp_maybe_grant_resume_extensions_caps(array $all): array { return $all; }
function wp_maybe_grant_site_health_caps(array $all, array $caps, array $args, WP_User $user): array { return $all; }
