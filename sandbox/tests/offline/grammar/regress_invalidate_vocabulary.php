<?php
/**
 * The `invalidate[]` verb vocabulary, and the rule that decides what enters it
 * (WP-6.2; spec/repo-format.md § v3.13).
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * `ManifestGrammar::assert_invalidate_grammar()`'s refusal used to end "belongs
 * in a native action or a provider capability". That sentence is the boundary
 * that converts a declarative adapter into a `compatibility_shim` one: every
 * `providers[].source: "manifest"` row in the shipped library is a recorded
 * statement of something the declarative set could not say, and the nine shipped
 * provider files under `manifests/providers/` are 4,573 lines of that statement.
 *
 * Widening the vocabulary is therefore the highest-leverage move available AND
 * the easiest one to get wrong — "chase per-plugin behaviour into the engine one
 * verb at a time" is precisely what the boundary text refuses. So a verb enters
 * on EVIDENCE: two or more INDEPENDENT demands across the ledger and the
 * provider corpus. This suite is where that rule is enforced against the tree
 * rather than asserted in a comment — PART 1 reads the two demands out of the
 * shipped provider files themselves, so deleting the demand without deleting the
 * verb fails here.
 *
 * WHAT EACH PART PROVES
 * ---------------------
 *   PART 1 — the ADMISSION RULE. The two independent demands for
 *   `{cache_group, cache_key}` remain live in two unrelated shipped adapters
 *   and on two different sides of the entry: PMPro now consumes the admitted
 *   key-side verb declaratively, while Woo still carries the group-side demand
 *   in its provider. The single-demand shape is still single-demand, is still
 *   refused, and is still RECORDED in tools/engine-gaps.json — the ledger stays
 *   honest about what the boundary costs rather than forgetting the shapes it
 *   turned away.
 *
 *   PART 2 — the VOCABULARY. All three verbs, each closed in both directions,
 *   each with its own refusal. The `{id}` rule is proven on both members,
 *   because a rule that admitted only PMPro's key-side spelling would have
 *   generalised nothing.
 *
 *   PART 3 — the FEATURE GATE. The verb is v3-staged through `engine_features`
 *   (§ v3.2), so it ships with no version integer moving. The gate refuses by
 *   FEATURE NAME, fires on a malformed reach (presence, not shape), and cannot
 *   be minted by a `site.wprism.json` policy override.
 *
 *   PART 4 — the RUNTIME. TypedTableMaterializer executes the verb on both
 *   spellings and REFUSES when the entry survives, which is what makes the
 *   declaration equal in strength to the provider it replaces rather than a
 *   weaker imitation of it.
 *
 *   PART 5 — THE ACCEPTANCE, through the real product path. The shipped PMPro
 *   manifest has dropped BOTH its `actions[]` and its `providers[]` — that
 *   adapter's entire executable surface — in exchange for one declarative line,
 *   and `wprism manifest-validate` reports `[ok]`. Its `tier_decision()` is now
 *   `declarative_manifest`. This is intentionally fleet-visible product work:
 *   the changed manifest digest requires the ordinary recompile-and-repin flow.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

$repo = dirname(__DIR__, 4);

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 3);
}

require_once $repo . '/agent/src/Kernel/Canon.php';
require_once $repo . '/agent/src/Policy/ManifestGrammar.php';
require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
require_once $repo . '/agent/src/Adapter/AdapterSources.php';
require_once $repo . '/agent/src/Apply/TypedTableMaterializer.php';

use WPrism\AdapterContractGrammar;
use WPrism\AdapterSources;
use WPrism\ManifestGrammar;
use WPrism\TypedTableMaterializer;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

/** One `tables.<t>` declaration carrying exactly the invalidate entries given. */
$table = static fn(array $entries): array => [
    'class' => 'authored_snapshot',
    'columns' => ['label' => ['class' => 'authored']],
    'id_kind' => 'acme_thing',
    'invalidate' => $entries,
    'pk' => 'id',
    'refs' => [],
];

$accepts = static function (array $entries, string $label) use ($table): void {
    try {
        ManifestGrammar::assert_table_grammar('acme_things', $table($entries), "manifest 'acme'");
        wprism_check(true, $label);
    } catch (\Throwable $e) {
        wprism_check(false, $label);
        wprism_check_detail('unexpected refusal: ' . $e->getMessage());
    }
};

$refuses = static function (array $entries, string $needle, string $label) use ($table): void {
    try {
        ManifestGrammar::assert_table_grammar('acme_things', $table($entries), "manifest 'acme'");
        wprism_check(false, $label);
        wprism_check_detail('expected a refusal, nothing was thrown');
    } catch (\RuntimeException $e) {
        wprism_check(str_contains($e->getMessage(), $needle), $label);
        if (!str_contains($e->getMessage(), $needle)) {
            wprism_check_detail('refusal does not name "' . $needle . '"');
            wprism_check_detail('message: ' . $e->getMessage());
        }
    }
};

// =====================================================================
// PART 1 — the admission rule: two independent demands, and the ledger
// =====================================================================

$pmproManifest = json_decode(
    (string) file_get_contents($repo . '/adapter-packages/paid-memberships-pro/package/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$wooProvider = (string) file_get_contents($repo . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-product-lookups.php');
$snippetsProvider = (string) file_get_contents($repo . '/adapter-packages/code-snippets/package/runtime/providers/code-snippets-state.php');

// DEMAND 1: the id on the KEY side. PMPro now consumes the admitted primitive
// directly, proving the verb has a shipped product owner after its compatibility
// provider is removed rather than surviving only as unused engine vocabulary.
wprism_check_same(
    [['cache_group' => 'pmpro_membership_level_meta', 'cache_key' => '{id}']],
    $pmproManifest['tables']['pmpro_membership_levels']['invalidate'] ?? null,
    'DEMAND 1 is still in the tree as a shipped declarative consumer: Paid Memberships Pro drops the '
        . "current row id from 'pmpro_membership_level_meta' — the id on the KEY side"
);
wprism_check(
    !isset($pmproManifest['providers'])
        && !isset($pmproManifest['actions'])
        && !is_file($repo . '/adapter-packages/paid-memberships-pro/package/runtime/providers/paid-memberships-pro-cache.php'),
    'and the PMPro compatibility provider is actually retired rather than left digest-bound beside its replacement'
);

// DEMAND 2: the id on the GROUP side, in an unrelated plugin. This is what makes
// the "{id} in EITHER member" rule a generalisation instead of a transcription
// of PMPro's one spelling.
wprism_check(
    str_contains($wooProvider, "wp_cache_delete('lookup_table', 'object_' . \$id)"),
    'DEMAND 2 is still in the tree, and is INDEPENDENT: manifests/providers/woocommerce-product-lookups.php '
        . "drops wp_cache_delete('lookup_table', 'object_<row id>') — the same primitive with the id on the "
        . 'GROUP side, in a different plugin'
);

// The single-demand shape. Code Snippets drops a cache entry SHARED by every row
// of its table (neither member carries an id). One demand is not two, so it stays
// out of the engine vocabulary. The certified adapter closes that product demand
// through its verified provider; the implementation detail is therefore not an
// open platform primitive competing in the demand ranking.
wprism_check(
    str_contains($snippetsProvider, 'wp_cache_delete(\\Code_Snippets\\Settings\\CACHE_KEY, \\Code_Snippets\\CACHE_GROUP)'),
    'THE SINGLE-DEMAND SHAPE is real and is still single: manifests/providers/code-snippets-state.php drops a '
        . 'table-scoped entry whose key and group are both literal — one demand, so it does not enter'
);

$ledger = json_decode((string) file_get_contents($repo . '/tools/engine-gaps.json'), true, 512, JSON_THROW_ON_ERROR);

wprism_check(
    ($ledger['primitives']['row_cache_entry_invalidation']['status'] ?? null) === 'shipped',
    'the ledger records the ADMITTED verb as shipped: primitive `row_cache_entry_invalidation`'
);
wprism_check(
    !array_key_exists('table_scoped_cache_entry_invalidation', (array) ($ledger['primitives'] ?? [])),
    'the Code Snippets-only table-wide cache shape is not promoted into an open platform primitive after its '
        . 'certified provider closed the product demand'
);

$demandFor = static function (array $ledger, string $primitive): array {
    $out = [];
    foreach ($ledger['candidates'] as $row) {
        foreach ($row['coordinates'] as $coordinate) {
            if (($coordinate['primitive_required'] ?? null) === $primitive) {
                $out[(string) $row['candidate']] = true;
            }
        }
    }
    $names = array_keys($out);
    sort($names, SORT_STRING);
    return $names;
};

wprism_check(
    in_array('Code Snippets', $demandFor($ledger, 'verified_provider_postcondition'), true),
    'the ledger keeps the Code Snippets demand attached to the generic verified-provider facility that closes it, '
        . 'without minting a plugin-named platform primitive'
);
wprism_check(
    in_array('Paid Memberships Pro', $demandFor($ledger, 'row_cache_entry_invalidation'), true),
    'the admitted verb names the candidate it closed: Paid Memberships Pro'
);

// =====================================================================
// PART 2 — the vocabulary: three verbs, each closed in both directions
// =====================================================================

$accepts([['table' => 'nf3_upgrades', 'column' => 'id']], 'VERB 1 {table, column}: unchanged targeted row delete');
$accepts([['option_pattern' => 'nf_form_{id}']], 'VERB 2 {option_pattern}: unchanged named option');
$accepts(
    [['cache_group' => 'pmpro_membership_level_meta', 'cache_key' => '{id}']],
    'VERB 3 {cache_group, cache_key}, key-side {id}: PMPro\'s spelling is now declarable'
);
$accepts(
    [['cache_group' => 'object_{id}', 'cache_key' => 'lookup_table']],
    'VERB 3, group-side {id}: WooCommerce\'s spelling is declarable by the SAME verb — the generalisation'
);
$accepts(
    [
        ['cache_group' => 'object_{id}', 'cache_key' => 'lookup_table'],
        ['option_pattern' => 'nf_form_{id}'],
        ['table' => 'nf3_upgrades', 'column' => 'id'],
    ],
    'the three verbs compose in one invalidate[] list'
);

$refuses(
    [['cache_group' => 'g']],
    'the invalidation vocabulary is closed and engine-owned',
    'the vocabulary stays CLOSED: {cache_group} alone is not a verb, and the refusal enumerates all three'
);
$refuses(
    [['cache_group' => 'g{id}', 'cache_key' => 'k', 'ttl' => 60]],
    'the invalidation vocabulary is closed and engine-owned',
    'closed in the OTHER direction too: a fourth member alongside a legal pair is refused, not ignored'
);
$refuses(
    [['cache_group' => 'code_snippets', 'cache_key' => 'settings']],
    'belongs in the top-level actions channel',
    'THE ADMISSION RULE, ENFORCED IN THE GRAMMAR: neither member carries {id}, so this is the blanket case '
        . 'Code Snippets alone demands — refused, and the refusal names where a blanket cache does belong'
);
$refuses(
    [['cache_group' => '', 'cache_key' => '{id}']],
    'must be a non-empty string',
    'an empty cache_group is refused'
);
$refuses(
    [['cache_group' => 'g', 'cache_key' => 42]],
    'must be a non-empty string',
    'a non-string cache_key is refused rather than coerced'
);
$refuses(
    [['cache_group' => "bad group\n", 'cache_key' => '{id}']],
    'must be [A-Za-z0-9_.:-] outside its {id} substitution points',
    'a cache name carrying whitespace/control bytes is refused AT LOAD, not at apply time on one row — the '
        . 'live bound CacheInvalidationTransaction::assert_cache_group() enforces becomes unreachable'
);
$refuses(
    [['cache_group' => 'g{slug}', 'cache_key' => '{id}']],
    'a second kind of brace',
    'a second substitution point this engine does not implement is refused rather than passed through literally'
);
$refuses(
    [['cache_group' => str_repeat('g', 161), 'cache_key' => '{id}']],
    'over the 160-byte budget',
    'the byte budget is checked on the DECLARATION: 160 + (20 - 4) = 176 still fits the live 191-byte bound'
);

// =====================================================================
// PART 3 — the feature gate: v3-staged, no version bump
// =====================================================================

$FEATURE = ManifestGrammar::INVALIDATE_VOCABULARY_FEATURE;
wprism_check_same('invalidate-vocabulary/v1', $FEATURE, 'the gating feature name');

wprism_check(
    in_array($FEATURE, AdapterContractGrammar::implemented_features(), true),
    'the engine IMPLEMENTS the feature, so a manifest may declare it (§ v3.2: a name nothing implements is '
        . 'refused as unimplemented rather than admitted as forward-looking)'
);
wprism_check_same(
    [],
    AdapterContractGrammar::admitted_feature_keys(['engine_features' => [$FEATURE]]),
    'AND IT CLAIMS NO TOP-LEVEL KEY: this feature widens a value vocabulary inside a section that already '
        . 'exists, so § v3.3\'s partition does not move and no manifest gains a section'
);
wprism_check_same(
    33,
    count(AdapterContractGrammar::admitted_top_level_keys([])),
    'the closed top-level key set is still 33 (register row R-21) — a value-vocabulary feature must not '
        . 'quietly widen the key set'
);

$gated = ['pmpro_membership_levels' => $table([['cache_group' => 'pmpro_membership_level_meta', 'cache_key' => '{id}']])];

wprism_check_throws(
    static fn() => ManifestGrammar::validate_tables(['tables' => $gated], "manifest 'acme'"),
    \RuntimeException::class,
    'THE GATE REFUSES BY FEATURE NAME when the manifest has not declared it — never by mis-reading the '
        . 'declaration, which is what lets the verb ship with no version bump',
    "the engine feature 'invalidate-vocabulary/v1' gates"
);
try {
    ManifestGrammar::validate_tables(
        ['engine_features' => [$FEATURE], 'tables' => $gated],
        "manifest 'acme'"
    );
    wprism_check(true, 'and admits the identical declaration once the feature is declared');
} catch (\Throwable $e) {
    wprism_check(false, 'and admits the identical declaration once the feature is declared');
    wprism_check_detail('unexpected refusal: ' . $e->getMessage());
}

wprism_check_throws(
    static fn() => ManifestGrammar::validate_tables(
        ['tables' => ['acme_things' => $table([['cache_group' => 42]])]],
        "manifest 'acme'"
    ),
    \RuntimeException::class,
    'THE GATE FIRES ON A MALFORMED REACH: recognition is by PRESENCE of the member key, so a broken '
        . 'declaration cannot slip past the privilege its well-formed spelling is gated on',
    "the engine feature 'invalidate-vocabulary/v1' gates"
);

// SitePolicyValidator::validate() hands validate_tables() `$site['policy']` with
// the SITE marker set. The fixture writes the feature list into that policy
// object on purpose: `engine_features` is a manifest section, so this is the
// exact document that must NOT be able to mint one. Without the marker the list
// would look like a manifest's and the site half would become the one place a
// gated verb can be unlocked by a document nothing validates it in.
$sitePolicy = ['engine_features' => [$FEATURE], 'tables' => $gated];
wprism_check_throws(
    static fn() => ManifestGrammar::validate_tables($sitePolicy, 'site policy', true),
    \RuntimeException::class,
    'A SITE POLICY CANNOT MINT THE FEATURE: even spelling the feature list into `policy`, a site table '
        . 'override is held to the ungated vocabulary',
    "the engine feature 'invalidate-vocabulary/v1' gates"
);
wprism_check(
    (bool) preg_match(
        '/ManifestGrammar::validate_tables\(\$site\[.policy.\] \?\? \[\], \$label, true\)/',
        (string) file_get_contents($repo . '/agent/src/Policy/SitePolicyValidator.php')
    ),
    'and the site validator actually passes the marker — the flag is worth nothing if the one caller that '
        . 'needs it forgets, so the wiring is asserted rather than assumed'
);

wprism_check_same(
    // The sections the OTHER features claim (WP-4.2, WP-6.1, WP-6.4, WP-6.5) —
    // and deliberately NOT one for this rider: the assertion's point is that a
    // feature with no `keys` contributes no section floor, and the merged
    // roster is what makes that visible rather than vacuous. It grows with
    // every feature that DOES claim a key, which is the half that keeps this
    // assertion honest — `body_refs` (WP-6.5) is here and
    // `invalidate-vocabulary/v1` is still not.
    [
        'attr_id_codecs' => 3,
        'block_content' => 3,
        'block_media_derivatives' => 3,
        'block_values' => 3,
        'body_refs' => 3,
        'column_codecs' => 3,
        'declaration_evidence' => 3,
        'engine_features' => 3,
        'incompatible_plugins' => 3,
    ],
    AdapterContractGrammar::section_min_spec(),
    'section_min_spec() gains no floor from this rider: a feature with no `keys` contributes none, so no manifest '
        . 'starts refusing a section it already declares'
);

// =====================================================================
// PART 4 — the runtime: the entry is dropped, and the drop is PROVEN
// =====================================================================

$store = WpStore::reset();
$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_acme_things', [['id' => 7, 'label' => 'before', 'parent_id' => 0]]);

$cacheDeletes = [];
$materializer = new TypedTableMaterializer(
    static fn(): array => [],
    static fn(): array => [],
    static fn(string $uuid, string $kind): ?int => null,
    static function (): void {},
    static fn(string $t, array $c): int => 0,
    static fn(int $p): array => [0, 0],
    static fn(array $d, string $k): bool => true,
    static fn(mixed $v): mixed => is_string($v) ? $v : serialize($v),
    static function (string|int $key, string $group) use (&$cacheDeletes, $store): void {
        $cacheDeletes[] = "$group/$key";
        unset($store->cache[$group][(string) $key]);
    },
    // WP-6.1's tenth capability, added by the sibling rider merged beside this
    // one: the column-codec projection. These fixtures declare no
    // `column_codecs`, so the honest answer for every table is the empty rule
    // set — supplied HERE because the constructor deliberately has no default.
    static fn(string $table): array => []
);

// No setAccessible(): it is a no-op since PHP 8.1 and deprecated in 8.5, and a
// deprecation notice would put a warning in a suite that must be silent to pass.
$run = (new ReflectionClass(TypedTableMaterializer::class))->getMethod('runInvalidation');

$store->cache['pmpro_membership_level_meta']['7'] = ['stale' => true];
$run->invoke($materializer, ['cache_group' => 'pmpro_membership_level_meta', 'cache_key' => '{id}'], 7);
wprism_check_same(
    ['pmpro_membership_level_meta/7'],
    $cacheDeletes,
    'RUNTIME, key-side: {id} substitutes into cache_key and the exact entry is dropped'
);

$cacheDeletes = [];
$store->cache['object_7']['lookup_table'] = ['stale' => true];
$run->invoke($materializer, ['cache_group' => 'object_{id}', 'cache_key' => 'lookup_table'], 7);
wprism_check_same(
    ['object_7/lookup_table'],
    $cacheDeletes,
    'RUNTIME, group-side: {id} substitutes into cache_group — WooCommerce\'s spelling runs on the same code'
);

// The readback is what makes the declaration as strong as the provider it
// replaces. The retired paid-memberships-pro-cache provider refused when an
// entry survived; so does this, rather than reporting success for a delete the
// backend ignored.
$cacheDeletes = [];
$survives = new TypedTableMaterializer(
    static fn(): array => [],
    static fn(): array => [],
    static fn(string $uuid, string $kind): ?int => null,
    static function (): void {},
    static fn(string $t, array $c): int => 0,
    static fn(int $p): array => [0, 0],
    static fn(array $d, string $k): bool => true,
    static fn(mixed $v): mixed => (string) $v,
    static function (string|int $key, string $group) use (&$cacheDeletes): void {
        $cacheDeletes[] = "$group/$key"; // a backend that acknowledges and keeps the value
    },
    // The codec projection again — empty for these codec-less fixtures.
    static fn(string $table): array => []
);
$store->cache['pmpro_membership_level_meta']['9'] = ['stale' => true];
wprism_check_throws(
    static fn() => $run->invoke($survives, ['cache_group' => 'pmpro_membership_level_meta', 'cache_key' => '{id}'], 9),
    \RuntimeException::class,
    'AN UNVERIFIED INVALIDATION IS REFUSED: a delete the cache backend ignored is exactly the stale read the '
        . 'declaration exists to prevent, so it must not be reported as success',
    'left \'9\' cached in group \'pmpro_membership_level_meta\''
);

// =====================================================================
// PART 5 — THE ACCEPTANCE, through the real product path
// =====================================================================

$shipped = $pmproManifest;

wprism_check_same(
    ['tier_basis' => 'no interpreter, regenerator, provider, or native action is declared',
     'trust_tier' => 'declarative_manifest'],
    AdapterSources::tier_decision($shipped),
    'THE ACCEPTANCE: with its cache invalidation expressed declaratively, shipped PMPro\'s whole executable surface '
        . '— one action, one provider, one wp_cache_delete() loop — is gone and the tier DROPS from '
        . 'compatibility_shim to declarative_manifest'
);
wprism_check_same(
    ['invalidate-vocabulary/v1', 'spec-window/v1'],
    $shipped['engine_features'] ?? null,
    'the shipped adapter explicitly negotiates the post-v3 invalidate vocabulary before using it'
);

$cmd = implode(' ', array_map('escapeshellarg', [
    PHP_BINARY,
    $repo . '/cli/wprism',
    'manifest-validate',
    $repo,
    '--manifest=paid-memberships-pro',
]));
$pipes = [];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$stdout = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
$stderr = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
if (is_resource($proc)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}
wprism_check(
    str_contains($stdout, '[ok] paid-memberships-pro'),
    'AND IT LOADS THROUGH THE PRODUCT PATH: the real `wprism manifest-validate` reports [ok] for the shipped '
        . 'declarative PMPro — the migration is reachable by the product, not only by this suite'
);
if (!str_contains($stdout, '[ok] paid-memberships-pro')) {
    wprism_check_detail('stdout: ' . substr($stdout, 0, 1200));
    wprism_check_detail('stderr: ' . substr($stderr, 0, 600));
}

wprism_check(
    !array_key_exists('providers', $shipped)
        && !array_key_exists('actions', $shipped)
        && ($shipped['spec_version'] ?? null) === 3,
    'AND THE SHIPPED IDENTITY STATES THE NEW BOUNDARY: no executable declaration remains and spec v3 carries '
        . 'the feature-gated declarative verb. Operators recompile and re-pin this intentional digest change'
);

wprism_check_summary('regress_invalidate_vocabulary');
