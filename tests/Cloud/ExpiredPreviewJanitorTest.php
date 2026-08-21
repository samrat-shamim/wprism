<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ControlRefusal;
use Duo\Cloud\ExpiredPreviewJanitor;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\MutationAuthorityPublisher;
use Duo\Cloud\PreviewSlotLifecycle;
use Duo\Cloud\WorkloadRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ExpiredPreviewJanitor.php';

/** @internal */
final class JanitorRuntime implements WorkloadRuntime {
    public bool $present = false;
    public bool $failDeleteAfterEffect = false;
    public bool $failProvisionAfterEffect = false;
    public bool $failRoutingAfterEffect = false;
    /** @var list<string> */
    public array $calls = [];

    public function inspect(array $lease): array {
        return ['evidence_sha256' => hash('sha256', 'inspect-' . (int) $this->present), 'presence' => $this->present ? 'present' : 'absent', 'url' => 'https://preview.example.test'];
    }

    public function provision(array $lease): array {
        $this->calls[] = 'provision';
        $this->present = true;
        if ($this->failProvisionAfterEffect) {
            $this->failProvisionAfterEffect = false;
            throw new \RuntimeException('lost provision response');
        }
        return ['evidence_sha256' => hash('sha256', 'provision'), 'url' => 'https://preview.example.test'];
    }

    public function restoreSnapshot(array $authority, array $snapshot): array {
        return ['evidence_sha256' => hash('sha256', 'restore')];
    }

    public function materializeRepository(array $authority, array $repository): array {
        return ['evidence_sha256' => hash('sha256', 'repository')];
    }

    public function configureUrl(array $authority, string $url): array {
        return ['evidence_sha256' => hash('sha256', 'url')];
    }

    public function revokeRouting(array $authority): array {
        $this->calls[] = 'routing';
        if ($this->failRoutingAfterEffect) {
            $this->failRoutingAfterEffect = false;
            throw new \RuntimeException('lost routing response');
        }
        return ['evidence_sha256' => hash('sha256', 'routing')];
    }

    public function revokeExecution(array $authority): array {
        $this->calls[] = 'execution';
        return ['evidence_sha256' => hash('sha256', 'execution')];
    }

    public function resumeExecution(array $authority): array {
        $this->calls[] = 'execution-resume';
        return ['evidence_sha256' => hash('sha256', 'execution-resume')];
    }

    public function deleteState(array $authority): array {
        $this->calls[] = 'delete';
        $this->present = false;
        if ($this->failDeleteAfterEffect) {
            $this->failDeleteAfterEffect = false;
            throw new \RuntimeException('lost delete response');
        }
        return ['evidence_sha256' => hash('sha256', 'delete')];
    }

    public function verifyAbsent(array $lease): array {
        $this->calls[] = 'absent';
        return ['absence_proof_sha256' => hash('sha256', 'absent'), 'absent' => !$this->present];
    }
}

/** @internal */
final class JanitorPublisher implements MutationAuthorityPublisher {
    public bool $failHoldAfterEffect = false;
    public bool $failReleaseAfterEffect = false;
    /** @var list<string> */
    public array $calls = [];
    /** @var ?array<string,mixed> */
    private ?array $target = null;
    private string $state = 'absent';

    public function hold(string $tenantId, string $siteId, string $operationId, array $target): void {
        $this->calls[] = 'hold';
        if ($this->target !== null && $this->target !== $target) {
            throw new \RuntimeException('foreign hold');
        }
        if ($this->state === 'released') {
            throw new \RuntimeException('released authority cannot be held again');
        }
        $this->target = $target;
        $this->state = 'held';
        if ($this->failHoldAfterEffect) {
            $this->failHoldAfterEffect = false;
            throw new \RuntimeException('lost hold response');
        }
    }

    public function release(string $tenantId, string $siteId, string $operationId, array $target): void {
        $this->calls[] = 'release';
        if ($this->target !== $target || !in_array($this->state, ['held', 'released'], true)) {
            throw new \RuntimeException('release without exact hold');
        }
        $this->state = 'released';
        if ($this->failReleaseAfterEffect) {
            $this->failReleaseAfterEffect = false;
            throw new \RuntimeException('lost release response');
        }
    }

    public function isReleased(): bool {
        return $this->state === 'released';
    }
}

#[CoversNothing]
final class ExpiredPreviewJanitorTest extends TestCase {
    private string $scratch;
    private int $now = 2000000000;
    private FileAuthorityStore $store;
    private JanitorRuntime $runtime;
    private PreviewSlotLifecycle $lifecycle;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-expired-janitor-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->store = new FileAuthorityStore($this->scratch . '/lifecycle.json');
        $this->runtime = new JanitorRuntime();
        $this->lifecycle = new PreviewSlotLifecycle(
            $this->store,
            $this->runtime,
            null,
            fn (): int => $this->now
        );
    }

    protected function tearDown(): void {
        unset($this->lifecycle, $this->runtime, $this->store);
        foreach (scandir($this->scratch) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->scratch . '/' . $entry);
            }
        }
        @rmdir($this->scratch);
    }

    public function testSweepDestroysExpiredReleasedGeneration(): void {
        [, , $ttl] = $this->materializedAndTtl(true);
        $this->now = strtotime($ttl['expires_at']) + 1;
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);

        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep(10));
        self::assertSame(['routing', 'execution', 'delete', 'absent'], $this->runtime->calls);
        self::assertFalse($this->runtime->present);
        self::assertSame(['examined' => 0, 'reaped' => 0, 'refused' => 0], $janitor->sweep(10));
    }

    public function testSweepDestroysExpiredAsleepGenerationWithoutWakingIt(): void {
        [$identity, $fence, $ttl] = $this->materializedAndTtl(false);
        $this->lifecycle->perform(
            'sleep',
            'tenant-a',
            'site-a',
            self::operation('b'),
            self::identityInput($identity) + self::mutationInput($fence)
        );
        self::assertSame('asleep', $this->current()['state']);
        $this->now = strtotime($ttl['expires_at']) + 1;
        $this->runtime->calls = [];
        $janitor = new ExpiredPreviewJanitor(
            $this->store,
            $this->lifecycle,
            fn (): int => $this->now
        );

        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep());
        self::assertSame(['routing', 'execution', 'delete', 'absent'], $this->runtime->calls);
        self::assertNotContains('execution-resume', $this->runtime->calls);
        self::assertFalse($this->runtime->present);
    }

    public function testSweepDestroysExpiredInterruptedSleepTransition(): void {
        [$identity, $fence, $ttl] = $this->materializedAndTtl(false);
        $sleepOperation = self::operation('b');
        $sleepInput = self::identityInput($identity) + self::mutationInput($fence);
        $this->runtime->failRoutingAfterEffect = true;
        try {
            $this->lifecycle->perform(
                'sleep',
                'tenant-a',
                'site-a',
                $sleepOperation,
                $sleepInput
            );
            self::fail('expected the injected lost sleep-routing response');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString(
                'sleep routing revocation outcome is indeterminate',
                $error->getMessage()
            );
        }
        self::assertSame('sleeping', $this->current()['state']);
        $this->now = strtotime($ttl['expires_at']) + 1;
        $this->runtime->calls = [];
        $janitor = new ExpiredPreviewJanitor(
            $this->store,
            $this->lifecycle,
            fn (): int => $this->now
        );

        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep());
        self::assertSame(['routing', 'execution', 'delete', 'absent'], $this->runtime->calls);
        self::assertFalse($this->runtime->present);
        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('superseded by exact destroy');
        $this->lifecycle->perform(
            'sleep',
            'tenant-a',
            'site-a',
            $sleepOperation,
            $sleepInput
        );
    }

    public function testFirstDurableGenerationHasAProvisionalDeadlineThatFinalTtlSupersedes(): void {
        $operation = self::operation('a');
        $identity = $this->lifecycle->perform('create', 'tenant-a', 'site-a', $operation, [
            'intent_sha256' => hash('sha256', 'intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $provisional = $this->current()['ttl'];
        self::assertSame('provisional', $provisional['state']);
        self::assertSame(1, $provisional['generation']);
        self::assertSame($operation, $provisional['operation_id']);
        self::assertSame($this->now + 3600, strtotime($provisional['expires_at']));

        $fence = $this->lifecycle->perform(
            'mutation-acquire',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + ['mutation_owner' => 'duo-env-materialize-' . $operation]
        );
        $final = $this->lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + self::mutationInput($fence) + ['ttl_seconds' => 7200]
        );
        self::assertSame('active', $final['ttl_state']);
        self::assertSame(2, $final['ttl_generation']);
        self::assertGreaterThan(
            strtotime($provisional['expires_at']),
            strtotime($final['expires_at'])
        );

        $this->now = strtotime($provisional['expires_at']) + 1;
        $this->runtime->calls = [];
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);
        self::assertSame(['examined' => 0, 'reaped' => 0, 'refused' => 0], $janitor->sweep());
        self::assertTrue($this->runtime->present);
        self::assertSame([], $this->runtime->calls);
    }

    public function testPresentGenerationWithoutControllerFenceIsReapedAtProvisionalDeadline(): void {
        $this->lifecycle->perform('create', 'tenant-a', 'site-a', self::operation('a'), [
            'intent_sha256' => hash('sha256', 'intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $provisional = $this->current()['ttl'];
        $this->now = strtotime($provisional['expires_at']) + 1;
        $this->runtime->calls = [];
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);

        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep());
        self::assertSame(['routing', 'execution', 'delete', 'absent'], $this->runtime->calls);
        self::assertFalse($this->runtime->present);
    }

    public function testLostFenceReleaseResponseResumesWithoutReacquiringAuthority(): void {
        $publisher = new JanitorPublisher();
        $this->lifecycle = new PreviewSlotLifecycle(
            $this->store,
            $this->runtime,
            $publisher,
            fn (): int => $this->now
        );
        $operation = self::operation('a');
        $identity = $this->lifecycle->perform('create', 'tenant-a', 'site-a', $operation, [
            'intent_sha256' => hash('sha256', 'intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $publisher->failHoldAfterEffect = true;
        try {
            $this->lifecycle->perform(
                'mutation-acquire',
                'tenant-a',
                'site-a',
                $operation,
                self::identityInput($identity) + ['mutation_owner' => 'duo-env-materialize-' . $operation]
            );
            self::fail('expected the injected lost hold response');
        } catch (ControlRefusal) {
        }
        $current = $this->current();
        $this->now = strtotime($current['ttl']['expires_at']) + 1;
        $publisher->failReleaseAfterEffect = true;
        $this->runtime->calls = [];
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);

        self::assertSame(['examined' => 1, 'reaped' => 0, 'refused' => 1], $janitor->sweep());
        self::assertSame(['hold', 'hold', 'release'], $publisher->calls);
        self::assertTrue($publisher->isReleased());
        self::assertSame([], $this->runtime->calls);

        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep());
        self::assertSame(
            ['hold', 'hold', 'release', 'release'],
            $publisher->calls,
            'release recovery must not reacquire possibly released command authority'
        );
        self::assertFalse($this->runtime->present);
    }

    public function testIndeterminateCreateIsReconciledAndReapedWithoutControllerJournal(): void {
        $this->runtime->failProvisionAfterEffect = true;
        try {
            $this->lifecycle->perform('create', 'tenant-a', 'site-a', self::operation('a'), [
                'intent_sha256' => hash('sha256', 'intent'),
                'mode' => 'create',
                'target_environment' => 'preview',
            ]);
            self::fail('expected the injected lost provision response');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('provision outcome is indeterminate', $error->getMessage());
        }
        $current = $this->current();
        self::assertSame('acquiring', $current['state']);
        self::assertSame('provisional', $current['ttl']['state']);
        self::assertTrue($this->runtime->present);

        $this->now = strtotime($current['ttl']['expires_at']) + 1;
        $this->runtime->calls = [];
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);
        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep());
        self::assertSame(['provision', 'routing', 'execution', 'delete', 'absent'], $this->runtime->calls);
        self::assertFalse($this->runtime->present);
    }

    public function testIndeterminateFenceHoldIsReconciledReleasedAndReaped(): void {
        $publisher = new JanitorPublisher();
        $this->lifecycle = new PreviewSlotLifecycle(
            $this->store,
            $this->runtime,
            $publisher,
            fn (): int => $this->now
        );
        $operation = self::operation('a');
        $identity = $this->lifecycle->perform('create', 'tenant-a', 'site-a', $operation, [
            'intent_sha256' => hash('sha256', 'intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $publisher->failHoldAfterEffect = true;
        try {
            $this->lifecycle->perform(
                'mutation-acquire',
                'tenant-a',
                'site-a',
                $operation,
                self::identityInput($identity) + ['mutation_owner' => 'duo-env-materialize-' . $operation]
            );
            self::fail('expected the injected lost hold response');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('hold outcome is indeterminate', $error->getMessage());
        }
        $current = $this->current();
        self::assertSame('publishing', $current['mutation']['state']);

        $this->now = strtotime($current['ttl']['expires_at']) + 1;
        $this->runtime->calls = [];
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);
        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep());
        self::assertSame(['hold', 'hold', 'release'], $publisher->calls);
        self::assertTrue($publisher->isReleased());
        self::assertFalse($this->runtime->present);
    }

    public function testChangedTtlRefusesUnderLockWithoutDeleting(): void {
        [$identity, $fence, $oldTtl] = $this->materializedAndTtl(false);
        $newTtl = $this->lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-a',
            self::operation('b'),
            self::identityInput($identity) + self::mutationInput($fence) + ['ttl_seconds' => 600]
        );
        $this->now = strtotime($oldTtl['expires_at']) + 1;
        self::assertGreaterThan($this->now, strtotime($newTtl['expires_at']));

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('TTL comparison is stale');
        try {
            $this->lifecycle->reapExpired(
                'tenant-a',
                'site-a',
                self::operation('c'),
                self::identityInput($identity) + ['compare_and_reap' => true],
                self::ttlInput($oldTtl, self::operation('a'))
            );
        } finally {
            self::assertTrue($this->runtime->present);
            self::assertSame([], $this->runtime->calls);
        }
    }

    public function testLostDeleteResponseResumesTheSameDurableExpiredReap(): void {
        [, , $ttl] = $this->materializedAndTtl(true);
        $this->now = strtotime($ttl['expires_at']) + 1;
        $this->runtime->failDeleteAfterEffect = true;
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);

        self::assertSame(['examined' => 1, 'reaped' => 0, 'refused' => 1], $janitor->sweep());
        self::assertSame(['routing', 'execution', 'delete'], $this->runtime->calls);
        self::assertSame(['examined' => 1, 'reaped' => 1, 'refused' => 0], $janitor->sweep());
        self::assertSame(['routing', 'execution', 'delete', 'delete', 'absent'], $this->runtime->calls);
    }

    public function testSweepFailsLoudlyWhenCurrentGenerationHasNoDeadline(): void {
        $this->lifecycle->perform('create', 'tenant-a', 'site-a', self::operation('a'), [
            'intent_sha256' => hash('sha256', 'intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $this->mutateCurrent(static function (array &$current): void {
            $current['ttl'] = null;
        });
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('service deadline is corrupt');
        $janitor->sweep();
    }

    public function testSweepFailsLoudlyOnMalformedUnexpiredMutationAuthority(): void {
        $operation = self::operation('a');
        $identity = $this->lifecycle->perform('create', 'tenant-a', 'site-a', $operation, [
            'intent_sha256' => hash('sha256', 'intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $this->lifecycle->perform(
            'mutation-acquire',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + ['mutation_owner' => 'duo-env-materialize-' . $operation]
        );
        $this->mutateCurrent(static function (array &$current): void {
            $current['mutation']['state'] = 'foreign';
        });
        $janitor = new ExpiredPreviewJanitor($this->store, $this->lifecycle, fn (): int => $this->now);

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('mutation authority is corrupt');
        $janitor->sweep();
    }

    /** @return array{array<string,mixed>,array<string,mixed>,array<string,mixed>} */
    private function materializedAndTtl(bool $release): array {
        $operation = self::operation('a');
        $identity = $this->lifecycle->perform('create', 'tenant-a', 'site-a', $operation, [
            'intent_sha256' => hash('sha256', 'intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $fence = $this->lifecycle->perform(
            'mutation-acquire',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + ['mutation_owner' => 'duo-env-materialize-' . $operation]
        );
        $ttl = $this->lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + self::mutationInput($fence) + ['ttl_seconds' => 60]
        );
        if ($release) {
            $this->lifecycle->perform(
                'mutation-release',
                'tenant-a',
                'site-a',
                $operation,
                self::identityInput($identity) + self::mutationInput($fence)
            );
        }
        $this->runtime->calls = [];
        return [$identity, $fence, $ttl];
    }

    /** @return array<string,mixed> */
    private function current(): array {
        return $this->store->locked(static function ($session): array {
            $sites = array_values($session->state()['sites'] ?? []);
            if (!is_array($sites[0]['current'] ?? null)) {
                throw new \RuntimeException('test lifecycle current generation is absent');
            }
            return $sites[0]['current'];
        });
    }

    /** @param callable(array<string,mixed>&):void $change */
    private function mutateCurrent(callable $change): void {
        $this->store->locked(static function ($session) use ($change): void {
            $state = $session->state();
            $siteKey = array_key_first($state['sites'] ?? []);
            if (!is_string($siteKey) || !is_array($state['sites'][$siteKey]['current'] ?? null)) {
                throw new \RuntimeException('test lifecycle current generation is absent');
            }
            $change($state['sites'][$siteKey]['current']);
            $session->save($state);
        });
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function identityInput(array $identity): array {
        return [
            'expected_environment_identity' => $identity['environment_identity'],
            'expected_lease_generation' => $identity['lease_generation'],
            'expected_lease_id' => $identity['lease_id'],
            'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'expected_resource_id' => $identity['resource_id'],
        ];
    }

    /** @param array<string,mixed> $fence @return array<string,mixed> */
    private static function mutationInput(array $fence): array {
        return [
            'expected_mutation_generation' => $fence['mutation_generation'],
            'expected_mutation_id' => $fence['mutation_id'],
            'expected_mutation_owner' => $fence['mutation_owner'],
            'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
        ];
    }

    /** @param array<string,mixed> $ttl @return array<string,mixed> */
    private static function ttlInput(array $ttl, string $operationId): array {
        return [
            'expires_at' => $ttl['expires_at'],
            'generation' => $ttl['ttl_generation'],
            'lease_id' => $ttl['ttl_lease_id'],
            'operation_id' => $operationId,
            'receipt_sha256' => $ttl['ttl_receipt_sha256'],
            'state' => $ttl['ttl_state'],
        ];
    }

    private static function operation(string $character): string {
        return '20260818-120000-' . str_repeat($character, 24);
    }
}
