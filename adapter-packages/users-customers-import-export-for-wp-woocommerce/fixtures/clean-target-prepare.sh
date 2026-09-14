#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/roundtrip.sh"
importer_roundtrip_capture pristine importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" pristine "$IMPORTER_EVIDENCE" "$CONF_PAIR"
importer_roundtrip_capture prerequisites importer_roundtrip_native 2 clean-target-native
importer_roundtrip_capture target-inputs importer_roundtrip_native 2 import-templates-native bindings
input_index=0
while IFS= read -r input_binding; do
  input_index=$((input_index + 1))
  importer_roundtrip_capture "input-$input_index" wp_conf2 wprism env-set --repo=/siterepo \
    --name="$input_binding" --stdin --format=json <<<target-input.csv
done < <(jq -r '.bindings[]' "$IMPORTER_EVIDENCE/target-inputs.stdout")
[ "$input_index" = 2 ] || fail 'clean target requires both public CSV bindings'
importer_roundtrip_capture before importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" prepared "$IMPORTER_EVIDENCE" "$CONF_PAIR"
