#!/usr/bin/env bash
# Exact-artifact source fixture for five experimental adapters. Every write
# uses the plugin's own validation/persistence API where it exposes one; the
# two settings-only plugins expose only register_setting callbacks, so their
# validated values are written through WordPress's option API and WPS Hide
# Login's required rewrite flush is invoked explicitly. The fixture contains
# both shortcode aliases and a durable Duplicate Post reference so capture
# must prove the two non-scalar identity paths rather than merely options.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

// Advanced Editor Tools keeps its import/admin-save implementation private.
// Reach the live plugin instance registered on mce_buttons and invoke that
// exact persistence path with the same shape its JSON importer passes.
$advanced_editor = null;
foreach (($GLOBALS['wp_filter']['mce_buttons']->callbacks ?? []) as $callbacks) {
    foreach ($callbacks as $callback) {
        $fn = $callback['function'] ?? null;
        if (is_array($fn) && is_object($fn[0] ?? null) && ($fn[1] ?? null) === 'mce_buttons_1') {
            $advanced_editor = $fn[0];
            break 2;
        }
    }
}
if (!$advanced_editor) {
    throw new RuntimeException('Advanced Editor Tools live callback instance was not registered');
}
$save_settings = new ReflectionMethod($advanced_editor, 'save_settings');
$save_settings->invoke($advanced_editor, [
    'settings' => [
        'toolbar_1' => 'bold,italic,underline',
        'toolbar_2' => 'bullist,numlist,blockquote',
        'toolbar_3' => '',
        'toolbar_4' => '',
        'toolbar_classic_block' => 'bold,italic,link',
        'options' => '',
        'plugins' => '',
    ],
    'admin_settings' => [
        'options' => 'no_autop',
        'disabled_editors' => '',
    ],
]);

// Classic Editor's registered sanitizer callbacks are the whole save
// contract for these scalar settings.
update_option('classic-editor-replace', Classic_Editor::validate_option_editor('classic'));
update_option('classic-editor-allow-users', Classic_Editor::validate_option_allow_users('disallow'));

// Mint and delete one row through Code Snippets first so the proving row is
// not id=1. A scanner that accidentally preserves a local id is therefore
// observable rather than hidden by fresh-install symmetry.
$discarded = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    'name' => 'Discarded identity spacer',
    'desc' => 'Deleted before capture',
    'code' => '<p>discarded</p>',
    'scope' => 'content',
    'active' => false,
]));
if (!$discarded || !Code_Snippets\delete_snippet((int) $discarded->id)) {
    throw new RuntimeException('Code Snippets could not create/delete the identity spacer through its API');
}
$snippet = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    'name' => 'Duo conformance content',
    'desc' => 'Portable HTML rendered by both shortcode aliases.',
    'code' => '<strong class="duo-code-snippet-marker">portable snippet</strong>',
    'tags' => ['duo', 'conformance'],
    'scope' => 'content',
    'priority' => 17,
    'active' => true,
]));
if (!$snippet || (int) $snippet->id <= 1) {
    throw new RuntimeException('Code Snippets did not persist a divergent proving id through save_snippet()');
}
$shortcode_page = wp_insert_post([
    'post_title' => 'Ecosystem Shortcode References',
    'post_name' => 'ecosystem-shortcode-references',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => sprintf(
        '[code_snippet id="%1$d"]\n'
        . '[code_snippet snippet_id="%1$d"]\n'
        . '[code_snippet_source id="%1$d"]\n'
        . '[code_snippet_source snippet_id="%1$d"]',
        (int) $snippet->id
    ),
], true);
if (is_wp_error($shortcode_page) || !$shortcode_page) {
    throw new RuntimeException('could not persist the Code Snippets shortcode reference fixture');
}

// Use Yoast Duplicate Post's real clone function so _dp_original is authored
// by the plugin rather than manufactured directly by the test.
// WordPress CLI is not an admin request, so load the plugin's shipped admin
// API explicitly; duplicate-post.php does the same include when is_admin().
require_once DUPLICATE_POST_PATH . 'admin-functions.php';
update_option('duplicate_post_copytitle', 1);
update_option('duplicate_post_copycontent', 1);
update_option('duplicate_post_copystatus', 0);
update_option('duplicate_post_title_prefix', 'Replica');
update_option('duplicate_post_title_suffix', 'Evidence');
update_option('duplicate_post_types_enabled', ['post', 'page']);
update_option('duplicate_post_roles', ['administrator']);
$original_id = wp_insert_post([
    'post_title' => 'Duo Original Article',
    'post_name' => 'duo-original-article',
    'post_status' => 'publish',
    'post_type' => 'post',
    'post_content' => 'Original body copied through the plugin API.',
], true);
if (is_wp_error($original_id) || !$original_id) {
    throw new RuntimeException('could not persist the Duplicate Post original fixture');
}
$duplicate_id = duplicate_post_create_duplicate(get_post($original_id));
if (is_wp_error($duplicate_id) || !$duplicate_id || (int) get_post_meta($duplicate_id, '_dp_original', true) !== (int) $original_id) {
    throw new RuntimeException('Yoast Duplicate Post did not create a durable _dp_original reference');
}

// WPS Hide Login's settings callback is sanitize_title_with_dashes followed
// by a hard rewrite flush in its settings save path. Exercise both facts.
update_option('whl_page', sanitize_title_with_dashes('Duo Login'));
update_option('whl_redirect_admin', sanitize_title_with_dashes('Duo Missing'));
flush_rewrite_rules(true);

echo wp_json_encode([
    'duplicate_id' => (int) $duplicate_id,
    'original_id' => (int) $original_id,
    'shortcode_page_id' => (int) $shortcode_page,
    'snippet_id' => (int) $snippet->id,
], JSON_UNESCAPED_SLASHES);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-ecosystem-adapter-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-ecosystem-adapter-seed.php)
require_observed_nonempty "ecosystem adapter source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .snippet_id > 1 and .shortcode_page_id > 0 and
  .original_id > 0 and .duplicate_id > 0 and .original_id != .duplicate_id
' >/dev/null || fail "ecosystem adapter seed did not return four distinct live identities: $SEED_JSON"
rm -f "$SEED_FILE"
pass "five plugin-owned source fixtures were persisted, including divergent snippet and post references"
