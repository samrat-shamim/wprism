<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\Canon;
use WPrism\Policy;

/**
 * Contact Form 7 stores five form properties in either current underscored
 * metadata or its still-readable pre-3.3 names. The interpreter keeps legacy
 * forms portable, excludes verified environment/runtime residues, and closes
 * the repository shapes CF7 6.0 and 6.1.7 actually consume.
 */
final class ContactForm7 {
    private const PROPERTY_RULES = [
        'additional_settings' => ['plain_data' => false],
        'form' => ['plain_data' => false],
        'mail' => ['plain_data' => true, 'allow_pii' => true],
        'mail_2' => ['plain_data' => true, 'allow_pii' => true],
        // messages carries the same reviewed PII exception the manifest records
        // for the underscored spelling, and for the same reason: the capture
        // guard matches keys as well as values, and this object's keys are CF7
        // message identifiers rather than data fields. invalid_email --
        // registered by modules/text.php through the wpcf7_messages filter,
        // default prose 'Please enter an email address.' -- trips the email-key
        // heuristic on its name alone. These rules key on the legacy unprefixed
        // property while the manifest declares the underscored one, so the two
        // move together or a legacy-spelling form refuses where a current one
        // captures.
        'messages' => ['plain_data' => true, 'allow_pii' => true],
    ];

    private const NON_PORTABLE_RULES = [
        '_config_errors' => ['class' => 'runtime'],
        '_config_validation' => ['class' => 'runtime'],
        '_constant_contact' => ['class' => 'env'],
        '_flamingo' => ['class' => 'runtime'],
        '_sendinblue' => ['class' => 'env'],
    ];

    private const MAIL_STRING_FIELDS = [
        'additional_headers',
        'attachments',
        'body',
        'recipient',
        'sender',
        'subject',
    ];

    public function __construct(Policy $policy) {
        // Classification is completely defined by the pinned CF7 storage
        // contract; no site policy lookup is needed after construction.
    }

    public function post_meta_rule(string $key, array $allMeta): ?array {
        if (!$this->is_cf7_meta($allMeta)) {
            return null;
        }
        if (isset(self::PROPERTY_RULES[$key])) {
            if (array_key_exists('_' . $key, $allMeta)) {
                throw new \RuntimeException(
                    "wprism: Contact Form 7 form has both '$key' and '_$key'; current CF7 prefers the underscored "
                    . 'property, so the legacy duplicate must be removed before capture'
                );
            }
            $rule = ['class' => 'authored'];
            if (self::PROPERTY_RULES[$key]['plain_data']) {
                $rule['plain_data'] = true;
            }
            if (!empty(self::PROPERTY_RULES[$key]['allow_pii'])) {
                $rule['allow_pii'] = true;
            }
            return $rule;
        }
        return self::NON_PORTABLE_RULES[$key] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        $prefixOwners = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $front = $entity['data'] ?? Canon::parse_post_file((string) $entity['content'])[0];
            if (($front['type'] ?? '') !== 'wpcf7_contact_form') {
                continue;
            }
            $path = (string) ($entity['path'] ?? '');
            $meta = (array) ($front['meta'] ?? []);
            $hash = $meta['_hash'] ?? null;
            if (!is_string($hash) || preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', $hash) !== 1) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._hash',
                    'Contact Form 7 _hash must be one 40- or 64-byte lowercase hexadecimal identity'
                );
            } else {
                $prefix = substr($hash, 0, 7);
                if (isset($prefixOwners[$prefix])) {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta._hash',
                        "Contact Form 7 _hash prefix '$prefix' duplicates {$prefixOwners[$prefix]}; native lookup is ambiguous"
                    );
                } else {
                    $prefixOwners[$prefix] = $path;
                }
            }

            $locale = $meta['_locale'] ?? null;
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(?:_[a-zA-Z_]{2,})?$/D', $locale) !== 1) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._locale',
                    'Contact Form 7 _locale does not satisfy the exact CF7 6.x locale grammar'
                );
            }

            $properties = [];
            foreach (array_keys(self::PROPERTY_RULES) as $name) {
                $current = '_' . $name;
                $hasCurrent = array_key_exists($current, $meta);
                $hasLegacy = array_key_exists($name, $meta);
                if ($hasCurrent === $hasLegacy) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$current",
                        $hasCurrent
                            ? "Contact Form 7 form has both current '$current' and legacy '$name' properties"
                            : "Contact Form 7 form is missing both current '$current' and legacy '$name' properties"
                    );
                    continue;
                }
                $properties[$name] = $meta[$hasCurrent ? $current : $name];
            }
            foreach (['form', 'additional_settings'] as $name) {
                if (array_key_exists($name, $properties) && !is_string($properties[$name])) {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta.' . (array_key_exists('_' . $name, $meta) ? '_' : '') . $name,
                        "Contact Form 7 $name property must be a string"
                    );
                }
            }
            foreach (['mail', 'mail_2'] as $name) {
                if (array_key_exists($name, $properties)) {
                    $out = array_merge($out, $this->mail_diagnostics($path, $name, $properties[$name]));
                }
            }
            if (array_key_exists('messages', $properties)) {
                $messages = $properties['messages'];
                if (!is_array($messages) || array_is_list($messages)) {
                    $out[] = $this->diagnostic(
                        $path,
                        $this->property_locator($meta, 'messages'),
                        'Contact Form 7 messages property must be an object of named strings'
                    );
                } else {
                    foreach ($messages as $name => $message) {
                        if (!is_string($name) || $name === '' || !is_string($message)) {
                            $out[] = $this->diagnostic(
                                $path,
                                $this->property_locator($meta, 'messages'),
                                'Contact Form 7 messages property must contain only non-empty string names and string values'
                            );
                            break;
                        }
                    }
                }
            }
            if (array_key_exists('_old_cf7_unit_id', $meta)
                && !$this->is_positive_decimal_alternate($meta['_old_cf7_unit_id'])) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._old_cf7_unit_id',
                    'Contact Form 7 legacy unit identity must be a canonical positive decimal within its runtime domain'
                );
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function mail_diagnostics(string $path, string $name, mixed $mail): array {
        $locator = "meta._$name";
        if (!is_array($mail) || array_is_list($mail)) {
            return [$this->diagnostic($path, $locator, "Contact Form 7 $name property must be an object")];
        }
        $out = [];
        $allowed = [...self::MAIL_STRING_FIELDS, 'active', 'exclude_blank', 'use_html'];
        foreach (array_diff(array_keys($mail), $allowed) as $unknown) {
            $out[] = $this->diagnostic(
                $path,
                "$locator.$unknown",
                "Contact Form 7 $name contains an unknown field '$unknown'"
            );
        }
        foreach (self::MAIL_STRING_FIELDS as $field) {
            if (!array_key_exists($field, $mail) || !is_string($mail[$field])) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.$field",
                    "Contact Form 7 $name.$field must be a string"
                );
            }
        }
        foreach (['exclude_blank', 'use_html'] as $field) {
            if (!array_key_exists($field, $mail) || !$this->is_boolean_wire($mail[$field])) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.$field",
                    "Contact Form 7 $name.$field must be boolean or integer 0/1"
                );
            }
        }
        if (array_key_exists('active', $mail) && !$this->is_boolean_wire($mail['active'])) {
            $out[] = $this->diagnostic(
                $path,
                "$locator.active",
                "Contact Form 7 $name.active must be boolean or integer 0/1"
            );
        }
        return $out;
    }

    private function is_cf7_meta(array $meta): bool {
        if (!array_key_exists('_hash', $meta)) {
            return false;
        }
        foreach (array_keys(self::PROPERTY_RULES) as $name) {
            if (array_key_exists($name, $meta) || array_key_exists('_' . $name, $meta)) {
                return true;
            }
        }
        return false;
    }

    private function property_locator(array $meta, string $name): string {
        return 'meta.' . (array_key_exists('_' . $name, $meta) ? '_' : '') . $name;
    }

    private function is_boolean_wire(mixed $value): bool {
        return is_bool($value) || (is_int($value) && ($value === 0 || $value === 1));
    }

    private function is_positive_decimal_alternate(mixed $value): bool {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return false;
        }
        return strlen($value) < 10 || (strlen($value) === 10 && strcmp($value, '9999999999') <= 0);
    }

    /** @return array{code:string,path:string,locator:string,message:string} */
    private function diagnostic(string $path, string $locator, string $message): array {
        return [
            'code' => 'adapter_schema_content_mismatch',
            'path' => $path,
            'locator' => $locator,
            'message' => $message,
        ];
    }
}
