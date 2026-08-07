#!/usr/bin/env bash
# Offline product-path regression for DUO-3216's host orchestrator. A fake
# wp binary records exactly what `cli/duo promote` asks the local transport to
# execute, proving compile -> checkpoint -> deploy -> apply ordering, one frozen
# artifact across both mutation phases, and stop-on-first-failure recovery text.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DUO="$REPO_ROOT/cli/duo"
FIX="$(mktemp -d)"
FAKE_WP="$FIX/bin"
SITE="$FIX/site"
ENVS="$FIX/envs.json"
LOG="$FIX/wp.log"

pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
cleanup() { rm -rf "$FIX"; }
trap cleanup EXIT

mkdir -p "$FAKE_WP" "$SITE"

cat > "$FAKE_WP/wp" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_WP_LOG"
args=("$@")
pos=0
if [[ "${args[0]:-}" == --path=* ]]; then pos=1; fi
first="${args[$pos]:-}"
second="${args[$((pos + 1))]:-}"

if [ "$first" = duo ] && [ "$second" = compile ]; then
  [ "${FAKE_COMPILE_FAIL:-0}" = 0 ] || exit 6
  artifact_hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  for arg in "${args[@]}"; do
    if [[ "$arg" == --out=* ]]; then
      out="${arg#--out=}"
      printf '{"artifact_hash":"%s"}\n' "$artifact_hash" > "$out"
    fi
  done
  printf '{"artifact_hash":"%s"}\n' "$artifact_hash"
  exit 0
fi

if [ "$first" = db ] && [ "$second" = export ]; then
  file="${args[$((pos + 2))]}"
  printf '%s\n' snapshot > "$file"
  printf '%s\n' "$file"
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = deploy ]; then
  [ "${FAKE_DEPLOY_FAIL:-0}" = 0 ] || exit 7
  printf 'deploy ok\n'
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = apply ]; then
  printf 'apply ok\n'
  exit 0
fi

exit 9
FAKE
chmod +x "$FAKE_WP/wp"

cat > "$ENVS" <<EOF
{"envs":{"unit":{"transport":"local","wp_path":"$FAKE_WP","repo_path":"$SITE"}}}
EOF

export PATH="$FAKE_WP:$PATH" FAKE_WP_LOG="$LOG"

OUT="$($DUO --envs-file="$ENVS" promote unit --default-author=admin 2>&1)" \
  || fail "successful promote exited non-zero: $OUT"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 4 ] || fail "expected four wp calls, got ${#CALLS[@]}"
[[ "${CALLS[0]}" == *"duo compile"* ]] || fail "phase 1 was not compile"
[[ "${CALLS[1]}" == *"db export"* ]] || fail "phase 2 was not db export"
[[ "${CALLS[2]}" == *"duo deploy"* ]] || fail "phase 3 was not deploy"
[[ "${CALLS[3]}" == *"duo apply"* ]] || fail "phase 4 was not apply"
DEPLOY_ARTIFACT="$(printf '%s\n' "${CALLS[2]}" | grep -o -- '--compiled=[^ ]*')"
APPLY_ARTIFACT="$(printf '%s\n' "${CALLS[3]}" | grep -o -- '--compiled=[^ ]*')"
[ "$DEPLOY_ARTIFACT" = "$APPLY_ARTIFACT" ] || fail "deploy/apply used different compiled artifacts"
DEPLOY_OWNER="$(printf '%s\n' "${CALLS[2]}" | grep -o -- '--promotion-owner=[^ ]*')"
APPLY_OWNER="$(printf '%s\n' "${CALLS[3]}" | grep -o -- '--promotion-owner=[^ ]*')"
[ -n "$DEPLOY_OWNER" ] && [ "$DEPLOY_OWNER" = "$APPLY_OWNER" ] \
  || fail "deploy/apply did not share one target lease owner"
[[ "${CALLS[2]}" == *"--promotion-hold"* ]] || fail "deploy did not retain the lease for apply"
[[ "${CALLS[3]}" != *"--promotion-hold"* ]] || fail "apply was told to retain the completed lease"
CHECKPOINT="$(printf '%s\n' "$OUT" | sed -n 's/^database checkpoint: //p' | head -1)"
[ -f "$CHECKPOINT" ] || fail "database checkpoint was not retained"
[[ "$CHECKPOINT" == "$SITE/.duo/checkpoints/"* ]] \
  || fail "checkpoint was not isolated below the target operational directory"
pass "compile -> checkpoint -> deploy -> apply used one frozen artifact and one target lease"

if $DUO --envs-file="$ENVS" promote unit --promotion-owner=intruder >/dev/null 2>&1; then
  fail "caller-supplied internal promotion owner was accepted"
fi
pass "host promote owns its internal lease flags"

: > "$LOG"
if OUT="$(FAKE_DEPLOY_FAIL=1 $DUO --envs-file="$ENVS" promote unit --with-deletes 2>&1)"; then
  fail "deploy failure returned success"
else
  CODE=$?
fi
[ "$CODE" -eq 7 ] || fail "deploy exit 7 was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 3 ] || fail "apply ran after deploy failure"
[[ "${CALLS[2]}" == *"duo deploy"* ]] || fail "third call was not failed deploy"
printf '%s\n' "$OUT" | grep -q 'later phases were not run' \
  || fail "failure output did not state stop-on-first-failure"
printf '%s\n' "$OUT" | grep -q 'restore with:.*db.*import' \
  || fail "failure output omitted exact restore command"
pass "deploy failure stops apply, propagates exit, and prints checkpoint restore command"

: > "$LOG"
if OUT="$(FAKE_COMPILE_FAIL=1 $DUO --envs-file="$ENVS" promote unit 2>&1)"; then
  fail "compile failure returned success"
else
  CODE=$?
fi
[ "$CODE" -eq 6 ] || fail "compile exit 6 was not propagated (got $CODE)"
[ "$(wc -l < "$LOG" | tr -d ' ')" -eq 1 ] || fail "checkpoint/deploy ran after compile failure"
printf '%s\n' "$OUT" | grep -q 'no checkpoint or target mutation occurred' \
  || fail "compile failure boundary was not reported"
pass "compile failure occurs before checkpoint and all target mutation"

printf '\n✔ REGRESS_PROMOTION_UNIT PASSED\n'
