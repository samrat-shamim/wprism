<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/agent/src/Capture/MediaCapture.php';

use WPrism\MediaCapture;
use WPrism\MediaPayloadAuthority;
use WPrismTest\WpStore;

// The original 1px fixture passed WordPress metadata inspection but Capture
// rejected its invalid IDAT CRC. Exercise the exact file the native seed uploads.
$store = WpStore::reset();
$uploads = $store->ensureUploadDir();
$fixture = dirname(__DIR__, 2) . '/fixtures/native/design.png';
copy($fixture, $uploads . '/aio-native-design.png');
$capture = new MediaCapture();
$result = $capture->capture(6, 'aio-native-design.png', 'image/png', 'AIO native design');
$bytes = (string) file_get_contents($fixture);
wprism_check_same(hash('sha256', $bytes) . '.png', $result['front']['media'], 'native design image passes the actual attachment capture boundary');
wprism_check_same($bytes, MediaPayloadAuthority::sourceBytes($result['media_ref'][0], $result['media_ref'][1]), 'captured media source retains the exact native upload bytes');
$broken = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aBfkAAAAASUVORK5CYII=', true);
file_put_contents($uploads . '/broken.png', $broken);
wprism_check_throws(static fn() => $capture->capture(7, 'broken.png', 'image/png', ''), RuntimeException::class, 'the prior malformed native image remains refused', 'raster container');
wprism_check_summary('AIO native media fixture');
