#!/usr/bin/env bash
# Exact-artifact database engine matrix — the live half of the per-engine
# platform claim.
#
# platform/adapter-library/capabilities/platform.json's database axis is a map from engine to
# that engine's own version line (agent/src/Policy/PlatformCompatibility.php's
# valid_database_axis()). This suite is what that map's MySQL entry rests on:
# one full round trip per CLAIMED engine, on that engine's own shared server,
# plus the five dialect probe groups docs/mysql-dialect-audit.md derived from
# the shipped SQL. Until it has run, the claim's own note says PENDING and
# names the remedy on failure — drop the MySQL entry and restore a
# MariaDB-only engines map, never a fallback (AGENTS.md rule 9).
#
# The claimed set is read out of platform.json rather than restated, so an
# engine added to the claim with no cell here fails before any pair boots.
# regress_core_scope_platform.sh owns the core/PHP matrix and runs entirely on
# MariaDB; this suite owns the engine axis. That split is deliberate: an engine
# axis "proven" by a suite that never booted the engine is exactly the hollow
# coverage DESIGN.md forbids.
#
# Probe ORDER is load-bearing and comes straight from the audit's own closing
# paragraph: §5 (authentication) first, because a failed caching_sha2_password
# handshake blocks the whole lane before any dialect question is reachable;
# then §3 (GET_LOCK bounds), §1 (VALUES(col) upserts), §2 (JSON predicates over
# a LONGTEXT column) and §4 (schema/collation), which need progressively more
# of the agent to be working.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
note() { printf '\033[1;33mnote: %s\033[0m\n' "$*"; }

PAIR="${CORE_SCOPE_DATABASE_PAIR:-coredb}"
PORT1="${CORE_SCOPE_DATABASE_PORT1:-8990}"
PORT2="${CORE_SCOPE_DATABASE_PORT2:-8991}"
PLATFORM_FILE='../platform/adapter-library/capabilities/platform.json'
ARTIFACTS='tmp/core-scope-database'

# The web/cli pair every cell boots. The engine is the variable under test, so
# core and PHP are held at the claim's own newest exercised values — a failure
# in this suite must be attributable to the engine and nothing else.
WP71_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
CLI83_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
# sandbox/db.mysql.yml boots the floating tag `mysql:8.4`; this is the digest
# that tag must currently resolve to locally. Pinning it here rather than in
# db.mysql.yml keeps registry digests out of the harness's own compose files
# (the same rule regress_core_scope_platform.sh states for WordPress images),
# while still refusing to record evidence from an image that floated.
MYSQL84_IMAGE='mysql@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb'

# One cell per claimed engine, as
# `<engine-name> <WPRISM_DB_ENGINE> <container> <client-binary> <compose-project> <compose-file>`.
# The engine NAME is the claim's own spelling, because that is the key the
# agent looks up exact-case (PlatformCompatibility::assert_supported()).
ENGINE_CELLS=(
  "MariaDB mariadb wprism-shared-db mariadb wprism-db db.yml"
  "MySQL mysql wprism-shared-mysql mysql wprism-db-mysql db.mysql.yml"
)

[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CORE_SCOPE_DATABASE_PAIR '$PAIR'"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] || fail 'database ports must be decimal integers'
PORT1=$((10#$PORT1)); PORT2=$((10#$PORT2))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail 'database ports must be an even value >=8900 and its adjacent successor'
command -v jq >/dev/null || fail 'jq is required'
command -v docker >/dev/null || fail 'docker is required'

SOURCE_SHA=$(git rev-parse --verify 'HEAD^{commit}') || fail 'database evidence has no resolvable Git HEAD'
[ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" ] || fail 'WPRISM_EXPECTED_SOURCE_SHA is required for exact database evidence'
[ "$WPRISM_EXPECTED_SOURCE_SHA" = "$SOURCE_SHA" ] \
  || fail "expected source $WPRISM_EXPECTED_SOURCE_SHA does not equal this checkout HEAD $SOURCE_SHA"
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] \
  || fail "database evidence checkout is dirty; commit the exact candidate $SOURCE_SHA first"

export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_ARTIFACT_OFFLINE=1
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"
ENVS_FILE=$(mktemp "${TMPDIR:-/tmp}/wprism-core-database.${PAIR}.XXXXXX")
mkdir -p "$ARTIFACTS"

compose() { docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml -f pair.wordpress-offline.yml "$@"; }
wp1() { compose run --rm -T cli1 wp "$@"; }
wp2() { compose run --rm -T cli2 wp "$@"; }

# Root SQL against whichever shared server the current cell selected. Mirrors
# sandbox/lib/pair_db.sh's pair_db_sql() exactly, including the client-binary
# split (mariadb:11 ships only `mariadb`, mysql:8.4 only `mysql`).
sql() { # sql <container> <client> <statement>
  docker exec -i -e MYSQL_PWD=root "$1" "$2" -uroot -N -B -e "$3"
}

remove_owned_path() {
  local owned="$1"
  [ ! -e "$owned" ] || find "$owned" -depth -delete
}

destroy_owned_pair() {
  bash bin/pair.sh destroy "$PAIR" >/dev/null
  remove_owned_path "$R1"
  remove_owned_path "$R2"
  remove_owned_path "$ORIGIN"
}

cleanup() {
  local status=$? destroy_status=0
  trap - EXIT
  set +e
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1
  destroy_status=$?
  remove_owned_path "$R1"
  remove_owned_path "$R2"
  remove_owned_path "$ORIGIN"
  rm -f -- "$ENVS_FILE"
  # The MySQL server is a SECOND 2g/2.0-cpu long-lived container beside
  # db.yml's (db.mysql.yml:43-47 records the OrbStack wedge that motivated the
  # rule), so this suite brings it down when it is done rather than leaving it
  # for the next sweep to trip over. db.yml's own server is fleet-shared and is
  # deliberately left alone.
  docker compose -p wprism-db-mysql -f db.mysql.yml down -v >/dev/null 2>&1
  if [ "$status" -eq 0 ] && [ "$destroy_status" -ne 0 ]; then
    status=$destroy_status
  fi
  exit "$status"
}
trap cleanup EXIT

write_env_file() {
  jq -n --arg compose "$(pwd)/pair.yml" --arg name "${PAIR}1" '
    {envs:{($name):{transport:"docker",compose_file:$compose,service:"cli1",repo_path:"/siterepo"}}}
  ' > "$ENVS_FILE"
}

prepare_repo() {
  cp tests/fixtures/core_lifecycle_site.wprism.json "$R1/site.wprism.json"
  cp tests/fixtures/core_lifecycle_site.wprism.json "$R2/site.wprism.json"
  cp site-repo.gitignore.template "$R1/.gitignore"
  cp site-repo.gitignore.template "$R2/.gitignore"
  wp1 site empty --yes >/dev/null
  wp2 site empty --yes >/dev/null
  wp1 option update wprism_database_mutation_canary untouched >/dev/null
  write_env_file
}

wprism_json() { # <wp-runner> <label> <wprism arguments...>
  local runner="$1" label="$2" output payload
  shift 2
  if ! output=$("$runner" wprism "$@" --format=json 2>&1); then
    fail "$label failed: $output"
  fi
  payload=$(awk 'NF { line=$0 } END { print line }' <<<"$output")
  jq -e 'type == "object"' <<<"$payload" >/dev/null \
    || fail "$label did not return a JSON object: $output"
  printf '%s\n' "$payload"
}

# --------------------------------------------------------------- claim gates

jq -e '
  (.platform.compatibility.database as $db |
    ($db | has("engine") | not) and
    ($db.engines | type) == "object" and ($db.engines | length) > 0 and
    ([$db.engines[] | (keys == ["max","min"])] | all))
' "$PLATFORM_FILE" >/dev/null \
  || fail 'shipped platform declaration does not carry a well-formed per-engine database map'

# Every claimed engine must have a cell here, and every cell must be an engine
# the claim names. Read out of the shipped file so an engine widened into the
# claim without live evidence fails before any pair boots — which is the only
# mechanical thing standing between a two-line JSON edit and an unproven claim.
while IFS= read -r claimed_engine; do
  matched=0
  for cell in "${ENGINE_CELLS[@]}"; do
    read -r cell_engine _ _ _ _ _ <<<"$cell"
    [ "$cell_engine" = "$claimed_engine" ] && matched=1
  done
  [ "$matched" -eq 1 ] \
    || fail "claimed database engine $claimed_engine has no exercise cell in this matrix"
done < <(jq -er '.platform.compatibility.database.engines | keys[]' "$PLATFORM_FILE")
for cell in "${ENGINE_CELLS[@]}"; do
  read -r cell_engine _ _ _ _ _ <<<"$cell"
  jq -e --arg engine "$cell_engine" \
    '.platform.compatibility.database.engines | has($engine)' "$PLATFORM_FILE" >/dev/null \
    || fail "exercise cell $cell_engine is not an engine the shipped claim names"
done

for image in "$WP71_IMAGE" "$CLI83_IMAGE" "$MYSQL84_IMAGE"; do
  docker image inspect "$image" >/dev/null 2>&1 \
    || fail "exact database-matrix image is absent locally; this evidence run will not float or pull: $image"
done
# db.mysql.yml boots `mysql:8.4`, a floating tag. Evidence recorded from a tag
# that drifted under the pin is evidence about an unknown server, so the local
# tag must currently BE the pinned digest.
[ "$(docker image inspect mysql:8.4 --format '{{.Id}}')" = "$(docker image inspect "$MYSQL84_IMAGE" --format '{{.Id}}')" ] \
  || fail 'the local mysql:8.4 tag db.mysql.yml boots is not the pinned MYSQL84_IMAGE digest'
# db.yml's `mariadb:11` is deliberately NOT pinned here: it is the pre-existing
# fleet-shared server every other live suite already runs against, and pinning
# it in this one suite would make its evidence disagree with theirs. Recorded
# rather than silently skipped.
note 'mariadb:11 is not digest-pinned by this suite (db.yml is the fleet-shared server every live suite uses)'

pass 'every claimed database engine has an exercise cell, and every image is exact'

# ------------------------------------------------- §5 authentication (FIRST)
#
# docs/mysql-dialect-audit.md §5: mysql:8.4 defaults new accounts to
# caching_sha2_password and ships mysql_native_password disabled. If mysqlnd
# cannot complete that handshake the whole lane is blocked before any dialect
# question is reachable, so this runs before everything else and its failure
# message is the one that would justify an engine-conditional
# `IDENTIFIED WITH ...` clause in pair_db_ensure_app_user() — with the measured
# error quoted beside it, never speculatively.

say 'audit §5: the wordpress account authenticates on every claimed engine'
# db.yml FIRST and unconditionally: it is the sole creator of the `wprism-shared`
# network (db.yml:37-39 declares it without `external: true`), and every other
# compose file in this lane attaches to it as external.
docker compose -p wprism-db -f db.yml up -d >/dev/null
for cell in "${ENGINE_CELLS[@]}"; do
  read -r _ _ _ _ cell_project cell_file <<<"$cell"
  docker compose -p "$cell_project" -f "$cell_file" up -d >/dev/null
done

for cell in "${ENGINE_CELLS[@]}"; do
  read -r cell_engine cell_env cell_container cell_client _ _ <<<"$cell"
  export WPRISM_DB_ENGINE="$cell_env" WPRISM_WP_IMAGE="$WP71_IMAGE" WPRISM_CLI_IMAGE="$CLI83_IMAGE"
  destroy_owned_pair
  bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless --artifacts --wordpress-offline
  prepare_repo
  sql "$cell_container" "$cell_client" \
    "SELECT user, host, plugin FROM mysql.user WHERE user='wordpress'" \
    | tee "$ARTIFACTS/auth-$cell_env.txt"
  [ -s "$ARTIFACTS/auth-$cell_env.txt" ] \
    || fail "$cell_engine: the wordpress account this lane grants does not exist on $cell_container"
  wp1 db check >/dev/null \
    || fail "$cell_engine: wp db check failed — mysqlnd could not complete the handshake against $cell_container (see $ARTIFACTS/auth-$cell_env.txt)"
  pass "$cell_engine: the wordpress account authenticates and wp db check succeeds (auth plugin recorded in $ARTIFACTS/auth-$cell_env.txt)"

  # ------------------------------------------------------ §3 GET_LOCK bounds
  #
  # Audit §3: ProcessFence.php builds a 63-character lock name and
  # InitConfirmation.php a 57-character one, against a MySQL cap of 64. 63 is
  # inside that cap by exactly one character, and nothing in the tree asserts
  # the bound, so it is MEASURED here rather than assumed — including the exact
  # error a 65-character name produces.
  say "audit §3: $cell_engine named-lock bounds and multi-lock semantics"
  sql "$cell_container" "$cell_client" \
    "SELECT GET_LOCK(REPEAT('x',63),0), IS_USED_LOCK(REPEAT('x',63)) = CONNECTION_ID(), GET_LOCK(REPEAT('y',63),0), RELEASE_LOCK(REPEAT('x',63)), IS_USED_LOCK(REPEAT('y',63)) = CONNECTION_ID()" \
    > "$ARTIFACTS/getlock-$cell_env.txt"
  # One session, one connection: a 63-char name round-trips, a SECOND 63-char
  # name is held at the same time (the multi-lock semantics ProcessFence and
  # InitConfirmation both depend on), and releasing the first leaves the second
  # held.
  [ "$(tr '\t' ' ' < "$ARTIFACTS/getlock-$cell_env.txt")" = '1 1 1 1 1' ] \
    || fail "$cell_engine: 63-character named locks did not round-trip with multi-lock semantics: $(cat "$ARTIFACTS/getlock-$cell_env.txt")"
  set +e
  sql "$cell_container" "$cell_client" "SELECT GET_LOCK(REPEAT('x',65),0)" \
    > "$ARTIFACTS/getlock-65-$cell_env.txt" 2>&1
  set -e
  pass "$cell_engine: 63-character locks round-trip and hold concurrently; the 65-character answer is recorded verbatim in $ARTIFACTS/getlock-65-$cell_env.txt"

  # ------------------------------ §1 VALUES(col) + §2 JSON over LONGTEXT
  #
  # Audit §1 and §2 are exercised through the REAL lease, not a synthetic
  # upsert: PromotionLease.php:372-393's statement is a compare-and-swap whose
  # `then` branch IS `VALUES(v)` and whose predicate is five
  # JSON_UNQUOTE(JSON_EXTRACT(...)) tests over a LONGTEXT column. A synthetic
  # upsert would exercise the syntax and not the semantics.
  say "audit §1/§2: $cell_engine promotion lease acquire, renew and release"
  # php, not shasum/sha256sum: this suite already requires php for the host
  # `wprism doctor` calls, and the two GNU/BSD digest binaries disagree on name
  # and output shape between the machines these live runs happen on.
  ARTIFACT_HASH=$(php -r 'echo hash("sha256", $argv[1]);' "core-scope-database-$cell_env")
  ACQUIRE=$(wprism_json wp1 "$cell_engine lease acquire" promotion-begin \
    --repo=/siterepo --promotion-owner=core-scope-database --artifact-hash="$ARTIFACT_HASH")
  jq -e --arg hash "$ARTIFACT_HASH" '.artifact_hash == $hash and (.expires_at | type) == "number"' \
    <<<"$ACQUIRE" >/dev/null || fail "$cell_engine lease acquire returned an unexpected summary: $ACQUIRE"
  # The second begin lands on the ON DUPLICATE KEY UPDATE branch — the upsert
  # actually UPDATING on conflict, which is audit §1's first required
  # assertion, reached through the CAS rather than beside it.
  RENEW=$(wprism_json wp1 "$cell_engine lease renew" promotion-begin \
    --repo=/siterepo --promotion-owner=core-scope-database --artifact-hash="$ARTIFACT_HASH")
  jq -e --arg hash "$ARTIFACT_HASH" '.artifact_hash == $hash' <<<"$RENEW" >/dev/null \
    || fail "$cell_engine lease renew did not update on conflict: $RENEW"
  [ "$(jq -er '.expires_at' <<<"$RENEW")" -ge "$(jq -er '.expires_at' <<<"$ACQUIRE")" ] \
    || fail "$cell_engine lease renew moved the expiry backwards: $ACQUIRE -> $RENEW"
  # Audit §1 assertion 2: the warnings the engine raised for that statement,
  # captured verbatim. A VALUES(col) deprecation notice is a finding to record
  # in the widening commit's rationale, not a failure.
  sql "$cell_container" "$cell_client" "SHOW WARNINGS" > "$ARTIFACTS/warnings-$cell_env.txt" 2>&1 || true
  RELEASE=$(wprism_json wp1 "$cell_engine lease release" promotion-abort \
    --promotion-owner=core-scope-database --artifact-hash="$ARTIFACT_HASH")
  jq -e '.released == true' <<<"$RELEASE" >/dev/null \
    || fail "$cell_engine lease release did not release: $RELEASE"
  pass "$cell_engine: the real lease acquires, updates on conflict, and releases (engine warnings in $ARTIFACTS/warnings-$cell_env.txt)"

  # Audit §2's planted-garbage case, run IDENTICALLY on both engines so the two
  # refusal envelopes can be diffed byte for byte. MariaDB returns NULL from
  # JSON_EXTRACT over invalid JSON; MySQL is expected to raise
  # ER_INVALID_JSON_TEXT (3141) and fail the statement instead — a
  # fail-closed/fail-open difference, and the one this suite exists to measure.
  say "audit §2: $cell_engine acquire over a planted non-JSON lease row"
  wprism_json wp1 "$cell_engine lease reseed" promotion-begin \
    --repo=/siterepo --promotion-owner=core-scope-database --artifact-hash="$ARTIFACT_HASH" >/dev/null
  sql "$cell_container" "$cell_client" \
    "UPDATE wp_${PAIR}1.wp_wprism_kv SET v='not-json' WHERE k='promotion_lock'" >/dev/null
  set +e
  wp1 wprism promotion-begin --repo=/siterepo --promotion-owner=core-scope-database-other \
    --artifact-hash="$ARTIFACT_HASH" --format=json > "$ARTIFACTS/planted-$cell_env.json" 2>&1
  PLANTED_RC=$?
  set -e
  [ "$PLANTED_RC" -ne 0 ] \
    || fail "$cell_engine: a second owner ACQUIRED the lease over an unparseable row — the CAS did not fail closed (see $ARTIFACTS/planted-$cell_env.json)"
  pass "$cell_engine: a planted non-JSON lease row fails closed; the envelope is recorded in $ARTIFACTS/planted-$cell_env.json"
  sql "$cell_container" "$cell_client" \
    "DELETE FROM wp_${PAIR}1.wp_wprism_kv WHERE k='promotion_lock'" >/dev/null

  # ------------------------------------------- §4 schema and collation record
  say "audit §4: $cell_engine ledger schema and collation"
  : > "$ARTIFACTS/schema-$cell_env.txt"
  for table in wprism_map wprism_state wprism_kv wprism_journal; do
    docker exec -i -e MYSQL_PWD=root "$cell_container" "$cell_client" -uroot \
      -e "SHOW CREATE TABLE wp_${PAIR}1.wp_${table}\\G" >> "$ARTIFACTS/schema-$cell_env.txt"
  done
  sql "$cell_container" "$cell_client" \
    "SELECT @@character_set_database, @@collation_database" \
    >> "$ARTIFACTS/schema-$cell_env.txt"
  grep -q 'wp_wprism_kv' "$ARTIFACTS/schema-$cell_env.txt" \
    || fail "$cell_engine: the ledger tables were not created on wp_${PAIR}1"
  pass "$cell_engine: all four wp_wprism_* tables exist; schema and collation recorded in $ARTIFACTS/schema-$cell_env.txt"

  # ------------------------------------------------- the full product round trip
  #
  # The assertion that actually matters (audit §4's third): a real capture ->
  # apply -> recapture on this engine, producing byte-identical managed state.
  say "$cell_engine: real round trip, verified zero-write repeat, and doctor"
  FACTS=$(wp1 eval 'echo wp_json_encode(\WPrism\PlatformCompatibility::current_facts());' | awk 'NF { line=$0 } END { print line }')
  jq -e --arg engine "$cell_engine" '
    .site_mode == "single-site" and .database.engine == $engine and
    .filesystem == {
      directory_separator:"/",
      functions:{chmod:true,flock:true,fsync:true,lstat:true,rename:true},
      os_family:"Linux"
    }
  ' \
    <<<"$FACTS" >/dev/null || fail "$cell_engine pair reported the wrong engine: $FACTS"
  DB_VERSION=$(jq -er '.database.version' <<<"$FACTS")
  ENGINE_MIN=$(jq -er --arg e "$cell_engine" '.platform.compatibility.database.engines[$e].min' "$PLATFORM_FILE")
  ENGINE_MAX=$(jq -er --arg e "$cell_engine" '.platform.compatibility.database.engines[$e].max' "$PLATFORM_FILE")
  php -r 'exit(version_compare($argv[1], $argv[2], ">=") && version_compare($argv[1], $argv[3], "<") ? 0 : 1);' \
    "$DB_VERSION" "$ENGINE_MIN" "$ENGINE_MAX" \
    || fail "$cell_engine server $DB_VERSION is outside the range the claim states for it (>=$ENGINE_MIN <$ENGINE_MAX); this run would record evidence for a line nobody claimed"

  wp1 post create --post_type=post --post_status=publish \
    --post_title='Engine Matrix Exact' --post_name=engine-matrix-exact \
    --post_content='Exact engine content — বাংলা — delimiter | value' --porcelain >/dev/null
  CAPTURE=$(wprism_json wp1 "$cell_engine source capture" capture --repo=/siterepo)
  jq -e '.counts.post == 1 and .notes == [] and .warnings == []' <<<"$CAPTURE" >/dev/null \
    || fail "$cell_engine source capture reported unexpected coverage: $CAPTURE"
  cp -R "$R1/state" "$R2/state"
  FIRST=$(wprism_json wp2 "$cell_engine initial apply" apply --repo=/siterepo \
    --default-author=admin --adopt-by-slug=terms)
  jq -e '.canary == "clean" and .verification.result == "pass" and .promotion_lock.released == true' \
    <<<"$FIRST" >/dev/null || fail "$cell_engine initial apply did not verify: $FIRST"
  SECOND=$(wprism_json wp2 "$cell_engine idempotent apply" apply --repo=/siterepo \
    --default-author=admin --adopt-by-slug=terms)
  jq -e '.applied == 0 and .warnings == [] and .canary == "clean" and .verification.result == "pass"' \
    <<<"$SECOND" >/dev/null || fail "$cell_engine repeat apply was not a verified zero-write result: $SECOND"
  wprism_json wp2 "$cell_engine target recapture" capture --repo=/siterepo --out=/siterepo/state-check >/dev/null
  while IFS= read -r state_file; do
    cmp "$R2/state/$state_file" "$R2/state-check/$state_file" >/dev/null \
      || fail "$cell_engine recapture changed managed state bytes in $state_file"
  done < <(cd "$R2/state" && find . -type f -print | LC_ALL=C sort)

  DOCTOR_OUT=$(php ../cli/wprism doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1) \
    || fail "$cell_engine host doctor refused: $DOCTOR_OUT"
  grep -q "\[PASS\] database ($cell_env ${DB_VERSION//./\\.})" <<<"$DOCTOR_OUT" \
    || fail "$cell_engine host doctor did not pass the database row: $DOCTOR_OUT"
  pass "$cell_engine: real round trip, verified zero-write repeat, byte-identical recapture, and a passing doctor database row"
  unset WPRISM_DB_ENGINE
done

# ------------------------------------------------ cross-engine record (audit §4)
#
# The diffs are RECORDED, not asserted equal: MySQL 8 negotiates
# utf8mb4_0900_ai_ci and MariaDB 11 a uca1400/general collation, so a
# byte-identical SHOW CREATE TABLE was never the claim. What must hold is that
# the managed repository bytes agree, which each cell already proved against
# its own capture; this diff is what EXPLAINS a failure if one ever appears.
say 'audit §4: cross-engine schema and refusal-envelope diffs'
diff "$ARTIFACTS/schema-mariadb.txt" "$ARTIFACTS/schema-mysql.txt" \
  > "$ARTIFACTS/schema-diff.txt" || true
diff "$ARTIFACTS/planted-mariadb.json" "$ARTIFACTS/planted-mysql.json" \
  > "$ARTIFACTS/planted-diff.txt" || true
pass "cross-engine schema diff in $ARTIFACTS/schema-diff.txt; planted-row envelope diff in $ARTIFACTS/planted-diff.txt"

printf '\n\033[1;32m✔ REGRESS_CORE_SCOPE_DATABASE PASSED\033[0m\n'
