#!/usr/bin/env bash
# issue #3207: supported core entities preserve behavior, not merely rows.
set -euo pipefail
cd "$(dirname "$0")/../.."

export WPRISM_PAIR=codexmac3207 WPRISM_PORT1=8900 WPRISM_PORT2=8901
COMPOSE="docker compose -p wprism-codexmac3207 -f pair.yml"
R1="siterepo/${WPRISM_PAIR}1"
R2="siterepo/${WPRISM_PAIR}2"
wp1() { $COMPOSE run --rm -T cli1 wp --require=/siterepo/core-semantics-register.php "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp --require=/siterepo/core-semantics-register.php "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
bash bin/pair.sh reset "$WPRISM_PAIR"
bash bin/pair.sh up "$WPRISM_PAIR" "$WPRISM_PORT1" "$WPRISM_PORT2" --headless
cp tests/fixtures/core_semantics_register.php "$R1/core-semantics-register.php"
cp tests/fixtures/core_semantics_register.php "$R2/core-semantics-register.php"

for side in 1 2; do
  cp /dev/null "siterepo/${WPRISM_PAIR}${side}/.gitignore"
  cat > "siterepo/${WPRISM_PAIR}${side}/site.wprism.json" <<'JSON'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "term_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag", "wprism_ordered"]
  },
  "spec_version": 2
}
JSON
done

cleanup() {
  rm -rf "$R1/state-check"
  [ -z "${PROTECTED_PLAN_ERR:-}" ] || rm -f "$PROTECTED_PLAN_ERR"
}
trap cleanup EXIT

wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 option update timezone_string Asia/Dhaka >/dev/null
wp2 option update timezone_string Asia/Dhaka >/dev/null

TERM_ID=$(wp1 term create wprism_ordered 'Ordered Term' --slug=ordered-term --porcelain)
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
  wp_set_object_terms($POST_ID, [$TERM_ID], 'wprism_ordered');
  \$tt = \$wpdb->get_var(\$wpdb->prepare(
    'SELECT term_taxonomy_id FROM ' . \$wpdb->term_taxonomy . ' WHERE term_id=%d AND taxonomy=%s',
    $TERM_ID,
    'wprism_ordered'
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
imagepng($im, '/tmp/wprism-core-semantics.png');
PHP
ATT_ID=$($COMPOSE run --rm -T cli1 sh -lc 'wp eval-file /siterepo/.tmp-image.php && wp media import /tmp/wprism-core-semantics.png --title="Semantic Image" --porcelain' | tail -1)
rm -f "$R1/.tmp-image.php"

wp1 wprism capture --repo=/siterepo >/dev/null
PROTECTED=$(wp1 post create --post_type=post --post_status=publish --post_title='Protected' --post_name=protected \
  --post_password='not-for-git' --porcelain)
wp1 wprism capture --repo=/siterepo >/dev/null
PROTECTED_UUID=$(wp1 post meta get "$PROTECTED" _wprism_uuid)
PROTECTED_FILE="$R1/state/posts/post/${PROTECTED_UUID}--protected.md"
[ -f "$PROTECTED_FILE" ] || fail "protected post was not captured"
grep -Fq "\"password_binding\": \"post_password:${PROTECTED_UUID}\"" "$PROTECTED_FILE" \
  || fail "protected post did not carry its UUID-derived binding"
! grep -q 'not-for-git' "$PROTECTED_FILE" \
  || fail "protected-post password leaked into canonical state"
printf '%s\n' 'source-local-password' \
  | wp1 wprism env-set --repo=/siterepo --name="post_password:${PROTECTED_UUID}" --stdin >/dev/null
[ "$(wp1 post get "$PROTECTED" --field=post_password)" = 'source-local-password' ] \
  || fail "source environment did not provision its local post password"
pass "password-protected post capture stores only a UUID-derived secret binding"

sync_repo() {
  rm -rf "$R2/state" "$R2/media"
  cp -R "$R1/state" "$R2/state"
  [ ! -d "$R1/media" ] || cp -R "$R1/media" "$R2/media"
}
sync_repo
for name in admin_email home siteurl; do
  value=$(wp2 option get "$name")
  printf '%s\n' "$value" \
    | wp2 wprism env-set --repo=/siterepo --name="$name" --stdin >/dev/null
done
PROTECTED_PLAN_ERR=$(mktemp "${TMPDIR:-/tmp}/wprism-core-semantics-plan.XXXXXX")
set +e
PROTECTED_PLAN=$(wp2 wprism plan --repo=/siterepo --default-author=admin --adopt-by-slug=posts,terms,menus --format=json 2>"$PROTECTED_PLAN_ERR")
PROTECTED_PLAN_RC=$?
set -e
[ "$PROTECTED_PLAN_RC" -eq 0 ] \
  || fail "protected-post plan command failed: stdout=$PROTECTED_PLAN stderr=$(cat "$PROTECTED_PLAN_ERR")"
jq -e --arg name "post_password:${PROTECTED_UUID}" \
  '.env_missing[] | select(.name == $name and .required == true)' <<<"$PROTECTED_PLAN" >/dev/null \
  || fail "target plan did not report the required protected-post binding"
printf '%s\n' 'target-local-password' \
  | wp2 wprism env-set --repo=/siterepo --name="post_password:${PROTECTED_UUID}" --stdin >/dev/null
wp2 wprism apply --repo=/siterepo --default-author=admin --adopt-by-slug=posts,terms,menus >/dev/null

POST2=$(wp2 eval '$p=get_page_by_path("semantic-post", OBJECT, "post"); echo $p ? $p->ID : "";')
FUTURE2=$(wp2 eval '$p=get_page_by_path("scheduled-semantic", OBJECT, "post"); echo $p ? $p->ID : "";')
MENU_ITEM2=$(wp2 eval 'global $wpdb; echo $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type=\"nav_menu_item\" AND post_title=\"Described Item\"");')
ATT2=$(wp2 eval '$p=get_page_by_path("semantic-image", OBJECT, "attachment"); echo $p ? $p->ID : "";')
PROTECTED2=$(wp2 eval '$p=get_page_by_path("protected", OBJECT, "post"); echo $p ? $p->ID : "";')
[ -n "$POST2" ] && [ -n "$FUTURE2" ] && [ -n "$MENU_ITEM2" ] && [ -n "$ATT2" ] && [ -n "$PROTECTED2" ] \
  || fail "fresh target is missing a seeded entity"
[ "$(wp2 post get "$PROTECTED2" --field=post_password)" = 'target-local-password' ] \
  || fail "fresh target did not materialize its target-local protected-post password"

TIMES=$(wp2 eval "\$p=get_post($POST2); echo \$p->post_modified . '|' . \$p->post_modified_gmt;")
[ "$TIMES" = '2026-08-09 17:45:00|2026-08-09 11:45:00' ] \
  || fail "local/GMT modified timestamps collapsed: $TIMES"
ORDER=$(wp2 eval "global \$wpdb; echo \$wpdb->get_var('SELECT term_order FROM ' . \$wpdb->term_relationships . ' WHERE object_id=$POST2 AND term_order<>0');")
[ "$ORDER" = 37 ] || fail "ordered relationship did not round-trip: $ORDER"
DESCRIPTION=$(wp2 post get "$MENU_ITEM2" --field=content)
[ "$DESCRIPTION" = 'Portable menu description' ] || fail "menu description lost: $DESCRIPTION"
COUNT=$(wp2 eval '$term=get_term_by("slug", "ordered-term", "wprism_ordered"); echo $term ? $term->count : "missing";')
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
wp1 wprism capture --repo=/siterepo >/dev/null
sync_repo
wp2 wprism apply --repo=/siterepo --default-author=admin >/dev/null
META_WIDTH=$(wp2 eval "\$m=wp_get_attachment_metadata($ATT2); echo \$m['width'] ?? 0;")
[ "$META_WIDTH" = 80 ] || fail "updated attachment bytes did not regenerate metadata: width=$META_WIDTH"

wp2 wprism capture --repo=/siterepo --out=/siterepo/state-check >/dev/null
diff -r "$R2/state" "$R2/state-check" >/dev/null || fail "update-target recapture differs from canonical source"
pass "existing target regenerates updated attachment metadata and recaptures byte-identically"
