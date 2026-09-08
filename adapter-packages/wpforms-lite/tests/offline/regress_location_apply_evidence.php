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
$action = ['manifest' => 'wpforms-lite', 'index' => 0, 'kind' => 'provider',
    'provider' => 'wpforms-form-locations', 'capability' => 'rebuild_form_locations', 'args' => [],
    'effects' => [['id' => 'location-rows']]];
$identity = ['manifest' => 'wpforms-lite', 'index' => 0, 'declaration_hash' => hash('sha256', WPrism\Canon::encode($action))];
$contract = ['format' => 'wprism-scope-contract/v1', 'code_diagnostic' => null,
    'source' => ['artifact_hash' => str_repeat('a', 64)],
    'potential_actions' => [['manifest' => 'wpforms-lite', 'index' => 0, 'source' => 'manifest:wpforms-lite:action:0', 'declaration' => $action]]];
$plan = array_fill_keys(['conflict', 'collision', 'drift', 'delete', 'delete_conflict', 'code_mismatch', 'code_drift',
    'incomplete_apply', 'incomplete_lifecycle', 'missing_user'], []);
$plan['selected_actions'] = [$identity];
$plan['artifact_hash'] = str_repeat('a', 64);
$plan['adapter_dispositions'] = [
    ['name' => 'wpforms-lite', 'status' => 'unsupported', 'code' => 'authored_state_not_certified'],
    ['name' => 'wpforms-lite', 'status' => 'unsupported', 'code' => 'operation_not_certified'],
];
$apply = ['canary' => 'clean', 'verification' => ['result' => 'pass'], 'drift' => [], 'applied' => 1,
    'artifact' => ['hash' => str_repeat('a', 64)],
    'warnings' => ['provider capability fired: wpforms-form-locations@1.0.0 rebuild_form_locations (0.5s, verified)'],
    'actions' => [['source' => 'manifest:wpforms-lite:action:0', 'kind' => 'provider', 'manifest' => 'wpforms-lite', 'verified' => true]]];
$repeat = array_replace($apply, ['applied' => 0, 'warnings' => [], 'actions' => []]);
WPFormsApplyEvidence::selection($contract, $plan, $apply, $repeat);
wprism_check(true, 'actual admission accepts the documented public declaration/identity projections');
$cases = [
    'descriptor' => static function (&$c, &$p, &$a, &$r): void { $c['code_diagnostic'] = ['code_revision' => str_repeat('a', 64)]; },
    'missing diagnostic' => static function (&$c, &$p, &$a, &$r): void { unset($c['code_diagnostic']); },
    'form-only trigger' => static function (&$c, &$p, &$a, &$r): void { $c['potential_actions'][0]['declaration']['triggers'] = ['post:wpforms']; },
    'dropped action' => static function (&$c, &$p, &$a, &$r): void { $p['selected_actions'] = []; },
    'changed effects' => static function (&$c, &$p, &$a, &$r): void { $c['potential_actions'][0]['declaration']['effects'] = []; },
    'private fields in plan' => static function (&$c, &$p, &$a, &$r): void { $p['selected_actions'][0]['provider'] = 'wpforms-form-locations'; },
    'duplicate action' => static function (&$c, &$p, &$a, &$r): void { $p['selected_actions'][] = $p['selected_actions'][0]; },
    'promotion erased' => static function (&$c, &$p, &$a, &$r): void { $p['adapter_dispositions'] = []; },
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

$observation = static function (string $case, bool $target): array {
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
    $widgets = [['type' => 'widget', 'title' => 'Local orphan', 'form_id' => (string) $integer, 'id' => 'wpforms-widget-99']];
    if (in_array($case, ['widgets', 'routing'], true)) {
        $families['wpforms-widget'][100] = ['title' => 'Apply widget', 'form_id' => (string) $integer];
        $families['block'][3] = ['content' => '<!-- wp:wpforms/form-selector {"formId":"' . $integer . '"} /-->'];
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
        $native[$role] = ['locations' => $locations, 'column' => str_repeat('class="wpforms-locations-list-item"', count($locations)),
            'rendered' => 'id="wpforms-form-' . $form . '" name="wpforms[fields][1]" name="wpforms[fields][2]"'];
        $owned[] = ['meta_id' => count($owned) + 1, 'post_id' => $form, 'meta_key' => 'wpforms_form_locations', 'meta_value' => serialize($locations)];
    }
    return ['format' => 'wprism-wpforms-apply-observation/v1', 'case' => $case, 'version' => '2.0.1.1', 'home' => $home,
        'posts' => $posts, 'native' => $native, 'widgets' => $widgets, 'widget_options' => $families, 'owned' => $owned,
        'padding' => array_map(static fn(int $id): array => ['ID' => $id, 'post_status' => 'trash'], range(200, 206))];
};
$prior = $observation('baseline', true);
foreach (['baseline', 'embeds', 'widgets', 'routing'] as $case) {
    $source = $observation($case, false);
    $target = $observation($case, true);
    WPFormsApplyEvidence::native($case, $source, $prior, $target, $target);
    wprism_check(true, 'native admission accepts complete ' . $case . ' verifier fixture');
    $prior = $target;
}
$nativeCases = [
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
wprism_check_summary('regress_wpforms_location_apply_evidence');
