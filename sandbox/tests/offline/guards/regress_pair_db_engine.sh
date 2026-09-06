#!/usr/bin/env bash
# Offline regression — the MySQL evidence lane's engine selection.
#
# The lane adds a SECOND shared database server (sandbox/db.mysql.yml, project
# wprism-db-mysql, container wprism-shared-mysql) beside sandbox/db.yml's MariaDB,
# and one variable, WPRISM_DB_ENGINE, choosing between them. Everything that
# choice touches is shell that executes nowhere offline
# (docs/agents/linear-loop.md's §Evidence scoping: an edit under sandbox/bin or
# sandbox/lib proves nothing until something drives it), so this suite drives
# the same shipped logic with simulated input and a fake docker, never a
# daemon.
#
# The load-bearing assertion is the DEFAULT path, not the MySQL one: pair.sh's
# four shared-db constants became a function call, and the whole change is only
# admissible if an unset WPRISM_DB_ENGINE reproduces the previous values and
# pair_db_sql()'s argv byte-for-byte (AGENTS.md rule 8). The MySQL cases prove
# the lane resolves at all; the refusal case proves a typo cannot silently
# produce MariaDB evidence an operator would record as MySQL.
#
# Selecting the MySQL engine still CLAIMS NOTHING about MySQL support, and this
# suite still deliberately asserts nothing about the shipped contract -- its
# subject is the harness, not the claim. What changed underneath it: that
# contract's database axis is now an engine-keyed map naming MySQL 8.4 beside
# MariaDB 11 (agent/src/Policy/PlatformCompatibility.php's
# valid_database_axis()), with a PENDING live proof, so a pair on that server
# is no longer answered by platform_database_engine_unsupported. The
# assertions below are unchanged by that on purpose: a harness suite that
# tracked the claim would fail every time the claim moved, for no reason
# connected to what it tests.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-pair-db-engine.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

DB_LIB="$ROOT/sandbox/lib/pair_db.sh"
IDENTITY_LIB="$ROOT/sandbox/lib/pair_identity.sh"
COMPOSE_LIB="$ROOT/sandbox/lib/pair_compose.sh"
PAIR_SH="$ROOT/sandbox/bin/pair.sh"
PAIR_YML="$ROOT/sandbox/pair.yml"
DB_MYSQL_YML="$ROOT/sandbox/db.mysql.yml"
DB_SCOPE_LIVE="$ROOT/sandbox/tests/live/regress_core_scope_database.sh"

for f in "$DB_LIB" "$IDENTITY_LIB" "$COMPOSE_LIB" "$PAIR_SH" "$PAIR_YML" "$DB_MYSQL_YML" "$DB_SCOPE_LIVE"; do
  [ -r "$f" ] || fail "missing file this suite is about: $f"
done
for f in "$DB_LIB" "$IDENTITY_LIB" "$COMPOSE_LIB" "$PAIR_SH"; do
  bash -n "$f" || fail "does not parse: $f"
done

# A fake `docker` on PATH that RECORDS every invocation. Recording, not just
# refusing, is the point: the refusal case below asserts the log is still
# empty, which is how "the engine refusal happens before any container work"
# is proven rather than assumed.
FAKE_BIN="$TMP/bin"
DOCKER_LOG="$TMP/docker.log"
CONTEXT_LOG="$TMP/context.log"
SQL_LOG="$TMP/sql.log"
mkdir -p "$FAKE_BIN"
: > "$DOCKER_LOG"
: > "$SQL_LOG"
cat > "$FAKE_BIN/docker" <<FAKE_DOCKER
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "\$*" >> "$DOCKER_LOG"
printf '%s\t%s\n' "\$*" "\${WPRISM_DB_HOST:-unset}" >> "$CONTEXT_LOG"
# Running the copied launcher from a real throwaway worktree deliberately
# activates its shared budget/lease boundary before stop. Supply exact empty
# Compose state and finite host capacity so this fixture reaches the engine
# propagation call it owns, while every unrecognized Docker call still fails.
if [ "\$*" = "compose ls --format json" ]; then
  printf '[]\n'
  exit 0
fi
if [ "\$*" = "info -f {{.NCPU}}" ]; then
  printf '8\n'
  exit 0
fi
if [ "\$*" = "info -f {{.MemTotal}}" ]; then
  printf '17179869184\n'
  exit 0
fi
# Beyond those exact budget probes, admin SQL is the only invocation this
# suite lets succeed; anything else is a sentinel so an unexpected escape to
# a real daemon is loud, not skipped.
if [ "\${1:-}" = exec ]; then
  sql="\$(cat 2>/dev/null || true)"
  printf '%s\n' "\$sql" >> "$SQL_LOG"
  if grep -Fq '__WPRISM_PAIR_SCHEMA_COUNT__' <<<"\$sql"; then
    [ "\${WPRISM_PAIR_TEST_SCHEMA_NO_WITNESS:-0}" = 0 ] || exit 0
    rows="\${WPRISM_PAIR_TEST_SCHEMA_ROWS:-}"
    count="\$(printf '%s\n' "\$rows" | awk 'NF { count++ } END { print count + 0 }')"
    printf 'wprism_pair_schema_count\n__WPRISM_PAIR_SCHEMA_COUNT__:%s\n' "\$count"
    if [ "\$count" -gt 0 ]; then
      printf 'wprism_pair_schema_found\n'
      while IFS= read -r schema; do
        [ -n "\$schema" ] && printf '__WPRISM_PAIR_SCHEMA_FOUND__:%s\n' "\$schema"
      done <<<"\$rows"
    fi
  fi
  exit 0
fi
printf 'FAKE-DOCKER-SENTINEL: %s\n' "\$*" >&2
exit 42
FAKE_DOCKER
chmod +x "$FAKE_BIN/docker"
export PATH="$FAKE_BIN:$PATH"

# shellcheck source=../../../lib/pair_db.sh
source "$DB_LIB"

DB_ROOT_USER=root
DB_ROOT_PASS=root

# assert_engine <label> <expected-container> <expected-client> <expected-compose-argv> <expected-label>
assert_engine() {
  local label="$1" container="$2" client="$3" compose="$4" dblabel="$5"
  [ "$DB_CONTAINER" = "$container" ] || fail "$label: DB_CONTAINER is '$DB_CONTAINER', expected '$container'"
  [ "$DB_CLIENT" = "$client" ] || fail "$label: DB_CLIENT is '$DB_CLIENT', expected '$client'"
  [ "${DB_COMPOSE[*]}" = "$compose" ] || fail "$label: DB_COMPOSE is '${DB_COMPOSE[*]}', expected '$compose'"
  [ "$DB_LABEL" = "$dblabel" ] || fail "$label: DB_LABEL is '$DB_LABEL', expected '$dblabel'"
}

say "WPRISM_DB_ENGINE unset resolves to exactly the values pair.sh hard-coded before this lane existed"
unset WPRISM_DB_ENGINE
unset DB_CONTAINER DB_CLIENT DB_LABEL || true
pair_db_select_engine
assert_engine "default" wprism-shared-db mariadb "docker compose -p wprism-db -f db.yml" 'MariaDB (wprism-db)'
# The DB_LABEL value is asserted as a literal because cmd_up prints it into
# "shared infra: <label> + wprism-shared network" -- the string that existed
# before DB_LABEL did.
pass "default: wprism-shared-db / mariadb / -p wprism-db -f db.yml / 'MariaDB (wprism-db)'"

say "the default path's admin-SQL argv is byte-identical to the pre-lane invocation"
: > "$DOCKER_LOG"
pair_db_sql </dev/null
got="$(cat "$DOCKER_LOG")"
expected='exec -i -e MYSQL_PWD=root wprism-shared-db mariadb -uroot'
[ "$got" = "$expected" ] || fail "pair_db_sql argv moved: got '$got', expected '$expected'"
pass "pair_db_sql still runs: docker $expected"

say "shared authentication stays global while application authority is exact per schema"
: > "$SQL_LOG"
pair_db_ensure_app_user
grep -Fqx "GRANT PROCESS ON *.* TO 'wordpress'@'%';" "$SQL_LOG" \
  || fail "MariaDB application principal lacks the PROCESS authority required by the FK census: $(cat "$SQL_LOG")"
if grep -Fq 'GRANT ALL PRIVILEGES ON `wp\_%`.*' "$SQL_LOG"; then
  fail "principal setup must not retain wildcard database authority: $(cat "$SQL_LOG")"
fi
: > "$SQL_LOG"
pair_db_create fixture
grep -Fqx "GRANT ALL PRIVILEGES ON wp_fixture1.* TO 'wordpress'@'%';" "$SQL_LOG" \
  || fail "pair database 1 lacks its exact all-privileges grant: $(cat "$SQL_LOG")"
grep -Fqx "GRANT ALL PRIVILEGES ON wp_fixture2.* TO 'wordpress'@'%';" "$SQL_LOG" \
  || fail "pair database 2 lacks its exact all-privileges grant: $(cat "$SQL_LOG")"
if grep -Fq 'GRANT TRIGGER ON ' "$SQL_LOG"; then
  fail "a narrower exact TRIGGER row would shadow ordinary wildcard privileges on MariaDB: $(cat "$SQL_LOG")"
fi
pass "each concrete schema receives normal WordPress authority and directly observable TRIGGER authority in one row"

say "lease admission proves both exact pair schemas absent through the selected engine"
: > "$SQL_LOG"
unset WPRISM_PAIR_TEST_SCHEMA_ROWS
pair_db_assert_pair_schemas_absent leaseprobe
grep -Fq "WHERE SCHEMA_NAME IN ('wp_leaseprobe1','wp_leaseprobe2');" "$SQL_LOG" \
  || fail "database absence census did not query both exact schemas: $(cat "$SQL_LOG")"
if grep -Fq "wp_leaseprobe%" "$SQL_LOG"; then
  fail "database absence census widened exact pair authority to a pattern: $(cat "$SQL_LOG")"
fi
pass "an empty information-schema census admits only the two exact schema names"

for orphan in wp_leaseprobe1 wp_leaseprobe2; do
  export WPRISM_PAIR_TEST_SCHEMA_ROWS="$orphan"
  err="$TMP/schema-$orphan.txt"
  if (pair_db_assert_pair_schemas_absent leaseprobe) > /dev/null 2>"$err"; then
    fail "database absence census admitted orphan schema $orphan"
  fi
  grep -Fq "pair database schema already exists ($orphan); pair namespace is not empty" "$err" \
    || fail "orphan schema $orphan produced the wrong refusal: $(cat "$err")"
done
unset WPRISM_PAIR_TEST_SCHEMA_ROWS
pass "either side's orphan schema refuses before a lease can own its destructive cleanup"

say "database census requires an explicit, unique empty-result witness"
err="$TMP/schema-census-transport.txt"
if (WPRISM_PAIR_TEST_SCHEMA_NO_WITNESS=1 pair_db_assert_pair_schemas_absent leaseprobe) > /dev/null 2>"$err"; then
  fail "database absence census treated missing output as an empty namespace"
fi
grep -Fq 'pair database absence census returned no unique count witness' "$err" \
  || fail "missing census witness produced the wrong refusal: $(cat "$err")"
if (pair_db_assert_pair_schemas_absent 'lease-probe') > /dev/null 2>"$err"; then
  fail "database absence census accepted an unsafe pair name"
fi
grep -Fq "pair database absence census received unsafe pair name 'lease-probe'" "$err" \
  || fail "unsafe schema-census name produced the wrong refusal: $(cat "$err")"
pass "missing census output refuses, and the DB transport independently retains the pair-name grammar"

say "every database-creating command establishes the shared principal before its first grant"
for creator in cmd_up cmd_reset; do
  body=$(awk -v fn="$creator" '
    $0 ~ "^" fn "\\(\\)" { inside=1 }
    inside { print }
    inside && /^}/ { exit }
  ' "$PAIR_SH")
  principal_line=$(grep -n 'pair_db_ensure_app_user' <<<"$body" | head -1 | cut -d: -f1)
  create_line=$(grep -n 'pair_db_create "\$name"' <<<"$body" | head -1 | cut -d: -f1)
  [[ "$principal_line" =~ ^[0-9]+$ && "$create_line" =~ ^[0-9]+$ && "$principal_line" -lt "$create_line" ]] \
    || fail "$creator must establish the wordpress principal before pair_db_create: $body"
done
pass "up and reset cannot grant an absent principal or leave fresh-host conformance order-dependent"

say "an explicit WPRISM_DB_ENGINE=mariadb is identical to leaving it unset"
export WPRISM_DB_ENGINE=mariadb
pair_db_select_engine
assert_engine "explicit mariadb" wprism-shared-db mariadb "docker compose -p wprism-db -f db.yml" 'MariaDB (wprism-db)'
pass "naming the default engine explicitly changes nothing"

say "WPRISM_DB_ENGINE=mysql resolves to the parallel evidence-lane server"
export WPRISM_DB_ENGINE=mysql
pair_db_select_engine
assert_engine "mysql" wprism-shared-mysql mysql "docker compose -p wprism-db-mysql -f db.mysql.yml" 'MySQL (wprism-db-mysql)'
# Separate compose project and separate container name: db.yml's MariaDB is
# fleet-shared (db.yml:4-18), so the second engine must never be able to
# recreate or replace it.
[ "$DB_CONTAINER" != wprism-shared-db ] || fail "the mysql lane must not target db.yml's container"
pass "mysql: wprism-shared-mysql / mysql / -p wprism-db-mysql -f db.mysql.yml"

say "the mysql lane's admin SQL runs the client binary that image actually ships"
: > "$DOCKER_LOG"
pair_db_sql </dev/null
got="$(cat "$DOCKER_LOG")"
expected='exec -i -e MYSQL_PWD=root wprism-shared-mysql mysql -uroot'
[ "$got" = "$expected" ] || fail "mysql-lane pair_db_sql argv: got '$got', expected '$expected'"
# mysql:8.4 has no `mariadb` binary and mariadb:11 has no `mysql` binary
# (pair_db_sql's own comment) -- getting this wrong fails at exec time, on a
# live server, after the pair is already half up.
pass "pair_db_sql runs: docker $expected"

say "the MySQL principal path also defers database authority to exact pair schemas"
: > "$SQL_LOG"
pair_db_ensure_app_user
grep -Fqx "ALTER USER 'wordpress'@'%' IDENTIFIED WITH mysql_native_password BY 'wordpress';" "$SQL_LOG" \
  || fail "MySQL principal setup lost its client-compatible authentication convergence: $(cat "$SQL_LOG")"
grep -Fqx "GRANT PROCESS ON *.* TO 'wordpress'@'%';" "$SQL_LOG" \
  || fail "MySQL principal setup lacks PROCESS authority: $(cat "$SQL_LOG")"
if grep -Fq 'GRANT ALL PRIVILEGES ON `wp\_%`.*' "$SQL_LOG"; then
  fail "MySQL principal setup must not retain wildcard database authority: $(cat "$SQL_LOG")"
fi
pass "MySQL authentication and FK-census authority remain explicit; schema access is pair-owned"

say "an unknown engine refuses by name, before any docker invocation"
: > "$DOCKER_LOG"
err="$TMP/refusal.txt"
if (export WPRISM_DB_ENGINE=postgres; pair_db_select_engine) >/dev/null 2>"$err"; then
  fail "an unrecognised WPRISM_DB_ENGINE must refuse, not fall back to the default"
fi
grep -Fq "unknown WPRISM_DB_ENGINE 'postgres' -- supported engines are 'mariadb' (default) and 'mysql'" "$err" \
  || fail "refusal message is not the named one: $(cat "$err")"
[ ! -s "$DOCKER_LOG" ] || fail "the refusal must precede every container call; docker was invoked: $(cat "$DOCKER_LOG")"
pass "postgres refuses by name and touches no container"

say "the shipped pair.sh calls the selector at load, so the refusal precedes every subcommand"
: > "$DOCKER_LOG"
out="$TMP/pair-sh.txt"
if env WPRISM_DB_ENGINE=bogus bash "$PAIR_SH" list >"$out" 2>&1; then
  fail "pair.sh list with a bogus engine must exit non-zero; output: $(cat "$out")"
fi
grep -Fq "unknown WPRISM_DB_ENGINE 'bogus'" "$out" \
  || fail "pair.sh did not refuse by name (is pair_db_select_engine actually called?): $(cat "$out")"
[ ! -s "$DOCKER_LOG" ] || fail "pair.sh reached docker despite an unknown engine: $(cat "$DOCKER_LOG")"
pass "pair.sh refuses at load: no subcommand, no docker, no pair mutation"
unset WPRISM_DB_ENGINE

say "a non-up subcommand keeps its selected host local and removes legacy shared database authority"
# Every subcommand must retain the selected tuple, not just up. Publication
# must also remove the former shared DB host: retaining it would still reroute
# a concurrent legacy MariaDB parent that deliberately selects no engine.
# Driven through the shipped pair.sh, not by re-implementing its wiring: a
# symlinked sandbox/ (pair.sh:62 does `cd "$(dirname "$0")/.."`, which follows
# the invoked path, not the link target) gives the run a real lib/ and a
# throwaway .env, so this suite never touches the worktree's own sandbox/.env.
SUBCMD_ROOT="$TMP/subcmd"
mkdir -p "$SUBCMD_ROOT/sandbox/bin" \
  "$SUBCMD_ROOT/agent" "$SUBCMD_ROOT/adapter-packages" "$SUBCMD_ROOT/platform"
SUBCMD_ROOT="$(cd "$SUBCMD_ROOT" && pwd -P)"
git -C "$SUBCMD_ROOT" init -q
ln -s "$PAIR_SH" "$SUBCMD_ROOT/sandbox/bin/pair.sh"
ln -s "$ROOT/sandbox/lib" "$SUBCMD_ROOT/sandbox/lib"
: > "$DOCKER_LOG"
# The throwaway repository keeps this copied-launcher fixture inside the same
# physical-worktree contract that pair_identity_export_source_mounts() now
# revalidates. The fake docker makes the compose call itself fail, which is
# fine -- .env is written before that call.
stop_output="$TMP/stop-mysql.txt"
if (
  export PAIR_SOURCE_ROOT="$SUBCMD_ROOT" WPRISM_DB_ENGINE=mysql
  bash "$SUBCMD_ROOT/sandbox/bin/pair.sh" stop probe
) >"$stop_output" 2>&1; then
  fail "the fake docker should have made 'pair.sh stop' fail; it did not run compose at all"
fi
grep -Fq 'compose -p wprism-probe -f pair.yml stop' "$DOCKER_LOG" \
  || fail "pair.sh stop did not reach its compose call: $(cat "$DOCKER_LOG"); output: $(cat "$stop_output")"
grep -Fqx $'compose -p wprism-probe -f pair.yml stop\twprism-shared-mysql' "$CONTEXT_LOG" \
  || fail "stop on the mysql lane lost its process-local engine: $(cat "$CONTEXT_LOG")"
if grep -q '^WPRISM_DB_' "$SUBCMD_ROOT/sandbox/.env"; then
  fail "stop must not publish database authority to shared .env: $(cat "$SUBCMD_ROOT/sandbox/.env")"
fi
# Default-engine children still receive the immutable MariaDB host.
: > "$DOCKER_LOG"
(
  export PAIR_SOURCE_ROOT="$SUBCMD_ROOT"
  unset WPRISM_DB_ENGINE
  bash "$SUBCMD_ROOT/sandbox/bin/pair.sh" stop probe
) >/dev/null 2>&1 || true
grep -Fqx $'compose -p wprism-probe -f pair.yml stop\twprism-shared-db' "$CONTEXT_LOG" \
  || fail "default-engine stop must retain wprism-shared-db: $(cat "$CONTEXT_LOG")"
pass "stop carries the selected engine; the default path is unchanged"

say "pair.yml's WORDPRESS_DB_HOST is the defaulted variable, and its default is db.yml's container"
line="$(grep -n 'WORDPRESS_DB_HOST' "$PAIR_YML" | grep -v '^[0-9]*:#')"
[ "$(printf '%s\n' "$line" | wc -l | tr -d ' ')" = 1 ] \
  || fail "expected exactly one non-comment WORDPRESS_DB_HOST line in pair.yml, got: $line"
value="${line#*WORDPRESS_DB_HOST: }"
[ "$value" = '${WPRISM_DB_HOST:-wprism-shared-db}' ] \
  || fail "pair.yml's WORDPRESS_DB_HOST must be '\${WPRISM_DB_HOST:-wprism-shared-db}', got '$value'"
# Compose's ${VAR:-default} substitution has the same semantics bash does here,
# so expanding it in bash is a faithful check of what compose renders without
# needing a daemon (`docker compose config` is deliberately left to the lead --
# an offline suite must not require Docker).
rendered="$(unset WPRISM_DB_HOST; eval "printf '%s' \"$value\"")"
[ "$rendered" = wprism-shared-db ] || fail "with WPRISM_DB_HOST unset pair.yml must render wprism-shared-db, got '$rendered'"
rendered="$(WPRISM_DB_HOST=wprism-shared-mysql; eval "printf '%s' \"$value\"")"
[ "$rendered" = wprism-shared-mysql ] || fail "with WPRISM_DB_HOST set pair.yml must render it, got '$rendered'"
pass "unset renders wprism-shared-db (byte-identical to the pre-lane literal); set renders the mysql server"

say "pair_compose_configure publishes only source roots, never a database selection"
# shellcheck source=../../../lib/pair_identity.sh
source "$IDENTITY_LIB"
# shellcheck source=../../../lib/pair_compose.sh
source "$COMPOSE_LIB"
ENV_CWD="$SUBCMD_ROOT/envcwd"
mkdir -p "$ENV_CWD"
(
  cd "$ENV_CWD"
  PAIR_SOURCE_ROOT="$SUBCMD_ROOT"
  unset WPRISM_DB_HOST
  pair_compose_configure probe
)
if grep -q '^WPRISM_DB_' "$ENV_CWD/.env"; then
  fail ".env must not carry database authority: $(cat "$ENV_CWD/.env")"
fi
grep -Fqx "WPRISM_AGENT_SRC=$SUBCMD_ROOT/agent" "$ENV_CWD/.env" \
  || fail ".env lost WPRISM_AGENT_SRC: $(cat "$ENV_CWD/.env")"
grep -Fqx "WPRISM_ADAPTER_PACKAGES_SRC=$SUBCMD_ROOT/adapter-packages" "$ENV_CWD/.env" \
  || fail ".env lost WPRISM_ADAPTER_PACKAGES_SRC: $(cat "$ENV_CWD/.env")"
grep -Fqx "WPRISM_PLATFORM_SRC=$SUBCMD_ROOT/platform" "$ENV_CWD/.env" \
  || fail ".env lost WPRISM_PLATFORM_SRC: $(cat "$ENV_CWD/.env")"
(
  cd "$ENV_CWD"
  PAIR_SOURCE_ROOT="$SUBCMD_ROOT"
  # A pre-fix checkout left this value behind; every publication must remove
  # it, not merely stop adding new entries while preserving the old one.
  printf 'WPRISM_DB_HOST=wprism-shared-mysql\n' >> .env
  export WPRISM_DB_HOST=wprism-shared-mysql
  pair_compose_configure probe
)
if grep -q '^WPRISM_DB_' "$ENV_CWD/.env"; then
  fail "MySQL publication retained or added shared database authority: $(cat "$ENV_CWD/.env")"
fi
[ "$(wc -l < "$ENV_CWD/.env" | tr -d ' ')" = 3 ] \
  || fail ".env must be exactly three source lines (overwritten, never appended): $(cat "$ENV_CWD/.env")"
legacy_host="$(cd "$ENV_CWD" && bash -c 'unset WPRISM_DB_HOST WPRISM_DB_ENGINE; . ./.env; printf "%s" "${WPRISM_DB_HOST:-wprism-shared-db}"')"
[ "$legacy_host" = wprism-shared-db ] \
  || fail "a legacy default parent was rerouted by MySQL publication: $legacy_host"
pass ".env carries only three source roots; a MySQL publisher cannot reroute legacy default parents"

say "parent engine selection survives another pair rewriting shared .env in both directions"
# Drive the selector and Compose publisher in separate process contexts. A
# child's export cannot flow back to its parent; this is the same boundary
# conformance crosses between pair.sh and direct/host-orchestrated WP calls.
for selected in mariadb mysql; do
  if [ "$selected" = mariadb ]; then
    wanted=wprism-shared-db
    other=mysql
  else
    wanted=wprism-shared-mysql
    other=mariadb
  fi
  (
    cd "$ENV_CWD"
    export PAIR_SOURCE_ROOT="$SUBCMD_ROOT"
    unset WPRISM_DB_ENGINE WPRISM_DB_HOST
    # Deliberately a shell-only selection: the shared selector must retain
    # both the default/explicit engine and its derived host for descendants.
    [ "$selected" = mariadb ] || WPRISM_DB_ENGINE="$selected"
    pair_db_select_engine
    (
      export WPRISM_DB_ENGINE="$other"
      pair_db_select_engine
      export WPRISM_DB_HOST="$DB_CONTAINER"
      pair_compose_configure neighbor
    )
    observed="$(bash -c '
      # Compose environment values outrank .env, including for a fresh host
      # CLI grandchild. Source only a missing value to model that precedence.
      selected_engine="${WPRISM_DB_ENGINE:-unset}"
      selected_host="${WPRISM_DB_HOST:-}"
      . ./.env
      export WPRISM_DB_HOST="${selected_host:-${WPRISM_DB_HOST:-wprism-shared-db}}"
      printf "%s\\t%s\\n" "$selected_engine" "$WPRISM_DB_HOST"
      bash -c '\''printf "%s\\n" "$WPRISM_DB_HOST"'\''
    ')"
    expected="$(printf '%s\t%s\n%s' "$selected" "$wanted" "$wanted")"
    [ "$observed" = "$expected" ] \
      || fail "$selected parent lost its engine/host after $other publication: $observed"
  )
done
pass "default MariaDB and explicit MySQL remain exact through fresh children and host grandchildren"

say "candidate harnesses select their database in the parent before pair mutation"
for caller in \
  sandbox/conformance/run.sh \
  sandbox/tests/certify/certify_version_matrix.sh \
  adapter-packages/woocommerce/tests/live/regress_woocommerce_multisite_refusal.sh \
  integration-scenarios/woocommerce-rewrite-coinstall/tests/live/regress_woocommerce_rewrite_coinstall.sh; do
  source_line="$(grep -nFx '. lib/pair_db.sh' "$ROOT/$caller" | cut -d: -f1)"
  select_line="$(grep -nFx 'pair_db_select_engine' "$ROOT/$caller" | cut -d: -f1)"
  mutation_line="$(grep -nE '^[[:space:]]*bash bin/pair.sh (reset|up)' "$ROOT/$caller" | head -1 | cut -d: -f1)"
  [ -n "$source_line" ] && [ -n "$select_line" ] && [ -n "$mutation_line" ] \
    && [ "$source_line" -lt "$select_line" ] && [ "$select_line" -lt "$mutation_line" ] \
    || fail "$caller must pin the selected database in its parent before pair mutation"
done
pass "conformance, version certification, and candidate WooCommerce evidence retain parent database authority"

say "db.mysql.yml is a parallel project attached to db.yml's network, with a real-query healthcheck"
python3 - "$DB_MYSQL_YML" <<'PY'
import sys
# PyYAML is not a declared dependency of this estate (no other offline suite
# imports it), so its absence must not silently weaken this check: the literal
# floor below runs unconditionally and asserts the same facts against the raw
# bytes; the structural parse runs on top of it when the module happens to be
# installed.
raw = open(sys.argv[1], encoding='utf-8').read()
floor = [
    'name: wprism-db-mysql',
    '  wprism-shared:',
    '    name: wprism-shared',
    '    external: true',
    '  wprism-db-mysql-data: {}',
    '    image: mysql:8.4',
    '    container_name: wprism-shared-mysql',
    '      MYSQL_ROOT_PASSWORD: root',
    '      - "127.0.0.1:3326:3306"',
    '      - wprism-db-mysql-data:/var/lib/mysql',
    '      test: ["CMD", "mysql", "-uroot", "-proot", "-e", "SELECT 1"]',
]
for want in floor:
    if want not in raw.splitlines():
        sys.exit("db.mysql.yml is missing the exact line %r" % want)
for forbidden, why in (
    ('healthcheck.sh', 'ships only in MariaDB images (db.yml:68); a missing healthcheck '
                       'binary never reports healthy, so pair_db_ensure_up would poll 120s and refuse'),
    ('mysqladmin', 'ping answers before the server serves real queries -- the exact '
                   'fake-readiness gap db.yml:61-66 records'),
    ('wprism-shared-db', 'this file must never name db.yml\'s fleet-shared container'),
    ('wprism-db-data', 'this file must never mount db.yml\'s volume'),
):
    for line in raw.splitlines():
        stripped = line.strip()
        if stripped.startswith('#'):
            continue
        if forbidden in stripped:
            sys.exit("db.mysql.yml names %r outside a comment: %s" % (forbidden, why))
try:
    import yaml
except ImportError:
    print("  (PyYAML absent: literal-line floor asserted, structural parse skipped)")
    sys.exit(0)
doc = yaml.safe_load(raw)
def bad(msg):
    sys.exit("db.mysql.yml: " + msg)
if doc.get('name') != 'wprism-db-mysql':
    bad("top-level name must be wprism-db-mysql (its own compose project, so db.yml's "
        "fleet-shared MariaDB is never in its blast radius); got %r" % doc.get('name'))
net = (doc.get('networks') or {}).get('wprism-shared') or {}
if net.get('name') != 'wprism-shared' or net.get('external') is not True:
    bad("wprism-shared must be attached as external: true -- db.yml:37-39 is the sole "
        "creator of that network; got %r" % (net,))
if 'wprism-db-mysql-data' not in (doc.get('volumes') or {}):
    bad("needs its own named volume; sharing db.yml's wprism-db-data would put two "
        "servers on one datadir")
svc = (doc.get('services') or {}).get('db') or {}
if svc.get('container_name') != 'wprism-shared-mysql':
    bad("container_name must be wprism-shared-mysql; got %r" % svc.get('container_name'))
if not str(svc.get('image', '')).startswith('mysql:'):
    bad("image must be a mysql image; got %r" % svc.get('image'))
if svc.get('environment', {}).get('MYSQL_ROOT_PASSWORD') != 'root':
    bad("MYSQL_ROOT_PASSWORD is the mysql entrypoint's own variable; "
        "MARIADB_ROOT_PASSWORD (db.yml:50) would leave this server with no root credential")
ports = svc.get('ports') or []
if ports != ['127.0.0.1:3326:3306']:
    bad("port must be loopback-only 3326 -- distinct from db.yml:58's 3316, since both "
        "servers are up at once during a matrix run; got %r" % (ports,))
test = (svc.get('healthcheck') or {}).get('test') or []
joined = ' '.join(str(t) for t in test)
if 'healthcheck.sh' in joined:
    bad("healthcheck.sh ships only in MariaDB images (db.yml:68); a missing binary never "
        "reports healthy, so pair_db_ensure_up would poll for 120s and refuse")
if 'mysqladmin' in joined or 'ping' in joined:
    bad("mysqladmin ping answers before the server serves real queries -- the exact "
        "fake-readiness gap db.yml:61-66 records")
if 'SELECT 1' not in joined:
    bad("healthcheck must run a real statement (SELECT 1), the property "
        "--innodb_initialized buys on the MariaDB side; got %r" % (test,))
PY
pass "db.mysql.yml: own project/volume, external wprism-shared, loopback 3326, real-query healthcheck"

say "db.yml is untouched by this lane"
# The whole design rests on the MariaDB server not moving: every other agent's
# in-flight pairs sit on it.
grep -Fq 'container_name: wprism-shared-db' "$ROOT/sandbox/db.yml" \
  || fail "db.yml no longer declares wprism-shared-db"
grep -Fq 'image: mariadb:11' "$ROOT/sandbox/db.yml" \
  || fail "db.yml's image moved -- the MySQL lane must never retag the shared MariaDB"
grep -Fq '"127.0.0.1:${WPRISM_SHARED_DB_PORT:-3316}:3306"' "$ROOT/sandbox/db.yml" \
  || fail "db.yml's published port lost its task-specific override with default 3316"
pass "db.yml still defaults mariadb:11 / wprism-shared-db to 3316 and permits a task-scoped port override"

say "every direct live-suite prerequisite preserves the fleet-shared database container"
direct_db_up="$(grep -RhE 'docker compose .*wprism-db .*up ' "$ROOT/sandbox/tests/live" || true)"
expected_db_up='docker compose -p wprism-db -f db.yml up -d --no-recreate >/dev/null'
[ "$direct_db_up" = "$expected_db_up" ] \
  || fail "direct live-suite wprism-db calls must be the one reviewed non-recreating prerequisite; got: $direct_db_up"
case "$direct_db_up" in
  *--force-recreate*) fail "a live suite may not force-recreate the fleet-shared database" ;;
esac
matrix_db_up="$(grep -F 'docker compose -p "$cell_project"' "$DB_SCOPE_LIVE" || true)"
expected_matrix_db_up='  docker compose -p "$cell_project" -f "$cell_file" up -d --no-recreate >/dev/null'
[ "$matrix_db_up" = "$expected_matrix_db_up" ] \
  || fail "the database matrix's shared-engine prerequisite must be the exact reviewed non-recreating command; got: $matrix_db_up"
pass "direct and matrix live-suite DB prerequisites are exact --no-recreate calls"

say "the engine matrix settles its exact lease before changing cells and never owns the shared server"
acquire_line="$(grep -nFx '  pair_live_ownership_acquire "$cell_env"' "$DB_SCOPE_LIVE" | cut -d: -f1)"
up_line="$(grep -nFx '  pair_live_ownership_up --headless --artifacts --wordpress-offline' "$DB_SCOPE_LIVE" | cut -d: -f1)"
finish_line="$(grep -nFx '  pair_live_ownership_finish_leg' "$DB_SCOPE_LIVE" | cut -d: -f1)"
complete_line="$(grep -nF "pair_live_ownership_complete '✔ REGRESS_CORE_SCOPE_DATABASE PASSED'" "$DB_SCOPE_LIVE" | cut -d: -f1)"
[ -n "$acquire_line" ] && [ -n "$up_line" ] && [ -n "$finish_line" ] && [ -n "$complete_line" ] \
  && [ "$acquire_line" -lt "$up_line" ] && [ "$up_line" -lt "$finish_line" ] \
  && [ "$finish_line" -lt "$complete_line" ] \
  || fail 'database matrix must acquire, start, settle its cell and only then publish completion'
grep -Fqx '    && [ "$WPRISM_DB_HOST" = "$cell_container" ] \' "$DB_SCOPE_LIVE" \
  || fail 'database matrix must compare its direct Compose host with the exact cell container'
grep -Fqx 'ARTIFACTS="tmp/core-scope-database/$PAIR"' "$DB_SCOPE_LIVE" \
  || fail 'independent database matrices must not overwrite neighboring evidence'
if grep -Eq 'bash bin/pair.sh destroy|unset WPRISM_DB_ENGINE|wprism-db-mysql .*down' "$DB_SCOPE_LIVE"; then
  fail 'database matrix bypasses engine-bound ownership or destroys the fleet-shared MySQL service'
fi
pass 'each database cell keeps its tuple through verified teardown; shared engines and neighboring evidence are untouched'

say 'every tracked direct-Compose pair parent selects its database in its own process'
php /dev/stdin "$ROOT" <<'PHP'
<?php
declare(strict_types=1);

$root = $argv[1];
require_once $root . '/tools/src/ActiveShellSource.php';

function isDirectPairParent(string $active): bool
{
    // proof_legacy_pair.sh belongs to docker-compose.yml's independent r1
    // databases. A substring census would incorrectly migrate those hosts.
    return preg_match('~(?<![A-Za-z0-9_.-])pair[.]sh(?![A-Za-z0-9_.-])~', $active) === 1
        && preg_match('~\bdocker\s+compose\b~', $active) === 1;
}
if (!isDirectPairParent('bash bin/pair.sh up probe; docker compose -f pair.yml run cli1')
    || isDirectPairParent('source lib/proof_legacy_pair.sh; docker compose -f docker-compose.yml run cli-r1a')) {
    throw new RuntimeException('direct pair parent detection lost the independent legacy topology boundary');
}
$paths = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files -- ' . escapeshellarg('*.sh'), $paths, $status);
if ($status !== 0) {
    throw new RuntimeException('could not inventory tracked shell parents');
}
$failures = [];
$count = 0;
foreach ($paths as $path) {
    if (preg_match('~^(?:sandbox/(?:bin/adapter-boundary[.]sh|conformance/run[.]sh|tests/(?:live|grind|certify|spike|lib)/)|adapter-packages/[^/]+/tests/(?:live|certify)/|integration-scenarios/[^/]+/tests/live/)~', $path) !== 1) {
        continue;
    }
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('could not read pair parent ' . $path);
    }
    $active = WPrism\Tooling\ActiveShellSource::source($source);
    if (!isDirectPairParent($active)) {
        continue;
    }
    $count++;
    if (WPrism\Tooling\ActiveShellSource::statement($source, 'pair_db_select_engine') === null
        && WPrism\Tooling\ActiveShellSource::statement($source, 'pair_live_ownership_acquire') === null) {
        $failures[] = $path;
    }
}
if ($count === 0 || $failures !== []) {
    throw new RuntimeException('direct pair parents lack caller-local engine selection: ' . implode(', ', $failures));
}
echo "ok: all $count direct-Compose pair parents retain caller-local database context\n";
PHP

say 'MariaDB-specific evidence refuses a conflicting engine before any container operation'
# Reference-provider dump/probe assumptions are not a MySQL capability claim.
# Exercise the real parents, including the indirect provider-conformance one
# that the direct-Compose inventory intentionally cannot infer from its name.
for maria_parent in \
  sandbox/tests/grind/grind_adapter_walk.sh \
  sandbox/tests/grind/grind_adoption.sh \
  sandbox/tests/grind/grind_mup.sh \
  sandbox/tests/live/regress_core_scope_platform.sh \
  sandbox/tests/live/regress_env_provider_conformance_live.sh \
  sandbox/tests/live/regress_environment_materializer_live.sh \
  sandbox/tests/live/regress_rehearsal_containment_live.sh; do
  : > "$DOCKER_LOG"
  premise_out="$TMP/$(basename "$maria_parent").out"
  if env WPRISM_DB_ENGINE=mysql WPRISM_DB_HOST=wprism-shared-db \
    bash "$ROOT/$maria_parent" >"$premise_out" 2>&1; then
    fail "$maria_parent accepted an engine outside its evidence premise"
  else
    premise_status=$?
  fi
  [ "$premise_status" -eq 1 ] \
    && grep -Fq 'requires MariaDB; got WPRISM_DB_ENGINE=mysql' "$premise_out" \
    || fail "$maria_parent did not name its conflicting engine before setup: $(cat "$premise_out")"
  [ ! -s "$DOCKER_LOG" ] \
    || fail "$maria_parent reached a container before refusing its unsupported evidence premise: $(cat "$DOCKER_LOG")"
done
pass 'all seven MariaDB-specific parents refuse ambient MySQL before Docker, including indirect provider conformance'

say 'a legacy entry point retains ambient MySQL before delegating its first pair mutation'
# Execute the actual legacy parent, but intercept its first pair.sh child.
# The sentinel stops before reset/up: no daemon, database, or site file can be
# touched, while caller-local selection and inherited child authority are real.
REAL_BASH="$(command -v bash)"
LEGACY_CONTEXT="$TMP/legacy-context.txt"
cat > "$FAKE_BIN/bash" <<'FAKE_PAIR_ENTRY'
#!/bin/bash
set -eu
[ "$#" -eq 3 ] && [ "$1" = bin/pair.sh ] && [ "$2" = reset ] \
  && [ "$3" = contextprobe ] || exit 79
printf '%s\t%s\n' "${WPRISM_DB_ENGINE:-unset}" "${WPRISM_DB_HOST:-unset}" > "$WPRISM_CONTEXT_PROBE_OUTPUT"
exit 78
FAKE_PAIR_ENTRY
chmod +x "$FAKE_BIN/bash"
for selected in mariadb mysql; do
  if (
    unset WPRISM_DB_ENGINE WPRISM_DB_HOST
    [ "$selected" = mariadb ] || export WPRISM_DB_ENGINE="$selected"
    export WPRISM_CONTEXT_PROBE_OUTPUT="$LEGACY_CONTEXT" PAIR=contextprobe PORT1=9580 PORT2=9581
    "$REAL_BASH" "$ROOT/sandbox/tests/live/regress_option_reconciliation.sh"
  ) >"$TMP/legacy-$selected.out" 2>&1; then
    fail 'legacy context probe escaped its pre-mutation sentinel'
  else
    probe_status=$?
  fi
  [ "$probe_status" -eq 78 ] \
    || fail "legacy $selected context did not reach the exact pre-mutation sentinel: $(cat "$TMP/legacy-$selected.out")"
  case "$selected" in
    mariadb) expected_host=wprism-shared-db ;;
    mysql) expected_host=wprism-shared-mysql ;;
  esac
  [ "$(cat "$LEGACY_CONTEXT")" = "$(printf '%s\t%s' "$selected" "$expected_host")" ] \
    || fail "legacy $selected caller split its parent and pair.sh database contexts: $(cat "$LEGACY_CONTEXT")"
done
pass 'the unchanged legacy entry-point interface keeps default MariaDB and ambient MySQL exact before mutation'

printf '\n\033[1;32m✔ REGRESS_PAIR_DB_ENGINE PASSED\033[0m\n'
