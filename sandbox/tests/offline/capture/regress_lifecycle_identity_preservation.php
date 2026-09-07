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
use WPrism\CommandRefusalException;
use WPrism\Ledger;
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

wprism_check_summary('lifecycle identity preservation');
