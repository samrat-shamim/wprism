<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\CloudHttpResponse;
use Duo\Cloud\CloudHttpRouter;
use Duo\Cloud\CommandRunner;
use Duo\Cloud\ControlAuthority;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\OriginAuthority;
use Duo\Cloud\OriginControllerGateway;
use Duo\Cloud\OriginFileBlobStore;
use Duo\Cloud\OriginProtocol;
use Duo\Cloud\PreviewLifecycleGateway;
use Duo\Cloud\PreviewSlotLifecycle;
use Duo\Cloud\ReviewedPreviewBaseProvider;
use Duo\Cloud\RepositorySyncRuntime;
use Duo\Cloud\WorkloadRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/CloudHttpRouter.php';
require_once DUO_REPO_ROOT . '/cloud/src/OriginFileBlobStore.php';

/** @internal */
final class HttpRouterRecordingRunner implements CommandRunner {
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function run(array $request): array {
        $this->calls[] = $request;
        return ['exit' => 0, 'stderr' => '', 'stdout' => "routed\n"];
    }
}

/** @internal */
final class HttpRouterInertRuntime implements WorkloadRuntime, ReviewedPreviewBaseProvider, RepositorySyncRuntime {
    public int $calls = 0;

    public function reviewedBaseDescriptor(): array {
        return [
            'format' => 'duo-reviewed-preview-base/v1',
            'image_digest' => 'sha256:' . hash('sha256', 'router reviewed image'),
            'platform_fingerprint_sha256' => hash('sha256', 'router platform'),
            'review_receipt_sha256' => hash('sha256', 'router review'),
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
            'runtime_configuration_sha256' => hash('sha256', 'router runtime configuration'),
            'seccomp_profile_sha256' => hash('sha256', 'router seccomp profile'),
            'secrets_evidence' => 'generation-private-files-readonly-mount-readback/v1',
            'storage_evidence' => 'dm-crypt-xfs-project-quota-exact-readback/v1',
        ];
        return ['descriptor_sha256' => hash(
            'sha256',
            "duo-reviewed-preview-base-containment/v1\0" . CanonicalJson::encode($basis)
        )] + $basis;
    }

    public function repositoryAuthorityDescriptor(): array {
        $basis = [
            'credential_helper_sha256' => hash('sha256', 'router credential helper'),
            'format' => 'duo-cloud-repository-authority/v1',
            'ref_prefix' => 'refs/heads/duo-preview/',
            'remote_url_sha256' => hash('sha256', 'router remote URL'),
        ];
        return ['descriptor_sha256' => hash(
            'sha256',
            "duo-cloud-repository-authority/v1\0" . CanonicalJson::encode($basis)
        )] + $basis;
    }

    public function syncRepository(array $authority, array $repository): array {
        return ['evidence_sha256' => hash(
            'sha256',
            CanonicalJson::encode($authority + $repository)
        )];
    }

    public function inspect(array $lease): array {
        $this->calls++;
        return [
            'evidence_sha256' => hash('sha256', 'inspect'),
            'presence' => 'absent',
            'url' => 'https://preview.example.invalid',
        ];
    }

    public function provision(array $lease): array {
        $this->calls++;
        return [
            'evidence_sha256' => hash('sha256', 'provision'),
            'url' => 'https://preview.example.invalid',
        ];
    }

    public function restoreSnapshot(array $authority, array $snapshot): array {
        $this->calls++;
        return ['evidence_sha256' => hash('sha256', 'restore')];
    }

    public function materializeRepository(array $authority, array $repository): array {
        $this->calls++;
        return ['evidence_sha256' => hash('sha256', 'repository')];
    }

    public function configureUrl(array $authority, string $url): array {
        $this->calls++;
        return ['evidence_sha256' => hash('sha256', 'url')];
    }

    public function revokeRouting(array $authority): array {
        $this->calls++;
        return ['evidence_sha256' => hash('sha256', 'routing')];
    }

    public function revokeExecution(array $authority): array {
        $this->calls++;
        return ['evidence_sha256' => hash('sha256', 'execution')];
    }

    public function resumeExecution(array $authority): array {
        $this->calls++;
        return ['evidence_sha256' => hash('sha256', 'execution-resume')];
    }

    public function deleteState(array $authority): array {
        $this->calls++;
        return ['evidence_sha256' => hash('sha256', 'delete')];
    }

    public function verifyAbsent(array $lease): array {
        $this->calls++;
        return ['absence_proof_sha256' => hash('sha256', 'absent'), 'absent' => true];
    }
}

#[CoversNothing]
final class CloudHttpRouterTest extends TestCase {
    private const NOW = 2000000000;
    private const OPERATION_ID = '20260818-120000-aaaaaaaaaaaaaaaaaaaaaaaa';
    private const REFUSAL_BODY = "{\"error\":\"request_refused\"}\n";

    private string $scratch;
    private string $serviceSecret;
    private string $servicePublic;
    private string $controllerSecret;
    private string $originSecret;
    private string $originPublic;
    private string $originKeyId;
    private HttpRouterRecordingRunner $runner;
    private HttpRouterInertRuntime $runtime;
    private OriginAuthority $origin;
    private CloudHttpRouter $router;
    /** @var array<string,mixed> */
    private array $target;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-cloud-http-router-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        self::assertTrue(mkdir($this->scratch . '/blobs', 0700));
        self::assertTrue(chmod($this->scratch . '/blobs', 0700));

        $service = sodium_crypto_sign_keypair();
        $this->serviceSecret = sodium_crypto_sign_secretkey($service);
        $this->servicePublic = sodium_crypto_sign_publickey($service);
        $controller = sodium_crypto_sign_keypair();
        $this->controllerSecret = sodium_crypto_sign_secretkey($controller);
        $controllerPublic = sodium_crypto_sign_publickey($controller);
        $originKeypair = sodium_crypto_sign_keypair();
        $this->originSecret = sodium_crypto_sign_secretkey($originKeypair);
        $this->originPublic = sodium_crypto_sign_publickey($originKeypair);
        $this->originKeyId = OriginProtocol::originKeyId($this->originPublic);

        $this->runner = new HttpRouterRecordingRunner();
        $control = new ControlAuthority(
            new FileAuthorityStore($this->scratch . '/control.json'),
            $this->runner,
            'service-key-v1',
            $this->serviceSecret
        );
        $control->registerRequestKey('controller-key-v1', 'tenant-a', 'site-a', $controllerPublic);
        $this->target = [
            'environment_identity' => 'environment-a',
            'lease_generation' => 1,
            'lease_id' => 'lease-a',
            'mutation_generation' => 1,
            'mutation_id' => 'mutation-a',
            'mutation_owner' => 'duo-env-materialize-' . self::OPERATION_ID,
            'mutation_receipt_sha256' => hash('sha256', 'mutation-a'),
            'ownership_receipt_sha256' => hash('sha256', 'ownership-a'),
            'resource_id' => 'resource-a',
        ];
        $control->holdAuthority('tenant-a', 'site-a', self::OPERATION_ID, $this->target);

        $this->runtime = new HttpRouterInertRuntime();
        $lifecycle = new PreviewLifecycleGateway(
            new PreviewSlotLifecycle(
                new FileAuthorityStore($this->scratch . '/lifecycle.json'),
                $this->runtime,
                null,
                static fn (): int => self::NOW
            ),
            $control,
            'service-key-v1',
            $this->serviceSecret
        );
        $this->origin = new OriginAuthority(
            new FileAuthorityStore($this->scratch . '/origin.json'),
            new OriginFileBlobStore($this->scratch . '/blobs'),
            'service-key-v1',
            $this->serviceSecret,
            random_bytes(32),
            static fn (): int => self::NOW
        );
        $originController = new OriginControllerGateway(
            $this->origin,
            $control,
            new FileAuthorityStore($this->scratch . '/origin-controller.json'),
            'service-key-v1',
            $this->serviceSecret
        );
        $this->router = new CloudHttpRouter($lifecycle, $control, $this->origin, $originController);
    }

    protected function tearDown(): void {
        unset($this->router, $this->origin);
        if (isset($this->scratch) && is_dir($this->scratch)) {
            self::removeTree($this->scratch);
        }
        foreach (['serviceSecret', 'controllerSecret', 'originSecret'] as $property) {
            if (isset($this->{$property}) && $this->{$property} !== '') {
                sodium_memzero($this->{$property});
            }
        }
    }

    public function testExactRoutesReturnSignedHttp200WithoutChangingAuthorityBytes(): void {
        $lifecycle = $this->router->handle(
            'POST',
            CloudHttpRouter::LIFECYCLE_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            $this->lifecycleRequest()
        );
        $lifecycleEnvelope = $this->assertSignedResponse($lifecycle);
        self::assertSame(PreviewLifecycleGateway::RESPONSE_FORMAT, $lifecycleEnvelope['payload']['format']);
        self::assertSame(0, $this->runtime->calls);

        $control = $this->router->handle(
            'POST',
            CloudHttpRouter::CONTROL_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            $this->controlRequest()
        );
        $controlEnvelope = $this->assertSignedResponse($control);
        self::assertSame('duo-cloud-preview-control-response/v1', $controlEnvelope['payload']['format']);
        self::assertSame("routed\n", $controlEnvelope['payload']['result']['stdout']);
        self::assertCount(1, $this->runner->calls);

        $originRequest = $this->originPairBeginRequest();
        $origin = $this->router->handle(
            'POST',
            OriginProtocol::PAIR_BEGIN_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            $originRequest
        );
        $originEnvelope = $this->assertSignedResponse($origin);
        self::assertSame(OriginProtocol::PAIR_BEGIN_RESPONSE, $originEnvelope['payload']['format']);
        self::assertSame('pending', $originEnvelope['payload']['state']);

        $originController = $this->router->handle(
            'POST',
            OriginControllerGateway::PATH,
            CloudHttpRouter::CONTENT_TYPE,
            "{}\n"
        );
        self::assertSame(403, $originController->status);
        self::assertSame(self::REFUSAL_BODY, $originController->body);
    }

    public function testAuthenticatedOriginStateRefusalRemainsSignedHttp200(): void {
        $begin = $this->assertSignedResponse($this->router->handle(
            'POST',
            OriginProtocol::PAIR_BEGIN_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            $this->originPairBeginRequest()
        ));
        $attempt = str_repeat('a', 32);
        $pairingId = $begin['payload']['pairing_id'];
        self::assertIsString($pairingId);
        $poll = [
            'format' => OriginProtocol::PAIR_POLL_REQUEST,
            'origin_key_id' => $this->originKeyId,
            'pair_attempt_id' => $attempt,
            'pairing_id' => $pairingId,
            'poll_sequence' => 0,
            'request_id' => OriginProtocol::pairPollRequestId($attempt, $pairingId, 0),
        ];
        $this->assertSignedResponse($this->router->handle(
            'POST',
            OriginProtocol::PAIR_POLL_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            $this->originSigned($poll)
        ));

        $demandPoll = [
            'after_demand_generation' => 0,
            'format' => OriginProtocol::DEMAND_POLL_REQUEST,
            'origin_generation' => 2,
            'origin_key_id' => $this->originKeyId,
            'poll_sequence' => 0,
            'request_id' => OriginProtocol::demandPollRequestId(
                'tenant-a',
                'site-a',
                2,
                $this->originKeyId,
                0,
                0
            ),
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
        $refusal = $this->assertSignedResponse($this->router->handle(
            'POST',
            OriginProtocol::DEMAND_POLL_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            $this->originSigned($demandPoll)
        ));
        self::assertSame(OriginProtocol::REFUSAL, $refusal['payload']['format']);
        self::assertSame('stale_origin_generation', $refusal['payload']['reason_code']);
    }

    public function testMethodTargetContentTypeAndBodyLimitsRefuseBeforeDispatch(): void {
        $lifecycle = $this->lifecycleRequest();
        $cases = [
            ['GET', CloudHttpRouter::LIFECYCLE_PATH, CloudHttpRouter::CONTENT_TYPE, $lifecycle],
            ['post', CloudHttpRouter::LIFECYCLE_PATH, CloudHttpRouter::CONTENT_TYPE, $lifecycle],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH . '?debug=1', CloudHttpRouter::CONTENT_TYPE, $lifecycle],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH . '#fragment', CloudHttpRouter::CONTENT_TYPE, $lifecycle],
            ['POST', 'https://cloud.example.test' . CloudHttpRouter::LIFECYCLE_PATH, CloudHttpRouter::CONTENT_TYPE, $lifecycle],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH . '/', CloudHttpRouter::CONTENT_TYPE, $lifecycle],
            ['POST', '/v1/preview/unknown', CloudHttpRouter::CONTENT_TYPE, $lifecycle],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH, null, $lifecycle],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH, 'Application/JSON', $lifecycle],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH, 'application/json; charset=utf-8', $lifecycle],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH, CloudHttpRouter::CONTENT_TYPE, ''],
            ['POST', CloudHttpRouter::LIFECYCLE_PATH, CloudHttpRouter::CONTENT_TYPE, str_repeat('x', 1048577)],
            ['POST', OriginProtocol::PAIR_BEGIN_PATH, CloudHttpRouter::CONTENT_TYPE, str_repeat('x', 2097153)],
        ];
        foreach ($cases as [$method, $target, $contentType, $body]) {
            $this->assertGenericRefusal($this->router->handle($method, $target, $contentType, $body), 400);
        }
        $this->assertGenericRefusal($this->router->handle(
            'POST',
            CloudHttpRouter::CONTROL_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            str_repeat('x', 1048576)
        ), 403);
        $this->assertGenericRefusal($this->router->handle(
            'POST',
            OriginProtocol::PAIR_BEGIN_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            str_repeat('x', 2097152)
        ), 403);
        self::assertSame(0, $this->runtime->calls);
        self::assertCount(0, $this->runner->calls);

        // A query-bearing pairing request never consumed its one-time device
        // code: the identical body succeeds when sent to the exact path.
        $originRequest = $this->originPairBeginRequest();
        $this->assertGenericRefusal($this->router->handle(
            'POST',
            OriginProtocol::PAIR_BEGIN_PATH . '?retry=1',
            CloudHttpRouter::CONTENT_TYPE,
            $originRequest
        ), 400);
        $paired = $this->assertSignedResponse($this->router->handle(
            'POST',
            OriginProtocol::PAIR_BEGIN_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            $originRequest
        ));
        self::assertSame('pending', $paired['payload']['state']);
    }

    public function testAuthorityDiagnosticsAndRequestBytesNeverCrossUnsignedRefusals(): void {
        $malformed = "{\"request_body_secret\":\"sentinel-value\"}\n";
        foreach ([
            CloudHttpRouter::LIFECYCLE_PATH,
            CloudHttpRouter::CONTROL_PATH,
            OriginProtocol::PAIR_BEGIN_PATH,
            OriginProtocol::PAIR_POLL_PATH,
            OriginProtocol::DEMAND_POLL_PATH,
            OriginProtocol::ANNOUNCE_PATH,
            OriginProtocol::MISSING_PATH,
            OriginProtocol::CHUNK_PATH,
            OriginProtocol::COMMIT_PATH,
            OriginProtocol::ROTATE_PATH,
            OriginProtocol::REVOKE_PATH,
        ] as $path) {
            $response = $this->router->handle(
                'POST',
                $path,
                CloudHttpRouter::CONTENT_TYPE,
                $malformed
            );
            $this->assertGenericRefusal($response, 403);
            self::assertStringNotContainsString('sentinel-value', $response->body);
            self::assertStringNotContainsString('missing or unknown fields', $response->body);
        }

        $invalidSignature = CanonicalJson::decodeObject($this->lifecycleRequest());
        $invalidSignature['signature'] = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
        $response = $this->router->handle(
            'POST',
            CloudHttpRouter::LIFECYCLE_PATH,
            CloudHttpRouter::CONTENT_TYPE,
            CanonicalJson::encode($invalidSignature) . "\n"
        );
        $this->assertGenericRefusal($response, 403);
        self::assertStringNotContainsString('signature', $response->body);
        self::assertSame(0, $this->runtime->calls);
        self::assertCount(0, $this->runner->calls);
    }

    /** @return array<string,mixed> */
    private function assertSignedResponse(CloudHttpResponse $response): array {
        self::assertSame(200, $response->status);
        self::assertSame(CloudHttpRouter::CONTENT_TYPE, $response->headers['content-type']);
        self::assertSame('no-store', $response->headers['cache-control']);
        self::assertSame((string) strlen($response->body), $response->headers['content-length']);
        $envelope = CanonicalJson::decodeObject($response->body, 16777216);
        self::assertSame('service-key-v1', $envelope['key_id']);
        $signature = base64_decode((string) $envelope['signature'], true);
        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            CanonicalJson::encode($envelope['payload']),
            $this->servicePublic
        ));
        return $envelope;
    }

    private function assertGenericRefusal(CloudHttpResponse $response, int $status): void {
        self::assertSame($status, $response->status);
        self::assertSame(self::REFUSAL_BODY, $response->body);
        self::assertSame(CloudHttpRouter::CONTENT_TYPE, $response->headers['content-type']);
        self::assertSame('no-store', $response->headers['cache-control']);
        self::assertSame((string) strlen(self::REFUSAL_BODY), $response->headers['content-length']);
        self::assertSame(['error' => 'request_refused'], json_decode($response->body, true));
        self::assertStringNotContainsString('signature', $response->body);
    }

    private function lifecycleRequest(): string {
        $payload = [
            'action' => 'capabilities',
            'environment' => 'preview',
            'format' => PreviewLifecycleGateway::REQUEST_FORMAT,
            'input' => [],
            'operation_id' => self::OPERATION_ID,
            'request_id' => '',
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
        $payload['request_id'] = hash(
            'sha256',
            "duo-cloud-preview-lifecycle/v1\0tenant-a\0site-a\0preview\0"
                . self::OPERATION_ID . "\0capabilities\0" . hash('sha256', CanonicalJson::encode([]))
        );
        return $this->controllerSigned($payload);
    }

    private function controlRequest(): string {
        $payload = [
            'action' => 'raw',
            'command_index' => 0,
            'command_phase' => 'materialize',
            'environment' => 'preview',
            'format' => 'duo-cloud-preview-control-request/v1',
            'input' => ['script' => 'printf routed'],
            'operation_id' => self::OPERATION_ID,
            'request_id' => hash(
                'sha256',
                'duo-cloud-preview-command/v1' . "\0tenant-a\0site-a\0" . self::OPERATION_ID
                    . "\0materialize\0" . '0'
            ),
            'site_id' => 'site-a',
            'target' => $this->target,
            'tenant_id' => 'tenant-a',
        ];
        return $this->controllerSigned($payload);
    }

    /** @param array<string,mixed> $payload */
    private function controllerSigned(array $payload): string {
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

    private function originPairBeginRequest(): string {
        $issued = $this->origin->issueDeviceCode('tenant-a', 'site-a');
        $attempt = str_repeat('a', 32);
        return $this->originSigned([
            'connector' => [
                'agent_version' => '1.0.0',
                'home_url_sha256' => hash('sha256', 'https://example.test'),
                'installation_id' => 'installation-a',
                'multisite' => false,
                'php_version' => PHP_VERSION,
                'site_url_sha256' => hash('sha256', 'https://example.test'),
                'wordpress_version' => '6.9',
            ],
            'device_code' => $issued['device_code'],
            'format' => OriginProtocol::PAIR_BEGIN_REQUEST,
            'origin_key' => [
                'algorithm' => 'Ed25519',
                'key_id' => $this->originKeyId,
                'public_key' => base64_encode($this->originPublic),
            ],
            'pair_attempt_id' => $attempt,
            'request_id' => OriginProtocol::pairBeginRequestId($attempt, $this->originKeyId),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function originSigned(array $payload): string {
        return CanonicalJson::encode([
            'format' => OriginProtocol::ENVELOPE_FORMAT,
            'key_id' => $this->originKeyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($payload),
                $this->originSecret
            )),
        ]) . "\n";
    }

    private static function removeTree(string $path): void {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
                continue;
            }
            self::assertTrue(unlink($child));
        }
        self::assertTrue(rmdir($path));
    }
}
