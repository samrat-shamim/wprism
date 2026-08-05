#!/usr/bin/env bash
# Spike A — round-trip acceptance:
#   capture(A) is deterministic; apply(B) reproduces A's canonical state
#   byte-for-byte; B's runtime rows are untouched; the side-effect canary is
#   clean; derived state (term counts, rendered pages) is rebuilt on B.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yml"
wp_a() { $COMPOSE run --rm -T cli-a wp "$@"; }
wp_b() { $COMPOSE run --rm -T cli-b wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

[ -d siterepo/a/.git ] || fail "run 'make setup' first"

say "seed authored content on A"
NEWS_ID=$(wp_a term create category News --slug=news --description="Duo news" --porcelain)
wp_a term create post_tag Release --slug=release --porcelain >/dev/null
HOME_ID=$(wp_a post create --post_type=page --post_title=Home --post_name=home --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Welcome to the Duo demo home page.</p><!-- /wp:paragraph -->' --porcelain)
ABOUT_ID=$(wp_a post create --post_type=page --post_title=About --post_name=about --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>About Duo.</p><!-- /wp:paragraph -->' --porcelain)
TEAM_ID=$(wp_a post create --post_type=page --post_title=Team --post_name=team --post_status=publish \
  --post_parent="$ABOUT_ID" --post_content='<!-- wp:paragraph --><p>Our team.</p><!-- /wp:paragraph -->' --porcelain)
BLOG_ID=$(wp_a post create --post_type=page --post_title=Blog --post_name=blog --post_status=publish --porcelain)

cat > siterepo/a/.tmp-makeimg.php <<'EOF'
<?php
$im = imagecreatetruecolor(96, 64);
imagefilledrectangle($im, 0, 0, 95, 63, imagecolorallocate($im, 30, 90, 200));
imagepng($im, '/tmp/duo-logo.png');
echo "made\n";
EOF
ATT_ID=$($COMPOSE run --rm -T cli-a bash -c \
  "wp eval-file /siterepo/.tmp-makeimg.php >/dev/null && wp media import /tmp/duo-logo.png --title='Duo Logo' --alt='Duo logo' --porcelain")
rm -f siterepo/a/.tmp-makeimg.php
UP_URL=$(wp_a eval "echo wp_get_attachment_url($ATT_ID);")

HELLO_CONTENT="<!-- wp:image {\"id\":$ATT_ID,\"sizeSlug\":\"full\",\"linkDestination\":\"none\"} -->
<figure class=\"wp-block-image size-full\"><img src=\"$UP_URL\" alt=\"Duo logo\" class=\"wp-image-$ATT_ID\"/></figure>
<!-- /wp:image -->
<!-- wp:paragraph --><p>Hello from Duo. Read <a href=\"http://localhost:8801/about/\">about us</a>.</p><!-- /wp:paragraph -->"
HELLO_ID=$(wp_a post create --post_type=post --post_title='Hello Duo' --post_name=hello-duo --post_status=publish \
  --post_category="$NEWS_ID" --tags_input=release --post_content="$HELLO_CONTENT" --porcelain)

wp_a menu create Main --porcelain >/dev/null
IT_ABOUT=$(wp_a menu item add-post main "$ABOUT_ID" --porcelain)
wp_a menu item add-post main "$TEAM_ID" --parent-id="$IT_ABOUT" --porcelain >/dev/null
wp_a menu item add-term main category "$NEWS_ID" --porcelain >/dev/null
wp_a menu item add-custom main Contact "http://localhost:8801/contact/" --porcelain >/dev/null
wp_a menu location assign main primary

wp_a option update blogname 'Duo Demo' >/dev/null
wp_a option update blogdescription 'Branchable WordPress' >/dev/null
wp_a option update show_on_front page >/dev/null
wp_a option update page_on_front "$HOME_ID" >/dev/null
wp_a option update page_for_posts "$BLOG_ID" >/dev/null
wp_a option update posts_per_page 7 >/dev/null
wp_a option update default_category "$NEWS_ID" >/dev/null
wp_a option update sticky_posts "[$HELLO_ID]" --format=json >/dev/null
pass "seeded (news=$NEWS_ID home=$HOME_ID about=$ABOUT_ID team=$TEAM_ID att=$ATT_ID hello=$HELLO_ID)"

say "capture A into the site repo"
wp_a duo capture --repo=/siterepo
git -C siterepo/a add -A
git -C siterepo/a -c user.name=duo -c user.email=duo@example.test commit -qm "capture: seeded content on A"
git -C siterepo/a push -q origin main

say "acceptance: capture is deterministic (capture twice, zero diff)"
wp_a duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/a/state siterepo/a/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/a/.tmp-state2
pass "capture-twice diff is empty"

say "clone the repo for env B"
if [ -d siterepo/b/.git ]; then
  git -C siterepo/b checkout -q main && git -C siterepo/b pull -q origin main
else
  rm -rf siterepo/b && git clone -q siterepo/origin.git siterepo/b
fi

say "seed env-local runtime on B (must survive apply untouched)"
wp_b user create visitor visitor@example.test --role=subscriber --user_pass=visitor >/dev/null 2>&1 || true
wp_b transient set duo_probe runtime-value 3600 >/dev/null
wp_b option add some_plugin_state zzz >/dev/null 2>&1 || true
USERS_PRE=$(wp_b db query "SELECT MD5(GROUP_CONCAT(ID,':',user_login ORDER BY ID)) FROM wp_users" --skip-column-names)
TRANSIENT_PRE=$(wp_b transient get duo_probe)
OPT_PRE=$(wp_b option get some_plugin_state)

say "db snapshot (rollback point, orchestrator's job in v0)"
$COMPOSE run --rm -T cli-b bash -c "wp db export /siterepo/.tmp-preapply.sql >/dev/null && echo snapshot ok"

say "apply repo state into B"
REV=$(git -C siterepo/b rev-parse HEAD)
wp_b duo apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --revision="$REV"
wp_b rewrite flush --hard >/dev/null 2>&1 || wp_b rewrite flush

say "acceptance: canonical(B) == canonical(A), byte for byte"
wp_b duo capture --repo=/siterepo --out=/siterepo/.tmp-bstate >/dev/null
diff -r siterepo/a/state siterepo/b/.tmp-bstate || fail "round-trip mismatch between A and B"
rm -rf siterepo/b/.tmp-bstate
pass "canonical state identical across environments"

say "acceptance: B runtime untouched"
USERS_POST=$(wp_b db query "SELECT MD5(GROUP_CONCAT(ID,':',user_login ORDER BY ID)) FROM wp_users" --skip-column-names)
[ "$USERS_PRE" = "$USERS_POST" ] || fail "users table changed"
[ "$(wp_b transient get duo_probe)" = "$TRANSIENT_PRE" ] || fail "transient changed"
[ "$(wp_b option get some_plugin_state)" = "$OPT_PRE" ] || fail "unmanaged option changed"
pass "users, transient, unmanaged option all untouched"

say "acceptance: rendered pages on B"
curl -fs http://localhost:8802/ | grep -q 'Welcome to the Duo demo' || fail "front page does not render Home content"
curl -fs http://localhost:8802/ | grep -q 'Contact' || fail "menu (Contact item) not rendered"
curl -fs http://localhost:8802/category/news/ | grep -q 'Hello Duo' || fail "category archive missing applied post (derived counts not rebuilt?)"
IMG_REL=$(grep -h '"file"' siterepo/a/state/posts/attachment/*.md | sed 's/.*"file": "\([^"]*\)".*/\1/')
curl -fso /dev/null "http://localhost:8802/wp-content/uploads/$IMG_REL" || fail "media binary not materialized on B"
curl -fs http://localhost:8802/hello-duo/ | grep -q 'localhost:8802/about' || fail "internal link not re-bound to B's home URL"
pass "front page, menu, category archive, media, re-bound links all render"

say "acceptance: term counts rebuilt"
NEWS_COUNT=$(wp_b db query "SELECT tt.count FROM wp_term_taxonomy tt JOIN wp_terms t ON t.term_id=tt.term_id WHERE t.slug='news' AND tt.taxonomy='category'" --skip-column-names)
[ "$NEWS_COUNT" = "1" ] || fail "news category count is '$NEWS_COUNT', expected 1"
pass "category count correct"

say "acceptance: re-apply is a no-op and runtime created after apply survives"
HELLO_B_ID=$(wp_b post list --post_type=post --name=hello-duo --field=ID)
wp_b comment create --comment_post_ID="$HELLO_B_ID" --comment_content='local runtime comment' \
  --comment_author=visitor --comment_author_email=visitor@example.test >/dev/null
COMMENTS_PRE=$(wp_b db query "SELECT MD5(GROUP_CONCAT(comment_ID,':',comment_content ORDER BY comment_ID)) FROM wp_comments" --skip-column-names)
APPLIED=$(wp_b duo apply --repo=/siterepo --default-author=admin --json | tail -1 | jq -r '.applied')
[ "$APPLIED" = "0" ] || fail "re-apply applied $APPLIED entities, expected 0"
COMMENTS_POST=$(wp_b db query "SELECT MD5(GROUP_CONCAT(comment_ID,':',comment_content ORDER BY comment_ID)) FROM wp_comments" --skip-column-names)
[ "$COMMENTS_PRE" = "$COMMENTS_POST" ] || fail "comments changed during re-apply"
pass "re-apply: 0 entities, comments intact"

printf '\n\033[1;32m✔ SPIKE A PASSED\033[0m\n'
