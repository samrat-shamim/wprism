<?php

declare(strict_types=1);

// pair.yml:195 runs this as the target CLI uid without loading WordPress;
// PrivateRefusalEvidence.php:15-18 deliberately makes the 0700/0600 store
// unreadable to a different native-Linux host identity.

const WPRISM_RANK_MATH_PRIVATE_REFUSAL_FORMAT = 'wprism-rank-math-private-refusal-check/v1';
const WPRISM_RANK_MATH_PRIVATE_REFUSAL_RECORD_LIMIT = 262144;
const WPRISM_RANK_MATH_PRIVATE_REFUSAL_PROFILES = [
    'missing-code' => [
        'command' => 'lifecycle-status',
        'reason_code' => 'lifecycle_status_failed',
        'message' => "wprism: deploy refused — code_mismatch:\n\n"
            . "  - active_plugins in state/options/core.json declares 'seo-by-rank-math/rank-math.php' "
            . "but seo-by-rank-math/rank-math.php does not exist in this environment (checked against "
            . "this environment's wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' "
            . "means 'installed on the env'). Install/vendor the plugin here, or this branch's code/ changes "
            . "haven't reached this environment yet.\n\n"
            . 'Install/vendor whatever is missing (or update code/) in this environment first, or pass '
            . '--force-code-mismatch to proceed anyway.',
    ],
    'schema-loss' => [
        'command' => 'schema-status',
        'reason_code' => 'schema_status_failed',
        'message' => "wprism: schema-settle table 'rank_math_redirections' is absent but durable identity "
            . 'history remains; restore the database-matched table instead of preparing an empty replacement',
    ],
];

function rank_math_private_refusal_fail(string $message): never
{
    fwrite(STDERR, "wprism-rank-math-private-refusal: $message\n");
    exit(1);
}

/** @return array{command: string, reason_code: string, message: string} */
function rank_math_private_refusal_profile(string $name): array
{
    $profile = WPRISM_RANK_MATH_PRIVATE_REFUSAL_PROFILES[$name] ?? null;
    if (!is_array($profile)) {
        rank_math_private_refusal_fail('unknown verification profile');
    }
    return $profile;
}

function rank_math_private_refusal_name_is_valid(string $name, string $profileName): bool
{
    $command = rank_math_private_refusal_profile($profileName)['command'];
    return preg_match(
        '/\A[0-9]{8}-[0-9]{6}-' . preg_quote($command, '/') . '-[a-f0-9]{24}\.json\z/',
        $name
    ) === 1;
}

/** @return list<string> */
function rank_math_private_refusal_inventory(string $directory, string $profileName): array
{
    $command = rank_math_private_refusal_profile($profileName)['command'];
    clearstatcache(true, $directory);
    $directoryStat = @lstat($directory);
    if ($directoryStat === false) {
        return [];
    }
    if ((((int) ($directoryStat['mode'] ?? 0)) & 0170000) !== 0040000
        || (((int) ($directoryStat['mode'] ?? 0)) & 0777) !== 0700) {
        rank_math_private_refusal_fail('refusal directory is not one ordinary 0700 directory');
    }
    $entries = @scandir($directory);
    if (!is_array($entries)) {
        rank_math_private_refusal_fail('refusal directory cannot be read by the target CLI identity');
    }
    $names = [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || !str_contains($name, '-' . $command . '-')) {
            continue;
        }
        if (!rank_math_private_refusal_name_is_valid($name, $profileName)) {
            rank_math_private_refusal_fail("$command refusal record has a noncanonical name");
        }
        $path = $directory . '/' . $name;
        clearstatcache(true, $path);
        $fileStat = @lstat($path);
        if (!is_array($fileStat)
            || (((int) ($fileStat['mode'] ?? 0)) & 0170000) !== 0100000
            || (((int) ($fileStat['mode'] ?? 0)) & 0777) !== 0600) {
            rank_math_private_refusal_fail("$command refusal record is not one ordinary 0600 file");
        }
        $names[] = $name;
    }
    sort($names, SORT_STRING);
    return $names;
}

/** @return list<string> */
function rank_math_private_refusal_decode_baseline(string $encoded, string $profileName): array
{
    try {
        $baseline = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        rank_math_private_refusal_fail('baseline is not canonical JSON');
    }
    if (!is_array($baseline) || !array_is_list($baseline)) {
        rank_math_private_refusal_fail('baseline is not a JSON list');
    }
    foreach ($baseline as $name) {
        if (!is_string($name) || !rank_math_private_refusal_name_is_valid($name, $profileName)) {
            rank_math_private_refusal_fail('baseline contains a noncanonical record name');
        }
    }
    $canonical = $baseline;
    sort($canonical, SORT_STRING);
    if ($canonical !== $baseline || count(array_unique($baseline)) !== count($baseline)) {
        rank_math_private_refusal_fail('baseline record names are not sorted and unique');
    }
    return $baseline;
}

function rank_math_private_refusal_read(string $path): string
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        rank_math_private_refusal_fail('the appended refusal record could not be opened');
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
        rank_math_private_refusal_fail('the appended refusal path does not name its opened ordinary 0600 inode');
    }
    $size = (int) ($opened['size'] ?? -1);
    if ($size < 1 || $size > WPRISM_RANK_MATH_PRIVATE_REFUSAL_RECORD_LIMIT) {
        fclose($handle);
        rank_math_private_refusal_fail('the appended refusal record is outside its fixed byte boundary');
    }
    $bytes = stream_get_contents($handle);
    $closed = fclose($handle);
    if (!is_string($bytes) || strlen($bytes) !== $size || !$closed) {
        rank_math_private_refusal_fail('the appended refusal record could not be read exactly');
    }
    return $bytes;
}

function rank_math_private_refusal_receipt(string $profileName = 'schema-loss'): string
{
    $profile = rank_math_private_refusal_profile($profileName);
    return json_encode([
        'command' => $profile['command'],
        'format' => WPRISM_RANK_MATH_PRIVATE_REFUSAL_FORMAT,
        'new_records' => 1,
        'root_message_sha256' => hash('sha256', $profile['message']),
        'verified' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function rank_math_private_refusal_verify(
    string $directory,
    string $encodedBaseline,
    string $profileName
): string {
    $profile = rank_math_private_refusal_profile($profileName);
    $baseline = rank_math_private_refusal_decode_baseline($encodedBaseline, $profileName);
    $current = rank_math_private_refusal_inventory($directory, $profileName);
    if (array_diff($baseline, $current) !== []) {
        rank_math_private_refusal_fail('the invocation removed prior refusal evidence');
    }
    $new = array_values(array_diff($current, $baseline));
    if (count($current) !== count($baseline) + 1 || count($new) !== 1) {
        rank_math_private_refusal_fail(
            "the invocation did not append exactly one {$profile['command']} refusal record"
        );
    }

    $path = $directory . '/' . $new[0];
    $bytes = rank_math_private_refusal_read($path);
    try {
        $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        rank_math_private_refusal_fail('the appended refusal record is not JSON');
    }
    if (!is_array($record)
        || ($record['format'] ?? null) !== 'wprism-private-refusal-evidence/v2'
        || ($record['command'] ?? null) !== $profile['command']
        || ($record['reason_code'] ?? null) !== $profile['reason_code']
        || ($record['traversal']['scan_complete'] ?? null) !== true
        || ($record['traversal']['record_complete'] ?? null) !== true) {
        rank_math_private_refusal_fail('the appended refusal record has an incomplete envelope');
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
        || ($roots[0]['message'] ?? null) !== $profile['message']
        || ($roots[0]['message_encoding'] ?? null) !== 'utf-8'
        || ($roots[0]['message_original_bytes'] ?? null) !== strlen($profile['message'])
        || ($roots[0]['message_sha256'] ?? null) !== hash('sha256', $profile['message'])
        || ($roots[0]['message_truncated'] ?? null) !== false) {
        rank_math_private_refusal_fail('the appended refusal record does not retain the exact complete root cause');
    }

    return rank_math_private_refusal_receipt($profileName);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$mode = $argv[1] ?? '';
$profileName = $argv[2] ?? '';
$directory = $argv[3] ?? '';
if ($mode === 'snapshot' && count($argv) === 4 && $directory !== '') {
    echo json_encode(
        rank_math_private_refusal_inventory($directory, $profileName),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
    exit(0);
}
if ($mode === 'verify' && count($argv) === 5 && $directory !== '') {
    echo rank_math_private_refusal_verify($directory, $argv[4], $profileName), "\n";
    exit(0);
}
rank_math_private_refusal_fail(
    'usage: private-refusal-evidence.php <snapshot|verify> <profile> <directory> [baseline-json]'
);
