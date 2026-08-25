<?php
declare(strict_types=1);

/**
 * Child-process fixture for Polylang's same-apply media-support boundary.
 *
 * The production-readiness suite already loads adapter classes against a
 * deliberately tiny fake Policy. This fixture needs the real Policy,
 * CompiledRepository and TaxonomyApplyContext graph, so it runs separately
 * and publishes only the resolved ownership rows as JSON.
 */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

$root = dirname(__DIR__, 3);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/CompiledArtifact.php';
require_once $root . '/agent/src/Apply/TaxonomyApplyContext.php';

function get_taxonomy(string $taxonomy): object|false {
    if (!in_array($taxonomy, ['language', 'post_translations'], true)) {
        return false;
    }
    // Hostile target process booted while media_support was disabled.
    return (object) ['object_type' => ['post', 'page', 'wp_block']];
}

/** @return array<string,mixed> */
function polylang_media_ownership(mixed $mediaSupport, array $postTypes): array {
    $policy = new \Duo\Policy();
    $policy->site = ['policy' => ['taxonomies' => ['language', 'post_translations']]];
    $optionDeclarations = [
        ['option' => 'polylang', 'sub_key' => 'post_types'],
        [
            'option' => 'polylang',
            'sub_key' => 'media_support',
            'object_types_when_truthy' => ['attachment'],
        ],
    ];
    $policy->manifests = [[
        'taxonomies' => [
            'language' => [
                'object_keyspace' => 'post',
                'object_type_from_option' => $optionDeclarations,
            ],
            'post_translations' => [
                'object_keyspace' => 'post',
                'object_type_from_option' => $optionDeclarations,
            ],
        ],
    ]];
    $compiled = \Duo\CompiledRepository::create([
        'tree' => [
            'options/core' => [
                'type' => 'options',
                'data' => \Duo\OptionState::document([
                    'polylang' => \Duo\OptionState::present([
                        'media_support' => $mediaSupport,
                        'post_types' => $postTypes,
                    ], 'yes'),
                ]),
            ],
        ],
    ]);
    $warnings = [];
    return (new \Duo\TaxonomyApplyContext($policy, $compiled))->ownership($warnings)
        + ['warnings' => $warnings];
}

echo \Duo\Canon::encode([
    'disabled' => polylang_media_ownership(0, ['book']),
    'enabled' => polylang_media_ownership(1, []),
    'malformed' => polylang_media_ownership('1', []),
]);
