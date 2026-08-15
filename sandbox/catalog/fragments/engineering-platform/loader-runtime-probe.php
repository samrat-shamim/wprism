#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @return never */
function loader_runtime_fail(string $message): void
{
    fwrite(STDERR, 'loader-runtime: ' . $message . "\n");
    exit(2);
}

/**
 * @param list<string> $arguments
 * @return array<string,string>
 */
function loader_runtime_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (substr($argument, 0, 2) !== '--' || strpos($argument, '=') === false) {
            loader_runtime_fail('arguments must use --name=value');
        }
        $parts = explode('=', substr($argument, 2), 2);
        if ($parts[0] === '' || $parts[1] === '' || isset($options[$parts[0]])) {
            loader_runtime_fail('arguments must be nonempty and unique');
        }
        $options[$parts[0]] = $parts[1];
    }
    return $options;
}

function loader_runtime_candidate(): string
{
    $candidate = getenv('CANDIDATE_SHA');
    if (!is_string($candidate) || preg_match('/^[a-f0-9]{40}$/D', $candidate) !== 1) {
        loader_runtime_fail('CANDIDATE_SHA must be the exact checked-out commit');
    }
    return $candidate;
}

function loader_runtime_result_path(string $root, string $relative): string
{
    if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $relative) !== 1
        || strpos($relative, '..') !== false) {
        loader_runtime_fail('result path must be a safe artifacts-relative file');
    }
    return $root . '/' . $relative;
}

/** @param array<string,mixed> $receipt */
function loader_runtime_publish(string $root, string $relative, array $receipt): void
{
    $path = loader_runtime_result_path($root, $relative);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        loader_runtime_fail('cannot create result directory');
    }
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($bytes)) {
        loader_runtime_fail('cannot encode result');
    }
    $bytes .= "\n";
    $temporary = tempnam(dirname($path), '.loader-runtime.');
    if (!is_string($temporary)) {
        loader_runtime_fail('cannot create result temporary file');
    }
    if (file_put_contents($temporary, $bytes) !== strlen($bytes)
        || !chmod($temporary, 0600)
        || !rename($temporary, $path)) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
        loader_runtime_fail('cannot publish result');
    }
}

/** @return array<string,mixed> */
function loader_runtime_read(string $root, string $relative): array
{
    $bytes = file_get_contents(loader_runtime_result_path($root, $relative));
    $decoded = is_string($bytes) ? json_decode($bytes, true) : null;
    if (!is_array($decoded)) {
        loader_runtime_fail('input receipt is absent or malformed: ' . $relative);
    }
    $receipt = [];
    foreach ($decoded as $key => $value) {
        if (!is_string($key)) {
            loader_runtime_fail('input receipt has a non-string field: ' . $relative);
        }
        $receipt[$key] = $value;
    }
    return $receipt;
}

/** @param array<string,string> $options */
function loader_runtime_probe(string $root, array $options): void
{
    foreach (['expected-version', 'layout', 'loader', 'result'] as $required) {
        if (!isset($options[$required])) {
            loader_runtime_fail('probe requires --' . $required);
        }
    }
    if (count($options) !== 4
        || preg_match('/^8\.[0-4]$/D', $options['expected-version']) !== 1
        || !in_array($options['layout'], ['source', 'dist'], true)) {
        loader_runtime_fail('probe options are invalid');
    }
    $runtime = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidate = loader_runtime_candidate();
    $expectedLoader = $options['layout'] === 'source'
        ? $root . '/agent/duo.php'
        : $root . '/artifacts/dist/payload/agent/duo-loader.php';
    $loader = realpath($options['loader']);
    $expected = realpath($expectedLoader);
    if ($runtime !== $options['expected-version'] || $loader === false || $expected === false || $loader !== $expected) {
        loader_runtime_fail('runtime version or loader layout does not match the declared matrix row');
    }
    $before = count(spl_autoload_functions());
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname($loader) . '/');
    }
    require $loader;
    $after = count(spl_autoload_functions());
    $behavior = [];
    $pass = false;
    if (PHP_VERSION_ID < 80200) {
        $status = defined('DUO_AGENT_RUNTIME_STATUS') ? constant('DUO_AGENT_RUNTIME_STATUS') : null;
        $behavior = [
            'autoloaders_registered' => $after - $before,
            'runtime_status' => $status,
        ];
        $pass = $status === 'unsupported_php_' . PHP_MAJOR_VERSION . '_' . PHP_MINOR_VERSION
            && $after === $before
            && !defined('DUO_AGENT_VERSION');
    } else {
        $engineBeforeReference = class_exists('Duo\\Apply', false) || class_exists('Duo\\Capture', false);
        $lazyClass = class_exists('Duo\\Canon');
        $behavior = [
            'autoloaders_registered' => $after - $before,
            'engine_loaded_before_reference' => $engineBeforeReference,
            'explicit_lazy_class' => $lazyClass,
            'runtime_status_defined' => defined('DUO_AGENT_RUNTIME_STATUS'),
        ];
        $pass = !$engineBeforeReference && $lazyClass && $after === $before + 1 && !defined('DUO_AGENT_RUNTIME_STATUS');
    }
    $receipt = [
        'format' => 'duo-loader-runtime-probe/v1',
        'state' => $pass ? 'pass' : 'fail',
        'candidate_sha' => $candidate,
        'layout' => $options['layout'],
        'runtime_php' => PHP_VERSION,
        'runtime_php_id' => PHP_VERSION_ID,
        'runtime_php_sha256' => loader_runtime_digest(PHP_BINARY),
        'expected_major_minor' => $options['expected-version'],
        'loader_sha256' => loader_runtime_digest($loader),
        'behavior' => $behavior,
    ];
    loader_runtime_publish($root, $options['result'], $receipt);
    exit($pass ? 0 : 1);
}

/** @param array<string,string> $options */
function loader_runtime_aggregate(string $root, array $options): void
{
    foreach (['expected-version', 'source-result', 'dist-result', 'result'] as $required) {
        if (!isset($options[$required])) {
            loader_runtime_fail('aggregate requires --' . $required);
        }
    }
    $allowed = ['expected-version', 'source-result', 'dist-result', 'full-result', 'result'];
    if (count(array_diff(array_keys($options), $allowed)) !== 0
        || preg_match('/^8\.[0-4]$/D', $options['expected-version']) !== 1) {
        loader_runtime_fail('aggregate options are invalid');
    }
    $candidate = loader_runtime_candidate();
    $bindings = [];
    foreach (['source', 'dist'] as $layout) {
        $receipt = loader_runtime_read($root, $options[$layout . '-result']);
        if (($receipt['format'] ?? null) !== 'duo-loader-runtime-probe/v1'
            || ($receipt['state'] ?? null) !== 'pass'
            || ($receipt['candidate_sha'] ?? null) !== $candidate
            || ($receipt['layout'] ?? null) !== $layout
            || ($receipt['expected_major_minor'] ?? null) !== $options['expected-version']) {
            loader_runtime_fail($layout . ' runtime probe did not pass for the exact candidate and matrix row');
        }
        $bindings[$layout] = loader_runtime_digest(loader_runtime_result_path($root, $options[$layout . '-result']));
    }
    $full = null;
    if (isset($options['full-result'])) {
        $receipt = loader_runtime_read($root, $options['full-result']);
        $runtimePhp = $receipt['runtime_php'] ?? null;
        if (($receipt['format'] ?? null) !== 'duo-loader-check/v1'
            || ($receipt['state'] ?? null) !== 'pass'
            || ($receipt['candidate_sha'] ?? null) !== $candidate
            || !is_string($runtimePhp)
            || strpos($runtimePhp, $options['expected-version'] . '.') !== 0) {
            loader_runtime_fail('full loader check did not pass for the exact candidate and runtime');
        }
        $full = loader_runtime_digest(loader_runtime_result_path($root, $options['full-result']));
    } elseif (in_array($options['expected-version'], ['8.2', '8.3'], true)) {
        loader_runtime_fail('PHP 8.2 and 8.3 rows require the full source/dist loader check');
    }
    $receipt = [
        'format' => 'duo-loader-runtime-pair/v1',
        'state' => 'pass',
        'candidate_sha' => $candidate,
        'runtime_major_minor' => $options['expected-version'],
        'probe_receipt_sha256' => $bindings,
        'full_loader_receipt_sha256' => $full,
    ];
    loader_runtime_publish($root, $options['result'], $receipt);
}

/** @param array<string,string> $options */
function loader_runtime_verdict(string $root, array $options): void
{
    if (!isset($options['result'])) {
        loader_runtime_fail('verdict requires --result');
    }
    $candidate = loader_runtime_candidate();
    $digests = [];
    foreach (['8.0', '8.1', '8.2', '8.3', '8.4'] as $version) {
        $key = 'runtime-' . $version;
        if (!isset($options[$key])) {
            loader_runtime_fail('verdict is missing --' . $key);
        }
        $receipt = loader_runtime_read($root, $options[$key]);
        if (($receipt['format'] ?? null) !== 'duo-loader-runtime-pair/v1'
            || ($receipt['state'] ?? null) !== 'pass'
            || ($receipt['candidate_sha'] ?? null) !== $candidate
            || ($receipt['runtime_major_minor'] ?? null) !== $version) {
            loader_runtime_fail('runtime pair did not pass for PHP ' . $version);
        }
        $digests[$version] = loader_runtime_digest(loader_runtime_result_path($root, $options[$key]));
    }
    if (count($options) !== 6) {
        loader_runtime_fail('verdict received an unexpected option');
    }
    loader_runtime_publish($root, $options['result'], [
        'format' => 'duo-loader-runtime-matrix/v1',
        'state' => 'pass',
        'candidate_sha' => $candidate,
        'runtime_receipt_sha256' => $digests,
    ]);
}

function loader_runtime_digest(string $path): string
{
    $digest = hash_file('sha256', $path);
    if (!is_string($digest)) {
        loader_runtime_fail('cannot digest matrix input');
    }
    return 'sha256:' . $digest;
}

$root = dirname(__DIR__, 4);
$mode = $argv[1] ?? null;
$options = loader_runtime_options(array_slice($argv, 2));
if ($mode === 'probe') {
    loader_runtime_probe($root, $options);
}
if ($mode === 'aggregate') {
    loader_runtime_aggregate($root, $options);
    exit(0);
}
if ($mode === 'verdict') {
    loader_runtime_verdict($root, $options);
    exit(0);
}
loader_runtime_fail('expected probe, aggregate, or verdict mode');
