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
$classic = $post('duo-conformance-elementor-page', 'page');
$target = $post('duo-elementor-target', 'page');
$template = $post('duo-portable-section', 'elementor_library');
$atomic = $post('duo-atomic-elementor-page', 'page');
$hero = $post('duo-conf-elementor-hero', 'attachment');
$galleryA = $post('duo-conf-elementor-gallery-a', 'attachment');
$galleryB = $post('duo-conf-elementor-gallery-b', 'attachment');
$background = $post('duo-conf-elementor-bg', 'attachment');
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
$missing = array_values(array_diff($builderIds, $postCssIds));
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
    'gallery_a' => (int) $galleryA->ID,
    'gallery_b' => (int) $galleryB->ID,
    'hero' => (int) $hero->ID,
    'kit' => $kitId,
    'target' => (int) $target->ID,
    'template' => (int) $template->ID,
];
$attachmentIds = array_values(array_intersect_key($ids, array_flip(['background', 'gallery_a', 'gallery_b', 'hero'])));
$attachmentExists = [];
foreach ($attachmentIds as $id) {
    $attachmentExists[(string) $id] = get_post($id) instanceof WP_Post;
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
        'gallery' => array_map(
            static fn(array $item): int => (int) ($item['id'] ?? 0),
            (array) ($classicData[0]['elements'][1]['elements'][0]['settings']['wp_gallery'] ?? [])
        ),
        'hero' => $classicData[0]['elements'][0]['elements'][0]['settings']['image']['id'] ?? null,
        'large_headings' => $large,
        'link' => $classicData[0]['elements'][0]['elements'][1]['settings']['link']['url'] ?? null,
    ],
    'derived' => [
        'builder_ids' => $builderIds,
        'css_files' => count($cssFiles),
        'css_fingerprint' => hash('sha256', serialize($cssFiles)),
        'missing' => $missing,
        'orphan' => $orphan,
        'post_css_ids' => $postCssIds,
        'render_caches' => $renderCaches,
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
        'checklist' => get_option('elementor_checklist', null),
        'connect_key' => get_option('elementor_connect_site_key', null),
        'experiment' => get_option('elementor_experiment-e_atomic_elements', null),
        'neighbor' => get_option('elementor_target_undeclared_neighbor', null),
    ],
    'template' => [
        'image' => $templateData[0]['elements'][0]['elements'][0]['settings']['image']['id'] ?? null,
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
  .classic.hero == .ids.hero and .classic.background == .ids.background and
  .classic.gallery == [.ids.gallery_a,.ids.gallery_b] and
  .classic.link == ("http://localhost:'"$CONF2_PORT"'/duo-elementor-target/?from=elementor&encoded=a%2Fb") and
  .template.type == "section" and .template.image == .ids.gallery_a and
  .kit.site_logo == .ids.hero and .kit.site_favicon == .ids.gallery_a and
  .kit.background == .ids.background and .kit.gallery == [.ids.gallery_a,.ids.gallery_b] and
  .kit.fonts == "Inter, Arial, sans-serif" and
  (.kit.system_colors | map(select(._id == "primary" and .color == "#123456")) | length) == 1 and
  (.kit.system_colors | map(select(._id == "secondary" and .color == "#654321")) | length) == 1 and
  (.kit.custom_colors | map(select(._id == "duocustom" and .title == "Duo Custom 東京 🚀" and .color == "#abcdef")) | length) == 1 and
  (.media_exist | to_entries | all(.value == true)) and
  .derived.missing == [] and .derived.orphan == [] and .derived.render_caches == 0 and
  .derived.builder_ids == .derived.post_css_ids and
  .runtime.connect_key == "target-connect-site-key-preserved" and
  .runtime.checklist.completed == ["target-runtime-marker"] and
  .runtime.experiment == "target-experiment-marker" and
  .runtime.neighbor == "target-neighbor-preserved"
' <<<"$TARGET" >/dev/null || fail "Elementor authored/runtime/native state did not converge: $TARGET"

if [ "$ATOMIC_SUPPORTED" = true ]; then
  jq -e '
    .ids.atomic > 0 and .atomic.heading == "Atomic portable heading 東京 🚀" and
    .atomic.image == .ids.hero
  ' <<<"$TARGET" >/dev/null || fail "Elementor Atomic document did not converge through its generated prop envelope: $TARGET"
fi

for key in classic target template kit hero gallery_a gallery_b background; do
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
      .after.missing_document_css == 0 and .after.orphan_document_css == 0 and
      .after.render_caches == 0 and .after.builder_documents == .after.post_css_files)
  ' <<<"$PROVIDER_RECEIPT" >/dev/null \
    || fail 'Elementor provider JSON receipt omitted its closed document/CSS projection'
fi
pass 'Elementor documents, media, template and kit references rebound across divergent adopted identities'
pass 'Elementor provider 2.0.0 removed stale/orphan CSS and render caches with a closed readback receipt'

FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/duo-conformance-elementor-page/") \
  || fail 'conf2 classic Elementor page did not return 200'
require_observed_nonempty 'conf2 classic Elementor rendered response' "$FRONT"
[ "${#FRONT}" -ge 20000 ] || fail "conf2 classic Elementor response was suspiciously short (${#FRONT} bytes)"
grep -qiE 'fatal error|uncaught' <<<"$FRONT" && fail 'conf2 classic Elementor response contains a fatal marker'
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$FRONT" && fail 'conf2 classic Elementor response leaked the source host'
grep -q 'src="http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-conf-elementor-hero[^"?]*' <<<"$FRONT" \
  || fail 'classic Image widget did not consume the target attachment reference'
grep -q 'duo-conf-elementor-gallery-a' <<<"$FRONT" && grep -q 'duo-conf-elementor-gallery-b' <<<"$FRONT" \
  || fail 'classic gallery did not consume both target attachment references'
grep -Fq "href=\"http://localhost:${CONF2_PORT}/duo-elementor-target/?from=elementor" <<<"$FRONT" \
  || fail 'classic plain-URL internal link did not rebind to the target host'
grep -Fq 'Portable nested heading 000 東京 🚀' <<<"$FRONT" \
  && grep -Fq 'Portable nested heading 127 東京 🚀' <<<"$FRONT" \
  || fail 'large nested UTF-8 Elementor document did not render both boundaries'

PAGE_ID=$(wp_conf2 post list --post_type=page --name=duo-conformance-elementor-page --field=ID)
require_fixture_ids PAGE_ID
[ "$PAGE_ID" = "$(jq -r '.ids.classic' <<<"$TARGET")" ] \
  || fail 'Elementor rendered-page id read disagrees with the native observation'
CSS=$(curl -fsSL "http://localhost:${CONF2_PORT}/wp-content/uploads/elementor/css/post-${PAGE_ID}.css") \
  || fail "could not fetch conf2 regenerated Elementor CSS for $PAGE_ID"
require_observed_nonempty 'conf2 Elementor regenerated CSS' "$CSS"
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$CSS" && fail 'regenerated Elementor CSS leaked the source host'
grep -q 'background-image:url("http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-conf-elementor-bg' <<<"$CSS" \
  || fail 'classic background image did not regenerate from the target attachment'

if [ "$ATOMIC_SUPPORTED" = true ]; then
  ATOMIC_FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/duo-atomic-elementor-page/") \
    || fail 'conf2 Atomic Elementor page did not return 200'
  require_observed_nonempty 'conf2 Atomic Elementor rendered response' "$ATOMIC_FRONT"
  grep -Fq 'Atomic portable heading 東京 🚀' <<<"$ATOMIC_FRONT" \
    || fail 'Atomic Heading did not consume its generated html-v3/string envelope'
  grep -q 'duo-conf-elementor-hero' <<<"$ATOMIC_FRONT" \
    || fail 'Atomic Image did not consume its generated attachment-id envelope'
  grep -Fq "http://localhost:${CONF1_PORT}" <<<"$ATOMIC_FRONT" \
    && fail 'conf2 Atomic Elementor response leaked the source host'
fi
pass 'classic and Atomic frontend rendering consumes target-local URLs, media, large UTF-8 data, and regenerated CSS'

ZERO_PLAN=$($COMPOSE run --rm -T cli2 wp duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Elementor zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "Elementor retry retained work: $ZERO_PLAN"
ZERO_APPLY=$($COMPOSE run --rm -T cli2 wp duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'Elementor zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Elementor no-op apply was not clean and idempotent: $ZERO_APPLY"
pass 'Elementor zero-change plan/apply is mutation-free and does not rerun the provider'

if [ "${ELEMENTOR_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "Elementor $ELEMENTOR_EXPECTED_VERSION exact boundary consumed the full portable fixture"
  return 0 2>/dev/null || exit 0
fi
