<?php
declare(strict_types=1);

$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Visual Portfolio native migration: ' . $why);
};
$check(current_user_can('manage_options')
    && defined('VISUAL_PORTFOLIO_VERSION')
    && VISUAL_PORTFOLIO_VERSION === '3.8.1'
    && class_exists('Visual_Portfolio_Migrations'), 'exact plugin migration runtime and administrator');

global $wpdb;
foreach ([$wpdb->options, $wpdb->posts, $wpdb->postmeta] as $table) {
    $check(is_string($table) && preg_match('/^[A-Za-z0-9_]+$/D', $table) === 1, 'bounded native table name');
}
$read = static function (string $sql, string $label) use ($wpdb, $check): array {
    $wpdb->last_error = '';
    $value = $wpdb->get_results($sql, ARRAY_A);
    $check(is_array($value) && count($value) <= 4096 && $wpdb->last_error === '', $label . ' bounded query');
    return $value;
};
$project = static function (array $value, string $label) use ($check): array {
    $check(count($value) <= 4096, $label . ' bounded rows');
    $bytes = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $check(strlen($bytes) <= 4194304, $label . ' bounded bytes');
    return ['count' => count($value), 'sha256' => hash('sha256', $bytes)];
};
$rows = static fn(string $sql, string $label): array => $project($read($sql, $label), $label);
$observe = static function () use ($wpdb, $read, $rows, $project, $check): array {
    $wpdb->last_error = '';
    $cursorRows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_value, autoload FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 2",
        'vpf_db_version'
    ), ARRAY_A);
    $check(is_array($cursorRows) && count($cursorRows) <= 1 && $wpdb->last_error === '', 'exact physical cursor');
    $cursor = $cursorRows[0]['option_value'] ?? null;
    $check($cursor === null || (is_string($cursor) && strlen($cursor) <= 64
        && preg_match('/^[0-9A-Za-z._-]+$/D', $cursor) === 1), 'bounded cursor value');
    $images = get_option('vp_images', null);
    $lazyLoading = is_array($images) && array_key_exists('lazy_loading', $images)
        ? $images['lazy_loading']
        : null;
    $check($lazyLoading === null || is_string($lazyLoading), 'bounded native lazy-loading value type');
    if (is_string($lazyLoading)) $check(strlen($lazyLoading) <= 64, 'bounded native lazy-loading value');
    $names = [
        '_vp_add_archive_page', '_vp_trying_to_add_archive_page',
        'vp_general', 'vp_images', 'vp_popup_gallery', 'vpf_db_version',
    ];
    $quoted = implode(', ', array_map(
        static fn(string $name): string => "'" . esc_sql($name) . "'",
        $names
    ));
    $archiveValue = get_option('_vp_add_archive_page', false);
    $archiveId = null;
    if ($archiveValue !== false && $archiveValue !== null && $archiveValue !== '' && $archiveValue !== '0'
        && $archiveValue !== 0) {
        $check((is_int($archiveValue) || is_string($archiveValue))
            && preg_match('/^[1-9][0-9]*$/D', (string) $archiveValue) === 1
            && (string) (int) $archiveValue === (string) $archiveValue,
            'bounded legacy archive post identity');
        $archiveId = (int) $archiveValue;
    }
    $postRows = $read(
        "SELECT * FROM `{$wpdb->posts}` WHERE post_type IN ('page', 'vp_lists') ORDER BY ID LIMIT 4097",
        'migration posts'
    );
    $archivePost = null;
    if ($archiveId !== null) {
        $archiveRows = $read($wpdb->prepare(
            "SELECT * FROM `{$wpdb->posts}` WHERE ID = %d ORDER BY ID LIMIT 2",
            $archiveId
        ), 'legacy archive post');
        $check(count($archiveRows) <= 1, 'unique legacy archive post identity');
        $archivePost = $archiveRows[0] ?? null;
        $byId = [];
        foreach (array_merge($postRows, $archiveRows) as $row) $byId[(string) $row['ID']] = $row;
        ksort($byId, SORT_NUMERIC);
        $postRows = array_values($byId);
    }
    return [
        'cursor' => $cursor,
        'lazy_loading' => $lazyLoading,
        'options' => $rows(
            "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` "
                . "WHERE option_name IN ($quoted) ORDER BY option_name LIMIT 4097",
            'migration options'
        ),
        'posts' => $project($postRows, 'migration posts'),
        'archive_post' => $archivePost === null ? null : [
            'ID' => $archivePost['ID'],
            'post_type' => $archivePost['post_type'],
            'post_name' => $archivePost['post_name'],
        ],
        'postmeta' => $rows(
            "SELECT * FROM `{$wpdb->postmeta}` WHERE meta_key REGEXP '^_?vp_' ORDER BY meta_id LIMIT 4097",
            'migration postmeta'
        ),
    ];
};

$mode = $args[0] ?? 'observe';
if ($mode === 'observe') {
    echo json_encode(['format' => 'wprism-vp-native-migration/v1', 'state' => $observe()], JSON_THROW_ON_ERROR), "\n";
    return;
}
$id = static function (mixed $value, string $label) use ($check): int {
    $check((is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
        && (string) (int) $value === (string) $value, $label . ' positive post identity');
    return (int) $value;
};
if ($mode === 'seed-legacy-archive') {
    $original = get_option('_vp_add_archive_page', false);
    $originalId = ($original === false || $original === null || $original === '' || $original === '0' || $original === 0)
        ? 0
        : $id($original, 'original archive');
    $legacyId = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Visual Portfolio legacy archive target',
        'post_name' => 'vp-legacy-archive-before-migration',
    ], true);
    $check(is_int($legacyId) && $legacyId > 0, 'native ordinary-post legacy archive fixture');
    $general = get_option('vp_general', []);
    $check(is_array($general) && !array_key_exists('portfolio_slug', $general), 'clean legacy slug premise');
    $general['portfolio_slug'] = 'vp-migrated-ordinary-post';
    update_option('vp_general', $general);
    update_option('_vp_add_archive_page', $legacyId);
    $state = $observe();
    $check(($state['archive_post']['ID'] ?? null) === (string) $legacyId
        && ($state['archive_post']['post_type'] ?? null) === 'post'
        && ($state['archive_post']['post_name'] ?? null) === 'vp-legacy-archive-before-migration',
        'ordinary-post legacy archive is inside the bounded migration projection');
    echo json_encode([
        'format' => 'wprism-vp-native-migration-fixture/v1',
        'legacy_id' => $legacyId,
        'original_archive_id' => $originalId,
        'state' => $state,
    ], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($mode === 'cleanup-legacy-archive') {
    $legacyId = $id($args[1] ?? null, 'legacy archive');
    $originalId = $args[2] ?? '0';
    $check($originalId === '0' || (preg_match('/^[1-9][0-9]*$/D', (string) $originalId) === 1
        && (string) (int) $originalId === (string) $originalId), 'original archive identity');
    $legacy = get_post($legacyId);
    $check($legacy instanceof WP_Post && $legacy->post_type === 'post'
        && $legacy->post_name === 'vp-migrated-ordinary-post', 'native migration changed the ordinary archive target');
    $check(wp_delete_post($legacyId, true) instanceof WP_Post, 'fixture legacy archive cleanup');
    if ($originalId === '0') delete_option('_vp_add_archive_page');
    else update_option('_vp_add_archive_page', (int) $originalId);
    echo json_encode(['legacy_id' => $legacyId, 'restored_archive_id' => (int) $originalId], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check(in_array($mode, ['native-settle', 'provider-settle'], true), 'known fixture mode');
$before = $observe();
$provider = null;
$replayActions = null;
if ($mode === 'native-settle') {
    $migration = new Visual_Portfolio_Migrations();
    $migration->init();
    $after = $observe();
    $migration->init();
    $fixed = $observe();
} else {
    $repo = '/siterepo';
    $artifactPath = __DIR__ . '/migration-compiled.json';
    $policy = WPrism\Policy::load($repo);
    WPrism\RepositoryCompiler::compile($repo, $policy)->write($artifactPath);
    try {
        $policy = WPrism\Policy::load($repo);
        WPrism\RepositoryCompiler::read_artifact($artifactPath, $policy);
        $readiness = WPrism\StoragePrerequisites::readiness($policy->manifests);
        $actions = WPrism\StoragePrerequisiteSettlement::actions_for_readiness(
            $policy->manifests,
            $readiness
        );
        $receipts = WPrism\ProviderPhaseExecutor::run(
            $policy,
            $actions,
            'Visual Portfolio conformance migration settlement',
            static function (): void {}
        );
        WPrism\StoragePrerequisites::assert_ready($policy->manifests);
        $after = $observe();
        $replayActions = WPrism\StoragePrerequisiteSettlement::actions_for_readiness(
            $policy->manifests,
            WPrism\StoragePrerequisites::readiness($policy->manifests)
        );
        $fixed = $observe();
        $provider = [
            'actions' => count($actions),
            'receipts' => array_map(static fn(array $row): array => [
                'manifest' => $row['manifest'],
                'provider' => $row['provider'],
                'capability' => $row['capability'],
            ], $receipts),
        ];
    } finally {
        if (is_file($artifactPath)) unlink($artifactPath);
    }
}
$check($after['cursor'] === VISUAL_PORTFOLIO_VERSION, 'native procedure advances exact cursor');
$check($after === $fixed, 'native procedure reaches a bounded observed migration-surface fixed point');
echo json_encode([
    'format' => 'wprism-vp-native-migration/v1',
    'mode' => $mode,
    'before' => $before,
    'after' => $after,
    'bounded_observed_fixed_point' => true,
    'provider' => $provider,
    'replay_actions' => is_array($replayActions) ? count($replayActions) : null,
], JSON_THROW_ON_ERROR), "\n";
