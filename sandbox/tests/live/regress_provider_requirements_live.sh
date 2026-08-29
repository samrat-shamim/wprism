#!/usr/bin/env bash
# Live regression — issue #3317: a provider declares a `requires` contract naming
# an environment it does not have, and apply refuses BEFORE the first target
# mutation, naming the provider, its owning plugin, the declaring manifest, the
# unmet requirement, and what to do about it — then converges once the same
# apply runs against the shipped adapter that declares no such requirement.
#
# The offline half (sandbox/tests/offline/adapter/regress_provider_contract.php +
# regress_actions_providers.php) proves the grammar, the load validation, the
# negotiation refusal codes, and that the requirement gate fires before the
# provider is constructed. It structurally cannot prove the thing the gate
# exists for: that a real `wprism apply` against a real target, through the
# ordinary product path, refuses before it writes anything. That needs a live
# pair.
#
# Bundle-free by construction — this proves the requirement contract WITHOUT a
# certification bundle. The shipped wprism-agency-cpt package declares the
# `wprism-agency-index` provider but NO `requires`; a `requires` edit to a shipped
# manifest is a certified-identity change (the manifest bytes fold into the
# adapter digest ArtifactPolicyIdentity::manifest_rows() hashes), so this suite
# supplies the requirement through a private source-layout AdapterLibrary and
# injects only its object through apply's unregistered evidence seam. The
# shipped file is asserted byte-identical before and after assembly.
#
# Own pair, so this is regress-live-list material, never regress-offline-all.
#
# What it proves, in order:
#  (1) scaffold + seed conf1, capture, deploy the plugin onto conf2, and apply
#      once against the SHIPPED adapter so conf2's generated index converges —
#      the baseline the refusal is measured against.
#  (2) an authored change on conf1 that WOULD fire the provider capability.
#  (3) FAIL BEFORE MUTATION: apply on conf2 under the overlay whose provider
#      declares `requires.functions: ["wprism_absent_requirement_probe_fn"]`
#      refuses before any write, with provider_requirement_unmet naming the
#      provider, plugin, manifest, the missing function, and a remediation;
#      conf2's rows, options, and the apply_in_progress marker are unchanged.
#      The plugin stays ACTIVE throughout, so this is the requirement gate, not
#      the lifecycle gate.
#  (4) recovery: the identical apply against the SHIPPED adapter (no requires)
#      converges and fires the capability — proving the refusal was the
#      requirement and nothing else.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR="${PROVIDER_REQUIREMENTS_PAIR:-claudemacb3317}"
PORT1="${PROVIDER_REQUIREMENTS_PORT1:-8930}"
PORT2="${PROVIDER_REQUIREMENTS_PORT2:-8931}"
PLUGIN_DIR=wprism-agency-cpt
PLUGIN_BASENAME="$PLUGIN_DIR/$PLUGIN_DIR.php"
PLUGIN_FILE="code/wp-content/plugins/$PLUGIN_DIR/$PLUGIN_DIR.php"
MANIFEST=adapter-packages/wprism-agency-cpt/package/manifest.json
OVERLAY=.tmp-3317-library
MISSING_FN=wprism_absent_requirement_probe_fn
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN="$PLUGIN_DIR"
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.codebind.yml)
. tests/support/explicit_adapter_library.sh

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
# The private-library invocation on conf2. Only the refused apply uses it;
# every other apply stays on the shipped adapter bytes, which is what keeps the
# shipped manifest honest and makes the recovery a genuine A/B on `requires`.
wp2m() { [ "${1:-}" = wprism ] || fail 'wp2m accepts only wprism commands'; shift; wprism_with_adapter_library cli2 "/siterepo/$OVERLAY" "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=provider-requirements-a -c user.email=a1@example.test)
GIT2=(git -C "siterepo/${PAIR}2" -c user.name=provider-requirements-b -c user.email=a2@example.test)

GREEN=0
cleanup() {
  if [ "$GREEN" = 1 ]; then
    bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  else
    printf '\033[1;33m(pair %s left up for inspection after a failed run)\033[0m\n' "$PAIR" >&2
  fi
}
trap cleanup EXIT

say "the shipped adapter bytes must be untouched before this suite starts"
git -C .. diff --quiet -- "$MANIFEST" \
  || fail "$MANIFEST has uncommitted changes — this suite proves the requirement contract without changing the shipped adapter"
jq -e '.providers[0].requires == null' "../$MANIFEST" >/dev/null \
  || fail "the shipped $MANIFEST already declares a provider requires block — this suite's whole premise is that it does not"
pass "$MANIFEST is unmodified and declares no provider requires"

say "clean-room site repositories, with the fixture plugin authored before the code-bind containers are created"
bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
rm -rf "siterepo/${PAIR}1" "siterepo/${PAIR}2" "siterepo/origin-$PAIR.git"
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
"${GIT1[@]}" commit -qm "init: wprism-agency-cpt in code/ + site.wprism.json"
git -C "siterepo/${PAIR}1" push -qu origin main
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --codebind "$PLUGIN_DIR" --headless
pass "pair $PAIR up (headless, code-bound to $PLUGIN_DIR)"

say "conf1 activates the fixture; conf2 receives it through deploy alone"
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 plugin activate "$PLUGIN_DIR" >/dev/null
wp1 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "$PLUGIN_DIR did not activate on conf1"
pass "conf1 active, conf2 inactive"

# ============================================================ (1) baseline

say "(1) seed conf1, capture, push"
wp1 post create --post_type=project --post_status=publish --post_title="Harbour Rebrand" --porcelain >/dev/null
wp1 post create --post_type=project --post_status=publish --post_title="Meridian Storefront" --porcelain >/dev/null
IDS1=$(wp1 post list --post_type=project --post_status=publish --orderby=ID --order=ASC --format=ids | tr -d '\r')
wp1 wprism capture --repo=/siterepo >/dev/null
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: two projects on conf1"
"${GIT1[@]}" push -q origin main
pass "conf1 captured and pushed"

say "(1) conf2: deploy activates the plugin, then a SHIPPED-adapter apply converges (the baseline the refusal is measured against)"
"${GIT2[@]}" pull -q origin main
DEPLOY_JSON=$(wp2 wprism deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e --arg p "$PLUGIN_BASENAME" '.activated | any(. == $p)' >/dev/null \
  || fail "deploy did not activate $PLUGIN_BASENAME on conf2 (got: $DEPLOY_JSON)"
REV1=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
BASE_JSON=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV1" --format=json | tail -1)
echo "$BASE_JSON" | jq -e '.canary == "clean"' >/dev/null || fail "baseline apply canary was not clean: $BASE_JSON"
pass "conf2 deployed and converged against the shipped adapter"

# ============================================================ (2) authored change

say "(2) author a change on conf1 that WOULD fire the provider capability"
FIRST_ID1=$(awk '{print $1}' <<<"$IDS1")
wp1 post update "$FIRST_ID1" --post_title="Harbour Rebrand (phase two)" >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: retitle the first project"
"${GIT1[@]}" push -q origin main
"${GIT2[@]}" pull -q origin main
REV2=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
pass "conf2 holds a revision whose apply selects the provider's post:project action"

# ============================================================ (3) fail before mutation

say "(3) build the overlay: shipped bytes + a provider requires block naming an absent function; the shipped file stays byte-identical"
DIR="siterepo/${PAIR}2/$OVERLAY"
mkdir -p "$DIR/adapter-packages/wprism-agency-cpt" "$DIR/platform"
cp -R ../platform/adapter-library "$DIR/platform/adapter-library"
cp -R ../adapter-packages/wprism-agency-cpt/package "$DIR/adapter-packages/wprism-agency-cpt/package"
jq --arg fn "$MISSING_FN" '.providers[0].requires = {"functions": [$fn]}' \
  "../$MANIFEST" > "$DIR/adapter-packages/wprism-agency-cpt/package/manifest.json.tmp"
# Atomic publish + container-side settle barrier: the host write races the
# container's bind-mount view on macOS (the parent-scoped suite documents the
# same race), so mv atomically, then prove the container parses the final bytes
# before apply loads policy.
mv "$DIR/adapter-packages/wprism-agency-cpt/package/manifest.json.tmp" \
  "$DIR/adapter-packages/wprism-agency-cpt/package/manifest.json"
jq -e --arg fn "$MISSING_FN" '.providers[0].requires.functions == [$fn]' \
  "$DIR/adapter-packages/wprism-agency-cpt/package/manifest.json" >/dev/null \
  || fail "overlay manifest did not receive the provider requires block"
jq -e '.providers[0].requires == null' "../$MANIFEST" >/dev/null \
  || fail "the SHIPPED $MANIFEST gained a provider requires block — it must stay byte-identical"
git -C .. diff --quiet -- "$MANIFEST" \
  || fail "the SHIPPED $MANIFEST changed on disk while the overlay was built"
for i in $(seq 1 20); do
  SEEN=$(wp2 eval '$library = \WPrism\AdapterLibrary::fromSourceTree("/siterepo/'"$OVERLAY"'"); echo json_encode(\WPrism\Policy::load("/siterepo", adapterLibrary: $library)->provider_declarations()["wprism-agency-index"]["requires"] ?? null);' 2>/dev/null | tr -d '\r' | tail -1) || SEEN=""
  [ "$SEEN" = "{\"functions\":[\"$MISSING_FN\"]}" ] && break
  [ "$i" = "20" ] && fail "conf2 never saw the settled overlay through the bind mount (last: $SEEN)"
  sleep 1
done
pass "overlay built and visible inside conf2; the shipped manifest still declares no requires"

say "(3) capture the pre-refusal witness, then apply under the overlay — it must refuse before any mutation, with the plugin still active"
wp2 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "the owning plugin must be ACTIVE for this to test the requirement gate rather than the lifecycle gate"
TITLES_BEFORE=$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project' ORDER BY ID" --skip-column-names | tr -d '\r')
INDEX_BEFORE=$(wp2 db query "SELECT option_value FROM wp_options WHERE option_name='wprism_agency_project_index'" --skip-column-names | tr -d '\r')

if OUT=$(wp2m wprism apply --repo=/siterepo --default-author=admin --revision="$REV2" 2>&1); then
  echo "$OUT"
  fail "apply succeeded with an unmet provider requirement — the requirement gate did not hold"
fi
echo "$OUT"
grep -Fq "refused before target mutation" <<<"$OUT" || fail "refusal did not name the pre-mutation gate"
grep -Fq "provider_requirement_unmet" <<<"$OUT" || fail "refusal did not carry the provider_requirement_unmet code"
grep -Fq "wprism-agency-index" <<<"$OUT" || fail "refusal did not name the provider"
grep -Fq "$PLUGIN_BASENAME" <<<"$OUT" || fail "refusal did not name the owning plugin"
grep -Fq "wprism-agency-cpt" <<<"$OUT" || fail "refusal did not name the declaring manifest"
grep -Fq "$MISSING_FN" <<<"$OUT" || fail "refusal did not name the missing function"
grep -Fq "declared requirements" <<<"$OUT" || fail "refusal carried no remediation path"
pass "apply refused with provider_requirement_unmet, naming the provider, plugin, manifest, missing function, and a remediation"

say "(3) conf2 is byte-for-byte unmutated by the refused apply"
grep -Fq "Harbour Rebrand (phase two)" <<<"$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project'" --skip-column-names)" \
  && fail "the refused apply wrote the authored title anyway — it mutated before negotiating"
[ "$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project' ORDER BY ID" --skip-column-names | tr -d '\r')" = "$TITLES_BEFORE" ] \
  || fail "project rows changed during a refused apply"
[ "$(wp2 db query "SELECT option_value FROM wp_options WHERE option_name='wprism_agency_project_index'" --skip-column-names | tr -d '\r')" = "$INDEX_BEFORE" ] \
  || fail "the generated index changed during a refused apply"
MARKER=$(wp2 eval "echo \\WPrism\\Ledger::kv_get('apply_in_progress') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$MARKER" = NULL ] \
  || fail "a refused apply wrote the incomplete-apply retry marker despite attempting no mutation (got: $MARKER)"
pass "no post row, no option, and no retry marker moved — the refusal happened before the first write"

# ============================================================ (4) recovery

say "(4) the identical apply against the SHIPPED adapter (no requires) converges and fires the capability"
RECOVER_JSON=$(wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV2" --format=json | tail -1)
echo "$RECOVER_JSON" | jq -e '.canary == "clean"' >/dev/null || fail "recovery apply canary was not clean: $RECOVER_JSON"
echo "$RECOVER_JSON" | jq -e '
  .warnings | any(test("^provider capability fired: wprism-agency-index@1\\.0\\.0 rebuild_project_index \\([0-9.]+s, verified\\)$"))
' >/dev/null || fail "recovery apply did not fire the provider capability: $(echo "$RECOVER_JSON" | jq -c .warnings)"
grep -Fq "Harbour Rebrand (phase two)" <<<"$(wp2 db query "SELECT post_title FROM wp_posts WHERE post_type='project'" --skip-column-names)" \
  || fail "recovery apply did not land the authored title on conf2"
pass "the shipped adapter converged and re-fired the capability — the refusal was the requirement, and nothing else"

GREEN=1
printf '\n\033[1;32m✔ REGRESS_PROVIDER_REQUIREMENTS_LIVE PASSED\033[0m\n'
