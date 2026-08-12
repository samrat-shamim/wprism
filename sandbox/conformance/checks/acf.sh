#!/usr/bin/env bash
# ACF render/API-level acceptance (DUO-3223): byte-identical canonical
# state proves the field GROUP/FIELD definitions and the raw postmeta ref
# values round-tripped, but not that ACF's OWN runtime (get_field(), the
# thing every theme/template actually calls) resolves them correctly on a
# FRESH conf2 process using WHATEVER local ids conf2 assigned its posts/
# terms/attachment/user — never conf1's. Exercises every field type
# conformance/seeds/acf.sh authors: image, relationship (multi-post),
# taxonomy (multi-value checkbox + single-value radio), and user.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; $COMPOSE/fail/pass are exported by run.sh itself (matching
# every other checks/*.sh file's convention — see checks/woocommerce.sh).
set -euo pipefail

API_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$content = get_page_by_path("conf-acf-content", OBJECT, "post");
if (!$content) { echo "NO_CONTENT_POST"; exit; }
$id = $content->ID;

$hero = get_field("duo_hero", $id);
$hero_attachment = $hero ? get_post($hero) : null;
$hero_ok = $hero_attachment && $hero_attachment->post_type === "attachment" ? "yes" : "no";

$related = get_field("duo_related", $id);
$related_count = is_array($related) ? count($related) : 0;
$related_titles = [];
foreach ((array) $related as $rid) {
  $p = get_post($rid);
  if ($p) { $related_titles[] = $p->post_title; }
}
sort($related_titles);

$cats = get_field("duo_cats", $id);
$cats_count = is_array($cats) ? count($cats) : 0;
$cat_names = [];
foreach ((array) $cats as $tid) {
  $t = get_term($tid, "category");
  if ($t && !is_wp_error($t)) { $cat_names[] = $t->name; }
}
sort($cat_names);

$cat = get_field("duo_cat", $id);
$cat_term = $cat ? get_term($cat, "category") : null;
$cat_name = $cat_term && !is_wp_error($cat_term) ? $cat_term->name : "";

$owner = get_field("duo_owner", $id);
$owner_user = $owner ? get_user_by("id", $owner) : null;
$owner_login = $owner_user ? $owner_user->user_login : "";

echo implode("|", [
  $hero_ok,
  $related_count,
  implode(",", $related_titles),
  $cats_count,
  implode(",", $cat_names),
  $cat_name,
  $owner_login,
]);
' 2>&1 | tail -1)
require_observed_nonempty "conf2 ACF runtime observation" "$API_OUT"
echo "conf2 ACF field resolution: $API_OUT"

[ "$API_OUT" != "NO_CONTENT_POST" ] || fail "conf2 has no 'conf-acf-content' post — seed content did not round-trip"

IFS='|' read -r HERO_OK RELATED_COUNT RELATED_TITLES CATS_COUNT CAT_NAMES CAT_NAME OWNER_LOGIN <<< "$API_OUT"

[ "$HERO_OK" = "yes" ] || fail "conf2's get_field('duo_hero') did not resolve to a real attachment post (got: $API_OUT)"
[ "$RELATED_COUNT" = "2" ] || fail "conf2's get_field('duo_related') did not resolve to exactly 2 posts (got: $API_OUT)"
[ "$RELATED_TITLES" = "Conformance Related Target One,Conformance Related Target Two" ] \
  || fail "conf2's relationship field did not resolve to the expected two target posts (got: $RELATED_TITLES)"
[ "$CATS_COUNT" = "2" ] || fail "conf2's get_field('duo_cats') (multi-value taxonomy) did not resolve to exactly 2 terms (got: $API_OUT)"
[ "$CAT_NAMES" = "Conformance Category One,Conformance Category Two" ] \
  || fail "conf2's multi-value taxonomy field did not resolve to the expected two categories (got: $CAT_NAMES)"
[ "$CAT_NAME" = "Conformance Category Three" ] \
  || fail "conf2's get_field('duo_cat') (single-value taxonomy) did not resolve to 'Conformance Category Three' (got: '$CAT_NAME')"
[ "$OWNER_LOGIN" = "admin" ] || fail "conf2's get_field('duo_owner') did not resolve to the admin user (got: '$OWNER_LOGIN')"

pass "conf2 resolves all five ACF field types (image, multi-relationship, multi/single taxonomy, user) via ACF's own get_field() API, using conf2's own local ids throughout"
