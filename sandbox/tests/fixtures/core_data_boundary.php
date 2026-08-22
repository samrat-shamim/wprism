<?php
/**
 * Real WordPress fixture for the core adapter's difficult-value boundary.
 *
 * Loaded by regress_core_data_boundary.sh through WP-CLI's `--require`; the
 * harness then invokes one named function. All large values are regenerated from small deterministic
 * recipes, so observations compare the plugin-visible values rather than a
 * second checked-in state tree.
 */
declare(strict_types=1);

function duo_boundary_pattern(string $label, int $repeat): string {
    $unit = "বাংলা|東京|e\u{0301}|🚀|comma,quote\"apostrophe'backslash\\|N;|"
        . "a:1:{s:3:\"key\";s:5:\"value\";}|---\n";
    return "$label|" . str_repeat($unit, $repeat);
}

function duo_boundary_title(): string {
    return duo_boundary_pattern('CORE-TITLE', 96);
}

function duo_boundary_excerpt(): string {
    return duo_boundary_pattern('CORE-EXCERPT', 128);
}

function duo_boundary_term_name(): string {
    return 'Core Boundary ' . str_repeat('界', 48);
}

function duo_boundary_term_description(): string {
    return duo_boundary_pattern('CORE-TERM', 420)
        . home_url('/term-boundary/?next=' . rawurlencode(wp_upload_dir()['baseurl'] . '/term-asset.png'));
}

function duo_boundary_origin(): string {
    return duo_boundary_pattern('CORE-META', 360)
        . home_url('/origin/?asset=' . rawurlencode(wp_upload_dir()['baseurl'] . '/origin.png'));
}

function duo_boundary_widget_text(): string {
    return duo_boundary_pattern('CORE-WIDGET-TEXT', 260)
        . home_url('/widget/?asset=' . rawurlencode(wp_upload_dir()['baseurl'] . '/widget.png'));
}

function duo_boundary_menu_description(): string {
    return duo_boundary_pattern('CORE-MENU-DESCRIPTION', 96)
        . home_url('/menu-description');
}

function duo_boundary_menu_url(): string {
    return home_url('/boundary-menu/?asset=' . rawurlencode(wp_upload_dir()['baseurl'] . '/menu.png'))
        . '&pipe=one%7Ctwo&quote=%22';
}

function duo_boundary_body(int $attachmentId, string $attachmentUrl): string {
    return '<!-- wp:image {"id":' . $attachmentId . ',"url":"' . $attachmentUrl . '"} -->'
        . '<figure class="wp-block-image"><img src="' . $attachmentUrl . '" class="wp-image-'
        . $attachmentId . '"/></figure><!-- /wp:image -->' . "\n"
        . '[gallery ids="' . $attachmentId . '"]' . "\n"
        . home_url('/body/?asset=' . rawurlencode($attachmentUrl)) . "\n"
        . 'A review-only credential example: sk_live_DUOBOUNDARY1234567890' . "\n"
        . duo_boundary_pattern('CORE-BODY', 420);
}

/** @return array<string,mixed> */
function duo_boundary_ids(): array {
    $ids = get_option('duo_boundary_ids', []);
    if (!is_array($ids)) {
        throw new RuntimeException('core boundary fixture ids are unavailable');
    }
    return $ids;
}

function duo_boundary_attachment(): int {
    $bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=',
        true
    );
    if (!is_string($bytes)) {
        throw new RuntimeException('core boundary PNG fixture did not decode');
    }
    $upload = wp_upload_bits('core-boundary.png', null, $bytes);
    if (!empty($upload['error'])) {
        throw new RuntimeException('core boundary attachment upload failed');
    }
    $id = wp_insert_attachment([
        'post_title' => duo_boundary_pattern('CORE-MEDIA-TITLE', 64),
        'post_name' => 'core-boundary-media',
        'post_status' => 'inherit',
        'post_mime_type' => 'image/png',
    ], $upload['file']);
    if (is_wp_error($id) || (int) $id <= 0) {
        throw new RuntimeException('core boundary attachment row failed');
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata((int) $id, wp_generate_attachment_metadata((int) $id, $upload['file']));
    update_post_meta((int) $id, '_wp_attachment_image_alt', duo_boundary_pattern('CORE-MEDIA-ALT', 72));
    return (int) $id;
}

function duo_boundary_write_theme(
    int $attachmentId,
    int $menuId,
    bool $targetRuntime,
    int $customCssId = 0
): void {
    $url = (string) wp_get_attachment_url($attachmentId);
    $mods = [
        'nav_menu_locations' => ['primary' => $menuId],
        'background_color' => '1a2b3c',
        'custom_logo' => $attachmentId,
        'header_image' => $url,
        'header_image_data' => [
            'attachment_id' => $attachmentId,
            'url' => $url,
            'thumbnail_url' => $url,
            'height' => 1,
            'width' => 1,
        ],
    ];
    if ($targetRuntime) {
        $mods['sidebars_widgets'] = ['runtime' => 'target-theme-switch-history'];
        $mods['wp_classic_sidebars'] = ['runtime' => 'target-classic-sidebar-history'];
    }
    if ($customCssId > 0) {
        $mods['custom_css_post_id'] = $customCssId;
    }
    global $wpdb;
    $name = 'theme_mods_' . get_option('stylesheet');
    $wire = maybe_serialize($mods);
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $name
    ));
    $ok = $exists
        ? $wpdb->update($wpdb->options, ['option_value' => $wire], ['option_name' => $name])
        : $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $wire, 'autoload' => 'yes']);
    if ($ok === false) {
        throw new RuntimeException('core boundary theme-mod write failed');
    }
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
}

/** @return array{menu:int,item:int} */
function duo_boundary_menu(): array {
    $menu = wp_create_nav_menu('Core Boundary Menu');
    if (is_wp_error($menu)) {
        throw new RuntimeException('core boundary menu creation failed');
    }
    $item = wp_update_nav_menu_item((int) $menu, 0, [
        'menu-item-title' => duo_boundary_pattern('CORE-MENU-TITLE', 48),
        'menu-item-description' => duo_boundary_menu_description(),
        'menu-item-url' => duo_boundary_menu_url(),
        'menu-item-status' => 'publish',
        'menu-item-type' => 'custom',
    ]);
    if (is_wp_error($item) || (int) $item <= 0) {
        throw new RuntimeException('core boundary menu item creation failed');
    }
    update_post_meta((int) $item, '_menu_item_classes', ['boundary', '界|comma,quote"', 'slash\\class']);
    return ['menu' => (int) $menu, 'item' => (int) $item];
}

function duo_boundary_widgets(int $menuId, int $attachmentId, string $attachmentUrl): void {
    update_option('widget_text', [
        2 => [
            'title' => duo_boundary_pattern('CORE-WIDGET-TITLE', 48),
            'text' => duo_boundary_widget_text(),
            'filter' => null,
            'visual' => true,
        ],
        '_multiwidget' => 1,
    ], true);
    update_option('widget_block', [
        2 => ['content' => duo_boundary_body($attachmentId, $attachmentUrl)],
        '_multiwidget' => 1,
    ], true);
    update_option('widget_nav_menu', [
        2 => ['title' => duo_boundary_pattern('CORE-NAV-WIDGET', 32), 'nav_menu' => $menuId],
        '_multiwidget' => 1,
    ], true);
    update_option('sidebars_widgets', [
        'wp_inactive_widgets' => [],
        'sidebar-1' => ['text-2', 'block-2', 'nav_menu-2'],
        'array_version' => 3,
    ], true);
}

function duo_boundary_seed_source(): void {
    $attachmentId = duo_boundary_attachment();
    $attachmentUrl = (string) wp_get_attachment_url($attachmentId);
    $defaultCategory = (int) get_option('default_category');
    $term = wp_update_term($defaultCategory, 'category', [
        'name' => duo_boundary_term_name(),
        'slug' => 'core-boundary-category',
        'description' => duo_boundary_term_description(),
    ]);
    if (is_wp_error($term)) {
        throw new RuntimeException('core boundary category update failed');
    }
    $termId = (int) $term['term_id'];

    $postId = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_name' => 'core-boundary',
        'post_title' => duo_boundary_title(),
        'post_content' => duo_boundary_body($attachmentId, $attachmentUrl),
        'post_excerpt' => duo_boundary_excerpt(),
        'comment_status' => 'closed',
        'ping_status' => 'closed',
    ], true);
    if (is_wp_error($postId) || (int) $postId <= 0) {
        throw new RuntimeException('core boundary post creation failed');
    }
    $postId = (int) $postId;
    wp_set_object_terms($postId, [$termId], 'category');
    update_post_meta($postId, 'origin', duo_boundary_origin());
    update_post_meta($postId, '_wp_page_template', 'templates/界|quoted".php');
    update_post_meta($postId, '_thumbnail_id', $attachmentId);

    $menu = duo_boundary_menu();
    duo_boundary_widgets($menu['menu'], $attachmentId, $attachmentUrl);
    $css = wp_update_custom_css_post('body::before { content: "境界|comma,quote\\\""; }');
    if (is_wp_error($css) || !$css instanceof WP_Post) {
        throw new RuntimeException('core boundary Custom CSS post creation failed');
    }
    duo_boundary_write_theme($attachmentId, $menu['menu'], false, (int) $css->ID);

    update_option('blogname', duo_boundary_pattern('CORE-OPTION-TITLE', 160), true);
    update_option('permalink_structure', '', true);
    update_option('show_on_front', 'posts', true);
    update_option('page_on_front', '0', true);
    update_option('page_for_posts', '0', true);
    update_option('wp_page_for_privacy_policy', '0', true);
    update_option('default_category', (string) $termId, true);
    update_option('sticky_posts', [$postId, 0, -7, '999999999999999999999999999999999999'], true);
    update_option('posts_per_page', '999', true);
    update_option('blog_public', '0', true);
    update_option('_wp_session_core_boundary_source', 'source-runtime', false);

    global $wpdb;
    $wpdb->update(
        $wpdb->options,
        ['option_value' => 'N;'],
        ['option_name' => 'blogdescription']
    );
    wp_cache_delete('blogdescription', 'options');
    wp_cache_delete('alloptions', 'options');

    update_option('duo_boundary_ids', [
        'attachment' => $attachmentId,
        'post' => $postId,
        'term' => $termId,
        'menu' => $menu['menu'],
        'menu_item' => $menu['item'],
        'custom_css' => (int) $css->ID,
    ], false);
    echo wp_json_encode(duo_boundary_ids());
}

function duo_boundary_seed_target(): void {
    for ($i = 0; $i < 6; $i++) {
        $id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => "Target filler $i",
        ]);
        wp_delete_post((int) $id, true);
    }
    $frontId = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => 'target-hostile-front',
        'post_title' => 'Target hostile front page',
    ]);
    update_option('blogname', 'target-only-title', true);
    update_option('blogdescription', 'target-only-description', true);
    update_option('permalink_structure', '/target/%postname%/', true);
    update_option('show_on_front', 'page', true);
    update_option('page_on_front', (string) $frontId, true);
    update_option('page_for_posts', (string) $frontId, true);
    update_option('wp_page_for_privacy_policy', (string) $frontId, true);
    update_option('sticky_posts', [(int) $frontId], true);
    update_option('_wp_session_core_boundary_target', 'target-runtime-survives', false);
    update_option('duo_boundary_ids', ['hostile_front' => (int) $frontId], false);
    global $wpdb;
    $name = 'theme_mods_' . get_option('stylesheet');
    $runtimeWire = serialize([
        'sidebars_widgets' => ['runtime' => 'target-theme-switch-history'],
        'wp_classic_sidebars' => ['runtime' => 'target-classic-sidebar-history'],
    ]);
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $name
    ));
    $ok = $exists
        ? $wpdb->update($wpdb->options, ['option_value' => $runtimeWire], ['option_name' => $name])
        : $wpdb->insert($wpdb->options, [
            'option_name' => $name,
            'option_value' => $runtimeWire,
            'autoload' => 'yes',
        ]);
    if ($ok === false) {
        throw new RuntimeException('core boundary target theme-runtime write failed');
    }
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
    echo wp_json_encode(duo_boundary_ids());
}

/** @return array<string,mixed> */
function duo_boundary_observe(bool $updated): array {
    $post = get_page_by_path('core-boundary', OBJECT, 'post');
    $term = get_term_by('slug', 'core-boundary-category', 'category');
    $menu = wp_get_nav_menu_object('Core Boundary Menu');
    if (!$post instanceof WP_Post || !$term instanceof WP_Term || !$menu instanceof WP_Term) {
        throw new RuntimeException('core boundary observation cannot find the applied core entities');
    }
    $attachmentId = (int) get_post_meta($post->ID, '_thumbnail_id', true);
    $attachment = get_post($attachmentId);
    $attachmentUrl = (string) wp_get_attachment_url($attachmentId);
    $items = wp_get_nav_menu_items($menu->term_id, ['post_status' => 'publish']);
    $item = is_array($items) ? reset($items) : false;
    if (!$attachment instanceof WP_Post || !$item instanceof WP_Post) {
        throw new RuntimeException('core boundary observation cannot find media/menu children');
    }

    $sidebars = get_option('sidebars_widgets');
    $widgets = [];
    foreach ((array) ($sidebars['sidebar-1'] ?? []) as $key) {
        if (!preg_match('/^(text|block|nav_menu)-([1-9][0-9]*)$/', (string) $key, $match)) {
            continue;
        }
        $all = get_option('widget_' . $match[1]);
        $widgets[$match[1]] = is_array($all) ? ($all[(int) $match[2]] ?? null) : null;
    }
    $mods = get_theme_mods();
    $expectedTitle = duo_boundary_title() . ($updated ? '|UPDATED' : '');
    $sticky = get_option('sticky_posts');
    $locations = get_theme_mod('nav_menu_locations', []);

    return [
        'ids' => [
            'post' => (int) $post->ID,
            'attachment' => $attachmentId,
            'term' => (int) $term->term_id,
            'menu' => (int) $menu->term_id,
            'menu_item' => (int) $item->ID,
        ],
        'options' => [
            'blogname' => get_option('blogname') === duo_boundary_pattern('CORE-OPTION-TITLE', 160),
            'blogdescription_is_null' => get_option('blogdescription', 'missing') === null,
            'permalink_empty' => get_option('permalink_structure', null) === '',
            'show_on_front' => get_option('show_on_front') === 'posts',
            'page_on_front_zero' => get_option('page_on_front') === '0',
            'page_for_posts_zero' => get_option('page_for_posts') === '0',
            'privacy_zero' => get_option('wp_page_for_privacy_policy') === '0',
            'default_category' => (int) get_option('default_category') === (int) $term->term_id,
            'sticky_exact' => $sticky === [(int) $post->ID],
            'large_title_bytes' => strlen((string) get_option('blogname')),
        ],
        'post' => [
            'title' => $post->post_title === $expectedTitle,
            'body' => $post->post_content === duo_boundary_body($attachmentId, $attachmentUrl),
            'excerpt' => $post->post_excerpt === duo_boundary_excerpt(),
            'origin' => get_post_meta($post->ID, 'origin', true) === duo_boundary_origin(),
            'template' => get_post_meta($post->ID, '_wp_page_template', true) === 'templates/界|quoted".php',
            'thumbnail' => $attachmentId > 0,
            'body_bytes' => strlen($post->post_content),
            'excerpt_bytes' => strlen($post->post_excerpt),
            'meta_bytes' => strlen((string) get_post_meta($post->ID, 'origin', true)),
        ],
        'term' => [
            'name' => $term->name === duo_boundary_term_name(),
            'description' => $term->description === duo_boundary_term_description(),
            'description_bytes' => strlen($term->description),
        ],
        'media' => [
            'mime' => $attachment->post_mime_type === 'image/png',
            'url_target_bound' => str_starts_with($attachmentUrl, wp_upload_dir()['baseurl'] . '/'),
            'alt' => get_post_meta($attachmentId, '_wp_attachment_image_alt', true)
                === duo_boundary_pattern('CORE-MEDIA-ALT', 72),
        ],
        'menu' => [
            'title' => $item->post_title === duo_boundary_pattern('CORE-MENU-TITLE', 48),
            'description' => $item->post_content === duo_boundary_menu_description(),
            'url' => get_post_meta($item->ID, '_menu_item_url', true) === duo_boundary_menu_url(),
            'classes' => get_post_meta($item->ID, '_menu_item_classes', true)
                === ['boundary', '界|comma,quote"', 'slash\\class'],
            'location' => (int) ($locations['primary'] ?? 0) === (int) $menu->term_id,
        ],
        'widgets' => [
            'text' => is_array($widgets['text'] ?? null)
                && ($widgets['text']['title'] ?? null) === duo_boundary_pattern('CORE-WIDGET-TITLE', 48)
                && ($widgets['text']['text'] ?? null) === duo_boundary_widget_text()
                && array_key_exists('filter', $widgets['text']) && $widgets['text']['filter'] === null
                && ($widgets['text']['visual'] ?? null) === true,
            'block' => is_array($widgets['block'] ?? null)
                && ($widgets['block']['content'] ?? null) === duo_boundary_body($attachmentId, $attachmentUrl),
            'nav_menu' => is_array($widgets['nav_menu'] ?? null)
                && (int) ($widgets['nav_menu']['nav_menu'] ?? 0) === (int) $menu->term_id,
        ],
        'theme' => [
            'background' => ($mods['background_color'] ?? null) === '1a2b3c',
            'logo' => (int) ($mods['custom_logo'] ?? 0) === $attachmentId,
            'header_url' => ($mods['header_image'] ?? null) === $attachmentUrl,
            'header_data' => (int) ($mods['header_image_data']['attachment_id'] ?? 0) === $attachmentId
                && ($mods['header_image_data']['url'] ?? null) === $attachmentUrl
                && ($mods['header_image_data']['thumbnail_url'] ?? null) === $attachmentUrl,
            'runtime_sidebars' => ($mods['sidebars_widgets']['runtime'] ?? null)
                === 'target-theme-switch-history',
            'runtime_classic' => ($mods['wp_classic_sidebars']['runtime'] ?? null)
                === 'target-classic-sidebar-history',
        ],
        'runtime' => get_option('_wp_session_core_boundary_target') === 'target-runtime-survives',
    ];
}

function duo_boundary_secret_meta(): void {
    $ids = duo_boundary_ids();
    update_post_meta((int) $ids['post'], 'origin', 'sk_live_DUOBOUNDARYSECRET9988776655');
}

function duo_boundary_restore_meta(): void {
    $ids = duo_boundary_ids();
    update_post_meta((int) $ids['post'], 'origin', duo_boundary_origin());
}

function duo_boundary_corrupt_theme(): void {
    global $wpdb;
    $name = 'theme_mods_' . get_option('stylesheet');
    $malformed = serialize(['background_color' => '1a2b3c']) . 'i:42;';
    if ($wpdb->update($wpdb->options, ['option_value' => $malformed], ['option_name' => $name]) === false) {
        throw new RuntimeException('core boundary theme corruption fixture failed');
    }
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
}

function duo_boundary_restore_theme(): void {
    $ids = duo_boundary_ids();
    duo_boundary_write_theme(
        (int) $ids['attachment'],
        (int) $ids['menu'],
        false,
        (int) $ids['custom_css']
    );
}

function duo_boundary_update_source(): void {
    $ids = duo_boundary_ids();
    wp_update_post([
        'ID' => (int) $ids['post'],
        'post_title' => duo_boundary_title() . '|UPDATED',
    ]);
}
