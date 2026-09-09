#!/usr/bin/env bash
# Author a Hustle module through the plugin's OWN model, not by inserting rows.
#
# Hustle keeps no post type: Hustle_Module_Model::save() writes the
# hustle_modules row and update_meta() writes each hustle_modules_meta row
# (inc/class-hustle-db.php:225-267 defines both). Driving the model is what
# makes this a test of the manifest rather than of the seed, and the seed
# proves the model ran by reading back the module id it minted.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
if ( ! class_exists( 'Hustle_Module_Model' ) ) {
    throw new RuntimeException('Hustle model classes are unavailable; the plugin did not load');
}
// Burn two auto-increment ids on the SOURCE so the fixture module cannot share
// an id with the one apply mints on a fresh target. Without this the embed
// rebinding assertion passes by coincidence — measured: source and target both
// landed on module_id 1, so a verbatim id would have satisfied it.
global $wpdb;
foreach ( array( 'wprism-burn-1', 'wprism-burn-2' ) as $burn ) {
    $throwaway = Hustle_Module_Model::new_instance();
    $throwaway->module_name = $burn;
    $throwaway->module_type = Hustle_Module_Model::EMBEDDED_MODULE;
    $throwaway->module_mode = Hustle_Module_Model::INFORMATIONAL_MODE;
    $throwaway->active      = 1;
    $throwaway->save();
    $wpdb->delete( $wpdb->prefix . 'hustle_modules', array( 'module_id' => (int) $throwaway->id ), array( '%d' ) );
}

// hustle-model.php:211 makes the constructor private on purpose ("Hide
// constructor to force use of new_instance method"), and new_instance() with
// no id is documented as the empty model for creating one (:1481-1487).
$module = Hustle_Module_Model::new_instance();
if ( is_wp_error( $module ) ) {
    throw new RuntimeException('Hustle_Module_Model::new_instance refused: ' . $module->get_error_message());
}
$module->module_name = 'WPrism Embed Fixture';
$module->module_type = Hustle_Module_Model::EMBEDDED_MODULE;
$module->module_mode = Hustle_Module_Model::INFORMATIONAL_MODE;
$module->active      = 1;
$saved = $module->save();
if ( is_wp_error( $saved ) ) {
    throw new RuntimeException('Hustle_Module_Model::save refused: ' . $saved->get_error_message());
}
// save() keys insert-vs-update off $this->id and assigns insert_id to it
// (hustle-model.php:294-296), so that is the minted identity to read back.
$moduleId = (int) $module->id;
if ( $moduleId < 1 ) {
    throw new RuntimeException('Hustle_Module_Model::save did not mint a module id');
}

// The model's own meta writer, one row per KEY_* constant.
$module->update_meta( Hustle_Module_Model::KEY_CONTENT, array(
    'title'       => 'WPrism fixture title',
    'sub_title'   => 'authored subtitle',
    'main_content' => '<p>Authored module body.</p>',
) );
$module->update_meta( Hustle_Module_Model::KEY_SETTINGS, array( 'auto_close_success_message' => '0' ) );
$module->update_meta( Hustle_Module_Model::KEY_DESIGN, array( 'style' => 'minimal' ) );
$module->update_meta( Hustle_Module_Model::KEY_SHORTCODE_ID, 'wprism-embed-fixture' );

// A page embedding the module by its NUMERIC id — the >=4.2.1 form that a
// target must rebind, as distinct from the legacy shortcode_id string.
$embed = wp_insert_post( array(
    'post_type'    => 'page',
    'post_status'  => 'publish',
    'post_title'   => 'Hustle Embed Page',
    'post_name'    => 'wprism-hustle-embed',
    'post_content' => '[wd_hustle id="' . $moduleId . '" type="embedded"]',
), true );
if ( is_wp_error( $embed ) ) {
    throw new RuntimeException('embedding page was not created: ' . $embed->get_error_message());
}

$metaRows = (int) $wpdb->get_var( $wpdb->prepare(
    'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'hustle_modules_meta WHERE module_id = %d', $moduleId
) );
echo wp_json_encode( array(
    'module'     => $moduleId,
    'embed'      => (int) $embed,
    'meta_rows'  => $metaRows,
    'module_row' => (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'hustle_modules WHERE module_id = %d', $moduleId ) ),
) );
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-hustle-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-hustle-seed.php)
rm -f "$SEED_FILE"
require_observed_nonempty "Hustle native source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '.module > 2 and .embed > 0 and .module_row == 1 and .meta_rows >= 4' >/dev/null \
  || fail "Hustle's own model did not persist the module and its meta rows above the burned id range: $SEED_JSON"
printf '%s\n' "$SEED_JSON"
pass "Hustle module, its content/settings/design/shortcode_id meta and a numeric-id embed were authored through the plugin's own model"
