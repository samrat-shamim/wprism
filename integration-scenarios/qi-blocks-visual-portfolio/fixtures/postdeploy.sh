#!/usr/bin/env bash
set -euo pipefail
SCENARIO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
REPOSITORY_ROOT=$(cd "$SCENARIO_ROOT/../.." && pwd -P)
SCENARIO_TARGET="$CONF_REPO2/.tmp-qi-vp-roundtrip"
mkdir -p "$SCENARIO_TARGET"
bash "$REPOSITORY_ROOT/adapter-packages/qi-blocks/tests/conformance/postdeploy.sh"
bash "$REPOSITORY_ROOT/adapter-packages/visual-portfolio/tests/conformance/postdeploy.sh"
capture_wprism_json_success QI_VP_BEFORE 'combined complete target preimage' \
  wp_conf2 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php observe --use-include --user=admin
printf '%s\n' "$QI_VP_BEFORE" > "$SCENARIO_TARGET/before.json"
pass 'the combined target holds both native lifecycle premises and forty target-local identity witnesses'
