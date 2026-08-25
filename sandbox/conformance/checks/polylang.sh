#!/usr/bin/env bash
# Exact-artifact Polylang acceptance: hostile identity adoption, native
# three-language API/frontend behavior, full derived-state closure, explicit
# schema/deletion boundaries, recovery, contention, and package lifecycle.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"
POLYLANG_EXPECTED_VERSION="${POLYLANG_EXPECTED_VERSION:-${POLYLANG_VERSION:-3.8.6}}"

observe_polylang() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Polylang observation side: $side" ;;
  esac
  file="$repo/.tmp-polylang-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
$post = static function (string $slug, string $type): WP_Post {
    global $wpdb;
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s ORDER BY ID ASC LIMIT 1",
        $slug,
        $type
    ));
    $found = $id ? get_post((int) $id) : null;
    if (!$found instanceof WP_Post) {
        throw new RuntimeException("Polylang $type fixture missing: $slug");
    }
    return $found;
};
$term = static function (string $slug): WP_Term {
    $found = get_term_by('slug', $slug, 'category');
    if (!$found instanceof WP_Term) {
        throw new RuntimeException("Polylang category fixture missing: $slug");
    }
    return $found;
};
$menu = static function (string $name): WP_Term {
    $found = wp_get_nav_menu_object($name);
    if (!$found instanceof WP_Term) {
        throw new RuntimeException("Polylang nav menu fixture missing: $name");
    }
    return $found;
};
$slugs = ['en' => 'portable-polylang-story-en', 'fr' => 'portable-polylang-story-fr', 'ar' => 'portable-polylang-story-ar'];
$pageSlugs = ['en' => 'portable-polylang-page-en', 'fr' => 'portable-polylang-page-fr', 'ar' => 'portable-polylang-page-ar'];
$blockSlugs = ['en' => 'portable-polylang-block-en', 'fr' => 'portable-polylang-block-fr', 'ar' => 'portable-polylang-block-ar'];
$categorySlugs = ['en' => 'conformance-polylang-news-en', 'fr' => 'conformance-polylang-news-fr', 'ar' => 'conformance-polylang-news-ar'];
$menuNames = ['en' => 'Polylang Primary English', 'fr' => 'Polylang Principal Français', 'ar' => 'قائمة بوليلانج الرئيسية'];
$posts = $pages = $blocks = $terms = $attachments = $menus = [];
foreach (['en', 'fr', 'ar'] as $language) {
    $p = $post($slugs[$language], 'post');
    $page = $post($pageSlugs[$language], 'page');
    $block = $post($blockSlugs[$language], 'wp_block');
    $a = $post("duo-polylang-media-$language", 'attachment');
    $t = $term($categorySlugs[$language]);
    $m = $menu($menuNames[$language]);
    $posts[$language] = [
        'content_bytes' => strlen((string) $p->post_content),
        'excerpt' => (string) $p->post_excerpt,
        'id' => (int) $p->ID,
        'language' => (string) pll_get_post_language((int) $p->ID, 'slug'),
        'permalink' => (string) get_permalink((int) $p->ID),
        'title' => (string) $p->post_title,
        'translations' => array_map('intval', pll_get_post_translations((int) $p->ID)),
    ];
    $pages[$language] = [
        'id' => (int) $page->ID,
        'language' => (string) pll_get_post_language((int) $page->ID, 'slug'),
        'permalink' => (string) get_permalink((int) $page->ID),
        'status' => (string) $page->post_status,
        'title' => (string) $page->post_title,
        'translations' => array_map('intval', pll_get_post_translations((int) $page->ID)),
    ];
    $blocks[$language] = [
        'content' => (string) $block->post_content,
        'date_gmt' => (string) $block->post_date_gmt,
        'id' => (int) $block->ID,
        'language' => (string) pll_get_post_language((int) $block->ID, 'slug'),
        'status' => (string) $block->post_status,
        'title' => (string) $block->post_title,
        'translations' => array_map('intval', pll_get_post_translations((int) $block->ID)),
    ];
    $attachments[$language] = [
        'alt' => (string) get_post_meta((int) $a->ID, '_wp_attachment_image_alt', true),
        'id' => (int) $a->ID,
        'language' => (string) pll_get_post_language((int) $a->ID, 'slug'),
        'mime' => (string) $a->post_mime_type,
        'parent' => (int) $a->post_parent,
        'translations' => array_map('intval', pll_get_post_translations((int) $a->ID)),
        'url' => (string) wp_get_attachment_url((int) $a->ID),
    ];
    $terms[$language] = [
        'id' => (int) $t->term_id,
        'language' => (string) pll_get_term_language((int) $t->term_id, 'slug'),
        'name' => (string) $t->name,
        'translations' => array_map('intval', pll_get_term_translations((int) $t->term_id)),
    ];
    $menus[$language] = ['id' => (int) $m->term_id, 'name' => (string) $m->name];
}
$languageTerms = [];
foreach (get_terms(['taxonomy' => 'language', 'hide_empty' => false]) as $languageTerm) {
    if (!$languageTerm instanceof WP_Term || !in_array($languageTerm->slug, ['en', 'fr', 'ar'], true)) {
        continue;
    }
    $description = @unserialize((string) $languageTerm->description, ['allowed_classes' => false]);
    $languageTerms[$languageTerm->slug] = [
        'id' => (int) $languageTerm->term_id,
        'locale' => is_array($description) ? (string) ($description['locale'] ?? '') : '',
        'name' => (string) $languageTerm->name,
        'rtl' => is_array($description) ? (bool) ($description['rtl'] ?? false) : false,
    ];
}
ksort($languageTerms, SORT_STRING);
$switcher = null;
foreach (wp_get_nav_menu_items($menus['en']['id']) ?: [] as $item) {
    $candidate = get_post_meta((int) $item->ID, '_pll_menu_item', true);
    if (is_array($candidate) && $candidate !== []) {
        $switcher = ['id' => (int) $item->ID, 'settings' => $candidate];
        break;
    }
}
if (!is_array($switcher)) {
    throw new RuntimeException('Polylang native nav-menu switcher metadata missing');
}
$option = get_option('polylang');
$stylesheet = (string) get_option('stylesheet');
$themeMods = get_option('theme_mods_' . $stylesheet);
$rawLocations = is_array($themeMods) && is_array($themeMods['nav_menu_locations'] ?? null) ? $themeMods['nav_menu_locations'] : [];
$defaultCategory = (int) get_option('default_category');
$rules = get_option('rewrite_rules');
echo wp_json_encode([
    'attachments' => $attachments,
    'blocks' => $blocks,
    'default_category' => ['id' => $defaultCategory, 'language' => (string) pll_get_term_language($defaultCategory, 'slug')],
    'home' => home_url('/'),
    'language_terms' => $languageTerms,
    'languages' => array_values(pll_languages_list(['fields' => 'slug'])),
    'menus' => $menus,
    'options' => [
        'browser' => is_array($option) ? ($option['browser'] ?? null) : null,
        'default_lang' => is_array($option) ? ($option['default_lang'] ?? null) : null,
        'force_lang' => is_array($option) ? ($option['force_lang'] ?? null) : null,
        'hide_default' => is_array($option) ? ($option['hide_default'] ?? null) : null,
        'media_support' => is_array($option) ? ($option['media_support'] ?? null) : null,
        'nav_menus' => is_array($option) ? ($option['nav_menus'] ?? null) : null,
        'post_types' => is_array($option) ? ($option['post_types'] ?? null) : null,
        'sync' => is_array($option) ? ($option['sync'] ?? null) : null,
        'taxonomies' => is_array($option) ? ($option['taxonomies'] ?? null) : null,
    ],
    'posts' => $posts,
    'pages' => $pages,
    'rewrite' => [
        'count' => is_array($rules) ? count($rules) : 0,
        'hash' => is_array($rules) ? hash('sha256', serialize($rules)) : '',
        'stale_target_rule' => is_array($rules) && array_key_exists('^target-stale/?$', $rules),
    ],
    'runtime' => [
        'language_from_content' => get_option('pll_language_from_content_available'),
        'language_taxonomies' => get_option('pll_language_taxonomies'),
        'undeclared_neighbor' => get_option('duo_polylang_undeclared_neighbor'),
    ],
    'switcher' => $switcher,
    'terms' => $terms,
    'theme_locations' => $rawLocations,
    'version' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : null,
    'widgets' => get_option('widget_polylang'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-polylang-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-polylang-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "$side Polylang native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

commit_polylang_source() { # <message>
  wp_conf1 duo capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

polylang_target_hash() {
  observe_polylang conf2 | shasum -a 256 | awk '{print $1}'
}

SOURCE=$(observe_polylang conf1)
TARGET=$(observe_polylang conf2)
SOURCE_IDS=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-polylang-source.json")
TARGET_IDS_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-polylang-target.json"
TARGET_IDS='{}'
[ ! -f "$TARGET_IDS_FILE" ] || TARGET_IDS=$(cat "$TARGET_IDS_FILE")

jq -e --arg version "$POLYLANG_EXPECTED_VERSION" '
  .version == $version and .languages == ["en","fr","ar"] and
  .language_terms.en.locale == "en_US" and .language_terms.en.rtl == false and
  .language_terms.fr.locale == "fr_FR" and .language_terms.fr.name == "Français 東京" and .language_terms.fr.rtl == false and
  .language_terms.ar.locale == "ar" and .language_terms.ar.name == "العربية 🚀" and .language_terms.ar.rtl == true and
  .options.browser == false and .options.default_lang == "en" and .options.force_lang == 1 and
  .options.hide_default == false and .options.media_support == 1 and
  .options.post_types == [] and .options.taxonomies == [] and
  .options.redirect_lang == false and .options.rewrite == true and
  .options.sync == ["taxonomies","post_meta","post_date"] and
  .posts.en.language == "en" and .posts.fr.language == "fr" and .posts.ar.language == "ar" and
  .posts.en.content_bytes > 50000 and .posts.fr.content_bytes > 50000 and .posts.ar.content_bytes > 50000 and
  (.posts.en.title | contains("東京 🚀")) and (.posts.fr.title | contains("française")) and (.posts.ar.title | contains("قصة")) and
  .posts.en.translations == {en:.posts.en.id,fr:.posts.fr.id,ar:.posts.ar.id} and
  .posts.fr.translations == .posts.en.translations and .posts.ar.translations == .posts.en.translations and
  .pages.en.status == "publish" and .pages.fr.status == "private" and .pages.ar.status == "draft" and
  .pages.en.language == "en" and .pages.fr.language == "fr" and .pages.ar.language == "ar" and
  (.pages.en.title | contains("English Page 東京 🚀")) and (.pages.fr.title | contains("française")) and (.pages.ar.title | contains("صفحة")) and
  .pages.en.translations == {en:.pages.en.id,fr:.pages.fr.id,ar:.pages.ar.id} and
  .pages.fr.translations == .pages.en.translations and .pages.ar.translations == .pages.en.translations and
  .blocks.en.status == "pending" and .blocks.fr.status == "future" and .blocks.ar.status == "private" and
  .blocks.fr.date_gmt != "0000-00-00 00:00:00" and
  .blocks.en.language == "en" and .blocks.fr.language == "fr" and .blocks.ar.language == "ar" and
  (.blocks.en.content | contains("English portable synced pattern")) and
  (.blocks.fr.content | contains("Composition française portable")) and
  (.blocks.ar.content | contains("نمط عربي قابل للنقل")) and
  .blocks.en.translations == {en:.blocks.en.id,fr:.blocks.fr.id,ar:.blocks.ar.id} and
  .blocks.fr.translations == .blocks.en.translations and .blocks.ar.translations == .blocks.en.translations and
  .terms.en.language == "en" and .terms.fr.language == "fr" and .terms.ar.language == "ar" and
  .terms.en.translations == {en:.terms.en.id,fr:.terms.fr.id,ar:.terms.ar.id} and
  .terms.fr.translations == .terms.en.translations and .terms.ar.translations == .terms.en.translations and
  .attachments.en.language == "en" and .attachments.fr.language == "fr" and .attachments.ar.language == "ar" and
  .attachments.en.translations == {en:.attachments.en.id,fr:.attachments.fr.id,ar:.attachments.ar.id} and
  .attachments.fr.translations == .attachments.en.translations and .attachments.ar.translations == .attachments.en.translations and
  .attachments.en.parent == .posts.en.id and .attachments.fr.parent == .posts.fr.id and .attachments.ar.parent == .posts.ar.id and
  (.attachments | to_entries | all(.value.mime == "image/png" and (.value.alt | contains("東京 🚀")))) and
  .switcher.settings == {hide_if_no_translation:0,hide_current:0,force_home:0,show_flags:0,show_names:1,dropdown:1} and
  .widgets["1"].title == "All Languages 東京 🚀" and .widgets["2"].pll_lang == "ar" and
  .default_category.language == "en" and .theme_locations.primary == .menus.en.id and
  .rewrite.count > 0 and .rewrite.stale_target_rule == false
' <<<"$TARGET" >/dev/null || fail "Polylang post/page/pattern status and translation graph, Unicode/RTL data, widget/menu switchers, or derived state did not converge: $TARGET"

if [ -f "$TARGET_IDS_FILE" ]; then
  jq -e --argjson observed "$TARGET" '
    .posts == ($observed.posts | with_entries(.value = .value.id)) and
    .pages == ($observed.pages | with_entries(.value = .value.id)) and
    .blocks == ($observed.blocks | with_entries(.value = .value.id)) and
    .attachments == ($observed.attachments | with_entries(.value = .value.id)) and
    .terms == ($observed.terms | with_entries(.value = .value.id)) and
    .menus.en == $observed.menus.en.id
  ' <<<"$TARGET_IDS" >/dev/null || fail "Polylang apply replaced rather than adopted hostile target identities: premise=$TARGET_IDS observed=$TARGET"
  jq -e '
    .options.browser == false and .options.force_lang == 1 and .options.hide_default == false and
    .options.redirect_lang == false and .options.rewrite == true and
    .runtime.language_from_content == "target-runtime-sentinel" and
    .runtime.language_taxonomies == ["target-runtime-taxonomy-cache"] and
    .runtime.undeclared_neighbor == "target-only-preserved"
  ' <<<"$TARGET" >/dev/null || fail "Polylang target-owned option/runtime siblings crossed the authored boundary: $TARGET"
fi

for kind in posts pages blocks attachments terms language_terms menus; do
  for language in en fr ar; do
    SOURCE_ID=$(jq -r --arg kind "$kind" --arg language "$language" '.[$kind][$language]' <<<"$SOURCE_IDS")
    TARGET_ID=$(jq -r --arg kind "$kind" --arg language "$language" '.[$kind][$language].id' <<<"$TARGET")
    require_fixture_ids SOURCE_ID TARGET_ID
    [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Polylang source/target $kind.$language identity did not diverge ($SOURCE_ID)"
  done
done

POST_EN_ID=$(jq -r '.posts.en.id' <<<"$TARGET")
POST_FR_ID=$(jq -r '.posts.fr.id' <<<"$TARGET")
PAGE_EN_ID=$(jq -r '.pages.en.id' <<<"$TARGET")
PAGE_FR_ID=$(jq -r '.pages.fr.id' <<<"$TARGET")
PAGE_AR_ID=$(jq -r '.pages.ar.id' <<<"$TARGET")
BLOCK_EN_ID=$(jq -r '.blocks.en.id' <<<"$TARGET")
BLOCK_FR_ID=$(jq -r '.blocks.fr.id' <<<"$TARGET")
BLOCK_AR_ID=$(jq -r '.blocks.ar.id' <<<"$TARGET")
NEWS_ID=$(jq -r '.terms.en.id' <<<"$TARGET")
ACT_ID=$(jq -r '.terms.fr.id' <<<"$TARGET")
require_fixture_ids POST_EN_ID POST_FR_ID PAGE_EN_ID PAGE_FR_ID PAGE_AR_ID BLOCK_EN_ID BLOCK_FR_ID BLOCK_AR_ID NEWS_ID ACT_ID
POST_TR=$(wp_conf2 eval "echo json_encode(pll_get_post_translations($POST_EN_ID));")
PAGE_TR=$(wp_conf2 eval "echo json_encode(pll_get_post_translations($PAGE_EN_ID));")
BLOCK_TR=$(wp_conf2 eval "echo json_encode(pll_get_post_translations($BLOCK_EN_ID));")
TERM_TR=$(wp_conf2 eval "echo json_encode(pll_get_term_translations($NEWS_ID));")
TERM_TR_FR=$(wp_conf2 eval "echo json_encode(pll_get_term_translations($ACT_ID));")
NEWS_LANG=$(wp_conf2 eval "echo pll_get_term_language($NEWS_ID, 'slug');")
ACT_LANG=$(wp_conf2 eval "echo pll_get_term_language($ACT_ID, 'slug');")
require_observed_nonempty "conf2 Polylang post translation map" "$POST_TR"
require_observed_nonempty "conf2 Polylang page translation map" "$PAGE_TR"
require_observed_nonempty "conf2 Polylang synced-pattern translation map" "$BLOCK_TR"
require_observed_nonempty "conf2 Polylang English term translation map" "$TERM_TR"
require_observed_nonempty "conf2 Polylang French term translation map" "$TERM_TR_FR"
require_observed_nonempty "conf2 Polylang English term language" "$NEWS_LANG"
require_observed_nonempty "conf2 Polylang French term language" "$ACT_LANG"

jq -e --argjson page_en "$PAGE_EN_ID" --argjson page_fr "$PAGE_FR_ID" --argjson page_ar "$PAGE_AR_ID" '
  .en == $page_en and .fr == $page_fr and .ar == $page_ar
' <<<"$PAGE_TR" >/dev/null || fail "Polylang native page translation map did not bind target-local identities: $PAGE_TR"
jq -e --argjson block_en "$BLOCK_EN_ID" --argjson block_fr "$BLOCK_FR_ID" --argjson block_ar "$BLOCK_AR_ID" '
  .en == $block_en and .fr == $block_fr and .ar == $block_ar
' <<<"$BLOCK_TR" >/dev/null || fail "Polylang native synced-pattern translation map did not bind target-local identities: $BLOCK_TR"

TERM_GROUP_TT=$(wp_conf2 db query "SELECT tr.term_taxonomy_id FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=$NEWS_ID AND tt.taxonomy='term_translations'" --skip-column-names)
require_observed_nonempty "conf2 Polylang term translation term-taxonomy id" "$TERM_GROUP_TT"
TERM_GROUP_DESC=$(wp_conf2 db query "SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id=$TERM_GROUP_TT" --skip-column-names)
require_observed_nonempty "conf2 Polylang term translation serialized description" "$TERM_GROUP_DESC"
for OBJECT_ID in "$POST_EN_ID" "$PAGE_EN_ID" "$BLOCK_EN_ID"; do
  POST_GROUP_TT=$(wp_conf2 db query "SELECT tr.term_taxonomy_id FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=$OBJECT_ID AND tt.taxonomy='post_translations'" --skip-column-names)
  require_observed_nonempty "conf2 Polylang post/page/pattern translation term-taxonomy id" "$POST_GROUP_TT"
  POST_GROUP_DESC=$(wp_conf2 db query "SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id=$POST_GROUP_TT" --skip-column-names)
  require_observed_nonempty "conf2 Polylang post/page/pattern translation serialized description" "$POST_GROUP_DESC"
  grep -qE 's:[0-9]+:"[0-9]+"' <<<"$POST_GROUP_DESC" && fail "Polylang translation description stringified a local id: $POST_GROUP_DESC"
  grep -qE 'i:[0-9]+;' <<<"$POST_GROUP_DESC" || fail "Polylang translation description lost native integer ids: $POST_GROUP_DESC"
done
for DESC in "$TERM_GROUP_DESC"; do
  grep -qE 's:[0-9]+:"[0-9]+"' <<<"$DESC" && fail "Polylang translation description stringified a local id: $DESC"
  grep -qE 'i:[0-9]+;' <<<"$DESC" || fail "Polylang translation description lost native integer ids: $DESC"
done
pass 'posts, pages, synced patterns, terms, media, menu maps and serialized translation groups rebind every target-local identity'

PROVIDER_RECEIPT="${APPLY_JSON:-}"
if [ -z "$PROVIDER_RECEIPT" ] && [ -n "${VMATRIX_APPLY_LOG:-}" ] && [ -f "$VMATRIX_APPLY_LOG" ]; then
  PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
fi
grep -Fq 'polylang-nav-menus@2.0.0' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt did not identify Polylang provider 2.0.0: ${PROVIDER_RECEIPT:-<missing>}"
if jq -e 'type == "object"' <<<"$PROVIDER_RECEIPT" >/dev/null 2>&1; then
  jq -e '
    any(.actions[]?; .source == "provider:polylang-nav-menus/synchronize_runtime" and .verified == true and
      .after.default_category_language == "en" and .after.nav_menu_locations_count >= 1 and
      (.after.nav_menu_locations_hash | test("^[0-9a-f]{64}$")) and
      (.after.native_catalogs_hash | test("^[0-9a-f]{64}$"))) and
    any(.actions[]?; .source == "native:rewrite.flush" and .verified == true and
      .after.rules_present == true and .after.rules_count > 0 and
      (.after.rules_hash | test("^[0-9a-f]{64}$")) and
      (.after.runtime_rules_hash | test("^[0-9a-f]{64}$")))
  ' <<<"$PROVIDER_RECEIPT" >/dev/null || fail 'Polylang projection/native rewrite receipts omitted their closed postconditions'
fi
pass 'Polylang provider 2.0.0 verifies plugin projections; the separate native action verifies rewrites'

for language in en fr ar; do
  URL=$(jq -r --arg language "$language" '.posts[$language].permalink' <<<"$TARGET")
  URL=${URL/\/\/localhost\//\/\/localhost:${CONF2_PORT}\/}
  FRONT=$(curl -fsSL "$URL") || fail "conf2 Polylang $language permalink did not return 200: $URL"
  require_observed_nonempty "conf2 Polylang rendered response" "$FRONT"
  [ "${#FRONT}" -ge 10000 ] || fail "conf2 Polylang $language response was suspiciously short (${#FRONT} bytes)"
  grep -qiE 'fatal error|uncaught' <<<"$FRONT" && fail "conf2 Polylang $language render contains a fatal marker"
  grep -Fq "localhost:${CONF1_PORT}" <<<"$FRONT" && fail "conf2 Polylang $language render leaked the source host"
  grep -Fq "marker-$language-東京-🚀" <<<"$FRONT" || fail "conf2 Polylang $language request did not select its native language menu"
  if [ "$language" = ar ]; then
    grep -Eqi "<html[^>]+dir=[\"']rtl[\"']" <<<"$FRONT" || fail 'Arabic frontend did not expose native RTL document direction'
    grep -Fq 'محتوى عربي قابل للنقل' <<<"$FRONT" || fail 'Arabic frontend did not consume its translated content'
  fi
done
REST=$(curl -fsSL "http://localhost:${CONF2_PORT}/wp-json/wp/v2/posts/$POST_EN_ID") || fail 'conf2 Polylang REST post request failed'
jq -e --argjson id "$POST_EN_ID" '.id == $id and (.content.rendered | contains("English portable body 東京 🚀"))' <<<"$REST" >/dev/null \
  || fail "Polylang target REST API did not consume the translated target post: $REST"
PAGE_URL=$(jq -r '.pages.en.permalink' <<<"$TARGET")
PAGE_URL=${PAGE_URL/\/\/localhost\//\/\/localhost:${CONF2_PORT}\/}
PAGE_FRONT=$(curl -fsSL "$PAGE_URL") || fail "conf2 Polylang English page permalink did not return 200: $PAGE_URL"
grep -Fq 'English portable page reference.' <<<"$PAGE_FRONT" \
  || fail 'Polylang public page route did not consume the translated target page'
PAGE_REST=$(curl -fsSL "http://localhost:${CONF2_PORT}/wp-json/wp/v2/pages/$PAGE_EN_ID") || fail 'conf2 Polylang REST page request failed'
jq -e --argjson id "$PAGE_EN_ID" '.id == $id and .status == "publish" and (.content.rendered | contains("English portable page reference."))' <<<"$PAGE_REST" >/dev/null \
  || fail "Polylang target REST API did not consume the translated target page: $PAGE_REST"
pass 'frontend language switching, per-language menus, public post/page routes, Arabic RTL, media URLs and REST all consume target-local state'

ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null || fail "Polylang retry retained work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null || fail "Polylang no-op apply reran effects or mutated state: $ZERO_APPLY"
pass 'Polylang zero-change plan/apply is mutation-free and idempotent'

if [ "${POLYLANG_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "Polylang $POLYLANG_EXPECTED_VERSION exact boundary consumed the full portable fixture"
  return 0 2>/dev/null || exit 0
fi

# Re-capture two non-public scoped groups after initial adoption. This proves
# the status-bearing page/pattern records do not only survive first import:
# their second capture resolves the pre-existing target-local identities and a
# zero-change retry remains effect-free after the durable source edit.
SOURCE_PAGE_FR=$(jq -r '.pages.fr' <<<"$SOURCE_IDS")
SOURCE_BLOCK_FR=$(jq -r '.blocks.fr' <<<"$SOURCE_IDS")
TARGET_PAGE_FR_BEFORE=$(jq -r '.pages.fr.id' <<<"$TARGET")
TARGET_BLOCK_FR_BEFORE=$(jq -r '.blocks.fr.id' <<<"$TARGET")
require_fixture_ids SOURCE_PAGE_FR SOURCE_BLOCK_FR TARGET_PAGE_FR_BEFORE TARGET_BLOCK_FR_BEFORE
wp_conf1 eval "\$result=wp_update_post(['ID'=>(int)$SOURCE_PAGE_FR,'post_title'=>'Repository private French page refresh 東京 🚀'],true); if (is_wp_error(\$result)) throw new RuntimeException(\$result->get_error_message());" >/dev/null
wp_conf1 eval "\$result=wp_update_post(['ID'=>(int)$SOURCE_BLOCK_FR,'post_content'=>'<!-- wp:paragraph --><p>Repository scheduled French pattern refresh 東京 🚀.</p><!-- /wp:paragraph -->'],true); if (is_wp_error(\$result)) throw new RuntimeException(\$result->get_error_message());" >/dev/null
commit_polylang_source 'conformance: recapture Polylang private page and scheduled pattern'
PAGE_BLOCK_RECAPTURE=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang page/pattern recapture apply' json "$PAGE_BLOCK_RECAPTURE"
PAGE_BLOCK_RECAPTURED=$(observe_polylang conf2)
jq -e --argjson page_id "$TARGET_PAGE_FR_BEFORE" --argjson block_id "$TARGET_BLOCK_FR_BEFORE" '
  .pages.fr.id == $page_id and .pages.fr.status == "private" and
  .pages.fr.title == "Repository private French page refresh 東京 🚀" and
  .blocks.fr.id == $block_id and .blocks.fr.status == "future" and
  (.blocks.fr.content | contains("Repository scheduled French pattern refresh 東京 🚀"))
' <<<"$PAGE_BLOCK_RECAPTURED" >/dev/null \
  || fail "Polylang page/pattern recapture did not preserve target identities, hidden statuses, and source intent: $PAGE_BLOCK_RECAPTURED"
PAGE_BLOCK_ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
PAGE_BLOCK_ZERO_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$PAGE_BLOCK_ZERO_PLAN" >/dev/null \
  || fail "Polylang page/pattern recapture retained work: $PAGE_BLOCK_ZERO_PLAN"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$PAGE_BLOCK_ZERO_APPLY" >/dev/null \
  || fail "Polylang page/pattern recapture retry reran effects: $PAGE_BLOCK_ZERO_APPLY"
pass 'private page and scheduled pattern re-capture preserve target identity/status and converge to a zero-change retry'

# Corrupt one exact source frontier at a time. Each capture must refuse before
# publication, after which raw backups restore byte-identical plugin state.
BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-polylang-schema-backup.json"
wp_conf1 eval '
  global $wpdb;
  $switcher=$wpdb->get_row("SELECT pm.post_id,pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.meta_key=\"_pll_menu_item\" LIMIT 1",ARRAY_A);
  $group=$wpdb->get_row("SELECT tt.term_taxonomy_id,tt.description FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy=\"post_translations\" LIMIT 1",ARRAY_A);
  $language=$wpdb->get_row("SELECT tt.term_taxonomy_id,tt.description FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tt.taxonomy=\"language\" AND t.slug=\"ar\" LIMIT 1",ARRAY_A);
  if (!$switcher || !$group || !$language) throw new RuntimeException("Polylang schema backup fixture missing");
  file_put_contents("/siterepo/.tmp-polylang-schema-backup.json",wp_json_encode(["switcher"=>$switcher,"group"=>$group,"language"=>$language]));
' >/dev/null
BASELINE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)

GROUP_ID=$(jq -r '.group.term_taxonomy_id' "$BACKUP")
require_fixture_ids GROUP_ID
wp_conf1 eval '
  global $wpdb; $b=json_decode(file_get_contents("/siterepo/.tmp-polylang-schema-backup.json"),true);
  $wpdb->update($wpdb->term_taxonomy,["description"=>"a:2:{broken"],["term_taxonomy_id"=>(int)$b["group"]["term_taxonomy_id"]]);
' >/dev/null
MALFORMED_RC=0
MALFORMED_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || MALFORMED_RC=$?
[ "$MALFORMED_RC" -ne 0 ] && grep -Eqi 'description|serialized|unserialize|array' <<<"$MALFORMED_OUT" \
  || fail "malformed Polylang translation description did not refuse: $MALFORMED_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BASELINE" ] \
  || fail 'malformed Polylang description partially published canonical state'
wp_conf1 eval '
  global $wpdb; $b=json_decode(file_get_contents("/siterepo/.tmp-polylang-schema-backup.json"),true);
  $wpdb->update($wpdb->term_taxonomy,["description"=>$b["group"]["description"]],["term_taxonomy_id"=>(int)$b["group"]["term_taxonomy_id"]]);
' >/dev/null

wp_conf1 eval '
  global $wpdb; $b=json_decode(file_get_contents("/siterepo/.tmp-polylang-schema-backup.json"),true);
  $wpdb->update($wpdb->term_taxonomy,["description"=>"a:3:{broken"],["term_taxonomy_id"=>(int)$b["language"]["term_taxonomy_id"]]);
' >/dev/null
LANGUAGE_SCHEMA_RC=0
LANGUAGE_SCHEMA_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || LANGUAGE_SCHEMA_RC=$?
[ "$LANGUAGE_SCHEMA_RC" -ne 0 ] && grep -Fq 'Polylang live language description must be canonical PHP-serialized plain data' <<<"$LANGUAGE_SCHEMA_OUT" \
  || fail "malformed Polylang language description did not refuse: $LANGUAGE_SCHEMA_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BASELINE" ] \
  || fail 'malformed Polylang language description partially published canonical state'
wp_conf1 eval '
  global $wpdb; $b=json_decode(file_get_contents("/siterepo/.tmp-polylang-schema-backup.json"),true);
  $wpdb->update($wpdb->term_taxonomy,["description"=>$b["language"]["description"]],["term_taxonomy_id"=>(int)$b["language"]["term_taxonomy_id"]]);
' >/dev/null

FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
wp_conf1 eval '
  global $wpdb; $b=json_decode(file_get_contents("/siterepo/.tmp-polylang-schema-backup.json"),true);
  $value=maybe_unserialize($b["switcher"]["meta_value"]); $value["future_secret"]="AKIAABCDEFGHIJKLMNOP";
  update_post_meta((int)$b["switcher"]["post_id"],"_pll_menu_item",$value);
' >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SECRET_RC=$?
[ "$SECRET_RC" -ne 0 ] && grep -Eqi 'Polylang live _pll_menu_item|switcher keys|secret guard' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "Polylang switcher schema/secret did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BASELINE" ] \
  || fail 'Polylang switcher schema refusal partially published canonical state'
wp_conf1 eval '
  global $wpdb; $b=json_decode(file_get_contents("/siterepo/.tmp-polylang-schema-backup.json"),true);
  $wpdb->update($wpdb->postmeta,["meta_value"=>$b["switcher"]["meta_value"]],["post_id"=>(int)$b["switcher"]["post_id"],"meta_key"=>"_pll_menu_item"]);
  clean_post_cache((int)$b["switcher"]["post_id"]);
' >/dev/null

LANG_TERM=$(jq -r '.language_terms.fr' <<<"$SOURCE_IDS")
require_fixture_ids LANG_TERM
wp_conf1 term meta add "$LANG_TERM" _pll_strings_translations 'a:1:{i:0;a:2:{i:0;s:5:"Hello";i:1;s:7:"Bonjour";}}' >/dev/null
STRINGS_STATE="$CONF_REPO1/.tmp-polylang-strings"
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-polylang-strings >/dev/null
jq -s -e 'any(.[]; .meta._pll_strings_translations == [["Hello", "Bonjour"]])' \
  "$STRINGS_STATE"/terms/language/*.json >/dev/null \
  || fail 'populated Polylang string translations were not captured as the reviewed plain-data termmeta shape'
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BASELINE" ] \
  || fail 'Polylang string-translation probe partially published canonical state'
rm -rf "$STRINGS_STATE"
wp_conf1 term meta delete "$LANG_TERM" _pll_strings_translations >/dev/null

DELETE_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-polylang-delete-row.json"
wp_conf1 eval '
  global $wpdb; $term=get_term_by("slug","ar","language");
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->terms} WHERE term_id=%d",$term->term_id),ARRAY_A);
  file_put_contents("/siterepo/.tmp-polylang-delete-row.json",wp_json_encode($row));
  if (1!==$wpdb->delete($wpdb->terms,["term_id"=>(int)$term->term_id])) throw new RuntimeException($wpdb->last_error);
  clean_term_cache((int)$term->term_id,"language");
' >/dev/null
DELETE_RC=0
DELETE_OUT=$(wp_conf1 duo capture --repo=/siterepo --format=json) || DELETE_RC=$?
require_duo_answered 'Polylang unsupported language deletion capture' json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and (.surface | contains("term:")))
' <<<"$DELETE_OUT" >/dev/null || fail "Polylang language deletion did not refuse atomically: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BASELINE" ] \
  || fail 'Polylang unsupported language deletion published a tombstone'
wp_conf1 eval '
  global $wpdb; $row=json_decode(file_get_contents("/siterepo/.tmp-polylang-delete-row.json"),true);
  if (false===$wpdb->insert($wpdb->terms,$row)) throw new RuntimeException($wpdb->last_error);
  clean_term_cache((int)$row["term_id"],"language");
' >/dev/null
rm -f "$BACKUP" "$DELETE_BACKUP"
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-polylang-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-polylang-restored" \
  || fail 'Polylang source did not restore exactly after malformed/secret/strings/deletion probes'
rm -rf "$CONF_REPO1/.tmp-polylang-restored"
pass 'malformed groups/language metadata, switcher schema/secrets and language deletion refuse atomically; reviewed string catalogs capture without publication'

# Managed source and target edits form a true three-way conflict. Unforced
# apply must be mutation-free; explicit repository authority then converges.
SOURCE_FR=$(jq -r '.posts.fr' <<<"$SOURCE_IDS")
TARGET_FR=$(jq -r '.posts.fr.id' <<<"$TARGET")
require_fixture_ids SOURCE_FR TARGET_FR
wp_conf1 post update "$SOURCE_FR" --post_title='Repository competing French title 東京 🚀' >/dev/null
commit_polylang_source 'conformance: competing Polylang translation intent'
wp_conf2 post update "$TARGET_FR" --post_title='Target competing French title' >/dev/null
CONFLICT_BEFORE=$(polylang_target_hash)
CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang competing branch plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Polylang managed divergence did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
[ "$CONFLICT_RC" -ne 0 ] && grep -qi conflict <<<"$CONFLICT_OUT" \
  || fail "Polylang unforced conflict did not refuse: $CONFLICT_OUT"
[ "$(polylang_target_hash)" = "$CONFLICT_BEFORE" ] || fail 'Polylang unforced conflict partially mutated target state'
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang forced competing branch apply' json "$FORCED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.conflict >= 1' <<<"$FORCED" >/dev/null \
  || fail "Polylang forced repository choice did not converge: $FORCED"
CONVERGED=$(observe_polylang conf2)
jq -e '
  .posts.fr.title == "Repository competing French title 東京 🚀" and
  .runtime.undeclared_neighbor == "target-only-preserved"
' <<<"$CONVERGED" >/dev/null || fail "Polylang conflict recovery lost repository or target runtime state: $CONVERGED"
pass 'dirty translation conflicts refuse atomically; explicit authority converges without crossing runtime state'

# Change the default language and suppress the raw theme-mod write in a real
# MU hook. Authored intent must survive for retry, but publication must remain
# incomplete until every provider postcondition verifies.
wp_conf1 eval '
  $option=get_option("polylang"); $option["default_lang"]="fr";
  $option["sync"]=["post_date","post_meta","taxonomies"];
  update_option("polylang",$option);
' >/dev/null
commit_polylang_source 'conformance: Polylang provider-fault recovery intent'
FAULT_HOOK="$CONF_REPO2/.tmp-polylang-provider-fault.php"
cat > "$FAULT_HOOK" <<'PHPEOF'
<?php
add_filter('pre_update_option_theme_mods_twentytwentyone', static function ($new, $old) {
    return is_file(WP_CONTENT_DIR . '/.duo-polylang-provider-fault') ? $old : $new;
}, 10, 2);
PHPEOF
$COMPOSE run --rm -T --user=0 cli2 sh -c '
  install -m 0644 /siterepo/.tmp-polylang-provider-fault.php /var/www/html/wp-content/mu-plugins/duo-polylang-provider-fault.php
  touch /var/www/html/wp-content/.duo-polylang-provider-fault
' || fail 'could not install the Polylang provider fault hook into the disposable target volume'
rm -f "$FAULT_HOOK"
FAILURE_REV_BEFORE=$(wp_conf2 eval 'echo (string)\Duo\Ledger::kv_get("applied_revision");')
FAULT_REWRITE_BEFORE=$(wp_conf2 db query "SELECT SHA2(option_value,256) FROM wp_options WHERE option_name='rewrite_rules'" --skip-column-names)
require_observed_nonempty 'Polylang provider-fault precondition rewrite fingerprint' "$FAULT_REWRITE_BEFORE"
FAULT_RC=0
FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || FAULT_RC=$?
require_duo_answered 'Polylang provider retained-write failure' human "$FAULT_OUT"
[ "$FAULT_RC" -ne 0 ] && grep -q "provider 'polylang-nav-menus' capability 'synchronize_runtime' failed" <<<"$FAULT_OUT" \
  || fail "Polylang retained provider write did not refuse exactly: $FAULT_OUT"
[ "$(wp_conf2 eval 'echo (string)\Duo\Ledger::kv_get("applied_revision");')" = "$FAILURE_REV_BEFORE" ] \
  || fail 'Polylang provider failure advanced applied_revision'
[ "$(wp_conf2 eval 'echo null===\Duo\Ledger::kv_get("apply_in_progress")?"clear":"retained";')" = retained ] \
  || fail 'Polylang provider failure did not retain retry authority'
[ "$(wp_conf2 eval '$o=get_option("polylang"); echo $o["default_lang"];')" = fr ] \
  || fail 'Polylang provider failure did not retain post-commit authored intent'
[ "$(wp_conf2 option get duo_polylang_undeclared_neighbor)" = target-only-preserved ] \
  || fail 'Polylang provider failure crossed the unrelated target option boundary'
[ "$(wp_conf2 db query "SELECT SHA2(option_value,256) FROM wp_options WHERE option_name='rewrite_rules'" --skip-column-names)" = "$FAULT_REWRITE_BEFORE" ] \
  || fail 'Polylang projection failure ran rewrite generation despite the preceding action refusal'
$COMPOSE run --rm -T --user=0 cli2 rm -f \
  /var/www/html/wp-content/.duo-polylang-provider-fault \
  /var/www/html/wp-content/mu-plugins/duo-polylang-provider-fault.php \
  || fail 'could not remove the Polylang provider fault hook from the disposable target volume'
RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang provider retry after exact repair' json "$RETRY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?; .source == "provider:polylang-nav-menus/synchronize_runtime" and
    .verified == true and .after.default_category_language == "fr" and .after.nav_menu_locations_count >= 1) and
  any(.actions[]?; .source == "native:rewrite.flush" and .verified == true and
    .after.rules_present == true and .after.rules_count > 0)
' <<<"$RETRY" >/dev/null || fail "Polylang projection/native rewrite retry did not consume durable intent: $RETRY"
RETRIED=$(observe_polylang conf2)
jq -e '.options.default_lang == "fr" and .default_category.language == "fr" and .theme_locations.primary == .menus.fr.id' <<<"$RETRIED" >/dev/null \
  || fail "Polylang provider retry did not converge native projections: $RETRIED"
pass 'post-commit projection failure retains intent/authority and exact repair retries plugin and rewrite effects'

# Two apply processes compete over one translated title. At least one must
# succeed; the other may only stop at the named promotion lock.
wp_conf1 post update "$SOURCE_FR" --post_title='Concurrent Polylang intent 東京 🚀' >/dev/null
commit_polylang_source 'conformance: concurrent Polylang apply intent'
CONCURRENT_A="$CONF_REPO2/.tmp-polylang-concurrent-a.log"
CONCURRENT_B="$CONF_REPO2/.tmp-polylang-concurrent-b.log"
set +e
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing Polylang applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing Polylang apply lacked clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing Polylang apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_polylang conf2)
jq -e '.posts.fr.title == "Concurrent Polylang intent 東京 🚀" and .default_category.language == "fr"' <<<"$CONCURRENT" >/dev/null \
  || fail "competing Polylang applies lost authored or derived intent: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "competing Polylang applies left retained work: $CONCURRENT_PLAN"
pass 'competing Polylang applies serialize and leave one exact idempotent multilingual result'

# Deploy repairs deactivation. Polylang's documented default uninstall keeps
# all data unless PLL_REMOVE_ALL_DATA is explicitly true, so exercise both the
# retained-data reinstall and the opt-in destructive recovery branch.
wp_conf2 plugin deactivate polylang >/dev/null
wp_conf2 plugin is-active polylang >/dev/null 2>&1 && fail 'Polylang deactivation premise did not land'
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang deploy after deactivation' json "$REACTIVATE"
wp_conf2 plugin is-active polylang >/dev/null || fail 'Duo deploy did not reactivate exact Polylang code'
wp_conf2 plugin deactivate polylang >/dev/null
wp_conf2 plugin uninstall polylang >/dev/null
wp_conf2 plugin is-installed polylang >/dev/null 2>&1 && fail 'Polylang default uninstall left plugin code installed'
DEFAULT_RESIDUE=$(wp_conf2 eval '
  global $wpdb;
  $option = get_option("polylang");
  echo wp_json_encode([
    "default_lang" => is_array($option) ? ($option["default_lang"] ?? null) : null,
    "language_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = '\''language'\''"),
    "translation_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('\''post_translations'\'', '\''term_translations'\'')"),
    "switcher_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '\''_pll_menu_item'\''"),
    "widget_exists" => false !== get_option("widget_polylang", false),
  ]);
' | awk 'NF { line=$0 } END { print line }')
jq -e '
  .default_lang == "fr" and .language_rows >= 3 and
  .translation_rows >= 2 and .switcher_rows >= 1 and .widget_exists == true
' <<<"$DEFAULT_RESIDUE" >/dev/null \
  || fail "Polylang default uninstall did not retain its native state exactly: $DEFAULT_RESIDUE"
[ "$(wp_conf2 option get duo_polylang_undeclared_neighbor)" = target-only-preserved ] \
  || fail 'Polylang default uninstall mutated an unrelated target option'
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Polylang code did not refuse at compatibility: $MISSING_OUT"
POLYLANG_SHA=dd2a213d407c6d565eb5e246e68b434003f1112c059ee53ca070bf97102010aa
POLYLANG_ARTIFACT="/artifacts-cache/plugin-polylang-3.8.6-${POLYLANG_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256','$POLYLANG_ARTIFACT');")" = "$POLYLANG_SHA" ] \
  || fail 'cached Polylang reinstall artifact digest moved'
wp_conf2 plugin install "$POLYLANG_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get polylang --field=version)" = 3.8.6 ] || fail 'Polylang exact reinstall reported wrong version'
RESIDUE_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang deploy after retained-data reinstall' json "$RESIDUE_DEPLOY"
RESIDUE_OBSERVED=$(observe_polylang conf2)
jq -e '
  .version == "3.8.6" and .options.default_lang == "fr" and
  .posts.fr.title == "Concurrent Polylang intent 東京 🚀" and
  .default_category.language == "fr" and .theme_locations.primary == .menus.fr.id
' <<<"$RESIDUE_OBSERVED" >/dev/null \
  || fail "Polylang retained-data reinstall did not preserve native behavior: $RESIDUE_OBSERVED"
RESIDUE_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$RESIDUE_PLAN" >/dev/null \
  || fail "Polylang retained-data reinstall was not idempotent: $RESIDUE_PLAN"

REMOVE_ALL_HOOK="$CONF_REPO2/.tmp-polylang-remove-all.php"
cat > "$REMOVE_ALL_HOOK" <<'PHPEOF'
<?php
defined('PLL_REMOVE_ALL_DATA') || define('PLL_REMOVE_ALL_DATA', true);
PHPEOF
$COMPOSE run --rm -T --user=0 cli2 install -m 0644 \
  /siterepo/.tmp-polylang-remove-all.php \
  /var/www/html/wp-content/mu-plugins/duo-polylang-remove-all.php \
  || fail 'could not install the Polylang complete-uninstall control'
rm -f "$REMOVE_ALL_HOOK"
wp_conf2 plugin deactivate polylang >/dev/null
wp_conf2 plugin uninstall polylang >/dev/null
wp_conf2 plugin is-installed polylang >/dev/null 2>&1 && fail 'Polylang complete uninstall left plugin code installed'
DESTRUCTIVE_RESIDUE=$(wp_conf2 eval '
  global $wpdb;
  echo wp_json_encode([
    "option_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ('\''polylang'\'', '\''widget_polylang'\'')"),
    "taxonomy_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('\''language'\'', '\''term_language'\'', '\''post_translations'\'', '\''term_translations'\'')"),
    "switcher_rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '\''_pll_menu_item'\''"),
    "neighbor" => get_option("duo_polylang_undeclared_neighbor"),
  ]);
' | awk 'NF { line=$0 } END { print line }')
jq -e '.option_rows == 0 and .taxonomy_rows == 0 and .switcher_rows == 0 and .neighbor == "target-only-preserved"' \
  <<<"$DESTRUCTIVE_RESIDUE" >/dev/null \
  || fail "Polylang complete uninstall left owned data or crossed its boundary: $DESTRUCTIVE_RESIDUE"
$COMPOSE run --rm -T --user=0 cli2 rm -f \
  /var/www/html/wp-content/mu-plugins/duo-polylang-remove-all.php \
  || fail 'could not remove the Polylang complete-uninstall control'
wp_conf2 plugin install "$POLYLANG_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get polylang --field=version)" = 3.8.6 ] || fail 'Polylang complete-uninstall reinstall reported wrong version'
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang deploy after complete uninstall and exact reinstall' json "$REINSTALL_DEPLOY"
SOURCE_MEDIA_EN=$(jq -r '.attachments.en' <<<"$SOURCE_IDS")
SOURCE_MEDIA_FR=$(jq -r '.attachments.fr' <<<"$SOURCE_IDS")
SOURCE_MEDIA_AR=$(jq -r '.attachments.ar' <<<"$SOURCE_IDS")
SOURCE_SWITCHER=$(jq -r '.switcher_item' <<<"$SOURCE_IDS")
SOURCE_LANGUAGE_FR=$(jq -r '.language_terms.fr' <<<"$SOURCE_IDS")
SOURCE_LANGUAGE_AR=$(jq -r '.language_terms.ar' <<<"$SOURCE_IDS")
require_fixture_ids SOURCE_MEDIA_EN SOURCE_MEDIA_FR SOURCE_MEDIA_AR SOURCE_SWITCHER SOURCE_LANGUAGE_FR SOURCE_LANGUAGE_AR
wp_conf1 eval '
  foreach (['"$SOURCE_MEDIA_EN"' => "en", '"$SOURCE_MEDIA_FR"' => "fr", '"$SOURCE_MEDIA_AR"' => "ar"] as $id => $language) {
    update_post_meta($id, "_wp_attachment_image_alt", "Recovered attachment $language 東京 🚀");
  }
  foreach (['"$SOURCE_LANGUAGE_FR"' => "Français récupéré 東京", '"$SOURCE_LANGUAGE_AR"' => "العربية المستعادة 🚀"] as $id => $name) {
    $updated = wp_update_term($id, "language", ["name" => $name]);
    if (is_wp_error($updated)) { throw new RuntimeException($updated->get_error_message()); }
  }
  $switcher = get_post_meta('"$SOURCE_SWITCHER"', "_pll_menu_item", true);
  if (!is_array($switcher)) { throw new RuntimeException("source switcher settings missing during lifecycle recovery"); }
  $switcher["hide_current"] = 1;
  update_post_meta('"$SOURCE_SWITCHER"', "_pll_menu_item", $switcher);
  $widgets = get_option("widget_polylang");
  $sidebars = get_option("sidebars_widgets");
  $first = is_array($sidebars) ? ($sidebars["sidebar-1"][0] ?? null) : null;
  if (!is_array($widgets) || !is_string($first) || preg_match("/^polylang-([1-9][0-9]*)$/", $first, $match) !== 1
      || !is_array($widgets[(int) $match[1]] ?? null)) {
    throw new RuntimeException("source assigned widget settings missing during lifecycle recovery");
  }
  $widgets[(int) $match[1]]["title"] = "Recovered language switcher 東京 🚀";
  update_option("widget_polylang", $widgets);
  $option = get_option("polylang");
  if (!is_array($option)) { throw new RuntimeException("source Polylang option missing during lifecycle recovery"); }
  $option["sync"] = ["post_meta", "taxonomies"];
  update_option("polylang", $option);
' >/dev/null
commit_polylang_source 'conformance: Polylang complete-uninstall recovery intent'
REINSTALL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang complete-uninstall recovery plan' json "$REINSTALL_PLAN"
jq -e '(.drift | length) == 0 and (.conflict | length) >= 8' <<<"$REINSTALL_PLAN" >/dev/null \
  || fail "Polylang destructive lifecycle did not surface explicit recovery work: $REINSTALL_PLAN"
REINSTALL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --adopt-by-slug=terms,posts --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Polylang exact reinstall recovery apply' json "$REINSTALL_APPLY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$REINSTALL_APPLY" >/dev/null \
  || fail "Polylang exact reinstall did not recover canonical state: $REINSTALL_APPLY"
RECOVERED=$(observe_polylang conf2)
jq -e '
  .version == "3.8.6" and .options.default_lang == "fr" and
  .options.sync == ["post_meta","taxonomies"] and
  .posts.fr.title == "Concurrent Polylang intent 東京 🚀" and
  .attachments.en.alt == "Recovered attachment en 東京 🚀" and
  .attachments.fr.alt == "Recovered attachment fr 東京 🚀" and
  .attachments.ar.alt == "Recovered attachment ar 東京 🚀" and
  .language_terms.fr.name == "Français récupéré 東京" and
  .language_terms.ar.name == "العربية المستعادة 🚀" and
  .switcher.settings.hide_current == 1 and
  .widgets["1"].title == "Recovered language switcher 東京 🚀" and
  .default_category.language == "fr" and .theme_locations.primary == .menus.fr.id and
  .runtime.undeclared_neighbor == "target-only-preserved"
' <<<"$RECOVERED" >/dev/null || fail "Polylang native state did not recover after exact reinstall: $RECOVERED"
RECOVERY_URL=$(jq -r '.posts.ar.permalink' <<<"$RECOVERED")
RECOVERY_URL=${RECOVERY_URL/\/\/localhost\//\/\/localhost:${CONF2_PORT}\/}
RECOVERY_FRONT=$(curl -fsSL "$RECOVERY_URL") || fail 'Polylang recovered Arabic page did not render'
grep -Fq 'محتوى عربي قابل للنقل' <<<"$RECOVERY_FRONT" || fail 'Polylang recovered frontend did not consume Arabic authored data'
FINAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "Polylang recovery was not idempotent: $FINAL_PLAN"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-polylang-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-polylang-final" \
  || fail 'Polylang final recovered state was not byte-identical'
rm -rf "$CONF_REPO2/.tmp-polylang-final"
pass 'deactivate/reactivate, default uninstall residue, absent-code refusal, complete uninstall, exact reinstall and final native recovery are clean'

echo 'polylang conformance checks passed'
