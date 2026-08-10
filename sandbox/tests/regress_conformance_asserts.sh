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
cd "$(dirname "$0")/.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

FRAGMENT=conformance/asserts.sh
[ -f "$FRAGMENT" ] || fail "shared fragment $FRAGMENT is missing"

# Every require_* invoked anywhere in the hooks both harnesses source...
# (require_once is PHP inside the hooks' heredocs, not a bash helper.)
CALLED=$(grep -rhoE '\brequire_[a-z_]+' conformance/seeds/ conformance/postdeploy/ conformance/checks/ | grep -v '^require_once$' | sort -u)
[ -n "$CALLED" ] || fail "no require_* calls found under conformance/seeds/ + postdeploy/ + checks/ — the grep itself regressed"

# ...must be defined in the fragment (definition = `name() {`).
MISSING=""
while IFS= read -r fn; do
  grep -qE "^${fn}\(\) \{" "$FRAGMENT" || MISSING="$MISSING $fn"
done <<<"$CALLED"
[ -z "$MISSING" ] || fail "helper(s) called by the hooks but not defined in $FRAGMENT:$MISSING — the next bundle dies at leg 12 with 'command not found'"
pass "every hook-called require_* helper ($(wc -l <<<"$CALLED" | tr -d ' ') distinct) is defined in the shared fragment"

# Both harnesses must source the fragment.
for harness in conformance/run.sh tests/certify_version_matrix.sh; do
  grep -qE '^\. conformance/asserts\.sh' "$harness" \
    || fail "$harness does not source the shared fragment — its hooks' premise assertions die at runtime"
done
pass "both hook-sourcing harnesses source the fragment"

# And the fragment must not silently grow a second definition home: the
# helpers may be defined nowhere else.
DUPES=$(grep -rlE '^require_[a-z_]+\(\) \{' conformance/ tests/ | grep -v "^$FRAGMENT\$" | grep -v '^tests/regress_conformance_asserts.sh$' || true)
[ -z "$DUPES" ] || fail "helper definitions exist outside the fragment (one owner per grammar):$DUPES"
pass "the fragment is the single definition home"

printf '\033[1;32m✔ REGRESS_CONFORMANCE_ASSERTS PASSED\033[0m\n'
