<?php
/**
 * Offline characterization for the pure manifest-declared option-name
 * reference resolver (DUO-3348 slice 45).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/Grammar/OptionNameReferenceResolver.php";
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
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), $needle),
            "$label: refusal names '$needle' (got: {$e->getMessage()})");
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\Duo\OptionNameReferenceResolver::class, false) && !class_exists(\Duo\Policy::class, false) && !class_exists(\Duo\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$childOut = is_resource($child) ? stream_get_contents($pipes[1]) : '';
$childErr = is_resource($child) ? stream_get_contents($pipes[2]) : '';
if (is_resource($child)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    $childExit = proc_close($child);
} else {
    $childExit = 1;
}
$check(
    $childExit === 0 && $childOut === "loaded\n" && $childErr === '',
    'direct resolver load is pure and does not load Policy, RepositoryCompiler, or WordPress'
);

require_once $resolverPath;

use Duo\OptionNameReferenceResolver;

$normalize = static function (array $rule, array $source): array {
    if (!array_key_exists('autoload', $rule) && array_key_exists('option_autoload', $source)) {
        $rule['autoload'] = $source['option_autoload'];
    }
    return $rule;
};
$uuid = '123e4567-e89b-12d3-a456-426614174000';
$manifests = [
    [
        'name' => 'first',
        'option_autoload' => 'yes',
        'option_name_refs' => [[
            'match' => '^thing_(?<id>[1-9][0-9]*)$',
            'malformed_match' => '^thing_0[0-9]+$',
            'id_kind' => 'thing',
            'class' => 'authored',
        ]],
    ],
    [
        'name' => 'second',
        'option_autoload' => 'preserve',
        'option_name_refs' => [[
            'match' => '^other_(?<id>[0-9]+)$',
            'id_kind' => 'other',
            'class' => 'authored',
            'autoload' => 'no',
        ]],
    ],
];
$resolver = new OptionNameReferenceResolver($manifests, $normalize);

$rules = $resolver->rules();
$check(
    count($rules) === 2 && ($rules[0]['autoload'] ?? null) === 'yes' && ($rules[1]['autoload'] ?? null) === 'no',
    'resolver preserves manifest/declaration order, source defaults, and an explicit autoload override'
);
$details = $resolver->match_details('thing_7');
$check(
    ($details['source'] ?? null) === 'first'
        && ($details['rule']['id_kind'] ?? null) === 'thing'
        && ($details['matches']['id'][0] ?? null) === '7'
        && ($details['matches']['id'][1] ?? null) === 6
        && ($details['index'] ?? null) === 0
        && $resolver->match('thing_7') === ($details['rule'] ?? null)
        && $resolver->match_details('missing_7') === null,
    'resolver preserves PREG_OFFSET_CAPTURE details, source/index provenance, facade match, and unknown-name null'
);
$assertThrows(
    static fn() => $resolver->match_details('thing_0007'),
    'matches a malformed option_name_refs namespace',
    'a manifest-owned malformed namespace refuses rather than becoming an unowned option'
);
$malformedWins = new OptionNameReferenceResolver([
    [
        'name' => 'valid-owner',
        'option_name_refs' => [[
            'match' => '^winner_(?<id>[1-9][0-9]*)$', 'id_kind' => 'thing', 'class' => 'authored',
        ]],
    ],
    [
        'name' => 'malformed-owner',
        'option_name_refs' => [[
            'match' => '^never_(?<id>[1-9][0-9]*)$',
            'malformed_match' => '^winner_7$',
            'id_kind' => 'other',
            'class' => 'authored',
        ]],
    ],
], $normalize);
$assertThrows(
    static fn() => $malformedWins->match_details('winner_7'),
    'declared by malformed-owner',
    'a malformed namespace wins globally even when another declaration matched a valid local id'
);
$broadNumeric = new OptionNameReferenceResolver([[
    'name' => 'broad',
    'option_name_refs' => [[
        'match' => '^broad_(?<id>[0-9]+)$', 'id_kind' => 'thing', 'class' => 'authored',
    ]],
]], $normalize);
$assertThrows(
    static fn() => $broadNumeric->match_details('broad_0'),
    'invalid local id',
    'zero local id is refused before callers can resolve it'
);
$assertThrows(
    static fn() => $broadNumeric->match_details('broad_999999999999999999999999999999999999999999'),
    'invalid local id',
    'overflow local id is refused rather than saturated'
);

$ambiguous = new OptionNameReferenceResolver([
    $manifests[0],
    ['name' => 'third', 'option_name_refs' => [[
        'match' => '^thing_(?<id>[0-9]+)$', 'id_kind' => 'other', 'class' => 'authored',
    ]]],
], $normalize);
$assertThrows(
    static fn() => $ambiguous->match_details('thing_7'),
    'matches multiple option_name_refs rules',
    'overlapping rules refuse rather than applying manifest-pin order'
);

$canonical = $resolver->canonical_details("thing_{{thing:$uuid}}");
$check(
    ($canonical['source'] ?? null) === 'first'
        && ($canonical['rule']['id_kind'] ?? null) === 'thing'
        && ($canonical['matches']['id'][0] ?? null) === '1'
        && ($canonical['token_kind'] ?? null) === 'thing',
    'canonical token resolution uses the concrete numeric matcher and preserves its details'
);
$check(
    $resolver->canonical_details("unowned_{{unknown:$uuid}}") === ['rule' => null, 'source' => null]
        && $resolver->canonical_details('ordinary_option') === ['rule' => null, 'source' => null],
    'unknown token kinds and ordinary option names remain unowned'
);
$assertThrows(
    static fn() => $resolver->canonical_details("other_{{thing:$uuid}}"),
    'id_kind does not match',
    'canonical token cannot borrow an authored owner from another keyspace'
);
$assertThrows(
    static fn() => $resolver->canonical_details("unowned_{{thing:$uuid}}"),
    'not owned by exactly one authored',
    'known token kind without an authored name owner refuses'
);
$assertThrows(
    static fn() => $resolver->canonical_details("thing_{{thing:$uuid}}_{{thing:$uuid}}"),
    'contains multiple embedded identity tokens',
    'multiple canonical identity tokens refuse ambiguity'
);

$missingCapture = new OptionNameReferenceResolver([[
    'name' => 'bad',
    'option_name_refs' => [['match' => '^bad_[0-9]+$', 'id_kind' => 'thing', 'class' => 'authored']],
]], $normalize);
$assertThrows(
    static fn() => $missingCapture->match_details('bad_7'),
    'did not expose its named id capture',
    'runtime matcher retains its loud missing named-capture refusal for unvalidated input'
);
$check(
    OptionNameReferenceResolver::strict_positive_local_id('1') === 1
        && OptionNameReferenceResolver::strict_positive_local_id('001') === null
        && OptionNameReferenceResolver::strict_positive_local_id('0') === null
        && OptionNameReferenceResolver::strict_positive_local_id(1) === null,
    'strict positive local-id parser accepts only exact nonzero decimal strings'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->option_name_ref_rules() === $rules
        && $policy->option_name_ref_match_details('thing_7') === $details
        && $policy->canonical_option_name_ref_details("thing_{{thing:$uuid}}") === $canonical
        && $policy->match_option_name_ref('thing_7') === ($details['rule'] ?? null)
        && Duo\Policy::strict_positive_local_id('7') === OptionNameReferenceResolver::strict_positive_local_id('7'),
    'Policy retains byte-equivalent public facades over the pure resolver, including the static id parser'
);
$policy->manifests[0]['option_name_refs'][0]['match'] = '^changed_(?<id>[1-9][0-9]*)$';
$check(
    $policy->match_option_name_ref('thing_7') === null
        && ($policy->match_option_name_ref('changed_7')['id_kind'] ?? null) === 'thing',
    'Policy builds a fresh resolver for each facade call so public fixture mutations are observed'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/OptionNameReferenceResolver.php';") === 1
        && str_contains($policySource, 'return $this->option_name_reference_resolver()->rules();')
        && str_contains($policySource, 'return $this->option_name_reference_resolver()->match_details($realOptionName);')
        && str_contains($policySource, 'return OptionNameReferenceResolver::strict_positive_local_id($value);')
        && str_contains($policySource, 'return $this->option_name_reference_resolver()->canonical_details($name);')
        && str_contains($policySource, 'return $this->option_name_reference_resolver()->match($realOptionName);')
        && str_contains($policySource, 'new OptionNameReferenceResolver(')
        && str_contains($policySource, 'self::with_option_autoload($rule, $source)')
        && !str_contains($policySource, 'foreach ($this->manifests as $manifest) {' . "\n"
            . "            foreach (\$manifest['option_name_refs'] ?? [] as \$index => \$rule) {")
        && !str_contains($policySource, 'foreach ($this->manifests as $m) {' . "\n"
            . "            foreach (\$m['option_name_refs'] ?? [] as \$rule) {"),
    'Policy requires the resolver once, keeps only explicit facades, and leaves normalization at its public compatibility port'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
