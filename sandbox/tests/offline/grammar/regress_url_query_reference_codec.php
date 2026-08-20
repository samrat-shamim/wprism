<?php
declare(strict_types=1);

/**
 * DUO-3354: URL query post-reference rewriting is a structural codec seam.
 * This drives it without WordPress, Ledger, Capture, or policy; Tokens keeps
 * those stateful compatibility responsibilities through supplied callbacks.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/../../../../agent/src/Kernel/UrlQueryReferenceCodec.php';

use Duo\UrlQueryReferenceCodec;

check(!class_exists(\Duo\Ledger::class, false), 'codec standalone load does not load Ledger');
check(!function_exists('get_option'), 'codec standalone load does not need WordPress');

const UUID = '11111111-1111-1111-1111-111111111111';
const ID = 701;
$warnings = [];
$unresolved = [];
$idToToken = static fn(int $id): ?string => $id === ID ? '{{post:' . UUID . '}}' : null;
$warn = static function (string $warning) use (&$warnings): void {
    $warnings[] = $warning;
};
$observe = static function (string $context, string $param, int $id) use (&$unresolved): void {
    $unresolved[] = compact('context', 'param', 'id');
};

$captured = UrlQueryReferenceCodec::capture(
    'See {{home}}/?p=' . ID . '&page_id=' . ID . '&attachment_id=' . ID,
    "page 'about'",
    $idToToken,
    $warn,
    $observe
);
check(
    $captured === 'See {{home}}/?p={{post:' . UUID . '}}&page_id={{post:' . UUID . '}}&attachment_id={{post:' . UUID . '}}',
    'codec rewrites exactly the three declared query-reference parameters'
);
check($warnings === [] && $unresolved === [], 'mapped references emit no warning or unresolved observation');

$external = 'https://external.example/?p=999';
check(
    UrlQueryReferenceCodec::capture($external, '', $idToToken, $warn, $observe) === $external,
    'codec never rewrites an unanchored external URL'
);
check(
    UrlQueryReferenceCodec::capture('{{home}}/post/?page=2&foo=999', '', $idToToken, $warn, $observe)
        === '{{home}}/post/?page=2&foo=999',
    'codec leaves pagination and undeclared query parameters untouched'
);

$warnings = [];
$unresolved = [];
$dropped = UrlQueryReferenceCodec::capture('{{home}}/?foo=1&p=999&bar=2', 'option:example', $idToToken, $warn, $observe);
check($dropped === '{{home}}/?foo=1&bar=2', 'unmapped reference drops exactly its separator/parameter/value span');
check(
    $warnings === ["url query ref 'p=999' unmapped post id 999 dropped (dangling reference)"],
    'codec emits the historical unresolved-reference warning'
);
check(
    $unresolved === [['context' => 'option:example', 'param' => 'p', 'id' => 999]],
    'codec reports unresolved context, parameter, and id to its caller after warning'
);

$tokenToId = static function (string $token): int {
    if ($token !== '{{post:' . UUID . '}}') {
        throw new RuntimeException("unresolvable $token");
    }
    return ID;
};
check(
    UrlQueryReferenceCodec::apply('{{home}}/?page_id={{post:' . UUID . '}}', $tokenToId)
        === '{{home}}/?page_id=' . ID,
    'codec restores an emitted token only at a declared query-reference position'
);
check(
    UrlQueryReferenceCodec::apply('{{post:' . UUID . '}} outside a query parameter', $tokenToId)
        === '{{post:' . UUID . '}} outside a query parameter',
    'codec never rewrites a post token outside its declared query-reference shape'
);
$threw = false;
try {
    UrlQueryReferenceCodec::apply('{{home}}/?p={{post:00000000-0000-7000-8000-000000000000}}', $tokenToId);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'unresolvable');
}
check($threw, 'codec propagates an unresolvable apply lookup');

fwrite(STDOUT, "ALL PASSED\n");
