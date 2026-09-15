#!/usr/bin/env bash
set -euo pipefail
QI_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
QI_NATIVE="$CONF_REPO1/.tmp-qi-native"
QI_ROUNDTRIP="$CONF_REPO1/.tmp-qi-roundtrip"
[ ! -e "$QI_NATIVE" ] || fail 'Qi native source scratch already exists; use a clean pair'
[ ! -e "$QI_ROUNDTRIP" ] || fail 'Qi native roundtrip scratch already exists; use a clean pair'
mkdir -p "$QI_NATIVE"
mkdir -p "$QI_ROUNDTRIP/native-apply" "$QI_ROUNDTRIP/conformance"
cp "$QI_CAPSULE/fixtures/conformance/"*.php "$QI_NATIVE/"
cp "$QI_CAPSULE/fixtures/native-blocks.html" "$QI_CAPSULE/fixtures/native-options.json" "$QI_NATIVE/"
cp "$QI_CAPSULE/fixtures/native-apply/native.php" "$QI_ROUNDTRIP/native-apply/"
cp "$QI_CAPSULE/fixtures/conformance/corpus.php" "$QI_ROUNDTRIP/conformance/"
capture_wprism_json_success QI_SEED 'Qi native WordPress REST authoring' wp_conf1 eval-file /siterepo/.tmp-qi-native/native.php seed --use-include --user=admin
printf '%s\n' "$QI_SEED"
printf '%s\n' "$QI_SEED" > "$QI_ROUNDTRIP/source-seed.json"
capture_wprism_json_success QI_STYLES 'Qi native style REST writer' wp_conf1 eval-file /siterepo/.tmp-qi-native/native.php styles --use-include --user=admin
printf '%s\n' "$QI_STYLES" | jq -e '.status == "success"' >/dev/null || fail 'Qi style writer did not return native success'
capture_wprism_json_success QI_OBSERVATION 'Qi fresh native source readback' wp_conf1 eval-file /siterepo/.tmp-qi-native/native.php observe --use-include --user=admin
printf '%s\n' "$QI_OBSERVATION"
pass 'Qi standalone corpus authors 47 native block types and their saved CSS through native writers'
