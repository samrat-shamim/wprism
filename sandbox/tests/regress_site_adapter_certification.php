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
require_once __DIR__ . '/../../cli/src/CodeDeploy.php';

use Duo\AdapterCertification;
use Duo\Canon;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\Orchestrator\CodeDeploy;

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
    $environment = [
        'multisite' => false,
        'php' => '8.3.0',
        'wordpress' => '7.0.2',
    ];
    cert_write_canon($dir . '/environment.json', $environment);
    cert_write_canon($dir . '/ratification.json', $ratification);
    cert_write(
        $dir . '/results/site-conformance.json',
        cert_bundle_pretty([
            'exit_code' => 0,
            'schema_version' => 1,
            'test' => 'site-conformance',
            'verdict' => 'pass',
        ])
    );
    cert_write_canon($dir . '/diffs/site-conformance.json', ['changed' => [], 'status' => 'clean']);
    cert_write($dir . '/logs/site-conformance.txt', "site adapter conformance passed\n");

    $adapterPath = $site . '/adapters/site-demo.json';
    $boundInputs = $options['bound_inputs'] ?? [
        cert_descriptor($adapterPath, 'adapters/site-demo.json'),
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
            'certified_claims' => ['manifests.site-demo'],
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
cert_write_canon($integrationManifests . '/capabilities/registry.json', $integrationRegistry);
cert_write_canon($integrationManifests . '/capabilities/adapter-authorities.json', $authorities);
$originalCertificateRaw = (string) file_get_contents($certPath);

putenv('DUO_MANIFESTS_DIR=' . $integrationManifests);
try {
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
