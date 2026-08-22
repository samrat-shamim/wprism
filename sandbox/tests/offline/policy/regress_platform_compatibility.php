<?php
declare(strict_types=1);

/**
 * Offline platform-boundary regression.
 *
 * Exercises the agent-owned gate with every inclusive/exclusive version edge,
 * exact WordPress semantics, engine/topology mismatches, checked live-probe
 * parsing, safe aggregate diagnostics, and Policy ordering. Before this gate,
 * a direct `wp duo` command bypassed the host doctor and reached repository
 * reads or mutation on an entirely unexercised runtime.
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

$GLOBALS['platform_wordpress_version'] = '7.0.3';
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
    string $wordpress = '7.0.3',
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
duo_check(true, 'the exact exercised PHP/MariaDB/WordPress/single-site platform is accepted');

foreach ([
    'PHP inclusive minimum' => $facts(php: '8.3.0'),
    'PHP value below exclusive maximum' => $facts(php: '8.3.999'),
    'MariaDB inclusive minimum' => $facts(database: '11.0.0'),
    'MariaDB value below exclusive maximum' => $facts(database: '11.999.999'),
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
    'older WordPress' => [$facts(wordpress: '7.0.2'), 'platform_wordpress_version_unsupported'],
    'newer WordPress' => [$facts(wordpress: '7.0.4'), 'platform_wordpress_version_unsupported'],
    'multisite topology' => [$facts(siteMode: 'multisite'), 'platform_site_mode_unsupported'],
] as $label => [$caseFacts, $code]) {
    $failure = $refusal(static fn() => PlatformCompatibility::assert_supported($platform, $caseFacts));
    duo_check($failure instanceof CommandRefusalException, "$label refuses through the typed platform contract");
    duo_check_same('platform_unsupported', $failure?->reasonCode, "$label shares one stable command reason");
    duo_check_same($code, $failure?->diagnostics[0]['code'] ?? null, "$label names its exact platform axis");
}

$allMismatch = $refusal(static fn() => PlatformCompatibility::assert_supported(
    $platform,
    $facts('8.4.0', 'MySQL', '8.4.3', '7.0.4', 'multisite')
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

$liveFacts = PlatformCompatibility::current_facts();
duo_check_same(
    [
        'php' => PHP_VERSION,
        'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
        'wordpress' => '7.0.3',
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
