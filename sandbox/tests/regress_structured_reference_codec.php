<?php
declare(strict_types=1);

/**
 * DUO-3354: declared json_refs/key_refs rewriting is a pure structural
 * codec seam. This drives it without WordPress or Ledger, then proves the
 * stateful Tokens facade keeps its historic leaf/text responsibility.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/../../agent/src/StructuredReferenceCodec.php';

use Duo\StructuredReferenceCodec;

check(!class_exists(\Duo\Ledger::class, false), 'codec standalone load does not load Ledger');
check(!function_exists('get_option'), 'codec standalone load does not need WordPress');

$ids = [
    'post' => [11 => '11111111-1111-1111-1111-111111111111'],
    'term' => [22 => '22222222-2222-2222-2222-222222222222'],
];
$reverse = [];
foreach ($ids as $kind => $rows) {
    foreach ($rows as $id => $uuid) {
        $reverse['{{' . $kind . ':' . $uuid . '}}'] = $id;
    }
}
$warnings = [];
$idToToken = static function (int $id, string $kind) use ($ids): ?string {
    $uuid = $ids[$kind][$id] ?? null;
    return $uuid === null ? null : '{{' . $kind . ':' . $uuid . '}}';
};
$tokenToId = static function (string $token) use ($reverse): int {
    if (!isset($reverse[$token])) {
        throw new RuntimeException("unresolvable $token");
    }
    return $reverse[$token];
};
$warn = static function (string $warning) use (&$warnings): void {
    $warnings[] = $warning;
};

$source = [
    'nested' => [
        ['post_id' => 11, 'term_id' => 22],
        ['post_id' => 0, 'term_id' => 999],
    ],
    'by_term' => [22 => ['label' => 'kept'], 999 => ['label' => 'dropped']],
    'ordinary' => 'http://example.test/untouched-by-the-pure-codec',
];
$jsonRefs = [
    ['path' => '$.nested.post_id', 'kind' => 'post'],
    ['path' => '$.nested.term_id', 'kind' => 'term', 'cast' => 'string'],
];
$keyRefs = ['path' => '$.by_term', 'kind' => 'term'];
$captured = StructuredReferenceCodec::capture($source, $jsonRefs, $keyRefs, $idToToken, $warn);

check(
    $captured['nested'][0]['post_id'] === '{{post:11111111-1111-1111-1111-111111111111}}'
        && $captured['nested'][0]['term_id'] === '{{term:22222222-2222-2222-2222-222222222222}}',
    'codec rewrites every declared nested scalar reference'
);
check(
    $captured['nested'][1]['post_id'] === 0 && $captured['nested'][1]['term_id'] === null,
    'codec preserves unset scalar ids and nulls unmapped declared ids'
);
check(
    array_keys($captured['by_term']) === ['{{term:22222222-2222-2222-2222-222222222222}}'],
    'codec rewrites declared id-map keys and drops an unmapped whole entry'
);
check(
    $captured['ordinary'] === $source['ordinary'],
    'pure codec leaves unrelated string leaves for Tokens to own'
);
check(
    $warnings === [
        "json_refs path '.nested[1].term_id': unmapped term id 999 dropped (dangling reference)",
        "key_refs: unmapped term id '999' at .by_term dropped (dangling reference)",
    ],
    'codec preserves historical warnings and declaration-order emission'
);

$restored = StructuredReferenceCodec::apply($captured, $jsonRefs, $keyRefs, $tokenToId);
check(
    $restored['nested'][0] === ['post_id' => 11, 'term_id' => '22']
        && $restored['nested'][1] === ['post_id' => 0, 'term_id' => null],
    'codec restores scalar ids with the declared cast and preserves capture-time nulls'
);
check(array_keys($restored['by_term']) === [22], 'codec restores declared token keys as integer ids');

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $name === 'home' ? 'http://example.test' : $default;
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) {
        return ['baseurl' => 'http://example.test/wp-content/uploads'];
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value) {
        return rtrim((string) $value, '/\\');
    }
}

final class StructuredCodecFakeWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';

    public function prepare(string $sql, ...$args): array {
        return ['sql' => $sql, 'args' => $args];
    }

    public function get_var(array $prepared): mixed {
        $args = $prepared['args'];
        if (str_contains($prepared['sql'], 'SELECT uuid FROM')) {
            return match ([(string) $args[0], (int) $args[1]]) {
                ['post', 11] => '11111111-1111-1111-1111-111111111111',
                ['term', 22] => '22222222-2222-2222-2222-222222222222',
                default => null,
            };
        }
        if (str_contains($prepared['sql'], 'SELECT local_id FROM')) {
            return match ([(string) $args[1], (string) $args[0]]) {
                ['post', '11111111-1111-1111-1111-111111111111'] => 11,
                ['term', '22222222-2222-2222-2222-222222222222'] => 22,
                default => null,
            };
        }
        throw new RuntimeException('unexpected fake ledger query');
    }
}

require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Tokens.php';

$GLOBALS['wpdb'] = new StructuredCodecFakeWpdb();
$tokens = new Duo\Tokens();
$facadeCaptured = $tokens->struct_capture(
    ['post_id' => 11, 'by_term' => [22 => ['url' => 'http://example.test/a']]],
    [['path' => '$.post_id', 'kind' => 'post']],
    ['path' => '$.by_term', 'kind' => 'term']
);
check(
    $facadeCaptured === [
        'post_id' => '{{post:11111111-1111-1111-1111-111111111111}}',
        'by_term' => ['{{term:22222222-2222-2222-2222-222222222222}}' => ['url' => '{{home}}/a']],
    ],
    'Tokens facade delegates structural refs then retains its historic text-leaf tokenization'
);
check(
    $tokens->struct_apply(
        $facadeCaptured,
        [['path' => '$.post_id', 'kind' => 'post']],
        ['path' => '$.by_term', 'kind' => 'term']
    ) === ['post_id' => 11, 'by_term' => [22 => ['url' => 'http://example.test/a']]],
    'Tokens facade detokenizes leaves before structural restoration as before'
);

$tokenSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Tokens.php');
check(str_contains($tokenSource, 'StructuredReferenceCodec::capture('), 'Tokens delegates capture structural rewriting to the codec');
check(str_contains($tokenSource, 'StructuredReferenceCodec::apply('), 'Tokens delegates apply structural rewriting to the codec');
check(!str_contains($tokenSource, 'private function rewrite_keys'), 'Tokens no longer owns duplicate key-reference rewrite implementation');

fwrite(STDOUT, "ALL PASSED\n");
