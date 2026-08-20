#!/usr/bin/env bash
# Regression — DUO-3288: WooCommerce global-attribute deletion remains a
# deliberate, loud refusal until Duo can model Woo's semantic delete.
#
# WooCommerce 11.0.0's wc_delete_attribute() does much more than delete the
# woocommerce_attribute_taxonomies row: it derives pa_<slug>, deletes terms
# through wp_delete_term(), fires hooks, schedules a rewrite flush, and clears
# transient + object caches. Its live reverse references are strings and
# serialized/meta-key shapes, not the attribute row's numeric id. Duo v1's
# scalar-id guards and attached-meta table cascades cannot represent that
# contract safely.
#
# This fresh two-environment product-path proof establishes a real global
# attribute, term, variable product, variation, lookup row, and warm cache;
# then proves:
#   1. source capture refuses the disappearance without publishing a tombstone;
#   2. a syntactically valid hand-authored tombstone is refused offline by
#      plan and apply --with-deletes (even with the reference-force flag);
#   3. the target's definition, taxonomy, terms, product refs, lookup row,
#      cache, and rewrite schedule remain byte-for-byte untouched; and
#   4. restoring the supported live tree leaves plan/capture converged.
#
# The pair is destroyed only when every assertion is green. A failed run leaves
# it available for inspection, matching the sandbox certification convention.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

PAIR="${WOOATTRDEL_PAIR:-wooattrdel}"
PORT1="${WOOATTRDEL_PORT1:-8996}"
PORT2="${WOOATTRDEL_PORT2:-8997}"
COMPOSE="docker compose -p duo-$PAIR -f pair.yml"
export DUO_PAIR="$PAIR"

wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp "$@"; }
repo_host() { bash bin/pair.sh repo-host "$PAIR" "$1" >/dev/null; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

HOST1="siterepo/${PAIR}1"
HOST2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"
GIT1=(git -C "$HOST1" -c user.name=duo-a -c user.email=a@example.test)

say "clean-room pair + exact WooCommerce 11.0.0"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
wp1 plugin install woocommerce --version=11.0.0 --activate >/dev/null
wp2 plugin install woocommerce --version=11.0.0 --activate >/dev/null
[ "$(wp1 plugin get woocommerce --field=version)" = "11.0.0" ] || fail "source WooCommerce version is not 11.0.0"
[ "$(wp2 plugin get woocommerce --field=version)" = "11.0.0" ] || fail "target WooCommerce version is not 11.0.0"
pass "both isolated environments run WooCommerce 11.0.0"

say "initialize a Woo-scoped site repo"
git init --bare -q -b main "$ORIGIN"
cat > "$HOST1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "$HOST1/.gitignore"
git -C "$HOST1" init -q -b main
git -C "$HOST1" remote add origin "../origin-${PAIR}.git"

say "seed a real global attribute, term, variable product, variation, and lookup row on source"
ATTR1=$(wp1 wc product_attribute create --name='Duo Delete Probe' --slug=duo-delete-probe --type=select --order_by=menu_order --has_archives=false --user=admin --porcelain)
wp1 wc product_attribute_term create "$ATTR1" --name=Red --slug=red --user=admin >/dev/null
PRODUCT1=$(wp1 wc product create --name='Attribute Delete Probe Product' --type=variable \
  --attributes="[{\"id\":$ATTR1,\"variation\":true,\"visible\":true,\"options\":[\"Red\"]}]" \
  --status=publish --user=admin --porcelain)
wp1 wc product_variation create "$PRODUCT1" \
  --attributes="[{\"id\":$ATTR1,\"option\":\"Red\"}]" \
  --regular_price=19.99 --sku=DUO-ATTR-DELETE-PROBE --user=admin >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
# Capture writes as uid 33. Return this exact root before host Git indexes the
# resulting tree; the handback also makes later target backup removal writable.
repo_host 1
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "baseline: Woo attribute deletion refusal fixture"
"${GIT1[@]}" push -qu origin main

git clone -q "$ORIGIN" "$HOST2"
# The host clone recreates canonical state with host umask (directories 0755).
# Normalize this exact peer root before target apply/recapture can rotate that
# tree into state.capture-backup and ask uid 33 to remove its descendants.
repo_host 2
# WordPress/Woo activation creates the same starter posts and terms on both
# sides independently. Remove target copies before apply so this deletion
# regression does not need an unrelated adopt flow (and therefore cannot hide
# an unexpected warning among routine adoption notices).
wp2 db query '
DELETE FROM wp_commentmeta;
DELETE FROM wp_comments;
DELETE FROM wp_term_relationships;
DELETE FROM wp_termmeta;
DELETE FROM wp_term_taxonomy;
DELETE FROM wp_terms;
DELETE FROM wp_postmeta;
DELETE FROM wp_posts;
' >/dev/null
APPLY_BASE=$(wp2 duo apply --repo=/siterepo --default-author=admin --format=json | tail -1)
# DUO-3338 replaced the free-form `rebuilders` command strings with structured
# actions, so the per-declaration confirmation lines Apply::rebuild() emits are
# now the action's closed identity plus its value-level verification, not the
# command text and an exit code. Both are pinned byte-exactly here, and the
# count still bounds the whole set so an extra unexpected warning fails.
#
# The currently shipped provider-owned per-entity product-lookup repair joins
# the native transient deletion and Woo cache invalidation. These three closed
# identities are the complete action set for this move. The count remains
# exact so an unexpected extra warning still fails.
echo "$APPLY_BASE" | jq -e '
  (.warnings | length) == 3 and
  (.warnings | any(. == "native action fired: transient.delete (verified)")) and
  (.warnings | any(test("^provider capability fired: woocommerce-cache@1\\.0\\.0 invalidate_cache_groups \\([0-9.]+s, verified\\)$"))) and
  (.warnings | any(test("^provider capability fired: woocommerce-product-lookups@1\\.0\\.0 rebuild_product_lookups \\([0-9.]+s, verified\\)$")))
' >/dev/null || fail "baseline apply did not emit exactly the three required successful Woo cache/projection action notices: $APPLY_BASE"
PRODUCT2=$(wp2 post list --post_type=product --name=attribute-delete-probe-product --field=ID)
VARIATION2=$(wp2 post list --post_type=product_variation --post_parent="$PRODUCT2" --field=ID)
[ -n "$PRODUCT2" ] && [ -n "$VARIATION2" ] || fail "variable product/variation did not converge on target"
wp2 eval 'wc_get_container()->get(Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator::class)->initiate_regeneration();' >/dev/null
wp2 action-scheduler run >/dev/null
wp2 eval 'wc_get_attribute_taxonomies(); wp_clear_scheduled_hook("woocommerce_flush_rewrite_rules");' >/dev/null
pass "target converged and its lookup/cache fixtures are live"

target_facts() {
  wp2 eval "
global \$wpdb;
\$taxonomy = 'pa_duo-delete-probe';
\$tt = (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1\", \$taxonomy));
\$attrs = wc_get_attribute_taxonomies();
\$names = array_map(static fn(\$a) => \$a->attribute_name, \$attrs);
sort(\$names, SORT_STRING);
echo wp_json_encode([
  'attribute_row' => (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name='duo-delete-probe'\"),
  'taxonomy_exists' => taxonomy_exists(\$taxonomy),
  'term_taxonomy' => (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->term_taxonomy} WHERE taxonomy = %s\", \$taxonomy)),
  'terms' => (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->terms} t JOIN {\$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy = %s\", \$taxonomy)),
  'relationships' => \$tt ? (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->term_relationships} WHERE term_taxonomy_id = %d\", \$tt)) : 0,
  'product_meta' => (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE post_id = %d AND meta_key='_product_attributes' AND meta_value LIKE '%%pa_duo-delete-probe%%'\", $PRODUCT2)),
  'variation_meta' => (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->postmeta} WHERE post_id = %d AND meta_key='attribute_pa_duo-delete-probe'\", $VARIATION2)),
  'lookup_rows' => (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}wc_product_attributes_lookup WHERE taxonomy = %s\", \$taxonomy)),
  'cached_names' => \$names,
  'rewrite_event' => (bool) wp_next_scheduled('woocommerce_flush_rewrite_rules'),
]);
" 2>/dev/null | tail -1
}

BEFORE=$(target_facts)
echo "$BEFORE" | jq .
echo "$BEFORE" | jq -e '
  .attribute_row == 1 and .taxonomy_exists == true and
  .term_taxonomy == 1 and .terms == 1 and .relationships >= 1 and
  .product_meta == 1 and .variation_meta == 1 and .lookup_rows >= 1 and
  (.cached_names | index("duo-delete-probe")) != null and
  .rewrite_event == false
' >/dev/null || fail "target fixture is incomplete before the refusal checks: $BEFORE"

say "source definition-row disappearance must refuse the exact table selector without publishing"
# Use the narrow row disappearance that originally exposed DUO-3288. Calling
# wc_delete_attribute() here would correctly delete the pa_* term too, causing
# capture to encounter (and refuse) term:pa_duo-delete-probe before it reaches
# the table entity. Woo's broader API effects are characterized separately in
# the manifest/spec evidence; this assertion is intentionally selector-exact.
wp1 db query "DELETE FROM wp_woocommerce_attribute_taxonomies WHERE attribute_id=$ATTR1" >/dev/null
wp1 transient delete wc_attribute_taxonomies >/dev/null
set +e
CAPTURE_ERR=$(wp1 duo capture --repo=/siterepo 2>&1)
CAPTURE_RC=$?
set -e
[ "$CAPTURE_RC" -ne 0 ] || fail "capture accepted unsupported Woo attribute deletion"
grep -Fq 'deletion intent for table:woocommerce_attribute_taxonomies is unsupported' <<<"$CAPTURE_ERR" \
  || fail "capture refusal did not name the exact unsupported selector: $CAPTURE_ERR"
[ ! -d "$HOST1/state/deletions" ] || [ -z "$(find "$HOST1/state/deletions" -type f -name '*.json' -print -quit)" ] \
  || fail "failed capture published a deletion tombstone"
[ -z "$(git -C "$HOST1" status --porcelain)" ] || fail "failed capture changed the published repository tree"
pass "capture refused atomically and emitted no tombstone"

say "hand-authored intent still fails offline in plan and apply --with-deletes"
ATTR_FILE=$(find "$HOST2/state/tables/woocommerce_attribute_taxonomies" -type f -name '*.json' -print -quit)
[ -n "$ATTR_FILE" ] || fail "target repository attribute entity file missing"
UUID=$(jq -r '.uuid' "$ATTR_FILE")
EXPECTED_HASH=$(shasum -a 256 "$ATTR_FILE" | awk '{print $1}')
EXPECTED_REVISION=$(wp2 eval 'echo \Duo\RepositoryCompiler::compile("/siterepo", \Duo\Policy::load("/siterepo"))->revision_hash();')
SOURCE_PATH="tables/woocommerce_attribute_taxonomies/$(basename "$ATTR_FILE")"
BACKUP="$HOST2/.tmp-unsupported-attribute.json"
mkdir -p "$HOST2/state/deletions"
mv "$ATTR_FILE" "$BACKUP"
jq -n \
  --arg expected_hash "$EXPECTED_HASH" \
  --arg expected_revision "$EXPECTED_REVISION" \
  --arg source_path "$SOURCE_PATH" \
  --arg uuid "$UUID" \
  '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"duo-deletion/v1",kind:"table",source_path:$source_path,type:"woocommerce_attribute_taxonomies",uuid:$uuid}' \
  > "$HOST2/state/deletions/$UUID.json"

set +e
PLAN_ERR=$(wp2 duo plan --repo=/siterepo --format=json 2>&1)
PLAN_RC=$?
APPLY_ERR=$(wp2 duo apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin 2>&1)
APPLY_RC=$?
set -e
[ "$PLAN_RC" -ne 0 ] || fail "plan accepted a hand-authored unsupported tombstone"
[ "$APPLY_RC" -ne 0 ] || fail "apply --with-deletes accepted a hand-authored unsupported tombstone"
grep -Fq 'deletion intent for table:woocommerce_attribute_taxonomies is unsupported' <<<"$PLAN_ERR" \
  || fail "plan refusal did not name the exact unsupported selector: $PLAN_ERR"
grep -Fq 'deletion intent for table:woocommerce_attribute_taxonomies is unsupported' <<<"$APPLY_ERR" \
  || fail "apply refusal did not name the exact unsupported selector: $APPLY_ERR"
pass "offline compiler blocks plan/apply; --with-deletes and force cannot bypass missing capability"

AFTER=$(target_facts)
[ "$AFTER" = "$BEFORE" ] || fail "target state changed across refused plan/apply\nbefore=$BEFORE\nafter=$AFTER"
pass "definition, taxonomy, terms, refs, lookup, cache, and rewrite schedule stayed untouched"

say "restore the supported tree and prove plan/capture convergence"
rm "$HOST2/state/deletions/$UUID.json"
rmdir "$HOST2/state/deletions"
mv "$BACKUP" "$ATTR_FILE"
PLAN_OK=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_OK" | jq -e '
  (.create | length) == 0 and (.update | length) == 0 and
  (.drift | length) == 0 and (.conflict | length) == 0 and
  (.delete | length) == 0 and (.delete_conflict | length) == 0 and
  (.warnings | length) == 0
' >/dev/null || fail "restored target did not plan cleanly: $PLAN_OK"
wp2 duo capture --repo=/siterepo >/dev/null
[ -z "$(git -C "$HOST2" status --porcelain)" ] || fail "target recapture changed the converged repository tree"
pass "restored supported state plans and recaptures with zero diff"

bash bin/pair.sh destroy "$PAIR"
pass "DUO-3288 regression complete; isolated pair destroyed"
