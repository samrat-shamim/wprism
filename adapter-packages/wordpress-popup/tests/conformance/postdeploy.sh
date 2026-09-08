#!/usr/bin/env bash
# Start the target dirty with the state a real target legitimately carries and
# this adapter does not own: a visitor submission row, a conversion counter and
# an undeclared option. Apply must converge the authored module without
# inheriting, deleting or renumbering any of it.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
global $wpdb;

// Deliberately NOT a target-only module row. hustle_modules is an
// authored_snapshot table in `mapped` identity mode, and the engine refuses to
// apply while a populated row carries no mapped identity — measured directly:
// "mapped identity missing for populated table 'hustle_modules' row 1
// (hustle_module); refusing to create or rebind it". That refusal is real
// product behaviour and is asserted in check.sh; planting such a row here
// would only prove the harness can violate the contract.
//
// What the target legitimately starts with is state the adapter does not own:
// a visitor submission, a conversion counter, and an undeclared option.
$wpdb->insert(
    $wpdb->prefix . 'hustle_entries',
    array( 'entry_type' => 'optin', 'module_id' => 999, 'date_created' => current_time( 'mysql' ) ),
    array( '%s', '%d', '%s' )
);
$wpdb->insert(
    $wpdb->prefix . 'hustle_tracking',
    array(
        'module_id'    => 999,
        'page_id'      => 0,
        'module_type'  => 'embedded',
        'action'       => 'view',
        'counter'      => 7,
        'date_created' => current_time( 'mysql' ),
        'date_updated' => current_time( 'mysql' ),
    ),
    array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
);
update_option( 'hustle_target_only_neighbor', 'target-only-neighbor' );

echo wp_json_encode( array(
    'entries'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_entries" ),
    'tracking' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_tracking" ),
    'counter'  => (int) $wpdb->get_var( "SELECT counter FROM {$wpdb->prefix}hustle_tracking LIMIT 1" ),
    'neighbor' => get_option( 'hustle_target_only_neighbor' ),
    'modules'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_modules" ),
) );
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-hustle-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-hustle-hostile.php)
rm -f "$HOSTILE_FILE"
require_observed_nonempty "Hustle hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .entries == 1 and .tracking == 1 and .counter == 7 and
  .neighbor == "target-only-neighbor" and .modules == 0
' >/dev/null || fail "Hustle hostile target premise did not land: $HOSTILE_JSON"
printf '%s\n' "$HOSTILE_JSON"
pass "Hustle target starts with a visitor submission, a conversion counter and an undeclared neighbour option that apply must preserve untouched"
