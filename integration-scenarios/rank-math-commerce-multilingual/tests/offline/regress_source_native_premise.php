<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$relative = '/integration-scenarios/rank-math-commerce-multilingual';
$current = (string) file_get_contents($root . $relative . '/tests/live/regress_rank_math_commerce_multilingual.sh');
$priorRoot = is_dir($argv[1] ?? '') ? $argv[1] : $root;
$caller = (string) file_get_contents($priorRoot . $relative . '/tests/live/regress_rank_math_commerce_multilingual.sh');
$definitionsStart = strpos($current, 'assert_rmcombo_acf_values() {');
$definitionsEnd = strpos($current, 'run_leg() {', $definitionsStart);
$definitions = substr($current, $definitionsStart, $definitionsEnd - $definitionsStart);
$assertionsStart = strpos($current, 'assert_rmcombo_warning_free_capture() {');
$assertionsEnd = strpos($current, "\nfor command in docker", $assertionsStart);
$assertions = substr($current, $assertionsStart, $assertionsEnd - $assertionsStart);
$start = strpos($caller, "\nprepare_rmcombo_source_native\n");
if ($start === false) {
    $start = strpos($caller, 'SOURCE_NATIVE=$(native_state wp1)');
}
$endToken = "pass 'source-only Action Scheduler state exists natively before capture'";
$end = strpos($caller, $endToken, $start);
if ($start === false || $end === false || $definitions === '') {
    throw new RuntimeException('the actual pre-capture source acceptance window is absent');
}
$window = substr($caller, $start, $end + strlen($endToken) - $start);

// These authored values reproduce the actual e647 source-only native controls.
// Counts differ by graph degree, not by language or a two-product shortcut.
$base = 'http://rmcomboevidence1.invalid';
$seed = ['book' => 311, 'categories' => ['en' => 321, 'de' => 322],
    'category_tts' => ['en' => 331, 'de' => 332], 'group' => 307,
    'products' => ['en' => 309, 'de' => 310], 'redirection' => 1];
$case = static function (string $mode) use ($base, $seed): array {
    $sync = $mode === 'synchronized' ? ['taxonomies', 'post_meta', 'post_date'] : ['taxonomies', 'post_date'];
    $state = ['book' => [], 'categories' => $seed['categories'], 'category_languages' => ['en' => 'en', 'de' => 'de'],
        'modules' => ['link-counter', 'redirections', 'rich-snippet'], 'neighbor' => null,
        'polylang_sync' => ['stored' => $sync, 'loaded' => $sync], 'products' => [],
        'redirection' => ['header_code' => 302, 'hits' => 0, 'id' => 1,
            'sources' => [['ignore' => '', 'pattern' => 'rmcombo-old', 'comparison' => 'exact']],
            'status' => 'active', 'url_to' => "$base/en/product/rmcombo-product-en/"],
        'redirection_cache' => [], 'retired_target_counts' => null,
        'scheduler' => [['action_id' => '5', 'hook' => 'rmcombo_source_runtime', 'status' => 'pending', 'group_slug' => '']],
        'stale_link_sentinels' => 0, 'term_translations' => $seed['categories'], 'translations' => $seed['products']];
    foreach (['en', 'de'] as $language) {
        $peer = $language === 'en' ? 'de' : 'en';
        $price = $language === 'en' ? '29' : '31';
        $value = $language === 'en' || $mode === 'synchronized' ? 'English badge 東京' : 'Deutsches Abzeichen 東京';
        $url = "$base/$language/product/rmcombo-product-$language/";
        $peerUrl = "$base/$peer/product/rmcombo-product-$peer/";
        $state['products'][$language] = ['acf' => $value, 'acf_metadata_api' => $value,
            'acf_raw' => [['meta_key' => '_rmcombo_badge', 'meta_value' => 'field_rmcombo_badge'], ['meta_key' => 'rmcombo_badge', 'meta_value' => $value]],
            'canonical' => $url,
            'content' => "<p>Portable $language product 東京 🚀 <a href=\"$peerUrl\">translated peer</a> <a href=\"https://external.example.test/rmcombo\">external</a></p>",
            'description' => $language === 'en' ? 'Portable English commerce SEO 東京.' : 'Tragbare deutsche Commerce-SEO 東京.',
            'id' => $seed['products'][$language], 'language' => $language,
            'links' => [['url' => 'https://external.example.test/rmcombo', 'target_post_id' => '0', 'type' => 'external'],
                ['url' => $peerUrl, 'target_post_id' => (string) $seed['products'][$peer], 'type' => 'internal']],
            'lookup' => ['min_price' => "$price.0000", 'max_price' => "$price.0000", 'stock_status' => 'instock'],
            'price' => $price, 'primary' => $seed['categories'][$language], 'processed' => true,
            'rank_counts' => ['internal_link_count' => '1', 'external_link_count' => '1', 'incoming_link_count' => $language === 'en' ? '2' : '1'],
            'title' => $language === 'en' ? 'Portable Rank Math Commerce EN 東京 🚀' : 'Tragbarer Rank Math Handel DE 東京 🚀', 'url' => $url];
    }
    $enUrl = $state['products']['en']['url'];
    $state['book'] = ['content' => "<p>Custom CPT <a href=\"$enUrl\">product</a> <a href=\"https://external.example.test/rmcombo-book\">external</a></p>",
        'id' => $seed['book'], 'links' => [['url' => 'https://external.example.test/rmcombo-book', 'target_post_id' => '0', 'type' => 'external'],
            ['url' => $enUrl, 'target_post_id' => (string) $seed['products']['en'], 'type' => 'internal']],
        'processed' => true, 'rank_counts' => ['internal_link_count' => '1', 'external_link_count' => '1', 'incoming_link_count' => '0'],
        'title' => 'Portable custom CPT SEO 東京'];
    return $state;
};
$probe = <<<'SH'
set -euo pipefail
root="$1" fixture_native="$2" SOURCE_SEED="$3" meta_mode="$4" fault="$5"
PAIR=rmcomboevidence
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { :; }
. "$root/sandbox/conformance/asserts.sh"
. "$root/sandbox/tests/lib/private_command_capture.sh"
scratch=$(mktemp -d "${TMPDIR:-/tmp}/wprism-source-premise.XXXXXX")
trap 'find "$scratch" -depth -delete' EXIT
ROOT="$scratch/checkout"
mkdir -p "$ROOT/integration-scenarios/rank-math-commerce-multilingual/fixtures"
ln -s "$root/integration-scenarios/rank-math-commerce-multilingual/fixtures/native-state-evidence.php" \
  "$ROOT/integration-scenarios/rank-math-commerce-multilingual/fixtures/native-state-evidence.php"
wp1() {
  local phase=routes
  [[ "$2" != *'flush_rewrite_rules(false)'* ]] || phase=flush
  [ "$fault" != "$phase-warning" ] || printf 'PHP Warning: native premise in /fixture.php on line 1\n' >&2
  if [ "$phase" = flush ]; then printf '{"flushed":true}\n'
  else
    jq -nc --argjson seed "$SOURCE_SEED" --argjson state "$fixture_native" --arg fault "$fault" '
      {en:{id:$seed.products.en,resolved:$seed.products.en,url:$state.products.en.url},
       de:{id:$seed.products.de,resolved:(if $fault == "route-wrong" then 0 else $seed.products.de end),url:$state.products.de.url},
       book:{id:$seed.book,resolved:$seed.book,url:"http://rmcomboevidence1.invalid/rmcombo-books/rmcombo-book/"}}'
  fi
  [ "$fault" != "$phase-nonzero" ] || return 7
}
native_state() {
  printf '%s\n' "$1" >>"$scratch/native-sites"
  [ "$fault" != native-compose ] || printf ' Container wprism-%s-cli%s-run-abcd Created \n' "$PAIR" "${1#wp}" >&2
  [ "$fault" != native-other-site ] || printf ' Container wprism-%s-cli1-run-abcd Created \n' "$PAIR" >&2
  [ "$fault" != native-warning ] || printf 'PHP Warning: native source in /fixture.php on line 1\n' >&2
  [ "$fault" != native-noise ] || printf 'unexpected native diagnostic\n' >&2
  [ "$fault" != native-foreign ] || printf ' Container wprism-foreign-cli1-run-abcd Creating \n' >&2
  if [ -n "${__rmcombo_native_directory:-}" ]; then
    case "$fault" in
      mode:*) chmod 0644 "$__rmcombo_native_directory/native.${fault#*:}" ;;
      missing:*) rm "$__rmcombo_native_directory/native.${fault#*:}" ;;
      hardlink) ln "$__rmcombo_native_directory/native.stdout" "$scratch/shared-file" ;;
      directory-mode) chmod 0755 "$__rmcombo_native_directory" ;;
      symlink) mv "$__rmcombo_native_directory/native.stdout" "$scratch/actual-stdout"; ln -s "$scratch/actual-stdout" "$__rmcombo_native_directory/native.stdout" ;;
    esac
  fi
  [ "$fault" != native-empty ] || return 0
  printf '%s\n' "$fixture_native"
  [ "$fault" != oversized-stdout ] || php -r 'echo str_repeat(" ", 1048577);'
  [ "$fault" != oversized-stderr ] || php -r 'fwrite(STDERR, str_repeat("\n", 1048577));'
  [ "$fault" != native-duplicate ] || printf '%s\n' "$fixture_native"
  [ "$fault" != native-nonzero ] || return 7
}
SH;
$run = static function (array $state, string $mode, string $fault = 'ready') use ($probe, $assertions, $definitions, $window, $root, $seed): array {
    return ShellProbe::run($probe . "\n" . $assertions . "\n" . $definitions . "\n" . $window . "\nprintf 'SOURCE_READY\\n'\n",
        [$root, json_encode($state, JSON_THROW_ON_ERROR), json_encode($seed, JSON_THROW_ON_ERROR), $mode, $fault], $root);
};
foreach (['independent', 'synchronized'] as $mode) {
    $healthy = $case($mode);
    [$status, $stdout, $stderr] = $run($healthy, $mode);
    wprism_check($status === 0 && str_contains($stdout, 'SOURCE_READY'), "actual pre-capture window accepts coherent $mode source (exit $status)");
    if ($status !== 0) {
        fwrite(STDERR, substr($stderr, 0, 2048));
    }
    $mutations = [];
    $walk = static function (array $node, array $path = []) use (&$walk, &$mutations, $healthy): void {
        foreach ($node as $key => $value) {
            $next = [...$path, $key];
            if (is_array($value) && $value !== []) {
                $walk($value, $next);
                continue;
            }
            $mutated = $healthy;
            $cursor = &$mutated;
            foreach ($next as $part) {
                $cursor = &$cursor[$part];
            }
            $cursor = is_bool($value) ? !$value : (is_int($value) ? $value + 1 : (is_string($value) ? $value . ':wrong' : 'unexpected'));
            unset($cursor);
            $mutations[implode('.', $next)] = $mutated;
        }
    };
    $walk($healthy);
    $unresolved = $healthy;
    foreach (['en', 'de'] as $language) {
        $unresolved['products'][$language]['links'][1]['target_post_id'] = '0';
        $unresolved['products'][$language]['rank_counts']['incoming_link_count'] = '0';
    }
    $unresolved['book']['links'][1]['target_post_id'] = '0';
    $mutations['unresolved-but-internally-counted-graph'] = $unresolved;
    foreach ($mutations as $name => $mutated) {
        [$status, $stdout] = $run($mutated, $mode);
        wprism_check($status !== 0 && !str_contains($stdout, 'SOURCE_READY'), "actual pre-capture $mode source refuses $name");
    }
}
foreach (['flush-warning', 'flush-nonzero', 'routes-warning', 'routes-nonzero', 'route-wrong',
    'native-warning', 'native-noise', 'native-foreign', 'native-empty', 'native-duplicate', 'native-nonzero',
    'mode:stdout', 'mode:stderr', 'mode:exit', 'missing:stdout', 'missing:stderr', 'hardlink',
    'directory-mode', 'symlink', 'oversized-stdout', 'oversized-stderr'] as $fault) {
    [$status, $stdout, $stderr] = $run($case('independent'), 'independent', $fault);
    wprism_check($status !== 0 && !str_contains($stdout, 'SOURCE_READY'), "actual source native preparation/capture refuses $fault");
    wprism_check(!str_contains($stderr, 'English badge') && !str_contains($stderr, 'Trying to access'), "$fault does not disclose private metadata or unchecked file diagnostics");
}

// Replaying only the old target caller must still read its healthy native
// value, then fail because no complete baseline survives site cleanup. This
// separates db96's missing evidence from the not-yet-diagnosed native delta.
$targetRoot = is_dir($argv[2] ?? '') ? $argv[2] : $root;
$targetSource = (string) file_get_contents($targetRoot . $relative . '/tests/live/regress_rank_math_commerce_multilingual.sh');
$targetCalls = [];
foreach (['TARGET' => 'target-initial', 'TARGET_RUNTIME' => 'target-after-redirect',
    'FAILURE_NATIVE' => 'target-after-failure', 'RETRY_NATIVE' => 'target-after-retry',
    'RETRY_RUNTIME' => 'target-after-retry-redirect',
    'TARGET_FINAL' => 'target-final', 'DELETE_REFUSAL_NATIVE' => 'target-delete-refusal'] as $variable => $phase) {
    $pattern = '/^(?:capture_rmcombo_native_state ' . $variable . ' wp2 ' . $phase
        . '|' . $variable . '=\$\(native_state wp2\))$/m';
    if (preg_match_all($pattern, $targetSource, $matches) !== 1) {
        throw new RuntimeException('the actual native target observation caller is ambiguous or absent');
    }
    $targetCalls[$variable] = [$phase, $matches[0][0]];
}
$targetAfter = <<<'SH'
[ "${!observed_variable}" = "$fixture_native" ] || fail 'target capture altered the complete native value'
[ "$(<"$scratch/native-sites")" = wp2 ] || fail 'target capture observed a different site or read twice'
printf 'TARGET_VALUE_READY\n'
rmdir "$ROOT/sandbox/siterepo/rmcomboevidence2"
matches=("$ROOT/sandbox/tmp/wprism-rmcombo-native.$PAIR.$observed_phase."*)
[ "${#matches[@]}" -eq 1 ] && [ -d "${matches[0]}" ] || fail 'target observation was not retained outside site cleanup'
reopened=$(php "$root/integration-scenarios/rank-math-commerce-multilingual/fixtures/native-state-evidence.php" \
  "${matches[0]}/native" "$PAIR" cli2) || fail 'retained target state failed bounded readback'
[ "$reopened" = "$fixture_native" ] || fail 'retained target state is not the complete observed native value'
printf 'TARGET_RETAINED\n'
SH;
$runTarget = static function (string $variable, string $fault = 'ready', ?string $json = null) use ($probe, $definitions, $root, $case, $seed, $targetCalls, $targetAfter): array {
    [$phase, $call] = $targetCalls[$variable];
    $script = $probe . "\n" . $definitions . "\n"
        . 'mkdir -p "$ROOT/sandbox/siterepo/rmcomboevidence2"' . "\n"
        . 'observed_variable=' . escapeshellarg($variable) . ' observed_phase=' . escapeshellarg($phase) . "\n"
        . $call . "\n" . $targetAfter;
    return ShellProbe::run($script,
        [$root, $json ?? json_encode($case('independent'), JSON_THROW_ON_ERROR),
            json_encode($seed, JSON_THROW_ON_ERROR), 'independent', $fault], $root);
};
foreach (array_keys($targetCalls) as $variable) {
    foreach (['ready', 'native-compose'] as $fault) {
        [$status, $stdout, $stderr] = $runTarget($variable, $fault);
        wprism_check(str_contains($stdout, 'TARGET_VALUE_READY'), "$variable $fault preserves the complete healthy native value with one target read");
        wprism_check($status === 0 && str_contains($stdout, 'TARGET_RETAINED'), "$variable $fault retains bounded private evidence after site cleanup");
        if ($status !== 0 && $targetRoot === $root) fwrite(STDERR, substr($stderr, 0, 1024));
    }
}
foreach (['native-warning', 'native-noise', 'native-foreign', 'native-other-site', 'native-empty',
    'native-duplicate', 'native-nonzero', 'mode:stdout', 'mode:stderr', 'mode:exit',
    'missing:stdout', 'missing:stderr', 'hardlink', 'directory-mode', 'symlink',
    'oversized-stdout', 'oversized-stderr'] as $fault) {
    [$status, $stdout, $stderr] = $runTarget('TARGET_RUNTIME', $fault);
    wprism_check($status !== 0 && !str_contains($stdout, 'TARGET_VALUE_READY'), "actual target observation refuses $fault before publication");
    wprism_check(!str_contains($stderr, 'English badge') && !str_contains($stderr, 'Trying to access'), "$fault target refusal exposes no private value or unchecked diagnostic");
}
foreach (['null', '[]', '"not an object"', '{} {}'] as $json) {
    [$status, $stdout] = $runTarget('TARGET_RUNTIME', 'ready', $json);
    wprism_check($status !== 0 && !str_contains($stdout, 'TARGET_VALUE_READY'), 'target evidence rejects a non-object or ambiguous JSON record');
}
foreach (['capture_rmcombo_native_state',
    'capture_rmcombo_native_state __rmcombo_native_value wp2 target',
    'capture_rmcombo_native_state "bad-name" wp2 target',
    'capture_rmcombo_native_state RESULT unknown target',
    'capture_rmcombo_native_state RESULT wp2 ../target',
    'PAIR=../bad; capture_rmcombo_native_state RESULT wp2 target'] as $call) {
    [$status] = ShellProbe::run($probe . "\n" . $definitions . "\ncall_status=0\n( " . $call . " ) || call_status=$?\n"
        . '[ "$call_status" -ne 0 ] && [ ! -e "$scratch/native-sites" ]',
        [$root, '{}', '{}', 'independent', 'ready'], $root);
    wprism_check($status === 0, 'native capture rejects malformed caller authority before observation');
}

$retry = $case('independent');
$retry['products']['en']['links'][] = ['url' => 'https://retry.example.test/new', 'type' => 'external', 'target_post_id' => '0'];
$retry['products']['en']['rank_counts']['external_link_count'] = '2';
foreach (['ready', 'missing-book-incoming', 'missing-retry-outgoing'] as $fault) {
    $state = $retry;
    if ($fault === 'missing-book-incoming') {
        $state['products']['en']['rank_counts']['incoming_link_count'] = '1';
    } elseif ($fault === 'missing-retry-outgoing') {
        $state['products']['en']['rank_counts']['external_link_count'] = '1';
    }
    [$status] = ShellProbe::run("set -euo pipefail\nfail() { exit 1; }\n" . $definitions . "\n" . 'assert_rmcombo_link_counts "$1"' . "\n",
        [json_encode($state, JSON_THROW_ON_ERROR)], $root);
    wprism_check(($status === 0) === ($fault === 'ready'), "actual retry graph count oracle distinguishes $fault");
}

// The third optional root executes the old retry caller, not a reimplementation
// of its predicate. Both callers receive the same complete native observations.
$retryRoot = is_dir($argv[3] ?? '') ? $argv[3] : $root;
$retrySource = (string) file_get_contents($retryRoot . $relative . '/tests/live/regress_rank_math_commerce_multilingual.sh');
$retryStart = strpos($retrySource, 'capture_rmcombo_native_state RETRY_NATIVE wp2 target-after-retry');
$retryEnd = strpos($retrySource, "say 'combined recapture and repeated apply are exact no-ops'", $retryStart ?: 0);
if ($retryStart === false || $retryEnd === false) {
    throw new RuntimeException('the actual retry/native-redirect acceptance window is absent');
}
$retryWindow = substr($retrySource, $retryStart, $retryEnd - $retryStart);
$baseline = $case('independent');
$baseline['redirection']['hits'] = 42;
$baseline['redirection_cache'] = [['from_url' => 'rmcombo-old', 'redirection_id' => '1',
    'object_id' => '0', 'object_type' => 'any', 'is_redirected' => '1']];
$baseline['neighbor'] = ['id' => 501, 'acf' => 'target only', 'price' => '67', 'title' => 'local SEO'];
$baseline['retired_target_counts'] = ['internal_link_count' => '0', 'external_link_count' => '0', 'incoming_link_count' => '0'];
$retried = $baseline;
$retried['products']['en']['content'] .= '<p>provider failure retained combined retry authority <a href="https://retry.example.test/">retry external</a></p>';
$retried['products']['en']['links'][] = ['url' => 'https://retry.example.test/', 'target_post_id' => '0', 'type' => 'external'];
usort($retried['products']['en']['links'], static fn(array $a, array $b): int => [$a['type'], $a['url'], $a['target_post_id']] <=> [$b['type'], $b['url'], $b['target_post_id']]);
$retried['products']['en']['rank_counts']['external_link_count'] = '2';
$retried['redirection_cache'] = [];
$served = $retried;
$served['redirection']['hits']++;
$served['redirection_cache'] = $baseline['redirection_cache'];
$retryProbe = <<<'SH'
set -euo pipefail
TARGET_RUNTIME="$1" fixture_retry="$2" fixture_served="$3" fixture_status="$4" fixture_location="$5"
fail() { printf '%s\n' "$*" >&2; exit 1; }
pass() { :; }
capture_rmcombo_native_state() {
  [ "$2" = wp2 ] || fail 'retry read a different site'
  printf 'native:%s\n' "$3"
  case "$3" in
    target-after-retry) printf -v "$1" '%s' "$fixture_retry" ;;
    target-after-retry-redirect) printf -v "$1" '%s' "$fixture_served" ;;
    *) fail 'unknown retry phase' ;;
  esac
}
redirection_response() {
  printf 'http\n'
  REDIRECT_STATUS="$fixture_status" REDIRECT_LOCATION="$fixture_location"
}
SH;
// Only the graph-count definition is needed; the native capture above remains
// the deterministic seam, while jq and both actual acceptance callers run.
$countStart = strpos($current, 'assert_rmcombo_link_counts() {');
$countEnd = strpos($current, "\n}", $countStart ?: 0);
if ($countStart === false || $countEnd === false) throw new RuntimeException('graph-count definition is absent');
$countDefinition = substr($current, $countStart, $countEnd + 2 - $countStart);
$retryReadyTrace = "native:target-after-retry\nhttp\nnative:target-after-retry-redirect\nRETRY_READY\n";
$runRetry = static function (array $before, array $after, array $http, string $status = '302', ?string $location = null) use ($retryProbe, $assertions, $countDefinition, $retryWindow, $root): array {
    return ShellProbe::run($retryProbe . "\n" . $assertions . "\n" . $countDefinition . "\n" . $retryWindow . "\nprintf 'RETRY_READY\\n'\n",
        [json_encode($before, JSON_THROW_ON_ERROR), json_encode($after, JSON_THROW_ON_ERROR), json_encode($http, JSON_THROW_ON_ERROR),
            $status, $location ?? $before['products']['en']['url']], $root);
};
[$status, $stdout, $stderr] = $runRetry($baseline, $retried, $served);
wprism_check($status === 0 && $stdout === $retryReadyTrace && $stderr === '',
    'actual retry caller accepts exact content/edge/count changes, owned cache invalidation, and native 302 cache refill');
if ($status !== 0 && $retryRoot === $root) fwrite(STDERR, substr($stderr, 0, 2048));
foreach (['retained-owned-cache', 'wrong-owned-cache', 'missing-premise-cache', 'foreign-premise-cache', 'duplicate-edge', 'imprecise-content', 'wrong-edge-target', 'wrong-edge-url'] as $fault) {
    $before = $baseline;
    $after = $retried;
    if ($fault === 'retained-owned-cache') $after['redirection_cache'] = $before['redirection_cache'];
    if ($fault === 'wrong-owned-cache') $after['redirection_cache'] = [array_replace($before['redirection_cache'][0], ['redirection_id' => '999'])];
    if ($fault === 'missing-premise-cache') $before['redirection_cache'] = [];
    if ($fault === 'foreign-premise-cache') $before['redirection_cache'][0]['redirection_id'] = '999';
    if ($fault === 'duplicate-edge') $after['products']['en']['links'][] = $after['products']['en']['links'][1];
    if ($fault === 'imprecise-content') $after['products']['en']['content'] .= 'unrelated drift';
    if ($fault === 'wrong-edge-target') $after['products']['en']['links'][1]['target_post_id'] = '999';
    if ($fault === 'wrong-edge-url') $after['products']['en']['links'][1]['url'] .= '?wrong';
    [$status, $stdout] = $runRetry($before, $after, $served);
    wprism_check($status !== 0 && !str_contains($stdout, 'http'), "actual retry caller refuses $fault before HTTP");
}
// Mutate every complete native top-level witness, then each product field.
// Normalizing the changed product as a whole would incorrectly admit these.
foreach (array_keys($retried) as $key) {
    $after = $retried;
    $after[$key] = null;
    [$status, $stdout] = $runRetry($baseline, $after, $served);
    wprism_check($status !== 0 && !str_contains($stdout, 'RETRY_READY'), "retry preserves the complete $key witness");
}
foreach (['en', 'de'] as $language) {
    foreach (array_keys($retried['products'][$language]) as $key) {
        $after = $retried;
        $after['products'][$language][$key] = null;
        [$status, $stdout] = $runRetry($baseline, $after, $served);
        wprism_check($status !== 0 && !str_contains($stdout, 'RETRY_READY'), "retry refuses $language.$key drift");
    }
}
foreach (['status', 'location', 'no-cache', 'wrong-cache-owner', 'wrong-cache-object', 'two-hits', 'lost-edge', 'runtime-neighbor'] as $fault) {
    $http = $served;
    if ($fault === 'no-cache') $http['redirection_cache'] = [];
    if ($fault === 'wrong-cache-owner') $http['redirection_cache'][0]['redirection_id'] = '999';
    if ($fault === 'wrong-cache-object') $http['redirection_cache'][0]['object_id'] = '999';
    if ($fault === 'two-hits') $http['redirection']['hits']++;
    if ($fault === 'lost-edge') array_pop($http['products']['en']['links']);
    if ($fault === 'runtime-neighbor') $http['neighbor']['price'] = '1';
    [$status, $stdout] = $runRetry($baseline, $retried, $http, $fault === 'status' ? '301' : '302', $fault === 'location' ? 'https://wrong.invalid/' : null);
    wprism_check($status !== 0 && !str_contains($stdout, 'RETRY_READY'), "post-retry native redirect refuses $fault");
}

$dispatchStart = strpos($current, 'pair_live_ownership_acquire mariadb');
if ($dispatchStart === false) {
    throw new RuntimeException('the actual four-lane ownership dispatcher is absent');
}
$dispatch = substr($current, $dispatchStart);
$dispatchProbe = <<<'SH'
set -euo pipefail
HEAD=candidate
failed_leg="$1" leg=0
PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE=0
say() { :; }
fail() { exit 1; }
pair_live_ownership_acquire() { PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE=1; printf 'acquire\n'; }
pair_live_ownership_up() { printf 'up\n'; }
pair_live_ownership_reset() { printf 'reset\n'; }
pair_live_ownership_complete() { printf 'complete\n'; }
run_leg() {
  leg=$((leg + 1))
  printf 'leg:%s:%s:%s\n' "$1" "$2" "$3"
  [ "$leg" -ne "$failed_leg" ]
}
SH;
$expectedDispatch = ['acquire', 'up', 'leg:forward:reverse:independent', 'reset', 'up',
    'leg:reverse:forward:independent', 'reset', 'up', 'leg:forward:reverse:synchronized', 'reset', 'up',
    'leg:reverse:forward:synchronized', 'complete'];
foreach ([0, 1, 2, 3, 4] as $failedLeg) {
    [$status, $stdout] = ShellProbe::run($dispatchProbe . "\n" . $dispatch, [(string) $failedLeg], $root);
    $expected = $failedLeg === 0 ? $expectedDispatch : array_slice($expectedDispatch, 0, $failedLeg * 3);
    wprism_check(($status === 0) === ($failedLeg === 0) && explode("\n", trim($stdout)) === $expected,
        "actual four-lane dispatcher resets only after complete legs and never completes after failure $failedLeg");
}

wprism_check(str_contains($current, 'for meta_mode in independent synchronized; do')
    && str_contains($current, 'run_leg forward reverse "$meta_mode"')
    && str_contains($current, 'run_leg reverse forward "$meta_mode"')
    && str_contains($current, 'create_languages wp2 "$target_meta_mode"'),
    'both metadata modes execute both plugin orders against the opposite target setting');
wprism_check(str_contains($current, 'assert_rmcombo_link_counts "$TARGET"')
    && str_contains($current, 'assert_rmcombo_link_counts "$RETRY_NATIVE"'),
    'initial Apply and retry consume the complete three-author graph count oracle');
wprism_check_summary('combined source and target native evidence');
