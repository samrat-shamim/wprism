#!/usr/bin/env bash
# Offline product-path regression for Thread 0's promotion quarantine.
#
# Promotion may be re-enabled only after Thread 5 durably binds and rechecks
# the chosen recovery provider/profile. Until then both ordinary and scoped
# public forms must refuse before target contact. Component orchestration below
# this gate remains covered by the focused DeployCommand, rollback-authority,
# and recovery protocol suites.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DUO="$REPO_ROOT/cli/duo"
FIX="$(mktemp -d)"
BIN="$FIX/bin"
SITE="$FIX/site"
ENVS="$FIX/envs.json"
CALLS="$FIX/wp.calls"

cleanup() { rm -rf "$FIX"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
trap cleanup EXIT

mkdir -p "$BIN" "$SITE"
cat > "$BIN/wp" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$DUO_FOUNDATION_PROMOTE_CALLS"
exit 97
FAKE
chmod +x "$BIN/wp"
cat > "$ENVS" <<EOF
{"envs":{"unit":{"transport":"local","wp_path":"$BIN","repo_path":"$SITE"}}}
EOF
export PATH="$BIN:$PATH" DUO_FOUNDATION_PROMOTE_CALLS="$CALLS"

set +e
"$DUO" --envs-file="$ENVS" promote unit --default-author=admin >"$FIX/human.out" 2>"$FIX/human.err"
human_status=$?
set -e
[ "$human_status" -eq 1 ] || fail "human promote did not exit 1"
[ ! -s "$FIX/human.out" ] || fail "human promote wrote stdout"
grep -Fxq \
  'duo: promote refused: recovery provider/profile selection is not yet durably bound and reverified before mutation' \
  "$FIX/human.err" || fail "human promote did not publish the fixed quarantine"
[ ! -e "$CALLS" ] || fail "human promote contacted the target"
pass "human promote refuses before target contact"

set +e
"$DUO" --envs-file="$ENVS" promote unit --scope-contract="$FIX/scope.json" --format=json \
  >"$FIX/json.out" 2>"$FIX/json.err"
json_status=$?
set -e
[ "$json_status" -eq 1 ] || fail "JSON promote did not exit 1"
[ ! -s "$FIX/json.err" ] || fail "JSON promote wrote human stderr"
[ ! -e "$CALLS" ] || fail "scoped JSON promote contacted the target"
php -r '
$bytes = file_get_contents($argv[1]);
$row = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
if (($row["format"] ?? null) !== "duo-command-refusal/v1"
    || ($row["ok"] ?? null) !== false
    || ($row["command"] ?? null) !== "promote"
    || ($row["error"] ?? null) !== "promotion_recovery_decision_unbound"
    || ($row["reason_code"] ?? null) !== "promotion_recovery_decision_unbound") {
    fwrite(STDERR, "invalid promotion quarantine envelope\n");
    exit(1);
}
' "$FIX/json.out" || fail "JSON promote did not publish its fixed quarantine envelope"
pass "ordinary and scoped JSON promote share the fail-closed recovery-decision gate"

printf 'REGRESS_PROMOTION_UNIT PASSED\n'
