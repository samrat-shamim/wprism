#!/usr/bin/env bash
# Live public-path regression: automatic verified rollback on a `local` target.
#
# The local mirror of regress_ssh_adopt.sh's rollback-authority evidence, on
# the estate shape DUO-3365 established for the local transport: one disposable
# pair supplies an installed WordPress database and webroot volume, its own
# containers are stopped, and a controller container mounts that webroot. The
# host CLI and the target then share one filesystem, which is what makes the
# `local` transport real rather than simulated.
#
# What it proves, through the product commands and nothing else:
#
#   1. `duo adopt` on a `local` environment whose machine-local .duo-envs.json
#      entry carries rollback_key_id/rollback_signing_key/rollback_recovery
#      provisions the public key and recovery-config, not just the runtime
#      files. Before RecoveryTransport that gate was `instanceof SshTransport`,
#      so a local adopt installed a runtime it could never authorize against.
#   2. `duo promote` prints `promote profile: automatic verified rollback` and
#      its phase sequence, instead of the WARN + operator-directed checkpoint
#      path.
#   3. A failure injected at `lifecycle-activate` reaches
#      `duo: promote: lifecycle-activate failed; entering signed verified
#      rollback` and converges to `prior world verified; rollback generation
#      <n> is rolled_back and exclusion is released`.
#   4. `duo recover <env> --list` reads the signed catalog rather than refusing
#      with `recovery_authority_unavailable`.
#
# What it does NOT prove, deliberately: this is evidence, not a certificate.
# docs/ssh-rollback-certification.md records a 198-case crash matrix on a
# four-container two-sshd estate whose crash classes (SSH loss, remote-command
# kill) have no local analogue. A local certificate is a separate document with
# its own matrix, and nothing here may be described as certified.
#
# The providers are the file-backed fixtures the offline recovery suites drive
# (sandbox/tests/fixtures/), not the MariaDB-backed
# ssh-rollback-checkpoint-provider.php, which is specific to the certification
# estate's own `duo_cert_state` schema. The subject here is the transport and
# the product path, not the provider.
#
# Minimal reasonably-safe scope: one disposable pair, zero sweeps, no manifest
# matrix.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${LOCAL_VERIFIED_PAIR:-codexlocalverified}"
PORT1="${LOCAL_VERIFIED_PORT1:-9184}"
PORT2="${LOCAL_VERIFIED_PORT2:-9185}"
EXPECTED_SHA="${DUO_EXPECTED_SOURCE_SHA:-}"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

for command in git docker php jq sha256sum; do
  command -v "$command" >/dev/null || fail "$command is required"
done
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "LOCAL_VERIFIED_PAIR must contain lowercase letters/digits and start with a letter"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] || fail "local-verified ports must be decimal integers"
PORT1=$((10#$PORT1)); PORT2=$((10#$PORT2))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail "LOCAL_VERIFIED_PORT1 must be an even port >=8900 and PORT2 its successor"
[[ "$EXPECTED_SHA" =~ ^[0-9a-fA-F]{40}$ ]] || fail "DUO_EXPECTED_SOURCE_SHA must be the exact candidate SHA"

ACTUAL_SHA="$(git rev-parse --verify 'HEAD^{commit}')"
[ "$EXPECTED_SHA" = "$ACTUAL_SHA" ] || fail "DUO_EXPECTED_SOURCE_SHA does not equal this checkout HEAD"
[ -d "$REPO_ROOT/.git" ] || fail "live verified-rollback evidence must run from a standalone clone"
[ -z "$(git status --porcelain --untracked-files=all)" ] || fail "live verified-rollback evidence requires a clean checkout"
export DUO_EXPECTED_SOURCE_SHA="$ACTUAL_SHA"

HOST_REPO1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
HOST_REPO2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
HOST_ORIGIN="$REPO_ROOT/sandbox/siterepo/origin-${PAIR}.git"
WP_VOLUME="duo-${PAIR}_wp1"
REPO_VOLUME="duo-${PAIR}-verified-repo"
IMAGE="duo-local-verified-cli:${PAIR}"
SCRATCH_ROOT=""
HERMETIC_ROOT=""
ENVS_FILE=""
SIGNING_KEY=""
EVIDENCE_LOG=""
PAIR_OWNED=0
REPO_VOLUME_OWNED=0
IMAGE_OWNED=0
GREEN=0

if [ -e "$HOST_REPO1" ] || [ -e "$HOST_REPO2" ] || [ -e "$HOST_ORIGIN" ]; then
  fail "pair repository roots already exist; choose an unused LOCAL_VERIFIED_PAIR"
fi
if [ -n "$(docker ps -a --filter "label=com.docker.compose.project=duo-${PAIR}" --format '{{.ID}}')" ]; then
  fail "compose project duo-${PAIR} already has containers; choose an unused pair"
fi
if docker volume inspect "$REPO_VOLUME" >/dev/null 2>&1; then
  fail "evidence volume $REPO_VOLUME already exists; inspect it or choose an unused pair"
fi
if docker image inspect "$IMAGE" >/dev/null 2>&1; then
  fail "evidence image $IMAGE already exists; inspect it or choose an unused pair"
fi

SCRATCH_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/${PAIR}-local-verified.XXXXXX")"
HERMETIC_ROOT="$SCRATCH_ROOT/hermetic"
ENVS_FILE="$SCRATCH_ROOT/envs.json"
# The controller runs as uid 33 with HOME=/ inside wordpress:cli-php8.3, and
# `duo init` sources the pair's active theme through the host code-artifact
# cache (cli/src/Code/WpOrgReleases.php:117-127 resolves $XDG_CACHE_HOME, else
# ~/.cache -- "/.cache" here, which uid 33 cannot create; measured on the first
# run of this suite, 2026-08-24: "could not create the code artifact cache at
# /.cache/duo/code-artifacts"). A real operator's host has a cache; give the
# controller one on scratch, world-writable because the bind mount is owned by
# the host user and the container writes as uid 33.
CACHE_DIR="$SCRATCH_ROOT/cache"
mkdir -p "$CACHE_DIR" && chmod 0777 "$CACHE_DIR"
# Create the file BEFORE any container bind-mounts it: every helper below
# mounts $ENVS_FILE at /controller/envs.json, and a bind mount whose host path
# does not exist yet is created by Docker as a DIRECTORY, after which the later
# write fails with "Is a directory" (measured on the first run of this suite,
# 2026-08-24). The real machine-local registry is written in place later.
: > "$ENVS_FILE"
SIGNING_KEY="$SCRATCH_ROOT/signing.key"
EVIDENCE_LOG="$SCRATCH_ROOT/evidence.log"

cleanup_on_exit() {
  local incoming=$? cleanup_failed=0 remaining=""
  trap - EXIT
  if [ "$PAIR_OWNED" -eq 1 ]; then
    if ! bash "$REPO_ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >>"$EVIDENCE_LOG" 2>&1; then
      printf 'FAIL: pair destroy failed; preserving all evidence and live resources for %s\n' "$PAIR" >&2
      cleanup_failed=1
    elif ! remaining=$(docker ps -a --filter "label=com.docker.compose.project=duo-${PAIR}" --format '{{.ID}}'); then
      printf 'FAIL: could not verify pair teardown; preserving evidence for %s\n' "$PAIR" >&2
      cleanup_failed=1
    elif [ -n "$remaining" ]; then
      printf 'FAIL: pair %s still has containers; preserving all evidence\n' "$PAIR" >&2
      cleanup_failed=1
    else
      rm -rf -- "$HOST_REPO1" "$HOST_REPO2" "$HOST_ORIGIN"
    fi
  fi
  if [ "$cleanup_failed" -eq 0 ] && [ "$GREEN" -eq 1 ] && [ "$incoming" -eq 0 ]; then
    if [ "$REPO_VOLUME_OWNED" -eq 1 ]; then
      docker volume rm "$REPO_VOLUME" >/dev/null || cleanup_failed=1
    fi
    if [ "$IMAGE_OWNED" -eq 1 ]; then
      docker image rm "$IMAGE" >/dev/null || cleanup_failed=1
    fi
    [ "$cleanup_failed" -ne 0 ] || rm -rf -- "$SCRATCH_ROOT"
  else
    printf 'preserved local-verified evidence: %s\n' "$SCRATCH_ROOT" >&2
    [ "$REPO_VOLUME_OWNED" -eq 0 ] || printf 'preserved repository volume: %s\n' "$REPO_VOLUME" >&2
    [ "$IMAGE_OWNED" -eq 0 ] || printf 'preserved controller image: %s\n' "$IMAGE" >&2
  fi
  if [ "$cleanup_failed" -ne 0 ]; then
    exit 1
  fi
  exit "$incoming"
}
trap cleanup_on_exit EXIT

say "build the hermetic manifest library before Docker mutation"
HERMETIC_MANIFESTS="$(php sandbox/tests/offline/adapter/certification_fixture.php "$HERMETIC_ROOT")" \
  || fail "could not build the hermetic manifest library"
[ "$HERMETIC_MANIFESTS" = "$HERMETIC_ROOT/manifests" ] \
  || fail "hermetic manifest library landed outside the owned scratch root"
pass "hermetic manifest library is ready"

say "mint the controller signing key"
# The Ed25519 secret is the controller's. On a `local` target the controller IS
# the target, which is exactly the reduced tamper-evidence property
# docs/recovery-runtime.md documents; the key still never enters the repository
# or the target's control root, only the controller's own scratch.
php -r '
  $pair = sodium_crypto_sign_keypair();
  file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
' "$SIGNING_KEY" || fail "could not mint the controller signing key"
chmod 0600 "$SIGNING_KEY"
pass "controller signing key minted outside the repository"

say "build a Git-capable controller and create one headless disposable pair"
docker build -q -f sandbox/init-cli.Dockerfile -t "$IMAGE" sandbox >/dev/null
IMAGE_OWNED=1
PAIR_OWNED=1
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless >>"$EVIDENCE_LOG" 2>&1

pair_compose() {
  (cd "$REPO_ROOT/sandbox" && \
    DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" \
    docker compose -p "duo-${PAIR}" -f pair.yml "$@")
}
pair_compose stop wp1 wp2 cli1 cli2 >>"$EVIDENCE_LOG" 2>&1
docker volume inspect "$WP_VOLUME" >/dev/null 2>&1 || fail "pair webroot volume $WP_VOLUME is missing"
docker volume create --label "duo.live-regression=local-verified-rollback" "$REPO_VOLUME" >/dev/null
REPO_VOLUME_OWNED=1

# The pair's bind destinations exist underneath its named volume. With every
# pair container stopped, remove only those test-owned mountpoint bytes so the
# controller sees the required pre-Duo WordPress target.
docker run --rm --user 0 \
  -v "$WP_VOLUME:/var/www/html" -v "$REPO_VOLUME:/siterepo" \
  --entrypoint sh "$IMAGE" -eu -c '
    mkdir -p /siterepo
    chown 33:33 /siterepo
    mkdir -p /var/www/html/wp-content/mu-plugins
    chown 33:33 /var/www/html/wp-content/mu-plugins
    rm -rf /var/www/html/wp-content/mu-plugins/duo \
      /var/www/html/wp-content/mu-plugins/duo-loader.php \
      /var/www/html/wp-content/mu-plugins/manifests
  '

DOCKER_COMMON=(
  --rm --network duo-shared --user 33:33 --workdir /duo-source
  -e WORDPRESS_DB_HOST=duo-shared-db
  -e WORDPRESS_DB_USER=wordpress
  -e WORDPRESS_DB_PASSWORD=wordpress
  -e "WORDPRESS_DB_NAME=wp_${PAIR}1"
  -e 'WORDPRESS_CONFIG_EXTRA=define("WP_ENVIRONMENT_TYPE", "local");'
  -v "$WP_VOLUME:/var/www/html"
  -v "$REPO_VOLUME:/siterepo"
  -v "$REPO_ROOT:/duo-source:ro"
  -v "$HERMETIC_MANIFESTS:/duo-source/manifests:ro"
  -v "$ENVS_FILE:/controller/envs.json:ro"
  -v "$SIGNING_KEY:/controller/signing.key:ro"
  -e XDG_CACHE_HOME=/controller-cache
  -v "$CACHE_DIR:/controller-cache"
)

controller() {
  docker run "${DOCKER_COMMON[@]}" --entrypoint php "$IMAGE" \
    /duo-source/cli/duo --envs-file=/controller/envs.json "$@"
}
target_wp() {
  docker run "${DOCKER_COMMON[@]}" --entrypoint wp "$IMAGE" --path=/var/www/html "$@"
}
target_sh() {
  docker run "${DOCKER_COMMON[@]}" --entrypoint sh "$IMAGE" -eu -c "$1"
}
run_controller() {
  local label="$1"; shift
  set +e
  OUT="$(controller "$@" 2>&1)"
  CODE=$?
  set -e
  {
    printf '\n[%s] exit=%s\n' "$label" "$CODE"
    printf '%s\n' "$OUT"
  } >>"$EVIDENCE_LOG"
  printf '%s\n' "$OUT"
}

say "stage the target-owned recovery providers and their state"
# Providers are TARGET-owned by contract: absolute argv, invoked target-side,
# never a shell command. On a local target that is the same filesystem, so they
# are staged into the repository volume rather than scp'd.
target_sh '
  set -eu
  mkdir -p /siterepo/providers /siterepo/provider-state /siterepo/releases /siterepo/uploads/2026/08 /siterepo/offload /siterepo/media
  for file in offline-checkpoint-provider.php code-release-provider.php upload-provider.php \
              effect-provider.php recovery-adapter.php recovery-exclusion-provider.php; do
    cp "/duo-source/sandbox/tests/fixtures/$file" "/siterepo/providers/$file"
    chmod 0700 "/siterepo/providers/$file"
  done
  printf "CREATE TABLE prior_state (id INT);\n" > /siterepo/provider-state/database-export.sql
  php -r "file_put_contents(\"/siterepo/provider-state/kms.key\", base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)) . \"\n\");"
  php -r "file_put_contents(\"/siterepo/provider-state/upload.key\", random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));"
  chmod 0600 /siterepo/provider-state/kms.key /siterepo/provider-state/upload.key
  mkdir -p /siterepo/releases/release-prior/wp-content/plugins/acme
  mkdir -p /siterepo/releases/release-desired-1/wp-content/plugins/acme
  mkdir -p /siterepo/releases/release-desired-2/wp-content/plugins/acme
  printf "prior release payload\n" > /siterepo/releases/release-prior/wp-content/plugins/acme/acme.php
  printf "desired release payload 1\n" > /siterepo/releases/release-desired-1/wp-content/plugins/acme/acme.php
  printf "desired release payload 2\n" > /siterepo/releases/release-desired-2/wp-content/plugins/acme/acme.php
  printf "release-prior\n" > /siterepo/provider-state/code-pointer
  printf "prior-upload" > /siterepo/uploads/2026/08/photo.jpg
' || fail "could not stage the target-owned recovery providers"
pass "target-owned providers and their state are staged"

say "write the machine-local environment that arms the rollback authority"
php -r '
$body = ["envs" => [
    "local" => [
        "transport" => "local",
        "wp_path" => "/var/www/html",
        "repo_path" => "/siterepo/site",
        "bootstrap" => ["format" => "duo-local-control-plane/v1"],
        "rollback_key_id" => "local-verified-live",
        "rollback_signing_key" => "/controller/signing.key",
        "verified_rollback" => [
            "claim_ttl_seconds" => 300,
            "encryption_key_id" => "local-kms-fixture",
            "retention_seconds" => 86400,
        ],
        "rollback_recovery" => [
            "adapters" => [
                "code_restore" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
                "database_restore" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
                "prior_verify" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
                "storage_restore" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
            ],
            "checkpoint_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/offline-checkpoint-provider.php",
                "/siterepo/provider-state/checkpoint", "/siterepo/provider-state/database-export.sql",
                "/siterepo/provider-state/kms.key",
            ],
            "code_release_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/code-release-provider.php",
                "/siterepo/provider-state/code", "/siterepo/releases", "/siterepo/provider-state/code-pointer",
            ],
            "effect_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/effect-provider.php",
                "/siterepo/provider-state/effects", "/siterepo/site",
            ],
            "exclusion_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/recovery-exclusion-provider.php",
                "/siterepo/provider-state/exclusion.json",
            ],
            "timeout_seconds" => 30,
            "upload_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/upload-provider.php",
                "/siterepo/provider-state/uploads", "/siterepo/uploads", "/siterepo/offload",
                "/siterepo/media", "/siterepo/provider-state/upload.key",
            ],
        ],
    ],
    // The same target with the authority NOT configured: its refusals must be
    // exactly what they were before this transport could carry an authority.
    "unarmed" => [
        "transport" => "local",
        "wp_path" => "/var/www/html",
        "repo_path" => "/siterepo/site",
        "bootstrap" => ["format" => "duo-local-control-plane/v1"],
    ],
]];
file_put_contents($argv[1], json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$ENVS_FILE" || fail "could not write the machine-local environment file"
chmod 0644 "$ENVS_FILE"
pass "machine-local environment names its rollback authority"

say "duo envs is unchanged for the environment that did not opt in"
run_controller "envs" envs
[ "$CODE" -eq 0 ] || fail "duo envs failed"
grep -Fq 'rollback_key_id=local-verified-live' <<<"$OUT" \
  || fail "duo envs did not name the configured local rollback authority"
grep -Eq '^unarmed[[:space:]]+local  wp_path=/var/www/html repo_path=/siterepo/site bootstrap=authorized$' <<<"$OUT" \
  || grep -Fq 'local  wp_path=/var/www/html repo_path=/siterepo/site bootstrap=authorized' <<<"$OUT" \
  || fail "the un-armed local environment's describe line moved"
pass "opting in is visible; not opting in changes nothing"

say "adopt provisions the public key and recovery-config, not just the runtime"
run_controller "adopt" adopt local
[ "$CODE" -eq 0 ] || fail "local adopt failed"
grep -Fq '+ rollback authority' <<<"$OUT" || fail "adopt did not report installing the rollback authority"
target_sh '
  test -f /siterepo/site/.duo/control/recovery-runtime/rollback-control.php
  test -f /siterepo/site/.duo/control/recovery-config.json
  test -f /siterepo/site/.duo/control/public-keys/local-verified-live.pub
  test -f /siterepo/site/.duo/control/target.json
  test ! -e /siterepo/site/.duo/control/rollback-signing.key
  test ! -e /siterepo/site/.duo/control/private-keys
' || fail "local adopt did not provision the public-key-only rollback authority"
[ "$(target_sh 'stat -c %a /siterepo/site/.duo/control')" = "700" ] \
  || fail "rollback control root is not protected mode 0700"
pass "the local control plane carries the public key and recovery-config, and no private material"

say "status verifies the authority the same way an SSH target's does"
run_controller "status" status local
grep -Fq '[PASS] rollback authority: ready (no active generation)' <<<"$OUT" \
  || fail "status did not verify and render the local rollback authority"
run_controller "unarmed status" status unarmed
grep -Fq 'rollback authority' <<<"$OUT" \
  && fail "the un-armed local environment printed a rollback authority line it never had before" \
  || pass "status reports the authority for the armed environment and stays silent for the un-armed one"

say "initialize the canonical baseline"
# The probe plugin is on the target BEFORE init and declared first-party, so
# the canonical code half carries it: a plugin that appears on the target after
# init has no source under code/wp-content and capture refuses it with
# code_state_mismatch (measured on this suite's first run, 2026-08-24) -- the
# product working, not a fixture the suite may bypass.
target_sh '
  set -eu
  mkdir -p /var/www/html/wp-content/plugins/duo-promotion-probe
  cp /duo-source/sandbox/tests/fixtures/duo-promotion-probe.php \
     /var/www/html/wp-content/plugins/duo-promotion-probe/duo-promotion-probe.php
'
run_controller "init" init local --yes --first-party=plugins/duo-promotion-probe
[ "$CODE" -eq 0 ] || fail "init failed after local adoption"
grep -Fq 'Initialized canonical state baseline' <<<"$OUT" || fail "init omitted its canonical baseline result"
pass "canonical baseline established"

say "promote selects the automatic verified profile off SSH"
# The probe plugin's activation hook is a real lifecycle boundary: promote must
# reach lifecycle-activate through the signed generation, not through the
# operator-directed checkpoint path. (Its bytes are already in the code half --
# see init above.)
target_wp plugin activate duo-promotion-probe >/dev/null || fail "could not activate the promotion probe"
run_controller "capture" capture local --yes
[ "$CODE" -eq 0 ] || fail "capture failed"
target_wp plugin deactivate duo-promotion-probe >/dev/null || fail "could not deactivate the promotion probe"

run_controller "promote" promote local
[ "$CODE" -eq 0 ] || fail "verified promote failed"
grep -Fq 'promote profile: automatic verified rollback' <<<"$OUT" \
  || fail "promote did not select the automatic verified profile on a local target"
grep -Fq 'promote phase: rollback-claim' <<<"$OUT" || fail "promote did not publish the signed claim"
grep -Fq 'promote phase: promotion-begin' <<<"$OUT" || fail "promote did not enter promotion-begin"
grep -Fq 'promote phase: lifecycle-activate' <<<"$OUT" || fail "promote did not enter lifecycle-activate"
grep -Fq 'promote phase: apply' <<<"$OUT" || fail "promote did not reach apply"
grep -Fq 'WARN automatic verified rollback unavailable' <<<"$OUT" \
  && fail "promote still warned that the verified profile is unavailable" \
  || pass "promote ran the signed verified profile end to end on a local target"
[ "$(target_wp option get duo_promotion_probe_activated 2>/dev/null || true)" = yes ] \
  || fail "the lifecycle activation hook did not run for real"
pass "generation 1 committed through the signed verified profile"

say "recover reads the signed catalog rather than refusing"
run_controller "recover list" recover local --list
[ "$CODE" -eq 0 ] || fail "recover --list failed on the armed local environment"
grep -Fq 'recovery_authority_unavailable' <<<"$OUT" \
  && fail "an armed local environment still refused with recovery_authority_unavailable" \
  || pass "the signed catalog is readable on a local target"
run_controller "unarmed recover list" recover unarmed --list
grep -Fq 'this transport carries no rollback authority runtime' <<<"$OUT" \
  || fail "the un-armed local environment lost its honest no-authority disclosure"
pass "the refusal is about a configured authority, not about SSH"

say "a lifecycle-activate failure converges through the signed rollback"
# The injected failure is a real activation fatal, produced by the probe
# plugin's own hook, not by patching the orchestrator. It is authored where a
# first-party component's bytes live -- the site repository's code half -- and
# committed, so promote's own code-stage materializes the broken hook onto the
# target before lifecycle-activate fires it. Editing the target's copy directly
# would be overwritten by that same stage.
target_sh '
  set -eu
  printf "%s\n" "<?php register_activation_hook(__FILE__, static function (): void { throw new RuntimeException(\"injected lifecycle-activate failure\"); });" \
    > /siterepo/site/code/wp-content/plugins/duo-promotion-probe/duo-promotion-probe.php
  git -C /siterepo/site -c user.name=duo -c user.email=duo@example.test commit -qam "inject lifecycle-activate failure"
'
target_wp plugin deactivate duo-promotion-probe >/dev/null 2>&1 || true
run_controller "failing promote" promote local
[ "$CODE" -ne 0 ] || fail "promote succeeded despite an injected lifecycle-activate failure"
grep -Fq 'duo: promote: lifecycle-activate failed; entering signed verified rollback' <<<"$OUT" \
  || fail "the injected failure did not enter the signed verified rollback"
grep -Eq 'duo: promote: prior world verified; rollback generation [0-9]+ is rolled_back and exclusion is released' <<<"$OUT" \
  || fail "the signed rollback did not converge to a verified prior world with the exclusion released"
grep -Fq 'operator-directed' <<<"$OUT" \
  && fail "a verified promotion fell through to the operator-directed dump path" \
  || pass "a failure after promoting converged through signed operations only"

STATUS_JSON="$(target_sh 'php /siterepo/site/.duo/control/recovery-runtime/rollback-control.php status --root=/siterepo/site/.duo/control')"
jq -e '.state == "rolled_back" and .terminal == true' <<<"$STATUS_JSON" >/dev/null \
  || fail "the target authority did not finish rolled_back and terminal"
EXCLUSION_STATE="$(target_sh 'php -r "\$s=json_decode(file_get_contents(\"/siterepo/provider-state/exclusion.json\"),true);echo \$s[\"state\"];"')"
[ "$EXCLUSION_STATE" = released ] || fail "the maintenance exclusion remains held after a terminal rollback"
pass "the target's own runtime and exclusion provider agree with the controller's report"

GREEN=1
printf '\nREGRESS_LOCAL_VERIFIED_ROLLBACK_LIVE PASSED\n'
