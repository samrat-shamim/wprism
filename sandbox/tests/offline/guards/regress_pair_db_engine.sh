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

for f in "$DB_LIB" "$IDENTITY_LIB" "$COMPOSE_LIB" "$PAIR_SH" "$PAIR_YML" "$DB_MYSQL_YML"; do
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
mkdir -p "$FAKE_BIN"
: > "$DOCKER_LOG"
cat > "$FAKE_BIN/docker" <<FAKE_DOCKER
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "\$*" >> "$DOCKER_LOG"
# Admin SQL is the only invocation this suite lets succeed; anything else is a
# sentinel so an unexpected escape to a real daemon is loud, not skipped.
if [ "\${1:-}" = exec ]; then
  cat >/dev/null 2>/dev/null || true
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

say "a non-up subcommand on the mysql lane still writes the mysql server into .env"
# The regression this closes: WPRISM_DB_HOST was first exported inside cmd_up
# only. But pair_compose_configure() REWRITES sandbox/.env on every call, and
# stop/start/destroy each call it (pair.sh's cmd_stop/cmd_start/cmd_destroy) --
# so `pair.sh stop <mysql-pair>` overwrote that file's WPRISM_DB_HOST with
# pair_compose.sh's wprism-shared-db default, and the next subprocess
# `docker compose -f pair.yml up` from conformance/run.sh or a regress_*.sh
# recreated wp1/wp2 against MariaDB while the operator recorded the run as
# MySQL evidence. Measured against the pre-fix source, this section's grep
# found WPRISM_DB_HOST=wprism-shared-db. It is the same lesson WPRISM_AGENT_SRC already
# learned one export earlier in that function ("the first version of this fix
# only set them in cmd_up and `stop` broke instantly", pair_compose.sh:32-33).
#
# Driven through the shipped pair.sh, not by re-implementing its wiring: a
# symlinked sandbox/ (pair.sh:62 does `cd "$(dirname "$0")/.."`, which follows
# the invoked path, not the link target) gives the run a real lib/ and a
# throwaway .env, so this suite never touches the worktree's own sandbox/.env.
SUBCMD_ROOT="$TMP/subcmd"
mkdir -p "$SUBCMD_ROOT/sandbox/bin"
ln -s "$PAIR_SH" "$SUBCMD_ROOT/sandbox/bin/pair.sh"
ln -s "$ROOT/sandbox/lib" "$SUBCMD_ROOT/sandbox/lib"
: > "$DOCKER_LOG"
# PAIR_SOURCE_ROOT short-circuits pair_identity_source_root()'s git resolution
# (pair_compose.sh:34-40); the fake docker makes the compose call itself fail,
# which is fine -- .env is written before that call.
if (
  export PAIR_SOURCE_ROOT="$TMP/fake-source-root" WPRISM_DB_ENGINE=mysql
  bash "$SUBCMD_ROOT/sandbox/bin/pair.sh" stop probe
) >/dev/null 2>&1; then
  fail "the fake docker should have made 'pair.sh stop' fail; it did not run compose at all"
fi
grep -Fq 'compose -p wprism-probe -f pair.yml stop' "$DOCKER_LOG" \
  || fail "pair.sh stop did not reach its compose call: $(cat "$DOCKER_LOG")"
grep -Fqx 'WPRISM_DB_HOST=wprism-shared-mysql' "$SUBCMD_ROOT/sandbox/.env" \
  || fail "stop on the mysql lane wrote the wrong engine into .env: $(cat "$SUBCMD_ROOT/sandbox/.env")"
# And the default engine's non-up subcommands still write the pre-lane value.
: > "$DOCKER_LOG"
(
  export PAIR_SOURCE_ROOT="$TMP/fake-source-root"
  unset WPRISM_DB_ENGINE
  bash "$SUBCMD_ROOT/sandbox/bin/pair.sh" stop probe
) >/dev/null 2>&1 || true
grep -Fqx 'WPRISM_DB_HOST=wprism-shared-db' "$SUBCMD_ROOT/sandbox/.env" \
  || fail "default-engine stop must write wprism-shared-db: $(cat "$SUBCMD_ROOT/sandbox/.env")"
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

say "pair_compose_configure persists WPRISM_DB_HOST into sandbox/.env for subprocess compose callers"
# pair.yml:59-74 records the live catch this covers: conformance/run.sh and
# every regress_*.sh invoke `pair.sh up` as a subprocess and then make their
# OWN `docker compose -f pair.yml` calls, which never see pair.sh's export. A
# MySQL pair whose subprocesses re-rendered the wprism-shared-db default would run
# green against MariaDB and be recorded as MySQL evidence.
# shellcheck source=../../../lib/pair_identity.sh
source "$IDENTITY_LIB"
# shellcheck source=../../../lib/pair_compose.sh
source "$COMPOSE_LIB"
ENV_CWD="$TMP/envcwd"
mkdir -p "$ENV_CWD"
(
  cd "$ENV_CWD"
  PAIR_SOURCE_ROOT="$TMP/fake-source-root"
  unset WPRISM_DB_HOST
  pair_compose_configure probe
)
grep -Fqx 'WPRISM_DB_HOST=wprism-shared-db' "$ENV_CWD/.env" \
  || fail ".env must carry the defaulted WPRISM_DB_HOST; got: $(cat "$ENV_CWD/.env")"
grep -Fqx "WPRISM_AGENT_SRC=$TMP/fake-source-root/agent" "$ENV_CWD/.env" \
  || fail ".env lost WPRISM_AGENT_SRC: $(cat "$ENV_CWD/.env")"
grep -Fqx "WPRISM_ADAPTER_PACKAGES_SRC=$TMP/fake-source-root/adapter-packages" "$ENV_CWD/.env" \
  || fail ".env lost WPRISM_ADAPTER_PACKAGES_SRC: $(cat "$ENV_CWD/.env")"
grep -Fqx "WPRISM_PLATFORM_SRC=$TMP/fake-source-root/platform" "$ENV_CWD/.env" \
  || fail ".env lost WPRISM_PLATFORM_SRC: $(cat "$ENV_CWD/.env")"
(
  cd "$ENV_CWD"
  PAIR_SOURCE_ROOT="$TMP/fake-source-root"
  export WPRISM_DB_HOST=wprism-shared-mysql
  pair_compose_configure probe
)
grep -Fqx 'WPRISM_DB_HOST=wprism-shared-mysql' "$ENV_CWD/.env" \
  || fail ".env must carry the selected engine's host; got: $(cat "$ENV_CWD/.env")"
[ "$(wc -l < "$ENV_CWD/.env" | tr -d ' ')" = 4 ] \
  || fail ".env must be exactly four lines (overwritten, never appended): $(cat "$ENV_CWD/.env")"
pass ".env carries all four values and is rewritten, not appended, on every call"

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
grep -Fq '"127.0.0.1:3316:3306"' "$ROOT/sandbox/db.yml" \
  || fail "db.yml's published port moved"
pass "db.yml still publishes mariadb:11 as wprism-shared-db on 3316"

printf '\n\033[1;32m✔ REGRESS_PAIR_DB_ENGINE PASSED\033[0m\n'
