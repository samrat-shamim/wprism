<?php
declare(strict_types=1);

/**
 * Offline platform-boundary regression.
 *
 * Exercises the agent-owned gate with every inclusive/exclusive version edge,
 * the WordPress range-plus-exercised-series semantics, engine/topology
 * mismatches, checked live-probe parsing, safe aggregate diagnostics, and
 * Policy ordering. Before this gate, a direct `wp duo` command bypassed the
 * host doctor and reached repository reads or mutation on an entirely
 * unexercised runtime.
 *
 * The WordPress axis is two independent conditions, and this suite proves
 * both separately: inside [min, max) AND the observed MAJOR.MINOR present in
 * `verified`. A boundary with a deliberate hole (below) is what stops the
 * series half passing merely by agreeing with the range half.
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
    string $siteMode = 'single-site'
): array => [
    'php' => $php,
    'database' => ['engine' => $engine, 'version' => $database],
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
duo_check(true, 'the shipped PHP/MariaDB/WordPress/single-site platform boundary accepts its own newest exercised core');

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

foreach ([
    'PHP inclusive minimum' => $facts(php: '8.3.0'),
    'PHP value below exclusive maximum' => $facts(php: '8.3.999'),
    'MariaDB inclusive minimum' => $facts(database: '11.0.0'),
    'MariaDB value below exclusive maximum' => $facts(database: '11.999.999'),
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
    'PHP exact exclusive maximum' => [$facts(php: '8.4.0'), 'platform_php_version_unsupported'],
    'MariaDB below minimum' => [$facts(database: '10.11.0'), 'platform_database_version_unsupported'],
    'MariaDB exact exclusive maximum' => [$facts(database: '12.0.0'), 'platform_database_version_unsupported'],
    'MySQL engine' => [$facts(engine: 'MySQL', database: '8.4.3'), 'platform_database_engine_unsupported'],
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

$allMismatch = $refusal(static fn() => PlatformCompatibility::assert_supported(
    $platform,
    $facts('8.4.0', 'MySQL', '8.4.3', '7.2.0', 'multisite')
));
duo_check_same(
    [
        'platform_site_mode_unsupported',
        'platform_php_version_unsupported',
        'platform_database_engine_unsupported',
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

$liveFacts = PlatformCompatibility::current_facts();
duo_check_same(
    [
        'php' => PHP_VERSION,
        'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
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

// The host PHP intentionally runs outside the shipped 8.3.x window. With a
// healthy target probe restored, Policy must therefore refuse that platform
// before it can reveal whether the deliberately missing repository exists.
$GLOBALS['wpdb']->server = '11.8.8-MariaDB';
$GLOBALS['wpdb']->last_error = '';
require_once $root . '/agent/src/Policy/Policy.php';

$policyFailure = $refusal(static fn() => Duo\Policy::load('/definitely-missing-platform-ordering-repository'));
duo_check_same('platform_unsupported', $policyFailure?->reasonCode, 'Policy load invokes the platform gate on a real WordPress-shaped runtime');
duo_check(
    $policyFailure !== null && !str_contains($policyFailure->getMessage(), 'site.duo.json'),
    'platform refusal precedes the first repository read'
);

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
