<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/Canon.php';
}

require_once __DIR__ . '/MediaPayloadAuthority.php';

/** Target-independent image work, bound to one original and exact content consumers. */
final class MediaDerivativeRecipe {
    public const MAX_RECIPES = 4096;
    public const MAX_PER_ATTACHMENT = 128;
    public const MAX_CONSUMERS = 4096;
    public const MAX_DIMENSION = 16384;
    public const MAX_PIXELS = 67108864;

    public static function dimension(mixed $value, string $cast): int {
        if ((!is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))
            || $value < 0 || $value > self::MAX_DIMENSION || ($cast === 'integer' && !is_int($value))) {
            throw new \RuntimeException('wprism: media derivative dimensions must be bounded nonnegative numbers in their declared type');
        }
        return (int) $value;
    }

    public static function target_path(string $original, int $width, int $height): string {
        $directory = dirname($original);
        $name = pathinfo(basename($original), PATHINFO_FILENAME);
        $extension = pathinfo($original, PATHINFO_EXTENSION);
        MediaPayloadAuthority::assertRelativeUploadPath($original);
        if ($extension === '' || $name === '') throw new \RuntimeException('wprism: media derivative original has no image extension');
        $target = ($directory === '.' ? '' : $directory . '/') . $name . '-' . $width . 'x' . $height . '.' . $extension;
        if (strlen(basename($target)) > 255 || strlen($target) > 1024) {
            throw new \RuntimeException('wprism: media derivative target path exceeds its filesystem bound');
        }
        return $target;
    }

    public static function identity(array $row): string {
        unset($row['recipe_id'], $row['consumers']);
        return hash('sha256', Canon::encode($row));
    }

    public static function assert_inventory(array $rows, array $tree): void {
        if (!array_is_list($rows) || count($rows) > self::MAX_RECIPES) {
            throw new \RuntimeException('wprism: media derivative inventory is malformed or oversized');
        }
        if ($rows === []) return;
        $originals = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') === 'post' && ($entity['data']['type'] ?? '') === 'attachment') {
                $originals[strtolower((string) ($entity['data']['file'] ?? ''))] = true;
            }
        }
        $prior = null;
        $counts = [];
        $targets = [];
        foreach ($rows as $row) {
            $keys = is_array($row) ? array_keys($row) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['attachment_uuid', 'consumers', 'crop', 'height', 'media_blob', 'original_path', 'recipe_id', 'target_path', 'width']
                || !is_string($row['attachment_uuid']) || !is_string($row['media_blob']) || !is_string($row['original_path'])
                || !is_string($row['recipe_id']) || !is_string($row['target_path']) || !is_bool($row['crop'])
                || !is_array($row['consumers']) || !array_is_list($row['consumers']) || $row['consumers'] === []
                || count($row['consumers']) > self::MAX_CONSUMERS) {
                throw new \RuntimeException('wprism: media derivative row requires its exact typed recipe and consumers');
            }
            $width = self::dimension($row['width'], 'integer');
            $height = self::dimension($row['height'], 'integer');
            if ($width * $height > self::MAX_PIXELS) throw new \RuntimeException('wprism: media derivative exceeds its requested pixel bound');
            $entity = $tree[$row['attachment_uuid']] ?? [];
            $front = $entity['data'] ?? [];
            if (($entity['type'] ?? null) !== 'post' || ($front['type'] ?? null) !== 'attachment'
                || ($front['file'] ?? null) !== $row['original_path'] || ($front['media'] ?? null) !== $row['media_blob']
                || !in_array($front['mime'] ?? null, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
                || $row['target_path'] !== self::target_path($row['original_path'], $width, $height)
                || $row['recipe_id'] !== self::identity($row)) {
                throw new \RuntimeException('wprism: media derivative does not bind its immutable original and requested transform');
            }
            $consumerBefore = null;
            foreach ($row['consumers'] as $consumer) {
                if (!is_string($consumer) || !isset($tree[$consumer])
                    || ($consumerBefore !== null && strcmp($consumerBefore, $consumer) >= 0)) {
                    throw new \RuntimeException('wprism: media derivative consumer roster is missing, duplicated or unsorted');
                }
                $consumerBefore = $consumer;
            }
            $sortKey = $row['attachment_uuid'] . ':' . $row['target_path'];
            $portableTarget = strtolower($row['target_path']);
            if (($prior !== null && strcmp($prior, $sortKey) >= 0) || isset($targets[$portableTarget])
                || ($counts[$row['attachment_uuid']] ?? 0) >= self::MAX_PER_ATTACHMENT) {
                throw new \RuntimeException('wprism: media derivative inventory is duplicated, conflicting, unsorted or oversized');
            }
            if (isset($originals[$portableTarget])) {
                throw new \RuntimeException('wprism: media derivative destination collides with an authored original');
            }
            $counts[$row['attachment_uuid']] = ($counts[$row['attachment_uuid']] ?? 0) + 1;
            $targets[$portableTarget] = true;
            $prior = $sortKey;
        }
    }
}
