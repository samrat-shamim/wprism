<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/FileAuthorityStore.php';

/** One shared durable gate for the fleet-wide host-authority readiness result. */
final class ProductionHostPreflightReceipt {
    public const FORMAT = 'duo-cloud-production-host-preflight-receipt/v1';
    public const TTL_SECONDS = 60;

    private \Closure $clock;

    /** @param callable():int|null $clock */
    public function __construct(
        private FileAuthorityStore $store,
        private string $hostAuthoritySha256,
        ?callable $clock = null
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hostAuthoritySha256) !== 1) {
            throw new ControlRefusal('production host preflight authority digest is invalid');
        }
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function publish(array $evidence): array {
        $now = ($this->clock)();
        if ($now < 1 || ($evidence['host_authority_sha256'] ?? null) !== $this->hostAuthoritySha256) {
            throw new ControlRefusal('production host preflight evidence is invalid');
        }
        $receipt = [
            'configuration_sha256s' => $evidence['configuration_sha256s'] ?? null,
            'docker_root_sha256' => $evidence['docker_root_sha256'] ?? null,
            'evidence_sha256' => hash(
                'sha256',
                "duo-cloud-production-host-preflight-evidence/v1\0" . CanonicalJson::encode($evidence)
            ),
            'expires_at' => $now + self::TTL_SECONDS,
            'firewall_bindings' => $evidence['firewall_bindings'] ?? null,
            'format' => self::FORMAT,
            'host_authority_sha256' => $this->hostAuthoritySha256,
            'issued_at' => $now,
            'route_count' => $evidence['route_count'] ?? null,
            'storage_bindings' => $evidence['storage_bindings'] ?? null,
            'state' => 'ready',
        ];
        $this->save($receipt);
        return $receipt;
    }

    public function invalidate(): void {
        $this->save([
            'configuration_sha256s' => [],
            'docker_root_sha256' => str_repeat('0', 64),
            'evidence_sha256' => str_repeat('0', 64),
            'expires_at' => 0,
            'firewall_bindings' => 0,
            'format' => self::FORMAT,
            'host_authority_sha256' => $this->hostAuthoritySha256,
            'issued_at' => 0,
            'route_count' => 0,
            'storage_bindings' => 0,
            'state' => 'invalid',
        ]);
    }

    /** @return array<string,mixed> */
    public function assertCurrent(string $configurationSha256): array {
        $receipt = $this->store->stableRead();
        $this->validate($receipt, true);
        if (!in_array($configurationSha256, $receipt['configuration_sha256s'], true)) {
            throw new ControlRefusal('production host preflight receipt does not admit this worker');
        }
        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    private function save(array $receipt): void {
        $this->store->locked(static function (AuthorityStateSession $session) use ($receipt): void {
            $session->save($receipt);
        });
    }

    /** @param array<string,mixed> $receipt */
    private function validate(array $receipt, bool $requireCurrent): void {
        self::exactKeys($receipt, [
            'configuration_sha256s', 'docker_root_sha256', 'evidence_sha256',
            'expires_at', 'firewall_bindings',
            'format', 'host_authority_sha256', 'issued_at', 'route_count',
            'state', 'storage_bindings',
        ]);
        $now = ($this->clock)();
        if (($receipt['format'] ?? null) !== self::FORMAT
            || ($receipt['state'] ?? null) !== 'ready'
            || ($receipt['host_authority_sha256'] ?? null) !== $this->hostAuthoritySha256
            || !is_string($receipt['evidence_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['evidence_sha256']) !== 1
            || !is_string($receipt['docker_root_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['docker_root_sha256']) !== 1
            || !is_int($receipt['firewall_bindings'] ?? null)
            || $receipt['firewall_bindings'] < 0 || $receipt['firewall_bindings'] > 32
            || !is_int($receipt['route_count'] ?? null)
            || $receipt['route_count'] < 0 || $receipt['route_count'] > 32
            || !is_int($receipt['storage_bindings'] ?? null)
            || $receipt['storage_bindings'] < 0 || $receipt['storage_bindings'] > 32
            || !self::configurationSha256s($receipt['configuration_sha256s'] ?? null)
            || !is_int($receipt['issued_at'] ?? null) || !is_int($receipt['expires_at'] ?? null)
            || $receipt['issued_at'] < 1
            || $receipt['expires_at'] !== $receipt['issued_at'] + self::TTL_SECONDS
            || ($requireCurrent
                && ($now < $receipt['issued_at'] || $now >= $receipt['expires_at']))) {
            throw new ControlRefusal('production host preflight receipt is absent, stale, or changed');
        }
    }

    private static function configurationSha256s(mixed $value): bool {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 64) {
            return false;
        }
        $previous = null;
        foreach ($value as $digest) {
            if (!is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || ($previous !== null && strcmp($previous, $digest) >= 0)) {
                return false;
            }
            $previous = $digest;
        }
        return true;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('production host preflight receipt has missing or unknown fields');
        }
    }
}
