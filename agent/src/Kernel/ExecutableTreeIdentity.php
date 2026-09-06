<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/Canon.php';
require_once __DIR__ . '/FilesystemTreeSnapshot.php';

/** Stable executable-owner identity projected from the generic tree observer. */
final class ExecutableTreeIdentity {
    public const FORMAT = 'wprism-executable-tree/v1';

    /** @return array{format:string,root:string,sha256:string} */
    public static function observe(
        string $contentRoot,
        string $absoluteRoot,
        string $canonicalRoot
    ): array {
        $snapshot = FilesystemTreeSnapshot::observe(
            $contentRoot,
            $absoluteRoot,
            $canonicalRoot,
            'executable owner',
            'WP_CONTENT_DIR'
        );
        $rows = array_map(static fn(array $row): array => [
            'path' => $row['path'],
            'sha256' => $row['sha256'],
        ], $snapshot['files']);
        $payload = [
            'files' => $rows,
            'format' => self::FORMAT,
            'root' => $canonicalRoot,
        ];
        return [
            'format' => self::FORMAT,
            'root' => $canonicalRoot,
            'sha256' => hash('sha256', Canon::encode($payload)),
        ];
    }
}
