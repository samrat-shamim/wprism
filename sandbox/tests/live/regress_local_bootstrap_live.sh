#!/usr/bin/env bash
# Live public-path regression for issue #3365.
#
# One disposable pair supplies an installed WordPress database and webroot
# volume.  Its ordinary pair containers are then stopped and a controller
# container mounts that webroot WITHOUT the repository's pre-bound WPrism agent.
# The host CLI and target share that container's filesystem, which makes the
# local transport real while preserving the required "WordPress without WPrism"
# starting point.  No plugin-specific fixture or semantics participate.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${LOCAL_BOOTSTRAP_PAIR:-codex3365local}"
PORT1="${LOCAL_BOOTSTRAP_PORT1:-9180}"
PORT2="${LOCAL_BOOTSTRAP_PORT2:-9181}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

for command in git docker php jq sha256sum; do
  command -v "$command" >/dev/null || fail "$command is required"
done
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "LOCAL_BOOTSTRAP_PAIR must contain lowercase letters/digits and start with a letter"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] || fail "local-bootstrap ports must be decimal integers"
PORT1=$((10#$PORT1)); PORT2=$((10#$PORT2))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail "LOCAL_BOOTSTRAP_PORT1 must be an even port >=8900 and PORT2 its successor"
[[ "$EXPECTED_SHA" =~ ^[0-9a-fA-F]{40}$ ]] || fail "WPRISM_EXPECTED_SOURCE_SHA must be the exact candidate SHA"

ACTUAL_SHA="$(git rev-parse --verify 'HEAD^{commit}')"
[ "$EXPECTED_SHA" = "$ACTUAL_SHA" ] || fail "WPRISM_EXPECTED_SOURCE_SHA does not equal this checkout HEAD"
[ -d "$REPO_ROOT/.git" ] || fail "live local-bootstrap evidence must run from a standalone clone"
[ -z "$(git status --porcelain --untracked-files=all)" ] || fail "live local-bootstrap evidence requires a clean checkout"
export WPRISM_EXPECTED_SOURCE_SHA="$ACTUAL_SHA"

HOST_REPO1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
HOST_REPO2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
HOST_ORIGIN="$REPO_ROOT/sandbox/siterepo/origin-${PAIR}.git"
WP_VOLUME="wprism-${PAIR}_wp1"
REPO_VOLUME="wprism-${PAIR}-bootstrap-repo"
IMAGE="wprism-local-bootstrap-cli:${PAIR}"
SCRATCH_ROOT=""
ENVS_FILE=""
EVIDENCE_LOG=""
PAIR_OWNED=0
REPO_VOLUME_OWNED=0
IMAGE_OWNED=0
GREEN=0

if [ -e "$HOST_REPO1" ] || [ -e "$HOST_REPO2" ] || [ -e "$HOST_ORIGIN" ]; then
  fail "pair repository roots already exist; choose an unused LOCAL_BOOTSTRAP_PAIR"
fi
if [ -n "$(docker ps -a --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}')" ]; then
  fail "compose project wprism-${PAIR} already has containers; choose an unused pair"
fi
if docker volume inspect "$REPO_VOLUME" >/dev/null 2>&1; then
  fail "evidence volume $REPO_VOLUME already exists; inspect it or choose an unused pair"
fi
if docker image inspect "$IMAGE" >/dev/null 2>&1; then
  fail "evidence image $IMAGE already exists; inspect it or choose an unused pair"
fi

SCRATCH_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/${PAIR}-local-bootstrap.XXXXXX")"
ENVS_FILE="$SCRATCH_ROOT/envs.json"
EVIDENCE_LOG="$SCRATCH_ROOT/evidence.log"

cleanup_on_exit() {
  local incoming=$? cleanup_failed=0 remaining=""
  trap - EXIT
  if [ "$PAIR_OWNED" -eq 1 ]; then
    if ! bash "$REPO_ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >>"$EVIDENCE_LOG" 2>&1; then
      printf 'FAIL: pair destroy failed; preserving all evidence and live resources for %s\n' "$PAIR" >&2
      cleanup_failed=1
    elif ! remaining=$(docker ps -a --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}'); then
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
    printf 'preserved local-bootstrap evidence: %s\n' "$SCRATCH_ROOT" >&2
    [ "$REPO_VOLUME_OWNED" -eq 0 ] || printf 'preserved repository volume: %s\n' "$REPO_VOLUME" >&2
    [ "$IMAGE_OWNED" -eq 0 ] || printf 'preserved controller image: %s\n' "$IMAGE" >&2
  fi
  if [ "$cleanup_failed" -ne 0 ]; then
    exit 1
  fi
  exit "$incoming"
}
trap cleanup_on_exit EXIT

say "build a Git-capable controller and create one headless disposable pair"
docker build -q -f sandbox/init-cli.Dockerfile -t "$IMAGE" sandbox >/dev/null
IMAGE_OWNED=1
PAIR_OWNED=1
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless >>"$EVIDENCE_LOG" 2>&1

pair_compose() {
  (cd "$REPO_ROOT/sandbox" && \
    WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" \
    docker compose -p "wprism-${PAIR}" -f pair.yml "$@")
}
pair_compose stop wp1 wp2 cli1 cli2 >>"$EVIDENCE_LOG" 2>&1
docker volume inspect "$WP_VOLUME" >/dev/null 2>&1 || fail "pair webroot volume $WP_VOLUME is missing"
docker volume create --label "wprism.live-regression=issue #3365" "$REPO_VOLUME" >/dev/null
REPO_VOLUME_OWNED=1

# The pair's bind destinations exist underneath its named volume.  With every
# pair container stopped, remove only those test-owned mountpoint bytes so the
# custom controller sees the required pre-WPrism WordPress target.
docker run --rm --user 0 \
  -v "$WP_VOLUME:/var/www/html" -v "$REPO_VOLUME:/siterepo" \
  --entrypoint sh "$IMAGE" -eu -c '
    mkdir -p /siterepo
    chown 33:33 /siterepo
    mkdir -p /var/www/html/wp-content/mu-plugins
    chown 33:33 /var/www/html/wp-content/mu-plugins
    rm -rf /var/www/html/wp-content/mu-plugins/wprism \
      /var/www/html/wp-content/mu-plugins/wprism-loader.php \
      /var/www/html/wp-content/mu-plugins/manifests \
      /var/www/html/wp-content/mu-plugins/wprism-control
  '

php -r '
$pair = $argv[1];
$body = ["envs" => [
    "local" => [
        "transport" => "local",
        "wp_path" => "/var/www/html",
        "repo_path" => "/siterepo/site",
        "bootstrap" => ["format" => "wprism-local-control-plane/v1"],
    ],
    "denied" => [
        "transport" => "local",
        "wp_path" => "/var/www/html",
        "repo_path" => "/siterepo/site",
    ],
]];
file_put_contents($argv[2], json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$PAIR" "$ENVS_FILE"
chmod 0644 "$ENVS_FILE"

DOCKER_COMMON=(
  --rm --network wprism-shared --user 33:33 --workdir /wprism-source
  -e WORDPRESS_DB_HOST=wprism-shared-db
  -e WORDPRESS_DB_USER=wordpress
  -e WORDPRESS_DB_PASSWORD=wordpress
  -e "WORDPRESS_DB_NAME=wp_${PAIR}1"
  -e 'WORDPRESS_CONFIG_EXTRA=define("WP_ENVIRONMENT_TYPE", "local");'
  -v "$WP_VOLUME:/var/www/html"
  -v "$REPO_VOLUME:/siterepo"
  -v "$REPO_ROOT:/wprism-source:ro"
  -v "$ENVS_FILE:/controller/envs.json:ro"
)

controller() {
  docker run "${DOCKER_COMMON[@]}" --entrypoint php "$IMAGE" \
    /wprism-source/cli/wprism --envs-file=/controller/envs.json "$@"
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
target_digest() {
  target_sh '
    set -o pipefail
    for root in /var/www/html/wp-content/mu-plugins /siterepo/site; do
      if [ ! -e "$root" ] && [ ! -L "$root" ]; then
        printf "A %s\n" "$root"
        continue
      fi
      find "$root" -print | LC_ALL=C sort | while IFS= read -r path; do
        if [ -L "$path" ]; then
          printf "L %s %s\n" "$path" "$(readlink "$path")"
        elif [ -d "$path" ]; then
          printf "D %s %s\n" "$path" "$(stat -c %a "$path")"
        elif [ -f "$path" ]; then
          printf "F %s %s %s\n" "$path" "$(stat -c %a "$path")" "$(sha256sum "$path" | cut -c1-64)"
        else
          printf "S %s\n" "$path"
        fi
      done
    done | sha256sum | cut -c1-64
  ' | tr -d '\r\n'
}
wprism_table_count() {
  target_wp db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names 2>/dev/null \
    | awk 'NF { n++ } END { print n + 0 }'
}
assert_no_transaction_paths() {
  target_sh '
    test -z "$(find /var/www/html/wp-content/mu-plugins -maxdepth 1 \
      \( -name ".wprism-adopt-*" -o -name ".wprism-new-*" -o -name ".wprism-old-*" \
         -o -name ".wprism-loader-new-*" -o -name ".wprism-loader-old-*" \
         -o -name ".wprism-manifests-new-*" -o -name ".wprism-manifests-old-*" \) -print -quit)"
    test -z "$(find /siterepo -maxdepth 2 \
      \( -name ".wprism-new-*" -o -name ".wprism-old-*" -o -name ".site.wprism.new-*" \) -print -quit)"
  '
}

say "prove the fixture really is installed WordPress with no WPrism control plane"
target_wp core is-installed >/dev/null || fail "fixture WordPress is not installed"
[ "$(target_wp eval 'echo class_exists("\\WPrism\\Capture") ? "present" : "absent";' | tr -d '\r\n')" = absent ] \
  || fail "WPrism is already loaded before local adoption"
target_sh 'test ! -e /var/www/html/wp-content/mu-plugins/wprism; test ! -e /var/www/html/wp-content/mu-plugins/wprism-loader.php; test ! -e /siterepo/site' \
  || fail "pre-adoption control plane or repository is already present"
[ "$(wprism_table_count)" -eq 0 ] || fail "fixture already contains WPrism ledger tables"
pass "public-path target starts with WordPress only"

say "static authorization refusal is target-free and mutation-free"
BEFORE="$(target_digest)"
run_controller "unauthorized adopt" adopt denied
[ "$CODE" -ne 0 ] || fail "local adopt succeeded without the machine-local bootstrap opt-in"
grep -Fq '.wprism-envs.json' <<<"$OUT" || fail "static refusal omitted actionable machine-local remediation"
[ "$BEFORE" = "$(target_digest)" ] || fail "static authorization refusal changed target bytes"
[ "$(wprism_table_count)" -eq 0 ] || fail "static authorization refusal created WPrism ledger tables"
pass "missing local authority refuses before target mutation"

say "incomplete prior authority refuses during read-only eligibility"
target_sh 'mkdir -p /siterepo/site/.wprism/rollback; printf "%s\n" incomplete > /siterepo/site/.wprism/rollback/sentinel'
BEFORE="$(target_digest)"
run_controller "incomplete authority" adopt local
[ "$CODE" -ne 0 ] || fail "adopt accepted an incomplete prior .wprism authority"
grep -Fq 'control_authority' <<<"$OUT" || fail "incomplete authority refusal omitted its named check"
[ "$BEFORE" = "$(target_digest)" ] || fail "eligibility refusal changed the incomplete prior authority"
[ "$(wprism_table_count)" -eq 0 ] || fail "eligibility refusal created WPrism ledger tables"
target_sh 'rm -rf /siterepo/site'
pass "malformed re-adoption state is blocked without writes"

say "post-swap policy failure restores the exact preexisting target"
target_sh 'mkdir /siterepo/site; printf "%s\n" "{invalid-local-bootstrap-policy" > /siterepo/site/site.wprism.json'
BEFORE="$(target_digest)"
run_controller "policy rollback" adopt local
[ "$CODE" -ne 0 ] || fail "adopt accepted an invalid preexisting site policy"
grep -Fq 'policy verification' <<<"$OUT" || fail "post-swap refusal did not identify policy verification"
[ "$BEFORE" = "$(target_digest)" ] || fail "post-swap failure did not restore target bytes and modes"
[ "$(wprism_table_count)" -eq 0 ] || fail "post-swap failure created WPrism ledger tables"
assert_no_transaction_paths || fail "post-swap rollback left transaction paths"
target_sh 'rm -rf /siterepo/site'
pass "failed staged verification leaves no partial control plane, config, identity, or ledger"

say "authorized local adoption installs and transactionally doctors the target"
run_controller "successful adoption" adopt local
[ "$CODE" -eq 0 ] || fail "authorized local adoption failed"
grep -Fq 'doctor (verified before commit)' <<<"$OUT" || fail "adoption did not report its transactional doctor"
target_sh '
  test -f /var/www/html/wp-content/mu-plugins/wprism/wprism.php
  test -f /var/www/html/wp-content/mu-plugins/wprism-loader.php
  test -f /var/www/html/wp-content/mu-plugins/wprism/adapter-library/platform/core/manifest.json
  test ! -e /var/www/html/wp-content/mu-plugins/manifests
  test -f /siterepo/site/site.wprism.json
  test -f /siterepo/site/.wprism/control/target.json
  test -d /siterepo/site/.wprism/rollback
' || fail "successful adoption omitted a control-plane or authority artifact"
assert_no_transaction_paths || fail "successful adoption left transaction paths"
[ "$(wprism_table_count)" -eq 0 ] || fail "control-plane adoption created canonical state/identity/ledger tables"
pass "control-plane bootstrap is complete and remains separate from managed state"

say "cancelled initialization is read-only, then explicit initialization succeeds"
BEFORE="$(target_digest)"
run_controller "cancelled init" init local
[ "$CODE" -ne 0 ] || fail "noninteractive init unexpectedly confirmed itself"
grep -Fq 'Initialization cancelled' <<<"$OUT" || fail "init cancellation did not state its no-mutation boundary"
[ "$BEFORE" = "$(target_digest)" ] || fail "cancelled init changed control-plane or repository bytes"
[ "$(wprism_table_count)" -eq 0 ] || fail "cancelled init created canonical state/identity/ledger tables"

run_controller "confirmed init" init local --yes
[ "$CODE" -eq 0 ] || fail "confirmed init failed after successful local adoption"
grep -Fq 'Initialized canonical state baseline' <<<"$OUT" || fail "confirmed init omitted its canonical baseline result"
target_sh '
  test -d /siterepo/site/.git
  test -d /siterepo/site/state
  test -d /siterepo/site/code/wp-content
  test ! -e /siterepo/site/code/wp-content/mu-plugins/wprism
  test ! -e /siterepo/site/code/wp-content/mu-plugins/wprism-loader.php
  test -f /var/www/html/wp-content/mu-plugins/wprism/wprism.php
' || fail "init did not create its baseline or leaked the control plane into managed code"
[ "$(wprism_table_count)" -gt 0 ] || fail "explicit init did not create its canonical identity/ledger tables"
run_controller "post-init status" status local
[ "$CODE" -eq 0 ] || fail "post-init status is not clean"
pass "public adopt -> init path reaches a clean managed baseline"

GREEN=1
printf 'REGRESS_LOCAL_BOOTSTRAP_LIVE PASSED\n'
