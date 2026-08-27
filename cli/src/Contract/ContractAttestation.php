<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__, 3) . '/agent/src/Policy/AdapterLibrary.php';
require_once __DIR__ . '/ApplicationContract.php';

use Duo\AdapterLibrary;
use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * Sign and verify `attestation.state: "signed"` on the application contract,
 * under a trust root the OPERATOR provisions and this build ships empty.
 *
 * ## The shape of the claim
 *
 * `duo adapter certify` already lets a customer organization vouch for its own
 * adapter bytes under its own Ed25519 key (AdapterCertify.php:15-45). The
 * contract had no equivalent: `ContractStore::writeContract()` refused every
 * signed document with `attestation_signing_unsupported`, so the honest
 * evidence line every assessment printed ended `; contract attestation
 * unsigned` and there was no command that could ever change it. This class is
 * that command's engine. It changes nothing for a site that has not
 * provisioned a key — and no shipped site has, because the trust root is a
 * file in the SITE repository that nothing creates but `duo contract <env>
 * attest`.
 *
 * ## Three properties, and each one is why a line here reads as it does
 *
 * **1. A new signature domain, never the adapter's.**
 * `AdapterCertification::SIGNATURE_DOMAIN`'s own docblock (:52) says it is
 * "kept independent from JSON framing so this signature cannot verify
 * elsewhere". Reusing it here would make an adapter certificate and a contract
 * attestation two statements one verifier could confuse — the exact property
 * that comment exists to deny. Same primitive, different domain constant.
 *
 * **2. `attested_digest`, not `contract_digest`.** `contract_digest` is the
 * digest of the document minus that one key (ApplicationContract.php:150-159),
 * and `attestation` is INSIDE its input. A signature over `contract_digest`
 * therefore moves `contract_digest` the moment it is written into the
 * document, and could never verify against the document carrying it. So the
 * signature binds `attested_digest`: the same rule, applied to the document
 * minus `contract_digest` AND with `attestation.signature` forced to null —
 * a signature is never inside its own input. `contract_digest`'s definition
 * and value semantics do not move at all, which is what keeps `duo release`'s
 * frozen authorization plan and every `site.duo.json` content pin correct.
 *
 * **3. The signed statement carries the digest and the format, and nothing
 * else.** Every fact an operator would want inside the signed bytes — who
 * approved, under which policy version, until when, under which key and root,
 * against which platform boundary — is a FIELD of `attestation`, and
 * `attestation` is inside `attested_digest`'s input. The statement is rebuilt
 * from the document at verify time, so restating those fields inside it would
 * be bytes that cannot ever disagree: a check that looks like a check and is
 * not. `platform_sha256` is the one fact that earns a stored field of its own
 * (see below), because there a live value exists to compare it against.
 *
 * ## Platform re-binding, and its loud consequence
 *
 * `attestation.platform_sha256` records the agent capability boundary the
 * document was attested against — the same digest
 * `AdapterCertification::currentPlatform()` (:1438-1467) puts inside every
 * adapter certificate, computed from the same file and the same typed object.
 * `verify()` re-binds it against the live agent AFTER the signature check, so
 * a moved boundary refuses by its own name (`contract_attestation_platform_moved`)
 * instead of surfacing as a tamper alarm. The consequence is stated rather
 * than softened: after an agent-version bump a signed contract refuses until
 * it is attested again. That is the same rule an adapter certificate has
 * always had, and the remedy is one command, never a fallback (AGENTS.md
 * rule 9).
 *
 * ## The trust root
 *
 * `.duo/contract/authorities.json`, format
 * `duo-contract-attestation-authorities/v1`, scope `contract_attestation`, in
 * the site repository beside the contract it authorizes. It is a SEPARATE file
 * from `adapters/authorities.json` and that is forced, not preferred:
 * `AdapterCertification::validateAuthorityRecord()` (:1305-1336) requires
 * `scope: site_adapter_certification` plus `adapter_names`/`trust_tiers` on
 * EVERY record, and `assert_site_authorities()` validates the whole file the
 * moment it exists (AdapterSources.php:803-811) — one contract-scoped record
 * in that file would break every adapter certificate in the repository.
 *
 * A record is `{algorithm, public_key, scope, status}` and deliberately has no
 * `adapter_names` analogue: `adapter_names` scopes a key to a subset of a
 * repository's adapters, and a contract is ONE document per repository, so a
 * name list here would only restate the file's own location. The approving
 * principal lives in the signed document, where the signature binds it.
 *
 * Absence of the file is a legitimate answer — it is the shipped state of
 * every site — and an absent root mints nothing and verifies nothing. A file
 * that EXISTS and is not a readable, well-formed document is a refusal, never
 * an absence: laundering unreadable authority bytes into "no trust root" is
 * the failure mode `AdapterCertification::authorityKeys()` (:1237-1245)
 * already refuses in the same words.
 */
final class ContractAttestation {
    /**
     * A NEW domain. See property 1 in the class docblock: the adapter
     * domain's independence from every other statement is the point of it
     * existing, so borrowing it here would be the one thing it forbids.
     */
    public const SIGNATURE_DOMAIN = "duo-contract-attestation-signature/v1\0";

    public const STATEMENT_FORMAT = 'duo-contract-attestation-statement/v1';
    public const AUTHORITIES_FORMAT = 'duo-contract-attestation-authorities/v1';

    /**
     * Beside `contract.json`, in the repository the contract is about.
     *
     * Spelled out rather than composed from `ContractStore::DIRECTORY`: the
     * store requires THIS file (it verifies on read), so a constant expression
     * pointing the other way would make the two require lines a cycle that
     * only fires for whichever file a partially-loaded context reached first.
     */
    public const AUTHORITIES_RELATIVE = '.duo/contract/authorities.json';

    /** The one scope word a record in this file may carry. */
    public const SCOPE = 'contract_attestation';

    /**
     * The only trust root this build admits, and the reason is the same one
     * that made `Site-certified` unreachable before T6: the shipped
     * `manifests/capabilities/adapter-authorities.json` is `{"keys": {}}` and
     * only Anthropic-side review could ever fill it. A contract is a
     * customer-organization statement about the customer's own site, which is
     * exactly what the product spec means by site certification. A
     * platform-rooted contract attestation refuses BY NAME
     * (`contract_attestation_trust_root_unsupported`) so the ruling that opens
     * it needs no schema change.
     */
    public const TRUST_ROOT_SITE = 'site';

    /** The platform boundary file, relative to the manifest library. */
    private const PLATFORM_RELATIVE = 'capabilities/platform.json';

    /**
     * Byte-identical to `AdapterSources::assert_name()`'s grammar for an
     * authority key id, restated here rather than imported because this is
     * where a key id stops being a name and becomes an object key in a
     * document PHP decodes — the same reason `ContractStore::ENVIRONMENT_PATTERN`
     * (:72) restates the registry's rule at its own boundary.
     */
    private const KEY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    /** The one timestamp grammar, so "expired" is never a parse accident. */
    private const EXPIRES_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * The digest the signature binds: the document minus `contract_digest`,
     * with `attestation.signature` forced to null.
     *
     * Both exclusions are forced. `contract_digest` is excluded for the reason
     * `ApplicationContract::digest()` excludes it — it is the digest OF
     * everything else. `attestation.signature` is excluded because a signature
     * is never inside its own input; leaving it in would make the value being
     * signed depend on the signature.
     *
     * @param array<string,mixed> $document
     */
    public static function attestedDigest(array $document): string {
        if (!is_array($document['attestation'] ?? null)) {
            throw self::refuse(
                'contract_attestation_signature_invalid',
                'the contract carries no attestation object to attest',
                'accept a freshly proposed contract, then attest it'
            );
        }
        unset($document['contract_digest']);
        $document['attestation']['signature'] = null;

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /** `<siteRepo>/.duo/contract/authorities.json`. */
    public static function authoritiesPath(string $siteRepo): string {
        return rtrim($siteRepo, '/') . '/' . self::AUTHORITIES_RELATIVE;
    }

    /**
     * The validated key map, or `[]` when the site has provisioned no root.
     *
     * `[]` is the shipped state of every site and is not an error. A file that
     * exists and is malformed IS an error: see the class docblock.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function authorities(string $siteRepo): array {
        $file = self::authoritiesPath($siteRepo);
        if (!file_exists($file) && !is_link($file)) {
            return [];
        }
        if (!is_file($file) || is_link($file)) {
            throw self::refuseAuthorities('the contract attestation trust root is not an ordinary regular file');
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw self::refuseAuthorities('the contract attestation trust root could not be read');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw self::refuseAuthorities('the contract attestation trust root is not a JSON object');
        }
        if (($decoded['format'] ?? null) !== self::AUTHORITIES_FORMAT
            || !is_array($decoded['keys'] ?? null) || array_is_list($decoded['keys'])) {
            throw self::refuseAuthorities(
                'the contract attestation trust root is not a ' . self::AUTHORITIES_FORMAT . ' document'
            );
        }
        $keys = [];
        foreach ($decoded['keys'] as $keyId => $record) {
            if (!is_string($keyId)) {
                // PHP coerces a numeric JSON object-map key to an integer, so
                // a numeric-only identity would compare unequal to the string
                // the document carries. The adapter root refuses the same
                // shape for the same reason (AdapterCertification.php:1264-1269).
                throw self::refuseAuthorities(
                    'the contract attestation trust root carries a non-string key id'
                );
            }
            self::assertKeyId($keyId);
            if (!is_array($record) || array_is_list($record)) {
                throw self::refuseAuthorities("contract attestation key '$keyId' is not an object");
            }
            self::validateRecord($record, $keyId);
            $keys[$keyId] = $record;
        }

        return $keys;
    }

    /**
     * Put one public key in the trust root, creating the file if needed.
     *
     * The key must be registered BEFORE anything is signed under it, exactly
     * as `AdapterCertify::certify()` orders the same two steps
     * (AdapterCertify.php:296-303): the trust root is where `verify()`
     * resolves a key from, and a document signed under a key nothing trusts is
     * a document that cannot be read back.
     *
     * @return bool true when the file changed
     */
    public static function registerAuthority(string $siteRepo, string $keyId, string $publicKey): bool {
        self::assertKeyId($keyId);
        $file = self::authoritiesPath($siteRepo);
        $keys = self::authorities($siteRepo);
        $encoded = base64_encode($publicKey);
        $record = $keys[$keyId] ?? null;
        if (is_array($record)) {
            if (!is_string($record['public_key'] ?? null)
                || !hash_equals((string) $record['public_key'], $encoded)) {
                throw self::refuse(
                    'contract_attestation_key_mismatch',
                    "contract attestation key '$keyId' is already registered with a different public key",
                    'choose another --key-id, or remove the stale record deliberately — every attestation '
                        . 'it signed stops verifying'
                );
            }
            if (($record['status'] ?? null) !== 'trusted') {
                throw self::refuse(
                    'contract_attestation_key_revoked',
                    "contract attestation key '$keyId' is revoked in " . self::AUTHORITIES_RELATIVE,
                    'attest under a trusted key, or re-trust this one deliberately in the trust root'
                );
            }
        } else {
            $keys[$keyId] = [
                'algorithm' => 'ed25519',
                'public_key' => $encoded,
                'scope' => self::SCOPE,
                'status' => 'trusted',
            ];
        }
        ksort($keys, SORT_STRING);
        // `keys` is a JSON OBJECT in this grammar and stays one even when it
        // holds a single member: Canon encodes an empty PHP array as `[]`,
        // which authorities() refuses. Same cast, same reason, as
        // AdapterCertify::registerAuthority() (:832-838).
        $document = Canon::encode(['format' => self::AUTHORITIES_FORMAT, 'keys' => (object) $keys]);
        $existing = is_file($file) ? (string) @file_get_contents($file) : null;
        if ($existing !== null && hash_equals($existing, $document)) {
            return false;
        }
        self::writeAtomic($file, $document);

        return true;
    }

    /**
     * Return the document with a verified `signed` attestation.
     *
     * @param array<string,mixed> $document an accepted, validated contract
     * @param array{approving_principal:string,policy_version:string,expires_at:string,reason:string} $claim
     * @param string $secret raw Ed25519 secret key bytes
     * @return array<string,mixed> re-digested through ApplicationContract::withDigest()
     */
    public static function sign(
        array $document,
        string $siteRepo,
        string $keyId,
        string $secret,
        array $claim,
        string|AdapterLibrary|null $manifestDir = null
    ): array {
        self::assertSodium();
        self::assertKeyId($keyId);
        $authorities = self::authorities($siteRepo);
        if ($authorities === []) {
            throw self::refuseUnsignedAnchor();
        }
        $record = $authorities[$keyId] ?? null;
        if ($record === null) {
            throw self::refuseKeyUnknown($keyId);
        }
        if (($record['status'] ?? null) !== 'trusted') {
            throw self::refuseKeyRevoked($keyId);
        }
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        if (!hash_equals(self::publicKey($record, $keyId), $public)) {
            throw self::refuse(
                'contract_attestation_key_mismatch',
                "the private key does not match trusted contract attestation key '$keyId'",
                'pass the secret key file that belongs to this --key-id, or register the key you hold'
            );
        }

        $document['attestation'] = [
            'approving_principal' => self::claimField($claim, 'approving_principal'),
            'expires_at' => self::assertExpires(self::claimField($claim, 'expires_at')),
            'format' => ApplicationContract::ATTESTATION_FORMAT,
            'key_id' => $keyId,
            'platform_sha256' => self::currentPlatformDigest($manifestDir),
            'policy_version' => self::claimField($claim, 'policy_version'),
            'reason' => self::claimField($claim, 'reason'),
            'signature' => null,
            'state' => 'signed',
            'trust_root' => self::TRUST_ROOT_SITE,
        ];
        $signature = sodium_crypto_sign_detached(
            self::signedBytes(self::attestedDigest($document)),
            $secret
        );
        $document['attestation']['signature'] = base64_encode($signature);

        return ApplicationContract::withDigest($document);
    }

    /**
     * Verify a `signed` attestation and return what it proves.
     *
     * Ordering is load-bearing. The signature is checked BEFORE the platform
     * re-bind so an edited `platform_sha256` reads as the tamper it is rather
     * than as an agent upgrade, and the expiry is checked last so an expired
     * document still reports a real principal in the refusal detail.
     *
     * @param array<string,mixed> $document
     * @param int|null $now injected clock; null reads the wall clock
     * @return array{expires_at:string,key_id:string,policy_version:string,principal:string,trust_root:string}
     */
    public static function verify(
        array $document,
        string $siteRepo,
        string|AdapterLibrary|null $manifestDir = null,
        ?int $now = null
    ): array {
        self::assertSodium();
        $attestation = is_array($document['attestation'] ?? null) ? $document['attestation'] : [];
        if (($attestation['state'] ?? null) !== 'signed') {
            throw self::refuse(
                'contract_attestation_signature_invalid',
                'the contract attestation is not signed, so there is nothing to verify',
                'attest the contract with duo contract <env> attest, or read it as the unsigned document it is'
            );
        }
        $trustRoot = (string) ($attestation['trust_root'] ?? '');
        if ($trustRoot !== self::TRUST_ROOT_SITE) {
            throw self::refuse(
                'contract_attestation_trust_root_unsupported',
                "this build verifies contract attestations under the '" . self::TRUST_ROOT_SITE
                    . "' trust root only",
                're-attest the contract under the site trust root; a platform-rooted contract attestation '
                    . 'is not a claim this build can check',
                [['trust_root' => self::safe($trustRoot)]]
            );
        }
        $keyId = (string) ($attestation['key_id'] ?? '');
        self::assertKeyId($keyId);
        $authorities = self::authorities($siteRepo);
        if ($authorities === []) {
            throw self::refuseUnsignedAnchor();
        }
        $record = $authorities[$keyId] ?? null;
        if ($record === null) {
            throw self::refuseKeyUnknown($keyId);
        }
        if (($record['status'] ?? null) !== 'trusted') {
            throw self::refuseKeyRevoked($keyId);
        }

        $signature = base64_decode((string) ($attestation['signature'] ?? ''), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $signature,
                self::signedBytes(self::attestedDigest($document)),
                self::publicKey($record, $keyId)
            )) {
            throw self::refuse(
                'contract_attestation_signature_invalid',
                'the contract attestation signature does not verify against this contract',
                'restore .duo/contract/contract.json from git; an edited contract drops its attestation '
                    . 'rather than degrading it, and re-attesting is the only way to restore one',
                [['key_id' => self::safe($keyId)]]
            );
        }

        $signedPlatform = (string) ($attestation['platform_sha256'] ?? '');
        $currentPlatform = self::currentPlatformDigest($manifestDir);
        if (!hash_equals($currentPlatform, $signedPlatform)) {
            throw self::refuse(
                'contract_attestation_platform_moved',
                'the contract was attested against a different agent capability boundary than this one',
                're-attest the contract with duo contract <env> attest; the attestation binds the platform '
                    . 'boundary it was reviewed against, and an agent upgrade moves it',
                [['attested' => substr($signedPlatform, 0, 12), 'current' => substr($currentPlatform, 0, 12)]]
            );
        }

        $expiresAt = self::assertExpires((string) ($attestation['expires_at'] ?? ''));
        $expiry = \DateTimeImmutable::createFromFormat(
            self::EXPIRES_FORMAT,
            $expiresAt,
            new \DateTimeZone('UTC')
        );
        if ($expiry !== false && ($now ?? time()) >= $expiry->getTimestamp()) {
            // An expired attestation REFUSES; it does not quietly become an
            // unsigned one. A silent downgrade is the fallback AGENTS.md
            // rule 9 forbids, and it would make a stale claim
            // indistinguishable from a fresh one at every consumer.
            throw self::refuse(
                'contract_attestation_expired',
                'the contract attestation expired on ' . self::safe($expiresAt),
                're-attest the contract with duo contract <env> attest; an expired attestation is refused, '
                    . 'never silently downgraded to unsigned',
                [['expires_at' => self::safe($expiresAt)]]
            );
        }

        return [
            'expires_at' => $expiresAt,
            'key_id' => $keyId,
            'policy_version' => (string) ($attestation['policy_version'] ?? ''),
            'principal' => (string) ($attestation['approving_principal'] ?? ''),
            'trust_root' => $trustRoot,
        ];
    }

    /**
     * The live agent capability boundary digest.
     *
     * Computed exactly as `AdapterCertification::currentPlatform()`
     * (:1438-1467) computes the `platform_sha256` inside every adapter
     * certificate: the TYPED decode of `capabilities/platform.json`, canonical
     * encoding of its `platform` object, SHA-256 of those bytes. Typed rather
     * than array, because an array projection re-encodes an empty object as
     * `[]` and would hash different bytes than the certificate path does.
     */
    public static function currentPlatformDigest(string|AdapterLibrary|null $manifestDir = null): string {
        $file = self::platformPath($manifestDir);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false) {
            throw self::refuse(
                'contract_attestation_platform_moved',
                'the agent capability platform boundary is absent, so an attestation cannot be bound to it',
                'run this command from a complete duo checkout; capabilities/platform.json is what an '
                    . 'attestation binds'
            );
        }
        $typed = json_decode($raw);
        if (!is_object($typed) || !isset($typed->platform) || !is_object($typed->platform)) {
            throw self::refuse(
                'contract_attestation_platform_moved',
                'the agent capability platform boundary document has no platform object',
                'restore manifests/capabilities/platform.json from git'
            );
        }

        return hash('sha256', Canon::encode($typed->platform));
    }

    /**
     * Resolve production through AdapterLibrary while retaining the explicit
     * string spelling used by custom migration fixtures during this phase.
     */
    private static function platformPath(string|AdapterLibrary|null $manifestDir): string {
        if ($manifestDir instanceof AdapterLibrary) {
            return $manifestDir->platformBoundaryPath();
        }
        $directory = $manifestDir !== null && $manifestDir !== ''
            ? $manifestDir
            : dirname(__DIR__, 3) . '/manifests';
        return rtrim($directory, '/') . '/' . self::PLATFORM_RELATIVE;
    }

    private static function signedBytes(string $attestedDigest): string {
        return self::SIGNATURE_DOMAIN . Canon::encode([
            'attested_digest' => $attestedDigest,
            'format' => self::STATEMENT_FORMAT,
        ]);
    }

    /** @param array<string,mixed> $record */
    private static function validateRecord(array $record, string $keyId): void {
        $known = ['algorithm', 'public_key', 'scope', 'status'];
        foreach (array_keys($record) as $key) {
            if (!in_array((string) $key, $known, true)) {
                throw self::refuseAuthorities(
                    "contract attestation key '$keyId' carries the unrecognised field '" . self::safe((string) $key) . "'"
                );
            }
        }
        if (($record['algorithm'] ?? null) !== 'ed25519' || ($record['scope'] ?? null) !== self::SCOPE) {
            throw self::refuseAuthorities(
                "contract attestation key '$keyId' must declare algorithm ed25519 and scope " . self::SCOPE
            );
        }
        if (!in_array($record['status'] ?? null, ['trusted', 'revoked'], true)) {
            throw self::refuseAuthorities("contract attestation key '$keyId' status must be trusted or revoked");
        }
        self::publicKey($record, $keyId);
    }

    /** @param array<string,mixed> $record */
    private static function publicKey(array $record, string $keyId): string {
        $encoded = $record['public_key'] ?? null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || !is_string($encoded) || !hash_equals(base64_encode($key), $encoded)
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw self::refuseAuthorities(
                "contract attestation key '$keyId' public_key is not a base64 Ed25519 public key"
            );
        }

        return $key;
    }

    /** @param array<string,mixed> $claim */
    private static function claimField(array $claim, string $key): string {
        $value = $claim[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw self::refuse(
                'contract_attestation_claim_incomplete',
                "a contract attestation must state its $key",
                'pass --principal, --policy-version and --expires; an attestation that states nothing '
                    . 'proves nothing'
            );
        }

        return $value;
    }

    /**
     * One timestamp grammar, checked at both ends. A hand-written
     * `2027-13-01T00:00:00Z` that parsed loosely would make "expired" a
     * property of the parser rather than of the clock.
     */
    private static function assertExpires(string $value): string {
        $parsed = \DateTimeImmutable::createFromFormat(self::EXPIRES_FORMAT, $value, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format(self::EXPIRES_FORMAT) !== $value) {
            throw self::refuse(
                'contract_attestation_claim_incomplete',
                'the attestation expiry is not an ISO-8601 UTC instant (YYYY-MM-DDTHH:MM:SSZ)',
                'pass --expires=2027-01-01T00:00:00Z; the expiry is enforced at read time, so it must '
                    . 'be one unambiguous instant'
            );
        }

        return $value;
    }

    private static function assertKeyId(string $keyId): void {
        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
            throw self::refuse(
                'contract_attestation_key_unknown',
                'the contract attestation key id is not a legal key name',
                'use a key id matching [A-Za-z0-9][A-Za-z0-9._-]{0,63}',
                [['key_id' => self::safe($keyId)]]
            );
        }
    }

    private static function assertSodium(): void {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw self::refuse(
                'contract_attestation_unsupported',
                'contract attestation requires the PHP sodium extension',
                'install the PHP sodium extension on the machine running duo'
            );
        }
    }

    private static function writeAtomic(string $path, string $contents): void {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw self::refuseAuthorities('the contract attestation trust root directory could not be created');
        }
        $temporary = @tempnam($directory, '.duo-authorities-');
        if (!is_string($temporary) || $temporary === '') {
            throw self::refuseAuthorities('a temporary file could not be created beside the trust root');
        }
        try {
            if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw self::refuseAuthorities('the contract attestation trust root could not be written');
            }
            // A trust root is a committed review artifact every team member
            // reads, exactly like contract.json — the same widening
            // ContractStore::writeAtomic() (:296-299) applies, and for the
            // same reason: tempnam() creates 0600.
            @chmod($temporary, 0666 & ~umask());
            if (!@rename($temporary, $path)) {
                throw self::refuseAuthorities('the contract attestation trust root could not be published atomically');
            }
            $temporary = null;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function refuseUnsignedAnchor(): CommandRefusalException {
        return self::refuse(
            'contract_attestation_unsigned_anchor',
            'this site repository has no contract attestation trust root, so nothing can be attested',
            'provision a key with duo adapter keygen and attest with duo contract <env> attest '
                . '--secret-key-file=<path>; ' . self::AUTHORITIES_RELATIVE . ' is written by that command '
                . 'and ships with no key'
        );
    }

    private static function refuseKeyUnknown(string $keyId): CommandRefusalException {
        return self::refuse(
            'contract_attestation_key_unknown',
            "contract attestation key '" . self::safe($keyId) . "' is not in this repository's trust root",
            'attest under a key registered in ' . self::AUTHORITIES_RELATIVE . ', or register the key you hold'
        );
    }

    private static function refuseKeyRevoked(string $keyId): CommandRefusalException {
        return self::refuse(
            'contract_attestation_key_revoked',
            "contract attestation key '" . self::safe($keyId) . "' is revoked in " . self::AUTHORITIES_RELATIVE,
            're-attest the contract under a trusted key; a revoked key cannot vouch for a contract'
        );
    }

    private static function refuseAuthorities(string $message): CommandRefusalException {
        return self::refuse(
            'contract_attestation_authorities_invalid',
            $message,
            'restore ' . self::AUTHORITIES_RELATIVE . ' from git; unreadable authority bytes are a broken '
                . 'trust root, never an absent one'
        );
    }

    /** @param list<array<string,mixed>> $detail */
    private static function refuse(
        string $code,
        string $message,
        string $remediation,
        array $detail = []
    ): CommandRefusalException {
        return new CommandRefusalException($code, $message, $remediation, $detail);
    }

    /** Operator-facing values are bounded and control-stripped, as everywhere else. */
    private static function safe(string $value): string {
        $bounded = strlen($value) > 120 ? substr($value, 0, 120) . '…' : $value;

        return (string) preg_replace('/[\x00-\x1f\x7f]/', '?', $bounded);
    }
}
