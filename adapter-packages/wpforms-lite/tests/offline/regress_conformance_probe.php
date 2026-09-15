<?php
declare(strict_types=1);

// Synthetic admission controls, not a native-run claim. Canonical positives
// come from actual PostCapture with the capsule policy, not the probe's oracle.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Capture/PostCapture.php';
require_once dirname(__DIR__, 2) . '/fixtures/capture-plan/probe.php';

use WPrism\Canon;
use WPrism\Policy;
use WPrism\AdapterLibrary;
use WPrism\PostCapture;
use WPrism\EntityMetaCapture;
use WPrism\MediaCapture;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;
use WPrismTest\ShellProbe;

wprism_test_define_agent_versions();
WpStore::reset();
$wpdb = new FakeWpdb();
$policy = Policy::load(null, ['core', 'wpforms-lite'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'wpforms-lite'));
$home = 'http://localhost:9490';
$record = [
    'format' => 'wprism-wpforms-capture-probe/v1', 'phase' => 'observe', 'version' => '2.0.1.1', 'home' => $home,
    'onboarding' => ['version' => '2.0.1.1', 'edition' => 'lite'], 'posts' => [], 'located' => [10, 11], 'rendered' => [],
];
$rows = $map = [];
foreach (['integer' => 10, 'string' => 11, 'template' => 12, 'destination' => 13, 'embed' => 14] as $role => $id) {
    $type = match ($role) { 'integer', 'string' => 'wpforms', 'template' => 'wpforms-template', default => 'page' };
    $body = ['field_id' => '3', 'settings' => ['form_title' => 'WPrism WPForms ' . $role]];
    if (in_array($role, ['integer', 'string'], true)) {
        $body['id'] = $role === 'string' ? (string) $id : $id;
        $body['fields'] = [1 => ['type' => 'text', 'default_value' => 'Public fixture'], 2 => ['type' => 'email']];
        $body['settings']['confirmations'] = [
            1 => ['type' => 'message', 'message' => 'Thanks 日本語'],
            2 => ['type' => 'page', 'page' => '13'],
            3 => ['type' => 'page', 'page' => 'previous_page'],
            4 => ['type' => 'redirect', 'redirect' => $home . '/wprism-wpf-destination/?page_id=13'],
        ];
        $body['settings']['notifications'] = [1 => [
            'email' => 'operations@example.test', 'sender_name' => 'WPrism notifications Ω',
            'sender_address' => 'forms@example.test', 'replyto' => 'support@example.test',
            'subject' => 'Native fixture', 'message' => '{all_fields}',
        ]];
        $record['rendered'][$role] = '<form id="wpforms-form-' . $id . '"><input name="wpforms[fields][1]"><input name="wpforms[fields][2]"></form>';
    }
    $raw = match ($role) {
        'destination' => 'Native confirmation destination.',
        'embed' => '[wpforms id="10" title="true"]' . "\n" . '<!-- wp:wpforms/form-selector {"formId":"11","displayTitle":true} /-->',
        default => json_encode($body, JSON_THROW_ON_ERROR),
    };
    $uuid = '019200cc-0000-7000-8000-' . sprintf('%012d', $id);
    $record['posts'][$role] = ['id' => $id, 'type' => $type, 'slug' => 'wprism-wpf-' . $role, 'body' => $raw, 'uuid' => $uuid];
    $rows[] = [
        'ID' => $id, 'post_type' => $type, 'post_name' => 'wprism-wpf-' . $role, 'post_title' => 'WPrism WPForms ' . $role,
        'post_content' => $raw, 'post_status' => 'publish', 'post_password' => '', 'post_parent' => 0, 'post_author' => 0,
        'post_date' => '2026-09-07 00:00:00', 'post_date_gmt' => '2026-09-07 00:00:00',
        'post_modified' => '2026-09-07 00:00:00', 'post_modified_gmt' => '2026-09-07 00:00:00',
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_excerpt' => '', 'post_mime_type' => '',
    ];
    $map[] = ['id' => $id, 'uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id];
}
$wpdb->seedTable('wp_posts', $rows)->seedTable('wp_postmeta', [])->seedTable('wp_wprism_map', $map);
$repo = sys_get_temp_dir() . '/wprism-wpf-probe-' . bin2hex(random_bytes(8));
mkdir($repo, 0700);
register_shutdown_function(static function () use ($repo): void {
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($walk as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($repo);
});
mkdir($repo . '/sandbox/tmp', 0700, true);
$tokens = new Tokens($home, $home . '/wp-content/uploads');
$tokens->policy = $policy;
$capture = new PostCapture($policy, $tokens, new EntityMetaCapture(
    $policy, $tokens, static function (): void {}, static function (): void {}, static function (): void {}
), new MediaCapture());
foreach ($rows as $row) {
    $entity = $capture->capture((object) $row, $map[array_search($row['ID'], array_column($map, 'id'), true)]['uuid'], [])['entity'];
    Canon::write_file($repo . '/state/' . $entity['path'], $entity['content']);
}
wprism_check_same([], $tokens->warnings, 'the native-shaped probe fixture captures without token warnings');
WPFormsCaptureProbe::admit($record, 'observe', $home);
wprism_check(true, 'the complete synthetic observation is admitted');
$writeTransport = static function (array $value, string $stderr = '', string $exit = "0\n") use ($repo): string {
    $stem = $repo . '/native';
    foreach (['stdout' => json_encode($value, JSON_THROW_ON_ERROR), 'stderr' => $stderr, 'exit' => $exit] as $suffix => $bytes) {
        file_put_contents($stem . '.' . $suffix, $bytes);
        chmod($stem . '.' . $suffix, 0600);
    }
    return $stem;
};
$stem = $writeTransport($record);
WPFormsCaptureProbe::verify($stem, 'wfpprobe', 'observe', $repo, '9490');
wprism_check(true, 'the host probe admits complete bodies produced by actual capsule PostCapture');

foreach ([
    [['version'], '2.0.0.4'], [['phase'], 'seed'], [['home'], 'https://foreign.example.test'],
    [['posts'], []], [['posts', 'integer', 'id'], 0], [['posts', 'integer', 'uuid'], null],
    [['posts', 'integer', 'type'], 'page'], [['posts', 'integer', 'slug'], '../foreign'],
    [['posts', 'integer', 'body'], '{}'], [['posts', 'string', 'uuid'], $record['posts']['integer']['uuid']],
    [['rendered', 'integer'], ''], [['rendered', 'string'], $record['rendered']['integer']],
    [['located'], [10]], [['posts', 'embed', 'body'], '[wpforms id="10"]'],
    [['onboarding', 'edition'], 'pro'],
] as $index => [$path, $value]) {
    $bad = $record;
    $cursor =& $bad;
    foreach ($path as $part) {
        $cursor =& $cursor[$part];
    }
    $cursor = $value;
    unset($cursor);
    wprism_check_throws(static fn() => WPFormsCaptureProbe::admit($bad, 'observe', $home), Throwable::class, "malformed native observation $index refuses");
}
foreach (["Warning: a native warning\n", "Container wprism-foreign-cli1-run-abc Created\n"] as $stderr) {
    $writeTransport($record, $stderr);
    wprism_check_throws(static fn() => WPFormsCaptureProbe::verify($stem, 'wfpprobe', 'observe', $repo, '9490'), RuntimeException::class, 'unexpected native stderr never becomes successful evidence');
}
$writeTransport($record, '', "1\n");
wprism_check_throws(static fn() => WPFormsCaptureProbe::verify($stem, 'wfpprobe', 'observe', $repo, '9490'), RuntimeException::class, 'a nonzero native command cannot be admitted');
$writeTransport($record);
$path = $repo . '/state/posts/wpforms/' . $record['posts']['integer']['uuid'] . '--wprism-wpf-integer.md';
$before = file_get_contents($path);
file_put_contents($path, str_replace('Public fixture', 'Different authored bytes', $before));
wprism_check_throws(static fn() => WPFormsCaptureProbe::verify($stem, 'wfpprobe', 'observe', $repo, '9490'), RuntimeException::class, 'unrelated canonical body drift refuses rather than checking only declared references');
wprism_check_same(str_replace('Public fixture', 'Different authored bytes', $before), file_get_contents($path), 'a failed host observation preserves the complete canonical input');
file_put_contents($path, $before);

$seed = $record;
$seed['phase'] = 'seed';
foreach ($seed['posts'] as &$post) {
    $post['uuid'] = null;
}
unset($post);
WPFormsCaptureProbe::admit($seed, 'seed', $home);
wprism_check(true, 'the seed observation admits deliberately not-yet-captured identities');
$shell = <<<'SH'
set -euo pipefail
root="$1"
export CONF_REPO1="$2" WPRISM_ARTIFACT_LIBRARY_ROOT="$2" CONF_PAIR=wfpprobe CONF1_PORT=9490
fixture_phase="$3" fixture_stdout="$4" fixture_stderr="$5" fixture_exit="$6"
wp_conf1() {
  [ "$#" -eq 5 ] && [ "$1" = eval-file ] && [ "$3" = "$fixture_phase" ] \
    && [ "$4" = --use-include ] && [ "$5" = --user=admin ] || return 91
  [[ "$2" == /siterepo/.tmp-wpforms-native.*.php ]] || return 92
  [ -f "$CONF_REPO1/${2#/siterepo/}" ] || return 93
  printf '%s' "$fixture_stdout"
  printf '%s' "$fixture_stderr" >&2
  return "$fixture_exit"
}
fail() { printf '%s\n' "$*" >&2; exit 1; }
pass() { printf 'PASS: %s\n' "$*"; }
. "$root/sandbox/conformance/asserts.sh"
export -f wp_conf1 fail pass require_observed_nonempty
export fixture_phase fixture_stdout fixture_stderr fixture_exit
case "$fixture_phase" in seed) hook=seed ;; observe) hook=capture-check ;; esac
bash "$root/adapter-packages/wpforms-lite/tests/conformance/$hook.sh"
SH;
foreach (['seed' => $seed, 'observe' => $record] as $phase => $value) {
    $valid = json_encode($value, JSON_THROW_ON_ERROR);
    foreach ([
        'positive' => [$valid, '', 0, true],
        'native failure' => [$valid, '', 1, false],
        'empty stdout' => ['', '', 0, false],
        'prefixed stdout' => ["unexpected prelude\n" . $valid, '', 0, false],
        'native warning' => [$valid, "PHP Warning: native fixture warning\n", 0, false],
        'wrong pair stderr' => [$valid, "Container wprism-foreign-cli1-run-abcd Created\n", 0, false],
    ] as $label => [$out, $err, $exit, $positive]) {
        [$status, $stdout, $stderr] = ShellProbe::run($shell, [$root, $repo, $phase, $out, $err, (string) $exit], $root);
        wprism_check($positive ? $status === 0 : $status !== 0, "$phase actual shell $label preserves the native command and complete output boundary");
        wprism_check($positive ? str_contains($stdout, 'PASS: WPForms') : !str_contains($stdout, 'PASS: WPForms'), "$phase $label cannot publish a false successful hook result");
        wprism_check_same([], glob($repo . '/.tmp-wpforms-native.*.php'), "$phase $label removes its exact temporary native executable");
        wprism_check(str_contains($stderr, 'WPForms private native observation: '), "$phase $label retains a diagnostic pointer even when admission fails");
    }
}
$entry = json_decode((string) file_get_contents(dirname(__DIR__) . '/conformance/entry.json'), true, flags: JSON_THROW_ON_ERROR);
wprism_check(!isset($entry['entry']['mode']), 'the package selects the complete roundtrip conformance profile');
$checkSource = (string) file_get_contents(dirname(__DIR__) . '/conformance/check.sh');
wprism_check(
    str_contains($checkSource, 'WPForms target native round-trip observation')
        && !str_contains($checkSource, 'capture-plan is the only admitted conformance mode'),
    'the target hook carries native round-trip assertions instead of a source-only refusal'
);
wprism_check_summary('regress_wpforms_lite_conformance_probe');
