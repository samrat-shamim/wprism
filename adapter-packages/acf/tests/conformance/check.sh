#!/usr/bin/env bash
# Exact-artifact ACF acceptance beyond the harness's deterministic capture,
# clean apply, and byte-identical recapture. This verifies ACF's own runtime
# APIs against divergent local identities and then exercises loud boundaries.
set -euo pipefail

observe_acf() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid ACF observation side: $side" ;;
  esac
  file="$repo/.tmp-acf-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
$post = get_page_by_path('conf-acf-content', OBJECT, 'post');
$editor = get_user_by('login', 'acf-editor');
$term = get_term_by('slug', 'conf-cat-one', 'category');
$menu = wp_get_nav_menu_object('Conformance ACF Menu');
$items = $menu ? wp_get_nav_menu_items($menu->term_id) : [];
$menuItem = $items ? $items[0] : null;
$field = acf_get_field('field_duo_hero');
$group = acf_get_field_group('group_duo_post');
if (!$post || !$editor || !$term || !$menuItem || !$field || !$group) {
    throw new RuntimeException('ACF runtime fixture is incomplete');
}
$schemaPost = get_post((int) $field['ID']);
$schema = $schemaPost ? unserialize((string) $schemaPost->post_content, ['allowed_classes' => false]) : null;
if (!is_array($schema)) {
    throw new RuntimeException('ACF field schema body is not serialized plain data');
}
$idsToTitles = static function ($ids): array {
    $titles = [];
    foreach ((array) $ids as $id) {
        $p = get_post((int) $id);
        $titles[] = $p ? (string) $p->post_title : '';
    }
    sort($titles, SORT_STRING);
    return $titles;
};
$idsToLogins = static function ($ids): array {
    $logins = [];
    foreach ((array) $ids as $id) {
        $u = get_user_by('id', (int) $id);
        $logins[] = $u ? (string) $u->user_login : '';
    }
    sort($logins, SORT_STRING);
    return $logins;
};
$idsToTerms = static function ($ids): array {
    $names = [];
    foreach ((array) $ids as $id) {
        $t = get_term((int) $id, 'category');
        $names[] = $t && !is_wp_error($t) ? (string) $t->name : '';
    }
    sort($names, SORT_STRING);
    return $names;
};
$hero = (int) get_field('field_duo_hero', $post->ID);
$icon = get_field('field_duo_icon', $post->ID);
$iconId = is_array($icon) && is_array($icon['value'] ?? null)
    ? (int) ($icon['value']['ID'] ?? 0)
    : (int) ($icon['value'] ?? 0);
$termImage = (int) get_field('field_duo_term_image', 'category_' . (int) $term->term_id);
$userImage = (int) get_field('field_duo_user_image', 'user_' . (int) $editor->ID);
$optionImage = (int) get_field('field_duo_option_image', 'option');
$menuImage = (int) get_field('field_duo_menu_image', (int) $menuItem->ID);
$link = get_field('field_duo_link', $post->ID);
$runtimeHealth = get_option('acf_site_health');
if (is_string($runtimeHealth)) {
    $decodedHealth = json_decode($runtimeHealth, true);
    if (is_array($decodedHealth)) {
        $runtimeHealth = $decodedHealth;
    }
}
echo wp_json_encode([
    'content_id' => (int) $post->ID,
    'editor_id' => (int) $editor->ID,
    'feature_title' => $idsToTitles([get_field('field_duo_feature', $post->ID)])[0],
    'features' => $idsToTitles(get_field('field_duo_features', $post->ID)),
    'field_id' => (int) $field['ID'],
    'group_id' => (int) $group['ID'],
    'hero_id' => $hero,
    'hero_title' => (string) get_the_title($hero),
    'home' => home_url('/'),
    'icon_id' => $iconId,
    'icon_title' => (string) get_the_title($iconId),
    'link' => $link,
    'menu_image_id' => $menuImage,
    'menu_image_title' => (string) get_the_title($menuImage),
    'option_image_id' => $optionImage,
    'option_image_title' => (string) get_the_title($optionImage),
    'option_note' => get_field('field_duo_option_note', 'option'),
    'owner' => $idsToLogins([get_field('field_duo_owner', $post->ID)])[0],
    'owners' => $idsToLogins(get_field('field_duo_owners', $post->ID)),
    'page' => get_field('field_duo_page', $post->ID),
    'pages' => get_field('field_duo_pages', $post->ID),
    'primary_category' => $idsToTerms([get_field('field_duo_cat', $post->ID)])[0],
    'related' => $idsToTitles(get_field('field_duo_related', $post->ID)),
    'schema_home' => str_contains((string) ($schema['instructions'] ?? ''), home_url('/')),
    'schema_length' => strlen((string) ($schema['instructions'] ?? '')),
    'schema_unicode' => str_contains((string) ($schema['instructions'] ?? ''), '東京 🚀 | delimiter ::'),
    'term_id' => (int) $term->term_id,
    'term_image_id' => $termImage,
    'term_image_title' => (string) get_the_title($termImage),
    'term_note' => get_field('field_duo_term_note', 'category_' . (int) $term->term_id),
    'user_image_id' => $userImage,
    'user_image_title' => (string) get_the_title($userImage),
    'user_note' => get_field('field_duo_user_note', 'user_' . (int) $editor->ID),
    'runtime_first' => get_option('acf_first_activated_version'),
    'runtime_health' => $runtimeHealth,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-acf-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-acf-observe.php)
  fi
  rm -f "$file"
  if [ "$side" = conf1 ]; then
    require_observed_nonempty "conf1 ACF runtime observation" "$out"
  else
    require_observed_nonempty "conf2 ACF runtime observation" "$out"
  fi
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

SOURCE=$(observe_acf conf1)
TARGET=$(observe_acf conf2)
TARGET_PREMISE=$(cat "${CONF_REPO2:-siterepo/conf2}/.tmp-acf-target.json")
printf '%s\n' "$TARGET" | jq -e '
  .home as $home |
  .feature_title == "Conformance Related Target One" and
  .features == ["Conformance Related Target One", "Conformance Related Target Two"] and
  .hero_title == "Conformance ACF Hero" and
  .icon_title == "Conformance ACF Secondary" and
  .menu_image_title == "Conformance ACF Hero" and
  .option_image_title == "Conformance ACF Secondary" and
  .owner == "admin" and .owners == ["acf-editor", "admin"] and
  .primary_category == "Conformance Category Three" and
  .related == ["Conformance Related Target One", "Conformance Related Target Two"] and
  .term_image_title == "Conformance ACF Secondary" and
  .user_image_title == "Conformance ACF Hero" and
  .schema_home == true and .schema_unicode == true and .schema_length > 40000 and
  (.link.title == "Portable source link 東京 🚀") and (.link.url | contains($home)) and
  (.page | contains($home + "conf-linked-page-one/")) and
  (.pages | length) == 2 and all(.pages[]; contains($home)) and
  (.term_note | contains($home)) and (.term_note | contains("東京 🚀")) and
  (.user_note | contains($home)) and (.user_note | contains("東京 🚀")) and
  (.option_note | contains($home)) and (.option_note | contains("東京 🚀")) and
  .runtime_first == "target-runtime-first-activation" and
  .runtime_health.nonce_like_neighbor == "target-runtime-preserved"
' >/dev/null || fail "ACF target APIs, nested URLs, long schema, or runtime sovereignty did not converge: $TARGET"

SOURCE_CONTENT=$(jq -r '.content_id' <<<"$SOURCE")
TARGET_CONTENT=$(jq -r '.content_id' <<<"$TARGET")
SOURCE_EDITOR=$(jq -r '.editor_id' <<<"$SOURCE")
TARGET_EDITOR=$(jq -r '.editor_id' <<<"$TARGET")
SOURCE_FIELD=$(jq -r '.field_id' <<<"$SOURCE")
TARGET_FIELD=$(jq -r '.field_id' <<<"$TARGET")
require_fixture_ids SOURCE_CONTENT TARGET_CONTENT SOURCE_EDITOR TARGET_EDITOR SOURCE_FIELD TARGET_FIELD
[ "$SOURCE_CONTENT" != "$TARGET_CONTENT" ] \
  && [ "$SOURCE_EDITOR" != "$TARGET_EDITOR" ] \
  && [ "$SOURCE_FIELD" != "$TARGET_FIELD" ] \
  || fail "ACF divergent-identity premise did not hold: source=$SOURCE target=$TARGET"
jq -e --argjson observed "$TARGET" '
  .content == $observed.content_id and .editor == $observed.editor_id and
  .group == $observed.group_id and .hero_field == $observed.field_id and
  .terms.one == $observed.term_id
' <<<"$TARGET_PREMISE" >/dev/null \
  || fail "ACF apply replaced instead of adopting the hostile target identities: premise=$TARGET_PREMISE observed=$TARGET"
pass "ACF schemas and values converge through native APIs across divergent post/term/user/menu/option identities, long UTF-8 data, nested URLs, and hostile target state"

ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF zero-change plan" json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "ACF retry retained work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF zero-change apply" json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "ACF no-op retry was not clean/idempotent: $ZERO_APPLY"
pass "ACF zero-change plan and apply are idempotent"

# The owning user-meta login is not a portable entity and cannot fall back to
# the default author. A missing exact login must block the whole plan before
# any otherwise-valid ACF post/term/option work could run.
wp_conf2 eval '
  $user=get_user_by("login", "acf-editor");
  global $wpdb;
  if (1 !== $wpdb->update($wpdb->users, ["user_login"=>"acf-editor-missing"], ["ID"=>(int)$user->ID])) {
    throw new RuntimeException($wpdb->last_error ?: "exact user rename did not change one row");
  }
  clean_user_cache((int)$user->ID);
' >/dev/null
MISSING_USER_RC=0
MISSING_USER_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || MISSING_USER_RC=$?
require_duo_answered "ACF missing exact user-meta owner" human "$MISSING_USER_OUT"
[ "$MISSING_USER_RC" -ne 0 ] \
  && grep -q "exact login 'acf-editor'" <<<"$MISSING_USER_OUT" \
  && grep -Eqi 'missing|required|refused' <<<"$MISSING_USER_OUT" \
  || fail "ACF missing exact user-meta owner did not refuse preflight: $MISSING_USER_OUT"
wp_conf2 eval '
  $user=get_user_by("login", "acf-editor-missing");
  global $wpdb;
  if (1 !== $wpdb->update($wpdb->users, ["user_login"=>"acf-editor"], ["ID"=>(int)$user->ID])) {
    throw new RuntimeException($wpdb->last_error ?: "exact user restore did not change one row");
  }
  clean_user_cache((int)$user->ID);
' >/dev/null
MISSING_USER_RECOVERY=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF plan after exact user restoration" json "$MISSING_USER_RECOVERY"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict,.missing_user] | map(length) | add) == 0' <<<"$MISSING_USER_RECOVERY" >/dev/null \
  || fail "ACF exact user restoration did not return to a clean plan: $MISSING_USER_RECOVERY"
pass "a missing exact-login ACF user-meta owner blocks preflight and restoring that login recovers cleanly"

# A local PHP/JSON schema with the same key would win ACF's runtime registry
# over the repository-owned DB post. Refuse that ambiguity before mutation.
LOCAL_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-acf-local-collision.php"
cat > "$LOCAL_FILE" <<'PHPEOF'
<?php
add_action('acf/init', static function () {
    acf_add_local_field_group([
        'key' => 'group_duo_post', 'title' => 'Target local collision',
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
    ]);
    acf_add_local_field([
        'key' => 'field_duo_hero', 'label' => 'Target local collision',
        'name' => 'duo_hero', 'type' => 'true_false', 'parent' => 'group_duo_post',
    ]);
}, 1);
PHPEOF
$COMPOSE exec -T --user root wp2 install -D -m 0644 \
  /siterepo/.tmp-acf-local-collision.php \
  /var/www/html/wp-content/mu-plugins/duo-acf-local-collision.php
rm -f "$LOCAL_FILE"
LOCAL_RC=0
LOCAL_OUT=$(wp_conf2 duo plan --repo=/siterepo 2>&1) || LOCAL_RC=$?
require_duo_answered "ACF local-schema collision plan" human "$LOCAL_OUT"
[ "$LOCAL_RC" -ne 0 ] \
  && grep -q 'acf_local_schema_collision' <<<"$LOCAL_OUT" \
  && grep -q 'field_duo_hero' <<<"$LOCAL_OUT" \
  && grep -q 'group_duo_post' <<<"$LOCAL_OUT" \
  || fail "ACF local schema did not refuse with both exact collision diagnostics: $LOCAL_OUT"
$COMPOSE exec -T --user root wp2 rm -f /var/www/html/wp-content/mu-plugins/duo-acf-local-collision.php
RECOVERED_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF plan after local-schema removal" json "$RECOVERED_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$RECOVERED_PLAN" >/dev/null \
  || fail "ACF did not recover after the local schema was removed: $RECOVERED_PLAN"
pass "local PHP/JSON schema precedence refuses loudly and removal returns to a clean plan"

# ACF 6.1+ free can author CPT/taxonomy definitions in its own UI. Those
# post types are outside this adapter and must remain an atomic scope refusal.
UI_OUT=$(wp_conf2 eval '
  $row=acf_update_post_type([
    "key"=>"post_type_duo_scope_probe", "title"=>"Duo Scope Probe",
    "post_type"=>"duo_scope_probe", "active"=>true,
  ]);
  if (empty($row["ID"])) { throw new RuntimeException("ACF UI CPT was not created"); }
  echo (int)$row["ID"];
')
require_fixture_ids UI_OUT
SCOPE_RC=0
SCOPE_OUT=$(wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-acf-ui-scope 2>&1) || SCOPE_RC=$?
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-acf-ui-scope"
require_duo_answered "ACF UI CPT capture" human "$SCOPE_OUT"
[ "$SCOPE_RC" -ne 0 ] \
  && grep -Eq 'incomplete_policy_scope|policy_scope_gap|outside policy scope' <<<"$SCOPE_OUT" \
  && grep -q 'acf-post-type' <<<"$SCOPE_OUT" \
  || fail "ACF UI-created CPT did not refuse at the exact scope boundary: $SCOPE_OUT"
wp_conf2 eval "if (!acf_delete_post_type($UI_OUT)) { throw new RuntimeException('ACF UI CPT cleanup failed'); }" >/dev/null
pass "ACF free UI-created post types stay explicitly unsupported and refuse capture atomically"

# Strict serialized schema bytes reject trailing payload rather than letting
# PHP unserialize() silently accept a valid prefix.
BODY_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-acf-schema-body"
wp_conf2 eval '
  $field=acf_get_field("field_duo_hero"); $post=get_post((int)$field["ID"]);
  file_put_contents("/siterepo/.tmp-acf-schema-body", $post->post_content);
  global $wpdb;
  $wpdb->update($wpdb->posts, ["post_content"=>$post->post_content . "trailing-payload"], ["ID"=>(int)$post->ID]);
' >/dev/null
MALFORMED_RC=0
MALFORMED_OUT=$(wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-acf-malformed 2>&1) || MALFORMED_RC=$?
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-acf-malformed"
require_duo_answered "ACF malformed serialized schema capture" human "$MALFORMED_OUT"
[ "$MALFORMED_RC" -ne 0 ] \
  && grep -Eqi 'serialized|trailing|plain data|unserialize' <<<"$MALFORMED_OUT" \
  || fail "ACF trailing serialized payload was not refused: $MALFORMED_OUT"
wp_conf2 eval '
  $field=acf_get_field("field_duo_hero");
  global $wpdb;
  $body=file_get_contents("/siterepo/.tmp-acf-schema-body");
  $wpdb->update($wpdb->posts, ["post_content"=>$body], ["ID"=>(int)$field["ID"]]);
  clean_post_cache((int)$field["ID"]); acf_flush_field_cache($field);
' >/dev/null
rm -f "$BODY_FILE"
pass "malformed serialized ACF schema refuses before publication and exact bytes are recoverable"

# A credential-shaped schema member must never reach canonical state or echo
# its value in a refusal. The field is outside the published revision, so its
# cleanup cannot disturb repository identity.
FAKE_TOKEN='ghp_1234567890abcdefghij'
SECRET_ID=$(wp_conf1 eval '
  foreach (get_posts(["post_type"=>"acf-field", "name"=>"field_duo_secret_probe", "post_status"=>"any", "posts_per_page"=>-1, "fields"=>"ids"]) as $prior) {
    acf_delete_field((int)$prior);
  }
  $group=acf_get_field_group("group_duo_post");
  $field=acf_update_field([
    "key"=>"field_duo_secret_probe", "label"=>"Secret Probe",
    "name"=>"duo_secret_probe", "type"=>"text", "parent"=>(int)$group["ID"],
    "api_token"=>"ghp_1234567890abcdefghij",
  ]);
  if (empty($field["ID"])) { throw new RuntimeException("secret probe field was not created"); }
  echo (int)$field["ID"];
')
require_fixture_ids SECRET_ID
SECRET_STATUS_BEFORE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-acf-secret 2>&1) || SECRET_RC=$?
require_duo_answered "ACF credential-shaped schema capture" human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] \
  && grep -Eq 'secret guard tripped|contains a github token' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_TOKEN" <<<"$SECRET_OUT" \
  || fail "ACF credential-shaped schema did not refuse with redaction: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$SECRET_STATUS_BEFORE" ] \
  || fail "ACF secret refusal partially published canonical state"
rm -rf "$CONF_REPO1/.tmp-acf-secret"
wp_conf1 eval '
  $deleted=0;
  foreach (get_posts(["post_type"=>"acf-field", "name"=>"field_duo_secret_probe", "post_status"=>"any", "posts_per_page"=>-1, "fields"=>"ids"]) as $id) {
    $deleted += acf_delete_field((int)$id) ? 1 : 0;
  }
  if ($deleted < 1) { throw new RuntimeException("secret probe cleanup failed"); }
' >/dev/null
pass "credential-shaped ACF schema refuses atomically and the public diagnostic redacts the value"

# Simulate disappearance without invoking plugin cascades: remove only the
# field's wp_posts row, retain its meta/identity, prove capture refuses the
# unsupported post:acf-field selector without publishing a tombstone, then
# restore the exact row and identity bytes.
DELETE_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-acf-delete-row.json"
DELETE_ID=$(wp_conf1 eval '
  $field=acf_get_field("field_duo_delete_probe");
  if (!$field) { throw new RuntimeException("delete probe field is absent"); }
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d", (int)$field["ID"]), ARRAY_A);
  file_put_contents("/siterepo/.tmp-acf-delete-row.json", wp_json_encode($row));
  if (1 !== $wpdb->delete($wpdb->posts, ["ID"=>(int)$field["ID"]])) { throw new RuntimeException($wpdb->last_error); }
  clean_post_cache((int)$field["ID"]); acf_flush_field_cache($field);
  echo (int)$field["ID"];
')
require_fixture_ids DELETE_ID
DELETE_STATUS_BEFORE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
DELETE_RC=0
DELETE_OUT=$(wp_conf1 duo capture --repo=/siterepo --format=json) || DELETE_RC=$?
require_duo_answered "ACF unsupported field deletion capture" json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "post:acf-field")
' <<<"$DELETE_OUT" >/dev/null \
  || fail "ACF field deletion did not refuse at exact post:acf-field: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$DELETE_STATUS_BEFORE" ] \
  || fail "ACF deletion refusal partially published a tombstone"
wp_conf1 eval '
  global $wpdb;
  $row=json_decode(file_get_contents("/siterepo/.tmp-acf-delete-row.json"), true, 512, JSON_THROW_ON_ERROR);
  if (false === $wpdb->insert($wpdb->posts, $row)) { throw new RuntimeException($wpdb->last_error); }
  clean_post_cache((int)$row["ID"]); acf_get_store("fields")->reset();
' >/dev/null
rm -f "$DELETE_BACKUP"
pass "ACF entity deletion refuses at the exact selector and publishes no partial tombstone"

# Publish a multi-surface source change, then make the final user-meta write
# fail inside MySQL. The authored transaction must roll back earlier post,
# term, option, and user-meta mutations; removing the injected fault must make
# the identical durable repository intent retry cleanly.
wp_conf1 eval '
  $post=get_page_by_path("conf-acf-content", OBJECT, "post");
  $editor=get_user_by("login", "acf-editor");
  $term=get_term_by("slug", "conf-cat-one", "category");
  update_field("field_duo_link", [
    "title"=>"Repository transaction intent 東京 🚀",
    "url"=>home_url("/conf-linked-page-two/?from=acf-transaction"),
    "target"=>"_self",
  ], $post->ID);
  update_field("field_duo_term_note", "Repository term transaction " . home_url("/"), "category_" . $term->term_id);
  update_field("field_duo_user_note", "Repository user transaction " . home_url("/"), "user_" . $editor->ID);
  update_field("field_duo_option_note", "Repository option transaction " . home_url("/"), "option");
' >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: ACF transaction recovery intent'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

TARGET_HASH_BEFORE=$(wp_conf2 eval '
  global $wpdb;
  $rows=[
    "posts"=>$wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"acf-field\",\"acf-field-group\",\"post\",\"page\",\"attachment\",\"nav_menu_item\") ORDER BY ID", ARRAY_A),
    "postmeta"=>$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE meta_key LIKE \"duo\\_%\" OR meta_key LIKE \"\\_duo\\_%\" ORDER BY meta_id", ARRAY_A),
    "termmeta"=>$wpdb->get_results("SELECT * FROM {$wpdb->termmeta} WHERE meta_key LIKE \"duo\\_%\" OR meta_key LIKE \"\\_duo\\_%\" ORDER BY meta_id", ARRAY_A),
    "usermeta"=>$wpdb->get_results("SELECT * FROM {$wpdb->usermeta} WHERE meta_key LIKE \"duo\\_%\" OR meta_key LIKE \"\\_duo\\_%\" ORDER BY umeta_id", ARRAY_A),
    "options"=>$wpdb->get_results("SELECT * FROM {$wpdb->options} WHERE option_name LIKE \"options\\_duo\\_%\" OR option_name LIKE \"\\_options\\_duo\\_%\" ORDER BY option_id", ARRAY_A),
  ];
  echo hash("sha256", wp_json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
')
wp_conf2 db query '
  DROP TRIGGER IF EXISTS duo_acf_fail_user_meta;
  CREATE TRIGGER duo_acf_fail_user_meta BEFORE UPDATE ON wp_usermeta
  FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "duo injected ACF user-meta failure"
' >/dev/null
FAULT_RC=0
FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || FAULT_RC=$?
require_duo_answered "ACF injected transaction failure" human "$FAULT_OUT"
[ "$FAULT_RC" -ne 0 ] && grep -q 'duo injected ACF user-meta failure' <<<"$FAULT_OUT" \
  || fail "ACF injected database failure did not surface exactly: $FAULT_OUT"
TARGET_HASH_AFTER=$(wp_conf2 eval '
  global $wpdb;
  $rows=[
    "posts"=>$wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"acf-field\",\"acf-field-group\",\"post\",\"page\",\"attachment\",\"nav_menu_item\") ORDER BY ID", ARRAY_A),
    "postmeta"=>$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE meta_key LIKE \"duo\\_%\" OR meta_key LIKE \"\\_duo\\_%\" ORDER BY meta_id", ARRAY_A),
    "termmeta"=>$wpdb->get_results("SELECT * FROM {$wpdb->termmeta} WHERE meta_key LIKE \"duo\\_%\" OR meta_key LIKE \"\\_duo\\_%\" ORDER BY meta_id", ARRAY_A),
    "usermeta"=>$wpdb->get_results("SELECT * FROM {$wpdb->usermeta} WHERE meta_key LIKE \"duo\\_%\" OR meta_key LIKE \"\\_duo\\_%\" ORDER BY umeta_id", ARRAY_A),
    "options"=>$wpdb->get_results("SELECT * FROM {$wpdb->options} WHERE option_name LIKE \"options\\_duo\\_%\" OR option_name LIKE \"\\_options\\_duo\\_%\" ORDER BY option_id", ARRAY_A),
  ];
  echo hash("sha256", wp_json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
')
[ "$TARGET_HASH_AFTER" = "$TARGET_HASH_BEFORE" ] \
  || fail "ACF failed apply left a partial database mutation (before=$TARGET_HASH_BEFORE after=$TARGET_HASH_AFTER)"
wp_conf2 db query 'DROP TRIGGER duo_acf_fail_user_meta' >/dev/null
RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF retry after injected failure" json "$RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 4' <<<"$RETRY" >/dev/null \
  || fail "ACF retry did not consume the durable intent cleanly: $RETRY"
RETRIED_TARGET=$(observe_acf conf2)
printf '%s\n' "$RETRIED_TARGET" | jq -e '
  .home as $home |
  .link.title == "Repository transaction intent 東京 🚀" and
  (.link.url | contains($home + "conf-linked-page-two/")) and
  (.term_note | contains("Repository term transaction")) and
  (.user_note | contains("Repository user transaction")) and
  (.option_note | contains("Repository option transaction"))
' >/dev/null || fail "ACF retry did not converge through native APIs: $RETRIED_TARGET"
pass "an injected late ACF write failure rolls back every authored table and the exact retry converges"

# Two real wp-cli processes now race the same new repository intent. Exactly
# one may own the lock at a time; the loser may wait and observe no work or
# refuse with the named lock, but final state and the next plan must be clean.
wp_conf1 eval 'update_field("field_duo_option_note", "Concurrent ACF intent " . home_url("/"), "option");' >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: concurrent ACF apply intent'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
CONCURRENT_A="${CONF_REPO2:-siterepo/conf2}/.tmp-acf-concurrent-a.log"
CONCURRENT_B="${CONF_REPO2:-siterepo/conf2}/.tmp-acf-concurrent-b.log"
set +e
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 &
PID_A=$!
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 &
PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing ACF applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"
  eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" \
      || fail "successful competing ACF apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing ACF apply failed outside the named lock boundary: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT_VALUE=$(wp_conf2 eval 'echo get_field("field_duo_option_note", "option");')
[[ "$CONCURRENT_VALUE" == "Concurrent ACF intent "* ]] \
  || fail "competing ACF applies did not preserve repository intent: $CONCURRENT_VALUE"
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF plan after competing applies" json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "ACF competing applies left retained work: $CONCURRENT_PLAN"
pass "competing ACF applies serialize at the promotion lock and leave one clean, idempotent result"

# Ordinary deactivation and code removal must not turn data recovery into a
# silent fallback. Deploy reactivates installed exact code; absent code
# refuses; digest-bound reinstall plus apply restores plugin-visible state.
wp_conf2 plugin deactivate advanced-custom-fields >/dev/null
if wp_conf2 plugin is-active advanced-custom-fields >/dev/null 2>&1; then
  fail "ACF deactivation premise did not land"
fi
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF deploy after deactivation" json "$REACTIVATE"
wp_conf2 plugin is-active advanced-custom-fields >/dev/null \
  || fail "Duo deploy did not reactivate exact ACF code"
wp_conf2 plugin uninstall advanced-custom-fields --deactivate >/dev/null
if wp_conf2 plugin is-installed advanced-custom-fields >/dev/null 2>&1; then
  fail "ACF uninstall left plugin code installed"
fi
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered "ACF deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing ACF code did not refuse at compatibility: $MISSING_OUT"
ACF_SHA=f877a94871e55cc2f2931052c693705d376da12cb85c9761b6915c037f91cec2
ACF_ARTIFACT="/artifacts-cache/plugin-advanced-custom-fields-6.8.7-${ACF_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$ACF_ARTIFACT');")" = "$ACF_SHA" ] \
  || fail "cached ACF reinstall artifact digest moved"
wp_conf2 plugin install "$ACF_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get advanced-custom-fields --field=version)" = '6.8.7' ] \
  || fail "ACF exact reinstall reported the wrong version"
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF deploy after exact reinstall" json "$REINSTALL_DEPLOY"
REINSTALL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF apply after exact reinstall" json "$REINSTALL_APPLY"
jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$REINSTALL_APPLY" >/dev/null \
  || fail "ACF exact reinstall did not recover canonical state: $REINSTALL_APPLY"
REINSTALLED=$(observe_acf conf2)
printf '%s\n' "$REINSTALLED" | jq -e '
  .hero_title == "Conformance ACF Hero" and
  .user_image_title == "Conformance ACF Hero" and
  .option_image_title == "Conformance ACF Secondary" and
  (.option_note | startswith("Concurrent ACF intent "))
' >/dev/null || fail "ACF native state did not survive/recover after exact reinstall: $REINSTALLED"
pass "deactivate/reactivate, absent-code refusal, digest-bound reinstall, and canonical recovery preserve ACF native behavior"

# ACF option-page values are ordinary authored options even though entity
# deletes remain unsupported. Removing one value through ACF's API removes
# value+shadow as a pair; Duo must require --with-deletes, preserve sibling
# and runtime rows, and recapture the authorized result byte-identically.
wp_conf1 eval '
  if (!delete_field("field_duo_option_note", "option")) {
    throw new RuntimeException("ACF option value+shadow deletion did not land");
  }
' >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete one ACF option-page value'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
OPTION_DELETE_RC=0
OPTION_DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || OPTION_DELETE_RC=$?
require_duo_answered "ACF option deletion without authorization" human "$OPTION_DELETE_OUT"
[ "$OPTION_DELETE_RC" -ne 0 ] \
  && grep -q 'authored option deletion intent requires --with-deletes' <<<"$OPTION_DELETE_OUT" \
  || fail "ACF option deletion did not require explicit authorization: $OPTION_DELETE_OUT"
[ "$(wp_conf2 option get options_duo_option_note)" = "$CONCURRENT_VALUE" ] \
  || fail "unauthorized ACF option deletion partially mutated the target"
OPTION_DELETE=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "ACF authorized option deletion" json "$OPTION_DELETE"
if wp_conf2 option get options_duo_option_note >/dev/null 2>&1 \
  || wp_conf2 option get _options_duo_option_note >/dev/null 2>&1; then
  fail "authorized ACF option deletion left the value or shadow row present"
fi
[ "$(wp_conf2 eval 'echo get_field("field_duo_option_image", "option") ? "image-present" : "image-missing";')" = image-present ] \
  || fail "authorized ACF option deletion removed a sibling field value"
OPTION_DELETE_HEALTH=$(wp_conf2 option get acf_site_health --format=json)
require_observed_nonempty "ACF target runtime option after authored deletion" "$OPTION_DELETE_HEALTH"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-acf-option-delete >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-acf-option-delete" \
  || fail "authorized ACF option deletion did not recapture byte-identically"
rm -rf "$CONF_REPO2/.tmp-acf-option-delete"
pass "ACF option value+shadow deletion requires authorization, preserves siblings/runtime state, and recaptures identically"
