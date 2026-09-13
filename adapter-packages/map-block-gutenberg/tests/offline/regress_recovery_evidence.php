<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Publication/Publish.php';
require_once dirname(__DIR__, 2) . '/fixtures/recovery-evidence.php';

use WPrism\Canon;
use WPrism\CapturePublicationRecovery;
use WPrism\CommandRefusalException;
use WPrism\Publish;
use WPrismTest\FakeWpdb;

if (($argv[1] ?? '') === '--crash') {
    [$checkpoint, $state] = array_slice($argv, 2);
    if (!in_array($checkpoint, MapRecoveryEvidence::CHECKPOINTS, true) || basename($state) !== 'state'
        || basename(dirname($state)) !== $checkpoint || preg_match('/^map-recovery-[a-f0-9]{24}$/D', basename(dirname($state, 2))) !== 1
        || !function_exists('posix_kill')) throw new RuntimeException('owned crash-child premise differs');
    putenv('WPRISM_TEST_MODE=1');
    putenv('WPRISM_TEST_CAPTURE_KILL_PHASE=' . $checkpoint);
    $lock = Publish::lock($state);
    $intent = Publish::begin_intent($state, Publish::stage_dir($state));
    Publish::swap($state, true);
    $intent = Publish::mark_swapped($state, $intent);
    $intent = Publish::mark_commit_ready($state, $intent);
    $intent = Publish::mark_committing($state, $intent);
    Publish::write_receipt($state, $intent);
    throw new RuntimeException('publication checkpoint did not terminate its owned child');
}

require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Capture/CapturePublicationRecovery.php';
wprism_test_define_agent_versions();
$scratch = sys_get_temp_dir() . '/map-recovery-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$library = \WPrism\AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg');
$policy = \WPrism\Policy::load(null, ['core', 'map-block-gutenberg'], adapterLibrary: $library);
$policy->site['spec_version'] = WPRISM_SPEC_VERSION;
$tokens = new \WPrism\Tokens('https://source.example.test', 'https://source.example.test/wp-content/uploads');
$native = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/saved-default-key.html');
$old = \WPrism\Blocks::capture_rewrite($native, $policy, $tokens);
$new = \WPrism\Blocks::capture_rewrite(str_replace(['"height":420', 'height="420px"'], ['"height":430', 'height="430px"'], $native, $replacements), $policy, $tokens);
wprism_check_same(2, $replacements, 'crash candidate changes both native saver representations');
$uuid = '11111111-1111-4111-8111-111111111111';
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-12 00:00:00',
    'date_gmt' => '2026-09-12 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-12 00:00:00', 'modified_gmt' => '2026-09-12 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'map', 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Map', 'type' => 'page', 'uuid' => $uuid];
$relative = '/posts/page/' . $uuid . '--map.md';
$db = FakeWpdb::install();
$kv = $db->prefix . 'wprism_kv';
$db->seedTable($kv, []);
$db->seedTable($db->options, [['option_id' => 1, 'option_name' => 'gmw-map-block-key', 'option_value' => 'map-fixture-target-key', 'autoload' => 'off']]);
$options = $db->rows($db->options);
$failures = [];
$inventory = static function (string $directory): array {
    $rows = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) $rows[substr($entry->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $entry->getPathname());
    ksort($rows, SORT_STRING);
    return $rows;
};

foreach (MapRecoveryEvidence::CHECKPOINTS as $checkpoint) {
    $repo = $scratch . '/' . $checkpoint;
    $state = $repo . '/state';
    $staging = Publish::stage_dir($state);
    Canon::write_file($state . $relative, Canon::post_file($front, $old));
    Canon::write_file($staging . $relative, Canon::post_file($front, $new));
    $prior = Publish::tree_digest($state);
    $candidate = Publish::tree_digest($staging);
    wprism_check($prior !== $candidate, "$checkpoint has genuinely different credential-free generations");
    wprism_check(isset(\WPrism\RepositoryCompiler::compile($repo, $policy)->tree()[$uuid]), "$checkpoint prior tree is a real compiled Map entity");
    $stdout = tmpfile();
    $stderr = tmpfile();
    if ($stdout === false || $stderr === false) throw new RuntimeException('private crash transport allocation failed');
    $process = proc_open([PHP_BINARY, __FILE__, '--crash', $checkpoint, $state], [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $root, ['PATH' => (string) getenv('PATH')]);
    if (!is_resource($process)) throw new RuntimeException('owned crash child did not start');
    fclose($pipes[0]);
    $deadline = hrtime(true) + 10000000000;
    do { $status = proc_get_status($process); if (!$status['running']) break; usleep(10000); } while (hrtime(true) < $deadline);
    if ($status['running']) { proc_terminate($process, 9); proc_close($process); throw new RuntimeException('owned crash child timed out'); }
    proc_close($process);
    rewind($stdout);
    rewind($stderr);
    wprism_check($status['signaled'] && $status['termsig'] === 9, "$checkpoint terminates by real SIGKILL, not a synthetic exit");
    wprism_check_same('', stream_get_contents($stdout), "$checkpoint publishes no success output");
    wprism_check_same('', stream_get_contents($stderr), "$checkpoint has no unrelated child diagnostic");
    fclose($stdout);
    fclose($stderr);
    $intent = Canon::decode((string) file_get_contents(Publish::intent_path($state)));
    // The child above exercises the filesystem journal. Only native capture
    // can establish SQL COMMIT; this offline leg supplies that explicit fact
    // through row-backed Ledger storage and the real marker reader.
    $marker = $checkpoint === 'receipt-written' ? Canon::decode(CapturePublicationRecovery::marker($state, $intent)) : null;
    $db->seedTable($kv, $marker === null ? [] : [['k' => CapturePublicationRecovery::markerKey($state), 'v' => Canon::encode($marker)]]);
    $observation = ['format' => 'wprism-map-recovery-observation/v1', 'checkpoint' => $checkpoint,
        'prior_sha256' => $prior, 'candidate_sha256' => $candidate, 'live_sha256' => Publish::tree_digest($state),
        'staging_sha256' => is_dir($staging) ? Publish::tree_digest($staging) : null,
        'backup_sha256' => is_dir(Publish::backup_dir($state)) ? Publish::tree_digest(Publish::backup_dir($state)) : null,
        'intent' => $intent, 'receipt' => is_file(Publish::receipt_path($state)) ? Canon::decode((string) file_get_contents(Publish::receipt_path($state))) : null,
        'marker' => $marker, 'destination_sha256' => CapturePublicationRecovery::destinationSha256($state), 'transitions' => []];
    MapRecoveryEvidence::assertCrash($observation, $checkpoint);
    wprism_check(true, "$checkpoint independent witness admits the actual retained journal");
    foreach (['same-generation', 'wrong-placement', 'bad-intent', 'missing-intent', 'foreign-destination', 'extra-field'] as $mutation) {
        $bad = $observation;
        if ($mutation === 'same-generation') $bad['prior_sha256'] = $candidate;
        if ($mutation === 'wrong-placement') $bad['live_sha256'] = str_repeat('f', 64);
        if ($mutation === 'bad-intent') $bad['intent']['record_sha256'] = str_repeat('0', 64);
        if ($mutation === 'missing-intent') $bad['intent'] = null;
        if ($mutation === 'foreign-destination') $bad['destination_sha256'] = 'invalid';
        if ($mutation === 'extra-field') $bad['private'] = 'map-fixture-target-key';
        wprism_check_throws(static fn() => MapRecoveryEvidence::assertCrash($bad, $checkpoint), RuntimeException::class, "$checkpoint rejects $mutation evidence");
    }
    $lock = Publish::lock($state);
    try {
        $recordPath = $checkpoint === 'receipt-written' ? Publish::receipt_path($state) : Publish::intent_path($state);
        $retained = (string) file_get_contents($recordPath);
        $badRecord = Canon::decode($retained);
        $badRecord['record_sha256'] = str_repeat('0', 64);
        Canon::write_file($recordPath, Canon::encode($badRecord));
        $tampered = $inventory($repo);
        try {
            Publish::recover($state, static fn(array $found): bool => CapturePublicationRecovery::commitStatus($state, $found));
            wprism_check(false, "$checkpoint tampered publication must refuse");
        } catch (CommandRefusalException $failure) {
            wprism_check_same('capture_recovery_ambiguous', $failure->reasonCode, "$checkpoint tampered record refuses without guessing");
            $failures[$checkpoint === 'receipt-written' ? 'receipt' : 'intent'] = $failure;
        }
        wprism_check_same($tampered, $inventory($repo), "$checkpoint refusal retains every recovery file byte");
        Canon::write_file($recordPath, $retained);
        if ($marker !== null) {
            $badMarker = $marker;
            $badMarker['candidate_sha256'] = str_repeat('f', 64);
            unset($badMarker['record_sha256']);
            $badMarker['record_sha256'] = hash('sha256', Canon::encode($badMarker));
            $db->seedTable($kv, [['k' => CapturePublicationRecovery::markerKey($state), 'v' => Canon::encode($badMarker)]]);
            $retainedTree = $inventory($repo);
            try {
                Publish::recover($state, static fn(array $found): bool => CapturePublicationRecovery::commitStatus($state, $found));
                wprism_check(false, 'coherently rehashed but contradictory commit proof must refuse');
            } catch (CommandRefusalException $failure) {
                wprism_check_same('capture_recovery_ambiguous', $failure->reasonCode, 'current-intent marker mismatch cannot authorize receipt recovery');
                $failures['marker'] = $failure;
            }
            wprism_check_same($retainedTree, $inventory($repo), 'contradictory commit proof preserves every recovery file');
            $db->seedTable($kv, [['k' => CapturePublicationRecovery::markerKey($state), 'v' => Canon::encode($marker)]]);
        }
        $recovered = Publish::recover($state, static fn(array $found): bool => CapturePublicationRecovery::commitStatus($state, $found));
        wprism_check_same(MapRecoveryEvidence::warnings($checkpoint, $state), $recovered, "$checkpoint recovery reports exactly its justified choice");
        wprism_check_same($checkpoint === 'receipt-written' ? $candidate : $prior, Publish::tree_digest($state), "$checkpoint recovery keeps the transaction-authorized generation");
        wprism_check_same([], Publish::recover($state, static fn(array $found): bool => CapturePublicationRecovery::commitStatus($state, $found)), "$checkpoint repeated recovery is a clean no-op");
        wprism_check(!file_exists(Publish::intent_path($state)) && !is_dir($staging) && !is_dir(Publish::backup_dir($state)), "$checkpoint consumes only completed recovery sidecars");
        wprism_check_same($options, $db->rows($db->options), "$checkpoint recovery preserves the target credential row");
        wprism_check(isset(\WPrism\RepositoryCompiler::compile($repo, $policy)->tree()[$uuid]), "$checkpoint recovered Map entity still compiles");
    } finally { Publish::unlock($lock); }
}

final class MapRecoveryCliExit extends RuntimeException {}
final class WP_CLI {
    public static string $output = '';
    public static function add_command(string $name, string $handler): void {}
    public static function line(string $line): void { self::$output .= $line . "\n"; }
    public static function halt(int $status): never { throw new MapRecoveryCliExit((string) $status); }
}
require_once $root . '/agent/src/Command/Cli.php';
wprism_check_same(['intent', 'receipt', 'marker'], array_keys($failures), 'all three hostile recovery gates produced actual exceptions');
foreach ($failures as $kind => $failure) {
    wprism_check_same(MapRecoveryEvidence::refusalProfile($kind)['nodes'], [['parent_index' => null, 'relation' => 'root',
        'class' => get_class($failure), 'message' => $failure->getMessage()]], "$kind private profile matches the complete actual cause");
    wprism_check_same(null, $failure->getPrevious(), "$kind has no omitted private cause");
    WP_CLI::$output = '';
    try {
        (new ReflectionMethod(\WPrism\Cli::class, 'halt_json_failure'))->invoke(null, $failure, ['format' => 'json'], 'capture');
        wprism_check(false, "$kind real formatter must halt");
    } catch (MapRecoveryCliExit $exit) { wprism_check_same('1', $exit->getMessage(), "$kind real formatter exits one"); }
    wprism_check_same(MapRecoveryEvidence::publicRefusal(), json_decode(WP_CLI::$output, true, 32, JSON_THROW_ON_ERROR), "$kind complete expected public envelope matches the real formatter");
}
$transport = $scratch . '/transport';
mkdir($transport, 0700);
foreach (['crash', ...MapRecoveryEvidence::CHECKPOINTS, 'clean'] as $checkpoint) {
    $answer = ['counts' => ['post' => 2, 'term' => 0, 'menu' => 0, 'sidebar' => 0, 'options' => 1, 'deletion' => 0],
        'media' => 0, 'notes' => [], 'warnings' => in_array($checkpoint, ['clean', 'crash'], true) ? [] : MapRecoveryEvidence::warnings($checkpoint),
        'state_dir' => '/siterepo/state', 'revision_hash' => str_repeat('a', 64), 'initial_code_baseline' => null, 'initial_publication_cleanup' => 'not-applicable'];
    foreach (['valid', 'wrong-exit', 'stdout-leak', 'stderr-leak', 'wrong-pair', 'missing-warnings', 'unexpected-warning', 'missing-revision', 'extra-field'] as $mutation) {
        $candidate = $answer;
        if ($mutation === 'missing-warnings') unset($candidate['warnings']);
        if ($mutation === 'unexpected-warning') $candidate['warnings'][] = 'map-fixture-target-key';
        if ($mutation === 'missing-revision') $candidate['revision_hash'] = null;
        if ($mutation === 'extra-field') $candidate['private'] = 'map-fixture-target-key';
        $bytes = $checkpoint === 'crash' ? '' : json_encode($candidate, JSON_THROW_ON_ERROR);
        // A crash has no public object: every alleged object/warning is
        // unrelated output, not proof that the kill checkpoint was reached.
        if ($checkpoint === 'crash' && in_array($mutation, ['missing-warnings', 'unexpected-warning', 'missing-revision', 'extra-field'], true)) $bytes = json_encode($candidate, JSON_THROW_ON_ERROR);
        if ($mutation === 'stdout-leak') $bytes = 'map-fixture-target-key' . $bytes;
        foreach (['stdout' => $bytes, 'stderr' => $mutation === 'stderr-leak' ? 'map-fixture-target-key' : " Container wprism-fixture-cli2-run-aaaaaaaaaaaa Created \n",
            'exit' => ($mutation === 'wrong-exit' ? 1 : ($checkpoint === 'crash' ? 137 : 0)) . "\n"] as $suffix => $contents) {
            file_put_contents("$transport/command.$suffix", $contents);
            chmod("$transport/command.$suffix", 0600);
            clearstatcache(true, "$transport/command.$suffix");
        }
        $check = static fn() => MapRecoveryEvidence::assertTransport($checkpoint, $mutation === 'wrong-pair' ? 'foreign' : 'fixture', "$transport/command");
        if ($mutation === 'valid') { $check(); wprism_check(true, "$checkpoint complete transport admitted"); }
        else wprism_check_throws($check, Throwable::class, "$checkpoint $mutation cannot count as recovery evidence");
    }
}
wprism_check_summary('map-block-gutenberg recovery evidence');
