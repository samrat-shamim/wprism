<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/PrivateFileBytes.php';
require_once __DIR__ . '/../Kernel/Uuid.php';

/**
 * Operator-reviewed intent, never a database ownership or commit witness.
 *
 * The externally supplied SHA binds the exact once-read canonical bytes.
 * A well-formed file (including its hashes) grants no write authority: the
 * fork service must freshly prove every preimage under its own transaction.
 * Bodies/passwords are not serialized here; only their bounded row digest.
 */
final readonly class IdentityForkRequest {
    public const FORMAT = 'wprism-identity-fork-request/v1';
    public const MAX_BYTES = 16384;
    public const MAX_LIFETIME_SECONDS = 900;
    private const DIGEST_FIELDS = [
        'canonical_preimage_sha256', 'database_target_sha256', 'identity_postimage_sha256',
        'identity_preimage_sha256', 'ledger_preimage_sha256', 'manifest_hash',
        'physical_rows_sha256', 'repository_revision', 'site_hash',
    ];
    private const FIELDS = [
        'canonical_preimage_sha256', 'created_at', 'database_target_sha256', 'expires_at', 'format',
        'identity_postimage_sha256', 'identity_preimage_sha256', 'ledger_preimage_sha256',
        'manifest_hash', 'new_uuid', 'old_uuid', 'operation_id', 'original_post_id',
        'physical_rows_sha256', 'post_type', 'repository_revision', 'selected_meta_id',
        'selected_post_id', 'site_hash',
    ];

    /** @param array<string,int|string> $document */
    private function __construct(private array $document, private string $sha256) {}

    /**
     * Caller has already observed the proposal; publication alone writes no DB.
     * The existing private directory belongs to the invoking effective UID.
     *
     * @param array<string,mixed> $document
     */
    public static function create(array $document, string $parent, string $name): self {
        self::assert_document($document);
        $bytes = Canon::encode($document);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('wprism: identity-fork request exceeds its byte bound');
        }
        $sha256 = PrivateFileBytes::create($parent, $name, $bytes);
        return new self($document, $sha256);
    }

    /** Exact hash must come from the reviewed preview, not from inside this file. */
    public static function read(string $parent, string $name, string $expectedSha256): self {
        self::assert_digest($expectedSha256);
        $bytes = PrivateFileBytes::read($parent, $name, self::MAX_BYTES);
        if (!hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('wprism: identity-fork request differs from the reviewed bytes');
        }
        try {
            $document = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \RuntimeException('wprism: identity-fork request is not canonical JSON', 0, $failure);
        }
        self::assert_document($document);
        if (Canon::encode($document) !== $bytes) {
            // Duplicate JSON keys, alternate spellings and reordered keys may
            // decode alike; they are not the one reviewed wire representation.
            throw new \RuntimeException('wprism: identity-fork request is not canonical JSON');
        }
        return new self($document, $expectedSha256);
    }

    /** @return array<string,int|string> */
    public function document(): array {
        return $this->document;
    }

    public function sha256(): string {
        return $this->sha256;
    }

    public function receipt_key(): string {
        return 'identity_fork:' . $this->document['operation_id'];
    }

    /** Status may inspect an expired intent; only confirmation has a time gate. */
    public function assert_confirmable_at(int $now): void {
        if ($now < $this->document['created_at'] || $now >= $this->document['expires_at']) {
            throw new \RuntimeException('wprism: identity-fork request is not within its confirmation window');
        }
    }

    /** Current target is computed by the command, never taken from request input. */
    public function assert_database_target(string $currentSha256): void {
        self::assert_digest($currentSha256);
        if (!hash_equals($this->document['database_target_sha256'], $currentSha256)) {
            throw new \RuntimeException('wprism: identity-fork request belongs to a different configured database target');
        }
    }

    /** @phpstan-assert array<string,int|string> $document */
    private static function assert_document(mixed $document): void {
        if (!is_array($document) || array_is_list($document)) {
            throw new \RuntimeException('wprism: identity-fork request must be a closed object');
        }
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        if ($keys !== self::FIELDS || $document['format'] !== self::FORMAT) {
            throw new \RuntimeException('wprism: identity-fork request has an unsupported field set or format');
        }
        foreach (self::DIGEST_FIELDS as $field) self::assert_digest($document[$field]);
        if (!is_string($document['operation_id']) || preg_match('/^[a-f0-9]{32}$/D', $document['operation_id']) !== 1
            || !is_string($document['post_type']) || preg_match('/^[a-z0-9_-]{1,20}$/D', $document['post_type']) !== 1) {
            throw new \RuntimeException('wprism: identity-fork request has malformed operation or post-type coordinates');
        }
        foreach (['old_uuid', 'new_uuid'] as $field) {
            if (!is_string($document[$field]) || strlen($document[$field]) !== 36 || !Uuid::is($document[$field])) {
                throw new \RuntimeException('wprism: identity-fork request has malformed UUID coordinates');
            }
        }
        foreach (['original_post_id', 'selected_post_id', 'selected_meta_id', 'created_at', 'expires_at'] as $field) {
            if (!is_int($document[$field]) || $document[$field] < 1) {
                throw new \RuntimeException('wprism: identity-fork request requires positive integer coordinates and timestamps');
            }
        }
        if ($document['old_uuid'] === $document['new_uuid'] || $document['original_post_id'] === $document['selected_post_id']
            || $document['expires_at'] <= $document['created_at']
            || $document['expires_at'] - $document['created_at'] > self::MAX_LIFETIME_SECONDS) {
            throw new \RuntimeException('wprism: identity-fork request has conflicting coordinates or an invalid lifetime');
        }
    }

    private static function assert_digest(mixed $digest): void {
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException('wprism: identity-fork request requires canonical SHA-256 digests');
        }
    }
}
