<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3260's URL-query reference codec (`?p=`/`?page_id=`/
 * `?attachment_id=`):
 *
 *  - agent/src/Kernel/UrlQueryReferenceCodec.php, driven through Tokens.php's
 *    tokenize_text()/detokenize_text() facade, now also
 *    rewrite/restore these three WordPress-core query-string parameters
 *    within {{home}}-anchored URL spans, mirroring Blocks.php's/
 *    Shortcodes.php's own dangling-vs-unscoped triage (Capture::
 *    classify_unscoped_ref()) a third time.
 *  - agent/src/Review/Lint.php's new unrewritten_url_query_ref finding.
 *
 * Runs the REAL, unmodified agent/src/{Canon,Policy,Tokens,Ledger,Pending,
 * Lint}.php against hand-built fixtures, plus agent/src/Capture/Capture.php
 * (class-definition only, for its zero-instance-dependency classify_
 * unscoped_ref()/ref_target_type() statics — same precedent regress_
 * block_refs.php/regress_shortcode_refs.php already established). A
 * minimal fake $wpdb stands in for the two narrow, fixed-shape query
 * patterns Ledger::id_for()/uuid_for() and Pending::resolve_id() actually
 * issue. No database, no HTTP, no docker, no block/shortcode stubs needed
 * at all — this mechanism is pure regex text handling.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

// ---------------------------------------------------------------- WP stubs

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['__fake_options'] = ['home' => 'http://example.test'];

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['__fake_options'][$name] ?? $default;
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) {
        return [
            'baseurl' => 'http://example.test/wp-content/uploads',
            'basedir' => sys_get_temp_dir() . '/duo-regress-uploads',
        ];
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim((string) $string, '/\\');
    }
}

// Lint::scan_post_file() unconditionally calls parse_blocks() (for its own
// unregistered_block_attr/unrewritten_registered_ref scan) on any non-
// verbatim post body -- needed here even though this harness's own
// mechanism is pure regex text handling, since Lint::scan_tree() is the
// single entry point exercising both. Same vendored, version-pinned stub
// regress_block_refs.php/regress_shortcode_refs.php already use.
require __DIR__ . '/../../support/wp-block-parser-stub.php';

// ------------------------------------------------------------- fake $wpdb

/**
 * Same shape as regress_block_refs.php's/regress_shortcode_refs.php's own
 * FakeWpdb (each regress_*.php harness owns its own copy, this codebase's
 * established convention).
 */
final class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $posts = 'wp_posts';
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';

    /** @var array<string, array<int, string>> id_kind => [local_id => uuid] */
    public $identity = [];
    /** @var array<int, array{post_type:string, post_title:string, post_status?:string}> */
    public $postsById = [];
    /** @var array<int, array{name:string, taxonomy:string}> */
    public $termsById = [];

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return ['__prepared' => true, 'sql' => $query, 'args' => $args];
    }

    public function get_var($prepared) {
        [$sql, $args] = $this->unwrap($prepared);
        if (str_contains($sql, 'SELECT local_id FROM') && str_contains($sql, 'duo_map')) {
            [$uuid, $kind] = $args;
            foreach ($this->identity[$kind] ?? [] as $localId => $u) {
                if ($u === $uuid) {
                    return $localId;
                }
            }
            return null;
        }
        if (str_contains($sql, 'SELECT uuid FROM') && str_contains($sql, 'duo_map')) {
            [$kind, $localId] = $args;
            return $this->identity[$kind][(int) $localId] ?? null;
        }
        if (str_contains($sql, 'SELECT post_type FROM') && str_contains($sql, $this->posts)) {
            $id = (int) $args[0];
            $row = $this->postsById[$id] ?? null;
            if ($row === null) {
                return null;
            }
            if (($row['post_type'] ?? '') === 'revision' || ($row['post_status'] ?? 'publish') === 'auto-draft') {
                return null;
            }
            return $row['post_type'];
        }
        if (str_contains($sql, 'SELECT taxonomy FROM') && str_contains($sql, $this->term_taxonomy)) {
            $id = (int) $args[0];
            return $this->termsById[$id]['taxonomy'] ?? null;
        }
        throw new \RuntimeException("FakeWpdb::get_var: unrecognized query shape: $sql");
    }

    public function get_row($prepared, $output = ARRAY_A) {
        [$sql, $args] = $this->unwrap($prepared);
        if (str_contains($sql, $this->posts)) {
            $id = (int) $args[0];
            $row = $this->postsById[$id] ?? null;
            if ($row === null) {
                return null;
            }
            if (($row['post_type'] ?? '') === 'revision' || ($row['post_status'] ?? 'publish') === 'auto-draft') {
                return null;
            }
            return ['ID' => $id, 'post_type' => $row['post_type'], 'post_title' => $row['post_title']];
        }
        if (str_contains($sql, $this->terms) && str_contains($sql, $this->term_taxonomy)) {
            $id = (int) $args[0];
            $row = $this->termsById[$id] ?? null;
            if ($row === null) {
                return null;
            }
            return ['term_id' => $id, 'name' => $row['name'], 'taxonomy' => $row['taxonomy']];
        }
        throw new \RuntimeException("FakeWpdb::get_row: unrecognized query shape: $sql");
    }

    private function unwrap($prepared): array {
        if (is_array($prepared) && ($prepared['__prepared'] ?? false)) {
            return [$prepared['sql'], $prepared['args']];
        }
        return [(string) $prepared, []];
    }
}

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

// ----------------------------------------------------------- engine + fixtures

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Review/Pending.php';
require __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require __DIR__ . '/../../../../agent/src/Review/Lint.php';
// DUO-3260: Tokens::queue_unscoped_url_query_ref() calls Capture::
// classify_unscoped_ref() directly (public static, itself built on the
// zero-instance-dependency ref_target_type() -- see both docblocks)
// rather than duplicating the query shapes it encapsulates. Loading the
// class definition only; nothing here ever instantiates Capture or calls
// any of its other (WordPress-dependent) methods.
require __DIR__ . '/../../../../agent/src/Capture/Capture.php';

use Duo\Canon;
use Duo\Policy;
use Duo\Tokens;
use Duo\Lint;

const MAPPED_UUID = '01980000-0005-7000-8000-000000000001';
const MAPPED_ID = 701;
const MAPPED2_UUID = '01980000-0005-7000-8000-000000000002';
const MAPPED2_ID = 702;
const UNMAPPED_ID = 979; // never in identity or postsById -> genuinely DANGLING
const UNSCOPED_ID = 878; // never in identity, but a REAL row of an out-of-scope type -> UNSCOPED
const UNMINTED_ID = 434; // never in identity, but a REAL row of an IN-scope type -> neither (false-positive guard)

$wpdb->identity['post'] = [MAPPED_ID => MAPPED_UUID, MAPPED2_ID => MAPPED2_UUID];
$wpdb->postsById[UNSCOPED_ID] = ['post_type' => 'elementor_library', 'post_title' => 'A Real Elementor Template'];
$wpdb->postsById[UNMINTED_ID] = ['post_type' => 'page', 'post_title' => 'A Real But Unminted Page'];

$policy = new Policy(); // bare policy: post_types() defaults to ['post','page','attachment']

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function fresh_tokens(Policy $policy, bool $forceUnresolvedRefs = false): Tokens {
    $t = new Tokens();
    $t->policy = $policy;
    $t->forceUnresolvedRefs = $forceUnresolvedRefs;
    return $t;
}

// ======================================================================
// PART 1 — Tokens.php: url-query-ref capture/apply semantics
// ======================================================================
echo "\n== Tokens.php: url-query-ref capture/apply semantics ==\n";

// Q1 — mapped ?p=N on a {{home}}-anchored URL: rewritten to a token, home
// URL itself also correctly tokenized in the same pass.
$tokens = fresh_tokens($policy);
$q1out = $tokens->tokenize_text('See <a href="http://example.test/?p=' . MAPPED_ID . '">this</a>.');
check($q1out === 'See <a href="{{home}}/?p={{post:' . MAPPED_UUID . '}}">this</a>.',
    'Q1: mapped ?p= rewritten to token inside a {{home}}-anchored href (got: ' . $q1out . ')');
check($tokens->warnings === [], 'Q1: no warnings on a clean mapped capture');

// Q2 — page_id and attachment_id both work identically.
$tokens = fresh_tokens($policy);
$q2a = $tokens->tokenize_text('http://example.test/?page_id=' . MAPPED_ID);
check($q2a === '{{home}}/?page_id={{post:' . MAPPED_UUID . '}}', "Q2a: page_id rewritten (got: $q2a)");
$q2b = $tokens->tokenize_text('http://example.test/?attachment_id=' . MAPPED_ID);
check($q2b === '{{home}}/?attachment_id={{post:' . MAPPED_UUID . '}}', "Q2b: attachment_id rewritten (got: $q2b)");

// Q3 — dangling (id exists nowhere): dropped with a warning, byte-clean
// removal of the whole separator+param+value span.
$tokens = fresh_tokens($policy);
$q3out = $tokens->tokenize_text('http://example.test/?p=' . UNMAPPED_ID);
check($q3out === '{{home}}/', "Q3: dangling ?p= dropped cleanly, only the {{home}}/ prefix survives (got: $q3out)");
$w = implode(' | ', $tokens->warnings);
check(str_contains($w, 'p=' . UNMAPPED_ID) && str_contains($w, 'dangling'), "Q3: warning names the param and id (got: $w)");
check($tokens->unscopedUrlQueryRefs === [], 'Q3: genuinely dangling id never queues as unscoped');

// Q4 — UNSCOPED: id names a REAL row of an out-of-scope type -- uniform
// drop (same as dangling), but ALSO queues onto $unscopedUrlQueryRefs.
$tokens = fresh_tokens($policy);
$q4out = $tokens->tokenize_text('http://example.test/?p=' . UNSCOPED_ID, "page 'about-us'");
check($q4out === '{{home}}/', "Q4: attribute still dropped for the unscoped ref (got: $q4out)");
check(count($tokens->unscopedUrlQueryRefs) === 1, 'Q4: exactly one unscoped violation queued (got: ' . json_encode($tokens->unscopedUrlQueryRefs) . ')');
$r4 = $tokens->unscopedUrlQueryRefs[0] ?? [];
check(($r4['context'] ?? null) === "page 'about-us'", 'Q4: violation names the context label passed in (got: ' . json_encode($r4) . ')');
check(($r4['param'] ?? null) === 'p', 'Q4: violation names the param');
check(($r4['id'] ?? null) === UNSCOPED_ID, 'Q4: violation names the raw id');
check(($r4['target_type'] ?? null) === 'elementor_library', 'Q4: violation names the real target type');

// Q5 — --force-unresolved-refs bypasses the QUEUE, not the drop.
$tokens = fresh_tokens($policy, true);
$q5out = $tokens->tokenize_text('http://example.test/?p=' . UNSCOPED_ID);
check($q5out === '{{home}}/', 'Q5: attribute STILL dropped under --force-unresolved-refs');
check($tokens->unscopedUrlQueryRefs === [], 'Q5: --force-unresolved-refs suppresses the unscoped queue entirely');

// Q6 — false-positive guard: a REAL row of an IN-scope type ('page')
// simply not minted a uuid on THIS build must be treated identically to
// dangling, never queued -- task #73's own critical guard, ported a
// third time.
$tokens = fresh_tokens($policy);
$q6out = $tokens->tokenize_text('http://example.test/?page_id=' . UNMINTED_ID);
check($q6out === '{{home}}/', 'Q6: in-scope-but-unminted ref dropped (uniform drop)');
check($tokens->unscopedUrlQueryRefs === [], 'Q6: in-scope-but-unminted NEVER queues as unscoped -- the false-positive guard');

// Q7 — THE critical safety property: an EXTERNAL url with an unrelated
// ?p= parameter (any third-party site using the same common name) is
// NEVER touched -- no {{home}} anchor, so no rewrite, no warning, no
// queue, byte-identical passthrough.
$tokens = fresh_tokens($policy);
$q7in = 'See https://totallydifferent.example/?p=' . UNMAPPED_ID . ' for details.';
$q7out = $tokens->tokenize_text($q7in);
check($q7out === $q7in, "Q7: external URL's own ?p= is completely untouched (got: $q7out)");
check($tokens->warnings === [], 'Q7: no warnings for an external URL');
check($tokens->unscopedUrlQueryRefs === [], 'Q7: no unscoped queue entry for an external URL');

// Q8 — ?page=N (no underscore, WordPress's own PAGINATION var) is never
// mistaken for ?page_id=N -- deliberately excluded, confirmed distinct.
$tokens = fresh_tokens($policy);
$q8out = $tokens->tokenize_text('http://example.test/some-post/?page=2');
check($q8out === '{{home}}/some-post/?page=2', "Q8: ?page= (pagination) left completely untouched, never rewritten (got: $q8out)");

// Q9 — multiple params, only one a ref: the OTHER params and separators
// survive byte-for-byte; only the declared ref param's value changes.
$tokens = fresh_tokens($policy);
$q9out = $tokens->tokenize_text('http://example.test/?foo=bar&p=' . MAPPED_ID . '&baz=qux');
check($q9out === '{{home}}/?foo=bar&p={{post:' . MAPPED_UUID . '}}&baz=qux',
    "Q9: only the declared ?p= param rewritten, foo/baz survive byte-for-byte (got: $q9out)");

// Q10 — apply-direction round trip: a captured token resolves back to
// its original numeric id.
$tokens = fresh_tokens($policy);
$q10cap = $tokens->tokenize_text('http://example.test/?p=' . MAPPED_ID);
$q10out = $tokens->detokenize_text($q10cap);
check($q10out === 'http://example.test/?p=' . MAPPED_ID, "Q10: capture-then-apply round trip restores the original numeric id (got: $q10out)");

// Q11 — apply-direction throws on an unresolvable token (a uuid that
// resolves nowhere in this fixture's identity map) -- matching every
// other apply-direction ref restore in this codebase, no soft fallback.
$tokens = fresh_tokens($policy);
$q11in = '{{home}}/?p={{post:00000000-0000-7000-8000-000000000000}}';
$threw = false;
try {
    $tokens->detokenize_text($q11in);
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'unresolvable');
}
check($threw, 'Q11: apply direction throws on an unresolvable token, matching every other ref restore');

// Q12 — determinism: capturing the SAME raw content twice is byte-identical.
$tokens = fresh_tokens($policy);
$q12in = 'http://example.test/?p=' . UNMAPPED_ID;
$q12a = $tokens->tokenize_text($q12in);
$tokens->warnings = [];
$q12b = $tokens->tokenize_text($q12in);
check($q12a === $q12b, 'Q12: capture-twice on the same raw (unmapped-ref) content is byte-identical');

// Q13 — CSV-shaped-looking multi-id text is NOT touched (only a real
// {{home}}-anchored ?p=/?page_id=/?attachment_id= is a declared shape --
// this mechanism is not a blind sweep for any digit near a '?').
$tokens = fresh_tokens($policy);
$q13out = $tokens->tokenize_text('http://example.test/?foo=' . UNMAPPED_ID);
check($q13out === '{{home}}/?foo=' . UNMAPPED_ID, "Q13: an undeclared query param ('foo') is never touched, even {{home}}-anchored (got: $q13out)");

// Q14-Q17 -- the throw-guard (PR #53 review: Tokens::$policy/
// $forceUnresolvedRefs as instance properties only prevents "silently
// forgot to configure this" if an unconfigured instance FAILS LOUDLY the
// moment it would need $policy, not just "happens not to be hit today".
// A bare `new Tokens()` (no ->policy set, no ->forceUnresolvedRefs set --
// exactly the shape of a hypothetical future capture-direction caller
// that forgets, the same shape Apply.php's own instance has, though the
// Apply.php trace proved it never reaches tokenize_text() at all) stands
// in for that caller here.
$bareTokens = new Tokens();
check($bareTokens->policy === null, 'Q14 setup: sanity -- a bare `new Tokens()` really has $policy === null');

// Q14 -- an id that resolves cleanly (MAPPED_ID) never needs
// classification, so the guard must NOT fire even on a totally
// unconfigured instance: tokenize_text() staying usable for its
// home/uploads-substitution role (and this mechanism's own happy path)
// on instances that never touch Policy is the entire reason the guard is
// scoped to queue_unscoped_url_query_ref() and not tokenize_text()
// itself (see Tokens::$policy's own docblock for the two already-shipped
// suites -- regress_block_refs.php, regress_shortcode_refs.php -- that
// depend on exactly this).
$q14out = $bareTokens->tokenize_text('http://example.test/?p=' . MAPPED_ID);
check($q14out === '{{home}}/?p={{post:' . MAPPED_UUID . '}}',
    "Q14: a MAPPED ref on an unconfigured (\$policy===null) instance resolves normally, no throw (got: $q14out)");

// Q15 -- plain content with no query-ref pattern at all must never touch
// the guard either (proves the shared tokenize_text() entry point stays
// open for its home/uploads role regardless of $policy).
$q15out = $bareTokens->tokenize_text('Just some ordinary text, no query string here at all.');
check($q15out === 'Just some ordinary text, no query string here at all.',
    'Q15: plain non-URL text on an unconfigured instance passes through untouched, no throw');

// Q16 -- an UNSCOPED id (a real row of an out-of-scope type) on an
// unconfigured instance: id_to_token() fails to resolve it exactly like
// Q5's already-configured case, but this time there is no Policy to run
// the dangling-vs-unscoped classification at all -- must throw rather
// than silently guessing "dangling" (silently dropping a reference to a
// real, out-of-scope row is precisely the task #73 defect class this
// mechanism exists to prevent).
$q16threw = null;
try {
    $bareTokens->tokenize_text('http://example.test/?p=' . UNSCOPED_ID);
} catch (\RuntimeException $e) {
    $q16threw = $e;
}
check($q16threw !== null, 'Q16: an unresolved ref needing classification on an unconfigured instance throws');
check($q16threw !== null && str_contains($q16threw->getMessage(), 'policy'),
    'Q16: the thrown message names the missing $policy as the cause (got: '
    . ($q16threw !== null ? $q16threw->getMessage() : '(no exception)') . ')');

// Q17 -- Q16's twin for the OTHER sub-case: a genuinely DANGLING id (no
// real row anywhere, UNMAPPED_ID) on an unconfigured instance ALSO
// throws. This is deliberate, not overreach: classify_unscoped_ref()
// itself is what tells dangling apart from unscoped, and it needs
// $policy to do that -- an unconfigured instance can't safely assume
// "unresolved must mean dangling" any more than it can assume "must mean
// unscoped", so BOTH sub-cases of "unresolved, no way to classify" must
// refuse identically.
$q17threw = null;
try {
    $bareTokens->tokenize_text('http://example.test/?p=' . UNMAPPED_ID);
} catch (\RuntimeException $e) {
    $q17threw = $e;
}
check($q17threw !== null, 'Q17: a genuinely dangling ref on an unconfigured instance ALSO throws (not just the unscoped sub-case)');

$tokenSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Grammar/Tokens.php');
check(str_contains($tokenSource, 'UrlQueryReferenceCodec::capture('), 'Q18: Tokens delegates query-reference capture to the codec');
check(str_contains($tokenSource, 'UrlQueryReferenceCodec::apply('), 'Q18: Tokens delegates query-reference apply to the codec');
check(!str_contains($tokenSource, 'private function tokenize_url_query_refs'), 'Q18: Tokens no longer owns the query-reference capture implementation');
check(!str_contains($tokenSource, 'private function detokenize_url_query_refs'), 'Q18: Tokens no longer owns the query-reference apply implementation');

// ======================================================================
// PART 2 — Lint.php: unrewritten_url_query_ref
// ======================================================================
echo "\n== Lint.php: unrewritten_url_query_ref ==\n";

$stateDir = sys_get_temp_dir() . '/duo_regress_url_query_refs_' . bin2hex(random_bytes(4));
register_shutdown_function(fn() => rrmdir($stateDir));

function write_fixture_post(string $stateDir, string $slug, string $uuid, string $body, array $meta = []): string {
    $front = [
        'uuid' => $uuid, 'type' => 'post', 'slug' => $slug, 'title' => $slug,
        'status' => 'publish', 'meta' => (object) $meta,
    ];
    $path = "$stateDir/posts/post/$uuid--$slug.md";
    Canon::write_file($path, Canon::post_file($front, $body));
    return "posts/post/$uuid--$slug.md";
}

// L1 -- raw ?p= survivor in the body: finding fires.
$l1path = write_fixture_post($stateDir, 'l1-raw-body', '01980000-0006-7000-8000-000000000001',
    'Link: http://old-site.example/?p=' . UNMAPPED_ID);

// L2 -- raw ?page_id= survivor in meta: finding fires.
$l2path = write_fixture_post($stateDir, 'l2-raw-meta', '01980000-0006-7000-8000-000000000002',
    'body text', ['duo_related' => 'see ?page_id=' . UNMAPPED_ID . ' for more']);

// L3 -- already-tokenized: no finding.
$l3path = write_fixture_post($stateDir, 'l3-tokenized', '01980000-0006-7000-8000-000000000003',
    'Link: {{home}}/?p={{post:' . MAPPED_UUID . '}}');

// L4 -- ?page=N (pagination, no underscore): never flagged, not a ref shape at all.
$l4path = write_fixture_post($stateDir, 'l4-pagination', '01980000-0006-7000-8000-000000000004',
    'Paged: http://example.test/?page=2');

// L5 -- two raw survivors in one body: two findings, precisely located.
$l5path = write_fixture_post($stateDir, 'l5-two-raw', '01980000-0006-7000-8000-000000000005',
    'First ?p=' . UNMAPPED_ID . ' and second ?attachment_id=988');

$findings = Lint::scan_tree($stateDir, $policy);
$byPath = [];
foreach ($findings as $f) {
    $byPath[$f['path']][] = $f;
}

$l1 = $byPath[$l1path] ?? [];
check(count($l1) === 1, 'L1: raw ?p= in body -> exactly one finding (got ' . count($l1) . ')');
if (count($l1) === 1) {
    check($l1[0]['class'] === 'unrewritten_url_query_ref', 'L1: finding class is unrewritten_url_query_ref (got: ' . $l1[0]['class'] . ')');
    check($l1[0]['locator'] === 'body[url_query:0]', 'L1: locator is body[url_query:0] (got: ' . $l1[0]['locator'] . ')');
    check($l1[0]['value'] === UNMAPPED_ID, 'L1: value is the raw int (got: ' . json_encode($l1[0]['value']) . ')');
    check(!isset($l1[0]['matches']), 'L1: matches is absent (this id does not resolve to a live entity in this fixture)');
}

$l2 = $byPath[$l2path] ?? [];
check(count($l2) === 1, 'L2: raw ?page_id= in meta -> exactly one finding (got ' . count($l2) . ')');
if (count($l2) === 1) {
    check($l2[0]['locator'] === 'meta.duo_related[url_query:0]', 'L2: locator names the meta key (got: ' . $l2[0]['locator'] . ')');
}

check(count($byPath[$l3path] ?? []) === 0, 'L3: already-tokenized -> zero findings (got: ' . json_encode($byPath[$l3path] ?? []) . ')');
check(count($byPath[$l4path] ?? []) === 0, 'L4: ?page= (pagination) -> zero findings, never mistaken for a ref (got: ' . json_encode($byPath[$l4path] ?? []) . ')');

$l5 = $byPath[$l5path] ?? [];
check(count($l5) === 2, 'L5: two raw survivors in one body -> exactly two findings (got ' . count($l5) . ')');
if (count($l5) === 2) {
    check($l5[0]['locator'] === 'body[url_query:0]', 'L5: first finding locates index 0 (got: ' . $l5[0]['locator'] . ')');
    check($l5[1]['locator'] === 'body[url_query:1]', 'L5: second finding locates index 1 (got: ' . $l5[1]['locator'] . ')');
    check($l5[0]['value'] === UNMAPPED_ID, 'L5: first value correct');
    check($l5[1]['value'] === 988, 'L5: second value correct');
}

check(count($findings) === 4, 'sanity: exactly 4 findings total across all 5 fixtures (L1 + L2 + L5x2) -- got ' . count($findings) . ': ' . json_encode(array_column($findings, 'class')));

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
