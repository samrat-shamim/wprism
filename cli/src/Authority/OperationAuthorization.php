<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * The external human/policy authority shared by release and recovery.
 *
 * Technical readiness never grants mutation authority. This boundary verifies
 * a separate Ed25519 trust root, an authenticated actor, one exact operation
 * subject, one target, and a bounded lifetime. The rollback signing key,
 * adapter authorities, and contract-attestation keys deliberately cannot
 * verify here because this format has its own trust file and signature domain.
 */
final class OperationAuthorization {
    public const FORMAT = 'wprism-operation-authorization/v1';
    public const TRUST_FORMAT = 'wprism-operation-authorities/v1';
    public const TRUST_RELATIVE = '.wprism/authority/authorities.json';
    public const SIGNATURE_DOMAIN = "wprism-operation-authorization-signature/v1\0";

    /** @var list<string> */
    public const OPERATIONS = ['recovery', 'release'];

    private const DIGEST_PATTERN = '/^sha256:[a-f0-9]{64}$/D';
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:@+\/-]{0,255}$/D';
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    private const NONCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:@+-]{15,127}$/D';
    private const TARGET_PATTERN = '/^wprism-target:[a-f0-9]{64}$/D';
    private const ED25519_PUBLIC_BYTES = 32;
    private const ED25519_SECRET_BYTES = 64;
    private const ED25519_SIGNATURE_BYTES = 64;

    /** `<site-repo>/.wprism/authority/authorities.json`. */
    public static function trustPath(string $siteRepo): string {
        return rtrim($siteRepo, '/') . '/' . self::TRUST_RELATIVE;
    }

    /**
     * Read and validate the site-owned operation authority policy.
     *
     * Absence is a named refusal: unlike an unsigned application contract,
     * external execution has no interactive fallback and therefore no safe
     * meaning without a provisioned trust root.
     *
     * @return array<string,mixed>
     */
    public static function trust(string $siteRepo): array {
        $path = self::trustPath($siteRepo);
        $document = self::readCanonical($path, self::TRUST_FORMAT, 'operation_authority_trust');
        self::validateTrust($document);

        return $document;
    }

    /** `sha256:` over the exact canonical authority policy. */
    public static function trustDigest(array $trust): string {
        self::validateTrust($trust);

        return self::digest(Canon::encode($trust));
    }

    /**
     * Canonical bytes an external signer signs.
     *
     * The NUL-terminated domain is outside JSON framing, so a valid signature
     * for an adapter, contract, rollback event, release, or recovery document
     * cannot be replayed as another kind of statement.
     *
     * @param array<string,mixed> $statement
     */
    public static function signingBytes(array $statement): string {
        self::validateStatement($statement);

        return self::SIGNATURE_DOMAIN . Canon::encode($statement);
    }

    /**
     * Convenience for controller implementations and deterministic tests.
     * The secret remains caller-owned and is never written by this class.
     *
     * @param array<string,mixed> $statement
     * @return array<string,mixed>
     */
    public static function sign(array $statement, string $secretKey): array {
        self::validateStatement($statement);
        if (!function_exists('sodium_crypto_sign_detached')
            || strlen($secretKey) !== self::ED25519_SECRET_BYTES) {
            throw self::refuse(
                'authorization_signing_key_invalid',
                'the operation authorization signing key is not an Ed25519 secret key',
                'supply exactly one canonical Ed25519 secret key from the external authority'
            );
        }

        return [
            'format' => self::FORMAT,
            'signature' => base64_encode(sodium_crypto_sign_detached(self::signingBytes($statement), $secretKey)),
            'statement' => $statement,
        ];
    }

    /** `sha256:` over the complete canonical signed envelope. */
    public static function envelopeDigest(array $envelope): string {
        self::validateEnvelopeShape($envelope);

        return self::digest(Canon::encode($envelope));
    }

    /** Read one canonical authorization envelope from a caller-selected file. */
    public static function readEnvelope(string $path): array {
        return self::readCanonical($path, self::FORMAT, 'operation_authorization');
    }

    /**
     * Verify the signed envelope against one immutable prepared subject.
     *
     * `$subject` is deliberately a small cross-operation projection. Release
     * and recovery own their complete document validators; this class owns the
     * fields an actor signature must bind and no domain-specific plan meaning.
     *
     * @param array<string,mixed> $envelope
     * @param array{operation:string,operation_id:string,subject_digest:string,presentation_digest:string,target_id:string,required_grants:list<string>,authority_policy_digest:string} $subject
     * @param array<string,mixed> $trust
     * @return array{actor:string,authorization_digest:string,expires_at:string,key_id:string,nonce:string,operation:string,operation_id:string,presentation_digest:string,subject_digest:string,target_id:string}
     */
    public static function verify(array $envelope, array $subject, array $trust, string $now): array {
        self::validateEnvelopeShape($envelope);
        self::validateTrust($trust);
        self::validateSubjectProjection($subject);

        if (!hash_equals((string) $subject['authority_policy_digest'], self::trustDigest($trust))) {
            throw self::refuse(
                'authorization_authority_policy_changed',
                'the operation authority policy changed after the immutable subject was prepared',
                'prepare a fresh subject under the authority policy that is in force now'
            );
        }

        $statement = $envelope['statement'];
        $comparisons = [
            'operation' => $subject['operation'],
            'operation_id' => $subject['operation_id'],
            'presentation_digest' => $subject['presentation_digest'],
            'subject_digest' => $subject['subject_digest'],
            'target_id' => $subject['target_id'],
        ];
        $changed = [];
        foreach ($comparisons as $field => $expected) {
            if (!hash_equals((string) $expected, (string) $statement[$field])) {
                $changed[] = $field;
            }
        }
        if ($changed !== []) {
            throw self::refuse(
                'authorization_subject_mismatch',
                'the signed authorization does not apply to this exact operation subject and target',
                'obtain a new authorization over the immutable subject being executed',
                [['changed_fields' => $changed]]
            );
        }

        $keyId = (string) $statement['key_id'];
        $record = $trust['keys'][$keyId] ?? null;
        if (!is_array($record)) {
            throw self::refuse(
                'authorization_key_unknown',
                'the signed authorization names no key in the site operation authority policy',
                'authorize with a trusted key provisioned in ' . self::TRUST_RELATIVE
            );
        }
        if (($record['status'] ?? null) !== 'trusted') {
            throw self::refuse(
                'authorization_key_revoked',
                'the signed authorization was made by a revoked operation authority key',
                'obtain a fresh authorization from a trusted actor and key'
            );
        }
        if (!hash_equals((string) $record['actor'], (string) $statement['actor'])) {
            throw self::refuse(
                'authorization_actor_mismatch',
                'the signing key is not enrolled for the actor named by the authorization',
                'use the actor identity enrolled beside this key, or enroll the intended actor deliberately'
            );
        }
        if (!in_array($statement['operation'], $record['operations'], true)) {
            throw self::refuse(
                'authorization_operation_not_granted',
                'the signing actor is not trusted for this operation kind',
                'obtain authority from an actor whose enrolled operation scope includes this operation'
            );
        }
        $missingGrants = array_values(array_diff($subject['required_grants'], $record['grants']));
        sort($missingGrants, SORT_STRING);
        if ($missingGrants !== []) {
            throw self::refuse(
                'authorization_grant_missing',
                'the signing actor does not hold every authority the prepared subject requires',
                'obtain authorization from an actor whose enrolled grants cover the complete subject',
                [['missing_grants' => $missingGrants]]
            );
        }

        $public = self::decodeCanonicalBase64(
            (string) $record['public_key'],
            self::ED25519_PUBLIC_BYTES,
            'authorization authority public key'
        );
        $signature = self::decodeCanonicalBase64(
            (string) $envelope['signature'],
            self::ED25519_SIGNATURE_BYTES,
            'operation authorization signature'
        );
        if (!function_exists('sodium_crypto_sign_verify_detached')
            || !sodium_crypto_sign_verify_detached($signature, self::signingBytes($statement), $public)) {
            throw self::refuse(
                'authorization_signature_invalid',
                'the operation authorization signature does not verify under its enrolled actor key',
                'obtain a fresh canonical authorization from the enrolled external authority'
            );
        }

        $nowValue = self::timestamp($now, 'authorization verification time');
        $issued = self::timestamp((string) $statement['issued_at'], 'authorization issued_at');
        $expires = self::timestamp((string) $statement['expires_at'], 'authorization expires_at');
        $skew = (int) $trust['max_clock_skew_seconds'];
        if ($issued > $nowValue + $skew) {
            throw self::refuse(
                'authorization_not_yet_valid',
                'the operation authorization was issued beyond the permitted clock skew',
                'correct the authority/controller clocks and issue a fresh authorization'
            );
        }
        if ($expires <= $nowValue) {
            throw self::refuse(
                'authorization_expired',
                'the operation authorization expired before the mutation gate',
                'obtain a fresh short-lived authorization for the same unchanged subject'
            );
        }
        if ($expires <= $issued || ($expires - $issued) > (int) $trust['max_ttl_seconds']) {
            throw self::refuse(
                'authorization_lifetime_invalid',
                'the operation authorization lifetime exceeds the site authority policy',
                'issue a fresh authorization whose expiry is after issuance and within the configured maximum TTL'
            );
        }

        return [
            'actor' => (string) $statement['actor'],
            'authorization_digest' => self::envelopeDigest($envelope),
            'expires_at' => (string) $statement['expires_at'],
            'key_id' => $keyId,
            'nonce' => (string) $statement['nonce'],
            'operation' => (string) $statement['operation'],
            'operation_id' => (string) $statement['operation_id'],
            'presentation_digest' => (string) $statement['presentation_digest'],
            'subject_digest' => (string) $statement['subject_digest'],
            'target_id' => (string) $statement['target_id'],
        ];
    }

    /** @param array<string,mixed> $trust */
    public static function validateTrust(array $trust): void {
        self::assertExactKeys(
            $trust,
            ['format', 'keys', 'max_clock_skew_seconds', 'max_ttl_seconds'],
            'operation authority policy'
        );
        if (($trust['format'] ?? null) !== self::TRUST_FORMAT
            || !is_array($trust['keys'] ?? null)
            || array_is_list($trust['keys'])) {
            throw self::refuse(
                'operation_authority_trust_invalid',
                'the site operation authority policy is not a ' . self::TRUST_FORMAT . ' document',
                'provision a canonical ' . self::TRUST_RELATIVE . ' before preparing external execution'
            );
        }
        $ttl = $trust['max_ttl_seconds'] ?? null;
        $skew = $trust['max_clock_skew_seconds'] ?? null;
        if (!is_int($ttl) || $ttl < 30 || $ttl > 86400
            || !is_int($skew) || $skew < 0 || $skew > 300) {
            throw self::refuse(
                'operation_authority_trust_invalid',
                'the site operation authority policy has an invalid TTL or clock-skew bound',
                'set max_ttl_seconds to 30..86400 and max_clock_skew_seconds to 0..300'
            );
        }
        foreach ($trust['keys'] as $keyId => $record) {
            if (!is_string($keyId) || preg_match(self::KEY_PATTERN, $keyId) !== 1
                || !is_array($record) || array_is_list($record)) {
                throw self::refuse(
                    'operation_authority_trust_invalid',
                    'the site operation authority policy carries a malformed key record',
                    'repair the named authority record before preparing or executing an operation'
                );
            }
            self::assertExactKeys(
                $record,
                ['actor', 'algorithm', 'grants', 'operations', 'public_key', 'status'],
                "operation authority key '$keyId'"
            );
            if (($record['algorithm'] ?? null) !== 'ed25519'
                || !is_string($record['actor'] ?? null)
                || preg_match(self::ID_PATTERN, (string) $record['actor']) !== 1
                || !in_array($record['status'] ?? null, ['revoked', 'trusted'], true)) {
                throw self::refuse(
                    'operation_authority_trust_invalid',
                    "operation authority key '$keyId' has an invalid actor, algorithm, or status",
                    'repair the authority record before preparing or executing an operation'
                );
            }
            self::stringSet($record['operations'] ?? null, self::OPERATIONS, 'authority operations');
            self::stringSet($record['grants'] ?? null, null, 'authority grants');
            self::decodeCanonicalBase64(
                is_string($record['public_key'] ?? null) ? $record['public_key'] : '',
                self::ED25519_PUBLIC_BYTES,
                "operation authority key '$keyId' public key"
            );
        }
    }

    /** @param array<string,mixed> $envelope */
    public static function validateEnvelopeShape(array $envelope): void {
        self::assertExactKeys($envelope, ['format', 'signature', 'statement'], 'operation authorization');
        if (($envelope['format'] ?? null) !== self::FORMAT
            || !is_string($envelope['signature'] ?? null)
            || !is_array($envelope['statement'] ?? null)
            || array_is_list($envelope['statement'])) {
            throw self::refuse(
                'authorization_format_invalid',
                'the signed operation authorization is not a ' . self::FORMAT . ' document',
                'provide one canonical signed operation authorization envelope'
            );
        }
        self::validateStatement($envelope['statement']);
        self::decodeCanonicalBase64(
            $envelope['signature'],
            self::ED25519_SIGNATURE_BYTES,
            'operation authorization signature'
        );
    }

    /** @param array<string,mixed> $statement */
    public static function validateStatement(array $statement): void {
        self::assertExactKeys($statement, [
            'actor', 'expires_at', 'issued_at', 'key_id', 'nonce', 'operation', 'operation_id',
            'presentation_digest', 'subject_digest', 'target_id',
        ], 'operation authorization statement');
        foreach (['actor', 'key_id', 'nonce', 'operation', 'operation_id', 'presentation_digest',
            'subject_digest', 'target_id', 'issued_at', 'expires_at'] as $field) {
            if (!is_string($statement[$field] ?? null)) {
                throw self::shape("the operation authorization statement has no string $field");
            }
        }
        if (preg_match(self::ID_PATTERN, $statement['actor']) !== 1
            || preg_match(self::KEY_PATTERN, $statement['key_id']) !== 1
            || preg_match(self::NONCE_PATTERN, $statement['nonce']) !== 1
            || preg_match(self::ID_PATTERN, $statement['operation_id']) !== 1
            || !in_array($statement['operation'], self::OPERATIONS, true)
            || preg_match(self::DIGEST_PATTERN, $statement['presentation_digest']) !== 1
            || preg_match(self::DIGEST_PATTERN, $statement['subject_digest']) !== 1
            || preg_match(self::TARGET_PATTERN, $statement['target_id']) !== 1) {
            throw self::shape('the operation authorization statement carries an invalid identity or digest');
        }
        self::timestamp($statement['issued_at'], 'authorization issued_at');
        self::timestamp($statement['expires_at'], 'authorization expires_at');
    }

    /** @param array<string,mixed> $subject */
    private static function validateSubjectProjection(array $subject): void {
        self::assertExactKeys($subject, [
            'authority_policy_digest', 'operation', 'operation_id', 'presentation_digest',
            'required_grants', 'subject_digest', 'target_id',
        ], 'authorization subject projection');
        if (!in_array($subject['operation'] ?? null, self::OPERATIONS, true)
            || !is_string($subject['operation_id'] ?? null)
            || preg_match(self::ID_PATTERN, (string) $subject['operation_id']) !== 1
            || !is_string($subject['target_id'] ?? null)
            || preg_match(self::TARGET_PATTERN, (string) $subject['target_id']) !== 1) {
            throw self::shape('the prepared authorization subject has an invalid operation or target identity');
        }
        foreach (['authority_policy_digest', 'presentation_digest', 'subject_digest'] as $field) {
            if (!is_string($subject[$field] ?? null)
                || preg_match(self::DIGEST_PATTERN, (string) $subject[$field]) !== 1) {
                throw self::shape("the prepared authorization subject has no valid $field");
            }
        }
        self::stringSet($subject['required_grants'] ?? null, null, 'required authority grants');
    }

    /**
     * @param array<string,mixed> $document
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $document, array $expected, string $label): void {
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::shape("the $label does not have its closed key set");
        }
    }

    /**
     * @param mixed $value
     * @param ?list<string> $allowed
     */
    private static function stringSet(mixed $value, ?array $allowed, string $label): void {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::shape("$label must be a sorted unique list");
        }
        $normalized = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || preg_match(self::ID_PATTERN, $entry) !== 1
                || ($allowed !== null && !in_array($entry, $allowed, true))) {
                throw self::shape("$label carries an invalid value");
            }
            $normalized[] = $entry;
        }
        $sorted = array_values(array_unique($normalized));
        sort($sorted, SORT_STRING);
        if ($normalized !== $sorted) {
            throw self::shape("$label must be sorted and unique");
        }
    }

    /** @return array<string,mixed> */
    private static function readCanonical(string $path, string $format, string $codePrefix): array {
        if (!file_exists($path) && !is_link($path)) {
            throw self::refuse(
                $codePrefix . '_missing',
                'the required signed-operation document is absent',
                'provide the canonical document at the configured path and retry'
            );
        }
        if (!is_file($path) || is_link($path)) {
            throw self::refuse(
                $codePrefix . '_unreadable',
                'the required signed-operation document is not an ordinary regular file',
                'replace it with a canonical regular JSON file and retry'
            );
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw self::refuse(
                $codePrefix . '_unreadable',
                'the required signed-operation document could not be read',
                'repair file permissions and retry'
            );
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded) || ($decoded['format'] ?? null) !== $format
            || !hash_equals(Canon::encode($decoded), $raw)) {
            throw self::refuse(
                $codePrefix . '_noncanonical',
                "the required signed-operation document is not canonical $format JSON",
                're-emit the document with WPrism canonical JSON and retry'
            );
        }

        return $decoded;
    }

    private static function decodeCanonicalBase64(string $encoded, int $length, string $label): string {
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || strlen($decoded) !== $length || !hash_equals(base64_encode($decoded), $encoded)) {
            throw self::shape("the $label is not canonical base64 of the required Ed25519 byte length");
        }

        return $decoded;
    }

    private static function timestamp(string $value, string $label): int {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw self::shape("the $label is not a canonical UTC-seconds timestamp");
        }

        return $date->getTimestamp();
    }

    private static function digest(string $bytes): string {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private static function shape(string $message): CommandRefusalException {
        return self::refuse(
            'authorization_shape_invalid',
            $message,
            'obtain a freshly prepared canonical subject and a freshly signed canonical authorization'
        );
    }

    /** @param list<array<string,mixed>> $diagnostics */
    private static function refuse(
        string $code,
        string $message,
        string $remediation,
        array $diagnostics = []
    ): CommandRefusalException {
        return new CommandRefusalException($code, $message, $remediation, $diagnostics);
    }
}
