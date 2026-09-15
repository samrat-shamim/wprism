#!/usr/bin/env bash
set -euo pipefail
VP_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
VP_NATIVE="$CONF_REPO1/.tmp-vp-capture"
[ ! -e "$VP_NATIVE" ] || fail 'Visual Portfolio source scratch exists; use a fresh pair'
mkdir -p "$VP_NATIVE"
cp "$VP_CAPSULE/fixtures/native/"* "$VP_NATIVE/"
capture_wprism_json_success VP_MIGRATION_BEFORE 'Visual Portfolio source migration premise' wp_conf1 eval-file /siterepo/.tmp-vp-capture/migration.php observe --use-include --user=admin
jq -e '.format == "wprism-vp-native-migration/v1" and .state.cursor == null' <<<"$VP_MIGRATION_BEFORE" >/dev/null \
  || fail 'Visual Portfolio source did not begin at the natural missing migration cursor'
capture_wprism_json_success VP_MIGRATION_SOURCE 'Visual Portfolio source native migration' wp_conf1 eval-file /siterepo/.tmp-vp-capture/migration.php settle --use-include --user=admin
jq -e '.format == "wprism-vp-native-migration/v1" and .before.cursor == null and .after.cursor == "3.8.1" and .fixed_point == true' \
  <<<"$VP_MIGRATION_SOURCE" >/dev/null || fail 'Visual Portfolio source migration did not reach the exact current fixed point'
pass 'Visual Portfolio source storage is current before native authoring'
capture_wprism_json_success VP_PADDING 'Visual Portfolio native identity divergence' wp_conf1 eval-file /siterepo/.tmp-vp-capture/setup.php pad-target --use-include --user=admin
capture_wprism_json_success VP_SEED 'Visual Portfolio native media and projects' wp_conf1 eval-file /siterepo/.tmp-vp-capture/setup.php seed-source --use-include --user=admin
printf '%s\n' "$VP_SEED" > "$VP_NATIVE/seed.json"
capture_wprism_json_success VP_SAVED 'Visual Portfolio native block and settings writers' wp_conf1 eval-file /siterepo/.tmp-vp-capture/save.php --use-include --user=admin
printf '%s\n' "$VP_SAVED"
pass 'Visual Portfolio authors the retained native gallery, archive, media, projects and settings'
