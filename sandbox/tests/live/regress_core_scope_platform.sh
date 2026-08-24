#!/usr/bin/env bash
# Exact-artifact core AND PHP platform matrix. Every WordPress core and every
# PHP series the shipped claim exercises — one pinned image digest per cell,
# including a core patch inside a proven series that no proof value names —
# must round-trip real core content on a MariaDB 11 single-site pair and pass
# the host doctor. A core below the claimed range and a PHP runtime at the
# claim's exclusive maximum must make both the direct agent product path and
# host doctor refuse before repository or authored-state mutation. The existing
# regress_multisite_refusal.sh owns the fourth live axis; the readiness ledger
# cites both suites explicitly.
#
# Both claimed sets are read out of manifests/capabilities/platform.json rather
# than restated here, so the proof cannot drift from the claim it backs. The
# image digest per cell is the one thing that must be hard-coded: registry
# digests do not belong in a shipped manifest.
#
# The PHP dimension is carried by the CLI image, not the web image: `wp1` runs
# in the pair's cli1 service, so PlatformCompatibility::current_facts()'s
# `php` fact is that image's PHP_VERSION. Each cell therefore names both, and
# asserts the measured PHP_VERSION EQUALS the claim's proof patch for that
# series — not `startswith`. That equality is the assertion that catches a
# `verified` value naming a patch nobody ran, which is the one failure mode a
# series map cannot prevent by shape alone.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${CORE_SCOPE_PLATFORM_PAIR:-corescope}"
PORT1="${CORE_SCOPE_PLATFORM_PORT1:-8996}"
PORT2="${CORE_SCOPE_PLATFORM_PORT2:-8997}"
PLATFORM_FILE='../manifests/capabilities/platform.json'
WP692_IMAGE='wordpress@sha256:ba1996f128e96e06613cffd9efa17e619d077a427c2d135204b1bc3bc9fa0510'
WP702_IMAGE='wordpress@sha256:3dcb744b16cb673639d98cf1aa5ea1de46732850629830bf101f44165b9040a1'
WP703_IMAGE='wordpress@sha256:a09147f15a882b956f67a617e9e1e053adf9322c45c797c2ff7c0e66522bf204'
# The 7.1 series' only release. Both wordpress:7.1-php8.3-apache and the
# docker-library alias wordpress:7.1.0-php8.3-apache resolve to this one OCI
# index (measured with `docker buildx imagetools inspect`); the core inside it
# calls itself '7.1', because wp-includes/version.php:19 of a WordPress x.y.0
# release ships a two-component $wp_version.
WP71_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
WP683_IMAGE='wordpress@sha256:30bff39330d1693b0ce13d32fc9b7bb67193064f040b7d60d3494e136fa599d4'
# The 7.1 series on PHP 8.4 — the web half of the newly claimed PHP series'
# exercise cell. The core is a claimed one on purpose: the only NEW axis this
# cell exercises is PHP.
WP71_PHP84_IMAGE='wordpress@sha256:dd1d6ff323bae668ebbfb0fce91042e1af7ee8d1568d4308f0f07ce3a4fe5140'
# The PHP refusal pair. It moved from 8.4 to 8.5 when the claim widened: 8.4 is
# an exercised series now, and 8.5.0 is the new exclusive maximum. Both halves
# are pinned so the refusal is a real runtime, not a fabricated fact.
WP85_IMAGE='wordpress@sha256:26cc4158e9665d943362bd224a0610a1e487514a1e13aa96512366b425c0cab0'
CLI83_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
CLI84_IMAGE='wordpress@sha256:13d152baa3c9111882d05e8ef4c32b4c84019b1bf7bf66b042c6b45e7aaba81d'
CLI85_IMAGE='wordpress@sha256:c2685291859c333b38afdbf882c5b9abdc0423703f3a8c6539bfd5ee3e7e2656'

# One exercise cell per claimed core AND per claimed PHP series, as
# `<wordpress> <web-image> <php-series> <cli-image>`. 6.9.2, 7.0.3 and 7.1 are
# the exact patches platform.json names as its per-series core proofs; 7.0.2 is
# a patch INSIDE the proven 7.0 series that no proof value names, and is
# exercised precisely because the claim generalizes over it — it refused before
# this matrix. The fifth cell is the PHP dimension: the same claimed 7.1 core
# on the newly claimed 8.4 series, so a failure there can only be PHP.
EXERCISE_CELLS=(
  "6.9.2 $WP692_IMAGE 8.3 $CLI83_IMAGE"
  "7.0.2 $WP702_IMAGE 8.3 $CLI83_IMAGE"
  "7.0.3 $WP703_IMAGE 8.3 $CLI83_IMAGE"
  "7.1 $WP71_IMAGE 8.3 $CLI83_IMAGE"
  "7.1 $WP71_PHP84_IMAGE 8.4 $CLI84_IMAGE"
)
# A core below the claimed minimum, not an adjacent patch: every 7.0.x is
# inside the claim now, so the refusal cell has to leave the range entirely.
WORDPRESS_REFUSED='6.8.3'
WORDPRESS_REFUSED_IMAGE="$WP683_IMAGE"
# The PHP refusal cell ships a claimed core on purpose: the only axis it may
# fail on is PHP.
PHP_REFUSED_WORDPRESS='7.1'

[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CORE_SCOPE_PLATFORM_PAIR '$PAIR'"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] || fail 'platform ports must be decimal integers'
PORT1=$((10#$PORT1)); PORT2=$((10#$PORT2))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail 'platform ports must be an even value >=8900 and its adjacent successor'
command -v jq >/dev/null || fail 'jq is required'
command -v docker >/dev/null || fail 'docker is required'

SOURCE_ROOT=$(git rev-parse --show-toplevel) || fail 'platform evidence has no resolvable repository root'
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

# The whole supported leg for one claimed core: a real round trip, a verified
# zero-write repeat, target-only survival, a byte-identical recapture, and
# doctor. Run once per exercise cell against that cell's own pinned digest.
exercise_core() { # <wordpress-version> <web-image> <php-series> <cli-image>
  local version="$1" image="$2" php_series="$3" cli_image="$4"
  local version_re="${version//./\\.}"
  local php_proof facts source_post capture first_apply second_apply target_post target_content
  local target_only_post recapture extra_state doctor_out state_file

  # The exact patch the claim says this PHP series was proven on. The cell
  # asserts EQUALITY against it below, so a `verified` value naming a patch
  # nobody ran fails here loudly instead of being published quietly.
  php_proof=$(jq -er --arg series "$php_series" \
    '.platform.compatibility.php.verified[$series]' "$PLATFORM_FILE") \
    || fail "the shipped claim names no PHP proof patch for series $php_series"

  say "supported claimed core $version on claimed PHP $php_series ($php_proof): real core round trip and doctor"
  destroy_owned_pair
  export DUO_WP_IMAGE="$image" DUO_CLI_IMAGE="$cli_image"
  bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless --artifacts --wordpress-offline
  prepare_repo
  facts=$(wp1 eval 'echo wp_json_encode(\Duo\PlatformCompatibility::current_facts());' | awk 'NF { line=$0 } END { print line }')
  jq -e --arg version "$version" --arg php "$php_proof" '
    .site_mode == "single-site" and .wordpress == $version and
    .php == $php and .database.engine == "MariaDB" and
    (.database.version | test("^11\\.")) and
    .filesystem == {
      directory_separator:"/",
      functions:{chmod:true,flock:true,fsync:true,lstat:true,rename:true},
      os_family:"Linux"
    } and
    .process == {
      functions:{passthru:true,posix_kill:true,posix_setsid:true,proc_close:true,proc_get_status:true,proc_open:true,proc_terminate:true},
      os_family:"Linux",
      shell:{executable:true,path:"/bin/sh"}
    }
  ' <<<"$facts" >/dev/null || fail "claimed core $version on PHP $php_series reported unexpected platform facts (expected php exactly $php_proof): $facts"

  source_post=$(wp1 post create --post_type=post --post_status=publish \
    --post_title='Core Platform Exact' --post_name=core-platform-exact \
    --post_content='Exact platform content — বাংলা — delimiter | value' --porcelain)
  wp2 post create --post_type=post --post_status=publish --post_title='Target-only platform row' \
    --post_name=target-only-platform --post_content='must survive' --porcelain >/dev/null
  capture=$(duo_json wp1 "claimed core $version source capture" capture --repo=/siterepo)
  jq -e '
    .counts.post == 1 and .counts.term == 1 and .counts.options == 1 and
    .media == 0 and .notes == [] and .warnings == [] and
    .initial_publication_cleanup == "not-applicable"
  ' <<<"$capture" >/dev/null \
    || fail "claimed core $version source capture reported unexpected coverage: $capture"
  cp -R "$R1/state" "$R2/state"
  first_apply=$(duo_json wp2 "claimed core $version initial apply" apply --repo=/siterepo \
    --default-author=admin --adopt-by-slug=terms)
  jq -e '
    .plan.create == 1 and .plan.update == 2 and .plan.adopt == 1 and
    .plan.collision == 0 and .plan.conflict == 0 and .applied == 4 and
    .canary == "clean" and .verification.result == "pass" and
    .verification.live_entities == 4 and .promotion_lock.released == true and
    (.warnings | length) == 1 and
    (.warnings[0] | startswith("adopted env term 1 as ")) and
    (.warnings[0] | endswith("--uncategorized.json)"))
  ' <<<"$first_apply" >/dev/null \
    || fail "claimed core $version initial apply did not prove explicit default-term adoption: $first_apply"
  second_apply=$(duo_json wp2 "claimed core $version idempotent apply" apply --repo=/siterepo \
    --default-author=admin --adopt-by-slug=terms)
  jq -e '
    .plan.create == 0 and .plan.update == 0 and .plan.unchanged == 4 and
    .plan.adopt == 0 and .plan.collision == 0 and .plan.conflict == 0 and
    .applied == 0 and .warnings == [] and .canary == "clean" and
    .verification.result == "pass" and .verification.live_entities == 4 and
    .promotion_lock.released == true
  ' <<<"$second_apply" >/dev/null \
    || fail "claimed core $version repeat apply was not a verified zero-write result: $second_apply"
  target_post=$(wp2 post list --post_type=post --name=core-platform-exact --field=ID | awk 'NF { print; exit }')
  [ -n "$target_post" ] && [ "$target_post" != "$source_post" ] \
    || fail "claimed core $version round trip did not force and preserve divergent post identities"
  target_content=$(wp2 post get "$target_post" --field=post_content)
  [ "$target_content" = 'Exact platform content — বাংলা — delimiter | value' ] \
    || fail "claimed core $version round trip changed native UTF-8 content"
  target_only_post=$(wp2 post list --post_type=post --name=target-only-platform --field=ID | awk 'NF { print; exit }')
  [ -n "$target_only_post" ] && [ "$(wp2 post get "$target_only_post" --field=post_content)" = 'must survive' ] \
    || fail "claimed core $version apply overwrote target-only core state"
  recapture=$(duo_json wp2 "claimed core $version target recapture" capture \
    --repo=/siterepo --out=/siterepo/state-check)
  jq -e '
    .counts.post == 2 and .counts.term == 1 and .counts.options == 1 and
    .media == 0 and .notes == [] and .warnings == [] and
    .initial_publication_cleanup == "not-applicable"
  ' <<<"$recapture" >/dev/null \
    || fail "claimed core $version target recapture reported unexpected coverage: $recapture"
  while IFS= read -r state_file; do
    [ -f "$R2/state-check/$state_file" ] \
      || fail "claimed core $version recapture dropped managed state file $state_file"
    cmp "$R2/state/$state_file" "$R2/state-check/$state_file" >/dev/null \
      || fail "claimed core $version recapture changed managed state bytes in $state_file"
  done < <(cd "$R2/state" && find . -type f -print | LC_ALL=C sort)
  extra_state=$(comm -13 \
    <(cd "$R2/state" && find . -type f -print | LC_ALL=C sort) \
    <(cd "$R2/state-check" && find . -type f -print | LC_ALL=C sort))
  [ "$(awk 'NF { count++ } END { print count + 0 }' <<<"$extra_state")" -eq 1 ] \
    && [[ "$extra_state" =~ ^\./posts/post/[0-9a-f-]+--target-only-platform\.md$ ]] \
    || fail "claimed core $version recapture did not add exactly the surviving target-only post: $extra_state"

  doctor_out=$(php ../cli/duo doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1) \
    || fail "claimed core $version host doctor refused: $doctor_out"
  grep -q "\[PASS\] PHP version (${php_proof//./\\.})" <<<"$doctor_out" \
    && grep -q '\[PASS\] database (mariadb 11\.' <<<"$doctor_out" \
    && grep -q "\[PASS\] WordPress core ($version_re)" <<<"$doctor_out" \
    || fail "claimed core $version on PHP $php_proof host doctor did not pass every platform axis: $doctor_out"
  pass "claimed core $version on PHP $php_proof round-trips, repeats idempotently, preserves target-only state, recaptures managed bytes identically, and passes doctor"
}

MATRIX_IMAGES=("$WORDPRESS_REFUSED_IMAGE" "$WP85_IMAGE" "$CLI85_IMAGE")
for cell in "${EXERCISE_CELLS[@]}"; do
  read -r _ cell_image _ cell_cli <<<"$cell"
  MATRIX_IMAGES+=("$cell_image" "$cell_cli")
done
for image in "${MATRIX_IMAGES[@]}"; do
  docker image inspect "$image" >/dev/null 2>&1 \
    || fail "exact platform image is absent locally; this evidence run will not float or pull: $image"
done
for cell in "${EXERCISE_CELLS[@]}" "$WORDPRESS_REFUSED $WORDPRESS_REFUSED_IMAGE" "$PHP_REFUSED_WORDPRESS $WP85_IMAGE"; do
  read -r cell_version cell_image _ <<<"$cell"
  [ "$(docker run --rm --entrypoint php "$cell_image" -r 'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;')" = "$cell_version" ] \
    || fail "matrix image does not carry exact WordPress $cell_version: $cell_image"
done

# Each cell's CLI image must be the EXACT patch the claim names for that
# cell's series — not merely a member of the series. `verified` says "this
# series was proven on this runtime"; if the image is a different patch then
# either the claim names a runtime nobody ran or this matrix is not the proof
# the claim points at. Both are refusals, and both are cheaper here than after
# five pairs have booted.
PROCESS_PROFILE_SERIES=()
PROCESS_PROFILE_IMAGES=()
for cell in "${EXERCISE_CELLS[@]}"; do
  read -r _ _ cell_series cell_cli <<<"$cell"
  cell_proof=$(jq -er --arg series "$cell_series" \
    '.platform.compatibility.php.verified[$series]' "$PLATFORM_FILE") \
    || fail "exercise cell names PHP series $cell_series, which the shipped claim does not exercise"
  cell_php=$(docker run --rm --entrypoint php "$cell_cli" -r 'echo PHP_VERSION;')
  [ "$cell_php" = "$cell_proof" ] \
    || fail "PHP $cell_series exercise image reports $cell_php, but the claim's proof patch for that series is $cell_proof: $cell_cli"
  process_series_seen=0
  for seen_series in "${PROCESS_PROFILE_SERIES[@]}"; do
    [ "$seen_series" != "$cell_series" ] || process_series_seen=1
  done
  if [ "$process_series_seen" -eq 0 ]; then
    PROCESS_PROFILE_SERIES+=("$cell_series")
    PROCESS_PROFILE_IMAGES+=("$cell_cli")
  fi
done

# Function facts prove only that symbols exist. The declared process profile
# also promises concurrent bounded pipes, monotonic timeout, TERM-to-KILL
# escalation, and an empty owned process group before return. Exercise those
# semantics under the exact Linux image for every claimed PHP series before a
# pair can mutate either database; the same 63-assertion product regression is
# the deterministic Darwin proof in regress-offline-all.
for process_index in "${!PROCESS_PROFILE_SERIES[@]}"; do
  process_series=${PROCESS_PROFILE_SERIES[$process_index]}
  process_image=${PROCESS_PROFILE_IMAGES[$process_index]}
  process_output=$(docker run --rm \
    --volume "$SOURCE_ROOT:/duo-source:ro" \
    --entrypoint php "$process_image" \
    /duo-source/sandbox/tests/offline/guards/regress_wp_cli_child_process.php 2>&1) \
    || fail "PHP $process_series Linux process-profile behavior failed: $process_output"
  grep -Fxq 'PASS: bounded WP-CLI child process (63 assertions)' <<<"$process_output" \
    || fail "PHP $process_series Linux process-profile regression returned an incomplete verdict: $process_output"
  pass "PHP $process_series Linux process groups, bounded pipes, deadlines, and descendant reap satisfy all 63 assertions"
done
PHP_REFUSED=$(docker run --rm --entrypoint php "$CLI85_IMAGE" -r 'echo PHP_VERSION;')
[ -n "$PHP_REFUSED" ] || fail 'could not read the refusal CLI image PHP version'
PHP_CLAIM_MAX="$(jq -er '.platform.compatibility.php.max' "$PLATFORM_FILE")"
php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$PHP_REFUSED" "$PHP_CLAIM_MAX" \
  || fail "refusal CLI image PHP $PHP_REFUSED is inside the claimed range (<$PHP_CLAIM_MAX); the refusal cell would prove nothing"

# The declaration this matrix backs must be well formed BEFORE any pair boots,
# and the shape is asserted rather than the numbers: the numbers are read out
# of the same file everywhere below, so restating them here would only pin the
# proof to a snapshot of the claim instead of to the claim.
jq -e '
  .platform.site_mode == "single-site" and
  (.platform.compatibility.wordpress as $wp |
    ($wp.min | type) == "string" and ($wp.max | type) == "string" and
    ($wp.verified | type) == "object" and ($wp.verified | length) > 0 and
    ([$wp.verified[]] | index($wp.last_verified)) != null) and
  (.platform.compatibility.php as $php |
    ($php.min | type) == "string" and ($php.max | type) == "string" and
    ($php.verified | type) == "object" and ($php.verified | length) > 0 and
    ($php | has("last_verified") | not)) and
  (.platform.compatibility.database as $db |
    ($db | has("engine") | not) and
    ($db.engines | type) == "object" and ($db.engines | length) > 0 and
    ($db.engines | has("MariaDB")) and
    ([$db.engines[] | (keys == ["max","min"])] | all)) and
  (.platform.compatibility.filesystem == {
    directory_separator:"/",
    note:.platform.compatibility.filesystem.note,
    os_families:["Darwin","Linux"],
    profile:"local-posix-atomic-rename-flock-fsync/v1",
    required_functions:["chmod","flock","fsync","lstat","rename"]
  }) and
  (.platform.compatibility.process == {
    note:.platform.compatibility.process.note,
    os_families:["Darwin","Linux"],
    profile:"local-posix-process-group-exec/v1",
    required_functions:["passthru","posix_kill","posix_setsid","proc_close","proc_get_status","proc_open","proc_terminate"],
    shell:"/bin/sh"
  })
' "$PLATFORM_FILE" >/dev/null \
  || fail 'shipped platform declaration is not a well-formed core/PHP/database/local-POSIX/process matrix'

# This suite runs every pair on MariaDB 11 (sandbox/db.yml), so the MariaDB
# entry is the one it can speak for. The MySQL entry the same map now claims is
# proven by sandbox/tests/live/regress_core_scope_database.sh, not here — and
# that split is deliberate: an engine axis proven by a suite that never booted
# the engine would be exactly the hollow coverage DESIGN.md forbids.
jq -e '
  .platform.compatibility.database.engines.MariaDB.min == "11.0.0" and
  .platform.compatibility.database.engines.MariaDB.max == "12.0.0"
' "$PLATFORM_FILE" >/dev/null \
  || fail 'the MariaDB engine entry this matrix exercises is not the 11.x line the harness boots'

# Every core the claim says was proven must have an exercise cell here, and
# every exercise cell must be a core the claim actually admits. Read out of the
# shipped file so a series added to the claim without a live cell — or a cell
# the claim does not cover — fails before any pair boots.
while IFS= read -r verified_patch; do
  matched=0
  for cell in "${EXERCISE_CELLS[@]}"; do
    [ "${cell%% *}" = "$verified_patch" ] && matched=1
  done
  [ "$matched" -eq 1 ] \
    || fail "claimed proof core $verified_patch has no exercise cell in this matrix"
done < <(jq -er '.platform.compatibility.wordpress.verified | .[]' "$PLATFORM_FILE")
for cell in "${EXERCISE_CELLS[@]}"; do
  version="${cell%% *}"
  jq -e --arg version "$version" '
    .platform.compatibility.wordpress.verified
    | has($version | capture("^(?<series>[0-9]+\\.[0-9]+)").series)
  ' "$PLATFORM_FILE" >/dev/null \
    || fail "exercise cell $version is not inside any core series the shipped claim exercises"
done
jq -e --arg version "$WORDPRESS_REFUSED" '
  .platform.compatibility.wordpress.verified
  | has($version | capture("^(?<series>[0-9]+\\.[0-9]+)").series)
  | not
' "$PLATFORM_FILE" >/dev/null \
  || fail "refusal cell $WORDPRESS_REFUSED is a core series the shipped claim exercises"

# The identical cross-check for the PHP dimension, both ways. A series added to
# the claim without an exercise cell, or a cell naming a series the claim does
# not exercise, must fail before any pair boots — that is what stops the two
# halves of this evidence drifting apart between commits.
while IFS= read -r verified_series; do
  matched=0
  for cell in "${EXERCISE_CELLS[@]}"; do
    read -r _ _ cell_series _ <<<"$cell"
    [ "$cell_series" = "$verified_series" ] && matched=1
  done
  [ "$matched" -eq 1 ] \
    || fail "claimed PHP series $verified_series has no exercise cell in this matrix"
done < <(jq -er '.platform.compatibility.php.verified | keys[]' "$PLATFORM_FILE")
for cell in "${EXERCISE_CELLS[@]}"; do
  read -r _ _ cell_series _ <<<"$cell"
  jq -e --arg series "$cell_series" \
    '.platform.compatibility.php.verified | has($series)' "$PLATFORM_FILE" >/dev/null \
    || fail "exercise cell PHP series $cell_series is not a series the shipped claim exercises"
done
jq -e --arg version "$PHP_REFUSED" '
  .platform.compatibility.php.verified
  | has($version | capture("^(?<series>[0-9]+\\.[0-9]+)").series)
  | not
' "$PLATFORM_FILE" >/dev/null \
  || fail "PHP refusal cell $PHP_REFUSED is a series the shipped claim exercises"

# The exact `required` label the agent emits for a refused core
# (PlatformCompatibility::wordpress_label()): the declared range plus every
# exercised series. jq's `keys` sorts strings; the agent orders with
# version_compare, and the two agree for every series this claim names.
WORDPRESS_REQUIRED_LABEL="$(jq -er '
  .platform.compatibility.wordpress
  | ">=\(.min) <\(.max) exercised \(.verified | keys | join(", "))"
' "$PLATFORM_FILE")" || fail 'could not derive the WordPress matrix label from the shipped claim'
# The PHP axis emits the identical label shape through the identical helper
# (PlatformCompatibility::exercised_label()), so it is derived the same way
# rather than written out — a literal here would be a second copy of the claim.
PHP_REQUIRED_LABEL="$(jq -er '
  .platform.compatibility.php
  | ">=\(.min) <\(.max) exercised \(.verified | keys | join(", "))"
' "$PLATFORM_FILE")" || fail 'could not derive the PHP matrix label from the shipped claim'
pass "all WordPress/PHP artifacts and declared platform values are exact before pair mutation ($WORDPRESS_REQUIRED_LABEL; $PHP_REQUIRED_LABEL)"

for cell in "${EXERCISE_CELLS[@]}"; do
  # shellcheck disable=SC2086  # each field is a version, a series or a digest
  exercise_core $cell
done

say "real core below the claimed range refuses before mutation"
destroy_owned_pair
export DUO_WP_IMAGE="$WORDPRESS_REFUSED_IMAGE" DUO_CLI_IMAGE="$CLI83_IMAGE"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless --artifacts --wordpress-offline
prepare_repo
[ "$(wp1 core version)" = "$WORDPRESS_REFUSED" ] \
  || fail 'pinned unexercised WordPress artifact did not boot the refused runtime'
assert_platform_json_refusal platform_wordpress_version_unsupported "$WORDPRESS_REFUSED" \
  "$WORDPRESS_REQUIRED_LABEL" "WordPress $WORDPRESS_REFUSED"
[ ! -e "$R1/state" ] && [ "$(wp1 option get duo_platform_mutation_canary)" = untouched ] \
  && [ ! -e "$R1/state.capture-staging" ] && [ ! -e "$R1/state.capture-backup" ] \
  || fail 'WordPress version refusal changed repository or authored state'
set +e
DOCTOR_OUT=$(php ../cli/duo doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1)
DOCTOR_RC=$?
set -e
[ "$DOCTOR_RC" -ne 0 ] && grep -q "\[FAIL\] WordPress core (${WORDPRESS_REFUSED//./\\.})" <<<"$DOCTOR_OUT" \
  || fail "host doctor did not refuse the unexercised WordPress core: $DOCTOR_OUT"
pass 'a real WordPress artifact outside the claimed matrix is rejected by agent and doctor with zero mutation'

say 'real PHP runtime past the exclusive maximum refuses before first publication'
destroy_owned_pair
export DUO_WP_IMAGE="$WP85_IMAGE" DUO_CLI_IMAGE="$CLI85_IMAGE"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless --artifacts --wordpress-offline
prepare_repo
PHP_BOOTED=$(wp1 eval 'echo PHP_VERSION;' | awk 'NF { line=$0 } END { print line }')
[ "$PHP_BOOTED" = "$PHP_REFUSED" ] \
  || fail "refusal pair booted $PHP_BOOTED, not the preflighted out-of-range runtime $PHP_REFUSED"
# The expected label is derived from the claim, exactly like the WordPress one:
# the whole point of the exercised-series label is that it moves with the claim,
# so a literal here would stop proving that the operator is told the current
# matrix.
assert_platform_json_refusal platform_php_version_unsupported "$PHP_BOOTED" "$PHP_REQUIRED_LABEL" \
  "PHP $PHP_BOOTED past the exclusive maximum"
[ ! -e "$R1/state" ] && [ ! -e "$R1/state.capture-staging" ] && [ ! -e "$R1/state.capture-backup" ] \
  && [ "$(wp1 option get duo_platform_mutation_canary)" = untouched ] \
  || fail 'PHP version refusal published repository state or changed authored state'
set +e
DOCTOR_OUT=$(php ../cli/duo doctor "${PAIR}1" --envs-file="$ENVS_FILE" 2>&1)
DOCTOR_RC=$?
set -e
[ "$DOCTOR_RC" -ne 0 ] && grep -q "\[FAIL\] PHP version ($PHP_BOOTED)" <<<"$DOCTOR_OUT" \
  || fail "host doctor did not refuse real PHP past the exclusive maximum: $DOCTOR_OUT"
pass "real PHP $PHP_BOOTED runtime is rejected by agent and doctor before first publication"

printf '\n\033[1;32m✔ REGRESS_CORE_SCOPE_PLATFORM PASSED\033[0m\n'
