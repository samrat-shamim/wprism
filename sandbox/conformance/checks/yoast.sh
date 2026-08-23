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
        'main_excluded' => $mainOption['enable_admin_bar_menu'] ?? null,
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
  .home as $home |
  .version == $version and
  .primary.api_name == "Conformance Primary 東京 🚀" and .primary.api_id == .ids.cat_a and
  .parent == .ids.hub and .llms.mode == "manual" and (.llms.included | length) == 2 and
  .disableadvanced_meta == true and
  .option_posts_exist == {"company":true,"default":true,"person":true} and
  .tax_posts_exist == {"og":true,"twitter":true} and
  (.tax["wpseo_desc"] | contains("東京 🚀")) and
  .meta["_yoast_wpseo_bctitle"] == "Breadcrumb 東京 🚀 | %%title%%" and
  (.meta["_yoast_wpseo_canonical"] | contains($home)) and
  .meta["_yoast_wpseo_focuskw"] == "portable 東京 search" and
  .meta["_yoast_wpseo_is_cornerstone"] == "1" and
  .meta["_yoast_wpseo_meta-robots-adv"] == "noimageindex,nosnippet" and
  .meta["_yoast_wpseo_meta-robots-nofollow"] == "1" and
  .meta["_yoast_wpseo_meta-robots-noindex"] == "" and
  (.meta["_yoast_wpseo_metadesc"] | contains("東京 🚀")) and
  (.meta["_yoast_wpseo_opengraph-description"] | contains("東京 🚀")) and
  .meta["_yoast_wpseo_opengraph-image-id"] != "" and
  .meta["_yoast_wpseo_primary_category"] == (.ids.cat_a | tostring) and
  (.meta["_yoast_wpseo_redirect"] | contains($home)) and
  (.meta["_yoast_wpseo_title"] | contains("東京 🚀")) and
  (.meta["_yoast_wpseo_twitter-description"] | contains("東京 🚀")) and
  .runtime.main_excluded == false and
  .runtime.undeclared_neighbor == "target-neighbor-preserved" and
  (.runtime.tracking.task_list_first_opened_on | tonumber) == 1999999001 and
  .runtime.migration.error.message == "target-runtime-marker" and
  .meta["_yoast_wpseo_content_score"] == "target-derived-17" and
  .meta["_yoast_wpseo_estimated-reading-time-minutes"] == "99" and
  .meta["_yoast_wpseo_linkdex"] == ""
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

# The authored redirect is itself plugin-visible behavior. Prove it first,
# then remove it for one controlled render so title/description output can be
# observed; restore the exact target-local URL before any subsequent capture.
REDIRECT_HEADERS=$(mktemp "${TMPDIR:-/tmp}/duo-yoast-redirect.XXXXXX")
REDIRECT_CODE=$(curl -sS -D "$REDIRECT_HEADERS" -o /dev/null -w '%{http_code}' \
  "http://localhost:${CONF2_PORT}/conformance-yoast-post/")
REDIRECT_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$REDIRECT_HEADERS" | tail -1)
rm -f "$REDIRECT_HEADERS"
[[ "$REDIRECT_CODE" =~ ^30[12378]$ ]] && [[ "$REDIRECT_LOCATION" == "http://localhost:${CONF2_PORT}/"*"conformance-yoast-child/"* ]] \
  || fail "Yoast authored redirect did not drive the frontend (status=$REDIRECT_CODE location=${REDIRECT_LOCATION:-<none>})"
$COMPOSE run --rm -T cli2 wp eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  if (!$post) throw new RuntimeException("Yoast render probe post missing");
  delete_post_meta($post->ID,"_yoast_wpseo_redirect");
' >/dev/null
FRONT_RC=0
FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/conformance-yoast-post/") || FRONT_RC=$?
$COMPOSE run --rm -T cli2 wp eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  if (!$post) throw new RuntimeException("Yoast render probe post missing during restore");
  update_post_meta($post->ID,"_yoast_wpseo_redirect",home_url("/conformance-yoast-child/?from=seo"));
' >/dev/null
[ "$FRONT_RC" -eq 0 ] || fail 'conf2 conformance-yoast-post did not return 200 with its redirect temporarily isolated'
require_observed_nonempty 'conf2 Yoast rendered response' "$FRONT"
[ "${#FRONT}" -ge 1000 ] || fail "conf2 Yoast response was suspiciously short (${#FRONT} bytes)"
grep -qiE 'fatal error|uncaught' <<<"$FRONT" && fail 'conf2 Yoast response contains a fatal marker'
grep -Fq 'Conformance Yoast Post 東京 🚀' <<<"$FRONT" || fail 'rendered title did not consume authored Yoast title'
grep -Fq 'Portable meta description 東京 🚀' <<<"$FRONT" || fail 'rendered description did not consume authored Yoast description'
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$FRONT" && fail 'conf2 Yoast render leaked the source host'
pass 'frontend metadata renders UTF-8 authored values and target-local URLs without fatal output'
pass 'Yoast authored redirect executes and the controlled render probe restores it exactly'

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

commit_yoast_source() { # <message>
  wp_conf1 duo capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

yoast_target_hash() {
  wp_conf2 eval '
    global $wpdb;
    $rows = [
      "posts" => $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"post\",\"page\",\"attachment\") ORDER BY ID", ARRAY_A),
      "meta" => $wpdb->get_results("SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN (\"post\",\"page\",\"attachment\") ORDER BY pm.meta_id", ARRAY_A),
      "options" => $wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (\"wpseo\",\"wpseo_llmstxt\",\"wpseo_social\",\"wpseo_taxonomy_meta\",\"wpseo_titles\",\"wpseo_tracking_only\",\"yoast_migrations_free\",\"yoast_target_undeclared_neighbor\") ORDER BY option_name", ARRAY_A),
      "terms" => $wpdb->get_results("SELECT t.*,tt.taxonomy,tt.description,tt.parent,tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id ORDER BY t.term_id,tt.term_taxonomy_id", ARRAY_A),
      "indexable" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}yoast_indexable ORDER BY id", ARRAY_A),
      "hierarchy" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}yoast_indexable_hierarchy ORDER BY indexable_id,ancestor_id,depth", ARRAY_A),
      "primary" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}yoast_primary_term ORDER BY id", ARRAY_A),
      "links" => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}yoast_seo_links ORDER BY id", ARRAY_A),
    ];
    echo hash("sha256", wp_json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  '
}

yoast_derived_hash() {
  wp_conf2 eval '
    global $wpdb; $rows=[];
    foreach (["yoast_indexable","yoast_indexable_hierarchy","yoast_primary_term","yoast_seo_links"] as $suffix) {
      $table=$wpdb->prefix.$suffix; $rows[$suffix]=$wpdb->get_results("SELECT * FROM `$table` ORDER BY 1",ARRAY_A);
    }
    echo hash("sha256",wp_json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

install_yoast_index_fixture() { # <wp1|wp2>
  $COMPOSE exec -T --user root "$1" sh -c \
    'printf "%s\n" "<?php" "add_filter(\"Yoast\\\\WP\\\\SEO\\\\should_index_indexables\", \"__return_true\", 999);" > /var/www/html/wp-content/mu-plugins/duo-yoast-index-fixture.php'
}

# Malformed structured options and credential-shaped authored metadata must
# refuse capture without changing the committed canonical state or exposing
# the credential. Restore through the exact plugin APIs before continuing.
CAPTURE_BASELINE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
wp_conf1 eval '
  global $wpdb;
  $wpdb->update($wpdb->options,["option_value"=>maybe_serialize("malformed-taxonomy-shape")],["option_name"=>"wpseo_taxonomy_meta"]);
  wp_cache_delete("wpseo_taxonomy_meta","options");
' >/dev/null
MALFORMED_RC=0
MALFORMED_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || MALFORMED_RC=$?
require_duo_answered 'Yoast malformed taxonomy-option capture' human "$MALFORMED_OUT"
[ "$MALFORMED_RC" -ne 0 ] && grep -Eqi 'structured|array|wpseo_taxonomy_meta|container' <<<"$MALFORMED_OUT" \
  || fail "Yoast malformed taxonomy option did not refuse: $MALFORMED_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Yoast malformed option refusal partially published canonical state'
wp_conf1 eval '
  $ids=json_decode(file_get_contents("/siterepo/.tmp-yoast-source.json"),true,512,JSON_THROW_ON_ERROR);
  delete_option("wpseo_taxonomy_meta");
  WPSEO_Taxonomy_Meta::set_values((int)$ids["cat_a"],"category",[
    "wpseo_desc"=>"Conformance per-term SEO description 東京 🚀.","wpseo_noindex"=>"index",
    "wpseo_opengraph-image-id"=>(string)$ids["og"],"wpseo_opengraph-image"=>wp_get_attachment_url((int)$ids["og"]),
    "wpseo_twitter-image-id"=>(string)$ids["twitter"],"wpseo_twitter-image"=>wp_get_attachment_url((int)$ids["twitter"]),
  ]);
' >/dev/null

FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
wp_conf1 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  update_post_meta($post->ID,"_yoast_wpseo_focuskw","AKIAABCDEFGHIJKLMNOP");
' >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SECRET_RC=$?
require_duo_answered 'Yoast credential-shaped metadata capture' human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "Yoast credential-shaped metadata did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Yoast secret refusal partially published canonical state'
wp_conf1 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  update_post_meta($post->ID,"_yoast_wpseo_focuskw","portable 東京 search");
' >/dev/null
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-yoast-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-yoast-restored" \
  || fail 'Yoast source did not restore byte-identically after malformed/secret probes'
rm -rf "$CONF_REPO1/.tmp-yoast-restored"
pass 'malformed structured options and credential-shaped authored metadata refuse atomically and redact values'

# Competing authored branches refuse before mutation. An explicit repository
# choice converges while excluded main-option siblings and runtime rows remain
# owned by the target environment.
wp_conf1 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  update_post_meta($post->ID,"_yoast_wpseo_title","Repository competing Yoast title 東京 🚀 %%sep%% %%sitename%%");
' >/dev/null
commit_yoast_source 'conformance: competing Yoast title intent'
wp_conf2 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  update_post_meta($post->ID,"_yoast_wpseo_title","Target competing Yoast title");
' >/dev/null
CONFLICT_BEFORE=$(yoast_target_hash)
CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast competing branch plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Yoast competing title did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered 'Yoast unforced competing branch apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "Yoast competing branch did not refuse: $CONFLICT_OUT"
[ "$(yoast_target_hash)" = "$CONFLICT_BEFORE" ] || fail 'Yoast unforced conflict partially mutated target state'
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast forced competing branch apply' json "$FORCED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.conflict > 0' <<<"$FORCED" >/dev/null \
  || fail "Yoast forced repository intent did not converge cleanly: $FORCED"
CONVERGED=$(observe_yoast conf2)
jq -e '
  (.meta["_yoast_wpseo_title"] | contains("Repository competing Yoast title 東京 🚀")) and
  .runtime.main_excluded == false and .runtime.undeclared_neighbor == "target-neighbor-preserved" and
  .runtime.migration.error.message == "target-runtime-marker"
' <<<"$CONVERGED" >/dev/null || fail "Yoast forced conflict lost repository or target-owned state: $CONVERGED"
pass 'dirty authored conflicts refuse atomically; explicit force converges without crossing target runtime boundaries'

# Yoast intentionally disables indexable creation outside its own selected
# contexts. Removing only the disposable filter exercises that exact branch:
# authored state lands, derived bytes stay untouched, and the receipt is a
# verified no-op rather than a hollow reindex claim.
$COMPOSE exec -T --user root wp2 rm -f -- /var/www/html/wp-content/mu-plugins/duo-yoast-index-fixture.php
DISABLED_DERIVED_BEFORE=$(yoast_derived_hash)
wp_conf1 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  update_post_meta($post->ID,"_yoast_wpseo_focuskw","disabled-branch authored 東京 🚀");
' >/dev/null
commit_yoast_source 'conformance: Yoast plugin-disabled indexing branch'
DISABLED_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast disabled-indexing apply' json "$DISABLED_APPLY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?; .source == "provider:yoast-index/reindex" and
    (.after.outcome | startswith("no-op (Yoast indexables are disabled")) and .verified == true)
' <<<"$DISABLED_APPLY" >/dev/null || fail "Yoast disabled branch was not a verified provider no-op: $DISABLED_APPLY"
[ "$(yoast_derived_hash)" = "$DISABLED_DERIVED_BEFORE" ] \
  || fail 'Yoast plugin-disabled branch mutated derived tables'
[ "$(wp_conf2 post meta get "$(jq -r '.ids.post' <<<"$(observe_yoast conf2)")" _yoast_wpseo_focuskw)" = 'disabled-branch authored 東京 🚀' ] \
  || fail 'Yoast plugin-disabled branch lost the authored state change'
install_yoast_index_fixture wp2
pass 'Yoast-owned disabled-indexing policy yields a verified no-op while authored state still converges'

# Rename a required live column after publishing new authored intent. Provider
# schema preflight must refuse before the destructive command, the database
# checkpoint must undo earlier materialization, and retry must consume the same
# retained authority once the exact schema is restored.
wp_conf1 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  update_post_meta($post->ID,"_yoast_wpseo_metadesc","Schema recovery Yoast description 東京 🚀");
' >/dev/null
commit_yoast_source 'conformance: Yoast schema-fault recovery intent'
wp_conf2 db query 'ALTER TABLE wp_yoast_indexable RENAME COLUMN link_count TO duo_fault_link_count' >/dev/null
SCHEMA_BEFORE=$(yoast_target_hash)
SCHEMA_RC=0
SCHEMA_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || SCHEMA_RC=$?
require_duo_answered 'Yoast schema-preflight failure' human "$SCHEMA_OUT"
[ "$SCHEMA_RC" -ne 0 ] && grep -q 'missing required column(s): link_count' <<<"$SCHEMA_OUT" \
  || fail "Yoast missing provider column did not refuse exactly: $SCHEMA_OUT"
[ "$(yoast_target_hash)" = "$SCHEMA_BEFORE" ] || fail 'Yoast schema failure left partial authored or derived writes'
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'Yoast schema failure did not retain retry authority'
wp_conf2 db query 'ALTER TABLE wp_yoast_indexable RENAME COLUMN duo_fault_link_count TO link_count' >/dev/null
RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast retry after schema repair' json "$RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$RETRY" >/dev/null \
  || fail "Yoast schema retry did not consume durable intent: $RETRY"
RETRIED=$(observe_yoast conf2)
jq -e '.meta["_yoast_wpseo_metadesc"] == "Schema recovery Yoast description 東京 🚀" and .derived.invalid_links == 0' <<<"$RETRIED" >/dev/null \
  || fail "Yoast schema retry did not converge through native readback: $RETRIED"
pass 'provider schema drift rolls back every write, retains authority, and retries cleanly after exact repair'

# Remove both a scalar post-meta field and a structured option reference. The
# first apply must require deletion authority and remain atomic; --with-deletes
# then removes the authored state while Yoast's own option default reads 0.
wp_conf1 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  delete_post_meta($post->ID,"_yoast_wpseo_bctitle");
  WPSEO_Options::set("contact_page",0);
' >/dev/null
commit_yoast_source 'conformance: Yoast authored deletion intent'
DELETE_BEFORE=$(yoast_target_hash)
DELETE_RC=0
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_RC=$?
require_duo_answered 'Yoast deletion without authority' human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -Eqi 'delete|with-deletes|deletion' <<<"$DELETE_OUT" \
  || fail "Yoast deletion did not require explicit authority: $DELETE_OUT"
[ "$(yoast_target_hash)" = "$DELETE_BEFORE" ] || fail 'Yoast unauthorized deletion partially mutated target state'
DELETED=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast authorized deletion apply' json "$DELETED"
jq -e '.canary == "clean" and .verification.result == "pass" and (.plan.delete + .plan.deleted) > 0' <<<"$DELETED" >/dev/null \
  || fail "Yoast authorized deletion did not converge: $DELETED"
DELETE_OBSERVED=$(observe_yoast conf2)
jq -e '.meta["_yoast_wpseo_bctitle"] == "" and .llms.contact == 0 and .derived.main_primary == 1' <<<"$DELETE_OBSERVED" >/dev/null \
  || fail "Yoast native APIs did not consume authored deletions: $DELETE_OBSERVED"
pass 'post-meta and structured option deletion refuse without authority, then converge with verified reindex'

# Two real processes race one new intent. One may complete and one may observe
# no work or refuse at the named lock; final native state and plan must be exact.
wp_conf1 eval '
  $post=get_page_by_path("conformance-yoast-post",OBJECT,"post");
  update_post_meta($post->ID,"_yoast_wpseo_twitter-title","Concurrent Yoast intent 東京 🚀");
' >/dev/null
commit_yoast_source 'conformance: concurrent Yoast apply intent'
CONCURRENT_A="$CONF_REPO2/.tmp-yoast-concurrent-a.log"
CONCURRENT_B="$CONF_REPO2/.tmp-yoast-concurrent-b.log"
set +e
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing Yoast applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing Yoast apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing Yoast apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_yoast conf2)
jq -e '.meta["_yoast_wpseo_twitter-title"] == "Concurrent Yoast intent 東京 🚀"' <<<"$CONCURRENT" >/dev/null \
  || fail "competing Yoast applies lost repository intent: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast plan after competing applies' json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "Yoast competing applies left retained work: $CONCURRENT_PLAN"
pass 'competing Yoast applies serialize and leave one exact idempotent result'

# Deactivation is repaired by deploy. Yoast's native uninstall removes code
# but deliberately retains authored/options/indexable storage; absent code must
# refuse, then the digest-bound exact artifact restores a clean active runtime.
wp_conf2 plugin deactivate wordpress-seo >/dev/null
wp_conf2 plugin is-active wordpress-seo >/dev/null 2>&1 && fail 'Yoast deactivation premise did not land'
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast deploy after deactivation' json "$REACTIVATE"
wp_conf2 plugin is-active wordpress-seo >/dev/null || fail 'Duo deploy did not reactivate exact Yoast code'
LIFECYCLE_BEFORE=$(yoast_target_hash)
wp_conf2 plugin deactivate wordpress-seo >/dev/null
wp_conf2 plugin uninstall wordpress-seo >/dev/null
wp_conf2 plugin is-installed wordpress-seo >/dev/null 2>&1 && fail 'Yoast uninstall left plugin code installed'
[ "$(yoast_target_hash)" = "$LIFECYCLE_BEFORE" ] \
  || fail 'Yoast ordinary uninstall unexpectedly removed or changed retained state'
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered 'Yoast deploy with code absent' human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Yoast code did not refuse at compatibility: $MISSING_OUT"
YOAST_SHA=381edc1603147bd76af81341f21c9155ff3e9f6ce29ed20886d889fb9d6744fb
YOAST_ARTIFACT="/artifacts-cache/plugin-wordpress-seo-28.3-${YOAST_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256','$YOAST_ARTIFACT');")" = "$YOAST_SHA" ] \
  || fail 'cached Yoast reinstall artifact digest moved'
wp_conf2 plugin install "$YOAST_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get wordpress-seo --field=version)" = 28.3 ] || fail 'Yoast exact reinstall reported wrong version'
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast deploy after exact reinstall' json "$REINSTALL_DEPLOY"
RECOVERED=$(observe_yoast conf2)
jq -e '
  .version == "28.3" and .meta["_yoast_wpseo_twitter-title"] == "Concurrent Yoast intent 東京 🚀" and
  .runtime.undeclared_neighbor == "target-neighbor-preserved" and .runtime.main_excluded == false and
  .derived.main_primary == 1 and .derived.invalid_hierarchy == 0 and .derived.invalid_links == 0
' <<<"$RECOVERED" >/dev/null || fail "Yoast retained state did not recover after exact reinstall: $RECOVERED"
FINAL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast final apply after exact reinstall' json "$FINAL_APPLY"
jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$FINAL_APPLY" >/dev/null \
  || fail "Yoast exact reinstall did not remain clean: $FINAL_APPLY"
FINAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Yoast final recovery plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "Yoast recovery was not idempotent: $FINAL_PLAN"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-yoast-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-yoast-final" || fail 'Yoast final recovered state was not byte-identical'
rm -rf "$CONF_REPO2/.tmp-yoast-final"
pass 'deactivate/reactivate, retained-data uninstall, absent-code refusal, exact reinstall, and final retry are clean'
