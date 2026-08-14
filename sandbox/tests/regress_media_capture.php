<?php
declare(strict_types=1);

/** Direct offline characterization for attachment media source capture. */

$GLOBALS['media_capture_basedir'] = '';
$GLOBALS['media_capture_filter_calls'] = [];
$GLOBALS['media_capture_filter'] = static fn($source) => $source;

function wp_upload_dir($time = null, bool $createDir = true): array {
    return ['basedir' => $GLOBALS['media_capture_basedir']];
}

function trailingslashit(string $value): string {
    return rtrim($value, '/\\') . '/';
}

function apply_filters(string $tag, $source, ...$args) {
    $GLOBALS['media_capture_filter_calls'][] = [$tag, $source, $args];
    return ($GLOBALS['media_capture_filter'])($source, ...$args);
}

$root = dirname(__DIR__, 2);
require_once "$root/agent/src/MediaCapture.php";

use Duo\CommandRefusalException;
use Duo\MediaCapture;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
};

$tmp = sys_get_temp_dir() . '/duo-media-capture-' . bin2hex(random_bytes(6));
$uploadRoot = "$tmp/uploads";
$localDir = "$uploadRoot/2026/08";
mkdir($localDir, 0777, true);
$GLOBALS['media_capture_basedir'] = $uploadRoot;
$localBytes = "local-media\0bytes";
$localPath = "$localDir/photo.JPG";
file_put_contents($localPath, $localBytes);

$capture = new MediaCapture();
$check(class_exists(MediaCapture::class, false), 'MediaCapture loads as a direct offline boundary');
$check(!class_exists(Duo\Capture::class, false), 'MediaCapture does not load Duo\\Capture');
$check(!class_exists(Duo\Policy::class, false), 'MediaCapture does not load Duo\\Policy');
$check(!class_exists(Duo\Tokens::class, false), 'MediaCapture does not load Duo\\Tokens');
$check(!class_exists(Duo\Ledger::class, false), 'MediaCapture does not load Duo\\Ledger');

$GLOBALS['media_capture_filter_calls'] = [];
$GLOBALS['media_capture_filter'] = static fn($source) => $source;
$local = $capture->capture(17, '2026/08/photo.JPG', 'image/jpeg', 'Local alt', false);
$localSha = hash('sha256', $localBytes);
$check(
    $local['front'] === [
        'file' => '2026/08/photo.JPG',
        'media' => "$localSha.JPG",
        'mime' => 'image/jpeg',
        'alt' => 'Local alt',
    ],
    'local attachment front fields preserve path, content hash, extension case, mime, and alt'
);
$check(
    $local['media_ref'] === ["$localSha.JPG", ['path' => $localPath]],
    'local attachment returns the exact publication source contract'
);
$check(
    $GLOBALS['media_capture_filter_calls'] === [[
        'duo_attachment_capture_source',
        ['path' => $localPath],
        [17, '2026/08/photo.JPG', $localPath],
    ]],
    'ordinary capture invokes the offload hook with the historical local default and exact arguments'
);

$GLOBALS['media_capture_filter_calls'] = [];
$strict = $capture->capture(18, '2026/08/photo.JPG', 'image/jpeg', '', true);
$check($strict['media_ref'] === ["$localSha.JPG", ['path' => $localPath]],
    'strict observation still captures an available local source');
$check($GLOBALS['media_capture_filter_calls'] === [],
    'strict observation never invokes the external offload hook');

$offloadPath = "$tmp/materialized.webp";
$offloadPathBytes = 'provider-path-bytes';
file_put_contents($offloadPath, $offloadPathBytes);
$GLOBALS['media_capture_filter_calls'] = [];
$GLOBALS['media_capture_filter'] = static fn($source) => ['path' => $offloadPath];
$pathBacked = $capture->capture(19, 'remote/original.webp', 'image/webp', 'Remote', false);
$pathSha = hash('sha256', $offloadPathBytes);
$check(
    $pathBacked['front']['media'] === "$pathSha.webp"
        && $pathBacked['media_ref'] === ["$pathSha.webp", ['path' => $offloadPath]],
    'an offload provider may replace a missing local source with one readable path'
);

$offloadBytes = "provider\0raw\0bytes";
$GLOBALS['media_capture_filter'] = static fn($source) => ['bytes' => $offloadBytes];
$bytesBacked = $capture->capture(20, 'remote/original.bin', 'application/octet-stream', '', false);
$bytesSha = hash('sha256', $offloadBytes);
$check(
    $bytesBacked['front']['media'] === "$bytesSha.bin"
        && $bytesBacked['media_ref'] === ["$bytesSha.bin", ['bytes' => $offloadBytes]],
    'an offload provider may supply raw bytes with the same content-addressed publication contract'
);

$missingAttached = false;
try {
    $capture->capture(21, null, 'image/png', '', false);
} catch (Throwable $e) {
    $missingAttached = $e->getMessage() === 'duo: attachment 21 has no _wp_attached_file';
}
$check($missingAttached, 'a missing _wp_attached_file refuses with the historical exact message');

$GLOBALS['media_capture_filter_calls'] = [];
$strictMissing = null;
try {
    $capture->capture(22, 'remote/missing.png', 'image/png', '', true);
} catch (Throwable $e) {
    $strictMissing = $e;
}
$check(
    $strictMissing instanceof CommandRefusalException
        && $strictMissing->reasonCode === 'explain_observation_precondition_failed'
        && $strictMissing->getPrevious()?->getMessage()
            === 'duo: strict attachment observation has no local media source; the external offload hook is deliberately not invoked by explain',
    'strict missing media maps to the stable value-free explain precondition with private exact cause'
);
$check($GLOBALS['media_capture_filter_calls'] === [],
    'strict missing media refuses before provider execution');

$GLOBALS['media_capture_filter'] = static fn($source) => null;
$noProvider = false;
try {
    $capture->capture(23, 'remote/missing.png', 'image/png', '', false);
} catch (Throwable $e) {
    $noProvider = $e->getMessage()
        === "duo: attachment 23 file 'remote/missing.png' is not present locally and no offload provider supplied bytes via duo_attachment_capture_source; capture cannot proceed for this attachment";
}
$check($noProvider, 'ordinary missing media names the absent local/provider contract exactly');

$invalidCases = [
    'non-array' => [
        'source' => 'raw-string',
        'message' => "duo: attachment 24 offload provider returned an invalid duo_attachment_capture_source value; expected exactly ['path' => <readable path>] or ['bytes' => <raw bytes>]",
    ],
    'neither-key' => [
        'source' => [],
        'message' => "duo: attachment 24 offload provider returned an invalid duo_attachment_capture_source value; expected exactly one of 'path' or 'bytes'",
    ],
    'both-keys' => [
        'source' => ['path' => $offloadPath, 'bytes' => $offloadBytes],
        'message' => "duo: attachment 24 offload provider returned an invalid duo_attachment_capture_source value; expected exactly one of 'path' or 'bytes'",
    ],
    'bad-path' => [
        'source' => ['path' => "$tmp/absent.file"],
        'message' => 'duo: attachment 24 offload provider path is not a readable file',
    ],
    'bad-bytes' => [
        'source' => ['bytes' => 42],
        'message' => 'duo: attachment 24 offload provider bytes must be a string',
    ],
];
foreach ($invalidCases as $label => $case) {
    $GLOBALS['media_capture_filter'] = static fn($source) => $case['source'];
    $rejected = false;
    try {
        $capture->capture(24, 'remote/missing.dat', 'application/octet-stream', '', false);
    } catch (Throwable $e) {
        $rejected = $e->getMessage() === $case['message'];
    }
    $check($rejected, "provider $label shape keeps its exact fail-closed diagnostic");
}

$mediaSource = file_get_contents("$root/agent/src/MediaCapture.php");
$captureSource = file_get_contents("$root/agent/src/Capture.php");
$check(
    !str_contains($mediaSource, 'Db::')
        && !str_contains($mediaSource, 'Ledger::')
        && !str_contains($mediaSource, 'Uuid::')
        && !str_contains($mediaSource, '$wpdb'),
    'MediaCapture owns no database, identity, or ledger mutation'
);
$check(
    substr_count($captureSource, "require_once __DIR__ . '/MediaCapture.php';") === 1
        && str_contains($captureSource, 'private ?MediaCapture $mediaCapture = null;')
        && str_contains($captureSource, 'new MediaCapture()'),
    'Capture explicitly requires and lazily binds the extracted media collaborator'
);
$check(
    str_contains($captureSource, '$this->media_capture()->capture(')
        && str_contains($captureSource, '$front += $attachment[\'front\'];')
        && str_contains($captureSource, '$mediaRef = $attachment[\'media_ref\'];'),
    'build_post delegates attachment source projection and retains its historical media result'
);
$check(
    !str_contains($captureSource, "apply_filters(\n                    'duo_attachment_capture_source'")
        && !str_contains($captureSource, 'offload provider returned an invalid duo_attachment_capture_source value'),
    'Capture no longer owns duplicate attachment source validation'
);

@unlink($localPath);
@unlink($offloadPath);
@rmdir($localDir);
@rmdir(dirname($localDir));
@rmdir($uploadRoot);
@rmdir($tmp);

if ($failures > 0) {
    echo "\nFAIL: $failures media-capture check(s) failed\n";
    exit(1);
}
echo "\nREGRESS_MEDIA_CAPTURE PASSED\n";
