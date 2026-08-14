<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Closed validator for portable evidence/run identity fields. */
final class RedactedEvidenceIdentity {
    public const FORMAT = 'duo-redacted-evidence-identity/v1';

    /** @param array<string,mixed> $identity */
    public static function assertValid(array $identity): void {
        $keys = array_keys($identity); sort($keys, SORT_STRING);
        if ($keys !== ['format','keyed_bindings','public','secret_references']
            || ($identity['format'] ?? null) !== self::FORMAT
            || !is_array($identity['public'] ?? null) || array_is_list($identity['public'])
            || !is_array($identity['secret_references'] ?? null) || !array_is_list($identity['secret_references'])
            || !is_array($identity['keyed_bindings'] ?? null) || !array_is_list($identity['keyed_bindings'])) {
            throw new \RuntimeException('duo evidence: redacted identity is malformed');
        }
        foreach ($identity['public'] as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $key) !== 1
                || preg_match('/(?:secret|password|credential|token|path|email|phone|address|login|pii)/i', $key) === 1
                || (!is_string($value) && !is_int($value) && !is_bool($value))
                || (is_string($value) && self::looksSensitive($value))) {
                throw new \RuntimeException('duo evidence: public identity contains a secret, PII, path, or unreviewed value');
            }
        }
        $seen = [];
        foreach ($identity['secret_references'] as $reference) {
            if (!is_string($reference)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $reference) !== 1
                || isset($seen[$reference])) {
                throw new \RuntimeException('duo evidence: secret reference id is malformed or duplicated');
            }
            $seen[$reference] = true;
        }
        foreach ($identity['keyed_bindings'] as $binding) {
            if (!is_array($binding)) throw new \RuntimeException('duo evidence: keyed binding is malformed');
            $bindingKeys = array_keys($binding); sort($bindingKeys, SORT_STRING);
            if ($bindingKeys !== ['digest','domain','key_id']
                || !is_string($binding['domain'] ?? null)
                || preg_match('/^[a-z][a-z0-9._-]{2,63}$/D', $binding['domain']) !== 1
                || !is_string($binding['key_id'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $binding['key_id']) !== 1
                || !is_string($binding['digest'] ?? null)
                || preg_match('/^hmac-sha256:[a-f0-9]{64}$/D', $binding['digest']) !== 1) {
                throw new \RuntimeException('duo evidence: keyed binding must be domain-separated HMAC identity');
            }
        }
    }

    private static function looksSensitive(string $value): bool {
        return str_starts_with($value, '/')
            || preg_match('~(?:^|[\\/])(?:Users|home|var|tmp|srv)(?:[\\/]|$)~i', $value) === 1
            || str_contains($value, '-----BEGIN')
            || preg_match('/(?:AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|sk_(?:live|test)_[A-Za-z0-9]+)/', $value) === 1;
    }
}
