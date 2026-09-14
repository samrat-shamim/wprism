<?php
declare(strict_types=1);

require_once __DIR__ . '/load-order-evidence.php';
$phase = $args[0] ?? '';
$side = $args[1] ?? '';
$expected = ImporterWooLoadOrderEvidence::expected($side);
if (!is_admin() || !current_user_can('manage_options')) {
    throw new RuntimeException('Importer/Woo load order: native administrator context required');
}
$active = get_option('active_plugins');
if (!is_array($active) || count($active) !== 2 || array_diff($expected, $active) !== []) {
    throw new RuntimeException('Importer/Woo load order: exactly the two active participants required');
}
if ($phase === 'set') {
    // Activation can sort this option. Set the persisted WordPress ordering,
    // then observe entry-file execution only in a separate request.
    update_option('active_plugins', $expected);
    if (get_option('active_plugins') !== $expected) {
        throw new RuntimeException('Importer/Woo load order: order did not persist');
    }
    echo json_encode(['side' => $side, 'active' => $expected], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase !== 'observe') throw new RuntimeException('Importer/Woo load order: unknown phase');
$loaded = [];
$prefix = rtrim((string) realpath(WP_PLUGIN_DIR), '/') . '/';
foreach (get_included_files() as $file) {
    if (str_starts_with($file, $prefix)) {
        $relative = substr($file, strlen($prefix));
        if (in_array($relative, $expected, true)) $loaded[] = $relative;
    }
}
$record = ['side' => $side, 'active' => $active, 'loaded' => $loaded,
    'versions' => ['importer' => defined('WT_U_IEW_VERSION') ? WT_U_IEW_VERSION : null,
        'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null]];
ImporterWooLoadOrderEvidence::verify($record, $side);
echo json_encode($record, JSON_THROW_ON_ERROR), "\n";
