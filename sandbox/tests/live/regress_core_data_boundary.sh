#!/usr/bin/env bash
# Exact-host core adapter proof for difficult values and fail-closed schema
# boundaries. The happy path crosses every core entity family with long UTF-8,
# delimiter/serialization-shaped text, empty/null values, environment URLs,
# divergent ids, and zero/negative/overflow-shaped references. Negative cases
# prove secret, malformed serialization, and source/target schema drift cannot
# publish a partial tree or mutate the target.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. lib/pair_db.sh
pair_db_select_engine

PAIR="${CORE_DATA_BOUNDARY_PAIR:-coreboundary}"
PORT1="${CORE_DATA_BOUNDARY_PORT1:-8994}"
PORT2="${CORE_DATA_BOUNDARY_PORT2:-8995}"
# The boundary proof runs against ONE exact core per invocation. The default is
# the newest exercised core (platform.json last_verified); the matrix in
# regress_core_scope_platform.sh names the other exercised series, and this
# suite is re-run per series by overriding both values together with the
# exact digest of that series' proof image (never a floating tag).
WORDPRESS_VERSION="${CORE_DATA_BOUNDARY_WORDPRESS:-7.1}"
WORDPRESS_IMAGE="${CORE_DATA_BOUNDARY_IMAGE:-wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf}"

[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CORE_DATA_BOUNDARY_PAIR '$PAIR'"
[ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" ] \
  || fail 'WPRISM_EXPECTED_SOURCE_SHA is required: core data-boundary evidence must bind the exact clean candidate'
command -v jq >/dev/null || fail 'jq is required'
command -v curl >/dev/null || fail 'curl is required'

export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_WP_IMAGE="$WORDPRESS_IMAGE" WPRISM_ARTIFACT_OFFLINE=1
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.http.yml -f pair.artifacts.yml -f pair.wordpress-offline.yml)
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }

fixture_code() {
  case "$1" in
    seed-source) printf '%s' 'wprism_boundary_seed_source();' ;;
    seed-target) printf '%s' 'wprism_boundary_seed_target();' ;;
    observe) printf '%s' 'echo wp_json_encode(wprism_boundary_observe(false));' ;;
    observe-updated) printf '%s' 'echo wp_json_encode(wprism_boundary_observe(true));' ;;
    secret-meta) printf '%s' 'wprism_boundary_secret_meta();' ;;
    restore-meta) printf '%s' 'wprism_boundary_restore_meta();' ;;
    corrupt-theme) printf '%s' 'wprism_boundary_corrupt_theme();' ;;
    restore-theme) printf '%s' 'wprism_boundary_restore_theme();' ;;
    update-source) printf '%s' 'wprism_boundary_update_source();' ;;
    block-schema) printf '%s' 'echo wp_json_encode(wprism_boundary_core_block_schema());' ;;
    observe-oembed) printf '%s' 'echo wp_json_encode(wprism_boundary_oembed_cache_observation());' ;;
    legacy-id) printf '%s' 'wprism_boundary_legacy_widget("id");' ;;
    legacy-instance) printf '%s' 'wprism_boundary_legacy_widget("instance");' ;;
    remove-legacy) printf '%s' 'wprism_boundary_remove_legacy_widget();' ;;
    *) fail "unknown core data-boundary fixture mode '$1'" ;;
  esac
}
fixture1() { wp1 --require=/siterepo/core_data_boundary.php eval "$(fixture_code "$1")"; }
fixture2() { wp2 --require=/siterepo/core_data_boundary.php eval "$(fixture_code "$1")"; }

clear_tree() {
  [ ! -e "$1" ] || find "$1" -depth -delete
}

sync_state() {
  clear_tree "$R2/state"
  clear_tree "$R2/media"
  cp -R "$R1/state" "$R2/state"
  [ ! -d "$R1/media" ] || cp -R "$R1/media" "$R2/media"
}

tree_fingerprint() {
  find "$1" -type f -print0 \
    | LC_ALL=C sort -z \
    | xargs -0 shasum -a 256 \
    | shasum -a 256 \
    | awk '{print $1}'
}

capture_source() { # <label>
  local label="$1" output warnings
  output=$(wp1 wprism capture --repo=/siterepo 2>&1) \
    || fail "$label: source capture failed: $output"
  warnings=$(grep -c '^Warning:' <<<"$output" || true)
  [ "$warnings" = 3 ] \
    && grep -Fq "post 'core-boundary' body looks like it contains a stripe key" <<<"$output" \
    && grep -Fq 'option sticky_posts: unmanaged post id -7 dropped' <<<"$output" \
    && grep -Fq 'option sticky_posts: unmanaged post id 999999999999999999999999999999999999 dropped' <<<"$output" \
    || fail "$label: expected body-secret plus negative/overflow ref warnings exactly once each: $output"
}

changed_apply() { # <label>
  local label="$1" raw output
  raw=$(wp2 wprism apply --repo=/siterepo --default-author=admin --format=json 2>&1) \
    || fail "$label: target apply failed: $raw"
  ! grep -Fq 'Warning:' <<<"$raw" \
    || fail "$label: machine apply leaked an unstructured warning: $raw"
  output=$(awk 'NF { line=$0 } END { print line }' <<<"$raw")
  jq -e '.canary == "clean"' <<<"$output" >/dev/null \
    || fail "$label: target apply lacked a clean machine result: $raw"
}

recapture_matches() { # <label>
  local label="$1" output warnings
  clear_tree "$R2/state-check"
  output=$(wp2 wprism capture --repo=/siterepo --out=/siterepo/state-check 2>&1) \
    || fail "$label: target recapture failed: $output"
  warnings=$(grep -c '^Warning:' <<<"$output" || true)
  [ "$warnings" = 1 ] \
    && grep -Fq "post 'core-boundary' body looks like it contains a stripe key" <<<"$output" \
    || fail "$label: target recapture did not return exactly its asserted body-secret review warning: $output"
  diff -r "$R2/state" "$R2/state-check" >/dev/null \
    || fail "$label: target recapture differs from the source canonical tree"
  pass "$label recaptures byte-identically with exactly one reviewed body warning"
}

assert_observation() { # <json> <label>
  local observation="$1" label="$2"
  jq -e '
    ([paths(scalars) as $p | getpath($p) | select(type == "boolean" and . == false)] | length) == 0 and
    .options.large_title_bytes > 10000 and
    .post.body_bytes > 30000 and .post.excerpt_bytes > 9000 and .post.meta_bytes > 25000 and
    .blocks.bytes > 1000 and
    .term.description_bytes > 30000
  ' <<<"$observation" >/dev/null \
    || fail "$label: a native value, reference, URL binding, runtime boundary, or size premise failed: $observation"
}

assert_capture_refusal() { # <label> <message-fragment> [forbidden-fragment]
  local label="$1" fragment="$2" forbidden="${3:-}" before after output rc=0
  before=$(tree_fingerprint "$R1/state")
  output=$(wp1 wprism capture --repo=/siterepo 2>&1) || rc=$?
  [ "$rc" -ne 0 ] && grep -Fq "$fragment" <<<"$output" \
    || fail "$label did not refuse with '$fragment': $output"
  if [ -n "$forbidden" ]; then
    ! grep -Fq "$forbidden" <<<"$output" \
      || fail "$label exposed forbidden value bytes: $output"
  fi
  after=$(tree_fingerprint "$R1/state")
  [ "$before" = "$after" ] \
    || fail "$label changed the previously published source tree"
  [ ! -e "$R1/state.capture-staging" ] && [ ! -e "$R1/state.capture-backup" ] \
    || fail "$label leaked capture publication artifacts"
  pass "$label refused non-zero without changing the published tree"
}

say 'exact image and candidate preflight'
docker image inspect "$WORDPRESS_IMAGE" >/dev/null 2>&1 \
  || fail "exact core image is absent locally (offline proof will not pull): $WORDPRESS_IMAGE"
[ "$(docker run --rm --entrypoint php "$WORDPRESS_IMAGE" -r 'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;')" = "$WORDPRESS_VERSION" ] \
  || fail "$WORDPRESS_IMAGE does not contain WordPress $WORDPRESS_VERSION"
# The core under test must be one of the exact patches platform.json names as
# a per-series proof (the values of compatibility.wordpress.verified): this
# suite IS that proof for the data boundary, so running it on a core the claim
# does not name would prove nothing about the claim.
jq -e --arg version "$WORDPRESS_VERSION" \
  '[.platform.compatibility.wordpress.verified | to_entries[] | .value] | index($version) != null' \
  ../platform/adapter-library/capabilities/platform.json >/dev/null \
  || fail "platform.json names no exercised series whose proof is WordPress $WORDPRESS_VERSION"
pass 'core data-boundary proof uses the exact reviewed WordPress image and platform declaration'

say 'fresh exact pair and hostile source/target seeds'
bash bin/pair.sh list
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --http --artifacts --wordpress-offline
for repo_dir in "$R1" "$R2"; do
  cp site-repo.gitignore.template "$repo_dir/.gitignore"
  cp tests/fixtures/core_lifecycle_site.wprism.json "$repo_dir/site.wprism.json"
  cp tests/fixtures/core_data_boundary.php "$repo_dir/core_data_boundary.php"
done
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
BLOCK_SCHEMA=$(fixture1 block-schema | awk 'NF { line=$0 } END { print line }')
# The registered core block inventory is PER SERIES, not one list across the
# whole claim: WordPress 7.1 adds six core blocks and one of them,
# core/playlist-track, carries an attachment id plus two absolute media URLs
# (wp-includes/blocks/playlist-track/block.json; the id is required — the
# renderer returns '' without it, playlist-track.php:20-22). Flattening the
# two shapes into one list would either fail on 6.9/7.0 or stop asserting the
# 7.1 rows, so each exercised series states its own expectation and an
# unhandled series refuses rather than defaulting to a neighbour's list.
case "$WORDPRESS_VERSION" in
  6.9*|7.0*)
    EXPECTED_ID_LIKE='[
      "core/audio.id", "core/avatar.userId", "core/block.ref", "core/cover.id",
      "core/file.fileId", "core/file.id", "core/gallery.ids", "core/image.id",
      "core/legacy-widget.id", "core/media-text.mediaId", "core/navigation-link.id",
      "core/navigation-submenu.id", "core/navigation.ref", "core/page-list-item.id",
      "core/page-list.parentPageID", "core/query.queryId", "core/video.id"
    ]'
    EXPECTED_URL_LIKE='[
      "core/audio.src", "core/button.url", "core/cover.poster", "core/cover.url",
      "core/embed.url", "core/file.href", "core/file.textLinkHref", "core/image.href",
      "core/image.url", "core/media-text.href", "core/media-text.mediaUrl",
      "core/navigation-link.url", "core/navigation-submenu.url", "core/page-list-item.link",
      "core/rss.feedURL", "core/social-link.url", "core/video.poster", "core/video.src",
      "core/video.tracks"
    ]'
    ;;
  7.1*)
    EXPECTED_ID_LIKE='[
      "core/audio.id", "core/avatar.userId", "core/block.ref", "core/cover.id",
      "core/file.fileId", "core/file.id", "core/gallery.ids", "core/image.id",
      "core/legacy-widget.id", "core/media-text.mediaId", "core/navigation-link.id",
      "core/navigation-submenu.id", "core/navigation.ref", "core/page-list-item.id",
      "core/page-list.parentPageID", "core/playlist-track.id", "core/query.queryId",
      "core/video.id"
    ]'
    EXPECTED_URL_LIKE='[
      "core/audio.src", "core/button.url", "core/cover.poster", "core/cover.url",
      "core/embed.url", "core/file.href", "core/file.textLinkHref", "core/image.href",
      "core/image.url", "core/media-text.href", "core/media-text.mediaUrl",
      "core/navigation-link.url", "core/navigation-submenu.url", "core/page-list-item.link",
      "core/playlist-track.image", "core/playlist-track.src",
      "core/rss.feedURL", "core/social-link.url", "core/video.poster", "core/video.src",
      "core/video.tracks"
    ]'
    ;;
  *)
    fail "no reviewed core block attribute inventory for WordPress $WORDPRESS_VERSION"
    ;;
esac
jq -e --argjson expected_id "$EXPECTED_ID_LIKE" --argjson expected_url "$EXPECTED_URL_LIKE" \
  '.id_like == $expected_id and .url_like == $expected_url' <<<"$BLOCK_SCHEMA" >/dev/null \
  || fail "WordPress $WORDPRESS_VERSION core block attribute inventory drifted: $BLOCK_SCHEMA"
pass 'exact registered core block id and URL attribute inventory matches the reviewed manifest boundary'
SOURCE_IDS=$(fixture1 seed-source | awk 'NF { line=$0 } END { print line }')
TARGET_SEED=$(fixture2 seed-target | awk 'NF { line=$0 } END { print line }')
jq -e '.attachment > 0 and .post > 0 and .page > 0 and .user > 0 and .term > 0 and .menu > 0 and .menu_item > 0 and .custom_css > 0' \
  <<<"$SOURCE_IDS" >/dev/null || fail "source boundary seed returned malformed identities: $SOURCE_IDS"
jq -e '.hostile_front > 0 and .user > 0' <<<"$TARGET_SEED" >/dev/null \
  || fail "target boundary seed returned malformed identities: $TARGET_SEED"
SOURCE_OEMBED=$(fixture1 observe-oembed | awk 'NF { line=$0 } END { print line }')
jq -e '.count == 0 and .cache_count == 0 and .time_count == 0 and .unknown_count == 0 and .all_exact' \
  <<<"$SOURCE_OEMBED" >/dev/null \
  || fail "source unexpectedly generated an oEmbed cache before capture: $SOURCE_OEMBED"
pass 'real WordPress APIs persisted every core entity family plus hostile target option/runtime state'

say 'long UTF-8, delimiters, environment URLs, null/empty, and hostile reference values'
capture_source 'initial difficult-value capture'
sync_state
changed_apply 'initial difficult-value apply'
TARGET_OBS=$(fixture2 observe | awk 'NF { line=$0 } END { print line }')
assert_observation "$TARGET_OBS" 'initial target observation'
for kind in post page attachment term menu menu_item; do
  [ "$(jq -r --arg key "$kind" '.[$key]' <<<"$SOURCE_IDS")" != "$(jq -r --arg key "$kind" '.ids[$key]' <<<"$TARGET_OBS")" ] \
    || fail "$kind source/target ids did not deliberately diverge"
done
[ "$(jq -r '.user' <<<"$SOURCE_IDS")" != "$(jq -r '.ids.user' <<<"$TARGET_OBS")" ] \
  || fail 'source/target user ids did not deliberately diverge'
[ "$(wp2 option get page_on_front)" = 0 ] \
  && [ "$(wp2 option get page_for_posts)" = 0 ] \
  && [ "$(wp2 option get wp_page_for_privacy_policy)" = 0 ] \
  || fail 'scalar zero reference intent did not clear hostile target page ids'
[ "$(wp2 option get _wp_session_core_boundary_target)" = target-runtime-survives ] \
  || fail 'target-owned runtime option was overwritten'
TARGET_POST_URL=$(wp2 eval '$p=get_page_by_path("core-boundary", OBJECT, "post"); echo $p ? get_permalink($p) : "";')
[ -n "$TARGET_POST_URL" ] || fail 'target difficult-value post has no permalink'
PUBLIC_BODY=$(curl -fsSL "$TARGET_POST_URL") \
  || fail 'target difficult-value post did not render through HTTP'
grep -Fq 'CORE-BODY' <<<"$PUBLIC_BODY" && grep -Fq 'বাংলা' <<<"$PUBLIC_BODY" \
  || fail 'public response did not render the long UTF-8 body'
TARGET_BLOCK_URL=$(wp2 eval '$p=get_page_by_path("core-block-boundary", OBJECT, "page"); echo $p ? get_permalink($p) : "";')
[ -n "$TARGET_BLOCK_URL" ] || fail 'target block-catalog page has no permalink'
BLOCK_PUBLIC_BODY=$(curl -fsSL "$TARGET_BLOCK_URL") \
  || fail 'target block-catalog page did not render through HTTP'
grep -Fq 'Core block attribute boundary' <<<"$BLOCK_PUBLIC_BODY" \
  || fail 'public block-catalog response did not render the intended page'
TARGET_OEMBED=$(fixture2 observe-oembed | awk 'NF { line=$0 } END { print line }')
jq -e '
  .count == 1 and .cache_count == 1 and .time_count == 0 and .unknown_count == 1 and
  .all_exact and (.keys[0] | test("^_oembed_[a-f0-9]{32}$"))
' <<<"$TARGET_OEMBED" >/dev/null \
  || fail "frontend render did not generate the exact WordPress oEmbed failure-cache shape: $TARGET_OEMBED"
pass 'frontend render generated target-only oEmbed post-meta cache bytes from the portable embed block'
HOSTILE_FRONT=$(jq -r '.hostile_front' <<<"$TARGET_SEED")
wp2 post delete "$HOSTILE_FRONT" --force >/dev/null
UNCATEGORIZED=$(wp2 term list category --slug=uncategorized --field=term_id 2>/dev/null || true)
[ -z "$UNCATEGORIZED" ] || wp2 term delete category "$UNCATEGORIZED" >/dev/null
recapture_matches 'complete difficult-value product path'
pass 'all core values converge through native APIs, public rendering, divergent ids, URL rebinding, and target-runtime preservation'

say 'secret-shaped authored metadata refuses with redacted evidence'
fixture1 secret-meta >/dev/null
assert_capture_refusal \
  'authored post-meta secret' \
  "secret guard tripped — post_meta 'origin'" \
  'sk_live_WPRISMBOUNDARYSECRET9988776655'
fixture1 restore-meta >/dev/null

say 'malformed serialized theme state refuses before publication'
fixture1 corrupt-theme >/dev/null
assert_capture_refusal 'malformed theme-mod serialization' 'trailing or noncanonical PHP-serialized data'
fixture1 restore-theme >/dev/null

say 'environment-bound legacy widget block forms refuse before publication'
fixture1 legacy-id >/dev/null
assert_capture_refusal 'stored legacy widget reference' "attribute 'id' is explicitly unsupported"
fixture1 remove-legacy >/dev/null
fixture1 legacy-instance >/dev/null
assert_capture_refusal 'salted embedded legacy widget instance' "attribute 'idBase' is explicitly unsupported"
fixture1 remove-legacy >/dev/null

say 'source schema drift refuses before lossy SELECT-star projection'
SCHEMA_BASE=$(tree_fingerprint "$R1/state")
wp1 db query 'ALTER TABLE wp_posts CHANGE post_excerpt post_excerpt_hold text NOT NULL' >/dev/null
SCHEMA_RC=0
SCHEMA_OUT=$(wp1 wprism capture --repo=/siterepo 2>&1) || SCHEMA_RC=$?
[ "$SCHEMA_RC" -ne 0 ] \
  && grep -Fq 'core capture schema drift' <<<"$SCHEMA_OUT" \
  && grep -Fq 'wp_posts.post_excerpt' <<<"$SCHEMA_OUT" \
  && ! grep -Fq 'Undefined property' <<<"$SCHEMA_OUT" \
  || fail "renamed source post_excerpt did not fail at the schema boundary: $SCHEMA_OUT"
[ "$(tree_fingerprint "$R1/state")" = "$SCHEMA_BASE" ] \
  || fail 'source schema refusal changed the previously published tree'
wp1 db query 'ALTER TABLE wp_posts CHANGE post_excerpt_hold post_excerpt text NOT NULL' >/dev/null
pass 'source schema drift is a typed pre-projection refusal with no lossy publication'

say 'target schema drift refuses a real pending apply before mutation'
fixture1 update-source >/dev/null
capture_source 'updated difficult-value capture'
sync_state
TARGET_TITLE_BEFORE=$(wp2 post get "$(jq -r '.ids.post' <<<"$TARGET_OBS")" --field=title)
TARGET_REVISION_BEFORE=$(wp2 eval 'echo (string) \WPrism\Ledger::kv_get("applied_revision");')
wp2 db query 'ALTER TABLE wp_posts CHANGE post_excerpt post_excerpt_hold text NOT NULL' >/dev/null
APPLY_SCHEMA_RC=0
APPLY_SCHEMA_OUT=$(wp2 wprism apply --repo=/siterepo --default-author=admin --format=json 2>&1) || APPLY_SCHEMA_RC=$?
APPLY_SCHEMA_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$APPLY_SCHEMA_OUT")
[ "$APPLY_SCHEMA_RC" -ne 0 ] && jq -e '
  .error == "capture_schema_unsupported" and
  .reason_code == "capture_schema_unsupported" and
  .diagnostics[0].code == "core_schema_drift" and
  .diagnostics[0].missing == [{"table":"posts","column":"post_excerpt"}]
' <<<"$APPLY_SCHEMA_JSON" >/dev/null \
  || fail "target schema drift did not refuse with its exact logical column: $APPLY_SCHEMA_OUT"
[ "$(wp2 post get "$(jq -r '.ids.post' <<<"$TARGET_OBS")" --field=title)" = "$TARGET_TITLE_BEFORE" ] \
  || fail 'schema-refused apply partially changed the target post'
[ "$(wp2 eval 'echo (string) \WPrism\Ledger::kv_get("applied_revision");')" = "$TARGET_REVISION_BEFORE" ] \
  || fail 'schema-refused apply advanced target ledger authority'
wp2 db query 'ALTER TABLE wp_posts CHANGE post_excerpt_hold post_excerpt text NOT NULL' >/dev/null
changed_apply 'post-schema-repair retry'
UPDATED_OBS=$(fixture2 observe-updated | awk 'NF { line=$0 } END { print line }')
assert_observation "$UPDATED_OBS" 'post-schema-repair target observation'
recapture_matches 'post-schema-repair retry'
pass 'target schema repair enables one exact retry; no partial mutation or stale ledger was hidden'

printf '\n\033[1;32m✔ REGRESS_CORE_DATA_BOUNDARY PASSED\033[0m\n'

say 'cleanup: destroy own disposable pair'
bash bin/pair.sh destroy "$PAIR"
pass "$PAIR destroyed"
