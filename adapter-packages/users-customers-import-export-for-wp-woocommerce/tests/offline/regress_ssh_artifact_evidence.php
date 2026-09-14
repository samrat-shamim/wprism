<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/ssh-artifact-evidence.php';

$inactive = ['version' => null, 'active' => false, 'loaded' => false, 'marker' => null,
    'tables' => ['wt_iew_mapping_template' => false, 'wt_iew_action_history' => false]];
$active = ['version' => '2.7.5', 'active' => true, 'loaded' => false, 'marker' => '1',
    'tables' => ['wt_iew_mapping_template' => true, 'wt_iew_action_history' => true]];
$states = ['initial' => $inactive, 'prior' => array_replace($inactive, ['version' => '2.7.4']),
    'supported' => array_replace($inactive, ['version' => '2.7.5']), 'activated' => $active,
    'admin' => array_replace($active, ['loaded' => true])];
ImporterSshArtifactEvidence::verify($states);
wprism_check(true, 'exact inactive artifacts precede raw native activation and separate admin loading');
foreach (['missing-stage', 'wrong-version', 'early-active', 'early-loaded', 'early-marker', 'early-table',
    'missing-marker', 'missing-table', 'observer-loaded', 'admin-not-loaded'] as $fault) {
    $bad = $states;
    switch ($fault) {
        case 'missing-stage': unset($bad['prior']); break;
        case 'wrong-version': $bad['prior']['version'] = '2.7.5'; break;
        case 'early-active': $bad['supported']['active'] = true; break;
        case 'early-loaded': $bad['supported']['loaded'] = true; break;
        case 'early-marker': $bad['supported']['marker'] = '1'; break;
        case 'early-table': $bad['supported']['tables']['wt_iew_mapping_template'] = true; break;
        case 'missing-marker': $bad['activated']['marker'] = null; break;
        case 'missing-table': $bad['activated']['tables']['wt_iew_action_history'] = false; break;
        case 'observer-loaded': $bad['activated']['loaded'] = true; break;
        case 'admin-not-loaded': $bad['admin']['loaded'] = false; break;
    }
    wprism_check_throws(static fn() => ImporterSshArtifactEvidence::verify($bad), RuntimeException::class,
        'actual SSH artifact oracle rejects ' . $fault);
}
wprism_check_summary('Importer SSH artifact evidence');
