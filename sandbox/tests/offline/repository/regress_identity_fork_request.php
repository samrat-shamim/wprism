<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Repository/IdentityForkRequest.php';

use WPrism\Canon;
use WPrism\IdentityForkRequest;
use WPrism\PrivateFileBytes;

// Real private files exercise publication/read admission; DB ownership and
// transactional effects belong to the separate workflow suite, not this wire.
$base = realpath(sys_get_temp_dir());
if (!is_string($base)) throw new RuntimeException('test temporary root is unavailable');
$directory = $base . '/wprism-fork-request-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('test directory creation failed');
$document = [
    'format' => IdentityForkRequest::FORMAT,
    'operation_id' => str_repeat('7a', 16),
    'old_uuid' => '0199fbcc-2200-7000-8000-000000000001',
    'new_uuid' => '0199fbcc-2200-7000-8000-000000000002',
    'original_post_id' => 7, 'selected_post_id' => 19, 'selected_meta_id' => 31,
    'post_type' => 'page', 'created_at' => 1800000000, 'expires_at' => 1800000900,
];
$digestFields = [
    'canonical_preimage_sha256', 'database_target_sha256', 'identity_postimage_sha256',
    'identity_preimage_sha256', 'ledger_preimage_sha256', 'manifest_hash',
    'physical_rows_sha256', 'repository_revision', 'site_hash',
];
foreach ($digestFields as $field) $document[$field] = hash('sha256', $field);
$sequence = 0;
$checkBadCreate = static function (array $bad, string $label) use ($directory, &$sequence): void {
    $name = 'bad-create-' . ++$sequence . '.json';
    wprism_check_throws(static fn() => IdentityForkRequest::create($bad, $directory, $name), RuntimeException::class, $label);
    wprism_check(!file_exists($directory . '/' . $name), $label . ' publishes no file');
};
$checkBadRead = static function (string $bytes, string $label) use ($directory, &$sequence): void {
    $name = 'bad-read-' . ++$sequence . '.json';
    $sha = PrivateFileBytes::create($directory, $name, $bytes);
    wprism_check_throws(static fn() => IdentityForkRequest::read($directory, $name, $sha), RuntimeException::class, $label);
    wprism_check_same($bytes, file_get_contents($directory . '/' . $name), $label . ' preserves diagnostic input');
};
try {
    $request = IdentityForkRequest::create($document, $directory, 'intent.json');
    $canonical = Canon::encode($document);
    wprism_check_same($canonical, file_get_contents($directory . '/intent.json'), 'publication is the exact canonical document');
    wprism_check_same(hash('sha256', $canonical), $request->sha256(), 'preview hash binds every byte');
    wprism_check_same(0600, fileperms($directory . '/intent.json') & 07777, 'intent publication is private');
    $loaded = IdentityForkRequest::read($directory, 'intent.json', $request->sha256());
    wprism_check_same($canonical, Canon::encode($loaded->document()), 'fresh status process can read the reviewed intent');
    wprism_check_same('identity_fork:' . $document['operation_id'], $loaded->receipt_key(), 'bounded receipt key derives from the reviewed operation');
    $copy = $loaded->document(); $copy['selected_post_id'] = 99;
    wprism_check_same(19, $loaded->document()['selected_post_id'], 'callers cannot mutate retained intent through a returned array');
    foreach ([1800000000, 1800000899] as $now) {
        $loaded->assert_confirmable_at($now);
        wprism_check(true, 'confirmation admits half-open time window at ' . $now);
    }
    foreach ([-1, 0, 1799999999, 1800000900, PHP_INT_MAX] as $now) {
        wprism_check_throws(static fn() => $loaded->assert_confirmable_at($now), RuntimeException::class, 'confirmation refuses outside window at ' . $now);
    }
    $loaded->assert_database_target($document['database_target_sha256']);
    wprism_check(true, 'current configured target must match reviewed digest');
    wprism_check_throws(static fn() => $loaded->assert_database_target(hash('sha256', 'another target')), RuntimeException::class, 'different configured target refuses');
    wprism_check_throws(static fn() => $loaded->assert_database_target(str_repeat('A', 64)), RuntimeException::class, 'current target also requires canonical digest grammar');
    wprism_check_throws(static fn() => IdentityForkRequest::create($document, $directory, 'intent.json'), RuntimeException::class, 'occupied request destination cannot be replaced');
    wprism_check_same($canonical, file_get_contents($directory . '/intent.json'), 'occupied request remains byte-identical');
    wprism_check_throws(static fn() => IdentityForkRequest::read($directory, 'intent.json', hash('sha256', 'other intent')), RuntimeException::class, 'well-formed file cannot supply its own confirmation hash');
    // A missing path would produce a filesystem error if opened. The malformed
    // external hash must be rejected before the pathname can be consulted.
    try {
        IdentityForkRequest::read($directory, 'absent.json', 'not-a-hash');
        wprism_check(false, 'external hash validated before file access');
    } catch (RuntimeException $failure) {
        wprism_check_same('wprism: identity-fork request requires canonical SHA-256 digests', $failure->getMessage(), 'external hash validated before file access');
    }
    wprism_check_throws(static fn() => IdentityForkRequest::read($directory, 'absent.json', $request->sha256()), RuntimeException::class, 'missing reviewed intent refuses');
    $past = $document; $past['created_at'] = 1; $past['expires_at'] = 2;
    $expired = IdentityForkRequest::create($past, $directory, 'expired.json');
    wprism_check_same($expired->sha256(), IdentityForkRequest::read($directory, 'expired.json', $expired->sha256())->sha256(), 'expired intent remains inspectable for recovery status');
    $lastSecond = $document; $lastSecond['created_at'] = PHP_INT_MAX - 1; $lastSecond['expires_at'] = PHP_INT_MAX;
    IdentityForkRequest::create($lastSecond, $directory, 'int-frontier.json')->assert_confirmable_at(PHP_INT_MAX - 1);
    wprism_check(true, 'lifetime subtraction admits integer frontier without addition overflow');

    foreach (array_keys($document) as $field) {
        $bad = $document; unset($bad[$field]);
        $checkBadCreate($bad, 'missing field ' . $field . ' refuses');
        foreach ([null, false, [], new stdClass()] as $badValue) {
            $bad = $document; $bad[$field] = $badValue;
            $checkBadCreate($bad, 'non-scalar field ' . $field . ' refuses ' . get_debug_type($badValue));
        }
    }
    foreach (['request_sha256', 'post_content', 'post_password', 'selected_state_absent'] as $extra) {
        $bad = $document; $bad[$extra] = 'private-canary';
        $checkBadCreate($bad, 'undeclared authority or plaintext field ' . $extra . ' refuses');
    }
    foreach ($digestFields as $field) {
        foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 64) . "\n", 123] as $badValue) {
            $bad = $document; $bad[$field] = $badValue;
            $checkBadCreate($bad, 'digest ' . $field . ' rejects ' . get_debug_type($badValue) . ' width ' . strlen((string) $badValue));
        }
    }
    foreach (['original_post_id', 'selected_post_id', 'selected_meta_id', 'created_at', 'expires_at'] as $field) {
        foreach ([-1, 0, '1', 1.0, INF] as $badValue) {
            $bad = $document; $bad[$field] = $badValue;
            $checkBadCreate($bad, 'integer coordinate ' . $field . ' rejects non-positive or non-integer');
        }
    }
    foreach (['old_uuid', 'new_uuid'] as $field) {
        foreach (['', strtoupper($document[$field]), $document[$field] . "\n", '0199fbcc-2200-0000-8000-000000000001', '0199fbcc-2200-7000-0000-000000000001'] as $value) {
            $bad = $document; $bad[$field] = $value;
            $checkBadCreate($bad, 'UUID ' . $field . ' rejects noncanonical bytes');
        }
    }
    foreach ([
        ['new_uuid' => $document['old_uuid']], ['selected_post_id' => $document['original_post_id']],
        ['expires_at' => $document['created_at']], ['expires_at' => $document['created_at'] - 1],
        ['expires_at' => $document['created_at'] + 901], ['format' => 'wprism-identity-fork-request/v2'],
        ['post_type' => 'Page'], ['post_type' => ''], ['post_type' => str_repeat('p', 21)],
        ['post_type' => "page\n"], ['post_type' => "page\0"],
        ['operation_id' => str_repeat('A', 32)], ['operation_id' => str_repeat('1', 31)],
        ['operation_id' => str_repeat('1', 33)], ['operation_id' => str_repeat('1', 32) . "\n"],
    ] as $mutation) $checkBadCreate(array_replace($document, $mutation), 'conflicting or malformed request coordinates refuse');

    foreach (['', '{}', '[]', 'null', '"object"', '{', $canonical . "\n", ltrim($canonical), str_replace("\n", "\r\n", $canonical)] as $bytes) {
        if ($bytes !== $canonical) $checkBadRead($bytes, 'noncanonical or malformed JSON refuses');
    }
    $checkBadRead(json_encode(array_reverse($document, true), JSON_THROW_ON_ERROR), 'reordered compact representation refuses');
    $checkBadRead(str_replace('"selected_post_id": 19', '"selected_post_id": 19, "selected_post_id": 19', $canonical), 'duplicate keys refuse even with identical values and matching external hash');
    $checkBadRead(str_replace('"selected_post_id": 19', '"selected_post_id": 1.9e1', $canonical), 'alternate integer spelling refuses');
    $checkBadRead(str_replace('"selected_post_id": 19', '"selected_post_id": 9223372036854775808', $canonical), 'overflow JSON integer refuses');
    $checkBadRead(str_replace('"page"', '"p\u0061ge"', $canonical), 'alternate string escape refuses');
    $checkBadRead(str_repeat('x', IdentityForkRequest::MAX_BYTES + 1), 'wire byte limit is independent of document parsing');
    $badName = 'wrong-hash-malformed.json';
    PrivateFileBytes::create($directory, $badName, 'not-json');
    try {
        IdentityForkRequest::read($directory, $badName, $request->sha256());
        wprism_check(false, 'external byte hash precedes JSON decode');
    } catch (RuntimeException $failure) {
        wprism_check_same('wprism: identity-fork request differs from the reviewed bytes', $failure->getMessage(), 'external byte hash precedes JSON decode');
    }
} finally {
    // Only this invocation's random, populated test directory is owned here.
    foreach (new DirectoryIterator($directory) as $entry) {
        if (!$entry->isDot() && !unlink($entry->getPathname())) throw new RuntimeException('test file cleanup failed');
    }
    if (!rmdir($directory)) throw new RuntimeException('test directory cleanup failed');
}
wprism_check_summary('identity fork private request');
