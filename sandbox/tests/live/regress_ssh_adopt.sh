#!/usr/bin/env bash
# issue #3281/issue #3344: prove the product adoption path and scoped-promotion
# checkpoint recovery against a standalone WordPress host reached only over
# SSH. This deliberately uses docker run, not compose, pair.sh, shared volumes
# with the source checkout, or docker exec for any product operation. Docker is
# only the disposable host boundary; every install/verification action after
# boot travels through cli/wprism's SSH path.
#
# Run only from a clean standalone candidate clone, with explicitly allocated
# resources. Adapter capsules may reuse this exact host/provider setup by
# setting WPRISM_SSH_ADOPT_EXTENSION to one tracked
# adapter-packages/<slug>/tests/live/*.sh file that defines
# wprism_ssh_adopt_extension(). The extension runs after the shared scoped
# rollback proof and before label-verified cleanup; it is not a product hook.
#
#   make regress-ssh-adopt ADOPT_FIXTURE=<unique-name> ADOPT_SSH_PORT=<free-port> \
#     WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd -P)"
cd "$ROOT"

PREFIX="${ADOPT_FIXTURE:-}"
PORT_RAW="${ADOPT_SSH_PORT:-}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"
SOURCE_SHA=""
AGENT_VERSION=""
NET="${PREFIX}-net"
DB="${PREFIX}-db"
TARGET="${PREFIX}-target"
VOLUME="${PREFIX}-wordpress"
IMAGE="${PREFIX}-ssh-image"
PORT=""
TMP=""
DIAG_DIR=""
WPRISM="$ROOT/cli/wprism"
SUITE_LABEL="${WPRISM_SSH_SUITE_LABEL:-regress-ssh-adopt}"
FINAL_LABEL="${WPRISM_SSH_FINAL_LABEL:-REGRESS_SSH_ADOPT}"
EXTENSION="${WPRISM_SSH_ADOPT_EXTENSION:-}"
RUN_ID=""
BODY_COMPLETE=0
IMAGE_OWNED=0
NETWORK_OWNED=0
VOLUME_OWNED=0
DATABASE_OWNED=0
TARGET_OWNED=0
SCOPED_PLAN_STDOUT=""
SCOPED_PLAN_STDERR=""
SCOPED_PLAN_EXIT=""
SCOPED_REFRESH_STDOUT=""
SCOPED_REFRESH_STDERR=""
SCOPED_REFRESH_EXIT=""
SCOPED_PROMOTE_STDOUT=""
SCOPED_PROMOTE_STDERR=""
SCOPED_PROMOTE_EXIT=""
SCOPED_SUCCESS_PROMOTE_STDOUT=""
SCOPED_SUCCESS_PROMOTE_STDERR=""
SCOPED_SUCCESS_PROMOTE_EXIT=""
AUTHORITY_STATUS_STDOUT=""
AUTHORITY_STATUS_STDERR=""
AUTHORITY_STATUS_EXIT=""

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

resource_has_our_labels() {
  local kind="$1" name="$2" labels=""
  case "$kind" in
    container)
      labels="$(docker container inspect --format '{{index .Config.Labels "wprism.live-suite"}}|{{index .Config.Labels "wprism.live-run"}}|{{index .Config.Labels "wprism.live-source"}}' "$name" 2>/dev/null)" || return 1
      ;;
    network)
      labels="$(docker network inspect --format '{{index .Labels "wprism.live-suite"}}|{{index .Labels "wprism.live-run"}}|{{index .Labels "wprism.live-source"}}' "$name" 2>/dev/null)" || return 1
      ;;
    volume)
      labels="$(docker volume inspect --format '{{index .Labels "wprism.live-suite"}}|{{index .Labels "wprism.live-run"}}|{{index .Labels "wprism.live-source"}}' "$name" 2>/dev/null)" || return 1
      ;;
    image)
      labels="$(docker image inspect --format '{{index .Config.Labels "wprism.live-suite"}}|{{index .Config.Labels "wprism.live-run"}}|{{index .Config.Labels "wprism.live-source"}}' "$name" 2>/dev/null)" || return 1
      ;;
    *)
      return 1
      ;;
  esac
  [ "$labels" = "$SUITE_LABEL|$RUN_ID|$SOURCE_SHA" ]
}

cleanup_container() {
  local owned="$1" name="$2"
  [ "$owned" -eq 1 ] || return 0
  resource_has_our_labels container "$name" \
    || { printf 'preserving container %s: ownership labels no longer match this run\n' "$name" >&2; return 1; }
  docker rm -f "$name" >/dev/null \
    || { printf 'could not remove owned container %s\n' "$name" >&2; return 1; }
  ! docker container inspect "$name" >/dev/null 2>&1 \
    || { printf 'owned container %s remains after cleanup\n' "$name" >&2; return 1; }
}

cleanup_resource() {
  local kind="$1" owned="$2" name="$3"
  local -a remove=()
  [ "$owned" -eq 1 ] || return 0
  resource_has_our_labels "$kind" "$name" \
    || { printf 'preserving %s %s: ownership labels no longer match this run\n' "$kind" "$name" >&2; return 1; }
  case "$kind" in
    network) remove=(docker network rm "$name") ;;
    volume)  remove=(docker volume rm "$name") ;;
    image)   remove=(docker image rm "$name") ;;
    *) return 1 ;;
  esac
  "${remove[@]}" >/dev/null \
    || { printf 'could not remove owned %s %s\n' "$kind" "$name" >&2; return 1; }
  case "$kind" in
    network) ! docker network inspect "$name" >/dev/null 2>&1 ;;
    volume)  ! docker volume inspect "$name" >/dev/null 2>&1 ;;
    image)   ! docker image inspect "$name" >/dev/null 2>&1 ;;
  esac || { printf 'owned %s %s remains after cleanup\n' "$kind" "$name" >&2; return 1; }
}

cleanup() {
  local incoming=$? cleanup_failed=0
  trap - EXIT
  cleanup_container "$TARGET_OWNED" "$TARGET" || cleanup_failed=1
  cleanup_container "$DATABASE_OWNED" "$DB" || cleanup_failed=1
  cleanup_resource network "$NETWORK_OWNED" "$NET" || cleanup_failed=1
  cleanup_resource volume "$VOLUME_OWNED" "$VOLUME" || cleanup_failed=1
  cleanup_resource image "$IMAGE_OWNED" "$IMAGE" || cleanup_failed=1
  # TMP contains the one-run SSH key, rollback signing key, and database
  # connection material. It is never diagnostic evidence: erase it on every
  # EXIT path even if an owned Docker resource could not be cleaned up.
  if [ -n "$TMP" ]; then
    rm -rf -- "$TMP" || cleanup_failed=1
    [ ! -e "$TMP" ] && [ ! -L "$TMP" ] || cleanup_failed=1
  fi

  # A green body is not a green live proof until the label-verified fixture
  # cleanup succeeds. Only then may the narrow, non-secret diagnostic record
  # be discarded and the final PASS be published.
  if [ "$BODY_COMPLETE" -eq 1 ] && [ "$incoming" -eq 0 ] && [ "$cleanup_failed" -eq 0 ]; then
    if [ -n "$DIAG_DIR" ]; then
      rm -rf -- "$DIAG_DIR" || cleanup_failed=1
      [ ! -e "$DIAG_DIR" ] && [ ! -L "$DIAG_DIR" ] || cleanup_failed=1
    fi
    if [ "$cleanup_failed" -eq 0 ]; then
      printf '\n\033[1;32m✔ %s PASSED\033[0m\n' "$FINAL_LABEL"
      exit 0
    fi
  fi

  if [ "$cleanup_failed" -ne 0 ]; then
    printf 'FAIL: SSH-adoption fixture cleanup was incomplete; diagnostic evidence retained privately\n' >&2
  fi
  if [ -n "$DIAG_DIR" ]; then
    printf 'FAIL: SSH-adoption diagnostic evidence retained privately at %s\n' "$DIAG_DIR" >&2
  fi
  exit 1
}

for command in git docker lsof php; do
  command -v "$command" >/dev/null 2>&1 || fail "$command is required for SSH-adoption live evidence"
done
[[ "$SUITE_LABEL" =~ ^[a-z][a-z0-9-]{2,63}$ ]] \
  || fail "WPRISM_SSH_SUITE_LABEL must be a lowercase 3..64 character resource label"
[[ "$FINAL_LABEL" =~ ^[A-Z][A-Z0-9_]{2,63}$ ]] \
  || fail "WPRISM_SSH_FINAL_LABEL must be an uppercase 3..64 character result label"
[[ "$PREFIX" =~ ^[a-z][a-z0-9]{2,31}$ ]] \
  || fail "ADOPT_FIXTURE is required and must be a unique lowercase 3..32 character name"
[[ "$PORT_RAW" =~ ^[0-9]+$ ]] \
  || fail "ADOPT_SSH_PORT is required and must be a decimal port"
PORT=$((10#$PORT_RAW))
(( PORT >= 8900 && PORT <= 65535 )) \
  || fail "ADOPT_SSH_PORT must be within the explicit disposable range 8900..65535"
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail "WPRISM_EXPECTED_SOURCE_SHA is required and must be a lowercase 40-character commit SHA"
GIT_DIR="$(git -C "$ROOT" --no-optional-locks rev-parse --path-format=absolute --git-dir 2>/dev/null)" \
  || fail "SSH-adoption live evidence requires a Git checkout"
COMMON_DIR="$(git -C "$ROOT" --no-optional-locks rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
  || fail "could not resolve the Git common directory"
[ "$GIT_DIR" = "$ROOT/.git" ] && [ "$COMMON_DIR" = "$ROOT/.git" ] && [ -d "$ROOT/.git" ] \
  || fail "SSH-adoption live evidence requires a standalone clone, not a linked worktree"
SOURCE_SHA="$(git -C "$ROOT" --no-optional-locks rev-parse --verify 'HEAD^{commit}')" \
  || fail "SSH-adoption live evidence source has no resolvable Git HEAD"
[ "$EXPECTED_SHA" = "$SOURCE_SHA" ] \
  || fail "WPRISM_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal this checkout HEAD=$SOURCE_SHA"
git -C "$ROOT" --no-optional-locks diff --check \
  || fail "SSH-adoption live evidence checkout has unstaged whitespace errors"
git -C "$ROOT" --no-optional-locks diff --cached --check \
  || fail "SSH-adoption live evidence checkout has staged whitespace errors"
SOURCE_STATUS="$(git -C "$ROOT" --no-optional-locks status --porcelain=v1 --untracked-files=all)" \
  || fail "could not inspect SSH-adoption live evidence source cleanliness"
[ -z "$SOURCE_STATUS" ] \
  || fail "SSH-adoption live evidence requires a clean standalone clone at $SOURCE_SHA"
AGENT_VERSION="$(php -r '
  $source = file_get_contents($argv[1]);
  if (!is_string($source)
      || preg_match("/define\\(\\x27WPRISM_AGENT_VERSION\\x27,\\s*\\x27([^\\x27]+)\\x27\\)/", $source, $match) !== 1) {
    exit(1);
  }
  echo $match[1];
' "$ROOT/agent/wprism.php")" \
  || fail "agent/wprism.php does not declare one readable WPRISM_AGENT_VERSION"
[[ "$AGENT_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
  || fail "agent/wprism.php declares a non-semantic WPRISM_AGENT_VERSION"
docker info >/dev/null 2>&1 || fail "Docker daemon is unavailable"
for container in "$TARGET" "$DB"; do
  docker container inspect "$container" >/dev/null 2>&1 \
    && fail "container '$container' already exists; refusing to take ownership"
done
docker network inspect "$NET" >/dev/null 2>&1 \
  && fail "network '$NET' already exists; refusing to take ownership"
docker volume inspect "$VOLUME" >/dev/null 2>&1 \
  && fail "volume '$VOLUME' already exists; refusing to take ownership"
docker image inspect "$IMAGE" >/dev/null 2>&1 \
  && fail "image '$IMAGE' already exists; refusing to take ownership"
if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  fail "ADOPT_SSH_PORT=$PORT is already listening; refusing fixture allocation"
fi
export WPRISM_EXPECTED_SOURCE_SHA="$SOURCE_SHA"
RUN_ID="${PREFIX}-${SOURCE_SHA:0:12}-$$-${RANDOM}${RANDOM}"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${PREFIX}-ssh-adopt.XXXXXX")"
trap cleanup EXIT

# The scoped-promotion leg below used to begin by manufacturing a hermetic
# adapter library and pushing its capabilities/ directory onto the target: the
# generated attestation was candidate/expired on any tree that had not imported
# current subject evidence, so every certified claim carried
# `evidence_not_current` and promotion refused before it could exercise
# anything. That attestation no longer exists, and `wprism adopt` assembles this
# checkout's reviewed package capsules directly inside the staged agent.
# Nothing is manufactured or pushed separately; the product gate is unchanged
# and is exercised where the embedded library lives.

DIAG_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PREFIX}-ssh-adopt-diagnostics.XXXXXX")"
chmod 0700 "$DIAG_DIR"
SCOPED_PLAN_STDOUT="$DIAG_DIR/scoped-plan.stdout"
SCOPED_PLAN_STDERR="$DIAG_DIR/scoped-plan.stderr"
SCOPED_PLAN_EXIT="$DIAG_DIR/scoped-plan.exit"
SCOPED_REFRESH_STDOUT="$DIAG_DIR/scoped-refresh.stdout"
SCOPED_REFRESH_STDERR="$DIAG_DIR/scoped-refresh.stderr"
SCOPED_REFRESH_EXIT="$DIAG_DIR/scoped-refresh.exit"
SCOPED_PROMOTE_STDOUT="$DIAG_DIR/scoped-promote.stdout"
SCOPED_PROMOTE_STDERR="$DIAG_DIR/scoped-promote.stderr"
SCOPED_PROMOTE_EXIT="$DIAG_DIR/scoped-promote.exit"
SCOPED_SUCCESS_PROMOTE_STDOUT="$DIAG_DIR/scoped-success-promote.stdout"
SCOPED_SUCCESS_PROMOTE_STDERR="$DIAG_DIR/scoped-success-promote.stderr"
SCOPED_SUCCESS_PROMOTE_EXIT="$DIAG_DIR/scoped-success-promote.exit"
AUTHORITY_STATUS_STDOUT="$DIAG_DIR/authority-status.stdout"
AUTHORITY_STATUS_STDERR="$DIAG_DIR/authority-status.stderr"
AUTHORITY_STATUS_EXIT="$DIAG_DIR/authority-status.exit"
for diagnostic_file in "$SCOPED_PLAN_STDOUT" "$SCOPED_PLAN_STDERR" "$SCOPED_PLAN_EXIT" "$SCOPED_REFRESH_STDOUT" "$SCOPED_REFRESH_STDERR" "$SCOPED_REFRESH_EXIT" "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR" "$SCOPED_PROMOTE_EXIT" "$SCOPED_SUCCESS_PROMOTE_STDOUT" "$SCOPED_SUCCESS_PROMOTE_STDERR" "$SCOPED_SUCCESS_PROMOTE_EXIT" "$AUTHORITY_STATUS_STDOUT" "$AUTHORITY_STATUS_STDERR" "$AUTHORITY_STATUS_EXIT"; do
  ( umask 077; : >"$diagnostic_file" )
  chmod 0600 "$diagnostic_file"
done

ssh_fixture() { ssh -F "$TMP/ssh_config" wprism-adopt-fixture "$@"; }

target_ledger_value() {
  local key="$1"
  ssh_fixture "cd /var/www/html && wp db query \"SELECT v FROM wp_wprism_kv WHERE k = '$key'\" --skip-column-names"
}

target_checkpoint_state() {
  ssh_fixture "cd /var/www/html && wp db query \"SELECT value FROM wprism_cert_state WHERE id = 1\" --skip-column-names"
}

say "build a standalone SSH WordPress host image"
docker build -q \
  --label "wprism.live-suite=$SUITE_LABEL" \
  --label "wprism.live-run=$RUN_ID" \
  --label "wprism.live-source=$SOURCE_SHA" \
  -t "$IMAGE" -f sandbox/tests/fixtures/ssh-adopt.Dockerfile . >/dev/null
IMAGE_OWNED=1
docker network create \
  --label "wprism.live-suite=$SUITE_LABEL" \
  --label "wprism.live-run=$RUN_ID" \
  --label "wprism.live-source=$SOURCE_SHA" \
  "$NET" >/dev/null
NETWORK_OWNED=1
docker volume create \
  --label "wprism.live-suite=$SUITE_LABEL" \
  --label "wprism.live-run=$RUN_ID" \
  --label "wprism.live-source=$SOURCE_SHA" \
  "$VOLUME" >/dev/null
VOLUME_OWNED=1
ssh-keygen -q -t ed25519 -N '' -f "$TMP/id_ed25519"
pass "labeled standalone image, network, volume, and one-run SSH credential created"
# The SSH estate installs WordPress from wp.org; without a version it floats to
# whatever core is current that day and silently leaves the exercised platform
# boundary (measured 2026-08-24: `wp core download` fetched 7.1 while the claim
# still stopped at 7.1.0 — the 7.1 series has since been claimed, but a floating
# fetch would leave the boundary again on the next release either way). Pin to
# the newest exercised core the claim itself names, read from the shipped
# boundary so estate and claim cannot drift apart.
WP_CORE_VERSION="$(jq -r '.platform.compatibility.wordpress.last_verified' platform/adapter-library/capabilities/platform.json)"
[[ "$WP_CORE_VERSION" =~ ^[0-9]+(\.[0-9]+){1,3}$ ]] || fail "platform.json names no usable last_verified WordPress core"

say "start an independent database and initialize WordPress core"
docker run -d --name "$DB" --network "$NET" \
  --label "wprism.live-suite=$SUITE_LABEL" \
  --label "wprism.live-run=$RUN_ID" \
  --label "wprism.live-source=$SOURCE_SHA" \
  -e MARIADB_ROOT_PASSWORD=root-pass \
  -e MARIADB_DATABASE=wordpress \
  -e MARIADB_USER=wordpress \
  -e MARIADB_PASSWORD=wordpress-pass \
  mariadb:11.8 >/dev/null
DATABASE_OWNED=1
for _ in $(seq 1 60); do
  docker exec "$DB" mariadb-admin ping -h 127.0.0.1 -uroot -proot-pass --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$DB" mariadb-admin ping -h 127.0.0.1 -uroot -proot-pass --silent >/dev/null 2>&1 \
  || fail "database never became ready"
docker run --rm --user root --network "$NET" -v "$VOLUME:/var/www/html" wordpress:cli-php8.3 \
  sh -lc 'php -d memory_limit=512M /usr/local/bin/wp core download --version="'"$WP_CORE_VERSION"'" --path=/var/www/html --allow-root --quiet && chown -R 1000:1000 /var/www/html'
pass "WordPress files initialized without sharing the WPrism checkout"

say "start the target and expose only its SSH port"
docker run -d --name "$TARGET" --network "$NET" \
  --label "wprism.live-suite=$SUITE_LABEL" \
  --label "wprism.live-run=$RUN_ID" \
  --label "wprism.live-source=$SOURCE_SHA" \
  -p "127.0.0.1:${PORT}:22" \
  -v "$VOLUME:/var/www/html" \
  -v "$TMP/id_ed25519.pub:/tmp/authorized_key:ro" \
  --entrypoint sh "$IMAGE" -lc \
  'cp /tmp/authorized_key /home/wprism/.ssh/authorized_keys; chown wprism:wprism /home/wprism/.ssh/authorized_keys; chmod 0600 /home/wprism/.ssh/authorized_keys; exec /usr/sbin/sshd -D -e' \
  >/dev/null
TARGET_OWNED=1
for _ in $(seq 1 60); do
  ssh-keyscan -p "$PORT" 127.0.0.1 >"$TMP/known_hosts" 2>/dev/null && [ -s "$TMP/known_hosts" ] && break
  sleep 1
done
[ -s "$TMP/known_hosts" ] || fail "SSH host key never became available"
cat >"$TMP/ssh_config" <<EOF
Host wprism-adopt-fixture
  HostName 127.0.0.1
  Port $PORT
  User wprism
  IdentityFile $TMP/id_ed25519
  UserKnownHostsFile $TMP/known_hosts
  StrictHostKeyChecking yes
  IdentitiesOnly yes
  BatchMode yes
EOF
ssh_fixture 'echo wprism-ssh-ready' | grep -qx wprism-ssh-ready || fail "SSH transport did not become ready"
pass "fresh SSH login is reachable without a process-selected adapter library"

say "install WordPress through the SSH boundary"
ssh_fixture "cd /var/www/html && wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress-pass --dbhost=$DB --skip-check --quiet"
ssh_fixture "cd /var/www/html && wp core install --url=http://adopt.example.test --title='Adopt Fixture' --admin_user=admin --admin_password=admin-pass --admin_email=admin@example.test --skip-email --quiet"
ssh_fixture "cd /var/www/html && wp db query \"CREATE TABLE wprism_cert_state (id bigint primary key, value varchar(191) not null); INSERT INTO wprism_cert_state VALUES (1,'prior-db'); CREATE TABLE wprism_cert_lease (id bigint primary key, owner varchar(191) not null);\""
if ssh_fixture "cd /var/www/html && wp eval 'echo class_exists(\"\\WPrism\\Capture\") ? \"present\" : \"absent\";'" | grep -qx present; then
  fail "fixture unexpectedly started with WPrism installed"
fi
pass "pre-existing WordPress target starts without WPrism"

php -r '$pair=sodium_crypto_sign_keypair(); file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($pair))."\n");' "$TMP/rollback-signing.key"
chmod 0600 "$TMP/rollback-signing.key"
openssl rand 32 >"$TMP/checkpoint.key"
chmod 0600 "$TMP/checkpoint.key"
jq -n --arg host "$DB" '{host:$host,port:3306,database:"wordpress",user:"wordpress",password:"wordpress-pass",admin_user:"root",admin_password:"root-pass",code_pointer:"/home/wprism/recovery-fixture/code-pointer",effect_target:"/home/wprism/recovery-fixture/effect-target"}' >"$TMP/checkpoint-db.json"
ssh_fixture 'mkdir -p /home/wprism/recovery-fixture /home/wprism/recovery-fixture/checkpoint && chmod 700 /home/wprism/recovery-fixture /home/wprism/recovery-fixture/checkpoint && printf "adopt-code\\n" > /home/wprism/recovery-fixture/code-pointer && printf "adopt-effect\\n" > /home/wprism/recovery-fixture/effect-target'
scp -F "$TMP/ssh_config" sandbox/tests/fixtures/recovery-exclusion-provider.php sandbox/tests/fixtures/recovery-adapter.php sandbox/tests/fixtures/ssh-rollback-checkpoint-provider.php sandbox/tests/fixtures/code-release-provider.php \
  wprism-adopt-fixture:/home/wprism/recovery-fixture/ >/dev/null
scp -F "$TMP/ssh_config" "$TMP/checkpoint.key" "$TMP/checkpoint-db.json" \
  wprism-adopt-fixture:/home/wprism/recovery-fixture/ >/dev/null
ssh_fixture 'chmod 700 /home/wprism/recovery-fixture/*.php'
ssh_fixture 'chmod 600 /home/wprism/recovery-fixture/checkpoint.key /home/wprism/recovery-fixture/checkpoint-db.json'

cat >"$TMP/envs.json" <<EOF
{
  "envs": {
    "target": {
      "transport": "ssh",
      "host": "wprism-adopt-fixture",
      "ssh_config": "$TMP/ssh_config",
      "rollback_key_id": "fixture-key-1",
      "rollback_signing_key": "$TMP/rollback-signing.key",
      "verified_rollback": {
        "claim_ttl_seconds": 120,
        "encryption_key_id": "ssh-adopt-scoped-kms",
        "retention_seconds": 86400
      },
      "rollback_recovery": {
        "adapters": {
          "code_restore": ["/usr/local/bin/php", "/home/wprism/recovery-fixture/recovery-adapter.php"],
          "database_restore": ["/usr/local/bin/php", "/home/wprism/recovery-fixture/recovery-adapter.php"],
          "prior_verify": ["/usr/local/bin/php", "/home/wprism/recovery-fixture/recovery-adapter.php"],
          "storage_restore": ["/usr/local/bin/php", "/home/wprism/recovery-fixture/recovery-adapter.php"]
        },
        "checkpoint_provider": ["/usr/local/bin/php", "/home/wprism/recovery-fixture/ssh-rollback-checkpoint-provider.php", "/home/wprism/recovery-fixture/checkpoint", "/home/wprism/recovery-fixture/checkpoint-db.json", "/home/wprism/recovery-fixture/checkpoint.key"],
        "code_release_provider": ["/usr/local/bin/php", "/home/wprism/recovery-fixture/code-release-provider.php", "/home/wprism/recovery-fixture/code-release-state", "/home/wprism/code-releases", "/home/wprism/code-current"],
        "exclusion_provider": ["/usr/local/bin/php", "/home/wprism/recovery-fixture/recovery-exclusion-provider.php", "/home/wprism/recovery-fixture/provider-state.json"],
        "timeout_seconds": 30
      },
      "wp_path": "/var/www/html",
      "repo_path": "/home/wprism/site"
    }
  }
}
EOF

say "negotiate the SSH driver before any adoption target call"
if DRIVER_JSON="$("$WPRISM" --envs-file="$TMP/envs.json" driver-capabilities target --operation=adopt --format=json 2>"$TMP/driver-adopt.err")"; then
  DRIVER_CODE=0
else
  DRIVER_CODE=$?
fi
[ "$DRIVER_CODE" -eq 0 ] || fail "SSH adopt driver preflight failed"
php -r '
  $r=json_decode($argv[1],true);
  if (!is_array($r) || ($r["format"] ?? null) !== "wprism-environment-driver-capabilities/v1"
      || ($r["driver"]["id"] ?? null) !== "ssh" || ($r["operation"] ?? null) !== "adopt"
      || ($r["ready"] ?? null) !== true
      || preg_match("/^sha256:[a-f0-9]{64}$/", (string)($r["digest"] ?? "")) !== 1) {
    fwrite(STDERR,"invalid SSH adopt driver report\n"); exit(1);
  }
' "$DRIVER_JSON" || fail "SSH adopt driver report was not canonical and ready"
if CREATE_JSON="$("$WPRISM" --envs-file="$TMP/envs.json" driver-capabilities target --operation=create --format=json 2>"$TMP/driver-create.err")"; then
  CREATE_CODE=0
else
  CREATE_CODE=$?
fi
[ "$CREATE_CODE" -ne 0 ] || fail "attach-only SSH driver silently claimed environment creation"
php -r '$r=json_decode($argv[1],true); exit(is_array($r) && ($r["ready"] ?? null) === false ? 0 : 1);' "$CREATE_JSON" \
  || fail "unsupported SSH create capability was not visible in JSON"
ssh_fixture 'test ! -e /var/www/html/wp-content/mu-plugins/wprism && test ! -e /home/wprism/site' \
  || fail "driver negotiation contacted or mutated the SSH target"
pass "SSH driver reports adopt ready, create unsupported, and performs zero target mutation during negotiation"

say "refuse an unsafe durable-control destination before first adoption"
ssh_fixture 'cd /var/www/html/wp-content/mu-plugins && mkdir wprism-control-real && printf "%s\n" preserve > wprism-control-real/sentinel && ln -s wprism-control-real wprism-control'
if OUT="$("$WPRISM" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -ne 0 ] || fail "adopt followed a symlink durable-control destination"
grep -q 'refusing symlink destination: /var/www/html/wp-content/mu-plugins/wprism-control' <<<"$OUT" \
  || fail "durable-control symlink refusal omitted the unsafe destination"
ssh_fixture 'test "$(cat /var/www/html/wp-content/mu-plugins/wprism-control/sentinel)" = preserve; test ! -e /var/www/html/wp-content/mu-plugins/wprism; test ! -e /home/wprism/site; test ! -e /var/www/html/wp-content/mu-plugins/.wprism-adopt-lock' \
  || fail "durable-control symlink refusal changed the target before first adoption"
ssh_fixture 'cd /var/www/html/wp-content/mu-plugins && rm wprism-control && rm -rf wprism-control-real'
pass "unsafe durable-control topology refuses before target mutation"

say "adopt the pre-existing target through the product command"
if OUT="$("$WPRISM" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -eq 0 ] || fail "first wprism adopt failed with exit $CODE"
grep -Fq "adopt: installed agent $AGENT_VERSION + embedded adapter library + rollback authority; created seed site.wprism.json" <<<"$OUT" \
  || fail "first adopt did not report the installed version and seed creation"
grep -q '\[PASS\] wprism agent present' <<<"$OUT" || fail "doctor did not pass agent presence"
grep -q '\[PASS\] repo path has site.wprism.json (/home/wprism/site)' <<<"$OUT" \
  || fail "doctor did not pass the seeded repo"
ssh_fixture "test -f /var/www/html/wp-content/mu-plugins/wprism/wprism.php && test -f /var/www/html/wp-content/mu-plugins/wprism-loader.php && test -f /var/www/html/wp-content/mu-plugins/wprism/adapter-library/platform/core/manifest.json && test ! -e /var/www/html/wp-content/mu-plugins/manifests"
[ "$(ssh_fixture "cd /var/www/html && wp eval 'echo \\WPrism\\Policy::adapter_library_context()->root();'")" = "/var/www/html/wp-content/mu-plugins/wprism/adapter-library" ] \
  || fail "fresh process did not select the installed embedded adapter library"
ssh_fixture 'test -f /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php && test -f /home/wprism/site/.wprism/control/recovery-runtime/RecoveryExecutor.php && test -f /home/wprism/site/.wprism/control/recovery-runtime/CodeRelease.php && test -f /home/wprism/site/.wprism/control/recovery-config.json && test -f /home/wprism/site/.wprism/control/public-keys/fixture-key-1.pub && test -f /home/wprism/site/.wprism/control/target.json' \
  || fail "adopt did not provision the external rollback authority"
ssh_fixture 'test -f /var/www/html/wp-content/mu-plugins/wprism/scoped-promotion-control.json && test "$(stat -c %a /var/www/html/wp-content/mu-plugins/wprism/scoped-promotion-control.json)" = 600 && php -r '\''$v=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR); exit(($v["format"]??null)==="wprism-scoped-promotion-control/v1" && ($v["control_root"]??null)==="/home/wprism/site/.wprism/control" ? 0 : 1);'\'' /var/www/html/wp-content/mu-plugins/wprism/scoped-promotion-control.json' \
  || fail "adopt did not pin the mode-0600 scoped-promotion recovery trust root"
[ "$(ssh_fixture 'stat -c %a /home/wprism/site/.wprism/control')" = "700" ] \
  || fail "rollback control root is not protected mode 0700"
TARGET_ID="$(ssh_fixture "php -r 'echo json_decode(file_get_contents(\"/home/wprism/site/.wprism/control/target.json\"),true)[\"target_id\"];'")"
[ "${#TARGET_ID}" -eq 32 ] || fail "rollback authority did not establish a stable target identity"
ssh_fixture 'test ! -e /home/wprism/site/.wprism/control/rollback-signing.key && test ! -e /home/wprism/site/.wprism/control/private-keys' \
  || fail "adoption copied private signing material to the target"
pass "agent with embedded adapters, seed repo, public-key-only rollback authority, and doctor verify through SSH"

ssh_fixture 'mv /var/www/html/wp-config.php /var/www/html/wp-config.broken; printf "%s\n" "<?php throw new RuntimeException(\"broken bootstrap\");" > /var/www/html/wp-config.php'
ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php recovery-probe --root=/home/wprism/site/.wprism/control' \
  | grep -q '"provider_id":"ssh-fixture-provider"' \
  || fail "raw recovery probe depended on the WordPress bootstrap"
ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php recovery-probe --root=/home/wprism/site/.wprism/control' \
  | grep -q '"provider_id":"ssh-mariadb-checkpoint"' \
  || fail "raw checkpoint probe depended on the WordPress bootstrap"
ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php recovery-probe --root=/home/wprism/site/.wprism/control' \
  | grep -q '"provider_id":"ssh-release-fixture"' \
  || fail "raw code-release probe depended on the WordPress bootstrap"
ssh_fixture 'rm /var/www/html/wp-config.php; mv /var/www/html/wp-config.broken /var/www/html/wp-config.php'
pass "configured exclusion, checkpoint/code-release providers, and all four recovery adapters probe over raw SSH with WordPress broken"

if STATUS_OUT="$("$WPRISM" --envs-file="$TMP/envs.json" status target 2>&1)"; then STATUS_CODE=0; else STATUS_CODE=$?; fi
grep -q '\[PASS\] rollback authority: ready (no active generation)' <<<"$STATUS_OUT" \
  || fail "status did not verify and render the external rollback authority"
ssh_fixture 'cp /home/wprism/site/.wprism/control/target.json /home/wprism/site/.wprism/control/target.valid.json && printf " " >> /home/wprism/site/.wprism/control/target.json'
if BAD_STATUS="$("$WPRISM" --envs-file="$TMP/envs.json" status target 2>&1)"; then BAD_STATUS_CODE=0; else BAD_STATUS_CODE=$?; fi
[ "$BAD_STATUS_CODE" -ne 0 ] && grep -q '\[FAIL\] rollback authority: invalid' <<<"$BAD_STATUS" \
  || fail "tampered authority did not make status non-green"
if FENCE_OUT="$("$WPRISM" --envs-file="$TMP/envs.json" promote target 2>&1)"; then FENCE_CODE=0; else FENCE_CODE=$?; fi
[ "$FENCE_CODE" -ne 0 ] && grep -q 'rollback authority is invalid; refusing target mutation' <<<"$FENCE_OUT" \
  || fail "promotion did not fail closed at the external authority fence"
ssh_fixture 'test ! -d /home/wprism/site/.wprism/artifacts && mv /home/wprism/site/.wprism/control/target.valid.json /home/wprism/site/.wprism/control/target.json' \
  || fail "authority refusal occurred after promotion created artifacts"
pass "status detects tampering and promotion refuses before its first target mutation"

say "prove update/idempotence without overwriting site policy"
ssh_fixture "php -r '\$p=\"/home/wprism/site/site.wprism.json\"; \$d=json_decode(file_get_contents(\$p),true); \$d[\"adoption_probe\"]=\"retain\"; file_put_contents(\$p,json_encode(\$d));'"
ssh_fixture "sed -i \"s/WPRISM_AGENT_VERSION', '$AGENT_VERSION/WPRISM_AGENT_VERSION', '0.0.0/\" /var/www/html/wp-content/mu-plugins/wprism/wprism.php"
[ "$(ssh_fixture "cd /var/www/html && wp eval 'echo WPRISM_AGENT_VERSION;'")" = "0.0.0" ] \
  || fail "could not create the stale-agent precondition"
if OUT="$("$WPRISM" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -eq 0 ] || fail "second wprism adopt failed with exit $CODE"
grep -q 'retained existing site.wprism.json' <<<"$OUT" || fail "rerun did not report non-destructive repo retention"
[ "$(ssh_fixture "cd /var/www/html && wp eval 'echo WPRISM_AGENT_VERSION;'")" = "$AGENT_VERSION" ] \
  || fail "rerun did not update the stale agent to the orchestrator's exact version"
[ "$(ssh_fixture "php -r 'echo json_decode(file_get_contents(\"/home/wprism/site/site.wprism.json\"),true)[\"adoption_probe\"] ?? \"missing\";'")" = "retain" ] \
  || fail "rerun overwrote existing site.wprism.json"
[ "$(ssh_fixture "php -r 'echo json_decode(file_get_contents(\"/home/wprism/site/.wprism/control/target.json\"),true)[\"target_id\"];'")" = "$TARGET_ID" ] \
  || fail "rerun replaced the stable rollback target identity"
pass "rerun updates stale code and embedded adapters while retaining site policy and rollback target identity"

say "roll back the installed release when fresh policy verification fails"
ssh_fixture 'cp /home/wprism/site/site.wprism.json /home/wprism/site/site.wprism.valid.json'
ssh_fixture "sed -i \"s/WPRISM_AGENT_VERSION', '$AGENT_VERSION/WPRISM_AGENT_VERSION', '0.0.0/\" /var/www/html/wp-content/mu-plugins/wprism/wprism.php"
ssh_fixture "printf '%s\n' '{invalid-json' > /home/wprism/site/site.wprism.json"
BEFORE_AGENT="$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/wprism/wprism.php')"
BEFORE_LIBRARY="$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/wprism/adapter-library/platform/core/manifest.json')"
BEFORE_RUNTIME="$(ssh_fixture 'cksum /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php')"
BEFORE_SITE="$(ssh_fixture 'cksum /home/wprism/site/site.wprism.json')"
if OUT="$("$WPRISM" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -ne 0 ] || fail "adopt reported success for an invalid existing site policy"
grep -q 'adopt failed during policy verification' <<<"$OUT" \
  || fail "invalid existing policy did not fail at the named verification boundary"
[ "$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/wprism/wprism.php')" = "$BEFORE_AGENT" ] \
  || fail "policy-verification failure did not restore the previous agent"
[ "$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/wprism/adapter-library/platform/core/manifest.json')" = "$BEFORE_LIBRARY" ] \
  || fail "policy-verification failure did not restore the previous embedded adapter library"
[ "$(ssh_fixture 'cksum /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php')" = "$BEFORE_RUNTIME" ] \
  || fail "policy-verification failure did not restore the previous rollback runtime"
[ "$(ssh_fixture 'cksum /home/wprism/site/site.wprism.json')" = "$BEFORE_SITE" ] \
  || fail "policy-verification failure changed the existing site policy"
ssh_fixture 'mv /home/wprism/site/site.wprism.valid.json /home/wprism/site/site.wprism.json'
"$WPRISM" --envs-file="$TMP/envs.json" adopt target >/dev/null \
  || fail "adopt did not recover after the valid policy was restored"
pass "post-swap verification failure restores the agent with its embedded adapters and leaves site policy unchanged"

say "the adopted target carries the reviewed embedded adapter library it will be gated on"
# A premise check, not a fixture: `wprism adopt` above installed this checkout's
# assembled adapter library whole, so the reviewed dispositions and platform boundary are
# already there. Asserted before the scoped promotion so a library that failed
# to land is diagnosed here rather than as an unexplained capability refusal
# eight commands later. Nothing is written; the product gate is untouched.
if ! ssh_fixture 'php -r '\''$m="/var/www/html/wp-content/mu-plugins/wprism/adapter-library"; $p=json_decode(file_get_contents("$m/platform/capabilities/platform.json"),true,512,JSON_THROW_ON_ERROR); if(($p["format"]??null)!=="wprism-platform-boundary/v1")exit(1); $files=glob("$m/adapters/*/disposition.json")?:[]; $files[]="$m/platform/core/disposition.json"; foreach($files as $f){$v=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR); if(($v["status"]??null)==="certified"&&count($v["evidence"]["tests"]??[])<1)exit(1);} exit(0);'\'''; then
  fail "the adopted target has no reviewed embedded adapter library: platform boundary or disposition evidence citation is missing"
fi
pass "target carries the shipped platform boundary and a cited disposition for every certified claim"

say "exercise a real checkpointed SSH scoped promotion and its recovery boundary"
ssh_fixture 'php -r '\''$p="/home/wprism/site/site.wprism.json"; $d=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR); $d["policy"]["options"]["scoped-apply_scoped_option"]=["autoload"=>"preserve","class"=>"authored"]; file_put_contents($p,json_encode($d,JSON_UNESCAPED_SLASHES)."\n");'\'''
ssh_fixture 'cd /var/www/html && wp option update scoped-apply_scoped_option desired-failure --autoload=no >/dev/null'
"$WPRISM" --envs-file="$TMP/envs.json" capture target --format=json >"$TMP/scoped-apply-failure-capture.json" \
  || fail "could not capture the desired scoped-promotion source state"
"$WPRISM" --envs-file="$TMP/envs.json" scope target --roots=options --contract >"$TMP/scoped-apply-failure-scope.json" \
  || fail "could not mint the desired scoped-promotion contract"

# Cross-command identity fence: refresh-export must associate the same
# immutable contract before the target is deliberately moved to its prior
# value. Keep the contract on the target only for this read-only command and
# retain its bounded result privately if the command or later promotion fails.
FAILURE_SCOPE_HASH="$(jq -r '.scope_hash' "$TMP/scoped-apply-failure-scope.json")"
jq -r '[(.live.roots // [])[], (.live.closure // [])[] | .entity] + [(.tombstones // [])[] | .uuid] | sort[]' \
  "$TMP/scoped-apply-failure-scope.json" >"$TMP/scoped-apply-failure-scope-identities"
scp -F "$TMP/ssh_config" "$TMP/scoped-apply-failure-scope.json" \
  wprism-adopt-fixture:/home/wprism/site/.scoped-apply-scope-chain.json >/dev/null
if ssh_fixture 'cd /var/www/html && wp wprism refresh-export --repo=/home/wprism/site --scope-contract=/home/wprism/site/.scoped-apply-scope-chain.json --format=json' >"$SCOPED_REFRESH_STDOUT" 2>"$SCOPED_REFRESH_STDERR"; then
  SCOPED_REFRESH_CODE=0
else
  SCOPED_REFRESH_CODE=$?
fi
printf '%s\n' "$SCOPED_REFRESH_CODE" >"$SCOPED_REFRESH_EXIT"
ssh_fixture 'rm -f /home/wprism/site/.scoped-apply-scope-chain.json'
[ "$SCOPED_REFRESH_CODE" -eq 0 ] \
  || fail "target scoped refresh-export did not complete before promotion"
jq -e --arg h "$FAILURE_SCOPE_HASH" \
  '.format == "wprism-refresh-production/v1" and .scope.format == "wprism-refresh-scope/v1" and .scope.scope_hash == $h' \
  "$SCOPED_REFRESH_STDOUT" >/dev/null \
  || fail "target scoped refresh-export did not echo the exact scope hash"
jq -r '(.scope.selected_identities // [])[]' "$SCOPED_REFRESH_STDOUT" | LC_ALL=C sort >"$TMP/scoped-apply-refresh-identities"
diff -u "$TMP/scoped-apply-failure-scope-identities" "$TMP/scoped-apply-refresh-identities" >/dev/null \
  || fail "target scoped refresh-export changed the selected identity set"
ssh_fixture 'cd /var/www/html && wp option update scoped-apply_scoped_option prior-failure --autoload=no >/dev/null'

# Begin and abort an ordinary promotion first. The target deliberately retains
# its completed ordinary session record, exercising the scoped begin reclaim
# boundary rather than assuming a newly adopted target is session-empty.
ORDINARY_OWNER="ordinary-scoped-apply-completed"
ORDINARY_ARTIFACT="$(printf %s scoped-apply-ordinary-completed | shasum -a 256 | awk '{print $1}')"
ssh_fixture "cd /var/www/html && wp wprism promotion-begin --promotion-owner=$ORDINARY_OWNER --artifact-hash=$ORDINARY_ARTIFACT --format=json" >"$TMP/scoped-apply-ordinary-begin.json" \
  || fail "could not establish the completed ordinary-session precondition"
ssh_fixture "cd /var/www/html && wp wprism promotion-abort --promotion-owner=$ORDINARY_OWNER --artifact-hash=$ORDINARY_ARTIFACT --format=json" >"$TMP/scoped-apply-ordinary-abort.json" \
  || fail "could not retire the ordinary promotion lock"
[ -z "$(target_ledger_value promotion_lock)" ] \
  || fail "ordinary promotion left an active target lock"
ORDINARY_SESSION="$(target_ledger_value promotion_session)"
jq -e --arg owner "$ORDINARY_OWNER" --arg artifact "$ORDINARY_ARTIFACT" '
  .owner == $owner and .artifact_hash == $artifact
  and ((.profile // "") != "scoped-checkpoint-v1") and (.lifecycle_attempt? | not)
' <<<"$ORDINARY_SESSION" >/dev/null \
  || fail "target did not retain the safely completed ordinary session precondition"

cat >"$TMP/scoped-apply-promotion-fault.php" <<'PHP'
<?php
declare(strict_types=1);

if (defined('WP_CLI') && WP_CLI
    && is_file('/home/wprism/recovery-fixture/scoped-apply-fault-active')) {
    $argv = $GLOBALS['argv'] ?? [];
    if (is_array($argv) && in_array('wprism', $argv, true) && in_array('apply', $argv, true)) {
        add_action('plugins_loaded', static function (): void {
            global $wpdb;
            if ($wpdb->query("UPDATE wprism_cert_state SET value = 'mutated-after-checkpoint' WHERE id = 1") !== 1) {
                throw new RuntimeException('issue #3344 test fixture could not mutate the checkpoint probe');
            }
            if (!update_option('scoped-apply_scoped_restore_probe', 'mutated-after-checkpoint', false)) {
                throw new RuntimeException('issue #3344 test fixture could not mutate the WordPress restore probe');
            }
            putenv('WPRISM_TEST_MODE=1');
            putenv('WPRISM_TEST_FAIL_DB_CONTEXT=rebuild object cache');
        }, PHP_INT_MAX);
    }
}
PHP
scp -F "$TMP/ssh_config" "$TMP/scoped-apply-promotion-fault.php" \
  wprism-adopt-fixture:/var/www/html/wp-content/mu-plugins/scoped-apply-promotion-fault.php >/dev/null
ssh_fixture 'touch /home/wprism/recovery-fixture/scoped-apply-fault-active'

# Capture the exact read-only scoped plan for the pre-promote target state.
# The controlled apply fault is active to prove this plan path does not run
# target Apply; only the private, bounded diagnostic streams retain its result.
if "$WPRISM" --envs-file="$TMP/envs.json" plan target --scope-contract="$TMP/scoped-apply-failure-scope.json" --format=json >"$SCOPED_PLAN_STDOUT" 2>"$SCOPED_PLAN_STDERR"; then
  SCOPED_PLAN_CODE=0
else
  SCOPED_PLAN_CODE=$?
fi
printf '%s\n' "$SCOPED_PLAN_CODE" >"$SCOPED_PLAN_EXIT"
[ "$SCOPED_PLAN_CODE" -eq 0 ] \
  || fail "pre-promote scoped target plan did not complete"

if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/scoped-apply-failure-scope.json" >"$SCOPED_PROMOTE_STDOUT" 2>"$SCOPED_PROMOTE_STDERR"; then
  FAILURE_CODE=0
else
  FAILURE_CODE=$?
fi
printf '%s\n' "$FAILURE_CODE" >"$SCOPED_PROMOTE_EXIT"
# Capture only the raw signed authority status before removing the injected
# fault. The decorated `status` action probes recovery providers and therefore
# is not observation-only. The separate diagnostic directory deliberately
# contains only these bounded plan/promote/authority observations, never the
# SSH config, keys, or DB credentials from TMP. Its contents are private and
# must not be printed into CI output.
if ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php authority-status --root=/home/wprism/site/.wprism/control' >"$AUTHORITY_STATUS_STDOUT" 2>"$AUTHORITY_STATUS_STDERR"; then
  AUTHORITY_STATUS_CODE=0
else
  AUTHORITY_STATUS_CODE=$?
fi
printf '%s\n' "$AUTHORITY_STATUS_CODE" >"$AUTHORITY_STATUS_EXIT"
ssh_fixture 'rm -f /home/wprism/recovery-fixture/scoped-apply-fault-active /var/www/html/wp-content/mu-plugins/scoped-apply-promotion-fault.php'
[ "$FAILURE_CODE" -ne 0 ] || fail "post-begin scoped fault unexpectedly promoted"
grep -q 'scoped promote phase: promotion-begin-scoped' "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR" \
  || fail "controlled scoped fault did not cross target promotion-begin-scoped"
grep -q 'scoped promote phase: apply' "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR" \
  || fail "controlled scoped fault did not enter the receipt-bound target apply"
grep -q 'prior database verified; generation .* rolled_back and exclusion released' "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR" \
  || fail "controlled scoped fault did not report verified pre-commit rollback"

[ "$AUTHORITY_STATUS_CODE" -eq 0 ] \
  || fail "scoped failure authority status probe did not complete"
jq -e '
  .ok == true and .receipt_format == "wprism-scoped-promotion-receipt/v1"
  and .state == "rolled_back" and .terminal == true
' "$AUTHORITY_STATUS_STDOUT" >/dev/null \
  || fail "scoped failure did not leave a signed rolled_back terminal receipt"
jq -e --arg h "$FAILURE_SCOPE_HASH" '
  .ok == true and .receipt_format == "wprism-scoped-promotion-receipt/v1"
  and .scope_hash == $h and .state == "rolled_back" and .terminal == true
' "$AUTHORITY_STATUS_STDOUT" >/dev/null \
  || fail "scoped failure changed the immutable scope hash"
FAIL_EVIDENCE="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php active-evidence --root=/home/wprism/site/.wprism/control')"
jq -e '
  .status.state == "rolled_back"
  and .completed_operations.database_restore.operation_status == "completed"
  and .completed_operations.prior_verify.operation_status == "completed"
' <<<"$FAIL_EVIDENCE" >/dev/null \
  || fail "signed failure evidence did not bind encrypted database_restore and prior_verify"
FAIL_RECEIPT="$(jq -r '.receipt_id' "$AUTHORITY_STATUS_STDOUT")"
ssh_fixture "test -s /home/wprism/site/.wprism/rollback/$FAIL_RECEIPT/artifacts/checkpoint.enc" \
  || fail "rolled-back scoped generation did not retain its real encrypted database checkpoint"
[ "$(target_checkpoint_state)" = "prior-db" ] \
  || fail "encrypted scoped rollback did not restore the prior database value"
[ "$(ssh_fixture 'cd /var/www/html && wp option get scoped-apply_scoped_option')" = "prior-failure" ] \
  || fail "encrypted scoped rollback did not restore the prior authored option"
if ssh_fixture 'cd /var/www/html && wp option get scoped-apply_scoped_restore_probe' >/dev/null 2>&1; then
  fail "encrypted scoped rollback retained the post-checkpoint restore probe"
fi
[ -z "$(target_ledger_value promotion_lock)" ] \
  || fail "rolled-back scoped promotion left an active target lock"
ROLLED_BACK_SESSION="$(target_ledger_value promotion_session)"
jq -e --arg owner "$ORDINARY_OWNER" --arg artifact "$ORDINARY_ARTIFACT" '
  .owner == $owner and .artifact_hash == $artifact
  and ((.profile // "") != "scoped-checkpoint-v1") and (.lifecycle_attempt? | not)
' <<<"$ROLLED_BACK_SESSION" >/dev/null \
  || fail "rolled-back scoped promotion retained a scoped target session"
jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
  || fail "v2 exclusion provider did not release after scoped rollback"

ssh_fixture 'cd /var/www/html && wp option update scoped-apply_scoped_option desired-success --autoload=no >/dev/null'
"$WPRISM" --envs-file="$TMP/envs.json" capture target --format=json >"$TMP/scoped-apply-success-capture.json" \
  || fail "could not capture the successful scoped-promotion source state"
"$WPRISM" --envs-file="$TMP/envs.json" scope target --roots=options --contract >"$TMP/scoped-apply-success-scope.json" \
  || fail "could not mint the successful scoped-promotion contract"
SUCCESS_SCOPE_HASH="$(jq -r '.scope_hash' "$TMP/scoped-apply-success-scope.json")"
ssh_fixture 'cd /var/www/html && wp option update scoped-apply_scoped_option prior-success --autoload=no >/dev/null'

# Keep the committed retry's bounded public result private when it fails or
# its receipt cannot be parsed. TMP remains exclusively secret-bearing
# scratch, so cleanup always erases it while retaining only this controlled
# promote transcript, numeric exit, and the earlier failure observations.
if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/scoped-apply-success-scope.json" --format=json >"$SCOPED_SUCCESS_PROMOTE_STDOUT" 2>"$SCOPED_SUCCESS_PROMOTE_STDERR"; then
  SUCCESS_CODE=0
else
  SUCCESS_CODE=$?
fi
printf '%s\n' "$SUCCESS_CODE" >"$SCOPED_SUCCESS_PROMOTE_EXIT"
[ "$SUCCESS_CODE" -eq 0 ] \
  || fail "public SSH scoped promote did not complete"
jq -e --argjson failed_generation "$(jq -r '.generation' "$AUTHORITY_STATUS_STDOUT")" '
  .format == "wprism-scoped-promotion-result/v1" and .state == "committed"
  and (.generation > $failed_generation)
  and .rollback.format == "wprism-scoped-promotion-receipt/v1"
  and .rollback.automatic_window_closed == true and .rollback.later_rollback_supported == false
  and .scoped_apply.format == "wprism-scoped-apply-result/v1"
  and .scoped_apply.scoped_receipt.phase == "complete"
' "$SCOPED_SUCCESS_PROMOTE_STDOUT" >/dev/null \
  || fail "successful scoped promotion did not return its receipt-bound terminal result"
jq -e --arg h "$SUCCESS_SCOPE_HASH" '.scope_hash == $h' "$SCOPED_SUCCESS_PROMOTE_STDOUT" >/dev/null \
  || fail "successful scoped promotion changed the immutable scope hash"
SUCCESS_STATUS="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php status --root=/home/wprism/site/.wprism/control')"
jq -e --arg h "$SUCCESS_SCOPE_HASH" '
  .ok == true and .receipt_format == "wprism-scoped-promotion-receipt/v1"
  and .scope_hash == $h and .state == "committed" and .terminal == true and .exclusion_state == "released"
' <<<"$SUCCESS_STATUS" >/dev/null \
  || fail "successful scoped promotion did not leave a signed committed terminal receipt with v2 exclusion released"
SUCCESS_EVIDENCE="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php active-evidence --root=/home/wprism/site/.wprism/control')"
jq -e '
  .status.state == "committed"
  and .completed_operations.scoped_apply.operation_status == "completed"
' <<<"$SUCCESS_EVIDENCE" >/dev/null \
  || fail "successful scoped promotion did not bind the terminal target Apply receipt into signed evidence"
[ "$(ssh_fixture 'cd /var/www/html && wp option get scoped-apply_scoped_option')" = "desired-success" ] \
  || fail "successful scoped promotion did not converge the target authored option"
"$WPRISM" --envs-file="$TMP/envs.json" plan target --scope-contract="$TMP/scoped-apply-success-scope.json" --format=json >"$TMP/scoped-apply-success-plan.json" \
  || fail "successful scoped promotion did not permit a converged public scoped plan"
jq -e '
  .format == "wprism-scoped-plan/v1"
  and .create == [] and .update == [] and .drift == [] and .conflict == []
  and .delete == [] and .delete_conflict == []
' "$TMP/scoped-apply-success-plan.json" >/dev/null \
  || fail "successful scoped promotion target did not converge"
[ -z "$(target_ledger_value promotion_lock)" ] \
  || fail "successful scoped promotion left an active target lock"
[ -z "$(target_ledger_value promotion_session)" ] \
  || fail "successful scoped promotion did not execute target promotion-complete-scoped"
jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
  || fail "v2 exclusion provider did not release after scoped commit"
pass "public scoped promotion restores a real encrypted DB checkpoint on failure, then commits a receipt-bound target Apply and retires its scoped session"

if [ -n "$EXTENSION" ]; then
  EXTENSION_REAL="$(php -r '$p=realpath($argv[1]); if(!is_string($p)||$p==="")exit(1); echo $p;' "$EXTENSION")" \
    || fail "WPRISM_SSH_ADOPT_EXTENSION does not resolve to a tracked capsule live script"
  case "$EXTENSION_REAL" in
    "$ROOT"/adapter-packages/*/tests/live/*.sh) ;;
    *) fail "WPRISM_SSH_ADOPT_EXTENSION must stay under adapter-packages/<slug>/tests/live" ;;
  esac
  [ -f "$EXTENSION_REAL" ] && [ ! -L "$EXTENSION_REAL" ] && [ -r "$EXTENSION_REAL" ] \
    || fail "WPRISM_SSH_ADOPT_EXTENSION must be a readable, non-symlink regular file"
  EXTENSION_RELATIVE="${EXTENSION_REAL#"$ROOT"/}"
  git -C "$ROOT" --no-optional-locks ls-files --error-unmatch -- "$EXTENSION_RELATIVE" >/dev/null 2>&1 \
    || fail "WPRISM_SSH_ADOPT_EXTENSION must be tracked by the exact candidate commit"
  # shellcheck source=/dev/null
  . "$EXTENSION_REAL"
  declare -F wprism_ssh_adopt_extension >/dev/null \
    || fail "WPRISM_SSH_ADOPT_EXTENSION must define wprism_ssh_adopt_extension()"
  wprism_ssh_adopt_extension
  pass "candidate-bound capsule SSH extension completed under the shared recovery fixture"
fi

BODY_COMPLETE=1
