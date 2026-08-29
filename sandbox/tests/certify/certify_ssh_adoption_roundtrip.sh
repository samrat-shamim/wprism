#!/usr/bin/env bash
# issue #3257: prove the remaining adoption boundary with two independent hosts.
# Docker supplies disposable machines only. Once the hosts are reachable,
# every WordPress/WPrism operation and every site-repo transfer crosses SSH;
# neither host shares the source checkout or a state volume with the other.
set -euo pipefail

# macOS libarchive otherwise serializes com.apple.* metadata as AppleDouble
# `._*` files. Those are not site entities and the compiler correctly refuses
# them; a release archive must carry repository bytes, not Finder metadata.
export COPYFILE_DISABLE=1

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PREFIX="${ADOPTION_ROUNDTRIP_FIXTURE:-codexmac3257}"
SOURCE_PORT="${ADOPTION_SOURCE_SSH_PORT:-8996}"
TARGET_PORT="${ADOPTION_TARGET_SSH_PORT:-8997}"
NET="${PREFIX}-net"
DB="${PREFIX}-db"
SOURCE="${PREFIX}-source"
TARGET="${PREFIX}-target"
SOURCE_VOLUME="${PREFIX}-source-wordpress"
TARGET_VOLUME="${PREFIX}-target-wordpress"
IMAGE="${PREFIX}-ssh-image"
TMP="$(mktemp -d)"
WPRISM="$ROOT/cli/wprism"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"

cleanup() {
  docker rm -f "$SOURCE" "$TARGET" "$DB" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  docker volume rm "$SOURCE_VOLUME" "$TARGET_VOLUME" >/dev/null 2>&1 || true
  docker image rm "$IMAGE" >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT
cleanup
mkdir -p "$TMP"

ssh_source() { ssh -F "$TMP/ssh_config" wprism-adoption-source "$@"; }
ssh_target() { ssh -F "$TMP/ssh_config" wprism-adoption-target "$@"; }

say "build two standalone SSH WordPress hosts"
docker build -q -t "$IMAGE" -f sandbox/tests/fixtures/ssh-adopt.Dockerfile . >/dev/null
docker network create "$NET" >/dev/null
docker volume create "$SOURCE_VOLUME" >/dev/null
docker volume create "$TARGET_VOLUME" >/dev/null
ssh-keygen -q -t ed25519 -N '' -f "$TMP/id_ed25519"

docker run -d --name "$DB" --network "$NET" \
  -e MARIADB_ROOT_PASSWORD=root-pass \
  -e MARIADB_DATABASE=sourcewp \
  -e MARIADB_USER=wordpress \
  -e MARIADB_PASSWORD=wordpress-pass \
  mariadb:11.8 >/dev/null
for _ in $(seq 1 60); do
  docker exec "$DB" mariadb-admin ping -h 127.0.0.1 -uroot -proot-pass --silent >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$DB" mariadb-admin ping -h 127.0.0.1 -uroot -proot-pass --silent >/dev/null 2>&1 \
  || fail "database never became ready"
docker exec "$DB" mariadb -uroot -proot-pass -e \
  "CREATE DATABASE targetwp; GRANT ALL ON targetwp.* TO 'wordpress'@'%'; FLUSH PRIVILEGES;"
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
  'cp /tmp/authorized_key /home/wprism/.ssh/authorized_keys; chown wprism:wprism /home/wprism/.ssh/authorized_keys; chmod 0600 /home/wprism/.ssh/authorized_keys; exec /usr/sbin/sshd -D -e' >/dev/null
docker run -d --name "$TARGET" --network "$NET" -p "127.0.0.1:${TARGET_PORT}:22" \
  -v "$TARGET_VOLUME:/var/www/html" -v "$TMP/id_ed25519.pub:/tmp/authorized_key:ro" \
  --entrypoint sh "$IMAGE" -lc \
  'cp /tmp/authorized_key /home/wprism/.ssh/authorized_keys; chown wprism:wprism /home/wprism/.ssh/authorized_keys; chmod 0600 /home/wprism/.ssh/authorized_keys; exec /usr/sbin/sshd -D -e' >/dev/null

for port in "$SOURCE_PORT" "$TARGET_PORT"; do
  for _ in $(seq 1 60); do
    ssh-keyscan -p "$port" 127.0.0.1 >>"$TMP/known_hosts" 2>/dev/null && break
    sleep 1
  done
done
cat >"$TMP/ssh_config" <<EOF
Host wprism-adoption-source
  HostName 127.0.0.1
  Port $SOURCE_PORT
  User wprism
  IdentityFile $TMP/id_ed25519
  UserKnownHostsFile $TMP/known_hosts
  StrictHostKeyChecking yes
  IdentitiesOnly yes
  BatchMode yes
Host wprism-adoption-target
  HostName 127.0.0.1
  Port $TARGET_PORT
  User wprism
  IdentityFile $TMP/id_ed25519
  UserKnownHostsFile $TMP/known_hosts
  StrictHostKeyChecking yes
  IdentitiesOnly yes
  BatchMode yes
EOF
ssh_source 'echo source-ready' | grep -qx source-ready || fail "source SSH transport not ready"
ssh_target 'echo target-ready' | grep -qx target-ready || fail "target SSH transport not ready"
pass "two isolated target filesystems are reachable only through their SSH ports"

say "install distinct pre-WPrism WordPress sites through SSH"
ssh_source "cd /var/www/html && wp config create --dbname=sourcewp --dbuser=wordpress --dbpass=wordpress-pass --dbhost=$DB --skip-check --quiet"
ssh_target "cd /var/www/html && wp config create --dbname=targetwp --dbuser=wordpress --dbpass=wordpress-pass --dbhost=$DB --skip-check --quiet"
ssh_source "cd /var/www/html && wp core install --url=http://source.example.test --title='Aged Source' --admin_user=admin --admin_password=admin-pass --admin_email=source@example.test --skip-email --quiet"
ssh_target "cd /var/www/html && wp core install --url=http://target.example.test --title='Independent Target' --admin_user=admin --admin_password=admin-pass --admin_email=target@example.test --skip-email --quiet"
ssh_source "cd /var/www/html && wp post create --post_type=page --post_status=publish --post_title='Adoption Handbook' --post_name=adoption-handbook --post_date='2021-03-04 05:06:07' --post_content='Grounded source content' --porcelain >/dev/null"
ssh_target "cd /var/www/html && wp post create --post_type=page --post_status=publish --post_title='Old Adoption Handbook' --post_name=adoption-handbook --post_content='Target-local old content' --porcelain >/dev/null"
ssh_source "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); update_post_meta(\$p->ID, \"legacy_banner\", \"source-authored-banner\"); update_post_meta(\$p->ID, \"legacy_runtime_token\", \"source-runtime-token\");'"
ssh_target "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); update_post_meta(\$p->ID, \"legacy_banner\", \"target-old-banner\"); update_post_meta(\$p->ID, \"legacy_runtime_token\", \"target-runtime-token\");'"
pass "source and target begin with deliberately different authored and runtime values"

cat >"$TMP/envs.json" <<EOF
{
  "envs": {
    "source": {
      "transport": "ssh",
      "host": "wprism-adoption-source",
      "ssh_config": "$TMP/ssh_config",
      "wp_path": "/var/www/html",
      "repo_path": "/home/wprism/site"
    },
    "target": {
      "transport": "ssh",
      "host": "wprism-adoption-target",
      "ssh_config": "$TMP/ssh_config",
      "wp_path": "/var/www/html",
      "repo_path": "/home/wprism/site"
    }
  }
}
EOF

say "adopt both sites and export a redacted source review batch"
"$WPRISM" --envs-file="$TMP/envs.json" adopt source >/dev/null
"$WPRISM" --envs-file="$TMP/envs.json" adopt target >/dev/null
"$WPRISM" --envs-file="$TMP/envs.json" coverage source --format=json >"$TMP/coverage.json"
jq -e '.options.total > 0' "$TMP/coverage.json" >/dev/null \
  || fail "coverage did not report the source option inventory"
"$WPRISM" --envs-file="$TMP/envs.json" classify source --export-batch="$TMP/review.json" >/dev/null
jq -e '[.decisions[] | select(.section == "post_meta" and (.key == "legacy_banner" or .key == "legacy_runtime_token"))] | length == 2' \
  "$TMP/review.json" >/dev/null || fail "review artifact did not name both legacy decisions"
grep -q 'source-authored-banner' "$TMP/review.json" && fail "review artifact leaked an authored value"
grep -q 'source-runtime-token' "$TMP/review.json" && fail "review artifact leaked a runtime value"
pass "coverage is measurable and the queue review artifact contains evidence without values"

say "prove a changed queue refuses before policy mutation"
SOURCE_POLICY_BEFORE="$(ssh_source 'cksum /home/wprism/site/site.wprism.json')"
ssh_source "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); update_post_meta(\$p->ID, \"late_unreviewed_write\", \"arrived-after-export\");'"
if OUT="$("$WPRISM" --envs-file="$TMP/envs.json" classify source --apply-batch="$TMP/review.json" 2>&1)"; then CODE=0; else CODE=$?; fi
[ "$CODE" -ne 0 ] || fail "stale classification batch unexpectedly applied"
grep -q 'classification batch is stale' <<<"$OUT" || fail "queue change did not name the stale-batch boundary"
[ "$(ssh_source 'cksum /home/wprism/site/site.wprism.json')" = "$SOURCE_POLICY_BEFORE" ] \
  || fail "stale-batch refusal changed source policy"
ssh_source "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); delete_post_meta(\$p->ID, \"late_unreviewed_write\");'"
rm "$TMP/review.json"
"$WPRISM" --envs-file="$TMP/envs.json" classify source --export-batch="$TMP/review.json" >/dev/null
jq '(.decisions[] | select(.key == "legacy_banner") | .class) = "authored" |
    (.decisions[] | select(.key == "legacy_runtime_token") | .class) = "runtime"' \
  "$TMP/review.json" >"$TMP/review.edited.json"
mv "$TMP/review.edited.json" "$TMP/review.json"
"$WPRISM" --envs-file="$TMP/envs.json" classify source --apply-batch="$TMP/review.json" >/dev/null
"$WPRISM" --envs-file="$TMP/envs.json" pending source | grep -q 'review queue is empty' \
  || fail "reviewed batch did not empty the source queue"
pass "fresh, complete reviewed batch applies once; stale batch is mutation-free"

say "capture source without changing its runtime-owned value"
SOURCE_RUNTIME_BEFORE="$(ssh_source "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); echo hash(\"sha256\", (string) get_post_meta(\$p->ID, \"legacy_runtime_token\", true));'")"
"$WPRISM" --envs-file="$TMP/envs.json" capture source >/dev/null
SOURCE_RUNTIME_AFTER="$(ssh_source "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); echo hash(\"sha256\", (string) get_post_meta(\$p->ID, \"legacy_runtime_token\", true));'")"
[ "$SOURCE_RUNTIME_AFTER" = "$SOURCE_RUNTIME_BEFORE" ] || fail "source capture changed runtime-owned state"
scp -F "$TMP/ssh_config" -r wprism-adoption-source:/home/wprism/site "$TMP/source-site" >/dev/null
cp -R "$TMP/source-site/state" "$TMP/source-state"
pass "source canonical state captured and source runtime checksum preserved"

say "transfer the canonical repo over SSH and apply it to the independent target"
tar --no-xattrs -C "$TMP/source-site" -czf "$TMP/site-transfer.tgz" site.wprism.json state
scp -F "$TMP/ssh_config" "$TMP/site-transfer.tgz" wprism-adoption-target:/tmp/site-transfer.tgz >/dev/null
ssh_target 'cd /home/wprism/site && rm -rf state && tar -xzf /tmp/site-transfer.tgz && rm /tmp/site-transfer.tgz'
TARGET_RUNTIME_BEFORE="$(ssh_target "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); echo hash(\"sha256\", (string) get_post_meta(\$p->ID, \"legacy_runtime_token\", true));'")"
require_observed_nonempty "target runtime checksum before apply" "$TARGET_RUNTIME_BEFORE"
"$WPRISM" --envs-file="$TMP/envs.json" plan target --adopt-by-slug=posts,terms,menus --default-author=admin >/dev/null
"$WPRISM" --envs-file="$TMP/envs.json" apply target --adopt-by-slug=posts,terms,menus --default-author=admin >/dev/null
TARGET_RUNTIME_AFTER="$(ssh_target "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); echo hash(\"sha256\", (string) get_post_meta(\$p->ID, \"legacy_runtime_token\", true));'")"
require_observed_nonempty "target runtime checksum after apply" "$TARGET_RUNTIME_AFTER"
[ "$TARGET_RUNTIME_AFTER" = "$TARGET_RUNTIME_BEFORE" ] || fail "target apply changed runtime-owned state"
TARGET_BANNER="$(ssh_target "cd /var/www/html && wp eval '\$p=get_page_by_path(\"adoption-handbook\"); echo get_post_meta(\$p->ID, \"legacy_banner\", true);'")"
require_observed_nonempty "target authored banner after apply" "$TARGET_BANNER"
[ "$TARGET_BANNER" = "source-authored-banner" ] \
  || fail "target did not receive the source authored value"
pass "cross-environment apply converged authored state and preserved target runtime state"

say "recapture target and prove byte identity plus a clean plan"
"$WPRISM" --envs-file="$TMP/envs.json" capture target >/dev/null
scp -F "$TMP/ssh_config" -r wprism-adoption-target:/home/wprism/site/state "$TMP/target-state" >/dev/null
diff -ru "$TMP/source-state" "$TMP/target-state" >/dev/null \
  || fail "source and target canonical state trees are not byte-identical"
"$WPRISM" --envs-file="$TMP/envs.json" plan target --adopt-by-slug=posts,terms,menus --default-author=admin \
  | grep -q 'UNCHANGED' || fail "final target plan was not clean"
pass "target recapture is byte-identical to source and the final plan is clean"

printf '\n\033[1;32m✔ CERTIFY_SSH_ADOPTION_ROUNDTRIP PASSED\033[0m\n'
