<?php
declare(strict_types=1);

// Synthetic host-admission controls, never claimed as native plugin evidence.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/provider-evidence.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once $root . '/agent/src/Kernel/BoundedChildProcess.php';

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
        $posts[$name] = ['id' => $id, 'type' => $type, 'status' => $status,
            'writer_status' => $name === 'excluded_attachment' ? 'inherit' : $status, 'title' => 'WPrism ' . $name,
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
    $after['inputs']['posts'] = array_values(array_map(static fn(array $post): array => ['ID' => (string) $post['id'],
        'post_type' => $post['type'], 'post_status' => $post['status'], 'post_title' => $post['title'], 'post_content' => '[wpforms id="1"]'], $posts));
    foreach ($forms as $name => $id) {
        $settings = $name === 'form_page' ? ['form_pages_enable' => true, 'form_pages_page_slug' => 'standalone-page'] : [];
        $after['inputs']['posts'][] = ['ID' => (string) $id, 'post_type' => 'wpforms', 'post_status' => 'publish',
            'post_title' => 'WPrism ' . $name, 'post_content' => json_encode(['id' => $id, 'settings' => $settings], JSON_THROW_ON_ERROR)];
    }
    $after['inputs']['posts'][] = ['ID' => '130', 'post_type' => 'wpforms-template', 'post_status' => 'publish',
        'post_title' => 'WPrism template exclusion', 'post_content' => '{}'];
    usort($after['inputs']['posts'], static fn(array $a, array $b): int => (int) $a['ID'] <=> (int) $b['ID']);
    $after['inputs']['options'][] = ['option_id' => '1', 'option_name' => 'widget_wpforms-widget',
        'option_value' => serialize([2 => ['form_id' => '1', 'title' => ''], '_multiwidget' => 1]), 'autoload' => 'yes'];
    $before = $after;
    $before['owned'] = [['meta_id' => '1', 'post_id' => '1', 'meta_key' => 'wpforms_form_locations', 'meta_value' => 'stale'],
        ['meta_id' => '2', 'post_id' => '1', 'meta_key' => 'wpforms_form_locations', 'meta_value' => 'duplicate']];
    $boot = static fn(int $pid, bool $child): array => ['pid' => $pid, 'boot' => str_pad((string) $pid, 32, '0', STR_PAD_LEFT), 'child' => $child];
    $projection = static fn(array $state): array => ['inputs_sha256' => str_repeat('a', 64),
        'locations_rows' => count($state['owned']), 'locations_sha256' => hash('sha256', serialize($state['owned'])),
        'remainder_sha256' => hash('sha256', serialize($state['remainder']))];
    $writer = array_map(static fn(array $value): array => ['id' => $value['id'], 'locations' => $value['locations']], $native);
    $seed = $base + ['template_standalone' => [], 'native' => $writer, 'widgets' => $widgets, 'posts' => $posts, 'physical' => $before, 'boot' => $boot(10, false)];
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
$reordered = $model;
foreach ([1, 3] as $index) {
    foreach (['before', 'after'] as $side) $reordered[$index]['receipt'][$side] = array_reverse($reordered[$index]['receipt'][$side], true);
}
WPFormsLocationProviderEvidence::verify(...$reordered);
wprism_check(true, 'closed receipt schema is independent of JSON object key insertion order');
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
    'unrecorded dirty attachment provenance' => static function (array &$r): void { $r[0]['posts']['excluded_attachment']['writer_status'] = 'publish'; },
    'claimed status differs from physical row' => static function (array &$r): void {
        foreach ($r[0]['physical']['inputs']['posts'] as &$post) {
            if ((int) $post['ID'] === $r[0]['posts']['excluded_attachment']['id']) $post['post_status'] = 'inherit';
        }
    },
    'extra provider projection field' => static function (array &$r): void { $r[1]['receipt']['after']['unexpected'] = true; },
    'wrong default native title' => static function (array &$r): void { $r[2]['native']['embeds']['html'] = str_replace('WPForms Widget', 'wrong', $r[2]['native']['embeds']['html']); },
    'warm writer masquerades as fresh UI evidence' => static function (array &$r): void { $r[0]['native']['embeds']['html'] = $r[2]['native']['embeds']['html']; },
    'fresh consumer loses rendered row' => static function (array &$r): void {
        $r[2]['native']['embeds']['html'] = substr($r[2]['native']['embeds']['html'], strlen('<span class="wpforms-locations-list-item"></span>'));
    },
    'fresh retry changes consumer markup' => static function (array &$r): void { $r[4]['native']['embeds']['html'] .= 'changed'; },
    'wrong empty native column' => static function (array &$r): void { $r[2]['native']['no_locations']['html'] = '0'; },
    'changed artifact on retry' => static function (array &$r): void { $r[3]['artifact']['artifact_hash'] = str_repeat('b', 64); },
];
foreach ($mutations as $name => $mutate) {
    $changed = $model;
    $mutate($changed);
    wprism_check_throws(static fn() => WPFormsLocationProviderEvidence::verify(...$changed), RuntimeException::class,
        'host rejects ' . $name, 'WPForms native provider evidence refused:');
}

/** Actual kernel graph framing around synthetic transport, not a child run. */
function location_provider_failure_graph(array $report): array {
    $transport = WPrism\BoundedChildProcess::failure_evidence(
        'wprism: manifest-provider fresh process did not complete cleanly; recovery_required',
        ['return_code' => 1, 'stdout' => json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'stderr' => "wprism-provider-operation-failed\n"]);
    return WPrism\PrivateRefusalEvidence::graph(new WPrism\PrivateEvidenceException(
        "wprism: provider 'wpforms-form-locations' capability 'rebuild_form_locations' failed", $transport));
}

function location_provider_refusal_model(string $case, array $positive): array {
    $baseline = $positive[4]['physical'];
    $property = $case === 'null-widget-title' ? 'options' : 'posts';
    $column = $property === 'posts' ? 'post_content' : 'option_value';
    $key = $property === 'posts' ? 'ID' : 'option_id';
    $target = match ($case) { 'malformed-body' => '2', 'unsafe-standalone-uri' => '3', 'null-widget-title' => '1', default => '100' };
    $index = array_search($target, array_column($baseline['inputs'][$property], $key), true);
    $old = $baseline['inputs'][$property][$index][$column];
    $new = match ($case) {
        'missing-embed' => '[wpforms id="2147483646"]', 'nonform-embed' => '[wpforms id="100"]', 'template-embed' => '[wpforms id="130"]',
        'malformed-body' => '{', 'null-widget-title' => serialize([2 => ['form_id' => '1', 'title' => null], '_multiwidget' => 1]),
        'unsafe-standalone-uri' => json_encode(['id' => 3, 'settings' => ['form_pages_enable' => true, 'form_pages_page_slug' => 'a%3Fb']], JSON_THROW_ON_ERROR),
    };
    $dirty = $baseline;
    $dirty['inputs'][$property][$index][$column] = $new;
    $message = match ($case) {
        'missing-embed', 'nonform-embed', 'template-embed' => 'wprism: WPForms locations a native placement references a missing or non-form post',
        'malformed-body' => 'wprism: WPForms location source has malformed form JSON',
        'null-widget-title' => 'wprism: WPForms locations native location text is malformed or over the bounded frontier',
        'unsafe-standalone-uri' => 'wprism: WPForms locations native URL is outside the current-home renderer frontier',
    };
    $failure = new RuntimeException($message, 0, $case === 'malformed-body' ? new JsonException('Syntax error') : null);
    $report = ['evidence' => WPrism\PrivateRefusalEvidence::graph($failure),
        'format' => 'wprism-provider-operation-failure/v1', 'request_sha256' => str_repeat('c', 64)];
    $header = static fn(string $phase): array => ['format' => 'wprism-wpforms-native-provider/v1',
        'phase' => $phase, 'case' => $case, 'version' => '2.0.1.1', 'home' => 'http://wpfmodel1.invalid'];
    $boot = static fn(int $pid, bool $child = false): array => ['pid' => $pid,
        'boot' => str_pad((string) $pid, 32, '0', STR_PAD_LEFT), 'child' => $child];
    $prepared = $header('refusal-seed') + ['before' => $baseline,
        'mutation' => ['table' => $property, 'identity' => [$key => $target], 'column' => $column, 'before' => $old, 'after' => $new],
        'parser' => match ($case) { 'missing-embed' => [2147483646], 'nonform-embed' => [100], 'template-embed' => [130], default => null },
        'physical' => $dirty, 'boot' => $boot(20)];
    $invoke = $header('invoke') + ['artifact' => $positive[1]['artifact'], 'before' => $dirty, 'receipt' => null,
        'failure' => location_provider_failure_graph($report), 'after' => $dirty, 'children' => [$boot(22, true)], 'boot' => $boot(21)];
    $observed = $header('physical') + ['physical' => $dirty, 'boot' => $boot(23)];
    $restored = $header('refusal-restore') + ['before' => $dirty, 'physical' => $baseline, 'boot' => $boot(24)];
    return [$positive, $prepared, $invoke, $observed, $restored];
}

foreach (['missing-embed', 'nonform-embed', 'template-embed', 'malformed-body', 'null-widget-title', 'unsafe-standalone-uri'] as $case) {
    $refusalModel = location_provider_refusal_model($case, $model);
    WPFormsLocationProviderEvidence::verifyRefusal($case, ...$refusalModel);
    wprism_check(true, 'synthetic complete refusal model admitted (not a native run): ' . $case);
    $mutateReport = static function (array &$r, callable $change): void {
        $report = json_decode($r[2]['failure']['throwable'][5]['message'], true, 32, JSON_THROW_ON_ERROR);
        $change($report);
        $r[2]['failure'] = location_provider_failure_graph($report);
    };
    $faults = [
        'wrong case' => static function (array &$r): void { $r[1]['case'] = 'unrelated'; },
        'wrong home' => static function (array &$r): void { $r[2]['home'] = 'http://foreign1.invalid'; },
        'extra phase field' => static function (array &$r): void { $r[2]['accepted'] = true; },
        'missing positive record' => static function (array &$r): void { array_pop($r[0]); },
        'unverified positive baseline' => static function (array &$r): void { $r[0][1]['receipt']['verified'] = false; },
        'stale baseline' => static function (array &$r): void { $r[1]['before']['owned'][0]['meta_value'] = 'stale'; },
        'wrong mutation coordinate' => static function (array &$r): void { $r[1]['mutation']['column'] = 'post_title'; },
        'unchanged claimed input' => static function (array &$r): void { $r[1]['mutation']['after'] = $r[1]['mutation']['before']; },
        'wrong parser witness' => static function (array &$r): void { $r[1]['parser'] = ['unrelated']; },
        'unexpected dirty input' => static function (array &$r): void { $r[1]['physical']['inputs']['posts'][0]['post_title'] = 'changed'; },
        'changed input during refusal' => static function (array &$r): void { $r[2]['after']['inputs']['posts'][0]['post_title'] = 'changed'; },
        'changed owned row' => static function (array &$r): void { $r[2]['after']['owned'][0]['meta_value'] = 'changed'; },
        'changed nonowned row' => static function (array &$r): void { $r[2]['after']['remainder'][0]['meta_value'] = 'changed'; },
        'fresh readback disagreement' => static function (array &$r): void { $r[3]['physical']['owned'][0]['meta_value'] = 'changed'; },
        'bad restored state' => static function (array &$r): void { $r[4]['physical']['remainder'][0]['meta_value'] = 'changed'; },
        'bad restore preimage' => static function (array &$r): void { $r[4]['before']['owned'] = []; },
        'changed compiled identity' => static function (array &$r): void { $r[2]['artifact']['artifact_hash'] = str_repeat('b', 64); },
        'success receipt on refusal' => static function (array &$r): void { $r[2]['receipt'] = ['verified' => true]; },
        'missing failure' => static function (array &$r): void { $r[2]['failure'] = null; },
        'public wrapper alone' => static function (array &$r): void { $r[2]['failure']['throwable'] = [$r[2]['failure']['throwable'][0]]; },
        'no child' => static function (array &$r): void { $r[2]['children'] = []; },
        'unexpected success observer' => static function (array &$r): void { $r[2]['children'][] = $r[2]['children'][0]; },
        'parent posed as child' => static function (array &$r): void { $r[2]['children'][0]['pid'] = $r[2]['boot']['pid']; },
        'reused observation boot' => static function (array &$r): void { $r[3]['boot'] = $r[1]['boot']; },
        'wrong report format' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void { $report['format'] = 'unrelated'; }); },
        'extra report field' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void { $report['accepted'] = true; }); },
        'invalid request identity' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void { $report['request_sha256'] = 'invalid'; }); },
        'wrong exact cause' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void {
            $report['evidence'] = WPrism\PrivateRefusalEvidence::graph(new RuntimeException('unrelated failure'));
        }); },
        'incomplete inner traversal' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void { $report['evidence']['traversal']['record_complete'] = false; }); },
        'wrong inner message digest' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void { $report['evidence']['throwable'][0]['message_sha256'] = str_repeat('0', 64); }); },
        'encoded inner message' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void { $report['evidence']['throwable'][0]['message_encoding'] = 'base64'; }); },
        'truncated inner message' => static function (array &$r) use ($mutateReport): void { $mutateReport($r, static function (array &$report): void { $report['evidence']['throwable'][0]['message_truncated'] = true; }); },
        'multiple child reports' => static function (array &$r): void { $r[2]['failure']['throwable'][6]['message'] = $r[2]['failure']['throwable'][5]['message']; },
        'truncated report field' => static function (array &$r): void { $r[2]['failure']['throwable'][5]['message_truncated'] = true; },
        'report digest disagreement' => static function (array &$r): void { $r[2]['failure']['throwable'][5]['message_sha256'] = str_repeat('0', 64); },
        'wrong exit evidence' => static function (array &$r): void { $r[2]['failure']['throwable'][2]['message'] = 'wprism: child process return_code=0'; },
        'incomplete outer traversal' => static function (array &$r): void { $r[2]['failure']['traversal']['record_complete'] = false; },
    ];
    foreach ($faults as $fault => $mutate) {
        $changed = $refusalModel;
        $mutate($changed);
        wprism_check_throws(static fn() => WPFormsLocationProviderEvidence::verifyRefusal($case, ...$changed),
            RuntimeException::class, $case . ' refuses ' . $fault);
    }
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
