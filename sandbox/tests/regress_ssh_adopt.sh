#!/usr/bin/env bash
# DUO-3281/DUO-3344: prove the product adoption path and scoped-promotion
# checkpoint recovery against a standalone WordPress host reached only over
# SSH. This deliberately uses docker run, not compose, pair.sh, shared volumes
# with the source checkout, or docker exec for any product operation. Docker is
# only the disposable host boundary; every install/verification action after
# boot travels through cli/duo's SSH path.
#
# Run only from a clean standalone candidate clone, with explicitly allocated
# resources:
#   make regress-ssh-adopt ADOPT_FIXTURE=<unique-name> ADOPT_SSH_PORT=<free-port> \
#     DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
cd "$ROOT"

PREFIX="${ADOPT_FIXTURE:-}"
PORT_RAW="${ADOPT_SSH_PORT:-}"
EXPECTED_SHA="${DUO_EXPECTED_SOURCE_SHA:-}"
SOURCE_SHA=""
NET="${PREFIX}-net"
DB="${PREFIX}-db"
TARGET="${PREFIX}-target"
VOLUME="${PREFIX}-wordpress"
IMAGE="${PREFIX}-ssh-image"
PORT=""
TMP=""
DUO="$ROOT/cli/duo"
SUITE_LABEL="regress-ssh-adopt"
RUN_ID=""
IMAGE_OWNED=0
NETWORK_OWNED=0
VOLUME_OWNED=0
DATABASE_OWNED=0
TARGET_OWNED=0

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

resource_has_our_labels() {
  local kind="$1" name="$2" labels=""
  case "$kind" in
    container)
      labels="$(docker container inspect --format '{{index .Config.Labels "duo.live-suite"}}|{{index .Config.Labels "duo.live-run"}}|{{index .Config.Labels "duo.live-source"}}' "$name" 2>/dev/null)" || return 1
      ;;
    network)
      labels="$(docker network inspect --format '{{index .Labels "duo.live-suite"}}|{{index .Labels "duo.live-run"}}|{{index .Labels "duo.live-source"}}' "$name" 2>/dev/null)" || return 1
      ;;
    volume)
      labels="$(docker volume inspect --format '{{index .Labels "duo.live-suite"}}|{{index .Labels "duo.live-run"}}|{{index .Labels "duo.live-source"}}' "$name" 2>/dev/null)" || return 1
      ;;
    image)
      labels="$(docker image inspect --format '{{index .Config.Labels "duo.live-suite"}}|{{index .Config.Labels "duo.live-run"}}|{{index .Config.Labels "duo.live-source"}}' "$name" 2>/dev/null)" || return 1
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
  if [ -n "$TMP" ] && [ -d "$TMP" ]; then
    rm -rf -- "$TMP" || cleanup_failed=1
  fi
  if [ "$cleanup_failed" -ne 0 ]; then
    printf 'FAIL: owned SSH-adoption fixture cleanup was incomplete; preserved unmatched resources\n' >&2
    exit 1
  fi
  exit "$incoming"
}

for command in git docker lsof; do
  command -v "$command" >/dev/null 2>&1 || fail "$command is required for SSH-adoption live evidence"
done
[[ "$PREFIX" =~ ^[a-z][a-z0-9]{2,31}$ ]] \
  || fail "ADOPT_FIXTURE is required and must be a unique lowercase 3..32 character name"
[[ "$PORT_RAW" =~ ^[0-9]+$ ]] \
  || fail "ADOPT_SSH_PORT is required and must be a decimal port"
PORT=$((10#$PORT_RAW))
(( PORT >= 8900 && PORT <= 65535 )) \
  || fail "ADOPT_SSH_PORT must be within the explicit disposable range 8900..65535"
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail "DUO_EXPECTED_SOURCE_SHA is required and must be a lowercase 40-character commit SHA"
GIT_DIR="$(git -C "$ROOT" --no-optional-locks rev-parse --path-format=absolute --git-dir 2>/dev/null)" \
  || fail "SSH-adoption live evidence requires a Git checkout"
COMMON_DIR="$(git -C "$ROOT" --no-optional-locks rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
  || fail "could not resolve the Git common directory"
[ "$GIT_DIR" = "$ROOT/.git" ] && [ "$COMMON_DIR" = "$ROOT/.git" ] && [ -d "$ROOT/.git" ] \
  || fail "SSH-adoption live evidence requires a standalone clone, not a linked worktree"
SOURCE_SHA="$(git -C "$ROOT" --no-optional-locks rev-parse --verify 'HEAD^{commit}')" \
  || fail "SSH-adoption live evidence source has no resolvable Git HEAD"
[ "$EXPECTED_SHA" = "$SOURCE_SHA" ] \
  || fail "DUO_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal this checkout HEAD=$SOURCE_SHA"
git -C "$ROOT" --no-optional-locks diff --check \
  || fail "SSH-adoption live evidence checkout has unstaged whitespace errors"
git -C "$ROOT" --no-optional-locks diff --cached --check \
  || fail "SSH-adoption live evidence checkout has staged whitespace errors"
SOURCE_STATUS="$(git -C "$ROOT" --no-optional-locks status --porcelain=v1 --untracked-files=all)" \
  || fail "could not inspect SSH-adoption live evidence source cleanliness"
[ -z "$SOURCE_STATUS" ] \
  || fail "SSH-adoption live evidence requires a clean standalone clone at $SOURCE_SHA"
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
export DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA"
RUN_ID="${PREFIX}-${SOURCE_SHA:0:12}-$$-${RANDOM}${RANDOM}"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${PREFIX}-ssh-adopt.XXXXXX")"
trap cleanup EXIT

ssh_fixture() { ssh -F "$TMP/ssh_config" duo-adopt-fixture "$@"; }

target_ledger_value() {
  local key="$1"
  ssh_fixture "cd /var/www/html && wp db query \"SELECT v FROM wp_duo_kv WHERE k = '$key'\" --skip-column-names"
}

target_checkpoint_state() {
  ssh_fixture "cd /var/www/html && wp db query \"SELECT value FROM duo_cert_state WHERE id = 1\" --skip-column-names"
}

say "build a standalone SSH WordPress host image"
docker build -q \
  --label "duo.live-suite=$SUITE_LABEL" \
  --label "duo.live-run=$RUN_ID" \
  --label "duo.live-source=$SOURCE_SHA" \
  -t "$IMAGE" -f sandbox/tests/fixtures/ssh-adopt.Dockerfile . >/dev/null
IMAGE_OWNED=1
docker network create \
  --label "duo.live-suite=$SUITE_LABEL" \
  --label "duo.live-run=$RUN_ID" \
  --label "duo.live-source=$SOURCE_SHA" \
  "$NET" >/dev/null
NETWORK_OWNED=1
docker volume create \
  --label "duo.live-suite=$SUITE_LABEL" \
  --label "duo.live-run=$RUN_ID" \
  --label "duo.live-source=$SOURCE_SHA" \
  "$VOLUME" >/dev/null
VOLUME_OWNED=1
ssh-keygen -q -t ed25519 -N '' -f "$TMP/id_ed25519"
pass "labeled standalone image, network, volume, and one-run SSH credential created"

say "start an independent database and initialize WordPress core"
docker run -d --name "$DB" --network "$NET" \
  --label "duo.live-suite=$SUITE_LABEL" \
  --label "duo.live-run=$RUN_ID" \
  --label "duo.live-source=$SOURCE_SHA" \
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
  sh -lc 'php -d memory_limit=512M /usr/local/bin/wp core download --path=/var/www/html --allow-root --quiet && chown -R 1000:1000 /var/www/html'
pass "WordPress files initialized without sharing the Duo checkout"

say "start the target and expose only its SSH port"
docker run -d --name "$TARGET" --network "$NET" \
  --label "duo.live-suite=$SUITE_LABEL" \
  --label "duo.live-run=$RUN_ID" \
  --label "duo.live-source=$SOURCE_SHA" \
  -p "127.0.0.1:${PORT}:22" \
  -e DUO_MANIFESTS_DIR=/container-only-value \
  -v "$VOLUME:/var/www/html" \
  -v "$TMP/id_ed25519.pub:/tmp/authorized_key:ro" \
  --entrypoint sh "$IMAGE" -lc \
  'cp /tmp/authorized_key /home/duo/.ssh/authorized_keys; chown duo:duo /home/duo/.ssh/authorized_keys; chmod 0600 /home/duo/.ssh/authorized_keys; exec /usr/sbin/sshd -D -e' \
  >/dev/null
TARGET_OWNED=1
for _ in $(seq 1 60); do
  ssh-keyscan -p "$PORT" 127.0.0.1 >"$TMP/known_hosts" 2>/dev/null && [ -s "$TMP/known_hosts" ] && break
  sleep 1
done
[ -s "$TMP/known_hosts" ] || fail "SSH host key never became available"
cat >"$TMP/ssh_config" <<EOF
Host duo-adopt-fixture
  HostName 127.0.0.1
  Port $PORT
  User duo
  IdentityFile $TMP/id_ed25519
  UserKnownHostsFile $TMP/known_hosts
  StrictHostKeyChecking yes
  IdentitiesOnly yes
  BatchMode yes
EOF
ssh_fixture 'echo duo-ssh-ready' | grep -qx duo-ssh-ready || fail "SSH transport did not become ready"
ssh_fixture 'test -z "${DUO_MANIFESTS_DIR+x}"' \
  || fail "fixture did not reproduce the fresh-SSH DUO_MANIFESTS_DIR gap"
pass "fresh SSH login is reachable and does not inherit the container-only DUO_MANIFESTS_DIR"

say "install WordPress through the SSH boundary"
ssh_fixture "cd /var/www/html && wp config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress-pass --dbhost=$DB --skip-check --quiet"
ssh_fixture "cd /var/www/html && wp core install --url=http://adopt.example.test --title='Adopt Fixture' --admin_user=admin --admin_password=admin-pass --admin_email=admin@example.test --skip-email --quiet"
ssh_fixture "cd /var/www/html && wp db query \"CREATE TABLE duo_cert_state (id bigint primary key, value varchar(191) not null); INSERT INTO duo_cert_state VALUES (1,'prior-db'); CREATE TABLE duo_cert_lease (id bigint primary key, owner varchar(191) not null);\""
if ssh_fixture "cd /var/www/html && wp eval 'echo class_exists(\"\\Duo\\Capture\") ? \"present\" : \"absent\";'" | grep -qx present; then
  fail "fixture unexpectedly started with Duo installed"
fi
pass "pre-existing WordPress target starts without Duo"

php -r '$pair=sodium_crypto_sign_keypair(); file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($pair))."\n");' "$TMP/rollback-signing.key"
chmod 0600 "$TMP/rollback-signing.key"
openssl rand 32 >"$TMP/checkpoint.key"
chmod 0600 "$TMP/checkpoint.key"
jq -n --arg host "$DB" '{host:$host,port:3306,database:"wordpress",user:"wordpress",password:"wordpress-pass",admin_user:"root",admin_password:"root-pass",code_pointer:"/home/duo/recovery-fixture/code-pointer",effect_target:"/home/duo/recovery-fixture/effect-target"}' >"$TMP/checkpoint-db.json"
ssh_fixture 'mkdir -p /home/duo/recovery-fixture /home/duo/recovery-fixture/checkpoint && chmod 700 /home/duo/recovery-fixture /home/duo/recovery-fixture/checkpoint && printf "adopt-code\\n" > /home/duo/recovery-fixture/code-pointer && printf "adopt-effect\\n" > /home/duo/recovery-fixture/effect-target'
scp -F "$TMP/ssh_config" sandbox/tests/fixtures/recovery-exclusion-provider.php sandbox/tests/fixtures/recovery-adapter.php sandbox/tests/fixtures/ssh-rollback-checkpoint-provider.php sandbox/tests/fixtures/code-release-provider.php \
  duo-adopt-fixture:/home/duo/recovery-fixture/ >/dev/null
scp -F "$TMP/ssh_config" "$TMP/checkpoint.key" "$TMP/checkpoint-db.json" \
  duo-adopt-fixture:/home/duo/recovery-fixture/ >/dev/null
ssh_fixture 'chmod 700 /home/duo/recovery-fixture/*.php'
ssh_fixture 'chmod 600 /home/duo/recovery-fixture/checkpoint.key /home/duo/recovery-fixture/checkpoint-db.json'

cat >"$TMP/envs.json" <<EOF
{
  "envs": {
    "target": {
      "transport": "ssh",
      "host": "duo-adopt-fixture",
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
          "code_restore": ["/usr/local/bin/php", "/home/duo/recovery-fixture/recovery-adapter.php"],
          "database_restore": ["/usr/local/bin/php", "/home/duo/recovery-fixture/recovery-adapter.php"],
          "prior_verify": ["/usr/local/bin/php", "/home/duo/recovery-fixture/recovery-adapter.php"],
          "storage_restore": ["/usr/local/bin/php", "/home/duo/recovery-fixture/recovery-adapter.php"]
        },
        "checkpoint_provider": ["/usr/local/bin/php", "/home/duo/recovery-fixture/ssh-rollback-checkpoint-provider.php", "/home/duo/recovery-fixture/checkpoint", "/home/duo/recovery-fixture/checkpoint-db.json", "/home/duo/recovery-fixture/checkpoint.key"],
        "code_release_provider": ["/usr/local/bin/php", "/home/duo/recovery-fixture/code-release-provider.php", "/home/duo/recovery-fixture/code-release-state", "/home/duo/code-releases", "/home/duo/code-current"],
        "exclusion_provider": ["/usr/local/bin/php", "/home/duo/recovery-fixture/recovery-exclusion-provider.php", "/home/duo/recovery-fixture/provider-state.json"],
        "timeout_seconds": 30
      },
      "wp_path": "/var/www/html",
      "repo_path": "/home/duo/site"
    }
  }
}
EOF

say "negotiate the SSH driver before any adoption target call"
if DRIVER_JSON="$("$DUO" --envs-file="$TMP/envs.json" driver-capabilities target --operation=adopt --format=json 2>"$TMP/driver-adopt.err")"; then
  DRIVER_CODE=0
else
  DRIVER_CODE=$?
fi
[ "$DRIVER_CODE" -eq 0 ] || fail "SSH adopt driver preflight failed: $(cat "$TMP/driver-adopt.err")"
php -r '
  $r=json_decode($argv[1],true);
  if (!is_array($r) || ($r["format"] ?? null) !== "duo-environment-driver-capabilities/v1"
      || ($r["driver"]["id"] ?? null) !== "ssh" || ($r["operation"] ?? null) !== "adopt"
      || ($r["ready"] ?? null) !== true
      || preg_match("/^sha256:[a-f0-9]{64}$/", (string)($r["digest"] ?? "")) !== 1) {
    fwrite(STDERR,"invalid SSH adopt driver report\n"); exit(1);
  }
' "$DRIVER_JSON" || fail "SSH adopt driver report was not canonical and ready"
if CREATE_JSON="$("$DUO" --envs-file="$TMP/envs.json" driver-capabilities target --operation=create --format=json 2>"$TMP/driver-create.err")"; then
  CREATE_CODE=0
else
  CREATE_CODE=$?
fi
[ "$CREATE_CODE" -ne 0 ] || fail "attach-only SSH driver silently claimed environment creation"
php -r '$r=json_decode($argv[1],true); exit(is_array($r) && ($r["ready"] ?? null) === false ? 0 : 1);' "$CREATE_JSON" \
  || fail "unsupported SSH create capability was not visible in JSON"
ssh_fixture 'test ! -e /var/www/html/wp-content/mu-plugins/duo && test ! -e /home/duo/site' \
  || fail "driver negotiation contacted or mutated the SSH target"
pass "SSH driver reports adopt ready, create unsupported, and performs zero target mutation during negotiation"

say "adopt the pre-existing target through the product command"
if OUT="$("$DUO" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -eq 0 ] || fail "first duo adopt failed with exit $CODE"
grep -q 'adopt: installed agent 0.5.0 + manifest library + rollback authority; created seed site.duo.json' <<<"$OUT" \
  || fail "first adopt did not report the installed version and seed creation"
grep -q '\[PASS\] duo agent present' <<<"$OUT" || fail "doctor did not pass agent presence"
grep -q '\[PASS\] repo path has site.duo.json (/home/duo/site)' <<<"$OUT" \
  || fail "doctor did not pass the seeded repo"
ssh_fixture "test -f /var/www/html/wp-content/mu-plugins/duo/duo.php && test -f /var/www/html/wp-content/mu-plugins/duo-loader.php && test -f /var/www/html/wp-content/mu-plugins/manifests/core.json"
[ "$(ssh_fixture "cd /var/www/html && wp eval 'echo \\Duo\\Policy::manifests_dir();'")" = "/var/www/html/wp-content/mu-plugins/manifests" ] \
  || fail "fresh process did not select the installed sibling manifest library"
ssh_fixture 'test ! -e /duo-manifests' || fail "adopt unexpectedly required the root-owned fallback"
ssh_fixture 'test -f /home/duo/site/.duo/control/recovery-runtime/rollback-control.php && test -f /home/duo/site/.duo/control/recovery-runtime/RecoveryExecutor.php && test -f /home/duo/site/.duo/control/recovery-runtime/CodeRelease.php && test -f /home/duo/site/.duo/control/recovery-config.json && test -f /home/duo/site/.duo/control/public-keys/fixture-key-1.pub && test -f /home/duo/site/.duo/control/target.json' \
  || fail "adopt did not provision the external rollback authority"
ssh_fixture 'test -f /var/www/html/wp-content/mu-plugins/duo/scoped-promotion-control.json && test "$(stat -c %a /var/www/html/wp-content/mu-plugins/duo/scoped-promotion-control.json)" = 600 && php -r '\''$v=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR); exit(($v["format"]??null)==="duo-scoped-promotion-control/v1" && ($v["control_root"]??null)==="/home/duo/site/.duo/control" ? 0 : 1);'\'' /var/www/html/wp-content/mu-plugins/duo/scoped-promotion-control.json' \
  || fail "adopt did not pin the mode-0600 scoped-promotion recovery trust root"
[ "$(ssh_fixture 'stat -c %a /home/duo/site/.duo/control')" = "700" ] \
  || fail "rollback control root is not protected mode 0700"
TARGET_ID="$(ssh_fixture "php -r 'echo json_decode(file_get_contents(\"/home/duo/site/.duo/control/target.json\"),true)[\"target_id\"];'")"
[ "${#TARGET_ID}" -eq 32 ] || fail "rollback authority did not establish a stable target identity"
ssh_fixture 'test ! -e /home/duo/site/.duo/control/rollback-signing.key && test ! -e /home/duo/site/.duo/control/private-keys' \
  || fail "adoption copied private signing material to the target"
pass "agent, manifests, seed repo, public-key-only rollback authority, and doctor verify through SSH"

ssh_fixture 'mv /var/www/html/wp-config.php /var/www/html/wp-config.broken; printf "%s\n" "<?php throw new RuntimeException(\"broken bootstrap\");" > /var/www/html/wp-config.php'
ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php recovery-probe --root=/home/duo/site/.duo/control' \
  | grep -q '"provider_id":"ssh-fixture-provider"' \
  || fail "raw recovery probe depended on the WordPress bootstrap"
ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php recovery-probe --root=/home/duo/site/.duo/control' \
  | grep -q '"provider_id":"ssh-mariadb-checkpoint"' \
  || fail "raw checkpoint probe depended on the WordPress bootstrap"
ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php recovery-probe --root=/home/duo/site/.duo/control' \
  | grep -q '"provider_id":"ssh-release-fixture"' \
  || fail "raw code-release probe depended on the WordPress bootstrap"
ssh_fixture 'rm /var/www/html/wp-config.php; mv /var/www/html/wp-config.broken /var/www/html/wp-config.php'
pass "configured exclusion, checkpoint/code-release providers, and all four recovery adapters probe over raw SSH with WordPress broken"

if STATUS_OUT="$("$DUO" --envs-file="$TMP/envs.json" status target 2>&1)"; then STATUS_CODE=0; else STATUS_CODE=$?; fi
grep -q '\[PASS\] rollback authority: ready (no active generation)' <<<"$STATUS_OUT" \
  || fail "status did not verify and render the external rollback authority"
ssh_fixture 'cp /home/duo/site/.duo/control/target.json /home/duo/site/.duo/control/target.valid.json && printf " " >> /home/duo/site/.duo/control/target.json'
if BAD_STATUS="$("$DUO" --envs-file="$TMP/envs.json" status target 2>&1)"; then BAD_STATUS_CODE=0; else BAD_STATUS_CODE=$?; fi
[ "$BAD_STATUS_CODE" -ne 0 ] && grep -q '\[FAIL\] rollback authority: invalid' <<<"$BAD_STATUS" \
  || fail "tampered authority did not make status non-green"
if FENCE_OUT="$("$DUO" --envs-file="$TMP/envs.json" promote target 2>&1)"; then FENCE_CODE=0; else FENCE_CODE=$?; fi
[ "$FENCE_CODE" -ne 0 ] && grep -q 'rollback authority is invalid; refusing target mutation' <<<"$FENCE_OUT" \
  || fail "promotion did not fail closed at the external authority fence"
ssh_fixture 'test ! -d /home/duo/site/.duo/artifacts && mv /home/duo/site/.duo/control/target.valid.json /home/duo/site/.duo/control/target.json' \
  || fail "authority refusal occurred after promotion created artifacts"
pass "status detects tampering and promotion refuses before its first target mutation"

say "prove update/idempotence without overwriting site policy"
ssh_fixture "php -r '\$p=\"/home/duo/site/site.duo.json\"; \$d=json_decode(file_get_contents(\$p),true); \$d[\"adoption_probe\"]=\"retain\"; file_put_contents(\$p,json_encode(\$d));'"
ssh_fixture "sed -i \"s/DUO_AGENT_VERSION', '0.5.0/DUO_AGENT_VERSION', '0.0.0/\" /var/www/html/wp-content/mu-plugins/duo/duo.php"
[ "$(ssh_fixture "cd /var/www/html && wp eval 'echo DUO_AGENT_VERSION;'")" = "0.0.0" ] \
  || fail "could not create the stale-agent precondition"
if OUT="$("$DUO" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -eq 0 ] || fail "second duo adopt failed with exit $CODE"
grep -q 'retained existing site.duo.json' <<<"$OUT" || fail "rerun did not report non-destructive repo retention"
[ "$(ssh_fixture "cd /var/www/html && wp eval 'echo DUO_AGENT_VERSION;'")" = "0.5.0" ] \
  || fail "rerun did not update the stale agent to the orchestrator's exact version"
[ "$(ssh_fixture "php -r 'echo json_decode(file_get_contents(\"/home/duo/site/site.duo.json\"),true)[\"adoption_probe\"] ?? \"missing\";'")" = "retain" ] \
  || fail "rerun overwrote existing site.duo.json"
[ "$(ssh_fixture "php -r 'echo json_decode(file_get_contents(\"/home/duo/site/.duo/control/target.json\"),true)[\"target_id\"];'")" = "$TARGET_ID" ] \
  || fail "rerun replaced the stable rollback target identity"
pass "rerun updates stale code/manifests while retaining site policy and rollback target identity"

say "roll back the installed release when fresh policy verification fails"
ssh_fixture 'cp /home/duo/site/site.duo.json /home/duo/site/site.duo.valid.json'
ssh_fixture "sed -i \"s/DUO_AGENT_VERSION', '0.5.0/DUO_AGENT_VERSION', '0.0.0/\" /var/www/html/wp-content/mu-plugins/duo/duo.php"
ssh_fixture "printf '%s\n' '{invalid-json' > /home/duo/site/site.duo.json"
BEFORE_AGENT="$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/duo/duo.php')"
BEFORE_MANIFEST="$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/manifests/core.json')"
BEFORE_RUNTIME="$(ssh_fixture 'cksum /home/duo/site/.duo/control/recovery-runtime/rollback-control.php')"
BEFORE_SITE="$(ssh_fixture 'cksum /home/duo/site/site.duo.json')"
if OUT="$("$DUO" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -ne 0 ] || fail "adopt reported success for an invalid existing site policy"
grep -q 'adopt failed during policy verification' <<<"$OUT" \
  || fail "invalid existing policy did not fail at the named verification boundary"
[ "$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/duo/duo.php')" = "$BEFORE_AGENT" ] \
  || fail "policy-verification failure did not restore the previous agent"
[ "$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/manifests/core.json')" = "$BEFORE_MANIFEST" ] \
  || fail "policy-verification failure did not restore the previous manifests"
[ "$(ssh_fixture 'cksum /home/duo/site/.duo/control/recovery-runtime/rollback-control.php')" = "$BEFORE_RUNTIME" ] \
  || fail "policy-verification failure did not restore the previous rollback runtime"
[ "$(ssh_fixture 'cksum /home/duo/site/site.duo.json')" = "$BEFORE_SITE" ] \
  || fail "policy-verification failure changed the existing site policy"
ssh_fixture 'mv /home/duo/site/site.duo.valid.json /home/duo/site/site.duo.json'
"$DUO" --envs-file="$TMP/envs.json" adopt target >/dev/null \
  || fail "adopt did not recover after the valid policy was restored"
pass "post-swap verification failure restores agent/manifests and leaves site policy unchanged"

say "refuse a symlink destination without mutating the installed release"
BEFORE_AGENT="$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/duo/duo.php')"
BEFORE_SITE="$(ssh_fixture 'cksum /home/duo/site/site.duo.json')"
ssh_fixture 'cd /var/www/html/wp-content/mu-plugins && mv manifests manifests-real && ln -s manifests-real manifests'
if OUT="$("$DUO" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -ne 0 ] || fail "adopt followed a symlink destination"
grep -q 'refusing symlink destination: /var/www/html/wp-content/mu-plugins/manifests' <<<"$OUT" \
  || fail "symlink refusal did not identify the unsafe destination"
[ "$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/duo/duo.php')" = "$BEFORE_AGENT" ] \
  || fail "symlink refusal changed the installed agent"
[ "$(ssh_fixture 'cksum /home/duo/site/site.duo.json')" = "$BEFORE_SITE" ] \
  || fail "symlink refusal changed site.duo.json"
ssh_fixture 'cd /var/www/html/wp-content/mu-plugins && rm manifests && mv manifests-real manifests'
pass "unsafe destination is a loud failure with agent and site policy unchanged"

say "exercise a real checkpointed SSH scoped promotion and its recovery boundary"
ssh_fixture 'php -r '\''$p="/home/duo/site/site.duo.json"; $d=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR); $d["policy"]["options"]["duo3344_scoped_option"]=["autoload"=>"preserve","class"=>"authored"]; file_put_contents($p,json_encode($d,JSON_UNESCAPED_SLASHES)."\n");'\'''
ssh_fixture 'cd /var/www/html && wp option update duo3344_scoped_option desired-failure --autoload=no >/dev/null'
"$DUO" --envs-file="$TMP/envs.json" capture target --format=json >"$TMP/duo3344-failure-capture.json" \
  || fail "could not capture the desired scoped-promotion source state"
"$DUO" --envs-file="$TMP/envs.json" scope target --roots=options --contract >"$TMP/duo3344-failure-scope.json" \
  || fail "could not mint the desired scoped-promotion contract"
ssh_fixture 'cd /var/www/html && wp option update duo3344_scoped_option prior-failure --autoload=no >/dev/null'

# Begin and abort an ordinary promotion first. The target deliberately retains
# its completed ordinary session record, exercising the scoped begin reclaim
# boundary rather than assuming a newly adopted target is session-empty.
ORDINARY_OWNER="ordinary-duo3344-completed"
ORDINARY_ARTIFACT="$(printf %s duo3344-ordinary-completed | shasum -a 256 | awk '{print $1}')"
ssh_fixture "cd /var/www/html && wp duo promotion-begin --promotion-owner=$ORDINARY_OWNER --artifact-hash=$ORDINARY_ARTIFACT --format=json" >"$TMP/duo3344-ordinary-begin.json" \
  || fail "could not establish the completed ordinary-session precondition"
ssh_fixture "cd /var/www/html && wp duo promotion-abort --promotion-owner=$ORDINARY_OWNER --artifact-hash=$ORDINARY_ARTIFACT --format=json" >"$TMP/duo3344-ordinary-abort.json" \
  || fail "could not retire the ordinary promotion lock"
[ -z "$(target_ledger_value promotion_lock)" ] \
  || fail "ordinary promotion left an active target lock"
ORDINARY_SESSION="$(target_ledger_value promotion_session)"
jq -e --arg owner "$ORDINARY_OWNER" --arg artifact "$ORDINARY_ARTIFACT" '
  .owner == $owner and .artifact_hash == $artifact
  and ((.profile // "") != "scoped-checkpoint-v1") and (.lifecycle_attempt? | not)
' <<<"$ORDINARY_SESSION" >/dev/null \
  || fail "target did not retain the safely completed ordinary session precondition"

cat >"$TMP/duo3344-scoped-promotion-fault.php" <<'PHP'
<?php
declare(strict_types=1);

if (defined('WP_CLI') && WP_CLI
    && is_file('/home/duo/recovery-fixture/duo3344-scoped-fault-active')) {
    $argv = $GLOBALS['argv'] ?? [];
    if (is_array($argv) && in_array('duo', $argv, true) && in_array('apply', $argv, true)) {
        add_action('plugins_loaded', static function (): void {
            global $wpdb;
            if ($wpdb->query("UPDATE duo_cert_state SET value = 'mutated-after-checkpoint' WHERE id = 1") !== 1) {
                throw new RuntimeException('DUO-3344 test fixture could not mutate the checkpoint probe');
            }
            if (!update_option('duo3344_scoped_restore_probe', 'mutated-after-checkpoint', false)) {
                throw new RuntimeException('DUO-3344 test fixture could not mutate the WordPress restore probe');
            }
            putenv('DUO_TEST_MODE=1');
            putenv('DUO_TEST_FAIL_DB_CONTEXT=rebuild object cache');
        }, PHP_INT_MAX);
    }
}
PHP
scp -F "$TMP/ssh_config" "$TMP/duo3344-scoped-promotion-fault.php" \
  duo-adopt-fixture:/var/www/html/wp-content/mu-plugins/duo3344-scoped-promotion-fault.php >/dev/null
ssh_fixture 'touch /home/duo/recovery-fixture/duo3344-scoped-fault-active'

if FAILURE_OUT="$("$DUO" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/duo3344-failure-scope.json" 2>&1)"; then
  FAILURE_CODE=0
else
  FAILURE_CODE=$?
fi
ssh_fixture 'rm -f /home/duo/recovery-fixture/duo3344-scoped-fault-active /var/www/html/wp-content/mu-plugins/duo3344-scoped-promotion-fault.php'
[ "$FAILURE_CODE" -ne 0 ] || fail "post-begin scoped fault unexpectedly promoted"
grep -q 'scoped promote phase: promotion-begin-scoped' <<<"$FAILURE_OUT" \
  || fail "controlled scoped fault did not cross target promotion-begin-scoped"
grep -q 'scoped promote phase: apply' <<<"$FAILURE_OUT" \
  || fail "controlled scoped fault did not enter the receipt-bound target apply"
grep -q 'prior database verified; generation .* rolled_back and exclusion released' <<<"$FAILURE_OUT" \
  || fail "controlled scoped fault did not report verified pre-commit rollback"

FAIL_STATUS="$(ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php status --root=/home/duo/site/.duo/control')"
jq -e '
  .ok == true and .receipt_format == "duo-scoped-promotion-receipt/v1"
  and .state == "rolled_back" and .terminal == true and .exclusion_state == "released"
' <<<"$FAIL_STATUS" >/dev/null \
  || fail "scoped failure did not leave a signed rolled_back terminal receipt with v2 exclusion released"
FAIL_EVIDENCE="$(ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php active-evidence --root=/home/duo/site/.duo/control')"
jq -e '
  .status.state == "rolled_back"
  and .completed_operations.database_restore.operation_status == "completed"
  and .completed_operations.prior_verify.operation_status == "completed"
' <<<"$FAIL_EVIDENCE" >/dev/null \
  || fail "signed failure evidence did not bind encrypted database_restore and prior_verify"
FAIL_RECEIPT="$(jq -r '.receipt_id' <<<"$FAIL_STATUS")"
ssh_fixture "test -s /home/duo/site/.duo/rollback/$FAIL_RECEIPT/artifacts/checkpoint.enc" \
  || fail "rolled-back scoped generation did not retain its real encrypted database checkpoint"
[ "$(target_checkpoint_state)" = "prior-db" ] \
  || fail "encrypted scoped rollback did not restore the prior database value"
[ "$(ssh_fixture 'cd /var/www/html && wp option get duo3344_scoped_option')" = "prior-failure" ] \
  || fail "encrypted scoped rollback did not restore the prior authored option"
if ssh_fixture 'cd /var/www/html && wp option get duo3344_scoped_restore_probe' >/dev/null 2>&1; then
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
jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/duo/recovery-fixture/provider-state.json')" >/dev/null \
  || fail "v2 exclusion provider did not release after scoped rollback"

ssh_fixture 'cd /var/www/html && wp option update duo3344_scoped_option desired-success --autoload=no >/dev/null'
"$DUO" --envs-file="$TMP/envs.json" capture target --format=json >"$TMP/duo3344-success-capture.json" \
  || fail "could not capture the successful scoped-promotion source state"
"$DUO" --envs-file="$TMP/envs.json" scope target --roots=options --contract >"$TMP/duo3344-success-scope.json" \
  || fail "could not mint the successful scoped-promotion contract"
ssh_fixture 'cd /var/www/html && wp option update duo3344_scoped_option prior-success --autoload=no >/dev/null'

if SUCCESS_JSON="$("$DUO" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/duo3344-success-scope.json" --format=json 2>"$TMP/duo3344-success.err")"; then
  SUCCESS_CODE=0
else
  SUCCESS_CODE=$?
fi
[ "$SUCCESS_CODE" -eq 0 ] \
  || fail "public SSH scoped promote did not complete: $(cat "$TMP/duo3344-success.err")"
jq -e --argjson failed_generation "$(jq -r '.generation' <<<"$FAIL_STATUS")" '
  .format == "duo-scoped-promotion-result/v1" and .state == "committed"
  and (.generation > $failed_generation)
  and .rollback.format == "duo-scoped-promotion-receipt/v1"
  and .rollback.automatic_window_closed == true and .rollback.later_rollback_supported == false
  and .scoped_apply.format == "duo-scoped-apply-result/v1"
  and .scoped_apply.scoped_receipt.phase == "complete"
' <<<"$SUCCESS_JSON" >/dev/null \
  || fail "successful scoped promotion did not return its receipt-bound terminal result"
SUCCESS_STATUS="$(ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php status --root=/home/duo/site/.duo/control')"
jq -e '
  .ok == true and .receipt_format == "duo-scoped-promotion-receipt/v1"
  and .state == "committed" and .terminal == true and .exclusion_state == "released"
' <<<"$SUCCESS_STATUS" >/dev/null \
  || fail "successful scoped promotion did not leave a signed committed terminal receipt with v2 exclusion released"
SUCCESS_EVIDENCE="$(ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php active-evidence --root=/home/duo/site/.duo/control')"
jq -e '
  .status.state == "committed"
  and .completed_operations.scoped_apply.operation_status == "completed"
' <<<"$SUCCESS_EVIDENCE" >/dev/null \
  || fail "successful scoped promotion did not bind the terminal target Apply receipt into signed evidence"
[ "$(ssh_fixture 'cd /var/www/html && wp option get duo3344_scoped_option')" = "desired-success" ] \
  || fail "successful scoped promotion did not converge the target authored option"
"$DUO" --envs-file="$TMP/envs.json" plan target --scope-contract="$TMP/duo3344-success-scope.json" --format=json >"$TMP/duo3344-success-plan.json" \
  || fail "successful scoped promotion did not permit a converged public scoped plan"
jq -e '
  .format == "duo-scoped-plan/v1"
  and .create == [] and .update == [] and .drift == [] and .conflict == []
  and .delete == [] and .delete_conflict == []
' "$TMP/duo3344-success-plan.json" >/dev/null \
  || fail "successful scoped promotion target did not converge"
[ -z "$(target_ledger_value promotion_lock)" ] \
  || fail "successful scoped promotion left an active target lock"
[ -z "$(target_ledger_value promotion_session)" ] \
  || fail "successful scoped promotion did not execute target promotion-complete-scoped"
jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/duo/recovery-fixture/provider-state.json')" >/dev/null \
  || fail "v2 exclusion provider did not release after scoped commit"
pass "public scoped promotion restores a real encrypted DB checkpoint on failure, then commits a receipt-bound target Apply and retires its scoped session"

printf '\n\033[1;32m✔ REGRESS_SSH_ADOPT PASSED\033[0m\n'
