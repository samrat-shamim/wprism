<?php
/**
 * Plugin Name: Duo Loop Demo
 * Description: Spike F fixture plugin — a minimal, dependency-free plugin
 *   that exercises the four write shapes the core loop (pending/classify)
 *   has to sort out from one small surface: an admin-authored REST settings
 *   write touching both an option and post meta, a secret-shaped option
 *   value mixed into the same write, and an anonymous front-end write that
 *   is nobody's "authored" content at all.
 * Version: 0.1.0
 *
 * REST: POST /wp-json/duo-loop/v1/settings (manage_options only):
 *   - option duo_loop_color    — an ordinary admin setting (authored)
 *   - option duo_loop_api_key  — shaped like a live Stripe secret key
 *                                 (the sk_live_ pattern denylist target)
 *   - post meta _duo_loop_badge on the given post (authored)
 * Front end: every anonymous GET bumps option duo_loop_hits (runtime) via
 *   template_redirect — traffic nobody "authored", the control case pending
 *   must classify the opposite way from the REST write above.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('duo-loop/v1', '/settings', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback' => 'duo_loop_demo_settings',
    ]);
});

function duo_loop_demo_settings(WP_REST_Request $req) {
    $color = (string) $req->get_param('color');
    $api_key = (string) $req->get_param('api_key');
    $badge_post = (int) $req->get_param('badge_post');
    $badge = (string) $req->get_param('badge');

    update_option('duo_loop_color', $color);
    update_option('duo_loop_api_key', $api_key);
    if ($badge_post > 0) {
        update_post_meta($badge_post, '_duo_loop_badge', $badge);
    }

    return new WP_REST_Response([
        'color' => $color,
        'api_key' => $api_key,
        'badge_post' => $badge_post,
        'badge' => $badge,
    ], 200);
}

// Anonymous front-end traffic counter — deliberately no capability check:
// this IS the "the world" write class DESIGN.md's author axis calls out,
// the control case that must never propose 'authored' regardless of how
// often it fires.
add_action('template_redirect', function () {
    if (is_admin() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    update_option('duo_loop_hits', (int) get_option('duo_loop_hits', 0) + 1);
});
