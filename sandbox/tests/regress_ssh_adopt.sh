#!/usr/bin/env bash
# DUO-3281: prove the product adoption path against a standalone WordPress
# host reached only over SSH. This deliberately uses docker run, not compose,
# pair.sh, shared volumes with the source checkout, or docker exec for any
# product operation. Docker is only the disposable host boundary; every
# install/verification action after boot travels through cli/duo's SSH path.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PREFIX="${ADOPT_FIXTURE:-codexmac3281}"
PORT="${ADOPT_SSH_PORT:-8998}"
NET="${PREFIX}-net"
DB="${PREFIX}-db"
TARGET="${PREFIX}-target"
VOLUME="${PREFIX}-wordpress"
IMAGE="${PREFIX}-ssh-image"
TMP="$(mktemp -d)"
DUO="$ROOT/cli/duo"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

cleanup() {
  docker rm -f "$TARGET" "$DB" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  docker volume rm "$VOLUME" >/dev/null 2>&1 || true
  docker image rm "$IMAGE" >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT
cleanup
mkdir -p "$TMP"

ssh_fixture() { ssh -F "$TMP/ssh_config" duo-adopt-fixture "$@"; }

say "build a standalone SSH WordPress host image"
docker build -q -t "$IMAGE" -f sandbox/tests/fixtures/ssh-adopt.Dockerfile . >/dev/null
docker network create "$NET" >/dev/null
docker volume create "$VOLUME" >/dev/null
ssh-keygen -q -t ed25519 -N '' -f "$TMP/id_ed25519"
pass "standalone image, network, volume, and one-run SSH credential created"

say "start an independent database and initialize WordPress core"
docker run -d --name "$DB" --network "$NET" \
  -e MARIADB_ROOT_PASSWORD=root-pass \
  -e MARIADB_DATABASE=wordpress \
  -e MARIADB_USER=wordpress \
  -e MARIADB_PASSWORD=wordpress-pass \
  mariadb:11.8 >/dev/null
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
  -p "127.0.0.1:${PORT}:22" \
  -e DUO_MANIFESTS_DIR=/container-only-value \
  -v "$VOLUME:/var/www/html" \
  -v "$TMP/id_ed25519.pub:/tmp/authorized_key:ro" \
  --entrypoint sh "$IMAGE" -lc \
  'cp /tmp/authorized_key /home/duo/.ssh/authorized_keys; chown duo:duo /home/duo/.ssh/authorized_keys; chmod 0600 /home/duo/.ssh/authorized_keys; exec /usr/sbin/sshd -D -e' \
  >/dev/null
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
if ssh_fixture "cd /var/www/html && wp eval 'echo class_exists(\"\\Duo\\Capture\") ? \"present\" : \"absent\";'" | grep -qx present; then
  fail "fixture unexpectedly started with Duo installed"
fi
pass "pre-existing WordPress target starts without Duo"

cat >"$TMP/envs.json" <<EOF
{
  "envs": {
    "target": {
      "transport": "ssh",
      "host": "duo-adopt-fixture",
      "ssh_config": "$TMP/ssh_config",
      "wp_path": "/var/www/html",
      "repo_path": "/home/duo/site"
    }
  }
}
EOF

say "adopt the pre-existing target through the product command"
if OUT="$("$DUO" --envs-file="$TMP/envs.json" adopt target 2>&1)"; then CODE=0; else CODE=$?; fi
echo "$OUT"
[ "$CODE" -eq 0 ] || fail "first duo adopt failed with exit $CODE"
grep -q 'adopt: installed agent 0.5.0 + manifest library; created seed site.duo.json' <<<"$OUT" \
  || fail "first adopt did not report the installed version and seed creation"
grep -q '\[PASS\] duo agent present' <<<"$OUT" || fail "doctor did not pass agent presence"
grep -q '\[PASS\] repo path has site.duo.json (/home/duo/site)' <<<"$OUT" \
  || fail "doctor did not pass the seeded repo"
ssh_fixture "test -f /var/www/html/wp-content/mu-plugins/duo/duo.php && test -f /var/www/html/wp-content/mu-plugins/duo-loader.php && test -f /var/www/html/wp-content/mu-plugins/manifests/core.json"
[ "$(ssh_fixture "cd /var/www/html && wp eval 'echo \\Duo\\Policy::manifests_dir();'")" = "/var/www/html/wp-content/mu-plugins/manifests" ] \
  || fail "fresh process did not select the installed sibling manifest library"
ssh_fixture 'test ! -e /duo-manifests' || fail "adopt unexpectedly required the root-owned fallback"
pass "agent, loader, manifest library, seed repo, and doctor all verify through SSH"

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
pass "rerun updates stale code/manifests while retaining the operator's site policy"

say "roll back the installed release when fresh policy verification fails"
ssh_fixture 'cp /home/duo/site/site.duo.json /home/duo/site/site.duo.valid.json'
ssh_fixture "sed -i \"s/DUO_AGENT_VERSION', '0.5.0/DUO_AGENT_VERSION', '0.0.0/\" /var/www/html/wp-content/mu-plugins/duo/duo.php"
ssh_fixture "printf '%s\n' '{invalid-json' > /home/duo/site/site.duo.json"
BEFORE_AGENT="$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/duo/duo.php')"
BEFORE_MANIFEST="$(ssh_fixture 'cksum /var/www/html/wp-content/mu-plugins/manifests/core.json')"
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

printf '\n\033[1;32m✔ REGRESS_SSH_ADOPT PASSED\033[0m\n'
