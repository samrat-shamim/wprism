<?php
/**
 * Offline regression for DiscoveryGrammar (issue #3348 slice 13).
 *
 * Live discovery remains on Capture and its consumers. This suite proves the
 * moved manifest-only grammar directly, then drives the real Policy::load()
 * and Policy::from_snapshot() entry points so a loader can never silently
 * skip the pure option-namespace or authored-meta keyspace checks.
 */
declare(strict_types=1);

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/DiscoveryGrammar.php';
require_once __DIR__ . '/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use WPrism\Canon;
use WPrism\DiscoveryGrammar;
use WPrism\Policy;
use WPrismTest\FrozenPolicy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$assertThrows = static function (callable $fn, string $needle, string $label) use ($check): void {
    try {
        $fn();
        $check(false, "$label: expected a refusal, none thrown");
    } catch (\RuntimeException $e) {
        $check(
            str_contains($e->getMessage(), $needle),
            "$label: refusal names \"$needle\" (got: {$e->getMessage()})"
        );
    }
};

$assertAccepted = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(true, "$label: accepted");
    } catch (\Throwable $e) {
        $check(false, "$label: unexpectedly refused ({$e->getMessage()})");
    }
};

$keyspace = static function (array $overrides = []): array {
    return array_replace_recursive([
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'keys' => ['known_setting'],
        'patterns' => [['match' => '^upgrade_']],
    ], $overrides);
};

$metaTable = static function (array $overrides = []) use ($keyspace): array {
    return array_replace_recursive([
        'class' => 'authored_snapshot_meta',
        'attached_to' => ['table' => 'acme_a_rooms', 'column' => 'room_id'],
        'keyspace' => $keyspace(),
    ], $overrides);
};

$manifest = [
    'name' => 'acme',
    'option_namespaces' => [['match' => '^acme_']],
    'tables' => ['acme_meta' => $metaTable()],
];

$assertAccepted(
    static fn() => DiscoveryGrammar::validate_discovery_contract($manifest),
    'a valid option namespace and version-pinned authored-meta keyspace are accepted'
);
$assertAccepted(
    static fn() => DiscoveryGrammar::validate_discovery_contract(['name' => 'acme']),
    'omitted discovery declarations remain accepted'
);
$assertThrows(
    static fn() => DiscoveryGrammar::validate_discovery_contract([
        'name' => 'acme',
        'option_namespaces' => 'not-a-list',
    ]),
    'option_namespaces must be an array',
    'option namespaces refuse a scalar declaration'
);
$assertThrows(
    static fn() => DiscoveryGrammar::validate_discovery_contract([
        'name' => 'acme',
        'option_namespaces' => [['match' => '']],
    ]),
    'option_namespaces[0].match must be a non-empty valid regex',
    'option namespaces refuse an empty matcher'
);
$assertThrows(
    static fn() => DiscoveryGrammar::validate_discovery_contract([
        'name' => 'acme',
        'option_namespaces' => [['match' => '[']],
    ]),
    'option_namespaces[0].match must be a non-empty valid regex',
    'option namespaces refuse an invalid matcher'
);
$assertThrows(
    static fn() => DiscoveryGrammar::validate_discovery_contract([
        'name' => 'acme',
        'tables' => ['acme_meta' => $metaTable([
            'keyspace' => $keyspace(['version_range' => ['min' => '2.0.0', 'max' => '1.0.0']]),
        ])],
    ]),
    "manifest 'acme' table 'acme_meta' keyspace version_range has a malformed range",
    'authored-meta keyspaces refuse a reversed version range'
);
$assertThrows(
    static fn() => DiscoveryGrammar::validate_discovery_contract([
        'name' => 'acme',
        'tables' => ['acme_meta' => $metaTable([
            'keyspace' => $keyspace(['keys' => 'known_setting']),
        ])],
    ]),
    'keyspace keys/patterns must be arrays',
    'authored-meta keyspaces refuse scalar keys'
);
$assertThrows(
    static fn() => DiscoveryGrammar::validate_discovery_contract([
        'name' => 'acme',
        'tables' => ['acme_meta' => $metaTable([
            'keyspace' => $keyspace(['keys' => ['']]),
        ])],
    ]),
    'keyspace.keys must contain non-empty strings',
    'authored-meta keyspaces refuse empty literal keys'
);
$assertThrows(
    static fn() => DiscoveryGrammar::validate_discovery_contract([
        'name' => 'acme',
        'tables' => ['acme_meta' => $metaTable([
            'keyspace' => $keyspace(['patterns' => [['match' => '[']]]),
        ])],
    ]),
    "keyspace.patterns[0].match must be a valid regex",
    'authored-meta keyspaces refuse invalid pattern matchers'
);

/** Build the smallest frozen envelope accepted by the real loader. */
$frozenSnapshot = static function (array $manifests): array {
    return FrozenPolicy::envelope($manifests, FrozenPolicy::site($manifests, WPRISM_SPEC_VERSION));
};

$manifestA = manifest_a();
$manifestA['tables']['acme_a_meta'] = $metaTable();
$validManifests = [$manifestA, manifest_b()];
$assertAccepted(
    static fn() => manifest_fixture_policy_from_snapshot($frozenSnapshot($validManifests)),
    'a valid discovery declaration loads through Policy::from_snapshot()'
);
$invalidSnapshot = $validManifests;
$invalidSnapshot[0]['tables']['acme_a_meta']['keyspace']['patterns'][0]['match'] = '[';
$assertThrows(
    static fn() => manifest_fixture_policy_from_snapshot($frozenSnapshot($invalidSnapshot)),
    "manifest 'a' table 'acme_a_meta' keyspace.patterns[0].match must be a valid regex",
    'Policy::from_snapshot() invokes the extracted discovery grammar'
);

$loadRoot = sys_get_temp_dir() . '/wprism_regress_discovery_grammar_' . bin2hex(random_bytes(4));
$loadManifests = $loadRoot . '/manifests';
mkdir($loadManifests, 0777, true);
Canon::write_file($loadRoot . '/site.wprism.json', Canon::encode([
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => WPRISM_SPEC_VERSION,
]));
manifest_fixture_code($loadManifests);
Canon::write_file($loadManifests . '/a.json', Canon::encode($manifestA));
Canon::write_file($loadManifests . '/b.json', Canon::encode($validManifests[1]));
$adapterLibrary = manifest_fixture_adapter_library($loadManifests);
$assertAccepted(
    static fn() => Policy::load($loadRoot, adapterLibrary: $adapterLibrary),
    'a valid discovery declaration loads through Policy::load()'
);
$loadInvalid = $manifestA;
$loadInvalid['tables']['acme_a_meta']['keyspace']['keys'] = [''];
Canon::write_file($loadManifests . '/a.json', Canon::encode($loadInvalid));
$assertThrows(
    static fn() => Policy::load($loadRoot, adapterLibrary: $adapterLibrary),
    "manifest 'a' table 'acme_a_meta' keyspace.keys must contain non-empty strings",
    'Policy::load() invokes the extracted discovery grammar'
);
manifest_fixture_remove_tree($loadRoot);

$policySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$manifestValidatorSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php');
$policyReflection = new ReflectionClass(Policy::class);
$grammarReflection = new ReflectionClass(DiscoveryGrammar::class);
$check(
    !$policyReflection->hasMethod('validate_discovery_contract'),
    'Policy.php no longer defines validate_discovery_contract() itself'
);
$check(
    $grammarReflection->hasMethod('validate_discovery_contract')
        && $grammarReflection->getMethod('validate_discovery_contract')->isPublic(),
    'DiscoveryGrammar exposes the public moved entry point'
);
$check(
    substr_count($policySource, 'DiscoveryGrammar::validate_discovery_contract($manifest)') === 0
        && substr_count($manifestValidatorSource, 'DiscoveryGrammar::validate_discovery_contract($manifest)') === 1,
    'ManifestValidator owns the shared discovery grammar call for both Policy loader paths'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall DiscoveryGrammar checks passed\n";
