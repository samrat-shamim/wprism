<?php
declare(strict_types=1);
function aio_private_profile(string $case): array {
    $plugin = 'change-wp-admin-login/change-wp-admin-login.php';
    $missing = "active_plugins in state/options/core.json declares '$plugin' but "
        . "$plugin does not exist in this environment (checked against this environment's "
        . "wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' means "
        . "'installed on the env'). Install/vendor the plugin here, or this branch's code/ "
        . "changes haven't reached this environment yet.";
    $version = "$plugin 2.4.0 is active in this environment, outside the 'change-wp-admin-login' manifest's "
        . "declared version_range (>=2.4.1 <2.4.2, pinned by site.wprism.json). "
        . 'Classification guarantees for this plugin are NOT validated against this '
        . 'version — apply may silently misclassify fields. Update the plugin, pin an '
        . 'older manifest, or pass --force-code-mismatch to proceed at your own risk.';
    $compatibility = static fn(string $finding): string => "wprism: deploy refused — code_mismatch:\n\n  - $finding\n\n"
        . 'Install/vendor whatever is missing (or update code/) in this environment first, '
        . 'or pass --force-code-mismatch to proceed anyway.';
    [$command,$message] = match($case) {
        'missing-code' => ['deploy', $compatibility($missing)],
        'old-version' => ['deploy', $compatibility($version)],
        'conflict' => ['apply', "wprism: conflicts (env and repo both changed since last sync) — capture first or --force-theirs:\n  - options/core.json"],
        'delete' => ['apply', "wprism: authored option deletion intent requires --with-deletes; no target mutation attempted:\n  - aio_login_custom-css"],
        'pro-residue' => ['capture', 'wprism: AIO Login free redirect contract refused: user, role, and priority rules require a separately qualified Pro adapter'],
        default => throw new RuntimeException('unknown AIO refusal case'),
    };
    return ['command'=>$command,'reason_code'=>$command.'_failed','nodes'=>[['parent_index'=>null,'relation'=>'root','class'=>'RuntimeException','message'=>$message]]];
}
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
[$root,$pair,$case,$directory] = array_slice($argv,1);
require_once $root.'/sandbox/tests/lib/PrivateCommandOutput.php';
require_once $root.'/sandbox/tests/lib/PrivateRefusalReceipt.php';
$profile = aio_private_profile($case);
$service = $case === 'pro-residue' ? 'cli1' : 'cli2';
if (preg_match('/\A[a-z][a-z0-9]*\z/', $pair) !== 1) throw new RuntimeException('invalid evidence pair');
$prelude = '/\A Container wprism-'.preg_quote($pair,'/').'-'.$service.'-run-[a-f0-9]{12} (?:Creating|Created) \z/';
$diagnostic = json_decode(WPrismTest\PrivateCommandOutput::readObject($directory.'/private',$prelude,WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE),true,512,JSON_THROW_ON_ERROR);
echo WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($diagnostic,$profile),"\n";
