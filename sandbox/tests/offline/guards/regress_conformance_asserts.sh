#!/usr/bin/env bash
# Regression — issue #3408: every premise/answer assertion helper the conformance
# seeds and postdeploy hooks call must be DEFINED in the shared fragment
# (sandbox/conformance/asserts.sh), and every harness that sources those hooks
# must source the fragment. The defect class this pins: a helper added to one
# harness's private prelude works there, greens its own PR, and then kills the
# OTHER harness at bundle leg 12 with `command not found` — observed live at
# bundle 626da880 (exit 127), after two prior PRs (#173, #177) each did
# exactly that innocently.
set -euo pipefail
cd "$(dirname "$0")/../../.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

FRAGMENT=conformance/asserts.sh
[ -f "$FRAGMENT" ] || fail "shared fragment $FRAGMENT is missing"

# Every require_* invoked anywhere in the hooks both harnesses source...
# (require_once is PHP inside the hooks' heredocs, not a bash helper.)
CALLED=$(grep -rhoE '\brequire_[a-z_]+' conformance/seeds/ conformance/postdeploy/ conformance/checks/ conformance/capture-checks/ ../adapter-packages/*/tests/conformance/ | grep -v '^require_once$' | sort -u)
[ -n "$CALLED" ] || fail "no require_* calls found under conformance/seeds/ + postdeploy/ + checks/ + capture-checks/ — the grep itself regressed"

# ...must be defined in the fragment (definition = `name() {`).
MISSING=""
while IFS= read -r fn; do
  grep -qE "^${fn}\(\) \{" "$FRAGMENT" || MISSING="$MISSING $fn"
done <<<"$CALLED"
[ -z "$MISSING" ] || fail "helper(s) called by the hooks but not defined in $FRAGMENT:$MISSING — the next bundle dies at leg 12 with 'command not found'"
pass "every hook-called require_* helper ($(wc -l <<<"$CALLED" | tr -d ' ') distinct) is defined in the shared fragment"

# Both harnesses must source the fragment.
for harness in conformance/run.sh tests/certify/certify_version_matrix.sh; do
  grep -qE '^\. conformance/asserts\.sh' "$harness" \
    || fail "$harness does not source the shared fragment — its hooks' premise assertions die at runtime"
done
pass "both hook-sourcing harnesses source the fragment"

grep -q 'POSTAPPLY=$(conformance_hook postapply.sh "conformance/postapply/\$MANIFEST.sh")' conformance/run.sh \
  || fail 'conformance/run.sh does not resolve the package-first post-apply hook'
APPLY_LINE=$(grep -n 'pass "apply succeeded, side-effect canary clean"' conformance/run.sh | cut -d: -f1)
POSTAPPLY_LINE=$(grep -n '^POSTAPPLY=$(conformance_hook postapply.sh ' conformance/run.sh | cut -d: -f1)
RECAPTURE_LINE=$(grep -n '^say "acceptance: canonical(conf2) == canonical(conf1), byte for byte"' conformance/run.sh | cut -d: -f1)
CHECK_LINE=$(grep -n '^CHECK=$(conformance_hook check.sh ' conformance/run.sh | cut -d: -f1)
[ "$APPLY_LINE" -lt "$POSTAPPLY_LINE" ] \
  && [ "$POSTAPPLY_LINE" -lt "$RECAPTURE_LINE" ] \
  && [ "$RECAPTURE_LINE" -lt "$CHECK_LINE" ] \
  || fail 'post-apply hooks must run after successful apply and before generic recapture/diff and render checks'
pass 'post-apply target-local witness hooks have one convention path and an exact pre-recapture execution point'

# And the fragment must not silently grow a second definition home: the
# helpers may be defined nowhere else.
DUPES=$(grep -rlE '^require_[a-z_]+\(\) \{' conformance/ tests/ ../adapter-packages/*/tests/conformance/ | grep -v "^$FRAGMENT\$" | grep -v '^tests/offline/guards/regress_conformance_asserts.sh$' || true)
[ -z "$DUPES" ] || fail "helper definitions exist outside the fragment (one owner per grammar):$DUPES"
pass "the fragment is the single definition home"

# A successful HTTP body can exceed the pipe buffer. Under pipefail, piping
# curl directly into grep -q lets grep close early after a match; curl then
# reports EPIPE (exit 23) and the live check falsely fails a working route.
# Buffering the body also keeps transport success separate from body content.
EARLY_CLOSE_CURL=$(grep -En '^[^#]*curl[^#|]*\|[^#]*grep[^#]*-[[:alpha:]]*q' conformance/checks/*.sh ../adapter-packages/*/tests/conformance/check.sh || true)
[ -z "$EARLY_CLOSE_CURL" ] \
  || fail "conformance check streams curl into early-closing grep -q under pipefail; capture the body first: $EARLY_CLOSE_CURL"
pass "conformance checks separate HTTP transport success from body matching (no curl | grep -q EPIPE false negatives)"

# A capture-plan profile exists so an experimental adapter can provide live
# evidence for the operations it actually claims without the harness forcing
# an unsupported target apply. Pin all three seams: closed mode vocabulary,
# source-side hook timing, and the early stop before clone/deploy/apply.
grep -q 'roundtrip|capture-plan' conformance/run.sh \
  || fail "conformance/run.sh has no closed capture-plan mode vocabulary"
grep -q 'CAPTURE_CHECK=$(conformance_hook capture-check.sh "conformance/capture-checks/\$MANIFEST.sh")' conformance/run.sh \
  || fail "conformance/run.sh does not resolve the package-first source-side capture check"
grep -q 'CONFORMANCE PASSED (%s; capture-plan)' conformance/run.sh \
  || fail "conformance/run.sh has no explicit successful early terminal before target apply"
pass "capture-plan mode is closed, convention-hooked, and terminates explicitly before target apply"

# Every shipped adapter owns its entry and hooks; the shared aggregate was
# retired so adding an adapter never edits conformance infrastructure.
for manifest in ../adapter-packages/*; do
  name=${manifest##*/}
  [ "$name" = wprism-agency-cpt ] && continue
  [ -f "$manifest/tests/conformance/entry.json" ] \
    && [ -f "$manifest/tests/conformance/seed.sh" ] \
    && [ -f "$manifest/tests/conformance/check.sh" ] \
    || fail "adapter package $name does not own its conformance entry, seed, and check"
done
[ ! -e conformance/manifests.json ] \
  || fail 'the retired shared conformance registry still exists'
grep -q 'PACKAGE_ENTRY="$PACKAGE_CONFORMANCE/entry.json"' conformance/run.sh \
  || fail 'conformance/run.sh does not discover a package-owned entry'
pass 'adapter conformance entries and hooks are package-owned and package-first discovered'

[ -f ../adapter-packages/acf/tests/certify/version-matrix.sh ] \
  && [ ! -e tests/certify/matrix.d/acf.sh ] \
  || fail 'the ACF version-matrix hook has duplicate or missing ownership'
grep -Fq 'VMATRIX_CAPSULE="../adapter-packages/$VMATRIX_MANIFEST/tests/certify/version-matrix.sh"' tests/certify/certify_version_matrix.sh \
  && grep -Fq '. "$VMATRIX_CAPSULE"' tests/certify/certify_version_matrix.sh \
  || fail 'the shared certify matrix does not source the selected package-owned capsule'
if grep -Eq 'adapter-packages/\*|^if \[ "\$VMATRIX_MANIFEST" = ' tests/certify/certify_version_matrix.sh; then
  fail 'the shared certify matrix still owns a package registry or adapter-specific case dispatch'
fi
for package in ../adapter-packages/*; do
  disposition="$package/package/disposition.json"
  [ -f "$disposition" ] || continue
  jq -e '.evidence.tests | index("exact-artifact-version-matrix") != null' "$disposition" >/dev/null 2>&1 \
    || continue
  capsule="$package/tests/certify/version-matrix.sh"
  [ -f "$capsule" ] \
    && grep -q '^VMATRIX_PLUGIN_SLUG=' "$capsule" \
    && grep -q '^version_matrix_workflow() {' "$capsule" \
    || fail "exact-artifact package ${package##*/} does not own its complete certification workflow"
done
grep -q 'adapter-packages/${MANIFEST}/tests/certify/version-matrix.sh' bin/adapter-boundary.sh \
  || fail 'the boundary runner does not prefer a package-owned certify hook'
pass 'exact-version workflows and boundary helpers are package-owned and selected without a central registry'

# Execute the real driver's pre-pair selection boundary in a scratch tree. A
# sibling capsule with a top-level exit reproduces the former wildcard-source
# hazard: it must be completely invisible to ACF. The same probe proves a new
# valid package runs without a driver edit and an incomplete capsule refuses
# before Docker or any pair mutation can begin.
MATRIX_PROBE=$(mktemp -d "${TMPDIR:-/tmp}/wprism-certify-capsule.XXXXXX")
trap 'rm -rf -- "$MATRIX_PROBE"' EXIT
mkdir -p "$MATRIX_PROBE/sandbox/tests/certify" "$MATRIX_PROBE/sandbox/conformance"
cp tests/certify/certify_version_matrix.sh "$MATRIX_PROBE/sandbox/tests/certify/"
: > "$MATRIX_PROBE/sandbox/conformance/asserts.sh"

# A dev-bound pair loads agent/ directly, whereas every real adopted target
# also owns an initialized, database-independent recovery runtime. The host
# deploy's checkpoint preflight now proves that runtime before database access;
# reproduce the missing-premise live failure without Docker, then pin both the
# exact bytes and the pre-deploy wiring used by every Rank Math evidence lane.
. lib/host_orchestrator.sh
RUNTIME_SITE="$MATRIX_PROBE/runtime-site"
mkdir "$RUNTIME_SITE"
git -C "$RUNTIME_SITE" init -q
RUNTIME_SOURCE="$(cd .. && pwd -P)"
RUNTIME_HEAD="$(git -C "$RUNTIME_SOURCE" rev-parse HEAD)"
WPRISM_EXPECTED_SOURCE_SHA="$RUNTIME_HEAD" \
  wprism_host_install_recovery_runtime "$RUNTIME_SOURCE" "$RUNTIME_SITE" \
  || fail 'shared host helper could not install an adoption-equivalent recovery runtime'
RUNTIME="$RUNTIME_SITE/.wprism/control/recovery-runtime"
for source in ../recovery/*.php; do
  cmp -s "$source" "$RUNTIME/${source##*/}" \
    || fail "shared host helper changed recovery runtime bytes for ${source##*/}"
done
for file in DatabaseTargetIdentity.php RetainedCheckpointCipher.php; do
  cmp -s "../agent/src/Recovery/$file" "$RUNTIME/$file" \
    || fail "shared host helper omitted the adopted $file copy"
done
jq -e '.format == "wprism-rollback-target/v1" and (.target_id | test("^[a-f0-9]{32}$"))' \
  "$RUNTIME_SITE/.wprism/control/target.json" >/dev/null \
  || fail 'shared host helper did not initialize the durable recovery authority'
RUNTIME_BEFORE=$(find "$RUNTIME" -type f -exec shasum -a 256 {} \; | LC_ALL=C sort)
WPRISM_EXPECTED_SOURCE_SHA="$RUNTIME_HEAD" \
  wprism_host_install_recovery_runtime "$RUNTIME_SOURCE" "$RUNTIME_SITE" \
  || fail 'shared host helper is not idempotent over an exact installed runtime'
[ "$(find "$RUNTIME" -type f -exec shasum -a 256 {} \; | LC_ALL=C sort)" = "$RUNTIME_BEFORE" ] \
  || fail 'shared host helper moved exact runtime bytes on retry'
printf '%s\n' 'tampered-runtime' >> "$RUNTIME/CanonicalJson.php"
RUNTIME_REFUSAL_RC=0
RUNTIME_REFUSAL=$(WPRISM_EXPECTED_SOURCE_SHA="$RUNTIME_HEAD" \
  wprism_host_install_recovery_runtime "$RUNTIME_SOURCE" "$RUNTIME_SITE" 2>&1) \
  || RUNTIME_REFUSAL_RC=$?
[ "$RUNTIME_REFUSAL_RC" -ne 0 ] \
  && grep -Fq 'installed runtime differs from candidate bytes' <<<"$RUNTIME_REFUSAL" \
  && grep -Fq 'tampered-runtime' "$RUNTIME/CanonicalJson.php" \
  || fail "shared host helper replaced or admitted divergent durable runtime bytes: $RUNTIME_REFUSAL"
cp "$RUNTIME_SOURCE/recovery/CanonicalJson.php" "$RUNTIME/CanonicalJson.php"
mv "$RUNTIME/ProtocolLock.php" "$RUNTIME/ProtocolLock.real.php"
ln -s ProtocolLock.real.php "$RUNTIME/ProtocolLock.php"
RUNTIME_REFUSAL_RC=0
RUNTIME_REFUSAL=$(WPRISM_EXPECTED_SOURCE_SHA="$RUNTIME_HEAD" \
  wprism_host_install_recovery_runtime "$RUNTIME_SOURCE" "$RUNTIME_SITE" 2>&1) \
  || RUNTIME_REFUSAL_RC=$?
[ "$RUNTIME_REFUSAL_RC" -ne 0 ] \
  && grep -Fq 'installed runtime contains an unsafe node' <<<"$RUNTIME_REFUSAL" \
  && [ -L "$RUNTIME/ProtocolLock.php" ] \
  || fail "shared host helper followed or replaced an installed runtime symlink: $RUNTIME_REFUSAL"

CONF_CLONE_LINE=$(grep -n '^git clone -q "\$ORIGIN" "\$R2"' conformance/run.sh | cut -d: -f1)
CONF_RUNTIME_LINE=$(grep -n '^wprism_host_install_recovery_runtime .* "\$R2"' conformance/run.sh | cut -d: -f1)
CONF_DEPLOY_LINE=$(grep -n '^DEPLOY_OUT=.*host_wprism conf2 deploy' conformance/run.sh | cut -d: -f1)
[ "$CONF_CLONE_LINE" -lt "$CONF_RUNTIME_LINE" ] && [ "$CONF_RUNTIME_LINE" -lt "$CONF_DEPLOY_LINE" ] \
  || fail 'conformance must install target recovery authority after clone and before host deploy'
grep -Fq 'wprism_host_install_recovery_runtime "$PAIR_SOURCE_ROOT" "$R1"' conformance/run.sh \
  && grep -Fq 'wprism_host_install_recovery_runtime "$PAIR_SOURCE_ROOT" "$R2"' conformance/run.sh \
  || fail 'conformance recovery bytes must come from the exact worktree selected for pair mounts'
grep -A8 '^clone_case_target() {' tests/certify/certify_version_matrix.sh \
  | grep -Fq 'wprism_host_install_recovery_runtime "$PAIR_SOURCE_ROOT"' \
  || fail 'the version matrix does not reinstall recovery authority from its selected pair source after each repository reset'
grep -Fq 'Rank Math negative control could not install the source recovery runtime' \
  ../adapter-packages/rank-math/tests/certify/version-matrix.sh \
  || fail 'Rank Math negative control can reach host deploy without recovery authority'
for scenario in \
  ../integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh \
  ../integration-scenarios/rank-math-yoast-incompatibility/tests/live/regress_rank_math_yoast_incompatibility.sh; do
  grep -Fq 'wprism_host_install_recovery_runtime' "$scenario" \
    || fail "$scenario can reach host deploy without the adopted recovery premise"
done
pass 'pair-backed host deploys install exact initialized recovery bytes, refuse divergence, and stage before mutation'

write_probe_disposition() {
  local subject="$1"
  mkdir -p "$MATRIX_PROBE/adapter-packages/$subject/package" "$MATRIX_PROBE/adapter-packages/$subject/tests/certify"
  printf '%s\n' '{"evidence":{"tests":["exact-artifact-version-matrix"]}}' \
    > "$MATRIX_PROBE/adapter-packages/$subject/package/disposition.json"
}

write_probe_disposition acf
cat > "$MATRIX_PROBE/adapter-packages/acf/tests/certify/version-matrix.sh" <<'SH'
VMATRIX_PLUGIN_SLUG=advanced-custom-fields
version_matrix_workflow() { :; }
version_matrix_preflight() { printf '%s\n' 'selected-acf-only'; exit 0; }
SH
write_probe_disposition sibling
printf '%s\n' 'exit 73' > "$MATRIX_PROBE/adapter-packages/sibling/tests/certify/version-matrix.sh"
PROBE_RC=0
PROBE_OUT=$(VMATRIX_MANIFEST=acf bash "$MATRIX_PROBE/sandbox/tests/certify/certify_version_matrix.sh" 2>&1) || PROBE_RC=$?
[ "$PROBE_RC" -eq 0 ] && [ "$PROBE_OUT" = selected-acf-only ] \
  || fail "a sibling capsule affected the selected ACF workflow: rc=$PROBE_RC output=$PROBE_OUT"

write_probe_disposition new-adapter
cat > "$MATRIX_PROBE/adapter-packages/new-adapter/tests/certify/version-matrix.sh" <<'SH'
VMATRIX_PLUGIN_SLUG=new-adapter
version_matrix_workflow() { :; }
version_matrix_preflight() { printf '%s\n' 'selected-new-adapter'; exit 0; }
SH
PROBE_RC=0
PROBE_OUT=$(VMATRIX_MANIFEST=new-adapter bash "$MATRIX_PROBE/sandbox/tests/certify/certify_version_matrix.sh" 2>&1) || PROBE_RC=$?
[ "$PROBE_RC" -eq 0 ] && [ "$PROBE_OUT" = selected-new-adapter ] \
  || fail "a new package-owned workflow required central driver registration: rc=$PROBE_RC output=$PROBE_OUT"

write_probe_disposition missing-workflow
printf '%s\n' 'VMATRIX_PLUGIN_SLUG=missing-workflow' \
  > "$MATRIX_PROBE/adapter-packages/missing-workflow/tests/certify/version-matrix.sh"
PROBE_RC=0
PROBE_OUT=$(VMATRIX_MANIFEST=missing-workflow bash "$MATRIX_PROBE/sandbox/tests/certify/certify_version_matrix.sh" 2>&1) || PROBE_RC=$?
[ "$PROBE_RC" -ne 0 ] \
  && grep -q 'certification capsule does not define version_matrix_workflow' <<<"$PROBE_OUT" \
  || fail "a package without a workflow did not refuse before pair startup: rc=$PROBE_RC output=$PROBE_OUT"
pass 'selected capsule isolation, registry-free package addition, and missing-workflow refusal are executable offline contracts'

# issue #3391: wiring is necessary but not sufficient for require_wprism_answered.
# Its whole safety argument is that the "answered" marker is BROAD — a narrow
# marker demotes a real, differently-shaped engine answer into an
# "infrastructure failure:" signal, which silently weakens the engine assertion
# the call site exists to make. Pin that breadth here, in the fragment's own
# suite, by sourcing the real fragment with a non-exiting fail() and running
# both modes over real capture shapes. No docker, no pair.
probe() { # probe <mode> <capture> — prints the helper's verdict; exit 1 = it failed
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    require_wprism_answered 'unit probe' "$1" "$2"
    printf 'ANSWERED\n'
  )
}
expect_answered() { # expect_answered <label> <mode> <capture>
  local out rc=0
  out=$(probe "$2" "$3") || rc=$?
  [ "$rc" -eq 0 ] && [ "$out" = ANSWERED ] \
    || fail "require_wprism_answered $2 mode rejected $1 — a narrow marker turns a healthy engine answer into an infrastructure signal: $out"
}
expect_infrastructure() { # expect_infrastructure <label> <mode> <capture>
  local out rc=0
  out=$(probe "$2" "$3") || rc=$?
  [ "$rc" -ne 0 ] \
    || fail "require_wprism_answered $2 mode accepted $1 as an answer — a dead invocation would still reach the engine accusation"
  case "$out" in
    'infrastructure failure: '*) : ;;
    *) fail "require_wprism_answered $2 mode failed on $1 without the grep-able 'infrastructure failure:' prefix: $out" ;;
  esac
}

REFUSAL_ENVELOPE='{"format":"wprism-command-refusal/v1","ok":false,"command":"capture","reason_code":"unsupported_deletion"}'
COMPOSE_DEATH=' Container wprism-pair-cli1-1  Creating
Error response from daemon: could not create container: context deadline exceeded'

expect_answered 'a wprism-command-refusal/v1 envelope' json "$REFUSAL_ENVELOPE"
expect_answered 'a plan success summary object' json '{"create":[],"update":[],"conflict":[]}'
# The widening this pins: `wp wprism pending --format=json` answers with a LIST,
# and empty is its healthy answer (conformance/checks/core.sh asserts exactly
# `[]`). Object-only would report that engine as dead infrastructure.
expect_answered 'an empty JSON array (wprism pending answers [] when clean)' json '[]'
expect_answered 'a populated JSON array' json '[{"section":"widgets","key":"regress_fake_type"}]'
# ...without changing the mode's read: still the LAST non-empty line.
expect_answered 'an envelope followed by blank lines' json "$REFUSAL_ENVELOPE

"
expect_infrastructure 'an envelope followed by non-JSON output' json "$REFUSAL_ENVELOPE
not json at all"
expect_infrastructure 'compose container-creation chatter' json "$COMPOSE_DEATH"
expect_infrastructure 'an empty capture' json ''
expect_infrastructure 'a whitespace-only capture' json $' \t\n\n '
expect_infrastructure 'multiple JSON values on one last non-empty line' json '{"first":true} {"second":true}'
expect_infrastructure 'a bare JSON scalar' json '"refused"'

expect_answered "wp-cli's Error: framing" human 'Error: wprism: deletion intent for table:nf3_forms is unsupported'
expect_answered "wp-cli's Success: framing" human 'Success: captured 12 posts, 4 terms -> /siterepo/state'
expect_answered "wp-cli's Warning: framing" human 'Warning: regen_pending markers outstanding'
expect_answered "wprism's own message prefix without wp-cli framing" human 'wprism: mapped identity history is missing'
expect_answered "PHP's own fatal framing" human 'PHP Fatal error:  Uncaught RuntimeException'
expect_infrastructure 'compose container-creation chatter' human "$COMPOSE_DEATH"
expect_infrastructure 'an empty capture' human ''
pass "require_wprism_answered accepts every shape a live wprism answer takes (json: object OR array; human: wp-cli/wprism/PHP framing) and only fires on a capture with no answer in it"

capture_probe() { # <success|refusal|dead>
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    fake_wprism() {
      case "$1" in
        success)
          printf 'compose prelude\n{"canary":"clean"}\n'
          ;;
        refusal)
          printf '{"format":"wprism-command-refusal/v1","ok":false,"command":"apply"}\n'
          return 7
          ;;
        dead)
          printf '%s\n' "$COMPOSE_DEATH"
          return 9
          ;;
      esac
    }
    RESULT=unset
    capture_wprism_json_success RESULT 'unit WPrism apply' fake_wprism "$1"
    printf 'RESULT=%s\n' "$RESULT"
  ) 2>&1
}
CAPTURE_SUCCESS=$(capture_probe success)
[ "$CAPTURE_SUCCESS" = 'RESULT={"canary":"clean"}' ] \
  || fail "capture_wprism_json_success did not return the exact final success envelope: $CAPTURE_SUCCESS"
CAPTURE_REFUSAL=$(capture_probe refusal) && CAPTURE_REFUSAL_RC=0 || CAPTURE_REFUSAL_RC=$?
[ "$CAPTURE_REFUSAL_RC" -ne 0 ] \
  && grep -Fq '"format":"wprism-command-refusal/v1"' <<<"$CAPTURE_REFUSAL" \
  && grep -Fq 'unit WPrism apply failed with exit 7' <<<"$CAPTURE_REFUSAL" \
  || fail "capture_wprism_json_success swallowed or misclassified a nonzero WPrism envelope: $CAPTURE_REFUSAL"
CAPTURE_DEAD=$(capture_probe dead) && CAPTURE_DEAD_RC=0 || CAPTURE_DEAD_RC=$?
[ "$CAPTURE_DEAD_RC" -ne 0 ] \
  && grep -Fq 'infrastructure failure: unit WPrism apply was never answered' <<<"$CAPTURE_DEAD" \
  && ! grep -Fq 'unit WPrism apply failed with exit 9' <<<"$CAPTURE_DEAD" \
  || fail "capture_wprism_json_success accused the engine after a dead transport: $CAPTURE_DEAD"
grep -q '^capture_wprism_json_success ' conformance/run.sh \
  || fail "conformance apply does not use the refusal-preserving JSON command wrapper"
grep -Eq 'require_wprism_answered capture_wprism_json_success require_observed_nonempty' conformance/run.sh \
  || fail "manifest check subprocesses cannot call the refusal-preserving JSON command wrapper"
grep -Eq 'establish_woocommerce_hpos normalize_woocommerce_harness_placeholder_mode' conformance/run.sh \
  || fail "WooCommerce manifest check subprocesses cannot call their shared lifecycle helpers"
grep -q '^export WPRISM_ARTIFACT_LIBRARY_ROOT$' conformance/run.sh \
  || fail "package check subprocesses do not receive a stable artifact-library repository root"
grep -Eq 'artifact_library_repo_root artifact_library_package_context artifact_library_participant_context' conformance/run.sh \
  && grep -Eq 'artifact_library_emit' conformance/run.sh \
  && grep -Eq 'validate_artifact_library artifact_library_jq' conformance/run.sh \
  || fail "package check subprocesses cannot call the convention-discovered artifact-library helpers"
grep -Fq 'archive_root=$(artifact_library_platform_jq -r --arg slug "$slug" --arg version "$version"' \
  conformance/run.sh \
  || fail "conformance theme archive roots are not resolved from the explicit platform library"
! grep -q 'APPLY_JSON=.*wprism apply.*| tail -1' conformance/run.sh \
  || fail "conformance apply still discards a nonzero refusal through its old tail pipeline"
pass "conformance children receive assertion, lifecycle, and artifact-library helpers; apply preserves answered refusals"

# A mode typo must be a caller bug, never an infrastructure verdict: it may not
# borrow the prefix operators grep to route a failure away from the engine.
TYPO_OUT=$(probe jsonn "$REFUSAL_ENVELOPE") && TYPO_RC=0 || TYPO_RC=$?
[ "$TYPO_RC" -ne 0 ] || fail "require_wprism_answered accepted an unknown mode silently"
case "$TYPO_OUT" in
  'infrastructure failure: '*) fail "an unknown mode reported itself as an infrastructure failure: $TYPO_OUT" ;;
esac
grep -q "^require_wprism_answered: unknown mode 'jsonn' (expected human|json)$" <<<"$TYPO_OUT" \
  || fail "an unknown mode did not name itself as a caller bug: $TYPO_OUT"
pass "an unknown mode fails loudly as a caller bug, outside the infrastructure-failure grammar"

printf '\033[1;32m✔ REGRESS_CONFORMANCE_ASSERTS PASSED\033[0m\n'
