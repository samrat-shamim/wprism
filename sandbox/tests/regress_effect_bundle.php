<?php
declare(strict_types=1);

// DUO-3298: deterministic manifest grammar, pre-mutation automatic-profile
// refusal, receipt-bound outbox prevention, exact actual-effect reconciliation,
// and fresh-process inverse verification for a real lifecycle fixture.

define('DUO_SPEC_VERSION', 2);
require dirname(__DIR__, 2) . '/agent/src/Canon.php';
require dirname(__DIR__, 2) . '/agent/src/Code.php';
require dirname(__DIR__, 2) . '/agent/src/Policy.php';
require dirname(__DIR__, 2) . '/recovery/rollback-control.php';
require dirname(__DIR__, 2) . '/cli/src/Transport.php';
require dirname(__DIR__, 2) . '/cli/src/SshTransport.php';
require dirname(__DIR__, 2) . '/cli/src/RollbackAuthority.php';
require __DIR__ . '/fixtures/effect-lifecycle.php';

use Duo\Policy;
use Duo\Orchestrator\RollbackAuthority;
use Duo\Orchestrator\SshTransport;
use Duo\Recovery\EffectBundle;
use Duo\Recovery\RecoveryExecutor;
use Duo\Recovery\RollbackControl;

function eb_fail(string $m): never { fwrite(STDERR, "FAIL: $m\n"); exit(1); }
function eb_ok(bool $c, string $m): void { if (!$c) eb_fail($m); echo "ok: $m\n"; }
function eb_refuses(callable $f, string $m): void { try { $f(); } catch (Throwable $e) { echo "ok: $m [{$e->getMessage()}]\n"; return; } eb_fail($m); }
function eb_write(string $p, string $b, int $mode = 0600): void { if (!is_dir(dirname($p))) mkdir(dirname($p), 0700, true); if (file_put_contents($p, $b) !== strlen($b)) eb_fail("write $p"); chmod($p, $mode); }
function eb_remove(string $p): void { if (is_link($p) || is_file($p)) { @unlink($p); return; } if (!is_dir($p)) return; foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') eb_remove($p . '/' . $n); @rmdir($p); }
function eb_signed(string $root, array $payload, string $kind, string $keyId, string $secret): array { $p = tempnam(sys_get_temp_dir(), 'duo-effect-request-'); if ($p === false) eb_fail('temp'); eb_write($p, RollbackControl::canonical(RollbackControl::sign($payload, $keyId, $secret)) . "\n"); try { return $kind === 'effects' ? EffectBundle::handleRequest($root, $p) : RecoveryExecutor::handleExclusionRequest($root, $p); } finally { @unlink($p); } }
function eb_authority(string $root, array $event, ?array $receipt, string $keyId, string $secret): array { $request = ['action' => $receipt === null ? 'append' : 'claim', 'event' => RollbackControl::sign($event, $keyId, $secret), 'receipt' => $receipt === null ? null : RollbackControl::sign($receipt, $keyId, $secret)]; $p = tempnam(sys_get_temp_dir(), 'duo-effect-authority-'); if ($p === false) eb_fail('temp'); eb_write($p, RollbackControl::canonical($request) . "\n"); try { return RollbackControl::handleRequest($root, $p); } finally { @unlink($p); } }
function eb_event(array $receipt, array $status, string $state, string $opStatus, string $opId, int $attempt, string $input, string $result, string $time): array { $seconds = strtotime($time); return ['artifact_hash' => $receipt['artifact_hash'], 'attempt' => $attempt, 'claim_epoch' => 1, 'claim_expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $seconds + 60), 'claimant' => 'worker-a', 'format' => RollbackControl::EVENT_FORMAT, 'generation' => 1, 'input_sha256' => $input, 'operation_id' => $opId, 'operation_status' => $opStatus, 'owner' => $receipt['owner'], 'previous_event_sha256' => $status['head_event_sha256'] ?? str_repeat('0', 64), 'receipt_id' => $receipt['receipt_id'], 'result_sha256' => $result, 'sequence' => isset($status['sequence']) ? (int) $status['sequence'] + 1 : 1, 'signing_key_id' => $receipt['signing_key_id'], 'state' => $state, 'target_id' => $receipt['target_id'], 'timestamp' => $time]; }
function eb_policy(array $manifest): Policy { return Policy::from_snapshot(['format' => 'duo-policy-snapshot/v1', 'manifests' => [$manifest], 'site' => ['manifests' => ['effect-probe'], 'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []], 'spec_version' => 2]]); }

$tmp = sys_get_temp_dir() . '/duo-effect-bundle-' . bin2hex(random_bytes(8));
$pair = sodium_crypto_sign_keypair(); $secret = sodium_crypto_sign_secretkey($pair); $public = sodium_crypto_sign_publickey($pair); $keyId = 'effect-test';
try {
    mkdir($tmp, 0700, true);
    $manifest = [
        'lifecycle_effects' => [
            ['id' => 'probe-http', 'kind' => 'http', 'mode' => 'prevented', 'prevention' => 'receipt_outbox', 'selector' => ['scope' => 'external', 'type' => 'url_prefix', 'value' => 'https://duo-promotion-probe.invalid/']],
            ['adapter' => ['id' => 'fixture-file', 'inverse' => 'restore-bytes', 'inverse_inputs' => ['path', 'prior_sha256'], 'verifier' => 'fresh-readback', 'verifier_inputs' => ['path', 'prior_sha256'], 'version' => '1.0.0'], 'id' => 'probe-file', 'kind' => 'filesystem', 'mode' => 'reversible', 'selector' => ['scope' => 'external', 'type' => 'path', 'value' => 'wp-content/uploads/duo-promotion-probe.txt']],
        ],
        'name' => 'effect-probe', 'plugin' => 'duo-promotion-probe/duo-promotion-probe.php',
        'rebuilders' => [['command' => 'duo probe rebuild', 'effects' => [['id' => 'probe-db', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'options']]]]],
        'spec_version' => 2, 'version_range' => ['max' => '2.0.0', 'min' => '1.0.0'],
    ];
    $inventory = eb_policy($manifest)->effects_inventory();
    eb_ok(count($inventory) === 8, 'compiled inventory includes lifecycle, rebuilder, and engine-owned effects deterministically');
    $missing = $manifest; unset($missing['lifecycle_effects']);
    $missingRows = eb_policy($missing)->effects_inventory();
    eb_ok(count(array_filter($missingRows, fn($r) => ($r['effect']['mode'] ?? '') === 'irreversible')) === 1, 'undeclared lifecycle effects become explicit automatic-profile blockers');
    foreach ([
        'unbounded selector' => function (array $m): array { $m['lifecycle_effects'][0]['selector']['value'] = 'https://example.test/*'; return $m; },
        'secret-shaped selector' => function (array $m): array { $m['lifecycle_effects'][0]['selector']['value'] = 'https://example.test/password'; return $m; },
        'unpinned inverse adapter' => function (array $m): array { $m['lifecycle_effects'][1]['adapter']['version'] = 'latest'; return $m; },
        'secret-shaped inverse input' => function (array $m): array { $m['lifecycle_effects'][1]['adapter']['inverse_inputs'] = ['access_token']; return $m; },
        'reporting observer declaration' => function (array $m): array { $m['lifecycle_effects'][0]['prevention'] = 'observer'; return $m; },
    ] as $label => $mutate) eb_refuses(fn() => eb_policy($mutate($manifest)), "manifest rejects $label before target contact");

    $root = $tmp . '/site/.duo/control'; $initial = RollbackControl::initialize($root); RollbackControl::installPublicKey($root, $keyId, base64_encode($public));
    $siteRoot = $tmp . '/site'; $targetFile = $siteRoot . '/wp-content/uploads/duo-promotion-probe.txt'; eb_write($targetFile, "prior lifecycle bytes\n");
    $state = $tmp . '/effect-state'; $provider = __DIR__ . '/fixtures/effect-provider.php'; $exclusion = __DIR__ . '/fixtures/recovery-exclusion-provider.php'; $adapter = __DIR__ . '/fixtures/recovery-adapter.php';
    $adapters = []; foreach (['code_restore', 'database_restore', 'prior_verify', 'storage_restore'] as $name) $adapters[$name] = [PHP_BINARY, $adapter];
    $config = ['adapters' => $adapters, 'effect_provider' => [PHP_BINARY, $provider, $state, $siteRoot], 'exclusion_provider' => [PHP_BINARY, $exclusion, $tmp . '/exclusion.json'], 'format' => 'duo-recovery-config/v1', 'timeout_seconds' => 5];
    $configPath = $tmp . '/recovery.json'; eb_write($configPath, RollbackControl::canonical($config) . "\n"); RecoveryExecutor::configureFromFile($root, $configPath);
    touch($state . '.leak'); eb_refuses(fn() => RecoveryExecutor::probe($root), 'secret-shaped provider evidence is redacted and refused'); unlink($state . '.leak');
    $probe = RecoveryExecutor::probe($root); eb_ok(($probe['effects']['outbox_prevention'] ?? false) === true && ($probe['effects']['inverse_readback'] ?? false) === true, 'effect provider preflights outbox prevention and fresh inverse readback');
    $artifact = hash('sha256', 'artifact'); $receiptId = str_repeat('e', 48); $time = '2020-01-01T00:00:00Z'; $retention = '2020-01-02T00:00:00Z';
    $ex = eb_signed($root, ['action' => 'acquire', 'artifact_hash' => $artifact, 'claim_epoch' => 1, 'claimant' => 'worker-a', 'format' => 'duo-exclusion-request/v1', 'generation' => 1, 'owner' => 'controller:test', 'receipt_id' => $receiptId, 'target_id' => $initial['target_id'], 'timestamp' => $time], 'exclusion', $keyId, $secret);
    $prepare = ['action' => 'prepare', 'artifact_hash' => $artifact, 'claim_epoch' => 1, 'claimant' => 'worker-a', 'format' => 'duo-effect-bundle-request/v1', 'generation' => 1, 'inventory' => $inventory, 'owner' => 'controller:test', 'receipt_id' => $receiptId, 'retention_until' => $retention, 'target_id' => $initial['target_id'], 'timestamp' => $time];
    $blocked = $inventory; $blocked[0]['effect']['mode'] = 'irreversible'; unset($blocked[0]['effect']['adapter']);
    eb_refuses(fn() => eb_signed($root, array_replace($prepare, ['inventory' => $blocked]), 'effects', $keyId, $secret), 'irreversible effect blocks automatic promotion before provider or code mutation');
    eb_ok(!is_file($state . '.prepared'), 'blocked preflight never invokes provider prepare');
    $prepared = eb_signed($root, $prepare, 'effects', $keyId, $secret);
    eb_ok(preg_match('/^[a-f0-9]{64}$/', (string) $prepared['lifecycle_receipts_sha256']) === 1, 'provider-owned effect evidence is receipt-bindable');
    $receipt = ['adapter_versions_sha256' => hash('sha256', 'adapters'), 'artifact_hash' => $artifact, 'checkpoint_sha256' => hash('sha256', 'checkpoint'), 'claim_ttl_seconds' => 60, 'created_at' => $time, 'encryption_key_id' => 'kms-test', 'exclusion_token_sha256' => $ex['token_sha256'], 'format' => 'duo-rollback-receipt/v1', 'generation' => 1, 'ledger_session_sha256' => hash('sha256', 'ledger'), 'lifecycle_receipts_sha256' => $prepared['lifecycle_receipts_sha256'], 'owner' => 'controller:test', 'prior_code_descriptor_sha256' => hash('sha256', 'code'), 'prior_verifier_inputs_sha256' => hash('sha256', 'verify'), 'receipt_id' => $receiptId, 'resources_inventory_sha256' => hash('sha256', 'resources'), 'retention_until' => $retention, 'runtime_fingerprints_sha256' => hash('sha256', 'runtime'), 'signing_key_id' => $keyId, 'target_id' => $initial['target_id'], 'uploads_inventory_sha256' => hash('sha256', 'uploads')];
    $first = eb_event($receipt, [], 'prepared', 'state_transition', 'promotion-claim', 1, hash('sha256', RollbackControl::canonical($receipt)), str_repeat('0', 64), $time); $status = eb_authority($root, $first, $receipt, $keyId, $secret);
    $status = eb_authority($root, eb_event($receipt, $status, 'promoting', 'state_transition', 'promotion-start', 1, hash('sha256', 'promote'), str_repeat('0', 64), '2020-01-01T00:00:01Z'), null, $keyId, $secret);
    $httpActual = ['effect_id' => 'probe-http', 'kind' => 'http', 'manifest' => 'effect-probe', 'phase' => 'lifecycle', 'selector' => ['scope' => 'external', 'type' => 'url_prefix', 'value' => 'https://duo-promotion-probe.invalid/']];
    touch($state . '.report-only'); eb_refuses(fn() => EffectBundle::observe($root, $status, $httpActual), 'reporting-only lifecycle observer is never accepted as prevention'); unlink($state . '.report-only');
    duo_effect_fixture_activate($targetFile, fn(array $actual): array => EffectBundle::observe($root, $status, $actual));
    $observed = json_decode((string) file_get_contents($state . '.outbox'), true); eb_ok(is_array($observed) && is_file($state . '.outbox'), 'real lifecycle fixture prevents external effect into a receipt-bound outbox before mutating its declared file');
    $unknown = $httpActual; $unknown['effect_id'] = 'undeclared-http'; eb_refuses(fn() => EffectBundle::observe($root, $status, $unknown), 'runtime undeclared effect enters refusal without broadening authority');
    eb_ok(file_get_contents($targetFile) === "mutated lifecycle bytes\n", 'real lifecycle fixture emits its reversible non-DB mutation');
    $status = eb_authority($root, eb_event($receipt, $status, 'rollback_pending', 'state_transition', 'verification-failed', 1, hash('sha256', 'failure'), str_repeat('0', 64), '2020-01-01T00:00:02Z'), null, $keyId, $secret);
    $status = eb_authority($root, eb_event($receipt, $status, 'rolling_back', 'state_transition', 'rollback-start', 1, hash('sha256', 'rollback'), str_repeat('0', 64), '2020-01-01T00:00:03Z'), null, $keyId, $secret);
    $input = ['artifact_hash' => $artifact, 'effects_inventory_sha256' => $prepared['effects_inventory_sha256'], 'format' => 'duo-effect-operation/v1', 'generation' => 1, 'lifecycle_receipts_sha256' => $prepared['lifecycle_receipts_sha256'], 'operation' => 'restore_prior', 'owner' => 'controller:test', 'prior_evidence_sha256' => $prepared['prior_evidence_sha256'], 'receipt_id' => $receiptId, 'target_id' => $initial['target_id']];
    $inputPath = $tmp . '/effects-inverse.json'; eb_write($inputPath, RollbackControl::canonical($input) . "\n"); $inputHash = (string) hash_file('sha256', $inputPath);
    $status = eb_authority($root, eb_event($receipt, $status, 'rolling_back', 'prepared', 'effects_inverse', 1, $inputHash, str_repeat('0', 64), '2020-01-01T00:00:04Z'), null, $keyId, $secret);
    $inverse = RecoveryExecutor::execute($root, 'effects_inverse', 'effects_inverse', 1, 'worker-a', 1, $inputPath);
    eb_ok(file_get_contents($targetFile) === "prior lifecycle bytes\n" && preg_match('/^[a-f0-9]{64}$/', $inverse['result_sha256']) === 1, 'reversible non-DB lifecycle effect restores exact prior bytes and passes fresh-process verification');

    // Product controller path: compiled rows cross SSH, target preparation
    // owns the lifecycle receipt digest, and claim publication verifies it.
    $controllerHost = $tmp . '/controller-host'; $controllerRoot = $controllerHost . '/.duo/control';
    $controllerInitial = RollbackControl::initialize($controllerRoot); RollbackControl::installPublicKey($controllerRoot, $keyId, base64_encode($public));
    $runtime = $controllerRoot . '/recovery-runtime'; mkdir($runtime, 0700, true);
    foreach (['rollback-control.php','RecoveryExecutor.php','CheckpointBundle.php','CodeRelease.php','UploadBundle.php','EffectBundle.php'] as $file) copy(dirname(__DIR__, 2) . '/recovery/' . $file, $runtime . '/' . $file);
    $controllerSite = $controllerHost; eb_write($controllerSite . '/wp-content/uploads/duo-promotion-probe.txt', "controller prior\n");
    $controllerState = $tmp . '/controller-effects'; $controllerExclusion = $tmp . '/controller-exclusion.json';
    $controllerConfig = ['adapters' => $adapters, 'effect_provider' => [PHP_BINARY, $provider, $controllerState, $controllerSite], 'exclusion_provider' => [PHP_BINARY, $exclusion, $controllerExclusion], 'format' => 'duo-recovery-config/v1', 'timeout_seconds' => 5];
    $controllerConfigPath = $tmp . '/controller-recovery.json'; eb_write($controllerConfigPath, RollbackControl::canonical($controllerConfig) . "\n"); RecoveryExecutor::configureFromFile($controllerRoot, $controllerConfigPath);
    $signing = $tmp . '/controller-signing.key'; eb_write($signing, base64_encode($secret) . "\n");
    $fake = $tmp . '/fake-bin'; mkdir($fake, 0700); eb_write($fake . '/ssh', "#!/bin/sh\nshift\nexec /bin/sh -c \"\$1\"\n", 0700); eb_write($fake . '/scp', "#!/bin/sh\nsrc=\$1\ndest=\$2\ntarget=\${dest#*:}\ncp \"\$src\" \"\$target\"\n", 0700); putenv('PATH=' . $fake . ':' . getenv('PATH'));
    $transport = new SshTransport('effect-controller', ['transport' => 'ssh', 'host' => 'fixture-host', 'wp_path' => $tmp . '/unused-wordpress', 'repo_path' => $controllerHost, 'rollback_key_id' => $keyId, 'rollback_signing_key' => $signing, 'rollback_recovery' => ['adapters' => $adapters, 'effect_provider' => [PHP_BINARY, $provider, $controllerState, $controllerSite], 'exclusion_provider' => [PHP_BINARY, $exclusion, $controllerExclusion], 'timeout_seconds' => 5]]);
    $authority = new RollbackAuthority($transport);
    eb_refuses(fn() => $authority->claim(['effect_inventory' => $inventory, 'lifecycle_receipts_sha256' => hash('sha256', 'caller-owned')], 'controller-worker', '2020-02-01T00:00:00Z'), 'controller refuses caller-owned lifecycle receipt hash when effect provider is configured');
    $claim = $authority->claim(['adapter_versions_sha256' => hash('sha256', 'adapters'), 'artifact_hash' => hash('sha256', 'controller-artifact'), 'checkpoint_sha256' => hash('sha256', 'checkpoint'), 'claim_ttl_seconds' => 60, 'created_at' => '2020-02-01T00:00:00Z', 'effect_inventory' => $inventory, 'encryption_key_id' => 'manual-key', 'ledger_session_sha256' => hash('sha256', 'ledger'), 'owner' => 'controller:effect-test', 'prior_code_descriptor_sha256' => hash('sha256', 'code'), 'prior_verifier_inputs_sha256' => hash('sha256', 'verifier'), 'resources_inventory_sha256' => hash('sha256', 'resources'), 'retention_until' => '2020-02-02T00:00:00Z', 'runtime_fingerprints_sha256' => hash('sha256', 'runtime'), 'uploads_inventory_sha256' => hash('sha256', 'uploads')], 'controller-worker', '2020-02-01T00:00:00Z');
    eb_ok(($claim['status']['state'] ?? '') === 'prepared' && ($claim['receipt']['lifecycle_receipts_sha256'] ?? '') !== hash('sha256', 'caller-owned') && ($controllerInitial['target_id'] ?? '') === ($claim['receipt']['target_id'] ?? ''), 'SSH controller binds provider-owned prepared effect evidence before signed receipt publication');
    echo "PASS: rollback effect contracts are bounded, preflighted, reconciled, prevented, and freshly reversible\n";
} finally { sodium_memzero($secret); eb_remove($tmp); }
