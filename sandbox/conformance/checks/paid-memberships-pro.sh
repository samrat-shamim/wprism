#!/usr/bin/env bash
# Paid Memberships Pro render/API-level acceptance (DUO-3223): byte-
# identical canonical state proves task #125's composite-PK restriction
# row (pmpro_memberships_pages: membership_id, page_id — no surrogate id
# column) and task #126's levelmeta sidecar (id_column=meta_id override)
# round-tripped as raw bytes, but not that PMPro's OWN runtime access-
# control (pmpro_has_membership_access(), the function every theme/
# content-gate actually calls) resolves the restriction correctly on a
# FRESH conf2 process using conf2's own level_id/page_id — never conf1's.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; $COMPOSE/fail/pass are exported by run.sh itself (matching
# every other checks/*.sh file's convention — see checks/woocommerce.sh).
set -euo pipefail

API_OUT=$($COMPOSE run --rm -T cli2 wp eval '
global $wpdb;

$page = get_page_by_path("conformance-members-only", OBJECT, "page");
if (!$page) { echo "NO_PAGE"; exit; }
$page_id = $page->ID;

$level = $wpdb->get_row($wpdb->prepare(
  "SELECT id, name, initial_payment FROM {$wpdb->pmpro_membership_levels} WHERE name = %s",
  "Conformance Test Level"
));
if (!$level) { echo "NO_LEVEL"; exit; }
$level_id = (int) $level->id;

$meta_value = get_pmpro_membership_level_meta($level_id, "membership_account_message", true);

// The REAL access-control function every PMPro-gated theme template calls
// (PMPro'"'"'s own public API, pmpro-functions.php) -- not a raw table read.
// With no logged-in user (default here), a restricted page must report NO
// access, and must name exactly this conformance level as what WOULD grant
// it -- proving the restriction resolved via conf2'"'"'s own level_id/page_id,
// not a stale reference to conf1'"'"'s.
list($has_access, $lowest_level_ids) = pmpro_has_membership_access($page_id, 0, true);

echo implode("|", [
  $level->name,
  $level->initial_payment,
  $meta_value,
  $has_access ? "yes" : "no",
  is_array($lowest_level_ids) ? implode(",", $lowest_level_ids) : "",
  $level_id,
]);
' 2>&1 | tail -1)
require_observed_nonempty "conf2 PMPro runtime observation" "$API_OUT"
echo "conf2 PMPro resolution: $API_OUT"

[ "$API_OUT" != "NO_PAGE" ] || fail "conf2 has no 'conformance-members-only' page — seed content did not round-trip"
[ "$API_OUT" != "NO_LEVEL" ] || fail "conf2 has no 'Conformance Test Level' membership level — seed content did not round-trip"

IFS='|' read -r LEVEL_NAME LEVEL_PRICE LEVEL_META HAS_ACCESS LOWEST_IDS LEVEL_ID <<< "$API_OUT"

[ "$LEVEL_NAME" = "Conformance Test Level" ] || fail "conf2's membership level name mismatch (got: '$LEVEL_NAME')"
[ "$LEVEL_PRICE" = "9.99000000" ] \
  || fail "conf2's membership level initial_payment mismatch (got: '$LEVEL_PRICE', expected 9.99000000)"
[ "$LEVEL_META" = "DUO-3239 conformance levelmeta marker" ] \
  || fail "conf2's get_pmpro_membership_level_meta() did not resolve the task #126 levelmeta marker (got: '$LEVEL_META') -- id_column=meta_id sidecar override did not round-trip"
[ "$HAS_ACCESS" = "no" ] \
  || fail "pmpro_has_membership_access() reports an anonymous visitor (user 0) HAS access to the restricted page on conf2 -- restriction did not apply (got: $API_OUT)"
[ "$LOWEST_IDS" = "$LEVEL_ID" ] \
  || fail "pmpro_has_membership_access() did not name conf2's own level_id ($LEVEL_ID) as the level that would grant access (got: '$LOWEST_IDS') -- composite-PK restriction row did not resolve to conf2's own ids"

pass "conf2's PMPro runtime (pmpro_has_membership_access, get_pmpro_membership_level_meta) correctly denies an anonymous visitor and names conf2's own membership level, all using conf2's own local ids"
