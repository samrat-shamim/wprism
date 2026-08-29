<?php
/**
 * End-to-end verified rollback on a real `LocalTransport`, against a real
 * `.wprism/control` root and the real WordPress-free recovery runtime.
 *
 * This is the suite that fails against the prior defect through the product
 * path. Before RecoveryTransport existed, none of this was reachable off SSH:
 * `VerifiedRollbackProfile::select()`, `RollbackAuthority::__construct()` and
 * `cli/wprism`'s promote dispatch were all typed on `SshTransport`, so a local
 * target with a complete provider set and a signing key still fell through to
 * the operator-directed whole-database dump path. Nothing about the protocol
 * required SSH: the runtime is WordPress-free PHP invoked with `captureRaw()`,
 * which every transport has, and the one genuine gap was where a mode-0600
 * canonical-JSON handoff can live — which is now a per-transport method.
 *
 * The providers are the same fixture processes the SSH suites drive
 * (sandbox/tests/fixtures/), so what differs from the certified SSH path here
 * is the transport and nothing else.
 *
 * Scope note: this proves the protocol converges on a local target. It is NOT
 * a certificate. `docs/ssh-rollback-certification.md` records a 198-case crash
 * matrix on a four-container two-sshd estate whose crash classes (SSH loss,
 * remote-command kill) have no local analogue; a local certificate is a
 * different document with a different matrix.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once dirname(__DIR__, 4) . '/recovery/rollback-control.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/LocalTransport.php';
require_once dirname(__DIR__, 4) . '/cli/src/Recovery/RollbackAuthority.php';
require_once dirname(__DIR__, 4) . '/cli/src/Recovery/VerifiedRollbackProfile.php';
require_once dirname(__DIR__, 4) . '/cli/src/Recovery/RecoveryClaim.php';
require_once dirname(__DIR__, 4) . '/cli/src/Recovery/RecoveryProfileSelection.php';

use WPrism\Canon;
use WPrism\Orchestrator\LocalTransport;
use WPrism\Orchestrator\RecoveryClaim;
use WPrism\Orchestrator\RecoveryProfileSelection;
use WPrism\Orchestrator\RollbackAuthority;
use WPrism\Orchestrator\VerifiedRollbackProfile;
use WPrism\Recovery\RollbackControl;

$fixtures = dirname(__DIR__, 3) . '/tests/fixtures';
$tmp = sys_get_temp_dir() . '/wprism-local-verified-' . bin2hex(random_bytes(8));

function lvr_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            lvr_remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

function lvr_write(string $path, string $bytes, int $mode = 0600): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents($path, $bytes);
    chmod($path, $mode);
}

/** The compiled `code` descriptor a plan carries, with its own revision digest. */
function lvr_code_inventory(string $fileHash): array {
    $inventory = [
        'files' => [['path' => 'plugins/acme/acme.php', 'sha256' => $fileHash]],
        'format' => 'wprism-code/v1',
        'layout' => 'wp-content',
        'owned_roots' => ['plugins/acme'],
        'plugin_main_files' => [
            ['basename' => 'acme/acme.php', 'path' => 'plugins/acme/acme.php', 'sha256' => $fileHash],
        ],
        'source' => 'code/wp-content',
        'theme_slugs' => [],
        'theme_templates' => [],
    ];
    $inventory['code_revision'] = hash('sha256', Canon::encode($inventory));
    return $inventory;
}

$keyId = 'local-verified-key';
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);

try {
    mkdir($tmp, 0700, true);
    $repo = $tmp . '/site';
    $root = $repo . '/.wprism/control';
    $initial = RollbackControl::initialize($root);
    RollbackControl::installPublicKey($root, $keyId, base64_encode($public));

    // Adoption installs exactly these runtime files under .wprism/control on
    // every AdoptionTransport (cli/src/Onboarding/Adopt.php); the suite stages
    // them directly so it needs no live adopt transaction.
    $runtimeDir = $root . '/recovery-runtime';
    mkdir($runtimeDir, 0700, true);
    foreach ([
        'CanonicalJson.php', 'AtomicStore.php', 'ProtocolLock.php', 'ProviderClient.php',
        'rollback-control.php', 'RecoveryExecutor.php', 'CheckpointBundle.php', 'CodeRelease.php',
        'UploadBundle.php', 'EffectBundle.php',
    ] as $file) {
        copy(dirname(__DIR__, 4) . '/recovery/' . $file, $runtimeDir . '/' . $file);
    }

    // ------------------------------------------------------------ providers
    $checkpointState = $tmp . '/checkpoint-state';
    $dbExport = $tmp . '/database-export.sql';
    $kmsKey = $tmp . '/kms.key';
    lvr_write($dbExport, "CREATE TABLE prior_state (id INT);\nINSERT INTO prior_state VALUES (7);\n");
    lvr_write($kmsKey, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)) . "\n");

    $releaseRoot = $tmp . '/releases';
    $codePointer = $tmp . '/code-pointer';
    $codeState = $tmp . '/code-state';
    lvr_write($releaseRoot . '/release-prior/wp-content/plugins/acme/acme.php', "<?php echo 'prior';\n", 0644);
    lvr_write($releaseRoot . '/release-desired-1/wp-content/plugins/acme/acme.php', "<?php echo 'desired';\n", 0644);
    lvr_write($codePointer, "release-prior\n");

    $uploads = $tmp . '/uploads';
    $offload = $tmp . '/offload';
    $media = $tmp . '/media';
    $uploadState = $tmp . '/upload-state';
    $uploadKey = $tmp . '/upload.key';
    lvr_write($uploadKey, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    lvr_write($uploads . '/2026/08/photo.jpg', "prior-upload", 0644);
    mkdir($offload, 0700, true);
    $desiredUpload = "desired-upload";
    $mediaSha = hash('sha256', $desiredUpload);
    lvr_write($media . '/' . $mediaSha . '.jpg', $desiredUpload, 0644);

    $effectState = $tmp . '/effect-state';
    $effectTarget = $repo . '/wp-content/uploads/wprism-rollback-effect.txt';
    lvr_write($effectTarget, "prior-effect", 0644);

    $adapterArgv = [PHP_BINARY, $fixtures . '/recovery-adapter.php'];
    $adapters = [];
    foreach (['code_restore', 'database_restore', 'prior_verify', 'storage_restore'] as $name) {
        $adapters[$name] = $adapterArgv;
    }
    $recovery = [
        'adapters' => $adapters,
        'checkpoint_provider' => [PHP_BINARY, $fixtures . '/offline-checkpoint-provider.php', $checkpointState, $dbExport, $kmsKey],
        'code_release_provider' => [PHP_BINARY, $fixtures . '/code-release-provider.php', $codeState, $releaseRoot, $codePointer],
        'effect_provider' => [PHP_BINARY, $fixtures . '/effect-provider.php', $effectState, $repo],
        'exclusion_provider' => [PHP_BINARY, $fixtures . '/recovery-exclusion-provider.php', $tmp . '/exclusion.json'],
        'timeout_seconds' => 30,
        'upload_provider' => [PHP_BINARY, $fixtures . '/upload-provider.php', $uploadState, $uploads, $offload, $media, $uploadKey],
    ];
    $targetConfig = $recovery + ['format' => 'wprism-recovery-config/v1'];
    ksort($targetConfig, SORT_STRING);
    $configPath = $tmp . '/recovery-config.json';
    lvr_write($configPath, RollbackControl::canonical($targetConfig) . "\n");
    \WPrism\Recovery\RecoveryExecutor::configureFromFile($root, $configPath);

    $signingKeyPath = $tmp . '/signing.key';
    lvr_write($signingKeyPath, base64_encode($secret) . "\n");

    // ----------------------------------------------------- the environment
    // `_machine_local` is the loader-owned provenance flag only an untracked
    // .wprism-envs.json can carry (cli/src/Environment/Registry.php:125); without
    // it LocalTransport refuses to arm the authority at all.
    $envConfig = [
        '_machine_local' => true,
        'repo_path' => $repo,
        'rollback_key_id' => $keyId,
        'rollback_recovery' => $recovery,
        'rollback_signing_key' => $signingKeyPath,
        'transport' => 'local',
        'verified_rollback' => [
            'claim_ttl_seconds' => 120,
            'encryption_key_id' => 'kms-local-fixture',
            'retention_seconds' => 86400,
        ],
        'wp_path' => $tmp . '/wordpress',
    ];
    $transport = new LocalTransport('local-verified', $envConfig);
    wprism_check(
        $transport->carriesRollbackAuthority(),
        'a machine-local environment with a signing key carries a rollback authority'
    );

    // ------------------------------------------------------------- the plan
    $desiredFileHash = (string) hash_file('sha256', $releaseRoot . '/release-desired-1/wp-content/plugins/acme/acme.php');
    $code = lvr_code_inventory($desiredFileHash);
    $plan = [
        'artifact_hash' => hash('sha256', 'local-verified-artifact'),
        'code' => $code,
        'effects_inventory' => [
            [
                'effect' => [
                    'adapter' => [
                        'id' => 'fixture-file',
                        'inverse' => 'restore-bytes',
                        'inverse_inputs' => ['path', 'prior_sha256'],
                        'verifier' => 'fresh-readback',
                        'verifier_inputs' => ['path', 'prior_sha256'],
                        'version' => '1.0.0',
                    ],
                    'id' => 'lifecycle-file',
                    'kind' => 'filesystem',
                    'mode' => 'reversible',
                    'selector' => [
                        'scope' => 'external',
                        'type' => 'path',
                        'value' => 'wp-content/uploads/wprism-rollback-effect.txt',
                    ],
                ],
                'manifest' => 'rollback-fixture',
                'phase' => 'lifecycle',
                'source' => 'lifecycle_effects',
            ],
            [
                'effect' => [
                    'id' => 'rebuild-table',
                    'kind' => 'database',
                    'mode' => 'restorable',
                    'selector' => [
                        'scope' => 'database_checkpoint',
                        'type' => 'table',
                        'value' => 'wprism_local_state',
                    ],
                ],
                'manifest' => 'rollback-fixture',
                'phase' => 'rebuild',
                'source' => 'provider:rollback-fixture/rebuild_table',
            ],
            [
                'effect' => [
                    'id' => 'prevent-http',
                    'kind' => 'http',
                    'mode' => 'prevented',
                    'prevention' => 'receipt_outbox',
                    'selector' => [
                        'scope' => 'external',
                        'type' => 'url_prefix',
                        'value' => 'https://rollback.invalid/hooks/',
                    ],
                ],
                'manifest' => 'rollback-fixture',
                'phase' => 'lifecycle',
                'source' => 'lifecycle_effects',
            ],
        ],
        'resolved_adapters' => [['name' => 'rollback-fixture', 'version' => '1.0.0']],
        'uploads_inventory' => [[
            'attachment_uuid' => '11111111-1111-4111-8111-111111111111',
            'derivative_basename_prefix' => 'photo-',
            'derivative_directory' => '2026/08',
            'media_blob' => $mediaSha . '.jpg',
            'original_path' => '2026/08/photo.jpg',
            'original_sha256' => $mediaSha,
        ]],
    ];

    // -------------------------------------------------------------- select
    $selection = VerifiedRollbackProfile::select($transport, $plan);
    wprism_check_same(
        true,
        $selection['automatic'],
        'the verified profile selects automatic off SSH once the capability interface, not the transport class, decides'
    );
    wprism_check_same(
        'all verified rollback capabilities are ready',
        $selection['reason'],
        'the selection reason is the same sentence the SSH path prints'
    );
    $proof = RecoveryProfileSelection::proveVerified($transport, $plan, $selection['status']);
    wprism_check_same(
        RecoveryClaim::VERIFIED_AUTOMATIC,
        $proof['profile'],
        'the release claim an operator authorizes names verified-automatic, the same profile promote will run'
    );

    // --------------------------------------------------------------- claim
    $profile = new VerifiedRollbackProfile($transport);
    $owner = 'controller:local-verified';
    $claimant = 'local-verified-worker';
    // The signed chain refuses an event whose timestamp moved backwards, and
    // every later transition stamps itself with the real clock, so the claim
    // has to be stamped from the same clock rather than a frozen fixture date.
    $claim = $profile->claim($plan, $owner, $claimant, gmdate('Y-m-d\TH:i:s\Z'));
    wprism_check_same('prepared', $claim['status']['state'], 'the claim publishes a signed prepared receipt on a local target');
    wprism_check_same(
        1,
        (int) $claim['status']['generation'],
        'the local target advances to generation 1 under the signed receipt'
    );
    wprism_check(
        preg_match('/^[a-f0-9]{64}$/', (string) ($claim['receipt']['checkpoint_sha256'] ?? '')) === 1,
        'the encrypted database checkpoint is prepared and bound into the receipt before any mutation'
    );
    // `exclusion_state` lives on the DECORATED status the controller reads
    // back, not on the raw signed control response a claim/append returns.
    wprism_check_same(
        'held',
        (string) (RollbackAuthority::status($transport)['exclusion_state'] ?? ''),
        'the authority reserved the writer exclusion for the window itself, as the claim vocabulary promises'
    );

    // --------------------------------------------------- forward boundaries
    $promoting = $profile->startPromotion();
    wprism_check_same('promoting', $promoting['state'], 'promotion-start transitions the signed generation to promoting');
    $profile->keepalive();
    $afterKeepalive = RollbackAuthority::status($transport);
    wprism_check_same(
        ['promoting', 'held'],
        [(string) $afterKeepalive['state'], (string) ($afterKeepalive['exclusion_state'] ?? '')],
        'a keepalive renews the exclusion the authority holds without leaving promoting'
    );
    $codeSelected = $profile->selectCode();
    wprism_check_same(
        true,
        ($codeSelected['execution']['ok'] ?? false),
        'the desired code release is selected and independently verified through the target provider'
    );
    wprism_check_same(
        "release-desired-1\n",
        (string) file_get_contents($codePointer),
        'the atomic code pointer now names the desired release'
    );
    $uploadsApplied = $profile->applyUploads();
    wprism_check_same(
        true,
        ($uploadsApplied['execution']['ok'] ?? false),
        'the compiled upload inventory is published through the target provider'
    );
    wprism_check_same(
        $desiredUpload,
        (string) file_get_contents($uploads . '/2026/08/photo.jpg'),
        'the desired upload bytes are live before the injected failure'
    );

    // The lifecycle phase mutates its declared reversible effect. The provider
    // captured the before-image at prepare, so this is what the inverse has to
    // undo — restoring bytes that were never touched would prove nothing.
    lvr_write($effectTarget, 'mutated-effect', 0644);

    // ------------------------------------------------------------ rollback
    // The whole point of the profile: a failure after `promoting` converges to
    // the prior world through signed operations, never through the
    // operator-directed database dump.
    $rolledBack = $profile->rollback(true, true, true);
    wprism_check_same('rolled_back', $rolledBack['state'], 'the signed rollback reaches the rolled_back terminal state');
    wprism_check_same(true, (bool) ($rolledBack['terminal'] ?? false), 'the rolled_back generation is terminal');
    wprism_check_same(
        'released',
        (string) (RollbackAuthority::status($transport)['exclusion_state'] ?? ''),
        'the authority releases the exclusion it reserved, so no operator has to'
    );
    wprism_check_same(
        "release-prior\n",
        (string) file_get_contents($codePointer),
        'the code pointer is restored to the exact prior release'
    );
    wprism_check_same(
        'prior-upload',
        (string) file_get_contents($uploads . '/2026/08/photo.jpg'),
        'the prior upload bytes are restored from the encrypted before-image'
    );
    wprism_check_same(
        'prior-effect',
        (string) file_get_contents($effectTarget),
        'the reversible lifecycle effect is inverted back to its prior bytes'
    );

    // The controller read a target that never ran a shell it did not build:
    // every one of those transitions crossed captureRaw() and one mode-0600
    // handoff, which is the entire transport surface the protocol needs.
    $final = RollbackAuthority::status($transport);
    wprism_check_same(true, $final['ok'], 'the signed chain verifies from a fresh controller read');
    wprism_check_same('rolled_back', $final['state'], 'the fresh read agrees with the terminal state');
} finally {
    sodium_memzero($secret);
    lvr_remove_tree($tmp);
}

wprism_check_summary('local verified rollback');
