#!/usr/bin/env bash
# Offline product-path regression for the host promotion state machine.
#
# The fake wp binary records every target-side command so this proves the
# contract without Docker or WordPress: a state-only artifact goes directly
# from checkpoint to apply without extension hooks, while a
# code-enabled artifact inserts code-stage and code-finalize around the
# lifecycle window. Every phase receives one frozen artifact and owner; every
# failure stops later phases and retains the exact database restore advice.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
WPRISM="$REPO_ROOT/cli/wprism"
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

if [ "$first" = wprism ] && [ "$second" = code-preflight ]; then
  if [ "${FAKE_PREFLIGHT_FAIL:-0}" != 0 ]; then
    printf '%s\n' '{"format":"wprism-command-refusal/v1","ok":false,"command":"code-preflight","error":"code_compilation_failed","diagnostics":[{"code":"code_source_requires_wordpress_incompatible","path":"themes/inactive/style.css","required_version":"99.0","target_version":"6.8.2"}]}'
    exit 14
  fi
  required=true
  [ "${FAKE_CODE_CHANGE_REQUIRED:-1}" = 0 ] && required=false
  printf '%s\n' '{"format":"wprism-code-runtime/v1","enabled":true,"change_required":'"$required"',"compatible":true,"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","target":{"php":"8.3.0","wordpress":"6.8.2","source":"target-control-plane"},"requirements":[],"diagnostics":[]}'
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = lifecycle-status ]; then
  required=false
  reasons='[]'
  if [ "${FAKE_LIFECYCLE_CHANGE_REQUIRED:-0}" = 1 ]; then
    required=true
    reasons='["inactive_in_environment"]'
  fi
  printf '%s\n' '{"baseline_state":"exact","code_boundary_sha256":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","code_drift":[],"findings_sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","format":"wprism-lifecycle-status/v2","observation_sha256":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","reasons":'"$reasons"',"required":'"$required"',"warnings":[]}'
  exit 0
fi

# The product connects `db export -` directly to this authenticated sealer.
# Cipher behavior has its PHP regression; this phase fake preserves the
# no-plaintext path and the sealer's atomic output contract.
if [ "$first" = wprism ] && [ "$second" = checkpoint-target ]; then
  printf '%s\n' '{"database_target_sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","format":"wprism-database-target/v1"}'
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = checkpoint-seal ]; then
  output=''
  database_target=''
  for arg in "${args[@]}"; do
    [[ "$arg" == --output=* ]] && output="${arg#--output=}"
    [[ "$arg" == --database-target-sha256=* ]] && database_target="${arg#--database-target-sha256=}"
  done
  [ -n "$output" ] || exit 16
  [ "$database_target" = dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd ] || exit 17
  cat > "$output"
  exit 0
fi

# Preserve the historical phase log used by the detailed state-machine
# assertions below; the all-call trace separately proves the newly inserted
# no-write preflight ordering without mechanically weakening those checks.
printf '%s\n' "$*" >> "$FAKE_WP_LOG"

if [ "$first" = wprism ] && [ "$second" = compile ]; then
  if [ "${FAKE_COMPILE_FAIL:-0}" != 0 ]; then
    printf '%s\n' 'structured compiler diagnostic from stdout'
    printf '%s\n' 'transport lifecycle noise from stderr' >&2
    exit 6
  fi
  artifact_hash=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  effects=''
  if [ "${FAKE_SCHEMA_DECLARED:-0}" = 1 ]; then
    effects=',"effects_inventory":[{"phase":"schema-settle"}]'
  fi
  if [ "${FAKE_CODE_ENABLED:-0}" = 1 ]; then
    summary='{"artifact_hash":"'"$artifact_hash"'","code":{"code_revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","format":1,"layout":"wp-content"}'"$effects"'}'
  else
    summary='{"artifact_hash":"'"$artifact_hash"'"'"$effects"'}'
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

if [ "$first" = wprism ] && [ "$second" = promotion-begin ]; then
  if [ "${FAKE_BEGIN_FAIL:-0}" = 1 ]; then
    printf "Error: wprism: promotion lock held by 'other-owner' in phase 'apply'\n" >&2
    exit 4
  fi
  if [ "${FAKE_BEGIN_FAIL:-0}" = 3 ]; then
    printf '%s\n' 'Error: wprism: unresolved lifecycle attempt blocks a new promotion session; restore the exact pre-lifecycle database checkpoint' >&2
    exit 4
  fi
  [ "${FAKE_BEGIN_FAIL:-0}" = 2 ] && exit 4
  printf 'promotion-begin ok\n'
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = promotion-abort ]; then
  [ "${FAKE_ABORT_FAIL:-0}" = 0 ] || exit 12
  printf 'promotion-abort ok\n'
  exit 0
fi

if [ "$first" = db ] && [ "$second" = export ]; then
  [ "${FAKE_CHECKPOINT_FAIL:-0}" = 0 ] || exit 5
  file="${args[$((pos + 2))]}"
  if [ "$file" = - ]; then
    printf '%s\n' snapshot
    exit 0
  fi
  printf '%s\n' snapshot > "$file"
  printf '%s\n' "$file"
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = code-stage ]; then
  [ "${FAKE_STAGE_FAIL:-0}" = 0 ] || exit 8
  printf 'code-stage ok\n'
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = deploy ]; then
  if [[ "$*" == *"--lifecycle-phase=retire"* && "${FAKE_RETIRE_FAIL:-0}" != 0 ]]; then exit 7; fi
  if [[ "$*" == *"--lifecycle-phase=activate"* && "${FAKE_ACTIVATE_FAIL:-0}" != 0 ]]; then exit 13; fi
  printf 'deploy ok\n'
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = code-finalize ]; then
  [ "${FAKE_FINALIZE_FAIL:-0}" = 0 ] || exit 9
  printf 'code-finalize ok\n'
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = lifecycle-settle ]; then
  [ "${FAKE_SETTLE_FAIL:-0}" = 0 ] || exit 15
  printf 'lifecycle-settle ok\n'
  exit 0
fi

if [ "$first" = wprism ] && [ "$second" = apply ]; then
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
    && "$call" == *"WPRISM_CONTROL_PLANE"* \
    && "$call" == *"WPRISM_CONTROL_WPMU_PLUGIN_DIR"* \
    && "$call" == *"after_wp_config_load"* \
    && "$call" == *"SUNRISE"* \
    && "$call" == *"--skip-plugins"* \
    && "$call" == *"--skip-themes"* ]] \
    || fail "$label did not use the isolated WPrism control-plane bootstrap"
}
assert_runtime_call() {
  local call="$1" label="$2"
  [[ "$call" != *"--exec="* \
    && "$call" != *"--skip-plugins"* \
    && "$call" != *"--skip-themes"* ]] \
    || fail "$label incorrectly skipped the WordPress runtime it must reconcile"
}
assert_database_checkpoint_call() {
  local call="$1" label="$2"
  [[ "$call" == *"--exec="* \
    && "$call" == *"DatabaseTargetIdentity::fromWordPressConfig"* \
    && "$call" == *"require_recovery_intent"* \
    && "$call" == *"--skip-plugins"* \
    && "$call" == *"--skip-themes"* \
    && "$call" == *"db export -"* ]] \
    || fail "$label did not use the isolated preflight-bound database export"
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
  [[ "$begin" == *"wprism promotion-begin"* ]] || fail "promotion-begin was not invoked"
  [[ "$abort" == *"wprism promotion-abort"* ]] || fail "promotion-abort was not invoked"
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
  [[ "$checkpoint" == "$SITE/.wprism/checkpoints/"* ]] \
    || fail "checkpoint was not isolated below the target operational directory"
}
# The recovery guidance a post-checkpoint failure owes an operator.
#
# Until issue #3525 this asserted four numbered `wp` instructions — abort,
# re-begin, isolated `wp db import`, mandatory final abort. That recipe is
# precisely what docs/product-spec.md:556-557 says normal operation never
# depends on and what regress_mup_leak_audit.sh part (c) forbids in every
# guide, so the product stopped printing it: the remedy is now the ONE verb
# that drives those same four steps in the same order from the same
# `CodeDeploy` builders (RecoverCommand::ORDERED_STEPS,
# cli/src/Command/RecoverCommand.php:114), with the final abort in a `finally`
# (:497-507). The isolated fatal-safe bootstrap this used to check on step 3
# is unchanged; it just executes inside that verb now, and is pinned where it
# executes by regress_recover_ordering.sh.
assert_code_recovery_guidance() {
  local out="$1" remedy forbidden
  has "$out" 'code may be staged or partially finalized' \
    || fail "code-enabled failure did not warn that code must be recovered first"
  has "$out" 'first reconcile or restore code to a known pre-promotion revision' \
    || fail "code-enabled failure did not require code reconciliation before database import"
  has "$out" 'checkpoint contains its temporary promotion lease row' \
    || fail "code-enabled failure did not explain the checkpoint lease row"
  has "$out" 'external maintenance/exclusion' \
    || fail "code-enabled failure omitted the external recovery exclusion"
  remedy="$(sed -n 's/^wprism: promote: once that exclusion is in place, recover with: //p' <<<"$out")"
  [ -n "$remedy" ] || fail "code-enabled failure named no recovery exit path: $out"
  [[ "$remedy" == "wprism recover unit --restore=promote-"*" --writers-excluded --operator-directed" ]] \
    || fail "the recovery remedy is not the documented wprism recover form: $remedy"
  has "$out" 'releases the lease row the import reinstates, including when the import' \
    || fail "code-enabled failure dropped the mandatory-final-abort safety fact"
  for forbidden in 'wp wprism promotion-abort' 'wp wprism promotion-begin' 'wp db import'; do
    if grep -Fq -- "$forbidden" <<<"$out"; then
      fail "the failure view still publishes the retired raw-recovery step '$forbidden'"
    fi
  done
  if has "$out" '^restore with:'; then
    fail "code-enabled failure incorrectly advertised a database-only rollback"
  fi
}
run_promote() {
  local code="$1"; shift
  : > "$LOG"
  : > "$TRACE"
  if OUT="$(FAKE_CODE_ENABLED="$code" "$@" "$WPRISM" --envs-file="$ENVS" promote unit --default-author=admin --force-unresolved-refs 2>&1)"; then
    CODE=0
  else
    CODE=$?
  fi
}

run_promote_delete_flag() {
  local code="$1" delete_flag="$2"; shift 2
  : > "$LOG"
  : > "$TRACE"
  if OUT="$(FAKE_CODE_ENABLED="$code" "$@" "$WPRISM" --envs-file="$ENVS" promote unit "$delete_flag" 2>&1)"; then
    CODE=0
  else
    CODE=$?
  fi
}

# A deletion-capable invocation cannot fall through to the database-only
# operator-directed profile: that path could stage/finalize code before Apply
# discovers it has no signed writer-exclusion witness.
run_promote_delete_flag 1 --with-deletes env
[ "$CODE" -ne 0 ] || fail "deletion promotion without automatic rollback unexpectedly succeeded"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 1 ] || fail "deletion refusal reached promotion-begin, checkpoint, code, lifecycle, or apply"
[[ "${CALLS[0]}" == *"wprism compile"* ]] || fail "deletion refusal lost its read-only compiled plan"
has "$OUT" 'deletion requires automatic verified rollback' \
  || fail "deletion refusal did not name the missing automatic recovery authority"
has "$OUT" 'refusing before promotion-begin/checkpoint' \
  || fail "deletion refusal did not name its pre-mutation boundary"
pass "deletion intent refuses before begin/checkpoint when only operator-directed recovery is available"

for valued_delete in --with-deletes=true --with-deletes=1; do
  run_promote_delete_flag 1 "$valued_delete" env
  [ "$CODE" -ne 0 ] || fail "$valued_delete unexpectedly entered promotion"
  [ ! -s "$LOG" ] && [ ! -s "$TRACE" ] \
    || fail "$valued_delete reached compile or target contact before argument refusal"
  has "$OUT" 'valued --with-deletes boolean' \
    || fail "$valued_delete did not receive the closed boolean-wire refusal"
done
pass "valued deletion flags cannot bypass host admission through WP-CLI assoc parsing"

: > "$LOG"
: > "$TRACE"
if OUT="$(FAKE_CODE_ENABLED=1 "$WPRISM" --envs-file="$ENVS" promote unit --with-deletes --with-deletes 2>&1)"; then
  CODE=0
else
  CODE=$?
fi
[ "$CODE" -ne 0 ] && [ ! -s "$LOG" ] && [ ! -s "$TRACE" ] \
  || fail "duplicate --with-deletes reached compile or target contact"
has "$OUT" 'duplicate --with-deletes' \
  || fail "duplicate --with-deletes did not receive the closed boolean-wire refusal"
pass "duplicate deletion flags refuse before compile or target contact"

# A state-only repository acquires the target-authoritative lease and
# checkpoint, then applies without invoking extension lifecycle hooks.
run_promote 0 env
[ "$CODE" -eq 0 ] || fail "legacy promote exited non-zero: $OUT"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 4 ] || fail "state-only path expected four wp calls, got ${#CALLS[@]}"
[[ "${CALLS[0]}" == *"wprism compile"* ]] || fail "legacy phase 1 was not compile"
[[ "${CALLS[1]}" == *"wprism promotion-begin"* ]] || fail "legacy phase 2 did not acquire checkpoint lease"
[[ "${CALLS[2]}" == *"db export"* ]] || fail "legacy phase 3 was not checkpoint"
[[ "${CALLS[3]}" == *"wprism apply"* ]] || fail "state-only phase 4 was not apply"
assert_control_call "${CALLS[0]}" "legacy compile"
assert_control_call "${CALLS[1]}" "legacy promotion-begin"
assert_database_checkpoint_call "${CALLS[2]}" "legacy checkpoint"
assert_runtime_call "${CALLS[3]}" "state-only apply"
assert_begin_matches_mutations "${CALLS[1]}" "${CALLS[3]}"
[[ "${CALLS[3]}" == *"--force-unresolved-refs"* ]] \
  || fail "state-only apply did not receive unresolved-ref policy"
[[ "${CALLS[3]}" != *"--promotion-hold"* && "${CALLS[3]}" != *"--state-handoff"* \
  && "${CALLS[3]}" != *"--materializing-code"* ]] \
  || fail "state-only apply received lifecycle-only flags"
assert_checkpoint "$OUT"
has "$OUT" 'promote complete: content apply; code lifecycle hooks not run' \
  || fail "state-only promote did not report the hook-free content path"
if grep -Eq 'code-stage|code-finalize|lifecycle-phase' "$LOG"; then
  fail "state-only promotion invoked a code/lifecycle mutation"
fi
if has "${CALLS[*]}" 'promotion-abort'; then
  fail "successful legacy promotion invoked compensating abort"
fi
pass "state-only artifact acquires lease -> checkpoint -> apply with no extension hooks"

# A phase-owning state-only adapter cannot load its schema provider while the
# plugin is inactive. Promote detects that through the isolated lifecycle
# preflight and sends the operator through host deploy before it asks an
# ordinary WordPress process for schema readiness.
run_promote 0 env FAKE_SCHEMA_DECLARED=1 FAKE_LIFECYCLE_CHANGE_REQUIRED=1
[ "$CODE" -ne 0 ] || fail "inactive state-only adapter unexpectedly entered promotion"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 1 ] && [[ "${CALLS[0]}" == *"wprism compile"* ]] \
  || fail "inactive state-only refusal crossed the compile-only target boundary"
mapfile -t TRACED_CALLS < "$TRACE"
[ "${#TRACED_CALLS[@]}" -eq 2 ] \
  && [[ "${TRACED_CALLS[1]}" == *"wprism lifecycle-status"* ]] \
  || fail "inactive state-only refusal did not use exactly compile -> lifecycle-status"
assert_control_call "${TRACED_CALLS[1]}" "state-only lifecycle preflight"
has "$OUT" "run host 'wprism deploy <env>'" \
  || fail "inactive state-only refusal did not name the one host remediation"
if has "${TRACED_CALLS[*]}" 'schema-status'; then
  fail "inactive state-only promotion tried to load schema authority before activation"
fi
pass "inactive state-only adapter refuses promotion before provider load with host deploy remediation"

# Code-enabled artifacts add exactly two agent phases, all tied to the same
# frozen artifact and owner. The lifecycle flag is intentionally scoped only
# to this branch; stage/finalize remain normal agent invocations.
run_promote 1 env
[ "$CODE" -eq 0 ] || fail "code-enabled promote exited non-zero: $OUT"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 9 ] || fail "code-enabled path expected nine wp calls, got ${#CALLS[@]}"
[[ "${CALLS[0]}" == *"wprism compile"* ]] || fail "code path phase 1 was not compile"
[[ "${CALLS[1]}" == *"wprism promotion-begin"* ]] || fail "code path phase 2 did not acquire checkpoint lease"
[[ "${CALLS[2]}" == *"db export"* ]] || fail "code path phase 3 was not checkpoint"
[[ "${CALLS[3]}" == *"wprism code-stage"* ]] || fail "code path phase 4 was not code-stage"
[[ "${CALLS[4]}" == *"wprism deploy"*"--lifecycle-phase=retire"* ]] || fail "code path phase 5 was not lifecycle retirement"
[[ "${CALLS[5]}" == *"wprism deploy"*"--lifecycle-phase=activate"* ]] || fail "code path phase 6 was not lifecycle activation"
[[ "${CALLS[6]}" == *"wprism lifecycle-settle"* ]] || fail "code path phase 7 was not lifecycle settlement"
[[ "${CALLS[7]}" == *"wprism code-finalize"* ]] || fail "code path phase 8 was not code-finalize"
[[ "${CALLS[8]}" == *"wprism apply"* ]] || fail "code path phase 9 was not apply"
assert_control_call "${CALLS[0]}" "code compile"
assert_control_call "${CALLS[1]}" "code promotion-begin"
assert_database_checkpoint_call "${CALLS[2]}" "code checkpoint"
assert_control_call "${CALLS[3]}" "code stage"
assert_runtime_call "${CALLS[4]}" "code retirement"
assert_runtime_call "${CALLS[5]}" "code activation"
assert_runtime_call "${CALLS[6]}" "lifecycle settlement"
assert_control_call "${CALLS[7]}" "code finalize"
assert_runtime_call "${CALLS[8]}" "code apply"
assert_begin_matches_mutations "${CALLS[1]}" "${CALLS[3]}" "${CALLS[4]}" "${CALLS[5]}" "${CALLS[6]}" "${CALLS[7]}" "${CALLS[8]}"
assert_same_artifact_and_owner "${CALLS[3]}" "${CALLS[4]}" "${CALLS[5]}" "${CALLS[6]}" "${CALLS[7]}" "${CALLS[8]}"
[[ "${CALLS[3]}" != *"--promotion-hold"* && "${CALLS[3]}" != *"--materializing-code"* ]] \
  || fail "code-stage received lifecycle-only flags"
[[ "${CALLS[4]}" == *"--promotion-hold"* && "${CALLS[4]}" == *"--materializing-code"* && "${CALLS[4]}" == *"--state-handoff"* \
  && "${CALLS[5]}" == *"--promotion-hold"* && "${CALLS[5]}" == *"--materializing-code"* && "${CALLS[5]}" == *"--state-handoff"* ]] \
  || fail "code lifecycle phases did not retain lease and mark materialization/state handoff"
[[ "${CALLS[4]}" == *"--force-unresolved-refs"* && "${CALLS[5]}" == *"--force-unresolved-refs"* \
  && "${CALLS[8]}" == *"--force-unresolved-refs"* ]] \
  || fail "code lifecycle/apply did not share unresolved-ref snapshot policy"
[[ "${CALLS[6]}" != *"--materializing-code"* && "${CALLS[6]}" != *"--state-handoff"* ]] \
  || fail "lifecycle settlement received materialization/state-handoff flags"
[[ "${CALLS[7]}" == *"--promotion-hold"* && "${CALLS[7]}" != *"--materializing-code"* ]] \
  || fail "code-finalize did not retain lease cleanly for apply"
[[ "${CALLS[8]}" != *"--promotion-hold"* && "${CALLS[8]}" != *"--materializing-code"* && "${CALLS[8]}" != *"--state-handoff"* ]] \
  || fail "apply received code/lifecycle-only flags"
assert_checkpoint "$OUT"
has "$OUT" 'promote complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize -> apply' \
  || fail "code-enabled promote success line missing complete phase trace"
if has "${CALLS[*]}" 'promotion-abort'; then
  fail "successful code promotion invoked compensating abort"
fi
pass "code artifact sequences begin -> checkpoint -> stage -> retire -> activate -> settle -> finalize -> apply"

mapfile -t TRACED_CALLS < "$TRACE"
[ "${#TRACED_CALLS[@]}" -eq 12 ] || fail "code path expected compile/preflight, target-bound sealed checkpoint pipeline, and later mutation calls"
[[ "${TRACED_CALLS[0]}" == *"wprism compile"* \
  && "${TRACED_CALLS[1]}" == *"wprism code-preflight"* \
  && "${TRACED_CALLS[2]}" == *"wprism promotion-begin"* \
  && "${TRACED_CALLS[3]}" == *"wprism checkpoint-target"* ]] \
  || fail "code target-runtime preflight did not run after compile and before promotion-begin"
assert_control_call "${TRACED_CALLS[1]}" "promotion target-runtime preflight"
[ "$(call_artifact "${TRACED_CALLS[1]}")" = "$(call_artifact "${TRACED_CALLS[6]}")" ] \
  || fail "promotion preflight and code-stage did not inspect one frozen artifact"
[ "$(call_hash "${TRACED_CALLS[1]}")" = "$(call_hash "${TRACED_CALLS[2]}")" ] \
  || fail "promotion preflight and promotion-begin did not bind one artifact hash"
[[ "${TRACED_CALLS[4]} ${TRACED_CALLS[5]}" == *"db export"* \
  && "${TRACED_CALLS[4]} ${TRACED_CALLS[5]}" == *"wprism checkpoint-seal"* \
  && "${TRACED_CALLS[4]} ${TRACED_CALLS[5]}" == *"--database-target-sha256=dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"* ]] \
  || fail "checkpoint export and sealer were not fenced to the preflight database target"
pass "code target-runtime and database-target preflights are control-plane, immutable, and before their mutation boundaries"

# A repository can carry a code descriptor while this particular artifact is
# content-only. The target's payload-verifying preflight is the authority; an
# unchanged completed revision skips pointer selection, stage, both lifecycle
# legs, and finalize, then applies state under the ordinary lease/checkpoint.
run_promote 1 env FAKE_CODE_CHANGE_REQUIRED=0
[ "$CODE" -eq 0 ] || fail "content-only promote exited non-zero: $OUT"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 4 ] || fail "content-only path expected compile/begin/checkpoint/apply, got ${#CALLS[@]} calls"
[[ "${CALLS[0]}" == *"wprism compile"* && "${CALLS[1]}" == *"wprism promotion-begin"* \
  && "${CALLS[2]}" == *"db export"* && "${CALLS[3]}" == *"wprism apply"* ]] \
  || fail "content-only phase order was not compile -> begin -> checkpoint -> apply"
if grep -Eq 'code-stage|code-finalize|lifecycle-phase' "$LOG"; then
  fail "THE property: unchanged code still invoked stage/finalize or extension lifecycle hooks"
fi
mapfile -t TRACED_CALLS < "$TRACE"
[ "${#TRACED_CALLS[@]}" -eq 8 ] || fail "content-only path omitted compile/preflight/lifecycle-status/target-bound sealing or added a mutation"
[[ "${TRACED_CALLS[1]}" == *"wprism code-preflight"* ]] \
  || fail "content-only decision did not come from target code preflight"
[[ "${TRACED_CALLS[2]}" == *"wprism lifecycle-status"* ]] \
  || fail "content-only promotion did not prove the target lifecycle already exact"
has "$OUT" 'promote complete: content apply; code lifecycle hooks not run' \
  || fail "content-only completion did not disclose the hook-free path"
pass "unchanged verified code revision makes content promotion lifecycle-hook-free"

# A stage failure is after the checkpoint but before every later mutation.
run_promote 1 env FAKE_STAGE_FAIL=1
[ "$CODE" -eq 8 ] || fail "code-stage failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 5 ] || fail "later phases or abort boundary were wrong after code-stage failure"
[[ "${CALLS[3]}" == *"wprism code-stage"* ]] || fail "fourth call was not failed code-stage"
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

# Newly active code can enqueue deferred migrations. Settlement is a required
# runtime phase, so its failure must stop finalize/apply and retain recovery.
run_promote 1 env FAKE_SETTLE_FAIL=1
[ "$CODE" -eq 15 ] || fail "lifecycle settlement failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 8 ] || fail "finalize/apply or abort boundary were wrong after settlement failure"
[[ "${CALLS[6]}" == *"wprism lifecycle-settle"* ]] || fail "seventh call was not failed lifecycle settlement"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[7]}"
has "$OUT" 'lifecycle-settle failed.*later phases were not run' \
  || fail "lifecycle settlement failure did not name its stop boundary"
assert_code_recovery_guidance "$OUT"
pass "lifecycle settlement failure stops finalize/apply and aborts the checkpoint lease"

# Finalization is still before apply. A failure cannot be reported as a
# completed promotion simply because lifecycle activation already succeeded.
run_promote 1 env FAKE_FINALIZE_FAIL=1
[ "$CODE" -eq 9 ] || fail "code-finalize failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 9 ] || fail "apply or abort boundary were wrong after code-finalize failure"
[[ "${CALLS[7]}" == *"wprism code-finalize"* ]] || fail "eighth call was not failed code-finalize"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[8]}"
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
[ "${#CALLS[@]}" -eq 10 ] || fail "unexpected calls around failed apply"
[[ "${CALLS[8]}" == *"wprism apply"* ]] || fail "ninth call was not failed apply"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[9]}"
assert_code_recovery_guidance "$OUT"
pass "code-enabled apply failure aborts the lease and requires ordered code recovery"

# State-only failures still need the recovery lease because the post-begin
# dump contains its promotion_lock row, but they never claim code recovery.
run_promote 0 env FAKE_APPLY_FAIL=1
[ "$CODE" -eq 10 ] || fail "legacy apply failure exit was not propagated (got $CODE)"
mapfile -t CALLS < "$LOG"
[ "${#CALLS[@]}" -eq 5 ] || fail "state-only apply failure did not run compensating abort"
[[ "${CALLS[3]}" == *"wprism apply"* ]] || fail "state-only failure did not reach apply directly"
assert_begin_and_abort "${CALLS[1]}" "${CALLS[4]}"
has "$OUT" 'checkpoint contains its temporary promotion lease row' \
  || fail "legacy apply failure omitted checkpoint lease recovery explanation"
has "$OUT" 'external maintenance/exclusion' \
  || fail "legacy apply failure omitted external recovery exclusion"
# Same issue #3525 substitution as assert_code_recovery_guidance above: the
# legacy arm shares the one chokepoint, so it gets the one verb too.
has "$OUT" '^wprism: promote: once that exclusion is in place, recover with: wprism recover unit --restore=promote-.* --writers-excluded --operator-directed$' \
  || fail "legacy apply failure named no recovery exit path: $OUT"
has "$OUT" 'releases the lease row the import reinstates, including when the import' \
  || fail "legacy apply failure dropped the mandatory-final-abort safety fact"
if grep -Fq -- 'wp db import' <<<"$OUT"; then
  fail "the legacy failure view still publishes the retired raw database import"
fi
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
[[ "${CALLS[0]}" == *"wprism compile"* ]] || fail "target-runtime refusal did not retain compile as its only historical phase"
mapfile -t TRACED_CALLS < "$TRACE"
[ "${#TRACED_CALLS[@]}" -eq 2 ] || fail "target-runtime refusal did not stop at compile/preflight"
[[ "${TRACED_CALLS[1]}" == *"wprism code-preflight"* ]] || fail "second traced call was not target-runtime preflight"
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
[[ "${CALLS[0]}" == *"wprism compile"* && "${CALLS[1]}" == *"wprism promotion-begin"* ]] \
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
[[ "${CALLS[0]}" == *"wprism compile"* && "${CALLS[1]}" == *"wprism promotion-begin"* ]] \
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
[[ "${CALLS[1]}" == *"wprism promotion-begin"* && "${CALLS[2]}" == *"db export"* ]] \
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
  if FAKE_CODE_ENABLED=1 "$WPRISM" --envs-file="$ENVS" promote unit "$internal" >/dev/null 2>&1; then
    fail "promote accepted caller-owned internal flag $internal"
  fi
  [ ! -s "$LOG" ] || fail "promote contacted target after rejecting $internal"
done
if FAKE_CODE_ENABLED=1 "$WPRISM" --envs-file="$ENVS" promote unit --repo /tmp/forged >/dev/null 2>&1; then
  fail "promote accepted split caller-owned --repo flag"
fi
[ ! -s "$LOG" ] || fail "promote contacted target after rejecting split --repo"
if FAKE_CODE_ENABLED=1 "$WPRISM" --envs-file="$ENVS" promote unit --artifact-hash aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa >/dev/null 2>&1; then
  fail "promote accepted split caller-owned --artifact-hash flag"
fi
[ ! -s "$LOG" ] || fail "promote contacted target after rejecting split --artifact-hash"
pass "promote owns repo/artifact/code/lease flags before target contact"

printf '\n✔ REGRESS_PROMOTION_UNIT PASSED\n'
