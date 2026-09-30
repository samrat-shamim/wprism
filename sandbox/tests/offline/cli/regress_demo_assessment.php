<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once dirname(__DIR__, 4) . '/cli/src/Command/DemoCommand.php';
require_once dirname(__DIR__, 4) . '/cli/src/Release/AuthorizationPlan.php';

use WPrism\Orchestrator\AuthorizationPlan;
use WPrism\Orchestrator\DemoCommand;
use WPrism\Orchestrator\ProjectionVocabulary;

$scratch = sys_get_temp_dir() . '/wprism-demo-assessment-' . bin2hex(random_bytes(6));
mkdir($scratch . '/cli', 0700, true);
mkdir($scratch . '/repo', 0700);
$method = new ReflectionMethod(DemoCommand::class, 'assertCoreAssessment');
$invoke = static function (array $view, int $exit = 3) use ($scratch, $method): array {
    file_put_contents($scratch . '/view.json', json_encode($view, JSON_THROW_ON_ERROR));
    file_put_contents($scratch . '/cli/wprism', "#!/bin/sh\ncat " . escapeshellarg($scratch . '/view.json') . "\nexit $exit\n");
    chmod($scratch . '/cli/wprism', 0700);
    return $method->invoke(null, ['source_repo' => $scratch . '/repo'], $scratch);
};

// Native core assessment (WordPress 7.1): page is Ready, while the runtime
// option group and both comment tables are Unsupported because they stay local.
$page = [
    'readiness' => 'Ready', 'state_class' => 'authored', 'handling' => 'manage',
    'effect_containment' => 'prevented', 'blockers' => [], 'conditions' => [],
];
$runtime = [
    'readiness' => 'Unsupported', 'state_class' => 'runtime', 'handling' => 'preserve local',
    'certification_provenance' => 'Platform-certified', 'effect_containment' => 'prevented',
    'blockers' => [], 'conditions' => [],
    'annotations' => [ProjectionVocabulary::ANNOTATION_PRESERVE_LOCAL_UNSUPPORTED],
];
$surface = static fn(string $id, array $projection): array => [
    'kind' => 'surface', 'surface' => ['id' => $id, 'operations' => ['release' => $projection]],
];
$view = [
    'format' => 'wprism-assess-view/v1',
    'summary' => [
        'readiness' => 'blocked',
        'counts' => [
            'invisible_option_names' => 0, 'pending_classifications' => 0,
            'undeclared_tables' => 0, 'unknown_names' => 0, 'rows' => 4, 'surfaces' => 4,
        ],
        'dispositions' => ['agree' => true],
    ],
    'page' => ['shown' => 4, 'offset' => 0, 'remaining' => 0, 'has_more' => false, 'next_cursor' => null],
    'rows' => [
        $surface('post_type:page', $page),
        $surface('option_group:core:runtime', $runtime),
        $surface('table:commentmeta', $runtime),
        $surface('table:comments', $runtime),
    ],
];

try {
    try {
        $summary = $invoke($view);
        wprism_check_same('blocked', $summary['readiness'], 'page demo retains the native whole-site runtime boundaries');
    } catch (RuntimeException $error) {
        wprism_check(false, 'page demo accepts its native preserve-local assessment: ' . $error->getMessage());
    }

    $cases = [];
    $bad = $view;
    $bad['rows'][0]['surface']['operations']['release']['readiness'] = 'Not qualified';
    $cases['page not qualified'] = [$bad, 3];
    $bad = $view;
    $bad['rows'][0]['surface']['operations']['release']['handling'] = 'block';
    $cases['page handling blocked'] = [$bad, 3];
    $bad = $view;
    $bad['rows'][0]['surface']['operations']['release']['effect_containment'] = 'unknown';
    $cases['page containment unknown'] = [$bad, 3];
    $bad = $view;
    $bad['rows'][0]['surface']['id'] = 'post_type:post';
    $cases['page absent'] = [$bad, 3];
    $bad = $view;
    $bad['rows'][1]['surface']['id'] = 'option_group:unknown:runtime';
    $cases['unreviewed runtime surface'] = [$bad, 3];
    foreach ([
        'blockers' => ['surface_explicitly_unsupported'],
        'conditions' => ['provider negotiation required'],
        'certification_provenance' => 'Uncertified',
        'annotations' => [],
        'effect_containment' => 'unknown',
        'readiness' => 'Ready',
    ] as $field => $value) {
        $bad = $view;
        $bad['rows'][1]['surface']['operations']['release'][$field] = $value;
        $cases['runtime ' . $field . ' drift'] = [$bad, 3];
    }
    $bad = $view;
    $bad['rows'][3] = $bad['rows'][2];
    $cases['duplicate hides a runtime table'] = [$bad, 3];
    $bad = $view;
    $bad['page']['has_more'] = true;
    $bad['page']['remaining'] = 1;
    $cases['unshown blocker'] = [$bad, 3];
    foreach (['pending_classifications', 'invisible_option_names', 'undeclared_tables', 'unknown_names'] as $field) {
        $bad = $view;
        $bad['summary']['counts'][$field] = 1;
        $cases[$field] = [$bad, 3];
    }
    $bad = $view;
    $bad['summary']['counts']['rows'] = 5;
    $cases['incomplete row count'] = [$bad, 3];
    $bad = $view;
    $bad['summary']['dispositions']['agree'] = false;
    $cases['library mismatch'] = [$bad, 3];
    $cases['exit status contradicts boundaries'] = [$view, 0];
    $cases['assessment itself refused'] = [$view, 1];
    foreach ($cases as $name => [$bad, $exit]) {
        try {
            $invoke($bad, $exit);
            wprism_check(false, 'demo refuses ' . $name);
        } catch (RuntimeException $error) {
            wprism_check(str_contains($error->getMessage(), 'complete bounded release assessment'), 'demo refuses ' . $name);
        }
    }
} finally {
    unlink($scratch . '/view.json');
    unlink($scratch . '/cli/wprism');
    rmdir($scratch . '/cli');
    rmdir($scratch . '/repo');
    rmdir($scratch);
}
$parse = new ReflectionMethod(DemoCommand::class, 'lastJsonDocument');
$plan = ['format' => AuthorizationPlan::FORMAT, 'scope' => ['surfaces' => ['post_type:page']]];
$canonical = AuthorizationPlan::encode($plan);
foreach ([
    'native multiline formatter' => "authorization plan for demo-target\n" . $canonical,
    'compact JSON' => "authorization plan for demo-target\n" . json_encode($plan),
    'earlier JSON diagnostic' => "{\"diagnostic\":true}\n\n" . $canonical,
] as $name => $output) {
    try {
        wprism_check_same($plan, $parse->invoke(null, $output), 'demo reads the final plan from ' . $name);
    } catch (RuntimeException $error) {
        wprism_check(false, 'demo reads the final plan from ' . $name . ': ' . $error->getMessage());
    }
}
foreach ([
    'truncated JSON' => substr(trim($canonical), 0, -1),
    'trailing diagnostic' => $canonical . "preview did not finish\n",
    'no JSON' => "authorization plan unavailable\n",
] as $name => $output) {
    try {
        $parse->invoke(null, $output);
        wprism_check(false, 'demo refuses ' . $name);
    } catch (RuntimeException $error) {
        wprism_check(str_contains($error->getMessage(), 'no final JSON document'), 'demo refuses ' . $name);
    }
}
wprism_check_summary('page demo assessment and preview boundaries');
