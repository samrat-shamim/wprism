#!/usr/bin/env bash
# Offline contract for duo deploy <env>: compile -> begin target session ->
# optional code stage -> lifecycle deploy -> optional code finalize.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DUO="$REPO_ROOT/cli/duo"
FIX="$(mktemp -d)"
BIN="$FIX/bin"
SITE="$FIX/site"
ENVS="$FIX/envs.json"
LOG="$FIX/wp.log"

pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
trap 'rm -rf "$FIX"' EXIT
mkdir -p "$BIN" "$SITE"

cat > "$BIN/wp" <<'FAKE'
#!/usr/bin/env bash
set -e
printf '%s\n' "$*" >> "$FAKE_WP_LOG"
if [[ "$1" == --path=* ]]; then shift; fi
first=$1
second=$2
if [ "$first:$second" = duo:compile ]; then
  [ "$FAKE_COMPILE_FAIL" = 1 ] && exit 6
  hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  if [ "$FAKE_CODE_ENABLED" = 1 ]; then
    summary='{"artifact_hash":"'"$hash"'","code":{"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","format":1}}'
  else
    summary='{"artifact_hash":"'"$hash"'"}'
  fi
  for arg in "$@"; do
    case "$arg" in
      --out=*) out=$(printf '%s' "$arg" | sed 's/^--out=//'); printf '%s\n' "$summary" > "$out" ;;
    esac
  done
  printf '%s\n' "$summary"
  exit 0
fi
if [ "$first:$second" = duo:promotion-begin ]; then exit 0; fi
if [ "$first:$second" = duo:promotion-abort ]; then exit 0; fi
if [ "$first:$second" = duo:code-stage ]; then [ "$FAKE_STAGE_FAIL" = 1 ] && exit 8; exit 0; fi
if [ "$first:$second" = duo:deploy ]; then [ "$FAKE_DEPLOY_FAIL" = 1 ] && exit 7; exit 0; fi
if [ "$first:$second" = duo:code-finalize ]; then [ "$FAKE_FINALIZE_FAIL" = 1 ] && exit 9; exit 0; fi
exit 11
FAKE
chmod +x "$BIN/wp"
cat > "$ENVS" <<EOF
{"envs":{"unit":{"transport":"local","wp_path":"$BIN","repo_path":"$SITE"}}}
EOF
export PATH="$BIN:$PATH" FAKE_WP_LOG="$LOG"

line() { sed -n "$1p" "$LOG"; }
calls() { wc -l < "$LOG" | tr -d ' '; }
arg() { grep -o -- "$2" <<<"$1" || true; }
invoke() {
  mode=$1
  shift
  : > "$LOG"
  if OUT="$(FAKE_CODE_ENABLED="$mode" FAKE_COMPILE_FAIL=0 FAKE_STAGE_FAIL=0 FAKE_DEPLOY_FAIL=0 FAKE_FINALIZE_FAIL=0 "$@" "$DUO" --envs-file="$ENVS" deploy unit --force-code-mismatch --force-code-drift 2>&1)"; then
    CODE=0
  else
    CODE=$?
  fi
}

# No descriptor preserves lifecycle-only deploy and never receives code flags.
invoke 0 env
[ "$CODE" -eq 0 ] || fail "legacy deploy failed: $OUT"
[ "$(calls)" = 3 ] || fail "legacy path expected three wp calls"
ONE="$(line 1)"
TWO="$(line 2)"
THREE="$(line 3)"
[[ "$ONE" == *"duo compile"* && "$TWO" == *"duo promotion-begin"* && "$THREE" == *"duo deploy"* ]] || fail "legacy phase order wrong"
[[ "$THREE" != *"--materializing-code"* && "$THREE" != *"--promotion-hold"* && "$THREE" != *"--state-handoff"* ]] || fail "legacy lifecycle got promotion-only flags"
[[ "$THREE" == *"--force-code-mismatch"* && "$THREE" == *"--force-code-drift"* ]] \
  || fail "code force flags were not forwarded"
[ -n "$(arg "$THREE" '--compiled=[^ ]*')" ] || fail "legacy lifecycle lacks artifact"
[ "$(arg "$TWO" '--promotion-owner=[^ ]*')" = "$(arg "$THREE" '--promotion-owner=[^ ]*')" ] || fail "legacy begin/lifecycle owner changed"
[ "$(arg "$TWO" '--artifact-hash=[^ ]*')" = "$(arg "$THREE" '--artifact-hash=[^ ]*')" ] || fail "legacy begin/lifecycle hash changed"
grep -q 'deploy complete: lifecycle deploy (no code descriptor)' <<<"$OUT" || fail "legacy completion missing"
pass "legacy artifact keeps lifecycle-only deploy"

# Descriptor turns on exactly stage, lifecycle, finalize. All use one artifact
# and owner; only lifecycle gets hold/materializing-code; no DB checkpoint.
invoke 1 env
[ "$CODE" -eq 0 ] || fail "code deploy failed: $OUT"
[ "$(calls)" = 5 ] || fail "code path expected five wp calls"
ONE="$(line 1)"
TWO="$(line 2)"
THREE="$(line 3)"
FOUR="$(line 4)"
FIVE="$(line 5)"
[[ "$ONE" == *"duo compile"* && "$TWO" == *"duo promotion-begin"* && "$THREE" == *"duo code-stage"* && "$FOUR" == *"duo deploy"* && "$FIVE" == *"duo code-finalize"* ]] || fail "code phase order wrong"
A="$(arg "$THREE" '--compiled=[^ ]*')"
O="$(arg "$THREE" '--promotion-owner=[^ ]*')"
H="$(arg "$THREE" '--artifact-hash=[^ ]*')"
[ -n "$A" ] && [ -n "$O" ] || fail "stage lacks artifact/owner"
[ "$(arg "$FOUR" '--compiled=[^ ]*')" = "$A" ] && [ "$(arg "$FIVE" '--compiled=[^ ]*')" = "$A" ] || fail "artifact changed between code phases"
[ "$(arg "$TWO" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$FOUR" '--promotion-owner=[^ ]*')" = "$O" ] && [ "$(arg "$FIVE" '--promotion-owner=[^ ]*')" = "$O" ] || fail "owner changed between code phases"
[ "$(arg "$TWO" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$FOUR" '--artifact-hash=[^ ]*')" = "$H" ] && [ "$(arg "$FIVE" '--artifact-hash=[^ ]*')" = "$H" ] || fail "expected artifact hash changed between phases"
[[ "$THREE" != *"--promotion-hold"* && "$THREE" != *"--materializing-code"* ]] || fail "stage got lifecycle-only flags"
[[ "$FOUR" == *"--promotion-hold"* && "$FOUR" == *"--materializing-code"* && "$FOUR" != *"--state-handoff"* ]] \
  || fail "standalone lifecycle flags crossed the promotion-only handoff boundary"
[[ "$FIVE" != *"--promotion-hold"* && "$FIVE" != *"--materializing-code"* ]] || fail "standalone finalize retained lifecycle flags"
ART="$(printf '%s' "$A" | sed 's/^--compiled=//')"
[[ "$ART" == "$SITE/.duo/artifacts/deploy-"*.json ]] || fail "artifact outside target .duo/artifacts"
[ -f "$ART" ] || fail "artifact not retained"
grep -q 'deploy complete: code-stage -> lifecycle deploy -> code-finalize' <<<"$OUT" || fail "code completion missing"
pass "code deploy stages/lifecycle-deploys/finalizes one frozen artifact"

# Stop-on-first-failure boundaries.
invoke 1 env FAKE_STAGE_FAIL=1
[ "$CODE" -eq 8 ] || fail "stage exit not propagated"
[ "$(calls)" = 4 ] || fail "later phases or cleanup were wrong after stage failure"
[[ "$(line 4)" == *"duo promotion-abort"* ]] || fail "stage failure did not clean begun session"
grep -q 'code-stage failed.*were not run' <<<"$OUT" || fail "stage stop wording missing"
pass "stage failure stops lifecycle/finalize"

invoke 1 env FAKE_DEPLOY_FAIL=1
[ "$CODE" -eq 7 ] || fail "lifecycle exit not propagated"
[ "$(calls)" = 5 ] || fail "finalize/cleanup calls wrong after lifecycle failure"
[[ "$(line 5)" == *"duo promotion-abort"* ]] || fail "lifecycle failure did not clean begun session"
pass "lifecycle failure stops finalize"

invoke 1 env FAKE_FINALIZE_FAIL=1
[ "$CODE" -eq 9 ] || fail "finalize exit not propagated"
[ "$(calls)" = 6 ] || fail "wrong calls after finalize failure"
[[ "$(line 6)" == *"duo promotion-abort"* ]] || fail "finalize failure did not clean begun session"
pass "finalize failure is non-successful"

invoke 1 env FAKE_COMPILE_FAIL=1
[ "$CODE" -eq 6 ] || fail "compile exit not propagated"
[ "$(calls)" = 1 ] || fail "code/lifecycle ran after compile failure"
grep -q 'no lifecycle or code materialization occurred' <<<"$OUT" || fail "compile boundary missing"
pass "compile failure causes no code/lifecycle call"

# Public deploy owns its repo/artifact/lease flags and exposes only the two
# force flags. A second --repo would otherwise make the lifecycle command's
# target differ from the immutable artifact's source repo.
for bad in --repo=/tmp/forged --compiled=/tmp/fake.json --artifact-hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --promotion-owner=intruder --promotion-hold --materializing-code --state-handoff --force-unresolved-refs --with-deletes; do
  : > "$LOG"
  if FAKE_CODE_ENABLED=1 "$DUO" --envs-file="$ENVS" deploy unit "$bad" >/dev/null 2>&1; then
    fail "deploy accepted $bad"
  fi
  [ "$(calls)" = 0 ] || fail "deploy contacted target after rejecting $bad"
done
pass "deploy rejects caller-owned repo/internal flags before target contact"

printf '\n✔ REGRESS_CODE_DEPLOY_UNIT PASSED\n'
