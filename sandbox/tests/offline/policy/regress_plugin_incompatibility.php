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

wprism_check_summary('regress_plugin_incompatibility');
