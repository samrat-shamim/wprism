<?php
/** Deterministic product-path proof for Visual Portfolio's native migration provider. */
declare(strict_types=1);

namespace {
    $wprismRoot = dirname(__DIR__, 4);
    require_once $wprismRoot . '/sandbox/tests/lib/check.php';

    define('VISUAL_PORTFOLIO_VERSION', '3.8.1');

    function get_option(string $name, mixed $default = false): mixed {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE BINARY option_name = %s LIMIT 1", $name),
            ARRAY_A
        );
        return is_array($row) ? maybe_unserialize($row['option_value']) : $default;
    }

    function update_option(string $name, mixed $value, string|bool|null $autoload = null): bool {
        global $wpdb;
        $raw = maybe_serialize($value);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $wpdb->options WHERE BINARY option_name = %s LIMIT 1", $name),
            ARRAY_A
        );
        if (is_array($row) && $row['option_value'] === $raw) {
            return false;
        }
        if (is_array($row)) {
            $wpdb->update($wpdb->options, ['option_value' => $raw], ['option_id' => $row['option_id']]);
        } else {
            $wpdb->insert($wpdb->options, [
                'option_name' => $name,
                'option_value' => $raw,
                'autoload' => $autoload === false || $autoload === 'no' ? 'no' : 'yes',
            ]);
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete($name, 'options');
        return true;
    }

    /** Semantic double of the exact 3.8.1 version dispatch and representative native writes. */
    final class Visual_Portfolio_Migrations {
        public function init(): void {
            $GLOBALS['vp_migration_invocations']++;
            $saved = get_option('vpf_db_version', '1.16.2');
            if (!is_string($saved)) {
                throw new RuntimeException('fixture migration cursor is malformed');
            }
            if (version_compare($saved, '3.0.0', '<')) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->postmeta,
                    ['meta_value' => 'post-based'],
                    ['post_id' => 20, 'meta_key' => 'vp_content_source']
                );
                $wpdb->insert($wpdb->postmeta, [
                    'post_id' => 20,
                    'meta_key' => 'vp_posts_source',
                    'meta_value' => 'portfolio',
                ]);
            }
            if (version_compare($saved, '2.15.0', '<')) {
                global $wpdb;
                $general = get_option('vp_general', []);
                $slug = $general['portfolio_slug'] ?? null;
                if (is_string($slug)) {
                    $wpdb->update($wpdb->posts, ['post_name' => $slug], ['ID' => 10]);
                    unset($general['portfolio_slug']);
                    update_option('vp_general', $general);
                    update_option('_transient_vp_flush_rewrite_rules', '1');
                }
            }
            if (version_compare($saved, '2.10.0', '<')) {
                $images = get_option('vp_images', []);
                if (isset($images['lazy_loading'])) {
                    $images['lazy_loading'] = $images['lazy_loading'] === 'off' ? '' : 'vp';
                    update_option('vp_images', $images);
                }
            }
            if (version_compare($saved, '1.11.0', '<')) {
                $popup = get_option('vp_popup_gallery', []);
                unset($popup['show_caption'], $popup['caption_title'], $popup['caption_description']);
                update_option('vp_popup_gallery', $popup);
            }
            if (($GLOBALS['vp_migration_fault'] ?? '') === 'throw-after-write') {
                update_option('vpf_db_version', '2.15.0');
                throw new RuntimeException('fixture native migration failed');
            }
            if (($GLOBALS['vp_migration_fault'] ?? '') !== 'leave-stale'
                && version_compare($saved, VISUAL_PORTFOLIO_VERSION, '<')) {
                update_option('vpf_db_version', VISUAL_PORTFOLIO_VERSION);
            }
        }
    }
}

namespace WPrism {
    final class Policy {}
}

namespace {
    require_once $wprismRoot . '/sandbox/tests/lib/wp_stubs.php';
    require_once $wprismRoot . '/sandbox/tests/lib/FakeWpdb.php';
    require_once $wprismRoot . '/agent/src/Adapter/ProviderSdk.php';
    require_once $wprismRoot . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once $wprismRoot . '/agent/src/Adapter/ProviderOperationProcess.php';
    require_once $wprismRoot . '/agent/src/Adapter/Providers.php';
    require_once $wprismRoot . '/agent/src/Kernel/StoragePrerequisiteSettlement.php';
    require_once $wprismRoot . '/adapter-packages/visual-portfolio/package/runtime/providers/visual-portfolio-migrations.php';

    use WPrism\ManifestProviderRuntime;
    use WPrism\Providers;
    use WPrism\Providers\VisualPortfolioMigrations;
    use WPrism\StoragePrerequisiteSettlement;
    use WPrismTest\FakeWpdb;
    use WPrismTest\WpStore;

    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/package/manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $migrationDeclaration = null;
    foreach ($manifest['providers'] as $declaration) {
        if (($declaration['id'] ?? null) === 'visual-portfolio-migrations') {
            $migrationDeclaration = $declaration;
        }
    }
    wprism_check(is_array($migrationDeclaration), 'manifest declares the dedicated migration provider');

    $inventory = StoragePrerequisiteSettlement::inventory([$manifest]);
    wprism_check_same('lifecycle-settle', $inventory[0]['settlement'] ?? null,
        'the storage cursor derives automatic settlement from its effect-covered same-manifest action');
    $selected = StoragePrerequisiteSettlement::actions_for_readiness(
        [$manifest],
        [['manifest' => 'visual-portfolio', 'option' => 'vpf_db_version', 'ready' => false]]
    );
    wprism_check_same(1, count($selected), 'missing storage selects exactly one provider action');
    wprism_check_same('visual-portfolio-migrations', $selected[0]['provider'] ?? null,
        'storage debt cannot enlist the settings action with irreversible sibling effects');
    wprism_check_same([], StoragePrerequisiteSettlement::actions_for_readiness(
        [$manifest],
        [['manifest' => 'visual-portfolio', 'option' => 'vpf_db_version', 'ready' => true]]
    ), 'a current cursor selects no lifecycle work');

    $db = FakeWpdb::install()->setServerVersion('8.4.0')->enableInformationSchema();
    foreach ([
        'options' => ['option_id', 'option_name', 'option_value', 'autoload'],
        'posts' => ['ID', 'post_name', 'post_content', 'post_type'],
        'postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
        'terms' => ['term_id', 'name', 'slug'],
        'term_taxonomy' => ['term_taxonomy_id', 'term_id', 'taxonomy'],
        'term_relationships' => ['object_id', 'term_taxonomy_id'],
        'termmeta' => ['meta_id', 'term_id', 'meta_key', 'meta_value'],
    ] as $property => $columns) {
        $db->seedTable($db->{$property}, [])->setColumns($db->{$property}, array_fill_keys($columns, 'longtext'))
            ->setTableEngine($db->{$property}, 'InnoDB');
    }
    $db->setUniqueKey($db->options, ['option_name'])
        ->setAutoIncrement($db->options, 1, 'option_id')
        ->setAutoIncrement($db->posts, 30, 'ID')
        ->setAutoIncrement($db->postmeta, 3, 'meta_id');
    $GLOBALS['wp_filter'] = [];
    WpStore::reset();

    $providerFile = realpath(dirname(__DIR__, 2) . '/package/runtime/providers/visual-portfolio-migrations.php');
    if (!is_string($providerFile)) {
        throw new RuntimeException('fixture provider source is unavailable');
    }
    $migrationDeclaration['manifest'] = 'visual-portfolio';
    $migrationDeclaration['_wprism_adapter_digest'] = str_repeat('a', 64);
    $migrationDeclaration['_wprism_adapter_library_root'] = dirname(__DIR__, 4);
    $migrationDeclaration['_wprism_execution_bound'] = true;
    $migrationDeclaration['_wprism_execution_identity'] = [
        'artifact_hash' => str_repeat('b', 64),
        'manifest_hash' => str_repeat('c', 64),
        'resolved_adapters_sha256' => str_repeat('d', 64),
        'site_hash' => str_repeat('e', 64),
    ];
    $migrationDeclaration['_wprism_plugin_runtime'] = [
        'active' => true,
        'installed' => true,
        'version' => VISUAL_PORTFOLIO_VERSION,
    ];
    $migrationDeclaration['_wprism_policy_snapshot'] = ['fixture' => 'visual-portfolio-migrations'];
    $migrationDeclaration['_wprism_provider_file'] = $providerFile;
    $migrationDeclaration['_wprism_provider_sha256'] = hash_file('sha256', $providerFile);
    $provider = new VisualPortfolioMigrations($migrationDeclaration);
    $bind = new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts');
    $bind->invoke(null, $provider, $provider->capabilities());
    $invokeDirect = new ReflectionMethod(ManifestProviderRuntime::class, 'invokeDirect');
    $settle = static fn(array $args = []): array => $invokeDirect->invoke($provider, 'settle_storage', $args);

    $seed = static function (?string $cursor) use ($db): void {
        $options = [
            ['option_id' => 1, 'option_name' => 'vp_general', 'option_value' => serialize([
                'portfolio_slug' => 'legacy-portfolio', 'preserved' => 'yes',
            ]), 'autoload' => 'yes'],
            ['option_id' => 2, 'option_name' => 'vp_images', 'option_value' => serialize([
                'lazy_loading' => 'off', 'preserved' => 'yes',
            ]), 'autoload' => 'yes'],
            ['option_id' => 3, 'option_name' => 'vp_popup_gallery', 'option_value' => serialize([
                'show_caption' => 'on', 'caption_title' => 'title', 'caption_description' => 'description',
                'preserved' => 'yes',
            ]), 'autoload' => 'yes'],
        ];
        if ($cursor !== null) {
            $options[] = ['option_id' => 4, 'option_name' => 'vpf_db_version', 'option_value' => $cursor, 'autoload' => 'yes'];
        }
        $db->seedTable($db->options, $options)->setAutoIncrement($db->options, 5, 'option_id');
        $db->seedTable($db->posts, [
            ['ID' => 10, 'post_name' => 'old-portfolio', 'post_content' => '', 'post_type' => 'page'],
            ['ID' => 20, 'post_name' => 'saved-layout', 'post_content' => '', 'post_type' => 'vp_lists'],
            ['ID' => 21, 'post_name' => 'unrelated', 'post_content' => 'keep', 'post_type' => 'post'],
        ]);
        $db->seedTable($db->postmeta, [
            ['meta_id' => 1, 'post_id' => 20, 'meta_key' => 'vp_content_source', 'meta_value' => 'portfolio'],
            ['meta_id' => 2, 'post_id' => 21, 'meta_key' => 'foreign_key', 'meta_value' => 'keep'],
        ])->setAutoIncrement($db->postmeta, 3, 'meta_id');
        foreach (['terms', 'term_taxonomy', 'term_relationships', 'termmeta'] as $property) {
            $db->seedTable($db->{$property}, []);
        }
        $GLOBALS['vp_migration_invocations'] = 0;
        $GLOBALS['vp_migration_fault'] = '';
        WpStore::reset();
    };

    $seed(null);
    $missing = $settle();
    wprism_check_same(null, $missing['before']['cursor'], 'the receipt preserves the natural missing cursor');
    wprism_check_same('3.8.1', $missing['after']['cursor'], 'native migration advances the exact physical cursor');
    wprism_check_same(1, $GLOBALS['vp_migration_invocations'], 'the provider runs native migration once and proves its second pass as a no-op');
    wprism_check_same('legacy-portfolio', $db->rows($db->posts)[0]['post_name'], 'the legacy archive slug migrates through native semantics');
    wprism_check_same('', get_option('vp_images')['lazy_loading'], 'the legacy lazy-loading value migrates');
    wprism_check_same([
        'show_caption' => 'on', 'caption_title' => 'title', 'caption_description' => 'description',
        'preserved' => 'yes',
    ], get_option('vp_popup_gallery'), 'the native missing-cursor default correctly starts after the older popup migration');
    wprism_check_same('post-based', $db->rows($db->postmeta)[0]['meta_value'], 'legacy layout metadata migrates');
    $foreign = array_values(array_filter(
        $db->rows($db->postmeta),
        static fn(array $row): bool => $row['meta_key'] === 'foreign_key'
    ));
    wprism_check_same('keep', $foreign[0]['meta_value'] ?? null, 'the native migration preserves unrelated metadata');

    $beforeReplay = [$db->rows($db->options), $db->rows($db->posts), $db->rows($db->postmeta)];
    $replay = $settle();
    wprism_check_same($replay['before'], $replay['after'], 'an already-current invocation is a physical no-op');
    wprism_check_same(1, $GLOBALS['vp_migration_invocations'], 'an already-current provider invocation does not re-enter native migration code');
    wprism_check_same($beforeReplay, [$db->rows($db->options), $db->rows($db->posts), $db->rows($db->postmeta)],
        'repeated settlement preserves every fixture row');

    $seed('3.8.0');
    $stale = $settle();
    wprism_check_same('3.8.0', $stale['before']['cursor'], 'the receipt preserves a stale in-range cursor');
    wprism_check_same('3.8.1', $stale['after']['cursor'], 'a stale cursor settles to the exact active code version');
    wprism_check_same('old-portfolio', $db->rows($db->posts)[0]['post_name'], 'later-version settlement does not replay historical migrations');

    $seed('1.0.0');
    $old = $settle();
    wprism_check_same('1.0.0', $old['before']['cursor'], 'an explicit pre-initial-version cursor remains distinct from absence');
    wprism_check_same(['preserved' => 'yes'], get_option('vp_popup_gallery'),
        'the native pre-1.11 popup migration removes only its three historical members');
    wprism_check_same('3.8.1', $old['after']['cursor'], 'the complete historical chain reaches the exact current cursor');

    $seed(null);
    $GLOBALS['vp_migration_fault'] = 'throw-after-write';
    $beforeFailure = [$db->rows($db->options), $db->rows($db->posts), $db->rows($db->postmeta)];
    try {
        $settle();
        wprism_check(false, 'a failing native migration cannot publish a receipt');
    } catch (RuntimeException $failure) {
        wprism_check(str_contains($failure->getMessage(), 'fixture native migration failed'),
            'the native migration failure remains the actionable cause');
    }
    wprism_check_same($beforeFailure, [$db->rows($db->options), $db->rows($db->posts), $db->rows($db->postmeta)],
        'the engine transaction rolls back every partial migration write');

    try {
        $settle(['forged' => true]);
        wprism_check(false, 'storage settlement cannot accept caller-authored arguments');
    } catch (RuntimeException $failure) {
        wprism_check_same('wprism: Visual Portfolio storage settlement accepts no authored arguments',
            $failure->getMessage(), 'the argument refusal is exact and value-free');
    }

    $observe = new ReflectionMethod(VisualPortfolioMigrations::class, 'observe_fresh_postimage_settle_storage');
    $project = new ReflectionMethod(VisualPortfolioMigrations::class, 'project_fresh_postimage_settle_storage');
    $seed('3.8.1');
    $projection = $project->invoke($provider, $observe->invoke($provider, []));
    wprism_check_same('3.8.1', $projection['cursor'], 'the independent observer accepts the exact durable cursor');
    $seed('3.8.0');
    try {
        $project->invoke($provider, $observe->invoke($provider, []));
        wprism_check(false, 'the independent fresh observer cannot accept stale storage');
    } catch (RuntimeException $failure) {
        wprism_check(str_contains($failure->getMessage(), 'version-incomplete'),
            'the independent fresh observer returns a recovery-required stale-storage refusal');
    }

    wprism_check_summary('regress_visual_portfolio_storage_migrations');
}
