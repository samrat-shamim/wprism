<?php
/**
 * Offline characterization for `wp duo adapter-probe` — the live half of
 * `duo adapter-draft`'s `--evidence=` seam (`Duo\AdapterProbe`).
 *
 * The draft generator is WordPress-free, so every typed-table fact that needs
 * a server leaves it as a proposal carrying a NAMED question. This emitter is
 * the only thing that can answer one, and the property that matters is not
 * that it reports a schema — it is that reporting a schema is ALL it can do:
 * `authority: false`, no `class`/identity/deletion word anywhere in the
 * document, no row value, and a closed per-table key set the consumer refuses
 * anything outside of.
 *
 * ## Why this suite is half FakeWpdb and half recorded fixtures
 *
 * `sandbox/tests/lib/README.md` is explicit that the shared fake deliberately
 * declines schema-qualified reads (`information_schema.*`) and `SHOW INDEX`,
 * because a synthetic answer fed from `setPrimaryKey()`/`setUniqueKey()` would
 * assert the harness's own bookkeeping rather than a server's schema. It also
 * interprets only `COUNT(*)`, not `COUNT(DISTINCT col)` — whose live answer is
 * collation-dependent (a `utf8mb4_*_ci` column collapses `A` and `a`) and NULL
 * -excluding, which is exactly the kind of MySQL semantics the fake refuses to
 * reimplement. So the SHOW TABLES / SHOW COLUMNS half runs against the real
 * `FakeWpdb`, and those three statements are served from RECORDED fixtures
 * through a thin delegating shim. Test 1 proves the fake really does decline
 * each of them, so the fixtures are the sanctioned fill rather than a
 * convenience.
 */
declare(strict_types=1);

// From offline/adapter/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

$repoRoot = dirname(__DIR__, 4);
require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Adapter/AdapterProbe.php';
// The consumer, loaded for the seam test only: its file-scope declares no
// dependency, and read_probe() needs nothing but Canon.
require_once $repoRoot . '/cli/src/Adapter/AdapterDraft.php';

use Duo\AdapterProbe;
use Duo\Orchestrator\AdapterDraft;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

/**
 * A `$wpdb` that answers exactly the statements `FakeWpdb` refuses, from
 * recorded rows, and delegates everything else to it.
 *
 * This is not an eleventh bespoke fake: it holds no SQL interpreter, no rows
 * and no opinions. It is the recorded-fixture seam the library's README names
 * for facts the interpreter deliberately does not model, and every statement
 * it does not recognise reaches the real `FakeWpdb` — including the ones the
 * subject under test would be wrong to issue.
 */
final class RecordedSchemaWpdb {
    /** @var list<string> every recorded statement served, in order */
    public array $served = [];

    /** @param array<string,array{rows?:list<array<string,mixed>>,error?:string}> $recorded collapsed SQL => answer */
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
        // `wpdb::get_results()` returns [] on a failed read and sets
        // $last_error; reproducing both is the point of the failure arm.
        $this->inner->last_error = (string) ($answer['error'] ?? '');
        return $answer['rows'] ?? [];
    }

    public static function collapse(string $sql): string {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}

$store = WpStore::reset();
$fake = FakeWpdb::install();

// --------------------------------------------------------------------------
echo "\n== 1. the three statements FakeWpdb declines (so the fixtures are sanctioned) ==\n";
// --------------------------------------------------------------------------
$fake->seedTable('wp_rooms', []);
foreach ([
    'SHOW INDEX FROM `wp_rooms`' => 'SHOW INDEX',
    'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = \'wp_rooms\''
        => 'a schema-qualified information_schema read',
    'SELECT COUNT(*) AS row_count, COUNT(DISTINCT `code`) AS distinct_count FROM `wp_rooms`'
        => 'COUNT(DISTINCT col)',
] as $sql => $what) {
    duo_check_throws(
        static fn() => $fake->get_results($sql, ARRAY_A),
        LogicException::class,
        "FakeWpdb declines $what, naming the statement — recorded fixtures are the sanctioned fill"
    );
}

// --------------------------------------------------------------------------
echo "\n== 2. one probed table, from the real fake plus recorded schema facts ==\n";
// --------------------------------------------------------------------------
$fake = FakeWpdb::install();
$fake->setColumns('wp_rooms', [
    'id' => 'bigint(20) unsigned',
    'venue_id' => 'bigint(20) unsigned',
    'code' => 'varchar(191)',
    'label' => 'varchar(255)',
    // A MySQL type string that carries SITE VALUES inside itself.
    'status' => "enum('open','closed')",
]);
$fake->setPrimaryKey('wp_rooms', 'id');
$fake->seedTable('wp_rooms', [
    ['id' => 1, 'venue_id' => 7, 'code' => 'A1', 'label' => 'Alpha', 'status' => 'open'],
    ['id' => 2, 'venue_id' => 7, 'code' => 'B2', 'label' => 'Beta', 'status' => 'open'],
    ['id' => 3, 'venue_id' => 8, 'code' => 'C3', 'label' => 'Gamma', 'status' => 'closed'],
]);
// The EAV twin the draft's parent-only proposal would miss.
$fake->setColumns('wp_roomsmeta', [
    'meta_id' => 'bigint(20) unsigned',
    'room_id' => 'bigint(20) unsigned',
    'meta_key' => 'varchar(255)',
    'meta_value' => 'longtext',
]);
$fake->setPrimaryKey('wp_roomsmeta', 'meta_id');
$fake->seedTable('wp_roomsmeta', []);

/** SHOW INDEX rows in the server's own column names, as lock_index() reads them. */
$roomsIndex = [
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Sub_part' => null, 'Non_unique' => 0],
    ['Key_name' => 'code_unique', 'Seq_in_index' => 1, 'Column_name' => 'code', 'Sub_part' => null, 'Non_unique' => 0],
    ['Key_name' => 'label_idx', 'Seq_in_index' => 1, 'Column_name' => 'label', 'Sub_part' => 191, 'Non_unique' => 1],
    ['Key_name' => 'status_label', 'Seq_in_index' => 1, 'Column_name' => 'status', 'Sub_part' => null, 'Non_unique' => 1],
    ['Key_name' => 'status_label', 'Seq_in_index' => 2, 'Column_name' => 'label', 'Sub_part' => null, 'Non_unique' => 1],
];
$foreignKeySql = RecordedSchemaWpdb::collapse(
    "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME\n"
    . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
    . "AND TABLE_NAME = 'wp_rooms' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME"
);
$recorded = [
    'SHOW INDEX FROM `wp_rooms`' => ['rows' => $roomsIndex],
    $foreignKeySql => ['rows' => [['COLUMN_NAME' => 'venue_id', 'REFERENCED_TABLE_NAME' => 'wp_venues']]],
    'SELECT COUNT(*) AS row_count, COUNT(DISTINCT `code`) AS distinct_count FROM `wp_rooms`'
        => ['rows' => [['row_count' => '3', 'distinct_count' => '3']]],
];
$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, $recorded);

$document = AdapterProbe::report(['rooms', 'absent_table'], ['rooms' => 'code']);

duo_check_same('duo-adapter-probe/v1', $document['format'], 'the envelope names the versioned probe format');
duo_check_same(false, $document['authority'], 'the document declares authority:false in its own bytes');
duo_check_same('values_omitted', $document['redaction'], 'the document declares the value redaction');
duo_check_same(
    $document['probe_hash'],
    AdapterProbe::hash_document($document),
    'probe_hash is the canonical hash of the document minus itself'
);

$rooms = $document['tables']['rooms'];
duo_check_same(true, $rooms['present'], 'a seeded table is present');
duo_check_same(
    ['nullable' => false, 'type' => 'bigint(20) unsigned'],
    $rooms['columns']['id'],
    'the PRIMARY KEY column reports its live type and NOT NULL'
);
duo_check_same(
    ['nullable' => true, 'type' => 'varchar(191)'],
    $rooms['columns']['code'],
    'a nullable column reports its live width and nullability'
);
duo_check_same(
    'enum',
    $rooms['columns']['status']['type'],
    "enum('open','closed') is reduced to its base word: the members are SITE VALUES inside a type string"
);
duo_check(
    !str_contains(\Duo\Canon::encode($document), 'closed'),
    'no enum member reaches the document'
);

duo_check_same(['id'], $rooms['primary_key'], 'the real PRIMARY KEY is read from the index inventory, ordered');
duo_check_same(
    ['code_unique' => ['code']],
    $rooms['unique_keys'],
    'unique keys exclude PRIMARY and every non-unique index'
);

// --------------------------------------------------------------------------
echo "\n== 3. index coverage in DeleteGuardEvaluator::lock_index()'s own terms ==\n";
// --------------------------------------------------------------------------
duo_check_same(
    ['index' => 'code_unique', 'prefix' => null],
    $rooms['index_coverage']['code'],
    'a column that is the FIRST column of an index reports that covering index'
);
duo_check_same(
    ['index' => 'label_idx', 'prefix' => 191],
    $rooms['index_coverage']['label'],
    "Sub_part is carried as the prefix width lock_index() compares a key length against"
);
duo_check_same(
    ['index' => 'status_label', 'prefix' => null],
    $rooms['index_coverage']['status'],
    'a composite index covers its first column'
);
duo_check_same(
    ['index' => null, 'prefix' => null],
    $rooms['index_coverage']['venue_id'],
    'a column no index leads reports NO coverage rather than being omitted'
);
duo_check_same(
    ['index' => 'PRIMARY', 'prefix' => null],
    $rooms['index_coverage']['id'],
    'the PRIMARY KEY covers its own first column'
);
duo_check(
    !isset($rooms['index_coverage']['label']['index']) || $rooms['index_coverage']['label']['index'] !== 'status_label',
    'a column that is only a LATER part of a composite index is not reported as covered by it'
);

// --------------------------------------------------------------------------
echo "\n== 4. foreign keys, the EAV twin, and natural-key uniqueness ==\n";
// --------------------------------------------------------------------------
duo_check_same(
    ['venue_id' => 'wp_venues'],
    $rooms['foreign_keys'],
    'declared FOREIGN KEY constraints are reported as column => referenced table'
);
duo_check_same(
    [
        'key_column' => 'meta_key',
        'parent_column' => 'room_id',
        'table' => 'roomsmeta',
        'value_column' => 'meta_value',
    ],
    $rooms['eav_twin'],
    'the EAV twin is named in the draft\'s own unprefixed vocabulary, with its three columns'
);
duo_check_same(
    ['column' => 'code', 'distinct' => 3, 'rows' => 3, 'unique' => true],
    $rooms['natural_key'],
    'natural-key uniqueness is ONE COUNT(*) vs COUNT(DISTINCT col) across the whole keyspace'
);

// --------------------------------------------------------------------------
echo "\n== 5. an absent table is an ANSWER, and a non-unique key says so ==\n";
// --------------------------------------------------------------------------
duo_check_same(
    ['present' => false],
    $document['tables']['absent_table'],
    'a table this target does not have is reported present:false, never omitted'
);

$fake->setColumns('wp_tickets', ['id' => 'bigint(20) unsigned', 'slug' => 'varchar(191)']);
$fake->setPrimaryKey('wp_tickets', 'id');
$fake->seedTable('wp_tickets', [['id' => 1, 'slug' => 'a'], ['id' => 2, 'slug' => 'a']]);
$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, [
    'SHOW INDEX FROM `wp_tickets`' => ['rows' => [
        ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Sub_part' => null, 'Non_unique' => 0],
    ]],
    RecordedSchemaWpdb::collapse(
        "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME\n"
        . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
        . "AND TABLE_NAME = 'wp_tickets' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME"
    ) => ['rows' => []],
    'SELECT COUNT(*) AS row_count, COUNT(DISTINCT `slug`) AS distinct_count FROM `wp_tickets`'
        => ['rows' => [['row_count' => '5', 'distinct_count' => '4']]],
]);
$tickets = AdapterProbe::report(['tickets'], ['tickets' => 'slug'])['tables']['tickets'];
duo_check_same(
    ['column' => 'slug', 'distinct' => 4, 'rows' => 5, 'unique' => false],
    $tickets['natural_key'],
    'a keyspace with a duplicate reports unique:false — the offline guess bounded at 0.4 was wrong'
);
duo_check_same([], $tickets['unique_keys'], 'a table with only a PRIMARY KEY declares no unique key');
duo_check_same(null, $tickets['eav_twin'], 'a table with no `<table>meta` sidecar reports no twin');

// The SINGULARIZED stem, from a measured miss rather than a hypothetical.
// WPForms Lite 2.0.0.5 creates `wp_wpforms_payments` and, beside it,
// `wp_wpforms_payment_meta` (`id` PK, `payment_id`, `meta_key`, `meta_value`)
// — a textbook EAV pair the plugin models as one entity
// (`src/Db/Payments/Payment.php` and `Meta.php`). The heuristic tried only
// `<table>meta` and `<table>_meta`, so it probed `wp_wpforms_paymentsmeta`
// and `wp_wpforms_payments_meta`, found neither, and answered `eav_twin: null`
// for the guide's own worked example
// (`docs/guides/adapter-authoring.md:1016` probes exactly this table, and
// `:1008` advertises `[eav_twin]` as a question one command can answer).
// A plugin that pluralizes the parent usually does not pluralize the twin.
$fake->setColumns('wp_wpforms_payments', ['id' => 'bigint(20)', 'form_id' => 'bigint(20)']);
$fake->setPrimaryKey('wp_wpforms_payments', 'id');
$fake->seedTable('wp_wpforms_payments', []);
$fake->setColumns('wp_wpforms_payment_meta', [
    'id' => 'bigint(20)',
    'payment_id' => 'bigint(20)',
    'meta_key' => 'varchar(255)',
    'meta_value' => 'longtext',
]);
$fake->setPrimaryKey('wp_wpforms_payment_meta', 'id');
$fake->seedTable('wp_wpforms_payment_meta', []);
$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, [
    'SHOW INDEX FROM `wp_wpforms_payments`' => ['rows' => [
        ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Sub_part' => null, 'Non_unique' => 0],
    ]],
    RecordedSchemaWpdb::collapse(
        "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME\n"
        . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
        . "AND TABLE_NAME = 'wp_wpforms_payments' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME"
    ) => ['rows' => []],
]);
duo_check_same(
    [
        'key_column' => 'meta_key',
        'parent_column' => 'payment_id',
        'table' => 'wpforms_payment_meta',
        'value_column' => 'meta_value',
    ],
    AdapterProbe::report(['wpforms_payments'])['tables']['wpforms_payments']['eav_twin'],
    'a pluralized parent finds its SINGULAR-stem twin (measured: wp_wpforms_payments -> wp_wpforms_payment_meta)'
);
// The stem is a NAME guess and nothing more: a neighbour that merely matches
// the spelling still has to carry a key/value pair to be reported as a twin.
$fake->setColumns('wp_venues', ['id' => 'bigint(20)']);
$fake->setPrimaryKey('wp_venues', 'id');
$fake->seedTable('wp_venues', []);
$fake->setColumns('wp_venue_meta', ['id' => 'bigint(20)', 'venue_id' => 'bigint(20)', 'headcount' => 'int(11)']);
$fake->setPrimaryKey('wp_venue_meta', 'id');
$fake->seedTable('wp_venue_meta', []);
$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, [
    'SHOW INDEX FROM `wp_venues`' => ['rows' => [
        ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Sub_part' => null, 'Non_unique' => 0],
    ]],
    RecordedSchemaWpdb::collapse(
        "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME\n"
        . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
        . "AND TABLE_NAME = 'wp_venues' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME"
    ) => ['rows' => []],
]);
duo_check_same(
    null,
    AdapterProbe::report(['venues'])['tables']['venues']['eav_twin'],
    'a singular-stem neighbour with no key/value pair is still not a twin: the wider net invents no shape'
);

// --------------------------------------------------------------------------
echo "\n== 6. a probe can never propose a classification ==\n";
// --------------------------------------------------------------------------
$closed = ['present', 'columns', 'primary_key', 'unique_keys', 'index_coverage', 'foreign_keys', 'eav_twin', 'natural_key'];
foreach ($document['tables'] as $table => $facts) {
    duo_check_same(
        [],
        array_values(array_diff(array_keys($facts), $closed)),
        "probed table '$table' carries only the closed fact vocabulary"
    );
}
$encoded = \Duo\Canon::encode($document);
foreach (['"class"', '"identity"', '"deletions"', '"capability"', '"authored"', '"runtime"'] as $forbidden) {
    duo_check(
        !str_contains($encoded, $forbidden),
        "no $forbidden word appears anywhere in a probe document"
    );
}

// --------------------------------------------------------------------------
echo "\n== 7. loud refusals: nothing is inferred, sanitized or silently dropped ==\n";
// --------------------------------------------------------------------------
duo_check_throws(
    static fn() => AdapterProbe::report(['wp-rooms; DROP TABLE x']),
    RuntimeException::class,
    'an unsafe table name is refused, and the refusal never echoes the name',
    'outside the portable identifier grammar'
);
duo_check_throws(
    static fn() => AdapterProbe::report([]),
    RuntimeException::class,
    'a probe with no table refuses rather than emitting an empty document'
);
duo_check_throws(
    static fn() => AdapterProbe::report(array_map(static fn(int $i): string => "t$i", range(1, 65))),
    RuntimeException::class,
    'more tables than MAX_TABLES is refused rather than silently truncated',
    'refuses more than 64 tables'
);
duo_check_throws(
    static fn() => AdapterProbe::report(['tickets'], ['rooms' => 'code']),
    RuntimeException::class,
    'a natural key naming a table that was not probed is refused'
);
// The refusals above are about the AUTHOR's own arguments, so each one is a
// typed refusal whose sentence is the public answer. An untyped Throwable is
// operator evidence and `Cli::halt_json_failure()` replaces it with
// "adapter-probe refused at an unclassified safety gate"
// (`agent/src/Command/Cli.php:88-104`) — which is the right rule for a failed
// schema read and the wrong answer for a mistyped flag. Section 10 drives
// both halves through the real verb.
foreach ([
    'an unsafe table name' => static fn() => AdapterProbe::report(['wp-rooms; DROP TABLE x']),
    'no table at all' => static fn() => AdapterProbe::report([]),
    'a natural key on an unprobed table' => static fn() => AdapterProbe::report(['tickets'], ['rooms' => 'code']),
] as $label => $refused) {
    try {
        $refused();
        duo_check(false, "$label was accepted");
    } catch (Throwable $e) {
        duo_check(
            $e instanceof \Duo\CommandRefusalException && $e->reasonCode === 'invalid_arguments',
            "$label is a typed argument refusal, so its sentence survives the JSON envelope"
        );
    }
}
duo_check_throws(
    static fn() => AdapterProbe::report(['tickets'], ['rooms' => 'code']),
    RuntimeException::class,
    'and that refusal NAMES the table the author asked about but never probed',
    "natural key on 'rooms'"
);

$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, [
    'SHOW INDEX FROM `wp_tickets`' => ['error' => 'simulated index introspection failure'],
]);
duo_check_throws(
    static fn() => AdapterProbe::report(['tickets']),
    RuntimeException::class,
    'a failed index read refuses instead of inferring an unindexed table',
    'refusing to infer'
);

$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, [
    'SHOW INDEX FROM `wp_tickets`' => ['rows' => [
        ['Key_name' => 'idx`hostile', 'Seq_in_index' => 1, 'Column_name' => 'slug', 'Sub_part' => null, 'Non_unique' => 1],
    ]],
]);
duo_check_throws(
    static fn() => AdapterProbe::report(['tickets']),
    RuntimeException::class,
    'an index name outside the portable grammar refuses the table rather than reporting a rewritten name'
);

// --------------------------------------------------------------------------
echo "\n== 8. the seam: the emitter's own bytes are what adapter-draft accepts ==\n";
// --------------------------------------------------------------------------
$scratch = sys_get_temp_dir() . '/duo_regress_adapter_probe_' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);
register_shutdown_function(static function () use ($scratch): void {
    foreach (glob($scratch . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($scratch);
});
$readProbe = new ReflectionMethod(AdapterDraft::class, 'read_probe');
$write = static function (array $doc) use ($scratch): string {
    $path = $scratch . '/probe_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, \Duo\Canon::encode($doc));
    return $path;
};

$accepted = $readProbe->invoke(null, $write($document));
duo_check_same(
    ['absent_table', 'rooms'],
    array_keys($accepted['tables']),
    'the consumer accepts every table of an unmodified emitted document'
);

// The load-bearing refusal: a hand-added classification word.
$forged = $document;
$forged['tables']['rooms']['class'] = 'authored_snapshot';
$forged['probe_hash'] = AdapterProbe::hash_document($forged);
duo_check_throws(
    static fn() => $readProbe->invoke(null, $write($forged)),
    RuntimeException::class,
    'a probe document carrying a `class` is REFUSED even when its hash is recomputed',
    'outside the closed probe vocabulary'
);

$tampered = $document;
$tampered['tables']['rooms']['primary_key'] = ['code'];
duo_check_throws(
    static fn() => $readProbe->invoke(null, $write($tampered)),
    RuntimeException::class,
    'an edited fact whose probe_hash was not recomputed is refused',
    'does not describe the document'
);

$claimsAuthority = $document;
$claimsAuthority['authority'] = true;
$claimsAuthority['probe_hash'] = AdapterProbe::hash_document($claimsAuthority);
duo_check_throws(
    static fn() => $readProbe->invoke(null, $write($claimsAuthority)),
    RuntimeException::class,
    'a document claiming authority is refused by name',
    'authority:false'
);

// --------------------------------------------------------------------------
echo "\n== 9. the wp duo surface ==\n";
// --------------------------------------------------------------------------
$cli = (string) file_get_contents($repoRoot . '/agent/src/Command/Cli.php');
duo_check(str_contains($cli, '@subcommand adapter-probe'), 'the verb is declared as wp duo adapter-probe');
duo_check(
    substr_count($cli, 'public function adapter_probe(') === 1,
    'exactly one handler was added to the command surface'
);
duo_check(
    str_contains($cli, "self::halt_json_failure(\$t, \$assoc, 'adapter-probe')"),
    'the verb routes its refusals through the shared duo-command-refusal/v1 envelope'
);
duo_check(
    preg_match('/public function adapter_probe\(\$args, \$assoc\) \{\s*(?:\/\/[^\n]*\n\s*)*Journal::suspend_for_observation\(\);/', $cli) === 1,
    'the read-only verb suspends the provenance journal at ENTRY, before any argument refusal'
);
duo_check(
    str_contains((string) file_get_contents($repoRoot . '/agent/duo.php'), "require_once __DIR__ . '/src/Adapter/AdapterProbe.php';"),
    'the emitter is loaded by the drop-in bootstrap, like every one of its siblings'
);
$internals = (string) file_get_contents($repoRoot . '/docs/guides/internals.md');
duo_check(
    str_contains($internals, '`wp duo adapter-probe`'),
    'the authoring internal is named in docs/guides/internals.md (MUP §5.1)'
);

// --------------------------------------------------------------------------
echo "\n== 10. the verb, driven: the argument gate names its offender, and an\n";
echo "   unanswerable question is said out loud rather than left blank ==\n";
// --------------------------------------------------------------------------
/**
 * WP-CLI's transport, as the offline refusal suites already stub it
 * (`agent/src/Command/Cli.php:6-8` names that arrangement).
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
require_once $repoRoot . '/agent/src/Repository/Journal.php';
require_once $repoRoot . '/agent/src/Command/Cli.php';

$verb = new Duo\Cli();
$journalSuspended = new ReflectionProperty(Duo\Journal::class, 'observationSuspended');
$drive = static function (array $args, array $assoc) use ($verb, $journalSuspended): array {
    $journalSuspended->setValue(null, false);
    WP_CLI::reset();
    $thrown = null;
    try {
        $verb->adapter_probe($args, $assoc);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    return ['lines' => WP_CLI::$lines, 'thrown' => $thrown];
};

// The measured case. `wp duo coverage`, `pending`, `lint` and `adapter-observe`
// all REQUIRE --repo; adapter-probe is the one verb that rejects it, and the
// gate used to answer with a sentence about --tables syntax plus the
// remediation "name the tables one adapter draft proposes" — so an author who
// carried the flag over from the previous command was sent to re-check a
// spelling that was already correct.
$repoFlag = $drive([], ['repo' => '/srv/shop-state', 'tables' => 'rooms', 'format' => 'json']);
$repoEnvelope = json_decode(implode("\n", $repoFlag['lines']), true);
duo_check_same(
    ['duo-command-refusal/v1', 'adapter-probe', 'invalid_arguments', 'adapter-probe does not take --repo'],
    [
        $repoEnvelope['format'] ?? null,
        $repoEnvelope['command'] ?? null,
        $repoEnvelope['error'] ?? null,
        $repoEnvelope['message'] ?? null,
    ],
    'an unknown flag is NAMED, not answered with a lecture about the flags the verb does take'
);
duo_check(
    is_string($repoEnvelope['remediation'] ?? null)
        && str_contains($repoEnvelope['remediation'], 'owns no repository')
        && str_contains($repoEnvelope['remediation'], 'drop --repo'),
    'and the remediation says WHY this verb has no --repo and what to do, instead of naming tables'
);
$positional = $drive(['rooms'], ['format' => 'json']);
$positionalEnvelope = json_decode(implode("\n", $positional['lines']), true);
duo_check(
    is_string($positionalEnvelope['message'] ?? null)
        && str_contains($positionalEnvelope['message'], 'takes no positional arguments'),
    'a stray positional gets its own sentence: it was the same blanket refusal as a bad flag'
);

// An ABSENT table and an UNANSWERABLE natural key, through the human summary
// the author actually reads. `wpforms_payments` is seeded here exactly as a
// freshly activated WPForms Lite 2.0.0.5 leaves it: the table exists (created
// at activation) and holds no rows, so `COUNT(*) = 0` and the document's
// `unique: false` is not a measurement of the key at all — which is what the
// guide's own worked example (`docs/guides/adapter-authoring.md:1016`) returns
// on a fresh install.
$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, [
    'SHOW INDEX FROM `wp_wpforms_payments`' => ['rows' => [
        ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Sub_part' => null, 'Non_unique' => 0],
    ]],
    RecordedSchemaWpdb::collapse(
        "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME\n"
        . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
        . "AND TABLE_NAME = 'wp_wpforms_payments' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME"
    ) => ['rows' => []],
    'SELECT COUNT(*) AS row_count, COUNT(DISTINCT `form_id`) AS distinct_count FROM `wp_wpforms_payments`'
        => ['rows' => [['row_count' => '0', 'distinct_count' => '0']]],
]);
$fresh = $drive([], [
    'tables' => 'wpforms_payments,wpforms_entries',
    'natural-keys' => 'wpforms_payments.form_id',
]);
$human = implode("\n", $fresh['lines']);
duo_check(
    str_contains($human, 'wpforms_entries: absent on this target — no such table exists')
        && str_contains($human, 'name, prefix or not-yet-activated question')
        && str_contains($human, 'an activated plugin already has its tables (with no rows)'),
    'an absent table says what absent MEANS — activation creates tables, so this is never a "no rows yet" answer'
);
duo_check(
    str_contains($human, 'natural key wpforms_payments.form_id: 0 row(s) — nothing to measure yet')
        && str_contains($human, 'creates its tables EMPTY, so exercise the plugin before reading this as uniqueness'),
    'a natural key over an EMPTY keyspace reports that it measured nothing, rather than reading as "not unique"'
);
// Back to section 2's recording, where `rooms` really does hold three rows.
$GLOBALS['wpdb'] = new RecordedSchemaWpdb($fake, $recorded);
$measured = $drive([], ['tables' => 'rooms', 'natural-keys' => 'rooms.code']);
duo_check(
    str_contains(
        implode("\n", $measured['lines']),
        'natural key rooms.code: 3 row(s), 3 distinct — unique across the keyspace'
    ),
    'and a keyspace with rows still reports the measurement itself, counts and all'
);
$unprobed = $drive([], ['tables' => 'rooms', 'natural-keys' => 'tickets.slug', 'format' => 'json']);
$unprobedEnvelope = json_decode(implode("\n", $unprobed['lines']), true);
duo_check_same(
    ['invalid_arguments', null],
    [$unprobedEnvelope['error'] ?? null, $unprobedEnvelope['details_redacted'] ?? null],
    'a natural key on a table this run does not probe is a named argument refusal, not the redacted catch-all'
);
duo_check(
    is_string($unprobedEnvelope['message'] ?? null)
        && str_contains($unprobedEnvelope['message'], "natural key on 'tickets'"),
    'and it names the table the author asked about'
);
duo_check_same(
    true,
    $journalSuspended->getValue(),
    'every one of these paths suspended the journal first: asking a target for its schema is never a Duo INSERT'
);

duo_check_summary('adapter probe (duo-adapter-probe/v1): the live half of adapter-draft\'s --evidence= seam');
