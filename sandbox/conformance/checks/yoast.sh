#!/usr/bin/env bash
# Exact-artifact Yoast SEO acceptance: all authored references through Yoast's
# public APIs, frontend metadata, divergent native identities, target-runtime
# sovereignty, and the four derived projections rebuilt by provider 2.0.0.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"
YOAST_EXPECTED_VERSION="${YOAST_EXPECTED_VERSION:-28.3}"

observe_yoast() { # <conf1|conf2>
  local side="$1" repo service file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}"; service=cli1 ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}"; service=cli2 ;;
    *) fail "invalid Yoast observation side: $side" ;;
  esac
  file="$repo/.tmp-yoast-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
global $wpdb;
$post = get_page_by_path('conformance-yoast-post', OBJECT, 'post');
$hub = get_page_by_path('conformance-yoast-hub', OBJECT, 'page');
$child = get_page_by_path('conformance-yoast-hub/conformance-yoast-child', OBJECT, 'page');
$primary = get_term_by('slug', 'conformance-primary', 'category');
$secondary = get_term_by('slug', 'conformance-secondary', 'category');
$tag = get_term_by('slug', 'conformance-search-tag', 'post_tag');
if (!$post || !$hub || !$child || !$primary || !$secondary || !$tag) {
    throw new RuntimeException('Yoast native content fixture is incomplete');
}
$primaryApi = new WPSEO_Primary_Term('category', $post->ID);
$primaryId = (int) $primaryApi->get_primary_term();
$primaryTerm = $primaryId > 0 ? get_term($primaryId, 'category') : null;
$tax = WPSEO_Taxonomy_Meta::get_term_meta($primary->term_id, 'category');
$optionIds = [
    'company' => (int) WPSEO_Options::get('company_logo_id'),
    'default' => (int) WPSEO_Options::get('og_default_image_id'),
    'person' => (int) WPSEO_Options::get('person_logo_id'),
];
$llms = [
    'about' => (int) WPSEO_Options::get('about_us_page'),
    'contact' => (int) WPSEO_Options::get('contact_page'),
    'included' => array_map('intval', (array) WPSEO_Options::get('other_included_pages')),
    'mode' => (string) WPSEO_Options::get('llms_txt_selection_mode'),
    'privacy' => (int) WPSEO_Options::get('privacy_policy_page'),
    'shop' => (int) WPSEO_Options::get('shop_page'),
    'terms' => (int) WPSEO_Options::get('terms_page'),
];
$metaKeys = [
    '_yoast_wpseo_bctitle', '_yoast_wpseo_canonical', '_yoast_wpseo_content_score',
    '_yoast_wpseo_estimated-reading-time-minutes', '_yoast_wpseo_focuskw', '_yoast_wpseo_is_cornerstone',
    '_yoast_wpseo_linkdex', '_yoast_wpseo_meta-robots-adv', '_yoast_wpseo_meta-robots-nofollow',
    '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_metadesc', '_yoast_wpseo_opengraph-description',
    '_yoast_wpseo_opengraph-image', '_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_opengraph-title',
    '_yoast_wpseo_primary_category', '_yoast_wpseo_redirect', '_yoast_wpseo_title',
    '_yoast_wpseo_twitter-description', '_yoast_wpseo_twitter-image', '_yoast_wpseo_twitter-title',
];
$meta = [];
foreach ($metaKeys as $key) {
    $meta[$key] = get_post_meta($post->ID, $key, true);
}
$indexable = $wpdb->prefix . 'yoast_indexable';
$hierarchy = $wpdb->prefix . 'yoast_indexable_hierarchy';
$primaryTable = $wpdb->prefix . 'yoast_primary_term';
$links = $wpdb->prefix . 'yoast_seo_links';
$derived = [
    'hierarchy' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$hierarchy`"),
    'indexables' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$indexable`"),
    'invalid_hierarchy' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `$hierarchy` h LEFT JOIN `$indexable` child ON child.id=h.indexable_id " .
        "LEFT JOIN `$indexable` ancestor ON ancestor.id=h.ancestor_id " .
        'WHERE child.id IS NULL OR (h.ancestor_id <> 0 AND ancestor.id IS NULL)'
    ),
    'invalid_links' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `$links` links LEFT JOIN `$indexable` source ON source.id=links.indexable_id " .
        'WHERE links.indexable_id IS NULL OR source.id IS NULL'
    ),
    'invalid_primary' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `$primaryTable` pt LEFT JOIN {$wpdb->posts} p ON p.ID=pt.post_id " .
        "LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=pt.term_id AND tt.taxonomy=pt.taxonomy " .
        'WHERE p.ID IS NULL OR tt.term_taxonomy_id IS NULL'
    ),
    'links' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$links`"),
    'main_link_count' => $wpdb->get_var($wpdb->prepare(
        "SELECT link_count FROM `$indexable` WHERE object_id=%d AND object_type='post' LIMIT 1",
        $post->ID
    )),
    'main_primary' => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `$primaryTable` WHERE post_id=%d AND term_id=%d AND taxonomy='category'",
        $post->ID,
        $primary->term_id
    )),
    'primary_rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$primaryTable`"),
];
$mainOption = (array) get_option('wpseo', []);
echo wp_json_encode([
    'derived' => $derived,
    'disableadvanced_meta' => WPSEO_Options::get('disableadvanced_meta'),
    'home' => home_url('/'),
    'ids' => [
        'cat_a' => (int) $primary->term_id,
        'cat_b' => (int) $secondary->term_id,
        'child' => (int) $child->ID,
        'hub' => (int) $hub->ID,
        'post' => (int) $post->ID,
        'tag' => (int) $tag->term_id,
    ],
    'llms' => $llms,
    'meta' => $meta,
    'option_ids' => $optionIds,
    'option_posts_exist' => array_map(static fn(int $id): bool => $id > 0 && get_post($id) instanceof WP_Post, $optionIds),
    'parent' => (int) $child->post_parent,
    'primary' => [
        'api_id' => $primaryId,
        'api_name' => $primaryTerm && !is_wp_error($primaryTerm) ? $primaryTerm->name : '',
    ],
    'runtime' => [
        'main_neighbor' => $mainOption['duo_target_neighbor'] ?? null,
        'migration' => get_option('yoast_migrations_free'),
        'tracking' => get_option('wpseo_tracking_only'),
        'undeclared_neighbor' => get_option('yoast_target_undeclared_neighbor'),
    ],
    'tax' => $tax,
    'tax_posts_exist' => [
        'og' => !empty($tax['wpseo_opengraph-image-id']) && get_post((int) $tax['wpseo_opengraph-image-id']) instanceof WP_Post,
        'twitter' => !empty($tax['wpseo_twitter-image-id']) && get_post((int) $tax['wpseo_twitter-image-id']) instanceof WP_Post,
    ],
    'version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  out=$($COMPOSE run --rm -T "$service" wp eval-file /siterepo/.tmp-yoast-observe.php)
  rm -f "$file"
  require_observed_nonempty "$side Yoast native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

SOURCE=$(observe_yoast conf1)
TARGET=$(observe_yoast conf2)
SOURCE_IDS=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-yoast-source.json")
TARGET_IDS=$(cat "${CONF_REPO2:-siterepo/conf2}/.tmp-yoast-target.json")

jq -e --arg version "$YOAST_EXPECTED_VERSION" '
  .version == $version and
  .primary.api_name == "Conformance Primary 東京 🚀" and .primary.api_id == .ids.cat_a and
  .parent == .ids.hub and .llms.mode == "manual" and (.llms.included | length) == 2 and
  .disableadvanced_meta == true and
  .option_posts_exist == {"company":true,"default":true,"person":true} and
  .tax_posts_exist == {"og":true,"twitter":true} and
  (.tax["wpseo_desc"] | contains("東京 🚀")) and
  .meta["_yoast_wpseo_bctitle"] == "Breadcrumb 東京 🚀 | %%title%%" and
  (.meta["_yoast_wpseo_canonical"] | contains(.home)) and
  .meta["_yoast_wpseo_focuskw"] == "portable 東京 search" and
  .meta["_yoast_wpseo_is_cornerstone"] == "1" and
  .meta["_yoast_wpseo_meta-robots-adv"] == "noimageindex,nosnippet" and
  .meta["_yoast_wpseo_meta-robots-nofollow"] == "1" and
  .meta["_yoast_wpseo_meta-robots-noindex"] == "0" and
  (.meta["_yoast_wpseo_metadesc"] | contains("東京 🚀")) and
  (.meta["_yoast_wpseo_opengraph-description"] | contains("東京 🚀")) and
  .meta["_yoast_wpseo_opengraph-image-id"] != "" and
  .meta["_yoast_wpseo_primary_category"] == (.ids.cat_a | tostring) and
  (.meta["_yoast_wpseo_redirect"] | contains(.home)) and
  (.meta["_yoast_wpseo_title"] | contains("東京 🚀")) and
  (.meta["_yoast_wpseo_twitter-description"] | contains("東京 🚀")) and
  .runtime.main_neighbor == "target-main-option-preserved" and
  .runtime.undeclared_neighbor == "target-neighbor-preserved" and
  .runtime.tracking.task_list_first_opened_on == 1999999001 and
  .runtime.migration.error.message == "target-runtime-marker" and
  .meta["_yoast_wpseo_content_score"] == "target-derived-17" and
  .meta["_yoast_wpseo_estimated-reading-time-minutes"] == "99" and
  .meta["_yoast_wpseo_linkdex"] == "target-derived-19"
' <<<"$TARGET" >/dev/null || fail "Yoast authored/runtime native state did not converge: $TARGET"

for key in post hub child cat_a cat_b tag; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_IDS")
  TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Yoast source/target $key identity did not diverge ($SOURCE_ID)"
  [ "$TARGET_ID" = "$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_IDS")" ] \
    || fail "Yoast apply replaced rather than adopted hostile target $key"
done

jq -e '
  .llms.about > 0 and .llms.contact > 0 and .llms.terms > 0 and .llms.privacy > 0 and .llms.shop > 0 and
  (.llms.included | length) == 2 and
  ([.llms.about,.llms.contact,.llms.terms,.llms.privacy,.llms.shop] + .llms.included | unique | length) == 7
' <<<"$TARGET" >/dev/null || fail "Yoast llms.txt references were not independently rebound: $TARGET"

jq -e '
  .derived.indexables > 0 and .derived.hierarchy > 0 and .derived.primary_rows > 0 and .derived.links > 0 and
  .derived.main_primary == 1 and .derived.main_link_count != null and
  .derived.invalid_hierarchy == 0 and .derived.invalid_primary == 0 and .derived.invalid_links == 0
' <<<"$TARGET" >/dev/null || fail "Yoast provider did not repair all four relational projections: $TARGET"

PROVIDER_RECEIPT="${APPLY_JSON:-}"
if [ -z "$PROVIDER_RECEIPT" ] && [ -n "${VMATRIX_APPLY_LOG:-}" ] && [ -f "$VMATRIX_APPLY_LOG" ]; then
  PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
fi
grep -Fq 'yoast-index@2.0.0' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt did not identify Yoast provider 2.0.0: ${PROVIDER_RECEIPT:-<missing>}"
pass 'Yoast public APIs consume every authored ref at divergent IDs while derived scores and runtime options stay target-owned'
pass 'Yoast provider 2.0.0 rebuilt indexables, hierarchy, primary terms, and SEO links with relational readback'

FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/conformance-yoast-post/") \
  || fail 'conf2 conformance-yoast-post did not return 200'
require_observed_nonempty 'conf2 Yoast rendered response' "$FRONT"
[ "${#FRONT}" -ge 1000 ] || fail "conf2 Yoast response was suspiciously short (${#FRONT} bytes)"
grep -qiE 'fatal error|uncaught' <<<"$FRONT" && fail 'conf2 Yoast response contains a fatal marker'
grep -Fq 'Conformance Yoast Post 東京 🚀' <<<"$FRONT" || fail 'rendered title did not consume authored Yoast title'
grep -Fq 'Portable meta description 東京 🚀' <<<"$FRONT" || fail 'rendered description did not consume authored Yoast description'
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$FRONT" && fail 'conf2 Yoast render leaked the source host'
pass 'frontend metadata renders UTF-8 authored values and target-local URLs without fatal output'

ZERO_PLAN=$($COMPOSE run --rm -T cli2 wp duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "Yoast retry retained work: $ZERO_PLAN"
ZERO_APPLY=$($COMPOSE run --rm -T cli2 wp duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Yoast no-op apply was not clean and idempotent: $ZERO_APPLY"
pass 'Yoast zero-change plan/apply is mutation-free and does not rerun the provider'

if [ "${YOAST_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "Yoast $YOAST_EXPECTED_VERSION exact boundary consumed the full portable fixture"
  return 0 2>/dev/null || exit 0
fi
