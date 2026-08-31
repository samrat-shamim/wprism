#!/usr/bin/env php
<?php
declare(strict_types=1);

// Live-test provider for the WooCommerce SSH deletion proof. Descriptors are
// derived from immutable release trees, so Recovery\CodeRelease remains the
// authority that compares every owned root and file hash with the compiled
// plan. The target receives no Git history or registry credential.

function woo_release_canonical(array $value): string {
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = array_is_list($item)
                ? array_map(static fn($v) => is_array($v) ? json_decode(woo_release_canonical($v), true) : $v, $item)
                : json_decode(woo_release_canonical($item), true);
        }
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function woo_release_output(array $value): never {
    echo woo_release_canonical($value) . "\n";
    exit(0);
}

function woo_release_fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(42);
}

function woo_release_pointer_hash(string $release): string {
    return hash('sha256', $release);
}

function woo_release_assert_id(string $release): void {
    if (preg_match('/^release-[A-Za-z0-9._-]{1,128}$/D', $release) !== 1) {
        woo_release_fail('release id is malformed');
    }
}

function woo_release_atomic_write(string $path, string $bytes): void {
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $bytes) !== strlen($bytes)
        || !chmod($temporary, 0600)
        || !rename($temporary, $path)) {
        woo_release_fail('atomic descriptor write failed');
    }
}

/** @return list<string> */
function woo_release_owned_roots(string $base): array {
    $owned = [];
    foreach (['mu-plugins', 'plugins', 'themes'] as $root) {
        $directory = $base . '/wp-content/' . $root;
        if (!is_dir($directory) || is_link($directory)) {
            continue;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            woo_release_fail('release component root cannot be read');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $entry) !== 1) {
                woo_release_fail('release component name is malformed');
            }
            $owned[] = 'wp-content/' . $root . '/' . $entry;
        }
    }
    sort($owned, SORT_STRING);
    return $owned;
}

/** @param array<string,array{path:string,sha256:string,type:string}> $rows */
function woo_release_rows(string $base, string $owned, array &$rows): void {
    $path = $base . '/' . $owned;
    if (is_link($path)) {
        woo_release_fail('symlink refused');
    }
    if (is_file($path)) {
        $rows[$owned] = ['path' => $owned, 'sha256' => (string) hash_file('sha256', $path), 'type' => 'file'];
        return;
    }
    if (!is_dir($path)) {
        woo_release_fail('owned root is missing from the release');
    }
    $rows[$owned] = ['path' => $owned, 'sha256' => hash('sha256', ''), 'type' => 'directory'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($base) + 1);
        if ($entry->isLink()) {
            woo_release_fail('symlink refused');
        }
        if ($entry->isDir()) {
            $rows[$relative] = ['path' => $relative, 'sha256' => hash('sha256', ''), 'type' => 'directory'];
            continue;
        }
        if (!$entry->isFile()) {
            woo_release_fail('release entries must be regular files or directories');
        }
        $rows[$relative] = [
            'path' => $relative,
            'sha256' => (string) hash_file('sha256', $entry->getPathname()),
            'type' => 'file',
        ];
    }
}

/** @return array<string,mixed> */
function woo_release_descriptor(
    string $role,
    string $releaseRoot,
    string $release,
    string $artifact,
    string $revision,
    int $generation
): array {
    woo_release_assert_id($release);
    $base = $releaseRoot . '/' . $release;
    if (is_link($base) || !is_dir($base)) {
        woo_release_fail('release is missing or unsafe');
    }
    $owned = woo_release_owned_roots($base);
    if ($owned === []) {
        woo_release_fail('release carries no component root');
    }
    $rows = [];
    foreach ($owned as $root) {
        woo_release_rows($base, $root, $rows);
    }
    ksort($rows, SORT_STRING);
    return [
        'artifact_hash' => $artifact,
        'code_revision' => $revision,
        'files' => array_values($rows),
        'format' => 'wprism-code-release-descriptor/v1',
        'generation' => $generation,
        'owned_roots' => $owned,
        'release_id' => $release,
        'role' => $role,
    ];
}

/** @param array<string,mixed> $descriptor */
function woo_release_verify(string $releaseRoot, array $descriptor): void {
    woo_release_assert_id((string) ($descriptor['release_id'] ?? ''));
    $base = $releaseRoot . '/' . $descriptor['release_id'];
    if (is_link($base) || !is_dir($base)) {
        woo_release_fail('release is missing or unsafe');
    }
    $recorded = [];
    foreach ($descriptor['files'] as $entry) {
        $path = $base . '/' . $entry['path'];
        $recorded[$entry['path']] = true;
        if (is_link($path)) {
            woo_release_fail('symlink refused');
        }
        if ($entry['type'] === 'directory') {
            if (!is_dir($path)) {
                woo_release_fail('descriptor directory missing');
            }
        } elseif (!is_file($path)
            || !hash_equals((string) $entry['sha256'], (string) hash_file('sha256', $path))) {
            woo_release_fail('descriptor file hash mismatch');
        }
    }
    foreach ($descriptor['owned_roots'] as $owned) {
        if (!isset($recorded[$owned])) {
            woo_release_fail('owned root is not recorded');
        }
        $root = $base . '/' . $owned;
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($base) + 1);
            if ($entry->isLink() || !isset($recorded[$relative])) {
                woo_release_fail('unrecorded or linked owned path refused');
            }
        }
    }
}

/** Remove only one provider-selected immutable release beneath releaseRoot. */
function woo_release_remove(string $releaseRoot, string $release): void {
    woo_release_assert_id($release);
    $directory = $releaseRoot . '/' . $release;
    if (!is_dir($directory) || is_link($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            if (!unlink($entry->getPathname())) {
                woo_release_fail('release file deletion failed');
            }
            continue;
        }
        if (!$entry->isDir() || !rmdir($entry->getPathname())) {
            woo_release_fail('release directory deletion failed');
        }
    }
    if (!rmdir($directory)) {
        woo_release_fail('release root deletion failed');
    }
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($request) || array_is_list($request)) {
    woo_release_fail('request must be an object');
}
$releaseRoot = $argv[2] ?? '';
$pointer = $argv[3] ?? '';
if ($releaseRoot === '' || $pointer === '') {
    woo_release_fail('usage: provider STATE RELEASE_ROOT POINTER');
}
$priorRelease = is_file($pointer) ? trim((string) file_get_contents($pointer)) : 'release-prior';
$desiredRelease = 'release-desired-' . (string) ($request['generation'] ?? 0);
$action = (string) ($request['action'] ?? '');
$base = ['format' => 'wprism-code-release-provider-response/v1'];

if ($action === 'probe') {
    woo_release_output($base + [
        'atomic_pointer' => true,
        'available' => true,
        'build_resolution_off_target' => true,
        'immutable_releases' => true,
        'mutable_resolution' => false,
        'plan_bound_code_inventory' => true,
        'provider_id' => 'woocommerce-live-release',
        'provider_version' => '1.0.0',
        'state' => 'ready',
        'target_generation_fenced' => true,
        'target_git_history' => false,
        'target_registry_credentials' => false,
        'verified_descriptors' => true,
    ]);
}

if ($action === 'prepare') {
    if (($request['format'] ?? '') !== 'wprism-code-release-provider-request/v2'
        || !is_array($request['desired_code_inventory'] ?? null)) {
        woo_release_fail('unsupported prepare request format');
    }
    $selected = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
    if ($selected !== $priorRelease
        || !is_dir($releaseRoot . '/' . $priorRelease . '/wp-content')
        || !is_dir($releaseRoot . '/' . $desiredRelease . '/wp-content')) {
        woo_release_fail('immutable prior or desired release is unavailable');
    }
    $prior = woo_release_descriptor(
        'prior',
        $releaseRoot,
        $priorRelease,
        hash('sha256', 'prior-artifact'),
        hash('sha256', 'prior-code'),
        max(0, (int) $request['generation'] - 1)
    );
    $desired = woo_release_descriptor(
        'desired',
        $releaseRoot,
        $desiredRelease,
        (string) $request['artifact_hash'],
        (string) $request['desired_code_revision'],
        (int) $request['generation']
    );
    $priorBytes = woo_release_canonical($prior) . "\n";
    $desiredBytes = woo_release_canonical($desired) . "\n";
    woo_release_atomic_write((string) $request['prior_descriptor_path'], $priorBytes);
    woo_release_atomic_write((string) $request['desired_descriptor_path'], $desiredBytes);
    woo_release_verify($releaseRoot, $prior);
    woo_release_verify($releaseRoot, $desired);
    woo_release_output($base + [
        'atomic_pointer' => true,
        'available' => true,
        'build_resolution_off_target' => true,
        'desired_descriptor_path' => $request['desired_descriptor_path'],
        'desired_descriptor_sha256' => hash('sha256', $desiredBytes),
        'desired_pointer_sha256' => woo_release_pointer_hash($desiredRelease),
        'desired_release_id' => $desiredRelease,
        'immutable_releases' => true,
        'mutable_resolution' => false,
        'plan_bound_code_inventory' => true,
        'prior_descriptor_path' => $request['prior_descriptor_path'],
        'prior_descriptor_sha256' => hash('sha256', $priorBytes),
        'prior_pointer_sha256' => woo_release_pointer_hash($priorRelease),
        'prior_release_id' => $priorRelease,
        'provider_id' => 'woocommerce-live-release',
        'provider_version' => '1.0.0',
        'state' => 'prepared',
        'target_generation' => (int) $request['generation'],
        'target_generation_fenced' => true,
        'target_git_history' => false,
        'target_registry_credentials' => false,
        'verified_descriptors' => true,
    ]);
}

if (in_array($action, ['select_desired', 'restore_prior', 'verify_desired', 'verify_prior'], true)) {
    $role = str_contains($action, 'desired') ? 'desired' : 'prior';
    $descriptorPath = (string) $request[$role . '_descriptor_path'];
    $descriptorBytes = (string) file_get_contents($descriptorPath);
    $descriptor = json_decode($descriptorBytes, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($descriptor)
        || !hash_equals((string) $request[$role . '_descriptor_sha256'], hash('sha256', $descriptorBytes))) {
        woo_release_fail('descriptor changed');
    }
    $target = (string) $descriptor['release_id'];
    $current = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
    $isVerify = str_starts_with($action, 'verify_');
    woo_release_verify($releaseRoot, $descriptor);
    if (!$isVerify) {
        $otherRole = $role === 'desired' ? 'prior' : 'desired';
        $other = json_decode((string) file_get_contents((string) $request[$otherRole . '_descriptor_path']), true, 512, JSON_THROW_ON_ERROR);
        $from = is_array($other) ? (string) ($other['release_id'] ?? '') : '';
        if ($current !== $from && $current !== $target) {
            woo_release_fail('concurrent pointer writer refused');
        }
        if ($current !== $target) {
            woo_release_atomic_write($pointer, $target . "\n");
        }
    } elseif ($current !== $target) {
        woo_release_fail('selected pointer changed before verification');
    }
    woo_release_verify($releaseRoot, $descriptor);
    $result = hash('sha256', woo_release_canonical([
        'descriptor_sha256' => hash('sha256', $descriptorBytes),
        'generation' => $request['generation'],
        'pointer_sha256' => woo_release_pointer_hash($target),
        'release_id' => $target,
        'target_id' => $request['target_id'],
    ]));
    woo_release_output($base + [
        'action' => $action,
        'atomic_pointer' => true,
        'available' => true,
        'descriptor_sha256' => hash('sha256', $descriptorBytes),
        'generation' => (int) $request['generation'],
        'no_unrecorded_owned_paths' => true,
        'pointer_sha256' => woo_release_pointer_hash($target),
        'provider_id' => 'woocommerce-live-release',
        'provider_version' => '1.0.0',
        'release_id' => $target,
        'result_sha256' => $result,
        'state' => $isVerify ? 'verified' : 'selected',
        'symlinks_absent' => true,
        'target_id' => $request['target_id'],
        'verified_file_inventory' => true,
    ]);
}

if ($action === 'delete_prior') {
    $release = (string) ($request['release_id'] ?? '');
    if (is_file($pointer) && trim((string) file_get_contents($pointer)) === $release) {
        woo_release_fail('cannot delete selected prior release');
    }
    woo_release_remove($releaseRoot, $release);
    woo_release_output($base + [
        'action' => 'delete_prior',
        'available' => true,
        'prior_release_absent' => !is_dir($releaseRoot . '/' . $release),
        'provider_id' => 'woocommerce-live-release',
        'provider_version' => '1.0.0',
        'state' => 'deleted',
    ]);
}

woo_release_fail('unsupported fixture action');
