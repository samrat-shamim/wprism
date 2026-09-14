<?php
declare(strict_types=1);

// Public core-only Apply over the shared SQL, getter, and cache machinery.
// The verifier executes its real scoped implementation in a semantic child
// double; native process/bootstrap evidence remains the owned live lane.
$root = dirname(__DIR__, 4);
$scratch = sys_get_temp_dir() . '/wprism-scoped-request-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $scratch . '/content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
foreach (['check.php', 'agent_version.php', 'native_option_stubs.php', 'FakeWpdb.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp_cli_child_process_fake.php";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
wprism_test_define_agent_versions();
require_once "$root/agent/src/Apply/Apply.php";
require_once "$root/agent/src/Repository/RepositoryAuthorization.php";

use WPrism\AdapterLibrary;
use WPrism\Apply;
use WPrism\Canon;
use WPrism\CommandRefusalException;
use WPrism\ConvergenceVerifier;
use WPrism\EnvironmentValues;
use WPrism\LedgerScopedApplySessionStorage;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\ScopeContract;
use WPrism\ScopedApplySession;
use WPrism\ScopedApplyRequest;
use WPrism\TableSchema;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

function get_taxonomies(array $args = [], string $output = 'names'): array { return []; }
function get_taxonomy(string $name): object { return (object) ['name' => $name, 'object_type' => [], 'hierarchical' => false]; }
function get_plugins(): array { return []; }

final class WP_CLI {
    use WPrismTest\WpCliChildRuntime;

    public static function runcommand(string $command, array $options): object {
        if (!str_contains($command, 'wprism verify-canonical')) throw new LogicException('unexpected core request child');
        if (!empty($GLOBALS['scoped_request_verifier_failure'])) {
            return (object) ['return_code' => 1, 'stdout' => '', 'stderr' => 'fixture interrupted verification'];
        }
        [$repo, $policy, $compiled, $contract] = $GLOBALS['scoped_request_context'];
        $session = ScopedApplySession::open(new LedgerScopedApplySessionStorage());
        $authority = $session->authority();
        $report = (new ConvergenceVerifier($repo, $policy, $contract))->verify_scoped_local($compiled, false,
            $authority['target']['protected_out_of_scope_hash'], $authority['target']['protected_ledger_map_hash'],
            $session->authority_hash_value(), ScopedApplySession::hash_value($session->receipts()));
        return (object) ['return_code' => 0, 'stdout' => json_encode($report, JSON_THROW_ON_ERROR), 'stderr' => ''];
    }
}

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$repo = $scratch . '/repo';
foreach ([$repo . '/state/options', WP_CONTENT_DIR . '/themes/fixture', WP_PLUGIN_DIR, WPMU_PLUGIN_DIR] as $dir) mkdir($dir, 0700, true);
$repo = (string) realpath($repo);
file_put_contents(WP_CONTENT_DIR . '/themes/fixture/style.css', "/*\nTheme Name: Fixture\nVersion: 1.0.0\n*/\n");
$lifecyclePlugin = 'lifecycle-fixture/plugin.php';
$pluginPath = WP_PLUGIN_DIR . '/' . $lifecyclePlugin;
$pluginBytes = "<?php\n/*\nPlugin Name: Lifecycle fixture\nVersion: 1.0.0\n*/\n";
mkdir(dirname($pluginPath), 0700, true);
file_put_contents($pluginPath, $pluginBytes);
Canon::write_file($repo . '/site.wprism.json', Canon::encode(['spec_version' => WPRISM_SPEC_VERSION,
    'manifests' => ['core'], 'policy' => ['post_types' => [], 'taxonomies' => []]]));
WpStore::reset()->seedOptions(['home' => 'https://target.example.test', 'siteurl' => 'https://target.example.test',
    'admin_email' => 'admin@target.example.test', 'active_plugins' => [], 'stylesheet' => 'fixture', 'template' => 'fixture'])->ensureUploadDir();
$GLOBALS['wp_version'] = '7.1';
$GLOBALS['wp_filter'] = [];
$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
wprism_wp_store()->version = '7.1';
$db = FakeWpdb::install()->setServerVersion('8.4.0')->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
$tables = [];
foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
    $tables[] = $db->$property;
    $db->seedTable($db->$property, [])->setColumns($db->$property, array_fill_keys($columns, 'longtext'))->setTableEngine($db->$property, 'InnoDB');
}
$nativeOptions = ['active_plugins' => serialize([$lifecyclePlugin]), 'stylesheet' => 'fixture', 'template' => 'fixture',
    'home' => 'https://target.example.test', 'siteurl' => 'https://target.example.test',
    'admin_email' => 'admin@target.example.test', 'blogname' => 'Original', 'blogdescription' => 'Protected'];
$optionRows = [];
foreach ($nativeOptions as $name => $value) $optionRows[] = ['option_id' => count($optionRows) + 1,
    'option_name' => $name, 'option_value' => $value, 'autoload' => 'yes'];
$db->seedTable('wp_options', $optionRows)->setAutoIncrement('wp_options', count($optionRows) + 1, 'option_id');
foreach ([
    'wp_wprism_map' => ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'],
    'wp_wprism_state' => ['uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'varchar(64)'],
    'wp_wprism_kv' => ['k' => 'varchar(191)', 'v' => 'longtext'],
    'wp_wprism_journal' => array_fill_keys(['id', 't', 'op', 'tbl', 'item', 'surface', 'actor', 'caps', 'hook', 'proposal'], 'longtext'),
] as $table => $columns) {
    $tables[] = $table;
    $db->seedTable($table, [])->setColumns($table, $columns)->setTableEngine($table, 'InnoDB');
}
foreach (['wp_options' => [['option_id'], ['option_name']], 'wp_posts' => [['ID']], 'wp_postmeta' => [['meta_id']],
    'wp_wprism_map' => [['uuid', 'id_kind'], ['id_kind', 'local_id']], 'wp_wprism_state' => [['uuid']], 'wp_wprism_kv' => [['k']]] as $table => $keys) {
    $indexes = [];
    foreach ($keys as $index => $columns) {
        $db->setUniqueKey($table, $columns);
        foreach ($columns as $position => $column) $indexes[] = ['Key_name' => $index === 0 ? 'PRIMARY' : 'unique_' . $index,
            'Column_name' => $column, 'Seq_in_index' => $position + 1, 'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE'];
    }
    $db->setIndexes($table, $indexes);
}
$library = AdapterLibrary::fromSourceTree($root);
$policy = Policy::load($repo, adapterLibrary: $library);
foreach (['home', 'siteurl', 'admin_email'] as $name) EnvironmentValues::set($repo, $name, $nativeOptions[$name]);
$records = [];
foreach ([$policy->authored_options(), $policy->sub_keyed_options()] as $rules) {
    foreach ($rules as $name => $_rule) $records[$name] = OptionState::absent();
}
foreach (['active_plugins' => [$lifecyclePlugin], 'stylesheet' => 'fixture', 'template' => 'fixture', 'blogdescription' => 'Protected'] as $name => $value) {
    $records[$name] = OptionState::present($value, 'yes');
}
$source = static function (string $value, ?string $requestId) use ($repo, $scratch, $records, $policy, $library): array {
    $records['blogname'] = OptionState::present($value, 'yes');
    Canon::write_file($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
    $compiled = RepositoryCompiler::compile($repo, $policy);
    $artifact = $scratch . '/' . $compiled->artifact_hash() . '.json';
    $compiled->write($artifact);
    $contract = ScopeContract::resolve($compiled, $policy, ['option:blogname']);
    $GLOBALS['scoped_request_context'] = [$repo, $policy, $compiled, $contract];
    $opts = ['compiled' => $artifact, 'adapter_library' => $library, 'scope_request' => $contract];
    if ($requestId !== null) $opts['request_id'] = $requestId;
    return $opts;
};
$observe = static function () use ($db, $tables): array {
    $rows = [];
    foreach ($tables as $table) $rows[$table] = $db->rows($table);
    return $rows;
};
$refuses = static function (array $opts, string $message, string $needle = '') use ($repo, $observe): void {
    $before = $observe();
    $failure = null;
    try { Apply::apply($repo, $opts); } catch (CommandRefusalException $error) { $failure = $error; }
    wprism_check($failure !== null && ($needle === '' || str_contains($failure->publicMessage, $needle)), $message);
    wprism_check_same($before, $observe(), $message . ': complete physical target remains exact');
};
$complete = static function (array $opts, string $value) use ($repo): array {
    $result = Apply::apply($repo, $opts);
    wprism_check_same('complete', $result['scoped_receipt']['phase'], 'public request completes');
    wprism_check_same('pass', $result['verification']['result'], 'real scoped verifier proves authored convergence');
    wprism_check_same($value, get_option('blogname'), 'selected native option agrees with the desired source');
    wprism_check_same('Protected', get_option('blogdescription'), 'protected native option remains exact');
    return $result;
};

// GET_LOCK has a zero wait budget, but another process can finish between
// the earlier session read and this acquisition. Execute real public Apply
// at that shared SQL seam; never manufacture session or terminal-index rows.
$interleave = static function (array $opts, callable $concurrent) use ($db, $repo, $observe): array {
    $completed = $target = $result = $failure = null;
    $hit = false;
    $db->onQuery(static function (string $sql) use ($db, $concurrent, $observe, &$hit, &$completed, &$target): null {
        if (str_contains($sql, 'GET_LOCK(')) {
            $db->onQuery(null);
            $hit = true;
            $completed = $concurrent();
            $target = $observe();
        }
        return null;
    });
    try { $result = Apply::apply($repo, $opts); } catch (Throwable $error) { $failure = $error; }
    finally { $db->onQuery(null); }
    wprism_check($hit && $target !== null, 'the concurrent public request reaches its outcome before fence acquisition');
    return [$completed, $result, $failure, $target];
};

$setCode = static function (bool $ready) use ($pluginPath, $pluginBytes): void {
    if ($ready) file_put_contents($pluginPath, $pluginBytes);
    else unlink($pluginPath);
    clearstatcache(true, $pluginPath);
};
try {
    $racingOptions = $source('A', 'core-request-A1');
    [$concurrentResult, $a1, $raceFailure, $concurrentTarget] = $interleave(
        $racingOptions, static function () use ($complete, $racingOptions, $setCode): array {
            $result = $complete($racingOptions, 'A');
            $setCode(false);
            return $result;
        }
    );
    wprism_check($raceFailure === null, 'a concurrent exact retry succeeds after acquiring its fence');
    wprism_check_same($concurrentResult['scoped_receipt'], $a1['scoped_receipt'] ?? null,
        'a concurrent exact retry returns the completed authority receipt');
    wprism_check_same($concurrentTarget, $observe(), 'a concurrent exact retry preserves every completed physical row');
    $setCode(true);
    $b = $complete($source('B', 'core-request-B1'), 'B');
    $oldA = $source('A', 'core-request-A1');
    $refuses($oldA, 'retrying stale A cannot reverse B', 'terminal scoped receipt no longer describes');
    $a2Options = $source('A', 'core-request-A2');
    $a2 = $complete($a2Options, 'A');
    wprism_check($a1['scoped_receipt']['authority_hash'] !== $a2['scoped_receipt']['authority_hash'],
        'returning to source A mints separate target-bound request authority');
    $stable = $observe();
    $replay = Apply::apply($repo, $a2Options);
    wprism_check_same($a2['scoped_receipt'], $replay['scoped_receipt'], 'active request replay returns exact receipt bytes');
    wprism_check_same($stable, $observe(), 'active request replay mutates no table');
    // Installed code can disappear after completion while every canonical
    // witness stays exact. Historical replay authorizes no new plugin work.
    $setCode(false);
    $lifecycleChanged = $observe();
    $historical = $replayFailure = null;
    try { $historical = Apply::apply($repo, $a2Options); } catch (Throwable $error) { $replayFailure = $error; }
    wprism_check($replayFailure === null, 'completed scoped replay does not admit new plugin work');
    wprism_check_same($a2['scoped_receipt'], $historical['scoped_receipt'] ?? null,
        'later missing code cannot replace an exact scoped terminal receipt');
    wprism_check_same($lifecycleChanged, $observe(), 'terminal replay preserves every native and ledger row');
    wprism_check(!is_file($pluginPath), 'historical replay does not reinstall missing plugin code');
    $setCode(true);
    $legacy = $a2Options;
    unset($legacy['request_id']);
    $refuses($legacy, 'omitting the explicit ID cannot downgrade its authority', 'retained request ID');
    $refuses($a2Options + ['with_deletes' => true], 'changed deletion capability cannot reuse an ID', 'retained request ID');
    foreach (['', 'short', true, [], str_repeat('a', 129)] as $id) {
        $refuses(array_replace($a2Options, ['request_id' => $id]), 'malformed request ID refuses before writes', 'request ID is malformed');
    }
    $refuses(array_diff_key($a2Options, ['scope_request' => true]), 'full Apply refuses a scoped request ID', 'requires direct scoped apply');
    $refuses($source('B', 'core-request-A2'), 'active ID cannot be reused for changed source', 'retained request ID');
    $refuses($source('B', 'core-request-A1'), 'archived ID cannot be reused for changed source', 'does not bind an intact terminal receipt');
    $source('A', 'core-request-A1');
    $stable = $observe();
    $archived = Apply::apply($repo, $oldA);
    wprism_check_same($a1['scoped_receipt'], $archived['scoped_receipt'], 'archived request replays only after its exact target witnesses hold again');
    wprism_check_same($stable, $observe(), 'archived replay mutates no table');

    foreach (['delete capability', 'source', 'scope'] as $changed) {
        $id = 'core-race-' . str_replace(' ', '-', $changed);
        $racingOptions = $source('Race ' . $changed, $id);
        $concurrent = static fn() => $complete($racingOptions, 'Race ' . $changed);
        if ($changed === 'delete capability') {
            $racingOptions['with_deletes'] = true;
        } elseif ($changed === 'source') {
            $concurrent = static function () use ($source, $complete, $id): array {
                $first = $complete($source('Race earlier source', $id), 'Race earlier source');
                $source('Race source', $id);
                return $first;
            };
        } else {
            $racingOptions['scope_request'] = ScopeContract::resolve(
                $GLOBALS['scoped_request_context'][2], $policy, ['option:blogdescription']
            );
        }
        [, $result, $failure, $completedTarget] = $interleave($racingOptions, $concurrent);
        wprism_check($result === null && $failure instanceof CommandRefusalException
            && str_contains($failure->publicMessage, 'retained request ID'),
            'concurrent reuse with changed ' . $changed . ' returns the exact request collision refusal');
        wprism_check_same($completedTarget, $observe(), 'concurrent ' . $changed . ' collision preserves the completed target and evidence');
    }

    $racingOptions = $source('Race latest', 'core-race-latest');
    $middleContext = null;
    [$middle, $latest, $failure] = $interleave($racingOptions, static function () use ($source, $complete, &$middleContext): array {
        $first = $complete($source('Race middle', 'core-race-middle'), 'Race middle');
        $middleContext = $GLOBALS['scoped_request_context'];
        $source('Race latest', 'core-race-latest');
        return $first;
    });
    wprism_check($failure === null && ($latest['verification']['result'] ?? null) === 'pass'
        && get_option('blogname') === 'Race latest', 'a different new request completes after the intervening terminal');
    $middleArchive = ScopedApplySession::open_terminal_for_request(
        new LedgerScopedApplySessionStorage(), $middleContext[3]['scope_hash'], $middleContext[2]->artifact_hash(),
        null, ScopedApplyRequest::binding('core-race-middle', false)
    );
    wprism_check_same($middle['scoped_receipt'], $middleArchive?->terminal_receipt(),
        'rotation archives the newly observed terminal with its exact receipt');

    $racingOptions = $source('Race archived', 'core-race-archived');
    [, $result, $failure, $completedTarget] = $interleave($racingOptions + ['with_deletes' => true],
        static function () use ($repo, $source, $complete, $racingOptions): array {
            $first = $complete($racingOptions, 'Race archived');
            Apply::apply($repo, $source('Race archived', 'core-race-archive-next'));
            return $first;
        }
    );
    wprism_check($result === null && $failure instanceof CommandRefusalException
        && str_contains($failure->publicMessage, 'does not bind an intact terminal receipt'),
        'the locked lookup also catches request collisions archived by an intervening generation');
    wprism_check_same($completedTarget, $observe(), 'an intervening archived collision preserves all completed target evidence');

    $racingOptions = $source('Race pending', 'core-race-pending');
    [, $result, $failure, $pendingTarget] = $interleave($racingOptions, static function () use ($repo, $racingOptions): ?Throwable {
        $GLOBALS['scoped_request_verifier_failure'] = true;
        try { Apply::apply($repo, $racingOptions); } catch (Throwable $error) { return $error; }
        finally { $GLOBALS['scoped_request_verifier_failure'] = false; }
        return null;
    });
    wprism_check($result === null && $failure instanceof CommandRefusalException
        && str_contains($failure->publicMessage, 'session changed before'),
        'a request that becomes pending before acquisition requires its exact recovery lease');
    wprism_check_same($pendingTarget, $observe(), 'newly pending authority is not stolen or republished under the contender lease');
    $pendingRace = ScopedApplySession::open(new LedgerScopedApplySessionStorage());
    $recoveredRace = $complete($racingOptions, 'Race pending');
    wprism_check_same($pendingRace->authority_hash_value(), $recoveredRace['scoped_receipt']['authority_hash'],
        'retry after the pending-session race recovers the original authority');

    $interrupted = $source('C', 'core-request-C1');
    $GLOBALS['scoped_request_verifier_failure'] = true;
    $failed = null;
    try { Apply::apply($repo, $interrupted); } catch (Throwable $failure) { $failed = $failure; }
    $GLOBALS['scoped_request_verifier_failure'] = false;
    $pending = ScopedApplySession::open(new LedgerScopedApplySessionStorage());
    wprism_check($failed !== null && str_contains($failed->getMessage(), 'scoped convergence verification refused the bounded target')
        && $pending !== null && !$pending->is_terminal(), 'interrupted public verification retains a nonterminal request');
    $refuses(array_replace($interrupted, ['request_id' => 'core-request-C2']), 'a new ID cannot steal interrupted work', 'retained request ID');
    $refuses(array_diff_key($interrupted, ['request_id' => true]), 'interrupted work cannot shed its explicit ID', 'retained request ID');
    $refuses($interrupted + ['with_deletes' => true], 'interrupted work cannot change deletion capability', 'retained request ID');
    $recovered = $complete($interrupted, 'C');
    wprism_check_same($pending->authority_hash_value(), $recovered['scoped_receipt']['authority_hash'],
        'the original ID recovers the exact interrupted authority');
    $db->update('wp_options', ['option_value' => 'External change'], ['option_name' => 'blogname']);
    wp_cache_flush();
    $refuses($interrupted, 'completed request cannot hide selected target drift', 'terminal scoped receipt no longer describes');
    $db->update('wp_options', ['option_value' => 'C'], ['option_name' => 'blogname']);
    $db->update('wp_options', ['option_value' => 'External protected change'], ['option_name' => 'blogdescription']);
    wp_cache_flush();
    $refuses($interrupted, 'completed request cannot hide protected target drift', 'terminal scoped receipt no longer describes');
} catch (Throwable $failure) {
    fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . "\n");
    wprism_check(false, 'public core request sequence completes');
}
wprism_check_summary('regress_scoped_apply_requests');
