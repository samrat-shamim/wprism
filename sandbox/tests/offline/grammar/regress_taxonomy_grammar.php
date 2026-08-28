<?php
/**
 * Offline regression for TaxonomyGrammar (DUO-3348 slice 10: taxonomy
 * declaration validators extracted from Policy.php).
 *
 * regress_taxonomy_object_keyspace.php already covers the complete product
 * path through Policy::load()/from_snapshot(), including runtime resolution,
 * lint diagnostics, and repository compiler refusals. This direct suite adds
 * characterization of the manifest-only declaration grammar and proves the
 * moved validators are not duplicated on Policy.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Grammar/TaxonomyGrammar.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use Duo\Policy;
use Duo\TaxonomyGrammar;
use DuoTest\FrozenPolicy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$assertThrows = static function (callable $fn, string $needle, string $label) use ($check): void {
    try {
        $fn();
        $check(false, "$label: expected a refusal, none thrown");
    } catch (\RuntimeException $e) {
        $check(
            str_contains($e->getMessage(), $needle),
            "$label: refusal names \"$needle\" (got: {$e->getMessage()})"
        );
    }
};

$assertAccepted = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(true, "$label: accepted");
    } catch (\Throwable $e) {
        $check(false, "$label: unexpectedly refused ({$e->getMessage()})");
    }
};

/** Build the smallest frozen-policy envelope the real validator accepts. */
$frozenSnapshot = static function (array $manifest): array {
    return FrozenPolicy::envelope([$manifest], FrozenPolicy::site([$manifest]));
};

$assertAccepted(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => [
            'acme_posts' => ['object_keyspace' => 'post'],
            'acme_terms' => ['object_keyspace' => 'term'],
        ],
        'taxonomy_patterns' => [
            ['match' => '^pa_', 'object_keyspace' => 'term'],
            ['match' => '^legacy_'],
        ],
    ]),
    'exact and pattern declarations accept the closed post|term keyspaces'
);
$assertAccepted(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['acme_posts' => ['class' => 'authored']],
    ]),
    'a manifest without object_keyspace declarations is accepted'
);
$assertAccepted(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations(['name' => 'acme']),
    'a manifest without taxonomies or taxonomy_patterns is accepted'
);

$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['acme_posts' => ['object_keyspace' => 'user']],
    ]),
    'must be one of post|term',
    'exact taxonomy object_keyspace rejects an unsupported relationship keyspace'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomy_patterns' => [['match' => '^pa_', 'object_keyspace' => 7]],
    ]),
    'must be one of post|term',
    'pattern object_keyspace rejects a non-string value'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomy_patterns' => 'not-a-list',
    ]),
    'taxonomy_patterns that is not a list',
    'taxonomy_patterns rejects a scalar declaration'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomy_patterns' => [['match' => '[']],
    ]),
    'with an invalid or empty regex',
    'taxonomy_patterns rejects an invalid match regex'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomy_patterns' => [['match' => '^pa_', 'object_keyspace' => 'user']],
    ]),
    'taxonomy_patterns[0].object_keyspace must be one of post|term',
    'taxonomy_patterns reports the indexed declaration path'
);

// ----------------------------------------------- object_type_from_option

$assertAccepted(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'options' => [
            'acme_settings' => [
                'sub_keys' => ['object_type' => ['class' => 'authored']],
            ],
        ],
        'taxonomies' => [
            'acme_tax' => [
                'object_type_from_option' => [
                    'option' => 'acme_settings',
                    'sub_key' => 'object_type',
                ],
            ],
        ],
    ]),
    'object_type_from_option accepts a plain locally-declared option sub-key'
);
$assertAccepted(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'options' => [
            'acme_settings' => [
                'sub_keys' => [
                    'object_types' => ['class' => 'authored'],
                    'media_enabled' => ['class' => 'authored'],
                ],
            ],
        ],
        'taxonomies' => [
            'acme_tax' => [
                'object_type_from_option' => [
                    ['option' => 'acme_settings', 'sub_key' => 'object_types'],
                    [
                        'option' => 'acme_settings',
                        'sub_key' => 'media_enabled',
                        'object_types_when_truthy' => ['attachment'],
                    ],
                ],
            ],
        ],
    ]),
    'object_type_from_option accepts ordered array and strict truthy-gate contributions'
);
$assertAccepted(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'taxonomies' => [
            'acme_tax' => [
                'object_type_from_option' => [
                    'option' => 'owned-by-another-manifest',
                    'sub_key' => 'object_type',
                ],
            ],
        ],
    ]),
    'object_type_from_option preserves the cross-manifest ownership boundary'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'taxonomies' => ['acme_tax' => ['object_type_from_option' => ['option' => 'acme_settings']]],
    ]),
    'without both a non-empty string',
    'object_type_from_option refuses a declaration missing sub_key'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'options' => ['acme_settings' => ['sub_keys' => ['other' => ['class' => 'authored']]]],
        'taxonomies' => ['acme_tax' => ['object_type_from_option' => [
            'option' => 'acme_settings', 'sub_key' => 'object_type',
        ]]],
    ]),
    'never declares that key',
    'object_type_from_option refuses an undeclared local sub-key'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'options' => ['acme_settings' => ['sub_keys' => [
            'object_type' => ['class' => 'authored', 'json_refs' => ['post' => []]],
        ]]],
        'taxonomies' => ['acme_tax' => ['object_type_from_option' => [
            'option' => 'acme_settings', 'sub_key' => 'object_type',
        ]]],
    ]),
    'json_refs/key_refs',
    'object_type_from_option refuses a ref-typed local sub-key'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'taxonomies' => ['acme_tax' => ['object_type_from_option' => []]],
    ]),
    'not a declaration object or non-empty declaration list',
    'object_type_from_option refuses an empty contribution list'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'options' => ['acme_settings' => ['sub_keys' => [
            'media_enabled' => ['class' => 'authored'],
        ]]],
        'taxonomies' => ['acme_tax' => ['object_type_from_option' => [[
            'option' => 'acme_settings',
            'sub_key' => 'media_enabled',
            'object_types_when_truthy' => [],
        ]]]],
    ]),
    'without a non-empty list of object type names',
    'object_type_from_option refuses a truthy gate that authorizes no object type'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'options' => ['acme_settings' => ['sub_keys' => [
            'media_enabled' => ['class' => 'authored'],
        ]]],
        'taxonomies' => ['acme_tax' => ['object_type_from_option' => [[
            'option' => 'acme_settings',
            'sub_key' => 'media_enabled',
            'object_types_when_truthy' => ['attachment', 'attachment'],
        ]]]],
    ]),
    'with duplicate object types',
    'object_type_from_option refuses duplicate truthy-gate ownership'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_object_type_option_refs([
        'name' => 'acme',
        'taxonomies' => ['acme_tax' => ['object_type_from_option' => [[
            'option' => 'acme_settings',
            'sub_key' => 'media_enabled',
            'truthy_object_types' => ['attachment'],
        ]]]],
    ]),
    "with unsupported key 'truthy_object_types'",
    'object_type_from_option refuses a misspelled conditional authority key'
);

// ------------------------------------------------------ frozen Policy entry point

$assertAccepted(
    static fn() => FrozenPolicy::fromEnvelope($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'taxonomies' => ['acme_posts' => ['object_keyspace' => 'post']],
    ])),
    'a valid exact object_keyspace declaration loads through Policy::from_snapshot()'
);
$assertThrows(
    static fn() => FrozenPolicy::fromEnvelope($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'taxonomies' => ['acme_posts' => ['object_keyspace' => 'user']],
    ])),
    'manifest \'acme\' taxonomies.acme_posts.object_keyspace must be one of post|term',
    'Policy::from_snapshot() refuses an invalid exact object_keyspace declaration'
);
$assertThrows(
    static fn() => FrozenPolicy::fromEnvelope($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'taxonomy_patterns' => [['match' => '^acme_', 'object_keyspace' => 'user']],
    ])),
    'manifest \'acme\' taxonomy_patterns[0].object_keyspace must be one of post|term',
    'Policy::from_snapshot() refuses an invalid pattern object_keyspace declaration'
);
$assertAccepted(
    static fn() => FrozenPolicy::fromEnvelope($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'options' => [
            'acme_settings' => [
                'class' => 'runtime',
                'autoload' => 'yes',
                'sub_keys' => ['object_type' => ['class' => 'authored']],
            ],
        ],
        'taxonomies' => [
            'acme_tax' => ['object_type_from_option' => [
                'option' => 'acme_settings', 'sub_key' => 'object_type',
            ]],
        ],
    ])),
    'a valid object_type_from_option declaration loads through Policy::from_snapshot()'
);
$assertThrows(
    static fn() => FrozenPolicy::fromEnvelope($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'options' => [
            'acme_settings' => [
                'class' => 'runtime',
                'autoload' => 'yes',
                'sub_keys' => ['other' => ['class' => 'authored']],
            ],
        ],
        'taxonomies' => [
            'acme_tax' => ['object_type_from_option' => [
                'option' => 'acme_settings', 'sub_key' => 'object_type',
            ]],
        ],
    ])),
    'never declares that key',
    'Policy::from_snapshot() refuses an object_type_from_option local sub-key typo'
);

$policy = new ReflectionClass(Policy::class);
$grammar = new ReflectionClass(TaxonomyGrammar::class);
$check(
    !$policy->hasMethod('validate_taxonomy_object_keyspace_declarations')
        && !$policy->hasMethod('validate_taxonomy_object_keyspace_value')
        && !$policy->hasMethod('validate_object_type_option_refs'),
    'Policy no longer defines the moved taxonomy declaration validators'
);
$check(
    $grammar->hasMethod('validate_taxonomy_object_keyspace_declarations')
        && $grammar->getMethod('validate_taxonomy_object_keyspace_declarations')->isPublic()
        && $grammar->hasMethod('validate_object_type_option_refs')
        && $grammar->getMethod('validate_object_type_option_refs')->isPublic()
        && $grammar->hasMethod('validate_taxonomy_object_keyspace_value')
        && $grammar->getMethod('validate_taxonomy_object_keyspace_value')->isPrivate(),
    'TaxonomyGrammar exposes both taxonomy load-time entry points and keeps its scalar helper private'
);
$check(
    $grammar->hasConstant('TAXONOMY_RELATIONSHIP_OBJECTS')
        && $grammar->getConstant('TAXONOMY_RELATIONSHIP_OBJECTS') === ['post', 'term'],
    'TaxonomyGrammar preserves the exact closed relationship object-keyspace vocabulary'
);

// ---- round-3 T5: exact registration declarations on taxonomies.<tax>
$assertAccepted(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'woocommerce',
        'taxonomies' => [
            'product_cat' => ['class' => 'authored', 'object_type' => ['product'], 'update_count_callback' => '_wc_term_recount'],
            'product_type' => ['class' => 'authored', 'object_type' => ['product']],
            'product_visibility' => ['class' => 'runtime'],
        ],
    ]),
    'exact taxonomies may declare object_type, with or without update_count_callback'
);
$assertAccepted(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'woocommerce',
        'taxonomy_patterns' => [[
            'match' => '^pa_',
            'object_type' => ['product'],
            'hierarchical' => false,
        ]],
    ]),
    'dynamic taxonomy registration may declare its exact native hierarchy fact'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomy_patterns' => [['match' => '^future_', 'object_type' => ['post'], 'hierarchical' => 0]],
    ]),
    'hierarchical requires object_type and a boolean value',
    'dynamic hierarchy declarations reject stringly/numeric values'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['future_tax' => ['hierarchical' => true]],
    ]),
    'hierarchical requires object_type and a boolean value',
    'exact hierarchy declarations require a complete registration declaration'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['acme_cat' => ['class' => 'authored', 'object_type' => []]],
    ]),
    'object_type must be a non-empty list',
    'an empty object_type list is refused'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['acme_cat' => ['class' => 'authored', 'object_type' => 'product']],
    ]),
    'object_type must be a non-empty list',
    'a bare string object_type is refused'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['acme_cat' => ['class' => 'authored', 'object_type' => ['product', '']]],
    ]),
    'only non-empty strings',
    'an empty object type name is refused'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['acme_cat' => ['class' => 'authored', 'update_count_callback' => '_wc_term_recount']],
    ]),
    'requires an object_type declaration beside it',
    'a count callback without object_type is a contract about nothing and is refused'
);
$assertThrows(
    static fn() => TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'acme',
        'taxonomies' => ['acme_cat' => ['class' => 'authored', 'object_type' => ['product'], 'update_count_callback' => '']],
    ]),
    'must be a non-empty callback name',
    'an empty count callback is refused'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall TaxonomyGrammar checks passed\n";
