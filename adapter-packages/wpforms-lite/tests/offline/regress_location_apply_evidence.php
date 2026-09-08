<?php
declare(strict_types=1);

// Mutate the owner's actual admission code. These are verifier fixtures, not
// fabricated native evidence or an alternative Apply implementation.
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/apply-evidence.php';
$pointer = 'private command diagnostics (unverified): /owned/root/sandbox/tmp/wprism-conformance-apply.wpfprov05.a1B2c3';
$transport = WPFormsApplyEvidence::stderrPattern('wpfprov05', '/owned/root', 'baseline-apply');
wprism_check(preg_match($transport, $pointer) === 1, 'exact shared Apply diagnostic pointer is admitted');
wprism_check(preg_match($transport, ' Container wprism-wpfprov05-cli2-run-abcdef123456 Created ') === 1, 'exact pair Compose prelude remains admitted');
foreach (['foreign root' => str_replace('/owned/root', '/foreign/root', $pointer),
    'foreign pair' => str_replace('wpfprov05', 'foreign', $pointer),
    'foreign verb' => str_replace('-apply.', '-capture.', $pointer),
    'warning after pointer' => $pointer . ' PHP Warning: unexpected',
    'traversal' => $pointer . '/../other'] as $label => $line) {
    wprism_check(preg_match($transport, $line) === 0, 'diagnostic admission refuses ' . $label);
}
wprism_check(preg_match(WPFormsApplyEvidence::stderrPattern('wpfprov05', '/owned/root', 'baseline-repeat'), $pointer) === 1,
    'repeat binds the actual Apply verb');
wprism_check(preg_match(WPFormsApplyEvidence::stderrPattern('wpfprov05', '/owned/root', 'baseline-recapture'), str_replace('-apply.', '-capture.', $pointer)) === 1,
    'recapture binds the actual Capture verb');
wprism_check(preg_match(WPFormsApplyEvidence::stderrPattern('wpfprov05', '/owned/root', 'baseline-source'), $pointer) === 0,
    'native observation cannot smuggle a public-command diagnostic pointer');
wprism_check(preg_match(WPFormsApplyEvidence::stderrPattern('wpfprov05', '/owned/root', 'refusal-apply'), $pointer) === 1,
    'negative Apply retains the same exact public-command diagnostic pointer');
$action = ['manifest' => 'wpforms-lite', 'index' => 0, 'kind' => 'provider',
    'provider' => 'wpforms-form-locations', 'capability' => 'rebuild_form_locations', 'args' => [],
    'effects' => [['id' => 'location-rows']]];
$identity = ['manifest' => 'wpforms-lite', 'index' => 0, 'declaration_hash' => hash('sha256', WPrism\Canon::encode($action))];
$contract = ['format' => 'wprism-scope-contract/v1', 'code_diagnostic' => null,
    'source' => ['artifact_hash' => str_repeat('a', 64)],
    'potential_actions' => [['manifest' => 'wpforms-lite', 'index' => 0, 'source' => 'manifest:wpforms-lite:action:0', 'declaration' => $action]]];
$plan = array_fill_keys(['adopt', 'conflict', 'collision', 'drift', 'delete', 'delete_conflict', 'code_mismatch', 'code_drift',
    'incomplete_apply', 'incomplete_lifecycle', 'missing_user'], []);
$plan['selected_actions'] = [$identity];
$plan['artifact_hash'] = str_repeat('a', 64);
$plan['adapter_dispositions'] = [
    ['name' => 'wpforms-lite', 'status' => 'blocked', 'code' => 'authored_state_not_certified'],
    ['name' => 'wpforms-lite', 'status' => 'blocked', 'code' => 'operation_not_certified'],
];
$apply = ['canary' => 'clean', 'verification' => ['result' => 'pass'], 'drift' => [], 'applied' => 1,
    'artifact' => ['hash' => str_repeat('a', 64)],
    'warnings' => ['provider capability fired: wpforms-form-locations@1.0.0 rebuild_form_locations (0.5s, verified)'],
    'actions' => [['source' => 'manifest:wpforms-lite:action:0', 'kind' => 'provider', 'manifest' => 'wpforms-lite', 'verified' => true]]];
$repeat = array_replace($apply, ['applied' => 0, 'warnings' => [], 'actions' => []]);
WPFormsApplyEvidence::selection($contract, $plan, $apply, $repeat);
wprism_check(true, 'actual admission accepts the documented public declaration/identity projections');
$adoptPlan = $plan;
$adoptPlan['adopt'] = [['type' => 'post', 'env_id' => 23, 'uuid' => '10000000-0000-4000-8000-000000000001', 'path' => 'posts/page/placement.md'],
    ['type' => 'term', 'env_id' => 4, 'uuid' => '10000000-0000-4000-8000-000000000002', 'path' => 'terms/category/example.json']];
$adoptApply = $apply;
$adoptApply['warnings'][] = 'adopted env post 23 as 10000000-0000-4000-8000-000000000001 (posts/page/placement.md)';
$adoptApply['warnings'][] = 'adopted env term 4 as 10000000-0000-4000-8000-000000000002 (terms/category/example.json)';
WPFormsApplyEvidence::selection($contract, $adoptPlan, $adoptApply, $repeat);
wprism_check(true, 'planned post and term adoptions have exact informational receipts');
foreach (['missing event' => static function (&$p, &$a): void { array_pop($a['warnings']); },
    'duplicate event' => static function (&$p, &$a): void { $a['warnings'][] = $a['warnings'][1]; },
    'wrong native ID' => static function (&$p, &$a): void { $p['adopt'][0]['env_id'] = 99; },
    'wrong canonical ID' => static function (&$p, &$a): void { $p['adopt'][0]['uuid'] .= 'x'; },
    'wrong path' => static function (&$p, &$a): void { $p['adopt'][0]['path'] = 'other'; },
    'unplanned adoption' => static function (&$p, &$a): void { array_pop($p['adopt']); },
    'hidden warning' => static function (&$p, &$a): void { $a['warnings'][] = 'unresolved reference'; }] as $label => $mutate) {
    [$p, $a] = [$adoptPlan, $adoptApply];
    $mutate($p, $a);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::selection($contract, $p, $a, $repeat), RuntimeException::class,
        'actual adoption event admission refuses ' . $label, 'WPForms Apply evidence:');
}
$cases = [
    'descriptor' => static function (&$c, &$p, &$a, &$r): void { $c['code_diagnostic'] = ['code_revision' => str_repeat('a', 64)]; },
    'missing diagnostic' => static function (&$c, &$p, &$a, &$r): void { unset($c['code_diagnostic']); },
    'form-only trigger' => static function (&$c, &$p, &$a, &$r): void { $c['potential_actions'][0]['declaration']['triggers'] = ['post:wpforms']; },
    'dropped action' => static function (&$c, &$p, &$a, &$r): void { $p['selected_actions'] = []; },
    'changed effects' => static function (&$c, &$p, &$a, &$r): void { $c['potential_actions'][0]['declaration']['effects'] = []; },
    'private fields in plan' => static function (&$c, &$p, &$a, &$r): void { $p['selected_actions'][0]['provider'] = 'wpforms-form-locations'; },
    'duplicate action' => static function (&$c, &$p, &$a, &$r): void { $p['selected_actions'][] = $p['selected_actions'][0]; },
    'promotion erased' => static function (&$c, &$p, &$a, &$r): void { $p['adapter_dispositions'] = []; },
    'wrong disposition status' => static function (&$c, &$p, &$a, &$r): void { $p['adapter_dispositions'][0]['status'] = 'unsupported'; },
    'runtime blocker' => static function (&$c, &$p, &$a, &$r): void { $p['adapter_dispositions'][1]['code'] = 'provider_requirement_unmet'; },
    'wrong artifact' => static function (&$c, &$p, &$a, &$r): void { $a['artifact']['hash'] = str_repeat('b', 64); },
    'wrong plan artifact' => static function (&$c, &$p, &$a, &$r): void { $p['artifact_hash'] = str_repeat('b', 64); },
    'code stale' => static function (&$c, &$p, &$a, &$r): void { $p['code_mismatch'][] = ['issue' => 'code_revision_stale']; },
    'target drift' => static function (&$c, &$p, &$a, &$r): void { $p['drift'][] = ['path' => 'posts/page/example.md']; },
    'no authored work' => static function (&$c, &$p, &$a, &$r): void { $a['applied'] = 0; },
    'skipped provider' => static function (&$c, &$p, &$a, &$r): void { $a['actions'] = []; },
    'wrong receipt' => static function (&$c, &$p, &$a, &$r): void { $a['actions'][0]['source'] = 'other'; },
    'unverified receipt' => static function (&$c, &$p, &$a, &$r): void { $a['actions'][0]['verified'] = false; },
    'dirty canary' => static function (&$c, &$p, &$a, &$r): void { $a['canary'] = 'dirty'; },
    'failed convergence' => static function (&$c, &$p, &$a, &$r): void { $a['verification']['result'] = 'fail'; },
    'warning' => static function (&$c, &$p, &$a, &$r): void { $a['warnings'][] = 'unresolved reference'; },
    'retry writes' => static function (&$c, &$p, &$a, &$r): void { $r['applied'] = 1; },
    'retry action' => static function (&$c, &$p, &$a, &$r): void { $r['actions'] = $a['actions']; },
];
foreach ($cases as $label => $mutate) {
    [$c, $p, $a, $r] = [$contract, $plan, $apply, $repeat];
    $mutate($c, $p, $a, $r);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::selection($c, $p, $a, $r), RuntimeException::class,
        'actual public-path admission refuses ' . $label, 'WPForms Apply evidence:');
}

$observation = static function (string $case, bool $target, bool $empty = false): array {
    $home = $target ? 'http://target.invalid' : 'http://source.invalid';
    $posts = [];
    foreach (['integer', 'string', 'template', 'destination', 'embed'] as $i => $role) {
        $posts[$role] = ['id' => ($target ? 100 : 10) + $i,
            'uuid' => sprintf('10000000-0000-4000-8000-%012d', $i + 1),
            'type' => in_array($role, ['integer', 'string'], true) ? 'wpforms' : ($role === 'template' ? 'wpforms-template' : 'page'),
            'slug' => 'wprism-wpf-' . $role, 'status' => 'publish', 'title' => 'WPrism WPForms ' . $role,
            'body' => '{}', 'url' => $home . '/wprism-wpf-' . $role . '/'];
    }
    $integer = $posts['integer']['id'];
    $string = $posts['string']['id'];
    $posts['embed']['body'] = ($case === 'baseline' ? '[wpforms id="' . $integer . '" title="true"]' . "\n" : '')
        . '<!-- wp:wpforms/form-selector {"formId":"' . $string . '","displayTitle":true} /-->';
    if ($case === 'routing') {
        $posts['embed']['title'] = 'Renamed placement Ω';
        $posts['embed']['slug'] = 'wprism-wpf-embed-renamed';
        $posts['embed']['url'] = $home . '/wprism-wpf-embed-renamed/';
    }
    $families = ['wpforms-widget' => [99 => ['title' => 'Local orphan', 'form_id' => (string) $integer]], 'block' => []];
    $blockFormIds = [];
    if ($target) {
        $families['block'][99] = ['content' => '<!-- wp:paragraph --><p>Unrelated core widget</p><!-- /wp:paragraph -->'];
        $blockFormIds[99] = [];
    }
    if (in_array($case, ['baseline', 'embeds'], true)) {
        foreach ([1, 2] as $offset) {
            $number = ($target ? 70 : 10) + $offset;
            $families['block'][$number] = ['content' => '<!-- wp:paragraph --><p>Managed core ' . $offset . '</p><!-- /wp:paragraph -->'];
            $blockFormIds[$number] = [];
        }
    }
    $widgets = [['type' => 'widget', 'title' => 'Local orphan', 'form_id' => (string) $integer, 'id' => 'wpforms-widget-99']];
    if ($empty) {
        $families['wpforms-widget'] = [];
        $widgets = [];
    }
    if (in_array($case, ['widgets', 'routing'], true)) {
        $families['wpforms-widget'][100] = ['title' => 'Apply widget', 'form_id' => (string) $integer];
        $families['block'][3] = ['content' => '<!-- wp:wpforms/form-selector {"formId":"' . $integer . '"} /-->'];
        $blockFormIds[3] = [$integer];
        $widgets[] = ['type' => 'widget', 'title' => 'Apply widget', 'form_id' => (string) $integer, 'id' => 'wpforms-widget-100'];
        $widgets[] = ['type' => 'widget', 'title' => 'Block Widget', 'form_id' => $integer, 'id' => 'block-3'];
    }
    $native = $owned = [];
    foreach (['integer', 'string'] as $role) {
        $form = $posts[$role]['id'];
        $locations = [];
        if ($case === 'baseline' || $role === 'string') $locations[] = ['type' => 'page', 'title' => $posts['embed']['title'],
            'form_id' => $form, 'id' => $posts['embed']['id'], 'status' => 'publish', 'url' => substr($posts['embed']['url'], strlen($home))];
        if ($role === 'integer') $locations = array_merge($locations, $widgets);
        $native[$role] = ['locations' => $locations === [] ? '' : $locations,
            'column' => $locations === [] ? '—' : str_repeat('class="wpforms-locations-list-item"', count($locations)),
            'rendered' => 'id="wpforms-form-' . $form . '" data-token-time="1788860225" name="wpforms[fields][1]" name="wpforms[fields][2]"'];
        if ($locations !== []) $owned[] = ['meta_id' => count($owned) + 1, 'post_id' => $form, 'meta_key' => 'wpforms_form_locations', 'meta_value' => serialize($locations)];
    }
    return ['format' => 'wprism-wpforms-apply-observation/v1', 'case' => $case, 'version' => '2.0.1.1', 'home' => $home,
        'posts' => $posts, 'native' => $native, 'widgets' => $widgets, 'widget_options' => $families, 'owned' => $owned,
        'block_form_ids' => $blockFormIds,
        'padding' => array_map(static fn(int $id): array => ['ID' => $id, 'post_type' => 'post', 'post_status' => 'trash'], range(200, 206))];
};
$prior = $observation('baseline', true);
foreach (['baseline', 'embeds', 'widgets', 'routing'] as $case) {
    $source = $observation($case, false);
    $target = $observation($case, true);
    $stable = $target;
    foreach (['integer', 'string'] as $role) {
        $stable['native'][$role]['rendered'] = str_replace('1788860225', '1788860231', $stable['native'][$role]['rendered']);
    }
    WPFormsApplyEvidence::native($case, $source, $prior, $target, $stable);
    wprism_check(true, 'native admission accepts complete ' . $case . ' verifier fixture');
    $prior = $target;
}
foreach (['missing' => static function (&$a): void { unset($a['widget_options']['block'][71], $a['block_form_ids'][71]); },
    'duplicate' => static function (&$a): void { $a['widget_options']['block'][73] = $a['widget_options']['block'][71]; $a['block_form_ids'][73] = []; },
    'changed' => static function (&$a): void { $a['widget_options']['block'][71]['content'] .= ' changed'; },
    'missing source roster' => static function (&$a, &$s): void { unset($s['block_form_ids'][11]); },
    'dropped row hidden by wrong source ID' => static function (&$a, &$s): void {
        unset($a['widget_options']['block'][71], $a['block_form_ids'][71]);
        $s['block_form_ids'][11] = [999];
    },
    'dropped row hidden by valid ID on ordinary source content' => static function (&$a, &$s): void {
        unset($a['widget_options']['block'][71], $a['block_form_ids'][71]);
        $s['block_form_ids'][11] = [$s['posts']['integer']['id']];
    },
    'premature source WPForms block' => static function (&$a, &$s): void {
        unset($a['widget_options']['block'][71], $a['block_form_ids'][71]);
        $s['widget_options']['block'][11] = ['content' => '<!-- wp:wpforms/form-selector {"formId":"' . $s['posts']['integer']['id'] . '"} /-->'];
        $s['block_form_ids'][11] = [$s['posts']['integer']['id']];
    },
    'source selector hidden by empty roster' => static function (&$a, &$s): void {
        $s['widget_options']['block'][11] = ['content' => '<!-- wp:wpforms/form-selector {"formId":"' . $s['posts']['integer']['id'] . '"} /-->'];
        $a['widget_options']['block'][71] = $s['widget_options']['block'][11];
    }] as $label => $mutate) {
    $s = $observation('baseline', false);
    $b = $observation('baseline', true);
    $a = $b;
    $mutate($a, $s);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::native('baseline', $s, $b, $a, $a), RuntimeException::class,
        'reallocated managed core-widget multiset refuses ' . $label, 'WPForms Apply evidence:');
}
$nativeCases = [
    'missing render timestamp' => static function (&$s, &$b, &$a, &$r): void { $r['native']['integer']['rendered'] = str_replace(' data-token-time="1788860225"', '', $r['native']['integer']['rendered']); },
    'duplicate render timestamp' => static function (&$s, &$b, &$a, &$r): void { $r['native']['integer']['rendered'] .= ' data-token-time="1788860231"'; },
    'malformed render timestamp' => static function (&$s, &$b, &$a, &$r): void { $r['native']['integer']['rendered'] = str_replace('1788860225', 'not-a-time', $r['native']['integer']['rendered']); },
    'non-token markup changed' => static function (&$s, &$b, &$a, &$r): void { $r['native']['integer']['rendered'] .= ' changed'; },
    'unrelated block changed' => static function (&$s, &$b, &$a, &$r): void { $a['widget_options']['block'][99]['content'] .= ' changed'; $r = $a; },
    'unrelated block lost' => static function (&$s, &$b, &$a, &$r): void { unset($a['widget_options']['block'][99], $a['block_form_ids'][99]); $r = $a; },
    'missing block roster row' => static function (&$s, &$b, &$a, &$r): void { unset($a['block_form_ids'][99]); $r = $a; },
    'additional block roster row' => static function (&$s, &$b, &$a, &$r): void { $a['block_form_ids'][9] = []; $r = $a; },
    'duplicate block form ID' => static function (&$s, &$b, &$a, &$r): void { $a['block_form_ids'][3][] = $a['posts']['integer']['id']; $r = $a; },
    'malformed block form ID' => static function (&$s, &$b, &$a, &$r): void { $a['block_form_ids'][3] = [true]; $r = $a; },
    'forged empty block roster' => static function (&$s, &$b, &$a, &$r): void { $a['block_form_ids'][3] = []; $r = $a; },
    'forged unrelated form roster' => static function (&$s, &$b, &$a, &$r): void { $a['block_form_ids'][99] = [$a['posts']['integer']['id']]; $r = $a; },
    'duplicate source WPForms block' => static function (&$s, &$b, &$a, &$r): void {
        $s['widget_options']['block'][4] = $s['widget_options']['block'][3];
        $s['block_form_ids'][4] = $s['block_form_ids'][3];
    },
    'missing source WPForms block' => static function (&$s, &$b, &$a, &$r): void { unset($s['widget_options']['block'][3], $s['block_form_ids'][3]); },
    'source WPForms block has extra settings' => static function (&$s, &$b, &$a, &$r): void { $s['widget_options']['block'][3]['extra'] = 'unproved'; },
    'same IDs' => static function (&$s, &$b, &$a, &$r): void { $a['posts']['integer']['id'] = $s['posts']['integer']['id']; },
    'unmapped ID' => static function (&$s, &$b, &$a, &$r): void { $a['posts']['integer']['uuid'] = null; },
    'lost trash' => static function (&$s, &$b, &$a, &$r): void { array_pop($a['padding']); },
    'lost local widget' => static function (&$s, &$b, &$a, &$r): void { unset($a['widget_options']['wpforms-widget'][99]); },
    'retry row mutation' => static function (&$s, &$b, &$a, &$r): void { $r['owned'][0]['meta_id'] = 999; },
    'form body rewritten' => static function (&$s, &$b, &$a, &$r): void { $a['posts']['integer']['body'] = '{"changed":true}'; $r = $a; },
    'source embed ID' => static function (&$s, &$b, &$a, &$r): void { $a['posts']['embed']['body'] = $s['posts']['embed']['body']; $r = $a; },
    'wrong routing' => static function (&$s, &$b, &$a, &$r): void { $a['posts']['embed']['slug'] = 'old'; $r = $a; },
    'boolean widget ref' => static function (&$s, &$b, &$a, &$r): void { $a['widget_options']['wpforms-widget'][100]['form_id'] = true; $r = $a; },
    'lost native row' => static function (&$s, &$b, &$a, &$r): void { array_pop($a['native']['integer']['locations']); $r = $a; },
    'missing UI row' => static function (&$s, &$b, &$a, &$r): void { $a['native']['integer']['column'] = ''; $r = $a; },
    'source render ID' => static function (&$s, &$b, &$a, &$r): void { $a['native']['integer']['rendered'] = $s['native']['integer']['rendered']; $r = $a; },
    'physical duplicate' => static function (&$s, &$b, &$a, &$r): void { $a['owned'][] = $a['owned'][0]; $r = $a; },
    'malformed physical bytes' => static function (&$s, &$b, &$a, &$r): void { $a['owned'][0]['meta_value'] = 'a:broken'; $r = $a; },
    'object physical bytes' => static function (&$s, &$b, &$a, &$r): void { $a['owned'][0]['meta_value'] = 'O:8:"stdClass":0:{}'; $r = $a; },
    'wrong physical type' => static function (&$s, &$b, &$a, &$r): void { $a['owned'][0]['meta_value'] = null; $r = $a; },
    'wrong physical owner' => static function (&$s, &$b, &$a, &$r): void { $a['owned'][0]['meta_key'] = 'unrelated'; $r = $a; },
    'wrong block title' => static function (&$s, &$b, &$a, &$r): void { $a['widgets'][2]['title'] = ''; $r = $a; },
];
foreach ($nativeCases as $label => $mutate) {
    [$s, $b, $a, $r] = [$observation('routing', false), $observation('widgets', true), $observation('routing', true), $observation('routing', true)];
    $mutate($s, $b, $a, $r);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::native('routing', $s, $b, $a, $r), RuntimeException::class,
        'actual native admission refuses ' . $label, 'WPForms Apply evidence:');
}
$emptyBefore = $observation('baseline', true, true);
$emptyBefore['case'] = 'before';
foreach (['posts', 'native', 'content_roster', 'owned', 'widgets'] as $field) $emptyBefore[$field] = [];
$emptyBefore['sidebars'] = ['sidebar-1' => ['block-71', 'block-72'], 'wp_inactive_widgets' => [], 'array_version' => 3];
WPFormsApplyEvidence::emptyBefore($emptyBefore);
wprism_check(true, 'empty-content preimage has real local witnesses, not fabricated source post IDs');
$prior = $emptyBefore;
foreach (['baseline', 'embeds', 'widgets', 'routing'] as $case) {
    $source = $observation($case, false, true);
    $target = $observation($case, true, true);
    WPFormsApplyEvidence::native($case, $source, $prior, $target, $target, 'empty');
    wprism_check(true, 'empty-target native admission accepts ' . $case . ' including genuinely absent location rows');
    $prior = $target;
}
foreach (['hidden native form' => static function (&$b): void { $b['content_roster'][] = ['post_type' => 'wpforms', 'post_status' => 'trash']; },
    'fake mapped posts' => static function (&$b): void { $b['posts']['integer'] = ['id' => 7]; },
    'owned location row' => static function (&$b): void { $b['owned'][] = ['post_id' => 7]; },
    'orphan widget' => static function (&$b): void { $b['widget_options']['wpforms-widget'][99] = ['form_id' => 7]; },
    'native widget location' => static function (&$b): void { $b['widgets'][] = ['id' => 'wpforms-widget-2']; },
    'sidebar-only widget' => static function (&$b): void { $b['sidebars']['sidebar-1'][] = 'wpforms-widget-2'; },
    'block-hidden selector' => static function (&$b): void { $b['widget_options']['block'][71]['content'] = '<!-- wp:wpforms/form-selector {"formId":"7"} /-->'; },
    'block roster missing' => static function (&$b): void { unset($b['block_form_ids'][71]); },
    'block roster nonempty' => static function (&$b): void { $b['block_form_ids'][71] = [7]; },
    'lost core witness' => static function (&$b): void { unset($b['widget_options']['block'][99], $b['block_form_ids'][99]); },
    'duplicate trash witness' => static function (&$b): void { $b['padding'][1] = $b['padding'][0]; },
    'capturable padding' => static function (&$b): void { $b['padding'][0]['post_status'] = 'publish'; }] as $label => $mutate) {
    $b = $emptyBefore;
    $mutate($b);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::emptyBefore($b), RuntimeException::class,
        'empty-content admission refuses ' . $label, 'WPForms Apply evidence:');
}
$unlocated = $observation('embeds', true, true);
foreach (['stored empty row' => static function (&$a): void {
    $a['owned'][] = ['meta_id' => 9, 'post_id' => $a['posts']['integer']['id'], 'meta_key' => 'wpforms_form_locations', 'meta_value' => serialize([])];
}, 'wrong native absence' => static function (&$a): void { $a['native']['integer']['locations'] = []; },
    'wrong empty column' => static function (&$a): void { $a['native']['integer']['column'] = ''; }] as $label => $mutate) {
    $a = $unlocated;
    $mutate($a);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::native('embeds', $observation('embeds', false, true),
        $observation('baseline', true, true), $a, $a, 'empty'), RuntimeException::class,
        'unlocated form admission refuses ' . $label, 'WPForms Apply evidence:');
}
$diagnostics = [['code' => 'semantic_delete_reference', 'path' => 'posts/page/10000000-0000-4000-8000-000000000005--wprism-wpf-embed.md',
    'locator' => 'body', 'message' => 'reference target 10000000-0000-4000-8000-000000000099 is absent from the compiled revision']];
$refusal = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'apply',
    'error' => 'repository_compilation_failed', 'reason_code' => 'repository_compilation_failed',
    'message' => 'repository compilation refused this command', 'remediation' => 'repair the source graph', 'diagnostics' => $diagnostics];
WPFormsApplyEvidence::refused($emptyBefore, $emptyBefore, $refusal, $diagnostics);
wprism_check(true, 'typed compiler refusal binds complete diagnostics and unchanged empty native witnesses');
foreach (['wrong command' => static function (&$a, &$r, &$d): void { $r['command'] = 'plan'; },
    'success envelope' => static function (&$a, &$r, &$d): void { $r['ok'] = true; },
    'unclassified error' => static function (&$a, &$r, &$d): void { $r['reason_code'] = 'apply_failed'; },
    'redacted unknown cause' => static function (&$a, &$r, &$d): void { $r['details_redacted'] = true; },
    'provider executed' => static function (&$a, &$r, &$d): void { $r['actions'] = [['manifest' => 'wpforms-lite']]; },
    'diagnostic hidden' => static function (&$a, &$r, &$d): void { $r['diagnostics'] = []; },
    'wrong native diagnostic' => static function (&$a, &$r, &$d): void { $r['diagnostics'][0]['path'] = 'other'; },
    'wrong host cause' => static function (&$a, &$r, &$d): void { $d[0]['code'] = 'malformed_reference'; $r['diagnostics'] = $d; },
    'lost target-local bytes' => static function (&$a, &$r, &$d): void { $a['padding'][0]['unexpected'] = 'changed'; },
    'assigned core widgets changed' => static function (&$a, &$r, &$d): void { $a['sidebars']['sidebar-1'] = []; }] as $label => $mutate) {
    [$a, $r, $d] = [$emptyBefore, $refusal, $diagnostics];
    $mutate($a, $r, $d);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::refused($emptyBefore, $a, $r, $d), RuntimeException::class,
        'native refusal admission rejects ' . $label, 'WPForms Apply evidence:');
}
wprism_check_summary('regress_wpforms_location_apply_evidence');
