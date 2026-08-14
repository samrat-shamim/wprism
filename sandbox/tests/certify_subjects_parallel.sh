#!/usr/bin/env bash
# Run independently certified subjects concurrently on collision-free pairs.
# Subjects are convention-discovered from dispositions unless explicitly
# supplied through CERT_PARALLEL_SUBJECTS. The host-wide pair budget is read
# under the same lock used by pair.sh admission; no override is inferred.
set -euo pipefail
cd "$(dirname "$0")/../.."
PAIR_TOOL="${CERT_PARALLEL_PAIR_TOOL:-sandbox/bin/pair.sh}"
CERTIFIER="${CERT_PARALLEL_CERTIFIER:-sandbox/tests/certify_subject_bundle.sh}"
export PAIR_TOOL CERTIFIER

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

run_lane() { # --lane <index> <subject> <pair> <port1> <port2> <run-root> <source-sha>
  local index="$1" subject="$2" pair="$3" port1="$4" port2="$5" run_root="$6" source_sha="$7"
  local label subject_out log bundle count
  local -a bundles=()
  label="${subject//./-}"
  subject_out="$run_root/bundles/$index-$label"
  log="$run_root/logs/$index-$label.log"
  mkdir -p -- "$subject_out"
  CERT_SUBJECT_EXPECTED_SHA="$source_sha" CERT_SUBJECT_PAIR="$pair" \
    CERT_SUBJECT_PORT1="$port1" CERT_SUBJECT_PORT2="$port2" CERT_SUBJECT_OUT="$subject_out" \
    bash "$CERTIFIER" "$subject" >"$log" 2>&1 || {
      printf 'FAIL: %s (log: %s)\n' "$subject" "$log" >&2
      tail -60 "$log" >&2 || true
      return 1
    }
  mapfile -t bundles < <(find "$subject_out" -mindepth 1 -maxdepth 1 -type d -print)
  count="${#bundles[@]}"
  [ "$count" -eq 1 ] || {
    printf 'FAIL: %s produced %s bundle directories (log: %s)\n' "$subject" "$count" "$log" >&2
    return 1
  }
  bundle="${bundles[0]}"
  jq -e --arg source_sha "$source_sha" '.git_revision == $source_sha' "$bundle/bundle.json" >/dev/null \
    || { printf 'FAIL: %s produced a mixed-revision bundle\n' "$subject" >&2; return 1; }
  jq -n --arg subject "$subject" --arg pair "$pair" --argjson port1 "$port1" \
    --argjson port2 "$port2" --arg bundle "$bundle" --arg log "$log" \
    '{subject:$subject,pair:$pair,ports:[$port1,$port2],bundle:$bundle,log:$log}' \
    >"$run_root/results/$index.json"
  printf 'ok: %s -> %s\n' "$subject" "$bundle"
}

if [ "${1:-}" = --lane ]; then
  [ "$#" -eq 8 ] || fail "internal lane invocation is malformed"
  shift
  run_lane "$@"
  exit
fi

command -v jq >/dev/null || fail "jq required"
command -v php >/dev/null || fail "php required"
command -v python3 >/dev/null || fail "python3 required for isolated lane process groups"
[ -f "$PAIR_TOOL" ] && [ -f "$CERTIFIER" ] || fail "parallel pair tool/certifier is absent"
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

declare -A SEEN_SUBJECTS=()
for subject in "${SUBJECTS[@]}"; do
  [[ "$subject" =~ ^(manifests|profiles)\.([a-z][a-z0-9-]*)$ ]] \
    || fail "subject '$subject' is not canonical"
  section="${BASH_REMATCH[1]}"
  name="${BASH_REMATCH[2]}"
  jq -e --arg section "$section" --arg name "$name" \
    '.[$section][$name].status == "certified"' manifests/dispositions.json >/dev/null \
    || fail "subject '$subject' is absent or not certified"
  [ -z "${SEEN_SUBJECTS[$subject]:-}" ] || fail "subject '$subject' is duplicated"
  SEEN_SUBJECTS[$subject]=1
done

CAPACITY=$(bash "$PAIR_TOOL" capacity) \
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
LAST_PORT=$((PORT_BASE + (JOBS * 2) - 1))
[ "$LAST_PORT" -le 65535 ] || fail "parallel subject ports exceed 65535"

PAIR_PREFIX="${CERT_PARALLEL_PAIR_PREFIX:-certp$$}"
[[ "$PAIR_PREFIX" =~ ^[a-z][a-z0-9]*$ ]] \
  || fail "CERT_PARALLEL_PAIR_PREFIX must use lowercase letters/digits"
[ "${#PAIR_PREFIX}" -le 24 ] || fail "CERT_PARALLEL_PAIR_PREFIX must be at most 24 characters"
REGISTRATION_DELAY="${CERT_PARALLEL_REGISTRATION_DELAY:-0}"
[[ "$REGISTRATION_DELAY" =~ ^(0|[0-9]+([.][0-9]+)?)$ ]] \
  || fail "CERT_PARALLEL_REGISTRATION_DELAY must be a non-negative number"

OUT_PARENT="${CERT_PARALLEL_OUT:-${TMPDIR:-/tmp}/duo-subject-certification-parallel}"
mkdir -p -- "$OUT_PARENT"
RUN_ROOT=$(mktemp -d "$OUT_PARENT/run.XXXXXX")
mkdir -p -- "$RUN_ROOT/logs" "$RUN_ROOT/results" "$RUN_ROOT/bundles"

LEASE_TOKEN="$(php -r 'echo bin2hex(random_bytes(16));')"
OWNER_START="$(ps -o lstart= -p $$ 2>/dev/null | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
[ -n "$OWNER_START" ] || fail "could not identify parallel batch owner process"
declare -a LEASE_REQUEST=()
declare -a LEASE_PAIRS=()
for ((slot=0; slot<JOBS; slot++)); do
  pair="${PAIR_PREFIX}${slot}"
  port1=$((PORT_BASE + (slot * 2)))
  port2=$((port1 + 1))
  LEASE_REQUEST+=("$pair" "$port1" "$port2")
  LEASE_PAIRS+=("$pair")
done
bash "$PAIR_TOOL" lease-batch-acquire "$LEASE_TOKEN" "$$" "$OWNER_START" "${LEASE_REQUEST[@]}"
export DUO_PAIR_LEASE_TOKEN="$LEASE_TOKEN"
LEASED=1

declare -a ACTIVE_PIDS=()
declare -a ACTIVE_PAIRS=()
release_leases() {
  [ "${LEASED:-0}" -eq 1 ] || return 0
  bash "$PAIR_TOOL" lease-batch-release "$LEASE_TOKEN" >/dev/null 2>&1 || {
    printf 'WARNING: could not release pair leases for token %s\n' "$LEASE_TOKEN" >&2
    return 1
  }
  LEASED=0
}
exit_cleanup() {
  local status=$?
  trap - EXIT
  release_leases || true
  exit "$status"
}
trap exit_cleanup EXIT
abort_batch() {
  local status="$1" pid pair attempt any
  trap - INT TERM
  for pid in "${ACTIVE_PIDS[@]}"; do
    kill -TERM -- "-$pid" >/dev/null 2>&1 || true
  done
  for attempt in $(seq 1 50); do
    any=0
    for pid in "${ACTIVE_PIDS[@]}"; do
      if kill -0 -- "-$pid" >/dev/null 2>&1; then any=1; fi
    done
    [ "$any" -eq 1 ] || break
    sleep 0.1
  done
  for pid in "${ACTIVE_PIDS[@]}"; do
    kill -KILL -- "-$pid" >/dev/null 2>&1 || true
    wait "$pid" >/dev/null 2>&1 || true
  done
  for pair in "${ACTIVE_PAIRS[@]}"; do
    bash "$PAIR_TOOL" destroy "$pair" >/dev/null 2>&1 || true
  done
  printf 'FAIL: parallel certification interrupted; logs remain under %s\n' "$RUN_ROOT" >&2
  exit "$status"
}
trap 'abort_batch 130' INT
trap 'abort_batch 143' TERM

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
    slot="$offset"
    subject="${SUBJECTS[$index]}"
    pair="${PAIR_PREFIX}${slot}"
    port1=$((PORT_BASE + (slot * 2)))
    port2=$((port1 + 1))
    pending_signal=0
    trap 'pending_signal=130' INT
    trap 'pending_signal=143' TERM
    python3 -c 'import os,sys; os.setsid(); os.execvp(sys.argv[1], sys.argv[1:])' \
      bash "$PWD/sandbox/tests/certify_subjects_parallel.sh" --lane \
      "$index" "$subject" "$pair" "$port1" "$port2" "$RUN_ROOT" "$SOURCE_SHA" &
    lane_pid="$!"
    [ "$REGISTRATION_DELAY" = 0 ] || sleep "$REGISTRATION_DELAY" || true
    wave_pids+=("$lane_pid")
    wave_subjects+=("$subject")
    ACTIVE_PIDS+=("$lane_pid")
    ACTIVE_PAIRS+=("$pair")
    trap 'abort_batch 130' INT
    trap 'abort_batch 143' TERM
    [ "$pending_signal" -eq 0 ] || abort_batch "$pending_signal"
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

[ "$(git rev-parse --verify HEAD^{commit})" = "$SOURCE_SHA" ] \
  || fail "parallel certification source HEAD changed before index assembly"
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] \
  || fail "parallel certification source became dirty before index assembly"
for result in "$RUN_ROOT"/results/*.json; do
  bundle="$(jq -er '.bundle' "$result")" || fail "parallel result is malformed: $result"
  jq -e --arg source_sha "$SOURCE_SHA" '.git_revision == $source_sha' "$bundle/bundle.json" >/dev/null \
    || fail "parallel result mixes a different source revision: $result"
done

jq -s --arg source_sha "$SOURCE_SHA" --arg run_root "$RUN_ROOT" \
  --argjson jobs "$JOBS" --argjson capacity "$CAPACITY" \
  '{schema_version:1,source_sha:$source_sha,run_root:$run_root,jobs:$jobs,
    capacity:$capacity,subjects:sort_by(.subject)}' \
  "$RUN_ROOT"/results/*.json >"$RUN_ROOT/index.json"
release_leases
jq . "$RUN_ROOT/index.json"
printf 'Import each .subjects[].bundle only after every exact-source run has completed.\n'
