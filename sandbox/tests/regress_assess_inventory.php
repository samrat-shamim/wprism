<?php
/**
 * Offline characterization for `wp duo assess-inventory` / \Duo\AssessInventory.
 *
 * WHAT CAN DRIFT, AND WHY IT MATTERS
 * ----------------------------------
 * `duo-assess-inventory/v1` is the ONE document `duo assess` reads off a
 * target (round-3 MUP §2.1/§4.5). Two properties of it are load-bearing and
 * neither is visible by reading the class:
 *
 *  1. **Redaction.** The document is names and counts only. It is produced by
 *     code that legitimately READS option values (Coverage classifies by
 *     value) and table rows, so "no value ever reaches the output" is a
 *     property of the composition, not of any one function. This suite seeds
 *     a sentinel secret into a live option and asserts the sentinel appears
 *     nowhere in the canonical bytes.
 *  2. **Closed vocabularies and a stable key set.** The host side implements
 *     the same contract independently; a silently added key or a surface
 *     class outside `Policy::CLASSES` breaks it at the seam rather than here.
 *
 * It also pins the two bounds §4.6 requires (the pending queue truncates at
 * 50 with a flag) and the engine-adapter boundary the boundary doctrine binds
 * to the drop-in first: no plugin slug, name or theme may appear anywhere in
 * `agent/src/Assess/` — asserted against the real manifest library, so a new
 * adapter automatically joins the forbidden set.
 *
 * SEAM. `AssessInventory::report()` calls `CapabilityRegistry::probe_target()`,
 * whose `SELECT VERSION()` the offline `$wpdb` deliberately refuses to model,
 * and `Pending::scan_read_only()`, whose gate walk needs a live WordPress. So
 * the suite drives `from_facts()`, which is the same document builder with
 * exactly those two facts (plus the coverage projection and the adapter
 * survey) handed in. Everything the class does itself — plugins, themes,
 * media, the manifest list and every surface group — is still read live from
 * the seeded fake site, and `Coverage::report()` is the REAL one, which is
 * what makes the sentinel assertion mean something.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';
require_once __DIR__ . '/lib/wp_stubs.php';
require_once __DIR__ . '/lib/FakeWpdb.php';

$repoRoot = dirname(__DIR__, 2);

// The agent binds both constants at load; read them from the drop-in rather
// than restating them, so a version bump does not need this file edited.
$bootstrap = (string) file_get_contents($repoRoot . '/agent/duo.php');
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', preg_match("/define\('DUO_SPEC_VERSION',\s*(\d+)\)/", $bootstrap, $m) === 1
        ? (int) $m[1] : 2);
}
if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', preg_match("/define\('DUO_AGENT_VERSION',\s*'([^']+)'\)/", $bootstrap, $m) === 1
        ? $m[1] : '0.0.0');
}

// The drop-in loads its whole class graph from agent/duo.php before any
// command runs. Offline, only what is required transitively is present,
// and Policy guards its reviewed registries with class_exists() -- so
// without this the pinned-adapter rows would silently report a null
// digest and a null status, and the suite would pin the degraded shape
// as if it were the contract.
require_once $repoRoot . '/agent/src/Policy/ManifestDispositions.php';
require_once $repoRoot . '/agent/src/Assess/AssessInventory.php';

use Duo\AssessInventory;
use Duo\Canon;
use Duo\Coverage;
use Duo\Policy;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

// ---------------------------------------------------------------------------
// Stubs the shared lib does not carry yet. Every one is function_exists()
// guarded, per lib/README.md's own migration rule; they are reported for
// upstreaming rather than written into lib/ here, because another change is
// editing that directory.
// ---------------------------------------------------------------------------
if (!class_exists('DuoTest\\FakeTheme')) {
    /** The two accessors `wp_get_theme()` consumers actually call. */
    final class FakeTheme {
        /** @param array<string,string> $headers */
        public function __construct(private array $headers) {}

        public function get(string $header): string {
            return $this->headers[$header] ?? '';
        }
    }
    class_alias(FakeTheme::class, 'DuoTest\\FakeTheme');
}

// basename => plugin header array, as get_plugins() returns it.
$GLOBALS['duo_test_plugins'] = [];
// stylesheet => FakeTheme, as wp_get_themes() returns it.
$GLOBALS['duo_test_themes'] = [];

if (!function_exists('get_plugins')) {
    function get_plugins(string $plugin_folder = ''): array {
        return $GLOBALS['duo_test_plugins'];
    }
}
if (!function_exists('wp_get_themes')) {
    function wp_get_themes(array $args = []): array {
        return $GLOBALS['duo_test_themes'];
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string {
        return rtrim((string) get_option('home', 'http://example.test'), '/') . $path;
    }
}
if (!function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string {
        return rtrim((string) get_option('siteurl', 'http://example.test'), '/') . $path;
    }
}

// ---------------------------------------------------------------------------
// Fixture site
// ---------------------------------------------------------------------------
$scratch = sys_get_temp_dir() . '/duo_regress_assess_inventory_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($scratch . '/repo', 0777, true);
register_shutdown_function(static function () use ($scratch): void {
    foreach (['/repo/site.duo.json'] as $file) {
        @unlink($scratch . $file);
    }
    @rmdir($scratch . '/repo');
    @rmdir($scratch);
});
$repo = $scratch . '/repo';

// The real shipped library: Policy::manifests_dir() already resolves to it,
// so the pinned adapter row, its digest and its reviewed status are the real
// registry's answers rather than a fixture's idea of them.
file_put_contents($repo . '/site.duo.json', json_encode([
    'manifests' => ['core'],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// A value no redaction bug could produce by coincidence.
const SENTINEL = 'duo-sentinel-8f3ac1e0-never-in-output';

$store = WpStore::reset();
$store->version = '7.0.3';
$store->seedOptions([
    'active_plugins' => ['acme-storefront/acme-storefront.php'],
    'stylesheet' => 'fixture-child',
    'template' => 'fixture-parent',
    'home' => 'https://shop.example.test',
    'siteurl' => 'https://shop.example.test/wp',
]);

$GLOBALS['duo_test_plugins'] = [
    'zeta-tools/zeta-tools.php' => ['Name' => 'Zeta Tools', 'Version' => '0.9.1'],
    'acme-storefront/acme-storefront.php' => ['Name' => 'Acme Storefront', 'Version' => '4.2.0'],
];
$GLOBALS['duo_test_themes'] = [
    'fixture-parent' => new FakeTheme(['Name' => 'Fixture Parent', 'Version' => '2.0.0']),
    'fixture-child' => new FakeTheme(['Name' => 'Fixture Child', 'Version' => '2.0.1']),
    'fixture-unused' => new FakeTheme(['Name' => 'Fixture Unused', 'Version' => '1.0.0']),
];

$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'blogname', 'option_value' => 'Fixture Shop', 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'siteurl', 'option_value' => 'https://shop.example.test/wp', 'autoload' => 'yes'],
    // The sentinel rides an option name no manifest declares, so Coverage
    // classifies it as invisible -- the exact bucket whose GROUPING reads the
    // name and whose classification reads the value.
    ['option_id' => 3, 'option_name' => 'acme_storefront_api_secret', 'option_value' => SENTINEL, 'autoload' => 'no'],
    ['option_id' => 4, 'option_name' => '_transient_acme_cache', 'option_value' => SENTINEL, 'autoload' => 'no'],
]);
$wpdb->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello', 'post_status' => 'publish'],
    ['ID' => 2, 'post_type' => 'page', 'post_title' => 'About', 'post_status' => 'publish'],
    ['ID' => 3, 'post_type' => 'attachment', 'post_title' => 'Mug', 'post_status' => 'inherit'],
    ['ID' => 4, 'post_type' => 'attachment', 'post_title' => 'Cup', 'post_status' => 'inherit'],
    ['ID' => 5, 'post_type' => 'acme_widget', 'post_title' => 'Undeclared', 'post_status' => 'publish'],
]);
$wpdb->seedTable('wp_term_taxonomy', [
    ['term_taxonomy_id' => 1, 'term_id' => 1, 'taxonomy' => 'category', 'count' => 1],
    ['term_taxonomy_id' => 2, 'term_id' => 2, 'taxonomy' => 'post_tag', 'count' => 0],
    ['term_taxonomy_id' => 3, 'term_id' => 3, 'taxonomy' => 'post_tag', 'count' => 0],
]);
// Coverage's table half and AssessInventory's declared-table counts both walk
// SHOW TABLES LIKE 'wp_%'; core declares comments/commentmeta, and wp_acme_log
// is the undeclared table an assessment must be able to see.
$wpdb->seedTable('wp_comments', [['comment_ID' => 1, 'comment_post_ID' => 1]]);
$wpdb->seedTable('wp_commentmeta', []);
$wpdb->seedTable('wp_acme_log', [['id' => 1, 'payload' => SENTINEL]]);

$policy = Policy::load($repo);

// ---------------------------------------------------------------------------
// The injected facts
// ---------------------------------------------------------------------------
$probe = [
    'wordpress' => '7.0.3',
    'php' => '8.3.33',
    'database' => [
        'client' => '110808',
        'server' => '11.8.8-MariaDB-1:11.8.8+maria~ubu2404',
        'engine' => 'MariaDB',
    ],
    'multisite' => false,
    'active_plugins' => ['acme-storefront/acme-storefront.php'],
    'active_theme' => ['template' => 'fixture-parent', 'stylesheet' => 'fixture-child'],
    'plugins' => [],
    'themes' => [],
];

$pendingRows = [];
for ($i = 0; $i < 52; $i++) {
    $pendingRows[] = [
        'section' => 'options',
        'key' => sprintf('acme_pending_%02d', $i),
        'proposal' => null,
        'evidence' => ['entities' => 1],
    ];
}

$survey = [
    'sources' => [['source' => 'shipped', 'path' => Policy::manifests_dir(), 'scanned' => true, 'note' => 'fixture']],
    'adapters' => [['name' => 'core', 'source' => 'shipped', 'grammar' => ['status' => 'ok', 'message' => null]]],
    'not_installed' => [],
    'refusals' => [],
];

$coverage = Coverage::report($repo);
duo_check(
    $coverage['options']['total'] === 4,
    'the real Coverage projection read all four seeded option rows (' . $coverage['options']['total'] . ')'
);

$facts = [
    'probe' => $probe,
    'coverage' => $coverage,
    'pending' => $pendingRows,
    'adapter_survey' => $survey,
    'adapter_survey_reason' => null,
];

$document = AssessInventory::from_facts($policy, $facts);
$encoded = Canon::encode($document);

// ---------------------------------------------------------------------------
echo "\n== the document's own contract ==\n";
// ---------------------------------------------------------------------------
duo_check_same('duo-assess-inventory/v1', $document['format'], 'the document names its own versioned format');
duo_check_same(AssessInventory::FORMAT, $document['format'], 'the constant and the emitted format agree');
duo_check_same(DUO_SPEC_VERSION, $document['spec_version'], 'the document binds the agent spec version');
duo_check_same(DUO_AGENT_VERSION, $document['agent_version'], 'the document binds the agent version');

$topLevel = array_keys($document);
sort($topLevel, SORT_STRING);
duo_check_same(
    ['adapter_survey', 'agent_version', 'coverage', 'format', 'media', 'pending', 'plugins',
        'plugins_without_adapter', 'policy', 'spec_version', 'target', 'themes'],
    $topLevel,
    'a healthy document carries exactly the shared contract key set'
);

// ---------------------------------------------------------------------------
echo "\n== target: the stack, from the probe the registry already runs ==\n";
// ---------------------------------------------------------------------------
$target = $document['target'];
$targetKeys = array_keys($target);
sort($targetKeys, SORT_STRING);
duo_check_same(
    ['database', 'home', 'php', 'site_mode', 'siteurl', 'wordpress'],
    $targetKeys,
    'target carries exactly its contract keys'
);
duo_check_same('7.0.3', $target['wordpress'], 'target names the probed WordPress version');
duo_check_same('8.3.33', $target['php'], 'target names the probed PHP version');
duo_check_same('MariaDB', $target['database']['engine'], 'a MariaDB banner projects the MariaDB engine');
duo_check_same('11.8.8', $target['database']['version'], 'the server banner reduces to its dotted numeric version');
duo_check_same('single-site', $target['site_mode'], 'a non-multisite probe projects single-site');
duo_check(
    in_array($target['site_mode'], AssessInventory::SITE_MODES, true)
        && in_array($target['database']['engine'], AssessInventory::DATABASE_ENGINES, true),
    'site_mode and database engine come from their closed vocabularies'
);
duo_check_same('https://shop.example.test', $target['home'], 'home comes from the WordPress URL API');
duo_check_same('https://shop.example.test/wp', $target['siteurl'], 'siteurl comes from the WordPress URL API');

$mysql = AssessInventory::from_facts($policy, ['probe' => ['database' => ['server' => '8.0.36']] + $probe] + $facts);
duo_check_same('MySQL', $mysql['target']['database']['engine'], 'a banner without MariaDB in it projects MySQL');
duo_check_same('8.0.36', $mysql['target']['database']['version'], 'a bare numeric banner survives version reduction');

$multisite = AssessInventory::from_facts($policy, ['probe' => ['multisite' => true] + $probe] + $facts);
duo_check_same(
    'multisite',
    $multisite['target']['site_mode'],
    'assess-inventory REPORTS an unsupported topology instead of refusing to describe it'
);

// ---------------------------------------------------------------------------
echo "\n== installed code: every plugin and theme, ordered, with activation ==\n";
// ---------------------------------------------------------------------------
duo_check_same(
    ['acme-storefront/acme-storefront.php', 'zeta-tools/zeta-tools.php'],
    array_column($document['plugins'], 'basename'),
    'plugins are ordered by basename, inactive ones included'
);
duo_check_same([true, false], array_column($document['plugins'], 'active'), 'only the probe-active plugin is active');
duo_check_same(['4.2.0', '0.9.1'], array_column($document['plugins'], 'version'), 'plugin versions come from the header');
duo_check_same(
    ['fixture-child', 'fixture-parent', 'fixture-unused'],
    array_column($document['themes'], 'stylesheet'),
    'themes are ordered by stylesheet, uninstalled-but-present ones included'
);
duo_check_same(
    [true, true, false],
    array_column($document['themes'], 'active'),
    'a child theme makes BOTH stylesheet and template active; the third theme is not'
);

// ---------------------------------------------------------------------------
echo "\n== the active plugins no pinned adapter declares ==\n";
// ---------------------------------------------------------------------------
// The fixture pins `core` alone, which declares no plugin, so the one active
// plugin is unmanaged. Without this list the host has no bounded, name-only
// source for the `plugin:<slug>` assess surface (round-3 T6 §3.6) and has to
// re-derive plugin ownership from manifest bytes it does not hold.
duo_check_same(
    [[
        'basename' => 'acme-storefront/acme-storefront.php',
        'file' => 'acme-storefront.php',
        'slug' => 'acme-storefront',
    ]],
    $document['plugins_without_adapter'],
    'the one active plugin no pinned adapter declares is published with all three parts of its identity, '
    . 'so the host splits nothing: basename (the same key the plugins rows use), file, and slug'
);
duo_check(
    !in_array(
        'zeta-tools/zeta-tools.php',
        array_column($document['plugins_without_adapter'], 'basename'),
        true
    ),
    'an INACTIVE plugin is not an unmanaged-plugin row — activation is what puts a plugin in scope'
);
$singleFile = AssessInventory::from_facts(
    $policy,
    ['probe' => ['active_plugins' => ['hello.php', 'acme-storefront/acme-storefront.php']] + $probe] + $facts
);
duo_check_same(
    [
        ['basename' => 'acme-storefront/acme-storefront.php', 'file' => 'acme-storefront.php',
            'slug' => 'acme-storefront'],
        ['basename' => 'hello.php', 'file' => 'hello.php', 'slug' => 'hello'],
    ],
    $singleFile['plugins_without_adapter'],
    'a single-file plugin has no directory, so its slug is the file name without .php; rows sort by basename'
);

duo_check_same(2, $document['media']['count'], 'media counts the attachment rows and nothing else');
duo_check_same(null, $document['media']['bytes'], 'stored bytes are declared unknown rather than guessed');

// ---------------------------------------------------------------------------
echo "\n== policy: the pinned adapters and the surface groups ==\n";
// ---------------------------------------------------------------------------
duo_check_same(['core'], array_column($document['policy']['manifests'], 'name'), 'the pinned adapter set is reported');
$manifestRow = $document['policy']['manifests'][0];
duo_check_same(
    ['adapter_digest', 'name', 'source', 'status'],
    (static function (array $r): array { $k = array_keys($r);
    sort($k, SORT_STRING);
    return $k; })($manifestRow),
    'each manifest row carries exactly its contract keys'
);
duo_check_same('shipped', $manifestRow['source'], 'the shipped library reports the shipped source');
duo_check(
    preg_match('/^[0-9a-f]{64}$/D', (string) $manifestRow['adapter_digest']) === 1,
    'adapter_digest is the registry claim\'s bare hex digest, never a decorated string'
);
duo_check_same(
    (string) (json_decode((string) file_get_contents($repoRoot . '/manifests/capabilities/registry.json'), true)['manifests']['core']['adapter_digest'] ?? ''),
    $manifestRow['adapter_digest'],
    'the digest is READ from the generated registry, not recomputed here'
);
duo_check_same(
    (string) (json_decode((string) file_get_contents($repoRoot . '/manifests/dispositions.json'), true)['manifests']['core']['status'] ?? ''),
    $manifestRow['status'],
    'status is the reviewed disposition status, quoted'
);

$groups = $document['policy']['surface_groups'];
$ids = array_column($groups, 'id');
$sortedIds = $ids;
sort($sortedIds, SORT_STRING);
duo_check_same($sortedIds, $ids, 'surface groups are emitted in a stable id order');
duo_check_same(count($ids), count(array_unique($ids)), 'no surface group id is emitted twice');

$badKind = array_values(array_filter($groups, static fn(array $g): bool => !in_array($g['kind'], AssessInventory::SURFACE_KINDS, true)));
duo_check_same([], $badKind, 'every surface kind comes from the closed set');
$badClass = array_values(array_filter($groups, static fn(array $g): bool => !in_array($g['class'], Policy::CLASSES, true)));
duo_check_same([], $badClass, 'every surface class comes from Policy::CLASSES');
$declarants = array_unique(array_column($groups, 'declared_by'));
sort($declarants, SORT_STRING);
duo_check_same(['core'], $declarants, 'with only core pinned, every group is declared by core');
$badCount = array_values(array_filter(
    $groups,
    static fn(array $g): bool => $g['count'] !== null && !is_int($g['count'])
));
duo_check_same([], $badCount, 'count is an int or null, never a string from the text protocol');

$byId = [];
foreach ($groups as $group) {
    $byId[$group['id']] = $group;
}
duo_check_same(
    ['class' => 'authored', 'count' => 1, 'declared_by' => 'core', 'id' => 'post_type:post', 'kind' => 'post_type'],
    (static function (array $g): array { ksort($g, SORT_STRING);
    return $g; })($byId['post_type:post'] ?? []),
    'a site-scoped post type is an authored surface group counted live'
);
duo_check_same(2, $byId['post_type:attachment']['count'] ?? null, 'the attachment post type carries the live count');
duo_check_same(2, $byId['media:attachment']['count'] ?? null, 'media repeats that count under the surface an operator names');
duo_check_same(2, $byId['taxonomy:post_tag']['count'] ?? null, 'a taxonomy group counts its term_taxonomy rows');
duo_check_same('runtime', $byId['table:comments']['class'] ?? null, "core's own table declaration classifies comments runtime");
duo_check_same(1, $byId['table:comments']['count'] ?? null, 'a declared table present on this install carries its row count');
duo_check_same(0, $byId['table:commentmeta']['count'] ?? null, 'an empty declared table counts 0, not null');
duo_check(!isset($byId['table:acme_log']), 'an UNDECLARED live table is coverage\'s finding, not a declared surface group');
duo_check_same('authored', $byId['menu:locations']['class'] ?? null, 'the declared menu field is an authored surface');
duo_check(
    array_key_exists('count', $byId['menu:locations'] ?? []) && $byId['menu:locations']['count'] === null,
    'a menu group carries an explicit null count: no bounded live oracle exists for it'
);
duo_check(isset($byId['widget:nav_menu']), 'each declared widget type is its own surface group');
duo_check_same('authored', $byId['widget:nav_menu']['class'] ?? null, 'a widget whose settings agree reports that class');
duo_check(isset($byId['option_group:core:authored']), 'options are grouped by declarant and class, never listed per name');
duo_check(isset($byId['option_group:core:env']), 'an env-classified option group is visible as its own row');
duo_check(isset($byId['option_group:core:managed']), 'a managed lifecycle option group is visible as its own row');
duo_check(
    array_key_exists('count', $byId['option_group:core:authored'] ?? [])
        && $byId['option_group:core:authored']['count'] === null,
    'an option group carries an explicit null count: the live number would mean re-reading the options table'
);
$optionGroupIds = array_values(array_filter($ids, static fn(string $id): bool => str_starts_with($id, 'option_group:')));
duo_check(
    count($optionGroupIds) <= count(Policy::CLASSES) * count($document['policy']['manifests']),
    'option groups are bounded by declarants x classes, never by the site (' . count($optionGroupIds) . ')'
);

// ---------------------------------------------------------------------------
echo "\n== quoted projections, and the bound on the one that is site-sized ==\n";
// ---------------------------------------------------------------------------
duo_check_same($coverage, $document['coverage'], 'the coverage report is quoted verbatim, not re-shaped');
duo_check_same(52, $document['pending']['count'], 'pending reports the TRUE count, not the truncated one');
duo_check_same(50, count($document['pending']['rows']), 'pending truncates at the §4.6 bound');
duo_check_same(AssessInventory::PENDING_ROW_LIMIT, count($document['pending']['rows']), 'that bound is the published constant');
duo_check_same(true, $document['pending']['truncated'], 'truncation is declared, never silent');
duo_check_same($pendingRows[0], $document['pending']['rows'][0], 'pending rows keep the shape scan_read_only() produced');

$short = AssessInventory::from_facts($policy, ['pending' => array_slice($pendingRows, 0, 3)] + $facts);
duo_check_same(false, $short['pending']['truncated'], 'a queue inside the bound is not marked truncated');
duo_check_same(3, count($short['pending']['rows']), 'a short queue is passed through whole');

duo_check_same($survey, $document['adapter_survey'], 'the adapter survey block is quoted verbatim');
$noSurvey = AssessInventory::from_facts($policy, ['adapter_survey' => null, 'adapter_survey_reason' => 'adapter_survey_unreadable'] + $facts);
duo_check_same(null, $noSurvey['adapter_survey'], 'an unavailable survey is null, per the shared contract');
duo_check_same(
    'adapter_survey_unreadable',
    $noSurvey['adapter_survey_reason'] ?? null,
    'a null survey says WHY, so it cannot be read as "this target has no adapters"'
);
duo_check(
    !array_key_exists('adapter_survey_reason', $document),
    'the reason key is absent from a healthy document, so the contract key set is exact'
);

// ---------------------------------------------------------------------------
echo "\n== redaction: names and counts only ==\n";
// ---------------------------------------------------------------------------
duo_check(
    !str_contains($encoded, SENTINEL),
    'no seeded option VALUE reaches the document, though Coverage read every one of them to classify it'
);
duo_check(
    !str_contains($encoded, 'Hello') && !str_contains($encoded, 'Undeclared'),
    'no wp_posts row CONTENT reaches the document'
);
duo_check(
    str_contains($encoded, 'acme_storefront'),
    "the invisible option's own NAME prefix is still reported -- redaction is of values, not of the inventory"
);

// ---------------------------------------------------------------------------
echo "\n== determinism ==\n";
// ---------------------------------------------------------------------------
duo_check_same(
    $encoded,
    Canon::encode(AssessInventory::from_facts($policy, $facts)),
    'two runs over identical facts produce byte-identical canonical JSON'
);
duo_check_same(
    $encoded,
    Canon::encode(Canon::decode($encoded)),
    'the document survives a canonical round trip unchanged'
);

// ---------------------------------------------------------------------------
echo "\n== refusals ==\n";
// ---------------------------------------------------------------------------
duo_check_refuses(
    static fn() => AssessInventory::from_facts($policy, ['probe' => null] + $facts),
    'assess_inventory_unavailable',
    'a target that cannot be probed is refused, never described from defaults'
);
duo_check_refuses(
    static fn() => AssessInventory::report($policy, []),
    'invalid_arguments',
    'report() refuses without --repo rather than assessing an unnamed repository'
);

// ---------------------------------------------------------------------------
echo "\n== the wp duo surface ==\n";
// ---------------------------------------------------------------------------
$reportMethod = new ReflectionMethod(AssessInventory::class, 'report');
duo_check(
    $reportMethod->isStatic() && $reportMethod->isPublic(),
    'report() is the public static entry point the command calls'
);
duo_check_same(
    ['Duo\\Policy', 'array'],
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $reportMethod->getParameters()),
    'report(Policy, array) is the signature the command binds'
);

$cli = (string) file_get_contents($repoRoot . '/agent/src/Command/Cli.php');
duo_check(str_contains($cli, '@subcommand assess-inventory'), 'the verb is declared as wp duo assess-inventory');
duo_check(
    str_contains($cli, "self::halt_json_failure(\$t, \$assoc, 'assess-inventory')"),
    'the verb routes its refusals through the shared duo-command-refusal/v1 envelope'
);
duo_check(
    str_contains($cli, '[--manifests=<dir>]') && str_contains($cli, "putenv('DUO_MANIFESTS_DIR')"),
    'the verb accepts a manifest library selector and restores the previous one'
);
duo_check(
    substr_count($cli, 'public function assess_inventory(') === 1,
    'exactly one handler was added to the command surface'
);

// ---------------------------------------------------------------------------
echo "\n== engine-adapter boundary: no plugin name inside agent/src/Assess/ ==\n";
// ---------------------------------------------------------------------------
// The forbidden set is DERIVED from the shipped library, so a new adapter
// joins it without this file being edited. `core` is excluded: it is the
// engine's own baseline manifest, not a third-party plugin.
$forbidden = [];
foreach (glob($repoRoot . '/manifests/*.json') ?: [] as $file) {
    if (basename($file) === 'dispositions.json') {
        continue;
    }
    $manifest = json_decode((string) file_get_contents($file), true);
    if (!is_array($manifest)) {
        continue;
    }
    $name = (string) ($manifest['name'] ?? '');
    if ($name !== '' && $name !== 'core') {
        $forbidden[$name] = true;
    }
    if (is_string($manifest['plugin'] ?? null) && $manifest['plugin'] !== '') {
        $forbidden[strtok($manifest['plugin'], '/')] = true;
    }
    if (is_string($manifest['theme'] ?? null) && $manifest['theme'] !== '') {
        $forbidden[$manifest['theme']] = true;
    }
}
duo_check(count($forbidden) >= 8, 'the forbidden set was derived from the real manifest library (' . count($forbidden) . ')');

$boundaryDirs = array_values(array_filter([
    $repoRoot . '/agent/src/Assess',
    $repoRoot . '/cli/src/Assess',
    $repoRoot . '/cli/src/Contract',
], 'is_dir'));
duo_check(in_array($repoRoot . '/agent/src/Assess', $boundaryDirs, true), 'the agent Assess module exists and is scanned');
$leaks = [];
foreach ($boundaryDirs as $dir) {
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $source = strtolower((string) file_get_contents($file));
        foreach (array_keys($forbidden) as $slug) {
            if (str_contains($source, strtolower((string) $slug))) {
                $leaks[] = basename(dirname($file)) . '/' . basename($file) . ' names ' . $slug;
            }
        }
    }
}
duo_check_same([], $leaks, 'no plugin slug, adapter name or theme appears in the assess/contract modules');

duo_check_summary('assess-inventory');
