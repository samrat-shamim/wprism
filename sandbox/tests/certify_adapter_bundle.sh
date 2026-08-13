#!/usr/bin/env bash
# Build one independently current adapter certification bundle.  This is
# intentionally not the release-wide reference certifier: it runs exactly one
# manifest's conformance fixture plus its exact artifact/range boundary.
set -euo pipefail
cd "$(dirname "$0")/.." # sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

MANIFEST="${1:-}"
[ -n "$MANIFEST" ] || fail "usage: certify_adapter_bundle.sh <manifest>"
# The first shipped scoped lane is WooCommerce.  Keep the adapter choice in
# this harness, beside its fixture and manifest provider, never in engine code.
[ "$MANIFEST" = woocommerce ] || fail "no scoped certification lane is implemented for '$MANIFEST'"

PAIR="${CERT_ADAPTER_PAIR:-adaptercert}"
PORT1="${CERT_ADAPTER_PORT1:-8920}"
PORT2="${CERT_ADAPTER_PORT2:-8921}"
OUT_ROOT="${CERT_ADAPTER_OUT:-/tmp/duo-adapter-certification-bundles}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "CERT_ADAPTER_PAIR must use lowercase letters/digits"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" -ge 8900 && "$PORT2" -eq $((PORT1 + 1)) ]] \
  || fail "CERT_ADAPTER_PORT1 must be an even port >= 8900 and PORT2 must immediately follow"
(( PORT1 % 2 == 0 )) || fail "CERT_ADAPTER_PORT1 must be even"
case "${DUO_PAIR_BUDGET_OVERRIDE:-}" in
  ''|0) FORCE_HATCHES='[]' ;;
  1) FORCE_HATCHES='["DUO_PAIR_BUDGET_OVERRIDE"]' ;;
  *) fail "DUO_PAIR_BUDGET_OVERRIDE must be unset, 0, or 1 for scoped certification" ;;
esac
command -v jq >/dev/null || fail "jq required"
command -v php >/dev/null || fail "php required"

REPO_ROOT=$(cd .. && pwd -P)
SOURCE_SHA=$(git -C "$REPO_ROOT" rev-parse --verify HEAD^{commit}) \
  || fail "scoped certification requires a Git checkout"
[ -z "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
  || fail "scoped certification requires a clean exact-source checkout"
WORK_ROOT=$(mktemp -d /tmp/duo-adapter-cert-run.XXXXXX)
cleanup() {
  # Cleanup is unconditional: a narrowly-scoped proof must never leave its
  # custom pair consuming the shared host.  pair.sh teardown never needs the
  # exact-source gate, so it remains available even if a test failed.
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  case "$WORK_ROOT" in
    /tmp/duo-adapter-cert-run.*) rm -rf -- "$WORK_ROOT" ;;
    *) printf 'WARNING: refusing unexpected work-root cleanup: %s\n' "$WORK_ROOT" >&2 ;;
  esac
}
trap cleanup EXIT

say "scoped adapter certification preflight: $MANIFEST @ $SOURCE_SHA"
php -l bin/adapter-certification-bundle.php >/dev/null
bash -n conformance/run.sh tests/certify_version_matrix.sh
bash bin/pair.sh list
mkdir -p -- "$OUT_ROOT"
pass "builder, exact-source checkout, and shared-pair inventory are ready"
if [ "$FORCE_HATCHES" != '[]' ]; then
  printf 'note: budget override is active and will be sealed in force_hatches; this bundle cannot be published as a current capability claim\n'
fi

CONFORMANCE_LOG="$WORK_ROOT/conformance-$MANIFEST.log"
say "scoped leg: $MANIFEST conformance"
set +e
DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA" \
  CONF_EXPECTED_SOURCE_SHA="$SOURCE_SHA" \
  CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" \
  CONFORMANCE_ENTRY_FILE="conformance/entries/$MANIFEST.json" \
  CONFORMANCE_EVIDENCE_DIR="$WORK_ROOT" \
  bash conformance/run.sh "$MANIFEST" >"$CONFORMANCE_LOG" 2>&1
CONFORMANCE_RC=$?
set -e
# Docker Compose progress output commonly has a trailing space.  Evidence logs
# are source-controlled artifacts, so normalize that presentation-only byte
# before it is content-addressed; command status and substantive output remain
# unchanged.
sed -i.bak -E 's/[[:space:]]+$//' "$CONFORMANCE_LOG"
rm -f -- "$CONFORMANCE_LOG.bak"
tail -40 "$CONFORMANCE_LOG"
bash bin/pair.sh destroy "$PAIR"
[ "$CONFORMANCE_RC" -eq 0 ] \
  && grep -qF "✔ CONFORMANCE PASSED ($MANIFEST)" "$CONFORMANCE_LOG" \
  && jq -e --arg id "conformance-$MANIFEST" \
    '.test == $id and .verdict == "pass" and .exit_code == 0' \
    "$WORK_ROOT/conformance-$MANIFEST.result.json" >/dev/null \
  && jq -e --arg manifest "$MANIFEST" \
    '.status == "clean" and .manifest == $manifest' \
    "$WORK_ROOT/conformance-$MANIFEST.diff.json" >/dev/null \
  || fail "scoped conformance did not produce a complete passing evidence record"
pass "$MANIFEST conformance passed and its pair was destroyed"

MATRIX_LOG="$WORK_ROOT/exact-artifact-version-matrix.log"
MATRIX_RESULT="$WORK_ROOT/exact-artifact-version-matrix.result.json"
MATRIX_DIFF="$WORK_ROOT/exact-artifact-version-matrix.diff.json"
say "scoped leg: WooCommerce exact artifact/version boundary and below-range refusal"
bash bin/pair.sh list
set +e
DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA" \
  VMATRIX_MANIFEST="$MANIFEST" VMATRIX_PAIR="$PAIR" VMATRIX_PORT1="$PORT1" VMATRIX_PORT2="$PORT2" \
  bash tests/certify_version_matrix.sh >"$MATRIX_LOG" 2>&1
MATRIX_RC=$?
set -e
sed -i.bak -E 's/[[:space:]]+$//' "$MATRIX_LOG"
rm -f -- "$MATRIX_LOG.bak"
tail -40 "$MATRIX_LOG"
bash bin/pair.sh destroy "$PAIR"
MATRIX_REASON=passed
if [ "$MATRIX_RC" -ne 0 ] || ! grep -qF '✔ CERTIFY_VERSION_MATRIX PASSED' "$MATRIX_LOG"; then
  MATRIX_REASON=command_failed
fi
jq -n --arg test exact-artifact-version-matrix \
  --arg verdict "$([ "$MATRIX_REASON" = passed ] && printf pass || printf fail)" \
  --arg reason "$MATRIX_REASON" --argjson exit_code "$MATRIX_RC" \
  '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,
    assertions:["woocommerce_11_0_0_digest_verified","woocommerce_11_0_0_round_trip","woocommerce_10_9_4_refused"]}' \
  > "$MATRIX_RESULT"
jq -n --arg status "$([ "$MATRIX_REASON" = passed ] && printf clean || printf unknown)" \
  '{status:$status,manifest:"woocommerce",diffs:["woocommerce-in-range-recapture"],negative_controls:["woocommerce-10.9.4-refused"]}' \
  > "$MATRIX_DIFF"
[ "$MATRIX_REASON" = passed ] || fail "scoped WooCommerce version boundary failed"
pass "WooCommerce boundary/refusal passed and its pair was destroyed"

# Keep the closure intentionally conservative but adapter-local: generic
# engine/CLI and shared harness bytes, plus only Woo's manifest, providers,
# disposition, fixtures, artifacts, and scoped runner.  No unrelated adapter
# manifest, provider, or matrix lane can expire this certificate.
BOUND_INPUTS=$({ git -C "$REPO_ROOT" ls-files \
  agent cli sandbox/bin \
  sandbox/conformance/run.sh sandbox/conformance/asserts.sh \
  sandbox/conformance/entries/woocommerce.json \
  sandbox/conformance/seeds/woocommerce.sh sandbox/conformance/postdeploy/woocommerce.sh sandbox/conformance/checks/woocommerce.sh \
  sandbox/conformance/artifacts.lock.json \
  sandbox/tests/certify_adapter_bundle.sh sandbox/tests/certify_version_matrix.sh \
  sandbox/pair.yml sandbox/pair.artifacts.yml sandbox/pair.wordpress-offline.yml sandbox/db.yml sandbox/init-cli.Dockerfile \
  scripts/capability-registry.php docs/compatibility-baseline.json Makefile; \
  printf '%s\n' manifests/dispositions.json manifests/woocommerce.json \
    manifests/providers/woocommerce-cache.php manifests/providers/woocommerce-product-lookups.php; } \
  | LC_ALL=C sort -u | jq -R . | jq -s .)
SPEC="$WORK_ROOT/$MANIFEST.spec.json"
jq -n \
  --arg repo_root "$REPO_ROOT" --arg manifest "$MANIFEST" \
  --arg created_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" --arg git_revision "$SOURCE_SHA" \
  --arg conformance_result "$WORK_ROOT/conformance-$MANIFEST.result.json" \
  --arg conformance_diff "$WORK_ROOT/conformance-$MANIFEST.diff.json" --arg conformance_log "$CONFORMANCE_LOG" \
  --arg matrix_result "$MATRIX_RESULT" --arg matrix_diff "$MATRIX_DIFF" --arg matrix_log "$MATRIX_LOG" \
  --argjson bound_inputs "$BOUND_INPUTS" --argjson force_hatches "$FORCE_HATCHES" \
  '{repo_root:$repo_root,manifest:$manifest,created_at:$created_at,git_revision:$git_revision,force_hatches:$force_hatches,bound_inputs:$bound_inputs,
    tests:[
      {id:"conformance-woocommerce",result:$conformance_result,diff:$conformance_diff,log:$conformance_log},
      {id:"exact-artifact-version-matrix",result:$matrix_result,diff:$matrix_diff,log:$matrix_log}
    ]}' > "$SPEC"

BUILD=$(php bin/adapter-certification-bundle.php build "$SPEC" "$OUT_ROOT") \
  || fail "scoped adapter bundle assembly failed"
BUNDLE=$(jq -r '.bundle // empty' <<<"$BUILD")
[ -n "$BUNDLE" ] && [ -d "$BUNDLE" ] || fail "builder did not return a content-addressed scoped bundle"
php bin/adapter-certification-bundle.php verify "$BUNDLE" "$REPO_ROOT" >/dev/null \
  || fail "new scoped adapter bundle did not verify against current bytes"
printf '%s\n' "$BUILD"
pass "scoped $MANIFEST certificate is verified: $BUNDLE"
if [ "$FORCE_HATCHES" = '[]' ]; then
  printf 'To publish only this claim: php scripts/capability-registry.php import-adapter-bundle %q\n' "$BUNDLE"
else
  printf 'Forced scoped evidence is verified but intentionally not publishable as a current capability claim.\n'
fi
