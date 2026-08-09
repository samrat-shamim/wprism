#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generate/check Duo's product capability registry and its public prose.
 *
 * Usage:
 *   php scripts/capability-registry.php import-bundle <bundle-dir|bundle.json>
 *   php scripts/capability-registry.php generate
 *   php scripts/capability-registry.php check
 *
 * The certification bundle is upstream evidence. This script stores a
 * compact checked-in attestation, verifies every bound input still matches,
 * then derives the registry, README summary, and compatibility document.
 * Generated prose can therefore drift only by making this check fail.
 */

$repo = dirname(__DIR__);
if (!defined('DUO_AGENT_VERSION')) {
    $agentSource = (string) file_get_contents($repo . '/agent/duo.php');
    if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $agentSource, $m) !== 1) {
        fwrite(STDERR, "could not resolve DUO_AGENT_VERSION\n");
        exit(1);
    }
    define('DUO_AGENT_VERSION', $m[1]);
}
if (!defined('DUO_SPEC_VERSION')) {
    $agentSource ??= (string) file_get_contents($repo . '/agent/duo.php');
    if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $agentSource, $m) !== 1) {
        fwrite(STDERR, "could not resolve DUO_SPEC_VERSION\n");
        exit(1);
    }
    define('DUO_SPEC_VERSION', (int) $m[1]);
}
function is_multisite(): bool { return false; }

require $repo . '/agent/src/Canon.php';
require $repo . '/agent/src/ManifestDispositions.php';
require $repo . '/agent/src/CapabilityRegistry.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\ManifestDispositions;

const EVIDENCE_FILE = '/manifests/capabilities/evidence.json';
const REGISTRY_FILE = '/manifests/capabilities/registry.json';
const DOC_FILE = '/docs/capabilities.md';
const README_FILE = '/README.md';
const README_BEGIN = '<!-- BEGIN GENERATED CAPABILITY SUMMARY -->';
const README_END = '<!-- END GENERATED CAPABILITY SUMMARY -->';

function cap_fail(string $message): never {
    fwrite(STDERR, "capability registry: $message\n");
    exit(1);
}

function cap_read_json(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("missing JSON file: $path");
    }
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException("JSON root must be an object: $path");
    }
    return $decoded;
}

function cap_sha(string $path): string {
    $digest = hash_file('sha256', $path);
    if ($digest === false) {
        throw new RuntimeException("could not hash $path");
    }
    return $digest;
}

function cap_bundle_digest(array $bundle): string {
    $unsigned = $bundle;
    unset($unsigned['bundle_digest']);
    // Certification bundles deliberately use compact canonical JSON, while
    // Canon::encode() is the pretty-printed repository-file representation.
    // Keep the builder's exact hash basis (certification-bundle.php::cert_json)
    // instead of accidentally inventing a second notion of bundle identity.
    $json = json_encode(
        Canon::normalize($unsigned),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return hash('sha256', $json . "\n");
}

function cap_import_bundle(string $repo, string $input): void {
    $file = is_dir($input) ? rtrim($input, '/') . '/bundle.json' : $input;
    $bundle = cap_read_json($file);
    $claimed = (string) ($bundle['bundle_digest'] ?? '');
    if (($bundle['schema_version'] ?? null) !== ManifestDispositions::BUNDLE_SCHEMA
        || !preg_match('/^[0-9a-f]{64}$/', $claimed)
        || !hash_equals($claimed, cap_bundle_digest($bundle))
        || ($bundle['verdict'] ?? null) !== 'pass') {
        throw new RuntimeException('bundle is malformed, failed, or has a mismatched digest');
    }
    foreach (($bundle['tests'] ?? []) as $test) {
        if (!is_array($test) || ($test['verdict'] ?? null) !== 'pass') {
            throw new RuntimeException('bundle has an absent or non-passing test');
        }
    }
    $evidence = [
        'format' => CapabilityRegistry::EVIDENCE_FORMAT,
        'status' => 'current',
        'bundle' => $bundle,
    ];
    $path = $repo . EVIDENCE_FILE;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('could not create capability registry directory');
    }
    Canon::write_file($path, Canon::encode($evidence));
    fwrite(STDOUT, "imported certification bundle $claimed\n");
}

/** @return list<array{path:string,reason:string}> */
function cap_expired_inputs(string $repo, array $evidence): array {
    $expired = [];
    $boundInputs = $evidence['bundle']['bound_inputs'] ?? $evidence['bound_inputs'] ?? [];
    foreach ($boundInputs as $input) {
        if (!is_array($input) || !is_string($input['path'] ?? null)) {
            $expired[] = ['path' => '?', 'reason' => 'malformed'];
            continue;
        }
        $relative = $input['path'];
        $path = $repo . '/' . $relative;
        if (!is_file($path)) {
            $expired[] = ['path' => $relative, 'reason' => 'missing'];
        } elseif (!hash_equals((string) ($input['sha256'] ?? ''), cap_sha($path))
            || (int) ($input['size'] ?? -1) !== filesize($path)) {
            $expired[] = ['path' => $relative, 'reason' => 'digest_mismatch'];
        }
    }
    return $expired;
}

/** @return array<string,array> */
function cap_manifests(string $dir): array {
    $out = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
        if (basename($file) === 'dispositions.json') {
            continue;
        }
        $manifest = Canon::decode(Canon::read_file($file));
        $out[(string) ($manifest['name'] ?? basename($file, '.json'))] = $manifest;
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** @return list<string> */
function cap_surfaces(array $manifest, array $disposition): array {
    $surfaces = [];
    foreach (array_merge(
        $disposition['capabilities']['entity_sections'] ?? [],
        $disposition['capabilities']['field_sections'] ?? []
    ) as $section) {
        $surfaces[] = (string) $section;
        $value = $manifest[$section] ?? null;
        if (is_array($value) && !array_is_list($value)) {
            foreach (array_keys($value) as $key) {
                $surfaces[] = $section . '.' . $key;
            }
        }
    }
    foreach (($disposition['capabilities']['deletion_semantics']['supported'] ?? []) as $selector) {
        $surfaces[] = 'deletions.' . $selector;
    }
    $surfaces = array_values(array_unique($surfaces, SORT_STRING));
    sort($surfaces, SORT_STRING);
    return $surfaces;
}

/** @return list<string> */
function cap_operations(array $disposition): array {
    $operations = $disposition['capabilities']['operations'] ?? [];
    if (in_array('deploy', $operations, true) && in_array('apply', $operations, true)) {
        $operations[] = 'promote';
    }
    $operations = array_values(array_unique($operations, SORT_STRING));
    sort($operations, SORT_STRING);
    return $operations;
}

function cap_build_registry(string $repo, bool $requireCurrent): array {
    $manifestDir = $repo . '/manifests';
    $dispositions = ManifestDispositions::load($manifestDir);
    if ($dispositions === null) {
        throw new RuntimeException('manifest dispositions are absent');
    }
    $dispositionData = $dispositions->data();
    $evidence = cap_read_json($repo . EVIDENCE_FILE);
    if (($evidence['format'] ?? null) !== CapabilityRegistry::EVIDENCE_FORMAT
        || !in_array($evidence['status'] ?? null, ['candidate', 'current'], true)) {
        throw new RuntimeException('capability evidence attestation is malformed');
    }
    $bundle = is_array($evidence['bundle'] ?? null) ? $evidence['bundle'] : $evidence;
    if (($bundle['schema_version'] ?? $bundle['bundle_schema'] ?? null) !== ManifestDispositions::BUNDLE_SCHEMA
        || !preg_match('/^[0-9a-f]{64}$/', (string) ($bundle['bundle_digest'] ?? ''))) {
        throw new RuntimeException('capability evidence bundle identity is malformed');
    }
    if (($evidence['status'] ?? null) === 'current'
        && (($bundle['verdict'] ?? null) !== 'pass'
            || !hash_equals((string) $bundle['bundle_digest'], cap_bundle_digest($bundle)))) {
        throw new RuntimeException('current evidence does not contain an intact passing bundle manifest');
    }
    $expired = cap_expired_inputs($repo, $evidence);
    if ($requireCurrent && (($evidence['status'] ?? null) !== 'current' || $expired !== [])) {
        $details = implode(', ', array_map(fn(array $r): string => $r['path'] . ':' . $r['reason'], $expired));
        throw new RuntimeException('certification evidence is expired' . ($details !== '' ? ": $details" : ''));
    }
    $status = ($evidence['status'] ?? null) === 'current' && $expired === [] ? 'current' : 'candidate';
    $evidence['status'] = $status;

    $compatibility = cap_read_json($repo . '/docs/compatibility-baseline.json');
    unset($compatibility['_comment']);
    $manifests = cap_manifests($manifestDir);
    $claims = [];
    foreach ($manifests as $name => $manifest) {
        $disposition = $dispositions->entry($name);
        if ($disposition === null) {
            throw new RuntimeException("manifest '$name' has no disposition");
        }
        $executionMode = $name === 'core'
            ? 'platform'
            : (str_starts_with($name, 'duo-') ? 'synthetic-fixture' : 'unmodified');
        $claimStatus = (string) $disposition['status'];
        $claims[$name] = [
            'name' => $name,
            'status' => $claimStatus,
            'reason' => (string) $disposition['reason'],
            'adapter_digest' => CapabilityRegistry::adapter_digest($manifest, $disposition, $manifestDir),
            'plugin_execution' => [
                'mode' => $executionMode,
                'status' => $claimStatus === 'certified' ? 'verified' : ($claimStatus === 'excluded' ? 'not-a-product-claim' : 'unverified'),
            ],
            'authored_state' => [
                'status' => $claimStatus === 'excluded' ? 'unsupported' : $claimStatus,
                'scope' => 'only the exact registered surfaces and operations below',
            ],
            'supported_versions' => $disposition['supported_versions'],
            'environment_assumptions' => [
                'site_mode' => 'single-site',
                'php' => $compatibility['php'],
                'database' => $compatibility['database'],
                'wordpress' => $compatibility['wordpress'],
            ],
            'operations' => cap_operations($disposition),
            'surfaces' => cap_surfaces($manifest, $disposition),
            'lifecycle_phases' => $disposition['capabilities']['lifecycle_phases'],
            'deletion_semantics' => $disposition['capabilities']['deletion_semantics'],
            'unsupported' => $disposition['unsupported'],
            'evidence' => [
                'status' => $status,
                'bundle_schema' => ManifestDispositions::BUNDLE_SCHEMA,
                'bundle_digest' => $bundle['bundle_digest'],
                'tests' => $disposition['evidence']['tests'] ?? [],
                'force_hatches' => $bundle['force_hatches'] ?? [],
            ],
        ];
    }

    $profiles = [];
    foreach ($dispositionData['profiles'] as $name => $profile) {
        $manifest = (string) $profile['manifest'];
        $profiles[$name] = [
            'name' => $name,
            'manifest' => $manifest,
            'status' => $profile['status'],
            'reason' => $profile['reason'],
            'adapter_digest' => $claims[$manifest]['adapter_digest'],
            'supported_versions' => $profile['supported_versions'],
            'scope' => $profile['scope'],
            'evidence' => [
                'status' => $status,
                'bundle_schema' => ManifestDispositions::BUNDLE_SCHEMA,
                'bundle_digest' => $bundle['bundle_digest'],
                'tests' => $profile['evidence']['tests'],
                'force_hatches' => $bundle['force_hatches'] ?? [],
            ],
        ];
        $claims[$manifest]['surfaces'][] = 'profile:' . $name;
        $claims[$manifest]['surfaces'] = array_values(array_unique($claims[$manifest]['surfaces'], SORT_STRING));
        sort($claims[$manifest]['surfaces'], SORT_STRING);
    }

    return [
        'format' => CapabilityRegistry::FORMAT,
        'generated_from' => [
            'dispositions_sha256' => cap_sha($repo . '/manifests/dispositions.json'),
            'compatibility_sha256' => cap_sha($repo . '/docs/compatibility-baseline.json'),
            'evidence_sha256' => cap_sha($repo . EVIDENCE_FILE),
        ],
        'platform' => [
            'agent_version' => DUO_AGENT_VERSION,
            'spec_version' => DUO_SPEC_VERSION,
            'site_mode' => 'single-site',
            'plugin_execution' => 'unmodified',
            'branchable_state' => 'only exact certified registry surfaces and operations',
            'compatibility' => $compatibility,
        ],
        'evidence' => [
            'format' => CapabilityRegistry::EVIDENCE_FORMAT,
            'status' => $status,
            'bundle_schema' => ManifestDispositions::BUNDLE_SCHEMA,
            'bundle_digest' => $bundle['bundle_digest'],
            'created_at' => $bundle['created_at'] ?? null,
            'git_revision' => $bundle['git_revision'] ?? null,
            'harness' => $bundle['harness'] ?? new stdClass(),
            'force_hatches' => $bundle['force_hatches'] ?? [],
            'environment_summary' => $bundle['environment_summary'] ?? new stdClass(),
            'tests' => array_map(
                fn(array $test): array => ['id' => (string) ($test['id'] ?? ''), 'verdict' => (string) ($test['verdict'] ?? '')],
                $bundle['tests'] ?? []
            ),
        ],
        'manifests' => $claims,
        'profiles' => $profiles,
    ];
}

function cap_version_label(array $supported): string {
    if (($supported['fixture'] ?? false) === true) {
        return 'fixture only';
    }
    if (isset($supported['plugin'], $supported['range']['min'], $supported['range']['max'])) {
        return $supported['plugin'] . ' >=' . $supported['range']['min'] . ' <' . $supported['range']['max'];
    }
    if (isset($supported['wordpress'])) {
        return 'WordPress ' . ($supported['wordpress']['source'] ?? 'evidence-bound');
    }
    return 'unbound';
}

function cap_docs(array $registry): string {
    $evidence = $registry['evidence'];
    $out = "# Generated capability registry\n\n";
    $out .= "<!-- Generated by scripts/capability-registry.php; do not hand-edit. -->\n\n";
    $out .= "Duo agent **" . $registry['platform']['agent_version'] . "** / repo spec **"
        . $registry['platform']['spec_version'] . "** is certified only for the exact boundaries below. "
        . "Plugins execute unmodified; that fact is separate from whether their authored state is branchable.\n\n";
    $out .= "Evidence bundle: `" . $evidence['bundle_digest'] . "` (" . strtoupper($evidence['status'])
        . ", source revision `" . $evidence['git_revision'] . "`). Missing entries and unlisted surfaces are unsupported.\n\n";
    $out .= "| Adapter | Authored state | Plugin execution | Versions | Operations |\n";
    $out .= "|---|---|---|---|---|\n";
    foreach ($registry['manifests'] as $claim) {
        if ($claim['status'] === 'excluded') {
            continue;
        }
        $out .= '| ' . $claim['name'] . ' | ' . $claim['authored_state']['status'] . ' | '
            . $claim['plugin_execution']['mode'] . ' / ' . $claim['plugin_execution']['status'] . ' | '
            . str_replace('|', '\\|', cap_version_label($claim['supported_versions'])) . ' | '
            . implode(', ', $claim['operations']) . " |\n";
    }
    $out .= "\n## Platform and environment boundary\n\n";
    $compatibility = $registry['platform']['compatibility'];
    $out .= '- Site mode: **' . $registry['platform']['site_mode'] . "**; multisite is unsupported.\n";
    $out .= '- WordPress: exact evidence-bound version **'
        . $compatibility['wordpress']['last_verified'] . "**.\n";
    $out .= '- PHP: **>=' . $compatibility['php']['min'] . ' <' . $compatibility['php']['max'] . "**.\n";
    $out .= '- Database: **' . $compatibility['database']['engine'] . ' >='
        . $compatibility['database']['min'] . ' <' . $compatibility['database']['max'] . "**.\n";
    $out .= "\n## Registered authored-state surfaces\n\n";
    foreach ($registry['manifests'] as $claim) {
        if ($claim['status'] === 'excluded') {
            continue;
        }
        $lifecyclePhases = $claim['lifecycle_phases'] === []
            ? 'none declared'
            : implode(', ', $claim['lifecycle_phases']);
        $out .= '- **' . $claim['name'] . '** (`' . $claim['adapter_digest'] . '`): '
            . implode(', ', array_map(fn(string $surface): string => '`' . $surface . '`', $claim['surfaces']))
            . ". Lifecycle phases: " . $lifecyclePhases . ".\n";
    }
    if ($registry['profiles'] !== []) {
        $out .= "\n## Certified profiles\n\n";
        foreach ($registry['profiles'] as $profile) {
            $out .= '- **' . $profile['name'] . '** (' . $profile['status'] . ', manifest `'
                . $profile['manifest'] . '`): ' . $profile['reason'] . ' Versions: '
                . cap_version_label($profile['supported_versions']) . ".\n";
        }
    }
    $out .= "\n## Explicit unsupported boundaries\n\n";
    foreach ($registry['manifests'] as $claim) {
        if ($claim['status'] === 'excluded') {
            continue;
        }
        foreach ($claim['unsupported'] as $unsupported) {
            $out .= '- **' . $claim['name'] . '** — `' . $unsupported['surface'] . '` / `'
                . $unsupported['operation'] . '`: ' . $unsupported['reason'] . "\n";
        }
    }
    return $out;
}

function cap_readme_block(array $registry): string {
    $certified = [];
    $experimental = [];
    foreach ($registry['manifests'] as $claim) {
        if ($claim['status'] === 'certified') {
            $certified[] = $claim['name'];
        } elseif ($claim['status'] === 'experimental') {
            $experimental[] = $claim['name'];
        }
    }
    return README_BEGIN . "\n"
        . "- **Certified authored-state adapters:** " . implode(', ', $certified) . ".\n"
        . "- **Experimental and promotion-blocking:** " . implode(', ', $experimental) . ".\n"
        . "- **Evidence:** bundle `" . $registry['evidence']['bundle_digest'] . "` ("
        . $registry['evidence']['status'] . "); exact versions, operations, surfaces, and unsupported boundaries are in "
        . "[the generated capability document](docs/capabilities.md). Plugins run unmodified; only registry-named authored state is branchable.\n"
        . README_END;
}

function cap_expected_readme(string $repo, array $registry): string {
    $current = (string) file_get_contents($repo . README_FILE);
    $block = cap_readme_block($registry);
    $pattern = '/' . preg_quote(README_BEGIN, '/') . '.*?' . preg_quote(README_END, '/') . '/s';
    if (preg_match($pattern, $current) === 1) {
        return preg_replace($pattern, $block, $current, 1) ?? $current;
    }
    $needle = "# Duo — Branchable WordPress\n";
    if (!str_contains($current, $needle)) {
        throw new RuntimeException('README heading not found');
    }
    return str_replace($needle, $needle . "\n" . $block . "\n", $current);
}

function cap_generate(string $repo, bool $check): void {
    $registry = cap_build_registry($repo, $check);
    $expected = [
        $repo . REGISTRY_FILE => Canon::encode($registry),
        $repo . DOC_FILE => cap_docs($registry),
        $repo . README_FILE => cap_expected_readme($repo, $registry),
    ];
    if ($check) {
        foreach ($expected as $path => $content) {
            if (!is_file($path) || (string) file_get_contents($path) !== $content) {
                throw new RuntimeException(str_replace($repo . '/', '', $path) . ' is stale; run capability-registry.php generate');
            }
        }
        $dir = $repo . '/manifests';
        $dispositions = ManifestDispositions::load($dir);
        $loaded = CapabilityRegistry::load($dir, $dispositions, array_values(cap_manifests($dir)));
        if ($loaded === null) {
            throw new RuntimeException('generated registry did not load');
        }
        fwrite(STDOUT, "capability registry check: current evidence, generated registry, and product prose agree\n");
        return;
    }
    foreach ($expected as $path => $content) {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            throw new RuntimeException("could not create " . dirname($path));
        }
        file_put_contents($path, $content);
    }
    fwrite(STDOUT, "generated manifests/capabilities/registry.json, docs/capabilities.md, and README capability summary\n");
}

try {
    $command = $argv[1] ?? '';
    if ($command === 'import-bundle') {
        if (!isset($argv[2])) {
            throw new RuntimeException('usage: import-bundle <bundle-dir|bundle.json>');
        }
        cap_import_bundle($repo, $argv[2]);
    } elseif ($command === 'generate') {
        cap_generate($repo, false);
    } elseif ($command === 'check') {
        cap_generate($repo, true);
    } else {
        throw new RuntimeException('usage: import-bundle <bundle>|generate|check');
    }
} catch (Throwable $e) {
    cap_fail($e->getMessage());
}
