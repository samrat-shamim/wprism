#!/usr/bin/env php
<?php
declare(strict_types=1);

function release_canonical(array $value): string {
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = array_is_list($item)
                ? array_map(static fn($v) => is_array($v) ? json_decode(release_canonical($v), true) : $v, $item)
                : json_decode(release_canonical($item), true);
        }
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function release_output(array $value): never { echo release_canonical($value) . "\n"; exit(0); }
function release_fail(string $message, int $code = 42): never { fwrite(STDERR, $message . "\n"); exit($code); }
function release_pointer_hash(string $release): string { return hash('sha256', $release); }
function release_atomic_write(string $path, string $bytes): void {
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $bytes); chmod($tmp, 0600); rename($tmp, $path);
}
/** @return array<string,mixed> */
function release_descriptor(string $role, string $release, string $artifact, string $revision, int $generation, string $fileHash): array {
    return [
        'artifact_hash' => $artifact,
        'code_revision' => $revision,
        'files' => [
            ['path' => 'wp-content/plugins/acme', 'sha256' => hash('sha256', ''), 'type' => 'directory'],
            ['path' => 'wp-content/plugins/acme/acme.php', 'sha256' => $fileHash, 'type' => 'file'],
        ],
        'format' => 'duo-code-release-descriptor/v1',
        'generation' => $generation,
        'owned_roots' => ['wp-content/plugins/acme'],
        'release_id' => $release,
        'role' => $role,
    ];
}
/** Verify every descriptor file/type/hash and absence of unrecorded owned paths. */
function release_verify(string $releaseRoot, array $descriptor): void {
    $base = $releaseRoot . '/' . $descriptor['release_id'];
    if (is_link($base) || !is_dir($base)) release_fail('release is missing or unsafe');
    $recorded = [];
    foreach ($descriptor['files'] as $entry) {
        $path = $base . '/' . $entry['path']; $recorded[$entry['path']] = true;
        if (is_link($path)) release_fail('symlink refused');
        if ($entry['type'] === 'directory') {
            if (!is_dir($path)) release_fail('descriptor directory missing');
        } elseif (!is_file($path) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
            release_fail('descriptor file hash mismatch');
        }
    }
    foreach ($descriptor['owned_roots'] as $owned) {
        $root = $base . '/' . $owned;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($base) + 1);
            if ($file->isLink()) release_fail('symlink refused');
            if (!isset($recorded[$relative])) release_fail('unrecorded owned path refused');
        }
    }
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$state = $argv[1];
$releaseRoot = $argv[2];
$pointer = $argv[3];
$priorRelease = is_file($pointer) ? trim((string) file_get_contents($pointer)) : 'release-prior';
$desiredRelease = 'release-desired-' . (string) ($request['generation'] ?? 0);
$action = (string) ($request['action'] ?? '');
$base = ['format' => 'duo-code-release-provider-response/v1'];

if ($action === 'probe') {
    release_output($base + [
        'atomic_pointer' => true, 'available' => true, 'build_resolution_off_target' => true,
        'immutable_releases' => true, 'mutable_resolution' => false,
        'provider_id' => 'ssh-release-fixture', 'provider_version' => '1.0.0',
        'state' => 'ready', 'target_generation_fenced' => true,
        'target_git_history' => false, 'target_registry_credentials' => false,
        'verified_descriptors' => true,
    ]);
}

if ($action === 'prepare') {
    if (is_file($state . '.kill-before-upload')) { @unlink($state . '.kill-before-upload'); exit(91); }
    $selected = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
    if ($selected !== $priorRelease || !is_dir($releaseRoot . '/' . $priorRelease)) release_fail('missing exact prior release');
    $priorFile = $releaseRoot . '/' . $priorRelease . '/wp-content/plugins/acme/acme.php';
    if (!is_file($priorFile)) release_fail('prior release incomplete');
    $desiredFile = $releaseRoot . '/' . $desiredRelease . '/wp-content/plugins/acme/acme.php';
    if (!is_file($desiredFile)) release_fail('off-target desired release unavailable');
    $prior = release_descriptor('prior', $priorRelease, hash('sha256', 'prior-artifact'), hash('sha256', 'prior-code'), max(0, (int) $request['generation'] - 1), (string) hash_file('sha256', $priorFile));
    $desired = release_descriptor('desired', $desiredRelease, (string) $request['artifact_hash'], (string) $request['desired_code_revision'], (int) $request['generation'], (string) hash_file('sha256', $desiredFile));
    $desiredBytes = release_canonical($desired) . "\n";
    if (!hash_equals(hash('sha256', $desiredBytes), (string) $request['desired_descriptor_sha256'])) release_fail('desired descriptor request mismatch');
    release_atomic_write((string) $request['prior_descriptor_path'], release_canonical($prior) . "\n");
    release_atomic_write((string) $request['desired_descriptor_path'], $desiredBytes);
    if (is_file($state . '.kill-after-upload')) { @unlink($state . '.kill-after-upload'); exit(92); }
    release_verify($releaseRoot, $prior); release_verify($releaseRoot, $desired);
    if (is_file($state . '.kill-after-verification')) { @unlink($state . '.kill-after-verification'); exit(93); }
    release_output($base + [
        'atomic_pointer' => true, 'available' => true, 'build_resolution_off_target' => true,
        'desired_descriptor_path' => $request['desired_descriptor_path'], 'desired_descriptor_sha256' => hash('sha256', $desiredBytes),
        'desired_pointer_sha256' => release_pointer_hash($desiredRelease), 'desired_release_id' => $desiredRelease,
        'immutable_releases' => true, 'mutable_resolution' => false,
        'prior_descriptor_path' => $request['prior_descriptor_path'], 'prior_descriptor_sha256' => hash('sha256', release_canonical($prior) . "\n"),
        'prior_pointer_sha256' => release_pointer_hash($priorRelease), 'prior_release_id' => $priorRelease,
        'provider_id' => 'ssh-release-fixture', 'provider_version' => '1.0.0', 'state' => 'prepared',
        'target_generation' => (int) $request['generation'], 'target_generation_fenced' => true,
        'target_git_history' => false, 'target_registry_credentials' => false, 'verified_descriptors' => true,
    ]);
}

if (in_array($action, ['select_desired', 'restore_prior', 'verify_desired', 'verify_prior'], true)) {
    $role = str_contains($action, 'desired') ? 'desired' : 'prior';
    $descriptorPath = (string) $request[$role . '_descriptor_path'];
    $descriptorBytes = (string) file_get_contents($descriptorPath);
    $descriptor = json_decode($descriptorBytes, true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals((string) $request[$role . '_descriptor_sha256'], hash('sha256', $descriptorBytes))) release_fail('descriptor changed');
    $target = (string) $descriptor['release_id'];
    $current = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
    $isVerify = str_starts_with($action, 'verify_');
    if (!$isVerify) {
        $otherRole = $role === 'desired' ? 'prior' : 'desired';
        $other = json_decode((string) file_get_contents((string) $request[$otherRole . '_descriptor_path']), true, 512, JSON_THROW_ON_ERROR);
        $from = (string) $other['release_id'];
        if ($current !== $from && $current !== $target) release_fail('concurrent pointer writer refused');
        if ($current !== $target) {
            if (is_file($state . '.kill-before-pointer')) { @unlink($state . '.kill-before-pointer'); exit(94); }
            release_atomic_write($pointer, $target . "\n");
            if (is_file($state . '.kill-after-pointer')) { @unlink($state . '.kill-after-pointer'); exit(95); }
        }
    } elseif ($current !== $target) {
        release_fail('selected pointer changed before verification');
    }
    release_verify($releaseRoot, $descriptor);
    $result = hash('sha256', release_canonical(['descriptor_sha256' => hash('sha256', $descriptorBytes), 'generation' => $request['generation'], 'pointer_sha256' => release_pointer_hash($target), 'release_id' => $target, 'target_id' => $request['target_id']]));
    release_output($base + [
        'action' => $action, 'atomic_pointer' => true, 'available' => true,
        'descriptor_sha256' => hash('sha256', $descriptorBytes), 'generation' => (int) $request['generation'],
        'no_unrecorded_owned_paths' => true, 'pointer_sha256' => release_pointer_hash($target),
        'provider_id' => 'ssh-release-fixture', 'provider_version' => '1.0.0', 'release_id' => $target,
        'result_sha256' => $result, 'state' => $isVerify ? 'verified' : 'selected',
        'symlinks_absent' => true, 'target_id' => $request['target_id'], 'verified_file_inventory' => true,
    ]);
}

if ($action === 'delete_prior') {
    $deleteRelease = (string) $request['release_id'];
    if (trim((string) file_get_contents($pointer)) === $deleteRelease) release_fail('cannot delete selected prior release');
    $dir = $releaseRoot . '/' . $deleteRelease;
    $file = $dir . '/wp-content/plugins/acme/acme.php';
    @unlink($file); @rmdir(dirname($file)); @rmdir(dirname(dirname($file))); @rmdir(dirname(dirname(dirname($file)))); @rmdir($dir);
    if (is_file($state . '.kill-after-delete')) { @unlink($state . '.kill-after-delete'); exit(96); }
    release_output($base + ['action' => 'delete_prior', 'available' => true, 'prior_release_absent' => !is_dir($dir), 'provider_id' => 'ssh-release-fixture', 'provider_version' => '1.0.0', 'state' => 'deleted']);
}

release_fail('unsupported fixture action');
