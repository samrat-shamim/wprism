<?php
/**
 * Offline adversarial contract for DUO-3314's separately signed site-adapter
 * certification.  The fixture is deliberately plugin-blind: its adapter is
 * only a small data manifest, yet the test exercises the exact authority,
 * bundle, ratification, Ed25519, live, and frozen boundaries a real adapter
 * would use.
 */
declare(strict_types=1);

if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', '0.5.0');
}
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Init/InitPlanner.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterCertification.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/Deploy.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/Providers.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';
require_once __DIR__ . '/../../../../cli/src/Transport/CodeDeploy.php';

/** Minimal command runner surface for exercising the real Cli handler offline. */
if (!class_exists('WP_CLI', false)) {
    final class WP_CLI {
        public static array $lines = [];

        public static function add_command($name, $class): void {}
        public static function line($line): void { self::$lines[] = (string) $line; }
        public static function warning($message): void { self::line('WARNING: ' . $message); }
        public static function success($message): void { self::line('SUCCESS: ' . $message); }
        public static function halt($code): void { throw new RuntimeException('WP_CLI halt ' . $code); }
        public static function error($message): void { throw new RuntimeException((string) $message); }
    }
}

// These are the narrow WordPress runtime seams Deploy::plugin_runtime_state()
// and Providers::plugin_supplied_providers() use. They intentionally return
// only the fixture's installed/active plugin and one plugin-owned object;
// there is no WordPress bootstrap or network access in this regression.
if (!function_exists('is_wp_error')) {
    function is_wp_error($value): bool { return false; }
}
if (!function_exists('validate_plugin')) {
    function validate_plugin($plugin): bool { return (string) $plugin === 'acme/acme.php'; }
}
if (!function_exists('get_plugins')) {
    function get_plugins(): array {
        return ['acme/acme.php' => ['Version' => '1.2.3']];
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $name === 'active_plugins' ? ['acme/acme.php'] : $default;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        if ($tag === 'duo_providers' && ($GLOBALS['cert_plugin_registry_throw'] ?? null) !== null) {
            throw new RuntimeException($GLOBALS['cert_plugin_registry_throw']);
        }
        return $tag === 'duo_providers'
            ? (array) ($GLOBALS['cert_plugin_providers'] ?? [])
            : $value;
    }
}
require_once __DIR__ . '/../../../../agent/src/Command/Cli.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\InitPlanner;
use Duo\ManifestDispositions;
use Duo\Policy;
use Duo\Providers;
use Duo\RepositoryCompiler;
use Duo\Orchestrator\CodeDeploy;
use Duo\Orchestrator\PlanSummary;

$failures = 0;

function cert_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
    } else {
        echo "FAIL: $message\n";
        $failures++;
    }
}

function cert_expect_throw(callable $fn, string $needle, string $message): void {
    try {
        $fn();
        cert_check(false, "$message (no exception)");
    } catch (Throwable $e) {
        cert_check(str_contains($e->getMessage(), $needle), "$message ({$e->getMessage()})");
    }
}

/** @return ?array<string,mixed> */
function cert_provider_blocker(array $rows, string $code): ?array {
    foreach ($rows as $row) {
        if (is_array($row) && ($row['code'] ?? null) === $code) {
            return $row;
        }
    }
    return null;
}

function cert_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        cert_remove_tree($item->getPathname());
    }
    rmdir($path);
}

function cert_copy_tree(string $from, string $to): void {
    if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
        throw new RuntimeException("cannot create fixture directory: $to");
    }
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getBasename();
        if ($item->isDir() && !$item->isLink()) {
            cert_copy_tree($item->getPathname(), $target);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("cannot copy fixture file: {$item->getPathname()}");
        }
    }
}

function cert_write(string $path, string $contents): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create fixture directory: $dir");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("cannot write fixture file: $path");
    }
}

function cert_write_canon(string $path, $value): void {
    cert_write($path, Canon::encode($value));
}

function cert_bundle_pretty($value): string {
    return json_encode(
        Canon::normalize($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
}

/** @return array{path:string,sha256:string,size:int} */
function cert_descriptor(string $path, string $relative): array {
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("cannot read fixture descriptor file: $path");
    }
    return [
        'path' => $relative,
        'sha256' => hash('sha256', $contents),
        'size' => strlen($contents),
    ];
}

function cert_bundle_digest(array $bundle): string {
    unset($bundle['bundle_digest']);
    return hash('sha256', json_encode(
        Canon::normalize($bundle),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
}

/**
 * Create the exact subset of a site-adapter-scoped certification bundle needed for one
 * certified site adapter.  Files used for facts that later need frozen
 * revalidation (the ratification and environment) use repository Canon;
 * bundle.json and result assets use the established bundle producer's
 * four-space canonical encoding.
 *
 * @param array<string,mixed> $options
 */
function cert_write_bundle(
    string $dir,
    string $site,
    array $ratification,
    array $options = []
): string {
    $name = (string) ($options['name'] ?? 'site-demo');
    $environment = [
        'multisite' => false,
        'php' => '8.3.0',
        'wordpress' => '7.0.2',
    ];
    cert_write_canon($dir . '/environment.json', $environment);
    cert_write_canon($dir . '/ratification.json', $ratification);
    $result = $options['result'] ?? [
        'exit_code' => 0,
        'schema_version' => 1,
        'test' => 'site-conformance',
        'verdict' => 'pass',
    ];
    cert_write(
        $dir . '/results/site-conformance.json',
        cert_bundle_pretty($result)
    );
    cert_write_canon($dir . '/diffs/site-conformance.json', ['changed' => [], 'status' => 'clean']);
    cert_write($dir . '/logs/site-conformance.txt', "site adapter conformance passed\n");
    $exercised = ($options['evidence']['exercised'] ?? true) === true;

    $adapterPath = $site . '/adapters/' . $name . '.json';
    $boundInputs = $options['bound_inputs'] ?? [
        cert_descriptor($adapterPath, 'adapters/' . $name . '.json'),
    ];
    $bundle = [
        // Non-empty by default (DUO-3339/B2): `artifacts[]` is the half of a
        // certified adapter's VERSION story that says what was actually
        // exercised and at which version, and it is the one field
        // `certification_evidence` has to decode the retained envelope to
        // recover. A fixture that always shipped an empty list could not tell
        // "this bundle exercised nothing" from "the projection dropped it".
        'artifacts' => $options['artifacts'] ?? ($exercised ? [[
            'name' => 'site-demo-boundary',
            'role' => 'certified-boundary',
            'sha256' => str_repeat('c', 64),
            'url' => 'https://example.invalid/site-demo-boundary-1.2.3.zip',
            'version' => '1.2.3',
        ]] : []),
        'bound_inputs' => $boundInputs,
        'created_at' => '2026-08-09T00:00:00Z',
        'environment' => cert_descriptor($dir . '/environment.json', 'environment.json'),
        'environment_summary' => $environment,
        // What the bundle actually proves, declared rather than implied
        // (round-3 T6). The default is the reviewed-exercise shape every
        // platform-rooted certificate must take; a site-rooted fixture passes
        // `exercised: false` with empty tests/artifacts.
        'evidence' => $options['evidence'] ?? [
            'exercised' => true,
            'grammar' => 'ok',
            'reason' => 'the external review exercised this adapter against a live conformance target',
        ],
        'force_hatches' => $options['force_hatches'] ?? [],
        'git_revision' => $options['git_revision'] ?? str_repeat('a', 40),
        'harness' => ['name' => 'site-adapter-certification-regression', 'version' => 1],
        'ratification' => cert_descriptor($dir . '/ratification.json', 'ratification.json'),
        'ratification_summary' => [
            'certified_claims' => ['manifests.' . $name],
            'manifest_count' => 1,
            'profile_count' => 0,
        ],
        'schema_version' => AdapterCertification::BUNDLE_FORMAT,
        'subject' => [
            'kind' => 'site_adapter',
            'name' => $name,
        ],
        'tests' => $exercised ? [[
            'diff' => cert_descriptor($dir . '/diffs/site-conformance.json', 'diffs/site-conformance.json'),
            'id' => 'site-conformance',
            'log' => cert_descriptor($dir . '/logs/site-conformance.txt', 'logs/site-conformance.txt'),
            'result' => cert_descriptor($dir . '/results/site-conformance.json', 'results/site-conformance.json'),
            'verdict' => 'pass',
        ]] : [],
        'verdict' => 'pass',
    ];
    $bundle['bundle_digest'] = cert_bundle_digest($bundle);
    cert_write($dir . '/bundle.json', cert_bundle_pretty($bundle));
    return $dir;
}

/** @return array{exit:int,stdout:string,stderr:string} */
function cert_run(array $command): array {
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start fixture command');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "FAIL: PHP sodium extension is required for certification regression\n");
    exit(1);
}

$root = sys_get_temp_dir() . '/duo_site_adapter_certification_' . bin2hex(random_bytes(6));
if (!defined('ABSPATH')) {
    // The provider diagnostic gate recognizes an ordinary WordPress target,
    // while this harness supplies its narrow lifecycle primitives itself.
    define('ABSPATH', $root . '/');
}
$agent = $root . '/agent-manifests';
$site = $root . '/site';
$bundle = $root . '/bundle';
$policyManifests = $root . '/policy-manifests';
register_shutdown_function(static fn() => cert_remove_tree($root));

$manifest = [
    'name' => 'site-demo',
    'option_autoload' => 'preserve',
    'post_types' => [],
    'spec_version' => DUO_SPEC_VERSION,
    'tables' => [],
];
$ratification = [
    'format' => 'duo-manifest-dispositions/v1',
    'manifests' => [
        'site-demo' => [
            'capabilities' => [
                'deletion_semantics' => ['supported' => [], 'unsupported' => ['all']],
                'entity_sections' => ['post_types'],
                'field_sections' => [],
                'lifecycle_phases' => [],
                'operations' => ['apply', 'capture', 'deploy'],
            ],
            'default_authored_keyspaces' => [],
            'evidence' => [
                'bundle_schema' => AdapterCertification::BUNDLE_FORMAT,
                'tests' => ['site-conformance'],
            ],
            'reason' => 'The external review covered this exact declarative site adapter.',
            'status' => 'certified',
            'supported_versions' => ['wordpress' => ['source' => 'fixture']],
            'unsupported' => [[
                'operation' => 'delete',
                'reason' => 'Deletion was not part of this focused certification.',
                'surface' => 'all',
            ]],
        ],
    ],
    'profiles' => [],
];
$platform = [
    'agent_version' => DUO_AGENT_VERSION,
    'branchable_state' => 'only exact certified registry surfaces and operations',
    'compatibility' => [
        // The shipped boundary's own shapes: a per-engine database map and a
        // range-plus-exercised-series php axis. A certification fixture that
        // kept the retired single-engine/bare-range shapes would still hash
        // and verify (AdapterCertification only requires `compatibility` be an
        // object), and would therefore stop being evidence that
        // platform_sha256 binds the boundary a site actually ships.
        'database' => [
            'engines' => [
                'MariaDB' => ['max' => '12.0.0', 'min' => '11.0.0'],
                'MySQL' => ['max' => '8.5.0', 'min' => '8.4.0'],
            ],
            'note' => 'fixture',
        ],
        'php' => ['max' => '8.5.0', 'min' => '8.3.0', 'note' => 'fixture',
            'verified' => ['8.3' => '8.3.33', '8.4' => '8.4.24']],
        'wordpress' => ['last_verified' => '7.0.2', 'note' => 'fixture'],
    ],
    'plugin_execution' => 'unmodified',
    'site_mode' => 'single-site',
    'spec_version' => DUO_SPEC_VERSION,
];

cert_write_canon($site . '/adapters/site-demo.json', $manifest);
cert_write_canon($policyManifests . '/site-demo.json', $manifest);
putenv('DUO_MANIFESTS_DIR=' . $policyManifests);
try {
    Policy::load(null, ['site-demo']);
    cert_check(true, 'the fixture adapter is a valid Policy spec-v2 manifest independent of certification');
} catch (Throwable $e) {
    cert_check(false, 'the fixture adapter is a valid Policy spec-v2 manifest independent of certification (' . $e->getMessage() . ')');
}
putenv('DUO_MANIFESTS_DIR');
cert_write_canon($agent . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $platform,
]);

$keypair = sodium_crypto_sign_seed_keypair(str_repeat('K', SODIUM_CRYPTO_SIGN_SEEDBYTES));
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$keys = new stdClass();
$keys->{'review-key'} = [
    'adapter_names' => ['site-demo'],
    'algorithm' => 'ed25519',
    'public_key' => base64_encode($public),
    'scope' => 'site_adapter_certification',
    'status' => 'trusted',
    'trust_tiers' => ['declarative_manifest'],
];
$authorities = [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $keys,
];
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $authorities);

cert_write_bundle($bundle, $site, $ratification);
cert_write($root . '/review-secret.key', base64_encode($secret) . "\n");
chmod($root . '/review-secret.key', 0644);

echo "\n== signing/import tool and live verifier ==\n";
$numericName = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'verify',
    '--manifest-dir=' . $agent,
    '--repo=' . $site,
    '--name=123',
]);
cert_check(
    $numericName['exit'] !== 0 && str_contains($numericName['stderr'], 'numeric-only identities')
        && !str_contains($numericName['stderr'], 'cannot read manifest'),
    'CLI refuses a numeric-only adapter identity before any path or map lookup'
);
$badName = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'verify',
    '--manifest-dir=' . $agent,
    '--repo=' . $site,
    '--name=../outside',
]);
cert_check(
    $badName['exit'] !== 0 && str_contains($badName['stderr'], 'site adapter certification name')
        && !str_contains($badName['stderr'], 'cannot read manifest'),
    'CLI validates a path-like name before interpolating it into an adapter path'
);
$openKey = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $agent,
    '--repo=' . $site,
    '--name=site-demo',
    '--bundle=' . $bundle,
    '--evidence-repo=' . $site,
    '--authority=review-key',
    '--secret-key-file=' . $root . '/review-secret.key',
]);
cert_check(
    $openKey['exit'] !== 0 && str_contains($openKey['stderr'], 'must not be group/world accessible'),
    'CLI refuses a group/world-readable private-key file'
);
chmod($root . '/review-secret.key', 0600);
symlink($root . '/review-secret.key', $root . '/review-secret-link');
$linkedKey = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $agent,
    '--repo=' . $site,
    '--name=site-demo',
    '--bundle=' . $bundle,
    '--evidence-repo=' . $site,
    '--authority=review-key',
    '--secret-key-file=' . $root . '/review-secret-link',
]);
cert_check(
    $linkedKey['exit'] !== 0 && str_contains($linkedKey['stderr'], 'regular non-symlink'),
    'CLI refuses a symlink private-key file'
);
$sign = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $agent,
    '--repo=' . $site,
    '--name=site-demo',
    '--bundle=' . $bundle,
    '--evidence-repo=' . $site,
    '--authority=review-key',
    '--secret-key-file=' . $root . '/review-secret.key',
]);
$certPath = $site . '/adapters/certifications/site-demo.json';
$signSummary = json_decode($sign['stdout'], true);
cert_check($sign['exit'] === 0 && is_array($signSummary), 'signer imports a full verified bundle and emits only a summary');
cert_check(is_file($certPath), 'signer writes only the path-derived adapters/certifications/site-demo.json certificate');
cert_check(($signSummary['certificate_path'] ?? null) === 'adapters/certifications/site-demo.json', 'signer reports the derived certificate path');

$verified = AdapterCertification::verifyFile($agent, $site, 'site-demo', $manifest, $certPath);
$proof = $verified['disposition']['provenance']['proof'] ?? [];
cert_check(($verified['disposition']['certification'] ?? null) === 'certified', 'live verifier derives a certified disposition');
cert_check(($verified['disposition']['trust_tier'] ?? null) === 'declarative_manifest', 'derived disposition carries the authority-scoped trust tier');
cert_check(($verified['disposition']['provenance']['path'] ?? null) === 'adapters/site-demo.json', 'derived disposition carries the path-derived site provenance');
cert_check(
    isset($proof['authority']['fingerprint'], $proof['authority']['key_id'], $proof['authority']['record_sha256'])
    && isset($proof['bundle']['digest'], $proof['bundle']['git_revision'], $proof['bundle']['tests'])
    && isset($proof['certificate_sha256'], $proof['statement_sha256'], $proof['platform_sha256'], $proof['ratification_sha256'], $proof['raw_input']),
    'derived disposition digest-binds authority, envelope, signed statement, bundle, ratification, platform, and raw-input proof facts'
);
cert_check(
    ($verified['claim']['evidence']['status'] ?? null) === 'current'
    && ($verified['claim']['evidence']['git_revision'] ?? null) === str_repeat('a', 40)
    && ($verified['claim']['evidence']['tests'] ?? null) === ['site-conformance']
    && ($verified['claim']['evidence']['statement_sha256'] ?? null) === ($proof['statement_sha256'] ?? null),
    'per-row capability claim carries its own current signed evidence facts'
);
cert_check(
    ($verified['claim']['platform']['agent_version'] ?? null) === DUO_AGENT_VERSION
    && ($verified['claim']['platform']['spec_version'] ?? null) === DUO_SPEC_VERSION
    && ($verified['claim']['provider_code']['binding'] ?? null) === 'providers_negotiation',
    'per-row claim carries its signed platform boundary while plugin provider code remains negotiation-anchored'
);

$verify = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'verify',
    '--manifest-dir=' . $agent,
    '--repo=' . $site,
    '--name=site-demo',
]);
cert_check($verify['exit'] === 0 && is_array(json_decode($verify['stdout'], true)), 'verification tool revalidates the exact path-derived certificate');

echo "\n== plugin-owned provider certification and offline negotiation ==\n";
$providerSite = $root . '/provider-site';
$providerBundle = $root . '/provider-bundle';
$providerAgent = $root . '/provider-agent';
$providerPolicyManifests = $root . '/provider-policy-manifests';
$providerRange = ['min' => '1.0.0', 'max' => '2.0.0'];
$providerManifest = [
    'actions' => [[
        'args' => [],
        'capability' => 'rebuild_cache',
        'effects' => [[
            'id' => 'site-provider-effect',
            'kind' => 'external',
            'mode' => 'irreversible',
            'selector' => [
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'provider:site-cache/rebuild_cache',
            ],
        ]],
        'kind' => 'provider',
        'provider' => 'site-cache',
        'triggers' => ['post:page'],
    ]],
    'name' => 'site-plugin',
    'option_autoload' => 'preserve',
    'plugin' => 'acme/acme.php',
    'post_types' => [],
    'providers' => [[
        'capabilities' => ['rebuild_cache'],
        'id' => 'site-cache',
        'plugin' => 'acme/acme.php',
        'source' => 'plugin',
        'version' => '1.2.3',
    ]],
    'spec_version' => DUO_SPEC_VERSION,
    'tables' => [],
    'version_range' => $providerRange,
];
$providerRatification = $ratification;
$providerRatification['manifests'] = [
    'site-plugin' => $ratification['manifests']['site-demo'],
];
$providerRatification['manifests']['site-plugin']['reason'] =
    'The external review covered this exact plugin-owned provider adapter and its typed action boundary.';
$providerRatification['manifests']['site-plugin']['supported_versions'] = [
    'plugin' => 'acme/acme.php',
    'range' => $providerRange,
];
cert_write_canon($providerSite . '/adapters/site-plugin.json', $providerManifest);
cert_write_canon($providerPolicyManifests . '/site-plugin.json', $providerManifest);
putenv('DUO_MANIFESTS_DIR=' . $providerPolicyManifests);
try {
    Policy::load(null, ['site-plugin']);
    cert_check(true, 'the plugin-provider fixture is a valid structured spec-v2 manifest without loading provider code');
} catch (Throwable $e) {
    cert_check(false, 'the plugin-provider fixture is a valid structured spec-v2 manifest without loading provider code (' . $e->getMessage() . ')');
}
putenv('DUO_MANIFESTS_DIR');

$providerKeys = new stdClass();
$providerKeys->{'provider-key'} = [
    'adapter_names' => ['site-plugin'],
    'algorithm' => 'ed25519',
    'public_key' => base64_encode($public),
    'scope' => 'site_adapter_certification',
    'status' => 'trusted',
    'trust_tiers' => ['plugin_provider'],
];
$providerAuthorities = [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $providerKeys,
];
cert_write_canon($providerAgent . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $platform,
]);
cert_write_canon($providerAgent . '/capabilities/adapter-authorities.json', $providerAuthorities);
cert_write_bundle($providerBundle, $providerSite, $providerRatification, ['name' => 'site-plugin']);

// A provider-capable adapter must not be certifiable with a lower authority
// tier, even when the key and bundle are otherwise valid.
$providerKeys->{'provider-key'}['trust_tiers'] = ['declarative_manifest'];
cert_write_canon($providerAgent . '/capabilities/adapter-authorities.json', $providerAuthorities);
$badProviderTier = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $providerAgent,
    '--repo=' . $providerSite,
    '--name=site-plugin',
    '--bundle=' . $providerBundle,
    '--evidence-repo=' . $providerSite,
    '--authority=provider-key',
    '--secret-key-file=' . $root . '/review-secret.key',
]);
cert_check(
    $badProviderTier['exit'] !== 0 && str_contains($badProviderTier['stderr'], 'not scoped to derived trust tier'),
    'a plugin-provider certificate cannot be signed by an authority scoped only to declarative manifests'
);
$providerKeys->{'provider-key'}['trust_tiers'] = ['plugin_provider'];
cert_write_canon($providerAgent . '/capabilities/adapter-authorities.json', $providerAuthorities);
$providerSign = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $providerAgent,
    '--repo=' . $providerSite,
    '--name=site-plugin',
    '--bundle=' . $providerBundle,
    '--evidence-repo=' . $providerSite,
    '--authority=provider-key',
    '--secret-key-file=' . $root . '/review-secret.key',
]);
$providerCertPath = $providerSite . '/adapters/certifications/site-plugin.json';
cert_check(
    $providerSign['exit'] === 0 && is_file($providerCertPath),
    'the real signer emits a signed plugin-provider certificate from an offline bundle'
);
$providerVerified = AdapterCertification::verifyFile(
    $providerAgent,
    $providerSite,
    'site-plugin',
    $providerManifest,
    $providerCertPath
);
cert_check(
    ($providerVerified['disposition']['certification'] ?? null) === 'certified'
    && ($providerVerified['disposition']['trust_tier'] ?? null) === 'plugin_provider'
    && ($providerVerified['claim']['provider_code']['binding'] ?? null) === 'providers_negotiation',
    'the signed provider adapter proves plugin_provider authority scope while executable semantics remain negotiation-bound'
);

$pluginDir = $root . '/wp-content/plugins';
$pluginFile = $pluginDir . '/acme/acme.php';
cert_write($pluginFile, <<<'PLUGIN'
<?php
final class DuoCertificationPluginProvider {
    public function identity(): array {
        return ['id' => 'site-cache', 'plugin' => 'acme/acme.php', 'version' => '1.2.3'];
    }

    public function capabilities(): array {
        return ['rebuild_cache' => [
            'args' => [],
            'idempotent' => true,
            'reads' => ['post:page'],
            'scope' => 'site',
            'timeout_seconds' => 1,
            'writes' => ['post:page'],
        ]];
    }

    public function invoke(string $capability, array $args): array {
        $GLOBALS['cert_provider_invocations'] = (int) ($GLOBALS['cert_provider_invocations'] ?? 0) + 1;
        if ($capability !== 'rebuild_cache' || $args !== []) {
            throw new RuntimeException('unexpected provider fixture invocation');
        }
        return ['before' => 'fixture-before', 'after' => 'fixture-after', 'verified' => true];
    }
}

final class DuoCertificationWrongRegistrationProvider {
    public function identity(): array {
        return ['id' => 'site-cache', 'plugin' => 'acme/acme.php', 'version' => '9.9.9'];
    }

    public function capabilities(): array {
        return ['rebuild_cache' => [
            'args' => [],
            'idempotent' => true,
            'reads' => ['post:page'],
            'scope' => 'site',
            'timeout_seconds' => 1,
            'writes' => ['post:page'],
        ]];
    }

    public function invoke(string $capability, array $args): array {
        $GLOBALS['cert_provider_invocations'] = (int) ($GLOBALS['cert_provider_invocations'] ?? 0) + 1;
        throw new RuntimeException('diagnostics must not invoke a wrong-registration provider');
    }
}

final class DuoCertificationMissingCapabilityProvider {
    public function identity(): array {
        return ['id' => 'site-cache', 'plugin' => 'acme/acme.php', 'version' => '1.2.3'];
    }

    public function capabilities(): array {
        return ['rebuild_other_cache' => [
            'args' => [],
            'idempotent' => true,
            'reads' => ['post:page'],
            'scope' => 'site',
            'timeout_seconds' => 1,
            'writes' => ['post:page'],
        ]];
    }

    public function invoke(string $capability, array $args): array {
        $GLOBALS['cert_provider_invocations'] = (int) ($GLOBALS['cert_provider_invocations'] ?? 0) + 1;
        throw new RuntimeException('diagnostics must not invoke a missing-capability provider');
    }
}

final class DuoCertificationThrowingCapabilitiesProvider {
    public function identity(): array {
        return ['id' => 'site-cache', 'plugin' => 'acme/acme.php', 'version' => '1.2.3'];
    }

    public function capabilities(): array {
        throw new RuntimeException("https://provider.example.test/rebuild?access_token=DUO_PROVIDER_SECRET_TOKEN\nINJECTED_PROVIDER_LINE");
    }

    public function invoke(string $capability, array $args): array {
        $GLOBALS['cert_provider_invocations'] = (int) ($GLOBALS['cert_provider_invocations'] ?? 0) + 1;
        throw new RuntimeException('diagnostics must not invoke a capabilities-throwing provider');
    }
}

final class DuoCertificationThrowingIdentityProvider {
    public function identity(): array {
        throw new RuntimeException("https://provider.example.test/identity?access_token=DUO_PROVIDER_SECRET_TOKEN\nINJECTED_PROVIDER_LINE");
    }

    public function capabilities(): array {
        return ['rebuild_cache' => [
            'args' => [],
            'idempotent' => true,
            'reads' => ['post:page'],
            'scope' => 'site',
            'timeout_seconds' => 1,
            'writes' => ['post:page'],
        ]];
    }

    public function invoke(string $capability, array $args): array {
        $GLOBALS['cert_provider_invocations'] = (int) ($GLOBALS['cert_provider_invocations'] ?? 0) + 1;
        throw new RuntimeException('diagnostics must not invoke an identity-throwing provider');
    }
}

final class DuoCertificationMalformedIdentityProvider {
    public function identity(): array {
        return ['id' => new class {
            public function __toString(): string {
                throw new RuntimeException("https://provider.example.test/identity?access_token=DUO_PROVIDER_SECRET_TOKEN\nINJECTED_PROVIDER_LINE");
            }
        }];
    }

    public function capabilities(): array {
        return ['rebuild_cache' => [
            'args' => [],
            'idempotent' => true,
            'reads' => ['post:page'],
            'scope' => 'site',
            'timeout_seconds' => 1,
            'writes' => ['post:page'],
        ]];
    }

    public function invoke(string $capability, array $args): array {
        $GLOBALS['cert_provider_invocations'] = (int) ($GLOBALS['cert_provider_invocations'] ?? 0) + 1;
        throw new RuntimeException('diagnostics must not invoke a malformed-identity provider');
    }
}
PLUGIN
);
require_once $pluginFile;
$GLOBALS['cert_plugin_providers'] = [new \DuoCertificationPluginProvider()];
$GLOBALS['cert_plugin_registry_throw'] = null;
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', $pluginDir);
}
$providerReflection = new ReflectionClass($GLOBALS['cert_plugin_providers'][0]);
$providerCodeFile = $providerReflection->getFileName();
cert_check(
    is_string($providerCodeFile)
    && str_starts_with((string) realpath($providerCodeFile), rtrim((string) realpath($pluginDir), '/') . '/')
    && !str_starts_with((string) realpath($providerCodeFile), rtrim((string) realpath($providerSite), '/') . '/adapters/'),
    'the negotiated provider class is physically plugin-owned rather than copied into the site adapter'
);

echo "\n== target context lazy plugin API negotiation ==\n";
// A real WP-CLI request has ABSPATH/WP_PLUGIN_DIR and the ordinary runtime
// seams before wp-admin/includes/plugin.php is loaded. Deploy owns that
// include-on-demand step. Run a clean PHP child so validate_plugin(),
// get_plugins(), and is_wp_error() genuinely begin absent: making the
// Policy diagnostic gate require them would skip the very runtime check this
// change needs to add.
$lazyWp = $root . '/lazy-wp';
$lazyPlugins = $lazyWp . '/wp-content/plugins';
$lazyProvider = $lazyPlugins . '/lazy/lazy.php';
cert_write($lazyWp . '/wp-admin/includes/plugin.php', <<<'PHP'
<?php
function validate_plugin(string $plugin): int { return 0; }
function get_plugins(): array { return ['lazy/lazy.php' => ['Version' => '1.0.0']]; }
function is_wp_error(mixed $value): bool { return false; }
PHP
);
cert_write($lazyProvider, <<<'PHP'
<?php
final class DuoCertificationLazyApiProvider {
    public function identity(): array {
        return ['id' => 'lazy-cache', 'plugin' => 'lazy/lazy.php', 'version' => '1.0.0'];
    }
    public function capabilities(): array {
        return ['flush' => [
            'args' => [], 'idempotent' => true, 'reads' => ['post:page'],
            'scope' => 'site', 'timeout_seconds' => 1, 'writes' => ['post:page'],
        ]];
    }
    public function invoke(string $capability, array $args): array {
        $GLOBALS['lazy_provider_invocations'] = (int) ($GLOBALS['lazy_provider_invocations'] ?? 0) + 1;
        return ['before' => null, 'after' => null, 'verified' => true];
    }
}
PHP
);
$lazyScript = str_replace(
    ['__ABSPATH__', '__WP_PLUGIN_DIR__', '__PROVIDER_FILE__', '__ENGINE_ROOT__'],
    [
        var_export($lazyWp . '/', true),
        var_export($lazyPlugins, true),
        var_export($lazyProvider, true),
        var_export(dirname(__DIR__, 4), true),
    ],
    <<<'PHP'
<?php
declare(strict_types=1);
define('DUO_SPEC_VERSION', 2);
define('ABSPATH', __ABSPATH__);
define('WP_PLUGIN_DIR', __WP_PLUGIN_DIR__);
function apply_filters(string $tag, mixed $value): mixed {
    return $tag === 'duo_providers' ? (array) ($GLOBALS['lazy_providers'] ?? []) : $value;
}
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'active_plugins' ? ['lazy/lazy.php'] : $default;
}
$payload = [
    'admin_api_absent_before' => !function_exists('validate_plugin')
        && !function_exists('get_plugins') && !function_exists('is_wp_error'),
];
require __ENGINE_ROOT__ . '/agent/src/Kernel/Canon.php';
require __ENGINE_ROOT__ . '/agent/src/Kernel/OptionState.php';
require __ENGINE_ROOT__ . '/agent/src/Policy/Policy.php';
require __ENGINE_ROOT__ . '/agent/src/Promotion/Deploy.php';
require __ENGINE_ROOT__ . '/agent/src/Adapter/Providers.php';
require __PROVIDER_FILE__;
$GLOBALS['lazy_providers'] = [new DuoCertificationLazyApiProvider()];
$GLOBALS['lazy_provider_invocations'] = 0;
$payload['gate_before_loader'] = \Duo\Providers::runtime_negotiation_available();
$lazyManifest = [
    'name' => 'lazy-provider',
    'spec_version' => 2,
    'plugin' => 'lazy/lazy.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'providers' => [[
        'id' => 'lazy-cache', 'plugin' => 'lazy/lazy.php',
        'source' => 'plugin', 'version' => '1.0.0', 'capabilities' => ['flush'],
    ]],
    'actions' => [[
        'kind' => 'provider', 'provider' => 'lazy-cache', 'capability' => 'flush', 'args' => [],
        'triggers' => ['post:page'],
        'effects' => [[
            'id' => 'lazy-cache-effect', 'kind' => 'external', 'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => 'provider_resource', 'value' => 'provider:lazy-cache/flush'],
        ]],
    ]],
];
// The v6 wire proves shipped membership against the trusted library instead of
// trusting the snapshot, so this synthetic adapter needs a library that really
// holds its bytes — otherwise from_snapshot() refuses before the lazy-loading
// question this child process exists to answer is ever reached.
$lazyLibrary = sys_get_temp_dir() . '/duo-lazy-manifests-' . getmypid();
@mkdir($lazyLibrary, 0700, true);
file_put_contents($lazyLibrary . '/lazy-provider.json', \Duo\Canon::encode($lazyManifest));
putenv('DUO_MANIFESTS_DIR=' . $lazyLibrary);
try {
    $policy = \Duo\Policy::from_snapshot([
        'format' => 'duo-policy-snapshot/v6',
        'adapter_sources' => ['certificates' => [], 'format' => 'duo-adapter-sources/v2', 'out_of_tree' => []],
        'dispositions' => null,
        'site' => [
            'manifests' => ['lazy-provider'],
            'spec_version' => 2,
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
        ],
        'manifests' => [$lazyManifest],
    ]);
    $negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:page']));
    $payload['problems'] = $negotiation['problems'];
} catch (\Throwable $failure) {
    $payload['error'] = $failure->getMessage();
}
$payload['admin_api_loaded_after'] = function_exists('validate_plugin')
    && function_exists('get_plugins') && function_exists('is_wp_error');
$payload['invocations'] = $GLOBALS['lazy_provider_invocations'];
@unlink($lazyLibrary . '/lazy-provider.json');
@rmdir($lazyLibrary);
echo json_encode($payload, JSON_THROW_ON_ERROR);
PHP
);
$lazyScriptPath = $lazyWp . '/lazy-api-negotiation.php';
cert_write($lazyScriptPath, $lazyScript);
$lazyRun = cert_run([PHP_BINARY, $lazyScriptPath]);
$lazyPayload = json_decode($lazyRun['stdout'], true);
cert_check(
    $lazyRun['exit'] === 0
    && is_array($lazyPayload)
    && ($lazyPayload['admin_api_absent_before'] ?? null) === true
    && ($lazyPayload['gate_before_loader'] ?? null) === true
    && ($lazyPayload['admin_api_loaded_after'] ?? null) === true
    && ($lazyPayload['problems'] ?? null) === []
    && ($lazyPayload['invocations'] ?? null) === 0,
    'runtime diagnostics negotiate a real target after Deploy lazily loads wp-admin plugin APIs, without invoking the provider'
);

echo "\n== plugin-owned provider identity boundary ==\n";
$providerManifest = $manifest;
$providerManifest['providers'] = [[
    'capabilities' => ['refresh'],
    'id' => 'fixture-refresh',
    'plugin' => 'fixture-provider/fixture-provider.php',
    'source' => 'plugin',
    'version' => '1.0.0',
]];
$badProviderManifest = $providerManifest;
$badProviderManifest['providers'][0]['plugin'] = '../outside.php';
cert_write_canon($site . '/adapters/site-demo.json', $badProviderManifest);
cert_expect_throw(
    static fn() => AdapterCertification::sign(
        $agent, $site, 'site-demo', $bundle, $site, 'review-key', base64_encode($secret)
    ),
    'plugin basename',
    'signing rejects a path-like plugin-owned provider identity even with no top-level plugin claim'
);

$declarativeCertificateRaw = (string) file_get_contents($certPath);
$declarativeTiers = $keys->{'review-key'}['trust_tiers'];
cert_write_canon($site . '/adapters/site-demo.json', $providerManifest);
$keys->{'review-key'}['trust_tiers'] = ['declarative_manifest', 'plugin_provider'];
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $authorities);
$providerBundle = $root . '/provider-bundle';
cert_write_bundle($providerBundle, $site, $ratification);
$providerCertificate = AdapterCertification::sign(
    $agent, $site, 'site-demo', $providerBundle, $site, 'review-key', base64_encode($secret)
);
cert_write($certPath, $providerCertificate);
$providerVerified = AdapterCertification::verifyFile($agent, $site, 'site-demo', $providerManifest, $certPath);
cert_check(
    ($providerVerified['disposition']['trust_tier'] ?? null) === 'plugin_provider',
    'a valid plugin-owned provider without a top-level plugin claim signs and verifies at the derived plugin_provider tier'
);

$providerPolicyManifests = $root . '/provider-policy-manifests';
cert_write_canon($providerPolicyManifests . '/capabilities/adapter-authorities.json', $authorities);
cert_write_canon($providerPolicyManifests . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $platform,
]);
cert_write_canon($site . '/site.duo.json', [
    'manifests' => [['name' => 'site-demo', 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]);
putenv('DUO_MANIFESTS_DIR=' . $providerPolicyManifests);
try {
    $providerPolicy = Policy::load($site);
    cert_check(
        $providerPolicy->adapter_sources()->source('site-demo') === 'site'
        && $providerPolicy->adapter_sources()->provenance('site-demo')['trust_tier'] === 'plugin_provider',
        'a site pin validates the same safe provider plugin basename before runtime provider negotiation'
    );
} catch (Throwable $e) {
    cert_check(false, 'a site pin validates the same safe provider plugin basename before runtime provider negotiation (' . $e->getMessage() . ')');
} finally {
    putenv('DUO_MANIFESTS_DIR');
    unlink($site . '/site.duo.json');
}
cert_write($certPath, $declarativeCertificateRaw);
cert_write_canon($site . '/adapters/site-demo.json', $manifest);
$keys->{'review-key'}['trust_tiers'] = $declarativeTiers;
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $authorities);
echo "\n== Policy, digest pin, reporting, and host-promotion integration ==\n";
$integrationManifests = $root . '/integration-manifests';
cert_copy_tree(dirname(__DIR__, 4) . '/manifests', $integrationManifests);
// The copied library keeps its shipped dispositions and gets this fixture's
// platform boundary, so every signature below binds one known platform.
//
// It used to additionally neutralise the copied registry's evidence bindings —
// a copy that still named current scoped evidence made the runtime re-verify
// closure inputs against this scratch library's parent, which is no deployed
// layout, and refuse. No document binds a repository input any more, so there
// is nothing to neutralise and the copy is usable as it stands.
cert_write_canon($integrationManifests . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $platform,
]);
$integrationKeys = new stdClass();
$integrationKeys->{'review-key'} = $keys->{'review-key'};
$integrationKeys->{'provider-key'} = $providerKeys->{'provider-key'};
cert_write_canon($integrationManifests . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $integrationKeys,
]);
$originalCertificateRaw = (string) file_get_contents($certPath);

putenv('DUO_MANIFESTS_DIR=' . $integrationManifests);
try {
    cert_write_canon($providerSite . '/site.duo.json', [
        'manifests' => [['name' => 'site-plugin', 'source' => 'site']],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    $providerUnpinnedPolicy = Policy::load($providerSite);
    $providerUnpinnedResolved = RepositoryCompiler::resolved_adapters($providerUnpinnedPolicy);
    $providerDigest = (string) ($providerUnpinnedResolved[0]['digest'] ?? '');
    $providerUnpinnedReport = $providerUnpinnedPolicy->capability_report(['operation' => 'promote']);
    $providerUnpinnedRow = $providerUnpinnedReport['manifests'][0] ?? [];
    $providerUnpinnedCodes = array_column($providerUnpinnedReport['blockers'] ?? [], 'code');
    cert_check(
        ($providerUnpinnedPolicy->adapter_sources()->diagnostics($providerUnpinnedPolicy->manifests)['site-plugin']['certification'] ?? null)
            === 'signed_unpinned'
        && in_array('adapter_certification_unpinned', $providerUnpinnedCodes, true)
        && ($providerUnpinnedResolved[0]['capability']['status'] ?? null) === 'uncertified'
        && ($providerUnpinnedRow['source']['trust_tier'] ?? null) === 'plugin_provider',
        'a signed plugin-provider adapter remains signed_unpinned and uncertified before its exact digest pin'
    );

    $providerActions = $providerUnpinnedPolicy->actions_for(['post:page']);
    $GLOBALS['cert_provider_invocations'] = 0;
    $negotiated = Providers::negotiate($providerUnpinnedPolicy, $providerActions);
    cert_check(
        count($providerActions) === 1
        && ($providerActions[0]['provider'] ?? null) === 'site-cache'
        && $negotiated['problems'] === []
        && isset($negotiated['providers']['site-cache'], $negotiated['capabilities']['site-cache']['rebuild_cache'])
        && $GLOBALS['cert_provider_invocations'] === 0,
        'offline negotiation resolves the typed provider action through identity/capabilities without invoking it'
    );
    $providerReceipt = Providers::invoke(
        $negotiated['providers']['site-cache'],
        $providerActions[0],
        $negotiated['capabilities']['site-cache']['rebuild_cache'],
        []
    );
    cert_check(
        $providerReceipt['before'] === 'fixture-before'
        && $providerReceipt['after'] === 'fixture-after'
        && $providerReceipt['verified'] === true
        && isset($providerReceipt['duration_seconds'])
        && $GLOBALS['cert_provider_invocations'] === 1,
        'an explicitly invoked negotiated provider returns the required value-verified receipt'
    );
    // The diagnostic assertions below must prove their own read-only posture,
    // independent of this explicit provider-contract exercise.
    $GLOBALS['cert_provider_invocations'] = 0;

    $providerPlan = PlanSummary::render([
        'adapter_dispositions' => $providerUnpinnedPolicy->adapter_readiness_blockers(),
    ]);
    $providerPlanText = implode("\n", $providerPlan['lines']);
    cert_check(
        $providerPlan['ok'] === false
        && str_contains($providerPlanText, 'site-plugin')
        && str_contains($providerPlanText, 'source=site tier=plugin_provider certification=signed_unpinned')
        && str_contains($providerPlanText, 'adapter_certification_unpinned')
        && str_contains($providerPlanText, 'remediation:'),
        'human status/plan rendering identifies the signed-unpinned provider row, tier, blocker, and remediation'
    );
    WP_CLI::$lines = [];
    (new \Duo\Cli())->capabilities([], ['repo' => $providerSite]);
    $providerHuman = implode("\n", WP_CLI::$lines);
    cert_check(
        str_contains($providerHuman, 'CAPABILITY site-plugin BLOCKED')
        && str_contains($providerHuman, 'trust_tier: plugin_provider')
        && str_contains($providerHuman, 'certification: signed_unpinned')
        && str_contains($providerHuman, 'blocked: adapter_certification_unpinned')
        && str_contains($providerHuman, 'remediation:'),
        'the product human capability renderer exposes signed-unpinned provider readiness and its remediation'
    );
    WP_CLI::$lines = [];
    (new \Duo\Cli())->capabilities([], ['repo' => $providerSite, 'format' => 'json']);
    $providerMachine = json_decode(WP_CLI::$lines[0] ?? '', true);
    cert_check(
        is_array($providerMachine)
        && ($providerMachine['ready'] ?? null) === false
        && in_array('adapter_certification_unpinned', array_column($providerMachine['blockers'] ?? [], 'code'), true)
        && ($providerMachine['manifests'][0]['source']['certification'] ?? null) === 'signed_unpinned'
        && ($providerMachine['manifests'][0]['source']['trust_tier'] ?? null) === 'plugin_provider',
        'the product machine capability renderer preserves signed-unpinned blocker and provider tier fields'
    );

    $providerExactPin = [
        'digest' => $providerDigest,
        'name' => 'site-plugin',
        'source' => 'site',
    ];
    cert_write_canon($providerSite . '/site.duo.json', [
        'manifests' => [$providerExactPin],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    $providerPinnedPolicy = Policy::load($providerSite);
    $providerPinnedResolved = RepositoryCompiler::resolved_adapters($providerPinnedPolicy);
    $providerPinnedReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $providerPinnedRow = $providerPinnedReport['manifests'][0] ?? [];
    cert_check(
        $providerPinnedReport['ready'] === true
        && ($providerPinnedResolved[0]['capability']['status'] ?? null) === 'certified'
        && ($providerPinnedRow['source']['certification'] ?? null) === 'third_party_signed'
        && ($providerPinnedRow['source']['trust_tier'] ?? null) === 'plugin_provider'
        && $providerPinnedPolicy->adapter_readiness_blockers() === []
        && $GLOBALS['cert_provider_invocations'] === 0,
        'the exact site/digest pin elevates the same negotiated plugin-provider row to ready status without invoking it'
    );
    $providerPinnedPlan = PlanSummary::render([
        'adapter_dispositions' => $providerPinnedPolicy->adapter_readiness_blockers(),
    ]);
    cert_check(
        $providerPinnedPlan['ok'] === true
        && !str_contains(implode("\n", $providerPinnedPlan['lines']), 'site-plugin'),
        'human status/plan rendering is clean after the exact provider pin'
    );
    WP_CLI::$lines = [];
    (new \Duo\Cli())->capabilities([], ['repo' => $providerSite]);
    $providerPinnedHuman = implode("\n", WP_CLI::$lines);
    cert_check(
        str_contains($providerPinnedHuman, 'CAPABILITY site-plugin CERTIFIED')
        && str_contains($providerPinnedHuman, 'trust_tier: plugin_provider')
        && str_contains($providerPinnedHuman, 'certification: third_party_signed')
        && !str_contains($providerPinnedHuman, 'blocked: adapter_certification_unpinned'),
        'the product human capability renderer reports the exact plugin-provider pin as certified'
    );
    WP_CLI::$lines = [];
    (new \Duo\Cli())->capabilities([], ['repo' => $providerSite, 'format' => 'json']);
    $providerPinnedMachine = json_decode(WP_CLI::$lines[0] ?? '', true);
    cert_check(
        is_array($providerPinnedMachine)
        && ($providerPinnedMachine['ready'] ?? null) === true
        && ($providerPinnedMachine['blockers'] ?? []) === []
        && ($providerPinnedMachine['manifests'][0]['verdict']['status'] ?? null) === 'certified'
        && ($providerPinnedMachine['manifests'][0]['source']['certification'] ?? null) === 'third_party_signed',
        'the product machine capability renderer reports ready with no blockers after the exact pin'
    );

    echo "\n== signed plugin-provider runtime readiness diagnostics ==\n";
    // Capability reports deliberately test every declared provider action;
    // status/plan rows deliberately test only the actions selected by their
    // current canonical surfaces. This fixture's one action is post:page.
    $providerPlanDispositions = static function (Policy $policy, array $surfaces): array {
        return array_merge(
            $policy->certification_readiness_blockers(),
            $policy->provider_readiness_blockers($policy->actions_for($surfaces))
        );
    };
    $hasProviderDiagnosticFields = static function (?array $blocker, string $code): bool {
        return is_array($blocker)
            && ($blocker['name'] ?? null) === 'site-plugin'
            && ($blocker['status'] ?? null) === 'blocked'
            && ($blocker['code'] ?? null) === $code
            && ($blocker['provider'] ?? null) === 'site-cache'
            && ($blocker['manifest'] ?? null) === 'site-plugin'
            && ($blocker['plugin'] ?? null) === 'acme/acme.php'
            && trim((string) ($blocker['expected'] ?? '')) !== ''
            && trim((string) ($blocker['found'] ?? '')) !== ''
            && trim((string) ($blocker['remediation'] ?? '')) !== ''
            && ($blocker['source'] ?? null) === 'site'
            && ($blocker['trust_tier'] ?? null) === 'plugin_provider'
            && ($blocker['certification'] ?? null) === 'third_party_signed';
    };
    $providerDiagnosticsAreSecretFree = static function (
        array $report,
        array $planRows,
        string $requiredCode
    ) use ($providerSite): bool {
        $reportJson = json_encode($report, JSON_THROW_ON_ERROR);
        $planJson = json_encode($planRows, JSON_THROW_ON_ERROR);
        $status = PlanSummary::render(['adapter_dispositions' => $planRows]);
        $statusText = implode("\n", $status['lines']);
        WP_CLI::$lines = [];
        (new \Duo\Cli())->capabilities([], ['repo' => $providerSite]);
        $cliText = implode("\n", WP_CLI::$lines);
        foreach ([$reportJson, $planJson, $statusText, $cliText] as $public) {
            if (str_contains($public, 'DUO_PROVIDER_SECRET_TOKEN')
                || str_contains($public, 'INJECTED_PROVIDER_LINE')) {
                return false;
            }
        }
        foreach (array_merge((array) ($report['blockers'] ?? []), $planRows) as $row) {
            if (is_array($row) && str_contains((string) ($row['found'] ?? ''), "\n")) {
                return false;
            }
        }
        return $status['ok'] === false
            && str_contains($statusText, $requiredCode)
            && str_contains($cliText, $requiredCode);
    };
    $validProviderRegistration = $GLOBALS['cert_plugin_providers'];

    // A signed, exact-pinned claim proves reviewed adapter bytes, not that its
    // plugin registered a usable provider in this runtime. The capability
    // report has no active surface input, yet must still reject this missing
    // declared action; the plan-facing rows reject it only for post:page.
    $GLOBALS['cert_plugin_providers'] = [];
    $GLOBALS['cert_provider_invocations'] = 0;
    $missingProviderReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $missingProviderBlocker = cert_provider_blocker(
        (array) ($missingProviderReport['blockers'] ?? []),
        'missing_plugin_provider'
    );
    $missingProviderRowReason = cert_provider_blocker(
        (array) (($missingProviderReport['manifests'][0]['verdict']['reasons'] ?? [])),
        'missing_plugin_provider'
    );
    $missingProviderPlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    $missingProviderPlanBlocker = cert_provider_blocker($missingProviderPlanRows, 'missing_plugin_provider');
    $missingProviderStatus = PlanSummary::render(['adapter_dispositions' => $missingProviderPlanRows]);
    $missingProviderStatusText = implode("\n", $missingProviderStatus['lines']);
    cert_check(
        ($missingProviderReport['ready'] ?? null) === false
        && ($missingProviderReport['manifests'][0]['verdict']['status'] ?? null) === 'blocked'
        && $hasProviderDiagnosticFields($missingProviderBlocker, 'missing_plugin_provider')
        && is_array($missingProviderRowReason)
        && ($missingProviderRowReason['provider'] ?? null) === 'site-cache'
        && ($missingProviderRowReason['plugin'] ?? null) === 'acme/acme.php'
        && trim((string) ($missingProviderRowReason['remediation'] ?? '')) !== '',
        'capability JSON blocks an exact signed provider whose required registration is missing, with structured responsibility and remediation'
    );
    cert_check(
        $hasProviderDiagnosticFields($missingProviderPlanBlocker, 'missing_plugin_provider')
        && $missingProviderStatus['ok'] === false
        && str_contains($missingProviderStatusText, 'site-cache')
        && str_contains($missingProviderStatusText, 'acme/acme.php')
        && str_contains($missingProviderStatusText, 'source=site tier=plugin_provider certification=third_party_signed')
        && str_contains($missingProviderStatusText, 'remediation:'),
        'selected plan/status adapter_dispositions expose the missing provider, plugin, signed source/tier/certification, and remediation'
    );
    cert_check(
        $providerPlanDispositions($providerPinnedPolicy, ['post:post']) === []
        && $GLOBALS['cert_provider_invocations'] === 0,
        'an unrelated plan surface does not inherit a global provider blocker, and diagnostics invoked no provider action'
    );

    $GLOBALS['cert_plugin_providers'] = [new \DuoCertificationWrongRegistrationProvider()];
    $GLOBALS['cert_provider_invocations'] = 0;
    $wrongRegistrationReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $wrongRegistrationPlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    cert_check(
        ($wrongRegistrationReport['ready'] ?? null) === false
        && $hasProviderDiagnosticFields(
            cert_provider_blocker((array) ($wrongRegistrationReport['blockers'] ?? []), 'identity_mismatch'),
            'identity_mismatch'
        )
        && $hasProviderDiagnosticFields(
            cert_provider_blocker($wrongRegistrationPlanRows, 'identity_mismatch'),
            'identity_mismatch'
        )
        && $GLOBALS['cert_provider_invocations'] === 0,
        'wrong provider registration identity blocks global capability JSON and the selected plan without invoking it'
    );

    $GLOBALS['cert_plugin_providers'] = [new \DuoCertificationMissingCapabilityProvider()];
    $GLOBALS['cert_provider_invocations'] = 0;
    $missingCapabilityReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $missingCapabilityPlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    cert_check(
        ($missingCapabilityReport['ready'] ?? null) === false
        && $hasProviderDiagnosticFields(
            cert_provider_blocker((array) ($missingCapabilityReport['blockers'] ?? []), 'missing_capability'),
            'missing_capability'
        )
        && $hasProviderDiagnosticFields(
            cert_provider_blocker($missingCapabilityPlanRows, 'missing_capability'),
            'missing_capability'
        )
        && $GLOBALS['cert_provider_invocations'] === 0,
        'missing advertised capability blocks global capability JSON and the selected plan without invoking it'
    );

    $GLOBALS['cert_plugin_providers'] = [new \DuoCertificationThrowingCapabilitiesProvider()];
    $GLOBALS['cert_provider_invocations'] = 0;
    $throwingCapabilitiesReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $throwingCapabilitiesPlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    $throwingCapabilitiesBlocker = cert_provider_blocker(
        (array) ($throwingCapabilitiesReport['blockers'] ?? []),
        'contract_shape'
    );
    cert_check(
        ($throwingCapabilitiesReport['ready'] ?? null) === false
        && $hasProviderDiagnosticFields($throwingCapabilitiesBlocker, 'contract_shape')
        && (($throwingCapabilitiesBlocker['expected'] ?? null) === 'capabilities() returning a name => declaration map')
        && (($throwingCapabilitiesBlocker['found'] ?? null) === 'capabilities() threw')
        && $hasProviderDiagnosticFields(
            cert_provider_blocker($throwingCapabilitiesPlanRows, 'contract_shape'),
            'contract_shape'
        )
        && $providerDiagnosticsAreSecretFree(
            $throwingCapabilitiesReport,
            $throwingCapabilitiesPlanRows,
            'contract_shape'
        )
        && $GLOBALS['cert_provider_invocations'] === 0,
        'a capabilities()-throwing provider remains a structured, redacted global/selected-plan blocker without invoking it'
    );

    $GLOBALS['cert_plugin_providers'] = [new \DuoCertificationThrowingIdentityProvider()];
    $GLOBALS['cert_provider_invocations'] = 0;
    $throwingIdentityReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $throwingIdentityPlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    $throwingIdentityBlocker = cert_provider_blocker(
        (array) ($throwingIdentityReport['blockers'] ?? []),
        'missing_plugin_provider'
    );
    cert_check(
        ($throwingIdentityReport['ready'] ?? null) === false
        && $hasProviderDiagnosticFields($throwingIdentityBlocker, 'missing_plugin_provider')
        && (($throwingIdentityBlocker['found'] ?? null) === 'no registered provider matched the declared identity')
        && $hasProviderDiagnosticFields(
            cert_provider_blocker($throwingIdentityPlanRows, 'missing_plugin_provider'),
            'missing_plugin_provider'
        )
        && $providerDiagnosticsAreSecretFree(
            $throwingIdentityReport,
            $throwingIdentityPlanRows,
            'missing_plugin_provider'
        )
        && $GLOBALS['cert_provider_invocations'] === 0,
        'an identity()-throwing plugin registration remains a structured, redacted global/selected-plan blocker without invoking it'
    );

    $GLOBALS['cert_plugin_providers'] = [new \DuoCertificationMalformedIdentityProvider()];
    $GLOBALS['cert_provider_invocations'] = 0;
    $malformedIdentityReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $malformedIdentityPlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    $malformedIdentityBlocker = cert_provider_blocker(
        (array) ($malformedIdentityReport['blockers'] ?? []),
        'missing_plugin_provider'
    );
    cert_check(
        ($malformedIdentityReport['ready'] ?? null) === false
        && $hasProviderDiagnosticFields($malformedIdentityBlocker, 'missing_plugin_provider')
        && (($malformedIdentityBlocker['found'] ?? null) === 'no registered provider matched the declared identity')
        && $hasProviderDiagnosticFields(
            cert_provider_blocker($malformedIdentityPlanRows, 'missing_plugin_provider'),
            'missing_plugin_provider'
        )
        && $providerDiagnosticsAreSecretFree(
            $malformedIdentityReport,
            $malformedIdentityPlanRows,
            'missing_plugin_provider'
        )
        && $GLOBALS['cert_provider_invocations'] === 0,
        'a malformed Stringable plugin registration id is skipped without a fatal or public payload leak'
    );

    $GLOBALS['cert_plugin_registry_throw'] = "https://provider.example.test/registry?access_token=DUO_PROVIDER_SECRET_TOKEN\nINJECTED_PROVIDER_LINE";
    $GLOBALS['cert_provider_invocations'] = 0;
    $registryFailureReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $registryFailurePlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    $registryFailureBlocker = cert_provider_blocker(
        (array) ($registryFailureReport['blockers'] ?? []),
        'provider_registry_unavailable'
    );
    cert_check(
        ($registryFailureReport['ready'] ?? null) === false
        && $hasProviderDiagnosticFields($registryFailureBlocker, 'provider_registry_unavailable')
        && (($registryFailureBlocker['expected'] ?? null) === 'a readable `duo_providers` registry')
        && (($registryFailureBlocker['found'] ?? null) === 'provider registry callback failed')
        && $hasProviderDiagnosticFields(
            cert_provider_blocker($registryFailurePlanRows, 'provider_registry_unavailable'),
            'provider_registry_unavailable'
        )
        && $providerDiagnosticsAreSecretFree(
            $registryFailureReport,
            $registryFailurePlanRows,
            'provider_registry_unavailable'
        )
        && $GLOBALS['cert_provider_invocations'] === 0,
        'a throwing duo_providers registry remains a structured, redacted global/selected-plan/status/CLI blocker without invoking it'
    );

    $GLOBALS['cert_plugin_registry_throw'] = null;
    $GLOBALS['cert_plugin_providers'] = $validProviderRegistration;
    $GLOBALS['cert_provider_invocations'] = 0;
    $validProviderReport = $providerPinnedPolicy->capability_report(['operation' => 'promote']);
    $validProviderPlanRows = $providerPlanDispositions($providerPinnedPolicy, ['post:page']);
    $validProviderStatus = PlanSummary::render(['adapter_dispositions' => $validProviderPlanRows]);
    cert_check(
        ($validProviderReport['ready'] ?? null) === true
        && ($validProviderReport['blockers'] ?? []) === []
        && $validProviderPlanRows === []
        && $validProviderStatus['ok'] === true
        && $GLOBALS['cert_provider_invocations'] === 0,
        'a valid negotiated provider remains ready globally and for its selected plan, without running an action'
    );

    cert_write_canon($site . '/site.duo.json', [
        'manifests' => [['name' => 'site-demo', 'source' => 'site']],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    $unpinnedPolicy = Policy::load($site);
    $unpinnedResolved = RepositoryCompiler::resolved_adapters($unpinnedPolicy);
    $certifiedDigest = (string) ($unpinnedResolved[0]['digest'] ?? '');
    $unpinnedReport = $unpinnedPolicy->capability_report(['operation' => 'promote']);
    $unpinnedCodes = array_column($unpinnedReport['blockers'] ?? [], 'code');
    cert_check(
        ($unpinnedPolicy->adapter_sources()->diagnostics($unpinnedPolicy->manifests)['site-demo']['certification'] ?? null)
            === 'signed_unpinned'
        && in_array('adapter_certification_unpinned', $unpinnedCodes, true)
        && ($unpinnedResolved[0]['capability']['status'] ?? null) === 'uncertified',
        'a valid signature remains signed_unpinned and host-bound capability stays uncertified before an exact digest pin'
    );
    cert_check(
        CodeDeploy::dispositionBlockers(['resolved_adapters' => $unpinnedResolved]) !== [],
        'host promotion refuses the signed-but-unpinned compiled adapter'
    );

    cert_write_canon($site . '/site.duo.json', [
        'manifests' => [[
            'digest' => $certifiedDigest,
            'name' => 'site-demo',
            'source' => 'shipped',
        ]],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    cert_expect_throw(
        static fn() => Policy::load($site),
        'pinned to the shipped adapter source but resolves from the site source',
        'a signed adapter pin cannot lie about its source'
    );
    cert_write_canon($site . '/site.duo.json', [
        'manifests' => [[
            'digest' => str_repeat('0', 64),
            'name' => 'site-demo',
            'source' => 'site',
        ]],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    cert_expect_throw(
        static fn() => Policy::load($site),
        'digest mismatch',
        'a signed adapter pin cannot elevate a different final digest'
    );

    $exactPin = ['digest' => $certifiedDigest, 'name' => 'site-demo', 'source' => 'site'];
    cert_write_canon($site . '/site.duo.json', [
        'manifests' => [$exactPin],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    $pinnedPolicy = Policy::load($site);
    $pinnedResolved = RepositoryCompiler::resolved_adapters($pinnedPolicy);
    $pinnedReport = $pinnedPolicy->capability_report(['operation' => 'promote']);
    cert_check(
        $pinnedReport['ready'] === true
        && ($pinnedResolved[0]['capability']['status'] ?? null) === 'certified'
        && ($pinnedPolicy->adapter_sources()->diagnostics($pinnedPolicy->manifests)['site-demo']['certification'] ?? null)
            === 'third_party_signed',
        'the exact {name,source:site,digest} pin elevates only that signed adapter to certified readiness'
    );
    $loadInitSelection = new ReflectionMethod(InitPlanner::class, 'load_selected_policy');
    [$initPolicy, $initPins] = $loadInitSelection->invoke(null, ['site-demo'], $site);
    $initReport = $initPolicy->capability_report(['operation' => 'capture']);
    cert_check(
        $initPins === [$exactPin]
        && ($initReport['ready'] ?? null) === true
        && ($initReport['blockers'] ?? null) === []
        && ($initPolicy->adapter_sources()->diagnostics($initPolicy->manifests)['site-demo']['certification'] ?? null)
            === 'third_party_signed',
        'init turns signed discovery into the exact source/digest pin used for capture readiness and its generated config'
    );
    cert_check(
        CodeDeploy::dispositionBlockers(['resolved_adapters' => $pinnedResolved]) === [],
        'host promotion accepts the exact pinned current external claim'
    );

    // ==================================================================
    echo "\n== DUO-3339: a signed adapter's VERSION story is reportable (the #168 gap) ==\n";
    // ==================================================================
    // The reviewed dispositions name the SHIPPED subset only, so a name-keyed
    // claim lookup answers null for every out-of-tree row, and
    // `duo adapter inspect` printed "registry claim: (none)" for an adapter
    // carrying a complete, verified signed envelope. survey() now carries that
    // envelope's own facts on the row instead. Everything asserted here is
    // PROJECTED, never recomputed: if any of it could drift from the signed
    // statement it would be a second, unsigned copy of the same claim.
    $signedSurvey = AdapterSources::survey($site);
    $signedRow = null;
    foreach ($signedSurvey['adapters'] as $surveyRow) {
        if (($surveyRow['name'] ?? null) === 'site-demo') {
            $signedRow = $surveyRow;
        }
    }
    $signedEvidence = $signedRow['certification_evidence'] ?? null;
    $signedProof = $verified['disposition']['provenance']['proof'];
    cert_check(
        is_array($signedEvidence)
        && ($signedEvidence['certificate_sha256'] ?? null) === $signedProof['certificate_sha256']
        && ($signedEvidence['statement_sha256'] ?? null) === $signedProof['statement_sha256']
        && ($signedEvidence['platform_sha256'] ?? null) === $signedProof['platform_sha256']
        && ($signedEvidence['authority']['key_id'] ?? null) === 'review-key'
        && ($signedEvidence['authority']['fingerprint'] ?? null) === $signedProof['authority']['fingerprint'],
        'a signed, pinned site adapter reports its authority and the exact certificate/statement/platform digests '
        . 'the signature covers — the facts that used to be invisible'
    );
    cert_check(
        ($signedEvidence['bundle']['digest'] ?? null) === $signedProof['bundle']['digest']
        && ($signedEvidence['bundle']['git_revision'] ?? null) === $signedProof['bundle']['git_revision']
        && ($signedEvidence['bundle']['schema'] ?? null) === $signedProof['bundle']['schema']
        && ($signedEvidence['bundle']['tests'] ?? null) === ['site-conformance'],
        'and its evidence bundle, the git revision it was produced at, and the named tests it cites'
    );
    cert_check(
        is_array($signedEvidence['artifacts'] ?? null)
        && count($signedEvidence['artifacts']) === 1
        && ($signedEvidence['artifacts'][0]['name'] ?? null) === 'site-demo-boundary'
        && ($signedEvidence['artifacts'][0]['version'] ?? null) === '1.2.3'
        && ($signedEvidence['artifacts'][0]['role'] ?? null) === 'certified-boundary',
        'and artifacts[] — WHAT was exercised and at WHICH version — which only exists inside the certificate, so '
        . 'the retained envelope really is decoded rather than the row guessing from the disposition'
    );
    cert_check(
        ($signedEvidence['supported_versions'] ?? null)
            === ($verified['claim']['supported_versions'] ?? '(claim absent)'),
        'and the supported_versions the signed ratification forced to equal the manifest\'s own, byte for byte '
        . 'from the verified claim'
    );
    $unsignedRow = null;
    foreach (AdapterSources::survey(null)['adapters'] as $shippedRow) {
        if (($shippedRow['source'] ?? null) === 'shipped') {
            $unsignedRow = $shippedRow;
            break;
        }
    }
    cert_check(
        is_array($unsignedRow) && array_key_exists('certification_evidence', $unsignedRow)
        && $unsignedRow['certification_evidence'] === null,
        'while a row with no signed envelope carries the key with an explicit null — absent and "no evidence" '
        . 'must not read the same to a consumer'
    );
    $tamperedEnvelope = $verified['envelope'];
    $tamperedEnvelope['certificate_json'] = base64_encode('not a certificate');
    $unreadable = (new ReflectionMethod(AdapterSources::class, 'certification_evidence'))->invoke(
        null,
        $verified['disposition'],
        $tamperedEnvelope,
        $verified['claim']
    );
    cert_check(
        is_array($unreadable) && array_key_exists('artifacts', $unreadable)
        && $unreadable['artifacts'] === null,
        'and an envelope whose artifact list cannot be decoded reports null rather than an empty list — "nothing '
        . 'was exercised" and "nobody could read what was exercised" are different answers'
    );
    // End to end through the REAL host command, because the projection is only
    // worth having if the surface an operator actually runs prints it.
    $inspectRun = cert_run([
        PHP_BINARY,
        dirname(__DIR__, 4) . '/cli/duo',
        'adapter',
        'inspect',
        'site-demo',
        '--repo=' . $site,
    ]);
    cert_check(
        str_contains($inspectRun['stdout'], 'signed certification evidence')
        && str_contains($inspectRun['stdout'], 'site-demo-boundary v1.2.3 [certified-boundary]')
        && str_contains($inspectRun['stdout'], $signedProof['certificate_sha256'])
        && str_contains($inspectRun['stdout'], 'site-conformance')
        && str_contains(
            $inspectRun['stdout'],
            'registry claim:    (none — a non-shipped adapter never has a shipped reviewed claim; its own '
            . 'signed certification evidence is reported below)'
        ),
        '`duo adapter inspect` renders that evidence block for the signed site adapter, and the absent shipped '
        . 'registry claim beside it now says WHY it is absent and where the real evidence is — it used to print a '
        . 'bare "(none)" and stop, which read as "nothing is known" (exit ' . $inspectRun['exit'] . ')'
    );

    $policySnapshot = $pinnedPolicy->export_snapshot();
    $frozenPolicy = Policy::from_snapshot($policySnapshot);
    cert_check(
        RepositoryCompiler::resolved_adapters($frozenPolicy) === $pinnedResolved,
        'a frozen policy snapshot re-verifies the certificate and reconstructs the exact source/digest/capability row'
    );
    $missingFrozenCertificate = $policySnapshot;
    unset($missingFrozenCertificate['adapter_sources']['certificates']['site-demo']);
    cert_expect_throw(
        static fn() => Policy::from_snapshot($missingFrozenCertificate),
        'malformed',
        'a frozen certified disposition cannot survive deletion of its certificate envelope'
    );

    // ==================================================================
    echo "\n== DUO-3339/B2: a plugin-bundled adapter cannot hold this certification ==\n";
    // ==================================================================
    // Three hard bindings make it impossible, and all three are inside the
    // SIGNED statement rather than beside it: certificatePath() derives
    // <repo>/adapters/certifications/<name>.json, verifyFile() opens
    // adapters/<name>.json to hash, and assertAdapterBinding() requires
    // adapter.source === "site" with adapter.path === "adapters/<name>.json".
    // So the read side refuses the pairing rather than waiting for a signature
    // check that could never have produced it.
    $pluginFrozen = $policySnapshot;
    $pluginFrozen['adapter_sources']['out_of_tree']['site-demo']['provenance']['source'] = 'plugin';
    $pluginFrozen['adapter_sources']['out_of_tree']['site-demo']['provenance']['path']
        = 'plugins/site-demo/duo-adapter.json';
    cert_expect_throw(
        static fn() => Policy::from_snapshot($pluginFrozen),
        'bundled by a plugin and cannot carry a certificate',
        'a frozen plugin-sourced record paired with a certificate is refused, naming the impossibility'
    );

    // The SAME record without the certificate is refused too, for a different
    // and equally specific reason: its path is re-derived from the frozen
    // manifest's own `plugin` claim, which this manifest does not make.
    $pluginFrozenUnsigned = $pluginFrozen;
    unset($pluginFrozenUnsigned['adapter_sources']['certificates']['site-demo']);
    $pluginFrozenUnsigned['adapter_sources']['out_of_tree']['site-demo'] = [
        'certification' => 'uncertified',
        'provenance' => [
            'format' => 'duo-adapter-sources/v2',
            'path' => 'plugins/site-demo/duo-adapter.json',
            'sha256' => hash('sha256', Canon::encode($manifest)),
            'source' => 'plugin',
        ],
        'reason' => 'fixture',
        'status' => 'uncertified',
        'trust_tier' => 'declarative_manifest',
    ];
    cert_expect_throw(
        static fn() => Policy::from_snapshot($pluginFrozenUnsigned),
        'plugin basename',
        'and a frozen bundled record whose manifest declares no owning plugin has no derivable path at all'
    );

    // ==================================================================
    echo "\n== DUO-3339/B2: the promotion path completes with the bundling plugin ACTIVE ==\n";
    // ==================================================================
    // Amendment A's whole justification, executed: a plugin bundles an adapter
    // under the SAME name as the certified repository package. Precedence
    // ranks the site source above the plugin one, so the reviewed, signed
    // definition wins, the bundled one reports as installed-but-not-loaded,
    // and nothing has to be deactivated for the certified adapter to stay
    // certified. Under whole-scan refusal this repository would instead have
    // lost every command the moment that plugin updated.
    //
    // WP_PLUGIN_DIR is a define(), so this runs in a clean child: making the
    // parent process a plugin-scanning one would silently change every check
    // above it.
    $b2Plugins = $root . '/b2-plugins';
    cert_write($b2Plugins . '/acme/acme.php', "<?php\n// fixture plugin\n");
    cert_write_canon($b2Plugins . '/acme/duo-adapter.json', [
        'name' => 'site-demo',
        'option_autoload' => 'preserve',
        'plugin' => 'acme/acme.php',
        'post_types' => [],
        'spec_version' => DUO_SPEC_VERSION,
        'tables' => [],
    ]);
    $b2Script = str_replace(
        ['__ABSPATH__', '__WP_PLUGIN_DIR__', '__MANIFESTS__', '__SITE__', '__ENGINE_ROOT__'],
        [
            var_export($root . '/', true),
            var_export($b2Plugins, true),
            var_export($integrationManifests, true),
            var_export($site, true),
            var_export(dirname(__DIR__, 4), true),
        ],
        <<<'PHP'
<?php
declare(strict_types=1);
define('DUO_SPEC_VERSION', 2);
define('DUO_AGENT_VERSION', '0.5.0');
define('ABSPATH', __ABSPATH__);
define('WP_PLUGIN_DIR', __WP_PLUGIN_DIR__);
function is_multisite(): bool { return false; }
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'active_plugins' ? ['acme/acme.php'] : $default;
}
putenv('DUO_MANIFESTS_DIR=' . __MANIFESTS__);
require __ENGINE_ROOT__ . '/agent/src/Kernel/Canon.php';
require __ENGINE_ROOT__ . '/agent/src/Kernel/OptionState.php';
require __ENGINE_ROOT__ . '/agent/src/Policy/ManifestDispositions.php';
require __ENGINE_ROOT__ . '/agent/src/Policy/Policy.php';
require __ENGINE_ROOT__ . '/agent/src/Repository/Ledger.php';
require __ENGINE_ROOT__ . '/agent/src/Repository/RepositoryCompiler.php';
$payload = [];
try {
    $policy = \Duo\Policy::load(__SITE__);
    $sources = $policy->adapter_sources();
    $payload['source'] = $sources->source('site-demo');
    $payload['path'] = $sources->path('site-demo');
    $payload['certification'] = $sources->diagnostics($policy->manifests)['site-demo']['certification'] ?? null;
    $payload['ready'] = $policy->capability_report(['operation' => 'promote'])['ready'] ?? null;
    $payload['digest'] = \Duo\RepositoryCompiler::resolved_adapters($policy)[0]['digest'] ?? null;
    $payload['not_installed'] = $sources->not_installed();
    $payload['plugin_refusals'] = $sources->plugin_refusals();
    $survey = \Duo\AdapterSources::survey(__SITE__);
    $payload['survey_not_installed'] = $survey['not_installed'];
    $payload['survey_sources'] = $survey['sources'];
} catch (\Throwable $failure) {
    $payload['error'] = $failure->getMessage();
}
echo json_encode($payload, JSON_THROW_ON_ERROR);
PHP
    );
    cert_write($root . '/b2-promotion.php', $b2Script);
    $b2Run = cert_run([PHP_BINARY, $root . '/b2-promotion.php']);
    $b2 = json_decode($b2Run['stdout'], true);
    cert_check(
        is_array($b2) && !isset($b2['error'])
        && ($b2['source'] ?? null) === 'site'
        && ($b2['path'] ?? null) === 'adapters/site-demo.json'
        && ($b2['certification'] ?? null) === 'third_party_signed'
        && ($b2['ready'] ?? null) === true
        && ($b2['digest'] ?? null) === $certifiedDigest,
        'the certified, exactly pinned SITE adapter still wins, stays third_party_signed, and keeps its exact '
        . 'digest while an active plugin bundles the same name (' . trim((string) ($b2['error'] ?? '')) . ')'
    );
    $b2Shadow = ($b2['not_installed'] ?? [])[0] ?? [];
    cert_check(
        count($b2['not_installed'] ?? []) === 1
        && ($b2Shadow['reason_code'] ?? null) === 'shadowed'
        && ($b2Shadow['name'] ?? null) === 'site-demo'
        && ($b2Shadow['path'] ?? null) === 'plugins/acme/duo-adapter.json'
        && ($b2Shadow['winner']['source'] ?? null) === 'site'
        && ($b2Shadow['winner']['path'] ?? null) === 'adapters/site-demo.json'
        && ($b2['plugin_refusals'] ?? null) === [],
        'the bundled copy is REPORTED as installed-but-not-loaded naming its winner — not refused, so the plugin '
        . 'stays active and no other command breaks'
    );
    cert_check(
        ($b2['survey_not_installed'] ?? null) === ($b2['not_installed'] ?? null)
        && in_array('plugin', array_column((array) ($b2['survey_sources'] ?? []), 'source'), true),
        'and discover() and survey() agree about it row for row, from the one scan'
    );

    cert_write_canon($site . '/site.duo.json', [
        'manifests' => ['core', $exactPin],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    $mixedPolicy = Policy::load($site);
    $mixedReport = $mixedPolicy->capability_report(['operation' => 'promote']);
    $mixedRows = [];
    foreach ($mixedReport['manifests'] as $row) {
        $mixedRows[$row['name']] = $row;
    }
    // Each row's evidence comes from its own authority and from no other. The
    // fixture used to prove this by BLOCKING the shipped rows — the copied
    // registry's evidence was neutralised to `candidate` and only the site row
    // survived. There is no generated evidence to neutralise now, so the
    // isolation is asserted the direct way: two rows, two different scopes,
    // two different documents, and neither one's citation appearing on the
    // other. Both are certified, which is the harder case — a contaminating
    // read would go unnoticed if one of them were failing anyway.
    $shippedCoreCitation = Canon::decode(
        Canon::read_file($integrationManifests . '/dispositions.json')
    )['manifests']['core']['evidence'] ?? null;
    cert_check(
        ($mixedReport['evidence_scope'] ?? null) === 'per_subject'
        && $mixedReport['evidence'] === null
        && ($mixedRows['site-demo']['evidence_scope'] ?? null) === 'site_certificate'
        && ($mixedRows['site-demo']['verdict']['status'] ?? null) === 'certified'
        && ($mixedRows['core']['evidence_scope'] ?? null) === 'authored_disposition'
        && ($mixedRows['core']['verdict']['status'] ?? null) === 'certified'
        && is_array($shippedCoreCitation)
        && ($mixedRows['core']['evidence'] ?? null) === $shippedCoreCitation
        && ($mixedRows['site-demo']['evidence'] ?? null) !== $shippedCoreCitation
        && ($mixedRows['site-demo']['evidence']['bundle_schema'] ?? null)
            === AdapterCertification::BUNDLE_FORMAT,
        'mixed reporting gives the signed site row its own certificate evidence and the shipped row its own '
        . 'authored citation — different scopes, different bundle schemas, and neither borrows the other'
    );
    cert_check(
        array_key_exists('platform', $mixedReport) && $mixedReport['platform'] === null,
        'and the report publishes no aggregate platform authority once a row answers from a source of its own'
    );
    $shippedOnly = Policy::load(null, ['core']);
    $shippedOnlyResolved = RepositoryCompiler::resolved_adapters($shippedOnly);
    $mixedResolved = RepositoryCompiler::resolved_adapters($mixedPolicy);
    cert_check(
        ($mixedResolved[0]['digest'] ?? null) === ($shippedOnlyResolved[0]['digest'] ?? null)
        && ($shippedOnly->capability_report(['operation' => 'promote'])['evidence_scope'] ?? null) === 'per_subject',
        'installing a certified site adapter leaves the shipped core digest and shipped-only report shape unchanged'
    );

    $updatedBundle = $root . '/updated-bundle';
    cert_write_bundle($updatedBundle, $site, $ratification, ['git_revision' => str_repeat('b', 40)]);
    $updatedCertificate = AdapterCertification::sign(
        $integrationManifests,
        $site,
        'site-demo',
        $updatedBundle,
        $site,
        'review-key',
        base64_encode($secret)
    );
    cert_write($certPath, $updatedCertificate);
    cert_write_canon($site . '/site.duo.json', [
        'manifests' => [$exactPin],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    cert_expect_throw(
        static fn() => Policy::load($site),
        'digest mismatch',
        'changing and re-signing the evidence bundle invalidates the prior explicit adapter pin'
    );
    cert_write_canon($site . '/site.duo.json', [
        'manifests' => [['name' => 'site-demo', 'source' => 'site']],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    $updatedDigest = RepositoryCompiler::resolved_adapters(Policy::load($site))[0]['digest'] ?? null;
    cert_check(
        is_string($updatedDigest) && $updatedDigest !== $certifiedDigest,
        'a new signed statement/envelope/evidence proof produces a new final adapter digest'
    );

    // ==================================================================
    echo "\n== WP-1.1: a moved agent boundary WITHDRAWS one claim, never the source ==\n";
    // ==================================================================
    // `manifests/capabilities/platform.json` is AGENT-owned and moves on an
    // ordinary upgrade, and every signed statement binds its exact bytes
    // (AdapterCertification::verifyCertificate). Before WP-1.1 that comparison
    // threw a bare \RuntimeException; scan_site_source() could not tell it from
    // a forgery, so discover() refused the WHOLE site source and Policy::load()
    // propagated it uncaught (Policy.php:400). One upgrade therefore took every
    // command on every site holding a certified adapter — including the `duo
    // adapter certify --pin` that is the only way back — for a condition no
    // site caused. Cases (d) and (e) below are what keep the remedy from being
    // the fallback rule 9 forbids: the line is drawn at TWO named typed
    // exceptions, and everything else still refuses.
    cert_write($certPath, $originalCertificateRaw);
    cert_write_canon($site . '/site.duo.json', [
        'manifests' => ['core', $exactPin],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    $currentPolicy = Policy::load($site);
    $currentResolved = RepositoryCompiler::resolved_adapters($currentPolicy);
    $currentCoreDigest = (string) ($currentResolved[0]['digest'] ?? '');
    // Captured while the boundary still agrees, because that is the artifact a
    // promoted site is actually holding when the agent under it moves.
    $currentSnapshot = $currentPolicy->export_snapshot();
    $currentEnvelope = $currentSnapshot['adapter_sources']['certificates']['site-demo'] ?? [];
    cert_check(
        $currentPolicy->adapter_sources()->is_certified('site-demo')
        && ($currentResolved[1]['digest'] ?? null) === $certifiedDigest
        && $currentCoreDigest !== '',
        'baseline: the certified, exactly-pinned site adapter and an unrelated shipped adapter load in one pin set'
    );

    // ONE byte of the agent-owned boundary, moved the way an upgrade moves it —
    // a newly verified WordPress release. agent_version/spec_version are left
    // alone so currentPlatform()'s own agreement checks still pass and the only
    // thing that differs is the byte comparison the signature covers.
    $stalePlatform = $platform;
    $stalePlatform['compatibility']['wordpress']['last_verified'] = '7.1.0';
    cert_write_canon($integrationManifests . '/capabilities/platform.json', [
        'format' => ManifestDispositions::PLATFORM_FORMAT,
        'platform' => $stalePlatform,
    ]);

    $degradedPolicy = null;
    try {
        $degradedPolicy = Policy::load($site);
        cert_check(
            true,
            '(a) a moved platform boundary no longer refuses the whole source — Policy::load() completes, and the '
            . 'now-stale exact digest pin rides the site-pin concession (PinResolver.php:187-190) instead of '
            . 'stranding the load'
        );
    } catch (Throwable $e) {
        cert_check(
            false,
            '(a) a moved platform boundary no longer refuses the whole source — Policy::load() completes ('
            . $e->getMessage() . ')'
        );
    }
    $degradedResolved = [];
    if ($degradedPolicy instanceof Policy) {
        $degradedSources = $degradedPolicy->adapter_sources();
        $degradedReason = (string) ($degradedSources->provenance('site-demo')['reason'] ?? '');
        $degradedResolved = RepositoryCompiler::resolved_adapters($degradedPolicy);
        cert_check(
            !$degradedSources->is_certified('site-demo')
            && ($degradedSources->diagnostics($degradedPolicy->manifests)['site-demo']['certification'] ?? null)
                === 'uncertified'
            // capability === null, not `['status' => 'uncertified']`: that
            // second shape is `signed_unpinned`, where a claim exists and is
            // merely not elevated. A withdrawn claim leaves NO claim at all —
            // byte-identical to the companion-absent site adapter, which is the
            // whole point of routing to the same record.
            && array_key_exists('capability', $degradedResolved[1] ?? [])
            && $degradedResolved[1]['capability'] === null
            && str_contains($degradedReason, 'agent platform boundary this agent no longer publishes'),
            '(b) the adapter resolves uncertified and its reason NAMES the stale boundary — an operator reading '
            . '"carries no reviewed certification evidence" would go looking for a missing companion that is '
            . 'sitting right there (' . $degradedReason . ')'
        );
        cert_check(
            ($degradedResolved[0]['digest'] ?? null) === $currentCoreDigest
            && ($degradedResolved[0]['capability']['status'] ?? null) === 'certified',
            '(c) the unrelated shipped adapter in the same pin set is untouched — same digest, same certified '
            . 'capability; the withdrawal is scoped to the one adapter whose certificate went stale'
        );
    } else {
        cert_check(false, '(b) the adapter resolves uncertified naming the stale boundary — unreachable: the load refused');
        cert_check(false, '(c) the unrelated shipped adapter is untouched — unreachable: the load refused');
    }

    // (f) The frozen path is the one a PROMOTED site walks: it verifies from
    // its own snapshot and may not reopen the mutable repository, so if it kept
    // refusing, the unbrick would stop at the host.
    $frozenStale = null;
    try {
        AdapterCertification::verifyFrozen($integrationManifests, 'site-demo', $manifest, $currentEnvelope);
    } catch (Throwable $t) {
        $frozenStale = $t;
    }
    cert_check(
        $frozenStale instanceof \Duo\StalePlatformSiteAdapterCertificate,
        '(f) verifyFrozen() raises the same TYPED staleness the live entry point does, so one signal serves both '
        . 'paths and neither can drift (' . ($frozenStale === null ? 'no exception' : get_class($frozenStale)) . ')'
    );
    $frozenPolicy = null;
    try {
        $frozenPolicy = Policy::from_snapshot($currentSnapshot);
        cert_check(true, '(f) and Policy::from_snapshot() completes on the frozen snapshot the site already holds');
    } catch (Throwable $e) {
        cert_check(
            false,
            '(f) and Policy::from_snapshot() completes on the frozen snapshot the site already holds ('
            . $e->getMessage() . ')'
        );
    }
    if ($frozenPolicy instanceof Policy) {
        $frozenResolved = RepositoryCompiler::resolved_adapters($frozenPolicy);
        cert_check(
            !$frozenPolicy->adapter_sources()->is_certified('site-demo')
            && ($frozenResolved[0]['digest'] ?? null) === $currentCoreDigest
            && ($frozenResolved[1]['digest'] ?? null) === ($degradedResolved[1]['digest'] ?? 'live-load-refused'),
            '(f) and it withdraws exactly the claim the live scan withdraws, digest for digest — the frozen record '
            . 'is DISCARDED and re-derived from the frozen manifest, never trusted for the state it claims'
        );
    } else {
        cert_check(false, '(f) and it withdraws exactly the claim the live scan does — unreachable: from_snapshot refused');
    }

    // (g) Remedy invocability. `duo adapter certify --pin` runs Policy::load()
    // three times (AdapterCertify.php:283 pre-flight, :349/:663 pin object,
    // :570 scope), so under whole-source refusal the repair command was the
    // first casualty of the condition it repairs. Driven on a COPY so the
    // fixture the rest of this suite depends on is not re-signed underneath it.
    $remedySite = $root . '/stale-remedy-site';
    cert_copy_tree($site, $remedySite);
    $remedyKeypair = sodium_crypto_sign_seed_keypair(str_repeat('R', SODIUM_CRYPTO_SIGN_SEEDBYTES));
    cert_write($root . '/remedy-secret.key', base64_encode(sodium_crypto_sign_secretkey($remedyKeypair)) . "\n");
    chmod($root . '/remedy-secret.key', 0600);
    $remedyRun = cert_run([
        'env',
        'DUO_MANIFESTS_DIR=' . $integrationManifests,
        PHP_BINARY,
        dirname(__DIR__, 4) . '/cli/duo',
        'adapter',
        'certify',
        $remedySite,
        '--name=site-demo',
        '--secret-key-file=' . $root . '/remedy-secret.key',
        '--pin',
    ]);
    cert_check(
        $remedyRun['exit'] === 0 && str_contains($remedyRun['stdout'], 'certified:  site-demo'),
        '(g) `duo adapter certify --pin` — the one command that repairs this — completes on the degraded site '
        . '(exit ' . $remedyRun['exit'] . ' ' . trim($remedyRun['stderr']) . ')'
    );
    try {
        cert_check(
            Policy::load($remedySite)->adapter_sources()->is_certified('site-demo'),
            '(g) and the re-signed adapter is certified again against the CURRENT boundary — the withdrawal is a '
            . 'state an operator can leave, not a trap'
        );
    } catch (Throwable $e) {
        cert_check(false, '(g) and the re-signed adapter is certified again (' . $e->getMessage() . ')');
    }

    // (d) THE LINE. Every one of these is the identical stale-platform
    // condition with one more thing wrong, and every one of them must still
    // take the whole source down. The structural reason forgery cannot reach
    // the withdrawal is ordering: assertAdapterBinding, the authority binding
    // and the Ed25519 check all run BEFORE the platform comparison
    // (AdapterCertification::verifyCertificate), so a companion that is not
    // provably the operator's own never gets as far as the typed signal.
    $forgedStatement = Canon::decode($originalCertificateRaw);
    $forgedStatement['statement']['bundle']['git_revision'] = str_repeat('f', 40);
    cert_write_canon($certPath, $forgedStatement);
    cert_expect_throw(
        static fn() => Policy::load($site),
        'invalid Ed25519 signature',
        '(d) a FORGED statement under the identical stale-platform conditions still refuses the whole source'
    );
    $misbound = Canon::decode($originalCertificateRaw);
    $misbound['statement']['adapter']['path'] = 'adapters/somewhere-else.json';
    cert_write_canon($certPath, $misbound);
    cert_expect_throw(
        static fn() => Policy::load($site),
        'does not bind the exact source/path/canonical manifest/trust tier',
        '(d) a WRONG-BINDING companion still refuses the whole source, and is not read as a stale boundary'
    );
    cert_write($certPath, $originalCertificateRaw);
    $revokedIntegrationKeys = new stdClass();
    foreach ((array) $integrationKeys as $revokedId => $revokedRecord) {
        $revokedRecord['status'] = $revokedId === 'review-key' ? 'revoked' : $revokedRecord['status'];
        $revokedIntegrationKeys->{$revokedId} = $revokedRecord;
    }
    cert_write_canon($integrationManifests . '/capabilities/adapter-authorities.json', [
        'format' => 'duo-adapter-authorities/v1',
        'keys' => $revokedIntegrationKeys,
    ]);
    cert_expect_throw(
        static fn() => Policy::load($site),
        // A revoked key is dropped from the current authority map, so the
        // binding check refuses before the revocation message is ever reached —
        // either way the source is refused, and the refusal names the root it
        // disagreed with rather than the boundary.
        'does not match the current platform authority record',
        '(d) an AUTHORITY ANOMALY — the signing key revoked in the current agent root — still refuses the whole '
        . 'source; a withdrawal here would launder a revoked signature into unsigned support'
    );
    cert_write_canon($integrationManifests . '/capabilities/adapter-authorities.json', [
        'format' => 'duo-adapter-authorities/v1',
        'keys' => $integrationKeys,
    ]);

    // (e) An UNPARSEABLE or shape-broken statement, as distinct from a validly
    // older wire. These are the cases a version-tolerant reader would be
    // tempted to excuse; each one is a file that is not a certificate, and each
    // one stays a refusal.
    $unparseableCases = [
        'a statement that is not an object at all' => static function (array $certificate): array {
            $certificate['statement'] = 'not an object';
            return $certificate;
        },
        'a statement missing the platform boundary it must bind' => static function (array $certificate): array {
            unset($certificate['statement']['platform']);
            return $certificate;
        },
        'a statement carrying a key this wire does not define' => static function (array $certificate): array {
            $certificate['statement']['future_field'] = ['unread' => true];
            return $certificate;
        },
    ];
    foreach ($unparseableCases as $unparseableLabel => $unparseableMutation) {
        cert_write_canon($certPath, $unparseableMutation(Canon::decode($originalCertificateRaw)));
        cert_expect_throw(
            static fn() => Policy::load($site),
            'duo: site adapter certification',
            "(e) $unparseableLabel still refuses the whole source"
        );
    }
    cert_write($certPath, "{ this is not canonical json\n");
    cert_expect_throw(
        static fn() => Policy::load($site),
        'is not valid JSON',
        '(e) and a companion that is not parseable JSON never reaches the wire-version test at all'
    );
    // Parseable but not CANONICAL: the wire-version test sits inside
    // assertCertificateShape, downstream of readCanonicalObjectFile, so a
    // hand-formatted certificate cannot claim a future wire to dodge the
    // canonical-bytes rule. Compact rather than merely re-indented, because
    // Canon's canonical form IS four-space pretty JSON with a trailing newline
    // — a pretty-printed re-encode of already-sorted keys is byte-identical.
    $nonCanonicalFutureWire = Canon::decode($originalCertificateRaw);
    $nonCanonicalFutureWire['format'] = 'duo-adapter-certification/v2';
    cert_write($certPath, json_encode(
        $nonCanonicalFutureWire,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n");
    cert_expect_throw(
        static fn() => Policy::load($site),
        'canonical',
        '(e) and neither does a parseable but non-canonical one that claims a future wire'
    );

    // The withdrawal is REVERSIBLE and moves nothing permanent: put the agent's
    // boundary back and the same certificate, unchanged, is certified again at
    // the same digest it always had.
    cert_write($certPath, $originalCertificateRaw);
    cert_write_canon($integrationManifests . '/capabilities/platform.json', [
        'format' => ManifestDispositions::PLATFORM_FORMAT,
        'platform' => $platform,
    ]);
    $restoredResolved = RepositoryCompiler::resolved_adapters(Policy::load($site));
    cert_check(
        ($restoredResolved[1]['digest'] ?? null) === $certifiedDigest
        && ($restoredResolved[1]['capability']['status'] ?? null) === 'certified',
        'restoring the agent boundary restores the certified claim at its original digest — the withdrawal wrote '
        . 'nothing and revoked nothing'
    );

    // The same withdrawal for the other agent-owned document inside a
    // certificate: its wire version. There is no /v2 wire today, so this is the
    // mechanism proved against a synthetic version field — which is exactly
    // what makes case (e) above a real line rather than a tautology.
    $futureWire = Canon::decode($originalCertificateRaw);
    $futureWire['format'] = 'duo-adapter-certification/v2';
    cert_write_canon($certPath, $futureWire);
    $wirePolicy = null;
    try {
        $wirePolicy = Policy::load($site);
    } catch (Throwable $e) {
        cert_check(false, 'a superseded certificate WIRE withdraws one claim instead of refusing the source ('
            . $e->getMessage() . ')');
    }
    if ($wirePolicy instanceof Policy) {
        $wireReason = (string) ($wirePolicy->adapter_sources()->provenance('site-demo')['reason'] ?? '');
        cert_check(
            !$wirePolicy->adapter_sources()->is_certified('site-demo')
            && str_contains($wireReason, 'certification wire version this agent does not verify')
            && (RepositoryCompiler::resolved_adapters($wirePolicy)[0]['digest'] ?? null) === $currentCoreDigest,
            'a superseded certificate WIRE withdraws one claim, names the wire in its reason, and leaves the '
            . 'unrelated shipped adapter alone (' . $wireReason . ')'
        );
    }
    // The predicate is the exact family at another integer version and nothing
    // else. Each of these is one character away from the withdrawal above and
    // every one of them is still a whole-source refusal — this is the
    // "one predicate too wide" the risk note is about, tested rather than
    // reasoned about.
    foreach ([
        'duo-adapter-certification/v0',
        'duo-adapter-certification/v01',
        'duo-adapter-certification/v',
        'duo-adapter-certification/vnext',
        'duo-adapter-certification/v2x',
        'duo-adapter-certification/v2 ',
        'duo-site-adapter-certification/v2',
    ] as $nearMissFormat) {
        $nearMiss = Canon::decode($originalCertificateRaw);
        $nearMiss['format'] = $nearMissFormat;
        cert_write_canon($certPath, $nearMiss);
        cert_expect_throw(
            static fn() => Policy::load($site),
            'unsupported or malformed root',
            "(e) format '$nearMissFormat' is not this wire family at another version — still a whole-source refusal"
        );
    }
    cert_write($certPath, $originalCertificateRaw);
    cert_write_canon($site . '/site.duo.json', [
        'manifests' => [['name' => 'site-demo', 'source' => 'site']],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]);
} finally {
    cert_write($certPath, $originalCertificateRaw);
    putenv('DUO_MANIFESTS_DIR');
}

echo "\n== frozen verification and current agent-owned roots ==\n";
$envelope = $verified['envelope'];
cert_check(
    AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $envelope)['claim']['status'] === 'certified',
    'frozen verification rechecks the signed certificate against current authorities without site raw bytes'
);
unlink($site . '/adapters/site-demo.json');
cert_check(
    AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $envelope)['claim']['status'] === 'certified',
    'frozen verification intentionally does not reopen mutable site adapter bytes'
);
cert_write_canon($site . '/adapters/site-demo.json', $manifest);

$v2Envelope = $envelope;
$v2Envelope['format'] = 'duo-adapter-certification/v2';
cert_expect_throw(
    static fn() => AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $v2Envelope),
    'envelope',
    'a v2/alternate frozen envelope format is refused'
);
$nonCanonicalEnvelope = $envelope;
$nonCanonicalEnvelope['certificate_json'] .= '=';
cert_expect_throw(
    static fn() => AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $nonCanonicalEnvelope),
    'corrupt certificate bytes',
    'noncanonical base64 certificate envelope data is refused'
);

$numericFrozenCertificate = $policySnapshot;
$numericCertificateEnvelope = $numericFrozenCertificate['adapter_sources']['certificates']['site-demo'];
unset($numericFrozenCertificate['adapter_sources']['certificates']['site-demo']);
$numericFrozenCertificate['adapter_sources']['certificates']['123'] = $numericCertificateEnvelope;
cert_expect_throw(
    static fn() => Policy::from_snapshot($numericFrozenCertificate),
    'numeric-only identities',
    'a frozen certified source record rejects a numeric-only certificate-map key before PHP map coercion can relabel it'
);

cert_expect_throw(
    static fn() => AdapterCertification::sign(
        $agent, $site, 'site-demo', $bundle, $site, '123', base64_encode($secret)
    ),
    'numeric-only identities',
    'signing rejects a numeric-only authority selector before authority-map lookup'
);
$numericKeys = clone $keys;
$numericKeys->{'123'} = $keys->{'review-key'};
$numericAuthorities = $authorities;
$numericAuthorities['keys'] = $numericKeys;
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $numericAuthorities);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $envelope),
    'numeric-only identities',
    'frozen verification rejects a numeric authority-record map key rather than depending on an integer PHP key'
);
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $authorities);

$keys->{'review-key'}['status'] = 'revoked';
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $authorities);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $envelope),
    'does not match the current platform authority record',
    'a current revoked platform authority invalidates frozen certification, naming the root it disagreed with'
);
$keys->{'review-key'}['status'] = 'trusted';
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $authorities);

$changedPlatform = $platform;
$changedPlatform['branchable_state'] = 'a different current platform boundary';
cert_write_canon($agent . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $changedPlatform,
]);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $envelope),
    'platform boundary',
    'a current platform mutation invalidates frozen certification'
);
cert_write_canon($agent . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $platform,
]);

echo "\n== signed payload, source, and evidence mutation refusals ==\n";
$certificateRaw = (string) file_get_contents($certPath);
$certificate = json_decode($certificateRaw, true, 512, JSON_THROW_ON_ERROR);
$mutatedBundleCertificate = $certificate;
$mutatedBundleCertificate['statement']['bundle']['git_revision'] = str_repeat('b', 40);
cert_write_canon($certPath, $mutatedBundleCertificate);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFile($agent, $site, 'site-demo', $manifest, $certPath),
    'invalid Ed25519 signature',
    'a signed bundle-identity mutation fails before becoming a claim'
);
$mutatedEvidenceCertificate = $certificate;
$mutatedEvidenceCertificate['statement']['ratification']['manifests']['site-demo']['evidence']['tests'] = ['other-test'];
cert_write_canon($certPath, $mutatedEvidenceCertificate);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFile($agent, $site, 'site-demo', $manifest, $certPath),
    'invalid Ed25519 signature',
    'a signed ratification/evidence mutation fails before becoming a claim'
);
cert_write($certPath, $certificateRaw);

$changedManifest = $manifest;
$changedManifest['post_types'] = ['page' => []];
cert_write_canon($site . '/adapters/site-demo.json', $changedManifest);
// An edit moves the digest: the well-formed companion now binds superseded
// bytes. verifyFile signals that distinctly (SupersededSiteAdapterCertificate,
// message "binds a superseded manifest"), separate from the generic "does not
// bind" that a malformed/misplaced/wrong-tier companion throws — so only a
// content edit, never a corrupt or forged companion, is treated as benign.
cert_expect_throw(
    static fn() => AdapterCertification::verifyFile($agent, $site, 'site-demo', $changedManifest, $certPath),
    'superseded',
    'live verification signals a changed canonical/raw source adapter as superseded, not a generic binding failure'
);
// docs/guides/adapter-authoring.md: an edit "moves the digest and the claim
// drops back to uncertified" — the whole source scan must NOT hard-fail over a
// superseded companion; it resolves the adapter as uncertified support (the
// same state a companion-absent site adapter reaches), which `duo adapter
// certify --pin` re-establishes. grind_adoption A8 exercises exactly this.
$editedSources = \Duo\AdapterSources::discover($agent, $site);
cert_check(
    !$editedSources->is_certified('site-demo'),
    'an edited (superseded) site adapter resolves as uncertified through the whole source scan, never a hard refusal'
);
cert_write_canon($site . '/adapters/site-demo.json', $manifest);
cert_check(
    \Duo\AdapterSources::discover($agent, $site)->is_certified('site-demo'),
    'restoring the exact certified bytes restores the certified claim (the untouched companion binds them again)'
);

$tamperedBundle = $root . '/tampered-bundle';
cert_write_bundle($tamperedBundle, $site, $ratification);
cert_write($tamperedBundle . '/results/site-conformance.json', "tampered evidence\n");
cert_expect_throw(
    static fn() => AdapterCertification::sign(
        $agent, $site, 'site-demo', $tamperedBundle, $site, 'review-key', base64_encode($secret)
    ),
    'tampered',
    'signing independently rejects a changed bundle evidence asset'
);

$swappedBundle = $root . '/swapped-bundle';
cert_write_bundle($swappedBundle, $site, $ratification, [
    'result' => [
        'exit_code' => 1,
        'schema_version' => 1,
        'test' => 'site-conformance',
        'verdict' => 'fail',
    ],
]);
$assetReadHook = new ReflectionProperty(AdapterCertification::class, 'testVerifiedBundleAssetReadHook');
$assetReadHook->setValue(null, static function (string $path) use ($swappedBundle): void {
    if ($path === 'results/site-conformance.json') {
        cert_write(
            $swappedBundle . '/results/site-conformance.json',
            cert_bundle_pretty([
                'exit_code' => 0,
                'schema_version' => 1,
                'test' => 'site-conformance',
                'verdict' => 'pass',
            ])
        );
    }
});
try {
    cert_expect_throw(
        static fn() => AdapterCertification::sign(
            $agent, $site, 'site-demo', $swappedBundle, $site, 'review-key', base64_encode($secret)
        ),
        'does not record a named passing zero-exit test',
        'signing parses the descriptor-authenticated result bytes rather than a replacement written after verification'
    );
} finally {
    $assetReadHook->setValue(null, null);
}

$unboundBundle = $root . '/unbound-bundle';
cert_write_bundle($unboundBundle, $site, $ratification, [
    'bound_inputs' => [[
        'path' => 'not-the-subject.json',
        'sha256' => str_repeat('0', 64),
        'size' => 1,
    ]],
]);
cert_expect_throw(
    static fn() => AdapterCertification::sign(
        $agent, $site, 'site-demo', $unboundBundle, $site, 'review-key', base64_encode($secret)
    ),
    'must bind exactly current raw adapters/site-demo.json',
    'signing refuses a passing bundle that never bound the subject adapter raw bytes'
);

$numericRatification = $ratification;
$numericRatification['manifests'] = ['123' => $ratification['manifests']['site-demo']];
$numericRatificationBundle = $root . '/numeric-ratification-bundle';
cert_write_bundle($numericRatificationBundle, $site, $numericRatification);
cert_expect_throw(
    static fn() => AdapterCertification::sign(
        $agent, $site, 'site-demo', $numericRatificationBundle, $site, 'review-key', base64_encode($secret)
    ),
    'numeric-only identities',
    'signing rejects a numeric-only ratification manifest-map key before it can be coerced in PHP'
);

echo "\n== certificate directory pairing and structural refusals ==\n";
cert_check(count(AdapterCertification::verifyDirectory($agent, $site)) === 1, 'directory scan accepts the one exact paired certificate');
cert_write($site . '/adapters/certifications/.hidden.json', $certificateRaw);
cert_expect_throw(
    static fn() => AdapterCertification::verifyDirectory($agent, $site),
    'name',
    'hidden certificate names are not silently ignored'
);
unlink($site . '/adapters/certifications/.hidden.json');
cert_write($site . '/adapters/certifications/orphan.json', $certificateRaw);
cert_expect_throw(
    static fn() => AdapterCertification::verifyDirectory($agent, $site),
    'certified site adapter',
    'orphan certificates without adapters/<name>.json are fatal'
);
unlink($site . '/adapters/certifications/orphan.json');
// The variant deliberately gets a basename with NO lowercase sibling: on a
// case-insensitive filesystem (macOS default) writing `site-demo.JSON` next
// to the existing `site-demo.json` OVERWRITES the valid pair instead of
// creating a second entry — the directory then holds one well-named
// certificate, nothing throws, and the unlink below deletes the pair the
// rest of the suite relies on. The refusal under test is about the
// EXTENSION's case, which a distinct basename exercises identically on
// every host. Fixture manufacture asserted before the refusal (DUO-3381).
cert_write($site . '/adapters/certifications/variant.JSON', $certificateRaw);
cert_check(
    is_file($site . '/adapters/certifications/variant.JSON')
        && in_array('variant.JSON', scandir($site . '/adapters/certifications') ?: [], true)
        && in_array('site-demo.json', scandir($site . '/adapters/certifications') ?: [], true),
    'the case-variant fixture actually exists as its own directory entry beside the untouched valid pair'
);
cert_expect_throw(
    static fn() => AdapterCertification::verifyDirectory($agent, $site),
    'only direct',
    'case-variant certificate extensions are fatal rather than ignored'
);
unlink($site . '/adapters/certifications/variant.JSON');
cert_write($site . '/adapters/certifications/nested/other.json', $certificateRaw);
cert_expect_throw(
    static fn() => AdapterCertification::verifyDirectory($agent, $site),
    'only direct',
    'nested certificate paths are fatal rather than ignored'
);
cert_remove_tree($site . '/adapters/certifications/nested');

chmod($site . '/adapters/certifications', 0000);
try {
    cert_expect_throw(
        static fn() => \Duo\AdapterSources::discover(dirname(__DIR__, 4) . '/manifests', $site),
        'not readable',
        'adapter discovery cannot launder an unreadable authority directory into an uncertified absence'
    );
    cert_expect_throw(
        static fn() => AdapterCertification::verifyDirectory($agent, $site),
        'not readable',
        'direct certificate verification cannot report an unreadable authority directory as empty'
    );
} finally {
    chmod($site . '/adapters/certifications', 0777);
}

$emptySite = $root . '/empty-site';
mkdir($emptySite, 0777, true);
cert_check(AdapterCertification::verifyDirectory($agent, $emptySite) === [], 'absence of certifications remains an uncertified, nonfatal state');

// ======================================================================
echo "\n== T6 §3.1/§3.2: the SITE trust root, and Site-certified ==\n";
// ======================================================================
// Everything above signs under a key in the AGENT-OWNED authorities file.
// That file ships EMPTY and only this project can fill it, so before T6 no
// operator could complete certification at all and `Site-certified` was
// NEVER_EMITTED. This group is the same protocol under the operator's own
// root, in the operator's own repository, with the one honest relaxation the
// weaker evidence requires.
$orgRoot = $root . '/site-root';
$orgAgent = $orgRoot . '/agent-manifests';
$orgSite = $orgRoot . '/site';
$orgBundle = $orgRoot . '/bundle';

$orgManifest = [
    'name' => 'acme-catalog',
    'option_autoload' => 'preserve',
    'options' => ['acme_catalog_layout' => ['class' => 'authored']],
    'post_types' => [],
    'spec_version' => DUO_SPEC_VERSION,
    'tables' => [],
];
cert_write_canon($orgSite . '/adapters/acme-catalog.json', $orgManifest);
// The REAL shipped library, because the claim under test is that an
// operator's own root works while the AGENT-OWNED one stays exactly as
// shipped: present, well-formed, and EMPTY. A synthetic manifest directory
// with no disposition registry cannot demonstrate that — the capability
// report degrades to `unreviewed` before any of this is reached.
cert_copy_tree(dirname(__DIR__, 4) . '/manifests', $orgAgent);
cert_write_canon($orgAgent . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $platform,
]);
cert_write_canon($orgAgent . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => new stdClass(),
]);

$orgKeypair = sodium_crypto_sign_seed_keypair(str_repeat('S', SODIUM_CRYPTO_SIGN_SEEDBYTES));
$orgSecret = sodium_crypto_sign_secretkey($orgKeypair);
$orgPublic = sodium_crypto_sign_publickey($orgKeypair);
$orgKeyRecord = [
    'adapter_names' => ['acme-catalog'],
    'algorithm' => 'ed25519',
    'public_key' => base64_encode($orgPublic),
    'scope' => 'site_adapter_certification',
    'status' => 'trusted',
    'trust_tiers' => ['declarative_manifest'],
];
$orgKeys = new stdClass();
$orgKeys->{'acme-ops'} = $orgKeyRecord;
cert_write_canon($orgSite . '/adapters/authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $orgKeys,
]);
cert_write($orgRoot . '/acme-ops.key', base64_encode($orgSecret) . "\n");
chmod($orgRoot . '/acme-ops.key', 0600);

// The evidence an operator can actually produce: the loader's own grammar
// verdict and a stated reason, with NO exercise proof.
$orgRatification = [
    'format' => 'duo-manifest-dispositions/v1',
    'manifests' => [
        'acme-catalog' => [
            'capabilities' => [
                'deletion_semantics' => ['supported' => [], 'unsupported' => ['all']],
                'entity_sections' => ['options'],
                'field_sections' => [],
                'lifecycle_phases' => [],
                'operations' => ['apply', 'capture', 'deploy'],
            ],
            'default_authored_keyspaces' => [],
            'evidence' => [
                'bundle_schema' => AdapterCertification::BUNDLE_FORMAT,
                'tests' => [],
            ],
            'reason' => 'The site operator certified this adapter for their own site; grammar verified, not exercised.',
            'status' => 'certified',
            'supported_versions' => ['wordpress' => ['source' => 'site-operator']],
            // A certified disposition must state a boundary (ManifestDispositions
            // refuses an empty `unsupported`), and for an unexercised site
            // adapter the honest one is the operation nobody proved.
            'unsupported' => [[
                'operation' => 'delete',
                'reason' => 'This site certification covers authored option state only.',
                'surface' => 'all',
            ]],
        ],
    ],
    'profiles' => [],
];
$orgEvidence = [
    'exercised' => false,
    'grammar' => 'ok',
    'reason' => 'duo manifest-validate reported ok; this adapter has not been exercised against a live target',
];
cert_write_bundle($orgBundle, $orgSite, $orgRatification, [
    'name' => 'acme-catalog',
    'evidence' => $orgEvidence,
    // A repository with no HEAD still binds a 40-hex revision; the nil SHA is
    // the honest value for "no commit bound", not a fabricated one.
    'git_revision' => str_repeat('0', 40),
]);

$orgSign = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $orgAgent,
    '--repo=' . $orgSite,
    '--name=acme-catalog',
    '--bundle=' . $orgBundle,
    '--evidence-repo=' . $orgSite,
    '--authority=acme-ops',
    '--secret-key-file=' . $orgRoot . '/acme-ops.key',
]);
cert_check(
    $orgSign['exit'] === 0,
    'an operator can complete certification under their OWN root with the shipped authorities file empty ('
    . trim($orgSign['stderr']) . ')'
);
$orgSummary = $orgSign['exit'] === 0 ? Canon::decode($orgSign['stdout']) : [];
cert_check(
    ($orgSummary['trust_root'] ?? null) === 'site'
        && ($orgSummary['principal'] ?? null) === 'acme-ops'
        && ($orgSummary['exercised'] ?? null) === false,
    'the signing summary names the root, the principal, and that nothing was exercised'
);

$orgCertPath = $orgSite . '/adapters/certifications/acme-catalog.json';
$orgVerified = AdapterCertification::verifyFile($orgAgent, $orgSite, 'acme-catalog', $orgManifest, $orgCertPath);
$orgClaim = $orgVerified['claim'];
cert_check(
    ($orgClaim['status'] ?? null) === 'certified'
        && ($orgClaim['evidence']['status'] ?? null) === 'current',
    'a site-rooted certificate produces status certified with evidence current — the two facts duo promote '
    . 'already gates on, so host promotion needs no second gate'
);
cert_check(
    $orgClaim['certification'] === [
        'principal' => 'acme-ops',
        'signed_at' => '2026-08-09T00:00:00Z',
        'source' => 'site',
        'trust_root' => 'site',
    ],
    'the claim carries exactly {principal, signed_at, source, trust_root} — the shared host contract for '
    . 'printing Site-certified with a name on it: ' . Canon::encode($orgClaim['certification'] ?? null)
);
cert_check(
    ($orgClaim['evidence']['exercised'] ?? null) === false
        && ($orgClaim['evidence']['tests'] ?? null) === [],
    'the claim records that nothing was exercised, so `certified` can never be read as "somebody ran it"'
);

// The two words on every catalog row, before and after the exact pin.
cert_write_canon($orgSite . '/site.duo.json', [
    'manifests' => [['name' => 'acme-catalog', 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]);
putenv('DUO_MANIFESTS_DIR=' . $orgAgent);
$orgSurveyRow = null;
foreach (AdapterSources::survey($orgSite)['adapters'] as $orgRow) {
    if (($orgRow['name'] ?? null) === 'acme-catalog') {
        $orgSurveyRow = $orgRow;
    }
}
cert_check(
    ($orgSurveyRow['certification'] ?? null) === 'signed_unpinned'
        && ($orgSurveyRow['trust_root'] ?? null) === 'site'
        && ($orgSurveyRow['principal'] ?? null) === 'acme-ops',
    'a valid site signature without the exact digest pin is still signed_unpinned, but the root and principal '
    . 'are already named — got ' . var_export($orgSurveyRow['certification'] ?? null, true)
);

$orgDigest = null;
try {
    $orgPolicy = Policy::load($orgSite);
    $orgResolved = RepositoryCompiler::resolved_adapters($orgPolicy);
    $orgDigest = (string) ($orgResolved[0]['digest'] ?? '');
} catch (Throwable $t) {
    cert_check(false, 'the name+source pin loads so manifest-pin can print the exact object (' . $t->getMessage() . ')');
}
cert_write_canon($orgSite . '/site.duo.json', [
    'manifests' => [['digest' => $orgDigest, 'name' => 'acme-catalog', 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]);
$orgPinnedPolicy = Policy::load($orgSite);
$orgDiagnostics = $orgPinnedPolicy->adapter_sources()->diagnostics($orgPinnedPolicy->manifests);
cert_check(
    ($orgDiagnostics['acme-catalog']['certification'] ?? null) === AdapterSources::CERTIFICATION_SITE_SIGNED
        && ($orgDiagnostics['acme-catalog']['trust_root'] ?? null) === 'site'
        && ($orgDiagnostics['acme-catalog']['principal'] ?? null) === 'acme-ops',
    'the exact pin elevates it to site_signed — a separate word from third_party_signed, because the customer '
    . 'organization vouching for its own adapter is explicitly not a Duo endorsement'
);
$orgReport = $orgPinnedPolicy->capability_report(['operation' => 'promote']);
cert_check(
    ($orgReport['ready'] ?? null) === true && ($orgReport['blockers'] ?? null) === [],
    'duo promote\'s existing gate (capability certified + evidence current) admits it with NO host change — got '
    . Canon::encode(['blockers' => array_column((array) ($orgReport['blockers'] ?? []), 'code'),
        'ready' => $orgReport['ready'] ?? null])
);
cert_check(
    CodeDeploy::dispositionBlockers([
        'resolved_adapters' => RepositoryCompiler::resolved_adapters($orgPinnedPolicy),
    ]) === [],
    'host promotion accepts the site-rooted current claim'
);
putenv('DUO_MANIFESTS_DIR');

// The frozen path, which reopens no mutable site file at all.
$orgFrozen = AdapterCertification::verifyFrozen(
    $orgAgent,
    'acme-catalog',
    $orgManifest,
    $orgVerified['envelope']
);
cert_check(
    ($orgFrozen['claim']['status'] ?? null) === 'certified'
        && ($orgFrozen['claim']['certification']['trust_root'] ?? null) === 'site',
    'frozen verification re-binds a site-rooted certificate to the authority record the SIGNATURE covers, '
    . 'because there is no repository to reopen'
);

// SHIPPED WINS THE KEY-ID NAMESPACE, on both paths. Without this an operator
// could re-point a key id this project reviews and have a certificate signed
// under it read as platform-rooted.
$clashKeys = new stdClass();
$clashKeys->{'acme-ops'} = ['adapter_names' => ['acme-catalog'], 'algorithm' => 'ed25519',
    'public_key' => base64_encode($public), 'scope' => 'site_adapter_certification',
    'status' => 'trusted', 'trust_tiers' => ['declarative_manifest']];
cert_write_canon($orgAgent . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $clashKeys,
]);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFile($orgAgent, $orgSite, 'acme-catalog', $orgManifest, $orgCertPath),
    'does not match the current platform authority record',
    'a site key id the shipped library also declares resolves to the SHIPPED record, and the certificate that '
    . 'claimed a site root is refused by name rather than silently downgraded'
);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFrozen($orgAgent, 'acme-catalog', $orgManifest, $orgVerified['envelope']),
    'reviewed and shipped by this agent',
    'the frozen path asks the same shipped-wins question, which needs no repository'
);
cert_write_canon($orgAgent . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => new stdClass(),
]);

// A revoked SITE key stops verifying on the live path, which is where an
// operator's own revocation takes effect.
$orgKeys->{'acme-ops'}['status'] = 'revoked';
cert_write_canon($orgSite . '/adapters/authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $orgKeys,
]);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFile($orgAgent, $orgSite, 'acme-catalog', $orgManifest, $orgCertPath),
    'does not match the current site authority record',
    'revoking a key in the site trust root invalidates its certificates on every live scan'
);
$orgKeys->{'acme-ops'}['status'] = 'trusted';
cert_write_canon($orgSite . '/adapters/authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $orgKeys,
]);
cert_check(
    (AdapterCertification::verifyFile($orgAgent, $orgSite, 'acme-catalog', $orgManifest, $orgCertPath)['claim']['status'] ?? null)
        === 'certified',
    'restoring the key restores the claim, so the refusal above was the revocation and nothing else'
);

// A malformed site trust root is a WHOLE-SOURCE refusal, not one adapter's
// problem: every certificate in the repository is judged against it.
cert_write($orgSite . '/adapters/authorities.json', "{ not json\n");
$orgBrokenSurvey = AdapterSources::survey($orgSite);
cert_check(
    in_array(
        AdapterSources::REFUSAL_CERTIFICATION_SOURCE,
        array_column($orgBrokenSurvey['refusals'], 'code'),
        true
    ),
    'a malformed adapters/authorities.json is refused as a certification source, never laundered into an '
    . 'ordinary uncertified row'
);
cert_write_canon($orgSite . '/adapters/authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $orgKeys,
]);

// THE RELAXATION IS SITE-ONLY. The same unexercised bundle under an
// agent-owned key must refuse: a platform-rooted certificate states a
// reviewed exercise, and that is the whole difference between the two words.
cert_write_canon($orgAgent . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => (object) ['platform-key' => [
        'adapter_names' => ['acme-catalog'],
        'algorithm' => 'ed25519',
        'public_key' => base64_encode($orgPublic),
        'scope' => 'site_adapter_certification',
        'status' => 'trusted',
        'trust_tiers' => ['declarative_manifest'],
    ]],
]);
$platformUnexercised = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $orgAgent,
    '--repo=' . $orgSite,
    '--name=acme-catalog',
    '--bundle=' . $orgBundle,
    '--evidence-repo=' . $orgSite,
    '--authority=platform-key',
    '--secret-key-file=' . $orgRoot . '/acme-ops.key',
]);
cert_check(
    $platformUnexercised['exit'] !== 0
        && str_contains($platformUnexercised['stderr'], 'only a site trust root may certify'),
    'an unexercised bundle under an AGENT-OWNED key is refused — the relaxation follows the root, not the '
    . 'bundle (' . trim($platformUnexercised['stderr']) . ')'
);
cert_write_canon($orgAgent . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => new stdClass(),
]);

// And an unexercised bundle may not smuggle evidence back in.
$smuggleBundle = $orgRoot . '/smuggle-bundle';
cert_write_bundle($smuggleBundle, $orgSite, $orgRatification, [
    'name' => 'acme-catalog',
    'evidence' => $orgEvidence,
    'git_revision' => str_repeat('0', 40),
    'artifacts' => [[
        'name' => 'acme-catalog',
        'role' => 'certified-boundary',
        'sha256' => str_repeat('d', 64),
        'url' => 'https://example.invalid/acme-catalog-1.0.0.zip',
        'version' => '1.0.0',
    ]],
]);
$smuggle = cert_run([
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $orgAgent,
    '--repo=' . $orgSite,
    '--name=acme-catalog',
    '--bundle=' . $smuggleBundle,
    '--evidence-repo=' . $orgSite,
    '--authority=acme-ops',
    '--secret-key-file=' . $orgRoot . '/acme-ops.key',
]);
cert_check(
    $smuggle['exit'] !== 0 && str_contains($smuggle['stderr'], 'exercised false but names tests or artifacts'),
    'a bundle cannot declare exercised:false and still name artifacts — the projection an operator reads must '
    . 'not assert both halves at once'
);

// ======================================================================
echo "\n== T6: sign_site() — the agent owns the unexercised bundle ==\n";
// ======================================================================
// The host verb `duo adapter certify` calls ONE entry point and writes what it
// returns. The bundle grammar and its producer stay in one file, so a host
// that drifted could not mint a certificate at all rather than minting one
// nothing re-verifies.
$autoRoot = $root . '/auto-site';
$autoAgent = $autoRoot . '/agent-manifests';
$autoSite = $autoRoot . '/site';

// A manifest with the three shapes the derivation actually has to reason
// about: a plugin claim (supported_versions is compared against it), an
// intent-only table (must be marked unsupported), and an open-ended authored
// default (must be recorded, and cannot be justified by a grammar check).
$autoManifest = [
    'name' => 'acme-shop',
    'option_autoload' => 'preserve',
    'options' => ['acme_shop_layout' => ['class' => 'authored']],
    'plugin' => 'acme-shop/acme-shop.php',
    'post_types' => [],
    'spec_version' => DUO_SPEC_VERSION,
    'tables' => [
        'acme_shop_index' => [
            'class' => 'authored_typed_snapshot_post_v1',
            'id' => ['column' => 'id', 'kind' => 'surrogate'],
            'post_type' => 'acme_shop_index',
        ],
    ],
    'version_range' => ['max' => '3.0.0', 'min' => '1.0.0'],
];
cert_write_canon($autoSite . '/adapters/acme-shop.json', $autoManifest);
cert_copy_tree(dirname(__DIR__, 4) . '/manifests', $autoAgent);
cert_write_canon($autoAgent . '/capabilities/platform.json', [
    'format' => ManifestDispositions::PLATFORM_FORMAT,
    'platform' => $platform,
]);
cert_write_canon($autoAgent . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => new stdClass(),
]);
$autoKeypair = sodium_crypto_sign_seed_keypair(str_repeat('A', SODIUM_CRYPTO_SIGN_SEEDBYTES));
$autoKeys = new stdClass();
$autoKeys->{'acme-ops'} = [
    'adapter_names' => ['acme-shop'],
    'algorithm' => 'ed25519',
    'public_key' => base64_encode(sodium_crypto_sign_publickey($autoKeypair)),
    'scope' => 'site_adapter_certification',
    'status' => 'trusted',
    'trust_tiers' => ['declarative_manifest'],
];
cert_write_canon($autoSite . '/adapters/authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => $autoKeys,
]);
cert_write($autoRoot . '/acme-ops.key', base64_encode(sodium_crypto_sign_secretkey($autoKeypair)) . "\n");
chmod($autoRoot . '/acme-ops.key', 0600);
cert_write_canon($autoSite . '/site.duo.json', [
    'manifests' => [['name' => 'acme-shop', 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]);

$autoReason = 'duo manifest-validate reported ok; certified by the site operator, not exercised';
$autoEnv = 'DUO_MANIFESTS_DIR=' . $autoAgent;
$autoSign = cert_run([
    'env',
    $autoEnv,
    PHP_BINARY,
    __DIR__ . '/../../../../scripts/adapter-certification.php',
    'sign-site',
    '--manifest-dir=' . $autoAgent,
    '--repo=' . $autoSite,
    '--name=acme-shop',
    '--authority=acme-ops',
    '--secret-key-file=' . $autoRoot . '/acme-ops.key',
    '--reason=' . $autoReason,
]);
cert_check(
    $autoSign['exit'] === 0,
    'sign-site certifies from repo/name/key/reason alone — no bundle directory and no evidence repository ('
    . trim($autoSign['stderr']) . ')'
);
cert_check(
    !is_dir($autoSite . '/bundle') && !is_file($autoSite . '/bundle.json'),
    'nothing was written but the certificate: an unexercised bundle lives entirely inside the signed statement'
);

putenv('DUO_MANIFESTS_DIR=' . $autoAgent);
$autoCertPath = $autoSite . '/adapters/certifications/acme-shop.json';
$autoVerified = AdapterCertification::verifyFile(
    $autoAgent,
    $autoSite,
    'acme-shop',
    $autoManifest,
    $autoCertPath
);
$autoClaim = $autoVerified['claim'];
cert_check(
    ($autoClaim['status'] ?? null) === 'certified'
        && ($autoClaim['certification']['trust_root'] ?? null) === 'site'
        && ($autoClaim['certification']['principal'] ?? null) === 'acme-ops'
        && ($autoClaim['evidence']['exercised'] ?? null) === false,
    'the produced certificate verifies through the ordinary path and carries the site certification facts'
);

// The derivation, field by field — this is the part a host producer would
// have had to reimplement, and every one of these is a restatement of the
// manifest or of something a grammar check provably did not review.
$autoCertificate = Canon::decode((string) file_get_contents($autoCertPath));
$autoDisposition = $autoCertificate['statement']['ratification']['manifests']['acme-shop'];
cert_check(
    $autoDisposition['capabilities']['entity_sections'] === ['post_types', 'tables']
        && $autoDisposition['capabilities']['field_sections'] === ['options'],
    'sections are exactly the state surfaces the manifest declares, partitioned by the shipped vocabulary — got '
    . Canon::encode([$autoDisposition['capabilities']['entity_sections'],
        $autoDisposition['capabilities']['field_sections']])
);
cert_check(
    !in_array('delete', $autoDisposition['capabilities']['operations'], true)
        && $autoDisposition['capabilities']['deletion_semantics']['supported'] === []
        && $autoDisposition['capabilities']['lifecycle_phases'] === [],
    'deletion and lifecycle are claimed by nobody: a validator run reviews neither'
);
cert_check(
    $autoDisposition['supported_versions'] === [
        'plugin' => 'acme-shop/acme-shop.php',
        'range' => ['max' => '3.0.0', 'min' => '1.0.0'],
    ],
    'supported_versions restates the manifest\'s own plugin/range, which validate_entry() compares'
);
$autoSurfaces = array_column($autoDisposition['unsupported'], 'surface');
cert_check(
    in_array('tables.acme_shop_index', $autoSurfaces, true) && in_array('deletions.*', $autoSurfaces, true),
    'the intent-only table and the unreviewed deletion surface are both marked unsupported — got '
    . implode(',', $autoSurfaces)
);
cert_check(
    $autoDisposition['evidence']['tests'] === []
        && $autoCertificate['statement']['bundle']['tests'] === []
        && $autoCertificate['statement']['bundle']['artifacts'] === []
        && $autoCertificate['statement']['bundle']['evidence'] === [
            'exercised' => false,
            'grammar' => 'ok',
            'reason' => $autoReason,
        ],
    'the bundle names no test and no artifact, and says so in one place a reader cannot miss'
);
cert_check(
    $autoCertificate['statement']['bundle']['git_revision'] === str_repeat('0', 40),
    'the nil SHA binds no evidence repository, rather than borrowing the site repo HEAD as provenance it is not'
);

// A default_class: authored keyspace cannot be JUSTIFIED by a grammar check.
$autoDefaultManifest = $autoManifest;
$autoDefaultManifest['tables']['acme_shop_meta'] = [
    'attached_to' => ['column' => 'id', 'table' => 'acme_shop_index'],
    'class' => 'authored_snapshot_meta',
    'default_class' => 'authored',
    'key_column' => 'meta_key',
    'keys' => new stdClass(),
    'value_column' => 'meta_value',
];
cert_write_canon($autoSite . '/adapters/acme-shop.json', $autoDefaultManifest);
unlink($autoCertPath);
$autoDefaultSign = cert_run([
    'env', $autoEnv, PHP_BINARY, __DIR__ . '/../../../../scripts/adapter-certification.php', 'sign-site',
    '--manifest-dir=' . $autoAgent, '--repo=' . $autoSite, '--name=acme-shop',
    '--authority=acme-ops', '--secret-key-file=' . $autoRoot . '/acme-ops.key', '--reason=' . $autoReason,
]);
if ($autoDefaultSign['exit'] === 0) {
    $autoDefaultRows = Canon::decode((string) file_get_contents($autoCertPath))
        ['statement']['ratification']['manifests']['acme-shop']['default_authored_keyspaces'];
    cert_check(
        count($autoDefaultRows) === 1
            && $autoDefaultRows[0]['table'] === 'acme_shop_meta'
            && $autoDefaultRows[0]['status'] === 'unsupported',
        'an open-ended authored default is RECORDED but never justified — justification is a review judgement '
        . 'about a plugin-upgrade tripwire, and no exercise made one'
    );
} else {
    cert_check(false, 'the default-authored keyspace fixture signs (' . trim($autoDefaultSign['stderr']) . ')');
}
cert_write_canon($autoSite . '/adapters/acme-shop.json', $autoManifest);

// The four ways sign_site() must refuse.
$autoRefusals = [
    'an agent-owned key cannot take this path' => [
        ['--authority=review-key'],
        'agent-owned key',
        static function () use ($autoAgent, $keys): void {
            $agentKeys = new stdClass();
            $agentKeys->{'review-key'} = $keys->{'review-key'};
            cert_write_canon($autoAgent . '/capabilities/adapter-authorities.json', [
                'format' => 'duo-adapter-authorities/v1',
                'keys' => $agentKeys,
            ]);
        },
    ],
    // Whitespace rather than empty: the tool's own argument grammar refuses an
    // empty --reason= before the class is reached, so this drives the class's
    // own rule instead of re-asserting the parser's.
    'a blank reason is not a basis' => [['--reason=   '], 'must state its basis', null],
];
foreach ($autoRefusals as $autoLabel => [$autoExtra, $autoNeedle, $autoSetup]) {
    if ($autoSetup !== null) {
        $autoSetup();
    }
    $autoArgs = [
        'env', $autoEnv, PHP_BINARY, __DIR__ . '/../../../../scripts/adapter-certification.php', 'sign-site',
        '--manifest-dir=' . $autoAgent, '--repo=' . $autoSite, '--name=acme-shop',
        '--authority=acme-ops', '--secret-key-file=' . $autoRoot . '/acme-ops.key', '--reason=' . $autoReason,
    ];
    foreach ($autoExtra as $autoFlag) {
        [$autoKey] = explode('=', $autoFlag, 2);
        $autoArgs = array_values(array_filter(
            $autoArgs,
            static fn(string $a): bool => !str_starts_with($a, $autoKey . '=')
        ));
        $autoArgs[] = $autoFlag;
    }
    $autoRun = cert_run($autoArgs);
    cert_check(
        $autoRun['exit'] !== 0 && str_contains($autoRun['stderr'], $autoNeedle),
        "sign-site refuses: $autoLabel (" . trim($autoRun['stderr']) . ')'
    );
}
cert_write_canon($autoAgent . '/capabilities/adapter-authorities.json', [
    'format' => 'duo-adapter-authorities/v1',
    'keys' => new stdClass(),
]);

// A manifest the loader refuses has no grammar verdict to certify. The
// certificate written above is removed first, deliberately: leaving it would
// make discover() refuse the SOURCE over a certificate that no longer binds
// these bytes, and this check would pass on a message about the wrong thing.
unlink($autoCertPath);
// An incompatible spec_version, chosen because it is one of the few things
// the loader genuinely refuses: a bad option class and an unknown top-level
// key both LOAD today (verified), which is exactly why the signer's own
// section classifier below has to exist.
cert_write_canon($autoSite . '/adapters/acme-shop.json', [
    'name' => 'acme-shop',
    'option_autoload' => 'preserve',
    'options' => ['acme_shop_layout' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION + 97,
]);
$autoBroken = cert_run([
    'env', $autoEnv, PHP_BINARY, __DIR__ . '/../../../../scripts/adapter-certification.php', 'sign-site',
    '--manifest-dir=' . $autoAgent, '--repo=' . $autoSite, '--name=acme-shop',
    '--authority=acme-ops', '--secret-key-file=' . $autoRoot . '/acme-ops.key', '--reason=' . $autoReason,
]);
cert_check(
    $autoBroken['exit'] !== 0 && str_contains($autoBroken['stderr'], 'no grammar verdict to certify'),
    'a manifest the real loader refuses is not signed — the grammar verdict is TAKEN from the loader, never '
    . 'asserted by the signer (' . trim($autoBroken['stderr']) . ')'
);

// An unclassifiable section stops the signer instead of narrowing the claim.
// Driven at the derivation directly rather than through the tool: a manifest
// key the LOADER already refuses never reaches the signer, and the condition
// this guard exists for is the opposite one — a future section kind the loader
// accepts and this file has not been taught. Reflection is how that future is
// reachable today.
$autoDerive = new ReflectionMethod(AdapterCertification::class, 'siteRatification');
cert_expect_throw(
    static fn() => $autoDerive->invoke(
        null,
        'acme-shop',
        $autoManifest + ['future_surface' => ['acme_next' => ['class' => 'authored']]],
        $autoReason
    ),
    'cannot classify as an entity or field surface',
    'a manifest section the signer cannot classify stops it rather than minting a certificate that covers less '
    . 'than the adapter declares'
);
cert_write_canon($autoSite . '/adapters/acme-shop.json', $autoManifest);
putenv('DUO_MANIFESTS_DIR');

if ($failures !== 0) {
    fwrite(STDERR, "\n$failures site-adapter-certification regression assertion(s) failed\n");
    exit(1);
}

echo "\nsite-adapter-certification regression passed\n";
