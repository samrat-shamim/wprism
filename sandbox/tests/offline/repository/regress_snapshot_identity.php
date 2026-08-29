<?php
/**
 * Offline certification for issue #3349's extracted typed-table identity seam.
 *
 * The boundary is loaded and executed before any other engine class. Injected
 * fakes pin mapped/natural/composite derivation, strict read-only identity,
 * packed tuple bookkeeping, recursive adoption collision lookup, and the thin
 * Snapshot compatibility adapter.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../../../../agent/src/Repository/SnapshotIdentity.php';

use WPrism\Canon;
use WPrism\Ledger;
use WPrism\Policy;
use WPrism\Snapshot;
use WPrism\SnapshotIdentity;
use WPrism\Tokens;
use WPrism\Uuid;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};
$throws = static function (callable $run, string $fragment, string $message) use ($check): void {
    try {
        $run();
        $check(false, $message);
    } catch (\Throwable $failure) {
        $check(str_contains($failure->getMessage(), $fragment), $message);
    }
};

$check(class_exists(SnapshotIdentity::class, false), 'SnapshotIdentity loads as a direct offline boundary');
foreach ([Snapshot::class, Policy::class, Ledger::class, Tokens::class, Canon::class, Uuid::class] as $runtimeClass) {
    $check(!class_exists($runtimeClass, false), "SnapshotIdentity does not pull in $runtimeClass");
}

final class SnapshotIdentityFixture {
    /** @var array<string,array> */
    public array $rowTables = [];
    /** @var array<string,list<array<string,mixed>>> */
    public array $liveRows = [];
    /** @var array<string,string> */
    public array $ledgerUuids = [];
    /** @var array<string,int> */
    public array $ledgerIds = [];
    /** @var list<array{string,string,string,int}> */
    public array $sets = [];
    /** @var list<array{string,string,string,int,string}> */
    public array $readOnlyChecks = [];
    /** @var list<array{string,string}> */
    public array $idLookups = [];
    /** @var list<array{string,string,array,array}> */
    public array $queries = [];
    public int $v7Calls = 0;

    /** @return list<string> */
    private static function naturalColumns(array $decl): array {
        $identity = is_array($decl['identity'] ?? null) ? $decl['identity'] : [];
        if (($identity['mode'] ?? 'mapped') !== 'natural_key') {
            return [];
        }
        if (is_string($identity['column'] ?? null) && $identity['column'] !== '') {
            return [$identity['column']];
        }
        return array_values((array) ($identity['columns'] ?? []));
    }

    public function boundary(): SnapshotIdentity {
        return new SnapshotIdentity(
            static fn(array $decl): array => self::naturalColumns($decl),
            fn(): array => $this->rowTables,
            fn(int $localId, string $kind): ?string => $this->ledgerUuids["$kind|$localId"] ?? null,
            function (string $uuid, string $kind): ?int {
                $this->idLookups[] = [$uuid, $kind];
                $ledgerKind = $kind === 'tt' ? 'term_taxonomy' : $kind;
                return $this->ledgerIds["$ledgerKind|$uuid"] ?? null;
            },
            function (string $uuid, string $table, string $kind, int $localId): void {
                $this->sets[] = [$uuid, $table, $kind, $localId];
            },
            function (string $uuid, string $table, string $kind, int $localId, string $context): void {
                $this->readOnlyChecks[] = [$uuid, $table, $kind, $localId, $context];
            },
            static fn(string $name): string => "v5<$name>",
            function (): string {
                $this->v7Calls++;
                return '01990000-0000-7000-8000-000000000001';
            },
            static fn(string $content): array => json_decode($content, true, 512, JSON_THROW_ON_ERROR),
            function (string $table, string $pk, array $predicates, array $args): ?int {
                $this->queries[] = [$table, $pk, $predicates, $args];
                foreach ($this->liveRows[$table] ?? [] as $row) {
                    $matched = true;
                    foreach ($predicates as $index => $predicate) {
                        if (!preg_match('/^`([^`]+)` = %[ds]$/', $predicate, $parts)
                            || (string) ($row[$parts[1]] ?? '') !== (string) ($args[$index] ?? '')) {
                            $matched = false;
                            break;
                        }
                    }
                    if ($matched) {
                        return isset($row[$pk]) ? (int) $row[$pk] : null;
                    }
                }
                return null;
            }
        );
    }
}

final class SnapshotIdentityTokens {
    /** @param array<string,array<int,string>> $uuids */
    public function __construct(public array $uuids) {}

    public function id_to_token(int $id, string $kind): ?string {
        $uuid = $this->uuids[$kind][$id] ?? null;
        return $uuid === null ? null : '{{' . $kind . ':' . $uuid . '}}';
    }
}

$roomUuid = '11111111-1111-5111-8111-111111111111';
$otherUuid = '22222222-2222-5222-8222-222222222222';
$roomDecl = [
    'class' => 'authored_snapshot',
    'id_kind' => 'acme_room',
    'pk' => 'room_id',
    'refs' => [],
    'identity' => ['mode' => 'natural_key', 'column' => 'room_code'],
];
$slotDecl = [
    'class' => 'authored_snapshot',
    'id_kind' => 'acme_slot',
    'pk' => 'slot_id',
    'refs' => [['column' => 'room_id', 'kind' => 'acme_room']],
    'identity' => ['mode' => 'natural_key', 'columns' => ['room_id', 'slot_code']],
];
$mappedDecl = [
    'class' => 'authored_snapshot',
    'id_kind' => 'mapped_row',
    'pk' => 'id',
    'refs' => [],
    'identity' => ['mode' => 'mapped'],
];
$joinDecl = [
    'class' => 'authored_snapshot',
    'id_kind' => 'join_row',
    'refs' => [
        ['column' => 'left_id', 'kind' => 'left'],
        ['column' => 'right_id', 'kind' => 'right'],
    ],
    'identity' => ['mode' => 'composite_ref', 'columns' => ['left_id', 'right_id']],
];

$fixture = new SnapshotIdentityFixture();
$fixture->rowTables = [
    'acme_rooms' => $roomDecl,
    'acme_slots' => $slotDecl,
    'mapped_rows' => $mappedDecl,
    'join_rows' => $joinDecl,
];
$identity = $fixture->boundary();
$tokens = new SnapshotIdentityTokens([
    'acme_room' => [3 => $roomUuid],
    'left' => [7 => $roomUuid],
    'right' => [9 => $otherUuid],
]);

$check(
    $identity->naturalKeyName('acme_rooms', $roomDecl, ['room_code' => 'studio']) === 'acme_rooms:studio',
    'single-column natural identity preserves its frozen UUIDv5 name'
);
$components = $identity->naturalKeyComponentsFromFront($slotDecl, [
    'room_id' => "{{acme_room:$roomUuid}}",
    'slot_code' => 'morning',
]);
$check($components === ['room_id' => $roomUuid, 'slot_code' => 'morning'],
    'captured tuple identity extracts a ref UUID and scalar in declaration order');
$check(
    $identity->naturalKeyName('acme_slots', $slotDecl, $components ?? [])
        === "acme_slots:room_id=$roomUuid:slot_code=morning",
    'tuple natural identity preserves its ordered unambiguous UUIDv5 name'
);
$check($identity->naturalKeyComponentsFromFront($slotDecl, ['room_id' => '{{acme_room:broken}}']) === null,
    'incomplete or malformed informational front matter derives no identity');

$fixture->ledgerUuids['mapped_row|7'] = $otherUuid;
$check($identity->identifyRow('mapped_rows', $mappedDecl, ['id' => 7], 7, $tokens, false) === $otherUuid,
    'an existing mapped identity is reused without minting');
$check($fixture->sets === [[$otherUuid, 'mapped_rows', 'mapped_row', 7]] && $fixture->v7Calls === 0,
    'ordinary reuse reaffirms the exact ledger tuple and never calls UUIDv7');
$fixture->sets = [];
$check($identity->identifyRow('mapped_rows', $mappedDecl, ['id' => 7], 7, $tokens, false, true) === $otherUuid,
    'strict read-only export returns the existing continuity identity');
$check($fixture->readOnlyChecks === [[$otherUuid, 'mapped_rows', 'mapped_row', 7, "table 'mapped_rows' row 7"]]
    && $fixture->sets === [],
    'strict read-only export proves the bidirectional mapping without writing it');
$throws(
    fn() => $identity->identifyRow('mapped_rows', $mappedDecl, ['id' => 8], 8, $tokens, true, true),
    'refresh export refused — mapped identity missing',
    'strict read-only export refuses a missing map even when minting was requested'
);
$throws(
    fn() => $identity->identifyRow('mapped_rows', $mappedDecl, ['id' => 8], 8, $tokens, false),
    'refusing to create or rebind it',
    'non-minting mapped capture refuses to invent identity'
);
$fixture->sets = [];
$minted = $identity->identifyRow('mapped_rows', $mappedDecl, ['id' => 8], 8, $tokens, true);
$check($minted === '01990000-0000-7000-8000-000000000001' && $fixture->v7Calls === 1,
    'minting mapped capture obtains exactly one injected UUIDv7');
$check($fixture->sets === [[$minted, 'mapped_rows', 'mapped_row', 8]],
    'new mapped identity is persisted through the injected ledger writer');

$fixture->sets = [];
$derived = $identity->identifyRow(
    'acme_slots',
    $slotDecl,
    ['slot_id' => 4, 'room_id' => 3, 'slot_code' => 'morning'],
    4,
    $tokens,
    false
);
$check($derived === "v5<acme_slots:room_id=$roomUuid:slot_code=morning>",
    'natural identity derives from the referenced row UUID, never its local id');
$check($fixture->sets === [[$derived, 'acme_slots', 'acme_slot', 4]],
    'derived natural identity is recorded in the ordinary ledger tuple');
$throws(
    fn() => $identity->identifyRow(
        'acme_slots',
        $slotDecl,
        ['slot_id' => 5, 'room_id' => 99, 'slot_code' => 'morning'],
        5,
        $tokens,
        true
    ),
    'capture scope must include that row',
    'an unmanaged ref component refuses natural identity derivation'
);

[$joinUuid, $joinTokens, $joinLocals] = $identity->identifyCompositeRow(
    'join_rows',
    $joinDecl,
    ['left_id' => 7, 'right_id' => 9],
    $tokens
);
$check($joinUuid === "v5<join_rows:left_id=$roomUuid:right_id=$otherUuid>",
    'composite identity derives from both referenced UUIDs');
$check($joinTokens === ['left_id' => "{{left:$roomUuid}}", 'right_id' => "{{right:$otherUuid}}"]
    && $joinLocals === ['left_id' => 7, 'right_id' => 9],
    'composite derivation returns portable tokens beside exact local bookkeeping values');
$packed = $identity->packCompositeId('join_rows', $joinLocals);
$check($identity->unpackCompositeId($packed) === [7, 9],
    'composite local ids round-trip through the positive 31-bit tuple encoding');
$throws(
    fn() => $identity->packCompositeId('join_rows', ['left_id' => 1 << 31, 'right_id' => 9]),
    'out-of-budget component(s) (left_id=2147483648)',
    'packed composite identity refuses an overflowing component with tuple context'
);
$throws(
    fn() => $identity->identifyCompositeRow(
        'join_rows',
        $joinDecl,
        ['left_id' => 7, 'right_id' => 99],
        $tokens
    ),
    'unmanaged right ref 99',
    'composite identity refuses an unresolved structural reference'
);

$fixture->liveRows = [
    'acme_rooms' => [['room_id' => 12, 'room_code' => 'studio']],
    'acme_slots' => [['slot_id' => 4, 'room_id' => 12, 'slot_code' => 'morning']],
];
$roomEntity = [
    'type' => 'acme_rooms',
    'data' => ['uuid' => $roomUuid, 'columns' => ['room_code' => 'studio']],
];
$slotUuid = '33333333-3333-5333-8333-333333333333';
$slotEntity = [
    'type' => 'acme_slots',
    'data' => [
        'uuid' => $slotUuid,
        'columns' => ['room_id' => "{{acme_room:$roomUuid}}", 'slot_code' => 'morning'],
    ],
];
$tree = [$roomUuid => $roomEntity, $slotUuid => $slotEntity];
$cache = [];
$check($identity->findCollision($slotEntity, $tree, $cache) === 4,
    'a parent-scoped collision recursively adopts its still-unmapped natural-key parent');
$check(($cache[$roomUuid] ?? null) === 12,
    'recursive parent collision resolution is memoized by portable UUID');
$check(count($fixture->queries) === 2
    && $fixture->queries[0][0] === 'acme_rooms'
    && $fixture->queries[1][3] === [12, 'morning'],
    'collision lookup queries parent then child with target-local values');

$fixture->queries = [];
$customKinds = [];
$customCache = [];
$check($identity->findCollision(
    $slotEntity,
    [],
    $customCache,
    [],
    static function (string $uuid, string $kind) use (&$customKinds, $roomUuid): ?int {
        $customKinds[] = [$uuid, $kind];
        return $uuid === $roomUuid && $kind === 'acme_room' ? 12 : null;
    }
) === 4, 'a planner-owned resolver can supply the parent mapping without recursion');
$check($customKinds === [[$roomUuid, 'acme_room']],
    'the optional resolver receives the manifest ref kind unchanged');
$mappedCache = [];
$check($identity->findCollision(['type' => 'mapped_rows', 'data' => ['columns' => []]], [], $mappedCache) === null,
    'mapped and composite rows have no natural-key collision query');
$throws(
    fn() => $identity->uuidFromToken('{{acme_room:not-a-uuid}}'),
    'composite_ref/natural_key identity derivation',
    'decision paths refuse malformed identity tokens with the stable attribution'
);

$fixture->sets = [];
$identity->adopt($slotUuid, 'acme_slots', 44);
$check($fixture->sets === [[$slotUuid, 'acme_slots', 'acme_slot', 44]],
    'adoption writes only the declared table identity tuple');

require_once __DIR__ . '/../../../../agent/src/Repository/Snapshot.php';
$snapshotLines = file(__DIR__ . '/../../../../agent/src/Repository/Snapshot.php');
$methodSource = static function (string $name) use ($snapshotLines): string {
    $method = new \ReflectionMethod(Snapshot::class, $name);
    return implode('', array_slice(
        $snapshotLines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
};
$delegates = [
    'natural_key_name' => 'snapshot_identity()->naturalKeyName',
    'natural_key_components_from_front' => 'snapshot_identity()->naturalKeyComponentsFromFront',
    'identify_row' => 'snapshot_identity()->identifyRow',
    'identify_composite_row' => 'snapshot_identity()->identifyCompositeRow',
    'uuid_from_token' => 'snapshot_identity()->uuidFromToken',
    'pack_composite_id' => 'snapshot_identity()->packCompositeId',
    'unpack_composite_id' => 'snapshot_identity()->unpackCompositeId',
    'find_collision' => 'snapshot_identity($policy)->findCollision',
    'adopt' => 'snapshot_identity($policy)->adopt',
];
foreach ($delegates as $method => $call) {
    $source = $methodSource($method);
    $check(str_contains($source, $call) && !str_contains($source, 'foreach'),
        "Snapshot::$method remains a thin SnapshotIdentity compatibility facade");
}
$factorySource = $methodSource('snapshot_identity');
foreach (['Policy::natural_key_columns', 'Ledger::uuid_for', 'Ledger::id_for', 'Tokens::ledger_kind',
    'Ledger::set', 'Ledger::require_read_only_mapping', 'Uuid::v5', 'Uuid::v7', 'Canon::decode'] as $capability) {
    $check(str_contains($factorySource, $capability),
        "Snapshot identity adapter injects $capability explicitly");
}

$snapshotSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/Snapshot.php');
$identitySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/SnapshotIdentity.php');
$check(str_contains($snapshotSource, "require_once __DIR__ . '/SnapshotIdentity.php';"),
    'Snapshot directly requires its identity collaborator');
$check(!str_contains($identitySource, 'require_once') && !str_contains($identitySource, 'global $wpdb'),
    'SnapshotIdentity has no hidden load-order or database dependency');
$check(!preg_match('/\b(?:Snapshot|Policy|Ledger|Tokens|Canon|Uuid)::/', $identitySource),
    'SnapshotIdentity depends only on injected engine capabilities');

require_once __DIR__ . '/../../../../agent/src/Kernel/Uuid.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Identity.php';

final class EmbeddedIdentityWpdbFixture {
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';
    public string $termmeta = 'wp_termmeta';
    public string $terms = 'wp_terms';
    public string $last_error = '';
    /** @var array<string,list<array{meta_id:int,owner_id:int,meta_key:string,meta_value:string}>> */
    public array $meta = ['post' => [], 'term' => []];
    /** @var array<string,list<int>> */
    public array $owners = ['post' => [], 'term' => []];
    public ?string $failureKind = null;
    public ?string $saturatedKind = null;
    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $sql, mixed ...$args): string {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
        }
        return $sql;
    }

    public function get_results(string $sql, mixed $mode): mixed {
        if ($mode !== ARRAY_A) {
            throw new RuntimeException('embedded identity fixture expected ARRAY_A');
        }
        $this->queries[] = $sql;
        $kind = str_contains($sql, '`wp_postmeta`') ? 'post' : 'term';
        if ($this->failureKind === $kind) {
            $this->last_error = 'simulated embedded identity read failure';
            return false;
        }
        if ($this->saturatedKind === $kind) {
            return array_fill(0, 100001, [
                'local_id' => '1',
                'uuid' => '11111111-1111-4111-8111-111111111111',
                'uuid_bytes' => '36',
            ]);
        }
        $rows = array_values(array_filter(
            $this->meta[$kind],
            static fn(array $row): bool => $row['meta_key'] === '_wprism_uuid'
        ));
        usort($rows, static fn(array $left, array $right): int =>
            [$left['owner_id'], $left['meta_id']] <=> [$right['owner_id'], $right['meta_id']]);
        return array_map(static fn(array $row): array => [
            'local_id' => (string) $row['owner_id'],
            'uuid' => substr($row['meta_value'], 0, 37),
            'uuid_bytes' => (string) strlen($row['meta_value']),
        ], $rows);
    }

    public function get_col(string $sql): array {
        $this->queries[] = $sql;
        $kind = str_contains($sql, '`wp_posts`') ? 'post' : 'term';
        preg_match('/ IN \(([^)]*)\)/', $sql, $match);
        $requested = array_map('intval', explode(',', (string) ($match[1] ?? '')));
        $live = array_values(array_intersect($this->owners[$kind], $requested));
        sort($live, SORT_NUMERIC);
        return array_map('strval', $live);
    }
}

$embeddedWpdb = new EmbeddedIdentityWpdbFixture();
$embeddedWpdb->owners = ['post' => [12], 'term' => [20]];
$embeddedWpdb->meta = [
    'post' => [[
        'meta_id' => 1,
        'owner_id' => 12,
        'meta_key' => '_wprism_uuid',
        'meta_value' => '11111111-1111-4111-8111-111111111111',
    ]],
    'term' => [
        [
            'meta_id' => 2,
            'owner_id' => 20,
            'meta_key' => '_WPRISM_UUID',
            'meta_value' => '11111111-1111-4111-8111-111111111111',
        ],
        [
            'meta_id' => 3,
            'owner_id' => 99,
            'meta_key' => '_wprism_uuid',
            'meta_value' => '11111111-1111-4111-8111-111111111111',
        ],
    ],
];
$GLOBALS['wpdb'] = $embeddedWpdb;
\WPrism\Identity::assert_embedded_unique();
$check(
    count($embeddedWpdb->queries) === 4
        && !array_filter(
            array_filter(
                $embeddedWpdb->queries,
                static fn(string $sql): bool => str_contains($sql, 'meta_value')
            ),
            static fn(string $sql): bool => !str_contains($sql, "BINARY meta_key = BINARY '_wprism_uuid'")
                || !str_contains($sql, 'LEFT(meta_value, 37)')
                || !str_contains($sql, 'LIMIT 100001')
        )
        && !array_filter(
            array_filter(
                $embeddedWpdb->queries,
                static fn(string $sql): bool => str_contains($sql, ' WHERE `') && str_contains($sql, ' IN (')
            ),
            static fn(string $sql): bool => !str_contains($sql, 'LIMIT 501')
        ),
    'embedded identity reads exclude aliases at source and filter orphans through bounded checked owner chunks'
);

$embeddedWpdb->failureKind = 'post';
$throws(
    static fn() => \WPrism\Identity::assert_embedded_unique(),
    'simulated embedded identity read failure',
    'embedded identity DB failure never becomes an empty identity estate'
);
$embeddedWpdb->failureKind = null;
$embeddedWpdb->last_error = '';
$embeddedWpdb->saturatedKind = 'post';
$throws(
    static fn() => \WPrism\Identity::assert_embedded_unique(),
    'bounded row limit exceeded',
    'embedded identity validation refuses its high-cardinality frontier before building an unbounded owner list'
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " snapshot-identity regression(s) failed\n");
    exit(1);
}

echo "ALL PASSED\n";
