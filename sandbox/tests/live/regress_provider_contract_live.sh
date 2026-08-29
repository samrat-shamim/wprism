#!/usr/bin/env bash
# Regression — issue #3338, live pair: a CUSTOM plugin implements and advertises
# its OWN provider, and WPrism negotiates, invokes, and verifies it end to end
# through the ordinary `wp wprism` product path.
#
# Pair "claudemacb3338", ports 8930/8931 (owned by this script; headless — no
# render checks, pure wp-cli). Code-bound to the sandbox agency fixture plugin
# via pair.sh's --codebind mode (sandbox/pair.codebind.yml): the plugin is
# authored into the site repo's own code/ tree BEFORE the containers are
# created, then travels to the second environment through git + `wprism deploy`,
# never a direct `wp plugin install` on the target.
#
# The whole point of this file is the half no offline harness can reach. The
# grammar, negotiation refusals, receipts, and timeout budget are covered by
# sandbox/tests/offline/adapter/regress_actions_providers.sh against fake providers; here the
# provider is real plugin code, the data is real target data, and the ids are
# environment-local — which is what makes the index assertion meaningful.
#
# What it proves, in order:
#
#  (1) A plugin nobody in the engine knows about registers a provider on the
#      `wprism_providers` filter and WPrism finds it BY ITS OWN DECLARED IDENTITY.
#      The declaring manifest pins no plugin/version_range at all, so this also
#      exercises the documented path where negotiation checks installed +
#      active and skips the range comparison.
#  (2) The provider repairs generated data the engine could not have repaired:
#      wprism_agency_project_index is rebuilt on conf2 from conf2's OWN project
#      ids. WPrism wrote those rows with direct SQL and fired no save_post, so a
#      correct index there is only possible if the capability actually ran
#      against target data.
#  (3) The engine's own closed native action clears a plugin's stale WordPress
#      transient, and both declarations report back per-declaration receipts
#      (issue #3282's unconditional confirmation lines plus the issue #3338
#      machine-readable `actions` array).
#  (4) FAIL BEFORE MUTATION: with the owning plugin deactivated, apply refuses
#      before it writes anything, names the provider and its plugin, carries a
#      remediation, and leaves conf2 byte-for-byte unchanged. The refusal is
#      NOT forceable: this run passes --force-code-mismatch, which gets past
#      the lifecycle gate and still cannot get past the capability gate.
#  (5) Recovery: `wprism deploy` reactivates the plugin and the identical apply
#      converges, re-firing the same idempotent capability.
#
# The pair is destroyed when every assertion is green; a failed run leaves it
# up for inspection, matching the sandbox certification convention.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR="${PROVIDER_CONTRACT_PAIR:-claudemacb3338}"
PORT1="${PROVIDER_CONTRACT_PORT1:-8930}"
PORT2="${PROVIDER_CONTRACT_PORT2:-8931}"
PLUGIN_DIR=wprism-agency-cpt
PLUGIN_BASENAME="$PLUGIN_DIR/$PLUGIN_DIR.php"
PLUGIN_FILE="code/wp-content/plugins/$PLUGIN_DIR/$PLUGIN_DIR.php"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN="$PLUGIN_DIR"
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.codebind.yml)
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=provider-probe-a -c user.email=a1@example.test)
GIT2=(git -C "siterepo/${PAIR}2" -c user.name=provider-probe-b -c user.email=a2@example.test)

GREEN=0
cleanup() {
  if [ "$GREEN" = 1 ]; then
    bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  else
    printf '\033[1;33m(pair %s left up for inspection after a failed run)\033[0m\n' "$PAIR" >&2
  fi
}
trap cleanup EXIT

# conf2's own published project ids, ascending — the environment-local truth
# every index assertion below is compared against.
project_ids_2() {
  wp2 post list --post_type=project --post_status=publish --orderby=ID --order=ASC --format=ids | tr -d '\r'
}
ids_json_2() {
  # shellcheck disable=SC2046  # word splitting is the point: ids -> JSON array
  jq -cn '$ARGS.positional | map(tonumber)' --args $(project_ids_2)
}

# ============================================================ scaffold

say "clean-room site repositories, with the fixture plugin authored before the code-bind containers are created"
# destroy-then-implicit-up rather than reset: a prior FAILED run leaves this
# pair up with its codebind mount pinned (deliberately, for inspection), and
# pair.sh reset refuses a codebind-pinned pair by design — its own message
# prescribes exactly this destroy. A destroy of a nonexistent pair is a no-op,
# so the clean first run is unaffected.
bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
# The destroy leaves site-repo trees on disk by design; this script owns this
# pair's two trees and re-scaffolds them from scratch, so clear them the way
# reset used to (a stale .git here breaks the git-init/remote-add below).
rm -rf "siterepo/${PAIR}1" "siterepo/${PAIR}2"
rm -rf "siterepo/origin-$PAIR.git"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1/code/wp-content/plugins/$PLUGIN_DIR"
cp "fixtures/$PLUGIN_DIR/$PLUGIN_DIR.php" "siterepo/${PAIR}1/$PLUGIN_FILE"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "wprism-agency-cpt"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "project"],
    "taxonomies": ["category"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
git -C "siterepo/${PAIR}1" init -q -b main
git -C "siterepo/${PAIR}1" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "init: wprism-agency-cpt in code/ + site.wprism.json pinning the wprism-agency-cpt manifest"
git -C "siterepo/${PAIR}1" push -qu origin main
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --codebind "$PLUGIN_DIR" --headless
pass "pair $PAIR up (headless, code-bound to $PLUGIN_DIR); both site repos hold the same commit"

say "conf1 activates the fixture plugin for real; conf2 stays inactive and will receive it through deploy alone"
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 plugin activate "$PLUGIN_DIR" >/dev/null
wp1 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "$PLUGIN_DIR did not activate on conf1"
wp2 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  && fail "$PLUGIN_DIR is already active on conf2 — it must arrive via deploy, not a local install"
pass "conf1 active, conf2 inactive"

# ============================================================ (1) seed + capture

say "seed conf1 with projects; the plugin's OWN save_post hook builds the generated index"
wp1 post create --post_type=project --post_status=publish --post_title="Harbour Rebrand" --porcelain >/dev/null
wp1 post create --post_type=project --post_status=publish --post_title="Meridian Storefront" --porcelain >/dev/null
wp1 post create --post_type=project --post_status=publish --post_title="Northwind Report" --porcelain >/dev/null
IDS1=$(wp1 post list --post_type=project --post_status=publish --orderby=ID --order=ASC --format=ids | tr -d '\r')
IDS1_JSON=$(jq -cn '$ARGS.positional | map(tonumber)' --args $IDS1)
INDEX1=$(wp1 option get wprism_agency_project_index --format=json | tail -1)
echo "conf1 project ids: $IDS1"
echo "conf1 index: $INDEX1"
jq -e --argjson ids "$IDS1_JSON" '.ids == $ids and (.titles | length) == 3' <<<"$INDEX1" >/dev/null \
  || fail "conf1's own hook-built index does not describe conf1's projects (got: $INDEX1)"
pass "conf1's generated index is correct under the ordinary hook path"

say "capture conf1 and push; the generated index must NOT travel as canonical state"
wp1 wprism capture --repo=/siterepo >/dev/null
grep -q wprism_agency_project_index "siterepo/${PAIR}1/state/options/core.json" \
  && fail "the derived project index leaked into canonical state — it is classified 'derived' precisely so it cannot"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: three projects on conf1"
"${GIT1[@]}" push -q origin main
pass "conf1 captured; the derived index stayed target-local"

# ============================================================ (2)(3) deploy + apply

say "conf2: deploy activates the plugin (so its provider can register at all), then a stale project cache is warmed to stand in for conf2's own pre-promotion runtime state"
"${GIT2[@]}" pull -q origin main
DEPLOY_JSON=$(wp2 wprism deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e --arg p "$PLUGIN_BASENAME" '.activated | any(. == $p)' >/dev/null \
  || fail "deploy did not activate $PLUGIN_BASENAME on conf2 (got: $DEPLOY_JSON)"
wp2 eval 'wprism_agency_cpt_store_project_index();' >/dev/null
STALE_CACHE=$(wp2 eval 'var_export(get_transient("wprism_agency_project_cache"));' | tr -d '\r' | tail -1)
[ "$STALE_CACHE" != "false" ] || fail "could not warm conf2's project cache transient before apply"
echo "conf2 stale cache before apply: $STALE_CACHE"
pass "conf2 has the plugin active and a warm, now-stale project cache"

say "conf2: apply — the provider capability and the native action both fire during the rebuild pass"
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
APPLY_JSON=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --format=json | tail -1)
echo "$APPLY_JSON" | jq . >/dev/null 2>&1 || fail "apply did not emit JSON: $APPLY_JSON"
echo "$APPLY_JSON" | jq -e '.canary == "clean"' >/dev/null || fail "apply canary was not clean: $APPLY_JSON"

echo "$APPLY_JSON" | jq -e '
  .warnings | any(test("^provider capability fired: wprism-agency-index@1\\.0\\.0 rebuild_project_index \\([0-9.]+s, verified\\)$"))
' >/dev/null || fail "apply printed no provider confirmation line for wprism-agency-index: $(echo "$APPLY_JSON" | jq -c .warnings)"
echo "$APPLY_JSON" | jq -e '
  .warnings | any(. == "native action fired: transient.delete (verified)")
' >/dev/null || fail "apply printed no native confirmation line for transient.delete: $(echo "$APPLY_JSON" | jq -c .warnings)"
pass "both per-declaration confirmation lines were printed with the provider identity and its verification"

IDS2=$(project_ids_2)
IDS2_JSON=$(ids_json_2)
echo "conf2 project ids: $IDS2"
echo "$APPLY_JSON" | jq -e --argjson ids "$IDS2_JSON" '
  (.actions | length) == 2
  and (.actions | any(
        .kind == "provider"
        and .source == "provider:wprism-agency-index/rebuild_project_index"
        and .manifest == "wprism-agency-cpt"
        and .provider_version == "1.0.0"
        and .verified == true
        and (.after.ids == $ids)))
  and (.actions | any(
        .kind == "native"
        and .source == "native:transient.delete"
        and .manifest == "wprism-agency-cpt"
        and .verified == true
        and .before.value_row == true
        and .after.value_row == false))
' >/dev/null || fail "apply's machine-readable action receipts are not the expected shape: $(echo "$APPLY_JSON" | jq -c .actions)"
pass "structured receipts carry the provider identity, the observed after-state, and the transient's before/after rows"

say "(2) conf2's generated index describes CONF2's own project ids, not conf1's"
INDEX2=$(wp2 option get wprism_agency_project_index --format=json | tail -1)
echo "conf2 index: $INDEX2"
jq -e --argjson ids "$IDS2_JSON" '.ids == $ids and (.titles | length) == 3' <<<"$INDEX2" >/dev/null \
  || fail "conf2's index does not describe conf2's own projects (index: $INDEX2, ids: $IDS2_JSON)"
jq -e '.ids | length == 3' <<<"$INDEX2" >/dev/null \
  || fail "conf2's index was not rebuilt at all — it still holds the pre-apply (empty) projection"
if [ "$IDS1" != "$IDS2" ]; then
  jq -e --argjson conf1 "$IDS1_JSON" '.ids != $conf1' <<<"$INDEX2" >/dev/null \
    || fail "conf2's index holds CONF1's ids — the index travelled as data instead of being recomputed on the target"
  pass "conf2 minted different local ids than conf1 and its index holds conf2's, so the capability demonstrably ran on target data"
else
  echo "(informational: conf1 and conf2 happened to mint identical local ids this run, so the ids-differ half of this proof is vacuous; the index equality above still holds)"
  pass "conf2's index matches conf2's own ids"
fi

say "(3) the stale project cache transient is gone on conf2"
CACHE_AFTER=$(wp2 eval 'var_export(get_transient("wprism_agency_project_cache"));' | tr -d '\r' | tail -1)
[ "$CACHE_AFTER" = "false" ] \
  || fail "the native transient.delete action did not clear wprism_agency_project_cache on conf2 (got: $CACHE_AFTER)"
# Last NON-empty line: `wp db query --skip-column-names` emits a trailing
# blank line, so a bare `tail -1` reads the blank and a passing "0" would
# false-fail with an empty count (caught live on this script's first run).
ROWS=$(wp2 db query "SELECT COUNT(*) FROM wp_options WHERE option_name IN ('_transient_wprism_agency_project_cache','_transient_timeout_wprism_agency_project_cache')" --skip-column-names | tr -d '\r' | awk 'NF {last=$0} END {print last}')
[ "$ROWS" = "0" ] || fail "transient option rows survived the native action on conf2 (count: $ROWS)"
pass "both transient option rows are gone and the cache no longer answers"

# ============================================================ (4) fail before mutation

say "(4) author a change on conf1 that WOULD trigger the provider action, then deactivate the owning plugin on conf2"
FIRST_ID1=$(awk '{print $1}' <<<"$IDS1")
wp1 post update "$FIRST_ID1" --post_title="Harbour Rebrand (phase two)" >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: retitle the first project"
"${GIT1[@]}" push -q origin main
"${GIT2[@]}" pull -q origin main
REV2=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)

wp2 plugin deactivate "$PLUGIN_DIR" >/dev/null
INDEX_BEFORE_REFUSAL=$(wp2 db query "SELECT option_value FROM wp_options WHERE option_name='wprism_agency_project_index'" --skip-column-names | tr -d '\r')
TITLES_BEFORE_REFUSAL=$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project' ORDER BY ID" --skip-column-names | tr -d '\r')

# --force-code-mismatch deliberately: it gets past the lifecycle gate that
# would otherwise refuse first for the deactivated plugin, which is what makes
# this a test of the CAPABILITY gate rather than a re-test of the code gate.
# The capability gate is not forceable and must refuse anyway.
if OUT=$(wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV2" --force-code-mismatch 2>&1); then
  echo "$OUT"
  fail "apply succeeded with the provider's owning plugin deactivated — the capability gate did not hold"
fi
echo "$OUT"
grep -Fq "refused before target mutation" <<<"$OUT" || fail "refusal did not name the pre-mutation gate"
grep -Fq "wprism-agency-index" <<<"$OUT" || fail "refusal did not name the provider"
grep -Fq "$PLUGIN_BASENAME" <<<"$OUT" || fail "refusal did not name the owning plugin"
grep -Fq "wprism-agency-cpt" <<<"$OUT" || fail "refusal did not name the declaring manifest"
grep -Fq "wprism deploy" <<<"$OUT" || fail "refusal carried no remediation path"
pass "apply refused, naming the provider, its owning plugin, the declaring manifest, and what to do about it"

say "(4) conf2 is byte-for-byte unmutated by the refused apply"
grep -Fq "Harbour Rebrand (phase two)" <<<"$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project'" --skip-column-names)" \
  && fail "the refused apply wrote the authored title anyway — it mutated before negotiating"
[ "$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project' ORDER BY ID" --skip-column-names | tr -d '\r')" = "$TITLES_BEFORE_REFUSAL" ] \
  || fail "project rows changed during a refused apply"
[ "$(wp2 db query "SELECT option_value FROM wp_options WHERE option_name='wprism_agency_project_index'" --skip-column-names | tr -d '\r')" = "$INDEX_BEFORE_REFUSAL" ] \
  || fail "the generated index changed during a refused apply"
MARKER=$(wp2 eval "echo \\WPrism\\Ledger::kv_get('apply_in_progress') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$MARKER" = NULL ] \
  || fail "a refused apply wrote the incomplete-apply retry marker despite attempting no mutation (got: $MARKER)"
pass "no post row, no option, and no retry marker moved — the refusal happened before the first write"

# ============================================================ (5) recovery

say "(5) deploy reactivates the plugin and the identical apply converges, re-firing the same idempotent capability"
wp2 wprism deploy --repo=/siterepo --format=json >/dev/null
wp2 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "deploy did not reactivate $PLUGIN_DIR on conf2"
RECOVER_JSON=$(wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV2" --format=json | tail -1)
echo "$RECOVER_JSON" | jq -e '.canary == "clean"' >/dev/null || fail "recovery apply canary was not clean: $RECOVER_JSON"
echo "$RECOVER_JSON" | jq -e '
  .warnings | any(test("^provider capability fired: wprism-agency-index@1\\.0\\.0 rebuild_project_index \\([0-9.]+s, verified\\)$"))
' >/dev/null || fail "recovery apply did not re-fire the provider capability: $(echo "$RECOVER_JSON" | jq -c .warnings)"
grep -Fq "Harbour Rebrand (phase two)" <<<"$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project'" --skip-column-names)" \
  || fail "recovery apply did not land the authored title on conf2"
RECOVER_IDS_JSON=$(ids_json_2)
jq -e --argjson ids "$RECOVER_IDS_JSON" '.ids == $ids and (.titles | to_entries | map(.value) | any(. == "Harbour Rebrand (phase two)"))' \
  <<<"$(wp2 option get wprism_agency_project_index --format=json | tail -1)" >/dev/null \
  || fail "conf2's index was not rebuilt from the newly applied titles"
pass "the identical apply converged after deploy, and the idempotent capability re-fired and re-verified"

GREEN=1
printf '\n\033[1;32m✔ REGRESS_PROVIDER_CONTRACT_LIVE PASSED\033[0m\n'
