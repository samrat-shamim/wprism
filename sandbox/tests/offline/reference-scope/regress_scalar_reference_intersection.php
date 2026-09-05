<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/ScalarReferenceIntersection.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/ReferenceShapeGrammar.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/ReferenceKeyspaceGrammar.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/TermCoordinateWitness.php';

use WPrism\CommandRefusalException;
use WPrism\CrossManifestGuards;
use WPrism\Policy;
use WPrism\ReferenceKeyspaceGrammar;
use WPrism\ReferenceRules;
use WPrism\ReferenceShapeGrammar;
use WPrism\ScalarReferenceIntersection as Intersection;
use WPrism\TermCoordinateWitness;
use WPrismTest\FakeWpdb;

$uuid = '11111111-1111-7111-8111-111111111111';
$other = '22222222-2222-7222-8222-222222222222';
$rule = ['class' => 'authored', 'ref' => 'term', Intersection::FIELD => ['tt'], Intersection::TAXONOMY_FIELD => 'category'];
$physical = static fn(int $id, string $taxonomy): bool => $taxonomy === 'category';
$token = '{{term:' . $uuid . '}}';
$source = ['spec_version' => 3, 'engine_features' => [Intersection::FEATURE], 'options' => ['subject' => $rule]];
ReferenceShapeGrammar::validate_reference_shapes($source, 'fixture', true);
wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($source, 'site'),
    RuntimeException::class, 'site data cannot self-assert a manifest feature context');
ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$source], []);
wprism_check_same(['tt', 'term'], ReferenceRules::kinds($rule), 'both physical keyspaces participate in reference authority');
wprism_check_same($token, Intersection::capture('41', $rule, static fn(): string => $uuid, 'fixture', $physical), 'capture preserves the ordinary primary token');
wprism_check_same(73, Intersection::apply($token, $rule, static fn(): int => 73, 'fixture', $physical), 'apply may rebind to a different jointly valid target integer');
wprism_check_same('73', Intersection::apply($token, $rule + ['cast' => 'string'], static fn(): int => 73, 'fixture', $physical), 'scalar string casting remains explicit');
foreach (['11111111-1111-0111-8111-111111111111', '11111111-1111-9111-8111-111111111111', '11111111-1111-7111-1111-111111111111'] as $invalidUuid) {
    wprism_check_throws(static fn() => Intersection::capture(41, $rule, static fn(): string => $invalidUuid, 'fixture', $physical),
        CommandRefusalException::class, 'capture requires RFC version and variant bits, not merely a UUID-shaped string');
    wprism_check_throws(static fn() => Intersection::apply('{{term:' . $invalidUuid . '}}', $rule, static fn(): int => 73, 'fixture', $physical),
        CommandRefusalException::class, 'apply rejects the same non-RFC canonical identity before target lookup');
}
foreach ([0, '0'] as $zero) {
    $never = static function (): never { throw new LogicException('zero must not look up an identity'); };
    wprism_check_same(0, Intersection::capture($zero, $rule, $never, 'fixture', $never), 'capture retains durable zero without invented identity');
    wprism_check_same(0, Intersection::apply(0, $rule, $never, 'fixture', $never), 'apply retains durable zero without invented identity');
}

foreach ([-1, '-1', '01', '+1', '1.0', '1e2', ' 1', true, false, null, [], (string) PHP_INT_MAX . '0'] as $value) {
    wprism_check_throws(
        static fn() => Intersection::capture($value, $rule, static fn(): string => $uuid, 'fixture', $physical),
        CommandRefusalException::class, 'capture refuses noncanonical or overflowing scalar identity'
    );
}
foreach ([null, $other, 'malformed'] as $alternate) {
    wprism_check_throws(
        static fn() => Intersection::capture(41, $rule, static fn(int $id, string $kind): ?string => $kind === 'term' ? $uuid : $alternate, 'fixture', $physical),
        CommandRefusalException::class, 'capture refuses absent, contradictory, or malformed alternate identity'
    );
}
wprism_check_same(null, Intersection::capture(41, $rule, static fn(): ?string => null, 'fixture', $physical, true),
    'a strict read-only observation may project a wholly unmapped hook-created reference as absent');
wprism_check_throws(static fn() => Intersection::capture(41, $rule, static fn(): ?string => null, 'fixture', $physical),
    CommandRefusalException::class, 'ordinary capture never omits the same wholly unmapped reference');
foreach ([[null, $uuid], [$uuid, null], [$uuid, $other], ['malformed', null]] as [$primary, $alternate]) {
    wprism_check_throws(static fn() => Intersection::capture(41, $rule,
        static fn(int $id, string $kind): ?string => $kind === 'term' ? $primary : $alternate, 'fixture', $physical, true),
        CommandRefusalException::class, 'read-only projection cannot hide a partial, contradictory, or malformed identity');
}
foreach ([null, 0, 99] as $alternate) {
    wprism_check_throws(
        static fn() => Intersection::apply($token, $rule, static fn(string $id, string $kind): ?int => $kind === 'term' ? 73 : $alternate, 'fixture', $physical),
        CommandRefusalException::class, 'apply refuses absent, zero, or divergent alternate binding'
    );
}
foreach ([73, '73', '0', '{{tt:' . $uuid . '}}', '{{term:private-canonical-canary}}'] as $value) {
    wprism_check_throws(
        static fn() => Intersection::apply($value, $rule, static fn(): int => 73, 'fixture', $physical),
        CommandRefusalException::class, 'apply accepts only the declared primary token or durable zero'
    );
}
try {
    Intersection::apply('{{term:private-canonical-canary}}', $rule, static fn(): int => 73, 'fixture', $physical);
} catch (CommandRefusalException $failure) {
    wprism_check_same('reference_intersection_failed', $failure->reasonCode, 'the new refusal has a reviewed public category');
    wprism_check(!str_contains(json_encode($failure->payload(), JSON_THROW_ON_ERROR), 'private-canonical-canary'), 'public refusal contains no supplied reference bytes');
}

foreach ([
    'empty list' => [Intersection::FIELD => []],
    'object' => [Intersection::FIELD => ['key' => 'tt']],
    'duplicate' => [Intersection::FIELD => ['tt', 'tt']],
    'primary repeated' => [Intersection::FIELD => ['term']],
    'aliased primary repeated' => ['ref' => 'tt', Intersection::FIELD => ['term_taxonomy']],
    'user login keyspace' => [Intersection::FIELD => ['user']],
    'unmodeled multi-coordinate post' => ['ref' => 'post'],
    'unmodeled multi-coordinate custom entity' => ['ref' => 'custom'],
    'unproved reverse coordinate' => ['ref' => 'tt', Intersection::FIELD => ['term']],
    'unknown syntax' => [Intersection::FIELD => ['bad/kind']],
    'more than four alternates' => [Intersection::FIELD => ['a', 'b', 'c', 'd', 'e']],
    'array ref' => ['ref' => 'term[]'],
    'non-authored' => ['class' => 'env'],
    'csv' => ['cast' => 'csv'],
    'sub-key' => ['sub_keys' => []],
    'structured' => ['json_refs' => []],
    'plain' => ['plain_data' => true],
    'missing taxonomy' => [Intersection::TAXONOMY_FIELD => null],
    'empty taxonomy' => [Intersection::TAXONOMY_FIELD => ''],
    'noncanonical taxonomy' => [Intersection::TAXONOMY_FIELD => 'Category'],
    'overlong taxonomy' => [Intersection::TAXONOMY_FIELD => str_repeat('a', 33)],
] as $label => $override) {
    $bad = array_replace($rule, $override);
    wprism_check_throws(static fn() => ReferenceRules::value_rule($bad, 'fixture'), RuntimeException::class, "grammar rejects $label");
}
foreach (['post_meta', 'term_meta', 'user_meta', 'option_patterns', 'meta_patterns', 'option_name_refs'] as $section) {
    $bad = ['spec_version' => 3, 'engine_features' => [Intersection::FEATURE], $section => ['subject' => $rule]];
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true), RuntimeException::class, "intersection is not silently admitted on unsupported $section storage");
}
foreach ([['engine_features' => []], ['spec_version' => 2]] as $override) {
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes(array_replace($source, $override), 'fixture', true), RuntimeException::class, 'exact option constraint requires its v3 feature claim');
}
$unknown = $source;
$unknown['options']['subject'][Intersection::FIELD] = ['unowned'];
wprism_check_throws(static fn() => ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$unknown], []), RuntimeException::class, 'alternate keyspace must have actual loaded ownership');
ReferenceShapeGrammar::validate_reference_shapes(['options' => ['legacy' => ['class' => 'authored', 'ref' => 'term']]], 'legacy');
wprism_check_same(['term'], ReferenceRules::kinds(['ref' => 'term']), 'unconstrained legacy reference declarations remain unchanged');

$dynamicParent = ['dynamic_options' => ['subject' => $rule + ['sub_keys' => []]]];
wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($dynamicParent, 'fixture'),
    RuntimeException::class, 'a dynamic-options parent cannot silently carry an ignored intersection');

// Invoke each public interpreter-backed classifier, not a stand-in grammar.
// Inject only the already-loaded executable collaborator: loader provenance is
// covered separately, while this regression owns dynamic dispatch semantics.
$dynamicPolicy = static function (?array $static, ?array $answer): Policy {
    $policy = new Policy();
    $policy->site = ['policy' => []];
    $policy->manifests = [[
        'name' => 'fixture', 'interpreter' => 'fixture', 'option_autoload' => 'preserve',
        'options' => $static === null ? [] : ['subject' => $static],
    ]];
    $interpreter = new class($answer) {
        public function __construct(private ?array $answer) {}
        public function option_rule(string $key, array $values): ?array { return $this->answer; }
        public function post_meta_rule(string $key, array $values): ?array { return $this->answer; }
        public function term_meta_rule(string $key, array $values): ?array { return $this->answer; }
        public function user_meta_rule(string $key, array $values): ?array { return $this->answer; }
    };
    (new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($policy, ['fixture' => $interpreter]);
    return $policy;
};
foreach (['option_rule_details_for_option', 'meta_rule_details_for_post', 'meta_rule_details_for_term', 'meta_rule_details_for_user'] as $method) {
    $policy = $dynamicPolicy(null, $rule);
    wprism_check_throws(static fn() => $policy->{$method}('subject', []), RuntimeException::class,
        'an interpreter cannot mint the static-only intersection on ' . $method, 'cannot classify');
}
foreach ([$rule, ['class' => 'authored', 'ref' => 'term'], ['class' => 'runtime']] as $answer) {
    $policy = $dynamicPolicy($rule, $answer);
    wprism_check_throws(static fn() => $policy->option_rule_details_for_option('subject', []), RuntimeException::class,
        'an interpreter cannot echo, strip, or reclassify the static value authority', 'cannot classify');
}
$policy = $dynamicPolicy(null, ['class' => 'env', 'sub_keys' => ['nested' => $rule]]);
wprism_check_throws(static fn() => $policy->option_rule_details_for_option('subject', []), RuntimeException::class,
    'an interpreter cannot smuggle the constraint inside an authored sub-key', 'cannot classify');
$policy = $dynamicPolicy($rule, null);
wprism_check_same($rule + ['autoload' => 'preserve'], $policy->option_rule_details_for_option('subject', [])['rule'],
    'null interpreter answers leave the exact static authority intact');

foreach ([['class' => 'authored'], ['class' => 'managed'], ['class' => 'env', 'sub_keys' => ['child' => ['class' => 'authored']]], ['class' => 'runtime', 'sub_keys' => []]] as $override) {
    $policy = $dynamicPolicy($rule, null);
    $policy->site['policy']['options']['subject'] = $override;
    wprism_check_throws(static fn() => $policy->authored_options(), RuntimeException::class,
        'effective option enumeration cannot erase a manifest value constraint via site policy', 'cannot replace');
    wprism_check_throws(static fn() => CrossManifestGuards::validate_no_conflicting_option_rules(
        $policy->manifests, ['subject' => $override]), RuntimeException::class,
        'the load-time cross-source gate refuses that same authored override', 'cannot replace');
}
foreach (['runtime', 'env', 'derived'] as $class) {
    $policy = $dynamicPolicy($rule, null);
    $policy->site['policy']['options']['subject'] = ['class' => $class];
    CrossManifestGuards::validate_no_conflicting_option_rules($policy->manifests, ['subject' => ['class' => $class]]);
    wprism_check_same([], $policy->authored_options(), 'an explicit whole-option exclusion may still remove propagation');
}
$published = \WPrism\AdapterContractGrammar::implemented_feature_rows()[Intersection::FEATURE];
wprism_check_same(Intersection::declaration_grammar(), $published['value_constraint'] ?? null,
    'the emitted feature description comes from the same engine-owned value constraint');
wprism_check_same([], $published['keys'], 'the existing option field adds no top-level section or certificate arm');

// Column-name evidence is not a width guarantee. These are real SELECTs over
// the shared row store, including one-byte-past-domain and driver failures.
$witnessDb = static function (): FakeWpdb {
    return FakeWpdb::install()
        ->seedTable('wp_terms', [['term_id' => 41]])
        ->seedTable('wp_term_taxonomy', [[
            'term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => 'category',
        ]]);
};
$db = $witnessDb();
wprism_check(TermCoordinateWitness::matches(41, 'category'), 'the shared physical witness accepts exactly one matching native tuple');
wprism_check_same(2, count($db->queries()), 'the native witness performs exactly two reads');
foreach ($db->queries() as $query) {
    wprism_check(str_contains($query, 'LEFT(BINARY ') && str_contains($query, ' LIMIT 2')
        && !str_contains($query, 'FOR UPDATE'), 'capture bounds both rows and projected bytes without acquiring apply locks');
}
$db = $witnessDb()->seedTable('wp_term_taxonomy', [[
    'term_taxonomy_id' => 41, 'term_id' => 41, 'taxonomy' => str_repeat('a', 33),
]]);
wprism_check(!TermCoordinateWitness::matches(41, str_repeat('a', 32)),
    'one-byte-over taxonomy cannot be truncated into an accepted maximum-length name');
$db = $witnessDb()->seedTable('wp_term_taxonomy', [[
    'term_taxonomy_id' => 41, 'term_id' => str_repeat('4', 1048576), 'taxonomy' => str_repeat('a', 1048576),
]]);
wprism_check(!TermCoordinateWitness::matches(41, 'category'), 'oversized physical coordinates refuse');
$queries = $db->queries();
$boundedRows = $db->get_results($queries[1], ARRAY_A);
wprism_check_same(21, strlen($boundedRows[0]['term_id']), 'even an untyped source column transfers only the bounded ID witness');
wprism_check_same(33, strlen($boundedRows[0]['taxonomy']), 'even an untyped source column transfers only the bounded taxonomy witness');
foreach (['wp_terms', 'wp_term_taxonomy'] as $table) {
    foreach ([false, true] as $suppressed) {
        $db = $witnessDb()->failNextQuery('private-coordinate-read-canary', "FROM $table ");
        $db->suppress_errors($suppressed);
        wprism_check_throws(static fn() => TermCoordinateWitness::matches(41, 'category'), RuntimeException::class,
            'each native read fails closed on wpdb last_error', 'bounded term-coordinate witness could not be read');
        wprism_check_same($suppressed, $db->suppress_errors(), 'each failed witness restores its caller error-display mode');
        wprism_check_same($table === 'wp_terms' ? 1 : 2, count($db->queries()), 'a failed witness performs no later native read');
    }
    foreach ([false, null, ['not-a-list' => []], ['not-a-row']] as $malformed) {
        $db = $witnessDb()->returnNextGetResultsAs($malformed, "FROM $table ");
        wprism_check_throws(static fn() => TermCoordinateWitness::matches(41, 'category'), RuntimeException::class,
            'a compatible driver cannot supply malformed physical evidence');
    }
}
$db = $witnessDb()->seedTable('wp_term_taxonomy', [[
    'term_taxonomy_id' => 41, 'term_id' => 99, 'taxonomy' => 'category',
]]);
$lookups = 0;
wprism_check_throws(static fn() => Intersection::capture(41, $rule,
    static function () use (&$lookups, $uuid): string { ++$lookups;
    return $uuid; }, 'fixture', TermCoordinateWitness::matches(...), true),
    CommandRefusalException::class, 'physical inconsistency refuses even the unmapped lifecycle projection');
wprism_check_same(0, $lookups, 'physical evidence is established before any source identity lookup');

wprism_check_summary('scalar reference intersection');
