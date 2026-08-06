<?php
/**
 * Plugin Name: Duo Agency CPT
 * Description: Grind R1-C fixture plugin — the custom post type + taxonomy
 *   an agency site ships in-house (a portfolio of client projects), shipped
 *   through the site repo's code/ tree (spike G's dogfooding pattern) and
 *   activated via `wp duo deploy` rather than a static sandbox fixture, so
 *   it round-trips exactly the way a real client plugin would. Also
 *   exercises the core loop's own review queue with three plugin-owned
 *   keys shaped like duo-loop-demo's (authored / secret-shaped / runtime),
 *   but the runtime case lives at POST-META grain (a per-project view
 *   counter) rather than duo-loop-demo's option-level counter — new
 *   marginal coverage of the classification surface, not a repeat of it.
 * Version: 0.1.0
 *
 * 'project' CPT + 'project_type' taxonomy: what an ACF field group and an
 * Elementor page both need to exist before this round's interplay points
 * (docs/grind/r1c-agency.md) can be built at all.
 *
 * REST: POST /wp-json/duo-agency/v1/projects/<id>/notes (manage_options only):
 *   - post meta _duo_project_internal_notes — an admin-authored account note
 *                                              (authored)
 *   - option duo_agency_client_api_key      — shaped like a live Stripe
 *                                              secret key (the sk_live_
 *                                              pattern denylist target)
 * Front end: every anonymous GET of a single 'project' bumps that project's
 *   own _duo_project_views post meta (runtime) via template_redirect —
 *   traffic nobody "authored," the control case pending must classify the
 *   opposite way from the REST-written note above, at the SAME grain
 *   (post meta) as the authored key it sits next to on the same post.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    register_post_type('project', [
        'label' => 'Projects',
        'labels' => ['singular_name' => 'Project', 'name' => 'Projects'],
        'public' => true,
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-portfolio',
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'projects'],
    ]);

    register_taxonomy('project_type', ['project'], [
        'label' => 'Project Types',
        'labels' => ['singular_name' => 'Project Type', 'name' => 'Project Types'],
        'public' => true,
        'show_in_rest' => true,
        'hierarchical' => false,
        'rewrite' => ['slug' => 'project-type'],
    ]);
});

add_action('rest_api_init', function () {
    register_rest_route('duo-agency/v1', '/projects/(?P<id>\d+)/notes', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback' => 'duo_agency_cpt_set_notes',
    ]);
});

function duo_agency_cpt_set_notes(WP_REST_Request $req) {
    $project_id = (int) $req->get_param('id');
    $notes = (string) $req->get_param('notes');
    $api_key = (string) $req->get_param('api_key');

    if ($project_id > 0) {
        update_post_meta($project_id, '_duo_project_internal_notes', $notes);
    }
    update_option('duo_agency_client_api_key', $api_key);

    return new WP_REST_Response([
        'project_id' => $project_id,
        'notes' => $notes,
        'api_key' => $api_key,
    ], 200);
}

// Anonymous front-end per-project view counter — deliberately no capability
// check: this IS the "the world" write class DESIGN.md's author axis calls
// out, the control case that must never propose 'authored' regardless of
// how often it fires, sitting on the SAME post as the admin-authored note.
add_action('template_redirect', function () {
    if (is_admin() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !is_singular('project')) {
        return;
    }
    $id = get_queried_object_id();
    if ($id > 0) {
        update_post_meta($id, '_duo_project_views', (int) get_post_meta($id, '_duo_project_views', true) + 1);
    }
});
