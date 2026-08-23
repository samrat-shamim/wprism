#!/usr/bin/env bash
# Hostile Elementor target, after deploy activation and before apply. It owns
# same-slug documents/media at a disjoint high-ID range, divergent authored
# state, a different active kit, stale rendered caches/CSS, and runtime options
# that promotion must preserve.
set -euo pipefail
TARGET_REPO="${CONF_REPO2:-siterepo/conf2}"
HOSTILE_FILE="$TARGET_REPO/.tmp-elementor-hostile.php"

cat > "$HOSTILE_FILE" <<'PHPEOF'
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
global $wpdb;

$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (!$admins) {
    throw new RuntimeException('Elementor hostile target requires an administrator');
}
wp_set_current_user($admins[0]->ID);
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 8100001");
if ($wpdb->last_error !== '') {
    throw new RuntimeException('Elementor target could not establish the high-ID premise');
}

function duo_elementor_target_image(string $path, int $red, int $green, int $blue): void {
    $image = imagecreatetruecolor(48, 36);
    if (!$image) {
        throw new RuntimeException('Elementor target could not allocate an image');
    }
    imagefilledrectangle($image, 0, 0, 47, 35, imagecolorallocate($image, $red, $green, $blue));
    imagepng($image, $path);
    imagedestroy($image);
}

function duo_elementor_target_import(string $path, string $title): int {
    $id = media_handle_sideload(['name' => basename($path), 'tmp_name' => $path], 0, $title);
    if (is_wp_error($id)) {
        throw new RuntimeException('Elementor target attachment import failed');
    }
    return (int) $id;
}

function duo_elementor_target_post(string $type, string $title, string $slug): int {
    $id = wp_insert_post([
        'post_type' => $type,
        'post_title' => $title,
        'post_name' => $slug,
        'post_status' => 'publish',
        'post_content' => '<p>Hostile target content that must converge.</p>',
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException('Elementor target post creation failed');
    }
    return (int) $id;
}

function duo_elementor_target_save(int $id, string $templateType, array $elements): void {
    update_post_meta($id, '_elementor_edit_mode', 'builder');
    update_post_meta($id, '_elementor_template_type', $templateType);
    $document = \Elementor\Plugin::$instance->documents->get($id);
    if (!$document || $document->save(['elements' => $elements]) === false) {
        throw new RuntimeException("Elementor target Document::save() failed for $id");
    }
}

duo_elementor_target_image('/tmp/duo-conf-elementor-hero.png', 220, 25, 25);
duo_elementor_target_image('/tmp/duo-conf-elementor-gallery-a.png', 25, 220, 25);
duo_elementor_target_image('/tmp/duo-conf-elementor-gallery-b.png', 25, 25, 220);
duo_elementor_target_image('/tmp/duo-conf-elementor-bg.png', 220, 220, 25);
$hero = duo_elementor_target_import('/tmp/duo-conf-elementor-hero.png', 'Duo Conformance Elementor Hero');
$galleryA = duo_elementor_target_import('/tmp/duo-conf-elementor-gallery-a.png', 'Duo Conformance Elementor Gallery A');
$galleryB = duo_elementor_target_import('/tmp/duo-conf-elementor-gallery-b.png', 'Duo Conformance Elementor Gallery B');
$background = duo_elementor_target_import('/tmp/duo-conf-elementor-bg.png', 'Duo Conformance Elementor BG');
$heroUrl = (string) wp_get_attachment_url($hero);

$target = duo_elementor_target_post('page', 'Target Elementor Link Stale', 'duo-elementor-target');
$classic = duo_elementor_target_post('page', 'Target Elementor Classic Stale', 'duo-conformance-elementor-page');
duo_elementor_target_save($classic, 'wp-page', [[
    'id' => 'stalsec1',
    'elType' => 'section',
    'settings' => [],
    'elements' => [[
        'id' => 'stalcol1',
        'elType' => 'column',
        'settings' => ['_column_size' => 100],
        'elements' => [[
            'id' => 'stalimg1',
            'elType' => 'widget',
            'widgetType' => 'image',
            'settings' => ['image' => ['id' => $hero, 'url' => $heroUrl]],
            'elements' => [],
        ]],
    ]],
]]);

$template = duo_elementor_target_post('elementor_library', 'Duo Portable Section', 'duo-portable-section');
duo_elementor_target_save($template, 'section', [[
    'id' => 'staltpl1',
    'elType' => 'section',
    'settings' => [],
    'elements' => [],
]]);

$atomic = 0;
if (class_exists('\Elementor\Modules\AtomicWidgets\PropTypes\Html_V3_Prop_Type')) {
    $atomic = duo_elementor_target_post('page', 'Target Atomic Elementor Stale', 'duo-atomic-elementor-page');
    duo_elementor_target_save($atomic, 'wp-page', [[
        'id' => 'stalat01',
        'elType' => 'container',
        'settings' => [],
        'elements' => [],
    ]]);
}

$kitId = (int) \Elementor\Plugin::$instance->kits_manager->create_new_kit('Duo Portable Kit', [], true);
$kit = \Elementor\Plugin::$instance->documents->get($kitId);
if (!$kit instanceof \Elementor\Core\Kits\Documents\Kit) {
    throw new RuntimeException('Elementor hostile custom kit creation failed');
}
$kit->update_settings([
    'system_colors' => [['_id' => 'primary', 'title' => 'Target Stale', 'color' => '#ff0000']],
    'custom_colors' => [['_id' => 'targetonly', 'title' => 'Target Only', 'color' => '#00ff00']],
    'default_generic_fonts' => 'Comic Sans MS',
    'site_logo' => ['id' => $galleryB, 'url' => (string) wp_get_attachment_url($galleryB), 'size' => 'full'],
    'site_favicon' => ['id' => $background, 'url' => (string) wp_get_attachment_url($background), 'size' => 'full'],
    'background_image' => ['id' => $hero, 'url' => $heroUrl, 'size' => 'full'],
    'background_slideshow_gallery' => [['id' => $hero, 'url' => $heroUrl, 'size' => 'full']],
]);

update_post_meta($classic, '_elementor_element_cache', '<div>stale rendered target secret</div>');
update_post_meta($classic, '_elementor_page_assets', ['stale-target-asset']);
update_option('elementor_connect_site_key', 'target-connect-site-key-preserved');
update_option('elementor_checklist', ['completed' => ['target-runtime-marker']]);
update_option('elementor_atomic_cache_validity__global', 'target-atomic-cache-marker');
update_option('elementor_experiment-e_atomic_elements', 'target-experiment-marker');
update_option('elementor_target_undeclared_neighbor', 'target-neighbor-preserved');

$uploads = wp_upload_dir();
$cssDir = rtrim((string) ($uploads['basedir'] ?? ''), '/') . '/elementor/css';
if ($cssDir === '/elementor/css' || (!is_dir($cssDir) && !wp_mkdir_p($cssDir))) {
    throw new RuntimeException('Elementor target stale CSS directory could not be created');
}
file_put_contents($cssDir . '/post-' . $classic . '.css', 'stale-target-css https://target.invalid');
file_put_contents($cssDir . '/post-999999.css', 'orphan-target-css');

$ids = [
    'atomic' => $atomic,
    'background' => $background,
    'classic' => $classic,
    'gallery_a' => $galleryA,
    'gallery_b' => $galleryB,
    'hero' => $hero,
    'kit' => $kitId,
    'target' => $target,
    'template' => $template,
];
foreach (['background', 'classic', 'gallery_a', 'gallery_b', 'hero', 'kit', 'target', 'template'] as $key) {
    if ($ids[$key] < 8100001) {
        throw new RuntimeException("Elementor target high-ID premise failed for $key");
    }
}
echo wp_json_encode($ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-elementor-hostile.php)
rm -f "$HOSTILE_FILE"
require_observed_nonempty 'conf2 Elementor hostile fixture output' "$HOSTILE_OUT"
TARGET_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '
  .classic >= 8100001 and .target >= 8100001 and .template >= 8100001 and
  .kit >= 8100001 and .hero >= 8100001 and .background >= 8100001
' <<<"$TARGET_JSON" >/dev/null || fail "Elementor hostile target premise was incomplete: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_REPO/.tmp-elementor-target.json"

SOURCE_JSON=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-elementor-source.json")
for key in classic target template kit hero gallery_a gallery_b background; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_JSON")
  TARGET_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_JSON")
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Elementor divergent identity premise failed for $key ($SOURCE_ID)"
done
if [ "$(jq -r '.atomic_supported' <<<"$SOURCE_JSON")" = true ]; then
  SOURCE_ATOMIC=$(jq -r '.atomic' <<<"$SOURCE_JSON")
  TARGET_ATOMIC=$(jq -r '.atomic' <<<"$TARGET_JSON")
  require_fixture_ids SOURCE_ATOMIC TARGET_ATOMIC
  [ "$SOURCE_ATOMIC" != "$TARGET_ATOMIC" ] || fail "Elementor divergent identity premise failed for atomic ($SOURCE_ATOMIC)"
fi

pass 'Elementor target begins with divergent same-slug identities, stale authored/cache/CSS state, and target-owned runtime options'
