<?php

declare(strict_types=1);

// The live scenario copies this ignored helper into each site repository so
// pair.yml can execute it as uid 33 without loading WordPress. The refusal
// store is intentionally 0700/0600 and must not be read as the host identity.

const WPRISM_RANK_YOAST_PRIVATE_REFUSAL_FORMAT =
    'wprism-rank-math-yoast-private-refusal-check/v1';
const WPRISM_RANK_YOAST_PRIVATE_REFUSAL_COMMAND = 'compile';
const WPRISM_RANK_YOAST_PRIVATE_REFUSAL_REASON = 'compile_failed';
const WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE =
    "wprism: manifest 'rank-math' for plugin 'seo-by-rank-math/rank-math.php' declares plugin "
    . "'wordpress-seo/wp-seo.php' incompatible, and pinned manifest(s) {'yoast'} claim that plugin — "
    . 'incompatible plugin adapters cannot share one policy; pin only one';
const WPRISM_RANK_YOAST_PRIVATE_REFUSAL_RECORD_LIMIT = 262144;

function rank_yoast_private_refusal_fail(string $message): never
{
    throw new RuntimeException($message);
}

function rank_yoast_private_refusal_name_is_valid(string $name): bool
{
    return preg_match(
        '/\A[0-9]{8}-[0-9]{6}-compile-[a-f0-9]{24}\.json\z/',
        $name
    ) === 1;
}

/** @return list<string> */
function rank_yoast_private_refusal_inventory(string $directory): array
{
    clearstatcache(true, $directory);
    $directoryStat = @lstat($directory);
    if ($directoryStat === false) {
        return [];
    }
    if ((((int) ($directoryStat['mode'] ?? 0)) & 0170000) !== 0040000
        || (((int) ($directoryStat['mode'] ?? 0)) & 0777) !== 0700) {
        rank_yoast_private_refusal_fail('refusal directory is not one ordinary 0700 directory');
    }
    $entries = @scandir($directory);
    if (!is_array($entries)) {
        rank_yoast_private_refusal_fail('refusal directory cannot be read by the target CLI identity');
    }
    $names = [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || !str_contains($name, '-compile-')) {
            continue;
        }
        if (!rank_yoast_private_refusal_name_is_valid($name)) {
            rank_yoast_private_refusal_fail('compile refusal record has a noncanonical name');
        }
        $path = $directory . '/' . $name;
        clearstatcache(true, $path);
        $fileStat = @lstat($path);
        if (!is_array($fileStat)
            || (((int) ($fileStat['mode'] ?? 0)) & 0170000) !== 0100000
            || (((int) ($fileStat['mode'] ?? 0)) & 0777) !== 0600) {
            rank_yoast_private_refusal_fail('compile refusal record is not one ordinary 0600 file');
        }
        $names[] = $name;
    }
    sort($names, SORT_STRING);
    return $names;
}

/** @return list<string> */
function rank_yoast_private_refusal_decode_baseline(string $encoded): array
{
    try {
        $baseline = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        rank_yoast_private_refusal_fail('baseline is not canonical JSON');
    }
    if (!is_array($baseline) || !array_is_list($baseline)) {
        rank_yoast_private_refusal_fail('baseline is not a JSON list');
    }
    foreach ($baseline as $name) {
        if (!is_string($name) || !rank_yoast_private_refusal_name_is_valid($name)) {
            rank_yoast_private_refusal_fail('baseline contains a noncanonical record name');
        }
    }
    $canonical = $baseline;
    sort($canonical, SORT_STRING);
    if ($canonical !== $baseline || count(array_unique($baseline)) !== count($baseline)) {
        rank_yoast_private_refusal_fail('baseline record names are not sorted and unique');
    }
    return $baseline;
}

function rank_yoast_private_refusal_read(string $path): string
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        rank_yoast_private_refusal_fail('the appended refusal record could not be opened');
    }
    $opened = fstat($handle);
    clearstatcache(true, $path);
    $named = @lstat($path);
    if (!is_array($opened) || !is_array($named)
        || (((int) ($opened['mode'] ?? 0)) & 0170000) !== 0100000
        || (((int) ($opened['mode'] ?? 0)) & 0777) !== 0600
        || (((int) ($named['mode'] ?? 0)) & 0170000) !== 0100000
        || (((int) ($named['mode'] ?? 0)) & 0777) !== 0600
        || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
        || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')) {
        fclose($handle);
        rank_yoast_private_refusal_fail('the appended refusal path does not name its opened ordinary 0600 inode');
    }
    $size = (int) ($opened['size'] ?? -1);
    if ($size < 1 || $size > WPRISM_RANK_YOAST_PRIVATE_REFUSAL_RECORD_LIMIT) {
        fclose($handle);
        rank_yoast_private_refusal_fail('the appended refusal record is outside its fixed byte boundary');
    }
    $bytes = stream_get_contents($handle);
    $closed = fclose($handle);
    if (!is_string($bytes) || strlen($bytes) !== $size || !$closed) {
        rank_yoast_private_refusal_fail('the appended refusal record could not be read exactly');
    }
    return $bytes;
}

function rank_yoast_private_refusal_receipt(): string
{
    return json_encode([
        'command' => WPRISM_RANK_YOAST_PRIVATE_REFUSAL_COMMAND,
        'format' => WPRISM_RANK_YOAST_PRIVATE_REFUSAL_FORMAT,
        'new_records' => 1,
        'root_message_sha256' => hash('sha256', WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE),
        'verified' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function rank_yoast_private_refusal_verify(string $directory, string $encodedBaseline): string
{
    $baseline = rank_yoast_private_refusal_decode_baseline($encodedBaseline);
    $current = rank_yoast_private_refusal_inventory($directory);
    if (array_diff($baseline, $current) !== []) {
        rank_yoast_private_refusal_fail('the invocation removed prior compile refusal evidence');
    }
    $new = array_values(array_diff($current, $baseline));
    if (count($current) !== count($baseline) + 1 || count($new) !== 1) {
        rank_yoast_private_refusal_fail('the invocation did not append exactly one compile refusal record');
    }

    $bytes = rank_yoast_private_refusal_read($directory . '/' . $new[0]);
    try {
        $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        rank_yoast_private_refusal_fail('the appended refusal record is not JSON');
    }
    if (!is_array($record)
        || ($record['format'] ?? null) !== 'wprism-private-refusal-evidence/v2'
        || ($record['command'] ?? null) !== WPRISM_RANK_YOAST_PRIVATE_REFUSAL_COMMAND
        || ($record['reason_code'] ?? null) !== WPRISM_RANK_YOAST_PRIVATE_REFUSAL_REASON
        || ($record['traversal']['scan_complete'] ?? null) !== true
        || ($record['traversal']['record_complete'] ?? null) !== true) {
        rank_yoast_private_refusal_fail('the appended refusal record has an incomplete envelope');
    }
    $roots = array_values(array_filter(
        is_array($record['throwable'] ?? null) ? $record['throwable'] : [],
        static fn(mixed $row): bool => is_array($row)
            && array_key_exists('parent_index', $row)
            && $row['parent_index'] === null
            && ($row['relation'] ?? null) === 'root'
    ));
    if (count($roots) !== 1
        || ($roots[0]['index'] ?? null) !== 0
        || ($roots[0]['message'] ?? null) !== WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE
        || ($roots[0]['message_encoding'] ?? null) !== 'utf-8'
        || ($roots[0]['message_original_bytes'] ?? null) !== strlen(WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE)
        || ($roots[0]['message_sha256'] ?? null) !== hash('sha256', WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE)
        || ($roots[0]['message_truncated'] ?? null) !== false) {
        rank_yoast_private_refusal_fail('the appended refusal record does not retain the exact complete root cause');
    }

    return rank_yoast_private_refusal_receipt();
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

try {
    $mode = $argv[1] ?? '';
    $directory = $argv[2] ?? '';
    if ($mode === 'snapshot' && count($argv) === 3 && $directory !== '') {
        echo json_encode(
            rank_yoast_private_refusal_inventory($directory),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ), "\n";
        exit(0);
    }
    if ($mode === 'verify' && count($argv) === 4 && $directory !== '') {
        echo rank_yoast_private_refusal_verify($directory, $argv[3]), "\n";
        exit(0);
    }
    rank_yoast_private_refusal_fail(
        'usage: private-refusal-evidence.php <snapshot|verify> <directory> [baseline-json]'
    );
} catch (Throwable $throwable) {
    fwrite(STDERR, 'wprism-rank-math-yoast-private-refusal: ' . $throwable->getMessage() . "\n");
    exit(1);
}
