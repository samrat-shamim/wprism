#!/usr/bin/env bash
# Offline contract for the shared legacy-profile R1 proof primitives.
# No Docker daemon or WordPress is used: a fake docker records the public
# command shape and the library's readiness timeout is reduced via fake seq
# and sleep only inside its private test process.
set -euo pipefail
cd "$(dirname "$0")/../../.."

LIB="$PWD/lib/proof_legacy_pair.sh"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-proof-legacy-pair.XXXXXX")"
FAKE_BIN="$TMP/bin"
LOG="$TMP/docker.log"
STDIN_CAPTURE="$TMP/docker.stdin"
ERR="$TMP/error.log"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok: $*"; }
cleanup() {
  local status=$?
  trap - EXIT
  rm -rf -- "$TMP"
  exit "$status"
}
trap cleanup EXIT

mkdir -p "$FAKE_BIN"
cat > "$FAKE_BIN/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$PROOF_LEGACY_FAKE_LOG"

if [[ " $* " == *" run "* && " $* " == *" core version "* ]]; then
  attempts=0
  if [ -r "$PROOF_LEGACY_FAKE_ATTEMPTS" ]; then
    attempts="$(cat "$PROOF_LEGACY_FAKE_ATTEMPTS")"
  fi
  attempts=$((attempts + 1))
  printf '%s\n' "$attempts" > "$PROOF_LEGACY_FAKE_ATTEMPTS"
  [ "$attempts" -ge "${PROOF_LEGACY_FAKE_READY_AFTER:-1}" ]
  exit
fi

if [[ " $* " == *" exec "* && " $* " == *" tee /var/www/html/.htaccess "* ]]; then
  cat > "$PROOF_LEGACY_FAKE_STDIN"
fi
EOF
chmod +x "$FAKE_BIN/docker"

cat > "$FAKE_BIN/seq" <<'EOF'
#!/usr/bin/env bash
printf '1\n'
EOF
chmod +x "$FAKE_BIN/seq"

cat > "$FAKE_BIN/sleep" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$FAKE_BIN/sleep"

assert_log_has() { # assert_log_has <literal> <what>
  local literal="$1" what="$2"
  grep -Fqx -- "$literal" "$LOG" || {
    cat "$LOG" >&2
    fail "$what"
  }
}

[ -r "$LIB" ] || fail "shared legacy proof library is missing"
bash -n "$LIB"
grep -Fqx '  for _ in $(seq 1 90); do' "$LIB" \
  || fail 'shared readiness helper changed the historical 90-attempt bound'
grep -Fqx '    sleep 2' "$LIB" \
  || fail 'shared readiness helper changed the historical two-second interval'

export PATH="$FAKE_BIN:$PATH"
export PROOF_LEGACY_COMPOSE='docker compose -f docker-compose.yml --profile r1a'
export PROOF_LEGACY_FAKE_LOG="$LOG"
export PROOF_LEGACY_FAKE_STDIN="$STDIN_CAPTURE"
export PROOF_LEGACY_FAKE_ATTEMPTS="$TMP/attempts"

# shellcheck source=../../../lib/proof_legacy_pair.sh
source "$LIB"

proof_legacy_pair_wp_env r1a1 plugin list
assert_log_has 'compose -f docker-compose.yml --profile r1a run --rm -T cli-r1a1 wp plugin list' \
  'shared wp runner changed the legacy R1 compose/service command'
pass 'shared wp runner preserves the legacy profile command shape'

rm -f "$LOG" "$PROOF_LEGACY_FAKE_ATTEMPTS"
PROOF_LEGACY_FAKE_READY_AFTER=1 proof_legacy_pair_wait_for r1a2
assert_log_has 'compose -f docker-compose.yml --profile r1a run --rm -T cli-r1a2 wp core version' \
  'shared readiness probe changed the historical core-version command'
pass 'shared readiness probe preserves the first-success contract'

rm -f "$LOG" "$STDIN_CAPTURE"
proof_legacy_pair_write_htaccess r1a1
assert_log_has 'compose -f docker-compose.yml --profile r1a exec -T -u www-data wp-r1a1 tee /var/www/html/.htaccess' \
  'shared htaccess writer changed the legacy web service command'
grep -Fqx '# BEGIN WordPress' "$STDIN_CAPTURE" \
  || fail 'shared htaccess writer omitted the WordPress marker'
grep -Fqx 'RewriteRule ^index\.php$ - [L]' "$STDIN_CAPTURE" \
  || fail 'shared htaccess writer changed the rewrite rule'
grep -Fqx '# END WordPress' "$STDIN_CAPTURE" \
  || fail 'shared htaccess writer omitted the closing marker'
pass 'shared htaccess writer preserves the historical content'

rm -f "$PROOF_LEGACY_FAKE_ATTEMPTS"
if PROOF_LEGACY_FAKE_READY_AFTER=2 bash -c 'source "$1"; proof_legacy_pair_wait_for r1a3' bash "$LIB" > /dev/null 2>"$ERR"; then
  fail 'shared readiness helper succeeded when its only probe failed'
fi
grep -Fqx 'env r1a3 never became ready' "$ERR" \
  || { cat "$ERR" >&2; fail 'shared readiness helper changed its timeout diagnostic'; }
pass 'shared readiness helper preserves the timeout refusal'

for scenario in tests/grind/grind_r1a_forms.sh tests/grind/grind_r1b_shop.sh tests/grind/grind_r1c_agency.sh; do
  bash -n "$scenario"
  grep -Fqx 'source "lib/proof_legacy_pair.sh"' "$scenario" \
    || fail "$scenario does not source the shared legacy proof library"
  grep -Fqx 'wp_env() { proof_legacy_pair_wp_env "$@"; }' "$scenario" \
    || fail "$scenario retains a local wp command implementation"
  grep -Fqx 'wait_for() { proof_legacy_pair_wait_for "$@"; }' "$scenario" \
    || fail "$scenario retains a local readiness implementation"
  grep -Fqx 'write_htaccess() { proof_legacy_pair_write_htaccess "$@"; }' "$scenario" \
    || fail "$scenario retains a local htaccess implementation"
  if grep -Fq 'RewriteEngine On' "$scenario"; then
    fail "$scenario still carries the shared htaccess body"
  fi
done
pass 'all legacy R1 proof scenarios delegate only their common primitives'

echo 'regress-proof-legacy-pair: PASS'
