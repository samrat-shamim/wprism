<?php
/** First Apply must compare installer defaults before it can classify adoption. */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$runtime = $argv[1] ?? $root;
$scratch = sys_get_temp_dir() . '/wprism-plan-reference-' . bin2hex(random_bytes(8));
define('WP_CONTENT_DIR', $scratch . '/content');
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $runtime . '/agent/src/Capture/Capture.php';
require_once $runtime . '/agent/src/Apply/ApplyPlanBuilder.php';
require_once $runtime . '/agent/src/Apply/EntityAdopter.php';
require_once $runtime . '/agent/src/Apply/OptionsMaterializer.php';
require_once $runtime . '/agent/src/Repository/RepositoryAuthorization.php';

use WPrism\AdapterLibrary;
use WPrism\ApplyFieldMaterializer;
use WPrism\ApplyPlanBuilder;
use WPrism\ApplyPlanner;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\Capture;
use WPrism\CaptureCandidateBuilder;
use WPrism\Db;
use WPrism\DeleteGuardReferenceScanner;
use WPrism\EntityAdopter;
use WPrism\Ledger;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\ScalarReferenceIntersection;
use WPrism\TableSchema;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

function get_taxonomies(array $args = [], string $output = 'names'): array {
    return ['category'];
}

function get_taxonomy(string $name): object {
    return (object) ['name' => $name, 'object_type' => ['post'], 'hierarchical' => true];
}

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $remove($path . '/' . $name);
            }
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};
register_shutdown_function(static fn() => $remove($scratch));
mkdir(WP_CONTENT_DIR . '/themes/fixture', 0700, true);
file_put_contents(WP_CONTENT_DIR . '/themes/fixture/style.css', "/*\nTheme Name: Plan reference fixture\nVersion: 1.0.0\n*/\n");
$uuid = '11111111-1111-7111-8111-111111111111';
$other = '22222222-2222-7222-8222-222222222222';
$token = '{{term:' . $uuid . '}}';

// Real compiler, policy loader, native capture joins and full plan producer.
// The fake supplies native rows/schema, never a canned Capture or plan result.
$fixture = static function (array $changes = []) use ($scratch, $runtime, $uuid, $token): array {
    $repo = $scratch . '/repo-' . bin2hex(random_bytes(4));
    foreach (['adapters', 'state/terms/category', 'state/options'] as $relative) {
        mkdir($repo . '/' . $relative, 0700, true);
    }
    Canon::write_file($repo . '/site.wprism.json', Canon::encode([
        'spec_version' => WPRISM_SPEC_VERSION,
        'manifests' => [['name' => 'core'], ['name' => 'reference-fixture', 'source' => 'site']],
        'policy' => ['post_types' => [], 'taxonomies' => ['category']],
    ]));
    Canon::write_file($repo . '/adapters/reference-fixture.json', Canon::encode([
        'name' => 'reference-fixture', 'spec_version' => 3,
        'engine_features' => [ScalarReferenceIntersection::FEATURE, 'spec-window/v1'],
        'option_autoload' => 'preserve', 'options' => [
            'reference_subject' => [
                'class' => 'authored', 'ref' => 'term',
                'ref_same_local_id_as' => ['tt'], 'ref_taxonomy' => 'category',
            ],
            'reference_preceding' => ['class' => 'authored'],
        ],
    ]));
    $desired = array_replace([
        'uuid' => $uuid, 'taxonomy' => 'category', 'slug' => 'local-category', 'name' => 'Local category',
        'parent' => null, 'description' => '', 'meta' => (object) [], 'relationships' => (object) [],
    ], $changes['desired'] ?? []);
    $terms = [$desired, ...($changes['extra_desired'] ?? [])];
    foreach ($terms as $term) {
        Canon::write_file($repo . '/state/terms/category/' . $term['uuid'] . '--' . $term['slug'] . '.json', Canon::encode($term));
    }
    $policy = Policy::load($repo, adapterLibrary: AdapterLibrary::fromSourceTree($runtime));
    $records = [];
    foreach ($policy->authored_options() as $name => $rule) {
        $records[$name] = OptionState::absent();
    }
    foreach (['active_plugins' => [], 'stylesheet' => 'fixture', 'template' => 'fixture',
        'reference_subject' => $changes['value'] ?? $token] as $name => $value) {
        $records[$name] = OptionState::present($value, 'yes');
    }
    if (!empty($changes['preceding_desired'])) {
        $records['reference_preceding'] = OptionState::present('written', 'yes');
    }
    Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
    $compiled = RepositoryCompiler::compile($repo, $policy);
    WpStore::reset()->seedOptions(['home' => 'https://reference.example.test']);
    $db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
    foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
        $db->setColumns($db->$property, array_fill_keys($columns, 'longtext'))->setTableEngine($db->$property, 'InnoDB');
    }
    $db->setColumns('wp_wprism_map', [
        'uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
    ])->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB')
        ->setColumns('wp_wprism_state', ['uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'char(64)'])
        ->setUniqueKey('wp_wprism_state', ['uuid'])->setTableEngine('wp_wprism_state', 'InnoDB')
        ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'varchar(1024)'])
        ->setUniqueKey('wp_wprism_kv', ['k'])->setTableEngine('wp_wprism_kv', 'InnoDB')
        ->seedTable('wp_terms', $changes['terms'] ?? [[
            'term_id' => 41, 'name' => 'Local category', 'slug' => 'local-category', 'term_group' => 0,
        ]])->seedTable('wp_term_taxonomy', $changes['tt'] ?? [[
            'term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category', 'parent' => 0, 'description' => '', 'count' => 0,
        ]])->seedTable('wp_wprism_map', $changes['maps'] ?? [])
        ->seedTable('wp_termmeta', $changes['meta'] ?? [])
        ->seedTable('wp_options', [
            ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => 'a:0:{}', 'autoload' => 'yes'],
            ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture', 'autoload' => 'yes'],
            ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture', 'autoload' => 'yes'],
            ['option_id' => 4, 'option_name' => 'reference_subject', 'option_value' => (string) ($changes['local'] ?? 41), 'autoload' => 'yes'],
        ])->setUniqueKey('wp_options', ['option_name'])->setAutoIncrement('wp_options', 5, 'option_id')
        ->setAutoIncrement('wp_termmeta', 1, 'meta_id');
    foreach (['wp_options' => ['option_name', true], 'wp_termmeta' => ['term_id', false],
        'wp_term_taxonomy' => ['term_id', false]] as $table => [$column, $unique]) {
        $db->setIndexes($table, [[
            'Key_name' => $column, 'Column_name' => $column, 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => $unique ? 0 : 1, 'Index_type' => 'BTREE',
        ]]);
    }
    foreach (['wp_wprism_map' => [['uuid', 'id_kind'], ['id_kind', 'local_id']],
        'wp_wprism_state' => [['uuid']], 'wp_wprism_kv' => [['k']]] as $table => $keys) {
        $indexes = [];
        foreach ($keys as $index => $columns) {
            foreach ($columns as $position => $column) {
                $indexes[] = ['Key_name' => 'unique_' . $index, 'Column_name' => $column,
                    'Seq_in_index' => $position + 1, 'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE'];
            }
        }
        $db->setIndexes($table, $indexes);
    }
    $planner = new ApplyPlanner($policy, [], Ledger::id_for(...), Ledger::id_for(...));
    $builder = new ApplyPlanBuilder($repo, $policy, $planner, new DeleteGuardReferenceScanner($policy), [], null,
        static fn(): array => ['regen_pending' => [], 'regen_context' => [], 'warnings' => []],
        static fn(): array => ['env_missing' => [], 'warnings' => []]);
    return compact('repo', 'policy', 'compiled', 'db', 'planner', 'builder', 'desired');
};
$native = static fn(FakeWpdb $db): array => array_map($db->rows(...), [
    'wp_terms', 'wp_term_taxonomy', 'wp_termmeta', 'wp_wprism_map', 'wp_wprism_state', 'wp_options',
]);
$plan = static function (array $f, array $opts, string $label): ?array {
    try {
        $result = $f['builder']->build($opts, $f['compiled'], false, false);
        wprism_check(true, "$label reaches the actual completed plan");
        wprism_check_same([], $result['warnings'], "$label is warning-free");
        return $result['plan'];
    } catch (Throwable $failure) {
        wprism_check(false, "$label unexpectedly refused: " . $failure->getMessage());
        return null;
    }
};

// The zero control is also green against the preceding runtime: the fixture
// has not accidentally made every capture, compile or plan attempt fail.
$f = $fixture(['value' => 0, 'local' => 0]);
$zero = $plan($f, ['adopt_by_slug' => 'terms'], 'durable zero healthy control');
wprism_check_same([$uuid], array_column($zero['adopt'] ?? [], 'uuid'), 'zero does not require invented reference identity');
foreach ([[], ['adopt_by_slug' => 'terms'], ['adopt_by_slug' => 'terms', 'force_unresolved_refs' => true],
    ['adopt_by_slug' => 'terms', 'rebind_from_home' => 'https://source.example.test',
        'rebind_from_uploads' => 'https://source.example.test/uploads']] as $opts) {
    $f = $fixture();
    $before = $native($f['db']);
    $result = $plan($f, $opts, 'installer default ' . json_encode($opts, JSON_THROW_ON_ERROR));
    $bucket = isset($opts['adopt_by_slug']) ? 'adopt' : 'collision';
    wprism_check_same([$uuid], array_column($result[$bucket] ?? [], 'uuid'), "only the explicit request selects $bucket");
    wprism_check_same([], $result[$bucket === 'adopt' ? 'collision' : 'adopt'] ?? null, 'comparison grants no additional adoption permission');
    wprism_check_same($before, $native($f['db']), 'planning does not install a sidecar, ledger map or native option value');
    if ($bucket === 'adopt') {
        $owner = array_column($result['update'] ?? [], null, 'uuid')['options/core'] ?? [];
        wprism_check_same([$uuid], $owner['reference_rebind_targets'] ?? null, 'existing reverse-reference machinery schedules the reference owner after adoption');
    }
}

$mapping = static fn(string $id, string $kind, int $local): array => [
    'uuid' => $id, 'entity_type' => 'term', 'id_kind' => $kind, 'local_id' => $local,
];
$base = $fixture()['desired'];
wprism_check_throws(static fn() => $fixture(['extra_desired' => [array_replace($base, ['uuid' => $other])]]),
    WPrism\RepositoryCompilationException::class, 'duplicate desired natural identities refuse in the real compiler before target contact');
$badCases = [
    'no desired natural key' => ['desired' => ['slug' => 'different-category']],
    'wrong native parent' => ['tt' => [[
        'term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category', 'parent' => 9, 'description' => '', 'count' => 0,
    ]]],
    'wrong physical taxonomy' => ['tt' => [[
        'term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'post_tag', 'parent' => 0, 'description' => '', 'count' => 0,
    ]]],
    'mismatched physical coordinates' => ['tt' => [[
        'term_taxonomy_id' => 71, 'term_id' => 41, 'taxonomy' => 'category', 'parent' => 0, 'description' => '', 'count' => 0,
    ]]],
    'partial primary map' => ['maps' => [$mapping($uuid, 'term', 41)]],
    'partial alternate map' => ['maps' => [$mapping($uuid, 'term_taxonomy', 41)]],
    'contradictory map' => ['maps' => [$mapping($uuid, 'term', 41), $mapping($other, 'term_taxonomy', 41)]],
    'canonical identity already live elsewhere' => [
        'maps' => [$mapping($uuid, 'term', 42), $mapping($uuid, 'term_taxonomy', 42)],
        'meta' => [['meta_id' => 1, 'term_id' => 42, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid]],
        'terms' => [
            ['term_id' => 41, 'name' => 'Local category', 'slug' => 'local-category', 'term_group' => 0],
            ['term_id' => 42, 'name' => 'Other category', 'slug' => 'other-category', 'term_group' => 0],
        ], 'tt' => [
            ['term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category', 'parent' => 0, 'description' => '', 'count' => 0],
            ['term_taxonomy_id' => 42, 'term_id' => 42, 'taxonomy' => 'category', 'parent' => 0, 'description' => '', 'count' => 0],
        ],
    ],
    'ambiguous native key' => [
        'terms' => [
            ['term_id' => 41, 'name' => 'Local category', 'slug' => 'local-category', 'term_group' => 0],
            ['term_id' => 42, 'name' => 'Other category', 'slug' => 'local-category', 'term_group' => 0],
        ], 'tt' => [
            ['term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category', 'parent' => 0, 'description' => '', 'count' => 0],
            ['term_taxonomy_id' => 42, 'term_id' => 42, 'taxonomy' => 'category', 'parent' => 0, 'description' => '', 'count' => 0],
        ],
    ],
];
foreach ($badCases as $label => $changes) {
    foreach ([false, true] as $force) {
        $f = $fixture($changes);
        $before = $native($f['db']);
        wprism_check_throws(static fn() => $f['builder']->build([
            'adopt_by_slug' => 'terms', 'force_unresolved_refs' => $force,
        ], $f['compiled'], false, false), RuntimeException::class, "$label refuses through the full planner; force=" . (int) $force);
        wprism_check_same($before, $native($f['db']), "$label cannot become durable adoption");
        wprism_check_same(null, $f['db']->activeTransactionIsolation(), "$label returns the database transaction idle");
    }
}

$f = $fixture();
$hit = false;
$f['db']->onQuery(static function (string $sql, string $method) use (&$hit): ?string {
    if ($method === 'get_col' && str_contains($sql, 't.slug =')) {
        $hit = true;
        return 'injected natural-key read failure';
    }
    return null;
});
wprism_check_throws(static fn() => $f['builder']->build(['adopt_by_slug' => 'terms'], $f['compiled'], false, false),
    RuntimeException::class, 'failed native natural-key query cannot mean no collision');
wprism_check($hit, 'the failed read is reached inside the actual option comparison');
$f['db']->onQuery(null);

// This healthy/read-failure pair reaches collision classification directly,
// including on the preceding runtime whose option capture fails earlier.
$f = $fixture();
$cache = [];
wprism_check_same(41, $f['planner']->find_collision($f['compiled']->tree()[$uuid], $f['compiled']->tree(), $cache),
    'native natural-key lookup has a positive control independent of reference capture');
$cache = [];
$f['db']->failNextQuery('injected collision observation failure', 'FROM wp_terms t');
wprism_check_throws(static function () use ($f, $uuid, &$cache): void {
    $f['planner']->find_collision($f['compiled']->tree()[$uuid], $f['compiled']->tree(), $cache);
}, RuntimeException::class, 'a failed natural-key read cannot authorize creation by reporting no collision');
wprism_check_same([], $cache, 'a failed observation is not memoized as a missing native entity');
foreach (['0', '-1', '041', '41trailing', (string) PHP_INT_MAX . '0'] as $malformed) {
    $f = $fixture();
    $f['db']->seedTable('wp_terms', [['term_id' => $malformed, 'slug' => 'local-category']])
        ->seedTable('wp_term_taxonomy', [['term_id' => $malformed, 'taxonomy' => 'category', 'parent' => 0]]);
    $cache = [];
    wprism_check_throws(static function () use ($f, $uuid, &$cache): void {
        $f['planner']->find_collision($f['compiled']->tree()[$uuid], $f['compiled']->tree(), $cache);
    }, RuntimeException::class, 'malformed natural-key result is not integer-coerced into adoption authority');
}
$f = $fixture();
$f['db']->seedTable('wp_term_taxonomy', [
    ['term_id' => 41, 'taxonomy' => 'category', 'parent' => 0],
    ['term_id' => 41, 'taxonomy' => 'category', 'parent' => 0],
    ['term_id' => 41, 'taxonomy' => 'category', 'parent' => 0],
]);
$cache = [];
wprism_check_throws(static function () use ($f, $uuid, &$cache): void {
    $f['planner']->find_collision($f['compiled']->tree()[$uuid], $f['compiled']->tree(), $cache);
}, RuntimeException::class, 'duplicate physical join rows are not deduplicated into a false unique adoption witness');

// Hierarchy is part of the natural key; the parent can itself be awaiting
// adoption. A UUID/slug-only shortcut would miss this real planner recursion.
$parented = $fixture(['desired' => ['parent' => $other],
    'extra_desired' => [array_replace($base, ['uuid' => $other, 'slug' => 'parent-category'])],
    'terms' => [
        ['term_id' => 40, 'name' => 'Local category', 'slug' => 'parent-category', 'term_group' => 0],
        ['term_id' => 41, 'name' => 'Local category', 'slug' => 'local-category', 'term_group' => 0],
    ], 'tt' => [
        ['term_taxonomy_id' => 40, 'term_id' => 40, 'taxonomy' => 'category', 'parent' => 0, 'description' => '', 'count' => 0],
        ['term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category', 'parent' => 40, 'description' => '', 'count' => 0],
    ],
]);
$parentPlan = $plan($parented, ['adopt_by_slug' => 'terms'], 'parent-scoped installer default');
$adoptions = array_column($parentPlan['adopt'] ?? [], 'env_id', 'uuid');
wprism_check_same(41, $adoptions[$uuid] ?? null, 'child comparison binds the correct local parent, not only its slug');
wprism_check_same(40, $adoptions[$other] ?? null, 'the parent still needs its own explicit adoption row');

$extra = [];
for ($i = 0; $i < 1100; $i++) {
    $extra[] = array_replace($base, ['uuid' => sprintf('33333333-3333-7333-8333-%012d', $i), 'slug' => 'unrelated-' . $i]);
}
$f = $fixture(['extra_desired' => $extra]);
$comparisonQueries = [];
$f['db']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$comparisonQueries): null {
    if ($method === 'get_col' && str_contains($sql, 't.slug =') && $db->activeTransactionIsolation() !== null) {
        $comparisonQueries[] = $sql;
    }
    return null;
});
$scaled = $plan($f, ['adopt_by_slug' => 'terms'], '1100 unrelated desired terms');
wprism_check_same([$uuid], array_column($scaled['adopt'] ?? [], 'uuid'), 'large desired state retains the same precise adoption');
wprism_check_same(1, count($comparisonQueries), 'one reference comparison probes only its indexed natural key inside the bounded snapshot work unit');
wprism_check(str_ends_with($comparisonQueries[0] ?? '', 'LIMIT 2'), 'uniqueness observation requests at most the one-match plus ambiguity witness');
$f['db']->onQuery(null);

// A previous snapshot's collision cache cannot decide a later binding's
// observation. Exercise two independently produced closures against real rows.
$f = $fixture();
if (method_exists($f['planner'], 'unmapped_term_reference_observer')) {
    $observer = $f['planner']->unmapped_term_reference_observer($f['compiled']->tree());
    $nativeTerm = (object) ['term_id' => '41', 'taxonomy' => 'category', 'slug' => 'local-category'];
    wprism_check_same($uuid, $observer($nativeTerm), 'the comparison observer starts from a real matching native natural key');
    $f['db']->query("UPDATE wp_terms SET slug = 'now-different' WHERE term_id = 41");
    $fresh = $f['planner']->unmapped_term_reference_observer($f['compiled']->tree());
    wprism_check_same(null, $fresh($nativeTerm), 'a fresh snapshot does not reuse the prior natural-key collision cache');
    foreach ([['term_id' => true], ['term_id' => '041'], ['term_id' => '41tail'], ['term_id' => 0],
        ['taxonomy' => 'post_tag'], ['slug' => null]] as $override) {
        wprism_check_same(null, $fresh((object) array_replace((array) $nativeTerm, $override)), 'malformed or excluded native coordinates cannot gain a desired identity');
    }
    // Purpose misuse refuses before the candidate can read any native table.
    $f = $fixture();
    $never = static function (): never { throw new LogicException('comparison callback must not run for this purpose'); };
    $candidate = new CaptureCandidateBuilder($f['repo'], $f['policy'], null, $f['compiled']->tree(), $never);
    foreach (['mint', 'strict', 'options-only'] as $purpose) {
        $before = $f['db']->queries();
        $run = match ($purpose) {
            'mint' => static fn() => $candidate->build(true),
            'strict' => static fn() => $candidate->build(false, strictReadOnly: true),
            'options-only' => static fn() => $candidate->buildOptionsOnly(false),
        };
        wprism_check_throws($run, LogicException::class, "planned identity is not admitted for $purpose capture");
        wprism_check_same($before, $f['db']->queries(), "$purpose purpose misuse refuses before any native read");
    }
}

foreach (['snapshot', 'strict plan', 'export'] as $purpose) {
    $f = $fixture();
    $before = $native($f['db']);
    $observe = match ($purpose) {
        'snapshot' => static fn() => Capture::snapshot($f['repo'], false, $f['compiled'], $f['policy']),
        'strict plan' => static fn() => $f['builder']->build(['adopt_by_slug' => 'terms'], $f['compiled'], true, false),
        'export' => static fn() => WPrism\CaptureSnapshotService::buildReadOnlyExport($f['repo'], $f['policy'], null, []),
    };
    wprism_check_throws($observe, RuntimeException::class, "$purpose still requires durable identity, not a planned natural-key witness");
    wprism_check_same($before, $native($f['db']), "$purpose refusal makes no durable changes");
}
$f = $fixture(['maps' => [$mapping($uuid, 'term', 41), $mapping($uuid, 'term_taxonomy', 41)],
    'meta' => [['meta_id' => 1, 'term_id' => 41, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid]]]);
$before = $native($f['db']);
$strict = $f['builder']->build([], $f['compiled'], true, false);
wprism_check_same([], $strict['plan']['adopt'], 'healthy durable-identity control reaches the complete strict plan');
wprism_check_same($before, $native($f['db']), 'healthy strict observation performs no durable writes');

// A real authored transaction consumes only the actual plan-approved row.
// A later reference refusal must undo both earlier data and new identities.
foreach (['none', 'before adoption', 'after adoption'] as $breakTuple) {
    $f = $fixture(['preceding_desired' => true]);
    $result = $plan($f, ['adopt_by_slug' => 'terms'], 'transaction prerequisite');
    if ($result === null) {
        continue;
    }
    if ($breakTuple === 'before adoption') {
        $f['db']->query("UPDATE wp_term_taxonomy SET taxonomy = 'post_tag' WHERE term_id = 41");
    }
    $before = $native($f['db']);
    $tokens = new Tokens('https://reference.example.test', 'https://reference.example.test/uploads');
    $tokens->policy = $f['policy'];
    $fields = new ApplyFieldMaterializer($f['policy'], $tokens);
    $writer = new OptionsMaterializer($f['policy'], $tokens, $fields);
    $adopter = new EntityAdopter($f['policy'], [], $fields);
    $warnings = [];
    $caught = null;
    Db::start_repeatable_read('planned reference adoption regression', new NativeDatabaseProfile(
        ['wp_terms'], ['wp_options', 'wp_termmeta', 'wp_term_taxonomy', 'wp_wprism_map']
    ));
    $fields->begin_authored_transaction();
    $writer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $writer->apply_options(OptionState::document([
            'reference_preceding' => OptionState::present('written', 'yes'),
        ]), false, $warnings);
        wprism_check(count($f['db']->rows('wp_options')) === 5, 'a real authored write precedes identity adoption');
        $adopter->adopt($result['adopt'][0], $f['compiled']->tree()[$uuid], $warnings);
        wprism_check_same(2, count($f['db']->rows('wp_wprism_map')), 'the real transaction has installed both identity coordinates before option materialization');
        if ($breakTuple === 'after adoption') {
            Db::update('wp_term_taxonomy', ['term_taxonomy_id' => 71], ['term_id' => 41], null, null,
                'inject physical tuple divergence after adoption');
        }
        $writer->apply_options(OptionState::document([
            'reference_subject' => OptionState::present($token, 'yes'),
        ]), false, $warnings);
        Db::commit('planned reference adoption commit');
        $writer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
    } catch (Throwable $failure) {
        $caught = $failure;
        $writer->rollback_authored_transaction();
        Db::rollback('planned reference adoption rollback');
    } finally {
        $writer->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
    wprism_check_same($breakTuple !== 'none', $caught !== null, 'transaction settlement agrees with the physical tuple: ' . ($caught?->getMessage() ?? 'committed'));
    wprism_check_same(null, $f['db']->activeTransactionIsolation(), 'actual adoption transaction returns idle');
    if ($breakTuple !== 'none') {
        wprism_check_same($before, $native($f['db']), 'refused adoption rolls back the preceding option and all identity writes');
        if ($breakTuple === 'after adoption') {
            wprism_check($caught instanceof WPrism\CommandRefusalException && $caught->reasonCode === 'reference_intersection_failed',
                'the post-adoption refusal comes from actual option intersection materialization, not an earlier fixture failure');
        }
    } elseif ($caught === null) {
        wprism_check_same(41, Ledger::id_for($uuid, 'term'), 'actual adopter installs the primary coordinate');
        wprism_check_same(41, Ledger::id_for($uuid, 'term_taxonomy'), 'actual adopter installs the alternate coordinate');
        wprism_check_same($uuid, $f['db']->rows('wp_termmeta')[0]['meta_value'], 'actual adopter owns the sole durable identity sidecar');
        $observed = Capture::snapshot($f['repo'], false, $f['compiled'], $f['policy']);
        $options = Canon::decode($observed['options/core']['content']);
        wprism_check_same($token, OptionState::values($options)['reference_subject'], 'ordinary capture agrees without any planned-reference observer after adoption');
        // Retain actual observed hashes as ordinary apply settlement does.
        foreach ($observed as $id => $entity) {
            Ledger::set_state_hash((string) $id, $entity['type'], $entity['hash']);
        }
        $repeat = $plan($f, ['adopt_by_slug' => 'terms'], 'post-adoption repeat plan');
        wprism_check_same([], $repeat['adopt'] ?? null, 'repeat plan has no identity adoption left');
        wprism_check_same([], $repeat['collision'] ?? null, 'repeat plan has no installer collision left');
        wprism_check_same([], $repeat['update'] ?? null, 'repeat plan has no reference or authored option changes left');
        wprism_check_same([], $repeat['create'] ?? null, 'repeat plan has no authored creates left');
    }
}

wprism_check_summary('first-apply reference adoption');
