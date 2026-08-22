#!/usr/bin/env bash
# Exact-artifact core platform matrix. A supported PHP 8.3 / MariaDB 11 /
# WordPress 7.0.3 single-site pair must round-trip real core content and pass
# the host doctor. Real WordPress 7.0.2 and PHP 8.4 runtimes must make both the
# direct agent product path and host doctor refuse before repository or
# authored-state mutation. The existing regress_multisite_refusal.sh owns the
# fourth live axis; the readiness ledger cites both suites explicitly.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${CORE_SCOPE_PLATFORM_PAIR:-corescope}"
PORT1="${CORE_SCOPE_PLATFORM_PORT1:-8996}"
PORT2="${CORE_SCOPE_PLATFORM_PORT2:-8997}"
WP83_IMAGE='wordpress@sha256:a09147f15a882b956f67a617e9e1e053adf9322c45c797c2ff7c0e66522bf204'
WP84_IMAGE='wordpress@sha256:322fedc0b666dfdbbb7c940fc934ec9f5ac4d8b1c4c6147838291b6c7eb197db'
CLI83_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
CLI84_IMAGE='wordpress@sha256:13d152baa3c9111882d05e8ef4c32b4c84019b1bf7bf66b042c6b45e7aaba81d'
WORDPRESS_SUPPORTED='7.0.3'
WORDPRESS_REFUSED='7.0.2'

[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CORE_SCOPE_PLATFORM_PAIR '$PAIR'"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] || fail 'platform ports must be decimal integers'
PORT1=$((10#$PORT1)); PORT2=$((10#$PORT2))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail 'platform ports must be an even value >=8900 and its adjacent successor'
command -v jq >/dev/null || fail 'jq is required'
command -v docker >/dev/null || fail 'docker is required'

SOURCE_SHA=$(git rev-parse --verify 'HEAD^{commit}') || fail 'platform evidence has no resolvable Git HEAD'
[ -n "${DUO_EXPECTED_SOURCE_SHA:-}" ] || fail 'DUO_EXPECTED_SOURCE_SHA is required for exact platform evidence'
[ "$DUO_EXPECTED_SOURCE_SHA" = "$SOURCE_SHA" ] \
  || fail "expected source $DUO_EXPECTED_SOURCE_SHA does not equal this checkout HEAD $SOURCE_SHA"
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] \
  || fail "platform evidence checkout is dirty; commit the exact candidate $SOURCE_SHA first"

export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
export DUO_ARTIFACT_OFFLINE=1
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"
ENVS_FILE=$(mktemp "${TMPDIR:-/tmp}/duo-core-platform.${PAIR}.XXXXXX")
COMPOSE=(
  docker compose -p "duo-$PAIR"
  -f pair.yml
  -f pair.artifacts.yml
  -f pair.wordpress-offline.yml
)
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }

duo_json() { # <wp-runner> <label> <duo arguments...>
  local runner="$1" label="$2" output payload
  shift 2
  if ! output=$("$runner" duo "$@" --format=json 2>&1); then
    fail "$label failed: $output"
  fi
  payload=$(awk 'NF { line=$0 } END { print line }' <<<"$output")
  jq -e 'type == "object"' <<<"$payload" >/dev/null \
    || fail "$label did not return a JSON object: $output"
  printf '%s\n' "$payload"
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

tree_fingerprint() {
  find "$1" -type f -print0 \
    | LC_ALL=C sort -z \
    | xargs -0 shasum -a 256 \
    | shasum -a 256 \
    | awk '{print $1}'
}

prepare_repo() {
  cp tests/fixtures/core_lifecycle_site.duo.json "$R1/site.duo.json"
  cp tests/fixtures/core_lifecycle_site.duo.json "$R2/site.duo.json"
  cp site-repo.gitignore.template "$R1/.gitignore"
  cp site-repo.gitignore.template "$R2/.gitignore"
  wp1 site empty --yes >/dev/null
  wp2 site empty --yes >/dev/null
  wp1 option update duo_platform_mutation_canary untouched >/dev/null
  wp2 option update duo_platform_mutation_canary untouched >/dev/null
  write_env_file
}

assert_platform_json_refusal() { # <expected-code> <observed> <required> <label>
  local expected_code="$1" observed="$2" required="$3" label="$4"
  local output payload rc=0
  output=$(wp1 duo capture --repo=/siterepo --format=json 2>&1) || rc=$?
  [ "$rc" -ne 0 ] || fail "$label capture unexpectedly succeeded"
  payload=$(awk 'NF { line=$0 } END { print line }' <<<"$output")
  jq -e --arg code "$expected_code" --arg observed "$observed" --arg required "$required" '
    .format == "duo-command-refusal/v1" and .command == "capture" and
    .reason_code == "platform_unsupported" and .details_redacted != true and
    (.diagnostics | length) == 1 and .diagnostics[0].code == $code and
    .diagnostics[0].observed == $observed and .diagnostics[0].required == $required
  ' <<<"$payload" >/dev/null || fail "$label returned the wrong refusal envelope: $output"
  ! grep -Fq '/Users/' <<<"$payload" || fail "$label exposed a host path"
  pass "$label is a typed non-zero platform refusal"
}

for image in "$WP83_IMAGE" "$WP84_IMAGE" "$CLI83_IMAGE" "$CLI84_IMAGE"; do
  docker image inspect "$image" >/dev/null 2>&1 \
    || fail "exact platform image is absent locally; this evidence run will not float or pull: $image"
done
[ "$(docker run --rm --entrypoint php "$WP83_IMAGE" -r 'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;')" = "$WORDPRESS_SUPPORTED" ] \
  || fail 'PHP 8.3 web image does not carry exact WordPress 7.0.3'
[ "$(docker run --rm --entrypoint php "$WP84_IMAGE" -r 'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;')" = "$WORDPRESS_SUPPORTED" ] \
  || fail 'PHP 8.4 refusal image does not carry exact WordPress 7.0.3'
[[ "$(docker run --rm --entrypoint php "$CLI83_IMAGE" -r 'echo PHP_VERSION;')" == 8.3.* ]] \
  || fail 'supported CLI image is not PHP 8.3'
[[ "$(docker run --rm --entrypoint php "$CLI84_IMAGE" -r 'echo PHP_VERSION;')" == 8.4.* ]] \
  || fail 'refusal CLI image is not PHP 8.4'
jq -e --arg wp "$WORDPRESS_SUPPORTED" '
  .platform.site_mode == "single-site" and
  .platform.compatibility.php == {max:"8.4.0",min:"8.3.0",note:.platform.compatibility.php.note} and
  .platform.compatibility.database.engine == "MariaDB" and
  .platform.compatibility.database.min == "11.0.0" and
  .platform.compatibility.database.max == "12.0.0" and
  .platform.compatibility.wordpress.last_verified == $wp
' ../manifests/capabilities/platform.json >/dev/null \
  || fail 'shipped platform declaration no longer matches this exact matrix'
pass 'all WordPress/PHP artifacts and declared platform values are exact before pair mutation'

say 'supported exact platform: real core round trip and doctor'
destroy_owned_pair
export DUO_WP_IMAGE="$WP83_IMAGE" DUO_CLI_IMAGE="$CLI83_IMAGE"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless --artifacts --wordpress-offline
prepare_repo
FACTS=$(wp1 eval 'echo wp_json_encode(\Duo\PlatformCompatibility::current_facts());' | awk 'NF { line=$0 } END { print line }')
jq -e '
  .site_mode == "single-site" and .wordpress == "7.0.3" and
  (.php | startswith("8.3.")) and .database.engine == "MariaDB" and
  (.database.version | test("^11\\."))
' <<<"$FACTS" >/dev/null || fail "supported pair reported unexpected platform facts: $FACTS"

SOURCE_POST=$(wp1 post create --post_type=post --post_status=publish \
  --post_title='Core Platform Exact' --post_name=core-platform-exact \
  --post_content='Exact platform content — বাংলা — delimiter | value' --porcelain)
wp2 post create --post_type=post --post_status=publish --post_title='Target-only platform row' \
  --post_name=target-only-platform --post_content='must survive' --porcelain >/dev/null
CAPTURE=$(duo_json wp1 'supported source capture' capture --repo=/siterepo)
jq -e '
  .counts.post == 1 and .counts.term == 1 and .counts.options == 1 and
  .media == 0 and .notes == [] and .warnings == [] and
  .initial_publication_cleanup == "not-applicable"
' <<<"$CAPTURE" >/dev/null \
  || fail "supported source capture reported unexpected coverage: $CAPTURE"
cp -R "$R1/state" "$R2/state"
FIRST_APPLY=$(duo_json wp2 'supported initial apply' apply --repo=/siterepo \
  --default-author=admin --adopt-by-slug=terms)
jq -e '
  .plan.create == 1 and .plan.update == 2 and .plan.adopt == 1 and
  .plan.collision == 0 and .plan.conflict == 0 and .applied == 4 and
  .canary == "clean" and .verification.result == "pass" and
  .verification.live_entities == 4 and .promotion_lock.released == true and
  (.warnings | length) == 1 and
  (.warnings[0] | startswith("adopted env term 1 as ")) and
  (.warnings[0] | endswith("--uncategorized.json)"))
' <<<"$FIRST_APPLY" >/dev/null \
  || fail "supported initial apply did not prove explicit default-term adoption: $FIRST_APPLY"
SECOND_APPLY=$(duo_json wp2 'supported idempotent apply' apply --repo=/siterepo \
  --default-author=admin --adopt-by-slug=terms)
jq -e '
  .plan.create == 0 and .plan.update == 0 and .plan.unchanged == 4 and
  .plan.adopt == 0 and .plan.collision == 0 and .plan.conflict == 0 and
  .applied == 0 and .warnings == [] and .canary == "clean" and
  .verification.result == "pass" and .verification.live_entities == 4 and
  .promotion_lock.released == true
' <<<"$SECOND_APPLY" >/dev/null \
  || fail "supported repeat apply was not a verified zero-write result: $SECOND_APPLY"
TARGET_POST=$(wp2 post list --post_type=post --name=core-platform-exact --field=ID | awk 'NF { print; exit }')
[ -n "$TARGET_POST" ] && [ "$TARGET_POST" != "$SOURCE_POST" ] \
  || fail 'supported core round trip did not force and preserve divergent post identities'
TARGET_CONTENT=$(wp2 post get "$TARGET_POST" --field=post_content)
[ "$TARGET_CONTENT" = 'Exact platform content — বাংলা — delimiter | value' ] \
  || fail 'supported core round trip changed native UTF-8 content'
TARGET_ONLY_POST=$(wp2 post list --post_type=post --name=target-only-platform --field=ID | awk 'NF { print; exit }')
[ -n "$TARGET_ONLY_POST" ] && [ "$(wp2 post get "$TARGET_ONLY_POST" --field=post_content)" = 'must survive' ] \
  || fail 'supported apply overwrote target-only core state'
RECAPTURE=$(duo_json wp2 'supported target recapture' capture \
  --repo=/siterepo --out=/siterepo/state-check)
jq -e '
  .counts.post == 2 and .counts.term == 1 and .counts.options == 1 and
  .media == 0 and .notes == [] and .warnings == [] and
  .initial_publication_cleanup == "not-applicable"
' <<<"$RECAPTURE" >/dev/null \
  || fail "supported target recapture reported unexpected coverage: $RECAPTURE"
while IFS= read -r state_file; do
  [ -f "$R2/state-check/$state_file" ] \
    || fail "supported recapture dropped managed state file $state_file"
  cmp "$R2/state/$state_file" "$R2/state-check/$state_file" >/dev/null \
    || fail "supported recapture changed managed state bytes in $state_file"
done < <(cd "$R2/state" && find . -type f -print | LC_ALL=C sort)
EXTRA_STATE=$(comm -13 \
  <(cd "$R2/state" && find . -type f -print | LC_ALL=C sort) \
  <(cd "$R2/state-check" && find . -type f -print | LC_ALL=C sort))
[ "$(awk 'NF { count++ } END { print count + 0 }' <<<"$EXTRA_STATE")" -eq 1 ] \
  && [[ "$EXTRA_STATE" =~ ^\./posts/post/[0-9a-f-]+--target-only-platform\.md$ ]] \
  || fail "supported recapture did not add exactly the surviving target-only post: $EXTRA_STATE"

DOCTOR_OUT=$(php ../cli/duo doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1) \
  || fail "supported host doctor refused: $DOCTOR_OUT"
grep -q '\[PASS\] PHP version (8\.3\.' <<<"$DOCTOR_OUT" \
  && grep -q '\[PASS\] database (mariadb 11\.' <<<"$DOCTOR_OUT" \
  && grep -q '\[PASS\] WordPress core (7\.0\.3)' <<<"$DOCTOR_OUT" \
  || fail "supported host doctor did not pass every platform axis: $DOCTOR_OUT"
pass 'supported exact platform round-trips, repeats idempotently, preserves target-only state, recaptures managed bytes identically, and passes doctor'

say 'real adjacent WordPress version refuses before mutation'
STATE_BEFORE=$(tree_fingerprint "$R1/state")
wp1 core download --version="$WORDPRESS_REFUSED" --force --skip-content >/dev/null
[ "$(wp1 core version)" = "$WORDPRESS_REFUSED" ] \
  || fail 'real adjacent WordPress artifact did not replace the source runtime'
wp1 core verify-checksums --version="$WORDPRESS_REFUSED" >/dev/null \
  || fail 'adjacent WordPress bytes do not match the official release checksums'
assert_platform_json_refusal platform_wordpress_version_unsupported "$WORDPRESS_REFUSED" "$WORDPRESS_SUPPORTED" 'WordPress 7.0.2'
[ "$(tree_fingerprint "$R1/state")" = "$STATE_BEFORE" ] \
  && [ "$(wp1 option get duo_platform_mutation_canary)" = untouched ] \
  && [ ! -e "$R1/state.capture-staging" ] && [ ! -e "$R1/state.capture-backup" ] \
  || fail 'WordPress version refusal changed repository or authored state'
set +e
DOCTOR_OUT=$(php ../cli/duo doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1)
DOCTOR_RC=$?
set -e
[ "$DOCTOR_RC" -ne 0 ] && grep -q '\[FAIL\] WordPress core (7\.0\.2)' <<<"$DOCTOR_OUT" \
  || fail "host doctor did not refuse adjacent WordPress core: $DOCTOR_OUT"
pass 'real adjacent WordPress artifact is rejected by agent and doctor with zero mutation'

say 'real PHP exclusive-maximum runtime refuses before first publication'
destroy_owned_pair
export DUO_WP_IMAGE="$WP84_IMAGE" DUO_CLI_IMAGE="$CLI84_IMAGE"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless --artifacts --wordpress-offline
prepare_repo
PHP84=$(wp1 eval 'echo PHP_VERSION;' | awk 'NF { line=$0 } END { print line }')
[[ "$PHP84" == 8.4.* ]] || fail "refusal pair did not boot PHP 8.4: $PHP84"
assert_platform_json_refusal platform_php_version_unsupported "$PHP84" '>=8.3.0 <8.4.0' 'PHP 8.4 exclusive maximum'
[ ! -e "$R1/state" ] && [ ! -e "$R1/state.capture-staging" ] && [ ! -e "$R1/state.capture-backup" ] \
  && [ "$(wp1 option get duo_platform_mutation_canary)" = untouched ] \
  || fail 'PHP version refusal published repository state or changed authored state'
set +e
DOCTOR_OUT=$(php ../cli/duo doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1)
DOCTOR_RC=$?
set -e
[ "$DOCTOR_RC" -ne 0 ] && grep -q "\[FAIL\] PHP version ($PHP84)" <<<"$DOCTOR_OUT" \
  || fail "host doctor did not refuse real PHP exclusive maximum: $DOCTOR_OUT"
pass 'real PHP 8.4 runtime is rejected by agent and doctor before first publication'

printf '\n\033[1;32m✔ REGRESS_CORE_SCOPE_PLATFORM PASSED\033[0m\n'
