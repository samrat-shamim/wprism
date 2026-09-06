<?php
declare(strict_types=1);

require_once __DIR__ . '/polylang_biography_values.php';
require_once WPMU_PLUGIN_DIR . '/wprism/src/Kernel/MetaRows.php';

// This entrypoint is eval-file input inside an ordinary WordPress bootstrap.
// It never loads Polylang classes or substitutes a native sanitizer.
if (count($args) !== 1 || !in_array($args[0], ['seed-source', 'seed-target', 'observe'], true)
    || !function_exists('wp_kses') || !function_exists('pll_languages_list')) {
    throw new RuntimeException('Polylang biography native fixture premise is unavailable');
}
$publisher = get_user_by('login', 'admin');
if (!$publisher instanceof WP_User || $publisher->user_login !== 'admin') {
    throw new RuntimeException('Polylang biography requires the pre-existing exact admin login');
}
$home = get_option('home');
if (!is_string($home) || !preg_match('~^http://localhost:[0-9]+$~D', $home)) {
    throw new RuntimeException('Polylang biography fixture requires its port-bound native home');
}
$expected = PolylangBiographyValues::authored($home);
foreach ($expected as $value) {
    if (wp_kses($value, 'pre_user_description') !== $value) {
        throw new RuntimeException('Polylang safe biography fixture is not native KSES canonical');
    }
}
$hostile = [];
foreach (PolylangBiographyValues::hostile() as $name => $value) {
    $sanitized = wp_kses($value, 'pre_user_description');
    if (!is_string($sanitized) || $sanitized === $value) {
        throw new RuntimeException('Polylang hostile biography fixture is not rejected by native KSES');
    }
    $hostile[$name] = ['input' => $value, 'sanitized' => $sanitized];
}
if ($args[0] !== 'observe') {
    foreach ($expected as $key => $value) {
        if ($args[0] === 'seed-target') $value = 'Target stale ' . $value;
        // Native Polylang's writer uses this exact WordPress filter followed
        // by update_user_meta. Neither path has unfiltered_html privilege.
        $filtered = apply_filters('pre_user_description', wp_slash($value));
        if (!is_string($filtered) || wp_unslash($filtered) !== $value) {
            throw new RuntimeException('Polylang biography native writer changed the authored fixture');
        }
        update_user_meta((int) $publisher->ID, $key, $filtered);
        if (get_user_meta((int) $publisher->ID, $key, false) !== [$value]) {
            throw new RuntimeException('Polylang biography native writer did not retain one exact value');
        }
    }
    echo json_encode(['format' => 'polylang-biography-seed/v1', 'mode' => $args[0], 'keys' => PolylangBiographyValues::KEYS], JSON_THROW_ON_ERROR) . "\n";
    return;
}

global $wpdb;
$readUsers = static function () use ($wpdb): array {
    // All wp_users columns are bounded native varchar/integer fields. This
    // disposable fixture permits at most 64 users, never a filtered admin-only
    // read that could conceal unexpected account creation or deletion.
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->users} ORDER BY ID ASC LIMIT 65", ARRAY_A);
    if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 64 || $wpdb->last_error !== '') {
        throw new RuntimeException('Polylang biography complete native user roster failed');
    }
    return $rows;
};
$users = $readUsers();
$orphans = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.ID IS NULL");
if ($orphans !== '0' || $wpdb->last_error !== '') {
    throw new RuntimeException('Polylang biography fixture has orphaned or unreadable user metadata');
}
$metadata = [];
foreach ($users as $user) {
    $id = \WPrism\MetaRows::positive_id($user['ID'] ?? null);
    if ($id === null) throw new RuntimeException('Polylang biography user identity is malformed');
    $metadata[] = ['user_id' => (string) $id, 'rows' => \WPrism\MetaRows::ordered(
        $wpdb->usermeta, 'user_id', $id, 'umeta_id', 'Polylang biography complete user metadata'
    )];
}
if ($readUsers() !== $users) throw new RuntimeException('Polylang biography user roster changed during observation');
$biographies = [];
foreach (PolylangBiographyValues::KEYS as $key) $biographies[$key] = get_user_meta((int) $publisher->ID, $key, false);
$record = [
    'format' => 'polylang-biography-native/v1', 'home' => $home, 'admin_id' => (string) $publisher->ID,
    'users' => $users, 'metadata' => $metadata, 'biographies' => $biographies,
    'orphan_rows' => $orphans,
    'default_shadow' => get_user_meta((int) $publisher->ID, 'description_en', false),
    'hostile_oracle' => $hostile,
];
$encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (strlen($encoded) > 65536) throw new RuntimeException('Polylang biography native fixture exceeds its private record bound');
echo $encoded . "\n";
