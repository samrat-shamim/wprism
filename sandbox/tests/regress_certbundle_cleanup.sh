#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"
LIB="$PWD/../lib/certbundle_cleanup.sh"
SHIPPED="$PWD/certify_reference_bundle.sh"
SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/duo-certbundle-cleanup.XXXXXX")
SUCCESS_ROOT=$(mktemp -d /tmp/duo-certbundle.cleanup-success.XXXXXX)
FAILED_ROOT=$(mktemp -d /tmp/duo-certbundle.cleanup-failed.XXXXXX)
trap 'command rm -rf -- "$SCRATCH" "$SUCCESS_ROOT" "$FAILED_ROOT"' EXIT

say()  { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

[ -f "$LIB" ] || fail "cleanup library is missing"
[ -f "$SHIPPED" ] || fail "reference certification runner is missing"
bash -n "$LIB" "$SHIPPED" || fail "cleanup library or certification runner does not parse"

say "source-only boundary"
DIRECT_OUTPUT="$SCRATCH/direct.out"
if bash "$LIB" >"$DIRECT_OUTPUT" 2>&1; then
  fail "certbundle_cleanup.sh executed directly instead of refusing"
fi
grep -qF 'source-only library' "$DIRECT_OUTPUT" \
  || fail "direct execution did not report the source-only boundary"
pass "direct execution is refused"

say "successful cleanup ordering"
SUCCESS_EVENTS="$SCRATCH/success.events"
SUCCESS_ROOT="$SUCCESS_ROOT" SUCCESS_EVENTS="$SUCCESS_EVENTS" LIB="$LIB" bash -c '
  set -euo pipefail
  source "$LIB"
  rm() {
    printf "rm\n" >>"$SUCCESS_EVENTS"
    command rm "$@"
  }
  certbundle_lock_release() {
    printf "release\n" >>"$SUCCESS_EVENTS"
  }
  WORK_ROOT="$SUCCESS_ROOT"
  certbundle_cleanup_run
' || fail "successful cleanup child failed"
[ ! -e "$SUCCESS_ROOT" ] || fail "successful cleanup left the work root behind"
[ "$(sed -n '1p' "$SUCCESS_EVENTS")" = rm ] \
  || fail "successful cleanup did not remove the work root first"
[ "$(sed -n '2p' "$SUCCESS_EVENTS")" = release ] \
  || fail "successful cleanup did not release the lock second"
pass "successful cleanup removes then releases"

say "failed removal still releases"
FAILED_EVENTS="$SCRATCH/failed.events"
FAILED_OUTPUT="$SCRATCH/failed.out"
FAILED_ROOT="$FAILED_ROOT" FAILED_EVENTS="$FAILED_EVENTS" LIB="$LIB" bash -c '
  set -euo pipefail
  source "$LIB"
  rm() {
    printf "rm\n" >>"$FAILED_EVENTS"
    return 1
  }
  certbundle_lock_release() {
    printf "release\n" >>"$FAILED_EVENTS"
  }
  WORK_ROOT="$FAILED_ROOT"
  certbundle_cleanup_run
' >"$FAILED_OUTPUT" 2>&1 || fail "failed-removal cleanup child failed"
[ -e "$FAILED_ROOT" ] || fail "failed-removal case unexpectedly removed the work root"
grep -qF "WARNING: could not remove the work root $FAILED_ROOT" "$FAILED_OUTPUT" \
  || fail "failed-removal case did not emit the actionable warning"
[ "$(sed -n '1p' "$FAILED_EVENTS")" = rm ] \
  || fail "failed-removal case did not attempt removal"
[ "$(sed -n '2p' "$FAILED_EVENTS")" = release ] \
  || fail "failed-removal case skipped lock release"
pass "failed removal warns and still releases"

say "unsafe path refusal"
UNSAFE_PATH="$SCRATCH/unsafe-root"
UNSAFE_EVENTS="$SCRATCH/unsafe.events"
UNSAFE_PATH="$UNSAFE_PATH" UNSAFE_EVENTS="$UNSAFE_EVENTS" LIB="$LIB" bash -c '
  set -euo pipefail
  source "$LIB"
  certbundle_lock_release() {
    printf "release\n" >>"$UNSAFE_EVENTS"
  }
  WORK_ROOT="$UNSAFE_PATH"
  certbundle_cleanup_run
' >"$SCRATCH/unsafe.out" 2>&1 || fail "unsafe-path cleanup child failed"
grep -qF "refusing unsafe work cleanup path: $UNSAFE_PATH" "$SCRATCH/unsafe.out" \
  || fail "unsafe-path cleanup did not refuse the path"
grep -qFx release "$UNSAFE_EVENTS" || fail "unsafe-path cleanup skipped lock release"
pass "unsafe paths are refused before removal"

say "owned-pair teardown arguments"
PAIR_ARGS="$SCRATCH/pair.args"
PAIR_ARGS="$PAIR_ARGS" LIB="$LIB" bash -c '
  set -euo pipefail
  source "$LIB"
  bash() {
    printf "%s\n" "$*" >"$PAIR_ARGS"
  }
  PAIR=cleanup-regression
  certbundle_destroy_own_pair
' || fail "owned-pair teardown child failed"
grep -qFx 'bin/pair.sh destroy cleanup-regression' "$PAIR_ARGS" \
  || fail "owned-pair teardown changed the pair destroy arguments"
pass "owned-pair teardown preserves the exact command"

say "runner wiring"
grep -qF 'source lib/certbundle_cleanup.sh' "$SHIPPED" \
  || fail "runner does not source the cleanup library"
grep -q '^trap certbundle_cleanup_run EXIT' "$SHIPPED" \
  || fail "runner does not install certbundle_cleanup_run as its EXIT trap"
! grep -q '^cleanup_run()' "$SHIPPED" \
  || fail "runner still defines the extracted cleanup_run helper"
! grep -q '^destroy_own_pair()' "$SHIPPED" \
  || fail "runner still defines the extracted destroy_own_pair helper"
grep -q '^certbundle_cleanup_run()' "$LIB" \
  || fail "cleanup library does not own certbundle_cleanup_run"
grep -q '^certbundle_destroy_own_pair()' "$LIB" \
  || fail "cleanup library does not own certbundle_destroy_own_pair"
grep -qF 'sandbox/lib/certbundle_cleanup.sh' "$SHIPPED" \
  || fail "bundle bound-input list omits the cleanup library"
pass "runner sources and binds the extracted helpers"

printf '✔ REGRESS_CERTBUNDLE_CLEANUP PASSED\n'
