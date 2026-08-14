<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Independently pinned release selection supplied by the release authority. */
final class ReleaseSelection {
    public const FORMAT = 'duo-release-selection/v1';

    /**
     * @param array{host:string,agent:string,recovery:string} $expectedProtocols
     * @param array<string,string> $trustedReviewKeys authority-id:key-id => base64 Ed25519 public key
     */
    public function __construct(
        public readonly string $bundlePath,
        public readonly string $expectedReleaseFamilySha256,
        public readonly string $expectedTargetReleaseSetSha256,
        public readonly string $expectedHostArtifactSha256,
        public readonly array $expectedProtocols,
        public readonly array $trustedReviewKeys,
        public readonly string $pinRecordSha256
    ) {
        if ($bundlePath === '' || $bundlePath[0] !== '/' || str_contains($bundlePath, "\0")) {
            throw new \RuntimeException('duo adopt: release selection requires an absolute bundle path');
        }
        foreach ([$expectedReleaseFamilySha256, $expectedTargetReleaseSetSha256, $expectedHostArtifactSha256, $pinRecordSha256] as $digest) {
            self::assertDigest($digest);
        }
        self::assertProtocols($expectedProtocols);
        self::assertTrustedKeys($trustedReviewKeys);
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self {
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'bundle_path', 'expected_host_artifact_sha256', 'expected_protocols',
            'expected_release_family_sha256', 'expected_target_release_set_sha256',
            'format', 'pin_record_sha256', 'trusted_review_keys',
        ] || ($input['format'] ?? null) !== self::FORMAT
            || !is_string($input['bundle_path'] ?? null)
            || !is_string($input['expected_release_family_sha256'] ?? null)
            || !is_string($input['expected_target_release_set_sha256'] ?? null)
            || !is_string($input['expected_host_artifact_sha256'] ?? null)
            || !is_string($input['pin_record_sha256'] ?? null)
            || !is_array($input['expected_protocols'] ?? null)
            || !is_array($input['trusted_review_keys'] ?? null)) {
            throw new \RuntimeException('duo adopt: release selection record is malformed');
        }
        return new self(
            $input['bundle_path'],
            $input['expected_release_family_sha256'],
            $input['expected_target_release_set_sha256'],
            $input['expected_host_artifact_sha256'],
            $input['expected_protocols'],
            $input['trusted_review_keys'],
            $input['pin_record_sha256']
        );
    }

    private static function assertDigest(string $digest): void {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException('duo adopt: release selection contains a malformed digest');
        }
    }

    /** @param array<string,mixed> $protocols */
    private static function assertProtocols(array $protocols): void {
        $keys = array_keys($protocols);
        sort($keys, SORT_STRING);
        if ($keys !== ['agent', 'host', 'recovery']) {
            throw new \RuntimeException('duo adopt: release selection protocol tuple is malformed');
        }
        foreach ($protocols as $protocol) {
            if (!is_string($protocol) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $protocol) !== 1) {
                throw new \RuntimeException('duo adopt: release selection protocol tuple is malformed');
            }
        }
    }

    /** @param array<string,mixed> $keys */
    private static function assertTrustedKeys(array $keys): void {
        if ($keys === [] || array_is_list($keys)) {
            throw new \RuntimeException('duo adopt: release selection needs trusted review keys');
        }
        foreach ($keys as $identity => $encoded) {
            $publicKey = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (!is_string($identity)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}:[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $identity) !== 1
                || $publicKey === false
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \RuntimeException('duo adopt: release selection contains an invalid trusted review key');
            }
        }
    }
}
