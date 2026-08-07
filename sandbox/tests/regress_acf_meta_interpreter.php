<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression for DUO-3263's ACF
 * interpreter extensions: term_meta_rule() (thin reuse of post_meta_rule()'s
 * shadow-key/field-definition machinery against wp_termmeta's identical
 * "first value per key" shape — confirmed by reading Capture::term_meta_map()
 * against post_meta_map()) and option_rule() (the options_/_options_ prefix
 * convention, empirically grounded against a fresh ACF 6.8.7 free-plugin
 * install — see manifests/interpreters/acf.php's own class docblock and the
 * DUO-3263 PR body for the full finding, including that acf_add_options_page()
 * itself is PRO-only while the underlying update_field(...,'option') storage
 * is not).
 *
 * DUO-3262's own regress_interpreter_policy.php already proves the GENERIC
 * Policy-level dispatch mechanism (precedence, optional-hook fallback, the
 * post_meta_rule-is-mandatory load-time check) with FAKE interpreters — this
 * file does not re-prove that. It proves two different things: (1) the REAL
 * Acf class's new classification logic in isolation, and (2) one end-to-end
 * pass through the REAL manifests/acf.json + Policy::meta_rule_for_option()/
 * owned_option_rule_via_interpreter()/option_rule_details_for_option() wiring,
 * confirming the option_namespaces claim, the interpreter dispatch, and the
 * cross-manifest-ownership check compose correctly together.
 */

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/RepositoryAuthorization.php';
require __DIR__ . '/../../manifests/interpreters/acf.php';

use Duo\Policy;
use Duo\Interpreters\Acf;

if (!defined('DUO_SPEC_VERSION')) {
    // DUO-3261 bumped the engine's required spec_version to 2 (the term-file
    // `meta` wire format) after this test was first written — the real
    // manifests/acf.json this file's end-to-end section loads now declares
    // 2, so this constant has to match or Policy::load() refuses it outright.
    define('DUO_SPEC_VERSION', 2);
}

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

/** A fake acf-field tree entity, the exact shape Acf::prime_repository() reads. */
function fake_field(string $key, string $type, array $extra = []): array {
    return [
        'type' => 'post',
        'path' => "state/posts/acf-field/$key.json",
        'data' => ['type' => 'acf-field', 'slug' => $key],
        'body' => serialize(array_merge(['type' => $type, 'key' => $key], $extra)),
    ];
}

// A Policy instance to satisfy Acf::__construct()'s type hint -- its own
// docblock says the param is unused (classification is fully schema-driven
// from primed field definitions), so an empty, manifest-less Policy is
// exactly as valid as any other for the direct-class tests below.
$emptyPolicy = Policy::load(null, []);

echo "\n== term_meta_rule(): reuses post_meta_rule()'s exact shadow-key machinery ==\n";
$acf = new Acf($emptyPolicy);
$acf->prime_repository([
    fake_field('field_bio_photo', 'image'),
    fake_field('field_bio_text', 'text'),
    fake_field('field_related_terms', 'taxonomy', ['field_type' => 'checkbox']),
]);

check(
    $acf->term_meta_rule('_bio_photo', ['_bio_photo' => 'field_bio_photo', 'bio_photo' => '9']) === ['class' => 'authored'],
    'term shadow-key meta ("_<key>") classifies authored, same as post_meta_rule()'
);
check(
    $acf->term_meta_rule('bio_photo', ['_bio_photo' => 'field_bio_photo', 'bio_photo' => '9'])
        === ['class' => 'authored', 'ref' => 'post', 'cast' => 'string'],
    'term-attached image field resolves the same ref/cast a post-attached one gets'
);
check(
    $acf->term_meta_rule('bio_text', ['_bio_text' => 'field_bio_text', 'bio_text' => 'hello'])
        === ['class' => 'authored'],
    'term-attached plain text field is a bare authored value'
);
check(
    $acf->term_meta_rule('related_terms', ['_related_terms' => 'field_related_terms', 'related_terms' => 'a:1:{i:0;i:2;}'])
        === ['class' => 'authored', 'ref' => 'term[]'],
    'term-attached multi-value taxonomy field resolves term[] the same as on a post'
);
check(
    $acf->term_meta_rule('mystery_key', ['mystery_key' => 'no shadow at all']) === null,
    'a key with no shadow pointer defers to null (static rules, then the loud gate) -- never guesses'
);
check(
    $acf->term_meta_rule('ghost_field', ['_ghost_field' => 'field_never_defined', 'ghost_field' => 'x']) === null,
    'a shadow pointer to a field definition NOT in the repository defers to null -- never guesses'
);

echo "\n== option_rule(): the options_/_options_ prefix convention ==\n";
$acf2 = new Acf($emptyPolicy);
$acf2->prime_repository([
    fake_field('field_site_tagline', 'text'),
    fake_field('field_site_logo', 'image'),
]);
$allOptions = [
    'options_site_tagline' => 'Duo makes WordPress branchable.',
    '_options_site_tagline' => 'field_site_tagline',
    'options_site_logo' => '9',
    '_options_site_logo' => 'field_site_logo',
];
check(
    $acf2->option_rule('options_site_tagline', $allOptions) === ['class' => 'authored'],
    'a plain options-page field resolves to a bare authored value'
);
check(
    $acf2->option_rule('_options_site_tagline', $allOptions)
        === ['class' => 'authored', 'deletion_witness' => true],
    'the options-page shadow pointer is authored and declares its value as deletion-classification context'
);
check(
    $acf2->option_rule('options_site_logo', $allOptions) === ['class' => 'authored', 'ref' => 'post', 'cast' => 'string'],
    'a ref-type (image) options-page field resolves the same ref/cast post_meta_rule() would give it'
);
check(
    $acf2->option_rule('wp_unrelated_option', $allOptions) === null,
    'a name with no options_/_options_ prefix at all defers to null -- interpreter never over-claims'
);
check(
    $acf2->option_rule('options_no_shadow', $allOptions) === null,
    'an options_<name> row with no corresponding shadow pointer defers to null -- never guesses'
);
check(
    $acf2->option_rule('options_undefined_field', [
        'options_undefined_field' => 'x', '_options_undefined_field' => 'field_never_defined',
    ]) === null,
    'a shadow pointer naming a field NOT in the repository defers to null -- never guesses (mirrors term/post)'
);
check(
    $acf2->option_rule('_options_bad_pointer', [
        'options_bad_pointer' => 'x', '_options_bad_pointer' => 'not-a-field-key',
    ]) === null,
    'a malformed shadow pointer value (not matching field_*) is rejected, not trusted blindly'
);

echo "\n== end-to-end: Policy dispatch through the REAL manifests/acf.json ==\n";
$policy = Policy::load(null, ['acf']);
// Policy's OWN internal Acf instance (built lazily inside interpreters(),
// separate from $acf/$acf2 above) must be primed the same way a real
// RepositoryAuthorization/RepositoryCompiler pass primes it -- otherwise
// field_definition() falls through to its live-$wpdb branch, which is null
// in this offline harness (by design: never touch a live DB, matching every
// OTHER regress_*.php file's own no-docker posture).
$policy->prime_interpreters_from_repository([
    fake_field('field_site_tagline', 'text'),
    fake_field('field_site_logo', 'image'),
]);

echo "\n== option tombstones: cold-tree classification witness ==\n";
$priorTagline = \Duo\OptionState::present('Duo makes WordPress branchable.', 'off');
$priorShadow = \Duo\OptionState::present('field_site_tagline', 'off');
check(
    \Duo\OptionState::document(['blogname' => $priorTagline])['format'] === 'duo-options/v1',
    'ordinary documents stay byte-compatible v1; the format changes only when the new field is used'
);
$deletedOptions = \Duo\OptionState::document([
    'options_site_tagline' => \Duo\OptionState::deleted($priorTagline),
    '_options_site_tagline' => \Duo\OptionState::deleted($priorShadow, true),
]);
$deletionContext = \Duo\OptionState::classification_values($deletedOptions);
check(
    $deletedOptions['format'] === 'duo-options/v2'
        && !isset($deletedOptions['records']['options_site_tagline']['classification_witness'])
        && ($deletedOptions['records']['_options_site_tagline']['classification_witness']['value'] ?? null)
            === 'field_site_tagline',
    'v2 retains only the shadow pointer selected by policy, not the deleted authored field value'
);
check(
    ($policy->meta_rule_for_option('options_site_tagline', $deletionContext)['class'] ?? null) === 'authored'
        && ($policy->meta_rule_for_option('_options_site_tagline', $deletionContext)['class'] ?? null) === 'authored',
    'both tombstones re-derive as authored from the fresh document alone'
);
$coldTreeAuthorized = true;
$coldTreeError = '';
try {
    $coldPolicy = Policy::load(null, ['acf']);
    $coldPolicy->site['policy']['post_types'] = ['acf-field'];
    \Duo\RepositoryAuthorization::assert_tree($coldPolicy, [
        'field_site_tagline' => fake_field('field_site_tagline', 'text'),
        'options/core' => [
            'type' => 'options',
            'path' => 'options/core.json',
            'data' => $deletedOptions,
            'content' => \Duo\Canon::encode($deletedOptions),
        ],
    ]);
} catch (\Throwable $e) {
    $coldTreeAuthorized = false;
    $coldTreeError = $e->getMessage();
}
check(
    $coldTreeAuthorized,
    'RepositoryAuthorization accepts the witnessed ACF pair from one cold immutable tree with no capture history'
        . ($coldTreeError === '' ? '' : " (got: $coldTreeError)")
);

$tampered = $deletedOptions;
$tampered['records']['_options_site_tagline']['classification_witness']['value'] = 'field_site_logo';
$tamperRefused = false;
try {
    \Duo\OptionState::records($tampered);
} catch (\RuntimeException $e) {
    $tamperRefused = str_contains($e->getMessage(), 'does not match its expected_hash');
}
check($tamperRefused, 'a witness edited independently of its prior-record hash is rejected');

$misversioned = $deletedOptions;
$misversioned['format'] = 'duo-options/v1';
$misversionedRefused = false;
try {
    \Duo\OptionState::records($misversioned);
} catch (\RuntimeException $e) {
    $misversionedRefused = str_contains($e->getMessage(), 'optional v2 classification_witness');
}
check($misversionedRefused, 'the new witness is refused under the legacy v1 format tag');

$unrelatedDocument = \Duo\OptionState::document([
    // Simulate the strongest hand-edit: a syntactically valid, hash-matched
    // witness on an option no active policy/interpreter owns.
    'cron' => \Duo\OptionState::deleted(
        \Duo\OptionState::present('field_site_tagline', 'yes'),
        true
    ),
]);
$unrelatedTree = [
    'options/core' => [
        'type' => 'options',
        'path' => 'options/core.json',
        'data' => $unrelatedDocument,
        'content' => \Duo\Canon::encode($unrelatedDocument),
    ],
];
$unrelatedRefused = false;
try {
    $corePolicy = Policy::load(null, ['core']);
    \Duo\RepositoryAuthorization::assert_tree($corePolicy, $unrelatedTree);
} catch (\Duo\RepositoryAuthorizationException $e) {
    $unrelatedRefused = count(array_filter(
        $e->diagnostics,
        static fn(array $d): bool => ($d['code'] ?? '') === 'repository_option_delete_not_authored'
            && ($d['field'] ?? '') === 'cron'
            && ($d['classification'] ?? '') === 'runtime'
            && ($d['declared_by'] ?? '') === 'core'
    )) === 1;
}
check(
    $unrelatedRefused,
    'even a validly hash-bound witness cannot authorize a tombstone for a non-authored option'
);

check(
    ($policy->meta_rule_for_option('options_site_tagline', $allOptions)['class'] ?? null) === 'authored',
    'Policy::meta_rule_for_option() dispatches to the real Acf interpreter through the real manifest'
);
check(
    $policy->option_namespace('options_site_tagline') !== null
        && $policy->option_namespace('options_site_tagline')['owner'] === 'acf',
    'the real acf.json option_namespaces declaration claims the options_ prefix'
);
check(
    ($policy->owned_option_rule_via_interpreter('options_site_tagline', $allOptions)['class'] ?? null) === 'authored',
    'owned_option_rule_via_interpreter() composes namespace ownership + interpreter classification cleanly '
        . '(no false-positive ambiguous-ownership exception between the plain manifest name and the '
        . '"acf (interpreter acf)" source string)'
);
check(
    ($policy->meta_rule_for_option('options_site_tagline', $allOptions)['autoload'] ?? null) === 'preserve',
    "an interpreter-classified options rule gets the owning manifest's own option_autoload default injected "
        . '(acf.json declares "option_autoload": "preserve") -- caught live on the first run of the '
        . 'regress_acf_term_options_fields.sh sandbox test: OptionState::assert_rule_autoload() hard-requires '
        . "every options rule to declare autoload, and the interpreter dispatch path originally bypassed the "
        . 'static path\'s own with_option_autoload() injection entirely, so capture failed outright'
);
check(
    !array_key_exists('autoload', $policy->meta_rule_for_term('site_logo', ['_site_logo' => 'field_site_logo', 'site_logo' => '9']) ?? []),
    "the autoload injection is scoped to the option_rule hook specifically -- resolving the SAME primed "
        . "field_site_logo definition through meta_rule_for_term() instead (a real, non-null rule) must never "
        . "carry an autoload key, even though the owning manifest (acf.json) DOES declare option_autoload; "
        . 'term_meta has no such concept and must not silently inherit it'
);
check(
    $policy->owned_option_rule_via_interpreter('unrelated_option_nobody_owns', $allOptions) === null,
    'an option no manifest namespace claims stays null even though ACF is loaded'
);
$details = $policy->option_rule_details_for_option('options_site_tagline', $allOptions);
check(
    ($details['rule']['class'] ?? null) === 'authored' && str_contains((string) $details['source'], 'interpreter acf'),
    'option_rule_details_for_option() reports interpreter provenance, same shape meta_rule_details_for_post() uses'
);
check(
    $policy->meta_rule_for_option('wp_unrelated_option', $allOptions) === null,
    'a name the ACF interpreter itself defers on (no prefix match) still falls through to null overall '
        . '(no static options.wp_unrelated_option rule exists either)'
);

echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
