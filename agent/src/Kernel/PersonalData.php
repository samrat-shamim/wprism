<?php
namespace WPrism;

/**
 * Conservative PII detector for user-meta values entering canonical state.
 *
 * User meta is credential/PII-dense by default. Unlike Secrets (whose hard
 * vendor-token matches are globally useful), these patterns intentionally
 * apply only to the new user-meta sidecar. A hit requires an explicit
 * allow_pii:true on that exact user_meta rule; unknown keys never reach this
 * scanner because they remain target-local unless an adapter/operator first
 * classifies them authored.
 */
final class PersonalData {
    private const MAX_LEN = 65536;

    /** @var array<string,string> */
    private const KEY_PATTERNS = [
        '/(^|_)(first_?name|last_?name|full_?name|display_?name|nickname)(_|$)/i' => 'personal name',
        '/(^|_)(email|e_?mail)(_|$)/i' => 'email address',
        '/(^|_)(phone|mobile|telephone|tel)(_|$)/i' => 'phone number',
        '/(^|_)(address|street|city|state|province|postcode|postal|zip|country)(_|$)/i' => 'postal address',
        '/(^|_)(birth|birthday|dob|ssn|national_?id|passport|tax_?id)(_|$)/i' => 'personal identifier',
    ];

    /** Return a short PII label, or null when no conservative signal matches. */
    public static function match_deep(string $key, $value): ?string {
        foreach (self::KEY_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $key)) {
                return $label;
            }
        }
        return self::match_value_deep($value);
    }

    private static function match_value_deep($value): ?string {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                if (is_string($childKey)) {
                    foreach (self::KEY_PATTERNS as $pattern => $label) {
                        if (preg_match($pattern, $childKey)) {
                            return $label;
                        }
                    }
                }
                $label = self::match_value_deep($child);
                if ($label !== null) {
                    return $label;
                }
            }
            return null;
        }
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LEN) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return 'email address';
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return 'IP address';
        }
        // Deliberately requires 7+ digits and a phone-like alphabet. This
        // avoids treating ordinary prose or short numeric settings as PII.
        if (preg_match('/^[+() .\/-]*[0-9][0-9+() .\/-]{5,}[0-9]$/', $value)
            && preg_match_all('/[0-9]/', $value) >= 7) {
            return 'phone number';
        }
        return null;
    }
}
