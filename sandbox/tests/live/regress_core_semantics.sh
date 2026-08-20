#!/usr/bin/env bash
# DUO-3207: supported core entities preserve behavior, not merely rows.
set -euo pipefail
cd "$(dirname "$0")/../.."

export DUO_PAIR=codexmac3207 DUO_PORT1=8900 DUO_PORT2=8901
COMPOSE="docker compose -p duo-codexmac3207 -f pair.yml"
R1="siterepo/${DUO_PAIR}1"
R2="siterepo/${DUO_PAIR}2"
wp1() { $COMPOSE run --rm -T cli1 wp --require=/siterepo/core-semantics-register.php "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp --require=/siterepo/core-semantics-register.php "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
bash bin/pair.sh reset "$DUO_PAIR"
bash bin/pair.sh up "$DUO_PAIR" "$DUO_PORT1" "$DUO_PORT2" --headless
cp tests/fixtures/core_semantics_register.php "$R1/core-semantics-register.php"
cp tests/fixtures/core_semantics_register.php "$R2/core-semantics-register.php"

for side in 1 2; do
  cp /dev/null "siterepo/${DUO_PAIR}${side}/.gitignore"
  cat > "siterepo/${DUO_PAIR}${side}/site.duo.json" <<'JSON'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "term_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag", "duo_ordered"]
  },
  "spec_version": 2
}
JSON
done

cleanup() {
  rm -rf "$R1/state-check" "$R1/.state-before-protected"
}
trap cleanup EXIT

wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 option update timezone_string Asia/Dhaka >/dev/null
wp2 option update timezone_string Asia/Dhaka >/dev/null

TERM_ID=$(wp1 term create duo_ordered 'Ordered Term' --slug=ordered-term --porcelain)
POST_ID=$(wp1 eval '
  $id = wp_insert_post([
    "post_type" => "post", "post_status" => "publish", "post_title" => "Semantic Post",
    "post_name" => "semantic-post", "post_content" => "semantic body",
  ]);
  global $wpdb;
  $wpdb->update($wpdb->posts, [
    "post_date" => "2026-08-08 09:15:00", "post_date_gmt" => "2026-08-08 03:15:00",
    "post_modified" => "2026-08-09 17:45:00", "post_modified_gmt" => "2026-08-09 11:45:00",
  ], ["ID" => $id]);
  echo $id;
')
wp1 eval "
  global \$wpdb;
  wp_set_object_terms($POST_ID, [$TERM_ID], 'duo_ordered');
  \$tt = \$wpdb->get_var(\$wpdb->prepare(
    'SELECT term_taxonomy_id FROM ' . \$wpdb->term_taxonomy . ' WHERE term_id=%d AND taxonomy=%s',
    $TERM_ID,
    'duo_ordered'
  ));
  if (\$wpdb->update(\$wpdb->term_relationships, ['term_order' => 37], ['object_id' => $POST_ID, 'term_taxonomy_id' => \$tt]) !== 1) {
    throw new RuntimeException('failed to seed ordered relationship');
  }
" >/dev/null

FUTURE_DATA=$(wp1 eval '
  $gmt = gmdate("Y-m-d H:i:s", time() + DAY_IN_SECONDS);
  $id = wp_insert_post([
    "post_type" => "post", "post_title" => "Scheduled Semantic Post",
    "post_name" => "scheduled-semantic", "post_status" => "future",
    "post_date" => get_date_from_gmt($gmt), "post_date_gmt" => $gmt,
  ]);
  echo $id . "|" . $gmt . "|" . strtotime($gmt . " UTC");
')
IFS='|' read -r FUTURE_ID FUTURE_GMT EXPECTED_TS <<<"$FUTURE_DATA"

MENU_ID=$(wp1 eval '
  $menu = wp_create_nav_menu("Semantic Menu");
  $item = wp_update_nav_menu_item($menu, 0, [
    "menu-item-title" => "Described Item", "menu-item-description" => "Portable menu description",
    "menu-item-url" => home_url("/described"), "menu-item-status" => "publish",
  ]);
  echo $menu . ":" . $item;
')
IFS=: read -r MENU_TERM_ID MENU_ITEM_ID <<<"$MENU_ID"

cat > "$R1/.tmp-image.php" <<'PHP'
<?php
$im = imagecreatetruecolor(64, 48);
imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 40, 120, 200));
imagepng($im, '/tmp/duo-core-semantics.png');
PHP
ATT_ID=$($COMPOSE run --rm -T cli1 sh -lc 'wp eval-file /siterepo/.tmp-image.php && wp media import /tmp/duo-core-semantics.png --title="Semantic Image" --porcelain' | tail -1)
rm -f "$R1/.tmp-image.php"

wp1 duo capture --repo=/siterepo >/dev/null
cp -R "$R1/state" "$R1/.state-before-protected"
PROTECTED=$(wp1 post create --post_type=post --post_status=publish --post_title='Protected' --post_name=protected \
  --post_password='not-for-git' --porcelain)
set +e
PROTECTED_OUT=$(wp1 duo capture --repo=/siterepo 2>&1)
PROTECTED_RC=$?
set -e
[ "$PROTECTED_RC" -ne 0 ] && grep -q "protected post 'protected'.*post_password" <<<"$PROTECTED_OUT" \
  || fail "protected post was not explicitly refused: $PROTECTED_OUT"
diff -r "$R1/.state-before-protected" "$R1/state" >/dev/null \
  || fail "protected-post refusal changed the published tree"
wp1 post delete "$PROTECTED" --force >/dev/null
pass "password-protected posts refuse before publication without leaking the password"

sync_repo() {
  rm -rf "$R2/state" "$R2/media"
  cp -R "$R1/state" "$R2/state"
  [ ! -d "$R1/media" ] || cp -R "$R1/media" "$R2/media"
}
sync_repo
wp2 duo apply --repo=/siterepo --default-author=admin --adopt-by-slug=posts,terms,menus >/dev/null

POST2=$(wp2 eval '$p=get_page_by_path("semantic-post", OBJECT, "post"); echo $p ? $p->ID : "";')
FUTURE2=$(wp2 eval '$p=get_page_by_path("scheduled-semantic", OBJECT, "post"); echo $p ? $p->ID : "";')
MENU_ITEM2=$(wp2 eval 'global $wpdb; echo $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type=\"nav_menu_item\" AND post_title=\"Described Item\"");')
ATT2=$(wp2 eval '$p=get_page_by_path("semantic-image", OBJECT, "attachment"); echo $p ? $p->ID : "";')
[ -n "$POST2" ] && [ -n "$FUTURE2" ] && [ -n "$MENU_ITEM2" ] && [ -n "$ATT2" ] \
  || fail "fresh target is missing a seeded entity"

TIMES=$(wp2 eval "\$p=get_post($POST2); echo \$p->post_modified . '|' . \$p->post_modified_gmt;")
[ "$TIMES" = '2026-08-09 17:45:00|2026-08-09 11:45:00' ] \
  || fail "local/GMT modified timestamps collapsed: $TIMES"
ORDER=$(wp2 eval "global \$wpdb; echo \$wpdb->get_var('SELECT term_order FROM ' . \$wpdb->term_relationships . ' WHERE object_id=$POST2 AND term_order<>0');")
[ "$ORDER" = 37 ] || fail "ordered relationship did not round-trip: $ORDER"
DESCRIPTION=$(wp2 post get "$MENU_ITEM2" --field=content)
[ "$DESCRIPTION" = 'Portable menu description' ] || fail "menu description lost: $DESCRIPTION"
COUNT=$(wp2 eval '$term=get_term_by("slug", "ordered-term", "duo_ordered"); echo $term ? $term->count : "missing";')
[ "$COUNT" = 777 ] || fail "registered taxonomy count callback was not invoked: $COUNT"
SCHEDULED=$(wp2 eval "echo wp_next_scheduled('publish_future_post', [$FUTURE2]);")
[ "$SCHEDULED" = "$EXPECTED_TS" ] || fail "future publication event mismatch: expected $EXPECTED_TS got $SCHEDULED"
META_WIDTH=$(wp2 eval "\$m=wp_get_attachment_metadata($ATT2); echo \$m['width'] ?? 0;")
[ "$META_WIDTH" = 64 ] || fail "fresh attachment metadata not generated: width=$META_WIDTH"
pass "fresh target preserves timestamps, ordered terms, menu description, custom recount, cron, and media metadata"

wp1 eval "
  \$file=get_attached_file($ATT_ID);
  \$im=imagecreatetruecolor(80,60);
  imagefilledrectangle(\$im,0,0,79,59,imagecolorallocate(\$im,200,80,40));
  imagepng(\$im,\$file);
  global \$wpdb; \$wpdb->update(\$wpdb->posts,['post_modified'=>'2026-08-10 12:00:00','post_modified_gmt'=>'2026-08-10 06:00:00'],['ID'=>$ATT_ID]);
" >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
sync_repo
wp2 duo apply --repo=/siterepo --default-author=admin >/dev/null
META_WIDTH=$(wp2 eval "\$m=wp_get_attachment_metadata($ATT2); echo \$m['width'] ?? 0;")
[ "$META_WIDTH" = 80 ] || fail "updated attachment bytes did not regenerate metadata: width=$META_WIDTH"

wp2 duo capture --repo=/siterepo --out=/siterepo/state-check >/dev/null
diff -r "$R2/state" "$R2/state-check" >/dev/null || fail "update-target recapture differs from canonical source"
pass "existing target regenerates updated attachment metadata and recaptures byte-identically"
