<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\CommandRunner;
use Duo\Cloud\ControlAuthority;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/ControlAuthority.php';

/** @internal */
final class RecordingCloudCommandRunner implements CommandRunner {
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    public bool $fail = false;
    public ?string $stdout = null;

    public function run(array $request): array {
        $this->calls[] = $request;
        if ($this->fail) {
            throw new \RuntimeException('fixture runner died after dispatch');
        }
        return [
            'exit' => 0,
            'stderr' => '',
            'stdout' => $this->stdout
                ?? ($request['action'] === 'wp' ? "wp-ok\n" : "raw-ok\n"),
        ];
    }
}

#[CoversNothing]
final class ControlAuthorityTest extends TestCase {
    private string $scratch;
    private string $statePath;
    private string $controllerSecret;
    private string $controllerPublic;
    private string $serviceSecret;
    private string $servicePublic;
    private string $operationId = '20260818-120000-aaaaaaaaaaaaaaaaaaaaaaaa';
    /** @var array<string,mixed> */
    private array $target;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-cloud-authority-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->statePath = $this->scratch . '/authority.json';

        $controller = sodium_crypto_sign_keypair();
        $this->controllerSecret = sodium_crypto_sign_secretkey($controller);
        $this->controllerPublic = sodium_crypto_sign_publickey($controller);
        $service = sodium_crypto_sign_keypair();
        $this->serviceSecret = sodium_crypto_sign_secretkey($service);
        $this->servicePublic = sodium_crypto_sign_publickey($service);
        $this->target = $this->targetFor($this->operationId, 1, 'a');
    }

    protected function tearDown(): void {
        foreach ([$this->statePath, $this->statePath . '.lock'] as $path) {
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

    public function testExactReplayReturnsTheCachedSignedResponseWithoutExecution(): void {
        $runner = new RecordingCloudCommandRunner();
        $authority = $this->authority($runner);
        $request = $this->request('materialize-raw', 0, 'raw', ['script' => 'printf ready']);

        $first = $authority->handle($request);
        $second = $authority->handle($request);

        self::assertSame($first, $second);
        self::assertCount(1, $runner->calls);
        $response = CanonicalJson::decodeObject($first, 16777216);
        self::assertSame('duo-cloud-preview-signed-envelope/v1', $response['format']);
        self::assertSame('service-key-v1', $response['key_id']);
        $signature = base64_decode((string) $response['signature'], true);
        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            CanonicalJson::encode($response['payload']),
            $this->servicePublic
        ));
        self::assertSame('raw-ok' . "\n", $response['payload']['result']['stdout']);

        $authority->releaseAuthority('tenant-a', 'site-a', $this->operationId, $this->target);
        self::assertSame($first, $authority->handle($request));
        self::assertCount(1, $runner->calls);
        $this->assertRefused(
            fn (): string => $authority->handle($this->request(
                'materialize-raw',
                1,
                'raw',
                ['script' => 'printf after-release']
            )),
            'exact current held preview authority'
        );
        self::assertCount(1, $runner->calls);

        self::assertSame(0600, fileperms($this->statePath) & 0777);
        self::assertSame(0600, fileperms($this->statePath . '.lock') & 0777);
    }

    public function testReceiptStateRemainsBoundedAcrossOneThousandAuthorityGenerations(): void {
        $runner = new RecordingCloudCommandRunner();
        $authority = $this->authority($runner);
        $twoBackRequest = null;
        $terminalRequest = null;

        for ($generation = 1; $generation <= 1000; $generation++) {
            if ($generation === 1) {
                $operationId = $this->operationId;
                $target = $this->target;
            } else {
                $operationId = $this->operationIdForGeneration($generation);
                $target = $this->targetFor($operationId, $generation, 'bounded-' . $generation);
                $authority->holdAuthority('tenant-a', 'site-a', $operationId, $target);
            }
            $request = $this->request(
                'bounded',
                0,
                'raw',
                ['script' => 'printf bounded'],
                $operationId,
                $target
            );
            $authority->handle($request);
            $authority->releaseAuthority('tenant-a', 'site-a', $operationId, $target);
            if ($generation === 998) {
                $twoBackRequest = $request;
            } elseif ($generation === 999) {
                $terminalRequest = $request;
            }
        }

        self::assertIsString($terminalRequest);
        self::assertSame('raw-ok' . "\n", CanonicalJson::decodeObject(
            $authority->handle($terminalRequest)
        )['payload']['result']['stdout']);
        self::assertCount(1000, $runner->calls);
        self::assertIsString($twoBackRequest);
        $this->assertRefused(
            fn (): string => $authority->handle($twoBackRequest),
            'exact current held preview authority'
        );

        $state = CanonicalJson::decodeObject((string) file_get_contents($this->statePath));
        $site = array_values($state['sites'])[0];
        self::assertCount(1, $site['receipts']);
        self::assertCount(1, $site['terminal']['receipts']);
        self::assertLessThan(16384, filesize($this->statePath));
    }

    public function testUniqueCommandCountIsHardBoundedBeforeDispatch(): void {
        $runner = new RecordingCloudCommandRunner();
        $authority = $this->authority($runner);
        for ($index = 0; $index < 256; $index++) {
            $authority->handle($this->request(
                'bounded',
                $index,
                'raw',
                ['script' => 'printf bounded']
            ));
        }
        $this->assertRefused(
            fn (): string => $authority->handle($this->request(
                'bounded',
                256,
                'raw',
                ['script' => 'printf refused']
            )),
            'receipt count exceeds its per-generation limit'
        );
        self::assertCount(256, $runner->calls);
    }

    public function testInsufficientMaximumResponseBudgetRefusesBeforeRunnerDispatch(): void {
        $runner = new RecordingCloudCommandRunner();
        $runner->stdout = str_repeat('x', 3145728);
        $authority = $this->authority($runner);
        $authority->handle($this->request(
            'bounded-bytes',
            0,
            'raw',
            ['script' => 'printf large']
        ));
        self::assertCount(1, $runner->calls);

        $this->assertRefused(
            fn (): string => $authority->handle($this->request(
                'bounded-bytes',
                1,
                'raw',
                ['script' => 'must-not-run']
            )),
            'insufficient receipt bytes for the maximum response'
        );
        self::assertCount(1, $runner->calls);
    }

    public function testChangedSignedBytesCannotReuseACompletedRequestId(): void {
        $runner = new RecordingCloudCommandRunner();
        $authority = $this->authority($runner);
        $authority->handle($this->request('restore', 0, 'raw', ['script' => 'printf first']));

        $this->assertRefused(
            fn (): string => $authority->handle($this->request(
                'restore',
                0,
                'raw',
                ['script' => 'printf changed']
            )),
            'replayed with changed signed bytes'
        );
        self::assertCount(1, $runner->calls);
    }

    public function testForeignStaleReleasedAndOldGenerationCommandsNeverReachTheRunner(): void {
        $runner = new RecordingCloudCommandRunner();
        $authority = $this->authority($runner);

        $foreignTenant = $this->payload('phase-a', 0, 'raw', ['script' => 'foreign']);
        $foreignTenant['tenant_id'] = 'tenant-b';
        $foreignTenant['request_id'] = $this->requestId($foreignTenant);
        $this->assertRefused(
            fn (): string => $authority->handle($this->signed($foreignTenant)),
            'not active for the signed tenant and site'
        );

        $stale = $this->targetFor($this->operationId, 2, 'b');
        $this->assertRefused(
            fn (): string => $authority->handle($this->request(
                'phase-a',
                1,
                'raw',
                ['script' => 'stale'],
                $this->operationId,
                $stale
            )),
            'exact current held preview authority'
        );
        self::assertCount(0, $runner->calls);

        $authority->releaseAuthority('tenant-a', 'site-a', $this->operationId, $this->target);
        $this->assertRefused(
            fn (): string => $authority->handle($this->request(
                'phase-a',
                2,
                'raw',
                ['script' => 'released']
            )),
            'exact current held preview authority'
        );

        $secondOperation = '20260818-120001-bbbbbbbbbbbbbbbbbbbbbbbb';
        $secondTarget = $this->targetFor($secondOperation, 2, 'c');
        $authority->holdAuthority('tenant-a', 'site-a', $secondOperation, $secondTarget);
        $oldGeneration = $this->request(
            'phase-a',
            3,
            'raw',
            ['script' => 'old-generation'],
            $this->operationId,
            $this->target
        );
        $this->assertRefused(
            fn (): string => $authority->handle($oldGeneration),
            'exact current held preview authority'
        );
        self::assertCount(0, $runner->calls);

        $authority->handle($this->request(
            'phase-b',
            0,
            'wp',
            ['argv' => ['option', 'get', 'home']],
            $secondOperation,
            $secondTarget
        ));
        self::assertCount(1, $runner->calls);
        self::assertSame(2, $runner->calls[0]['target']['lease_generation']);
    }

    public function testDurableExecutingReservationPreventsASecondDispatchAfterFailure(): void {
        $failing = new RecordingCloudCommandRunner();
        $failing->fail = true;
        $authority = $this->authority($failing);
        $request = $this->request('restore', 0, 'raw', ['script' => 'unknown outcome']);

        $this->assertRefused(
            fn (): string => $authority->handle($request),
            'outcome is indeterminate'
        );
        self::assertCount(1, $failing->calls);

        unset($authority);
        $replacement = new RecordingCloudCommandRunner();
        $reopened = new ControlAuthority(
            new FileAuthorityStore($this->statePath),
            $replacement,
            'service-key-v1',
            $this->serviceSecret
        );
        $this->assertRefused(
            fn (): string => $reopened->handle($request),
            'cannot be dispatched again'
        );
        self::assertCount(0, $replacement->calls);
    }

    public function testTamperingBadDeterministicIdAndRevokedKeyAreRefusedBeforeExecution(): void {
        $runner = new RecordingCloudCommandRunner();
        $authority = $this->authority($runner);
        $payload = $this->payload('verify', 0, 'raw', ['script' => 'signed']);
        $signed = CanonicalJson::decodeObject($this->signed($payload));
        $signed['payload']['input']['script'] = 'tampered-after-signing';
        $this->assertRefused(
            fn (): string => $authority->handle(CanonicalJson::encode($signed) . "\n"),
            'signature is invalid'
        );

        $badId = $payload;
        $badId['request_id'] = str_repeat('f', 64);
        $this->assertRefused(
            fn (): string => $authority->handle($this->signed($badId)),
            'not derived from its journal command phase and index'
        );

        $authority->revokeRequestKey('controller-key-v1', 'tenant-a', 'site-a');
        $this->assertRefused(
            fn (): string => $authority->handle($this->signed($payload)),
            'not active for the signed tenant and site'
        );
        self::assertCount(0, $runner->calls);
    }

    public function testAuthorityTransitionsAreExactAndGenerationMonotonic(): void {
        $runner = new RecordingCloudCommandRunner();
        $authority = $this->authority($runner);
        $current = $authority->currentAuthority('tenant-a', 'site-a');
        self::assertSame('held', $current['state']);
        self::assertSame($this->target, $current['target']);

        $authority->holdAuthority('tenant-a', 'site-a', $this->operationId, $this->target);
        $foreign = $this->target;
        $foreign['lease_id'] = 'lease-foreign';
        $this->assertRefused(
            fn (): null => $authority->releaseAuthority(
                'tenant-a',
                'site-a',
                $this->operationId,
                $foreign
            ),
            'does not match the current preview authority'
        );

        $authority->releaseAuthority('tenant-a', 'site-a', $this->operationId, $this->target);
        $refenceOperation = '20260818-120002-cccccccccccccccccccccccc';
        $refenced = $this->target;
        $refenced['mutation_generation'] = 2;
        $refenced['mutation_id'] = 'mutation-refenced';
        $refenced['mutation_owner'] = 'duo-env-materialize-' . $refenceOperation;
        $refenced['mutation_receipt_sha256'] = hash('sha256', 'mutation-refenced');
        $authority->holdAuthority('tenant-a', 'site-a', $refenceOperation, $refenced);
        self::assertSame($refenced, $authority->currentAuthority('tenant-a', 'site-a')['target']);
        $authority->releaseAuthority('tenant-a', 'site-a', $refenceOperation, $refenced);

        $staleMutation = $refenced;
        $staleMutation['mutation_id'] = 'mutation-stale-refence';
        $staleMutation['mutation_owner'] = 'duo-env-materialize-20260818-120003-dddddddddddddddddddddddd';
        $staleMutation['mutation_receipt_sha256'] = hash('sha256', 'mutation-stale-refence');
        $this->assertRefused(
            fn (): null => $authority->holdAuthority(
                'tenant-a',
                'site-a',
                '20260818-120003-dddddddddddddddddddddddd',
                $staleMutation
            ),
            'advance the site mutation generation'
        );
        $this->assertRefused(
            fn (): null => $authority->holdAuthority(
                'tenant-a',
                'site-a',
                '20260818-120004-eeeeeeeeeeeeeeeeeeeeeeee',
                $this->targetFor('20260818-120004-eeeeeeeeeeeeeeeeeeeeeeee', 1, 'e')
            ),
            "changed resource identity 'environment_identity'"
        );
    }

    private function authority(RecordingCloudCommandRunner $runner): ControlAuthority {
        $authority = new ControlAuthority(
            new FileAuthorityStore($this->statePath),
            $runner,
            'service-key-v1',
            $this->serviceSecret
        );
        $authority->registerRequestKey(
            'controller-key-v1',
            'tenant-a',
            'site-a',
            $this->controllerPublic
        );
        $authority->holdAuthority('tenant-a', 'site-a', $this->operationId, $this->target);
        return $authority;
    }

    /**
     * @param array<string,mixed> $input
     * @param ?array<string,mixed> $target
     */
    private function request(
        string $phase,
        int $index,
        string $action,
        array $input,
        ?string $operationId = null,
        ?array $target = null
    ): string {
        return $this->signed($this->payload($phase, $index, $action, $input, $operationId, $target));
    }

    /**
     * @param array<string,mixed> $input
     * @param ?array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function payload(
        string $phase,
        int $index,
        string $action,
        array $input,
        ?string $operationId = null,
        ?array $target = null
    ): array {
        $operationId ??= $this->operationId;
        $target ??= $this->target;
        $payload = [
            'action' => $action,
            'command_index' => $index,
            'command_phase' => $phase,
            'environment' => 'preview',
            'format' => 'duo-cloud-preview-control-request/v1',
            'input' => $input,
            'operation_id' => $operationId,
            'request_id' => '',
            'site_id' => 'site-a',
            'target' => $target,
            'tenant_id' => 'tenant-a',
        ];
        $payload['request_id'] = $this->requestId($payload);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function requestId(array $payload): string {
        return hash(
            'sha256',
            "duo-cloud-preview-command/v1\0{$payload['tenant_id']}\0{$payload['site_id']}\0"
                . "{$payload['operation_id']}\0{$payload['command_phase']}\0{$payload['command_index']}"
        );
    }

    /** @param array<string,mixed> $payload */
    private function signed(array $payload): string {
        $envelope = [
            'format' => 'duo-cloud-preview-signed-envelope/v1',
            'key_id' => 'controller-key-v1',
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($payload),
                $this->controllerSecret
            )),
        ];
        return CanonicalJson::encode($envelope) . "\n";
    }

    /** @return array<string,mixed> */
    private function targetFor(string $operationId, int $generation, string $seed): array {
        return [
            'environment_identity' => 'environment-' . $seed,
            'lease_generation' => $generation,
            'lease_id' => 'lease-' . $seed,
            'mutation_generation' => $generation,
            'mutation_id' => 'mutation-' . $seed,
            'mutation_owner' => 'duo-env-materialize-' . $operationId,
            'mutation_receipt_sha256' => hash('sha256', 'mutation-' . $seed),
            'ownership_receipt_sha256' => hash('sha256', 'ownership-' . $seed),
            'resource_id' => 'resource-' . $seed,
        ];
    }

    private function operationIdForGeneration(int $generation): string {
        return '20260818-' . str_pad((string) $generation, 6, '0', STR_PAD_LEFT)
            . '-' . str_pad(dechex($generation), 24, '0', STR_PAD_LEFT);
    }

    /** @param callable():mixed $call */
    private function assertRefused(callable $call, string $messageFragment): void {
        try {
            $call();
            self::fail('expected cloud control authority to refuse the operation');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString($messageFragment, $error->getMessage());
        }
    }
}
