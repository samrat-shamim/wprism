#!/usr/bin/env bash
# Prove apply preserved the hostile target's authored/runtime witnesses, then
# remove only the temporary authored group/item before the generic byte-identical
# recapture. Request logs and the undeclared neighbor deliberately remain.
set -euo pipefail

[ -f "${CONF_REPO2:-siterepo/conf2}/.redirection-target-fixture.json" ] \
  || fail "Redirection target witness file is missing"
TARGET_GROUP_ID=$(jq -r '.group_id' "${CONF_REPO2:-siterepo/conf2}/.redirection-target-fixture.json")
TARGET_ITEM_ID=$(jq -r '.item_id' "${CONF_REPO2:-siterepo/conf2}/.redirection-target-fixture.json")
TARGET_CACHE_KEY=$(jq -r '.cache_key' "${CONF_REPO2:-siterepo/conf2}/.redirection-target-fixture.json")
require_fixture_ids TARGET_GROUP_ID TARGET_ITEM_ID

OBSERVED=$(wp_conf2 eval "
  global \$wpdb;
  echo wp_json_encode([
    'group' => (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}redirection_groups WHERE id=%d AND name='Target-only operations'\", $TARGET_GROUP_ID)),
    'item' => (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}redirection_items WHERE id=%d AND title='Target-only redirect'\", $TARGET_ITEM_ID)),
    'logs' => (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}redirection_logs\"),
    'not_found' => (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}redirection_404\"),
    'neighbor' => get_option('duo_redirection_target_neighbor'),
    'cache_key' => (int) Red_Options::get()['cache_key'],
  ]);
")
require_observed_nonempty "Redirection preserved target witnesses" "$OBSERVED"
jq -e --argjson before "$TARGET_CACHE_KEY" '
  .group == 1 and .item == 1 and .logs >= 1 and .not_found >= 1 and
  .neighbor == "target-only-neighbor" and .cache_key > $before
' <<<"$OBSERVED" >/dev/null || fail "Redirection apply crossed a target-owned boundary or did not rotate cache: $OBSERVED"

wp_conf2 eval "
  \$item = Red_Item::get_by_id($TARGET_ITEM_ID);
  if (!\$item instanceof Red_Item) { throw new RuntimeException('target-only item vanished before cleanup'); }
  \$item->delete();
  \$group = Red_Group::get($TARGET_GROUP_ID, true);
  if (!\$group instanceof Red_Group) { throw new RuntimeException('target-only group vanished before cleanup'); }
  \$group->delete();
  foreach (Red_Group::get_all() as \$row) {
    if (str_starts_with((string) \$row['name'], 'Target activation residue ')) {
      \$residue = Red_Group::get((int) \$row['id'], true);
      if (!\$residue instanceof Red_Group || \$residue->get_total_redirects() !== 0) {
        throw new RuntimeException('activation residue acquired an authored redirect before cleanup');
      }
      \$residue->delete();
    }
  }
" >/dev/null
rm -f "${CONF_REPO2:-siterepo/conf2}/.redirection-target-fixture.json"
pass "Redirection apply preserves target-only authored/runtime state, rotates the native cache, and cleanup removes only test-owned target witnesses"
