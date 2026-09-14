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

$expected = [
    'enable_exclude_by_role',
    'remove_everywhere',
    'remove_rest_API_comments',
    'remove_xmlrpc_comments',
    'show_existing_comments',
];
$subKeys = $parent['sub_keys'] ?? [];
foreach ($expected as $subKey) {
    wprism_check_same('authored', $subKeys[$subKey]['class'] ?? null, "$subKey is carried as authored intent");
    wprism_check_same(true, $subKeys[$subKey]['lint_ok'] ?? null, "$subKey is explicitly protected from bare-ID lint false positives");
}

wprism_check(isset($policy->sub_keyed_options()['disable_comments_options']), 'the product policy exposes the shared blob through sub-key ownership');
foreach (['disabled_post_types', 'extra_post_types', 'conditional_rules', 'allowed_comment_types', 'blocked_comment_types', 'db_version'] as $subKey) {
    wprism_check(!array_key_exists($subKey, $subKeys), "$subKey remains unclaimed rather than being guessed portable");
}

wprism_check_summary('regress_disable_comments_classification_boundary');
