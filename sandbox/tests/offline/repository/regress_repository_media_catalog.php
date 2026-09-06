<?php
/**
 * Offline regression for RepositoryMediaCatalog (issue #3348 slice 31).
 *
 * The catalog is deliberately a compiler-independent filesystem boundary:
 * it validates attachment references and the immutable media/ partition,
 * then sends findings to the compiler-owned aggregate diagnostic sink. This
 * directly characterizes the extracted behavior while regress_repository_
 * compiler.sh continues to exercise the full offline compiler workflow.
 */
declare(strict_types=1);

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryMediaCatalog.php';

use WPrism\RepositoryMediaCatalog;
use WPrism\MediaPayloadAuthority;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$tmp = sys_get_temp_dir() . '/wprism-repository-media-catalog-' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
    throw new RuntimeException("could not create $tmp");
}
register_shutdown_function(static function () use ($tmp): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    @rmdir($tmp);
});

$check(
    class_exists(\WPrism\Canon::class, false) && !class_exists(\WPrism\RepositoryCompiler::class, false),
    'RepositoryMediaCatalog directly loads only its canonical filesystem dependency, not RepositoryCompiler'
);

/** @param array<int,array<string,string|null>> $diagnostics */
$catalog = static function (string $mediaDir, array &$diagnostics): RepositoryMediaCatalog {
    $diagnostics = [];
    return new RepositoryMediaCatalog(
        $mediaDir,
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
};

$put = static function (string $path, string $bytes): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        throw new RuntimeException("could not create $parent");
    }
    file_put_contents($path, $bytes);
};

$attachment = static function (string $file, string $media, string $mime = 'text/plain'): array {
    return [
        'type' => 'attachment',
        'file' => $file,
        'media' => $media,
        'mime' => $mime,
        'alt' => 'Portable attachment',
    ];
};

$codes = static fn(array $diagnostics): array => array_column($diagnostics, 'code');

$validMedia = "$tmp/valid-media";
$referencedBytes = "referenced media\n";
$referencedHash = hash('sha256', $referencedBytes);
$orphanBytes = "safe orphan media\n";
$orphanHash = hash('sha256', $orphanBytes);
$put("$validMedia/$referencedHash.txt", $referencedBytes);
$put("$validMedia/$orphanHash.bin", $orphanBytes);
$validDiagnostics = [];
$valid = $catalog($validMedia, $validDiagnostics);
$valid->validate_attachment('state/posts/attachment/a--photo.md', $attachment('2026/08/photo.txt', "$referencedHash.txt"));
$valid->catalog_directory();
$check($validDiagnostics === [], 'a safe referenced blob and a safe orphan catalog without diagnostics');
$check(
    $valid->referenced_media() === [
        "$referencedHash.txt" => ['sha256' => $referencedHash, 'base64' => base64_encode($referencedBytes)],
    ],
    'referenced_media() returns the exact canonical blob payload'
);
$expectedCatalog = ["$orphanHash.bin" => $orphanHash, "$referencedHash.txt" => $referencedHash];
ksort($expectedCatalog, SORT_STRING);
$check(
    $valid->catalog() === $expectedCatalog,
    'catalog() returns every safe content-addressed blob in deterministic lexical order'
);

$originalCwd = getcwd();
try {
    chdir($tmp);
    $relativeDiagnostics = [];
    $relative = $catalog('valid-media', $relativeDiagnostics);
    chdir($originalCwd);
    $relative->validate_attachment('state/posts/attachment/a--photo.md', $attachment('2026/08/photo.txt', "$referencedHash.txt"));
    $relative->catalog_directory();
    $check($relativeDiagnostics === [] && $relative->referenced_media() === $valid->referenced_media()
        && $relative->catalog() === $expectedCatalog,
        'relative media root binds its construction-time working directory before any later cwd change');
} finally {
    chdir($originalCwd);
}

$sharedUsageMedia = "$tmp/shared-usage-media";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgAAIAAAUAAWJVMogAAAAASUVORK5CYII=', true);
if (!is_string($png)) {
    throw new RuntimeException('test PNG fixture did not decode');
}
$pngHash = hash('sha256', $png);
$pngName = "$pngHash.png";
$put("$sharedUsageMedia/$pngName", $png);
$sharedUsageDiagnostics = [];
$sharedUsage = $catalog($sharedUsageMedia, $sharedUsageDiagnostics);
$sharedUsage->validate_attachment(
    'state/posts/attachment/shared-raster.md',
    $attachment('images/raster.png', $pngName, 'image/png')
);
$sharedUsage->validate_attachment(
    'state/posts/attachment/shared-generic.md',
    $attachment('files/opaque.png', $pngName, 'application/vnd.ms-excel.sheet.macroEnabled.12')
);
$sharedUsage->catalog_directory();
$check(
    $sharedUsageDiagnostics === []
        && $sharedUsage->referenced_media() === [
            $pngName => ['sha256' => $pngHash, 'base64' => base64_encode($png)],
        ],
    'catalog deduplicates one immutable blob while every attachment retains its own Core MIME branch classification'
);

foreach ([
    ['application/pdf', 'pdf'],
    ['audio/mpeg', 'mp3'],
    ['image/svg+xml', 'svg'],
    ['video/mp4', 'mp4'],
] as [$mime, $extension]) {
    $check(
        MediaPayloadAuthority::kindFor($mime, $extension) === 'generic',
        "$mime originals take the closed filesize-only metadata branch"
    );
}

$externalMedia = "$tmp/external-media";
$externalBytes = str_repeat('large-media-segment-', 450000);
$externalHash = hash('sha256', $externalBytes);
$externalName = "$externalHash.mp4";
$put("$externalMedia/$externalName", $externalBytes);
$externalDiagnostics = [];
$external = $catalog($externalMedia, $externalDiagnostics);
$external->validate_attachment(
    'state/posts/attachment/launch-video.md',
    $attachment('2026/08/launch-video.mp4', $externalName, 'video/mp4')
);
$external->catalog_directory();
$check(
    $externalDiagnostics === [] && $external->referenced_media() === [
        $externalName => [
            'sha256' => $externalHash,
            'size' => strlen($externalBytes),
            'source' => 'repository',
        ],
    ],
    'compiler media catalog externalizes a large content-addressed original instead of base64-embedding it'
);
unset($externalBytes);

$largeCatalogMedia = "$tmp/large-catalog-media";
$largeCatalogDiagnostics = [];
for ($index = 0; $index < 4097; $index++) {
    $bytes = "catalog-$index";
    $put($largeCatalogMedia . '/' . hash('sha256', $bytes) . '.LegacyExtensionMoreThanSixteen', $bytes);
}
$largeCatalog = $catalog($largeCatalogMedia, $largeCatalogDiagnostics);
$largeCatalog->catalog_directory();
$check(
    $largeCatalogDiagnostics === [] && count($largeCatalog->catalog()) === 4097,
    'a current-scale catalog above 4096 bounded names is admitted by dynamic map headroom rather than a small file-count ceiling'
);

$unsafeDiagnostics = [];
$unsafe = $catalog($validMedia, $unsafeDiagnostics);
$unsafe->validate_attachment('state/posts/attachment/b--unsafe.md', $attachment('../escape.txt', "$referencedHash.txt"));
$check(
    in_array('unsafe_media_path', $codes($unsafeDiagnostics), true),
    'an unsafe upload-relative path is refused without consulting a target upload directory'
);

$unsafeCatalogMedia = "$tmp/unsafe-catalog-media";
mkdir("$unsafeCatalogMedia/nested", 0777, true);
$unsafeCatalogDiagnostics = [];
$unsafeCatalog = $catalog($unsafeCatalogMedia, $unsafeCatalogDiagnostics);
$unsafeCatalog->catalog_directory();
$check(
    in_array('unsafe_media_path', $codes($unsafeCatalogDiagnostics), true),
    'a nested media entry is refused because the immutable media partition permits only direct regular blobs'
);

$mismatchMedia = "$tmp/mismatch-media";
$mismatchBytes = "different bytes\n";
$declaredHash = str_repeat('a', 64);
$put("$mismatchMedia/$declaredHash.txt", $mismatchBytes);
$mismatchDiagnostics = [];
$mismatch = $catalog($mismatchMedia, $mismatchDiagnostics);
$mismatch->validate_attachment('state/posts/attachment/c--mismatch.md', $attachment('mismatch.txt', "$declaredHash.txt"));
$mismatch->catalog_directory();
$check(
    in_array('media_hash_mismatch', $codes($mismatchDiagnostics), true),
    'a blob whose content disagrees with its content-addressed filename is refused'
);

$duplicateDiagnostics = [];
$duplicates = $catalog($validMedia, $duplicateDiagnostics);
$duplicates->validate_attachment('state/posts/attachment/d--one.md', $attachment('photo.txt', "$referencedHash.txt"));
$duplicates->validate_attachment('state/posts/attachment/e--two.md', $attachment('photo.txt', "$referencedHash.txt"));
$check(
    in_array('duplicate_upload_path', $codes($duplicateDiagnostics), true),
    'two attachment documents cannot claim one mutable upload path'
);

$derivativeDiagnostics = [];
$derivatives = $catalog($validMedia, $derivativeDiagnostics);
$derivatives->validate_attachment('state/posts/attachment/f--one.md', $attachment('images/photo.jpg', "$referencedHash.txt"));
$derivatives->validate_attachment('state/posts/attachment/g--two.md', $attachment('images/photo.png', "$referencedHash.txt"));
$check(
    in_array('duplicate_media_derivative_root', $codes($derivativeDiagnostics), true),
    'two attachment documents cannot claim the same bounded derivative root'
);

$compilerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php');
$entityParserSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryEntityParser.php');
$check(
    substr_count($compilerSource, 'new RepositoryMediaCatalog(') === 1
        && substr_count($compilerSource, '->catalog_directory()') === 1
        && substr_count($entityParserSource, '->mediaCatalog->validate_attachment($path, $data)') === 1
        && !str_contains($compilerSource, 'private function validate_attachment(')
        && !str_contains($compilerSource, 'private function catalog_media_directory('),
    'RepositoryEntityParser owns attachment references while RepositoryCompiler retains complete media cataloguing without duplicate private implementations'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}

echo "\nALL PASSED\n";
