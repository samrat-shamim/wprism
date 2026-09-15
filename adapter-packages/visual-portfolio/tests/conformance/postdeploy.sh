#!/usr/bin/env bash
set -euo pipefail
. tests/lib/conformance_private_command.sh
VP_TARGET="$CONF_REPO2/.tmp-vp-capture"
[ ! -e "$VP_TARGET" ] || fail 'Visual Portfolio target fixture already exists'
mkdir -p "$VP_TARGET"
cp "$CONF_REPO1/.tmp-vp-capture/"* "$VP_TARGET/"
VP_REV=$(git -C "$CONF_REPO2" rev-parse HEAD)
capture_wprism_json_success VP_LEGACY_ARCHIVE 'Visual Portfolio non-page legacy archive premise' \
  wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php seed-legacy-archive --use-include --user=admin
jq -e '.format == "wprism-vp-native-migration-fixture/v1" and .legacy_id > 0 and
  .state.archive_post.ID == (.legacy_id | tostring) and .state.archive_post.post_type == "post" and
  .state.archive_post.post_name == "vp-legacy-archive-before-migration"' <<<"$VP_LEGACY_ARCHIVE" >/dev/null \
  || fail 'Visual Portfolio non-page legacy archive was not established inside the migration projection'
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
capture_wprism_json_success VP_MIGRATION_SETTLED 'Visual Portfolio target provider migration' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php provider-settle --use-include --user=admin
jq -e '.mode == "provider-settle" and .before.cursor == null and .after.cursor == "3.8.1" and
  .bounded_observed_fixed_point == true and .provider.actions == 1 and .replay_actions == 0 and
  .before.archive_post.post_type == "post" and
  .before.archive_post.post_name == "vp-legacy-archive-before-migration" and
  .after.archive_post.post_name == "vp-migrated-ordinary-post" and
  .before.posts.sha256 != .after.posts.sha256 and
  .provider.receipts == [{"manifest":"visual-portfolio","provider":"visual-portfolio-migrations","capability":"settle_storage"}]' \
  <<<"$VP_MIGRATION_SETTLED" >/dev/null || fail 'Visual Portfolio provider did not settle the natural missing cursor through its exact action'
VP_LEGACY_ID=$(jq -r '.legacy_id' <<<"$VP_LEGACY_ARCHIVE")
VP_ORIGINAL_ARCHIVE_ID=$(jq -r '.original_archive_id' <<<"$VP_LEGACY_ARCHIVE")
capture_wprism_json_success VP_LEGACY_CLEANUP 'Visual Portfolio legacy archive fixture cleanup' \
  wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php cleanup-legacy-archive \
  "$VP_LEGACY_ID" "$VP_ORIGINAL_ARCHIVE_ID" --use-include --user=admin
jq -e --argjson legacy "$VP_LEGACY_ID" --argjson original "$VP_ORIGINAL_ARCHIVE_ID" \
  '.legacy_id == $legacy and .restored_archive_id == $original' <<<"$VP_LEGACY_CLEANUP" >/dev/null \
  || fail 'Visual Portfolio legacy archive fixture did not restore its target-only state'

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
capture_wprism_json_success VP_STALE_SETTLED 'Visual Portfolio stale target provider migration' wp_conf2 eval-file /siterepo/.tmp-vp-capture/migration.php provider-settle --use-include --user=admin
jq -e '.mode == "provider-settle" and .before.cursor == "3.8.0" and .after.cursor == "3.8.1" and
  .bounded_observed_fixed_point == true and .provider.actions == 1 and .replay_actions == 0 and
  .provider.receipts == [{"manifest":"visual-portfolio","provider":"visual-portfolio-migrations","capability":"settle_storage"}]' \
  <<<"$VP_STALE_SETTLED" >/dev/null || fail 'Visual Portfolio provider did not settle the stale cursor through its exact action'
pass 'Visual Portfolio missing and stale storage refuse without mutation, then settle through the engine provider phase'
capture_wprism_json_success VP_TARGET_PADDING 'Visual Portfolio divergent target identities' wp_conf2 eval-file /siterepo/.tmp-vp-capture/roundtrip-native.php pad-target --use-include --user=admin
printf '%s\n' "$VP_TARGET_PADDING"
