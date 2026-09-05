<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/ShellProbe.php';
require_once __DIR__ . '/../../lib/PrivateRefusalReceipt.php';
require_once __DIR__ . '/../../fixtures/core-ssh-deletion.php';

use WPrismTest\CoreSshDeletionFixture as Fixture;
use WPrismTest\FakeWpdb;
use WPrismTest\ShellProbe;
use WPrismTest\WpStore;

$root = dirname(__DIR__, 4);
if (($argv[1] ?? null) === '--metadata') {
    $fixtureSource = file_get_contents($argv[2] . '/sandbox/tests/fixtures/core-ssh-deletion.php');
    $start = strpos($fixtureSource, '$metadata = wp_generate_attachment_metadata(');
    $end = strpos($fixtureSource, '$paths = [$metadata[\'file\']];', $start);
    if ($start === false || $end === false) {
        throw new LogicException('the real metadata seed acceptance block is unavailable');
    }
    $mode = $argv[3];
    $metadataFixture = ['file' => '2026/09/original.png',
        'sizes' => ['thumbnail' => ['file' => 'original-150x150.png', 'width' => 150, 'height' => 150]]];
    if ($mode === 'no derivatives') {
        $metadataFixture['sizes'] = [];
    }
    $wpdb = FakeWpdb::install();
    $updateCalls = 0;
    // Model only the native API boundary: generation has already persisted
    // its data and WordPress's final identical update returns false. The real
    // seed acceptance below reads actual FakeWpdb rows, not a canned answer.
    function wp_generate_attachment_metadata(int $id, string $file): array {
        global $wpdb, $metadataFixture;
        $wpdb->seedTable('wp_postmeta', [['meta_id' => 1, 'post_id' => $id,
            'meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize($metadataFixture)]]);
        return $metadataFixture;
    }
    function wp_update_attachment_metadata(int $id, array $metadata): mixed {
        global $wpdb, $mode, $updateCalls;
        $updateCalls++;
        $row = ['meta_id' => 1, 'post_id' => $id, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize($metadata)];
        if (in_array($mode, ['false stale', 'true stale'], true)) {
            $wpdb->seedTable('wp_postmeta', [array_replace($row, ['meta_value' => 'stale'])]);
        } elseif ($mode === 'missing') {
            $wpdb->seedTable('wp_postmeta', []);
        } elseif ($mode === 'duplicate') {
            $wpdb->seedTable('wp_postmeta', [$row, array_replace($row, ['meta_id' => 2])]);
        } elseif ($mode === 'write error') {
            $wpdb->last_error = 'injected metadata write failure';
        } elseif ($mode === 'read error') {
            $wpdb->failNextQuery('injected metadata read failure', 'SELECT LEFT(meta_key,24)');
        } elseif ($mode === 'malformed read') {
            $wpdb->returnNextGetResultsAs([['meta_key' => '_wp_attachment_metadata']], 'SELECT LEFT(meta_key,24)');
        }
        return match ($mode) {
            'unchanged', 'false stale', 'write error' => false,
            'new meta id' => 19,
            'invalid return' => null,
            default => true,
        };
    }
    $ids = ['attachment' => 13];
    $upload = ['file' => '/native/fixture.png'];
    $block = substr($fixtureSource, $start, $end - $start);
    $run = Closure::bind(eval('return static function () use ($ids, $upload) { global $wpdb; ' . $block . 'return $metadata; };'), null, Fixture::class);
    try {
        $result = $run();
        echo json_encode(['accepted' => true, 'update_calls' => $updateCalls, 'metadata' => $result], JSON_THROW_ON_ERROR) . "\n";
    } catch (RuntimeException $failure) {
        echo json_encode(['accepted' => false, 'update_calls' => $updateCalls, 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR) . "\n";
        exit(1);
    }
    exit(0);
}
$metadataSourceRoot = $argv[1] ?? $root;
foreach (['unchanged', 'changed', 'new meta id', 'false stale', 'true stale', 'missing', 'duplicate',
    'write error', 'read error', 'malformed read', 'invalid return', 'no derivatives'] as $mode) {
    $process = proc_open([PHP_BINARY, __FILE__, '--metadata', $metadataSourceRoot, $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('metadata seed child could not start');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $answer = json_decode($out, true);
    $accepted = in_array($mode, ['unchanged', 'changed', 'new meta id'], true);
    wprism_check_same('', $err, "$mode metadata acceptance has no PHP/fixture infrastructure failure");
    wprism_check_same($accepted ? 0 : 1, $exit, "$mode metadata acceptance agrees with durable storage, not the setter boolean");
    wprism_check_same($accepted, $answer['accepted'] ?? null, "$mode metadata answer agrees with exit status");
    wprism_check_same($mode === 'no derivatives' ? 0 : 1, $answer['update_calls'] ?? null, "$mode executes the actual metadata update boundary exactly once after valid generation");
}
$source = file_get_contents($root . '/sandbox/tests/live/regress_core_ssh_deletion.sh');
$parent = file_get_contents($root . '/sandbox/tests/live/regress_ssh_adopt.sh');
if (!is_string($source) || !is_string($parent)
    || preg_match('/^assert_ssh_fixture_positive_diagnostics\(\).*?^}/ms', $parent, $match) !== 1) {
    throw new RuntimeException('shared SSH owner source is unavailable');
}
$setup = <<<'SH'
set -euo pipefail
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
ROOT="$1"
. "$ROOT/sandbox/conformance/asserts.sh"
. "$ROOT/sandbox/tests/live/regress_core_ssh_deletion.sh"
DIAG_DIR=$(mktemp -d "${TMPDIR:-/tmp}/wprism-core-ssh-contract.XXXXXX")
trap 'rm -rf -- "$DIAG_DIR"' EXIT
SH;
$setup .= "\n" . $match[0] . "\n";
$encode = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

// Execute the live owner's real whole-stream capture/acceptance. An expected
// refusal must not be substituted for successful Apply; normal host receipts
// and complete JSON still remain a healthy control.
$apply = ['warnings' => [], 'canary' => 'clean', 'verification' => ['result' => 'pass']];
$refusal = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'apply',
    'error' => 'apply_failed', 'reason_code' => 'apply_failed', 'details_redacted' => true,
    'message' => 'apply refused at an unclassified safety gate'];
$host = "promote phase: compile\npromote profile: automatic verified rollback\npromote phase: apply\n";
$terminal = "\npromote complete: verified committed receipt; traffic exclusion released\n";
$captureCases = [
    'healthy complete signed Apply' => ['apply', $host . $encode($apply) . $terminal, '', 0, true],
    'empty successful transport' => ['apply', '', '', 0, false],
    'nonzero with green Apply' => ['apply', $host . $encode($apply) . $terminal, '', 7, false],
    'missing host terminal' => ['apply', $host . $encode($apply), '', 0, false],
    'duplicated host terminal' => ['apply', $host . $encode($apply) . $terminal . $terminal, '', 0, false],
    'additional JSON answer' => ['apply', $host . $encode($apply) . "\n{}" . $terminal, '', 0, false],
    'unknown stdout prelude' => ['apply', "private-capture-canary\n" . $host . $encode($apply) . $terminal, '', 0, false],
    'PHP startup stderr' => ['apply', $host . $encode($apply) . $terminal, 'PHP Warning: private-capture-canary in Unknown on line 0', 0, false],
    'PHP stdout before answer' => ['apply', 'PHP Notice: private-capture-canary in /private/test.php on line 1' . "\n" . $host . $encode($apply) . $terminal, '', 0, false],
    'required env only on stderr' => ['apply', $host . $encode($apply) . $terminal, 'env_missing: private-capture-canary', 0, false],
    'serialized required env' => ['apply', $host . $encode(array_replace($apply, ['warnings' => ['env_missing: private-capture-canary']])) . $terminal, '', 0, false],
    'dirty canary' => ['apply', $host . $encode(array_replace($apply, ['canary' => 'dirty'])) . $terminal, '', 0, false],
    'failed verification' => ['apply', $host . $encode(array_replace($apply, ['verification' => ['result' => 'fail']])) . $terminal, '', 0, false],
    'refusal pretending to be Apply' => ['apply', $host . $encode($refusal) . $terminal, '', 0, false],
    'expected public FK refusal' => ['refusal', $host . $encode($refusal), 'wprism: promote: apply failed; entering signed verified rollback', 1, true],
    'zero exit with expected refusal' => ['refusal', $host . $encode($refusal), '', 0, false],
    'wrong refusal category' => ['refusal', $host . $encode(array_replace($refusal, ['reason_code' => 'other_failed'])), '', 1, false],
    'unredacted expected refusal' => ['refusal', $host . $encode(array_replace($refusal, ['details_redacted' => false])), '', 1, false],
    'expected refusal with successful host claim' => ['refusal', $host . $encode($refusal) . $terminal, '', 1, false],
];
foreach ($captureCases as $label => [$mode, $stdout, $stderr, $exit, $ok]) {
    $script = $setup . <<<'SH'
fixture_command() { printf '%s' "$3"; printf '%s' "$4" >&2; return "$5"; }
umask 022
core_ssh_capture ANSWER witness "$2" fixture_command "$@"
[ "$(umask)" = 0022 ] || fail 'private capture leaked its umask'
php -r 'foreach(array_slice($argv,1) as $p) { if ((fileperms($p)&0777)!==0600) exit(1); }' "$DIAG_DIR"/*
[ "$(cat "$DIAG_DIR/core-delete-witness.exit")" = "$5" ] || fail 'capture exit was not retained'
printf 'ACCEPTED\n'
SH;
    [$code, $out, $err] = ShellProbe::run($script, [$root, $mode, $stdout, $stderr, (string) $exit], $root);
    if ($ok) {
        wprism_check_same('', $err, $label . ' has no unexpected public diagnostic');
    }
    wprism_check($ok ? $code === 0 && str_contains($out, 'ACCEPTED') : $code !== 0 && !str_contains($out, 'ACCEPTED'), $label . ' has truthful exit/envelope acceptance');
    wprism_check(!str_contains($out . $err, 'private-capture-canary'), $label . ' never publishes private diagnostic bytes');
}
$script = $setup . <<<'SH'
trap 'test "$(cat "$DIAG_DIR/core-delete-exit-helper.exit")" = 7 && printf "PARENT_CLEANUP_VISIBLE\n"; rm -rf -- "$DIAG_DIR"' EXIT
fixture_exits() { printf 'private-capture-canary\n'; exit 7; }
core_ssh_capture ANSWER exit-helper human fixture_exits
printf 'INCORRECT_SUCCESS\n'
SH;
[$code, $out, $err] = ShellProbe::run($script, [$root], $root);
wprism_check($code !== 0 && str_contains($out, 'PARENT_CLEANUP_VISIBLE') && !str_contains($out, 'INCORRECT_SUCCESS'), 'helper exit is captured as its exact numeric status before visible parent cleanup');
wprism_check(!str_contains($out . $err, 'private-capture-canary'), 'helper exit never redirects private bytes or parent cleanup into the wrong public stream');

// This observer shares the real wpdb interpreter, not an answer-list double.
// Its checked rows and filesystem witnesses are also the exact values used
// by the actual live pre/postimage jq acceptance below.
$store = WpStore::reset();
$store->ensureUploadDir();
$store->uploadBaseDir = (string) realpath($store->uploadBaseDir);
mkdir($store->uploadBaseDir . '/2026', 0700);
mkdir($store->uploadBaseDir . '/2026/09', 0700);
$uploads = ['2026/09/original.png', '2026/09/original-150x150.png'];
foreach ($uploads as $path) {
    file_put_contents($store->uploadBaseDir . '/' . $path, 'native-upload-witness-' . $path);
}
$context = [
    'ids' => ['page' => 11, 'post' => 12, 'attachment' => 13],
    'comment' => 21, 'revisions' => [14], 'uploads' => $uploads,
    'uuids' => ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'],
];
$tables = [
    'wp_posts' => [
        ['ID' => 11, 'post_type' => 'page', 'post_parent' => 0],
        ['ID' => 12, 'post_type' => 'post', 'post_parent' => 0],
        ['ID' => 13, 'post_type' => 'attachment', 'post_parent' => 0],
        ['ID' => 14, 'post_type' => 'revision', 'post_parent' => 12],
    ],
    'wp_postmeta' => array_map(static fn(int $id): array => ['meta_id' => $id, 'post_id' => $id, 'meta_key' => '_core_ssh_delete_note', 'meta_value' => 'native metadata'], [11, 12, 13, 14]),
    'wp_term_relationships' => [['object_id' => 12, 'term_taxonomy_id' => 31, 'term_order' => 0]],
    'wp_comments' => [['comment_ID' => 21, 'comment_post_ID' => 11, 'comment_content' => 'Runtime comment survives explicit page deletion.']],
    'wp_commentmeta' => [['meta_id' => 1, 'comment_id' => 21, 'meta_key' => 'core_ssh_runtime_note', 'meta_value' => 'runtime metadata']],
    'wp_options' => array_map(static fn(int $id, string $name): array => ['option_id' => $id, 'option_name' => $name, 'option_value' => 'preserved-' . $name, 'autoload' => 'yes'], [1, 2, 3, 4], ['admin_email', 'home', 'siteurl', 'scoped-apply_scoped_option']),
    'wp_wprism_map' => array_map(static fn(string $uuid, int $id): array => ['uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id], $context['uuids'], [11, 12, 13]),
    'wp_wprism_state' => array_map(static fn(string $uuid): array => ['uuid' => $uuid, 'entity_type' => 'post', 'content_hash' => str_repeat('a', 64)], $context['uuids']),
];
$seed = static function (?FakeWpdb $db = null) use ($tables): FakeWpdb {
    $db ??= new FakeWpdb();
    $GLOBALS['wpdb'] = $db;
    foreach ($tables as $name => $rows) {
        $db->seedTable($name, $rows);
    }
    return $db;
};
$db = $seed();
$before = Fixture::observe($context);
wprism_check(count($before['posts']) === 4 && count($before['uploads']) === 2, 'actual native observer retains non-vacuous rows and original/derivative hashes');
wprism_check(array_filter($db->queries(), static fn(string $sql): bool => preg_match('/\A(?:INSERT|UPDATE|DELETE|CREATE|DROP)\b/i', $sql) === 1) === [], 'actual observer performs no database mutations');
$queries = $db->queries();
foreach ($queries as $index => $query) {
    $db = $seed();
    $db->failNextQuery('private-native-read-canary', $query);
    $failure = null;
    try {
        Fixture::observe($context);
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    wprism_check($failure instanceof RuntimeException
        && str_starts_with($failure->getMessage(), 'core SSH deletion fixture:')
        && !str_contains($failure->getMessage(), 'private-native-read-canary'), "native observation read $index refuses immediately and value-free");
    wprism_check_same($query, $db->queries()[array_key_last($db->queries())], "native observation read $index cannot be erased by a later query");
}

final class CoreWitnessShapeWpdb extends FakeWpdb {
    public mixed $shape;
    public function get_results(string $query, string $output = OBJECT): array|false|null {
        $rows = parent::get_results($query, $output);
        return str_starts_with($query, 'SELECT * FROM wp_posts ') ? $this->shape : $rows;
    }
}
foreach ([null, false, ['key' => ['ID' => '11']], [[]], [['ID' => '11']], [['ID' => 11, 'post_type' => 'page', 'post_parent' => '0']], array_fill(0, 4097, ['ID' => '11', 'post_type' => 'page', 'post_parent' => '0'])] as $index => $shape) {
    $shapeDb = new CoreWitnessShapeWpdb();
    $shapeDb->shape = $shape;
    $seed($shapeDb);
    wprism_check_throws(fn() => Fixture::observe($context), RuntimeException::class, "native observer shape $index is not a stable empty hash");
}
$db = $seed();
$db->seedTable('wp_coreXsshXdeleteXfk', [['id' => 1, 'post_id' => 12]]);
wprism_check_same(null, Fixture::observe($context)['fk'], 'escaped exact presence probe does not confuse underscores with LIKE wildcards');
$db->seedTable('wp_core_ssh_delete_fk', [['id' => 1, 'post_id' => 12]]);
wprism_check_same([['id' => '1', 'post_id' => '12']], Fixture::observe($context)['fk'], 'present FK table requires its exact checked child rows');
$db->failNextQuery('private-native-read-canary', 'SELECT id,post_id FROM wp_core_ssh_delete_fk ORDER BY id');
wprism_check_throws(fn() => Fixture::observe($context), RuntimeException::class, 'FK child read failure is not accepted as an empty child table');
$db = $seed();
$nativeFile = $store->uploadBaseDir . '/' . $uploads[0];
rename($nativeFile, $nativeFile . '.retained');
wprism_check_throws(fn() => Fixture::observe($context), RuntimeException::class, 'missing original is not successful attachment file preservation');
symlink($nativeFile . '.retained', $nativeFile);
wprism_check_throws(fn() => Fixture::observe($context), RuntimeException::class, 'linked original is not successful attachment file preservation');
unlink($nativeFile);
rename($nativeFile . '.retained', $nativeFile);
$db->seedTable('wp_postmeta', [['meta_id' => 11, 'post_id' => 11, 'meta_key' => '_core_ssh_delete_note', 'meta_value' => "\xff"]]);
wprism_check_throws(fn() => Fixture::observe($context), JsonException::class, 'invalid UTF-8 cannot become a successful empty JSON hash');

$postimage = $before;
foreach (['posts', 'postmeta', 'relationships', 'children', 'map'] as $field) {
    $postimage[$field] = [];
}
foreach ($postimage['state'] as &$row) {
    $row['entity_type'] = 'deletion';
}
unset($row);
$oracles = [
    ['preimage', $before, true, 'healthy native preimage'],
    ['postimage', $postimage, true, 'healthy exact deletion with uploads preserved'],
];
foreach (['posts', 'postmeta', 'relationships', 'children', 'comments', 'commentmeta', 'uploads', 'options', 'state', 'map'] as $field) {
    $changed = $before;
    $changed[$field] = [];
    $oracles[] = ['preimage', $changed, false, 'vacuous preimage ' . $field];
}
foreach (['posts', 'postmeta', 'relationships', 'children', 'map'] as $field) {
    $changed = $postimage;
    $changed[$field] = $before[$field];
    $oracles[] = ['postimage', $changed, false, 'retained native deletion rows ' . $field];
}
foreach (['comments', 'commentmeta', 'uploads', 'options', 'state'] as $field) {
    $changed = $postimage;
    $changed[$field] = [];
    $oracles[] = ['postimage', $changed, false, 'lost preserved witness ' . $field];
}
$changed = $postimage;
$changed['uploads'][$uploads[0]] = str_repeat('f', 64);
$oracles[] = ['postimage', $changed, false, 'changed original bytes'];
$changed = $postimage;
$changed['comments'][0]['comment_post_ID'] = '12';
$oracles[] = ['postimage', $changed, false, 'reparented runtime comment'];
foreach ($oracles as [$which, $observation, $ok, $label]) {
    $script = $setup . ($which === 'preimage'
        ? 'core_ssh_assert_preimage "$2" "$3"'
        : 'core_ssh_assert_postimage "$2" "$4" "$3"') . "\nprintf 'ACCEPTED\\n'\n";
    [$code, $out] = ShellProbe::run($script, [$root, $encode($context), $encode($observation), $encode($before)], $root);
    wprism_check($ok ? $code === 0 && str_contains($out, 'ACCEPTED') : $code !== 0 && !str_contains($out, 'ACCEPTED'), 'actual live oracle: ' . $label);
}

$plan = ['warnings' => [], 'code_mismatch' => [], 'provider_problems' => [], 'env_missing' => [],
    'delete' => [
        ['uuid' => $context['uuids'][0], 'deletion_type' => 'page', 'blocked' => 'comments reference this page'],
        ['uuid' => $context['uuids'][2], 'deletion_type' => 'attachment'],
    ],
    'delete_conflict' => [['uuid' => $context['uuids'][1], 'conflict_view' => ['reason_code' => 'target_changed_since_delete_base']]],
];
$forced = ['warnings' => ['FORCED delete of guarded post ' . $context['uuids'][0] . ': wp_comments',
    'FORCED deletion conflict ' . $context['uuids'][1]], 'forced_overrides' => [[
        'format' => 'wprism-forced-plan-override/v1', 'plan_bucket' => 'delete_conflict',
        'entity_identity_sha256' => hash('sha256', $context['uuids'][1]),
        'reason_code' => 'target_changed_since_delete_base', 'status' => 'authorized',
        'required_flags' => ['--with-deletes', '--force-theirs'],
        'supplied_flags' => ['--with-deletes', '--force-theirs'],
    ]]];
$fixed = ['warnings' => [], 'env_missing' => [], 'deleted' => array_map(static fn(string $uuid): array => ['uuid' => $uuid], $context['uuids'])];
foreach (['create', 'update', 'adopt', 'drift', 'conflict', 'delete', 'delete_conflict', 'code_mismatch', 'code_drift', 'selected_actions', 'provider_problems'] as $bucket) {
    $fixed[$bucket] = [];
}
$machineCases = [['plan', $plan, true, 'exact three-intent plan'], ['forced', $forced, true, 'exact named guard and local-edit authority'], ['fixed_point', $fixed, true, 'exact three-tombstone fixed point']];
$changed = $plan;
$changed['delete'][0]['uuid'] = $context['uuids'][1];
$machineCases[] = ['plan', $changed, false, 'comment guard attached to wrong page'];
$changed = $plan;
unset($changed['delete'][0]['blocked']);
$machineCases[] = ['plan', $changed, false, 'missing actual comment guard'];
$changed = $plan;
$changed['delete_conflict'][0]['conflict_view']['reason_code'] = 'target_without_last_synced_base';
$machineCases[] = ['plan', $changed, false, 'different local-edit premise'];
$changed = $plan;
$changed['delete'][] = ['uuid' => 'unexpected'];
$machineCases[] = ['plan', $changed, false, 'extra deletion intent'];
foreach (['warnings', 'forced_overrides'] as $field) {
    $changed = $forced;
    $changed[$field] = [];
    $machineCases[] = ['forced', $changed, false, 'missing forced evidence ' . $field];
}
$changed = $forced;
$changed['warnings'][0] = 'FORCED delete of guarded post ' . $context['uuids'][1] . ': wp_comments';
$machineCases[] = ['forced', $changed, false, 'wrong identity guard override'];
foreach (['entity_identity_sha256' => str_repeat('b', 64), 'status' => 'incomplete', 'supplied_flags' => ['--force-theirs']] as $field => $value) {
    $changed = $forced;
    $changed['forced_overrides'][0][$field] = $value;
    $machineCases[] = ['forced', $changed, false, 'incorrect forced authority ' . $field];
}
foreach (array_keys($fixed) as $field) {
    $changed = $fixed;
    $changed[$field] = match ($field) {
        'warnings' => ['env_missing: private-capture-canary'],
        'env_missing' => [['required' => true]],
        'deleted' => [],
        default => [['uuid' => 'unexpected']],
    };
    $machineCases[] = ['fixed_point', $changed, false, 'nonconverged bucket ' . $field];
}
foreach ($machineCases as [$which, $answer, $ok, $label]) {
    [$code, $out] = ShellProbe::run($setup . 'core_ssh_assert_' . $which . ' "$2" "$3"' . "\nprintf 'ACCEPTED\\n'\n", [$root, $encode($context), $encode($answer)], $root);
    wprism_check($ok ? $code === 0 && str_contains($out, 'ACCEPTED') : $code !== 0 && !str_contains($out, 'ACCEPTED'), 'actual machine oracle: ' . $label);
}

foreach (['success', 'retry'] as $attempt) {
    $start = strpos($source, '  core_ssh_capture result ' . $attempt . ' apply ');
    $end = strpos($source, '  core_ssh_assert_terminal committed ' . $attempt, $start ?: 0);
    if ($start === false || $end === false) {
        throw new RuntimeException('actual signed core Apply block is missing');
    }
    $block = substr($source, $start, $end - $start);
    $healthy = array_replace($apply, $forced);
    foreach ([
        ['healthy', $healthy, '', 0, true],
        ['dirty canary', array_replace($healthy, ['canary' => 'dirty']), '', 0, false],
        ['failed verification', array_replace($healthy, ['verification' => ['result' => 'fail']]), '', 0, false],
        ['required stderr', $healthy, 'env_missing: private-capture-canary', 0, false],
        ['native startup diagnostic', $healthy, 'PHP Warning: private-capture-canary in Unknown on line 0', 0, false],
        ['nonzero despite success answer', $healthy, '', 7, false],
    ] as [$label, $answer, $stderr, $exit, $ok]) {
        $script = $setup . <<<'SH'
context="$2" fixture_stdout="$3" fixture_stderr="$4" fixture_exit="$5" attempt="$6"
TMP="$DIAG_DIR" WPRISM=fixture_promote
fixture_promote() {
  local expected="--envs-file=$TMP/envs.json promote target --with-deletes"
  [ "$attempt" != success ] || expected+=' --force-theirs --force-delete-referenced'
  expected+=' --default-author=admin --format=json'
  [ "$*" = "$expected" ] || fail 'actual core promote argv lost its signed/force/JSON contract'
  printf '%s' "$fixture_stdout"
  printf '%s' "$fixture_stderr" >&2
  return "$fixture_exit"
}
SH;
        [$code, $out, $err] = ShellProbe::run($script . "\n" . $block . "\nprintf 'APPLY_READY\\n'\n", [$root, $encode($context), $host . $encode($answer) . $terminal, $stderr, (string) $exit, $attempt], $root);
        wprism_check($ok ? $code === 0 && str_contains($out, 'APPLY_READY') : $code !== 0 && !str_contains($out, 'APPLY_READY'), "actual $attempt promote command block: $label");
        wprism_check(!str_contains($out . $err, 'private-capture-canary'), "actual $attempt promote command block keeps $label diagnostics private");
    }
}

$extensionStart = strpos($parent, '  EXTENSION_REAL=');
$extensionEnd = strpos($parent, '  # shellcheck source=/dev/null', $extensionStart ?: 0);
if ($extensionStart === false || $extensionEnd === false) {
    throw new RuntimeException('actual parent extension guard is missing');
}
$extensionGuard = substr($parent, $extensionStart, $extensionEnd - $extensionStart);
foreach ([
    ['sandbox/tests/live/regress_core_ssh_deletion.sh', false, true, 'exact shared core extension'],
    ['integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual_ssh_deletion.sh', false, true, 'existing scenario extension'],
    ['sandbox/tests/live/regress_ssh_adopt.sh', false, false, 'other tracked shared live file'],
    ['sandbox/tests/lib/ssh_adopt_extension.sh', false, false, 'shared helper is not an extension'],
    ['sandbox/tests/live/regress_core_ssh_deletion.sh', true, false, 'untracked exact core path'],
] as [$path, $untracked, $ok, $label]) {
    $script = $setup . "\n" . 'EXTENSION="$ROOT/$2"' . "\n";
    if ($untracked) {
        $script .= "git() { return 1; }\n";
    }
    $script .= $extensionGuard . "\nprintf 'ADMITTED\\n'\n";
    [$code, $out] = ShellProbe::run($script, [$root, $path], $root);
    wprism_check($ok ? $code === 0 && str_contains($out, 'ADMITTED') : $code !== 0 && !str_contains($out, 'ADMITTED'), 'actual parent path admission: ' . $label);
}

// The exact private FK profile comes from the engine transaction boundary,
// not a test-assumed SQL error or a matching public catch-all alone.
require_once $root . '/agent/wprism.php';
$db = FakeWpdb::install()->enableInformationSchema();
$db->seedTable('wp_posts', [['ID' => 12]])->setColumns('wp_posts', ['ID' => 'bigint unsigned'])->setTableEngine('wp_posts', 'InnoDB');
$profile = new \WPrism\NativeDatabaseProfile([], ['wp_posts']);
\WPrism\Db::start_repeatable_read('apply transaction start', $profile);
\WPrism\Db::rollback('core SSH profile healthy control');
wprism_check_same(null, $db->activeTransactionIsolation(), 'actual mutation profile with no escaping FK has a healthy settled control');
$db->addForeignKey('wordpress/core_ssh_delete_fk', 'wp_core_ssh_delete_fk', 'wp_posts', 'RESTRICT', 'CASCADE');
$db->resetLog();
$failure = null;
try {
    \WPrism\Db::start_repeatable_read('apply transaction start', $profile);
} catch (Throwable $caught) {
    $failure = $caught;
}
$expected = Fixture::refusalProfile();
wprism_check($failure instanceof RuntimeException && $failure->getPrevious() === null
    && get_class($failure) === $expected['nodes'][0]['class']
    && $failure->getMessage() === $expected['nodes'][0]['message'], 'actual CASCADE mutation preflight emits the exact declared private root without a hidden cause chain');
wprism_check_same([['ID' => 12]], $db->rows('wp_posts'), 'actual FK preflight leaves the native parent untouched');
wprism_check($db->activeTransactionIsolation() === null
    && array_filter($db->queries(), static fn(string $sql): bool => preg_match('/\A(?:INSERT|UPDATE|DELETE)\b/i', $sql) === 1) === [], 'actual FK preflight settles without authored DML, not a partial-delete rollback');

WpStore::reset();
wprism_check_summary('core signed SSH deletion evidence contract');
