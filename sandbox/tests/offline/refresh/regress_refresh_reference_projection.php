<?php
declare(strict_types=1);

// The optional source root executes this same product-path regression against
// the pre-fix candidate; it is never a runtime policy or library override.
$root = isset($argv[1]) ? rtrim($argv[1], '/') : dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Capture/Capture.php';
require_once $root . '/agent/src/Capture/RefreshExport.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';

use WPrism\Canon;
use WPrism\CaptureCandidateBuilder;
use WPrism\CaptureSnapshotService;
use WPrism\CommandRefusalException;
use WPrism\Db;
use WPrism\OptionState;
use WPrism\OptionsCapture;
use WPrism\Policy;
use WPrism\RefreshExport;
use WPrism\RepositoryCompiler;
use WPrism\ScalarReferenceIntersection;
use WPrism\TableSchema;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array {
        return [];
    }
}

/** @return array<string,list<array<string,mixed>>> */
function projection_rows(FakeWpdb $db): array {
    $rows = [];
    foreach (array_keys(TableSchema::core_capture_required_columns()) as $property) {
        $rows[$db->$property] = $db->rows($db->$property);
    }
    foreach (['wp_wprism_map', 'wp_wprism_state', 'wp_wprism_kv'] as $table) {
        $rows[$table] = $db->rows($table);
    }
    ksort($rows, SORT_STRING);
    return $rows;
}

function projection_database(string $value = '41'): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset()->seedOptions(['home' => 'https://projection.example.test']);
    $db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions();
    foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
        $db->setColumns($db->$property, array_fill_keys($columns, 'longtext'))
            ->setTableEngine($db->$property, 'InnoDB');
    }
    $index = static fn(string $name, string $column, int $position = 1): array => [
        'Key_name' => $name, 'Non_unique' => 0, 'Seq_in_index' => $position,
        'Column_name' => $column, 'Sub_part' => null, 'Index_type' => 'BTREE',
    ];
    return $db->seedTable($db->options, [[
        'option_id' => 1, 'option_name' => 'projection_subject', 'option_value' => $value, 'autoload' => 'yes',
    ]])->seedTable($db->terms, [[
        'term_id' => 41, 'name' => 'Local category', 'slug' => 'local-category', 'term_group' => 0,
    ]])->seedTable($db->term_taxonomy, [[
        'term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category',
        'description' => '', 'parent' => 0, 'count' => 0,
    ]])->setColumns('wp_wprism_map', [
        'uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
    ])->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
        ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB')
        ->setColumns('wp_wprism_state', [
            'uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'char(64)',
        ])->setUniqueKey('wp_wprism_state', ['uuid'])
        ->setTableEngine('wp_wprism_state', 'InnoDB')
        ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'varchar(1024)'])
        ->setUniqueKey('wp_wprism_kv', ['k'])
        ->setTableEngine('wp_wprism_kv', 'InnoDB')
        ->setIndexes('wp_wprism_map', [
            $index('PRIMARY', 'uuid'), $index('PRIMARY', 'id_kind', 2),
            $index('kind_local', 'id_kind'), $index('kind_local', 'local_id', 2),
        ])->setIndexes('wp_wprism_state', [
            $index('PRIMARY', 'uuid'),
        ])->setIndexes('wp_wprism_kv', [
            $index('PRIMARY', 'k'),
        ]);
}

/** @return array<string,string> */
function projection_repository_bytes(string $directory): array {
    $out = [];
    $walk = static function (string $path) use (&$walk, &$out, $directory): void {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $entry = "$path/$name";
            $key = substr($entry, strlen($directory) + 1);
            if (is_dir($entry)) {
                $out[$key] = 'directory';
                $walk($entry);
            } else {
                $out[$key] = hash_file('sha256', $entry);
            }
        }
    };
    $walk($directory);
    ksort($out, SORT_STRING);
    return $out;
}

// A data-only, site-owned static manifest exercises the generic feature through
// normal Policy::load and compilation. It makes no shipped/plugin claim. The
// desired zero is valid without a canonical term; native category exclusion is
// intentional and must not turn that later live reference into false absence.
$repo = sys_get_temp_dir() . '/wprism_refresh_projection_' . bin2hex(random_bytes(6));
foreach (['adapters', 'state/options', 'media'] as $relative) {
    if (!mkdir("$repo/$relative", 0700, true) && !is_dir("$repo/$relative")) {
        throw new RuntimeException('cannot allocate refresh projection fixture');
    }
}
register_shutdown_function(static function () use ($repo): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $remove("$path/$name");
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    };
    $remove($repo);
});
$rule = [
    'class' => 'authored', 'ref' => 'term', 'ref_same_local_id_as' => ['tt'], 'ref_taxonomy' => 'category',
];
Canon::write_file("$repo/adapters/projection-fixture.json", Canon::encode([
    'name' => 'projection-fixture', 'spec_version' => 3,
    'engine_features' => [ScalarReferenceIntersection::FEATURE, 'spec-window/v1'],
    'option_autoload' => 'preserve', 'options' => ['projection_subject' => $rule],
    'taxonomies' => ['category' => ['class' => 'authored']],
]));
Canon::write_file("$repo/site.wprism.json", Canon::encode([
    'spec_version' => WPRISM_SPEC_VERSION,
    'manifests' => [['name' => 'projection-fixture', 'source' => 'site']],
    'policy' => ['post_types' => [], 'taxonomies' => [], 'scope' => ['taxonomy' => ['category' => ['class' => 'runtime']]]],
]));
Canon::write_file("$repo/state/options/core.json", Canon::encode(OptionState::document([
    'projection_subject' => OptionState::present(0, 'yes'),
])));
$policy = Policy::load($repo);
$compiled = RepositoryCompiler::compile($repo, $policy);
wprism_check_same([], $policy->taxonomies(), 'the actual loaded policy deliberately excludes the referenced native taxonomy');
wprism_check_same($rule + ['autoload' => 'preserve'], $policy->authored_options()['projection_subject'] ?? null,
    'the normal static manifest loader preserves the authored intersection despite the entity scope exclusion');

$beforeFiles = projection_repository_bytes($repo);
$db = projection_database('0');
$beforeRows = projection_rows($db);
$healthy = RefreshExport::run($repo);
wprism_check_same(RefreshExport::FORMAT, $healthy['format'] ?? null, 'the complete export command accepts a healthy durable zero');
$healthyDocument = Canon::decode($healthy['records']['options/core']['content']);
wprism_check_same(Canon::encode(OptionState::present(0, 'yes')), Canon::encode(OptionState::records($healthyDocument)['projection_subject'] ?? null),
    'the full wire record retains the present canonical zero, not an invented absence');
wprism_check_same([], $healthy['warnings'] ?? null, 'the healthy full export carries no hidden warning');
wprism_check_same($beforeRows, projection_rows($db), 'the healthy run-level export is a native and ledger observer');
wprism_check_same($beforeFiles, projection_repository_bytes($repo), 'the healthy export does not write repository files');
wprism_check_same(null, $db->activeTransactionIsolation(), 'the healthy export settles its read-only snapshot');

foreach (['export', 'explain', 'ordinary'] as $mode) {
    foreach ([false, true] as $force) {
        $db = projection_database();
        $beforeRows = projection_rows($db);
        $failure = null;
        $answer = null;
        try {
            $answer = match ($mode) {
                'export' => RefreshExport::run($repo, $force),
                'explain' => CaptureSnapshotService::snapshotReadOnly($repo, $force, $compiled, $policy),
                default => CaptureSnapshotService::snapshot($repo, $force, $compiled, $policy),
            };
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        $label = "$mode with force=" . (int) $force;
        wprism_check($failure instanceof CommandRefusalException && $failure->reasonCode === 'reference_intersection_failed',
            "$label refuses the physically valid but unmapped authored reference");
        wprism_check_same(null, $answer, "$label never publishes a false absent record");
        wprism_check_same($beforeRows, projection_rows($db), "$label leaves every source and ledger row unchanged");
        wprism_check_same($beforeFiles, projection_repository_bytes($repo), "$label leaves repository bytes and directory inventory unchanged");
        wprism_check_same(null, $db->activeTransactionIsolation(), "$label settles the database session idle");
        $queries = $db->queries();
        wprism_check_same(1, count(array_filter($queries, static fn(string $sql): bool => str_starts_with($sql, 'START TRANSACTION '))),
            "$label reaches the real snapshot rather than failing fixture preflight");
        wprism_check_same(1, count(array_filter($queries, static fn(string $sql): bool => $sql === 'ROLLBACK AND NO CHAIN NO RELEASE')),
            "$label rolls back the refused observation exactly once");
    }
}

foreach (['term', 'term_taxonomy'] as $kind) {
    $db = projection_database()->seedTable('wp_wprism_map', [[
        'uuid' => '11111111-1111-7111-8111-111111111111', 'entity_type' => 'term', 'id_kind' => $kind, 'local_id' => 41,
    ]]);
    $beforeRows = projection_rows($db);
    wprism_check_refuses(static fn() => RefreshExport::run($repo), 'reference_intersection_failed',
        'full export cannot hide a partial ' . $kind . ' coordinate either');
    wprism_check_same($beforeRows, projection_rows($db), 'partial-coordinate refusal preserves native and ledger rows');
    wprism_check_same(null, $db->activeTransactionIsolation(), 'partial-coordinate refusal settles the export snapshot');
}

// Neither generic strictness nor the old dynamic-desired flag confers the new
// purpose. The internal options-only edge must say what it is observing.
$db = projection_database();
$builder = new CaptureCandidateBuilder($repo, $policy);
wprism_check_refuses(static fn() => $builder->buildOptionsOnly(false, bindMissingDynamicDesired: true, strictReadOnly: true),
    'reference_intersection_failed', 'an options-only strict read also requires explicit lifecycle purpose');
$tokens = new Tokens('https://projection.example.test', 'https://projection.example.test/uploads');
$tokens->policy = $policy;
$reader = new OptionsCapture($policy, $tokens, static function (): void {}, static fn(): ?string => null, static fn(): bool => false);
foreach ([[true, true, true], [false, false, true], [false, true, false]] as [$mint, $strict, $bind]) {
    $db->resetLog();
    wprism_check_throws(static fn() => $reader->capture($mint, bindMissingDynamicDesired: $bind, strictReadOnly: $strict,
        lifecycleHandoffProjection: true), LogicException::class,
        'lifecycle projection rejects a write-capable, non-strict, or unbound invocation');
    wprism_check_same([], $db->queries(), 'an invalid projection purpose combination is rejected before any native read');
}

wprism_check_summary('refresh scalar-reference projection boundary');
