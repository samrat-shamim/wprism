<?php
namespace WPrism;

/**
 * Conservative PII detector for values entering canonical state.
 *
 * Key names catch reviewed structured fields; bounded overlapping windows
 * catch email, IP, and phone values embedded in prose. Capture decides which
 * canonical surfaces admit an exact allow_pii review exception.
 */
final class PersonalData {
    private const MAX_LEN = 65536;

    /** @var array<string,string> */
    private const KEY_PATTERNS = [
        '/(^|_)(first_?name|last_?name|full_?name|display_?name|nickname|from_?name|reply_?to_?name)$/i' => 'personal name',
        '/(^|_)(email|e_?mail|from_?email|reply_?to_?email|email_(?:from|reply_?to)_address)$/i' => 'email address',
        '/(^|_)(phone(?:_number)?|mobile(?:_number)?|telephone(?:_number)?|tel)$/i' => 'phone number',
        '/(^|_)(address(?:_[12])?|street(?:_[12])?|city|state|province|postcode|postal(?:_code)?|zip(?:_code)?|country(?:_code)?)$/i' => 'postal address',
        '/(^|_)(birth|birthday|dob|ssn|national_?id|passport|tax_?id)$/i' => 'personal identifier',
    ];

    /** Return a short PII label, or null when no conservative signal matches. */
    public static function match_deep(string $key, $value): ?string {
        $keyMatch = self::match_key($key);
        if ($keyMatch !== null && !self::is_template_reference($value)) {
            return $keyMatch;
        }
        return self::match_value_deep($value);
    }

    private static function match_key(string $key): ?string {
        // JSON and serialized plugin state uses both snake_case and camelCase.
        // Normalize only separators and case transitions, then keep the same
        // terminal-field grammar below: firstName/customerEmail become
        // first_name/customer_email, while emailType/checkoutPhoneField stay
        // technical controls rather than personal-data-bearing fields.
        $key = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $key) ?? $key;
        $key = preg_replace('/[^A-Za-z0-9]+/', '_', $key) ?? $key;
        $key = trim($key, '_');
        foreach (self::KEY_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $key)) {
                // These terminal address phrases describe boolean/enum
                // controls, not address-bearing values. Actual address keys
                // (including address_1/address_2) and value scanning remain.
                if ($label === 'postal address'
                    && preg_match('/(^|_)(?:(?:requires|default_customer)_address|(?:editor|workflow)_state)$/i', $key)) {
                    continue;
                }
                return $label;
            }
        }
        return null;
    }

    private static function match_value_deep($value): ?string {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                if (is_string($childKey)) {
                    $keyMatch = self::match_key($childKey);
                    if ($keyMatch !== null && !self::is_template_reference($child)) {
                        return $keyMatch;
                    }
                }
                $label = self::match_value_deep($child);
                if ($label !== null) {
                    return $label;
                }
            }
            return null;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return 'IP address';
        }
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset += 32768) {
            $window = substr($value, $offset, self::MAX_LEN);
            // Canonical entity tokens and ordinary UUIDs are technical
            // identities, not phone numbers. Remove only that exact closed
            // shape before the prose detectors; adjacent content is still
            // scanned and key-name classification is unchanged.
            $window = preg_replace(
                '/(?<![0-9a-f])[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(?![0-9a-f])/i',
                '',
                $window
            ) ?? $window;
            if (preg_match('/(?<![A-Z0-9._%+-])[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,63}(?![A-Z0-9._%+-])/i', $window)) {
                return 'email address';
            }
            if (preg_match_all('/(?<![0-9A-F:.])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![0-9A-F:.])/i', $window, $ips)) {
                foreach ($ips[0] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        return 'IP address';
                    }
                }
            }
            if (preg_match_all('/(?<![A-F0-9:])(?:[A-F0-9]{0,4}:){2,7}[A-F0-9]{0,4}(?![A-F0-9:])/i', $window, $ips)) {
                foreach ($ips[0] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                        return 'IP address';
                    }
                }
            }
            // Deliberately requires 7+ digits and a phone-like alphabet.
            // A generic numeric id and an ISO date are not a phone merely
            // because they contain enough digits; the value also needs phone
            // punctuation or internal spacing unless its key named it.
            if (preg_match_all(
                '/(?<![0-9])\+?[() .\/-]*[0-9][0-9+() .\/-]{5,}[0-9](?![0-9])/',
                $window,
                $phones,
                PREG_OFFSET_CAPTURE
            )) {
                foreach ($phones[0] as [$phone, $phoneOffset]) {
                    $candidate = trim((string) $phone);
                    // WooCommerce permits arbitrary-precision decimal prices;
                    // the measured `_regular_price=2147484004.123456` capture
                    // is numeric state, not a punctuated telephone number.
                    // Key-classified phone fields still return before values
                    // reach this branch, and actual phone punctuation remains.
                    if (preg_match('/^-?(?:[0-9]+\.[0-9]+|\.[0-9]+)$/D', $candidate)) {
                        continue;
                    }
                    if (preg_match('/^[0-9]{4}[-\/]?[0-9]{2}[-\/]?[0-9]{2}$/D', $candidate)) {
                        continue;
                    }
                    // A timestamp's date-and-hour prefix used to satisfy the
                    // phone alphabet because ':' ends the regex match after
                    // `YYYY-MM-DD HH`. ISBNs and dotted release versions have
                    // the same digit count/punctuation coincidence. These are
                    // closed technical grammars; actual international,
                    // parenthesized and 3-3-4 telephone shapes still reach the
                    // positive branch below.
                    if (preg_match('/^[0-9]{4}[-\/][0-9]{2}[-\/][0-9]{2}(?:[ T][0-9]{1,2})?$/D', $candidate)) {
                        continue;
                    }
                    $digits = preg_replace('/[^0-9]/', '', $candidate) ?? '';
                    $prefix = substr($window, max(0, (int) $phoneOffset - 16), min(16, (int) $phoneOffset));
                    $isbnLabelled = preg_match('/ISBN(?:-1[03])?\s*[:#]?\s*$/i', $prefix) === 1;
                    $isbn13 = strlen($digits) === 13
                        && (str_starts_with($digits, '978') || str_starts_with($digits, '979'));
                    if (($isbnLabelled && in_array(strlen($digits), [10, 13], true)) || $isbn13) {
                        continue;
                    }
                    $labelledVersion = preg_match('/(?:^|\b)(?:v(?:ersion)?|release)\s*$/i', $prefix) === 1
                        && preg_match('/^[0-9]+(?:\.[0-9]+){2,}$/D', $candidate);
                    $bareShortDottedSequence = !str_starts_with($candidate, '+')
                        && preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,3}){3,}$/D', $candidate);
                    if ($labelledVersion || $bareShortDottedSequence) {
                        continue;
                    }
                    if (preg_match_all('/[0-9]/', $candidate) >= 7
                        && preg_match('/[+() .\/-]/', $candidate)) {
                        return 'phone number';
                    }
                }
            }
        }
        return null;
    }

    /** Exact canonical tokens and audited WPForms smart tags name future sources, not captured PII. */
    private static function is_template_reference($value): bool {
        if (!is_string($value)) {
            return false;
        }
        if (preg_match('/^\{\{(?:home|uploads)\}\}$/D', $value)) {
            return true;
        }
        if (preg_match(
            '/^\{\{[a-z][a-z0-9_]*:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\}\}$/D',
            $value
        )) {
            return true;
        }
        return preg_match('/^\{(?:admin_email|all_fields|field_id="[0-9]{1,10}")\}$/D', $value) === 1;
    }
}
