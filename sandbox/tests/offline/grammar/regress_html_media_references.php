<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/HtmlMediaReferences.php';

use WPrism\HtmlMediaReferences;

if (($argv[1] ?? '') === '--bounded-memory') {
    $plain = '<div class="' . str_repeat('plain ', 60000) . '">';
    HtmlMediaReferences::assert_canonical($plain);
    $plainCount = count(HtmlMediaReferences::references($plain));
    unset($plain);
    $manyAttributes = '<div';
    for ($i = 0; $i < 60000; ++$i) $manyAttributes .= ' data-' . $i . '="value"';
    $manyAttributes .= ' class="wp-image-{{post:11111111-1111-4111-8111-111111111111}}">';
    HtmlMediaReferences::assert_canonical($manyAttributes);
    echo json_encode(['plain_references' => $plainCount, 'attribute_references' => count(HtmlMediaReferences::references($manyAttributes))], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

$cases = [
    ['encoded carriage return is a class separator', '<img class="before&#13;wp-image-8&#xDwp-image-9">', '<img class="before&#13;wp-image-808&#xDwp-image-809">'],
    ['noscript fallback plus active remainder', '<noscript><img class=wp-image-8></noscript><img class=wp-image-9>', '<noscript><img class=wp-image-808></noscript><img class=wp-image-809>'],
    ['noscript raw child cannot consume the active remainder', '<noscript><style><img class=wp-image-8></noscript><img class=wp-image-9>', '<noscript><style><img class=wp-image-8></noscript><img class=wp-image-809>'],
    ['double escaped script', '<script><!-- <script> </script><img class=wp-image-8></script><img class=wp-image-9>', '<script><!-- <script> </script><img class=wp-image-8></script><img class=wp-image-809>'],
    ['double escape comment exit', '<script><!-- <script> --></script><img class=wp-image-8>', '<script><!-- <script> --></script><img class=wp-image-808>'],
    ['SVG title integration', '<svg><title><img class=wp-image-8></title><g class=wp-image-9 /></svg><img class=wp-image-10>', '<svg><title><img class=wp-image-808></title><g class=wp-image-809 /></svg><img class=wp-image-810>'],
    ['SVG CDATA', '<svg><![CDATA[<img class=wp-image-8>]]><g class=wp-image-9 /></svg><img class=wp-image-10>', '<svg><![CDATA[<img class=wp-image-8>]]><g class=wp-image-809 /></svg><img class=wp-image-810>'],
    ['HTML CDATA is a bogus comment', '<![CDATA[><img class=wp-image-8>]]><img class=wp-image-9>', '<![CDATA[><img class=wp-image-808>]]><img class=wp-image-809>'],
    ['SVG breakout returns to HTML', '<svg><g><div></div><title><img class=wp-image-8></title><img class=wp-image-9>', '<svg><g><div></div><title><img class=wp-image-8></title><img class=wp-image-809>'],
    ['SVG foreignObject HTML raw text', '<svg><foreignObject><style><img class=wp-image-8></style><img class=wp-image-9></foreignObject></svg>', '<svg><foreignObject><style><img class=wp-image-8></style><img class=wp-image-809></foreignObject></svg>'],
    ['MathML text integration', '<math><mtext><img class=wp-image-8></mtext><mi class=wp-image-9 /></math>', '<math><mtext><img class=wp-image-808></mtext><mi class=wp-image-809 /></math>'],
    ['bogus end tag', '</? <img class=wp-image-8>><img class=wp-image-9>', '</? <img class=wp-image-8>><img class=wp-image-809>'],
    ['plugin wrapper', '<div class="wp-block-example wp-image-8">Caption wp-image-9</div>', '<div class="wp-block-example wp-image-808">Caption wp-image-9</div>'],
    ['all attribute quoting', "<img CLASS='wp-image-8'><img class=wp-image-9 ><i class=\"wp-image-10\">", "<img CLASS='wp-image-808'><img class=wp-image-809 ><i class=\"wp-image-810\">"],
    ['first duplicate only', '<img class="wp-image-8" CLASS="wp-image-9">', '<img class="wp-image-808" CLASS="wp-image-9">'],
    ['first empty duplicate', '<img class CLASS="wp-image-9">', '<img class CLASS="wp-image-9">'],
    ['attribute names are exact', '<img data-class="wp-image-8" =class="wp-image-9" class-name="wp-image-10">', '<img data-class="wp-image-8" =class="wp-image-9" class-name="wp-image-10">'],
    ['exact class words', '<img class="xwp-image-8 wp-image-9x wp-image-8/ wp-image-0009">', '<img class="xwp-image-8 wp-image-9x wp-image-8/ wp-image-809">'],
    ['unquoted slash belongs to value', '<img class=wp-image-8/><img class=wp-image-9 />', '<img class=wp-image-8/><img class=wp-image-809 />'],
    ['unquoted quote does not open a value', '<img data=x" class=wp-image-8><img class=wp-image-9>', '<img data=x" class=wp-image-808><img class=wp-image-809>'],
    ['quote in attribute name', '<img data"name=x class=wp-image-8>', '<img data"name=x class=wp-image-808>'],
    ['quoted greater-than and apparent markup', '<div data="a > <img class=wp-image-9>" class="wp-image-8">', '<div data="a > <img class=wp-image-9>" class="wp-image-808">'],
    ['prose and escaped markup', 'wp-image-8 &lt;img class="wp-image-9"&gt;', 'wp-image-8 &lt;img class="wp-image-9"&gt;'],
    ['ordinary comment', '<!-- <img class=wp-image-8> --><img class=wp-image-9>', '<!-- <img class=wp-image-8> --><img class=wp-image-809>'],
    ['abrupt comment close', '<!--><img class=wp-image-8><!---><img class=wp-image-9>', '<!--><img class=wp-image-808><!---><img class=wp-image-809>'],
    ['comment end bang', '<!-- ignored --!><img class=wp-image-8>', '<!-- ignored --!><img class=wp-image-808>'],
    ['bogus comment and doctype', '<!bogus " ><img class=wp-image-8><!DOCTYPE html PUBLIC " ><img class=wp-image-9>', '<!bogus " ><img class=wp-image-808><!DOCTYPE html PUBLIC " ><img class=wp-image-809>'],
    ['raw script and closing name delimiter', '<script>"<img class=wp-image-8></scripted><img class=wp-image-9>"</ScRiPt><img class=wp-image-10>', '<script>"<img class=wp-image-8></scripted><img class=wp-image-9>"</ScRiPt><img class=wp-image-810>'],
    ['raw style and RCDATA', '<style>.wp-image-8{content:"<img class=wp-image-9>"}</style><textarea><img class=wp-image-8></textarea><title><img class=wp-image-9></title><img class=wp-image-10>', '<style>.wp-image-8{content:"<img class=wp-image-9>"}</style><textarea><img class=wp-image-8></textarea><title><img class=wp-image-9></title><img class=wp-image-810>'],
    ['plaintext consumes remainder', '<plaintext><img class=wp-image-8></plaintext><img class=wp-image-9>', '<plaintext><img class=wp-image-8></plaintext><img class=wp-image-9>'],
    ['incomplete final tag', '<img class=wp-image-8><img class="wp-image-9', '<img class=wp-image-808><img class="wp-image-9'],
    ['incomplete comment', '<img class=wp-image-8><!-- <img class=wp-image-9>', '<img class=wp-image-808><!-- <img class=wp-image-9>'],
    ['encoded class identity and separators', '<img class="other&#32;&#119;p-image-&#56;&#x9wp-image-9&Tab;end">', '<img class="other&#32;wp-image-808&#x9wp-image-809&Tab;end">'],
    ['unrelated bytes stay exact', '<img class="a&amp;b &copy &#0; wp-image-8 café&#160;thing">', '<img class="a&amp;b &copy &#0; wp-image-808 café&#160;thing">'],
    ['nonbreaking space is not class whitespace', '<img class="x&nbsp;wp-image-8 wp-image-9&#160;x">', '<img class="x&nbsp;wp-image-8 wp-image-9&#160;x">'],
];

if (($argv[1] ?? '') === '--browser-cases') { echo json_encode($cases, JSON_THROW_ON_ERROR); exit(0); }

foreach ($cases as [$label, $source, $expected]) {
    $actual = HtmlMediaReferences::rewrite($source, static fn(array $ref): string => (string) ($ref['reference'] + 800));
    wprism_check_same($expected, $actual, $label);
}
$token = '{{post:11111111-1111-4111-8111-111111111111}}';
wprism_check_same('<img class="before after">', HtmlMediaReferences::rewrite('<img class="before&#32;wp-image-8 after">', static fn(array $ref) => null), 'dropping removes the reference and its encoded leading separator only');
wprism_check_same('<img class=wp-image-' . $token . '>', HtmlMediaReferences::rewrite('<img class=wp-image-8>', static fn(array $ref) => $token), 'canonical token is valid unquoted HTML without reserializing the tag');
wprism_check_same(['outer/group', 'inner/image', 'outer/group', 'core/freeform'], array_column(HtmlMediaReferences::references('<!-- wp:outer/group --><img class=wp-image-8><!-- wp:inner/image --><img class=wp-image-9><!-- /wp:inner/image --><img class=wp-image-10><!-- /wp:outer/group --><img class=wp-image-11>'), 'block'), 'whole-body block context preserves warning ownership');
foreach (['wp-image-8', 'wp-image-' . str_replace('post:', 'term:', $token), 'wp-image-' . $token . 'suffix', 'wp-image-{{post:bad}}', 'wp-image-99999999999999999999999999'] as $bad) {
    wprism_check_throws(static fn() => HtmlMediaReferences::assert_canonical('<img class="' . $bad . '">'), RuntimeException::class, 'canonical HTML refuses raw, wrong-kind, malformed and overflowing identities');
}
HtmlMediaReferences::assert_canonical('<p>wp-image-8</p><!-- <img class=wp-image-9> --><img class="wp-image-' . $token . '">');
wprism_check_same(1, count(HtmlMediaReferences::references('<p>wp-image-8</p><!-- <img class=wp-image-9> --><img class="wp-image-' . $token . '">')), 'canonical validation ignores literal examples');
wprism_check_throws(static fn() => HtmlMediaReferences::references(str_repeat('x', 16777217)), RuntimeException::class, 'document byte budget refuses before a scan');
wprism_check_throws(static fn() => HtmlMediaReferences::references(str_repeat('<br>', 100001)), RuntimeException::class, 'document token budget bounds hostile tiny tags');
$encodedToken = str_replace('{{', '&#123;&#123;', $token);
wprism_check_throws(static fn() => HtmlMediaReferences::assert_canonical('<img class="wp-image-' . $encodedToken . '">'), RuntimeException::class, 'canonical HTML cannot hide an identity edge behind entity encoding');
wprism_check_same('<img class="wp-image-' . $token . '">', HtmlMediaReferences::rewrite('<img class="wp-image-' . $encodedToken . '">', static fn(array $ref) => $ref['suffix']), 'capture normalizes a pre-existing encoded token into literal canonical bytes');
foreach (range(0, 127) as $code) {
    foreach (['&#' . $code . ';', '&#x' . dechex($code) . ';', '&#000' . $code, '&#X000' . strtoupper(dechex($code))] as $entity) {
        $refs = HtmlMediaReferences::references('<img class="before' . $entity . 'wp-image-8">');
        wprism_check_same(in_array($code, [9, 10, 12, 13, 32], true) ? [8] : [], array_column($refs, 'reference'),
            'numeric character reference ' . $entity . ' follows HTML class whitespace');
    }
}
foreach (['0', '55296', '1114112', str_repeat('9', 5000), 'xDFFF', 'x110000', 'x' . str_repeat('F', 5000)] as $invalid) {
    $source = '<img class="prefix&#' . $invalid . ';wp-image-8 wp-image-9">';
    wprism_check_same(str_replace(' wp-image-9', ' wp-image-809', $source), HtmlMediaReferences::rewrite($source, static fn(array $ref) => (string) ($ref['reference'] + 800)),
        'invalid Unicode reference cannot manufacture a class boundary or alter unrelated bytes');
}
foreach ([str_repeat('plain ', 100001), str_repeat('&#32;', 100001)] as $largeValue) {
    wprism_check_throws(static fn() => HtmlMediaReferences::references('<div class="' . $largeValue . '">'), RuntimeException::class,
        'dense class words and character references refuse through the bounded value grammar');
}
$child = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', __FILE__, '--bounded-memory'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('cannot start the bounded HTML memory fixture');
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($child);
wprism_check_same(0, $status, 'large inert class and attribute lists fit a 32 MiB process');
wprism_check_same('', $errors, 'bounded HTML scans emit no memory/runtime diagnostics');
wprism_check_same(['plain_references' => 0, 'attribute_references' => 1], json_decode($output, true),
    'streaming preserves real references after sixty thousand unrelated attributes');
wprism_check_summary('HTML_MEDIA_REFERENCES');
