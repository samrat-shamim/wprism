<?php
declare(strict_types=1);

require_once __DIR__ . '/polylang_biography_values.php';
require_once WPMU_PLUGIN_DIR . '/wprism/src/Kernel/MetaRows.php';

// This entrypoint is eval-file input inside an ordinary WordPress bootstrap.
// It never loads Polylang classes or substitutes a native sanitizer.
$controlWrite = count($args) === 2 && in_array($args[0], ['corrupt', 'restore'], true)
    && array_key_exists($args[1], PolylangBiographyValues::hostile());
if ((!$controlWrite && (count($args) !== 1 || !in_array($args[0], ['seed-source', 'seed-target', 'observe'], true)))
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
if ($controlWrite) {
    global $wpdb;
    $rows = \WPrism\MetaRows::ordered($wpdb->usermeta, 'user_id', (int) $publisher->ID, 'umeta_id', 'Polylang biography control preimage');
    $selected = array_values(array_filter($rows, static fn(array $row): bool => $row['meta_key'] === 'description_fr'));
    $unsafe = PolylangBiographyValues::hostile()[$args[1]];
    $before = $args[0] === 'corrupt' ? $expected['description_fr'] : $unsafe;
    $after = $args[0] === 'corrupt' ? $unsafe : $expected['description_fr'];
    if (count($selected) !== 1 || $selected[0]['meta_value'] !== $before) {
        throw new RuntimeException('Polylang biography control does not own its exact native preimage');
    }
    // SQL is deliberate fault injection: a native profile editor would KSES
    // this value before saving it. CAS protects the one fixture-owned row;
    // no other row or preimage can be replaced by fixture recovery.
    $changed = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND user_id = %d AND BINARY meta_key = BINARY %s AND BINARY meta_value = BINARY %s",
        $after, (int) $selected[0]['meta_id'], (int) $publisher->ID, 'description_fr', $before
    ));
    if ($changed !== 1 || $wpdb->last_error !== '') throw new RuntimeException('Polylang biography native control CAS failed');
    wp_cache_delete((int) $publisher->ID, 'user_meta');
    if (get_user_meta((int) $publisher->ID, 'description_fr', false) !== [$after]) {
        throw new RuntimeException('Polylang biography native control readback failed');
    }
    echo json_encode(['format' => 'polylang-biography-control/v1', 'mode' => $args[0], 'control' => $args[1], 'rows_changed' => 1], JSON_THROW_ON_ERROR) . "\n";
    return;
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
$budget = $wpdb->get_row("SELECT COUNT(*) AS row_count, COALESCE(SUM(OCTET_LENGTH(meta_key) + COALESCE(OCTET_LENGTH(meta_value), 0)), 0) AS total_bytes FROM {$wpdb->usermeta}", ARRAY_A);
if (!is_array($budget) || array_keys($budget) !== ['row_count', 'total_bytes'] || $wpdb->last_error !== '') {
    throw new RuntimeException('Polylang biography native metadata budget preflight failed');
}
foreach (['row_count' => 512, 'total_bytes' => 32768] as $field => $limit) {
    if (!is_string($budget[$field]) || preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $budget[$field]) !== 1 || (int) $budget[$field] > $limit) {
        throw new RuntimeException('Polylang biography native metadata exceeds its fixture budget');
    }
}
$orphans = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.ID IS NULL");
if ($orphans !== '0' || $wpdb->last_error !== '') {
    throw new RuntimeException('Polylang biography fixture has orphaned or unreadable user metadata');
}
$metadata = [];
$retainedRows = $retainedBytes = 0;
foreach ($users as $user) {
    $id = \WPrism\MetaRows::positive_id($user['ID'] ?? null);
    if ($id === null) throw new RuntimeException('Polylang biography user identity is malformed');
    $rows = \WPrism\MetaRows::ordered(
        $wpdb->usermeta, 'user_id', $id, 'umeta_id', 'Polylang biography complete user metadata'
    );
    $retainedRows += count($rows);
    foreach ($rows as $row) $retainedBytes += strlen($row['meta_key']) + strlen($row['meta_value'] ?? '');
    if ($retainedRows > (int) $budget['row_count'] || $retainedBytes > (int) $budget['total_bytes']) {
        throw new RuntimeException('Polylang biography native metadata grew after its budget preflight');
    }
    $metadata[] = ['user_id' => (string) $id, 'rows' => $rows];
}
if ($retainedRows !== (int) $budget['row_count'] || $retainedBytes !== (int) $budget['total_bytes']) {
    throw new RuntimeException('Polylang biography native metadata changed after its budget preflight');
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
