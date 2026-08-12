<?php
declare(strict_types=1);

/**
 * DUO-3354: the {{home}}/{{uploads}} URL-prefix substitution is a pure text
 * seam. This suite deliberately loads it without WordPress, a database, or
 * a plugin and then proves that Tokens keeps only the historical
 * Ledger-backed facade around it.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/../../agent/src/TextTokenizer.php';
check(!class_exists(\Duo\Ledger::class, false), 'tokenizer standalone load does not load Ledger');
check(!function_exists('get_option'), 'tokenizer standalone load does not need WordPress');

use Duo\TextTokenizer;

$home = 'http://example.test';
$uploads = 'http://example.test/wp-content/uploads';
$t = new TextTokenizer($home, $uploads);

check($t->tokenize('') === '', 'empty string is returned untouched');
check($t->detokenize('') === '', 'empty string is returned untouched (detokenize)');
check(
    $t->tokenize($home . '/some-page/') === '{{home}}/some-page/',
    'a plain home-prefixed URL is tokenized'
);
check(
    $t->tokenize($uploads . '/2026/08/x.png') === '{{uploads}}/2026/08/x.png',
    'a plain uploads-prefixed URL is tokenized'
);
check(
    $t->tokenize(str_replace('/', '\/', $home) . '\/some-page\/') === '{{home}}\/some-page\/',
    'the JSON-escaped home form is ALSO matched and collapses to the plain token spelling'
);
check(
    $t->tokenize(str_replace('/', '\/', $uploads) . '\/2026\/08\/x.png') === '{{uploads}}\/2026\/08\/x.png',
    'the JSON-escaped uploads form is ALSO matched and collapses to the plain token spelling'
);
check(
    $t->tokenize($uploads . '/2026/08/x.png') === '{{uploads}}/2026/08/x.png'
        && !str_contains($t->tokenize($home . '/wp-content/uploads/x.png'), '{{home}}/wp-content/uploads'),
    'uploads is matched before home, since uploads is normally home-prefixed'
);
check(
    $t->detokenize('{{home}}/some-page/') === $home . '/some-page/',
    'a home token restores the plain (unescaped) URL'
);
check(
    $t->detokenize('{{uploads}}/2026/08/x.png') === $uploads . '/2026/08/x.png',
    'an uploads token restores the plain (unescaped) URL'
);
check(
    $t->tokenize('unrelated text with no tokens') === 'unrelated text with no tokens',
    'text carrying neither URL is returned unchanged'
);
check(
    $t->detokenize($t->tokenize('See ' . $home . '/a/ and ' . $uploads . '/b.png also.'))
        === 'See ' . $home . '/a/ and ' . $uploads . '/b.png also.',
    'tokenize then detokenize round-trips a mixed string exactly'
);

// The public Tokens facade remains the one spelling used by existing
// callers, but the URL-prefix substitution now lives in the pure
// collaborator, and the facade's own escaped-URL bookkeeping is gone.
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Tokens.php';

use Duo\Tokens;

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

$tokens = new Tokens();
check(
    $tokens->tokenize_text('See ' . $home . '/a/.') === 'See {{home}}/a/.',
    'Tokens facade still tokenizes a plain home URL through the collaborator'
);
check(
    $tokens->detokenize_text('See {{home}}/a/.') === 'See ' . $home . '/a/.',
    'Tokens facade still detokenizes a home token through the collaborator'
);
check($tokens->home() === $home, 'Tokens::home() is unaffected by the extraction');

$tokenSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Tokens.php');
check(str_contains($tokenSource, 'textTokenizer->tokenize('), 'Tokens delegates URL-prefix tokenizing to the collaborator');
check(str_contains($tokenSource, 'textTokenizer->detokenize('), 'Tokens delegates URL-prefix detokenizing to the collaborator');
check(!str_contains($tokenSource, 'homeEscaped'), 'Tokens no longer owns a duplicate escaped-home property');
check(!str_contains($tokenSource, 'uploadsUrlEscaped'), 'Tokens no longer owns a duplicate escaped-uploads property');

fwrite(STDOUT, "ALL PASSED\n");
