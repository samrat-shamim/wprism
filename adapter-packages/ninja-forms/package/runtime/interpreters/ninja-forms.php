<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\Policy;

/**
 * Repository boundary for Ninja Forms' built-in 3.x table graph.
 *
 * The typed-table grammar intentionally captures default-authored meta because
 * Ninja Forms stores most native settings there. That openness must not turn
 * into an accidental certification of arbitrary add-on field/action types or
 * unsafe serialized PHP objects. This interpreter closes those two shapes at
 * compile time while leaving scalar and array settings owned by core 3.x.
 */
final class NinjaForms {
    private const FIELD_TYPES = [
        'address', 'address2', 'button', 'checkbox', 'city', 'confirm',
        'creditcard', 'creditcardcvc', 'creditcardexpiration',
        'creditcardfullname', 'creditcardnumber', 'creditcardzip', 'date',
        'email', 'firstname', 'hcaptcha', 'hidden', 'hr', 'html', 'lastname',
        'listcheckbox', 'listcountry', 'listimage', 'listmultiselect',
        'listradio', 'listselect', 'liststate', 'note', 'number', 'password',
        'passwordconfirm', 'phone', 'product', 'quantity', 'recaptcha',
        'recaptcha_v3', 'repeater', 'shipping', 'signature', 'spam',
        'starrating', 'submit', 'terms', 'textarea', 'textbox', 'total',
        'turnstile', 'unknown', 'zip',
    ];

    private const ACTION_TYPES = [
        'akismet', 'collectpayment', 'custom', 'deletedatarequest', 'email',
        'exportdatarequest', 'googleanalytics', 'recaptcha', 'redirect', 'save',
        'successmessage',
    ];

    private const MAX_SERIALIZED_BYTES = 16777216;

    private const MAX_SERIALIZED_NODES = 200000;

    /** Core 3.x settings whose model contract is an array serialized at rest. */
    private const PLAIN_DATA_META_KEYS = [
        'nf3_forms' => ['calculations', 'formContentData'],
        'nf3_fields' => ['image_options', 'options', 'shipping_options'],
        'nf3_actions' => ['exception_fields'],
    ];

    public function __construct(Policy $policy) {
        // The contract is the exact built-in 3.x vocabulary above. Runtime
        // registry readback below only narrows that vocabulary for the active
        // exact release; it never admits an add-on type.
    }

    public function post_meta_rule(string $key, array $allMeta): ?array {
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        $runtime = $this->runtime_types();
        foreach ($tree as $entity) {
            $table = (string) ($entity['type'] ?? '');
            if (!in_array($table, ['nf3_forms', 'nf3_fields', 'nf3_actions'], true)) {
                continue;
            }
            $path = (string) ($entity['path'] ?? '');
            $data = is_array($entity['data'] ?? null) ? $entity['data'] : [];
            $columns = is_array($data['columns'] ?? null) ? $data['columns'] : [];
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

            if ($table === 'nf3_fields' || $table === 'nf3_actions') {
                $type = $columns['type'] ?? null;
                $allowed = $table === 'nf3_fields' ? self::FIELD_TYPES : self::ACTION_TYPES;
                $runtimeAllowed = $runtime[$table] ?? null;
                if (!is_string($type) || !in_array($type, $allowed, true)) {
                    $out[] = $this->diagnostic(
                        $path,
                        'columns.type',
                        "Ninja Forms $table type must be a certified built-in 3.x type; optional add-on type '"
                        . (is_scalar($type) ? (string) $type : get_debug_type($type)) . "' is outside this adapter"
                    );
                } elseif (is_array($runtimeAllowed) && !isset($runtimeAllowed[$type])) {
                    $out[] = $this->diagnostic(
                        $path,
                        'columns.type',
                        "Ninja Forms $table type '$type' is not registered by the installed exact plugin release"
                    );
                }
            }

            foreach (['columns' => $columns, 'meta' => $meta] as $section => $values) {
                foreach ($values as $key => $value) {
                    $locator = $section . '.' . (string) $key;
                    $plainData = $section === 'meta'
                        && in_array(
                            (string) $key,
                            self::PLAIN_DATA_META_KEYS[$table] ?? [],
                            true
                        );
                    if ($plainData) {
                        $problem = $this->plain_data_problem($value);
                        if ($problem !== null) {
                            $out[] = $this->diagnostic($path, $locator, $problem);
                        }
                        continue;
                    }

                    if ($section === 'meta' && is_array($value)) {
                        $out[] = $this->diagnostic(
                            $path,
                            $locator,
                            "Ninja Forms structured setting '$key' is not a certified core 3.x plain-data key; "
                            . 'optional add-on metadata is outside this adapter'
                        );
                        continue;
                    }
                    if (!is_string($value) || !$this->looks_serialized($value)) {
                        continue;
                    }
                    $problem = $this->serialized_problem($value);
                    $out[] = $this->diagnostic(
                        $path,
                        $locator,
                        $problem ?? "Ninja Forms serialized setting '$key' is not a certified core 3.x "
                            . 'plain-data key; optional add-on metadata is outside this adapter'
                    );
                }
            }
        }
        return $out;
    }

    /** @return array<string,array<string,true>> */
    private function runtime_types(): array {
        if (!function_exists('Ninja_Forms')) {
            return [];
        }
        $plugin = \Ninja_Forms();
        if (!is_object($plugin)
            || !isset($plugin->fields, $plugin->actions)
            || !is_array($plugin->fields)
            || !is_array($plugin->actions)) {
            return [];
        }
        return [
            'nf3_fields' => array_fill_keys(array_map('strval', array_keys($plugin->fields)), true),
            'nf3_actions' => array_fill_keys(array_map('strval', array_keys($plugin->actions)), true),
        ];
    }

    private function looks_serialized(string $value): bool {
        return preg_match(
            '/^(?:N;|b:[01];|i:-?\d+;|d:(?:-?(?:\d+(?:\.\d*)?|\.\d+)(?:E[+-]?\d+)?|INF|-INF|NAN);'
            . '|s:\d+:"|a:\d+:{|O:\d+:"|C:\d+:"|E:\d+:")/D',
            $value
        ) === 1;
    }

    private function serialized_problem(string $value): ?string {
        if (strlen($value) > self::MAX_SERIALIZED_BYTES) {
            return 'Ninja Forms serialized setting exceeds the reviewed 16 MiB per-value boundary';
        }
        $decoded = @unserialize($value, ['allowed_classes' => false, 'max_depth' => 64]);
        if ($decoded === false && $value !== 'b:0;') {
            return 'Ninja Forms serialized setting is malformed and cannot be consumed safely';
        }
        $nodes = 0;
        if (!$this->plain_serialized_value($decoded, 0, $nodes)) {
            return 'Ninja Forms serialized setting contains an object, resource, reference, excessive depth, or excessive node count';
        }
        if (serialize($decoded) !== $value) {
            return 'Ninja Forms serialized setting is noncanonical or has trailing bytes';
        }
        return null;
    }

    private function plain_data_problem(mixed $value): ?string {
        if (is_string($value) && $this->looks_serialized($value)) {
            $problem = $this->serialized_problem($value);
            return $problem
                ?? 'Ninja Forms core array setting contains raw PHP serialization in canonical state; '
                    . 'the manifest plain-data codec must decode storage bytes before compilation';
        }
        if (!is_array($value)) {
            return 'Ninja Forms core array setting must be canonical native plain data';
        }
        $nodes = 0;
        if (!$this->plain_serialized_value($value, 0, $nodes)) {
            return 'Ninja Forms plain-data setting contains an object, resource, reference, excessive depth, '
                . 'or excessive node count';
        }
        try {
            $encoded = serialize($value);
        } catch (\Throwable) {
            return 'Ninja Forms plain-data setting cannot be serialized canonically';
        }
        if (strlen($encoded) > self::MAX_SERIALIZED_BYTES) {
            return 'Ninja Forms plain-data setting exceeds the reviewed 16 MiB per-value boundary';
        }
        return null;
    }

    private function plain_serialized_value(mixed $value, int $depth, int &$nodes): bool {
        $nodes++;
        if ($depth > 64 || $nodes > self::MAX_SERIALIZED_NODES) {
            return false;
        }
        if (is_object($value) || is_resource($value)) {
            return false;
        }
        if (!is_array($value)) {
            return is_null($value) || is_scalar($value);
        }
        foreach ($value as $key => $child) {
            if (\ReflectionReference::fromArrayElement($value, $key) !== null
                || (!is_int($key) && !is_string($key))
                || !$this->plain_serialized_value($child, $depth + 1, $nodes)) {
                return false;
            }
        }
        return true;
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
