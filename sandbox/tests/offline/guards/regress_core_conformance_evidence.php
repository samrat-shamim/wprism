<?php
declare(strict_types=1);

/** Core fixture evidence must exercise unsigned refusal, not invent deletion authority. */
$pagePlanScratch = null;
if (($argv[1] ?? '') === '--page-plan') {
    $pagePlanScratch = sys_get_temp_dir() . '/wprism-core-page-plan-' . bin2hex(random_bytes(8));
    define('WP_CONTENT_DIR', $pagePlanScratch);
}
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/ShellProbe.php';
require_once __DIR__ . '/../../lib/PrivateRefusalReceipt.php';
$profileRoot = is_dir($argv[1] ?? '') ? $argv[1] : dirname(__DIR__, 4);
require_once $profileRoot . '/sandbox/conformance/fixtures/core-private-refusal-evidence.php';
require_once dirname(__DIR__, 4) . '/agent/src/Delete/DeletionWriterExclusion.php';
require_once dirname(__DIR__, 4) . '/agent/src/Apply/ApplyRequestCoordinator.php';
require_once dirname(__DIR__, 4) . '/agent/src/Command/Cli.php';

use WPrism\ApplyPlanner;
use WPrism\ApplyRequestCoordinator;
use WPrism\Cli;
use WPrism\CommandRefusalException;
use WPrism\DeleteGuardLockCoordinator;
use WPrism\DeletionWriterExclusion;
use WPrism\PrivateRefusalEvidence;
use WPrismTest\FakeWpdb;
use WPrismTest\ShellProbe;
use WPrismTest\WpStore;

final class CoreEvidenceHalt extends RuntimeException {}
final class WP_CLI {
    public static array $lines = [];
    public static function add_command(string $name, string $class): void {}
    public static function line(string $line): void { self::$lines[] = $line; }
    public static function halt(int $status): never { throw new CoreEvidenceHalt((string) $status); }
}

$root = dirname(__DIR__, 4);
$self = __FILE__;
$nativeTables = ['posts', 'postmeta', 'comments', 'commentmeta', 'term_relationships',
    'terms', 'termmeta', 'term_taxonomy', 'options', 'wprism_map', 'wprism_state', 'wprism_kv', 'wprism_journal'];

// Derive the warning's entity kind through the actual immutable-plan builder.
// An already-absent page isolates row construction from comment scanning; the
// comment witness below still belongs to the separately exercised live guard.
if (($argv[1] ?? '') === '--page-plan') {
    require_once $root . '/sandbox/tests/lib/agent_version.php';
    wprism_test_define_agent_versions();
    require_once $root . '/agent/src/Capture/Capture.php';
    function get_taxonomies(array $args = [], string $output = 'names'): array { return []; }
    $scratch = $pagePlanScratch;
    mkdir($scratch . '/themes/fixture', 0700, true);
    file_put_contents($scratch . '/themes/fixture/style.css', "/*\nTheme Name: Core plan fixture\nVersion: 1.0.0\n*/\n");
    try {
        $db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions();
        WpStore::reset()->seedOptions(['home' => 'https://core.example.test']);
        foreach (\WPrism\TableSchema::core_capture_required_columns() as $property => $columns) {
            $db->setColumns($db->$property, array_fill_keys($columns, 'longtext'))->setTableEngine($db->$property, 'InnoDB');
        }
        $db->setColumns('wp_wprism_map', [
            'uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
        ])->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
            ->setTableEngine('wp_wprism_map', 'InnoDB')->setColumns('wp_wprism_state', [
                'uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'char(64)',
            ])->setUniqueKey('wp_wprism_state', ['uuid'])->setTableEngine('wp_wprism_state', 'InnoDB')
            ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'varchar(1024)'])
            ->setUniqueKey('wp_wprism_kv', ['k'])->setTableEngine('wp_wprism_kv', 'InnoDB')
            ->seedTable('wp_options', [
                ['option_id' => 1, 'option_name' => 'active_plugins', 'option_value' => 'a:0:{}', 'autoload' => 'yes'],
                ['option_id' => 2, 'option_name' => 'stylesheet', 'option_value' => 'fixture', 'autoload' => 'yes'],
                ['option_id' => 3, 'option_name' => 'template', 'option_value' => 'fixture', 'autoload' => 'yes'],
            ]);
        $policy = new \WPrism\Policy();
        $policy->site = ['policy' => ['taxonomies' => [], 'post_types' => []]];
        $policy->manifests = [\WPrism\Canon::decode((string) file_get_contents($root . '/platform/adapter-library/core/manifest.json'))];
        $uuid = $argv[2];
        $previous = \WPrism\CompiledRepository::create(['revision_hash' => str_repeat('b', 64), 'tree' => [$uuid => [
            'type' => 'post', 'hash' => str_repeat('a', 64), 'path' => 'posts/page/' . $uuid . '.md',
            'data' => ['uuid' => $uuid, 'type' => 'page'],
        ]]]);
        $tombstone = \WPrism\Deletion::capture_tombstones($previous, [], $policy)[0];
        $compiled = \WPrism\CompiledRepository::create(['tree' => [], 'deletions' => [$uuid => $tombstone + [
            'data' => \WPrism\Canon::decode($tombstone['content']), 'hash' => hash('sha256', $tombstone['content']),
        ]]]);
        $planner = new ApplyPlanner($policy, [], \WPrism\Ledger::id_for(...), \WPrism\Ledger::id_for(...));
        $builder = new \WPrism\ApplyPlanBuilder($scratch, $policy, $planner,
            new \WPrism\DeleteGuardReferenceScanner($policy), [], null,
            static fn(): array => ['regen_pending' => [], 'regen_context' => [], 'warnings' => []],
            static fn(): array => ['env_missing' => [], 'warnings' => []]);
        $result = $builder->build([], $compiled, false, false);
        echo json_encode($result['plan']['deleted'][0], JSON_THROW_ON_ERROR) . "\n";
    } finally {
        unlink($scratch . '/themes/fixture/style.css');
        rmdir($scratch . '/themes/fixture');
        rmdir($scratch . '/themes');
        rmdir($scratch);
    }
    exit(0);
}

// Child modes run the actual submitted fixture PHP, or append an engine-built
// record only after the real shell block has inventoried its private store.
if (($argv[1] ?? '') === '--write-record') {
    [$directory, $recordJson, $mutation] = array_slice($argv, 2);
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $record = json_decode($recordJson, true, 32, JSON_THROW_ON_ERROR);
    if ($mutation === 'unrelated') {
        $record = ['format' => $record['format'], 'command' => 'apply', 'reason_code' => $record['reason_code'],
            ...PrivateRefusalEvidence::graph(new RuntimeException('unrelated private-core-fixture-value'))];
    } elseif ($mutation === 'incomplete') {
        $record['traversal']['scan_complete'] = false;
    }
    $file = $directory . '/20260905-120000-apply-' . str_repeat('a', 24) . '.json';
    file_put_contents($file, json_encode($record, JSON_THROW_ON_ERROR));
    chmod($file, 0600);
    if ($mutation === 'extra') {
        $extra = $directory . '/20260905-120001-apply-' . str_repeat('b', 24) . '.json';
        copy($file, $extra);
        chmod($extra, 0600);
    }
    exit(0);
}
if (($argv[1] ?? '') === '--native') {
    $wpdb = FakeWpdb::install();
    foreach ($nativeTables as $table) {
        $wpdb->seedTable('wp_' . $table, [['ID' => 1, 'meta_id' => 1, 'comment_ID' => 1,
            'object_id' => 1, 'term_id' => 1, 'term_taxonomy_id' => 1, 'option_id' => 1,
            'uuid' => 'fixture', 'id_kind' => 'post', 'k' => 'fixture', 'id' => 1]]);
    }
    $mutation = $argv[3];
    if (str_starts_with($mutation, 'error:')) {
        $wpdb->failNextQuery('private-core-fixture-value', 'FROM wp_' . substr($mutation, 6) . ' ');
    } elseif ($mutation === 'null' || $mutation === 'false') {
        $wpdb->returnNextGetResultsAs($mutation === 'null' ? null : false, 'FROM wp_posts ');
    } elseif ($mutation === 'rows' || $mutation === 'exact-rows') {
        $wpdb->returnNextGetResultsAs(array_fill(0, $mutation === 'rows' ? 4097 : 4096, ['ID' => 1]), 'FROM wp_posts ');
    } elseif ($mutation === 'bytes') {
        $wpdb->returnNextGetResultsAs([['value' => str_repeat('x', 1048576)]], 'FROM wp_posts ');
    }
    try {
        eval($argv[2]);
        exit(0);
    } catch (Throwable $failure) {
        fwrite(STDERR, $failure->getMessage() . "\n");
        exit(1);
    }
}
if (($argv[1] ?? '') === '--widget-eval') {
    $options = json_decode((string) file_get_contents($argv[2]), true, 8, JSON_THROW_ON_ERROR);
    $store = WpStore::reset()->seedOptions($options);
    try {
        eval($argv[3]);
        if ($argv[4] === 'cleanup-change' && str_contains($argv[3], 'unset($widgets[99])')) {
            update_option('widget_block', [21 => ['content' => 'changed unrelated widget']]);
            echo 'invalid readback';
        }
        file_put_contents($argv[2], json_encode($store->options, JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $failure) {
        fwrite(STDERR, $failure->getMessage() . "\n");
        exit(1);
    }
}

$source = (string) file_get_contents($root . '/sandbox/conformance/checks/core.sh');
// Stable fixture neighbors include the former bare `>/dev/null` command too;
// extracting only new capture-variable names would skip the prior defect.
$commandAfter = static function (string $before, string $after) use ($source): string {
    $start = strpos($source, $before);
    if ($start === false) {
        throw new RuntimeException('missing core fixture command predecessor');
    }
    $start += strlen($before);
    $end = strpos($source, $after, $start);
    if ($end === false) {
        throw new RuntimeException('missing core fixture command successor');
    }
    return substr($source, $start, $end - $start);
};
$start = strpos($source, 'core_private_refusal_evidence() {');
$end = strpos($source, '# issue #3210: absence alone', (int) $start);
wprism_check(is_int($start) && is_int($end), 'core owns one explicit receipt/native/refusal evidence block');
$helpers = substr($source, (int) $start, (int) $end - (int) $start);
$uuid = '11111111-1111-4111-8111-111111111111';
$secondUuid = '22222222-2222-4222-8222-222222222222';
[$pageStatus, $pageJson, $pageStderr] = ShellProbe::run('"$1" "$2" --page-plan "$3"', [PHP_BINARY, $self, $uuid], $root);
wprism_check($pageStatus === 0 && $pageStderr === '', 'page tombstone and full target planner produce a warning-free row');
$pageRow = json_decode($pageJson, true, 16, JSON_THROW_ON_ERROR);
wprism_check(($pageRow['uuid'] ?? null) === $uuid && ($pageRow['type'] ?? null) === 'post'
    && ($pageRow['deletion_kind'] ?? null) === 'post' && ($pageRow['deletion_type'] ?? null) === 'page',
    'actual page deletion planning preserves the logical post kind separately from its page subtype');
$contexts = [
    'plain' => '{}',
    'forced-comments' => json_encode(['uuid' => $uuid, 'comment_id' => 23], JSON_THROW_ON_ERROR),
    'forced-conflicts' => json_encode(['delete_conflict' => [
        ['uuid' => $uuid, 'reason' => 'target entity changed locally since the tombstone base'],
        ['uuid' => $secondUuid, 'reason' => 'tombstone expected hash does not match the target last-synced base'],
    ]], JSON_THROW_ON_ERROR),
];
$records = [];
$answers = [];
foreach ([
    ['plain', '[]'], ['unknown', '{}'], ['plain', '{'], ['plain', str_repeat('x', 65537)],
    ['forced-comments', json_encode(['uuid' => $uuid, 'comment_id' => '23'])],
    ['forced-comments', json_encode(['uuid' => $uuid, 'comment_id' => 0])],
    ['forced-comments', json_encode(['uuid' => '../private-core-fixture-value', 'comment_id' => 23])],
    ['forced-conflicts', '{"delete_conflict":[]}'],
    ['forced-conflicts', json_encode(['delete_conflict' => [['uuid' => $uuid, 'reason' => 'unrelated private-core-fixture-value']]])],
    ['forced-conflicts', json_encode(['delete_conflict' => array_fill(0, 17, ['uuid' => $uuid, 'reason' => 'target entity changed locally since the tombstone base'])])],
    ['forced-conflicts', json_encode(['delete_conflict' => array_fill(0, 2, ['uuid' => $uuid, 'reason' => 'target entity changed locally since the tombstone base'])])],
] as $index => [$name, $context]) {
    $failure = null;
    try {
        core_private_refusal_profile($name, $context);
    } catch (RuntimeException $caught) {
        $failure = $caught;
    }
    wprism_check($failure instanceof RuntimeException && !str_contains($failure->getMessage(), 'private-core-fixture-value'),
        "closed core profile context mutation $index refuses without inferring a private cause");
}
foreach ($contexts as $name => $contextJson) {
    $leaf = null;
    try {
        (new DeletionWriterExclusion())->assert_plan_authority();
    } catch (CommandRefusalException $failure) {
        $leaf = $failure;
    }
    wprism_check($leaf instanceof CommandRefusalException, "$name: real unsigned authority gate refuses before any target access");
    $coordinator = (new ReflectionClass(ApplyRequestCoordinator::class))->newInstanceWithoutConstructor();
    $warnings = [];
    $forced = [];
    if ($name === 'forced-comments') {
        DeleteGuardLockCoordinator::append_forced_warnings($warnings, $pageRow + [
            'guard_refs' => [['table' => 'comments', 'rows' => ['comments.comment_ID=23'],
                'repairable' => false, 'option_name_ref' => false]],
        ], 'FORCED delete of guarded');
    } elseif ($name === 'forced-conflicts') {
        foreach (json_decode($contextJson, true)['delete_conflict'] as $row) {
            $warnings[] = "FORCED deletion conflict {$row['uuid']} ({$row['reason']})";
            $forced[] = ApplyPlanner::forced_override_evidence($row, 'delete_conflict', [
                'with_deletes' => true, 'force_theirs' => true,
            ]);
        }
    }
    (new ReflectionProperty($coordinator, 'warnings'))->setValue($coordinator, $warnings);
    (new ReflectionProperty($coordinator, 'forcedOverrideEvidence'))->setValue($coordinator, $forced);
    $wrapped = (new ReflectionMethod(ApplyRequestCoordinator::class, 'failure_with_forced_warnings'))
        ->invoke(null, $leaf, $coordinator);
    $profile = core_private_refusal_profile($name, $contextJson);
    $graph = PrivateRefusalEvidence::graph($wrapped);
    $observedNodes = array_map(static fn(array $node): array => array_intersect_key($node, array_flip([
        'parent_index', 'relation', 'class', 'message',
    ])), $graph['throwable']);
    wprism_check($observedNodes == $profile['nodes'], "$name: fixture profile equals real guard/coordinator exact cause graph");
    $records[$name] = json_encode(['format' => 'wprism-private-refusal-evidence/v2',
        'command' => 'apply', 'reason_code' => $profile['reason_code'], ...$graph], JSON_THROW_ON_ERROR);
    WP_CLI::$lines = [];
    try {
        (new ReflectionMethod(Cli::class, 'halt_json_failure'))->invoke(null, $wrapped, ['format' => 'json'], 'apply');
    } catch (CoreEvidenceHalt) {
    }
    $answers[$name] = WP_CLI::$lines[0] ?? '';
    $public = json_decode($answers[$name], true);
    wprism_check(($public['reason_code'] ?? null) === $profile['reason_code']
        && !str_contains($answers[$name], WPRISM_CORE_DELETION_EXCLUSION_CAUSE),
        "$name: real CLI public refusal has exact reason and no private cause");
}

$setup = <<<'SH'
set -euo pipefail
root="$1" php="$2" self="$3" profile="$4" context="$5" record="$6" fixture_answer="$7" mutation="$8"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$root/sandbox/conformance/asserts.sh"
scratch=$(mktemp -d "${TMPDIR:-/tmp}/wprism-core-evidence.XXXXXX")
trap 'rm -rf -- "$scratch"' EXIT
ln -s "$root" "$scratch/source root"
PAIR_SOURCE_ROOT="$scratch/source root" COMPOSE=fixture_compose
fixture_observation_diagnostic() {
  case "$mutation" in
    "$1-php-stdout") printf 'PHP Warning: fixture observation in /fixture.php on line 1\n' ;;
    "$1-php-stderr") printf 'PHP Parse error: fixture observation\n' >&2 ;;
  esac
}
fixture_compose() {
  local expected=(run --rm -T --volume "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro"
    --volume "$PAIR_SOURCE_ROOT/sandbox/conformance/fixtures/core-private-refusal-evidence.php:/wprism-test/core-private-refusal-evidence.php:ro"
    --entrypoint php cli2 /wprism-test/core-private-refusal-evidence.php /wprism-test/PrivateRefusalReceipt.php)
  local word
  for word in "${expected[@]}"; do [ "${1:-}" = "$word" ] || return 94; shift; done
  [ "${3:-}" = /siterepo/.wprism/refusals ] || return 95
  [ "$mutation" != missing-mount ] || return 96
  local boundary="private-$1"
  if [ "$1" = verify ] && [ "$mutation" = empty-baseline ]; then [ "${5:-}" = '[]' ] || return 98; fi
  fixture_observation_diagnostic "$boundary"
  "$php" "$PAIR_SOURCE_ROOT/sandbox/conformance/fixtures/core-private-refusal-evidence.php" \
    "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php" "$1" "$2" "$scratch/refusals" "$4" "${@:5}" || return $?
  [ "$mutation" != "$boundary-nonzero" ] || return 7
}
wp_conf2() {
  if [ "$1" = eval ]; then
    local boundary=native-before
    [ ! -f "$scratch/applied" ] || boundary=native-after
    fixture_observation_diagnostic "$boundary"
    [ "$mutation" != empty-native ] || return 0
    if [ "$mutation" = native-change ] && [ -f "$scratch/applied" ]; then printf '{"posts":"after"}\n'; else printf '{"posts":"before"}\n'; fi
    [ "$mutation" != "$boundary-nonzero" ] || return 7
    return 0
  fi
  [ "$1 $2" = 'wprism apply' ] || return 97
  touch "$scratch/applied"
  if [ "$mutation" != stale ]; then "$php" "$self" --write-record "$scratch/refusals" "$record" "$mutation"; fi
  case "$mutation" in
    php-stdout) printf 'PHP Warning: private-core-fixture-value in /fixture.php on line 1\n' ;;
    php-stderr) printf 'PHP Parse error: private-core-fixture-value\n' >&2 ;;
    private-leak) printf 'wprism: deletion refused before mutation — no exact held external writer exclusion is bound\n' ;;
  esac
  case "$mutation" in
    wrong-envelope) printf '%s\n' "$fixture_answer" | jq -c '.reason_code="unrelated_failure"' ;;
    missing-forced) printf '%s\n' "$fixture_answer" | jq -c 'del(.forced_overrides)' ;;
    *) printf '%s\n' "$fixture_answer" ;;
  esac
  [ "$mutation" = zero-exit ] && return 0
  return 1
}
if [ "$mutation" = stale ]; then "$php" "$self" --write-record "$scratch/refusals" "$record" ready; fi
COMMENT_EXCLUSION_CONTEXT="$context" LOCAL_EXCLUSION_CONTEXT="$context"
BRANCH_EXCLUSION_CONTEXT="$context" RESTORED_EXCLUSION_CONTEXT="$context"
SH;
preg_match_all('/^core_assert_deletion_exclusion (plain|forced-comments|forced-conflicts) .*$/m', $source, $calls, PREG_SET_ORDER);
wprism_check(count($calls) === 5, 'all five direct-deletion acceptance sites use the exact fresh refusal evidence owner');
foreach ($calls as $index => $call) {
    $name = $call[1];
    $mutations = ['ready', 'empty-baseline', 'zero-exit', 'wrong-envelope', 'php-stdout', 'php-stderr', 'private-leak',
        'stale', 'unrelated', 'incomplete', 'extra', 'missing-mount', 'native-change', 'empty-native'];
    foreach (['native-before', 'native-after', 'private-snapshot', 'private-verify'] as $boundary) {
        foreach (['php-stdout', 'php-stderr', 'nonzero'] as $failure) {
            $mutations[] = "$boundary-$failure";
        }
    }
    if ($name === 'forced-conflicts') {
        $mutations[] = 'missing-forced';
    }
    foreach ($mutations as $mutation) {
        [$status, $stdout, $stderr] = ShellProbe::run($setup . "\n" . $helpers . "\n" . $call[0] . "\nprintf 'CORE_READY\\n'\n",
            [$root, PHP_BINARY, $self, $name, $contexts[$name], $records[$name], $answers[$name], $mutation], $root);
        $positive = in_array($mutation, ['ready', 'empty-baseline'], true);
        if ($positive && $status !== 0) {
            fwrite(STDERR, $stderr);
        }
        wprism_check($positive
            ? $status === 0 && str_contains($stdout, 'CORE_READY')
            : $status !== 0 && ($stdout . $stderr) !== '' && !str_contains($stdout, 'CORE_READY')
                && !str_contains($stdout . $stderr, 'private-core-fixture-value')
                && !str_contains($stdout . $stderr, WPRISM_CORE_DELETION_EXCLUSION_CAUSE),
            "actual core deletion site $index ($name): $mutation is classified without private payload (exit $status)");
    }
}

[$nativeStatus, $nativeProgram] = ShellProbe::run(
    "wp_conf2() { printf '%s' \"\$2\"; }\n" . $helpers . "\ncore_deletion_native_state\n", [], $root);
wprism_check($nativeStatus === 0 && str_contains($nativeProgram, 'LIMIT 4097'), 'native state proof executes the actual shell-submitted bounded SQL program');
foreach (['ready', 'exact-rows', 'null', 'false', 'rows', 'bytes', ...array_map(static fn(string $table): string => 'error:' . $table, $nativeTables)] as $mutation) {
    [$status, $stdout, $stderr] = ShellProbe::run('"$1" "$2" --native "$3" "$4"', [PHP_BINARY, $self, $nativeProgram, $mutation], $root);
    $positive = in_array($mutation, ['ready', 'exact-rows'], true);
    $observed = json_decode($stdout, true);
    wprism_check($positive ? $status === 0 && array_keys($observed ?? []) === $nativeTables
        : $status !== 0 && $stdout === '' && !str_contains($stderr, 'private-core-fixture-value'),
        "actual native SQL read: $mutation cannot turn an error or unbounded read into equality");
}

$parkedBlock = ShellProbe::captureBlock($source, 'PARKED_BEFORE', '# issue #3264 <-> issue #3278 cross-PR finding');
$widgetSetup = <<<'SH'
set -euo pipefail
root="$1" php="$2" self="$3" mutation="$4"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { :; }
. "$root/sandbox/conformance/asserts.sh"
CONF_REPO1=$(mktemp -d "${TMPDIR:-/tmp}/wprism-core-widgets.XXXXXX")
trap 'rm -rf -- "$CONF_REPO1"' EXIT
mkdir "$CONF_REPO1/state"
printf '%s' '{"widget_block":{"21":{"content":"authored image"},"_multiwidget":1},"sidebars_widgets":{"sidebar-1":["block-21"],"wp_inactive_widgets":[],"array_version":3}}' >"$CONF_REPO1/options"
wp_conf1() {
  if [ "$1" = eval ]; then
    local boundary=widget-before
    case "$2" in
      *'unset($widgets[99])'*) boundary=widget-after ;;
      *'$widgets[99] = '*) boundary=widget-install ;;
      *'Attachment retired in source intent'*) boundary=widget-retire ;;
    esac
    case "$mutation" in
      "$boundary-php-stdout") printf 'PHP Warning: fixture observation in /fixture.php on line 1\n' ;;
      "$boundary-php-stderr") printf 'PHP Parse error: fixture observation\n' >&2 ;;
    esac
    "$php" "$self" --widget-eval "$CONF_REPO1/options" "$2" "$mutation" || return $?
    [ "$mutation" != "$boundary-nonzero" ] || return 7
    return 0
  fi
  [ "$1 $2" = 'wprism capture' ] || return 96
  local warning='unreferenced wp_inactive_widgets entries are target-owned; parked widget content will not propagate'
  if jq -e '.widget_block | has("99")' "$CONF_REPO1/options" >/dev/null; then
    [ "$mutation" != php ] || printf 'PHP Warning: fixture in /fixture.php on line 1\n' >&2
    [ "$mutation" != captured ] || printf 'Parked source-only widget' >"$CONF_REPO1/state/leak"
    [ "$mutation" != wrong-warning ] || warning='unrelated warning'
    [ "$mutation" != missing-tree ] || rmdir "$CONF_REPO1/state"
    jq -nc --arg warning "$warning" '{warnings:[$warning],counts:{}}'
    [ "$mutation" != nonzero ] || return 7
  else
    if [ "$mutation" = residual-warning ]; then jq -nc --arg warning "$warning" '{warnings:[$warning],counts:{}}'; else printf '{"warnings":[],"counts":{}}\n'; fi
  fi
}
SH;
$widgetMutations = ['ready', 'wrong-warning', 'php', 'nonzero', 'captured', 'missing-tree', 'cleanup-change', 'residual-warning'];
foreach (['widget-before', 'widget-install', 'widget-after'] as $boundary) {
    foreach (['php-stdout', 'php-stderr', 'nonzero'] as $failure) {
        $widgetMutations[] = "$boundary-$failure";
    }
}
foreach ($widgetMutations as $mutation) {
    [$status, $stdout] = ShellProbe::run($widgetSetup . "\n" . $parkedBlock . "\nprintf 'WIDGET_READY\\n'\n", [$root, PHP_BINARY, $self, $mutation], $root);
    wprism_check($mutation === 'ready' ? $status === 0 && str_contains($stdout, 'WIDGET_READY')
        : $status !== 0 && !str_contains($stdout, 'WIDGET_READY'), "actual parked-widget warning/cleanup block rejects $mutation counterfactual correctly");
}
$attachmentBlock = $commandAfter("# refusal rather than the intended tombstone expected-base conflict.\n", 'wp_conf1 post delete "$ATT1"');
$attachmentSetup = str_replace('authored image', '<!-- wp:image {} -->authored image', $widgetSetup);
foreach (['ready', 'widget-retire-php-stdout', 'widget-retire-php-stderr', 'widget-retire-nonzero'] as $mutation) {
    [$status, $stdout] = ShellProbe::run($attachmentSetup . "\n" . $attachmentBlock . "\nprintf 'ATTACHMENT_READY\\n'\n",
        [$root, PHP_BINARY, $self, $mutation], $root);
    wprism_check($mutation === 'ready' ? $status === 0 && str_contains($stdout, 'ATTACHMENT_READY')
        : $status !== 0 && !str_contains($stdout, 'ATTACHMENT_READY'), "actual attachment-widget retirement classifies $mutation before accepting its native write");
}

$seed = (string) file_get_contents($root . '/sandbox/conformance/seeds/core.sh');
wprism_check(!str_contains($seed, "'wp_inactive_widgets'=>['block-99']")
    && !str_contains($seed, 'Parked source-only widget'), 'positive source seed contains no deliberate parked-widget warning fixture');
$deletionRegion = substr($source, (int) $end);
wprism_check(!str_contains($deletionRegion, 'format=json >/dev/null')
    && !str_contains($deletionRegion, 'wp_conf1 wprism capture --repo=/siterepo >/dev/null')
    && !str_contains($deletionRegion, 'partial delete failure rolls the transaction back')
    && !str_contains($deletionRegion, 'wprism_delete_block'),
    'pending tombstones never reach an ordinary positive apply or claim an unexecuted post-FK rollback');
preg_match_all('/^capture_wprism_json_checked [^\n]+\\\\\n[^\n]+/m', $deletionRegion, $planCaptures);
$sourceCapturePredecessors = [
    'LOCAL_DELETE_CAPTURE' => 'wp_conf1 post delete "$HELLO1" --force >/dev/null',
    'BRANCH_EDIT_CAPTURE' => 'wp_conf1 post update "$ATT1" --post_title=\'Conformance Logo Branch Edit\' >/dev/null',
    'BRANCH_DELETE_CAPTURE' => 'wp_conf1 post delete "$ATT1" --force >/dev/null',
    'CHILD_DELETE_CAPTURE' => 'wp_conf1 post delete "$CHILD1" --force >/dev/null',
];
$captureCases = [];
foreach ($planCaptures[0] as $capture) {
    foreach (array_keys($sourceCapturePredecessors) as $variable) {
        if (str_starts_with($capture, "capture_wprism_json_checked $variable ")) {
            continue 2;
        }
    }
    $captureCases[] = $capture;
}
foreach ($sourceCapturePredecessors as $predecessor) {
    $captureCases[] = $commandAfter($predecessor . "\n", "\ngit -C ");
}
wprism_check(count($captureCases) === 14, 'exactly 14 actual deletion-region source-capture/target-plan command boundaries are exercised');
foreach ($captureCases as $index => $capture) {
    foreach (['ready', 'php-stdout', 'php-stderr', 'required-stdout', 'required-stderr', 'nonzero'] as $mutation) {
        $script = <<<'SH'
set -euo pipefail
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
fixture_mutation="$2"
fixture_capture() {
  case "$fixture_mutation" in
    php-stdout) printf 'PHP Warning: fixture in /fixture.php on line 1\n' ;;
    php-stderr) printf 'PHP Parse error: fixture\n' >&2 ;;
    required-stdout) printf 'Warning: env_missing: fixture\n' ;;
    required-stderr) printf 'Warning: env_missing: fixture\n' >&2 ;;
  esac
  printf '{"warnings":[]}\n'
  [ "$fixture_mutation" != nonzero ] || return 7
}
wp_conf1() { fixture_capture "$@"; }
wp_conf2() { fixture_capture "$@"; }
SH;
        [$status, $stdout] = ShellProbe::run($script . "\n" . $capture . "\nprintf 'CAPTURE_READY\\n'\n", [$root, $mutation], $root);
        wprism_check($mutation === 'ready' ? $status === 0 && str_contains($stdout, 'CAPTURE_READY')
            : $status !== 0 && !str_contains($stdout, 'CAPTURE_READY'), "actual deletion capture/plan command $index classifies $mutation before publishing JSON");
    }
}
$fresh = ApplyPlanner::classify_deletion(['uuid' => $uuid, 'expected_hash' => str_repeat('a', 64)], ['hash' => str_repeat('a', 64)], null);
wprism_check($fresh['bucket'] === 'delete_conflict'
    && $fresh['row']['reason'] === 'target entity exists but has no last-synced base'
    && str_contains($source, 'all(.delete_conflict[]; .reason == "target entity exists but has no last-synced base")'),
    'clearing ledger history does not reinterpret a UUID-bearing native post as an already-deleted entity');

$freshBlock = ShellProbe::captureBlock($source, 'FRESH_NATIVE_BEFORE', 'pass "unmapped existing target entities');
$freshSetup = <<<'SH'
set -euo pipefail
root="$1" fixture_answer="$2" mutation="$3"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$root/sandbox/conformance/asserts.sh"
TOMBSTONES=1 FRESH_NATIVE_EXPECTED='{"posts":"retained","map":[],"state":[]}'
scratch=$(mktemp -d "${TMPDIR:-/tmp}/wprism-core-fresh.XXXXXX")
trap 'rm -rf -- "$scratch"' EXIT
wp_conf2() {
  [ "$1 $2" = 'wprism plan' ] || return 96
  touch "$scratch/planned"
  [ "$mutation" != php ] || printf 'PHP Warning: fixture in /fixture.php on line 1\n' >&2
  [ "$mutation" != required ] || printf 'Warning: env_missing: fixture\n' >&2
  printf '%s\n' "$fixture_answer"
  [ "$mutation" != nonzero ] || return 7
}
core_deletion_native_state() {
  local boundary=native-before
  [ ! -f "$scratch/planned" ] || boundary=native-after
  case "$mutation" in
    "$boundary-php-stdout") printf 'PHP Warning: fixture observation in /fixture.php on line 1\n' ;;
    "$boundary-php-stderr") printf 'PHP Parse error: fixture observation\n' >&2 ;;
  esac
  [ "$mutation" != empty-native ] || return 0
  if [ "$mutation" = native-change ] && [ -f "$scratch/planned" ]; then printf '{"posts":"changed"}\n'; else printf '%s\n' "$FRESH_NATIVE_EXPECTED"; fi
  [ "$mutation" != "$boundary-nonzero" ] || return 7
}
SH;
$freshMutations = ['ready', 'old-deleted', 'wrong-reason', 'wrong-count', 'php', 'required', 'nonzero', 'native-change', 'empty-native'];
foreach (['native-before', 'native-after'] as $boundary) {
    foreach (['php-stdout', 'php-stderr', 'nonzero'] as $failure) {
        $freshMutations[] = "$boundary-$failure";
    }
}
foreach ($freshMutations as $mutation) {
    $answer = ['warnings' => [], 'deleted' => [], 'delete' => [], 'delete_conflict' => [$fresh['row']]];
    if ($mutation === 'old-deleted') {
        $answer['deleted'] = $answer['delete_conflict'];
        $answer['delete_conflict'] = [];
    } elseif ($mutation === 'wrong-reason') {
        $answer['delete_conflict'][0]['reason'] = 'unrelated target failure';
    } elseif ($mutation === 'wrong-count') {
        $answer['delete_conflict'][] = $fresh['row'];
    }
    [$status, $stdout] = ShellProbe::run($freshSetup . "\n" . $freshBlock . "\nprintf 'FRESH_READY\\n'\n",
        [$root, json_encode($answer, JSON_THROW_ON_ERROR), $mutation], $root);
    wprism_check($mutation === 'ready' ? $status === 0 && str_contains($stdout, 'FRESH_READY')
        : $status !== 0 && !str_contains($stdout, 'FRESH_READY'), "actual unmapped-target final plan distinguishes $mutation without mutation");
}

wprism_check_summary('core conformance evidence');
