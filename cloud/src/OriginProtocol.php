<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlRefusal.php';

/** Closed identifiers and formats shared by the authority and its adapters. */
final class OriginProtocol {
    public const ENVELOPE_FORMAT = 'duo-cloud-origin-signed-envelope/v1';
    public const PAIR_BEGIN_REQUEST = 'duo-cloud-origin-pair-begin-request/v1';
    public const PAIR_BEGIN_RESPONSE = 'duo-cloud-origin-pair-begin-response/v1';
    public const PAIR_POLL_REQUEST = 'duo-cloud-origin-pair-poll-request/v1';
    public const PAIR_POLL_RESPONSE = 'duo-cloud-origin-pair-poll-response/v1';
    public const DEMAND_POLL_REQUEST = 'duo-cloud-origin-demand-poll-request/v1';
    public const DEMAND_POLL_RESPONSE = 'duo-cloud-origin-demand-poll-response/v1';
    public const DEMAND_FORMAT = 'duo-cloud-origin-export-demand/v1';
    public const MANIFEST_FORMAT = 'duo-cloud-origin-export-manifest/v1';
    public const ANNOUNCE_REQUEST = 'duo-cloud-origin-export-announce-request/v1';
    public const ANNOUNCE_RESPONSE = 'duo-cloud-origin-export-announce-response/v1';
    public const MISSING_REQUEST = 'duo-cloud-origin-export-missing-request/v1';
    public const MISSING_RESPONSE = 'duo-cloud-origin-export-missing-response/v1';
    public const CHUNK_REQUEST = 'duo-cloud-origin-export-chunk-request/v1';
    public const CHUNK_RESPONSE = 'duo-cloud-origin-export-chunk-response/v1';
    public const COMMIT_REQUEST = 'duo-cloud-origin-export-commit-request/v1';
    public const COMMIT_RESPONSE = 'duo-cloud-origin-export-commit-response/v1';
    public const ROTATE_REQUEST = 'duo-cloud-origin-key-rotation-request/v1';
    public const ROTATE_RESPONSE = 'duo-cloud-origin-key-rotation-response/v1';
    public const ROTATE_PROOF = 'duo-cloud-origin-key-possession/v1';
    public const REVOKE_REQUEST = 'duo-cloud-origin-revoke-request/v1';
    public const REVOKE_RESPONSE = 'duo-cloud-origin-revoke-response/v1';
    public const REFUSAL = 'duo-cloud-origin-refusal/v1';

    public const PAIR_BEGIN_PATH = '/v1/origin/pair/begin';
    public const PAIR_POLL_PATH = '/v1/origin/pair/poll';
    public const DEMAND_POLL_PATH = '/v1/origin/demand/poll';
    public const ANNOUNCE_PATH = '/v1/origin/export/announce';
    public const MISSING_PATH = '/v1/origin/export/missing';
    public const CHUNK_PATH = '/v1/origin/export/chunk';
    public const COMMIT_PATH = '/v1/origin/export/commit';
    public const ROTATE_PATH = '/v1/origin/key/rotate';
    public const REVOKE_PATH = '/v1/origin/revoke';

    public static function originKeyId(string $publicKey): string {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new ControlRefusal('origin public key is not an Ed25519 public key');
        }
        return hash('sha256', "duo-cloud-origin-key/v1\0" . $publicKey);
    }

    public static function pairBeginRequestId(string $attemptId, string $originKeyId): string {
        return self::id('duo-cloud-origin-pair-begin/v1', $attemptId, $originKeyId);
    }

    public static function pairPollRequestId(string $attemptId, string $pairingId, int $sequence): string {
        return self::id('duo-cloud-origin-pair-poll/v1', $attemptId, $pairingId, $sequence);
    }

    public static function demandId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $nonce,
        string $expectedCommit
    ): string {
        return self::id(
            'duo-cloud-origin-demand/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $nonce,
            $expectedCommit
        );
    }

    public static function demandPollRequestId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $originKeyId,
        int $afterGeneration,
        int $sequence
    ): string {
        return self::id(
            'duo-cloud-origin-demand-poll/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $originKeyId,
            $afterGeneration,
            $sequence
        );
    }

    public static function exportId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        string $manifestSha256
    ): string {
        return self::id(
            'duo-cloud-origin-export/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $demandId,
            $manifestSha256
        );
    }

    public static function announceRequestId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $originKeyId,
        int $demandGeneration,
        string $demandId,
        string $manifestSha256
    ): string {
        return self::id(
            'duo-cloud-origin-export-announce/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $originKeyId,
            $demandGeneration,
            $demandId,
            $manifestSha256
        );
    }

    public static function missingRequestId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $originKeyId,
        int $demandGeneration,
        string $demandId,
        string $exportId,
        string $manifestSha256,
        int $sequence
    ): string {
        return self::id(
            'duo-cloud-origin-export-missing/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $originKeyId,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256,
            $sequence
        );
    }

    public static function chunkRequestId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $originKeyId,
        int $demandGeneration,
        string $demandId,
        string $exportId,
        string $manifestSha256,
        string $chunkSha256,
        int $chunkSize
    ): string {
        return self::id(
            'duo-cloud-origin-export-chunk/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $originKeyId,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256,
            $chunkSha256,
            $chunkSize
        );
    }

    public static function commitRequestId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $originKeyId,
        int $demandGeneration,
        string $demandId,
        string $exportId,
        string $manifestSha256
    ): string {
        return self::id(
            'duo-cloud-origin-export-commit/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $originKeyId,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256
        );
    }

    public static function rotationRequestId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $newOriginKeyId
    ): string {
        return self::id(
            'duo-cloud-origin-key-rotation/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $newOriginKeyId
        );
    }

    public static function revokeRequestId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $reason
    ): string {
        return self::id(
            'duo-cloud-origin-revoke/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $reason
        );
    }

    public static function sessionId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        string $expectedCommit
    ): string {
        return self::id(
            'duo-cloud-origin-session/v1',
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $demandId,
            $expectedCommit
        );
    }

    /** @param string|int ...$fields */
    private static function id(string $domain, string|int ...$fields): string {
        $bytes = $domain;
        foreach ($fields as $field) {
            $bytes .= "\0" . (is_int($field) ? (string) $field : $field);
        }
        return hash('sha256', $bytes);
    }
}
