#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate/check Duo's product capability document and its README summary.
 *
 * Usage:
 *   php tools/capability-doc.php generate   # rewrite docs/capabilities.md and the README block
 *   php tools/capability-doc.php --check    # regenerate in memory, byte-compare, exit 1 on drift
 *   php tools/capability-doc.php            # same as --check
 *
 * WHAT THIS PROJECTS, AND WHAT IT DELIBERATELY NO LONGER PROVES
 * -------------------------------------------------------------
 * DESIGN.md's v0 scope decision 4 requires the product-claims prose to be
 * generated from one source, "never a duplicate handwritten list". The
 * predecessor (scripts/capability-registry.php) satisfied that by projecting
 * claims out of content-addressed certification bundles: a claim could not be
 * printed unless a current evidence record stood behind it. That apparatus is
 * gone. The single source is now exactly four files:
 *
 *   manifests/*.json                      what each adapter DECLARES it covers
 *   manifests/dispositions.json           the reviewed status/reason per manifest
 *   manifests/capabilities/platform.json  the one platform/environment boundary
 *   agent/duo.php                         DUO_AGENT_VERSION / DUO_SPEC_VERSION
 *
 * So a status in the generated document now means: declared by the manifest,
 * reviewed into dispositions.json by a human who wrote down why, and exercised
 * by the named conformance suites against a live pair. It does NOT mean a
 * bundle digest binds that claim to a closure, an artifact set, or a specific
 * run. No sentence emitted here may imply otherwise -- capdoc_preamble() and
 * capdoc_readme_block() hold the wording that states the narrowing, and are
 * the first thing to re-read before editing prose in this file.
 *
 * WHY THE CROSS-CHECKS BELOW EXIST
 * --------------------------------
 * With the evidence chain removed, the checks in capdoc_build() are the whole
 * of what keeps the narrowed claim honest. Each one refuses rather than
 * papering over, and each mirrors a rule the agent itself enforces at load
 * time (agent/src/Policy/ManifestDispositions.php), so the document cannot
 * describe a library the agent would reject:
 *
 *   - dispositions coverage is an EXACT set, not a subset (ManifestDispositions
 *     ::load()): a manifest cannot reach the document merely by existing beside
 *     the agent, and a reviewed entry cannot outlive its manifest.
 *   - a disposition naming a plugin must agree with that manifest's own
 *     `plugin`/`version_range` bytes (validate_entry()'s version cross-check),
 *     so the published range is the range the agent will actually admit.
 *   - a disposition's declared sections must exist in the manifest, and its
 *     supported deletion selectors must be exactly the manifest's declared
 *     `deletions` keys -- otherwise the document advertises an operation no
 *     manifest implements, or hides one no reviewer blessed.
 *   - platform.json's agent_version/spec_version must equal agent/duo.php's
 *     defines, and its compatibility block must equal
 *     docs/compatibility-baseline.json (which cli/src/Onboarding/Doctor.php
 *     reads at runtime for its BLOCKING PHP/database/filesystem/process checks). Those are two
 *     copies of the same pins on disk; this equality is what stops them
 *     drifting into two different truths.
 */

use Duo\Canon;

$repo = dirname(__DIR__);

// Two values are "the same fact stated twice" only under the repo's own
// canonical form: docs/compatibility-baseline.json writes min before max while
// platform.json writes max before min, so a raw `===` on the decoded arrays
// reports a difference that does not exist. Canon is required rather than
// reimplemented here for the same reason the predecessor required it -- and
// because ManifestDispositions::validate_entry() compares version ranges with
// exactly Canon::encode(), so the version cross-check below is byte-identical
// to the rule the agent enforces at load time rather than a lookalike. Canon
// has no requires of its own; nothing else of the agent is loaded.
require_once $repo . '/agent/src/Kernel/Canon.php';

const CAPDOC_DOC_FILE = '/docs/capabilities.md';
const CAPDOC_README_FILE = '/README.md';
const CAPDOC_README_BEGIN = '<!-- BEGIN GENERATED CAPABILITY SUMMARY -->';
const CAPDOC_README_END = '<!-- END GENERATED CAPABILITY SUMMARY -->';
const CAPDOC_MANIFEST_DIR = '/manifests';
const CAPDOC_DISPOSITIONS_FILE = '/manifests/dispositions.json';
const CAPDOC_PLATFORM_FILE = '/manifests/capabilities/platform.json';
const CAPDOC_BASELINE_FILE = '/docs/compatibility-baseline.json';
const CAPDOC_DISPOSITIONS_FORMAT = 'duo-manifest-dispositions/v1';
const CAPDOC_PLATFORM_FORMAT = 'duo-platform-boundary/v1';

/** Above this many keys a section is summarised by count alone; below it the keys are named. */
const CAPDOC_NAME_KEYS_UPTO = 6;

function capdoc_fail(string $message): never {
    fwrite(STDERR, "capability doc: $message\n");
    exit(1);
}

function capdoc_read_json(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException('missing JSON file: ' . $path);
    }
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('JSON root must be an object: ' . $path);
    }
    return $decoded;
}

/** @return array{agent_version:string,spec_version:int} */
function capdoc_agent_defines(string $repo): array {
    $source = (string) file_get_contents($repo . '/agent/duo.php');
    if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $version) !== 1) {
        throw new RuntimeException('could not resolve DUO_AGENT_VERSION from agent/duo.php');
    }
    if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $spec) !== 1) {
        throw new RuntimeException('could not resolve DUO_SPEC_VERSION from agent/duo.php');
    }
    return ['agent_version' => $version[1], 'spec_version' => (int) $spec[1]];
}

/** @return array<string,array> manifests keyed by file basename, sorted */
function capdoc_manifests(string $dir): array {
    $out = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
        $name = basename($file, '.json');
        if ($name === 'dispositions') {
            continue;
        }
        $manifest = capdoc_read_json($file);
        // ManifestDispositions::load() keys coverage by FILE BASENAME while
        // AdapterRegistry keys the loaded library by the manifest's own `name`.
        // The two are the same string for every shipped manifest; a
        // divergence would silently give one manifest two identities, so it is
        // refused here rather than rendered under whichever key won.
        $declared = $manifest['name'] ?? null;
        if ($declared !== $name) {
            throw new RuntimeException(
                "manifest '$name.json' declares name '" . var_export($declared, true) . "'; file and name must agree"
            );
        }
        $out[$name] = $manifest;
    }
    ksort($out, SORT_STRING);
    return $out;
}

function capdoc_dispositions(string $repo): array {
    $data = capdoc_read_json($repo . CAPDOC_DISPOSITIONS_FILE);
    $keys = array_keys($data);
    sort($keys, SORT_STRING);
    if ($keys !== ['format', 'manifests', 'profiles']
        || ($data['format'] ?? null) !== CAPDOC_DISPOSITIONS_FORMAT
        || !is_array($data['manifests']) || array_is_list($data['manifests'])
        || !is_array($data['profiles'])
        || (array_is_list($data['profiles']) && $data['profiles'] !== [])) {
        throw new RuntimeException(
            'manifests/dispositions.json must contain exactly format, manifests, and profiles for '
            . CAPDOC_DISPOSITIONS_FORMAT
        );
    }
    return $data;
}

/** The one platform/environment boundary, proven to agree with its two other on-disk copies. */
function capdoc_platform(string $repo): array {
    $data = capdoc_read_json($repo . CAPDOC_PLATFORM_FILE);
    if (($data['format'] ?? null) !== CAPDOC_PLATFORM_FORMAT
        || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])) {
        throw new RuntimeException(
            'manifests/capabilities/platform.json must be a ' . CAPDOC_PLATFORM_FORMAT . ' object'
        );
    }
    $platform = $data['platform'];
    $keys = array_keys($platform);
    sort($keys, SORT_STRING);
    // Exact key set, not a subset: an added platform axis must be rendered by
    // a deliberate edit here, never dropped silently from the published
    // boundary because this generator did not know about it.
    $expected = ['agent_version', 'branchable_state', 'compatibility', 'plugin_execution', 'site_mode', 'spec_version'];
    if ($keys !== $expected) {
        throw new RuntimeException(
            'platform boundary keys are [' . implode(',', $keys) . ']; expected [' . implode(',', $expected) . ']'
        );
    }
    $defines = capdoc_agent_defines($repo);
    if ($platform['agent_version'] !== $defines['agent_version']
        || $platform['spec_version'] !== $defines['spec_version']) {
        throw new RuntimeException(
            'platform boundary declares agent ' . var_export($platform['agent_version'], true) . ' / spec '
            . var_export($platform['spec_version'], true) . '; agent/duo.php defines '
            . $defines['agent_version'] . ' / ' . $defines['spec_version']
        );
    }
    $baseline = capdoc_read_json($repo . CAPDOC_BASELINE_FILE);
    unset($baseline['_comment']);
    if (Canon::encode($platform['compatibility']) !== Canon::encode($baseline)) {
        throw new RuntimeException(
            'platform boundary compatibility disagrees with docs/compatibility-baseline.json; that file is not '
            . 'documentation -- cli/src/Onboarding/Doctor.php reads it at runtime for the blocking compatibility checks'
        );
    }
    // An EXACT per-axis key set, deliberately not a subset check: this array
    // is the tripwire that makes a shape change to the boundary a decision
    // rather than a silent projection. `php` gained `verified` and `database`
    // traded `engine`+`min`+`max` for the `engines` map in the same commit
    // that widened both claims, and each of those edits threw here until it
    // was made on purpose (agent/src/Policy/PlatformCompatibility.php's
    // valid_exercised_axis()/valid_database_axis() are the load-time mirror).
    foreach (['database' => ['engines', 'note'],
              'filesystem' => ['directory_separator', 'note', 'os_families', 'profile', 'required_functions'],
              'php' => ['max', 'min', 'note', 'verified'],
              'process' => ['note', 'os_families', 'profile', 'required_functions', 'shell'],
              'wordpress' => ['last_verified', 'max', 'min', 'note', 'verified']] as $axis => $axisKeys) {
        $found = array_keys($platform['compatibility'][$axis] ?? []);
        sort($found, SORT_STRING);
        if ($found !== $axisKeys) {
            throw new RuntimeException("platform compatibility.$axis must declare exactly: " . implode(', ', $axisKeys));
        }
    }
    return $platform;
}

/**
 * Refuse anything the document would otherwise have to guess about or gloss.
 *
 * @param array<string,array> $manifests
 */
function capdoc_cross_check(array $manifests, array $dispositions): void {
    $declared = array_keys($dispositions['manifests']);
    $shipped = array_keys($manifests);
    sort($declared, SORT_STRING);
    sort($shipped, SORT_STRING);
    if ($declared !== $shipped) {
        throw new RuntimeException(
            'manifest disposition coverage mismatch; missing=[' . implode(',', array_diff($shipped, $declared))
            . '], extra=[' . implode(',', array_diff($declared, $shipped)) . ']'
        );
    }
    foreach ($manifests as $name => $manifest) {
        $entry = $dispositions['manifests'][$name];
        foreach (['status', 'reason'] as $field) {
            if (!is_string($entry[$field] ?? null) || trim((string) $entry[$field]) === '') {
                throw new RuntimeException("disposition '$name' has no $field");
            }
        }
        if (!in_array($entry['status'], ['certified', 'experimental', 'excluded'], true)) {
            throw new RuntimeException("disposition '$name' has an unknown status '{$entry['status']}'");
        }
        $capabilities = $entry['capabilities'] ?? [];
        foreach (array_merge($capabilities['entity_sections'] ?? [], $capabilities['field_sections'] ?? []) as $section) {
            if (!array_key_exists($section, $manifest)) {
                throw new RuntimeException("disposition '$name' names absent manifest section '$section'");
            }
        }

        // The published deletion claim and the manifest's own `deletions`
        // grammar are the same fact stated twice. Equality both ways: a
        // manifest-declared selector no reviewer blessed must not be hidden,
        // and a reviewed selector no manifest implements must not be printed.
        $supported = $capabilities['deletion_semantics']['supported'] ?? [];
        $implemented = array_keys($manifest['deletions'] ?? []);
        sort($supported, SORT_STRING);
        sort($implemented, SORT_STRING);
        if ($supported !== $implemented) {
            throw new RuntimeException(
                "disposition '$name' supports deletions [" . implode(',', $supported)
                . '] but the manifest declares [' . implode(',', $implemented) . ']'
            );
        }

        // ManifestDispositions::validate_entry() runs this for certified
        // entries only. The document publishes a version range for every
        // entry that names a plugin -- fixtures included -- so it is checked
        // for every entry that names one.
        $supportedVersions = $entry['supported_versions'] ?? [];
        $claimedPlugin = $supportedVersions['plugin'] ?? null;
        $manifestPlugin = $manifest['plugin'] ?? null;
        if (is_string($claimedPlugin) && $claimedPlugin !== 'unbound') {
            if ($claimedPlugin !== $manifestPlugin) {
                throw new RuntimeException(
                    "disposition '$name' claims plugin '$claimedPlugin'; its manifest declares "
                    . var_export($manifestPlugin, true)
                );
            }
            if (Canon::encode($supportedVersions['range'] ?? null) !== Canon::encode($manifest['version_range'] ?? null)) {
                throw new RuntimeException(
                    "disposition '$name' version range disagrees with its manifest's version_range"
                );
            }
        }
        if (is_string($manifestPlugin) && $manifestPlugin !== '' && !is_string($claimedPlugin)) {
            throw new RuntimeException(
                "manifest '$name' binds plugin '$manifestPlugin' but its disposition claims no plugin identity"
            );
        }
    }
    foreach ($dispositions['profiles'] as $name => $profile) {
        if (!is_array($profile) || !isset($manifests[$profile['manifest'] ?? ''])) {
            throw new RuntimeException("disposition profile '$name' names no shipped manifest");
        }
        if (!in_array($profile['status'] ?? null, ['certified', 'experimental'], true)) {
            throw new RuntimeException("disposition profile '$name' has an unknown status");
        }
    }
}

/**
 * The operation set exactly as the agent projects it.
 *
 * ManifestDispositions::claim_from_disposition() synthesizes `promote` from
 * deploy+apply rather than storing it, so a document that printed only the
 * declared list would advertise a narrower operation set than `duo capability`
 * reports for the same manifest -- and the dispositions' own unsupported rows
 * already speak about `promote` as an operation.
 */
function capdoc_operations(array $capabilities): array {
    $operations = array_values((array) ($capabilities['operations'] ?? []));
    if (in_array('deploy', $operations, true) && in_array('apply', $operations, true)) {
        $operations[] = 'promote';
    }
    $operations = array_values(array_unique($operations, SORT_STRING));
    sort($operations, SORT_STRING);
    return $operations;
}

/** Markdown table cells cannot carry a pipe or a newline. */
function capdoc_cell(string $text): string {
    return str_replace(['|', "\n"], ['\\|', ' '], $text);
}

/** core is the platform adapter every other row is scoped against, so it leads. */
function capdoc_order(array $names): array {
    sort($names, SORT_STRING);
    $out = in_array('core', $names, true) ? ['core'] : [];
    foreach ($names as $name) {
        if ($name !== 'core') {
            $out[] = $name;
        }
    }
    return $out;
}

function capdoc_plugin_label(string $name, array $manifest, array $supportedVersions): string {
    $plugin = $manifest['plugin'] ?? null;
    if (is_string($plugin) && $plugin !== '') {
        return '`' . $plugin . '`';
    }
    if ($name === 'core') {
        return 'WordPress core';
    }
    return ($supportedVersions['plugin'] ?? null) === 'unbound' ? 'unbound' : 'none declared';
}

/**
 * One exercised axis — WordPress or PHP — as a rendered cell: the declared
 * bounds plus the exact patch each exercised series was proven on. Both
 * halves are printed because both halves gate — the range alone would read as
 * a claim over minor lines the `verified` map deliberately excludes
 * (agent/src/Policy/PlatformCompatibility.php:exercised_supported()). One
 * function for both axes because one predicate decides both.
 */
function capdoc_exercised_label(array $axis): string {
    $patches = array_map('strval', array_values($axis['verified']));
    usort($patches, static fn(string $a, string $b): int => version_compare($a, $b));

    return '>=' . $axis['min'] . ' <' . $axis['max']
        . ' (exercised ' . implode(', ', $patches) . ')';
}

/**
 * The database axis as a rendered cell: every claimed engine with its own
 * range. Printing one entry per engine is the whole point of the engines map
 * — a single range could only describe one product, and an engine that is not
 * printed here is one the agent refuses outright
 * (platform_database_engine_unsupported), not one it merely has no numbers
 * for. Sorted by engine name so the document is stable against the claim's
 * own key order.
 */
function capdoc_database_label(array $database): string {
    $engines = $database['engines'];
    ksort($engines, SORT_STRING);
    $cells = [];
    foreach ($engines as $engine => $range) {
        $cells[] = (string) $engine . ' >=' . $range['min'] . ' <' . $range['max'];
    }

    return implode('; ', $cells);
}

function capdoc_filesystem_label(array $filesystem): string {
    return (string) $filesystem['profile']
        . ' (OS ' . implode(', ', array_map('strval', $filesystem['os_families']))
        . '; separator ' . json_encode(
            $filesystem['directory_separator'],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )
        . '; functions ' . implode(', ', array_map('strval', $filesystem['required_functions'])) . ')';
}

function capdoc_process_label(array $process): string {
    return (string) $process['profile']
        . ' (OS ' . implode(', ', array_map('strval', $process['os_families']))
        . '; functions ' . implode(', ', array_map('strval', $process['required_functions']))
        . '; executable shell ' . (string) $process['shell'] . ')';
}

/**
 * A supported_versions entry keyed by `wordpress` pins the claim to core
 * rather than to a plugin artifact; platform.json's wordpress axis is the one
 * place those numbers live, so they are read from there instead of echoing
 * the disposition's own pointer at it.
 */
function capdoc_version_label(array $supportedVersions, array $platform): string {
    if (isset($supportedVersions['wordpress'])) {
        return 'WordPress ' . capdoc_exercised_label($platform['compatibility']['wordpress']);
    }
    $range = $supportedVersions['range'] ?? null;
    if (is_array($range) && isset($range['min'], $range['max'])) {
        return '>=' . $range['min'] . ' <' . $range['max'];
    }
    if (($supportedVersions['fixture'] ?? false) === true) {
        return 'fixture only';
    }
    return 'unbound';
}

function capdoc_theme_label(array $manifest): string {
    $theme = $manifest['theme'] ?? null;
    if (!is_string($theme) || $theme === '') {
        return '';
    }
    $range = $manifest['theme_version_range'] ?? null;
    return is_array($range) && isset($range['min'], $range['max'])
        ? '`' . $theme . '` >=' . $range['min'] . ' <' . $range['max']
        : '`' . $theme . '` (no version range declared)';
}

/**
 * One section of the declared surface, as a count plus the keys when there are
 * few enough for the names themselves to be the useful fact.
 */
function capdoc_section_summary(string $section, mixed $value): string {
    if (is_array($value) && !array_is_list($value)) {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys === []) {
            return '`' . $section . '` (none declared)';
        }
        return count($keys) <= CAPDOC_NAME_KEYS_UPTO
            ? '`' . $section . '` (' . count($keys) . ': ' . implode(', ', $keys) . ')'
            : '`' . $section . '` (' . count($keys) . ' keys)';
    }
    if (is_array($value)) {
        return '`' . $section . '` (' . count($value) . ' rules)';
    }
    if (is_string($value)) {
        return '`' . $section . '` (`' . $value . '`)';
    }
    return '`' . $section . '`';
}

function capdoc_preamble(array $platform): string {
    return 'Duo agent **' . $platform['agent_version'] . '** / repo spec **' . $platform['spec_version']
        . "**. This document is the whole of what Duo claims; nothing outside it is supported.\n\n"
        . '**How to read a claim.** Each adapter below is *manifest-declared* — its own `manifests/<name>.json` '
        . 'states the exact surfaces it covers — *disposition-reviewed* — `manifests/dispositions.json` records a '
        . 'status and the written reason a reviewer gave it — and *conformance-tested*, by the named suites running '
        . 'against a live WordPress pair in the sandbox. A status is that review plus those runs. It is **not** an '
        . 'attestation: no digest binds a claim here to a particular closure, artifact set, or test run, so treat '
        . "each row as a reviewed declaration backed by testing rather than as sealed evidence.\n\n"
        . '**What happens outside a claim.** Refusal, not a guess. A surface, version, or operation this document '
        . 'does not name is unsupported, and the agent blocks loudly rather than falling back to a neighbouring '
        . 'capability. Plugins always execute unmodified; that fact is separate from whether their authored state '
        . "is branchable.\n";
}

function capdoc_platform_section(array $platform): string {
    $compatibility = $platform['compatibility'];
    $out = "## Platform and environment boundary\n\n";
    $out .= "| Axis | Boundary |\n|---|---|\n";
    $out .= '| Agent version | ' . capdoc_cell((string) $platform['agent_version']) . " |\n";
    $out .= '| Repo spec version | ' . capdoc_cell((string) $platform['spec_version']) . " |\n";
    $out .= '| Site mode | ' . capdoc_cell((string) $platform['site_mode']) . " |\n";
    $out .= '| Plugin execution | ' . capdoc_cell((string) $platform['plugin_execution']) . " |\n";
    $out .= '| Branchable state | ' . capdoc_cell((string) $platform['branchable_state']) . " |\n";
    $out .= '| WordPress | ' . capdoc_cell(capdoc_exercised_label($compatibility['wordpress'])) . " |\n";
    $out .= '| PHP | ' . capdoc_cell(capdoc_exercised_label($compatibility['php'])) . " |\n";
    $out .= '| Database | ' . capdoc_cell(capdoc_database_label($compatibility['database'])) . " |\n";
    $out .= '| Filesystem | ' . capdoc_cell(capdoc_filesystem_label($compatibility['filesystem'])) . " |\n";
    $out .= '| Process | ' . capdoc_cell(capdoc_process_label($compatibility['process'])) . " |\n\n";
    $out .= 'Multisite is refused before policy load or mutation. Each compatibility axis carries its own reviewed '
        . "note saying what pins it and what it does not claim:\n\n";
    $out .= '- **WordPress** — ' . $compatibility['wordpress']['note'] . "\n";
    $out .= '- **PHP** — ' . $compatibility['php']['note'] . "\n";
    $out .= '- **Database** — ' . $compatibility['database']['note'] . "\n";
    $out .= '- **Filesystem** — ' . $compatibility['filesystem']['note'] . "\n";
    $out .= '- **Process** — ' . $compatibility['process']['note'] . "\n";
    return $out;
}

/** @param array<string,array> $manifests */
function capdoc_index_table(array $manifests, array $dispositions, array $platform): string {
    $out = "## Adapters at a glance\n\n";
    $out .= "| Manifest | Status | Plugin | Version range | Operations |\n|---|---|---|---|---|\n";
    foreach (capdoc_order(array_keys($manifests)) as $name) {
        $entry = $dispositions['manifests'][$name];
        $operations = capdoc_operations($entry['capabilities'] ?? []);
        $out .= '| [' . $name . '](#' . $name . ') | ' . $entry['status'] . ' | '
            . capdoc_cell(capdoc_plugin_label($name, $manifests[$name], $entry['supported_versions'] ?? [])) . ' | '
            . capdoc_cell(capdoc_version_label($entry['supported_versions'] ?? [], $platform)) . ' | '
            . capdoc_cell(implode(', ', $operations)) . " |\n";
    }
    return $out;
}

function capdoc_manifest_section(
    string $name,
    array $manifest,
    array $entry,
    array $platform,
    array $profiles
): string {
    $capabilities = $entry['capabilities'] ?? [];
    $supportedVersions = $entry['supported_versions'] ?? [];
    $out = '## ' . $name . "\n\n";
    // The disposition's own status word, verbatim. "certified" here is the
    // reviewer's word for a reviewed, conformance-tested claim -- rendering it
    // as "supported" would widen a claim this generator cannot back.
    $out .= '**Status: ' . $entry['status'] . '.** ' . $entry['reason'] . "\n\n";
    $out .= '- **Plugin:** ' . capdoc_plugin_label($name, $manifest, $supportedVersions) . "\n";
    $out .= '- **Version range:** ' . capdoc_version_label($supportedVersions, $platform) . "\n";
    $theme = capdoc_theme_label($manifest);
    if ($theme !== '') {
        $out .= '- **Theme:** ' . $theme . "\n";
    }
    $operations = capdoc_operations($capabilities);
    $out .= '- **Operations:** ' . ($operations === [] ? 'none declared' : implode(', ', $operations)) . "\n";
    $lifecycle = $capabilities['lifecycle_phases'] ?? [];
    $out .= '- **Lifecycle phases:** ' . ($lifecycle === [] ? 'none declared' : implode(', ', $lifecycle)) . "\n";

    $entities = [];
    foreach ($capabilities['entity_sections'] ?? [] as $section) {
        $entities[] = capdoc_section_summary($section, $manifest[$section] ?? null);
    }
    $fields = [];
    foreach ($capabilities['field_sections'] ?? [] as $section) {
        $fields[] = capdoc_section_summary($section, $manifest[$section] ?? null);
    }
    $out .= '- **Declared entities:** ' . ($entities === [] ? 'none' : implode(', ', $entities)) . "\n";
    $out .= '- **Declared fields:** ' . ($fields === [] ? 'none' : implode(', ', $fields)) . "\n";

    $hooks = [];
    foreach (['providers' => 'provider', 'actions' => 'structured action', 'regenerators' => 'regenerator'] as $key => $label) {
        $value = $manifest[$key] ?? null;
        if (is_array($value) && $value !== []) {
            $hooks[] = count($value) . ' ' . $label . (count($value) === 1 ? '' : 's');
        }
    }
    if (is_string($manifest['interpreter'] ?? null)) {
        $hooks[] = 'interpreter `' . $manifest['interpreter'] . '`';
    }
    if ($hooks !== []) {
        $out .= '- **Adapter hooks:** ' . implode(', ', $hooks) . "\n";
    }

    $deletions = $capabilities['deletion_semantics'] ?? [];
    $supportedDeletes = $deletions['supported'] ?? [];
    $unsupportedDeletes = $deletions['unsupported'] ?? [];
    sort($supportedDeletes, SORT_STRING);
    $out .= '- **Deletions supported:** ' . ($supportedDeletes === []
        ? 'none'
        : implode(', ', array_map(static fn(string $s): string => '`' . $s . '`', $supportedDeletes))) . "\n";
    $out .= '- **Deletions unsupported:** ' . ($unsupportedDeletes === []
        ? 'none stated'
        : implode(', ', $unsupportedDeletes)) . "\n";

    // dispositions.json still carries the suite names each reviewed claim was
    // exercised by. They are named here because "conformance-tested" is only a
    // checkable statement if the reader can see which runs are meant.
    $tests = $entry['evidence']['tests'] ?? null;
    if (is_array($tests) && $tests !== []) {
        $out .= '- **Exercised by:** ' . implode(', ', array_map(
            static fn(string $t): string => '`' . $t . '`',
            $tests
        )) . "\n";
    }

    $ownProfiles = [];
    foreach ($profiles as $profileName => $profile) {
        if (($profile['manifest'] ?? null) === $name) {
            $ownProfiles[] = '[' . $profileName . '](#profile-' . $profileName . ') (' . $profile['status'] . ')';
        }
    }
    if ($ownProfiles !== []) {
        $out .= '- **Profiles:** ' . implode(', ', $ownProfiles) . "\n";
    }

    $keyspaces = $entry['default_authored_keyspaces'] ?? [];
    if ($keyspaces !== []) {
        $out .= "\n**Default-authored keyspaces.** A table whose unlisted keys default to authored needs its own "
            . "review; each is recorded with the verdict a reviewer reached.\n\n";
        foreach ($keyspaces as $row) {
            $out .= '- `' . $row['table'] . '` — **' . $row['status'] . '**: ' . $row['reason'] . "\n";
        }
    }

    $out .= "\n**Unsupported, explicitly.**\n\n";
    foreach ($entry['unsupported'] ?? [] as $unsupported) {
        $out .= '- `' . $unsupported['surface'] . '` / `' . $unsupported['operation'] . '` — '
            . $unsupported['reason'] . "\n";
    }
    return $out;
}

function capdoc_profiles_section(array $profiles, array $platform): string {
    $out = "## Profiles\n\n";
    $out .= "A profile is a named subset of one manifest's surface, reviewed and exercised separately because it "
        . "is only reachable in a particular site configuration.\n";
    foreach ($profiles as $name => $profile) {
        $out .= "\n### profile-" . $name . "\n\n";
        $out .= '**Status: ' . $profile['status'] . '.** ' . $profile['reason'] . "\n\n";
        $out .= '- **Manifest:** `' . $profile['manifest'] . "`\n";
        $out .= '- **Version range:** ' . capdoc_version_label($profile['supported_versions'] ?? [], $platform) . "\n";
        foreach ($profile['scope'] ?? [] as $section => $values) {
            $names = is_array($values) ? $values : [$values];
            sort($names, SORT_STRING);
            $out .= '- **Scope — `' . $section . '`:** ' . implode(', ', array_map(
                static fn(string $v): string => '`' . $v . '`',
                $names
            )) . "\n";
        }
        $tests = $profile['evidence']['tests'] ?? null;
        if (is_array($tests) && $tests !== []) {
            $out .= '- **Exercised by:** ' . implode(', ', array_map(
                static fn(string $t): string => '`' . $t . '`',
                $tests
            )) . "\n";
        }
    }
    return $out;
}

/** @param array<string,array> $manifests */
function capdoc_doc(array $manifests, array $dispositions, array $platform): string {
    $out = "# Duo capability boundary\n\n";
    $out .= '<!-- Generated by tools/capability-doc.php from manifests/*.json + manifests/dispositions.json; '
        . "do not hand-edit. Run `php tools/capability-doc.php generate` after changing either. -->\n\n";
    $out .= capdoc_preamble($platform) . "\n";
    $out .= capdoc_platform_section($platform) . "\n";
    $out .= capdoc_index_table($manifests, $dispositions, $platform) . "\n";
    foreach (capdoc_order(array_keys($manifests)) as $name) {
        $out .= capdoc_manifest_section(
            $name,
            $manifests[$name],
            $dispositions['manifests'][$name],
            $platform,
            $dispositions['profiles']
        ) . "\n";
    }
    if ($dispositions['profiles'] !== []) {
        $out .= capdoc_profiles_section($dispositions['profiles'], $platform);
    }
    return $out;
}

/** @param array<string,array> $manifests */
function capdoc_readme_block(array $manifests, array $dispositions, array $platform): string {
    $rows = '';
    $excluded = [];
    foreach (capdoc_order(array_keys($manifests)) as $name) {
        $entry = $dispositions['manifests'][$name];
        if ($entry['status'] === 'excluded') {
            $excluded[] = $name;
            continue;
        }
        $rows .= '| ' . $name . ' | ' . $entry['status'] . ' | '
            . capdoc_cell(capdoc_plugin_label($name, $manifests[$name], $entry['supported_versions'] ?? [])) . ' | '
            . capdoc_cell(capdoc_version_label($entry['supported_versions'] ?? [], $platform)) . " |\n";
    }
    $block = CAPDOC_README_BEGIN . "\n"
        . "<!-- Generated by tools/capability-doc.php; do not hand-edit. -->\n\n"
        . "| Manifest | Status | Plugin | Version range |\n|---|---|---|---|\n" . $rows . "\n"
        . "Every claim above is declared by the adapter's own manifest, reviewed into "
        . '`manifests/dispositions.json` with a written reason, and exercised by named conformance suites against a '
        . 'live WordPress pair — a reviewed, tested declaration rather than an attestation sealed to a '
        . 'content-addressed evidence bundle. Outside a declared surface, version range, or operation Duo refuses '
        . 'by default instead of guessing; the exact surfaces, operations, and explicit unsupported boundaries are '
        . 'in [the generated capability document](docs/capabilities.md), and plugins always run unmodified.';
    if ($excluded !== []) {
        // Both the noun and its verbs inflect. Pluralizing only the noun read
        // as "One excluded fixture manifest (duo-agency-cpt) ship with the
        // agent ... and carry no product claim" the moment the excluded set
        // fell to one, which is what deleting the four demo manifests did.
        $one = count($excluded) === 1;
        $block .= ' ' . ucfirst(capdoc_count_word(count($excluded))) . ' excluded fixture manifest'
            . ($one ? '' : 's') . ' (' . implode(', ', $excluded)
            . ($one ? ') ships' : ') ship') . ' with the agent for regression use only and '
            . ($one ? 'carries' : 'carry') . ' no product claim.';
    }
    return $block . "\n" . CAPDOC_README_END;
}

function capdoc_count_word(int $count): string {
    return [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten'][$count] ?? (string) $count;
}

function capdoc_expected_readme(string $repo, string $block): string {
    $current = (string) file_get_contents($repo . CAPDOC_README_FILE);
    $pattern = '/' . preg_quote(CAPDOC_README_BEGIN, '/') . '.*?' . preg_quote(CAPDOC_README_END, '/') . '/s';
    if (preg_match($pattern, $current) !== 1) {
        throw new RuntimeException(
            'README.md has no ' . CAPDOC_README_BEGIN . ' … ' . CAPDOC_README_END . ' block to regenerate'
        );
    }
    // preg_replace would interpret $ and \ sequences inside the generated
    // block as backreferences; the block is data, so it is spliced by offset.
    preg_match($pattern, $current, $match, PREG_OFFSET_CAPTURE);
    return substr($current, 0, (int) $match[0][1]) . $block
        . substr($current, (int) $match[0][1] + strlen((string) $match[0][0]));
}

/** @return array<string,string> path => expected bytes */
function capdoc_build(string $repo): array {
    $manifests = capdoc_manifests($repo . CAPDOC_MANIFEST_DIR);
    $dispositions = capdoc_dispositions($repo);
    $platform = capdoc_platform($repo);
    capdoc_cross_check($manifests, $dispositions);
    $block = capdoc_readme_block($manifests, $dispositions, $platform);
    return [
        $repo . CAPDOC_DOC_FILE => capdoc_doc($manifests, $dispositions, $platform),
        $repo . CAPDOC_README_FILE => capdoc_expected_readme($repo, $block),
    ];
}

/** Name the drift instead of merely reporting it, so a failed gate is actionable from its own output. */
function capdoc_drift(string $expected, string $actual): string {
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $count = max(count($expectedLines), count($actualLines));
    for ($i = 0; $i < $count; $i++) {
        $want = $expectedLines[$i] ?? '<end of file>';
        $have = $actualLines[$i] ?? '<end of file>';
        if ($want !== $have) {
            return 'first difference at line ' . ($i + 1) . "\n"
                . '    on disk:   ' . capdoc_excerpt($have) . "\n"
                . '    generated: ' . capdoc_excerpt($want);
        }
    }
    return 'files differ in trailing bytes only';
}

function capdoc_excerpt(string $line): string {
    return mb_strlen($line) > 120 ? mb_substr($line, 0, 117) . '...' : $line;
}

function capdoc_run(string $repo, bool $check): void {
    $expected = capdoc_build($repo);
    if ($check) {
        $stale = [];
        foreach ($expected as $path => $content) {
            $relative = str_replace($repo . '/', '', $path);
            if (!is_file($path)) {
                $stale[] = $relative . ': absent';
                continue;
            }
            $actual = (string) file_get_contents($path);
            if ($actual !== $content) {
                $stale[] = $relative . ': ' . capdoc_drift($content, $actual);
            }
        }
        if ($stale !== []) {
            throw new RuntimeException(
                "generated product prose is stale; run `php tools/capability-doc.php generate`\n  "
                . implode("\n  ", $stale)
            );
        }
        fwrite(STDOUT, "capability doc check: manifests, dispositions, and product prose agree\n");
        return;
    }
    foreach ($expected as $path => $content) {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('could not write ' . $path);
        }
    }
    fwrite(STDOUT, "generated docs/capabilities.md and the README capability summary\n");
}

try {
    $command = $argv[1] ?? '--check';
    if ($command === 'generate') {
        capdoc_run($repo, false);
    } elseif ($command === '--check' || $command === 'check') {
        capdoc_run($repo, true);
    } else {
        throw new RuntimeException('usage: php tools/capability-doc.php [generate|--check]');
    }
} catch (Throwable $e) {
    capdoc_fail($e->getMessage());
}
