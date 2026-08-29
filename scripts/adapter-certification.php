#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Import and verify a signed, site-local adapter certification.
 *
 * Usage:
 *   php scripts/adapter-certification.php sign \
 *     --manifest-dir=. --repo=/site/repository --name=example \
 *     --bundle=/review/bundle --evidence-repo=/review/checkout \
 *     --authority=review-key --secret-key-file=/secure/review-key
 *   php scripts/adapter-certification.php sign-site \
 *     --manifest-dir=. --repo=/site/repository --name=example \
 *     --authority=acme-ops --secret-key-file=/secure/acme-ops \
 *     --reason='grammar verified by the site operator; not exercised'
 *   php scripts/adapter-certification.php verify \
 *     --manifest-dir=. --repo=/site/repository --name=example
 *   php scripts/adapter-certification.php verify-frozen \
 *     --manifest-dir=. --name=example --manifest=/snapshot/example.json \
 *     --envelope=/snapshot/example-certification.json
 *   php scripts/adapter-certification.php authorities-sign \
 *     --authorities=platform/adapter-library/capabilities/adapter-authorities.json \
 *     --authority=acme-1a2b3c4d5e6f --secret-key-file=/secure/acme-root
 *
 * `sign` writes only the derived adapters/certifications/<name>.json path.
 * It never accepts an arbitrary output path or prints the secret/certificate
 * body.  The class verifies all bundle assets and evidence-repository bound
 * inputs before the private key is used, then this command immediately
 * verifies the written certificate through the live verifier.
 * `--manifest-dir` retains its wire-era option name, but `.` above is the
 * source root containing adapter-packages/ and platform/adapter-library/.
 *
 * The two mutation-boundary primitives -- the atomic derived-path certificate
 * write and the mode-checked secret-key read -- now live in
 * cli/src/Adapter/AdapterCertify.php, which `wprism adapter certify` (round-3 T6
 * SS3.5) is built on.  This script keeps its own reviewer-facing argument
 * grammar and its `--bundle`/`--evidence-repo` inputs, which the operator verb
 * deliberately does not expose; it just stopped carrying a second copy of the
 * hardening.  One copy means a fix to the 0600 check or the atomic rename
 * lands for both callers at once.
 */

$repoRoot = dirname(__DIR__);
if (!defined('WPRISM_AGENT_VERSION') || !defined('WPRISM_SPEC_VERSION')) {
    $agentSource = (string) file_get_contents($repoRoot . '/agent/wprism.php');
    if (!defined('WPRISM_AGENT_VERSION')
        && preg_match("/define\\('WPRISM_AGENT_VERSION', '([^']+)'\\)/", $agentSource, $match) === 1) {
        define('WPRISM_AGENT_VERSION', $match[1]);
    }
    if (!defined('WPRISM_SPEC_VERSION')
        && preg_match("/define\\('WPRISM_SPEC_VERSION', ([0-9]+)\\)/", $agentSource, $match) === 1) {
        define('WPRISM_SPEC_VERSION', (int) $match[1]);
    }
}

require_once $repoRoot . '/agent/src/Adapter/AdapterCertification.php';
require_once $repoRoot . '/cli/src/Adapter/AdapterCertify.php';

use WPrism\AdapterCertification;
use WPrism\Canon;
use WPrism\Orchestrator\AdapterCertify;

/** @return array{0:string,1:array<string,string>} */
function cert_cli_args(array $argv): array {
    $command = $argv[1] ?? '';
    if (!is_string($command) || $command === '') {
        throw new RuntimeException('usage: sign, verify, verify-frozen, or authorities-sign is required');
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

/**
 * The certificate write and the secret-key read are AdapterCertify's, not this
 * script's: `wprism adapter certify` performs the same two mutations under the
 * same rules, and a second implementation of an atomic write or a 0600 check is
 * a second thing to get wrong.  See that class for what each one refuses.
 */
function cert_cli_write_certificate(string $repo, string $name, string $certificate): string {
    return AdapterCertify::writeCertificate($repo, $name, $certificate);
}

/** A real regular file, resolved, so a signer never reads through a link. */
function cert_cli_existing_file(string $path, string $flag): string {
    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved) || is_link($path)) {
        throw new RuntimeException("$flag must name an existing regular file: $path");
    }
    return $resolved;
}

function cert_cli_secret_file(string $path): string {
    // The class returns RAW key bytes; AdapterCertification::sign() takes the
    // encoded form it decodes itself, so re-encode rather than widening the
    // signer's input grammar for one caller.
    return base64_encode(AdapterCertify::readSecretKey($path));
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

        case 'sign-site':
            // The site profile builds its own bundle (AdapterCertification::
            // sign_site()), so there is no --bundle and no --evidence-repo to
            // pass: an unexercised bundle's assets are already inside the
            // signed statement and never exist as files.
            cert_cli_require($args, [
                'manifest-dir', 'repo', 'name', 'authority', 'secret-key-file', 'reason',
            ]);
            AdapterCertification::certificatePath($args['repo'], $args['name']);
            $secret = cert_cli_secret_file($args['secret-key-file']);
            $certificate = AdapterCertification::sign_site(
                $args['manifest-dir'],
                $args['repo'],
                $args['name'],
                $args['authority'],
                $secret,
                $args['reason']
            );
            $path = cert_cli_write_certificate($args['repo'], $args['name'], $certificate);
            $paths = cert_cli_adapter_paths($args['repo'], $args['name']);
            $verified = AdapterCertification::verifyFile(
                $args['manifest-dir'], $args['repo'], $args['name'], cert_cli_manifest($paths['adapter']), $path
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

        case 'authorities-sign':
            // The v2 authorities envelope (spec § v3.7 change (d)). Rewrites
            // the named document in place with its `signature` member, through
            // AdapterCertification::signAuthorities(), which validates every
            // record before the private key is touched — so a registry that
            // could not be READ can never be signed. Only a
            // wprism-adapter-authorities/v2 document has an envelope at all; a v1
            // one is refused by name rather than left unchanged.
            cert_cli_require($args, ['authorities', 'authority', 'secret-key-file']);
            $path = realpath($args['authorities']);
            if ($path === false || !is_file($path) || is_link($path)) {
                throw new RuntimeException(
                    "--authorities must name an existing regular file: {$args['authorities']}"
                );
            }
            $signed = AdapterCertification::signAuthorities(
                (string) file_get_contents($path),
                $args['authority'],
                cert_cli_secret_file($args['secret-key-file'])
            );
            if (file_put_contents($path, $signed) === false) {
                throw new RuntimeException("could not write the signed authorities document: $path");
            }
            fwrite(STDOUT, Canon::encode([
                'authorities_path' => $path,
                'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
                'signed_by' => $args['authority'],
            ]));
            break;

        case 'delegation-sign':
            // Depth-1 delegation (spec § v3.8). Signs the statement it is
            // handed and prints the `{signature, statement}` object an operator
            // installs under adapters/delegations.json — printed rather than
            // written, because one file holds several delegations and the
            // producer has no business deciding which ones a repository keeps.
            cert_cli_require($args, ['statement', 'authority', 'secret-key-file']);
            fwrite(STDOUT, AdapterCertification::signDelegation(
                (string) file_get_contents(cert_cli_existing_file($args['statement'], '--statement')),
                $args['authority'],
                cert_cli_secret_file($args['secret-key-file'])
            ));
            break;

        case 'revocations-sign':
            // The out-of-band revocation channel (spec § v3.8). Prints the whole
            // installable document; the operator drops it at the durable
            // WPMU_PLUGIN_DIR/wprism-control/adapter-revocations.json path, which
            // live and frozen verification both read beside the embedded library.
            cert_cli_require($args, ['statement', 'authority', 'secret-key-file']);
            fwrite(STDOUT, AdapterCertification::signRevocations(
                (string) file_get_contents(cert_cli_existing_file($args['statement'], '--statement')),
                $args['authority'],
                cert_cli_secret_file($args['secret-key-file'])
            ));
            break;

        default:
            throw new RuntimeException(
                "unknown command '$command'; expected sign, sign-site, verify, verify-frozen, authorities-sign,"
                . ' delegation-sign, or revocations-sign'
            );
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'adapter certification: ' . $e->getMessage() . "\n");
    exit(1);
}
