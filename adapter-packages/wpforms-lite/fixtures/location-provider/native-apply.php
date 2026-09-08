<?php
declare(strict_types=1);

// Native writers/consumers only. Ordinary WP-CLI owns Capture/Plan/Apply and
// re-proves the same experimental package in its fresh convergence verifier.
require_once __DIR__ . '/provider-library.php';
$phase = $args[0] ?? '';
$case = $args[1] ?? 'baseline';
$targetKind = $args[2] ?? 'seeded';
$recordPath = WP_CONTENT_DIR . '/wprism-wpforms-apply-native.json';
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('WPForms native Apply fixture: ' . $message);
};
$check(defined('WPFORMS_VERSION') && WPFORMS_VERSION === '2.0.1.1' && !wpforms()->is_pro(), 'exact native Lite release');
$check(current_user_can('manage_options'), 'native fixture administrator');
$check(in_array($targetKind, ['seeded', 'empty'], true), 'declared target content premise');
if ($phase === 'setup') {
    if ($case === 'source') {
        $check(!file_exists('/siterepo/site.wprism.json'), 'new authored-state repository, never strip a code baseline');
        WPrism\Canon::write_file('/siterepo/site.wprism.json', WPrism\Canon::encode([
            'manifests' => ['core', 'wpforms-lite'], 'spec_version' => 3,
            'policy' => ['post_types' => ['post', 'page', 'attachment', 'wpforms', 'wpforms-template'],
                'taxonomies' => ['category', 'post_tag', 'wpforms_form_tag'],
                'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) []],
        ]));
    } else $check($case === 'target', 'declared setup side');
    echo wp_json_encode(['format' => 'wprism-wpforms-apply-setup/v1', 'side' => $case,
        'version' => WPFORMS_VERSION, 'home' => home_url(), 'code_baseline_created' => false, 'target_kind' => $targetKind]);
    return;
}
if ($phase === 'seed') {
    $check(!file_exists($recordPath), 'fresh native seed');
    $padding = [];
    if ($case === 'target') {
        // Native trash is outside authored capture. Retain its complete rows
        // as a preservation witness while forcing independently allocated IDs.
        for ($i = 0; $i < 7; $i++) {
            $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'trash',
                'post_title' => 'WPrism Apply padding ' . $i], true);
            $check(is_int($id) && $id > 0, 'native target padding');
            $padding[] = $id;
        }
    } else $check($case === 'source', 'declared seed side');
    $emptyTarget = $case === 'target' && $targetKind === 'empty';
    if ($emptyTarget) {
        // Installing the already-pinned plugin is a premise, not evidence of
        // managed-code adoption. Do not run the source wizard or form writers
        // on this target: ordinary Apply must create every controlled post.
        $seed = ['format' => 'wprism-wpforms-apply-empty-seed/v1', 'version' => WPFORMS_VERSION,
            'home' => home_url(), 'posts' => []];
    } else {
        ob_start();
        $args = ['seed'];
        require dirname(__DIR__) . '/capture-plan/native.php';
        $seed = json_decode((string) ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);
    }
    if ($case === 'target') {
        // An unassigned, unmapped native widget is target-local. SidebarState
        // must allocate around it, and the global Locator still consumes it.
        if (!$emptyTarget) {
            update_option('widget_wpforms-widget', []);
            update_option('widget_wpforms-widget', [99 => ['title' => 'Local orphan',
                'form_id' => (string) $seed['posts']['integer']['id'], 'show_title' => false, 'show_desc' => false],
                '_multiwidget' => 1]);
        }
        $blocks = get_option('widget_block', []);
        $check(is_array($blocks) && !isset($blocks[99]), 'fresh target-local core block sentinel');
        $blocks[99] = ['content' => '<!-- wp:paragraph --><p>Unrelated core widget</p><!-- /wp:paragraph -->'];
        update_option('widget_block', $blocks);
    }
    $bytes = wp_json_encode(['seed' => $seed, 'padding' => $padding, 'empty_target' => $emptyTarget], JSON_THROW_ON_ERROR);
    $check(file_put_contents($recordPath, $bytes) === strlen($bytes) && chmod($recordPath, 0600), 'private native identities');
    echo wp_json_encode($seed, JSON_THROW_ON_ERROR);
    return;
}
$saved = json_decode((string) file_get_contents($recordPath), true, 32, JSON_THROW_ON_ERROR);
$check(($saved['empty_target'] ?? false) === ($targetKind === 'empty' && $saved['seed']['format'] === 'wprism-wpforms-apply-empty-seed/v1'),
    'saved seed and caller agree on the empty-target premise');
$ids = array_column($saved['seed']['posts'], 'id', 'slug');
$id = static fn(string $role): int => $ids['wprism-wpf-' . $role];
if ($phase === 'mutate') {
    if ($case === 'embeds') {
        $check(wp_update_post(wp_slash(['ID' => $id('embed'),
            'post_content' => '<!-- wp:wpforms/form-selector {"formId":"' . $id('string') . '","displayTitle":true} /-->']), true)
            === $id('embed'), 'native embed-only mutation');
    } elseif ($case === 'widgets') {
        global $wp_registered_sidebars;
        $check(isset($wp_registered_sidebars['sidebar-1']), 'normal active theme supplies the sidebar');
        update_option('widget_wpforms-widget', [2 => ['title' => 'Apply widget', 'form_id' => (string) $id('integer'),
            'show_title' => true, 'show_desc' => true], '_multiwidget' => 1]);
        update_option('widget_block', [2 => ['content' => '<!-- wp:wpforms/form-selector {"formId":"'
            . $id('integer') . '"} /-->'], '_multiwidget' => 1]);
        $sidebars = get_option('sidebars_widgets');
        $check(is_array($sidebars), 'native sidebar state');
        $sidebars['sidebar-1'] = ['wpforms-widget-2', 'block-2'];
        wp_set_sidebars_widgets($sidebars);
    } elseif ($case === 'routing') {
        $check(wp_update_post(['ID' => $id('embed'), 'post_name' => 'wprism-wpf-embed-renamed',
            'post_title' => 'Renamed placement Ω'], true) === $id('embed'), 'native page routing-only mutation');
    } else throw new RuntimeException('WPForms native Apply fixture: unknown mutation');
    echo wp_json_encode(['format' => 'wprism-wpforms-apply-mutation/v1', 'case' => $case]);
    return;
}
if ($phase === 'contract') {
    $library = WPrism\Policy::shipped_adapter_library();
    [$policy, $compiled] = WPFormsLocationProviderLibrary::compile_and_load('/siterepo', $library);
    $check($policy->code_config() === null && $compiled->code_descriptor() === null, 'authored-state-only premise');
    $selectors = $case === 'widgets' ? ['sidebar:sidebar-1'] : ['post:' . WPrism\Ledger::uuid_for($id('embed'), 'post')];
    echo wp_json_encode(WPrism\ScopeContract::resolve($compiled, $policy, $selectors), JSON_THROW_ON_ERROR);
    return;
}
$check($phase === 'observe', 'declared native phase');
$posts = $native = [];
$locator = wpforms()->obj('locator');
$postIds = array_map(static fn(array $row): int => $row['id'], $saved['seed']['posts']);
$emptyWitness = [];
if ($saved['empty_target']) {
    global $wpdb;
    // No Ledger calls or post-status filtering at the absence boundary. This
    // includes trash, children, duplicate slugs and the entire two CPTs, not
    // merely whichever rows a normal published-post query happens to expose.
    $roster = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type IN ('wpforms','wpforms-template')"
        . " OR (post_type = 'page' AND post_name IN ('wprism-wpf-destination','wprism-wpf-embed','wprism-wpf-embed-renamed')) ORDER BY ID", ARRAY_A);
    $check(is_array($roster) && $wpdb->last_error === '', 'complete empty-target content census');
    if ($case !== 'before') {
        foreach (['integer' => 'wpforms', 'string' => 'wpforms', 'template' => 'wpforms-template',
            'destination' => 'page', 'embed' => 'page'] as $role => $type) {
            $slug = 'wprism-wpf-' . $role . ($case === 'routing' && $role === 'embed' ? '-renamed' : '');
            $matches = array_values(array_filter($roster, static fn(array $row): bool =>
                $row['post_type'] === $type && $row['post_name'] === $slug && (int) $row['post_parent'] === 0));
            $check(count($matches) === 1, 'exactly one natively created root post: ' . $role);
            $postIds[$role] = (int) $matches[0]['ID'];
        }
    }
    $emptyWitness = ['content_roster' => $roster, 'sidebars' => get_option('sidebars_widgets', [])];
}
foreach ($postIds as $role => $postId) {
    $post = get_post($postId);
    $check($post instanceof WP_Post, 'native fixture post survives');
    $posts[$role] = ['id' => (int) $post->ID, 'uuid' => $case === 'before' ? null : WPrism\Ledger::uuid_for((int) $post->ID, 'post'),
        'type' => $post->post_type, 'slug' => $post->post_name, 'status' => $post->post_status,
        'title' => $post->post_title, 'body' => $post->post_content, 'url' => get_permalink($post)];
    if (in_array($role, ['integer', 'string'], true)) {
        ob_start();
        wpforms_display((int) $post->ID, true, true);
        $rendered = ob_get_clean();
        $native[$role] = ['locations' => get_post_meta($post->ID, 'wpforms_form_locations', true),
            'column' => $locator->column_value('', $post, WPForms\Forms\Locator::COLUMN_NAME), 'rendered' => $rendered];
    }
}
global $wpdb;
$owned = $wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE BINARY meta_key = 'wpforms_form_locations' ORDER BY meta_id", ARRAY_A);
$check(is_array($owned) && $wpdb->last_error === '', 'complete physical owned rows');
$padding = [];
foreach ($saved['padding'] as $paddingId) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $paddingId), ARRAY_A);
    $check(is_array($row) && $wpdb->last_error === '', 'complete target-only native preimage');
    $padding[] = $row;
}
$blockWidgets = get_option('widget_block', []);
$check(is_array($blockWidgets), 'complete native block-widget option');
$blockFormIds = [];
foreach ($blockWidgets as $number => $settings) {
    if ($number === '_multiwidget') continue;
    $check(is_array($settings) && is_string($settings['content'] ?? null), 'native block-widget content');
    // Locator documents int[] but preserves regex capture-array offsets.
    // This observation transports the ordered ID values, not regex offsets.
    $blockFormIds[$number] = array_values($locator->get_form_ids($settings['content']));
}
echo wp_json_encode(['format' => 'wprism-wpforms-apply-observation/v1', 'case' => $case,
    'version' => WPFORMS_VERSION, 'home' => home_url(), 'posts' => $posts, 'native' => $native,
    'widgets' => $locator->search_in_widgets(),
    'widget_options' => ['wpforms-widget' => get_option('widget_wpforms-widget', []), 'block' => $blockWidgets],
    'block_form_ids' => $blockFormIds,
    'owned' => $owned, 'padding' => $padding] + $emptyWitness, JSON_THROW_ON_ERROR);
