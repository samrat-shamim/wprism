#!/usr/bin/env bash
# Core manifest conformance seed: a term, a page, a post carrying an image
# block (exercises block_attrs id rewriting) and a category assignment, plus
# the ref-typed options whitelist (page_on_front, default_category,
# sticky_posts) — a trim of spike A's seed down to what the core manifest
# alone needs to prove. Invoked by conformance/run.sh with wp_conf1/wp_conf2
# and $COMPOSE already exported; runs from the sandbox/ directory.
set -euo pipefail

NEWS_ID=$(wp_conf1 term create category News --slug=news --description="Conformance news" --porcelain)
HOME_ID=$(wp_conf1 post create --post_type=page --post_title=Home --post_name=home --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Welcome to the conformance home page.</p><!-- /wp:paragraph -->' --porcelain)

# DUO-3209: the portable adoption key for hierarchical posts is
# (post_type, slug, parent identity), never slug alone. Two siblings in
# different branches deliberately share the same slug.
BRANCH_A=$(wp_conf1 post create --post_type=page --post_title='Branch A' --post_name=branch-a --post_status=publish --porcelain)
BRANCH_B=$(wp_conf1 post create --post_type=page --post_title='Branch B' --post_name=branch-b --post_status=publish --porcelain)
CHILD_A=$(wp_conf1 post create --post_type=page --post_title='Child A' --post_name=shared-child --post_parent="$BRANCH_A" --post_status=publish --porcelain)
CHILD_B=$(wp_conf1 post create --post_type=page --post_title='Child B' --post_name=shared-child --post_parent="$BRANCH_B" --post_status=publish --porcelain)

# Same-filename re-import across pair.sh resets gets WordPress's collision
# suffix (uploads persist in the webroot volume; the reset only drops the
# DB) — nondeterministic canonical filenames across runs. Fresh DB = the
# old files are orphans; delete our own artifact glob on both sides first.
# See seeds/fse.sh's identical block for the full story (its check greps
# the exact filename, which is how this class of bug surfaced).
for side in conf1 conf2; do
  wp_env "$side" eval 'foreach (glob(wp_upload_dir()["basedir"] . "/*/*/conf-core-logo*.png") as $f) { unlink($f); }' >/dev/null
done

cat > "${CONF_REPO1:-siterepo/conf1}"/.tmp-makeimg.php <<'EOF'
<?php
$im = imagecreatetruecolor(64, 48);
imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 60, 140, 60));
imagepng($im, '/tmp/conf-core-logo.png');
echo "made\n";
EOF
ATT_ID=$($COMPOSE run --rm -T cli1 bash -c \
  "wp eval-file /siterepo/.tmp-makeimg.php >/dev/null && wp media import /tmp/conf-core-logo.png --title='Conformance Logo' --alt='Conformance logo' --porcelain")
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-makeimg.php
UP_URL=$(wp_conf1 eval "echo wp_get_attachment_url($ATT_ID);")

HELLO_CONTENT="<!-- wp:image {\"id\":$ATT_ID,\"sizeSlug\":\"full\",\"linkDestination\":\"none\"} -->
<figure class=\"wp-block-image size-full\"><img src=\"$UP_URL\" alt=\"\" class=\"wp-image-$ATT_ID\"/></figure>
<!-- /wp:image -->
<!-- wp:paragraph --><p>Hello from the core conformance seed.</p><!-- /wp:paragraph -->"
HELLO_ID=$(wp_conf1 post create --post_type=post --post_title='Hello Conformance' --post_name=hello-conformance \
  --post_status=publish --post_category="$NEWS_ID" --post_content="$HELLO_CONTENT" --porcelain)

wp_conf1 option update blogname 'Duo Conformance' >/dev/null
wp_conf1 option update show_on_front page >/dev/null
wp_conf1 option update page_on_front "$HOME_ID" >/dev/null
wp_conf1 option update default_category "$NEWS_ID" >/dev/null
wp_conf1 option update sticky_posts "[$HELLO_ID]" --format=json >/dev/null

echo "core seed: news=$NEWS_ID home=$HOME_ID att=$ATT_ID hello=$HELLO_ID hierarchy=$BRANCH_A/$CHILD_A,$BRANCH_B/$CHILD_B"
