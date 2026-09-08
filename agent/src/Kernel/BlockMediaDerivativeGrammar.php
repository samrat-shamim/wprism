<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/BlockValueGrammar.php';
require_once __DIR__ . '/JsonRefs.php';
if (!class_exists(Canon::class, false)) require_once __DIR__ . '/Canon.php';

/** A content declaration chooses data; the attachment transaction owns every resulting file effect. */
final class BlockMediaDerivativeGrammar {
    public const FEATURE = 'block-media-derivatives/v1';
    public const SECTION = 'block_media_derivatives';
    public const MAX_RULES = 128;

    public static function section_grammar(): array {
        return ['keyed_by' => 'block name', 'value' => 'one to 128 derivative declarations',
            'required' => ['attachment', 'url', 'width', 'height', 'crop', 'filename', 'dimension_cast'],
            'optional' => ['path', 'when'], 'path' => 'shared JSON reference path selecting an object or list of objects; absent means block attributes',
            'fields' => 'exact child JSON paths relative to each selected object',
            'when' => ['key' => 'exact sibling key', 'equals' => 'nonempty string; absent or unequal discriminator selects no recipe'],
            'filename' => ['requested-dimensions'], 'dimension_cast' => ['integer', 'truncate'],
            'ownership' => 'same manifest must declare the attachment as a post reference in block_values',
            'validated_by' => 'WPrism\\BlockMediaDerivativeGrammar::validate()'];
    }

    public static function validate(array $manifest): void {
        if (!array_key_exists(self::SECTION, $manifest)) return;
        $blocks = $manifest[self::SECTION];
        if (!is_array($blocks) || $blocks === [] || array_is_list($blocks) || count($blocks) > self::MAX_RULES) {
            throw new \RuntimeException('wprism: block_media_derivatives requires a bounded nonempty block map');
        }
        $count = 0;
        foreach ($blocks as $block => $rules) {
            if (!is_string($block) || preg_match('/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/D', $block) !== 1
                || !is_array($rules) || !array_is_list($rules) || $rules === []) {
                throw new \RuntimeException('wprism: block_media_derivatives requires named blocks and nonempty rule lists');
            }
            $seen = [];
            foreach ($rules as $rule) {
                if (++$count > self::MAX_RULES || !is_array($rule) || array_is_list($rule)
                    || array_diff(array_keys($rule), ['attachment', 'url', 'width', 'height', 'crop', 'filename', 'dimension_cast', 'path', 'when'])
                    || !is_bool($rule['crop'] ?? null) || ($rule['filename'] ?? null) !== 'requested-dimensions'
                    || !in_array($rule['dimension_cast'] ?? null, ['integer', 'truncate'], true)) {
                    throw new \RuntimeException('wprism: block media derivative has an unsupported or oversized declaration');
                }
                foreach (['attachment', 'url', 'width', 'height'] as $field) self::field_path($rule[$field] ?? null);
                if (array_key_exists('path', $rule)) self::path($rule['path']);
                if (array_key_exists('when', $rule)) {
                    $when = $rule['when'];
                    if (!is_array($when) || count($when) !== 2 || array_diff(array_keys($when), ['key', 'equals'])
                        || !is_string($when['key'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $when['key']) !== 1
                        || !is_string($when['equals'] ?? null) || $when['equals'] === '' || strlen($when['equals']) > 128
                        || preg_match('/[\x00-\x1f\x7f]/', $when['equals']) === 1 || preg_match('//u', $when['equals']) !== 1) {
                        throw new \RuntimeException('wprism: block media derivative when requires an exact bounded string discriminator');
                    }
                }
                $referencePath = ($rule['path'] ?? '$') . substr($rule['attachment'], 1);
                $owned = false;
                foreach ($manifest[BlockValueGrammar::SECTION][$block] ?? [] as $attribute => $valueRule) {
                    if (($valueRule['ref'] ?? '') === 'post' && $referencePath === '$.' . $attribute) $owned = true;
                    foreach ($valueRule['json_refs'] ?? [] as $ref) {
                        if (($ref['kind'] ?? '') === 'post' && !isset($ref['when'])
                            && $referencePath === '$.' . $attribute . substr($ref['path'], 1)) $owned = true;
                    }
                }
                if (!$owned) throw new \RuntimeException('wprism: block media derivative lacks its same-manifest typed attachment reference');
                $identity = Canon::encode($rule);
                if (isset($seen[$identity])) throw new \RuntimeException('wprism: block media derivative declaration is duplicated');
                $seen[$identity] = true;
            }
        }
    }

    public static function project(array $manifests): array {
        $out = [];
        $owners = [];
        foreach ($manifests as $index => $manifest) {
            foreach (['block_attrs', BlockValueGrammar::SECTION, self::SECTION] as $section) {
                foreach ($manifest[$section] ?? [] as $block => $_) $owners[$block][$index] = true;
            }
            foreach ($manifest[self::SECTION] ?? [] as $block => $rules) {
                if (isset($out[$block])) throw new \RuntimeException('wprism: block media derivatives require one manifest owner');
                $out[$block] = $rules;
            }
        }
        foreach ($out as $block => $_) {
            if (count($owners[$block]) !== 1) throw new \RuntimeException('wprism: block media derivatives conflict with another block owner');
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public static function field_path(mixed $path): array {
        $segments = self::path($path);
        foreach ($segments as $segment) {
            if ($segment['type'] !== 'child') throw new \RuntimeException('wprism: derivative fields require exact child paths within their selected object');
        }
        return $segments;
    }

    private static function path(mixed $path): array {
        if (!is_string($path) || strlen($path) > 512 || trim($path) !== $path) {
            throw new \RuntimeException('wprism: derivative selector requires a bounded canonical JSON reference path');
        }
        $segments = JsonRefs::parse_path($path);
        if (count($segments) > 32) throw new \RuntimeException('wprism: derivative selector exceeds its path depth bound');
        return $segments;
    }
}
