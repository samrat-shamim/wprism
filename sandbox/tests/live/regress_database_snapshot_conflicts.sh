#!/usr/bin/env bash
# MariaDB ER_CHECKREAD is a real server rollback, including on locking reads.
set -euo pipefail
cd "$(dirname "$0")/../.."
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
ROOT="$(cd .. && pwd -P)"
PAIR="${SNAPSHOT_CONFLICT_PAIR:-snapconflict}"
PORT1="${SNAPSHOT_CONFLICT_PORT1:-9212}"
PORT2="${SNAPSHOT_CONFLICT_PORT2:-9213}"
SOURCE_SHA="$(git rev-parse HEAD)"
[ "${WPRISM_EXPECTED_SOURCE_SHA:-}" = "$SOURCE_SHA" ] || fail 'exact expected source SHA is required'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native snapshot-conflict source must be clean'
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_DB_ENGINE=mariadb WPRISM_CODEBIND_PLUGIN=''
export WPRISM_WP_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
export WPRISM_CLI_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
ARTIFACTS="tmp/database-snapshot-conflicts/$PAIR/$SOURCE_SHA"
mkdir -p "$ARTIFACTS"
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'native snapshot-conflict evidence' 'wprism-snapshot-conflicts'
bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --journal
printf 'Source: %s; engine: mariadb\n' "$SOURCE_SHA" > "$ARTIFACTS/result.log"
docker inspect "$PAIR_LIVE_OWNERSHIP_CONTAINER" --format '{{.Image}}' > "$ARTIFACTS/database-image.txt"
bash tests/offline_diagnostics_guard.sh docker compose -p "wprism-$PAIR" -f pair.yml -f pair.journal.yml \
  run --rm -T -v "$ROOT/sandbox/tests:/wprism-tests:ro" cli1 \
  wp eval-file /wprism-tests/live/fixtures/database_snapshot_conflicts.php --use-include >> "$ARTIFACTS/result.log" 2>&1 \
  || { cat "$ARTIFACTS/result.log"; fail 'native snapshot-conflict regression failed'; }
cat "$ARTIFACTS/result.log"
pair_live_ownership_complete 'NATIVE_DATABASE_SNAPSHOT_CONFLICTS_PASSED'
