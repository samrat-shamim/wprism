#!/usr/bin/env bash
# Live regression — issue #3263: ACF fields attached to terms and the options
# page (user arm out of scope per the issue #3262 owner ruling b197dd16 —
# blocked on issue #3268). Two arms, proven differently on purpose:
#
# TERM ARM (end-to-end — upgraded from interpreter-classification-only
#   partway through this issue when issue #3261 landed mid-flight):
#   ACF term-attached fields used to be entirely unclassified (the ordinary
#   unclassified-meta gate fired with no ACF-aware naming at all). This adds
#   Acf::term_meta_rule() (pure reuse of post_meta_rule()'s shadow-key/
#   field-definition machinery — term_meta_map()'s shape is byte-identical
#   to post_meta_map()'s), wired through issue #3262's own meta_rule_for_term()
#   dispatch. At CLAIM time issue #3261 (the term-file `meta` wire format) was
#   In Progress, not Done, so this arm was originally scoped as
#   interpreter-classification-only with a proven loud refusal at capture.
#   issue #3261 merged (PR #55) while this issue was still in flight — rebased
#   onto it rather than shipping documentation that would go stale the
#   moment it merged. Capture.php's build_term() now calls the SAME
#   classify_meta_value() helper issue #3266 built for posts/menu-items, with
#   $termMeta=true routing through meta_rule_for_term() — term_meta_rule()
#   needed zero further engine changes to compose with it. Proven fully
#   end-to-end below: capture -> apply -> cross-environment round-trip ->
#   recapture byte-identical, the same depth as the options arm.
#
# OPTIONS-PAGE ARM (end-to-end — options have an explicit versioned record
#   format): ACF's acf_add_options_page() (the admin-UI
#   registration convenience) does not exist in the free plugin at all
#   (confirmed by reading the installed plugin source: no options-page-
#   functions file, only a PRO upsell preview) — but the underlying value
#   storage is not gated the same way: update_field($key,$val,'option')
#   persists via ACF's ordinary object-type value API regardless (a real
#   free-plugin pattern: acf_form() on a front-end page, a custom admin
#   page, WP-CLI, or a snippet). Storage is individually-stored wp_options
#   rows with a fixed options_/_options_ prefix (options_<field>=value,
#   _options_<field>=shadow pointer to field_<key>) — a third shape,
#   matching neither post/term meta's bare shadow convention nor a single
#   blob. manifests/acf.json's new option_namespaces claim plus
#   Acf::option_rule() (a new, third optional interpreter hook,
#   Policy::meta_rule_for_option()) makes these fields classify, capture,
#   apply like any other authored option. Proven end-to-end for the PRESENT
#   state: pending -> capture -> apply -> cross-environment round-trip ->
#   recapture byte-identical. issue #3279 closes the deletion arm too: when ACF
#   atomically removes the value and shadow rows, the v2 option tombstone for
#   the shadow retains its prior field-key pointer as a hash-bound
#   classification witness. The deleted authored value itself is not
#   retained. RepositoryAuthorization re-runs the current ACF interpreter
#   against that immutable-tree context, so a separately cloned repository
#   can authorize and apply both deletes without capture history.
#
# Dedicated pair, destroyed unconditionally on exit. Capture writes as
# container uid 33, so every later host-side commit/copy/removal first uses
# pair.sh's exact-root repo-host handback, and every refresh clears contents
# in place so Docker's mounted root inode and mode 0777 survive. The historical
# default remains asub3263 for CI; distributed agents must supply their own
# PAIR and explicit ports so this regression never resets another actor's
# sandbox.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${PAIR:-asub3263}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || { echo "FAIL: PAIR '$PAIR' invalid (pair.sh naming: lowercase letters/digits, letter first)" >&2; exit 1; }
if [ "$PAIR" != "asub3263" ] && { [ -z "${PORT1:-}" ] || [ -z "${PORT2:-}" ]; }; then
  echo "FAIL: custom PAIR '$PAIR' requires explicit PORT1 and PORT2" >&2
  exit 1
fi
PORT1="${PORT1:-8970}"
PORT2="${PORT2:-8971}"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
COMPOSE=(docker compose -p "wprism-${PAIR}" -f sandbox/pair.yml)
SITE1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
repo_host() { bash sandbox/bin/pair.sh repo-host "$PAIR" "${1:-both}" >/dev/null; }
clear_repo() {
  local root="$1"
  [ -d "$root" ] && [ ! -L "$root" ] || fail "pair repository root is not an ordinary directory: $root"
  find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  chmod 0777 "$root"
}
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
cleanup() {
  repo_host both >/dev/null 2>&1 || true
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "$SITE1" "$SITE2"
}
trap cleanup EXIT

say "bring up own pair ($PAIR, $PORT1/$PORT2, headless)"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless >/dev/null || fail "pair up failed"
pass "pair up"

say "install ACF (free, wp.org) on side 1 -- fresh, no leftover field groups from exploration"
wp1 plugin install advanced-custom-fields --activate >/dev/null || fail "ACF install failed on side 1"
pass "ACF active on side 1 ($(wp1 plugin get advanced-custom-fields --field=version 2>/dev/null))"

say "site-repo: core + acf manifests, category taxonomy in scope"
repo_host both
clear_repo "$SITE1"
clear_repo "$SITE2"
cat > "$SITE1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "acf"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "acf-field-group", "acf-field"],
    "taxonomies": ["category"]
  },
  "spec_version": 2
}
EOF
cp sandbox/site-repo.gitignore.template "$SITE1/.gitignore"
git -C "$SITE1" init -q
git -C "$SITE1" config user.name wprism
git -C "$SITE1" config user.email wprism@example.test
pass "site-repo scaffolded"

# =====================================================================
# PART A -- term arm
# =====================================================================

say "(A) seed: real category term + ACF term field group (a plain field + a ref-type image field), values set via update_field(...,'term_<id>')"
cat > "$SITE1/.tmp-seed-term.php" <<'PHPEOF'
<?php
$term = wp_insert_term('TermOptions Term', 'category');
if (is_wp_error($term)) { fwrite(STDERR, "term insert failed: " . $term->get_error_message() . "\n"); exit(1); }
$term_id = (int) $term['term_id'];

acf_update_field_group([
    'key' => 'group_term-options_term',
    'title' => 'TermOptions Term Fields',
    'fields' => [],
    'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
    'active' => true,
]);
$gp = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_term-options_term', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
$group_id = $gp ? (int) $gp[0] : 0;
acf_update_field(['key' => 'field_term-options_term_plain', 'label' => 'Plain', 'name' => 'term-options_term_plain', 'type' => 'text', 'parent' => $group_id]);
acf_update_field(['key' => 'field_term-options_term_img', 'label' => 'Img', 'name' => 'term-options_term_img', 'type' => 'image', 'parent' => $group_id, 'return_format' => 'id']);

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$upload_dir = wp_upload_dir();
$filename = trailingslashit($upload_dir['path']) . 'term-options-term.png';
$im = imagecreatetruecolor(8, 8);
imagefilledrectangle($im, 0, 0, 7, 7, imagecolorallocate($im, 10, 90, 200));
imagepng($im, $filename);
imagedestroy($im);
$filetype = wp_check_filetype(basename($filename), null);
$att_id = wp_insert_attachment(['post_mime_type' => $filetype['type'], 'post_title' => 'TermOptionsTermImg', 'post_status' => 'inherit'], $filename);
wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $filename));

update_field('field_term-options_term_plain', 'a plain term value', 'term_' . $term_id);
update_field('field_term-options_term_img', $att_id, 'term_' . $term_id);
echo "term_id=$term_id\n";
PHPEOF
TERM_OUT=$(wp1 eval-file /siterepo/.tmp-seed-term.php 2>&1)
echo "$TERM_OUT"
TERM_ID=$(echo "$TERM_OUT" | grep -o 'term_id=[0-9]*' | cut -d= -f2)
[ -n "$TERM_ID" ] || fail "term seed did not report a term_id"
pass "(A) term $TERM_ID seeded with an ACF plain field and an ACF image-ref field"

say "(A1) capture succeeds end-to-end (issue #3261's wire format is live on main); the ref field resolves to a real {{post:<uuid>}} token in the term's meta"
wp1 wprism capture --repo=/siterepo --format=json >/dev/null || fail "capture failed for the term arm"
TERM_FILE=$(find "$SITE1/state/terms/category" -name '*term-options-term*' | head -1)
[ -n "$TERM_FILE" ] || fail "no term file captured for the seeded category"
python3 -c "
import json
d = json.load(open('$TERM_FILE'))
meta = d.get('meta', {})
plain = meta.get('term-options_term_plain')
shadow_p = meta.get('_term-options_term_plain')
img = meta.get('term-options_term_img')
shadow_i = meta.get('_term-options_term_img')
assert plain == 'a plain term value', f'plain field wrong: {plain!r}'
assert shadow_p == 'field_term-options_term_plain', f'plain shadow wrong: {shadow_p!r}'
assert isinstance(img, str) and img.startswith('{{post:') and img.endswith('}}'), f'expected a resolved post ref token for the term image, got {img!r}'
assert shadow_i == 'field_term-options_term_img', f'img shadow wrong: {shadow_i!r}'
print('plain:', plain)
print('img token:', img)
" || fail "captured term file did not carry the expected ACF term-meta fields"
pass "(A1) term file's meta carries term-options_term_plain (plain), its shadow pointer, and term-options_term_img resolved to a real post ref token, plus its own shadow pointer -- all 4 keys, correctly classified, zero manual policy needed"

say "(A2) commit SITE1 (term arm content), clone to SITE2, apply on the second, independent environment"
repo_host both
git -C "$SITE1" add -A
git -C "$SITE1" commit -qm "asub3263 ACF term-meta fixture" >/dev/null
clear_repo "$SITE2"
cp -R "$SITE1"/. "$SITE2"/
chmod -R a+rwX "$SITE2"
wp2 plugin install advanced-custom-fields --activate >/dev/null || fail "ACF install failed on side 2"
wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms,menus --default-author=admin >/dev/null \
  || fail "apply of the term arm failed on env2"
pass "(A2) apply succeeded on env2"

say "(round-trip) env2's own local term-image attachment id -- detokenization must resolve to THAT local id, not env1's copied number"
ENV2_TERM_ID=$(wp2 term list category --name__like="TermOptions Term" --field=term_id --format=csv 2>/dev/null | tail -1)
[ -n "$ENV2_TERM_ID" ] || fail "term did not apply to env2"
ENV2_TERM_PLAIN=$(wp2 eval 'echo get_field("field_term-options_term_plain", "term_'"$ENV2_TERM_ID"'");')
[ "$ENV2_TERM_PLAIN" = "a plain term value" ] || fail "env2 term plain field is wrong: got '$ENV2_TERM_PLAIN'"
ENV2_TERM_IMG_ID=$(wp2 eval 'echo get_field("field_term-options_term_img", "term_'"$ENV2_TERM_ID"'");')
ENV2_OWN_TERM_ATT_ID=$(wp2 post list --post_type=attachment --title="TermOptionsTermImg" --field=ID)
[ -n "$ENV2_OWN_TERM_ATT_ID" ] || fail "term image attachment did not apply to env2"
[ "$ENV2_TERM_IMG_ID" = "$ENV2_OWN_TERM_ATT_ID" ] \
  || fail "env2's term image field ($ENV2_TERM_IMG_ID) does not match env2's own local attachment id ($ENV2_OWN_TERM_ATT_ID) -- detokenization did not resolve correctly"
pass "round-trip proven: env2's applied term image field resolves to env2's OWN local attachment id ($ENV2_OWN_TERM_ATT_ID), not a raw copied number"

say "(A3) recapture env2 and confirm byte-identical term meta (true round trip, not just 'apply didn't crash')"
wp2 wprism capture --repo=/siterepo --format=json >/dev/null || fail "recapture on env2 failed"
TERM_FILE_2=$(find "$SITE2/state/terms/category" -name '*term-options-term*' | head -1)
[ -n "$TERM_FILE_2" ] || fail "no term file recaptured on env2"
diff <(python3 -c "import json; print(json.load(open('$TERM_FILE'))['meta'])") \
     <(python3 -c "import json; print(json.load(open('$TERM_FILE_2'))['meta'])") \
  || fail "recaptured term meta on env2 differs from env1's original capture (not a clean round trip)"
pass "(A3) recaptured env2 term meta is byte-identical to env1's original capture -- clean round trip"

# =====================================================================
# PART B -- options-page arm (end-to-end)
# =====================================================================

say "(B) seed: ACF field group (ordinary location -- irrelevant for option-type storage) + a plain field + a ref-type image field, values set via update_field(...,'option')"
cat > "$SITE1/.tmp-seed-options.php" <<'PHPEOF'
<?php
acf_update_field_group([
    'key' => 'group_term-options_opts',
    'title' => 'TermOptions Options Fields',
    'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'active' => true,
]);
$gp = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_term-options_opts', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
$group_id = $gp ? (int) $gp[0] : 0;
acf_update_field(['key' => 'field_term-options_tagline', 'label' => 'Tagline', 'name' => 'term-options_tagline', 'type' => 'text', 'parent' => $group_id]);
acf_update_field(['key' => 'field_term-options_logo', 'label' => 'Logo', 'name' => 'term-options_logo', 'type' => 'image', 'parent' => $group_id, 'return_format' => 'id']);

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$upload_dir = wp_upload_dir();
$filename = trailingslashit($upload_dir['path']) . 'term-options-opts-logo.png';
$im = imagecreatetruecolor(8, 8);
imagefilledrectangle($im, 0, 0, 7, 7, imagecolorallocate($im, 200, 90, 10));
imagepng($im, $filename);
imagedestroy($im);
$filetype = wp_check_filetype(basename($filename), null);
$att_id = wp_insert_attachment(['post_mime_type' => $filetype['type'], 'post_title' => 'TermOptionsOptsLogo', 'post_status' => 'inherit'], $filename);
wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $filename));

update_field('field_term-options_tagline', 'WPrism makes WordPress branchable.', 'option');
update_field('field_term-options_logo', $att_id, 'option');
echo "logo_att_id=$att_id\n";
PHPEOF
OPT_OUT=$(wp1 eval-file /siterepo/.tmp-seed-options.php 2>&1)
echo "$OPT_OUT"
LOGO_ATT_ID=$(echo "$OPT_OUT" | grep -o 'logo_att_id=[0-9]*' | cut -d= -f2)
[ -n "$LOGO_ATT_ID" ] || fail "options seed did not report logo_att_id"
pass "(B) options-page tagline + logo set via update_field(...,'option') -- the free-plugin storage path, no acf_add_options_page() involved"

say "(B1) present-state capture succeeds end-to-end; the ref field resolves to a real {{post:<uuid>}} token"
wp1 wprism capture --repo=/siterepo --format=json >/dev/null || fail "capture failed for the options-page arm"
OPTIONS_FILE=$(find "$SITE1/state/options" -name '*.json' | head -1)
[ -n "$OPTIONS_FILE" ] || fail "no options file captured"
python3 -c "
import json
d = json.load(open('$OPTIONS_FILE'))
recs = d['records']
tagline = recs.get('options_term-options_tagline')
shadow_t = recs.get('_options_term-options_tagline')
logo = recs.get('options_term-options_logo')
shadow_l = recs.get('_options_term-options_logo')
assert tagline and tagline['state'] == 'present' and tagline['value'] == 'WPrism makes WordPress branchable.', tagline
assert shadow_t and shadow_t['state'] == 'present' and shadow_t['value'] == 'field_term-options_tagline', shadow_t
assert logo and logo['state'] == 'present', logo
v = logo['value']
assert isinstance(v, str) and v.startswith('{{post:') and v.endswith('}}'), f'expected a resolved post ref token for the logo, got {v!r}'
assert shadow_l and shadow_l['state'] == 'present' and shadow_l['value'] == 'field_term-options_logo', shadow_l
print('tagline:', tagline['value'])
print('logo token:', v)
" || fail "captured options.json did not carry the expected ACF options-page records"
pass "(B1) options.json carries options_term-options_tagline (plain), its shadow pointer, and options_term-options_logo resolved to a real post ref token, plus its own shadow pointer -- all 4 rows, correctly classified, zero manual policy needed"

say "(B2) commit SITE1, clone to SITE2, apply on a second, independent environment"
repo_host both
git -C "$SITE1" add -A
git -C "$SITE1" commit -qm "asub3263 ACF options-page fixture" >/dev/null
# SITE2 already exists from Part A's own env2. Clear its children in place;
# replacing the bind root would leave the running container on a stale inode.
clear_repo "$SITE2"
cp -R "$SITE1"/. "$SITE2"/
chmod -R a+rwX "$SITE2"
wp2 plugin install advanced-custom-fields --activate >/dev/null || fail "ACF install failed on side 2"
# --force-theirs: env2 is REUSED from Part A (already applied to, then
# recaptured in A3), and SITE2 was just refreshed from SITE1's own latest
# commit -- env2's sync bookkeeping correctly sees a real divergence between
# its own A3 recapture and this fresh copy, which this test intentionally
# wants to overwrite (env2 was never independently edited in between; there
# is no real conflicting content to preserve, just this test's own reuse of
# one env across two parts).
wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms,menus --default-author=admin --force-theirs >/dev/null \
  || fail "apply failed on the target environment"
pass "(B2) apply succeeded on env2"

say "(round-trip) env2's own local logo attachment id -- detokenization must resolve to THAT local id, not env1's copied number"
ENV2_TAGLINE=$(wp2 eval 'echo get_option("options_term-options_tagline");')
[ "$ENV2_TAGLINE" = "WPrism makes WordPress branchable." ] || fail "env2 tagline option is wrong: got '$ENV2_TAGLINE'"
ENV2_LOGO_ID=$(wp2 eval 'echo get_option("options_term-options_logo");')
ENV2_OWN_ATT_ID=$(wp2 post list --post_type=attachment --title="TermOptionsOptsLogo" --field=ID)
[ -n "$ENV2_OWN_ATT_ID" ] || fail "logo attachment did not apply to env2"
[ "$ENV2_LOGO_ID" = "$ENV2_OWN_ATT_ID" ] \
  || fail "env2's options_term-options_logo value ($ENV2_LOGO_ID) does not match env2's own local attachment id ($ENV2_OWN_ATT_ID) -- detokenization did not resolve correctly"
if [ "$ENV2_OWN_ATT_ID" = "$LOGO_ATT_ID" ]; then
  echo "note: env1/env2 attachment ids coincided ($LOGO_ATT_ID) -- still correct, just not independently distinguishing"
fi
pass "round-trip proven: env2's applied options_term-options_logo resolves to env2's OWN local attachment id ($ENV2_OWN_ATT_ID), not a raw copied number; the shadow pointer _options_term-options_logo also applied correctly (env2's own get_field() readback would use it)"

say "(B3) recapture env2 and confirm byte-identical options (true round trip, not just 'apply didn't crash')"
wp2 wprism capture --repo=/siterepo --format=json >/dev/null || fail "recapture on env2 failed"
OPTIONS_FILE_2=$(find "$SITE2/state/options" -name '*.json' | head -1)
[ -n "$OPTIONS_FILE_2" ] || fail "no options file recaptured on env2"
diff <(python3 -c "
import json
d = json.load(open('$OPTIONS_FILE'))['records']
print(json.dumps({k: v for k, v in d.items() if k in ('options_term-options_tagline','_options_term-options_tagline')}, sort_keys=True))
") <(python3 -c "
import json
d = json.load(open('$OPTIONS_FILE_2'))['records']
print(json.dumps({k: v for k, v in d.items() if k in ('options_term-options_tagline','_options_term-options_tagline')}, sort_keys=True))
") || fail "recaptured tagline/shadow records differ between env1 and env2 (not a clean round trip)"
pass "(B3) recaptured env2 tagline + shadow records are byte-identical to env1's original capture"

say "(B4) deletion: delete_field() removes both source rows; capture emits self-classifiable tombstones"
wp1 eval "delete_field('field_term-options_tagline', 'option');" >/dev/null || fail "delete_field failed on env1"
STILL_THERE=$(wp1 eval 'echo get_option("options_term-options_tagline", "GONE");')
[ "$STILL_THERE" = "GONE" ] || fail "delete_field did not actually remove the live option (test setup problem, not the fix under test)"
SHADOW_STILL_THERE=$(wp1 eval 'echo get_option("_options_term-options_tagline", "GONE");')
[ "$SHADOW_STILL_THERE" = "GONE" ] || fail "delete_field did not remove the live shadow option"
wp1 wprism capture --repo=/siterepo --format=json >/dev/null || fail "capture refused the ACF options-page deletion"
python3 -c "
import json
d = json.load(open('$OPTIONS_FILE'))
assert d['format'] == 'wprism-options/v2', d['format']
recs = d['records']
value = recs['options_term-options_tagline']
shadow = recs['_options_term-options_tagline']
assert value['state'] == 'deleted' and 'classification_witness' not in value, value
assert shadow['state'] == 'deleted', shadow
assert shadow['classification_witness']['value'] == 'field_term-options_tagline', shadow
assert shadow['classification_witness']['autoload'] in ('yes','no','auto','on','off','auto-on','auto-off'), shadow
assert len(value['expected_hash']) == 64 and len(shadow['expected_hash']) == 64
" || fail "captured ACF deletion tombstones have the wrong v2 witness shape"
pass "(B4) capture succeeds with two explicit tombstones; only the shadow retains the minimum field-key witness"

say "(B5) commit deletion, clone the repository cold, and apply --with-deletes to independent env2"
repo_host both
git -C "$SITE1" add state/options/core.json
git -C "$SITE1" commit -qm "issue #3279 ACF options-page deletion" >/dev/null
clear_repo "$SITE2"
git clone -q --depth 1 "file://$SITE1" "$SITE2" || fail "fresh repository clone for deletion apply failed"
chmod -R a+rwX "$SITE2"
wp2 wprism apply --repo=/siterepo --with-deletes >/dev/null \
  || fail "fresh-clone apply refused or failed to apply ACF option tombstones"
ENV2_TAGLINE_AFTER_DELETE=$(wp2 eval 'echo get_option("options_term-options_tagline", "GONE");')
ENV2_SHADOW_AFTER_DELETE=$(wp2 eval 'echo get_option("_options_term-options_tagline", "GONE");')
[ "$ENV2_TAGLINE_AFTER_DELETE" = "GONE" ] || fail "env2 value row survived --with-deletes"
[ "$ENV2_SHADOW_AFTER_DELETE" = "GONE" ] || fail "env2 shadow row survived --with-deletes"
pass "(B5) a depth-1 cold clone with no prior present commit or capture history authorizes the tombstones and deletes both target rows"

say "(regression) a genuinely unrelated, no-manifest-declares-it WordPress-internal option does not leak into captured state"
# NOT siteurl/blogname -- those are legitimately captured (manifests/core.json
# declares blogname class:authored and siteurl class:env,required:true), so
# their presence is correct, not a leak; asserting their absence was this
# test's own bug on an earlier run, not a real regression. 'cron' is a real,
# always-present WordPress-core option no manifest anywhere declares.
python3 -c "
import json
d = json.load(open('$OPTIONS_FILE_2'))['records']
assert 'cron' not in d, 'a genuinely unrelated, undeclared WordPress-internal option leaked into captured state'
print('no undeclared options leaked')
" || fail "unrelated options regression check failed"
pass "(regression) no undeclared options leaked into captured state"

printf '\n\033[1;32m✔ REGRESS_ACF_TERM_OPTIONS_FIELDS PASSED\033[0m\n'
