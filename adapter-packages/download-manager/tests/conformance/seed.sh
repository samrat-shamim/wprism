#!/usr/bin/env bash
# Author the source fixture through Download Manager's OWN writers, not through
# bare meta writes that would merely reproduce what the manifest already says.
#
# Packages.php:29 hooks savePackage() on save_post and Categories.php:20-21
# hooks saveMetaData() on create_wpdmcategory. Both read $_POST, so the seed
# populates $_POST and lets WordPress fire them. Neither hook returns a value,
# so the seed proves each one ran by reading back a row only that writer
# produces: __wpdm_masterkey (Packages.php:117-120, minted whenever absent) and
# the __wpdm_-prefixed term meta saveMetaData() writes from __wpdmcategory.
#
# savePackage() additionally guards on get_post_type() with NO argument, which
# resolves the global $post. An admin request has it; wp eval-file does not, so
# the first save_post returns early and mints no master key (measured on this
# harness: masterkey after insert=false, after update with the global set=true).
# The seed therefore reproduces the admin request's state — insert, publish the
# global, then update — rather than writing __wpdm_ rows itself. Do not "fix" a
# future failure here by calling update_post_meta directly: that would prove the
# manifest against the seed instead of against the plugin.
#
# __wpdm_files is validated by the plugin, not stored verbatim:
# isBlocked/locateFile/allowedPath blank any name that does not resolve under
# UPLOAD_DIR, so the fixture file has to exist before the save.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

if (!post_type_exists('wpdmpro') || !taxonomy_exists('wpdmcategory') || !taxonomy_exists('wpdmtag')) {
    throw new RuntimeException('Download Manager did not register wpdmpro/wpdmcategory/wpdmtag');
}

// Categories::saveMetaData() sanitizes each posted member and stores it as
// term meta "__wpdm_<key>". pagestyle is the per-term override of the global
// __wpdm_cpage layout defaults.
$_POST['__wpdmcategory'] = [
    'style' => 'grid',
    'pagestyle' => ['template' => 'link-template-default', 'cols' => 3, 'colspad' => 2, 'colsphone' => 1, 'heading' => 1],
    'access' => ['administrator', 'subscriber'],
];
$category = wp_insert_term('WPrism Handbooks', 'wpdmcategory', ['slug' => 'wprism-dlm-handbooks']);
if (is_wp_error($category)) {
    throw new RuntimeException('wpdmcategory term was not created: ' . $category->get_error_message());
}
$categoryId = (int) $category['term_id'];
if (get_term_meta($categoryId, '__wpdm_style', true) !== 'grid') {
    throw new RuntimeException('Categories::saveMetaData did not run on create_wpdmcategory');
}
$tag = wp_insert_term('Release Notes', 'wpdmtag', ['slug' => 'wprism-dlm-release-notes']);
if (is_wp_error($tag)) {
    throw new RuntimeException('wpdmtag term was not created: ' . $tag->get_error_message());
}

$terms = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Download Terms',
    'post_name' => 'wprism-dlm-terms',
    'post_content' => 'Operator-authored terms page selected by the package lock options.',
], true);
if (is_wp_error($terms)) {
    throw new RuntimeException('terms page was not created: ' . $terms->get_error_message());
}

if (!file_exists(UPLOAD_DIR)) {
    WPDM()->createDir();
}
if (file_put_contents(UPLOAD_DIR . 'wprism-handbook.pdf', "%PDF-1.4 wprism conformance fixture\n") === false) {
    throw new RuntimeException('could not stage the package file under the plugin upload directory');
}

$_POST['file'] = [
    'access' => ['administrator'],
    'additional_previews' => [],
    'captcha_lock' => '0',
    'changelog' => [
        ['id' => 'c1', 'version' => '2.1.0', 'date' => '2026-01-15', 'changes' => '<p>Rewrote the onboarding chapter.</p>', 'timestamp' => 1768435200],
    ],
    'files' => ['wprism-handbook.pdf'],
    'link_label' => 'Download the handbook',
    'page_template' => 'page-template-default.php',
    'password_lock' => '0',
    'quota' => '25',
    'terms_check_label' => 'I accept the download terms',
    'terms_lock' => '1',
    'terms_page' => (string) (int) $terms,
    'terms_title' => 'Download terms',
    'version' => '2.1.0',
];
$package = wp_insert_post([
    'post_type' => 'wpdmpro',
    'post_status' => 'publish',
    'post_title' => 'WPrism Handbook',
    'post_name' => 'wprism-dlm-handbook',
    'post_content' => 'The handbook package description, authored in the editor wpdmpro supports.',
], true);
if (is_wp_error($package)) {
    throw new RuntimeException('wpdmpro package was not created: ' . $package->get_error_message());
}
$packageId = (int) $package;
if (get_post_meta($packageId, '__wpdm_masterkey', true) !== '') {
    throw new RuntimeException('savePackage ran without the global $post this harness deliberately withholds; the two-phase save below is no longer the native path');
}

$GLOBALS['post'] = get_post($packageId);
wp_update_post(['ID' => $packageId], true);
if (get_post_meta($packageId, '__wpdm_masterkey', true) === '') {
    throw new RuntimeException('Packages::savePackage did not run on save_post; no native master key was minted');
}
if (get_post_meta($packageId, '__wpdm_version', true) !== '2.1.0') {
    throw new RuntimeException('Packages::savePackage did not persist the posted package fields');
}
if (get_post_meta($packageId, '__wpdm_files', true) !== ['wprism-handbook.pdf']) {
    throw new RuntimeException('the package file did not survive the plugin\'s own upload-path validation');
}
unset($_POST['file'], $_POST['__wpdmcategory'], $GLOBALS['post']);

wp_set_object_terms($packageId, [$categoryId], 'wpdmcategory');
wp_set_object_terms($packageId, [(int) $tag['term_id']], 'wpdmtag');

$embed = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Handbook Downloads',
    'post_name' => 'wprism-dlm-embed',
    'post_content' => '[wpdm_package id="' . $packageId . '"]' . "\n" . '[wpdm_direct_link id="' . $packageId . '" label="Direct"]',
], true);
if (is_wp_error($embed)) {
    throw new RuntimeException('embedding page was not created: ' . $embed->get_error_message());
}

echo wp_json_encode([
    'category' => $categoryId,
    'embed' => (int) $embed,
    'package' => $packageId,
    'tag' => (int) $tag['term_id'],
    'terms_page' => (int) $terms,
]);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-download-manager-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-download-manager-seed.php)
rm -f "$SEED_FILE"
require_observed_nonempty "Download Manager native source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .package > 0 and .category > 0 and .tag > 0 and .embed > 0 and .terms_page > 0
  and .package != .embed and .embed != .terms_page
' >/dev/null || fail "Download Manager did not author distinct native fixtures: $SEED_JSON"
printf '%s\n' "$SEED_JSON"
pass "Download Manager package, category, tag, terms page and shortcode embeds were authored through the plugin's own save_post and create_wpdmcategory writers"
