<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
require_once $root . '/agent/src/Apply/ApplyPreparationCoordinator.php';
require_once dirname(__DIR__, 2) . '/fixtures/dependency-evidence.php';
wprism_test_define_agent_versions();
$slug = 'users-customers-import-export-for-wp-woocommerce';
$plugin = ImporterDependencyEvidence::PLUGIN;
$policy = WPrism\Policy::load(null, ['core', $slug], adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
$facts = ['active_plugins' => [], 'plugins' => [$plugin => '2.7.5'], 'plugin_exists' => [$plugin => true],
    'template' => 'fixture', 'stylesheet' => 'fixture', 'themes' => ['fixture' => '1.0'], 'theme_exists' => ['fixture' => true], 'recorded_raw' => null];
$desired = ['active_plugins' => [$plugin]];
WPrismTest\WpStore::instance()->ensureUploadDir();
mkdir(dirname(WP_PLUGIN_DIR . '/' . $plugin), 0700, true);
file_put_contents(WP_PLUGIN_DIR . '/' . $plugin, "<?php\n/*\nPlugin Name: Importer fixture\nVersion: 2.7.5\n*/\n");
WPrismTest\FakeWpdb::install()->setColumns('options', [
    'option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)',
    'option_value' => 'longtext', 'autoload' => 'varchar(20)',
])->seedTable('options', [
    ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => serialize([]), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture', 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture', 'autoload' => 'yes'],
]);
register_shutdown_function(static fn() => WPrismTest\WpStore::reset());
$compiled = WPrism\CompiledRepository::create(['tree' => []]);
$unexpected = static fn() => throw new RuntimeException('dependency refusal must precede Apply services');
$services = new WPrism\ApplyServices($policy, $compiled, new WPrism\ApplyServiceCallbacks(
    taxonomyOwnership: $unexpected, renewPromotionLock: $unexpected,
    renewRegenerationLease: $unexpected, renewProviderLease: $unexpected,
    lockDeleteGuards: $unexpected, deletionDatabaseProfile: $unexpected,
    recheckDeleteGuard: $unexpected, selectionDeclaresChannelFor: $unexpected,
    selectionDeclaresEntityBatchFor: $unexpected, selectionTriggersProviderActionFor: $unexpected,
    pinnedProviderActionOwns: $unexpected, upsertMeta: $unexpected
), '/fixture/repo');
foreach (['inactive' => [], 'deactivated' => [], 'missing' => ['plugins' => [], 'plugin_exists' => [$plugin => false]], 'prior' => ['plugins' => [$plugin => '2.7.4']]] as $case => $changes) {
    try {
        $observation = array_replace($facts, $changes);
        if (in_array($case, ['inactive', 'deactivated'], true)) {
            $plan = array_fill_keys(['collision', 'conflict', 'delete_conflict', 'drift', 'create', 'update', 'missing_user'], []);
            $plan['code_mismatch'] = WPrism\LifecyclePlanner::code_mismatch($policy, $desired);
            if ($case === 'deactivated') $plan['drift'] = [['path' => 'options/core.json']];
            $coordinator = new WPrism\ApplyPreparationCoordinator('/fixture/repo', $policy, $services,
                new WPrism\RebuildSelection($policy), new WPrism\ScopedApplyWorkflow(), $unexpected, $unexpected);
            $request = new WPrism\ApplyPreparationRequest([], $compiled, $plan, [], false, false, false, false,
                'dependency-probe', str_repeat('e', 64));
            $warnings = []; $overrides = [];
            $coordinator->prepare($request, $warnings, $overrides);
        } else {
            WPrism\LifecyclePlanner::deployment_status_from_observation($policy, $desired, false, $observation);
        }
        $actual = null;
    } catch (RuntimeException $failure) {
        $actual = $failure->getMessage();
        wprism_check_same(ImporterDependencyEvidence::profile($case)['nodes'][0]['class'], get_class($failure),
            'refusal receipt names the exact thrown type: ' . $case);
    }
    wprism_check_same(ImporterDependencyEvidence::profile($case)['nodes'][0]['message'], $actual,
        'native refusal cause is prebound to the actual public preparation gate: ' . $case);
}
$state = ['format' => 'wprism-importer-native-settings/v1', 'tables' => array_fill_keys([
    'posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
    'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], [['fixture' => 'preserve']]),
    'settings' => ['wt_iew_default_export_batch' => 41], 'files' => ['webtoffee_export/fixture.csv' => str_repeat('a', 64)]];
$state['tables']['options'] = [['option_id' => '12', 'option_name' => 'wt_iew_advanced_settings', 'option_value' => 'native', 'autoload' => 'yes']];
wprism_check(ImporterDependencyEvidence::retained($state, $state), 'complete retention control is admitted');
foreach (array_keys($state['tables']) as $table) {
    $changed = $state;
    if ($table === 'options') $changed['tables'][$table][0]['option_value'] = 'changed';
    else $changed['tables'][$table][0]['fixture'] = 'changed';
    wprism_check(!ImporterDependencyEvidence::retained($state, $changed), 'retention rejects changed native rows: ' . $table);
}
foreach (['files', 'settings'] as $key) {
    $changed = $state; $changed[$key] = [];
    wprism_check(!ImporterDependencyEvidence::retained($state, $changed), 'retention rejects lost ' . $key);
}
$changed = $state; unset($changed['tables']['usermeta']);
wprism_check(!ImporterDependencyEvidence::retained($state, $changed), 'retention rejects an incomplete table roster');
wprism_check(!ImporterDependencyEvidence::retained([], []), 'matching empty observations cannot prove native retention');
wprism_check_throws(static fn() => ImporterDependencyEvidence::profile('unknown'), RuntimeException::class,
    'unknown refusal cannot select a generic success predicate');
wprism_check_summary('Importer dependency evidence');
