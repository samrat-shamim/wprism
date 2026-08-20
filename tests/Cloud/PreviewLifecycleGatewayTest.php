<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\CommandRunner;
use Duo\Cloud\ControlAuthority;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\PreviewLifecycleGateway;
use Duo\Cloud\PreviewSlotLifecycle;
use Duo\Cloud\ReviewedPreviewBaseProvider;
use Duo\Cloud\RepositorySyncRuntime;
use Duo\Cloud\WorkloadRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/PreviewLifecycleGateway.php';

/** @internal */
final class GatewayNoopCommandRunner implements CommandRunner {
    public function run(array $request): array {
        throw new \RuntimeException('lifecycle authentication must never dispatch a workload command');
    }
}

/** @internal */
final class GatewayRecordingRuntime implements WorkloadRuntime, ReviewedPreviewBaseProvider, RepositorySyncRuntime {
    /** @var list<string> */
    public array $calls = [];
    public bool $corruptContainmentHash = false;
    /** @var array<string,array{present:bool,url:string}> */
    private array $resources = [];

    public function reviewedBaseDescriptor(): array {
        return [
            'format' => 'duo-reviewed-preview-base/v1',
            'image_digest' => 'sha256:' . hash('sha256', 'gateway reviewed image'),
            'platform_fingerprint_sha256' => hash('sha256', 'gateway platform'),
            'review_receipt_sha256' => hash('sha256', 'gateway review'),
        ];
    }

    public function reviewedBaseContainmentDescriptor(): array {
        $basis = [
            'egress_evidence' => 'host-nft-input-forward-default-deny-readback/v1',
            'format' => 'duo-reviewed-preview-base-containment/v1',
            'image_reference' => 'registry.example.test/duo/wordpress@'
                . $this->reviewedBaseDescriptor()['image_digest'],
            'reviewed_base' => $this->reviewedBaseDescriptor(),
            'routing_evidence' => 'credential-free-route-authority-readback/v1',
            'runtime_configuration_sha256' => hash('sha256', 'gateway runtime configuration'),
            'seccomp_profile_sha256' => hash('sha256', 'gateway seccomp profile'),
            'secrets_evidence' => 'generation-private-files-readonly-mount-readback/v1',
            'storage_evidence' => 'dm-crypt-xfs-project-quota-exact-readback/v1',
        ];
        $hash = hash(
            'sha256',
            "duo-reviewed-preview-base-containment/v1\0" . CanonicalJson::encode($basis)
        );
        return ['descriptor_sha256' => $this->corruptContainmentHash ? str_repeat('0', 64) : $hash]
            + $basis;
    }

    public function repositoryAuthorityDescriptor(): array {
        $basis = [
            'credential_helper_sha256' => hash('sha256', 'gateway credential helper'),
            'format' => 'duo-cloud-repository-authority/v1',
            'ref_prefix' => 'refs/heads/duo-preview/',
            'remote_url_sha256' => hash('sha256', 'gateway remote URL'),
        ];
        return ['descriptor_sha256' => hash(
            'sha256',
            "duo-cloud-repository-authority/v1\0" . CanonicalJson::encode($basis)
        )] + $basis;
    }

    public function syncRepository(array $authority, array $repository): array {
        $this->calls[] = 'syncRepository';
        return ['evidence_sha256' => self::evidence('syncRepository', $authority + $repository)];
    }

    public function inspect(array $lease): array {
        $this->calls[] = 'inspect';
        $resource = $this->resources[$lease['resource_id']] ?? [
            'present' => false,
            'url' => 'https://preview.example.invalid',
        ];
        return [
            'evidence_sha256' => self::evidence('inspect', $lease),
            'presence' => $resource['present'] ? 'present' : 'absent',
            'url' => $resource['url'],
        ];
    }

    public function provision(array $lease): array {
        $this->calls[] = 'provision';
        $url = 'https://preview.example.invalid';
        $this->resources[$lease['resource_id']] = ['present' => true, 'url' => $url];
        return ['evidence_sha256' => self::evidence('provision', $lease), 'url' => $url];
    }

    public function restoreSnapshot(array $authority, array $snapshot): array {
        $this->calls[] = 'restoreSnapshot';
        return ['evidence_sha256' => self::evidence('restoreSnapshot', $authority + $snapshot)];
    }

    public function materializeRepository(array $authority, array $repository): array {
        $this->calls[] = 'materializeRepository';
        return ['evidence_sha256' => self::evidence('materializeRepository', $authority + $repository)];
    }

    public function configureUrl(array $authority, string $url): array {
        $this->calls[] = 'configureUrl';
        return ['evidence_sha256' => self::evidence('configureUrl', $authority + ['url' => $url])];
    }

    public function revokeRouting(array $authority): array {
        $this->calls[] = 'revokeRouting';
        return ['evidence_sha256' => self::evidence('revokeRouting', $authority)];
    }

    public function revokeExecution(array $authority): array {
        $this->calls[] = 'revokeExecution';
        return ['evidence_sha256' => self::evidence('revokeExecution', $authority)];
    }

    public function resumeExecution(array $authority): array {
        $this->calls[] = 'resumeExecution';
        return ['evidence_sha256' => self::evidence('resumeExecution', $authority)];
    }

    public function deleteState(array $authority): array {
        $this->calls[] = 'deleteState';
        unset($this->resources[$authority['resource_id']]);
        return ['evidence_sha256' => self::evidence('deleteState', $authority)];
    }

    public function verifyAbsent(array $lease): array {
        $this->calls[] = 'verifyAbsent';
        return [
            'absence_proof_sha256' => self::evidence('verifyAbsent', $lease),
            'absent' => !isset($this->resources[$lease['resource_id']]),
        ];
    }

    /** @param array<string,mixed> $value */
    private static function evidence(string $stage, array $value): string {
        return hash('sha256', $stage . "\0" . CanonicalJson::encode($value));
    }
}

#[CoversNothing]
final class PreviewLifecycleGatewayTest extends TestCase {
    private string $scratch;
    private string $controllerSecret;
    private string $controllerPublic;
    private string $serviceSecret;
    private string $servicePublic;
    private GatewayRecordingRuntime $runtime;
    private ControlAuthority $controllerKeys;
    private PreviewLifecycleGateway $gateway;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-preview-lifecycle-gateway-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));

        $controller = sodium_crypto_sign_keypair();
        $this->controllerSecret = sodium_crypto_sign_secretkey($controller);
        $this->controllerPublic = sodium_crypto_sign_publickey($controller);
        $service = sodium_crypto_sign_keypair();
        $this->serviceSecret = sodium_crypto_sign_secretkey($service);
        $this->servicePublic = sodium_crypto_sign_publickey($service);

        $this->runtime = new GatewayRecordingRuntime();
        $lifecycle = new PreviewSlotLifecycle(
            new FileAuthorityStore($this->scratch . '/lifecycle.json'),
            $this->runtime,
            null,
            static fn (): int => 1893456000
        );
        $this->controllerKeys = new ControlAuthority(
            new FileAuthorityStore($this->scratch . '/commands.json'),
            new GatewayNoopCommandRunner(),
            'service-key-v1',
            $this->serviceSecret
        );
        $this->controllerKeys->registerRequestKey(
            'controller-key-v1',
            'tenant-a',
            'site-a',
            $this->controllerPublic
        );
        $this->gateway = new PreviewLifecycleGateway(
            $lifecycle,
            $this->controllerKeys,
            'service-key-v1',
            $this->serviceSecret
        );
    }

    protected function tearDown(): void {
        unset($this->gateway, $this->controllerKeys);
        foreach (glob($this->scratch . '/*') ?: [] as $path) {
            if (is_file($path)) {
                self::assertTrue(unlink($path));
            }
        }
        if (isset($this->scratch) && is_dir($this->scratch)) {
            self::assertTrue(rmdir($this->scratch));
        }
        foreach (['controllerSecret', 'serviceSecret'] as $property) {
            if (isset($this->{$property}) && $this->{$property} !== '') {
                sodium_memzero($this->{$property});
            }
        }
    }

    public function testSignedCapabilitiesAndCreateReplayCrossTheExactLifecycleBoundary(): void {
        $capabilities = $this->response($this->gateway->handle($this->request('capabilities', [])));
        self::assertSame('ok', $capabilities['status']);
        self::assertSame('capabilities', $capabilities['action']);
        self::assertContains('environment.create', $capabilities['result']['capabilities']);
        self::assertContains('environment.sleep', $capabilities['result']['capabilities']);
        self::assertContains('environment.wake', $capabilities['result']['capabilities']);
        self::assertContains('snapshot.set.restore', $capabilities['result']['capabilities']);
        self::assertContains('repository.sync', $capabilities['result']['capabilities']);
        self::assertSame(
            CanonicalJson::encode($this->runtime->repositoryAuthorityDescriptor()),
            CanonicalJson::encode($capabilities['result']['repository_authority'])
        );
        self::assertSame(
            $this->runtime->reviewedBaseContainmentDescriptor(),
            $capabilities['result']['reviewed_base_containment']
        );
        self::assertSame([], $this->runtime->calls);

        $request = $this->request('create', [
            'intent_sha256' => hash('sha256', 'preview intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $firstBytes = $this->gateway->handle($request);
        $secondBytes = $this->gateway->handle($request);
        self::assertSame($firstBytes, $secondBytes);
        $created = $this->response($firstBytes);
        self::assertSame('present', $created['result']['presence']);
        self::assertSame(1, $created['result']['lease_generation']);
        self::assertSame(['verifyAbsent', 'provision', 'inspect'], $this->runtime->calls);

        $identity = [
            'expected_environment_identity' => $created['result']['environment_identity'],
            'expected_lease_generation' => $created['result']['lease_generation'],
            'expected_lease_id' => $created['result']['lease_id'],
            'expected_ownership_receipt_sha256' => $created['result']['ownership_receipt_sha256'],
            'expected_resource_id' => $created['result']['resource_id'],
        ];
        $acquired = $this->response($this->gateway->handle($this->request(
            'mutation-acquire',
            $identity + ['mutation_owner' => 'duo-env-materialize-20260818-120000-aaaaaaaaaaaaaaaaaaaaaaaa']
        )));
        $mutation = [
            'expected_mutation_generation' => $acquired['result']['mutation_generation'],
            'expected_mutation_id' => $acquired['result']['mutation_id'],
            'expected_mutation_owner' => $acquired['result']['mutation_owner'],
            'expected_mutation_receipt_sha256' => $acquired['result']['mutation_receipt_sha256'],
        ];
        $sleepRequest = $this->request('sleep', $identity + $mutation);
        $sleepFirst = $this->gateway->handle($sleepRequest);
        self::assertSame($sleepFirst, $this->gateway->handle($sleepRequest));
        self::assertSame('asleep', $this->response($sleepFirst)['result']['sleep_state']);
        $wakeRequest = $this->request('wake', $identity + $mutation);
        $wakeFirst = $this->gateway->handle($wakeRequest);
        self::assertSame($wakeFirst, $this->gateway->handle($wakeRequest));
        self::assertSame('awake', $this->response($wakeFirst)['result']['sleep_state']);
        self::assertSame([
            'verifyAbsent', 'provision', 'inspect', 'revokeRouting', 'revokeExecution',
            'resumeExecution', 'configureUrl', 'inspect',
        ], $this->runtime->calls);
    }

    public function testForeignTamperedRevokedAndMalformedRequestsExecuteNoRuntimeWork(): void {
        $before = $this->runtime->calls;

        $foreign = $this->payload('create', [
            'intent_sha256' => hash('sha256', 'foreign'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $foreign['site_id'] = 'site-b';
        $foreign['request_id'] = self::requestId($foreign);
        $this->assertRefused(
            fn (): string => $this->gateway->handle($this->signed($foreign)),
            'not active for the signed tenant and site'
        );

        $changed = $this->payload('create', [
            'intent_sha256' => hash('sha256', 'first'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $changed['input']['intent_sha256'] = hash('sha256', 'changed after request id');
        $this->assertRefused(
            fn (): string => $this->gateway->handle($this->signed($changed)),
            'does not bind its exact input'
        );

        $badSignature = CanonicalJson::decodeObject($this->request('capabilities', []));
        $badSignature['signature'] = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
        $this->assertRefused(
            fn (): string => $this->gateway->handle(CanonicalJson::encode($badSignature) . "\n"),
            'request signature is invalid'
        );

        $this->controllerKeys->revokeRequestKey('controller-key-v1', 'tenant-a', 'site-a');
        $this->assertRefused(
            fn (): string => $this->gateway->handle($this->request('capabilities', [])),
            'not active for the signed tenant and site'
        );
        self::assertSame($before, $this->runtime->calls);
    }

    public function testCapabilitiesRefuseUnverifiableRuntimeContainmentBeforeSlotWork(): void {
        $this->runtime->corruptContainmentHash = true;
        $this->assertRefused(
            fn (): string => $this->gateway->handle($this->request('capabilities', [])),
            'descriptor hash does not verify'
        );
        self::assertSame([], $this->runtime->calls);
    }

    /** @param array<string,mixed> $input */
    private function request(string $action, array $input): string {
        return $this->signed($this->payload($action, $input));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function payload(string $action, array $input): array {
        $payload = [
            'action' => $action,
            'environment' => 'preview',
            'format' => PreviewLifecycleGateway::REQUEST_FORMAT,
            'input' => $input,
            'operation_id' => '20260818-120000-aaaaaaaaaaaaaaaaaaaaaaaa',
            'request_id' => '',
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
        $payload['request_id'] = self::requestId($payload);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private static function requestId(array $payload): string {
        return hash(
            'sha256',
            "duo-cloud-preview-lifecycle/v1\0{$payload['tenant_id']}\0{$payload['site_id']}\0"
                . "{$payload['environment']}\0{$payload['operation_id']}\0{$payload['action']}\0"
                . hash('sha256', CanonicalJson::encode($payload['input']))
        );
    }

    /** @param array<string,mixed> $payload */
    private function signed(array $payload): string {
        return CanonicalJson::encode([
            'format' => PreviewLifecycleGateway::ENVELOPE_FORMAT,
            'key_id' => 'controller-key-v1',
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($payload),
                $this->controllerSecret
            )),
        ]) . "\n";
    }

    /** @return array<string,mixed> */
    private function response(string $bytes): array {
        $envelope = CanonicalJson::decodeObject($bytes);
        self::assertSame(PreviewLifecycleGateway::ENVELOPE_FORMAT, $envelope['format']);
        self::assertSame('service-key-v1', $envelope['key_id']);
        $signature = base64_decode((string) $envelope['signature'], true);
        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            CanonicalJson::encode($envelope['payload']),
            $this->servicePublic
        ));
        return $envelope['payload'];
    }

    /** @param callable():mixed $call */
    private function assertRefused(callable $call, string $messageFragment): void {
        try {
            $call();
            self::fail('expected preview lifecycle gateway to refuse the request');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString($messageFragment, $error->getMessage());
        }
    }
}
