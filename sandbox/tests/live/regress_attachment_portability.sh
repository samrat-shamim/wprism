#!/usr/bin/env bash
# issue #3265: attachments own taxonomy relationships, accept provider bytes,
# detect same-id binary replacement, and regenerate responsive metadata.
set -euo pipefail
cd "$(dirname "$0")/../.."

export WPRISM_PAIR=codexmac3265 WPRISM_PORT1=8964 WPRISM_PORT2=8965
COMPOSE="docker compose -p wprism-codexmac3265 -f pair.yml"
R1="siterepo/${WPRISM_PAIR}1"
R2="siterepo/${WPRISM_PAIR}2"
wp1() { $COMPOSE run --rm -T cli1 wp --require=/siterepo/attachment-portability-register.php "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp --require=/siterepo/attachment-portability-register.php "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
bash bin/pair.sh reset "$WPRISM_PAIR"
bash bin/pair.sh up "$WPRISM_PAIR" "$WPRISM_PORT1" "$WPRISM_PORT2" --headless
cp tests/fixtures/attachment_portability_register.php "$R1/attachment-portability-register.php"
cp tests/fixtures/attachment_portability_register.php "$R2/attachment-portability-register.php"

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
    "taxonomies": ["category", "post_tag", "wprism_media_tag"]
  },
  "spec_version": 2
}
JSON
done

cleanup() {
  rm -rf "$R1/state-check" "$R1/.state-before-offload-refusal" "$R2/state-check" "$R2/bad-repo"
}
trap cleanup EXIT

wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
TERM_ID=$(wp1 term create wprism_media_tag 'Portable Media' --slug=portable-media --porcelain)

cat > "$R1/.tmp-image.php" <<'PHP'
<?php
$im = imagecreatetruecolor(64, 48);
imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 40, 120, 200));
imagepng($im, '/tmp/wprism-attachment-portability.png');
PHP
ATT_ID=$($COMPOSE run --rm -T cli1 sh -lc 'wp eval-file /siterepo/.tmp-image.php && wp --require=/siterepo/attachment-portability-register.php media import /tmp/wprism-attachment-portability.png --title="Portable Media" --porcelain' | tail -1)
rm -f "$R1/.tmp-image.php"
wp1 eval "wp_set_object_terms($ATT_ID, [$TERM_ID], 'wprism_media_tag');" >/dev/null

wp1 wprism capture --repo=/siterepo >/dev/null
ATT_STATE=$(find "$R1/state/posts/attachment" -name '*--portable-media.md' -print -quit)
[ -n "$ATT_STATE" ] || fail "capture did not write the attachment entity"
grep -q '"wprism_media_tag"' "$ATT_STATE" || fail "attachment taxonomy relationship was absent from canonical state"

sync_repo() {
  rm -rf "$R2/state" "$R2/media"
  cp -R "$R1/state" "$R2/state"
  [ ! -d "$R1/media" ] || cp -R "$R1/media" "$R2/media"
}

sync_repo
wp2 wprism apply --repo=/siterepo --default-author=admin --adopt-by-slug=posts,terms >/dev/null
RELATION=$(wp2 eval '$p=get_page_by_path("portable-media", OBJECT, "attachment"); $slugs=$p ? wp_get_object_terms($p->ID, "wprism_media_tag", ["fields"=>"slugs"]) : []; echo implode(",", $slugs);')
[ "$RELATION" = portable-media ] || fail "target attachment taxonomy relationship mismatch: $RELATION"
WIDTH=$(wp2 eval '$p=get_page_by_path("portable-media", OBJECT, "attachment"); $m=$p ? wp_get_attachment_metadata($p->ID) : []; echo $m["width"] ?? 0;')
[ "$WIDTH" = 64 ] || fail "fresh target attachment metadata was not regenerated: width=$WIDTH"
wp2 wprism plan --repo=/siterepo >/dev/null
pass "attachment taxonomy relationship captures, compiles, applies, and resolves on the target"

mkdir -p "$R2/bad-repo"
cp "$R2/site.wprism.json" "$R2/bad-repo/site.wprism.json"
cp -R "$R2/state" "$R2/bad-repo/state"
cp -R "$R2/media" "$R2/bad-repo/media"
BAD_ATT=$(find "$R2/bad-repo/state/posts/attachment" -name '*--portable-media.md' -print -quit)
TERM_UUID=$(basename "$(find "$R2/bad-repo/state/terms/wprism_media_tag" -name '*--portable-media.json' -print -quit)" | cut -d- -f1-5)
sed -i.bak "s/$TERM_UUID/ffffffff-ffff-7fff-8fff-ffffffffffff/" "$BAD_ATT"
rm -f "$BAD_ATT.bak"
set +e
BAD_OUT=$(wp2 wprism plan --repo=/siterepo/bad-repo 2>&1)
BAD_RC=$?
set -e
[ "$BAD_RC" -ne 0 ] && grep -q 'semantic_delete_reference' <<<"$BAD_OUT" \
  || fail "compiler did not reject an attachment taxonomy reference to an absent term: $BAD_OUT"
pass "compiler gate rejects a dangling attachment taxonomy relationship"

wp1 eval "
  \$file=get_attached_file($ATT_ID);
  \$im=imagecreatetruecolor(80,60);
  imagefilledrectangle(\$im,0,0,79,59,imagecolorallocate(\$im,200,80,40));
  imagepng(\$im,\$file);
" >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
sync_repo
wp2 wprism apply --repo=/siterepo --default-author=admin >/dev/null
WIDTH=$(wp2 eval '$p=get_page_by_path("portable-media", OBJECT, "attachment"); $m=$p ? wp_get_attachment_metadata($p->ID) : []; echo $m["width"] ?? 0;')
[ "$WIDTH" = 80 ] || fail "same-id binary replacement did not regenerate metadata: width=$WIDTH"
wp2 wprism capture --repo=/siterepo --out=/siterepo/state-check >/dev/null
diff -r "$R2/state" "$R2/state-check" >/dev/null || fail "updated attachment target did not recapture byte-identically"
rm -rf "$R2/state-check"
pass "same-id binary replacement updates the target and regenerates responsive metadata"

OFFLOAD_SHA=$(wp1 eval "
  \$file=get_attached_file($ATT_ID);
  \$im=imagecreatetruecolor(96,72);
  imagefilledrectangle(\$im,0,0,95,71,imagecolorallocate(\$im,80,180,90));
  imagepng(\$im,\$file);
  \$bytes=file_get_contents(\$file);
  update_option('wprism_test_offload_fixture_bytes', base64_encode(\$bytes), false);
  update_option('wprism_test_offload_enabled', '0', false);
  unlink(\$file);
  echo hash('sha256', \$bytes);
")
cp -R "$R1/state" "$R1/.state-before-offload-refusal"
set +e
REFUSAL_OUT=$(wp1 wprism capture --repo=/siterepo 2>&1)
REFUSAL_RC=$?
set -e
[ "$REFUSAL_RC" -ne 0 ] \
  && grep -q 'is not present locally and no offload provider supplied bytes via wprism_attachment_capture_source' <<<"$REFUSAL_OUT" \
  || fail "missing local media did not produce the named offload refusal: $REFUSAL_OUT"
diff -r "$R1/.state-before-offload-refusal" "$R1/state" >/dev/null \
  || fail "offload refusal changed the published canonical state"
pass "missing local media without a provider refuses loudly and leaves published state untouched"

wp1 option update wprism_test_offload_enabled 1 >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
[ -f "$R1/media/$OFFLOAD_SHA.png" ] || fail "provider bytes were not published as content-addressed media"
ACTUAL_SHA=$(shasum -a 256 "$R1/media/$OFFLOAD_SHA.png" | awk '{print $1}')
[ "$ACTUAL_SHA" = "$OFFLOAD_SHA" ] || fail "provider media hash mismatch: got $ACTUAL_SHA want $OFFLOAD_SHA"
sync_repo
wp2 wprism apply --repo=/siterepo --default-author=admin >/dev/null
TARGET=$(wp2 eval '$p=get_page_by_path("portable-media", OBJECT, "attachment"); $m=$p ? wp_get_attachment_metadata($p->ID) : []; $f=$p ? get_attached_file($p->ID) : ""; echo ($m["width"] ?? 0) . "|" . ($f && is_file($f) ? hash_file("sha256", $f) : "missing");')
[ "$TARGET" = "96|$OFFLOAD_SHA" ] || fail "provider-backed attachment did not apply/regenerate correctly: $TARGET"
wp2 wprism capture --repo=/siterepo --out=/siterepo/state-check >/dev/null
diff -r "$R2/state" "$R2/state-check" >/dev/null || fail "provider-backed target did not recapture byte-identically"
pass "offload provider bytes capture, apply, regenerate metadata, and recapture byte-identically"
