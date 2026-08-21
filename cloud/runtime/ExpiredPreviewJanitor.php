<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/FileAuthorityStore.php';
require_once dirname(__DIR__) . '/src/PreviewSlotLifecycle.php';

/** Bounded service-owned enforcement of lifecycle TTL evidence. */
final class ExpiredPreviewJanitor {
    /** @var \Closure():int */
    private \Closure $clock;

    public function __construct(
        private FileAuthorityStore $store,
        private PreviewSlotLifecycle $lifecycle,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
    }

    /** @return array{examined:int,reaped:int,refused:int} */
    public function sweep(int $limit = 100): array {
        if ($limit < 1 || $limit > 1000) {
            throw new ControlRefusal('expired preview sweep limit must be from one through 1000');
        }
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) {
            throw new ControlRefusal('expired preview sweep clock is invalid');
        }
        $candidates = $this->candidates($now, $limit);
        $reaped = 0;
        $refused = 0;
        foreach ($candidates as $candidate) {
            try {
                $result = $this->lifecycle->reapExpired(
                    $candidate['tenant_id'],
                    $candidate['site_id'],
                    $candidate['operation_id'],
                    self::identityInput($candidate) + ['compare_and_reap' => true],
                    $candidate['ttl']
                );
                if (($result['disposition'] ?? null) !== 'destroyed') {
                    throw new ControlRefusal('expired preview reap did not return destroyed disposition');
                }
                $reaped++;
            } catch (ControlRefusal) {
                // A concurrent exact state change is a safe refusal. The next
                // bounded pass re-reads authority instead of deleting stale.
                $refused++;
            }
        }
        return ['examined' => count($candidates), 'reaped' => $reaped, 'refused' => $refused];
    }

    /** @return list<array<string,mixed>> */
    private function candidates(int $now, int $limit): array {
        return $this->store->locked(function (AuthorityStateSession $session) use ($now, $limit): array {
            $state = $session->state();
            $sites = $state['sites'] ?? [];
            if (!is_array($sites) || (array_is_list($sites) && $sites !== [])) {
                throw new ControlRefusal('expired preview sweep lifecycle site map is invalid');
            }
            ksort($sites, SORT_STRING);
            $result = [];
            foreach ($sites as $site) {
                if (count($result) >= $limit) {
                    break;
                }
                if (!is_array($site) || !is_array($site['current'] ?? null)) {
                    continue;
                }
                $current = $site['current'];
                [$ttl, $expiresAt] = self::candidateAuthority($current);
                if ($expiresAt > $now) {
                    continue;
                }
                $operationId = is_array($current['reap'] ?? null)
                    ? ($current['reap']['operation_id'] ?? null)
                    : self::operationId($ttl);
                if (!is_string($operationId)) {
                    throw new ControlRefusal('expired preview sweep operation identity is invalid');
                }
                $result[] = [
                    'environment_identity' => $current['environment_identity'],
                    'lease_generation' => $current['lease_generation'],
                    'lease_id' => $current['lease_id'],
                    'operation_id' => $operationId,
                    'ownership_receipt_sha256' => $current['ownership_receipt_sha256'],
                    'resource_id' => $current['resource_id'],
                    'site_id' => $site['site_id'],
                    'tenant_id' => $site['tenant_id'],
                    'ttl' => [
                        'expires_at' => $ttl['expires_at'],
                        'generation' => $ttl['generation'],
                        'lease_id' => $ttl['lease_id'],
                        'operation_id' => $ttl['operation_id'],
                        'receipt_sha256' => $ttl['receipt_sha256'],
                        'state' => $ttl['state'],
                    ],
                ];
            }
            return $result;
        });
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private static function identityInput(array $candidate): array {
        return [
            'expected_environment_identity' => $candidate['environment_identity'],
            'expected_lease_generation' => $candidate['lease_generation'],
            'expected_lease_id' => $candidate['lease_id'],
            'expected_ownership_receipt_sha256' => $candidate['ownership_receipt_sha256'],
            'expected_resource_id' => $candidate['resource_id'],
        ];
    }

    /**
     * A present lifecycle record is authority, not optional inventory. Silently
     * skipping its corrupt deadline or fence would turn a bounded orphan into
     * an indefinitely retained workload while reporting examined=0.
     *
     * @param array<string,mixed> $current
     * @return array{0:array<string,mixed>,1:int}
     */
    private static function candidateAuthority(array $current): array {
        $state = $current['state'] ?? null;
        if (!in_array($state, [
            'acquiring', 'asleep', 'present', 'reaping', 'sleeping', 'waking',
        ], true)) {
            throw new ControlRefusal('expired preview sweep current lifecycle state is corrupt');
        }
        $ttl = $current['ttl'] ?? null;
        if (!is_array($ttl) || array_is_list($ttl)) {
            throw new ControlRefusal('expired preview sweep service deadline is corrupt');
        }
        self::exactKeys($ttl, [
            'expires_at', 'generation', 'lease_id', 'operation_id', 'receipt_sha256', 'state',
        ], 'expired preview sweep service deadline');
        $expires = $ttl['expires_at'] ?? null;
        $parsed = is_string($expires)
            ? \DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                $expires,
                new \DateTimeZone('UTC')
            )
            : false;
        if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z') !== $expires
            || !is_int($ttl['generation'] ?? null) || $ttl['generation'] < 1
            || !self::identifier($ttl['lease_id'] ?? null)
            || !self::operationIdValue($ttl['operation_id'] ?? null)
            || !self::sha256($ttl['receipt_sha256'] ?? null)
            || !in_array($ttl['state'] ?? null, ['active', 'provisional'], true)
            || ($current['last_ttl_generation'] ?? null) !== $ttl['generation']) {
            throw new ControlRefusal('expired preview sweep service deadline is corrupt');
        }

        $mutation = $current['mutation'] ?? null;
        if ($mutation === null) {
            if ($state === 'reaping' || ($current['last_mutation_generation'] ?? null) !== 0) {
                throw new ControlRefusal('expired preview sweep mutation authority is corrupt');
            }
            return [$ttl, $parsed->getTimestamp()];
        }
        if (!is_array($mutation) || array_is_list($mutation)) {
            throw new ControlRefusal('expired preview sweep mutation authority is corrupt');
        }
        self::exactKeys($mutation, [
            'generation', 'held_receipt_sha256', 'id', 'operation_id', 'owner',
            'receipt_sha256', 'state',
        ], 'expired preview sweep mutation authority');
        $mutationState = $mutation['state'] ?? null;
        if (!is_int($mutation['generation'] ?? null) || $mutation['generation'] < 1
            || !self::identifier($mutation['id'] ?? null)
            || !self::operationIdValue($mutation['operation_id'] ?? null)
            || !is_string($mutation['owner'] ?? null)
            || preg_match(
                '/\Aduo-env-(?:materialize|reap|sleep)-[0-9]{8}-[0-9]{6}-[a-f0-9]{24}\z/D',
                $mutation['owner']
            ) !== 1
            || !self::sha256($mutation['held_receipt_sha256'] ?? null)
            || !self::sha256($mutation['receipt_sha256'] ?? null)
            || !in_array($mutationState, [
                'held', 'publishing', 'released', 'releasing', 'reaping',
            ], true)
            || ($current['last_mutation_generation'] ?? null) !== $mutation['generation']
            || $mutationState !== 'released'
                && $mutation['receipt_sha256'] !== $mutation['held_receipt_sha256']
            || ($state === 'reaping') !== ($mutationState === 'reaping')) {
            throw new ControlRefusal('expired preview sweep mutation authority is corrupt');
        }
        return [$ttl, $parsed->getTimestamp()];
    }

    /** @param array<string,mixed> $ttl */
    private static function operationId(array $ttl): string {
        $timestamp = strtotime((string) ($ttl['expires_at'] ?? ''));
        $receipt = $ttl['receipt_sha256'] ?? null;
        if (!is_int($timestamp) || !is_string($receipt)
            || preg_match('/\A[a-f0-9]{64}\z/D', $receipt) !== 1) {
            throw new ControlRefusal('expired preview TTL cannot derive a reap operation id');
        }
        return gmdate('Ymd-His', $timestamp) . '-' . substr(
            hash('sha256', "duo-cloud-expired-preview-reap/v1\0$receipt"),
            0,
            24
        );
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal("$label has missing or unknown fields");
        }
    }

    private static function identifier(mixed $value): bool {
        return is_string($value)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:@+-]{0,127}\z/D', $value) === 1;
    }

    private static function operationIdValue(mixed $value): bool {
        return is_string($value)
            && preg_match('/\A[0-9]{8}-[0-9]{6}-[a-f0-9]{24}\z/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
