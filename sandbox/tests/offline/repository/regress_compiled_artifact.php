<?php
/**
 * Offline regression for CompiledArtifact.php (DUO-3348 slice 2: the
 * CompiledRepository/RepositoryCompilationException value objects, moved out
 * of RepositoryCompiler.php into their own file). Pure file relocation, no
 * logic change — existing suites (regress_repository_compiler.sh,
 * regress_plan_explain.php, regress_capture_atomicity.php, etc.) already
 * exercise these classes in depth through the real compiler; this file is
 * deliberately narrow: it proves agent/src/Repository/CompiledArtifact.php is
 * independently loadable (no WordPress/DB/docker) and that the basic
 * create/export/write/from_array round-trip and hash-verification refusals
 * still work when required standalone, without the rest of RepositoryCompiler.
 */
declare(strict_types=1);

// CompiledArtifact.php deliberately does not require Canon.php itself (see its
// own docblock) -- callers that need Canon::encode()/write_file() to actually
// run (as this test's create()/export()/write() calls do) must require it
// themselves.
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Repository/CompiledArtifact.php';

use Duo\CompiledRepository;
use Duo\RepositoryCompilationException;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$payload = [
    'tree' => ['u1' => ['type' => 'post', 'data' => ['type' => 'page']]],
    'deletions' => [],
    'revision_hash' => str_repeat('a', 64),
    'manifest_hash' => str_repeat('b', 64),
    'site_hash' => str_repeat('c', 64),
];

$compiled = CompiledRepository::create($payload);
$check($compiled->tree() === $payload['tree'], 'create(): tree is preserved verbatim');
$check($compiled->deletions() === [], 'create(): empty deletions default');
$check(preg_match('/^[0-9a-f]{64}$/', $compiled->artifact_hash()) === 1, 'create(): artifact_hash is a sha256 hex digest');
$check($compiled->code_descriptor() === null, 'create(): no code key means no code descriptor');
$check($compiled->code_revision() === null, 'create(): no code descriptor means no code revision');
$check($compiled->resolved_adapters() === [], 'create(): resolved_adapters defaults to empty');
$check($compiled->uploads_inventory() === [], 'create(): no attachments means empty uploads inventory');
$check($compiled->effects_inventory() === [], 'create(): effects_inventory defaults to empty list');

$mediaBytes = "bounded generic media\n";
$mediaHash = hash('sha256', $mediaBytes);
$mediaName = "$mediaHash.txt";
$mediaPayload = $payload;
$mediaPayload['tree'] = [
    'media-text' => [
        'type' => 'post',
        'data' => ['type' => 'attachment', 'file' => 'uploads/one.txt', 'mime' => 'text/plain', 'media' => $mediaName],
    ],
];
$mediaPayload['media'] = [$mediaName => ['sha256' => $mediaHash, 'base64' => base64_encode($mediaBytes)]];
$mediaCompiled = CompiledRepository::create($mediaPayload);
$check(
    $mediaCompiled->media_content($mediaName) === $mediaBytes
        && $mediaCompiled->media_content($mediaName) === $mediaBytes,
    'media_content(): decodes an exact canonical payload and accounts each immutable blob only once'
);
$mediaTmp = tempnam(sys_get_temp_dir(), 'duo-compiled-artifact-media-');
$mediaCompiled->write($mediaTmp);
$mediaWritten = CompiledRepository::from_array(json_decode(file_get_contents($mediaTmp), true));
$check(
    $mediaWritten->media_content($mediaName) === $mediaBytes,
    'from_array(): accepts canonical JSON key order while re-proving encoded media length, hash, and attachment authority'
);
unlink($mediaTmp);

$badBase64 = $mediaPayload;
$badBase64['media'][$mediaName]['base64'] = 'AA=A';
$badBase64Rejected = false;
try {
    CompiledRepository::create($badBase64);
} catch (\RuntimeException $e) {
    $badBase64Rejected = str_contains($e->getMessage(), 'canonical base64');
}
$check($badBase64Rejected, 'create(): noncanonical encoded media is refused before base64_decode allocation');

// The Juniper Lane WooCommerce rehearsal reached a 95,662-byte product image
// whose 127,552-byte encoded payload made PCRE2 return
// PREG_JIT_STACKLIMIT_ERROR. Use a larger ordinary blob so this regression
// also fails with JIT disabled (the prior repeated-group regex then exhausts
// PCRE's recursion limit) while staying far below the 256 MiB media frontier.
$largeMediaBytes = str_repeat("\0", 786432);
$largeMediaBase64 = base64_encode($largeMediaBytes);
$largeMediaHash = hash('sha256', $largeMediaBytes);
$largeMediaName = "$largeMediaHash.bin";
$largeMediaPayload = $payload;
$largeMediaPayload['tree'] = [
    'marketplace-product-image' => [
        'type' => 'post',
        'data' => [
            'type' => 'attachment',
            'file' => '2026/08/marketplace-product-image.bin',
            'mime' => 'application/octet-stream',
            'media' => $largeMediaName,
        ],
    ],
];
$largeMediaPayload['media'] = [
    $largeMediaName => ['sha256' => $largeMediaHash, 'base64' => $largeMediaBase64],
];
$largeMediaCompiled = CompiledRepository::create($largeMediaPayload);
$largeMediaRoundTrip = CompiledRepository::from_array($largeMediaCompiled->export());
$check(
    strlen($largeMediaBase64) === 1048576
        && $largeMediaRoundTrip->media_content($largeMediaName) === $largeMediaBytes,
    'create()/from_array(): a normal megabyte-scale attachment payload is independent of PCRE engine limits'
);
unset($largeMediaBytes, $largeMediaBase64, $largeMediaPayload, $largeMediaCompiled, $largeMediaRoundTrip);

$externalDirectory = sys_get_temp_dir() . '/duo-external-media-' . bin2hex(random_bytes(6));
mkdir($externalDirectory, 0700);
$externalBytes = str_repeat('large-video-chunk-', 530000);
$externalHash = hash('sha256', $externalBytes);
$externalName = "$externalHash.mp4";
file_put_contents("$externalDirectory/$externalName", $externalBytes);
$externalPayload = $payload;
$externalPayload['tree'] = [
    'large-video' => [
        'type' => 'post',
        'data' => [
            'type' => 'attachment',
            'file' => '2026/08/launch-video.mp4',
            'mime' => 'video/mp4',
            'media' => $externalName,
        ],
    ],
];
$externalPayload['media'] = [$externalName => [
    'sha256' => $externalHash,
    'size' => strlen($externalBytes),
    'source' => 'repository',
]];
$externalCompiled = CompiledRepository::create($externalPayload, $externalDirectory);
$check(
    !array_key_exists('base64', $externalCompiled->export()['media'][$externalName])
        && strlen(\Duo\Canon::encode($externalCompiled->export())) < 16384,
    'a media payload above the inline frontier is an external content-addressed artifact reference'
);
$externalOutput = fopen('php://temp', 'w+b');
$externalCompiled->copy_media_to_stream($externalName, $externalOutput);
rewind($externalOutput);
$check(
    stream_get_contents($externalOutput) === $externalBytes,
    'external media is copied in verified chunks through the compiled product boundary'
);
fclose($externalOutput);
$externalRoundTrip = CompiledRepository::from_array($externalCompiled->export(), $externalDirectory);
$check(
    $externalRoundTrip->media_size($externalName) === strlen($externalBytes)
        && $externalRoundTrip->media_sha256($externalName) === $externalHash,
    'the persisted external media reference retains exact size and content identity'
);
unset($externalBytes);
unlink("$externalDirectory/$externalName");
rmdir($externalDirectory);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgAAIAAAUAAWJVMogAAAAASUVORK5CYII=', true);
if (!is_string($png)) {
    throw new RuntimeException('test PNG fixture did not decode');
}
$pngHash = hash('sha256', $png);
$pngName = "$pngHash.png";
$mixedUsage = $payload;
$mixedUsage['tree'] = [
    'raster-use' => [
        'type' => 'post',
        'data' => ['type' => 'attachment', 'file' => 'images/raster.png', 'mime' => 'image/png', 'media' => $pngName],
    ],
    'generic-use' => [
        'type' => 'post',
        'data' => ['type' => 'attachment', 'file' => 'files/opaque.png', 'mime' => 'application/epub+zip', 'media' => $pngName],
    ],
];
$mixedUsage['media'] = [$pngName => ['sha256' => $pngHash, 'base64' => base64_encode($png)]];
$mixedCompiled = CompiledRepository::create($mixedUsage);
$check(
    $mixedCompiled->media_content($pngName) === $png,
    'one immutable PNG blob may back independently-routed raster and safe generic attachment MIME rows'
);

$exported = $compiled->export();
$check($exported['format'] === CompiledRepository::FORMAT, 'export(): carries the versioned format');
$check($exported['artifact_hash'] === $compiled->artifact_hash(), 'export(): artifact_hash matches the accessor');

$roundTripped = CompiledRepository::from_array($exported);
$check($roundTripped->artifact_hash() === $compiled->artifact_hash(), 'from_array(): round-trips the exact same artifact_hash');
$check($roundTripped->tree() === $compiled->tree(), 'from_array(): round-trips the exact same tree');

$tmp = tempnam(sys_get_temp_dir(), 'duo-compiled-artifact-test-');
$compiled->write($tmp);
$writtenRoundTrip = CompiledRepository::from_array(json_decode(file_get_contents($tmp), true));
$check($writtenRoundTrip->artifact_hash() === $compiled->artifact_hash(), 'write(): file round-trips through from_array() with the same hash');
unlink($tmp);

$tamperedExport = $exported;
$tamperedExport['tree']['u1']['data']['type'] = 'post_tampered';
$threwOnTamper = false;
try {
    CompiledRepository::from_array($tamperedExport);
} catch (\RuntimeException $e) {
    $threwOnTamper = str_contains($e->getMessage(), 'content hash does not verify');
}
$check($threwOnTamper, 'from_array(): a tampered tree fails hash verification, not silently accepted');

$missingUploadsInventory = $exported;
unset($missingUploadsInventory['uploads_inventory']);
$threwOnMissingInventory = false;
try {
    CompiledRepository::from_array($missingUploadsInventory);
} catch (\RuntimeException $e) {
    // Removing uploads_inventory changes the payload the hash was computed
    // over, so this is refused as a hash mismatch before the inventory check
    // itself is ever reached -- both are fail-closed, either is acceptable.
    $threwOnMissingInventory = str_contains($e->getMessage(), 'content hash does not verify')
        || str_contains($e->getMessage(), 'upload inventory does not match');
}
$check($threwOnMissingInventory, 'from_array(): a missing/mismatched uploads_inventory is refused, not silently derived');

$refusal = new RepositoryCompilationException([
    ['code' => 'bad_ref', 'path' => 'state/posts/page/x.md', 'locator' => 'terms.category', 'message' => 'unknown uuid'],
]);
$check($refusal->payload()['error'] === 'repository_compilation_failed', 'RepositoryCompilationException: exact error code');
$check(count($refusal->payload()['diagnostics']) === 1, 'RepositoryCompilationException: diagnostics carried verbatim');
$check(str_contains($refusal->getMessage(), '[bad_ref] state/posts/page/x.md:terms.category — unknown uuid'),
    'RepositoryCompilationException: human message names path, locator, and message');

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall CompiledArtifact checks passed\n";
exit(0);
