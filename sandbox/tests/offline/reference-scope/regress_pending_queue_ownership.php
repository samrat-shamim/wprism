<?php
/**
 * DUO-3508: the review queue's mechanism-ownership filter, and the ref hint's
 * boolean-value caveat.
 *
 * `duo pending` has two halves and, before this, only one of them knew about
 * mechanism ownership. The journal half dropped an item on exactly one test —
 * `Journal::ground_truth() !== null` (Pending.php:494) — which for options is
 * `Policy::option_rule()` (Journal.php:331-334, Policy.php:634-636), the exact
 * `options{}` entries plus `option_patterns` and nothing else. Two families
 * are owned end to end by engine mechanisms that table structurally cannot
 * see, so a fresh install demanded a classification for every `widget_<type>`
 * row, `sidebars_widgets`, and both the active theme's `theme_mods_*` row and
 * each stale-residue one — names for which no class exists to give.
 *
 * The policy under test is built from the REAL manifests/core.json, not a
 * synthetic fixture library: the shipped declarations are exactly what make
 * `theme_mods_` an owned prefix and what deliberately leave the widget family
 * undeclared (manifests/core.json:86 records that declaring it in `options{}`
 * was tried and reverted, naming SidebarState's own guard as the sufficient
 * blocking net). A fixture manifest would prove the mechanism works and prove
 * nothing about the queue an operator actually sees.
 *
 * ONE faked collaborator, `Duo\Capture`: `gate_scan_read_only()` reaches
 * CaptureGateScanner's two JOIN queries (CaptureGateScanner.php:140-143,
 * ScopeDiscovery.php:177) which `DuoTest\FakeWpdb` deliberately refuses to
 * model (sandbox/tests/lib/FakeWpdb.php:96-100 — a JOIN belongs in live
 * certification, not an in-memory MySQL). It is the same seam
 * sandbox/tests/offline/adapter/regress_adapter_observation.php:245 uses, and
 * the real scanner's own output is pinned by
 * sandbox/tests/offline/capture/regress_capture_gate_scanner.php. Everything
 * else is the real engine file: Policy, Journal, Secrets, SidebarState,
 * Snapshot, Ledger, Tokens, Pending.
 *
 * Fails against the prior defect at the queue assertion (`widget_*`,
 * `sidebars_widgets` and both `theme_mods_*` rows appear in the queue) and at
 * the ref-hint assertion (`acme_public_flag`, whole value '1', is decorated
 * `-> post #1 'Hello world!'`).
 *
 * The final section is the WPForms Lite recon's measured ref-hint scoreboard
 * (2026-08-25): three hints emitted, all three false, none of the site's four
 * real cross-entity references found. Against the prior engine it fails four
 * ways at once — each false hint reappears, `wpforms_form_locations` has no
 * hint at all, and `wpforms_forms_first_created`'s unix timestamp is chased
 * all the way into `resolve_id()`'s term JOIN (which is where the prior engine
 * spends two SELECTs on an epoch, and where FakeWpdb's deliberate JOIN refusal
 * ends the run).
 */
declare(strict_types=1);

namespace Duo {
    /**
     * The one collaborator seam, per the file docblock. Behaves as the real
     * gate walk does for a site whose scope/meta surfaces are all classified,
     * and carries the widgets section verbatim in the shape
     * regress_capture_gate_scanner.php:191-197 pins the real scanner emitting
     * for an undeclared type with live instances.
     */
    final class Capture {
        /** @var array<string,mixed> */
        public static array $gate = [];
        public static int $calls = 0;

        /** @return array<string,mixed> */
        public static function gate_scan_read_only(
            string $repo,
            Policy $policy,
            ?callable $observationReadCheckpoint = null
        ): array {
            self::$calls++;
            return self::$gate;
        }
    }
}

namespace {
    // From offline/<domain>/: two hops to the corpus root, four to the repo root.
    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../lib/wp_stubs.php';
    require_once __DIR__ . '/../../lib/FakeWpdb.php';

    $repoRoot = dirname(__DIR__, 4);
    require_once $repoRoot . '/agent/src/Policy/Policy.php';
    require_once $repoRoot . '/agent/src/Grammar/Tokens.php';
    require_once $repoRoot . '/agent/src/Kernel/Secrets.php';
    require_once $repoRoot . '/agent/src/Repository/Ledger.php';
    require_once $repoRoot . '/agent/src/Repository/Snapshot.php';
    require_once $repoRoot . '/agent/src/Repository/SidebarState.php';
    require_once $repoRoot . '/agent/src/Repository/Journal.php';
    require_once $repoRoot . '/agent/src/Review/Pending.php';

    use Duo\Journal;
    use Duo\Pending;
    use Duo\Policy;
    use Duo\SidebarState;
    use Duo\Tokens;
    use DuoTest\FakeWpdb;
    use DuoTest\WpStore;

    // ---------------------------------------------------------------- fixture

    /** The shipped library, loaded as bytes — see the file docblock. */
    $core = json_decode((string) file_get_contents($repoRoot . '/platform/adapter-library/core/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $policy = new Policy();
    $policy->site = ['policy' => []];
    $policy->manifests = [$core];

    duo_check_same(
        'theme_mods_',
        $core['dynamic_options']['theme_mods']['prefix'] ?? null,
        'the shipped core.json still declares the theme_mods_ dynamic_options prefix this filter relies on'
    );
    duo_check_same(
        ['block', 'nav_menu', 'text'],
        array_keys($policy->widget_types()),
        'the shipped core.json still declares exactly three widget types, so widget_undeclared below is genuinely undeclared'
    );

    $store = WpStore::reset()->seedOptions(['home' => 'https://queue.example.test']);
    $wpdb = FakeWpdb::install();

    /** One duo_journal row, in the real column shape (Ledger.php:100-113). */
    $journalRow = static function (int $id, string $tbl, string $item, string $surface, string $caps, string $proposal): array {
        return [
            'id' => $id, 't' => '2026-08-21 09:00:00', 'op' => 'update', 'tbl' => $tbl,
            'item' => $item, 'surface' => $surface, 'actor' => 0, 'caps' => $caps,
            'hook' => '', 'proposal' => $proposal,
        ];
    };

    // What a fresh install's journal actually holds: WordPress's own widget and
    // theme bookkeeping writes, plus the two genuinely undeclared plugin
    // options and one boolean core flag that the queue SHOULD keep asking about.
    $wpdb->seedTable('wp_duo_journal', [
        $journalRow(1, 'options', 'widget_text', 'admin', 'manage_options', 'authored'),
        $journalRow(2, 'options', 'widget_text', 'rest', 'edit_theme_options', 'runtime'),
        $journalRow(3, 'options', 'widget_block', 'admin', 'manage_options', 'authored'),
        $journalRow(4, 'options', 'widget_undeclared', 'admin', 'manage_options', 'authored'),
        $journalRow(5, 'options', 'sidebars_widgets', 'admin', 'manage_options', 'authored'),
        $journalRow(6, 'options', 'theme_mods_twentytwentyfive', 'admin', 'manage_options', 'authored'),
        $journalRow(7, 'options', 'theme_mods_twentytwentyone', 'admin', 'manage_options', 'authored'),
        $journalRow(8, 'options', '_transient_acme_lock', 'cron', '', 'review'),
        $journalRow(9, 'options', 'acme_public_flag', 'admin', 'manage_options', 'authored'),
        $journalRow(10, 'options', 'acme_api_endpoint', 'admin', 'manage_options', 'authored'),
        $journalRow(11, 'options', 'acme_featured_post', 'admin', 'manage_options', 'authored'),
        // tbl != 'options': the ownership question is options-scoped, so a
        // post_meta key that happens to share a widget option's NAME is still
        // an ordinary review item.
        $journalRow(12, 'postmeta', 'widget_text', 'admin', 'edit_posts', 'authored'),
    ]);

    $widgetInstances = static fn(array $settings): string => serialize($settings + ['_multiwidget' => 1]);
    $wpdb->seedTable('wp_options', [
        ['option_id' => 1, 'option_name' => 'widget_text', 'option_value' => $widgetInstances([2 => ['title' => 'About']]), 'autoload' => 'yes'],
        ['option_id' => 2, 'option_name' => 'widget_block', 'option_value' => $widgetInstances([]), 'autoload' => 'yes'],
        ['option_id' => 3, 'option_name' => 'widget_undeclared', 'option_value' => $widgetInstances([5 => ['title' => 'Third party']]), 'autoload' => 'yes'],
        ['option_id' => 4, 'option_name' => 'sidebars_widgets', 'option_value' => serialize(['wp_inactive_widgets' => [], 'sidebar-1' => ['text-2'], 'array_version' => 3]), 'autoload' => 'yes'],
        ['option_id' => 5, 'option_name' => 'theme_mods_twentytwentyfive', 'option_value' => serialize(['custom_logo' => 42]), 'autoload' => 'yes'],
        ['option_id' => 6, 'option_name' => 'theme_mods_twentytwentyone', 'option_value' => serialize(['custom_logo' => 42]), 'autoload' => 'yes'],
        ['option_id' => 7, 'option_name' => '_transient_acme_lock', 'option_value' => '1', 'autoload' => 'no'],
        // The DUO-3508 ref-hint case: a boolean flag whose whole value is '1',
        // on a site that has WordPress's own seed post at id 1.
        ['option_id' => 8, 'option_name' => 'acme_public_flag', 'option_value' => '1', 'autoload' => 'yes'],
        ['option_id' => 9, 'option_name' => 'acme_api_endpoint', 'option_value' => 'https://api.acme.test/v2', 'autoload' => 'yes'],
        ['option_id' => 10, 'option_name' => 'acme_featured_post', 'option_value' => '42', 'autoload' => 'yes'],
    ]);
    // The journal's postmeta half joins onto gate findings rather than
    // standing alone (Pending.php:120-124), so the options-scoping assertion
    // below needs both halves present for the same key.
    $wpdb->seedTable('wp_postmeta', [
        ['meta_id' => 1, 'post_id' => 1, 'meta_key' => 'widget_text', 'meta_value' => 'a plugin wrote this'],
    ]);
    // WordPress's own fresh-install seed rows, ids and all — they are why the
    // pre-DUO-3508 hint was always confidently wrong: every low integer
    // anywhere in a bookkeeping value resolved to one of these.
    $wpdb->seedTable('wp_posts', [
        ['ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello world!'],
        ['ID' => 2, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Sample Page'],
        ['ID' => 3, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Privacy Policy'],
        ['ID' => 42, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Featured'],
    ]);

    \Duo\Capture::$gate = [
        'scope' => [], 'options' => [], 'widgets' => [],
        'post_meta' => ['widget_text' => ['entities' => 1, 'post_types' => ['post']]],
        'term_meta' => [], 'user_meta' => [],
    ];

    // ------------------------------------------------ the defect's root cause

    echo "\n== the rule table cannot answer for either mechanism ==\n";

    foreach (['widget_text', 'widget_undeclared', 'sidebars_widgets', 'theme_mods_twentytwentyfive', 'theme_mods_twentytwentyone'] as $owned) {
        duo_check_same(
            null,
            Journal::ground_truth($policy, 'options', $owned),
            "Journal::ground_truth() has no answer for the mechanism-owned name '$owned' (the exact/pattern table is blind to it)"
        );
    }
    duo_check_same(
        'derived',
        Journal::ground_truth($policy, 'options', '_transient_acme_lock'),
        'a name the rule table DOES classify is still excluded by ground_truth alone, unchanged'
    );

    echo "\n== mechanism_owner names the owner, and only for options ==\n";

    $mechanismOwner = new ReflectionMethod(Pending::class, 'mechanism_owner');
    $owner = static fn(string $tbl, string $item): ?string => $mechanismOwner->invoke(null, $policy, $tbl, $item);

    duo_check_same('widgets', $owner('options', 'sidebars_widgets'), 'SidebarState owns the top-level sidebars_widgets option');
    duo_check_same('widgets', $owner('options', 'widget_text'), 'SidebarState owns a declared widget type row');
    duo_check_same('widgets', $owner('options', 'widget_undeclared'), 'SidebarState owns an UNdeclared widget type row too — ownership is the family, not the declaration');
    duo_check_same('dynamic_options', $owner('options', 'theme_mods_twentytwentyfive'), "the active theme's theme_mods row is owned by the dynamic_options resolver");
    duo_check_same('dynamic_options', $owner('options', 'theme_mods_twentytwentyone'), 'a stale theme_mods residue row is owned by the same declared prefix');
    // DUO-3509 declares blog_public (authored) in manifests/core.json, so the boolean-valued UNDECLARED example the queue
    // assertions below use is acme_public_flag; blog_public stays here only to show mechanism_owner() is about mechanisms, not rules.
    duo_check_same(null, $owner('options', 'blog_public'), 'an unowned core option is left for the queue to ask about');
    duo_check_same(null, $owner('options', 'acme_api_endpoint'), 'an unowned plugin option is left for the queue to ask about');
    duo_check_same(null, $owner('postmeta', 'widget_text'), 'the ownership test is options-scoped: a post_meta key of the same name is untouched');
    duo_check_same(null, $owner('termmeta', 'sidebars_widgets'), 'the ownership test is options-scoped: a term_meta key of the same name is untouched');

    echo "\n== the queue an operator sees ==\n";

    $items = Pending::scan($repoRoot . '/sandbox/tmp', $policy);
    $keys = array_map(static fn(array $i): string => $i['section'] . ':' . $i['key'], $items);

    duo_check_same(
        ['options:acme_api_endpoint', 'options:acme_featured_post', 'options:acme_public_flag', 'post_meta:widget_text'],
        $keys,
        'only genuinely undeclared names are queued; every widget_*, sidebars_widgets and theme_mods_* observation is out'
    );
    duo_check_same(1, \Duo\Capture::$calls, 'the queue still takes exactly one gate walk');

    $byKey = [];
    foreach ($items as $item) {
        $byKey[$item['section'] . ':' . $item['key']] = $item;
    }

    duo_check_same(
        ['n' => 1, 'surfaces' => ['admin' => 1], 'caps' => ['manage_options' => 1], 'proposal' => 'authored'],
        $byKey['options:acme_api_endpoint']['evidence']['journal'] ?? null,
        'a surviving item keeps its full journal evidence bundle and proposal'
    );
    duo_check_same(
        ['n' => 1, 'surfaces' => ['admin' => 1], 'caps' => ['edit_posts' => 1], 'proposal' => 'authored'],
        $byKey['post_meta:widget_text']['evidence']['journal'] ?? null,
        "a post_meta key named 'widget_text' keeps its journal evidence: the filter never leaves the options table"
    );

    // ------------------------------------------------------- not silenced (a)

    echo "\n== an undeclared widget type with real instances still refuses ==\n";

    duo_check_throws(
        static fn() => SidebarState::capture($policy, new Tokens(), false),
        RuntimeException::class,
        "capture still refuses widget_undeclared's live instances even though the queue no longer asks about the row",
        "duo: widget option 'widget_undeclared' contains instances but type 'undeclared' is undeclared"
    );

    // The same seeded row that the queue is now quiet about is the one the
    // capture-time guard refuses on, so the loud path is provably the same
    // fixture, not a different one (SidebarState.php:262-269).
    duo_check(
        str_contains((string) $wpdb->last_query, "option_name LIKE 'widget\\_%'"),
        'that refusal came from the real widget_% enumeration over this fixture'
    );

    echo "\n== the gate half still surfaces an undeclared widget type ==\n";

    \Duo\Capture::$gate['widgets'] = ['undeclared' => [
        'entities' => 1,
        'value_shapes' => ['multi-instance array'],
        'reason' => 'live widget instances exist but no pinned manifest declares this widget type',
    ]];
    $withGateFinding = Pending::scan($repoRoot . '/sandbox/tmp', $policy);
    duo_check(
        in_array('widgets:undeclared', array_map(static fn(array $i): string => $i['section'] . ':' . $i['key'], $withGateFinding), true),
        'the journal filter touches only the journal half: a gate-walk widgets:<type> finding still reaches the queue'
    );
    \Duo\Capture::$gate['widgets'] = [];

    // ------------------------------------------------------- the ref hint (c)

    echo "\n== a whole value of exactly 0/1 is a flag, not a reference ==\n";

    duo_check_same(
        null,
        $byKey['options:acme_public_flag']['ref_hint'] ?? null,
        "acme_public_flag's whole value of '1' gets no hint, though post #1 ('Hello world!') exists and used to be offered as one"
    );
    duo_check_same(
        ['kind' => 'post', 'id' => 42, 'title' => 'Featured', 'post_type' => 'page', 'at' => ''],
        $byKey['options:acme_featured_post']['ref_hint'] ?? null,
        'a genuine id-shaped value still gets its hint — the linter is narrowed, not disabled; `at` is empty because the id IS the value'
    );
    $wpdb->seedTable('wp_terms', [
        ['term_id' => 42, 'name' => 'Portable Category', 'slug' => 'portable-category'],
    ]);
    $wpdb->seedTable('wp_term_taxonomy', [
        ['term_taxonomy_id' => 42, 'term_id' => 42, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1],
    ])->enableJoinedCaptureSql();
    $refHint = new ReflectionMethod(Pending::class, 'ref_hint');
    duo_check_same(
        ['kind' => 'term', 'id' => 42, 'title' => 'Portable Category', 'post_type' => 'category', 'at' => ''],
        $refHint->invoke(null, 'rank_math_primary_category', '42'),
        'a category-shaped key prefers the term namespace when the same integer is also a live post id'
    );
    duo_check_same(
        [[1, '']],
        Pending::numeric_candidates('1'),
        'numeric_candidates() is unchanged: Lint::scan_tree() shares it at eight call sites and a bare 1 stays a candidate there'
    );

    // ==================================================================
    // THE WPFORMS LITE RECON SCOREBOARD (measured 2026-08-25, site
    // running wpforms-lite 2.0.0.5 with its bundled Action Scheduler).
    //
    // The site held FOUR genuine cross-entity references:
    //   1. settings.confirmations.1.page = "4"   (in a wpforms post's BODY)
    //   2. wpforms_form_locations[0].id = 5      (postmeta on a wpforms post)
    //   3. block formId "6"                      (in a page's BODY)
    //   4. block formId "14"                     (in a page's BODY)
    // `duo pending` found ZERO of them and emitted THREE hints, all wrong:
    //   wpforms_settings                          -> post:1 "Hello world!"
    //   wpforms_constant_contact_version = '3'    -> post:3 "Privacy Policy"
    //   action_scheduler_hybrid_store_demarkation = '4'
    //                                             -> post:4 "Recon Thank You"
    // Every option value below is the byte-for-byte measured one
    // (sandbox/tmp/wpforms-recon/option-values.txt and
    // postmeta-and-as.side1.json), and every post id/title is the recon
    // site's own, so what these assertions pin is the real scoreboard
    // flipping rather than a fixture's idea of it.
    //
    // Against the prior engine this whole block fails: the three false hints
    // are all present, and post_meta:wpforms_form_locations has none.
    // ==================================================================

    echo "\n== the recon's three false hints are gone ==\n";

    // The recon site's own posts, ids and titles as measured.
    $wpdb->seedTable('wp_posts', [
        ['ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello world!'],
        ['ID' => 3, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Privacy Policy'],
        ['ID' => 4, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Recon Thank You'],
        ['ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Recon Contact Page'],
        ['ID' => 6, 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'Recon Contact Form'],
        ['ID' => 14, 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'Recon Path C Form'],
    ]);
    $wpdb->seedTable('wp_options', [
        // The plugin's ONE operator-authored option. Its first extractable id
        // is the `"1"` of `s:13:"modern-markup";s:1:"1"`, two levels down —
        // which is how it became a confident `post:1 "Hello world!"`.
        ['option_id' => 1, 'option_name' => 'wpforms_settings', 'autoload' => 'yes',
            'option_value' => 'a:3:{s:13:"modern-markup";s:1:"1";s:20:"modern-markup-is-set";b:1;s:26:"modern-markup-hide-setting";b:1;}'],
        // A provider SCHEMA version that happens to be a live page id.
        ['option_id' => 2, 'option_name' => 'wpforms_constant_contact_version', 'option_value' => '3', 'autoload' => 'yes'],
        // An action-id watermark that happens to be a live page id. No value
        // shape can tell '4' here from confirmations.1.page = "4" above; only
        // the KEY can, which is why the key vocabulary is the load-bearing rule.
        ['option_id' => 3, 'option_name' => 'action_scheduler_hybrid_store_demarkation', 'option_value' => '4', 'autoload' => 'yes'],
        // An epoch under a key whose `forms` token DOES claim a reference —
        // the measured case the timestamp veto exists for.
        ['option_id' => 4, 'option_name' => 'wpforms_forms_first_created', 'option_value' => '1787672947', 'autoload' => 'yes'],
        // A JSON map, version-keyed, holding one epoch. Reaches the JSON
        // decoder AND the version veto; measured verbatim.
        ['option_id' => 5, 'option_name' => 'wpforms_versions_lite', 'autoload' => 'yes',
            'option_value' => '{"1.5.9":0,"1.6.7.2":0,"1.7.5":0,"1.9.8.6":0,"2.0.0":0,"2.0.0.5":1787672785}'],
    ]);
    $wpdb->seedTable('wp_duo_journal', [
        $journalRow(1, 'options', 'wpforms_settings', 'admin', 'manage_options', 'authored'),
        $journalRow(2, 'options', 'wpforms_constant_contact_version', 'cron', '', 'review'),
        $journalRow(3, 'options', 'action_scheduler_hybrid_store_demarkation', 'cron', '', 'review'),
        $journalRow(4, 'options', 'wpforms_forms_first_created', 'admin', 'manage_options', 'review'),
        $journalRow(5, 'options', 'wpforms_versions_lite', 'cron', '', 'review'),
    ]);
    // The one real reference pending's surfaces CAN reach: postmeta on the
    // form post, byte-for-byte as measured. `type`/`title`/`status`/`url` sit
    // beside the two ids and must contribute nothing.
    $wpdb->seedTable('wp_postmeta', [
        ['meta_id' => 1, 'post_id' => 6, 'meta_key' => 'wpforms_form_locations',
            'meta_value' => 'a:1:{i:0;a:6:{s:4:"type";s:4:"page";s:5:"title";s:18:"Recon Contact Page";s:7:"form_id";i:6;s:2:"id";i:5;s:6:"status";s:7:"publish";s:3:"url";s:20:"/recon-contact-page/";}}'],
    ]);
    \Duo\Capture::$gate = [
        'scope' => [], 'options' => [], 'widgets' => [],
        'post_meta' => ['wpforms_form_locations' => ['entities' => 2, 'post_types' => ['wpforms']]],
        'term_meta' => [], 'user_meta' => [],
    ];

    $reconItems = Pending::scan($repoRoot . '/sandbox/tmp', $policy);
    $reconByKey = [];
    foreach ($reconItems as $item) {
        $reconByKey[$item['section'] . ':' . $item['key']] = $item;
    }

    foreach ([
        'options:wpforms_settings' =>
            'a boolean two levels inside a serialized settings array is still a boolean — DUO-3508\'s rule now holds at every depth, not just on a whole value',
        'options:wpforms_constant_contact_version' =>
            'a key naming a VERSION holds a version, even when the version is also a live page id',
        'options:action_scheduler_hybrid_store_demarkation' =>
            'a watermark under a key that claims no reference gets no hint, however cleanly the integer resolves',
        'options:wpforms_forms_first_created' =>
            'an epoch under an id-shaped key is vetoed by value: `forms` claims a reference, 1787672947 is not one',
        'options:wpforms_versions_lite' =>
            'a JSON version map is decoded and still yields nothing — the version veto runs before the walk',
    ] as $reconKey => $why) {
        duo_check(isset($reconByKey[$reconKey]), "$reconKey is in the queue at all (otherwise the hint assertion proves nothing)");
        duo_check_same(null, $reconByKey[$reconKey]['ref_hint'] ?? null, $why);
    }

    echo "\n== the reference inside a structure is now reachable ==\n";

    duo_check_same(
        ['kind' => 'post', 'id' => 6, 'title' => 'Recon Contact Form', 'post_type' => 'wpforms', 'at' => '[0].form_id'],
        $reconByKey['post_meta:wpforms_form_locations']['ref_hint'] ?? null,
        'wpforms_form_locations now yields a hint at the exact member that matched — the prior engine offered NOTHING here, '
        . 'because its extractor never descended into the nested array'
    );

    // Both ids the value really holds, in value order: the form\'s own id and
    // the id of the PAGE that embeds it (the recon\'s cross-entity reference).
    // First-that-resolves wins, so the published hint is form_id; an operator
    // following `at [0].form_id` sees `id` sitting next to it.
    $collect = new ReflectionMethod(Pending::class, 'collect_ref_candidates');
    $walk = static function ($value, ?string $refKey) use ($collect): array {
        $found = [];
        $args = [$value, '', $refKey, 0, &$found];
        $collect->invokeArgs(null, $args);
        return $found;
    };
    duo_check_same(
        [[6, '[0].form_id'], [5, '[0].id']],
        $walk(
            unserialize('a:1:{i:0;a:6:{s:4:"type";s:4:"page";s:5:"title";s:18:"Recon Contact Page";s:7:"form_id";i:6;s:2:"id";i:5;s:6:"status";s:7:"publish";s:3:"url";s:20:"/recon-contact-page/";}}', ['allowed_classes' => false]),
            'wpforms_form_locations'
        ),
        'the walk offers exactly the two id-shaped members under id-shaped keys, in value order, and nothing from type/title/status/url'
    );

    echo "\n== the three refs pending cannot see, and why ==\n";

    // Naming the misses rather than implying they were found. All three live
    // in `post_content`: `settings.confirmations.1.page` inside a wpforms
    // post's body, and two `wpforms/form-selector` `formId` block attributes
    // inside a page's body. `Pending::current_value()` reads exactly four
    // surfaces — options, post_meta, term_meta, user_meta — so no body-borne
    // reference has a queue row to carry a hint in the first place. `wp duo
    // lint` is the body scanner, and on the recon site it DID find both block
    // attrs (`unregistered_block_attr … attrs.formId value=6 matches=post:6`).
    foreach (['post_content', 'post_body', 'blocks'] as $bodySection) {
        duo_check_same(
            null,
            Pending::current_value($bodySection, 'anything'),
            "current_value('$bodySection') is null: the review queue has no body surface, which is why refs 1, 3 and 4 stay out of reach here"
        );
    }
    duo_check(
        !in_array('post_content', array_map(static fn(array $i): string => $i['section'], $reconItems), true),
        'and no queue row claims one either — the miss is structural, not a heuristic failure'
    );

    // The heuristic is nevertheless not what blocks them: walked directly,
    // the measured confirmations block yields page 4 at the member that holds
    // it, and leaves the `previous_page` sentinel alone (includes/class-process.php:1553-1562
    // branches on that literal before casting, so a rule that coerced this
    // slot to int would destroy it).
    // The locator reads `[1].page`, not `.1.page`: WPForms numbers its
    // confirmations `"1"`, `"3"`, and PHP turns a numeric string key into an
    // int on decode, so the walk sees a list position and says so.
    duo_check_same(
        [[4, '[1].page']],
        $walk(json_decode('{"1":{"type":"page","page":"4","page_url_parameters":"src=recon"},"3":{"type":"page","page":"previous_page"}}', true), null),
        'the same walk resolves confirmations.1.page = "4" and offers nothing for the non-numeric `previous_page` sentinel'
    );

    // ------------------------------------------------- the ownership claim (a)

    echo "\n== SidebarState's ownership claim cannot drift from its queries ==\n";

    duo_check_same('sidebars_widgets', SidebarState::SIDEBARS_OPTION, 'the owned top-level option name is a constant');
    foreach (['sidebars_widgets' => true, 'widget_text' => true, 'widget_' => true, 'widgets_text' => false, 'theme_mods_x' => false, 'sidebars_widgets_backup' => false] as $name => $expected) {
        duo_check_same($expected, SidebarState::owns_option((string) $name), "owns_option('$name') is " . var_export($expected, true));
    }

    // owns_option()'s docblock claims the two queries that MAKE the claim true
    // name the same constant. Pin both, so a future edit that re-inlines the
    // literal in one of them fails here instead of silently letting the
    // ownership claim and the enumeration drift apart.
    $sidebarSource = (string) file_get_contents($repoRoot . '/agent/src/Repository/SidebarState.php');
    duo_check(
        str_contains(
            $sidebarSource,
            "self::read_exact_option(self::SIDEBARS_OPTION, 'sidebars option')"
        ),
        'load_sidebars_option() reads the exact bounded row through the constant'
    );
    duo_check(
        str_contains($sidebarSource, 'self::lock_authored_option_row(')
            && str_contains($sidebarSource, "['option_name' => self::SIDEBARS_OPTION")
            && str_contains($sidebarSource, 'self::queue_authored_option(self::SIDEBARS_OPTION')
            && str_contains($sidebarSource, 'self::assert_authored_option_row('),
        'the apply lock/write/cache/readback path uses the same constant'
    );

    duo_check_summary('regress_pending_queue_ownership');
}
