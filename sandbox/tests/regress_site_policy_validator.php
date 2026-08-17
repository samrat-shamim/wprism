<?php
/**
 * Offline regression for the shared site.duo.json validation sequence
 * (DUO-3348 slice 29).
 *
 * Policy::load() and Policy::from_snapshot() used to carry duplicate calls to
 * the same eight pure site-policy grammars. This suite drives the extracted
 * collaborator directly, proves caller-supplied vocabularies and refusal
 * labels are preserved, and checks both Policy entry points route through it.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../agent/src/Policy/SitePolicyValidator.php';
require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/manifest_fixtures.php';

use Duo\Canon;
use Duo\Policy;
use Duo\SitePolicyValidator;

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

$baseSite = [
    'policy' => [
        'options' => [],
        'post_meta' => [],
        'term_meta' => [],
        'user_meta' => [],
    ],
];

try {
    SitePolicyValidator::validate($baseSite, 'site.duo.json', Policy::CLASSES, ['block', 'warn']);
    $check(true, 'valid legacy site policy is accepted');
} catch (Throwable $e) {
    $check(false, 'valid legacy site policy is accepted (threw: ' . $e->getMessage() . ')');
}

$customSite = $baseSite;
$customSite['policy']['user_meta'] = [
    'acme_custom' => ['class' => 'custom'],
];
try {
    SitePolicyValidator::validate($customSite, 'custom-site.json', ['custom'], ['retain']);
    $check(true, 'site validation consumes explicit class/missing-user vocabularies');
} catch (Throwable $e) {
    $check(false, 'site validation consumes explicit class/missing-user vocabularies (threw: ' . $e->getMessage() . ')');
}

$customMissingUserSite = $baseSite;
$customMissingUserSite['policy']['user_meta'] = [
    'acme_authored' => ['class' => 'authored', 'missing_user' => 'retain'],
];
try {
    SitePolicyValidator::validate($customMissingUserSite, 'custom-site.json', ['authored'], ['retain']);
    $check(true, 'site validation consumes the caller-supplied missing-user vocabulary');
} catch (Throwable $e) {
    $check(false, 'site validation consumes the caller-supplied missing-user vocabulary (threw: ' . $e->getMessage() . ')');
}

$badEnv = $baseSite;
$badEnv['policy']['options'] = [
    'acme_secret' => ['class' => 'env'],
];
$assertThrows(
    fn() => SitePolicyValidator::validate($badEnv, 'frozen site.duo.json', Policy::CLASSES, ['block', 'warn']),
    'needs an explicit boolean',
    'site validation preserves frozen refusal labels/order'
);

$badSubKeys = $baseSite;
$badSubKeys['policy']['options'] = [
    'acme_runtime' => ['class' => 'runtime', 'sub_keys' => []],
];
$assertThrows(
    fn() => SitePolicyValidator::validate($badSubKeys, 'frozen site.duo.json', Policy::CLASSES, ['block', 'warn']),
    'declares options.acme_runtime.sub_keys but it is not a non-empty object',
    'site validation reaches the SubKeyGrammar site-policy gate'
);

$badPolicyType = $baseSite;
$badPolicyType['policy'] = 'not-an-object';
try {
    SitePolicyValidator::validate($badPolicyType, 'site.duo.json', Policy::CLASSES, ['block', 'warn']);
    $check(false, 'scalar site policy remains a typed refusal');
} catch (TypeError $e) {
    $check(true, 'scalar site policy remains a typed refusal');
} catch (Throwable $e) {
    $check(false, 'scalar site policy remains a typed refusal (wrong exception: ' . $e::class . ')');
}

$policySource = (string) file_get_contents(__DIR__ . '/../../agent/src/Policy/Policy.php');
$validatorSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Policy/SitePolicyValidator.php');
$check(
    substr_count($policySource, 'SitePolicyValidator::validate(') === 2
        && !str_contains($policySource, 'CodeConfigGrammar::validate_site_code($p->site,')
        && !str_contains($policySource, 'ReferenceShapeGrammar::validate_reference_shapes($p->site[\'policy\'] ?? [],'),
    'both Policy loaders delegate the site sequence instead of retaining duplicate grammar calls'
);
$check(
    substr_count($validatorSource, 'CodeConfigGrammar::validate_site_code($site, $label)') === 1
        && substr_count($validatorSource, 'ScopeGrammar::validate_scope_classes($site, $label, true)') === 1
        && substr_count($validatorSource, "OptionGrammar::validate_option_storage(\$site['policy'] ?? [], \$label)") === 1
        && substr_count($validatorSource, "OptionGrammar::validate_env_options(\$site['policy'] ?? [], \$label)") === 1
        && substr_count($validatorSource, 'UserMetaGrammar::validate_user_meta_rules(') === 1
        && substr_count($validatorSource, "ManifestGrammar::validate_tables(\$site['policy'] ?? [], \$label)") === 1
        && substr_count($validatorSource, "SubKeyGrammar::validate_sub_keys(\$site['policy'] ?? [], \$label)") === 1
        && substr_count($validatorSource, "ReferenceShapeGrammar::validate_reference_shapes(\$site['policy'] ?? [], \$label)") === 1
        && (strpos($validatorSource, 'CodeConfigGrammar::validate_site_code(') < strpos($validatorSource, 'ScopeGrammar::validate_scope_classes('))
        && (strpos($validatorSource, 'ScopeGrammar::validate_scope_classes(') < strpos($validatorSource, 'OptionGrammar::validate_option_storage('))
        && (strpos($validatorSource, 'OptionGrammar::validate_option_storage(') < strpos($validatorSource, 'OptionGrammar::validate_env_options('))
        && (strpos($validatorSource, 'OptionGrammar::validate_env_options(') < strpos($validatorSource, 'UserMetaGrammar::validate_user_meta_rules('))
        && (strpos($validatorSource, 'UserMetaGrammar::validate_user_meta_rules(') < strpos($validatorSource, 'ManifestGrammar::validate_tables('))
        && (strpos($validatorSource, 'ManifestGrammar::validate_tables(') < strpos($validatorSource, 'SubKeyGrammar::validate_sub_keys('))
        && (strpos($validatorSource, 'SubKeyGrammar::validate_sub_keys(') < strpos($validatorSource, 'ReferenceShapeGrammar::validate_reference_shapes(')),
    'SitePolicyValidator owns all eight ordered site grammar calls'
);

$snapshot = [
    'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
    'capabilities' => null,
    'dispositions' => null,
    'format' => 'duo-policy-snapshot/v4',
    'manifests' => [manifest_a()],
    'site' => $baseSite + [
        'manifests' => ['a'],
        'spec_version' => DUO_SPEC_VERSION,
    ],
];
try {
    Policy::from_snapshot($snapshot);
    $check(true, 'Policy::from_snapshot() reaches the extracted site validator');
} catch (Throwable $e) {
    $check(false, 'Policy::from_snapshot() reaches the extracted site validator (threw: ' . $e->getMessage() . ')');
}

$root = sys_get_temp_dir() . '/duo_regress_site_policy_validator_' . bin2hex(random_bytes(4));
$manifests = $root . '/manifests';
mkdir($manifests, 0777, true);
manifest_fixture_code($manifests);
Canon::write_file($root . '/site.duo.json', Canon::encode($snapshot['site']));
Canon::write_file($manifests . '/a.json', Canon::encode(manifest_a()));
$previousManifestsDir = getenv('DUO_MANIFESTS_DIR');
putenv("DUO_MANIFESTS_DIR=$manifests");
try {
    Policy::load($root);
    $check(true, 'Policy::load() reaches the extracted site validator');
} catch (Throwable $e) {
    $check(false, 'Policy::load() reaches the extracted site validator (threw: ' . $e->getMessage() . ')');
}
if ($previousManifestsDir === false) {
    putenv('DUO_MANIFESTS_DIR');
} else {
    putenv("DUO_MANIFESTS_DIR=$previousManifestsDir");
}
foreach (glob($manifests . '/*') ?: [] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
manifest_fixture_code_cleanup($manifests);
rmdir($manifests);
unlink($root . '/site.duo.json');
rmdir($root);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall SitePolicyValidator checks passed\n";
