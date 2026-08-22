#!/usr/bin/env bash
# Opposite target settings prove convergence. Every excluded runtime/legacy
# row plus an undeclared adjacent option is then seeded after plugin upgrade
# handling, so apply must touch only the two manifest-authored rows.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$instance = null;
foreach (($GLOBALS['wp_filter']['mce_buttons']->callbacks ?? []) as $callbacks) {
    foreach ($callbacks as $callback) {
        $fn = $callback['function'] ?? null;
        if (is_array($fn) && is_object($fn[0] ?? null) && ($fn[1] ?? null) === 'mce_buttons_1') {
            $instance = $fn[0];
            break 2;
        }
    }
}
if (!$instance) {
    throw new RuntimeException('Advanced Editor Tools target callback instance was not registered');
}
$save = new ReflectionMethod($instance, 'save_settings');
$save->invoke($instance, [
    'settings' => [
        'toolbar_1' => 'alignleft,aligncenter,alignright',
        'toolbar_2' => '',
        'toolbar_3' => '',
        'toolbar_4' => '',
        'toolbar_classic_block' => 'alignleft,alignright',
        'options' => '',
        'plugins' => '',
    ],
    'admin_settings' => ['options' => '', 'disabled_editors' => 'on_front_end'],
]);
$runtime = [
    'tadv_options', 'tadv_toolbars', 'tadv_plugins', 'tadv_btns1',
    'tadv_btns2', 'tadv_btns3', 'tadv_btns4', 'tadv_allbtns',
];
foreach ($runtime as $name) {
    update_option($name, ['probe' => "target-runtime-$name"]);
}
update_option('tadv_future_setting', 'target-only-neighbor');
echo wp_json_encode([
    'admin' => get_option('tadv_admin_settings'),
    'neighbor' => get_option('tadv_future_setting'),
    'settings' => get_option('tadv_settings'),
    'version' => get_option('tadv_version'),
]);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-advanced-editor-tools-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-advanced-editor-tools-hostile.php)
require_observed_nonempty "Advanced Editor Tools hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .settings.toolbar_1 == "alignleft,aligncenter,alignright" and
  .admin.disabled_editors == "on_front_end" and
  .neighbor == "target-only-neighbor" and (.version | tonumber) >= 5900
' >/dev/null || fail "Advanced Editor Tools hostile target premise did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"
pass "Advanced Editor Tools target starts with opposite settings, every excluded legacy row, and an undeclared neighbor"
