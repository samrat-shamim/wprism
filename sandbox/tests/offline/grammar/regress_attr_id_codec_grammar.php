<?php
/**
 * WP-6.1 primitive 2 — `attr_id_codecs`, the type-preserving block attribute id
 * codec, and the previously-rejected candidate that proves it SUFFICIENT.
 *
 * WHAT THIS SUITE IS FOR. `tools/engine-gaps.json` recorded the demand as the
 * primitive `type_preserving_string_id_attr_codec` (candidate WPForms Lite
 * 2.0.0.4 / 2.0.0.5, coordinate `block_attrs.wpforms/form-selector`): the block
 * persists `formId` as a JSON STRING, the attribute value vocabulary is closed
 * at `{int, int[]}`, and the apply arm wrote `(int) $v` unconditionally. The
 * identity resolved and the STORED TYPE did not survive, so a capture/apply
 * round trip that substituted nothing still changed the post's bytes:
 *
 *     source   <!-- wp:wpforms/form-selector {"formId":"12"} /-->
 *     applied  <!-- wp:wpforms/form-selector {"formId":12} /-->
 *
 * Group C1 is that exact body, and it now round-trips byte for byte.
 *
 * The four groups mirror regress_column_codec_grammar.php, because the two
 * primitives owe the same four things: A the `engine_features` staging, B the
 * grammar refusals, C the identity round-trip precondition (including a
 * substitution that changes byte length), D the rejected candidate authored end
 * to end.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';

$root = dirname(__DIR__, 4);
duo_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
// Group D's body carries ordinary inner content, which Blocks routes through
// the shortcode codec before the URL pass; without it the walker resolves the
// class lazily and finds nothing.
require_once $root . '/agent/src/Grammar/Shortcodes.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Grammar/AttrIdCodecGrammar.php';

use Duo\AttrIdCodecGrammar;
use Duo\Blocks;
use Duo\Canon;
use Duo\Policy;
use Duo\Tokens;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

// ---------------------------------------------------------------------------
// The fixture adapter: WPForms Lite 2.0.0.4, authored end to end for the ONE
// coordinate this primitive closes.
//
// It is a FIXTURE, not a shipped adapter: `manifests/` carries no WPForms
// entry, this makes no capability claim, and no adapter digest moves
// (AGENTS.md rule 2). WPForms' ledger row records two further blockers —
// `structured_post_body_reference_paths` for the JSON post body and
// `taxonomy_delete_scope_exercise` for `wpforms_form_tag` — and this manifest
// deliberately claims neither: no `post_types`, no `taxonomies`, no
// `deletions`. Group D7 asserts that, so the fixture cannot drift into
// implying a candidate this primitive did not unblock.
// ---------------------------------------------------------------------------
$wpforms = [
    'name' => 'wpforms',
    'spec_version' => 3,
    'plugin' => 'wpforms-lite/wpforms.php',
    'version_range' => ['min' => '2.0.0.4', 'max' => '2.0.0.5'],
    'option_autoload' => 'preserve',
    // Both names: `engine_features` is itself claimed by `spec-window/v1`
    // (spec/repo-format.md § v3.3's worked example), so an adapter using the
    // channel declares the channel's own feature. A5 pins it.
    'engine_features' => ['attr-id-codecs/v1', 'spec-window/v1'],
    'block_attrs' => [
        'wpforms/form-selector' => [
            ['path' => 'formId', 'kind' => 'post', 'type' => 'int'],
            ['path' => 'className', 'tokenize' => 'text'],
        ],
        // A second block, declared WITHOUT a codec, so every assertion about
        // the string arm has an integer arm beside it in the same pin set.
        'acme/legacy-selector' => [
            ['path' => 'formId', 'kind' => 'post', 'type' => 'int'],
        ],
    ],
    'attr_id_codecs' => [
        'wpforms/form-selector' => ['formId' => ['id_type' => 'string']],
    ],
];

/**
 * Load one synthetic manifest library through the REAL loader — the same
 * technique regress_ecosystem_adapter_batch.php uses, and for the same reason:
 * no dispositions file is written, so these cases exercise grammar only.
 *
 * @param array<string,array<string,mixed>> $files
 */
$load = static function (array $files): Policy {
    $dir = sys_get_temp_dir() . '/duo_attr_id_codec_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create manifest library $dir");
    }
    foreach ($files as $name => $manifest) {
        Canon::write_file("$dir/$name.json", Canon::encode($manifest));
    }
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    });
    putenv('DUO_MANIFESTS_DIR=' . $dir);
    return Policy::load(null, array_keys($files));
};

/** @param array<string,mixed> $overlay */
$variant = static fn(array $overlay): array => array_replace($wpforms, $overlay);

// ===========================================================================
// A. The `engine_features` staging channel (spec/repo-format.md § v3.2, § v3.3)
// ===========================================================================

duo_check_same(3, DUO_SPEC_VERSION, 'DUO_SPEC_VERSION is still 3 — `attr_id_codecs` shipped through engine_features, not a bump');

$policy = $load(['wpforms' => $wpforms]);
duo_check_same(
    ['wpforms'],
    array_column($policy->manifests, 'name'),
    'A1: a spec_version 3 manifest declaring the feature AND the section loads through the real loader'
);
duo_check_same(
    ['wpforms/form-selector' => ['formId' => ['id_type' => 'string']]],
    $policy->attr_id_codec_rules(),
    'A1: the loaded policy projects the codec for the declaring block and for no other'
);

duo_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant(['spec_version' => 2, 'engine_features' => null])]),
    RuntimeException::class,
    'A2: a spec_version 2 manifest declaring the section is refused BY SECTION, naming the version that has it',
    "the section 'attr_id_codecs', which this engine implements only at spec_version 3"
);

$noFeature = $wpforms;
unset($noFeature['engine_features']);
duo_check_throws(
    static fn(): Policy => $load(['wpforms' => $noFeature]),
    RuntimeException::class,
    'A3: at spec_version 3 the key set is CLOSED, so the section without its feature is refused BY KEY',
    "the top-level key 'attr_id_codecs', which this engine does not recognise"
);

duo_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant([
        'engine_features' => ['attr-id-codecs/v2', 'spec-window/v1'],
    ])]),
    RuntimeException::class,
    'A4: a feature name this engine does not implement is refused BY FEATURE NAME',
    "declares engine feature 'attr-id-codecs/v2'"
);

duo_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant(['engine_features' => ['attr-id-codecs/v1']])]),
    RuntimeException::class,
    'A5: declaring only the section\'s own feature refuses — `engine_features` is itself claimed by `spec-window/v1`',
    "the top-level key 'engine_features', which this engine does not recognise"
);

// ===========================================================================
// B. The grammar: every declaration that would load and then do nothing
// ===========================================================================

/** @param array<string,mixed> $codecs */
$refuse = static function (array $codecs, string $fragment, string $message) use ($load, $variant): void {
    duo_check_throws(
        static fn(): Policy => $load(['wpforms' => $variant(['attr_id_codecs' => $codecs])]),
        RuntimeException::class,
        $message,
        $fragment
    );
};

$refuse(
    [],
    'attr_id_codecs must be a non-empty object',
    'B1: an empty section declares a capability the adapter does not use'
);
$refuse(
    ['wpforms/form-selector' => []],
    'must be a non-empty object keyed by attribute path',
    'B2: a block entry naming no attribute is refused'
);
$refuse(
    ['wpforms/other-block' => ['formId' => ['id_type' => 'string']]],
    'names a block this manifest declares no block_attrs rules for',
    'B3: a codec for a block with no declared rules is refused — it would never run'
);
$refuse(
    ['wpforms/form-selector' => ['formID' => ['id_type' => 'string']]],
    "names an attribute path none of this block's block_attrs rules declares",
    'B4: a transposed path is refused rather than silently skipped at rewrite time'
);
$refuse(
    ['wpforms/form-selector' => ['className' => ['id_type' => 'string']]],
    "refines a rule whose disposition is 'tokenize'",
    'B5: a codec over a text-tokenized attribute is refused — that rule resolves no id'
);
$refuse(
    ['wpforms/form-selector' => ['formId' => ['id_type' => 'int']]],
    'the stored-id type vocabulary is closed and engine-owned',
    'B6: `int` is refused — it is what every rule already writes, so there is no declaration to make'
);
$refuse(
    ['wpforms/form-selector' => ['formId' => ['id_type' => 'string', 'cast' => 'csv']]],
    'an id codec is exactly {id_type}',
    'B7: an extra member is refused; the codec vocabulary is exact'
);
$refuse(
    ['wpforms/form-selector' => ['formId' => 'string']],
    'must be an object declaring exactly {id_type}',
    'B8: a bare string codec is refused rather than coerced'
);

$listRule = $wpforms['block_attrs'];
$listRule['wpforms/form-selector'][0]['type'] = 'int[]';
duo_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant(['block_attrs' => $listRule])]),
    RuntimeException::class,
    'B9: a codec over an int[] rule is refused — a native list of ids has a per-element type this primitive does not claim',
    'an id codec applies to a scalar id attribute only'
);

$lintOkRule = $wpforms['block_attrs'];
$lintOkRule['wpforms/form-selector'][0] = ['path' => 'formId', 'lint_ok' => true];
duo_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant(['block_attrs' => $lintOkRule])]),
    RuntimeException::class,
    'B10: a codec over a declared non-ref attribute is refused',
    "refines a rule whose disposition is 'lint_ok'"
);

duo_check_same(
    ['string'],
    Policy::closed_vocabularies()['attribute_id_types'],
    'B11: the stored-id type vocabulary is published from the same const the refusal consults'
);
duo_check_same(
    ['int', 'int[]'],
    Policy::closed_vocabularies()['attribute_value_types'],
    'B11: `block_attrs`\' own value vocabulary is untouched — no shipped manifest\'s bytes move'
);

// ===========================================================================
// C. The codec through the product path: Blocks::capture_rewrite/apply_rewrite
// ===========================================================================

WpStore::reset()->seedOptions(['home' => 'https://source.example']);
$wpdb = FakeWpdb::install();
$formUuid = '019200cc-0000-7000-8000-0000000000f1';
$seedMap = static function (int $localId) use ($wpdb, $formUuid): void {
    $wpdb->seedTable('wp_duo_map', [[
        'id' => 1,
        'uuid' => $formUuid,
        'entity_type' => 'post',
        'id_kind' => 'post',
        'local_id' => $localId,
    ]]);
};
$tokensFor = static function (Policy $policy): Tokens {
    $tokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
    $tokens->policy = $policy;
    return $tokens;
};

$seedMap(12);
$source = '<!-- wp:wpforms/form-selector {"formId":"12"} /-->';
$captured = trim(Blocks::capture_rewrite($source, $policy, $tokensFor($policy), false, "page 'contact'"));
duo_check_same(
    '<!-- wp:wpforms/form-selector {"formId":"{{post:' . $formUuid . '}}"} /-->',
    $captured,
    'C1: capture resolves the string id to a token, exactly as it does for an integer one'
);

// C1 — THE IDENTITY ROUND-TRIP PRECONDITION, through the product path.
$applied = trim(Blocks::apply_rewrite($captured, $policy, $tokensFor($policy)));
duo_check_same(
    $source,
    $applied,
    'C1: apply on the SAME environment reproduces the post body byte for byte — the round trip that used to write {"formId":12}'
);

// C2 — a substitution that CHANGES BYTE LENGTH: the same form is row 3456 on
// the target, so the attribute is four characters where it was two.
$seedMap(3456);
duo_check_same(
    '<!-- wp:wpforms/form-selector {"formId":"3456"} /-->',
    trim(Blocks::apply_rewrite($captured, $policy, $tokensFor($policy))),
    'C2: a target-local id of a different length is written back as a string of that length'
);

// C3 — the arm with NO codec is byte-identical to the pre-WP-6.1 behaviour.
$seedMap(12);
$legacy = '<!-- wp:acme/legacy-selector {"formId":12} /-->';
$legacyCaptured = trim(Blocks::capture_rewrite($legacy, $policy, $tokensFor($policy), false, "page 'contact'"));
duo_check_same(
    $legacy,
    trim(Blocks::apply_rewrite($legacyCaptured, $policy, $tokensFor($policy))),
    'C3: a block with no codec still round-trips its integer id as an integer'
);
$seedMap(3456);
duo_check_same(
    '<!-- wp:acme/legacy-selector {"formId":3456} /-->',
    trim(Blocks::apply_rewrite($legacyCaptured, $policy, $tokensFor($policy))),
    'C3: and still writes a target-local integer, unquoted'
);

// C4 — the capture-side precondition. A declaration that is FALSE about this
// source is refused before the value is tokenized, because apply writes the
// declared type and would otherwise change bytes nobody asked to change.
$seedMap(12);
duo_check_throws(
    static fn(): string => Blocks::capture_rewrite(
        '<!-- wp:wpforms/form-selector {"formId":12} /-->',
        $policy,
        $tokensFor($policy),
        false,
        "page 'contact'"
    ),
    RuntimeException::class,
    'C4: an integer source under an id_type=string declaration is refused BEFORE substitution',
    'Refusing before substitution'
);
duo_check_throws(
    static fn(): string => Blocks::capture_rewrite(
        '<!-- wp:wpforms/form-selector {"formId":12} /-->',
        $policy,
        $tokensFor($policy),
        false,
        "page 'contact'"
    ),
    RuntimeException::class,
    'C4: the refusal names the block, the attribute and the type it actually found',
    "block 'wpforms/form-selector' attribute 'formId' declares the id codec id_type=string, but this source stores it as int"
);

// C5 — the unset convention is a decision about ABSENCE and is decided before
// the type question, so it is unchanged in both arms.
duo_check_same(
    '<!-- wp:wpforms/form-selector /-->',
    trim(Blocks::capture_rewrite(
        '<!-- wp:wpforms/form-selector {"formId":"0"} /-->',
        $policy,
        $tokensFor($policy),
        false,
        "page 'contact'"
    )),
    'C5: a "0" id is still WordPress\'s unset convention — the attribute drops, no type refusal'
);

// C6 — the pure encoder, so the one place the JSON type is decided is pinned
// independently of the block walker.
duo_check_same(
    '77',
    AttrIdCodecGrammar::encode_id(77, ['formId' => ['id_type' => 'string']], 'formId'),
    'C6: encode_id writes the declared string'
);
duo_check_same(
    77,
    AttrIdCodecGrammar::encode_id(77, ['formId' => ['id_type' => 'string']], 'otherId'),
    'C6: an undeclared path keeps the integer default'
);
duo_check_same(77, AttrIdCodecGrammar::encode_id(77, [], 'formId'), 'C6: no codecs at all keeps the integer default');

// ===========================================================================
// D. THE SUFFICIENCY PROOF — the rejected candidate, round-tripped end to end
// ===========================================================================

$seedMap(12);
$body = "<!-- wp:paragraph -->\n<p>Contact us at <a href=\"https://source.example/contact\">contact</a>.</p>\n"
    . "<!-- /wp:paragraph -->\n\n"
    . '<!-- wp:wpforms/form-selector {"className":"https://source.example/style.css","formId":"12"} /-->' . "\n\n"
    . '<!-- wp:group --><div class="wp-block-group">'
    . '<!-- wp:wpforms/form-selector {"formId":"12"} /--></div><!-- /wp:group -->';

$capturedBody = Blocks::capture_rewrite($body, $policy, $tokensFor($policy), false, "page 'contact'");
duo_check(
    !str_contains($capturedBody, '"formId":"12"') && !str_contains($capturedBody, '"formId":12'),
    'D1: no environment-local form id survives into canonical state, at the top level or inside a group'
);
duo_check_same(
    2,
    substr_count($capturedBody, '"formId":"{{post:' . $formUuid . '}}"'),
    'D2: both occurrences — including the nested one — captured as a STRING-typed token'
);
duo_check(
    str_contains($capturedBody, '"className":"{{home}}/style.css"'),
    'D3: the sibling text-tokenized attribute is unaffected by the id codec'
);

duo_check_same(
    $body,
    Blocks::apply_rewrite($capturedBody, $policy, $tokensFor($policy)),
    'D4: the whole post body round-trips byte for byte on the source environment'
);

$seedMap(3456);
$onTarget = Blocks::apply_rewrite($capturedBody, $policy, $tokensFor($policy));
duo_check_same(
    2,
    substr_count($onTarget, '"formId":"3456"'),
    'D5: on a target whose form is a different row, both blocks are rebound and both stay strings'
);
duo_check(
    !str_contains($onTarget, '"formId":3456'),
    'D5: and neither is written as the bare integer the pre-WP-6.1 codec produced'
);

// D6 — capture is a fixed point: re-capturing what apply produced on the source
// gives the identical canonical bytes.
$seedMap(12);
duo_check_same(
    $capturedBody,
    Blocks::capture_rewrite(
        Blocks::apply_rewrite($capturedBody, $policy, $tokensFor($policy)),
        $policy,
        $tokensFor($policy),
        false,
        "page 'contact'"
    ),
    'D6: apply-then-capture on the source environment is a fixed point'
);

// D7 — the coordinates that stay OPEN. WPForms' ledger row records two further
// blockers, and this fixture claims neither.
duo_check(
    !array_key_exists('post_types', $wpforms)
        && !array_key_exists('taxonomies', $wpforms)
        && !array_key_exists('deletions', $wpforms),
    'D7: the fixture adapter claims no post type, taxonomy or deletion — WPForms\' other two coordinates stay open'
);

duo_check_summary('regress_attr_id_codec_grammar');
