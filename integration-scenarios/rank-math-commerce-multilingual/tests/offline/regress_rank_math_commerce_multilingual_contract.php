<?php
/** Product-path contract regression for the Rank Math commerce/multilingual stack. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';

use WPrism\Canon;
use WPrism\Policy;

$scenarioPath = $root . '/integration-scenarios/rank-math-commerce-multilingual/scenario.json';
$scenarioBytes = (string) file_get_contents($scenarioPath);
$scenario = Canon::decode($scenarioBytes);
wprism_check_same($scenarioBytes, Canon::encode($scenario),
    'the Rank Math commerce/multilingual participant record is canonical');
wprism_check_same(
    ['acf', 'polylang', 'rank-math', 'woocommerce'],
    $scenario['participants'] ?? null,
    'the scenario declares the four exact adapter owners in canonical order'
);

$historicalPath = $root . '/integration-scenarios/rank-math-commerce-multilingual/fixtures/'
    . 'historical-site-outcome.json';
$historicalBytes = (string) file_get_contents($historicalPath);
$historical = Canon::decode($historicalBytes);
wprism_check_same($historicalBytes, Canon::encode($historical),
    'the pre-promotion four-plugin site exercise remains canonical and value-redacted');
wprism_check_same(
    ['advanced-custom-fields:6.8.7', 'polylang:3.8.6', 'seo-by-rank-math:1.0.277', 'woocommerce:11.0.1'],
    array_map(
        static fn(array $plugin): string => $plugin['name'] . ':' . $plugin['version'],
        $historical['combinations'][0]['plugins'] ?? []
    ),
    'the historical scenario pins every exact plugin artifact it exercised'
);
$historicalSignature = (string) ($historical['combinations'][0]['signature'] ?? '');
wprism_check(($historical['combinations'][0]['outcome'] ?? null) === 'green'
    && str_contains($historicalSignature, 'unchanged 43')
    && str_contains($historicalSignature, 'target-only scheduler action remained')
    && str_contains($historicalSignature, 'hreflang'),
    'the historical scenario retains convergence, runtime isolation and rendered multilingual SEO evidence');
wprism_check_same('refused', $historical['refusals'][0]['outcome'] ?? null,
    'the historical hostile ACF overlap is a refusal, never a compatibility claim');
wprism_check(str_contains(
    (string) ($historical['refusals'][0]['signature'] ?? ''),
    "post_meta 'rank_math_title' has multiple classification owners"
), 'the historical scenario retains the exact cross-owner refusal');

$site = sys_get_temp_dir() . '/wprism_rank_math_combo_' . bin2hex(random_bytes(8));
if (!mkdir($site, 0700, true) && !is_dir($site)) {
    throw new RuntimeException("could not create scratch site repository $site");
}
register_shutdown_function(static function () use ($site): void {
    @unlink($site . '/site.wprism.json');
    @rmdir($site);
});

$library = \WPrism\AdapterLibrary::fromSourceTree($root);
$orders = [
    ['core', 'acf', 'polylang', 'rank-math', 'woocommerce'],
    ['core', 'woocommerce', 'rank-math', 'polylang', 'acf'],
];
$ordinaryAcfField = [
    'type' => 'post',
    'path' => 'state/posts/acf-field/field_rmcombo_badge.json',
    'data' => ['type' => 'acf-field', 'slug' => 'field_rmcombo_badge'],
    'body' => serialize([
        'key' => 'field_rmcombo_badge',
        'name' => 'rmcombo_badge',
        'type' => 'text',
    ]),
];
$hostileAcfField = [
    'type' => 'post',
    'path' => 'state/posts/acf-field/field_rmcombo_rank_title.json',
    'data' => ['type' => 'acf-field', 'slug' => 'field_rmcombo_rank_title'],
    'body' => serialize([
        'key' => 'field_rmcombo_rank_title',
        'name' => 'rank_math_title',
        'type' => 'post_object',
    ]),
];

foreach ($orders as $pins) {
    Canon::write_file($site . '/site.wprism.json', Canon::encode([
        'manifests' => array_map(
            static fn(string $name): array => ['name' => $name, 'source' => 'shipped'],
            array_slice($pins, 1)
        ),
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));
    $policy = Policy::load($site, $pins, adapterLibrary: $library);
    $policy->prime_interpreters_from_repository([$ordinaryAcfField, $hostileAcfField]);

    foreach (['actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs'] as $table) {
        wprism_check_same('runtime', $policy->table_rule($table)['class'] ?? null,
            "$table remains one byte-identical runtime declaration under pin order " . implode(',', $pins));
    }
    wprism_check_same('rank-math', $policy->option_namespace('rank_math_modules')['owner'] ?? null,
        'Rank Math keeps its option namespace under either active-plugin order');
    wprism_check_same('woocommerce', $policy->option_namespace('woocommerce_shop_page_id')['owner'] ?? null,
        'WooCommerce keeps its option namespace under either active-plugin order');
    wprism_check_same('polylang', $policy->option_namespace('polylang')['owner'] ?? null,
        'Polylang keeps its option namespace under either active-plugin order');
    wprism_check_same('authored', $policy->meta_rule_for_post('rank_math_title', [])['class'] ?? null,
        'ordinary Rank Math product SEO remains statically authored without an ACF shadow');
    wprism_check_same('authored', $policy->meta_rule_for_post('rmcombo_badge', [
        '_rmcombo_badge' => 'field_rmcombo_badge',
        'rmcombo_badge' => 'Portable badge',
    ])['class'] ?? null,
        'a non-overlapping ACF product field composes with Rank Math metadata');

    wprism_check_throws(
        fn() => $policy->meta_rule_for_post('rank_math_title', [
            '_rank_math_title' => 'field_rmcombo_rank_title',
            'rank_math_title' => '17',
        ]),
        RuntimeException::class,
        'an ACF field cannot reinterpret Rank Math physical metadata under pin order ' . implode(',', $pins),
        "post_meta 'rank_math_title' has multiple classification owners"
    );
}

$rankMath = Canon::decode(Canon::read_file(
    $root . '/adapter-packages/rank-math/package/manifest.json'
));
$actions = $rankMath['actions'] ?? [];
wprism_check(!array_key_exists('triggers', $actions[2] ?? []),
    'Rank Math declares its native repair as site-complete rather than a partial product/CPT trigger list');
foreach (['post:product', 'option:polylang', 'table:wc_product_meta_lookup', 'term:product_cat'] as $surface) {
    $selected = array_values(array_filter(
        $policy->actions_for([$surface]),
        static fn(array $action): bool => ($action['provider'] ?? null) === 'rank-math-state'
    ));
    wprism_check_same(
        ['rebuild_all_link_state'],
        array_column($selected, 'capability'),
        "$surface selects Rank Math's one site-complete repair inside the combined policy"
    );
}
wprism_check_same(
    ['inspect_schema', 'prepare_schema', 'rebuild_all_link_state'],
    $rankMath['providers'][0]['capabilities'] ?? null,
    'the combined scenario consumes the same readiness/schema/site-repair provider contract as standalone Rank Math'
);

$livePath = $root . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/'
    . 'regress_rank_math_commerce_multilingual.sh';
$live = is_file($livePath) ? (string) file_get_contents($livePath) : '';
foreach ([
    'advanced-custom-fields 6.8.7',
    'polylang 3.8.6',
    'seo-by-rank-math 1.0.277.2',
    'woocommerce 11.0.1',
    "post_meta 'rank_math_title' has multiple classification owners",
    'ALTER TABLE wp_rank_math_internal_links ADD wprism_hostile_schema',
    "add_filter('rank_math/excluded_post_types'",
    'register_post_type(\'rmcombo_book\'',
    'source-only Action Scheduler state exists natively before capture',
    'exact reciprocal hreflang, canonical and Open Graph state renders on both products',
    'native 302 routing consumes the target-bound URL',
    'provider failure retained combined retry authority',
    'direct deletion remains refusal-only across the combined adapter boundary',
    'deletion_writer_exclusion_required',
    'combined direct deletion refusal changed native or target-runtime state',
    'signed promotion is exercised by the SSH scenario extension',
    'combined target recapture differs',
] as $witness) {
    wprism_check(str_contains($live, $witness), "the candidate-bound live scenario pins: $witness");
}

// Execute the actual native_state shell function far enough to observe the
// PHP program handed to `wp eval`. At 900b3e52, Bash consumed three SQL quote
// pairs before WordPress saw them; reading the file or pinning its text would
// preserve those bytes and miss the live defect.
$nativeStateStart = strpos($live, 'native_state() {');
$nativeStateEnd = $nativeStateStart === false ? false : strpos($live, "\nproduct_response() {", $nativeStateStart);
$nativeStateDefinition = $nativeStateStart === false || $nativeStateEnd === false
    ? ''
    : substr($live, $nativeStateStart, $nativeStateEnd - $nativeStateStart);
$nativeStateCapture = <<<'SH'
set -euo pipefail
wp1() {
  [ "$#" -eq 2 ] && [ "$1" = eval ] || return 81
  php -r 'printf("%s\n", base64_encode($argv[1]));' "$2"
}
SH;
$nativeStateProcess = proc_open(
    ['bash', '-c', $nativeStateCapture . "\n" . $nativeStateDefinition . "\nnative_state wp1\n", 'rmcombo-native-state'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $nativeStatePipes,
    $root,
    ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
);
$nativeStateStdout = '';
$nativeStateStderr = '';
$nativeStateStatus = 127;
if (is_resource($nativeStateProcess)) {
    $nativeStateStdout = (string) stream_get_contents($nativeStatePipes[1]);
    fclose($nativeStatePipes[1]);
    $nativeStateStderr = (string) stream_get_contents($nativeStatePipes[2]);
    fclose($nativeStatePipes[2]);
    $nativeStateStatus = proc_close($nativeStateProcess);
}
$submittedNativeState = base64_decode(trim($nativeStateStdout), true);
$submittedNativeState = is_string($submittedNativeState) ? $submittedNativeState : '';
wprism_check(
    $nativeStateDefinition !== ''
        && $nativeStateStatus === 0
        && $nativeStateStderr === ''
        && $submittedNativeState !== '',
    'the actual shell function submits one inspectable native-state program to wp eval'
);

$nativeReadersStart = strpos($submittedNativeState, '$readRows =');
$nativeReadersEnd = $nativeReadersStart === false ? false : strpos($submittedNativeState, '$en =', $nativeReadersStart);
$nativeQueriesStart = $nativeReadersEnd === false ? false : strpos($submittedNativeState, '$scheduler =', $nativeReadersEnd);
$nativeQueriesEnd = $nativeQueriesStart === false ? false : strpos($submittedNativeState, '$neighborState =', $nativeQueriesStart);
$nativeSqlProgram = $nativeReadersStart === false || $nativeReadersEnd === false
    || $nativeQueriesStart === false || $nativeQueriesEnd === false
    ? ''
    : substr($submittedNativeState, $nativeReadersStart, $nativeReadersEnd - $nativeReadersStart)
        . substr($submittedNativeState, $nativeQueriesStart, $nativeQueriesEnd - $nativeQueriesStart);
wprism_check($nativeSqlProgram !== '',
    'the submitted native-state program retains its checked readers and literal-bearing query block');

$runNativeSqlProgram = static function (string $failure) use ($nativeSqlProgram): array {
    $wpdb = new class($failure) {
        public string $prefix = 'wp_';
        public string $last_error = '';

        /** @var list<string> */
        public array $queries = [];

        public function __construct(private readonly string $failure) {}

        public function prepare(string $sql, mixed ...$values): string {
            $index = 0;
            $prepared = preg_replace_callback(
                '/%([ds])/',
                static function (array $match) use (&$index, $values): string {
                    if (!array_key_exists($index, $values)) {
                        throw new RuntimeException('native SQL oracle received too few bindings');
                    }
                    $value = $values[$index++];
                    return $match[1] === 'd'
                        ? (string) (int) $value
                        : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
                },
                $sql
            );
            if (!is_string($prepared) || $index !== count($values)) {
                throw new RuntimeException('native SQL oracle received an invalid binding count');
            }
            return $prepared;
        }

        /** @return list<array<string,int|string>> */
        public function get_results(string $sql, string $output): array {
            if (str_contains($sql, 'actionscheduler_actions')) {
                if (!$this->accept(
                    $sql,
                    'scheduler',
                    "WHERE a.hook IN ('rmcombo_source_runtime','rmcombo_target_runtime')"
                )) {
                    return [];
                }
                return [['action_id' => '71', 'hook' => 'rmcombo_source_runtime', 'status' => 'pending', 'group_slug' => '']];
            }
            if (str_contains($sql, 'rank_math_redirections_cache')) {
                if (!$this->accept($sql, 'cache', "WHERE from_url='rmcombo-old'")) {
                    return [];
                }
                return [[
                    'from_url' => 'rmcombo-old',
                    'redirection_id' => '19',
                    'object_id' => '31',
                    'object_type' => 'post',
                    'is_redirected' => '1',
                ]];
            }
            throw new RuntimeException('native SQL oracle received an unexpected row-set query');
        }

        /** @return array<string,int|string>|null */
        public function get_row(string $sql, string $output): ?array {
            throw new RuntimeException('native SQL oracle unexpectedly queried a row');
        }

        public function get_var(string $sql): int {
            if (!$this->accept($sql, 'stale', "WHERE url IN ('/target-stale','/target-stale-book')")) {
                return 0;
            }
            return 3;
        }

        private function accept(string $sql, string $kind, string $literal): bool {
            $this->last_error = '';
            $this->queries[] = $sql;
            if ($this->failure === $kind || !str_contains($sql, $literal)) {
                $this->last_error = 'fixture database error';
                return false;
            }
            return true;
        }
    };
    $neighbor = null;
    $scheduler = null;
    $redirectionCache = null;
    $staleLinkSentinels = null;
    eval($nativeSqlProgram);
    return [
        'queries' => $wpdb->queries,
        'redirection_cache' => $redirectionCache,
        'scheduler' => $scheduler,
        'stale_link_sentinels' => $staleLinkSentinels,
    ];
};

$nativeSqlObservation = $runNativeSqlProgram('');
wprism_check(
    ($nativeSqlObservation['scheduler'][0]['hook'] ?? null) === 'rmcombo_source_runtime'
        && ($nativeSqlObservation['redirection_cache'][0]['from_url'] ?? null) === 'rmcombo-old'
        && ($nativeSqlObservation['stale_link_sentinels'] ?? null) === 3
        && count($nativeSqlObservation['queries'] ?? []) === 3,
    'the actual submitted SQL binds both scheduler hooks, the redirection slug and both stale URLs'
);
foreach ([
    'scheduler' => 'row-set',
    'cache' => 'row-set',
    'stale' => 'scalar',
] as $failure => $reader) {
    $exception = null;
    try {
        $runNativeSqlProgram($failure);
    } catch (Throwable $caught) {
        $exception = $caught;
    }
    wprism_check(
        $exception instanceof RuntimeException
            && $exception->getMessage() === "combined native database $reader observation failed",
        "$failure query errors refuse instead of becoming an empty or zero native-state witness"
    );
}

$configureDefinition = strpos($live, 'configure_rank_math() {');
$nativeModuleDisable = strpos($live, 'RankMath\\Helper::update_modules(array_fill_keys($stored, "off"));');
$nativeModuleEnable = strpos($live, 'RankMath\\Helper::update_modules(array_fill_keys($desired, "on"));');
$readinessDefinition = strpos($live, 'rank_math_readiness() {');
$activeModuleReadback = strpos($live, '$activeModules = array_values(RankMath\\Helper::get_active_modules());');
$sourceConfigure = strpos($live, "\nconfigure_rank_math wp1 source\n");
$targetConfigure = strpos($live, "\nconfigure_rank_math wp2 target\n");
$sourceReadiness = strpos($live, 'SOURCE_RANK_MATH_READY=$(rank_math_readiness wp1 source)');
$targetReadiness = strpos($live, 'TARGET_RANK_MATH_READY=$(rank_math_readiness wp2 target)');
$sourceReadinessObserved = strpos(
    $live,
    "require_observed_nonempty 'source Rank Math native module readiness' \"\$SOURCE_RANK_MATH_READY\""
);
$targetReadinessObserved = strpos(
    $live,
    "require_observed_nonempty 'target Rank Math native module readiness' \"\$TARGET_RANK_MATH_READY\""
);
$readinessOracle = strpos($live, <<<'SH'
jq -en --argjson source "$SOURCE_RANK_MATH_READY" --argjson target "$TARGET_RANK_MATH_READY" '
  $source == {
    active_modules:["link-counter","redirections","rich-snippet"],
    modules:["link-counter","redirections","rich-snippet"],role:"source",
    tables:{rank_math_internal_links:true,rank_math_internal_meta:true,
      rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  } and
  $target == {
    active_modules:["redirections","rich-snippet"],
    modules:["redirections","rich-snippet"],role:"target",
    tables:{rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  }
' >/dev/null || fail "Rank Math native module readiness is incomplete: $SOURCE_RANK_MATH_READY / $TARGET_RANK_MATH_READY"
SH);
$nativeAuthoring = strpos($live,
    "say 'author native multilingual products, ACF values, Rank Math SEO/link state and Woo lookup state'");
wprism_check(
    $configureDefinition !== false
        && $nativeModuleDisable !== false
        && $nativeModuleEnable !== false
        && $readinessDefinition !== false
        && $activeModuleReadback !== false
        && $sourceConfigure !== false
        && $targetConfigure !== false
        && str_contains($live, '["link-counter", "redirections", "rich-snippet"]')
        && str_contains($live, '["redirections", "rich-snippet"]')
        && !str_contains($live, 'update_option("rank_math_modules"')
        && str_contains($live,
            '["rank_math_internal_links", "rank_math_internal_meta", "rank_math_redirections", "rank_math_redirections_cache"]')
        && str_contains($live, '["rank_math_redirections", "rank_math_redirections_cache"]')
        && $sourceReadiness !== false
        && $targetReadiness !== false
        && $sourceReadinessObserved !== false
        && $targetReadinessObserved !== false
        && $readinessOracle !== false
        && $nativeAuthoring !== false
        && $configureDefinition < $nativeModuleDisable
        && $nativeModuleDisable < $nativeModuleEnable
        && $nativeModuleEnable < $readinessDefinition
        && $readinessDefinition < $activeModuleReadback
        && $activeModuleReadback < $sourceConfigure
        && $sourceConfigure < $targetConfigure
        && $targetConfigure < $sourceReadiness
        && $sourceReadiness < $targetReadiness
        && $targetReadiness < $sourceReadinessObserved
        && $sourceReadinessObserved < $targetReadinessObserved
        && $targetReadinessObserved < $readinessOracle
        && $readinessOracle < $nativeAuthoring,
    'runtime module setup and fresh registered-active readbacks prove every exact Rank Math role and table before authoring'
);

preg_match_all('/^run_leg (forward reverse|reverse forward)$/m', $live, $legs);
wprism_check_same(
    ['forward reverse', 'reverse forward'],
    $legs[1] ?? [],
    'both pairwise-opposed source/target plugin-load orders run the complete parameterized product path'
);
$sourceOrderReadback = strpos($live, 'SOURCE_ORDER=$(active_plugin_order wp1)');
$targetOrderReadback = strpos($live, 'TARGET_ORDER=$(active_plugin_order wp2)');
$jointOrderGuard = strpos($live, '  $source == $expected_source and $target == $expected_target');
wprism_check(
    $sourceOrderReadback !== false
        && $targetOrderReadback !== false
        && $jointOrderGuard !== false
        && $sourceOrderReadback < $targetOrderReadback
        && $targetOrderReadback < $jointOrderGuard,
    'the live scenario reads back both actual plugin boot orders before comparing both exact expectations'
);
foreach ([
    'persist_active_plugin_order 1 "$source_order"',
    'persist_active_plugin_order 2 "$target_order"',
] as $setter) {
    $position = strpos($live, $setter);
    wprism_check($position !== false && $position < $sourceOrderReadback,
        'each explicit plugin-order premise is persisted before either order is observed');
}
wprism_check(
    str_contains($live,
        '["polylang/polylang.php","advanced-custom-fields/acf.php","seo-by-rank-math/rank-math.php","woocommerce/woocommerce.php"]')
        && str_contains($live,
            '["polylang/polylang.php","woocommerce/woocommerce.php","seo-by-rank-math/rank-math.php","advanced-custom-fields/acf.php"]'),
    'both pairwise-opposed orders retain Polylang native precedence and reverse every other participant'
);
$hostDeploy = strpos($live, 'CLEAN_DEPLOY=$(host_wprism_combo wp2 deploy');
$settledOrderReadback = strpos($live, 'HOST_SETTLED_ORDER=$(active_plugin_order wp2)');
$initialApply = strpos($live, 'capture_wprism_json_success INITIAL');
wprism_check(
    $hostDeploy !== false
        && $settledOrderReadback !== false
        && $initialApply !== false
        && $hostDeploy < $settledOrderReadback
        && $settledOrderReadback < $initialApply
        && str_contains($live,
            'jq -en --argjson actual "$HOST_SETTLED_ORDER" --argjson expected "$expected_source"')
        && str_contains($live, '$actual == $expected'),
    'host lifecycle order is independently read back and matched to canonical source before product apply'
);
$sourceBinding = strpos($live, <<<'SH'
establish_core_environment_bindings wp1 /siterepo admin@example.test \
  "http://${PAIR}1.invalid" "http://${PAIR}1.invalid"
SH);
$targetBinding = strpos($live, <<<'SH'
establish_core_environment_bindings wp2 /siterepo admin@example.test \
  "http://${PAIR}2.invalid" "http://${PAIR}2.invalid"
SH);
$firstCapture = strpos($live, 'wp1 wprism capture --repo=/siterepo >/dev/null');
$targetClone = strpos($live, 'git clone -q "$ORIGIN" "$R2"');
$firstDeploy = strpos($live, 'DIRTY_DEPLOY=$(host_wprism_combo wp2 deploy');
wprism_check(
    $sourceBinding !== false
        && $targetBinding !== false
        && $firstCapture !== false
        && $targetClone !== false
        && $firstDeploy !== false
        && $nativeAuthoring !== false
        && $nativeAuthoring < $sourceBinding
        && $sourceBinding < $firstCapture
        && $firstCapture < $targetClone
        && $targetClone < $targetBinding
        && $targetBinding < $firstDeploy
        && substr_count($live, 'establish_core_environment_bindings ') === 2,
    'each complete plugin-order leg provisions the exact headless core intent after seed/clone and before capture/deploy'
);
foreach ([
    ['INITIAL', 'Rank Math commerce/multilingual initial apply', 'TARGET=$(native_state wp2)'],
    ['RETRY', 'Rank Math combination provider retry', 'RETRY_NATIVE=$(native_state wp2)'],
    ['NOOP', 'Rank Math combination no-op apply', 'wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-final'],
] as [$answer, $label, $observation]) {
    $captureStart = strpos($live, "capture_wprism_json_success $answer '$label' ");
    $ready = strpos($live, "assert_wprism_apply_ready '$label' \"\$$answer\"");
    $observe = strpos($live, $observation);
    wprism_check(
        $captureStart !== false
            && $ready !== false
            && $observe !== false
            && $captureStart < $ready
            && $ready < $observe
            && !str_contains($live, "$answer=\$(wp2 wprism apply"),
        "$answer preserves the public refusal/diagnostic stream and requires provisioned, verified apply before its native oracle"
    );
    $block = $captureStart === false || $ready === false ? '' : substr(
        $live,
        $captureStart,
        $ready + strlen("assert_wprism_apply_ready '$label' \"\$$answer\"") - $captureStart
    );
    foreach (['ready', 'missing', 'refusal', 'diagnostic', 'startup', 'parse'] as $case) {
        $script = <<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1"
REVISION=fixture-revision
wp2() {
  [ "$1" = wprism ] && [ "$2" = apply ] || return 64
  case " $* " in *' --repo=/siterepo '*) ;; *) return 65 ;; esac
  case " $* " in *' --format=json '*) ;; *) return 66 ;; esac
  if [ "$PROBE_CASE" = refusal ]; then
    printf '%s\n' '{"format":"wprism-command-refusal/v1","reason_code":"fixture_refusal"}'
    return 7
  fi
  if [ "$PROBE_CASE" = diagnostic ]; then
    printf 'PHP Warning: fixture diagnostic in /fixture.php on line 1\n' >&2
  fi
  if [ "$PROBE_CASE" = startup ]; then
    printf 'PHP Warning: PHP Startup: fixture diagnostic in Unknown on line 0\n' >&2
  fi
  if [ "$PROBE_CASE" = parse ]; then
    printf 'PHP Parse error: fixture diagnostic\n' >&2
  fi
  if [ "$PROBE_CASE" = missing ]; then
    printf '%s\n' '{"canary":"clean","verification":{"result":"pass"},"warnings":["env_missing: option home is required"]}'
  else
    printf '%s\n' '{"canary":"clean","verification":{"result":"pass"},"warnings":[]}'
  fi
}
PROBE_CASE="$2"
SH;
        $process = proc_open(
            ['bash', '-c', $script . "\n" . $block . "\nprintf 'APPLY_READY\\n'\n", 'combo-apply-probe',
                $root . '/sandbox/conformance/asserts.sh', $case],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
        );
        $stdout = '';
        $stderr = '';
        $status = 127;
        if (is_resource($process)) {
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $status = proc_close($process);
        }
        $accepted = $status === 0 && $stdout === "APPLY_READY\n";
        wprism_check(
            match ($case) {
                'ready' => $accepted && $stderr === '',
                'missing' => $status !== 0 && !str_contains($stdout, 'APPLY_READY')
                    && str_contains($stderr, 'did not prove all required environment bindings'),
                'refusal' => $status !== 0 && !str_contains($stdout, 'APPLY_READY')
                    && str_contains($stderr, 'fixture_refusal') && str_contains($stderr, 'failed with exit 7'),
                'diagnostic', 'startup', 'parse' => $status !== 0 && !str_contains($stdout, 'APPLY_READY')
                    && str_contains($stderr, 'fixture diagnostic')
                    && str_contains($stderr, 'emitted a PHP runtime diagnostic'),
            },
            "$answer actual live command block preserves the $case evidence domain"
        );
    }
}
wprism_check(
    str_contains($live, '([ $target.products.en.links[].type ] | sort) == ["external","internal"]')
        && str_contains($live, '.retired_target_counts.incoming_link_count | tonumber) == 0')
        && str_contains($live, '$target.stale_link_sentinels == 0')
        && !str_contains($live, '.products.en.links >=')
        && !str_contains($live, "grep -Fq 'hreflang='"),
    'the live oracle asserts exact link rows, retired-target counts and head semantics rather than lower bounds or token greps'
);
wprism_check(
    str_contains($live, '($failed | .products.en.content = $baseline.products.en.content) == $baseline')
        && str_contains($live, '| .products.en.rank_counts = $baseline.products.en.rank_counts) == $baseline')
        && str_contains($live, '$final == $retried')
        && str_contains($live, 'provider:rank-math-state/rebuild_all_link_state'),
    'failure, retry and no-op phases retain exact target-only witnesses and bind the selected Rank Math action source'
);
$directDeletion = strpos($live, "say 'direct deletion remains refusal-only across the combined adapter boundary'");
$withheldRefusal = strpos($live, 'DELETE_WITHHELD_RC=0');
$exclusionRefusal = strpos($live, '.reason_code == "deletion_writer_exclusion_required"');
$refusalObservation = strpos($live, 'DELETE_REFUSAL_NATIVE=$(native_state wp2)');
$refusalEquality = strpos(
    $live,
    '--argjson before "$TARGET_FINAL" --argjson after "$DELETE_REFUSAL_NATIVE" \'$after == $before\''
);
wprism_check(
    $directDeletion !== false
        && $withheldRefusal !== false
        && $exclusionRefusal !== false
        && $refusalObservation !== false
        && $refusalEquality !== false
        && $directDeletion < $withheldRefusal
        && $withheldRefusal < $exclusionRefusal
        && $exclusionRefusal < $refusalObservation
        && $refusalObservation < $refusalEquality
        && !str_contains($live, 'promote target --with-deletes')
        && !str_contains($live, 'promote complete: verified committed receipt; traffic exclusion released'),
    'the Docker scenario proves both direct deletion refusals and exact native-state preservation, never a fabricated success'
);

$sshDeletionPath = $root . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/'
    . 'regress_rank_math_commerce_multilingual_ssh_deletion.sh';
$sshDeletion = is_file($sshDeletionPath) ? (string) file_get_contents($sshDeletionPath) : '';
wprism_check($sshDeletion !== '',
    'the participant-owned scenario includes a tracked SSH-adoption deletion extension');
$directProbe = proc_open(
    ['bash', $sshDeletionPath],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $directPipes,
    $root,
    ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
);
$directStdout = '';
$directStderr = '';
$directStatus = 127;
if (is_resource($directProbe)) {
    $directStdout = (string) stream_get_contents($directPipes[1]);
    fclose($directPipes[1]);
    $directStderr = (string) stream_get_contents($directPipes[2]);
    fclose($directPipes[2]);
    $directStatus = proc_close($directProbe);
}
wprism_check(
    $directStatus !== 0
        && $directStdout === ''
        && str_contains($directStderr, 'ADOPT_FIXTURE'),
    'the discovered direct live-gate argv refuses missing allocation instead of returning a silent false green'
);
wprism_check(
    !str_contains($sshDeletion, 'declare(strict_types=1);')
        && str_contains($sshDeletion, 'if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then')
        && str_contains($sshDeletion, 'export WPRISM_SSH_ADOPT_EXTENSION=')
        && str_contains($sshDeletion, 'exec bash "$ROOT/sandbox/tests/live/regress_ssh_adopt.sh"'),
    'the extension remains WP-CLI eval-safe when sourced and delegates direct execution to the shared SSH product gate'
);
$sshExtensionHelper = (string) file_get_contents($root . '/sandbox/tests/lib/ssh_adopt_extension.sh');
wprism_check(
    str_contains($sshDeletion, 'wprism_ssh_enroll_full_recovery rm-combo')
        && str_contains($sshDeletion, 'wprism_ssh_install_certified_plugin "$adapter" "$expected_version"')
        && str_contains($sshDeletion, 'wprism_ssh_stage_code_inventory')
        && str_contains($sshDeletion, 'advanced-custom-fields polylang seo-by-rank-math woocommerce')
        && str_contains($sshDeletion, 'wprism_ssh_stage_generation_releases 1')
        && str_contains($sshDeletion, 'wprism_ssh_publish_post_tombstone post rmcombo-ssh-delete')
        && !str_contains($sshDeletion, 'wp plugin install')
        && str_contains($sshExtensionHelper, 'artifact_library_jq -ce')
        && str_contains($sshExtensionHelper, '.role == "certified-boundary"')
        && str_contains($sshExtensionHelper, 'hash_file("sha256", $argv[1])')
        && str_contains($sshExtensionHelper, 'Deletion::capture_tombstones($compiled, [], $policy, [$uuid])')
        && str_contains($sshExtensionHelper,
            '$ROOT/sandbox/tests/fixtures/plan-bound-code-release-provider.php')
        && !str_contains($sshExtensionHelper, 'adapter-packages/woocommerce/fixtures/'),
    'the scenario composes certified artifacts, code/release/tombstone staging, and recovery through shared machinery'
);
$sshArtifactPins = [
    'acf' => ['advanced-custom-fields', '6.8.7', 'f877a94871e55cc2f2931052c693705d376da12cb85c9761b6915c037f91cec2'],
    'polylang' => ['polylang', '3.8.6', 'dd2a213d407c6d565eb5e246e68b434003f1112c059ee53ca070bf97102010aa'],
    'rank-math' => ['seo-by-rank-math', '1.0.277.2', '1c6cae3fda401798dfdc5d1d5814de17c040ffcb457c40e2f0256db84a680b1b'],
    'woocommerce' => ['woocommerce', '11.0.1', 'da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21'],
];
foreach ($sshArtifactPins as $participant => [$slug, $version, $sha256]) {
    $lock = Canon::decode(Canon::read_file(
        "$root/adapter-packages/$participant/evidence/artifacts.lock.json"
    ));
    wprism_check_same(
        ['url' => "https://downloads.wordpress.org/plugin/$slug.$version.zip", 'sha256' => $sha256, 'role' => 'certified-boundary'],
        $lock['plugins'][$slug][$version] ?? null,
        "the SSH combination's $slug $version bytes are fixed by the participant-owned certified artifact lock"
    );
}
foreach ([
    'advanced-custom-fields|6.8.7',
    'polylang|3.8.6',
    'seo-by-rank-math|1.0.277.2',
    'woocommerce|11.0.1',
    'wprism_ssh_enroll_full_recovery rm-combo',
    'WC_Product_Simple',
    "pll_set_post_language(\$postId, 'en')",
    "update_field('field_rmcombo_ssh_note', 'delete this ACF value', \$postId)",
    'RankMath\\Links\\Links::process_post_links($postId, get_post($postId))',
    '.source == "provider:rank-math-state/rebuild_all_link_state"',
    '.source == "provider:woocommerce-product-lookups/cleanup_product_deletions"',
    'promote target --with-deletes',
    'promote complete: verified committed receipt; traffic exclusion released',
    '.receipt.allow_deletes == true',
    'signed promotion deletes ACF/Polylang/Rank Math state, preserves the live Woo product/lookup, and converges',
] as $witness) {
    wprism_check(str_contains($sshDeletion, $witness),
        "the SSH deletion extension pins: $witness");
}
$extensionDefinition = strpos($sshDeletion, 'wprism_ssh_adopt_extension() {');
$recoveryEnrollment = strpos($sshDeletion, 'wprism_ssh_enroll_full_recovery rm-combo');
$codeBinding = strpos($sshDeletion, 'bind the exact combined plugin/theme code and adapter pins');
$baselineCapture = strpos($sshDeletion, 'capture target --target-branch="$TARGET_REPOSITORY_BRANCH"');
$tombstonePublication = strpos($sshDeletion,
    'wprism_ssh_publish_post_tombstone post rmcombo-ssh-delete');
$fullPlan = strpos($sshDeletion, 'plan target --format=json');
$signedPromotion = strpos($sshDeletion, 'promote target --with-deletes');
$postimageObservation = strpos($sshDeletion, 'after_json="$(ssh_fixture');
$fixedPointPlan = strrpos($sshDeletion, 'plan target --format=json');
wprism_check(
    $extensionDefinition !== false
        && $recoveryEnrollment !== false
        && $codeBinding !== false
        && $baselineCapture !== false
        && $tombstonePublication !== false
        && $fullPlan !== false
        && $signedPromotion !== false
        && $postimageObservation !== false
        && $fixedPointPlan !== false
        && $extensionDefinition < $recoveryEnrollment
        && $recoveryEnrollment < $codeBinding
        && $codeBinding < $baselineCapture
        && $baselineCapture < $tombstonePublication
        && $tombstonePublication < $fullPlan
        && $fullPlan < $signedPromotion
        && $signedPromotion < $postimageObservation
        && $postimageObservation < $fixedPointPlan,
    'the SSH extension binds recovery/code before capture and proves cleanup plus convergence only after signed promotion'
);
wprism_check(
    str_contains($sshDeletion, 'declared core post:post boundary')
        && str_contains($sshDeletion, 'wprism_ssh_publish_post_tombstone post rmcombo-ssh-delete')
        && str_contains($sshDeletion, '.deletion_type == "post"')
        && str_contains($sshDeletion,
            '([.effects_inventory[]? | select(.source == "provider:woocommerce-product-lookups/cleanup_product_deletions")] | length) == 0')
        && !str_contains($sshDeletion, 'deletion_owner_agreements')
        && !str_contains($sshDeletion, 'type:"product",uuid:$uuid'),
    'the SSH extension stays on core post deletion and does not manufacture unaudited Woo product authority'
);
wprism_check(
    str_contains($sshDeletion, '.deleted.acf_meta == 2')
        && str_contains($sshDeletion, '.deleted.language == "en"')
        && str_contains($sshDeletion, '.deleted.rank_target_links == 1')
        && str_contains($sshDeletion, 'rank_links:0')
        && str_contains($sshDeletion, 'rank_meta:0')
        && str_contains($sshDeletion, 'relationships:0'),
    'the successful SSH path starts from non-vacuous ACF/Polylang/Rank Math state and proves every owned row is gone'
);
wprism_check(
    str_contains($sshDeletion, '.stock_notifications == true')
        && str_contains($sshDeletion, 'lookup:1')
        && str_contains($sshDeletion, 'rank_incoming:1')
        && str_contains($sshDeletion, '($after.product | del(.rank_incoming)) == ($before.product | del(.rank_incoming))')
        && str_contains($sshDeletion, '$after.product.rank_incoming == 0')
        && str_contains($sshDeletion, '$after.product.post == 1')
        && str_contains($sshDeletion, '$after.product.lookup == 1')
        && str_contains($sshDeletion, '.selected_actions == []'),
    'WooCommerce is live and populated, remains outside the core deletion, and the signed path reaches a no-action fixed point'
);

// Run the exact SSH scenario command/acceptance blocks. Required rows must
// fail independently of their warning strings, while optional rows and native
// action receipts stay admissible. Private host diagnostics are never echoed.
$sshAdoptSource = (string) file_get_contents($root . '/sandbox/tests/live/regress_ssh_adopt.sh');
preg_match('/^assert_ssh_fixture_positive_diagnostics\(\).*?^\}/ms', $sshAdoptSource, $sshDiagnosticDefinition);
$sshCaptureStart = strpos($sshDeletion, '  local success_stdout=');
$sshCaptureEnd = $sshCaptureStart === false ? false : strpos($sshDeletion, '  say "enroll full recovery', $sshCaptureStart);
$sshCaptureBlock = $sshCaptureStart === false || $sshCaptureEnd === false ? ''
    : substr($sshDeletion, $sshCaptureStart, $sshCaptureEnd - $sshCaptureStart);
wprism_check($sshCaptureBlock !== '', 'the SSH scenario has an executable private capture allocation block');
$sshProbe = static function (string $block, string $answer, string $diagnostic = '', int $exit = 0) use ($root, $sshDiagnosticDefinition, $sshCaptureBlock): array {
    $script = <<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
umask 000
TMP=$(mktemp -d "${TMPDIR:-/tmp}/wprism-rmcombo-ssh-oracle.XXXXXX")
trap 'code=$?; if [ "$code" -ne 0 ]; then printf "SSH_CLEANUP_READY\n" >&2; fi; rm -rf -- "$TMP"' EXIT
DIAG_DIR="$TMP"
post_uuid=12345678-1234-1234-1234-123456789abc
WPRISM=fixture_host
fixture_answer="$2" fixture_diagnostic="$3" fixture_exit="$4"
fixture_host() {
  [ "$3" = target ] || return 81
  case "$2" in plan|promote|deploy) ;; *) return 82 ;; esac
  printf '%s\n' "$fixture_answer"
  [ -z "$fixture_diagnostic" ] || printf '%s\n' "$fixture_diagnostic" >&2
  return "$fixture_exit"
}
SH;
    $privacy = <<<'SH'
php -r '
    $files = glob($argv[1] . "/*");
    if (count($files) !== 6 || (fileperms($argv[1]) & 0777) !== 0700) exit(81);
    foreach ($files as $file) {
        if (!is_file($file) || is_link($file) || (fileperms($file) & 0777) !== 0600) exit(82);
    }
' "$DIAG_DIR" || fail 'the actual scenario did not keep all six stdout/stderr captures private'
SH;
    $process = proc_open(
        ['bash', '-c', $script . "\n" . ($sshDiagnosticDefinition[0] ?? '')
            . "\nrun_scenario_probe() {\n" . $sshCaptureBlock . "\n" . $privacy . "\n" . $block
            . "\nprintf 'SSH_READY\\n'\n}\nrun_scenario_probe\n",
            'ssh-scenario-oracle', $root, $answer, $diagnostic, (string) $exit],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
    );
    if (!is_resource($process)) {
        return [127, '', 'could not start SSH scenario oracle'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$sshPlanCases = [
    'deletion' => [
        '  plan_code=0', '  pass "one core post deletion',
        [
            'delete' => [['uuid' => '12345678-1234-1234-1234-123456789abc', 'type' => 'post', 'deletion_type' => 'post', 'blocked' => '']],
            'selected_actions' => [['manifest' => 'rank-math']],
            'effects_inventory' => [['source' => 'provider:rank-math-state/rebuild_all_link_state']],
            'code_mismatch' => [], 'provider_problems' => [],
        ],
    ],
    'converged' => [
        '  converged_code=0', '  [ -z "$(target_ledger_value promotion_lock)" ]',
        array_fill_keys(['create', 'update', 'adopt', 'drift', 'conflict', 'delete', 'delete_conflict', 'code_mismatch', 'selected_actions', 'provider_problems'], []),
    ],
];
foreach ($sshPlanCases as $phase => [$startToken, $endToken, $plan]) {
    $start = strpos($sshDeletion, $startToken);
    $end = $start === false ? false : strpos($sshDeletion, $endToken, $start);
    $block = $start === false || $end === false ? '' : substr($sshDeletion, $start, $end - $start);
    wprism_check($block !== '' && isset($sshDiagnosticDefinition[0]),
        "the SSH $phase plan has an executable command-to-acceptance block");
    $plan['env_missing'] = [['name' => 'fixture_optional', 'required' => false]];
    $plan['warnings'] = ['native action fired: fixture (verified)'];
    foreach (['ready', 'required-row', 'required-warning', 'missing-warnings', 'malformed-warnings', 'nonstring-warning', 'missing-env', 'malformed-env', 'provider-problem', 'php-stderr', 'php-stdout', 'php-startup-stdout', 'php-parse-stdout', 'malformed-stdout', 'malformed-plan-value', 'refusal'] as $mutation) {
        $candidate = $plan;
        switch ($mutation) {
            case 'required-row':
                $candidate['env_missing'][] = ['name' => 'home', 'required' => true];
                break;
            case 'required-warning':
                $candidate['warnings'][] = 'env_missing: option home is required';
                break;
            case 'missing-warnings':
                unset($candidate['warnings']);
                break;
            case 'malformed-warnings':
                $candidate['warnings'] = 'private-operator-value';
                break;
            case 'nonstring-warning':
                $candidate['warnings'][] = ['private-operator-value'];
                break;
            case 'missing-env':
                unset($candidate['env_missing']);
                break;
            case 'malformed-env':
                $candidate['env_missing'][0]['required'] = 'false';
                break;
            case 'provider-problem':
                $candidate['provider_problems'][] = ['provider' => 'fixture'];
                break;
            case 'malformed-plan-value':
                $candidate['delete'] = 'private-operator-value';
                break;
        }
        $answer = json_encode($candidate, JSON_THROW_ON_ERROR);
        switch ($mutation) {
            case 'php-stdout':
                $answer = "PHP Warning: private-operator-value in /fixture.php on line 12\n" . $answer;
                break;
            case 'php-startup-stdout':
                $answer = "PHP Warning: PHP Startup: Unable to load dynamic library private-operator-value in Unknown on line 0\n" . $answer;
                break;
            case 'php-parse-stdout':
                $answer = "PHP Parse error: private-operator-value\n" . $answer;
                break;
            case 'malformed-stdout':
                $answer = 'private-operator-value';
                break;
        }
        [$status, $stdout, $stderr] = $sshProbe(
            $block,
            $answer,
            $mutation === 'php-stderr' ? 'PHP Warning: private-operator-value in /fixture.php on line 12' : '',
            $mutation === 'refusal' ? 7 : 0
        );
        wprism_check(
            $mutation === 'ready'
                ? $status === 0 && $stdout === "SSH_READY\n" && $stderr === ''
                : $status !== 0 && !str_contains($stdout, 'SSH_READY')
                    && str_contains($stderr, 'combined')
                    && str_contains($stderr, "SSH_CLEANUP_READY\n")
                    && !str_contains($stdout . $stderr, 'private-operator-value'),
            "the actual SSH $phase plan block classifies $mutation with a public failure category and cleanup but no private values"
        );
    }
}
$sshHumanCases = [
    'baseline deploy' => [
        '  "$WPRISM" --envs-file="$TMP/envs.json" deploy target',
        '  pass "the four-plugin state',
        'deploy complete: verified immutable code baseline',
    ],
    'signed deletion' => [
        '  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes',
        '  status_json=',
        'promote complete: verified committed receipt; traffic exclusion released',
    ],
];
foreach ($sshHumanCases as $phase => [$startToken, $endToken, $receipt]) {
    $start = strpos($sshDeletion, $startToken);
    $end = $start === false ? false : strpos($sshDeletion, $endToken, $start);
    $block = $start === false || $end === false ? '' : substr($sshDeletion, $start, $end - $start);
    wprism_check($block !== '', "the SSH $phase has an executable complete-stream acceptance block");
    $success = "Success: applied 1 entities (canary clean) — plan was: {\"env_missing\":1}\n" . $receipt;
    foreach (['ready', 'required-stderr', 'required-stdout', 'php-stderr', 'php-stdout', 'php-startup', 'php-parse', 'missing-receipt', 'refusal'] as $mutation) {
        $answer = $success;
        $diagnostic = 'Warning: provider capability fired: fixture (verified)';
        switch ($mutation) {
            case 'required-stderr':
                $diagnostic = 'Warning: env_missing: private-operator-value';
                break;
            case 'required-stdout':
                $answer = "Warning: env_missing: private-operator-value\n" . $answer;
                break;
            case 'php-stderr':
                $diagnostic = 'PHP Deprecated: private-operator-value in /fixture.php on line 12';
                break;
            case 'php-stdout':
                $answer = "PHP Notice: private-operator-value in /fixture.php on line 12\n" . $answer;
                break;
            case 'php-startup':
                $diagnostic = 'PHP Warning: PHP Startup: Unable to load dynamic library private-operator-value in Unknown on line 0';
                break;
            case 'php-parse':
                $diagnostic = 'PHP Parse error: private-operator-value';
                break;
            case 'missing-receipt':
                $answer = 'Success: applied 1 entities (canary clean)';
                break;
        }
        [$status, $stdout, $stderr] = $sshProbe($block, $answer, $diagnostic, $mutation === 'refusal' ? 7 : 0);
        wprism_check(
            $mutation === 'ready'
                ? $status === 0 && $stdout === "SSH_READY\n" && $stderr === ''
                : $status !== 0 && !str_contains($stdout, 'SSH_READY') && !str_contains($stdout . $stderr, 'private-operator-value'),
            "the actual SSH $phase block gates $mutation before trusting its completed receipt"
        );
    }
}

foreach ([
    'PAIR="${RANK_MATH_COMBO_PAIR:-}"',
    'PORT1_RAW="${RANK_MATH_COMBO_PORT1:-}"',
    'PORT2_RAW="${RANK_MATH_COMBO_PORT2:-}"',
    'EXPECTED_SHA="${RANK_MATH_COMBO_EXPECTED_SOURCE_SHA:-}"',
    'PORT1 % 2 == 0 && PORT2 == PORT1 + 1',
    'export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"',
    "WPRISM_DB_ENGINE='mariadb' WPRISM_DB_HOST='wprism-shared-db'",
    '. tests/lib/pair_live_ownership.sh',
    'pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2"',
    'TMP_ROOT="$PAIR_LIVE_OWNERSHIP_TMP_ROOT"',
    'WPRISM_HOST_REGISTRY="$TMP_ROOT/host-envs.json"',
    'private Rank Math combination host registry mode is not 0600',
    'pair_live_ownership_mode_of "$WPRISM_HOST_REGISTRY"',
    'pair_live_ownership_acquire mariadb',
    'pair_live_ownership_up --artifacts --headless',
    'pair_live_ownership_reset',
    "pair_live_ownership_complete '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED'",
] as $ownershipWitness) {
    wprism_check(str_contains($live, $ownershipWitness),
        "the Docker combination owns its disposable boundary: $ownershipWitness");
}
wprism_check(
    !str_contains($live, 'RANK_MATH_COMBO_PAIR:-rmcombo')
        && !str_contains($live, 'RANK_MATH_COMBO_EXPECTED_SOURCE_SHA:-${WPRISM_EXPECTED_SOURCE_SHA')
        && !str_contains($live, 'destroy "$PAIR" >/dev/null 2>&1 || true')
        && !str_contains($live, 'left up for inspection after failure')
        && !str_contains($live, 'cleanup() {')
        && !str_contains($live, "stat -f '%Lp' \"\$TMP_ROOT\" 2>/dev/null || stat -c")
        && substr_count($live, '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED') === 1,
    'the Docker combination has no default allocation, suppressed teardown, failure leak, or early PASS route'
);
$ownershipPrepare = strpos($live, 'pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2"');
$leaseAcquire = strpos($live, 'pair_live_ownership_acquire mariadb');
$firstUp = strpos($live, 'pair_live_ownership_up --artifacts --headless', $leaseAcquire === false ? 0 : $leaseAcquire);
$firstLeg = strpos($live, 'run_leg forward reverse');
$ownedReset = strpos($live, 'pair_live_ownership_reset');
$secondUp = $ownedReset === false ? false : strpos($live, 'pair_live_ownership_up --artifacts --headless', $ownedReset);
$secondLeg = strpos($live, 'run_leg reverse forward');
$complete = strpos($live, "pair_live_ownership_complete '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED'");
wprism_check(
    $ownershipPrepare !== false
        && $leaseAcquire !== false
        && $firstUp !== false
        && $firstLeg !== false
        && $ownedReset !== false
        && $secondUp !== false
        && $secondLeg !== false
        && $complete !== false
        && $ownershipPrepare < $leaseAcquire
        && $leaseAcquire < $firstUp
        && $firstUp < $firstLeg
        && $firstLeg < $ownedReset
        && $ownedReset < $secondUp
        && $secondUp < $secondLeg
        && $secondLeg < $complete
        && substr_count($live, 'pair_live_ownership_reset') === 1,
    'the first order starts only after generic lease publication and the sole reset occurs between its two owned legs'
);
$ownershipHelper = (string) file_get_contents($root . '/sandbox/tests/lib/pair_live_ownership.sh');
wprism_check(
    str_contains($ownershipHelper, 'pair_live_ownership_remove_pair_roots')
        && str_contains($ownershipHelper, 'pair_live_ownership_remove_scratch')
        && str_contains($ownershipHelper, 'lease-batch-release')
        && str_contains($ownershipHelper, 'PAIR_LIVE_OWNERSHIP_BODY_COMPLETE=1'),
    'partial-up cleanup and sole PASS are delegated to the behaviorally tested shared ownership state machine'
);
$makefile = (string) file_get_contents($root . '/Makefile');
wprism_check(
    str_contains($makefile, "regress-rank-math-commerce-multilingual:\n")
        && str_contains($makefile, '@test -n "$(RANK_MATH_COMBO_PAIR)"')
        && str_contains($makefile, '@test -n "$(RANK_MATH_COMBO_PORT1)"')
        && str_contains($makefile, '@test -n "$(RANK_MATH_COMBO_PORT2)"')
        && str_contains($makefile, '@test -n "$(RANK_MATH_COMBO_EXPECTED_SOURCE_SHA)"')
        && str_contains($makefile,
            'RANK_MATH_COMBO_EXPECTED_SOURCE_SHA="$(RANK_MATH_COMBO_EXPECTED_SOURCE_SHA)" bash integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh'),
    'the Make entrypoint requires and forwards the exact scenario allocation instead of reviving defaults'
);

wprism_check_summary('regress_rank_math_commerce_multilingual_contract');
