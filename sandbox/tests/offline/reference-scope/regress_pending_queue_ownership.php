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
 * the ref-hint assertion (`blog_public`, whole value '1', is decorated
 * `-> post #1 'Hello world!'`).
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
    require_once $repoRoot . '/agent/src/Review/Journal.php';
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
    $core = json_decode((string) file_get_contents($repoRoot . '/manifests/core.json'), true, 512, JSON_THROW_ON_ERROR);
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
        $journalRow(9, 'options', 'blog_public', 'admin', 'manage_options', 'authored'),
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
        ['option_id' => 8, 'option_name' => 'blog_public', 'option_value' => '1', 'autoload' => 'yes'],
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
    duo_check_same(null, $owner('options', 'blog_public'), 'an unowned core option is left for the queue to ask about');
    duo_check_same(null, $owner('options', 'acme_api_endpoint'), 'an unowned plugin option is left for the queue to ask about');
    duo_check_same(null, $owner('postmeta', 'widget_text'), 'the ownership test is options-scoped: a post_meta key of the same name is untouched');
    duo_check_same(null, $owner('termmeta', 'sidebars_widgets'), 'the ownership test is options-scoped: a term_meta key of the same name is untouched');

    echo "\n== the queue an operator sees ==\n";

    $items = Pending::scan_read_only($repoRoot . '/sandbox/tmp', $policy);
    $keys = array_map(static fn(array $i): string => $i['section'] . ':' . $i['key'], $items);

    duo_check_same(
        ['options:acme_api_endpoint', 'options:acme_featured_post', 'options:blog_public', 'post_meta:widget_text'],
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
    $withGateFinding = Pending::scan_read_only($repoRoot . '/sandbox/tmp', $policy);
    duo_check(
        in_array('widgets:undeclared', array_map(static fn(array $i): string => $i['section'] . ':' . $i['key'], $withGateFinding), true),
        'the journal filter touches only the journal half: a gate-walk widgets:<type> finding still reaches the queue'
    );
    \Duo\Capture::$gate['widgets'] = [];

    // ------------------------------------------------------- the ref hint (c)

    echo "\n== a whole value of exactly 0/1 is a flag, not a reference ==\n";

    duo_check_same(
        null,
        $byKey['options:blog_public']['ref_hint'] ?? null,
        "blog_public's whole value of '1' gets no hint, though post #1 ('Hello world!') exists and used to be offered as one"
    );
    duo_check_same(
        ['kind' => 'post', 'id' => 42, 'title' => 'Featured', 'post_type' => 'page'],
        $byKey['options:acme_featured_post']['ref_hint'] ?? null,
        'a genuine id-shaped value still gets its hint — the linter is narrowed, not disabled'
    );
    duo_check_same(
        [[1, '']],
        Pending::numeric_candidates('1'),
        'numeric_candidates() is unchanged: Lint::scan_tree() shares it at eight call sites and a bare 1 stays a candidate there'
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
        preg_match(
            '/SELECT option_value FROM \{\$wpdb->options\} WHERE option_name = %s LIMIT 1",\s*self::SIDEBARS_OPTION/',
            $sidebarSource
        ) === 1,
        'load_sidebars_option() reads the row through the constant'
    );
    duo_check(
        str_contains($sidebarSource, 'VALUES (\'" . self::SIDEBARS_OPTION . "\', %s, \'yes\')'),
        'the apply write writes the row through the same constant'
    );

    duo_check_summary('regress_pending_queue_ownership');
}
