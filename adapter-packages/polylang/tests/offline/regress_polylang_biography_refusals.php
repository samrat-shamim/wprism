<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once dirname(__DIR__, 2) . '/fixtures/polylang_biography_refusals.php';

use WPrism\Canon;
use WPrism\PrivateRefusalEvidence;
use WPrism\UserMetaState;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\ShellProbe;

$scratch = sys_get_temp_dir() . '/wprism-polylang-biography-refusals-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$write = static function (string $path, mixed $value): void {
    file_put_contents($path, is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
};
$transport = static function (string $stem, mixed $value, int $status = 0, string $stderr = '') use ($write): void {
    $write($stem . '.stdout', is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR) . "\n");
    $write($stem . '.stderr', $stderr); $write($stem . '.exit', $status . "\n");
};
// Synthetic transport premises only. Native KSES evidence is supplied by the
// separately allocated normal-WordPress conformance run, never this fixture.
$native = static function (string $control = 'safe'): array {
    $values = PolylangBiographyValues::authored('http://localhost:9415', $control);
    $rows = $api = $oracle = [];
    foreach ($values as $key => $value) {
        $rows[] = ['meta_id' => (string) (count($rows) + 1), 'meta_key' => $key, 'meta_value' => $value];
        $api[$key] = [$value];
    }
    $rows[] = ['meta_id' => '4', 'meta_key' => 'runtime-neighbor', 'meta_value' => 'preserve raw neighbor'];
    foreach (PolylangBiographyValues::hostile() as $key => $value) $oracle[$key] = ['input' => $value, 'sanitized' => 'synthetic differential only'];
    return ['format' => 'polylang-biography-native/v1', 'home' => 'http://localhost:9415', 'admin_id' => '1001',
        'users' => [['ID' => '1001', 'user_login' => 'admin', 'user_pass' => 'private synthetic fixture', 'user_nicename' => 'admin',
            'user_email' => 'admin@example.test', 'user_url' => '', 'user_registered' => '2026-09-07 00:00:00',
            'user_activation_key' => '', 'user_status' => '0', 'display_name' => 'Admin']],
        'metadata' => [['user_id' => '1001', 'rows' => $rows]], 'biographies' => $api, 'default_shadow' => [],
        'orphan_rows' => '0', 'hostile_oracle' => $oracle];
};
$repo = $scratch . '/repo';
mkdir($repo . '/state/user-meta', 0700, true);
mkdir($repo . '/state/terms/language', 0700, true);
mkdir($repo . '/state/posts/attachment', 0700, true);
mkdir($repo . '/state/options', 0700);
mkdir($repo . '/media', 0700);
$policy = Canon::encode(['spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core', 'polylang'],
    'policy' => ['post_types' => ['attachment'], 'taxonomies' => ['language']]]);
$write($repo . '/site.wprism.json', $policy);
$coreOptions = [];
foreach (['active_plugins', 'blog_public', 'blogdescription', 'blogname', 'default_category',
    'page_for_posts', 'page_on_front', 'permalink_structure', 'posts_per_page', 'show_on_front',
    'sticky_posts', 'stylesheet', 'template', 'wp_page_for_privacy_policy', 'polylang'] as $name) {
    $coreOptions[$name] = \WPrism\OptionState::absent();
}
$coreOptions['polylang'] = \WPrism\OptionState::present([
    'browser' => false, 'default_lang' => 'en', 'force_lang' => 1, 'hide_default' => true,
    'media_support' => false, 'nav_menus' => [], 'post_types' => [], 'redirect_lang' => false,
    'rewrite' => true, 'sync' => [], 'taxonomies' => [],
], 'yes');
$write($repo . '/state/options/core.json', Canon::encode(\WPrism\OptionState::document($coreOptions)));
$sidecar = $repo . '/state/' . UserMetaState::path('admin');
$safeDocument = Canon::encode(UserMetaState::document('admin', PolylangBiographyValues::authored('{{home}}')));
$write($sidecar, $safeDocument);
foreach (['fr', 'ar', 'en'] as $index => $slug) {
    $uuid = '10000000-0000-4000-8000-00000000000' . ($index + 1);
    $write($repo . '/state/terms/language/' . $uuid . '--' . $slug . '.json', Canon::encode([
        'uuid' => $uuid, 'taxonomy' => 'language', 'name' => $slug, 'slug' => $slug, 'parent' => null,
        'description' => serialize(['locale' => ['fr' => 'fr_FR', 'ar' => 'ar', 'en' => 'en_US'][$slug],
            'rtl' => $slug === 'ar', 'flag_code' => ['fr' => 'fr', 'ar' => 'sa', 'en' => 'us'][$slug]]),
        'term_group' => 0, 'meta' => (object) [], 'relationships' => (object) [],
    ]));
}
// The real conformance caller supplies a relative repository containing media.
// A sidecar-only fixture never reaches the immutable media path authority.
$mediaBytes = "wprism-polylang-biography-media\n";
$mediaName = hash('sha256', $mediaBytes) . '.txt';
$write($repo . '/media/' . $mediaName, $mediaBytes);
$attachment = '10000000-0000-4000-8000-000000000004';
$write($repo . '/state/posts/attachment/' . $attachment . '--biography.txt.md', Canon::post_file([
    'author' => 'user:admin', 'comment_status' => 'open', 'date' => '2026-09-07 00:00:00',
    'date_gmt' => '2026-09-07 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified_gmt' => '2026-09-07 00:00:00', 'parent' => null, 'ping_status' => 'closed',
    'slug' => 'biography.txt', 'status' => 'inherit', 'terms' => (object) [], 'title' => 'Biography attachment',
    'type' => 'attachment', 'uuid' => $attachment, 'alt' => '', 'file' => 'biography.txt',
    'media' => $mediaName, 'mime' => 'text/plain',
], ''));
$transport($scratch . '/native', $native());
$safe = PolylangBiographyRefusals::snapshot($repo, $scratch . '/native', 'biographytest', 'cli2', 'safe', 'safe');
wprism_check(!function_exists('wp_kses'), 'complete preservation snapshot uses the real standalone compiler without a native sanitizer');
$compileProbe = <<<'SH'
set -euo pipefail
php "$1" capture "$2" "$3" biographytest cli2
SH;
foreach ([$repo, 'repo', './repo/'] as $repository) {
    [$status, $output, $diagnostic] = ShellProbe::run($compileProbe,
        [dirname(__DIR__, 2) . '/fixtures/polylang_biography_evidence.php', $repository, $scratch . '/native'], $scratch);
    wprism_check($status === 0 && $diagnostic === '', "actual host biography command compiles an attachment-bearing repository from $repository");
    if ($status === 0 && $diagnostic === '') {
        $compiled = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        PolylangBiographyEvidence::assertRecord($compiled);
        wprism_check_same($safe['observation'], $compiled, 'absolute and relative command inputs retain identical complete canonical and compiled evidence');
    }
}
symlink($repo, $scratch . '/linked-repo');
mkdir($scratch . '/linked-state', 0700);
symlink($repo . '/state', $scratch . '/linked-state/state');
foreach ([$scratch . '/missing', $scratch . '/linked-repo', $scratch . '/linked-state'] as $invalid) {
    wprism_check_throws(static fn() => PolylangBiographyEvidence::capture($invalid, $scratch . '/native', 'biographytest', 'cli2'),
        RuntimeException::class, 'repository resolution does not bypass shared missing-root or symlink confinement');
}
foreach (array_keys(PolylangBiographyValues::hostile()) as $control) {
    foreach (['corrupt', 'restore'] as $mode) {
        $controlReceipt = ['format' => 'polylang-biography-control/v1', 'mode' => $mode, 'control' => $control, 'rows_changed' => 1];
        $transport($scratch . '/control', $controlReceipt);
        PolylangBiographyRefusals::nativeControl($scratch . '/control', 'biographytest', 'cli2', $mode, $control);
        $controlReceipt['rows_changed'] = 2;
        $transport($scratch . '/control', $controlReceipt);
        wprism_check_throws(static fn() => PolylangBiographyRefusals::nativeControl($scratch . '/control', 'biographytest', 'cli2', $mode, $control),
            RuntimeException::class, "$mode $control refuses a native mutation beyond its exact row");
    }
    $transport($scratch . '/native', $native($control));
    $existing = PolylangBiographyRefusals::snapshot($repo, $scratch . '/native', 'biographytest', 'cli2', 'existing', $control);
    wprism_check_same($safe['observation']['compiled'], $existing['observation']['compiled'], "$control existing fault does not rewrite desired intent");
    wprism_check_throws(static fn() => PolylangBiographyRefusals::assertSnapshot($existing, 'safe', 'safe'), RuntimeException::class,
        "$control native fault cannot select its own acceptance profile");
    $transport($scratch . '/native', $native());
    $corrupt = PolylangBiographyRefusals::corruptCanonical($repo, $control);
    $transport($scratch . '/corrupt', $corrupt);
    $desired = PolylangBiographyRefusals::snapshot($repo, $scratch . '/native', 'biographytest', 'cli2', 'desired', $control);
    wprism_check_same(PolylangBiographyValues::hostile()[$control], $desired['observation']['compiled']['meta']['description_fr'],
        "$control desired fault still compiles portably before the native command gate");
    PolylangBiographyRefusals::restoreCanonical($repo, $control, $scratch . '/corrupt');
    wprism_check_same($safe, PolylangBiographyRefusals::snapshot($repo, $scratch . '/native', 'biographytest', 'cli2', 'safe', 'safe'),
        "$control fixture restoration is complete and exact");
}
$corrupt = PolylangBiographyRefusals::corruptCanonical($repo, 'script');
$transport($scratch . '/corrupt', $corrupt);
$write($sidecar, $safeDocument . "\n");
wprism_check_throws(static fn() => PolylangBiographyRefusals::restoreCanonical($repo, 'script', $scratch . '/corrupt'), RuntimeException::class,
    'fixture restoration refuses a changed preimage rather than hiding a protected-command edit', 'no longer owns');
$write($sidecar, $safeDocument);
foreach ([['safe', 'script'], ['existing', 'unknown'], ['desired', 'safe']] as [$boundary, $control]) {
    wprism_check_throws(static fn() => PolylangBiographyRefusals::coordinates($boundary, $control), RuntimeException::class,
        'an undeclared snapshot coordinate refuses');
}
foreach ([['desired', 'capture'], ['existing', 'deploy'], ['safe', 'apply']] as [$boundary, $command]) {
    wprism_check_throws(static fn() => PolylangBiographyRefusals::profile($boundary, $command), RuntimeException::class,
        'an undeclared native refusal route refuses');
}
$oversized = $native();
$oversized['metadata'][0]['rows'][3]['meta_value'] = str_repeat('x', 32768);
wprism_check_throws(static fn() => PolylangBiographyEvidence::assertNative($oversized), RuntimeException::class,
    'native transport cannot exceed the complete fixture metadata budget', 'fixture budget');

$public = static function (string $command): array {
    $message = "$command refused at an unclassified safety gate";
    $remediation = match ($command) {
        'capture' => 'inspect private operator evidence and capture recovery state; classify, correct, or recover the blocker before another attempt',
        'plan' => 'inspect private operator evidence and target state, then correct the repository, policy, capability, or target-state blocker',
        'apply' => 'inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase',
    };
    return ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => $command, 'error' => $command . '_failed',
        'reason_code' => $command . '_failed', 'message' => $message, 'remediation' => $remediation, 'details_redacted' => true,
        'diagnostics' => [['code' => $command . '_failed', 'message' => $message, 'remediation' => $remediation]]];
};
foreach (['capture', 'plan', 'apply'] as $command) {
    PolylangBiographyRefusals::assertPublic($public($command), $command);
    $bad = $public($command); $bad['operator_message'] = 'private';
    wprism_check_throws(static fn() => PolylangBiographyRefusals::assertPublic($bad, $command), RuntimeException::class,
        "$command refuses an extra public disclosure field");
}
$profile = PolylangBiographyRefusals::profile('existing', 'apply');
$privateDirectory = $scratch . '/refusals';
mkdir($privateDirectory, 0700);
$privateName = '20260907-000001-apply-' . str_repeat('a', 24) . '.json';
$record = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'apply', 'reason_code' => 'apply_failed',
    ...PrivateRefusalEvidence::graph(new RuntimeException($profile['nodes'][0]['message']))];
$write($privateDirectory . '/' . $privateName, $record);
$collection = json_decode(PrivateRefusalReceipt::collect($privateDirectory, '[]', $profile), true, 32, JSON_THROW_ON_ERROR);
PolylangBiographyRefusals::assertPrivate($collection, 'existing', 'apply');
wprism_check_throws(static fn() => PolylangBiographyRefusals::assertPrivate($collection, 'desired', 'apply'), RuntimeException::class,
    'a correct existing-state cause cannot certify the desired-state gate');

$probe = <<<'SH'
set -euo pipefail
root="$1" WPRISM_ARTIFACT_LIBRARY_ROOT="$1" fixture="$2" repo="$3" fault="$4" COMPOSE=biography_compose
umask 022
. "$root/sandbox/tests/lib/private_command_capture.sh"
. "$root/adapter-packages/polylang/fixtures/polylang-biography-refusals.sh"
biography_compose() {
  [ "$#" -eq 12 ] && [ "$1" = run ] && [ "$2" = --rm ] && [ "$3" = -T ] \
    && [ "$4" = --volume ] && [ "$5" = "$root/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" ] \
    && [ "$6" = --entrypoint ] && [ "$7" = php ] && [ "$8" = cli2 ] && [ "$9" = -r ] || return 53
  case "${11}" in
    snapshot)
      printf 'baseline\n' >>"$fixture/events"
      if [ "$fault" = bad-baseline ]; then printf '{}\n'; else printf '{"baseline":"[]"}\n'; fi
      [ "$fault" != baseline-nonzero ] || return 7
      [ "$fault" != baseline-warning ] || printf 'PHP Warning: synthetic\n' >&2
      ;;
    collect)
      printf 'private\n' >>"$fixture/events"
      cat "$fixture/collection.json"
      [ "$fault" != private-nonzero ] || return 7
      ;;
    *) return 54 ;;
  esac
}
wp_conf2() {
  if [ "$1" = eval-file ]; then
    [ "$#" -eq 4 ] && [ "$2" = --use-include ] && [ "$3" = /siterepo/.tmp-polylang-biography/polylang_biography_native.php ] && [ "$4" = observe ] || return 55
    if [ -f "$fixture/ran" ]; then
      printf 'after\n' >>"$fixture/events"
      [ "$fault" != after-nonzero ] || return 8
      cat "$fixture/after-native.json"
    else
      printf 'before\n' >>"$fixture/events"
      cat "$fixture/native.json"
    fi
    return 0
  fi
  [ "$#" -eq 4 ] && [ "$1" = wprism ] && [ "$2" = apply ] && [ "$3" = --repo=/siterepo ] && [ "$4" = --format=json ] || return 56
  printf 'command\n' >>"$fixture/events"
  : >"$fixture/ran"
  if [ "$fault" = policy-change ]; then printf '\n' >>"$repo/site.wprism.json"; fi
  if [ "$fault" = timestamp-change ]; then touch -t 202001010101 "$repo/state/terms/language/10000000-0000-4000-8000-000000000001--fr.json"; fi
  if [ "$fault" = command-warning ]; then printf 'PHP Warning: synthetic\n' >&2; fi
  cat "$fixture/public.json"
  case "$fault" in command-zero) return 0 ;; command-seven) return 7 ;; *) return 1 ;; esac
}
polylang_biography_refusal_command "$fixture/sink" "$repo" wp_conf2 cli2 biographytest existing apply script
printf 'REFUSAL_VERIFIED\n'
SH;
foreach (['valid', 'command-zero', 'command-seven', 'command-warning', 'command-empty', 'command-two-json', 'wrong-public',
    'bad-baseline', 'baseline-nonzero', 'baseline-warning', 'private-nonzero', 'wrong-private', 'after-nonzero',
    'native-neighbor', 'native-account', 'policy-change', 'timestamp-change'] as $fault) {
    $fixture = $scratch . '/probe-' . $fault;
    mkdir($fixture . '/sink', 0700, true);
    $before = $native('script'); $after = $before;
    if ($fault === 'native-neighbor') $after['metadata'][0]['rows'][3]['meta_value'] = 'changed runtime neighbor';
    if ($fault === 'native-account') $after['users'][0]['user_pass'] = 'changed account';
    $write($fixture . '/native.json', $before); $write($fixture . '/after-native.json', $after);
    $answer = $public('apply');
    if ($fault === 'wrong-public') $answer['reason_code'] = 'different_failure';
    $bytes = json_encode($answer, JSON_THROW_ON_ERROR) . "\n";
    if ($fault === 'command-empty') $bytes = '';
    if ($fault === 'command-two-json') $bytes .= $bytes;
    $write($fixture . '/public.json', $bytes);
    $private = $collection;
    if ($fault === 'wrong-private') {
        $badRecord = array_replace($record, PrivateRefusalEvidence::graph(new RuntimeException('unrelated cause')));
        $encoded = json_encode($badRecord, JSON_THROW_ON_ERROR);
        $private['diagnostic']['records'][0] = ['name' => $privateName, 'bytes' => strlen($encoded),
            'contents_base64' => base64_encode($encoded), 'sha256' => hash('sha256', $encoded)];
    }
    $write($fixture . '/collection.json', $private);
    [$status, $output] = ShellProbe::run($probe, [$root, $fixture, $repo, $fault], $root . '/sandbox');
    wprism_check($fault === 'valid' ? $status === 0 && str_contains($output, 'REFUSAL_VERIFIED')
        : $status !== 0 && !str_contains($output, 'REFUSAL_VERIFIED'), "actual private biography command and acceptance classify $fault");
    $events = file($fixture . '/events', FILE_IGNORE_NEW_LINES);
    $baselineFailure = in_array($fault, ['bad-baseline', 'baseline-nonzero', 'baseline-warning'], true);
    wprism_check_same($baselineFailure ? ['before', 'baseline'] : ['before', 'baseline', 'command', 'after', 'private'], $events,
        "$fault gates the command on its baseline and still collects after every protected attempt");
    if (!$baselineFailure) {
        wprism_check_same($fault === 'command-zero' ? "0\n" : ($fault === 'command-seven' ? "7\n" : "1\n"),
            file_get_contents($fixture . '/sink/command.exit'), "$fault preserves the original exact protected exit");
    }
    $write($repo . '/site.wprism.json', $policy);
}
$matrixProbe = <<<'SH'
set -euo pipefail
root="$1" WPRISM_ARTIFACT_LIBRARY_ROOT="$1" fixture="$2" CONF_REPO1="$3" CONF_REPO2="$3" CONF_PAIR=biographytest
. "$root/adapter-packages/polylang/fixtures/polylang-biography-refusals.sh"
fail() { printf '%s\n' "$*" >&2; exit 1; }
pass() { :; }
mktemp() {
  [ "$#" -eq 2 ] && [ "$1" = -d ] || return 57
  case "$2" in "$root/sandbox/tmp/polylang-biography-refusal.biographytest."*.XXXXXX) ;; *) return 58 ;; esac
  command mktemp -d "$fixture/capture.XXXXXX"
}
polylang_biography_refusal_observe() { [ "$7:$8" = safe:safe ] || return 59; }
polylang_biography_refusal_control() { printf 'native|%s|%s|%s\n' "$2" "$4" "$6" >>"$fixture/matrix"; }
polylang_biography_refusal_command() { printf 'command|%s|%s|%s|%s\n' "$4" "$6" "$7" "$8" >>"$fixture/matrix"; }
polylang_biography_refusal_stage() {
  local sink="$1" stage="$2"
  (umask 077; : >"$sink/$stage.stdout"; : >"$sink/$stage.stderr"; printf '0\n' >"$sink/$stage.exit")
  if [ "$stage" = corrupt ] || [ "$stage" = restore ]; then printf 'canonical|%s|%s\n' "$stage" "$7" >>"$fixture/matrix"; fi
}
polylang_biography_refusals_check
SH;
$matrixRoot = $scratch . '/matrix';
mkdir($matrixRoot, 0700);
[$status, $output] = ShellProbe::run($matrixProbe, [$root, $matrixRoot, $repo], $root . '/sandbox');
wprism_check_same(0, $status, 'actual matrix dispatcher completes its explicit bounded lane roster');
$expectedMatrix = [];
foreach (array_keys(PolylangBiographyValues::hostile()) as $control) {
    array_push($expectedMatrix,
        "native|corrupt|cli1|$control", "command|cli1|existing|capture|$control", "native|restore|cli1|$control",
        "native|corrupt|cli2|$control", "command|cli2|existing|plan|$control", "command|cli2|existing|apply|$control", "native|restore|cli2|$control",
        "canonical|corrupt|$control", "command|cli2|desired|plan|$control", "command|cli2|desired|apply|$control", "canonical|restore|$control");
}
wprism_check_same($expectedMatrix, file($matrixRoot . '/matrix', FILE_IGNORE_NEW_LINES),
    'all five controls execute source Capture and both target Plan/Apply boundaries with exact restoration ownership');
wprism_check_summary('Polylang biography native-refusal evidence controls');
