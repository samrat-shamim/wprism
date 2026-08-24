<?php
declare(strict_types=1);

/**
 * Offline platform-boundary regression.
 *
 * Exercises the agent-owned gate with every inclusive/exclusive version edge,
 * the WordPress AND PHP range-plus-exercised-series semantics, the per-engine
 * database map, engine/topology mismatches, checked live-probe parsing, safe
 * local-POSIX filesystem/process capability profiles, aggregate diagnostics, and
 * Policy ordering. Before this gate, a direct
 * `wp duo` command bypassed the host doctor and reached repository reads or
 * mutation on an entirely unexercised runtime.
 *
 * WordPress and PHP are each two independent conditions, and this suite proves
 * both separately for both axes: inside [min, max) AND the observed
 * MAJOR.MINOR present in that axis's `verified`. A boundary with a deliberate
 * hole (below, once per axis) is what stops the series half passing merely by
 * agreeing with the range half — and for PHP that holed fixture is the ONLY
 * proof available today, because the shipped claim's range currently contains
 * no unexercised series (see the php axis note in
 * manifests/capabilities/platform.json).
 *
 * The database axis is a map from engine to that engine's own range, so it has
 * its own two conditions: the observed engine must be a key of `engines`, and
 * the observed version must be inside THAT engine's range — never another
 * engine's. The refusal labels are asserted verbatim because they are the
 * operator's whole answer: an engine refusal names every claimed engine, and a
 * version refusal is engine-qualified because the range is now a function of
 * the engine.
 */

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', '0.5.0');
}
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

$GLOBALS['platform_wordpress_version'] = '7.1';
$GLOBALS['platform_multisite'] = false;
function get_bloginfo(string $show): string {
    return $show === 'version' ? (string) $GLOBALS['platform_wordpress_version'] : '';
}
function is_multisite(): bool {
    return (bool) $GLOBALS['platform_multisite'];
}

final class PlatformCompatibilityWpdb {
    public string $last_error = '';
    public string|false|null $server = '11.8.8-MariaDB-1:11.8.8+maria~ubu2404';
    public ?Throwable $failure = null;
    /** @var list<string> */
    public array $queries = [];

    public function get_var(string $query): string|false|null {
        $this->queries[] = $query;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->server;
    }
}

$GLOBALS['wpdb'] = new PlatformCompatibilityWpdb();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/CommandRefusal.php';
require_once $root . '/agent/src/Policy/ManifestDispositions.php';
require_once $root . '/agent/src/Policy/PlatformCompatibility.php';

use Duo\CommandRefusalException;
use Duo\PlatformCompatibility;

$platformDocument = json_decode(
    (string) file_get_contents($root . '/manifests/capabilities/platform.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$platform = $platformDocument['platform'];
$facts = static fn(
    string $php = '8.3.33',
    string $engine = 'MariaDB',
    string $database = '11.8.8',
    string $wordpress = '7.1',
    string $siteMode = 'single-site',
    string $osFamily = 'Linux',
    string $processOsFamily = 'Linux',
    string $directorySeparator = '/',
    array $filesystemFunctions = [
        'chmod' => true,
        'flock' => true,
        'fsync' => true,
        'lstat' => true,
        'rename' => true,
    ],
    array $processFunctions = [
        'pcntl_exec' => true,
        'posix_kill' => true,
        'posix_setsid' => true,
        'proc_close' => true,
        'proc_open' => true,
    ]
): array => [
    'php' => $php,
    'database' => ['engine' => $engine, 'version' => $database],
    'filesystem' => [
        'directory_separator' => $directorySeparator,
        'functions' => $filesystemFunctions,
        'os_family' => $osFamily,
    ],
    'process' => [
        'functions' => $processFunctions,
        'os_family' => $processOsFamily,
    ],
    'wordpress' => $wordpress,
    'site_mode' => $siteMode,
];

/** @return ?CommandRefusalException */
$refusal = static function (callable $operation): ?CommandRefusalException {
    try {
        $operation();
        return null;
    } catch (CommandRefusalException $failure) {
        return $failure;
    }
};

PlatformCompatibility::assert_supported($platform, $facts());
duo_check(true, 'the shipped PHP/MariaDB/Linux-filesystem/WordPress/single-site boundary accepts its exercised facts');
duo_check(
    $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $facts(osFamily: 'Darwin'))) === null,
    'the measured Darwin local-POSIX process profile is accepted beside the Linux pair profile'
);

// Every value of the shipped `verified` map is, by definition, a core a live
// matrix ran end to end; the gate must accept each one. Read from the claim
// rather than restated here so adding a series to platform.json cannot leave
// this suite asserting the old set.
$verifiedWordPress = $platform['compatibility']['wordpress']['verified'];
duo_check(count($verifiedWordPress) >= 2, 'the shipped claim names more than one exercised core series');
foreach ($verifiedWordPress as $series => $patch) {
    $failure = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $facts(wordpress: $patch)));
    duo_check($failure === null, "the exercised $series proof core $patch is accepted by the agent gate");
}

// Same contract on the PHP axis, and read from the claim for the same reason:
// every value of php.verified is a runtime a live matrix ran end to end.
$verifiedPhp = $platform['compatibility']['php']['verified'];
duo_check(count($verifiedPhp) >= 2, 'the shipped claim names more than one exercised PHP series');
foreach ($verifiedPhp as $series => $patch) {
    $failure = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $facts(php: $patch)));
    duo_check($failure === null, "the exercised PHP $series proof runtime $patch is accepted by the agent gate");
}

foreach ([
    'PHP inclusive minimum' => $facts(php: '8.3.0'),
    'PHP value below exclusive maximum' => $facts(php: '8.3.999'),
    // 8.4.0 was the exclusive maximum — a hard refusal — until this claim
    // widened to [8.3.0, 8.5.0) and named 8.4 as an exercised series. This
    // cell fails against the prior boundary.
    'PHP inclusive minimum of the newly exercised 8.4 series' => $facts(php: '8.4.0'),
    'PHP patch above the 8.4 proof inside its exercised series' => $facts(php: '8.4.99'),
    'MariaDB inclusive minimum' => $facts(database: '11.0.0'),
    'MariaDB value below exclusive maximum' => $facts(database: '11.999.999'),
    // The old suite refused this exact pair on the engine axis; the engines
    // map is what inverts it, and MySQL 8.4.3 is the line sandbox/db.mysql.yml
    // actually boots.
    'MySQL inside its own claimed engine range' => $facts(engine: 'MySQL', database: '8.4.3'),
    'MySQL inclusive minimum' => $facts(engine: 'MySQL', database: '8.4.0'),
    'MySQL value below its own exclusive maximum' => $facts(engine: 'MySQL', database: '8.4.999'),
    // Patch-level generalization inside an exercised series, the one genuine
    // widening in this claim: it is the same basis PHP 8.3.x and MariaDB 11.x
    // are already claimed on from one measured runtime each. Each of these
    // refused before the matrix, under hash_equals against 7.0.3.
    'WordPress inclusive minimum' => $facts(wordpress: '6.9.0'),
    'WordPress patch below the 6.9 proof' => $facts(wordpress: '6.9.1'),
    'WordPress unrun patch inside the exercised 6.9 series' => $facts(wordpress: '6.9.99'),
    'WordPress patch below last_verified inside its exercised series' => $facts(wordpress: '7.0.2'),
    'WordPress patch above last_verified inside its exercised series' => $facts(wordpress: '7.0.4'),
    // WordPress ships '7.1' — two components — as the 7.1 series' first
    // release (wp-includes/version.php:19), so the value the gate compares for
    // a brand-new series is not MAJOR.MINOR.PATCH. version() accepts 1-3 dots
    // and series('7.1') === '7.1', so this is a first-class claimed core, not
    // a shape the gate tolerates by accident.
    'WordPress two-component core string for a series first release' => $facts(wordpress: '7.1'),
    // The patch that does not exist yet but will: 7.1.0 was the exclusive
    // maximum — a hard refusal — until this claim widened to [6.9.0, 7.2.0).
    'WordPress patch generalized over inside the newly exercised 7.1 series' => $facts(wordpress: '7.1.0'),
    'WordPress later patch inside the newly exercised 7.1 series' => $facts(wordpress: '7.1.9'),
] as $label => $caseFacts) {
    $failure = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $caseFacts));
    duo_check($failure === null, "$label is accepted by the agent gate");
}

foreach ([
    'PHP below minimum' => [$facts(php: '8.2.99'), 'platform_php_version_unsupported'],
    'PHP exact exclusive maximum' => [$facts(php: '8.5.0'), 'platform_php_version_unsupported'],
    // A pre-release engine ranks inside the window under version_compare; the
    // observed value must still be a plain dotted version or the gate is
    // comparing something it never exercised. Doctor carries the same guard.
    'PHP pre-release runtime inside an exercised series' => [$facts(php: '8.4.0RC1'), 'platform_php_version_unsupported'],
    'MariaDB below minimum' => [$facts(database: '10.11.0'), 'platform_database_version_unsupported'],
    'MariaDB exact exclusive maximum' => [$facts(database: '12.0.0'), 'platform_database_version_unsupported'],
    // MySQL is claimed on ITS OWN line, not MariaDB's: 8.3.9 and 8.5.0 sit
    // inside no claimed range at all, and both would have been accepted by a
    // single shared {min,max} that happened to admit them.
    'MySQL below its own minimum' => [$facts(engine: 'MySQL', database: '8.3.9'), 'platform_database_version_unsupported'],
    'MySQL exact exclusive maximum of its own range' => [$facts(engine: 'MySQL', database: '8.5.0'), 'platform_database_version_unsupported'],
    // MariaDB 11.8.8 is inside MariaDB's range and nowhere near MySQL's, so
    // this proves the lookup is engine-keyed rather than a scan of every range.
    'MySQL carrying a MariaDB-shaped version' => [$facts(engine: 'MySQL', database: '11.8.8'), 'platform_database_version_unsupported'],
    'WordPress below minimum' => [$facts(wordpress: '6.8.3'), 'platform_wordpress_version_unsupported'],
    'WordPress exact exclusive maximum' => [$facts(wordpress: '7.2.0'), 'platform_wordpress_version_unsupported'],
    // Inside [6.9.0, 7.2.0) by version_compare and still unexercised: 6.10 is
    // a minor line the shipped `verified` map does not name. WordPress is not
    // semver, so this is a case the range half cannot catch on its own.
    'WordPress unexercised minor line inside the shipped range' => [$facts(wordpress: '6.10.0'), 'platform_wordpress_version_unsupported'],
    // A pre-release core ranks inside the window under version_compare; the
    // observed value must still be a plain dotted version or the gate is
    // comparing something it never exercised.
    'WordPress pre-release core inside an exercised series' => [$facts(wordpress: '7.0.4-alpha'), 'platform_wordpress_version_unsupported'],
    'unexercised filesystem OS family' => [$facts(osFamily: 'Windows'), 'platform_filesystem_os_unsupported'],
    'unexercised process OS family' => [$facts(processOsFamily: 'Windows'), 'platform_process_os_unsupported'],
    'non-POSIX directory separator' => [$facts(directorySeparator: '\\'), 'platform_filesystem_separator_unsupported'],
    'missing durable fsync function' => [$facts(filesystemFunctions: [
        'chmod' => true, 'flock' => true, 'fsync' => false, 'lstat' => true, 'rename' => true,
    ]), 'platform_filesystem_function_unsupported'],
    'missing process-group exec function' => [$facts(processFunctions: [
        'pcntl_exec' => false, 'posix_kill' => true, 'posix_setsid' => true, 'proc_close' => true, 'proc_open' => true,
    ]), 'platform_process_function_unsupported'],
    'multisite topology' => [$facts(siteMode: 'multisite'), 'platform_site_mode_unsupported'],
] as $label => [$caseFacts, $code]) {
    $failure = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $caseFacts));
    duo_check($failure instanceof CommandRefusalException, "$label refuses through the typed platform contract");
    duo_check_same('platform_unsupported', $failure?->reasonCode, "$label shares one stable command reason");
    duo_check_same($code, $failure?->diagnostics[0]['code'] ?? null, "$label names its exact platform axis");
}

// The `required` value is the whole matrix — range AND exercised series, in
// version_compare order — never one exact version. This exact string is the
// one the live proof asserts against a real refused core
// (sandbox/tests/live/regress_core_scope_platform.sh), so the offline and
// live halves of the evidence cannot drift apart.
$belowMinimum = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $facts(wordpress: '6.8.3')));
duo_check_same(
    '>=6.9.0 <7.2.0 exercised 6.9, 7.0, 7.1',
    $belowMinimum?->diagnostics[0]['required'] ?? null,
    'a refused core is told the exercised matrix, not one exact version'
);

// The PHP axis's own label, pinned for the same reason: it is the string the
// live PHP-refusal cell derives from the claim and asserts against a real
// refused runtime (sandbox/tests/live/regress_core_scope_platform.sh), so a
// drift between the two halves of the evidence would surface here first.
$phpBelowMinimum = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $facts(php: '8.2.99')));
duo_check_same(
    '>=8.3.0 <8.5.0 exercised 8.3, 8.4',
    $phpBelowMinimum?->diagnostics[0]['required'] ?? null,
    'a refused PHP runtime is told the exercised matrix, not the bare range'
);

// The database labels are the two the engines map introduced. An engine
// refusal names EVERY claimed engine (an operator on Postgres needs the set,
// not one member of it); a version refusal is engine-qualified, because
// '>=11.0.0 <12.0.0' alone would not say whose range it is now that the range
// depends on the engine.
$mysqlBelowMinimum = $refusal(static fn() => PlatformCompatibility::assert_supported(
    $platform,
    $facts(engine: 'MySQL', database: '8.3.9')
));
duo_check_same(
    'MySQL >=8.4.0 <8.5.0',
    $mysqlBelowMinimum?->diagnostics[0]['required'] ?? null,
    'a refused database version is told its OWN engine range, engine-qualified'
);
duo_check_same(
    'MariaDB >=11.0.0 <12.0.0',
    $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $facts(database: '10.11.0')))
        ?->diagnostics[0]['required'] ?? null,
    'and the established MariaDB range is qualified the same way — every version label names its engine, including the one that used to be the only claim'
);

// The engine half cannot be reached with the shipped claim (current_facts()
// classifies every server as MariaDB or MySQL and both are claimed), so the
// refusal that used to be the 'MySQL engine' cell is REPLACED, not deleted: a
// fixture boundary that omits MySQL must still refuse a MySQL target on the
// engine axis, naming the engines it does claim.
$mariadbOnly = $platform;
$mariadbOnly['compatibility']['database'] = [
    'engines' => ['MariaDB' => ['max' => '12.0.0', 'min' => '11.0.0']],
    'note' => 'fixture boundary claiming one engine',
];
$unclaimedEngine = $refusal(static fn() => PlatformCompatibility::assert_supported(
    $mariadbOnly,
    $facts(engine: 'MySQL', database: '8.4.3')
));
duo_check_same('platform_unsupported', $unclaimedEngine?->reasonCode, 'an unclaimed engine shares one stable command reason');
duo_check_same(
    'platform_database_engine_unsupported',
    $unclaimedEngine?->diagnostics[0]['code'] ?? null,
    'an engine no engines map names refuses on the engine axis, never on a version comparison'
);
duo_check_same(
    'MariaDB',
    $unclaimedEngine?->diagnostics[0]['required'] ?? null,
    'and the operator is told exactly which engines that boundary claims'
);
duo_check(
    $refusal(static fn() => PlatformCompatibility::assert_supported($mariadbOnly, $facts())) === null,
    'the same single-engine fixture still accepts the engine it does claim'
);

// The case a plain [min,max) range cannot express and would silently accept.
// The fixture leaves a deliberate hole at the 7.0 line, so a 7.0.1 target is
// inside the declared window and still unexercised: this refusal can only
// come from the series check, never from the range check agreeing by
// accident. DESIGN.md's vision invariant forbids exactly this shape of
// unproven behavior hidden behind a broad claim.
$holed = $platform;
$holed['compatibility']['wordpress'] = [
    'last_verified' => '7.1.4',
    'max' => '7.2.0',
    'min' => '6.9.0',
    'note' => 'fixture boundary with a deliberate 7.0 hole',
    'verified' => ['6.9' => '6.9.2', '7.1' => '7.1.4'],
];
$holeFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($holed, $facts(wordpress: '7.0.1')));
duo_check_same('platform_unsupported', $holeFailure?->reasonCode, 'an unexercised minor line inside the range shares one stable command reason');
duo_check_same(
    'platform_wordpress_version_unsupported',
    $holeFailure?->diagnostics[0]['code'] ?? null,
    'an unexercised minor line inside the declared range refuses on the WordPress axis'
);
duo_check_same(
    '>=6.9.0 <7.2.0 exercised 6.9, 7.1',
    $holeFailure?->diagnostics[0]['required'] ?? null,
    'the hole is named to the operator: the label lists exercised series, not the range endpoints alone'
);
duo_check(
    $refusal(static fn() => PlatformCompatibility::assert_supported($holed, $facts(wordpress: '7.1.9'))) === null,
    'the same holed boundary still accepts an unrun patch inside one of its exercised series'
);

// The identical proof for the PHP axis, and today the ONLY one available:
// the shipped php range [8.3.0, 8.5.0) contains exactly the two series
// `verified` names, so no shipped fact can separate the range half from the
// series half. This fixture holes 8.4 out of a wider range, which is exactly
// the state the next widening passes through — the shape is what makes that
// widening a data edit plus an exercise cell rather than a re-argued claim.
$holedPhp = $platform;
$holedPhp['compatibility']['php'] = [
    'max' => '8.6.0',
    'min' => '8.3.0',
    'note' => 'fixture boundary with a deliberate 8.4 hole',
    'verified' => ['8.3' => '8.3.33', '8.5' => '8.5.1'],
];
$phpHoleFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($holedPhp, $facts(php: '8.4.7')));
duo_check_same('platform_unsupported', $phpHoleFailure?->reasonCode, 'an unexercised PHP series inside the range shares one stable command reason');
duo_check_same(
    'platform_php_version_unsupported',
    $phpHoleFailure?->diagnostics[0]['code'] ?? null,
    'an unexercised PHP series inside the declared range refuses on the PHP axis alone'
);
duo_check_same(
    '>=8.3.0 <8.6.0 exercised 8.3, 8.5',
    $phpHoleFailure?->diagnostics[0]['required'] ?? null,
    'the PHP hole is named to the operator: the label lists exercised series, not the range endpoints alone'
);
duo_check(
    $refusal(static fn() => PlatformCompatibility::assert_supported($holedPhp, $facts(php: '8.5.9'))) === null,
    'the same holed PHP boundary still accepts an unrun patch inside one of its exercised series'
);

// Re-pointed by the widening, not weakened: 8.4.0 and MySQL 8.4.3 are both
// CLAIMED now, so the four axes have to be driven off values that are still
// outside the boundary — 8.5.0 is the new exclusive PHP maximum and MySQL
// 8.3.9 is below MySQL's own minimum. The database diagnostic is therefore
// the version one rather than the engine one; the engine axis has its own
// dedicated single-engine fixture above, because no shipped fact can reach it.
$allMismatch = $refusal(static fn() => PlatformCompatibility::assert_supported(
    $platform,
    $facts('8.5.0', 'MySQL', '8.3.9', '7.2.0', 'multisite', 'Windows', processOsFamily: 'Windows')
));
duo_check_same(
    [
        'platform_site_mode_unsupported',
        'platform_php_version_unsupported',
        'platform_database_version_unsupported',
        'platform_filesystem_os_unsupported',
        'platform_process_os_unsupported',
        'platform_wordpress_version_unsupported',
    ],
    array_column($allMismatch?->diagnostics ?? [], 'code'),
    'multi-axis incompatibility reports every independent mismatch in stable review order'
);
duo_check(
    $allMismatch !== null
        && !$allMismatch->detailsRedacted
        && !str_contains((string) json_encode($allMismatch->payload()), 'maria~')
        && !str_contains((string) json_encode($allMismatch->payload()), $root),
    'public platform diagnostics are actionable without database banners or host paths'
);

$malformed = $platform;
$malformed['compatibility']['php']['max'] = '8.3.0';
$invalidBoundary = $refusal(static fn() => PlatformCompatibility::assert_supported($malformed, $facts()));
duo_check_same('platform_boundary_invalid', $invalidBoundary?->reasonCode, 'a malformed platform range refuses before comparison');

// Each row is a boundary whose wordpress axis cannot be trusted to say what
// was exercised. Every one of them fails closed on the SAME existing
// platform_boundary_invalid refusal — there is no dual-shape acceptance path,
// including for the pre-matrix {last_verified, note} axis (AGENTS.md rule 9:
// no compat shim). Without these invariants a single typo in a series key
// silently widens the claim past what any live run proved.
foreach ([
    'the pre-matrix two-key wordpress axis' => [
        'last_verified' => '7.0.3',
        'note' => 'the shape this claim replaced',
    ],
    'an empty exercised-series map' => [
        'last_verified' => '7.0.3', 'max' => '7.1.0', 'min' => '6.9.0', 'note' => 'fixture',
        'verified' => [],
    ],
    'an exercised-series map declared as a list' => [
        'last_verified' => '7.0.3', 'max' => '7.1.0', 'min' => '6.9.0', 'note' => 'fixture',
        'verified' => ['6.9.2', '7.0.3'],
    ],
    'a series key that disagrees with its own proof patch' => [
        'last_verified' => '7.0.3', 'max' => '7.1.0', 'min' => '6.9.0', 'note' => 'fixture',
        'verified' => ['6.9' => '7.0.1', '7.0' => '7.0.3'],
    ],
    'an exercised patch outside the declared range' => [
        'last_verified' => '7.1.0', 'max' => '7.1.0', 'min' => '6.9.0', 'note' => 'fixture',
        'verified' => ['6.9' => '6.9.2', '7.1' => '7.1.0'],
    ],
    'a last_verified no exercised series names' => [
        'last_verified' => '7.0.4', 'max' => '7.1.0', 'min' => '6.9.0', 'note' => 'fixture',
        'verified' => ['6.9' => '6.9.2', '7.0' => '7.0.3'],
    ],
    // A member of `verified`, and still not the newest one: the readers that
    // bind last_verified as a scalar (the generic pair image, every
    // contract's expiry_and_dependencies) would point at a core the claim no
    // longer calls newest.
    'a last_verified older than an exercised series the same claim names' => [
        'last_verified' => '6.9.2', 'max' => '7.1.0', 'min' => '6.9.0', 'note' => 'fixture',
        'verified' => ['6.9' => '6.9.2', '7.0' => '7.0.3'],
    ],
    'a wordpress range whose minimum is not below its maximum' => [
        'last_verified' => '7.0.3', 'max' => '6.9.0', 'min' => '7.1.0', 'note' => 'fixture',
        'verified' => ['7.0' => '7.0.3'],
    ],
] as $label => $axis) {
    $shape = $platform;
    $shape['compatibility']['wordpress'] = $axis;
    $shapeFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($shape, $facts()));
    duo_check_same(
        'platform_boundary_invalid',
        $shapeFailure?->reasonCode,
        "$label refuses fail-closed before any platform comparison"
    );
}

// The identical fail-closed table for the PHP axis, which now declares the
// SAME shape through the SAME validator (PlatformCompatibility::
// valid_exercised_axis()). The first row is the shape this claim replaced:
// the pre-map two-key {min, max} php axis has no acceptance path, so a
// boundary that cannot state which PHP series were exercised refuses rather
// than silently reverting to a bare range (AGENTS.md rule 9).
foreach ([
    'the pre-map two-key php axis' => [
        'max' => '8.4.0', 'min' => '8.3.0', 'note' => 'the shape this claim replaced',
    ],
    'an empty exercised-series map on the php axis' => [
        'max' => '8.5.0', 'min' => '8.3.0', 'note' => 'fixture', 'verified' => [],
    ],
    'an exercised-series map on the php axis declared as a list' => [
        'max' => '8.5.0', 'min' => '8.3.0', 'note' => 'fixture', 'verified' => ['8.3.33', '8.4.24'],
    ],
    'a php series key that disagrees with its own proof patch' => [
        'max' => '8.5.0', 'min' => '8.3.0', 'note' => 'fixture',
        'verified' => ['8.3' => '8.4.24', '8.4' => '8.4.24'],
    ],
    'an exercised php patch outside the declared range' => [
        'max' => '8.5.0', 'min' => '8.3.0', 'note' => 'fixture',
        'verified' => ['8.3' => '8.3.33', '8.5' => '8.5.1'],
    ],
    'a php range whose minimum is not below its maximum' => [
        'max' => '8.3.0', 'min' => '8.5.0', 'note' => 'fixture',
        'verified' => ['8.3' => '8.3.33'],
    ],
] as $label => $axis) {
    $shape = $platform;
    $shape['compatibility']['php'] = $axis;
    $shapeFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($shape, $facts()));
    duo_check_same(
        'platform_boundary_invalid',
        $shapeFailure?->reasonCode,
        "$label refuses fail-closed before any platform comparison"
    );
}

// And the database axis, whose shape rules are the engines map's own. The
// pre-map {engine, min, max} row is the load-bearing one: under a per-engine
// claim a single engine name beside a single bare range cannot say which
// engine ANY other range would belong to, so it fails closed rather than
// being read as a one-entry map.
foreach ([
    'the pre-map single-engine database axis' => [
        'engine' => 'MariaDB', 'max' => '12.0.0', 'min' => '11.0.0',
        'note' => 'the shape this claim replaced',
    ],
    'an empty engines map' => ['engines' => [], 'note' => 'fixture'],
    'an engines map declared as a list' => [
        'engines' => [['max' => '12.0.0', 'min' => '11.0.0']], 'note' => 'fixture',
    ],
    'an engines map with a numeric (non-string) engine key' => [
        'engines' => [8 => ['max' => '8.5.0', 'min' => '8.4.0']], 'note' => 'fixture',
    ],
    'an engines map with a blank engine name' => [
        'engines' => ['' => ['max' => '12.0.0', 'min' => '11.0.0']], 'note' => 'fixture',
    ],
    // A stray key is refused because nothing else validates an engine entry:
    // tools/capability-doc.php's allowlist checks only the axis's own
    // top-level keys, so a `verified`-looking extra here would read as an
    // exercised-series claim this gate never evaluates.
    'an engine entry carrying a stray key' => [
        'engines' => ['MariaDB' => ['max' => '12.0.0', 'min' => '11.0.0', 'verified' => ['11.8' => '11.8.8']]],
        'note' => 'fixture',
    ],
    'an engine entry missing its maximum' => [
        'engines' => ['MariaDB' => ['min' => '11.0.0']], 'note' => 'fixture',
    ],
    'an engine range whose minimum is not below its maximum' => [
        'engines' => ['MariaDB' => ['max' => '11.0.0', 'min' => '12.0.0']], 'note' => 'fixture',
    ],
    'an engine entry that is not an object at all' => [
        'engines' => ['MariaDB' => '11.0.0-12.0.0'], 'note' => 'fixture',
    ],
] as $label => $axis) {
    $shape = $platform;
    $shape['compatibility']['database'] = $axis;
    $shapeFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($shape, $facts()));
    duo_check_same(
        'platform_boundary_invalid',
        $shapeFailure?->reasonCode,
        "$label refuses fail-closed before any platform comparison"
    );
}

// The filesystem profile is closed in both dimensions: its exact semantic
// identifier/function roster is code-owned, while the two OS families and
// slash separator are the only environments measured. A truncated or
// decorative profile must blame the boundary, never a healthy target.
foreach ([
    'a missing filesystem profile' => null,
    'an unreviewed filesystem profile identifier' => [
        'directory_separator' => '/', 'note' => 'fixture', 'os_families' => ['Darwin', 'Linux'],
        'profile' => 'generic-posix/v1',
        'required_functions' => ['chmod', 'flock', 'fsync', 'lstat', 'rename'],
    ],
    'a filesystem profile omitting one required function' => [
        'directory_separator' => '/', 'note' => 'fixture', 'os_families' => ['Darwin', 'Linux'],
        'profile' => 'local-posix-atomic-rename-flock-fsync/v1',
        'required_functions' => ['chmod', 'flock', 'lstat', 'rename'],
    ],
    'a filesystem profile widening to an unexercised OS' => [
        'directory_separator' => '/', 'note' => 'fixture', 'os_families' => ['Darwin', 'Linux', 'BSD'],
        'profile' => 'local-posix-atomic-rename-flock-fsync/v1',
        'required_functions' => ['chmod', 'flock', 'fsync', 'lstat', 'rename'],
    ],
    'a filesystem profile with an empty rationale' => [
        'directory_separator' => '/', 'note' => '', 'os_families' => ['Darwin', 'Linux'],
        'profile' => 'local-posix-atomic-rename-flock-fsync/v1',
        'required_functions' => ['chmod', 'flock', 'fsync', 'lstat', 'rename'],
    ],
] as $label => $axis) {
    $shape = $platform;
    if ($axis === null) {
        unset($shape['compatibility']['filesystem']);
    } else {
        $shape['compatibility']['filesystem'] = $axis;
    }
    $shapeFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($shape, $facts()));
    duo_check_same(
        'platform_boundary_invalid',
        $shapeFailure?->reasonCode,
        "$label refuses fail-closed before any platform comparison"
    );
}

// The process profile is closed to the exact transport contract: no generic
// POSIX alias, extra OS family, or omitted primitive may widen the claim.
foreach ([
    'a missing process profile' => null,
    'an unreviewed process profile identifier' => [
        'note' => 'fixture', 'os_families' => ['Darwin', 'Linux'],
        'profile' => 'generic-posix/v1',
        'required_functions' => ['pcntl_exec', 'posix_kill', 'posix_setsid', 'proc_close', 'proc_open'],
    ],
    'a process profile omitting one required function' => [
        'note' => 'fixture', 'os_families' => ['Darwin', 'Linux'],
        'profile' => 'local-posix-process-group-exec/v1',
        'required_functions' => ['pcntl_exec', 'posix_kill', 'posix_setsid', 'proc_open'],
    ],
    'a process profile widening to an unexercised OS' => [
        'note' => 'fixture', 'os_families' => ['Darwin', 'Linux', 'BSD'],
        'profile' => 'local-posix-process-group-exec/v1',
        'required_functions' => ['pcntl_exec', 'posix_kill', 'posix_setsid', 'proc_close', 'proc_open'],
    ],
    'a process profile with an empty rationale' => [
        'note' => '', 'os_families' => ['Darwin', 'Linux'],
        'profile' => 'local-posix-process-group-exec/v1',
        'required_functions' => ['pcntl_exec', 'posix_kill', 'posix_setsid', 'proc_close', 'proc_open'],
    ],
] as $label => $axis) {
    $shape = $platform;
    if ($axis === null) {
        unset($shape['compatibility']['process']);
    } else {
        $shape['compatibility']['process'] = $axis;
    }
    $shapeFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($shape, $facts()));
    duo_check_same(
        'platform_boundary_invalid',
        $shapeFailure?->reasonCode,
        "$label refuses fail-closed before any platform comparison"
    );
}

foreach ([
    'missing filesystem function fact' => $facts(filesystemFunctions: [
        'chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true,
    ]),
    'non-boolean filesystem function fact' => $facts(filesystemFunctions: [
        'chmod' => true, 'flock' => true, 'fsync' => 'yes', 'lstat' => true, 'rename' => true,
    ]),
    'missing process function fact' => $facts(processFunctions: [
        'pcntl_exec' => true, 'posix_kill' => true, 'posix_setsid' => true, 'proc_close' => true,
    ]),
    'non-boolean process function fact' => $facts(processFunctions: [
        'pcntl_exec' => true, 'posix_kill' => true, 'posix_setsid' => 'yes', 'proc_close' => true, 'proc_open' => true,
    ]),
] as $label => $caseFacts) {
    $shapeFailure = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $caseFacts));
    duo_check_same('platform_probe_unavailable', $shapeFailure?->reasonCode, "$label is a probe failure, not a target mismatch");
}

$liveFacts = PlatformCompatibility::current_facts();
$liveFunctions = [];
foreach (['chmod', 'flock', 'fsync', 'lstat', 'rename'] as $function) {
    $liveFunctions[$function] = function_exists($function);
}
$liveProcessFunctions = [];
foreach (['pcntl_exec', 'posix_kill', 'posix_setsid', 'proc_close', 'proc_open'] as $function) {
    $liveProcessFunctions[$function] = function_exists($function);
}
duo_check_same(
    [
        'php' => PHP_VERSION,
        'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
        'filesystem' => [
            'directory_separator' => DIRECTORY_SEPARATOR,
            'functions' => $liveFunctions,
            'os_family' => PHP_OS_FAMILY,
        ],
        'process' => [
            'functions' => $liveProcessFunctions,
            'os_family' => PHP_OS_FAMILY,
        ],
        'wordpress' => '7.1',
        'site_mode' => 'single-site',
    ],
    $liveFacts,
    'the checked probe reduces a real MariaDB-style server banner to reviewed platform facts'
);
duo_check_same(['SELECT VERSION()'], $GLOBALS['wpdb']->queries, 'the platform gate reads the server version exactly once');

$GLOBALS['wpdb']->server = '8.4.3';
$mysqlFacts = PlatformCompatibility::current_facts();
duo_check_same(['engine' => 'MySQL', 'version' => '8.4.3'], $mysqlFacts['database'], 'a non-MariaDB server banner is classified as MySQL, never guessed compatible');

$GLOBALS['wpdb']->server = false;
$GLOBALS['wpdb']->last_error = 'private database password sk_live_12345678901234567890';
$probeFailure = $refusal(static fn() => PlatformCompatibility::current_facts());
duo_check_same('platform_probe_unavailable', $probeFailure?->reasonCode, 'an unreadable database fact is a typed fail-closed probe refusal');
duo_check(
    $probeFailure !== null
        && !str_contains($probeFailure->getMessage(), 'password')
        && !str_contains((string) json_encode($probeFailure->payload()), 'sk_live'),
    'database driver errors and secret-shaped bytes never enter human or machine platform evidence'
);

// Whether THIS host's PHP is inside the shipped claim is derived, never
// assumed. It used to be a safe literal ("the host runs outside the 8.3.x
// window"); with the range widened to [8.3.0, 8.5.0) and this host on 8.5.6
// the margin is 0.0.6, and a literal here would silently change what the
// block proves the next time the claim moves — the suite would keep passing
// while asserting something else. Either branch is a real ordering claim:
// outside the claim, the platform refusal must precede the first repository
// read; inside it, the platform gate must NOT be what answers, so the
// deliberately missing repository is reached.
$GLOBALS['wpdb']->server = '11.8.8-MariaDB';
$GLOBALS['wpdb']->last_error = '';
require_once $root . '/agent/src/Policy/Policy.php';

$claimedPhp = $platform['compatibility']['php'];
$hostSeries = preg_match('/^(\d+\.\d+)/', PHP_VERSION, $hostMatch) === 1 ? $hostMatch[1] : '';
$hostInsideClaim = preg_match('/^\d+(?:\.\d+){1,3}$/D', PHP_VERSION) === 1
    && version_compare(PHP_VERSION, (string) $claimedPhp['min'], '>=')
    && version_compare(PHP_VERSION, (string) $claimedPhp['max'], '<')
    && is_string($claimedPhp['verified'][$hostSeries] ?? null)
    && in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)
    && DIRECTORY_SEPARATOR === '/'
    && !in_array(false, $liveFunctions, true);

$policyFailure = $refusal(static fn() => Duo\Policy::load('/definitely-missing-platform-ordering-repository'));
if ($hostInsideClaim) {
    duo_check(
        $policyFailure !== null && $policyFailure->reasonCode !== 'platform_unsupported',
        'Policy load on a host PHP the claim exercises passes the platform gate and refuses on the missing repository instead'
    );
} else {
    duo_check_same('platform_unsupported', $policyFailure?->reasonCode, 'Policy load invokes the platform gate on a real WordPress-shaped runtime');
    duo_check(
        $policyFailure !== null && !str_contains($policyFailure->getMessage(), 'site.duo.json'),
        'platform refusal precedes the first repository read'
    );
}

$GLOBALS['platform_multisite'] = true;
try {
    Duo\Policy::load('/definitely-missing-platform-ordering-repository');
    $multisiteMessage = '';
} catch (Throwable $failure) {
    $multisiteMessage = $failure->getMessage();
}
duo_check(
    str_contains($multisiteMessage, 'multisite is unsupported by the certified v1 contract')
        && !str_contains($multisiteMessage, 'platform compatibility refused'),
    'the established multisite refusal remains the first and exact topology answer'
);

duo_check_summary('platform compatibility');
