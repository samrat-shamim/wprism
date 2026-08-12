#!/usr/bin/env bash
# Yoast manifest conformance seed: a post in two categories (so "primary
# category" is a meaningful choice) carrying the authored Yoast SEO metas —
# title/meta-description/focus-keyword, a cornerstone flag, a robots
# override, and _yoast_wpseo_primary_category pointing at one of the two.
# Yoast's own admin-ajax handler normally sets the primary-category meta from
# the block-editor sidebar; update_post_meta() here reproduces exactly that
# write without needing a UI round-trip. Invoked by conformance/run.sh with
# wp_conf1/wp_conf2/$COMPOSE already exported.
set -euo pipefail

CAT_A=$(wp_conf1 term create category "Conformance Primary" --slug=conformance-primary --porcelain)
CAT_B=$(wp_conf1 term create category "Conformance Secondary" --slug=conformance-secondary --porcelain)

POST_ID=$(wp_conf1 post create --post_type=post --post_title='Conformance Yoast Post' \
  --post_name=conformance-yoast-post --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Content for the Yoast conformance seed, long enough to exercise a readability/SEO score.</p><!-- /wp:paragraph -->' \
  --porcelain)
# DUO-3381: assert the premise before anything consumes it. CAT_A is written
# straight into _yoast_wpseo_primary_category below, and `post meta update
# ... ""` succeeds silently — an empty capture from a load-starved `docker
# compose run` (see the shared conformance/asserts.sh's require_fixture_ids) would leave checks/yoast.sh
# reporting "ref not rebound to conf2's own term id" for a ref this seed
# never authored.
require_fixture_ids CAT_A CAT_B POST_ID
wp_conf1 post term add "$POST_ID" category conformance-primary --by=slug
wp_conf1 post term add "$POST_ID" category conformance-secondary --by=slug

wp_conf1 post meta update "$POST_ID" _yoast_wpseo_title 'Conformance Yoast Post %%sep%% %%sitename%%'
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_metadesc 'A meta description written for the Yoast conformance seed.'
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_focuskw conformance
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_primary_category "$CAT_A"
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_is_cornerstone 1
wp_conf1 post meta update "$POST_ID" _yoast_wpseo_meta-robots-noindex 0

# Per-term SEO override on CAT_A (task #11 wave 2 — key_refs + json_refs on
# wpseo_taxonomy_meta): seeded via WPSEO_Taxonomy_Meta::set_values(),
# Yoast's own documented public API (verified empirically against a running
# install this session, not guessed — see docs/frontier/elementor.md's
# sibling report and manifests/yoast.json's notes for the exact byte shape:
# PHP-serialized {taxonomy: {term_id(int): {wpseo_opengraph-image-id
# (digit STRING), ...}}}). Needs an attachment for the og-image-id field,
# which this seed didn't otherwise create.
#
# Also creates three MORE attachments (task #31 — the wpseo_titles/
# wpseo_social logo/default-image sub-key gap): a company logo, a person
# logo, and a site-wide OG default image — distinct ids so a cross-wired
# json_refs path (e.g. company_logo_id accidentally rewriting person_logo_id)
# would be caught by the round-trip diff rather than coincidentally matching.
cat > "${CONF_REPO1:-siterepo/conf1}"/.tmp-makeimg-yoast.php <<'EOF'
<?php
function duo_conf_yoast_png($path, $r, $g, $b) {
    $im = imagecreatetruecolor(48, 32);
    imagefilledrectangle($im, 0, 0, 47, 31, imagecolorallocate($im, $r, $g, $b));
    imagepng($im, $path);
}
duo_conf_yoast_png('/tmp/duo-conf-yoast-og.png', 90, 160, 90);
duo_conf_yoast_png('/tmp/duo-conf-yoast-company.png', 200, 80, 80);
duo_conf_yoast_png('/tmp/duo-conf-yoast-person.png', 80, 200, 80);
duo_conf_yoast_png('/tmp/duo-conf-yoast-ogdefault.png', 80, 80, 200);
echo "made\n";
EOF
# Same-filename re-import across pair.sh resets gets WordPress's collision
# suffix (uploads persist; reset only drops the DB) — delete our own four
# artifact files on both sides first. See seeds/fse.sh's identical block.
for side in conf1 conf2; do
  wp_env "$side" eval 'foreach (glob(wp_upload_dir()["basedir"] . "/*/*/duo-conf-yoast-*.png") as $f) { unlink($f); }' >/dev/null
done

IMG_IDS=$($COMPOSE run --rm -T cli1 bash -c '
  wp eval-file /siterepo/.tmp-makeimg-yoast.php >/dev/null &&
  echo OG_ID=$(wp media import /tmp/duo-conf-yoast-og.png --title="Conformance Yoast OG Image" --porcelain) &&
  echo COMPANY_LOGO_ID=$(wp media import /tmp/duo-conf-yoast-company.png --title="Conformance Yoast Company Logo" --porcelain) &&
  echo PERSON_LOGO_ID=$(wp media import /tmp/duo-conf-yoast-person.png --title="Conformance Yoast Person Logo" --porcelain) &&
  echo OG_DEFAULT_ID=$(wp media import /tmp/duo-conf-yoast-ogdefault.png --title="Conformance Yoast OG Default Image" --porcelain)
')
OG_ID=$(echo "$IMG_IDS" | grep -oE 'OG_ID=[0-9]+' | cut -d= -f2)
COMPANY_LOGO_ID=$(echo "$IMG_IDS" | grep -oE 'COMPANY_LOGO_ID=[0-9]+' | cut -d= -f2)
PERSON_LOGO_ID=$(echo "$IMG_IDS" | grep -oE 'PERSON_LOGO_ID=[0-9]+' | cut -d= -f2)
OG_DEFAULT_ID=$(echo "$IMG_IDS" | grep -oE 'OG_DEFAULT_ID=[0-9]+' | cut -d= -f2)
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-makeimg-yoast.php
# Four ids parsed out of one container's stdout: any line the container
# failed to emit leaves its id empty here, not at the point of failure.
require_fixture_ids OG_ID COMPANY_LOGO_ID PERSON_LOGO_ID OG_DEFAULT_ID

cat > "${CONF_REPO1:-siterepo/conf1}"/.tmp-yoast-taxmeta-seed.php <<PHP
<?php
WPSEO_Taxonomy_Meta::set_values((int) $CAT_A, 'category', [
    'wpseo_desc' => 'Conformance per-term SEO description.',
    'wpseo_noindex' => 'index',
    'wpseo_opengraph-image-id' => '$OG_ID',
    'wpseo_opengraph-image' => wp_get_attachment_url((int) $OG_ID),
]);
echo "taxonomy meta set for term $CAT_A\n";
PHP
$COMPOSE run --rm -T cli1 wp eval-file /siterepo/.tmp-yoast-taxmeta-seed.php
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-yoast-taxmeta-seed.php

# wpseo_titles (company/person logo) + wpseo_social (site-wide OG default
# image) sub-keys (task #31): seeded via WPSEO_Options::set(), Yoast's own
# public setter — it routes through the SAME sanitize_option_{group} filter
# (WPSEO_Option::validate()) a genuine wp-admin Site Representation / Social
# settings save triggers, so this produces exactly what a real save produces
# (confirmed on e1 by reading raw wp_options bytes before/after: company_logo_id
# and person_logo_id are native PHP ints; og_default_image_id is a native PHP
# int too once set, NOT the digit-string shape wpseo_taxonomy_meta's own
# image-id fields use above — see manifests/yoast.json's notes for the byte-
# level detail). The plain-URL siblings (company_logo/person_logo/
# og_default_image) are set alongside each id, exactly as the real Site
# Representation media picker submits both fields together.
cat > "${CONF_REPO1:-siterepo/conf1}"/.tmp-yoast-options-seed.php <<PHP
<?php
WPSEO_Options::set('company_logo_id', (int) $COMPANY_LOGO_ID);
WPSEO_Options::set('company_logo', wp_get_attachment_url((int) $COMPANY_LOGO_ID));
WPSEO_Options::set('person_logo_id', (int) $PERSON_LOGO_ID);
WPSEO_Options::set('person_logo', wp_get_attachment_url((int) $PERSON_LOGO_ID));
WPSEO_Options::set('og_default_image_id', (int) $OG_DEFAULT_ID);
WPSEO_Options::set('og_default_image', wp_get_attachment_url((int) $OG_DEFAULT_ID));
echo "wpseo_titles/wpseo_social logo+image sub-keys set\n";
PHP
$COMPOSE run --rm -T cli1 wp eval-file /siterepo/.tmp-yoast-options-seed.php
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-yoast-options-seed.php

# Yoast 28.0 lazily replaces wpseo_titles.company_logo_meta=false with an
# image-metadata cache on the first frontend render. Warm that real path on
# conf1 before capture, matching Elementor's generated-state precedent, so
# both environments round-trip the same canonical shape. The cache's nested
# attachment id is declared by manifests/yoast.json; leaving the source cold
# would instead make the target's first ordinary page view look like drift.
CONF1_PORT="${CONF1_PORT:-8806}"
YOAST_FRONT=$(curl -fsSL "http://localhost:${CONF1_PORT}/conformance-yoast-post/") \
  || fail "conf1 front-end render of the seeded Yoast post failed"
require_observed_nonempty "conf1 Yoast seed rendered response" "$YOAST_FRONT"
[ "${#YOAST_FRONT}" -ge 1000 ] \
  || fail "conf1 seeded Yoast post response was suspiciously short (${#YOAST_FRONT} bytes)"
if grep -qiE 'fatal error|uncaught' <<<"$YOAST_FRONT"; then
  fail "conf1 seeded Yoast post contains a PHP fatal error marker"
fi

echo "yoast seed: cat_a=$CAT_A cat_b=$CAT_B post=$POST_ID og_image=$OG_ID company_logo=$COMPANY_LOGO_ID person_logo=$PERSON_LOGO_ID og_default=$OG_DEFAULT_ID"
