#!/usr/bin/env bash
set -euo pipefail
SCENARIO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
REPOSITORY_ROOT=$(cd "$SCENARIO_ROOT/../.." && pwd -P)
SCENARIO_TARGET="$CONF_REPO2/.tmp-qi-vp-roundtrip"
bash "$REPOSITORY_ROOT/adapter-packages/visual-portfolio/tests/conformance/postapply.sh"
printf '%s\n' "$APPLY_JSON" > "$SCENARIO_TARGET/apply.json"
capture_wprism_json_success QI_VP_TARGET 'combined complete target postimage' \
  wp_conf2 eval-file /siterepo/.tmp-qi-roundtrip/native-apply/native.php observe --use-include --user=admin
printf '%s\n' "$QI_VP_TARGET" > "$SCENARIO_TARGET/target.json"
php "$SCENARIO_ROOT/fixtures/evidence.php" --native \
  "$CONF_REPO1/.tmp-qi-roundtrip/source.json" "$SCENARIO_TARGET/target.json" \
  "$SCENARIO_TARGET/before.json" "$CONF_REPO1/.tmp-qi-roundtrip/source-seed.json" \
  "$REPOSITORY_ROOT/adapter-packages/qi-blocks/fixtures/native-blocks.html" \
  "$REPOSITORY_ROOT/adapter-packages/qi-blocks/fixtures/native-options.json" "$SCENARIO_TARGET/apply.json"
pass 'the combined Apply preserves every target-local row and rebinds both adapters under divergent identities'
