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

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Db.php';
require_once __DIR__ . '/../../agent/src/AdapterSources.php';
require_once __DIR__ . '/../../agent/src/ManifestDispositions.php';
require_once __DIR__ . '/../../agent/src/CapabilityRegistry.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/RepositoryCompiler.php';
require_once __DIR__ . '/../../agent/src/AdapterCertification.php';
require_once __DIR__ . '/../../agent/src/Deploy.php';
require_once __DIR__ . '/../../agent/src/Providers.php';
require_once __DIR__ . '/../../cli/src/PlanSummary.php';
require_once __DIR__ . '/../../cli/src/CodeDeploy.php';

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
        return $tag === 'duo_providers'
            ? (array) ($GLOBALS['cert_plugin_providers'] ?? [])
            : $value;
    }
}
require_once __DIR__ . '/../../agent/src/Cli.php';

use Duo\AdapterCertification;
use Duo\Canon;
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
 * Create the exact subset of a duo-certification-bundle/v1 needed for one
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

    $adapterPath = $site . '/adapters/' . $name . '.json';
    $boundInputs = $options['bound_inputs'] ?? [
        cert_descriptor($adapterPath, 'adapters/' . $name . '.json'),
    ];
    $bundle = [
        'artifacts' => [],
        'bound_inputs' => $boundInputs,
        'created_at' => '2026-08-09T00:00:00Z',
        'environment' => cert_descriptor($dir . '/environment.json', 'environment.json'),
        'environment_summary' => $environment,
        'force_hatches' => $options['force_hatches'] ?? [],
        'git_revision' => $options['git_revision'] ?? str_repeat('a', 40),
        'harness' => ['name' => 'site-adapter-certification-regression', 'version' => 1],
        'ratification' => cert_descriptor($dir . '/ratification.json', 'ratification.json'),
        'ratification_summary' => [
            'certified_claims' => ['manifests.' . $name],
            'manifest_count' => 1,
            'profile_count' => 0,
        ],
        'schema_version' => 'duo-certification-bundle/v1',
        'tests' => [[
            'diff' => cert_descriptor($dir . '/diffs/site-conformance.json', 'diffs/site-conformance.json'),
            'id' => 'site-conformance',
            'log' => cert_descriptor($dir . '/logs/site-conformance.txt', 'logs/site-conformance.txt'),
            'result' => cert_descriptor($dir . '/results/site-conformance.json', 'results/site-conformance.json'),
            'verdict' => 'pass',
        ]],
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
                'operations' => ['apply', 'deploy'],
            ],
            'default_authored_keyspaces' => [],
            'evidence' => [
                'bundle_schema' => 'duo-certification-bundle/v1',
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
        'database' => ['engine' => 'MariaDB', 'max' => '12.0.0', 'min' => '11.0.0', 'note' => 'fixture'],
        'php' => ['max' => '8.4.0', 'min' => '8.3.0', 'note' => 'fixture'],
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
cert_write_canon($agent . '/capabilities/registry.json', [
    'format' => 'duo-capability-registry/v1',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
cert_write_canon($providerAgent . '/capabilities/registry.json', [
    'format' => 'duo-capability-registry/v1',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
    __DIR__ . '/../../scripts/adapter-certification.php',
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
PLUGIN
);
require_once $pluginFile;
$GLOBALS['cert_plugin_providers'] = [new \DuoCertificationPluginProvider()];
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
        var_export(dirname(__DIR__, 2), true),
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
require __ENGINE_ROOT__ . '/agent/src/Canon.php';
require __ENGINE_ROOT__ . '/agent/src/OptionState.php';
require __ENGINE_ROOT__ . '/agent/src/Policy.php';
require __ENGINE_ROOT__ . '/agent/src/Deploy.php';
require __ENGINE_ROOT__ . '/agent/src/Providers.php';
require __PROVIDER_FILE__;
$GLOBALS['lazy_providers'] = [new DuoCertificationLazyApiProvider()];
$GLOBALS['lazy_provider_invocations'] = 0;
$payload['gate_before_loader'] = \Duo\Providers::runtime_negotiation_available();
try {
    $policy = \Duo\Policy::from_snapshot([
        'format' => 'duo-policy-snapshot/v4',
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'site' => [
            'manifests' => ['lazy-provider'],
            'spec_version' => 2,
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
        ],
        'manifests' => [[
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
        ]],
    ]);
    $negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:page']));
    $payload['problems'] = $negotiation['problems'];
} catch (\Throwable $failure) {
    $payload['error'] = $failure->getMessage();
}
$payload['admin_api_loaded_after'] = function_exists('validate_plugin')
    && function_exists('get_plugins') && function_exists('is_wp_error');
$payload['invocations'] = $GLOBALS['lazy_provider_invocations'];
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
cert_write_canon($providerPolicyManifests . '/capabilities/registry.json', [
    'format' => 'duo-capability-registry/v1',
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
cert_copy_tree(dirname(__DIR__, 2) . '/manifests', $integrationManifests);
$integrationRegistry = json_decode(
    (string) file_get_contents($integrationManifests . '/capabilities/registry.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$integrationRegistry['platform'] = $platform;
// Exercise mixed per-row evidence isolation independent of whether the
// checked-out shipped registry currently carries current or candidate global
// evidence.  Only the shipped row should inherit this synthetic blocker.
$integrationRegistry['evidence']['status'] = 'candidate';
foreach ($integrationRegistry['manifests'] as &$integrationManifestClaim) {
    $integrationManifestClaim['evidence']['status'] = 'candidate';
}
unset($integrationManifestClaim);
foreach ($integrationRegistry['profiles'] as &$integrationProfileClaim) {
    $integrationProfileClaim['evidence']['status'] = 'candidate';
}
unset($integrationProfileClaim);
cert_write_canon($integrationManifests . '/capabilities/registry.json', $integrationRegistry);
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
    $providerDiagnosticsAreSecretFree = static function (array $report, array $planRows) use ($providerSite): bool {
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
        return $status['ok'] === false;
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
        && $providerDiagnosticsAreSecretFree($throwingCapabilitiesReport, $throwingCapabilitiesPlanRows)
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
        && $providerDiagnosticsAreSecretFree($throwingIdentityReport, $throwingIdentityPlanRows)
        && $GLOBALS['cert_provider_invocations'] === 0,
        'an identity()-throwing plugin registration remains a structured, redacted global/selected-plan blocker without invoking it'
    );

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
    cert_check(
        CodeDeploy::dispositionBlockers(['resolved_adapters' => $pinnedResolved]) === [],
        'host promotion accepts the exact pinned current external claim'
    );

    $policySnapshot = $pinnedPolicy->export_snapshot();
    $frozenPolicy = Policy::from_snapshot($policySnapshot);
    cert_check(
        RepositoryCompiler::resolved_adapters($frozenPolicy) === $pinnedResolved,
        'Policy snapshot v5 re-verifies the certificate and reconstructs the exact source/digest/capability row'
    );
    $missingFrozenCertificate = $policySnapshot;
    unset($missingFrozenCertificate['adapter_sources']['certificates']['site-demo']);
    cert_expect_throw(
        static fn() => Policy::from_snapshot($missingFrozenCertificate),
        'malformed',
        'a frozen certified disposition cannot survive deletion of its certificate envelope'
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
    cert_check(
        ($mixedReport['evidence_scope'] ?? null) === 'per_manifest'
        && $mixedReport['evidence'] === null
        && ($mixedRows['site-demo']['evidence_scope'] ?? null) === 'site_certificate'
        && ($mixedRows['site-demo']['verdict']['status'] ?? null) === 'certified'
        && ($mixedRows['core']['evidence_scope'] ?? null) === 'shipped_registry'
        && ($mixedRows['core']['verdict']['status'] ?? null) === 'blocked',
        'mixed reporting evaluates the signed site row against its own current evidence while shipped candidate evidence blocks only the shipped row'
    );
    $shippedOnly = Policy::load(null, ['core']);
    $shippedOnlyResolved = RepositoryCompiler::resolved_adapters($shippedOnly);
    $mixedResolved = RepositoryCompiler::resolved_adapters($mixedPolicy);
    cert_check(
        ($mixedResolved[0]['digest'] ?? null) === ($shippedOnlyResolved[0]['digest'] ?? null)
        && !array_key_exists('evidence_scope', $shippedOnly->capability_report(['operation' => 'promote'])),
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
    'current agent-owned authority record',
    'a current revoked authority invalidates frozen certification'
);
$keys->{'review-key'}['status'] = 'trusted';
cert_write_canon($agent . '/capabilities/adapter-authorities.json', $authorities);

$changedPlatform = $platform;
$changedPlatform['branchable_state'] = 'a different current platform boundary';
cert_write_canon($agent . '/capabilities/registry.json', [
    'format' => 'duo-capability-registry/v1',
    'platform' => $changedPlatform,
]);
cert_expect_throw(
    static fn() => AdapterCertification::verifyFrozen($agent, 'site-demo', $manifest, $envelope),
    'platform boundary',
    'a current platform mutation invalidates frozen certification'
);
cert_write_canon($agent . '/capabilities/registry.json', [
    'format' => 'duo-capability-registry/v1',
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
cert_expect_throw(
    static fn() => AdapterCertification::verifyFile($agent, $site, 'site-demo', $changedManifest, $certPath),
    'does not bind',
    'live verification rejects a changed canonical/raw source adapter'
);
cert_write_canon($site . '/adapters/site-demo.json', $manifest);

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
cert_write($site . '/adapters/certifications/site-demo.JSON', $certificateRaw);
cert_expect_throw(
    static fn() => AdapterCertification::verifyDirectory($agent, $site),
    'only direct',
    'case-variant certificate extensions are fatal rather than ignored'
);
unlink($site . '/adapters/certifications/site-demo.JSON');
cert_write($site . '/adapters/certifications/nested/other.json', $certificateRaw);
cert_expect_throw(
    static fn() => AdapterCertification::verifyDirectory($agent, $site),
    'only direct',
    'nested certificate paths are fatal rather than ignored'
);
cert_remove_tree($site . '/adapters/certifications/nested');

$emptySite = $root . '/empty-site';
mkdir($emptySite, 0777, true);
cert_check(AdapterCertification::verifyDirectory($agent, $emptySite) === [], 'absence of certifications remains an uncertified, nonfatal state');

if ($failures !== 0) {
    fwrite(STDERR, "\n$failures site-adapter-certification regression assertion(s) failed\n");
    exit(1);
}

echo "\nsite-adapter-certification regression passed\n";
