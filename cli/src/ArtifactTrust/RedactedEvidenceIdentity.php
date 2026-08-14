<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Closed validator for portable evidence/run identity fields. */
final class RedactedEvidenceIdentity {
    public const FORMAT = 'duo-redacted-evidence-identity/v1';

    /** Reviewed public fields; all environment-specific identity belongs in a reference or keyed binding. */
    private const PUBLIC_GRAMMAR = [
        'platform_profile' => '/^[a-z][a-z0-9]{1,15}-[a-z0-9][a-z0-9._-]{0,47}$/D',
        'runner_protocol' => '/^[1-9][0-9]{0,5}$/D',
        'toolchain_profile' => '/^[a-z][a-z0-9]{1,15}-[a-z0-9][a-z0-9._-]{0,47}$/D',
        'harness_profile' => '/^[a-z][a-z0-9]{1,15}-[a-z0-9][a-z0-9._-]{0,47}$/D',
    ];

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
            if (!is_string($key) || !isset(self::PUBLIC_GRAMMAR[$key])
                || (!is_string($value) && !is_int($value))
                || preg_match(self::PUBLIC_GRAMMAR[$key], (string) $value) !== 1
                || (is_string($value) && self::looksSensitive($value))) {
                throw new \RuntimeException('duo evidence: public identity contains an unreviewed field or value');
            }
        }
        $seen = [];
        foreach ($identity['secret_references'] as $reference) {
            if (!is_string($reference)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $reference) !== 1
                || self::looksSensitive($reference)
                || isset($seen[$reference])) {
                throw new \RuntimeException('duo evidence: secret reference id is malformed, sensitive, or duplicated');
            }
            $seen[$reference] = true;
        }
        $seenBindings = [];
        foreach ($identity['keyed_bindings'] as $binding) {
            if (!is_array($binding)) throw new \RuntimeException('duo evidence: keyed binding is malformed');
            $bindingKeys = array_keys($binding); sort($bindingKeys, SORT_STRING);
            $bindingIdentity = is_string($binding['domain'] ?? null) && is_string($binding['key_id'] ?? null)
                ? $binding['domain'] . ':' . $binding['key_id'] : '';
            if ($bindingKeys !== ['digest','domain','key_id']
                || preg_match('/^[a-z][a-z0-9._-]{2,63}$/D', (string) ($binding['domain'] ?? '')) !== 1
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', (string) ($binding['key_id'] ?? '')) !== 1
                || preg_match('/^hmac-sha256:[a-f0-9]{64}$/D', (string) ($binding['digest'] ?? '')) !== 1
                || isset($seenBindings[$bindingIdentity])) {
                throw new \RuntimeException('duo evidence: keyed binding must be unique domain-separated HMAC identity');
            }
            $seenBindings[$bindingIdentity] = true;
        }
    }

    private static function looksSensitive(string $value): bool {
        return str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, '@')
            || str_contains($value, '://') || str_contains($value, '-----BEGIN')
            || preg_match('/(?:^|[^A-Za-z0-9])(?:[A-Za-z0-9_-]+\.){2}[A-Za-z0-9_-]+(?:[^A-Za-z0-9]|$)/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1
            || preg_match('/(?:AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|sk_(?:live|test)_[A-Za-z0-9]+)/', $value) === 1;
    }
}
