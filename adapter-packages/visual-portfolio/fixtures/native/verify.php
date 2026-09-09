<?php
declare(strict_types=1);
$check = static function (bool $ok, string $why): void { if (!$ok) throw new RuntimeException('VP capture readback: ' . $why); };
$check(current_user_can('manage_options'), 'owned administrator');
$policy = WPrism\Policy::load('/siterepo');
$seed = json_decode(file_get_contents(__DIR__ . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
$state = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/siterepo/state', FilesystemIterator::SKIP_DOTS)) as $file) {
    $check($file->isFile() && !$file->isLink() && $file->getSize() <= 4194304, 'bounded complete state file');
    $state[substr($file->getPathname(), strlen('/siterepo/state/'))] = base64_encode(WPrism\Canon::read_file($file->getPathname()));
}
$check(count($state) >= 10 && count($state) <= 128, 'nonvacuous complete state inventory');
ksort($state);
$options = WPrism\OptionState::values(WPrism\Canon::decode(WPrism\Canon::read_file('/siterepo/state/options/core.json')));
$check($options['vp_general']['portfolio_archive_page'] === '{{post:' . WPrism\Ledger::uuid_for($seed['pages']['vp-alternate-archive'], 'post') . '}}', 'archive selection captures the real UUID');
$check($options['vp_general']['no_image'] === '{{post:' . WPrism\Ledger::uuid_for($seed['images']['harbor'], 'post') . '}}', 'placeholder captures the real UUID');
$check(!array_key_exists('thumbs_position', $options['vp_popup_gallery']), 'hidden premium data stays out of canonical options');
foreach ($state as $path => $base64) {
    $bytes = base64_decode($base64, true);
    if (str_starts_with($path, 'posts/')) {
        $check(!str_contains($bytes, '_vp_post_type_mapped') && !str_contains($bytes, '_vp_views_count') && !str_contains($bytes, '_vp_words_count'), 'runtime and derived post metadata stay excluded');
    }
}
global $wpdb;
$beforeQueries = $wpdb->num_queries;
$compiled = WPrism\RepositoryCompiler::compile('/siterepo', $policy);
$check($wpdb->num_queries === $beforeQueries, 'immutable native fixture compilation needs no database query');
$check(count($compiled->tree()) >= 10, 'nonvacuous compiled native graph');
echo json_encode(['format' => 'wprism-vp-native-capture-readback/v1', 'qualification' => false, 'state' => $state,
    'entities' => count($compiled->tree()), 'artifact_hash' => $compiled->artifact_hash(), 'options' => $options], JSON_THROW_ON_ERROR), "\n";
