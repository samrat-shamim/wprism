#!/usr/bin/env bash
# Target-side acceptance a byte-diff cannot see: after deploy+apply, does the
# TARGET's own Hustle runtime agree with the applied custom-table rows?
#
# The generic harness already proved canonical(conf2) == canonical(conf1). What
# is asserted here is the half the canonical tree does not carry: that the
# plugin's own model can load the applied module, that the embed resolves to
# the TARGET's module id rather than the source's, and that the withheld
# credential/submission families did not arrive.
set -euo pipefail

read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
global $wpdb;
$moduleId = (int) $wpdb->get_var(
    "SELECT module_id FROM {$wpdb->prefix}hustle_modules WHERE module_name = 'WPrism Embed Fixture'"
);
if ( ! $moduleId ) {
    throw new RuntimeException('the applied Hustle module is missing from hustle_modules on the target');
}
// The plugin's own loader is the difference between "rows landed" and "Hustle
// accepts them": new_instance() runs init(), which reads the row and its meta.
$module = Hustle_Module_Model::new_instance( $moduleId );
if ( is_wp_error( $module ) ) {
    throw new RuntimeException('Hustle refused to load the applied module: ' . $module->get_error_message());
}
// get_content() returns a Hustle_Meta_Base_Content wrapper; to_array() is the
// accessor the plugin itself uses (hustle-module-model.php:321).
$content = $module->get_content()->to_array();
$embed   = get_page_by_path( 'wprism-hustle-embed', OBJECT, 'page' );
echo wp_json_encode( array(
    'module'        => $moduleId,
    'module_name'   => $module->module_name,
    'module_type'   => $module->module_type,
    'content_title' => is_array( $content ) && isset( $content['title'] ) ? $content['title'] : null,
    'shortcode_id'  => $module->get_meta( 'shortcode_id' ),
    'embed_content' => $embed ? $embed->post_content : null,
    'entries'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_entries" ),
    'tracking'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_tracking" ),
    'integrations'  => $module->get_meta( 'integrations_settings' ),
) );
PHPEOF

OBSERVE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-hustle-target.php"
printf '%s' "$OBSERVE_PHP" > "$OBSERVE_FILE"
TARGET_OUT=$(wp_conf2 eval-file /siterepo/.tmp-hustle-target.php)
rm -f "$OBSERVE_FILE"
require_observed_nonempty "Hustle target observation" "$TARGET_OUT"
TARGET=$(printf '%s\n' "$TARGET_OUT" | awk 'NF { line=$0 } END { print line }')

# The authored half must survive apply and be readable through Hustle's own
# model, not merely present as rows.
printf '%s\n' "$TARGET" | jq -e '
  .module_name == "WPrism Embed Fixture" and
  .module_type == "embedded" and
  .content_title == "WPrism fixture title" and
  .shortcode_id == "wprism-embed-fixture"
' >/dev/null || fail "the target plugin's own model does not agree with the applied module rows: $TARGET"

# The embed must address the TARGET's module id, not the source's — and the two
# must actually differ, or a verbatim id would satisfy this by coincidence. The
# seed burns source ids precisely so this assertion has teeth.
TARGET_ID=$(printf '%s\n' "$TARGET" | jq -r '.module')
# This hook runs on the host, so it can ask conf1 directly rather than routing
# the source id through the repository.
SOURCE_ID=$(wp_conf1 eval "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT module_id FROM {\$wpdb->prefix}hustle_modules WHERE module_name = 'WPrism Embed Fixture'\");" \
  | awk 'NF { line=$0 } END { print line }' | tr -dc '0-9')
[ -n "$SOURCE_ID" ] && [ "$SOURCE_ID" -gt 0 ] 2>/dev/null \
  || fail "the source module id could not be read from conf1, so embed rebinding cannot be distinguished from coincidence"
[ "$SOURCE_ID" != "$TARGET_ID" ] \
  || fail "source and target module ids are both $TARGET_ID, so this run cannot prove the embed was rebound"
printf '%s\n' "$TARGET" | jq -e --arg id "$TARGET_ID" '
  .embed_content | test("\\[wd_hustle id=\"" + $id + "\"")
' >/dev/null || fail "the applied embed does not resolve to the target's own module id ($TARGET_ID): $TARGET"

# Withheld families must not have TRAVELLED. The source authored no entries and
# no tracking rows, and postdeploy planted exactly one of each on the target, so
# one of each afterwards means apply carried nothing in and deleted nothing —
# any other count would mean a runtime table crossed the boundary.
printf '%s\n' "$TARGET" | jq -e '
  .entries == 1 and .tracking == 1 and
  ((.integrations == null) or (.integrations == "") or (.integrations == false))
' >/dev/null || fail "a withheld Hustle family crossed the boundary through apply: $TARGET"

printf '%s\n' "$TARGET"
pass "the target's own Hustle model accepts the applied module, the embed resolves to the target's id, and credentials and submissions are absent"

# ---- dirty target: the target's own state survives a converging apply -------
# postdeploy.sh planted a visitor submission, a conversion counter and an
# undeclared option. None is authored state, so apply must neither carry them
# away nor destroy them.
read -r -d '' PRESERVE_PHP <<'PHPEOF' || true
<?php
global $wpdb;
echo wp_json_encode( array(
    'entries'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_entries" ),
    'tracking' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_tracking" ),
    'counter'  => (int) $wpdb->get_var( "SELECT counter FROM {$wpdb->prefix}hustle_tracking LIMIT 1" ),
    'neighbor' => get_option( 'hustle_target_only_neighbor' ),
) );
PHPEOF
PRESERVE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-hustle-preserve.php"
printf '%s' "$PRESERVE_PHP" > "$PRESERVE_FILE"
PRESERVE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-hustle-preserve.php)
rm -f "$PRESERVE_FILE"
require_observed_nonempty "Hustle target preservation observation" "$PRESERVE_OUT"
PRESERVED=$(printf '%s\n' "$PRESERVE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$PRESERVED" | jq -e '
  .entries == 1 and .tracking == 1 and .counter == 7 and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "apply destroyed or absorbed target-only Hustle state: $PRESERVED"
pass "apply converged the authored module while preserving the target's visitor submission, conversion counter and neighbour option"

# ---- idempotence: the retry is a zero-change plan with a clean canary -------
ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Hustle zero-change plan" json "$ZERO_PLAN"
printf '%s\n' "$ZERO_PLAN" | jq -e '
  (.create | length) == 0 and (.update | length) == 0 and
  (.conflict | length) == 0 and (.drift | length) == 0
' >/dev/null || fail "Hustle retry was not a zero-change plan: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Hustle zero-change apply" json "$ZERO_APPLY"
[ "$(jq -r '.canary' <<<"$ZERO_APPLY")" = "clean" ] \
  || fail "Hustle zero-change retry dirtied the apply canary: $ZERO_APPLY"
pass "Hustle apply is idempotent: the retry plans nothing and keeps the canary clean"

# ---- lifecycle: deactivation preserves state, deploy restores behaviour -----
wp_conf2 plugin deactivate wordpress-popup >/dev/null
DEACTIVATED=$(wp_conf2 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hustle_modules");')
require_observed_nonempty "Hustle module count after deactivation" "$DEACTIVATED"
# The applied authored module is the only one on this target; deactivation
# must not drop it, because Hustle's uninstall path is separate from
# deactivation and authored rows are not runtime state.
[ "$DEACTIVATED" -ge 1 ] \
  || fail "Hustle deactivation removed authored module rows (count=$DEACTIVATED)"
REACTIVATE=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Hustle deploy after deactivation" json "$REACTIVATE"
wp_conf2 plugin is-active wordpress-popup >/dev/null \
  || fail "WPrism deploy did not reactivate exact Hustle code"
RELOADED=$(wp_conf2 eval '
  global $wpdb;
  $id = (int) $wpdb->get_var("SELECT module_id FROM {$wpdb->prefix}hustle_modules WHERE module_name = \x27WPrism Embed Fixture\x27");
  $m = Hustle_Module_Model::new_instance( $id );
  $c = is_wp_error( $m ) ? array() : $m->get_content()->to_array();
  echo isset( $c["title"] ) ? $c["title"] : "";')
require_observed_nonempty "Hustle module after reactivation" "$RELOADED"
grep -Fq 'WPrism fixture title' <<<"$RELOADED" \
  || fail "Hustle module content did not survive deactivate/reactivate: $RELOADED"
pass "Hustle deactivation preserves authored module rows and deploy reactivation restores the plugin's own reader"

# ---- an unmapped authored row refuses, loudly and without mutation ----------
# hustle_modules is an authored_snapshot table in `mapped` identity mode. A row
# the engine has no identity for cannot be created or rebound, because it can
# neither claim it as a source entity nor prove it is target-only. Measured:
# "mapped identity missing for populated table 'hustle_modules' row N
# (hustle_module); refusing to create or rebind it". This is the operational
# boundary for adopting a target that already runs Hustle.
UNMAPPED_ID=$(wp_conf2 eval '
  global $wpdb;
  $wpdb->insert( $wpdb->prefix . "hustle_modules", array(
    "module_name" => "Unmapped Target Module",
    "module_type" => "embedded",
    "module_mode" => "informational",
    "active" => 1,
  ), array( "%s", "%s", "%s", "%d" ) );
  echo (int) $wpdb->insert_id;' | awk 'NF { line=$0 } END { print line }' | tr -dc '0-9')
require_observed_nonempty "Hustle unmapped module id" "$UNMAPPED_ID"
BEFORE_UNMAPPED=$(wp_conf2 eval '
  global $wpdb;
  echo hash("sha256", maybe_serialize($wpdb->get_results("SELECT * FROM {$wpdb->prefix}hustle_modules ORDER BY module_id", ARRAY_A)));')
UNMAPPED_RC=0
UNMAPPED_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || UNMAPPED_RC=$?
require_wprism_answered "Hustle apply with an unmapped module row" human "$UNMAPPED_OUT"
[ "$UNMAPPED_RC" -ne 0 ] \
  || fail "apply accepted an unmapped authored module row instead of refusing: $UNMAPPED_OUT"
AFTER_UNMAPPED=$(wp_conf2 eval '
  global $wpdb;
  echo hash("sha256", maybe_serialize($wpdb->get_results("SELECT * FROM {$wpdb->prefix}hustle_modules ORDER BY module_id", ARRAY_A)));')
[ "$AFTER_UNMAPPED" = "$BEFORE_UNMAPPED" ] \
  || fail "the unmapped-row refusal mutated hustle_modules before stopping"
wp_conf2 eval "global \$wpdb; \$wpdb->delete( \$wpdb->prefix . 'hustle_modules', array( 'module_id' => $UNMAPPED_ID ), array( '%d' ) );" >/dev/null
pass "an unmapped authored module row refuses apply by name and mutates no row; adopting a populated Hustle target needs a verified identity sidecar first"

# ---- deletion: undeclared deletion intent refuses before it can travel ------
# The capsule declares no deletion selector for hustle_modules. Removing an
# authored module natively and re-capturing must therefore refuse at the SOURCE,
# before a tombstone can be published, rather than letting the intent reach a
# target. Measured message: "deletion intent for table:hustle_modules is
# unsupported — no pinned adapter declares its reverse-reference checks and
# cascade effects".
wp_conf1 eval '
  global $wpdb;
  $id = (int) $wpdb->get_var("SELECT module_id FROM {$wpdb->prefix}hustle_modules WHERE module_name = \x27WPrism Embed Fixture\x27");
  $wpdb->delete( $wpdb->prefix . "hustle_modules_meta", array( "module_id" => $id ), array( "%d" ) );
  $wpdb->delete( $wpdb->prefix . "hustle_modules", array( "module_id" => $id ), array( "%d" ) );' >/dev/null

DELETE_RC=0
DELETE_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || DELETE_RC=$?
require_wprism_answered "Hustle capture after an undeclared module deletion" human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] \
  || fail "capture published deletion intent for a surface no adapter declares deletable: $DELETE_OUT"
grep -q 'deletion intent for table:hustle_modules is unsupported' <<<"$DELETE_OUT" \
  || fail "Hustle deletion refused for the wrong reason: $DELETE_OUT"
# Nothing may have been published: the canonical module document must survive.
MODULE_DOC=$(grep -rl 'WPrism Embed Fixture' "$CONF_REPO1/state/tables" 2>/dev/null | head -1)
[ -n "$MODULE_DOC" ] \
  || fail "the refused deletion still removed the authored module from canonical state"
pass "removing an authored Hustle module refuses at capture, names the undeclared table, and leaves canonical state intact"

