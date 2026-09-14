#!/usr/bin/env bash
set -euo pipefail
VP_TARGET="$CONF_REPO2/.tmp-vp-capture"
[ ! -e "$VP_TARGET" ] || fail 'Visual Portfolio target fixture already exists'
mkdir -p "$VP_TARGET"
cp "$CONF_REPO1/.tmp-vp-capture/"* "$VP_TARGET/"
capture_wprism_json_success VP_TARGET_PADDING 'Visual Portfolio divergent target identities' wp_conf2 eval-file /siterepo/.tmp-vp-capture/roundtrip-native.php pad-target --use-include --user=admin
printf '%s\n' "$VP_TARGET_PADDING"
