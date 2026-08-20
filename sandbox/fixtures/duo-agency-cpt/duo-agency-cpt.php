<?php
/**
 * Plugin Name: Duo Agency CPT
 * Description: Grind R1-C fixture plugin — the custom post type + taxonomy
 *   an agency site ships in-house (a portfolio of client projects), shipped
 *   through the site repo's code/ tree (spike G's dogfooding pattern) and
 *   activated via `wp duo deploy` rather than a static sandbox fixture, so
 *   it round-trips exactly the way a real client plugin would. Also
 *   exercises the core loop's own review queue with three plugin-owned
 *   keys, one per classification outcome (authored / secret-shaped /
 *   runtime), with the runtime case at POST-META grain (a per-project view
 *   counter) rather than the option grain the earlier core-loop fixtures
 *   used — marginal coverage of the classification surface, not a repeat.
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
 *
 * Generated data (DUO-3338): option duo_agency_project_index is this plugin's
 *   own derived projection of its authored projects — an ordered id list plus
 *   an id => title map, recomputed from the posts on every project save. It is
 *   generated, never authored: nothing edits it directly, its ids are
 *   environment-local, and it is reproducible from the projects alone, which is
 *   exactly why it is classified `derived` in manifests/duo-agency-cpt.json and
 *   excluded from canonical state. Duo writes project rows with direct SQL and
 *   deliberately does not fire save_post, so on a promotion target this
 *   projection would silently keep describing the pre-apply site. The plugin
 *   therefore advertises its OWN repair through the `duo_providers` filter
 *   (see the provider at the bottom of this file) instead of expecting the
 *   engine to know how a projection it does not own is built.
 *
 * Parent-scoped tables (DUO-3318): activation also creates duo_agency_rooms
 *   and duo_agency_room_slots — a parent row with a site-unique code and a
 *   child row whose code is unique only WITHIN its parent, the shape a
 *   multi-column natural key exists for. The SHIPPED manifest deliberately
 *   does not declare them; sandbox/tests/live/regress_parent_scoped_natural_key.sh
 *   supplies that declaration through a test-manifests overlay, so the two
 *   suites that already drive this fixture keep loading byte-identical
 *   adapter bytes.
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

// ---------------------------------------------------------------------------
// Parent-scoped custom tables: bookable rooms and their per-room slots (DUO-3318)
// ---------------------------------------------------------------------------

/**
 * Two authored tables in the exact shape a parent-scoped natural key exists
 * for, and the shape a hand-written plugin actually reaches for first: a
 * parent row with a human-chosen code that is unique site-wide, and a child
 * row with a human-chosen code that is unique only WITHIN its parent. Two
 * different rooms both having a slot called `morning` is the normal case, not
 * a data error, which is why the child's identity cannot be its own code
 * alone — and why it also cannot be its `room_id`, an environment-local
 * auto-increment value that means a different room on every install.
 *
 * `slot_id` is a real surrogate primary key, deliberately: this is NOT the
 * pure-join shape `composite_ref` covers (where the row IS the fact and there
 * is no scalar id at all). The row carries its own authored payload
 * (`capacity`) that changes independently of its identity, so it needs an
 * ordinary primary key, ordinary updates, and an ordinary ledger local_id.
 *
 * Raw CREATE TABLE rather than dbDelta(), matching every other fixture plugin
 * in this tree (dbDelta's whitespace-sensitive DDL parser buys nothing for a
 * table that is created once and never migrated) — and `IF NOT EXISTS` so a
 * re-activation during a promotion is a no-op rather than a fatal.
 */
register_activation_hook(__FILE__, static function (): void {
    global $wpdb;
    $collate = $wpdb->get_charset_collate();
    $rooms = $wpdb->prefix . 'duo_agency_rooms';
    $slots = $wpdb->prefix . 'duo_agency_room_slots';
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `$rooms` ("
        . 'room_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'room_code VARCHAR(64) NOT NULL, '
        . 'room_label VARCHAR(191) NOT NULL DEFAULT \'\', '
        . 'PRIMARY KEY (room_id), '
        . 'UNIQUE KEY room_code (room_code)'
        . ") $collate"
    );
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `$slots` ("
        . 'slot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'room_id BIGINT UNSIGNED NOT NULL, '
        . 'slot_code VARCHAR(64) NOT NULL, '
        . 'capacity BIGINT UNSIGNED NOT NULL DEFAULT 0, '
        . 'PRIMARY KEY (slot_id), '
        // Deliberately a plain index, NOT a UNIQUE KEY on (room_id,
        // slot_code), even though that pair genuinely is unique: Duo's typed
        // apply is two-phase and parks every ref column at 0 in phase 1
        // before resolving it in phase 2, so two sibling rows that differ only
        // by their parent are momentarily identical. A UNIQUE constraint
        // spanning a ref column is therefore unsatisfiable mid-apply — a real
        // engine boundary this fixture records rather than papering over by
        // giving every slot a globally distinct code (which would also have
        // quietly removed the very case a parent-scoped key exists for).
        . 'KEY room_slot (room_id, slot_code)'
        . ") $collate"
    );
});

// ---------------------------------------------------------------------------
// Generated project index + the provider that repairs it (DUO-3338)
// ---------------------------------------------------------------------------

const DUO_AGENCY_PROJECT_INDEX_OPTION = 'duo_agency_project_index';
const DUO_AGENCY_PROJECT_CACHE_TRANSIENT = 'duo_agency_project_cache';

/**
 * Recompute the index from the posts themselves.
 *
 * cache_results is off deliberately. This runs inside a promotion, after rows
 * were written with direct SQL by a process that had already read the old rows
 * into the object cache; a cached read here would recompute the index from the
 * state the site had BEFORE the promotion and then "verify" it successfully.
 *
 * @return array{ids: list<int>, titles: array<string,string>}
 */
function duo_agency_cpt_compute_project_index(): array {
    $query = new WP_Query([
        'post_type' => 'project',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
        'cache_results' => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);
    $ids = [];
    $titles = [];
    foreach ($query->posts as $project) {
        $ids[] = (int) $project->ID;
        $titles[(string) $project->ID] = (string) $project->post_title;
    }
    return ['ids' => $ids, 'titles' => $titles];
}

/**
 * The stored index as the DATABASE holds it, not as the object cache
 * remembers it — the same reason the recompute above bypasses caching. A
 * provider that proves its write against a cached value proves nothing.
 *
 * @return array{ids: list<int>, titles: array<string,string>}
 */
function duo_agency_cpt_read_project_index(): array {
    global $wpdb;
    $wpdb->last_error = '';
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        DUO_AGENCY_PROJECT_INDEX_OPTION
    ));
    if ((string) ($wpdb->last_error ?? '') !== '') {
        throw new RuntimeException(
            'duo-agency-cpt: project index read failed (' . $wpdb->last_error . ')'
        );
    }
    if ($raw === null) {
        return ['ids' => [], 'titles' => []];
    }
    $value = maybe_unserialize($raw);
    return is_array($value) && isset($value['ids'], $value['titles'])
        ? ['ids' => array_map('intval', (array) $value['ids']), 'titles' => (array) $value['titles']]
        : ['ids' => [], 'titles' => []];
}

/**
 * Write the index and warm this plugin's own read cache. Called on every
 * project save — the ordinary, hook-driven path an editor exercises.
 *
 * @return array{ids: list<int>, titles: array<string,string>}
 */
function duo_agency_cpt_store_project_index(): array {
    $index = duo_agency_cpt_compute_project_index();
    update_option(DUO_AGENCY_PROJECT_INDEX_OPTION, $index, false);
    set_transient(DUO_AGENCY_PROJECT_CACHE_TRANSIENT, $index['ids'], DAY_IN_SECONDS);
    return $index;
}

add_action('save_post_project', static function ($post_id, $post, $update): void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    duo_agency_cpt_store_project_index();
}, 10, 3);

/**
 * This plugin's own repair capability, offered to Duo through the provider
 * contract (DUO-3338).
 *
 * The engine deliberately does not own this: how the index is built is a fact
 * about this plugin's data model, and an engine that reproduced it would be
 * carrying plugin logic it cannot version or verify. What the engine owns is
 * the contract — a stable identity, a declared capability with a closed
 * argument schema, and a receipt that must prove the value it wrote.
 *
 * `duo_agency_project_cache` is deliberately NOT this provider's business: a
 * WordPress transient means the same thing for every plugin, so the manifest
 * clears it with the engine's own native `transient.delete` action instead.
 */
final class Duo_Agency_Index_Provider {
    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'duo-agency-index',
            'plugin' => 'duo-agency-cpt/duo-agency-cpt.php',
            'version' => '1.0.0',
        ];
    }

    /**
     * Site-scoped because the projection is one option describing the whole
     * catalogue: repairing it for "the projects that changed" is not a thing
     * that can be true. Idempotent because recomputing from the posts always
     * lands on the same value, which is what makes it safe for apply's retry
     * machinery to re-fire. 60 seconds is generous for one query plus one
     * option write on a portfolio-sized site.
     */
    public function capabilities(): array {
        return [
            'rebuild_project_index' => [
                'args' => [],
                'reads' => ['post:project'],
                'writes' => ['option:duo_agency_project_index'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 60,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array{before:array, after:array, verified:true}
     */
    public function invoke(string $capability, array $args): array {
        if ($capability !== 'rebuild_project_index') {
            throw new RuntimeException(
                "duo-agency-cpt: provider does not implement capability '$capability'"
            );
        }
        $before = duo_agency_cpt_read_project_index();
        $computed = duo_agency_cpt_compute_project_index();
        update_option(DUO_AGENCY_PROJECT_INDEX_OPTION, $computed, false);

        // Value-level verification, not "update_option returned something":
        // update_option answers false both when the write failed and when the
        // value was already identical, so only a fresh uncached read of the
        // row can tell a converged index from an unwritten one.
        $stored = duo_agency_cpt_read_project_index();
        if ($stored !== $computed) {
            throw new RuntimeException(
                'duo-agency-cpt: project index readback does not match the recomputed index (wrote '
                . wp_json_encode($computed) . ', read back ' . wp_json_encode($stored) . ')'
            );
        }
        return ['before' => $before, 'after' => $stored, 'verified' => true];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => duo_agency_cpt_read_project_index(),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== 'rebuild_project_index') {
            throw new RuntimeException(
                "duo-agency-cpt: provider does not implement capability '$capability'"
            );
        }
        return [
            'operation' => $operation,
            'after' => duo_agency_cpt_read_project_index(),
            'verified' => true,
        ];
    }
}

// One registry filter, not a per-id hook: the engine matches a supplied entry
// by the identity() it declares, so nothing here has to agree with the engine
// about how a hook name is spelled.
add_filter('duo_providers', static function ($providers) {
    $providers = is_array($providers) ? $providers : [];
    $providers[] = new Duo_Agency_Index_Provider();
    return $providers;
});
