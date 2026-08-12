<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * reference-hygiene fixes in the block and menu layers:
 *
 *  - agent/src/Lint.php's scan_blocks(): a registered block_attrs path used
 *    to be 100% exempt from the suspicious-ref check regardless of whether
 *    its value actually got rewritten. New finding class
 *    'unrewritten_registered_ref' catches a registered ref path whose
 *    captured value is still numeric.
 *  - agent/src/Lint.php's scan_tree(): menu files used to be skipped
 *    entirely. Menu item refs and plugin-owned meta are now scanned with
 *    their schema/policy-specific semantics.
 *  - agent/src/Lint.php's structured scan: numeric survivors at an exact
 *    json_refs path are caught even when the leaf key is a language slug,
 *    as in Polylang's nav_menus[theme][location][lang] shape.
 *  - agent/src/Blocks.php's walk(): an unmapped/dangling block ref used to
 *    keep the raw env-local id (`?? (int) $v`) instead of dropping it, the
 *    way options/post_meta refs already do (spec/repo-format.md "Dangling
 *    references"). Now drops (scalar: the whole attr key; array: just that
 *    element) with a warning naming block/attribute/id.
 *
 * Runs the REAL, unmodified agent/src/{Canon,Policy,Tokens,Ledger,Pending,
 * Blocks,Lint}.php against hand-built fixtures, with only two things
 * stubbed: WordPress's block-parser primitives (support/wp-block-parser-
 * stub.php — a verbatim vendored copy, see its own docblock) and a minimal
 * fake $wpdb (below) standing in for the two narrow, fixed-shape query
 * patterns Ledger::id_for()/uuid_for() and Pending::resolve_id() actually
 * issue. No database, no HTTP, no docker.
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

require __DIR__ . '/support/wp-block-parser-stub.php';

// ------------------------------------------------------------- fake $wpdb

/**
 * Stands in for exactly the query shapes Ledger::id_for()/uuid_for() and
 * Pending::resolve_id() issue (verified by reading agent/src/Ledger.php and
 * agent/src/Pending.php directly — both are single fixed-shape SELECTs, no
 * dynamic query building) — not a general SQL engine. prepare() returns a
 * small array token; get_var()/get_row() pattern-match the SQL text to
 * dispatch to in-memory fixture data instead of a real database.
 */
final class FakeWpdb {
    public $prefix = 'wp_';
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
        // DUO-3212: Capture::ref_target_type()'s two scalar lookups (real
        // row's own type, independent of whether it's minted a uuid) —
        // reuses the SAME $postsById/$termsById fixtures get_row() above
        // already has, since both are answering the same underlying
        // question ("does a real row exist, and what type is it") via a
        // different SQL shape.
        if (str_contains($sql, 'SELECT post_type FROM') && str_contains($sql, $this->posts)) {
            $id = (int) $args[0];
            $row = $this->postsById[$id] ?? null;
            if ($row === null) {
                return null;
            }
            if (($row['post_type'] ?? '') === 'revision' || ($row['post_status'] ?? 'publish') === 'auto-draft') {
                return null; // matches ref_target_type()'s own exclusion exactly
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

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Pending.php';
require __DIR__ . '/../../agent/src/JsonRefs.php';
require __DIR__ . '/../../agent/src/Tokens.php';
require __DIR__ . '/../../agent/src/Blocks.php';
require __DIR__ . '/../../agent/src/Lint.php';
// DUO-3212: Blocks::queue_unscoped() calls Capture::ref_target_type()
// directly (public static, zero instance dependency — see its own
// docblock) rather than duplicating the query shapes it encapsulates.
// Loading the class definition only; nothing here ever instantiates
// Capture or calls any of its other (WordPress-dependent) methods.
require __DIR__ . '/../../agent/src/Capture.php';
// DUO-3259: Blocks.php's $rewriteString closure now unconditionally calls
// Shortcodes::capture_rewrite_text()/apply_rewrite_text() too (the new
// integration point) -- the class must be loadable wherever Blocks.php
// is, exactly like Capture.php above. No support/wp-shortcode-stub.php
// require needed here: this file's own $policy fixture declares no
// shortcode_attrs at all, so Shortcodes' own early-exit (empty rules)
// fires before get_shortcode_regex() is ever called -- see regress_
// shortcode_refs.php for the harness that DOES exercise that path.
require_once __DIR__ . '/../../agent/src/Shortcodes.php';

use Duo\Canon;
use Duo\Policy;
use Duo\Tokens;
use Duo\Blocks;
use Duo\Lint;
use Duo\OptionState;

const MAPPED_UUID = '01980000-0001-7000-8000-000000000001';
const MAPPED_ID = 501;
const UNMAPPED_ID = 999; // never in $wpdb->identity, never in $wpdb->postsById -> genuinely DANGLING
const UNSCOPED_ID = 888; // never in $wpdb->identity, but a REAL row of an out-of-scope type -> UNSCOPED
const UNMINTED_ID = 444; // never in $wpdb->identity, but a REAL row of an IN-scope type -> neither (false-positive guard)

$wpdb->identity['post'] = [MAPPED_ID => MAPPED_UUID];
// A real, resolvable post for the UNREGISTERED-attr regression case (L4) —
// deliberately DOES resolve, contrasting with UNMAPPED_ID (deliberately
// does NOT resolve) so both branches of "fires regardless of whether the
// id resolves" get exercised across the two new/changed classes.
$wpdb->postsById[777] = ['post_type' => 'post', 'post_title' => 'Some Real Post'];
// DUO-3212: a real row of a type this fixture's bare `new Policy()` does
// NOT have in scope (post_types() falls back to its default ['post',
// 'page', 'attachment'] with no site.duo.json override here) — the exact
// UNSCOPED shape Capture::ref_target_type()'s own docblock uses as its
// worked example ("elementor_active_kit when elementor_library isn't in
// policy.post_types"), reused verbatim rather than inventing a new one.
$wpdb->postsById[UNSCOPED_ID] = ['post_type' => 'elementor_library', 'post_title' => 'A Real Elementor Template'];
// A real row of an IN-scope type ('page', already in the default list)
// that simply hasn't been minted a uuid on THIS build — task #73's own
// critical false-positive guard (Capture::queue_or_warn_unscoped()'s
// docblock), ported: must be treated identically to dangling, never queued.
$wpdb->postsById[UNMINTED_ID] = ['post_type' => 'page', 'post_title' => 'A Real But Unminted Page'];

$policy = new Policy();
$policy->manifests = [[
    'block_attrs' => [
        'core/image'   => [['kind' => 'post', 'path' => 'id', 'type' => 'int']],
        'core/gallery' => [['kind' => 'post', 'path' => 'ids', 'type' => 'int[]']],
        'core/query'   => [['lint_ok' => true, 'path' => 'queryId']],
    ],
    'post_meta' => [
        'menu_structured' => [
            'class' => 'authored',
            'json_refs' => [['kind' => 'post', 'path' => '$.owner_id']],
        ],
        'menu_direct_ref' => ['class' => 'authored', 'ref' => 'post'],
    ],
    'options' => [
        'polylang' => [
            'class' => 'env',
            'sub_keys' => [
                'nav_menus' => [
                    'class' => 'authored',
                    'json_refs' => [['kind' => 'term', 'path' => '$.*.*.*']],
                    'key_refs' => ['kind' => 'term', 'path' => '$.by_term'],
                ],
            ],
        ],
    ],
]];
$tokens = new Tokens();

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

// ======================================================================
// PART 1 — Blocks.php: dangling block refs must drop, not keep-as-raw-int
// ======================================================================
echo "\n== Blocks.php: dangling ref drop semantics ==\n";

// B1 — mapped scalar: id_to_token succeeds, attrs.id becomes the token.
$b1in = '<!-- wp:image {"id":501,"sizeSlug":"large"} -->' . "\n"
    . '<figure class="wp-block-image size-large"><img src="http://example.test/wp-content/uploads/cat.jpg" class="wp-image-501" alt=""/></figure>' . "\n"
    . '<!-- /wp:image -->';
$tokens->warnings = [];
$b1out = Blocks::capture_rewrite($b1in, $policy, $tokens);
$b1blocks = parse_blocks($b1out);
check(($b1blocks[0]['attrs']['id'] ?? null) === '{{post:' . MAPPED_UUID . '}}', 'B1: mapped scalar id rewritten to token');
check(str_contains($b1out, 'wp-image-{{post:' . MAPPED_UUID . '}}'), 'B1: wp-image-N class ALSO rewritten (same ledger entry, independent mechanism)');
check($tokens->warnings === [], 'B1: no warnings on a clean mapped capture (got: ' . json_encode($tokens->warnings) . ')');

// B2 — unmapped scalar: must DROP the attribute key entirely (the fix),
// not keep raw int 999 the way `?? (int) $v` used to.
$b2in = '<!-- wp:image {"id":999} --><figure class="wp-block-image"></figure><!-- /wp:image -->';
$tokens->warnings = [];
$b2out = Blocks::capture_rewrite($b2in, $policy, $tokens);
$b2blocks = parse_blocks($b2out);
check(!array_key_exists('id', $b2blocks[0]['attrs']), 'B2: unmapped scalar ref -- attrs.id key is ABSENT (dropped), not left as raw 999');
check(!str_contains($b2out, '"id"'), 'B2: serialized block markup contains no "id" attribute at all');
$w = implode(' | ', $tokens->warnings);
check(str_contains($w, 'core/image') && str_contains($w, "'id'") && str_contains($w, '999'), "B2: warning names block/attribute/id (got: $w)");

// B3 — mixed array: element 0 mapped (kept as token), element 1 unmapped
// (dropped) -- element-level drop, not whole-key drop, for int[] refs.
$b3in = '<!-- wp:gallery {"ids":[501,999]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->';
$tokens->warnings = [];
$b3out = Blocks::capture_rewrite($b3in, $policy, $tokens);
$b3blocks = parse_blocks($b3out);
check(($b3blocks[0]['attrs']['ids'] ?? null) === ['{{post:' . MAPPED_UUID . '}}'], 'B3: gallery ids -- mapped element kept as token, unmapped element dropped (not the whole key)');
$w = implode(' | ', $tokens->warnings);
check(str_contains($w, 'core/gallery') && str_contains($w, 'ids') && str_contains($w, '999'), "B3: warning names block/attribute/id for the dropped array element (got: $w)");

// B4 — knock-on effect (a): does dropping attrs.id leave the SEPARATE
// wp-image-N innerHTML class rewrite half-applied? The two mechanisms are
// independent (attrs.id via the block_attrs rule loop; wp-image-N via its
// own regex + its own id_to_token() call on the class's digits) -- confirm
// they don't interfere with each other's control flow. DUO-3212 closed the
// asymmetry this case used to document: the class rewrite now drops on
// unmapped too, matching attrs.id exactly (previously it fail-OPEN, leaking
// the raw digits unchanged into canonical state).
$b4in = '<!-- wp:image {"id":999} -->' . "\n"
    . '<figure class="wp-block-image"><img src="http://example.test/wp-content/uploads/dog.jpg" class="wp-image-999" alt=""/></figure>' . "\n"
    . '<!-- /wp:image -->';
$tokens->warnings = [];
$b4out = Blocks::capture_rewrite($b4in, $policy, $tokens);
$b4blocks = parse_blocks($b4out);
check(!array_key_exists('id', $b4blocks[0]['attrs']), 'B4: attrs.id dropped for the unmapped core/image (same as B2)');
check(!str_contains($b4blocks[0]['innerHTML'], 'wp-image-999'), 'B4: wp-image-999 class in innerHTML is now DROPPED, not left unchanged (DUO-3212 closes the fail-open)');
check(!str_contains($b4blocks[0]['innerHTML'], 'wp-image-{{'), 'B4: class was definitely not (even partially) tokenized');
check(str_contains($b4blocks[0]['innerHTML'], 'class=""'), 'B4: class attribute is empty, not malformed, once its sole class is dropped (got: ' . $b4blocks[0]['innerHTML'] . ')');
$w = implode(' | ', $tokens->warnings);
check(str_contains($w, 'wp-image-999') && str_contains($w, 'core/image'), "B4: a warning names the dropped wp-image-999 class and its block (got: $w)");
check($tokens->unscopedBlockRefs === [], 'B4: id 999 is genuinely dangling (no row anywhere) -- neither drop queues an unscoped violation (got: ' . json_encode($tokens->unscopedBlockRefs) . ')');

// B7 — UNSCOPED scalar ref: id_to_token() fails the SAME way a dangling
// ref does (attrs.id still drops, uniform treatment, matching task #73's
// own posture for options exactly), but because the id names a REAL row
// of an out-of-scope type (elementor_library), it ALSO queues onto
// Tokens::$unscopedBlockRefs for Capture::build()'s batched abort --
// Blocks.php itself never throws; queuing is as far as this layer goes.
$tokens->warnings = [];
$tokens->unscopedBlockRefs = [];
$b7in = '<!-- wp:image {"id":' . UNSCOPED_ID . '} --><figure class="wp-block-image"></figure><!-- /wp:image -->';
$b7out = Blocks::capture_rewrite($b7in, $policy, $tokens, false, "page 'about-us'");
$b7blocks = parse_blocks($b7out);
check(!array_key_exists('id', $b7blocks[0]['attrs']), 'B7: attrs.id still dropped for the unscoped ref (uniform drop, same as dangling)');
$w = implode(' | ', $tokens->warnings);
check(str_contains($w, (string) UNSCOPED_ID) && str_contains($w, 'core/image'), "B7: the uniform dangling-style warning still fires too (got: $w)");
check(count($tokens->unscopedBlockRefs) === 1, 'B7: exactly one unscoped violation queued (got: ' . json_encode($tokens->unscopedBlockRefs) . ')');
$r7 = $tokens->unscopedBlockRefs[0] ?? [];
check(($r7['post'] ?? null) === "page 'about-us'", 'B7: violation names the post via the postLabel Capture::build_post() would pass (got: ' . json_encode($r7) . ')');
check(($r7['block'] ?? null) === 'core/image', 'B7: violation names the block');
check(($r7['attr'] ?? null) === 'id', 'B7: violation names the attribute path');
check(($r7['kind'] ?? null) === 'post', 'B7: violation names the ref kind');
check(($r7['id'] ?? null) === UNSCOPED_ID, 'B7: violation names the raw id');
check(($r7['target_type'] ?? null) === 'elementor_library', 'B7: violation names the real target type Capture::ref_target_type() found');

// B8 — --force-unresolved-refs bypasses the QUEUE, not the drop: matching
// Capture::queue_or_warn_unscoped()'s own $force short-circuit exactly.
$tokens->warnings = [];
$tokens->unscopedBlockRefs = [];
$b8out = Blocks::capture_rewrite($b7in, $policy, $tokens, true, "page 'about-us'");
$b8blocks = parse_blocks($b8out);
check(!array_key_exists('id', $b8blocks[0]['attrs']), 'B8: attrs.id is STILL dropped under --force-unresolved-refs (force changes reporting, not the drop)');
check($tokens->unscopedBlockRefs === [], 'B8: --force-unresolved-refs suppresses the unscoped queue entirely (got: ' . json_encode($tokens->unscopedBlockRefs) . ')');

// B9 — the critical false-positive guard (task #73's own, ported): a REAL
// row of an IN-scope type ('page') that simply has no ledger uuid minted
// on THIS build must be treated identically to dangling, never queued --
// this is the exact fresh-target-environment shape (every entity is
// "unmapped" before its own first capture) that would otherwise hard-
// abort capture on an ordinary, correctly-configured site.
$tokens->warnings = [];
$tokens->unscopedBlockRefs = [];
$b9in = '<!-- wp:image {"id":' . UNMINTED_ID . '} --><figure class="wp-block-image"></figure><!-- /wp:image -->';
$b9out = Blocks::capture_rewrite($b9in, $policy, $tokens);
$b9blocks = parse_blocks($b9out);
check(!array_key_exists('id', $b9blocks[0]['attrs']), 'B9: attrs.id dropped for the in-scope-but-unminted ref (uniform drop)');
check($tokens->unscopedBlockRefs === [], 'B9: in-scope-but-unminted NEVER queues as unscoped -- the false-positive guard (got: ' . json_encode($tokens->unscopedBlockRefs) . ')');

// B10 — wp-image-N participates in the SAME triage as attrs.id (closing
// the asymmetry B4 used to document all the way, not just the drop half).
$tokens->warnings = [];
$tokens->unscopedBlockRefs = [];
$b10in = '<!-- wp:image {} -->' . "\n"
    . '<figure class="wp-block-image"><img src="http://example.test/wp-content/uploads/x.jpg" class="wp-image-' . UNSCOPED_ID . '" alt=""/></figure>' . "\n"
    . '<!-- /wp:image -->';
$b10out = Blocks::capture_rewrite($b10in, $policy, $tokens, false, "post 'hello-world'");
$b10blocks = parse_blocks($b10out);
check(!str_contains($b10blocks[0]['innerHTML'], (string) UNSCOPED_ID), "B10: wp-image-" . UNSCOPED_ID . " class dropped for the unscoped ref");
check(count($tokens->unscopedBlockRefs) === 1, 'B10: wp-image-N class ALSO queues an unscoped violation (got: ' . json_encode($tokens->unscopedBlockRefs) . ')');
$r10 = $tokens->unscopedBlockRefs[0] ?? [];
check(($r10['attr'] ?? null) === 'wp-image-class', 'B10: violation identifies the wp-image-class mechanism specifically, distinct from attrs.id (got: ' . json_encode($r10) . ')');
check(($r10['target_type'] ?? null) === 'elementor_library', 'B10: violation names the real target type');

// B11 — array-ref parity: an unscoped id inside an int[] ref (core/gallery)
// gets the SAME triage as a scalar ref, at the specific array index --
// task #73's own "scalar and array refs must not diverge in severity"
// acceptance criterion, ported.
$tokens->warnings = [];
$tokens->unscopedBlockRefs = [];
$b11in = '<!-- wp:gallery {"ids":[501,' . UNSCOPED_ID . ']} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->';
$b11out = Blocks::capture_rewrite($b11in, $policy, $tokens);
$b11blocks = parse_blocks($b11out);
check(($b11blocks[0]['attrs']['ids'] ?? null) === ['{{post:' . MAPPED_UUID . '}}'], 'B11: gallery ids -- mapped element kept, unscoped element dropped (same shape as B3)');
check(count($tokens->unscopedBlockRefs) === 1, 'B11: exactly one unscoped violation queued for the array element (got: ' . json_encode($tokens->unscopedBlockRefs) . ')');
$r11 = $tokens->unscopedBlockRefs[0] ?? [];
check(($r11['attr'] ?? null) === 'ids[1]', 'B11: violation locates the SPECIFIC array index, matching Lint::scan_blocks()\'s own locator precision (got: ' . json_encode($r11) . ')');

// B5 — apply-side tolerance: detokenizing content whose ref attr was
// dropped at capture must not throw, and the attribute stays absent
// (Apply::finalize_post()'s Blocks::apply_rewrite() call path).
$tokens->warnings = [];
$b5out = Blocks::apply_rewrite($b2out, $policy, $tokens);
$b5blocks = parse_blocks($b5out);
check(!array_key_exists('id', $b5blocks[0]['attrs']), 'B5: apply_rewrite() on a dropped-attr block does not resurrect or crash on the missing "id"');

// B6 — determinism: capturing the SAME raw content twice is byte-identical
// (both the successful and the dropped case), matching spec/repo-format.md's
// "capturing the same site twice must produce byte-identical trees".
$tokens->warnings = [];
$b6a = Blocks::capture_rewrite($b2in, $policy, $tokens);
$tokens->warnings = [];
$b6b = Blocks::capture_rewrite($b2in, $policy, $tokens);
check($b6a === $b6b, 'B6: capture-twice on the same raw (unmapped-ref) content is byte-identical');

// ======================================================================
// PART 2 — Lint.php: unrewritten_registered_ref
// ======================================================================
echo "\n== Lint.php: unrewritten_registered_ref ==\n";

$stateDir = sys_get_temp_dir() . '/duo_regress_block_refs_' . bin2hex(random_bytes(4));
register_shutdown_function(fn() => rrmdir($stateDir));

function write_fixture_post(string $stateDir, string $slug, string $uuid, string $body): string {
    $front = [
        'uuid' => $uuid, 'type' => 'post', 'slug' => $slug, 'title' => $slug,
        'status' => 'publish', 'meta' => (object) [],
    ];
    $path = "$stateDir/posts/post/$uuid--$slug.md";
    Canon::write_file($path, Canon::post_file($front, $body));
    return "posts/post/$uuid--$slug.md";
}

// L1 -- registered path + already-tokenized value: no finding.
$l1path = write_fixture_post($stateDir, 'l1-registered-token', '01980000-0002-7000-8000-000000000001',
    '<!-- wp:image {"id":"{{post:' . MAPPED_UUID . '}}","sizeSlug":"large"} -->' . "\n"
    . '<figure class="wp-block-image"></figure>' . "\n<!-- /wp:image -->");

// L2 -- registered path + raw int survivor: THE fix's new finding.
$l2path = write_fixture_post($stateDir, 'l2-registered-raw', '01980000-0002-7000-8000-000000000002',
    '<!-- wp:image {"id":999} --><figure class="wp-block-image"></figure><!-- /wp:image -->');

// L3 -- registered path + lint_ok: no finding (the sanctioned exemption).
$l3path = write_fixture_post($stateDir, 'l3-lint-ok', '01980000-0002-7000-8000-000000000003',
    '<!-- wp:query {"queryId":5} /-->');

// L4 -- UNREGISTERED id-shaped attr: existing finding, must be unaffected.
$l4path = write_fixture_post($stateDir, 'l4-unregistered', '01980000-0002-7000-8000-000000000004',
    '<!-- wp:fake/widget {"id":777} /-->');

// L5 -- array with exactly one raw (unmapped-shaped) element.
$l5path = write_fixture_post($stateDir, 'l5-array-one-raw', '01980000-0002-7000-8000-000000000005',
    '<!-- wp:gallery {"ids":["{{post:' . MAPPED_UUID . '}}",999]} -->'
    . '<figure class="wp-block-gallery"></figure><!-- /wp:gallery -->');

// M1/M2 -- menus are not post files, but item meta uses the same post_meta
// policy. Prove custom numeric-looking URLs stay URLs; typed refs must be
// canonical tokens; direct declared refs are exempt; and a structured rule
// keeps scanning id-shaped positions its own json_refs path did not cover.
$menuRel = 'menus/01980000-0003-7000-8000-000000000001--primary.json';
Canon::write_file($stateDir . '/' . $menuRel, Canon::encode([
    'uuid' => '01980000-0003-7000-8000-000000000001',
    'name' => 'Primary',
    'slug' => 'primary',
    'locations' => [],
    'items' => [
        [
            'uuid' => '01980000-0003-7000-8000-000000000002',
            'type' => 'custom',
            'object' => 'custom',
            'ref' => '777',
            'meta' => [
                'plain_menu_value' => 777,
                'menu_direct_ref' => '{{post:' . MAPPED_UUID . '}}',
            ],
            'parent' => null,
            'position' => 1,
            'title' => 'Custom',
        ],
        [
            'uuid' => '01980000-0003-7000-8000-000000000003',
            'type' => 'post_type',
            'object' => 'post',
            'ref' => '999',
            'meta' => [
                'menu_structured' => [
                    'owner_id' => '{{post:' . MAPPED_UUID . '}}',
                    'related_id' => 777,
                ],
            ],
            'parent' => null,
            'position' => 2,
            'title' => 'Post',
        ],
        [
            'uuid' => '01980000-0003-7000-8000-000000000004',
            'type' => 'custom',
            'object' => 'custom',
            'ref' => 'http:\/\/example.test\/environment-bound',
            'meta' => [],
            'parent' => null,
            'position' => 3,
            'title' => 'Escaped URL',
        ],
    ],
]));

// S1 -- exact json_refs match under a NON-id-shaped leaf key. The old deep
// scanner only recognized owner_id/related_id-style names and therefore
// missed this real Polylang shape completely.
Canon::write_file($stateDir . '/options/core.json', Canon::encode(OptionState::document([
    'polylang' => OptionState::present([
        'nav_menus' => [
            'twentytwentyone' => [
                'primary' => [
                    'en' => '{{term:01980000-0004-7000-8000-000000000001}}',
                    'de' => 999,
                ],
            ],
            'by_term' => [999 => 'raw-key-survivor'],
        ],
    ], 'yes'),
])));

$findings = Lint::scan_tree($stateDir, $policy);
$byPath = [];
foreach ($findings as $f) {
    $byPath[$f['path']][] = $f;
}

check(count($byPath[$l1path] ?? []) === 0, 'L1: registered path + token value -> zero findings (got: ' . json_encode($byPath[$l1path] ?? []) . ')');

$l2 = $byPath[$l2path] ?? [];
check(count($l2) === 1, 'L2: registered path + raw int -> exactly one finding (got ' . count($l2) . ')');
if (count($l2) === 1) {
    check($l2[0]['class'] === 'unrewritten_registered_ref', 'L2: finding class is unrewritten_registered_ref (got: ' . $l2[0]['class'] . ')');
    check($l2[0]['locator'] === 'blocks.core/image.attrs.id', 'L2: locator is blocks.core/image.attrs.id (got: ' . $l2[0]['locator'] . ')');
    check($l2[0]['value'] === 999, 'L2: value is the raw int 999 (got: ' . json_encode($l2[0]['value']) . ')');
    check(!isset($l2[0]['matches']), 'L2: matches is absent (999 does not resolve to a live entity in this fixture) -- confirms the finding fires regardless of resolution');
}

check(count($byPath[$l3path] ?? []) === 0, 'L3: registered path + lint_ok -> zero findings (the sanctioned exemption still exempts) (got: ' . json_encode($byPath[$l3path] ?? []) . ')');

$l4 = $byPath[$l4path] ?? [];
check(count($l4) === 1, 'L4 (regression): unregistered id-shaped attr -> exactly one finding, unaffected by the fix (got ' . count($l4) . ')');
if (count($l4) === 1) {
    check($l4[0]['class'] === 'unregistered_block_attr', 'L4: finding class is (still) unregistered_block_attr (got: ' . $l4[0]['class'] . ')');
    check($l4[0]['locator'] === 'blocks.fake/widget.attrs.id', 'L4: locator is blocks.fake/widget.attrs.id (got: ' . $l4[0]['locator'] . ')');
    check(($l4[0]['matches']['id'] ?? null) === 777, 'L4: matches a real resolvable post (id 777) -- unregistered_block_attr still resolves when it can');
}

$l5 = $byPath[$l5path] ?? [];
check(count($l5) === 1, 'L5: array with one raw element -> exactly one finding for that element (got ' . count($l5) . ')');
if (count($l5) === 1) {
    check($l5[0]['class'] === 'unrewritten_registered_ref', 'L5: finding class is unrewritten_registered_ref (got: ' . $l5[0]['class'] . ')');
    check($l5[0]['locator'] === 'blocks.core/gallery.attrs.ids[1]', 'L5: locator points at the SPECIFIC raw element, index 1 (got: ' . $l5[0]['locator'] . ')');
    check($l5[0]['value'] === 999, 'L5: value is 999, the raw survivor (the token at index 0 is correctly untouched/not flagged)');
}

$menuFindings = $byPath[$menuRel] ?? [];
check(count($menuFindings) === 4, 'M1: menu file -> exactly four planted findings (got ' . count($menuFindings) . ': ' . json_encode($menuFindings) . ')');
$menuByLocator = [];
foreach ($menuFindings as $finding) {
    $menuByLocator[$finding['locator']] = $finding;
}
check(
    ($menuByLocator['items[0].meta.plain_menu_value']['class'] ?? null) === 'bare_id',
    'M2: plain menu-item meta is scanned like plain post meta'
);
check(
    ($menuByLocator['items[1].meta.menu_structured.related_id']['class'] ?? null) === 'bare_id',
    'M3: structured menu-item meta scans undeclared id-shaped positions deeply'
);
check(
    ($menuByLocator['items[1].ref']['class'] ?? null) === 'unrewritten_registered_ref',
    'M4: raw post_type menu ref is a registered-ref survivor'
);
check(
    !isset($menuByLocator['items[0].ref']),
    'M5: numeric-looking custom menu ref remains a URL, never an entity-id finding'
);
check(
    ($menuByLocator['items[2].ref']['class'] ?? null) === 'escaped_home',
    'M6: custom menu ref is treated as a URL and checked for escaped environment hosts'
);
check(
    !isset($menuByLocator['items[0].meta.menu_direct_ref'])
        && !isset($menuByLocator['items[1].meta.menu_structured.owner_id']),
    'M7: canonical direct/structured menu-meta refs stay lint-clean'
);

$optionFindings = $byPath['options/core.json'] ?? [];
check(count($optionFindings) === 2, 'S1: Polylang-shaped option -> exactly two declared value/key survivors (got ' . count($optionFindings) . ': ' . json_encode($optionFindings) . ')');
$optionByLocator = [];
foreach ($optionFindings as $finding) {
    $optionByLocator[$finding['locator']] = $finding;
}
$valueFinding = $optionByLocator['options.polylang.nav_menus.twentytwentyone.primary.de'] ?? null;
check(
    ($valueFinding['class'] ?? null) === 'unrewritten_registered_ref',
    'S2: declared json_refs survivor uses unrewritten_registered_ref'
);
check(($valueFinding['value'] ?? null) === 999, 'S3: value-ref finding retains raw numeric survivor 999');
check(!isset($valueFinding['matches']), 'S4: declared value-path survivor fires even when the id does not resolve live');
$keyFinding = $optionByLocator['options.polylang.nav_menus.by_term KEY 999'] ?? null;
check(
    ($keyFinding['class'] ?? null) === 'unrewritten_registered_ref',
    'S5: ordinary option sub-key key_refs survivor uses unrewritten_registered_ref'
);
check(($keyFinding['value'] ?? null) === 999, 'S6: key-ref finding retains the raw numeric map key');

check(count($findings) === 9, 'sanity: exactly 9 findings total across block, menu, and option fixtures -- got ' . count($findings) . ': ' . json_encode(array_column($findings, 'class')));

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
