<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer/Woo Apply fixture: ' . $why);
};
$check(is_admin() && current_user_can('manage_options') && defined('WC_VERSION') && WC_VERSION === '11.0.1'
    && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5', 'locked native administrator context');
add_filter('pre_wp_mail', static fn() => true);
if ($phase === 'configure') {
    $file = '/siterepo/site.wprism.json';
    $document = WPrism\Canon::decode(WPrism\Canon::read_file($file));
    $check($document['manifests'] === ['core', 'users-customers-import-export-for-wp-woocommerce'], 'known capsule seed policy');
    $document['manifests'][] = 'woocommerce';
    require_once WPMU_PLUGIN_DIR . '/wprism/src/Policy/Policy.php';
    $policy = WPrism\Policy::load(null, $document['manifests']);
    // Selecting both adapters also selects their reviewed authored surfaces.
    // Copying the capsule's core-only scope hid Woo's populated taxonomies.
    foreach ($policy->declared_post_types() as $name) {
        if (($policy->post_type_rule_details($name)['rule']['class'] ?? null) === 'authored') $document['policy']['post_types'][] = $name;
    }
    foreach ($policy->declared_taxonomies() as $name) {
        if (($policy->taxonomy_rule_details($name)['rule']['class'] ?? null) === 'authored') $document['policy']['taxonomies'][] = $name;
    }
    foreach (['post_types', 'taxonomies'] as $key) {
        $document['policy'][$key] = array_values(array_unique($document['policy'][$key]));
        sort($document['policy'][$key], SORT_STRING);
    }
    WPrism\Canon::write_file($file, WPrism\Canon::encode($document));
    echo json_encode(['phase' => $phase, 'manifests' => $document['manifests'], 'scope' => $document['policy']], JSON_THROW_ON_ERROR), "\n";
    return;
}
throw new RuntimeException('unknown combined Apply native phase');
