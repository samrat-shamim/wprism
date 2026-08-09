<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for DUO-3327:
 * `duo manifest-validate`, the adapter author's offline grammar check, and the
 * grammar document it emits.
 *
 * Nothing is faked. Every check below runs the REAL host CLI as a subprocess —
 * `php cli/duo manifest-validate …` — against REAL manifest fixture files this
 * test writes to a scratch directory, and reads the real exit code and the real
 * stdout/stderr. That is the whole point: the command is WordPress-free by
 * construction (cli/duo is dependency-free PHP and the validators it drives are
 * the pure half of Policy::load(), the same half regress_adapter_contract.php
 * and regress_vocabulary_ownership.php already exercise in-process), so the
 * honest way to test it is to run it, not to reimplement its wiring.
 *
 * The fixtures are the two adapters DUO-3318 established, imported from
 * sandbox/tests/manifest_fixtures.php rather than copied — see that file's
 * header. Every invalid case below is one of those adapters broken in exactly
 * one way, which is what makes "the message names the precise path" a
 * meaningful claim rather than a spot check.
 *
 * Three groups deserve their own note:
 *
 *   - The DRIFT group compares the emitted grammar document, field by field,
 *     against the live engine accessors it claims to be derived from. A
 *     document assembled from a second copy of the vocabularies would pass a
 *     "does it have keys" test and fail this one the first time either copy
 *     moved.
 *   - The SHARED-FIXTURE group closes the loop the other way: for each closed
 *     vocabulary in the emitted document, a token from the document is fed to
 *     the runtime validator and must be ACCEPTED, and a token outside it must
 *     be REFUSED with the document's own set printed in the refusal. Schema and
 *     validator are therefore checked against one set of declarations, which is
 *     the drift the issue's acceptance 3 is about.
 *   - The DEFERRED group asserts the live-target list is present on a passing
 *     run, on a failing run, and in the schema document — because a tool that
 *     printed it only on failure would let silence read as "fully verified".
 *
 * What this file does NOT cover, because it genuinely needs a live target:
 * every check the command itself reports as deferred (live table schema,
 * taxonomy_patterns expansion, installed plugin/theme versions, provider
 * negotiation, native-action execution, capability evaluation, lint's live id
 * cross-reference). Each of those has its own live suite; this one proves the
 * command SAYS so, never that it did them.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and
 * the script exits 1.
 */

$repo = dirname(__DIR__, 2);

// The command resolves DUO_SPEC_VERSION out of agent/duo.php's own source
// (scripts/capability-registry.php's established header). Read it the same way
// here so the fixtures below declare the version the engine actually supports,
// and so the emitted document's claim can be checked against the shipped
// constant rather than against a number this file made up.
if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_SPEC_VERSION', (int) $m[1]);

require $repo . '/agent/src/Canon.php';
require $repo . '/agent/src/OptionState.php';
require $repo . '/agent/src/Policy.php';
require __DIR__ . '/manifest_fixtures.php';

use Duo\Canon;
use Duo\NativeActions;
use Duo\Policy;

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

/**
 * Run the real host CLI and return its exact exit code and streams.
 *
 * @param list<string> $args
 * @return array{exit:int, stdout:string, stderr:string}
 */
function duo(array $args): array {
    global $repo;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/cli/duo') . ' manifest-validate';
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new \RuntimeException("could not run: $cmd");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Fresh scratch manifests dir for one check; auto-removed at exit. */
function fixtures(array $files): string {
    $root = sys_get_temp_dir() . '/duo_regress_manifest_validate_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    foreach ($files as $name => $content) {
        Canon::write_file("$root/$name.json", is_string($content) ? $content : Canon::encode($content));
    }
    register_shutdown_function(function () use ($root) {
        foreach (glob("$root/*") ?: [] as $f) {
            @chmod($f, 0644);
            @unlink($f);
        }
        @rmdir($root);
    });
    // The command reports realpath()s, and the system temp dir is a symlink on
    // some platforms — compare against the same resolved form it prints.
    return (string) realpath($root);
}

/**
 * Adapter B with the parent room table folded into it, so the manifest stands
 * on its own.
 *
 * DUO-3318 splits those two tables across two adapters deliberately: its whole
 * subject is what one adapter may say about another's entities, and B's ref
 * into A's keyspace is the point. Every check in the two groups below is about
 * ONE manifest's own grammar, and a per-manifest verdict is by design an
 * ISOLATED load — so with the pair as written, B's isolated verdict would
 * always be about the pin the fixture deliberately withholds rather than about
 * the one thing each check breaks. Same declarations, one owner. The pair is
 * used unchanged where cross-manifest authority is the actual subject.
 */
function solo_b(array $overrides = []): array {
    $b = manifest_b($overrides);
    $b['tables'] = array_merge(
        ['acme_b_rooms' => [
            'class' => 'authored_snapshot',
            'id_kind' => 'acme_room',
            'pk' => 'room_id',
            'slug_column' => 'room_code',
            'columns' => ['room_code' => ['class' => 'authored']],
            'refs' => [],
            'identity' => ['mode' => 'natural_key', 'column' => 'room_code'],
        ]],
        $b['tables'] ?? []
    );
    return $b;
}

/** The JSON report row for one manifest name. @return array<string,mixed> */
function row(array $report, string $name): array {
    foreach ($report['manifests'] ?? [] as $row) {
        if (($row['name'] ?? null) === $name) {
            return $row;
        }
    }
    return [];
}

/** @return array<string,mixed> the decoded --format=json report */
function report(array $result): array {
    $decoded = json_decode($result['stdout'], true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Manifest 'b', broken in one way, must be refused with a message naming the
 * exact coordinate — and the row must carry the fixture's own file path, so an
 * editor jumping to the problem lands in the right file.
 */
function refuses(array $b, string $needle, string $msg): void {
    $dir = fixtures(['b' => $b]);
    $result = duo([$dir, '--format=json']);
    $report = report($result);
    $row = row($report, 'b');
    $message = (string) ($row['message'] ?? '');
    check(
        $result['exit'] === 1
            && ($row['status'] ?? null) === 'error'
            && str_contains($message, $needle)
            && ($row['file'] ?? null) === "$dir/b.json",
        "$msg (exit {$result['exit']}, message: " . ($message === '' ? '<none>' : $message) . ')'
    );
}

/** The same fixture, well-formed: exit 0, and the manifest's own row ok. */
function accepts(array $b, string $msg): void {
    $dir = fixtures(['b' => $b]);
    $result = duo([$dir, '--format=json']);
    $report = report($result);
    check(
        $result['exit'] === 0
            && ($report['status'] ?? null) === 'ok'
            && (row($report, 'b')['status'] ?? null) === 'ok',
        "$msg (exit {$result['exit']}, stderr: " . trim($result['stderr']) . ')'
    );
}

// ======================================================================
echo "\n== the real-world smoke: every SHIPPED manifest validates through the command ==\n";

$shipped = duo([$repo . '/manifests', '--format=json']);
$shippedReport = report($shipped);
check($shipped['exit'] === 0, "the repository's own manifests/ directory validates clean (exit {$shipped['exit']})");
check(
    ($shippedReport['format'] ?? null) === 'duo-manifest-validation/v1'
        && ($shippedReport['status'] ?? null) === 'ok',
    'the JSON report carries its versioned envelope and an overall verdict'
);
check(
    ($shippedReport['spec_version'] ?? null) === DUO_SPEC_VERSION,
    'the report states the spec version it validated against, resolved from agent/duo.php rather than defaulted'
);
$shippedNames = array_column($shippedReport['manifests'] ?? [], 'name');
check(
    count($shippedNames) >= 10 && in_array('core', $shippedNames, true) && in_array('woocommerce', $shippedNames, true),
    'every manifest in the directory gets its own row (' . count($shippedNames) . ' rows)'
);
check(
    !in_array('dispositions', $shippedNames, true),
    'dispositions.json is external review state loaded WITH the directory, never validated as a manifest of its own'
);
check(
    ($shippedReport['pinned_set']['status'] ?? null) === 'ok'
        && count($shippedReport['pinned_set']['names'] ?? []) === count($shippedNames),
    'the whole shipped library also co-loads clean, which is the only way the cross-manifest guards run at all'
);
foreach ($shippedReport['manifests'] ?? [] as $row) {
    if (($row['status'] ?? null) !== 'ok') {
        check(false, "shipped manifest '{$row['name']}' did not validate: " . ($row['message'] ?? ''));
    }
}
check(
    is_file((string) ($shippedReport['manifests'][0]['file'] ?? '')),
    'each row carries the real file path of the manifest it judged'
);

$shippedText = duo([$repo . '/manifests']);
check(
    $shippedText['exit'] === 0
        && str_contains($shippedText['stdout'], 'per manifest')
        && str_contains($shippedText['stdout'], 'pinned set')
        && str_contains($shippedText['stdout'], $repo . '/manifests/core.json'),
    'text mode reports the same verdict and names each manifest file'
);

// ======================================================================
echo "\n== acceptance 1: invalid keys, shapes, ranges, exclusivity, action names, provider declarations ==\n";

// --- invalid KEYS
refuses(
    solo_b(['actions' => [[
        'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'acme_b'], 'sceduled' => true,
    ]]]),
    "actions[0] contains unknown key(s): sceduled",
    'an unknown action key is refused, naming the entry index and the key'
);
refuses(
    solo_b(['actions' => [[
        'kind' => 'native', 'action' => 'transient.delete', 'args' => ['nmae' => 'acme_b'],
    ]]]),
    "actions[0].args contains unknown key(s) for native action 'transient.delete': nmae",
    "an unknown native-action ARGUMENT key is refused, naming the action and the key"
);
refuses(
    solo_b(['tables' => ['acme_b_slots' => array_merge(
        solo_b()['tables']['acme_b_slots'],
        ['invalidate' => [['table' => 'acme_b_cache', 'colum' => 'slot_id']]]
    )]]),
    'the invalidation vocabulary is closed and engine-owned',
    'a misspelled invalidate key is refused instead of meaning "this cache is never invalidated"'
);
refuses(
    solo_b(['providers' => [[
        'id' => 'acme-b-cache', 'version' => '1.0.0', 'source' => 'manifest',
        'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'], 'timeout' => 30,
    ]]]),
    'providers[0] requires exactly capabilities, id, plugin, source, and version',
    'an extra provider key is refused, naming the exact declaration index'
);

// --- invalid SHAPES
refuses(
    solo_b(['tables' => ['acme_b_slots' => array_merge(
        solo_b()['tables']['acme_b_slots'],
        ['columns' => 'slot_code']
    )]]),
    'columns must be an object keyed by column name',
    'a scalar where an object belongs produces this engine\'s own refusal, never a PHP TypeError'
);
refuses(
    solo_b(['tables' => ['acme_b_slots' => array_merge(
        solo_b()['tables']['acme_b_slots'],
        ['refs' => [['column' => 'room_id']]]
    )]]),
    'refs[0].kind',
    'a ref entry missing its kind is refused, naming the entry and the missing half'
);
refuses(
    solo_b(['actions' => 'rebuild-everything']),
    "manifest 'b' actions must be a list",
    'a scalar actions channel is refused before anything walks it'
);
refuses(
    solo_b(['block_attrs' => ['acme/b' => [['kind' => 'post', 'path' => '', 'type' => 'int']]]]),
    'block_attrs.acme/b[0].path must be a non-empty attribute name',
    'an empty attribute path is refused, and the path in the message walks straight to the rule'
);

// --- invalid RANGES / bounds
refuses(
    solo_b(['actions' => [[
        'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => str_repeat('x', 200)],
    ]]]),
    'actions[0].args.name must be a bounded string matching',
    'an argument past its declared bound is refused, printing the bound it had to match'
);
refuses(
    solo_b(['providers' => [[
        'id' => 'acme-b-cache', 'version' => '1.0', 'source' => 'manifest',
        'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'],
    ]]]),
    'providers[0].version must be an exact <major>.<minor>.<patch> string',
    'a truncated provider version is refused — a compatibility window needs an exact point'
);
refuses(
    solo_b(['actions' => [[
        'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'acme_b'],
        'effects' => [[
            'id' => 'e', 'kind' => 'cache', 'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => str_repeat('n', 600)],
        ]],
    ]]]),
    'selector.value must be a non-empty string of at most 512 bytes',
    'an over-long selector value is refused, and the refusal states the byte budget and what it got'
);

// --- EXCLUSIVITY rules
refuses(
    solo_b(['tables' => ['acme_b_slots' => array_merge(
        solo_b()['tables']['acme_b_slots'],
        ['identity' => ['mode' => 'natural_key', 'column' => 'slot_code', 'columns' => ['room_id', 'slot_code']]]
    )]]),
    "BOTH 'column' and 'columns'",
    'the two spellings of one identity vocabulary may not both appear'
);
refuses(
    solo_b(['block_attrs' => ['acme/b' => [[
        'kind' => 'post', 'kind_from' => ['attr' => 'type', 'map' => ['thing' => 'post']], 'path' => 'id', 'type' => 'int',
    ]]]]),
    'declares BOTH kind and kind_from',
    "a rule's ref kind is either static or dispatched from a sibling attribute, never both"
);
refuses(
    solo_b(['widgets' => ['acme_b' => ['settings' => ['body' => [
        'class' => 'authored', 'codec' => 'blocks', 'ref' => 'term',
    ]]]]]),
    'cannot declare codec and ref',
    'a widget setting is either a structured document or a single reference, and declaring both is refused'
);

// --- ACTION NAMES
refuses(
    solo_b(['actions' => [['kind' => 'native', 'action' => 'transient.flush', 'args' => []]]]),
    "names unknown native action 'transient.flush' — the engine vocabulary is closed",
    'a native action name outside the closed vocabulary is refused, and the refusal prints the legal set'
);
refuses(
    solo_b(['actions' => [['kind' => 'nativ', 'action' => 'transient.delete', 'args' => []]]]),
    'actions[0].kind must be "native" or "provider"',
    'a misspelled action kind is refused before either branch runs'
);

// --- PROVIDER DECLARATIONS
refuses(
    solo_b(['providers' => [[
        'id' => 'Acme_B_Cache', 'version' => '1.0.0', 'source' => 'manifest',
        'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'],
    ]]]),
    'providers[0].id must match ^[a-z][a-z0-9-]{0,63}$',
    'a provider id outside its bounded charset is refused, printing the pattern it had to match'
);
refuses(
    solo_b(['providers' => [[
        'id' => 'acme-b-cache', 'version' => '1.0.0', 'source' => 'manifest',
        'plugin' => 'acme-a/acme-a.php', 'capabilities' => ['flush'],
    ]]]),
    "disagrees with manifest 'b' plugin 'acme-b/acme-b.php'",
    "a provider claiming another plugin is refused — the executable half may not escape the declarative half's version window"
);
refuses(
    solo_b(['actions' => [[
        'kind' => 'provider', 'provider' => 'acme-b-cache', 'capability' => 'purge', 'args' => [],
    ]]]),
    "actions[0].capability 'purge' is not listed in provider 'acme-b-cache' declaration's capabilities",
    'an action naming a capability its own provider never advertised is refused'
);
refuses(
    solo_b(['actions' => [[
        'kind' => 'provider', 'provider' => 'acme-a-cache', 'capability' => 'flush', 'args' => [],
    ]]]),
    "must name a `providers` entry declared by manifest 'b'",
    "an action reaching across into ANOTHER adapter's provider is refused"
);

// ======================================================================
echo "\n== the pin set: cross-manifest guards run, and an isolated verdict says when it is not the whole story ==\n";

$shadowTable = manifest_b(['tables' => manifest_b()['tables'] + ['acme_a_rooms' => array_merge(
    manifest_a()['tables']['acme_a_rooms'],
    ['slug_column' => 'room_label', 'columns' => ['room_code' => ['class' => 'authored'], 'room_label' => ['class' => 'authored']]]
)]]);
$dir = fixtures(['a' => manifest_a(), 'b' => $shadowTable]);
$result = duo([$dir, '--format=json']);
$report = report($result);
check(
    (row($report, 'a')['status'] ?? null) === 'ok' && (row($report, 'b')['status'] ?? null) === 'ok',
    'two manifests that are each perfectly valid ALONE both report ok in isolation'
);
check(
    ($report['pinned_set']['status'] ?? null) === 'error'
        && str_contains((string) ($report['pinned_set']['message'] ?? ''), 'both declare tables.acme_a_rooms'),
    'and the co-loaded pin set still catches the conflict between them — the per-manifest pass could never have seen it'
);
check($result['exit'] === 1, 'a pin-set-only failure is still a failure (exit 1)');

$needsPin = manifest_b(['options' => ['acme_b_ref' => [
    'class' => 'authored', 'autoload' => 'yes', 'ref' => 'acme_room',
]]]);
$dir = fixtures(['a' => manifest_a(), 'b' => $needsPin]);
$report = report(duo([$dir, '--format=json']));
check(
    (row($report, 'b')['status'] ?? null) === 'error'
        && ($report['pinned_set']['status'] ?? null) === 'ok'
        && str_contains((string) (row($report, 'b')['pinned_set_note'] ?? ''), 'not self-contained'),
    'a manifest that legitimately depends on another being pinned is told exactly that, instead of being left to read an isolated refusal as a broken declaration'
);

$report = report(duo([$dir, '--manifest=a', '--pins=a,b', '--format=json']));
check(
    count($report['manifests'] ?? []) === 1 && (row($report, 'a')['status'] ?? null) === 'ok'
        && ($report['pinned_set']['names'] ?? null) === ['a', 'b'],
    '--manifest narrows what is checked individually while --pins independently chooses the co-loaded set'
);
$report = report(duo([$dir, '--all', '--format=json']));
check(
    ($report['pinned_set']['names'] ?? null) === ['a', 'b'],
    '--all is the explicit spelling of the default pin set'
);

// ======================================================================
echo "\n== acceptance 2: the deferred list is emitted on EVERY run, so silence never reads as validity ==\n";

foreach ([
    'a passing run' => duo([$repo . '/manifests', '--format=json']),
    'a failing run' => duo([fixtures(['b' => manifest_b(['tables' => ['acme_b_slots' => ['class' => 'nope']]])]), '--format=json']),
] as $label => $result) {
    $deferred = report($result)['deferred'] ?? [];
    $allDeferred = $deferred !== [];
    foreach ($deferred as $entry) {
        $allDeferred = $allDeferred
            && ($entry['status'] ?? null) === 'deferred'
            && ($entry['check'] ?? '') !== ''
            && ($entry['surface'] ?? '') !== ''
            && ($entry['why'] ?? '') !== '';
    }
    check($allDeferred, "$label reports every deferred check with its engine symbol, its surface, and why it cannot be answered offline (" . count($deferred) . ' entries)');
}
$text = duo([$repo . '/manifests']);
check(
    str_contains($text['stdout'], 'deferred — NOT checked here')
        && substr_count($text['stdout'], '[deferred]') === count($shippedReport['deferred']),
    'text mode prints the identical deferred list — the human-facing output is not the quieter one'
);

// Each deferred entry names a real engine symbol. A list that drifted into
// naming a function this engine no longer has would still LOOK authoritative,
// which is the failure mode a static list invites.
$engine = '';
foreach (glob($repo . '/agent/src/*.php') ?: [] as $file) {
    $engine .= (string) file_get_contents($file);
}
$namedSymbols = 0;
$resolved = 0;
foreach ($shippedReport['deferred'] ?? [] as $entry) {
    preg_match_all('/([A-Za-z_]+)::([a-z_]+)\(\)|(?<![:\w])([a-z_]{4,})\(\)/', (string) $entry['check'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $method = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        if ($method === '') {
            continue;
        }
        $namedSymbols++;
        $resolved += str_contains($engine, "function $method(") ? 1 : 0;
    }
}
check(
    $namedSymbols >= 9 && $namedSymbols === $resolved,
    "every engine symbol the deferred list names actually exists in agent/src ($resolved/$namedSymbols)"
);

// ======================================================================
echo "\n== acceptance 3, half one: the emitted schema is DERIVED, not authored ==\n";

$emit = duo(['--emit-schema']);
check($emit['exit'] === 0, "--emit-schema exits 0 (exit {$emit['exit']}, stderr: " . trim($emit['stderr']) . ')');
$schema = json_decode($emit['stdout'], true);
check(is_array($schema), '--emit-schema writes a parseable JSON document to stdout');
$schema = is_array($schema) ? $schema : [];
check(
    ($schema['schema'] ?? null) === 'duo-manifest-grammar/v1' && ($schema['spec_version'] ?? null) === DUO_SPEC_VERSION,
    'the document is versioned and states the spec version it describes'
);

$vocabularies = Policy::closed_vocabularies();
check(
    ($schema['vocabularies'] ?? null) === $vocabularies,
    'every emitted vocabulary is byte-identical to Policy::closed_vocabularies() — keys, values, AND declared order, which is load-bearing because the refusal messages print these sets in it'
);
check(
    ($schema['patterns'] ?? null) === Policy::grammar_patterns(),
    'every emitted pattern is the exact PCRE the engine hands to preg_match(), delimiters and modifiers included'
);
check(
    array_keys($schema['native_actions'] ?? []) === NativeActions::vocabulary(),
    'the emitted action list is NativeActions::vocabulary() itself'
);
$argsMatch = ($schema['native_actions'] ?? []) !== [];
foreach (NativeActions::arg_schemas() as $action => $args) {
    $argsMatch = $argsMatch && (($schema['native_actions'][$action]['args'] ?? null) === $args);
}
check($argsMatch, "and each action's argument schema is the one validate() checks against, required flags and patterns included");
check(
    ($schema['deferred'] ?? null) === ($shippedReport['deferred'] ?? null),
    'the schema document carries the same deferred list the validation report does — one statement of what is not covered, not two'
);
check(
    count($vocabularies) >= 25 && isset($vocabularies['effect_kinds'], $vocabularies['table_classes'], $vocabularies['engine_ref_kinds']),
    'the published grammar covers the closed sets an author actually has to get right (' . count($vocabularies) . ' vocabularies)'
);

$emitWithDir = duo([$repo . '/manifests', '--emit-schema']);
check(
    $emitWithDir['exit'] === 2 && str_contains($emitWithDir['stderr'], 'duo: manifest-validate:'),
    '--emit-schema refuses a manifests dir rather than implying the grammar came from those files'
);

// ======================================================================
echo "\n== acceptance 3, half two: schema and runtime validator, checked against ONE set of fixtures ==\n";

// For each closed vocabulary the document publishes, a value FROM the document
// must load and a value outside it must be refused with the document's own set
// printed back. If either side ever moved on its own, exactly one of these two
// halves would fail.
$slots = static fn(array $extra): array => solo_b(['tables' => ['acme_b_slots' => array_merge(
    solo_b()['tables']['acme_b_slots'],
    $extra
)]]);
$postType = static fn(array $decl): array => solo_b(['post_types' => ['acme_widget' => $decl]]);
$effect = static fn(array $effect): array => solo_b(['actions' => [[
    'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'acme_b'], 'effects' => [$effect],
]]]);
$okSelector = ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'acme_b_setting'];

foreach ($vocabularies['post_type_body_modes'] as $mode) {
    accepts($postType(['class' => 'authored', 'body' => $mode]), "post_types body mode '$mode' is published as legal and loads");
}
refuses(
    $postType(['class' => 'authored', 'body' => 'verbatm']),
    'the vocabulary is closed (' . implode(', ', $vocabularies['post_type_body_modes']) . ')',
    'and a body mode outside the published set is refused with that exact set printed back'
);
foreach ($vocabularies['post_type_phases'] as $phase) {
    accepts($postType(['class' => 'authored', 'phase' => $phase]), "post_types phase '$phase' is published as legal and loads");
}
refuses(
    $postType(['class' => 'authored', 'phase' => 'earliest']),
    'the vocabulary is closed (' . implode(', ', $vocabularies['post_type_phases']) . ')',
    'and a phase outside the published set is refused with that exact set printed back'
);
refuses(
    $slots(['class' => 'authored_snaphot']),
    'the table class vocabulary is closed',
    'a table class outside the published set is refused'
);
refuses(
    $slots(['identity' => ['mode' => 'natrual_key', 'column' => 'slot_code']]),
    'the identity vocabulary is closed and engine-owned',
    'an identity mode outside the published set is refused'
);
refuses(
    $effect(['id' => 'e', 'kind' => 'telepathy', 'mode' => 'restorable', 'selector' => $okSelector]),
    'is not one of the engine-owned effect kinds (' . implode(', ', $vocabularies['effect_kinds']) . ')',
    'an effect kind outside the published set is refused with the published set printed back'
);
refuses(
    $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'telekinesis', 'selector' => $okSelector]),
    'reversibility modes (' . implode(', ', $vocabularies['effect_modes']) . ')',
    'an effect mode outside the published set is refused with the published set printed back'
);
refuses(
    $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'checkpoint', 'type' => 'option', 'value' => 'acme_b_setting']]),
    'is not one of the engine-owned scopes (' . implode(', ', $vocabularies['effect_selector_scopes']) . ')',
    'a selector scope outside the published set is refused with the published set printed back'
);
refuses(
    $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'row', 'value' => 'acme_b_setting']]),
    'selector types (' . implode(', ', $vocabularies['effect_selector_types']) . ')',
    'a selector type outside the published set is refused with the published set printed back'
);
foreach ($vocabularies['provider_sources'] as $source) {
    accepts(
        solo_b(['providers' => [[
            'id' => 'acme-b-cache', 'version' => '1.0.0', 'source' => $source,
            'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'],
        ]]]),
        "provider source '$source' is published as legal and loads"
    );
}
refuses(
    solo_b(['providers' => [[
        'id' => 'acme-b-cache', 'version' => '1.0.0', 'source' => 'vendor',
        'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'],
    ]]]),
    'providers[0].source must be "manifest" or "plugin"',
    'a provider source outside the published set is refused'
);
foreach ($vocabularies['option_autoload_values'] as $autoload) {
    accepts(
        solo_b(['options' => ['acme_b_setting' => ['class' => 'authored', 'autoload' => $autoload]]]),
        "option autoload value '$autoload' is published as legal and loads"
    );
}
foreach ($vocabularies['option_autoload_sentinels'] as $sentinel) {
    accepts(
        solo_b(['options' => ['acme_b_setting' => ['class' => 'authored', 'autoload' => $sentinel]]]),
        "option autoload sentinel '$sentinel' is published as legal and loads"
    );
}
refuses(
    solo_b(['options' => ['acme_b_setting' => ['class' => 'authored', 'autoload' => 'true']]]),
    'needs autoload=preserve or an explicit supported autoload value ('
        . implode('|', $vocabularies['option_autoload_values']) . ')',
    'an autoload value outside the published set is refused with the published set printed back'
);
foreach ($vocabularies['user_meta_missing_user_modes'] as $mode) {
    accepts(
        solo_b(['user_meta' => ['acme_b_pref' => ['class' => 'authored', 'missing_user' => $mode]]]),
        "user_meta missing_user mode '$mode' is published as legal and loads"
    );
}
refuses(
    solo_b(['user_meta' => ['acme_b_pref' => ['class' => 'authored', 'missing_user' => 'ignore']]]),
    'missing_user must be block or warn',
    'a missing_user mode outside the published set is refused'
);
foreach ($vocabularies['attribute_value_types'] as $type) {
    accepts(
        solo_b(['block_attrs' => ['acme/b' => [['kind' => 'post', 'path' => 'id', 'type' => $type]]]]),
        "block attribute value type '$type' is published as legal and loads"
    );
}
refuses(
    solo_b(['block_attrs' => ['acme/b' => [['kind' => 'post', 'path' => 'ids', 'type' => 'array']]]]),
    'engine-owned (' . implode(', ', $vocabularies['attribute_value_types']) . ')',
    'an attribute value type outside the published set is refused with the published set printed back'
);
foreach ($vocabularies['widget_setting_codecs'] as $codec) {
    accepts(
        solo_b(['widgets' => ['acme_b' => ['settings' => ['body' => ['class' => 'authored', 'codec' => $codec]]]]]),
        "widget settings codec '$codec' is published as legal and loads"
    );
}
refuses(
    solo_b(['widgets' => ['acme_b' => ['settings' => ['body' => ['class' => 'authored', 'codec' => 'markdown']]]]]),
    'codec vocabulary is closed and engine-owned (' . implode(', ', $vocabularies['widget_setting_codecs']) . ')',
    'a widget codec outside the published set is refused with the published set printed back'
);
foreach ($vocabularies['widget_setting_refs'] as $ref) {
    accepts(
        solo_b(['widgets' => ['acme_b' => ['settings' => ['owner' => ['class' => 'authored', 'ref' => $ref]]]]]),
        "widget settings ref kind '$ref' is published as legal and loads"
    );
}
foreach ($vocabularies['dynamic_option_resolvers'] as $resolver) {
    accepts(
        solo_b(['dynamic_options' => ['theme_mods' => [
            'prefix' => 'theme_mods_', 'resolver' => $resolver, 'autoload' => 'yes',
            'sub_keys' => ['acme_b' => ['class' => 'authored']],
        ]]]),
        "dynamic_options resolver '$resolver' is published as legal and loads"
    );
}
refuses(
    solo_b(['dynamic_options' => ['theme_mods' => [
        'prefix' => 'theme_mods_', 'resolver' => 'active_plugin', 'autoload' => 'yes',
        'sub_keys' => ['acme_b' => ['class' => 'authored']],
    ]]]),
    'only active_stylesheet is supported in v1',
    'a dynamic_options resolver outside the published set is refused'
);
foreach ($vocabularies['engine_ref_kinds'] as $kind) {
    accepts(
        solo_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => $kind]]]),
        "engine-owned ref kind '$kind' is published as legal and loads"
    );
}
refuses(
    solo_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'psot']]]),
    'reference kind vocabulary is closed',
    'a ref kind outside the published engine set (and outside every declared id_kind) is refused'
);
foreach (NativeActions::vocabulary() as $action) {
    accepts(
        solo_b(['actions' => [['kind' => 'native', 'action' => $action, 'args' => ['name' => 'acme_b']]]]),
        "published native action '$action' is accepted by the runtime validator"
    );
}

// ======================================================================
echo "\n== exit codes and the command's own fail-closed paths ==\n";

foreach ([
    'no arguments at all' => [],
    'a directory that does not exist' => [sys_get_temp_dir() . '/duo-no-such-manifests-dir'],
    'a path that is a file, not a directory' => [$repo . '/manifests/core.json'],
    'an unsupported flag' => [$repo . '/manifests', '--strict'],
    'an unsupported --format' => [$repo . '/manifests', '--format=yaml'],
    'two positional directories' => [$repo . '/manifests', $repo . '/manifests'],
    '--pins together with --all' => [$repo . '/manifests', '--pins=core', '--all'],
    'a repeated --pins' => [$repo . '/manifests', '--pins=core', '--pins=woocommerce'],
    'a repeated --format' => [$repo . '/manifests', '--format=json', '--format=json'],
    '--manifest with an empty list' => [$repo . '/manifests', '--manifest='],
    '--manifest naming a manifest the directory does not have' => [$repo . '/manifests', '--manifest=not-a-manifest'],
    '--pins naming a manifest the directory does not have' => [$repo . '/manifests', '--pins=not-a-manifest'],
] as $label => $args) {
    $result = duo($args);
    check(
        $result['exit'] === 2 && str_starts_with($result['stderr'], 'duo: manifest-validate: '),
        "$label exits 2 with a \"duo: \" diagnostic on stderr (exit {$result['exit']}: " . trim($result['stderr']) . ')'
    );
}

$emptyDir = fixtures([]);
$result = duo([$emptyDir]);
check(
    $result['exit'] === 2 && str_contains($result['stderr'], 'contains no manifest'),
    'a directory with no manifests is an IO refusal, never a vacuous "0 checked, 0 errors" pass'
);

$unreadableDir = fixtures(['a' => manifest_a(), 'b' => manifest_b()]);
chmod("$unreadableDir/b.json", 0000);
if (is_readable("$unreadableDir/b.json")) {
    // Running as a user that ignores the mode bits (root, or a filesystem that
    // does not honor them). Say so rather than reporting a check that was never
    // actually performed.
    check(true, 'SKIPPED: unreadable-file refusal (this user can read a 0000 file, so the case is unreachable here)');
} else {
    $result = duo([$unreadableDir]);
    check(
        $result['exit'] === 2 && str_contains($result['stderr'], 'is not readable'),
        'an unreadable manifest file is an IO refusal (exit 2), named by path — never a silently skipped manifest'
    );
}
chmod("$unreadableDir/b.json", 0644);

$result = duo([$repo . '/manifests', '--format=json']);
check($result['exit'] === 0 && $result['stderr'] === '', 'a clean run writes nothing to stderr and exits 0');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
