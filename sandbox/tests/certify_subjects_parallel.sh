#!/usr/bin/env bash
# Run independently certified subjects concurrently on collision-free pairs.
# Subjects are convention-discovered from dispositions unless explicitly
# supplied through CERT_PARALLEL_SUBJECTS. The host-wide pair budget is read
# under the same lock used by pair.sh admission; no override is inferred.
set -euo pipefail
cd "$(dirname "$0")/../.."

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
command -v jq >/dev/null || fail "jq required"
case "${DUO_PAIR_BUDGET_OVERRIDE:-}" in
  ''|0) ;;
  1) fail "parallel certification refuses DUO_PAIR_BUDGET_OVERRIDE; free capacity or reduce JOBS" ;;
  *) fail "DUO_PAIR_BUDGET_OVERRIDE must be unset or 0" ;;
esac

SOURCE_SHA=$(git rev-parse --verify HEAD^{commit}) \
  || fail "parallel certification requires a Git checkout"
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] \
  || fail "parallel certification requires a clean exact-source checkout"

declare -a SUBJECTS=()
if [ -n "${CERT_PARALLEL_SUBJECTS:-}" ]; then
  read -r -a SUBJECTS <<<"$CERT_PARALLEL_SUBJECTS"
else
  while IFS= read -r subject; do
    [ -n "$subject" ] && SUBJECTS+=("$subject")
  done < <(jq -r '
    (.manifests | to_entries[] | select(.value.status == "certified") | "manifests." + .key),
    (.profiles | to_entries[] | select(.value.status == "certified") | "profiles." + .key)
  ' manifests/dispositions.json)
fi
[ "${#SUBJECTS[@]}" -gt 0 ] || fail "no certified subjects selected"

for subject in "${SUBJECTS[@]}"; do
  [[ "$subject" =~ ^(manifests|profiles)\.([a-z][a-z0-9-]*)$ ]] \
    || fail "subject '$subject' is not canonical"
  section="${BASH_REMATCH[1]}"
  name="${BASH_REMATCH[2]}"
  jq -e --arg section "$section" --arg name "$name" \
    '.[$section][$name].status == "certified"' manifests/dispositions.json >/dev/null \
    || fail "subject '$subject' is absent or not certified"
done

CAPACITY=$(bash sandbox/bin/pair.sh capacity) \
  || fail "could not read the locked Docker pair capacity"
AVAILABLE=$(jq -er '.available | select(type == "number" and . >= 0 and floor == .)' <<<"$CAPACITY") \
  || fail "pair capacity did not report an integer available count"
[ "$AVAILABLE" -gt 0 ] \
  || fail "the Docker host has no free pair slots; stop idle pairs and retry"

REQUESTED_JOBS="${CERT_PARALLEL_JOBS:-$AVAILABLE}"
[[ "$REQUESTED_JOBS" =~ ^[1-9][0-9]*$ ]] \
  || fail "CERT_PARALLEL_JOBS must be a positive integer"
[ "$REQUESTED_JOBS" -le "$AVAILABLE" ] \
  || fail "CERT_PARALLEL_JOBS=$REQUESTED_JOBS exceeds the $AVAILABLE currently available pair slots"
JOBS="$REQUESTED_JOBS"
[ "$JOBS" -le "${#SUBJECTS[@]}" ] || JOBS="${#SUBJECTS[@]}"

PORT_BASE="${CERT_PARALLEL_PORT_BASE:-8900}"
[[ "$PORT_BASE" =~ ^[0-9]+$ ]] || fail "CERT_PARALLEL_PORT_BASE must be decimal"
(( PORT_BASE >= 8900 && PORT_BASE % 2 == 0 )) \
  || fail "CERT_PARALLEL_PORT_BASE must be an even port >= 8900"
LAST_PORT=$((PORT_BASE + (${#SUBJECTS[@]} * 2) - 1))
[ "$LAST_PORT" -le 65535 ] || fail "parallel subject ports exceed 65535"

PAIR_PREFIX="${CERT_PARALLEL_PAIR_PREFIX:-certp$$}"
[[ "$PAIR_PREFIX" =~ ^[a-z][a-z0-9]*$ ]] \
  || fail "CERT_PARALLEL_PAIR_PREFIX must use lowercase letters/digits"
[ "${#PAIR_PREFIX}" -le 24 ] || fail "CERT_PARALLEL_PAIR_PREFIX must be at most 24 characters"

OUT_PARENT="${CERT_PARALLEL_OUT:-${TMPDIR:-/tmp}/duo-subject-certification-parallel}"
mkdir -p -- "$OUT_PARENT"
RUN_ROOT=$(mktemp -d "$OUT_PARENT/run.XXXXXX")
mkdir -p -- "$RUN_ROOT/logs" "$RUN_ROOT/results" "$RUN_ROOT/bundles"

declare -a ACTIVE_PIDS=()
declare -a ACTIVE_PAIRS=()
abort_batch() {
  local status="$1" pid pair
  trap - INT TERM
  for pid in "${ACTIVE_PIDS[@]}"; do
    kill -TERM "$pid" >/dev/null 2>&1 || true
  done
  for pid in "${ACTIVE_PIDS[@]}"; do
    wait "$pid" >/dev/null 2>&1 || true
  done
  for pair in "${ACTIVE_PAIRS[@]}"; do
    bash sandbox/bin/pair.sh destroy "$pair" >/dev/null 2>&1 || true
  done
  printf 'FAIL: parallel certification interrupted; logs remain under %s\n' "$RUN_ROOT" >&2
  exit "$status"
}
trap 'abort_batch 130' INT
trap 'abort_batch 143' TERM

run_subject() {
  local index="$1" subject="$2" pair port1 port2 label subject_out log bundle count runner_pid rc
  pair="${PAIR_PREFIX}${index}"
  port1=$((PORT_BASE + (index * 2)))
  port2=$((port1 + 1))
  label="${subject//./-}"
  subject_out="$RUN_ROOT/bundles/$label"
  log="$RUN_ROOT/logs/$label.log"
  mkdir -p -- "$subject_out"
  CERT_SUBJECT_PAIR="$pair" CERT_SUBJECT_PORT1="$port1" CERT_SUBJECT_PORT2="$port2" \
    CERT_SUBJECT_OUT="$subject_out" \
    bash sandbox/tests/certify_subject_bundle.sh "$subject" >"$log" 2>&1 &
  runner_pid=$!
  trap 'kill -TERM "$runner_pid" >/dev/null 2>&1 || true; wait "$runner_pid" >/dev/null 2>&1 || true; exit 143' TERM
  trap 'kill -INT "$runner_pid" >/dev/null 2>&1 || true; wait "$runner_pid" >/dev/null 2>&1 || true; exit 130' INT
  set +e
  wait "$runner_pid"
  rc=$?
  set -e
  trap - INT TERM
  if [ "$rc" -ne 0 ]; then
    printf 'FAIL: %s (log: %s)\n' "$subject" "$log" >&2
    tail -60 "$log" >&2 || true
    return 1
  fi
  mapfile -t bundles < <(find "$subject_out" -mindepth 1 -maxdepth 1 -type d -print)
  count="${#bundles[@]}"
  [ "$count" -eq 1 ] || {
    printf 'FAIL: %s produced %s bundle directories (log: %s)\n' "$subject" "$count" "$log" >&2
    return 1
  }
  bundle="${bundles[0]}"
  jq -n --arg subject "$subject" --arg pair "$pair" --argjson port1 "$port1" \
    --argjson port2 "$port2" --arg bundle "$bundle" --arg log "$log" \
    '{subject:$subject,pair:$pair,ports:[$port1,$port2],bundle:$bundle,log:$log}' \
    >"$RUN_ROOT/results/$index.json"
  printf 'ok: %s -> %s\n' "$subject" "$bundle"
}

printf 'Parallel subject certification: %s subjects, %s workers, ports %s-%s\n' \
  "${#SUBJECTS[@]}" "$JOBS" "$PORT_BASE" "$LAST_PORT"
printf 'Output: %s\n' "$RUN_ROOT"

for ((wave=0; wave<${#SUBJECTS[@]}; wave+=JOBS)); do
  declare -a wave_pids=()
  declare -a wave_subjects=()
  ACTIVE_PIDS=()
  ACTIVE_PAIRS=()
  for ((offset=0; offset<JOBS && wave+offset<${#SUBJECTS[@]}; offset++)); do
    index=$((wave + offset))
    subject="${SUBJECTS[$index]}"
    run_subject "$index" "$subject" &
    wave_pids+=("$!")
    wave_subjects+=("$subject")
    ACTIVE_PIDS+=("$!")
    ACTIVE_PAIRS+=("${PAIR_PREFIX}${index}")
  done
  wave_failed=0
  for ((offset=0; offset<${#wave_pids[@]}; offset++)); do
    if ! wait "${wave_pids[$offset]}"; then
      printf 'FAIL: parallel worker failed for %s\n' "${wave_subjects[$offset]}" >&2
      wave_failed=1
    fi
  done
  ACTIVE_PIDS=()
  ACTIVE_PAIRS=()
  [ "$wave_failed" -eq 0 ] || fail "parallel certification wave failed; successful bundles remain under $RUN_ROOT"
done

trap - INT TERM

jq -s --arg source_sha "$SOURCE_SHA" --arg run_root "$RUN_ROOT" \
  --argjson jobs "$JOBS" --argjson capacity "$CAPACITY" \
  '{schema_version:1,source_sha:$source_sha,run_root:$run_root,jobs:$jobs,
    capacity:$capacity,subjects:sort_by(.subject)}' \
  "$RUN_ROOT"/results/*.json >"$RUN_ROOT/index.json"
jq . "$RUN_ROOT/index.json"
printf 'Import each .subjects[].bundle only after every exact-source run has completed.\n'
