<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/FileAuthorityStore.php';
require_once dirname(__DIR__) . '/src/ImmutableOciReference.php';
/** Short-lived durable gate between privileged active checks and public HTTP. */
final class ProductionPreflightReceipt {
    public const FORMAT = 'duo-cloud-production-preflight-receipt/v1';
    public const TTL_SECONDS = 60;

    private \Closure $clock;

    /** @param callable():int|null $clock */
    public function __construct(
        private FileAuthorityStore $store,
        private string $configurationSha256,
        private string $image,
        private string $seccompProfileSha256,
        private string $requiredPathsSha256,
        private string $hostAuthoritySha256,
        ?callable $clock = null
    ) {
        foreach ([
            $configurationSha256, $seccompProfileSha256, $requiredPathsSha256,
            $hostAuthoritySha256,
        ] as $digest) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new ControlRefusal('production preflight receipt digest is invalid');
            }
        }
        if (!ImmutableOciReference::valid($image)) {
            throw new ControlRefusal('production preflight receipt image is not immutable');
        }
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function publish(array $evidence): array {
        $now = ($this->clock)();
        $deploymentProofsSha256 = $evidence['deployment_proofs_sha256'] ?? null;
        if ($now < 1 || ($evidence['host_authority_sha256'] ?? null) !== $this->hostAuthoritySha256
            || !is_string($deploymentProofsSha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $deploymentProofsSha256) !== 1) {
            throw new ControlRefusal('production preflight receipt clock is invalid');
        }
        $receipt = [
            'configuration_sha256' => $this->configurationSha256,
            'deployment_proofs_sha256' => $deploymentProofsSha256,
            'evidence_sha256' => hash(
                'sha256',
                "duo-cloud-production-preflight-evidence/v1\0" . CanonicalJson::encode($evidence)
            ),
            'expires_at' => $now + self::TTL_SECONDS,
            'format' => self::FORMAT,
            'host_authority_sha256' => $this->hostAuthoritySha256,
            'image' => $this->image,
            'issued_at' => $now,
            'required_paths_sha256' => $this->requiredPathsSha256,
            'seccomp_profile_sha256' => $this->seccompProfileSha256,
        ];
        $this->store->locked(static function (AuthorityStateSession $session) use ($receipt): void {
            $session->save($receipt);
        });
        return $receipt;
    }

    /** @return array<string,mixed> */
    public function assertCurrent(string $deploymentProofsSha256): array {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $deploymentProofsSha256) !== 1) {
            throw new ControlRefusal('production preflight receipt is absent, stale, or changed');
        }
        $receipt = $this->store->stableRead();
        self::exactKeys($receipt, [
            'configuration_sha256', 'deployment_proofs_sha256', 'evidence_sha256',
            'expires_at', 'format',
            'host_authority_sha256', 'image', 'issued_at', 'required_paths_sha256',
            'seccomp_profile_sha256',
        ]);
        $now = ($this->clock)();
        if (($receipt['format'] ?? null) !== self::FORMAT
            || ($receipt['configuration_sha256'] ?? null) !== $this->configurationSha256
            || !hash_equals(
                $deploymentProofsSha256,
                (string) ($receipt['deployment_proofs_sha256'] ?? '')
            )
            || ($receipt['image'] ?? null) !== $this->image
            || ($receipt['seccomp_profile_sha256'] ?? null) !== $this->seccompProfileSha256
            || ($receipt['required_paths_sha256'] ?? null) !== $this->requiredPathsSha256
            || ($receipt['host_authority_sha256'] ?? null) !== $this->hostAuthoritySha256
            || !is_string($receipt['evidence_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['evidence_sha256']) !== 1
            || !is_int($receipt['issued_at'] ?? null) || !is_int($receipt['expires_at'] ?? null)
            || $receipt['issued_at'] < 1
            || $receipt['expires_at'] !== $receipt['issued_at'] + self::TTL_SECONDS
            || $now < $receipt['issued_at'] || $now >= $receipt['expires_at']) {
            throw new ControlRefusal('production preflight receipt is absent, stale, or changed');
        }
        return $receipt;
    }

    public function invalidate(): void {
        $receipt = [
            'configuration_sha256' => $this->configurationSha256,
            'deployment_proofs_sha256' => str_repeat('0', 64),
            'evidence_sha256' => str_repeat('0', 64),
            'expires_at' => 0,
            'format' => self::FORMAT,
            'host_authority_sha256' => $this->hostAuthoritySha256,
            'image' => $this->image,
            'issued_at' => 0,
            'required_paths_sha256' => $this->requiredPathsSha256,
            'seccomp_profile_sha256' => $this->seccompProfileSha256,
        ];
        $this->store->locked(static function (AuthorityStateSession $session) use ($receipt): void {
            $session->save($receipt);
        });
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('production preflight receipt has missing or unknown fields');
        }
    }
}
