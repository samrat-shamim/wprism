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
    WPrism\Canon::write_file($file, WPrism\Canon::encode($document));
    echo json_encode(['phase' => $phase, 'manifests' => $document['manifests']], JSON_THROW_ON_ERROR), "\n";
    return;
}
throw new RuntimeException('unknown combined Apply native phase');
