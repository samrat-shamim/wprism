#!/usr/bin/env bash
# Regression — DUO-3409: the conformance core sweep's host `duo explain` envs
# registry must allocate a concurrency-safe, per-run private path on BOTH GNU
# and BSD/macOS mktemp. The prior inline `mktemp ".../duo-explain-envs.XXXXXX.json"`
# put the `.json` suffix AFTER the X-run; BSD/macOS mktemp only substitutes a
# TERMINAL X-run, so it took the template literally and every concurrent core
# sweep raced for the one fixed name (mkstemp: File exists — the DUO-3344
# exact-source sweep, PR #184). This proves the extracted helper
# (conformance/checks/_explain_registry.sh) is collision-free, that a killed
# instance cannot block a later allocation, and that no suffixed-X mktemp
# template remains at the call site. Offline: no docker, no pair.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

HELPER=conformance/checks/_explain_registry.sh
[ -f "$HELPER" ] || fail "helper $HELPER is missing"
# shellcheck disable=SC1090
source "$HELPER"
command -v alloc_explain_registry_dir >/dev/null \
  || fail "alloc_explain_registry_dir is not defined by $HELPER"

# Isolate every allocation under one scratch TMPDIR so a failure cannot litter
# the real /tmp and the "stale artifact" case is observed in a controlled space.
SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/duo-3409-test.XXXXXX")
trap 'rm -rf -- "$SCRATCH"' EXIT
export TMPDIR="$SCRATCH"

# --- Acceptance 3: BSD/macOS semantics, host-independent --------------------
# The exact BSD failure is that the X-run is NOT substituted. Prove the helper's
# allocated basename carries no literal X placeholder — i.e. mktemp DID
# substitute — which is precisely what the old suffixed template failed to do on
# this (macOS) host. The assertion holds identically on GNU.
D1=$(alloc_explain_registry_dir)
[ -d "$D1" ] || fail "helper did not create a directory: '$D1'"
case "$D1" in
  *XXXXXX*) fail "allocated path still carries a literal X-run (BSD mktemp took the template literally): $D1" ;;
esac
pass "allocated registry dir is a real, substituted path with no literal X-run: $(basename "$D1")"

# --- Acceptance 1: distinct under concurrency -------------------------------
# Fire N allocations concurrently; every path must be distinct and a real dir.
N=50
OUT=$(mktemp -d "$SCRATCH/conc.XXXXXX")
for i in $(seq 1 "$N"); do
  ( alloc_explain_registry_dir >"$OUT/$i" ) &
done
wait
COUNT=$(cat "$OUT"/* | grep -c . || true)
[ "$COUNT" -eq "$N" ] || fail "expected $N non-empty allocations, got $COUNT (a concurrent alloc failed)"
UNIQ=$(cat "$OUT"/* | sort -u | grep -c . || true)
[ "$UNIQ" -eq "$N" ] || fail "concurrent allocations collided: $UNIQ distinct of $N (the exact DUO-3344 fixed-name contention)"
while IFS= read -r d; do
  [ -d "$d" ] || fail "a concurrently-allocated path is not a directory: $d"
done < <(cat "$OUT"/*)
pass "$N concurrent allocations produced $N distinct, real directories (no fixed-name contention)"

# --- Acceptance 2: a killed instance cannot block a later allocation --------
# Simulate an interrupted prior run whose cleanup trap never fired: an allocated
# dir left in place with a partial write. A later allocation must still succeed
# and must not alias the stale path.
STALE=$(alloc_explain_registry_dir)
printf '%s' 'partial-interrupted-write' >"$STALE/envs.json"
FRESH=$(alloc_explain_registry_dir) \
  || fail "allocation failed while a stale interrupted dir existed (namespace was blocked)"
[ "$FRESH" != "$STALE" ] || fail "a fresh allocation reused a stale interrupted path: $FRESH"
[ -d "$FRESH" ] || fail "fresh allocation is not a directory: $FRESH"
pass "a stale interrupted registry dir neither blocks nor aliases a later allocation"

# --- Acceptance 3 (static guard): the buggy template cannot return ----------
# Pin the fix at the call site: core.sh must carry no mktemp template whose X-run
# is followed by a suffix (the portability bug), and must route allocation
# through the helper.
CORE=conformance/checks/core.sh
[ -f "$CORE" ] || fail "$CORE is missing"
if grep -nE 'mktemp.*X{3,}\.' "$CORE" >/dev/null; then
  fail "$CORE still has a suffixed-X mktemp template (the BSD portability bug): $(grep -nE 'mktemp.*X{3,}\.' "$CORE")"
fi
grep -qE '\balloc_explain_registry_dir\b' "$CORE" \
  || fail "$CORE no longer routes explain-registry allocation through the helper"
pass "core.sh routes the explain registry through the helper and carries no suffixed-X mktemp template"

echo "REGRESS_EXPLAIN_REGISTRY PASSED"
