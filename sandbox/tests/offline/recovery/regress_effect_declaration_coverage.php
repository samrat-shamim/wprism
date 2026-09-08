<?php
/**
 * Offline invariant — declared `effects[]` are scored against observed writes,
 * report-only, and the shipped library's measured baseline is recorded here.
 *
 * WHY THIS EXISTS
 * ---------------
 * `recovery/EffectBundle.php:9-13` states the authority verbatim: "The compiled
 * inventory is the entire authority… it may never add an effect after
 * preparation", and `:121` asserts each actual effect matches exactly
 * `{effect_id, kind, manifest, phase, selector}`. So a manifest's declared
 * effects ARE recovery's rollback authority — and until
 * `WPrism\EffectDeclarationCoverage` landed, nothing compared them against what a
 * site's database actually receives. An adapter that declared FEWER effects
 * looked cleaner than one that declared more.
 *
 * WHAT THIS SUITE PINS
 * --------------------
 *  1. THE MEASURED BASELINE. Every shipped adapter scores clean over a fixture
 *     DERIVED from the tree — every surface they declare as an effect selector,
 *     as an `option:`/`table:` action trigger, as an exact option, or as a
 *     declared table. Not a hand-written journal: a hand-written one would only
 *     prove what its author already believed. The rate this produces is the
 *     published false-positive rate's seed, and the numbers are asserted
 *     exactly so a manifest edit that moves them has to move them here too.
 *  2. Under-declaration is NAMED, with its table and item, against observed
 *     journal rows.
 *  3. Over-declaration is NAMED as `unexercised` — and `unexercised` is
 *     EXPLICITLY NOT AN ERROR: a journal with nothing in it produces every
 *     observable declaration as unexercised, zero findings, and a document
 *     that returns.
 *  4. A MISSING journal is a TYPED REFUSAL. The failure this closes is a report
 *     that reads an absent table as zero rows and publishes a perfect clean
 *     sheet for a site that recorded no writes at all. `absent` and
 *     `unreadable` refuse under different reason codes; an EMPTY-but-present
 *     journal is scored, never refused, so the two are not conflated.
 *  5. REPORT-ONLY BY CONSTRUCTION. No mutation path in the shipped tree names
 *     the class, the document says so in machine-readable form, `from_facts()`
 *     contains no `throw` at all, and a journal that is nothing but findings
 *     still returns a document.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT PIN
 * -----------------------------------------
 * That the scorer is COMPLETE. The journal sees database writes only. The two
 * maximal compatible worlds project 408 effects / 121 database selectors
 * (Rank Math world) and 397 / 112 (Yoast world); the remainder selects external
 * hooks, cache namespaces and provider resources that no journal row can
 * confirm or refute. Nine of the nineteen adapters in each compatible world declare no
 * journal-observable effect at all. That is reported as
 * `scorable => false` rather than as a clean score, and asserted below, because
 * the honest answer to "can this become blocking?" has to start from how much
 * of the library it can see.
 *
 * #561 moved every number above and made that ratio WORSE, which is why they
 * are re-pinned here rather than relaxed: the-events-calendar became the
 * seventh scorable adapter, and its production-ready manifest plus core.json's
 * widened rewrite action added 56 effect rows of which only 8 are
 * journal-observable. The reviewed Polylang production-readiness port then
 * added 5 effects, 2 journal-observable, and the eighth scorable adapter. The
 * PMPro engine-absorption move then retired its one provider action effect;
 * generic row-cache invalidation is materializer behavior rather than a
 * separately dispatched action, so the total fell by one and observability did
 * not move. Redirection adds 3 projected effects, 1 journal-observable, and
 * becomes the ninth scorable adapter. WooCommerce product/variation deletion
 * adds four unique guard-table surfaces to the derived fixture without widening
 * the observable effect inventory. Rank Math adds 13 effects, 10 database
 * observable, and becomes the tenth scorable adapter. Its reviewed native
 * route-cache repair adds four exact option effects (two per repair phase),
 * two unique database surfaces and fourteen scored adapter/surface pairs.
 * Three irreversible rows
 * honestly name plugin lifecycle and native callback/cache frontiers that a
 * database journal cannot observe. Its exact schema phase adds the fourth
 * redirection table to the derived topology. The clean sheet -- 0 findings -- survived
 * these reviewed changes unchanged. The experimental WPForms declaration
 * adds 17 database surfaces and one non-journal-observable effect, without
 * adding a scorable adapter or granting production readiness. Staging its
 * bounded location provider adds two effects: one postmeta checkpoint and one
 * unobservable native request-cache effect. Each world gains one scorable
 * adapter and one scored surface, with no new fixture surface or readiness
 * promotion. That clean sheet, not the totals, is the property this suite asserts.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/CommandRefusal.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/agent/src/Adapter/ShippedIdentityInventory.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Journal.php';
require_once $root . '/agent/src/Review/EffectDeclarationCoverage.php';

use WPrism\CommandRefusalException;
use WPrism\EffectDeclarationCoverage;
use WPrism\Journal;
use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\ShippedIdentityInventory;
use WPrismTest\FakeWpdb;

// --------------------------------------------------------------- the library

$adapterLibrary = AdapterLibrary::fromSourceTree($root);
$shipped = array_map(
    static fn(WPrism\AdapterPackage $package): string => $package->name(),
    $adapterLibrary->packages()
);
sort($shipped, SORT_STRING);
// The generated runtime inventory is the exact library roster. The measured
// behavior below stays numeric so effect changes remain reviewed, while adding
// an adapter no longer requires copying a second name/count into this suite.
wprism_check_same(ShippedIdentityInventory::ADAPTER_NAMES, $shipped, 'the measured library exactly matches the generated shipped inventory');

$rankWorldPins = array_values(array_diff($shipped, ['change-wp-admin-login', 'yoast']));
$yoastWorldPins = array_values(array_diff($shipped, ['change-wp-admin-login', 'rank-math']));
$rankWorldPolicy = Policy::load(null, $rankWorldPins, true, null, $adapterLibrary);
$yoastWorldPolicy = Policy::load(null, $yoastWorldPins, true, null, $adapterLibrary);
$aioPins = ['core', 'change-wp-admin-login'];
$aioPolicy = Policy::load(null, $aioPins, true, null, $adapterLibrary);
$worldUnion = array_values(array_unique(array_merge($rankWorldPins, $yoastWorldPins, $aioPins)));
sort($worldUnion, SORT_STRING);
wprism_check_same(19, count($rankWorldPins), 'the established Rank Math world excludes Yoast and the separate AIO Login candidate');
wprism_check_same(19, count($yoastWorldPins), 'the established Yoast world excludes Rank Math and the separate AIO Login candidate');
wprism_check_same($shipped, $worldUnion, 'the three executable worlds cover every shipped adapter');

// The behavioral probes below need WooCommerce and Rank Math but not Yoast.
// Keep them attached to one valid site policy rather than manufacturing the
// now-refused all-adapter composition in a test fixture.
$policy = $rankWorldPolicy;

/**
 * A journal row in `Journal::report_read_only()` shape. Only `table`, `item`
 * and `n` are read by the scorer; the provenance columns are carried so the
 * fixture stays a faithful stand-in for the real aggregate rather than a
 * narrowed one that would hide a future coupling.
 *
 * @return array<string,mixed>
 */
function edc_row(string $table, string $item, int $n = 1): array {
    return [
        'caps' => '',
        'item' => $item,
        'manifest' => '—',
        'n' => $n,
        'proposal' => 'runtime',
        'surface' => 'cli',
        'table' => $table,
        'verdict' => 'unclassified',
    ];
}

/**
 * The DERIVED fixture: one observed write for every database surface the
 * shipped library declares, from four independent channels.
 *
 * Effect selectors and triggers alone would be circular — every row would come
 * from the same declaration it is scored against. The exact options and
 * declared tables are the non-circular half: they are what an apply actually
 * writes, and nothing about them is derived from `effects[]`.
 *
 * @return list<array<string,mixed>>
 */
function edc_derived_fixture(Policy $policy): array {
    $seen = [];
    $rows = [];
    $add = static function (string $table, string $item) use (&$rows, &$seen): void {
        $key = $table . "\0" . $item;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $rows[] = edc_row($table, $item);
    };
    foreach ($policy->effects_inventory() as $entry) {
        $selector = $entry['effect']['selector'] ?? [];
        if (($selector['scope'] ?? '') !== 'database_checkpoint') {
            continue;
        }
        if (($selector['type'] ?? '') === 'option') {
            $add('options', (string) $selector['value']);
        } else {
            $add((string) $selector['value'], '');
        }
    }
    foreach ($policy->actions() as $action) {
        foreach ((array) ($action['triggers'] ?? []) as $trigger) {
            if (is_string($trigger) && str_starts_with($trigger, 'option:')) {
                $add('options', substr($trigger, 7));
            } elseif (is_string($trigger) && str_starts_with($trigger, 'table:')) {
                $add(substr($trigger, 6), '');
            }
        }
    }
    foreach (array_keys($policy->exact_options()) as $option) {
        $add('options', (string) $option);
    }
    foreach (array_keys($policy->declared_tables()) as $table) {
        $add((string) $table, '');
    }
    return $rows;
}

/** @return array<string,mixed> */
function edc_adapter(array $report, string $name): array {
    foreach ($report['adapters'] as $row) {
        if ($row['adapter'] === $name) {
            return $row;
        }
    }
    wprism_check(false, "report has no row for adapter '$name'");
    return [];
}

echo "\n== the measured baseline: every shipped adapter across three compatible worlds ==\n";

$rankWorldFixture = edc_derived_fixture($rankWorldPolicy);
$yoastWorldFixture = edc_derived_fixture($yoastWorldPolicy);
wprism_check_same(670, count($rankWorldFixture), 'the Rank Math world fixture contains every database surface its valid policy declares');
wprism_check_same(656, count($yoastWorldFixture), 'the Yoast world fixture contains every database surface its valid policy declares');

$rankWorldBaseline = EffectDeclarationCoverage::from_facts($rankWorldPolicy, ['rows' => $rankWorldFixture]);
$yoastWorldBaseline = EffectDeclarationCoverage::from_facts($yoastWorldPolicy, ['rows' => $yoastWorldFixture]);
foreach ([
    'Rank Math world' => [$rankWorldBaseline, 408, 121, 3273],
    'Yoast world' => [$yoastWorldBaseline, 397, 112, 3750],
] as $label => [$report, $declared, $observable, $scored]) {
    wprism_check_same(EffectDeclarationCoverage::FORMAT, $report['format'], "$label names the versioned report format");
    wprism_check_same(19, $report['totals']['adapters'], "$label reports each pinned adapter, scorable or not");
    wprism_check_same($declared, $report['totals']['declared_effects'], "$label measures its complete effects inventory");
    wprism_check_same($observable, $report['totals']['observable_effects'], "$label measures every database-checkpoint selector");
    wprism_check_same(10, $report['totals']['scorable_adapters'], "$label names only adapters with journal-observable effects as scorable");
    wprism_check_same(0, $report['totals']['outside_declaration'], "$label has no write outside declared authority");
    wprism_check_same($scored, $report['baseline']['scored_surfaces'], "$label pins its executable (adapter, surface) judgement count");
    wprism_check_same(0, $report['baseline']['outside_declaration_surfaces'], "$label produces no finding");
    wprism_check_same(0.0, $report['baseline']['outside_declaration_rate'], "$label has a measured 0.0000 false-positive rate");
    wprism_check_same([], $report['unattributed'], "$label attributes every derived surface");
}

$aioBaseline = EffectDeclarationCoverage::from_facts($aioPolicy, ['rows' => edc_derived_fixture($aioPolicy)]);
wprism_check_same(0, $aioBaseline['totals']['outside_declaration'], 'AIO Login and core have no write outside declared authority');
wprism_check_same([], $aioBaseline['unattributed'], 'AIO Login and core attribute every declared fixture surface');

$coveredAdapters = array_values(array_unique(array_merge(
    array_column($rankWorldBaseline['adapters'], 'adapter'),
    array_column($yoastWorldBaseline['adapters'], 'adapter'),
    array_column($aioBaseline['adapters'], 'adapter')
)));
sort($coveredAdapters, SORT_STRING);
wprism_check_same($shipped, $coveredAdapters, 'the coherent reports cover every shipped adapter without inventing an all-adapter policy');

// The remaining single-policy probes use this valid maximal world.
$fixture = $rankWorldFixture;
$baseline = $rankWorldBaseline;

$scorable = [];
foreach ($baseline['adapters'] as $row) {
    if ($row['scorable']) {
        $scorable[] = $row['adapter'];
    }
}
wprism_check_same(
    ['core', 'elementor', 'ninja-forms', 'polylang', 'rank-math', 'redirection', 'the-events-calendar', 'woocommerce', 'wpforms-lite', 'yoast-duplicate-post'],
    $scorable,
    'the ten scorable adapters in the Rank Math world are named, so silent adapters cannot be summed as clean'
);
$yoastScorable = [];
foreach ($yoastWorldBaseline['adapters'] as $row) {
    if ($row['scorable']) {
        $yoastScorable[] = $row['adapter'];
    }
}
wprism_check_same(
    ['core', 'elementor', 'ninja-forms', 'polylang', 'redirection', 'the-events-calendar', 'woocommerce', 'wpforms-lite', 'yoast', 'yoast-duplicate-post'],
    $yoastScorable,
    'the Yoast world replaces only the incompatible Rank Math row and remains independently scorable'
);
$acf = edc_adapter($baseline, 'acf');
wprism_check_same(false, $acf['scorable'], 'an adapter with no journal-observable effect reports scorable=false');
wprism_check_same(0, $acf['observable_effects'], 'acf declares no database_checkpoint selector');
wprism_check_same(1, $acf['unobservable_effects'], 'its one declared effect is outside the journal entirely, and says so');

$polylang = edc_adapter($baseline, 'polylang');
wprism_check_same(7, $polylang['declared_effects'], 'Polylang has seven declared effects: the reviewed port added five to its two pre-existing effects');
wprism_check_same(2, $polylang['observable_effects'], 'two Polylang effects are database-checkpoint observable, making it scorable');
wprism_check_same(2, $polylang['exercised_effects'], 'the derived fixture exercises both Polylang database effects');

foreach ([$rankWorldBaseline, $yoastWorldBaseline] as $world) {
    $wpforms = edc_adapter($world, 'wpforms-lite');
    wprism_check_same(3, $wpforms['declared_effects'], 'WPForms retains its prior external effect and adds exactly two location-provider effects');
    wprism_check_same(1, $wpforms['observable_effects'], 'only the WPForms postmeta checkpoint is journal-observable');
    wprism_check_same(2, $wpforms['unobservable_effects'], 'the native request cache and prior external effect cannot be proved by database writes');
    wprism_check_same(1, $wpforms['exercised_effects'], 'each complete world exercises precisely the WPForms database effect');
}
$wpformsLocationEffects = [];
foreach ($rankWorldPolicy->effects_inventory() as $entry) {
    if ($entry['manifest'] === 'wpforms-lite' && str_starts_with($entry['effect']['id'], 'wpforms-location-')) {
        $wpformsLocationEffects[$entry['effect']['id']] = [$entry['phase'], $entry['effect']];
    }
}
wprism_check_same([
    'wpforms-location-native-cache' => ['rebuild', [
        'id' => 'wpforms-location-native-cache', 'kind' => 'external', 'mode' => 'irreversible',
        'selector' => ['scope' => 'external', 'type' => 'provider_resource', 'value' => 'wpforms-location-native-readers:v1:request-object-cache'],
    ]],
    'wpforms-location-rows' => ['rebuild', [
        'id' => 'wpforms-location-rows', 'kind' => 'database', 'mode' => 'restorable',
        'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'postmeta'],
    ]],
], $wpformsLocationEffects, 'the measured WPForms increment is exactly its reviewed postmeta and request-cache authority');

$rankMath = edc_adapter($baseline, 'rank-math');
wprism_check_same(17, $rankMath['declared_effects'], 'Rank Math declares every schema, lifecycle and rebuild side effect');
wprism_check_same(14, $rankMath['observable_effects'], 'fourteen Rank Math effects are database-checkpoint observable');
wprism_check_same(3, $rankMath['unobservable_effects'], 'three Rank Math effects name irreversible provider and plugin-lifecycle frontiers');
wprism_check_same(14, $rankMath['exercised_effects'], 'the derived fixture exercises every observable Rank Math effect');

// Fixed identities prove the additional count is exactly the two reviewed
// cache rows in both repair phases, not an accidental table-wide grant.
$rankCacheEffects = [];
foreach ($rankWorldPolicy->effects_inventory() as $entry) {
    if ($entry['manifest'] === 'rank-math' && str_contains($entry['effect']['id'], 'native-route-cache')) {
        $rankCacheEffects[$entry['effect']['id']] = [$entry['phase'], $entry['effect']];
    }
}
$expectedRankCacheEffects = [];
foreach (['lifecycle-' => 'lifecycle-settle', '' => 'rebuild'] as $prefix => $phase) {
    foreach (['' => '_transient_wc_term_counts', '-expiry' => '_transient_timeout_wc_term_counts'] as $suffix => $option) {
        $id = 'rank-math-' . $prefix . 'native-route-cache' . $suffix;
        $expectedRankCacheEffects[$id] = [$phase, [
            'id' => $id, 'kind' => 'database', 'mode' => 'restorable',
            'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => $option],
        ]];
    }
}
wprism_check_same($expectedRankCacheEffects, $rankCacheEffects, 'both Rank Math repair phases declare only the two exact checkpoint-restorable native cache options');
$cacheOnly = edc_adapter(EffectDeclarationCoverage::from_facts($rankWorldPolicy, ['rows' => [
    edc_row('options', '_transient_wc_term_counts'),
    edc_row('options', '_transient_timeout_wc_term_counts'),
]]), 'rank-math');
wprism_check_same(4, $cacheOnly['exercised_effects'], 'two native cache writes exercise exactly four Rank Math phase-specific effects');

$yoast = edc_adapter($yoastWorldBaseline, 'yoast');
wprism_check_same(6, $yoast['declared_effects'], 'Yoast declares all six effects in its maximal compatible world');
wprism_check_same(5, $yoast['observable_effects'], 'five Yoast effects are database-checkpoint observable');
wprism_check_same(5, $yoast['exercised_effects'], 'the Yoast-world fixture exercises every observable Yoast effect');

$woo = edc_adapter($baseline, 'woocommerce');
wprism_check_same(280, $woo['declared_effects'], 'woocommerce declares 280 of the Rank Math world\'s 408 effect rows');
wprism_check_same(86, $woo['observable_effects'], '86 of them are journal-observable');
wprism_check_same(86, $woo['exercised_effects'], 'the derived fixture exercises every one of them');
wprism_check_same([], $woo['unexercised_effects'], 'nothing is left unexercised when every declared surface is written');

echo "\n== an under-declared write is NAMED against observed journal rows ==\n";

// `woocommerce_reserved_stock_probe` is inside woocommerce's declared option
// namespace (`^(?:action_scheduler|wc|woocommerce)_`, manifests/woocommerce.json)
// and is classified by nothing and declared as no effect. Neither the apply
// ledger nor the checkpoint inventory has authority over that write, which is
// exactly the gap `effects[]` is supposed to close.
$underDeclaredPolicy = clone $policy;
foreach ($underDeclaredPolicy->manifests as &$manifest) {
    if (($manifest['name'] ?? null) !== 'woocommerce') {
        continue;
    }
    foreach ($manifest['actions'] as &$action) {
        $action['effects'] = array_values(array_filter(
            (array) ($action['effects'] ?? []),
            static fn(array $effect): bool => ($effect['selector']['value'] ?? null) !== 'options'
        ));
    }
    unset($action);
}
unset($manifest);
$underDeclared = EffectDeclarationCoverage::from_facts($underDeclaredPolicy, [
    'rows' => array_merge($fixture, [edc_row('options', 'woocommerce_reserved_stock_probe', 3)]),
]);
$wooUnder = edc_adapter($underDeclared, 'woocommerce');
wprism_check_same(
    [['item' => 'woocommerce_reserved_stock_probe', 'observations' => 3, 'table' => 'options']],
    $wooUnder['outside_declaration'],
    'the under-declared write is named by table, item and observation count against woocommerce'
);
wprism_check_same(1, $underDeclared['totals']['outside_declaration'], 'exactly one finding — the baseline did not become noisy around it');
wprism_check_same(1, $underDeclared['baseline']['outside_declaration_surfaces'], 'the published rate moves with the finding');
wprism_check(
    $underDeclared['baseline']['outside_declaration_rate'] > 0.0,
    'a real finding raises the published rate above the clean baseline'
);
wprism_check_same([], $underDeclared['unattributed'], 'a namespace-owned write is attributed, not silently dropped as unattributed');

// The other half of "named": a write NO adapter's territory claims is reported
// at the top level rather than disappearing.
$foreign = EffectDeclarationCoverage::from_facts($policy, [
    'rows' => [edc_row('zzz_unknown_plugin_table', '', 7)],
]);
wprism_check_same(
    [['item' => '', 'observations' => 7, 'table' => 'zzz_unknown_plugin_table']],
    $foreign['unattributed'],
    'a write no adapter territory claims is reported as unattributed, never dropped'
);
wprism_check_same(0, $foreign['totals']['outside_declaration'], 'an unattributed write is not charged to an adapter that never claimed it');

echo "\n== an over-declared effect is NAMED as unexercised, and that is not an error ==\n";

// An EMPTY-but-present journal. Every journal-observable declaration is
// unexercised; nothing is a finding. Treating this as a defect would punish
// exactly the over-declaration this report exists to reward.
$empty = EffectDeclarationCoverage::from_facts($policy, ['rows' => []]);
wprism_check_same(121, $empty['totals']['unexercised'], 'all 121 Rank Math-world database declarations report unexercised against an empty journal');
wprism_check_same(0, $empty['totals']['outside_declaration'], 'unexercised is NOT an error: an empty journal produces zero findings');
wprism_check_same(false, $empty['blocking'], 'the document still says it blocks nothing');
wprism_check_same(null, $empty['baseline']['outside_declaration_rate'], 'a rate over zero scored surfaces is null, never a fabricated 0');
$wooEmpty = edc_adapter($empty, 'woocommerce');
wprism_check(
    in_array('woocommerce-product-meta-lookup', $wooEmpty['unexercised_effects'], true),
    'the unexercised declaration is named by its exact effect id, not merely counted'
);
wprism_check_same(86, count($wooEmpty['unexercised_effects']), 'every one of woocommerce\'s 86 observable declarations is named');
wprism_check_same([], $wooEmpty['outside_declaration'], 'an adapter whose action never fired is not a finding');

// One declaration exercised, the rest not — the mixed case a real observation
// window produces.
$partial = EffectDeclarationCoverage::from_facts($policy, [
    'rows' => [edc_row('wc_product_meta_lookup', '', 2)],
]);
$wooPartial = edc_adapter($partial, 'woocommerce');
wprism_check_same(3, $wooPartial['exercised_effects'], 'all three declarations naming wc_product_meta_lookup are exercised by one observed write');
wprism_check_same(83, count($wooPartial['unexercised_effects']), 'the other 83 stay named as unexercised');
wprism_check_same(2, $wooPartial['declared_writes'], 'the observed write counts as declared, not as a finding');
wprism_check_same(0, $wooPartial['classified_writes'], 'a surface inside a declared effect selector is scored declared, not merely classified');

echo "\n== a MISSING journal is a typed refusal, never an empty result ==\n";

$present = FakeWpdb::install();
$present->seedTable('wp_wprism_journal', []);
wprism_check_same('present', Journal::table_state($present), 'a seeded journal table probes as present');
// The distinction this whole path exists for: present-and-empty is a SCORE
// (above), absent is a REFUSAL (below). Conflating them publishes "no write
// fell outside a declaration" for a site that recorded nothing.
wprism_check_same(0, $empty['totals']['outside_declaration'], 'a present but empty journal is scored, not refused');

$absent = FakeWpdb::install();
wprism_check_same('absent', Journal::table_state($absent), 'a usable $wpdb with no journal table probes as absent');
wprism_check_refuses(
    static fn() => EffectDeclarationCoverage::report(['core']),
    'effect_coverage_journal_absent',
    'an absent journal refuses by reason code instead of scoring a clean sheet'
);

$unreadable = FakeWpdb::install();
$unreadable->seedTable('wp_wprism_journal', []);
$unreadable->failNextQuery('injected journal prerequisite failure', 'SHOW TABLES', 4);
wprism_check_same('unreadable', Journal::table_state($unreadable), 'a failed probe is unreadable, never absent');
$unreadable->failNextQuery('injected journal prerequisite failure', 'SHOW TABLES', 4);
wprism_check_refuses(
    static fn() => EffectDeclarationCoverage::report(['core']),
    'effect_coverage_journal_unreadable',
    'a failed journal read refuses as unreadable rather than as an absent table'
);

$aggregateUnreadable = FakeWpdb::install();
$aggregateUnreadable->seedTable('wp_wprism_journal', []);
$aggregateUnreadable->failNextQuery(
    'injected journal aggregate failure',
    'SELECT tbl, item, surface, caps, proposal'
);
$aggregateReason = null;
$aggregatePreviousReason = null;
try {
    EffectDeclarationCoverage::report(['core']);
} catch (CommandRefusalException $failure) {
    $aggregateReason = $failure->reasonCode;
    $aggregatePreviousReason = $failure->getPrevious() instanceof CommandRefusalException
        ? $failure->getPrevious()->reasonCode
        : null;
}
wprism_check(
    $aggregateReason === 'effect_coverage_journal_unreadable'
        && $aggregatePreviousReason === 'journal_evidence_unreadable',
    'a failed aggregate SELECT is translated from the neutral repository fact into the effect-coverage contract'
);

$GLOBALS['wpdb'] = null;
wprism_check_same('unusable', Journal::table_state($GLOBALS['wpdb']), 'no usable $wpdb probes as unusable');
$legacyWpdbShape = new class() {
    public string $prefix = 'wp_';

    public function prepare(string $query, mixed ...$args): string {
        return $query;
    }

    public function get_var(string $query): mixed {
        throw new RuntimeException('an unusable database shape must not be queried');
    }
};
wprism_check_same(
    'unusable',
    Journal::table_state($legacyWpdbShape),
    'a database object without the required LIKE escaper is unusable instead of fatalling after preflight'
);
wprism_check_refuses(
    static fn() => EffectDeclarationCoverage::report(['core']),
    'effect_coverage_journal_absent',
    'an unusable $wpdb folds into the prerequisite refusal, never into a zero report'
);

// The projection seam refuses a malformed aggregate rather than guessing.
wprism_check_refuses(
    static fn() => EffectDeclarationCoverage::from_facts($policy, ['rows' => 'not-a-list']),
    'effect_coverage_invalid_facts',
    'a malformed journal aggregate refuses instead of being scored as no writes'
);
wprism_check_refuses(
    static fn() => EffectDeclarationCoverage::from_facts($policy, ['rows' => [['table' => 'options', 'item' => 'x', 'n' => '4']]]),
    'effect_coverage_invalid_facts',
    'a non-integer observation count refuses instead of being coerced'
);

echo "\n== report-only by construction: the scorer can never block an apply ==\n";

wprism_check_same(false, $baseline['authority'], 'the document declares it is not an authority');
wprism_check_same(false, $baseline['blocking'], 'the document declares it blocks nothing');

// A journal that is NOTHING BUT findings still returns a document. This is the
// whole difference between a report and a gate.
$allFindings = [];
for ($i = 0; $i < 40; $i++) {
    $allFindings[] = edc_row('options', 'woocommerce_undeclared_probe_' . $i, 5);
}
$saturated = EffectDeclarationCoverage::from_facts($underDeclaredPolicy, ['rows' => $allFindings]);
wprism_check_same(40, $saturated['totals']['outside_declaration'], 'forty findings are reported, not thrown');
// 200 scored judgements, not 40: four adapters (elementor, ninja-forms,
// polylang, yoast-duplicate-post) declare a `table:options` effect selector,
// which claims EVERY options row, so each of these 40 writes is judged five
// times and scores `declared` for four of them. That is a real
// property of a table-wide selector, and the rate reports it rather than
// hiding it.
wprism_check_same(200, $saturated['baseline']['scored_surfaces'], 'a table-wide options selector makes each options write a judgement for every adapter that declared it');
wprism_check_same(0.2, $saturated['baseline']['outside_declaration_rate'], 'a journal of nothing but findings reports its rate and still returns');
wprism_check_same(false, $saturated['blocking'], 'even a saturated report blocks nothing');

// The structural half: no mutation path in the shipped tree names the class.
// Comments and docblocks are stripped first, for the reason
// regress_platform_move_gates.php:32-35 records: AGENTS.md rule 10 makes this
// tree full of rationale-dense prose naming other classes, and a prose mention
// is not a call. Only executable tokens and string literals count — the
// bootstrap `require_once` path and the generated classmap entry are real
// references and are expected below.
$references = [];
foreach (['agent', 'cli', 'recovery'] as $shippedRoot) {
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $shippedRoot));
    foreach ($walk as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $text = is_array($token) ? (string) $token[1] : (string) $token;
            if (str_contains($text, 'EffectDeclarationCoverage')) {
                $references[] = substr($file->getPathname(), strlen($root) + 1);
                continue 2;
            }
        }
    }
}
sort($references, SORT_STRING);
wprism_check_same(
    [
        // the bootstrap require, the generated classmap, the surface verb, the class
        'agent/src/Command/Cli.php',
        'agent/src/Review/EffectDeclarationCoverage.php',
        'agent/wprism-classmap.php',
        'agent/wprism.php',
    ],
    $references,
    'nothing on the capture/plan/apply/deploy/recovery path names the scorer'
);

// `from_facts()` — the whole scoring path — contains no `throw` of any kind.
// The typed refusals live in the prerequisite probe and the malformed-facts
// guard, both of which run BEFORE a verdict exists, so no verdict can refuse.
$classSource = (string) file_get_contents($root . '/agent/src/Review/EffectDeclarationCoverage.php');
$scoringStart = strpos($classSource, 'public static function from_facts');
$scoringEnd = strpos($classSource, 'private static function observed_surfaces');
wprism_check($scoringStart !== false && $scoringEnd !== false && $scoringEnd > $scoringStart, 'the scoring body was located in the class source');
wprism_check_same(
    false,
    str_contains(substr($classSource, (int) $scoringStart, (int) $scoringEnd - (int) $scoringStart), 'throw'),
    'from_facts() contains no throw: a verdict is never a refusal'
);
wprism_check_same(
    ['classified', 'declared', 'outside_declaration'],
    EffectDeclarationCoverage::WRITE_VERDICTS,
    'the observed-write verdict set is closed and published'
);
wprism_check_same(
    ['exercised', 'unexercised', 'unobservable'],
    EffectDeclarationCoverage::EFFECT_VERDICTS,
    'the declared-effect verdict set is closed and published'
);

wprism_check_summary('effect declaration coverage');
