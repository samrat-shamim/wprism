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

require $repo . '/agent/src/Canon.php';
require $repo . '/agent/src/OptionState.php';
require $repo . '/agent/src/ManifestDispositions.php';
require $repo . '/agent/src/CapabilityRegistry.php';
require $repo . '/agent/src/Policy.php';

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
 * @param list<string> $args
 * @return array{exit:int, stdout:string, stderr:string}
 */
function duo(array $args): array {
    global $repo;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/cli/duo') . ' adapter';
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
    is_array($listReport) && ($listReport['format'] ?? null) === 'duo-adapter-catalog/v1'
    && ($listReport['command'] ?? null) === 'list'
    && ($listReport['spec_version'] ?? null) === DUO_SPEC_VERSION,
    'the JSON report parses and carries the duo-adapter-catalog/v1 envelope'
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
check(
    is_array($woo) && $woo['trust_tier'] === 'compatibility_shim'
    && str_contains((string) $woo['tier_basis'], 'regen_dependency.regenerator'),
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
    is_array($woo) && ($woo['executable_surfaces']['manifest_providers'] ?? []) === ['woocommerce-cache'],
    'and the same provider is listed as manifest-shipped executable code'
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
check(
    is_array($adapter) && str_contains((string) $adapter['tier_basis'], 'woocommerce-product-lookups'),
    'MERGED FROM THE SURVEY: the tier basis rides on the inspected row'
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
    ($adapter['verification']['tests'] ?? []) !== [] && $citedVerdicts !== ['absent'],
    'and each named test citation resolved against the bundle\'s OWN verdict rather than asserted (verdicts: '
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
    // malformed_manifest is a decode failure, which discover() lets propagate
    // from Canon rather than throwing itself; every other code below is a
    // refusal discover() owns, so the byte-comparison covers them.
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
check(
    array_filter($blockedReport['blockers'] ?? [], static fn(array $r): bool => ($r['name'] ?? '') === 'core') === [],
    'while the certified shipped adapter beside it contributes no blocker'
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
    ($brokenInspectReport['adapter']['grammar']['status'] ?? null) === 'error'
    && str_contains((string) ($brokenInspectReport['adapter']['grammar']['message'] ?? ''), 'shadows the shipped adapter'),
    'and says exactly why it cannot load — the engine\'s own words about the source it lives in'
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
check(
    str_contains($deferredSurfaces, 'discovered by nothing'),
    'and that the two sources it surveys are the only two that exist, so an empty catalog is not a claim about the world'
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
