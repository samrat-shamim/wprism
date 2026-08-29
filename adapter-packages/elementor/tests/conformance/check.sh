#!/usr/bin/env bash
# Exact-artifact Elementor acceptance. Native document/kit APIs, frontend
# HTML, generated CSS, divergent identities, target-owned runtime state, and
# provider 2.0.0's closed postcondition must all agree before the adversarial
# conflict/recovery/deletion/concurrency/lifecycle matrix begins.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"
ELEMENTOR_EXPECTED_VERSION="${ELEMENTOR_EXPECTED_VERSION:-4.2.3}"

observe_elementor() { # <conf1|conf2>
  local side="$1" repo service file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}"; service=cli1 ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}"; service=cli2 ;;
    *) fail "invalid Elementor observation side: $side" ;;
  esac
  file="$repo/.tmp-elementor-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
global $wpdb;

$post = static function (string $slug, string|array $type): ?WP_Post {
    $found = get_page_by_path($slug, OBJECT, $type);
    return $found instanceof WP_Post ? $found : null;
};
$classic = $post('wprism-conformance-elementor-page', 'page');
$deletion = $post('wprism-elementor-deletion-page', 'page');
$target = $post('wprism-elementor-target', 'page');
$template = $post('wprism-portable-section', 'elementor_library');
$atomic = $post('wprism-atomic-elementor-page', 'page');
$hero = $post('wprism-conformance-elementor-hero', 'attachment');
$galleryA = $post('wprism-conformance-elementor-gallery-a', 'attachment');
$galleryB = $post('wprism-conformance-elementor-gallery-b', 'attachment');
$background = $post('wprism-conformance-elementor-bg', 'attachment');
$kitId = (int) get_option('elementor_active_kit');
$kitPost = $kitId > 0 ? get_post($kitId) : null;
if (!$classic || !$target || !$template || !$hero || !$galleryA || !$galleryB || !$background
    || !$kitPost instanceof WP_Post) {
    throw new RuntimeException('Elementor native content fixture is incomplete');
}

$decode = static function (int $postId): array {
    $decoded = json_decode((string) get_post_meta($postId, '_elementor_data', true), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Elementor data is not a decoded array for $postId");
    }
    return $decoded;
};
$classicData = $decode((int) $classic->ID);
$templateData = $decode((int) $template->ID);
$atomicData = $atomic ? $decode((int) $atomic->ID) : [];
$kitSettings = get_post_meta($kitId, '_elementor_page_settings', true);
if (!is_array($kitSettings)) {
    throw new RuntimeException('Elementor active kit settings are not native array data');
}
$libraryTypes = wp_get_object_terms($template->ID, 'elementor_library_type', ['fields' => 'ids']);
if (is_wp_error($libraryTypes) || count($libraryTypes) !== 1) {
    throw new RuntimeException('Elementor library template type relationship is incomplete');
}
$libraryType = get_term((int) $libraryTypes[0], 'elementor_library_type');
if (!$libraryType instanceof WP_Term) {
    throw new RuntimeException('Elementor library template type term is incomplete');
}

$large = 0;
$walk = static function (array $elements) use (&$walk, &$large): void {
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        if (($element['widgetType'] ?? '') === 'heading'
            && str_starts_with((string) ($element['settings']['title'] ?? ''), 'Portable nested heading ')) {
            $large++;
        }
        $children = $element['elements'] ?? [];
        if (is_array($children)) {
            $walk($children);
        }
    }
};
$walk($classicData);

$builderIds = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID " .
    "WHERE p.post_status='publish' AND pm.meta_key=%s AND pm.meta_value=%s ORDER BY p.ID",
    '_elementor_edit_mode',
    'builder'
));
if (!is_array($builderIds) || $wpdb->last_error !== '') {
    throw new RuntimeException('Elementor builder inventory observation failed');
}
$builderIds = array_values(array_map('intval', $builderIds));
$uploads = wp_upload_dir();
$cssDir = rtrim((string) ($uploads['basedir'] ?? ''), '/') . '/elementor/css';
$cssFiles = [];
$postCssIds = [];
if (is_dir($cssDir)) {
    foreach (scandir($cssDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $cssDir . '/' . $entry;
        if (!is_file($path)) {
            throw new RuntimeException('Elementor CSS observation found a non-file entry');
        }
        $cssFiles[$entry] = hash_file('sha256', $path);
        if (preg_match('/^post-([1-9][0-9]*)\.css$/D', $entry, $match) === 1) {
            $postCssIds[] = (int) $match[1];
        }
    }
}
sort($postCssIds, SORT_NUMERIC);
ksort($cssFiles, SORT_STRING);
$fileIds = [];
$emptyIds = [];
$invalidReceipts = 0;
foreach ($builderIds as $builderId) {
    $receipt = get_post_meta($builderId, '_elementor_css', true);
    $status = is_array($receipt) ? ($receipt['status'] ?? null) : null;
    if ($status === 'file') {
        $fileIds[] = $builderId;
    } elseif ($status === 'empty') {
        $emptyIds[] = $builderId;
    } else {
        $invalidReceipts++;
    }
}
$missing = array_values(array_diff($fileIds, $postCssIds));
$unexpectedEmpty = array_values(array_intersect($emptyIds, $postCssIds));
$orphan = array_values(array_diff($postCssIds, $builderIds));
$renderCaches = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_element_cache','_elementor_page_assets')"
);
if ($wpdb->last_error !== '') {
    throw new RuntimeException('Elementor render cache observation failed');
}

$ids = [
    'atomic' => $atomic ? (int) $atomic->ID : 0,
    'background' => (int) $background->ID,
    'classic' => (int) $classic->ID,
    'deletion' => $deletion ? (int) $deletion->ID : 0,
    'gallery_a' => (int) $galleryA->ID,
    'gallery_b' => (int) $galleryB->ID,
    'hero' => (int) $hero->ID,
    'kit' => $kitId,
    'library_type' => (int) $libraryType->term_id,
    'target' => (int) $target->ID,
    'template' => (int) $template->ID,
];
$attachmentIds = array_values(array_intersect_key($ids, array_flip(['background', 'gallery_a', 'gallery_b', 'hero'])));
$attachmentExists = [];
foreach ($attachmentIds as $id) {
    $attachmentExists[(string) $id] = get_post($id) instanceof WP_Post;
}
$checklist = get_option('elementor_checklist', null);
if (is_string($checklist)) {
    $decodedChecklist = json_decode($checklist, true);
    $checklist = is_array($decodedChecklist) ? $decodedChecklist : $checklist;
}

echo wp_json_encode([
    'atomic' => [
        'heading' => $atomicData[0]['elements'][0]['settings']['title']['value']['content']['value'] ?? null,
        'image' => $atomicData[0]['elements'][1]['settings']['image']['value']['src']['value']['id']['value'] ?? null,
    ],
    'classic' => [
        'background' => $classicData[0]['settings']['background_image']['id'] ?? null,
        'data_bytes' => strlen((string) get_post_meta($classic->ID, '_elementor_data', true)),
        'data_hash' => hash('sha256', (string) get_post_meta($classic->ID, '_elementor_data', true)),
        'first_heading' => $classicData[0]['elements'][1]['elements'][1]['settings']['title'] ?? null,
        'gallery' => array_map(
            static fn(array $item): int => (int) ($item['id'] ?? 0),
            (array) ($classicData[0]['elements'][1]['elements'][0]['settings']['wp_gallery'] ?? [])
        ),
        'hero' => $classicData[0]['elements'][0]['elements'][0]['settings']['image']['id'] ?? null,
        'large_headings' => $large,
        'link' => $classicData[0]['elements'][0]['elements'][1]['settings']['link']['url'] ?? null,
        'post_title' => $classic->post_title,
    ],
    'derived' => [
        'builder_ids' => $builderIds,
        'css_files' => count($cssFiles),
        'css_fingerprint' => hash('sha256', serialize($cssFiles)),
        'empty_receipt_ids' => $emptyIds,
        'file_receipt_ids' => $fileIds,
        'invalid_receipts' => $invalidReceipts,
        'missing' => $missing,
        'orphan' => $orphan,
        'post_css_ids' => $postCssIds,
        'render_caches' => $renderCaches,
        'unexpected_empty' => $unexpectedEmpty,
    ],
    'ids' => $ids,
    'kit' => [
        'background' => $kitSettings['background_image']['id'] ?? null,
        'custom_colors' => $kitSettings['custom_colors'] ?? null,
        'fonts' => $kitSettings['default_generic_fonts'] ?? null,
        'gallery' => array_map(
            static fn(array $item): int => (int) ($item['id'] ?? 0),
            (array) ($kitSettings['background_slideshow_gallery'] ?? [])
        ),
        'site_favicon' => $kitSettings['site_favicon']['id'] ?? null,
        'site_logo' => $kitSettings['site_logo']['id'] ?? null,
        'system_colors' => $kitSettings['system_colors'] ?? null,
    ],
    'media_exist' => $attachmentExists,
    'runtime' => [
        'atomic_cache' => get_option('elementor_atomic_cache_validity__global', null),
        'checklist' => $checklist,
        'connect_key' => get_option('elementor_connect_site_key', null),
        'experiment' => get_option('elementor_experiment-e_atomic_elements', null),
        'neighbor' => get_option('elementor_target_undeclared_neighbor', null),
    ],
    'template' => [
        'image' => $templateData[0]['elements'][0]['elements'][0]['settings']['image']['id'] ?? null,
        'library_type' => $libraryType->slug,
        'type' => get_post_meta($template->ID, '_elementor_template_type', true),
    ],
    'version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  out=$($COMPOSE run --rm -T "$service" wp eval-file /siterepo/.tmp-elementor-observe.php)
  rm -f "$file"
  require_observed_nonempty "$side Elementor native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

SOURCE=$(observe_elementor conf1)
TARGET=$(observe_elementor conf2)
SOURCE_IDS=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-elementor-source.json")
TARGET_IDS=$(cat "${CONF_REPO2:-siterepo/conf2}/.tmp-elementor-target.json")
ATOMIC_SUPPORTED=$(jq -r '.atomic_supported' <<<"$SOURCE_IDS")

jq -e --arg version "$ELEMENTOR_EXPECTED_VERSION" '
  .version == $version and
  .classic.data_bytes > 20000 and .classic.large_headings == 128 and
  .classic.first_heading == "Portable nested heading 000 東京 🚀 delimiter |%| {{literal}}" and
  .ids.deletion > 0 and
  .classic.hero == .ids.hero and .classic.background == .ids.background and
  .classic.gallery == [.ids.gallery_a,.ids.gallery_b] and
  .classic.link == ("http://localhost:'"$CONF2_PORT"'/wprism-elementor-target/?from=elementor&encoded=a%2Fb") and
  .template.type == "section" and .template.library_type == "section" and .template.image == .ids.gallery_a and
  .kit.site_logo == .ids.hero and .kit.site_favicon == .ids.gallery_a and
  .kit.background == .ids.background and .kit.gallery == [.ids.gallery_a,.ids.gallery_b] and
  .kit.fonts == "Inter, Arial, sans-serif" and
  (.kit.system_colors | map(select(._id == "primary" and .color == "#123456")) | length) == 1 and
  (.kit.system_colors | map(select(._id == "secondary" and .color == "#654321")) | length) == 1 and
  (.kit.custom_colors | map(select(._id == "wprismcustom" and .title == "WPrism Custom 東京 🚀" and .color == "#abcdef")) | length) == 1 and
  (.media_exist | to_entries | all(.value == true)) and
  .derived.invalid_receipts == 0 and .derived.missing == [] and
  .derived.unexpected_empty == [] and .derived.orphan == [] and .derived.render_caches == 0 and
  .derived.file_receipt_ids == .derived.post_css_ids and
  ((.derived.file_receipt_ids + .derived.empty_receipt_ids) | sort) == .derived.builder_ids and
  .runtime.connect_key == "target-connect-site-key-preserved" and
  .runtime.checklist.completed == ["target-runtime-marker"] and
  .runtime.experiment == "active" and
  .runtime.neighbor == "target-neighbor-preserved"
' <<<"$TARGET" >/dev/null || fail "Elementor authored/runtime/native state did not converge: $TARGET"

if [ "$ATOMIC_SUPPORTED" = true ]; then
  jq -e '
    .ids.atomic > 0 and .atomic.heading == "Atomic portable heading 東京 🚀" and
    .atomic.image == .ids.hero
  ' <<<"$TARGET" >/dev/null || fail "Elementor Atomic document did not converge through its generated prop envelope: $TARGET"
fi

for key in classic deletion target template kit hero gallery_a gallery_b background library_type; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_IDS")
  TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  EXPECTED_TARGET_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_IDS")
  require_fixture_ids SOURCE_ID TARGET_ID EXPECTED_TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Elementor source/target $key identity did not diverge ($SOURCE_ID)"
  [ "$TARGET_ID" = "$EXPECTED_TARGET_ID" ] || fail "Elementor apply replaced rather than adopted hostile target $key"
done
if [ "$ATOMIC_SUPPORTED" = true ]; then
  SOURCE_ATOMIC=$(jq -r '.atomic' <<<"$SOURCE_IDS")
  TARGET_ATOMIC=$(jq -r '.ids.atomic' <<<"$TARGET")
  EXPECTED_TARGET_ATOMIC=$(jq -r '.atomic' <<<"$TARGET_IDS")
  require_fixture_ids SOURCE_ATOMIC TARGET_ATOMIC EXPECTED_TARGET_ATOMIC
  [ "$SOURCE_ATOMIC" != "$TARGET_ATOMIC" ] && [ "$TARGET_ATOMIC" = "$EXPECTED_TARGET_ATOMIC" ] \
    || fail 'Elementor Atomic page did not retain its divergent adopted target identity'
fi

PROVIDER_RECEIPT="${APPLY_JSON:-}"
if [ -z "$PROVIDER_RECEIPT" ] && [ -n "${VMATRIX_APPLY_LOG:-}" ] && [ -f "$VMATRIX_APPLY_LOG" ]; then
  PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
fi
grep -Fq 'elementor-css@2.0.0' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt did not identify Elementor provider 2.0.0: ${PROVIDER_RECEIPT:-<missing>}"
if jq -e 'type == "object"' <<<"$PROVIDER_RECEIPT" >/dev/null 2>&1; then
  jq -e '
    any(.actions[]?;
      .source == "provider:elementor-css/regenerate_css" and .verified == true and
      .after.invalid_css_receipts == 0 and .after.missing_document_css == 0 and
      .after.unexpected_empty_document_css == 0 and .after.orphan_document_css == 0 and
      .after.render_caches == 0 and
      .after.css_receipt_files == .after.post_css_files and
      (.after.css_receipt_files + .after.css_receipt_empty) == .after.builder_documents)
  ' <<<"$PROVIDER_RECEIPT" >/dev/null \
    || fail 'Elementor provider JSON receipt omitted its closed document/CSS projection'
fi
pass 'Elementor documents, media, template and kit references rebound across divergent adopted identities'
pass 'Elementor provider 2.0.0 removed stale/orphan CSS and render caches with a closed readback receipt'

FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/wprism-conformance-elementor-page/") \
  || fail 'conf2 classic Elementor page did not return 200'
require_observed_nonempty "conf2 Elementor rendered response" "$FRONT"
[ "${#FRONT}" -ge 20000 ] || fail "conf2 classic Elementor response was suspiciously short (${#FRONT} bytes)"
grep -qiE 'fatal error|uncaught' <<<"$FRONT" && fail 'conf2 classic Elementor response contains a fatal marker'
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$FRONT" && fail 'conf2 classic Elementor response leaked the source host'
grep -q 'src="http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/wprism-conf-elementor-hero[^"?]*' <<<"$FRONT" \
  || fail 'classic Image widget did not consume the target attachment reference'
grep -q 'wprism-conf-elementor-gallery-a' <<<"$FRONT" && grep -q 'wprism-conf-elementor-gallery-b' <<<"$FRONT" \
  || fail 'classic gallery did not consume both target attachment references'
grep -Fq "href=\"http://localhost:${CONF2_PORT}/wprism-elementor-target/?from=elementor" <<<"$FRONT" \
  || fail 'classic plain-URL internal link did not rebind to the target host'
grep -Fq 'Portable nested heading 000 東京 🚀' <<<"$FRONT" \
  && grep -Fq 'Portable nested heading 127 東京 🚀' <<<"$FRONT" \
  || fail 'large nested UTF-8 Elementor document did not render both boundaries'

PAGE_ID=$(wp_conf2 post list --post_type=page --name=wprism-conformance-elementor-page --field=ID)
require_fixture_ids PAGE_ID
[ "$PAGE_ID" = "$(jq -r '.ids.classic' <<<"$TARGET")" ] \
  || fail 'Elementor rendered-page id read disagrees with the native observation'
CSS=$(curl -fsSL "http://localhost:${CONF2_PORT}/wp-content/uploads/elementor/css/post-${PAGE_ID}.css") \
  || fail "could not fetch conf2 regenerated Elementor CSS for $PAGE_ID"
require_observed_nonempty "conf2 Elementor regenerated CSS" "$CSS"
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$CSS" && fail 'regenerated Elementor CSS leaked the source host'
grep -q 'background-image:url("http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/wprism-conf-elementor-bg' <<<"$CSS" \
  || fail 'classic background image did not regenerate from the target attachment'

if [ "$ATOMIC_SUPPORTED" = true ]; then
  ATOMIC_FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/wprism-atomic-elementor-page/") \
    || fail 'conf2 Atomic Elementor page did not return 200'
  require_observed_nonempty 'conf2 Atomic Elementor rendered response' "$ATOMIC_FRONT"
  grep -Fq 'Atomic portable heading 東京 🚀' <<<"$ATOMIC_FRONT" \
    || fail 'Atomic Heading did not consume its generated html-v3/string envelope'
  grep -q 'wprism-conf-elementor-hero' <<<"$ATOMIC_FRONT" \
    || fail 'Atomic Image did not consume its generated attachment-id envelope'
  grep -Fq "http://localhost:${CONF1_PORT}" <<<"$ATOMIC_FRONT" \
    && fail 'conf2 Atomic Elementor response leaked the source host'
fi
pass 'classic and Atomic frontend rendering consumes target-local URLs, media, large UTF-8 data, and regenerated CSS'

ZERO_PLAN=$($COMPOSE run --rm -T cli2 wp wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "Elementor retry retained work: $ZERO_PLAN"
ZERO_APPLY=$($COMPOSE run --rm -T cli2 wp wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Elementor no-op apply was not clean and idempotent: $ZERO_APPLY"
pass 'Elementor zero-change plan/apply is mutation-free and does not rerun the provider'

if [ "${ELEMENTOR_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "Elementor $ELEMENTOR_EXPECTED_VERSION exact boundary consumed the full portable fixture"
  return 0 2>/dev/null || exit 0
fi

commit_elementor_source() { # <message>
  wp_conf1 wprism capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

elementor_target_hash() {
  observe_elementor conf2 | shasum -a 256 | awk '{print $1}'
}

# A malformed native document, credential-shaped kit setting, and an
# unsupported plugin-owned template tombstone must each refuse before a new
# canonical tree is published. Restore the exact live bytes and prove the
# original tree still recaptures byte-for-byte before continuing.
CAPTURE_BASELINE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
SOURCE_CLASSIC=$(jq -r '.classic' <<<"$SOURCE_IDS")
SOURCE_KIT=$(jq -r '.kit' <<<"$SOURCE_IDS")
SOURCE_TEMPLATE=$(jq -r '.template' <<<"$SOURCE_IDS")
require_fixture_ids SOURCE_CLASSIC SOURCE_KIT SOURCE_TEMPLATE
CLASSIC_DATA=$(wp_conf1 post meta get "$SOURCE_CLASSIC" _elementor_data)
wp_conf1 post meta update "$SOURCE_CLASSIC" _elementor_data '{malformed-elementor-json' >/dev/null
MALFORMED_RC=0
MALFORMED_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || MALFORMED_RC=$?
require_wprism_answered 'Elementor malformed document capture' human "$MALFORMED_OUT"
[ "$MALFORMED_RC" -ne 0 ] && grep -Eqi 'json|structured|elementor_data|decode' <<<"$MALFORMED_OUT" \
  || fail "Elementor malformed document did not refuse: $MALFORMED_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Elementor malformed document refusal partially published canonical state'
wp_conf1 post meta update "$SOURCE_CLASSIC" _elementor_data "$CLASSIC_DATA" >/dev/null

KIT_SETTINGS_BACKUP=$(wp_conf1 eval 'echo base64_encode(serialize(get_post_meta((int)get_option("elementor_active_kit"),"_elementor_page_settings",true)));')
require_observed_nonempty 'Elementor source kit settings backup' "$KIT_SETTINGS_BACKUP"
FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
wp_conf1 eval '
  $kit=(int)get_option("elementor_active_kit");
  $settings=(array)get_post_meta($kit,"_elementor_page_settings",true);
  $settings["custom_colors"][]=["_id"=>"credentialprobe","title"=>"AKIAABCDEFGHIJKLMNOP","color"=>"#000000"];
  update_post_meta($kit,"_elementor_page_settings",$settings);
' >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || SECRET_RC=$?
require_wprism_answered 'Elementor credential-shaped kit capture' human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "Elementor credential-shaped kit setting did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Elementor secret refusal partially published canonical state'
wp_conf1 eval '
  $value=unserialize(base64_decode("'"$KIT_SETTINGS_BACKUP"'"),["allowed_classes"=>false]);
  update_post_meta('"$SOURCE_KIT"',"_elementor_page_settings",$value);
' >/dev/null

wp_conf1 eval '
  global $wpdb;
  if ($wpdb->update($wpdb->posts,["post_status"=>"trash"],["ID"=>'"$SOURCE_TEMPLATE"']) === false) {
    throw new RuntimeException("Elementor template deletion probe failed");
  }
  clean_post_cache('"$SOURCE_TEMPLATE"');
' >/dev/null
DELETE_RC=0
DELETE_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || DELETE_RC=$?
require_wprism_answered 'Elementor unsupported library-template deletion capture' human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -Eqi 'deletion|tombstone|elementor_library|unsupported' <<<"$DELETE_OUT" \
  || fail "Elementor library-template deletion did not refuse: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Elementor unsupported template deletion partially published canonical state'
wp_conf1 eval '
  global $wpdb;
  if ($wpdb->update($wpdb->posts,["post_status"=>"publish"],["ID"=>'"$SOURCE_TEMPLATE"']) === false) {
    throw new RuntimeException("Elementor template deletion restore failed");
  }
  clean_post_cache('"$SOURCE_TEMPLATE"');
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-elementor-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-elementor-restored" \
  || fail 'Elementor source did not restore byte-identically after malformed/secret/deletion probes'
rm -rf "$CONF_REPO1/.tmp-elementor-restored"
pass 'malformed documents, credential-shaped kit data, and unsupported template deletion refuse atomically and redact values'

# Both branches edit the same nested native document. The unforced path must
# be a pure refusal; explicit repository authority must converge the builder
# document while target-owned runtime options remain untouched.
wp_conf1 eval '
  $admins=get_users(["role"=>"administrator","number"=>1]);
  if (!$admins) throw new RuntimeException("source Elementor conflict save needs an administrator");
  wp_set_current_user($admins[0]->ID);
  $post=get_page_by_path("wprism-conformance-elementor-page",OBJECT,"page");
  $data=json_decode((string)get_post_meta($post->ID,"_elementor_data",true),true,512,JSON_THROW_ON_ERROR);
  $data[0]["elements"][1]["elements"][1]["settings"]["title"]="Repository competing Elementor heading 東京 🚀";
  $document=\Elementor\Plugin::$instance->documents->get($post->ID);
  if (!$document || $document->save(["elements"=>$data]) === false) throw new RuntimeException("source Elementor conflict save failed");
' >/dev/null
commit_elementor_source 'conformance: competing Elementor document intent'
wp_conf2 eval '
  $admins=get_users(["role"=>"administrator","number"=>1]);
  if (!$admins) throw new RuntimeException("target Elementor conflict save needs an administrator");
  wp_set_current_user($admins[0]->ID);
  $post=get_page_by_path("wprism-conformance-elementor-page",OBJECT,"page");
  $data=json_decode((string)get_post_meta($post->ID,"_elementor_data",true),true,512,JSON_THROW_ON_ERROR);
  $data[0]["elements"][1]["elements"][1]["settings"]["title"]="Target competing Elementor heading";
  $document=\Elementor\Plugin::$instance->documents->get($post->ID);
  if (!$document || $document->save(["elements"=>$data]) === false) throw new RuntimeException("target Elementor conflict save failed");
' >/dev/null
CONFLICT_BEFORE=$(elementor_target_hash)
CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor competing document plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Elementor competing document did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered 'Elementor unforced competing document apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "Elementor competing document did not refuse: $CONFLICT_OUT"
[ "$(elementor_target_hash)" = "$CONFLICT_BEFORE" ] \
  || fail 'Elementor unforced conflict partially mutated target state'
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor forced competing document apply' json "$FORCED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.conflict > 0' <<<"$FORCED" >/dev/null \
  || fail "Elementor forced repository intent did not converge cleanly: $FORCED"
CONVERGED=$(observe_elementor conf2)
jq -e '
  .classic.first_heading == "Repository competing Elementor heading 東京 🚀" and
  .runtime.connect_key == "target-connect-site-key-preserved" and
  .runtime.checklist.completed == ["target-runtime-marker"] and
  .runtime.neighbor == "target-neighbor-preserved"
' <<<"$CONVERGED" >/dev/null || fail "Elementor forced conflict crossed a runtime boundary: $CONVERGED"
pass 'dirty nested-document conflicts refuse atomically; explicit force preserves target runtime boundaries'

# Publish a post-title intent, then put an unsupported nested entry inside the
# generated-CSS projection. Core materialization must complete, the real
# provider must refuse its bounded readback before mutation, applied_revision
# must stay behind, and retry must converge after the projection is repaired.
wp_conf1 post update "$SOURCE_CLASSIC" --post_title='Failure recovery Elementor title 東京 🚀' >/dev/null
commit_elementor_source 'conformance: Elementor provider-fault recovery intent'
CSS_FAULT=$(wp_conf2 eval '
  $uploads=wp_upload_dir();
  $css=rtrim((string)$uploads["basedir"],"/")."/elementor/css";
  if (!is_dir($css) && !wp_mkdir_p($css)) throw new RuntimeException("Elementor fault CSS directory unavailable");
  $fault=$css."/wprism-unsupported-nested-entry";
  if (!mkdir($fault) && !is_dir($fault)) throw new RuntimeException("Elementor CSS fault injection failed");
  echo $fault;
')
require_observed_nonempty 'Elementor provider projection-fault path' "$CSS_FAULT"
FAILURE_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Elementor applied revision before provider fault' "$FAILURE_REV_BEFORE"
FAILURE_RC=0
FAILURE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAILURE_RC=$?
require_wprism_answered 'Elementor provider projection failure' human "$FAILURE_OUT"
[ "$FAILURE_RC" -ne 0 ] && grep -q "provider 'elementor-css' capability 'regenerate_css' failed" <<<"$FAILURE_OUT" \
  || fail "Elementor malformed CSS projection did not refuse in the provider: $FAILURE_OUT"
[ "$(wp_conf2 post get "$(jq -r '.ids.classic' <<<"$CONVERGED")" --field=post_title)" = 'Failure recovery Elementor title 東京 🚀' ] \
  || fail 'Elementor provider failure did not retain the post-commit authored state needed for retry'
[ "$(wp_conf2 option get elementor_target_undeclared_neighbor)" = target-neighbor-preserved ] \
  || fail 'Elementor provider recovery crossed the target-owned option boundary'
[ "$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FAILURE_REV_BEFORE" ] \
  || fail 'Elementor provider failure advanced applied_revision before verified effects'
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'Elementor provider failure did not retain retry authority'
wp_conf2 eval '
  $uploads=wp_upload_dir();
  $fault=rtrim((string)$uploads["basedir"],"/")."/elementor/css/wprism-unsupported-nested-entry";
  if (!rmdir($fault)) throw new RuntimeException("Elementor CSS fault repair failed");
' >/dev/null
RETRY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor retry after provider projection repair' json "$RETRY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:elementor-css/regenerate_css" and .verified == true)
' <<<"$RETRY" >/dev/null || fail "Elementor provider retry did not consume retained intent: $RETRY"
pass 'malformed CSS projection retains post-commit intent and authority, then retries cleanly after exact repair'

# Removing one authored kit sub-key is an ordinary structured update, not a
# whole-post tombstone. Elementor's native kit storage must observe absence.
wp_conf1 eval '
  $kit=(int)get_option("elementor_active_kit");
  $settings=(array)get_post_meta($kit,"_elementor_page_settings",true);
  unset($settings["default_generic_fonts"]);
  update_post_meta($kit,"_elementor_page_settings",$settings);
' >/dev/null
commit_elementor_source 'conformance: Elementor authored kit-field absence'
FIELD_REMOVED=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor kit-field absence apply' json "$FIELD_REMOVED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.update > 0 and .plan.delete == 0' <<<"$FIELD_REMOVED" >/dev/null \
  || fail "Elementor kit-field absence did not converge as an update: $FIELD_REMOVED"
FIELD_OBSERVED=$(observe_elementor conf2)
jq -e '.kit.fonts == null and .derived.invalid_receipts == 0 and .derived.missing == [] and .derived.orphan == []' <<<"$FIELD_OBSERVED" >/dev/null \
  || fail "Elementor native kit/CSS state did not consume field absence: $FIELD_OBSERVED"
pass 'authored kit sub-key absence converges as an ordinary verified update'

# A core page tombstone is withheld without authority, then explicitly
# applied. Its generated stylesheet must disappear in the same verified
# provider action, proving the deletion cannot strand derived residue.
SOURCE_DELETION=$(jq -r '.deletion' <<<"$SOURCE_IDS")
TARGET_BEFORE_DELETE=$(observe_elementor conf2)
TARGET_DELETION=$(jq -r '.ids.deletion' <<<"$TARGET_BEFORE_DELETE")
require_fixture_ids SOURCE_DELETION TARGET_DELETION
wp_conf1 post delete "$SOURCE_DELETION" --force >/dev/null
commit_elementor_source 'conformance: Elementor core-page deletion intent'
WITHHELD=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1)
require_wprism_answered 'Elementor page deletion withheld without authority' human "$WITHHELD"
grep -q 'planned deletions NOT applied (1)' <<<"$WITHHELD" \
  && grep -q -- '--with-deletes' <<<"$WITHHELD" \
  && grep -q 'canary clean' <<<"$WITHHELD" \
  || fail "Elementor page deletion was not explicitly withheld: $WITHHELD"
[ "$(wp_conf2 post list --post_type=page --name=wprism-elementor-deletion-page --format=count)" = 1 ] \
  || fail 'Elementor page deletion ran without explicit authority'
DELETED=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor authorized page deletion apply' json "$DELETED"
jq -e '
  .canary == "clean" and .verification.result == "pass" and (.plan.delete + .plan.deleted) > 0 and
  any(.actions[]?; .source == "provider:elementor-css/regenerate_css" and
    .after.orphan_document_css == 0 and .verified == true)
' <<<"$DELETED" >/dev/null || fail "Elementor authorized deletion did not verify CSS cleanup: $DELETED"
[ "$(wp_conf2 post list --post_type=page --name=wprism-elementor-deletion-page --format=count)" = 0 ] \
  || fail 'Elementor authorized deletion left the page on target'
DELETE_OBSERVED=$(observe_elementor conf2)
jq -e '
  .ids.deletion == 0 and .derived.invalid_receipts == 0 and .derived.missing == [] and
  .derived.unexpected_empty == [] and .derived.orphan == [] and .derived.render_caches == 0
' <<<"$DELETE_OBSERVED" >/dev/null || fail "Elementor deletion left invalid derived state: $DELETE_OBSERVED"
pass 'authorized core-page deletion removes its generated CSS; plugin-owned template deletion remains loudly unsupported'

# Two real apply processes race one authored title. At least one succeeds;
# the other may observe no work or refuse only at the named promotion lock.
wp_conf1 post update "$SOURCE_CLASSIC" --post_title='Concurrent Elementor intent 東京 🚀' >/dev/null
commit_elementor_source 'conformance: concurrent Elementor apply intent'
CONCURRENT_A="$CONF_REPO2/.tmp-elementor-concurrent-a.log"
CONCURRENT_B="$CONF_REPO2/.tmp-elementor-concurrent-b.log"
set +e
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing Elementor applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing Elementor apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing Elementor apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_elementor conf2)
jq -e '.classic.post_title == "Concurrent Elementor intent 東京 🚀"' <<<"$CONCURRENT" >/dev/null \
  || fail "competing Elementor applies lost repository intent: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor plan after competing applies' json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "Elementor competing applies left retained work: $CONCURRENT_PLAN"
pass 'competing Elementor applies serialize and leave one exact idempotent result'

# Deactivation is repaired by deploy. Native uninstall may remove plugin
# runtime state, but must leave the repository-owned post/meta graph available
# for an exact digest-bound reinstall and explicit apply recovery.
wp_conf2 plugin deactivate elementor >/dev/null
wp_conf2 plugin is-active elementor >/dev/null 2>&1 && fail 'Elementor deactivation premise did not land'
REACTIVATE=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor deploy after deactivation' json "$REACTIVATE"
wp_conf2 plugin is-active elementor >/dev/null || fail 'WPrism deploy did not reactivate exact Elementor code'
TARGET_CLASSIC=$(jq -r '.ids.classic' <<<"$CONCURRENT")
TARGET_KIT=$(jq -r '.ids.kit' <<<"$CONCURRENT")
require_fixture_ids TARGET_CLASSIC TARGET_KIT
CLASSIC_HASH_BEFORE=$(wp_conf2 eval 'echo hash("sha256",serialize(get_post_meta('"$TARGET_CLASSIC"',"_elementor_data",true)));')
KIT_HASH_BEFORE=$(wp_conf2 eval 'echo hash("sha256",serialize(get_post_meta('"$TARGET_KIT"',"_elementor_page_settings",true)));')
wp_conf2 plugin deactivate elementor >/dev/null
wp_conf2 plugin uninstall elementor >/dev/null
wp_conf2 plugin is-installed elementor >/dev/null 2>&1 && fail 'Elementor uninstall left plugin code installed'
[ "$(wp_conf2 eval 'echo hash("sha256",serialize(get_post_meta('"$TARGET_CLASSIC"',"_elementor_data",true)));')" = "$CLASSIC_HASH_BEFORE" ] \
  || fail 'Elementor native uninstall removed repository-owned document data'
[ "$(wp_conf2 eval 'echo hash("sha256",serialize(get_post_meta('"$TARGET_KIT"',"_elementor_page_settings",true)));')" = "$KIT_HASH_BEFORE" ] \
  || fail 'Elementor native uninstall removed repository-owned kit settings'
MISSING_RC=0
MISSING_OUT=$(wp_conf2 wprism deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_wprism_answered 'Elementor deploy with code absent' human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Elementor code did not refuse at compatibility: $MISSING_OUT"
ELEMENTOR_SHA=20d8bf5f8be00cd89f8ae6ba13a109faa175ac198f2280bb593d6960c1c65fd5
ELEMENTOR_ARTIFACT="/artifacts-cache/plugin-elementor-4.2.3-${ELEMENTOR_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256','$ELEMENTOR_ARTIFACT');")" = "$ELEMENTOR_SHA" ] \
  || fail 'cached Elementor reinstall artifact digest moved'
wp_conf2 plugin install "$ELEMENTOR_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get elementor --field=version)" = 4.2.3 ] || fail 'Elementor exact reinstall reported wrong version'
REINSTALL_DEPLOY=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor deploy after exact reinstall' json "$REINSTALL_DEPLOY"
REINSTALL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor plan after exact reinstall' json "$REINSTALL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$REINSTALL_PLAN" >/dev/null \
  || fail "Elementor uninstall unexpectedly removed repository-owned authored state: $REINSTALL_PLAN"
LAZY_FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/wprism-conformance-elementor-page/") \
  || fail 'Elementor exact reinstall did not lazily render retained document data'
grep -Fq 'Repository competing Elementor heading 東京 🚀' <<<"$LAZY_FRONT" \
  || fail 'Elementor exact reinstall did not consume retained document data through its native lazy path'

# Uninstall legitimately removes derived CSS receipts, and a zero-change apply
# does not fire a trigger-bounded provider. Publish one ordinary authored
# revision; that next real promotion must rebuild the complete projection.
wp_conf1 post update "$SOURCE_CLASSIC" --post_title='Post-reinstall Elementor recovery 東京 🚀' >/dev/null
commit_elementor_source 'conformance: Elementor post-reinstall recovery intent'
REINSTALL_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor authored apply after exact reinstall' json "$REINSTALL_APPLY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:elementor-css/regenerate_css" and .verified == true)
' <<<"$REINSTALL_APPLY" >/dev/null || fail "Elementor next authored revision did not recover derived state: $REINSTALL_APPLY"
RECOVERED=$(observe_elementor conf2)
jq -e '
  .version == "4.2.3" and .classic.post_title == "Post-reinstall Elementor recovery 東京 🚀" and
  .classic.first_heading == "Repository competing Elementor heading 東京 🚀" and .kit.fonts == null and
  .ids.deletion == 0 and .derived.invalid_receipts == 0 and .derived.missing == [] and
  .derived.unexpected_empty == [] and .derived.orphan == [] and .derived.render_caches == 0
' <<<"$RECOVERED" >/dev/null || fail "Elementor state did not recover after exact reinstall: $RECOVERED"
RECOVERY_FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/wprism-conformance-elementor-page/") \
  || fail 'Elementor recovered page did not render'
grep -Fq 'Repository competing Elementor heading 東京 🚀' <<<"$RECOVERY_FRONT" \
  || fail 'Elementor recovered frontend did not consume the repository document'
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$RECOVERY_FRONT" \
  && fail 'Elementor recovered frontend leaked the source host'
FINAL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Elementor final recovery plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "Elementor recovery was not idempotent: $FINAL_PLAN"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-elementor-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-elementor-final" \
  || fail 'Elementor final recovered state was not byte-identical'
rm -rf "$CONF_REPO2/.tmp-elementor-final"
pass 'deactivate/reactivate, native uninstall residue, absent-code refusal, exact reinstall, render, and final retry are clean'
