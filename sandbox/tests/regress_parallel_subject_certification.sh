#!/usr/bin/env bash
# Offline scheduler proof: parallel lanes, duplicate/collision refusal,
# exact-source indexing, and process-group interruption cleanup.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-parallel-certification.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }

LEASE_ROOT="$TMP/lease-root"
mkdir -p "$LEASE_ROOT/sandbox/siterepo" "$TMP/lease-bin"
cat >"$TMP/lease-bin/lsof" <<'SH'
#!/usr/bin/env bash
case " $* " in
  *" -iTCP:${LEASE_TEST_OCCUPIED_PORT:-none} "*) exit 0 ;;
  *) exit 1 ;;
esac
SH
chmod +x "$TMP/lease-bin/lsof"
ORIGINAL_PATH="$PATH"
export PATH="$TMP/lease-bin:$PATH"
source "$ROOT/sandbox/lib/pair_identity.sh"
source "$ROOT/sandbox/lib/pair_lease.sh"
PAIR_CANONICAL_ROOT="$LEASE_ROOT"
pair_compose_all_pairs() { printf '%s' "${LEASE_TEST_COMPOSE_PAIRS:-}"; }
pair_compose_all_bound_ports() { printf '%s' "${LEASE_TEST_BOUND_PORTS:-}"; }
lease_token=0123456789abcdef0123456789abcdef
lease_owner_start="$(pair_lease_owner_start $$)"
pushd "$LEASE_ROOT/sandbox" >/dev/null
pair_lease_acquire_batch "$lease_token" "$$" "$lease_owner_start" plock 9460 9461
[ -f "$LEASE_ROOT/sandbox/siterepo/.pair-leases/plock.json" ] \
  || fail "real pair lease did not publish its ownership record"
DUO_PAIR_LEASE_TOKEN="$lease_token" pair_lease_assert_access plock 9460 9461
if (DUO_PAIR_LEASE_TOKEN=ffffffffffffffffffffffffffffffff; pair_lease_assert_access plock) >/dev/null 2>&1; then
  fail "a different token accessed an owned pair lease"
fi
if (pair_lease_assert_access ordinary 9460 9461) >/dev/null 2>&1; then
  fail "an ordinary pair command reused ports owned by another lease"
fi
if (pair_lease_acquire_batch fedcba9876543210fedcba9876543210 "$$" "$lease_owner_start" plock 9462 9463) >/dev/null 2>&1; then
  fail "a second batch acquired an already leased pair name"
fi
mkdir -p "$LEASE_ROOT/sandbox/siterepo/pstate1"
if (pair_lease_acquire_batch fedcba9876543210fedcba9876543210 "$$" "$lease_owner_start" pstate 9462 9463) >/dev/null 2>&1; then
  fail "a pair lease ignored existing site state"
fi
rmdir "$LEASE_ROOT/sandbox/siterepo/pstate1"
export LEASE_TEST_OCCUPIED_PORT=9462
if (pair_lease_acquire_batch fedcba9876543210fedcba9876543210 "$$" "$lease_owner_start" pport 9462 9463) >/dev/null 2>&1; then
  fail "a pair lease ignored an occupied host port"
fi
unset LEASE_TEST_OCCUPIED_PORT
export LEASE_TEST_BOUND_PORTS=$'stopped\t9464\nstopped\t9465\n'
if (pair_lease_acquire_batch fedcba9876543210fedcba9876543210 "$$" "$lease_owner_start" pstopped 9464 9465) >/dev/null 2>&1; then
  fail "a pair lease ignored ports retained by a stopped pair"
fi
unset LEASE_TEST_BOUND_PORTS
pair_lease_release_token "$lease_token"
[ ! -e "$LEASE_ROOT/sandbox/siterepo/.pair-leases/plock.json" ] \
  || fail "pair lease release left its ownership record"
popd >/dev/null
export PATH="$ORIGINAL_PATH"
pass "real leases exclude ordinary commands, stopped bindings, owned names, existing roots, occupied ports, and wrong-token mutation"

REPO="$TMP/repo"
mkdir -p "$REPO/sandbox/tests" "$REPO/manifests" "$TMP/bin"
cp "$ROOT/sandbox/tests/certify_subjects_parallel.sh" "$REPO/sandbox/tests/"
cp "$ROOT/manifests/dispositions.json" "$REPO/manifests/"

cat >"$TMP/bin/pair.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"${FAKE_PAIR_LOG:?}"
case "${1:-}" in
  capacity)
    printf '{"schema_version":1,"budget":2,"live":0,"reserved":0,"available":2,"pairs":[],"reserved_pairs":[]}\n'
    ;;
  lease-batch-acquire)
    [ "${FAKE_LEASE_REFUSE:-0}" != 1 ] || { printf 'synthetic lease collision\n' >&2; exit 19; }
    ;;
  lease-batch-release|destroy) ;;
  *) printf 'unexpected fake pair command: %s\n' "$*" >&2; exit 20 ;;
esac
SH
chmod +x "$TMP/bin/pair.sh"

cat >"$TMP/bin/certifier.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
subject="${1:?}"
printf '%s\n' "$subject" >>"${FAKE_CERT_LOG:?}"
if [ "${FAKE_LONG_SUBJECT:-}" = "$subject" ]; then
  sleep 300 &
  child=$!
  printf '%s\n' "$child" >"${FAKE_CHILD_PID:?}"
  wait "$child"
fi
if [ "${FAKE_DIRTY_SUBJECT:-}" = "$subject" ]; then
  printf '\n' >>manifests/dispositions.json
fi
bundle="$CERT_SUBJECT_OUT/${subject//./-}-bundle"
mkdir -p "$bundle"
printf '{"git_revision":"%s"}\n' "$CERT_SUBJECT_EXPECTED_SHA" >"$bundle/bundle.json"
SH
chmod +x "$TMP/bin/certifier.sh"

git -C "$REPO" init -q
git -C "$REPO" -c user.name=parallel-regression -c user.email=parallel@example.test add -A
git -C "$REPO" -c user.name=parallel-regression -c user.email=parallel@example.test commit -qm fixture

PAIR_LOG="$TMP/pair.log"
CERT_LOG="$TMP/cert.log"
export FAKE_PAIR_LOG="$PAIR_LOG" FAKE_CERT_LOG="$CERT_LOG"
common_env=(
  CERT_PARALLEL_PAIR_TOOL="$TMP/bin/pair.sh"
  CERT_PARALLEL_CERTIFIER="$TMP/bin/certifier.sh"
  CERT_PARALLEL_PAIR_PREFIX=ptest
  CERT_PARALLEL_PORT_BASE=9300
)

OUT="$TMP/normal"
env "${common_env[@]}" CERT_PARALLEL_JOBS=2 CERT_PARALLEL_OUT="$OUT" \
  CERT_PARALLEL_SUBJECTS='manifests.acf manifests.woocommerce profiles.fse' \
  bash "$REPO/sandbox/tests/certify_subjects_parallel.sh" >"$TMP/normal.out"
INDEX="$(find "$OUT" -name index.json -type f -print -quit)"
[ -f "$INDEX" ] || fail "parallel scheduler did not publish an index"
jq -e '.jobs == 2 and (.subjects | length) == 3
  and ([.subjects[].pair] | unique | length) == 2
  and ([.subjects[].ports[0]] | unique | length) == 2' "$INDEX" >/dev/null \
  || fail "parallel index does not prove two leased slots served three subjects"
grep -q '^lease-batch-acquire ' "$PAIR_LOG" && grep -q '^lease-batch-release ' "$PAIR_LOG" \
  || fail "parallel scheduler did not acquire and release its batch leases"
pass "two isolated slots run three subjects in waves and publish one exact-source index"

pair_lines_before="$(wc -l <"$PAIR_LOG" | tr -d ' ')"
if env "${common_env[@]}" CERT_PARALLEL_JOBS=2 CERT_PARALLEL_OUT="$TMP/duplicate" \
  CERT_PARALLEL_SUBJECTS='manifests.acf manifests.acf' \
  bash "$REPO/sandbox/tests/certify_subjects_parallel.sh" >"$TMP/duplicate.out" 2>"$TMP/duplicate.err"; then
  fail "duplicate subjects were accepted"
fi
grep -q "subject 'manifests.acf' is duplicated" "$TMP/duplicate.err" \
  || fail "duplicate refusal is not actionable"
[ "$(wc -l <"$PAIR_LOG" | tr -d ' ')" = "$pair_lines_before" ] \
  || fail "duplicate refusal happened after pair mutation"
pass "duplicate subjects refuse before lease or lane allocation"

cert_lines_before="$(wc -l <"$CERT_LOG" | tr -d ' ')"
if env "${common_env[@]}" FAKE_LEASE_REFUSE=1 CERT_PARALLEL_JOBS=1 CERT_PARALLEL_OUT="$TMP/collision" \
  CERT_PARALLEL_SUBJECTS='manifests.acf' \
  bash "$REPO/sandbox/tests/certify_subjects_parallel.sh" >"$TMP/collision.out" 2>"$TMP/collision.err"; then
  fail "pair lease collision was accepted"
fi
[ "$(wc -l <"$CERT_LOG" | tr -d ' ')" = "$cert_lines_before" ] \
  || fail "lease collision launched a certification lane"
pass "name/port lease refusal happens before a certification process starts"

if env "${common_env[@]}" CERT_PARALLEL_JOBS=1 CERT_PARALLEL_OUT="$TMP/drift" \
  CERT_PARALLEL_SUBJECTS='manifests.acf manifests.woocommerce' FAKE_DIRTY_SUBJECT=manifests.acf \
  bash "$REPO/sandbox/tests/certify_subjects_parallel.sh" >"$TMP/drift.out" 2>"$TMP/drift.err"; then
  fail "source drift across waves was accepted"
fi
grep -q 'source became dirty before index assembly' "$TMP/drift.err" \
  || fail "source-drift refusal is not actionable"
git -C "$REPO" checkout -- manifests/dispositions.json
[ -z "$(find "$TMP/drift" -name index.json -type f -print -quit)" ] \
  || fail "mixed-source batch published an index"
pass "source drift across waves refuses before index publication"

cert_lines_before="$(wc -l <"$CERT_LOG" | tr -d ' ')"
env "${common_env[@]}" CERT_PARALLEL_JOBS=1 CERT_PARALLEL_OUT="$TMP/pre-setsid-signal" \
  CERT_PARALLEL_SUBJECTS='manifests.acf' CERT_PARALLEL_PRE_SETSID_DELAY=1 \
  bash "$REPO/sandbox/tests/certify_subjects_parallel.sh" \
  >"$TMP/pre-setsid-signal.out" 2>"$TMP/pre-setsid-signal.err" &
batch_pid=$!
launcher_pid=''
for _ in $(seq 1 200); do
  launcher_pid="$(ps -axo pid=,ppid=,command= 2>/dev/null \
    | awk -v parent="$batch_pid" '$2 == parent && tolower($3) ~ /python/ { print $1; exit }' || true)"
  [ -n "$launcher_pid" ] && break
  sleep 0.01
done
[ -n "$launcher_pid" ] || { kill -KILL "$batch_pid" 2>/dev/null || true; fail "pre-setsid launcher did not start"; }
kill -TERM "$batch_pid"
pre_setsid_status=0
wait "$batch_pid" || pre_setsid_status=$?
[ "$pre_setsid_status" -eq 143 ] || fail "pre-setsid SIGTERM batch exit was $pre_setsid_status, expected 143"
if kill -0 "$launcher_pid" >/dev/null 2>&1; then
  fail "pre-setsid SIGTERM left launcher $launcher_pid alive"
fi
[ "$(wc -l <"$CERT_LOG" | tr -d ' ')" = "$cert_lines_before" ] \
  || fail "pre-setsid SIGTERM allowed the certifier to run before process-group registration"
grep -q '^destroy ptest0$' "$PAIR_LOG" && grep -q '^lease-batch-release ' "$PAIR_LOG" \
  || fail "pre-setsid SIGTERM did not destroy its exact pair and release leases"
pass "SIGTERM before setsid waits for a registered process group and never runs the certifier"

CHILD_PID_FILE="$TMP/grandchild.pid"
export FAKE_CHILD_PID="$CHILD_PID_FILE"
env "${common_env[@]}" CERT_PARALLEL_JOBS=1 CERT_PARALLEL_OUT="$TMP/signal" \
  CERT_PARALLEL_SUBJECTS='manifests.acf' FAKE_LONG_SUBJECT=manifests.acf \
  CERT_PARALLEL_REGISTRATION_DELAY=1 \
  bash "$REPO/sandbox/tests/certify_subjects_parallel.sh" >"$TMP/signal.out" 2>"$TMP/signal.err" &
batch_pid=$!
for _ in $(seq 1 200); do [ -s "$CHILD_PID_FILE" ] && break; sleep 0.02; done
[ -s "$CHILD_PID_FILE" ] || { kill -KILL "$batch_pid" 2>/dev/null || true; fail "long-lived grandchild did not start"; }
grandchild_pid="$(cat "$CHILD_PID_FILE")"
kill -TERM "$batch_pid"
signal_status=0
wait "$batch_pid" || signal_status=$?
[ "$signal_status" -eq 143 ] || fail "SIGTERM batch exit was $signal_status, expected 143"
if kill -0 "$grandchild_pid" >/dev/null 2>&1; then
  fail "SIGTERM left certification grandchild $grandchild_pid alive"
fi
grep -q '^destroy ptest0$' "$PAIR_LOG" && grep -q '^lease-batch-release ' "$PAIR_LOG" \
  || fail "SIGTERM did not destroy its exact pair and release leases"
pass "SIGTERM terminates the complete lane process group and releases owned resources"

printf '✔ REGRESS_PARALLEL_SUBJECT_CERTIFICATION PASSED\n'
