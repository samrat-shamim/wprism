#!/usr/bin/env bash
# FSE (block theme) manifest conformance seed: exercises the four content
# types docs/frontier/fse.md's exploration found (wp_template,
# wp_template_part, wp_navigation, wp_block) plus the block registry's two
# new capabilities (polymorphic kind dispatch, string-attribute
# tokenization) via core/navigation-link's id+kind+url attributes — the
# report's one real gap. run.sh's "block-theme" setup hook activates
# twentytwentyfive (bundled with core, no `theme install` needed) on both
# conf1 and conf2 before this seed or the apply that follows it; without
# that symmetry the customized "home" wp_template's wp_theme term would be
# inert on conf2 (WP's template resolver silently ignores a template tagged
# for a theme that isn't active — see the report's active-theme-mismatch
# finding).
#
# The navigation carries all three kind_from branches the report's own
# fixture did NOT fully cover: kind:post-type (id -> post ref, closes the
# critical gap), kind:taxonomy (id -> term ref — the report asserted this
# from the schema but never actually seeded one), and kind:custom (no id at
# all, url is a genuine external URL that must NOT be mistaken for a
# same-site link). Invoked by conformance/run.sh with wp_conf1/$COMPOSE
# already exported; runs from the sandbox/ directory.
set -euo pipefail

ABOUT_ID=$(wp_conf1 post create --post_type=page --post_title='Duo FSE About' --post_name=duo-fse-about \
  --post_status=publish --post_content='<!-- wp:paragraph --><p>About the conformance site.</p><!-- /wp:paragraph -->' --porcelain)
CONTACT_ID=$(wp_conf1 post create --post_type=page --post_title='Duo FSE Contact' --post_name=duo-fse-contact \
  --post_status=publish --post_content='<!-- wp:paragraph --><p>Contact the conformance site.</p><!-- /wp:paragraph -->' --porcelain)
ABOUT_URL=$(wp_conf1 post get "$ABOUT_ID" --field=url)
CONTACT_URL=$(wp_conf1 post get "$CONTACT_ID" --field=url)

NEWS_ID=$(wp_conf1 term create category "Conformance FSE News" --slug=conformance-fse-news --porcelain)
NEWS_URL=$(wp_conf1 eval "echo get_term_link((int) $NEWS_ID, 'category');")

# Uploads persist across pair.sh resets (the webroot volume is deliberately
# kept — that's the reset-speed design, docs/sandbox.md), so a re-run's
# `media import` of the same filename gets WordPress's collision suffix
# (conf-fse-cta-1.png), silently changing the canonical src on every repeat
# run and breaking checks/fse.sh's exact-filename grep — surfaced on this
# pair's second-ever fse run (round-3 integration sweep). The freshly-reset
# DB has no attachment rows, so same-named files are orphans by
# construction. Delete OUR OWN artifact glob on both sides before importing
# (conf2 too, so its copy can only exist via apply's media materialization)
# — the ninja-forms seed's delete-your-own-leftovers reset discipline.
for side in conf1 conf2; do
  wp_env "$side" eval 'foreach (glob(wp_upload_dir()["basedir"] . "/*/*/conf-fse-cta*.png") as $f) { unlink($f); }' >/dev/null
done

cat > siterepo/conf1/.tmp-makeimg-fse.php <<'EOF'
<?php
$im = imagecreatetruecolor(64, 48);
imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 140, 60, 160));
imagepng($im, '/tmp/conf-fse-cta.png');
echo "made\n";
EOF
ATT_ID=$($COMPOSE run --rm -T cli1 bash -c \
  "wp eval-file /siterepo/.tmp-makeimg-fse.php >/dev/null && wp media import /tmp/conf-fse-cta.png --title='Conformance FSE CTA Image' --alt='Conformance FSE CTA image' --porcelain")
rm -f siterepo/conf1/.tmp-makeimg-fse.php
ATT_URL=$(wp_conf1 eval "echo wp_get_attachment_url((int) $ATT_ID);")

# A reusable block (wp_block / pattern) containing an image — already-working
# machinery per the report (core/image's existing id rule, core/block's
# existing ref rule); seeded here for completeness of the four-content-type
# fixture, tagged with a wp_pattern_category term like a real saved pattern.
CTA_CONTENT="<!-- wp:image {\"id\":$ATT_ID,\"sizeSlug\":\"full\",\"linkDestination\":\"none\"} -->
<figure class=\"wp-block-image size-full\"><img src=\"$ATT_URL\" alt=\"\" class=\"wp-image-$ATT_ID\"/></figure>
<!-- /wp:image -->"
CTA_ID=$(wp_conf1 post create --post_type=wp_block --post_title='Duo FSE CTA' --post_name=duo-fse-cta \
  --post_status=publish --post_content="$CTA_CONTENT" --porcelain)
wp_conf1 term create wp_pattern_category "Conformance Patterns" --slug=conformance-patterns --porcelain >/dev/null
wp_conf1 post term add "$CTA_ID" wp_pattern_category conformance-patterns --by=slug

# wp_pattern_sync_status (task #31 — the report's own open gap, closed here):
# a Site-Editor-only flag marking a saved pattern "unsynced" (the copy-once,
# edit-independently mode; core registers it on wp_block only, no plugin —
# see manifests/core.json's notes for the verified byte shape: a fresh
# wp_block has ZERO postmeta rows, so "synced" is the key's ABSENCE, not a
# value). It's a flag, not a ref — no rendering assertion is added for it;
# the existing byte-diff/lint gate is what exercises the manifests/core.json
# post_meta.wp_pattern_sync_status:authored declaration.
wp_conf1 post meta update "$CTA_ID" wp_pattern_sync_status unsynced

# The navigation: one link per kind_from branch (post-type/taxonomy/custom).
NAV_CONTENT="<!-- wp:navigation-link {\"label\":\"About\",\"type\":\"page\",\"id\":$ABOUT_ID,\"url\":\"$ABOUT_URL\",\"kind\":\"post-type\"} /-->
<!-- wp:navigation-link {\"label\":\"News\",\"type\":\"category\",\"id\":$NEWS_ID,\"url\":\"$NEWS_URL\",\"kind\":\"taxonomy\"} /-->
<!-- wp:navigation-link {\"label\":\"External\",\"type\":\"custom\",\"url\":\"https://duo-conformance-external.example.test/features\",\"kind\":\"custom\"} /-->"
NAV_ID=$(wp_conf1 post create --post_type=wp_navigation --post_title='Duo FSE Primary Nav' --post_name=duo-fse-primary-nav \
  --post_status=publish --post_content="$NAV_CONTENT" --porcelain)

# A customized "footer" template part: wp_theme + wp_template_part_area terms
# (a genuine taxonomy relationship, not postmeta — the report's own
# overturned-hypothesis finding) plus the "origin" meta a real Site-Editor
# save writes on a from-theme-file customization (empirically: plain
# wp_insert_post does NOT write this key on its own — it's the REST
# controller's doing — so it's set explicitly here to actually exercise the
# post_meta.origin:authored rule rather than leaving it untested).
FOOTER_CONTENT="<!-- wp:paragraph --><p>Contact us: <a href=\"$CONTACT_URL\">Contact</a></p><!-- /wp:paragraph -->"
FOOTER_ID=$(wp_conf1 post create --post_type=wp_template_part --post_title='Footer' --post_name=footer \
  --post_status=publish --post_content="$FOOTER_CONTENT" --porcelain)
wp_conf1 post term add "$FOOTER_ID" wp_theme twentytwentyfive --by=slug
wp_conf1 post term add "$FOOTER_ID" wp_template_part_area footer --by=slug
wp_conf1 post meta update "$FOOTER_ID" origin theme

# The "home" template override: an untouched template-part ref (header — no
# engine work needed, portable slug+theme pair), the customized footer part,
# the navigation, and the reusable block — all four "already works" +
# "the one real gap" surfaces the report identified, in one rendered page.
HOME_CONTENT="<!-- wp:template-part {\"slug\":\"header\",\"theme\":\"twentytwentyfive\"} /-->
<!-- wp:navigation {\"ref\":$NAV_ID} /-->
<!-- wp:block {\"ref\":$CTA_ID} /-->
<!-- wp:template-part {\"slug\":\"footer\",\"theme\":\"twentytwentyfive\"} /-->"
HOME_ID=$(wp_conf1 post create --post_type=wp_template --post_title='Home' --post_name=home \
  --post_status=publish --post_content="$HOME_CONTENT" --porcelain)
wp_conf1 post term add "$HOME_ID" wp_theme twentytwentyfive --by=slug
wp_conf1 post meta update "$HOME_ID" origin theme

echo "fse seed: about=$ABOUT_ID contact=$CONTACT_ID news=$NEWS_ID att=$ATT_ID cta=$CTA_ID nav=$NAV_ID footer=$FOOTER_ID home=$HOME_ID"
