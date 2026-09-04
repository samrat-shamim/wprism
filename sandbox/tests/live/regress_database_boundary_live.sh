#!/usr/bin/env bash
# Exact-source live regression for the generic database transaction boundary.
#
# Both claimed server dialects must prove the same physical facts through the
# shipped product path:
#   1. DatabaseLockBoundary's zero-row open retains a metadata lock until the
#      ProviderDatabaseSession read snapshot rolls back.
#   2. A view whose SQL SECURITY DEFINER function has an observable named-lock
#      side effect is rejected as non-plain before that function executes.
#   3. MariaDB NEXT/PREVIOUS VALUE FOR expressions are rejected by the checked
#      provider-read grammar before transport; the first subsequent direct
#      NEXT still returns the sequence's configured START value.
#   4. A schema-qualified stored function whose name is otherwise a SQL grammar
#      word is rejected before its observable named-lock side effect executes.
#
# This live, per-mechanism suite owns its one pair from creation through
# destruction and is intentionally outside regress-offline-all. Invoke it only
# from a clean committed candidate:
#
#   WPRISM_EXPECTED_SOURCE_SHA="$(git rev-parse HEAD)" \
#     make regress-database-boundary-live
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
note() { printf '\033[1;33mnote: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${DATABASE_BOUNDARY_PAIR:-dbbound${BASHPID}}"
DEFAULT_PORT1=$((20000 + (BASHPID % 20000) * 2))
PORT1="${DATABASE_BOUNDARY_PORT1:-$DEFAULT_PORT1}"
PORT2="${DATABASE_BOUNDARY_PORT2:-$((DEFAULT_PORT1 + 1))}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"
SOURCE_SHA="$(git -C "$REPO_ROOT" rev-parse --verify 'HEAD^{commit}')" \
  || fail 'database-boundary evidence has no resolvable Git HEAD'

[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || fail "DATABASE_BOUNDARY_PAIR must be a lowercase pair identifier, got '$PAIR'"
[ "${#PAIR}" -le 24 ] \
  || fail 'DATABASE_BOUNDARY_PAIR must be at most 24 characters for schema and lock identities'
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] \
  || fail 'database-boundary ports must be decimal integers'
PORT1=$((10#$PORT1)); PORT2=$((10#$PORT2))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail 'DATABASE_BOUNDARY_PORT1 must be even and >=8900; PORT2 must be its successor'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail 'WPRISM_EXPECTED_SOURCE_SHA must be the exact lowercase 40-character candidate SHA'
[ "$EXPECTED_SHA" = "$SOURCE_SHA" ] \
  || fail "WPRISM_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal checkout HEAD=$SOURCE_SHA"
[ -z "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
  || fail "database-boundary evidence requires a clean checkout at $SOURCE_SHA"
command -v docker >/dev/null || fail 'docker is required'

# WPRISM_SOURCE_ROOT is load-bearing in a linked worktree: pair.sh otherwise
# resolves the canonical checkout through Git's common directory. Binding it
# here makes the source mounted into WordPress the same clean commit that this
# exact script came from.
export WPRISM_SOURCE_ROOT="$REPO_ROOT"
export WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"

R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"
TMP_ROOT=''
CURRENT_DB_ENGINE=''
CURRENT_DB_CONTAINER=''
CURRENT_DB_CLIENT=''
CURRENT_DB_NAME=''
PAIR_OWNED=0
SITE_ROOTS_OWNED=0
ACTIVE_PID=''
ACTIVE_RELEASE=''
ACTIVE_LOG=''
MYSQL_SERVER_WAS_RUNNING=0
MYSQL_SERVER_PREEXISTED=0
MYSQL_SERVER_CLEANUP_COMPLETE=0

if docker inspect wprism-shared-mysql >/dev/null 2>&1; then
  MYSQL_SERVER_PREEXISTED=1
  if [ "$(docker inspect -f '{{.State.Running}}' wprism-shared-mysql 2>/dev/null || true)" = true ]; then
    MYSQL_SERVER_WAS_RUNNING=1
  fi
fi

compose() { docker compose -p "wprism-$PAIR" -f pair.yml "$@"; }
wp1() { compose run --rm -T cli1 wp "$@"; }

db_root() {
  docker exec -i -e MYSQL_PWD=root "$CURRENT_DB_CONTAINER" \
    "$CURRENT_DB_CLIENT" -uroot -N -B "$@"
}

db_query() {
  db_root -D "$CURRENT_DB_NAME" -e "$1"
}

db_script() {
  db_root -D "$CURRENT_DB_NAME"
}

remove_owned_path() {
  local owned="$1"
  case "$owned" in
    "siterepo/${PAIR}1"|"siterepo/${PAIR}2"|"siterepo/origin-${PAIR}.git") ;;
    *) fail "refusing to remove an unowned sandbox path: $owned" ;;
  esac
  [ ! -e "$owned" ] || find "$owned" -depth -delete
}

mysql_has_pair_consumers() {
  local container env
  while IFS= read -r container; do
    [ -n "$container" ] || continue
    [ "$(docker inspect -f '{{.Name}}' "$container" 2>/dev/null || true)" != '/wprism-shared-mysql' ] \
      || continue
    env="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$container" 2>/dev/null || true)"
    if grep -Fxq 'WORDPRESS_DB_HOST=wprism-shared-mysql' <<<"$env"; then
      return 0
    fi
  done < <(docker ps -aq --filter network=wprism-shared 2>/dev/null || true)
  return 1
}

settle_active_probe() {
  local status=0
  [ -n "$ACTIVE_PID" ] || return 0
  [ -z "$ACTIVE_RELEASE" ] || touch "$ACTIVE_RELEASE"
  for _ in $(seq 1 50); do
    kill -0 "$ACTIVE_PID" 2>/dev/null || break
    sleep 0.1
  done
  if kill -0 "$ACTIVE_PID" 2>/dev/null; then
    # Only the exact child recorded by this launcher is signalled. pair.sh
    # destroy below removes its one-off container if Compose itself was stuck.
    kill "$ACTIVE_PID" 2>/dev/null || true
  fi
  wait "$ACTIVE_PID" 2>/dev/null || status=$?
  ACTIVE_PID=''
  ACTIVE_RELEASE=''
  return "$status"
}

destroy_owned_pair() {
  local status=0
  if [ "$PAIR_OWNED" -eq 1 ]; then
    WPRISM_DB_ENGINE="$CURRENT_DB_ENGINE" bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 \
      || status=$?
    [ "$status" -eq 0 ] || return "$status"
    PAIR_OWNED=0
  fi
  if [ "$SITE_ROOTS_OWNED" -eq 1 ]; then
    remove_owned_path "$R1"
    remove_owned_path "$R2"
    remove_owned_path "$ORIGIN"
    SITE_ROOTS_OWNED=0
  fi
  return "$status"
}

cleanup_mysql_server() {
  [ "$MYSQL_SERVER_CLEANUP_COMPLETE" -eq 0 ] || return 0
  [ "$MYSQL_SERVER_WAS_RUNNING" -eq 0 ] || return 0
  if mysql_has_pair_consumers; then
    note 'leaving script-started MySQL server up because another pair is attached to it'
    return 0
  fi
  if [ "$MYSQL_SERVER_PREEXISTED" -eq 1 ]; then
    # Restore a pre-existing stopped server without deleting its shared data.
    docker compose -p wprism-db-mysql -f db.mysql.yml stop >/dev/null 2>&1 \
      || return $?
    return 0
  fi
  docker compose -p wprism-db-mysql -f db.mysql.yml down -v >/dev/null 2>&1 \
    || return $?
}

cleanup() {
  local status=$? step_status=0
  trap - EXIT INT TERM
  set +e
  settle_active_probe
  step_status=$?
  [ "$status" -ne 0 ] || status="$step_status"
  destroy_owned_pair
  step_status=$?
  [ "$status" -ne 0 ] || status="$step_status"
  cleanup_mysql_server
  step_status=$?
  [ "$status" -ne 0 ] || status="$step_status"
  if [ -n "$TMP_ROOT" ]; then
    rm -rf -- "$TMP_ROOT"
    step_status=$?
    [ "$status" -ne 0 ] || status="$step_status"
    if [ -e "$TMP_ROOT" ] || [ -L "$TMP_ROOT" ]; then
      [ "$status" -ne 0 ] || status=1
    fi
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wprism-database-boundary.${PAIR}.XXXXXX")"

wait_for_probe() {
  local label="$1" ready="$2"
  for _ in $(seq 1 200); do
    if [ -s "$ready" ]; then
      kill -0 "$ACTIVE_PID" 2>/dev/null \
        || { tail -80 "$ACTIVE_LOG" >&2; fail "$label exited after publishing a stale ready marker"; }
      return 0
    fi
    if ! kill -0 "$ACTIVE_PID" 2>/dev/null; then
      wait "$ACTIVE_PID" 2>/dev/null || true
      ACTIVE_PID=''
      tail -80 "$ACTIVE_LOG" >&2
      fail "$label exited before reaching its ready marker"
    fi
    sleep 0.1
  done
  tail -80 "$ACTIVE_LOG" >&2
  fail "$label did not reach its ready marker within 20 seconds"
}

release_probe() {
  local label="$1" status=0
  touch "$ACTIVE_RELEASE"
  set +e
  wait "$ACTIVE_PID"
  status=$?
  set -e
  ACTIVE_PID=''
  ACTIVE_RELEASE=''
  if [ "$status" -ne 0 ]; then
    tail -80 "$ACTIVE_LOG" >&2
    fail "$label failed with exit $status"
  fi
}

write_mdl_probe() {
  cat > "$R1/.wprism-database-boundary-mdl.php" <<'PHP'
<?php

use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;

$table = (string) getenv('WPRISM_LIVE_TABLE');
$ready = (string) getenv('WPRISM_LIVE_READY');
$release = (string) getenv('WPRISM_LIVE_RELEASE');
if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
    || !str_starts_with($ready, '/siterepo/')
    || !str_starts_with($release, '/siterepo/')) {
    throw new RuntimeException('invalid live metadata-lock fixture arguments');
}

ProviderDatabaseSession::read_only_snapshot(
    'live metadata-lock retention',
    NativeDatabaseProfile::read_only([$table]),
    static function () use ($ready, $release): void {
        if (file_put_contents($ready, "metadata-lock-held\n") === false) {
            throw new RuntimeException('could not publish metadata-lock ready marker');
        }
        $deadline = microtime(true) + 30.0;
        while (!is_file($release)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('metadata-lock holder timed out awaiting release');
            }
            usleep(100000);
        }
    }
);

echo "metadata-lock-released\n";
PHP
}

write_view_probe() {
  cat > "$R1/.wprism-database-boundary-view.php" <<'PHP'
<?php

use WPrism\DatabaseQueryIsolation;
use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;

$mode = (string) getenv('WPRISM_LIVE_MODE');
$table = (string) getenv('WPRISM_LIVE_TABLE');
$ready = (string) getenv('WPRISM_LIVE_READY');
$release = (string) getenv('WPRISM_LIVE_RELEASE');
if (!in_array($mode, ['control', 'guard'], true)
    || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
    || !str_starts_with($ready, '/siterepo/')
    || !str_starts_with($release, '/siterepo/')) {
    throw new RuntimeException('invalid live view fixture arguments');
}

if ($mode === 'control') {
    global $wpdb;
    $wpdb->last_error = '';
    $row = $wpdb->get_var("SELECT `id` FROM `$table` LIMIT 1");
    if ((string) $row !== '1' || trim((string) $wpdb->last_error) !== '') {
        throw new RuntimeException('definer-view control did not execute its one row');
    }
} else {
    $refused = false;
    try {
        ProviderDatabaseSession::read_only_snapshot(
            'live definer-view preflight',
            NativeDatabaseProfile::read_only([$table]),
            static function (): void {
                throw new RuntimeException('definer-view callback became reachable');
            }
        );
    } catch (Throwable $failure) {
        for ($cursor = $failure; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if (str_contains(
                $cursor->getMessage(),
                'is not the plain base table resolved by this session'
            )) {
                $refused = true;
                break;
            }
        }
        if (!$refused) {
            throw $failure;
        }
    }
    if (!$refused || DatabaseQueryIsolation::is_active()) {
        throw new RuntimeException('definer view was not refused with a settled query boundary');
    }
}

if (file_put_contents($ready, $mode . "-ready\n") === false) {
    throw new RuntimeException('could not publish definer-view ready marker');
}
$deadline = microtime(true) + 30.0;
while (!is_file($release)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('definer-view probe timed out awaiting release');
    }
    usleep(100000);
}

echo $mode . "-complete\n";
PHP
}

write_sequence_probe() {
  cat > "$R1/.wprism-database-boundary-sequence.php" <<'PHP'
<?php

use WPrism\DatabaseQueryIsolation;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;
use WPrism\ProviderSdk;

$sequence = (string) getenv('WPRISM_LIVE_SEQUENCE');
if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $sequence) !== 1) {
    throw new RuntimeException('invalid live sequence fixture argument');
}

foreach (['NEXT', 'PREVIOUS'] as $direction) {
    $refused = false;
    try {
        ProviderDatabaseSession::read_only_snapshot(
            'live sequence ' . strtolower($direction),
            NativeDatabaseProfile::read_only([]),
            static fn(): mixed => ProviderSdk::checked_get_var(
                "SELECT $direction VALUE FOR `$sequence`",
                'live sequence expression'
            )
        );
    } catch (DatabaseQueryIsolationViolationException $failure) {
        $refused = str_contains(
            $failure->getMessage(),
            'a sequence expression is outside the closed native database profile grammar'
        );
        if (!$refused) {
            throw $failure;
        }
    }
    if (!$refused || DatabaseQueryIsolation::is_active()) {
        throw new RuntimeException(
            strtolower($direction) . ' sequence expression was not refused with a settled query boundary'
        );
    }
    echo strtolower($direction) . "-refused\n";
}
PHP
}

write_schema_keyword_probe() {
  cat > "$R1/.wprism-database-boundary-schema-keyword.php" <<'PHP'
<?php

use WPrism\DatabaseQueryIsolation;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;
use WPrism\ProviderSdk;

global $wpdb;
$mode = (string) getenv('WPRISM_LIVE_MODE');
$ready = (string) getenv('WPRISM_LIVE_READY');
$release = (string) getenv('WPRISM_LIVE_RELEASE');
$schema = (string) ($wpdb->dbname ?? '');
if (!in_array($mode, ['control', 'guard'], true)
    || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $schema) !== 1
    || !str_starts_with($ready, '/siterepo/')
    || !str_starts_with($release, '/siterepo/')) {
    throw new RuntimeException('invalid live schema-keyword fixture arguments');
}
$query = "SELECT `$schema`.WHERE()";

if ($mode === 'control') {
    $wpdb->last_error = '';
    $result = $wpdb->get_var($query);
    if ((string) $result !== '1' || trim((string) $wpdb->last_error) !== '') {
        throw new RuntimeException('schema-qualified grammar-word function control did not execute');
    }
} else {
    $refused = false;
    try {
        ProviderDatabaseSession::read_only_snapshot(
            'live schema-qualified grammar-word function',
            NativeDatabaseProfile::read_only([]),
            static fn(): mixed => ProviderSdk::checked_get_var(
                $query,
                'live schema-qualified grammar-word function'
            )
        );
    } catch (DatabaseQueryIsolationViolationException $failure) {
        $refused = str_contains(
            $failure->getMessage(),
            'an unreviewed SQL function crossed the native database profile'
        );
        if (!$refused) {
            throw $failure;
        }
    }
    if (!$refused || DatabaseQueryIsolation::is_active()) {
        throw new RuntimeException('schema-qualified grammar-word function was not refused at a settled boundary');
    }
}

if (file_put_contents($ready, $mode . "-ready\n") === false) {
    throw new RuntimeException('could not publish schema-keyword ready marker');
}
$deadline = microtime(true) + 30.0;
while (!is_file($release)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('schema-keyword probe timed out awaiting release');
    }
    usleep(100000);
}

echo $mode . "-complete\n";
PHP
}

start_probe() {
  local mode="$1" fixture="$2" table="$3" ready="$4" release="$5" log="$6"
  rm -f -- "$ready" "$release" "$log"
  ACTIVE_RELEASE="$release"
  ACTIVE_LOG="$log"
  compose run --rm -T \
    -e "WPRISM_LIVE_MODE=$mode" \
    -e "WPRISM_LIVE_TABLE=$table" \
    -e "WPRISM_LIVE_READY=/siterepo/$(basename "$ready")" \
    -e "WPRISM_LIVE_RELEASE=/siterepo/$(basename "$release")" \
    cli1 wp eval-file "/siterepo/$fixture" >"$log" 2>&1 &
  ACTIVE_PID=$!
}

prove_metadata_lock() {
  local table='wp_wprism_live_mdl'
  local ready="$R1/.wprism-live-mdl-ready"
  local release="$R1/.wprism-live-mdl-release"
  local log="$TMP_ROOT/mdl-${CURRENT_DB_ENGINE}.log"
  local contender_output contender_status=0

  say "$CURRENT_DB_ENGINE: LIMIT 0 retains metadata lock until product rollback"
  db_query "DROP TABLE IF EXISTS \`$table\`; CREATE TABLE \`$table\` (\`id\` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB; INSERT INTO \`$table\` (\`id\`) VALUES (1)" >/dev/null
  write_mdl_probe
  start_probe holder '.wprism-database-boundary-mdl.php' "$table" "$ready" "$release" "$log"
  wait_for_probe "$CURRENT_DB_ENGINE metadata-lock holder" "$ready"

  set +e
  contender_output="$(wp1 db query \
    "SET SESSION lock_wait_timeout=1; ALTER TABLE \`$table\` ADD COLUMN \`blocked_until_rollback\` INT NULL" \
    2>&1)"
  contender_status=$?
  set -e
  [ "$contender_status" -ne 0 ] \
    || fail "$CURRENT_DB_ENGINE DDL crossed the live product snapshot before rollback"
  grep -Eqi 'lock wait timeout|1205' <<<"$contender_output" \
    || fail "$CURRENT_DB_ENGINE DDL failed outside the expected metadata-lock timeout: $contender_output"
  kill -0 "$ACTIVE_PID" 2>/dev/null \
    || fail "$CURRENT_DB_ENGINE metadata-lock holder exited before the contention proof completed"

  release_probe "$CURRENT_DB_ENGINE metadata-lock holder"
  wp1 db query "SET SESSION lock_wait_timeout=5; ALTER TABLE \`$table\` ADD COLUMN \`released_after_rollback\` INT NULL" >/dev/null
  [ "$(db_query "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='released_after_rollback'" | tr -d '[:space:]')" = 1 ] \
    || fail "$CURRENT_DB_ENGINE DDL did not succeed after the product snapshot rolled back"
  db_query "DROP TABLE \`$table\`" >/dev/null
  pass "$CURRENT_DB_ENGINE retained the LIMIT-0 metadata lock and released it only at rollback"
}

prove_view_preflight() {
  local source='wp_wprism_live_view_source'
  local function_name='wp_wprism_live_definer_touch'
  local view='wp_wprism_live_definer_view'
  local lock_name="wprism_${PAIR}_${CURRENT_DB_ENGINE}_definer"
  local ready release log used

  [ "${#lock_name}" -le 64 ] || fail "generated named-lock tripwire exceeds 64 bytes: $lock_name"
  say "$CURRENT_DB_ENGINE: base-table proof precedes view/definer execution"
  db_script >/dev/null <<SQL
DROP VIEW IF EXISTS \`$view\`;
DROP FUNCTION IF EXISTS \`$function_name\`;
DROP TABLE IF EXISTS \`$source\`;
CREATE TABLE \`$source\` (\`id\` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO \`$source\` (\`id\`) VALUES (1);
DELIMITER //
CREATE DEFINER=CURRENT_USER FUNCTION \`$function_name\`()
RETURNS INTEGER
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY DEFINER
RETURN GET_LOCK('$lock_name', 0)//
DELIMITER ;
CREATE SQL SECURITY DEFINER VIEW \`$view\` AS
SELECT \`id\` FROM \`$source\` WHERE \`$function_name\`() = 1;
SQL
  write_view_probe

  ready="$R1/.wprism-live-view-control-ready"
  release="$R1/.wprism-live-view-control-release"
  log="$TMP_ROOT/view-control-${CURRENT_DB_ENGINE}.log"
  start_probe control '.wprism-database-boundary-view.php' "$view" "$ready" "$release" "$log"
  wait_for_probe "$CURRENT_DB_ENGINE definer-view control" "$ready"
  used="$(db_query "SELECT COALESCE(IS_USED_LOCK('$lock_name'), 0)" | tr -d '[:space:]')"
  [ "$used" != 0 ] \
    || fail "$CURRENT_DB_ENGINE premise failed: selecting the view did not execute its definer function"
  release_probe "$CURRENT_DB_ENGINE definer-view control"
  [ "$(db_query "SELECT COALESCE(IS_USED_LOCK('$lock_name'), 0)" | tr -d '[:space:]')" = 0 ] \
    || fail "$CURRENT_DB_ENGINE control connection retained its named-lock tripwire after exit"

  ready="$R1/.wprism-live-view-guard-ready"
  release="$R1/.wprism-live-view-guard-release"
  log="$TMP_ROOT/view-guard-${CURRENT_DB_ENGINE}.log"
  start_probe guard '.wprism-database-boundary-view.php' "$view" "$ready" "$release" "$log"
  wait_for_probe "$CURRENT_DB_ENGINE definer-view product guard" "$ready"
  used="$(db_query "SELECT COALESCE(IS_USED_LOCK('$lock_name'), 0)" | tr -d '[:space:]')"
  [ "$used" = 0 ] \
    || fail "$CURRENT_DB_ENGINE product preflight executed the definer function before rejecting its view"
  release_probe "$CURRENT_DB_ENGINE definer-view product guard"

  db_query "DROP VIEW \`$view\`; DROP FUNCTION \`$function_name\`; DROP TABLE \`$source\`" >/dev/null
  pass "$CURRENT_DB_ENGINE rejected the executable view without invoking its definer function"
}

prove_schema_keyword_function() {
  local function_name='WHERE'
  local lock_name="wprism_${PAIR}_${CURRENT_DB_ENGINE}_keyword"
  local ready release log used

  [ "${#lock_name}" -le 64 ] || fail "generated schema-keyword lock tripwire exceeds 64 bytes: $lock_name"
  say "$CURRENT_DB_ENGINE: schema-qualified grammar-word routine refuses before transport"
  db_script >/dev/null <<SQL
DROP FUNCTION IF EXISTS \`$function_name\`;
DELIMITER //
CREATE DEFINER=CURRENT_USER FUNCTION \`$function_name\`()
RETURNS INTEGER
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY DEFINER
RETURN GET_LOCK('$lock_name', 0)//
DELIMITER ;
SQL
  write_schema_keyword_probe

  ready="$R1/.wprism-live-schema-keyword-control-ready"
  release="$R1/.wprism-live-schema-keyword-control-release"
  log="$TMP_ROOT/schema-keyword-control-${CURRENT_DB_ENGINE}.log"
  start_probe control '.wprism-database-boundary-schema-keyword.php' "$CURRENT_DB_NAME" "$ready" "$release" "$log"
  wait_for_probe "$CURRENT_DB_ENGINE schema-keyword control" "$ready"
  used="$(db_query "SELECT COALESCE(IS_USED_LOCK('$lock_name'), 0)" | tr -d '[:space:]')"
  [ "$used" != 0 ] \
    || fail "$CURRENT_DB_ENGINE premise failed: schema.WHERE() did not execute its named-lock side effect"
  release_probe "$CURRENT_DB_ENGINE schema-keyword control"
  [ "$(db_query "SELECT COALESCE(IS_USED_LOCK('$lock_name'), 0)" | tr -d '[:space:]')" = 0 ] \
    || fail "$CURRENT_DB_ENGINE schema-keyword control retained its named-lock tripwire after exit"

  ready="$R1/.wprism-live-schema-keyword-guard-ready"
  release="$R1/.wprism-live-schema-keyword-guard-release"
  log="$TMP_ROOT/schema-keyword-guard-${CURRENT_DB_ENGINE}.log"
  start_probe guard '.wprism-database-boundary-schema-keyword.php' "$CURRENT_DB_NAME" "$ready" "$release" "$log"
  wait_for_probe "$CURRENT_DB_ENGINE schema-keyword product guard" "$ready"
  used="$(db_query "SELECT COALESCE(IS_USED_LOCK('$lock_name'), 0)" | tr -d '[:space:]')"
  [ "$used" = 0 ] \
    || fail "$CURRENT_DB_ENGINE product grammar guard executed schema.WHERE() before refusal"
  release_probe "$CURRENT_DB_ENGINE schema-keyword product guard"

  db_query "DROP FUNCTION \`$function_name\`" >/dev/null
  pass "$CURRENT_DB_ENGINE refused schema.WHERE() before its observable routine side effect"
}

prove_mariadb_sequences() {
  local sequence='wp_wprism_live_sequence'
  local start_value='700001'
  local output first_after

  say 'MariaDB: NEXT/PREVIOUS VALUE FOR refuse before checked-read transport'
  db_query "DROP SEQUENCE IF EXISTS \`$sequence\`; CREATE SEQUENCE \`$sequence\` START WITH $start_value INCREMENT BY 1 NOCACHE" >/dev/null
  write_sequence_probe
  output="$(compose run --rm -T \
    -e "WPRISM_LIVE_SEQUENCE=$sequence" \
    cli1 wp eval-file /siterepo/.wprism-database-boundary-sequence.php 2>&1)" \
    || fail "MariaDB sequence product probe failed: $output"
  grep -Fxq 'next-refused' <<<"$output" \
    || fail "MariaDB NEXT VALUE FOR did not return the exact checked-read refusal marker: $output"
  grep -Fxq 'previous-refused' <<<"$output" \
    || fail "MariaDB PREVIOUS VALUE FOR did not return the exact checked-read refusal marker: $output"

  # This verification intentionally advances the sequence once, after the
  # product calls have returned. Receiving START proves the rejected NEXT was
  # never transported; a lexical-only assertion without this physical check
  # could pass while the sequence had already moved.
  first_after="$(db_query "SELECT NEXT VALUE FOR \`$sequence\`" | tr -d '[:space:]')"
  [ "$first_after" = "$start_value" ] \
    || fail "MariaDB sequence moved through the refused product path: first direct NEXT returned $first_after"
  db_query "DROP SEQUENCE \`$sequence\`" >/dev/null
  pass 'MariaDB refused NEXT/PREVIOUS through the real session path without moving the sequence'
}

start_pair() {
  local engine="$1" container="$2" client="$3" expected_label="$4" actual_label
  CURRENT_DB_ENGINE="$engine"
  CURRENT_DB_CONTAINER="$container"
  CURRENT_DB_CLIENT="$client"
  CURRENT_DB_NAME="wp_${PAIR}1"
  export WPRISM_DB_ENGINE="$engine"

  [ -z "$(docker ps -aq --filter "label=com.docker.compose.project=wprism-$PAIR")" ] \
    || fail "refusing to reuse pre-existing Compose project wprism-$PAIR"
  [ ! -e "$R1" ] && [ ! -e "$R2" ] && [ ! -e "$ORIGIN" ] \
    || fail "refusing to reuse pre-existing site-repository paths for pair $PAIR"
  SITE_ROOTS_OWNED=1
  PAIR_OWNED=1
  bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
  chmod 0777 "$R1"
  wp1 db query 'SELECT 1' >/dev/null \
    || fail "$CURRENT_DB_ENGINE pair did not complete a real WordPress database round trip"
  actual_label="$(wp1 eval 'echo (string) (\WPrism\PlatformCompatibility::current_facts()["database"]["engine"] ?? "");' \
    | awk 'NF { line=$0 } END { print line }')"
  [ "$actual_label" = "$expected_label" ] \
    || fail "$CURRENT_DB_ENGINE lane reached '$actual_label' instead of claimed engine '$expected_label'"
}

finish_pair() {
  destroy_owned_pair || fail "$CURRENT_DB_ENGINE pair cleanup failed"
  pass "$CURRENT_DB_ENGINE pair, databases, containers, volumes, and site roots were removed"
}

say 'pair-budget and exact-source preflight'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pass "live evidence is bound to clean source $SOURCE_SHA"

start_pair mariadb wprism-shared-db mariadb MariaDB
prove_metadata_lock
prove_view_preflight
prove_schema_keyword_function
prove_mariadb_sequences
finish_pair

start_pair mysql wprism-shared-mysql mysql MySQL
prove_metadata_lock
prove_view_preflight
prove_schema_keyword_function
finish_pair

cleanup_mysql_server || fail 'script-owned MySQL server cleanup failed'
MYSQL_SERVER_CLEANUP_COMPLETE=1

printf '\n\033[1;32m\u2714 REGRESS_DATABASE_BOUNDARY_LIVE PASSED\033[0m\n'
