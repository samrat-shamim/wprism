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
cat > siterepo/conf1/.tmp-makeimg-yoast.php <<'EOF'
<?php
$im = imagecreatetruecolor(48, 32);
imagefilledrectangle($im, 0, 0, 47, 31, imagecolorallocate($im, 90, 160, 90));
imagepng($im, '/tmp/duo-conf-yoast-og.png');
echo "made\n";
EOF
OG_ID=$($COMPOSE run --rm -T cli-conf1 bash -c \
  "wp eval-file /siterepo/.tmp-makeimg-yoast.php >/dev/null && wp media import /tmp/duo-conf-yoast-og.png --title='Conformance Yoast OG Image' --porcelain")
rm -f siterepo/conf1/.tmp-makeimg-yoast.php

cat > siterepo/conf1/.tmp-yoast-taxmeta-seed.php <<PHP
<?php
WPSEO_Taxonomy_Meta::set_values((int) $CAT_A, 'category', [
    'wpseo_desc' => 'Conformance per-term SEO description.',
    'wpseo_noindex' => 'index',
    'wpseo_opengraph-image-id' => '$OG_ID',
    'wpseo_opengraph-image' => wp_get_attachment_url((int) $OG_ID),
]);
echo "taxonomy meta set for term $CAT_A\n";
PHP
$COMPOSE run --rm -T cli-conf1 wp eval-file /siterepo/.tmp-yoast-taxmeta-seed.php
rm -f siterepo/conf1/.tmp-yoast-taxmeta-seed.php

echo "yoast seed: cat_a=$CAT_A cat_b=$CAT_B post=$POST_ID og_image=$OG_ID"
