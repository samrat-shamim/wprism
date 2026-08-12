<?php
/**
 * Offline regression for TaxonomyGrammar (DUO-3348 slice 9: exact and
 * taxonomy-pattern object_keyspace declarations extracted from Policy.php).
 *
 * regress_taxonomy_object_keyspace.php already covers the complete product
 * path through Policy::load()/from_snapshot(), including runtime resolution,
 * lint diagnostics, and repository compiler refusals. This direct suite adds
 * characterization of the manifest-only declaration grammar and proves the
 * moved validator is not duplicated on Policy.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../agent/src/TaxonomyGrammar.php';
require_once __DIR__ . '/../../agent/src/Policy.php';

use Duo\Policy;
use Duo\TaxonomyGrammar;

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

/** Build the smallest legacy frozen-policy envelope the real validator accepts. */
$frozenSnapshot = static function (array $manifest): array {
    return [
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'format' => 'duo-policy-snapshot/v4',
        'manifests' => [$manifest],
        'site' => [
            'manifests' => [(string) $manifest['name']],
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
            'spec_version' => 2,
        ],
    ];
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

// ------------------------------------------------------ frozen Policy entry point

$assertAccepted(
    static fn() => Policy::from_snapshot($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'taxonomies' => ['acme_posts' => ['object_keyspace' => 'post']],
    ])),
    'a valid exact object_keyspace declaration loads through Policy::from_snapshot()'
);
$assertThrows(
    static fn() => Policy::from_snapshot($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'taxonomies' => ['acme_posts' => ['object_keyspace' => 'user']],
    ])),
    'manifest \'acme\' taxonomies.acme_posts.object_keyspace must be one of post|term',
    'Policy::from_snapshot() refuses an invalid exact object_keyspace declaration'
);
$assertThrows(
    static fn() => Policy::from_snapshot($frozenSnapshot([
        'name' => 'acme',
        'spec_version' => 2,
        'taxonomy_patterns' => [['match' => '^acme_', 'object_keyspace' => 'user']],
    ])),
    'manifest \'acme\' taxonomy_patterns[0].object_keyspace must be one of post|term',
    'Policy::from_snapshot() refuses an invalid pattern object_keyspace declaration'
);

$policy = new ReflectionClass(Policy::class);
$grammar = new ReflectionClass(TaxonomyGrammar::class);
$check(
    !$policy->hasMethod('validate_taxonomy_object_keyspace_declarations')
        && !$policy->hasMethod('validate_taxonomy_object_keyspace_value'),
    'Policy no longer defines the moved taxonomy declaration validators'
);
$check(
    $grammar->hasMethod('validate_taxonomy_object_keyspace_declarations')
        && $grammar->getMethod('validate_taxonomy_object_keyspace_declarations')->isPublic()
        && $grammar->hasMethod('validate_taxonomy_object_keyspace_value')
        && $grammar->getMethod('validate_taxonomy_object_keyspace_value')->isPrivate(),
    'TaxonomyGrammar exposes one load-time entry point and keeps its scalar helper private'
);
$check(
    $grammar->hasConstant('TAXONOMY_RELATIONSHIP_OBJECTS')
        && $grammar->getConstant('TAXONOMY_RELATIONSHIP_OBJECTS') === ['post', 'term'],
    'TaxonomyGrammar preserves the exact closed relationship object-keyspace vocabulary'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall TaxonomyGrammar checks passed\n";
