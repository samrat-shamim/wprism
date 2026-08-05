#!/usr/bin/env bash
# ACF manifest conformance seed: one field group (group_duo_demo) with an
# image field (field_duo_hero) and a relationship field (field_duo_related),
# plus the content they're attached to — the same scenario spike E already
# proved end to end (sandbox/tests/spike_e_acf.sh), trimmed for the generic
# byte-for-byte round-trip check the conformance gate runs. Invoked by
# conformance/run.sh with wp_conf1/wp_conf2/$COMPOSE already exported.
set -euo pipefail

cat > siterepo/conf1/.tmp-seed-acf.php <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) {
    fwrite(STDERR, "ACF functions not available\n");
    exit(1);
}

acf_update_field_group([
    'key' => 'group_duo_demo',
    'title' => 'Duo Demo',
    'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'menu_order' => 0,
    'position' => 'normal',
    'style' => 'default',
    'label_placement' => 'top',
    'instruction_placement' => 'label',
    'active' => true,
]);
$group_posts = get_posts([
    'post_type' => 'acf-field-group', 'name' => 'group_duo_demo',
    'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any',
]);
$group_id = $group_posts ? (int) $group_posts[0] : 0;
if (!$group_id) {
    fwrite(STDERR, "field group not created\n");
    exit(1);
}

acf_update_field([
    'key' => 'field_duo_hero', 'label' => 'Hero Image', 'name' => 'duo_hero',
    'type' => 'image', 'parent' => $group_id, 'return_format' => 'id',
]);
acf_update_field([
    'key' => 'field_duo_related', 'label' => 'Related', 'name' => 'duo_related',
    'type' => 'relationship', 'parent' => $group_id, 'post_type' => ['post'], 'return_format' => 'id',
]);

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$upload_dir = wp_upload_dir();
$filename = trailingslashit($upload_dir['path']) . 'conf-acf-logo.png';
$im = imagecreatetruecolor(64, 48);
imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 90, 60, 200));
imagepng($im, $filename);
imagedestroy($im);

$filetype = wp_check_filetype(basename($filename), null);
$att_id = wp_insert_attachment([
    'post_mime_type' => $filetype['type'], 'post_title' => 'Conformance ACF Logo',
    'post_content' => '', 'post_status' => 'inherit',
], $filename);
if (is_wp_error($att_id) || !$att_id) {
    fwrite(STDERR, "attachment insert failed\n");
    exit(1);
}
update_post_meta($att_id, '_wp_attachment_image_alt', 'Conformance ACF logo');
wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $filename));

$target1 = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Conformance Related Target One', 'post_name' => 'conf-related-target-one',
    'post_content' => "<!-- wp:paragraph -->\n<p>Relationship target one.</p>\n<!-- /wp:paragraph -->",
], true);
$target2 = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Conformance Related Target Two', 'post_name' => 'conf-related-target-two',
    'post_content' => "<!-- wp:paragraph -->\n<p>Relationship target two.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($target1) || is_wp_error($target2)) {
    fwrite(STDERR, "target post insert failed\n");
    exit(1);
}

$content_id = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Conformance ACF Content', 'post_name' => 'conf-acf-content',
    'post_content' => "<!-- wp:paragraph -->\n<p>Carries ACF fields.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($content_id)) {
    fwrite(STDERR, "content post insert failed\n");
    exit(1);
}

update_field('duo_hero', $att_id, $content_id);
update_field('duo_related', [$target1, $target2], $content_id);

echo json_encode([
    'group' => $group_id, 'attachment' => $att_id,
    'target1' => $target1, 'target2' => $target2, 'content' => $content_id,
]) . "\n";
PHPEOF
SEED_JSON=$(wp_conf1 eval-file /siterepo/.tmp-seed-acf.php)
rm -f siterepo/conf1/.tmp-seed-acf.php
echo "acf seed: $SEED_JSON"
