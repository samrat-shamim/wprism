#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Import and verify a signed, site-local adapter certification.
 *
 * Usage:
 *   php scripts/adapter-certification.php sign \
 *     --manifest-dir=manifests --repo=/site/repository --name=example \
 *     --bundle=/review/bundle --evidence-repo=/review/checkout \
 *     --authority=review-key --secret-key-file=/secure/review-key
 *   php scripts/adapter-certification.php verify \
 *     --manifest-dir=manifests --repo=/site/repository --name=example
 *   php scripts/adapter-certification.php verify-frozen \
 *     --manifest-dir=manifests --name=example --manifest=/snapshot/example.json \
 *     --envelope=/snapshot/example-certification.json
 *
 * `sign` writes only the derived adapters/certifications/<name>.json path.
 * It never accepts an arbitrary output path or prints the secret/certificate
 * body.  The class verifies all bundle assets and evidence-repository bound
 * inputs before the private key is used, then this command immediately
 * verifies the written certificate through the live verifier.
 */

$repoRoot = dirname(__DIR__);
if (!defined('DUO_AGENT_VERSION') || !defined('DUO_SPEC_VERSION')) {
    $agentSource = (string) file_get_contents($repoRoot . '/agent/duo.php');
    if (!defined('DUO_AGENT_VERSION')
        && preg_match("/define\\('DUO_AGENT_VERSION', '([^']+)'\\)/", $agentSource, $match) === 1) {
        define('DUO_AGENT_VERSION', $match[1]);
    }
    if (!defined('DUO_SPEC_VERSION')
        && preg_match("/define\\('DUO_SPEC_VERSION', ([0-9]+)\\)/", $agentSource, $match) === 1) {
        define('DUO_SPEC_VERSION', (int) $match[1]);
    }
}

require_once $repoRoot . '/agent/src/Adapter/AdapterCertification.php';

use Duo\AdapterCertification;
use Duo\Canon;

/** @return array{0:string,1:array<string,string>} */
function cert_cli_args(array $argv): array {
    $command = $argv[1] ?? '';
    if (!is_string($command) || $command === '') {
        throw new RuntimeException('usage: sign, verify, or verify-frozen is required');
    }
    $out = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!is_string($argument) || !str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new RuntimeException('arguments must use --name=value form; got ' . var_export($argument, true));
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if ($key === '' || $value === '' || isset($out[$key])) {
            throw new RuntimeException("malformed or duplicate argument --$key");
        }
        $out[$key] = $value;
    }
    return [$command, $out];
}

/** @param list<string> $required @param list<string> $optional */
function cert_cli_require(array $args, array $required, array $optional = []): void {
    $allowed = array_fill_keys(array_merge($required, $optional), true);
    foreach (array_keys($args) as $key) {
        if (!isset($allowed[$key])) {
            throw new RuntimeException("unsupported argument --$key");
        }
    }
    foreach ($required as $key) {
        if (!isset($args[$key]) || $args[$key] === '') {
            throw new RuntimeException("missing required argument --$key");
        }
    }
}

/** @return array<string,mixed> */
function cert_cli_manifest(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read manifest: $path");
    }
    try {
        $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('manifest is not valid JSON: ' . $e->getMessage());
    }
    if (!is_object($typed) || !is_array($decoded) || array_is_list($decoded)
        || !hash_equals(Canon::encode($typed), $raw)) {
        throw new RuntimeException("manifest must be a canonical JSON object: $path");
    }
    return $decoded;
}

/** Validate the canonical name before it is ever interpolated into a path. */
function cert_cli_adapter_paths(string $repo, string $name): array {
    $certificate = AdapterCertification::certificatePath($repo, $name);
    $adapters = dirname(dirname($certificate));
    return [
        'adapter' => $adapters . '/' . $name . '.json',
        'certificate' => $certificate,
    ];
}

/** @return array<string,mixed> */
function cert_cli_canonical_object(string $path, string $label): array {
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read $label: $path");
    }
    try {
        $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("$label is not valid JSON: " . $e->getMessage());
    }
    if (!is_object($typed) || !is_array($decoded) || array_is_list($decoded)
        || !hash_equals(Canon::encode($typed), $raw)) {
        throw new RuntimeException("$label must be a canonical JSON object: $path");
    }
    return $decoded;
}

function cert_cli_certificate_dir(string $repo): string {
    $root = realpath($repo);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException("site repository is absent or not a directory: $repo");
    }
    $adapters = $root . '/adapters';
    if (!is_dir($adapters) || is_link($adapters) || realpath($adapters) !== $adapters) {
        throw new RuntimeException('site adapters must be a real adapters directory inside the repository');
    }
    $directory = $adapters . '/certifications';
    if (!file_exists($directory) && !mkdir($directory, 0755)) {
        throw new RuntimeException("cannot create certification directory: $directory");
    }
    if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
        throw new RuntimeException('site adapter certifications must be a real certifications directory inside adapters');
    }
    return $directory;
}

function cert_cli_write_certificate(string $repo, string $name, string $certificate): string {
    $directory = cert_cli_certificate_dir($repo);
    $root = realpath($repo);
    if ($root === false) {
        throw new RuntimeException("site repository became unavailable while writing its certification: $repo");
    }
    $path = AdapterCertification::certificatePath($root, $name);
    if (dirname($path) !== $directory) {
        throw new RuntimeException('derived certification path escapes the canonical site certification directory');
    }
    $temporary = tempnam($directory, '.duo-certification-');
    if ($temporary === false) {
        throw new RuntimeException('cannot allocate a temporary certification file');
    }
    try {
        if (file_put_contents($temporary, $certificate, LOCK_EX) === false
            || !chmod($temporary, 0644)
            || !rename($temporary, $path)) {
            throw new RuntimeException("cannot atomically write certification: $path");
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    return $path;
}

function cert_cli_secret_file(string $path): string {
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException("secret-key-file must be a regular non-symlink file: $path");
    }
    $permissions = fileperms($path);
    if ($permissions === false || (($permissions & 0077) !== 0)) {
        throw new RuntimeException("secret-key-file must not be group/world accessible: $path");
    }
    $secret = file_get_contents($path);
    if ($secret === false || $secret === '') {
        throw new RuntimeException("cannot read secret-key-file: $path");
    }
    return $secret;
}

try {
    [$command, $args] = cert_cli_args($argv);
    switch ($command) {
        case 'sign':
            cert_cli_require($args, [
                'manifest-dir', 'repo', 'name', 'bundle', 'evidence-repo', 'authority', 'secret-key-file',
            ]);
            AdapterCertification::certificatePath($args['repo'], $args['name']);
            $secret = cert_cli_secret_file($args['secret-key-file']);
            $certificate = AdapterCertification::sign(
                $args['manifest-dir'],
                $args['repo'],
                $args['name'],
                $args['bundle'],
                $args['evidence-repo'],
                $args['authority'],
                $secret
            );
            $path = cert_cli_write_certificate($args['repo'], $args['name'], $certificate);
            $paths = cert_cli_adapter_paths($args['repo'], $args['name']);
            $manifest = cert_cli_manifest($paths['adapter']);
            $verified = AdapterCertification::verifyFile(
                $args['manifest-dir'], $args['repo'], $args['name'], $manifest, $path
            );
            $summary = AdapterCertification::certificateSummary($verified);
            $summary['certificate_path'] = 'adapters/certifications/' . $args['name'] . '.json';
            fwrite(STDOUT, Canon::encode($summary));
            break;

        case 'verify':
            cert_cli_require($args, ['manifest-dir', 'repo', 'name']);
            $paths = cert_cli_adapter_paths($args['repo'], $args['name']);
            $manifest = cert_cli_manifest($paths['adapter']);
            $verified = AdapterCertification::verifyFile(
                $args['manifest-dir'],
                $args['repo'],
                $args['name'],
                $manifest,
                $paths['certificate']
            );
            fwrite(STDOUT, Canon::encode(AdapterCertification::certificateSummary($verified)));
            break;

        case 'verify-frozen':
            cert_cli_require($args, ['manifest-dir', 'name', 'manifest', 'envelope']);
            // The frozen command receives an explicit snapshot path, but its
            // name still belongs to the same canonical certificate namespace.
            // Validate it before opening either caller-supplied JSON file.
            AdapterCertification::certificatePath('.', $args['name']);
            $verified = AdapterCertification::verifyFrozen(
                $args['manifest-dir'],
                $args['name'],
                cert_cli_manifest($args['manifest']),
                cert_cli_canonical_object($args['envelope'], 'frozen certification envelope')
            );
            fwrite(STDOUT, Canon::encode(AdapterCertification::certificateSummary($verified)));
            break;

        default:
            throw new RuntimeException("unknown command '$command'; expected sign, verify, or verify-frozen");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'adapter certification: ' . $e->getMessage() . "\n");
    exit(1);
}
