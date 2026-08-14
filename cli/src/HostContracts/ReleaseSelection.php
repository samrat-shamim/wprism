<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Independently pinned release-family selection supplied to adoption.
 *
 * The release producer may assemble a family manifest, but it cannot mint the
 * expected digest or trust roots represented here. Those arrive from a
 * non-co-located operator/release-authority record.
 */
final class ReleaseSelection {
    public const FORMAT = 'duo-release-selection/v1';

    /**
     * @param list<string> $trustedAuthorityIds
     * @param list<string> $trustedKeyIds
     */
    public function __construct(
        public readonly string $bundlePath,
        public readonly string $expectedReleaseFamilySha256,
        public readonly array $trustedAuthorityIds,
        public readonly array $trustedKeyIds,
        public readonly string $pinRecordSha256
    ) {
        if ($bundlePath === '' || $bundlePath[0] !== '/' || str_contains($bundlePath, "\0")) {
            throw new \RuntimeException('duo adopt: release selection requires an absolute bundle path');
        }
        foreach ([$expectedReleaseFamilySha256, $pinRecordSha256] as $digest) {
            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \RuntimeException('duo adopt: release selection contains a malformed digest');
            }
        }
        self::assertIds($trustedAuthorityIds, 'authority');
        self::assertIds($trustedKeyIds, 'key');
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self {
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'bundle_path', 'expected_release_family_sha256', 'format',
            'pin_record_sha256', 'trusted_authority_ids', 'trusted_key_ids',
        ] || ($input['format'] ?? null) !== self::FORMAT
            || !is_string($input['bundle_path'] ?? null)
            || !is_string($input['expected_release_family_sha256'] ?? null)
            || !is_string($input['pin_record_sha256'] ?? null)
            || !is_array($input['trusted_authority_ids'] ?? null)
            || !is_array($input['trusted_key_ids'] ?? null)) {
            throw new \RuntimeException('duo adopt: release selection record is malformed');
        }
        return new self(
            $input['bundle_path'],
            $input['expected_release_family_sha256'],
            array_values($input['trusted_authority_ids']),
            array_values($input['trusted_key_ids']),
            $input['pin_record_sha256']
        );
    }

    /** @param list<mixed> $values */
    private static function assertIds(array $values, string $label): void {
        if ($values === [] || !array_is_list($values)) {
            throw new \RuntimeException("duo adopt: release selection needs at least one trusted $label id");
        }
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1
                || isset($seen[$value])) {
                throw new \RuntimeException("duo adopt: release selection contains an invalid trusted $label id");
            }
            $seen[$value] = true;
        }
    }
}
