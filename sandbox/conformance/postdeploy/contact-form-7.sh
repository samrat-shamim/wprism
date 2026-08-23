#!/usr/bin/env bash
# Hostile CF7 target after deploy and before apply: native same-slug forms at
# divergent ids, stale authored properties, target-owned runtime/integration
# residue, and a reproduced foreign seven-byte hash collision.
set -euo pipefail

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-cf7-hostile.php"
cat > "$HOSTILE_FILE" <<'PHPEOF'
<?php
if (!class_exists('WPCF7_ContactForm')) {
    throw new RuntimeException('WPCF7_ContactForm not loaded on target');
}
$admin = get_user_by('login', 'admin');
wp_set_current_user((int) $admin->ID);
for ($i = 0; $i < 15; $i++) {
    $id = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'draft',
        'post_title' => "CF7 target identity spacer $i",
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    wp_delete_post((int) $id, true);
}

$create = static function (string $title, string $profile): WPCF7_ContactForm {
    $form = WPCF7_ContactForm::get_template(['title' => $title]);
    $mail = $form->prop('mail');
    $mail['recipient'] = "$profile-target@example.test";
    $mail['subject'] = "Hostile target $profile";
    $form->set_properties([
        'form' => "<p>Hostile target $profile form</p>[submit \"Target\"]",
        'mail' => $mail,
        'additional_settings' => 'demo_mode: on',
    ]);
    $id = $form->save();
    if (!$id) {
        throw new RuntimeException("target CF7 save failed for $title");
    }
    return WPCF7_ContactForm::get_instance((int) $id);
};

$main = $create('Conformance Contact Form', 'main');
$legacy = $create('Conformance Legacy Storage', 'legacy');
$deleteProbe = $create('Conformance Delete Probe', 'delete');
$defaults = WPCF7_ContactForm::find(['title' => 'Contact form 1', 'posts_per_page' => 1]);
$default = $defaults ? $defaults[0] : null;
if (!$main || !$legacy || !$deleteProbe || !$default) {
    throw new RuntimeException('target CF7 hostile fixture is incomplete');
}

update_post_meta($main->id(), '_old_cf7_unit_id', '8800001');
update_post_meta($legacy->id(), '_old_cf7_unit_id', '8800002');
update_post_meta($main->id(), '_config_validation', ['target-runtime' => true, 'timestamp' => 200]);
update_post_meta($main->id(), '_config_errors', ['target-obsolete-runtime']);
update_post_meta($main->id(), '_flamingo', ['channel' => 8801]);
update_post_meta($main->id(), '_constant_contact', ['list' => 'target-environment-list']);
update_post_meta($main->id(), '_sendinblue', ['list' => 'target-environment-list']);

$option = (array) get_option('wpcf7', []);
$option['duo_target_only'] = 'target-option-preserved';
$option['turnstile'] = ['sitekey' => 'target-site-key', 'secret' => 'target-secret-key'];
update_option('wpcf7', $option);

echo wp_json_encode([
    'default' => $default->id(),
    'default_hash' => get_post_meta($default->id(), '_hash', true),
    'delete_probe' => $deleteProbe->id(),
    'legacy' => $legacy->id(),
    'main' => $main->id(),
    'main_hash' => get_post_meta($main->id(), '_hash', true),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-cf7-hostile.php)
rm -f "$HOSTILE_FILE"
require_observed_nonempty "conf2 Contact Form 7 hostile target output" "$HOSTILE_OUT"
TARGET_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$TARGET_JSON" | jq -e '
  .default > 0 and .main > 15 and .legacy > 15 and .delete_probe > 15 and
  (.default_hash | test("^[0-9a-f]{64}$")) and (.main_hash | test("^[0-9a-f]{64}$"))
' >/dev/null || fail "CF7 hostile target premise was incomplete: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "${CONF_REPO2:-siterepo/conf2}/.tmp-cf7-target.json"

SOURCE_JSON=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-cf7-source.json")
SOURCE_MAIN=$(jq -r '.main_form' <<<"$SOURCE_JSON")
TARGET_MAIN=$(jq -r '.main' <<<"$TARGET_JSON")
TARGET_DEFAULT=$(jq -r '.default' <<<"$TARGET_JSON")
SOURCE_HASH=$(jq -r '.main_hash' <<<"$SOURCE_JSON")
TARGET_DEFAULT_HASH=$(jq -r '.default_hash' <<<"$TARGET_JSON")
require_fixture_ids SOURCE_MAIN TARGET_MAIN TARGET_DEFAULT
[ "$SOURCE_MAIN" != "$TARGET_MAIN" ] \
  || fail "CF7 source/target form ids did not diverge ($SOURCE_MAIN)"

# This is the concrete prior defect: CF7's REGEXP lookup would resolve the
# source page to an unrelated target form sharing the first seven hash bytes.
wp_conf2 post meta update "$TARGET_DEFAULT" _hash "$SOURCE_HASH" >/dev/null
TARGET_BEFORE=$(wp_conf2 eval '
  global $wpdb;
  $rows=[
    "posts"=>$wpdb->get_results("SELECT ID,post_title,post_name,post_status,post_content FROM {$wpdb->posts} WHERE post_type IN (\"wpcf7_contact_form\",\"page\") ORDER BY ID", ARRAY_A),
    "meta"=>$wpdb->get_results("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type=\"wpcf7_contact_form\") ORDER BY meta_id", ARRAY_A),
  ];
  echo hash("sha256", wp_json_encode($rows));
')
REV=$(git -C "${CONF_REPO2:-siterepo/conf2}" rev-parse HEAD)
COLLISION_RC=0
COLLISION_OUT=$(wp_conf2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1) || COLLISION_RC=$?
require_duo_answered "CF7 foreign hash-prefix collision apply" human "$COLLISION_OUT"
[ "$COLLISION_RC" -ne 0 ] \
  && grep -q "prefix '$(printf '%s' "$SOURCE_HASH" | cut -c1-7)' is already owned by another wpcf7_contact_form row ($TARGET_DEFAULT)" <<<"$COLLISION_OUT" \
  || fail "CF7 foreign hash-prefix collision did not refuse exactly: $COLLISION_OUT"
TARGET_AFTER=$(wp_conf2 eval '
  global $wpdb;
  $rows=[
    "posts"=>$wpdb->get_results("SELECT ID,post_title,post_name,post_status,post_content FROM {$wpdb->posts} WHERE post_type IN (\"wpcf7_contact_form\",\"page\") ORDER BY ID", ARRAY_A),
    "meta"=>$wpdb->get_results("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type=\"wpcf7_contact_form\") ORDER BY meta_id", ARRAY_A),
  ];
  echo hash("sha256", wp_json_encode($rows));
')
[ "$TARGET_AFTER" = "$TARGET_BEFORE" ] \
  || fail "CF7 preflight collision partially mutated target state ($TARGET_BEFORE -> $TARGET_AFTER)"
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = clear ] \
  || fail "CF7 pre-mutation hash collision retained apply recovery authority"
wp_conf2 post meta update "$TARGET_DEFAULT" _hash "$TARGET_DEFAULT_HASH" >/dev/null
pass "foreign target hash-prefix ownership refuses before mutation and leaves no recovery marker"
pass "CF7 target begins with divergent native identities, same-slug stale forms, and target-owned runtime/integration state"
