#!/usr/bin/env bash
# Offline contract for the reference-certification exact-source boundary.
# Real temporary Git repositories exercise standalone/linked/dirty/stale
# checkout refusal and the frozen-HEAD readback without Docker or a live pair.
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

command -v git >/dev/null || fail "git required"
LIB="$PWD/../lib/certbundle_source.sh"
SHIPPED="$PWD/certify_reference_bundle.sh"
[ -f "$LIB" ] || fail "cannot find the source library"
[ -f "$SHIPPED" ] || fail "cannot find the certification runner"
bash -n "$LIB" "$SHIPPED"

SCRATCH=$(mktemp -d "${TMPDIR:-/tmp}/duo-certbundle-source.XXXXXX")
trap 'rm -rf -- "$SCRATCH"' EXIT

if bash "$LIB" >"$SCRATCH/direct.out" 2>"$SCRATCH/direct.err"; then
  fail "source-only source library executed directly"
fi
grep -qF 'certbundle_source.sh is a source-only library' "$SCRATCH/direct.err" \
  || fail "direct-execution refusal did not name the source-only contract"
pass "the source boundary is source-only"

FIXTURE="$SCRATCH/checkout"
mkdir -p "$FIXTURE/agent" "$FIXTURE/manifests" "$FIXTURE/sandbox"
# The source boundary deliberately compares its persisted mount registry to
# `pwd -P`'s canonical checkout root.  macOS exposes /var through /private,
# so make the valid fixture use that same physical spelling; the later stale
# fixture remains a genuinely different mount source rather than a lexical
# alias of this checkout.
FIXTURE="$(cd "$FIXTURE" && pwd -P)"
git -C "$FIXTURE" init -q
git -C "$FIXTURE" config user.name certbundle-source-test
git -C "$FIXTURE" config user.email certbundle-source-test@example.invalid
printf 'sandbox/.env\n' > "$FIXTURE/.gitignore"
printf 'agent\n' > "$FIXTURE/agent/input"
printf 'manifest\n' > "$FIXTURE/manifests/input"
git -C "$FIXTURE" add .gitignore agent/input manifests/input
git -C "$FIXTURE" commit -qm initial
SOURCE_SHA=$(git -C "$FIXTURE" rev-parse --verify 'HEAD^{commit}')

run_action() { # <action> [expected-sha]
  local action="$1" expected="${2:-}"
  REPO_ROOT="$FIXTURE" LIB="$LIB" ACTION="$action" EXPECTED="$expected" \
    bash -c '
      set -euo pipefail
      fail() { printf "FAIL: %s\n" "$*" >&2; exit 1; }
      source "$LIB"
      case "$ACTION" in
        checkout) certbundle_source_assert_exact_checkout ;;
        freeze) certbundle_source_freeze_sha ;;
        unchanged) certbundle_source_assert_unchanged "$EXPECTED" ;;
        expected) certbundle_source_assert_expected_sha "$EXPECTED" TEST_EXPECTED "$EXPECTED" ;;
        expected-empty) certbundle_source_assert_expected_sha "$EXPECTED" TEST_EXPECTED "" ;;
        expected-bad-shape) certbundle_source_assert_expected_sha "$EXPECTED" TEST_EXPECTED 123 ;;
        expected-mismatch) certbundle_source_assert_expected_sha "$EXPECTED" TEST_EXPECTED 0000000000000000000000000000000000000000 ;;
        *) fail "unknown source regression action: $ACTION" ;;
      esac
    '
}

say "clean standalone checkout and frozen SHA"
run_action checkout
FROZEN=$(run_action freeze)
[ "$FROZEN" = "$SOURCE_SHA" ] || fail "source freeze returned $FROZEN, expected $SOURCE_SHA"
run_action expected "$SOURCE_SHA"
run_action expected-empty "$SOURCE_SHA"
run_action unchanged "$SOURCE_SHA"
pass "clean standalone source is accepted and one exact SHA is frozen/read back"

say "valid persisted mount registry"
cat > "$FIXTURE/sandbox/.env" <<EOF
DUO_AGENT_SRC=$FIXTURE/agent
DUO_MANIFESTS_SRC=$FIXTURE/manifests
EOF
run_action checkout
pass "the expected agent and manifest mount roots remain accepted"

say "dirty, stale, linked, malformed, and moved source refuse"
printf '%s\n' changed > "$FIXTURE/agent/input"
if run_action checkout >"$SCRATCH/dirty.out" 2>&1; then
  fail "tracked source mutation was accepted"
fi
grep -qF 'dirty checkout' "$SCRATCH/dirty.out" || fail "dirty checkout refusal lost its diagnostic"
printf '%s\n' agent > "$FIXTURE/agent/input"

printf 'untracked\n' > "$FIXTURE/untracked"
if run_action checkout >"$SCRATCH/untracked.out" 2>&1; then
  fail "untracked source mutation was accepted"
fi
grep -qF 'dirty checkout' "$SCRATCH/untracked.out" || fail "untracked checkout refusal lost its diagnostic"
rm -f -- "$FIXTURE/untracked"

printf 'DUO_AGENT_SRC=%s\nDUO_MANIFESTS_SRC=%s\n' "$FIXTURE/wrong-agent" "$FIXTURE/manifests" > "$FIXTURE/sandbox/.env"
if run_action checkout >"$SCRATCH/stale.out" 2>&1; then
  fail "stale mount registry was accepted"
fi
grep -qF 'stale canonical mount registry' "$SCRATCH/stale.out" || fail "stale mount refusal lost its diagnostic"
rm -f -- "$FIXTURE/sandbox/.env"

LINKED="$SCRATCH/linked"
git -C "$FIXTURE" worktree add -q "$LINKED" HEAD
if REPO_ROOT="$LINKED" LIB="$LIB" ACTION=checkout bash -c '
    set -euo pipefail
    fail() { printf "FAIL: %s\n" "$*" >&2; exit 1; }
    source "$LIB"
    certbundle_source_assert_exact_checkout
  ' >"$SCRATCH/linked.out" 2>&1; then
  git -C "$FIXTURE" worktree remove --force "$LINKED" >/dev/null 2>&1 || true
  fail "linked worktree was accepted"
fi
grep -qF 'linked worktree' "$SCRATCH/linked.out" || fail "linked-worktree refusal lost its diagnostic"
git -C "$FIXTURE" worktree remove --force "$LINKED"

if run_action expected-bad-shape "$SOURCE_SHA" >"$SCRATCH/bad-shape.out" 2>&1; then
  fail "malformed expected SHA was accepted"
fi
grep -qF 'full lowercase Git commit SHA' "$SCRATCH/bad-shape.out" || fail "malformed SHA refusal lost its diagnostic"

if run_action expected-mismatch "$SOURCE_SHA" >"$SCRATCH/mismatch.out" 2>&1; then
  fail "mismatched expected SHA was accepted"
fi
grep -qF 'names 0000000000000000000000000000000000000000' "$SCRATCH/mismatch.out" \
  || fail "mismatched SHA refusal lost its diagnostic"

OLD_SHA="$SOURCE_SHA"
printf 'second\n' > "$FIXTURE/agent/second"
git -C "$FIXTURE" add agent/second
git -C "$FIXTURE" commit -qm second
if run_action unchanged "$OLD_SHA" >"$SCRATCH/moved.out" 2>&1; then
  fail "moved source HEAD was accepted"
fi
grep -qF 'source HEAD moved' "$SCRATCH/moved.out" || fail "moved-HEAD refusal lost its diagnostic"
pass "source mutation, stale mounts, linked worktrees, malformed expectations, and moved HEAD all refuse"

say "thin reference wrapper"
grep -qF 'source lib/certbundle_source.sh' "$SHIPPED" \
  || fail "reference runner no longer sources the source boundary"
! grep -Eq '^certbundle_source_[a-z0-9_]+\(\)' "$SHIPPED" \
  || fail "source helpers leaked back into the reference wrapper"
for helper in assert_exact_checkout freeze_sha assert_expected_sha assert_unchanged; do
  case "$helper" in
    assert_exact_checkout) grep -qFx 'certbundle_source_assert_exact_checkout' "$SHIPPED" ;;
    freeze_sha) grep -qF 'certbundle_source_freeze_sha)' "$SHIPPED" ;;
    assert_expected_sha) grep -qF 'certbundle_source_assert_expected_sha "$SOURCE_SHA"' "$SHIPPED" ;;
    assert_unchanged) grep -qF 'certbundle_source_assert_unchanged "$SOURCE_SHA"' "$SHIPPED" ;;
  esac || fail "reference wrapper no longer calls certbundle_source_${helper}"
  grep -Eq "^certbundle_source_${helper}\(\)" "$LIB" \
    || fail "source library no longer owns certbundle_source_${helper}"
done
grep -qF 'sandbox/lib/certbundle_source.sh' "$SHIPPED" \
  || fail "source boundary is not included in the certification input list"
pass "the wrapper retains only the source handoff and the library owns all four helpers"

printf '\n\033[1;32m✔ REGRESS_CERTBUNDLE_SOURCE PASSED\033[0m\n'
