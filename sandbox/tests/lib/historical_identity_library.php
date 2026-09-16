<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../agent/src/Policy/AdapterLibrary.php';
require_once __DIR__ . '/ShellProbe.php';

use WPrism\{AdapterLibrary, Canon};
use WPrismTest\ShellProbe;

/** Historical identity assertions require historical input bytes, not today's capsule inventory. */
final class WPrismHistoricalIdentityLibrary {
    private const INDEX_SHA256 = '6d9876c07bd732d7fd98ada3fa97352703c6017456c745d83821db3572a820d8';
    private const ARCHIVE_SHA256 = '066cee07a7fe5c619d137352c714418e1ff1cd1a6d534bf1f4f8e3e9819ba9e7';

    public static function materialize(): AdapterLibrary {
        $repo = dirname(__DIR__, 3);
        $fixture = $repo . '/sandbox/tests/fixtures/spec-v3/historical-identity';
        $index = $fixture . '/files.json';
        $archive = $fixture . '/library.tar.gz';
        if (hash_file('sha256', $index) !== self::INDEX_SHA256
            || hash_file('sha256', $archive) !== self::ARCHIVE_SHA256) {
            throw new RuntimeException('historical identity input fixture differs from its retained source bytes');
        }
        $record = Canon::decode(Canon::read_file($index));
        if (($record['format'] ?? null) !== 'wprism-historical-identity-library/v1'
            || ($record['archive_sha256'] ?? null) !== self::ARCHIVE_SHA256) {
            throw new RuntimeException('historical identity input fixture has an invalid declaration');
        }
        // Fresh source checkouts have no ignored sandbox/tmp. Extraction owns
        // its private system-temp root independently of repository setup.
        $scratch = sys_get_temp_dir() . '/wprism-historical-identity-' . bin2hex(random_bytes(12));
        if (!mkdir($scratch, 0700)) throw new RuntimeException('cannot allocate historical identity input root');
        register_shutdown_function(static fn() => self::remove($scratch));
        // The archive is byte-pinned before tar sees it. Its closed per-file
        // index is verified after extraction; no live package byte is copied.
        [$status, $stdout, $stderr] = ShellProbe::run('exec tar -xzf "$1" -C "$2"', [$archive, $scratch], $repo);
        if ($status !== 0 || $stdout !== '' || $stderr !== '') {
            throw new RuntimeException('cannot materialize historical identity input archive');
        }
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isLink() || !$entry->isFile()) throw new RuntimeException('historical identity input contains a non-file member');
            $path = substr($entry->getPathname(), strlen($scratch) + 1);
            $actual[$path] = ['bytes' => $entry->getSize(), 'sha256' => hash_file('sha256', $entry->getPathname())];
        }
        ksort($actual, SORT_STRING);
        if ($actual !== $record['files']) throw new RuntimeException('historical identity input differs from its complete file index');
        $library = AdapterLibrary::fromSourceTree($scratch);
        $subjects = array_map(static fn(WPrism\AdapterPackage $package): string => $package->name(), $library->packages());
        sort($subjects, SORT_STRING);
        if ($subjects !== $record['subjects']) throw new RuntimeException('historical identity input subject roster differs');
        return $library;
    }

    private static function remove(string $path): void {
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) self::remove($entry->getPathname());
        rmdir($path);
    }
}
