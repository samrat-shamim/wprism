<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
if (($argv[1] ?? null) === 'native-residue') {
    $db = \WPrismTest\FakeWpdb::install();
    \WPrismTest\WpStore::reset()->seedOptions(['wprism_polylang_undeclared_neighbor' => 'target-only-preserved']);
    foreach (['wp_options', 'wp_term_taxonomy', 'wp_postmeta'] as $table) $db->seedTable($table, []);
    if ($argv[2] === 'retained-option') $db->seedTable('wp_options', [['option_name' => 'polylang', 'option_value' => 'retained']]);
    if (in_array($argv[2], ['wp_options', 'wp_term_taxonomy', 'wp_postmeta'], true)) {
        $db->failNextQuery('fixture uninstall observation failed', 'FROM ' . $argv[2]);
    }
    eval($argv[3]);
    exit(0);
}
require_once $root . '/agent/src/Capture/CaptureSnapshotService.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once dirname(__DIR__, 2) . '/fixtures/polylang_uninstall_evidence.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\CaptureSnapshotService;
use WPrism\CommandRefusalException;
use WPrism\Ledger;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\PrivateRefusalEvidence;
use WPrism\RepositoryCompiler;
use WPrism\TableSchema;
use WPrismTest\FakeWpdb;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\ShellProbe;
use WPrismTest\WpStore;

$scratch = sys_get_temp_dir() . '/wprism-polylang-uninstall-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$write = static function (string $path, string $bytes): void { file_put_contents($path, $bytes); chmod($path, 0600); };
$transport = static function (string $stage, string|array $value, int $status = 0, string $stderr = '') use ($scratch, $write): void {
    $write("$scratch/$stage.stdout", is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR) . "\n");
    $write("$scratch/$stage.stderr", $stderr);
    $write("$scratch/$stage.exit", "$status\n");
};
$repo = "$scratch/repo";
foreach (['state/options', 'state/sidebars', 'media'] as $directory) mkdir("$repo/$directory", 0700, true);
$write("$repo/site.wprism.json", Canon::encode(['spec_version' => WPRISM_SPEC_VERSION,
    'manifests' => ['core', 'polylang'], 'policy' => ['post_types' => [], 'taxonomies' => []]]));
$policy = Policy::load($repo, adapterLibrary: AdapterLibrary::fromSourceTree($root));
$options = [];
foreach ($policy->authored_options() as $name => $rule) $options[$name] = OptionState::absent();
foreach (['active_plugins', 'polylang', 'stylesheet', 'template'] as $name) $options[$name] = OptionState::absent();
$options['polylang'] = OptionState::present(['browser' => false, 'default_lang' => '', 'force_lang' => 1, 'hide_default' => true,
    'media_support' => false, 'nav_menus' => [], 'post_types' => [], 'redirect_lang' => false, 'rewrite' => true, 'sync' => [], 'taxonomies' => []], 'yes');
$write("$repo/state/options/core.json", Canon::encode(OptionState::document($options)));
$uuid = '10000000-0000-4000-8000-000000000001';
$write("$repo/state/sidebars/sidebar-1.json", Canon::encode(['widgets' => [[
    'uuid' => $uuid, 'type' => 'polylang', 'settings' => ['title' => 'Languages 東京', 'dropdown' => 0,
        'force_home' => 0, 'hide_current' => 0, 'hide_if_no_translation' => 0, 'show_flags' => 1, 'show_names' => 1],
]]]));
$compiled = RepositoryCompiler::compile($repo, $policy);
$snapshot = PolylangUninstallEvidence::snapshot($repo);
$transport('compiled', $snapshot);
wprism_check_same($snapshot, PolylangUninstallEvidence::readSnapshot("$scratch/compiled"), 'uninstall snapshot retains genuine compiled Polylang widget identity');
[$status, $output, $diagnostic] = ShellProbe::run('exec "$1" "$2" snapshot repo',
    [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/polylang_uninstall_evidence.php'], $scratch);
wprism_check($status === 0 && $diagnostic === '' && json_decode($output, true) === $snapshot,
    'actual WordPress-free uninstall evidence entrypoint compiles the complete caller-relative repository');

// The ordinary full-Plan snapshot, real policy/compiler, ledger and strict
// selected-widget readers run against shared row-backed storage. No canned
// guard or widget result can manufacture the expected refusal chain.
$db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
WpStore::reset()->seedOptions(['home' => 'https://uninstall.example.test']);
foreach ($db->tables() as $table) $db->seedTable($table, [])->setTableEngine($table, 'InnoDB');
foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
    $db->seedTable($db->$property, [])->setColumns($db->$property, array_fill_keys($columns, 'longtext'))->setTableEngine($db->$property, 'InnoDB');
}
$mutationStatements = static fn(array $queries): array => array_values(array_filter($queries,
    static fn(string $sql): bool => preg_match('/\A\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql) === 1));
$ensureOffset = count($db->queries());
Ledger::ensure();
$ensureStatements = $mutationStatements(array_slice($db->queries(), $ensureOffset));
wprism_check($ensureStatements !== [] && count(array_filter($ensureStatements,
    static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS `wp_wprism_'))) === count($ensureStatements),
    'native fixture establishes the real idempotent ledger schema precondition before observation');
$db->seedTable('wp_wprism_map', [['uuid' => $uuid, 'entity_type' => 'widget', 'id_kind' => 'widget_polylang', 'local_id' => 1]])
    ->seedTable('wp_options', [['option_id' => 1, 'option_name' => 'sidebars_widgets',
        'option_value' => serialize(['sidebar-1' => ['polylang-1'], 'array_version' => 3]), 'autoload' => 'yes']]);
$tables = ['wp_commentmeta', 'wp_comments', 'wp_links', 'wp_options', 'wp_postmeta', 'wp_posts', 'wp_term_relationships',
    'wp_term_taxonomy', 'wp_termmeta', 'wp_terms', 'wp_usermeta', 'wp_users', 'wp_wprism_map', 'wp_wprism_state'];
$nativeBefore = array_map($db->rows(...), $tables);
$queryOffset = count($db->queries());
$reason = null;
try {
    CaptureSnapshotService::snapshot($repo, false, $compiled, $policy);
    wprism_check(false, 'ordinary full-Plan observation must refuse missing canonical widget backing before pruning');
} catch (CommandRefusalException $failure) {
    $reason = $failure;
    wprism_check_same('canonical_identity_recovery_required', $failure->reasonCode, 'ordinary full-Plan observation reaches the stable identity recovery gate');
}
if (!$reason instanceof CommandRefusalException) throw new RuntimeException('actual product refusal was not retained');
wprism_check_same($nativeBefore, array_map($db->rows(...), $tables), 'ordinary full-Plan refusal preserves native rows and the stale map instead of pruning history');
wprism_check_same($ensureStatements, $mutationStatements(array_slice($db->queries(), $queryOffset)),
    'ordinary full-Plan observation repeats only the established ledger ensure statements, without pruning, repair or data writes');
wprism_check_same($snapshot, PolylangUninstallEvidence::snapshot($repo), 'ordinary full-Plan refusal preserves complete repository bytes');
$public = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'plan',
    'reason_code' => $reason->reasonCode, ...$reason->payload()];
PolylangUninstallEvidence::assertPublic($public);
wprism_check(true, 'actual product refusal satisfies the capsule exact public contract');
mkdir("$scratch/refusals", 0700);
$baseline = PrivateRefusalReceipt::diagnosticSnapshot("$scratch/refusals", 'plan');
$raw = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'plan', 'reason_code' => $reason->reasonCode,
    ...PrivateRefusalEvidence::graph($reason)];
$write("$scratch/refusals/20260907-090001-plan-111111111111111111111111.json", json_encode($raw, JSON_THROW_ON_ERROR));
$diagnostic = PrivateRefusalReceipt::diagnosticNewRecords("$scratch/refusals", $baseline, 'plan');
PrivateRefusalReceipt::verifyDiagnostic(json_decode($diagnostic, true), PolylangUninstallEvidence::profile());
wprism_check(true, 'actual full-Plan snapshot cause matches the complete two-node native uninstall profile');

// Synthetic native-dump transport, not a live database certificate. Its exact
// row bytes exercise retention; the caller binds the unfiltered native producer.
$roster = implode('', array_map(static fn(string $table): string => "$table\tBASE TABLE\n", $tables));
$dump = "-- MariaDB dump 10.19 Distrib 11.4\n";
foreach ($tables as $table) {
    $dump .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n  `id` int NOT NULL\n);\n-- Dumping data for table `$table`\n";
    $dump .= $table === 'wp_wprism_map'
        ? "INSERT INTO `$table` (`uuid`, `entity_type`, `id_kind`, `local_id`) VALUES ('$uuid','widget','widget_polylang',1);\n"
        : "INSERT INTO `$table` (`id`) VALUES (1);\n";
}
$dump .= "-- Dump completed\n";
$reset = static function () use ($transport, $snapshot, $roster, $dump, $public, $diagnostic): void {
    foreach (['before', 'after'] as $stage) {
        $transport($stage, $snapshot); $transport("$stage-tables", $roster); $transport("$stage-database", $dump);
    }
    $transport('command', $public, 1); $transport('private', $diagnostic);
};
$reset();
PolylangUninstallEvidence::verify($scratch, 'polyoffline');
wprism_check(true, 'uninstall verifier admits the exact cause and complete native/repository preservation');
foreach (['success', 'generic-error', 'extra-public-detail', 'database-change', 'state-change', 'media-change', 'policy-change',
    'empty-widgets', 'missing-canonical-widget', 'missing-map', 'wrong-map-kind', 'duplicate-map', 'empty-dump', 'partial-dump',
    'empty-roster', 'wrong-service', 'database-exit', 'private-exit', 'unrelated-cause', 'missing-previous', 'no-new-record'] as $fault) {
    $reset();
    switch ($fault) {
        case 'success': $transport('command', $public, 0); break;
        case 'generic-error': $bad = $public; $bad['reason_code'] = 'plan_failed'; $transport('command', $bad, 1); break;
        case 'extra-public-detail': $transport('command', $public + ['private' => 'not-public'], 1); break;
        case 'database-change': $transport('after-database', str_replace('VALUES (1)', 'VALUES (2)', $dump)); break;
        case 'state-change': case 'media-change': case 'policy-change':
            $boundary = match ($fault) { 'state-change' => 'state', 'media-change' => 'media', default => 'site.wprism.json' };
            $path = match ($fault) { 'state-change' => "$repo/state/options/core.json", 'media-change' => "$repo/media/retained.bin", default => "$repo/site.wprism.json" };
            $original = file_exists($path) ? file_get_contents($path) : null;
            $write($path, ($original ?? "binary\0\xff") . "\n");
            $bad = $snapshot; $bad['trees'][$boundary] = FilesystemTreeEvidence::capture($repo, $boundary);
            if ($original === null) unlink($path); else $write($path, $original);
            PolylangUninstallEvidence::assertSnapshot($bad);
            $transport('after', $bad); break;
        case 'empty-widgets':
            $bad = $snapshot; $bad['widgets'] = []; $transport('before', $bad); $transport('after', $bad); break;
        case 'missing-canonical-widget':
            $bad = $snapshot; $bad['widgets'] = ['20000000-0000-4000-8000-000000000002']; $transport('before', $bad); $transport('after', $bad); break;
        case 'missing-map': $transport('before-database', str_replace($uuid, '20000000-0000-4000-8000-000000000002', $dump)); break;
        case 'wrong-map-kind': $transport('before-database', str_replace("'widget_polylang'", "'widget_text'", $dump)); break;
        case 'duplicate-map':
            preg_match('/^INSERT INTO `wp_wprism_map`[^\n]+\n/m', $dump, $match);
            $transport('before-database', str_replace($match[0], $match[0] . $match[0], $dump)); break;
        case 'empty-dump': $transport('before-database', ''); break;
        case 'partial-dump': $transport('before-database', substr($dump, 0, -18)); break;
        case 'empty-roster': $transport('before-tables', ''); break;
        case 'wrong-service': $transport('before-database', $dump, 0, " Container wprism-polyoffline-cli1-run-123456789abc Created \n"); break;
        case 'database-exit': $transport('before-database', $dump, 1); break;
        case 'private-exit': $transport('private', $diagnostic, 1); break;
        case 'unrelated-cause': case 'missing-previous':
            $other = $fault === 'unrelated-cause' ? CommandRefusalException::canonicalIdentityRecoveryRequired(new RuntimeException('different cause'))
                : CommandRefusalException::canonicalIdentityRecoveryRequired();
            $otherRaw = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'plan', 'reason_code' => $reason->reasonCode,
                ...PrivateRefusalEvidence::graph($other)];
            $bytes = json_encode($otherRaw, JSON_THROW_ON_ERROR); $bad = json_decode($diagnostic, true);
            $bad['records'][0] = array_replace($bad['records'][0], ['bytes' => strlen($bytes), 'contents_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)]);
            $transport('private', $bad); break;
        case 'no-new-record': $bad = json_decode($diagnostic, true); $bad['new_records'] = 0; $bad['records'] = []; $transport('private', $bad); break;
    }
    wprism_check_throws(static fn() => PolylangUninstallEvidence::verify($scratch, 'polyoffline'), RuntimeException::class,
        "actual uninstall evidence refuses $fault");
}

$shell = file_get_contents(dirname(__DIR__, 2) . '/fixtures/polylang-uninstall-refusal.sh');
$transportProbe = <<<'SH'
set -euo pipefail
. "$1/sandbox/tests/lib/wordpress_cron_window.sh"
. "$1/adapter-packages/polylang/fixtures/polylang-uninstall-refusal.sh"
COMPOSE='fixture_compose -p polyoffline'
fixture_compose() {
  [ "$#" -eq 17 ] && [ "$*" = '-p polyoffline run --rm -T --no-deps --user root --workdir /var/www/html/wp-content/mu-plugins --entrypoint sh cli2 -s -- prepare 11111111111111111111111111111111' ]
}
polylang_uninstall_mu -s -- prepare 11111111111111111111111111111111
SH;
[$transportStatus] = ShellProbe::run($transportProbe, [$root], $root);
wprism_check($transportStatus === 0, 'actual uninstall guard callback binds shared root-owned MU transport to target cli2');
$start = strpos($shell, '  # Always retain postimages');
$end = strpos($shell, "\n)", $start);
if ($start === false || $end === false) throw new RuntimeException('actual uninstall command/collection block is missing');
$block = substr($shell, $start, $end - $start);
$probe = <<<'SH'
set -euo pipefail
root="$1" sink="$2" fault="$3" helper=fixture repo=fixture pair=polyoffline
. "$root/sandbox/tests/lib/private_command_capture.sh"
fail() { printf 'REFUSED\n'; exit 1; }
pass() { printf 'VERIFIED\n'; }
wp_conf2() {
  printf '%s\n' "$1-$2" >>"$sink/order"
  if [ "$1" = wprism ]; then [ "$fault" = command-success ] && return 0; return 1; fi
  [ "$fault" != database-failure ]
}
php() { printf '%s\n' "$2" >>"$sink/order"; [ "$2" != verify ] && [ "$fault" != snapshot-failure ]; }
conformance_private_command_native() { printf '%s\n' private >>"$sink/order"; }
SH;
foreach (['command-success', 'database-failure', 'snapshot-failure'] as $fault) {
    $lane = "$scratch/$fault"; mkdir($lane, 0700);
    [$status] = ShellProbe::run($probe . "\n" . $block, [$root, $lane, $fault], $root);
    $order = file_get_contents("$lane/order");
    wprism_check($status !== 0 && str_contains($order, "snapshot\ndb-query\ndb-export\nprivate\n"),
        "actual uninstall shell retains every postimage and private cause after $fault");
    wprism_check_same($fault === 'command-success' ? "0\n" : "1\n", file_get_contents("$lane/command.exit"),
        "actual uninstall shell keeps the original protected exit after $fault");
}
$caller = file_get_contents(dirname(__DIR__, 2) . '/tests/conformance/check.sh');
$residueStart = strpos($caller, 'DESTRUCTIVE_RESIDUE=$(wp_conf2 eval');
$residueEnd = strpos($caller, "\n\$COMPOSE run", $residueStart);
if ($residueStart === false || $residueEnd === false) throw new RuntimeException('native uninstall residue command/acceptance is missing');
$residueBlock = substr($caller, $residueStart, $residueEnd - $residueStart);
$residueProbe = <<<'SH'
set -euo pipefail
php_binary="$1" owner="$2" fault="$3"
wp_conf2() { [ "$1" = eval ] && [ "$#" -eq 2 ]; "$php_binary" "$owner" native-residue "$fault" "$2"; }
fail() { exit 1; }
SH;
foreach (['healthy', 'retained-option', 'wp_options', 'wp_term_taxonomy', 'wp_postmeta'] as $fault) {
    [$status] = ShellProbe::run($residueProbe . "\n" . $residueBlock, [PHP_BINARY, __FILE__, $fault], $root);
    wprism_check($fault === 'healthy' ? $status === 0 : $status !== 0, "actual native uninstall residue reader classifies $fault");
    if (str_starts_with($fault, 'wp_')) {
        $priorCasting = str_replace('$count("SELECT', '(int) $wpdb->get_var("SELECT', $residueBlock);
        [$priorStatus] = ShellProbe::run($residueProbe . "\n" . $priorCasting, [PHP_BINARY, __FILE__, $fault], $root);
        wprism_check_same(0, $priorStatus, "prior unchecked cast falsely accepted the $fault read failure as zero residue");
    }
}
$call = strpos($caller, "\npolylang_uninstall_refusal_check\n");
wprism_check($call !== false && strpos($caller, 'REINSTALL_DEPLOY=') < $call && strpos($caller, 'STALE_IDENTITY_RC=') > $call
    && !str_contains($caller, 'widget identity history is missing'),
    'native lifecycle executes the complete refusal proof after exact reinstall and before attempted sidecar/database recovery');

// Execute the entire actual evidence window with its real host compiler,
// admission, baseline validator and final verifier. Only native command
// transport is synthetic; exact argv and target binding remain mandatory.
$windowStart = strpos($shell, '  wprism_private_capture_stage "$sink" before php');
if ($windowStart === false) throw new RuntimeException('actual uninstall baseline window is missing');
$window = substr($shell, $windowStart, $end - $windowStart);
$write("$scratch/input-public", json_encode($public, JSON_THROW_ON_ERROR));
$write("$scratch/input-dump", $dump);
$write("$scratch/input-roster", $roster);
$write("$scratch/input-private", $diagnostic);
$fullProbe = <<<'SH'
set -euo pipefail
root="$1" sink="$2" fault="$3" repo="$4" input="$5" php_binary="$6" pair=polyoffline
helper="$root/adapter-packages/polylang/fixtures/polylang_uninstall_evidence.php"
. "$root/sandbox/tests/lib/private_command_capture.sh"
fail() { printf 'REFUSED\n'; exit 1; }
pass() { printf 'VERIFIED\n'; }
php() {
  if [ "$2" = snapshot ] && [ -e "$sink/ran" ] && [ "$fault" = post-snapshot ]; then return 1; fi
  "$php_binary" "$@"
}
wp_conf2() {
  if [ "$1" = wprism ]; then
    [ "$*" = 'wprism plan --repo=/siterepo --format=json' ] || return 64
    printf 'ran\n' >"$sink/ran"
    [ "$fault" != command-success ] || { printf '{}\n'; return 0; }
    cat "$input/input-public"
    return 1
  fi
  if [ "$2" = query ]; then
    [ "$*" = 'db query SHOW FULL TABLES --batch --raw --skip-column-names --quiet' ] || return 64
    [ "$fault" != empty-roster ] || return 0
    cat "$input/input-roster"
  else
    [ "$*" = 'db export - --single-transaction --skip-lock-tables --skip-add-locks --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet' ] || return 64
    [ "$fault" != post-database ] || [ ! -e "$sink/ran" ] || return 1
    cat "$input/input-dump"
  fi
}
conformance_private_command_native() {
  [ "$1" = cli2 ] && [ "$2" = plan ] || return 64
  if [ "$3" = snapshot ]; then
    [ "$fault" != wrong-baseline ] || { printf '{"command":"apply","baseline":"[]"}\n'; return 0; }
    printf '{"command":"plan","baseline":"[]"}\n'
  else
    [ "$3" = collect ] && [ "$4" = "$sink/baseline.stdout" ] || return 64
    cat "$input/input-private"
  fi
}
SH;
foreach (['healthy', 'empty-roster', 'wrong-baseline', 'command-success', 'post-snapshot', 'post-database'] as $fault) {
    $lane = "$scratch/full-$fault"; mkdir($lane, 0700);
    foreach (['before', 'before-tables', 'before-database', 'before-check', 'baseline', 'baseline-check', 'command',
        'after', 'after-tables', 'after-database', 'private', 'verify'] as $stage) {
        foreach (['stdout', 'stderr', 'exit'] as $suffix) $write("$lane/$stage.$suffix", '');
    }
    [$status, $output, $stderr] = ShellProbe::run($fullProbe . "\n" . $window,
        [$root, $lane, $fault, $repo, $scratch, PHP_BINARY], $root);
    wprism_check($fault === 'healthy' ? $status === 0 && $output === "VERIFIED\n" && $stderr === '' : $status !== 0,
        "complete actual uninstall evidence window classifies $fault");
    if (in_array($fault, ['empty-roster', 'wrong-baseline'], true)) {
        wprism_check(!file_exists("$lane/ran") && file_get_contents("$lane/command.exit") === '',
            "invalid $fault premise prevents the protected Plan entirely");
    } else {
        wprism_check_same($fault === 'command-success' ? "0\n" : "1\n", file_get_contents("$lane/command.exit"),
            "complete window retains the original Plan status for $fault");
        wprism_check(file_get_contents("$lane/private.exit") === "0\n" && file_get_contents("$lane/after-tables.exit") === "0\n",
            "complete window retains later native observations even for $fault");
        if ($fault === 'healthy') {
            PolylangUninstallEvidence::verify($lane, 'polyoffline');
            wprism_check(true, 'successful complete caller evidence remains independently re-admissible');
        }
    }
}
wprism_check_summary('regress_polylang_uninstall_evidence');
