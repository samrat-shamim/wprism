<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$sourceRoot = $argv[1] ?? $root;
$source = (string) file_get_contents($sourceRoot . '/adapter-packages/redirection/tests/conformance/check.sh');
$answer = ['warnings' => ['native action fired: fixture (verified)'], 'canary' => 'clean',
    'verification' => ['result' => 'pass'], 'applied' => 1, 'plan' => ['conflict' => 1, 'env_missing' => 1],
    'actions' => [['source' => 'provider:redirection-state/rebuild_redirect_state', 'verified' => true]]];
foreach ([
    ['ZERO_APPLY', "pass 'zero-change", array_replace($answer, ['actions' => [], 'applied' => 0])],
    ['FORCED', 'redirection_request /summer', $answer],
    ['RECOVERY', '[ "$(wp_conf2 db query', $answer],
] as [$variable, $end, $payload]) {
    ShellProbe::positiveApply($root, ShellProbe::captureBlock($source, $variable, $end), $payload, 'Redirection ' . $variable);
}
wprism_check_summary('regress_redirection_positive_apply');
