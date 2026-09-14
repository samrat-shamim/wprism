<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';

use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\Tooling\AdapterPackageValidator;

AdapterPackageValidator::validate($root, 'disable-comments');
$policy = Policy::load(null, ['core', 'disable-comments'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'disable-comments'));

$parent = $policy->option_rule('disable_comments_options');
wprism_check_same('env', $parent['class'] ?? null, 'the shared settings blob remains environment-owned');
wprism_check_same(false, $parent['required'] ?? null, 'the plugin may populate the settings blob without scalar provisioning');

$expected = [
    'remove_rest_API_comments',
    'remove_xmlrpc_comments',
];
$subKeys = $parent['sub_keys'] ?? [];
foreach ($expected as $subKey) {
    wprism_check_same('authored', $subKeys[$subKey]['class'] ?? null, "$subKey is carried as authored intent");
    wprism_check_same(true, $subKeys[$subKey]['lint_ok'] ?? null, "$subKey is explicitly protected from bare-ID lint false positives");
}

wprism_check(isset($policy->sub_keyed_options()['disable_comments_options']), 'the product policy exposes the shared blob through sub-key ownership');
foreach (['remove_everywhere', 'show_existing_comments', 'enable_exclude_by_role', 'disabled_post_types', 'extra_post_types', 'conditional_rules', 'allowed_comment_types', 'blocked_comment_types'] as $subKey) {
    wprism_check(!array_key_exists($subKey, $subKeys), "$subKey remains unclaimed rather than being guessed portable");
}

foreach (['disable_comment_version', 'disable_comments_blocked_since', 'disable_comments_blocked_stats_comment', 'disable_comments_blocked_stats_rest', 'disable_comments_blocked_stats_trackback', 'disable_comments_review_trigger'] as $option) {
    wprism_check_same('runtime', $policy->option_rule($option)['class'] ?? null, "$option remains runtime-owned");
}
wprism_check_same('runtime', $policy->user_meta_rule('disable_comments_review_dismissed')['class'] ?? null, 'review dismissal remains target-local user metadata');

wprism_check_summary('regress_disable_comments_classification_boundary');
