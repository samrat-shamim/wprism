<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Closed validator for portable evidence/run identity fields. */
final class RedactedEvidenceIdentity {
    public const FORMAT = 'duo-redacted-evidence-identity/v1';

    /** Reviewed public fields; all environment-specific identity belongs in a reference or keyed binding. */
    private const PUBLIC_VALUES = [
        'platform_profile' => ['php-8.3', 'wordpress-7.0.3', 'mariadb-11'],
        'runner_protocol' => [1],
        'toolchain_profile' => ['composer-2', 'phpunit-11', 'phpstan-2', 'rector-2'],
        'harness_profile' => ['duo-foundation', 'duo-qualification-v1'],
    ];

    /** @param array<string,mixed> $identity */
    public static function assertValid(array $identity): void {
        $keys = array_keys($identity); sort($keys, SORT_STRING);
        if ($keys !== ['format','keyed_bindings','public','secret_references']
            || ($identity['format'] ?? null) !== self::FORMAT
            || !is_array($identity['public'] ?? null)
            || ($identity['public'] !== [] && array_is_list($identity['public']))
            || !is_array($identity['secret_references'] ?? null) || !array_is_list($identity['secret_references'])
            || !is_array($identity['keyed_bindings'] ?? null)
            || ($identity['keyed_bindings'] !== [] && array_is_list($identity['keyed_bindings']))) {
            throw new \RuntimeException('duo evidence: redacted identity is malformed');
        }
        if ($identity['public'] === [] && $identity['secret_references'] === [] && $identity['keyed_bindings'] === []) {
            throw new \RuntimeException('duo evidence: redacted identity contains no portable identity');
        }
        foreach ($identity['public'] as $key => $value) {
            if (!is_string($key) || !isset(self::PUBLIC_VALUES[$key])
                || !in_array($value, self::PUBLIC_VALUES[$key], true)) {
                throw new \RuntimeException('duo evidence: public identity contains an unreviewed field or value');
            }
        }
        $seen = [];
        foreach ($identity['secret_references'] as $reference) {
            if (!is_string($reference)
                || preg_match('/^vault:[a-z][a-z0-9._-]{2,63}$/D', $reference) !== 1
                || self::looksSensitive($reference)
                || isset($seen[$reference])) {
                throw new \RuntimeException('duo evidence: secret reference id is malformed, sensitive, or duplicated');
            }
            $seen[$reference] = true;
        }
        foreach ($identity['keyed_bindings'] as $bindingIdentity => $digest) {
            if (!is_string($bindingIdentity)
                || preg_match('/^[a-z][a-z0-9._-]{2,63}:[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $bindingIdentity) !== 1
                || !is_string($digest)
                || preg_match('/^hmac-sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \RuntimeException('duo evidence: keyed binding must be unique domain-separated HMAC identity');
            }
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
