#!/usr/bin/env bash
# Regression — DUO-3374: close-gate-check.sh's squash-parent count must read
# only the commit HEADER. `git cat-file -p` prints the whole object including
# the MESSAGE, and a squash message legitimately wraps lines that begin with
# "parent " — DUO-3318's close (PR #155, a change about parent-scoped natural
# keys) was failed live by the unscoped count (2 under POSIX grep on that
# object; the live run printed 3 under this host's laxer-anchoring grep
# flavor) while `git rev-list --parents` and the API showed exactly one.
#
# Offline, no docker, no network: the counting expression is exercised against
# a scratch git repository holding the three commit shapes that matter (root,
# single-parent squash whose BODY carries `parent `-leading lines, and a real
# two-parent merge). The expression under test is EXTRACTED from the shipped
# script (whole-line, uncommented) and executed via eval, so the suite runs
# the helper's own bytes — a drifted, commented-out, or shadowed counting
# line fails the extraction instead of leaving a transcription to rot (the
# source-pin idiom, strengthened per this issue's own review).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

HELPER=../../scripts/close-gate-check.sh

say "extract the shipped counting expression itself (whole-line, uncommented) — the suite executes the helper's own bytes, so a commented-out or shadowed line cannot satisfy it"
PINNED=$(grep -x "PARENTS=.*sed '/^\$/q'.*grep -c '\^parent '.*" "$HELPER" || true)
[ "$(printf '%s\n' "$PINNED" | grep -c .)" = "1" ] \
  || fail "expected exactly one active header-scoped PARENTS= line in close-gate-check.sh (got: '$PINNED'); re-prove any replacement here before changing it"
pass "shipped expression extracted: $PINNED"

count_parents() { # runs the SHIPPED expression, not a transcription
  local SHA="$1" PARENTS=""
  eval "$PINNED"
  printf '%s' "$PARENTS"
}

SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/duo-3374.XXXXXX")
trap 'rm -rf -- "$SCRATCH"' EXIT
git -C "$SCRATCH" init -q -b main
git -C "$SCRATCH" -c user.name=duo-3374 -c user.email=t@example.test \
  commit -q --allow-empty -m "root"

say "a squash whose MESSAGE BODY wraps onto 'parent '-leading lines counts exactly 1"
git -C "$SCRATCH" -c user.name=duo-3374 -c user.email=t@example.test \
  commit -q --allow-empty -m "DUO-3374: fixture squash

The tuple-aware guard refuses when the
parent component is not participating, and the
parent-resolution tree fallback resolves the rest.
parent headers only ever appear before the blank line."
SQUASH=$(git -C "$SCRATCH" rev-parse HEAD)
BODY_MATCHES=$(git -C "$SCRATCH" cat-file -p "$SQUASH" | grep -c '^parent ' || true)
[ "$BODY_MATCHES" -ge 2 ] \
  || fail "fixture lost its point — the unscoped grep must see >=2 'parent ' lines (got $BODY_MATCHES); DUO-3318's live object measures 2 under POSIX grep, and this fixture is a deliberate superset of that shape"
GOT=$(cd "$SCRATCH" && count_parents "$SQUASH")
[ "$GOT" = "1" ] || fail "header-scoped count on the body-bearing squash was $GOT, expected 1"
pass "body-bearing squash counts 1 (unscoped grep would have said $BODY_MATCHES)"

say "a real two-parent merge still counts 2 — the check keeps refusing genuine merge commits"
git -C "$SCRATCH" checkout -q -b side "$SQUASH"
git -C "$SCRATCH" -c user.name=duo-3374 -c user.email=t@example.test \
  commit -q --allow-empty -m "side work"
git -C "$SCRATCH" checkout -q main
git -C "$SCRATCH" -c user.name=duo-3374 -c user.email=t@example.test \
  merge -q --no-ff -m "merge side" side
MERGE=$(git -C "$SCRATCH" rev-parse HEAD)
GOT=$(cd "$SCRATCH" && count_parents "$MERGE")
[ "$GOT" = "2" ] || fail "header-scoped count on a real merge was $GOT, expected 2"
pass "real merge commit still counts 2"

say "a root commit counts 0 — the expression never manufactures a parent"
ROOT=$(git -C "$SCRATCH" rev-list --max-parents=0 HEAD | head -1)
GOT=$(cd "$SCRATCH" && count_parents "$ROOT")
[ "$GOT" = "0" ] || fail "header-scoped count on a root commit was $GOT, expected 0"
pass "root commit counts 0"

printf '\n\033[1;32m✔ REGRESS_CLOSE_GATE_PARENT_COUNT PASSED\033[0m\n'
