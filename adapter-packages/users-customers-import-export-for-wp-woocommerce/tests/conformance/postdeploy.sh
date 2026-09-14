#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/roundtrip.sh"
# Native setup creates independent target IDs, changed authored forms and
# excluded rows before Apply. It cannot manufacture a canonical postimage.
importer_roundtrip_capture target-settings importer_roundtrip_native 2 settings-native save-target
importer_roundtrip_capture target-export importer_roundtrip_native 2 templates-native setup-target
importer_roundtrip_capture target-import importer_roundtrip_native 2 import-templates-native setup-target
importer_roundtrip_capture target-jobs importer_roundtrip_native 2 settings-native jobs
importer_roundtrip_capture target-inputs importer_roundtrip_native 2 import-templates-native bindings
input_index=0
while IFS= read -r input_binding; do
  input_index=$((input_index + 1))
  importer_roundtrip_capture "input-$input_index" wp_conf2 wprism env-set --repo=/siterepo \
    --name="$input_binding" --stdin --format=json <<<target-input.csv
done < <(jq -r '.bindings[]' "$IMPORTER_EVIDENCE/target-inputs.stdout")
[ "$input_index" = 2 ] || fail 'Importer roundtrip requires two explicit target CSV bindings'
importer_roundtrip_capture before importer_roundtrip_observe 2
