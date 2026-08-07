#!/usr/bin/env bash
# Offline code/state identity contract.  No WordPress, database, Composer, or
# target filesystem is contacted: the compiler only sees a temporary repo.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
$root = getenv('DUO_ROOT');
define('DUO_SPEC_VERSION', 2);
foreach (['Canon', 'OptionState', 'Uuid', 'Db', 'Ledger', 'Policy', 'Snapshot', 'Deletion', 'RepositoryAuthorization', 'SidebarState', 'Code', 'RepositoryCompiler', 'CodeStateContract'] as $file) {
    require_once "$root/agent/src/$file.php";
}

use Duo\Canon;
use Duo\Code;
use Duo\CodeCompatibility;
use Duo\CompiledRepository;
use Duo\OptionState;
use Duo\Policy;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;

function fail_test(string $message): never { throw new RuntimeException("FAIL: $message"); }
function assert_test(bool $condition, string $message): void {
    if (!$condition) { fail_test($message); }
}
function put_test(string $path, string $bytes): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        fail_test("cannot create " . dirname($path));
    }
    if (file_put_contents($path, $bytes) === false) { fail_test("cannot write $path"); }
}
function remove_test(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') { remove_test($path . '/' . $child); }
    }
    @rmdir($path);
}
/** Recreate the exact released duo-code/v1 descriptor shape. */
function legacy_descriptor_test(array $descriptor): array {
    unset($descriptor['theme_templates'], $descriptor['code_revision']);
    $descriptor['code_revision'] = Code::revision_for($descriptor);
    return $descriptor;
}

/** Minimal read-only wpdb seam for ledger descriptor/history loading. */
final class DescriptorLedgerWpdb {
    public string $prefix = 'wp_';
    /** @var array<string,string> */
    public array $kv = [];

    public function prepare(string $_sql, mixed ...$args): string {
        return 'duo-test-kv:' . (string) ($args[0] ?? '');
    }

    public function get_var(string $query): ?string {
        $prefix = 'duo-test-kv:';
        if (!str_starts_with($query, $prefix)) {
            throw new RuntimeException("FAIL: unexpected ledger query '$query'");
        }
        return $this->kv[substr($query, strlen($prefix))] ?? null;
    }
}

$repo = sys_get_temp_dir() . '/duo-code-contract-' . bin2hex(random_bytes(6));
$target = sys_get_temp_dir() . '/duo-code-contract-target-' . bin2hex(random_bytes(6));
mkdir($repo . '/state', 0777, true);
mkdir($target, 0777, true);
define('WP_CONTENT_DIR', $target);
define('WP_PLUGIN_DIR', $target . '/plugins');
define('WPMU_PLUGIN_DIR', $target . '/mu-plugins');
register_shutdown_function(static function () use ($repo, $target): void {
    remove_test($repo);
    remove_test($target);
});

put_test($repo . '/site.duo.json', Canon::encode([
    'manifests' => ['core'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => [], 'taxonomies' => [],
    ],
    'spec_version' => 2,
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
]));
put_test($repo . '/code/wp-content/plugins/example/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\n*/\n");
// A PHP include is inventory-owned but must not be blessed as a plugin main file.
put_test($repo . '/code/wp-content/plugins/example/include.php', "<?php\n// no WordPress Plugin Name header\n");
put_test($repo . '/code/wp-content/plugins/example/late.php', str_repeat("x\n", 5000) . "# Plugin Name: Late\n");
// WordPress get_plugins() discovers plugin headers only at the plugins root
// or one directory below it. A deep include must not satisfy active_plugins.
put_test($repo . '/code/wp-content/plugins/example/includes/fake-main.php', "<?php\n/*\nPlugin Name: Deep fake\n*/\n");
put_test($repo . '/code/wp-content/themes/example/style.css', "/*\nTheme Name: Example\n*/\n");
put_test($repo . '/code/wp-content/themes/example/index.php', "<?php\n");
put_test($repo . '/code/wp-content/mu-plugins/bootstrap.php', "<?php\n");

// Code descriptor discovery must use the same bounded WordPress header
// grammar as get_file_data(): line/shell comments, an opening PHP marker,
// and close-comment/PHP cleanup all discover the same components.
$headerSource = $repo . '/header-forms';
put_test($headerSource . '/plugins/line/line.php', "<?php\n// Plugin Name: Line Plugin */\n");
put_test($headerSource . '/plugins/hash.php', "<?php\n# Plugin Name: Hash Plugin\n");
put_test($headerSource . '/plugins/php/php.php', "<?php/* Plugin Name: PHP Plugin ?>\n");
put_test($headerSource . '/themes/line-theme/style.css', "// Theme Name: Line Theme\n// Template: parent */\n");
put_test($headerSource . '/themes/hash-theme/style.css', "# Theme Name: Hash Theme\n# Template: parent\n");
put_test($headerSource . '/themes/php-theme/style.css', "<?php/* Theme Name: PHP Theme */\n<?php/* Template: parent ?>\n");
put_test($headerSource . '/themes/parent/style.css', "/*\nTheme Name: Parent\n*/\n");
$headerDescriptor = Code::descriptor_from_source($headerSource);
$headerBasenames = array_column($headerDescriptor['plugin_main_files'], 'basename');
sort($headerBasenames, SORT_STRING);
assert_test(
    $headerBasenames === ['hash.php', 'line/line.php', 'php/php.php'],
    'WordPress comment/PHP-opening Plugin Name headers were not discovered'
);
assert_test(
    $headerDescriptor['theme_templates'] === [
        'hash-theme' => 'parent',
        'line-theme' => 'parent',
        'parent' => null,
        'php-theme' => 'parent',
    ],
    'WordPress comment/PHP-opening Theme Name/Template headers were not cleaned or discovered'
);
assert_test(
    CodeCompatibility::header_value($headerSource . '/plugins/line/line.php', 'Plugin Name') === 'Line Plugin',
    'trailing block-comment terminator was not removed from a Plugin Name header'
);
assert_test(
    CodeCompatibility::header_value($headerSource . '/plugins/php/php.php', 'Plugin Name') === 'PHP Plugin',
    'trailing PHP terminator was not removed from a PHP-opening Plugin Name header'
);
assert_test(
    CodeCompatibility::header_value($repo . '/code/wp-content/plugins/example/late.php', 'Plugin Name') === null,
    'headers beyond the first 8 KiB must remain undiscoverable'
);

// A code-enabled artifact has to carry explicit lifecycle intent. A present
// empty plugin list is meaningful; absent/deleted is not, because finalize
// may prune an older managed component after Deploy has intentionally skipped
// a non-present record.
$records = [];
foreach ([
    'active_plugins', 'blogdescription', 'blogname', 'default_category', 'page_for_posts',
    'page_on_front', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet',
    'template', 'wp_page_for_privacy_policy',
] as $name) {
    $records[$name] = OptionState::absent();
}
$records['active_plugins'] = OptionState::present(['example/example.php'], 'yes');
$records['template'] = OptionState::present('example', 'yes');
$records['stylesheet'] = OptionState::present('example', 'yes');
$baselineRecords = $records;
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));

$compile = static function () use ($repo) {
    $policy = Policy::load($repo);
    return RepositoryCompiler::compile($repo, $policy);
};

$baseline = $compile();
$descriptor = $baseline->code_descriptor();
assert_test(is_array($descriptor), 'opted-in repo must carry a code descriptor');
assert_test($descriptor['owned_roots'] === ['mu-plugins/bootstrap.php', 'plugins/example', 'themes/example'], 'ownership must be component-scoped and sorted');
assert_test(count($descriptor['plugin_main_files']) === 1, 'only a WordPress-discoverable Plugin Name header may be a plugin main file');
assert_test($descriptor['plugin_main_files'][0]['basename'] === 'example/example.php', 'plugin basename must be relative to plugins/');
assert_test($descriptor['theme_slugs'] === ['example'], 'theme slug must come from a valid style.css header');
assert_test($descriptor['theme_templates'] === ['example' => null], 'theme descriptor must map every standalone theme to a null Template header');
$baselineRevision = $baseline->revision_hash();
$baselineCodeRevision = $baseline->code_revision();

// The code opt-in itself belongs to the outer artifact/code half, not to the
// canonical database-state identity. Removing only that declaration must
// preserve revision_hash while disabling code materialization and moving the
// full policy/artifact identities.
$siteWithCode = Canon::decode(Canon::read_file($repo . '/site.duo.json'));
$siteWithoutCode = $siteWithCode;
unset($siteWithoutCode['code']);
put_test($repo . '/site.duo.json', Canon::encode($siteWithoutCode));
$stateOnlyPolicy = $compile();
assert_test(
    $stateOnlyPolicy->revision_hash() === $baselineRevision,
    'toggling only the code declaration must not change state revision_hash'
);
assert_test($stateOnlyPolicy->code_descriptor() === null, 'removing code opt-in must disable the code descriptor');
assert_test(
    $stateOnlyPolicy->site_hash() !== $baseline->site_hash(),
    'full site policy identity must still bind the code declaration'
);
assert_test(
    $stateOnlyPolicy->artifact_hash() !== $baseline->artifact_hash(),
    'outer artifact identity must still bind code opt-in and descriptor presence'
);
put_test($repo . '/site.duo.json', Canon::encode($siteWithCode));

// The released duo-code/v1 descriptor did not carry child-theme linkage.
// It remains exact-shape, self-verifying deletion authority when loaded from
// completed/staged/history ledger rows; only newly compiled descriptors add
// the strictly validated relation.
$legacyDescriptor = legacy_descriptor_test($descriptor);
Code::assert_descriptor($legacyDescriptor);
$legacyWithExtra = $legacyDescriptor;
$legacyWithExtra['unexpected'] = true;
try {
    Code::assert_descriptor($legacyWithExtra);
    fail_test('legacy descriptor accepted an arbitrary extra key');
} catch (RuntimeException $e) {
    assert_test(str_contains($e->getMessage(), 'unsupported or malformed shape'), 'legacy extra-key refusal was unclear');
}
$GLOBALS['wpdb'] = new DescriptorLedgerWpdb();
$GLOBALS['wpdb']->kv = [
    Code::CODE_DESCRIPTOR_KEY => Canon::encode($legacyDescriptor),
    Code::CODE_REVISION_KEY => $legacyDescriptor['code_revision'],
    Code::CODE_STAGE_HISTORY_KEY => Canon::encode([$legacyDescriptor]),
];
$storedDescriptor = new ReflectionMethod(Code::class, 'stored_descriptor');
$storedDescriptor->setAccessible(true);
$loadedLegacy = $storedDescriptor->invoke(null);
assert_test(is_array($loadedLegacy) && Canon::encode($loadedLegacy) === Canon::encode($legacyDescriptor), 'completed legacy descriptor no longer loads as self-verifying ownership');
$storedHistory = new ReflectionMethod(Code::class, 'stored_stage_history');
$storedHistory->setAccessible(true);
$loadedHistory = $storedHistory->invoke(null);
assert_test(
    isset($loadedHistory[$legacyDescriptor['code_revision']])
        && Canon::encode($loadedHistory[$legacyDescriptor['code_revision']]) === Canon::encode($legacyDescriptor),
    'legacy descriptor no longer loads from staged history as deletion authority'
);
unset($GLOBALS['wpdb']);

// State-only change: state revision moves, code revision remains independent.
$records = $baselineRecords;
$records['blogname'] = OptionState::present('State-only change', 'yes');
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
$stateChanged = $compile();
assert_test($stateChanged->revision_hash() !== $baselineRevision, 'state-only change must change revision_hash');
assert_test($stateChanged->code_revision() === $baselineCodeRevision, 'state-only change must not change code_revision');
assert_test($stateChanged->artifact_hash() !== $baseline->artifact_hash(), 'state-only artifact must still bind the new state');

// Present is intentional for every managed lifecycle record. In particular,
// active_plugins: [] is an explicit deactivation program; `absent` is not.
$absentPlugins = $baselineRecords;
$absentPlugins['active_plugins'] = OptionState::absent();
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($absentPlugins)));
try {
    $compile();
    fail_test('code-enabled compile accepted an absent active_plugins lifecycle record');
} catch (RepositoryCompilationException $e) {
    assert_test(in_array('code_state_mismatch', array_column($e->diagnostics, 'code'), true), 'absent active_plugins must be a compiler code/state gate');
    assert_test(str_contains($e->getMessage(), 'canonical active_plugins lifecycle record must be present'), 'absent active_plugins compiler refusal was unclear');
}
$absentPluginPayload = $baseline->export();
$absentPluginPayload['tree']['options/core']['data'] = OptionState::document($absentPlugins);
$absentPluginCompiled = CompiledRepository::create($absentPluginPayload);
$absentPluginStageFailure = null;
try {
    Code::stage($repo, $absentPluginCompiled, [
        'artifact_hash' => $absentPluginCompiled->artifact_hash(),
        'promotion_owner' => 'absent-active-plugins-stage-refusal',
    ]);
} catch (RuntimeException $e) {
    $absentPluginStageFailure = $e->getMessage();
}
assert_test($absentPluginStageFailure !== null, 'code-stage accepted an absent active_plugins lifecycle record');
assert_test(str_contains($absentPluginStageFailure, 'canonical active_plugins lifecycle record must be present'), 'absent active_plugins stage refusal was unclear');
assert_test((scandir($target) ?: []) === ['.', '..'], 'absent active_plugins stage refusal mutated target bytes');
$explicitNoPlugins = $baselineRecords;
$explicitNoPlugins['active_plugins'] = OptionState::present([], 'yes');
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($explicitNoPlugins)));
$noPlugins = $compile();
assert_test($noPlugins->code_revision() === $baselineCodeRevision, 'an explicit empty active_plugins list must remain valid lifecycle intent');

// Theme lifecycle is similarly a pair of explicit values. Either absent slot
// would make a later theme prune occur without a WordPress switch_theme()
// instruction, so the frozen artifact fails before target contact.
$absentStylesheet = $baselineRecords;
$absentStylesheet['stylesheet'] = OptionState::absent();
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($absentStylesheet)));
try {
    $compile();
    fail_test('code-enabled compile accepted an absent stylesheet lifecycle record');
} catch (RepositoryCompilationException $e) {
    assert_test(in_array('code_state_mismatch', array_column($e->diagnostics, 'code'), true), 'absent stylesheet must be a compiler code/state gate');
    assert_test(str_contains($e->getMessage(), 'canonical stylesheet lifecycle record must be present'), 'absent stylesheet compiler refusal was unclear');
}
$absentThemePayload = $baseline->export();
$absentThemePayload['tree']['options/core']['data'] = OptionState::document($absentStylesheet);
$absentThemeCompiled = CompiledRepository::create($absentThemePayload);
$absentThemeStageFailure = null;
try {
    Code::stage($repo, $absentThemeCompiled, [
        'artifact_hash' => $absentThemeCompiled->artifact_hash(),
        'promotion_owner' => 'absent-stylesheet-stage-refusal',
    ]);
} catch (RuntimeException $e) {
    $absentThemeStageFailure = $e->getMessage();
}
assert_test($absentThemeStageFailure !== null, 'code-stage accepted an absent stylesheet lifecycle record');
assert_test(str_contains($absentThemeStageFailure, 'canonical stylesheet lifecycle record must be present'), 'absent stylesheet stage refusal was unclear');
assert_test((scandir($target) ?: []) === ['.', '..'], 'absent stylesheet stage refusal mutated target bytes');

$absentTemplate = $baselineRecords;
$absentTemplate['template'] = OptionState::absent();
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($absentTemplate)));
try {
    $compile();
    fail_test('code-enabled compile accepted an absent template lifecycle record');
} catch (RepositoryCompilationException $e) {
    assert_test(in_array('code_state_mismatch', array_column($e->diagnostics, 'code'), true), 'absent template must be a compiler code/state gate');
    assert_test(str_contains($e->getMessage(), 'canonical template lifecycle record must be present'), 'absent template compiler refusal was unclear');
}

$deletedPlugins = $baselineRecords;
$deletedPlugins['active_plugins'] = OptionState::deleted($baselineRecords['active_plugins']);
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($deletedPlugins)));
try {
    $compile();
    fail_test('code-enabled compile accepted a deleted active_plugins lifecycle record');
} catch (RepositoryCompilationException $e) {
    assert_test(str_contains($e->getMessage(), "canonical active_plugins lifecycle record must be present in state/options/core.json; it is 'deleted'"), 'deleted active_plugins compiler refusal was unclear');
}

$records = $baselineRecords;
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));

// A child theme is not compatible merely because both directories exist: its
// bounded Template header must name the parent canonical state declares. This
// is a compiler gate, so a bad relation cannot reach code-stage.
put_test($repo . '/code/wp-content/themes/parent/style.css', "/*\nTheme Name: Parent\n*/\n");
put_test($repo . '/code/wp-content/themes/child/style.css', "/*\nTheme Name: Child\nTemplate: other-parent\n*/\n");
$records['template'] = OptionState::present('parent', 'yes');
$records['stylesheet'] = OptionState::present('child', 'yes');
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
try {
    $compile();
    fail_test('compile accepted a child Template header that disagreed with canonical template');
} catch (RepositoryCompilationException $e) {
    assert_test(in_array('code_state_mismatch', array_column($e->diagnostics, 'code'), true), 'child Template refusal must be a code/state compiler gate');
    assert_test(str_contains($e->getMessage(), "canonical child stylesheet 'child' requires Template header 'parent'"), 'child Template compiler refusal was unclear');
}

// Stage receives a frozen descriptor and must re-run the same bridge before
// any target write or lease/ledger mutation. CompiledRepository::create()
// deliberately validates descriptor shape, not this state-dependent contract.
$wrongChildDescriptor = Code::descriptor_from_source($repo . '/code/wp-content');
$stagePayload = $baseline->export();
$stagePayload['tree'] = [
    'options/core' => ['data' => OptionState::document($records)],
];
$stagePayload['code'] = $wrongChildDescriptor;
$stageCompiled = CompiledRepository::create($stagePayload);
$stageFailure = null;
try {
    Code::stage($repo, $stageCompiled, [
        'artifact_hash' => $stageCompiled->artifact_hash(),
        'promotion_owner' => 'theme-template-stage-refusal',
    ]);
} catch (RuntimeException $e) {
    $stageFailure = $e->getMessage();
}
assert_test($stageFailure !== null, 'code-stage accepted a child Template header that disagreed with canonical template');
assert_test(str_contains($stageFailure, "canonical child stylesheet 'child' requires Template header 'parent'"), 'child Template stage refusal was unclear');
assert_test(!file_exists($target . '/themes/child/style.css') && !file_exists($target . '/themes/parent/style.css'), 'child Template stage refusal mutated target theme bytes');
assert_test((scandir($target) ?: []) === ['.', '..'], 'child Template stage refusal created a target path before refusing');

put_test($repo . '/code/wp-content/themes/child/style.css', "/*\nTheme Name: Child\nTemplate: parent\n*/\n");
$linked = $compile();
$linkedDescriptor = $linked->code_descriptor();
assert_test(is_array($linkedDescriptor), 'linked child theme compile lost its code descriptor');
assert_test(
    $linkedDescriptor['theme_templates'] === ['child' => 'parent', 'example' => null, 'parent' => null],
    'theme_templates must deterministically map every theme slug to its bounded Template header'
);

// The relation is exact in both directions. A real child theme cannot be
// represented as its own canonical template merely because both lifecycle
// slots name the same installed slug; WordPress would still resolve the
// bounded Template header to its parent after stage.
$standaloneChildRecords = $records;
$standaloneChildRecords['template'] = OptionState::present('child', 'yes');
$standaloneChildRecords['stylesheet'] = OptionState::present('child', 'yes');
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($standaloneChildRecords)));
try {
    $compile();
    fail_test('compile accepted a child Template header while canonical state called it standalone');
} catch (RepositoryCompilationException $e) {
    assert_test(in_array('code_state_mismatch', array_column($e->diagnostics, 'code'), true), 'inverse child Template refusal must be a code/state compiler gate');
    assert_test(str_contains($e->getMessage(), "canonical standalone stylesheet 'child' requires no Template header"), 'inverse child Template compiler refusal was unclear');
}
$inverseStagePayload = $linked->export();
$inverseStagePayload['tree']['options/core']['data'] = OptionState::document($standaloneChildRecords);
$inverseStageCompiled = CompiledRepository::create($inverseStagePayload);
$inverseStageFailure = null;
try {
    Code::stage($repo, $inverseStageCompiled, [
        'artifact_hash' => $inverseStageCompiled->artifact_hash(),
        'promotion_owner' => 'inverse-theme-template-stage-refusal',
    ]);
} catch (RuntimeException $e) {
    $inverseStageFailure = $e->getMessage();
}
assert_test($inverseStageFailure !== null, 'code-stage accepted a child Template header while canonical state called it standalone');
assert_test(str_contains($inverseStageFailure, "canonical standalone stylesheet 'child' requires no Template header"), 'inverse child Template stage refusal was unclear');
assert_test((scandir($target) ?: []) === ['.', '..'], 'inverse child Template stage refusal mutated target bytes');
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));

$descriptorWithoutRevision = $linkedDescriptor;
unset($descriptorWithoutRevision['code_revision']);
$wrongTemplateRevision = $descriptorWithoutRevision;
$wrongTemplateRevision['theme_templates']['child'] = 'other-parent';
assert_test(
    Code::revision_for($wrongTemplateRevision) !== $linkedDescriptor['code_revision'],
    'theme_templates must participate in the immutable code revision'
);
$legacyLinkedDescriptor = legacy_descriptor_test($linkedDescriptor);
Code::assert_descriptor($legacyLinkedDescriptor);
$legacyStagePayload = $stagePayload;
$legacyStagePayload['code'] = $legacyLinkedDescriptor;
$legacyStageCompiled = CompiledRepository::create($legacyStagePayload);
$legacyStageFailure = null;
try {
    Code::stage($repo, $legacyStageCompiled, [
        'artifact_hash' => $legacyStageCompiled->artifact_hash(),
        'promotion_owner' => 'legacy-child-template-stage-refusal',
    ]);
} catch (RuntimeException $e) {
    $legacyStageFailure = $e->getMessage();
}
assert_test($legacyStageFailure !== null, 'code-stage accepted a child theme from a frozen legacy descriptor without linkage');
assert_test(str_contains($legacyStageFailure, 'frozen legacy code descriptor has no theme_templates relation'), 'legacy child-theme refusal was unclear');
assert_test((scandir($target) ?: []) === ['.', '..'], 'legacy child-theme refusal mutated target bytes');

// Frozen artifact loading is itself an offline code/state bridge. Apply,
// Deploy, and canonical verification all consume this loader, so none may
// defer a malformed current relation or a legacy child ambiguity until stage.
$currentMismatchArtifact = $repo . '/.loader-current-mismatch.json';
$stageCompiled->write($currentMismatchArtifact);
try {
    RepositoryCompiler::read_artifact($currentMismatchArtifact, Policy::load($repo));
    fail_test('artifact loader accepted a current child Template mismatch');
} catch (RepositoryCompilationException $e) {
    assert_test(
        in_array('compiled_artifact_code_state_mismatch', array_column($e->diagnostics, 'code'), true),
        'current child mismatch loader refusal was not structured'
    );
}
$legacyChildArtifact = $repo . '/.loader-legacy-child.json';
$legacyStageCompiled->write($legacyChildArtifact);
try {
    RepositoryCompiler::read_artifact($legacyChildArtifact, Policy::load($repo));
    fail_test('artifact loader accepted a frozen legacy child descriptor without linkage');
} catch (RepositoryCompilationException $e) {
    assert_test(
        in_array('compiled_artifact_code_state_mismatch', array_column($e->diagnostics, 'code'), true),
        'legacy child loader refusal was not structured'
    );
    assert_test(str_contains($e->getMessage(), 'frozen legacy code descriptor has no theme_templates relation'), 'legacy child loader refusal was unclear');
}
$legacyStandalonePayload = $baseline->export();
$legacyStandalonePayload['code'] = $legacyDescriptor;
$legacyStandaloneCompiled = CompiledRepository::create($legacyStandalonePayload);
$legacyStandaloneArtifact = $repo . '/.loader-legacy-standalone.json';
$legacyStandaloneCompiled->write($legacyStandaloneArtifact);
$loadedLegacyStandalone = RepositoryCompiler::read_artifact($legacyStandaloneArtifact, Policy::load($repo));
assert_test(
    $loadedLegacyStandalone->code_revision() === $legacyDescriptor['code_revision'],
    'artifact loader rejected an exact legacy standalone-theme descriptor'
);
$currentWithExtra = $linkedDescriptor;
$currentWithExtra['unexpected'] = true;
try {
    Code::assert_descriptor($currentWithExtra);
    fail_test('current descriptor accepted an arbitrary extra key');
} catch (RuntimeException $e) {
    assert_test(str_contains($e->getMessage(), 'unsupported or malformed shape'), 'current extra-key refusal was unclear');
}
$malformedTemplates = $linkedDescriptor;
$malformedTemplates['theme_templates'] = ['parent' => null, 'example' => null, 'child' => 'parent'];
$descriptorFailure = null;
try {
    Code::assert_descriptor($malformedTemplates);
} catch (RuntimeException $e) {
    $descriptorFailure = $e->getMessage();
}
assert_test($descriptorFailure !== null, 'descriptor accepted unsorted theme_templates');
assert_test(str_contains($descriptorFailure, 'theme_templates must map every theme slug in deterministic order'), 'unsorted theme_templates refusal was unclear');
$malformedTemplates = $linkedDescriptor;
$malformedTemplates['theme_templates']['child'] = 'bad/parent';
$descriptorFailure = null;
try {
    Code::assert_descriptor($malformedTemplates);
} catch (RuntimeException $e) {
    $descriptorFailure = $e->getMessage();
}
assert_test($descriptorFailure !== null, 'descriptor accepted an unsafe child Template header value');
assert_test(str_contains($descriptorFailure, "theme_templates['child'] is malformed"), 'unsafe Template descriptor refusal was unclear');

// The bridge is also a compiler-time gate, not only a stage-time check.
$records['active_plugins'] = OptionState::present(['missing/missing.php'], 'yes');
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
try {
    $compile();
    fail_test('compile accepted an active plugin absent from the code descriptor');
} catch (RepositoryCompilationException $e) {
    assert_test(in_array('code_state_mismatch', array_column($e->diagnostics, 'code'), true), 'compile refusal must identify the code/state bridge');
}

// Code-only change: code descriptor/artifact moves, state revision does not.
$records = $baselineRecords;
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
remove_test($repo . '/code/wp-content/themes/parent');
remove_test($repo . '/code/wp-content/themes/child');
file_put_contents($repo . '/code/wp-content/plugins/example/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 2.0.0\n*/\n");
$codeChanged = $compile();
assert_test($codeChanged->revision_hash() === $baselineRevision, 'code-only change must not change state revision_hash');
assert_test($codeChanged->code_revision() !== $baselineCodeRevision, 'code-only change must change code_revision');
assert_test($codeChanged->artifact_hash() !== $baseline->artifact_hash(), 'artifact_hash must bind the separate code descriptor');

$expectedArtifact = new ReflectionMethod(Code::class, 'assert_expected_artifact');
$expectedArtifact->setAccessible(true);
$expectedArtifact->invoke(null, $baseline, ['artifact_hash' => $baseline->artifact_hash()]);
try {
    $expectedArtifact->invoke(null, $codeChanged, ['artifact_hash' => $baseline->artifact_hash()]);
    fail_test('code materializer accepted a replaced artifact at the same target path');
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    assert_test(
        str_contains($e->getMessage(), 'does not match the host-compiled artifact hash'),
        'artifact replacement refusal was unclear'
    );
}

echo "ok: code/state revisions stay independent and every materializer phase pins the outer hash\n";
PHP
