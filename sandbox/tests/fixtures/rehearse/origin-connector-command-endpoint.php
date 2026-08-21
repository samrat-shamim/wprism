<?php
declare(strict_types=1);

use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\OriginAuthority;
use Duo\Cloud\OriginFileBlobStore;

$root = (string) getenv('DUO_OCC_ROOT');
$state = (string) getenv('DUO_OCC_STATE');
$blobs = (string) getenv('DUO_OCC_BLOBS');
$serviceKeyId = (string) getenv('DUO_OCC_SERVICE_KEY_ID');
$serviceSecretPath = (string) getenv('DUO_OCC_SERVICE_SECRET_PATH');
$deviceDigestPath = (string) getenv('DUO_OCC_DEVICE_DIGEST_PATH');
if ($root === '' || $state === '' || $blobs === '' || $serviceKeyId === ''
    || $serviceSecretPath === '' || $deviceDigestPath === '') {
    http_response_code(500);
    exit;
}

require_once $root . '/cloud/src/OriginAuthority.php';
require_once $root . '/cloud/src/OriginFileBlobStore.php';
$serviceSecret = base64_decode(trim((string) file_get_contents($serviceSecretPath)), true);
$deviceDigest = base64_decode(trim((string) file_get_contents($deviceDigestPath)), true);
if (!is_string($serviceSecret) || !is_string($deviceDigest)) {
    http_response_code(500);
    exit;
}
$authority = new OriginAuthority(
    new FileAuthorityStore($state),
    new OriginFileBlobStore($blobs),
    $serviceKeyId,
    $serviceSecret,
    $deviceDigest
);
sodium_memzero($serviceSecret);
sodium_memzero($deviceDigest);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$body = file_get_contents('php://input');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($path) || !is_string($body)) {
    http_response_code(405);
    exit;
}
try {
    $response = $authority->handle($path, $body);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($response));
    echo $response;
} catch (Throwable) {
    http_response_code(403);
    echo "{\"error\":\"request_refused\"}\n";
}
