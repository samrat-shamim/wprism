#!/usr/bin/env bash
# Native PHP/core-path evidence; existing physical reader dialect coverage is
# unchanged. This one MariaDB lane owns no persistent pair or adapter claim.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${NATIVE_PERMALINK_PAIR:?unique owned pair required}"
PORT1="${NATIVE_PERMALINK_PORT1:?even port required}"
PORT2="${NATIVE_PERMALINK_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[[ "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]] && [ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] || fail 'wrong native evidence source'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'dirty native evidence source'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'native permalink evidence' 'wprism-native-permalinks'
compose() { docker compose -p "wprism-$PAIR" -f pair.yml "$@"; }
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/native-permalinks-live.XXXXXX")
printf 'Retained source-bound %s native streams: %s\n' "$EXPECTED_SHA" "$sink"
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --headless
status=0
for suffix in stdout stderr exit; do (umask 077; set -C; : >"$sink/native.$suffix"); done
wprism_private_capture_stage "$sink" native compose run --rm -T \
  -v "$REPO_ROOT/sandbox/tests/fixtures/native-permalinks.php:/native-permalinks.php:ro" \
  cli1 wp eval-file /native-permalinks.php --use-include || status=$?
[ "$status" -eq 0 ] || fail "native permalink command exited $status; retained $sink/native"
php "$REPO_ROOT/sandbox/tests/fixtures/native-permalinks.php" --admit "$sink/native" "http://${PAIR}1.invalid"
pair_live_ownership_complete 'REGRESS_NATIVE_PERMALINKS PASSED'
