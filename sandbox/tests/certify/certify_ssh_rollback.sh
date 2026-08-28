#!/usr/bin/env bash
# DUO-3299: production-form SSH rollback certification. Four disposable
# containers provide two independent SSH hosts and two independent MariaDB
# servers. All authority/recovery actions cross SSH through product APIs.
set -euo pipefail
export COPYFILE_DISABLE=1

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PREFIX="${SSH_ROLLBACK_FIXTURE:-codexmaca3299}"
SOURCE_PORT="${SSH_ROLLBACK_SOURCE_PORT:-9396}"
TARGET_PORT="${SSH_ROLLBACK_TARGET_PORT:-9397}"
NET="${PREFIX}-net"
SOURCE_DB="${PREFIX}-source-db"
TARGET_DB="${PREFIX}-target-db"
SOURCE="${PREFIX}-source"
TARGET="${PREFIX}-target"
SOURCE_VOLUME="${PREFIX}-source-wordpress"
TARGET_VOLUME="${PREFIX}-target-wordpress"
IMAGE="${PREFIX}-ssh-image"
TMP="$(mktemp -d /tmp/duo3299.XXXXXX)"
EVIDENCE_ROOT="${SSH_ROLLBACK_EVIDENCE_DIR:-$ROOT/sandbox/tmp/ssh-rollback-certification}"
DUO="$ROOT/cli/duo"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"

cleanup_fixture() {
  docker rm -f "$SOURCE" "$TARGET" "$SOURCE_DB" "$TARGET_DB" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  docker volume rm "$SOURCE_VOLUME" "$TARGET_VOLUME" >/dev/null 2>&1 || true
  docker image rm "$IMAGE" >/dev/null 2>&1 || true
}
cleanup_all() {
  cleanup_fixture
  rm -rf "$TMP"
}
trap cleanup_all EXIT
cleanup_fixture
mkdir -p "$TMP" "$EVIDENCE_ROOT"

ssh_source() { ssh -F "$TMP/ssh_config" duo-rollback-source "$@"; }
ssh_target() { ssh -F "$TMP/ssh_config" duo-rollback-target "$@"; }

say "build two SSH hosts and two independent database servers"
docker build -q -t "$IMAGE" -f sandbox/tests/fixtures/ssh-adopt.Dockerfile . >/dev/null
docker network create "$NET" >/dev/null
docker volume create "$SOURCE_VOLUME" >/dev/null
docker volume create "$TARGET_VOLUME" >/dev/null
ssh-keygen -q -t ed25519 -N '' -f "$TMP/id_ed25519"

docker run -d --name "$SOURCE_DB" --network "$NET" \
  -e MARIADB_ROOT_PASSWORD=source-root-pass -e MARIADB_DATABASE=sourcewp \
  -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=source-wordpress-pass mariadb:11.8 >/dev/null
docker run -d --name "$TARGET_DB" --network "$NET" \
  -e MARIADB_ROOT_PASSWORD=target-root-pass -e MARIADB_DATABASE=targetwp \
  -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=target-wordpress-pass mariadb:11.8 >/dev/null
for database in "$SOURCE_DB" "$TARGET_DB"; do
  for _ in $(seq 1 60); do
    password=source-root-pass
    [ "$database" = "$TARGET_DB" ] && password=target-root-pass
    docker exec "$database" mariadb-admin ping -h 127.0.0.1 -uroot -p"$password" --silent >/dev/null 2>&1 && break
    sleep 1
  done
  docker exec "$database" mariadb-admin ping -h 127.0.0.1 -uroot -p"$password" --silent >/dev/null 2>&1 \
    || fail "$database did not become ready"
done
# The SSH estate installs WordPress from wp.org; without a version it floats to
# whatever core is current that day and silently leaves the exercised platform
# boundary (measured 2026-08-24: `wp core download` fetched 7.1 while the claim
# still stopped at 7.1.0 — the 7.1 series has since been claimed, but a floating
# fetch would leave the boundary again on the next release either way). Pin to
# the newest exercised core the claim itself names, read from the shipped
# boundary so estate and claim cannot drift apart.
WP_CORE_VERSION="$(jq -r '.platform.compatibility.wordpress.last_verified' platform/adapter-library/capabilities/platform.json)"
[[ "$WP_CORE_VERSION" =~ ^[0-9]+(\.[0-9]+){1,3}$ ]] || fail "platform.json names no usable last_verified WordPress core"

for volume in "$SOURCE_VOLUME" "$TARGET_VOLUME"; do
  docker run --rm --user root --network "$NET" -v "$volume:/var/www/html" wordpress:cli-php8.3 \
    sh -lc 'php -d memory_limit=512M /usr/local/bin/wp core download --version="'"$WP_CORE_VERSION"'" --path=/var/www/html --allow-root --quiet && chown -R 1000:1000 /var/www/html'
done

docker run -d --name "$SOURCE" --network "$NET" -p "127.0.0.1:${SOURCE_PORT}:22" \
  -v "$SOURCE_VOLUME:/var/www/html" -v "$TMP/id_ed25519.pub:/tmp/authorized_key:ro" \
  --entrypoint sh "$IMAGE" -lc \
  'cp /tmp/authorized_key /home/duo/.ssh/authorized_keys; chown duo:duo /home/duo/.ssh/authorized_keys; chmod 0600 /home/duo/.ssh/authorized_keys; exec /usr/sbin/sshd -D -e' >/dev/null
docker run -d --name "$TARGET" --network "$NET" -p "127.0.0.1:${TARGET_PORT}:22" \
  -v "$TARGET_VOLUME:/var/www/html" -v "$TMP/id_ed25519.pub:/tmp/authorized_key:ro" \
  --entrypoint sh "$IMAGE" -lc \
  'cp /tmp/authorized_key /home/duo/.ssh/authorized_keys; chown duo:duo /home/duo/.ssh/authorized_keys; chmod 0600 /home/duo/.ssh/authorized_keys; exec /usr/sbin/sshd -D -e' >/dev/null

for port in "$SOURCE_PORT" "$TARGET_PORT"; do
  for _ in $(seq 1 60); do ssh-keyscan -p "$port" 127.0.0.1 >>"$TMP/known_hosts" 2>/dev/null && break; sleep 1; done
done
cat >"$TMP/ssh_config" <<EOF
Host duo-rollback-source
  HostName 127.0.0.1
  Port $SOURCE_PORT
  User duo
  IdentityFile $TMP/id_ed25519
  UserKnownHostsFile $TMP/known_hosts
  StrictHostKeyChecking yes
  IdentitiesOnly yes
  BatchMode yes
  ControlMaster auto
  ControlPersist 60
  ControlPath $TMP/ssh-%C
Host duo-rollback-target
  HostName 127.0.0.1
  Port $TARGET_PORT
  User duo
  IdentityFile $TMP/id_ed25519
  UserKnownHostsFile $TMP/known_hosts
  StrictHostKeyChecking yes
  IdentitiesOnly yes
  BatchMode yes
  ControlMaster auto
  ControlPersist 60
  ControlPath $TMP/ssh-%C
EOF
ssh_source 'echo source-ready' | grep -qx source-ready || fail "source SSH host not ready"
ssh_target 'echo target-ready' | grep -qx target-ready || fail "target SSH host not ready"
pass "two independent SSH hosts and two independent database servers are ready"

say "install independent WordPress databases and target-owned recovery providers"
ssh_source "cd /var/www/html && wp config create --dbname=sourcewp --dbuser=wordpress --dbpass=source-wordpress-pass --dbhost=$SOURCE_DB --skip-check --quiet"
ssh_target "cd /var/www/html && wp config create --dbname=targetwp --dbuser=wordpress --dbpass=target-wordpress-pass --dbhost=$TARGET_DB --skip-check --quiet"
ssh_source "cd /var/www/html && wp core install --url=http://source.rollback.test --title=Source --admin_user=admin --admin_password=admin-pass --admin_email=source@example.test --skip-email --quiet"
ssh_target "cd /var/www/html && wp core install --url=http://target.rollback.test --title=Target --admin_user=admin --admin_password=admin-pass --admin_email=target@example.test --skip-email --quiet"
ssh_source "cd /var/www/html && wp db query \"CREATE TABLE duo_cert_state (id bigint primary key, value varchar(191) not null); INSERT INTO duo_cert_state VALUES (1,'desired-source');\""
ssh_target "cd /var/www/html && wp db query \"CREATE TABLE duo_cert_state (id bigint primary key, value varchar(191) not null); INSERT INTO duo_cert_state VALUES (1,'prior'); CREATE TABLE duo_cert_lease (id bigint primary key, owner varchar(191) not null);\""

ssh_target 'mkdir -p /home/duo/providers /home/duo/provider-state /home/duo/releases/release-prior/wp-content/plugins/acme /home/duo/releases/release-desired-1/wp-content/plugins/acme /home/duo/releases/release-desired-2/wp-content/plugins/acme /home/duo/uploads/2026/08 /home/duo/offload /home/duo/media /home/duo/site/wp-content/uploads'
for file in recovery-exclusion-provider.php recovery-adapter.php ssh-rollback-checkpoint-provider.php code-release-provider.php upload-provider.php effect-provider.php ssh-rollback-db-loss-probe.php; do
  scp -F "$TMP/ssh_config" "sandbox/tests/fixtures/$file" "duo-rollback-target:/home/duo/providers/$file" >/dev/null
done
ssh_target 'chmod 0700 /home/duo/providers/*.php'
printf "%s\n" "<?php echo 'prior';" >"$TMP/prior.php"
printf "%s\n" "<?php echo 'desired-1';" >"$TMP/desired-1.php"
printf "%s\n" "<?php echo 'desired-2';" >"$TMP/desired-2.php"
scp -F "$TMP/ssh_config" "$TMP/prior.php" duo-rollback-target:/home/duo/releases/release-prior/wp-content/plugins/acme/acme.php >/dev/null
scp -F "$TMP/ssh_config" "$TMP/desired-1.php" duo-rollback-target:/home/duo/releases/release-desired-1/wp-content/plugins/acme/acme.php >/dev/null
scp -F "$TMP/ssh_config" "$TMP/desired-2.php" duo-rollback-target:/home/duo/releases/release-desired-2/wp-content/plugins/acme/acme.php >/dev/null
ssh_target "printf 'release-prior\\n' > /home/duo/provider-state/code-pointer"
ssh_target "printf 'prior-effect' > /home/duo/site/wp-content/uploads/duo-rollback-effect.txt"
ssh_target "printf 'prior-upload' > /home/duo/uploads/2026/08/photo.jpg"
printf 'desired-upload' >"$TMP/desired-upload"
MEDIA_SHA="$(shasum -a 256 "$TMP/desired-upload" | awk '{print $1}')"
MEDIA_BLOB="${MEDIA_SHA}.jpg"
scp -F "$TMP/ssh_config" "$TMP/desired-upload" "duo-rollback-target:/home/duo/media/$MEDIA_BLOB" >/dev/null
openssl rand 32 >"$TMP/checkpoint.key"
openssl rand 32 >"$TMP/upload.key"
scp -F "$TMP/ssh_config" "$TMP/checkpoint.key" duo-rollback-target:/home/duo/provider-state/checkpoint.key >/dev/null
scp -F "$TMP/ssh_config" "$TMP/upload.key" duo-rollback-target:/home/duo/provider-state/upload.key >/dev/null
ssh_target 'chmod 0600 /home/duo/provider-state/*.key'

jq -n --arg host "$TARGET_DB" '{host:$host,port:3306,database:"targetwp",user:"wordpress",password:"target-wordpress-pass",admin_user:"root",admin_password:"target-root-pass",code_pointer:"/home/duo/provider-state/code-pointer",effect_target:"/home/duo/site/wp-content/uploads/duo-rollback-effect.txt"}' >"$TMP/db.json"
scp -F "$TMP/ssh_config" "$TMP/db.json" duo-rollback-target:/home/duo/provider-state/db.json >/dev/null
ssh_target 'chmod 0600 /home/duo/provider-state/db.json'

php -r '$pair=sodium_crypto_sign_keypair();file_put_contents($argv[1],base64_encode(sodium_crypto_sign_secretkey($pair))."\n");file_put_contents($argv[2],base64_encode(sodium_crypto_sign_publickey($pair))."\n");' "$TMP/signing.key" "$TMP/public.key"
chmod 0600 "$TMP/signing.key"

# These are the two immutable compiled-plan identities exercised by the
# certification generations. The driver consumes these plan inventories via
# VerifiedRollbackProfile::claimFields; it no longer manufactures a second,
# potentially divergent upload/effect inventory beside the product wiring.
ARTIFACT_1="$(printf %s artifact-generation-1 | shasum -a 256 | awk '{print $1}')"
ARTIFACT_2="$(printf %s artifact-generation-2 | shasum -a 256 | awk '{print $1}')"
CODE_FILE_SHA_1="$(shasum -a 256 "$TMP/desired-1.php" | awk '{print $1}')"
CODE_FILE_SHA_2="$(shasum -a 256 "$TMP/desired-2.php" | awk '{print $1}')"
CODE_REVISION_1="$(php -r 'require $argv[1];$sha=$argv[2];$code=["files"=>[["path"=>"plugins/acme/acme.php","sha256"=>$sha]],"format"=>"duo-code/v1","layout"=>"wp-content","owned_roots"=>["plugins/acme"],"plugin_main_files"=>[["basename"=>"acme/acme.php","path"=>"plugins/acme/acme.php","sha256"=>$sha]],"source"=>"code/wp-content","theme_slugs"=>[],"theme_templates"=>[]];echo hash("sha256",Duo\Canon::encode($code));' "$ROOT/agent/src/Kernel/Canon.php" "$CODE_FILE_SHA_1")"
CODE_REVISION_2="$(php -r 'require $argv[1];$sha=$argv[2];$code=["files"=>[["path"=>"plugins/acme/acme.php","sha256"=>$sha]],"format"=>"duo-code/v1","layout"=>"wp-content","owned_roots"=>["plugins/acme"],"plugin_main_files"=>[["basename"=>"acme/acme.php","path"=>"plugins/acme/acme.php","sha256"=>$sha]],"source"=>"code/wp-content","theme_slugs"=>[],"theme_templates"=>[]];echo hash("sha256",Duo\Canon::encode($code));' "$ROOT/agent/src/Kernel/Canon.php" "$CODE_FILE_SHA_2")"

cat >"$TMP/envs.json" <<EOF
{
  "envs": {
    "source": {
      "transport": "ssh", "host": "duo-rollback-source", "ssh_config": "$TMP/ssh_config",
      "wp_path": "/var/www/html", "repo_path": "/home/duo/site"
    },
    "target": {
      "transport": "ssh", "host": "duo-rollback-target", "ssh_config": "$TMP/ssh_config",
      "wp_path": "/var/www/html", "repo_path": "/home/duo/site",
      "rollback_key_id": "duo-3299-live", "rollback_signing_key": "$TMP/signing.key",
      "verified_rollback": {
        "claim_ttl_seconds": 120,
        "encryption_key_id": "ssh-kms-fixture",
        "retention_seconds": 86400
      },
      "rollback_recovery": {
        "adapters": {
          "code_restore": ["/usr/local/bin/php", "/home/duo/providers/recovery-adapter.php"],
          "database_restore": ["/usr/local/bin/php", "/home/duo/providers/recovery-adapter.php"],
          "prior_verify": ["/usr/local/bin/php", "/home/duo/providers/recovery-adapter.php"],
          "storage_restore": ["/usr/local/bin/php", "/home/duo/providers/recovery-adapter.php"]
        },
        "checkpoint_provider": ["/usr/local/bin/php", "/home/duo/providers/ssh-rollback-checkpoint-provider.php", "/home/duo/provider-state/checkpoint", "/home/duo/provider-state/db.json", "/home/duo/provider-state/checkpoint.key"],
        "code_release_provider": ["/usr/local/bin/php", "/home/duo/providers/code-release-provider.php", "/home/duo/provider-state/code", "/home/duo/releases", "/home/duo/provider-state/code-pointer"],
        "effect_provider": ["/usr/local/bin/php", "/home/duo/providers/effect-provider.php", "/home/duo/provider-state/effects", "/home/duo/site"],
        "exclusion_provider": ["/usr/local/bin/php", "/home/duo/providers/recovery-exclusion-provider.php", "/home/duo/provider-state/exclusion.json"],
        "timeout_seconds": 30,
        "upload_provider": ["/usr/local/bin/php", "/home/duo/providers/upload-provider.php", "/home/duo/provider-state/uploads", "/home/duo/uploads", "/home/duo/offload", "/home/duo/media", "/home/duo/provider-state/upload.key"]
      }
    }
  },
  "plans": {
    "1": {
      "artifact_hash": "$ARTIFACT_1",
      "code": {
        "code_revision": "$CODE_REVISION_1",
        "files": [{"path":"plugins/acme/acme.php","sha256":"$CODE_FILE_SHA_1"}],
        "format": "duo-code/v1", "layout": "wp-content", "owned_roots": ["plugins/acme"],
        "plugin_main_files": [{"basename":"acme/acme.php","path":"plugins/acme/acme.php","sha256":"$CODE_FILE_SHA_1"}],
        "source": "code/wp-content", "theme_slugs": [], "theme_templates": []
      },
      "effects_inventory": [
        {"effect":{"adapter":{"id":"fixture-file","inverse":"restore-bytes","inverse_inputs":["path","prior_sha256"],"verifier":"fresh-readback","verifier_inputs":["path","prior_sha256"],"version":"1.0.0"},"id":"lifecycle-file","kind":"filesystem","mode":"reversible","selector":{"scope":"external","type":"path","value":"wp-content/uploads/duo-rollback-effect.txt"}},"manifest":"rollback-fixture","phase":"lifecycle","source":"lifecycle_effects"},
        {"effect":{"id":"rebuild-table","kind":"database","mode":"restorable","selector":{"scope":"database_checkpoint","type":"table","value":"duo_cert_state"}},"manifest":"rollback-fixture","phase":"rebuild","source":"provider:rollback-fixture/rebuild_table"},
        {"effect":{"id":"prevent-http","kind":"http","mode":"prevented","prevention":"receipt_outbox","selector":{"scope":"external","type":"url_prefix","value":"https://rollback.invalid/hooks/"}},"manifest":"rollback-fixture","phase":"lifecycle","source":"lifecycle_effects"}
      ],
      "resolved_adapters": [{"name":"rollback-fixture","version":"1.0.0"}],
      "uploads_inventory": [{"attachment_uuid":"11111111-1111-4111-8111-111111111111","derivative_basename_prefix":"photo-","derivative_directory":"2026/08","media_blob":"$MEDIA_BLOB","original_path":"2026/08/photo.jpg","original_sha256":"$MEDIA_SHA"}]
    },
    "2": {
      "artifact_hash": "$ARTIFACT_2",
      "code": {
        "code_revision": "$CODE_REVISION_2",
        "files": [{"path":"plugins/acme/acme.php","sha256":"$CODE_FILE_SHA_2"}],
        "format": "duo-code/v1", "layout": "wp-content", "owned_roots": ["plugins/acme"],
        "plugin_main_files": [{"basename":"acme/acme.php","path":"plugins/acme/acme.php","sha256":"$CODE_FILE_SHA_2"}],
        "source": "code/wp-content", "theme_slugs": [], "theme_templates": []
      },
      "effects_inventory": [
        {"effect":{"adapter":{"id":"fixture-file","inverse":"restore-bytes","inverse_inputs":["path","prior_sha256"],"verifier":"fresh-readback","verifier_inputs":["path","prior_sha256"],"version":"1.0.0"},"id":"lifecycle-file","kind":"filesystem","mode":"reversible","selector":{"scope":"external","type":"path","value":"wp-content/uploads/duo-rollback-effect.txt"}},"manifest":"rollback-fixture","phase":"lifecycle","source":"lifecycle_effects"},
        {"effect":{"id":"rebuild-table","kind":"database","mode":"restorable","selector":{"scope":"database_checkpoint","type":"table","value":"duo_cert_state"}},"manifest":"rollback-fixture","phase":"rebuild","source":"provider:rollback-fixture/rebuild_table"},
        {"effect":{"id":"prevent-http","kind":"http","mode":"prevented","prevention":"receipt_outbox","selector":{"scope":"external","type":"url_prefix","value":"https://rollback.invalid/hooks/"}},"manifest":"rollback-fixture","phase":"lifecycle","source":"lifecycle_effects"}
      ],
      "resolved_adapters": [{"name":"rollback-fixture","version":"1.0.0"}],
      "uploads_inventory": [{"attachment_uuid":"11111111-1111-4111-8111-111111111111","derivative_basename_prefix":"photo-","derivative_directory":"2026/08","media_blob":"$MEDIA_BLOB","original_path":"2026/08/photo.jpg","original_sha256":"$MEDIA_SHA"}]
    }
  },
  "fixture": {
    "code_pointer": "/home/duo/provider-state/code-pointer",
    "code_state": "/home/duo/provider-state/code",
    "control_root": "/home/duo/site/.duo/control",
    "db_config": "/home/duo/provider-state/db.json",
    "db_probe": "/home/duo/providers/ssh-rollback-db-loss-probe.php",
    "effect_target": "/home/duo/site/wp-content/uploads/duo-rollback-effect.txt",
    "media_blob": "$MEDIA_BLOB", "media_sha256": "$MEDIA_SHA",
    "release_root": "/home/duo/releases", "uploads": "/home/duo/uploads"
  }
}
EOF

"$DUO" --envs-file="$TMP/envs.json" adopt source >/dev/null
"$DUO" --envs-file="$TMP/envs.json" adopt target >/dev/null
pass "both hosts adopted; target recovery providers passed product preflight"

say "execute closed crash matrix and converge rollback + commit generations"
SOURCE_HOST_RAW="$(ssh_source hostname)"
TARGET_HOST_RAW="$(ssh_target hostname)"
require_observed_nonempty "source SSH hostname" "$SOURCE_HOST_RAW"
require_observed_nonempty "target SSH hostname" "$TARGET_HOST_RAW"
SOURCE_HOST_HASH="$(printf '%s' "$SOURCE_HOST_RAW" | shasum -a 256 | awk '{print $1}')"
TARGET_HOST_HASH="$(printf '%s' "$TARGET_HOST_RAW" | shasum -a 256 | awk '{print $1}')"
SOURCE_DB_RAW="$(ssh_source "cd /var/www/html && wp db query 'SELECT @@hostname,DATABASE()' --skip-column-names")"
TARGET_DB_RAW="$(ssh_target "cd /var/www/html && wp db query 'SELECT @@hostname,DATABASE()' --skip-column-names")"
require_observed_nonempty "source database identity" "$SOURCE_DB_RAW"
require_observed_nonempty "target database identity" "$TARGET_DB_RAW"
SOURCE_DB_HASH="$(printf '%s' "$SOURCE_DB_RAW" | shasum -a 256 | awk '{print $1}')"
TARGET_DB_HASH="$(printf '%s' "$TARGET_DB_RAW" | shasum -a 256 | awk '{print $1}')"
[ "$SOURCE_HOST_HASH" != "$TARGET_HOST_HASH" ] || fail "SSH host fingerprints are not independent"
[ "$SOURCE_DB_HASH" != "$TARGET_DB_HASH" ] || fail "database fingerprints are not independent"
HARNESS_REVISION="$(shasum -a 256 sandbox/tests/certify/certify_ssh_rollback.sh sandbox/tests/fixtures/ssh-rollback-certify-driver.php sandbox/bin/ssh-rollback-certification.php recovery/*.php cli/src/Recovery/RollbackAuthority.php cli/src/Recovery/VerifiedRollbackProfile.php | shasum -a 256 | awk '{print $1}')"
php sandbox/tests/fixtures/ssh-rollback-certify-driver.php "$TMP/envs.json" "$TMP/spec.raw.json" \
  "$SOURCE_HOST_HASH" "$SOURCE_DB_HASH" "$TARGET_HOST_HASH" "$TARGET_DB_HASH" "$HARNESS_REVISION"
pass "198 injected cases produced signed-chain evidence and only verified rollback/commit outcomes"

say "prove target cleanup, destroy owned fixture, then sign the canonical bundle"
STATUS="$(ssh_target 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php status --root=/home/duo/site/.duo/control')"
require_observed_nonempty "target rollback status" "$STATUS"
jq -e '.terminal == true and .state == "committed" and .exclusion_state == "released"' <<<"$STATUS" >/dev/null \
  || fail "target did not finish committed with exclusion released"
TARGET_PLAINTEXT_COUNT="$(ssh_target "find /home/duo/site/.duo /home/duo/provider-state -type f \\( -name '*.sql' -o -name '*.dump' -o -name '*.plain' \\) -print | wc -l | tr -d ' '")"
require_observed_nonempty "target plaintext checkpoint count" "$TARGET_PLAINTEXT_COUNT"
[ "$TARGET_PLAINTEXT_COUNT" = 0 ] \
  || fail "plaintext checkpoint material remains"
TARGET_EXCLUSION_STATE="$(ssh_target "php -r '\$s=json_decode(file_get_contents(\"/home/duo/provider-state/exclusion.json\"),true);echo \$s[\"state\"];'")"
require_observed_nonempty "target maintenance exclusion state" "$TARGET_EXCLUSION_STATE"
[ "$TARGET_EXCLUSION_STATE" = released ] \
  || fail "maintenance exclusion remains held"

cleanup_fixture
for name in "$SOURCE" "$TARGET" "$SOURCE_DB" "$TARGET_DB"; do
  docker container inspect "$name" >/dev/null 2>&1 && fail "owned container $name remains"
done
docker network inspect "$NET" >/dev/null 2>&1 && fail "owned network $NET remains"
for volume in "$SOURCE_VOLUME" "$TARGET_VOLUME"; do docker volume inspect "$volume" >/dev/null 2>&1 && fail "owned volume $volume remains"; done
docker image inspect "$IMAGE" >/dev/null 2>&1 && fail "owned image $IMAGE remains"

jq '.cleanup={active_receipt_absent:true,maintenance_lock_absent:true,owned_ssh_fixture_absent:true,plaintext_checkpoint_absent:true}' "$TMP/spec.raw.json" >"$TMP/spec.json"
php sandbox/bin/ssh-rollback-certification.php build "$TMP/spec.json" "$TMP/signing.key" "$TMP/bundle.json" >"$TMP/build.json"
php sandbox/bin/ssh-rollback-certification.php verify "$TMP/bundle.json" "$TMP/public.key" >"$TMP/verify.json"
jq -e '.verdict == "valid" and .case_count == 198' "$TMP/verify.json" >/dev/null || fail "signed bundle verification failed"
BUNDLE_SHA="$(jq -r .bundle_sha256 "$TMP/verify.json")"
cp "$TMP/bundle.json" "$EVIDENCE_ROOT/$BUNDLE_SHA.json"
cp "$TMP/public.key" "$EVIDENCE_ROOT/$BUNDLE_SHA.pub"
pass "canonical signed bundle valid: $BUNDLE_SHA"

printf '\n\033[1;32m✔ CERTIFY_SSH_ROLLBACK PASSED\033[0m\n'
printf 'bundle: %s\n' "$EVIDENCE_ROOT/$BUNDLE_SHA.json"
printf 'public key: %s\n' "$EVIDENCE_ROOT/$BUNDLE_SHA.pub"
