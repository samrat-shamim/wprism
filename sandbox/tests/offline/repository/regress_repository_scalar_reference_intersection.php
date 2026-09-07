<?php
/**
 * Canonical compiler regression for the scalar reference intersection.
 * Runtime capture/apply prove local database coordinates; this pure pass
 * proves that the durable primary token names exactly one term in the
 * manifest-declared taxonomy before an artifact can be published.
 */
declare(strict_types=1);

namespace WPrism {
    final class Policy {
        /** @var array<string,array<string,mixed>> */
        public array $optionRules = [];

        public function description_reference_rule(string $taxonomy): ?array { return null; }
        public function meta_rule_for_term(string $key, array $allMeta): ?array { return null; }
        /** @return array{rule:?array,source:?string} */
        public function canonical_option_name_ref_details(string $name): array {
            return ['rule' => null, 'source' => null];
        }
        /** @return array{rule:?array,source:?string} */
        public function option_rule_details_for_option(string $name, array $allOptions): array {
            return ['rule' => $this->optionRules[$name] ?? null, 'source' => null];
        }
        /** @return array<string,array<string,mixed>> */
        public function widget_types(): array { return []; }
    }

    final class Snapshot {
        /** @return array<string,array<string,mixed>> */
        public static function row_tables(Policy $policy): array { return []; }
        /** @return array<string,array<string,mixed>> */
        public static function meta_tables(Policy $policy): array { return []; }
    }

    final class OptionState {
        /** @return array<string,mixed> */
        public static function classification_values(array $document): array { return $document; }
        /** @return array<string,array<string,mixed>> */
        public static function records(array $document): array { return (array) ($document['records'] ?? []); }
    }

    final class SidebarState { public const ENTITY_TYPE = 'sidebar'; }
}

namespace {
    use WPrism\Policy;
    use WPrism\RepositoryPortableShapeValidator;
    use WPrism\ScalarReferenceIntersection as Intersection;

    $root = dirname(__DIR__, 4);
    require_once "$root/agent/src/Kernel/ReferenceRules.php";
    require_once "$root/agent/src/Repository/RepositoryPortableShapeValidator.php";

    $failures = [];
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$failures, &$checks): void {
        ++$checks;
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $uuid = static fn(int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
    $productCategory = $uuid(1);
    $rule = [
        'class' => 'authored',
        'ref' => 'term',
        Intersection::FIELD => ['tt'],
        Intersection::TAXONOMY_FIELD => 'product_cat',
    ];
    $policy = new Policy();
    $policy->optionRules = [
        'default_product_cat' => $rule,
        'zero' => $rule,
        'absent' => $rule,
        'deleted' => $rule,
    ];

    /** @return array<string,mixed> */
    $term = static fn(string $key, string $id, string $taxonomy, string $path): array => [
        $key => [
            'type' => 'term',
            'path' => $path,
            'data' => [
                'uuid' => $id,
                'taxonomy' => $taxonomy,
                'meta' => [],
            ],
        ],
    ];
    /** @return array<string,mixed> */
    $options = static fn(array $records, string $path = 'options/core.json'): array => [
        'options/core' => [
            'type' => 'options',
            'path' => $path,
            'data' => ['records' => $records],
        ],
    ];

    $diagnostics = [];
    $validator = new RepositoryPortableShapeValidator(
        $policy,
        static function (
            string $code,
            string $path,
            string $locator,
            string $message,
            ?string $relatedPath = null
        ) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $validate = static function (array $tree) use ($validator, &$diagnostics): array {
        $diagnostics = [];
        $validator->validate($tree);
        return $diagnostics;
    };

    $clean = $validate(
        $options([
            'default_product_cat' => ['state' => 'present', 'value' => "{{term:$productCategory}}"],
            'zero' => ['state' => 'present', 'value' => 0],
            'absent' => ['state' => 'absent'],
            'deleted' => ['state' => 'deleted'],
        ])
        + $term('term-after-options', $productCategory, 'product_cat', 'terms/product_cat/default.json')
    );
    $check(
        $clean === [],
        'one exact product_cat term token, integer zero, absence, and deletion compile independent of tree order'
    );

    $wrongTaxonomy = $validate(
        $options(['default_product_cat' => ['state' => 'present', 'value' => "{{term:$productCategory}}"]])
        + $term('wrong-taxonomy', $productCategory, 'category', 'terms/category/default.json')
    );
    $check(
        $wrongTaxonomy === [[
            'code' => 'reference_taxonomy_mismatch',
            'path' => 'options/core.json',
            'locator' => 'options.default_product_cat',
            'message' => "declared term reference must resolve to exactly one canonical term in taxonomy 'product_cat'",
            'relatedPath' => 'terms/category/default.json',
        ]],
        'a canonical term in the wrong taxonomy is rejected with its exact related document'
    );

    $missing = $validate(
        $options(['default_product_cat' => ['state' => 'present', 'value' => "{{term:$productCategory}}"]])
    );
    $check(
        $missing === [[
            'code' => 'reference_taxonomy_mismatch',
            'path' => 'options/core.json',
            'locator' => 'options.default_product_cat',
            'message' => "declared term reference must resolve to exactly one canonical term in taxonomy 'product_cat'",
            'relatedPath' => null,
        ]],
        'an absent canonical target cannot satisfy the taxonomy constraint'
    );

    $menuTarget = [
        'menu-target' => [
            'type' => 'menu',
            'path' => 'menus/default.json',
            'data' => ['uuid' => $productCategory, 'items' => []],
        ],
    ];
    $check(
        $validate(
            $options(['default_product_cat' => ['state' => 'present', 'value' => "{{term:$productCategory}}"]])
            + $menuTarget
        ) === $missing,
        'the ordinary term keyspace alias for menus cannot satisfy a physical term-coordinate constraint'
    );

    $duplicates = $validate(
        $options(['default_product_cat' => ['state' => 'present', 'value' => "{{term:$productCategory}}"]])
        + $term('first-term', $productCategory, 'product_cat', 'terms/product_cat/first.json')
        + $term('second-term', $productCategory, 'product_cat', 'terms/product_cat/second.json')
    );
    $check(
        count($duplicates) === 1
        && $duplicates[0]['code'] === 'reference_taxonomy_mismatch'
        && $duplicates[0]['relatedPath'] === null,
        'duplicate UUID documents remain ambiguous even when every duplicate claims the required taxonomy'
    );

    $malformedValues = [
        'present_null' => null,
        'string_zero' => '0',
        'positive_integer' => 17,
        'negative_integer' => -1,
        'float' => 1.0,
        'array' => [],
        'alternate_kind' => "{{tt:$productCategory}}",
        'embedded_token' => "prefix {{term:$productCategory}}",
        'non_rfc_uuid' => '{{term:00000000-0000-0000-0000-000000000001}}',
        'uppercase_uuid' => '{{term:00000000-0000-4000-8000-00000000000A}}',
    ];
    $records = [];
    foreach ($malformedValues as $name => $value) {
        $policy->optionRules[$name] = $rule;
        $records[$name] = ['state' => 'present', 'value' => $value];
    }
    $malformed = $validate(
        $options($records, 'options/malformed.json')
        + $term('valid-term', $productCategory, 'product_cat', 'terms/product_cat/default.json')
    );
    $check(
        count($malformed) === count($malformedValues)
        && array_column($malformed, 'code') === array_fill(0, count($malformedValues), 'nonportable_reference')
        && array_column($malformed, 'locator') === array_map(
            static fn(string $name): string => 'options.' . $name,
            array_keys($malformedValues)
        )
        && count(array_unique(array_column($malformed, 'message'))) === 1
        && $malformed[0]['message']
            === 'declared term reference must be a canonical token or integer zero, never a raw target id',
        'present values accept only an exact lowercase RFC term token or integer zero; null and lookalikes refuse'
    );

    $invalidRule = $rule;
    unset($invalidRule[Intersection::TAXONOMY_FIELD]);
    $policy->optionRules['invalid_rule'] = $invalidRule;
    $invalidThrown = null;
    try {
        $validate($options(['invalid_rule' => ['state' => 'present', 'value' => 0]]));
    } catch (RuntimeException $e) {
        $invalidThrown = $e->getMessage();
    }
    $check(
        is_string($invalidThrown)
        && str_contains($invalidThrown, '.ref_same_local_id_as requires an authored scalar term ref')
        && str_contains($invalidThrown, 'canonical ref_taxonomy'),
        'the compiler reuses the closed declaration grammar instead of trusting a prevalidated policy object'
    );

    $source = (string) file_get_contents("$root/agent/src/Repository/RepositoryPortableShapeValidator.php");
    $check(
        substr_count($source, 'OptionState::records($d)') === 1
        && substr_count($source, 'ScalarReferenceIntersection::kinds(') === 1
        && !str_contains($source, 'ReferenceGraph::edges(')
        && !str_contains($source, '$wpdb')
        && !str_contains($source, 'Ledger::')
        && !str_contains($source, 'get_option('),
        'the constraint extends the existing canonical option pass and performs no graph, database, ledger, or WordPress I/O'
    );

    if ($failures !== []) {
        fwrite(STDERR, "\nFAILED " . count($failures) . " of $checks assertion(s)\n");
        exit(1);
    }
    echo "\nALL PASSED ($checks assertions)\n";
}
