#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generate/check Duo's product capability registry and its public prose.
 *
 * Usage:
 *   php scripts/capability-registry.php import-subject-bundle <bundle-dir|bundle.json>
 *   php scripts/capability-registry.php generate
 *   php scripts/capability-registry.php check
 *
 * Subject certification records are upstream evidence. This script stores a
 * compact checked-in index, verifies every subject's bound inputs, then
 * derives the registry, README summary, and compatibility document.
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
require_once $repo . '/agent/src/ScopedCertificationBundle.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\ManifestDispositions;
use Duo\ScopedCertificationBundle;

const EVIDENCE_FILE = '/manifests/capabilities/evidence.json';
const REGISTRY_FILE = '/manifests/capabilities/registry.json';
const DOC_FILE = '/docs/capabilities.md';
const README_FILE = '/README.md';
const README_BEGIN = '<!-- BEGIN GENERATED CAPABILITY SUMMARY -->';
const README_END = '<!-- END GENERATED CAPABILITY SUMMARY -->';
const SCOPED_EVIDENCE_ROOT = '/manifests/capabilities/scoped';

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

/** The platform projection is shared by independently certified subjects. */
function cap_platform(string $repo): array {
    $compatibility = cap_read_json($repo . '/docs/compatibility-baseline.json');
    unset($compatibility['_comment']);
    return [
        'agent_version' => DUO_AGENT_VERSION,
        'branchable_state' => 'only exact certified registry surfaces and operations',
        'compatibility' => $compatibility,
        'plugin_execution' => 'unmodified',
        'site_mode' => 'single-site',
        'spec_version' => DUO_SPEC_VERSION,
    ];
}

/** @return list<array{name:string,role:string,sha256:string,url:string,version:string}> */
function cap_subject_artifacts(string $repo, array $manifest): array {
    $plugin = $manifest['plugin'] ?? null;
    if (!is_string($plugin) || !str_contains($plugin, '/')) {
        return [];
    }
    $slug = strstr($plugin, '/', true);
    $lock = cap_read_json($repo . '/sandbox/conformance/artifacts.lock.json');
    $entries = $lock['plugins'][$slug] ?? null;
    if (!is_array($entries) || array_is_list($entries)) {
        throw new RuntimeException("scoped certification has no typed artifact lock entries for '$slug'");
    }
    $out = [];
    foreach ($entries as $version => $entry) {
        if (!is_array($entry) || !in_array($entry['role'] ?? null, ['certified-boundary', 'refusal-fixture'], true)) {
            continue;
        }
        $out[] = [
            'name' => $slug,
            'role' => $entry['role'],
            'sha256' => $entry['sha256'] ?? null,
            'url' => $entry['url'] ?? null,
            'version' => (string) $version,
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp(
        $a['name'] . "\0" . $a['role'] . "\0" . $a['version'],
        $b['name'] . "\0" . $b['role'] . "\0" . $b['version']
    ));
    if ($out === []) {
        throw new RuntimeException("scoped certification has no certified/refusal artifact boundary for '$slug'");
    }
    return $out;
}

/** @return list<array{path:string,sha256:string,size:int}> */
function cap_scoped_current_inputs(
    string $repo,
    string $kind,
    string $name,
    array $manifest,
    array $claim
): array {
    $paths = ScopedCertificationBundle::subjectInputPaths(
        $repo,
        $kind,
        $name,
        $manifest,
        $claim['evidence']['tests'] ?? []
    );
    return ScopedCertificationBundle::currentInputsForPaths($repo, $paths);
}

/** @return array{record:array,current:bool} */
function cap_scoped_record(
    string $repo,
    array $record,
    string $bundleDir,
    string $kind,
    string $name,
    array $manifest,
    array $claim
): array {
    $subjectKey = ScopedCertificationBundle::subjectKey($kind, $name);
    ScopedCertificationBundle::validate($record, "scoped certification '$subjectKey'");
    try {
        ScopedCertificationBundle::assertEvidenceAssets($record, $bundleDir);
        ScopedCertificationBundle::assertGitRevisionInputs($repo, $record);
        ScopedCertificationBundle::assertCurrent(
            $record,
            $kind,
            $name,
            CapabilityRegistry::subject_digest($kind, $name, $manifest, $claim, $repo . '/manifests'),
            cap_platform($repo),
            cap_scoped_current_inputs($repo, $kind, $name, $manifest, $claim),
            $claim['evidence']['tests'] ?? [],
            $claim,
            cap_subject_artifacts($repo, $manifest)
        );
        return ['record' => $record, 'current' => true];
    } catch (RuntimeException $e) {
        // A record that is well-formed but stale remains visible as candidate
        // evidence.  It cannot fall back to global/older evidence for this
        // adapter, so every consumer remains fail-closed until it is refreshed.
        return ['record' => $record, 'current' => false];
    }
}

/** @return array{bundle:array,path:string,dir:string} */
function cap_scoped_entry(string $repo, string $kind, string $name, mixed $entry): array {
    $subjectKey = ScopedCertificationBundle::subjectKey($kind, $name);
    if (!is_array($entry) || array_is_list($entry)) {
        throw new RuntimeException("scoped evidence entry '$subjectKey' is malformed");
    }
    $keys = array_keys($entry);
    sort($keys, SORT_STRING);
    if ($keys !== ['bundle', 'path'] || !is_array($entry['bundle']) || array_is_list($entry['bundle'])
        || !is_string($entry['path'])) {
        throw new RuntimeException("scoped evidence entry '$subjectKey' must name its bundle and durable path");
    }
    $bundle = $entry['bundle'];
    ScopedCertificationBundle::validate($bundle, "scoped certification '$subjectKey'");
    $expected = 'scoped/' . $kind . 's/' . $name . '/' . $bundle['bundle_digest'];
    if (!hash_equals($expected, $entry['path'])) {
        throw new RuntimeException("scoped evidence entry '$subjectKey' has a noncanonical durable path");
    }
    $dir = $repo . '/manifests/capabilities/' . $entry['path'];
    $bundleFile = $dir . '/bundle.json';
    if (!is_file($bundleFile) || is_link($bundleFile)) {
        throw new RuntimeException("scoped evidence entry '$subjectKey' has no durable bundle manifest");
    }
    $raw = (string) file_get_contents($bundleFile);
    if (!hash_equals(Canon::encode($bundle), $raw)) {
        throw new RuntimeException("scoped evidence entry '$subjectKey' durable manifest disagrees with the attestation");
    }
    return ['bundle' => $bundle, 'path' => $entry['path'], 'dir' => $dir];
}

function cap_copy_scoped_asset(string $sourceDir, string $stage, string $relative): void {
    $source = $sourceDir . '/' . $relative;
    $target = $stage . '/' . $relative;
    if (!is_file($source) || is_link($source)) {
        throw new RuntimeException("scoped bundle asset is absent or unsafe: $relative");
    }
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
        throw new RuntimeException("could not create durable scoped evidence directory for: $relative");
    }
    if (!copy($source, $target)) {
        throw new RuntimeException("could not copy durable scoped evidence asset: $relative");
    }
}

/** @return array{path:string,dir:string} */
function cap_publish_scoped_bundle(string $repo, string $kind, string $name, array $bundle, string $sourceDir): array {
    $subjectKey = ScopedCertificationBundle::subjectKey($kind, $name);
    $sourceDir = realpath($sourceDir);
    if ($sourceDir === false || !is_dir($sourceDir) || basename($sourceDir) !== $bundle['bundle_digest']) {
        throw new RuntimeException('scoped bundle directory does not match its content-addressed digest');
    }
    ScopedCertificationBundle::assertEvidenceAssets($bundle, $sourceDir);
    $relative = 'scoped/' . $kind . 's/' . $name . '/' . $bundle['bundle_digest'];
    $target = $repo . '/manifests/capabilities/' . $relative;
    if (file_exists($target) || is_link($target)) {
        $existing = cap_read_json($target . '/bundle.json');
        if (Canon::encode($existing) !== Canon::encode($bundle)) {
            throw new RuntimeException("durable scoped evidence path already contains a different bundle for '$subjectKey'");
        }
        ScopedCertificationBundle::assertEvidenceAssets($bundle, $target);
        return ['path' => $relative, 'dir' => $target];
    }
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        throw new RuntimeException('could not create scoped evidence parent directory');
    }
    $stage = $parent . '/.building-' . bin2hex(random_bytes(6));
    if (!mkdir($stage, 0777, true)) {
        throw new RuntimeException('could not create scoped evidence staging directory');
    }
    try {
        cap_copy_scoped_asset($sourceDir, $stage, 'bundle.json');
        foreach ($bundle['tests'] as $test) {
            foreach (['result', 'diff', 'log'] as $kind) {
                cap_copy_scoped_asset($sourceDir, $stage, $test[$kind]['path']);
            }
        }
        $copied = cap_read_json($stage . '/bundle.json');
        if (Canon::encode($copied) !== Canon::encode($bundle)) {
            throw new RuntimeException('durable scoped bundle manifest changed while being published');
        }
        ScopedCertificationBundle::assertEvidenceAssets($bundle, $stage);
        if (!rename($stage, $target)) {
            throw new RuntimeException('could not atomically publish durable scoped evidence');
        }
    } catch (Throwable $e) {
        if (is_dir($stage)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($stage);
        }
        throw $e;
    }
    return ['path' => $relative, 'dir' => $target];
}

function cap_subject_context(string $repo, string $kind, string $name): array {
    $manifestDir = $repo . '/manifests';
    $dispositions = ManifestDispositions::load($manifestDir);
    if ($dispositions === null) {
        throw new RuntimeException('manifest dispositions are absent');
    }
    $claim = $kind === 'manifest' ? $dispositions->entry($name) : ($dispositions->profiles()[$name] ?? null);
    if (!is_array($claim)) {
        throw new RuntimeException("scoped bundle subject '$kind:$name' is not reviewed");
    }
    $manifestName = $kind === 'manifest' ? $name : ($claim['manifest'] ?? null);
    $manifest = is_string($manifestName) ? (cap_manifests($manifestDir)[$manifestName] ?? null) : null;
    if (!is_array($manifest)) {
        throw new RuntimeException("scoped bundle subject '$kind:$name' has no shipped manifest");
    }
    return ['manifest' => $manifest, 'claim' => $claim];
}

function cap_import_subject_bundle(string $repo, string $input): void {
    $file = is_dir($input) ? rtrim($input, '/') . '/bundle.json' : $input;
    $raw = @file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException('scoped bundle is unreadable');
    }
    $bundle = cap_read_json($file);
    if (!hash_equals(Canon::encode($bundle), $raw)) {
        throw new RuntimeException('scoped bundle must use Duo canonical JSON bytes');
    }
    $kind = $bundle['subject']['kind'] ?? null;
    $name = $bundle['subject']['name'] ?? null;
    if (!in_array($kind, ['manifest', 'profile'], true) || !is_string($name)) {
        throw new RuntimeException('scoped bundle subject is malformed');
    }
    $subjectKey = ScopedCertificationBundle::subjectKey($kind, $name);
    $context = cap_subject_context($repo, $kind, $name);
    $sourceDir = dirname($file);
    $resolved = cap_scoped_record($repo, $bundle, $sourceDir, $kind, $name, $context['manifest'], $context['claim']);
    if (!$resolved['current']) {
        throw new RuntimeException("scoped bundle for '$subjectKey' is stale against its current identity, closure, platform, artifacts, or citations");
    }
    // A forced scoped run can be verified as bounded evidence, but it must
    // never promote a manifest to a current capability claim. Validation above
    // has already restricted the accepted list to the one named hatch.
    if (($bundle['force_hatches'] ?? null) !== []) {
        $shown = implode(', ', array_map('strval', $bundle['force_hatches']));
        throw new RuntimeException("scoped bundle used force hatches ($shown); a current capability claim must be produced without overrides");
    }
    $evidencePath = $repo . EVIDENCE_FILE;
    $evidence = cap_read_json($evidencePath);
    if (($evidence['format'] ?? null) !== CapabilityRegistry::EVIDENCE_FORMAT
        || !is_array($evidence['records'] ?? null)
        || (array_is_list($evidence['records']) && $evidence['records'] !== [])) {
        throw new RuntimeException('capability evidence attestation is malformed');
    }
    $published = cap_publish_scoped_bundle($repo, $kind, $name, $bundle, $sourceDir);
    $evidence['records'][$subjectKey] = ['bundle' => $bundle, 'path' => $published['path']];
    ksort($evidence['records'], SORT_STRING);
    Canon::write_file($evidencePath, Canon::encode($evidence));
    fwrite(STDOUT, "imported current scoped certification bundle {$bundle['bundle_digest']} for $subjectKey\n");
}

/** @return array<string,mixed> */
function cap_scoped_claim_evidence(array $record, bool $current, string $kind, string $name, array $tests): array {
    $subjectKey = ScopedCertificationBundle::subjectKey($kind, $name);
    if (($record['subject']['kind'] ?? null) !== $kind || ($record['subject']['name'] ?? null) !== $name) {
        throw new RuntimeException("scoped evidence entry '$subjectKey' has a different subject");
    }
    if (!$current) {
        return cap_candidate_claim_evidence($kind, $name, $tests);
    }
    return [
        'bundle_digest' => $record['bundle_digest'],
        'bundle_schema' => ScopedCertificationBundle::FORMAT,
        'closure_digest' => $record['closure']['digest'],
        'force_hatches' => $record['force_hatches'],
        'git_revision' => $record['git_revision'],
        'status' => 'current',
        'subject' => $subjectKey,
        'subject_digest' => $record['subject_digest'],
        'tests' => $tests,
    ];
}

/** @return array<string,mixed> */
function cap_candidate_claim_evidence(string $kind, string $name, array $tests): array {
    return [
        'bundle_digest' => null,
        'bundle_schema' => ScopedCertificationBundle::FORMAT,
        'closure_digest' => null,
        'force_hatches' => [],
        'git_revision' => null,
        'status' => 'candidate',
        'subject' => ScopedCertificationBundle::subjectKey($kind, $name),
        'subject_digest' => null,
        'tests' => $tests,
    ];
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
        || !is_array($evidence['records'] ?? null)
        || (array_is_list($evidence['records']) && $evidence['records'] !== [])) {
        throw new RuntimeException('capability evidence attestation is malformed');
    }
    $platform = cap_platform($repo);
    $compatibility = $platform['compatibility'];
    $manifests = cap_manifests($manifestDir);
    $resolvedRecords = [];
    foreach ($evidence['records'] as $subjectKey => $rawEntry) {
        if (!is_string($subjectKey)
            || preg_match('/^(manifests|profiles)\.([a-z][a-z0-9-]*)$/D', $subjectKey, $match) !== 1) {
            throw new RuntimeException('capability evidence contains a malformed subject key');
        }
        $kind = $match[1] === 'manifests' ? 'manifest' : 'profile';
        $name = $match[2];
        $context = cap_subject_context($repo, $kind, $name);
        $entry = cap_scoped_entry($repo, $kind, $name, $rawEntry);
        $resolved = cap_scoped_record(
            $repo,
            $entry['bundle'],
            $entry['dir'],
            $kind,
            $name,
            $context['manifest'],
            $context['claim']
        );
        $resolvedRecords[$subjectKey] = $resolved;
    }
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
        $adapterDigest = CapabilityRegistry::adapter_digest($manifest, $disposition, $manifestDir);
        $requiredTests = $disposition['evidence']['tests'] ?? [];
        $subjectKey = ScopedCertificationBundle::subjectKey('manifest', $name);
        $scoped = $resolvedRecords[$subjectKey] ?? null;
        $claimEvidence = is_array($scoped)
            ? cap_scoped_claim_evidence($scoped['record'], $scoped['current'], 'manifest', $name, $requiredTests)
            : cap_candidate_claim_evidence('manifest', $name, $requiredTests);
        if ($requireCurrent && $claimStatus === 'certified' && ($claimEvidence['status'] ?? null) !== 'current') {
            throw new RuntimeException("certification evidence for '$subjectKey' is absent or expired");
        }
        $claims[$name] = [
            'name' => $name,
            'status' => $claimStatus,
            'reason' => (string) $disposition['reason'],
            'adapter_digest' => $adapterDigest,
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
            'evidence' => $claimEvidence,
        ];
    }

    $profiles = [];
    foreach ($dispositionData['profiles'] as $name => $profile) {
        $manifest = (string) $profile['manifest'];
        $subjectKey = ScopedCertificationBundle::subjectKey('profile', $name);
        $scoped = $resolvedRecords[$subjectKey] ?? null;
        $profileEvidence = is_array($scoped)
            ? cap_scoped_claim_evidence($scoped['record'], $scoped['current'], 'profile', $name, $profile['evidence']['tests'] ?? [])
            : cap_candidate_claim_evidence('profile', $name, $profile['evidence']['tests'] ?? []);
        if ($requireCurrent && ($profile['status'] ?? null) === 'certified'
            && ($profileEvidence['status'] ?? null) !== 'current') {
            throw new RuntimeException("certification evidence for '$subjectKey' is absent or expired");
        }
        $profiles[$name] = [
            'name' => $name,
            'manifest' => $manifest,
            'status' => $profile['status'],
            'reason' => $profile['reason'],
            'subject_digest' => CapabilityRegistry::subject_digest(
                'profile',
                $name,
                $manifests[$manifest],
                $profile,
                $manifestDir
            ),
            'supported_versions' => $profile['supported_versions'],
            'scope' => $profile['scope'],
            'evidence' => $profileEvidence,
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
        'platform' => $platform,
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
    $out = "# Generated capability registry\n\n";
    $out .= "<!-- Generated by scripts/capability-registry.php; do not hand-edit. -->\n\n";
    $out .= "Duo agent **" . $registry['platform']['agent_version'] . "** / repo spec **"
        . $registry['platform']['spec_version'] . "** is certified only for the exact boundaries below. "
        . "Plugins execute unmodified; that fact is separate from whether their authored state is branchable.\n\n";
    $out .= "Every row names its own independently current evidence record. Missing, stale, or cross-subject evidence is unsupported and cannot fall back to another capability.\n\n";
    $out .= "| Adapter | Authored state | Plugin execution | Versions | Operations | Evidence |\n";
    $out .= "|---|---|---|---|---|---|\n";
    foreach ($registry['manifests'] as $claim) {
        if ($claim['status'] === 'excluded') {
            continue;
        }
        $out .= '| ' . $claim['name'] . ' | ' . $claim['authored_state']['status'] . ' | '
            . $claim['plugin_execution']['mode'] . ' / ' . $claim['plugin_execution']['status'] . ' | '
            . str_replace('|', '\\|', cap_version_label($claim['supported_versions'])) . ' | '
            . implode(', ', $claim['operations']) . ' | '
            . strtoupper((string) $claim['evidence']['status']) . ' / `'
            . $claim['evidence']['bundle_digest'] . "` |\n";
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
            . ". Lifecycle phases: " . $lifecyclePhases . '. Evidence: `'
            . $claim['evidence']['bundle_schema'] . '` / `'
            . $claim['evidence']['bundle_digest'] . '` (' . $claim['evidence']['status'] . ").\n";
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
        . "- **Evidence:** independently scoped per manifest and profile; exact versions, operations, surfaces, and unsupported boundaries are in "
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
    if ($command === 'import-subject-bundle') {
        if (!isset($argv[2])) {
            throw new RuntimeException('usage: import-subject-bundle <bundle-dir|bundle.json>');
        }
        cap_import_subject_bundle($repo, $argv[2]);
    } elseif ($command === 'generate') {
        cap_generate($repo, false);
    } elseif ($command === 'check') {
        cap_generate($repo, true);
    } else {
        throw new RuntimeException('usage: import-subject-bundle <bundle>|generate|check');
    }
} catch (Throwable $e) {
    cap_fail($e->getMessage());
}
