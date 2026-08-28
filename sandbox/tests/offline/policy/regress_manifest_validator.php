<?php
/**
 * Offline regression for the pure per-manifest validation pipeline
 * (DUO-3348 slice 28).
 *
 * The direct calls characterize both live and frozen ordering modes, while
 * the loader checks prove source/pin handling remains outside the extracted
 * collaborator. No WordPress, database, provider, or disposable pair is
 * needed.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php';
require_once __DIR__ . '/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use Duo\Canon;
use Duo\ManifestValidator;
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
    } catch (RuntimeException $e) {
        $check(
            str_contains($e->getMessage(), $needle),
            "$label: refusal names \"$needle\" (got: {$e->getMessage()})"
        );
    }
};

$published = Policy::closed_vocabularies();
$vocabulary = [
    'derivable_field_columns' => $published['post_derivable_fields'],
    'field_classes' => $published['post_field_classes'],
    'menu_derivable_fields' => $published['menu_derivable_fields'],
    'menu_field_classes' => $published['menu_field_classes'],
    'casts' => $published['value_casts'],
    'classes' => $published['classification_classes'],
    'missing_user_modes' => $published['user_meta_missing_user_modes'],
];

$manifest = manifest_a([
    'widgets' => ['acme_card' => ['settings' => ['title' => ['class' => 'authored']]]],
]);

try {
    ManifestValidator::validate_manifest($manifest, "manifest 'a'", $vocabulary);
    $check(true, 'direct validator accepts the live ordering mode');
} catch (Throwable $e) {
    $check(false, 'direct validator accepts the live ordering mode (threw: ' . $e->getMessage() . ')');
}
try {
    ManifestValidator::validate_manifest($manifest, "frozen manifest 'a'", $vocabulary, true);
    $check(true, 'direct validator accepts the frozen ordering mode');
} catch (Throwable $e) {
    $check(false, 'direct validator accepts the frozen ordering mode (threw: ' . $e->getMessage() . ')');
}

$customVocabulary = $vocabulary;
$customVocabulary['field_classes'] = ['custom-only'];
$assertThrows(
    fn() => ManifestValidator::validate_manifest($manifest, "manifest 'a'", $customVocabulary),
    'only custom-only',
    'direct validator consumes the caller-supplied engine vocabulary'
);

$validatorSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php');
$policySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$check(
    substr_count($policySource, 'ManifestValidator::validate_manifest(') === 2
        && !str_contains($policySource, 'FieldGrammar::validate_field_classes($manifest,')
        && !str_contains($policySource, 'TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations($manifest);')
        && str_contains($validatorSource, 'bool $dynamicOptionsBeforeTaxonomy = false'),
    'Policy delegates both local pipelines while ManifestValidator owns their order'
);

$frozenSnapshot = static function (array $manifests): array {
    return FrozenPolicy::envelope($manifests, FrozenPolicy::site($manifests, DUO_SPEC_VERSION));
};
try {
    manifest_fixture_policy_from_snapshot($frozenSnapshot([manifest_a(), manifest_b()]));
    $check(true, 'Policy::from_snapshot() uses the extracted frozen pipeline');
} catch (Throwable $e) {
    $check(false, 'Policy::from_snapshot() uses the extracted frozen pipeline (threw: ' . $e->getMessage() . ')');
}

$root = sys_get_temp_dir() . '/duo_regress_manifest_validator_' . bin2hex(random_bytes(4));
$manifests = $root . '/manifests';
mkdir($manifests, 0777, true);
manifest_fixture_code($manifests);
Canon::write_file($root . '/site.duo.json', Canon::encode([
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => DUO_SPEC_VERSION,
]));
Canon::write_file($manifests . '/a.json', Canon::encode(manifest_a()));
Canon::write_file($manifests . '/b.json', Canon::encode(manifest_b()));
$adapterLibrary = manifest_fixture_adapter_library($manifests);
try {
    Policy::load($root, adapterLibrary: $adapterLibrary);
    $check(true, 'Policy::load() uses the extracted live pipeline');
} catch (Throwable $e) {
    $check(false, 'Policy::load() uses the extracted live pipeline (threw: ' . $e->getMessage() . ')');
}
manifest_fixture_remove_tree($root);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall ManifestValidator checks passed\n";
