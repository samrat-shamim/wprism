<?php
/**
 * Offline regression for OptionReferenceGrammar (DUO-3348 slice 11).
 *
 * The option-name-ref runtime resolver remains on Policy and the discovery
 * consumer remains on Capture/OptionsMaterializer. This suite characterizes
 * the moved manifest-only grammar directly, proves both Policy loader entry
 * points still invoke it, and proves the cross-manifest identical-pattern
 * guard has no compatibility duplicate left on Policy.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/OptionReferenceGrammar.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use Duo\Canon;
use Duo\OptionReferenceGrammar;
use Duo\Policy;
use DuoTest\FrozenPolicy;

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

$rule = static function (array $overrides = []): array {
    return array_merge([
        'autoload' => 'yes',
        'class' => 'authored',
        'id_kind' => 'acme_slot',
        'match' => '^acme_b_slot_(?<id>[1-9][0-9]*)_settings$',
        'malformed_match' => '^acme_b_slot_(?:0|0[0-9]+)_settings$',
    ], $overrides);
};

$assertAccepted(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => [$rule()],
    ]),
    'a complete positive option-name-ref declaration is accepted'
);
$assertAccepted(
    static fn() => OptionReferenceGrammar::validate_option_name_refs(['name' => 'acme']),
    'a manifest without option_name_refs is accepted'
);
$assertAccepted(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => [$rule(['malformed_match' => '^acme_b_slot_0_settings$'])],
    ]),
    'malformed_match is optional and accepts a separate valid regex'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => 'not-a-list',
    ]),
    'option_name_refs must be a list',
    'option_name_refs refuses a scalar declaration'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => ['named' => $rule()],
    ]),
    'option_name_refs must be a list',
    'option_name_refs refuses an associative object instead of an ordered list'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => [$rule(['class' => 'not-a-class'])],
    ]),
    'must declare class, id_kind, and a valid match regex',
    'option_name_refs refuses an unknown classification class'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => [$rule(['id_kind' => 'Acme-Slot'])],
    ]),
    'must declare class, id_kind, and a valid match regex',
    'option_name_refs refuses an id_kind outside its lowercase key grammar'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => [$rule(['match' => '^[a-z]+$'])],
    ]),
    'must contain exactly one named (?<id>...) capture',
    'option_name_refs requires exactly one named id capture'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => [$rule(['match' => '['])],
    ]),
    'must declare class, id_kind, and a valid match regex',
    'option_name_refs refuses an invalid match regex'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_option_name_refs([
        'name' => 'acme',
        'option_name_refs' => [$rule(['malformed_match' => '['])],
    ]),
    'malformed_match must be a non-empty valid regex',
    'option_name_refs refuses an invalid malformed_match regex'
);

$assertAccepted(
    static fn() => OptionReferenceGrammar::validate_no_overlapping_option_name_refs([
        ['name' => 'a', 'option_name_refs' => [$rule()]],
        ['name' => 'b', 'option_name_refs' => [$rule(['match' => '^acme_b_slot_(?<id>[1-9][0-9]*)_other$'])]],
    ]),
    'different option-name-ref regexes remain order-independent and accepted'
);
$assertAccepted(
    static fn() => OptionReferenceGrammar::validate_no_overlapping_option_name_refs([
        ['name' => 'a'],
        ['name' => 'b', 'option_name_refs' => []],
    ]),
    'manifests without option-name-ref rules do not create overlap claims'
);
$assertThrows(
    static fn() => OptionReferenceGrammar::validate_no_overlapping_option_name_refs([
        ['name' => 'a', 'option_name_refs' => [$rule()]],
        ['name' => 'b', 'option_name_refs' => [$rule()]],
    ]),
    "'a[0]' and 'b[0]' have identical overlapping match regexes",
    'identical option-name-ref regexes are refused across manifests'
);

/** Build the smallest frozen envelope accepted by the real loader. */
$frozenSnapshot = static function (array $manifests): array {
    return FrozenPolicy::envelope($manifests, FrozenPolicy::site($manifests, DUO_SPEC_VERSION));
};

$validManifests = [
    manifest_a(),
    manifest_b(['option_name_refs' => [$rule()]]),
];
$assertAccepted(
    static fn() => Policy::from_snapshot($frozenSnapshot($validManifests)),
    'a valid option-name-ref declaration loads through Policy::from_snapshot()'
);
$assertThrows(
    static fn() => Policy::from_snapshot($frozenSnapshot([
        manifest_a(),
        manifest_b(['option_name_refs' => [$rule(['match' => '^[a-z]+$'])]]),
    ])),
    'must contain exactly one named (?<id>...) capture',
    'Policy::from_snapshot() invokes the extracted option-name-ref grammar'
);
$assertThrows(
    static fn() => Policy::from_snapshot($frozenSnapshot([
        manifest_a(['option_name_refs' => [$rule(['id_kind' => 'acme_room'])]]),
        manifest_b(['option_name_refs' => [$rule()]]),
    ])),
    "'a[0]' and 'b[0]' have identical overlapping match regexes",
    'Policy::from_snapshot() invokes the extracted cross-manifest duplicate-pattern guard'
);

$loadRoot = sys_get_temp_dir() . '/duo_regress_option_reference_' . bin2hex(random_bytes(4));
$loadManifests = $loadRoot . '/manifests';
mkdir($loadManifests, 0777, true);
Canon::write_file($loadRoot . '/site.duo.json', Canon::encode([
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => DUO_SPEC_VERSION,
]));
manifest_fixture_code($loadManifests);
Canon::write_file($loadManifests . '/a.json', Canon::encode(manifest_a()));
Canon::write_file($loadManifests . '/b.json', Canon::encode($validManifests[1]));
$previousManifestsDir = getenv('DUO_MANIFESTS_DIR');
putenv("DUO_MANIFESTS_DIR=$loadManifests");
$assertAccepted(
    static fn() => Policy::load($loadRoot),
    'a valid option-name-ref declaration loads through Policy::load()'
);
Canon::write_file($loadManifests . '/b.json', Canon::encode(
    manifest_b(['option_name_refs' => [$rule(['malformed_match' => '['])]])
));
$assertThrows(
    static fn() => Policy::load($loadRoot),
    'malformed_match must be a non-empty valid regex',
    'Policy::load() invokes the extracted option-name-ref grammar'
);
Canon::write_file($loadManifests . '/a.json', Canon::encode(
    manifest_a(['option_name_refs' => [$rule(['id_kind' => 'acme_room'])]])
));
Canon::write_file($loadManifests . '/b.json', Canon::encode($validManifests[1]));
$assertThrows(
    static fn() => Policy::load($loadRoot),
    "'a[0]' and 'b[0]' have identical overlapping match regexes",
    'Policy::load() invokes the extracted cross-manifest duplicate-pattern guard'
);

if ($previousManifestsDir === false) {
    putenv('DUO_MANIFESTS_DIR');
} else {
    putenv("DUO_MANIFESTS_DIR=$previousManifestsDir");
}
foreach (glob($loadManifests . '/*') ?: [] as $file) {
    @unlink($file);
}
manifest_fixture_code_cleanup($loadManifests);
@rmdir($loadManifests);
@unlink($loadRoot . '/site.duo.json');
@rmdir($loadRoot);

$policySource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$finalizerSource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/PolicyLoadFinalizer.php');
$manifestValidatorSource = file_get_contents(__DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php');
$policyReflection = new ReflectionClass(Policy::class);
$optionGrammarReflection = new ReflectionClass(OptionReferenceGrammar::class);
foreach (['validate_option_name_refs', 'validate_no_overlapping_option_name_refs'] as $method) {
    $check(
        !$policyReflection->hasMethod($method),
        "Policy.php no longer defines $method() itself (moved to OptionReferenceGrammar.php)"
    );
    $check(
        $optionGrammarReflection->hasMethod($method)
            && $optionGrammarReflection->getMethod($method)->isPublic(),
        "OptionReferenceGrammar::$method() is the public moved entry point"
    );
}
$check(
    substr_count($policySource, 'OptionReferenceGrammar::validate_option_name_refs(') === 0
        && substr_count($manifestValidatorSource, 'OptionReferenceGrammar::validate_option_name_refs(') === 1
        && substr_count($policySource, 'PolicyLoadFinalizer::finalize(') === 2
        && substr_count($policySource, 'OptionReferenceGrammar::validate_no_overlapping_option_name_refs(') === 0
        && substr_count($finalizerSource, 'OptionReferenceGrammar::validate_no_overlapping_option_name_refs(') === 1,
    'ManifestValidator owns local option-name refs while PolicyLoadFinalizer owns the cross-manifest guard'
);
$check(
    Policy::CLASSES === ['authored', 'runtime', 'derived', 'env', 'managed'],
    'option-name-ref extraction reads the unchanged shared Policy::CLASSES vocabulary'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall OptionReferenceGrammar checks passed\n";
exit(0);
