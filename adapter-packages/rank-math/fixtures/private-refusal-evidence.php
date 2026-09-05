<?php

declare(strict_types=1);

// pair.yml:195 runs this as the target CLI uid without loading WordPress;
// PrivateRefusalEvidence.php:15-18 deliberately makes the 0700/0600 store
// unreadable to a different native-Linux host identity.

const WPRISM_RANK_MATH_PRIVATE_REFUSAL_FORMAT = 'wprism-rank-math-private-refusal-check/v1';
const WPRISM_RANK_MATH_PRIVATE_REFUSAL_PROFILES = [
    'virgin-schema' => [
        'command' => 'plan',
        'reason_code' => 'plan_failed',
        'message' => "wprism: declared table 'rank_math_redirections' does not exist on this environment "
            . '(plugin inactive, or manifest stale?)',
    ],
    'missing-code' => [
        'command' => 'lifecycle-status',
        'reason_code' => 'lifecycle_status_failed',
        'message' => "wprism: deploy refused — code_mismatch:\n\n"
            . "  - active_plugins in state/options/core.json declares 'seo-by-rank-math/rank-math.php' "
            . 'but seo-by-rank-math/rank-math.php does not exist in this environment (checked against '
            . "this environment's wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' "
            . "means 'installed on the env'). Install/vendor the plugin here, or this branch's code/ changes "
            . "haven't reached this environment yet.\n\n"
            . 'Install/vendor whatever is missing (or update code/) in this environment first, or pass '
            . '--force-code-mismatch to proceed anyway.',
    ],
    'below-range' => [
        'command' => 'lifecycle-status',
        'reason_code' => 'lifecycle_status_failed',
        'message' => "wprism: deploy refused — code_mismatch:\n\n"
            . "  - seo-by-rank-math/rank-math.php 1.0.276 is active in this environment, outside the 'rank-math' "
            . "manifest's declared version_range (>=1.0.277 <1.0.277.3, pinned by site.wprism.json). "
            . 'Classification guarantees for this plugin are NOT validated against this version — apply may '
            . 'silently misclassify fields. Update the plugin, pin an older manifest, or pass '
            . "--force-code-mismatch to proceed at your own risk.\n\n"
            . 'Install/vendor whatever is missing (or update code/) in this environment first, or pass '
            . '--force-code-mismatch to proceed anyway.',
    ],
    'schema-loss' => [
        'command' => 'schema-status',
        'reason_code' => 'schema_status_failed',
        'message' => "wprism: schema-settle table 'rank_math_redirections' is absent but durable identity "
            . 'history remains; restore the database-matched table instead of preparing an empty replacement',
    ],
    'schema-mismatch' => [
        'command' => 'schema-settle',
        'reason_code' => 'schema_settle_failed',
        'message' => "wprism: provider 'rank-math-state' capability 'inspect_schema' failed",
        'private_cause_message' => 'wprism: Rank Math schema disagrees with the audited column/index contract',
    ],
];

function rank_math_private_refusal_fail(string $message): never
{
    fwrite(STDERR, "wprism-rank-math-private-refusal: $message\n");
    exit(1);
}

/** @return array{command: string, reason_code: string, message: string, private_cause_message?: string} */
function rank_math_private_refusal_profile(string $name): array
{
    $profile = WPRISM_RANK_MATH_PRIVATE_REFUSAL_PROFILES[$name] ?? null;
    if (!is_array($profile)) {
        rank_math_private_refusal_fail('unknown verification profile');
    }
    return $profile;
}

function rank_math_private_refusal_receipt(string $profileName = 'schema-loss'): string
{
    $profile = rank_math_private_refusal_profile($profileName);
    $receipt = [
        'command' => $profile['command'],
        'format' => WPRISM_RANK_MATH_PRIVATE_REFUSAL_FORMAT,
        'new_records' => 1,
        'root_message_sha256' => hash('sha256', $profile['message']),
    ];
    if (isset($profile['private_cause_message'])) {
        $receipt['private_cause_message_sha256'] = hash('sha256', $profile['private_cause_message']);
    }
    $receipt['verified'] = true;
    return json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @return array<string,mixed> */
function rank_math_private_refusal_graph_profile(string $profileName): array
{
    $profile = rank_math_private_refusal_profile($profileName);
    $nodes = [[
        'parent_index' => null,
        'relation' => 'root',
        'class' => isset($profile['private_cause_message']) ? 'WPrism\PrivateEvidenceException' : 'RuntimeException',
        'message' => $profile['message'],
    ]];
    if (isset($profile['private_cause_message'])) {
        $nodes[] = [
            'parent_index' => 0,
            'relation' => 'private_evidence',
            'class' => 'RuntimeException',
            'message' => $profile['private_cause_message'],
        ];
    }
    return ['command' => $profile['command'], 'reason_code' => $profile['reason_code'], 'nodes' => $nodes];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

// Compose mounts this exact candidate test library read-only for these
// invocations only. No sandbox bytes are assembled into the plugin package or
// loaded through WordPress, and a missing mount has no local/runtime fallback.
$library = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$profileName = $argv[3] ?? '';
$directory = $argv[4] ?? '';
if (!in_array($mode, ['snapshot', 'verify'], true)
    || count($argv) !== ($mode === 'snapshot' ? 5 : 6) || $directory === '') {
    rank_math_private_refusal_fail(
        'usage: private-refusal-evidence.php <test-library> <snapshot|verify> <profile> <directory> [baseline-json]'
    );
}
$profile = rank_math_private_refusal_graph_profile($profileName);
if ($library === '' || !is_file($library) || !is_readable($library)) {
    rank_math_private_refusal_fail('the explicitly mounted shared test library is unavailable');
}
require_once $library;
try {
    if ($mode === 'snapshot') {
        echo WPrismTest\PrivateRefusalReceipt::snapshot($directory, $profile), "\n";
    } else {
        WPrismTest\PrivateRefusalReceipt::verify($directory, $argv[5], $profile);
        echo rank_math_private_refusal_receipt($profileName), "\n";
    }
} catch (RuntimeException $failure) {
    rank_math_private_refusal_fail($failure->getMessage());
}
