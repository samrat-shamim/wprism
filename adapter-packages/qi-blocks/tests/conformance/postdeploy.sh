#!/usr/bin/env bash
set -euo pipefail
QI_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
QI_TARGET="$CONF_REPO2/.tmp-qi-roundtrip"
[ ! -e "$QI_TARGET" ] || fail 'Qi native target scratch already exists'
mkdir -p "$QI_TARGET/native-apply" "$QI_TARGET/conformance"
cp "$QI_CAPSULE/fixtures/native-apply/native.php" "$QI_TARGET/native-apply/"
cp "$QI_CAPSULE/fixtures/conformance/corpus.php" "$QI_TARGET/conformance/"
capture_wprism_json_success QI_TARGET_SETUP 'Qi independent target padding and runtime witnesses' \
  wp_conf2 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php setup-conformance-target --use-include --user=admin
printf '%s\n' "$QI_TARGET_SETUP"
capture_wprism_json_success QI_BEFORE 'Qi complete native target preimage' \
  wp_conf2 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php observe --use-include --user=admin
printf '%s\n' "$QI_BEFORE" > "$QI_TARGET/before.json"
pass 'Qi target starts with eight divergent trash identities and complete target-local runtime witnesses'
