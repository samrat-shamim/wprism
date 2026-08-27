<?php
/**
 * Direct offline certification for DUO-3349's TypedTableCapture boundary.
 *
 * The class is loaded before Snapshot or any runtime service. A wpdb-shaped
 * reader plus injected identity/ledger capabilities exercise ordinary rows,
 * attached meta, composite rows, strict read-only mapping proof, refusal
 * paths, stable filenames, and Snapshot's thin compatibility adapter.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../../../../agent/src/Capture/TypedTableCapture.php';

use Duo\Canon;
use Duo\IdentityNotes;
use Duo\Ledger;
use Duo\Policy;
use Duo\Snapshot;
use Duo\SnapshotIdentity;
use Duo\Tokens;
use Duo\TypedTableCapture;
use Duo\Uuid;

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

$check(class_exists(TypedTableCapture::class, false), 'TypedTableCapture loads as a direct offline boundary');
foreach ([Snapshot::class, Policy::class, Ledger::class, IdentityNotes::class, Tokens::class,
    SnapshotIdentity::class, Uuid::class] as $runtimeClass) {
    $check(!class_exists($runtimeClass, false), "TypedTableCapture does not pull in $runtimeClass");
}

final class TypedCaptureWpdb {
    public string $prefix = 'wp_';
    /** @var array<string,list<array<string,mixed>>> */
    public array $tables = [];
    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $sql, ...$args): array {
        return ['sql' => $sql, 'args' => $args];
    }

    /** @return list<array<string,mixed>> */
    public function get_results(string|array $query, string $format): array {
        $sql = is_array($query) ? $query['sql'] : $query;
        $args = is_array($query) ? $query['args'] : [];
        $this->queries[] = $sql;
        if (!preg_match('/FROM `wp_([^`]+)`/', $sql, $match)) {
            throw new \RuntimeException("unexpected fixture query: $sql");
        }
        $rows = $this->tables[$match[1]] ?? [];
        if (str_contains($sql, ' AS k, ') && str_contains($sql, ' AS v ')) {
            preg_match('/SELECT `([^`]+)` AS k, `([^`]+)` AS v/', $sql, $aliases);
            preg_match('/WHERE `([^`]+)` = %d/', $sql, $ownerMatch);
            preg_match('/ORDER BY `([^`]+)` ASC/', $sql, $orderMatch);
            $owner = (int) ($args[0] ?? 0);
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (int) ($row[$ownerMatch[1]] ?? 0) === $owner
            ));
            usort($rows, static fn(array $a, array $b): int =>
                ((int) $a[$orderMatch[1]]) <=> ((int) $b[$orderMatch[1]]));
            return array_map(static fn(array $row): array => [
                'k' => $row[$aliases[1]],
                'v' => $row[$aliases[2]],
            ], $rows);
        }
        if (preg_match('/ORDER BY (.+) ASC/', $sql, $orderMatch)) {
            preg_match_all('/`([^`]+)`/', $orderMatch[1], $columns);
            usort($rows, static function (array $a, array $b) use ($columns): int {
                foreach ($columns[1] as $column) {
                    $cmp = ($a[$column] ?? null) <=> ($b[$column] ?? null);
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }
                return 0;
            });
        }
        return $rows;
    }
}

final class TypedCaptureTokens {
    /** @var list<string> */
    public array $notes = [];
    /** @var list<string> */
    public array $warnings = [];

    /** @param array<string,array<int,string>> $ids */
    public function __construct(public array $ids) {}

    public function tokenize_text(string $value): string {
        return 'text<' . $value . '>';
    }

    public function id_to_token(int $id, string $kind): ?string {
        $uuid = $this->ids[$kind][$id] ?? null;
        return $uuid === null ? null : '{{' . $kind . ':' . $uuid . '}}';
    }

    public function struct_capture($value, array $jsonRefs, ?array $keyRefs) {
        if (!is_array($value)) {
            return $value;
        }
        $kind = (string) ($jsonRefs[0]['kind'] ?? 'post');
        $out = [];
        foreach ($value as $key => $localId) {
            $out[$key] = $this->id_to_token((int) $localId, $kind);
        }
        return $out;
    }

    public function plain_data_capture($value) {
        if (is_string($value)) {
            return $this->tokenize_text($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as &$child) {
            $child = $this->plain_data_capture($child);
        }
        unset($child);
        return $value;
    }
}

final class TypedCaptureIdentity {
    /** @var list<array> */
    public array $ordinary = [];
    /** @var list<array> */
    public array $composite = [];

    public function identifyRow(
        string $table,
        array $decl,
        array $row,
        int $localId,
        object $tokens,
        bool $mint,
        bool $strictReadOnly
    ): string {
        $this->ordinary[] = [$table, $localId, $mint, $strictReadOnly];
        return $localId === 7
            ? '11111111-1111-5111-8111-111111111111'
            : '22222222-2222-5222-8222-222222222222';
    }

    public function identifyCompositeRow(string $table, array $decl, array $row, object $tokens): array {
        $this->composite[] = [$table, $row];
        $tokensByCol = [];
        $localByCol = [];
        foreach ($decl['identity']['columns'] as $column) {
            $ref = array_values(array_filter(
                $decl['refs'],
                static fn(array $candidate): bool => $candidate['column'] === $column
            ))[0];
            $localByCol[$column] = (int) $row[$column];
            $tokensByCol[$column] = $tokens->id_to_token((int) $row[$column], $ref['kind']);
        }
        return ['cccccccc-cccc-5ccc-8ccc-cccccccccccc', $tokensByCol, $localByCol];
    }

    public function packCompositeId(string $table, array $localByCol): int {
        return ((int) array_values($localByCol)[0] << 31) | (int) array_values($localByCol)[1];
    }

    public function uuidFromToken(string $token): string {
        if (!preg_match('/\{\{[a-z][a-z0-9_]*:([^}]+)\}\}/', $token, $match)) {
            throw new \RuntimeException('bad fixture token');
        }
        return $match[1];
    }
}

$wpdb = new TypedCaptureWpdb();
$identity = new TypedCaptureIdentity();
$mappingCalls = [];
$capture = new TypedTableCapture(
    $identity,
    static function (...$args) use (&$mappingCalls): void {
        $mappingCalls[] = $args;
    },
    static fn(string $uuid, string $table, array $decl, array $columns): ?string =>
        ($decl['identity']['mode'] ?? 'mapped') === 'natural_key' ? "identity:$table:$uuid" : null,
    static fn(string $raw): string => strtolower(str_replace(' ', '-', trim($raw)))
);

$post3 = '33333333-3333-5333-8333-333333333333';
$post8 = '88888888-8888-5888-8888-888888888888';
$post4 = '44444444-4444-5444-8444-444444444444';
$post5 = '55555555-5555-5555-8555-555555555555';
$leftUuid = 'aaaaaaaa-aaaa-5aaa-8aaa-aaaaaaaaaaaa';
$rightUuid = 'bbbbbbbb-bbbb-5bbb-8bbb-bbbbbbbbbbbb';
$tokens = new TypedCaptureTokens([
    'post' => [3 => $post3, 4 => $post4, 5 => $post5, 8 => $post8],
    'left' => [7 => $leftUuid],
    'right' => [9 => $rightUuid],
]);

$rowDecl = [
    'class' => 'authored_snapshot',
    'pk' => 'id',
    'id_kind' => 'widget',
    'slug_column' => 'label',
    'identity' => ['mode' => 'natural_key', 'column' => 'label'],
    'columns' => [
        'label' => ['class' => 'authored'],
        'body' => ['class' => 'authored'],
        'runtime_clock' => ['class' => 'runtime'],
        'allowed_key' => ['class' => 'authored', 'allow_secret' => true],
    ],
    'refs' => [['column' => 'parent_id', 'kind' => 'post']],
];
$metaDecl = [
    'class' => 'authored_snapshot_meta',
    'attached_to' => ['table' => 'widgets', 'column' => 'owner_id'],
    'id_column' => 'meta_id',
    'key_column' => 'meta_key',
    'value_column' => 'meta_value',
    'default_class' => 'authored',
    'keys' => [
        'runtime_flag' => ['class' => 'runtime'],
        'linked' => ['class' => 'authored', 'ref' => 'post'],
        'dangling' => ['class' => 'authored', 'ref' => 'post'],
        'empty_ref' => ['class' => 'authored', 'ref' => 'post'],
        'config' => [
            'class' => 'authored',
            'json_refs' => [['path' => '$.*', 'kind' => 'post']],
            'json_encoded' => true,
            'order_preserving' => true,
        ],
        'portable_data' => ['class' => 'authored', 'plain_data' => true],
    ],
];
$wpdb->tables['widgets'] = [[
    'id' => 7,
    'label' => 'Hello World',
    'body' => 'Visit https://source.test',
    'runtime_clock' => '2026-08-13T00:00:00Z',
    'allowed_key' => 'sk_live_ABCDEFGHIJKLMNOPQRSTUV',
    'parent_id' => 3,
]];
$wpdb->tables['widget_meta'] = [
    ['meta_id' => 6, 'owner_id' => 7, 'meta_key' => 'config', 'meta_value' => '{"z":4,"a":5}'],
    ['meta_id' => 1, 'owner_id' => 7, 'meta_key' => 'plain', 'meta_value' => 'hello'],
    ['meta_id' => 2, 'owner_id' => 7, 'meta_key' => 'runtime_flag', 'meta_value' => 'scratch'],
    ['meta_id' => 3, 'owner_id' => 7, 'meta_key' => 'linked', 'meta_value' => '8'],
    ['meta_id' => 4, 'owner_id' => 7, 'meta_key' => 'dangling', 'meta_value' => '99'],
    ['meta_id' => 5, 'owner_id' => 7, 'meta_key' => 'empty_ref', 'meta_value' => '0'],
    [
        'meta_id' => 7,
        'owner_id' => 7,
        'meta_key' => 'portable_data',
        'meta_value' => serialize([
            'url' => 'https://source.test/path?field=1',
            'choices' => [['label' => 'Tokyo', 'selected' => false]],
        ]),
    ],
];

$entities = $capture->capture_table('widgets', $rowDecl, ['widget_meta' => $metaDecl], $tokens, true);
$check(count($entities) === 1, 'ordinary table capture emits one Capture-compatible entity');
$entity = $entities[0] ?? [];
$front = Canon::decode((string) ($entity['content'] ?? ''));
$check(($entity['type'] ?? null) === 'widgets'
    && ($entity['path'] ?? null) === 'tables/widgets/11111111-1111-5111-8111-111111111111--hello-world.json',
    'ordinary entity keeps the table type and portable authored slug');
$check(($front['columns']['label'] ?? null) === 'text<Hello World>'
    && ($front['columns']['body'] ?? null) === 'text<Visit https://source.test>'
    && ($front['columns']['parent_id'] ?? null) === "{{post:$post3}}"
    && ($front['columns']['allowed_key'] ?? null) === 'text<sk_live_ABCDEFGHIJKLMNOPQRSTUV>'
    && !array_key_exists('runtime_clock', $front['columns'] ?? []),
    'authored columns are tokenized, refs are portable, and runtime columns are excluded');
$check(($front['meta']['plain'] ?? null) === 'text<hello>'
    && ($front['meta']['linked'] ?? null) === "{{post:$post8}}"
    && array_key_exists('empty_ref', $front['meta'] ?? [])
    && $front['meta']['empty_ref'] === null
    && !array_key_exists('runtime_flag', $front['meta'] ?? [])
    && !array_key_exists('dangling', $front['meta'] ?? []),
    'attached meta preserves authored scalar/ref/null semantics and excludes runtime or dangling values');
$check(($front['meta']['config']['z'] ?? null) === "{{post:$post4}}"
    && ($front['meta']['config']['a'] ?? null) === "{{post:$post5}}",
    'structured attached meta runs through the shared plain-data and reference codec path');
$check(($front['meta']['portable_data']['url'] ?? null) === 'text<https://source.test/path?field=1>'
    && ($front['meta']['portable_data']['choices'][0]['label'] ?? null) === 'text<Tokyo>'
    && ($front['meta']['portable_data']['choices'][0]['selected'] ?? null) === false,
    'serialized attached meta is decoded before nested string leaves are tokenized');
$check(!str_contains((string) ($entity['content'] ?? ''), 'a:2:{s:3:"url"'),
    'canonical attached plain data never carries fragile PHP serialization byte counts');
$content = (string) ($entity['content'] ?? '');
$configOffset = strpos($content, '"config"');
$check($configOffset !== false
    && strpos($content, '"z"', $configOffset) < strpos($content, '"a"', $configOffset),
    'order-preserving structured meta survives canonical encoding in declared value order');
$check($tokens->notes === ['identity:widgets:11111111-1111-5111-8111-111111111111'],
    'natural-key continuity notes are emitted exactly once through the injected capability');
$check(count($tokens->warnings) === 1 && str_contains($tokens->warnings[0], 'post id 99 dropped'),
    'an optional dangling attached-meta ref drops with one attributed warning');
$check($identity->ordinary === [['widgets', 7, true, false]],
    'ordinary identity receives the exact mint and strict-read-only modes');
$check(in_array('SELECT * FROM `wp_widgets` ORDER BY `id` ASC', $wpdb->queries, true)
    && in_array('SELECT `meta_key` AS k, `meta_value` AS v FROM `wp_widget_meta` WHERE `owner_id` = %d ORDER BY `meta_id` ASC', $wpdb->queries, true),
    'row and sidecar reads pin deterministic primary/id ordering');

$wpdb->tables['widgets'][] = [
    'id' => 8,
    'label' => 'Hello World',
    'body' => 'A corrupt duplicate natural identity',
    'runtime_clock' => '2026-08-14T00:00:00Z',
    'allowed_key' => 'ordinary value',
    'parent_id' => 3,
];
$throws(
    fn() => $capture->capture_table('widgets', $rowDecl, [], $tokens, false),
    "table 'widgets' natural identity matches local ids 7 and 8",
    'duplicate live natural identities refuse even when ledger continuity retained distinct UUIDs'
);
array_pop($wpdb->tables['widgets']);

$missingRefTokens = new TypedCaptureTokens(['post' => []]);
$throws(
    fn() => $capture->capture_table('widgets', $rowDecl, [], $missingRefTokens, false),
    "unmanaged post ref 3 in column 'parent_id'",
    'an unresolved structural row ref refuses capture instead of dropping the row'
);
$secretDecl = $rowDecl;
unset($secretDecl['columns']['allowed_key']['allow_secret']);
$throws(
    fn() => $capture->capture_table('widgets', $secretDecl, [], $tokens, false),
    "column 'allowed_key' (row 7) looks like a stripe key",
    'the capture-owned secret gate refuses a hard match with exact row context'
);
$wpdb->tables['widget_meta'][] = [
    'meta_id' => 7,
    'owner_id' => 7,
    'meta_key' => 'plain',
    'meta_value' => 'duplicate',
];
$throws(
    fn() => $capture->capture_table('widgets', $rowDecl, ['widget_meta' => $metaDecl], $tokens, false),
    "multi-value meta key 'plain'",
    'duplicate attached-meta keys refuse instead of choosing a winner'
);
array_pop($wpdb->tables['widget_meta']);

$joinDecl = [
    'class' => 'authored_snapshot',
    'id_kind' => 'join',
    'identity' => ['mode' => 'composite_ref', 'columns' => ['left_id', 'right_id']],
    'refs' => [
        ['column' => 'left_id', 'kind' => 'left'],
        ['column' => 'right_id', 'kind' => 'right'],
    ],
    'columns' => [
        'label' => ['class' => 'authored'],
        'modified' => ['class' => 'runtime'],
    ],
];
$wpdb->tables['joins'] = [[
    'left_id' => 7,
    'right_id' => 9,
    'label' => 'portable fact',
    'modified' => '2026-08-13',
]];
$mappingCalls = [];
$join = $capture->capture_table('joins', $joinDecl, [], $tokens, true, false)[0] ?? [];
$joinFront = Canon::decode((string) ($join['content'] ?? ''));
$packed = (7 << 31) | 9;
$check(($join['path'] ?? null) === 'tables/joins/cccccccc-cccc-5ccc-8ccc-cccccccccccc--aaaaaaaa-bbbbbbbb.json'
    && ($joinFront['columns']['left_id'] ?? null) === "{{left:$leftUuid}}"
    && ($joinFront['columns']['right_id'] ?? null) === "{{right:$rightUuid}}"
    && ($joinFront['columns']['label'] ?? null) === 'text<portable fact>'
    && !array_key_exists('modified', $joinFront['columns'] ?? []),
    'composite capture derives portable ref-token bytes and a UUID-prefix filename');
$check($mappingCalls === [[
    'cccccccc-cccc-5ccc-8ccc-cccccccccccc',
    'joins',
    'join',
    $packed,
    false,
    "composite table 'joins' row $packed",
]], 'writable composite capture delegates one packed ledger mapping');
$mappingCalls = [];
$capture->capture_table('joins', $joinDecl, [], $tokens, false, true);
$check(($mappingCalls[0][4] ?? null) === true,
    'strict read-only composite capture delegates mapping proof rather than changing capture bytes');
$check(in_array('SELECT * FROM `wp_joins` ORDER BY `left_id`, `right_id` ASC', $wpdb->queries, true),
    'composite reads are deterministic in declared identity-column order');

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
$captureFacade = $methodSource('capture_table');
$check(str_contains($captureFacade, 'typed_table_capture()->capture_table')
    && !str_contains($captureFacade, 'foreach')
    && !str_contains($captureFacade, 'global $wpdb'),
    'Snapshot::capture_table remains a thin TypedTableCapture compatibility facade');
$factory = $methodSource('typed_table_capture');
foreach (['self::snapshot_identity', 'Ledger::require_read_only_mapping', 'Ledger::set',
    'IdentityNotes::natural_key_continuity', 'sanitize_title'] as $capability) {
    $check(str_contains($factory, $capability), "Snapshot capture adapter injects $capability explicitly");
}
$snapshotSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/Snapshot.php');
$captureSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Capture/TypedTableCapture.php');
$check(str_contains($snapshotSource, "require_once __DIR__ . '/../Capture/TypedTableCapture.php';"),
    'Snapshot directly requires its typed-table capture collaborator');
$check(!preg_match('/\b(?:Snapshot|Policy|Ledger|IdentityNotes|Tokens|Uuid)::/', $captureSource),
    'TypedTableCapture has no hidden runtime-service dependency');
$check(!str_contains($snapshotSource, 'SELECT * FROM `$prefixed` ORDER BY `$pk` ASC')
    && !str_contains($snapshotSource, 'multi-value meta key'),
    'typed row and attached-meta read logic has one owner outside Snapshot');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " typed-table capture regression(s) failed\n");
    exit(1);
}

echo "REGRESS_TYPED_TABLE_CAPTURE PASSED\n";
