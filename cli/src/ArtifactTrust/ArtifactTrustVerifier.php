<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/HostContracts/ReleaseSelection.php';

/** Pure host-side verifier for detached release-family bytes. */
final class ArtifactTrustVerifier {
    public const RECEIPT_FORMAT = 'duo-artifact-trust-verification/v1';

    /**
     * @return array{format:string,release_family_sha256:string,target_install_sha256:string,review_bundle_sha256:string,projection_pack_sha256:string}
     */
    public static function verify(
        ReleaseSelection $selection,
        string $releaseFamilyBytes,
        string $targetInstallBytes,
        string $reviewBundleBytes,
        string $projectionPackBytes
    ): array {
        $familyDigest = 'sha256:' . hash('sha256', $releaseFamilyBytes);
        if (!hash_equals($selection->expectedReleaseFamilySha256, $familyDigest)) {
            throw new \RuntimeException('duo adopt: release family does not match the independently pinned digest');
        }
        $family = self::canonicalObject($releaseFamilyBytes, 'release family');
        if (($family['format'] ?? null) !== 'duo-release-family/v1') {
            throw new \RuntimeException('duo adopt: release family format is unsupported');
        }

        $actual = [
            'target_install_sha256' => 'sha256:' . hash('sha256', $targetInstallBytes),
            'review_bundle_sha256' => 'sha256:' . hash('sha256', $reviewBundleBytes),
            'projection_pack_sha256' => 'sha256:' . hash('sha256', $projectionPackBytes),
        ];
        foreach ($actual as $field => $digest) {
            if (!is_string($family[$field] ?? null) || !hash_equals($family[$field], $digest)) {
                throw new \RuntimeException("duo adopt: release family $field does not bind the supplied bytes");
            }
        }

        $review = self::canonicalObject($reviewBundleBytes, 'review bundle');
        if (($review['format'] ?? null) !== 'duo-review-bundle/v2'
            || !is_string($review['authority_id'] ?? null)
            || !is_string($review['key_id'] ?? null)
            || !in_array($review['authority_id'], $selection->trustedAuthorityIds, true)
            || !in_array($review['key_id'], $selection->trustedKeyIds, true)) {
            throw new \RuntimeException('duo adopt: review bundle is not issued by a selected trust root');
        }

        return ['format' => self::RECEIPT_FORMAT, 'release_family_sha256' => $familyDigest] + $actual;
    }

    /** @return array<string,mixed> */
    private static function canonicalObject(string $bytes, string $label): array {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("duo adopt: $label is malformed JSON", 0, $e);
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || self::canonical($decoded) . "\n" !== $bytes) {
            throw new \RuntimeException("duo adopt: $label is not canonical JSON");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $value */
    public static function canonical(array $value): string {
        return (string) json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function normalize(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::normalize($child);
        return $value;
    }
}
