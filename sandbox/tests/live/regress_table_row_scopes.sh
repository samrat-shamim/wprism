#!/usr/bin/env bash
# Native evidence for BINARY ownership and current-row locking. The offline
# suite owns the grammar/compiler/identity matrix; this lane owns SQL behavior.
set -euo pipefail
cd "$(dirname "$0")/../.."
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
ROOT="$(cd .. && pwd -P)"
PAIR="${ROW_SCOPE_PAIR:-rowscope}"
PORT1="${ROW_SCOPE_PORT1:-9210}"
PORT2="${ROW_SCOPE_PORT2:-9211}"
ENGINE="${WPRISM_DB_ENGINE:-mariadb}"
SOURCE_SHA="$(git rev-parse HEAD)"
[ "${WPRISM_EXPECTED_SOURCE_SHA:-}" = "$SOURCE_SHA" ] || fail 'exact expected source SHA is required'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native row-scope source must be clean'
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_CODEBIND_PLUGIN=''
export WPRISM_WP_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
export WPRISM_CLI_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
ARTIFACTS="tmp/table-row-scopes/$PAIR/$SOURCE_SHA/$ENGINE"
mkdir -p "$ARTIFACTS"
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'native row-scope evidence' 'wprism-row-scopes'
WPRISM_DB_ENGINE="$ENGINE" bash bin/pair.sh list
pair_live_ownership_acquire "$ENGINE"
pair_live_ownership_up --journal
printf 'Source: %s; engine: %s\n' "$SOURCE_SHA" "$ENGINE" > "$ARTIFACTS/result.log"
docker inspect "$PAIR_LIVE_OWNERSHIP_CONTAINER" --format '{{.Image}}' > "$ARTIFACTS/database-image.txt"
bash tests/offline_diagnostics_guard.sh docker compose -p "wprism-$PAIR" -f pair.yml -f pair.journal.yml \
  run --rm -T -v "$ROOT/sandbox/tests:/wprism-tests:ro" cli1 \
  wp eval-file /wprism-tests/live/fixtures/table_row_scopes.php --use-include >> "$ARTIFACTS/result.log" 2>&1 \
  || { cat "$ARTIFACTS/result.log"; fail 'native row-scope regression failed'; }
cat "$ARTIFACTS/result.log"
pair_live_ownership_complete 'NATIVE_TABLE_ROW_SCOPES_PASSED'
