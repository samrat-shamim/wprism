<?php
/** Product-path contract regression for the Rank Math commerce/multilingual stack. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once dirname(__DIR__, 4) . '/adapter-packages/rank-math/fixtures/private-refusal-evidence.php';

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
$scenarioAssertionsStart = strpos($live, 'assert_rmcombo_warning_free_capture() {');
$scenarioAssertionsEnd = $scenarioAssertionsStart === false
    ? false
    : strpos($live, "\nfor command in docker", $scenarioAssertionsStart);
$scenarioAssertionDefinitions = $scenarioAssertionsStart === false || $scenarioAssertionsEnd === false
    ? ''
    : substr($live, $scenarioAssertionsStart, $scenarioAssertionsEnd - $scenarioAssertionsStart);
wprism_check($scenarioAssertionDefinitions !== '', 'the scenario-owned complete-stream assertions are extractable');
$nativeJsonProbe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_CASE="$2"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
native_json_command() {
  case "$PROBE_CASE" in
    object) printf '%s\n' '{"ok":true}' ;;
    array) printf '%s\n' '[{"ok":true}]' ;;
    php-stdout)
      printf 'PHP Warning: fixture stdout diagnostic in /fixture.php on line 1\n'
      printf '%s\n' '{"ok":true}'
      ;;
    php-stderr)
      printf 'PHP Warning: fixture stderr diagnostic in /fixture.php on line 1\n' >&2
      printf '%s\n' '{"ok":true}'
      ;;
    startup)
      printf 'PHP Warning: PHP Startup: fixture diagnostic in Unknown on line 0\n' >&2
      printf '%s\n' '{"ok":true}'
      ;;
    parse)
      printf 'PHP Parse error: fixture diagnostic\n'
      printf '%s\n' '{"ok":true}'
      ;;
    nonzero)
      printf '%s\n' '{"ok":true}'
      return 7
      ;;
    malformed) printf '%s\n' 'not-json' ;;
    missing) ;;
    *) return 81 ;;
  esac
}
SH;
foreach (['object', 'array', 'php-stdout', 'php-stderr', 'startup', 'parse', 'nonzero', 'malformed', 'missing'] as $case) {
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
        $nativeJsonProbe . "\n" . $scenarioAssertionDefinitions . <<<'SH'

NATIVE_ANSWER=$(capture_rmcombo_native_json 'fixture native JSON observation' native_json_command)
jq -e '(type == "object" or type == "array")' <<<"$NATIVE_ANSWER" >/dev/null
printf 'NATIVE_CAPTURE_READY\n'
SH,
        [$root, $case],
        $root
    );
    $expectedFailure = match ($case) {
        'php-stdout', 'php-stderr', 'startup', 'parse' => 'emitted a PHP runtime diagnostic',
        'nonzero' => 'failed with exit 7',
        default => 'infrastructure failure:',
    };
    wprism_check(
        in_array($case, ['object', 'array'], true)
            ? $status === 0 && $stdout === "NATIVE_CAPTURE_READY\n" && $stderr === ''
            : $status !== 0
                && !str_contains($stdout, 'NATIVE_CAPTURE_READY')
                && str_contains($stderr, $expectedFailure),
        "scenario-native JSON transport classifies $case before publishing an observation"
    );
}
foreach ([
    'rank_math_readiness() {' => 'create_languages() {',
    'active_plugin_order() {' => 'establish_woocommerce_default_category() {',
    'establish_woocommerce_default_category() {' => 'default_product_category_state() {',
    'default_product_category_state() {' => 'default_product_category_identity() {',
    'default_product_category_identity() {' => 'identity_map_digest() {',
    'identity_map_digest() {' => 'canonical_capture_digest() {',
    'seed_rmcombo_stale_links() {' => 'native_state() {',
    'native_state() {' => 'product_response() {',
    'product_response() {' => 'head_projection() {',
    'redirection_response() {' => 'install_hostile_provider() {',
] as $startToken => $endToken) {
    $start = strpos($live, $startToken);
    $end = $start === false ? false : strpos($live, "\n$endToken", $start);
    $definition = $start === false || $end === false ? '' : substr($live, $start, $end - $start);
    wprism_check(
        (str_contains($definition, 'capture_rmcombo_native_json ') || str_contains($definition, 'capture_wprism_json_checked '))
            && !str_contains($definition, "| awk 'NF { line=\$0 } END { print line }'"),
        "$startToken retains complete-stream checked JSON transport"
    );
}
foreach ([
    'SOURCE_SEED' => [
        "SOURCE_SEED=\$(capture_rmcombo_native_json 'Rank Math combination source native seed' wp1 eval '",
        "require_observed_nonempty 'Rank Math combination source seed'",
    ],
    'TARGET_SEED' => [
        "TARGET_SEED=\$(capture_rmcombo_native_json 'Rank Math combination target native seed' wp2 eval '",
        "require_observed_nonempty 'Rank Math combination hostile target'",
    ],
] as $answer => [$startToken, $endToken]) {
    $start = strpos($live, $startToken);
    $end = $start === false ? false : strpos($live, $endToken, $start);
    $block = $start === false || $end === false ? '' : substr($live, $start, $end - $start);
    wprism_check(
        $block !== '' && !str_contains($block, "| awk 'NF { line=\$0 } END { print line }'"),
        "$answer captures its actual authoring program through complete-stream checked JSON transport"
    );
}
wprism_check(
    !str_contains($live, "| awk 'NF { line=\$0 } END { print line }'"),
    'the combination has no remaining stdout-only last-line JSON selector'
);
foreach ([
    ['SOURCE_CAPTURE', 'Rank Math combination source capture'],
    ['RESTORED_DEFAULT_CAPTURE', 'Rank Math combination restored-default capture'],
    ['COLLISION_RESTORED_CAPTURE', 'Rank Math combination collision-restored capture'],
    ['RETRY_SOURCE_CAPTURE', 'Rank Math combination retry source capture'],
    ['TARGET_RECAPTURE', 'Rank Math combination target recapture'],
    ['DELETE_SOURCE_CAPTURE', 'Rank Math combination deletion source capture'],
] as [$answer, $label]) {
    $capture = "capture_wprism_json_checked $answer";
    $position = strpos($live, $capture);
    $window = $position === false ? '' : substr($live, $position, 320);
    wprism_check(
        $position !== false
            && substr_count($live, $capture) === 1
            && str_contains($window, "'$label'")
            && str_contains($window, 'assert_rmcombo_warning_free_capture'),
        "$answer retains complete-stream, warning-free positive capture evidence"
    );
}
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
    'source capture was not warning-free after authoring a coherent Woo default',
    'reference_intersection_failed',
    'reference-intersection refusal partially published canonical state',
    'portable Woo default UUID did not resolve to the target matching native coordinate',
    'direct deletion remains refusal-only across the combined adapter boundary',
    'deletion_writer_exclusion_required',
    'combined direct deletion refusal changed native or target-runtime state',
    'signed promotion is exercised by the SSH scenario extension',
    'combined target recapture differs',
] as $witness) {
    wprism_check(str_contains($live, $witness), "the candidate-bound live scenario pins: $witness");
}

// The fd8 live control reached the correct redacted provider boundary but
// still searched public output for its private schema sentence. Execute the
// real ALTER/refusal/receipt/native-equality block with an actual private
// verifier; no stub is allowed to pronounce the receipt valid.
$dirtyStartToken = "wp2 db query 'ALTER TABLE wp_rank_math_internal_links ADD wprism_hostile_schema varchar(12) NULL' >/dev/null";
$dirtyEndToken = 'RECOVERY_ID=$(sed';
$dirtyStart = strpos($live, $dirtyStartToken);
$dirtyEnd = $dirtyStart === false ? false : strpos($live, $dirtyEndToken, $dirtyStart);
$dirtyBlock = $dirtyStart === false || $dirtyEnd === false ? ''
    : substr($live, $dirtyStart, $dirtyEnd - $dirtyStart);
wprism_check($dirtyBlock !== '', 'the actual hostile-schema command and full acceptance block are extractable');
$dirtyProfile = rank_math_private_refusal_profile('schema-mismatch');
$dirtyRecord = [
    'format' => 'wprism-private-refusal-evidence/v2',
    'command' => 'schema-settle',
    'reason_code' => 'schema_settle_failed',
    ...WPrism\PrivateRefusalEvidence::graph(new WPrism\PrivateEvidenceException(
        $dirtyProfile['message'], new RuntimeException($dirtyProfile['private_cause_message'])
    )),
];
$dirtyPhases = [
    'compile', 'lifecycle-status', 'schema-status', 'promotion-begin', 'checkpoint',
    'provider-settlement-begin', 'lifecycle-retire', 'lifecycle-activate', 'schema-settle',
];
$dirtyOutput = implode("\n", array_map(static fn(string $phase): string => "deploy phase: $phase", $dirtyPhases))
    . "\nError: " . $dirtyProfile['message']
    . "\nwprism: deploy: schema settlement failed (exit 1); later phases were not run"
    . "\nwprism: deploy: promotion lease cleanup confirmed\n";
$dirtyProbe = <<<'SH'
set -euo pipefail
ROOT="$1" fixture_case="$2" fixture_directory="$3" fixture_record="$4" fixture_output="$5" fixture_rc="$6"
fixture_trace="$fixture_directory/trace"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
COMPOSE=(fixture_compose)
HOSTILE_NATIVE='{"native":"unchanged","scheduler":[17]}'
wp2() {
  [ "$#" -eq 3 ] && [ "$1" = db ] && [ "$2" = query ] || return 81
  case "$3" in
    'ALTER TABLE wp_rank_math_internal_links ADD wprism_hostile_schema varchar(12) NULL')
      printf 'ADD\n' >>"$fixture_trace" ;;
    'ALTER TABLE wp_rank_math_internal_links DROP COLUMN wprism_hostile_schema')
      printf 'DROP\n' >>"$fixture_trace" ;;
    *) return 82 ;;
  esac
}
fixture_compose() {
  [ "$#" -ge 13 ] && [ "$1" = run ] && [ "$2" = --rm ] && [ "$3" = -T ] \
    && [ "$4" = --volume ] \
    && [ "$5" = "$ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" ] \
    && [ "$6" = --entrypoint ] && [ "$7" = php ] && [ "$8" = cli2 ] \
    && [ "$9" = /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php ] \
    && [ "${10}" = /wprism-test/PrivateRefusalReceipt.php ] \
    && [ "${12}" = schema-mismatch ] && [ "${13}" = /siterepo/.wprism/refusals ] || return 83
  case "${11}" in
    snapshot) [ "$#" -eq 13 ] && printf 'SNAPSHOT\n' >>"$fixture_trace" || return 84 ;;
    verify) [ "$#" -eq 14 ] && printf 'VERIFY\n' >>"$fixture_trace" || return 85 ;;
    *) return 86 ;;
  esac
  php "$ROOT/adapter-packages/rank-math/fixtures/private-refusal-evidence.php" \
    "$ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php" "${11}" "${12}" "$fixture_directory" "${@:14}"
}
host_wprism_combo() {
  [ "$#" -eq 2 ] && [ "$1" = wp2 ] && [ "$2" = deploy ] || return 87
  printf 'INVOKE\n' >>"$fixture_trace"
  if [ "$fixture_case" != stale ]; then
    cp "$fixture_record" "$fixture_directory/20260905-095334-schema-settle-bbbbbbbbbbbbbbbbbbbbbbbb.json"
    chmod 0600 "$fixture_directory/20260905-095334-schema-settle-bbbbbbbbbbbbbbbbbbbbbbbb.json"
  fi
  if [ "$fixture_case" = extra ]; then
    cp "$fixture_record" "$fixture_directory/20260905-095335-schema-settle-cccccccccccccccccccccccc.json"
    chmod 0600 "$fixture_directory/20260905-095335-schema-settle-cccccccccccccccccccccccc.json"
  fi
  printf '%s\n' "$fixture_output"
  if [ "$fixture_case" = php-stderr ]; then
    printf 'PHP Warning: private scenario receipt canary in /fixture.php on line 1\n' >&2
  fi
  return "$fixture_rc"
}
native_state() {
  [ "$#" -eq 1 ] && [ "$1" = wp2 ] || return 88
  printf 'NATIVE\n' >>"$fixture_trace"
  if [ "$fixture_case" = native-change ]; then
    printf '{"native":"private scenario receipt canary","scheduler":[18]}\n'
  else
    printf '%s\n' "$HOSTILE_NATIVE"
  fi
}
SH;
$dirtyScratch = sys_get_temp_dir() . '/wprism-rmcombo-private-receipt-' . bin2hex(random_bytes(8));
mkdir($dirtyScratch, 0700);
$dirtyCleanup = static function () use ($dirtyScratch): void {
    foreach (scandir($dirtyScratch) ?: [] as $case) {
        if ($case === '.' || $case === '..') {
            continue;
        }
        $directory = $dirtyScratch . '/' . $case;
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink($directory . '/' . $name);
            }
        }
        @rmdir($directory);
    }
    @rmdir($dirtyScratch);
};
register_shutdown_function($dirtyCleanup);
foreach ([
    'valid', 'success', 'wrong-provider', 'missing-cleanup', 'later-phase', 'missing-phase',
    'wrong-terminal', 'private-leak', 'php-stdout', 'php-stderr', 'stale', 'extra',
    'unrelated-cause', 'incomplete-graph', 'old-root-only-graph', 'native-change',
] as $case) {
    $directory = $dirtyScratch . '/' . $case;
    mkdir($directory, 0700);
    $candidate = $dirtyRecord;
    $output = $dirtyOutput;
    if ($case === 'unrelated-cause') {
        $candidate = array_replace($candidate, WPrism\PrivateRefusalEvidence::graph(
            new WPrism\PrivateEvidenceException($dirtyProfile['message'], new RuntimeException('private scenario receipt canary'))
        ));
    } elseif ($case === 'incomplete-graph') {
        $candidate['traversal']['record_complete'] = false;
    } elseif ($case === 'old-root-only-graph') {
        $candidate = array_replace($candidate, WPrism\PrivateRefusalEvidence::graph(
            new RuntimeException($dirtyProfile['private_cause_message'])
        ));
    } elseif ($case === 'wrong-provider') {
        $output = str_replace("'rank-math-state'", "'unrelated-provider'", $output);
    } elseif ($case === 'missing-cleanup') {
        $output = str_replace("wprism: deploy: promotion lease cleanup confirmed\n", '', $output);
    } elseif ($case === 'later-phase') {
        $output .= "deploy phase: lifecycle-settle\n";
    } elseif ($case === 'missing-phase') {
        $output = str_replace("deploy phase: checkpoint\n", '', $output);
    } elseif ($case === 'wrong-terminal') {
        $output = str_replace('schema settlement failed (exit 1)', 'lifecycle settlement failed (exit 1)', $output);
    } elseif ($case === 'private-leak') {
        $output .= $dirtyProfile['private_cause_message'] . "\n";
    } elseif ($case === 'php-stdout') {
        $output = "PHP Warning: private scenario receipt canary in /fixture.php on line 1\n" . $output;
    }
    $payload = $directory . '/candidate';
    file_put_contents($payload, json_encode($candidate, JSON_THROW_ON_ERROR));
    chmod($payload, 0600);
    $stale = $directory . '/20260905-095300-schema-settle-aaaaaaaaaaaaaaaaaaaaaaaa.json';
    file_put_contents($stale, json_encode($dirtyRecord, JSON_THROW_ON_ERROR));
    chmod($stale, 0600);
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
        $dirtyProbe . "\n" . $dirtyBlock . "\nprintf 'SCHEMA_REFUSAL_READY\\n'\n",
        [$root, $case, $directory, $payload, $output, $case === 'success' ? '0' : '1'],
        $root
    );
    $trace = is_file($directory . '/trace') ? (string) file_get_contents($directory . '/trace') : '';
    wprism_check(
        ($case === 'valid'
            ? $status === 0 && $stdout === "SCHEMA_REFUSAL_READY\n" && $stderr === ''
                && $trace === "ADD\nSNAPSHOT\nINVOKE\nVERIFY\nNATIVE\n"
            : $status !== 0 && !str_contains($stdout, 'SCHEMA_REFUSAL_READY')
                && str_contains($stderr, 'FAIL:') && !str_contains($trace, "DROP\n"))
            && !str_contains($stdout . $stderr, 'private scenario receipt canary')
            && !str_contains($stdout . $stderr, $dirtyProfile['private_cause_message']),
        "actual hostile-schema block $case checks mounted private evidence, terminal phases, cleanup and native nonmutation"
    );
}

// Cover the whole failed-deploy -> exact recover -> fault-removal window.
// The older refusal test ended before the missing recovery, so it could not
// reject 0700be15's immediate second deploy. The shared recovery fixture runs
// cli/wprism itself; only native WP/import, Docker and plugin rows are doubles.
$recoverySource = isset($argv[1]) ? (string) file_get_contents($argv[1]
    . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh') : $live;
$recoveryStart = strpos($recoverySource, "say 'deploy/apply combined product path against reverse-order hostile target'");
$recoveryEnd = $recoveryStart === false ? false : strpos($recoverySource, '# An inactive plugin plus one absent derived table', $recoveryStart);
$recoveryDefinitionsStart = strpos($recoverySource, 'assert_rmcombo_warning_free_capture() {');
$recoveryDefinitionsEnd = strpos($recoverySource, "\nfor command in docker", $recoveryDefinitionsStart ?: 0);
if ($recoveryStart === false || $recoveryEnd === false || $recoveryDefinitionsStart === false || $recoveryDefinitionsEnd === false) {
    throw new LogicException('the actual combined host recovery window is not extractable');
}
$recoveryBlock = substr($recoverySource, $recoveryStart, $recoveryEnd - $recoveryStart);
$recoveryDefinitions = substr($recoverySource, $recoveryDefinitionsStart, $recoveryDefinitionsEnd - $recoveryDefinitionsStart);
$recoveryProbe = <<<'SH'
PHP="$7" PAIR=rmcomborecovery
say() { :; }
probe_scratch=$(mktemp -d "${TMPDIR:-/tmp}/rmcombo-recovery.XXXXXX")
trap 'if [ "$fixture_case" = ready ] && [ ! -f "$probe_scratch/resumed" ]; then printf "%s\n" "${RECOVERY_OUT:-}" >&2; fi; if [ -f "$probe_scratch/recovered" ]; then printf "RECOVERED\n"; fi; if [ -f "$probe_scratch/resumed" ]; then printf "RESUMED\n"; fi; rm -rf -- "$probe_scratch"' EXIT
"$PHP" "$ROOT/sandbox/tests/fixtures/release/make-recover-site.php" "$probe_scratch/product" >/dev/null
R2=$(cd "$probe_scratch/product/target" && pwd -P)
jq --arg environment "${PAIR}2" '{envs:{($environment):.envs.plain}}' \
  "$probe_scratch/product/envs.json" >"$probe_scratch/envs.json"
export PATH="$probe_scratch/product/bin:$PATH"
export WPRISM_WP_CALLS="$probe_scratch/wp-calls" WPRISM_PROVIDER_RECOVERY_STATUS="$probe_scratch/provider-status.json"
export WPRISM_RECOVERY_RUNTIME_SOURCE="$ROOT/recovery/rollback-control.php"
probe_php="$ROOT/integration-scenarios/rank-math-commerce-multilingual/fixtures/checkpoint-recovery-probe.php"
COMPOSE=(recovery_compose)
recovery_compose() {
  if [ "$1" = run ]; then fixture_compose "$@"; return; fi
  [ "$#" -eq 2 ] && [ "$2" = wp2 ] || return 91
  case "$1" in
    stop)
      [ "$fixture_case" != stop-failed ] || return 7
      [ "$fixture_case" = stop-noop ] || : >"$probe_scratch/stopped"
      ;;
    start)
      [ -f "$probe_scratch/recovered" ] || return 92
      [ "$fixture_case" != start-failed ] || return 7
      [ "$fixture_case" = start-noop ] || rm "$probe_scratch/stopped"
      : >"$probe_scratch/resumed"
      ;;
    *) return 93 ;;
  esac
}
docker() {
  [ "$*" = "container inspect wprism-${PAIR}-wp2-1 --format {{json .}}" ] || return 94
  local running=true project="wprism-$PAIR"
  [ ! -f "$probe_scratch/stopped" ] || running=false
  [ "$fixture_case" != foreign-container ] || project=wprism-foreign
  jq -nc --arg project "$project" --argjson running "$running" \
    '{Name:("/"+$project+"-wp2-1"),Config:{Labels:{"com.docker.compose.project":$project,"com.docker.compose.service":"wp2"}},State:{Running:$running,Paused:false,Restarting:false}}'
}
wp2() {
  if [ "$#" -eq 3 ] && [ "$1" = db ] && [ "$2" = query ]; then
    case "$3" in
      'ALTER TABLE wp_rank_math_internal_links ADD wprism_hostile_schema varchar(12) NULL') printf 'ADD\n' >>"$fixture_trace" ;;
      'ALTER TABLE wp_rank_math_internal_links DROP COLUMN wprism_hostile_schema')
        [ -f "$probe_scratch/recovered" ] || { printf 'FAIL: fault removal bypassed checkpoint recovery\n' >&2; return 95; }
        printf 'DROP\n' >>"$fixture_trace" ;;
      *) return 96 ;;
    esac
    return
  fi
  [ "$#" -eq 2 ] && [ "$1" = eval ] || return 97
  local fault=ready
  [ ! -f "$probe_scratch/recovered" ] || fault="$fixture_case"
  case "$fixture_case" in virgin-*) fault="$fixture_case" ;; esac
  "$PHP" "$probe_php" observe "$R2" "$fault" "$2"
}
active_plugin_order() {
  if [ -f "$probe_scratch/recovered" ] && [ "$fixture_case" = order-drift ]; then printf '["changed"]\n'
  else printf '["polylang","woocommerce","seo-by-rank-math","advanced-custom-fields"]\n'; fi
}
default_product_category_state() {
  if [ -f "$probe_scratch/recovered" ] && [ "$fixture_case" = default-drift ]; then printf '{"option":19}\n'
  else printf '{"option":17}\n'; fi
}
identity_map_digest() {
  [ -f "$R2/.wprism/probe-ledger-installed" ] || return 101
  if [ "$fixture_case" = map-before-minted ] || { [ -f "$probe_scratch/recovered" ] && [ "$fixture_case" = map-drift ]; }; then
    printf '{"count":1,"sha256":"changed"}\n'
  else printf '{"count":0,"sha256":"4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945"}\n'; fi
}
native_state() {
  [ "$*" = wp2 ] || return 98
  if [ -f "$probe_scratch/recovered" ] && [ "$fixture_case" = native-drift ]; then printf '{"native":"changed"}\n'
  else printf '%s\n' "$HOSTILE_NATIVE"; fi
}
host_wprism_combo() {
  [ "$1" = wp2 ] || return 99
  if [ "$2" = deploy ]; then
    cp "$fixture_record" "$fixture_directory/20260905-095334-schema-settle-bbbbbbbbbbbbbbbbbbbbbbbb.json"
    chmod 0600 "$fixture_directory/20260905-095334-schema-settle-bbbbbbbbbbbbbbbbbbbbbbbb.json"
    "$PHP" "$probe_php" begin "$R2" "$fixture_case" >"$WPRISM_PROVIDER_RECOVERY_STATUS" || return "$?"
    printf '%s\n' "$fixture_output"
    local environment="${PAIR}2" id=deploy-recover-fixture-owner
    [ "$fixture_case" != hint-wrong-target ] || environment=foreign2
    [ "$fixture_case" != hint-wrong-id ] || id=deploy-another-owner
    if [ "$fixture_case" != hint-missing ]; then
      printf 'wprism: deploy: once that exclusion is in place, recover with: wprism recover %s --restore=%s --writers-excluded --operator-directed\n' "$environment" "$id"
    fi
    if [ "$fixture_case" = hint-duplicate ]; then
      printf 'wprism: deploy: once that exclusion is in place, recover with: wprism recover %s --restore=%s --writers-excluded --operator-directed\n' "$environment" "$id"
    fi
    return 1
  fi
  [ "$#" -eq 6 ] && [ "$2" = recover ] && [ "$3" = --restore=deploy-recover-fixture-owner ] \
    && [ "$4" = --writers-excluded ] && [ "$5" = --operator-directed ] && [ "$6" = --format=json ] \
    && [ -f "$probe_scratch/stopped" ] || return 100
  local result rc=0
  if [ "$fixture_case" = import-failed ]; then export WPRISM_IMPORT_EXIT=7; fi
  result=$(cd "$probe_scratch/product/site" && "$PHP" "$ROOT/cli/wprism" \
    --envs-file="$probe_scratch/envs.json" recover "${PAIR}2" "${@:3}") || rc=$?
  if [ "$rc" -ne 0 ]; then printf '%s\n' "$result"; return "$rc"; fi
  "$PHP" "$probe_php" complete "$R2" "$fixture_case" || return "$?"
  : >"$probe_scratch/recovered"
  case "$fixture_case" in
    receipt-wrong-id) result=$(jq '.checkpoint.id="deploy-another-owner"' <<<"$result") ;;
    receipt-wrong-hash) result=$(jq '.checkpoint.artifact_hash="wrong"' <<<"$result") ;;
    receipt-wrong-target) result=$(jq '.environment="foreign2"' <<<"$result") ;;
    receipt-missing-step) result=$(jq '.steps |= .[:-1]' <<<"$result") ;;
    receipt-failed-step) result=$(jq '.steps[2].ok=false' <<<"$result") ;;
    receipt-unrecovered) result=$(jq '.recovered=false' <<<"$result") ;;
    receipt-extra) printf '{}\n' ;;
    receipt-warning) printf 'PHP Warning: fixture diagnostic\n' >&2 ;;
  esac
  printf '%s\n' "$result"
}
SH;
foreach (['ready', 'stop-failed', 'stop-noop', 'foreign-container', 'hint-missing', 'hint-duplicate', 'hint-wrong-target',
    'hint-wrong-id', 'import-failed', 'receipt-wrong-id', 'receipt-wrong-hash', 'receipt-wrong-target', 'receipt-missing-step',
    'receipt-failed-step', 'receipt-unrecovered', 'receipt-extra', 'receipt-warning', 'retained-debt', 'schema-debt',
    'control-read-error', 'native-drift', 'order-drift', 'default-drift', 'map-drift', 'start-failed', 'start-noop',
    'virgin-partial', 'virgin-denied', 'virgin-view', 'ledger-partial', 'ledger-denied', 'ledger-view', 'map-before-minted'] as $case) {
    $directory = $dirtyScratch . '/recovery-' . $case;
    mkdir($directory, 0700);
    $payload = $directory . '/candidate';
    file_put_contents($payload, json_encode($dirtyRecord, JSON_THROW_ON_ERROR));
    chmod($payload, 0600);
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run($dirtyProbe . "\n" . $recoveryProbe . "\n"
        . $recoveryDefinitions . "\n" . $recoveryBlock . "\nprintf 'RECOVERY_WINDOW_READY\\n'\n",
        [$root, $case, $directory, $payload, $dirtyOutput, '1', PHP_BINARY], $root);
    wprism_check($case === 'ready'
        ? $status === 0 && $stdout === "RECOVERY_WINDOW_READY\nRECOVERED\nRESUMED\n" && $stderr === ''
        : $status !== 0 && !str_contains($stdout, 'RECOVERY_WINDOW_READY'),
        "$case validates the actual target-bound recovery window before removing its fault and continuing");
    wprism_check_same(!in_array($case, ['stop-failed', 'stop-noop', 'foreign-container', 'hint-missing', 'hint-duplicate',
        'hint-wrong-target', 'hint-wrong-id', 'import-failed', 'virgin-partial', 'virgin-denied', 'virgin-view', 'map-before-minted'], true), str_contains($stdout, "RECOVERED\n"),
        "$case reaches its intended side of actual host recovery, not an unrelated earlier failure");
    if (!in_array($case, ['ready', 'start-failed', 'start-noop'], true)) {
        $trace = is_file($directory . '/trace') ? (string) file_get_contents($directory . '/trace') : '';
        wprism_check(!str_contains($trace, "DROP\n") && !str_contains($stdout, 'RESUMED'),
            "$case cannot remove the hostile schema or reopen HTTP after incomplete recovery");
    }
    if ($case === 'ready' && $status !== 0) fwrite(STDERR, $stdout . $stderr);
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
capture_rmcombo_native_json() {
  shift
  "$@"
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

// Execute the actual activation/default chronology. The fd8 fixture split
// term and TT counters before Woo activation; a text pin could prove the new
// numbers while missing an accidental reorder that recreates the same defect.
$defaultSetupStart = strpos($live, "# Woo's installer writes default_product_cat");
$defaultSetupEndToken = "wp2 db query 'ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=9300001' >/dev/null";
$defaultSetupEnd = $defaultSetupStart === false ? false : strpos($live, $defaultSetupEndToken, $defaultSetupStart);
$defaultSetupBlock = $defaultSetupStart === false || $defaultSetupEnd === false
    ? ''
    : substr($live, $defaultSetupStart, $defaultSetupEnd + strlen($defaultSetupEndToken) - $defaultSetupStart);
wprism_check($defaultSetupBlock !== '', 'the actual Woo activation/default chronology is extractable');
$sourceDefaultFixture = [
    'installer_default' => 3200001,
    'option' => 3200007,
    'role' => 'source',
    'slug' => 'rmcombo-default-product-category',
    'taxonomy' => 'product_cat',
    'taxonomy_term_id' => 3200007,
    'term_id' => 3200007,
    'term_taxonomy_id' => 3200007,
];
$targetDefaultFixture = [
    'installer_default' => 9200001,
    'option' => 9200001,
    'role' => 'target',
    'slug' => 'rmcombo-default-product-category',
    'taxonomy' => 'product_cat',
    'taxonomy_term_id' => 9200007,
    'term_id' => 9200007,
    'term_taxonomy_id' => 9200007,
];
$defaultSetupProbe = <<<'SH'
set -euo pipefail
TRACE_PATH="$1" SOURCE_FIXTURE="$2" TARGET_FIXTURE="$3"
source_order=forward target_order=reverse
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { :; }
require_observed_nonempty() { [ -n "$2" ] || fail "$1 is empty"; }
wp1() {
  if [ "$1" = db ] && [ "$2" = query ]; then
    case "$3" in
      'ALTER TABLE wp_posts AUTO_INCREMENT=3100001; ALTER TABLE wp_terms AUTO_INCREMENT=3200001; ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=3200001;') printf 'SOURCE_INITIAL\n' >>"$TRACE_PATH" ;;
      'ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=3300001') printf 'SOURCE_DIVERGE\n' >>"$TRACE_PATH" ;;
      *) return 81 ;;
    esac
    return 0
  fi
  [ "$1" = eval ] && [ "$2" = 'WC_Install::create_terms();' ] || return 82
  printf 'SOURCE_NATIVE_TERMS\n' >>"$TRACE_PATH"
}
wp2() {
  if [ "$1" = db ] && [ "$2" = query ]; then
    case "$3" in
      'ALTER TABLE wp_posts AUTO_INCREMENT=9100001; ALTER TABLE wp_terms AUTO_INCREMENT=9200001; ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=9200001;') printf 'TARGET_INITIAL\n' >>"$TRACE_PATH" ;;
      'ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=9300001') printf 'TARGET_DIVERGE\n' >>"$TRACE_PATH" ;;
      *) return 83 ;;
    esac
    return 0
  fi
  [ "$1" = eval ] && [ "$2" = 'WC_Install::create_terms();' ] || return 84
  printf 'TARGET_NATIVE_TERMS\n' >>"$TRACE_PATH"
}
install_stack() { printf 'INSTALL_%s_%s\n' "$1" "$2" >>"$TRACE_PATH"; }
persist_active_plugin_order() { printf 'ORDER_%s_%s\n' "$1" "$2" >>"$TRACE_PATH"; }
install_custom_post_type() { printf 'CPT_%s\n' "$1" >>"$TRACE_PATH"; }
active_plugin_order() {
  if [ "$1" = wp1 ]; then
    printf '%s\n' '["polylang","advanced-custom-fields","seo-by-rank-math","woocommerce"]'
  else
    printf '%s\n' '["polylang","woocommerce","seo-by-rank-math","advanced-custom-fields"]'
  fi
}
establish_woocommerce_default_category() {
  printf 'DEFAULT_%s_%s\n' "$1" "$2" >>"$TRACE_PATH"
  if [ "$2" = source ]; then printf '%s\n' "$SOURCE_FIXTURE"; else printf '%s\n' "$TARGET_FIXTURE"; fi
}
SH;
$setupCases = [
    'ready' => [$defaultSetupBlock, $sourceDefaultFixture, $targetDefaultFixture, true],
    'old preactivation split' => [
        str_replace(
            'wp_term_taxonomy AUTO_INCREMENT=3200001;',
            'wp_term_taxonomy AUTO_INCREMENT=3300001;',
            $defaultSetupBlock
        ),
        $sourceDefaultFixture,
        $targetDefaultFixture,
        false,
    ],
    'source unchanged installer default' => [
        $defaultSetupBlock,
        array_replace($sourceDefaultFixture, ['option' => 3200001]),
        $targetDefaultFixture,
        false,
    ],
    'source coordinate mismatch' => [
        $defaultSetupBlock,
        array_replace($sourceDefaultFixture, ['term_taxonomy_id' => 3300001]),
        $targetDefaultFixture,
        false,
    ],
    'target already selected candidate' => [
        $defaultSetupBlock,
        $sourceDefaultFixture,
        array_replace($targetDefaultFixture, ['option' => 9200007]),
        false,
    ],
    'cross-host aligned local id' => [
        $defaultSetupBlock,
        $sourceDefaultFixture,
        array_replace($targetDefaultFixture, [
            'taxonomy_term_id' => 3200007,
            'term_id' => 3200007,
            'term_taxonomy_id' => 3200007,
        ]),
        false,
    ],
];
foreach ($setupCases as $case => [$block, $sourceFixture, $targetFixture, $accept]) {
    $trace = tempnam(sys_get_temp_dir(), 'wprism-rmcombo-default-setup-');
    if (!is_string($trace)) {
        throw new RuntimeException('could not allocate Woo default setup trace');
    }
    file_put_contents($trace, '');
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
        $defaultSetupProbe . "\n" . $block . "\nprintf 'SETUP_READY\\n'\n",
        [
            $trace,
            json_encode($sourceFixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($targetFixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ],
        $root
    );
    $traceBytes = (string) file_get_contents($trace);
    unlink($trace);
    $expectedTrace = "SOURCE_INITIAL\nTARGET_INITIAL\nINSTALL_1_forward\nINSTALL_2_reverse\n"
        . "ORDER_1_forward\nORDER_2_reverse\nCPT_1\nCPT_2\nSOURCE_NATIVE_TERMS\nTARGET_NATIVE_TERMS\n"
        . "DEFAULT_wp1_source\nDEFAULT_wp2_target\nSOURCE_DIVERGE\nTARGET_DIVERGE\n";
    wprism_check(
        $accept
            ? $status === 0 && $stdout === "SETUP_READY\n" && $stderr === '' && $traceBytes === $expectedTrace
            : $status !== 0 && !str_contains($stdout, 'SETUP_READY'),
        "actual Woo default setup classifies $case and preserves activation-before-divergence ordering"
    );
}

// Capture the exact PHP submitted by the new physical/native default oracle,
// then execute it against controlled responses. A database error or malformed
// row must not become a stable null witness, while a real divergent value must
// remain observable for the negative capture nonmutation proof.
$defaultOracleStart = strpos($live, 'default_product_category_state() {');
$defaultOracleEnd = $defaultOracleStart === false
    ? false
    : strpos($live, "\ndefault_product_category_identity() {", $defaultOracleStart);
$defaultOracleDefinition = $defaultOracleStart === false || $defaultOracleEnd === false
    ? ''
    : substr($live, $defaultOracleStart, $defaultOracleEnd - $defaultOracleStart);
$defaultOracleCapture = <<<'SH'
set -euo pipefail
wp1() {
  [ "$#" -eq 2 ] && [ "$1" = eval ] || return 81
  php -r 'printf("%s\n", base64_encode($argv[1]));' "$2"
}
capture_rmcombo_native_json() {
  shift
  "$@"
}
SH;
[$defaultOracleStatus, $defaultOracleStdout, $defaultOracleStderr] = WPrismTest\ShellProbe::run(
    $defaultOracleCapture . "\n" . $defaultOracleDefinition . "\ndefault_product_category_state wp1\n",
    [],
    $root
);
$submittedDefaultOracle = base64_decode(trim($defaultOracleStdout), true);
$submittedDefaultOracle = is_string($submittedDefaultOracle) ? $submittedDefaultOracle : '';
wprism_check(
    $defaultOracleDefinition !== ''
        && $defaultOracleStatus === 0
        && $defaultOracleStderr === ''
        && $submittedDefaultOracle !== '',
    'the actual native default-category shell oracle submits one inspectable checked PHP program'
);
$runDefaultOracle = static function (string $case) use ($submittedDefaultOracle): array {
    WPrismTest\WpStore::instance()->reset();
    WPrismTest\WpStore::instance()->options['default_product_cat'] = $case === 'bad-option' ? '041' : '41';
    $wpdb = new class($case) {
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $last_error = '';

        /** @var list<string> */
        public array $queries = [];

        public function __construct(private readonly string $case) {}

        public function prepare(string $sql, mixed ...$values): string {
            if (count($values) !== 1 || !str_contains($sql, '%d')) {
                throw new RuntimeException('default-category oracle received malformed bindings');
            }
            return preg_replace('/%d/', (string) (int) $values[0], $sql, 1) ?? '';
        }

        public function get_row(string $sql, string $output): mixed {
            if ($output !== ARRAY_A) {
                throw new RuntimeException('default-category oracle did not request ARRAY_A');
            }
            $this->last_error = '';
            $this->queries[] = $sql;
            $kind = str_contains($sql, 'FROM wp_term_taxonomy') ? 'taxonomy' : 'term';
            if ($this->case === "$kind-error") {
                $this->last_error = 'fixture database error';
                return null;
            }
            if ($this->case === "$kind-malformed") {
                return false;
            }
            if ($this->case === "$kind-partial") {
                return $kind === 'term' ? ['term_id' => '41'] : ['term_taxonomy_id' => '41'];
            }
            if ($this->case === "$kind-wrong-id") {
                return $kind === 'term'
                    ? ['term_id' => '42', 'slug' => 'rmcombo-default-product-category']
                    : ['term_taxonomy_id' => '42', 'term_id' => '41', 'taxonomy' => 'product_cat'];
            }
            if ($kind === 'taxonomy' && $this->case === 'divergent') {
                return null;
            }
            return $kind === 'term'
                ? ['term_id' => '41', 'slug' => 'rmcombo-default-product-category']
                : ['term_taxonomy_id' => '41', 'term_id' => '41', 'taxonomy' => 'product_cat'];
        }
    };
    $GLOBALS['wpdb'] = $wpdb;
    ob_start();
    try {
        eval($submittedDefaultOracle);
        $bytes = (string) ob_get_clean();
        return ['exception' => null, 'output' => json_decode($bytes, true, 512, JSON_THROW_ON_ERROR), 'queries' => $wpdb->queries];
    } catch (Throwable $failure) {
        ob_end_clean();
        return ['exception' => $failure, 'output' => null, 'queries' => $wpdb->queries];
    }
};
$healthyDefaultOracle = $runDefaultOracle('healthy');
wprism_check(
    $healthyDefaultOracle['exception'] === null
        && $healthyDefaultOracle['output'] === [
            'option' => 41,
            'term' => ['term_id' => 41, 'slug' => 'rmcombo-default-product-category'],
            'term_taxonomy' => ['term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'product_cat'],
        ]
        && count($healthyDefaultOracle['queries']) === 2,
    'the actual native default oracle proves one option across both physical product_cat coordinates'
);
$divergentDefaultOracle = $runDefaultOracle('divergent');
wprism_check(
    $divergentDefaultOracle['exception'] === null
        && ($divergentDefaultOracle['output']['option'] ?? null) === 41
        && ($divergentDefaultOracle['output']['term']['term_id'] ?? null) === 41
        && array_key_exists('term_taxonomy', $divergentDefaultOracle['output'])
        && $divergentDefaultOracle['output']['term_taxonomy'] === null,
    'the native oracle preserves the deliberately divergent option preimage instead of inventing coherence'
);
foreach ([
    'term-error', 'taxonomy-error', 'term-malformed', 'taxonomy-malformed',
    'term-partial', 'taxonomy-partial', 'term-wrong-id', 'taxonomy-wrong-id', 'bad-option',
] as $case) {
    $failedDefaultOracle = $runDefaultOracle($case);
    wprism_check(
        $failedDefaultOracle['exception'] instanceof RuntimeException
            && $failedDefaultOracle['output'] === null,
        "$case cannot become an empty or zero positive Woo default-category witness"
    );
}

// Execute the complete public negative window, including the engine envelope,
// native/identity/tree nonmutation, exact restore and warning-free recapture.
// Mutations target each acceptance seam so this is evidence for the command
// path rather than a collection of nearby strings.
$intersectionStart = strpos(
    $live,
    "say 'incoherent Woo default refuses public capture without publication or native/identity drift'"
);
$intersectionEndToken = "pass 'one divergent custom category refuses exactly; the explicitly authored coherent default remains portable and warning-free'";
$intersectionEnd = $intersectionStart === false ? false : strpos($live, $intersectionEndToken, $intersectionStart);
$intersectionBlock = $intersectionStart === false || $intersectionEnd === false
    ? ''
    : substr($live, $intersectionStart, $intersectionEnd + strlen($intersectionEndToken) - $intersectionStart);
wprism_check($intersectionBlock !== '', 'the complete public Woo reference-intersection refusal window is extractable');
$intersectionProbe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_CASE="$2" R1="$3"
SOURCE_SEED='{"categories":{"en":3200101},"category_tts":{"en":3300101}}'
SOURCE_DEFAULT_FIXTURE='{"installer_default":3200001,"option":3200007,"role":"source","slug":"rmcombo-default-product-category","taxonomy":"product_cat","taxonomy_term_id":3200007,"term_id":3200007,"term_taxonomy_id":3200007}'
SOURCE_DEFAULT_NATIVE='{"option":3200007,"term":{"term_id":3200007,"slug":"rmcombo-default-product-category"},"term_taxonomy":{"term_taxonomy_id":3200007,"term_id":3200007,"taxonomy":"product_cat"}}'
PHASE_FILE="$R1/phase" NATIVE_COUNT="$R1/native-count" OPTION_COUNT="$R1/option-count" IDENTITY_COUNT="$R1/identity-count"
printf 'good\n' >"$PHASE_FILE"
printf '0\n' >"$NATIVE_COUNT"
printf '0\n' >"$OPTION_COUNT"
printf '0\n' >"$IDENTITY_COUNT"
mkdir -p "$R1/state"
printf 'canonical\n' >"$R1/state/entity"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
say() { :; }
pass() { :; }
. "$ROOT/sandbox/conformance/asserts.sh"
wp1() {
  if [ "$1" = eval ]; then
    if grep -Fq 'negative fixture did not persist' <<<"$2"; then
      printf 'bad\n' >"$PHASE_FILE"
      return 0
    fi
    if grep -Fq 'negative fixture did not restore exactly' <<<"$2"; then
      [ "$PROBE_CASE" != restore-failure ] || return 73
      printf 'good\n' >"$PHASE_FILE"
      return 0
    fi
    return 74
  fi
  [ "$1" = wprism ] && [ "$2" = capture ] || return 75
  case " $* " in
    *' --out=/siterepo/.tmp-rmcombo-default-restored '*)
      mkdir -p "$R1/.tmp-rmcombo-default-restored"
      cp -R "$R1/state/." "$R1/.tmp-rmcombo-default-restored/"
      if [ "$PROBE_CASE" = restored-tree-drift ]; then printf 'drift\n' >>"$R1/.tmp-rmcombo-default-restored/entity"; fi
      if [ "$PROBE_CASE" = restored-stderr-warning ]; then
        printf 'Warning: fixture restored-default capture warning\n' >&2
      fi
      if [ "$PROBE_CASE" = restored-warning ]; then
        printf '%s\n' '{"warnings":["fixture capture warning"]}'
      else
        printf '%s\n' '{"warnings":[]}'
      fi
      return 0
      ;;
  esac
  if [ "$PROBE_CASE" = publication-drift ]; then printf 'drift\n' >>"$R1/state/entity"; fi
  if [ "$PROBE_CASE" = success ]; then
    printf '%s\n' '{"warnings":[]}'
    return 0
  fi
  if [ "$PROBE_CASE" = php-diagnostic ]; then
    printf 'PHP Warning: fixture diagnostic in /fixture.php on line 1\n' >&2
  fi
  if [ "$PROBE_CASE" = downgraded-warning ]; then
    printf 'Warning: option default_product_cat: unmanaged term id 3200101 — key skipped\n' >&2
  fi
  if [ "$PROBE_CASE" = wrong-reason ]; then
    printf '%s\n' '{"format":"wprism-command-refusal/v1","reason_code":"fixture_refusal"}'
  else
    printf '%s\n' '{"format":"wprism-command-refusal/v1","reason_code":"reference_intersection_failed"}'
  fi
  return 1
}
native_state() {
  count=$(cat "$NATIVE_COUNT"); count=$((count + 1)); printf '%s\n' "$count" >"$NATIVE_COUNT"
  if [ "$PROBE_CASE" = native-drift ] && [ "$count" -eq 2 ]; then
    printf '%s\n' '{"native":"changed"}'
  else
    printf '%s\n' '{"native":"stable"}'
  fi
}
default_product_category_state() {
  count=$(cat "$OPTION_COUNT"); count=$((count + 1)); printf '%s\n' "$count" >"$OPTION_COUNT"
  if [ "$count" -ge 3 ] || [ "$(cat "$PHASE_FILE")" = good ]; then
    printf '%s\n' "$SOURCE_DEFAULT_NATIVE"
  elif [ "$PROBE_CASE" = option-drift ] && [ "$count" -eq 2 ]; then
    printf '%s\n' '{"option":3200102,"term":null,"term_taxonomy":null}'
  else
    printf '%s\n' '{"option":3200101,"term":{"term_id":3200101,"slug":"rmcombo-catalog-en"},"term_taxonomy":null}'
  fi
}
identity_map_digest() {
  count=$(cat "$IDENTITY_COUNT"); count=$((count + 1)); printf '%s\n' "$count" >"$IDENTITY_COUNT"
  if [ "$PROBE_CASE" = identity-drift ] && [ "$count" -eq 2 ]; then
    printf '%s\n' '{"count":3,"sha256":"changed"}'
  else
    printf '%s\n' '{"count":2,"sha256":"stable"}'
  fi
}
canonical_capture_digest() { shasum -a 256 "$1/state/entity" | awk '{print $1}'; }
SH;
foreach ([
    'ready', 'success', 'wrong-reason', 'php-diagnostic', 'downgraded-warning', 'publication-drift',
    'native-drift', 'option-drift', 'identity-drift', 'restore-failure',
    'restored-warning', 'restored-stderr-warning', 'restored-tree-drift',
] as $case) {
    $scratch = sys_get_temp_dir() . '/wprism-rmcombo-intersection-' . bin2hex(random_bytes(8));
    if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
        throw new RuntimeException('could not allocate Woo intersection probe scratch');
    }
    try {
        [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
            $intersectionProbe . "\n" . $scenarioAssertionDefinitions . "\n"
                . $intersectionBlock . "\nprintf 'INTERSECTION_READY\\n'\n",
            [$root, $case, $scratch],
            $root
        );
        wprism_check(
            $case === 'ready'
                ? $status === 0 && $stdout === "INTERSECTION_READY\n" && $stderr === ''
                : $status !== 0 && !str_contains($stdout, 'INTERSECTION_READY'),
            "actual public Woo intersection window classifies $case before accepting portable evidence"
        );
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                return;
            }
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $remove($path . '/' . $name);
                }
            }
            @rmdir($path);
        };
        $remove($scratch);
    }
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

// Replay the actual host transport AND its caller, not a fabricated diagnostic
// answer. The native command is a double; Cli's real repository witness and
// Kernel recorder publish private graphs, then the actual mounted reader runs.
$diagnosticSource = isset($argv[1]) ? (string) file_get_contents($argv[1]
    . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh') : $live;
$diagnosticStart = strpos($diagnosticSource, 'host_wprism_combo() {');
$diagnosticEnd = strpos($diagnosticSource, 'WP_CLI_MEMORY_LIMIT=512M', $diagnosticStart ?: 0);
if ($diagnosticStart === false || $diagnosticEnd === false) {
    throw new LogicException('the real combined host transport is unavailable');
}
$diagnosticDefinitions = substr($diagnosticSource, $diagnosticStart, $diagnosticEnd - $diagnosticStart);
$diagnosticCaller = WPrismTest\ShellProbe::captureBlock($diagnosticSource, 'CLEAN_DEPLOY', 'CLEAN_DEPLOY_PHASES=');
$nativeTransportStart = strpos($diagnosticSource, 'WP_CLI_MEMORY_LIMIT=512M');
$nativeTransportEndToken = 'wp2() { wp_side 2 "$@"; }';
$nativeTransportEnd = $nativeTransportStart === false ? false : strpos($diagnosticSource, $nativeTransportEndToken, $nativeTransportStart);
if ($nativeTransportStart === false || $nativeTransportEnd === false) {
    throw new LogicException('the real native Apply transport is unavailable');
}
$nativeTransportDefinitions = substr($diagnosticSource, $nativeTransportStart,
    $nativeTransportEnd + strlen($nativeTransportEndToken) - $nativeTransportStart);
$initialDiagnosticCaller = WPrismTest\ShellProbe::captureBlock($diagnosticSource, 'INITIAL', "jq -e '");
$diagnosticScratch = sys_get_temp_dir() . '/wprism-rmcombo-host-diagnostic-' . bin2hex(random_bytes(8));
mkdir($diagnosticScratch, 0700);
$diagnosticProbe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_CASE="$2" PROBE_SITE="$3" PHP="$4" PAIR="$5" WPRISM_HOST_REGISTRY=fixture-registry
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
. "$ROOT/sandbox/tests/lib/private_command_capture.sh"
COMPOSE=(diagnostic_compose)
diagnostic_compose() {
  if [ "${4-}" = --entrypoint ]; then
    [ "$#" -eq 16 ] && [ "$1 $2 $3 $4 $5 $6 $7 $8 $9" = 'run --rm -T --entrypoint php cli2 -d memory_limit=512M /usr/local/bin/wp' ] \
      && [ "${10} ${11} ${12} ${13} ${14} ${15} ${16}" = 'wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision=fixture --format=json' ] || return 85
    printf 'apply\n' >>"$PROBE_SITE/trace"
    : >"$PROBE_SITE/host-file"
    if [ "$PROBE_CASE" != ready ]; then diagnostic_record apply || return "$?"; fi
    printf ' Container wprism-%s-cli2-run-aabbcc Created \n' "$PAIR" >&2
    [ "$PROBE_CASE" != warning ] || printf 'PHP Warning: public-command-warning in Unknown on line 0\n' >&2
    case "$PROBE_CASE" in
      refused|too-many|record-mode)
        printf '%s\n' '{"format":"wprism-command-refusal/v1","ok":false,"command":"apply","reason_code":"apply_failed","details_redacted":true}'
        return 7 ;;
    esac
    printf '%s\n' '{"canary":"clean","verification":{"result":"pass"},"warnings":[]}'
    return 0
  fi
  # The preceding driver embeds its inventory in its submitted PHP; the new
  # one supplies that same owner-declared inventory as argv. Execute either
  # real program so a counterfactual never fails merely on the fixture ABI.
  { [ "$#" -eq 12 ] || [ "$#" -eq 13 ]; } && [ "$1 $2 $3 $4" = 'run --rm -T --volume' ] \
    && [ "$5" = "$ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" ] \
    && [ "$6 $7 $8 $9" = '--entrypoint php cli2 -r' ] || return 81
  local mode="${11}" fault=ready
  printf '%s\n' "$mode" >>"$PROBE_SITE/trace"
  case "$PROBE_CASE:$mode" in baseline-*:snapshot|capture-*:capture) fault="${PROBE_CASE#*-}" ;; esac
  [ "$fault" != nonzero ] || return 7
  [ "$fault" != warning ] || printf 'PHP Warning: private-transport-canary in Unknown on line 0\n' >&2
  [ "$fault" != extra ] || printf '{}\n'
  printf ' Container wprism-%s-cli2-run-aabbcc Created \n' "$PAIR" >&2
  local payload native_status=0
  payload=$("$PHP" -r '
$program = str_replace(["/wprism-test/PrivateRefusalReceipt.php", "/siterepo/.wprism/refusals"],
    [$argv[1]."/sandbox/tests/lib/PrivateRefusalReceipt.php", $argv[2]."/.wprism/refusals"], $argv[3]);
$argv = [$argv[0], $argv[4], $argv[5], $argv[6]];
eval($program);
' "$ROOT" "$PROBE_SITE" "${10}" "$mode" "${12}" "${13-null}") || native_status=$?
  [ "$native_status" -eq 0 ] || return "$native_status"
  case "$fault" in
    object) printf '{}\n' ;;
    value) jq -c 'with_entries(.value = null)' <<<"$payload" ;;
    noncanonical) jq -c 'with_entries(.value = " [ ] ")' <<<"$payload" ;;
    foreign-name) jq -c 'with_entries(.value = "[\"20260905-095334-foreign-bbbbbbbbbbbbbbbbbbbbbbbb.json\"]")' <<<"$payload" ;;
    verified) jq -c 'with_entries(.value |= (fromjson | .verified = true | tojson))' <<<"$payload" ;;
    *) printf '%s' "$payload" ;;
  esac
}
diagnostic_record() {
  "$PHP" -r '
class WP_CLI { public static function add_command($name, $class): void {} }
require $argv[1]."/agent/src/Command/Cli.php";
$command = $argv[4];
$witness = (new ReflectionMethod(\WPrism\Cli::class, "refusal_evidence_repository"))->invoke(null, $argv[2], $command);
if (!is_array($witness)) exit(83);
$count = $argv[3] === "too-many" ? 5 : 1;
for ($i=0; $i<$count; ++$i) {
    \WPrism\PrivateRefusalEvidence::record($argv[2], $witness,
        new \WPrism\PrivateEvidenceException("public lifecycle boundary", new RuntimeException("private-retry-cause-canary")),
        $command, $command === "apply" ? "apply_failed" : "lifecycle_status_failed");
}
if ($argv[3] === "record-mode") {
    foreach (glob($argv[2]."/.wprism/refusals/*") ?: [] as $path) chmod($path, 0644);
}
' "$ROOT" "$PROBE_SITE" "$PROBE_CASE" "$1"
}
wprism_host_call() {
  [ "$#" -eq 5 ] && [ "$1" = "$ROOT/cli/wprism" ] && [ "$2" = fixture-registry ] \
    && [ "$3" = "wprism-$PAIR" ] && [ "$4" = "${PAIR}2" ] && [ "$5" = deploy ] || return 82
  printf 'deploy\n' >>"$PROBE_SITE/trace"
  # Host-created readable fixtures must not inherit the diagnostic's 077 mask.
  : >"$PROBE_SITE/host-file"
  if [ "$PROBE_CASE" != ready ]; then diagnostic_record lifecycle-status || return "$?"; fi
  printf 'deploy phase: compile\ndeploy phase: lifecycle-status\n'
  printf 'public lifecycle transport\n' >&2
  if [ "$PROBE_CASE" = warning ]; then printf 'PHP Warning: public-command-warning in Unknown on line 0\n' >&2; fi
  case "$PROBE_CASE" in refused|too-many|record-mode) return 7 ;; esac
}
SH;
$diagnosticCommands = ['lifecycle-status', 'schema-status', 'promotion-begin', 'checkpoint', 'provider-settlement-begin',
    'lifecycle-retire', 'lifecycle-activate', 'schema-settle', 'lifecycle-settle', 'provider-settlement-complete', 'apply'];
foreach (['deploy', 'apply'] as $operation) {
$diagnosticCommand = $operation === 'apply' ? 'apply' : 'lifecycle-status';
foreach (['ready', 'refused', 'warning', 'baseline-nonzero', 'baseline-warning', 'baseline-extra',
    'capture-nonzero', 'capture-warning', 'capture-extra', 'too-many', 'record-mode',
    'baseline-object', 'baseline-value', 'baseline-noncanonical', 'baseline-foreign-name',
    'capture-object', 'capture-value', 'capture-verified'] as $case) {
    $directory = $diagnosticScratch . '/' . $operation . '-' . $case;
    mkdir($directory, 0700);
    file_put_contents($directory . '/site.wprism.json', "{}\n");
    $pair = 'rmdiag' . bin2hex(random_bytes(5));
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run($diagnosticProbe . "\n"
        . $scenarioAssertionDefinitions . "\n" . $diagnosticDefinitions . "\nREVISION=fixture\n"
        . ($operation === 'apply' ? $nativeTransportDefinitions . "\n" . $initialDiagnosticCaller : $diagnosticCaller)
        . "\nprintf 'DIAGNOSTIC_CALLER_ACCEPTED\\n'\n", [$root, $case, $directory, PHP_BINARY, $pair], $root);
    $sinks = glob($root . '/sandbox/tmp/wprism-rmcombo-' . $operation . '.' . $pair . '.*') ?: [];
    $sink = count($sinks) === 1 ? $sinks[0] : '';
    $files = $sink !== '' ? glob($sink . '/*') ?: [] : [];
    wprism_check(($case === 'ready' ? $status === 0 && str_contains($stdout, 'DIAGNOSTIC_CALLER_ACCEPTED')
            : $status !== 0 && !str_contains($stdout, 'DIAGNOSTIC_CALLER_ACCEPTED'))
        && !str_contains($stdout . $stderr, 'private-retry-cause-canary')
        && !str_contains($stdout . $stderr, 'private-transport-canary'),
        "actual $operation transport/caller $case refuses diagnostic failures without publishing private material");
    wprism_check(count($files) === 15 && (fileperms($sink) & 0777) === 0700
        && count(array_filter($files, static fn(string $file): bool => !is_link($file) && is_file($file) && (fileperms($file) & 0777) === 0600)) === 15,
        "$case retains all complete private transports/statuses outside disposable site ownership");
    $invoked = !str_starts_with($case, 'baseline-');
    $trace = is_file($directory . '/trace') ? trim((string) file_get_contents($directory . '/trace')) : '';
    wprism_check_same($invoked ? "snapshot\n$operation\ncapture" : 'snapshot', $trace,
        "$operation $case retains private evidence before caller acceptance and never mutates after a broken baseline");
    if ($sink !== '') {
        wprism_check_same($case === 'baseline-nonzero' ? "7\n" : "0\n", file_get_contents($sink . '/baseline.exit'),
            "$case retains the exact baseline transport status before validating its answer");
        if ($invoked) {
            $readerStatus = $case === 'capture-nonzero' ? 7 : (in_array($case, ['too-many', 'record-mode'], true) ? 255 : 0);
            wprism_check_same($readerStatus . "\n", file_get_contents($sink . '/private.exit'),
                "$case retains the exact private-reader transport status before validating its answer");
        }
        if (in_array($case, ['baseline-warning', 'capture-warning'], true)) {
            $stem = str_starts_with($case, 'baseline-') ? 'baseline' : 'private';
            wprism_check(str_contains((string) file_get_contents($sink . '/' . $stem . '.stderr'), 'private-transport-canary'),
                "$case keeps the whole native reader diagnostic privately before rejecting it");
        }
    }
    if ($invoked && $sink !== '') {
        wprism_check_same(in_array($case, ['refused', 'too-many', 'record-mode'], true) ? "7\n" : "0\n",
            is_file($sink . '/command.exit') ? file_get_contents($sink . '/command.exit') : null,
            "$operation $case preserves the exact original transport status");
        wprism_check((fileperms($directory . '/host-file') & 0044) === 0044,
            "$case diagnostic allocation does not change host publication readability");
    }
    if (in_array($case, ['ready', 'refused', 'warning'], true) && $sink !== '') {
        $captured = json_decode((string) file_get_contents($sink . '/private.stdout'), true, flags: JSON_THROW_ON_ERROR);
        wprism_check_same($diagnosticCommands, array_keys($captured), "$case captures the complete fixed host phase inventory");
        $receipt = json_decode($captured[$diagnosticCommand] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        wprism_check(($receipt['verified'] ?? null) === false && ($receipt['purpose'] ?? null) === 'diagnostic_only'
            && ($receipt['new_records'] ?? null) === ($case === 'ready' ? 0 : 1),
            "$case shared bounded retention never claims exact-cause verification");
        if ($case !== 'ready') {
            $record = $receipt['records'][0] ?? [];
            $bytes = base64_decode($record['contents_base64'] ?? '', true);
            wprism_check(is_string($bytes) && hash('sha256', $bytes) === ($record['sha256'] ?? null)
                && strlen($bytes) === ($record['bytes'] ?? null) && str_contains($bytes, 'private-retry-cause-canary'),
                "$case keeps byte-exact real engine private evidence before teardown");
        }
    }
    foreach ($files as $file) unlink($file);
    if ($sink !== '') rmdir($sink);
    foreach (glob($directory . '/.wprism/refusals/*') ?: [] as $file) unlink($file);
    if (is_dir($directory . '/.wprism/refusals')) rmdir($directory . '/.wprism/refusals');
    if (is_dir($directory . '/.wprism')) rmdir($directory . '/.wprism');
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
}
rmdir($diagnosticScratch);

$settledOrderReadback = strpos($live, 'HOST_SETTLED_ORDER=$(active_plugin_order wp2)');
$initialApply = strpos($live, 'capture_wprism_json_checked INITIAL');
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
$cleanDeployEndToken = "pass 'host deploy refuses hostile schema, then checkpoint-settles legitimate lifecycle/schema drift without crossing combination boundaries'";
$cleanDeployStart = strpos($diagnosticSource, 'CLEAN_DEPLOY=$(host_wprism_combo wp2 deploy');
$cleanDeployEnd = $cleanDeployStart === false ? false : strpos($diagnosticSource, $cleanDeployEndToken, $cleanDeployStart);
$cleanDeployBlock = $cleanDeployStart === false || $cleanDeployEnd === false
    ? ''
    : substr($diagnosticSource, $cleanDeployStart, $cleanDeployEnd + strlen($cleanDeployEndToken) - $cleanDeployStart);
wprism_check($cleanDeployBlock !== '', 'the complete clean host-settlement acceptance block is extractable');
// Native observation shape, not a fake "stable" field. The actual package
// provider regression independently proves disabled-module [0,0,0] link,
// count and marker cardinalities while preserving unrelated post metadata.
// Here the real caller must accept precisely that postimage and nothing else.
$hostileProduct = [
    'acf' => 'target badge', 'canonical' => '', 'content' => '', 'description' => '',
    'id' => 901, 'language' => 'en', 'links' => [['url' => '/target-stale', 'target_post_id' => '904', 'type' => 'internal']],
    'lookup' => ['min_price' => '81.0000', 'max_price' => '81.0000', 'stock_status' => 'instock'],
    'price' => '81', 'primary' => 921, 'processed' => true,
    'rank_counts' => ['internal_link_count' => '999', 'external_link_count' => '999', 'incoming_link_count' => '999'],
    'title' => 'target SEO', 'url' => 'http://target.invalid/product/en/',
];
$hostilePostimage = [
    'book' => ['content' => 'target content', 'id' => 903, 'links' => $hostileProduct['links'],
        'processed' => true, 'rank_counts' => $hostileProduct['rank_counts'], 'title' => 'target book SEO'],
    'categories' => ['en' => 921, 'de' => 922], 'category_languages' => ['en' => 'en', 'de' => 'de'],
    'modules' => ['redirections', 'rich-snippet'],
    'neighbor' => ['acf' => 'target-only badge', 'id' => 904, 'price' => '97', 'title' => 'target-only SEO'],
    'products' => ['en' => $hostileProduct, 'de' => array_replace($hostileProduct, ['id' => 902, 'language' => 'de', 'primary' => 922])],
    'redirection' => ['header_code' => 301, 'hits' => 41, 'id' => 1,
        'sources' => [['ignore' => '', 'pattern' => 'rmcombo-old', 'comparison' => 'exact']],
        'status' => 'inactive', 'url_to' => 'http://target.invalid/target-stale/'],
    'redirection_cache' => [['from_url' => 'rmcombo-old', 'redirection_id' => '1', 'object_id' => '999999999', 'object_type' => 'post', 'is_redirected' => '0']],
    'retired_target_counts' => ['internal_link_count' => '0', 'external_link_count' => '0', 'incoming_link_count' => '999'],
    'scheduler' => [['action_id' => '6', 'hook' => 'rmcombo_target_runtime', 'status' => 'pending', 'group_slug' => '']],
    'stale_link_sentinels' => 3, 'term_translations' => ['en' => 921, 'de' => 922],
    'translations' => ['en' => 901, 'de' => 902],
];
$settledPostimage = $hostilePostimage;
$settledPostimage['redirection_cache'] = [];
foreach (['en', 'de'] as $language) {
    $settledPostimage['products'][$language]['links'] = [];
    $settledPostimage['products'][$language]['rank_counts'] = null;
    $settledPostimage['products'][$language]['processed'] = false;
}
$settledPostimage['book']['links'] = [];
$settledPostimage['book']['rank_counts'] = null;
$settledPostimage['book']['processed'] = false;
$settledPostimage['retired_target_counts'] = null;
$settledPostimage['stale_link_sentinels'] = 0;
$cleanDeployProbe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_CASE="$2" R2="$3" HOSTILE_NATIVE="$4" SETTLED_NATIVE="$5" PAIR=rmcomboclean
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'HOST_SETTLEMENT_ACCEPTED\n'; }
. "$ROOT/sandbox/conformance/asserts.sh"
expected_source='["polylang","advanced-custom-fields","seo-by-rank-math","woocommerce"]'
TARGET_DEFAULT_FIXTURE='{"installer_default":41,"option":41,"term_id":42}'
host_wprism_combo() {
  [ "$#" -eq 2 ] && [ "$1" = wp2 ] && [ "$2" = deploy ] || return 81
  case "$PROBE_CASE" in
    php-stdout) printf 'PHP Warning: fixture diagnostic in /fixture.php on line 1\n' ;;
    php-stderr) printf 'PHP Warning: fixture diagnostic in /fixture.php on line 1\n' >&2 ;;
    startup-stdout) printf 'PHP Warning: PHP Startup: fixture diagnostic in Unknown on line 0\n' ;;
    startup-stderr) printf 'PHP Warning: PHP Startup: fixture diagnostic in Unknown on line 0\n' >&2 ;;
    parse-stdout) printf 'PHP Parse error: fixture diagnostic\n' ;;
    parse-stderr) printf 'PHP Parse error: fixture diagnostic\n' >&2 ;;
  esac
  local phase
  for phase in compile lifecycle-status schema-status promotion-begin checkpoint \
    provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle \
    lifecycle-settle provider-settlement-complete; do
    printf 'deploy phase: %s\n' "$phase"
  done
}
wp2() {
  if [ "$*" = 'plugin get seo-by-rank-math --fields=name,status,version --format=json' ]; then
    printf '%s\n' '{"name":"seo-by-rank-math","status":"active","version":"1.0.277.2"}'
    return 0
  fi
  if [ "$#" -eq 2 ] && [ "$1" = eval ]; then
    printf '%s\n' '{"table":"wp_rank_math_redirections_cache","present":true}'
    return 0
  fi
  if [ "$#" -eq 3 ] && [ "$1" = plugin ] && [ "$2" = is-active ] \
    && [ "$3" = seo-by-rank-math ]; then
    return 0
  fi
  if [ "$#" -eq 4 ] && [ "$1" = db ] && [ "$2" = query ] \
    && [ "$3" = "SHOW TABLES LIKE 'wp_rank_math_redirections_cache'" ] \
    && [ "$4" = --skip-column-names ]; then
    printf 'wp_rank_math_redirections_cache\n'
    return 0
  fi
  return 82
}
active_plugin_order() { printf '%s\n' "$expected_source"; }
native_state() { printf '%s\n' "$SETTLED_NATIVE"; }
default_product_category_state() {
  printf '%s\n' '{"option":41,"term":{"term_id":41,"slug":"fixture"},"term_taxonomy":{"term_taxonomy_id":41,"term_id":41,"taxonomy":"product_cat"}}'
}
SH;
$cleanDeployScratch = sys_get_temp_dir() . '/wprism-rmcombo-clean-deploy-' . bin2hex(random_bytes(8));
if (!mkdir($cleanDeployScratch, 0700)) {
    throw new RuntimeException('could not allocate clean host-settlement scratch');
}
foreach (['ready', 'php-stdout', 'php-stderr', 'startup-stdout', 'startup-stderr', 'parse-stdout', 'parse-stderr'] as $case) {
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
        $cleanDeployProbe . "\n" . $scenarioAssertionDefinitions . "\n" . $cleanDeployBlock . "\nprintf 'HOST_SETTLEMENT_READY\\n'\n",
        [$root, $case, $cleanDeployScratch, json_encode($hostilePostimage, JSON_THROW_ON_ERROR), json_encode($settledPostimage, JSON_THROW_ON_ERROR)],
        $root
    );
    wprism_check(
        $case === 'ready'
            ? $status === 0
                && $stdout === "HOST_SETTLEMENT_ACCEPTED\nHOST_SETTLEMENT_READY\n"
                && $stderr === ''
            : $status !== 0
                && !str_contains($stdout, 'HOST_SETTLEMENT_ACCEPTED')
                && !str_contains($stdout, 'HOST_SETTLEMENT_READY')
                && str_contains($stderr, 'clean Rank Math combination host deploy emitted a PHP runtime diagnostic')
                && !str_contains($stdout . $stderr, 'fixture diagnostic'),
        "actual clean host-settlement block classifies $case before accepting phases and native state"
    );
}
// Mutate every observed terminal leaf, including empty/null derived fields:
// filtering whole products or Rank-prefixed fields would admit these drifts.
$postimageMutations = ['stale-derived-state' => array_replace($hostilePostimage, ['redirection_cache' => []])];
$mutatePostimage = static function (array $node, array $path = []) use (&$mutatePostimage, &$postimageMutations, $settledPostimage): void {
    foreach ($node as $key => $value) {
        $next = [...$path, $key];
        if (is_array($value) && $value !== []) {
            $mutatePostimage($value, $next);
            continue;
        }
        $candidate = $settledPostimage;
        $leaf = &$candidate;
        foreach ($next as $part) $leaf = &$leaf[$part];
        $leaf = is_bool($value) ? !$value : ($value === null ? [] : 'postimage-drift-canary');
        unset($leaf);
        $postimageMutations[implode('.', $next)] = $candidate;
    }
};
$mutatePostimage($settledPostimage);
foreach ($postimageMutations as $case => $candidate) {
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
        $cleanDeployProbe . "\n" . $scenarioAssertionDefinitions . "\n" . $cleanDeployBlock,
        [$root, 'ready', $cleanDeployScratch, json_encode($hostilePostimage, JSON_THROW_ON_ERROR), json_encode($candidate, JSON_THROW_ON_ERROR)],
        $root
    );
    wprism_check($status !== 0 && !str_contains($stdout, 'HOST_SETTLEMENT_ACCEPTED'),
        "actual clean host settlement refuses altered $case rather than masking a whole plugin/content boundary");
}

$reseedStart = $cleanDeployEnd === false ? false : strpos($diagnosticSource, "\nseed_rmcombo_stale_links\n", $cleanDeployEnd);
$reseedEnd = $reseedStart === false ? false : strpos($diagnosticSource, "\nREVISION=", $reseedStart);
wprism_check($reseedStart !== false && $reseedEnd !== false,
    'Apply re-seeds and independently observes non-vacuous stale projections after host settlement');
if ($reseedStart !== false && $reseedEnd !== false) {
    $reseedBlock = substr($diagnosticSource, $reseedStart, $reseedEnd - $reseedStart);
    $reseedProbe = <<<'SH'
set -euo pipefail
HOSTILE_NATIVE="$1" RESEEDED_NATIVE="$2" PROBE_CASE="$3"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
seed_rmcombo_stale_links() { printf 'RESEEDED\n'; [ "$PROBE_CASE" != seed-failed ]; }
native_state() { printf '%s\n' "$RESEEDED_NATIVE"; }
SH;
    $reseeded = array_replace($hostilePostimage, ['redirection_cache' => []]);
    foreach (['ready', 'seed-failed', 'already-clean', 'unrelated-drift'] as $case) {
        $answer = $case === 'already-clean' ? $settledPostimage : $reseeded;
        if ($case === 'unrelated-drift') $answer['neighbor']['price'] = '98';
        [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
            $reseedProbe . "\n" . $reseedBlock . "\nprintf 'APPLY_PREMISE_READY\\n'\n",
            [json_encode($hostilePostimage, JSON_THROW_ON_ERROR), json_encode($answer, JSON_THROW_ON_ERROR), $case], $root);
        wprism_check($case === 'ready' ? $status === 0 && $stdout === "RESEEDED\nAPPLY_PREMISE_READY\n" && $stderr === ''
            : $status !== 0 && !str_contains($stdout, 'APPLY_PREMISE_READY'),
            "Apply premise $case must seed successfully and retain the exact hostile graph before mutation");
    }
}

$seedStart = strpos($diagnosticSource, 'seed_rmcombo_stale_links() {');
$seedEnd = $seedStart === false ? false : strpos($diagnosticSource, "\nnative_state() {", $seedStart);
wprism_check($seedStart !== false && $seedEnd !== false, 'the reusable stale-link seed is an actual checked native command');
if ($seedStart !== false && $seedEnd !== false) {
    $seedDefinition = substr($diagnosticSource, $seedStart, $seedEnd - $seedStart);
    $seedProbe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_CASE="$2" PHP="$3" WITNESS="$4" PAIR=rmcomboseed
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
wp2() {
  [ "$#" -eq 2 ] && [ "$1" = eval ] || return 81
  "$PHP" "$ROOT/integration-scenarios/rank-math-commerce-multilingual/fixtures/stale-link-seed-probe.php" \
    "$PROBE_CASE" "$2" "$WITNESS"
}
SH;
    foreach (['ready', 'missing-post', 'overlap', 'delete-links', 'delete-counts', 'insert-links', 'insert-counts',
        'marker', 'read-links', 'read-counts', 'short-count', 'extra', 'warning-stdout', 'warning-stderr', 'nonzero',
        'owned-compose', 'foreign-compose'] as $case) {
        $witness = $cleanDeployScratch . '/seed-witness.json';
        [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
            $seedProbe . "\n" . $scenarioAssertionDefinitions . "\n" . $seedDefinition . "\nseed_rmcombo_stale_links\nprintf 'SEED_READY\\n'\n",
            [$root, $case, PHP_BINARY, $witness], $root);
        wprism_check((in_array($case, ['ready', 'owned-compose'], true) ? $status === 0 && $stdout === "SEED_READY\n"
                && ($case === 'owned-compose' || $stderr === '')
                : $status !== 0 && !str_contains($stdout, 'SEED_READY'))
            && !str_contains($stdout . $stderr, 'private-seed-sql-canary'),
            "actual stale-link native seed classifies $case before accepting its readback");
        $observed = json_decode((string) file_get_contents($witness), true, flags: JSON_THROW_ON_ERROR);
        wprism_check(($observed['redirections'] ?? null) === [['id' => 1, 'hits' => 41]]
            && ($observed['scheduler'] ?? null) === [['action_id' => 6, 'hook' => 'target-runtime']]
            && ($observed['meta'][101]['authored'] ?? null) === 'preserved',
            "$case seed leaves authored metadata, redirects and unrelated scheduler rows exact");
        if ($case === 'ready') {
            wprism_check_same([
                ['url' => '/target-stale', 'post_id' => 101, 'target_post_id' => 104, 'type' => 'internal'],
                ['url' => '/target-stale', 'post_id' => 102, 'target_post_id' => 104, 'type' => 'internal'],
                ['url' => '/target-stale-book', 'post_id' => 103, 'target_post_id' => 104, 'type' => 'internal'],
            ], $observed['links'], 'the actual native seed binds all three stale edges to the target-only neighbor');
            wprism_check_same([101, 102, 103, 104], array_column($observed['counts'], 'object_id'),
                'the seed has four distinct count witnesses, including the retired target');
            wprism_check_same(['1', '1', '1'], array_map(static fn(int $id): mixed =>
                $observed['meta'][$id]['rank_math_internal_links_processed'] ?? null, [101, 102, 103]),
                'both lifecycle and Apply begin with non-vacuous processed markers');
        } elseif (in_array($case, ['missing-post', 'overlap'], true)) {
            wprism_check_same([], $observed['queries'], "$case refuses before the first destructive fixture write");
        }
        unlink($witness);
    }
}
rmdir($cleanDeployScratch);

// Execute the real drift-to-settlement window, including its preimage. The
// earlier clean-deploy probe began after the nonexistent is-inactive call and
// therefore could not detect the live 65d92 failure. Only native transport is
// simulated; the table observation runs its actual PHP against shared SQL.
$hostPremiseSource = isset($argv[1]) ? file_get_contents($argv[1]
    . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh') : $live;
$hostPremiseStart = strrpos($hostPremiseSource, 'wp2 plugin deactivate seo-by-rank-math');
$hostPremiseEnd = $hostPremiseStart === false ? false : strpos($hostPremiseSource,
    '[ ! -e "$R2/.wprism/control/provider-settlement-intent.json" ]', $hostPremiseStart);
$hostDefinitionsStart = strpos($hostPremiseSource, 'assert_rmcombo_warning_free_capture() {');
$hostDefinitionsEnd = $hostDefinitionsStart === false ? false : strpos($hostPremiseSource, "\nfor command in docker", $hostDefinitionsStart);
if ($hostPremiseStart === false || $hostPremiseEnd === false || $hostDefinitionsStart === false || $hostDefinitionsEnd === false) {
    throw new LogicException('the complete real host lifecycle/schema premise is unavailable');
}
$hostPremiseBlock = substr($hostPremiseSource, $hostPremiseStart, $hostPremiseEnd - $hostPremiseStart);
$hostDefinitions = substr($hostPremiseSource, $hostDefinitionsStart, $hostDefinitionsEnd - $hostDefinitionsStart);
$hostPremiseProbe = <<<'SH'
set -euo pipefail
ROOT="$1" PROBE_CASE="$2" PROBE_PHASE="$3" PHP="$4" PAIR=rmcomboprobe
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
probe_scratch=$(mktemp -d "${TMPDIR:-/tmp}/rmcombo-host-premise.XXXXXX")
trap '[ ! -f "$probe_scratch/deployed" ] || printf "DEPLOYED\n"; rm -rf -- "$probe_scratch"' EXIT
host_wprism_combo() {
  [ "$*" = 'wp2 deploy' ] || return 81
  : >"$probe_scratch/deployed"
  local phase
  for phase in compile lifecycle-status schema-status promotion-begin checkpoint \
    provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle \
    lifecycle-settle provider-settlement-complete; do printf 'deploy phase: %s\n' "$phase"; done
}
wp1() { return 82; }
wp2() {
  local phase=before fault=ready status=inactive
  if [ -f "$probe_scratch/deployed" ]; then phase=after; status=active; fi
  [ "$phase" != "$PROBE_PHASE" ] || fault="$PROBE_CASE"
  case "$*" in
    'plugin deactivate seo-by-rank-math'|'db query DROP TABLE wp_rank_math_redirections_cache') return 0 ;;
    'plugin is-inactive seo-by-rank-math') printf 'Error: is-inactive is not a registered subcommand\n' >&2; return 1 ;;
    'plugin is-active seo-by-rank-math') [ "$phase" = after ]; return ;;
    "db query SHOW TABLES LIKE 'wp_rank_math_redirections_cache' --skip-column-names")
      [ "$phase" != after ] || printf 'wp_rank_math_redirections_cache\n'; return 0 ;;
    'plugin get seo-by-rank-math --fields=name,status,version --format=json')
      local version=1.0.277.2 name=seo-by-rank-math
      case "$fault" in
        owned-compose) printf ' Container wprism-rmcomboprobe-cli2-run-aabbcc Created \n' >&2 ;;
        wrong-site-chatter) printf ' Container wprism-rmcomboprobe-cli1-run-aabbcc Created \n' >&2 ;;
        wrong-pair-chatter) printf ' Container wprism-rmcomboforeign-cli2-run-aabbcc Created \n' >&2 ;;
        plugin-nonzero) return 7 ;;
        plugin-empty) return 0 ;;
        plugin-wrong-status) if [ "$status" = active ]; then status=inactive; else status=active; fi ;;
        plugin-wrong-version) version=1.0.276 ;;
        plugin-wrong-name) name=wrong-plugin ;;
        plugin-stdout) printf 'PHP Warning: private-host-premise-canary in Unknown on line 0\n' ;;
        plugin-stderr) printf 'PHP Warning: private-host-premise-canary in Unknown on line 0\n' >&2 ;;
        plugin-extra) printf '{}\n' ;;
      esac
      jq -nc --arg name "$name" --arg status "$status" --arg version "$version" '{name:$name,status:$status,version:$version}'
      ;;
    *)
      [ "$#" -eq 2 ] && [ "$1" = eval ] || return 83
      case "$fault" in
        table-nonzero) return 7 ;;
        table-empty) return 0 ;;
        table-stdout) printf 'PHP Warning: private-host-premise-canary in Unknown on line 0\n' ;;
        table-stderr) printf 'PHP Warning: private-host-premise-canary in Unknown on line 0\n' >&2 ;;
        table-extra) printf '{}\n' ;;
      esac
      "$PHP" -r '
require $argv[1]."/sandbox/tests/lib/FakeWpdb.php";
$fault=$argv[2]; $present=$argv[4]==="after";
$wpdb=\WPrismTest\FakeWpdb::install($fault==="table-wrong-prefix" ? "foreign_" : "wp_");
$initialSuppression=!$present; $wpdb->suppress_errors($initialSuppression);
if ($fault==="table-wrong-state") $present=!$present;
if ($present) $wpdb->seedTable($wpdb->prefix."rank_math_redirections_cache",[]);
// A wildcard near-match must not turn an absent exact table into presence.
$wpdb->seedTable("wpXrankYmathZredirectionsWcache",[]);
if ($fault==="table-read-error") $wpdb->failNextQuery("private-host-premise-canary","SHOW TABLES LIKE");
ob_start();
try {
    eval($argv[3]); $answer=ob_get_clean();
    if (count($wpdb->queries())!==1 || $wpdb->suppress_errors($initialSuppression)!==$initialSuppression) exit(84);
    echo $answer;
} catch (Throwable $failure) {
    ob_end_clean(); fwrite(STDERR,"private-host-premise-canary\n"); exit(7);
}' "$ROOT" "$fault" "$2" "$phase"
      ;;
  esac
}
SH;
foreach (['before', 'after'] as $phase) {
    foreach (['ready', 'owned-compose', 'wrong-site-chatter', 'wrong-pair-chatter', 'plugin-nonzero', 'plugin-empty', 'plugin-wrong-status', 'plugin-wrong-version',
        'plugin-wrong-name', 'plugin-stdout', 'plugin-stderr', 'plugin-extra', 'table-nonzero', 'table-empty',
        'table-stdout', 'table-stderr', 'table-extra', 'table-wrong-state', 'table-read-error', 'table-wrong-prefix'] as $case) {
        [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run($hostPremiseProbe . "\n" . $hostDefinitions . "\n"
            . $hostPremiseBlock . "\nprintf 'HOST_PREMISE_ACCEPTED\\n'\n", [$root, $case, $phase, PHP_BINARY], $root);
        $ready = in_array($case, ['ready', 'owned-compose'], true);
        wprism_check($ready ? $status === 0 && str_contains($stdout, 'HOST_PREMISE_ACCEPTED')
                && ($case === 'owned-compose' ? trim($stderr) === 'Container wprism-rmcomboprobe-cli2-run-aabbcc Created' : $stderr === '')
            : $status !== 0 && !str_contains($stdout, 'HOST_PREMISE_ACCEPTED'),
            "$phase $case requires checked exact native status and table presence through the real host window");
        wprism_check_same($ready || $phase === 'after', str_contains($stdout, 'DEPLOYED'),
            "$phase $case cannot reach host deploy without its complete inactive/absent preimage");
    }
}
$sourceBinding = strpos($live, <<<'SH'
establish_core_environment_bindings wp1 /siterepo admin@example.test \
  "http://${PAIR}1.invalid" "http://${PAIR}1.invalid"
SH);
$targetBinding = strpos($live, <<<'SH'
establish_core_environment_bindings wp2 /siterepo admin@example.test \
  "http://${PAIR}2.invalid" "http://${PAIR}2.invalid"
SH);
$firstCapture = strpos($live, "capture_wprism_json_checked SOURCE_CAPTURE 'Rank Math combination source capture'");
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
    [
        'INITIAL',
        'Rank Math commerce/multilingual initial apply',
        'assert_rmcombo_default_apply_ready',
        'TARGET=$(native_state wp2)',
    ],
    [
        'RETRY',
        'Rank Math combination provider retry',
        'assert_rmcombo_default_apply_ready',
        'RETRY_NATIVE=$(native_state wp2)',
    ],
    [
        'NOOP',
        'Rank Math combination no-op apply',
        'assert_rmcombo_default_apply_ready',
        'wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-final',
    ],
] as [$answer, $label, $callback, $observation]) {
    $checkedCapture = strpos(
        $live,
        "capture_wprism_json_checked $answer '$label' $callback "
    );
    // Keep the old shape executable as a counterfactual: this regression must
    // demonstrate why publishing the last JSON line before readiness was wrong.
    $legacyCapture = strpos($live, "capture_wprism_json_success $answer '$label' ");
    $captureStart = $checkedCapture !== false ? $checkedCapture : $legacyCapture;
    $receipt = $captureStart === false ? false : strpos($live, "\njq -e '", $captureStart);
    $observe = $captureStart === false ? false : strpos($live, $observation, $captureStart);
    wprism_check(
        $checkedCapture !== false
            && $legacyCapture === false
            && $receipt !== false
            && $observe !== false
            && $checkedCapture < $receipt
            && $receipt < $observe
            && !str_contains($live, "\nassert_wprism_apply_ready '$label' \"\$$answer\"")
            && !str_contains($live, "$answer=\$(wp2 wprism apply"),
        "$answer checks the complete public stream before publishing verified apply evidence to its native oracle"
    );
    $block = $captureStart === false || $receipt === false ? '' : substr(
        $live,
        $captureStart,
        $receipt - $captureStart
    );
    $applyCases = ['ready', 'missing', 'missing-stderr', 'refusal', 'diagnostic', 'startup', 'parse'];
    $applyCases[] = 'default-warning';
    foreach ($applyCases as $case) {
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
  if [ "$PROBE_CASE" = missing-stderr ]; then
    printf 'Warning: env_missing: option home is required\n' >&2
  fi
  if [ "$PROBE_CASE" = default-warning ]; then
    printf 'Warning: option default_product_cat: unmanaged term id 41 — key skipped\n' >&2
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
            ['bash', '-c', $script . "\n" . $scenarioAssertionDefinitions . "\n" . $block
                . "\nprintf 'APPLY_READY\\n'\n", 'combo-apply-probe',
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
                'missing', 'missing-stderr' => $status !== 0 && !str_contains($stdout, 'APPLY_READY')
                    && str_contains($stderr, 'did not prove all required environment bindings'),
                'refusal' => $status !== 0 && !str_contains($stdout, 'APPLY_READY')
                    && str_contains($stderr, 'fixture_refusal') && str_contains($stderr, 'failed with exit 7'),
                'diagnostic', 'startup', 'parse' => $status !== 0 && !str_contains($stdout, 'APPLY_READY')
                    && str_contains($stderr, 'fixture diagnostic')
                    && str_contains($stderr, 'emitted a PHP runtime diagnostic'),
                'default-warning' => $status !== 0 && !str_contains($stdout, 'APPLY_READY')
                    && str_contains($stderr, 'did not settle the portable Woo default'),
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
$sshWooInstall = strpos($sshDeletion, 'wprism_ssh_install_certified_plugin "$adapter" "$expected_version"');
$sshWooTerms = strpos($sshDeletion, 'WC_Install::create_terms();');
$sshBaselineCapture = strpos($sshDeletion, 'capture target --target-branch="$TARGET_REPOSITORY_BRANCH"');
wprism_check(
    !str_contains($sshDeletion, 'AUTO_INCREMENT')
        && $sshWooInstall !== false
        && $sshWooTerms !== false
        && $sshBaselineCapture !== false
        && $sshWooInstall < $sshWooTerms
        && $sshWooTerms < $sshBaselineCapture,
    'the SSH extension keeps Woo native fresh-install coordinates intact and lets public capture enforce the intersection'
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
$postimageObservation = strpos($sshDeletion, 'capture_rmcombo_ssh_json after_json native-postimage');
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
preg_match('/^capture_rmcombo_ssh_json\(\).*?^\}/ms', $sshDeletion, $sshJsonCaptureDefinition);
wprism_check(
    isset($sshDiagnosticDefinition[0], $sshJsonCaptureDefinition[0]),
    'the SSH observation transport and its parent-owned private diagnostic classifier are extractable'
);
$sshJsonProbe = <<<'SH'
set -euo pipefail
ROOT="$1" DIAG_DIR="$2" PROBE_CASE="$3"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
umask 000
fixture_json_command() {
  case "$PROBE_CASE" in
    ready-object|duplicate) printf '%s\n' '{"ok":true}' ;;
    ready-array) printf '%s\n' '[{"ok":true}]' ;;
    php-stdout)
      printf 'PHP Warning: private-observation-canary in /fixture.php on line 1\n'
      printf '%s\n' '{"ok":true}'
      ;;
    php-stderr)
      printf 'PHP Warning: private-observation-canary in /fixture.php on line 1\n' >&2
      printf '%s\n' '{"ok":true}'
      ;;
    startup-stdout)
      printf 'PHP Warning: PHP Startup: private-observation-canary in Unknown on line 0\n'
      printf '%s\n' '{"ok":true}'
      ;;
    parse-stderr)
      printf 'PHP Parse error: private-observation-canary\n' >&2
      printf '%s\n' '{"ok":true}'
      ;;
    nonzero)
      printf '%s\n' '{"private":"private-observation-canary"}'
      return 7
      ;;
    malformed) printf '%s\n' 'private-observation-canary' ;;
    multiple) printf '%s\n' '{"ok":true}' '{"private":"private-observation-canary"}' ;;
    scalar) printf '%s\n' '"private-observation-canary"' ;;
    missing) ;;
    *) return 81 ;;
  esac
}
SH;
foreach (['ready-object', 'ready-array', 'php-stdout', 'php-stderr', 'startup-stdout', 'parse-stderr', 'nonzero', 'malformed', 'multiple', 'scalar', 'missing', 'duplicate'] as $case) {
    $scratch = sys_get_temp_dir() . '/wprism-rmcombo-ssh-observation-' . bin2hex(random_bytes(8));
    if (!mkdir($scratch, 0700)) {
        throw new RuntimeException('could not allocate SSH observation scratch');
    }
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
        $sshJsonProbe . "\n" . ($sshDiagnosticDefinition[0] ?? '')
            . "\n" . ($sshJsonCaptureDefinition[0] ?? '') . <<<'SH'

OBSERVED=''
capture_rmcombo_ssh_json OBSERVED fixture-observation \
  'combined SSH fixture observation' fixture_json_command
if [ "$PROBE_CASE" = duplicate ]; then
  capture_rmcombo_ssh_json OBSERVED fixture-observation \
    'combined SSH duplicate fixture observation' fixture_json_command
fi
jq -e '(type == "object" or type == "array")' <<<"$OBSERVED" >/dev/null
printf 'SSH_OBSERVATION_READY\n'
SH,
        [$root, $scratch, $case],
        $root
    );
    $captureDirectory = $scratch . '/rank-math-commerce-multilingual-fixture-observation';
    $captureFiles = is_dir($captureDirectory) ? array_values(array_diff(scandir($captureDirectory) ?: [], ['.', '..'])) : [];
    $privateModes = is_dir($captureDirectory)
        && !is_link($captureDirectory)
        && (fileperms($captureDirectory) & 0777) === 0700
        && $captureFiles === ['exit', 'stderr', 'stdout'];
    foreach ($captureFiles as $captureFile) {
        $path = $captureDirectory . '/' . $captureFile;
        $privateModes = $privateModes
            && is_file($path)
            && !is_link($path)
            && (fileperms($path) & 0777) === 0600;
    }
    $privateModes = $privateModes
        && trim((string) file_get_contents($captureDirectory . '/exit')) === ($case === 'nonzero' ? '7' : '0');
    $expectedFailure = match ($case) {
        'php-stdout', 'php-stderr', 'startup-stdout', 'parse-stderr' => 'emitted a PHP runtime diagnostic',
        'nonzero' => 'failed; inspect its private diagnostic capture',
        'malformed', 'multiple', 'scalar', 'missing' => 'did not return one JSON object or array',
        default => 'reused its private diagnostic label',
    };
    wprism_check(
        (in_array($case, ['ready-object', 'ready-array'], true)
            ? $status === 0 && $stdout === "SSH_OBSERVATION_READY\n" && $stderr === ''
            : $status !== 0
                && !str_contains($stdout, 'SSH_OBSERVATION_READY')
                && str_contains($stderr, $expectedFailure)
                && !str_contains($stdout . $stderr, 'private-observation-canary'))
            && $privateModes,
        "the actual private SSH JSON transport classifies $case, preserves modes and publishes no failed value"
    );
    foreach ($captureFiles as $captureFile) {
        unlink($captureDirectory . '/' . $captureFile);
    }
    rmdir($captureDirectory);
    rmdir($scratch);
}

// Host capture intentionally publishes a count, not private native warnings
// (CaptureCommand::receipt). Exercise the real baseline block before it can
// publish or commit state: a clean transport is necessary but not sufficient.
$sshBaselineStart = strpos($sshDeletion, '  capture_rmcombo_ssh_json baseline_capture_json baseline-capture');
$sshBaselineEnd = $sshBaselineStart === false ? false : strpos($sshDeletion, "  ssh_fixture '", $sshBaselineStart);
$sshBaselineBlock = $sshBaselineStart === false || $sshBaselineEnd === false ? ''
    : substr($sshDeletion, $sshBaselineStart, $sshBaselineEnd - $sshBaselineStart);
wprism_check($sshBaselineBlock !== '', 'the SSH host baseline capture and pre-publication receipt acceptance are executable');
$sshBaselineReceipt = [
    'branch' => 'fixture/private-baseline-canary',
    'capture' => [
        'counts' => ['post' => 2, 'option' => 3],
        'media_count' => 1,
        'notes_count' => 2,
        'state_revision' => str_repeat('a', 64),
        'warnings_count' => 0,
    ],
    'environment' => 'target',
    'format' => 'wprism-capture-result/v1',
    'next_action' => 'review_and_commit',
];
$sshBaselineReceipt['receipt_sha256'] = 'sha256:' . hash('sha256', Canon::encode($sshBaselineReceipt));
$sshBaselineCases = [
    'ready' => $sshBaselineReceipt,
    'command-nonzero' => $sshBaselineReceipt,
    'php-stdout' => $sshBaselineReceipt,
    'php-stderr' => $sshBaselineReceipt,
];
foreach ([
    ['warnings-positive', ['capture', 'warnings_count'], 1],
    ['warnings-missing', ['capture', 'warnings_count'], null, true],
    ['warnings-null', ['capture', 'warnings_count'], null],
    ['warnings-string', ['capture', 'warnings_count'], '0'],
    ['warnings-boolean', ['capture', 'warnings_count'], false],
    ['warnings-array', ['capture', 'warnings_count'], []],
    ['warnings-object', ['capture', 'warnings_count'], new stdClass()],
    ['warnings-negative', ['capture', 'warnings_count'], -1],
    ['warnings-fractional', ['capture', 'warnings_count'], 0.5],
    ['format-missing', ['format'], null, true],
    ['format-unsupported', ['format'], 'wprism-capture-result/v2'],
    ['environment-unbound', ['environment'], 'source'],
    ['branch-unbound', ['branch'], 'other/private-baseline-canary'],
    ['action-unrelated', ['next_action'], 'unrelated'],
    ['root-extra', ['extra'], 'private-baseline-canary'],
    ['capture-missing', ['capture'], null, true],
    ['capture-extra', ['capture', 'extra'], 'private-baseline-canary'],
    ['counts-missing', ['capture', 'counts'], null, true],
    ['counts-array', ['capture', 'counts'], []],
    ['counts-empty', ['capture', 'counts'], new stdClass()],
    ['counts-key', ['capture', 'counts'], ['INVALID' => 1]],
    ['counts-overflow', ['capture', 'counts'], array_fill_keys(array_map(static fn(int $index): string => 'kind_' . $index, range(0, 128)), 1)],
    ['counts-negative', ['capture', 'counts', 'post'], -1],
    ['counts-fractional', ['capture', 'counts', 'post'], 0.5],
    ['counts-string', ['capture', 'counts', 'post'], 'private-baseline-canary'],
    ['media-missing', ['capture', 'media_count'], null, true],
    ['media-negative', ['capture', 'media_count'], -1],
    ['notes-overflow', ['capture', 'notes_count'], 10001],
    ['revision-malformed', ['capture', 'state_revision'], 'private-baseline-canary'],
    ['digest-malformed', ['receipt_sha256'], 'private-baseline-canary'],
] as $mutation) {
    [$case, $path, $value] = $mutation;
    $candidate = $sshBaselineReceipt;
    $cursor = &$candidate;
    foreach (array_slice($path, 0, -1) as $field) {
        $cursor = &$cursor[$field];
    }
    $field = $path[count($path) - 1];
    if ($mutation[3] ?? false) {
        unset($cursor[$field]);
    } else {
        $cursor[$field] = $value;
    }
    unset($cursor);
    $sshBaselineCases[$case] = $candidate;
}
$sshBaselineProbe = <<<'SH'
set -euo pipefail
ROOT="$1" TMP="$2" DIAG_DIR="$2" fixture_answer="$3" PROBE_CASE="$4"
TARGET_REPOSITORY_BRANCH='fixture/private-baseline-canary'
WPRISM=fixture_capture
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
trap 'printf "SSH_BASELINE_CLEANUP_VISIBLE\n" >&2' EXIT
fixture_capture() {
  [ "$#" -eq 5 ] && [ "$1" = "--envs-file=$TMP/envs.json" ] \
    && [ "$2" = capture ] && [ "$3" = target ] \
    && [ "$4" = "--target-branch=$TARGET_REPOSITORY_BRANCH" ] && [ "$5" = --format=json ] \
    || return 81
  if [ "$PROBE_CASE" = php-stdout ]; then
    printf 'PHP Warning: private-baseline-canary in /fixture.php on line 1\n'
  elif [ "$PROBE_CASE" = php-stderr ]; then
    printf 'PHP Parse error: private-baseline-canary\n' >&2
  fi
  printf '%s\n' "$fixture_answer"
  [ "$PROBE_CASE" != command-nonzero ] || return 7
}
SH;
foreach ($sshBaselineCases as $case => $candidate) {
    $scratch = sys_get_temp_dir() . '/wprism-rmcombo-ssh-baseline-' . bin2hex(random_bytes(8));
    if (!mkdir($scratch, 0700)) {
        throw new RuntimeException('could not allocate SSH baseline scratch');
    }
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
        $sshBaselineProbe . "\n" . ($sshDiagnosticDefinition[0] ?? '')
            . "\n" . ($sshJsonCaptureDefinition[0] ?? '') . "\n" . $sshBaselineBlock
            . "\nprintf 'SSH_BASELINE_READY\\n'\n",
        [$root, $scratch, json_encode($candidate, JSON_THROW_ON_ERROR), $case],
        $root
    );
    $published = $scratch . '/rm-combo-baseline-capture.json';
    $captureDirectory = $scratch . '/rank-math-commerce-multilingual-baseline-capture';
    $publicFailure = in_array($case, ['php-stdout', 'php-stderr'], true)
        ? 'emitted a PHP runtime diagnostic'
        : ($case === 'command-nonzero'
            ? 'failed; inspect its private diagnostic capture'
            : 'did not return a warning-free bound capture receipt');
    wprism_check(
        ($case === 'ready'
            ? $status === 0 && $stdout === "SSH_BASELINE_READY\n"
                && $stderr === "SSH_BASELINE_CLEANUP_VISIBLE\n"
                && is_file($published)
                && Canon::encode(Canon::decode((string) file_get_contents($published))) === Canon::encode($candidate)
            : $status !== 0 && $stdout === '' && !file_exists($published)
                && str_contains($stderr, $publicFailure)
                && substr_count($stderr, 'SSH_BASELINE_CLEANUP_VISIBLE') === 1)
            && !str_contains($stdout . $stderr, 'private-baseline-canary')
            && trim((string) file_get_contents($captureDirectory . '/exit')) === ($case === 'command-nonzero' ? '7' : '0'),
        "the actual SSH baseline receipt rejects $case unless warning-free and bound, with private failure and visible cleanup"
    );
    if (is_file($published)) {
        unlink($published);
    }
    foreach (['stdout', 'stderr', 'exit'] as $captureFile) {
        unlink($captureDirectory . '/' . $captureFile);
    }
    rmdir($captureDirectory);
    rmdir($scratch);
}
foreach ([
    'capture_rmcombo_ssh_json version_json "plugin-version-$adapter"' => 'plugin version',
    'capture_rmcombo_ssh_json active_order_json active-plugin-order' => 'active-plugin order',
    'capture_rmcombo_ssh_json runtime_ready_json runtime-readiness' => 'runtime readiness',
    'capture_rmcombo_ssh_json pin_json "manifest-pin-$adapter"' => 'shipped manifest pin',
    'capture_rmcombo_ssh_json seed_json native-seed' => 'native seed',
    'capture_rmcombo_ssh_json baseline_capture_json baseline-capture' => 'code/state baseline capture',
    'capture_rmcombo_ssh_json before_json native-preimage' => 'native preimage',
    'capture_rmcombo_ssh_json status_json recovery-status' => 'recovery status',
    'capture_rmcombo_ssh_json after_json native-postimage' => 'native postimage',
    'capture_rmcombo_ssh_json promotion_lock_json promotion-lock' => 'promotion lock',
    'capture_rmcombo_ssh_json provider_state_json provider-state' => 'external-writer release receipt',
] as $call => $observation) {
    wprism_check(
        str_contains($sshDeletion, $call),
        "the SSH $observation uses private complete-stream checked JSON transport"
    );
}
wprism_check(
    !preg_match('/(?:actual_version|seed_json|before_json|status_json|after_json)="?\$\(ssh_fixture/', $sshDeletion)
        && !str_contains($sshDeletion, '<<<"$(ssh_fixture')
        && !str_contains($sshDeletion, 'ssh_fixture "cd /var/www/html && wp wprism manifest-pin')
        && !preg_match('/"\$WPRISM"[^\n]* capture target[^\n]*\n\s*--format=json >/', $sshDeletion)
        && !str_contains($sshDeletion, '[ -z "$(target_ledger_value promotion_lock)" ]'),
    'the SSH extension has no remaining raw positive JSON or PHP observation acceptance'
);
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
        '  converged_code=0', '  capture_rmcombo_ssh_json promotion_lock_json promotion-lock',
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
        '  capture_rmcombo_ssh_json status_json recovery-status',
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
