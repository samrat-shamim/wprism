#!/usr/bin/env bash
# Elementor manifest conformance seed: reproduces docs/frontier/elementor.md's
# exact verified fixture (an "image" widget referencing an attachment by id,
# a "button" widget with an internal link entered as a plain URL — carrying
# NO id at all, per the report's own finding) and extends it with the two
# additional media-control shapes task #11 wave 2 empirically verified
# beyond the report (section "background_image", gallery widget
# "wp_gallery" — an ARRAY of the same {id,url} shape) so all three declared
# json_refs paths in manifests/elementor.json are actually exercised, plus
# elementor_active_kit (the option-ref case the report found already works).
#
# Seeded via Elementor's OWN save pipeline
# (\Elementor\Plugin::$instance->documents->get($id)->save(['elements'=>...]))
# — the same code path the editor itself calls — per the report's own
# method, not a hand-written approximation of _elementor_data's shape.
# Document::save() checks is_editable_by_current_user(), which a bare
# wp-cli/eval context (user id 0) always fails silently (returns false, no
# exception) — wp_set_current_user() to an administrator first (found
# empirically this session; not mentioned in the original report's prose).
#
# Invoked by conformance/run.sh with wp_conf1/$COMPOSE already exported;
# runs from the sandbox/ directory. CONF1_PORT is set by run.sh (default
# below matches the legacy docker-compose.yml conf1 port for any standalone
# invocation).
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"

TARGET_ID=$(wp_conf1 post create --post_type=page --post_title='Duo Elementor Target' --post_name=duo-elementor-target \
  --post_status=publish --post_content='<!-- wp:paragraph --><p>The link target.</p><!-- /wp:paragraph -->' --porcelain)
TARGET_URL=$(wp_conf1 post get "$TARGET_ID" --field=url)

cat > siterepo/conf1/.tmp-elementor-seed.php <<PHP
<?php
error_reporting(E_ALL & ~E_DEPRECATED);

// Document::save() refuses to run for no current user (id 0, the default
// in a wp eval-file context) — is_editable_by_current_user() always fails
// silently otherwise (save() returns false, no exception).
\$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (\$admins) {
    wp_set_current_user(\$admins[0]->ID);
}

function duo_conf_make_img(\$path, \$r, \$g, \$b) {
    \$im = imagecreatetruecolor(64, 48);
    imagefilledrectangle(\$im, 0, 0, 63, 47, imagecolorallocate(\$im, \$r, \$g, \$b));
    imagepng(\$im, \$path);
}
duo_conf_make_img('/tmp/duo-conf-elementor-hero.png', 140, 60, 160);
duo_conf_make_img('/tmp/duo-conf-elementor-gallery-a.png', 60, 140, 90);
duo_conf_make_img('/tmp/duo-conf-elementor-gallery-b.png', 60, 90, 140);
duo_conf_make_img('/tmp/duo-conf-elementor-bg.png', 160, 140, 60);

function duo_conf_import(\$path, \$title) {
    \$id = media_handle_sideload(['name' => basename(\$path), 'tmp_name' => \$path], 0, \$title);
    if (is_wp_error(\$id)) {
        fwrite(STDERR, 'attachment import failed: ' . \$id->get_error_message() . "\n");
        exit(1);
    }
    return (int) \$id;
}
\$hero = duo_conf_import('/tmp/duo-conf-elementor-hero.png', 'Duo Conformance Elementor Hero');
\$galA = duo_conf_import('/tmp/duo-conf-elementor-gallery-a.png', 'Duo Conformance Elementor Gallery A');
\$galB = duo_conf_import('/tmp/duo-conf-elementor-gallery-b.png', 'Duo Conformance Elementor Gallery B');
\$bg   = duo_conf_import('/tmp/duo-conf-elementor-bg.png', 'Duo Conformance Elementor BG');
\$heroUrl = wp_get_attachment_url(\$hero);
\$galAUrl = wp_get_attachment_url(\$galA);
\$galBUrl = wp_get_attachment_url(\$galB);
\$bgUrl   = wp_get_attachment_url(\$bg);

\$page_id = wp_insert_post([
    'post_type' => 'page',
    'post_title' => 'Duo Conformance Elementor Page',
    'post_name' => 'duo-conformance-elementor-page',
    'post_status' => 'publish',
    'post_content' => '',
]);
update_post_meta(\$page_id, '_elementor_edit_mode', 'builder');
update_post_meta(\$page_id, '_elementor_template_type', 'wp-page');

\$elements = [
    [
        'id' => 'ceelsec01',
        'elType' => 'section',
        // section-level background image (Group_Control_Background,
        // name="background" -> "background_image" sub-key) — verified
        // shape task #11 wave 2, same {id,url} as every media control.
        'settings' => [
            'background_background' => 'classic',
            'background_image' => ['id' => \$bg, 'url' => \$bgUrl],
        ],
        'elements' => [
            [
                'id' => 'ceelcol01',
                'elType' => 'column',
                'settings' => ['_column_size' => 50],
                'elements' => [
                    // the report's exact verified shape: image widget (id
                    // ref) + button widget with a plain-URL internal link
                    // (NO id at all — Elementor doesn't know it's internal
                    // unless authored via Dynamic Tags, not exercised here,
                    // matching the report's own honest scope).
                    [
                        'id' => 'ceelimg01',
                        'elType' => 'widget',
                        'widgetType' => 'image',
                        'settings' => ['image' => ['id' => \$hero, 'url' => \$heroUrl]],
                        'elements' => [],
                    ],
                    [
                        'id' => 'ceelbtn01',
                        'elType' => 'widget',
                        'widgetType' => 'button',
                        'settings' => [
                            'text' => 'Learn more',
                            'link' => ['url' => '$TARGET_URL'],
                        ],
                        'elements' => [],
                    ],
                ],
            ],
            [
                'id' => 'ceelcol02',
                'elType' => 'column',
                'settings' => ['_column_size' => 50],
                'elements' => [
                    // gallery widget: wp_gallery is an ARRAY of the same
                    // {id,url} shape — exercises JsonRefs' array
                    // transparency (task #11 wave 2 verified shape).
                    [
                        'id' => 'ceelgal01',
                        'elType' => 'widget',
                        'widgetType' => 'image-gallery',
                        'settings' => [
                            'wp_gallery' => [
                                ['id' => \$galA, 'url' => \$galAUrl],
                                ['id' => \$galB, 'url' => \$galBUrl],
                            ],
                        ],
                        'elements' => [],
                    ],
                ],
            ],
        ],
    ],
];

\$doc = \Elementor\Plugin::\$instance->documents->get(\$page_id);
\$result = \$doc->save(['elements' => \$elements]);
if (\$result === false) {
    fwrite(STDERR, "Elementor Document::save() returned false\n");
    exit(1);
}

echo "elementor seed: target=$TARGET_ID page=\$page_id hero=\$hero galA=\$galA galB=\$galB bg=\$bg\n";
PHP
$COMPOSE run --rm -T cli1 wp eval-file /siterepo/.tmp-elementor-seed.php
rm -f siterepo/conf1/.tmp-elementor-seed.php

# docs/frontier/elementor.md's own finding, reproduced independently this
# session on fx1/fx2: _elementor_css / _elementor_element_cache / a
# versioned _elementor_migrations_state_<hash> are created lazily on the
# page's FIRST front-end render, not at save time — a capture taken before
# any render never sees them, which would let this seed silently exercise
# only 7 of the 10 real loud-gate keys and falsely look complete. Render
# conf1's own page now, before conformance/run.sh's first capture.
curl -fs "http://localhost:${CONF1_PORT}/duo-conformance-elementor-page/" >/dev/null \
  || fail "conf1 front-end render of the seeded elementor page failed"

echo "elementor seed: target page + hero/gallery/background images + kit (elementor_active_kit already set by activation), rendered once on conf1"
