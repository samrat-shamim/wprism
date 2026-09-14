#!/usr/bin/env bash
set -euo pipefail
IMPORTER_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
capture_wprism_json_success IMPORTER_SAVE 'Importer native settings Save' wp_conf1 --require="$IMPORTER_FIXTURES/admin-context.php" eval-file "$IMPORTER_FIXTURES/settings-native.php" save-source --use-include --user=admin
printf '%s\n' "$IMPORTER_SAVE" | jq -e '.status == true' >/dev/null || fail 'Importer native settings Save did not succeed'
pass 'Importer nine native settings were Saved through the authenticated plugin callback'

. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/roundtrip.sh"
importer_roundtrip_begin
importer_roundtrip_capture source-export importer_roundtrip_native 1 templates-native setup-source
importer_roundtrip_capture source-import importer_roundtrip_native 1 import-templates-native setup-source
importer_roundtrip_capture source importer_roundtrip_observe 1
