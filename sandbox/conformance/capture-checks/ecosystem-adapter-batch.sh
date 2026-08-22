#!/usr/bin/env bash
# Source-side acceptance for the capture-plan profile. Runtime checks ask the
# live plugins to consume the persisted values; repository checks assert the
# portable subset and typed references, and assert that env/runtime residue is
# absent. Byte-identical recapture is already enforced by run.sh immediately
# before this hook.
set -euo pipefail

read -r -d '' CHECK_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

$snippet = null;
foreach (Code_Snippets\get_snippets() as $candidate) {
    if ($candidate->name === 'Duo conformance content') {
        $snippet = $candidate;
        break;
    }
}
$original = get_page_by_path('duo-original-article', OBJECT, 'post');
$duplicates = get_posts([
    'post_type' => 'post',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'meta_key' => '_dp_original',
    'meta_value' => $original ? $original->ID : 0,
]);
$duplicate = $duplicates[0] ?? null;
$buttons = array_values(apply_filters('mce_buttons', ['formatselect'], 'content'));
$classic_post_type = apply_filters('use_block_editor_for_post_type', true, 'post');
$content_render = $snippet ? do_shortcode('[code_snippet snippet_id="' . (int) $snippet->id . '"]') : '';
$source_render = $snippet ? do_shortcode('[code_snippet_source id="' . (int) $snippet->id . '"]') : '';

echo wp_json_encode([
    'advanced_buttons' => $buttons,
    'classic_allow_users' => get_option('classic-editor-allow-users'),
    'classic_post_type_uses_blocks' => (bool) $classic_post_type,
    'classic_replace' => get_option('classic-editor-replace'),
    'content_render' => $content_render,
    'duplicate_original' => $duplicate ? (int) get_post_meta($duplicate->ID, '_dp_original', true) : 0,
    'duplicate_title' => $duplicate ? $duplicate->post_title : '',
    'login_url' => wp_login_url(),
    'original_id' => $original ? (int) $original->ID : 0,
    'snippet_id' => $snippet ? (int) $snippet->id : 0,
    'source_render' => $source_render,
    'tadv_admin_settings' => get_option('tadv_admin_settings'),
    'tadv_settings' => get_option('tadv_settings'),
    'whl_page' => get_option('whl_page'),
    'whl_redirect_admin' => get_option('whl_redirect_admin'),
], JSON_UNESCAPED_SLASHES);
PHPEOF

CHECK_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-ecosystem-adapter-check.php"
printf '%s' "$CHECK_PHP" > "$CHECK_FILE"
RUNTIME_OUT=$(wp_conf1 eval-file /siterepo/.tmp-ecosystem-adapter-check.php)
require_observed_nonempty "ecosystem adapter runtime readback" "$RUNTIME_OUT"
RUNTIME_JSON=$(printf '%s\n' "$RUNTIME_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$RUNTIME_JSON" | jq -e '
  .advanced_buttons == ["bold", "italic", "underline"] and
  .tadv_settings.toolbar_1 == "bold,italic,underline" and
  .tadv_admin_settings.options == "no_autop" and
  .classic_replace == "classic" and
  .classic_allow_users == "disallow" and
  .classic_post_type_uses_blocks == false and
  (.content_render | contains("duo-code-snippet-marker")) and
  (.source_render | contains("duo-code-snippet-marker")) and
  .snippet_id > 1 and
  .original_id > 0 and .duplicate_original == .original_id and
  .duplicate_title == "Replica Duo Original Article Evidence" and
  .whl_page == "duo-login" and .whl_redirect_admin == "duo-missing" and
  (.login_url | contains("duo-login"))
' >/dev/null || fail "one or more plugin APIs did not consume the captured source values: $RUNTIME_JSON"
rm -f "$CHECK_FILE"
pass "all five live plugins consume the exact persisted settings/row/reference fixture"

OPTIONS_FILE="${CONF_REPO1:-siterepo/conf1}/state/options/core.json"
jq -e '
  .records.tadv_settings.value.toolbar_1 == "bold,italic,underline" and
  .records.tadv_admin_settings.value.options == "no_autop" and
  .records["classic-editor-replace"].value == "classic" and
  .records["classic-editor-allow-users"].value == "disallow" and
  .records.whl_page.value == "duo-login" and
  .records.whl_redirect_admin.value == "duo-missing" and
  .records.duplicate_post_title_prefix.value == "Replica" and
  .records.duplicate_post_title_suffix.value == "Evidence" and
  (.records | has("tadv_version") | not) and
  (.records | has("code_snippets_settings") | not) and
  (.records | has("code_snippets_version") | not) and
  (.records | has("whl_redirect") | not) and
  (.records | has("duplicate_post_version") | not)
' "$OPTIONS_FILE" >/dev/null || fail "canonical options do not match the authored/runtime/env boundary"
pass "canonical options contain the portable settings and exclude every sampled runtime/env row"

mapfile -t SNIPPET_FILES < <(find "${CONF_REPO1:-siterepo/conf1}/state/tables/snippets" -maxdepth 1 -type f -name '*.json' -print | sort)
[ "${#SNIPPET_FILES[@]}" -eq 5 ] \
  || fail "expected four packaged samples plus one proving snippet after the API deletion probe, got ${#SNIPPET_FILES[@]}"
mapfile -t PROVING_SNIPPET_FILES < <(jq -r 'select(.columns.name == "Duo conformance content") | input_filename' "${SNIPPET_FILES[@]}")
[ "${#PROVING_SNIPPET_FILES[@]}" -eq 1 ] \
  || fail "expected exactly one proving snippet by its plugin-owned name, got ${#PROVING_SNIPPET_FILES[@]}"
if jq -e 'select(.columns.name == "Discarded identity spacer")' "${SNIPPET_FILES[@]}" >/dev/null; then
  fail "Code Snippets API deletion left the identity spacer in canonical state"
fi
jq -e '
  .table == "snippets" and
  .columns.name == "Duo conformance content" and
  .columns.description == "Portable HTML rendered by both shortcode aliases." and
  .columns.code == "<strong class=\"duo-code-snippet-marker\">portable snippet</strong>" and
  .columns.tags == "duo, conformance" and
  .columns.scope == "content" and
  .columns.priority == "17" and .columns.active == "1" and
  (.columns | has("cloud_id") | not) and
  (.columns | has("condition_id") | not) and
  (.columns | has("modified") | not) and
  (.columns | has("revision") | not)
' "${PROVING_SNIPPET_FILES[0]}" >/dev/null || fail "captured proving snippet does not match the exact authored/runtime/env column boundary"
for SNIPPET_FILE in "${SNIPPET_FILES[@]}"; do
  jq -e '
    (.columns | keys | sort) == ["active", "code", "description", "name", "priority", "scope", "tags"] and
    (.meta == {})
  ' "$SNIPPET_FILE" >/dev/null || fail "typed snippet capture leaked an undeclared or runtime/env column: $SNIPPET_FILE"
done
pass "typed snippet capture retains only portable columns across packaged and proving rows"

for SHORTCODE_FORM in \
  '[code_snippet id="{{code_snippet:' \
  '[code_snippet snippet_id="{{code_snippet:' \
  '[code_snippet_source id="{{code_snippet:' \
  '[code_snippet_source snippet_id="{{code_snippet:'
do
  grep -RFqs "$SHORTCODE_FORM" "${CONF_REPO1:-siterepo/conf1}/state/posts" \
    || fail "Code Snippets shortcode form was not rewritten to code_snippet identity: $SHORTCODE_FORM"
done
grep -Rqs '{{post:' "${CONF_REPO1:-siterepo/conf1}/state/posts" \
  || fail "Yoast Duplicate Post _dp_original was not rewritten to a post identity token"
if grep -RqsE 'code_snippet (id|snippet_id)="[0-9]+"|_dp_original:[[:space:]]*[0-9]+' "${CONF_REPO1:-siterepo/conf1}/state/posts"; then
  fail "a source-local shortcode or _dp_original id leaked into canonical post state"
fi
pass "both shortcode aliases and _dp_original are portable tokens, never source-local ids"
