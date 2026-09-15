#!/usr/bin/env bash
set -euo pipefail

read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$roles = [
    'integer' => ['wpforms', 'wprism-wpf-integer'],
    'string' => ['wpforms', 'wprism-wpf-string'],
    'template' => ['wpforms-template', 'wprism-wpf-template'],
    'destination' => ['page', 'wprism-wpf-destination'],
    'embed' => ['page', 'wprism-wpf-embed'],
];
$find = static function (string $type, string $slug): WP_Post {
    $rows = get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 2, 'suppress_filters' => true]);
    if (count($rows) !== 1) throw new RuntimeException("WPForms target fixture is not unique: $type/$slug");
    return $rows[0];
};
$forms = [];
foreach (['integer', 'string', 'template'] as $role) {
    $post = $find(...$roles[$role]);
    $data = wpforms_decode($post->post_content);
    if (!is_array($data) || (string) ($data['id'] ?? '') !== (string) $post->ID) {
        throw new RuntimeException("WPForms target body identity did not converge: $role");
    }
    ob_start();
    wpforms_display($post->ID, true, true);
    $html = (string) ob_get_clean();
    if ($html === '' || !str_contains($html, 'wpforms-form')) throw new RuntimeException("WPForms target form did not render: $role");
    $forms[$role] = ['id' => (int) $post->ID, 'data' => $data, 'locations' => get_post_meta($post->ID, 'wpforms_form_locations', true)];
}
$destination = $find(...$roles['destination']);
$embed = $find(...$roles['embed']);
$content = (string) $embed->post_content;
if (!str_contains($content, '[wpforms id="' . $forms['integer']['id'] . '"')
    || !str_contains($content, '"formId":"' . $forms['string']['id'] . '"')) {
    throw new RuntimeException('WPForms target embeds did not rewrite both form identity codecs');
}
if (!is_array($forms['integer']['locations']) || $forms['integer']['locations'] === []) {
    throw new RuntimeException('WPForms target location provider did not materialize a form location');
}
echo wp_json_encode([
    'destination' => (int) $destination->ID,
    'embed' => (int) $embed->ID,
    'forms' => array_map(static fn(array $form): array => ['id' => $form['id'], 'location_count' => count((array) $form['locations'])], $forms),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

file="${CONF_REPO2:-siterepo/conf2}/.tmp-wpforms-observe.php"
printf '%s' "$OBSERVE_PHP" > "$file"
out=$(wp_conf2 eval-file /siterepo/.tmp-wpforms-observe.php)
rm -f "$file"
require_observed_nonempty "WPForms target native round-trip observation" "$out"
printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }' \
  | jq -e '.forms.integer.id > 0 and .forms.string.id > 0 and .forms.template.id > 0 and .forms.integer.location_count > 0' >/dev/null \
  || fail "WPForms target native round-trip observation was incomplete: $out"
pass "WPForms target forms, template, embeds, native rendering and location provider converge after Apply"
