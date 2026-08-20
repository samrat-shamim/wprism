#!/usr/bin/env bash
# Offline product-path regression for the host promotion state machine.
#
# The fake wp binary records every target-side command so this proves the
# contract without Docker or WordPress: a legacy compiled artifact preserves
# the lifecycle-only compile -> checkpoint -> retire -> activate -> apply path,
# while a
# code-enabled artifact inserts code-stage and code-finalize around the
# lifecycle window. Every phase receives one frozen artifact and owner; every
# failure stops later phases and retains the exact database restore advice.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
DUO="$REPO_ROOT/cli/duo"
FIX="$(mktemp -d)"
FAKE_WP="$FIX/bin"
SITE="$FIX/site"
ENVS="$FIX/envs.json"
LOG="$FIX/wp.log"
TRACE="$FIX/wp.trace"

pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
cleanup() { rm -rf "$FIX"; }
trap cleanup EXIT

mkdir -p "$FAKE_WP" "$SITE"

cat > "$FAKE_WP/wp" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
args=("$@")
pos=0
while true; do
  case "${args[$pos]:-}" in
    --path=*|--exec=*|--skip-plugins|--skip-themes) pos=$((pos + 1)) ;;
    *) break ;;
  esac
done
first="${args[$pos]:-}"
second="${args[$((pos + 1))]:-}"
printf '%s\n' "$*" >> "$FAKE_WP_TRACE"

if [ "$first" = duo ] && [ "$second" = code-preflight ]; then
  if [ "${FAKE_PREFLIGHT_FAIL:-0}" != 0 ]; then
    printf '%s\n' '{"format":"duo-command-refusal/v1","ok":false,"command":"code-preflight","error":"code_compilation_failed","diagnostics":[{"code":"code_source_requires_wordpress_incompatible","path":"themes/inactive/style.css","required_version":"99.0","target_version":"6.8.2"}]}'
    exit 14
  fi
  printf '%s\n' '{"format":"duo-code-runtime/v1","enabled":true,"compatible":true,"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","target":{"php":"8.3.0","wordpress":"6.8.2","source":"target-control-plane"},"requirements":[],"diagnostics":[]}'
  exit 0
fi

# Preserve the historical phase log used by the detailed state-machine
# assertions below; the all-call trace separately proves the newly inserted
# no-write preflight ordering without mechanically weakening those checks.
printf '%s\n' "$*" >> "$FAKE_WP_LOG"

if [ "$first" = duo ] && [ "$second" = compile ]; then
  if [ "${FAKE_COMPILE_FAIL:-0}" != 0 ]; then
    printf '%s\n' 'structured compiler diagnostic from stdout'
    printf '%s\n' 'transport lifecycle noise from stderr' >&2
    exit 6
  fi
  artifact_hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  if [ "${FAKE_CODE_ENABLED:-0}" = 1 ]; then
    summary='{"artifact_hash":"'"$artifact_hash"'","code":{"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","format":1,"layout":"wp-content"}}'
  else
    summary='{"artifact_hash":"'"$artifact_hash"'"}'
  fi
  for arg in "${args[@]}"; do
    if [[ "$arg" == --out=* ]]; then
      out="${arg#--out=}"
      printf '%s\n' "$summary" > "$out"
    fi
  done
  printf '%s\n' "$summary"
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = promotion-begin ]; then
  if [ "${FAKE_BEGIN_FAIL:-0}" = 1 ]; then
    printf "Error: duo: promotion lock held by 'other-owner' in phase 'apply'\n" >&2
    exit 4
  fi
  if [ "${FAKE_BEGIN_FAIL:-0}" = 3 ]; then
    printf '%s\n' 'Error: duo: unresolved lifecycle attempt blocks a new promotion session; restore the exact pre-lifecycle database checkpoint' >&2
    exit 4
  fi
  [ "${FAKE_BEGIN_FAIL:-0}" = 2 ] && exit 4
  printf 'promotion-begin ok\n'
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = promotion-abort ]; then
  [ "${FAKE_ABORT_FAIL:-0}" = 0 ] || exit 12
  printf 'promotion-abort ok\n'
  exit 0
fi

if [ "$first" = db ] && [ "$second" = export ]; then
  [ "${FAKE_CHECKPOINT_FAIL:-0}" = 0 ] || exit 5
  file="${args[$((pos + 2))]}"
  printf '%s\n' snapshot > "$file"
  printf '%s\n' "$file"
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = code-stage ]; then
  [ "${FAKE_STAGE_FAIL:-0}" = 0 ] || exit 8
  printf 'code-stage ok\n'
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = deploy ]; then
  if [[ "$*" == *"--lifecycle-phase=retire"* && "${FAKE_RETIRE_FAIL:-0}" != 0 ]]; then exit 7; fi
  if [[ "$*" == *"--lifecycle-phase=activate"* && "${FAKE_ACTIVATE_FAIL:-0}" != 0 ]]; then exit 13; fi
  printf 'deploy ok\n'
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = code-finalize ]; then
  [ "${FAKE_FINALIZE_FAIL:-0}" = 0 ] || exit 9
  printf 'code-finalize ok\n'
  exit 0
fi

if [ "$first" = duo ] && [ "$second" = apply ]; then
  [ "${FAKE_APPLY_FAIL:-0}" = 0 ] || exit 10
  printf 'apply ok\n'
  exit 0
fi

exit 11
FAKE
chmod +x "$FAKE_WP/wp"

cat > "$ENVS" <<EOF
{"envs":{"unit":{"transport":"local","wp_path":"$FAKE_WP","repo_path":"$SITE"}}}
EOF

export PATH="$FAKE_WP:$PATH" FAKE_WP_LOG="$LOG" FAKE_WP_TRACE="$TRACE"

call_artifact() { grep -o -- '--compiled=[^ ]*' <<<"$1"; }
call_owner() { grep -o -- '--promotion-owner=[^ ]*' <<<"$1"; }
call_hash() { grep -o -- '--artifact-hash=[^ ]*' <<<"$1"; }
has() { grep -q -- "$2" <<<"$1"; }
assert_control_call() {
  local call="$1" label="$2"
  [[ "$call" == *"--exec="* \
    && "$call" == *"DUO_CONTROL_PLANE"* \
    && "$call" == *"DUO_CONTROL_WPMU_PLUGIN_DIR"* \
    && "$call" == *"after_wp_config_load"* \
    && "$call" == *"SUNRISE"* \
    && "$call" == *"--skip-plugins"* \
    && "$call" == *"--skip-themes"* ]] \
    || fail "$label did not use the isolated Duo control-plane bootstrap"
}
assert_runtime_call() {
  local call="$1" label="$2"
  [[ "$call" != *"--exec="* \
    && "$call" != *"--skip-plugins"* \
    && "$call" != *"--skip-themes"* ]] \
    || fail "$label incorrectly skipped the WordPress runtime it must reconcile"
}
assert_same_artifact_and_owner() {
  local first="$1"; shift
  local artifact owner call
  artifact="$(call_artifact "$first")"
  owner="$(call_owner "$first")"
  [ -n "$artifact" ] || fail "first mutation did not receive --compiled"
  [ -n "$owner" ] || fail "first mutation did not receive --promotion-owner"
  for call in "$@"; do
    [ "$(call_artifact "$call")" = "$artifact" ] || fail "phases did not use one frozen artifact"
    [ "$(call_owner "$call")" = "$owner" ] || fail "phases did not share one promotion owner"
  done
}
assert_begin_and_abort() {
  local begin="$1" abort="$2" phase
  [[ "$begin" == *"duo promotion-begin"* ]] || fail "promotion-begin was not invoked"
  [[ "$abort" == *"duo promotion-abort"* ]] || fail "promotion-abort was not invoked"
  assert_control_call "$begin" "promotion-begin"
  assert_control_call "$abort" "promotion-abort"
  [ "$(call_hash "$begin")" = "--artifact-hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" ] \
    || fail "promotion-begin did not bind the compiled outer artifact hash"
  [ "$(call_owner "$begin")" = "$(call_owner "$abort")" ] \
    || fail "promotion-abort did not use promotion-begin's owner"
  [ "$(call_hash "$begin")" = "$(call_hash "$abort")" ] \
    || fail "promotion-abort did not use promotion-begin's artifact hash"
}
assert_begin_matches_mutations() {
  local begin="$1"; shift
  local owner hash phase
  owner="$(call_owner "$begin")"
  hash="$(call_hash "$begin")"
  [ -n "$owner" ] || fail "promotion-begin did not receive --promotion-owner"
  [ -n "$hash" ] || fail "promotion-begin did not receive --artifact-hash"
  for phase in "$@"; do
    [ "$(call_owner "$phase")" = "$owner" ] \
      || fail "promotion-begin and mutation phases did not share one owner"
    [ "$(call_hash "$phase")" = "$hash" ] \
      || fail "promotion-begin and mutation phases did not share one expected artifact hash"
  done
}
assert_checkpoint() {
  local out="$1" checkpoint
  checkpoint="$(sed -n 's/^database checkpoint: //p' <<<"$out")"
  [ -f "$checkpoint" ] || fail "database checkpoint was not retained"
  [[ "$checkpoint" == "$SITE/.duo/checkpoints/"* ]] \
    || fail "checkpoint was not isolated below the target operational directory"
}
assert_code_recovery_guidance() {
  local out="$1" import_step
  has "$out" 'code may be staged or partially finalized' \
    || fail "code-enabled failure did not warn that code must be recovered first"
  has "$out" 'first reconcile or restore code to a known pre-promotion revision' \
    || fail "code-enabled failure did not require code reconciliation before database import"
  has "$out" 'checkpoint contains its temporary promotion lease row' \
    || fail "code-enabled failure did not explain the checkpoint lease row"
  has "$out" 'external maintenance/exclusion' \
    || fail "code-enabled failure omitted the external recovery exclusion"
  has "$out" '1\..*duo.*promotion-abort.*--artifact-hash=' \
    || fail "code-enabled failure omitted expired/original lease cleanup"
  has "$out" '2\..*duo.*promotion-begin.*--artifact-hash=' \
    || fail "code-enabled failure omitted recovery lease begin"
  has "$out" '3\..*db.*import' \
    || fail "code-enabled failure omitted database import in recovery order"
  import_step="$(sed -n 's/^  3\. //p' <<<"$out")"
  [[ "$import_step" == *"--exec="* \
    && "$import_step" == *"DUO_CONTROL_PLANE"* \
    && "$import_step" == *"--skip-plugins"* \
    && "$import_step" == *"--skip-themes"* ]] \
    || fail "checkpoint import recovery did not use the isolated fatal-safe bootstrap"
  has "$out" '4\..*duo.*promotion-abort.*--artifact-hash=' \
    || fail "code-enabled failure omitted post-import lease abort"
  has "$out" 'run step 4 even if the database import fails' \
    || fail "code-enabled failure did not require final cleanup after import failure"
  if has "$out" '^restore with:'; then
    fail "code-enabled failure incorrectly advertised a database-only rollback"
  fi
}
run_promote() {
  local code="$1"; shift
  : > "$LOG"
  : > "$TRACE"
  if OUT="$(FAKE_CODE_ENABLED="$code" "$@" "$DUO" --envs-file="$ENVS" promote unit --default-author=admin --force-unresolved-refs 2>&1)"; then
    CODE=0
  else
    CODE=$?
  fi
}

# Legacy repositories retain their lifecycle/apply graph, with the new
# target-authoritative begin boundary before the checkpoint.
run_promote 0 env
[ "$CODE" -eq 0 ] || fail "legacy promote exited non-zero: $OUT"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 6 ] || fail "legacy path expected six wp calls, got ${#CALLS[@]}"
[[ "${CALLS[0]}" == *"duo compile"* ]] || fail "legacy phase 1 was not compile"
[[ "${CALLS[1]}" == *"duo promotion-begin"* ]] || fail "legacy phase 2 did not acquire checkpoint lease"
[[ "${CALLS[2]}" == *"db export"* ]] || fail "legacy phase 3 was not checkpoint"
[[ "${CALLS[3]}" == *"duo deploy"*"--lifecycle-phase=retire"* ]] || fail "legacy phase 4 was not lifecycle retirement"
[[ "${CALLS[4]}" == *"duo deploy"*"--lifecycle-phase=activate"* ]] || fail "legacy phase 5 was not lifecycle activation"
[[ "${CALLS[5]}" == *"duo apply"* ]] || fail "legacy phase 6 was not apply"
assert_control_call "${CALLS[0]}" "legacy compile"
assert_control_call "${CALLS[1]}" "legacy promotion-begin"
assert_runtime_call "${CALLS[2]}" "legacy checkpoint"
assert_runtime_call "${CALLS[3]}" "legacy retirement"
assert_runtime_call "${CALLS[4]}" "legacy activation"
assert_runtime_call "${CALLS[5]}" "legacy apply"
[[ "${CALLS[3]}" != *"code-stage"* && "${CALLS[3]}" != *"--materializing-code"* \
  && "${CALLS[4]}" != *"--materializing-code"* ]] \
  || fail "legacy lifecycle phases were incorrectly marked materializing-code"
assert_begin_matches_mutations "${CALLS[1]}" "${CALLS[3]}" "${CALLS[4]}" "${CALLS[5]}"
assert_same_artifact_and_owner "${CALLS[3]}" "${CALLS[4]}" "${CALLS[5]}"
[[ "${CALLS[3]}" == *"--promotion-hold"* && "${CALLS[3]}" == *"--state-handoff"* \
  && "${CALLS[4]}" == *"--promotion-hold"* && "${CALLS[4]}" == *"--state-handoff"* ]] \
  || fail "legacy lifecycle phases did not retain lease with explicit state handoff"
[[ "${CALLS[3]}" == *"--force-unresolved-refs"* && "${CALLS[4]}" == *"--force-unresolved-refs"* \
  && "${CALLS[5]}" == *"--force-unresolved-refs"* ]] \
  || fail "legacy lifecycle/apply did not share unresolved-ref snapshot policy"
[[ "${CALLS[5]}" != *"--promotion-hold"* ]] || fail "legacy apply was told to retain completed lease"
[[ "${CALLS[5]}" != *"--state-handoff"* ]] || fail "legacy apply received deploy-only state handoff"
assert_checkpoint "$OUT"
has "$OUT" 'promote complete: lifecycle-retire -> lifecycle-activate -> apply' \
  || fail "legacy promote success line changed"
if has "${CALLS[*]}" 'promotion-abort'; then
  fail "successful legacy promotion invoked compensating abort"
fi
pass "legacy artifact acquires lease -> checkpoint -> retire -> activate -> apply"

# Code-enabled artifacts add exactly two agent phases, all tied to the same
# frozen artifact and owner. The lifecycle flag is intentionally scoped only
# to this branch; stage/finalize remain normal agent invocations.
run_promote 1 env
[ "$CODE" -eq 0 ] || fail "code-enabled promote exited non-zero: $OUT"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 8 ] || fail "code-enabled path expected eight wp calls, got ${#CALLS[@]}"
[[ "${CALLS[0]}" == *"duo compile"* ]] || fail "code path phase 1 was not compile"
[[ "${CALLS[1]}" == *"duo promotion-begin"* ]] || fail "code path phase 2 did not acquire checkpoint lease"
[[ "${CALLS[2]}" == *"db export"* ]] || fail "code path phase 3 was not checkpoint"
[[ "${CALLS[3]}" == *"duo code-stage"* ]] || fail "code path phase 4 was not code-stage"
[[ "${CALLS[4]}" == *"duo deploy"*"--lifecycle-phase=retire"* ]] || fail "code path phase 5 was not lifecycle retirement"
[[ "${CALLS[5]}" == *"duo deploy"*"--lifecycle-phase=activate"* ]] || fail "code path phase 6 was not lifecycle activation"
[[ "${CALLS[6]}" == *"duo code-finalize"* ]] || fail "code path phase 7 was not code-finalize"
[[ "${CALLS[7]}" == *"duo apply"* ]] || fail "code path phase 8 was not apply"
assert_control_call "${CALLS[0]}" "code compile"
assert_control_call "${CALLS[1]}" "code promotion-begin"
assert_runtime_call "${CALLS[2]}" "code checkpoint"
assert_control_call "${CALLS[3]}" "code stage"
assert_runtime_call "${CALLS[4]}" "code retirement"
assert_runtime_call "${CALLS[5]}" "code activation"
assert_control_call "${CALLS[6]}" "code finalize"
assert_runtime_call "${CALLS[7]}" "code apply"
assert_begin_matches_mutations "${CALLS[1]}" "${CALLS[3]}" "${CALLS[4]}" "${CALLS[5]}" "${CALLS[6]}" "${CALLS[7]}"
assert_same_artifact_and_owner "${CALLS[3]}" "${CALLS[4]}" "${CALLS[5]}" "${CALLS[6]}" "${CALLS[7]}"
[[ "${CALLS[3]}" != *"--promotion-hold"* && "${CALLS[3]}" != *"--materializing-code"* ]] \
  || fail "code-stage received lifecycle-only flags"
[[ "${CALLS[4]}" == *"--promotion-hold"* && "${CALLS[4]}" == *"--materializing-code"* && "${CALLS[4]}" == *"--state-handoff"* \
  && "${CALLS[5]}" == *"--promotion-hold"* && "${CALLS[5]}" == *"--materializing-code"* && "${CALLS[5]}" == *"--state-handoff"* ]] \
  || fail "code lifecycle phases did not retain lease and mark materialization/state handoff"
[[ "${CALLS[4]}" == *"--force-unresolved-refs"* && "${CALLS[5]}" == *"--force-unresolved-refs"* \
  && "${CALLS[7]}" == *"--force-unresolved-refs"* ]] \
  || fail "code lifecycle/apply did not share unresolved-ref snapshot policy"
[[ "${CALLS[6]}" == *"--promotion-hold"* && "${CALLS[6]}" != *"--materializing-code"* ]] \
  || fail "code-finalize did not retain lease cleanly for apply"
[[ "${CALLS[7]}" != *"--promotion-hold"* && "${CALLS[7]}" != *"--materializing-code"* && "${CALLS[7]}" != *"--state-handoff"* ]] \
  || fail "apply received code/lifecycle-only flags"
assert_checkpoint "$OUT"
has "$OUT" 'promote complete: code-stage -> lifecycle-retire -> lifecycle-activate -> code-finalize -> apply' \
  || fail "code-enabled promote success line missing complete phase trace"
if has "${CALLS[*]}" 'promotion-abort'; then
  fail "successful code promotion invoked compensating abort"
fi
pass "code artifact sequences begin -> checkpoint -> stage -> retire -> activate -> finalize -> apply"

mapfile -t TRACED_CALLS < "$TRACE"
[ "${#TRACED_CALLS[@]}" -eq 9 ] || fail "code path expected compile/preflight plus eight existing calls"
[[ "${TRACED_CALLS[0]}" == *"duo compile"* \
  && "${TRACED_CALLS[1]}" == *"duo code-preflight"* \
  && "${TRACED_CALLS[2]}" == *"duo promotion-begin"* ]] \
  || fail "code target-runtime preflight did not run after compile and before promotion-begin"
assert_control_call "${TRACED_CALLS[1]}" "promotion target-runtime preflight"
[ "$(call_artifact "${TRACED_CALLS[1]}")" = "$(call_artifact "${TRACED_CALLS[4]}")" ] \
  || fail "promotion preflight and code-stage did not inspect one frozen artifact"
[ "$(call_hash "${TRACED_CALLS[1]}")" = "$(call_hash "${TRACED_CALLS[2]}")" ] \
  || fail "promotion preflight and promotion-begin did not bind one artifact hash"
pass "code target-runtime preflight is control-plane, immutable, and before lease/checkpoint"

# A stage failure is after the checkpoint but before every later mutation.
run_promote 1 env FAKE_STAGE_FAIL=1
[ "$CODE" -eq 8 ] || fail "code-stage failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 5 ] || fail "later phases or abort boundary were wrong after code-stage failure"
[[ "${CALLS[3]}" == *"duo code-stage"* ]] || fail "fourth call was not failed code-stage"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[4]}"
has "$OUT" 'code-stage failed.*later phases were not run' \
  || fail "code-stage failure did not name its stop boundary"
assert_code_recovery_guidance "$OUT"
pass "code-stage failure aborts the checkpoint lease and gives ordered code recovery"

# Retirement failure after a successful stage must never activate/finalize/apply.
run_promote 1 env FAKE_RETIRE_FAIL=1
[ "$CODE" -eq 7 ] || fail "lifecycle retirement failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 6 ] || fail "activation/finalize/apply or abort boundary were wrong after retirement failure"
[[ "${CALLS[4]}" == *"--lifecycle-phase=retire"* ]] || fail "fifth call was not failed lifecycle retirement"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[5]}"
assert_code_recovery_guidance "$OUT"
pass "lifecycle retirement failure stops activation/finalize/apply and aborts the checkpoint lease"

# Activation runs in a fresh process. Its failure is still before finalize/apply.
run_promote 1 env FAKE_ACTIVATE_FAIL=1
[ "$CODE" -eq 13 ] || fail "lifecycle activation failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 7 ] || fail "finalize/apply or abort boundary were wrong after activation failure"
[[ "${CALLS[5]}" == *"--lifecycle-phase=activate"* ]] || fail "sixth call was not failed lifecycle activation"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[6]}"
assert_code_recovery_guidance "$OUT"
pass "lifecycle activation failure stops finalize/apply and aborts the checkpoint lease"

# Finalization is still before apply. A failure cannot be reported as a
# completed promotion simply because lifecycle activation already succeeded.
run_promote 1 env FAKE_FINALIZE_FAIL=1
[ "$CODE" -eq 9 ] || fail "code-finalize failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 8 ] || fail "apply or abort boundary were wrong after code-finalize failure"
[[ "${CALLS[6]}" == *"duo code-finalize"* ]] || fail "seventh call was not failed code-finalize"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[7]}"
has "$OUT" 'code-finalize failed.*later phases were not run' \
  || fail "code-finalize failure did not name its stop boundary"
assert_code_recovery_guidance "$OUT"
pass "code-finalize failure stops apply and aborts the checkpoint lease"

# Once finalize succeeded, a failed apply still leaves the new completed code
# on disk. The checkpoint remains useful, but only after code is reconciled
# back to a known revision; DB import alone is not a complete rollback.
run_promote 1 env FAKE_APPLY_FAIL=1
[ "$CODE" -eq 10 ] || fail "apply failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 9 ] || fail "unexpected calls around failed apply"
[[ "${CALLS[7]}" == *"duo apply"* ]] || fail "eighth call was not failed apply"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[8]}"
assert_code_recovery_guidance "$OUT"
pass "code-enabled apply failure aborts the lease and requires ordered code recovery"

# Legacy failures still need the recovery lease because the post-begin dump
# contains its promotion_lock row, but they never claim code recovery.
run_promote 0 env FAKE_APPLY_FAIL=1
[ "$CODE" -eq 10 ] || fail "legacy apply failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 7 ] || fail "legacy apply failure did not run compensating abort"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[6]}"
has "$OUT" 'checkpoint contains its temporary promotion lease row' \
  || fail "legacy apply failure omitted checkpoint lease recovery explanation"
has "$OUT" 'external maintenance/exclusion' \
  || fail "legacy apply failure omitted external recovery exclusion"
has "$OUT" '1\..*promotion-abort.*--artifact-hash=' \
  || fail "legacy apply failure omitted original lease cleanup"
has "$OUT" '2\..*promotion-begin.*--artifact-hash=' \
  || fail "legacy apply failure omitted recovery lease begin"
has "$OUT" '3\..*db.*import' \
  || fail "legacy apply failure omitted database import in recovery order"
has "$OUT" '4\..*promotion-abort.*--artifact-hash=' \
  || fail "legacy apply failure omitted post-import lease abort"
if has "$OUT" 'code may be staged or partially finalized'; then
  fail "legacy apply failure incorrectly received code recovery guidance"
fi
pass "legacy apply failure gives ordered DB/lease recovery without code warning"

# Target runtime/header compatibility is an earlier read-only control-plane
# gate. Its refusal has no lease to abort and no checkpoint/code/state effect
# to recover; the structured component/target diagnostic remains visible.
run_promote 1 env FAKE_PREFLIGHT_FAIL=1
[ "$CODE" -eq 14 ] || fail "target-runtime preflight failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 1 ] || fail "target-runtime preflight failure ran begin/checkpoint/mutation calls"
[[ "${CALLS[0]}" == *"duo compile"* ]] || fail "target-runtime refusal did not retain compile as its only historical phase"
mapfile -t TRACED_CALLS < "$TRACE"
[ "${#TRACED_CALLS[@]}" -eq 2 ] || fail "target-runtime refusal did not stop at compile/preflight"
[[ "${TRACED_CALLS[1]}" == *"duo code-preflight"* ]] || fail "second traced call was not target-runtime preflight"
assert_control_call "${TRACED_CALLS[1]}" "failed promotion target-runtime preflight"
has "$OUT" 'code_source_requires_wordpress_incompatible' \
  || fail "target-runtime refusal lost its component/target diagnostic"
has "$OUT" 'refusing before promotion-begin/checkpoint' \
  || fail "target-runtime refusal did not name its pre-lease/checkpoint boundary"
if has "$(cat "$TRACE")" 'promotion-abort'; then
  fail "target-runtime refusal attempted cleanup for a lease it never acquired"
fi
pass "target-runtime incompatibility stops promotion before lease/checkpoint without cleanup fiction"

# The lease is acquired before export. A begin refusal makes no checkpoint and
# never attempts an abort that could touch another owner's lock.
run_promote 1 env FAKE_BEGIN_FAIL=1
[ "$CODE" -eq 4 ] || fail "promotion-begin failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 2 ] || fail "checkpoint or later phase ran after promotion-begin failure"
[[ "${CALLS[0]}" == *"duo compile"* && "${CALLS[1]}" == *"duo promotion-begin"* ]] \
  || fail "promotion-begin did not follow compile"
if has "${CALLS[*]}" 'promotion-abort'; then
  fail "begin refusal attempted an abort"
fi
pass "promotion-begin refusal stops before checkpoint without touching another lease"

# An ambiguous failed hook is another definite protected-session refusal. The
# new owner must not compensate with its own abort, overwrite the original
# recovery identity, create a checkpoint, or reach code/lifecycle/apply.
run_promote 1 env FAKE_BEGIN_FAIL=3
[ "$CODE" -eq 4 ] || fail "lifecycle-attempt begin refusal exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 2 ] || fail "lifecycle-attempt refusal ran checkpoint, cleanup, or later phases"
[[ "${CALLS[0]}" == *"duo compile"* && "${CALLS[1]}" == *"duo promotion-begin"* ]] \
  || fail "lifecycle-attempt refusal did not stop at begin"
if has "${CALLS[*]}" 'promotion-abort'; then
  fail "lifecycle-attempt refusal attempted a new-owner abort"
fi
has "$OUT" 'target reported a definite protected session; no cleanup was attempted' \
  || fail "lifecycle-attempt refusal was mislabeled as an uncertain begin"
pass "unresolved lifecycle attempt preserves the original recovery session before checkpoint"

# A nonzero transport result without a definite competing-lock response may
# arrive after the agent committed begin. Exact abort cannot remove another
# owner and therefore safely closes the ambiguous outcome.
run_promote 1 env FAKE_BEGIN_FAIL=2
[ "$CODE" -eq 4 ] || fail "uncertain promotion-begin exit was not preserved (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 3 ] || fail "uncertain begin did not run exactly compile/begin/abort"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[2]}"
has "$OUT" 'begin outcome is uncertain; attempting exact owner/artifact cleanup' \
  || fail "uncertain begin did not explain its exact compensation"
pass "ambiguous promotion-begin transport failure is compensated exactly"

# An export failure is after begin, so it must compensate exactly once but
# never present a partial dump as recovery input.
run_promote 1 env FAKE_CHECKPOINT_FAIL=1
[ "$CODE" -eq 5 ] || fail "checkpoint failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 4 ] || fail "checkpoint failure did not run exactly begin/export/abort"
[[ "${CALLS[1]}" == *"duo promotion-begin"* && "${CALLS[2]}" == *"db export"* ]] \
  || fail "checkpoint was not protected by promotion-begin"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[3]}"
has "$OUT" 'no usable checkpoint was produced' \
  || fail "checkpoint failure did not reject partial dump recovery"
if has "$OUT" 'external maintenance/exclusion'; then
  fail "checkpoint failure advertised recovery from a failed export"
fi
pass "checkpoint export failure aborts the pre-export lease without DB restore fiction"

# Cleanup cannot overwrite the phase failure. The host preserves the original
# exit and prints the exact retry command rather than pretending the lock died.
run_promote 1 env FAKE_STAGE_FAIL=1 FAKE_ABORT_FAIL=1
[ "$CODE" -eq 8 ] || fail "abort failure replaced original stage exit (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 5 ] || fail "abort-failure path ran unexpected phases"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[4]}"
has "$OUT" 'promotion lease cleanup could not be confirmed' \
  || fail "abort failure was not surfaced"
has "$OUT" 'retry lease cleanup with:.*promotion-abort.*--artifact-hash=' \
  || fail "abort failure omitted exact retry"
if has "$OUT" 'external maintenance/exclusion'; then
  fail "abort failure advertised recovery before cleanup was confirmed"
fi
pass "abort failure preserves original exit and refuses premature recovery"

# Compile remains the no-mutation boundary even for a would-be code revision.
run_promote 1 env FAKE_COMPILE_FAIL=1
[ "$CODE" -eq 6 ] || fail "compile failure exit was not propagated (got $CODE)"
[ "$(wc -l < "$LOG" | tr -d ' ')" -eq 1 ] || fail "checkpoint/stage/deploy ran after compile failure"
has "$OUT" 'no checkpoint or target mutation occurred' \
  || fail "compile failure boundary was not reported"
has "$OUT" 'transport lifecycle noise from stderr' \
  || fail "compile failure lost the transport stderr stream"
has "$OUT" 'structured compiler diagnostic from stdout' \
  || fail "compile failure lost the agent stdout diagnostic behind transport stderr noise"
pass "compile failure occurs before checkpoint and all code/state mutation"

# No caller may smuggle the host-selected repo, artifact/hash, stage, or lease
# boundary into promote. In particular, a second --repo would otherwise be
# appended after the host's own --repo on apply and win wp-cli's assoc parsing.
for internal in --repo=/tmp/forged --compiled=/tmp/forged.json --artifact-hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --promotion-owner=intruder --promotion-hold --materializing-code --state-handoff --lifecycle-phase=activate; do
  : > "$LOG"
  if FAKE_CODE_ENABLED=1 "$DUO" --envs-file="$ENVS" promote unit "$internal" >/dev/null 2>&1; then
    fail "promote accepted caller-owned internal flag $internal"
  fi
  [ ! -s "$LOG" ] || fail "promote contacted target after rejecting $internal"
done
if FAKE_CODE_ENABLED=1 "$DUO" --envs-file="$ENVS" promote unit --repo /tmp/forged >/dev/null 2>&1; then
  fail "promote accepted split caller-owned --repo flag"
fi
[ ! -s "$LOG" ] || fail "promote contacted target after rejecting split --repo"
if FAKE_CODE_ENABLED=1 "$DUO" --envs-file="$ENVS" promote unit --artifact-hash aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa >/dev/null 2>&1; then
  fail "promote accepted split caller-owned --artifact-hash flag"
fi
[ ! -s "$LOG" ] || fail "promote contacted target after rejecting split --artifact-hash"
pass "promote owns repo/artifact/code/lease flags before target contact"

printf '\n✔ REGRESS_PROMOTION_UNIT PASSED\n'
