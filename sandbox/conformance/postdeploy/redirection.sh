#!/usr/bin/env bash
# Turn activation defaults into target-owned mapped residue, diverge future ids,
# plant a target-only redirect/log/404, and prime a negative cache generation.
# Apply must preserve all of it while adding the repository graph under new ids.
set -euo pipefail

# Deploy has activated the exact plugin bytes, but Redirection's activation
# hook intentionally does not install its database. Complete the same public
# onboarding lifecycle a real target requires, then verify every storage
# surface before manufacturing hostile target-owned state.
INSTALL_OUT=$(wp_conf2 redirection database install 2>&1)
require_observed_nonempty "Redirection target database install" "$INSTALL_OUT"
TARGET_DATABASE=$(wp_conf2 eval '
  global $wpdb;
  $tables=[];
  foreach (["redirection_items","redirection_groups","redirection_logs","redirection_404"] as $suffix) {
    $name=$wpdb->prefix.$suffix;
    $tables[$suffix]=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$name))===$name;
  }
  echo wp_json_encode([
    "database"=>(string)(Red_Options::get()["database"] ?? ""),
    "groups"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_groups"),
    "tables"=>$tables,
  ]);
')
require_observed_nonempty "Redirection target database readiness" "$TARGET_DATABASE"
jq -e '.database != "" and .groups >= 2 and (.tables | all(. == true))' <<<"$TARGET_DATABASE" >/dev/null \
  || fail "Redirection native target database install did not converge: $TARGET_DATABASE"

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

foreach (Red_Group::get_all() as $row) {
    $group = Red_Group::get((int) $row['id'], true);
    if (!$group instanceof Red_Group
        || !$group->update(['name' => 'Target activation residue ' . (int) $row['id'], 'moduleId' => 1])) {
        throw new RuntimeException('could not rename a target activation group');
    }
}

global $wpdb;
$wpdb->query("ALTER TABLE {$wpdb->prefix}redirection_groups AUTO_INCREMENT=101");
$wpdb->query("ALTER TABLE {$wpdb->prefix}redirection_items AUTO_INCREMENT=201");
$group = Red_Group::create('Target-only operations', WordPress_Module::MODULE_ID, true);
if (!$group instanceof Red_Group) {
    throw new RuntimeException('could not create target-only group');
}
$item = Red_Item::create([
    'url' => '/target-only-redirect',
    'regex' => false,
    'match_type' => 'url',
    'action_type' => 'url',
    'action_code' => 302,
    'action_data' => ['url' => home_url('/target-only-destination/')],
    'title' => 'Target-only redirect',
    'group_id' => $group->get_id(),
]);
if (is_wp_error($item) || !$item instanceof Red_Item) {
    throw new RuntimeException('could not create target-only item');
}

Red_Options::save(['cache_key' => true]);
update_option('duo_redirection_target_neighbor', 'target-only-neighbor');
file_put_contents('/siterepo/.redirection-target-fixture.json', wp_json_encode([
    'cache_key' => (int) Red_Options::get()['cache_key'],
    'group_id' => $group->get_id(),
    'item_id' => $item->get_id(),
], JSON_UNESCAPED_SLASHES));

echo wp_json_encode([
    'cache_key' => (int) Red_Options::get()['cache_key'],
    'group_id' => $group->get_id(),
    'item_id' => $item->get_id(),
    'neighbor' => get_option('duo_redirection_target_neighbor'),
], JSON_UNESCAPED_SLASHES);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-redirection-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-redirection-hostile.php)
require_observed_nonempty "Redirection hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '.cache_key > 0 and .group_id >= 101 and .item_id >= 201 and .neighbor == "target-only-neighbor"' \
  <<<"$HOSTILE_JSON" >/dev/null || fail "Redirection hostile target ids/state did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"

TARGET_REDIRECT_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF2_PORT}/target-only-redirect")
TARGET_MISSING_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF2_PORT}/target-only-missing")
NEGATIVE_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF2_PORT}/summer")
[ "$TARGET_REDIRECT_CODE" = 302 ] && [ "$TARGET_MISSING_CODE" = 404 ] && [ "$NEGATIVE_CODE" = 404 ] \
  || fail "Redirection target cache/log premises failed (redirect=$TARGET_REDIRECT_CODE missing=$TARGET_MISSING_CODE negative=$NEGATIVE_CODE)"

# A populated mapped row needs its own target identity before the repository's
# unrelated mapped graph is applied. Capture against an isolated policy root:
# using /siterepo directly would expose the source's canonical mapped UUIDs to
# a database with no corresponding target ledger and correctly refuse as a
# lost identity sidecar. The disposable state is never published; only the
# database-matched duo_map evidence survives.
IDENTITY_REPO="${CONF_REPO2:-siterepo/conf2}/.tmp-redirection-identity-repo"
IDENTITY_STATE="${CONF_REPO2:-siterepo/conf2}/.tmp-redirection-identity-state"
WIDGET_STATE="${CONF_REPO2:-siterepo/conf2}/.tmp-redirection-identity-widgets"
mkdir -p "$IDENTITY_REPO"
# The identity-minting pass must not touch core's default category/widgets:
# first apply adopts/reconciles those against the source graph, and a locally
# minted UUID would make that adoption correctly contradictory. Core's grammar
# must remain pinned because capture always owns required platform options such
# as active_plugins and refuses to guess their storage semantics. Project the
# temporary policy down to core grammar plus the one adapter, while explicitly
# leaving core's identity-bearing entity and reference state local.
jq '
  .manifests = ["core", "redirection"] |
  .policy.post_types = [] |
  .policy.taxonomies = [] |
  .policy.scope.taxonomy.category.class = "runtime" |
  .policy.options.default_category.class = "runtime"
' "${CONF_REPO2:-siterepo/conf2}/site.duo.json" > "$IDENTITY_REPO/site.duo.json"

# Capture audits global WordPress state even when the selected adapter owns no
# core surfaces. Fresh WordPress installs carry block-widget instances, and a
# redirection-only manifest correctly cannot classify their sidebar grammar.
# Quiesce those unrelated options through WordPress APIs only for this
# disposable identity pass, then restore and hash-check their exact decoded
# values on success or failure. The real repository and its policy stay
# untouched throughout.
restore_redirection_identity_widgets() {
  [ -f "$WIDGET_STATE" ] || return 0
  wp_conf2 eval '
    $path="/siterepo/.tmp-redirection-identity-widgets";
    $state=unserialize((string)file_get_contents($path),["allowed_classes"=>false]);
    if (!is_array($state) || !array_key_exists("sidebars_widgets",$state) || !array_key_exists("widget_block",$state)) {
      throw new RuntimeException("Redirection identity widget backup is malformed");
    }
    update_option("sidebars_widgets",$state["sidebars_widgets"]);
    update_option("widget_block",$state["widget_block"]);
    echo hash("sha256",serialize([
      "sidebars_widgets"=>get_option("sidebars_widgets"),
      "widget_block"=>get_option("widget_block"),
    ]));
  '
  rm -f "$WIDGET_STATE"
}
trap restore_redirection_identity_widgets EXIT
WIDGET_HASH=$(wp_conf2 eval '
  $path="/siterepo/.tmp-redirection-identity-widgets";
  $state=[
    "sidebars_widgets"=>get_option("sidebars_widgets"),
    "widget_block"=>get_option("widget_block"),
  ];
  if (file_put_contents($path,serialize($state),LOCK_EX) === false) {
    throw new RuntimeException("could not preserve unrelated WordPress widgets");
  }
  update_option("sidebars_widgets",["wp_inactive_widgets"=>[],"array_version"=>3]);
  update_option("widget_block",["_multiwidget"=>1]);
  echo hash("sha256",serialize($state));
')
require_observed_nonempty "Redirection identity widget backup" "$WIDGET_HASH"
# --out is load-bearing: capture still commits newly minted duo_map rows, but
# it skips canonical duo_state/media publication. Omitting it would rebase the
# target's conflict ledger against this disposable projection, so restoring
# the quiesced widgets would make the real first apply accuse options/core.
TARGET_IDENTITY_CAPTURE=$(wp_conf2 duo capture \
  --repo=/siterepo/.tmp-redirection-identity-repo \
  --out=/siterepo/.tmp-redirection-identity-state 2>&1)
require_duo_answered "Redirection target-only identity capture" human "$TARGET_IDENTITY_CAPTURE"
RESTORED_WIDGET_HASH=$(restore_redirection_identity_widgets)
require_observed_nonempty "Redirection identity widget restore" "$RESTORED_WIDGET_HASH"
[ "$RESTORED_WIDGET_HASH" = "$WIDGET_HASH" ] \
  || fail "Redirection identity pass did not exactly restore unrelated WordPress widgets"
trap - EXIT
rm -rf "$IDENTITY_REPO" "$IDENTITY_STATE"
RUNTIME=$(wp_conf2 eval '
  global $wpdb;
  echo wp_json_encode([
    "logs" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_logs"),
    "not_found" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_404"),
  ]);
')
require_observed_nonempty "Redirection target runtime premises" "$RUNTIME"
jq -e '.logs >= 1 and .not_found >= 1' <<<"$RUNTIME" >/dev/null \
  || fail "Redirection target requests did not create logs and 404 rows: $RUNTIME"
pass "Redirection target starts with divergent mapped residue, a target-only rule, runtime logs, and a primed negative cache"
