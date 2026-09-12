#!/usr/bin/env bash
set -euo pipefail
MAP_CAPSULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cp "$MAP_CAPSULE/fixtures/source-probe.php" "$CONF_REPO1/.tmp-map-probe.php"
cp "$MAP_CAPSULE/fixtures/saved-default-key.html" "$CONF_REPO1/.tmp-map-saved.html"
MAP_SEED=$(wp_conf1 eval-file /siterepo/.tmp-map-probe.php seed)
require_observed_nonempty "Map Block source seed" "$MAP_SEED"
jq -e '.mode == "seed" and .post > 0 and .version == "1.35" and .option_matches and .editor_uses_local_key and (.body_has_map | not)' <<<"$MAP_SEED" >/dev/null \
  || fail 'Map Block seed or native editor-localization premise failed'
pass 'Map Block 1.35 source uses its local API key and has ordinary authored content'
