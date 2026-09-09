<?php
/** Generic manifest-owned plugin incompatibility grammar and policy gate. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/agent_version.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';

use WPrism\AdapterContractGrammar;
use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\Policy;

$rank = Canon::decode(Canon::read_file(
    $root . '/adapter-packages/rank-math/package/manifest.json'
));
$yoast = Canon::decode(Canon::read_file(
    $root . '/adapter-packages/yoast/package/manifest.json'
));

wprism_check(
    in_array('plugin-incompatibility/v1', $rank['engine_features'] ?? [], true),
    'Rank Math opts into the engine-owned plugin incompatibility feature'
);
wprism_check_same(
    ['wordpress-seo/wp-seo.php'],
    $rank['incompatible_plugins'] ?? null,
    'Rank Math names the competing Yoast plugin by exact basename'
);
$grammar = AdapterContractGrammar::implemented_feature_rows();
wprism_check_same(
    [
        'incompatible_plugins' => [
            'arm' => 'non_surface',
            'grammar' => [
                'shape' => 'a non-empty, sorted, duplicate-free LIST of exact plugin basenames',
                'item' => "'<directory>/<main-file>.php' or '<main-file>.php'",
                'refines' => "the declaring plugin adapter's admissible co-installation boundary",
                'validated_by' => 'WPrism\\AdapterContractGrammar::assert_plugin_incompatibilities()',
            ],
        ],
    ],
    $grammar['plugin-incompatibility/v1']['sections'] ?? null,
    'the emitted manifest grammar publishes the new section and its non-surface certificate arm'
);

$withoutFeature = $rank;
$withoutFeature['engine_features'] = array_values(array_filter(
    $withoutFeature['engine_features'],
    static fn(string $feature): bool => $feature !== 'plugin-incompatibility/v1'
));
wprism_check_throws(
    static fn() => AdapterContractGrammar::validate_adapter_contract($withoutFeature),
    RuntimeException::class,
    'the section cannot bypass the engine feature channel',
    "top-level key 'incompatible_plugins'"
);

$unknownFeature = $rank;
$unknownFeature['engine_features'] = array_values(array_map(
    static fn(string $feature): string => $feature === 'plugin-incompatibility/v1'
        ? 'plugin-incompatibility/v2'
        : $feature,
    $unknownFeature['engine_features']
));
sort($unknownFeature['engine_features'], SORT_STRING);
wprism_check_throws(
    static fn() => AdapterContractGrammar::validate_adapter_contract($unknownFeature),
    RuntimeException::class,
    'an engine cannot silently reinterpret a future incompatibility grammar',
    "does not implement it"
);

foreach ([
    'empty' => [],
    'object' => ['plugin' => 'wordpress-seo/wp-seo.php'],
    'duplicate' => ['wordpress-seo/wp-seo.php', 'wordpress-seo/wp-seo.php'],
    'unsorted' => ['wordpress-seo/wp-seo.php', 'akismet/akismet.php'],
    'non-string' => [42],
] as $case => $value) {
    $invalid = $rank;
    $invalid['incompatible_plugins'] = $value;
    wprism_check_throws(
        static fn() => AdapterContractGrammar::validate_adapter_contract($invalid),
        RuntimeException::class,
        "the $case incompatibility declaration refuses at manifest load",
        'must be a non-empty, sorted, duplicate-free list of exact plugin basenames'
    );
}

$invalidBasename = $rank;
$invalidBasename['incompatible_plugins'] = ['../wordpress-seo/wp-seo.php'];
wprism_check_throws(
    static fn() => AdapterContractGrammar::validate_adapter_contract($invalidBasename),
    RuntimeException::class,
    'an incompatibility cannot escape the plugin-basename namespace',
    "a plugin basename is '<directory>/<file>.php'"
);
$selfConflict = $rank;
$selfConflict['incompatible_plugins'] = ['seo-by-rank-math/rank-math.php'];
wprism_check_throws(
    static fn() => AdapterContractGrammar::validate_adapter_contract($selfConflict),
    RuntimeException::class,
    'an adapter cannot declare its own plugin incompatible with itself',
    "declares its own plugin 'seo-by-rank-math/rank-math.php' incompatible with itself"
);
$ownerless = $rank;
unset($ownerless['plugin'], $ownerless['version_range']);
wprism_check_throws(
    static fn() => AdapterContractGrammar::validate_adapter_contract($ownerless),
    RuntimeException::class,
    'a non-plugin adapter cannot acquire plugin incompatibility authority',
    "declares 'incompatible_plugins' without owning a plugin"
);

$expected = "wprism: manifest 'rank-math' for plugin 'seo-by-rank-math/rank-math.php' declares plugin "
    . "'wordpress-seo/wp-seo.php' incompatible, and pinned manifest(s) {'yoast'} claim that plugin — "
    . 'incompatible plugin adapters cannot share one policy; pin only one';
$message = static function (array $manifests): string {
    try {
        AdapterContractGrammar::validate_no_incompatible_plugins($manifests);
    } catch (RuntimeException $failure) {
        return $failure->getMessage();
    }
    return '';
};
wprism_check_same($expected, $message([$rank, $yoast]),
    'Rank Math then Yoast refuses with the canonical incompatibility verdict');
wprism_check_same($expected, $message([$yoast, $rank]),
    'Yoast then Rank Math refuses with the same verdict rather than pin-order precedence');
wprism_check_same('', $message([$rank]),
    'an incompatibility declaration does not refuse when the competing adapter is absent');

$site = sys_get_temp_dir() . '/wprism_plugin_incompatibility_' . bin2hex(random_bytes(8));
if (!mkdir($site, 0700, true) && !is_dir($site)) {
    throw new RuntimeException("could not create scratch repository '$site'");
}
register_shutdown_function(static function () use ($site): void {
    @unlink($site . '/sentinel');
    @unlink($site . '/site.wprism.json');
    @rmdir($site);
});
Canon::write_file($site . '/site.wprism.json', Canon::encode([
    'manifests' => [
        ['name' => 'rank-math', 'source' => 'shipped'],
        ['name' => 'yoast', 'source' => 'shipped'],
    ],
    'policy' => new stdClass(),
    'spec_version' => WPRISM_SPEC_VERSION,
]));
file_put_contents($site . '/sentinel', "unchanged\n");
$library = AdapterLibrary::fromSourceTree($root);
foreach ([
    ['core', 'rank-math', 'yoast'],
    ['core', 'yoast', 'rank-math'],
] as $pins) {
    wprism_check_throws(
        static fn() => Policy::load($site, $pins, adapterLibrary: $library),
        RuntimeException::class,
        'the full policy load refuses the incompatible pair in order ' . implode(',', $pins),
        $expected
    );
    wprism_check_same("unchanged\n", file_get_contents($site . '/sentinel'),
        'the policy refusal leaves neighboring repository bytes unchanged');
    wprism_check(!is_dir($site . '/.wprism'),
        'the policy refusal occurs before any transaction or recovery state can be created');
}

// A pin need not exist for an incompatible plugin to be active. Compile the
// canonical state-only repository: CodeCompatibility is intentionally absent.
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
$manifest = [
    'name' => 'fixture-free-login', 'spec_version' => 3,
    'engine_features' => ['plugin-incompatibility/v1', 'spec-window/v1'],
    'plugin' => 'fixture-login/login.php', 'version_range' => ['min' => '1.0', 'max' => '2.0'],
    'incompatible_plugins' => ['fixture-competitor/login.php'],
    'option_autoload' => 'preserve',
    'options' => ['active_plugins' => ['class' => 'managed'], 'fixture_login_route' => ['class' => 'authored']],
];
$config = WPrismTest\FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$config['policy']['post_types'] = $config['policy']['taxonomies'] = [];
$policy = WPrismTest\FrozenPolicy::policy([$manifest], $config);
$repo = $site . '/compiler';
mkdir($repo . '/state/options', 0700, true);
register_shutdown_function(static function () use ($repo, $site): void {
    foreach (['/state/options/core.json', '/site.wprism.json'] as $path) @unlink($repo . $path);
    foreach (['/state/options', '/state', ''] as $path) @rmdir($repo . $path);
    @rmdir($site);
});
Canon::write_file($repo . '/site.wprism.json', Canon::encode($config));
$writeActive = static function (array $active) use ($repo): string {
    $bytes = Canon::encode(WPrism\OptionState::document([
        'active_plugins' => WPrism\OptionState::present($active, 'yes'),
        'fixture_login_route' => WPrism\OptionState::present('fixture-route', 'yes'),
    ]));
    Canon::write_file($repo . '/state/options/core.json', $bytes);
    return $bytes;
};
$verdicts = [];
foreach ([
    ['fixture-login/login.php', 'fixture-competitor/login.php'],
    ['fixture-competitor/login.php', 'fixture-login/login.php'],
    ['fixture-competitor/login.php'],
] as $active) {
    $bytes = $writeActive($active);
    $verdict = null;
    try {
        WPrism\RepositoryCompiler::compile($repo, $policy);
    } catch (WPrism\RepositoryAuthorizationException $failure) {
        $verdict = $failure->payload();
    }
    wprism_check_same('repository_active_plugin_incompatible', $verdict['diagnostics'][0]['code'] ?? null,
        'compilation refuses an active competitor without its adapter pin');
    wprism_check_same('fixture-competitor/login.php', $verdict['diagnostics'][0]['field'] ?? null,
        'the authorization finding names the exact active competitor');
    wprism_check_same('fixture-free-login', $verdict['diagnostics'][0]['declared_by'] ?? null,
        'the authorization finding names the contract that forbids the competitor');
    $verdicts[] = $verdict;
    wprism_check_same($bytes, Canon::read_file($repo . '/state/options/core.json'),
        'incompatible compilation leaves canonical state unchanged');
    wprism_check(!is_dir($repo . '/.wprism'), 'incompatible compilation creates no target or recovery state');
    // Capture publishes only after this same in-memory authorization boundary.
    wprism_check_throws(static fn() => WPrism\RepositoryAuthorization::assert_tree($policy, [
        'options/core' => ['type' => 'options', 'path' => 'options/core.json', 'data' => Canon::decode($bytes)],
    ]), WPrism\RepositoryAuthorizationException::class,
        'capture-tree authorization refuses the same unpinned active competitor', 'repository_active_plugin_incompatible');
}
wprism_check_same($verdicts[0], $verdicts[1], 'active-plugin order cannot change the structured incompatibility verdict');
foreach ([['fixture-login/login.php'], [], ['fixture-login/login.php', 'fixture-competitor/other.php']] as $active) {
    $writeActive($active);
    $compiled = WPrism\RepositoryCompiler::compile($repo, $policy);
    wprism_check_same($active, WPrism\OptionState::values($compiled->tree()['options/core']['data'])['active_plugins'],
        'an exact compatible desired graph compiles, allowing lifecycle to remove a target competitor');
}

wprism_check_summary('regress_plugin_incompatibility');
