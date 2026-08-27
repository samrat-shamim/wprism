<?php
/**
 * Offline regression for CodeConfigGrammar (DUO-3348 slice 25).
 *
 * The optional site.duo.json code envelope is a pure Policy-load grammar.
 * This suite exercises the extracted wrapper directly, then proves the
 * legacy/valid/refusal behavior through both Policy::from_snapshot() and
 * Policy::load(). Code itself remains the owner of the exact descriptor
 * contract; this file checks that Policy's label-aware diagnostic boundary is
 * preserved around it.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Code/Code.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/CodeConfigGrammar.php';
require_once __DIR__ . '/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use Duo\Canon;
use Duo\CodeConfigGrammar;
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

$validCode = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
$assertAccepted(
    static fn() => CodeConfigGrammar::validate_site_code([], 'site.duo.json'),
    'the legacy state-only site shape remains accepted'
);
$assertAccepted(
    static fn() => CodeConfigGrammar::validate_site_code(['code' => $validCode], 'site.duo.json'),
    'the exact v1 code envelope remains accepted'
);
$assertThrows(
    static fn() => CodeConfigGrammar::validate_site_code(['code' => 'enabled'], 'site.duo.json'),
    'code must be an object with exactly format, layout, and source',
    'a scalar code declaration is refused at the envelope boundary'
);
$assertThrows(
    static fn() => CodeConfigGrammar::validate_site_code(['code' => [$validCode]], 'site.duo.json'),
    'code must be an object with exactly format, layout, and source',
    'a list-shaped code declaration is refused at the envelope boundary'
);
$assertThrows(
    static fn() => CodeConfigGrammar::validate_site_code(['code' => ['format' => 1, 'layout' => 'custom', 'source' => 'code/wp-content']], 'site.duo.json'),
    'site.duo.json code declaration is invalid: duo: site.duo.json code must contain exactly',
    'an invalid descriptor keeps Code’s exact contract behind the label-aware wrapper'
);
$assertThrows(
    static fn() => CodeConfigGrammar::validate_site_code(['code' => ['format' => 1, 'layout' => 'custom', 'source' => 'code/wp-content']], 'frozen site.duo.json'),
    'duo: frozen site.duo.json code declaration is invalid:',
    'the frozen loader label is preserved by the extracted wrapper'
);

$frozenSnapshot = static function (array $manifests, ?array $code = null): array {
    $site = FrozenPolicy::site($manifests, DUO_SPEC_VERSION);
    if ($code !== null) {
        $site['code'] = $code;
    }
    return FrozenPolicy::envelope($manifests, $site);
};

$snapshotManifests = [manifest_a(), manifest_b()];
$snapshotPolicy = manifest_fixture_policy_from_snapshot($frozenSnapshot($snapshotManifests, $validCode));
$check(
    $snapshotPolicy->code_config() === $validCode,
    'Policy::from_snapshot() reaches CodeConfigGrammar and preserves the valid code config'
);
$assertThrows(
    static fn() => manifest_fixture_policy_from_snapshot($frozenSnapshot($snapshotManifests, ['format' => 1, 'layout' => 'custom', 'source' => 'code/wp-content'])),
    'duo: frozen site.duo.json code declaration is invalid:',
    'Policy::from_snapshot() preserves the extracted grammar refusal'
);

$loadRoot = sys_get_temp_dir() . '/duo_regress_code_config_' . bin2hex(random_bytes(4));
$loadManifests = $loadRoot . '/manifests';
mkdir($loadManifests, 0777, true);
manifest_fixture_code($loadManifests);
Canon::write_file($loadRoot . '/site.duo.json', Canon::encode([
    'code' => $validCode,
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => DUO_SPEC_VERSION,
]));
Canon::write_file($loadManifests . '/a.json', Canon::encode($snapshotManifests[0]));
Canon::write_file($loadManifests . '/b.json', Canon::encode($snapshotManifests[1]));
$adapterLibrary = manifest_fixture_adapter_library($loadManifests);
$livePolicy = Policy::load($loadRoot, adapterLibrary: $adapterLibrary);
$check(
    $livePolicy->code_config() === $validCode,
    'Policy::load() reaches CodeConfigGrammar and preserves the valid code config'
);
Canon::write_file($loadRoot . '/site.duo.json', Canon::encode([
    'code' => ['format' => 1, 'layout' => 'custom', 'source' => 'code/wp-content'],
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => DUO_SPEC_VERSION,
]));
$assertThrows(
    static fn() => Policy::load($loadRoot, adapterLibrary: $adapterLibrary),
    'duo: site.duo.json code declaration is invalid:',
    'Policy::load() preserves the extracted grammar refusal'
);
manifest_fixture_remove_tree($loadRoot);

$policySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$sitePolicyValidatorSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/SitePolicyValidator.php');
$policyReflection = new ReflectionClass(Policy::class);
$grammarReflection = new ReflectionClass(CodeConfigGrammar::class);
$check(
    !$policyReflection->hasMethod('validate_code_config')
        && $grammarReflection->hasMethod('validate_site_code')
        && $grammarReflection->getMethod('validate_site_code')->isPublic()
        && substr_count($policySource, 'CodeConfigGrammar::validate_site_code(') === 0
        && substr_count($sitePolicyValidatorSource, 'CodeConfigGrammar::validate_site_code(') === 1,
    'SitePolicyValidator owns CodeConfigGrammar site validation and Policy has no private duplicate'
);

if ($failures !== []) {
    fwrite(STDERR, 'regress_code_config_grammar: ' . count($failures) . " failure(s)\n");
    exit(1);
}
