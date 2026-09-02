#!/usr/bin/env bash
# Rank Math acceptance beyond canonical byte equality: native model/frontend,
# references, schema and derived state, failure/retry, conflicts, lifecycle,
# deletion/refusal boundaries, duplicate identities, and concurrent apply.
set -euo pipefail

CONF2_PORT="${CONF2_PORT:-8807}"
RANK_MATH_EXPECTED_VERSION="${RANK_MATH_EXPECTED_VERSION:-1.0.277.2}"

observe_rank_math() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Rank Math observation side: $side" ;;
  esac
  file="$repo/.tmp-rank-math-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
global $wpdb;
$post = get_page_by_path('rank-math-article', OBJECT, 'post');
$hub = get_page_by_path('rank-math-hub', OBJECT, 'page');
$category = get_term_by('slug', 'rank-math-primary', 'category');
$secondary = get_term_by('slug', 'rank-math-secondary', 'category');
$tag = get_term_by('slug', 'rank-math-portable', 'post_tag');
$attachment = get_page_by_path('wprism-rank-math-social', OBJECT, 'attachment');
if (!$post instanceof WP_Post || !$hub instanceof WP_Post || !$attachment instanceof WP_Post
    || !$category instanceof WP_Term || !$secondary instanceof WP_Term || !$tag instanceof WP_Term) {
    throw new RuntimeException('Rank Math native content fixture is incomplete');
}
$redirections = $wpdb->get_results(
    "SELECT id,sources,url_to,header_code,hits,status,created,updated,last_accessed "
        . "FROM {$wpdb->prefix}rank_math_redirections ORDER BY id",
    ARRAY_A
);
$redirection = null;
foreach ((array) $redirections as $candidate) {
    $sources = maybe_unserialize($candidate['sources'] ?? '');
    if (is_array($sources) && ($sources[0]['pattern'] ?? null) === 'rank-math-old') {
        $candidate['sources_shape'] = $sources;
        $redirection = $candidate;
        break;
    }
}
$links = $wpdb->get_results($wpdb->prepare(
    "SELECT url,post_id,target_post_id,type FROM {$wpdb->prefix}rank_math_internal_links "
        . "WHERE post_id=%d ORDER BY type,url,target_post_id",
    $post->ID
), ARRAY_A);
$postCounts = $wpdb->get_row($wpdb->prepare(
    "SELECT internal_link_count,external_link_count,incoming_link_count "
        . "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
    $post->ID
), ARRAY_A);
$hubCounts = $wpdb->get_row($wpdb->prepare(
    "SELECT internal_link_count,external_link_count,incoming_link_count "
        . "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
    $hub->ID
), ARRAY_A);
$schema = [];
foreach (['rank_math_internal_links', 'rank_math_internal_meta', 'rank_math_redirections', 'rank_math_redirections_cache'] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $schema[$suffix] = [
        'present' => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table,
        'columns' => array_values(array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM `$table`"))),
    ];
}
$titles = (array) get_option('rank-math-options-titles', []);
$general = (array) get_option('rank-math-options-general', []);
$instant = (array) get_option('rank-math-options-instant-indexing', []);
$sitemap = (array) get_option('rank-math-options-sitemap', []);
echo wp_json_encode([
    'version' => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : null,
    'ids' => [
        'attachment' => $attachment->ID,
        'category' => $category->term_id,
        'hub' => $hub->ID,
        'post' => $post->ID,
        'secondary' => $secondary->term_id,
        'tag' => $tag->term_id,
    ],
    'modules' => array_values((array) get_option('rank_math_modules', [])),
    'setup' => [
        'configured' => get_option('rank_math_is_configured', null),
        'registration_skip' => get_option('rank_math_registration_skip', null),
    ],
    'options' => [
        'breadcrumbs_home_label' => $general['breadcrumbs_home_label'] ?? null,
        'plain_large_bytes' => strlen((string) ($general['wprism_plain_data']['large'] ?? '')),
        'plain_nested' => $general['wprism_plain_data']['nested'] ?? null,
        'homepage_image_id' => (int) ($titles['homepage_facebook_image_id'] ?? 0),
        'local_seo_about_page' => (int) ($titles['local_seo_about_page'] ?? 0),
        'local_seo_contact_page' => (int) ($titles['local_seo_contact_page'] ?? 0),
        'logo_id' => (int) ($titles['knowledgegraph_logo_id'] ?? 0),
        'open_graph_image_id' => (int) ($titles['open_graph_image_id'] ?? 0),
    ],
    'post' => [
        'title' => get_post_meta($post->ID, 'rank_math_title', true),
        'description' => get_post_meta($post->ID, 'rank_math_description', true),
        'canonical' => get_post_meta($post->ID, 'rank_math_canonical_url', true),
        'facebook_image_id' => (int) get_post_meta($post->ID, 'rank_math_facebook_image_id', true),
        'primary_category' => (int) get_post_meta($post->ID, 'rank_math_primary_category', true),
        'processed' => (bool) get_post_meta($post->ID, 'rank_math_internal_links_processed', true),
    ],
    'term' => [
        'description' => get_term_meta($category->term_id, 'rank_math_description', true),
        'facebook_image_id' => (int) get_term_meta($category->term_id, 'rank_math_facebook_image_id', true),
        'title' => get_term_meta($category->term_id, 'rank_math_title', true),
    ],
    'redirection' => $redirection,
    'redirection_count' => count((array) $redirections),
    'redirection_cache_count' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_redirections_cache"
    ),
    'links' => $links,
    'post_counts' => $postCounts,
    'hub_counts' => $hubCounts,
    'schema' => $schema,
    'target_owned' => [
        'instant_key' => $instant['indexnow_api_key'] ?? null,
        'sitemap_posts' => $sitemap['exclude_posts'] ?? null,
        'notifications' => get_option('rank_math_notifications', null),
        'neighbor' => get_option('wprism_rank_math_target_neighbor', null),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-rank-math-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-rank-math-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "$side Rank Math observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

commit_rank_math_source() { # <message>
  local message="$1"
  wp_conf1 wprism capture --repo=/siterepo >/dev/null
  git -C "${CONF_REPO1:-siterepo/conf1}" add -A
  git -C "${CONF_REPO1:-siterepo/conf1}" -c user.name=wprism -c user.email=wprism@example.test commit -qm "$message"
  git -C "${CONF_REPO1:-siterepo/conf1}" push -q origin main
  git -C "${CONF_REPO2:-siterepo/conf2}" pull -q origin main
}

rank_math_request() { # <path>
  local path="$1"
  RANK_MATH_BODY=$(mktemp "${TMPDIR:-/tmp}/wprism-rank-math-body.XXXXXX")
  RANK_MATH_HEADERS=$(mktemp "${TMPDIR:-/tmp}/wprism-rank-math-headers.XXXXXX")
  RANK_MATH_CODE=$(curl --max-time 20 -sS -D "$RANK_MATH_HEADERS" -o "$RANK_MATH_BODY" \
    -w '%{http_code}' "http://localhost:${CONF2_PORT}${path}") \
    || fail "Rank Math request failed before response: $path"
  RANK_MATH_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' \
    "$RANK_MATH_HEADERS" | tail -1)
}

SOURCE=$(observe_rank_math conf1)
TARGET=$(observe_rank_math conf2)
jq -e --arg port "$CONF2_PORT" --arg version "$RANK_MATH_EXPECTED_VERSION" '
  .version == $version and
  (.setup.configured | tostring) == "1" and (.setup.registration_skip | tostring) == "1" and
  (.modules | index("link-counter")) != null and (.modules | index("redirections")) != null and
  .options.breadcrumbs_home_label == "Origin 東京 🚀" and
  .options.plain_large_bytes > 100000 and
  .options.plain_nested == {enabled:true,threshold:0,nullable:null} and
  .options.homepage_image_id == .ids.attachment and .options.logo_id == .ids.attachment and
  .options.open_graph_image_id == .ids.attachment and
  .options.local_seo_about_page == .ids.hub and .options.local_seo_contact_page == .ids.post and
  .post.title == "Portable Rank Math Title 東京 🚀 %sep% %sitename%" and
  .post.description == "Portable Rank Math description 東京 🚀 with | = : delimiters." and
  .post.facebook_image_id == .ids.attachment and .post.primary_category == .ids.category and
  .post.processed == true and
  .term.facebook_image_id == .ids.attachment and
  .term.title == "Portable Category Title 東京 🚀 %sep% %sitename%" and
  .redirection_count == 1 and .redirection.header_code == "302" and
  .redirection.status == "active" and
  .redirection.sources_shape == [{ignore:"",pattern:"rank-math-old",comparison:"exact"}] and
  (.redirection.url_to | startswith("http://localhost:" + $port + "/rank-math-hub/")) and
  (.links | length) == 2 and ([.links[].type] | sort) == ["external","internal"] and
  ([.links[] | select(.type == "internal")][0].target_post_id | tonumber) == .ids.hub and
  (.post_counts.internal_link_count | tonumber) == 1 and
  (.post_counts.external_link_count | tonumber) == 1 and
  (.hub_counts.incoming_link_count | tonumber) == 1 and
  ([.schema[].present] | all) and
  .target_owned.instant_key == "target-indexnow-credential-must-survive" and
  .target_owned.notifications == ["target-runtime-notification-must-survive"] and
  .target_owned.neighbor == "target-neighbor-must-survive"
' <<<"$TARGET" >/dev/null || fail "Rank Math portable/native/derived/runtime state did not converge: $TARGET"

for key in attachment category hub post secondary tag; do
  SOURCE_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$SOURCE")
  TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Rank Math $key identity did not diverge ($SOURCE_ID)"
done
SOURCE_REDIR=$(jq -r '.redirection.id' <<<"$SOURCE")
TARGET_REDIR=$(jq -r '.redirection.id' <<<"$TARGET")
require_fixture_ids SOURCE_REDIR TARGET_REDIR
[ "$SOURCE_REDIR" != "$TARGET_REDIR" ] || fail "Rank Math natural-key redirection did not adopt the target identity ($SOURCE_REDIR)"
[ "$(jq -r '.redirection.hits' <<<"$TARGET")" = 37 ] \
  || fail 'Rank Math authored redirection convergence overwrote the target runtime hit counter'

PROVIDER_RECEIPT="${APPLY_JSON:-}"
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?;
    .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true and
    .after.enabled == true and .after.link_count >= 2 and
    (.after.link_hash | test("^[a-f0-9]{64}$"))) and
  any(.actions[]?;
    .source == "provider:rank-math-state/rebuild_link_state" and .verified == true and
    .after.post_count >= 1 and (.after.meta_hash | test("^[a-f0-9]{64}$")))
' <<<"$PROVIDER_RECEIPT" >/dev/null \
  || fail "Rank Math initial apply omitted full/entity provider proof: ${PROVIDER_RECEIPT:-<missing>}"
grep -Fq 'rank-math-hub' <<<"$(jq -c '.actions' <<<"$PROVIDER_RECEIPT")" \
  && fail 'Rank Math provider receipt leaked an authored URL'
pass 'divergent posts, terms, attachment and redirection identities converge through exact value-free provider receipts while target credentials/runtime state survive'

rank_math_request '/rank-math-article/'
[ "$RANK_MATH_CODE" = 200 ] \
  && grep -Fq 'Portable Rank Math Title' "$RANK_MATH_BODY" \
  && grep -Fq 'Portable Rank Math description' "$RANK_MATH_BODY" \
  && grep -Fq "http://localhost:${CONF2_PORT}/rank-math-canonical/" "$RANK_MATH_BODY" \
  && grep -Fq "http://localhost:${CONF2_PORT}/wp-content/uploads/" "$RANK_MATH_BODY" \
  || fail "Rank Math frontend did not render target-bound title/description/canonical/social image (status=$RANK_MATH_CODE)"
rm -f "$RANK_MATH_BODY" "$RANK_MATH_HEADERS"

HITS_BEFORE=$(jq -r '.redirection.hits' <<<"$TARGET")
rank_math_request '/rank-math-old'
[[ "$RANK_MATH_CODE" =~ ^30[1278]$ ]] \
  && [[ "$RANK_MATH_LOCATION" == "http://localhost:${CONF2_PORT}/rank-math-hub/?from=redirect"* ]] \
  || fail "Rank Math native redirect failed (status=$RANK_MATH_CODE location=${RANK_MATH_LOCATION:-<none>})"
rm -f "$RANK_MATH_BODY" "$RANK_MATH_HEADERS"
AFTER_TRAFFIC=$(observe_rank_math conf2)
[ "$(jq -r '.redirection.hits' <<<"$AFTER_TRAFFIC")" -gt "$HITS_BEFORE" ] \
  || fail 'Rank Math native redirect did not advance target-local traffic telemetry'
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-traffic >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-traffic" \
  || fail 'Rank Math redirect traffic/cache/link projections leaked into canonical state'
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-traffic"
pass 'real frontend SEO and 302 routing consume target-local references while traffic, cache and links remain noncanonical'

ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' \
  <<<"$ZERO_PLAN" >/dev/null || fail "Rank Math zero-change plan retained work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Rank Math zero-change apply reran effects: $ZERO_APPLY"
pass 'zero-change plan/apply is mutation-free and does not rerun either provider action'

if [ "${RANK_MATH_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "Rank Math $RANK_MATH_EXPECTED_VERSION exact boundary passed native, provider, HTTP, runtime-isolation and no-op checks"
  return 0
fi

# Dynamic custom-schema metadata is intentionally outside the contract because
# its shortcode sibling refers to a physical postmeta row id. Capture must
# refuse atomically rather than carrying half a schema feature.
SOURCE_POST=$(jq -r '.ids.post' <<<"$SOURCE")
wp_conf1 post meta update "$SOURCE_POST" rank_math_schema_Article \
  '{"@type":"Article","headline":"unsupported custom schema"}' >/dev/null
SCHEMA_STATE_BEFORE=$(find "${CONF_REPO1:-siterepo/conf1}/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')
SCHEMA_RC=0
SCHEMA_OUT=$(wp_conf1 wprism capture --repo=/siterepo --format=json 2>&1) || SCHEMA_RC=$?
require_wprism_answered 'Rank Math unsupported custom schema capture' json "$SCHEMA_OUT"
[ "$SCHEMA_RC" -ne 0 ] && grep -Fq 'rank_math_schema_Article' <<<"$SCHEMA_OUT" \
  || fail "Rank Math custom schema did not refuse at its exact key: $SCHEMA_OUT"
[ "$(find "${CONF_REPO1:-siterepo/conf1}/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')" = "$SCHEMA_STATE_BEFORE" ] \
  || fail 'Rank Math custom-schema refusal partially published canonical state'
wp_conf1 post meta delete "$SOURCE_POST" rank_math_schema_Article >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-schema-restored >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-schema-restored" \
  || fail 'Rank Math source did not restore after custom-schema refusal'
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-schema-restored"
pass 'custom schema refuses atomically at the exact unsupported physical key'

# The reviewed natural identity is singular. A second target row with the same
# sources must refuse before row order can choose a behavioral winner.
DUPLICATE_ID=$(wp_conf2 eval '
global $wpdb;
$table=$wpdb->prefix."rank_math_redirections";
$row=$wpdb->get_row("SELECT * FROM `$table` ORDER BY id LIMIT 1",ARRAY_A);
unset($row["id"]);
$row["url_to"]=home_url("/duplicate-must-refuse/");
if ($wpdb->insert($table,$row) !== 1) throw new RuntimeException("duplicate fixture insert failed");
echo (int)$wpdb->insert_id;
')
require_fixture_ids DUPLICATE_ID
DUPLICATE_RC=0
DUPLICATE_OUT=$(wp_conf2 wprism plan --repo=/siterepo 2>&1) || DUPLICATE_RC=$?
require_wprism_answered 'Rank Math duplicate natural identity plan' human "$DUPLICATE_OUT"
[ "$DUPLICATE_RC" -ne 0 ] && grep -Eqi 'duplicate|natural identity|rank_math_redirections' <<<"$DUPLICATE_OUT" \
  || fail "Rank Math duplicate natural identity was guessed by row order: $DUPLICATE_OUT"
wp_conf2 db query "DELETE FROM wp_rank_math_redirections WHERE id=$DUPLICATE_ID" >/dev/null
pass 'duplicate redirection identity refuses without choosing a mutable-row winner'

# Unsupported row deletion is a capture-time capability boundary. Restore the
# row through the native writer and prove the prior repository stays exact.
SOURCE_DELETE_REDIR=$(wp_conf1 db query \
  "SELECT id FROM wp_rank_math_redirections ORDER BY id LIMIT 1" --skip-column-names | tr -d '[:space:]')
require_fixture_ids SOURCE_DELETE_REDIR
wp_conf1 eval "RankMath\\Redirections\\DB::delete([(int)$SOURCE_DELETE_REDIR]);" >/dev/null
DELETE_RC=0
DELETE_OUT=$(wp_conf1 wprism capture --repo=/siterepo --format=json 2>&1) || DELETE_RC=$?
require_wprism_answered 'Rank Math unsupported redirection deletion capture' json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "wprism-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "table:rank_math_redirections")
' <<<"$DELETE_OUT" >/dev/null \
  || fail "Rank Math redirection deletion did not refuse at its exact selector: $DELETE_OUT"
wp_conf1 eval '
$redirection=RankMath\Redirections\Redirection::from([
  "sources"=>[["pattern"=>"rank-math-old","comparison"=>"exact","ignore"=>""]],
  "url_to"=>home_url("/rank-math-hub/?from=redirect"),"header_code"=>"302","status"=>"active"
]);
if (!$redirection->save()) throw new RuntimeException("redirection restoration failed");
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-delete-restored >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-delete-restored" \
  || fail 'Rank Math source did not restore after unsupported deletion refusal'
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-delete-restored"
pass 'unsupported redirection deletion publishes no tombstone and restores byte-identically'

# Provider callback authority is injected only on the target. Direct authored
# state commits first; revision advance waits for the verified child receipt,
# and removing only the callback lets the retained intent retry.
wp_conf1 eval '
$post=get_page_by_path("rank-math-article",OBJECT,"post");
$post->post_content=str_replace("external proof","provider recovery proof",$post->post_content);
wp_update_post($post);
' >/dev/null
commit_rank_math_source 'conformance: Rank Math provider recovery intent'
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"rank_math/links/content\", static fn(\$content) => \$content, 10, 2);" > /var/www/html/wp-content/mu-plugins/wprism-rank-math-provider-fault.php'
FAIL_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Rank Math applied revision before provider fault' "$FAIL_REV_BEFORE"
FAILURE_RC=0
FAILURE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAILURE_RC=$?
require_wprism_answered 'Rank Math injected provider callback refusal' human "$FAILURE_OUT"
[ "$FAILURE_RC" -ne 0 ] && grep -Fq "provider 'rank-math-state' capability 'rebuild_link_state' failed" <<<"$FAILURE_OUT" \
  || fail "Rank Math unreviewed callback did not refuse in its provider: $FAILURE_OUT"
TARGET_POST=$(jq -r '.ids.post' <<<"$(observe_rank_math conf2)")
grep -Fq 'provider recovery proof' <<<"$(wp_conf2 post get "$TARGET_POST" --field=post_content)" \
  || fail 'Rank Math provider refusal lost post-commit authored state needed for retry'
[ "$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FAIL_REV_BEFORE" ] \
  || fail 'Rank Math provider refusal advanced applied_revision before verified link effects'
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'Rank Math provider refusal did not retain retry authority'
[ "$(wp_conf2 option get wprism_rank_math_target_neighbor)" = target-neighbor-must-survive ] \
  || fail 'Rank Math provider refusal crossed the target option boundary'
$COMPOSE exec -T --user root wp2 rm -f /var/www/html/wp-content/mu-plugins/wprism-rank-math-provider-fault.php
RETRY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math retry after callback repair' json "$RETRY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_link_state" and
    .verified == true and .after.link_count >= 2)
' <<<"$RETRY" >/dev/null || fail "Rank Math provider retry did not consume retained intent: $RETRY"
pass 'unreviewed callback failure retains post-commit intent/retry authority and converges after repairing only the callback'

# Competing metadata edits are a generic typed conflict. Nothing, including
# derived link state or target credentials, moves until explicit authority.
wp_conf1 post meta update "$SOURCE_POST" rank_math_title 'Repository competing Rank Math title 東京 🚀' >/dev/null
commit_rank_math_source 'conformance: competing Rank Math metadata intent'
TARGET_POST=$(jq -r '.ids.post' <<<"$(observe_rank_math conf2)")
wp_conf2 post meta update "$TARGET_POST" rank_math_title 'Target competing Rank Math title' >/dev/null
CONFLICT_BEFORE=$(observe_rank_math conf2 | shasum -a 256 | awk '{print $1}')
CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math competing metadata plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Rank Math competing metadata did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered 'Rank Math unforced competing metadata apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi conflict <<<"$CONFLICT_OUT" \
  || fail "Rank Math unforced conflict did not refuse: $CONFLICT_OUT"
[ "$(observe_rank_math conf2 | shasum -a 256 | awk '{print $1}')" = "$CONFLICT_BEFORE" ] \
  || fail 'Rank Math unforced conflict partially mutated authored, derived or target-owned state'
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math forced metadata conflict' json "$FORCED"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .plan.conflict > 0 and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_link_state" and .verified == true)
' <<<"$FORCED" >/dev/null || fail "Rank Math forced repository intent did not verify: $FORCED"
[ "$(wp_conf2 post meta get "$TARGET_POST" rank_math_title)" = 'Repository competing Rank Math title 東京 🚀' ] \
  || fail 'Rank Math forced conflict did not converge the native meta value'
pass 'competing SEO metadata refuses atomically and explicit repository authority preserves target boundaries'

# Deactivation plus lost module tables is the lifecycle recovery case that
# requires both ordered settle actions: schema first, then full native rebuild.
wp_conf2 plugin deactivate seo-by-rank-math >/dev/null
wp_conf2 db query '
  DROP TABLE wp_rank_math_internal_links,wp_rank_math_internal_meta,
             wp_rank_math_redirections,wp_rank_math_redirections_cache
' >/dev/null
REDEPLOY=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math deploy after deactivation/schema loss' json "$REDEPLOY"
wp_conf2 plugin is-active seo-by-rank-math >/dev/null \
  || fail 'Rank Math deploy did not reactivate exact plugin code'
LIFECYCLE=$(observe_rank_math conf2)
jq -e '
  ([.schema[].present] | all) and (.links | length) == 2 and
  (.post_counts.internal_link_count | tonumber) == 1 and
  (.post_counts.external_link_count | tonumber) == 1 and
  (.hub_counts.incoming_link_count | tonumber) == 1 and .redirection_count == 0
' <<<"$LIFECYCLE" >/dev/null \
  || fail "Rank Math ordered lifecycle settlement did not rebuild link projections after schema loss: $LIFECYCLE"
RESTORE=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math authored redirection restore after lifecycle recovery' json "$RESTORE"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.create >= 1' <<<"$RESTORE" >/dev/null \
  || fail "Rank Math post-lifecycle authored state did not restore: $RESTORE"
[ "$(wp_conf2 option get wprism_rank_math_target_neighbor)" = target-neighbor-must-survive ] \
  || fail 'Rank Math lifecycle recovery crossed the target option boundary'
pass 'deactivation and total module-schema loss recover in order: activate, create schema, rebuild links, then restore authored rules'

# Two real apply processes contend for one final title. At least one succeeds;
# the other may only succeed after observing no work or refuse at the named lock.
wp_conf1 post meta update "$SOURCE_POST" rank_math_title 'Concurrent Rank Math intent 東京 🚀' >/dev/null
commit_rank_math_source 'conformance: concurrent Rank Math apply intent'
CONCURRENT_A="${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-concurrent-a.log"
CONCURRENT_B="${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-concurrent-b.log"
set +e
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing Rank Math applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" \
      || fail "successful competing Rank Math apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing Rank Math apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
TARGET_FINAL=$(observe_rank_math conf2)
jq -e '
  .post.title == "Concurrent Rank Math intent 東京 🚀" and .post.processed == true and
  (.links | length) == 2 and .target_owned.instant_key == "target-indexnow-credential-must-survive" and
  .target_owned.neighbor == "target-neighbor-must-survive"
' <<<"$TARGET_FINAL" >/dev/null || fail "Rank Math concurrent apply did not converge exact native state: $TARGET_FINAL"

FINAL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Rank Math final zero plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' \
  <<<"$FINAL_PLAN" >/dev/null || fail "Rank Math final plan retained work: $FINAL_PLAN"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-final >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-final" \
  || fail 'Rank Math final state did not recapture byte-identically after failure/conflict/lifecycle/concurrency exercises'
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-final"
pass 'concurrent apply serializes at the promotion lock and the final native/canonical state is exact'
