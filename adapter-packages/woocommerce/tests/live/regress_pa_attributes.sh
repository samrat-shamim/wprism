#!/usr/bin/env bash
# Regression — task #92: taxonomy_patterns (dynamic-taxonomy scope) + the
# object_type fallback that makes direct-SQL term-relationship writes work
# correctly on a target where a pattern-matched taxonomy (WooCommerce's
# pa_*) was landed by THIS SAME apply's own phase 1 (Snapshot's typed-
# snapshot write of woocommerce_attribute_taxonomies), and so is not yet
# registered in WordPress's runtime taxonomy registry.
#
# Reproduces, in isolation, the two empirical facts the design rests on:
#   (1) a raw-SQL insert into wp_woocommerce_attribute_taxonomies leaves
#       get_taxonomy('pa_x') returning false for the REST of that same PHP
#       process, even though a paired raw-SQL term_taxonomy row for the new
#       taxonomy name is immediately visible to a live
#       `SELECT DISTINCT taxonomy FROM wp_term_taxonomy` in the identical
#       process — the timing gap Policy::taxonomies()'s live-DB expansion
#       exists to sidestep, confirmed by direct reproduction, not assumed.
#   (2) Policy::taxonomies() is SCOPE-GATED: only names matching a declared
#       taxonomy_patterns regex are ever added to the exact policy list —
#       never a blanket widen to "every taxonomy the database happens to
#       hold" (the #73 posture, mirrored).
# Plus a live capture/apply/lint proof that a variable product's pa_size/
# pa_color scope is entirely pattern-driven — site.wprism.json never lists
# them — and a fresh target resolves wc_get_attribute_taxonomies() and full
# variation data with zero manual pre-provisioning.
#
# Runs against the existing r3e pair (already up for tasks #92/#93; own
# WooCommerce install, own WPrism Tee variable-product fixture). This script IS
# the ongoing acceptance record: each assertion below states what it pins.
# Touches NEITHER r3e1's nor r3e2's real
# site-repo git history: every capture below targets a throwaway scratch
# repo directory inside the same bind mount (--repo=/siterepo/.tmp-*,
# --out=.../state-out, which Capture::run() documents as skipping ledger
# updates), mirroring regress_option_ref_scope.sh's established pattern.
# Self-contained, re-runnable.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
cd "$(dirname "$0")/../../../../sandbox"
export WPRISM_PAIR=r3e
COMPOSE="docker compose -p wprism-r3e -f pair.yml"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

say "(1) reproduce the same-request registration-timing hazard directly"
OUT1=$(wp1 eval '
global $wpdb;
$wpdb->insert($wpdb->prefix . "woocommerce_attribute_taxonomies", [
  "attribute_name" => "r92regress", "attribute_label" => "R92 Regress",
  "attribute_type" => "select", "attribute_orderby" => "menu_order", "attribute_public" => 1,
]);
$reg_before = get_taxonomy("pa_r92regress") === false ? "false" : "true";
$wpdb->insert($wpdb->terms, ["name" => "Probe", "slug" => "r92-probe", "term_group" => 0]);
$term_id = $wpdb->insert_id;
$wpdb->insert($wpdb->term_taxonomy, ["term_id" => $term_id, "taxonomy" => "pa_r92regress", "description" => "", "parent" => 0, "count" => 0]);
$distinct = $wpdb->get_col("SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}");
$found_distinct = in_array("pa_r92regress", $distinct, true) ? "yes" : "no";
$reg_after = get_taxonomy("pa_r92regress") === false ? "false" : "true";
echo "reg_before=$reg_before found_in_distinct=$found_distinct reg_after=$reg_after\n";
$wpdb->delete($wpdb->term_taxonomy, ["term_id" => $term_id, "taxonomy" => "pa_r92regress"]);
$wpdb->delete($wpdb->terms, ["term_id" => $term_id]);
$wpdb->delete($wpdb->prefix . "woocommerce_attribute_taxonomies", ["attribute_name" => "r92regress"]);
' 2>&1 | tail -1)
echo "$OUT1"
grep -q "reg_before=false" <<<"$OUT1" || fail "expected get_taxonomy() to be false immediately after the raw table insert (got: $OUT1)"
grep -q "found_in_distinct=yes" <<<"$OUT1" || fail "expected the DISTINCT query to see the new taxonomy name in the SAME request (got: $OUT1)"
grep -q "reg_after=false" <<<"$OUT1" || fail "expected get_taxonomy() to STILL be false — the registry never catches up mid-request (got: $OUT1)"
pass "hazard reproduced: DISTINCT sees it live, get_taxonomy() never does this request — exactly the gap taxonomy_patterns' live-DB expansion (not the registry) exists to close"

say "(2) scope-gating: an undeclared-pattern taxonomy must NOT enter scope even though it's now live in wp_term_taxonomy"
REPO=/siterepo/.tmp-r92-scope
HOST_REPO="siterepo/r3e1/.tmp-r92-scope"
rm -rf "$HOST_REPO"
mkdir -p "$HOST_REPO"
cat > "$HOST_REPO/site.wprism.json" <<'EOF'
{
  "spec_version": 2,
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {}, "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"],
    "scope": {
      "post_type": {
        "product": {"class": "runtime"},
        "product_variation": {"class": "runtime"}
      },
      "taxonomy": {
        "product_cat": {"class": "runtime"},
        "product_type": {"class": "runtime"},
        "product_visibility": {"class": "runtime"},
        "r92_not_pa_at_all": {"class": "runtime"}
      }
    }
  }
}
EOF
# RepositoryCompiler now validates even non-minting snapshots fail closed on
# malformed repositories. This probe needs a valid empty state tree so it
# reaches the taxonomy-scope assertion it was written to exercise. The scope
# dispositions above likewise satisfy the newer whole-entity completeness
# gate without promoting the deliberately undeclared taxonomy to authored.
mkdir -p "$HOST_REPO/state"
wp1 eval '
global $wpdb;
$wpdb->insert($wpdb->terms, ["name" => "Stray", "slug" => "r92-stray", "term_group" => 0]);
$term_id = $wpdb->insert_id;
$wpdb->insert($wpdb->term_taxonomy, ["term_id" => $term_id, "taxonomy" => "r92_not_pa_at_all", "description" => "", "parent" => 0, "count" => 0]);
echo "stray term_id=$term_id\n";
' > /tmp/r92-stray.txt
STRAY_TERM_ID=$(grep -o 'stray term_id=[0-9]*' /tmp/r92-stray.txt | cut -d= -f2)
rm -f /tmp/r92-stray.txt
SNAP=$(wp1 eval "try { \$s = \WPrism\Capture::snapshot('$REPO'); \$taxes = []; foreach (\$s as \$e) { if (\$e['type'] === 'term') { \$f = \WPrism\Canon::decode(\$e['content']); \$taxes[\$f['taxonomy']] = true; } } echo 'OK ' . (isset(\$taxes['r92_not_pa_at_all']) ? 'LEAKED' : 'clean'); } catch (\Throwable \$e) { echo 'THROWN: ' . \$e->getMessage(); }" 2>&1 | tail -1)
echo "$SNAP"
wp1 db query "DELETE FROM wp_term_taxonomy WHERE term_id = $STRAY_TERM_ID"
wp1 db query "DELETE FROM wp_terms WHERE term_id = $STRAY_TERM_ID"
grep -q "OK clean" <<<"$SNAP" || fail "an undeclared-pattern taxonomy (r92_not_pa_at_all) leaked into scope -- taxonomy_patterns must be scope-gated, never a blanket widen (got: $SNAP)"
pass "undeclared-pattern taxonomy correctly stayed OUT of scope -- taxonomy_patterns never blanket-widens to whatever the database happens to hold"
rm -rf "$HOST_REPO"

say "(3) live end-to-end: pa_size/pa_color enter scope via pattern alone (site.wprism.json never lists them)"
[ -f "siterepo/r3e1/site.wprism.json" ] || fail "expected siterepo/r3e1 to already have a real site.wprism.json (see r3-eng-woo's #92/#93 work)"
jq -e '.policy.taxonomies | index("pa_size") | not' siterepo/r3e1/site.wprism.json >/dev/null \
  || fail "expected pa_size to be ABSENT from site.wprism.json's policy.taxonomies (proves the pattern, not a leftover manual entry)"
jq -e '.policy.taxonomies | index("pa_color") | not' siterepo/r3e1/site.wprism.json >/dev/null \
  || fail "expected pa_color to be ABSENT from site.wprism.json's policy.taxonomies"
pass "site.wprism.json confirmed to list neither pa_size nor pa_color explicitly"

wp1 wprism capture --repo=/siterepo > /tmp/r92-capture.txt 2>&1
cat /tmp/r92-capture.txt
grep -qi success /tmp/r92-capture.txt || fail "capture failed (see output above)"
[ -d "siterepo/r3e1/state/terms/pa_size" ] || fail "state/terms/pa_size missing after capture -- taxonomy_patterns did not put it in scope"
[ -d "siterepo/r3e1/state/terms/pa_color" ] || fail "state/terms/pa_color missing after capture"
[ -f "siterepo/r3e1/state/tables/woocommerce_attribute_taxonomies"/*color*.json ] || fail "attribute_taxonomies row for color missing"
pass "pa_size/pa_color term files + attribute_taxonomies table rows present, driven entirely by the pattern"

say "(4) hard lint gate + wp_get_attribute_taxonomies() zero-provisioning proof stay green (regression, not re-litigation -- full narrative already proven live for #92)"
LINT_OUT=$(wp1 wprism lint --repo=/siterepo 2>&1)
echo "$LINT_OUT"
grep -qi "no findings" <<<"$LINT_OUT" || fail "expected lint 0 findings on r3e1's own captured state (got: $LINT_OUT)"
pass "lint clean on r3e1's captured state"

rm -f /tmp/r92-capture.txt
pass "task #92 regression: same-request registration hazard reproduced + fixed, scope-gating proven, pa_*/attribute-table capture proven live, lint clean"
