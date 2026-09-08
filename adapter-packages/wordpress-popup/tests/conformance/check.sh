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

# Withheld families must be absent: apply carries no credentials, and visitor
# submissions and counters are runtime tables that never travel.
printf '%s\n' "$TARGET" | jq -e '
  .entries == 0 and .tracking == 0 and
  ((.integrations == null) or (.integrations == "") or (.integrations == false))
' >/dev/null || fail "a withheld Hustle family arrived on the target through apply: $TARGET"

printf '%s\n' "$TARGET"
pass "the target's own Hustle model accepts the applied module, the embed resolves to the target's id, and credentials, submissions and tracking are absent"
