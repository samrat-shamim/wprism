#!/usr/bin/env bash
# Production-oriented Redirection 5.9.0 proof. The generic harness has already
# proved fresh deploy/apply and byte-identical recapture; this hook adds native
# row/API agreement, all measured action_data frames, real routing, runtime
# exclusion, cache repair, conflicts, lifecycle recovery, hostile bytes, and a
# post-commit provider refusal at the unsupported server-module boundary.
set -euo pipefail

observe_redirection() { # <conf1|conf2>
  local side="$1" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Redirection observation side: $side" ;;
  esac
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
global $wpdb;
$groups = $wpdb->get_results(
    "SELECT id,name,module_id,status,tracking,position FROM {$wpdb->prefix}redirection_groups ORDER BY id",
    ARRAY_A
);
$items = $wpdb->get_results(
    "SELECT id,title,url,match_url,match_data,regex,position,group_id,status,action_type,action_code,action_data " .
    "FROM {$wpdb->prefix}redirection_items ORDER BY id",
    ARRAY_A
);
$campaign = [];
$shape = ['null' => 0, 'plain' => 0, 'serialized' => 0];
foreach ($items as $row) {
    $raw = $row['action_data'];
    if ($raw === null) {
        $shape['null']++;
        $action = null;
    } elseif (is_serialized($raw)) {
        $shape['serialized']++;
        $action = unserialize($raw, ['allowed_classes' => false]);
    } else {
        $shape['plain']++;
        $action = $raw;
    }
    $native = Red_Item::get_by_id((int) $row['id']);
    $campaign[$row['title']] = [
        'action' => $action,
        'action_code' => (int) $row['action_code'],
        'action_type' => $row['action_type'],
        'group_id' => (int) $row['group_id'],
        'id' => (int) $row['id'],
        'match_data' => $row['match_data'] ? json_decode($row['match_data'], true) : null,
        'match_type' => $native instanceof Red_Item ? $native->get_match_type() : null,
        'native_sql_equal' => $native instanceof Red_Item && ($native->to_sql()['action_data'] ?? null) === $raw,
        'regex' => (int) $row['regex'],
        'status' => $row['status'],
        'url' => $row['url'],
    ];
}
$options = Red_Options::get();
$portable = Red_Options::get_import_export_options();
$portable['associated_redirect'] = $options['associated_redirect'] ?? null;
$summerGroup = null;
foreach ($groups as $group) {
    if ($group['name'] === 'Summer campaign 東京 🚀') {
        $summerGroup = (int) $group['id'];
    }
}
echo wp_json_encode([
    'cache_key' => (int) ($options['cache_key'] ?? 0),
    'campaign' => $campaign,
    'group_count' => count($groups),
    'group_ids' => array_map('intval', array_column($groups, 'id')),
    'groups' => $groups,
    'hits' => (int) $wpdb->get_var("SELECT COALESCE(SUM(last_count),0) FROM {$wpdb->prefix}redirection_items"),
    'item_count' => count($items),
    'logs' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_logs"),
    'monitor_post' => (int) ($options['monitor_post'] ?? 0),
    'neighbor' => get_option('duo_redirection_target_neighbor', null),
    'not_found' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_404"),
    'portable' => $portable,
    'shape' => $shape,
    'summer_group' => $summerGroup,
    'token' => $options['token'] ?? null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF
  file="$repo_var/.tmp-redirection-observe.php"
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-redirection-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-redirection-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "Redirection $side runtime observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

redirection_target_hash() {
  wp_conf2 eval '
    global $wpdb;
    $state = [
      "groups" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_groups ORDER BY id", ARRAY_A),
      "items" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items ORDER BY id", ARRAY_A),
      "options" => get_option("redirection_options", null),
      "logs" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_logs ORDER BY id", ARRAY_A),
      "not_found" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_404 ORDER BY id", ARRAY_A),
      "neighbor" => get_option("duo_redirection_target_neighbor", null),
    ];
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  '
}

commit_redirection_source() { # <message>
  wp_conf1 duo capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

redirection_request() { # <path> [extra curl args]
  local path="$1"; shift
  REDIRECTION_HEADERS=$(mktemp "${TMPDIR:-/tmp}/duo-redirection-headers.XXXXXX")
  REDIRECTION_BODY=$(mktemp "${TMPDIR:-/tmp}/duo-redirection-body.XXXXXX")
  REDIRECTION_CODE=$(curl --path-as-is --max-time 20 -sS "$@" -D "$REDIRECTION_HEADERS" \
    -o "$REDIRECTION_BODY" -w '%{http_code}' "http://localhost:${CONF2_PORT}${path}") \
    || fail "Redirection request failed before an HTTP response: $path"
  REDIRECTION_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$REDIRECTION_HEADERS" | tail -1)
  rm -f "$REDIRECTION_HEADERS" "$REDIRECTION_BODY"
}

SOURCE_INITIAL=$(observe_redirection conf1)
TARGET_INITIAL=$(observe_redirection conf2)
jq -e '
  .item_count == 4 and .shape == {null:1, plain:2, serialized:1} and
  .campaign["Summer marketplace 東京 🚀"].native_sql_equal == true and
  .campaign["Summer marketplace 東京 🚀"].action_code == 302 and
  .campaign["Summer marketplace 東京 🚀"].match_type == "url" and
  .campaign["Vendor route"].native_sql_equal == true and
  .campaign["Vendor route"].action_code == 307 and
  .campaign["Vendor route"].regex == 1 and
  .campaign["Localized offers"].native_sql_equal == true and
  .campaign["Localized offers"].match_type == "language" and
  .campaign["Localized offers"].action.language == "fr" and
  .campaign["Retired service"].native_sql_equal == true and
  .campaign["Retired service"].action == null and
  .campaign["Retired service"].action_type == "error" and
  .campaign["Retired service"].action_code == 410 and
  .neighbor == "target-only-neighbor" and .logs >= 1 and .not_found >= 1
' <<<"$TARGET_INITIAL" >/dev/null \
  || fail "Redirection target rows/APIs/mixed frames did not converge: $TARGET_INITIAL"
[ "$(jq -c '.portable' <<<"$SOURCE_INITIAL")" = "$(jq -c '.portable' <<<"$TARGET_INITIAL")" ] \
  || fail 'Redirection portable native options diverged after apply'
[ "$(jq -r '.token' <<<"$SOURCE_INITIAL")" != "$(jq -r '.token' <<<"$TARGET_INITIAL")" ] \
  || fail 'Redirection target environment token accidentally matched the source token'
SOURCE_GROUP=$(jq -r '.summer_group' <<<"$SOURCE_INITIAL")
TARGET_GROUP=$(jq -r '.summer_group' <<<"$TARGET_INITIAL")
require_fixture_ids SOURCE_GROUP TARGET_GROUP
[ "$SOURCE_GROUP" != "$TARGET_GROUP" ] || fail "Redirection mapped group ids did not diverge ($SOURCE_GROUP)"
[ "$(jq -r '.monitor_post' <<<"$SOURCE_INITIAL")" = "$SOURCE_GROUP" ] \
  && [ "$(jq -r '.monitor_post' <<<"$TARGET_INITIAL")" = "$TARGET_GROUP" ] \
  || fail 'Redirection monitor_post option did not rebind to each site-local group id'
for title in 'Summer marketplace 東京 🚀' 'Vendor route' 'Localized offers' 'Retired service'; do
  SOURCE_ITEM=$(jq -r --arg title "$title" '.campaign[$title].id' <<<"$SOURCE_INITIAL")
  TARGET_ITEM=$(jq -r --arg title "$title" '.campaign[$title].id' <<<"$TARGET_INITIAL")
  require_fixture_ids SOURCE_ITEM TARGET_ITEM
  [ "$SOURCE_ITEM" != "$TARGET_ITEM" ] || fail "Redirection mapped item id did not diverge for $title ($SOURCE_ITEM)"
  [ "$(jq -r --arg title "$title" '.campaign[$title].group_id' <<<"$TARGET_INITIAL")" = "$TARGET_GROUP" ] \
    || fail "Redirection group_id reference did not rebind for $title"
done

PROVIDER_RECEIPT="${APPLY_JSON:-}"
jq -e '
  any(.actions[]?;
    .source == "provider:redirection-state/rebuild_redirect_state" and .verified == true and
    (.after.group_count | numbers) > 0 and (.after.item_count | numbers) >= 4 and
    (.after.group_hash | test("^[a-f0-9]{64}$")) and
    (.after.item_hash | test("^[a-f0-9]{64}$")) and
    .after.cache_enabled == true and .after.cache_key > .before.cache_key)
' <<<"$PROVIDER_RECEIPT" >/dev/null \
  || fail "Redirection initial apply omitted its closed provider receipt: ${PROVIDER_RECEIPT:-<missing>}"
grep -Fq 'summer-marketplace' <<<"$(jq -c '.actions' <<<"$PROVIDER_RECEIPT")" \
  && fail 'Redirection provider receipt leaked an authored redirect URL'
pass 'mapped groups/items, mixed storage frames, native APIs, environment ownership, and a value-free provider receipt converge'

redirection_request /summer
[ "$REDIRECTION_CODE" = 302 ] && [ "$REDIRECTION_LOCATION" = "http://localhost:${CONF2_PORT}/summer-marketplace/" ] \
  || fail "ordinary redirect failed (status=$REDIRECTION_CODE location=${REDIRECTION_LOCATION:-<none>})"
redirection_request /marketplace/vendor/alice
[ "$REDIRECTION_CODE" = 307 ] && [ "$REDIRECTION_LOCATION" = "http://localhost:${CONF2_PORT}/providers/alice" ] \
  || fail "regex redirect failed (status=$REDIRECTION_CODE location=${REDIRECTION_LOCATION:-<none>})"
redirection_request /offers -H 'Accept-Language: fr-FR,fr;q=0.9,en;q=0.5'
[ "$REDIRECTION_CODE" = 302 ] && [ "$REDIRECTION_LOCATION" = "http://localhost:${CONF2_PORT}/fr/offres/" ] \
  || fail "language redirect failed (status=$REDIRECTION_CODE location=${REDIRECTION_LOCATION:-<none>})"
redirection_request /retired-service
[ "$REDIRECTION_CODE" = 410 ] || fail "error action failed (status=$REDIRECTION_CODE)"
TRAFFIC=$(observe_redirection conf2)
jq -e '.hits >= 3 and .logs >= 4 and .not_found >= 1' <<<"$TRAFFIC" >/dev/null \
  || fail "Redirection traffic did not update target-local telemetry: $TRAFFIC"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-redirection-after-traffic >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-redirection-after-traffic" \
  || fail 'Redirection runtime traffic or derived cache generation leaked into canonical state'
rm -rf "$CONF_REPO2/.tmp-redirection-after-traffic"
pass 'real 302, regex 307, language 302, and 410 behavior works while hits/logs/404/cache state remain target-local'

ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Redirection zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "Redirection zero-change plan retained work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Redirection zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Redirection zero-change apply reran effects: $ZERO_APPLY"
pass 'zero-change plan/apply is mutation-free and does not rotate the cache or rerun the provider'

# A malformed serialized-looking value and a credential-shaped plain value
# must both refuse before publishing state. The live schema probe deliberately
# cannot see either framing; this is the native-value exercise the draft names.
BASELINE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
ORIGINAL_ACTION=$(wp_conf1 db query "SELECT TO_BASE64(action_data) FROM wp_redirection_items WHERE title='Summer marketplace 東京 🚀'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Redirection original action_data bytes' "$ORIGINAL_ACTION"
wp_conf1 db query "UPDATE wp_redirection_items SET action_data='a:1:{s:3:\"url\";s:99:\"x\";}' WHERE title='Summer marketplace 東京 🚀'" >/dev/null
MALFORMED_RC=0
MALFORMED_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || MALFORMED_RC=$?
require_duo_answered 'Redirection malformed mixed-container capture' human "$MALFORMED_OUT"
[ "$MALFORMED_RC" -ne 0 ] && grep -Eqi 'codec|serial|container|action_data' <<<"$MALFORMED_OUT" \
  || fail "Redirection malformed serialized-looking value did not refuse: $MALFORMED_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BASELINE" ] \
  || fail 'Redirection malformed framing partially published canonical state'
wp_conf1 db query "UPDATE wp_redirection_items SET action_data=FROM_BASE64('$ORIGINAL_ACTION') WHERE title='Summer marketplace 東京 🚀'" >/dev/null
FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
wp_conf1 db query "UPDATE wp_redirection_items SET action_data='https://example.test/AKIAABCDEFGHIJKLMNOP' WHERE title='Summer marketplace 東京 🚀'" >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SECRET_RC=$?
require_duo_answered 'Redirection credential-shaped plain-frame capture' human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "Redirection credential-shaped plain frame did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BASELINE" ] \
  || fail 'Redirection secret refusal partially published canonical state'
wp_conf1 db query "UPDATE wp_redirection_items SET action_data=FROM_BASE64('$ORIGINAL_ACTION') WHERE title='Summer marketplace 東京 🚀'" >/dev/null
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-redirection-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-redirection-restored" \
  || fail 'Redirection source did not restore byte-identically after hostile framing probes'
rm -rf "$CONF_REPO1/.tmp-redirection-restored"
pass 'malformed serialized-looking and credential-shaped plain frames refuse atomically without exposing values'

# Competing native edits must plan as a conflict, refuse without changing a
# byte, and converge only after the operator explicitly chooses repository
# intent. Target logs and its undeclared neighbor remain outside that choice.
wp_conf1 eval '
  global $wpdb; $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}redirection_items WHERE title=%s","Summer marketplace 東京 🚀"));
  $item=Red_Item::get_by_id($id); if (!$item instanceof Red_Item) throw new RuntimeException("source item missing");
  $details=$item->to_json(); $details["action_data"]=["url"=>home_url("/summer-marketplace-v2/")];
  if (is_wp_error($item->update($details))) throw new RuntimeException("source native update failed");
' >/dev/null
commit_redirection_source 'conformance: competing Redirection route intent'
wp_conf2 eval '
  global $wpdb; $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}redirection_items WHERE title=%s","Summer marketplace 東京 🚀"));
  $item=Red_Item::get_by_id($id); if (!$item instanceof Red_Item) throw new RuntimeException("target item missing");
  $details=$item->to_json(); $details["action_data"]=["url"=>home_url("/target-summer-sale/")];
  if (is_wp_error($item->update($details))) throw new RuntimeException("target native update failed");
' >/dev/null
CONFLICT_BEFORE=$(redirection_target_hash)
CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Redirection competing branch plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Redirection competing rule did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered 'Redirection unforced competing branch apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi conflict <<<"$CONFLICT_OUT" \
  || fail "Redirection unforced conflict did not refuse: $CONFLICT_OUT"
[ "$(redirection_target_hash)" = "$CONFLICT_BEFORE" ] \
  || fail 'Redirection unforced conflict partially mutated target state'
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Redirection forced competing branch apply' json "$FORCED"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .plan.conflict > 0 and
  any(.actions[]?; .source == "provider:redirection-state/rebuild_redirect_state" and .verified == true)
' <<<"$FORCED" >/dev/null || fail "Redirection forced repository intent did not converge: $FORCED"
redirection_request /summer
[ "$REDIRECTION_CODE" = 302 ] && [ "$REDIRECTION_LOCATION" = "http://localhost:${CONF2_PORT}/summer-marketplace-v2/" ] \
  || fail "Redirection forced repository route is not live (status=$REDIRECTION_CODE location=${REDIRECTION_LOCATION:-<none>})"
[ "$(wp_conf2 option get duo_redirection_target_neighbor)" = target-only-neighbor ] \
  || fail 'Redirection forced conflict crossed the undeclared target option boundary'
pass 'dirty native-rule conflicts refuse atomically; explicit repository authority repairs cache and preserves runtime/neighbor state'

BEFORE_DEACTIVATE=$(observe_redirection conf2)
wp_conf2 plugin deactivate redirection >/dev/null
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_redirection_items' --skip-column-names | tr -d '[:space:]')" = 4 ] \
  || fail 'Redirection deactivation changed authored rules'
REDEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Redirection deploy after deactivation' json "$REDEPLOY"
wp_conf2 plugin is-active redirection >/dev/null || fail 'Duo deploy did not reactivate exact Redirection code'
redirection_request /summer
[ "$REDIRECTION_CODE" = 302 ] && [ "$REDIRECTION_LOCATION" = "http://localhost:${CONF2_PORT}/summer-marketplace-v2/" ] \
  || fail 'Redirection deactivate/deploy cycle did not restore native routing'
[ "$(wp_conf2 option get duo_redirection_target_neighbor)" = target-only-neighbor ] \
  || fail 'Redirection deactivate/deploy cycle crossed the target neighbor'
AFTER_DEACTIVATE=$(observe_redirection conf2)
[ "$(jq -r '.item_count' <<<"$BEFORE_DEACTIVATE")" = "$(jq -r '.item_count' <<<"$AFTER_DEACTIVATE")" ] \
  || fail 'Redirection deactivate/deploy cycle changed authored item cardinality'
pass 'deactivation retains authored/runtime state and deploy reactivation restores exact native routing'

# Unsupported Apache/Nginx module state is injected as repository intent. The
# generic materializer commits it before providers run, so the honest recovery
# contract is durable post-commit intent: no cache/effect verification or
# revision advance, retry authority retained, then exact repair converges.
wp_conf1 db query "UPDATE wp_redirection_groups SET module_id=2 WHERE name='Summer campaign 東京 🚀'" >/dev/null
commit_redirection_source 'conformance: unsupported Redirection server module refusal'
FAIL_CACHE_BEFORE=$(wp_conf2 eval 'echo (int) Red_Options::get()["cache_key"];')
FAIL_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Redirection revision before provider refusal' "$FAIL_REV_BEFORE"
MODULE_RC=0
MODULE_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || MODULE_RC=$?
require_duo_answered 'Redirection unsupported server-module apply' human "$MODULE_OUT"
[ "$MODULE_RC" -ne 0 ] && grep -Fq "provider 'redirection-state' capability 'rebuild_redirect_state' failed" <<<"$MODULE_OUT" \
  || fail "Redirection unsupported server module did not refuse in the provider: $MODULE_OUT"
[ "$(wp_conf2 db query "SELECT module_id FROM wp_redirection_groups WHERE name='Summer campaign 東京 🚀'" --skip-column-names | tr -d '[:space:]')" = 2 ] \
  || fail 'Redirection provider refusal lost the committed repository intent needed for retry'
[ "$(wp_conf2 eval 'echo (int) Red_Options::get()["cache_key"];')" = "$FAIL_CACHE_BEFORE" ] \
  || fail 'Redirection module preflight refusal rotated cache before proving its scope'
[ "$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FAIL_REV_BEFORE" ] \
  || fail 'Redirection provider refusal advanced applied_revision before verified effects'
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'Redirection provider refusal did not retain retry authority'
[ "$(wp_conf2 option get duo_redirection_target_neighbor)" = target-only-neighbor ] \
  || fail 'Redirection provider refusal crossed the target neighbor'
wp_conf1 db query "UPDATE wp_redirection_groups SET module_id=1 WHERE name='Summer campaign 東京 🚀'" >/dev/null
commit_redirection_source 'conformance: repair Redirection module scope'
RECOVERY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Redirection provider-scope recovery apply' json "$RECOVERY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:redirection-state/rebuild_redirect_state" and .verified == true)
' <<<"$RECOVERY" >/dev/null || fail "Redirection provider-scope recovery did not converge: $RECOVERY"
[ "$(wp_conf2 db query "SELECT module_id FROM wp_redirection_groups WHERE name='Summer campaign 東京 🚀'" --skip-column-names | tr -d '[:space:]')" = 1 ] \
  || fail 'Redirection scope repair did not restore the WordPress module'
FINAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Redirection final zero plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "Redirection final plan retained work: $FINAL_PLAN"
pass 'unsupported server modules fail before effects, retain durable intent, and converge idempotently after exact scope repair'
