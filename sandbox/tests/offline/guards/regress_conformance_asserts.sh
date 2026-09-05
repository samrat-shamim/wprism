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

# Reusable hooks can cross from the standalone runner into a package's exact-
# version workflow. Their host command must therefore resolve through one
# role-based ABI, not a runner-private function that disappears in the second
# harness (Rank Math's first 1.0.277 boundary reached exactly that exit-127
# failure after every product assertion before it had passed).
HOST_LIBRARY=lib/host_orchestrator.sh
grep -q '^host_wprism() {' "$HOST_LIBRARY" \
  || fail 'the shared host library does not define the role-based host_wprism hook ABI'
for harness in conformance/run.sh tests/certify/certify_version_matrix.sh; do
  grep -q '^\. lib/host_orchestrator\.sh$' "$harness" \
    || fail "$harness does not source the shared host_wprism implementation"
done
HOST_DEFINITION_DUPES=$(grep -rlE '^host_wprism\(\) \{' \
  conformance/ tests/ ../adapter-packages/*/tests/ \
  | grep -v '^tests/offline/guards/regress_conformance_asserts.sh$' || true)
[ -z "$HOST_DEFINITION_DUPES" ] \
  || fail "host_wprism has a runner/package-private definition instead of one shared owner: $HOST_DEFINITION_DUPES"
grep -Eq '^export -f .*[[:space:]]host_wprism([[:space:]]|$)' conformance/run.sh \
  && grep -q '^export WPRISM_HOST_CLI WPRISM_HOST_REGISTRY$' conformance/run.sh \
  || fail 'standalone conformance does not export host_wprism and its context to child check hooks'
grep -q '^WPRISM_HOST_REGISTRY=' tests/certify/certify_version_matrix.sh \
  && grep -q '^WPRISM_HOST_CLI=' tests/certify/certify_version_matrix.sh \
  && grep -q '^export WPRISM_HOST_CLI WPRISM_HOST_REGISTRY$' tests/certify/certify_version_matrix.sh \
  || fail 'the exact-version driver does not initialize the shared host_wprism context'
PRIVATE_MATRIX_HOST=$(grep -rE 'host_wprism_vmatrix' \
  tests/certify/ ../adapter-packages/*/tests/certify/ || true)
[ -z "$PRIVATE_MATRIX_HOST" ] \
  || fail "an exact-version workflow still depends on a driver-private host wrapper: $PRIVATE_MATRIX_HOST"
HOST_ROUTES=$(
  . "$HOST_LIBRARY"
  wprism_host_call() { printf '<%s>' "$@"; printf '\n'; }
  WPRISM_HOST_CLI=/candidate/cli/wprism
  WPRISM_HOST_REGISTRY=/candidate/registry.json
  WPRISM_PAIR=abiprobe
  host_wprism conf1 scope --roots=post:fixture
  host_wprism conf2 deploy --force-code-drift
)
[ "$HOST_ROUTES" = $'</candidate/cli/wprism></candidate/registry.json><wprism-abiprobe><abiprobe1><scope><--roots=post:fixture>\n</candidate/cli/wprism></candidate/registry.json><wprism-abiprobe><abiprobe2><deploy><--force-code-drift>' ] \
  || fail "host_wprism did not map both roles to their exact pair environments: $HOST_ROUTES"
HOST_CHILD_ROUTE=$(
  . "$HOST_LIBRARY"
  wprism_host_call() { printf '<%s>' "$@"; }
  export -f host_wprism wprism_host_call
  export WPRISM_HOST_CLI=/candidate/cli/wprism
  export WPRISM_HOST_REGISTRY=/candidate/registry.json
  export WPRISM_PAIR=childprobe
  bash -c 'host_wprism conf1 capture --format=json'
)
[ "$HOST_CHILD_ROUTE" = '</candidate/cli/wprism></candidate/registry.json><wprism-childprobe><childprobe1><capture><--format=json>' ] \
  || fail "an exported child hook could not use the complete host_wprism ABI: $HOST_CHILD_ROUTE"
HOST_ROLE_RC=0
HOST_ROLE_OUT=$(
  . "$HOST_LIBRARY"
  WPRISM_HOST_CLI=/candidate/cli/wprism
  WPRISM_HOST_REGISTRY=/candidate/registry.json
  WPRISM_PAIR=abiprobe
  host_wprism wp2 deploy 2>&1
) || HOST_ROLE_RC=$?
[ "$HOST_ROLE_RC" -eq 64 ] \
  && [ "$HOST_ROLE_OUT" = "wprism test host: unknown role 'wp2' (expected conf1|conf2)" ] \
  || fail "host_wprism admitted a concrete driver side instead of the shared role vocabulary: rc=$HOST_ROLE_RC output=$HOST_ROLE_OUT"
pass 'standalone and exact-version hooks share one closed role-based host orchestration ABI'

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
[ "$(php -r 'printf("%o", fileperms($argv[1]) & 07777);' "$RUNTIME_SITE/.wprism")" = 1777 ] \
  && [ "$(php -r 'printf("%o", fileperms($argv[1]) & 07777);' "$RUNTIME_SITE/.wprism/control")" = 1777 ] \
  || fail 'shared host helper did not preserve sticky state and control parents across recovery initialization'
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
sed -n '/^clone_case_target() {/,/^}/p' tests/certify/certify_version_matrix.sh \
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
          printf 'compose prelude\nPHP Warning: capture diagnostic canary\n{"canary":"clean"}\n'
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
[ "$CAPTURE_SUCCESS" = $'compose prelude\nPHP Warning: capture diagnostic canary\nRESULT={"canary":"clean"}' ] \
  || fail "capture_wprism_json_success did not publish the exact final envelope while preserving prefix diagnostics: $CAPTURE_SUCCESS"
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

refusal_capture_probe() { # <refusal|success|dead>
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    fake_wprism() {
      case "$1" in
        refusal)
          printf 'compose prelude\nPHP Warning: refusal capture diagnostic canary\n{"format":"wprism-command-refusal/v1","ok":false,"command":"capture"}\n'
          return 7
          ;;
        success)
          printf 'compose prelude\n{"format":"wprism-command-refusal/v1","ok":false,"command":"capture"}\n'
          ;;
        dead)
          printf '%s\n' "$COMPOSE_DEATH"
          return 9
          ;;
      esac
    }
    RESULT=unset
    capture_wprism_json_refusal RESULT 'unit WPrism capture refusal' fake_wprism "$1"
    printf 'RESULT=%s\n' "$RESULT"
  ) 2>&1
}
REFUSAL_CAPTURE=$(refusal_capture_probe refusal)
[ "$REFUSAL_CAPTURE" = $'compose prelude\nPHP Warning: refusal capture diagnostic canary\nRESULT={"format":"wprism-command-refusal/v1","ok":false,"command":"capture"}' ] \
  || fail "capture_wprism_json_refusal did not publish the exact refusal envelope while preserving prefix diagnostics: $REFUSAL_CAPTURE"
REFUSAL_SUCCESS=$(refusal_capture_probe success) && REFUSAL_SUCCESS_RC=0 || REFUSAL_SUCCESS_RC=$?
[ "$REFUSAL_SUCCESS_RC" -ne 0 ] \
  && grep -Fq 'unit WPrism capture refusal unexpectedly succeeded: {"format":"wprism-command-refusal/v1","ok":false,"command":"capture"}' <<<"$REFUSAL_SUCCESS" \
  && ! grep -Fq 'infrastructure failure:' <<<"$REFUSAL_SUCCESS" \
  || fail "capture_wprism_json_refusal accepted or misclassified an unexpected success: $REFUSAL_SUCCESS"
REFUSAL_DEAD=$(refusal_capture_probe dead) && REFUSAL_DEAD_RC=0 || REFUSAL_DEAD_RC=$?
[ "$REFUSAL_DEAD_RC" -ne 0 ] \
  && grep -Fq 'infrastructure failure: unit WPrism capture refusal was never answered' <<<"$REFUSAL_DEAD" \
  && ! grep -Fq 'unexpectedly succeeded' <<<"$REFUSAL_DEAD" \
  || fail "capture_wprism_json_refusal accused the engine after a dead transport: $REFUSAL_DEAD"

capture_output_variable_probe() { # <output-variable>
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    fake_wprism() {
      printf '{"format":"wprism-command-refusal/v1","ok":false,"command":"capture"}\n'
      return 7
    }
    capture_wprism_json_refusal "$1" 'unit WPrism refusal output variable' fake_wprism
  ) 2>&1
}
REFUSAL_MALFORMED=$(capture_output_variable_probe 'bad-name') && REFUSAL_MALFORMED_RC=0 || REFUSAL_MALFORMED_RC=$?
[ "$REFUSAL_MALFORMED_RC" -ne 0 ] \
  && [ "$REFUSAL_MALFORMED" = 'capture_wprism_json_refusal: malformed output variable' ] \
  || fail "capture_wprism_json_refusal accepted or misreported a malformed output variable: $REFUSAL_MALFORMED"
REFUSAL_RESERVED=$(capture_output_variable_probe '__wprism_capture_result') && REFUSAL_RESERVED_RC=0 || REFUSAL_RESERVED_RC=$?
[ "$REFUSAL_RESERVED_RC" -ne 0 ] \
  && [ "$REFUSAL_RESERVED" = 'capture_wprism_json_refusal: reserved output variable prefix __wprism_capture_' ] \
  || fail "capture_wprism_json_refusal accepted or misreported its reserved output prefix: $REFUSAL_RESERVED"

capture_collision_probe() {
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    fake_success() { printf '{"canary":"clean"}\n'; }
    fake_refusal() {
      printf '{"format":"wprism-command-refusal/v1","ok":false,"command":"capture"}\n'
      return 7
    }
    collision_scope() {
      local last=unset capture=unset
      capture_wprism_json_success last 'unit WPrism success collision' fake_success
      capture_wprism_json_refusal capture 'unit WPrism refusal collision' fake_refusal
      printf 'last=%s\ncapture=%s\n' "$last" "$capture"
    }
    collision_scope
  ) 2>&1
}
CAPTURE_COLLISIONS=$(capture_collision_probe)
[ "$CAPTURE_COLLISIONS" = $'last={"canary":"clean"}\ncapture={"format":"wprism-command-refusal/v1","ok":false,"command":"capture"}' ] \
  || fail "JSON capture helpers did not publish through former local-name collisions: $CAPTURE_COLLISIONS"
grep -q '^capture_wprism_json_success ' conformance/run.sh \
  || fail "conformance apply does not use the refusal-preserving JSON command wrapper"
grep -Eq 'require_wprism_answered capture_wprism_json_success capture_wprism_json_refusal require_observed_nonempty' conformance/run.sh \
  || fail "manifest check subprocesses cannot call the success/refusal-preserving JSON command wrappers"
grep -Eq 'establish_woocommerce_hpos normalize_woocommerce_harness_placeholder_mode' conformance/run.sh \
  || fail "WooCommerce manifest check subprocesses cannot call their shared lifecycle helpers"
grep -q '^export WPRISM_ARTIFACT_LIBRARY_ROOT$' conformance/run.sh \
  || fail "package check subprocesses do not receive a stable artifact-library repository root"
grep -Eq 'artifact_library_repo_root artifact_library_package_context artifact_library_participant_context' conformance/run.sh \
  && grep -Eq 'artifact_library_emit' conformance/run.sh \
  && grep -Eq 'validate_artifact_library artifact_library_jq' conformance/run.sh \
  || fail "package check subprocesses cannot call the convention-discovered artifact-library helpers"
# Check hooks are child Bash processes. The runner owns its non-exportable
# Compose argv and has already populated the verified cache during setup; a
# hook may resolve the scoped digest through artifact_library_jq, but must not
# attempt a second fetch through runner-private topology.
ACTIVE_SHELL_HELPER=../tools/active-shell-source.php
[ -f "$ACTIVE_SHELL_HELPER" ] && [ ! -L "$ACTIVE_SHELL_HELPER" ] \
  || fail "active-shell source helper is missing or unsafe: $ACTIVE_SHELL_HELPER"
CHILD_FETCH_CALLS=''
while IFS= read -r hook; do
  ACTIVE_HOOK=$(php "$ACTIVE_SHELL_HELPER" "$hook") \
    || fail "could not classify active conformance hook source: $hook"
  if grep -Eq '(^|[;&|()[:space:]])fetch_artifact([[:space:]]|$)' <<<"$ACTIVE_HOOK"; then
    CHILD_FETCH_CALLS="${CHILD_FETCH_CALLS}${CHILD_FETCH_CALLS:+ }$hook"
  fi
done < <(find conformance/seeds conformance/postdeploy conformance/checks conformance/capture-checks \
  ../adapter-packages/*/tests/conformance -type f -name '*.sh' -print | LC_ALL=C sort)
[ -z "$CHILD_FETCH_CALLS" ] \
  || fail "child conformance hook calls runner-private fetch_artifact; resolve its scoped digest with artifact_library_jq and consume the setup-established cache path:$CHILD_FETCH_CALLS"
pass 'child conformance hooks use only the exported read-only artifact ABI after runner-owned cache population'
grep -Fq 'archive_root=$(artifact_library_platform_jq -r --arg slug "$slug" --arg version "$version"' \
  conformance/run.sh \
  || fail "conformance theme archive roots are not resolved from the explicit platform library"
! grep -q 'APPLY_JSON=.*wprism apply.*| tail -1' conformance/run.sh \
  || fail "conformance apply still discards a nonzero refusal through its old tail pipeline"
pass "conformance children receive assertion, lifecycle, and artifact-library helpers; JSON captures preserve exit classification, publish clean answers, and keep prefix diagnostics gate-visible"

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

# A positive fixture must choose its core intent, not adopt ambient drift.
# Exercise the real shared helper with an argv/stdin-recording WP boundary;
# no database, pair bootstrap, or intentionally missing-env test is altered.
core_binding_probe() { # <case> <home URL>
  local binding_case="$1" fixture_home="$2"
  local binding_trace="$MATRIX_PROBE/core-bindings-$binding_case.jsonl"
  : > "$binding_trace"
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    fixture_wp() {
      case "$1" in
        eval)
          [[ "$2" == *'["admin_email", "home", "siteurl"]'* && "$2" == *'get_option($name)'* ]] \
            || return 81
          local observed
          observed=$(jq -nc --arg home "$fixture_home" \
            '{admin_email:"admin@example.test",home:$home,siteurl:($home + "/wordpress")}')
          case "$binding_case" in
            drift) observed=$(jq -c '.home="https://unexpected.invalid"' <<<"$observed") ;;
            email) observed=$(jq -c '.admin_email="unexpected@example.test"' <<<"$observed") ;;
            empty) observed=$(jq -c '.siteurl=""' <<<"$observed") ;;
            missing) observed=$(jq -c 'del(.home)' <<<"$observed") ;;
            extra) observed=$(jq -c '.plugin_secret="must-not-bind"' <<<"$observed") ;;
            boolean) observed=$(jq -c '.admin_email=false' <<<"$observed") ;;
            multiline) observed=$(jq -c '.home="line-one\nline-two"' <<<"$observed") ;;
            dead) printf 'Container fixture creation stopped\n'; return 9 ;;
          esac
          printf '%s\n' "$observed"
          [ "$binding_case" != read_failure ] || return 7
          ;;
        wprism)
          local name="${4#--name=}" value
          case "$name" in admin_email|home|siteurl) ;; *) return 82 ;; esac
          [ "$(printf '<%s>' "$@")" = "<wprism><env-set><--repo=/siterepo><--name=$name><--stdin><--format=json>" ] \
            || return 83
          IFS= read -r value || return 84
          jq -nc --arg name "$name" --arg value "$value" \
            '{name:$name,value:$value}' >> "$binding_trace"
          case "$binding_case" in
            wrong_receipt) printf '{"name":"other","previously_set":true}\n' ;;
            malformed_receipt) printf '{"name":"%s","previously_set":"true"}\n' "$name" ;;
            write_failure) printf '{"ok":false,"format":"wprism-command-refusal/v1"}\n'; return 7 ;;
            *) printf '{"name":"%s","previously_set":true}\n' "$name" ;;
          esac
          ;;
        *) return 85 ;;
      esac
    }
    establish_core_environment_bindings fixture_wp /siterepo admin@example.test \
      "$fixture_home" "$fixture_home/wordpress"
    printf 'FIXTURE_BOUND\n'
  ) 2>&1
}
for role in source target; do
  FIXTURE_HOME="http://$role.invalid"
  BINDING_OUT=$(core_binding_probe "$role" "$FIXTURE_HOME") \
    || fail "core binding helper failed on the exact $role fixture: $BINDING_OUT"
  [ "$BINDING_OUT" = FIXTURE_BOUND ] \
    || fail "core binding helper did not accept the exact $role fixture: $BINDING_OUT"
  jq -es --arg home "$FIXTURE_HOME" '. == [
    {name:"admin_email",value:"admin@example.test"},
    {name:"home",value:$home},
    {name:"siteurl",value:($home + "/wordpress")}
  ]' "$MATRIX_PROBE/core-bindings-$role.jsonl" >/dev/null \
    || fail "core binding helper changed the $role argv/stdin values or bound more than three core options"
done
for binding_case in drift email empty missing extra boolean multiline dead read_failure; do
  BINDING_RC=0
  BINDING_OUT=$(core_binding_probe "$binding_case" http://source.invalid) || BINDING_RC=$?
  [ "$BINDING_RC" -ne 0 ] && [ ! -s "$MATRIX_PROBE/core-bindings-$binding_case.jsonl" ] \
    || fail "core binding helper wrote intent before proving its $binding_case observation: $BINDING_OUT"
done
for binding_case in wrong_receipt malformed_receipt write_failure; do
  BINDING_RC=0
  BINDING_OUT=$(core_binding_probe "$binding_case" http://source.invalid) || BINDING_RC=$?
  [ "$BINDING_RC" -ne 0 ] \
    && [ "$(wc -l < "$MATRIX_PROBE/core-bindings-$binding_case.jsonl" | tr -d ' ')" = 1 ] \
    || fail "core binding helper proceeded after its $binding_case provisioning result: $BINDING_OUT"
done
pass 'positive fixtures bind exactly their chosen core values via stdin; ambient drift, malformed observations, and invalid provisioning receipts refuse'

apply_ready_probe() {
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    assert_wprism_apply_ready 'fixture apply' "$1"
    printf 'APPLY_READY\n'
  ) 2>&1
}
READY_APPLY='{"plan":{"env_missing":1},"canary":"clean","verification":{"result":"pass"},"warnings":["adopted env term 1 as fixture-id (terms/fixture.json)","provider capability fired: fixture@1.0.0 rebuild (0.1s, verified)","native action fired: rewrite.flush (verified)"]}'
[ "$(apply_ready_probe "$READY_APPLY")" = APPLY_READY ] \
  || fail 'apply readiness rejected optional env rows or normal lifecycle receipts'
for mutation in \
  '.warnings += ["env_missing: option admin_email is required"]' \
  '.canary="dirty"' '.verification.result="fail"' 'del(.verification)' \
  'del(.warnings)' '.warnings=null' '.warnings += [null]'; do
  READY_RC=0
  READY_OUT=$(apply_ready_probe "$(jq -c "$mutation" <<<"$READY_APPLY")") || READY_RC=$?
  [ "$READY_RC" -ne 0 ] \
    || fail "apply readiness accepted missing provisioning or malformed verification: $mutation"
done
environment_ready_probe() {
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    assert_wprism_required_environment 'fixture apply' "$1" "$2"
    printf 'ENVIRONMENT_READY\n'
  ) 2>&1
}
READY_HUMAN=$'Warning: provider capability fired: fixture@1.0.0 rebuild (0.1s, verified)\nSuccess: applied 1 entities (canary clean) — plan was: {"env_missing":1}'
[ "$(environment_ready_probe human "$READY_HUMAN")" = ENVIRONMENT_READY ] \
  || fail 'human apply readiness confused an optional count or action receipt with required provisioning'
for mode in human json; do
  READY_RC=0
  if [ "$mode" = human ]; then READY_ANSWER="$READY_HUMAN"; else READY_ANSWER="$READY_APPLY"; fi
  READY_OUT=$(environment_ready_probe "$mode" $'Warning: env_missing: option home is required\n'"$READY_ANSWER") || READY_RC=$?
  [ "$READY_RC" -ne 0 ] || fail "$mode readiness discarded a required-env diagnostic in its prelude"
done
pass 'apply acceptance refuses required-env diagnostics and failed verification while retaining optional rows and lifecycle receipts'

# The shared matrix wrapper must inspect the complete capture, not just the
# final JSON line. Exercise its real definition, then execute every human
# capsule's first apply command with a fake WP process that writes its required
# warning only to stderr. A stdout-only tee reproduces the former false green.
matrix_readiness_probe() { # <json|json-warning|php-warning|human-command> [actual capsule command]
  local readiness_case="$1" capsule_apply="${2:-}"
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    eval "$(sed -n '/^assert_no_php_diagnostics() {/,/^}/p' tests/certify/certify_version_matrix.sh)"
    eval "$(sed -n '/^assert_version_matrix_apply_ready() {/,/^}/p' tests/certify/certify_version_matrix.sh)"
    VMATRIX_APPLY_LOG="$MATRIX_PROBE/matrix-apply-$readiness_case.log"
    case "$readiness_case" in
      json) printf 'Container fixture created\n%s\n' "$READY_APPLY" > "$VMATRIX_APPLY_LOG" ;;
      json-warning) printf 'Warning: env_missing: option home is required\n%s\n' "$READY_APPLY" > "$VMATRIX_APPLY_LOG" ;;
      php-warning) printf 'PHP Warning: fixture diagnostic in /fixture.php on line 12\n%s\n' "$READY_APPLY" > "$VMATRIX_APPLY_LOG" ;;
      human-command)
        REV=fixture revision=fixture
        wp2() {
          printf 'Warning: env_missing: option home is required\n' >&2
          printf 'Success: applied 1 entities (canary clean)\n'
        }
        eval "$capsule_apply"
        ;;
      *) return 86 ;;
    esac
    assert_version_matrix_apply_ready
    printf 'MATRIX_READY\n'
  ) 2>&1
}
[ "$(matrix_readiness_probe json)" = MATRIX_READY ] \
  || fail 'matrix wrapper rejected a healthy JSON answer after ordinary Compose chatter'
for readiness_case in json-warning php-warning; do
  MATRIX_READY_RC=0
  MATRIX_READY_OUT=$(matrix_readiness_probe "$readiness_case") || MATRIX_READY_RC=$?
  [ "$MATRIX_READY_RC" -ne 0 ] \
    || fail "matrix wrapper discarded the full capture's $readiness_case diagnostic"
done
HUMAN_CAPTURE_CASES=0
for capsule in ../adapter-packages/*/tests/certify/version-matrix.sh; do
  CAPSULE_APPLY=$(awk '
    /^[[:space:]]*wp2 wprism apply / && index($0, "| tee \"$VMATRIX_APPLY_LOG\"") && !/--format=json/ { print; exit }
  ' "$capsule")
  [ -n "$CAPSULE_APPLY" ] || continue
  HUMAN_CAPTURE_CASES=$((HUMAN_CAPTURE_CASES + 1))
  MATRIX_READY_RC=0
  MATRIX_READY_OUT=$(matrix_readiness_probe human-command "$CAPSULE_APPLY") || MATRIX_READY_RC=$?
  [ "$MATRIX_READY_RC" -ne 0 ] \
    && grep -q 'did not prove all required environment bindings' <<<"$MATRIX_READY_OUT" \
    || fail "$capsule lost a stderr-only required-environment diagnostic before its positive apply gate: $MATRIX_READY_OUT"
done
[ "$HUMAN_CAPTURE_CASES" -gt 0 ] || fail 'the human capsule capture regression exercised no callsite'
pass "the real matrix wrapper rejects full-stream diagnostics across $HUMAN_CAPTURE_CASES actual human capsule apply commands and the JSON path"

CONF_BIND_SOURCE_LINE=$(grep -n '^establish_core_environment_bindings wp_conf1 ' conformance/run.sh | cut -d: -f1)
CONF_SEED_LINE=$(grep -n '^bash "\$SEED"$' conformance/run.sh | cut -d: -f1)
CONF_CAPTURE_LINE=$(grep -n '^say "capture conf1 into the site repo"$' conformance/run.sh | cut -d: -f1)
CONF_BIND_TARGET_LINE=$(grep -n '^establish_core_environment_bindings wp_conf2 ' conformance/run.sh | cut -d: -f1)
[ "$CONF_SEED_LINE" -lt "$CONF_BIND_SOURCE_LINE" ] \
  && [ "$CONF_BIND_SOURCE_LINE" -lt "$CONF_CAPTURE_LINE" ] \
  && [ "$CONF_CLONE_LINE" -lt "$CONF_BIND_TARGET_LINE" ] \
  && [ "$CONF_BIND_TARGET_LINE" -lt "$CONF_DEPLOY_LINE" ] \
  || fail 'conformance must establish chosen core intent after seeding/cloning and before capture/deploy'
grep -Fq "assert_wprism_apply_ready 'conf2 wprism apply'" conformance/run.sh \
  || fail 'conformance can still report PASS over missing required environment bindings'
MATRIX_CLONE_PROBE=$(
  PAIR=fixture
  PAIR_SOURCE_ROOT=/exact-candidate
  PORT1=9280 PORT2=9281
  git() { :; }
  chmod() { :; }
  establish_core_environment_bindings() { printf 'BIND'; printf '<%s>' "$@"; printf '\n'; }
  wprism_host_install_recovery_runtime() { printf 'RECOVERY<%s><%s>\n' "$@"; }
  eval "$(sed -n '/^clone_case_target() {/,/^}/p' tests/certify/certify_version_matrix.sh)"
  clone_case_target
)
[ "$MATRIX_CLONE_PROBE" = $'BIND<wp1></siterepo><admin@example.test><http://localhost:9280><http://localhost:9280>\nBIND<wp2></siterepo><admin@example.test><http://localhost:9281><http://localhost:9281>\nRECOVERY</exact-candidate><siterepo/fixture1>\nRECOVERY</exact-candidate><siterepo/fixture2>' ] \
  || fail "version boundaries did not reestablish each role's chosen binding after repository reset: $MATRIX_CLONE_PROBE"
for capsule in ../adapter-packages/*/tests/certify/version-matrix.sh; do
  awk '
    index($0, "| tee \"$VMATRIX_APPLY_LOG\"") {
      if (!/--format=json/ && !/2>&1/) exit 1
      if (getline <= 0 || $0 !~ /^[[:space:]]*assert_version_matrix_apply_ready$/) exit 1
    }
  ' "$capsule" || fail "$capsule reuses a positive apply log before requiring environment readiness"
done
for harness in bin/adapter-boundary.sh tests/live/regress_core_scope_database.sh; do
  grep -q 'establish_core_environment_bindings wp1 ' "$harness" \
    && grep -q 'establish_core_environment_bindings wp2 ' "$harness" \
    || fail "$harness lacks explicit source/target core binding premises"
done
! grep -Eq 'establish_core_environment_bindings|wprism env-set' lib/pair_bootstrap.sh \
  || fail 'pair bootstrap must retain the unprovisioned premise used by negative env-set tests'
! grep -q 'establish_core_environment_bindings' tests/live/regress_env_set.sh \
  || fail 'the intentional missing-binding product regression was silently provisioned'
pass 'shared conformance, version, boundary, and database positive fixtures require explicit core intent without altering missing-binding negatives'

printf '\033[1;32m✔ REGRESS_CONFORMANCE_ASSERTS PASSED\033[0m\n'
