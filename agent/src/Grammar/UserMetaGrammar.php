<?php
namespace WPrism;

/**
 * Pure safety grammar for user-meta rules.
 *
 * The same rule shape is used for static manifest declarations and for
 * interpreter-returned rules. Policy supplies the published class and
 * missing-user vocabularies explicitly; it retains those constants because
 * closed_vocabularies() publishes them. This collaborator performs no
 * interpreter dispatch or user lookup.
 */
final class UserMetaGrammar {
    /**
     * Validate every static user-meta rule in a manifest or site policy.
     *
     * @param list<string> $classes
     * @param list<string> $missingUserModes
     */
    public static function validate_user_meta_rules(
        array $source,
        string $label,
        array $classes,
        array $missingUserModes
    ): void {
        foreach ((array) ($source['user_meta'] ?? []) as $key => $rule) {
            if (!is_array($rule)) {
                throw new \RuntimeException("wprism: $label user_meta.$key must be a rule object");
            }
            self::validate_user_meta_rule($rule, "$label user_meta.$key", $classes, $missingUserModes);
        }
    }

    /**
     * Validate one static or interpreter-returned user-meta rule. Refusal
     * paths intentionally remain byte-for-byte identical to Policy's former
     * implementation.
     *
     * @param list<string> $classes
     * @param list<string> $missingUserModes
     */
    public static function validate_user_meta_rule(
        array $rule,
        string $where,
        array $classes,
        array $missingUserModes
    ): void {
        $class = $rule['class'] ?? null;
        if (!in_array($class, $classes, true)) {
            throw new \RuntimeException(
                "wprism: $where has an invalid or missing class (expected " . implode('|', $classes) . ')'
            );
        }
        if (isset($rule['allow_pii']) && !is_bool($rule['allow_pii'])) {
            throw new \RuntimeException("wprism: $where allow_pii must be a boolean");
        }
        if (isset($rule['allow_secret']) && !is_bool($rule['allow_secret'])) {
            throw new \RuntimeException("wprism: $where allow_secret must be a boolean");
        }
        if ($class !== 'authored' && (!empty($rule['allow_pii']) || !empty($rule['allow_secret']))) {
            throw new \RuntimeException(
                "wprism: $where PII/secret capture exceptions are valid only for class=authored"
            );
        }
        if (isset($rule['missing_user'])) {
            if ($class !== 'authored') {
                throw new \RuntimeException("wprism: $where missing_user is valid only for class=authored");
            }
            if (!in_array($rule['missing_user'], $missingUserModes, true)) {
                throw new \RuntimeException("wprism: $where missing_user must be block or warn");
            }
        }
    }
}
