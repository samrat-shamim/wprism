<?php
/**
 * Direct offline certification for Capture's extracted post/term metadata
 * boundary. Runs the exact SQL, classification, security, and codec pipeline
 * without loading Capture, Policy, Tokens, or WordPress.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../../agent/src/Capture/EntityMetaCapture.php';

use Duo\EntityMetaCapture;
use Duo\OrderPreserved;

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

$check(class_exists(EntityMetaCapture::class, false), 'EntityMetaCapture loads as a direct offline boundary');
foreach (['Duo\\Capture', 'Duo\\Policy', 'Duo\\Tokens'] as $runtimeClass) {
    $check(!class_exists($runtimeClass, false), "EntityMetaCapture does not load $runtimeClass");
}

final class EntityMetaPolicyFixture {
    /** @var array<int,array{surface:string,key:string,flat:array}> */
    public array $calls = [];
    private ?\Closure $record;

    public function __construct(?\Closure $record = null) {
        $this->record = $record;
    }

    private function rule(string $surface, string $key, array $flat): ?array {
        $this->calls[] = ['surface' => $surface, 'key' => $key, 'flat' => $flat];
        if ($this->record !== null) {
            ($this->record)("policy:$surface:$key");
        }
        return match ($key) {
            'plain', 'duplicate', 'secret_value' => ['class' => 'authored'],
            'contextual' => ($flat['marker'] ?? null) === 'first'
                ? ['class' => 'authored']
                : null,
            'runtime' => ['class' => 'runtime'],
            'scalar_ref', 'dangling' => ['class' => 'authored', 'ref' => 'post'],
            'term_ref' => ['class' => 'authored', 'ref' => 'term'],
            'structured' => [
                'class' => 'authored',
                'json_refs' => [['path' => 'owner', 'kind' => 'post']],
            ],
            'ordered' => ['class' => 'authored', 'order_preserving' => true],
            default => null,
        };
    }

    public function meta_rule_for_post(string $key, array $flat): ?array {
        return $this->rule('post', $key, $flat);
    }

    public function meta_rule_for_term(string $key, array $flat): ?array {
        return $this->rule('term', $key, $flat);
    }
}

final class EntityMetaTokensFixture {
    /** @var string[] */
    public array $calls = [];
    private ?\Closure $record;

    public function __construct(?\Closure $record = null) {
        $this->record = $record;
    }

    private function record(string $event): void {
        $this->calls[] = $event;
        if ($this->record !== null) {
            ($this->record)("codec:$event");
        }
    }

    public function tokenize_text(string $value): string {
        $this->record("text:$value");
        return "tokenized:$value";
    }

    public function meta_value_to_tokens($value, array $rule) {
        $this->record(($rule['ref'] ?? 'unknown') . ':' . (string) $value);
        return (string) $value === '404' ? null : '{{' . $rule['ref'] . ':fixture-' . $value . '}}';
    }

    public function struct_capture($value, array $_jsonRefs, ?array $_keyRefs) {
        $this->record('structured');
        return ['captured' => $value];
    }
}

final class EntityMetaWpdbFixture {
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';
    /** @var array<int,array{meta_id:int,post_id:int,meta_key:string,meta_value:string}> */
    public array $postRows = [];
    /** @var array<int,array{meta_id:int,term_id:int,meta_key:string,meta_value:string}> */
    public array $termRows = [];
    /** @var string[] */
    public array $sql = [];
    /** @var string[] */
    public array $events = [];

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) (int) $arg, $sql, 1) ?? $sql;
        }
        return $sql;
    }

    public function get_results(string $sql, mixed $mode): array {
        if ($mode !== ARRAY_A) {
            throw new \RuntimeException('fixture expected ARRAY_A');
        }
        $this->sql[] = $sql;
        $isPost = str_contains($sql, 'FROM wp_postmeta');
        $this->events[] = $isPost ? 'query:post' : 'query:term';
        preg_match('/(?:post_id|term_id) = ([0-9]+)/', $sql, $match);
        $ownerId = (int) ($match[1] ?? 0);
        $ownerColumn = $isPost ? 'post_id' : 'term_id';
        $rows = array_values(array_filter(
            $isPost ? $this->postRows : $this->termRows,
            static fn(array $row): bool => $row[$ownerColumn] === $ownerId
        ));
        usort($rows, static function (array $left, array $right) use ($sql): int {
            return str_contains($sql, 'ORDER BY meta_key ASC')
                ? [$left['meta_key'], $left['meta_id']] <=> [$right['meta_key'], $right['meta_id']]
                : $left['meta_id'] <=> $right['meta_id'];
        });
        return array_map(
            static fn(array $row): array => [
                'meta_key' => $row['meta_key'],
                'meta_value' => $row['meta_value'],
            ],
            $rows
        );
    }
}

$trace = [];
$policy = new EntityMetaPolicyFixture(static function (string $event) use (&$trace): void {
    $trace[] = $event;
});
$tokens = new EntityMetaTokensFixture(static function (string $event) use (&$trace): void {
    $trace[] = $event;
});
$wpdb = new EntityMetaWpdbFixture();
$wpdb->postRows = [
    ['meta_id' => 4, 'post_id' => 7, 'meta_key' => 'plain', 'meta_value' => 'hello'],
    ['meta_id' => 1, 'post_id' => 7, 'meta_key' => 'marker', 'meta_value' => 'first'],
    ['meta_id' => 2, 'post_id' => 7, 'meta_key' => 'marker', 'meta_value' => 'second'],
    ['meta_id' => 3, 'post_id' => 7, 'meta_key' => 'duplicate', 'meta_value' => 'one'],
    ['meta_id' => 5, 'post_id' => 7, 'meta_key' => 'duplicate', 'meta_value' => 'two'],
];
$wpdb->termRows = [
    ['meta_id' => 3, 'term_id' => 9, 'meta_key' => 'term_ref', 'meta_value' => '21'],
    ['meta_id' => 1, 'term_id' => 9, 'meta_key' => 'marker', 'meta_value' => 'first'],
    ['meta_id' => 2, 'term_id' => 9, 'meta_key' => 'marker', 'meta_value' => 'second'],
];
$GLOBALS['wpdb'] = $wpdb;
$unclassified = [];
$capture = new EntityMetaCapture(
    $policy,
    $tokens,
    static function (string $section, string $key, $value, array $_rule, string $context) use (&$trace): void {
        $trace[] = "secret:$section:$key:$context:" . get_debug_type($value);
        if ($key === 'secret_value') {
            throw new \RuntimeException('fixture secret refusal');
        }
    },
    static function () use ($wpdb): void {
        $wpdb->events[] = 'checkpoint';
    },
    static function (string $finding) use (&$unclassified): void {
        $unclassified[] = $finding;
    }
);

$postMap = $capture->postMetaMap(7);
$postByKey = $capture->postMetaByKey(7);
$termMap = $capture->termMetaMap(9);
$termByKey = $capture->termMetaByKey(9);
$normalizeSql = static fn(string $sql): string => preg_replace('/\s+/', ' ', trim($sql)) ?? '';
$check($postMap === ['marker' => 'first', 'duplicate' => 'one', 'plain' => 'hello'],
    'post flat context keeps the first value per key in meta_id order');
$check($postByKey === ['duplicate' => ['one', 'two'], 'marker' => ['first', 'second'], 'plain' => ['hello']],
    'post grouped context keeps every value in key/meta_id order');
$check($termMap === ['marker' => 'first', 'term_ref' => '21'],
    'term flat context keeps the first value per key in meta_id order');
$check($termByKey === ['marker' => ['first', 'second'], 'term_ref' => ['21']],
    'term grouped context keeps every value in key/meta_id order');
$check(array_map($normalizeSql, $wpdb->sql) === [
    'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 7 ORDER BY meta_id ASC',
    'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 7 ORDER BY meta_key ASC, meta_id ASC',
    'SELECT meta_key, meta_value FROM wp_termmeta WHERE term_id = 9 ORDER BY meta_id ASC',
    'SELECT meta_key, meta_value FROM wp_termmeta WHERE term_id = 9 ORDER BY meta_key ASC, meta_id ASC',
], 'all four reads preserve their exact normalized SQL and deterministic ordering');
$check($wpdb->events === [
    'query:post', 'checkpoint', 'query:post', 'query:term', 'checkpoint', 'query:term',
], 'observation checkpoints run immediately after flat reads and nowhere on grouped capture reads');

$trace = [];
[$store, $value] = $capture->classifyValue(
    'contextual', ['payload'], ['marker' => 'first', 'contextual' => 'payload'], 'post 7', 'post_meta'
);
$check($store && $value === 'tokenized:payload',
    'post policy receives first-value sibling context before text tokenization');
$check($trace === [
    'policy:post:contextual',
    'secret:post_meta:contextual: on post 7:string',
    'codec:text:payload',
], 'post classification runs policy then secret gate then codec in exact order');

$trace = [];
[$store, $value] = $capture->classifyValue(
    'structured', ['a:1:{s:5:"owner";i:17;}'], ['structured' => 'fixture'], 'post 7', 'post_meta'
);
$check($store && $value === ['captured' => ['owner' => 17]],
    'serialized structured values are safely decoded before structured reference capture');
$check($trace === [
    'policy:post:structured',
    'secret:post_meta:structured: on post 7:array',
    'codec:structured',
], 'array-shaped authored values pass the secret gate before structured encoding');

$trace = [];
[$termStore, $termValue] = $capture->classifyValue(
    'term_ref', ['21'], $termMap, 'term category:news', 'term_meta', true
);
$check($termStore && $termValue === '{{term:fixture-21}}',
    'term classification uses the term policy surface and term reference codec');
$check($trace === [
    'policy:term:term_ref',
    'secret:term_meta:term_ref: on term category:news:string',
    'codec:term:21',
], 'term security context and codec ordering remain exact');

[$danglingStore] = $capture->classifyValue(
    'dangling', ['404'], ['dangling' => '404'], 'post 7', 'post_meta'
);
$check(!$danglingStore, 'a dangling scalar reference is omitted after the token codec warns');
[$runtimeStore] = $capture->classifyValue(
    'runtime', ['local'], ['runtime' => 'local'], 'post 7', 'post_meta'
);
$check(!$runtimeStore, 'a classified non-authored key is omitted without entering the loud gate');
[$unknownStore] = $capture->classifyValue(
    'unknown', ['opaque'], ['unknown' => 'opaque'], 'menu item 12', 'menu_item_meta'
);
$check(!$unknownStore && $unclassified === ['menu_item_meta:unknown'],
    'an unclassified key records the caller-specific exact loud-gate finding');

[$orderedStore, $orderedValue] = $capture->classifyValue(
    'ordered', ['a:2:{s:1:"z";i:1;s:1:"a";i:2;}'], ['ordered' => 'fixture'], 'post 7', 'post_meta'
);
$check($orderedStore && $orderedValue instanceof OrderPreserved
    && $orderedValue->value === ['z' => 1, 'a' => 2],
    'order preservation wraps the fully decoded value last');

$trace = [];
$throws(
    static fn() => $capture->classifyValue(
        'duplicate', ['first', 'second'], ['duplicate' => 'first'], 'post 7', 'post_meta'
    ),
    "multi-value authored meta 'duplicate' on post 7 unsupported in v0",
    'multi-value authored metadata still refuses instead of choosing one row'
);
$check($trace === ['policy:post:duplicate'],
    'multi-value refusal happens before secret scanning and every codec');

$trace = [];
$throws(
    static fn() => $capture->classifyValue(
        'secret_value', ['credential'], ['secret_value' => 'credential'], 'post 7', 'post_meta'
    ),
    'fixture secret refusal',
    'secret refusal aborts authored entity metadata classification'
);
$check($trace === [
    'policy:post:secret_value',
    'secret:post_meta:secret_value: on post 7:string',
], 'secret refusal performs no codec work');

$captureSource = file_get_contents(__DIR__ . '/../../agent/src/Capture/Capture.php');
$candidateSource = file_get_contents(__DIR__ . '/../../agent/src/Capture/CaptureCandidateBuilder.php');
$termCaptureSource = file_get_contents(__DIR__ . '/../../agent/src/Capture/TermCapture.php');
$check(is_string($candidateSource)
    && str_contains($candidateSource, "require_once __DIR__ . '/EntityMetaCapture.php';"),
    'candidate builder explicitly requires its extracted entity-meta collaborator');
$check(is_string($candidateSource)
    && str_contains($candidateSource, 'new EntityMetaCapture(')
    && str_contains($candidateSource, '$this->safetyGates->guardSecret($section, $key, $value, $rule, $context);')
    && str_contains($candidateSource, '$this->unclassified[] = $finding;'),
    'candidate builder binds the exact secret and unclassified side channels');
$check(is_string($termCaptureSource)
    && str_contains($termCaptureSource, '$this->entityMetaCapture->termMetaByKey(')
    && str_contains($termCaptureSource, '$this->entityMetaCapture->classifyValue('),
    'the extracted term capturer directly consumes ordered term metadata classification');
$check(is_string($captureSource)
    && !str_contains($captureSource, 'SELECT meta_key, meta_value FROM {$wpdb->postmeta}')
    && !str_contains($captureSource, 'SELECT meta_key, meta_value FROM {$wpdb->termmeta}')
    && !str_contains($captureSource, 'PlainData::decode($values[0], "$ownerLabel meta $key")'),
    'Capture no longer owns duplicate post/term metadata SQL or classification logic');

if ($failures !== []) {
    fwrite(STDERR, "\nREGRESS_ENTITY_META_CAPTURE FAILED: " . count($failures) . " check(s)\n");
    exit(1);
}
fwrite(STDOUT, "\nREGRESS_ENTITY_META_CAPTURE PASSED\n");
