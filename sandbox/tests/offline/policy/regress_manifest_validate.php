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
 * sandbox/tests/offline/policy/manifest_fixtures.php rather than copied — see that file's
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

$repo = dirname(__DIR__, 4);

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

require $repo . '/agent/src/Kernel/Canon.php';
require $repo . '/agent/src/Kernel/OptionState.php';
require $repo . '/agent/src/Policy/Policy.php';
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
    // A manifests dir is JSON *and* the code its manifests name. manifest_a()
    // declares a regenerator, and DUO-3327's check now resolves that file
    // instead of leaving it to the first apply — so the fixture ships it.
    manifest_fixture_code($root);
    register_shutdown_function(function () use ($root) {
        manifest_fixture_code_cleanup($root);
        foreach (glob("$root/interpreters/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir("$root/interpreters");
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
 * Fresh scratch SITE repo for one check; auto-removed at exit.
 *
 * The minimum a duo site repo is: a `site.duo.json` carrying its pins and its
 * policy. `--site` is validated against exactly that file's presence, and
 * Policy::load() validates the policy half of it the same way it validates a
 * manifest — so a malformed one here would surface as an ordinary refusal.
 */
function site_repo(array $policy, array $manifests = []): string {
    $root = sys_get_temp_dir() . '/duo_regress_manifest_validate_site_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    Canon::write_file("$root/site.duo.json", Canon::encode([
        'manifests' => $manifests,
        'policy' => $policy === [] ? new \stdClass() : $policy,
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    register_shutdown_function(function () use ($root) {
        @unlink("$root/site.duo.json");
        @rmdir($root);
    });
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
 *
 * Returns the engine's message so a caller with more to assert about it (the
 * identity-mode vocabulary, whose refusal spells its set out in prose rather
 * than an implode, is the case that needs this) can assert it directly rather
 * than re-running the command.
 */
function refuses(array $b, string $needle, string $msg): string {
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
    return $message;
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

$repeatedRows = [
    'cardinality' => 'one_or_more',
    'duplicates' => 'forbid',
    'order' => 'preserve',
];
$repeatedRule = ['class' => 'authored', 'ref' => 'post', 'repeated_rows' => $repeatedRows];
accepts(
    solo_b(['post_meta' => ['acme_b_many' => $repeatedRule]]),
    'post metadata accepts the closed ordered unique repeated-row storage declaration'
);
accepts(
    solo_b(['term_meta' => ['acme_b_many' => $repeatedRule]]),
    'term metadata shares the same explicit repeated-row storage declaration'
);
accepts(
    solo_b(['post_meta_patterns' => [[
        'match' => '^acme_b_many_',
    ] + $repeatedRule]]),
    'post metadata patterns may opt matching physical keys into the same repeated-row contract'
);
accepts(
    solo_b(['meta_patterns' => [[
        'match' => '^acme_b_many_',
    ] + $repeatedRule]]),
    'shared post/term metadata patterns may declare repeated rows explicitly'
);
accepts(
    solo_b(['post_meta' => ['acme_b_many' => array_merge($repeatedRule, ['repeated_rows' => [
        'order' => 'preserve', 'cardinality' => 'one_or_more', 'duplicates' => 'forbid',
    ]])]]),
    'repeated-row declaration object key order is not semantically significant'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => array_merge($repeatedRule, ['repeated_rows' => [
        'cardinality' => 'zero_or_more', 'duplicates' => 'forbid', 'order' => 'preserve',
    ]])]]),
    'repeated_rows must be exactly {cardinality: one_or_more, duplicates: forbid, order: preserve}',
    'repeated rows cannot encode an empty present value instead of honest key absence'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => array_merge(
        $repeatedRule,
        ['repeated_rows' => $repeatedRows + ['max' => 5]]
    )]]),
    'repeated_rows must be exactly',
    'the repeated-row grammar is closed and rejects undeclared cardinality fields'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => ['class' => 'runtime', 'repeated_rows' => $repeatedRows]]]),
    'repeated_rows is valid only for authored metadata',
    'runtime metadata cannot claim authored repeated-row materialization'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => $repeatedRule + ['order_preserving' => true]]]),
    'cannot combine repeated_rows with order_preserving',
    'row ordering cannot be confused with the associative-value order wrapper'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => array_merge($repeatedRule, ['ref' => 'post[]'])]]),
    'cannot combine repeated_rows with an array ref',
    'one repeated scalar ref per physical row cannot be replaced by a serialized ref list'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => $repeatedRule + ['plain_data' => true]]]),
    'repeated_rows requires one scalar value per database row',
    'plain-data collections cannot make the canonical outer list storage-ambiguous'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => $repeatedRule + ['cast' => 'csv']]]),
    'repeated_rows requires one scalar value per database row',
    'a CSV collection inside one row cannot masquerade as repeated physical rows'
);
refuses(
    solo_b(['post_meta' => ['acme_b_many' => $repeatedRule + [
        'json_refs' => [['path' => '$.id', 'kind' => 'post']],
    ]]]),
    'repeated_rows requires one scalar value per database row',
    'a structured document in one row cannot masquerade as repeated physical rows'
);
refuses(
    solo_b(['options' => ['acme_b_many' => $repeatedRule + ['autoload' => 'yes']]]),
    'only post_meta and term_meta storage has repeated rows',
    'whole options cannot declare a physical metadata-row grammar'
);
refuses(
    solo_b(['user_meta' => ['acme_b_many' => $repeatedRule]]),
    'only post_meta and term_meta storage has repeated rows',
    'user metadata remains on its existing explicit single-row contract'
);
refuses(
    solo_b(['option_patterns' => [[
        'match' => '^acme_b_many_',
        'autoload' => 'preserve',
    ] + $repeatedRule]]),
    'only post_meta and term_meta storage has repeated rows',
    'option patterns cannot silently widen repeated-row storage beyond metadata tables'
);

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
$cf7Row = row($shippedReport, 'contact-form-7');
check(
    ($cf7Row['status'] ?? null) === 'ok',
    'the shipped Contact Form 7 manifest remains a valid whole-type declaration'
);
$cf7Manifest = json_decode((string) file_get_contents($repo . '/manifests/contact-form-7.json'), true);
check(
    is_array($cf7Manifest)
        && (($cf7Manifest['post_types']['wpcf7_contact_form'] ?? null) === []),
    'contact-form-7 declares its non-public wpcf7_contact_form post type for discovery'
);
$dispositions = json_decode((string) file_get_contents($repo . '/manifests/dispositions.json'), true);
check(
    is_array($dispositions)
        && in_array(
            'post_types',
            $dispositions['manifests']['contact-form-7']['capabilities']['entity_sections'] ?? [],
            true
        ),
    'contact-form-7 disposition registers the declared post_types surface'
);
// The third link of the chain: the declared type reaches the CAPABILITY CLAIM,
// not merely the manifest and the disposition. That claim used to be read out
// of a generated `capabilities/registry.json`; it is now projected from the
// reviewed disposition on demand, so the assertion runs the projection rather
// than reading a file. Same property, one document closer to the source — and
// it is `ManifestDispositions::claim_from_disposition()` that expands
// `post_types` into `post_types.<key>` from the manifest's own keys, which is
// exactly the step a whole-type declaration could silently lose.
$cf7Claim = \Duo\ManifestDispositions::claim_from_disposition(
    $cf7Manifest,
    $dispositions['manifests']['contact-form-7'],
    $dispositions['manifests']['contact-form-7']['evidence'] ?? [],
    ['compatibility' => []]
);
check(
    in_array('post_types', $cf7Claim['surfaces'] ?? [], true)
        && in_array('post_types.wpcf7_contact_form', $cf7Claim['surfaces'] ?? [], true),
    'the projected capability claim carries the Contact Form 7 post_types surface, expanded to the declared type'
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

// The same real-world smoke with a real site repo attached: the shipped library
// must validate clean against a site policy too, not only against no site at
// all — the --site path is the one an adapter author actually runs.
$smokeSite = site_repo(
    ['options' => ['blogname' => ['class' => 'authored', 'autoload' => 'yes']]],
    ['core', 'woocommerce']
);
$shippedWithSite = duo([$repo . '/manifests', '--site=' . $smokeSite, '--format=json']);
$withSiteReport = report($shippedWithSite);
check(
    $shippedWithSite['exit'] === 0
        && ($withSiteReport['status'] ?? null) === 'ok'
        && ($withSiteReport['site'] ?? null) === $smokeSite
        && count($withSiteReport['manifests'] ?? []) === count($shippedNames),
    "the whole shipped library also validates clean WITH a site repo attached (exit {$shippedWithSite['exit']})"
);

// ======================================================================
echo "\n== acceptance 1: invalid keys, shapes, ranges, exclusivity, action names, provider declarations ==\n";

// --- adapter IDENTITY (DUO-3371)
// The manifests dir this command is pointed at is a SHIPPED library by every
// definition the engine has, so the file-name/declared-name rule DUO-3314 gave
// the site source applies here too — and reaches this command for free, because
// nothing in it reimplements a validator: the refusal below is the one
// Policy::load() throws, verbatim, so the authoring surface and the load-time
// surface cannot drift into two rules. The needle is the whole sentence for
// exactly that reason.
refuses(
    solo_b(['name' => 'renamed']),
    "declares name 'renamed' but its file name is 'b' — a pin names the file while every downstream identity "
        . '(dispositions, digests, diagnostics) keys off the declared name, so the two disagreeing is ambiguous '
        . 'identity. Make the declared name match the file name',
    'a manifest whose declared name disagrees with its file name is refused, naming both values and the fix'
);
accepts(solo_b(), 'the same manifest, declaring the name its file already carries, validates clean');

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
    'providers[0] must declare exactly capabilities, id, plugin, source, version',
    'an extra provider key is refused, naming the exact declaration index'
);
// DUO-3317: the optional `requires` grammar, refused at the same coordinate.
refuses(
    solo_b(['providers' => [[
        'id' => 'acme-b-cache', 'version' => '1.0.0', 'source' => 'manifest',
        'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'], 'requires' => [],
    ]]]),
    'providers[0].requires must be a non-empty object',
    'an empty requires object is refused, naming the declaration index'
);
refuses(
    solo_b(['providers' => [[
        'id' => 'acme-b-cache', 'version' => '1.0.0', 'source' => 'manifest',
        'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'],
        'requires' => ['php_version' => ['min' => '9.0', 'max' => '8.0']],
    ]]]),
    'providers[0].requires.php_version has a malformed range',
    'a requires version window with min >= max is refused through the shared {min,max} predicate'
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
refuses(
    solo_b(['shortcode_attrs' => ['contact-form' => [[
        'kind' => 'term', 'position' => 0,
        'lookup' => ['post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form'],
    ]]]]),
    'shortcode_attrs.contact-form[0] positional refs require static kind=post',
    'a positional post-meta lookup cannot be declared in the term or arbitrary id namespace'
);
refuses(
    solo_b(['shortcode_attrs' => ['contact-form' => [[
        'kind' => 'post', 'position' => 0, 'lint_ok' => false,
        'lookup' => ['post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form'],
    ]]]]),
    'shortcode_attrs.contact-form[0] positional refs have a closed vocabulary',
    'false-present lint_ok is not silently accepted on a positional codec'
);
refuses(
    solo_b(['shortcode_attrs' => ['contact-form' => [[
        'kind' => 'post', 'position' => 0, 'cast' => 'string',
        'lookup' => ['post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form'],
    ]]]]),
    'shortcode_attrs.contact-form[0] positional refs have a closed vocabulary',
    'named casts are not silently ignored by positional rules'
);
refuses(
    solo_b(['shortcode_attrs' => ['contact-form' => [
        ['kind' => 'post', 'position' => 0, 'lookup' => ['post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form']],
        ['kind' => 'post', 'position' => 0, 'lookup' => ['post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form']],
    ]]]),
    'a.position duplicates position 0',
    'two positional rules cannot rewrite the same shortcode span'
);
refuses(
    solo_b(['shortcode_attrs' => ['contact-form' => [[
        'kind' => 'post', 'position' => 0,
        'lookup' => ['post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form', 'extra' => true],
    ]]]]),
    'shortcode_attrs.contact-form[0] positional refs have a closed vocabulary',
    'positional lookup domains reject undeclared keys regardless of JSON key order'
);
refuses(
    solo_b(['shortcode_attrs' => ['contact-form' => [[
        'kind' => 'post', 'position' => 0, 'lookup' => [
            'post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form',
        ], 'future' => true,
    ]]]]),
    'shortcode_attrs.contact-form[0] positional refs have a closed vocabulary: exactly {kind,position,lookup}',
    'positional rules reject unknown top-level keys instead of silently ignoring future declarations'
);
refuses(
    solo_b(['shortcode_attrs' => ['contact-form' => [
        ['kind' => 'post', 'position' => 0, 'lookup' => [
            'post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form',
        ]],
        ['kind' => 'post', 'path' => 'id'],
    ]]]),
    'shortcode_attrs.contact-form cannot mix positional and named path rules',
    'a shortcode tag cannot declare incompatible positional and named callback selectors'
);
refuses(
    solo_b(['block_attrs' => ['acme/b' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 64,
        ],
    ]]]]),
    'block_attrs.acme/b[0].lookup is supported only for shortcode refs',
    'named alternate identities cannot silently acquire a block rewrite path'
);
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true, 'future' => true,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 64,
        ],
    ]]]]),
    'shortcode_attrs.acme[0] named alternate refs have a closed vocabulary',
    'named alternate rules refuse unknown top-level keys'
);
accepts(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_lengths' => [40, 64],
        ],
    ]]]]),
    'named alternate identities may declare a closed increasing set of native storage widths'
);
foreach ([
    'one width' => [40],
    'duplicate widths' => [40, 40],
    'descending widths' => [64, 40],
    'non-integer width' => [40, '64'],
    'width below prefix' => [6, 64],
    'width above engine bound' => [40, 129],
] as $case => $storedLengths) {
    refuses(
        solo_b(['shortcode_attrs' => ['acme' => [[
            'kind' => 'post', 'path' => 'id', 'required' => true,
            'lookup' => [
                'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
                'prefix_length' => 7, 'stored_lengths' => $storedLengths,
            ],
        ]]]]),
        'stored_lengths must be a strictly increasing list of at least two unique integers',
        "named alternate identities refuse $case"
    );
}
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 64, 'stored_lengths' => [40, 64],
        ],
    ]]]]),
    'lookup must be exactly {codec:hex-prefix,post_meta,post_type,prefix_length,stored_length}',
    'named alternate identities cannot declare scalar and plural storage-width forms together'
);
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 6,
        ],
    ]]]]),
    'with non-empty domains and 1 <= prefix_length <= stored_length <= 128',
    'a prefix cannot exceed the stored alternate identity it projects'
);
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => false,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 64,
        ],
    ]]]]),
    'shortcode_attrs.acme[0] named alternate refs require static kind=post and required=true',
    'an optional identity cannot fall through to CF7 title matching'
);
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 64, 'fallback' => 'title',
        ],
    ]]]]),
    'lookup must be exactly {codec:hex-prefix,post_meta,post_type,prefix_length,stored_length}',
    'lookup domains are closed and cannot smuggle a mutable title fallback'
);
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
        'lookup' => [
            'codec' => 'opaque-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 64,
        ],
    ]]]]),
    'lookup must be exactly {codec:hex-prefix,post_meta,post_type,prefix_length,stored_length}',
    'unknown alternate codecs refuse instead of falling back to raw values'
);
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
        'lookup' => [
            'codec' => 'hex-prefix', 'post_meta' => '_hash', 'post_type' => 'acme_b',
            'prefix_length' => 7, 'stored_length' => 129,
        ],
    ]]]]),
    'with non-empty domains and 1 <= prefix_length <= stored_length <= 128',
    'alternate identities stay inside the engine-owned storage bound'
);
refuses(
    solo_b(['shortcode_attrs' => ['acme' => [[
        'kind' => 'post', 'path' => 'id', 'required' => true,
    ]]]]),
    'shortcode_attrs.acme[0].required is allowed only on named alternate shortcode refs',
    'required cannot become a silently ignored modifier on ordinary named refs'
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

check(
    ($report['pinned_set']['files'] ?? null) === ['a' => "$dir/a.json", 'b' => "$dir/b.json"],
    'the pin-set row carries the file path of every co-loaded manifest — a cross-manifest refusal names manifests, so the row has to be what resolves those names to files'
);
$pinnedText = duo([$dir]);
check(
    str_contains($pinnedText['stdout'], "- a: $dir/a.json") && str_contains($pinnedText['stdout'], "- b: $dir/b.json"),
    'and text mode prints those paths under the failure, not only in the JSON report'
);

$needsPin = manifest_b(['options' => ['acme_b_ref' => [
    'class' => 'authored', 'autoload' => 'yes', 'ref' => 'acme_room',
]]]);
$dir = fixtures(['a' => manifest_a(), 'b' => $needsPin]);
$result = duo([$dir, '--format=json']);
$report = report($result);
check(
    (row($report, 'b')['status'] ?? null) === 'error'
        && ($report['pinned_set']['status'] ?? null) === 'ok'
        && str_contains((string) (row($report, 'b')['pinned_set_note'] ?? ''), 'not self-contained'),
    'a manifest that legitimately depends on another being pinned is told exactly that, instead of being left to read an isolated refusal as a broken declaration'
);
check(
    $result['exit'] === 1 && ($report['status'] ?? null) === 'error',
    'and it is still a FAILING run (exit 1): the note explains the refusal, it does not withdraw it — the manifest genuinely does not stand alone'
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
echo "\n== the site half: two guards read site.duo.json as INPUT, so --site changes the verdict ==\n";

// Loading with a null repo does not merely check LESS. Two guards take the
// site's own policy as input, so without it they can refuse a manifest that is
// correct on its real site — and the second one's remediation is advice to add
// an override the author already has. Both scenarios below are asserted in BOTH
// directions: refused-with-annotation without --site, accepted with it.
const SITE_NOTE = 'note: this refusal can be resolved by a site.duo.json this offline check was not given — '
    . 're-run with --site=<repo> to validate against the real site policy';

// --- scenario 1: a ref kind the SITE declares by declaring a table.
$siteKind = solo_b(['options' => ['acme_b_ref' => [
    'class' => 'authored', 'autoload' => 'yes', 'ref' => 'site_room',
]]]);
$dir = fixtures(['b' => $siteKind]);
$site = site_repo(['tables' => ['site_rooms' => [
    'class' => 'authored_snapshot',
    'id_kind' => 'site_room',
    'pk' => 'room_id',
    'slug_column' => 'room_code',
    'columns' => ['room_code' => ['class' => 'authored']],
    'refs' => [],
    'identity' => ['mode' => 'natural_key', 'column' => 'room_code'],
]]], ['b']);

$result = duo([$dir, '--format=json']);
$report = report($result);
$row = row($report, 'b');
check(
    $result['exit'] === 1 && ($row['status'] ?? null) === 'error'
        && str_contains((string) ($row['message'] ?? ''), 'reference kind vocabulary is closed'),
    'without --site, a ref kind the SITE declares is refused: the guard builds its vocabulary from manifests AND site policy, and it was handed only half'
);
check(
    ($row['site_policy_note'] ?? null) === SITE_NOTE,
    'and that refusal is ANNOTATED as possibly site-resolvable, naming the flag that answers the question'
);
check(
    str_contains((string) ($row['message'] ?? ''), 'declare the table, then name its id_kind'),
    "the engine's own message is untouched beside it — the annotation adds a field, it never rewrites a refusal this command did not author"
);
check(
    array_key_exists('site', $report) && $report['site'] === null,
    'a no-site run reports site=null rather than omitting the field, so a consumer can tell "no site" from "old format"'
);

$result = duo([$dir, '--site=' . $site, '--format=json']);
$report = report($result);
$row = row($report, 'b');
check(
    $result['exit'] === 0 && ($row['status'] ?? null) === 'ok' && ($report['site'] ?? null) === $site,
    'with --site, the same manifest is ACCEPTED and the report names the site repo it read'
);
check(!array_key_exists('site_policy_note', $row), 'and nothing is annotated, because nothing was withheld');

// --- scenario 2: an option two manifests declare differently, which the site
// resolves. This one fails in the PINNED phase (no single manifest can conflict
// with itself), so the annotation has to reach that row too.
// Both stripped to their declarative core (no tables, no post types, no
// providers): the subject here is one option name, and either adapter's other
// declarations would drag in the cross-manifest guards this scenario is not
// about — including the id_kind uniqueness one, since both fixtures own a
// keyspace of their own.
$bare = ['tables' => [], 'post_types' => [], 'providers' => []];
$dir = fixtures([
    'a' => manifest_a($bare + ['options' => ['acme_shared' => ['class' => 'authored', 'autoload' => 'yes']]]),
    'b' => manifest_b($bare + ['options' => ['acme_shared' => ['class' => 'runtime', 'autoload' => 'no']]]),
]);
$site = site_repo(['options' => ['acme_shared' => ['class' => 'authored', 'autoload' => 'yes']]], ['a', 'b']);

$result = duo([$dir, '--format=json']);
$report = report($result);
$pinnedRow = $report['pinned_set'] ?? [];
check(
    $result['exit'] === 1 && ($pinnedRow['status'] ?? null) === 'error'
        && str_contains((string) ($pinnedRow['message'] ?? ''), 'declare contradictory rules for options.acme_shared'),
    'without --site, two manifests declaring one option differently are refused by the cross-manifest guard'
);
check(
    ($pinnedRow['site_policy_note'] ?? null) === SITE_NOTE,
    'and the PIN-SET row carries the annotation too — this class of refusal never appears on a per-manifest row'
);
check(
    str_contains((string) ($pinnedRow['message'] ?? ''), 'Add an explicit site.duo.json policy.options.acme_shared override'),
    "the engine's remediation is printed verbatim, which is precisely why the note is needed: on the real site that override already exists"
);
$text = duo([$dir]);
check(
    str_contains($text['stdout'], SITE_NOTE) && str_contains($text['stdout'], '(none — site policy is NOT part of this check'),
    'text mode prints the annotation and says up front that no site policy was read — the human-facing output is not the quieter one'
);

$result = duo([$dir, '--site=' . $site, '--format=json']);
$report = report($result);
check(
    $result['exit'] === 0 && ($report['pinned_set']['status'] ?? null) === 'ok',
    'with --site, the site rule resolves the option and the pin set loads clean'
);
check(
    in_array('site.duo.json policy.tables / policy.options', array_column($report['deferred'] ?? [], 'surface'), true),
    'the site-policy half is a PERMANENT row in the always-emitted deferred list — present even on a --site run, because that list states the boundary of the check, never the outcome of one'
);

// --- a malformed site.duo.json is ONE usage-level refusal, not a verdict about
// anybody's manifest. Every phase loads that same file, so without a pre-flight
// the site's single refusal is repeated once per manifest plus once for the pin
// set — sixteen "your manifest is broken" rows for one broken line that is in
// none of them.
$badSite = site_repo(['tables' => ['site_rooms' => ['class' => 'not_a_class']]], ['a', 'b']);
$result = duo([$repo . '/manifests', '--site=' . $badSite]);
check(
    $result['exit'] === 2
        && str_starts_with($result['stderr'], 'duo: manifest-validate: ')
        && str_contains($result['stderr'], 'site.duo.json this command cannot load')
        && str_contains($result['stderr'], "table 'site_rooms' (declared by site.duo.json)"),
    'a malformed site.duo.json is refused ONCE, as a usage error naming site.duo.json and carrying the engine\'s own reason'
);
check(
    $result['stdout'] === '' && substr_count($result['stderr'], 'not_a_class') === 1,
    'exactly one report and exactly one copy of the site\'s refusal — no per-manifest rows were produced at all'
);

// --- the pre-flight's empty-pin load still walks the manifests directory, so
// a defect THERE also surfaces through it. That refusal must not wear the
// --site headline: the site file is fine, and blaming it sends the author to
// the wrong file.
$dir = fixtures([
    'a' => manifest_a(),
    'dispositions' => ['format' => 'duo-dispositions/v1'],
]);
$goodSite = site_repo(['tables' => new \stdClass()], ['a']);
$result = duo([$dir, '--site=' . $goodSite]);
check(
    $result['exit'] === 2
        && !str_contains($result['stderr'], 'site.duo.json this command cannot load')
        && str_contains($result['stderr'], 'dispositions'),
    'a broken MANIFESTS DIR surfacing through the site pre-flight is reported unprefixed — the --site headline is reserved for defects the engine attributes to site.duo.json'
);

// ======================================================================
echo "\n== the code half a manifest NAMES: interpreters and regenerators are resolved, not deferred ==\n";

// Policy resolves both lazily, so a manifest naming a file that does not exist
// used to load clean and report `ok` — the loudest possible thing to get wrong
// about a manifest's code half was the one thing this command did not look at.
// Both resolutions are pure file-system + class-contract questions about the
// very directory being checked, so both belong here.
$dir = fixtures(['a' => manifest_a()]);
$report = report(duo([$dir, '--format=json']));
check(
    (row($report, 'a')['status'] ?? null) === 'ok',
    'a manifest whose declared regenerator file is present, and defines the contract class, still loads clean'
);

unlink("$dir/regenerators/acme-a.php");
$result = duo([$dir, '--format=json']);
$report = report($result);
check(
    $result['exit'] === 1
        && str_contains((string) (row($report, 'a')['message'] ?? ''), "wants regenerator 'acme-a' but $dir/regenerators/acme-a.php is missing"),
    'a declared regenerator with no file is an ordinary per-manifest error naming the exact path it looked for'
);

file_put_contents("$dir/regenerators/acme-a.php", "<?php\nnamespace Duo\\Regenerators;\nfinal class NotAcmeA {}\n");
$result = duo([$dir, '--format=json']);
$report = report($result);
check(
    $result['exit'] === 1
        && str_contains((string) (row($report, 'a')['message'] ?? ''), 'must define \\Duo\\Regenerators\\AcmeA with regenerate(int $localId): void'),
    'a regenerator file defining the wrong class is refused with the class-and-signature contract it had to satisfy'
);

$withInterpreter = fixtures(['b' => solo_b(['interpreter' => 'acme-int'])]);
$result = duo([$withInterpreter, '--format=json']);
$report = report($result);
check(
    $result['exit'] === 1
        && str_contains((string) (row($report, 'b')['message'] ?? ''), "wants interpreter 'acme-int' but $withInterpreter/interpreters/acme-int.php is missing"),
    'the same for a declared interpreter with no file — named at load, not at the first meta lookup on a live target'
);

mkdir("$withInterpreter/interpreters", 0777, true);
file_put_contents("$withInterpreter/interpreters/acme-int.php", "<?php\nnamespace Duo\\Interpreters;\nfinal class Wrong {}\n");
$result = duo([$withInterpreter, '--format=json']);
$report = report($result);
check(
    $result['exit'] === 1
        && str_contains((string) (row($report, 'b')['message'] ?? ''), 'must define \\Duo\\Interpreters\\AcmeInt with post_meta_rule(string, array): ?array'),
    'an interpreter file defining the wrong class is refused with the contract it had to satisfy'
);

file_put_contents(
    "$withInterpreter/interpreters/acme-int.php",
    "<?php\nnamespace Duo\\Interpreters;\nfinal class AcmeInt {\n"
        . "    public function __construct(private object \$policy) {}\n"
        . "    public function post_meta_rule(string \$key, array \$allMeta): ?array { return null; }\n}\n"
);
$result = duo([$withInterpreter, '--format=json']);
check(
    $result['exit'] === 0,
    'and a correct one loads — the check is the real loading contract, not a file-exists proxy for it'
);

// ======================================================================
echo "\n== --no-code: the trust boundary that resolution creates, and the escape from it ==\n";

// Resolving a declared interpreter EXECUTES it — the file's top level runs on
// require, and its constructor runs on instantiation. That is not a claim to
// take on trust either: the fixture below writes a marker file from its top
// level, so "did this command run hostile code" is answered by looking for the
// marker rather than by reading the implementation. Both directions, because
// only the pair proves anything: the default mode must still resolve (and so
// must still trip the marker), and --no-code must not.
$evilDir = fixtures(['b' => solo_b(['interpreter' => 'acme-evil'])]);
$marker = $evilDir . '/EVIL_RAN';
mkdir("$evilDir/interpreters", 0777, true);
file_put_contents(
    "$evilDir/interpreters/acme-evil.php",
    "<?php\nnamespace Duo\\Interpreters;\n"
        . "file_put_contents('" . $marker . "', 'top level ran');\n"
        . "final class AcmeEvil {\n"
        . "    public function __construct(private object \$policy) {}\n"
        . "    public function post_meta_rule(string \$key, array \$allMeta): ?array { return null; }\n}\n"
);
register_shutdown_function(static function () use ($marker) {
    @unlink($marker);
});

@unlink($marker);
$result = duo([$evilDir, '--format=json']);
check(
    $result['exit'] === 0 && is_file($marker),
    'DEFAULT mode really does execute a declared interpreter file — the marker it writes from its own top level is on disk, which is why the trust boundary is documented rather than assumed'
);

@unlink($marker);
$result = duo([$evilDir, '--no-code', '--format=json']);
$report = report($result);
check(
    !is_file($marker),
    '--no-code does NOT execute it: the same fixture, the same directory, and the marker was never written'
);
check(
    $result['exit'] === 0 && (row($report, 'b')['status'] ?? null) === 'ok'
        && ($report['code'] ?? null) === 'skipped',
    'and the declaration half is still fully validated under --no-code, with the report stating code=skipped'
);
$noCodeRow = null;
foreach ($report['deferred'] ?? [] as $entry) {
    if (str_starts_with((string) ($entry['why'] ?? ''), '--no-code:')) {
        $noCodeRow = $entry;
    }
}
check(
    ($noCodeRow['why'] ?? null) === '--no-code: declared interpreter/regenerator PHP was not loaded or '
        . 'contract-checked — use for a first look at an untrusted package; a full validation requires a trusted dir',
    'and the always-emitted list carries a not-performed row saying exactly what was skipped and when to use the flag'
);
check(
    ($noCodeRow['status'] ?? null) === 'deferred'
        && ($noCodeRow['check'] ?? null) === 'Policy::interpreters() / Policy::regenerators()',
    'naming the two engine symbols that were not run, in the same shape as every other row'
);

$noCodeText = duo([$evilDir, '--no-code']);
check(
    str_contains($noCodeText['stdout'], 'manifest code: --no-code')
        && str_contains($noCodeText['stdout'], 'declared interpreter/regenerator PHP was not loaded or contract-checked'),
    'text mode says it in the header AND in the list — a --no-code pass can never be read as a full one'
);

// The counterpart: --no-code cannot hide a broken declaration, only unread code.
$result = duo([$evilDir, '--no-code', '--manifest=b', '--format=json']);
check($result['exit'] === 0, '--no-code composes with the ordinary selection flags');
$missingRegen = fixtures(['a' => manifest_a()]);
unlink("$missingRegen/regenerators/acme-a.php");
$report = report(duo([$missingRegen, '--no-code', '--format=json']));
check(
    (row($report, 'a')['status'] ?? null) === 'ok',
    'a missing regenerator file passes under --no-code — which is precisely why the skip is reported: the flag really does buy less checking, not the same checking more safely'
);
$report = report(duo([$missingRegen, '--format=json']));
check(
    (row($report, 'a')['status'] ?? null) === 'error',
    'and the identical directory still fails without the flag, so the default has not quietly become the weaker one'
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
// Recursive since the module move (ROUND 3 TRAIN 1): agent/src is a tree of
// module directories now, and a flat glob would leave $engine empty and make
// every symbol check below pass vacuously.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/agent/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
        $engine .= (string) file_get_contents($file->getPathname());
    }
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
    ($schema['schema'] ?? null) === 'duo-manifest-grammar/v2' && ($schema['spec_version'] ?? null) === DUO_SPEC_VERSION,
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
$argFields = [];
foreach (NativeActions::arg_schemas() as $action => $args) {
    $argsMatch = $argsMatch && (($schema['native_actions'][$action]['args'] ?? null) === $args);
    foreach ($args as $rule) {
        $argFields = array_merge($argFields, array_keys($rule));
    }
}
check($argsMatch, "and each action's argument schema is the one validate() checks against, required flags and patterns included");
check(
    $argFields !== [] && array_diff(array_unique($argFields), ['type', 'required', 'pattern']) === [],
    'the published argument schemas are PROJECTED to the arg-schema fields only (type/required/pattern) — a future per-action or per-argument annotation on the engine const cannot leak into this document as though it were an argument an author may write'
);
check(
    ($schema['deferred'] ?? null) === ($shippedReport['deferred'] ?? null),
    'the schema document carries the same deferred list the validation report does — one statement of what is not covered, not two'
);
check(
    count($vocabularies) >= 25 && isset($vocabularies['effect_kinds'], $vocabularies['table_classes'], $vocabularies['engine_ref_kinds']),
    'the published grammar covers the closed sets an author actually has to get right (' . count($vocabularies) . ' vocabularies)'
);

// A published document has to state its own boundary, in the document — an
// editor built on `vocabularies` alone would offer keys the engine refuses and
// believe it held the whole grammar.
$coverage = $schema['coverage'] ?? [];
check(
    str_contains((string) ($coverage['vocabularies'] ?? ''), 'VALUE vocabularies only')
        && str_contains((string) ($coverage['patterns'] ?? ''), 'NAMED SUBSET'),
    'the document scopes its own two published sets: VALUE vocabularies, and a NAMED SUBSET of the bounded patterns'
);
$notIncluded = implode(' | ', (array) ($coverage['not_included'] ?? []));
foreach ([
    'key vocabularies' => 'key vocabularies',
    'conditional subsets' => 'conditional subsets',
    'the unpublished inline patterns' => 'unnamed patterns',
    'the pin-dependent halves' => 'pin-dependent halves',
] as $label => $needle) {
    check(str_contains($notIncluded, $needle), "and names $label as NOT included");
}
check(
    count((array) ($coverage['not_included'] ?? [])) === 4,
    'the coverage note is a list of exactly the four boundaries, not prose a consumer has to parse'
);

$emitWithDir = duo([$repo . '/manifests', '--emit-schema']);
check(
    $emitWithDir['exit'] === 2 && str_contains($emitWithDir['stderr'], 'duo: manifest-validate:'),
    '--emit-schema refuses a manifests dir rather than implying the grammar came from those files'
);

// ======================================================================
echo "\n== WP-4.1: duo-manifest-grammar/v2's two new blocks, and a MUTATION proof that they are derived ==\n";

// The v1 DRIFT group above compares the emitted document with the live
// accessors IN THIS PROCESS. That is a real check and it stays, but it cannot
// tell a derived list from a hand-copied one: a literal array typed into
// ManifestValidate.php equals the accessor on the day it is typed, and only
// stops equalling it the day the engine moves — which is the day nobody is
// looking. So the two blocks WP-4.1 adds are checked a second way: the engine
// constants they claim to come from are MUTATED in a fixture-scoped copy of
// the shipped trees, and the emitted document must move with them. A copied
// list fails every case below while passing every case above.
require_once $repo . '/agent/src/Adapter/AdapterCertification.php';

$partition = \Duo\AdapterCertification::topLevelKeyPartition();
$topLevel = $schema['top_level_keys'] ?? [];
check(
    ($topLevel['entity_sections'] ?? null) === $partition['entity_sections']
        && ($topLevel['field_sections'] ?? null) === $partition['field_sections']
        && ($topLevel['non_surface_keys'] ?? null) === $partition['non_surface_keys'],
    'top_level_keys publishes the signer\'s three arms exactly as AdapterCertification::topLevelKeyPartition() returns them'
);
$mergedPartition = array_merge($partition['entity_sections'], $partition['field_sections'], $partition['non_surface_keys']);
sort($mergedPartition, SORT_STRING);
check(
    ($topLevel['all'] ?? null) === $mergedPartition && count($mergedPartition) === count(array_unique($mergedPartition)),
    'and `all` is the merged, sorted, disjoint set (' . count($mergedPartition) . ' keys) rather than a fourth list'
);
check(
    str_contains((string) ($topLevel['enforced_by'] ?? ''), 'siteRatification')
        && str_contains((string) ($topLevel['enforced_by'] ?? ''), 'validate_adapter_contract')
        && str_contains((string) ($topLevel['not_enforced_by'] ?? ''), 'ManifestValidator')
        && str_contains((string) ($topLevel['status'] ?? ''), 'WP-4.3'),
    'the block states BOTH halves of the truth — it refuses at signing at every version AND at load for a spec_version 3 manifest, while a v2 manifest still admits an unrecognised key — and names the rider'
);
// The growth rule belongs in the published document and not only in the spec:
// an author reading `all` would otherwise conclude that the 33 keys are the
// whole answer, and be wrong for any manifest that declares a feature.
check(
    str_contains((string) ($topLevel['status'] ?? ''), 'engine feature')
        && str_contains((string) ($topLevel['status'] ?? ''), '_draft'),
    'and it publishes how the set GROWS (a key claimed by an implemented engine feature) and the one key refused on the merits (`_draft`), so `all` is not mistaken for the whole answer'
);

$window = $schema['spec_window'] ?? [];
check(
    ($window['engine_supported'] ?? null) === DUO_SPEC_VERSION
        && ($window['accepted'] ?? null) === [DUO_SPEC_VERSION - 1, DUO_SPEC_VERSION]
        && ($window['n_minus_1_accepted'] ?? null) === true,
    'spec_window reports this engine accepting {' . (DUO_SPEC_VERSION - 1) . ', ' . DUO_SPEC_VERSION
        . '} — WP-4.2\'s acceptance window, reported here because it is MEASURED and not restated'
);
check(
    ($window['probed'] ?? null) === [DUO_SPEC_VERSION - 2, DUO_SPEC_VERSION - 1, DUO_SPEC_VERSION, DUO_SPEC_VERSION + 1],
    'and publishes the probed range, so "refused" is distinguishable from "never asked" (' . implode(', ', (array) ($window['probed'] ?? [])) . ')'
);
check(
    ($window['probed'][0] ?? null) === DUO_SPEC_VERSION - 2
        && !in_array(DUO_SPEC_VERSION - 2, (array) ($window['accepted'] ?? []), true),
    'and N-2 was ASKED and refused, which is what makes "the floor is exactly N-1" a measurement rather than an assumption'
);
check(
    str_contains((string) ($window['status'] ?? ''), 'is ENFORCED here')
        && str_contains((string) ($window['status'] ?? ''), '§ v3.1'),
    'the window block says the N/N-1 acceptance window IS enforced here, naming the section that specifies it'
);

/** Copy one shipped tree into the scratch root, file by file. */
function copy_tree(string $src, string $dst): void {
    mkdir($dst, 0777, true);
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($walk as $item) {
        $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) {
            @mkdir($target, 0777, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function remove_tree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walk as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// agent/ + cli/ + recovery/ is exactly what the command needs: boot() resolves
// the two defines out of agent/duo.php and the engine files out of
// agent/duo-classmap.php, and cli/duo requires recovery/rollback-control.php at
// startup. manifests/ is deliberately NOT copied — --emit-schema reads no
// declaration directory, which is itself a property this copy exercises.
$mutantRoot = sys_get_temp_dir() . '/duo_regress_emit_derivation_' . bin2hex(random_bytes(4));
foreach (['agent', 'cli', 'recovery'] as $tree) {
    copy_tree($repo . '/' . $tree, $mutantRoot . '/' . $tree);
}
register_shutdown_function(static fn() => remove_tree($mutantRoot));

/**
 * The grammar document emitted by the COPY, decoded.
 *
 * @return array<string,mixed>
 */
function emit_from(string $root): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cli/duo')
        . ' manifest-validate --emit-schema';
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new \RuntimeException("could not run: $cmd");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) {
        throw new \RuntimeException("emit from $root exited $exit: " . trim($stderr));
    }
    $decoded = json_decode($stdout, true);
    return is_array($decoded) ? $decoded : [];
}

$baseline = emit_from($mutantRoot);
check(
    $baseline === $schema,
    'the untouched copy emits a byte-equal document to the real tree, so any difference below is the mutation and nothing else'
);

// MUTATION 1 — the signer's partition gains a key. A hand-copied list in the
// emitter does not move; a derived one does.
$certFile = $mutantRoot . '/agent/src/Adapter/AdapterCertification.php';
$certSource = (string) file_get_contents($certFile);
$anchor = "'providers', 'spec_version', 'theme', 'theme_version_range', 'version_range',";
check(str_contains($certSource, $anchor), 'the NON_SURFACE_KEYS anchor is present in the copied engine');
file_put_contents($certFile, str_replace(
    $anchor,
    "'providers', 'spec_version', 'theme', 'theme_version_range', 'version_range', 'acme_reserved_marker',",
    $certSource
));
$mutated = emit_from($mutantRoot);
check(
    in_array('acme_reserved_marker', $mutated['top_level_keys']['non_surface_keys'] ?? [], true)
        && in_array('acme_reserved_marker', $mutated['top_level_keys']['all'] ?? [], true),
    'MUTATION 1: a key added to the engine\'s NON_SURFACE_KEYS appears in the emitted partition — the block is READ from the constant, not typed beside it'
);
check(
    count($mutated['top_level_keys']['all'] ?? []) === count($mergedPartition) + 1
        && ($mutated['spec_window'] ?? null) === ($baseline['spec_window'] ?? null),
    'and it moves that block ONLY — the window block is byte-unchanged, so the two are independently derived'
);
file_put_contents($certFile, $certSource);
check(emit_from($mutantRoot) === $baseline, 'restoring the constant restores the document exactly');

// MUTATION 2 — the shipped window itself is NARROWED back to exact equality.
// Before WP-4.2 this mutation ran the other way (widen and watch the document
// widen); now that the window is the shipped behaviour, the case a restated
// `[DUO_SPEC_VERSION - 1, DUO_SPEC_VERSION]` in the emitter could never pass is
// the narrowing. Same claim, exercised from the side the engine is now on.
$grammarFile = $mutantRoot . '/agent/src/Adapter/AdapterContractGrammar.php';
$grammarSource = (string) file_get_contents($grammarFile);
$windowAnchor = 'return [$supported - 1, $supported];';
check(str_contains($grammarSource, $windowAnchor), 'the accepted-window return is present in the copied engine');
file_put_contents($grammarFile, str_replace($windowAnchor, 'return [$supported];', $grammarSource));
$narrowed = emit_from($mutantRoot);
check(
    ($narrowed['spec_window']['accepted'] ?? null) === [DUO_SPEC_VERSION]
        && ($narrowed['spec_window']['n_minus_1_accepted'] ?? null) === false,
    'MUTATION 2: narrowing the shipped window back to exact equality narrows the emitted `accepted` set — the window is MEASURED by running the refusal, never restated'
);
check(
    ($narrowed['top_level_keys'] ?? null) === ($baseline['top_level_keys'] ?? null),
    'and the partition block is untouched by it'
);
file_put_contents($grammarFile, $grammarSource);

// MUTATION 3 — the define moves. The document must follow the engine it was
// emitted from, including the probe range it centres on that engine.
$duoFile = $mutantRoot . '/agent/duo.php';
$duoSource = (string) file_get_contents($duoFile);
file_put_contents($duoFile, str_replace(
    "define('DUO_SPEC_VERSION', " . DUO_SPEC_VERSION . ')',
    "define('DUO_SPEC_VERSION', " . (DUO_SPEC_VERSION + 5) . ')',
    $duoSource
));
$bumped = emit_from($mutantRoot);
check(
    ($bumped['spec_version'] ?? null) === DUO_SPEC_VERSION + 5
        && ($bumped['spec_window']['engine_supported'] ?? null) === DUO_SPEC_VERSION + 5
        && ($bumped['spec_window']['accepted'] ?? null) === [DUO_SPEC_VERSION + 4, DUO_SPEC_VERSION + 5],
    'MUTATION 3: moving DUO_SPEC_VERSION in the copy moves the document\'s version, its supported integer and its whole accepted window together — the window travels WITH N, which is why the flip re-stamps no manifest'
);
file_put_contents($duoFile, $duoSource);
check(emit_from($mutantRoot) === $baseline, 'and restoring the define restores the document exactly');

// The shipped tree is untouched by all of the above — the mutations only ever
// wrote inside the scratch copy. Assert it rather than trust it: a mutation
// helper with the wrong root would otherwise leave the engine edited under a
// green suite.
check(
    ($shippedNow = duo(['--emit-schema']))['exit'] === 0 && json_decode($shippedNow['stdout'], true) === $schema,
    'the real tree still emits the original document — every mutation stayed inside the scratch copy'
);

// ======================================================================
echo "\n== acceptance 3, half two: schema and runtime validator, checked against ONE set of fixtures ==\n";

// For each closed vocabulary the document publishes, a value FROM the document
// must load and a value outside it must be refused with the document's own set
// printed back. If either side ever moved on its own, exactly one of these two
// halves would fail.
//
// ALL of them, not a representative sample: $covered below records which
// vocabulary each check exercises, and the last check in this group asserts the
// covered set is exactly the published set. A 32nd vocabulary therefore fails
// this suite until it is exercised here too, which is the only way "the schema
// and the validator agree" stays a claim about the whole document rather than
// about whichever entries someone remembered.
//
// A handful of vocabularies cannot be reached through a manifest fixture at all
// (a section name is not a declaration; a pattern-key map has no refusal of its
// own). Those are exercised against the engine directly — the refusal message's
// own implode, or the accessor's observable behavior — rather than skipped: the
// property being defended is that a literal rewrite of closed_vocabularies()
// fails this suite, and skipping is exactly how a rewrite would survive.
$covered = [];

$slots = static fn(array $extra): array => solo_b(['tables' => ['acme_b_slots' => array_merge(
    solo_b()['tables']['acme_b_slots'],
    $extra
)]]);
$extraTable = static fn(string $name, array $decl): array => solo_b(['tables' => solo_b()['tables'] + [$name => $decl]]);
$postType = static fn(array $decl): array => solo_b(['post_types' => ['acme_widget' => $decl]]);
$effect = static fn(array $effect): array => solo_b(['actions' => [[
    'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'acme_b'], 'effects' => [$effect],
]]]);
$okSelector = ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'acme_b_setting'];

// solo_b() declares exactly these two table id_kinds, and all three kind
// vocabularies union their engine base with the declared set and sort it — so
// the refusal's printed set is computable here from the PUBLISHED base, which
// is what makes the needle a real cross-check rather than a spelling test.
$declaredIdKinds = ['acme_room', 'acme_slot'];
$legalKinds = static function (array $base) use ($declaredIdKinds): string {
    $all = array_merge($base, $declaredIdKinds);
    sort($all, SORT_STRING);
    return implode(', ', $all);
};

/** The engine's own refusal for a surface no manifest fixture can reach. */
function engine_message(callable $fn): string {
    try {
        $fn();
    } catch (\Throwable $t) {
        return $t->getMessage();
    }
    return '';
}

$covered['post_type_body_modes'] = true;
foreach ($vocabularies['post_type_body_modes'] as $mode) {
    accepts($postType(['class' => 'authored', 'body' => $mode]), "post_types body mode '$mode' is published as legal and loads");
}
refuses(
    $postType(['class' => 'authored', 'body' => 'verbatm']),
    'the vocabulary is closed (' . implode(', ', $vocabularies['post_type_body_modes']) . ')',
    'and a body mode outside the published set is refused with that exact set printed back'
);
$covered['post_type_phases'] = true;
foreach ($vocabularies['post_type_phases'] as $phase) {
    accepts($postType(['class' => 'authored', 'phase' => $phase]), "post_types phase '$phase' is published as legal and loads");
}
refuses(
    $postType(['class' => 'authored', 'phase' => 'earliest']),
    'the vocabulary is closed (' . implode(', ', $vocabularies['post_type_phases']) . ')',
    'and a phase outside the published set is refused with that exact set printed back'
);

// --- table_classes: every published class in a declaration of its own shape.
$covered['table_classes'] = true;
foreach ($vocabularies['table_classes'] as $class) {
    $decl = match ($class) {
        // The one class with a full grammar behind it. Its own keyspace, so the
        // cross-manifest id_kind uniqueness guard has nothing to say.
        'authored_snapshot' => [
            'class' => 'authored_snapshot', 'id_kind' => 'acme_extra', 'pk' => 'extra_id',
            'slug_column' => 'extra_code', 'columns' => ['extra_code' => ['class' => 'authored']],
            'refs' => [], 'identity' => ['mode' => 'natural_key', 'column' => 'extra_code'],
        ],
        // An EAV sidecar: the pure half is which row table owns it, through
        // which column.
        'authored_snapshot_meta' => [
            'class' => 'authored_snapshot_meta',
            'attached_to' => ['table' => 'acme_b_slots', 'column' => 'slot_id'],
        ],
        // Inert markers and target-local dispositions: the class IS the whole
        // declaration.
        default => ['class' => $class],
    };
    accepts($extraTable('acme_b_extra', $decl), "table class '$class' is published as legal and loads");
}
refuses(
    $slots(['class' => 'authored_snaphot']),
    'the table class vocabulary is closed (' . implode(', ', $vocabularies['table_classes']) . ')',
    'and a table class outside the published set is refused with that exact set printed back'
);

// --- table_identity_modes: each mode is a different table SHAPE, so each gets
// a declaration of that shape rather than the same one with a field swapped.
$covered['table_identity_modes'] = true;
foreach ($vocabularies['table_identity_modes'] as $mode) {
    $fixture = match ($mode) {
        'mapped' => $slots(['identity' => ['mode' => 'mapped']]),
        'natural_key' => $slots(['identity' => ['mode' => 'natural_key', 'columns' => ['room_id', 'slot_code']]]),
        'composite_ref' => $extraTable('acme_b_join', [
            'class' => 'authored_snapshot', 'id_kind' => 'acme_join', 'columns' => [],
            'refs' => [['column' => 'room_id', 'kind' => 'acme_room'], ['column' => 'slot_id', 'kind' => 'acme_slot']],
            'identity' => ['mode' => 'composite_ref', 'columns' => ['room_id', 'slot_id']],
        ]),
    };
    accepts($fixture, "table identity mode '$mode' is published as legal and loads");
}
// This refusal spells its set out in prose (each mode with the shape it serves)
// rather than an implode, so the cross-check is per value: every published mode
// must appear, quoted, in the message an unpublished one produces.
$identityRefusal = refuses(
    $slots(['identity' => ['mode' => 'natrual_key', 'column' => 'slot_code']]),
    'the identity vocabulary is closed and engine-owned',
    'and an identity mode outside the published set is refused'
);
foreach ($vocabularies['table_identity_modes'] as $mode) {
    check(
        str_contains($identityRefusal, '"' . $mode . '"'),
        "and that refusal names the published mode '$mode' as one of the legal ones"
    );
}

// --- effect_kinds / effect_modes / selector scopes and types.
$covered['effect_kinds'] = true;
foreach ($vocabularies['effect_kinds'] as $kind) {
    // A database effect is the one kind bound to checkpoint coverage; every
    // other kind must say it reaches outside the checkpoint.
    $fixture = $kind === 'database'
        ? $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => $okSelector])
        : $effect(['id' => 'e', 'kind' => $kind, 'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => 'acme-b']]);
    accepts($fixture, "effect kind '$kind' is published as legal and loads");
}
refuses(
    $effect(['id' => 'e', 'kind' => 'telepathy', 'mode' => 'restorable', 'selector' => $okSelector]),
    'is not one of the engine-owned effect kinds (' . implode(', ', $vocabularies['effect_kinds']) . ')',
    'an effect kind outside the published set is refused with the published set printed back'
);

$covered['effect_modes'] = true;
foreach ($vocabularies['effect_modes'] as $mode) {
    $fixture = match ($mode) {
        'restorable' => $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => $okSelector]),
        'reversible' => $effect([
            'id' => 'e', 'kind' => 'external', 'mode' => 'reversible',
            'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => 'acme-b'],
            'adapter' => [
                'id' => 'acme-b.undo', 'version' => '1.0.0', 'inverse' => 'acme-b.restore',
                'verifier' => 'acme-b.verify', 'inverse_inputs' => ['namespace'], 'verifier_inputs' => ['namespace'],
            ],
        ]),
        'prevented' => $effect([
            'id' => 'e', 'kind' => 'mail', 'mode' => 'prevented',
            'selector' => ['scope' => 'external', 'type' => 'mail_subject', 'value' => 'Acme B receipt'],
            'prevention' => 'receipt_outbox',
        ]),
        'irreversible' => $effect(['id' => 'e', 'kind' => 'external', 'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => 'acme-b']]),
    };
    accepts($fixture, "effect reversibility mode '$mode' is published as legal and loads (with the evidence that mode binds it to)");
}
refuses(
    $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'telekinesis', 'selector' => $okSelector]),
    'reversibility modes (' . implode(', ', $vocabularies['effect_modes']) . ')',
    'an effect mode outside the published set is refused with the published set printed back'
);

$covered['effect_selector_scopes'] = true;
foreach ($vocabularies['effect_selector_scopes'] as $scope) {
    $fixture = $scope === 'database_checkpoint'
        ? $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => $okSelector])
        : $effect(['id' => 'e', 'kind' => 'external', 'mode' => 'irreversible',
            'selector' => ['scope' => $scope, 'type' => 'namespace', 'value' => 'acme-b']]);
    accepts($fixture, "effect selector scope '$scope' is published as legal and loads");
}
refuses(
    $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'checkpoint', 'type' => 'option', 'value' => 'acme_b_setting']]),
    'is not one of the engine-owned scopes (' . implode(', ', $vocabularies['effect_selector_scopes']) . ')',
    'a selector scope outside the published set is refused with the published set printed back'
);

$covered['effect_selector_types'] = true;
$selectorValues = [
    'table' => 'acme_b_slots',
    'option' => 'acme_b_setting',
    'path' => 'wp-content/uploads/acme-b',
    'hook' => 'acme_b_after_flush',
    'namespace' => 'acme-b',
    'queue' => 'acme-b-jobs',
    'mail_subject' => 'Acme B receipt',
    'url_prefix' => 'https://acme-b.example/api',
    'provider_resource' => 'acme-b-cache:v1:all',
    'plugin_lifecycle' => 'acme-b/acme-b.php',
];
foreach ($vocabularies['effect_selector_types'] as $type) {
    check(isset($selectorValues[$type]), "the suite has a legal value for published selector type '$type' (a new type needs one here)");
    accepts(
        $effect(['id' => 'e', 'kind' => 'external', 'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => $type, 'value' => $selectorValues[$type] ?? 'acme-b']]),
        "effect selector type '$type' is published as legal and loads"
    );
}
refuses(
    $effect(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'row', 'value' => 'acme_b_setting']]),
    'selector types (' . implode(', ', $vocabularies['effect_selector_types']) . ')',
    'a selector type outside the published set is refused with the published set printed back'
);

// --- provider_resource_placeholders: the two typed placeholders a
// provider-resource aggregate's templates may expand.
$covered['provider_resource_placeholders'] = true;
$members = static fn(array $templates): array => $effect([
    'id' => 'e', 'kind' => 'cache', 'mode' => 'irreversible',
    'selector' => [
        'scope' => 'external', 'type' => 'provider_resource', 'value' => 'acme-b-cache:v1:all',
        'members' => ['exact' => ['acme_b_index'], 'templates' => $templates],
    ],
]);
foreach ($vocabularies['provider_resource_placeholders'] as $placeholder) {
    accepts($members(['acme_b_{' . $placeholder . '}']), "provider-resource placeholder '{$placeholder}' is published as legal and loads");
}
refuses(
    $members(['acme_b_{uuid}']),
    'placeholder vocabulary is closed and engine-owned ({' . implode('}, {', $vocabularies['provider_resource_placeholders']) . '})',
    'a placeholder outside the published set is refused with the published set printed back'
);

$covered['provider_sources'] = true;
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
    'providers[0].source must be "' . implode('" or "', $vocabularies['provider_sources']) . '"',
    'a provider source outside the published set is refused, with the published set spelled back'
);

$covered['option_autoload_values'] = true;
foreach ($vocabularies['option_autoload_values'] as $autoload) {
    accepts(
        solo_b(['options' => ['acme_b_setting' => ['class' => 'authored', 'autoload' => $autoload]]]),
        "option autoload value '$autoload' is published as legal and loads"
    );
}
$covered['option_autoload_sentinels'] = true;
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
$covered['user_meta_missing_user_modes'] = true;
foreach ($vocabularies['user_meta_missing_user_modes'] as $mode) {
    accepts(
        solo_b(['user_meta' => ['acme_b_pref' => ['class' => 'authored', 'missing_user' => $mode]]]),
        "user_meta missing_user mode '$mode' is published as legal and loads"
    );
}
refuses(
    solo_b(['user_meta' => ['acme_b_pref' => ['class' => 'authored', 'missing_user' => 'ignore']]]),
    'missing_user must be ' . implode(' or ', $vocabularies['user_meta_missing_user_modes']),
    'a missing_user mode outside the published set is refused, with the published set spelled back'
);

$covered['attribute_value_types'] = true;
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

// --- attribute_tokenize_codecs: the one codec a block/shortcode attribute
// may declare for the recursive home/uploads URL pass.
$covered['attribute_tokenize_codecs'] = true;
foreach ($vocabularies['attribute_tokenize_codecs'] as $codec) {
    accepts(
        solo_b(['block_attrs' => ['acme/b' => [['path' => 'url', 'tokenize' => $codec]]]]),
        "attribute tokenize codec '$codec' is published as legal and loads"
    );
}
refuses(
    solo_b(['block_attrs' => ['acme/b' => [['path' => 'url', 'tokenize' => 'markdown']]]]),
    'the only supported codec for an attribute is "' . implode('", "', $vocabularies['attribute_tokenize_codecs']) . '"',
    'a tokenize codec outside the published set is refused with the published set printed back'
);

$covered['widget_setting_codecs'] = true;
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
$covered['widget_setting_refs'] = true;
foreach ($vocabularies['widget_setting_refs'] as $ref) {
    accepts(
        solo_b(['widgets' => ['acme_b' => ['settings' => ['owner' => ['class' => 'authored', 'ref' => $ref]]]]]),
        "widget settings ref kind '$ref' is published as legal and loads"
    );
}
refuses(
    solo_b(['widgets' => ['acme_b' => ['settings' => ['owner' => ['class' => 'authored', 'ref' => 'post']]]]]),
    'ref vocabulary is closed and engine-owned (' . implode(', ', $vocabularies['widget_setting_refs']) . ')',
    'a widget settings ref kind outside the published set is refused with the published set printed back — narrower than the general ref vocabulary, deliberately'
);

$covered['dynamic_option_resolvers'] = true;
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
// --- the three kind vocabularies. Each publishes only its ENGINE-OWNED BASE,
// and each refusal prints base ∪ declared-id_kinds, sorted — so the needle is
// built from the published base plus this fixture's own two keyspaces.
$covered['engine_ref_kinds'] = true;
foreach ($vocabularies['engine_ref_kinds'] as $kind) {
    accepts(
        solo_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => $kind]]]),
        "engine-owned ref kind '$kind' is published as legal and loads"
    );
}
refuses(
    solo_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'psot']]]),
    // No closing paren in the needle: the reference-kind refusal continues
    // "…, each optionally suffixed with [] for a list" inside the same
    // parentheses, which the token-kind one deliberately does not.
    'reference kind vocabulary is closed (' . $legalKinds($vocabularies['engine_ref_kinds']) . ', each optionally suffixed',
    'a ref kind outside the published engine base (and outside every declared id_kind) is refused, printing base + declared'
);

$covered['engine_token_kinds'] = true;
foreach ($vocabularies['engine_token_kinds'] as $kind) {
    accepts(
        $slots(['refs' => [['column' => 'room_id', 'kind' => 'acme_room'], ['column' => 'owner_id', 'kind' => $kind]]]),
        "engine-owned token kind '$kind' is published as legal and loads as a table ref"
    );
}
refuses(
    $slots(['refs' => [['column' => 'room_id', 'kind' => 'acme_room'], ['column' => 'owner_id', 'kind' => 'user']]]),
    'token kind vocabulary is closed (' . $legalKinds($vocabularies['engine_token_kinds']) . ')',
    "a token kind outside the published engine base is refused — and 'user' is the case that proves the two vocabularies are genuinely different sets, since it IS a legal ref kind"
);

$covered['engine_ledger_kinds'] = true;
$guard = static fn(string $kind): array => solo_b(['deletions' => ['post:acme_widget' => [
    'cascades' => [],
    'guards' => [['table' => 'acme_b_slots', 'column' => 'room_id', 'id_kind' => $kind, 'reason' => 'slots reference this']],
]]]);
foreach ($vocabularies['engine_ledger_kinds'] as $kind) {
    accepts($guard($kind), "engine-owned ledger kind '$kind' is published as legal and loads as a deletion guard");
}
refuses(
    $guard('tt'),
    'ledger kind vocabulary is closed here (' . $legalKinds($vocabularies['engine_ledger_kinds']) . ')',
    "a ledger kind outside the published engine base is refused — 'tt' is the case that proves it: the ledger's long spelling is term_taxonomy, and the token vocabulary's short one is not legal here"
);

// --- classification_classes / scope_classes: the same word means different
// sets on different surfaces, which is exactly why both are published.
$covered['classification_classes'] = true;
foreach ($vocabularies['classification_classes'] as $class) {
    accepts(
        solo_b(['user_meta' => ['acme_b_pref' => ['class' => $class]]]),
        "classification class '$class' is published as legal and loads"
    );
}
refuses(
    solo_b(['user_meta' => ['acme_b_pref' => ['class' => 'authoerd']]]),
    '(expected ' . implode('|', $vocabularies['classification_classes']) . ')',
    'a classification class outside the published set is refused with the published set printed back'
);

$covered['scope_classes'] = true;
foreach ($vocabularies['scope_classes'] as $class) {
    accepts($postType(['class' => $class]), "scope class '$class' is published as legal and loads on a post type");
}
refuses(
    $postType(['class' => 'managed']),
    '(expected ' . implode('|', $vocabularies['scope_classes']) . ')',
    "a scope class outside the published set is refused — and 'managed' is the case that proves this set is genuinely narrower than the classification one"
);

// --- value_casts.
$covered['value_casts'] = true;
foreach ($vocabularies['value_casts'] as $cast) {
    accepts(
        solo_b(['block_attrs' => ['acme/b' => [['kind' => 'post', 'path' => 'id', 'type' => 'int', 'cast' => $cast]]]]),
        "value cast '$cast' is published as legal and loads"
    );
}
refuses(
    solo_b(['block_attrs' => ['acme/b' => [['kind' => 'post', 'path' => 'id', 'type' => 'int', 'cast' => 'array']]]]),
    'but only ' . implode('|', $vocabularies['value_casts']) . ' are supported',
    'a cast outside the published set is refused with the published set printed back'
);

// --- post_derivable_fields / post_field_classes.
$covered['post_derivable_fields'] = true;
$covered['post_field_classes'] = true;
foreach (array_keys($vocabularies['post_derivable_fields']) as $field) {
    foreach ($vocabularies['post_field_classes'] as $class) {
        accepts(
            $postType(['class' => 'authored', 'fields' => [$field => ['class' => $class]]]),
            "post field '$field' is published as derivable and loads with the published class '$class'"
        );
    }
}
refuses(
    $postType(['class' => 'authored', 'fields' => ['excerpt' => ['class' => 'derived']]]),
    'but only ' . implode(', ', array_keys($vocabularies['post_derivable_fields'])) . ' may be field-classified',
    'a post field outside the published derivable set is refused with that exact set printed back'
);
refuses(
    $postType(['class' => 'authored', 'fields' => ['title' => ['class' => 'authored']]]),
    'but only ' . implode(', ', $vocabularies['post_field_classes']) . ' is supported for post fields',
    'a post-field class outside the published set is refused with that exact set printed back'
);

// --- menu_derivable_fields / menu_field_classes: the same split, one surface
// over, and deliberately NOT the same sets.
$covered['menu_derivable_fields'] = true;
$covered['menu_field_classes'] = true;
foreach ($vocabularies['menu_derivable_fields'] as $field) {
    foreach ($vocabularies['menu_field_classes'] as $class) {
        accepts(
            solo_b(['menu_fields' => [$field => ['class' => $class]]]),
            "menu field '$field' is published as classifiable and loads with the published class '$class'"
        );
    }
}
refuses(
    solo_b(['menu_fields' => ['items' => ['class' => 'derived']]]),
    'but only ' . implode(', ', $vocabularies['menu_derivable_fields']) . ' may be field-classified',
    'a menu field outside the published set is refused with that exact set printed back'
);
refuses(
    solo_b(['menu_fields' => ['locations' => ['class' => 'runtime']]]),
    'but only ' . implode(', ', $vocabularies['menu_field_classes']) . ' is supported for menu fields',
    'a menu-field class outside the published set is refused with that exact set printed back'
);

// --- action_kinds.
$covered['action_kinds'] = true;
foreach ($vocabularies['action_kinds'] as $kind) {
    $action = $kind === 'native'
        ? ['kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'acme_b']]
        : ['kind' => 'provider', 'provider' => 'acme-b-cache', 'capability' => 'flush', 'args' => []];
    accepts(solo_b(['actions' => [$action]]), "action kind '$kind' is published as legal and loads");
}
refuses(
    solo_b(['actions' => [['kind' => 'nativ', 'action' => 'transient.delete', 'args' => []]]]),
    'must be "' . implode('" or "', $vocabularies['action_kinds']) . '"',
    'an action kind outside the published set is refused with the published set spelled back'
);

// --- classification_sections: a section is a manifest KEY, not a value, so the
// positive half is "every published section is a section the loader reads" and
// the negative half comes from the engine's own refusal for an unknown one
// (Policy::set_rule(), which checks the section before it touches any repo).
$covered['classification_sections'] = true;
$sectionDecls = [];
foreach ($vocabularies['classification_sections'] as $section) {
    $sectionDecls[$section] = ['acme_b_' . $section => $section === 'options'
        ? ['class' => 'authored', 'autoload' => 'yes']
        : ['class' => 'authored']];
}
accepts(solo_b($sectionDecls), 'every published classification section is a manifest section the loader accepts (' . implode(', ', $vocabularies['classification_sections']) . ')');
check(
    str_contains(
        engine_message(static fn() => Policy::set_rule('/nonexistent-site-repo', 'optoins', 'k', ['class' => 'authored'])),
        '(expected ' . implode('|', array_merge($vocabularies['classification_sections'], ['scope'])) . ')'
    ),
    "and a section outside the published set is refused by the engine's own set_rule(), printing exactly the published set plus scope"
);

// --- pattern_keys: a MAP, and the only vocabulary here with no refusal of its
// own — its content is a routing decision, so it is cross-checked the only way
// a routing decision can be: by observing that the engine routes that way.
$covered['pattern_keys'] = true;
$patternDir = fixtures(['b' => solo_b([
    'option_patterns' => [['match' => '^acme_b_option_pat_', 'class' => 'authored', 'autoload' => 'yes']],
    'post_meta_patterns' => [['match' => '^acme_b_post_pat_', 'class' => 'authored']],
    'meta_patterns' => [['match' => '^acme_b_shared_meta_pat_', 'class' => 'authored']],
])]);
putenv('DUO_MANIFESTS_DIR=' . $patternDir);
$patternPolicy = Policy::load(null, ['b']);
$resolvers = [
    'options' => static fn(string $name): ?array => $patternPolicy->option_rule($name),
    'post_meta' => static fn(string $name): ?array => $patternPolicy->meta_rule_for_post($name, []),
    'term_meta' => static fn(string $name): ?array => $patternPolicy->meta_rule_for_term($name, []),
    'user_meta' => static fn(string $name): ?array => $patternPolicy->meta_rule_for_user($name, []),
];
$patternProbes = [
    'option_patterns' => 'acme_b_option_pat_1',
    'post_meta_patterns' => 'acme_b_post_pat_1',
    'meta_patterns' => 'acme_b_shared_meta_pat_1',
];
foreach ($vocabularies['classification_sections'] as $section) {
    $published = $vocabularies['pattern_keys'][$section] ?? [];
    if ($published === []) {
        check(
            $resolvers[$section]('acme_b_shared_meta_pat_1') === null,
            "section '$section' publishes NO pattern key, and the engine really does refuse to pattern-match it"
        );
        continue;
    }
    foreach ($published as $patternKey) {
        check(
            $resolvers[$section]($patternProbes[$patternKey]) !== null,
            "section '$section' publishes pattern key '$patternKey', and a rule declared under exactly that key really does resolve a matching name"
        );
    }
}
check(
    $patternPolicy->meta_rule_for_term('acme_b_post_pat_1', []) === null,
    'post_meta_patterns is surface-specific: the identical term-meta key remains unclassified'
);
$swappedDir = fixtures(['b' => solo_b([
    // The option pattern moved under the meta key, and nothing else changed.
    'meta_patterns' => [['match' => '^acme_b_option_pat_', 'class' => 'authored']],
])]);
putenv('DUO_MANIFESTS_DIR=' . $swappedDir);
$swappedPolicy = Policy::load(null, ['b']);
check(
    $swappedPolicy->option_rule('acme_b_option_pat_1') === null
        && $swappedPolicy->meta_rule_for_post('acme_b_option_pat_1', []) !== null,
    'and the published mapping is the WHOLE mapping: the same pattern under the other section\'s key resolves for that section and not for this one'
);
putenv('DUO_MANIFESTS_DIR');

foreach (NativeActions::vocabulary() as $action) {
    $args = $action === 'transient.delete' ? ['name' => 'acme_b'] : [];
    accepts(
        solo_b(['actions' => [['kind' => 'native', 'action' => $action, 'args' => $args]]]),
        "published native action '$action' is accepted by the runtime validator"
    );
}

// The ratchet. Every published vocabulary, exercised — not a sample of them.
$uncovered = array_values(array_diff(array_keys($vocabularies), array_keys($covered)));
check(
    $uncovered === [],
    'EVERY published vocabulary is exercised in both directions above (' . count($covered) . '/' . count($vocabularies)
        . ($uncovered === [] ? '' : '; not covered: ' . implode(', ', $uncovered)) . ')'
);
check(
    array_diff(array_keys($covered), array_keys($vocabularies)) === [],
    'and nothing is claimed as covered that the document does not publish'
);

// The pure block/shortcode declaration grammar belongs to AttributeGrammar;
// Policy retains the shared cast vocabulary because classification and
// deletion guards also consume it. Keep both loader paths and both published
// attribute vocabularies wired directly to the extracted collaborator.
$policySource = file_get_contents($repo . '/agent/src/Policy/Policy.php');
$manifestValidatorSource = file_get_contents($repo . '/agent/src/Policy/ManifestValidator.php');
$sitePolicyValidatorSource = file_get_contents($repo . '/agent/src/Policy/SitePolicyValidator.php');
$attributeGrammar = new \ReflectionClass('Duo\\AttributeGrammar');
$policyReflection = new \ReflectionClass(Policy::class);
check(
    $attributeGrammar->hasMethod('validate_attr_rules')
        && $attributeGrammar->getMethod('validate_attr_rules')->isPublic()
        && $attributeGrammar->hasMethod('attributeValueTypes')
        && $attributeGrammar->hasMethod('attributeTokenizeCodecs')
        && !$policyReflection->hasMethod('validate_attr_rules')
        && substr_count($policySource, 'AttributeGrammar::validate_attr_rules(') === 0
        && substr_count($manifestValidatorSource, 'AttributeGrammar::validate_attr_rules(') === 1
        && substr_count($policySource, 'AttributeGrammar::attributeValueTypes()') === 1
        && substr_count($policySource, 'AttributeGrammar::attributeTokenizeCodecs()') === 1
        && !str_contains($policySource, 'ATTR_VALUE_TYPES')
        && !str_contains($policySource, 'ATTR_TOKENIZE_CODECS'),
    'attribute declaration grammar and its vocabularies live in AttributeGrammar while Policy retains only shared casts'
);

// The pure reference-valued declaration shape grammar belongs to
// ReferenceShapeGrammar; Policy retains the later keyspace/sidecar pass
// because that pass needs the full declared-table set. Keep every site and
// manifest loader path wired directly to the extracted collaborator.
$referenceShapeGrammar = new ReflectionClass('Duo\\ReferenceShapeGrammar');
check(
    $referenceShapeGrammar->hasMethod('validate_reference_shapes')
        && $referenceShapeGrammar->getMethod('validate_reference_shapes')->isPublic()
        && !$policyReflection->hasMethod('validate_reference_shapes')
        && substr_count($policySource, 'ReferenceShapeGrammar::validate_reference_shapes(') === 0
        && substr_count($manifestValidatorSource, 'ReferenceShapeGrammar::validate_reference_shapes(') === 1
        && substr_count($sitePolicyValidatorSource, 'ReferenceShapeGrammar::validate_reference_shapes(') === 1,
    'reference-valued declaration shape grammar lives in ReferenceShapeGrammar while the site validator owns site loading'
);

// The post/menu field declaration grammar belongs to FieldGrammar. Policy
// keeps the published/shared vocabularies because runtime consumers read the
// post-field column map and the manifest report publishes all four sets.
$fieldGrammar = new ReflectionClass('Duo\\FieldGrammar');
check(
    $fieldGrammar->hasMethod('validate_field_classes')
        && $fieldGrammar->getMethod('validate_field_classes')->isPublic()
        && $fieldGrammar->hasMethod('validate_menu_field_classes')
        && $fieldGrammar->getMethod('validate_menu_field_classes')->isPublic()
        && !$policyReflection->hasMethod('validate_field_classes')
        && !$policyReflection->hasMethod('validate_menu_field_classes')
        && !str_contains($policySource, 'FieldGrammar::validate_field_classes($manifest,')
        && !str_contains($policySource, 'FieldGrammar::validate_menu_field_classes($manifest,')
        && substr_count($manifestValidatorSource, 'FieldGrammar::validate_field_classes(') === 1
        && substr_count($manifestValidatorSource, 'FieldGrammar::validate_menu_field_classes(') === 1,
    'post/menu field declaration grammar lives in ManifestValidator while Policy retains shared field vocabularies'
);

// UserMetaGrammar owns the same safety check for static declarations and
// interpreter-returned rules. Policy retains only the published vocabularies;
// keep all loader and runtime lookup call sites wired to the collaborator.
$userMetaGrammar = new ReflectionClass('Duo\\UserMetaGrammar');
check(
    $userMetaGrammar->hasMethod('validate_user_meta_rules')
        && $userMetaGrammar->getMethod('validate_user_meta_rules')->isPublic()
        && $userMetaGrammar->hasMethod('validate_user_meta_rule')
        && $userMetaGrammar->getMethod('validate_user_meta_rule')->isPublic()
        && !$policyReflection->hasMethod('validate_user_meta_rules')
        && !$policyReflection->hasMethod('validate_user_meta_rule')
        && substr_count($policySource, 'UserMetaGrammar::validate_user_meta_rules(') === 0
        && substr_count($manifestValidatorSource, 'UserMetaGrammar::validate_user_meta_rules(') === 1
        && substr_count($sitePolicyValidatorSource, 'UserMetaGrammar::validate_user_meta_rules(') === 1
        && substr_count($policySource, 'UserMetaGrammar::validate_user_meta_rule(') === 4,
    'user-meta safety grammar lives in UserMetaGrammar for declarations and interpreter rules'
);

// ScopeGrammar owns the pure whole-entity scope declaration check and its
// narrower vocabulary. Policy keeps the loader/writer/reporting boundaries,
// but all four loader calls plus set_rule() and closed_vocabularies() must use
// the same collaborator-owned values.
$scopeGrammar = new ReflectionClass('Duo\\ScopeGrammar');
check(
    $scopeGrammar->hasMethod('validate_scope_classes')
        && $scopeGrammar->getMethod('validate_scope_classes')->isPublic()
        && $scopeGrammar->hasMethod('scopeClasses')
        && $scopeGrammar->getMethod('scopeClasses')->isPublic()
        && !$policyReflection->hasMethod('validate_scope_classes')
        && !str_contains($policySource, 'SCOPE_CLASSES')
        && substr_count($policySource, 'ScopeGrammar::validate_scope_classes(') === 0
        && substr_count($manifestValidatorSource, 'ScopeGrammar::validate_scope_classes(') === 1
        && substr_count($sitePolicyValidatorSource, 'ScopeGrammar::validate_scope_classes(') === 1
        && substr_count($policySource, 'ScopeGrammar::scopeClasses()') === 3,
    'whole-entity scope declaration grammar and its vocabulary live in ScopeGrammar; site loading is delegated'
);

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
    '--site with an empty value' => [$repo . '/manifests', '--site='],
    '--site pointing at a path that does not exist' => [$repo . '/manifests', '--site=' . sys_get_temp_dir() . '/duo-no-such-site-repo'],
    // The most likely mistake, and the one worth refusing loudest: a directory
    // that exists but is not a site repo would otherwise fail every manifest
    // row with the engine's "not a duo site repo?" message, which reads as
    // "your manifests are broken".
    '--site pointing at a directory with no site.duo.json' => [$repo . '/manifests', '--site=' . $repo . '/manifests'],
    'a repeated --site' => [$repo . '/manifests', '--site=' . $repo, '--site=' . $repo],
    '--emit-schema together with --site' => ['--emit-schema', '--site=' . $repo],
    // A trust flag is the last place last-wins is acceptable, and the grammar
    // document has no code half to skip in the first place.
    'a repeated --no-code' => [$repo . '/manifests', '--no-code', '--no-code'],
    '--emit-schema together with --no-code' => ['--emit-schema', '--no-code'],
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
echo "\n== the site's own adapters/ with --site validates as the SITE source (T6 walk S2) ==\n";
// `manifest-validate <repo>/adapters --site=<repo>` is the authoring guide's
// own spelling. Before T6 the directory handed in became the shipped dir for
// every load, so AdapterSources saw each site adapter twice and refused it as
// "shadows the shipped adapter <name>". Now that directory is recognised as
// the site source: the shipped library stays what the agent ships and the
// site adapters load through the site source, exactly as the engine will.
$siteAuthored = site_repo([], ['core']);
mkdir($siteAuthored . '/adapters', 0777, true);
Canon::write_file($siteAuthored . '/adapters/acme-widgets.json', Canon::encode([
    'name' => 'acme-widgets',
    'option_autoload' => 'preserve',
    'option_namespaces' => [['match' => '^acme_widgets_']],
    'options' => ['acme_widgets_layout' => ['class' => 'authored']],
    'plugin' => 'acme-widgets/acme-widgets.php',
    'spec_version' => DUO_SPEC_VERSION,
    'version_range' => ['max' => '2.0.0', 'min' => '1.0.0'],
]));
register_shutdown_function(function () use ($siteAuthored) {
    @unlink($siteAuthored . '/adapters/acme-widgets.json');
    @rmdir($siteAuthored . '/adapters');
});
$siteRun = duo([$siteAuthored . '/adapters', '--site=' . $siteAuthored, '--format=json']);
$siteReport = json_decode($siteRun['stdout'], true);
check($siteRun['exit'] === 0, 'a site adapter directory validated with --site exits 0 (got ' . $siteRun['exit'] . ': ' . substr($siteRun['stderr'], 0, 200) . ')');
check(
    !str_contains($siteRun['stdout'] . $siteRun['stderr'], 'shadows the shipped adapter'),
    'the site adapter is never reported as shadowing itself'
);
check(
    is_array($siteReport) && ($siteReport['summary']['ok'] ?? null) === 1,
    'the one site adapter reads ok'
);

echo "\n== the site copy of a SHIPPED name: an unstated override is a typed stop naming the pin verb (T6 walk S4) ==\n";
// An operator building an override copies manifests/woocommerce.json into
// adapters/, edits it, and validates — before stating the override in
// site.duo.json. That is the shadow refusal, and it is the documented stop:
// the loader refuses the whole site source, so no manifest is judged. What
// this command owes the author is the reason CODE and the remediation that
// names `duo adapter pin … --source=site`, not only "rename or remove".
$shipped = json_decode((string) file_get_contents($repo . '/manifests/woocommerce.json'), true);
$shipped['options']['woocommerce_store_address_2'] = ['autoload' => 'preserve', 'class' => 'authored'];
$overrideSite = site_repo([], ['core', 'woocommerce']);
mkdir($overrideSite . '/adapters', 0777, true);
Canon::write_file($overrideSite . '/adapters/woocommerce.json', Canon::encode($shipped));
register_shutdown_function(function () use ($overrideSite) {
    @unlink($overrideSite . '/adapters/woocommerce.json');
    @rmdir($overrideSite . '/adapters');
});
$unstated = duo([$overrideSite . '/adapters', '--site=' . $overrideSite]);
check($unstated['exit'] === 2, 'the unstated override refuses (exit ' . $unstated['exit'] . ')');
check(
    str_contains($unstated['stderr'], '[shadows_shipped] duo: site adapter \'adapters/woocommerce.json\' shadows the shipped adapter \'woocommerce\''),
    'and the refusal carries the loader\'s own sentence under its bracketed reason code (got: ' . substr($unstated['stderr'], 0, 240) . ')'
);
check(
    str_contains($unstated['stderr'], 'duo adapter pin <site-repo> --name=woocommerce --source=site')
        && str_contains($unstated['stderr'], 'remediation:'),
    'the remediation names the override verb'
);
// Stated (the pin verb writes {name, source:"site", digest}; the shape is what
// the loader reads), the same directory validates: the shipped provider grant
// is inherited, so the copy is not refused as out-of-tree code either.
Canon::write_file($overrideSite . '/site.duo.json', Canon::encode([
    'manifests' => ['core', ['name' => 'woocommerce', 'source' => 'site']],
    'policy' => new \stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]));
$stated = duo([$overrideSite . '/adapters', '--site=' . $overrideSite, '--format=json']);
$statedReport = json_decode($stated['stdout'], true);
check(
    $stated['exit'] === 0 && is_array($statedReport) && ($statedReport['summary']['ok'] ?? null) === 1,
    'the stated override validates ok through the site source (exit ' . $stated['exit'] . ': ' . substr($stated['stderr'], 0, 200) . ')'
);
check(
    !str_contains($stated['stdout'] . $stated['stderr'], 'acquires no executable privileges')
        && !str_contains($stated['stdout'] . $stated['stderr'], 'source "manifest"'),
    'and the inherited provider declaration is not refused as out-of-tree code'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
