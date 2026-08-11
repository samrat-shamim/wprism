#!/usr/bin/env bash
# Offline source/plugin compatibility gates.  The first process exercises the
# pure helper and RepositoryCompiler's structured diagnostics; the second
# invokes Code::stage with a real lifecycle bridge and fake lease/ledger seams
# to prove an invalid source is rejected before the first target rename.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
$root = getenv('DUO_ROOT');
define('DUO_SPEC_VERSION', 2);
foreach ([
    'Uuid', 'Canon', 'OptionState', 'Db', 'Secrets', 'PersonalData',
    'CodeCompatibility', 'Code', 'UserMetaState', 'Policy', 'Ledger',
    'Snapshot', 'Deletion', 'RepositoryAuthorization', 'SidebarState',
    'CodeStateContract', 'RepositoryCompiler',
] as $file) {
    require_once "$root/agent/src/$file.php";
}

use Duo\Canon;
use Duo\Code;
use Duo\CodeCompatibility;
use Duo\OptionState;
use Duo\Policy;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;

function fail_compat(string $message): never { throw new RuntimeException("FAIL: $message"); }
function check_compat(bool $condition, string $message): void {
    if (!$condition) { fail_compat($message); }
}
function put_compat(string $path, string $bytes): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        fail_compat('cannot create ' . dirname($path));
    }
    if (file_put_contents($path, $bytes) === false) { fail_compat("cannot write $path"); }
}
function remove_compat(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') { remove_compat($path . '/' . $child); }
    }
    @rmdir($path);
}
function codes_compat(array $diagnostics): array { return array_column($diagnostics, 'code'); }
function has_code_compat(array $diagnostics, string $code): bool {
    return in_array($code, codes_compat($diagnostics), true);
}

$tmp = sys_get_temp_dir() . '/duo-code-compat-' . bin2hex(random_bytes(6));
$source = "$tmp/code/wp-content";
mkdir($source, 0777, true);
register_shutdown_function(static function () use ($tmp): void { remove_compat($tmp); });

$provider = "<?php\n/*\nPlugin Name: Compat Provider\n// Version: 1.5.0\nRequires PHP: 8.3\nRequires at least: 6.7\n*/\n";
$dependent = "<?php\n/*\nPlugin Name: Compat Dependent\nVersion: 1.5.0\nRequires Plugins: Provider\n*/\n";
$theme = "/*\nTheme Name: Compat Theme\nVersion: 3.5.0\nRequires PHP: 8.2\nRequires at least: 6.6\n*/\n";
put_compat("$source/plugins/provider/provider.php", $provider);
put_compat("$source/plugins/dependent/dependent.php", $dependent);
put_compat("$source/themes/compat-theme/style.css", $theme);

$descriptor = Code::descriptor_from_source($source);
$beforeDescriptor = Canon::encode($descriptor);
$adapters = [
    [
        'name' => 'compat-plugin', 'plugin' => 'provider/provider.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ],
    [
        'name' => 'compat-theme', 'theme' => 'compat-theme',
        'theme_version_range' => ['min' => '3.0.0', 'max' => '4.0.0'],
    ],
];

$clean = CodeCompatibility::diagnostics(
    $source,
    $descriptor,
    $adapters,
    ['provider/provider.php', 'dependent/dependent.php']
);
check_compat($clean === [], 'in-range provider/theme and provider closure must pass');
check_compat(Canon::encode($descriptor) === $beforeDescriptor, 'compatibility checks must not mutate the descriptor');

$runtimeClean = CodeCompatibility::target_report(
    $source,
    $descriptor,
    ['php' => '8.3.7', 'wordpress' => '6.8.2', 'source' => 'target-control-plane']
);
check_compat(
    ($runtimeClean['format'] ?? null) === 'duo-code-runtime/v1'
        && ($runtimeClean['compatible'] ?? false) === true
        && ($runtimeClean['diagnostics'] ?? null) === []
        && count((array) ($runtimeClean['requirements'] ?? [])) === 2,
    'satisfied plugin/theme runtime requirements must produce a canonical clean target report'
);

$runtimeBlocked = CodeCompatibility::target_report(
    $source,
    $descriptor,
    ['php' => '8.1.0', 'wordpress' => '6.5.5', 'source' => 'target-control-plane']
);
check_compat(
    has_code_compat($runtimeBlocked['diagnostics'] ?? [], 'code_source_requires_php_incompatible')
        && has_code_compat($runtimeBlocked['diagnostics'] ?? [], 'code_source_requires_wordpress_incompatible'),
    'plugin and theme requirements above the target runtime must fail generically'
);
$blockedPaths = array_values(array_unique(array_column($runtimeBlocked['diagnostics'] ?? [], 'path')));
sort($blockedPaths, SORT_STRING);
check_compat(
    $blockedPaths === ['plugins/provider/provider.php', 'themes/compat-theme/style.css'],
    'inactive/inventoried plugin and theme identities must remain explicit in runtime diagnostics'
);
foreach (($runtimeBlocked['diagnostics'] ?? []) as $diagnostic) {
    check_compat(
        isset(
            $diagnostic['required_version'],
            $diagnostic['target_version'],
            $diagnostic['target_php'],
            $diagnostic['target_wordpress'],
            $diagnostic['component_sha256']
        ),
        'runtime refusal rows must carry the requirement, both observed target values, and immutable component identity'
    );
}

$missingRuntime = CodeCompatibility::target_report(
    $source,
    $descriptor,
    ['php' => '', 'wordpress' => '', 'source' => 'target-control-plane']
);
check_compat(
    has_code_compat($missingRuntime['diagnostics'] ?? [], 'code_target_php_version_missing')
        && has_code_compat($missingRuntime['diagnostics'] ?? [], 'code_target_wordpress_version_missing'),
    'missing target PHP/WordPress evidence must fail closed'
);

put_compat(
    "$source/plugins/provider/provider.php",
    str_replace(
        ['Requires PHP: 8.3', 'Requires at least: 6.7'],
        ['Requires PHP: newest', 'Requires at least: current'],
        $provider
    )
);
$malformedDescriptor = Code::descriptor_from_source($source);
$malformedRuntime = CodeCompatibility::target_report(
    $source,
    $malformedDescriptor,
    ['php' => '8.3.7', 'wordpress' => '6.8.2', 'source' => 'target-control-plane']
);
check_compat(
    has_code_compat($malformedRuntime['diagnostics'] ?? [], 'code_source_requires_php_malformed')
        && has_code_compat($malformedRuntime['diagnostics'] ?? [], 'code_source_requires_wordpress_malformed'),
    'malformed runtime requirement headers must fail closed'
);
put_compat("$source/plugins/provider/provider.php", $provider);

put_compat("$source/plugins/provider/provider.php", str_replace('1.5.0', '2.0.0', $provider));
put_compat("$source/themes/compat-theme/style.css", str_replace('3.5.0', '4.0.0', $theme));
$outDescriptor = Code::descriptor_from_source($source);
$out = CodeCompatibility::diagnostics($source, $outDescriptor, $adapters, []);
check_compat(has_code_compat($out, 'code_source_outside_version_range'), 'out-of-range plugin source must be rejected');
check_compat(has_code_compat($out, 'code_source_theme_outside_version_range'), 'out-of-range theme source must be rejected');

put_compat("$source/plugins/provider/provider.php", $provider);
put_compat("$source/themes/compat-theme/style.css", $theme);
$order = CodeCompatibility::diagnostics(
    $source,
    $descriptor,
    $adapters,
    ['dependent/dependent.php', 'provider/provider.php']
);
check_compat(
    !has_code_compat($order, 'code_plugin_dependency_order')
        && !has_code_compat($order, 'code_plugin_dependency_missing')
        && !has_code_compat($order, 'code_plugin_dependency_inactive'),
    'uppercase dependency token must be ignored like WordPress core'
);

// The descriptor source currently carries an uppercase token; use a source
// copy with the valid lowercase token to prove provider closure is enforced
// while native/author active_plugins order remains acceptable.
put_compat(
    "$source/plugins/dependent/dependent.php",
    str_replace('Requires Plugins: Provider', 'Requires Plugins: provider', $dependent)
);
$lowercaseDescriptor = Code::descriptor_from_source($source);
$lowercaseOrder = CodeCompatibility::diagnostics(
    $source,
    $lowercaseDescriptor,
    $adapters,
    ['dependent/dependent.php', 'provider/provider.php']
);
check_compat(
    !has_code_compat($lowercaseOrder, 'code_plugin_dependency_order')
        && !has_code_compat($lowercaseOrder, 'code_plugin_dependency_missing')
        && !has_code_compat($lowercaseOrder, 'code_plugin_dependency_inactive'),
    'lowercase dependency must accept native/alphabetical active_plugins order'
);
put_compat("$source/plugins/dependent/dependent.php", $dependent);

$invalidSource = "$tmp/invalid/code/wp-content";
mkdir("$invalidSource/plugins/dependent", 0777, true);
put_compat(
    "$invalidSource/plugins/dependent/dependent.php",
    "<?php\n/*\nPlugin Name: Invalid Dependency\nRequires Plugins: not/a-slug\n*/\n"
);
$invalidDescriptor = Code::descriptor_from_source($invalidSource);
$invalid = CodeCompatibility::diagnostics(
    $invalidSource,
    $invalidDescriptor,
    [],
    ['dependent/dependent.php']
);
check_compat(
    !has_code_compat($invalid, 'code_plugin_dependency_missing')
        && !has_code_compat($invalid, 'code_plugin_dependency_inactive'),
    'invalid slash dependency token must be ignored like WordPress core'
);

put_compat(
    "$source/plugins/dependent/dependent.php",
    str_replace('Requires Plugins: Provider', 'Requires Plugins: provider', $dependent)
);
$inactive = CodeCompatibility::diagnostics(
    $source,
    $lowercaseDescriptor,
    $adapters,
    ['dependent/dependent.php']
);
check_compat(has_code_compat($inactive, 'code_plugin_dependency_inactive'), 'omitted provider must be rejected');
put_compat("$source/plugins/dependent/dependent.php", $dependent);

$missingSource = "$tmp/missing/code/wp-content";
mkdir("$missingSource/plugins/dependent", 0777, true);
put_compat(
    "$missingSource/plugins/dependent/dependent.php",
    str_replace('Requires Plugins: Provider', 'Requires Plugins: provider', $dependent)
);
$missingDescriptor = Code::descriptor_from_source($missingSource);
$missing = CodeCompatibility::diagnostics(
    $missingSource,
    $missingDescriptor,
    [],
    ['dependent/dependent.php']
);
check_compat(has_code_compat($missing, 'code_plugin_dependency_missing'), 'missing provider must be rejected');

$duplicateSource = "$tmp/duplicate/code/wp-content";
mkdir("$duplicateSource/plugins/provider", 0777, true);
put_compat("$duplicateSource/plugins/provider/provider.php", $provider);
put_compat("$duplicateSource/plugins/provider.php", "<?php\n/*\nPlugin Name: Compat Single Provider\nVersion: 1.0.0\n*/\n");
$duplicateDescriptor = Code::descriptor_from_source($duplicateSource);
$duplicate = CodeCompatibility::diagnostics($duplicateSource, $duplicateDescriptor, [], []);
check_compat(has_code_compat($duplicate, 'code_plugin_dependency_duplicate_slug'), 'duplicate provider slug must fail closed');

$cycleSource = "$tmp/cycle/code/wp-content";
mkdir("$cycleSource/plugins/a", 0777, true);
mkdir("$cycleSource/plugins/b", 0777, true);
put_compat("$cycleSource/plugins/a/a.php", "<?php\n/*\nPlugin Name: Cycle A\nVersion: 1.0.0\nRequires Plugins: b\n*/\n");
put_compat("$cycleSource/plugins/b/b.php", "<?php\n/*\nPlugin Name: Cycle B\nVersion: 1.0.0\nRequires Plugins: a\n*/\n");
$cycleDescriptor = Code::descriptor_from_source($cycleSource);
$cycle = CodeCompatibility::diagnostics($cycleSource, $cycleDescriptor, [], []);
check_compat(has_code_compat($cycle, 'code_plugin_dependency_cycle'), 'dependency cycle must fail closed');

$numericSlugs = CodeCompatibility::dependency_slugs('10,2,10');
check_compat(
    $numericSlugs === ['2', '10']
        && array_map('gettype', $numericSlugs) === ['string', 'string'],
    'numeric dependency slugs must retain strings and core sort semantics'
);
$numericCycleSource = "$tmp/numeric-cycle/code/wp-content";
mkdir("$numericCycleSource/plugins/a", 0777, true);
put_compat(
    "$numericCycleSource/plugins/10.php",
    "<?php\n/*\nPlugin Name: Numeric Provider\nRequires Plugins: a\n*/\n"
);
put_compat(
    "$numericCycleSource/plugins/a/a.php",
    "<?php\n/*\nPlugin Name: Numeric Dependent\nRequires Plugins: 10\n*/\n"
);
$numericCycleDescriptor = Code::descriptor_from_source($numericCycleSource);
$numericCycle = CodeCompatibility::diagnostics($numericCycleSource, $numericCycleDescriptor, [], []);
check_compat(has_code_compat($numericCycle, 'code_plugin_dependency_cycle'), 'numeric dependency cycle must fail closed');

$mappingSource = "$tmp/mapping/code/wp-content";
mkdir("$mappingSource/plugins/dependent", 0777, true);
put_compat(
    "$mappingSource/plugins/foo.php-bar.php",
    "<?php\n/*\nPlugin Name: Strange Provider\n*/\n"
);
put_compat(
    "$mappingSource/plugins/dependent/dependent.php",
    "<?php\n/*\nPlugin Name: Strange Dependent\nRequires Plugins: foo-bar\n*/\n"
);
$mappingDescriptor = Code::descriptor_from_source($mappingSource);
check_compat(CodeCompatibility::plugin_slug('foo.php-bar.php') === 'foo-bar', 'plugin slug must remove every .php occurrence');
$mappingClean = CodeCompatibility::diagnostics(
    $mappingSource,
    $mappingDescriptor,
    [],
    ['foo.php-bar.php', 'dependent/dependent.php']
);
check_compat($mappingClean === [], 'strange provider slug must satisfy dependency closure');
$mappingReversed = CodeCompatibility::diagnostics(
    $mappingSource,
    $mappingDescriptor,
    [],
    ['dependent/dependent.php', 'foo.php-bar.php']
);
check_compat($mappingReversed === [], 'strange provider dependency closure must ignore active_plugins order');

// Exercise the actual offline compiler gate, including its stable structured
// diagnostic payload, rather than only the pure helper.
$manifestDir = "$tmp/manifests";
mkdir($manifestDir, 0777, true);
copy($root . '/manifests/core.json', "$manifestDir/core.json");
put_compat("$manifestDir/compat-fixture.json", Canon::encode([
    'name' => 'compat-fixture',
    'spec_version' => 2,
    'plugin' => 'provider/provider.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'theme' => 'compat-theme',
    'theme_version_range' => ['min' => '3.0.0', 'max' => '4.0.0'],
    'options' => (object) [],
    'post_types' => [],
    'taxonomies' => [],
]));
putenv("DUO_MANIFESTS_DIR=$manifestDir");
$repo = "$tmp/compiler-repo";
mkdir("$repo/state/options", 0777, true);
mkdir("$repo/media", 0777, true);
put_compat("$repo/site.duo.json", Canon::encode([
    'manifests' => ['core', 'compat-fixture'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => [], 'taxonomies' => [],
    ],
    'spec_version' => 2,
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
]));
foreach (['provider/provider.php' => $provider, 'dependent/dependent.php' => str_replace('Provider', 'provider', $dependent)] as $relative => $bytes) {
    put_compat("$repo/code/wp-content/plugins/$relative", $bytes);
}
put_compat("$repo/code/wp-content/themes/compat-theme/style.css", $theme);
$policy = Policy::load($repo);
$records = [];
foreach (array_keys($policy->authored_options()) as $name) { $records[$name] = OptionState::absent(); }
foreach (array_keys($policy->sub_keyed_options()) as $name) { $records[$name] = OptionState::absent(); }
foreach (['active_plugins', 'template', 'stylesheet'] as $name) { $records[$name] = OptionState::absent(); }
$records['active_plugins'] = OptionState::present(['provider/provider.php', 'dependent/dependent.php'], 'yes');
$records['template'] = OptionState::present('compat-theme', 'yes');
$records['stylesheet'] = OptionState::present('compat-theme', 'yes');
put_compat("$repo/state/options/core.json", Canon::encode(OptionState::document($records)));

$compiled = RepositoryCompiler::compile($repo, $policy);
check_compat($compiled->code_descriptor() !== null, 'valid source compatibility fixture must compile');
$runtimeRows = Code::target_compatibility_rows(
    $repo,
    $compiled,
    ['php' => '8.2.0', 'wordpress' => '6.6.0', 'source' => 'target-control-plane']
);
check_compat(
    count($runtimeRows) >= 2
        && ($runtimeRows[0]['non_forceable'] ?? false) === true
        && ($runtimeRows[0]['code_revision'] ?? '') === $compiled->code_revision(),
    'semantic plan rows must bind non-forceable runtime findings to the immutable code revision'
);

put_compat(
    "$repo/code/wp-content/plugins/provider/provider.php",
    str_replace('Requires PHP: 8.3', 'Requires PHP: newest', $provider)
);
$policy = Policy::load($repo);
try {
    RepositoryCompiler::compile($repo, $policy);
    fail_compat('compiler accepted a malformed Requires PHP header');
} catch (RepositoryCompilationException $e) {
    $diagnostics = $e->payload()['diagnostics'] ?? [];
    check_compat(
        has_code_compat($diagnostics, 'code_source_requires_php_malformed'),
        'compiler must expose malformed runtime headers before target contact'
    );
}
put_compat("$repo/code/wp-content/plugins/provider/provider.php", $provider);

put_compat("$repo/code/wp-content/plugins/provider/provider.php", str_replace('1.5.0', '2.0.0', $provider));
$policy = Policy::load($repo);
try {
    RepositoryCompiler::compile($repo, $policy);
    fail_compat('compiler accepted an out-of-range vendored plugin');
} catch (RepositoryCompilationException $e) {
    $diagnostics = $e->payload()['diagnostics'] ?? [];
    check_compat(has_code_compat($diagnostics, 'code_source_outside_version_range'), 'compiler must expose structured source range diagnostic');
}
try {
    RepositoryCompiler::compile_for_diff($repo, $policy);
    fail_compat('comparison compiler accepted an out-of-range vendored plugin');
} catch (RepositoryCompilationException $e) {
    $diagnostics = $e->payload()['diagnostics'] ?? [];
    check_compat(has_code_compat($diagnostics, 'code_source_outside_version_range'), 'comparison compiler must retain source range validation');
}

echo "ok: source version/theme ranges and dependency closure diagnostics are deterministic and descriptor-neutral\n";
echo "ok: RepositoryCompiler and compile_for_diff reject out-of-range vendored source before target contact\n";
PHP

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
namespace Duo;

final class CompiledRepository {
    public function __construct(
        private array $descriptor,
        private string $artifact,
        private array $tree,
        private array $adapters
    ) {}
    public function code_descriptor(): ?array { return $this->descriptor; }
    public function artifact_hash(): string { return $this->artifact; }
    public function tree(): array { return $this->tree; }
    public function resolved_adapters(): array { return $this->adapters; }
}

final class Ledger {
    /** @var array<string,string> */
    public static array $rows = [];
    public static function ensure(): void {}
    public static function kv_get(string $key): ?string { return self::$rows[$key] ?? null; }
    public static function kv_set(string $key, string $value): void { self::$rows[$key] = $value; }
    public static function kv_delete(string $key): void { unset(self::$rows[$key]); }
}

final class Db {
    public static function start(string $context): void {}
    public static function commit(string $context): void {}
    public static function rollback(string $context): void {}
}

final class PromotionLock {
    public static function acquire(string $owner, string $artifact, string $phase, ?int $ttl, bool $continuation): array {
        return ['owner' => $owner, 'artifact_hash' => $artifact, 'phase' => $phase];
    }
    public static function assert_no_lifecycle_attempt(string $owner, string $artifact, string $context): void {}
    public static function heartbeat(string $owner, string $artifact, string $phase): void {}
    public static function release(string $owner, string $artifact): void {}
}

$root = getenv('DUO_ROOT');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/duo-code-compat-stage-target-' . bin2hex(random_bytes(6)));
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/OptionState.php";
require_once "$root/agent/src/CodeCompatibility.php";
require_once "$root/agent/src/CodeStateContract.php";
require_once "$root/agent/src/Code.php";

function fail_stage_compat(string $message): never { throw new \RuntimeException("FAIL: $message"); }
function put_stage_compat(string $path, string $bytes): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        fail_stage_compat('cannot create ' . dirname($path));
    }
    file_put_contents($path, $bytes);
}
function remove_stage_compat(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') { remove_stage_compat($path . '/' . $child); }
    }
    @rmdir($path);
}

$repo = sys_get_temp_dir() . '/duo-code-compat-stage-repo-' . bin2hex(random_bytes(6));
$source = "$repo/code/wp-content";
mkdir($source . '/plugins/provider', 0777, true);
mkdir($source . '/plugins/dependent', 0777, true);
$themeDir = $source . '/themes/compat-theme';
mkdir($themeDir, 0777, true);
$provider = "<?php\n/*\nPlugin Name: Stage Provider\nVersion: 2.0.0\nRequires PHP: 99.0\nRequires at least: 6.0\n*/\n";
$dependent = "<?php\n/*\nPlugin Name: Stage Dependent\nVersion: 1.0.0\nRequires Plugins: provider\n*/\n";
$theme = "/*\nTheme Name: Stage Compat Theme\nVersion: 1.0.0\n*/\n";
$GLOBALS['wp_version'] = '6.8.2';
put_stage_compat("$source/plugins/provider/provider.php", $provider);
put_stage_compat("$source/plugins/dependent/dependent.php", $dependent);
put_stage_compat("$themeDir/style.css", $theme);
mkdir(WP_CONTENT_DIR, 0777, true);
put_stage_compat(WP_CONTENT_DIR . '/plugins/provider/provider.php', "old-provider\n");
put_stage_compat(WP_CONTENT_DIR . '/plugins/dependent/dependent.php', "old-dependent\n");
$beforeProvider = file_get_contents(WP_CONTENT_DIR . '/plugins/provider/provider.php');
$beforeDependent = file_get_contents(WP_CONTENT_DIR . '/plugins/dependent/dependent.php');

$descriptor = Code::descriptor_from_source($source);
$records = [
    'active_plugins' => OptionState::present(['provider/provider.php', 'dependent/dependent.php'], 'yes'),
    'template' => OptionState::present('compat-theme', 'yes'),
    'stylesheet' => OptionState::present('compat-theme', 'yes'),
];
$tree = ['options/core' => ['data' => OptionState::document($records)]];
$compiled = new CompiledRepository(
    $descriptor,
    str_repeat('a', 64),
    $tree,
    [[
        'name' => 'stage-fixture', 'plugin' => 'provider/provider.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ]]
);

$failure = null;
try {
    Code::stage($repo, $compiled, ['artifact_hash' => str_repeat('a', 64), 'promotion_owner' => 'compat-stage']);
} catch (\Throwable $e) {
    $failure = $e->getMessage();
}
if ($failure === null || !str_contains($failure, 'code_source_outside_version_range')) {
    fail_stage_compat('stage did not refuse the out-of-range source before writing: ' . (string) $failure);
}
if (file_get_contents(WP_CONTENT_DIR . '/plugins/provider/provider.php') !== $beforeProvider
    || file_get_contents(WP_CONTENT_DIR . '/plugins/dependent/dependent.php') !== $beforeDependent) {
    fail_stage_compat('out-of-range stage changed target bytes');
}
if (Ledger::$rows !== []) {
    fail_stage_compat('out-of-range stage left durable staged markers');
}

// A valid-version source whose canonical active_plugins order is native/
// alphabetical must pass the same pre-write boundary. Lifecycle activation
// ordering is Deploy's responsibility, not a source canonical-order gate.
$stageProvider = str_replace('2.0.0', '1.5.0', $provider);
$stageDependent = str_replace('Requires Plugins: Provider', 'Requires Plugins: provider', $dependent);
put_stage_compat("$source/plugins/provider/provider.php", $stageProvider);
put_stage_compat(
    "$source/plugins/dependent/dependent.php",
    $stageDependent
);
$descriptor = Code::descriptor_from_source($source);
$compiled = new CompiledRepository(
    $descriptor,
    str_repeat('b', 64),
    ['options/core' => ['data' => OptionState::document([
        'active_plugins' => OptionState::present(['dependent/dependent.php', 'provider/provider.php'], 'yes'),
        'template' => OptionState::present('compat-theme', 'yes'),
        'stylesheet' => OptionState::present('compat-theme', 'yes'),
    ])]],
    [[
        'name' => 'stage-fixture', 'plugin' => 'provider/provider.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ]]
);
$runtimeFailure = null;
try {
    Code::stage($repo, $compiled, ['artifact_hash' => str_repeat('b', 64), 'promotion_owner' => 'compat-stage-runtime']);
} catch (\Throwable $e) {
    $runtimeFailure = $e->getMessage();
}
if ($runtimeFailure === null || !str_contains($runtimeFailure, 'code_source_requires_php_incompatible')) {
    fail_stage_compat('stage did not repeat target PHP compatibility before writing: ' . (string) $runtimeFailure);
}
if (file_get_contents(WP_CONTENT_DIR . '/plugins/provider/provider.php') !== $beforeProvider
    || file_get_contents(WP_CONTENT_DIR . '/plugins/dependent/dependent.php') !== $beforeDependent) {
    fail_stage_compat('runtime-incompatible stage changed target bytes');
}
if (Ledger::$rows !== []) {
    fail_stage_compat('runtime-incompatible stage left durable staged markers');
}

$stageProvider = str_replace('Requires PHP: 99.0', 'Requires PHP: 8.0', $stageProvider);
put_stage_compat("$source/plugins/provider/provider.php", $stageProvider);
$descriptor = Code::descriptor_from_source($source);
$compiled = new CompiledRepository(
    $descriptor,
    str_repeat('c', 64),
    ['options/core' => ['data' => OptionState::document([
        'active_plugins' => OptionState::present(['dependent/dependent.php', 'provider/provider.php'], 'yes'),
        'template' => OptionState::present('compat-theme', 'yes'),
        'stylesheet' => OptionState::present('compat-theme', 'yes'),
    ])]],
    [[
        'name' => 'stage-fixture', 'plugin' => 'provider/provider.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ]]
);
$stageResult = null;
try {
    $stageResult = Code::stage($repo, $compiled, ['artifact_hash' => str_repeat('c', 64), 'promotion_owner' => 'compat-stage-order']);
} catch (\Throwable $e) {
    fail_stage_compat('stage rejected native/alphabetical dependency order: ' . $e->getMessage());
}
if (!is_array($stageResult) || ($stageResult['staged'] ?? false) !== true) {
    fail_stage_compat('stage did not report success for native/alphabetical dependency order');
}
try {
    Code::assert_verified_staged($compiled);
} catch (\Throwable $e) {
    fail_stage_compat('successful native/alphabetical stage did not publish verifiable stage markers: ' . $e->getMessage());
}
if (file_get_contents(WP_CONTENT_DIR . '/plugins/provider/provider.php') !== $stageProvider
    || file_get_contents(WP_CONTENT_DIR . '/plugins/dependent/dependent.php') !== $stageDependent) {
    fail_stage_compat('accepted native/alphabetical dependency stage did not materialize expected target bytes');
}

remove_stage_compat($repo);
remove_stage_compat(WP_CONTENT_DIR);
echo "ok: Code::stage repeats source compatibility under lease and writes no target bytes on refusal\n";
PHP
