<?php
/**
 * WP-2.4(b): a `bare_id` on a custom-table column whose LIVE MySQL TYPE bounds
 * the column to {0,1} is emitted as a PROPOSED `lint_ok` carrying that type as
 * its premise — a proposal, never a silence.
 *
 * The measured case this reproduces is Ninja Forms, and it is measured rather
 * than invented: `manifests/ninja-forms.json:24-25` is a human's written
 * record of doing this review by hand. Eight columns are declared `lint_ok`
 * there. Six are BIT(1) booleans (`nf3_forms.show_title` / `clear_complete` /
 * `hide_complete` / `logged_in`, `nf3_fields.required` /
 * `personally_identifiable`) — "a column whose value space structurally
 * excludes ever holding a ref", exempted proactively for the whole column. The
 * other two, `nf3_actions.active` and `nf3_fields.order`, are argued
 * differently in that same note: they are small integers reviewed one by one
 * ("order=1..23 walks straight through this site's own post ids 1-16"). This
 * suite asserts the tool splits them exactly 6-of-8, leaving those two on the
 * reviewer's desk.
 *
 * WHAT THE FIXTURES CLAIM, AND WHAT THEY DO NOT. The policy is the SHIPPED
 * `manifests/ninja-forms.json`, read from disk, with the eight `lint_ok`
 * declarations stripped — i.e. the state of that manifest before the review
 * the note records, which is exactly the population this tool is meant to
 * shrink. The six `bit(1)` probe types are that same note's own live DESCRIBE
 * record (NF 3.14.11, `manifests/ninja-forms.json:16` and :25). The probe
 * types for `active` and `order` are FIXTURE values chosen to exercise the
 * non-boolean branch; nothing in this repository records NF's live types for
 * those two columns, and this suite makes no claim about them. What is pinned
 * is the mechanism: a type in the boolean domain proposes, any other type does
 * not, and no type at all proposes nothing.
 *
 * The one deliberate gap, asserted rather than left implicit: an
 * `ENUM('0','1')` column is as bounded as `BIT(1)`, and gets NO proposal,
 * because `AdapterProbe::normalized_type()` reduces every enum to the bare
 * word `enum` — the member list carries SITE VALUES inside the MySQL type
 * string (`agent/src/Adapter/AdapterProbe.php:435-447`, boundary 2). The
 * evidence that reaches lint cannot tell `enum('0','1')` from
 * `enum('draft','publish')`, so proposing on it would invent the premise
 * instead of carrying it.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

$root = dirname(__DIR__, 4);

// The engine's two version constants come from agent/duo.php's own source, the
// way every offline host-verb harness resolves them — never a literal here.
$agentSource = (string) file_get_contents($root . '/agent/duo.php');
if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $agentSource, $m) !== 1) {
    fwrite(STDERR, "FAIL: could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_SPEC_VERSION', (int) $m[1]);
if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $agentSource, $m) !== 1) {
    fwrite(STDERR, "FAIL: could not resolve DUO_AGENT_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_AGENT_VERSION', $m[1]);

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Review/Lint.php';
require_once $root . '/agent/src/Adapter/AdapterProbe.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use Duo\AdapterProbe;
use Duo\Canon;
use Duo\Lint;
use Duo\LintEnvironment;
use Duo\Policy;
use DuoTest\FakeWpdb;
use DuoTest\FrozenPolicy;
use DuoTest\WpStore;

// ---------------------------------------------------------------- fixtures

$tmp = sys_get_temp_dir() . '/duo_lint_type_exemptions_' . bin2hex(random_bytes(6));
mkdir($tmp . '/state/tables/nf3_forms', 0777, true);
mkdir($tmp . '/state/tables/nf3_fields', 0777, true);
mkdir($tmp . '/state/tables/nf3_actions', 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walk as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($tmp);
});

$state = $tmp . '/state';
file_put_contents($state . '/tables/nf3_forms/11111111-1111-5111-8111-111111111111--job-application.json', Canon::encode([
    'columns' => [
        'clear_complete' => 1,
        'form_title' => 'text<Job Application>',
        'hide_complete' => 1,
        'logged_in' => 1,
        'show_title' => 1,
    ],
    'meta' => (object) [],
    'table' => 'nf3_forms',
]));
file_put_contents($state . '/tables/nf3_fields/22222222-2222-5222-8222-222222222222--full-name.json', Canon::encode([
    'columns' => ['order' => 3, 'personally_identifiable' => 1, 'required' => 1],
    'meta' => (object) [],
    'table' => 'nf3_fields',
]));
file_put_contents($state . '/tables/nf3_actions/33333333-3333-5333-8333-333333333333--email.json', Canon::encode([
    'columns' => ['active' => 1],
    'meta' => (object) [],
    'table' => 'nf3_actions',
]));

// The collision the manifest note recorded live: `1` and `3` are real post ids
// on any small fixture site, which is what makes every one of these eight a
// genuine bare_id today.
WpStore::reset()->seedOptions(['home' => 'https://ninja.test']);
$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello world!', 'post_status' => 'publish'],
    ['ID' => 3, 'post_type' => 'page', 'post_title' => 'Sample Page', 'post_status' => 'publish'],
]);

/**
 * The shipped Ninja Forms manifest with the eight reviewed `lint_ok` flags
 * stripped: the manifest as it stood before the review, which is the input
 * this tool exists to make cheaper.
 *
 * @return array<string,mixed>
 */
function nf_manifest(string $root, bool $stripLintOk): array {
    $library = \Duo\AdapterLibrary::fromSourceTree($root);
    $manifest = json_decode((string) file_get_contents($library->package('ninja-forms')->manifestPath()), true);
    if (!is_array($manifest)) {
        fwrite(STDERR, "FAIL: the ninja-forms package manifest did not decode\n");
        exit(1);
    }
    if (!$stripLintOk) {
        return $manifest;
    }
    foreach (['nf3_actions', 'nf3_fields', 'nf3_forms'] as $table) {
        foreach (array_keys((array) ($manifest['tables'][$table]['columns'] ?? [])) as $column) {
            unset($manifest['tables'][$table]['columns'][$column]['lint_ok']);
        }
    }
    return $manifest;
}

function nf_policy(array $manifest): Policy {
    return FrozenPolicy::policy([$manifest], FrozenPolicy::site([$manifest]));
}

/**
 * A `duo-adapter-probe/v1` document over the three NF tables, self-hashed on
 * the emitter's own basis.
 *
 * @param array<string,array<string,string>> $tables table => column => type
 * @return array<string,mixed>
 */
function nf_probe(array $tables): array {
    $facts = [];
    foreach ($tables as $table => $columns) {
        $shape = [];
        foreach ($columns as $column => $type) {
            $shape[$column] = ['nullable' => false, 'type' => $type];
        }
        ksort($shape, SORT_STRING);
        $facts[$table] = ['columns' => $shape, 'present' => true];
    }
    ksort($facts, SORT_STRING);
    $document = [
        'authority' => false,
        'format' => AdapterProbe::FORMAT,
        'redaction' => AdapterProbe::REDACTION,
        'tables' => $facts,
        'target' => ['agent_version' => DUO_AGENT_VERSION, 'spec_version' => DUO_SPEC_VERSION],
    ];
    // The emitter's own hash function, not a re-spelling of it: this is half of
    // the pinning that keeps LintEnvironment's independent recomputation and
    // AdapterProbe::hash_document() describing one basis.
    $document['probe_hash'] = AdapterProbe::hash_document($document);
    return $document;
}

/** The six BIT(1) columns manifests/ninja-forms.json:25 recorded live. */
const NF_BIT_COLUMNS = [
    'nf3_fields' => ['personally_identifiable', 'required'],
    'nf3_forms' => ['clear_complete', 'hide_complete', 'logged_in', 'show_title'],
];

/** @return array<string,string> locator => class */
function classes_by_locator(array $findings): array {
    $out = [];
    foreach ($findings as $finding) {
        $out[$finding['path'] . ' ' . $finding['locator']] = $finding['class'];
    }
    ksort($out, SORT_STRING);
    return $out;
}

function note_for(array $findings, string $locator): string {
    foreach ($findings as $finding) {
        if ($finding['locator'] === $locator) {
            return (string) $finding['note'];
        }
    }
    return '';
}

$stripped = nf_manifest($root, true);
$policy = nf_policy($stripped);

// ------------------------------------------- 1. the pre-review population

$baseline = Lint::scan_tree($state, $policy, LintEnvironment::live());
duo_check_same(8, count($baseline), 'without a probe the reviewer faces all eight findings, the state the manifest note records');
duo_check_same(
    ['bare_id'],
    array_values(array_unique(array_column($baseline, 'class'))),
    'without a live column type every one of them is a plain bare_id — no type, no proposal'
);

// ------------------------------------------- 2. the measured 6-of-8 split

$probe = nf_probe([
    // manifests/ninja-forms.json:25's own live DESCRIBE record.
    'nf3_forms' => [
        'clear_complete' => 'bit(1)',
        'form_title' => 'varchar(250)',
        'hide_complete' => 'bit(1)',
        'logged_in' => 'bit(1)',
        'show_title' => 'bit(1)',
    ],
    'nf3_fields' => [
        'order' => 'int(11)',
        'personally_identifiable' => 'bit(1)',
        'required' => 'bit(1)',
    ],
    // Fixture types (see this file's header): nothing here records NF's live
    // type for `active`, and this suite asserts the mechanism, not that column.
    'nf3_actions' => ['active' => 'int(11)'],
]);
$proposed = Lint::scan_tree($state, $policy, LintEnvironment::live($probe));

duo_check_same(
    count($baseline),
    count($proposed),
    'the probe changes no finding COUNT: a proposal is a re-class, and a tool that shrank the set here would be '
    . 'silencing findings rather than proposing exemptions'
);
duo_check_same(
    array_keys(classes_by_locator($baseline)),
    array_keys(classes_by_locator($proposed)),
    'every finding keeps its exact path and locator; only its class and note move'
);

$expectedClasses = [];
foreach (classes_by_locator($baseline) as $key => $class) {
    $column = substr($key, strpos($key, 'columns.') + strlen('columns.'));
    $table = explode('/', $key)[1];
    $expectedClasses[$key] = in_array($column, NF_BIT_COLUMNS[$table] ?? [], true) ? 'proposed_lint_ok' : 'bare_id';
}
duo_check_same(
    $expectedClasses,
    classes_by_locator($proposed),
    'the six BIT(1) columns are proposed and nf3_actions.active + nf3_fields.order are left for review — the '
    . '6-of-8 split manifests/ninja-forms.json:24-25 records a human making by hand'
);
duo_check_same(
    6,
    count(array_filter($proposed, static fn(array $f): bool => $f['class'] === 'proposed_lint_ok')),
    'exactly six proposals'
);
duo_check_same(
    2,
    count(array_filter($proposed, static fn(array $f): bool => $f['class'] === 'bare_id')),
    'exactly two findings stay on the reviewer\'s desk'
);

// ------------------------------------------- 3. the proposal carries its premise

$bitNote = note_for($proposed, 'columns.show_title');
duo_check(str_contains($bitNote, 'the live column type is bit(1)'), 'a proposal states the live type it rests on');
duo_check(
    str_contains($bitNote, 'BIT(1) holds one bit'),
    'a proposal states WHY that type is evidence, not merely that it is'
);
duo_check(
    str_contains($bitNote, 'tables.nf3_forms.columns.show_title = {"class": "authored", "lint_ok": true}'),
    'a proposal ends in the exact declaration a reviewer would write, addressed at the COLUMN'
);
duo_check(
    str_contains($bitNote, 'still reported and still counted'),
    'a proposal says out loud that it is not a silence'
);
duo_check(
    str_contains($bitNote, 'coincides with an existing post id (#1 "Hello world!", post)'),
    'a proposal states the collision FIRST and in full, exactly as bare_id does — the evidence against it is not '
    . 'dropped because a premise was found for it'
);
duo_check(
    str_contains(note_for($proposed, 'columns.active'), 'signal to investigate, not proof')
    && !str_contains(note_for($proposed, 'columns.active'), 'PROPOSED EXEMPTION'),
    'a column with no boolean-domain type keeps the unchanged bare_id note'
);

// TINYINT(1) is the conventional boolean width, and MySQL's display width
// constrains nothing — the proposal must carry that weakness rather than
// borrow BIT(1)'s confidence. (Mechanism case: this is not a claim about
// nf3_fields.order's live type.)
$tinyProbe = nf_probe(['nf3_fields' => ['order' => 'tinyint(1)', 'personally_identifiable' => 'bit(1)', 'required' => 'bit(1)']]);
$tinyFindings = Lint::scan_tree($state, $policy, LintEnvironment::live($tinyProbe));
$tinyNote = note_for($tinyFindings, 'columns.order');
duo_check(str_contains($tinyNote, 'PROPOSED EXEMPTION'), 'tinyint(1) is in the boolean domain and proposes');
duo_check(
    str_contains($tinyNote, 'display width does NOT constrain the range')
    && str_contains($tinyNote, 'convention, not structure'),
    'the tinyint(1) proposal carries its own weaker premise verbatim, never bit(1)\'s'
);

// ------------------------------------------- 4. enum is deliberately absent

$enumProbe = nf_probe(['nf3_actions' => ['active' => 'enum']]);
$enumFindings = Lint::scan_tree($state, $policy, LintEnvironment::live($enumProbe));
duo_check_same(
    'bare_id',
    (string) (array_values(array_filter(
        $enumFindings,
        static fn(array $f): bool => $f['locator'] === 'columns.active'
    ))[0]['class'] ?? ''),
    "an ENUM('0','1') column reaches lint as the bare word `enum` (AdapterProbe boundary 2 strips the members, "
    . 'which are site values) and therefore proposes nothing: the premise cannot be checked, so it is not made'
);
duo_check_same(
    null,
    LintEnvironment::boolean_domain_premise('enum'),
    'the boolean domain is closed over bit(1) and tinyint(1); `enum` is not a member'
);
duo_check_same(
    null,
    LintEnvironment::boolean_domain_premise(null),
    'no recorded type is not evidence for anything'
);
duo_check_same(
    LintEnvironment::boolean_domain_premise('bit(1)'),
    LintEnvironment::boolean_domain_premise('BIT(1)'),
    'the type comparison is case-insensitive, since a probe normalizes to lower case but a hand-read DESCRIBE '
    . 'does not'
);

// ------------------------------------------- 5. the probe is evidence, and is checked

$tampered = $probe;
$tampered['tables']['nf3_actions']['columns']['active']['type'] = 'bit(1)';
duo_check_throws(
    static fn() => LintEnvironment::live($tampered),
    RuntimeException::class,
    'a probe edited by hand to widen the exemption is refused, not honoured at face value',
    'probe_hash does not describe the document'
);
$claiming = $probe;
$claiming['authority'] = true;
$claiming['probe_hash'] = AdapterProbe::hash_document($claiming);
duo_check_throws(
    static fn() => LintEnvironment::live($claiming),
    RuntimeException::class,
    'a document claiming authority is refused even when its own hash is consistent',
    'must declare authority:false'
);
duo_check_throws(
    static fn() => LintEnvironment::live(['format' => 'duo-lint-environment/v1']),
    RuntimeException::class,
    '--evidence names the probe envelope by name',
    'duo-adapter-probe/v1'
);

// One document, two independent hash computations: AdapterProbe emits it and
// LintEnvironment re-checks it without requiring the emitter (agent/src/Review
// is `engine`, agent/src/Adapter is `adapter` — tools/modules.json). Two bases
// that drifted apart would make every real probe unreadable here.
$roundTripped = json_decode(json_encode($probe, JSON_UNESCAPED_SLASHES), true);
duo_check_same(
    ['nf3_actions' => ['active' => 'int(11)']],
    array_intersect_key(LintEnvironment::column_types_from_probe($roundTripped), ['nf3_actions' => true]),
    'the types survive a JSON round trip and the two hash bases still agree over one document'
);

$absent = nf_probe(['nf3_forms' => ['show_title' => 'bit(1)']]);
$absent['tables']['nf3_fields'] = ['present' => false];
$absent['probe_hash'] = AdapterProbe::hash_document($absent);
$absentTypes = LintEnvironment::column_types_from_probe($absent);
duo_check_same(
    false,
    isset($absentTypes['nf3_fields']),
    'a `present:false` table is a real answer about a target that lacks it, and carries no column types to propose from'
);

// ------------------------------------------- 6. the declaration is still the thing that silences

$shipped = nf_policy(nf_manifest($root, false));
duo_check_same(
    [],
    Lint::scan_tree($state, $shipped, LintEnvironment::live($probe)),
    'against the SHIPPED manifest — where a human already wrote all eight lint_ok declarations — the same tree is '
    . 'clean. The proposal is the step before that edit, never a substitute for it: nothing here writes a '
    . 'manifest byte, which is adapter identity (AGENTS.md rule 2)'
);

duo_check_summary('regress_lint_type_exemptions');
