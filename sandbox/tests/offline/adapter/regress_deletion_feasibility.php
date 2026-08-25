<?php
/**
 * Offline characterization for `wp duo adapter-deletion-feasibility` — the
 * deletion-contract feasibility report (`Duo\DeletionFeasibility`).
 *
 * `DeleteGuardEvaluator::lock_index()` decides whether a deletion guard has a
 * complete indexed lock boundary, and it decides it at DELETION time: when it
 * answers null the scanner refuses the whole deletion with "guard table 'X'
 * has no complete indexed lock boundary for Y"
 * (`agent/src/Delete/DeleteGuardReferenceScanner.php:135-142`), on a live
 * site, long after the selector was declared and pinned. This emitter runs
 * that identical computation at AUTHORING time over a proposal nothing has
 * declared yet.
 *
 * The load-bearing property is not that it reports an index. It is:
 *
 *   1. the null it computes is the SAME null the engine would compute — the
 *      verdict published per guard is `lock_index()`'s own return value, and
 *      an explanation that disagrees with it is refused rather than printed;
 *   2. computing that null decides NOTHING. `manifests/ninja-forms.json:17`
 *      records the conclusion in prose — "Duo … does not advertise
 *      table:nf3_forms deletion" — and that sentence was written by a human.
 *      This tool reproduces the FACT under it and must not be able to reach
 *      the sentence: no capability is proposed, no cascade set is echoed, and
 *      the document's own bytes are refused by the capability resolver.
 *
 * ## Why recorded fixtures rather than `FakeWpdb` alone
 *
 * `sandbox/tests/lib/README.md` is explicit that the shared fake declines
 * `SHOW INDEX`, because an answer synthesized from `setPrimaryKey()` would
 * assert the harness's bookkeeping rather than a server's schema — and an
 * index inventory is the entire subject here. So `SHOW TABLES LIKE` runs
 * against the real `FakeWpdb` and `SHOW INDEX` is served from recorded rows
 * through the same thin delegating shim WP-2.1's probe suite uses
 * (`regress_adapter_probe.php`'s `RecordedSchemaWpdb`). Test 1 proves the
 * fake really does decline it, so the fixtures are the sanctioned fill.
 */
declare(strict_types=1);

// From offline/adapter/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

$repoRoot = dirname(__DIR__, 4);

// The two defines the drop-in stamps every document with. Parsed from
// agent/duo.php rather than written here: rule 8 keeps those lines still, and
// a literal copy in a test would be a second place that has to move with them.
$dropIn = (string) file_get_contents($repoRoot . '/agent/duo.php');
duo_check(
    preg_match("/define\('DUO_AGENT_VERSION',\s*'([^']+)'\)/", $dropIn, $versionMatch) === 1
        && preg_match("/define\('DUO_SPEC_VERSION',\s*(\d+)\)/", $dropIn, $specMatch) === 1,
    'agent/duo.php declares DUO_AGENT_VERSION and DUO_SPEC_VERSION'
);
define('DUO_AGENT_VERSION', (string) ($versionMatch[1] ?? ''));
define('DUO_SPEC_VERSION', (int) ($specMatch[1] ?? 0));

require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Adapter/DeletionFeasibility.php';
// The capability resolver, loaded for test 7 only: it is what a pasted
// document would have to get past to become a deletion capability.
require_once $repoRoot . '/agent/src/Policy/DeletionCapabilityResolver.php';

/**
 * WP-CLI's transport, as the offline refusal suites already stub it
 * (`agent/src/Command/Cli.php:6-8` names that arrangement). Test 10 drives
 * the real handler through it; nothing else in the process is a stub.
 */
final class WP_CLI {
    /** @var list<string> */
    public static array $lines = [];

    public static function add_command($name, $class): void {
    }

    public static function line($line): void {
        self::$lines[] = (string) $line;
    }

    public static function halt($status): void {
        throw new RuntimeException("wp-cli halt: $status");
    }

    public static function error($message, $exit = true): void {
        if ($exit !== false) {
            throw new RuntimeException("wp-cli error: $message");
        }
    }

    public static function reset(): void {
        self::$lines = [];
    }
}

// The verb itself, and the journal gate it closes at entry.
require_once $repoRoot . '/agent/src/Review/Journal.php';
require_once $repoRoot . '/agent/src/Command/Cli.php';

use Duo\Canon;
use Duo\DeletionCapabilityResolver;
use Duo\DeletionFeasibility;
use Duo\OptionNameReferenceResolver;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

/**
 * A `$wpdb` that answers `SHOW INDEX` from recorded rows and delegates
 * everything else to `FakeWpdb`.
 *
 * Not an eleventh bespoke fake: no SQL interpreter, no rows, no opinions. Its
 * one addition over the probe suite's shim is `then` — a second answer for the
 * SAME statement, which is how a schema that MOVES between the two reads one
 * guard makes is reproduced without a live server (test 6).
 */
final class RecordedIndexWpdb {
    /** @var list<string> every recorded statement served, in order */
    public array $served = [];

    /** @param array<string,array{rows?:list<array<string,mixed>>,error?:string,then?:array<string,mixed>}> $recorded collapsed SQL => answer */
    public function __construct(private FakeWpdb $inner, private array $recorded) {
    }

    public function __get(string $name): mixed {
        return $this->inner->$name;
    }

    public function __set(string $name, mixed $value): void {
        $this->inner->$name = $value;
    }

    public function __call(string $method, array $args): mixed {
        return $this->inner->$method(...$args);
    }

    public function prepare(string $sql, ...$args): string {
        return $this->inner->prepare($sql, ...$args);
    }

    public function get_var($sql = null, $x = 0, $y = 0): mixed {
        return $this->inner->get_var($sql, $x, $y);
    }

    public function get_results($sql = null, $format = null): mixed {
        $key = self::collapse((string) $sql);
        if (!array_key_exists($key, $this->recorded)) {
            return $this->inner->get_results($sql, $format);
        }
        $this->served[] = $key;
        $answer = $this->recorded[$key];
        if (isset($answer['then']) && is_array($answer['then'])) {
            $this->recorded[$key] = $answer['then'];
        }
        // `wpdb::get_results()` returns [] on a failed read and sets
        // $last_error; reproducing both is the point of the failure arm.
        $this->inner->last_error = (string) ($answer['error'] ?? '');
        return $answer['rows'] ?? [];
    }

    public static function collapse(string $sql): string {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}

/** One `SHOW INDEX` row in the server's own column names, as lock_index() reads them. */
$idx = static function (string $name, string $column, int $seq = 1, ?int $sub = null, int $nonUnique = 1): array {
    return [
        'Key_name' => $name,
        'Seq_in_index' => $seq,
        'Column_name' => $column,
        'Sub_part' => $sub,
        'Non_unique' => $nonUnique,
    ];
};

/**
 * Installs a fresh fake with the named tables seeded and the given SHOW INDEX
 * answers. Fresh per case on purpose: a guard table is either seeded (so
 * `SHOW TABLES LIKE` finds it) or it is not, and that difference is one of the
 * answers under test.
 */
$target = static function (array $tables, array $recorded): RecordedIndexWpdb {
    $fake = FakeWpdb::install();
    foreach ($tables as $table) {
        $fake->seedTable($table, []);
    }
    $GLOBALS['wpdb'] = new RecordedIndexWpdb($fake, $recorded);
    return $GLOBALS['wpdb'];
};

WpStore::reset();

// --------------------------------------------------------------------------
echo "\n== 1. FakeWpdb declines SHOW INDEX (so the recorded fixtures are sanctioned) ==\n";
// --------------------------------------------------------------------------
$plain = FakeWpdb::install();
$plain->seedTable('wp_nf3_fields', []);
duo_check_throws(
    static fn() => $plain->get_results('SHOW INDEX FROM `wp_nf3_fields`', ARRAY_A),
    LogicException::class,
    'FakeWpdb declines SHOW INDEX by name — an index inventory is the whole subject, so it is recorded, not synthesized'
);

// --------------------------------------------------------------------------
echo "\n== 2. the Ninja Forms conclusion, as a COMPUTED null ==\n";
// --------------------------------------------------------------------------
// The proposal is not hand-typed: it is derived from the manifest's own
// declared refs into nf3_form, which is exactly the reverse-reference set an
// author writing `table:nf3_forms` guards would have to cover.
$ninjaForms = json_decode((string) file_get_contents($repoRoot . '/manifests/ninja-forms.json'), true);
duo_check(is_array($ninjaForms), 'manifests/ninja-forms.json is readable');
duo_check(
    !isset($ninjaForms['deletions']['table:nf3_forms']),
    'table:nf3_forms deletion is NOT declared — the prose conclusion this report reproduces the fact under'
);
$duo3328 = '';
foreach ((array) ($ninjaForms['notes'] ?? []) as $note) {
    if (str_contains((string) $note, 'DUO-3328')) {
        $duo3328 = (string) $note;
    }
}
duo_check(
    str_contains($duo3328, 'nf3_actions.parent_id and nf3_fields.parent_id without complete indexes')
        && str_contains($duo3328, 'does not advertise table:nf3_forms deletion'),
    'the manifest note records, in prose, both the schema fact and the human decision taken from it'
);

$proposedGuards = [];
foreach ((array) ($ninjaForms['tables'] ?? []) as $table => $facts) {
    foreach ((array) ($facts['refs'] ?? []) as $ref) {
        if ((string) ($ref['kind'] ?? '') === 'nf3_form') {
            $proposedGuards[] = [
                'table' => (string) $table,
                'column' => (string) $ref['column'],
                'id_kind' => 'nf3_form',
                'reason' => "$table rows reference this form",
            ];
        }
    }
}
duo_check_same(
    ['nf3_actions.parent_id', 'nf3_fields.parent_id'],
    array_map(static fn(array $g): string => $g['table'] . '.' . $g['column'], $proposedGuards),
    'the proposed guard set is derived from the manifest\'s own declared refs into nf3_form'
);

// Ninja Forms 3.14.11 as certify_deletion_matrix.sh:17-19 records it: the
// shipped parent_id columns carry no index. nf3_fields' `key` index is kept
// in the fixture so the table is not trivially index-free — the answer has to
// come from "no index LEADS with parent_id", not from an empty inventory.
$target(['wp_nf3_actions', 'wp_nf3_fields'], [
    'SHOW INDEX FROM `wp_nf3_actions`' => ['rows' => [$idx('PRIMARY', 'id', 1, null, 0)]],
    'SHOW INDEX FROM `wp_nf3_fields`' => ['rows' => [
        $idx('PRIMARY', 'id', 1, null, 0),
        $idx('key_idx', 'key', 1, 191),
    ]],
]);

$report = DeletionFeasibility::report(['table:nf3_forms' => ['guards' => $proposedGuards]]);
$ninjaRows = $report['selectors']['table:nf3_forms']['guards'];

duo_check_same('duo-deletion-feasibility/v1', $report['format'], 'the envelope names the versioned feasibility format');
duo_check_same(false, $report['authority'], 'the document declares authority:false in its own bytes');
duo_check_same('values_omitted', $report['redaction'], 'the document declares the value redaction');
duo_check_same(
    $report['feasibility_hash'],
    DeletionFeasibility::hash_document($report),
    'feasibility_hash is the canonical hash of the document minus itself'
);
duo_check_same(
    ['agent_version' => DUO_AGENT_VERSION, 'spec_version' => DUO_SPEC_VERSION],
    $report['target'],
    'the answer is stamped with the loaded agent — a lock verdict is a fact about one target under one engine'
);

duo_check_same(
    [
        'column' => 'parent_id',
        'index' => null,
        'leading' => [],
        'lock_column' => 'parent_id',
        'prefix' => null,
        'reason' => 'no index leads with this column',
        'table' => 'nf3_actions',
        'table_present' => true,
    ],
    $ninjaRows[0],
    'nf3_actions.parent_id: the DUO-3328 prose reproduced as a computed null, with the reason named'
);
duo_check_same(
    [
        'column' => 'parent_id',
        'index' => null,
        'leading' => [],
        'lock_column' => 'parent_id',
        'prefix' => null,
        'reason' => 'no index leads with this column',
        'table' => 'nf3_fields',
        'table_present' => true,
    ],
    $ninjaRows[1],
    'nf3_fields.parent_id: the same null, computed against a table that DOES carry an index (on `key`)'
);
duo_check(
    $ninjaRows[0]['table_present'] === true && $ninjaRows[1]['table_present'] === true,
    'both guard tables are PRESENT — the null is about the index, not about a missing table'
);

// --------------------------------------------------------------------------
echo "\n== 3. the contrast: core's shipped, advertised guards compute an index ==\n";
// --------------------------------------------------------------------------
// Fed from manifests/core.json's own guards, unedited. A guard field added
// there that this report does not model fails here rather than in an author's
// terminal — the closed GUARD_KEYS set is checked against the shipped set.
$core = json_decode((string) file_get_contents($repoRoot . '/manifests/core.json'), true);
$postGuards = $core['deletions']['post:post']['guards'] ?? [];
duo_check_same(
    ['comments.comment_post_ID', 'posts.post_parent'],
    array_map(static fn(array $g): string => $g['table'] . '.' . $g['column'], $postGuards),
    'core.json post:post ships two reverse-reference guards, including one with exclude_where/source_pk'
);

// WordPress's own schema (wp-admin/includes/schema.php): wp_comments carries
// KEY comment_post_ID (comment_post_ID); wp_posts carries KEY post_parent
// (post_parent).
$target(['wp_comments', 'wp_posts'], [
    'SHOW INDEX FROM `wp_comments`' => ['rows' => [
        $idx('PRIMARY', 'comment_ID', 1, null, 0),
        $idx('comment_post_ID', 'comment_post_ID'),
    ]],
    'SHOW INDEX FROM `wp_posts`' => ['rows' => [
        $idx('PRIMARY', 'ID', 1, null, 0),
        $idx('post_parent', 'post_parent'),
        $idx('type_status_date', 'post_type', 1, 20),
        $idx('type_status_date', 'post_status', 2, 20),
    ]],
]);
$coreRows = DeletionFeasibility::report(['post:post' => ['guards' => $postGuards]])['selectors']['post:post']['guards'];
duo_check_same(
    ['index' => 'comment_post_ID', 'prefix' => null, 'reason' => null],
    ['index' => $coreRows[0]['index'], 'prefix' => $coreRows[0]['prefix'], 'reason' => $coreRows[0]['reason']],
    'a guard whose column leads an index reports that index and NO reason: the lock boundary exists'
);
duo_check_same(
    ['index' => 'post_parent', 'prefix' => null, 'reason' => null],
    ['index' => $coreRows[1]['index'], 'prefix' => $coreRows[1]['prefix'], 'reason' => $coreRows[1]['reason']],
    'the qualified guard (exclude_where + source_id_kind/source_pk) resolves on its own first column'
);
duo_check_same(
    [['index' => 'post_parent', 'prefix' => null]],
    $coreRows[1]['leading'],
    'a column that is only a LATER part of a composite index is not reported as leading it'
);

// --------------------------------------------------------------------------
echo "\n== 4. the prefix rule, in lock_index()'s own terms ==\n";
// --------------------------------------------------------------------------
// A metadata guard's key literal is compared against Sub_part in BYTES
// (`DeleteGuardEvaluator.php:452-454`); postmeta is the table the manifest
// grammar requires such a guard to target.
$metaGuard = static fn(string $metaKey): array => [
    'table' => 'postmeta',
    'column' => 'post_id',
    'id_kind' => 'post',
    'meta_key' => $metaKey,
    'ref' => 'post',
    'source_id_kind' => 'post',
    'source_pk' => 'post_id',
    'reason' => 'a stored reference names this post',
];
$postmetaIndexes = static fn(array $rows): array => ['SHOW INDEX FROM `wp_postmeta`' => ['rows' => $rows]];

$target(['wp_postmeta'], $postmetaIndexes([
    $idx('PRIMARY', 'meta_id', 1, null, 0),
    $idx('meta_key', 'meta_key', 1, 191),
]));
$wide = DeletionFeasibility::report(['post:post' => ['guards' => [$metaGuard('_linked_post_id')]]]);
$wideRow = $wide['selectors']['post:post']['guards'][0];
duo_check_same(
    ['index' => 'meta_key', 'lock_column' => 'meta_key', 'prefix' => 191, 'reason' => null],
    [
        'index' => $wideRow['index'],
        'lock_column' => $wideRow['lock_column'],
        'prefix' => $wideRow['prefix'],
        'reason' => $wideRow['reason'],
    ],
    'a metadata guard locks on meta_key, and a 191-byte prefix covers a 15-byte declared key'
);

$target(['wp_postmeta'], $postmetaIndexes([
    $idx('PRIMARY', 'meta_id', 1, null, 0),
    $idx('meta_key', 'meta_key', 1, 8),
]));
$narrowRow = DeletionFeasibility::report([
    'post:post' => ['guards' => [$metaGuard('_linked_post_id')]],
])['selectors']['post:post']['guards'][0];
duo_check_same(
    ['index' => null, 'prefix' => null, 'reason' => 'prefix index of 8 bytes cannot cover a declared key of 15'],
    ['index' => $narrowRow['index'], 'prefix' => $narrowRow['prefix'], 'reason' => $narrowRow['reason']],
    'a prefix too short for the declared key is a null with the SIZES named, not a covering index'
);
duo_check_same(
    [['index' => 'meta_key', 'prefix' => 8]],
    $narrowRow['leading'],
    'the rejected index is still reported as leading — the author sees what would have to grow'
);

// Two candidates, and the reason must name the CLOSEST miss: if the widest
// leading prefix cannot cover the key, naming a narrower one would understate
// the schema change.
$target(['wp_postmeta'], $postmetaIndexes([
    $idx('meta_key_short', 'meta_key', 1, 8),
    $idx('meta_key_mid', 'meta_key', 1, 12),
]));
$widestRow = DeletionFeasibility::report([
    'post:post' => ['guards' => [$metaGuard('_linked_post_id')]],
])['selectors']['post:post']['guards'][0];
duo_check_same(
    'prefix index of 12 bytes cannot cover a declared key of 15',
    $widestRow['reason'],
    'the reason names the WIDEST rejected prefix, the closest miss, not the first one walked'
);

// And the walk continues past a rejection exactly as lock_index()'s `continue`
// does: a narrower index first does not hide a sufficient one behind it.
$target(['wp_postmeta'], $postmetaIndexes([
    $idx('meta_key_short', 'meta_key', 1, 8),
    $idx('meta_key_full', 'meta_key', 1, 191),
]));
$acceptedRow = DeletionFeasibility::report([
    'post:post' => ['guards' => [$metaGuard('_linked_post_id')]],
])['selectors']['post:post']['guards'][0];
duo_check_same(
    ['index' => 'meta_key_full', 'prefix' => 191, 'reason' => null],
    ['index' => $acceptedRow['index'], 'prefix' => $acceptedRow['prefix'], 'reason' => $acceptedRow['reason']],
    'a too-narrow index first does not hide a sufficient one behind it (lock_index() continues, so this does)'
);

// --------------------------------------------------------------------------
echo "\n== 5. an absent guard table is an ANSWER, not an unindexed verdict ==\n";
// --------------------------------------------------------------------------
$target([], []);
$absentRow = DeletionFeasibility::report([
    'table:nf3_forms' => ['guards' => [$proposedGuards[0]]],
])['selectors']['table:nf3_forms']['guards'][0];
duo_check_same(
    [
        'index' => null,
        'reason' => 'guard table is absent on this target',
        'table_present' => false,
    ],
    [
        'index' => $absentRow['index'],
        'reason' => $absentRow['reason'],
        'table_present' => $absentRow['table_present'],
    ],
    'a guard table this target does not have stays distinguishable from an unindexed one'
);
duo_check(
    $absentRow['reason'] !== 'no index leads with this column',
    'the scanner reports absence before any index question, and so does this — blaming the index would be wrong'
);

// A selector with no guards at all is a real proposal (core's `menu:nav_menu`
// ships exactly that), and its answer is an empty list, not a refusal.
$target([], []);
$noGuards = DeletionFeasibility::report(['menu:nav_menu' => ['guards' => []]]);
duo_check_same(
    ['guards' => []],
    $noGuards['selectors']['menu:nav_menu'],
    'a selector with no guards answers an empty guard list: the lock question was never the open one'
);

// --------------------------------------------------------------------------
echo "\n== 6. the verdict is lock_index()'s own, and a disagreement is refused ==\n";
// --------------------------------------------------------------------------
// Each guard is read twice — once by lock_index(), once by the walk that
// EXPLAINS it. A schema that moves between the two reads (or a second
// implementation drifting from the first) must refuse, not publish a reason
// that does not belong to the answer.
$target(['wp_postmeta'], [
    'SHOW INDEX FROM `wp_postmeta`' => [
        // lock_index() sees a covering index and answers `meta_key` …
        'rows' => [$idx('meta_key', 'meta_key', 1, 191)],
        // … and the explaining walk sees an inventory without it.
        'then' => ['rows' => [$idx('PRIMARY', 'meta_id', 1, null, 0)]],
    ],
]);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['post:post' => ['guards' => [$metaGuard('_linked_post_id')]]]),
    RuntimeException::class,
    'an explanation that disagrees with lock_index()\'s verdict is REFUSED, not published',
    "disagrees with DeleteGuardEvaluator::lock_index()'s own verdict"
);

$target(['wp_postmeta'], [
    'SHOW INDEX FROM `wp_postmeta`' => [
        'rows' => [$idx('PRIMARY', 'meta_id', 1, null, 0)],
        'then' => ['rows' => [$idx('meta_key', 'meta_key', 1, 191)]],
    ],
]);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['post:post' => ['guards' => [$metaGuard('_linked_post_id')]]]),
    RuntimeException::class,
    'the refusal is symmetric: an index appearing between the two reads refuses just as loudly'
);

// --------------------------------------------------------------------------
echo "\n== 7. no capability is proposed and no ratification is taken ==\n";
// --------------------------------------------------------------------------
// The cascade set is the field that carries destructive authority. It is
// refused BY NAME rather than dropped, because a document that accepted it
// would be one paste away from looking like the capability itself.
$target(['wp_nf3_actions'], [
    'SHOW INDEX FROM `wp_nf3_actions`' => ['rows' => [$idx('PRIMARY', 'id', 1, null, 0)]],
]);
duo_check_throws(
    static fn() => DeletionFeasibility::report([
        'table:nf3_forms' => ['cascades' => ['attached_meta'], 'guards' => [$proposedGuards[0]]],
    ]),
    RuntimeException::class,
    'a proposal carrying a cascade set is refused by name — this report owns no destructive authority',
    'owns no destructive authority'
);

// The document's own bytes are not a capability, and the engine says so: the
// selectors block is manifest-SHAPED, and the resolver that turns a manifest
// into deletion authority refuses it for the field this report will not carry.
$asManifest = [
    'name' => 'proposal-pasted-verbatim',
    'deletions' => $report['selectors'],
];
$resolver = new DeletionCapabilityResolver(
    [$asManifest],
    new OptionNameReferenceResolver([], static fn(): bool => false),
    ['string', 'csv']
);
duo_check_throws(
    static fn() => $resolver->capability('table:nf3_forms'),
    RuntimeException::class,
    'pasting the report into a manifest does not make a capability: the resolver refuses it',
    'must declare a cascades list'
);

$encoded = Canon::encode($report);
foreach (['cascades', 'capability', 'ratif', 'advertise', 'eligible', 'approved', 'supported', 'recommend'] as $word) {
    duo_check(
        !str_contains($encoded, $word),
        "no `$word` word appears anywhere in a feasibility document"
    );
}

// The whole public surface, by reflection: two functions, one of which only
// hashes. There is no emit-a-fragment, propose-a-selector or ratify method for
// a caller to reach for.
$publicApi = array_map(
    static fn(ReflectionMethod $m): string => $m->getName(),
    (new ReflectionClass(DeletionFeasibility::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);
sort($publicApi);
duo_check_same(
    ['hash_document', 'report'],
    $publicApi,
    'the emitter can report and hash, and nothing else — there is no method that proposes a selector'
);

$closed = ['column', 'index', 'leading', 'lock_column', 'prefix', 'reason', 'table', 'table_present'];
foreach ($report['selectors'] as $selector => $facts) {
    duo_check_same(
        ['guards'],
        array_keys($facts),
        "selector '$selector' carries a guard list and nothing beside it"
    );
    foreach ($facts['guards'] as $i => $row) {
        duo_check_same(
            [],
            array_values(array_diff(array_keys($row), $closed)),
            "guard row $i carries only the closed answer vocabulary"
        );
    }
}
// The guard's own authored `reason` prose explains why the reference matters;
// this row's `reason` explains why the lock is impossible. Two meanings under
// one key in one document is how a reviewer misreads it.
duo_check(
    !str_contains($encoded, 'rows reference this form'),
    'the guard\'s authored reason prose is not echoed into the row whose `reason` means something else'
);

// --------------------------------------------------------------------------
echo "\n== 8. loud refusals: nothing is inferred, sanitized or silently dropped ==\n";
// --------------------------------------------------------------------------
$target(['wp_nf3_actions'], [
    'SHOW INDEX FROM `wp_nf3_actions`' => ['rows' => [$idx('PRIMARY', 'id', 1, null, 0)]],
]);
$oneGuard = ['guards' => [$proposedGuards[0]]];

duo_check_throws(
    static fn() => DeletionFeasibility::report([]),
    RuntimeException::class,
    'a report with no selector refuses rather than emitting an empty document'
);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['nf3_forms' => $oneGuard]),
    RuntimeException::class,
    'a selector outside the <post|term|menu|table>:<type> grammar is refused, and never echoed',
    'outside the <post|term|menu|table>:<type> grammar'
);
$unsafeSelector = null;
try {
    DeletionFeasibility::report(['table:nf3_forms; DROP TABLE x' => $oneGuard]);
} catch (Throwable $e) {
    $unsafeSelector = $e->getMessage();
}
duo_check(
    is_string($unsafeSelector) && !str_contains($unsafeSelector, 'DROP TABLE'),
    'the refusal for an unsafe selector does not repeat the one string nothing has vetted'
);
duo_check_throws(
    static fn() => DeletionFeasibility::report(array_fill_keys(
        array_map(static fn(int $i): string => "table:t$i", range(1, 33)),
        $oneGuard
    )),
    RuntimeException::class,
    'more selectors than MAX_SELECTORS is refused rather than silently truncated',
    'refuses more than 32 selectors'
);
duo_check_throws(
    static fn() => DeletionFeasibility::report([
        'table:nf3_forms' => ['guards' => array_fill(0, 33, $proposedGuards[0])],
    ]),
    RuntimeException::class,
    'more guards than MAX_GUARDS is refused rather than silently truncated',
    'refuses more than 32 guards'
);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['table:nf3_forms' => ['guards' => $oneGuard['guards'], 'notes' => 'x']]),
    RuntimeException::class,
    'a proposal field this report does not model is refused, not ignored'
);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['table:nf3_forms' => ['guards' => [
        ['table' => 'nf3_actions', 'column' => 'parent_id', 'id_kind' => 'nf3_form', 'when_referenced' => 'skip'],
    ]]]),
    RuntimeException::class,
    'a guard field outside the manifest grammar is refused: it could decide which column locks',
    'it would answer for the wrong column'
);
// …and the refusal NAMES it. The author who hits this is annotating a guard
// by hand, and `wp help duo adapter-deletion-feasibility` documents
// `--proposal` only as "`<selector>: {"guards": [...]}` — minus `cascades`",
// listing none of the 13 keys in GUARD_KEYS. Measured on a live WPForms Lite
// pair: the field that hit it was `note`, and neither the key nor the legal
// set reached the terminal.
$unmodelled = null;
try {
    DeletionFeasibility::report(['table:nf3_forms' => ['guards' => [
        ['table' => 'nf3_actions', 'column' => 'parent_id', 'id_kind' => 'nf3_form', 'note' => 'the payments twin'],
    ]]]);
} catch (Throwable $e) {
    $unmodelled = $e;
}
duo_check(
    $unmodelled instanceof \Duo\CommandRefusalException
        && str_contains($unmodelled->publicMessage, '`note`')
        && str_contains($unmodelled->publicMessage, "position 0 of 'table:nf3_forms'"),
    'the refusal names the offending guard field and where it sits, not merely that one exists'
);
duo_check(
    $unmodelled instanceof \Duo\CommandRefusalException
        && str_contains($unmodelled->remediation, 'cast, column, exclude_where, id_kind, identity_column, meta_key')
        && str_contains($unmodelled->remediation, 'source_id_kind, source_pk, table, where'),
    'and its remediation lists the whole closed guard grammar, which is the fact `wp help` never gives'
);
duo_check(
    !str_contains(
        (string) $unmodelled?->getMessage(),
        'the payments twin'
    ),
    'the offending VALUE is never echoed: only the key the grammar closed against is named'
);
$hostileKey = null;
try {
    DeletionFeasibility::report(['table:nf3_forms' => ['guards' => [
        ['table' => 'nf3_actions', 'column' => 'parent_id', 'id_kind' => 'nf3_form', "no'te; DROP TABLE x" => 1],
    ]]]);
} catch (Throwable $e) {
    $hostileKey = $e->getMessage();
}
duo_check(
    is_string($hostileKey) && !str_contains($hostileKey, 'DROP TABLE')
        && str_contains($hostileKey, 'outside the portable identifier grammar'),
    'a key nothing has vetted is described rather than echoed, exactly as an unsafe selector is'
);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['table:nf3_forms' => ['guards' => [
        ['table' => 'nf3_actions; DROP TABLE x', 'column' => 'parent_id', 'id_kind' => 'nf3_form'],
    ]]]),
    RuntimeException::class,
    'an unsafe guard table name is refused without echoing it',
    'outside the portable identifier grammar'
);

// `cast` is a shape the manifest grammar accepts
// (`DeletionCapabilityResolver.php:123-127`), so refusing it would refuse a
// legitimate proposal over a field that cannot change which index answers it.
$target(['wp_postmeta'], $postmetaIndexes([$idx('meta_key', 'meta_key', 1, 191)]));
$withCast = $metaGuard('_linked_post_id') + ['cast' => 'csv'];
// Caught rather than left to fatal: the failure this pins is a REFUSAL of a
// legitimate field, and the refusal's own message is the evidence a reader
// needs to see beside the failing assertion.
try {
    $castAnswer = DeletionFeasibility::report([
        'post:post' => ['guards' => [$withCast]],
    ])['selectors']['post:post']['guards'][0]['index'];
} catch (Throwable $e) {
    $castAnswer = 'refused: ' . $e->getMessage();
}
duo_check_same(
    'meta_key',
    $castAnswer,
    'every field of the shipped guard grammar is modelled, `cast` included'
);

// A failed read answers [] exactly as an unindexed table does, and "unindexed"
// is the conclusion this report must never reach by inference.
$target(['wp_nf3_actions'], [
    'SHOW INDEX FROM `wp_nf3_actions`' => ['error' => 'simulated index introspection failure'],
]);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['table:nf3_forms' => $oneGuard]),
    RuntimeException::class,
    'a failed index read refuses instead of inferring an unindexed guard table',
    'refusing to infer'
);

$target(['wp_nf3_actions'], [
    'SHOW INDEX FROM `wp_nf3_actions`' => ['rows' => [$idx('idx`hostile', 'parent_id')]],
]);
duo_check_throws(
    static fn() => DeletionFeasibility::report(['table:nf3_forms' => $oneGuard]),
    RuntimeException::class,
    'an index name outside the portable grammar refuses rather than reporting a silently rewritten name'
);

$GLOBALS['wpdb'] = null;
duo_check_throws(
    static fn() => DeletionFeasibility::report(['table:nf3_forms' => $oneGuard]),
    RuntimeException::class,
    'without a live target the report refuses: a document full of nulls would be indistinguishable from a real one',
    'needs a live target'
);

// --------------------------------------------------------------------------
echo "\n== 9. the wp duo surface ==\n";
// --------------------------------------------------------------------------
$cli = (string) file_get_contents($repoRoot . '/agent/src/Command/Cli.php');
duo_check(
    str_contains($cli, '@subcommand adapter-deletion-feasibility'),
    'the verb is declared as wp duo adapter-deletion-feasibility'
);
duo_check(
    substr_count($cli, 'public function adapter_deletion_feasibility(') === 1,
    'exactly one handler was added to the command surface'
);
duo_check(
    str_contains($cli, "self::halt_json_failure(\$t, \$assoc, 'adapter-deletion-feasibility')"),
    'the verb routes its refusals through the shared duo-command-refusal/v1 envelope'
);
duo_check(
    preg_match(
        '/public function adapter_deletion_feasibility\(\$args, \$assoc\) \{\s*(?:\/\/[^\n]*\n\s*)*Journal::suspend_for_observation\(\);/',
        $cli
    ) === 1,
    'the read-only verb suspends the provenance journal at ENTRY, before any argument refusal'
);
duo_check(
    str_contains($dropIn, "require_once __DIR__ . '/src/Adapter/DeletionFeasibility.php';"),
    'the emitter is loaded by the drop-in bootstrap, like every one of its siblings'
);
$internals = (string) file_get_contents($repoRoot . '/docs/guides/internals.md');
duo_check(
    str_contains($internals, '`wp duo adapter-deletion-feasibility`'),
    'the authoring internal is named in docs/guides/internals.md (MUP §5.1)'
);

// --------------------------------------------------------------------------
echo "\n== 10. the verb, driven: both output paths and the shared refusal envelope ==\n";
// --------------------------------------------------------------------------
// The human summary is what an author actually reads, and it indexes into
// every row the emitter builds — asserting it from source text would not
// notice a key that stopped being written. `Cli.php` states that the offline
// refusal suites load it against pre-declared stubs (`:6-8`); WP_CLI above is
// that stub and nothing else here is one.
$scratch = sys_get_temp_dir() . '/duo_regress_deletion_feasibility_' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);
register_shutdown_function(static function () use ($scratch): void {
    foreach (glob($scratch . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($scratch);
});
$proposalPath = $scratch . '/proposal.json';
file_put_contents($proposalPath, Canon::encode(['table:nf3_forms' => ['guards' => $proposedGuards]]));

$target(['wp_nf3_actions', 'wp_nf3_fields'], [
    'SHOW INDEX FROM `wp_nf3_actions`' => ['rows' => [$idx('PRIMARY', 'id', 1, null, 0)]],
    'SHOW INDEX FROM `wp_nf3_fields`' => ['rows' => [
        $idx('PRIMARY', 'id', 1, null, 0),
        $idx('key_idx', 'key', 1, 191),
    ]],
]);

$verb = new Duo\Cli();
WP_CLI::reset();
$verb->adapter_deletion_feasibility([], ['proposal' => $proposalPath]);
$human = implode("\n", WP_CLI::$lines);
duo_check(
    str_contains($human, 'authority: false; redaction: values_omitted'),
    'the human summary declares the same two boundaries the document does'
);
duo_check(
    str_contains(
        $human,
        'table:nf3_forms: nf3_actions.parent_id locks on parent_id — NO covering index: no index leads with this column'
    ),
    'the human summary names the guard, the column it locks on, and why there is no boundary'
);
duo_check(
    str_contains($human, 'feasibility hash: ' . $report['feasibility_hash']),
    'the summary carries the same hash the document does, so a pasted answer can be checked'
);
duo_check(
    !str_contains($human, 'advertise') && !str_contains($human, 'cascade'),
    'the terminal never suggests what to declare — the summary reports and stops'
);

// JSON is the transport form: the emitted bytes ARE the document.
WP_CLI::reset();
$verb->adapter_deletion_feasibility([], ['proposal' => $proposalPath, 'format' => 'json']);
$emitted = json_decode(implode("\n", WP_CLI::$lines), true);
duo_check_same(
    $report['feasibility_hash'],
    $emitted['feasibility_hash'] ?? null,
    'the verb emits the same document the emitter builds, for the same target and proposal'
);
duo_check_same(
    $emitted['feasibility_hash'] ?? null,
    DeletionFeasibility::hash_document(is_array($emitted) ? $emitted : []),
    'the emitted bytes verify against their own hash after a round trip through the wire'
);

// A refusal reaches the shared envelope at RUNTIME, not merely in source.
$journalSuspended = new ReflectionProperty(Duo\Journal::class, 'observationSuspended');
$journalSuspended->setValue(null, false);
WP_CLI::reset();
$envelope = null;
try {
    $verb->adapter_deletion_feasibility([], ['proposal' => $scratch . '/absent.json', 'format' => 'json']);
} catch (Throwable $halted) {
    $envelope = json_decode(implode("\n", WP_CLI::$lines), true);
}
duo_check_same(
    ['duo-command-refusal/v1', 'adapter-deletion-feasibility', false],
    [$envelope['format'] ?? null, $envelope['command'] ?? null, $envelope['ok'] ?? null],
    'an argument refusal reaches the shared duo-command-refusal/v1 envelope under its own command name'
);
duo_check(
    is_string($envelope['remediation'] ?? null)
        && $envelope['remediation'] !== 'correct the named adapter-deletion-feasibility blocker, then retry the command',
    'the verb carries a reviewed remediation arm, not the default that promises to name what it redacted'
);
duo_check_same(
    true,
    $journalSuspended->getValue(),
    'the journal is suspended before the refusal: asking whether a guard could lock never becomes a Duo INSERT'
);

// A PROPOSAL refusal reaches the operator as itself, not as a redaction.
// Measured on a live WPForms Lite pair: a guard carrying `note` came back as
// `adapter_deletion_feasibility_failed` / "adapter-deletion-feasibility
// refused at an unclassified safety gate" / `details_redacted: true`, with
// remediation "inspect the proposed deletion selectors and their guards" —
// while the sentence that names the real problem was thrown in
// DeletionFeasibility and dropped by Cli::halt_json_failure()'s catch-all,
// which publishes a message only from a TYPED refusal (`Cli.php:88-104`).
// The classification was right; the source was untyped. This drives the whole
// path — file on disk, real handler, real envelope — because that is where
// the sentence was being lost.
$notePath = $scratch . '/note-proposal.json';
file_put_contents($notePath, Canon::encode(['table:nf3_forms' => ['guards' => [
    ['table' => 'nf3_actions', 'column' => 'parent_id', 'id_kind' => 'nf3_form', 'note' => 'the actions twin'],
]]]));
WP_CLI::reset();
$noteEnvelope = null;
try {
    $verb->adapter_deletion_feasibility([], ['proposal' => $notePath, 'format' => 'json']);
} catch (Throwable $halted) {
    $noteEnvelope = json_decode(implode("\n", WP_CLI::$lines), true);
}
duo_check_same(
    ['invalid_arguments', 'invalid_arguments', null],
    [
        $noteEnvelope['error'] ?? null,
        $noteEnvelope['reason_code'] ?? null,
        $noteEnvelope['details_redacted'] ?? null,
    ],
    'a guard field the report does not model is a named argument refusal, never the redacted unclassified gate'
);
duo_check(
    is_string($noteEnvelope['message'] ?? null)
        && str_contains($noteEnvelope['message'], '`note`')
        && str_contains($noteEnvelope['message'], 'it would answer for the wrong column'),
    'the emitter\'s own sentence — the offending key and why it matters — reaches the JSON envelope'
);
duo_check(
    is_string($noteEnvelope['remediation'] ?? null)
        && str_contains($noteEnvelope['remediation'], 'option_name_ref, reason, ref'),
    'and the remediation hands the author the closed guard grammar instead of "inspect the proposed guards"'
);
duo_check(
    !str_contains((string) json_encode($noteEnvelope), 'unclassified safety gate')
        && !str_contains((string) json_encode($noteEnvelope), 'adapter_deletion_feasibility_failed')
        && !str_contains((string) json_encode($noteEnvelope), 'the actions twin'),
    'the prior redacted envelope is gone and the annotated VALUE still never leaves the target'
);
// The human path was answering with the catch-all sentence too.
WP_CLI::reset();
$noteHuman = null;
try {
    $verb->adapter_deletion_feasibility([], ['proposal' => $notePath]);
} catch (Throwable $halted) {
    $noteHuman = $halted->getMessage();
}
duo_check(
    is_string($noteHuman) && str_contains($noteHuman, '`note`')
        && !str_contains($noteHuman, 'deletion feasibility refused; inspect the proposed selectors'),
    'the terminal gets the same named sentence, not the generic "inspect the proposed selectors" fallback'
);
// Everything the catch-all is FOR keeps taking it: a failed schema read can
// carry a server identifier, so it stays operator-private and redacted.
$target(['wp_nf3_actions'], [
    'SHOW INDEX FROM `wp_nf3_actions`' => ['error' => 'simulated index introspection failure'],
]);
WP_CLI::reset();
$readEnvelope = null;
try {
    $verb->adapter_deletion_feasibility([], ['proposal' => $proposalPath, 'format' => 'json']);
} catch (Throwable $halted) {
    $readEnvelope = json_decode(implode("\n", WP_CLI::$lines), true);
}
duo_check_same(
    ['adapter_deletion_feasibility_failed', true],
    [$readEnvelope['error'] ?? null, $readEnvelope['details_redacted'] ?? null],
    'a failed schema read still redacts through the unclassified gate: typing the PROPOSAL refusals moved nothing else'
);

duo_check_summary(
    'deletion feasibility (duo-deletion-feasibility/v1): lock_index() answered at authoring time, deciding nothing'
);
