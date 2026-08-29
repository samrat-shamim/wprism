<?php
declare(strict_types=1);

/** Direct offline characterization for attachment media source capture. */

$GLOBALS['media_capture_basedir'] = '';
$GLOBALS['media_capture_filter_calls'] = [];
$GLOBALS['media_capture_filter'] = static fn($source) => $source;

function wp_upload_dir($time = null, bool $createDir = true): array {
    return ['basedir' => $GLOBALS['media_capture_basedir'], 'error' => false];
}

function trailingslashit(string $value): string {
    return rtrim($value, '/\\') . '/';
}

function apply_filters(string $tag, $source, ...$args) {
    $GLOBALS['media_capture_filter_calls'][] = [$tag, $source, $args];
    return ($GLOBALS['media_capture_filter'])($source, ...$args);
}

$root = dirname(__DIR__, 4);
require_once "$root/agent/src/Capture/MediaCapture.php";

use WPrism\CommandRefusalException;
use WPrism\MediaCapture;
use WPrism\MediaPayloadAuthority;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
};

$tmp = sys_get_temp_dir() . '/wprism-media-capture-' . bin2hex(random_bytes(6));
$uploadRoot = "$tmp/uploads";
$localDir = "$uploadRoot/2026/08";
mkdir($localDir, 0777, true);
$GLOBALS['media_capture_basedir'] = $uploadRoot;
$localBytes = "local-media\0bytes";
$localPath = "$localDir/photo.TXT";
file_put_contents($localPath, $localBytes);
$physicalLocalPath = realpath($localPath);

$capture = new MediaCapture();
$check(class_exists(MediaCapture::class, false), 'MediaCapture loads as a direct offline boundary');
$check(!class_exists(WPrism\Capture::class, false), 'MediaCapture does not load WPrism\\Capture');
$check(!class_exists(WPrism\Policy::class, false), 'MediaCapture does not load WPrism\\Policy');
$check(!class_exists(WPrism\Tokens::class, false), 'MediaCapture does not load WPrism\\Tokens');
$check(!class_exists(WPrism\Ledger::class, false), 'MediaCapture does not load WPrism\\Ledger');

$GLOBALS['media_capture_filter_calls'] = [];
$GLOBALS['media_capture_filter'] = static fn($source) => $source;
$local = $capture->capture(17, '2026/08/photo.TXT', 'text/plain', 'Local alt', false);
$localSha = hash('sha256', $localBytes);
$localWitness = ['extension' => 'TXT', 'sha256' => $localSha, 'size' => strlen($localBytes)];
$check(
    $local['front'] === [
        'file' => '2026/08/photo.TXT',
        'media' => "$localSha.TXT",
        'mime' => 'text/plain',
        'alt' => 'Local alt',
    ],
    'local attachment front fields preserve the shipped exact extension case while MIME routing normalizes separately'
);
$check(
    $local['media_ref'] === ["$localSha.TXT", ['path' => $physicalLocalPath, 'witness' => $localWitness]],
    'local attachment returns one physical source plus an immutable bounded blob witness'
);
$check(
    $GLOBALS['media_capture_filter_calls'] === [[
        'wprism_attachment_capture_source',
        ['path' => $physicalLocalPath],
        [17, '2026/08/photo.TXT', $physicalLocalPath],
    ]],
    'ordinary capture invokes the offload hook with the historical local default and exact arguments'
);

$GLOBALS['media_capture_filter_calls'] = [];
$GLOBALS['wp_filter']['wprism_attachment_capture_source'] = (object) [
    'callbacks' => [
        10 => ['throwing-provider-premise' => ['function' => static function (): void {
            throw new RuntimeException('strict local observation invoked the provider');
        }]],
    ],
];
$strict = $capture->capture(18, '2026/08/photo.TXT', 'text/plain', '', true);
$check($strict['media_ref'] === ["$localSha.TXT", ['path' => $physicalLocalPath, 'witness' => $localWitness]],
    'strict observation captures an available local source even when an offload provider is registered');
$check($GLOBALS['media_capture_filter_calls'] === [],
    'strict observation never invokes the external offload hook');
unset($GLOBALS['wp_filter']['wprism_attachment_capture_source']);

$offloadPath = "$tmp/materialized.epub";
$offloadPathBytes = 'provider-path-bytes';
file_put_contents($offloadPath, $offloadPathBytes);
$physicalOffloadPath = realpath($offloadPath);
$GLOBALS['media_capture_filter_calls'] = [];
$GLOBALS['media_capture_filter'] = static fn($source) => ['path' => $offloadPath];
$pathBacked = $capture->capture(19, 'remote/original.epub', 'application/epub+zip', 'Remote', false);
$pathSha = hash('sha256', $offloadPathBytes);
$check(
    $pathBacked['front']['media'] === "$pathSha.epub"
        && $pathBacked['media_ref'] === ["$pathSha.epub", [
            'path' => $physicalOffloadPath,
            'witness' => ['extension' => 'epub', 'sha256' => $pathSha, 'size' => strlen($offloadPathBytes)],
        ]],
    'an offload provider may supply a physical opaque custom-MIME source'
);

$offloadBytes = "provider\0raw\0bytes";
$GLOBALS['media_capture_filter'] = static fn($source) => ['bytes' => $offloadBytes];
$bytesBacked = $capture->capture(20, 'remote/original.xlsm', 'application/vnd.ms-excel.sheet.macroEnabled.12', '', false);
$bytesSha = hash('sha256', $offloadBytes);
$check(
    $bytesBacked['front']['media'] === "$bytesSha.xlsm"
        && $bytesBacked['front']['mime'] === 'application/vnd.ms-excel.sheet.macroEnabled.12'
        && $bytesBacked['media_ref'] === ["$bytesSha.xlsm", [
            'bytes' => $offloadBytes,
            'witness' => ['extension' => 'xlsm', 'sha256' => $bytesSha, 'size' => strlen($offloadBytes)],
        ]],
    'Core camel-case macro-enabled MIME reaches the bounded opaque generic branch unchanged'
);

$legacyExtension = 'LegacyExtensionMoreThanSixteen';
$legacyBytes = 'legacy-extension-media';
$legacySha = hash('sha256', $legacyBytes);
$GLOBALS['media_capture_filter'] = static fn($source) => ['bytes' => $legacyBytes];
$legacy = $capture->capture(200, "remote/original.$legacyExtension", 'text/plain', '', false);
$check(
    $legacy['media_ref'] === [
        "$legacySha.$legacyExtension",
        ['bytes' => $legacyBytes, 'witness' => [
            'extension' => $legacyExtension, 'sha256' => $legacySha, 'size' => strlen($legacyBytes),
        ]],
    ],
    'an upgrade preserves current-main mixed-case and longer-than-16-byte blob extensions without a repository migration'
);

$nameMaxExtension = str_repeat('a', 190);
$nameMaxBytes = 'exact-upload-name-boundary';
$nameMaxSha = hash('sha256', $nameMaxBytes);
$GLOBALS['media_capture_filter'] = static fn($source) => ['bytes' => $nameMaxBytes];
$nameMax = $capture->capture(206, "remote/original.$nameMaxExtension", 'text/plain', '', false);
$check(
    $nameMax['media_ref'][0] === "$nameMaxSha.$nameMaxExtension"
        && strlen($nameMax['media_ref'][0]) === 255,
    'a 190-byte extension reaches the filesystem NAME_MAX boundary without changing existing blob identity'
);
$nameOverRejected = false;
try {
    $capture->capture(207, 'remote/original.' . str_repeat('a', 191), 'text/plain', '', false);
} catch (Throwable $e) {
    $nameOverRejected = str_contains($e->getMessage(), 'canonical portable extension authority');
}
$check(
    $nameOverRejected,
    'a 191-byte extension refuses before any content-addressed repository filename is materialized'
);

$selectedPath = "$localDir/selected.txt";
file_put_contents($selectedPath, 'local-but-not-selected');
$selectedBytes = 'offload-wins';
$GLOBALS['media_capture_filter'] = static fn($source) => ['bytes' => $selectedBytes];
$selected = $capture->capture(201, '2026/08/selected.txt', 'text/plain', '', false);
$selectedSha = hash('sha256', $selectedBytes);
$check(
    $selected['media_ref'][0] === "$selectedSha.txt"
        && ($selected['media_ref'][1]['bytes'] ?? null) === $selectedBytes,
    'an offload provider selected over a readable local original becomes the sole capture authority'
);

$uploadsAlias = "$tmp/uploads-alias";
symlink($uploadRoot, $uploadsAlias);
$GLOBALS['media_capture_basedir'] = $uploadsAlias;
$GLOBALS['media_capture_filter'] = static fn($source) => $source;
$aliased = $capture->capture(202, '2026/08/photo.TXT', 'text/plain', '', false);
$check(
    ($aliased['media_ref'][1]['path'] ?? null) === $physicalLocalPath
        && ($aliased['media_ref'][1]['witness'] ?? null) === $localWitness,
    'a configured uploads-root symlink is rebound once to its physical local source identity'
);
$GLOBALS['media_capture_basedir'] = $uploadRoot;

$outside = "$tmp/outside.txt";
file_put_contents($outside, 'outside-upload-root');
$linkedDirectory = "$uploadRoot/2026/linked";
symlink(dirname($outside), $linkedDirectory);
$GLOBALS['media_capture_filter'] = static fn($source) => $source;
$symlinkLocalRejected = false;
try {
    $capture->capture(203, '2026/linked/outside.txt', 'text/plain', '', false);
} catch (Throwable $e) {
    $symlinkLocalRejected = str_contains($e->getMessage(), 'not present locally and no offload provider');
}
$check($symlinkLocalRejected, 'a symlinked upload ancestor cannot become local capture authority');

$providerLink = "$tmp/provider-link.txt";
symlink($outside, $providerLink);
$GLOBALS['media_capture_filter'] = static fn($source) => ['path' => $providerLink];
$providerLinkRejected = false;
try {
    $capture->capture(204, 'remote/provider.txt', 'text/plain', '', false);
} catch (Throwable $e) {
    $providerLinkRejected = $e->getMessage() === 'wprism: attachment 204 offload provider path is not a readable file';
}
$check($providerLinkRejected, 'an offload provider final symlink is refused before its bytes are observed');

$GLOBALS['media_capture_filter'] = static fn($source) => ['bytes' => "not-an-image"];
$unsafeImageRejected = false;
try {
    $capture->capture(205, 'remote/pretend.png', 'image/png', '', false);
} catch (Throwable $e) {
    $unsafeImageRejected = str_contains($e->getMessage(), 'raster container');
}
$check($unsafeImageRejected, 'the exact raster route rejects an opaque image prefix before publication');

$check(
    MediaPayloadAuthority::kindFor('application/epub+zip', 'epub') === 'generic'
        && MediaPayloadAuthority::kindFor('application/vnd.ms-excel.sheet.macroEnabled.12', 'xlsm') === 'generic',
    'the Core generic branch predicate admits custom and camel-case opaque MIME values without a roster substitute'
);

$missingAttached = false;
try {
    $capture->capture(21, null, 'image/png', '', false);
} catch (Throwable $e) {
    $missingAttached = $e->getMessage() === 'wprism: attachment 21 has no _wp_attached_file';
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
            === 'wprism: strict attachment observation has no local media source; the external offload hook is deliberately not invoked by explain',
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
        === "wprism: attachment 23 file 'remote/missing.png' is not present locally and no offload provider supplied bytes via wprism_attachment_capture_source; capture cannot proceed for this attachment";
}
$check($noProvider, 'ordinary missing media names the absent local/provider contract exactly');

$invalidCases = [
    'non-array' => [
        'source' => 'raw-string',
        'message' => "wprism: attachment 24 offload provider returned an invalid wprism_attachment_capture_source value; expected exactly ['path' => <readable path>] or ['bytes' => <raw bytes>]",
    ],
    'neither-key' => [
        'source' => [],
        'message' => "wprism: attachment 24 offload provider returned an invalid wprism_attachment_capture_source value; expected exactly one of 'path' or 'bytes'",
    ],
    'both-keys' => [
        'source' => ['path' => $offloadPath, 'bytes' => $offloadBytes],
        'message' => "wprism: attachment 24 offload provider returned an invalid wprism_attachment_capture_source value; expected exactly one of 'path' or 'bytes'",
    ],
    'bad-path' => [
        'source' => ['path' => "$tmp/absent.file"],
        'message' => 'wprism: attachment 24 offload provider path is not a readable file',
    ],
    'bad-bytes' => [
        'source' => ['bytes' => 42],
        'message' => 'wprism: attachment 24 offload provider bytes must be a string',
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

$mediaSource = file_get_contents("$root/agent/src/Capture/MediaCapture.php");
$captureSource = file_get_contents("$root/agent/src/Capture/Capture.php");
$candidateSource = file_get_contents("$root/agent/src/Capture/CaptureCandidateBuilder.php");
$postCaptureSource = file_get_contents("$root/agent/src/Capture/PostCapture.php");
$check(
    !str_contains($mediaSource, 'Db::')
        && !str_contains($mediaSource, 'Ledger::')
        && !str_contains($mediaSource, 'Uuid::')
        && !str_contains($mediaSource, '$wpdb'),
    'MediaCapture owns no database, identity, or ledger mutation'
);
$check(
    str_contains($candidateSource, "require_once __DIR__ . '/MediaCapture.php';")
        && str_contains($candidateSource, '$this->mediaCapture = new MediaCapture();')
        && str_contains($candidateSource, 'new PostCapture('),
    'candidate builder explicitly binds the extracted media collaborator'
);
$check(
    str_contains($postCaptureSource, '$this->mediaCapture->capture(')
        && str_contains($postCaptureSource, '$front += $attachment[\'front\'];')
        && str_contains($postCaptureSource, '$mediaRef = $attachment[\'media_ref\'];'),
    'PostCapture delegates attachment source projection and retains its historical media result'
);
$check(
    !str_contains($captureSource, "apply_filters(\n                    'wprism_attachment_capture_source'")
        && !str_contains($captureSource, 'offload provider returned an invalid wprism_attachment_capture_source value'),
    'Capture no longer owns duplicate attachment source validation'
);

@unlink($localPath);
@unlink($offloadPath);
@unlink($selectedPath);
@unlink($providerLink);
@unlink($outside);
@unlink($linkedDirectory);
@unlink($uploadsAlias);
@rmdir($localDir);
@rmdir(dirname($localDir));
@rmdir($uploadRoot);
@rmdir($tmp);

if ($failures > 0) {
    echo "\nFAIL: $failures media-capture check(s) failed\n";
    exit(1);
}
echo "\nREGRESS_MEDIA_CAPTURE PASSED\n";
