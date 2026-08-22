#!/usr/bin/env bash
# Build a hostile-but-bounded target after deploy: remove activation samples,
# advance target IDs beyond source IDs, enable flat files, leave an executable
# stale projection whose DB row is gone, and prime get_snippets() before every
# command so direct table writes would remain invisible without the provider.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
foreach (Code_Snippets\get_snippets() as $existing) {
    if (!Code_Snippets\delete_snippet((int) $existing->id)) {
        throw new RuntimeException('could not remove a target activation sample');
    }
}
for ($i = 0; $i < 5; $i++) {
    $spacer = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
        'name' => "Target identity spacer $i",
        'desc' => 'Deleted target-only sequence spacer.',
        'code' => '<p>target spacer</p>',
        'scope' => 'content',
        'active' => false,
    ]));
    if (!$spacer || !Code_Snippets\delete_snippet((int) $spacer->id)) {
        throw new RuntimeException('could not advance the target snippet sequence');
    }
}

$stale = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    'name' => 'Target stale executable projection',
    'desc' => 'Its row is removed without plugin hooks so the old file survives.',
    'code' => "add_filter('duo_code_snippets_runtime', static function (\$value) { return \$value . '|target-stale-runtime'; });",
    'scope' => 'global',
    'priority' => 2,
    'active' => true,
]));
if (!$stale) {
    throw new RuntimeException('could not create the stale target projection');
}
// The settings action is the plugin's public bulk-rebuild boundary and is the
// same path the shipped provider delegates.
do_action('code_snippets/settings_updated', Code_Snippets\Settings\get_settings_values());
$table = Code_Snippets\code_snippets()->db->get_table_name(false);
$hash = Code_Snippets\Snippet_Files::get_hashed_table_name($table);
$directory = Code_Snippets\Snippet_Files::get_base_dir($hash);
$staleFile = $directory . '/php/' . (int) $stale->id . '.php';
if (!is_file($staleFile)) {
    throw new RuntimeException('stale target code file premise was not created');
}
global $wpdb;
if (1 !== $wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE id = %d", (int) $stale->id))) {
    throw new RuntimeException('could not remove only the stale target DB row');
}
update_option('code_snippets_target_neighbor', 'target-only-neighbor');

if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
    throw new RuntimeException('could not create target MU plugin directory');
}
$primer = <<<'PHP'
<?php
if (file_exists('/siterepo/.code-snippets-safe-mode')) {
    define('CODE_SNIPPETS_SAFE_MODE', true);
}
add_action('plugins_loaded', static function () {
    if (function_exists('Code_Snippets\\get_snippets')) {
        Code_Snippets\get_snippets();
    }
}, PHP_INT_MAX);
PHP;
if (false === file_put_contents(WPMU_PLUGIN_DIR . '/duo-code-snippets-cache-primer.php', $primer)) {
    throw new RuntimeException('could not install the target cache-primer fixture');
}

echo wp_json_encode([
    'flat_enabled' => Code_Snippets\Snippet_Files::is_active(),
    'next_id_floor' => (int) $stale->id,
    'row_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`"),
    'runtime_value' => apply_filters('duo_code_snippets_runtime', 'base'),
    'stale_file' => is_file($staleFile),
], JSON_UNESCAPED_SLASHES);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-code-snippets-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
# Code Snippets caches flat-file activation for the life of a WordPress
# process. A real settings save and the next request are therefore distinct:
# enable in one process, then create/check the projection in the eval-file
# process whose plugin bootstrap observes the persisted value.
wp_conf2 eval 'Code_Snippets\Settings\update_setting("general", "enable_flat_files", true); do_action("code_snippets/settings_updated", Code_Snippets\Settings\get_settings_values());' >/dev/null
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-code-snippets-hostile.php)
require_observed_nonempty "Code Snippets hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .flat_enabled == true and .next_id_floor > 9 and .row_count == 0 and
  .runtime_value == "base|target-stale-runtime" and .stale_file == true
' >/dev/null || fail "Code Snippets hostile target cache/flat-file premise did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"
pass "Code Snippets target starts with divergent IDs, an empty primed API cache, a stale executable projection, flat mode, and an undeclared neighbor"
