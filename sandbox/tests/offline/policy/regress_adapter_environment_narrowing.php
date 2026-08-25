<?php
/**
 * WP-4.6 — per-adapter environment NARROWING, never widening (spec § v3.5).
 *
 * WHAT THIS SUITE HOLDS
 * ---------------------
 * `ManifestDispositions::claim_from_disposition()` used to copy
 * `environment_assumptions` verbatim out of the single global
 * `manifests/capabilities/platform.json` into every claim, so all 16 shipped
 * claims were byte-identical and no adapter could state which boundary cells it
 * actually ran on (`regress_spec_v3_dry_run.php`, rule V3-AXIS, measures that
 * starting point). A `spec_version: 3` adapter may now declare
 * `"environment": {"php": ["8.3"], …}` — a per-axis list of exercised cells,
 * the same subset shape `unsupported[]` already uses for surfaces — and the
 * claim states that subset instead of the whole matrix.
 *
 * The four properties, each asserted below rather than argued:
 *
 *   1. an adapter declaring nothing binds the whole current boundary BYTE FOR
 *      BYTE. All 16 shipped claims are re-projected here and compared against
 *      the pre-WP-4.6 formula, recomputed independently from platform.json — so
 *      this suite fails if the shipped claims move by one byte;
 *   2. a NARROWER declaration is honoured and reported, through the same funnel
 *      `wp duo capabilities` reads (`AdapterRegistry::shipped_claim()`);
 *   3. a WIDER declaration refuses BY NAME — the axis and the offending cell,
 *      on every one of the four narrowable axes, plus an axis no claim states;
 *   4. narrowing relaxes NO load-time assertion.
 *      `PlatformCompatibility::assert_supported()` is driven across all five
 *      axes — site_mode, php, database, filesystem, wordpress — and #560's
 *      process axis, with a narrowing adapter loaded, and every refusal fires
 *      exactly as it does today. That is asserted structurally as well: the
 *      gate reads neither a manifest nor a claim, so a declaration cannot reach
 *      it.
 *
 * WHY THE CHANNEL IS INERT AT v2, AND WHY THAT IS NOT SILENCE
 * ----------------------------------------------------------
 * `DUO_SPEC_VERSION` is 2 and stays 2 (the flip is WP-4.12), and
 * `AdapterContractGrammar` accepts exactly that one version, so no v3 manifest
 * loads through `Policy::load()` yet. A v2 manifest that declares the key is
 * therefore INERT — byte-identical to one that declares nothing — exactly as
 * `engine_features` is inert under § v3.2. Refusing a v3-only section inside a
 * v2 manifest BY NAME needs the acceptance window (§ v3.1, WP-4.2); doing it
 * here would mean refusing the manifest wholesale, which is the failure the
 * window exists to prevent. Both halves are pinned below.
 */
declare(strict_types=1);

// From offline/policy/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);

require_once __DIR__ . '/../../lib/agent_version.php';
duo_test_define_agent_versions();

// The platform gate probes WordPress and the database before it compares
// anything, so the injected-facts path still needs these two to exist.
function get_bloginfo(string $show): string {
    return $show === 'version' ? '7.1' : '';
}
function is_multisite(): bool {
    return false;
}

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/CommandRefusal.php';
require_once $root . '/agent/src/Policy/ManifestDispositions.php';
require_once $root . '/agent/src/Policy/PlatformCompatibility.php';
require_once $root . '/agent/src/Adapter/AdapterRegistry.php';
require_once $root . '/agent/src/Adapter/AdapterCertification.php';

use Duo\AdapterCertification;
use Duo\AdapterRegistry;
use Duo\Canon;
use Duo\CommandRefusalException;
use Duo\ManifestDispositions;
use Duo\PlatformCompatibility;

/**
 * The declared top-level manifest key, spelled out here rather than read back
 * out of `ManifestDispositions`.
 *
 * A key name IS the wire: an adapter author types these bytes, and an engine
 * that quietly renamed the channel would still agree with its own constant. So
 * this suite pins the literal and drives every assertion below through a
 * manifest that declares it — a rename fails here, loudly, instead of passing
 * against a moved definition.
 */
const DUO_ENVIRONMENT_CHANNEL = 'environment';

/** One indented report row (indented so the offline diagnostics guard cannot read it as a PHP notice). */
$report = static function (string $line): void {
    echo '  ' . $line . "\n";
};

$manifestDir = $root . '/manifests';
$platform = ManifestDispositions::platform_boundary($manifestDir);
$registry = ManifestDispositions::load($manifestDir);
duo_check($registry !== null, 'the shipped disposition registry loads');
$dispositions = $registry?->data()['manifests'] ?? [];

/** Every shipped manifest, by name. @var array<string,array<string,mixed>> $shipped */
$shipped = [];
foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
    if (basename($file) === 'dispositions.json') {
        continue;
    }
    $decoded = Canon::decode(Canon::read_file($file));
    $shipped[(string) $decoded['name']] = $decoded;
}
ksort($shipped, SORT_STRING);

/**
 * The claim, projected through the ONE funnel every shipped claim passes
 * through: `AdapterRegistry::shipped_claim()` runs `assert_entry()` and then
 * `claim_from_disposition()`, and `capability_claim()`/`report()` — so
 * `wp duo capabilities` and the adapter catalog — both land on it. Private
 * because it is an internal projection; reached by Reflection so this suite
 * measures the product path rather than a re-implementation of it.
 */
$claim = static function (array $manifest, array $disposition) use ($platform): array {
    static $method = null;
    if ($method === null) {
        $method = new ReflectionMethod(AdapterRegistry::class, 'shipped_claim');
    }
    return (array) $method->invoke(null, $manifest, $disposition, $platform);
};

/** The refusal message of a projection, or null when it produced a claim. */
$refusal = static function (callable $operation): ?string {
    try {
        $operation();
        return null;
    } catch (\Throwable $failure) {
        return $failure->getMessage();
    }
};

echo "\nPART 1 — an adapter declaring nothing binds the whole boundary, byte for byte\n";

// The pre-WP-4.6 formula, recomputed here from platform.json rather than read
// back out of the engine: a copy of the new code would agree with itself on the
// day it is typed. This is what makes clause 1 a regression pin instead of a
// tautology — if narrowing ever leaked into an undeclared claim, these bytes
// move and this assertion is the first thing that fails.
$wholeBoundary = Canon::encode([
    'site_mode' => $platform['site_mode'] ?? null,
    'php' => $platform['compatibility']['php'] ?? new \stdClass(),
    'database' => $platform['compatibility']['database'] ?? new \stdClass(),
    'wordpress' => $platform['compatibility']['wordpress'] ?? new \stdClass(),
]);

$declaringShipped = [];
$movedClaims = [];
foreach ($shipped as $name => $manifest) {
    if (array_key_exists(DUO_ENVIRONMENT_CHANNEL, $manifest)) {
        $declaringShipped[] = $name;
    }
    $projected = $claim($manifest, (array) $dispositions[$name]);
    if (Canon::encode($projected['environment_assumptions']) !== $wholeBoundary) {
        $movedClaims[] = $name;
    }
}
duo_check_same(16, count($shipped), 'the shipped library is the 16 adapters this claim is measured over');
duo_check_same([], $declaringShipped, 'no shipped adapter declares the narrowing channel, so WP-4.6 moves no shipped manifest byte and no adapter digest');
duo_check_same(
    [],
    $movedClaims,
    'all 16 shipped claims still carry the WHOLE boundary in `environment_assumptions`, byte-identical to the pre-WP-4.6 projection'
);
$report('shipped claims re-projected: ' . count($shipped) . '; environment_assumptions bytes: ' . strlen($wholeBoundary));

echo "\nPART 2 — a NARROWER declaration is honoured and reported\n";

// A real shipped pair, so the disposition half of the projection is reviewed
// bytes rather than a fixture: only the manifest's spec stamp and the new
// channel move. `classic-editor` declares one field section and no tables, so
// nothing else in validate_entry() is in play.
$subject = $shipped['classic-editor'];
$subjectDisposition = (array) $dispositions['classic-editor'];
$phpSeries = array_map('strval', array_keys($platform['compatibility']['php']['verified']));
$coreSeries = array_map('strval', array_keys($platform['compatibility']['wordpress']['verified']));
$engines = array_map('strval', array_keys($platform['compatibility']['database']['engines']));
duo_check(
    count($phpSeries) >= 2 && count($coreSeries) >= 2 && count($engines) >= 2,
    'the boundary carries more than one cell on the php, wordpress and database axes, so a subset is a real narrowing ('
        . implode('/', $phpSeries) . ' | ' . implode('/', $coreSeries) . ' | ' . implode('/', $engines) . ')'
);

$narrowing = $subject;
$narrowing['spec_version'] = 3;
$narrowing[DUO_ENVIRONMENT_CHANNEL] = [
    'database' => [$engines[0]],
    'php' => [$phpSeries[0]],
    'site_mode' => [(string) $platform['site_mode']],
    'wordpress' => [$coreSeries[0]],
];
$narrowed = $claim($narrowing, $subjectDisposition)['environment_assumptions'];

duo_check_same(
    [$phpSeries[0] => $platform['compatibility']['php']['verified'][$phpSeries[0]]],
    $narrowed['php']['verified'],
    'the php axis reports exactly the exercised series the adapter declared'
);
duo_check_same(
    [$coreSeries[0] => $platform['compatibility']['wordpress']['verified'][$coreSeries[0]]],
    $narrowed['wordpress']['verified'],
    'the wordpress axis reports exactly the exercised core series the adapter declared'
);
duo_check_same(
    [$engines[0] => $platform['compatibility']['database']['engines'][$engines[0]]],
    $narrowed['database']['engines'],
    'the database axis reports exactly the engine the adapter declared'
);
duo_check_same(
    $platform['site_mode'],
    $narrowed['site_mode'],
    'site_mode is a one-value axis: a subset of it can only ever restate it'
);
// `last_verified` is the GREATEST exercised core and must be a member of
// `verified` (PlatformCompatibility::valid_wordpress_axis()). A narrowed map
// behind the boundary's own scalar would publish a claim whose newest exercised
// core is not in its own exercised set.
duo_check_same(
    $platform['compatibility']['wordpress']['verified'][$coreSeries[0]],
    $narrowed['wordpress']['last_verified'],
    'and the narrowed wordpress axis recomputes last_verified into its own exercised set'
);
duo_check(
    Canon::encode($narrowed) !== $wholeBoundary,
    'the narrowed claim is not the whole boundary — the projection actually moved'
);
// Reported, not merely computed: the narrowing survives the canonical encode a
// capability report and every content pin are taken over.
$roundTrip = Canon::decode(Canon::encode($narrowed));
duo_check_same(
    [$phpSeries[0]],
    array_map('strval', array_keys($roundTrip['php']['verified'])),
    'the narrowed claim round-trips through Canon unchanged, so a report and a pin see the same subset'
);
$report('narrowed claim: php=' . implode(',', array_keys($narrowed['php']['verified']))
    . ' wordpress=' . implode(',', array_keys($narrowed['wordpress']['verified']))
    . ' database=' . implode(',', array_keys($narrowed['database']['engines'])));

// The un-narrowed axes of a PARTIAL declaration keep the whole boundary: an
// adapter that only says which PHP it ran on has said nothing about the others.
$partial = $subject;
$partial['spec_version'] = 3;
$partial[DUO_ENVIRONMENT_CHANNEL] = ['php' => [$phpSeries[0]]];
$partialClaim = $claim($partial, $subjectDisposition)['environment_assumptions'];
duo_check_same(
    Canon::encode($platform['compatibility']['wordpress']),
    Canon::encode($partialClaim['wordpress']),
    'an axis the declaration omits keeps the whole boundary, so narrowing is opt-in per axis'
);
duo_check_same(
    [$phpSeries[0]],
    array_map('strval', array_keys($partialClaim['php']['verified'])),
    '...while the declared axis narrows'
);

echo "\nPART 3 — a WIDER declaration refuses BY NAME\n";

// One case per narrowable axis. Each names a cell the reviewed boundary does
// not carry, and each refusal must name the axis AND the cell: "environment
// declaration invalid" would send an author to re-read four axes to find one
// transposed digit.
$widerCases = [
    'php' => ['php' => ['9.9']],
    'wordpress' => ['wordpress' => ['99.9']],
    'database' => ['database' => ['PostgreSQL']],
    'site_mode' => ['site_mode' => ['multisite']],
];
foreach ($widerCases as $axis => $declaration) {
    $wider = $subject;
    $wider['spec_version'] = 3;
    $wider[DUO_ENVIRONMENT_CHANNEL] = $declaration;
    $cell = (string) $declaration[$axis][0];
    $message = $refusal(static fn() => $claim($wider, $subjectDisposition));
    duo_check(
        is_string($message)
            && str_contains($message, "manifest 'classic-editor'")
            && str_contains($message, "environment axis '$axis'")
            && str_contains($message, $cell)
            && str_contains($message, 'never widen it'),
        "a wider $axis claim ('$cell') refuses naming the manifest, the axis and the cell"
    );
    duo_check_detail("wider $axis: " . (string) $message);
}

// `multisite` is the sharpest of the four: the reviewed boundary is
// single-site, PlatformCompatibility refuses a network before policy load, and
// this channel cannot be used to claim otherwise.
$multisite = $subject;
$multisite['spec_version'] = 3;
$multisite[DUO_ENVIRONMENT_CHANNEL] = ['site_mode' => ['single-site', 'multisite']];
duo_check(
    str_contains((string) $refusal(static fn() => $claim($multisite, $subjectDisposition)), 'multisite'),
    'a declaration that ADDS multisite beside the reviewed single-site value is still a widening and still refuses'
);

// An axis the claim does not state. `filesystem` and `process` are load-time
// profiles, not claim members, so narrowing one would narrow a sentence the
// claim never makes — refused rather than accepted as a no-op, because an
// unrecognised declaration that means nothing is indistinguishable from a
// deliberate one.
foreach (['filesystem', 'process', 'compatibility'] as $absentAxis) {
    $invented = $subject;
    $invented['spec_version'] = 3;
    $invented[DUO_ENVIRONMENT_CHANNEL] = [$absentAxis => ['whatever']];
    $message = $refusal(static fn() => $claim($invented, $subjectDisposition));
    duo_check(
        is_string($message)
            && str_contains($message, "environment axis '$absentAxis'")
            && str_contains($message, 'narrowable axes are database, php, site_mode, wordpress'),
        "an axis no claim states ('$absentAxis') refuses by name and lists the four that are narrowable"
    );
}
duo_check_detail('absent axis: ' . (string) $refusal(static function () use ($subject, $subjectDisposition, $claim) {
    $invented = $subject;
    $invented['spec_version'] = 3;
    $invented[DUO_ENVIRONMENT_CHANNEL] = ['filesystem' => ['local-posix-atomic-rename-flock-fsync/v1']];
    return $claim($invented, $subjectDisposition);
}));

// Shape refusals: the channel is a non-empty object of axis => non-empty list
// of distinct cell names, and every other shape is named rather than coerced.
$malformed = [
    'a list instead of an object' => [['php']],
    'an empty object' => [],
    'a string cell set' => ['php' => '8.3'],
    'an empty cell list' => ['php' => []],
    'a repeated cell' => ['php' => ['8.3', '8.3']],
    'a non-string cell' => ['php' => [83]],
];
foreach ($malformed as $label => $declaration) {
    $broken = $subject;
    $broken['spec_version'] = 3;
    $broken[DUO_ENVIRONMENT_CHANNEL] = $declaration;
    $message = $refusal(static fn() => $claim($broken, $subjectDisposition));
    duo_check(
        is_string($message) && str_contains($message, "manifest 'classic-editor'") && str_contains($message, 'environment'),
        "a malformed declaration ($label) refuses by name rather than being coerced"
    );
}

echo "\nPART 4 — the channel is INERT at spec_version 2, byte for byte\n";

$inert = $subject;
$inert[DUO_ENVIRONMENT_CHANNEL] = [
    'php' => [$phpSeries[0]],
    'site_mode' => ['multisite'],
];
duo_check_same(2, $subject['spec_version'], 'the shipped subject is stamped spec_version 2, like every shipped manifest');
duo_check_same(
    $wholeBoundary,
    Canon::encode($claim($inert, $subjectDisposition)['environment_assumptions']),
    'a v2 manifest declaring the channel projects the WHOLE boundary — identical to declaring nothing, including the widening it names'
);
// WP-4.12 flipped this. The assertion was "DUO_SPEC_VERSION is still 2",
// which pinned WP-4.6's own claim: it landed the ENFORCEMENT ahead of the
// bump. That claim was about a moment, and the moment passed. What survives
// the flip is the property the moment existed to protect, and it is the one
// worth asserting from here on: the engine is now AT the enforcing version
// and the shipped subject is still stamped one below it, so PART 4 above is
// still measuring the inert arm on a manifest inside the window — not an arm
// that stopped existing.
duo_check_same(
    3,
    DUO_SPEC_VERSION,
    'DUO_SPEC_VERSION is 3: the flip landed (WP-4.12), and the narrowing channel WP-4.6 shipped ahead of it is now the engine\'s own version'
);
duo_check(
    $subject['spec_version'] === DUO_SPEC_VERSION - 1,
    'and the shipped subject sits at N-1 inside the window, which is what keeps PART 4\'s inert arm reachable after the flip rather than dead code'
);

echo "\nPART 5 — narrowing relaxes NO load-time assertion\n";

// Structural first: the gate cannot be reached by a declaration it never reads.
$gateSource = (string) file_get_contents($root . '/agent/src/Policy/PlatformCompatibility.php');
duo_check(
    !str_contains($gateSource, 'ManifestDispositions')
        && !str_contains($gateSource, "'" . DUO_ENVIRONMENT_CHANNEL . "'")
        && !str_contains($gateSource, 'environment_assumptions'),
    'PlatformCompatibility names neither the disposition projection nor the narrowing channel — a claim cannot reach the runtime gate'
);
duo_check(
    !str_contains($gateSource, '$manifest'),
    '...and it takes no manifest at all: its inputs are the boundary and the observed facts'
);

$facts = static fn(
    string $php = '8.3.33',
    string $engine = 'MariaDB',
    string $database = '11.8.8',
    string $wordpress = '7.1',
    string $siteMode = 'single-site',
    string $osFamily = 'Linux',
    string $processOsFamily = 'Linux',
    string $separator = '/',
    array $filesystemFunctions = [
        'chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true, 'rename' => true,
    ],
    array $processFunctions = [
        'passthru' => true, 'posix_kill' => true, 'posix_setsid' => true, 'proc_close' => true,
        'proc_get_status' => true, 'proc_open' => true, 'proc_terminate' => true,
    ],
    string $shellPath = '/bin/sh',
    bool $shellExecutable = true
): array => [
    'php' => $php,
    'database' => ['engine' => $engine, 'version' => $database],
    'filesystem' => [
        'directory_separator' => $separator,
        'functions' => $filesystemFunctions,
        'os_family' => $osFamily,
    ],
    'process' => [
        'functions' => $processFunctions,
        'os_family' => $processOsFamily,
        'shell' => ['executable' => $shellExecutable, 'path' => $shellPath],
    ],
    'wordpress' => $wordpress,
    'site_mode' => $siteMode,
];

/** @return list<string> the diagnostic codes one facts set produces, sorted. */
$codes = static function (array $observed) use ($platform): array {
    try {
        PlatformCompatibility::assert_supported($platform, $observed);
        return [];
    } catch (CommandRefusalException $failure) {
        $out = array_map(static fn(array $row): string => (string) $row['code'], $failure->diagnostics);
        sort($out, SORT_STRING);
        return $out;
    }
};

// The adapter that narrowed hardest — php to one series, database to one
// engine, wordpress to one core — is projected FIRST, so every gate below runs
// with that claim already in hand. Narrowing scopes the claim, not the runtime:
// the runtime answers must be identical to a tree where nobody declared
// anything, and each case is a cell the narrowed claim does not name.
$narrowedTwice = $claim($narrowing, $subjectDisposition);
duo_check_same(
    [$phpSeries[0]],
    array_map('strval', array_keys($narrowedTwice['environment_assumptions']['php']['verified'])),
    'the narrowed claim is in hand before the load-time gate runs'
);

duo_check_same([], $codes($facts()), 'the exercised facts are still accepted with a narrowing adapter in the library');
foreach ($phpSeries as $series) {
    $patch = (string) $platform['compatibility']['php']['verified'][$series];
    duo_check_same(
        [],
        $codes($facts(php: $patch)),
        "PHP $patch is still accepted even though the narrowed claim names only " . $phpSeries[0]
            . ' — narrowing scopes the CLAIM, not the runtime'
    );
}
foreach ($engines as $engine) {
    $range = $platform['compatibility']['database']['engines'][$engine];
    duo_check_same(
        [],
        $codes($facts(engine: $engine, database: (string) $range['min'])),
        "the $engine engine is still accepted even though the narrowed claim names only " . $engines[0]
    );
}

// Every load-time refusal, one per axis, including #560's process axis. These
// are the assertions that would have to move if narrowing had leaked into the
// runtime; they are quoted from the engine's own codes.
foreach ([
    'site_mode' => ['facts' => $facts(siteMode: 'multisite'), 'code' => 'platform_site_mode_unsupported'],
    'php' => ['facts' => $facts(php: '8.2.99'), 'code' => 'platform_php_version_unsupported'],
    'php series hole' => ['facts' => $facts(php: '8.9.0'), 'code' => 'platform_php_version_unsupported'],
    'database engine' => ['facts' => $facts(engine: 'MySQL', database: '5.7.0'), 'code' => 'platform_database_version_unsupported'],
    'wordpress' => ['facts' => $facts(wordpress: '6.8.1'), 'code' => 'platform_wordpress_version_unsupported'],
    'filesystem os' => ['facts' => $facts(osFamily: 'Windows'), 'code' => 'platform_filesystem_os_unsupported'],
    'filesystem separator' => ['facts' => $facts(separator: '\\'), 'code' => 'platform_filesystem_separator_unsupported'],
    'filesystem function' => [
        'facts' => $facts(filesystemFunctions: [
            'chmod' => true, 'flock' => false, 'fsync' => true, 'lstat' => true, 'rename' => true,
        ]),
        'code' => 'platform_filesystem_function_unsupported',
    ],
    'process os' => ['facts' => $facts(processOsFamily: 'Windows'), 'code' => 'platform_process_os_unsupported'],
    'process function' => [
        'facts' => $facts(processFunctions: [
            'passthru' => true, 'posix_kill' => true, 'posix_setsid' => false, 'proc_close' => true,
            'proc_get_status' => true, 'proc_open' => true, 'proc_terminate' => true,
        ]),
        'code' => 'platform_process_function_unsupported',
    ],
    'process shell path' => ['facts' => $facts(shellPath: '/bin/dash'), 'code' => 'platform_process_shell_unsupported'],
    'process shell missing' => ['facts' => $facts(shellExecutable: false), 'code' => 'platform_process_shell_unavailable'],
] as $label => $case) {
    duo_check(
        in_array($case['code'], $codes($case['facts']), true),
        "the $label load-time refusal still fires ({$case['code']}) with a narrowing adapter projected"
    );
}

// The aggregate: every axis at once still answers with one
// `platform_unsupported` envelope carrying every diagnostic, unchanged. The
// engine is a CLAIMED one on an unclaimed version, because an engine name the
// map does not know is answered earlier by the facts-shape probe
// (`platform_probe_unavailable`) and would replace the six rows with one.
$everything = $codes($facts(
    php: '8.2.0',
    engine: 'MySQL',
    database: '5.7.0',
    wordpress: '6.0',
    siteMode: 'multisite',
    osFamily: 'Windows',
    processOsFamily: 'Windows'
));
duo_check_same(
    [
        'platform_database_version_unsupported',
        'platform_filesystem_os_unsupported',
        'platform_php_version_unsupported',
        'platform_process_os_unsupported',
        'platform_site_mode_unsupported',
        'platform_wordpress_version_unsupported',
    ],
    $everything,
    'and a target outside every axis still collects all six diagnostics in one refusal'
);

echo "\nPART 6 — the signer classifies the channel, so a narrowing adapter stays certifiable\n";

// The gap this rides with. `regress_spec_v3_dry_run.php` measured it under rule
// V3-KEYS: a top-level key in no arm of the signer's partition makes the whole
// adapter unsignable by name (the `theme_version_range` case). A narrowing
// channel nobody can certify would be a feature only uncertified adapters could
// use, so the key joins the non-surface arm in the same change.
$partition = AdapterCertification::topLevelKeyPartition();
duo_check(
    in_array(DUO_ENVIRONMENT_CHANNEL, $partition['non_surface_keys'], true)
        && !in_array(DUO_ENVIRONMENT_CHANNEL, $partition['entity_sections'], true)
        && !in_array(DUO_ENVIRONMENT_CHANNEL, $partition['field_sections'], true),
    'the narrowing channel is a NON-SURFACE key: it covers no branchable state, so no certificate surface is derived from it'
);
$ratify = new ReflectionMethod(AdapterCertification::class, 'siteRatification');
$ratification = (array) $ratify->invoke(null, 'classic-editor', $narrowing, 'WP-4.6 narrowing suite');
$ratified = (array) $ratification['manifests']['classic-editor']['capabilities'];
duo_check(
    !in_array(DUO_ENVIRONMENT_CHANNEL, (array) $ratified['entity_sections'], true)
        && !in_array(DUO_ENVIRONMENT_CHANNEL, (array) $ratified['field_sections'], true),
    'a narrowing manifest ratifies without the channel appearing among its covered surfaces'
);
// The refusal this rides ahead of, proven live rather than assumed: the SAME
// manifest carrying a key in no arm is still unsignable by name.
$unclassified = $narrowing;
$unclassified['totally_made_up_section'] = ['acme_thing' => ['class' => 'authored']];
duo_check(
    str_contains(
        (string) $refusal(static fn() => $ratify->invoke(null, 'classic-editor', $unclassified, 'WP-4.6 narrowing suite')),
        'which this signer cannot classify'
    ),
    '...and the classify-or-throw loop it passed through is still live for a key that IS in no arm'
);
$report('signer partition: ' . count($partition['entity_sections']) . ' entity + '
    . count($partition['field_sections']) . ' field + ' . count($partition['non_surface_keys']) . ' non-surface');

duo_check_summary('adapter environment narrowing');
