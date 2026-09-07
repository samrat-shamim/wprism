<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * issue #3259's shortcode-attribute reference codec:
 *
 *  - agent/src/Grammar/Shortcodes.php (new): structure-aware capture/apply
 *    rewriting of declared shortcode_attrs refs, mirroring Blocks.php's
 *    own block_attrs mechanism and task #73's dangling-vs-unscoped triage
 *    a third time (Capture::classify_unscoped_ref()).
 *  - agent/src/Grammar/Blocks.php's $rewriteString closure: now also threads
 *    every innerContent chunk through Shortcodes::capture_rewrite_text()/
 *    apply_rewrite_text() — proven here via Blocks::capture_rewrite()
 *    directly (S18), not just Shortcodes.php in isolation, since the
 *    wiring itself is new, untested surface.
 *  - agent/src/Review/Lint.php's new scan_shortcodes(): the shortcode twins of
 *    unregistered_block_attr / unrewritten_registered_ref.
 *
 * Shortcodes.php is a HARD Blocks.php dependency, not a sibling mechanism:
 * Blocks.php's $rewriteString closure threads every innerContent chunk
 * through Shortcodes::capture_rewrite_text()/apply_rewrite_text()
 * (Blocks.php:249 and :261), so a change on this side can regress the
 * block-refs mechanism without Blocks.php being touched at all. Carrying that
 * cross-regression HERE is not this suite's job and used to cost real time:
 * regress-block-refs is its own wired offline leaf, so every corpus pass runs
 * regress_block_refs.php regardless, and the wrapper that also invoked it
 * made tools/affected.php read block_refs.php as invoked-elsewhere -- under
 * which `regress-block-refs` selected for nothing at all on a --changed run.
 *
 * Runs the REAL, unmodified agent/src/{Canon,Policy,Tokens,Ledger,Pending,
 * Blocks,Shortcodes,Lint}.php against hand-built fixtures, with only two
 * things stubbed: WordPress's shortcode-parsing primitives (support/wp-
 * shortcode-stub.php — a verbatim vendored copy, see its own docblock),
 * WordPress's block-parser primitives (support/wp-block-parser-stub.php,
 * needed only for S18's Blocks.php integration check), and a minimal fake
 * $wpdb (below) standing in for the two narrow, fixed-shape query
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
            'basedir' => sys_get_temp_dir() . '/wprism-regress-uploads',
        ];
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim((string) $string, '/\\');
    }
}
if (!function_exists('get_post_stati')) {
    function get_post_stati($args = []) {
        return ($args['exclude_from_search'] ?? false) ? ['auto-draft', 'inherit', 'trash'] : [];
    }
}

require __DIR__ . '/../../support/wp-shortcode-stub.php';
require __DIR__ . '/../../support/wp-block-parser-stub.php'; // S18 only: Blocks.php integration check

// ------------------------------------------------------------- fake $wpdb

/**
 * Stands in for exactly the query shapes Ledger::id_for()/uuid_for() and
 * Pending::resolve_id() issue, plus Capture::ref_target_type()'s two
 * scalar lookups — verified by reading those classes directly, not a
 * general SQL engine. Deliberately the SAME shape as regress_block_refs.
 * php's own FakeWpdb (each regress_*.php harness owns its own copy, this
 * codebase's established convention — see regress_composite_ref.php/
 * regress_fatal_mutations.php's own FakeWpdb classes).
 */
final class FakeWpdb {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $last_error = '';
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';

    /** @var array<string, array<int, string>> id_kind => [local_id => uuid] */
    public $identity = [];
    /** @var array<int, array{post_type:string, post_title:string, post_status?:string}> */
    public $postsById = [];
    /** @var array<int, array<string, list<string>>> */
    public $postMetaById = [];
    public $injectGetColError = false;
    public $injectGetResultsError = false;
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
        if (str_contains($sql, 'SELECT local_id FROM') && str_contains($sql, 'wprism_map')) {
            [$uuid, $kind] = $args;
            foreach ($this->identity[$kind] ?? [] as $localId => $u) {
                if ($u === $uuid) {
                    return $localId;
                }
            }
            return null;
        }
        if (str_contains($sql, 'SELECT uuid FROM') && str_contains($sql, 'wprism_map')) {
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

    public function get_col($prepared) {
        [$sql, $args] = $this->unwrap($prepared);
        if ($this->injectGetColError) {
            $this->last_error = 'injected SQL failure';
            return [];
        }
        if (str_contains($sql, 'SELECT pm.post_id FROM') && str_contains($sql, $this->postmeta)) {
            [$key, $value, $postType] = array_pad($args, 3, null);
            $numeric = str_contains($sql, 'CAST(pm.meta_value AS DECIMAL');
            $excluded = array_slice($args, 3);
            $out = [];
            foreach ($this->postMetaById as $postId => $meta) {
                $values = array_map('strval', (array) ($meta[$key] ?? []));
                $matches = $numeric
                    ? array_filter($values, static fn(string $candidate): bool => preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/', $candidate) === 1
                        && (int) $candidate === (int) $value)
                    : (in_array((string) $value, $values, true) ? [$value] : []);
                if (($this->postsById[(int) $postId]['post_type'] ?? null) === $postType
                    && !in_array((string) ($this->postsById[(int) $postId]['post_status'] ?? 'publish'), $excluded, true)
                    && $matches !== []) {
                    foreach ($matches as $_) {
                        $out[] = $postId;
                    }
                }
            }
            sort($out, SORT_NUMERIC);
            return $out;
        }
        if (str_contains($sql, 'SELECT post_type FROM') && str_contains($sql, $this->posts)) {
            return $this->postsById[(int) $args[0]]['post_type'] ?? null;
        }
        if (str_contains($sql, 'SELECT meta_value FROM') && str_contains($sql, $this->postmeta)) {
            [$postId, $key] = $args;
            return array_values(array_map('strval', (array) (($this->postMetaById[(int) $postId] ?? [])[$key] ?? [])));
        }
        throw new \RuntimeException("FakeWpdb::get_col: unrecognized query shape: $sql");
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

    public function get_results($prepared, $output = ARRAY_A) {
        [$sql, $args] = $this->unwrap($prepared);
        if ($this->injectGetResultsError) {
            $this->last_error = 'injected SQL failure';
            return [];
        }
        if (str_contains($sql, 'SELECT pm.post_id, pm.meta_value FROM')
            && str_contains($sql, $this->postmeta)) {
            [$key, $postType] = array_pad($args, 2, null);
            $excluded = array_slice($args, 2);
            $out = [];
            foreach ($this->postMetaById as $postId => $meta) {
                if (($this->postsById[(int) $postId]['post_type'] ?? null) !== $postType
                    || in_array(
                        (string) ($this->postsById[(int) $postId]['post_status'] ?? 'publish'),
                        $excluded,
                        true
                    )) {
                    continue;
                }
                foreach ((array) ($meta[$key] ?? []) as $value) {
                    $out[] = ['post_id' => $postId, 'meta_value' => (string) $value];
                }
            }
            return $out;
        }
        throw new \RuntimeException("FakeWpdb::get_results: unrecognized query shape: $sql");
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
require __DIR__ . '/../../../../agent/src/Grammar/Blocks.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Shortcodes.php';
require __DIR__ . '/../../../../agent/src/Review/Lint.php';
require __DIR__ . '/../../../../agent/src/Apply/Apply.php';
// issue #3259: Shortcodes::queue_unscoped() calls Capture::
// classify_unscoped_ref() directly (public static, itself built on the
// zero-instance-dependency ref_target_type() -- see both docblocks)
// rather than duplicating the query shapes it encapsulates. Loading the
// class definition only; nothing here ever instantiates Capture or calls
// any of its other (WordPress-dependent) methods.
require_once __DIR__ . '/../../../../agent/src/Capture/Capture.php';

use WPrism\Canon;
use WPrism\Policy;
use WPrism\Tokens;
use WPrism\Blocks;
use WPrism\Shortcodes;
use WPrism\Lint;

const MAPPED_UUID = '01980000-0003-7000-8000-000000000001';
const MAPPED_ID = 601;
const MAPPED2_UUID = '01980000-0003-7000-8000-000000000002';
const MAPPED2_ID = 602;
const UNMAPPED_ID = 979; // never in identity or postsById -> genuinely DANGLING
const UNSCOPED_ID = 878; // never in identity, but a REAL row of an out-of-scope type -> UNSCOPED
const UNMINTED_ID = 434; // never in identity, but a REAL row of an IN-scope type -> neither (false-positive guard)

$wpdb->identity['post'] = [MAPPED_ID => MAPPED_UUID, MAPPED2_ID => MAPPED2_UUID];
$wpdb->postsById[UNSCOPED_ID] = ['post_type' => 'elementor_library', 'post_title' => 'A Real Elementor Template'];
$wpdb->postsById[UNMINTED_ID] = ['post_type' => 'page', 'post_title' => 'A Real But Unminted Page'];
$wpdb->postsById[MAPPED_ID] = ['post_type' => 'wpcf7_contact_form', 'post_title' => 'Legacy Form'];
$wpdb->postMetaById[MAPPED_ID] = ['_old_cf7_unit_id' => ['77']];
$wpdb->postsById[MAPPED2_ID] = ['post_type' => 'wpcf7_contact_form', 'post_title' => 'Second Form'];
// L3's unregistered-attr regression case -- deliberately DOES resolve.
$wpdb->postsById[701] = ['post_type' => 'post', 'post_title' => 'Some Real Post'];

$policy = new Policy();
$policy->manifests = [[
    'shortcode_attrs' => [
        'gallery' => [
            ['kind' => 'post', 'path' => 'id'],
            ['cast' => 'csv', 'kind' => 'post', 'path' => 'ids'],
            ['cast' => 'csv', 'kind' => 'post', 'path' => 'include'],
            ['cast' => 'csv', 'kind' => 'post', 'path' => 'exclude'],
        ],
        'contact-form' => [
            ['kind' => 'post', 'lookup' => ['post_meta' => '_old_cf7_unit_id', 'post_type' => 'wpcf7_contact_form'], 'position' => 0],
        ],
        'contact-form-7' => [[
            'kind' => 'post',
            'lookup' => [
                'codec' => 'hex-prefix',
                'post_meta' => '_hash',
                'post_type' => 'wpcf7_contact_form',
                'prefix_length' => 7,
                'stored_lengths' => [40, 64],
            ],
            'path' => 'id',
            'required' => true,
        ]],
    ],
]];
$tokens = new Tokens();
$tokens->policy = $policy;

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
// PART 1 — Shortcodes.php: capture/apply rewrite semantics
// ======================================================================
echo "\n== Shortcodes.php: capture/apply rewrite semantics ==\n";

// S1 — mapped scalar: id_to_token succeeds, id="..." becomes the token,
// every byte outside the rewritten value untouched (leading text, the
// unrelated 'columns' attribute, self-close marker).
$s1in = 'See the gallery: [gallery id="' . MAPPED_ID . '" columns="4" /] end.';
$tokens->warnings = [];
$s1out = Shortcodes::capture_rewrite_text($s1in, $policy, $tokens);
check($s1out === 'See the gallery: [gallery id="{{post:' . MAPPED_UUID . '}}" columns="4" /] end.',
    'S1: mapped scalar id rewritten to token, everything else byte-identical (got: ' . $s1out . ')');
check($tokens->warnings === [], 'S1: no warnings on a clean mapped capture (got: ' . json_encode($tokens->warnings) . ')');

// S1b — CF7's legacy positional shortcode resolves through the declared
// alternate post-meta identity, never through wp_posts.ID.  Capture emits a
// canonical post token; the ordinary direct seam restores the declared value.
$legacy = '[contact-form 77 "Legacy Form"]';
$legacyCanonical = Shortcodes::capture_rewrite_text($legacy, $policy, $tokens);
check($legacyCanonical === '[contact-form {{post:' . MAPPED_UUID . '}} "Legacy Form"]',
    'S1b: positional alternate id is canonicalized to the form token (got: ' . $legacyCanonical . ')');
check(Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens) === $legacy,
    'S1b: positional token round-trips through the declared alternate id');
check(!str_contains($legacyCanonical, '77'), 'S1b: canonical content has no raw _old_cf7_unit_id');
$savedBacktrackLimit = ini_get('pcre.backtrack_limit');
$captureRegexFailureRefused = false;
$applyRegexFailureRefused = false;
try {
    ini_set('pcre.backtrack_limit', '1');
    try {
        Shortcodes::capture_rewrite_text($legacy, $policy, $tokens);
    } catch (\Throwable $e) {
        $captureRegexFailureRefused = str_contains($e->getMessage(), 'rewrite regex failed');
    }
    try {
        Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens);
    } catch (\Throwable $e) {
        $applyRegexFailureRefused = str_contains($e->getMessage(), 'rewrite regex failed');
    }
} finally {
    ini_set('pcre.backtrack_limit', (string) $savedBacktrackLimit);
}
check($captureRegexFailureRefused, 'S1b: capture refuses a shortcode regex backtrack failure instead of preserving a raw positional id');
check($applyRegexFailureRefused, 'S1b: Apply refuses a shortcode regex backtrack failure instead of preserving unproven canonical content');
$structured = ['elements' => [['content' => $legacy]]];
$structuredCanonical = $tokens->struct_capture($structured, [], null);
check($structuredCanonical['elements'][0]['content'] === '[contact-form {{post:' . MAPPED_UUID . '}} "Legacy Form"]',
    'S1c: positional shortcode rewrite also covers structured/Elementor string leaves');
check($tokens->struct_apply($structuredCanonical, [], null)['elements'][0]['content'] === $legacy,
    'S1c: structured positional shortcode token round-trips on apply');
$tokens->forceUnresolvedRefs = true;
$structuredForced = $tokens->struct_capture(['content' => '[gallery id="' . UNSCOPED_ID . '"]'], [], null);
check($structuredForced['content'] === '[gallery]', 'S1c: force-unresolved-refs reaches named shortcode refs inside structured leaves');
$tokens->forceUnresolvedRefs = false;
$savedType = $wpdb->postsById[MAPPED_ID]['post_type'];
$wpdb->postsById[MAPPED_ID]['post_type'] = 'page';
$wrongTypeRefused = false;
try {
    Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens);
} catch (\Throwable $e) {
    $wrongTypeRefused = str_contains($e->getMessage(), 'outside post_type');
}
$wpdb->postsById[MAPPED_ID]['post_type'] = $savedType;
check($wrongTypeRefused, 'S1c: Apply rechecks target post_type before using a cached alternate witness');
$sealedTokens = new Tokens();
$sealedTokens->policy = $policy;
$sealedTokens->seal_shortcode_alternates();
$missingWitnessRefused = false;
try {
    Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $sealedTokens);
} catch (\Throwable $e) {
    $missingWitnessRefused = str_contains($e->getMessage(), 'no canonical alternate witness');
}
check($missingWitnessRefused, 'S1c: sealed Apply refuses a positional token without a canonical alternate witness');
$wpdb->postMetaById[MAPPED2_ID] = ['_old_cf7_unit_id' => ['77']];
$tokens->register_shortcode_alternate('{{post:' . MAPPED_UUID . '}}', '_old_cf7_unit_id', 'wpcf7_contact_form', '77');
$foreignDuplicateRefused = false;
try {
    Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens);
} catch (\Throwable $e) {
    $foreignDuplicateRefused = str_contains($e->getMessage(), 'already owned by another');
}
unset($wpdb->postMetaById[MAPPED2_ID]);
check($foreignDuplicateRefused, 'S1c: Apply refuses a target-side foreign duplicate alternate before body mutation');
$wpdb->postMetaById[MAPPED2_ID] = ['_old_cf7_unit_id' => ['077']];
$foreignNumericDuplicateRefused = false;
try {
    Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens);
} catch (\Throwable $e) {
    $foreignNumericDuplicateRefused = str_contains($e->getMessage(), 'already owned by another');
}
unset($wpdb->postMetaById[MAPPED2_ID]);
check($foreignNumericDuplicateRefused, 'S1c: Apply refuses a foreign lexical alternate that collides in CF7 numeric lookup');
$wpdb->postsById[603] = ['post_type' => 'wpcf7_contact_form', 'post_title' => 'Trashed Form', 'post_status' => 'trash'];
$wpdb->postMetaById[603] = ['_old_cf7_unit_id' => ['77']];
$trashedIgnored = Shortcodes::capture_rewrite_text($legacy, $policy, $tokens) === '[contact-form {{post:' . MAPPED_UUID . '}} "Legacy Form"]';
unset($wpdb->postsById[603], $wpdb->postMetaById[603]);
check($trashedIgnored, 'S1c: CF7-ineligible trash rows do not collide with runtime alternate lookup');
$wpdb->postMetaById[MAPPED_ID] = ['_old_cf7_unit_id' => ['77', '77']];
$targetDuplicateRowsRefused = false;
try {
    Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens);
} catch (\Throwable $e) {
    $targetDuplicateRowsRefused = str_contains($e->getMessage(), 'duplicate')
        && str_contains($e->getMessage(), 'metadata rows');
}
$wpdb->postMetaById[MAPPED_ID] = ['_old_cf7_unit_id' => ['77']];
check($targetDuplicateRowsRefused, 'S1c: Apply refuses duplicate alternate metadata rows on the selected target before body mutation');
$wpdb->injectGetColError = true;
$captureSqlFailureRefused = false;
try {
    Shortcodes::capture_rewrite_text($legacy, $policy, $tokens);
} catch (\Throwable $e) {
    $captureSqlFailureRefused = str_contains($e->getMessage(), 'alternate lookup failed');
}
$applySqlFailureRefused = false;
try {
    Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens);
} catch (\Throwable $e) {
    $applySqlFailureRefused = str_contains($e->getMessage(), 'collision check failed');
}
$wpdb->injectGetColError = false;
check($captureSqlFailureRefused, 'S1c: capture refuses an alternate lookup SQL failure instead of treating it as no match');
check($applySqlFailureRefused, 'S1c: Apply refuses a collision-check SQL failure before rewriting the body');
$wpdb->last_error = 'stale prior query failure';
check(Shortcodes::capture_rewrite_text($legacy, $policy, $tokens) === '[contact-form {{post:' . MAPPED_UUID . '}} "Legacy Form"]',
    'S1c: a stale prior wpdb error is cleared before a fresh alternate lookup');
$wpdb->last_error = 'stale prior query failure';
check(Shortcodes::apply_rewrite_text($legacyCanonical, $policy, $tokens) === $legacy,
    'S1c: a stale prior wpdb error is cleared before a fresh collision check');

// S1d — Apply registers the canonical alternate witnesses before any body
// rewrite.  The reverse index must reject duplicate alternate values within
// one declared lookup domain (and zero is never a valid legacy identifier).
$applyForAlternates = new \WPrism\ShortcodeAlternateRegistrar($policy, new Tokens());
$registerAlternates = new \ReflectionMethod(\WPrism\ShortcodeAlternateRegistrar::class, 'register');
$duplicateAlternateTree = [
    ['type' => 'post', 'data' => ['type' => 'wpcf7_contact_form', 'uuid' => MAPPED_UUID, 'meta' => [
        '_hash' => str_repeat('a', 64), '_old_cf7_unit_id' => '77',
    ]]],
    ['type' => 'post', 'data' => ['type' => 'wpcf7_contact_form', 'uuid' => MAPPED2_UUID, 'meta' => [
        '_hash' => str_repeat('b', 64), '_old_cf7_unit_id' => '77',
    ]]],
];
$duplicateRefused = false;
try {
    $registerAlternates->invoke($applyForAlternates, $duplicateAlternateTree);
} catch (\Throwable $e) {
    $duplicateRefused = str_contains($e->getMessage(), 'ambiguous');
}
check($duplicateRefused, 'S1d: Apply refuses duplicate positional alternate values before body mutation');
$zeroRefused = false;
try {
    $registerAlternates->invoke($applyForAlternates, [[
        'type' => 'post', 'data' => ['type' => 'wpcf7_contact_form', 'uuid' => MAPPED2_UUID, 'meta' => [
            '_hash' => str_repeat('b', 64), '_old_cf7_unit_id' => '0',
        ]],
    ]]);
} catch (\Throwable $e) {
    $zeroRefused = str_contains($e->getMessage(), 'malformed positional shortcode alternate');
}
check($zeroRefused, 'S1d: Apply refuses zero positional alternate values');
$leadingZeroRefused = false;
try {
    Shortcodes::capture_rewrite_text('[contact-form 077 "Legacy Form"]', $policy, $tokens);
} catch (\Throwable $e) {
    $leadingZeroRefused = str_contains($e->getMessage(), 'positive decimal alternate id');
}
check($leadingZeroRefused, 'S1d: capture refuses non-canonical leading-zero alternate ids');
$malformedSeparatorRefused = false;
try {
    Shortcodes::capture_rewrite_text('[contact-form foo="bar"77]', $policy, $tokens);
} catch (\Throwable $e) {
    $malformedSeparatorRefused = true;
}
check($malformedSeparatorRefused, 'S1d: malformed no-separator shortcode attrs do not get split into a positional id');
$namedPositionalCases = [
    '[contact-form id=77]',
    '[contact-form x=77 88]',
    '[contact-form 1=foo 77]',
];
foreach ($namedPositionalCases as $case) {
    $refused = false;
    try {
        Shortcodes::capture_rewrite_text($case, $policy, $tokens);
    } catch (\Throwable $e) {
        $refused = str_contains($e->getMessage(), 'refuse named attributes');
    }
    check($refused, "S1d: positional '$case' refuses named/mixed attributes rather than selecting a different callback argument");
}
$hugeAlternateRefused = false;
try {
    $tokens->register_shortcode_alternate('{{post:' . MAPPED2_UUID . '}}', '_old_cf7_unit_id', 'wpcf7_contact_form', '9223372036854775808');
} catch (\Throwable $e) {
    $hugeAlternateRefused = str_contains($e->getMessage(), 'malformed positional shortcode alternate');
}
check($hugeAlternateRefused, 'S1d: positional alternates outside the executable PHP integer range refuse');
$decimalOverflowRefused = false;
try {
    $tokens->register_shortcode_alternate('{{post:' . MAPPED2_UUID . '}}', '_old_cf7_unit_id', 'wpcf7_contact_form', '10000000000');
} catch (\Throwable $e) {
    $decimalOverflowRefused = str_contains($e->getMessage(), 'malformed positional shortcode alternate');
}
check($decimalOverflowRefused, 'S1d: positional alternates outside CF7 bare-DECIMAL range refuse');

// S1e — CF7 5.8+ uses a seven-byte prefix of its persisted 64-byte _hash
// as the public shortcode identity. Canonical state carries the post token,
// and both source and target prefix domains must remain unique.
$hashA = str_repeat('a', 64);
$hashB = str_repeat('b', 64);
$hashC = str_repeat('c', 64);
$wpdb->postMetaById[MAPPED_ID]['_hash'] = [$hashA];
$modern = '[contact-form-7 id="aaaaaaa" title="Legacy Form"]';
$modernCanonical = Shortcodes::capture_rewrite_text($modern, $policy, $tokens);
check(
    $modernCanonical === '[contact-form-7 id="{{post:' . MAPPED_UUID . '}}" title="Legacy Form"]',
    'S1e: CF7 hash prefix canonicalizes to the owning form token'
);
$hash40 = str_repeat('d', 40);
$wpdb->postMetaById[MAPPED_ID]['_hash'] = [$hash40];
$modern40 = '[contact-form-7 id="ddddddd" title="Legacy Form"]';
$modern40Canonical = Shortcodes::capture_rewrite_text($modern40, $policy, $tokens);
$canonical40Tokens = new Tokens();
$canonical40Tokens->policy = $policy;
(new \WPrism\ShortcodeAlternateRegistrar($policy, $canonical40Tokens))->register([[
    'type' => 'post',
    'data' => [
        'type' => 'wpcf7_contact_form',
        'uuid' => MAPPED_UUID,
        'meta' => ['_hash' => $hash40, '_old_cf7_unit_id' => '77'],
    ],
]]);
check(
    $modern40Canonical === $modernCanonical
        && Shortcodes::apply_rewrite_text($modern40Canonical, $policy, $canonical40Tokens) === $modern40,
    'S1e: the declared legacy SHA-1 width canonicalizes and applies through the same fixed-prefix identity'
);
$wpdb->postMetaById[MAPPED_ID]['_hash'] = [$hashC];
$canonicalNamedTokens = new Tokens();
$canonicalNamedTokens->policy = $policy;
(new \WPrism\ShortcodeAlternateRegistrar($policy, $canonicalNamedTokens))->register([[
    'type' => 'post',
    'data' => [
        'type' => 'wpcf7_contact_form',
        'uuid' => MAPPED_UUID,
        'meta' => ['_hash' => $hashA, '_old_cf7_unit_id' => '77'],
    ],
]]);
check(
    Shortcodes::apply_rewrite_text($modernCanonical, $policy, $canonicalNamedTokens) === $modern,
    'S1e: Apply registrar restores the canonical source prefix before the target hash is updated'
);
$boundIdentity = $wpdb->identity['post'][MAPPED_ID];
unset($wpdb->identity['post'][MAPPED_ID]);
$cleanTargetTokens = new Tokens();
$cleanTargetTokens->policy = $policy;
(new \WPrism\ShortcodeAlternateRegistrar($policy, $cleanTargetTokens))->register([[
    'type' => 'post',
    'data' => [
        'type' => 'wpcf7_contact_form',
        'uuid' => MAPPED_UUID,
        'meta' => ['_hash' => $hashA, '_old_cf7_unit_id' => '77'],
    ],
]]);
check(
    $cleanTargetTokens->shortcode_alternate(
        '{{post:' . MAPPED_UUID . '}}',
        '_hash',
        'wpcf7_contact_form'
    ) === 'aaaaaaa',
    'S1e: Apply registrar preflights and seals a canonical named witness before a clean target has a local binding'
);
$wpdb->identity['post'][MAPPED_ID] = $boundIdentity;
$wpdb->postMetaById[MAPPED_ID]['_hash'] = [$hashA];
$sealedNamedTokens = new Tokens();
$sealedNamedTokens->policy = $policy;
$sealedNamedTokens->seal_shortcode_alternates();
$missingNamedWitnessRefused = false;
try {
    Shortcodes::apply_rewrite_text($modernCanonical, $policy, $sealedNamedTokens);
} catch (\Throwable $e) {
    $missingNamedWitnessRefused = str_contains($e->getMessage(), 'no canonical alternate witness');
}
check($missingNamedWitnessRefused, 'S1e: sealed Apply refuses a named token without a canonical alternate witness');
$malformedNamedWitnessRefused = false;
try {
    (new Tokens())->register_shortcode_named_alternate(
        '{{post:' . MAPPED_UUID . '}}',
        '_hash',
        'wpcf7_contact_form',
        'AAAAAAA',
        7
    );
} catch (\Throwable $e) {
    $malformedNamedWitnessRefused = str_contains($e->getMessage(), 'malformed named shortcode alternate');
}
check($malformedNamedWitnessRefused, 'S1e: named alternate witnesses require the declared lowercase-hex width');
$duplicateNamedTokens = new Tokens();
$duplicateNamedTokens->register_shortcode_named_alternate(
    '{{post:' . MAPPED_UUID . '}}',
    '_hash',
    'wpcf7_contact_form',
    'aaaaaaa',
    7
);
$duplicateNamedWitnessRefused = false;
try {
    $duplicateNamedTokens->register_shortcode_named_alternate(
        '{{post:' . MAPPED2_UUID . '}}',
        '_hash',
        'wpcf7_contact_form',
        'aaaaaaa',
        7
    );
} catch (\Throwable $e) {
    $duplicateNamedWitnessRefused = str_contains($e->getMessage(), 'ambiguous');
}
check($duplicateNamedWitnessRefused, 'S1e: one named alternate cannot identify two canonical forms');
$recoveryTokens = new Tokens();
$recoveryTokens->policy = $policy;
$recoveryTokens->register_shortcode_alternate(
    '{{post:' . MAPPED_UUID . '}}',
    '_old_cf7_unit_id',
    'wpcf7_contact_form',
    '77'
);
$recoveryTokens->register_shortcode_named_alternate(
    '{{post:' . MAPPED_UUID . '}}',
    '_hash',
    'wpcf7_contact_form',
    'aaaaaaa',
    7
);
$recoveryTokens->seal_shortcode_alternates();
$mappedMeta = $wpdb->postMetaById[MAPPED_ID];
unset($wpdb->postMetaById[MAPPED_ID]);
$strictSourceTokens = new Tokens();
$strictSourceTokens->policy = $policy;
$missingSourcePositionalRefused = false;
try {
    Shortcodes::capture_rewrite_text($legacy, $policy, $strictSourceTokens);
} catch (\Throwable $e) {
    $missingSourcePositionalRefused = str_contains($e->getMessage(), 'no matching form');
}
check(
    $missingSourcePositionalRefused,
    'S1e: ordinary source capture cannot use an unsealed positional fallback when the owner is missing'
);
$missingSourceNamedRefused = false;
try {
    Shortcodes::capture_rewrite_text($modern, $policy, $strictSourceTokens);
} catch (\Throwable $e) {
    $missingSourceNamedRefused = str_contains($e->getMessage(), 'no matching form');
}
check(
    $missingSourceNamedRefused,
    'S1e: ordinary source capture cannot use an unsealed named fallback when the owner is missing'
);
check(
    Shortcodes::capture_rewrite_text($legacy, $policy, $recoveryTokens) === $legacyCanonical,
    'S1e: target observation recovers a dangling positional identity from the sealed repository witness'
);
check(
    Shortcodes::capture_rewrite_text($modern, $policy, $recoveryTokens) === $modernCanonical,
    'S1e: target observation recovers a dangling named identity from the sealed repository witness'
);
$wpdb->postMetaById[MAPPED2_ID] = [
    '_old_cf7_unit_id' => ['77'],
    '_hash' => [$hashA],
];
$disagreeingPositionalOwnerRefused = false;
try {
    Shortcodes::capture_rewrite_text($legacy, $policy, $recoveryTokens);
} catch (\Throwable $e) {
    $disagreeingPositionalOwnerRefused = str_contains($e->getMessage(), 'disagrees with its canonical witness');
}
check(
    $disagreeingPositionalOwnerRefused,
    'S1e: target observation refuses a live positional owner mapped to another canonical entity'
);
$disagreeingNamedOwnerRefused = false;
try {
    Shortcodes::capture_rewrite_text($modern, $policy, $recoveryTokens);
} catch (\Throwable $e) {
    $disagreeingNamedOwnerRefused = str_contains($e->getMessage(), 'disagrees with its canonical witness');
}
check(
    $disagreeingNamedOwnerRefused,
    'S1e: target observation refuses a live named owner mapped to another canonical entity'
);
unset($wpdb->postMetaById[MAPPED2_ID]);
$wpdb->postMetaById[MAPPED_ID] = $mappedMeta;
$missingModernIdRefused = false;
try {
    Shortcodes::capture_rewrite_text('[contact-form-7 title="Legacy Form"]', $policy, $tokens);
} catch (\Throwable $e) {
    $missingModernIdRefused = str_contains($e->getMessage(), 'requires exactly one');
}
check($missingModernIdRefused, 'S1e: title-only CF7 fallback refuses instead of using mutable title identity');
$malformedModernIdRefused = false;
try {
    Shortcodes::capture_rewrite_text('[contact-form-7 id="AAAAAAA" title="Legacy Form"]', $policy, $tokens);
} catch (\Throwable $e) {
    $malformedModernIdRefused = str_contains($e->getMessage(), 'lowercase hexadecimal');
}
check($malformedModernIdRefused, 'S1e: non-canonical CF7 hash prefixes refuse');
$duplicateModernAttrRefused = false;
try {
    Shortcodes::capture_rewrite_text(
        '[contact-form-7 id="aaaaaaa" id="aaaaaaa" title="Legacy Form"]',
        $policy,
        $tokens
    );
} catch (\Throwable $e) {
    $duplicateModernAttrRefused = str_contains($e->getMessage(), 'requires exactly one');
}
check($duplicateModernAttrRefused, 'S1e: duplicate CF7 identity attributes refuse rather than relying on parse order');
$wpdb->postMetaById[MAPPED2_ID] = ['_hash' => ['aaaaaaa' . substr($hashB, 7)]];
$duplicateModernSourceRefused = false;
try {
    Shortcodes::capture_rewrite_text($modern, $policy, $tokens);
} catch (\Throwable $e) {
    $duplicateModernSourceRefused = str_contains($e->getMessage(), 'multiple matching forms');
}
unset($wpdb->postMetaById[MAPPED2_ID]);
check($duplicateModernSourceRefused, 'S1e: a duplicate source hash prefix refuses capture');
$wpdb->postsById[603] = [
    'post_type' => 'wpcf7_contact_form',
    'post_title' => 'Trashed Hash Collision',
    'post_status' => 'trash',
];
$wpdb->postMetaById[603] = ['_hash' => ['aaaaaaa' . substr($hashB, 7)]];
check(
    Shortcodes::capture_rewrite_text($modern, $policy, $tokens) === $modernCanonical,
    'S1e: CF7-ineligible trash rows do not collide with modern hash lookup'
);
unset($wpdb->postsById[603], $wpdb->postMetaById[603]);
$wpdb->postMetaById[MAPPED2_ID] = ['_hash' => ['aaaaaaa' . substr($hashB, 7)]];
$duplicateModernTargetRefused = false;
try {
    Shortcodes::apply_rewrite_text($modernCanonical, $policy, $tokens);
} catch (\Throwable $e) {
    $duplicateModernTargetRefused = str_contains($e->getMessage(), 'already owned by another');
}
unset($wpdb->postMetaById[MAPPED2_ID]);
check($duplicateModernTargetRefused, 'S1e: a foreign target hash-prefix owner refuses apply');
$wpdb->postMetaById[MAPPED_ID]['_hash'] = [$hashA, $hashA];
$duplicateModernRowsRefused = false;
try {
    Shortcodes::apply_rewrite_text($modernCanonical, $policy, $tokens);
} catch (\Throwable $e) {
    $duplicateModernRowsRefused = str_contains($e->getMessage(), 'duplicate')
        && str_contains($e->getMessage(), 'metadata rows');
}
$wpdb->postMetaById[MAPPED_ID]['_hash'] = [$hashA];
check($duplicateModernRowsRefused, 'S1e: duplicate target _hash rows on the selected form refuse apply');
$wpdb->injectGetResultsError = true;
$modernSqlFailureRefused = false;
try {
    Shortcodes::capture_rewrite_text($modern, $policy, $tokens);
} catch (\Throwable $e) {
    $modernSqlFailureRefused = str_contains($e->getMessage(), 'collision lookup failed');
}
$wpdb->injectGetResultsError = false;
check($modernSqlFailureRefused, 'S1e: a CF7 hash collision-query failure refuses capture');
unset($wpdb->postMetaById[MAPPED_ID]['_hash']);

// S2 — unmapped scalar: DROP the attribute (+ its own leading whitespace)
// entirely, never leave the raw env-local id, matching Blocks.php's own
// dangling-ref posture exactly.
$s2in = '[gallery id="' . UNMAPPED_ID . '" columns="4"]';
$tokens->warnings = [];
$s2out = Shortcodes::capture_rewrite_text($s2in, $policy, $tokens);
check($s2out === '[gallery columns="4"]', 'S2: unmapped scalar id dropped WITH its leading whitespace, no double space (got: ' . $s2out . ')');
$w = implode(' | ', $tokens->warnings);
check(str_contains($w, 'gallery') && str_contains($w, "'id'") && str_contains($w, (string) UNMAPPED_ID), "S2: warning names shortcode/attribute/id (got: $w)");

// S3 — CSV mixed: one mapped element kept as token, one unmapped dropped,
// surviving elements rejoined on comma -- element-level drop, not whole-
// attribute drop, for cast:csv refs (mirrors Blocks.php's int[] handling).
$s3in = '[gallery ids="' . MAPPED_ID . ',' . UNMAPPED_ID . '"]';
$tokens->warnings = [];
$s3out = Shortcodes::capture_rewrite_text($s3in, $policy, $tokens);
check($s3out === '[gallery ids="{{post:' . MAPPED_UUID . '}}"]', 'S3: CSV -- mapped element kept as token, unmapped element dropped from the list (got: ' . $s3out . ')');
$w = implode(' | ', $tokens->warnings);
check(str_contains($w, "'ids[1]'") && str_contains($w, (string) UNMAPPED_ID), "S3: warning names the SPECIFIC csv index dropped (got: $w)");

// S4 — CSV all-unmapped: the WHOLE attribute drops (no purposeless ids="").
$s4in = '[gallery ids="' . UNMAPPED_ID . '" columns="4"]';
$tokens->warnings = [];
$s4out = Shortcodes::capture_rewrite_text($s4in, $policy, $tokens);
check($s4out === '[gallery columns="4"]', 'S4: CSV with every element unmapped drops the whole attribute, not ids="" (got: ' . $s4out . ')');

// S5 — UNSCOPED scalar: uniform drop (same as dangling), but ALSO queues
// onto Tokens::$unscopedShortcodeRefs since the id names a REAL row of an
// out-of-scope type -- every field of the violation checked precisely.
$tokens->warnings = [];
$tokens->unscopedShortcodeRefs = [];
$s5in = '[gallery id="' . UNSCOPED_ID . '"]';
$s5out = Shortcodes::capture_rewrite_text($s5in, $policy, $tokens, false, "page 'about-us'");
check($s5out === '[gallery]', 'S5: attribute still dropped for the unscoped ref (uniform drop, same as dangling) (got: ' . $s5out . ')');
check(count($tokens->unscopedShortcodeRefs) === 1, 'S5: exactly one unscoped violation queued (got: ' . json_encode($tokens->unscopedShortcodeRefs) . ')');
$r5 = $tokens->unscopedShortcodeRefs[0] ?? [];
check(($r5['post'] ?? null) === "page 'about-us'", 'S5: violation names the post via postLabel (got: ' . json_encode($r5) . ')');
check(($r5['shortcode'] ?? null) === 'gallery', 'S5: violation names the shortcode tag');
check(($r5['attr'] ?? null) === 'id', 'S5: violation names the attribute');
check(($r5['kind'] ?? null) === 'post', 'S5: violation names the ref kind');
check(($r5['id'] ?? null) === UNSCOPED_ID, 'S5: violation names the raw id');
check(($r5['target_type'] ?? null) === 'elementor_library', 'S5: violation names the real target type');

// S6 — --force-unresolved-refs bypasses the QUEUE, not the drop.
$tokens->warnings = [];
$tokens->unscopedShortcodeRefs = [];
$s6out = Shortcodes::capture_rewrite_text($s5in, $policy, $tokens, true, "page 'about-us'");
check($s6out === '[gallery]', 'S6: attribute STILL dropped under --force-unresolved-refs (force changes reporting, not the drop)');
check($tokens->unscopedShortcodeRefs === [], 'S6: --force-unresolved-refs suppresses the unscoped queue entirely (got: ' . json_encode($tokens->unscopedShortcodeRefs) . ')');

// S7 — false-positive guard: a REAL row of an IN-scope type ('page') that
// simply has no ledger uuid minted on THIS build must be treated
// identically to dangling, never queued (task #73's own critical guard,
// ported a third time).
$tokens->warnings = [];
$tokens->unscopedShortcodeRefs = [];
$s7in = '[gallery id="' . UNMINTED_ID . '"]';
$s7out = Shortcodes::capture_rewrite_text($s7in, $policy, $tokens);
check($s7out === '[gallery]', 'S7: attribute dropped for the in-scope-but-unminted ref (uniform drop)');
check($tokens->unscopedShortcodeRefs === [], 'S7: in-scope-but-unminted NEVER queues as unscoped (got: ' . json_encode($tokens->unscopedShortcodeRefs) . ')');

// S8 — CSV unscoped parity: an unscoped id inside a cast:csv ref gets the
// SAME triage as a scalar ref, at the SPECIFIC csv index -- task #73's own
// "scalar and array/csv refs must not diverge in severity" criterion.
$tokens->warnings = [];
$tokens->unscopedShortcodeRefs = [];
$s8in = '[gallery ids="' . MAPPED_ID . ',' . UNSCOPED_ID . '"]';
$s8out = Shortcodes::capture_rewrite_text($s8in, $policy, $tokens);
check($s8out === '[gallery ids="{{post:' . MAPPED_UUID . '}}"]', 'S8: mapped element kept, unscoped element dropped (got: ' . $s8out . ')');
check(count($tokens->unscopedShortcodeRefs) === 1, 'S8: exactly one unscoped violation queued for the csv element');
$r8 = $tokens->unscopedShortcodeRefs[0] ?? [];
check(($r8['attr'] ?? null) === 'ids[1]', 'S8: violation locates the SPECIFIC csv index, matching Blocks.php\'s own array-index precedent (got: ' . json_encode($r8) . ')');

// S9 — undeclared TAG: [caption ...] has no shortcode_attrs rule at all
// (this manifest's own explicit-unsupported ruling) -- must pass through
// completely byte-untouched, proving the ruling holds structurally, not
// just in documentation.
$s9in = '[caption id="attachment_' . MAPPED_ID . '" align="alignnone" width="300"]A cat.[/caption]';
$tokens->warnings = [];
$s9out = Shortcodes::capture_rewrite_text($s9in, $policy, $tokens);
check($s9out === $s9in, 'S9: undeclared tag [caption] passes through completely untouched (got: ' . $s9out . ')');
check($tokens->warnings === [], 'S9: no warnings for an undeclared tag (silently out of scope, not an error)');

// S10 — escaped shortcode [[gallery ...]]: WordPress's own literal,
// non-executing escape convention -- must never be touched, mirroring
// do_shortcode_tag()'s own check exactly.
$s10in = 'literal example: [[gallery id="' . UNMAPPED_ID . '"]] end.';
$tokens->warnings = [];
$s10out = Shortcodes::capture_rewrite_text($s10in, $policy, $tokens);
check($s10out === $s10in, 'S10: escaped [[gallery]] left completely untouched, even with an unmapped-shaped id inside (got: ' . $s10out . ')');
check($tokens->warnings === [], 'S10: no warnings for escaped/literal shortcode text');

// S11 — multiple declared attributes, only some present: absent ones are
// a no-op (matches Blocks.php's own isset() early-continue), present ones
// rewritten independently.
$s11in = '[gallery id="' . MAPPED_ID . '" exclude="' . MAPPED2_ID . '"]';
$tokens->warnings = [];
$s11out = Shortcodes::capture_rewrite_text($s11in, $policy, $tokens);
check($s11out === '[gallery id="{{post:' . MAPPED_UUID . '}}" exclude="{{post:' . MAPPED2_UUID . '}}"]',
    'S11: id and exclude both rewritten independently; ids/include (absent) are a no-op (got: ' . $s11out . ')');

// S12 — single-quoted / unquoted original values are correctly READ even
// though the rewritten output always normalizes to double-quotes (no
// blessed "serialize shortcode atts back to text" format to preserve
// against -- see Shortcodes.php's own class docblock).
$tokens->warnings = [];
$s12a = Shortcodes::capture_rewrite_text("[gallery id='" . MAPPED_ID . "']", $policy, $tokens);
check($s12a === '[gallery id="{{post:' . MAPPED_UUID . '}}"]', "S12a: single-quoted original value correctly read (got: $s12a)");
$s12b = Shortcodes::capture_rewrite_text('[gallery id=' . MAPPED_ID . ']', $policy, $tokens);
check($s12b === '[gallery id="{{post:' . MAPPED_UUID . '}}"]', "S12b: unquoted original value correctly read (got: $s12b)");

// S13 — attribute NAME casing preserved (only the VALUE changes) -- the
// shortcode author's own `ID=` byte survives, matching shortcode_parse_
// atts()'s own case-insensitive-but-preserving-on-read behavior.
$tokens->warnings = [];
$s13out = Shortcodes::capture_rewrite_text('[gallery ID="' . MAPPED_ID . '"]', $policy, $tokens);
check($s13out === '[gallery ID="{{post:' . MAPPED_UUID . '}}"]', "S13: original attribute name casing 'ID=' preserved, not forced to 'id=' (got: $s13out)");

// S14 — apply-direction round trip: a captured token resolves back to its
// original numeric id.
$tokens->warnings = [];
$s14cap = Shortcodes::capture_rewrite_text('[gallery id="' . MAPPED_ID . '"]', $policy, $tokens);
$s14out = Shortcodes::apply_rewrite_text($s14cap, $policy, $tokens);
check($s14out === '[gallery id="' . MAPPED_ID . '"]', "S14: capture-then-apply round trip restores the original numeric id (got: $s14out)");

// S15 — apply-direction CSV round trip.
$tokens->warnings = [];
$s15cap = Shortcodes::capture_rewrite_text('[gallery ids="' . MAPPED_ID . ',' . MAPPED2_ID . '"]', $policy, $tokens);
$s15out = Shortcodes::apply_rewrite_text($s15cap, $policy, $tokens);
check($s15out === '[gallery ids="' . MAPPED_ID . ',' . MAPPED2_ID . '"]', "S15: CSV capture-then-apply round trip restores both original ids in order (got: $s15out)");

// S16 — determinism: capturing the SAME raw content twice is byte-
// identical (spec/repo-format.md's own "capture twice -> identical" rule).
$tokens->warnings = [];
$s16a = Shortcodes::capture_rewrite_text($s2in, $policy, $tokens);
$tokens->warnings = [];
$s16b = Shortcodes::capture_rewrite_text($s2in, $policy, $tokens);
check($s16a === $s16b, 'S16: capture-twice on the same raw (unmapped-ref) content is byte-identical');

// S17 — duplicate attribute occurrences (malformed shortcode): every
// occurrence rewritten independently rather than crashing or silently
// picking one, matching rewrite_attrs()'s own documented posture.
$tokens->warnings = [];
$s17out = Shortcodes::capture_rewrite_text('[gallery id="' . MAPPED_ID . '" id="' . MAPPED2_ID . '"]', $policy, $tokens);
check($s17out === '[gallery id="{{post:' . MAPPED_UUID . '}}" id="{{post:' . MAPPED2_UUID . '}}"]',
    "S17: duplicate 'id' attribute -- both occurrences rewritten independently, neither crashes nor is silently dropped (got: $s17out)");

// S18 — Blocks.php INTEGRATION: a shortcode sitting inside real block
// content is rewritten via the actual production entry point
// (Capture::build_post()'s own Blocks::capture_rewrite() call), proving
// the $rewriteString wiring itself, not just Shortcodes.php in isolation.
$tokens->warnings = [];
$tokens->unscopedShortcodeRefs = [];
$s18in = '<!-- wp:paragraph -->' . "\n"
    . '<p>Check out [gallery id="' . MAPPED_ID . '"] and this one [gallery id="' . UNMAPPED_ID . '"] too.</p>' . "\n"
    . '<!-- /wp:paragraph -->';
$s18out = Blocks::capture_rewrite($s18in, $policy, $tokens);
check(str_contains($s18out, '[gallery id="{{post:' . MAPPED_UUID . '}}"]'), 'S18: mapped shortcode ref rewritten via the REAL Blocks::capture_rewrite() entry point (got: ' . $s18out . ')');
check(!str_contains($s18out, (string) UNMAPPED_ID), 'S18: unmapped shortcode ref dropped via the same real entry point');
check(str_contains($s18out, '[gallery]'), 'S18: the dropped instance still leaves a well-formed empty [gallery] shortcode');

// ======================================================================
// PART 2 — Lint.php: unregistered_shortcode_attr / unrewritten_registered_shortcode_ref
// ======================================================================
echo "\n== Lint.php: shortcode findings ==\n";

$stateDir = sys_get_temp_dir() . '/wprism_regress_shortcode_refs_' . bin2hex(random_bytes(4));
register_shutdown_function(fn() => rrmdir($stateDir));

function write_fixture_post(string $stateDir, string $slug, string $uuid, string $body, array $meta = []): string {
    $front = [
        'uuid' => $uuid, 'type' => 'post', 'slug' => $slug, 'title' => $slug,
        'status' => 'publish', 'meta' => $meta === [] ? (object) [] : $meta,
    ];
    $path = "$stateDir/posts/post/$uuid--$slug.md";
    Canon::write_file($path, Canon::post_file($front, $body));
    return "posts/post/$uuid--$slug.md";
}

// L1 -- registered path + already-tokenized value: no finding.
$l1path = write_fixture_post($stateDir, 'l1-registered-token', '01980000-0004-7000-8000-000000000001',
    '[gallery id="{{post:' . MAPPED_UUID . '}}"]');

// L2 -- registered path + raw int survivor: the new finding.
$l2path = write_fixture_post($stateDir, 'l2-registered-raw', '01980000-0004-7000-8000-000000000002',
    '[gallery id="' . UNMAPPED_ID . '"]');

// L3 -- UNREGISTERED id-shaped attr on a DECLARED tag ('user_id' has no
// shortcode_attrs rule for 'gallery' in this fixture's policy). Uses
// looks_like_id_KEY()'s underscore-suffix branch deliberately, not
// looks_like_id_ATTR()'s camelCase one: shortcode_parse_atts() strtolower()
// s every attribute name unconditionally (confirmed by reading it
// directly), so a source-text camelCase name can never survive to be
// checked -- scan_shortcodes() reuses looks_like_id_key() for exactly this
// reason (see its own comment at the call site). Resolves to a real post --
// fires regardless of resolution, same posture as unregistered_block_attr.
$l3path = write_fixture_post($stateDir, 'l3-unregistered-attr', '01980000-0004-7000-8000-000000000003',
    '[gallery user_id="701"]');

// L4 -- CSV path, BOTH elements still raw digits (simulates content
// captured BEFORE this manifest declaration existed -- the realistic way
// a declared-ref position ends up numeric in captured state; a partially-
// rewritten mix of one token + one raw digit can never arise from this
// engine's own rewrite, which always converts every csv element to a
// token or drops it, never leaves one raw -- so numeric_candidates()'s
// own all-digits-CSV shape, /^\d+(,\d+)+$/, is deliberately what's
// exercised here, not an unreachable mixed shape).
$l4path = write_fixture_post($stateDir, 'l4-csv-raw', '01980000-0004-7000-8000-000000000004',
    '[gallery ids="' . UNMAPPED_ID . ',988"]');

// L5 -- completely UNDECLARED tag ([caption]): out of scope by design,
// zero findings regardless of content -- confirms the "declared tags
// only" boundary is real, not just documented.
$l5path = write_fixture_post($stateDir, 'l5-undeclared-tag', '01980000-0004-7000-8000-000000000005',
    '[caption id="attachment_' . UNMAPPED_ID . '"]A cat.[/caption]');

// L6 — a structured/Elementor-style authored string leaf. The same
// positional shortcode rule owns this nested string, so a raw alternate is
// still a finding when it survives capture in post meta rather than body.
$l6path = write_fixture_post($stateDir, 'l6-structured-raw', '01980000-0004-7000-8000-000000000006',
    '', ['_elementor_data' => ['elements' => [['content' => '[contact-form 77 "Legacy Form"]']]]]);

$findings = Lint::scan_tree($stateDir, $policy);
$byPath = [];
foreach ($findings as $f) {
    $byPath[$f['path']][] = $f;
}

check(count($byPath[$l1path] ?? []) === 0, 'L1: registered path + token value -> zero findings (got: ' . json_encode($byPath[$l1path] ?? []) . ')');

$l2 = $byPath[$l2path] ?? [];
check(count($l2) === 1, 'L2: registered path + raw int -> exactly one finding (got ' . count($l2) . ')');
if (count($l2) === 1) {
    check($l2[0]['class'] === 'unrewritten_registered_shortcode_ref', 'L2: finding class is unrewritten_registered_shortcode_ref (got: ' . $l2[0]['class'] . ')');
    check($l2[0]['locator'] === 'shortcode.gallery.attrs.id', 'L2: locator is shortcode.gallery.attrs.id (got: ' . $l2[0]['locator'] . ')');
    check($l2[0]['value'] === UNMAPPED_ID, 'L2: value is the raw int (got: ' . json_encode($l2[0]['value']) . ')');
    check(!isset($l2[0]['matches']), 'L2: matches is absent (this id does not resolve to a live entity in this fixture)');
}

$l3 = $byPath[$l3path] ?? [];
check(count($l3) === 1, 'L3: unregistered id-shaped attr on a declared tag -> exactly one finding (got ' . count($l3) . ')');
if (count($l3) === 1) {
    check($l3[0]['class'] === 'unregistered_shortcode_attr', 'L3: finding class is unregistered_shortcode_attr (got: ' . $l3[0]['class'] . ')');
    check($l3[0]['locator'] === 'shortcode.gallery.attrs.user_id', 'L3: locator is shortcode.gallery.attrs.user_id (got: ' . $l3[0]['locator'] . ')');
    check(($l3[0]['matches']['id'] ?? null) === 701, 'L3: matches a real resolvable post -- unregistered_shortcode_attr still resolves when it can');
}

$l4 = $byPath[$l4path] ?? [];
check(count($l4) === 2, 'L4: csv with two raw digit elements -> exactly two findings, one per element (got ' . count($l4) . ')');
if (count($l4) === 2) {
    check($l4[0]['class'] === 'unrewritten_registered_shortcode_ref' && $l4[1]['class'] === 'unrewritten_registered_shortcode_ref',
        'L4: both findings are unrewritten_registered_shortcode_ref (got: ' . json_encode(array_column($l4, 'class')) . ')');
    check($l4[0]['locator'] === 'shortcode.gallery.attrs.ids[csv:0]', 'L4: first finding locates csv index 0 (got: ' . $l4[0]['locator'] . ')');
    check($l4[1]['locator'] === 'shortcode.gallery.attrs.ids[csv:1]', 'L4: second finding locates csv index 1 (got: ' . $l4[1]['locator'] . ')');
    check($l4[0]['value'] === UNMAPPED_ID, 'L4: first value is the expected raw survivor (got: ' . json_encode($l4[0]['value']) . ')');
    check($l4[1]['value'] === 988, 'L4: second value is the expected raw survivor (got: ' . json_encode($l4[1]['value']) . ')');
}

check(count($byPath[$l5path] ?? []) === 0, 'L5: completely undeclared tag [caption] -> zero findings regardless of content (got: ' . json_encode($byPath[$l5path] ?? []) . ')');

$l6 = $byPath[$l6path] ?? [];
check(count($l6) === 1, 'L6: structured positional shortcode survivor -> exactly one finding (got ' . count($l6) . ')');
if (count($l6) === 1) {
    check($l6[0]['class'] === 'unrewritten_registered_shortcode_ref', 'L6: structured finding is unrewritten_registered_shortcode_ref');
    check($l6[0]['locator'] === 'meta._elementor_data.elements[0].content.shortcode.contact-form.positional[0]',
        'L6: structured locator preserves the meta/string-leaf path (got: ' . $l6[0]['locator'] . ')');
}

check(count($findings) === 5, 'sanity: exactly 5 findings total across all 6 fixtures (L2 + L3 + L4x2 + L6) -- got ' . count($findings) . ': ' . json_encode(array_column($findings, 'class')));

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
