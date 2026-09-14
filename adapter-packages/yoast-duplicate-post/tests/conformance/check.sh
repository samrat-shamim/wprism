#!/usr/bin/env bash
# Production-oriented Yoast Duplicate Post 4.7 proof. The generic harness has
# already completed clean deploy/apply and byte-identical recapture; this hook
# adds native API/UI behavior, role-derived state, conflict/force/idempotence,
# provider failure/retry, lifecycle residue, explicit deletions, runtime
# workflow sovereignty, and capture secrecy.
set -euo pipefail

observe_ydp() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Yoast Duplicate Post observation side: $side" ;;
  esac
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$original = get_page_by_path('wprism-duplicate-original', OBJECT, 'post');
$copy = get_page_by_path('wprism-duplicate-copy', OBJECT, 'post');
$settingNames = [
    'duplicate_post_blacklist', 'duplicate_post_copyattachments', 'duplicate_post_copyauthor',
    'duplicate_post_copychildren', 'duplicate_post_copycomments', 'duplicate_post_copycontent',
    'duplicate_post_copydate', 'duplicate_post_copyexcerpt', 'duplicate_post_copyformat',
    'duplicate_post_copymenuorder', 'duplicate_post_copypassword', 'duplicate_post_copyslug',
    'duplicate_post_copystatus', 'duplicate_post_copytemplate', 'duplicate_post_copythumbnail',
    'duplicate_post_copytitle', 'duplicate_post_increase_menu_order_by', 'duplicate_post_roles',
    'duplicate_post_show_link', 'duplicate_post_show_link_in', 'duplicate_post_show_notice',
    'duplicate_post_show_original_column', 'duplicate_post_show_original_in_post_states',
    'duplicate_post_show_original_meta_box', 'duplicate_post_taxonomies_blacklist',
    'duplicate_post_title_prefix', 'duplicate_post_title_suffix', 'duplicate_post_types_enabled',
];
$settings = [];
foreach ($settingNames as $name) {
    $value = get_option($name, null);
    if ($value !== null) {
        $settings[$name] = $value;
    }
}
ksort($settings, SORT_STRING);
$normalizeSetting = static function (mixed $value) use (&$normalizeSetting): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $nested) {
        $value[$key] = $normalizeSetting($nested);
    }
    return $value;
};
$settings = $normalizeSetting($settings);
$roles = [];
foreach (['administrator', 'wprism_reviewer', 'wprism_source_only', 'editor', 'subscriber'] as $name) {
    $role = get_role($name);
    $roles[$name] = $role ? $role->has_cap('copy_posts') : null;
}

$options = new Yoast\WP\Duplicate_Post\Admin\Options();
$generator = new Yoast\WP\Duplicate_Post\Admin\Options_Form_Generator(
    new Yoast\WP\Duplicate_Post\Admin\Options_Inputs()
);
$page = new Yoast\WP\Duplicate_Post\Admin\Options_Page(
    $options,
    $generator,
    new Yoast\WP\Duplicate_Post\UI\Asset_Manager()
);
$prefixHtml = $page->generate_input('duplicate_post_title_prefix');
$rolesHtml = $generator->generate_roles_permission_list();
$copyOriginal = $copy ? duplicate_post_get_original($copy) : null;
$cloneLink = $original ? duplicate_post_get_clone_post_link($original->ID, 'display', false) : '';
$rowActions = $original ? apply_filters('post_row_actions', [], $original) : [];
$postStates = $copy ? apply_filters('display_post_states', [], $copy) : [];
$metaboxHtml = '';
if ($copy && $copyOriginal) {
    ob_start();
    (new Yoast\WP\Duplicate_Post\UI\Metabox(
        new Yoast\WP\Duplicate_Post\Permissions_Helper()
    ))->custom_metabox_html($copy, ['args' => ['original' => $copyOriginal]]);
    $metaboxHtml = (string) ob_get_clean();
}

echo wp_json_encode([
    'copy' => $copy ? [
        'content_hash' => hash('sha256', (string) $copy->post_content),
        'excerpt' => (string) $copy->post_excerpt,
        'id' => (int) $copy->ID,
        'menu_order' => (int) $copy->menu_order,
        'original_id' => (int) get_post_meta($copy->ID, '_dp_original', true),
        'original_via_api' => $copyOriginal ? (int) $copyOriginal->ID : 0,
        'status' => (string) $copy->post_status,
        'title' => (string) $copy->post_title,
        'categories' => wp_get_post_terms($copy->ID, 'category', ['fields' => 'slugs']),
        'tags' => wp_get_post_terms($copy->ID, 'post_tag', ['fields' => 'slugs']),
        'runtime_creation' => get_post_meta($copy->ID, '_dp_creation_date_gmt', true),
        'runtime_is_rewrite' => get_post_meta($copy->ID, '_dp_is_rewrite_republish_copy', true),
    ] : null,
    'clone_link' => $cloneLink,
    'metabox_original_marker' => str_contains($metaboxHtml, 'duplicate_post_original_item_title_span'),
    'neighbor' => get_option('yoast_duplicate_post_target_neighbor', null),
    'option_count' => count($settings),
    'option_hash' => hash('sha256', wp_json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    'original' => $original ? [
        'content_hash' => hash('sha256', (string) $original->post_content),
        'id' => (int) $original->ID,
        'runtime_copy' => get_post_meta($original->ID, '_dp_has_rewrite_republish_copy', true),
        'runtime_republished' => get_post_meta($original->ID, '_dp_has_been_republished', true),
    ] : null,
    'post_states' => array_keys($postStates),
    'post_states_text' => implode('|', array_map('wp_strip_all_tags', $postStates)),
    'prefix' => get_option('duplicate_post_title_prefix'),
    'prefix_html_present' => str_contains($prefixHtml, esc_attr((string) get_option('duplicate_post_title_prefix'))),
    'roles' => $roles,
    'roles_html_reviewer' => str_contains($rolesHtml, 'duplicate-post-wprism-reviewer'),
    'row_actions' => array_keys($rowActions),
    'suffix' => get_option('duplicate_post_title_suffix'),
    'version' => get_option('duplicate_post_version'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF
  file="$repo/.tmp-yoast-duplicate-post-observe.php"
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-yoast-duplicate-post-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-yoast-duplicate-post-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "Yoast Duplicate Post $side observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

set_ydp_branch() { # <conf1|conf2> <repository|target>
  local side="$1" profile="$2" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Yoast Duplicate Post branch side: $side" ;;
  esac
  read -r -d '' BRANCH_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$profile = '__WPRISM_YDP_PROFILE__';
$copy = get_page_by_path('wprism-duplicate-copy', OBJECT, 'post');
if (!$copy) { throw new RuntimeException('mapped duplicate is absent'); }
if ($profile === 'repository') {
    update_option('duplicate_post_title_prefix', 'Repository prefix 東京 🚀');
    update_option('duplicate_post_title_suffix', 'Repository suffix | reviewed');
    update_option('duplicate_post_roles', ['administrator', 'editor']);
    wp_update_post(['ID' => $copy->ID, 'post_excerpt' => 'Repository competing excerpt 東京 🚀']);
} elseif ($profile === 'target') {
    update_option('duplicate_post_title_prefix', 'Target competing prefix');
    update_option('duplicate_post_title_suffix', 'Target competing suffix');
    update_option('duplicate_post_roles', ['wprism_reviewer', 'subscriber']);
    wp_update_post(['ID' => $copy->ID, 'post_excerpt' => 'Target competing excerpt']);
} else {
    throw new RuntimeException('unknown branch profile');
}
$_GET['settings-updated'] = 'true';
$page = new Yoast\WP\Duplicate_Post\Admin\Options_Page(
    new Yoast\WP\Duplicate_Post\Admin\Options(),
    new Yoast\WP\Duplicate_Post\Admin\Options_Form_Generator(
        new Yoast\WP\Duplicate_Post\Admin\Options_Inputs()
    ),
    new Yoast\WP\Duplicate_Post\UI\Asset_Manager()
);
$page->register_capabilities();
unset($_GET['settings-updated']);
echo $copy->ID;
PHPEOF
  BRANCH_PHP="${BRANCH_PHP/__WPRISM_YDP_PROFILE__/$profile}"
  file="$repo/.tmp-yoast-duplicate-post-branch.php"
  printf '%s' "$BRANCH_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-yoast-duplicate-post-branch.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-yoast-duplicate-post-branch.php)
  fi
  rm -f "$file"
  require_fixture_ids out
}

SOURCE_INITIAL=$(observe_ydp conf1)
TARGET_INITIAL=$(observe_ydp conf2)
SOURCE_ORIGINAL=$(jq -r '.original.id' <<<"$SOURCE_INITIAL")
TARGET_ORIGINAL=$(jq -r '.original.id' <<<"$TARGET_INITIAL")
SOURCE_COPY=$(jq -r '.copy.id' <<<"$SOURCE_INITIAL")
TARGET_COPY=$(jq -r '.copy.id' <<<"$TARGET_INITIAL")
require_fixture_ids SOURCE_ORIGINAL TARGET_ORIGINAL SOURCE_COPY TARGET_COPY
[ "$SOURCE_ORIGINAL" != "$TARGET_ORIGINAL" ] && [ "$SOURCE_COPY" != "$TARGET_COPY" ] \
  || fail "Yoast Duplicate Post source/target post identities accidentally matched"
printf '%s\n' "$TARGET_INITIAL" | jq -e --argjson original "$TARGET_ORIGINAL" --argjson copy "$TARGET_COPY" '
  .option_count == 28 and .version == "4.7" and
  .copy.original_id == $original and .copy.original_via_api == $original and
  .copy.status == "draft" and .copy.menu_order == 24 and
  (.copy.title | contains("WPrism Duplicate Original 東京 🚀")) and
  (.copy.content_hash == .original.content_hash) and
  .copy.categories == ["wprism-duplicate-category"] and .copy.tags == ["wprism-duplicate-tag"] and
  .roles.administrator == true and .roles.wprism_reviewer == true and
  .roles.editor == false and .roles.subscriber == false and
  (.clone_link | contains("duplicate_post")) and
  (.row_actions | index("clone") != null) and (.row_actions | index("edit_as_new_draft") != null) and
  .prefix_html_present == true and .roles_html_reviewer == true and .metabox_original_marker == true and
  (.post_states | index("duplicate_post_original_item") != null) and
  (.copy.runtime_is_rewrite | tonumber) == 1 and .copy.runtime_creation == "not-a-date-東京-🚀" and
  (.original.runtime_copy | tonumber) == $copy and .original.runtime_republished == "999999999999999999999999" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Yoast Duplicate Post target did not converge through native clone/reference/UI/role behavior: $TARGET_INITIAL"
[ "$(jq -r '.option_hash' <<<"$SOURCE_INITIAL")" = "$(jq -r '.option_hash' <<<"$TARGET_INITIAL")" ] \
  || fail "Yoast Duplicate Post authored settings hashes differ after apply"
pass "large divergent IDs rewrite _dp_original; all settings, clone semantics, native links/UI, role caps, and hostile target runtime residue converge"

# Deactivation and ordinary uninstall retain every authored option/post/meta and
# plugin-owned capability. Missing code refuses; exact reinstall reuses residue.
BASE_OPTION_HASH=$(jq -r '.option_hash' <<<"$TARGET_INITIAL")
BASE_CONTENT_HASH=$(jq -r '.copy.content_hash' <<<"$TARGET_INITIAL")
wp_conf2 plugin deactivate duplicate-post >/dev/null
[ "$(wp_conf2 option get duplicate_post_title_prefix)" = "$(jq -r '.prefix' <<<"$TARGET_INITIAL")" ] \
  && [ "$(wp_conf2 post meta get "$TARGET_COPY" _dp_original)" = "$TARGET_ORIGINAL" ] \
  || fail "Yoast Duplicate Post deactivation changed authored option/reference state"
wp_conf2 wprism deploy --repo=/siterepo >/dev/null
wp_conf2 plugin is-active duplicate-post >/dev/null \
  || fail "WPrism deploy did not reactivate exact Yoast Duplicate Post code"
REACTIVATED=$(observe_ydp conf2)
[ "$(jq -r '.option_hash' <<<"$REACTIVATED")" = "$BASE_OPTION_HASH" ] \
  && [ "$(jq -r '.copy.content_hash' <<<"$REACTIVATED")" = "$BASE_CONTENT_HASH" ] \
  || fail "Yoast Duplicate Post deactivate/reactivate moved authored state"

wp_conf2 plugin uninstall duplicate-post --deactivate >/dev/null
if wp_conf2 plugin is-installed duplicate-post >/dev/null 2>&1; then
  fail "Yoast Duplicate Post ordinary uninstall left plugin code installed"
fi
[ "$(wp_conf2 option get duplicate_post_title_prefix)" = "$(jq -r '.prefix' <<<"$TARGET_INITIAL")" ] \
  && [ "$(wp_conf2 post meta get "$TARGET_COPY" _dp_original)" = "$TARGET_ORIGINAL" ] \
  && [ "$(wp_conf2 eval 'echo get_role("administrator")->has_cap("copy_posts") ? "yes" : "no";')" = yes ] \
  || fail "Yoast Duplicate Post ordinary uninstall did not retain settings/reference/capability residue"
MISSING_RC=0
MISSING_OUT=$(wp_conf2 wprism deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_wprism_answered "Yoast Duplicate Post deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Yoast Duplicate Post code did not refuse: $MISSING_OUT"
YDP_SHA=8cbeebf7c2ee982d2ae33833088ba8ac2391cc316a4dd71800a07f5fa128b418
YDP_ARTIFACT="/artifacts-cache/plugin-duplicate-post-4.7-${YDP_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$YDP_ARTIFACT');")" = "$YDP_SHA" ] \
  || fail "Yoast Duplicate Post cached reinstall artifact digest moved"
wp_conf2 plugin install "$YDP_ARTIFACT" --force >/dev/null
wp_conf2 wprism deploy --repo=/siterepo >/dev/null
REINSTALLED=$(observe_ydp conf2)
[ "$(jq -r '.option_hash' <<<"$REINSTALLED")" = "$BASE_OPTION_HASH" ] \
  && [ "$(jq -r '.copy.original_id' <<<"$REINSTALLED")" = "$TARGET_ORIGINAL" ] \
  || fail "Yoast Duplicate Post exact reinstall did not reuse retained authored state"
pass "deactivate/reactivate, ordinary uninstall residue, absent-code refusal, and digest-bound reinstall preserve native state"

# Competing repository/target edits must refuse atomically, then converge only
# under explicit overwrite. The role provider receipt is count/hash-only.
set_ydp_branch conf1 repository
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: Yoast Duplicate Post repository branch intent'
git -C "$CONF_REPO1" push -q origin main
set_ydp_branch conf2 target
git -C "$CONF_REPO2" pull -q origin main
CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Yoast Duplicate Post competing plan" json "$CONFLICT_PLAN"
jq -e '(.conflict | length) >= 2' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Yoast Duplicate Post competing settings/post edits did not produce conflicts: $CONFLICT_PLAN"
CONFLICT_BEFORE=$(observe_ydp conf2)
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered "Yoast Duplicate Post unforced conflict apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "Yoast Duplicate Post unforced conflict did not refuse: $CONFLICT_OUT"
CONFLICT_AFTER=$(observe_ydp conf2)
[ "$CONFLICT_BEFORE" = "$CONFLICT_AFTER" ] \
  || fail "Yoast Duplicate Post unforced conflict partially mutated target state"
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Yoast Duplicate Post forced conflict apply" json "$FORCED"
jq -e '
  .canary == "clean" and (.warnings | any(contains("FORCED conflict"))) and
  (.actions | any(
    .source == "provider:yoast-duplicate-post-role-capabilities/reconcile_role_capabilities" and
    .provider_version == "1.0.0" and .verified == true and
    .after.desired_role_count == 2 and .after.capability_role_count == 2 and
    .after.desired_roles_hash == .after.capability_roles_hash and
    (.after.desired_roles_hash | test("^[a-f0-9]{64}$"))
  ))
' <<<"$FORCED" >/dev/null || fail "Yoast Duplicate Post forced apply lacked its verified role-provider receipt: $FORCED"
CONVERGED=$(observe_ydp conf2)
printf '%s\n' "$CONVERGED" | jq -e '
  .prefix == "Repository prefix 東京 🚀" and .suffix == "Repository suffix | reviewed" and
  .copy.excerpt == "Repository competing excerpt 東京 🚀" and
  .roles.administrator == true and .roles.editor == true and
  .roles.wprism_reviewer == false and .roles.subscriber == false and
  .neighbor == "target-only-neighbor" and .copy.runtime_creation == "not-a-date-東京-🚀"
' >/dev/null || fail "Yoast Duplicate Post forced conflict did not converge/preserve runtime state: $CONVERGED"
ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Yoast Duplicate Post zero plan" json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "Yoast Duplicate Post retry retained repository work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Yoast Duplicate Post zero apply" json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Yoast Duplicate Post zero apply fired a provider or dirtied the canary: $ZERO_APPLY"
pass "competing options/post edits refuse atomically, explicit force converges with a secret-safe provider receipt, and retry is idempotent"

# A source-selected role missing on the target fails after selection, leaves a
# recoverable apply, and succeeds only after the environment prerequisite is
# supplied. This is the real provider failure/retry path.
wp_conf1 eval '
  wp_set_current_user(1);
  if (!get_role("wprism_source_only")) add_role("wprism_source_only", "WPrism Source Only", ["read"=>true,"edit_posts"=>true]);
  update_option("duplicate_post_roles", ["administrator","wprism_source_only"]);
  $_GET["settings-updated"]="true";
  $p=new Yoast\WP\Duplicate_Post\Admin\Options_Page(new Yoast\WP\Duplicate_Post\Admin\Options(),new Yoast\WP\Duplicate_Post\Admin\Options_Form_Generator(new Yoast\WP\Duplicate_Post\Admin\Options_Inputs()),new Yoast\WP\Duplicate_Post\UI\Asset_Manager());
  $p->register_capabilities(); unset($_GET["settings-updated"]);
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: select a source-only Duplicate Post role'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
wp_conf2 eval 'if (get_role("wprism_source_only")) remove_role("wprism_source_only");' >/dev/null
FAILURE_RC=0
FAILURE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAILURE_RC=$?
require_wprism_answered "Yoast Duplicate Post missing-role provider failure" human "$FAILURE_OUT"
[ "$FAILURE_RC" -ne 0 ] \
  && grep -q "required manifest action 'provider:yoast-duplicate-post-role-capabilities/reconcile_role_capabilities' failed" <<<"$FAILURE_OUT" \
  && ! grep -q 'wprism_source_only' <<<"$FAILURE_OUT" \
  || fail "Yoast Duplicate Post missing target role did not fail through the redacted provider boundary: $FAILURE_OUT"
PRE_RETRY_CAPS=$(wp_conf2 eval '
  $out=[];
  foreach (["administrator","wprism_source_only","editor"] as $name) {
    $role=get_role($name); $out[$name]=$role ? $role->has_cap("copy_posts") : null;
  }
  echo wp_json_encode($out);
')
require_observed_nonempty "Yoast Duplicate Post failed-provider capability state" "$PRE_RETRY_CAPS"
jq -e '.administrator == true and .wprism_source_only == null and .editor == true' <<<"$PRE_RETRY_CAPS" >/dev/null \
  || fail "Yoast Duplicate Post missing-role refusal partially mutated role capabilities: $PRE_RETRY_CAPS"
wp_conf2 eval 'add_role("wprism_source_only", "WPrism Source Only", ["read"=>true,"edit_posts"=>true]);' >/dev/null
RETRY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Yoast Duplicate Post provider retry" json "$RETRY"
jq -e '
  .canary == "clean" and (.actions | any(
    .source == "provider:yoast-duplicate-post-role-capabilities/reconcile_role_capabilities" and
    .verified == true and .after.desired_role_count == 2 and
    .after.desired_roles_hash == .after.capability_roles_hash
  ))
' <<<"$RETRY" >/dev/null || fail "Yoast Duplicate Post provider retry lacked verified recovery evidence: $RETRY"
RECOVERED=$(observe_ydp conf2)
printf '%s\n' "$RECOVERED" | jq -e '
  .roles.administrator == true and .roles.wprism_source_only == true and
  .roles.wprism_reviewer == false and .roles.editor == false and .roles.subscriber == false and
  .copy.runtime_creation == "not-a-date-東京-🚀" and .neighbor == "target-only-neighbor"
' >/dev/null || fail "Yoast Duplicate Post retry did not converge roles while preserving runtime state: $RECOVERED"
pass "missing-role failure is loud and recoverable; retry replays the verified provider without weakening permissions"

# Generic authored-option deletion is supported, but must carry explicit
# destructive authorization. Unrelated settings and target-only neighbors stay.
wp_conf1 eval 'delete_option("duplicate_post_show_notice");' >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: delete Duplicate Post welcome-notice setting'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
DELETE_OPTION_RC=0
DELETE_OPTION_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_OPTION_RC=$?
require_wprism_answered "Yoast Duplicate Post option deletion without authorization" human "$DELETE_OPTION_OUT"
[ "$DELETE_OPTION_RC" -ne 0 ] && grep -q 'authored option deletion intent requires --with-deletes' <<<"$DELETE_OPTION_OUT" \
  || fail "Yoast Duplicate Post option deletion did not require --with-deletes: $DELETE_OPTION_OUT"
host_wprism conf2 promote --with-deletes --default-author=admin --format=json >/dev/null
if wp_conf2 option get duplicate_post_show_notice >/dev/null 2>&1; then
  fail "authorized Yoast Duplicate Post option deletion left the row present"
fi
[ "$(wp_conf2 option get yoast_duplicate_post_target_neighbor)" = target-only-neighbor ] \
  || fail "authorized option deletion changed the undeclared target neighbor"
pass "authored option deletion refuses without authorization, deletes exactly one row, and preserves siblings/neighbor state"

# Remove the durable original link through the plugin's own REST callback,
# then prove hook-free apply removes only authored provenance while preserving
# target-owned Rewrite & Republish bytes.
REST_RESULT=$(wp_conf1 eval '
  wp_set_current_user(1);
  $copy=get_page_by_path("wprism-duplicate-copy", OBJECT, "post");
  $request=new WP_REST_Request("DELETE", "/yoast/v1/duplicate-post/original/".$copy->ID);
  $request->set_param("post_id", $copy->ID);
  $handler=new Yoast\WP\Duplicate_Post\Handlers\Rest_API_Handler(new Yoast\WP\Duplicate_Post\Permissions_Helper());
  $allowed=$handler->can_remove_original($request);
  if (true !== $allowed) throw new RuntimeException("REST permission refused");
  $response=$handler->remove_original($request);
  if (is_wp_error($response) || 200 !== $response->get_status()) throw new RuntimeException("REST removal failed");
  echo false === get_post_meta($copy->ID, "_dp_original", true) ? "absent" : (get_post_meta($copy->ID, "_dp_original", true) === "" ? "absent" : "present");
')
[ "$REST_RESULT" = absent ] || fail "Yoast Duplicate Post REST API did not remove _dp_original"
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: remove Duplicate Post original provenance through REST'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json >/dev/null
AFTER_REF_DELETE=$(observe_ydp conf2)
printf '%s\n' "$AFTER_REF_DELETE" | jq -e '
  .copy.original_id == 0 and .copy.original_via_api == 0 and
  (.copy.runtime_is_rewrite | tonumber) == 1 and .copy.runtime_creation == "not-a-date-東京-🚀" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "authored original-reference removal disturbed runtime workflow state: $AFTER_REF_DELETE"
pass "plugin-native REST provenance removal converges without delete authorization and preserves target runtime workflow bytes"

# Deleting the duplicated post itself is ordinary core entity deletion: it
# requires --with-deletes, but this capsule deliberately withholds the signed
# automatic rollback authority needed to remove it.
wp_conf1 eval '$copy=get_page_by_path("wprism-duplicate-copy", OBJECT, "post"); if (!$copy || !wp_delete_post($copy->ID, true)) throw new RuntimeException("copy delete failed");' >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: delete the duplicated post entity'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
DELETE_POST_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Yoast Duplicate Post entity deletion plan" json "$DELETE_POST_PLAN"
jq -e '.delete | any(.type == "post")' <<<"$DELETE_POST_PLAN" >/dev/null \
  || fail "duplicated post deletion was not planned as a core post tombstone: $DELETE_POST_PLAN"
DELETE_POST_RC=0
DELETE_POST_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_POST_RC=$?
require_wprism_answered "Yoast Duplicate Post entity deletion without authorization" human "$DELETE_POST_OUT"
[ "$DELETE_POST_RC" -ne 0 ] \
  && grep -q 'planned deletions require --with-deletes (1)' <<<"$DELETE_POST_OUT" \
  && grep -q 'no target mutation attempted' <<<"$DELETE_POST_OUT" \
  && grep -qi -- '--with-deletes' <<<"$DELETE_POST_OUT" \
  || fail "duplicated post deletion no-op did not require --with-deletes: $DELETE_POST_OUT"
wp_conf2 post get "$TARGET_COPY" >/dev/null 2>&1 \
  || fail "unauthorized duplicated-post deletion no-op removed the target copy"
DELETE_POST_AUTH_RC=0
DELETE_POST_AUTH_OUT=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || DELETE_POST_AUTH_RC=$?
require_wprism_answered "Yoast Duplicate Post signed deletion refusal" human "$DELETE_POST_AUTH_OUT"
[ "$DELETE_POST_AUTH_RC" -ne 0 ] \
  && grep -q 'deletion_writer_exclusion_required' <<<"$DELETE_POST_AUTH_OUT" \
  || fail "duplicated-post deletion crossed the unsigned writer-exclusion boundary: $DELETE_POST_AUTH_OUT"
wp_conf2 post get "$TARGET_COPY" >/dev/null 2>&1 \
  || fail "duplicated-post deletion refusal removed the target copy"
[ "$(wp_conf2 post get "$TARGET_ORIGINAL" --field=post_name)" = wprism-duplicate-original ] \
  || fail "duplicated-post deletion refusal removed or changed the original"
[ "$(wp_conf2 post meta get "$TARGET_ORIGINAL" _dp_has_rewrite_republish_copy)" = "$TARGET_COPY" ] \
  || fail "deletion refusal disturbed target-sovereign Rewrite & Republish state"
pass "duplicated-post entity deletion remains explicitly unsupported without signed automatic rollback authority and preserves both posts"

# Credential-shaped authored settings must refuse atomically and redact the
# value. Restore the local probe afterward; canonical bytes never move.
FAKE_TOKEN='ghp_1234567890abcdefghij'
wp_conf1 option update duplicate_post_title_prefix "$FAKE_TOKEN" >/dev/null
SECRET_STATUS_BEFORE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
SECRET_RC=0
SECRET_OUT=$(wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-yoast-duplicate-post-secret 2>&1) || SECRET_RC=$?
require_wprism_answered "Yoast Duplicate Post credential-shaped setting capture" human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_TOKEN" <<<"$SECRET_OUT" \
  || fail "Yoast Duplicate Post secret guard did not refuse/redact the option: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$SECRET_STATUS_BEFORE" ] \
  || fail "Yoast Duplicate Post secret refusal partially published state"
rm -rf "$CONF_REPO1/.tmp-yoast-duplicate-post-secret"
wp_conf1 option update duplicate_post_title_prefix 'Repository prefix 東京 🚀' >/dev/null
pass "credential-shaped settings refuse atomically and public diagnostics redact the value"

wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-yoast-duplicate-post-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-yoast-duplicate-post-final" \
  || fail "Yoast Duplicate Post final deletion/recovery state did not recapture byte-identically"
rm -rf "$CONF_REPO2/.tmp-yoast-duplicate-post-final"
FINAL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Yoast Duplicate Post final zero plan" json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "Yoast Duplicate Post final state was not idempotent: $FINAL_PLAN"
pass "final exact-artifact state recaptures byte-identically with malformed runtime residue excluded and a zero plan"
