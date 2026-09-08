<?php
declare(strict_types=1);

// Synthetic host-admission controls, never claimed as native plugin evidence.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/provider-evidence.php';

function location_provider_evidence_model(): array {
    $home = 'http://wpfmodel1.invalid';
    $base = ['format' => 'wprism-wpforms-native-provider/v1', 'phase' => 'seed', 'case' => 'positive', 'version' => '2.0.1.1', 'home' => $home];
    $forms = ['embeds' => 1, 'no_locations' => 2, 'form_page' => 3, 'conversation' => 4, 'precedence' => 5, 'null_defaults' => 6, 'unicode' => 7];
    $expected = array_fill_keys(array_keys($forms), []);
    $posts = [];
    $names = ['post_publish', 'post_pending', 'post_draft', 'post_future', 'post_private', 'page_root', 'page_child',
        'cpt_both', 'cpt_public', 'cpt_query', 'template', 'template_part', 'duplicates', 'cross_shortcode', 'excluded_attachment',
        'excluded_trash', 'excluded_hidden', 'malformed', 'wrong_case', 'unquoted', 'reusable_only'];
    foreach ($names as $offset => $name) {
        $id = 100 + $offset;
        $type = match ($name) {
            'post_publish', 'post_pending', 'post_draft', 'post_future', 'post_private' => 'post',
            'cpt_both' => 'wpf_both', 'cpt_public' => 'wpf_public', 'cpt_query' => 'wpf_query',
            'template' => 'wp_template', 'template_part' => 'wp_template_part',
            'excluded_attachment' => 'attachment', 'excluded_hidden' => 'wpf_hidden', default => 'page',
        };
        $status = match ($name) {
            'post_pending' => 'pending', 'post_draft' => 'draft', 'post_future' => 'future',
            'post_private' => 'private', 'excluded_trash' => 'trash', default => 'publish',
        };
        $posts[$name] = ['id' => $id, 'type' => $type, 'status' => $status, 'title' => 'WPrism ' . $name,
            'url' => $home . '/' . $name . '/', 'parser' => $name === 'duplicates' ? [1, 1]
                : (in_array($name, ['malformed', 'wrong_case', 'unquoted', 'reusable_only'], true) ? [] : [1])];
        if ($offset < 14) {
            $expected['embeds'][] = ['type' => $type, 'title' => 'WPrism ' . $name, 'form_id' => 1,
                'id' => $id, 'status' => $status, 'url' => '/' . $name . '/'];
        }
    }
    $widgets = [];
    foreach (['wpforms-widget-2', 'text-2', 'text-3', 'text-4', 'block-2'] as $id) {
        $widgets[] = ['type' => 'widget', 'title' => '', 'form_id' => $id === 'wpforms-widget-2' ? '1' : 1, 'id' => $id];
    }
    $expected['embeds'] = array_merge($expected['embeds'], $widgets);
    foreach (['form_page' => ['form_pages', 'Standalone page', '/standalone-page/'],
        'conversation' => ['conversational_forms', 'Conversation', '/conversation/'],
        'precedence' => ['form_pages', 'First enabled', '/%41%7a/'], 'null_defaults' => ['form_pages', '', '//'],
        'unicode' => ['form_pages', '東京', '/%E6%9D%B1%E4%BA%AC/']] as $name => [$type, $title, $url]) {
        $id = $forms[$name];
        $expected[$name][] = ['type' => $type, 'title' => $title, 'form_id' => $id, 'id' => $id, 'status' => 'publish', 'url' => $url];
    }
    $native = [];
    $owned = [];
    foreach ($expected as $name => $rows) {
        $html = str_repeat('<span class="wpforms-locations-list-item"></span>', count($rows));
        if ($name === 'embeds') $html .= 'WPrism locator sidebar: WPForms Widget WPrism locator sidebar: Text Widget Inactive widgets: Inactive (no title) Site editor template: WPrism template Site editor template: WPrism template_part';
        if ($rows === []) $html = '—';
        if ($name === 'precedence') $html .= '<a href="' . $home . '/Az/" target="_blank">';
        if ($name === 'unicode') $html .= '<a href="' . $home . '/東京/" target="_blank">';
        $native[$name] = ['id' => $forms[$name], 'locations' => $rows === [] ? '' : $rows, 'html' => $html, 'passthrough' => 'untouched'];
        if ($rows !== []) $owned[] = ['meta_id' => (string) (count($owned) + 1), 'post_id' => (string) $forms[$name],
            'meta_key' => 'wpforms_form_locations', 'meta_value' => serialize($rows)];
    }
    $after = ['inputs' => array_fill_keys(['posts', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta'], []),
        'remainder' => [['meta_id' => '50', 'post_id' => '1', 'meta_key' => 'unrelated', 'meta_value' => 'untouched']], 'owned' => $owned];
    $before = $after;
    $before['owned'] = [['meta_id' => '1', 'post_id' => '1', 'meta_key' => 'wpforms_form_locations', 'meta_value' => 'stale'],
        ['meta_id' => '2', 'post_id' => '1', 'meta_key' => 'wpforms_form_locations', 'meta_value' => 'duplicate']];
    $boot = static fn(int $pid, bool $child): array => ['pid' => $pid, 'boot' => str_pad((string) $pid, 32, '0', STR_PAD_LEFT), 'child' => $child];
    $projection = static fn(array $state): array => ['inputs_sha256' => str_repeat('a', 64),
        'remainder_sha256' => hash('sha256', serialize($state['remainder'])), 'locations_rows' => count($state['owned']),
        'locations_sha256' => hash('sha256', serialize($state['owned']))];
    $seed = $base + ['template_standalone' => [], 'native' => $native, 'widgets' => $widgets, 'posts' => $posts, 'physical' => $before, 'boot' => $boot(10, false)];
    $invoke = array_replace($base, ['phase' => 'invoke']) + ['artifact' => array_fill_keys(['artifact_hash', 'manifest_hash', 'resolved_adapters_sha256', 'site_hash'], str_repeat('a', 64)),
        'before' => $before, 'receipt' => ['before' => $projection($before), 'after' => $projection($after), 'verified' => true, 'duration_seconds' => 1.0],
        'failure' => null, 'after' => $after, 'children' => [$boot(12, true), $boot(13, true)], 'boot' => $boot(11, false)];
    $observe = array_replace($base, ['phase' => 'observe']) + ['physical' => $after, 'native' => $native, 'boot' => $boot(14, false)];
    $repeat = array_replace($invoke, ['case' => 'repeat', 'before' => $after,
        'receipt' => ['before' => $projection($after), 'after' => $projection($after), 'verified' => true, 'duration_seconds' => 1.0],
        'children' => [$boot(16, true), $boot(17, true)], 'boot' => $boot(15, false)]);
    $stable = array_replace($observe, ['case' => 'repeat', 'boot' => $boot(18, false)]);
    return [$seed, $invoke, $observe, $repeat, $stable];
}

$model = location_provider_evidence_model();
WPFormsLocationProviderEvidence::verify(...$model);
wprism_check(true, 'complete synthetic control reaches host admission (not native evidence)');
$mutations = [
    'missing parser vector' => static function (array &$r): void { unset($r[0]['posts']['malformed']); },
    'native cross-shortcode recognition dropped' => static function (array &$r): void { $r[0]['posts']['cross_shortcode']['parser'] = []; },
    'missing widget family' => static function (array &$r): void { array_pop($r[0]['widgets']); },
    'template builder not excluded' => static function (array &$r): void { $r[0]['template_standalone'] = ['unexpected']; },
    'extra transport field' => static function (array &$r): void { $r[1]['success'] = true; },
    'failed provider with plausible rows' => static function (array &$r): void { $r[1]['failure'] = ['private failure']; },
    'unverified receipt' => static function (array &$r): void { $r[1]['receipt']['verified'] = false; },
    'extra invoker receipt field' => static function (array &$r): void { $r[1]['receipt']['success'] = true; },
    'missing invoker duration' => static function (array &$r): void { unset($r[1]['receipt']['duration_seconds']); },
    'invalid invoker duration' => static function (array &$r): void { $r[1]['receipt']['duration_seconds'] = -1; },
    'changed receipt input projection' => static function (array &$r): void { $r[1]['receipt']['after']['inputs_sha256'] = str_repeat('b', 64); },
    'malformed receipt input projection' => static function (array &$r): void { $r[1]['receipt']['after']['inputs_sha256'] = 'not a digest'; },
    'receipt unrelated to physical rows' => static function (array &$r): void { $r[1]['receipt']['after']['locations_sha256'] = str_repeat('0', 64); },
    'missing observer boot' => static function (array &$r): void { array_pop($r[1]['children']); },
    'same-process fake observer' => static function (array &$r): void { $r[1]['children'][1] = $r[1]['children'][0]; },
    'parent masquerades as child' => static function (array &$r): void { $r[1]['children'][0]['pid'] = $r[1]['boot']['pid']; },
    'changed unrelated row' => static function (array &$r): void { $r[1]['after']['remainder'][0]['meta_value'] = 'changed'; },
    'missing physical input table' => static function (array &$r): void { unset($r[1]['before']['inputs']['usermeta']); },
    'raw rows differ from native UI' => static function (array &$r): void {
        foreach ([1, 2, 3, 4] as $index) {
            foreach (['before', 'after', 'physical'] as $key) {
                if (isset($r[$index][$key]) && !($index === 1 && $key === 'before')) $r[$index][$key]['owned'][0]['meta_value'] = 'tampered';
            }
        }
    },
    'wrong literal renderer href' => static function (array &$r): void { $r[2]['native']['unicode']['html'] = '<span class="wpforms-locations-list-item"></span>'; },
    'coerced private status' => static function (array &$r): void { $r[0]['posts']['post_private']['status'] = 'publish'; },
    'ambiguous attachment exclusion' => static function (array &$r): void { $r[0]['posts']['excluded_attachment']['status'] = 'inherit'; },
    'wrong default native title' => static function (array &$r): void { $r[2]['native']['embeds']['html'] = str_replace('WPForms Widget', 'wrong', $r[2]['native']['embeds']['html']); },
    'wrong empty native column' => static function (array &$r): void { $r[2]['native']['no_locations']['html'] = '0'; },
    'changed artifact on retry' => static function (array &$r): void { $r[3]['artifact']['artifact_hash'] = str_repeat('b', 64); },
];
foreach ($mutations as $name => $mutate) {
    $changed = $model;
    $mutate($changed);
    wprism_check_throws(static fn() => WPFormsLocationProviderEvidence::verify(...$changed), RuntimeException::class,
        'host rejects ' . $name, 'WPForms native provider evidence refused:');
}

// Invoke the live harness's actual capture function. The low-level stage
// retains private bytes without the separate public-command wrapper's replay.
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
$sink = WPrismTest\FrozenPolicy::library();
$shell = (string) file_get_contents(dirname(__DIR__) . '/live/regress_location_provider.sh');
wprism_check(preg_match('/^capture\(\) \{\n.*?^\}\n/ms', $shell, $match) === 1, 'actual live capture function is selected');
$script = 'set -euo pipefail' . "\n" . 'REPO_ROOT="$1"; sink="$2"' . "\n"
    . '. "$REPO_ROOT/sandbox/tests/lib/private_command_capture.sh"' . "\n"
    . 'fail() { printf "FAIL: %s\\n" "$*" >&2; exit 1; }' . "\n" . $match[0]
    . 'capture probe php -r \'echo "PRIVATE_DATABASE_SENTINEL";\'';
$process = proc_open(['bash', '-c', $script, 'capture-private-test', $root, $sink],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
wprism_check(is_resource($process), 'actual private-capture shell started');
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
wprism_check_same(0, proc_close($process), 'actual native capture succeeds');
wprism_check_same('', $stdout, 'raw native stdout is never replayed publicly');
wprism_check_same('', $stderr, 'private native streams stay out of public diagnostics');
wprism_check_same('PRIVATE_DATABASE_SENTINEL', file_get_contents($sink . '/probe.stdout'), 'complete raw native bytes stay in the private sink');
wprism_check_same(0600, fileperms($sink . '/probe.stdout') & 0777, 'native evidence sink retains private file mode');
wprism_check_summary('regress_wpforms_location_provider_evidence');
