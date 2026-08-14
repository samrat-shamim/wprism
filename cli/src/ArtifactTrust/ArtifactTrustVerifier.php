<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/HostContracts/ReleaseSelection.php';

/** Pure host-side verifier for detached, signed release-family bytes. */
final class ArtifactTrustVerifier {
    public const RECEIPT_FORMAT = 'duo-artifact-trust-verification/v1';

    /** @return array<string,string> */
    public static function verify(
        ReleaseSelection $selection,
        string $pinRecordBytes,
        string $releaseFamilyBytes,
        string $targetReleaseSetBytes,
        string $hostArtifactBytes,
        string $targetInstallBytes,
        string $reviewEnvelopeBytes,
        string $projectionPackBytes
    ): array {
        self::assertDigest($selection->pinRecordSha256, $pinRecordBytes, 'selection pin record');
        $pin = self::canonicalObject($pinRecordBytes, 'selection pin record');
        self::assertClosedKeys($pin, [
            'expected_host_artifact_sha256', 'expected_protocols',
            'expected_release_family_sha256', 'expected_target_release_set_sha256',
            'format', 'trusted_review_keys',
        ], 'selection pin record');
        if (($pin['format'] ?? null) !== 'duo-release-selection-pin/v1'
            || $pin['expected_release_family_sha256'] !== $selection->expectedReleaseFamilySha256
            || $pin['expected_target_release_set_sha256'] !== $selection->expectedTargetReleaseSetSha256
            || $pin['expected_host_artifact_sha256'] !== $selection->expectedHostArtifactSha256
            || $pin['expected_protocols'] !== $selection->expectedProtocols
            || $pin['trusted_review_keys'] !== $selection->trustedReviewKeys) {
            throw new \RuntimeException('duo adopt: selection pin record disagrees with the trusted selection');
        }

        self::assertDigest($selection->expectedReleaseFamilySha256, $releaseFamilyBytes, 'release family');
        self::assertDigest($selection->expectedTargetReleaseSetSha256, $targetReleaseSetBytes, 'target release set');
        self::assertDigest($selection->expectedHostArtifactSha256, $hostArtifactBytes, 'host artifact');

        $family = self::canonicalObject($releaseFamilyBytes, 'release family');
        self::assertClosedKeys($family, ['format','host_artifact_sha256','protocols','target_release_set_sha256'], 'release family');
        if (($family['format'] ?? null) !== 'duo-release-family/v1'
            || ($family['target_release_set_sha256'] ?? null) !== $selection->expectedTargetReleaseSetSha256
            || ($family['host_artifact_sha256'] ?? null) !== $selection->expectedHostArtifactSha256
            || ($family['protocols'] ?? null) !== $selection->expectedProtocols) {
            throw new \RuntimeException('duo adopt: release family disagrees with the independently pinned release set, host, or protocol tuple');
        }

        $set = self::canonicalObject($targetReleaseSetBytes, 'target release set');
        self::assertClosedKeys($set, ['format','projection_pack_sha256','review_envelope_sha256','target_install_sha256'], 'target release set');
        if (($set['format'] ?? null) !== 'duo-target-release-set/v1') {
            throw new \RuntimeException('duo adopt: target release set format is unsupported');
        }
        $actual = [
            'target_install_sha256' => self::digest($targetInstallBytes),
            'review_envelope_sha256' => self::digest($reviewEnvelopeBytes),
            'projection_pack_sha256' => self::digest($projectionPackBytes),
        ];
        foreach ($actual as $field => $digest) {
            if (($set[$field] ?? null) !== $digest) {
                throw new \RuntimeException("duo adopt: target release set $field does not bind the supplied bytes");
            }
        }

        $review = self::verifyReviewEnvelope($selection, $reviewEnvelopeBytes);

        $projection = self::canonicalObject($projectionPackBytes, 'projection pack');
        self::assertClosedKeys($projection, ['format','review_envelope_sha256','reviewed_payload_sha256'], 'projection pack');
        if (($projection['format'] ?? null) !== 'duo-projection-pack/v1'
            || ($projection['review_envelope_sha256'] ?? null) !== self::digest($reviewEnvelopeBytes)
            || ($projection['reviewed_payload_sha256'] ?? null) !== self::digest(self::canonical($review['payload']) . "\n")) {
            throw new \RuntimeException('duo adopt: projection pack does not agree with its exact reviewed input');
        }

        return [
            'format' => self::RECEIPT_FORMAT,
            'selection_pin_sha256' => self::digest($pinRecordBytes),
            'release_family_sha256' => self::digest($releaseFamilyBytes),
            'target_release_set_sha256' => self::digest($targetReleaseSetBytes),
            'host_artifact_sha256' => self::digest($hostArtifactBytes),
        ] + $actual;
    }

    /** @return array<string,mixed> */
    private static function verifyReviewEnvelope(ReleaseSelection $selection, string $reviewEnvelopeBytes): array {
        $review = self::canonicalObject($reviewEnvelopeBytes, 'review envelope');
        self::assertClosedKeys($review, ['authority_id','format','key_id','payload','signature'], 'review envelope');
        if (($review['format'] ?? null) !== 'duo-review-envelope/v1'
            || !is_string($review['authority_id'] ?? null)
            || !is_string($review['key_id'] ?? null)
            || !is_array($review['payload'] ?? null) || array_is_list($review['payload'])
            || !is_string($review['signature'] ?? null)) {
            throw new \RuntimeException('duo adopt: review envelope is malformed');
        }
        $trustIdentity = $review['authority_id'] . ':' . $review['key_id'];
        $publicKey = isset($selection->trustedReviewKeys[$trustIdentity])
            ? base64_decode($selection->trustedReviewKeys[$trustIdentity], true)
            : false;
        $signature = base64_decode($review['signature'], true);
        $signedClaims = $review;
        unset($signedClaims['signature']);
        if (!is_string($publicKey) || !is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, self::canonical($signedClaims), $publicKey)) {
            throw new \RuntimeException('duo adopt: review envelope lacks a valid signature from the selected trust root');
        }
        if (($review['payload']['format'] ?? null) !== 'duo-review-bundle/v2') {
            throw new \RuntimeException('duo adopt: reviewed payload format is unsupported');
        }
        return $review;
    }

    private static function assertDigest(string $expected, string $bytes, string $label): void {
        if (!hash_equals($expected, self::digest($bytes))) {
            throw new \RuntimeException("duo adopt: $label does not match the independently pinned digest");
        }
    }

    private static function digest(string $bytes): string {
        return 'sha256:' . hash('sha256', $bytes);
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertClosedKeys(array $value, array $expected, string $label): void {
        $keys = array_keys($value); sort($keys, SORT_STRING);
        $expectedKeys = $expected; sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new \RuntimeException("duo adopt: $label has unrecognized or missing fields");
        }
    }

    /** @return array<string,mixed> */
    private static function canonicalObject(string $bytes, string $label): array {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("duo adopt: $label is malformed JSON", 0, $e);
        }
        if (!is_array($decoded) || array_is_list($decoded) || self::canonical($decoded) . "\n" !== $bytes) {
            throw new \RuntimeException("duo adopt: $label is not canonical JSON");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $value */
    public static function canonical(array $value): string {
        return (string) json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(static fn(mixed $item): mixed => self::normalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::normalize($child);
        return $value;
    }
}
