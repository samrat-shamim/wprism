<?php
declare(strict_types=1);
// WordPress's Customizer reifies empty legacy categories/RSS option arrays as
// instance 1. Native before/after observation proves these are unassigned;
// the existing onboarding exclusion keeps their settings and identities local.
global $wpdb;
$sidebars = wp_get_sidebars_widgets();
$rows = [];
foreach (['categories', 'rss'] as $type) {
    $name = 'widget_' . $type;
    $settings = get_option($name, null);
    if ($settings !== [1 => [], '_multiwidget' => 1]) {
        throw new RuntimeException("$name is not the observed empty Customizer residue; review widget scope");
    }
    foreach ($sidebars as $sidebar => $keys) {
        if ($sidebar !== 'wp_inactive_widgets' && is_array($keys) && in_array($type . '-1', $keys, true)) {
            throw new RuntimeException("$name has an active assignment; it needs a portable widget declaration");
        }
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name), ARRAY_A);
    if (!is_array($row)) throw new RuntimeException("$name raw observation is absent");
    $rows[$name] = $row;
}
echo wp_json_encode(['unassigned_empty_families' => array_keys($rows), 'native_rows' => $rows]);
