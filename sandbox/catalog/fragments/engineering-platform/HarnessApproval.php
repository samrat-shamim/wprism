<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * Verifies externally issued qualification authority and a fresh observed
 * environment record. This code can consume authority, but cannot mint it.
 *
 * @phpstan-type HarnessContext array{
 *     approval_id:string,
 *     approval_sha256:string,
 *     provisioning_sha256:string,
 *     probe_sha256:string,
 *     image_digest:string,
 *     environment_class:string,
 *     data_profile:string,
 *     credential_realm:string,
 *     egress_policy:string,
 *     effect_policy:string,
 *     sandbox_destinations_sha256:string,
 *     environment_fingerprint:string,
 *     output_authority:string,
 *     output_adoptability:string,
 *     environment:array<string,string>
 * }
 */
final class HarnessApproval
{
    /** @var list<string> */
    private const APPROVAL_KEYS = [
        'approval_id', 'credential_realm', 'data_profile', 'effect_policy', 'egress_policy',
        'environment_role', 'expires_at', 'format', 'issuer', 'key_id', 'provisioning_sha256',
        'sandbox_destinations_sha256', 'signature', 'target_sha256',
    ];

    /** @var list<string> */
    private const IDENTITY_KEYS = [
        'credential_realm', 'data_profile', 'effect_policy', 'egress_policy', 'environment_role',
        'image_digest', 'output_adoptability', 'output_authority', 'sandbox_destinations_sha256',
        'target_sha256',
    ];

    public function __construct(private readonly string $root) {}

    /** @return HarnessContext */
    public function verify(string $approvalPath, string $keyringPath, string $provisioningPath, string $probePath): array
    {
        $approvalBytes = $this->externalBytes($approvalPath, 'approval');
        $keyringBytes = $this->externalBytes($keyringPath, 'keyring');
        $provisioningBytes = $this->externalBytes($provisioningPath, 'provisioning record');
        $probeBytes = $this->externalBytes($probePath, 'environment probe');
        $approval = $this->canonicalObject($approvalBytes, 'approval');
        $keyring = $this->canonicalObject($keyringBytes, 'keyring');
        $provisioning = $this->canonicalObject($provisioningBytes, 'provisioning record');
        $probe = $this->canonicalObject($probeBytes, 'environment probe');

        $this->assertKeys($approval, self::APPROVAL_KEYS, 'approval');
        if (($approval['format'] ?? null) !== 'duo-harness-approval/v1'
            || !is_string($approval['approval_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $approval['approval_id']) !== 1
            || !is_string($approval['issuer'] ?? null) || $approval['issuer'] === ''
            || !is_string($approval['key_id'] ?? null) || $approval['key_id'] === ''
            || !is_string($approval['signature'] ?? null)
            || !$this->validApprovalIdentity($approval)) {
            throw new CatalogException('harness approval is malformed');
        }
        $expires = is_string($approval['expires_at'] ?? null)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $approval['expires_at'], new \DateTimeZone('UTC'))
            : false;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$expires instanceof \DateTimeImmutable || $expires <= $now) {
            throw new CatalogException('harness approval is expired or has a noncanonical expiry');
        }

        $approvalId = $this->requiredString($approval, 'approval_id', 'approval');
        $issuer = $this->requiredString($approval, 'issuer', 'approval');
        $keyId = $this->requiredString($approval, 'key_id', 'approval');
        $provisioningApprovalDigest = $this->requiredString($approval, 'provisioning_sha256', 'approval');
        $publicKey = $this->trustedKey($keyring, $issuer, $keyId);
        $signature = base64_decode($this->requiredString($approval, 'signature', 'approval'), true);
        $signed = $approval;
        unset($signed['signature']);
        if (!is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $this->canonical($signed), $publicKey)) {
            throw new CatalogException('harness approval signature is invalid or untrusted');
        }

        $provisioningDigest = $this->digest($provisioningBytes);
        if (!hash_equals($provisioningApprovalDigest, $provisioningDigest)) {
            throw new CatalogException('harness approval does not bind the supplied provisioning record');
        }
        $environmentNames = $this->assertProvisioning($provisioning);
        $this->assertProbe($probe, $now);
        if (($probe['provisioning_sha256'] ?? null) !== $provisioningDigest) {
            throw new CatalogException('environment probe does not bind the approved provisioning record');
        }
        foreach (self::IDENTITY_KEYS as $key) {
            if (($provisioning[$key] ?? null) !== ($probe[$key] ?? null)) {
                throw new CatalogException("environment probe disagrees with approved provisioning: $key");
            }
        }
        foreach ([
            'target_sha256', 'environment_role', 'data_profile', 'credential_realm', 'egress_policy',
            'effect_policy', 'sandbox_destinations_sha256',
        ] as $key) {
            if (($approval[$key] ?? null) !== ($probe[$key] ?? null)) {
                throw new CatalogException("harness approval disagrees with actual environment probe: $key");
            }
        }

        $environment = [];
        foreach ($environmentNames as $name) {
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                throw new CatalogException("approved harness environment variable is unavailable: $name");
            }
            $environment[$name] = $value;
        }
        ksort($environment, SORT_STRING);
        $fingerprintInput = [
            'probe_sha256' => $this->digest($probeBytes),
            'present_environment_names' => array_keys($environment),
        ];

        return [
            'approval_id' => $approvalId,
            'approval_sha256' => $this->digest($approvalBytes),
            'provisioning_sha256' => $provisioningDigest,
            'probe_sha256' => $this->digest($probeBytes),
            'image_digest' => $this->requiredString($probe, 'image_digest', 'environment probe'),
            'environment_class' => 'isolated_qualification',
            'data_profile' => $this->requiredString($probe, 'data_profile', 'environment probe'),
            'credential_realm' => $this->requiredString($probe, 'credential_realm', 'environment probe'),
            'egress_policy' => $this->requiredString($probe, 'egress_policy', 'environment probe'),
            'effect_policy' => $this->requiredString($probe, 'effect_policy', 'environment probe'),
            'sandbox_destinations_sha256' => $this->requiredString($probe, 'sandbox_destinations_sha256', 'environment probe'),
            'environment_fingerprint' => 'sha256:' . hash('sha256', "duo-harness-environment/v1\0" . $this->canonical($fingerprintInput)),
            'output_authority' => 'non_authorizing',
            'output_adoptability' => 'forbidden',
            'environment' => $environment,
        ];
    }

    /** @param array<string,mixed> $value */
    private function validApprovalIdentity(array $value): bool
    {
        return $this->digestValue($value['target_sha256'] ?? null)
            && $this->digestValue($value['provisioning_sha256'] ?? null)
            && $this->digestValue($value['sandbox_destinations_sha256'] ?? null)
            && $this->validPolicyIdentity($value);
    }

    /** @param array<string,mixed> $value */
    private function validPolicyIdentity(array $value): bool
    {
        return ($value['environment_role'] ?? null) === 'isolated_qualification'
            && in_array($value['data_profile'] ?? null, ['approved_synthetic', 'approved_minimized'], true)
            && ($value['credential_realm'] ?? null) === 'non_production'
            && ($value['egress_policy'] ?? null) === 'default_deny'
            && in_array($value['effect_policy'] ?? null, ['sandbox_only', 'no_external_effect_possible'], true);
    }

    /**
     * @param array<string,mixed> $record
     * @return list<string>
     */
    private function assertProvisioning(array $record): array
    {
        $this->assertKeys($record, array_merge(['environment_variable_names', 'format'], self::IDENTITY_KEYS), 'provisioning record');
        if (($record['format'] ?? null) !== 'duo-harness-provisioning/v1'
            || !$this->validObservedIdentity($record)
            || !is_array($record['environment_variable_names'] ?? null)
            || !array_is_list($record['environment_variable_names'])) {
            throw new CatalogException('provisioning record is malformed');
        }
        $names = [];
        foreach ($this->requiredList($record, 'environment_variable_names', 'provisioning record') as $name) {
            if (!is_string($name) || !$this->safeEnvironmentName($name)) {
                throw new CatalogException('provisioning record contains an unsafe environment variable name');
            }
            $names[] = $name;
        }
        if (count(array_unique($names, SORT_STRING)) !== count($names)) {
            throw new CatalogException('provisioning environment variable names are duplicated');
        }
        $sorted = $names;
        sort($sorted, SORT_STRING);
        if ($names !== $sorted) {
            throw new CatalogException('provisioning environment variable names are not canonical');
        }
        return $names;
    }

    /** @param array<string,mixed> $probe */
    private function assertProbe(array $probe, \DateTimeImmutable $now): void
    {
        $this->assertKeys($probe, array_merge(['format', 'observed_at', 'provisioning_sha256'], self::IDENTITY_KEYS), 'environment probe');
        $observed = is_string($probe['observed_at'] ?? null)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $probe['observed_at'], new \DateTimeZone('UTC'))
            : false;
        if (($probe['format'] ?? null) !== 'duo-harness-probe/v1'
            || !$this->validObservedIdentity($probe)
            || !$this->digestValue($probe['provisioning_sha256'] ?? null)
            || !$observed instanceof \DateTimeImmutable
            || $observed < $now->modify('-5 minutes')
            || $observed > $now->modify('+1 minute')) {
            throw new CatalogException('environment probe is malformed, stale, or from the future');
        }
    }

    /** @param array<string,mixed> $value */
    private function validObservedIdentity(array $value): bool
    {
        return $this->digestValue($value['target_sha256'] ?? null)
            && $this->digestValue($value['sandbox_destinations_sha256'] ?? null)
            && $this->validPolicyIdentity($value)
            && $this->digestValue($value['image_digest'] ?? null)
            && ($value['output_authority'] ?? null) === 'non_authorizing'
            && ($value['output_adoptability'] ?? null) === 'forbidden';
    }

    /**
     * @param array<string,mixed> $keyring
     * @return non-empty-string
     */
    private function trustedKey(array $keyring, string $issuer, string $keyId): string
    {
        $this->assertKeys($keyring, ['format', 'keys'], 'harness keyring');
        if (($keyring['format'] ?? null) !== 'duo-harness-keyring/v1'
            || !is_array($keyring['keys'] ?? null) || !array_is_list($keyring['keys']) || $keyring['keys'] === []) {
            throw new CatalogException('harness keyring is malformed');
        }
        $match = null;
        $seen = [];
        foreach ($this->requiredList($keyring, 'keys', 'harness keyring') as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new CatalogException('harness keyring entry is malformed');
            }
            $entry = $this->stringKeyed($entry, 'harness keyring entry');
            $this->assertKeys($entry, ['issuer', 'key_id', 'public_key'], 'harness keyring entry');
            $entryIssuer = $this->requiredString($entry, 'issuer', 'harness keyring entry');
            $entryKeyId = $this->requiredString($entry, 'key_id', 'harness keyring entry');
            $identity = $entryIssuer . "\0" . $entryKeyId;
            $decoded = base64_decode($this->requiredString($entry, 'public_key', 'harness keyring entry'), true);
            if ($identity === "\0" || isset($seen[$identity]) || !is_string($decoded)
                || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new CatalogException('harness keyring entry is malformed or duplicated');
            }
            $seen[$identity] = true;
            if (hash_equals($entryIssuer, $issuer) && hash_equals($entryKeyId, $keyId)) {
                $match = $decoded;
            }
        }
        if (!is_string($match)) {
            throw new CatalogException('harness approval trust root is absent');
        }
        return $match;
    }

    private function safeEnvironmentName(string $name): bool
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $name) !== 1) {
            return false;
        }
        return preg_match('/^(?:PATH|HOME|TMPDIR|TEMP|TMP|PWD|OLDPWD|BASH_ENV|ENV|SHELLOPTS|PHP_INI_SCAN_DIR|PHPRC|LD_.+|DYLD_.+|GIT_.+|COMPOSER_.+|XDG_.+)$/D', $name) !== 1;
    }

    private function externalBytes(string $path, string $label): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || is_link($path) || !is_file($path)) {
            throw new CatalogException("$label must be an absolute external regular file");
        }
        $real = realpath($path);
        $root = realpath($this->root);
        if (!is_string($real) || !is_string($root) || $real === $root || str_starts_with($real, $root . '/')) {
            throw new CatalogException("$label must be controlled outside the repository");
        }
        $mode = fileperms($real);
        if (!is_int($mode) || ($mode & 0022) !== 0) {
            throw new CatalogException("$label must not be group/world writable");
        }
        $bytes = file_get_contents($real);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 1_048_576) {
            throw new CatalogException("$label is unreadable or unreasonably large");
        }
        return $bytes;
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $bytes, string $label): array
    {
        try {
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException("$label is invalid JSON: {$exception->getMessage()}");
        }
        if (!is_array($decoded) || array_is_list($decoded) || $this->canonical($decoded) . "\n" !== $bytes) {
            throw new CatalogException("$label is not a canonical JSON object");
        }
        return $this->stringKeyed($decoded, $label);
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $keys
     */
    private function assertKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new CatalogException("$label has missing or unrecognized fields");
        }
    }

    private function digestValue(mixed $value): bool
    {
        return is_string($value) && preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
    }

    private function digest(string $bytes): string
    {
        return 'sha256:' . hash('sha256', $bytes);
    }

    /**
     * @param array<mixed,mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyed(array $value, string $label): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new CatalogException("$label must be an object");
            }
            $normalized[$key] = $item;
        }
        return $normalized;
    }

    /** @param array<string,mixed> $value */
    private function requiredString(array $value, string $key, string $label): string
    {
        $found = $value[$key] ?? null;
        if (!is_string($found)) {
            throw new CatalogException("$label $key must be a string");
        }
        return $found;
    }

    /**
     * @param array<string,mixed> $value
     * @return list<mixed>
     */
    private function requiredList(array $value, string $key, string $label): array
    {
        $found = $value[$key] ?? null;
        if (!is_array($found) || !array_is_list($found)) {
            throw new CatalogException("$label $key must be a list");
        }
        return $found;
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(fn(mixed $item): string => $this->canonical($item), $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . $this->canonical($item);
            }
            return '{' . implode(',', $pairs) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
