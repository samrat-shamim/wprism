#!/usr/bin/env bash
# ACF manifest conformance seed: one field group (group_duo_demo) with an
# image field (field_duo_hero) and a relationship field (field_duo_related),
# plus the content they're attached to — the same scenario spike E already
# proved end to end (sandbox/tests/spike_e_acf.sh), trimmed for the generic
# byte-for-byte round-trip check the conformance gate runs. Invoked by
# conformance/run.sh with wp_conf1/wp_conf2/$COMPOSE already exported.
#
# Also seeds the taxonomy/user field branches (task #16 — these were
# implemented by inspection but never exercised end to end): a multi-value
# taxonomy field (checkbox, 2 terms) and a single-value one (radio, 1 term)
# on the owned "category" taxonomy, plus a single-value user field pointing
# at admin. Deliberately NOT seeded: a user that exists on only one of
# conf1/conf2 — Tokens::user_token_to_id()'s default-author fallback would
# legitimately produce different ids per env for such a ref (DESIGN.md
# §3.2 — users are env-local by design), which would fail the byte-for-byte
# canonical-JSON round trip for a reason that isn't a bug. admin exists
# identically on both conf envs (both `core install --admin_user=admin`),
# so the single-value user field here round-trips through user:admin without
# exercising that fallback path at all.
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
// field_type "checkbox" = multi-value; "add_term"/"save_terms"/"load_terms"
// off so this is purely an ACF postmeta ref, decoupled from the post's real
// term relationships (verified via the interpreter's empirical probe: a
// taxonomy field with save_terms=0 leaves term_relationships untouched).
acf_update_field([
    'key' => 'field_duo_cats', 'label' => 'Categories', 'name' => 'duo_cats',
    'type' => 'taxonomy', 'parent' => $group_id,
    'taxonomy' => 'category', 'field_type' => 'checkbox',
    'add_term' => 0, 'save_terms' => 0, 'load_terms' => 0, 'return_format' => 'id',
]);
// field_type "radio" = single-value.
acf_update_field([
    'key' => 'field_duo_cat', 'label' => 'Primary Category', 'name' => 'duo_cat',
    'type' => 'taxonomy', 'parent' => $group_id,
    'taxonomy' => 'category', 'field_type' => 'radio',
    'add_term' => 0, 'save_terms' => 0, 'load_terms' => 0, 'return_format' => 'id',
]);
// "multiple" off = single-value user field.
acf_update_field([
    'key' => 'field_duo_owner', 'label' => 'Owner', 'name' => 'duo_owner',
    'type' => 'user', 'parent' => $group_id,
    'role' => '', 'multiple' => 0, 'return_format' => 'id',
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

// category is an owned taxonomy (conformance/manifests.json's acf entry
// taxonomies list) so these mint _duo_uuid termmeta + ledger rows on capture
// just like any other in-scope term — the ACF taxonomy field's stored ids
// are refs into the very same term ledger post-term relationships use.
$cat_one = wp_insert_term('Conformance Category One', 'category', ['slug' => 'conf-cat-one']);
$cat_two = wp_insert_term('Conformance Category Two', 'category', ['slug' => 'conf-cat-two']);
$cat_three = wp_insert_term('Conformance Category Three', 'category', ['slug' => 'conf-cat-three']);
if (is_wp_error($cat_one) || is_wp_error($cat_two) || is_wp_error($cat_three)) {
    fwrite(STDERR, "category term insert failed\n");
    exit(1);
}
$cat_one_id = (int) $cat_one['term_id'];
$cat_two_id = (int) $cat_two['term_id'];
$cat_three_id = (int) $cat_three['term_id'];

$admin = get_user_by('login', 'admin');
if (!$admin) {
    fwrite(STDERR, "admin user not found\n");
    exit(1);
}

update_field('duo_hero', $att_id, $content_id);
update_field('duo_related', [$target1, $target2], $content_id);
update_field('duo_cats', [$cat_one_id, $cat_two_id], $content_id);
update_field('duo_cat', $cat_three_id, $content_id);
update_field('duo_owner', $admin->ID, $content_id);

echo json_encode([
    'group' => $group_id, 'attachment' => $att_id,
    'target1' => $target1, 'target2' => $target2, 'content' => $content_id,
    'cat_one' => $cat_one_id, 'cat_two' => $cat_two_id, 'cat_three' => $cat_three_id,
    'admin' => $admin->ID,
]) . "\n";
PHPEOF
SEED_JSON=$(wp_conf1 eval-file /siterepo/.tmp-seed-acf.php)
rm -f siterepo/conf1/.tmp-seed-acf.php
echo "acf seed: $SEED_JSON"
