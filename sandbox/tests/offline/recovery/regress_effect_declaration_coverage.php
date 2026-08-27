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
 * `Duo\EffectDeclarationCoverage` landed, nothing compared them against what a
 * site's database actually receives. An adapter that declared FEWER effects
 * looked cleaner than one that declared more.
 *
 * WHAT THIS SUITE PINS
 * --------------------
 *  1. THE MEASURED BASELINE. The 16 shipped adapters score clean over a fixture
 *     DERIVED from the tree — every surface they declare as an effect selector,
 *     as an `option:`/`table:` action trigger, as an exact option, or as a
 *     declared table. Not a hand-written journal: a hand-written one would only
 *     prove what its author already believed. The rate this produces is the
 *     published false-positive rate's seed, and the numbers are asserted
 *     exactly so a manifest edit that moves them has to move them here too.
 *  2. Under-declaration is NAMED, with its table and item, against observed
 *     journal rows.
 *  3. Over-declaration is NAMED as `unexercised` — and `unexercised` is
 *     EXPLICITLY NOT AN ERROR: a journal with nothing in it produces 64
 *     unexercised declarations, zero findings, and a document that returns.
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
 * That the scorer is COMPLETE. The journal sees database writes only, so of the
 * 381 effect rows the shipped library projects, 101 carry a `database_checkpoint`
 * selector and 183 select external hooks, cache namespaces and provider
 * resources that no journal row can confirm or refute. Eight of the sixteen
 * adapters declare no journal-observable effect at all. That is reported as
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
 * not move. The
 * clean sheet -- 0 findings -- survived these reviewed changes unchanged, and that,
 * not the totals, is the property this suite asserts.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';

duo_test_define_agent_versions();
$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/CommandRefusal.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Review/Journal.php';
require_once $root . '/agent/src/Review/EffectDeclarationCoverage.php';

use Duo\CommandRefusalException;
use Duo\EffectDeclarationCoverage;
use Duo\Journal;
use Duo\Policy;
use DuoTest\FakeWpdb;

// --------------------------------------------------------------- the library

$shipped = [];
foreach (glob($root . '/manifests/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name !== 'dispositions') {
        $shipped[] = $name;
    }
}
sort($shipped, SORT_STRING);
// AGENTS.md's repo map says "16 adapters in all". Every count below is stated
// per-adapter, so a seventeenth manifest arriving silently would shift them all
// without naming itself.
duo_check_same(16, count($shipped), 'the shipped manifest library is the 16 adapters the baseline is measured over');

$policy = Policy::load(null, $shipped, true);

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
    duo_check(false, "report has no row for adapter '$name'");
    return [];
}

echo "\n== the measured baseline: the 16 shipped adapters over a derived fixture ==\n";

$fixture = edc_derived_fixture($policy);
duo_check_same(619, count($fixture), 'the derived fixture is every database surface the 16 shipped adapters declare');

$baseline = EffectDeclarationCoverage::from_facts($policy, ['rows' => $fixture]);

duo_check_same(EffectDeclarationCoverage::FORMAT, $baseline['format'], 'the report names its versioned format');
duo_check_same(16, $baseline['totals']['adapters'], 'every pinned adapter gets a row, scorable or not');
duo_check_same(381, $baseline['totals']['declared_effects'], 'the declared side is Policy::effects_inventory() in full');
duo_check_same(101, $baseline['totals']['observable_effects'], '101 of the 381 declared effects carry a database_checkpoint selector');
duo_check_same(8, $baseline['totals']['scorable_adapters'], 'only 8 of 16 adapters declare a journal-observable effect at all');

// THE NUMBER THE RISK FIELD ASKS TO BE PUBLISHED. 0 findings over 2178 scored
// (adapter, surface) judgements on the shipped library: the noise floor a
// later argument about making this blocking has to start from.
duo_check_same(0, $baseline['totals']['outside_declaration'], 'the 16 shipped adapters score clean: no observed write falls outside every declared effect');
duo_check_same(3554, $baseline['baseline']['scored_surfaces'], 'the published rate is measured over 3554 scored (adapter, surface) judgements');
duo_check_same(0, $baseline['baseline']['outside_declaration_surfaces'], 'no scored judgement produced a finding');
duo_check_same(0.0, $baseline['baseline']['outside_declaration_rate'], 'the published false-positive baseline over the shipped library is 0.0000');
duo_check_same([], $baseline['unattributed'], 'every derived surface is claimed by at least one adapter territory');
duo_check_detail(sprintf(
    'BASELINE: %d/%d adapters scorable, %d observable effects, %d findings over %d scored surfaces, rate %s',
    $baseline['totals']['scorable_adapters'],
    $baseline['totals']['adapters'],
    $baseline['totals']['observable_effects'],
    $baseline['baseline']['outside_declaration_surfaces'],
    $baseline['baseline']['scored_surfaces'],
    var_export($baseline['baseline']['outside_declaration_rate'], true)
));

$scorable = [];
foreach ($baseline['adapters'] as $row) {
    if ($row['scorable']) {
        $scorable[] = $row['adapter'];
    }
}
duo_check_same(
    ['core', 'elementor', 'ninja-forms', 'polylang', 'the-events-calendar', 'woocommerce', 'yoast', 'yoast-duplicate-post'],
    $scorable,
    'the eight scorable adapters are named, so the eight silent ones cannot be summed as clean'
);
$acf = edc_adapter($baseline, 'acf');
duo_check_same(false, $acf['scorable'], 'an adapter with no journal-observable effect reports scorable=false');
duo_check_same(0, $acf['observable_effects'], 'acf declares no database_checkpoint selector');
duo_check_same(1, $acf['unobservable_effects'], 'its one declared effect is outside the journal entirely, and says so');

$polylang = edc_adapter($baseline, 'polylang');
duo_check_same(7, $polylang['declared_effects'], 'Polylang has seven declared effects: the reviewed port added five to its two pre-existing effects');
duo_check_same(2, $polylang['observable_effects'], 'two Polylang effects are database-checkpoint observable, making it scorable');
duo_check_same(2, $polylang['exercised_effects'], 'the derived fixture exercises both Polylang database effects');

$woo = edc_adapter($baseline, 'woocommerce');
duo_check_same(271, $woo['declared_effects'], 'woocommerce declares 271 of the 381 effect rows');
duo_check_same(77, $woo['observable_effects'], '77 of them are journal-observable');
duo_check_same(77, $woo['exercised_effects'], 'the derived fixture exercises every one of them');
duo_check_same([], $woo['unexercised_effects'], 'nothing is left unexercised when every declared surface is written');

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
duo_check_same(
    [['item' => 'woocommerce_reserved_stock_probe', 'observations' => 3, 'table' => 'options']],
    $wooUnder['outside_declaration'],
    'the under-declared write is named by table, item and observation count against woocommerce'
);
duo_check_same(1, $underDeclared['totals']['outside_declaration'], 'exactly one finding — the baseline did not become noisy around it');
duo_check_same(1, $underDeclared['baseline']['outside_declaration_surfaces'], 'the published rate moves with the finding');
duo_check(
    $underDeclared['baseline']['outside_declaration_rate'] > 0.0,
    'a real finding raises the published rate above the clean baseline'
);
duo_check_same([], $underDeclared['unattributed'], 'a namespace-owned write is attributed, not silently dropped as unattributed');

// The other half of "named": a write NO adapter's territory claims is reported
// at the top level rather than disappearing.
$foreign = EffectDeclarationCoverage::from_facts($policy, [
    'rows' => [edc_row('zzz_unknown_plugin_table', '', 7)],
]);
duo_check_same(
    [['item' => '', 'observations' => 7, 'table' => 'zzz_unknown_plugin_table']],
    $foreign['unattributed'],
    'a write no adapter territory claims is reported as unattributed, never dropped'
);
duo_check_same(0, $foreign['totals']['outside_declaration'], 'an unattributed write is not charged to an adapter that never claimed it');

echo "\n== an over-declared effect is NAMED as unexercised, and that is not an error ==\n";

// An EMPTY-but-present journal. Every journal-observable declaration is
// unexercised; nothing is a finding. Treating this as a defect would punish
// exactly the over-declaration this report exists to reward.
$empty = EffectDeclarationCoverage::from_facts($policy, ['rows' => []]);
duo_check_same(101, $empty['totals']['unexercised'], 'all 101 journal-observable declarations report unexercised against an empty journal');
duo_check_same(0, $empty['totals']['outside_declaration'], 'unexercised is NOT an error: an empty journal produces zero findings');
duo_check_same(false, $empty['blocking'], 'the document still says it blocks nothing');
duo_check_same(null, $empty['baseline']['outside_declaration_rate'], 'a rate over zero scored surfaces is null, never a fabricated 0');
$wooEmpty = edc_adapter($empty, 'woocommerce');
duo_check(
    in_array('woocommerce-product-meta-lookup', $wooEmpty['unexercised_effects'], true),
    'the unexercised declaration is named by its exact effect id, not merely counted'
);
duo_check_same(77, count($wooEmpty['unexercised_effects']), 'every one of woocommerce\'s 77 observable declarations is named');
duo_check_same([], $wooEmpty['outside_declaration'], 'an adapter whose action never fired is not a finding');

// One declaration exercised, the rest not — the mixed case a real observation
// window produces.
$partial = EffectDeclarationCoverage::from_facts($policy, [
    'rows' => [edc_row('wc_product_meta_lookup', '', 2)],
]);
$wooPartial = edc_adapter($partial, 'woocommerce');
duo_check_same(2, $wooPartial['exercised_effects'], 'both declarations naming wc_product_meta_lookup are exercised by one observed write');
duo_check_same(75, count($wooPartial['unexercised_effects']), 'the other 75 stay named as unexercised');
duo_check_same(2, $wooPartial['declared_writes'], 'the observed write counts as declared, not as a finding');
duo_check_same(0, $wooPartial['classified_writes'], 'a surface inside a declared effect selector is scored declared, not merely classified');

echo "\n== a MISSING journal is a typed refusal, never an empty result ==\n";

$present = FakeWpdb::install();
$present->seedTable('wp_duo_journal', []);
duo_check_same('present', Journal::table_state($present), 'a seeded journal table probes as present');
// The distinction this whole path exists for: present-and-empty is a SCORE
// (above), absent is a REFUSAL (below). Conflating them publishes "no write
// fell outside a declaration" for a site that recorded nothing.
duo_check_same(0, $empty['totals']['outside_declaration'], 'a present but empty journal is scored, not refused');

$absent = FakeWpdb::install();
duo_check_same('absent', Journal::table_state($absent), 'a usable $wpdb with no journal table probes as absent');
duo_check_refuses(
    static fn() => EffectDeclarationCoverage::report(['core']),
    'effect_coverage_journal_absent',
    'an absent journal refuses by reason code instead of scoring a clean sheet'
);

$unreadable = FakeWpdb::install();
$unreadable->seedTable('wp_duo_journal', []);
$unreadable->failNextQuery('injected journal prerequisite failure', 'SHOW TABLES', 4);
duo_check_same('unreadable', Journal::table_state($unreadable), 'a failed probe is unreadable, never absent');
$unreadable->failNextQuery('injected journal prerequisite failure', 'SHOW TABLES', 4);
duo_check_refuses(
    static fn() => EffectDeclarationCoverage::report(['core']),
    'effect_coverage_journal_unreadable',
    'a failed journal read refuses as unreadable rather than as an absent table'
);

$GLOBALS['wpdb'] = null;
duo_check_same('unusable', Journal::table_state($GLOBALS['wpdb']), 'no usable $wpdb probes as unusable');
duo_check_refuses(
    static fn() => EffectDeclarationCoverage::report(['core']),
    'effect_coverage_journal_absent',
    'an unusable $wpdb folds into the prerequisite refusal, never into a zero report'
);

// The projection seam refuses a malformed aggregate rather than guessing.
duo_check_refuses(
    static fn() => EffectDeclarationCoverage::from_facts($policy, ['rows' => 'not-a-list']),
    'effect_coverage_invalid_facts',
    'a malformed journal aggregate refuses instead of being scored as no writes'
);
duo_check_refuses(
    static fn() => EffectDeclarationCoverage::from_facts($policy, ['rows' => [['table' => 'options', 'item' => 'x', 'n' => '4']]]),
    'effect_coverage_invalid_facts',
    'a non-integer observation count refuses instead of being coerced'
);

echo "\n== report-only by construction: the scorer can never block an apply ==\n";

duo_check_same(false, $baseline['authority'], 'the document declares it is not an authority');
duo_check_same(false, $baseline['blocking'], 'the document declares it blocks nothing');

// A journal that is NOTHING BUT findings still returns a document. This is the
// whole difference between a report and a gate.
$allFindings = [];
for ($i = 0; $i < 40; $i++) {
    $allFindings[] = edc_row('options', 'woocommerce_undeclared_probe_' . $i, 5);
}
$saturated = EffectDeclarationCoverage::from_facts($underDeclaredPolicy, ['rows' => $allFindings]);
duo_check_same(40, $saturated['totals']['outside_declaration'], 'forty findings are reported, not thrown');
// 240 scored judgements, not 40: five adapters (elementor, ninja-forms,
// polylang, yoast, yoast-duplicate-post) declare a `table:options` effect
// selector, which claims EVERY options row, so each of these 40 writes is
// judged six times and scores `declared` for five of them. That is a real
// property of a table-wide selector, and the rate reports it rather than
// hiding it.
duo_check_same(240, $saturated['baseline']['scored_surfaces'], 'a table-wide options selector makes each options write a judgement for every adapter that declared it');
duo_check_same(0.1667, $saturated['baseline']['outside_declaration_rate'], 'a journal of nothing but findings reports its rate and still returns');
duo_check_same(false, $saturated['blocking'], 'even a saturated report blocks nothing');

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
duo_check_same(
    [
        // the bootstrap require, the generated classmap, the surface verb, the class
        'agent/duo-classmap.php',
        'agent/duo.php',
        'agent/src/Command/Cli.php',
        'agent/src/Review/EffectDeclarationCoverage.php',
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
duo_check($scoringStart !== false && $scoringEnd !== false && $scoringEnd > $scoringStart, 'the scoring body was located in the class source');
duo_check_same(
    false,
    str_contains(substr($classSource, (int) $scoringStart, (int) $scoringEnd - (int) $scoringStart), 'throw'),
    'from_facts() contains no throw: a verdict is never a refusal'
);
duo_check_same(
    ['classified', 'declared', 'outside_declaration'],
    EffectDeclarationCoverage::WRITE_VERDICTS,
    'the observed-write verdict set is closed and published'
);
duo_check_same(
    ['exercised', 'unexercised', 'unobservable'],
    EffectDeclarationCoverage::EFFECT_VERDICTS,
    'the declared-effect verdict set is closed and published'
);

duo_check_summary('effect declaration coverage');
