#!/usr/bin/env bash
set -euo pipefail
IMPORTER_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
capture_wprism_json_success IMPORTER_VERIFY1 'Importer exact native and canonical settings readback' wp_conf1 --require="$IMPORTER_FIXTURES/admin-context.php" eval-file "$IMPORTER_FIXTURES/settings-native.php" verify-capture --use-include --user=admin
capture_wprism_json_success IMPORTER_RECAPTURE 'Importer repeated public Capture' wp_conf1 wprism capture --repo=/siterepo --format=json
capture_wprism_json_success IMPORTER_VERIFY2 'Importer repeated native and canonical settings readback' wp_conf1 --require="$IMPORTER_FIXTURES/admin-context.php" eval-file "$IMPORTER_FIXTURES/settings-native.php" verify-capture --use-include --user=admin
[ "$IMPORTER_VERIFY1" = "$IMPORTER_VERIFY2" ] || fail 'Importer repeated Capture changed settings or canonical bytes'
pass 'Importer nine settings capture and recapture with an exact native fixed point'
