#!/usr/bin/env bash
# Exact-artifact Elementor source estate. Every authored structure is created
# through WordPress/Elementor APIs, including classic and Atomic documents,
# a saved library template, a custom active kit, media controls, a plain-link
# URL, and a large UTF-8 document. High post identities make accidental raw-id
# survival visible when the target starts in a different numeric range.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
SOURCE_REPO="${CONF_REPO1:-siterepo/conf1}"
SEED_FILE="$SOURCE_REPO/.tmp-elementor-seed.php"

cat > "$SEED_FILE" <<'PHPEOF'
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
global $wpdb;

$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (!$admins) {
    throw new RuntimeException('Elementor seed requires an administrator');
}
wp_set_current_user($admins[0]->ID);
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 4100001");
if ($wpdb->last_error !== '') {
    throw new RuntimeException('Elementor seed could not establish the high-ID premise');
}
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 4200001");
if ($wpdb->last_error !== '') {
    throw new RuntimeException('Elementor seed could not establish the high-term-ID premise');
}

function wprism_elementor_image(string $path, int $red, int $green, int $blue): void {
    $image = imagecreatetruecolor(96, 72);
    if (!$image) {
        throw new RuntimeException('Elementor seed could not allocate an image');
    }
    imagefilledrectangle($image, 0, 0, 95, 71, imagecolorallocate($image, $red, $green, $blue));
    imagepng($image, $path);
    imagedestroy($image);
}

function wprism_elementor_import(string $path, string $title): int {
    $id = media_handle_sideload(['name' => basename($path), 'tmp_name' => $path], 0, $title);
    if (is_wp_error($id)) {
        throw new RuntimeException('Elementor attachment import failed');
    }
    return (int) $id;
}

function wprism_elementor_post(string $type, string $title, string $slug, string $content = ''): int {
    $id = wp_insert_post([
        'post_type' => $type,
        'post_title' => $title,
        'post_name' => $slug,
        'post_status' => 'publish',
        'post_content' => $content,
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException('Elementor post creation failed');
    }
    return (int) $id;
}

function wprism_elementor_save(int $id, string $templateType, array $elements): void {
    update_post_meta($id, '_elementor_edit_mode', 'builder');
    update_post_meta($id, '_elementor_template_type', $templateType);
    $document = \Elementor\Plugin::$instance->documents->get($id);
    if (!$document || $document->save(['elements' => $elements]) === false) {
        throw new RuntimeException("Elementor Document::save() failed for $id");
    }
}

wprism_elementor_image('/tmp/wprism-conf-elementor-hero.png', 140, 60, 160);
wprism_elementor_image('/tmp/wprism-conf-elementor-gallery-a.png', 60, 140, 90);
wprism_elementor_image('/tmp/wprism-conf-elementor-gallery-b.png', 60, 90, 140);
wprism_elementor_image('/tmp/wprism-conf-elementor-bg.png', 160, 140, 60);
$hero = wprism_elementor_import('/tmp/wprism-conf-elementor-hero.png', 'WPrism Conformance Elementor Hero');
$galleryA = wprism_elementor_import('/tmp/wprism-conf-elementor-gallery-a.png', 'WPrism Conformance Elementor Gallery A');
$galleryB = wprism_elementor_import('/tmp/wprism-conf-elementor-gallery-b.png', 'WPrism Conformance Elementor Gallery B');
$background = wprism_elementor_import('/tmp/wprism-conf-elementor-bg.png', 'WPrism Conformance Elementor BG');
$media = [];
foreach (['hero' => $hero, 'gallery_a' => $galleryA, 'gallery_b' => $galleryB, 'background' => $background] as $key => $id) {
    $url = wp_get_attachment_url($id);
    if (!is_string($url) || $url === '') {
        throw new RuntimeException("Elementor attachment URL missing for $key");
    }
    $media[$key] = ['id' => $id, 'url' => $url, 'size' => 'full'];
}

$target = wprism_elementor_post(
    'page',
    'WPrism Elementor Target',
    'wprism-elementor-target',
    '<!-- wp:paragraph --><p>The portable link target 東京 🚀.</p><!-- /wp:paragraph -->'
);
$targetUrl = get_permalink($target);
if (!is_string($targetUrl) || $targetUrl === '') {
    throw new RuntimeException('Elementor link target URL missing');
}

$largeWidgets = [];
for ($i = 0; $i < 128; $i++) {
    $largeWidgets[] = [
        'id' => sprintf('lg%06x', $i),
        'elType' => 'widget',
        'widgetType' => 'heading',
        'settings' => [
            'title' => sprintf('Portable nested heading %03d 東京 🚀 delimiter |%%| {{literal}}', $i),
            'header_size' => $i % 2 === 0 ? 'h3' : 'h4',
        ],
        'elements' => [],
    ];
}

$classic = wprism_elementor_post('page', 'WPrism Conformance Elementor Page', 'wprism-conformance-elementor-page');
$classicElements = [[
    'id' => 'ceelsec1',
    'elType' => 'section',
    'settings' => [
        'background_background' => 'classic',
        'background_image' => $media['background'],
    ],
    'elements' => [
        [
            'id' => 'ceelcol1',
            'elType' => 'column',
            'settings' => ['_column_size' => 50],
            'elements' => [
                [
                    'id' => 'ceelimg1',
                    'elType' => 'widget',
                    'widgetType' => 'image',
                    'settings' => ['image' => $media['hero']],
                    'elements' => [],
                ],
                [
                    'id' => 'ceelbtn1',
                    'elType' => 'widget',
                    'widgetType' => 'button',
                    'settings' => [
                        'text' => 'Learn more 東京 🚀',
                        'link' => ['url' => $targetUrl . '?from=elementor&encoded=a%2Fb'],
                    ],
                    'elements' => [],
                ],
            ],
        ],
        [
            'id' => 'ceelcol2',
            'elType' => 'column',
            'settings' => ['_column_size' => 50],
            'elements' => array_merge([[
                'id' => 'ceelgal1',
                'elType' => 'widget',
                'widgetType' => 'image-gallery',
                'settings' => ['wp_gallery' => [$media['gallery_a'], $media['gallery_b']]],
                'elements' => [],
            ]], $largeWidgets),
        ],
    ],
]];
wprism_elementor_save($classic, 'wp-page', $classicElements);

$deletion = wprism_elementor_post('page', 'WPrism Elementor Deletion Page', 'wprism-elementor-deletion-page');
wprism_elementor_save($deletion, 'wp-page', [[
    'id' => 'delsect1',
    'elType' => 'section',
    'settings' => ['background_background' => 'classic', 'background_color' => '#2468ac'],
    'elements' => [[
        'id' => 'delcol01',
        'elType' => 'column',
        'settings' => ['_column_size' => 100],
        'elements' => [[
            'id' => 'delhead1',
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => ['title' => 'Disposable Elementor deletion fixture 東京 🚀'],
            'elements' => [],
        ]],
    ]],
]]);

$template = wprism_elementor_post('elementor_library', 'WPrism Portable Section', 'wprism-portable-section');
wprism_elementor_save($template, 'section', [[
    'id' => 'tplsect1',
    'elType' => 'section',
    'settings' => [],
    'elements' => [[
        'id' => 'tplcol01',
        'elType' => 'column',
        'settings' => ['_column_size' => 100],
        'elements' => [[
            'id' => 'tplimg01',
            'elType' => 'widget',
            'widgetType' => 'image',
            'settings' => ['image' => $media['gallery_a']],
            'elements' => [],
        ]],
    ]],
]]);
$libraryTypes = wp_get_object_terms($template, 'elementor_library_type', ['fields' => 'ids']);
if (is_wp_error($libraryTypes) || count($libraryTypes) !== 1) {
    throw new RuntimeException('Elementor saved template did not create one library-type term');
}
$libraryType = (int) $libraryTypes[0];

$atomic = 0;
$atomicSupported = class_exists('\Elementor\Modules\AtomicWidgets\PropTypes\Html_V3_Prop_Type')
    && class_exists('\Elementor\Modules\AtomicWidgets\PropTypes\Image_Prop_Type')
    && class_exists('\Elementor\Modules\AtomicWidgets\PropTypes\Image_Src_Prop_Type')
    && class_exists('\Elementor\Modules\AtomicWidgets\PropTypes\Image_Attachment_Id_Prop_Type')
    && class_exists('\Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type');
if ($atomicSupported) {
    $stringType = '\Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type';
    $htmlType = '\Elementor\Modules\AtomicWidgets\PropTypes\Html_V3_Prop_Type';
    $attachmentType = '\Elementor\Modules\AtomicWidgets\PropTypes\Image_Attachment_Id_Prop_Type';
    $imageSourceType = '\Elementor\Modules\AtomicWidgets\PropTypes\Image_Src_Prop_Type';
    $imageType = '\Elementor\Modules\AtomicWidgets\PropTypes\Image_Prop_Type';
    $atomic = wprism_elementor_post('page', 'WPrism Atomic Elementor Page', 'wprism-atomic-elementor-page');
    wprism_elementor_save($atomic, 'wp-page', [[
        'id' => 'atcont01',
        'elType' => 'e-flexbox',
        'settings' => [],
        'elements' => [
            [
                'id' => 'athead01',
                'elType' => 'widget',
                'widgetType' => 'e-heading',
                'settings' => [
                    'title' => $htmlType::generate([
                        'content' => $stringType::generate('Atomic portable heading 東京 🚀'),
                        'children' => [],
                    ]),
                ],
                'elements' => [],
            ],
            [
                'id' => 'atimg001',
                'elType' => 'widget',
                'widgetType' => 'e-image',
                'settings' => [
                    'image' => $imageType::generate([
                        'src' => $imageSourceType::generate([
                            'id' => $attachmentType::generate($hero),
                        ]),
                        'size' => $stringType::generate('full'),
                    ]),
                ],
                'elements' => [],
            ],
        ],
    ]]);
    $atomicRaw = json_decode((string) get_post_meta($atomic, '_elementor_data', true), true);
    $atomicId = $atomicRaw[0]['elements'][1]['settings']['image']['value']['src']['value']['id']['value'] ?? null;
    if ((int) $atomicId !== $hero) {
        throw new RuntimeException('Elementor Atomic attachment envelope was not persisted at the certified path');
    }
}

$kitId = (int) \Elementor\Plugin::$instance->kits_manager->create_new_kit('WPrism Portable Kit', [], true);
$kit = \Elementor\Plugin::$instance->documents->get($kitId);
if (!$kit instanceof \Elementor\Core\Kits\Documents\Kit) {
    throw new RuntimeException('Elementor custom kit creation failed');
}
$kit->update_settings([
    'system_colors' => [
        ['_id' => 'primary', 'title' => 'WPrism Primary', 'color' => '#123456'],
        ['_id' => 'secondary', 'title' => 'WPrism Secondary', 'color' => '#654321'],
    ],
    'custom_colors' => [
        ['_id' => 'wprismcustom', 'title' => 'WPrism Custom 東京 🚀', 'color' => '#abcdef'],
    ],
    'default_generic_fonts' => 'Inter, Arial, sans-serif',
    'site_logo' => $media['hero'],
    'site_favicon' => $media['gallery_a'],
    'background_image' => $media['background'],
    'background_slideshow_gallery' => [$media['gallery_a'], $media['gallery_b']],
]);
$kitSettings = get_post_meta($kitId, '_elementor_page_settings', true);
if (!is_array($kitSettings)
    || (int) ($kitSettings['site_logo']['id'] ?? 0) !== $hero
    || (int) ($kitSettings['site_favicon']['id'] ?? 0) !== $galleryA
    || (int) ($kitSettings['background_image']['id'] ?? 0) !== $background
    || (int) ($kitSettings['background_slideshow_gallery'][1]['id'] ?? 0) !== $galleryB
    || (int) get_option('elementor_active_kit') !== $kitId) {
    throw new RuntimeException('Elementor custom kit settings were not persisted through Kit::update_settings()');
}

$ids = [
    'atomic' => $atomic,
    'atomic_supported' => $atomicSupported,
    'background' => $background,
    'classic' => $classic,
    'deletion' => $deletion,
    'gallery_a' => $galleryA,
    'gallery_b' => $galleryB,
    'hero' => $hero,
    'kit' => $kitId,
    'library_type' => $libraryType,
    'target' => $target,
    'template' => $template,
];
foreach (['background', 'classic', 'deletion', 'gallery_a', 'gallery_b', 'hero', 'kit', 'target', 'template'] as $key) {
    if ($ids[$key] < 4100001) {
        throw new RuntimeException("Elementor high-ID premise failed for $key");
    }
}
if ($ids['library_type'] < 4200001) {
    throw new RuntimeException('Elementor high-term-ID premise failed for library_type');
}
echo wp_json_encode($ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

SEED_OUT=$($COMPOSE run --rm -T cli1 wp eval-file /siterepo/.tmp-elementor-seed.php)
rm -f "$SEED_FILE"
require_observed_nonempty 'conf1 Elementor source fixture output' "$SEED_OUT"
SOURCE_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '
  .classic >= 4100001 and .deletion >= 4100001 and .target >= 4100001 and .template >= 4100001 and
  .kit >= 4100001 and .hero >= 4100001 and .background >= 4100001 and .library_type >= 4200001 and
  (.atomic_supported == false or .atomic >= 4100001)
' <<<"$SOURCE_JSON" >/dev/null || fail "Elementor source fixture premise was incomplete: $SOURCE_JSON"
printf '%s\n' "$SOURCE_JSON" > "$SOURCE_REPO/.tmp-elementor-source.json"

curl -fs "http://localhost:${CONF1_PORT}/wprism-conformance-elementor-page/" >/dev/null \
  || fail 'conf1 classic Elementor page did not render before capture'
if [ "$(jq -r '.atomic_supported' <<<"$SOURCE_JSON")" = true ]; then
  curl -fs "http://localhost:${CONF1_PORT}/wprism-atomic-elementor-page/" >/dev/null \
    || fail 'conf1 Atomic Elementor page did not render before capture'
fi

pass 'Elementor source has classic/Atomic documents, library/kit entities, large UTF-8 data, media refs, and lazy render state at high identities'
