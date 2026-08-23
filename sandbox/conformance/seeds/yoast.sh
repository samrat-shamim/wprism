#!/usr/bin/env bash
# Yoast SEO exact-artifact seed: every authored option/post-meta reference,
# long UTF-8/environment URLs, a real primary term, hierarchy and internal
# links. IDs begin above three million so truncation or integer-width mistakes
# cannot hide behind a tiny fixture. The MU filter is disposable test estate:
# it selects Yoast's documented production indexing branch without changing
# shipped agent/plugin bytes.
set -euo pipefail

SOURCE_REPO="${CONF_REPO1:-siterepo/conf1}"

wp_conf1 eval '
global $wpdb;
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 3100001");
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 3200001");
$wpdb->query("ALTER TABLE {$wpdb->term_taxonomy} AUTO_INCREMENT = 3300001");
if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
    throw new RuntimeException("could not create source MU-plugin directory");
}
$fixture = "<?php\nadd_filter(\"Yoast\\\\WP\\\\SEO\\\\should_index_indexables\", \"__return_true\", 999);\n";
if (file_put_contents(WPMU_PLUGIN_DIR . "/duo-yoast-index-fixture.php", $fixture) === false) {
    throw new RuntimeException("could not install source Yoast index fixture");
}
' >/dev/null

CAT_A=$(wp_conf1 term create category 'Conformance Primary 東京 🚀' --slug=conformance-primary --porcelain)
CAT_B=$(wp_conf1 term create category 'Conformance Secondary' --slug=conformance-secondary --porcelain)
TAG_ID=$(wp_conf1 term create post_tag 'Conformance Search Tag' --slug=conformance-search-tag --porcelain)

make_page() { # <title> <slug> [parent]
  local title="$1" slug="$2" parent="${3:-0}"
  wp_conf1 post create --post_type=page --post_status=publish --post_title="$title" \
    --post_name="$slug" --post_parent="$parent" \
    --post_content="<!-- wp:paragraph --><p>${title} — portable 東京 🚀 at http://localhost:${CONF1_PORT:-8806}/${slug}/</p><!-- /wp:paragraph -->" \
    --porcelain
}

HUB_ID=$(make_page 'Conformance Yoast Hub' conformance-yoast-hub)
CHILD_ID=$(make_page 'Conformance Yoast Child' conformance-yoast-child "$HUB_ID")
ABOUT_ID=$(make_page 'Conformance About' conformance-about)
CONTACT_ID=$(make_page 'Conformance Contact' conformance-contact)
TERMS_ID=$(make_page 'Conformance Terms' conformance-terms)
PRIVACY_ID=$(make_page 'Conformance Privacy' conformance-privacy)
SHOP_ID=$(make_page 'Conformance Shop' conformance-shop)
INCLUDED_A_ID=$(make_page 'Conformance Included A' conformance-included-a)
INCLUDED_B_ID=$(make_page 'Conformance Included B' conformance-included-b)

POST_ID=$(wp_conf1 post create --post_type=post --post_title='Conformance Yoast Post 東京 🚀' \
  --post_name=conformance-yoast-post --post_status=publish \
  --post_content="<!-- wp:paragraph --><p>Long portable SEO content 東京 🚀 linking to <a href=\"http://localhost:${CONF1_PORT:-8806}/conformance-yoast-child/\">the child page</a> and https://example.test/a|b?x=1&amp;y=2.</p><!-- /wp:paragraph -->" \
  --porcelain)
require_fixture_ids CAT_A CAT_B TAG_ID HUB_ID CHILD_ID ABOUT_ID CONTACT_ID TERMS_ID PRIVACY_ID SHOP_ID INCLUDED_A_ID INCLUDED_B_ID POST_ID
wp_conf1 post term add "$POST_ID" category conformance-primary conformance-secondary --by=slug >/dev/null
wp_conf1 post term add "$POST_ID" post_tag conformance-search-tag --by=slug >/dev/null

wp_conf1 post meta update "$POST_ID" _yoast_wpseo_bctitle 'Breadcrumb 東京 🚀 | %%title%%' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_canonical "http://localhost:${CONF1_PORT:-8806}/canonical/東京/?a=1&b=two" >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_focuskw 'portable 東京 search' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_is_cornerstone 1 >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_meta-robots-adv 'noimageindex,nosnippet' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_meta-robots-nofollow 1 >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_meta-robots-noindex 0 >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_metadesc 'Portable meta description 東京 🚀 with delimiters | = : and a long real-world value.' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_opengraph-description 'Portable Open Graph description 東京 🚀.' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_opengraph-title 'Portable OG title %%sep%% %%sitename%%' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_primary_category "$CAT_A" >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_redirect "http://localhost:${CONF1_PORT:-8806}/conformance-yoast-child/?from=seo" >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_title 'Conformance Yoast Post 東京 🚀 %%sep%% %%sitename%%' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_twitter-description 'Portable Twitter description 東京 🚀.' >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_twitter-title 'Portable Twitter title 東京 🚀' >/dev/null

cat > "$SOURCE_REPO/.tmp-makeimg-yoast.php" <<'PHPEOF'
<?php
function duo_conf_yoast_png(string $path, int $red, int $green, int $blue): void {
    $image = imagecreatetruecolor(64, 48);
    imagefilledrectangle($image, 0, 0, 63, 47, imagecolorallocate($image, $red, $green, $blue));
    imagepng($image, $path);
}
$images = [
    'og' => [90, 160, 90], 'twitter' => [160, 90, 160], 'company' => [200, 80, 80],
    'person' => [80, 200, 80], 'default' => [80, 80, 200], 'post' => [220, 170, 40],
];
foreach ($images as $name => $rgb) {
    duo_conf_yoast_png("/tmp/duo-conf-yoast-$name.png", ...$rgb);
}
PHPEOF
for side in conf1 conf2; do
  wp_env "$side" eval 'foreach (glob(wp_upload_dir()["basedir"] . "/*/*/duo-conf-yoast-*.png") as $file) { unlink($file); }' >/dev/null
done

IMG_IDS=$($COMPOSE run --rm -T cli1 bash -c '
  wp eval-file /siterepo/.tmp-makeimg-yoast.php >/dev/null
  for name in og twitter company person default post; do
    id=$(wp media import "/tmp/duo-conf-yoast-${name}.png" --title="Conformance Yoast ${name} Image" --porcelain) || exit
    printf "%s=%s\n" "${name^^}_ID" "$id"
  done
')
rm -f "$SOURCE_REPO/.tmp-makeimg-yoast.php"
OG_ID=$(grep -oE 'OG_ID=[0-9]+' <<<"$IMG_IDS" | cut -d= -f2)
TWITTER_ID=$(grep -oE 'TWITTER_ID=[0-9]+' <<<"$IMG_IDS" | cut -d= -f2)
COMPANY_ID=$(grep -oE 'COMPANY_ID=[0-9]+' <<<"$IMG_IDS" | cut -d= -f2)
PERSON_ID=$(grep -oE 'PERSON_ID=[0-9]+' <<<"$IMG_IDS" | cut -d= -f2)
DEFAULT_ID=$(grep -oE 'DEFAULT_ID=[0-9]+' <<<"$IMG_IDS" | cut -d= -f2)
POST_IMAGE_ID=$(grep -oE 'POST_ID=[0-9]+' <<<"$IMG_IDS" | cut -d= -f2)
require_fixture_ids OG_ID TWITTER_ID COMPANY_ID PERSON_ID DEFAULT_ID POST_IMAGE_ID

wp_conf1 post meta update "$POST_ID" _yoast_wpseo_opengraph-image "$(wp_conf1 post get "$POST_IMAGE_ID" --field=guid)" >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_opengraph-image-id "$POST_IMAGE_ID" >/dev/null
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_twitter-image "$(wp_conf1 post get "$TWITTER_ID" --field=guid)" >/dev/null

cat > "$SOURCE_REPO/.tmp-yoast-native-seed.php" <<PHP
<?php
WPSEO_Taxonomy_Meta::set_values((int) $CAT_A, 'category', [
    'wpseo_desc' => 'Conformance per-term SEO description 東京 🚀.',
    'wpseo_noindex' => 'index',
    'wpseo_opengraph-image-id' => '$OG_ID',
    'wpseo_opengraph-image' => wp_get_attachment_url((int) $OG_ID),
    'wpseo_twitter-image-id' => '$TWITTER_ID',
    'wpseo_twitter-image' => wp_get_attachment_url((int) $TWITTER_ID),
]);
WPSEO_Options::set('company_logo_id', (int) $COMPANY_ID);
WPSEO_Options::set('company_logo', wp_get_attachment_url((int) $COMPANY_ID));
WPSEO_Options::set('person_logo_id', (int) $PERSON_ID);
WPSEO_Options::set('person_logo', wp_get_attachment_url((int) $PERSON_ID));
WPSEO_Options::set('og_default_image_id', (int) $DEFAULT_ID);
WPSEO_Options::set('og_default_image', wp_get_attachment_url((int) $DEFAULT_ID));
WPSEO_Options::set('disableadvanced_meta', true);
WPSEO_Options::set('llms_txt_selection_mode', 'manual');
WPSEO_Options::set('about_us_page', (int) $ABOUT_ID);
WPSEO_Options::set('contact_page', (int) $CONTACT_ID);
WPSEO_Options::set('terms_page', (int) $TERMS_ID);
WPSEO_Options::set('privacy_policy_page', (int) $PRIVACY_ID);
WPSEO_Options::set('shop_page', (int) $SHOP_ID);
WPSEO_Options::set('other_included_pages', [(int) $INCLUDED_A_ID, (int) $INCLUDED_B_ID]);

// Derived analysis scores are deliberately target-local: a hostile target
// value below must survive authored-state convergence while indexables rebuild.
update_post_meta((int) $POST_ID, '_yoast_wpseo_content_score', 'source-derived-91');
update_post_meta((int) $POST_ID, '_yoast_wpseo_estimated-reading-time-minutes', '7');
update_post_meta((int) $POST_ID, '_yoast_wpseo_linkdex', 'source-derived-88');

echo wp_json_encode([
    'about' => (int) WPSEO_Options::get('about_us_page'),
    'contact' => (int) WPSEO_Options::get('contact_page'),
    'included' => array_map('intval', (array) WPSEO_Options::get('other_included_pages')),
    'mode' => (string) WPSEO_Options::get('llms_txt_selection_mode'),
    'primary' => (int) (new WPSEO_Primary_Term('category', (int) $POST_ID))->get_primary_term(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHP
NATIVE_SEED=$(wp_conf1 eval-file /siterepo/.tmp-yoast-native-seed.php)
rm -f "$SOURCE_REPO/.tmp-yoast-native-seed.php"
require_observed_nonempty 'conf1 Yoast native seed result' "$NATIVE_SEED"
NATIVE_JSON=$(printf '%s\n' "$NATIVE_SEED" | awk 'NF { line=$0 } END { print line }')
jq -e --argjson about "$ABOUT_ID" --argjson contact "$CONTACT_ID" --argjson primary "$CAT_A" \
  --argjson included_a "$INCLUDED_A_ID" --argjson included_b "$INCLUDED_B_ID" '
  .mode == "manual" and .about == $about and .contact == $contact and .primary == $primary and
  .included == [$included_a, $included_b]
' <<<"$NATIVE_JSON" >/dev/null || fail "Yoast native settings API did not retain the seed: $NATIVE_JSON"

YOAST_FRONT=$(curl -fsSL "http://localhost:${CONF1_PORT:-8806}/conformance-yoast-post/") \
  || fail 'conf1 front-end render of the seeded Yoast post failed'
require_observed_nonempty 'conf1 Yoast seed rendered response' "$YOAST_FRONT"
[ "${#YOAST_FRONT}" -ge 1000 ] || fail "conf1 seeded Yoast response was suspiciously short (${#YOAST_FRONT} bytes)"
grep -qiE 'fatal error|uncaught' <<<"$YOAST_FRONT" && fail 'conf1 seeded Yoast response contains a fatal marker'

cat > "$SOURCE_REPO/.tmp-yoast-source.json" <<JSON
{"about":$ABOUT_ID,"cat_a":$CAT_A,"cat_b":$CAT_B,"child":$CHILD_ID,"company":$COMPANY_ID,"contact":$CONTACT_ID,"default":$DEFAULT_ID,"hub":$HUB_ID,"included_a":$INCLUDED_A_ID,"included_b":$INCLUDED_B_ID,"og":$OG_ID,"person":$PERSON_ID,"post":$POST_ID,"post_image":$POST_IMAGE_ID,"privacy":$PRIVACY_ID,"shop":$SHOP_ID,"tag":$TAG_ID,"terms":$TERMS_ID,"twitter":$TWITTER_ID}
JSON

echo "yoast seed: $(cat "$SOURCE_REPO/.tmp-yoast-source.json")"
