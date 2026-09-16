<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'ShellProbe.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Promotion/LifecyclePlanner.php";
require_once "$root/agent/src/Apply/ApplyPreparationCoordinator.php";
require_once "$capsule/fixtures/native-dependencies/evidence.php";
wprism_test_define_agent_versions();
$policy = WPrism\Policy::load(null, ['core', 'qi-blocks'], adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
$plugin = QiNativeDependencyEvidence::PLUGIN;
$wrong = QiNativeDependencyEvidence::WRONG_PLUGIN;
wprism_check_same([$plugin => ['min' => '1.5.2', 'max' => '1.5.3', 'manifest' => 'qi-blocks']], $policy->version_ranges(),
    'actual capsule policy pins the exact native basename and inclusive/exclusive interval');
$store = WPrismTest\WpStore::instance();
$scratch = $store->ensureUploadDir();
mkdir(dirname(WP_PLUGIN_DIR . '/' . $plugin), 0700, true);
register_shutdown_function(static fn() => WPrismTest\WpStore::reset());
$wpdb = WPrismTest\FakeWpdb::install()->setColumns('options', [
    'option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)',
]);
$compiled = WPrism\CompiledRepository::create(['tree' => []]);
$unexpected = static fn() => throw new RuntimeException('Qi dependency refusal must precede Apply mutation services');
$services = new WPrism\ApplyServices($policy, $compiled, new WPrism\ApplyServiceCallbacks(
    taxonomyOwnership: $unexpected, renewPromotionLock: $unexpected,
    renewRegenerationLease: $unexpected, renewProviderLease: $unexpected,
    lockDeleteGuards: $unexpected, deletionDatabaseProfile: $unexpected,
    recheckDeleteGuard: $unexpected, selectionDeclaresChannelFor: $unexpected,
    selectionDeclaresEntityBatchFor: $unexpected, selectionTriggersProviderActionFor: $unexpected,
    pinnedProviderActionOwns: $unexpected, upsertMeta: $unexpected
), '/fixture/repo');
$header = static fn(string $version): string => "<?php\n/*\nPlugin Name: Qi native boundary fixture\nVersion: $version\n*/\n";
foreach (['inactive' => ['1.5.2', []], 'missing' => [null, []], 'prior' => ['1.5.1', [$plugin]],
    'maximum' => ['1.5.3', [$plugin]], 'unreadable' => ['', [$plugin]], 'wrong-basename' => [null, [$wrong]]] as $case => [$version, $active]) {
    foreach ([$plugin, $wrong] as $file) if (is_file(WP_PLUGIN_DIR . '/' . $file)) unlink(WP_PLUGIN_DIR . '/' . $file);
    if ($version !== null) file_put_contents(WP_PLUGIN_DIR . '/' . $plugin, $header($version));
    if ($case === 'wrong-basename') file_put_contents(WP_PLUGIN_DIR . '/' . $wrong, $header('1.5.2'));
    $wpdb->seedTable('options', [
        ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => serialize($active), 'autoload' => 'yes'],
        ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture', 'autoload' => 'yes'],
        ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture', 'autoload' => 'yes'],
    ]);
    $plan = array_fill_keys(['collision', 'conflict', 'delete_conflict', 'drift', 'create', 'update', 'missing_user'], []);
    $plan['code_mismatch'] = WPrism\LifecyclePlanner::code_mismatch($policy, ['active_plugins' => [$plugin]]);
    $coordinator = new WPrism\ApplyPreparationCoordinator('/fixture/repo', $policy, $services,
        new WPrism\RebuildSelection($policy), new WPrism\ScopedApplyWorkflow(), $unexpected, $unexpected);
    $request = new WPrism\ApplyPreparationRequest([], $compiled, $plan, [], false, false, false, false, 'qi-dependency-probe', str_repeat('e', 64));
    $warnings = [];
    $overrides = [];
    $failure = null;
    try { $coordinator->prepare($request, $warnings, $overrides); } catch (Throwable $actual) { $failure = $actual; }
    wprism_check($failure !== null, 'actual Apply preparation refuses independent native boundary: ' . $case);
    wprism_check_same(QiNativeDependencyEvidence::profile($case)['nodes'][0]['class'], get_class($failure), 'exact refusal type: ' . $case);
    wprism_check_same(QiNativeDependencyEvidence::profile($case)['nodes'][0]['message'], $failure->getMessage(),
        'predeclared live cause agrees with the actual product preparation gate: ' . $case);
    wprism_check_same([], $warnings, 'refused boundary cannot manufacture compatibility override warnings');
}
foreach ([$plugin, $wrong] as $file) if (is_file(WP_PLUGIN_DIR . '/' . $file)) unlink(WP_PLUGIN_DIR . '/' . $file);
file_put_contents(WP_PLUGIN_DIR . '/' . $plugin, $header('1.5.2'));
$wpdb->seedTable('options', [
    ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => serialize([$plugin]), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture', 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture', 'autoload' => 'yes'],
]);
wprism_check_same([], WPrism\LifecyclePlanner::code_mismatch($policy, ['active_plugins' => [$plugin]]),
    'exact restoration settles the same generic lifecycle observation after all faults');

// The live runner must take its complete postimage before checking a refusal.
// A generic JSON failure with exit 1 cannot impersonate a private cause record.
$runner = file_get_contents($capsule . '/tests/live/regress_native_dependencies.sh');
$start = strpos($runner, 'capture_dependency() {');
$end = strpos($runner, 'capture deactivate', $start);
if ($start === false || $end === false) throw new RuntimeException('Qi dependency runner boundary unavailable');
$script = <<<'SH'
set -euo pipefail
PACKAGE_ROOT="$1" PAIR=dependencyprobe
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$2/sandbox/tests/lib/private_command_capture.sh"
sink=$(umask 077; mktemp -d)
trap 'rm -rf -- "$sink"' EXIT
fixture_exit="$3"
candidate() { printf '{"fixture":true}\n'; return "$fixture_exit"; }
dependency_native() { printf '{"fixture":true}\n'; }
SH;
$script .= "\n" . substr($runner, $start, $end - $start) . "\ndependency_snapshot() { printf 'snapshot:%s\\n' \"\$1\"; }\ndependency_refusal probe\n";
foreach ([0, 1, 2] as $exit) {
    [$status, $out] = WPrismTest\ShellProbe::run($script, [$capsule, $root, (string) $exit], $root);
    wprism_check($status !== 0, 'wrong exit or absent private cause cannot pass native evidence: ' . $exit);
    wprism_check(str_contains($out, "snapshot:probe-before\nsnapshot:probe-after\n"), 'both native images survive an unexpected command outcome: ' . $exit);
}

// Complete independent test images exercise the live admission, including
// whole-table coverage and each filesystem root; equality alone is insufficient.
$tables = ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_wprism_map', 'wp_wprism_state'];
sort($tables, SORT_STRING);
$sql = "-- MariaDB dump fixture\n";
foreach ($tables as $table) $sql .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n  `id` bigint NOT NULL\n);\n-- Dumping data for table `$table`\nINSERT INTO `$table` (`id`) VALUES (1);\n";
$sql .= "-- Dump completed\n";
$repo = [];
foreach (['state' => ['state', 'native.json'], 'policy' => ['site.wprism.json', null], 'media' => ['media', 'native.png']] as $name => [$path, $file]) {
    if ($file !== null) { mkdir("$scratch/$path", 0700);
    file_put_contents("$scratch/$path/$file", $name . ' native bytes'); } else file_put_contents("$scratch/$path", 'native policy bytes');
    $repo[$name] = WPrismTest\FilesystemTreeEvidence::capture($scratch, $path, WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
}
$files = [];
foreach (['plugins', 'uploads'] as $name) {
    mkdir("$scratch/$name", 0700);
    file_put_contents("$scratch/$name/native.bin", $name . ' bytes');
    $files[$name] = WPrism\FilesystemTreeSnapshot::observe($scratch, "$scratch/$name", $name, 'Qi native evidence pin', 'fixture');
}
$image = ['tables' => implode("\n", array_map(static fn(string $table): string => "$table\tBASE TABLE", $tables)) . "\n", 'database' => $sql,
    'repository' => $repo, 'files' => $files, 'code' => QiNativeDependencyEvidence::expected('inactive')];
QiNativeDependencyEvidence::images($image, $image);
wprism_check(true, 'complete byte-identical images admit the independently inventoried native premises');
foreach (['database', 'repository', 'files', 'code'] as $field) {
    $changed = $image;
    $changed[$field] = $field === 'database' ? $sql . "extra\n" : [];
    wprism_check_throws(static fn() => QiNativeDependencyEvidence::images($image, $changed), Throwable::class, 'complete native refusal image rejects a changed ' . $field);
}
$empty = $image;
$empty['database'] = "-- MariaDB dump fixture\n-- Dump completed\n";
wprism_check_throws(static fn() => QiNativeDependencyEvidence::images($empty, $empty), Throwable::class, 'stable truncated SQL cannot prove no mutation');
foreach (['state', 'policy', 'media'] as $name) {
    $changed = $image;
    $changed['repository'][$name]['files'][0]['contents_base64'] = base64_encode('changed bytes');
    wprism_check_throws(static fn() => QiNativeDependencyEvidence::images($image, $changed), Throwable::class, 'retained repository bytes cannot diverge from their recorded digest: ' . $name);
}
foreach (['plugins', 'uploads'] as $name) {
    $changed = $image;
    $changed['files'][$name]['files'][0]['sha256'] = str_repeat('f', 64);
    wprism_check_throws(static fn() => QiNativeDependencyEvidence::images($image, $changed), Throwable::class, 'same-size native file rewrite invalidates refusal evidence: ' . $name);
    $changed = $image;
    $changed['files'][$name]['files'][0]['sha256'] = 'invalid';
    wprism_check_throws(static fn() => QiNativeDependencyEvidence::images($changed, $changed), Throwable::class,
        'stable malformed native hash inventory cannot prove no mutation: ' . $name);
}
wprism_check_throws(static fn() => QiNativeDependencyEvidence::profile('unknown'), RuntimeException::class, 'no generic refusal cause fallback');
foreach (['regress_native_apply.sh', 'regress_native_dependencies.sh'] as $name) {
    $statements = array_values(array_filter(array_map('trim', explode("\n", file_get_contents($capsule . '/tests/live/' . $name))),
        static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    wprism_check_same(['set -euo pipefail', 'PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"',
        'export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"'], array_slice($statements, 0, 3),
        'live caller declares canonical artifact authority before branches or sources: ' . $name);
}
$prefixProbe = <<<'SH'
set -euo pipefail
PACKAGE_ROOT="$1" WPRISM_ARTIFACT_PACKAGE="$2"
. "$3/fixtures/native-apply/setup.sh"
SH;
foreach ([[$capsule, 'qi-blocks', 'QI_APPLY_PAIR'], [$capsule, 'core', 'canonical caller capsule authority'],
    [$root, 'qi-blocks', 'canonical caller capsule authority']] as [$selectedRoot, $selectedScope, $message]) {
    [$status, $out, $err] = WPrismTest\ShellProbe::run($prefixProbe, [$selectedRoot, $selectedScope, $capsule], $root);
    wprism_check($status !== 0 && $out === '' && str_contains($err, $message),
        'actual sourced baseline validates caller ownership before requiring or mutating a pair: ' . $selectedScope . ' ' . $message);
}
$capture = ['counts' => ['post' => 4, 'term' => 1, 'menu' => 0, 'sidebar' => 1, 'options' => 1, 'deletion' => 0],
    'media' => 1, 'notes' => [], 'warnings' => [], 'initial_code_baseline' => null];
QiNativeApplyEvidence::capture_result($capture);
wprism_check(true, 'final native restoration Capture uses the complete baseline admission');
foreach (['media' => 0, 'notes' => ['unadmitted note'], 'warnings' => ['unadmitted warning'], 'initial_code_baseline' => 'unproved'] as $field => $value) {
    $changed = $capture;
    $changed[$field] = $value;
    wprism_check_throws(static fn() => QiNativeApplyEvidence::capture_result($changed), Throwable::class,
        'restored Capture cannot hide changed media or diagnostics: ' . $field);
}
wprism_check_summary('Qi native dependency evidence');
