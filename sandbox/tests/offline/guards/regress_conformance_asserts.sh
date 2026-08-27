#!/usr/bin/env bash
# Regression — DUO-3408: every premise/answer assertion helper the conformance
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
CALLED=$(grep -rhoE '\brequire_[a-z_]+' conformance/seeds/ conformance/postdeploy/ conformance/postapply/ conformance/checks/ conformance/capture-checks/ ../adapter-packages/*/tests/conformance/ | grep -v '^require_once$' | sort -u)
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

# ACF is the first closed package migration: its entry and hooks must be owned
# only by that package, and run.sh must select them without an aggregate row.
[ -f ../adapter-packages/acf/tests/conformance/entry.json ] \
  && [ -f ../adapter-packages/acf/tests/conformance/seed.sh ] \
  && [ -f ../adapter-packages/acf/tests/conformance/check.sh ] \
  && [ -f ../adapter-packages/acf/tests/conformance/postdeploy.sh ] \
  || fail 'the ACF package does not own its complete conformance fixture set'
jq -e 'has("acf") | not' conformance/manifests.json >/dev/null \
  || fail 'the shared conformance registry still registers ACF'
grep -q 'PACKAGE_ENTRY="$PACKAGE_CONFORMANCE/entry.json"' conformance/run.sh \
  || fail 'conformance/run.sh does not discover a package-owned entry'
pass 'ACF conformance entry and hooks are package-owned and package-first discovered'

[ -f ../adapter-packages/acf/tests/certify/version-matrix.sh ] \
  && [ ! -e tests/certify/matrix.d/acf.sh ] \
  || fail 'the ACF version-matrix hook has duplicate or missing ownership'
grep -q 'adapter-packages/\*/tests/certify/version-matrix.sh' tests/certify/certify_version_matrix.sh \
  || fail 'the shared certify matrix does not discover package-owned hooks'
grep -q 'adapter-packages/${MANIFEST}/tests/certify/version-matrix.sh' bin/adapter-boundary.sh \
  || fail 'the boundary runner does not prefer a package-owned certify hook'
pass 'ACF exact-version and boundary helpers are package-owned and generically discovered'

# DUO-3391: wiring is necessary but not sufficient for require_duo_answered.
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
    require_duo_answered 'unit probe' "$1" "$2"
    printf 'ANSWERED\n'
  )
}
expect_answered() { # expect_answered <label> <mode> <capture>
  local out rc=0
  out=$(probe "$2" "$3") || rc=$?
  [ "$rc" -eq 0 ] && [ "$out" = ANSWERED ] \
    || fail "require_duo_answered $2 mode rejected $1 — a narrow marker turns a healthy engine answer into an infrastructure signal: $out"
}
expect_infrastructure() { # expect_infrastructure <label> <mode> <capture>
  local out rc=0
  out=$(probe "$2" "$3") || rc=$?
  [ "$rc" -ne 0 ] \
    || fail "require_duo_answered $2 mode accepted $1 as an answer — a dead invocation would still reach the engine accusation"
  case "$out" in
    'infrastructure failure: '*) : ;;
    *) fail "require_duo_answered $2 mode failed on $1 without the grep-able 'infrastructure failure:' prefix: $out" ;;
  esac
}

REFUSAL_ENVELOPE='{"format":"duo-command-refusal/v1","ok":false,"command":"capture","reason_code":"unsupported_deletion"}'
COMPOSE_DEATH=' Container duo-pair-cli1-1  Creating
Error response from daemon: could not create container: context deadline exceeded'

expect_answered 'a duo-command-refusal/v1 envelope' json "$REFUSAL_ENVELOPE"
expect_answered 'a plan success summary object' json '{"create":[],"update":[],"conflict":[]}'
# The widening this pins: `wp duo pending --format=json` answers with a LIST,
# and empty is its healthy answer (conformance/checks/core.sh asserts exactly
# `[]`). Object-only would report that engine as dead infrastructure.
expect_answered 'an empty JSON array (duo pending answers [] when clean)' json '[]'
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

expect_answered "wp-cli's Error: framing" human 'Error: duo: deletion intent for table:nf3_forms is unsupported'
expect_answered "wp-cli's Success: framing" human 'Success: captured 12 posts, 4 terms -> /siterepo/state'
expect_answered "wp-cli's Warning: framing" human 'Warning: regen_pending markers outstanding'
expect_answered "duo's own message prefix without wp-cli framing" human 'duo: mapped identity history is missing'
expect_answered "PHP's own fatal framing" human 'PHP Fatal error:  Uncaught RuntimeException'
expect_infrastructure 'compose container-creation chatter' human "$COMPOSE_DEATH"
expect_infrastructure 'an empty capture' human ''
pass "require_duo_answered accepts every shape a live duo answer takes (json: object OR array; human: wp-cli/duo/PHP framing) and only fires on a capture with no answer in it"

capture_probe() { # <success|refusal|dead>
  (
    fail() { printf '%s\n' "$*"; exit 1; }
    . "$FRAGMENT"
    fake_duo() {
      case "$1" in
        success)
          printf 'compose prelude\n{"canary":"clean"}\n'
          ;;
        refusal)
          printf '{"format":"duo-command-refusal/v1","ok":false,"command":"apply"}\n'
          return 7
          ;;
        dead)
          printf '%s\n' "$COMPOSE_DEATH"
          return 9
          ;;
      esac
    }
    RESULT=unset
    capture_duo_json_success RESULT 'unit Duo apply' fake_duo "$1"
    printf 'RESULT=%s\n' "$RESULT"
  ) 2>&1
}
CAPTURE_SUCCESS=$(capture_probe success)
[ "$CAPTURE_SUCCESS" = 'RESULT={"canary":"clean"}' ] \
  || fail "capture_duo_json_success did not return the exact final success envelope: $CAPTURE_SUCCESS"
CAPTURE_REFUSAL=$(capture_probe refusal) && CAPTURE_REFUSAL_RC=0 || CAPTURE_REFUSAL_RC=$?
[ "$CAPTURE_REFUSAL_RC" -ne 0 ] \
  && grep -Fq '"format":"duo-command-refusal/v1"' <<<"$CAPTURE_REFUSAL" \
  && grep -Fq 'unit Duo apply failed with exit 7' <<<"$CAPTURE_REFUSAL" \
  || fail "capture_duo_json_success swallowed or misclassified a nonzero Duo envelope: $CAPTURE_REFUSAL"
CAPTURE_DEAD=$(capture_probe dead) && CAPTURE_DEAD_RC=0 || CAPTURE_DEAD_RC=$?
[ "$CAPTURE_DEAD_RC" -ne 0 ] \
  && grep -Fq 'infrastructure failure: unit Duo apply was never answered' <<<"$CAPTURE_DEAD" \
  && ! grep -Fq 'unit Duo apply failed with exit 9' <<<"$CAPTURE_DEAD" \
  || fail "capture_duo_json_success accused the engine after a dead transport: $CAPTURE_DEAD"
grep -q '^capture_duo_json_success ' conformance/run.sh \
  || fail "conformance apply does not use the refusal-preserving JSON command wrapper"
grep -Eq 'require_duo_answered capture_duo_json_success require_observed_nonempty' conformance/run.sh \
  || fail "manifest check subprocesses cannot call the refusal-preserving JSON command wrapper"
grep -Eq 'establish_woocommerce_hpos normalize_woocommerce_harness_placeholder_mode' conformance/run.sh \
  || fail "WooCommerce manifest check subprocesses cannot call their shared lifecycle helpers"
! grep -q 'APPLY_JSON=.*duo apply.*| tail -1' conformance/run.sh \
  || fail "conformance apply still discards a nonzero refusal through its old tail pipeline"
pass "conformance apply preserves answered refusal envelopes, separates dead transport, and publishes only successful JSON"

# A mode typo must be a caller bug, never an infrastructure verdict: it may not
# borrow the prefix operators grep to route a failure away from the engine.
TYPO_OUT=$(probe jsonn "$REFUSAL_ENVELOPE") && TYPO_RC=0 || TYPO_RC=$?
[ "$TYPO_RC" -ne 0 ] || fail "require_duo_answered accepted an unknown mode silently"
case "$TYPO_OUT" in
  'infrastructure failure: '*) fail "an unknown mode reported itself as an infrastructure failure: $TYPO_OUT" ;;
esac
grep -q "^require_duo_answered: unknown mode 'jsonn' (expected human|json)$" <<<"$TYPO_OUT" \
  || fail "an unknown mode did not name itself as a caller bug: $TYPO_OUT"
pass "an unknown mode fails loudly as a caller bug, outside the infrastructure-failure grammar"

printf '\033[1;32m✔ REGRESS_CONFORMANCE_ASSERTS PASSED\033[0m\n'
