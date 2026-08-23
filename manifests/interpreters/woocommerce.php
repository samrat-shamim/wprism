<?php
declare(strict_types=1);

namespace Duo\Interpreters;

use Duo\Canon;
use Duo\Policy;

/**
 * WooCommerce 11.0.0 reads `_product_attributes` as a six-field row map.
 * Its native reader silently drops a non-array value and fills missing row
 * fields with defaults, so canonical JSON can otherwise be valid while the
 * promoted catalog loses merchant-authored attributes. Keep extension-owned
 * extra fields portable, but reject corruption of the core row contract.
 */
final class Woocommerce {
    private const REQUIRED_FIELDS = [
        'name',
        'value',
        'position',
        'is_visible',
        'is_variation',
        'is_taxonomy',
    ];

    public function __construct(private readonly Policy $policy) {}

    public function post_meta_rule(string $key, array $allMeta): ?array {
        if (!in_array($key, ['_downloadable_files', '_product_attributes'], true)) {
            return null;
        }
        return $this->policy->post_meta_rule($key);
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $front = $entity['data'] ?? Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
            $meta = (array) ($front['meta'] ?? []);
            $postType = (string) ($front['type'] ?? '');
            $path = (string) ($entity['path'] ?? '');
            if (array_key_exists('_product_attributes', $meta)) {
                if ($postType !== 'product') {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta._product_attributes',
                        'WooCommerce product attributes are valid only on product entities'
                    );
                } else {
                    $out = array_merge($out, $this->attribute_diagnostics($path, $meta['_product_attributes']));
                }
            }
            if (array_key_exists('_downloadable_files', $meta)) {
                if (!in_array($postType, ['product', 'product_variation'], true)) {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta._downloadable_files',
                        'WooCommerce downloadable files are valid only on product or product_variation entities'
                    );
                } else {
                    $out = array_merge($out, $this->download_diagnostics($path, $meta['_downloadable_files']));
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function download_diagnostics(string $path, mixed $downloads): array {
        if (!is_array($downloads)) {
            return [$this->diagnostic(
                $path,
                'meta._downloadable_files',
                'WooCommerce downloadable files must be an object keyed by download identity'
            )];
        }
        if ($downloads !== [] && array_is_list($downloads)) {
            return [$this->diagnostic(
                $path,
                'meta._downloadable_files',
                'WooCommerce downloadable files must be a named object, not a positional list'
            )];
        }

        $out = [];
        foreach ($downloads as $downloadKey => $row) {
            $locator = 'meta._downloadable_files.' . (is_string($downloadKey) ? $downloadKey : (string) $downloadKey);
            if (!is_string($downloadKey) || $downloadKey === '' || strlen($downloadKey) > 128) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce download identities must be non-empty strings of at most 128 bytes'
                );
                continue;
            }
            if (!is_array($row) || array_is_list($row)) {
                $out[] = $this->diagnostic($path, $locator, 'WooCommerce downloadable-file rows must be named objects');
                continue;
            }
            foreach (['name', 'file'] as $field) {
                if (!array_key_exists($field, $row) || !is_string($row[$field])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.$field",
                        "WooCommerce downloadable-file $field must be a string"
                    );
                }
            }
            if (is_string($row['file'] ?? null)) {
                if ($row['file'] === '') {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.file",
                        'WooCommerce downloadable-file file must be non-empty'
                    );
                } elseif (str_starts_with($row['file'], '[') && str_ends_with($row['file'], ']')) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.file",
                        'WooCommerce shortcode download locators are extension-executed and outside this adapter contract'
                    );
                }
            }
            if (array_key_exists('id', $row)
                && (!is_string($row['id']) || $row['id'] !== $downloadKey)) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.id",
                    'WooCommerce downloadable-file id must equal its object key'
                );
            }
            if (array_key_exists('enabled', $row)) {
                if (!is_bool($row['enabled'])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.enabled",
                        'WooCommerce downloadable-file enabled must be boolean when present'
                    );
                } elseif ($row['enabled'] !== true) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.enabled",
                        'WooCommerce disabled download rows reflect site-local approval state and are outside the portable authored contract'
                    );
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function attribute_diagnostics(string $path, mixed $attributes): array {
        if (!is_array($attributes)) {
            return [$this->diagnostic(
                $path,
                'meta._product_attributes',
                'WooCommerce product attributes must be an object keyed by the native attribute name'
            )];
        }
        if ($attributes !== [] && array_is_list($attributes)) {
            return [$this->diagnostic(
                $path,
                'meta._product_attributes',
                'WooCommerce product attributes must be a named object, not a positional list'
            )];
        }

        $out = [];
        foreach ($attributes as $attributeKey => $row) {
            $locator = 'meta._product_attributes.' . (is_string($attributeKey) ? $attributeKey : (string) $attributeKey);
            if (!is_string($attributeKey) || $attributeKey === '') {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute keys must be non-empty strings'
                );
                continue;
            }
            if (!is_array($row) || array_is_list($row)) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute rows must be named objects'
                );
                continue;
            }
            $missing = array_values(array_diff(self::REQUIRED_FIELDS, array_keys($row)));
            if ($missing !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute row is missing required field(s): ' . implode(', ', $missing)
                );
                continue;
            }
            if (!is_string($row['name']) || $row['name'] === '') {
                $out[] = $this->diagnostic($path, "$locator.name", 'WooCommerce product attribute name must be a non-empty string');
            }
            if (!is_string($row['value'])) {
                $out[] = $this->diagnostic($path, "$locator.value", 'WooCommerce product attribute value must be a string');
            }
            if (!is_int($row['position']) || $row['position'] < 0) {
                $out[] = $this->diagnostic($path, "$locator.position", 'WooCommerce product attribute position must be a non-negative integer');
            }
            foreach (['is_visible', 'is_variation', 'is_taxonomy'] as $flag) {
                if (!is_int($row[$flag]) || ($row[$flag] !== 0 && $row[$flag] !== 1)) {
                    $out[] = $this->diagnostic($path, "$locator.$flag", "WooCommerce product attribute $flag must be integer 0 or 1");
                }
            }
            if (($row['is_taxonomy'] ?? null) === 1) {
                if (($row['name'] ?? null) !== $attributeKey
                    || preg_match('/^pa_[a-z0-9_-]+$/D', $attributeKey) !== 1) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.name",
                        'WooCommerce global attribute name must equal its pa_* object key'
                    );
                }
                if (($row['value'] ?? null) !== '') {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.value",
                        'WooCommerce global attribute value must be empty; term relationships carry its options'
                    );
                }
            }
        }
        return $out;
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
