<?php
declare(strict_types=1);

namespace Duo\Tests\Support;

/**
 * The exact-version downgrade mutates two fixture products through native Woo
 * saves on different hosts. Their manifest-declared derived modification
 * clocks may differ; every path, field, formatting byte, and body byte remains
 * part of the equality proof.
 */
function assert_woocommerce_downgrade_recapture(string $sourceRoot, string $targetRoot): void {
    $source = woocommerce_downgrade_tree($sourceRoot);
    $target = woocommerce_downgrade_tree($targetRoot);
    if (array_keys($source) !== array_keys($target)) {
        throw new \RuntimeException('source and target tree inventories differ');
    }

    $expected = [];
    foreach (['conformance-widget', 'conformance-precision-download'] as $slug) {
        $matches = array_values(array_filter(
            array_keys($source),
            static fn(string $path): bool => str_starts_with($path, 'posts/product/')
                && str_ends_with($path, "--$slug.md")
        ));
        if (count($matches) !== 1 || $source[$matches[0]]['type'] !== 'file') {
            throw new \RuntimeException("expected exactly one product fixture path for $slug");
        }
        $expected[] = $matches[0];
    }
    sort($expected, SORT_STRING);

    foreach ($source as $relative => $sourceEntry) {
        $targetEntry = $target[$relative];
        if ($sourceEntry['type'] !== $targetEntry['type']) {
            throw new \RuntimeException("tree entry type differs at $relative");
        }
        if ($sourceEntry['type'] === 'dir') {
            continue;
        }
        $sourceBytes = file_get_contents($sourceEntry['path']);
        $targetBytes = file_get_contents($targetEntry['path']);
        if (!is_string($sourceBytes) || !is_string($targetBytes)) {
            throw new \RuntimeException("could not read recaptured bytes at $relative");
        }
        if (hash_equals(hash('sha256', $sourceBytes), hash('sha256', $targetBytes))) {
            continue;
        }
        if (!in_array($relative, $expected, true)) {
            throw new \RuntimeException("unexpected recapture difference at $relative");
        }
        woocommerce_downgrade_assert_timestamp_only($relative, $sourceBytes, $targetBytes);
    }
}

/** @return array<string,array{type:'dir'|'file',path:string}> */
function woocommerce_downgrade_tree(string $root): array {
    $realRoot = realpath($root);
    if (!is_string($realRoot) || !is_dir($realRoot)) {
        throw new \RuntimeException('recapture tree root is not a directory');
    }
    $rows = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new \RuntimeException('recapture tree contains a symbolic link');
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($realRoot) + 1));
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new \RuntimeException('recapture tree contains an invalid relative path');
        }
        if ($entry->isDir()) {
            $type = 'dir';
        } elseif ($entry->isFile()) {
            $type = 'file';
        } else {
            throw new \RuntimeException("recapture tree contains an unsupported entry at $relative");
        }
        $rows[$relative] = ['type' => $type, 'path' => $entry->getPathname()];
    }
    ksort($rows, SORT_STRING);
    return $rows;
}

function woocommerce_downgrade_assert_timestamp_only(
    string $relative,
    string $sourceBytes,
    string $targetBytes
): void {
    $source = woocommerce_downgrade_timestamp_projection($relative, $sourceBytes);
    $target = woocommerce_downgrade_timestamp_projection($relative, $targetBytes);
    if (!hash_equals($source['normalized'], $target['normalized'])) {
        throw new \RuntimeException("recapture differs outside derived product timestamps at $relative");
    }
}

/** @return array{normalized:string,values:array{modified:string,modified_gmt:string}> */
function woocommerce_downgrade_timestamp_projection(string $relative, string $bytes): array {
    $pattern = '/^    "(modified|modified_gmt)": "([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})",$/m';
    preg_match_all($pattern, $bytes, $matches, PREG_SET_ORDER);
    $values = [];
    foreach ($matches as $match) {
        $field = (string) $match[1];
        $value = (string) $match[2];
        if (isset($values[$field]) || !woocommerce_downgrade_valid_datetime($value)) {
            throw new \RuntimeException("derived timestamp shape is invalid at $relative");
        }
        $values[$field] = $value;
    }
    if (array_keys($values) !== ['modified', 'modified_gmt']) {
        throw new \RuntimeException("both exact derived timestamp fields are required at $relative");
    }
    $normalized = preg_replace_callback(
        $pattern,
        static fn(array $match): string => '    "' . $match[1] . '": "__DUO_DERIVED_WOO_TIMESTAMP__",',
        $bytes,
        -1,
        $replacements
    );
    if (!is_string($normalized) || $replacements !== 2) {
        throw new \RuntimeException("derived timestamp normalization failed at $relative");
    }
    /** @var array{modified:string,modified_gmt:string} $values */
    return ['normalized' => $normalized, 'values' => $values];
}

function woocommerce_downgrade_valid_datetime(string $value): bool {
    $parsed = \DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new \DateTimeZone('UTC')
    );
    $errors = \DateTimeImmutable::getLastErrors();
    return $parsed instanceof \DateTimeImmutable
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d H:i:s') === $value;
}

if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if ($argc !== 3) {
            throw new \RuntimeException('expected source and target recapture roots');
        }
        assert_woocommerce_downgrade_recapture((string) $argv[1], (string) $argv[2]);
    } catch (\Throwable $failure) {
        fwrite(STDERR, 'WooCommerce downgrade recapture comparator: ' . $failure->getMessage() . "\n");
        exit(1);
    }
}
