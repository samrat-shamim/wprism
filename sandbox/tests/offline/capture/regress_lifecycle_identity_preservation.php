<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$runtime = $argv[1] ?? $root;
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $runtime . '/sandbox/tests/lib/FilesystemTreeEvidence.php';
require_once $runtime . '/agent/src/Capture/CaptureSnapshotService.php';
require_once $runtime . '/agent/src/Repository/RepositoryAuthorization.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\CanonicalLedgerMapGuard;
use WPrism\CaptureSnapshotService;
use WPrism\CaptureTransaction;
use WPrism\CommandRefusalException;
use WPrism\DatabaseWorkAuthority;
use WPrism\Ledger;
use WPrism\LifecycleReferenceView;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\TableSchema;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\WpStore;

function get_taxonomies(array $args = [], string $output = 'names'): array {
    return ['category'];
}

function get_taxonomy(string $name): object {
    return (object) ['name' => $name, 'object_type' => ['post'], 'hierarchical' => true];
}

$scratch = sys_get_temp_dir() . '/wprism-lifecycle-identity-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            $remove($entry->getPathname());
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};
register_shutdown_function(static fn() => $remove($scratch));
$termUuid = '20000000-0000-4000-8000-000000000002';
$postUuid = '30000000-0000-4000-8000-000000000003';
$maps = [
    ['uuid' => $termUuid, 'entity_type' => 'term', 'id_kind' => 'term', 'local_id' => 41],
    ['uuid' => $termUuid, 'entity_type' => 'term', 'id_kind' => 'term_taxonomy', 'local_id' => 42],
    ['uuid' => $postUuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 71],
];
$mutationStatements = static fn(array $queries): array => array_values(array_filter($queries,
    static fn(string $sql): bool => preg_match('/\A\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql) === 1));
$trees = static fn(string $repo): array => array_map(
    static fn(string $boundary): array => FilesystemTreeEvidence::capture($repo, $boundary),
    ['state', 'media', 'site.wprism.json', 'adapters']
);
$native = static function (FakeWpdb $db): array {
    $rows = [];
    foreach ($db->get_col('SHOW TABLES') as $table) {
        $rows[$table] = $db->rows($table);
    }
    ksort($rows, SORT_STRING);
    return $rows;
};
$guard = static function (array $fixture): string {
    try {
        CanonicalLedgerMapGuard::assert_pre_prune($fixture['policy'], $fixture['compiled']);
        return 'accepted';
    } catch (CommandRefusalException $failure) {
        return $failure->reasonCode;
    }
};

// The narrow observer runs the real compiler, policy, ledger and capture
// transaction. Shared row-backed DELETE execution is essential: a prune no-op
// used to make this boundary appear to preserve missing canonical identities.
$fixture = static function (string $mode) use ($scratch, $runtime, $termUuid, $postUuid, $maps, $mutationStatements): array {
    $repo = $scratch . '/repo-' . bin2hex(random_bytes(4));
    foreach (['state/terms/category', 'state/options', 'media', 'adapters'] as $directory) {
        mkdir($repo . '/' . $directory, 0700, true);
    }
    Canon::write_file($repo . '/site.wprism.json', Canon::encode([
        'spec_version' => WPRISM_SPEC_VERSION,
        'manifests' => ['core', ['name' => 'lifecycle-reference-fixture', 'source' => 'site']],
        'policy' => ['post_types' => [], 'taxonomies' => ['category']],
    ]));
    Canon::write_file($repo . '/adapters/lifecycle-reference-fixture.json', Canon::encode([
        'name' => 'lifecycle-reference-fixture', 'spec_version' => WPRISM_SPEC_VERSION,
        'option_autoload' => 'preserve', 'options' => [
            'lifecycle_tt_ref' => ['class' => 'authored', 'ref' => 'tt'],
            'lifecycle_term_refs' => ['class' => 'authored', 'ref' => 'term[]'],
        ],
    ]));
    Canon::write_file($repo . '/state/terms/category/' . $termUuid . '--category.json', Canon::encode([
        'uuid' => $termUuid, 'taxonomy' => 'category', 'slug' => 'category', 'name' => 'Category',
        'parent' => null, 'description' => '', 'meta' => (object) [], 'relationships' => (object) [],
    ]));
    $media = "retained\0\xffmedia";
    file_put_contents($repo . '/media/' . hash('sha256', $media) . '.bin', $media);
    $policy = Policy::load($repo, adapterLibrary: AdapterLibrary::fromSourceTree($runtime));
    $options = [];
    foreach ($policy->authored_options() as $name => $rule) {
        $options[$name] = OptionState::absent();
    }
    foreach (['active_plugins', 'stylesheet', 'template'] as $name) {
        $options[$name] = OptionState::absent();
    }
    Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($options)));
    $compiled = RepositoryCompiler::compile($repo, $policy);
    WpStore::reset()->seedOptions(['home' => 'https://lifecycle.example.test']);
    $db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
    foreach ($db->tables() as $table) {
        $db->seedTable($table, [])->setTableEngine($table, 'InnoDB');
    }
    foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
        $db->setColumns($db->$property, array_fill_keys($columns, 'longtext'));
    }
    $offset = count($db->queries());
    Ledger::ensure();
    $ensure = $mutationStatements(array_slice($db->queries(), $offset));
    foreach ($ensure as $sql) {
        if (preg_match('/\ACREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $match) !== 1) {
            throw new RuntimeException('fixture expected idempotent ledger schema statements');
        }
        $db->setTableEngine($match[1], 'InnoDB');
    }
    $db->setColumns('wp_wprism_map', [
        'uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
    ]);
    $db->seedTable('wp_wprism_map', $mode === 'fresh' ? [] : $maps);
    if (in_array($mode, ['healthy', 'term-only', 'rebound'], true)) {
        $db->seedTable('wp_terms', [['term_id' => 41, 'name' => 'Category', 'slug' => 'category', 'term_group' => 0]])
            ->seedTable('wp_termmeta', [['meta_id' => 1, 'term_id' => 41, 'meta_key' => '_wprism_uuid',
                'meta_value' => $mode === 'rebound' ? $postUuid : $termUuid]]);
    }
    if (in_array($mode, ['healthy', 'tt-only', 'rebound'], true)) {
        $db->seedTable('wp_term_taxonomy', [['term_taxonomy_id' => 42, 'term_id' => 41, 'taxonomy' => 'category',
            'parent' => 0, 'description' => '', 'count' => 0]]);
    }
    if ($mode === 'healthy') {
        $db->seedTable('wp_posts', [['ID' => 71, 'post_type' => 'page', 'post_status' => 'publish']]);
    }
    $db->seedTable('wp_options', [
        ['option_id' => 1, 'option_name' => 'default_category', 'option_value' => '41', 'autoload' => 'yes'],
        ['option_id' => 2, 'option_name' => 'page_on_front', 'option_value' => '71', 'autoload' => 'yes'],
        ['option_id' => 3, 'option_name' => 'lifecycle_tt_ref', 'option_value' => '42', 'autoload' => 'yes'],
        ['option_id' => 4, 'option_name' => 'lifecycle_term_refs', 'option_value' => serialize([41, 99, 0]), 'autoload' => 'yes'],
    ]);
    return compact('repo', 'policy', 'compiled', 'db', 'ensure');
};

foreach (['healthy', 'missing', 'term-only', 'tt-only', 'rebound', 'fresh'] as $mode) {
    $f = $fixture($mode);
    $expectedGuard = in_array($mode, ['healthy', 'fresh'], true) ? 'accepted' : 'canonical_identity_recovery_required';
    wprism_check_same($expectedGuard, $guard($f), "$mode independently establishes the canonical guard precondition");
    $beforeRows = $native($f['db']);
    $beforeTrees = $trees($f['repo']);
    $offset = count($f['db']->queries());
    try {
        $snapshot = CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
        $record = $snapshot['options/core'];
        $document = json_decode($record['content'], true, 64, JSON_THROW_ON_ERROR);
        wprism_check_same(hash('sha256', $record['content']), $record['hash'], "$mode returns a genuine canonical options hash");
        $values = OptionState::values($document);
        $hasTerm = in_array($mode, ['healthy', 'term-only', 'rebound'], true);
        $hasTt = in_array($mode, ['healthy', 'tt-only', 'rebound'], true);
        wprism_check_same($hasTerm ? '{{term:' . $termUuid . '}}' : null, $values['default_category'] ?? null,
            "$mode projects only physically present term references");
        wprism_check_same($hasTt ? '{{tt:' . $termUuid . '}}' : null, $values['lifecycle_tt_ref'] ?? null,
            "$mode preserves the independent term-taxonomy presence semantics");
        wprism_check_same($mode === 'healthy' ? '{{post:' . $postUuid . '}}' : null, $values['page_on_front'] ?? null,
            "$mode projects only physically present post references");
        wprism_check_same($hasTerm ? ['{{term:' . $termUuid . '}}'] : [], $values['lifecycle_term_refs'] ?? null,
            "$mode drops dangling list members without manufacturing durable identity");
    } catch (Throwable $failure) {
        wprism_check(false, "$mode options-only observation failed: " . $failure->getMessage());
    }
    wprism_check_same($beforeRows, $native($f['db']), "$mode options-only observation preserves every native table and identity tuple");
    wprism_check_same($beforeTrees, $trees($f['repo']), "$mode options-only observation preserves complete state/media/policy/adapter bytes");
    wprism_check_same($f['ensure'], $mutationStatements(array_slice($f['db']->queries(), $offset)),
        "$mode core-only observation performs only established ledger schema ensures, no DML");
    wprism_check_same($expectedGuard, $guard($f), "$mode retains the exact canonical identity gate after lifecycle observation");
    if ($expectedGuard !== 'accepted') {
        try {
            CaptureSnapshotService::snapshot($f['repo'], false, $f['compiled'], $f['policy']);
            wprism_check(false, "$mode subsequent full Plan observation must refuse missing or rebound backing");
        } catch (CommandRefusalException $failure) {
            wprism_check_same($expectedGuard, $failure->reasonCode, "$mode subsequent full Plan observation reaches the exact canonical guard");
        } catch (Throwable $failure) {
            wprism_check(false, "$mode subsequent full Plan observation reached an unrelated failure: " . $failure->getMessage());
        }
        wprism_check_same($beforeRows, $native($f['db']), "$mode full Plan refusal also preserves all native rows and map history");
        wprism_check_same($beforeTrees, $trees($f['repo']), "$mode full Plan refusal also preserves complete repository bytes");
    }
    if ($mode !== 'fresh') {
        wprism_check_same($termUuid, Ledger::uuid_for(41, 'term'), "$mode raw ledger lookup still exposes retained history");
        wprism_check_same('{{term:' . $termUuid . '}}', (new Tokens())->id_to_token(41, 'term'),
            "$mode ordinary token lookup semantics are not globally changed");
    }
}

// Failure after the old pre-transaction prune must not erase evidence either.
// This read is needed by both runtimes, so the old-runtime replay proves the
// preservation defect independently of the new presence lookup SQL.
$f = $fixture('missing');
$beforeRows = $native($f['db']);
$beforeTrees = $trees($f['repo']);
$f['db']->failNextQuery('fixture lifecycle option read failure', 'FROM wp_options');
try {
    CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
    wprism_check(false, 'failed options read must refuse the lifecycle observation');
} catch (Throwable $failure) {
    wprism_check(str_contains($failure->getMessage(), 'failed') || str_contains($failure->getMessage(), 'failure'),
        'actual lifecycle options read failure remains loud');
}
wprism_check_same($beforeRows, $native($f['db']), 'failed lifecycle observation preserves all native rows and retained mappings');
wprism_check_same($beforeTrees, $trees($f['repo']), 'failed lifecycle observation preserves complete repository bytes');
wprism_check_same('canonical_identity_recovery_required', $guard($f), 'failed lifecycle observation cannot turn stale identity into a fresh-target authorization');

foreach (['posts', 'terms', 'term_taxonomy', 'map'] as $fault) {
    $f = $fixture('healthy');
    $beforeRows = $native($f['db']);
    $beforeTrees = $trees($f['repo']);
    $query = $fault === 'map'
        ? "SELECT uuid FROM wp_wprism_map WHERE id_kind = 'term' AND local_id = 41"
        : 'SELECT 1 FROM `wp_' . $fault . '` WHERE';
    $f['db']->failNextQuery('fixture reference observation failure', $query);
    try {
        CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
        wprism_check(false, "$fault read failure must not be projected as a dangling reference");
    } catch (RuntimeException $failure) {
        wprism_check_same(mysqli_sql_exception::class, get_class($failure),
            "$fault read failure uses the actual strict transaction transport");
        wprism_check_same('fixture reference observation failure', $failure->getMessage(),
            "$fault lookup preserves its exact read failure instead of treating it as absence");
    }
    wprism_check_same($beforeRows, $native($f['db']), "$fault lookup failure preserves every native row and mapping");
    wprism_check_same($beforeTrees, $trees($f['repo']), "$fault lookup failure preserves complete repository bytes");
    $snapshot = CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
    $values = OptionState::values(json_decode($snapshot['options/core']['content'], true, 64, JSON_THROW_ON_ERROR));
    wprism_check_same('{{term:' . $termUuid . '}}', $values['default_category'] ?? null,
        "$fault fresh observation recovers the healthy reference, not a cached absence");
    wprism_check_same($beforeRows, $native($f['db']), "$fault retry leaves native data and history untouched");
}

$typedUuid = '40000000-0000-4000-8000-000000000004';
$neighborUuid = '50000000-0000-4000-8000-000000000005';
$typedToken = '{{lifecycle_row:' . $typedUuid . '}}';
$neighborToken = '{{lifecycle_row:' . $neighborUuid . '}}';
$typedFixture = static function (string $mode, string $nameWitness = 'none', bool $valueRefs = true) use (
    $fixture, $runtime, $typedUuid, $neighborUuid, $typedToken
): array {
    $f = $fixture('fresh');
    $adapterPath = $f['repo'] . '/adapters/lifecycle-reference-fixture.json';
    $adapter = json_decode(file_get_contents($adapterPath), true, 64, JSON_THROW_ON_ERROR);
    $adapter['tables']['lifecycle_rows'] = [
        'class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'lifecycle_row',
        'slug_column' => 'label', 'identity' => ['mode' => 'natural_key', 'column' => 'label'],
        'columns' => ['label' => ['class' => 'authored']],
    ];
    $adapter['option_name_refs'] = [[
        'match' => '^lifecycle_(?<id>[0-9]+)_settings$', 'class' => 'authored', 'id_kind' => 'lifecycle_row',
    ]];
    $adapter['options'] += [
        'lifecycle_scalar' => ['class' => 'authored', 'ref' => 'lifecycle_row'],
        'lifecycle_list' => ['class' => 'authored', 'ref' => 'lifecycle_row[]'],
        'lifecycle_struct' => ['class' => 'authored', 'key_refs' => ['path' => '$.by_id', 'kind' => 'lifecycle_row'], 'json_refs' => [
            ['path' => '$.primary', 'kind' => 'lifecycle_row'], ['path' => '$.secondary', 'kind' => 'lifecycle_row'],
        ]],
    ];
    Canon::write_file($adapterPath, Canon::encode($adapter));
    mkdir($f['repo'] . '/state/tables/lifecycle_rows', 0700, true);
    foreach ([$typedUuid => 'Owned', $neighborUuid => 'Neighbor'] as $uuid => $label) {
        Canon::write_file($f['repo'] . '/state/tables/lifecycle_rows/' . $uuid . '--' . strtolower($label) . '.json', Canon::encode([
            'uuid' => $uuid, 'table' => 'lifecycle_rows', 'columns' => ['label' => $label], 'meta' => (object) [],
        ]));
    }
    $document = json_decode(file_get_contents($f['repo'] . '/state/options/core.json'), true, 64, JSON_THROW_ON_ERROR);
    $records = OptionState::records($document);
    foreach (['lifecycle_scalar', 'lifecycle_list', 'lifecycle_struct'] as $name) {
        $records[$name] = OptionState::absent();
    }
    if ($nameWitness === 'canonical') {
        $records['lifecycle_' . $typedToken . '_settings'] = OptionState::absent();
    }
    Canon::write_file($f['repo'] . '/state/options/core.json', Canon::encode(OptionState::document($records)));
    $f['policy'] = Policy::load($f['repo'], adapterLibrary: AdapterLibrary::fromSourceTree($runtime));
    $f['compiled'] = RepositoryCompiler::compile($f['repo'], $f['policy']);
    if ($mode !== 'absent-table') {
        $rows = [['id' => 56, 'label' => 'Neighbor']];
        if ($mode !== 'missing') {
            $rows[] = ['id' => 55, 'label' => 'Owned'];
        }
        $f['db']->seedTable('wp_lifecycle_rows', $rows)
            ->setColumns('wp_lifecycle_rows', ['id' => 'bigint unsigned', 'label' => 'varchar(191)'])
            ->setTableEngine('wp_lifecycle_rows', 'InnoDB')->setPrimaryKey('wp_lifecycle_rows', 'id');
    }
    $f['db']->seedTable('wp_wprism_map', $mode === 'fresh' ? [] : [
        ['uuid' => $typedUuid, 'entity_type' => 'lifecycle_rows', 'id_kind' => 'lifecycle_row', 'local_id' => 55],
        ['uuid' => $neighborUuid, 'entity_type' => 'lifecycle_rows', 'id_kind' => 'lifecycle_row', 'local_id' => 56],
    ]);
    $rows = $f['db']->rows('wp_options');
    if ($valueRefs) {
        $rows[] = ['option_id' => 5, 'option_name' => 'lifecycle_scalar', 'option_value' => '55', 'autoload' => 'yes'];
        $rows[] = ['option_id' => 6, 'option_name' => 'lifecycle_list', 'option_value' => serialize([55, 56, 999]), 'autoload' => 'yes'];
        $rows[] = ['option_id' => 7, 'option_name' => 'lifecycle_struct',
            'option_value' => serialize(['primary' => 55, 'secondary' => 56, 'literal' => 'unchanged',
                'by_id' => [55 => 'owned', 56 => 'neighbor', 999 => 'dangling']]), 'autoload' => 'yes'];
    }
    if ($nameWitness === 'live') {
        $rows[] = ['option_id' => 8, 'option_name' => 'lifecycle_55_settings', 'option_value' => 'retained settings', 'autoload' => 'yes'];
    }
    $f['db']->seedTable('wp_options', $rows);
    return $f;
};

foreach ([['healthy', 'none', true], ['missing', 'none', true], ['missing', 'none', false],
    ['missing', 'live', true], ['missing', 'canonical', true], ['absent-table', 'none', true],
    ['absent-table', 'live', true], ['fresh', 'none', true]] as [$mode, $nameWitness, $valueRefs]) {
    $label = "typed $mode/$nameWitness/" . ($valueRefs ? 'value-refs' : 'unreferenced');
    $f = $typedFixture($mode, $nameWitness, $valueRefs);
    $expectedGuard = in_array($mode, ['healthy', 'fresh'], true) ? 'accepted' : 'canonical_identity_recovery_required';
    wprism_check_same($expectedGuard, $guard($f), "$label establishes its genuine compiled identity precondition");
    $beforeRows = $native($f['db']);
    $beforeTrees = $trees($f['repo']);
    $offset = count($f['db']->queries());
    try {
        $snapshot = CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
        $values = OptionState::values(json_decode($snapshot['options/core']['content'], true, 64, JSON_THROW_ON_ERROR));
        $primary = $mode === 'fresh' || ($mode === 'missing' && $nameWitness === 'none') ? null : $typedToken;
        $secondary = $mode === 'fresh' ? null : $neighborToken;
        if ($valueRefs) {
            wprism_check_same($primary, $values['lifecycle_scalar'] ?? null, "$label preserves the scalar-reference projection");
            wprism_check_same(array_values(array_filter([$primary, $secondary], static fn($token): bool => $token !== null)),
                $values['lifecycle_list'] ?? null, "$label preserves mixed known and dangling list-reference projection");
            $byId = [];
            if ($primary !== null) {
                $byId[$primary] = 'owned';
            }
            if ($secondary !== null) {
                $byId[$secondary] = 'neighbor';
            }
            wprism_check_same(['by_id' => $byId, 'literal' => 'unchanged', 'primary' => $primary, 'secondary' => $secondary],
                $values['lifecycle_struct'] ?? null, "$label preserves structured-reference and literal values");
        }
        if ($nameWitness === 'live') {
            wprism_check_same('retained settings', $values['lifecycle_' . $typedToken . '_settings'] ?? null,
                "$label retains the authored option-name identity even when its owning row is missing");
        }
    } catch (Throwable $failure) {
        wprism_check(false, "$label options-only observation failed: " . $failure->getMessage());
    }
    wprism_check_same($beforeRows, $native($f['db']), "$label retains every native row and canonical mapping");
    wprism_check_same($beforeTrees, $trees($f['repo']), "$label preserves complete repository bytes");
    wprism_check_same($f['ensure'], $mutationStatements(array_slice($f['db']->queries(), $offset)),
        "$label options-only observation has no typed reconciliation DML");
    $reads = array_slice($f['db']->queries(), $offset);
    wprism_check_same(1, count(array_filter($reads, static fn(string $sql): bool => str_starts_with($sql,
        'SELECT option_name, option_value FROM wp_options ORDER BY'))), "$label reads one bounded coherent namespace");
    wprism_check(!in_array('SELECT `option_name` FROM `wp_options`', $reads, true),
        "$label never adds an unbounded maintenance namespace scan");
    wprism_check_same($expectedGuard, $guard($f), "$label cannot turn retained canonical history into a fresh identity");
    if ($mode === 'missing') {
        try {
            CaptureSnapshotService::snapshot($f['repo'], false, $f['compiled'], $f['policy']);
            wprism_check(false, "$label subsequent full snapshot must refuse retained missing identity");
        } catch (CommandRefusalException $failure) {
            wprism_check_same($expectedGuard, $failure->reasonCode, "$label full snapshot reaches the exact canonical guard");
        } catch (Throwable $failure) {
            wprism_check(false, "$label full snapshot reached an unrelated failure: " . $failure->getMessage());
        }
        wprism_check_same($beforeRows, $native($f['db']), "$label full refusal preserves every native row");
        wprism_check_same($beforeTrees, $trees($f['repo']), "$label full refusal preserves complete repository bytes");
    }
}

$f = $typedFixture('missing');
$beforeRows = $native($f['db']);
$beforeTrees = $trees($f['repo']);
$f['db']->failNextQuery('fixture typed lifecycle option read failure', 'FROM wp_options');
try {
    CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
    wprism_check(false, 'failed typed lifecycle option read must refuse');
} catch (Throwable $failure) {
    wprism_check(str_contains($failure->getMessage(), 'failed') || str_contains($failure->getMessage(), 'failure'),
        'typed lifecycle read failure stays loud');
}
wprism_check_same($beforeRows, $native($f['db']), 'failed typed lifecycle read retains all native rows and canonical mappings');
wprism_check_same($beforeTrees, $trees($f['repo']), 'failed typed lifecycle read preserves complete repository bytes');
wprism_check_same('canonical_identity_recovery_required', $guard($f), 'failed typed lifecycle read cannot erase the later canonical guard');

foreach (['namespace', 'physical-row', 'presence'] as $fault) {
    $f = $typedFixture('healthy');
    $beforeRows = $native($f['db']);
    $beforeTrees = $trees($f['repo']);
    $query = match ($fault) {
        'namespace' => 'SELECT option_name, option_value FROM wp_options ORDER BY',
        'physical-row' => 'SELECT 1 FROM `wp_lifecycle_rows` WHERE `id` = 55',
        default => 'SELECT 1 FROM `wp_lifecycle_rows` LIMIT 0',
    };
    $armAt = $fault === 'presence' ? 'SELECT option_name, option_value FROM wp_options ORDER BY' : 'START TRANSACTION';
    $f['db']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use ($query, $armAt): null {
        if (str_starts_with($sql, $armAt)) {
            $db->onQuery(null)->failNextQuery('fixture typed read denied', $query, errno: 1142);
        }
        return null;
    });
    try {
        CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
        wprism_check(false, "$fault failure must not be accepted as an empty namespace or absent row/table");
    } catch (Throwable $failure) {
        wprism_check_same($fault === 'presence' ? WPrism\DatabaseTablePresenceException::class : mysqli_sql_exception::class,
            get_class($failure), "$fault refuses through the actual typed read boundary");
    }
    wprism_check_same($beforeRows, $native($f['db']), "$fault failure preserves all native rows and mappings");
    wprism_check_same($beforeTrees, $trees($f['repo']), "$fault failure preserves all canonical bytes");
    $snapshot = CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
    $values = OptionState::values(json_decode($snapshot['options/core']['content'], true, 64, JSON_THROW_ON_ERROR));
    wprism_check_same($typedToken, $values['lifecycle_scalar'] ?? null, "$fault recovers through a fresh ordinary snapshot");
}

// Profiles grant metadata for an absent declared owner, never row reads or
// reconciliation writes. Appearance after binding cannot widen that grant.
$f = $typedFixture('absent-table');
$profile = CaptureTransaction::database_profile($f['policy'], true);
wprism_check_same(['wp_lifecycle_rows'], $profile->table_presence_reads(), 'presence authority names only the declared option-name owner');
wprism_check(!in_array('wp_lifecycle_rows', $profile->readable_tables(), true), 'an absent owner receives no row-read authority');
wprism_check_same([], $profile->write_tables(), 'lifecycle reference projection has no write authority');
$appearedRows = null;
$beforeTrees = $trees($f['repo']);
$f['db']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$appearedRows, $native): null {
    if ($appearedRows === null && str_starts_with($sql, 'START TRANSACTION')) {
        $db->seedTable('wp_lifecycle_rows', [['id' => 55, 'label' => 'Owned']])
            ->setColumns('wp_lifecycle_rows', ['id' => 'bigint unsigned', 'label' => 'varchar(191)'])
            ->setTableEngine('wp_lifecycle_rows', 'InnoDB')->setPrimaryKey('wp_lifecycle_rows', 'id');
        $db->onQuery(null);
        $appearedRows = $native($db);
    }
    return null;
});
try {
    CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
    wprism_check(false, 'a table appearing after profile binding must not gain row-read authority');
} catch (WPrism\DatabaseQueryIsolationViolationException $failure) {
    wprism_check_same('wprism: a native database read escaped its declared physical-table profile', $failure->getMessage(),
        'late table appearance refuses at the exact row-read boundary');
}
wprism_check(is_array($appearedRows), 'the appearance control actually ran before transaction start');
wprism_check_same($appearedRows, $native($f['db']), 'profile refusal preserves the externally appeared table and every map');
wprism_check_same($beforeTrees, $trees($f['repo']), 'profile refusal preserves all canonical bytes');

// One retained callback can be prepared for another observation, but never
// reuse a completed authority or an earlier table-presence/name witness.
$f = $typedFixture('absent-table');
$view = new LifecycleReferenceView($f['policy'], null);
try {
    $view->uuidFor(55, 'lifecycle_row');
    wprism_check(false, 'unprepared lifecycle reference lookup must refuse');
} catch (LogicException $failure) {
    wprism_check_same('wprism: lifecycle references have no prepared observation', $failure->getMessage(), 'an unprepared view has no ambient authority');
}
$observe = static function (array $names) use ($f, $view): ?string {
    return CaptureTransaction::run($f['policy'], static function (DatabaseWorkAuthority $authority) use ($view, $names): ?string {
        $view->prepare($names, $authority);
        return $view->uuidFor(55, 'lifecycle_row');
    }, optionsOnly: true);
};
wprism_check_same($typedUuid, $observe([]), 'absent owner keeps the prior lifecycle identity projection');
try {
    $view->uuidFor(55, 'lifecycle_row');
    wprism_check(false, 'a completed observation cannot lend lookup authority to later work');
} catch (RuntimeException $failure) {
    wprism_check_same('wprism: engine database work item authority lost database query-filter isolation', $failure->getMessage(),
        'completed observation authority refuses before any cached lookup');
}
$f['db']->seedTable('wp_lifecycle_rows', [])->setColumns('wp_lifecycle_rows', ['id' => 'bigint unsigned', 'label' => 'varchar(191)'])
    ->setTableEngine('wp_lifecycle_rows', 'InnoDB')->setPrimaryKey('wp_lifecycle_rows', 'id');
wprism_check_same(null, $observe([]), 'a fresh observation discards the previous absent-table cache');
wprism_check_same($typedUuid, $observe(['lifecycle_55_settings' => 'settings']), 'a fresh live option-name witness retains its missing owner');
wprism_check_same(null, $observe([]), 'a later empty namespace discards the previous preserved-name cache');

$f = $typedFixture('healthy');
$beforeRows = $native($f['db']);
$beforeTrees = $trees($f['repo']);
$injected = 0;
$f['db']->onQuery(static function (string $sql) use (&$injected): null {
    if ($injected === 0 && str_starts_with($sql, 'SELECT 1 FROM `wp_lifecycle_rows` WHERE `id` = 55')) {
        $injected++;
        throw new WPrism\TransientDbException('fixture transient lifecycle lookup');
    }
    return null;
});
$snapshot = CaptureSnapshotService::snapshotOptionsCore($f['repo'], false, $f['compiled'], $f['policy']);
$values = OptionState::values(json_decode($snapshot['options/core']['content'], true, 64, JSON_THROW_ON_ERROR));
wprism_check_same(1, $injected, 'the same-call retry control actually interrupts a typed physical lookup');
wprism_check_same($typedToken, $values['lifecycle_scalar'] ?? null, 'the ordinary capture retry prepares a fresh authority for the retained callback');
wprism_check_same($beforeRows, $native($f['db']), 'same-call retry preserves all native rows and retained identities');
wprism_check_same($beforeTrees, $trees($f['repo']), 'same-call retry preserves all canonical bytes');

foreach ([[], ['table' => 'wp_lifecycle_rows', 'pk' => 'id'], ['wp_lifecycle_rows', 'id` OR 1=1'],
    ['foreign.wp_lifecycle_rows', 'id'], ['wp_lifecycle_rows', 1]] as $physical) {
    $offset = count($f['db']->queries());
    try {
        Ledger::capture_reference_uuid_for(999, 'lifecycle_row', $physical);
        wprism_check(false, 'an invalid physical key must refuse even for an unmapped id');
    } catch (LogicException $failure) {
        wprism_check_same('wprism: capture reference requires an exact physical key', $failure->getMessage(),
            'generic reference observation refuses malformed physical identifiers');
    }
    wprism_check_same($offset, count($f['db']->queries()), 'invalid physical identifiers refuse before database access');
}

wprism_check_summary('lifecycle identity preservation');
