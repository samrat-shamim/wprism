<?php
declare(strict_types=1);

/**
 * issue #3354: the entity-token wire format is a pure codec seam.  This suite
 * deliberately loads it without WordPress, a database, or a plugin and then
 * proves that Tokens keeps only the historical Ledger-backed facade.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

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

final class CodecFakeWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<string,array<int,string>> */
    public array $identity = [
        'post' => [42 => '01234567-89ab-cdef-0123-456789abcdef'],
        'term_taxonomy' => [7 => 'fedcba98-7654-3210-fedc-ba9876543210'],
        'custom_kind' => [9 => '11111111-2222-3333-4444-555555555555'],
    ];

    public function prepare(string $sql, ...$args): array {
        return ['sql' => $sql, 'args' => $args];
    }

    public function get_var(array $prepared): mixed {
        $sql = $prepared['sql'];
        $args = $prepared['args'];
        if (str_contains($sql, 'SELECT uuid FROM')) {
            return $this->identity[(string) $args[0]][(int) $args[1]] ?? null;
        }
        if (str_contains($sql, 'SELECT local_id FROM')) {
            foreach ($this->identity[(string) $args[1]] ?? [] as $id => $uuid) {
                if ($uuid === $args[0]) {
                    return $id;
                }
            }
            return null;
        }
        throw new RuntimeException('unexpected fake ledger query');
    }
}

require __DIR__ . '/../../../../agent/src/Kernel/IdentityTokenCodec.php';
check(!class_exists(\WPrism\Ledger::class, false), 'codec standalone load does not load Ledger');
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';

use WPrism\IdentityTokenCodec;
use WPrism\Tokens;

$uuid = '01234567-89ab-cdef-0123-456789abcdef';

$GLOBALS['wpdb'] = new CodecFakeWpdb();
$tokens = new Tokens();
check($tokens->id_to_token(42, 'post') === '{{post:' . $uuid . '}}', 'Tokens facade round-trips a post identity through Ledger');
check($tokens->id_to_token(7, 'tt') === '{{tt:fedcba98-7654-3210-fedc-ba9876543210}}', 'Tokens facade preserves the tt token spelling while using term_taxonomy storage');
check($tokens->id_to_token(9, 'custom_kind') === '{{custom_kind:11111111-2222-3333-4444-555555555555}}', 'Tokens facade round-trips a custom manifest identity');
check($tokens->token_to_id('{{tt:fedcba98-7654-3210-fedc-ba9876543210}}') === 7, 'Tokens facade resolves an aliased tt token through Ledger');
check($tokens->token_to_id('{{custom_kind:11111111-2222-3333-4444-555555555555}}') === 9, 'Tokens facade resolves a custom token through Ledger');
$unresolvable = false;
try {
    $tokens->token_to_id('{{post:aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa}}');
} catch (RuntimeException $e) {
    $unresolvable = str_contains($e->getMessage(), 'unresolvable ref');
}
check($unresolvable, 'valid token with no Ledger row remains a distinct unresolvable refusal');

check(class_exists(IdentityTokenCodec::class), 'codec is directly loadable without WordPress');
check(IdentityTokenCodec::ledger_kind('post') === 'post', 'post kind remains unchanged');
check(IdentityTokenCodec::ledger_kind('term') === 'term', 'term kind remains unchanged');
check(IdentityTokenCodec::ledger_kind('tt') === 'term_taxonomy', 'tt alias remains the ledger taxonomy kind');
check(IdentityTokenCodec::ledger_kind('wc_zone_method') === 'wc_zone_method', 'custom manifest kinds pass through');
check(IdentityTokenCodec::ledger_kind('post') === \WPrism\Ledger::KIND_POST, 'post alias stays aligned with Ledger vocabulary');
check(IdentityTokenCodec::ledger_kind('term') === \WPrism\Ledger::KIND_TERM, 'term alias stays aligned with Ledger vocabulary');
check(IdentityTokenCodec::ledger_kind('tt') === \WPrism\Ledger::KIND_TT, 'tt alias stays aligned with Ledger vocabulary');
check(IdentityTokenCodec::encode('post', $uuid) === '{{post:' . $uuid . '}}', 'encode preserves canonical post token spelling');
check(IdentityTokenCodec::encode('custom_kind', $uuid) === '{{custom_kind:' . $uuid . '}}', 'encode supports declared custom kinds');
check(IdentityTokenCodec::encode('post', null) === null, 'unmapped identities remain null at the codec boundary');
check(
    IdentityTokenCodec::decode('{{tt:' . $uuid . '}}') === ['kind' => 'tt', 'uuid' => $uuid],
    'decode returns the original kind and UUID'
);
check(
    IdentityTokenCodec::decode('{{custom_kind:' . $uuid . '}}')['kind'] === 'custom_kind',
    'decode accepts the manifest custom-kind vocabulary'
);

foreach ([
    '{{POST:' . $uuid . '}}',
    '{{post:not-a-uuid}}',
    '{{post:' . strtoupper($uuid) . '}}',
    'post:' . $uuid,
] as $malformed) {
    $threw = false;
    try {
        IdentityTokenCodec::decode($malformed);
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), "wprism: malformed ref token '$malformed'");
    }
    check($threw, "malformed token is refused with the historical diagnostic: $malformed");
}

// The public facade remains the one spelling used by existing callers, but
// its mapping and parser implementation now live in the pure collaborator.
$tokenSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Grammar/Tokens.php');
check(Tokens::ledger_kind('tt') === 'term_taxonomy', 'Tokens facade preserves ledger_kind() behavior');
check(str_contains($tokenSource, 'IdentityTokenCodec::ledger_kind'), 'Tokens delegates kind mapping to the codec');
check(str_contains($tokenSource, 'IdentityTokenCodec::encode'), 'Tokens delegates token formatting to the codec');
check(str_contains($tokenSource, 'IdentityTokenCodec::decode'), 'Tokens delegates token parsing to the codec');
check(!str_contains($tokenSource, 'private const KIND_MAP'), 'Tokens no longer owns a duplicate kind map');
check(!str_contains($tokenSource, 'KIND_NAME_RE'), 'Tokens no longer owns a duplicate token grammar');

fwrite(STDOUT, "ALL PASSED\n");
