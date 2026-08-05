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

echo "yoast seed: cat_a=$CAT_A cat_b=$CAT_B post=$POST_ID"
