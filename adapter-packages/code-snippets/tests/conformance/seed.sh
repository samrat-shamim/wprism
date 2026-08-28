#!/usr/bin/env bash
# Code Snippets production fixture: real plugin writes, mapped IDs above the
# activation defaults, executable PHP, executable HTML, invalid inactive PHP,
# LONGTEXT/UTF-8/delimiter bytes, both shortcodes, every ID alias, and optional
# flat-file execution enabled through the plugin's settings/rebuild contract.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

// Activation samples have no stable identity across environments. Remove the
// fresh-install rows through the plugin API before authoring the portable set;
// the target hook proves and performs the same bounded clean-target step.
foreach (Code_Snippets\get_snippets() as $existing) {
    if (!Code_Snippets\delete_snippet((int) $existing->id)) {
        throw new RuntimeException('could not remove a fresh-install Code Snippets sample');
    }
}

// Move the source sequence past the activation defaults without letting a
// fresh target accidentally share the proving IDs.
$spacer = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    'name' => 'Source identity spacer',
    'desc' => 'Deleted before capture.',
    'code' => '<p>discarded source spacer</p>',
    'scope' => 'content',
    'active' => false,
]));
if (!$spacer || !Code_Snippets\delete_snippet((int) $spacer->id)) {
    throw new RuntimeException('could not advance the source snippet identity sequence');
}

$long = str_repeat("界|comma,quote\"apostrophe'backslash\\\n", 1200);
$content = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    'name' => 'Duo portable content 東京 🚀',
    'desc' => "UTF-8 and delimiter proof: 東京 🚀 | comma, quote\" apostrophe' backslash\\\n" . $long,
    'code' => '<strong class="duo-code-snippet-marker">portable 東京 🚀 | comma, quote&quot; apostrophe&#039; backslash\\</strong>',
    'tags' => ['duo', 'portable', 'utf8'],
    'scope' => 'content',
    'priority' => 17,
    'active' => true,
]));
$runtime = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    'name' => 'Duo runtime filter',
    'desc' => 'Active PHP execution proof at the SMALLINT upper boundary.',
    'code' => "add_filter('duo_code_snippets_runtime', static function (\$value) { return \$value . '|repository-runtime'; });",
    'tags' => ['duo', 'runtime'],
    'scope' => 'global',
    'priority' => 32767,
    'active' => true,
]));
$invalid = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    'name' => 'Duo invalid inactive PHP',
    'desc' => 'A deliberately invalid program that must remain inactive and inert.',
    'code' => 'if (',
    'tags' => ['duo', 'invalid'],
    'scope' => 'global',
    'priority' => 1,
    'active' => false,
]));
if (!$content || !$runtime || !$invalid || (int) $content->id <= 4) {
    throw new RuntimeException('Code Snippets did not persist the full source fixture');
}
if (!$runtime->active || $invalid->active) {
    throw new RuntimeException('Code Snippets changed the intended active/inactive boundary');
}

$page = wp_insert_post([
    'post_title' => 'Code Snippets Reference Matrix',
    'post_name' => 'code-snippets-reference-matrix',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => sprintf(
        "[code_snippet id=\"%1\$d\"]\n"
        . "[code_snippet snippet_id=\"%1\$d\"]\n"
        . "[code_snippet_source id=\"%1\$d\"]\n"
        . "[code_snippet_source snippet_id=\"%1\$d\"]",
        (int) $content->id
    ),
], true);
if (is_wp_error($page) || !$page) {
    throw new RuntimeException('could not persist the Code Snippets reference page');
}

Code_Snippets\Settings\update_setting('general', 'enable_flat_files', true);
$settings = Code_Snippets\Settings\get_settings_values();
do_action('code_snippets/settings_updated', $settings);
$table = Code_Snippets\code_snippets()->db->get_table_name(false);
$hash = Code_Snippets\Snippet_Files::get_hashed_table_name($table);
$directory = Code_Snippets\Snippet_Files::get_base_dir($hash);
if (!Code_Snippets\Snippet_Files::is_active()
    || !is_file($directory . '/php/' . (int) $runtime->id . '.php')
    || !is_file($directory . '/html/' . (int) $content->id . '.php')) {
    throw new RuntimeException('Code Snippets did not build the source flat-file execution tree');
}

echo wp_json_encode([
    'content_id' => (int) $content->id,
    'flat_directory' => basename($directory),
    'invalid_active' => (bool) $invalid->active,
    'invalid_id' => (int) $invalid->id,
    'page_id' => (int) $page,
    'runtime_id' => (int) $runtime->id,
    'runtime_value' => apply_filters('duo_code_snippets_runtime', 'base'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-code-snippets-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-code-snippets-seed.php)
require_observed_nonempty "Code Snippets source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .content_id > 4 and .runtime_id > .content_id and .invalid_id > .runtime_id and
  .page_id > 0 and .invalid_active == false and
  .runtime_value == "base|repository-runtime" and
  (.flat_directory | test("^[a-f0-9]{32}$"))
' >/dev/null || fail "Code Snippets source fixture did not establish identity, execution, and flat-file premises: $SEED_JSON"
rm -f "$SEED_FILE"
pass "Code Snippets authored executable/content/invalid boundary rows, all shortcode aliases, hostile bytes, and flat files through plugin APIs"
