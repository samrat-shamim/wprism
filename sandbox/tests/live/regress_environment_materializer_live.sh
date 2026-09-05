#!/usr/bin/env bash
# issue #3324 live product-path regression.
#
# This exercises only `wprism env materialize` and `wprism env reap` for lifecycle
# transitions.  A machine-local fixture provider owns the physical pair
# resources: source snapshot prepare/create/read/abort, target restore and
# repository materialization, URL/TTL receipts, exclusive mutation fences,
# and exact attach/create cleanup.  The fixture is deliberately generic: it
# handles opaque database/media/repository bytes and never reads WordPress
# entities or plugin semantics.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/lib/pair_db.sh"
pair_db_select_engine
[ "$WPRISM_DB_ENGINE" = mariadb ] \
  || fail "environment materializer live evidence requires MariaDB; got WPRISM_DB_ENGINE=$WPRISM_DB_ENGINE"

PAIR=environment-materializer
PORT1=9100
PORT2=9101
SOURCE_CONTAINER="wprism-${PAIR}-wp1-1"
TARGET_CONTAINER="wprism-${PAIR}-wp2-1"
SITE1="$ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$ROOT/sandbox/siterepo/${PAIR}2"
WPRISM="$ROOT/cli/wprism"
PROVIDER="$ROOT/sandbox/tests/fixtures/environment-materializer-live-provider.php"
DRIVER_COMPOSE="$ROOT/sandbox/tests/fixtures/environment-materializer-live-driver.yml"
DRIVER_DOCKERFILE="$ROOT/sandbox/tests/fixtures/environment-materializer-live-cli.Dockerfile"
PHP_BIN="$(command -v php)"
IMAGE="environment-materializer-live-${PAIR}:fixture"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/environment-materializer-live.XXXXXX")"
ENVS="$TMP/envs.json"
PROVIDER_CONFIG="$TMP/provider.json"
CONTROLLER="$TMP/controller"
STATE="$TMP/provider-state"
PAIR_OWNED=0
IMAGE_OWNED=0

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }

source_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T source wp "$@"; }
target_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T target wp "$@"; }

# `pair.sh list` deliberately reports bare pair names (`  - foo`), not
# Docker project names.  Keep this parser strict so a leaked/other-owner pair
# can never be mistaken for absent merely because its project is `wprism-foo`.
pair_list_has_exact() { # pair_list_has_exact <bare-pair-name>; reads list on stdin
  local pair=$1
  grep -Eq "^[[:space:]]*-[[:space:]]*${pair}[[:space:]]*$"
}

assert_pair_list_parser() {
  local sample near
  sample=$'== live sandbox pairs ==\n  - environment-materializer\n== stopped pairs ==\n  - r3b'
  near=$'  - wprism-environment-materializer\n  - environment-materializer-zero'
  pair_list_has_exact "$PAIR" <<<"$sample" || fail "pair-list parser missed its exact live/stopped entry"
  if pair_list_has_exact "$PAIR" <<<"$near"; then
    fail "pair-list parser accepts a Docker project prefix or pair-name prefix"
  fi
}

owned_pair_unpause() {
  local container paused
  for container in "$SOURCE_CONTAINER" "$TARGET_CONTAINER"; do
    docker inspect "$container" >/dev/null 2>&1 || continue
    paused="$(docker inspect --format '{{.State.Paused}}' "$container")" || return 1
    case "$paused" in
      false) ;;
      true)
        printf 'fixture cleanup: unpausing exact owned container %s\n' "$container" >&2
        docker unpause "$container" >/dev/null || return 1
        ;;
      *) return 1 ;;
    esac
  done
}

assert_owned_pair_unpaused() {
  local container paused
  for container in "$SOURCE_CONTAINER" "$TARGET_CONTAINER"; do
    docker inspect "$container" >/dev/null 2>&1 || continue
    paused="$(docker inspect --format '{{.State.Paused}}' "$container")" || return 1
    [ "$paused" = false ] || return 1
  done
}

cleanup() {
  local status=$?
  trap - EXIT INT TERM
  set +e
  if [ "$PAIR_OWNED" -eq 1 ]; then
    # A failed freeze must never strand either web runtime paused.  Only these
    # exact pair containers are touched; the shared DB is intentionally never
    # paused or unpaused by this fixture.
    owned_pair_unpause || status=1
    assert_owned_pair_unpaused || status=1
    bash "$ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >/dev/null 2>&1 || status=1
    rm -rf -- "$SITE1" "$SITE2" || status=1
  fi
  if [ "$IMAGE_OWNED" -eq 1 ]; then docker image rm "$IMAGE" >/dev/null 2>&1 || true; fi
  # The provider publishes immutable snapshot evidence read-only.  This
  # temporary tree is exclusively this script's mktemp allocation, so restore
  # owner write bits before removing it on either success or failure.
  if [ -d "$TMP" ]; then chmod -R u+w "$TMP" >/dev/null 2>&1 || true; fi
  rm -rf -- "$TMP" || status=1
  # The final list is evidence that this script did not leave its pair alive.
  local list
  list="$(bash "$ROOT/sandbox/bin/pair.sh" list 2>&1)" || status=1
  if pair_list_has_exact "$PAIR" <<<"$list"; then
    printf 'FAIL: cleanup left pair %s running:\n%s\n' "$PAIR" "$list" >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# Zero-allocation parser regression for the safety boundary above.  It is kept
# opt-in so normal live runs exercise the same self-check in their preflight.
if [ "${WPRISM_ENVIRONMENT_MATERIALIZER_SELF_TEST_PAIR_PARSER:-0}" = 1 ]; then
  assert_pair_list_parser
  pass "exact bare pair-list parser rejects project/prefix lookalikes"
  exit 0
fi

extract_final_json() { # extract_final_json <mixed-output-file> <receipt-file>
  php -r '
    $raw=file_get_contents($argv[1]);
    $start=strrpos($raw, "\n{");
    if ($start === false) $start = ($raw !== "" && $raw[0] === "{") ? -1 : false;
    if ($start === false) { fwrite(STDERR,"no final JSON object\n"); exit(1); }
    $json=substr($raw,$start + 1);
    $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($value)) { fwrite(STDERR,"final JSON is not an object\n"); exit(1); }
    file_put_contents($argv[2], json_encode($value, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n");
  ' "$1" "$2" || fail "could not extract final JSON receipt from $1"
}

run_wprism_json() { # run_wprism_json <label> <receipt-file> <wprism args...>
  local label=$1 receipt=$2
  shift 2
  local output="$TMP/${label}.out"
  if ! (cd "$CONTROLLER" && "$WPRISM" --envs-file="$ENVS" "$@") >"$output" 2>&1; then
    cat "$output" >&2
    if [ -f "$STATE/provider-errors.log" ]; then
      printf '%s\n' 'fixture provider diagnostic (never product output):' >&2
      cat "$STATE/provider-errors.log" >&2
    fi
    # Failure-only fixture evidence: it names protocol phases, never raw
    # provider payloads or WordPress data.  This keeps a redacted product
    # boundary debuggable without changing its public output contract.
    if [ -f "$STATE/actions.ndjson" ]; then
      printf '%s\n' 'fixture provider actions before failure:' >&2
      provider_actions >&2
    fi
    if [ -n "${JOURNAL:-}" ] && [ -d "$JOURNAL/runs" ]; then
      printf '%s\n' 'materializer phases before failure:' >&2
      find "$JOURNAL/runs" -path '*/events/*.json' -type f -print0 \
        | xargs -0 -n1 basename | sed 's/^[0-9]*-//' | sed 's/\.json$//' >&2 || true
    fi
    fail "$label failed"
  fi
  extract_final_json "$output" "$receipt"
}

assert_receipt() { # assert_receipt <path> <format> <mode-or-disposition>
  php -r '
    $r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($r) || ($r["format"] ?? null) !== $argv[2]) { fwrite(STDERR,"wrong receipt format\n"); exit(1); }
    if (($r["mode"] ?? $r["disposition"] ?? null) !== $argv[3]) { fwrite(STDERR,"wrong receipt mode/disposition\n"); exit(1); }
    foreach (["operation_id","receipt_sha256"] as $key) if (!is_string($r[$key] ?? null) || $r[$key] === "") exit(1);
  ' "$1" "$2" "$3" || fail "invalid receipt $(basename "$1")"
}

source_dump_hash() {
  local dump="$TMP/source-dump-$1.sql"
  docker exec "$DB_CONTAINER" mariadb-dump --single-transaction --skip-comments --skip-dump-date -uroot -proot "wp_${PAIR}1" >"$dump"
  shasum -a 256 "$dump" | awk '{print $1}'
}

provider_actions() {
  php -r '
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $row=json_decode($line,true); if (is_array($row)) echo ($row["action"] ?? ""), "\n";
    }
  ' "$STATE/actions.ndjson"
}

assert_snapshot_evidence() { # <materialize-receipt>
  php -r '
    $receipt=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $state=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    $journal=$argv[3];
    $op=$receipt["operation_id"] ?? null;
    if (!is_string($op) || !is_array($state["snapshots"] ?? null)) exit(1);
    $snapshot=null;
    foreach ($state["snapshots"] as $candidate) if (is_array($candidate) && ($candidate["snapshot_set_id"] ?? null) === ($receipt["snapshot_set_id"] ?? null)) $snapshot=$candidate;
    if (!is_array($snapshot) || !preg_match("/^[a-f0-9]{64}$/", (string)($snapshot["database_sha256"] ?? ""))
        || !preg_match("/^[a-f0-9]{64}$/", (string)($snapshot["media_sha256"] ?? ""))
        || !preg_match("/^[a-f0-9]{64}$/", (string)($snapshot["semantic_snapshot_sha256"] ?? ""))
        || ($snapshot["snapshot_set_receipt_sha256"] ?? null) !== ($receipt["snapshot_set_receipt_sha256"] ?? null)) exit(1);
    $events=glob($journal . "/runs/" . $op . "/events/*.json"); sort($events);
    $created=$read=null;
    foreach ($events as $path) { $event=json_decode(file_get_contents($path),true); if (($event["event"] ?? null) === "snapshot-created") $created=$event["data"]; if (($event["event"] ?? null) === "snapshot-read") $read=$event["data"]; }
    foreach (["database_sha256","media_sha256","semantic_snapshot_sha256","snapshot_set_id","snapshot_set_receipt_sha256"] as $key)
      if (!is_array($created) || !is_array($read) || ($created[$key] ?? null) !== ($read[$key] ?? null) || ($created[$key] ?? null) !== ($snapshot[$key] ?? null)) exit(1);
    if (($read["immutable"] ?? null) !== true) exit(1);
  ' "$1" "$STATE/state.json" "$JOURNAL" || fail "snapshot set/readback evidence is not coherent and immutable"
}

assert_promotion_release_evidence() { # <materialize-receipt>
  php -r '
    $receipt=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $journal=$argv[2]; $op=$receipt["operation_id"] ?? null;
    if (!is_string($op) || !preg_match("/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/",$op)) exit(1);
    foreach (["outer_artifact_hash","state_revision","promotion_receipt_sha256","checkpoint_identity"] as $key)
      if (!is_string($receipt[$key] ?? null) || !preg_match("/^[a-f0-9]{64}$/",$receipt[$key])) exit(1);
    if (!array_key_exists("code_revision",$receipt) || ($receipt["code_revision"] !== null && (!is_string($receipt["code_revision"]) || !preg_match("/^[a-f0-9]{64}$/",$receipt["code_revision"])))) exit(1);
    $events=[];
    foreach (glob($journal . "/runs/" . $op . "/events/*.json") ?: [] as $path) {
      $event=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
      if (is_array($event) && is_string($event["event"] ?? null)) $events[$event["event"]]=$event["data"] ?? null;
    }
    $release=["outer_artifact_hash"=>$receipt["outer_artifact_hash"],"state_revision"=>$receipt["state_revision"],"code_revision"=>$receipt["code_revision"]];
    foreach (["release-compiled","release-verified","release-converged"] as $phase) {
      $data=$events[$phase] ?? null;
      if (!is_array($data)) exit(1);
      foreach ($release as $key=>$value) if (!array_key_exists($key,$data) || $data[$key] !== $value) exit(1);
    }
    $promotion=$events["promotion-applied"] ?? null;
    if (!is_array($promotion)
      || ($promotion["format"] ?? null) !== "wprism-branch-environment-promotion-receipt/v1"
      || ($promotion["status"] ?? null) !== "completed"
      || !is_string($promotion["owner"] ?? null) || $promotion["owner"] === ""
      || ($promotion["operation_id"] ?? null) !== $op
      || ($promotion["artifact_hash"] ?? null) !== $receipt["outer_artifact_hash"]
      || ($promotion["state_revision"] ?? null) !== $receipt["state_revision"]
      || !array_key_exists("code_revision",$promotion) || $promotion["code_revision"] !== $receipt["code_revision"]
      || ($promotion["receipt_sha256"] ?? null) !== $receipt["promotion_receipt_sha256"]
      || ($promotion["checkpoint_identity"] ?? null) !== $receipt["checkpoint_identity"]) exit(1);
  ' "$1" "$JOURNAL" || fail "promotion/release journal evidence is not bound to the final receipt"
}

assert_preparing_snapshot_abort() {
  local operation session lease_id lease_receipt staging request response
  operation=20260809-000000-000000000000000000000001
  session="snapshot-session-preparing-${PAIR}"
  lease_id="snapshot-lease-preparing-${PAIR}"
  lease_receipt="$(printf '%s' "${operation}|${session}|${lease_id}" | shasum -a 256 | awk '{print $1}')"
  staging="$STATE/prepared/preparing-abort-${PAIR}"
  request="$TMP/preparing-abort-request.json"
  response="$TMP/preparing-abort-response.json"
  mkdir -p "$staging"
  "$PHP_BIN" -r '
    $state=["fences"=>[],"resources"=>[],"sessions"=>[],"snapshots"=>[],"ttls"=>[]];
    $state["sessions"][$argv[2]]=[
      "lease_generation"=>1,"lease_id"=>$argv[4],"lease_receipt_sha256"=>$argv[5],"path"=>$argv[6],
      "snapshot_session_id"=>$argv[3],"source_identity"=>$argv[7],"source_paused"=>true,"state"=>"preparing"
    ];
    $bytes=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($argv[1],$bytes,LOCK_EX)!==strlen($bytes)) exit(1);
  ' "$STATE/state.json" "production|$operation" "$session" "$lease_id" "$lease_receipt" "$staging" "source-environment-${PAIR}" \
    || fail "could not persist preparing-session recovery fixture"
  docker pause "$SOURCE_CONTAINER" >/dev/null
  [ "$(docker inspect --format '{{.State.Paused}}' "$SOURCE_CONTAINER")" = true ] \
    || fail "could not establish preparing-session source pause"
  "$PHP_BIN" -r '
    $input=[
      "expected_snapshot_session_id"=>$argv[2],"expected_source_identity"=>$argv[3],
      "expected_source_lease_generation"=>1,"expected_source_lease_id"=>$argv[4],
      "expected_source_lease_receipt_sha256"=>$argv[5]
    ];
    echo json_encode(["action"=>"snapshot-abort","environment"=>"production","format"=>"wprism-branch-environment-provider-request/v2","input"=>$input,"operation_id"=>$argv[1]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
  ' "$operation" "$session" "source-environment-${PAIR}" "$lease_id" "$lease_receipt" >"$request"
  "$PHP_BIN" "$PROVIDER" "$PROVIDER_CONFIG" <"$request" >"$response" \
    || fail "provider did not abort the exact preparing source session"
  "$PHP_BIN" -r '
    $response=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $result=$response["result"] ?? null;
    if (($response["status"] ?? null)!=="ok" || !is_array($result) || ($result["disposition"] ?? null)!=="aborted"
      || ($result["snapshot_session_id"] ?? null)!==$argv[2] || ($result["lease_id"] ?? null)!==$argv[3]
      || ($result["lease_receipt_sha256"] ?? null)!==$argv[4] || ($result["source_identity"] ?? null)!==$argv[5]) exit(1);
  ' "$response" "$session" "$lease_id" "$lease_receipt" "source-environment-${PAIR}" \
    || fail "preparing-session abort receipt lost its exact identity/lease binding"
  assert_owned_pair_unpaused || fail "preparing-session abort left a web runtime paused"
  [ ! -e "$staging" ] || fail "preparing-session abort left its owned staging path behind"
  pass "exact preparing-session abort unpauses only its source runtime"
}

say "static preflight before allocating pair resources"
php -l "$PROVIDER" >/dev/null || fail "provider PHP syntax failed"
bash -n "$0" || fail "live harness shell syntax failed"
bash -n "$ROOT/sandbox/bin/pair.sh" || fail "pair lifecycle shell syntax failed"
git diff --check || fail "working tree has whitespace errors"
! grep -Fq 'WPRISM_''MANIFESTS_DIR' "$DRIVER_COMPOSE" \
  || fail "fixture driver reintroduced process-global adapter-library selection"
grep -Fq '${WPRISM_ENVIRONMENT_MATERIALIZER_ADAPTER_PACKAGES_SRC}:/var/www/html/wp-content/mu-plugins/adapter-packages:ro' "$DRIVER_COMPOSE" \
  || fail "fixture driver does not mount packaged adapters beside the agent"
grep -Fq '${WPRISM_ENVIRONMENT_MATERIALIZER_PLATFORM_SRC}:/var/www/html/wp-content/mu-plugins/platform:ro' "$DRIVER_COMPOSE" \
  || fail "fixture driver does not mount the platform contract beside the agent"
assert_pair_list_parser
if docker image inspect "$IMAGE" >/dev/null 2>&1; then
  fail "fixture image '$IMAGE' already exists; refusing to overwrite or delete an image not created by this run"
fi
pass "shell/PHP/static checks pass before pair creation"

# Never take over an occupied namespace. Pair directories are likewise
# intentionally rejected rather than reset: only the creator may destroy it.
PAIR_LIST="$(bash "$ROOT/sandbox/bin/pair.sh" list)" || fail "could not inspect pair budget/state"
if pair_list_has_exact "$PAIR" <<<"$PAIR_LIST"; then
  fail "owned pair name '$PAIR' is already live; refusing to touch it"
fi
if [ -e "$SITE1" ] || [ -e "$SITE2" ]; then
  fail "pair site-repository paths already exist; refusing a destructive reset of $PAIR"
fi

say "bring up exactly the authorized disposable pair ${PAIR} on ${PORT1}/${PORT2}"
PAIR_OWNED=1
bash "$ROOT/sandbox/bin/pair.sh" up "$PAIR" "$PORT1" "$PORT2" --http
pass "pair is ready and owns independent wp_${PAIR}1/wp_${PAIR}2 databases"

say "build the Git-capable ephemeral DockerTransport image"
IMAGE_OWNED=1
docker build -q -t "$IMAGE" -f "$DRIVER_DOCKERFILE" "$ROOT" >/dev/null
export WPRISM_ENVIRONMENT_MATERIALIZER_DRIVER_IMAGE="$IMAGE"
export WPRISM_ENVIRONMENT_MATERIALIZER_PAIR="$PAIR"
export WPRISM_ENVIRONMENT_MATERIALIZER_AGENT_SRC="$ROOT/agent"
export WPRISM_ENVIRONMENT_MATERIALIZER_ADAPTER_PACKAGES_SRC="$ROOT/adapter-packages"
export WPRISM_ENVIRONMENT_MATERIALIZER_PLATFORM_SRC="$ROOT/platform"
export WPRISM_ENVIRONMENT_MATERIALIZER_SITE1="$SITE1"
export WPRISM_ENVIRONMENT_MATERIALIZER_SITE2="$SITE2"
docker compose -f "$DRIVER_COMPOSE" config >/dev/null || fail "fixture driver compose configuration is invalid"
source_wp core is-installed >/dev/null || fail "source custom DockerTransport is not reachable"
target_wp core is-installed >/dev/null || fail "target custom DockerTransport is not reachable"
pass "public Docker drivers use current worktree agent code and independent pair volumes"

say "seed independent source repository, authored state, media, and runtime code sentinel"
cat >"$SITE1/site.wprism.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp "$ROOT/sandbox/site-repo.gitignore.template" "$SITE1/.gitignore"
git -C "$SITE1" init -q -b production
git -C "$SITE1" config user.name 'issue #3324 live fixture'
git -C "$SITE1" config user.email 'environment-materializer@example.invalid'
# Pair bootstrap creates WordPress's mutable sample post/pages independently
# on each side.  Remove only that source fixture residue before capture so
# the test asserts its own authored record rather than timestamp-dependent
# installer content; the target remains independently initialized until the
# provider restores the source's immutable physical snapshot.
SOURCE_BOOTSTRAP_POSTS="$(source_wp post list --post_type=post,page --post_status=any --format=ids)"
if [ -n "$SOURCE_BOOTSTRAP_POSTS" ]; then
  source_wp post delete $SOURCE_BOOTSTRAP_POSTS --force >/dev/null
fi
source_wp option delete wp_page_for_privacy_policy >/dev/null 2>&1 || true
SOURCE_POST_ID="$(source_wp post create --post_title='issue #3324 coherent source' --post_content='immutable source truth' --post_status=publish --porcelain)"
docker exec "$SOURCE_CONTAINER" sh -c 'mkdir -p /var/www/html/wp-content/uploads/environment-materializer && printf source-media-environment-materializer > /var/www/html/wp-content/uploads/environment-materializer/source-media.txt && printf source-only-code-environment-materializer > /var/www/html/wp-content/plugins/environment-materializer-source-only.php'
source_wp wprism capture --repo=/siterepo --format=json >/dev/null
git -C "$SITE1" add -A
git -C "$SITE1" commit -qm 'production source captured'
SOURCE_HEAD="$(git -C "$SITE1" rev-parse HEAD)"
SOURCE_DB_BEFORE="$(source_dump_hash before)"
SOURCE_MEDIA_BEFORE="$(docker exec "$SOURCE_CONTAINER" sha256sum /var/www/html/wp-content/uploads/environment-materializer/source-media.txt | awk '{print $1}')"
git clone -q --no-hardlinks "$SITE1" "$CONTROLLER"
git -C "$CONTROLLER" switch -q -c feature
git -C "$CONTROLLER" remote remove origin
JOURNAL="$(git -C "$CONTROLLER" rev-parse --path-format=absolute --git-common-dir)/wprism-environments"
pass "source and controller repositories have exact independent Git storage at $SOURCE_HEAD"

mkdir -p "$STATE"
cat >"$PROVIDER_CONFIG" <<EOF
{
  "controller_repo": "$CONTROLLER",
  "db_container": "$DB_CONTAINER",
  "driver_compose": "$DRIVER_COMPOSE",
  "source_environment": "production",
  "state_root": "$STATE",
  "environments": {
    "production": {
      "container": "$SOURCE_CONTAINER",
      "database": "wp_${PAIR}1",
      "environment_identity": "source-environment-${PAIR}",
      "lease_id": "source-lease-${PAIR}",
      "repo": "$SITE1",
      "resource_id": "source-resource-${PAIR}",
      "role": "source",
      "service": "source",
      "url": "http://localhost:${PORT1}"
    },
    "branchattach": {
      "container": "$TARGET_CONTAINER",
      "database": "wp_${PAIR}2",
      "environment_identity": "attach-environment-${PAIR}",
      "lease_id": "attach-lease-${PAIR}",
      "repo": "$SITE2",
      "resource_id": "attach-resource-${PAIR}",
      "role": "target",
      "service": "target",
      "url": "http://localhost:${PORT2}"
    },
    "branchcreate": {
      "container": "$TARGET_CONTAINER",
      "database": "wp_${PAIR}2",
      "environment_identity": "create-environment-${PAIR}",
      "lease_id": "create-lease-${PAIR}",
      "repo": "$SITE2",
      "resource_id": "create-resource-${PAIR}",
      "role": "target",
      "service": "target",
      "url": "http://localhost:${PORT2}"
    }
  }
}
EOF
cat >"$ENVS" <<EOF
{
  "envs": {
    "production": {
      "transport": "docker",
      "compose_file": "$DRIVER_COMPOSE",
      "service": "source",
      "repo_path": "/siterepo",
      "environment_provider": {"command": ["$PHP_BIN", "$PROVIDER", "$PROVIDER_CONFIG"], "timeout_seconds": 60}
    },
    "branchattach": {
      "transport": "docker",
      "compose_file": "$DRIVER_COMPOSE",
      "service": "target",
      "repo_path": "/siterepo",
      "environment_provider": {"command": ["$PHP_BIN", "$PROVIDER", "$PROVIDER_CONFIG"], "timeout_seconds": 60}
    },
    "branchcreate": {
      "transport": "docker",
      "compose_file": "$DRIVER_COMPOSE",
      "service": "target",
      "repo_path": "/siterepo",
      "environment_provider": {"command": ["$PHP_BIN", "$PROVIDER", "$PROVIDER_CONFIG"], "timeout_seconds": 60}
    }
  }
}
EOF

say "recover a crash-boundary preparing source session without leaving it paused"
assert_preparing_snapshot_abort

say "materialize attached target exclusively through the public CLI"
ATTACH_RECEIPT="$TMP/attach.json"
run_wprism_json attach "$ATTACH_RECEIPT" env materialize branchattach --from production --branch feature --format=json
assert_receipt "$ATTACH_RECEIPT" wprism-branch-environment-receipt/v1 attach
assert_snapshot_evidence "$ATTACH_RECEIPT"
assert_promotion_release_evidence "$ATTACH_RECEIPT"
[ "$(target_wp post get "$SOURCE_POST_ID" --field=post_title)" = 'issue #3324 coherent source' ] \
  || fail "target did not converge to the source semantic state"
[ "$(docker exec "$TARGET_CONTAINER" sha256sum /var/www/html/wp-content/uploads/environment-materializer/source-media.txt | awk '{print $1}')" = "$SOURCE_MEDIA_BEFORE" ] \
  || fail "target did not receive the exact provider-owned media snapshot"
[ "$(source_dump_hash after)" = "$SOURCE_DB_BEFORE" ] \
  || fail "source database changed during materialization"
[ "$(docker exec "$SOURCE_CONTAINER" sha256sum /var/www/html/wp-content/uploads/environment-materializer/source-media.txt | awk '{print $1}')" = "$SOURCE_MEDIA_BEFORE" ] \
  || fail "source media changed during materialization"
target_wp eval 'exit(file_exists(ABSPATH . "wp-content/plugins/environment-materializer-source-only.php") ? 1 : 0);' \
  || fail "target runtime received a source checkout code bind"
TARGET_SITEREPO_MOUNT="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/siterepo"}}{{.Source}}{{end}}{{end}}' "$TARGET_CONTAINER")"
[ "$TARGET_SITEREPO_MOUNT" = "$SITE2" ] && ! grep -Fq "$SITE1" <<<"$TARGET_SITEREPO_MOUNT" \
  || fail "target runtime /siterepo mount is not its independent target storage"
if ! (cd "$CONTROLLER" && "$WPRISM" --envs-file="$ENVS" status branchattach) >"$TMP/status.out" 2>&1; then
  cat "$TMP/status.out" >&2
  fail "materialized target is not converged under public wprism status"
fi
curl -fsSL --max-time 20 -o "$TMP/target.html" "http://127.0.0.1:${PORT2}/" || fail "target HTTP endpoint did not serve after URL restore"
[ -s "$TMP/target.html" ] || fail "target HTTP endpoint returned no body"
pass "coherent snapshot ids/hashes, target convergence, source immutability, and independent target code/repo storage proven"

say "reap attached target exclusively through the public CLI"
ATTACH_REAP="$TMP/attach-reap.json"
run_wprism_json attach-reap "$ATTACH_REAP" env reap branchattach --format=json
assert_receipt "$ATTACH_REAP" wprism-branch-environment-reap/v1 detached
grep -Fxq detach <<<"$(provider_actions)" || fail "attached target was not detached by the provider"
if grep -Fxq destroy <<<"$(provider_actions)"; then fail "attached target was incorrectly destroyed"; fi
[ "$(source_dump_hash after-attach-reap)" = "$SOURCE_DB_BEFORE" ] \
  || fail "source database changed during attached target reap"
[ "$(docker exec "$SOURCE_CONTAINER" sha256sum /var/www/html/wp-content/uploads/environment-materializer/source-media.txt | awk '{print $1}')" = "$SOURCE_MEDIA_BEFORE" ] \
  || fail "source media changed during attached target reap"
pass "attach cleanup is an exact detach, never destroy"

say "publish a TTL, prove changed-TTL reap refusal, then restore exact lease and detach"
TTL_RECEIPT="$TMP/ttl.json"
run_wprism_json ttl "$TTL_RECEIPT" env materialize branchattach --from production --branch feature --ttl 600 --format=json
assert_receipt "$TTL_RECEIPT" wprism-branch-environment-receipt/v1 attach
assert_snapshot_evidence "$TTL_RECEIPT"
assert_promotion_release_evidence "$TTL_RECEIPT"
TTL_ACTIONS_BEFORE="$(provider_actions | wc -l | tr -d ' ')"
"$PHP_BIN" "$PROVIDER" --mutate-ttl "$PROVIDER_CONFIG" branchattach
if TTL_REFUSAL="$(cd "$CONTROLLER" && "$WPRISM" --envs-file="$ENVS" env reap branchattach --format=json 2>&1)"; then
  fail "reap accepted a changed TTL lease"
fi
TTL_ACTIONS_AFTER="$(provider_actions | sed -n "$((TTL_ACTIONS_BEFORE + 1)),\$p")"
grep -Fxq ttl-read <<<"$TTL_ACTIONS_AFTER" \
  || fail "changed TTL reap did not reach the provider TTL readback boundary"
if grep -Exq 'detach|destroy' <<<"$TTL_ACTIONS_AFTER"; then
  fail "TTL mismatch reached a destructive cleanup action"
fi
"$PHP_BIN" "$PROVIDER" --restore-ttl "$PROVIDER_CONFIG" branchattach
TTL_REAP="$TMP/ttl-reap.json"
run_wprism_json ttl-reap "$TTL_REAP" env reap branchattach --format=json
assert_receipt "$TTL_REAP" wprism-branch-environment-reap/v1 detached
pass "TTL generation change refuses before cleanup; restored exact receipt permits detach"

say "materialize an explicit created target and prove exact destroy cleanup"
CREATE_RECEIPT="$TMP/create.json"
run_wprism_json create "$CREATE_RECEIPT" env materialize branchcreate --from production --branch feature --create --format=json
assert_receipt "$CREATE_RECEIPT" wprism-branch-environment-receipt/v1 create
assert_snapshot_evidence "$CREATE_RECEIPT"
assert_promotion_release_evidence "$CREATE_RECEIPT"
grep -Fxq create <<<"$(provider_actions)" || fail "explicit create never reached provider create"
CREATE_REAP="$TMP/create-reap.json"
run_wprism_json create-reap "$CREATE_REAP" env reap branchcreate --format=json
assert_receipt "$CREATE_REAP" wprism-branch-environment-reap/v1 destroyed
grep -Fxq destroy <<<"$(provider_actions)" || fail "created target was not destroyed by the provider"
if docker exec "$DB_CONTAINER" mariadb -N -uroot -proot "wp_${PAIR}2" -e 'SHOW TABLES' | grep -q .; then
  fail "provider destroy left target database tables behind"
fi
[ -d "$SITE2" ] && [ -z "$(find "$SITE2" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "provider destroy left created target repository bytes behind"
pass "explicit create is distinct from attach and destroy clears only its owned target resource"

say "final exact pair teardown and resource audit"
assert_owned_pair_unpaused || fail "fixture left an owned source or target web container paused"
bash "$ROOT/sandbox/bin/pair.sh" destroy "$PAIR"
PAIR_OWNED=0
rm -rf -- "$SITE1" "$SITE2"
FINAL_LIST="$(bash "$ROOT/sandbox/bin/pair.sh" list)"
if pair_list_has_exact "$PAIR" <<<"$FINAL_LIST"; then
  fail "final pair audit still lists $PAIR"
fi
pass "all pair containers, volumes, databases, site repositories, fixture state, and image are cleanup-owned"

printf '\nPASS: issue #3324 live public CLI environment materialization regression\n'
