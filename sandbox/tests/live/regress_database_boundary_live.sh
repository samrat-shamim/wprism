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
#   5. A multibyte client character set that can reinterpret 0x5c is refused
#      before even a zero-table provider callback receives control.
#   6. LIKE presence probes require wpdb::esc_like()'s exact spelling, and SHOW
#      database operands cannot borrow physical-table profile authority.
#   7. Large keyed strings preserve exact bytes through real wpdb field
#      validation, bounded chunks, complete readback and failed-batch rollback.
#
# This live, per-mechanism suite owns its one pair from creation through
# destruction and is intentionally outside regress-offline-all. Invoke it only
# from a clean committed candidate:
#
#   DATABASE_BOUNDARY_PAIR=<unique-name> DATABASE_BOUNDARY_PORT1=<even-port> \
#     DATABASE_BOUNDARY_PORT2=<successor> \
#     WPRISM_EXPECTED_SOURCE_SHA="$(git rev-parse HEAD)" \
#     make regress-database-boundary-live
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
note() { printf '\033[1;33mnote: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${DATABASE_BOUNDARY_PAIR:-}"
PORT1_RAW="${DATABASE_BOUNDARY_PORT1:-}"
PORT2_RAW="${DATABASE_BOUNDARY_PORT2:-}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"
SOURCE_SHA="$(git -C "$REPO_ROOT" --no-optional-locks rev-parse --verify 'HEAD^{commit}')" \
  || fail 'database-boundary evidence has no resolvable Git HEAD'

[[ "$PAIR" =~ ^[a-z][a-z0-9]{2,23}$ ]] \
  || fail 'DATABASE_BOUNDARY_PAIR is required and must be a unique lowercase 3..24 character pair name'
case "$PAIR" in
  db|sandbox) fail "DATABASE_BOUNDARY_PAIR '$PAIR' is reserved by the shared sandbox" ;;
esac
[[ "$PORT1_RAW" =~ ^[0-9]+$ && "$PORT2_RAW" =~ ^[0-9]+$ ]] \
  || fail 'DATABASE_BOUNDARY_PORT1 and DATABASE_BOUNDARY_PORT2 are required decimal ports'
PORT1=$((10#$PORT1_RAW)); PORT2=$((10#$PORT2_RAW))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail 'DATABASE_BOUNDARY_PORT1 must be even and >=8900; PORT2 must be its successor'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail 'WPRISM_EXPECTED_SOURCE_SHA must be the exact lowercase 40-character candidate SHA'
[ "$EXPECTED_SHA" = "$SOURCE_SHA" ] \
  || fail "WPRISM_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal checkout HEAD=$SOURCE_SHA"
SOURCE_STATUS="$(git -C "$REPO_ROOT" --no-optional-locks status --porcelain=v1 --untracked-files=all)" \
  || fail 'could not inspect database-boundary evidence source cleanliness'
[ -z "$SOURCE_STATUS" ] \
  || fail "database-boundary evidence requires a clean checkout at $SOURCE_SHA"
for command in docker git jq mktemp php; do
  command -v "$command" >/dev/null 2>&1 || fail "$command is required"
done

# WPRISM_SOURCE_ROOT is load-bearing in a linked worktree: pair.sh otherwise
# resolves the canonical checkout through Git's common directory. Binding it
# here makes the source mounted into WordPress the same clean commit that this
# exact script came from.
export WPRISM_SOURCE_ROOT="$REPO_ROOT"
export WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_CODEBIND_PLUGIN='' WPRISM_DB_ENGINE='mariadb' WPRISM_DB_HOST='wprism-shared-db'
# shellcheck source=../lib/pair_live_ownership.sh
. "$REPO_ROOT/sandbox/tests/lib/pair_live_ownership.sh"
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" \
  'database-boundary evidence' 'wprism-database-boundary'

R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"
TMP_ROOT="$PAIR_LIVE_OWNERSHIP_TMP_ROOT"
CURRENT_DB_ENGINE=''
CURRENT_DB_CONTAINER=''
CURRENT_DB_CLIENT=''
CURRENT_DB_NAME=''
ACTIVE_PID=''
ACTIVE_RELEASE=''
ACTIVE_LOG=''

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

pair_live_ownership_before_teardown() {
  settle_active_probe
}

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

write_session_grammar_probe() {
  cat > "$R1/.wprism-database-boundary-session-grammar.php" <<'PHP'
<?php

use WPrism\DatabaseQueryIsolation;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;

global $wpdb;
$callbackCalls = 0;
$wpdb->last_error = '';
$wpdb->query('SET SESSION character_set_client = gbk');
if (trim((string) $wpdb->last_error) !== '') {
    throw new RuntimeException('could not establish the unsafe client-character-set premise');
}
$characterSetFailure = null;
try {
    ProviderDatabaseSession::read_only_snapshot(
        'live unsafe character-set premise',
        NativeDatabaseProfile::read_only([]),
        static function () use (&$callbackCalls): void {
            $callbackCalls++;
        }
    );
} catch (Throwable $failure) {
    $characterSetFailure = $failure;
}
$wpdb->last_error = '';
$wpdb->query('SET SESSION character_set_client = utf8mb4');
if (trim((string) $wpdb->last_error) !== '') {
    throw new RuntimeException('could not restore the reviewed client character set');
}
$characterSetRefused = false;
for ($cursor = $characterSetFailure; $cursor !== null; $cursor = $cursor->getPrevious()) {
    if ($cursor instanceof DatabaseQueryIsolationViolationException
        && str_contains($cursor->getMessage(), 'character-set premise is incompatible')) {
        $characterSetRefused = true;
        break;
    }
}
if (!$characterSetRefused || $callbackCalls !== 0 || DatabaseQueryIsolation::is_active()) {
    throw new RuntimeException('unsafe client character set reached a provider callback or unsettled boundary');
}
echo "unsafe-character-set-refused\n";

$table = (string) $wpdb->options;
$database = (string) ($wpdb->dbname ?? '');
if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
    || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) !== 1) {
    throw new RuntimeException('invalid live SHOW grammar fixture identity');
}
$unsafeShows = [
    "SHOW TABLES LIKE '$table'",
    "SHOW TABLE STATUS LIKE '$table'",
    "SHOW TABLES FROM `$database`",
    "SHOW TRIGGERS FROM `$database`",
    "SHOW OPEN TABLES FROM `$database`",
];
foreach ($unsafeShows as $offset => $sql) {
    $failure = null;
    try {
        ProviderDatabaseSession::read_only_snapshot(
            'live unsafe SHOW grammar',
            NativeDatabaseProfile::read_only([$table]),
            static fn(): mixed => $wpdb->get_results($sql)
        );
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    $refused = false;
    for ($cursor = $failure; $cursor !== null; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof DatabaseQueryIsolationViolationException) {
            $refused = true;
            break;
        }
    }
    if (!$refused || DatabaseQueryIsolation::is_active()) {
        throw new RuntimeException('unsafe SHOW case ' . ($offset + 1) . ' crossed or unsettled the profile');
    }
}
echo "unsafe-show-forms-refused\n";

$exact = ProviderDatabaseSession::read_only_snapshot(
    'live exact LIKE presence',
    NativeDatabaseProfile::read_only([$table]),
    static fn(): mixed => $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
    ))
);
if ($exact !== $table || DatabaseQueryIsolation::is_active()) {
    throw new RuntimeException('escaped exact LIKE presence did not resolve through a settled profile');
}
echo "exact-like-presence-passed\n";
PHP
}

start_probe() {
  local mode="$1" fixture="$2" table="$3" ready="$4" release="$5" log="$6"
  rm -f -- "$ready" "$release" "$log"
  # The probe owns its diagnostic leaf, not the caller's repository mask.
  # Preallocation keeps native-query output at 0600 while leaving the direct
  # Compose child and its recorded PID/release ordering unchanged.
  (umask 077; set -C; : > "$log") \
    || fail 'could not allocate the private database-boundary probe transcript'
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

prove_session_grammar() {
  local output
  say "$CURRENT_DB_ENGINE: session lexer premises and exact SHOW grammar"
  write_session_grammar_probe
  output="$(compose run --rm -T \
    cli1 wp eval-file /siterepo/.wprism-database-boundary-session-grammar.php 2>&1)" \
    || fail "$CURRENT_DB_ENGINE session/SHOW product probe failed: $output"
  grep -Fxq 'unsafe-character-set-refused' <<<"$output" \
    || fail "$CURRENT_DB_ENGINE did not publish the unsafe-character-set refusal marker: $output"
  grep -Fxq 'unsafe-show-forms-refused' <<<"$output" \
    || fail "$CURRENT_DB_ENGINE did not publish the closed SHOW-grammar refusal marker: $output"
  grep -Fxq 'exact-like-presence-passed' <<<"$output" \
    || fail "$CURRENT_DB_ENGINE did not publish the exact LIKE control marker: $output"
  pass "$CURRENT_DB_ENGINE bound its byte lexer and SHOW authority to exact live session evidence"
}

prove_large_keyed_values() {
  local sink suffix status=0 expected_engine
  case "$CURRENT_DB_ENGINE" in mariadb) expected_engine=MariaDB ;; mysql) expected_engine=MySQL ;; esac
  say "$CURRENT_DB_ENGINE: bounded exact keyed values and swallowed-failure rollback"
  # Retain admitted records beyond pair teardown, using the shared transport
  # rather than a pass-marker grep that could hide native warnings or truncation.
  sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/keyed-values-$CURRENT_DB_ENGINE.XXXXXX")
  for suffix in stdout stderr exit; do (umask 077; set -C; : >"$sink/native.$suffix"); done
  . "$REPO_ROOT/sandbox/tests/lib/private_command_capture.sh"
  wprism_private_capture_stage "$sink" native compose run --rm -T \
    -v "$REPO_ROOT/sandbox/tests/fixtures/ledger-large-values.php:/keyed-values.php:ro" \
    cli1 wp eval-file /keyed-values.php --use-include || status=$?
  printf 'retained keyed-value transport: %s\n' "$sink/native"
  [ "$status" -eq 0 ] || fail "$CURRENT_DB_ENGINE keyed-value command failed with exit $status"
  php "$REPO_ROOT/sandbox/tests/fixtures/ledger-large-values.php" --admit "$sink/native" "$expected_engine"
  pass "$CURRENT_DB_ENGINE preserved complete keyed values, refusal preimages and fresh retry"
}

start_pair() {
  local engine="$1" client="$2" expected_label="$3" actual_label
  CURRENT_DB_ENGINE="$engine"
  CURRENT_DB_CLIENT="$client"
  CURRENT_DB_NAME="wp_${PAIR}1"
  pair_live_ownership_acquire "$engine"
  CURRENT_DB_CONTAINER="$PAIR_LIVE_OWNERSHIP_CONTAINER"
  pair_live_ownership_up --headless
  chmod 0777 "$R1"
  wp1 db query 'SELECT 1' >/dev/null \
    || fail "$CURRENT_DB_ENGINE pair did not complete a real WordPress database round trip"
  actual_label="$(wp1 eval 'echo (string) (\WPrism\PlatformCompatibility::current_facts()["database"]["engine"] ?? "");' \
    | awk 'NF { line=$0 } END { print line }')"
  [ "$actual_label" = "$expected_label" ] \
    || fail "$CURRENT_DB_ENGINE lane reached '$actual_label' instead of claimed engine '$expected_label'"
}

finish_pair() {
  pair_live_ownership_finish_leg
  pass "$CURRENT_DB_ENGINE pair, databases, containers, volumes, site roots, and engine-bound lease were removed"
}

say 'pair-budget and exact-source preflight'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pass "live evidence is bound to clean source $SOURCE_SHA"

start_pair mariadb mariadb MariaDB
prove_metadata_lock
prove_view_preflight
prove_schema_keyword_function
prove_mariadb_sequences
prove_session_grammar
prove_large_keyed_values
finish_pair

start_pair mysql mysql MySQL
prove_metadata_lock
prove_view_preflight
prove_schema_keyword_function
prove_session_grammar
prove_large_keyed_values
finish_pair

# The MySQL container is shared infrastructure, not this script's resource.
# Stopping it after a point-in-time consumer scan races another worktree's pair
# admission. Pair destruction above removes only this script's schemas/network;
# leave the shared service lifecycle to pair.sh's cross-worktree owner.
note 'leaving the shared MySQL service lifecycle unchanged after pair cleanup'

pair_live_ownership_complete '✔ REGRESS_DATABASE_BOUNDARY_LIVE PASSED'
