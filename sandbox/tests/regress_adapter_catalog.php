<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for DUO-3339:
 * the installed-adapter catalog — `duo adapter list|inspect|doctor` — and the
 * reporting half of the source scan it is built on
 * (`AdapterSources::survey()`).
 *
 * Nothing is faked. Every CLI check below runs the REAL host command as a
 * subprocess (`php cli/duo adapter …`) against the REAL shipped manifest
 * library and against REAL site-repository fixtures this test writes to
 * scratch directories, and reads the real exit code and the real
 * stdout/stderr. The command is WordPress-free by construction, so the honest
 * way to test it is to run it. Same idiom as
 * sandbox/tests/regress_manifest_validate.sh's harness (DUO-3327).
 *
 * The suite also loads the engine IN-PROCESS, for one claim a subprocess
 * cannot make: that a refusal ROW carries `AdapterSources::discover()`'s own
 * message BYTE FOR BYTE. Each refusal fixture is run twice — once through
 * `discover()`, whose exception message is captured here, and once through the
 * catalog, whose JSON row is captured from the subprocess — and the two
 * strings are compared. A reporting layer that paraphrased the engine, or a
 * scan that grew a second copy of a rule, fails exactly there. That is the
 * whole architectural claim of the survey/discover split, so it is a failing
 * check rather than a comment.
 *
 * What this deliberately does NOT cover, because it genuinely needs a live
 * target — every check the command itself prints as deferred: whether a
 * declared plugin or theme is installed/active/in range, whether provider code
 * answers under its declared identity, and whether a certification claim holds
 * for one environment's WordPress/PHP/database. Those belong to
 * regress_provider_contract_live.sh and `duo capabilities <env>`; this suite
 * proves the command SAYS it did not do them, on every run.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and
 * the script exits 1.
 */

$repo = dirname(__DIR__, 2);

// Resolved from agent/duo.php's own source, the header
// scripts/capability-registry.php established and cli/src/*.php's boot()
// methods reuse: fixtures must declare the spec version the engine actually
// supports, or every fixture would fail for a reason about this file.
if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_SPEC_VERSION', (int) $m[1]);
if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_AGENT_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_AGENT_VERSION', $m[1]);

// Policy::assert_single_site() is function_exists()-guarded precisely so the
// offline validators run outside WordPress; the in-process half below reaches
// Policy::load(), so the same switchable stub regress_adapter_sources.php uses
// keeps the REAL single-site gate live rather than skipped.
$GLOBALS['duo_test_is_multisite'] = false;
function is_multisite(): bool {
    return (bool) $GLOBALS['duo_test_is_multisite'];
}

require $repo . '/agent/src/Kernel/Canon.php';
require $repo . '/agent/src/Kernel/OptionState.php';
require $repo . '/agent/src/Policy/ManifestDispositions.php';
require $repo . '/agent/src/Adapter/CapabilityRegistry.php';
require $repo . '/agent/src/Policy/Policy.php';

use Duo\AdapterSources;
use Duo\Canon;
use Duo\Policy;

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

/**
 * Run the real host CLI and return its exact exit code and streams.
 *
 * $manifestDirOverride points the subprocess at a different shipped library
 * through DUO_MANIFESTS_DIR — the env var Policy::manifests_dir() reads, and
 * the only way to exercise a library that is NOT this repository's own.
 *
 * @param list<string> $args
 * @return array{exit:int, stdout:string, stderr:string}
 */
function duo(array $args, ?string $manifestDirOverride = null): array {
    global $repo;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/cli/duo') . ' adapter';
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $env = null;
    if ($manifestDirOverride !== null) {
        // proc_open REPLACES the environment when given one, so the inherited
        // env is merged rather than dropped — a bare DUO_MANIFESTS_DIR would
        // take PATH and HOME with it.
        $env = array_merge(getenv(), ['DUO_MANIFESTS_DIR' => $manifestDirOverride]);
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new \RuntimeException("could not run: $cmd");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** The parsed JSON report, or null when stdout is not a document. */
function report(array $result): ?array {
    $decoded = json_decode($result['stdout'], true);
    return is_array($decoded) ? $decoded : null;
}

function rm_rf(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                rm_rf("$path/$entry");
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function copy_tree(string $source, string $destination): void {
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);
        $target = $destination . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function scratch(string $label): string {
    $root = sys_get_temp_dir() . "/duo_regress_adapter_catalog_{$label}_" . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    register_shutdown_function(fn() => rm_rf($root));
    return (string) realpath($root);
}

/**
 * A site repo pinning $pins whose adapters/ source holds $adapters (basename
 * => manifest array, or a raw string for a deliberately malformed file).
 * $extra writes arbitrary repo-relative files, $links repo-relative symlinks.
 */
function site_repo(array $pins, array $adapters = [], array $extra = [], array $links = []): string {
    $root = scratch('repo');
    Canon::write_file("$root/site.duo.json", Canon::encode([
        'manifests' => $pins,
        'policy' => new \stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    foreach ($adapters as $name => $content) {
        $file = "$root/adapters/$name.json";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        if (is_string($content)) {
            file_put_contents($file, $content);
        } else {
            Canon::write_file($file, Canon::encode($content));
        }
    }
    foreach ($extra as $relative => $content) {
        $file = "$root/$relative";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }
    foreach ($links as $relative => $target) {
        $file = "$root/$relative";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        symlink($target, $file);
    }
    return $root;
}

/** Minimal, valid, purely declarative out-of-tree adapter. */
function site_adapter(string $name, array $extra = []): array {
    return $extra + [
        'name' => $name,
        'spec_version' => DUO_SPEC_VERSION,
        'option_autoload' => 'preserve',
        'options' => ['acme_widget_layout' => ['class' => 'authored']],
    ];
}

/** The message AdapterSources::discover() throws for this repository, if any. */
function discover_message(string $siteRepo): ?string {
    try {
        AdapterSources::discover(Policy::manifests_dir(), $siteRepo);
        return null;
    } catch (\Throwable $t) {
        return $t->getMessage();
    }
}

/** @return list<array<string,mixed>> */
function refusals_of(?array $report): array {
    return is_array($report) && is_array($report['refusals'] ?? null) ? $report['refusals'] : [];
}

function row_named(?array $report, string $name): ?array {
    foreach (is_array($report) ? ($report['adapters'] ?? []) : [] as $row) {
        if (($row['name'] ?? null) === $name) {
            return $row;
        }
    }
    return null;
}

$manifestDir = Policy::manifests_dir();

// ======================================================================
echo "\n== the real shipped library rows up, every adapter, with its derived tier ==\n";
// ======================================================================
$list = duo(['list', '--format=json']);
$listReport = report($list);
check($list['exit'] === 0, 'duo adapter list over the shipped library exits 0');
check($list['stderr'] === '', 'a clean run writes nothing to stderr (message: ' . trim($list['stderr']) . ')');
check(
    is_array($listReport) && ($listReport['format'] ?? null) === 'duo-adapter-catalog/v2'
    && ($listReport['command'] ?? null) === 'list'
    && ($listReport['spec_version'] ?? null) === DUO_SPEC_VERSION,
    'the JSON report parses and carries the duo-adapter-catalog/v2 envelope'
);
// DUO-3339/B2: v2 is not a courtesy bump. A consumer written against v1 that
// read a v2 report would believe it had seen every installed adapter while an
// entire SOURCE was missing from its world, so the two new top-level keys are
// asserted as part of the envelope rather than as a nice-to-have.
check(
    is_array($listReport['sources'] ?? null) && count($listReport['sources']) === 3
    && array_column($listReport['sources'], 'source') === ['shipped', 'site', 'plugin']
    && array_key_exists('not_installed', $listReport),
    'v2 carries the three-source inventory and the not_installed list at the top level'
);
$pluginSourceRow = null;
foreach ($listReport['sources'] as $sourceRow) {
    if ($sourceRow['source'] === 'plugin') {
        $pluginSourceRow = $sourceRow;
    }
}
check(
    is_array($pluginSourceRow) && $pluginSourceRow['scanned'] === false
    && $pluginSourceRow['path'] === null
    && str_contains((string) $pluginSourceRow['note'], 'WP_PLUGIN_DIR'),
    'and this WordPress-free host command reports the plugin source as NOT SCANNED with the reason, rather '
    . 'than omitting a source it cannot see'
);
check(
    ($listReport['summary']['plugin'] ?? null) === 0
    && ($listReport['summary']['not_installed'] ?? null) === 0,
    'the summary counts the third source and the not-loaded rows, so a zero is a measured zero'
);

$shippedFiles = [];
foreach (glob(rtrim($manifestDir, '/') . '/*.json') ?: [] as $file) {
    if (basename($file, '.json') !== 'dispositions') {
        $shippedFiles[] = basename($file, '.json');
    }
}
sort($shippedFiles, SORT_STRING);
$listedNames = array_column($listReport['adapters'] ?? [], 'name');
check(
    $listedNames === $shippedFiles && $shippedFiles !== [],
    'every shipped manifest is one row, and only those — ' . count($shippedFiles) . ' adapter(s)'
);
check(
    array_values(array_unique(array_column($listReport['adapters'] ?? [], 'source'))) === ['shipped'],
    'with no --repo every row is sourced from the shipped library alone'
);
$grammarStatuses = array_values(array_unique(array_column(
    array_column($listReport['adapters'] ?? [], 'grammar'),
    'status'
)));
check($grammarStatuses === ['ok'], 'every shipped manifest passes its isolated grammar verdict from the REAL loader');

$acf = row_named($listReport, 'acf');
check(
    is_array($acf) && $acf['trust_tier'] === 'compatibility_shim',
    "the shipped acf adapter REPORTS the compatibility_shim tier — the doctrine's fourth surface is displayed, not merely derived"
);
check(
    is_array($acf) && $acf['tier_basis'] === "interpreter 'acf'",
    'and names the declaration that put it there, so "says who?" has an answer inside the manifest (basis: '
    . (is_array($acf) ? $acf['tier_basis'] : '(no row)') . ')'
);
check(
    is_array($acf) && ($acf['executable_surfaces']['interpreter'] ?? null) === 'acf',
    'the interpreter it loads is listed as an executable surface in its own right'
);

$woo = row_named($listReport, 'woocommerce');
// DUO-3342 retired this manifest's regenerator: the lookup repair is a
// manifest-sourced PROVIDER now. The tier is unchanged — manifest-sourced
// provider code loads out of the agent's own tree exactly as a regenerator
// does — so this row still demonstrates what it was written to demonstrate,
// and demonstrates it better: woocommerce and the-events-calendar now reach
// one tier through two genuinely different declarations rather than through
// the same one.
check(
    is_array($woo) && $woo['trust_tier'] === 'compatibility_shim'
    && str_contains((string) $woo['tier_basis'], 'providers[0] source "manifest"'),
    'woocommerce reaches the same tier through a different declaration, and the basis says which (basis: '
    . (is_array($woo) ? $woo['tier_basis'] : '(no row)') . ')'
);
$wooProviders = [];
foreach (is_array($woo) ? $woo['required_providers'] : [] as $provider) {
    $wooProviders[(string) $provider['id']] = $provider;
}
check(
    isset($wooProviders['woocommerce-cache'])
    && $wooProviders['woocommerce-cache']['capabilities'] === ['invalidate_cache_groups']
    && $wooProviders['woocommerce-cache']['source'] === 'manifest'
    && $wooProviders['woocommerce-cache']['plugin'] === 'woocommerce/woocommerce.php',
    "woocommerce's required provider is named with the exact capability it must advertise, its source, and its owning plugin"
);
check(
    is_array($woo) && ($woo['executable_surfaces']['manifest_providers'] ?? [])
        === ['woocommerce-cache', 'woocommerce-product-lookups'],
    'and both of its manifest-shipped providers are listed as executable code — including the lookup repair '
    . 'DUO-3342 moved out of the regenerator channel, which the row below confirms is no longer a regenerator'
);
check(
    is_array($woo) && ($woo['executable_surfaces']['regenerators'] ?? null) === [],
    'while it declares no regenerator at all any more: the executable surface moved, it did not double up'
);

$core = row_named($listReport, 'core');
check(
    is_array($core) && $core['trust_tier'] === 'declarative_manifest'
    && str_contains((string) $core['tier_basis'], 'no interpreter, regenerator, provider, or native action'),
    'a data-only adapter reports the lowest tier and says explicitly that nothing executable is declared'
);
$agency = row_named($listReport, 'duo-agency-cpt');
check(
    is_array($agency) && $agency['trust_tier'] === 'plugin_provider'
    && str_contains((string) $agency['tier_basis'], "source 'plugin'"),
    'a plugin-owned provider reaches its own tier, distinct from a manifest-shipped one'
);

$statuses = array_values(array_unique(array_column($listReport['adapters'] ?? [], 'disposition_status')));
sort($statuses, SORT_STRING);
check(
    $statuses === ['certified', 'excluded', 'experimental'],
    "every shipped row carries its REVIEWED disposition status, from the reviewed set's own vocabulary (found: "
    . implode(', ', array_map(fn($s) => var_export($s, true), $statuses)) . ')'
);
check(
    array_values(array_unique(array_column($listReport['adapters'] ?? [], 'certification'))) === ['registry'],
    'and defers certification itself to the registry rather than restating it'
);

// ======================================================================
echo "\n== the tier and its basis come from ONE walk of the manifest ==\n";
// ======================================================================
$basisMismatch = [];
$emptyBasis = [];
foreach ($shippedFiles as $name) {
    $manifest = Canon::decode(Canon::read_file(rtrim($manifestDir, '/') . "/$name.json"));
    $decision = AdapterSources::tier_decision($manifest);
    if ($decision['trust_tier'] !== AdapterSources::trust_tier($manifest)) {
        $basisMismatch[] = $name;
    }
    if (trim($decision['tier_basis']) === '') {
        $emptyBasis[] = $name;
    }
}
check(
    $basisMismatch === [],
    'trust_tier() IS tier_decision() for every shipped manifest — one derivation, so the word and the receipt beside '
    . 'it cannot disagree' . ($basisMismatch === [] ? '' : ' (disagreed: ' . implode(', ', $basisMismatch) . ')')
);
check($emptyBasis === [], 'and no adapter reports a tier with no stated basis');

// ======================================================================
echo "\n== inspect merges every source that already knew something about one adapter ==\n";
// ======================================================================
$inspect = report(duo(['inspect', 'woocommerce', '--format=json']));
$adapter = is_array($inspect) ? ($inspect['adapter'] ?? null) : null;
check(
    is_array($adapter) && ($inspect['command'] ?? null) === 'inspect',
    'duo adapter inspect emits one adapter under the same envelope'
);
// Asserted as EQUALITY with the survey's own row rather than against a
// literal: "merged from the survey" is the property, and pinning the basis
// string here a second time only records which declaration happened to be
// first today (DUO-3342 moved it from the regenerator to providers[0]).
check(
    is_array($adapter) && is_array($woo)
    && (string) $adapter['tier_basis'] === (string) $woo['tier_basis']
    && (string) $adapter['tier_basis'] !== '',
    'MERGED FROM THE SURVEY: the tier basis rides on the inspected row (basis: '
    . (is_array($adapter) ? $adapter['tier_basis'] : '(no row)') . ')'
);
check(
    is_array($adapter) && is_array($adapter['disposition'] ?? null)
    && isset($adapter['disposition']['capabilities']['entity_sections']),
    'MERGED FROM dispositions.json: the reviewed entry, including a field that exists nowhere else '
    . '(capabilities.entity_sections)'
);
check(
    is_array($adapter) && is_array($adapter['claim'] ?? null)
    && preg_match('/^[0-9a-f]{64}$/D', (string) ($adapter['claim']['adapter_digest'] ?? '')) === 1
    && ($adapter['claim']['status'] ?? null) === 'certified',
    'MERGED FROM capabilities/registry.json: the generated claim with its exact adapter digest'
);
check(
    is_array($adapter) && ($adapter['claim']['surfaces'] ?? []) !== []
    && ($adapter['claim']['operations'] ?? []) !== [],
    'and the operations and surfaces the claim registers'
);
check(
    is_array($adapter)
    && in_array($adapter['verification']['evidence_status'] ?? null, ['current', 'candidate'], true),
    'VERIFICATION STRENGTH is the existing evidence.status word, not a minted scale (found: '
    . (is_array($adapter) ? var_export($adapter['verification']['evidence_status'] ?? null, true) : '?') . ')'
);
check(
    is_array($adapter) && in_array(
        $adapter['verification']['plugin_execution_status'] ?? null,
        ['verified', 'unverified', 'not-a-product-claim'],
        true
    ),
    "and the claim's own plugin_execution.status word, from the same closed set the registry validates"
);
$citedVerdicts = array_values(array_unique(array_column($adapter['verification']['tests'] ?? [], 'verdict')));
check(
    ($adapter['verification']['tests'] ?? []) !== []
        && $citedVerdicts === [(($adapter['verification']['evidence_status'] ?? null) === 'current' ? 'pass' : 'absent')],
    'and each named citation reflects whether its own subject record is current (verdicts: '
    . implode(', ', $citedVerdicts) . ')'
);
check(
    is_array($adapter) && isset($wooProviders['woocommerce-cache'])
    && $adapter['required_providers'] === $woo['required_providers'],
    'MERGED FROM THE MANIFEST: the required providers block is the same one list reports'
);

$unknown = duo(['inspect', 'no-such-adapter', '--format=json']);
check(
    $unknown['exit'] === 2 && str_contains($unknown['stderr'], 'no-such-adapter')
    && str_contains($unknown['stderr'], 'pass --repo=<site-repo>'),
    'inspecting a name nothing installs is a usage refusal (2) that names the other source it did not search'
);

// ======================================================================
echo "\n== a site repository's own adapters/ source joins the catalog ==\n";
// ======================================================================
$overlayRepo = site_repo(['core', 'acme-widget'], ['acme-widget' => site_adapter('acme-widget')]);
$overlay = duo(['list', '--repo=' . $overlayRepo, '--format=json']);
$overlayReport = report($overlay);
$siteRow = row_named($overlayReport, 'acme-widget');
check($overlay['exit'] === 0, 'a healthy overlay lists clean (exit 0)');
check(
    is_array($siteRow) && $siteRow['source'] === 'site'
    && $siteRow['path'] === 'adapters/acme-widget.json',
    'the out-of-tree adapter is a row of its own, at the repo-relative path its provenance records'
);
check(
    is_array($siteRow) && $siteRow['certification'] === 'uncertified' && $siteRow['disposition_status'] === null,
    'and is uncertified by construction with no reviewed entry, rather than blank-but-plausible'
);
check(
    is_array($siteRow) && $siteRow['grammar']['status'] === 'ok'
    && $siteRow['trust_tier'] === 'declarative_manifest',
    'a data-only site adapter still loads and still reports the lowest tier'
);
check(
    is_array($siteRow) && hash_equals(
        hash('sha256', Canon::encode(site_adapter('acme-widget'))),
        (string) $siteRow['sha256']
    ),
    "the row's sha256 is the CANONICAL manifest hash — the same basis the frozen provenance record binds"
);
check(
    is_array($overlayReport) && ($overlayReport['summary']['site'] ?? null) === 1
    && ($overlayReport['summary']['shipped'] ?? null) === count($shippedFiles),
    'the summary counts each source separately, so an overlay is never mistaken for a library'
);

// ======================================================================
echo "\n== the conditions discover() dies on are REPORTED, with its own words ==\n";
// ======================================================================
// Each fixture is judged twice: once through the real discover(), whose
// exception message is captured in-process, and once through the catalog,
// whose refusal row comes back from the subprocess. Equality of those two
// strings is the architectural claim — one scan, two modes.
$refusalCases = [
    'shadows_shipped' => site_repo(['core'], ['core' => site_adapter('core'), 'keeper' => site_adapter('keeper')]),
    'ambiguous_identity' => site_repo(['keeper'], [
        'mislabeled' => site_adapter('something-else'),
        'keeper' => site_adapter('keeper'),
    ]),
    // DUO-3314's signed-certification pass made one canonical lowercase-ASCII
    // slug the identity grammar for file names, pins, key ids, and frozen
    // records, so a case-variant name is now refused as a non-canonical
    // IDENTITY before the case-fold comparison it used to reach. The
    // case_collision guard is still in the scan as defense in depth; it is
    // simply no longer reachable through a file name, because two distinct
    // canonical slugs cannot case-fold onto each other.
    'invalid_adapter_name' => site_repo(['keeper'], ['CORE' => site_adapter('CORE'), 'keeper' => site_adapter('keeper')]),
    'reserved_name' => site_repo(['keeper'], ['keeper' => site_adapter('keeper')], [
        'adapters/dispositions.json' => "{}\n",
    ]),
    'nested_json' => site_repo(['keeper'], ['keeper' => site_adapter('keeper')], [
        'adapters/vendor/inner.json' => "{}\n",
    ]),
    'extension_case_mismatch' => site_repo(['keeper'], ['keeper' => site_adapter('keeper')], [
        'adapters/Loud.JSON' => "{}\n",
    ]),
    'malformed_manifest' => site_repo(['keeper'], [
        'keeper' => site_adapter('keeper'),
        'broken' => "{ this is not json\n",
    ]),
    // The three conditions DUO-3314 added to the same scan, each in its own
    // fixture so the byte-comparison below covers them individually rather
    // than only in the combined probe further down.
    'out_of_tree_privilege' => site_repo(['keeper'], [
        'keeper' => site_adapter('keeper'),
        'shimmy' => site_adapter('shimmy', ['interpreter' => 'acf']),
    ]),
    'certification_source' => site_repo(['keeper'], ['keeper' => site_adapter('keeper')], [
        'adapters/certifications/README' => "notes\n",
    ]),
    'certificate_invalid' => site_repo(['keeper'], [
        'keeper' => site_adapter('keeper'),
        'signed' => site_adapter('signed'),
    ], [
        'adapters/certifications/signed.json' => "not a certificate\n",
    ]),
];
foreach ($refusalCases as $code => $fixture) {
    $result = duo(['list', '--repo=' . $fixture, '--format=json']);
    $parsed = report($result);
    $rows = refusals_of($parsed);
    $matching = array_values(array_filter($rows, static fn(array $r): bool => $r['code'] === $code));
    check(
        count($matching) === 1,
        "$code is reported as exactly one refusal row rather than thrown (rows: "
        . implode(', ', array_column($rows, 'code')) . ')'
    );
    // Dedupe on the offending FILE, not on (code, paths): the defect this
    // pins was two rows with DIFFERENT codes about one file, which a
    // code-qualified key waves through. Every refuse() call site puts the
    // offending file first, and no fixture legitimately draws two rows
    // about one file.
    $firstPaths = array_map(static fn(array $r): string => (string) (((array) $r['paths'])[0] ?? ''), $rows);
    check(
        $firstPaths === array_unique($firstPaths),
        "$code: one wrong file draws one refusal, not several — a reserved name that is ALSO not a manifest "
        . 'used to be reported twice, the second time as an ambiguous identity nobody claimed (rows: '
        . implode(', ', array_column($rows, 'code')) . ')'
    );
    // DUO-3339/B2: every row states which source it is about and how far it
    // reaches. Both are load-bearing rather than decoration — grammar_verdict()
    // stops judging site adapters on `scope: source`, and
    // AdapterCatalog::blockers() attributes a pin-set failure from `source`
    // instead of sniffing `paths` for a leading `adapters/`.
    check(
        ($matching[0]['source'] ?? null) === 'site'
        && ($matching[0]['scope'] ?? null) === 'source',
        "$code carries source=site scope=source — every condition in the operator's OWN source is still "
        . 'whole-directory (found: ' . (string) ($matching[0]['source'] ?? '(absent)') . '/'
        . (string) ($matching[0]['scope'] ?? '(absent)') . ')'
    );
    check($result['exit'] === 1, "$code makes the run non-zero (exit {$result['exit']})");
    check(
        is_array($parsed) && ($parsed['adapters'] ?? []) !== [],
        "$code leaves stdout intact — the rest of the catalog still reports"
    );
    check(
        row_named($parsed, 'keeper') !== null,
        "$code does not take the source's other, valid adapters down with it — 'keeper' is still a row"
    );
    check(
        $matching !== [] && trim((string) $matching[0]['remediation']) !== '',
        "$code carries an actionable remediation of its own"
    );
    check(
        $matching !== [] && ($matching[0]['paths'] ?? []) !== [],
        "$code names the exact file(s) it is about (paths: "
        . implode(', ', $matching[0]['paths'] ?? []) . ')'
    );
    // THE MASQUERADE CHECK. A refused file must not ALSO appear as an
    // installed adapter: a refusal row beside a row that lists the same file as
    // usable would say "we refuse this" and "here it is, installed" in one
    // report, and for ambiguous_identity specifically that row would carry the
    // file's name while the manifest inside declares another — an adapter
    // answering to a name it never claimed, which is exactly the identity
    // confusion the whole refusal exists to prevent. Asserted by PATH so it
    // holds for every code uniformly, including the ones whose file is not an
    // adapter at all.
    $refusedPaths = [];
    foreach ($rows as $refusalRow) {
        foreach ((array) ($refusalRow['paths'] ?? []) as $path) {
            if (str_starts_with((string) $path, 'adapters/')) {
                $refusedPaths[(string) $path] = true;
            }
        }
    }
    $listedRefused = array_values(array_filter(
        $parsed['adapters'] ?? [],
        static fn(array $r): bool => isset($refusedPaths[(string) $r['path']])
    ));
    check(
        $refusedPaths !== [] && $listedRefused === [],
        "$code: the refused file is ABSENT from the adapter rows — refused and installed are never both true "
        . '(listed anyway: ' . implode(', ', array_column($listedRefused, 'path')) . ')'
    );

    if ($code === 'ambiguous_identity') {
        check(
            row_named($parsed, 'mislabeled') === null && row_named($parsed, 'something-else') === null,
            'and an ambiguous-identity file is listed under NEITHER name — not the file name it would be pinned '
            . 'by, nor the name its manifest declares'
        );
    }
    // malformed_manifest is the one deliberate message delta (see the two
    // stated deltas at AdapterSources::scan()): discover() used to propagate
    // Canon's bare exception, and the row wraps it so it names the file as a
    // site adapter. Every other code — including the three DUO-3314 added —
    // is a refusal discover() owns verbatim, so the byte-comparison covers it.
    if ($code === 'malformed_manifest') {
        continue;
    }
    $thrown = discover_message($fixture);
    check(
        $thrown !== null && $matching !== [] && $thrown === $matching[0]['message'],
        "$code's reported message is discover()'s own, byte for byte — one scan, two modes"
    );
}

$symlinkRepo = site_repo(['keeper'], ['keeper' => site_adapter('keeper')]);
symlink($symlinkRepo . '/site.duo.json', $symlinkRepo . '/adapters/linked.json');
$symlinkResult = duo(['list', '--repo=' . $symlinkRepo, '--format=json']);
$symlinkRows = refusals_of(report($symlinkResult));
$symlinkMatch = array_values(array_filter($symlinkRows, static fn(array $r): bool => $r['code'] === 'symlink_source'));
check(
    count($symlinkMatch) === 1 && $symlinkResult['exit'] === 1,
    'a symlink inside the adapter source is reported, not followed (rows: '
    . implode(', ', array_column($symlinkRows, 'code')) . ')'
);
check(
    $symlinkMatch !== [] && discover_message($symlinkRepo) === $symlinkMatch[0]['message'],
    "and with discover()'s own message"
);

$linkedSourceRepo = site_repo(['keeper']);
rm_rf($linkedSourceRepo . '/adapters');
$elsewhere = scratch('elsewhere');
Canon::write_file("$elsewhere/keeper.json", Canon::encode(site_adapter('keeper')));
symlink($elsewhere, $linkedSourceRepo . '/adapters');
$linkedResult = duo(['list', '--repo=' . $linkedSourceRepo, '--format=json']);
$linkedReport = report($linkedResult);
$linkedRows = refusals_of($linkedReport);
check(
    array_column($linkedRows, 'code') === ['source_not_in_repository'] && $linkedResult['exit'] === 1,
    'an adapters/ directory that is itself a link is refused as a source (rows: '
    . implode(', ', array_column($linkedRows, 'code')) . ')'
);
check(
    row_named($linkedReport, 'keeper') === null,
    'and nothing behind that link is surveyed — a repo-relative path no checkout holds is never reported as installed'
);
check(
    ($linkedReport['adapters'] ?? []) !== [],
    'while the shipped library still reports, so the operator sees where the catalog DID come from'
);

// ======================================================================
echo "\n== doctor: the readiness verdict, the refusals, and the deferred list ==\n";
// ======================================================================
$healthyDoctor = duo(['doctor', '--format=json']);
$healthyReport = report($healthyDoctor);
check(
    $healthyDoctor['exit'] === 0 && ($healthyReport['status'] ?? null) === 'ok',
    'doctor over a healthy shipped library exits 0'
);
check(
    ($healthyReport['blockers'] ?? null) === [],
    'with no --repo there is no pin set, so doctor reports no readiness verdict rather than inventing one from the whole library'
);

$blockedRepo = site_repo(['core', 'acme-widget'], ['acme-widget' => site_adapter('acme-widget')]);
$blockedDoctor = duo(['doctor', '--repo=' . $blockedRepo, '--format=json']);
$blockedReport = report($blockedDoctor);
$sourceBlockers = array_values(array_filter(
    $blockedReport['blockers'] ?? [],
    static fn(array $r): bool => ($r['code'] ?? null) === 'adapter_source_uncertified'
));
check($blockedDoctor['exit'] === 1, 'a pinned uncertified out-of-tree adapter makes doctor non-zero');
check(
    count($sourceBlockers) === 1
    && $sourceBlockers[0]['source'] === 'site'
    && $sourceBlockers[0]['trust_tier'] === 'declarative_manifest'
    && trim((string) $sourceBlockers[0]['remediation']) !== '',
    'and its blocker row carries source, trust tier, and remediation — the same row `duo status` renders'
);
// The property is NON-CONTAGION: an uncertified site adapter must not make the
// certified shipped adapter beside it look blocked. `evidence_not_current` is
// carved out because it is a fact about this WORKING TREE rather than about
// `core` — a branch that changed manifest or provider bytes regenerates the
// registry into `candidate` status until its certification bundle runs, and
// every shipped adapter carries that one row meanwhile. Carving it out keeps
// the check meaningful on both sides of the bundle instead of green only on a
// tree whose evidence happens to be current; any OTHER core blocker still
// fails, which is the contagion this was written to catch.
$coreBlockers = array_values(array_filter(
    $blockedReport['blockers'] ?? [],
    static fn(array $r): bool => ($r['name'] ?? '') === 'core'
        && ($r['code'] ?? '') !== 'evidence_not_current'
));
check(
    $coreBlockers === [],
    'while the certified shipped adapter beside it contributes no blocker of its own (found: '
    . implode(', ', array_column($coreBlockers, 'code')) . ')'
);

// A manifest-shipped provider is an executable package fact even on the
// WordPress-free host: doctor must report an absent provider file without
// loading provider PHP or pretending to know plugin liveness. Copy the real
// reviewed library into scratch, remove only the provider file, and exercise
// the actual CLI doctor subprocess against a real pinned manifest.
$missingProviderLibrary = scratch('missing-provider-library');
copy_tree($manifestDir, $missingProviderLibrary);
unlink($missingProviderLibrary . '/providers/woocommerce-cache.php');
$missingManifest = Canon::decode(Canon::read_file($missingProviderLibrary . '/woocommerce.json'));
$missingDispositions = Canon::decode(Canon::read_file($missingProviderLibrary . '/dispositions.json'));
$missingRegistry = Canon::decode(Canon::read_file($missingProviderLibrary . '/capabilities/registry.json'));
$missingAdapterDigest = \Duo\CapabilityRegistry::adapter_digest(
    $missingManifest,
    $missingDispositions['manifests']['woocommerce'],
    $missingProviderLibrary
);
$missingRegistry['manifests']['woocommerce']['adapter_digest'] = $missingAdapterDigest;
// This scratch directory is an intentionally incomplete shipped-library
// fixture, not either supported full-source or deployed-agent layout. Keep
// every copied claim candidate so unrelated current scoped records do not try
// to verify target-installed agent files that this fixture never copied. The
// Woo row is then free to exercise only the missing-provider blocker below.
foreach (['manifests', 'profiles'] as $section) {
    foreach ($missingRegistry[$section] as &$missingClaim) {
        $missingClaim['evidence']['bundle_digest'] = null;
        $missingClaim['evidence']['closure_digest'] = null;
        $missingClaim['evidence']['git_revision'] = null;
        $missingClaim['evidence']['status'] = 'candidate';
        $missingClaim['evidence']['subject_digest'] = null;
    }
    unset($missingClaim);
}
Canon::write_file(
    $missingProviderLibrary . '/capabilities/registry.json',
    Canon::encode($missingRegistry)
);
$missingProviderRepo = site_repo(['woocommerce']);
$missingProviderDoctor = duo(
    ['doctor', '--repo=' . $missingProviderRepo, '--format=json'],
    $missingProviderLibrary
);
$missingProviderReport = report($missingProviderDoctor);
$missingProviderBlockers = array_values(array_filter(
    $missingProviderReport['blockers'] ?? [],
    static fn(array $row): bool => ($row['code'] ?? null) === 'provider_code_unavailable'
));
check(
    $missingProviderDoctor['exit'] === 1
    && count($missingProviderBlockers) === 1
    && ($missingProviderBlockers[0]['provider'] ?? null) === 'woocommerce-cache'
    && ($missingProviderBlockers[0]['manifest'] ?? null) === 'woocommerce',
    'adapter doctor reports an absent manifest-shipped provider as a structured blocker without loading provider PHP '
    . '(exit ' . $missingProviderDoctor['exit'] . '; codes: '
    . implode(', ', array_column($missingProviderReport['blockers'] ?? [], 'code')) . '; reasons: '
    . implode(' | ', array_column($missingProviderReport['blockers'] ?? [], 'reason')) . ')'
);

$brokenList = report(duo(['list', '--repo=' . $refusalCases['shadows_shipped'], '--format=json']));
$brokenShippedGrammar = array_values(array_unique(array_column(
    array_column(array_filter(
        $brokenList['adapters'] ?? [],
        static fn(array $r): bool => $r['source'] === 'shipped'
    ), 'grammar'),
    'status'
)));
check(
    $brokenShippedGrammar === ['ok'],
    'one broken site file does not turn every unrelated SHIPPED adapter into a grammar error: discover() refuses '
    . 'whole-directory, so a shipped row is judged without the site half while a refusal stands (found: '
    . implode(', ', $brokenShippedGrammar) . ')'
);

$brokenInspect = duo(['inspect', 'keeper', '--repo=' . $refusalCases['shadows_shipped'], '--format=json']);
$brokenInspectReport = report($brokenInspect);
check(
    is_array($brokenInspectReport) && ($brokenInspectReport['adapter']['name'] ?? null) === 'keeper'
    && $brokenInspect['exit'] === 1,
    'inspecting a site adapter that SHARES a broken source still reports it (exit '
    . $brokenInspect['exit'] . ') rather than dying on the neighbouring refusal'
);
check(
    ($brokenInspectReport['adapter']['grammar']['status'] ?? null) === 'blocked_by_source_refusal'
    && str_contains((string) ($brokenInspectReport['adapter']['grammar']['message'] ?? ''), 'shadows_shipped'),
    'and says its own grammar was never judged, naming the sibling refusal that stopped the whole source — not '
    . "the neighbouring file's message reprinted as this adapter's verdict"
);

$shadowDoctor = duo(['doctor', '--repo=' . $refusalCases['shadows_shipped'], '--format=json']);
$shadowReport = report($shadowDoctor);
check(
    $shadowDoctor['exit'] === 1 && refusals_of($shadowReport) !== [],
    'doctor reports a shadowed pair rather than dying on it — the one command an operator can still run'
);
check(
    array_column($shadowReport['blockers'] ?? [], 'code') === ['pin_set_unloadable'],
    'and says plainly that the pin set has no readiness verdict while that refusal stands'
);

foreach ([
    'list (passing)' => $listReport,
    'list (failing)' => report(duo(['list', '--repo=' . $refusalCases['shadows_shipped'], '--format=json'])),
    'inspect' => $inspect,
    'doctor (passing)' => $healthyReport,
    'doctor (failing)' => $shadowReport,
] as $label => $document) {
    check(
        is_array($document) && count($document['deferred'] ?? []) >= 6
        && array_values(array_unique(array_column($document['deferred'] ?? [], 'status'))) === ['deferred'],
        "the deferred list is emitted on $label — silence can never read as 'everything here is verified'"
    );
}
$deferredSurfaces = implode("\n", array_map(
    static fn(array $r): string => $r['surface'] . ' ' . $r['check'] . ' ' . $r['why'],
    $healthyReport['deferred'] ?? []
));
check(
    str_contains($deferredSurfaces, 'CapabilityRegistry::report()')
    && str_contains($deferredSurfaces, 'duo capabilities <env>'),
    'doctor is offline-honest about certification: it names the live evaluation it did NOT perform, and where to get it'
);
check(
    str_contains($deferredSurfaces, 'Providers::diagnose()') && str_contains($deferredSurfaces, 'duo plan <env>'),
    'and about provider negotiation, pointing at the plan rows that answer it'
);
check(
    str_contains($deferredSurfaces, 'never loaded') && str_contains($deferredSurfaces, 'duo manifest-validate'),
    'and states that the manifest-shipped PHP it REPORTS is deliberately not loaded here'
);
check(
    str_contains($deferredSurfaces, 'the pinned SET'),
    'and that each grammar verdict is an isolated load, so a pin set is a different question'
);
// DUO-3339/B2 replaced this row's claim outright. It used to say the engine
// had "exactly two adapter sources" and that a plugin-bundled manifest "is
// discovered by nothing" — both true when it was written and both false the
// moment the plugin source landed. The replacement is rendered FROM
// survey()['sources'] rather than restated in prose, which is what stops the
// two CLIs from drifting into two descriptions of one scan, so the assertion
// checks the RENDERED source words, not a sentence.
check(
    !str_contains($deferredSurfaces, 'discovered by nothing')
    && !str_contains($deferredSurfaces, 'exactly two adapter sources'),
    'the deferred list no longer claims two sources and a manifest discovered by nothing — both went false in B2'
);
check(
    str_contains($deferredSurfaces, 'THREE adapter sources')
    && str_contains($deferredSurfaces, 'duo-adapter.json')
    && str_contains($deferredSurfaces, 'wp duo adapter-survey'),
    'it names the third source, its conventional file, and the command that reports it on the target'
);
check(
    str_contains($deferredSurfaces, 'This process scanned shipped (site, plugin not scanned)'),
    'and the sentence is BUILT from this run\'s own source inventory, so it cannot describe a scan that did not happen'
);
check(
    str_contains($deferredSurfaces, 'not a fourth source')
    && str_contains($deferredSurfaces, 'adapters/certifications/<name>.json'),
    'and it restates the package decision: a distributed package installs INTO the site source, certificate included'
);

// ======================================================================
echo "\n== the signed-certification conditions DUO-3314 added to the same scan ==\n";
// ======================================================================
// Those conditions were written as THROWS, against the pre-split file. Each
// one has to work identically in throw mode and report a row in collect mode,
// or the catalog would die on exactly the installations it exists to explain.
$certRepo = site_repo(['keeper'], [
    'keeper' => site_adapter('keeper'),
    'shimmy' => site_adapter('shimmy', ['interpreter' => 'acf']),
], [
    'adapters/certifications/keeper.json' => "not a certificate\n",
    'adapters/certifications/README' => "notes\n",
]);
$certResult = duo(['list', '--repo=' . $certRepo, '--format=json']);
$certReport = report($certResult);
$certCodes = array_column(refusals_of($certReport), 'code');
sort($certCodes, SORT_STRING);
check(
    $certCodes === ['certificate_invalid', 'certification_source', 'out_of_tree_privilege'],
    'a garbage certificate, a stray file in adapters/certifications/, and an out-of-tree manifest reaching for an '
    . 'interpreter are three refusal ROWS, not three ways to kill the command (rows: '
    . implode(', ', $certCodes) . ')'
);
check($certResult['exit'] === 1, 'and the run is non-zero');
check(
    row_named($certReport, 'shimmy') === null,
    'the manifest asking for executable privilege it cannot have is ABSENT from the adapter rows — discovery '
    . 'refuses it, so listing it as installed would be the masquerade the boundary exists to stop'
);
check(
    row_named($certReport, 'keeper') === null,
    'and so is the adapter whose companion certificate does not verify — a signature the engine rejects is an '
    . 'authority claim, never a downgrade to unsigned'
);
foreach (refusals_of($certReport) as $refusalRow) {
    check(
        trim((string) $refusalRow['remediation']) !== '' && ($refusalRow['paths'] ?? []) !== [],
        'the ' . $refusalRow['code'] . ' row names its file(s) and carries a remediation'
    );
}
// The same three conditions must still be THROWS on the loading path.
foreach (['certification_source', 'certificate_invalid', 'out_of_tree_privilege'] as $code) {
    $thrown = discover_message($certRepo);
    check(
        $thrown !== null,
        "discover() still refuses this repository outright (it is not merely reported): "
        . substr((string) $thrown, 0, 90) . '...'
    );
    break;
}

// The certification WORD is DUO-3314's, drawn from DUO-3314's own two
// predicates. A real Ed25519 fixture is regress_site_adapter_certification's
// subject and is not rebuilt here; what this suite owns is that the catalog
// reads the same state rather than inventing a parallel vocabulary.
$surveySource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterSources.php');
check(
    str_contains($surveySource, "? 'third_party_signed' : 'signed_unpinned'")
    && str_contains($surveySource, '$sources->is_certified($name)'),
    "survey() derives a site row's certification from is_certified() and the explicit-pin state — the same two "
    . 'facts diagnostics() uses for `wp duo capabilities`, so the two surfaces cannot disagree about whether an '
    . 'adapter is signed'
);
check(
    str_contains($surveySource, "&& \$grammar['status'] === self::GRAMMAR_OK")
    && str_contains($surveySource, "? 'third_party_signed'"),
    "ELEVATION IS GATED ON THE ROW'S OWN GRAMMAR. bind_explicit_pins() checks the digest's SHAPE, never its value; "
    . 'the engine compares the value and refuses the repository when it disagrees, so a well-formed but WRONG '
    . '64-hex pin would otherwise read as promotion-ready third-party evidence in a catalog while every real '
    . 'command refused the repo. Source-pinned rather than executed because reaching the signed branch at all '
    . 'needs a trusted authority, a signed envelope, and a complete passing bundle — regress_site_adapter_'
    . 'certification.php builds exactly that, in ~350 lines of fixture this suite would have to duplicate rather '
    . 'than import (its helpers are inline, not a shared include). The neighbouring unjudged rule below IS executed'
);
$catalogWords = array_values(array_unique(array_map(
    static fn(array $r) => $r['certification'],
    array_merge($listReport['adapters'], $overlayReport['adapters'] ?? [])
)));
sort($catalogWords, SORT_STRING);
check(
    $catalogWords === ['registry', 'uncertified'],
    'and the words it actually emits on these fixtures are from that closed set, never a minted extra (found: '
    . implode(', ', array_map(static fn($w) => var_export($w, true), $catalogWords)) . ')'
);

// R-5's shape, executed: a refused certification SOURCE means no companion was
// paired or opened, so a site row there was never judged rather than judged
// negative — the same distinction blocked_by_source_refusal draws for grammar.
$unjudgedRepo = site_repo(['keeper'], ['keeper' => site_adapter('keeper')], [
    'adapters/certifications/README' => "notes\n",
]);
$unjudgedReport = report(duo(['list', '--repo=' . $unjudgedRepo, '--format=json']));
$unjudgedRow = row_named($unjudgedReport, 'keeper');
check(
    is_array($unjudgedRow) && $unjudgedRow['certification'] === 'certification_unjudged',
    'a site adapter whose certification source is refused reports certification_unjudged, not uncertified — no '
    . 'certificate was paired, so "unsigned" would be a verdict on evidence nobody read (found: '
    . var_export(is_array($unjudgedRow) ? $unjudgedRow['certification'] : null, true) . ')'
);
check(
    array_column(refusals_of($unjudgedReport), 'code') === ['certification_source'],
    'and the refusal that caused it is the one row explaining why'
);

// ======================================================================
echo "\n== a broken source is never reported as a healthy one ==\n";
// ======================================================================
// `inspect` reports ONE adapter, but exit 0 is a claim about the whole run.
$inspectInBrokenSource = duo(['inspect', 'core', '--repo=' . $refusalCases['shadows_shipped'], '--format=json']);
$inspectBrokenReport = report($inspectInBrokenSource);
check(
    $inspectInBrokenSource['exit'] === 1 && ($inspectBrokenReport['status'] ?? null) === 'error',
    'inspecting a perfectly healthy SHIPPED adapter in a repository that has refusals still exits 1 — a zero exit '
    . 'would tell a script the source is fine (exit ' . $inspectInBrokenSource['exit'] . ')'
);
check(
    // array_key_exists, not ??: null is the ANSWER here, and `??` cannot tell
    // "reported as unanswered" from "never reported at all".
    is_array($inspectBrokenReport['adapter'] ?? null)
    && array_key_exists('verdict', $inspectBrokenReport['adapter'])
    && $inspectBrokenReport['adapter']['verdict'] === null,
    'and the verdict comes back null because the pin set would not load, rather than defaulting to certified'
);
check(
    refusals_of($inspectBrokenReport) !== [],
    'and inspect carries the refusal rows too, so the report says WHY it is non-zero'
);

// A site.duo.json that is not JSON: every grammar verdict loads that file, so
// the failure has exactly one cause and must be reported exactly once.
$badPolicyRepo = site_repo(['core'], ['acme-widget' => site_adapter('acme-widget')]);
file_put_contents($badPolicyRepo . '/site.duo.json', "{ \"manifests\": [\"core\",\n");
$badPolicy = duo(['list', '--repo=' . $badPolicyRepo, '--format=json']);
$badPolicyReport = report($badPolicy);
$policyRefusals = array_values(array_filter(
    refusals_of($badPolicyReport),
    static fn(array $r): bool => $r['code'] === 'site_policy_unreadable'
));
check(
    count($policyRefusals) === 1 && str_contains((string) $policyRefusals[0]['paths'][0], 'site.duo.json'),
    'an unparseable site.duo.json is ONE refusal row naming that file (rows: '
    . implode(', ', array_column(refusals_of($badPolicyReport), 'code')) . ')'
);
$badPolicyShipped = array_values(array_unique(array_column(
    array_column(array_filter(
        $badPolicyReport['adapters'] ?? [],
        static fn(array $r): bool => $r['source'] === 'shipped'
    ), 'grammar'),
    'status'
)));
check(
    $badPolicyShipped === ['ok'],
    'and NOT fifteen identical unattributed grammar errors: the shipped rows fall back to the no-site verdict '
    . 'the same way every other whole-directory refusal makes them (found: ' . implode(', ', $badPolicyShipped) . ')'
);
check($badPolicy['exit'] === 1, 'while the run itself is still non-zero');

// A site adapter whose SOURCE is refused was never read, so its grammar
// verdict is a third word rather than an error about bytes nobody opened.
$blockedSiteRow = row_named($badPolicyReport, 'acme-widget');
check(
    is_array($blockedSiteRow) && $blockedSiteRow['grammar']['status'] === 'blocked_by_source_refusal',
    'a site adapter in a refused source reports blocked_by_source_refusal, not `error` (found: '
    . (is_array($blockedSiteRow) ? $blockedSiteRow['grammar']['status'] : '(no row)') . ')'
);
check(
    is_array($blockedSiteRow)
    && str_contains((string) $blockedSiteRow['grammar']['message'], 'was not judged')
    && str_contains((string) $blockedSiteRow['grammar']['message'], 'site_policy_unreadable'),
    "and says its own grammar was not judged, naming the refusal that stopped it — rather than reprinting another "
    . "file's refusal as this adapter's verdict"
);
check(
    ($badPolicyReport['summary']['grammar_error'] ?? null) === 0
    && ($badPolicyReport['summary']['grammar_unjudged'] ?? null) === 1,
    'and the summary counts unjudged apart from broken, so "how many of my manifests are wrong" stays truthful '
    . '(errors: ' . var_export($badPolicyReport['summary']['grammar_error'] ?? null, true)
    . ', unjudged: ' . var_export($badPolicyReport['summary']['grammar_unjudged'] ?? null, true) . ')'
);

$badPolicyDeferred = implode("\n", array_column(report(duo(['list', '--repo=' . $badPolicyRepo, '--format=json']))['deferred'], 'why'));
check(
    str_contains($badPolicyDeferred, 'produced with no site policy after all'),
    'and the deferred list stops claiming the verdicts were produced against this site.duo.json, because they '
    . 'were not'
);
check(
    !str_contains(implode("\n", array_column($listReport['deferred'], 'why')), 'verdicts below'),
    'the deferred rows say "above", which is where the table actually is'
);

// pin_set_unloadable used to hardcode source=shipped, sending an operator
// whose SITE adapter broke the pin set to the wrong directory.
$shadowBlockers = report(duo(['doctor', '--repo=' . $refusalCases['shadows_shipped'], '--format=json']))['blockers'];
check(
    array_column($shadowBlockers, 'code') === ['pin_set_unloadable']
    && ($shadowBlockers[0]['source'] ?? null) === 'site',
    'a pin set broken by a SITE file reports source=site on its blocker row (found: '
    . var_export($shadowBlockers[0]['source'] ?? null, true) . ')'
);

// R-4: "not installed" and "installed but refused" are different answers, and
// only one of them is a usage error.
$refusedInspect = duo(['inspect', 'signed', '--repo=' . $refusalCases['certificate_invalid'], '--format=json']);
$refusedInspectReport = report($refusedInspect);
check(
    $refusedInspect['exit'] === 1 && $refusedInspect['stderr'] === '',
    'inspecting an adapter whose own certificate was refused exits 1 with a report, not 2 with a usage error — '
    . 'the file is on disk and the operator was told it does not exist (exit ' . $refusedInspect['exit'] . ')'
);
check(
    is_array($refusedInspectReport)
    && array_column($refusedInspectReport['refused'] ?? [], 'code') === ['certificate_invalid'],
    'and the report names the refusal standing against it'
);
check(
    duo(['inspect', 'genuinely-absent', '--repo=' . $refusalCases['certificate_invalid']])['exit'] === 2,
    'while a name nothing installs is still the usage refusal it always was'
);
$refusedText = duo(['inspect', 'signed', '--repo=' . $refusalCases['certificate_invalid']]);
check(
    str_contains($refusedText['stdout'], 'INSTALLED, AND REFUSED')
    && str_contains($refusedText['stdout'], 'certificate_invalid'),
    'and the human renderer says which of the two answers this is'
);

// ======================================================================
echo "\n== a manifest library that is not this repository's ==\n";
// ======================================================================
$plainLibrary = scratch('library');
file_put_contents("$plainLibrary/solo.json", json_encode([
    'name' => 'solo',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['solo_layout' => ['class' => 'authored']],
]));
// Valid JSON, and not a manifest. Canon::decode() returns whatever the
// document was, so this used to reach trust_tier(array $manifest) as an int
// and kill the survey with a TypeError — an inventory taken down by one of the
// files it exists to inventory.
file_put_contents("$plainLibrary/scalar.json", '123');
file_put_contents("$plainLibrary/listy.json", '[1,2,3]');
$plainResult = duo(['list', '--format=json'], $plainLibrary);
$plainReport = report($plainResult);
check(
    is_array($plainReport) && array_column($plainReport['adapters'] ?? [], 'name') === ['solo'],
    'a shipped manifest whose top level is not a JSON object is refused, not crashed on — the valid adapter '
    . 'beside it still reports (rows: '
    . implode(', ', array_column($plainReport['adapters'] ?? [], 'name')) . ')'
);
$shapeRefusals = array_values(array_filter(
    refusals_of($plainReport),
    static fn(array $r): bool => $r['code'] === 'malformed_manifest'
));
check(
    count($shapeRefusals) === 2
    && str_contains((string) $shapeRefusals[0]['message'], 'a manifest is a JSON object'),
    'each one is a malformed_manifest refusal saying what its top level actually is (rows: '
    . implode(' | ', array_column($shapeRefusals, 'message')) . ')'
);
check(
    str_contains((string) $shapeRefusals[0]['remediation'], 'JSON object')
    && !str_contains((string) $shapeRefusals[0]['remediation'], 'parses as JSON'),
    'and the remediation matches the actual failure — "make it an object", not "make it parse", which it already does'
);
check(
    ($shapeRefusals[0]['source'] ?? null) === 'shipped'
    && ($shapeRefusals[0]['scope'] ?? null) === 'source',
    'a SHIPPED-library refusal says so on the row — the source word is not a site/plugin-only field'
);
// The blocker attribution this replaced. `blockers()` used to sniff `paths`
// for a leading `adapters/` or a trailing `/site.duo.json`, so a refusal about
// the SHIPPED library — whose paths are absolute — fell through to `unknown`,
// and a `plugins/<dir>/duo-adapter.json` path would have too. It now reads the
// row's own `source`, which is why this fixture (a broken shipped library plus
// a repository pinning one of its manifests) can assert `shipped` at all.
$brokenLibraryRepo = site_repo(['listy'], []);
$brokenDoctor = report(duo(['doctor', '--repo=' . $brokenLibraryRepo, '--format=json'], $plainLibrary));
$pinBlocker = null;
foreach ($brokenDoctor['blockers'] ?? [] as $blocker) {
    if (($blocker['code'] ?? null) === 'pin_set_unloadable') {
        $pinBlocker = $blocker;
    }
}
check(
    is_array($pinBlocker) && ($pinBlocker['source'] ?? null) === 'shipped',
    'and a pin set that will not load is attributed to the source whose refusal stopped it, read off that '
    . 'refusal rather than guessed from its path (found: '
    . (string) ($pinBlocker['source'] ?? '(no pin_set_unloadable row)') . ')'
);
$soloRow = $plainReport['adapters'][0] ?? [];
check(
    array_key_exists('certification', $soloRow) && $soloRow['certification'] === null,
    'a library with no dispositions and no generated registry reports certification null rather than claiming a '
    . '`registry` that is not there (found: '
    . var_export($soloRow['certification'] ?? '(key absent)', true) . ')'
);
// R-2: the last DUO-3314 assertion reachable from collect mode that was still
// unguarded. declared_names() is reached ONLY when a site source exists, so an
// unguarded throw there made the catalog answer two different ways about one
// library — which is the single thing an inventory may never do.
$twoAnswers = scratch('two-answers');
file_put_contents("$twoAnswers/legal-file-name.json", json_encode([
    // The FILE name is a canonical slug; the DECLARED name is not.
    'name' => 'Illegal Declared Name',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'options' => ['solo_layout' => ['class' => 'authored']],
]));
$withoutRepo = duo(['list', '--format=json'], $twoAnswers);
// The site source must be non-empty: declared_names() is reached ONLY when
// one exists, which is exactly why the two runs used to disagree.
$withRepo = duo(
    ['list', '--repo=' . site_repo([], ['keeper' => site_adapter('keeper')]), '--format=json'],
    $twoAnswers
);
check(
    $withoutRepo['exit'] !== 2 && $withRepo['exit'] !== 2,
    'NEITHER run dies over a manifest whose declared name is not a canonical slug: the --repo run used to exit 2 '
    . 'from an unguarded assertion while the same library listed clean without --repo, so one library gave two '
    . "answers and one of them was a crash (without --repo: {$withoutRepo['exit']}, with --repo: {$withRepo['exit']})"
);
// DUO-3371 closed the last of that asymmetry from the other end. This library's
// declared name is not the file's name either, and the per-adapter grammar
// verdict — an isolated Policy::load(), which BOTH runs perform — now refuses
// exactly that. So the two runs no longer disagree about whether this library
// has a problem; they differ only in how much of it each one is in a position
// to have read.
check(
    $withoutRepo['exit'] === 1 && $withRepo['exit'] === 1,
    'both runs report the same library as broken (without --repo: ' . $withoutRepo['exit']
    . ", with --repo: {$withRepo['exit']})"
);
$withoutRepoParsed = report($withoutRepo);
check(
    str_contains(
        (string) ($withoutRepoParsed['adapters'][0]['grammar']['message'] ?? ''),
        'ambiguous identity'
    ) && refusals_of($withoutRepoParsed) === [],
    'the run with no site source reports it where it actually read it — the adapter\'s own grammar verdict — and '
    . 'still raises no source refusal, because the scan really did not open the declared names (grammar: '
    . ($withoutRepoParsed['adapters'][0]['grammar']['status'] ?? '(none)') . ')'
);
$withRepoParsed = report($withRepo);
check(
    is_array($withRepoParsed)
    && in_array('invalid_adapter_name', array_column(refusals_of($withRepoParsed), 'code'), true),
    'and the --repo run reports it as an invalid_adapter_name ROW rather than dying with a usage exit (rows: '
    . implode(', ', array_column(refusals_of($withRepoParsed), 'code')) . ')'
);
check(
    $withRepo['stderr'] === '',
    'writing nothing to stderr — the exit-2 IO path is for this command\'s own inputs, never for a manifest'
);

$plainText = duo(['inspect', 'solo'], $plainLibrary);
check(
    str_contains($plainText['stdout'], 'certification:     (none —'),
    'and the human renderer says so in words rather than printing an empty field'
);

// ======================================================================
echo "\n== the command's own fail-closed paths ==\n";
// ======================================================================
$noSiteFile = scratch('not-a-repo');
foreach ([
    [[], 'a subcommand is required'],
    [['bogus'], "unknown subcommand 'bogus'"],
    [['list', 'extra'], "takes no positional argument"],
    [['inspect'], 'inspect needs an adapter name'],
    [['list', '--nope'], "unsupported flag '--nope'"],
    [['list', '--repo=' . $overlayRepo, '--repo=' . $overlayRepo], "duplicate flag '--repo'"],
    [['list', '--repo='], '--repo needs the path'],
    [['list', '--repo=/definitely/not/here'], 'is not a directory'],
    [['list', '--repo=' . $noSiteFile], 'has no site.duo.json'],
] as [$args, $needle]) {
    $result = duo($args);
    check(
        $result['exit'] === 2 && str_contains($result['stderr'], $needle),
        'usage refusal (exit 2) for `duo adapter ' . implode(' ', $args) . "`: $needle"
    );
    check($result['stdout'] === '', 'and it writes no report to stdout, so a consumer cannot half-parse it');
}

// ======================================================================
echo "\n== a refused file's own NAME cannot rewrite this report ==\n";
// ======================================================================
// Refusal `paths` are DATA, kept exactly as the file is spelled so the row
// still names something an operator can go delete — and this renderer imploded
// them straight into the terminal. A site adapter file whose name carries ANSI
// escapes reaches that list through the ordinary identity refusal, so the
// bytes are third-party here too even in the operator's own directory: the
// engine never authored that filename.
$escapeName = "evil\x1b[2J\x1b[1;1Hok: everything is certified";
$escapeRepo = site_repo(['keeper'], ['keeper' => site_adapter('keeper')]);
file_put_contents("$escapeRepo/adapters/$escapeName.json", "{}\n");
if (in_array("$escapeName.json", scandir("$escapeRepo/adapters") ?: [], true)) {
    $escapeText = duo(['doctor', '--repo=' . $escapeRepo]);
    $escapeJson = report(duo(['doctor', '--repo=' . $escapeRepo, '--format=json']));
    check(
        strcspn($escapeText['stdout'], "\x1b\x00\x07") === strlen($escapeText['stdout'])
        && str_contains($escapeText['stdout'], 'hex '),
        'the rendered report carries no escape byte, and keeps the hex receipt that makes the refusal '
        . 'actionable'
    );
    check(
        in_array(
            AdapterSources::SITE_DIR . "/$escapeName.json",
            (array) (refusals_of($escapeJson)[0]['paths'] ?? []),
            true
        ),
        'while the document still spells the path exactly as the file is spelled — the rendered line and the '
        . 'machine record must not disagree about which file to delete'
    );
} else {
    check(false, "fixture '$escapeName.json' could not be created as its own entry on this filesystem");
}
// The not-installed renderer takes the same values through the same rule, but
// a WordPress-free host process never populates that block: `not_installed`
// rows come from the plugin source, which needs WP_PLUGIN_DIR. So its
// protection is asserted against the SOURCE — the wiring precedent
// regress_provider_contract.php:1082-1090 sets for a branch that cannot be
// reached from here — while `wp duo adapter-survey` drives it for real in
// regress_plugin_adapter_source.php.
$catalogSource = (string) file_get_contents(dirname(__DIR__, 2) . '/cli/src/Adapter/AdapterCatalog.php');
preg_match('/private static function render_not_installed.*?\n    \}/s', $catalogSource, $notInstalledRenderer);
check(
    isset($notInstalledRenderer[0])
    && substr_count($notInstalledRenderer[0], 'AdapterSources::render_untrusted(') === 3
    && !preg_match('/\$row\[.name.\] \?\? .\(name unreadable\).\)\n/', $notInstalledRenderer[0]),
    'and the not-installed renderer routes its three untrusted fields (name, path, winner path) through the '
    . 'same shared renderer, which is checkable here even though only the target-side survey can populate it'
);

// ======================================================================
echo "\n== the human renderer says the same things the document does ==\n";
// ======================================================================
$text = duo(['list', '--repo=' . $refusalCases['shadows_shipped']]);
check(
    str_contains($text['stdout'], '[shadows_shipped]')
    && str_contains($text['stdout'], 'remediation: ')
    && str_contains($text['stdout'], 'shadows the shipped adapter'),
    'the text renderer prints the refusal code, the engine message, and the remediation'
);
check(
    str_contains($text['stdout'], 'tier basis: ') && str_contains($text['stdout'], 'compatibility_shim'),
    'and the trust tier with the declaration behind it'
);
check(
    str_contains($text['stdout'], 'deferred — NOT checked here'),
    'and ends with the deferred list, exactly as the document carries it'
);
$inspectText = duo(['inspect', 'acf']);
check(
    str_contains($inspectText['stdout'], 'ADAPTER acf')
    && str_contains($inspectText['stdout'], 'tier_basis:')
    && str_contains($inspectText['stdout'], 'verification (the facts that exist, not a scale)'),
    'inspect renders the merged row in text mode too, and labels the verification block for what it is'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
