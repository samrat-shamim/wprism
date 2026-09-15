#!/usr/bin/env bash
set -euo pipefail
VP_TARGET="$CONF_REPO2/.tmp-vp-capture"
[ ! -e "$VP_TARGET" ] || fail 'Visual Portfolio target fixture already exists'
mkdir -p "$VP_TARGET"
cp "$CONF_REPO1/.tmp-vp-capture/"* "$VP_TARGET/"
VP_REV=$(git -C "$CONF_REPO2" rev-parse HEAD)
capture_wprism_json_success VP_MIGRATION_BEFORE 'Visual Portfolio target migration premise' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php observe --use-include --user=admin
jq -e '.format == "wprism-vp-native-migration/v1" and .state.cursor == null' <<<"$VP_MIGRATION_BEFORE" >/dev/null \
  || fail 'Visual Portfolio target activation unexpectedly completed its deferred migration'
capture_wprism_json_refusal VP_MISSING_REFUSAL 'Visual Portfolio missing-cursor Apply refusal' \
  conformance_private_command cli2 apply wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug=refuse \
  --default-author=admin --revision="$VP_REV" --json
jq -e '.format == "wprism-command-refusal/v1" and .command == "apply" and
  .reason_code == "storage_prerequisite_unmet" and (has("details_redacted") | not) and
  (.diagnostics | length == 1) and .diagnostics[0].manifest == "visual-portfolio" and
  .diagnostics[0].option == "vpf_db_version"' <<<"$VP_MISSING_REFUSAL" >/dev/null \
  || fail 'Visual Portfolio missing cursor did not return the exact value-free typed refusal'
capture_wprism_json_success VP_MIGRATION_AFTER_REFUSAL 'Visual Portfolio missing-cursor refusal postimage' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php observe --use-include --user=admin
[ "$(jq -cS '.state' <<<"$VP_MIGRATION_BEFORE")" = "$(jq -cS '.state' <<<"$VP_MIGRATION_AFTER_REFUSAL")" ] \
  || fail 'Visual Portfolio missing-cursor refusal changed a native migration surface'
capture_wprism_json_success VP_MIGRATION_SETTLED 'Visual Portfolio target native migration' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php settle --use-include --user=admin
jq -e '.before.cursor == null and .after.cursor == "3.8.1" and .fixed_point == true' <<<"$VP_MIGRATION_SETTLED" >/dev/null \
  || fail 'Visual Portfolio target migration did not settle the natural missing cursor'

wp_conf2 option update vpf_db_version 3.8.0 >/dev/null
capture_wprism_json_success VP_STALE_BEFORE 'Visual Portfolio stale migration premise' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php observe --use-include --user=admin
jq -e '.state.cursor == "3.8.0"' <<<"$VP_STALE_BEFORE" >/dev/null || fail 'Visual Portfolio stale cursor fixture was not established'
capture_wprism_json_refusal VP_STALE_REFUSAL 'Visual Portfolio stale-cursor Apply refusal' \
  conformance_private_command cli2 apply wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug=refuse \
  --default-author=admin --revision="$VP_REV" --json
jq -e '.reason_code == "storage_prerequisite_unmet" and .diagnostics[0].manifest == "visual-portfolio" and
  .diagnostics[0].option == "vpf_db_version"' <<<"$VP_STALE_REFUSAL" >/dev/null \
  || fail 'Visual Portfolio stale cursor did not return the exact typed refusal'
capture_wprism_json_success VP_STALE_AFTER 'Visual Portfolio stale-cursor refusal postimage' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php observe --use-include --user=admin
[ "$(jq -cS '.state' <<<"$VP_STALE_BEFORE")" = "$(jq -cS '.state' <<<"$VP_STALE_AFTER")" ] \
  || fail 'Visual Portfolio stale-cursor refusal changed a native migration surface'
capture_wprism_json_success VP_STALE_SETTLED 'Visual Portfolio stale target native migration' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php settle --use-include --user=admin
jq -e '.before.cursor == "3.8.0" and .after.cursor == "3.8.1" and .fixed_point == true' <<<"$VP_STALE_SETTLED" >/dev/null \
  || fail 'Visual Portfolio native procedure did not settle the stale cursor'
pass 'Visual Portfolio missing and stale storage refuse without mutation, then settle through the native migration procedure'
capture_wprism_json_success VP_TARGET_PADDING 'Visual Portfolio divergent target identities' wp_conf2 eval-file /siterepo/.tmp-vp-capture/roundtrip-native.php pad-target --use-include --user=admin
printf '%s\n' "$VP_TARGET_PADDING"
