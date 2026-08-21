<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlAuthority.php';

/**
 * Publishes the lifecycle's exact held/released fence to command authority.
 *
 * PreviewSlotLifecycle always holds its own file lock before invoking this
 * interface. Implementations must never acquire the lifecycle lock while
 * holding another lock; the fixed lifecycle -> command-authority order lets an
 * in-flight command finish before release can become visible.
 */
interface MutationAuthorityPublisher {
    /** @param array<string,mixed> $target Exact ControlAuthority target tuple. */
    public function hold(string $tenantId, string $siteId, string $operationId, array $target): void;

    /** @param array<string,mixed> $target Exact ControlAuthority target tuple. */
    public function release(string $tenantId, string $siteId, string $operationId, array $target): void;
}

/**
 * Adapter for the separately persisted signed-command authority.
 *
 * Only a materialization fence authorizes workload commands. Later sleep and
 * reap fences exist solely to suspend/resume or compare-and-delete the provider
 * resource; publishing either as command authority would turn lifecycle
 * evidence into a fresh remote execution grant. The released materialization
 * authority therefore remains the command-plane tombstone while provider state
 * advances independently.
 */
final class ControlAuthorityMutationPublisher implements MutationAuthorityPublisher {
    public function __construct(private ControlAuthority $authority) {}

    public function hold(string $tenantId, string $siteId, string $operationId, array $target): void {
        $phase = self::phase($target, $operationId);
        if ($phase === 'materialize') {
            $this->authority->holdAuthority($tenantId, $siteId, $operationId, $target);
            return;
        }
        $this->assertReleasedMaterialization($tenantId, $siteId, $target);
    }

    public function release(string $tenantId, string $siteId, string $operationId, array $target): void {
        $phase = self::phase($target, $operationId);
        if ($phase === 'materialize') {
            $this->authority->releaseAuthority($tenantId, $siteId, $operationId, $target);
            return;
        }
        $this->assertReleasedMaterialization($tenantId, $siteId, $target);
    }

    /** @param array<string,mixed> $target */
    private static function phase(array $target, string $operationId): string {
        $owner = $target['mutation_owner'] ?? null;
        if (!is_string($owner)
            || preg_match(
                '/\Aduo-env-(materialize|reap|sleep)-([0-9]{8}-[0-9]{6}-[a-f0-9]{24})\z/D',
                $owner,
                $match
            ) !== 1) {
            throw new ControlRefusal(
                'mutation owner is not a closed materialize, sleep, or reap authority'
            );
        }
        if ($match[1] === 'materialize' && $match[2] !== $operationId) {
            throw new ControlRefusal('materialization command authority does not match its operation');
        }
        return $match[1];
    }

    /** @param array<string,mixed> $target */
    private function assertReleasedMaterialization(string $tenantId, string $siteId, array $target): void {
        $current = $this->authority->currentAuthority($tenantId, $siteId);
        if (!is_array($current) || ($current['state'] ?? null) !== 'released'
            || !is_array($current['target'] ?? null)) {
            throw new ControlRefusal('reap fence requires released materialization command authority');
        }
        foreach ([
            'environment_identity', 'lease_generation', 'lease_id',
            'ownership_receipt_sha256', 'resource_id',
        ] as $field) {
            if (($current['target'][$field] ?? null) !== ($target[$field] ?? null)) {
                throw new ControlRefusal("reap fence changed command authority resource identity '$field'");
            }
        }
        $previousGeneration = $current['target']['mutation_generation'] ?? null;
        $reapGeneration = $target['mutation_generation'] ?? null;
        if (!is_int($previousGeneration) || !is_int($reapGeneration)
            || $reapGeneration <= $previousGeneration) {
            throw new ControlRefusal('reap fence did not advance beyond released command authority');
        }
    }
}
