#!/usr/bin/env bash
# Core has no activatable package or adapter version_range: its applicable
# lifecycle is the WordPress host plus the managed theme transition exercised
# by conformance/run.sh. This closes the host half against two exact core image
# digests: in-place upgrade, current-version reinstall, exact rollback and
# restore, stale core-file pruning, wp-content/config preservation, WPrism ledger
# survival, apply/recapture after every transition, and public HTTP behavior.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. lib/pair_db.sh
pair_db_select_engine

PAIR="${CORE_LIFECYCLE_PAIR:-corelifecycle}"
PORT1="${CORE_LIFECYCLE_PORT1:-8992}"
PORT2="${CORE_LIFECYCLE_PORT2:-8993}"
# CURRENT_VERSION is not free: :175-177 below asserts it IS
# platform.json's last_verified, and platform.json's own note states
# last_verified is what sandbox/pair.yml boots by default. Widening the claim
# to the 7.1 series therefore moves this pair too, and the upgrade under test
# becomes the 7.0.3 -> 7.1 minor step (both cores' $wp_db_version is 61833, so
# `wp core update-db` stays the no-op it was for 7.0.2 -> 7.0.3). '7.1' is the
# two-component string WordPress ships for a series' first release
# (wp-includes/version.php:19), which is what `wp core version` prints.
OLDER_VERSION=7.0.3
CURRENT_VERSION=7.1
OLDER_IMAGE='wordpress@sha256:a09147f15a882b956f67a617e9e1e053adf9322c45c797c2ff7c0e66522bf204'
CURRENT_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'

[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CORE_LIFECYCLE_PAIR '$PAIR'"
[ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" ] \
  || fail 'WPRISM_EXPECTED_SOURCE_SHA is required: lifecycle evidence must bind the exact clean candidate'
command -v jq >/dev/null || fail 'jq is required'
command -v curl >/dev/null || fail 'curl is required'

export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_WP_IMAGE="$OLDER_IMAGE" WPRISM_ARTIFACT_OFFLINE=1
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.http.yml -f pair.artifacts.yml -f pair.wordpress-offline.yml)
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }

image_core_version() {
  docker run --rm --entrypoint php "$1" -r \
    'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;'
}

wait_for_core() { # <side> <expected-version>
  local side="$1" expected="$2" attempt observed=''
  for attempt in 1 2 3 4 5 6 7 8; do
    if observed=$("${COMPOSE[@]}" run --rm -T "cli$side" wp core version 2>/dev/null) \
      && [ "$observed" = "$expected" ]; then
      return 0
    fi
    sleep "$attempt"
  done
  fail "side $side did not boot exact WordPress $expected after replacement (last answer: ${observed:-none})"
}

replace_core() { # <side> <exact-image-ref> <expected-version> <transition-label>
  local side="$1" image="$2" expected="$3" label="$4"
  local web="wp$side" cli="cli$side" container="wprism-${PAIR}-wp${side}-1"

  "${COMPOSE[@]}" stop "$web" "$cli" >/dev/null
  docker run --rm --volumes-from "$container" --entrypoint sh "$image" -c '
    find /var/www/html -mindepth 1 -maxdepth 1 \
      ! -name wp-content ! -name wp-config.php ! -name .htaccess \
      -exec rm -rf -- {} +
    cd /usr/src/wordpress
    tar -cf - --exclude=./wp-content --exclude=./.htaccess . \
      | tar -xf - -C /var/www/html
    diff -qr --exclude=wp-content --exclude=wp-config.php --exclude=.htaccess \
      /usr/src/wordpress /var/www/html
  '
  "${COMPOSE[@]}" start "$web" "$cli" >/dev/null
  wait_for_core "$side" "$expected"
  "${COMPOSE[@]}" run --rm -T "cli$side" wp core update-db >/dev/null
  pass "$label left side $side on the exact $expected core tree and completed the database upgrader"
}

clear_tree() {
  [ ! -e "$1" ] || find "$1" -depth -delete
}

sync_state() {
  clear_tree "$R2/state"
  clear_tree "$R2/media"
  cp -R "$R1/state" "$R2/state"
  [ ! -d "$R1/media" ] || cp -R "$R1/media" "$R2/media"
}

recapture_matches() {
  local output
  clear_tree "$R2/state-check"
  output=$(wp2 wprism capture --repo=/siterepo --out=/siterepo/state-check 2>&1) \
    || fail "$1: target recapture failed: $output"
  ! grep -Fq 'Warning:' <<<"$output" \
    || fail "$1: target recapture returned success with a warning: $output"
  diff -r "$R2/state" "$R2/state-check" >/dev/null \
    || fail "$1: target recapture differs from the source canonical tree"
  pass "$1 recaptures byte-identically"
}

source_capture() {
  local label="$1" output
  output=$(wp1 wprism capture --repo=/siterepo 2>&1) \
    || fail "$label: source capture failed: $output"
  ! grep -Fq 'Warning:' <<<"$output" \
    || fail "$label: source capture returned success with a warning: $output"
}

changed_apply() {
  local label="$1" raw output
  raw=$(wp2 wprism apply --repo=/siterepo --default-author=admin --format=json 2>&1) \
    || fail "$label: target apply failed: $raw"
  ! grep -Fq 'Warning:' <<<"$raw" \
    || fail "$label: target apply returned success with a warning: $raw"
  output=$(awk 'NF { line=$0 } END { print line }' <<<"$raw")
  jq -e '.canary == "clean"' <<<"$output" >/dev/null \
    || fail "$label: target apply lacked a clean machine result: $raw"
}

zero_apply() {
  local label="$1" raw output
  raw=$(wp2 wprism apply --repo=/siterepo --default-author=admin --format=json 2>&1) \
    || fail "$label: repeated apply failed: $raw"
  ! grep -Fq 'Warning:' <<<"$raw" \
    || fail "$label: repeated apply returned success with a warning: $raw"
  output=$(awk 'NF { line=$0 } END { print line }' <<<"$raw")
  jq -e '.canary == "clean" and .actions == []' <<<"$output" >/dev/null \
    || fail "$label: repeated apply was not a clean zero-action result: $output"
  [ "$(wp2 option get _wp_session_core_lifecycle_target)" = 'target-runtime-survives' ] \
    || fail "$label: target-owned runtime option was overwritten"
  pass "$label accepts a clean zero-action apply without overwriting target runtime state"
}

target_fingerprint() {
  wp2 eval '
    global $wpdb;
    $post = get_page_by_path("core-lifecycle", OBJECT, "post");
    $css_id = (int) get_theme_mod("custom_css_post_id", 0);
    $css = $css_id > 0 ? get_post($css_id) : null;
    echo wp_json_encode([
      "blogname" => get_option("blogname"),
      "blogdescription" => get_option("blogdescription"),
      "permalink_structure" => get_option("permalink_structure"),
      "post_content" => $post ? $post->post_content : null,
      "post_status" => $post ? $post->post_status : null,
      "custom_css_id" => $css_id,
      "custom_css" => $css ? $css->post_content : null,
      "target_runtime" => get_option("_wp_session_core_lifecycle_target"),
      "ledger_canary" => \WPrism\Ledger::kv_get("core_lifecycle_canary"),
      "applied_revision" => \WPrism\Ledger::kv_get("applied_revision"),
      "map_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wprism_map"),
      "state_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wprism_state"),
    ]);
  '
}

assert_public_post() {
  local label="$1" url body
  url=$(wp2 eval '$p=get_page_by_path("core-lifecycle", OBJECT, "post"); echo $p ? get_permalink($p) : "";')
  [ -n "$url" ] || fail "$label: target post has no public permalink"
  body=$(curl -fsSL "$url") || fail "$label: target post route did not return HTTP success"
  grep -Fq 'Core lifecycle body after exact upgrade.' <<<"$body" \
    || fail "$label: public response did not render the upgraded canonical content"
  pass "$label boots WordPress, resolves the canonical permalink, and renders the expected content"
}

say 'exact image and adapter-structure preflight'
for image in "$OLDER_IMAGE" "$CURRENT_IMAGE"; do
  docker image inspect "$image" >/dev/null 2>&1 \
    || fail "exact lifecycle image is absent locally (offline proof will not pull): $image"
done
[ "$(image_core_version "$OLDER_IMAGE")" = "$OLDER_VERSION" ] \
  || fail "$OLDER_IMAGE does not contain WordPress $OLDER_VERSION"
[ "$(image_core_version "$CURRENT_IMAGE")" = "$CURRENT_VERSION" ] \
  || fail "$CURRENT_IMAGE does not contain WordPress $CURRENT_VERSION"
jq -e '.name == "core" and (has("plugin") | not) and (has("version_range") | not)' ../platform/adapter-library/core/manifest.json >/dev/null \
  || fail 'core unexpectedly acquired an activatable plugin package or adapter version range'
jq -e --arg version "$CURRENT_VERSION" '.platform.compatibility.wordpress.last_verified == $version' \
  ../platform/adapter-library/capabilities/platform.json >/dev/null \
  || fail "platform last_verified no longer names lifecycle current version $CURRENT_VERSION"
pass 'both WordPress artifacts are digest-pinned; core has no fictitious plugin activation or adapter version range'

say 'fresh exact older-version pair'
bash bin/pair.sh list
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --http --artifacts --wordpress-offline
[ "$(wp1 core version)" = "$OLDER_VERSION" ] && [ "$(wp2 core version)" = "$OLDER_VERSION" ] \
  || fail "pair did not start with WordPress $OLDER_VERSION on both sides"

for repo_dir in "$R1" "$R2"; do
  cp site-repo.gitignore.template "$repo_dir/.gitignore"
  cp tests/fixtures/core_lifecycle_site.wprism.json "$repo_dir/site.wprism.json"
done
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 option update blogname 'Core Lifecycle Canonical' >/dev/null
wp1 option update blogdescription 'captured before exact upgrade' >/dev/null
wp1 option update permalink_structure '/lifecycle/%postname%/' >/dev/null
TERM1=$(wp1 term create category 'Lifecycle Category' --slug=lifecycle-category --porcelain)
POST1=$(wp1 post create --post_type=post --post_status=publish --post_name=core-lifecycle \
  --post_title='Core lifecycle' --post_content='Core lifecycle body before exact upgrade.' --porcelain)
wp1 post term add "$POST1" category lifecycle-category --by=slug >/dev/null
CSS1=$(wp1 eval '
  $post = wp_update_custom_css_post("body { border-top: 3px solid #135e96; }");
  if (is_wp_error($post) || !$post instanceof WP_Post) {
    throw new RuntimeException("could not create the lifecycle Custom CSS post");
  }
  echo $post->ID;
')
wp2 option update _wp_session_core_lifecycle_target 'target-runtime-survives' >/dev/null
wp2 option update blogname 'Hostile target title' >/dev/null
source_capture 'pre-upgrade source capture'
sync_state
INITIAL_APPLY=$(wp2 wprism apply --repo=/siterepo --default-author=admin --adopt-by-slug=posts,terms 2>&1) \
  || fail "initial target apply failed: $INITIAL_APPLY"
[ "$(grep -c '^Warning:' <<<"$INITIAL_APPLY")" = 2 ] \
  && grep -Fq 'Warning: adopted env term ' <<<"$INITIAL_APPLY" \
  && grep -Fq 'Warning: native action fired: rewrite.flush (verified)' <<<"$INITIAL_APPLY" \
  || fail "initial target apply did not return exactly its two expected, asserted warnings: $INITIAL_APPLY"
wp2 eval '\WPrism\Ledger::kv_set("core_lifecycle_canary", "ledger-survives-core-replacement");' >/dev/null
recapture_matches 'pre-upgrade control'
[ "$(wp2 option get _wp_session_core_lifecycle_target)" = 'target-runtime-survives' ] \
  || fail 'initial apply overwrote target-owned runtime state'
[ "$TERM1" -gt 0 ] || fail 'source lifecycle term was not created'
[ "$CSS1" -gt 0 ] || fail 'source lifecycle Custom CSS post was not created'
pass 'WordPress 7.0.2 executes the real core capture/apply/recapture path before the transition'

say "in-place exact upgrade: $OLDER_VERSION -> $CURRENT_VERSION on source and target"
replace_core 1 "$CURRENT_IMAGE" "$CURRENT_VERSION" 'source upgrade'
replace_core 2 "$CURRENT_IMAGE" "$CURRENT_VERSION" 'target upgrade'
wp1 option update blogdescription 'captured after exact upgrade' >/dev/null
wp1 post update "$POST1" --post_content='Core lifecycle body after exact upgrade.' >/dev/null
source_capture 'post-upgrade source capture'
sync_state
changed_apply 'post-upgrade target apply'
recapture_matches 'post-upgrade product path'
zero_apply 'post-upgrade idempotence'
assert_public_post 'post-upgrade target'

say 'same-version reinstall and residue boundary'
WP_CONFIG_BEFORE=$(docker exec "wprism-${PAIR}-wp2-1" sha256sum /var/www/html/wp-config.php | awk '{print $1}')
HTACCESS_BEFORE=$(docker exec "wprism-${PAIR}-wp2-1" sha256sum /var/www/html/.htaccess | awk '{print $1}')
docker exec "wprism-${PAIR}-wp2-1" sh -c '
  touch /var/www/html/wp-admin/wprism-stale-core-residue.php
  printf "%s\n" operator-owned > /var/www/html/wp-content/wprism-core-lifecycle-content-canary
'
FINGERPRINT_BEFORE=$(target_fingerprint)
replace_core 2 "$CURRENT_IMAGE" "$CURRENT_VERSION" 'same-version reinstall'
[ "$(docker exec "wprism-${PAIR}-wp2-1" sh -c 'test ! -e /var/www/html/wp-admin/wprism-stale-core-residue.php && echo absent')" = absent ] \
  || fail 'same-version reinstall retained a stale file inside the core tree'
[ "$(docker exec "wprism-${PAIR}-wp2-1" cat /var/www/html/wp-content/wprism-core-lifecycle-content-canary)" = operator-owned ] \
  || fail 'same-version reinstall removed operator-owned wp-content'
[ "$(docker exec "wprism-${PAIR}-wp2-1" sha256sum /var/www/html/wp-config.php | awk '{print $1}')" = "$WP_CONFIG_BEFORE" ] \
  || fail 'same-version reinstall changed wp-config.php'
[ "$(docker exec "wprism-${PAIR}-wp2-1" sha256sum /var/www/html/.htaccess | awk '{print $1}')" = "$HTACCESS_BEFORE" ] \
  || fail 'same-version reinstall changed .htaccess'
[ "$(target_fingerprint)" = "$FINGERPRINT_BEFORE" ] \
  || fail 'same-version reinstall changed canonical, runtime, or WPrism ledger state'
zero_apply 'post-reinstall idempotence'
recapture_matches 'post-reinstall product path'
assert_public_post 'post-reinstall target'
pass 'reinstall prunes core-tree residue while preserving wp-content, config, authored state, runtime state, and WPrism ledger authority'

say "exact rollback to $OLDER_VERSION and restore to $CURRENT_VERSION"
replace_core 2 "$OLDER_IMAGE" "$OLDER_VERSION" 'target rollback'
zero_apply 'rollback product path'
recapture_matches 'rollback product path'
assert_public_post 'rolled-back target'
[ "$(target_fingerprint)" = "$FINGERPRINT_BEFORE" ] \
  || fail 'exact rollback changed canonical, runtime, or WPrism ledger state'
replace_core 2 "$CURRENT_IMAGE" "$CURRENT_VERSION" 'target restore'
zero_apply 'restored-current product path'
recapture_matches 'restored-current product path'
assert_public_post 'restored-current target'
[ "$(target_fingerprint)" = "$FINGERPRINT_BEFORE" ] \
  || fail 'restoring the current core changed canonical, runtime, or WPrism ledger state'
pass 'rollback is handled explicitly and the current exact core can be restored without state loss or duplicate publication'

say 'cleanup: destroy own disposable pair'
bash bin/pair.sh destroy "$PAIR"
pass "$PAIR destroyed"

printf '\n\033[1;32m✔ REGRESS_CORE_LIFECYCLE PASSED\033[0m\n'
