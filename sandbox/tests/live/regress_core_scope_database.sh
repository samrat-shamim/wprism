#!/usr/bin/env bash
# Exact-artifact database engine matrix — the live half of the per-engine
# platform claim.
#
# platform/adapter-library/capabilities/platform.json's database axis is a map from engine to
# that engine's own version line (agent/src/Policy/PlatformCompatibility.php's
# valid_database_axis()). This suite is what that map's MySQL entry rests on:
# one full round trip per CLAIMED engine, on that engine's own shared server,
# plus the five dialect probe groups docs/mysql-dialect-audit.md derived from
# the shipped SQL. Its doctor assertion also executes each engine's declared
# PROCESS-gated InnoDB FK source, so a profile/source mismatch cannot pass on
# a version-only matrix. Until it has run, the claim's own note says PENDING and
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

. conformance/asserts.sh

PAIR="${CORE_SCOPE_DATABASE_PAIR:-coredb}"
PORT1="${CORE_SCOPE_DATABASE_PORT1:-8990}"
PORT2="${CORE_SCOPE_DATABASE_PORT2:-8991}"
PLATFORM_FILE='../platform/adapter-library/capabilities/platform.json'
ARTIFACTS="tmp/core-scope-database/$PAIR"

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

REPO_ROOT="$(cd .. && pwd -P)"
export WPRISM_SOURCE_ROOT="$REPO_ROOT"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_ARTIFACT_OFFLINE=1
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
mkdir -p "$ARTIFACTS"

# This matrix changes engines between legs. Keep each pair, both schemas, the
# caller-local engine/host context, and cleanup under one engine-bound lease;
# neither leg owns either fleet-shared database server itself.
# shellcheck source=../lib/pair_live_ownership.sh
. "$REPO_ROOT/sandbox/tests/lib/pair_live_ownership.sh"
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" \
  'database engine matrix evidence' 'wprism-core-scope-database'
ENVS_FILE="$PAIR_LIVE_OWNERSHIP_TMP_ROOT/environments.json"

compose() { docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml -f pair.wordpress-offline.yml "$@"; }
wp1() { compose run --rm -T cli1 wp "$@"; }
wp2() { compose run --rm -T cli2 wp "$@"; }

# Root SQL against whichever shared server the current cell selected. Mirrors
# sandbox/lib/pair_db.sh's pair_db_sql() exactly, including the client-binary
# split (mariadb:11 ships only `mariadb`, mysql:8.4 only `mysql`).
sql() { # sql <container> <client> <statement>
  docker exec -i -e MYSQL_PWD=root "$1" "$2" -uroot -N -B -e "$3"
}

write_env_file() (
  umask 077
  jq -n --arg compose "$(pwd)/pair.yml" --arg name "${PAIR}1" '
    {envs:{($name):{transport:"docker",compose_file:$compose,service:"cli1",repo_path:"/siterepo"}}}
  ' > "$ENVS_FILE"
)

prepare_repo() {
  cp tests/fixtures/core_lifecycle_site.wprism.json "$R1/site.wprism.json"
  cp tests/fixtures/core_lifecycle_site.wprism.json "$R2/site.wprism.json"
  cp site-repo.gitignore.template "$R1/.gitignore"
  cp site-repo.gitignore.template "$R2/.gitignore"
  wp1 site empty --yes >/dev/null
  wp2 site empty --yes >/dev/null
  establish_core_environment_bindings wp1 /siterepo admin@example.test \
    "http://${PAIR}1.invalid" "http://${PAIR}1.invalid"
  establish_core_environment_bindings wp2 /siterepo admin@example.test \
    "http://${PAIR}2.invalid" "http://${PAIR}2.invalid"
  wp1 option update wprism_database_mutation_canary untouched >/dev/null
  write_env_file
}

wprism_json() { # <wp-runner> <label> <wprism arguments...>
  local runner="$1" label="$2" payload
  shift 2
  capture_wprism_json_success payload "$label" "$runner" wprism "$@" --format=json
  jq -e 'type == "object"' <<<"$payload" >/dev/null \
    || fail "$label did not return a JSON object: $payload"
  printf '%s\n' "$payload"
}

# --------------------------------------------------------------- claim gates

jq -e '
  (.platform.compatibility.database as $db |
    ($db | has("engine") | not) and
    ($db.engines | type) == "object" and ($db.engines | length) > 0 and
    ([$db.engines[] | (keys == ["max","min"])] | all) and
    $db.foreign_key_census == {
      metadata_sources:{MariaDB:"INNODB_SYS_FOREIGN",MySQL:"INNODB_FOREIGN"},
      profile:"complete-innodb-foreign-key-census/v1",
      required_global_privilege:"PROCESS",
      scope:"transactional-database-mutation"
    })
' "$PLATFORM_FILE" >/dev/null \
  || fail 'shipped platform declaration does not carry a well-formed per-engine database/FK-census profile'

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
docker compose -p wprism-db -f db.yml up -d --no-recreate >/dev/null
for cell in "${ENGINE_CELLS[@]}"; do
  read -r _ _ _ _ cell_project cell_file <<<"$cell"
  docker compose -p "$cell_project" -f "$cell_file" up -d --no-recreate >/dev/null
done

for cell in "${ENGINE_CELLS[@]}"; do
  read -r cell_engine cell_env cell_container cell_client _ _ <<<"$cell"
  export WPRISM_WP_IMAGE="$WP71_IMAGE" WPRISM_CLI_IMAGE="$CLI83_IMAGE"
  pair_live_ownership_acquire "$cell_env"
  [ "$PAIR_LIVE_OWNERSHIP_ENGINE" = "$cell_env" ] \
    && [ "$WPRISM_DB_ENGINE" = "$cell_env" ] \
    && [ "$PAIR_LIVE_OWNERSHIP_CONTAINER" = "$cell_container" ] \
    && [ "$WPRISM_DB_HOST" = "$cell_container" ] \
    || fail "$cell_engine did not bind its lease and direct Compose context to $cell_env/$cell_container"
  pair_live_ownership_up --headless --artifacts --wordpress-offline
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
  # A synthetic 63-byte probe passed while the rebranded ProcessFence emitted
  # 66 bytes and MySQL refused actual capture. Exercise the product's name,
  # acquisition, continuity, and release first; the independent SQL probe then
  # measures simultaneous locks at MySQL's exact 64-byte boundary.
  say "audit §3: $cell_engine named-lock bounds and multi-lock semantics"
  capture_wprism_json_success FENCE "$cell_engine product process fence" wp1 eval '
global $wpdb;
$name = \WPrism\ProcessFence::name();
if (strlen($name) > 64) {
    throw new RuntimeException("product process fence exceeds the database lock-name budget");
}
\WPrism\ProcessFence::acquire();
try {
    \WPrism\ProcessFence::assertHeld();
    $held = \WPrism\ProcessFence::isContinuous();
    $secondName = hash("sha256", "database-matrix|" . $name);
    $secondAcquired = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $secondName));
    if ((string) $secondAcquired !== "1" || $wpdb->last_error !== "") {
        throw new RuntimeException("a second lock could not coexist with the product process fence");
    }
    \WPrism\ProcessFence::assertHeld();
} finally {
    \WPrism\ProcessFence::release();
}
$wpdb->last_error = "";
$owner = $wpdb->get_var($wpdb->prepare("SELECT IS_USED_LOCK(%s)", $name));
$released = $owner === null && $wpdb->last_error === "";
$secondHeld = $wpdb->get_var($wpdb->prepare("SELECT IS_USED_LOCK(%s) = CONNECTION_ID()", $secondName));
$secondContinuous = (string) $secondHeld === "1" && $wpdb->last_error === "";
$secondReleased = $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $secondName));
echo wp_json_encode([
    "name_bytes" => strlen($name),
    "held" => $held,
    "released" => $released,
    "second_lock_survived" => $secondContinuous,
    "second_lock_released" => (string) $secondReleased === "1" && $wpdb->last_error === "",
    "continuous_after_release" => \WPrism\ProcessFence::isContinuous(),
]);
'
  printf '%s\n' "$FENCE" > "$ARTIFACTS/process-fence-$cell_env.json"
  jq -e '. == {name_bytes:64,held:true,released:true,second_lock_survived:true,second_lock_released:true,continuous_after_release:false}' \
    <<<"$FENCE" >/dev/null \
    || fail "$cell_engine: actual product process fence did not acquire, assert, and release: $FENCE"
  sql "$cell_container" "$cell_client" \
    "SELECT GET_LOCK(REPEAT('x',64),0), IS_USED_LOCK(REPEAT('x',64)) = CONNECTION_ID(), GET_LOCK(REPEAT('y',64),0), RELEASE_LOCK(REPEAT('x',64)), IS_USED_LOCK(REPEAT('y',64)) = CONNECTION_ID()" \
    > "$ARTIFACTS/getlock-$cell_env.txt"
  # One session, one connection: a 64-char name round-trips, a SECOND 64-char
  # name is held at the same time (the multi-lock semantics ProcessFence and
  # InitConfirmation both depend on), and releasing the first leaves the second
  # held.
  [ "$(tr '\t' ' ' < "$ARTIFACTS/getlock-$cell_env.txt")" = '1 1 1 1 1' ] \
    || fail "$cell_engine: 64-character named locks did not round-trip with multi-lock semantics: $(cat "$ARTIFACTS/getlock-$cell_env.txt")"
  set +e
  sql "$cell_container" "$cell_client" "SELECT GET_LOCK(REPEAT('x',65),0)" \
    > "$ARTIFACTS/getlock-65-$cell_env.txt" 2>&1
  set -e
  pass "$cell_engine: the actual process fence and concurrent 64-character locks round-trip; the 65-character answer is recorded verbatim in $ARTIFACTS/getlock-65-$cell_env.txt"

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
  jq -e --arg hash "$ARTIFACT_HASH" '
    .owner == "core-scope-database" and .artifact_hash == $hash and .phase == "checkpoint" and
    (.acquired_at | type) == "number" and (.expires_at | type) == "number" and
    .expires_at > .acquired_at and .recovered == false
  ' \
    <<<"$ACQUIRE" >/dev/null || fail "$cell_engine lease acquire returned an unexpected summary: $ACQUIRE"
  capture_wprism_json_success ACQUIRED_ROW "$cell_engine acquired lease row" wp1 eval \
    'echo wp_json_encode(["lease" => \WPrism\PromotionLease::current()]);'
  jq -en --argjson receipt "$ACQUIRE" --argjson observed "$ACQUIRED_ROW" \
    '$observed.lease == ($receipt | del(.recovered, .session_id))' >/dev/null \
    || fail "$cell_engine acquire receipt did not match the persisted lease row: $ACQUIRED_ROW"

  # The second begin lands on the ON DUPLICATE KEY UPDATE branch — the upsert
  # must actually replace a different value, even when both calls land in the
  # same second. A real heartbeat changes the phase; the public begin must
  # replace that observed sentinel with checkpoint through its CAS.
  capture_wprism_json_success BEFORE_RENEW "$cell_engine lease renewal premise" wp1 eval '
$lease = \WPrism\PromotionLease::current();
if (!is_array($lease) || ($lease["owner"] ?? null) !== "core-scope-database") {
    throw new RuntimeException("database matrix has no exact owned lease to heartbeat");
}
\WPrism\PromotionLease::heartbeat($lease["owner"], $lease["artifact_hash"], "database-matrix-renewal-sentinel");
echo wp_json_encode(["lease" => \WPrism\PromotionLease::current()]);
'
  jq -en --argjson before "$ACQUIRED_ROW" --argjson after "$BEFORE_RENEW" '
    $after.lease.phase == "database-matrix-renewal-sentinel" and
    $after.lease.owner == $before.lease.owner and
    $after.lease.artifact_hash == $before.lease.artifact_hash and
    $after.lease.expires_at >= $before.lease.expires_at
  ' >/dev/null || fail "$cell_engine heartbeat did not establish a changed, still-owned lease: $BEFORE_RENEW"
  RENEW=$(wprism_json wp1 "$cell_engine lease renew" promotion-begin \
    --repo=/siterepo --promotion-owner=core-scope-database --artifact-hash="$ARTIFACT_HASH")
  jq -e --arg hash "$ARTIFACT_HASH" '
    .owner == "core-scope-database" and .artifact_hash == $hash and
    .phase == "checkpoint" and .recovered == false
  ' <<<"$RENEW" >/dev/null \
    || fail "$cell_engine lease renew did not update on conflict: $RENEW"
  [ "$(jq -er '.expires_at' <<<"$RENEW")" -ge "$(jq -er '.expires_at' <<<"$ACQUIRE")" ] \
    || fail "$cell_engine lease renew moved the expiry backwards: $ACQUIRE -> $RENEW"
  capture_wprism_json_success RENEWED_ROW "$cell_engine renewed lease row" wp1 eval \
    'echo wp_json_encode(["lease" => \WPrism\PromotionLease::current()]);'
  jq -en --argjson receipt "$RENEW" --argjson before "$BEFORE_RENEW" --argjson observed "$RENEWED_ROW" '
    $observed.lease == ($receipt | del(.recovered, .session_id)) and
    $observed.lease != $before.lease
  ' >/dev/null || fail "$cell_engine renewal receipt did not prove the changed persisted row: $RENEWED_ROW"

  # SHOW WARNINGS belongs to the statement's own connection. A fresh mysql
  # 8.4.11 client first executes `select $$` to detect dollar quoting
  # (client/mysql.cc:1253-1256,1494), so the former root-client read attributed
  # its 1064 feature-probe error to unrelated promotion SQL. Record product
  # receipts plus row observations instead; no statement-warning claim is made.
  RELEASE=$(wprism_json wp1 "$cell_engine lease release" promotion-abort \
    --promotion-owner=core-scope-database --artifact-hash="$ARTIFACT_HASH")
  jq -e --arg hash "$ARTIFACT_HASH" '
    .released == true and .owner == "core-scope-database" and .artifact_hash == $hash
  ' <<<"$RELEASE" >/dev/null \
    || fail "$cell_engine lease release did not release: $RELEASE"
  capture_wprism_json_success RELEASED_ROW "$cell_engine released lease row" wp1 eval \
    'echo wp_json_encode(["lease" => \WPrism\PromotionLease::current()]);'
  jq -e '. == {lease:null}' <<<"$RELEASED_ROW" >/dev/null \
    || fail "$cell_engine release receipt left a persisted lease row: $RELEASED_ROW"
  (umask 077; jq -n --arg engine "$cell_engine" \
    --argjson acquire "$ACQUIRE" --argjson acquired "$ACQUIRED_ROW" \
    --argjson before "$BEFORE_RENEW" --argjson renew "$RENEW" --argjson renewed "$RENEWED_ROW" \
    --argjson release "$RELEASE" --argjson released "$RELEASED_ROW" '
      {engine:$engine,acquire:{receipt:$acquire,observed:$acquired.lease},
       before_renew:$before.lease,renew:{receipt:$renew,observed:$renewed.lease},
       release:{receipt:$release,observed:$released.lease}}
    ' > "$ARTIFACTS/promotion-lease-$cell_env.json")
  pass "$cell_engine: public lease acquire/update/release receipts match independently observed rows ($ARTIFACTS/promotion-lease-$cell_env.json)"

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
  capture_wprism_json_refusal PLANTED "$cell_engine planted non-JSON lease" \
    wp1 wprism promotion-begin --repo=/siterepo --promotion-owner=core-scope-database-other \
    --artifact-hash="$ARTIFACT_HASH" --format=json
  (umask 077; printf '%s\n' "$PLANTED" > "$ARTIFACTS/planted-$cell_env.json")
  jq -e '.format == "wprism-command-refusal/v1" and .ok == false and
    .command == "promotion-begin" and .reason_code == "promotion_begin_failed"' <<<"$PLANTED" >/dev/null \
    || fail "$cell_engine: the planted lease was not answered by the expected product refusal: $PLANTED"
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
  capture_wprism_json_success FACTS "$cell_engine platform facts" wp1 eval \
    'echo wp_json_encode(\WPrism\PlatformCompatibility::current_facts());'
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
  capture_wprism_json_checked FIRST "$cell_engine initial apply" assert_wprism_apply_ready \
    wp2 wprism apply --repo=/siterepo --default-author=admin --adopt-by-slug=terms --format=json
  jq -e '.plan.env_missing == 0 and .promotion_lock.released == true' \
    <<<"$FIRST" >/dev/null || fail "$cell_engine initial apply did not verify: $FIRST"
  capture_wprism_json_checked SECOND "$cell_engine idempotent apply" assert_wprism_apply_ready \
    wp2 wprism apply --repo=/siterepo --default-author=admin --adopt-by-slug=terms --format=json
  jq -e '.applied == 0 and .plan.env_missing == 0 and .warnings == [] and .canary == "clean" and .verification.result == "pass"' \
    <<<"$SECOND" >/dev/null || fail "$cell_engine repeat apply was not a verified zero-write result: $SECOND"
  wprism_json wp2 "$cell_engine target recapture" capture --repo=/siterepo --out=/siterepo/state-check >/dev/null
  while IFS= read -r state_file; do
    cmp "$R2/state/$state_file" "$R2/state-check/$state_file" >/dev/null \
      || fail "$cell_engine recapture changed managed state bytes in $state_file"
  done < <(cd "$R2/state" && find . -type f -print | LC_ALL=C sort)

  DOCTOR_RC=0
  DOCTOR_OUT=$(php ../cli/wprism doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1) || DOCTOR_RC=$?
  (umask 077; printf '%s\n' "$DOCTOR_OUT" > "$ARTIFACTS/doctor-$cell_env.txt")
  [ "$DOCTOR_RC" -eq 0 ] || fail "$cell_engine host doctor refused with exit $DOCTOR_RC: $DOCTOR_OUT"
  assert_no_php_runtime_diagnostics "$cell_engine host doctor" "$DOCTOR_OUT"
  grep -q "\[PASS\] database ($cell_env ${DB_VERSION//./\\.})" <<<"$DOCTOR_OUT" \
    || fail "$cell_engine host doctor did not pass the database row: $DOCTOR_OUT"
  grep -q "\[PASS\] transactional database mutation ($cell_env)" <<<"$DOCTOR_OUT" \
    || fail "$cell_engine host doctor did not prove the PROCESS-gated FK census source: $DOCTOR_OUT"
  pass "$cell_engine: real round trip, verified zero-write repeat, byte-identical recapture, and passing version/FK-census doctor rows"
  pair_live_ownership_finish_leg
  pass "$cell_engine pair, schemas, containers, volumes, and site roots were removed under its engine-bound lease"
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

pair_live_ownership_complete '✔ REGRESS_CORE_SCOPE_DATABASE PASSED'
