<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/BlockMediaDerivativeGrammar.php';
require_once __DIR__ . '/../Kernel/BlockAttributeReader.php';
require_once __DIR__ . '/../Kernel/JsonRefs.php';
require_once __DIR__ . '/../Kernel/IdentityTokenCodec.php';
require_once __DIR__ . '/../Kernel/MediaDerivativeRecipe.php';
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(Policy::class, false)) require_once __DIR__ . '/../Policy/Policy.php';

/** Compile content-selected image work; no native plugin cache or target filesystem is an input. */
final class RepositoryMediaDerivatives {
    public static function derive(array $tree, Policy $policy): array {
        return self::snapshot($tree, $policy)['recipes'];
    }

    /** Native plan observation also needs exact original identities for content-only work. */
    public static function snapshot(array $tree, Policy $policy): array {
        $registry = BlockMediaDerivativeGrammar::project($policy->manifests);
        if ($registry === []) return ['recipes' => [], 'attachments' => []];
        $tree = self::typed_tree($tree);
        $rows = [];
        $occurrences = 0;
        $widgets = $policy->widget_types();
        foreach ($tree as $consumer => $entity) {
            $bodies = [];
            if (($entity['type'] ?? '') === 'post' && $policy->body_mode((string) ($entity['data']['type'] ?? '')) === 'blocks') {
                $bodies[] = (string) ($entity['body'] ?? '');
            }
            if (($entity['type'] ?? '') === 'sidebar') {
                foreach ($entity['data']['widgets'] ?? [] as $widget) {
                    foreach ($widget['settings'] ?? [] as $setting => $value) {
                        if (($widgets[$widget['type']]['settings'][$setting]['codec'] ?? null) === 'blocks') {
                            if (!is_string($value)) throw new \RuntimeException('wprism: media derivative widget block content is not a string');
                            $bodies[] = $value;
                        }
                    }
                }
            }
            foreach ($bodies as $body) {
                foreach (BlockAttributeReader::read($body, array_keys($registry)) as $block) {
                    foreach ($registry[$block['blockName']] as $rule) {
                        $visit = static function (mixed $context) use (&$rows, &$occurrences, $consumer, $rule, $tree): void {
                            $contexts = is_array($context) && array_is_list($context) ? $context : [$context];
                            foreach ($contexts as $one) {
                                if (++$occurrences > 100000 || !is_array($one) || ($one !== [] && array_is_list($one))) {
                                    throw new \RuntimeException('wprism: media derivative selection requires bounded object contexts');
                                }
                                if (isset($rule['when']) && ($one[$rule['when']['key']] ?? null) !== $rule['when']['equals']) continue;
                                $row = self::select($one, $rule, $tree, (string) $consumer);
                                if ($row === null) continue;
                                $key = $row['attachment_uuid'] . ':' . $row['target_path'];
                                if (isset($rows[$key])) {
                                    if ($rows[$key]['recipe_id'] !== $row['recipe_id']) {
                                        throw new \RuntimeException('wprism: selected media derivatives disagree on one destination transform');
                                    }
                                    if (!in_array($consumer, $rows[$key]['consumers'], true)) {
                                        if (count($rows[$key]['consumers']) >= MediaDerivativeRecipe::MAX_CONSUMERS) throw new \RuntimeException('wprism: media derivative consumer roster exceeds its aggregate bound');
                                        $rows[$key]['consumers'][] = (string) $consumer;
                                    }
                                } else {
                                    if (count($rows) >= MediaDerivativeRecipe::MAX_RECIPES) throw new \RuntimeException('wprism: selected media derivatives exceed their aggregate bound');
                                    $rows[$key] = $row;
                                }
                            }
                        };
                        if (!isset($rule['path'])) $visit($block['attrs']);
                        else {
                            $attributes = $block['attrs'];
                            JsonRefs::walk($attributes, JsonRefs::parse_path($rule['path']),
                                static function (&$container, $key) use ($visit): void { $visit($container[$key]); }, 'attrs');
                        }
                    }
                }
            }
        }
        ksort($rows, SORT_STRING);
        foreach ($rows as &$row) sort($row['consumers'], SORT_STRING);
        unset($row);
        $rows = array_values($rows);
        MediaDerivativeRecipe::assert_inventory($rows, $tree);
        $attachments = [];
        foreach ($tree as $uuid => $entity) {
            if (($entity['type'] ?? '') === 'post' && ($entity['data']['type'] ?? '') === 'attachment') {
                $attachments[$uuid] = array_intersect_key($entity['data'], array_flip(['uuid', 'file', 'media', 'mime']));
            }
        }
        return ['recipes' => $rows, 'attachments' => $attachments];
    }

    private static function select(array $context, array $rule, array $tree, string $consumer): ?array {
        $token = self::field($context, $rule['attachment']);
        $url = self::field($context, $rule['url']);
        if (in_array($token, [null, '', 0, '0'], true) && in_array($url, [null, ''], true)) return null;
        try {
            if (!is_string($token)) throw new \RuntimeException();
            $identity = IdentityTokenCodec::decode($token);
            if ($identity['kind'] !== 'post' || IdentityTokenCodec::encode('post', $identity['uuid']) !== $token) throw new \RuntimeException();
        } catch (\RuntimeException) {
            throw new \RuntimeException('wprism: selected media derivative requires a canonical attachment reference');
        }
        $entity = $tree[$identity['uuid']] ?? [];
        $front = $entity['data'] ?? [];
        if (($entity['type'] ?? null) !== 'post' || ($front['type'] ?? null) !== 'attachment'
            || !is_string($front['file'] ?? null) || !is_string($front['media'] ?? null)) {
            throw new \RuntimeException('wprism: selected media derivative attachment is absent from the immutable tree');
        }
        // Selecting Custom before entering dimensions still displays the original.
        // File work follows the saved selected URL, not an incomplete control draft.
        if ($url === '{{uploads}}/' . $front['file']) return null;
        $width = MediaDerivativeRecipe::dimension(self::field($context, $rule['width']), $rule['dimension_cast']);
        $height = MediaDerivativeRecipe::dimension(self::field($context, $rule['height']), $rule['dimension_cast']);
        $target = MediaDerivativeRecipe::target_path($front['file'], $width, $height);
        if ($url !== '{{uploads}}/' . $target) {
            throw new \RuntimeException('wprism: selected derivative URL disagrees with its declared attachment and requested dimensions');
        }
        $row = ['attachment_uuid' => $identity['uuid'], 'consumers' => [$consumer], 'crop' => $rule['crop'],
            'height' => $height, 'media_blob' => $front['media'], 'original_path' => $front['file'],
            'target_path' => $target, 'width' => $width];
        $row['recipe_id'] = MediaDerivativeRecipe::identity($row);
        ksort($row, SORT_STRING);
        return $row;
    }

    private static function field(array $context, string $path): mixed {
        $value = $context;
        foreach (BlockMediaDerivativeGrammar::field_path($path) as $segment) {
            if (!is_array($value) || ($value !== [] && array_is_list($value)) || !array_key_exists($segment['key'], $value)) return null;
            $value = $value[$segment['key']];
        }
        return $value;
    }

    private static function typed_tree(array $tree): array {
        $list = array_is_list($tree);
        $typed = [];
        foreach ($tree as $key => $entity) {
            if (!is_array($entity)) throw new \RuntimeException('wprism: media derivative tree contains a malformed entity');
            $uuid = $list ? ($entity['uuid'] ?? null) : $key;
            if (!is_string($uuid) || $uuid === '' || isset($typed[$uuid])
                || (isset($entity['uuid']) && $entity['uuid'] !== $uuid)) {
                throw new \RuntimeException('wprism: media derivative tree has a missing, duplicated or contradictory identity');
            }
            if (!isset($entity['data'])) {
                if (!is_string($entity['content'] ?? null)) throw new \RuntimeException('wprism: media derivative consumer lacks canonical content');
                if (($entity['type'] ?? '') === 'post') [$entity['data'], $entity['body']] = Canon::parse_post_file($entity['content']);
                else $entity['data'] = Canon::decode($entity['content']);
            }
            if (!is_array($entity['data'])) throw new \RuntimeException('wprism: media derivative entity data is malformed');
            if (($entity['type'] ?? '') === 'post' && ($entity['data']['uuid'] ?? null) !== $uuid) {
                throw new \RuntimeException('wprism: media derivative post identity disagrees with its canonical content');
            }
            // CaptureCandidateBuilder validates its entity list before the
            // snapshot service keys records by UUID. Never treat list offsets
            // as attachment identities or overwrite a duplicate native row.
            $typed[$uuid] = $entity;
        }
        return $typed;
    }
}
