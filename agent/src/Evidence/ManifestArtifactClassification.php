<?php
declare(strict_types=1);

namespace Duo;

require_once dirname(__DIR__) . '/Canon.php';

/** Validator and lookup facade for Thread 4's manifest artifact partition. */
final class ManifestArtifactClassification {
    public const FORMAT = 'duo-manifest-source-to-artifact/v1';
    private const ROOTS = ['payload/declarations', 'payload/runtime', 'overlay/review', 'overlay/projection'];
    private const CLASSES = ['declaration', 'runtime_dependency', 'review_input', 'generated_projection'];

    /** @return array<string,mixed> */
    public static function load(string $path, ?array $trackedPaths = null): array {
        $data = Canon::decode(Canon::read_file($path));
        self::assertValid($data, $trackedPaths);
        return $data;
    }

    /** @param array<string,mixed> $data @param list<string>|null $trackedPaths */
    public static function assertValid(array $data, ?array $trackedPaths = null): void {
        if (($data['format'] ?? null) !== self::FORMAT
            || ($data['owner'] ?? null) !== 'thread-4'
            || ($data['authority_path'] ?? null) !== 'manifests/capabilities/source-to-artifact.json'
            || !is_string($data['authority'] ?? null) || trim($data['authority']) === ''
            || !is_array($data['roots'] ?? null) || array_is_list($data['roots'])
            || !is_array($data['entries'] ?? null) || !array_is_list($data['entries'])
            || count($data['entries']) === 0) {
            throw new \RuntimeException('duo evidence: manifest artifact classification is malformed');
        }
        foreach (self::ROOTS as $root) {
            if (!is_string($data['roots'][$root] ?? null) || trim($data['roots'][$root]) === '') {
                throw new \RuntimeException("duo evidence: manifest artifact root '$root' is undocumented");
            }
        }
        $seen = [];
        foreach ($data['entries'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)
                || !is_string($entry['path'] ?? null)
                || !is_string($entry['artifact_class'] ?? null)
                || !is_string($entry['artifact_root'] ?? null)
                || !in_array($entry['artifact_class'], self::CLASSES, true)
                || !in_array($entry['artifact_root'], self::ROOTS, true)
                || !self::safePath($entry['path'])
                || isset($seen[$entry['path']])) {
                throw new \RuntimeException('duo evidence: manifest artifact classification has a malformed or duplicate entry');
            }
            $expectedRoot = match ($entry['artifact_class']) {
                'declaration' => 'payload/declarations',
                'runtime_dependency' => 'payload/runtime',
                'review_input' => 'overlay/review',
                'generated_projection' => 'overlay/projection',
            };
            if ($entry['artifact_root'] !== $expectedRoot) {
                throw new \RuntimeException("duo evidence: manifest artifact class/root disagree for {$entry['path']}");
            }
            $seen[$entry['path']] = true;
        }
        $listed = array_keys($seen);
        sort($listed, SORT_STRING);
        $entries = array_map(static fn(array $entry): string => $entry['path'], $data['entries']);
        if ($entries !== $listed) {
            throw new \RuntimeException('duo evidence: manifest artifact entries are not in canonical order');
        }
        if ($trackedPaths !== null) {
            $expected = [];
            foreach ($trackedPaths as $path) {
                if (is_string($path) && str_starts_with($path, 'manifests/')
                    && $path !== $data['authority_path']) {
                    $expected[] = $path;
                }
            }
            sort($expected, SORT_STRING);
            if ($expected !== $listed) {
                throw new \RuntimeException('duo evidence: manifest artifact classification does not cover exactly the tracked manifest files');
            }
        }
    }

    /** @return array{artifact_class:string,artifact_root:string}|null */
    public static function classify(array $data, string $path): ?array {
        self::assertValid($data);
        foreach ($data['entries'] as $entry) {
            if ($entry['path'] === $path) {
                return [
                    'artifact_class' => $entry['artifact_class'],
                    'artifact_root' => $entry['artifact_root'],
                ];
            }
        }
        return null;
    }

    private static function safePath(string $path): bool {
        return $path !== '' && str_starts_with($path, 'manifests/')
            && !str_starts_with($path, '/') && !str_contains($path, "\0")
            && !str_contains($path, '\\') && !str_contains($path, '//')
            && !in_array('.', explode('/', $path), true)
            && !in_array('..', explode('/', $path), true);
    }
}
