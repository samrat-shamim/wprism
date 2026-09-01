<?php
/**
 * WP-6.5 — `body_refs` and the `json` post-type body mode: structured
 * post-body reference paths, and the previously-rejected candidate that proves
 * them SUFFICIENT.
 *
 * WHAT THIS SUITE IS FOR. `tools/engine-gaps.json` recorded the demand as the
 * primitive `structured_post_body_reference_paths` — the ledger's #1 open
 * primitive by demand — blocked candidate WPForms Lite 2.0.0.4 / 2.0.0.5,
 * coordinate `post_types.wpforms.body`: `wpforms` post_content is a JSON
 * document carrying cross-entity references inside it, and the body mode
 * vocabulary was closed at `{blocks, verbatim, serialized}` with `blocks` as
 * the default for an undeclared body. So the only three answers available were
 * "run a block parser over a document with no blocks", "decode PHP
 * serialization that is not there", and "preserve the bytes, including the
 * source-local id".
 *
 * THE FIXTURES ARE REAL CAPTURES, NOT HAND-WRITTEN BODIES.
 * `sandbox/tests/fixtures/wpforms-body/*.raw.json` are four `post_content`
 * values recorded on 2026-08-25 from a live WPForms Lite 2.0.0.5 pair, every
 * one authored through the plugin's OWN write path
 * (`wpforms()->obj('form')->add()/update()`, includes/class-form.php:535/:671)
 * rather than by `wp post create --post_content=…`. That distinction is the
 * whole reason this suite can exhibit what it exhibits:
 * `sandbox/tests/grind/grind_adapter_walk.sh:582-586` hand-writes a WPForms
 * body as `{"id":"1",…}` on a post whose id is not 1, and a hand-written
 * fixture has no confirmations, no page reference and no sentinel — which is
 * exactly why the three facts group D turns on were never seen before. The
 * content is synthetic exercise state (form titles "Recon …", `{admin_email}`
 * smart tags, a `localhost:9620` pair URL, the pair's own site name "WPrism
 * wpfrecon1"); it carries no real user data.
 *
 * THE THREE MEASUREMENTS THE GRAMMAR IS SHAPED BY, each asserted below against
 * the bytes rather than described:
 *   - form-b `$.settings.confirmations.1.page` is the JSON STRING `"4"`
 *     pointing at page 4, and `$.settings.confirmations.3.page` is the literal
 *     `"previous_page"` on the SAME key (includes/class-process.php:1553-1562
 *     branches on exactly that before `get_permalink((int) …)`). Type
 *     preservation and sentinels are one fixture, not two.
 *   - `$.id` is ABSENT on form-a and form-b, INT `12` on form-pathb
 *     (`['builder' => false]`), STRING `"14"` on form-pathc (the real builder
 *     save). The ledger's own sentence — "includes the form's source-local
 *     numeric `id`" — is true for one of the three and not for the common one.
 *   - all four bodies survive decode/re-encode byte for byte under
 *     `wp_json_encode()`'s default flags, which is what makes the identity
 *     round-trip precondition a check rather than an obstacle.
 *
 * The five groups mirror `regress_attr_id_codec_grammar.php` and
 * `regress_column_codec_grammar.php`, because the three primitives owe the same
 * things: A the `engine_features` staging, B the grammar refusals, C the
 * identity round-trip precondition and the sentinel/type rules through the
 * codec, D the rejected candidate authored end to end THROUGH THE REAL
 * `PostCapture` product seam, E the lint gap the recon measured (2 of 4 real
 * cross-entity references found, because nothing looked inside a JSON body).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Grammar/BodyRefGrammar.php';
require_once $root . '/agent/src/Capture/EntityMetaCapture.php';
require_once $root . '/agent/src/Capture/MediaCapture.php';
require_once $root . '/agent/src/Capture/PostCapture.php';

use WPrism\BodyRefGrammar;
use WPrism\Canon;
use WPrism\EntityMetaCapture;
use WPrism\MediaCapture;
use WPrism\Policy;
use WPrism\PostCapture;
use WPrism\PostTypeGrammar;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$fixtures = $root . '/sandbox/tests/fixtures/wpforms-body';
$capture = static fn(string $name): string => rtrim(
    (string) file_get_contents($fixtures . '/' . $name . '.post_content.raw.json'),
    "\n"
);

// ---------------------------------------------------------------------------
// The fixture adapter: WPForms Lite 2.0.0.5, authored end to end for the ONE
// coordinate this primitive closes.
//
// A FIXTURE, not a shipped adapter: `manifests/` carries no WPForms entry, this
// makes no capability claim, and no adapter digest moves (AGENTS.md rule 2).
// The two paths declared here are the two the live recon measured as genuine
// cross-entity references inside the BODY, and nothing else — `$.field_id`
// (an allocator), `$.fields.<n>.id` (form-local field ids) and the field ids
// inside smart-tag prose are all deliberately undeclared, and group D asserts
// they come through untouched.
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
    'engine_features' => ['spec-window/v1', 'structured-body-refs/v1'],
    'post_types' => [
        'wpforms' => ['class' => 'authored', 'body' => 'json'],
    ],
    'body_refs' => [
        'wpforms' => [
            'json_refs' => [
                ['path' => '$.settings.confirmations.*.page', 'kind' => 'post', 'cast' => 'string'],
            ],
            'sentinels' => ['$.settings.confirmations.*.page' => ['previous_page']],
        ],
    ],
];

/**
 * Load one synthetic manifest library through the REAL loader — the technique
 * `regress_attr_id_codec_grammar.php` and `regress_ecosystem_adapter_batch.php`
 * both use. The closed fixture library carries synthetic dispositions only to
 * satisfy the physical inventory contract; these cases exercise grammar.
 *
 * @param array<string,array<string,mixed>> $files
 */
$load = static function (array $files): Policy {
    $dir = sys_get_temp_dir() . '/wprism_body_ref_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create manifest library $dir");
    }
    foreach ($files as $name => $manifest) {
        Canon::write_file("$dir/$name.json", Canon::encode($manifest));
    }
    register_shutdown_function(static function () use ($dir): void {
        manifest_fixture_remove_tree($dir);
    });
    return Policy::load(
        null,
        array_keys($files),
        adapterLibrary: manifest_fixture_adapter_library($dir)
    );
};

/** @param array<string,mixed> $overlay */
$variant = static fn(array $overlay): array => array_replace($wpforms, $overlay);

// ===========================================================================
// A. The `engine_features` staging channel (spec/repo-format.md § v3.2, § v3.3)
// ===========================================================================

wprism_check_same(
    3,
    WPRISM_SPEC_VERSION,
    'WPRISM_SPEC_VERSION is still 3 — `body_refs` and the `json` body mode shipped through engine_features, not a bump'
);

$policy = $load(['wpforms' => $wpforms]);
wprism_check_same(
    ['wpforms'],
    array_column($policy->manifests, 'name'),
    'A1: a spec_version 3 manifest declaring the feature, the mode AND the section loads through the real loader'
);
wprism_check_same('json', $policy->body_mode('wpforms'), 'A1: the loaded policy reports the json body mode for the declaring type');
wprism_check_same(
    // Key order is Canon::encode()'s, not the author's — the loader reads the
    // canonical bytes it wrote, so the assertion compares the same normal form.
    wprism_check_ksort_recursive($wpforms['body_refs']['wpforms']),
    wprism_check_ksort_recursive($policy->body_ref_rule('wpforms')),
    'A1: and projects the declared paths and sentinels for that type'
);
wprism_check_same(null, $policy->body_ref_rule('page'), 'A1: and projects nothing for a type the manifest says nothing about');

wprism_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant(['spec_version' => 2, 'engine_features' => null])]),
    RuntimeException::class,
    'A2: a spec_version 2 manifest declaring the section is refused BY SECTION, naming the version that has it',
    "the section 'body_refs', which this engine implements only at spec_version 3"
);

$noFeature = $wpforms;
unset($noFeature['engine_features']);
wprism_check_throws(
    static fn(): Policy => $load(['wpforms' => $noFeature]),
    RuntimeException::class,
    'A3: at spec_version 3 the key set is CLOSED, so the section without its feature is refused BY KEY',
    "the top-level key 'body_refs', which this engine does not recognise"
);

wprism_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant([
        'engine_features' => ['spec-window/v1', 'structured-body-refs/v2'],
    ])]),
    RuntimeException::class,
    'A4: a feature name this engine does not implement is refused BY FEATURE NAME',
    "declares engine feature 'structured-body-refs/v2'"
);

wprism_check_throws(
    static fn(): Policy => $load(['wpforms' => $variant(['engine_features' => ['structured-body-refs/v1']])]),
    RuntimeException::class,
    'A5: declaring only the section\'s own feature refuses — `engine_features` is itself claimed by `spec-window/v1`',
    "the top-level key 'engine_features', which this engine does not recognise"
);

// A2/A3/A4 above are the ORDERING assertion as much as the verdict assertion:
// each of those manifests ALSO declares `body: "json"`, and each is refused by
// the § v3.2/§ v3.3 verdict rather than by the body-mode gate. That is why the
// gate lives in BodyRefGrammar's late slot and not in
// PostTypeGrammar::validate_post_type_contracts(), which runs first — refusing
// there would have told a spec_version-2 author about an engine feature when
// what is wrong is the version the whole document declares.

// A6 — the OTHER half of the gate, and the one no top-level key can carry: the
// body MODE is a value inside a vocabulary that already exists, so a manifest
// declaring the mode and NO section reaches no § v3.2 verdict at all.
$modeOnly = $wpforms;
unset($modeOnly['engine_features'], $modeOnly['body_refs']);
wprism_check_throws(
    static fn(): Policy => $load(['wpforms' => $modeOnly]),
    RuntimeException::class,
    'A6: `body: "json"` without the feature is refused BY FEATURE NAME, not as a misspelling',
    "declares post_types.wpforms.body='json', which the engine feature 'structured-body-refs/v1' gates"
);
wprism_check_throws(
    static fn(): Policy => $load(['wpforms' => $modeOnly]),
    RuntimeException::class,
    'A6: and the remedy names the list to declare it in, and that the list needs spec_version 3',
    'declare it in this manifest\'s top-level "engine_features" list (which itself requires spec_version 3'
);

// A7 — the base three are byte for byte what they were for a manifest that
// declares no feature. The pinned refusal in
// regress_vocabulary_ownership.php reads this exact sentence.
$typo = ['name' => 'fixture', 'post_types' => ['acme_widget' => ['body' => 'verbatm']]];
wprism_check_throws(
    static function () use ($typo): void { PostTypeGrammar::validate_post_type_contracts($typo); },
    RuntimeException::class,
    'A7: a manifest declaring no feature still sees exactly the three ungated modes in its refusal',
    "post_types.acme_widget.body='verbatm' but the vocabulary is closed (blocks, verbatim, serialized)"
);
$typoWithFeature = [
    'name' => 'fixture',
    'engine_features' => ['spec-window/v1', 'structured-body-refs/v1'],
    'post_types' => ['acme_widget' => ['body' => 'jsn']],
];
wprism_check_throws(
    static function () use ($typoWithFeature): void { PostTypeGrammar::validate_post_type_contracts($typoWithFeature); },
    RuntimeException::class,
    'A7: and a manifest that DID declare the feature sees four — the vocabulary a refusal prints is the one that refused',
    'the vocabulary is closed (blocks, verbatim, serialized, json)'
);

wprism_check_same(
    ['blocks', 'verbatim', 'serialized'],
    Policy::closed_vocabularies()['post_type_body_modes'],
    'A8: the published ungated body vocabulary is unchanged — no shipped manifest\'s bytes move'
);
wprism_check_same(
    ['structured-body-refs/v1' => ['json']],
    Policy::closed_vocabularies()['feature_gated_post_type_body_modes'],
    'A8: and the gated member is published BESIDE it, keyed by the feature that admits it, never folded in'
);

// ===========================================================================
// B. The grammar: every declaration that would load and then do nothing
// ===========================================================================

/** @param array<string,mixed> $overlay */
$refuse = static function (array $overlay, string $fragment, string $message) use ($load, $variant): void {
    wprism_check_throws(
        static fn(): Policy => $load(['wpforms' => $variant($overlay)]),
        RuntimeException::class,
        $message,
        $fragment
    );
};

$refuse(
    ['body_refs' => []],
    'body_refs must be a non-empty object',
    'B1: an empty section declares a capability the adapter does not use'
);
$refuse(
    ['body_refs' => ['wpforms_other' => $wpforms['body_refs']['wpforms']]],
    'names a post type this manifest does not declare as post_types.wpforms_other.body=json',
    'B2: paths for a post type that is not in json mode are refused — they would never run'
);
$refuse(
    ['body_refs' => ['wpforms' => ['json_refs' => []]]],
    'body_refs.wpforms.json_refs must be a non-empty list',
    'B3: a json post type whose path list is empty is refused'
);
$refuse(
    ['body_refs' => ['wpforms' => ['json_refs' => [['path' => 'settings.page', 'kind' => 'post']]]]],
    "json_refs/key_refs path 'settings.page' must start with '$'",
    'B4: the path dialect is the SHIPPED json_refs dialect — an author gets the refusal they already know'
);
$refuse(
    ['body_refs' => ['wpforms' => ['json_refs' => [['path' => '$.a', 'kind' => 'post', 'cast' => 'csv']]]]],
    "body_refs.wpforms.json_refs[0].cast must be 'string' when present",
    'B5: and the cast vocabulary is the shipped one, reported at the author\'s own locator'
);
$refuse(
    ['body_refs' => ['wpforms' => ['json_refs' => [
        ['path' => '$.settings.confirmations.1.page', 'kind' => 'post'],
        ['path' => '$.settings.confirmations.*.page', 'kind' => 'post'],
    ]]]],
    'has ambiguous overlapping json_refs paths',
    'B6: two paths that can select the same leaf are refused by the shipped NFA intersection, not by a second copy of it'
);
$refuse(
    ['body_refs' => ['wpforms' => [
        'json_refs' => [['path' => '$.a', 'kind' => 'post']],
        'key_refs' => ['kind' => 'post'],
    ]]],
    '`key_refs` in particular is NOT admitted',
    'B7: an id-KEYED map inside a post body has no measured demand and is refused by name'
);
$refuse(
    ['body_refs' => ['wpforms' => [
        'json_refs' => [['path' => '$.a', 'kind' => 'post']],
        'sentinels' => ['$.b' => ['x']],
    ]]],
    "names path '\$.b', which none of this post type's json_refs entries declares",
    'B8: a sentinel set over an undeclared path is refused — it would never be consulted'
);
$refuse(
    ['body_refs' => ['wpforms' => [
        'json_refs' => [['path' => '$.a', 'kind' => 'post']],
        'sentinels' => ['$.a' => ['4']],
    ]]],
    "is the numeric literal '4'",
    'B9: a NUMERIC sentinel is refused — it is indistinguishable from the id the path resolves'
);
$refuse(
    ['body_refs' => ['wpforms' => [
        'json_refs' => [['path' => '$.a', 'kind' => 'post']],
        'sentinels' => ['$.a' => []],
    ]]],
    "body_refs.wpforms.sentinels['\$.a'] must be a non-empty list of strings",
    'B10: an empty sentinel list is refused rather than meaning "none"'
);
$refuse(
    ['body_refs' => ['wpforms' => [
        'json_refs' => [['path' => '$.a', 'kind' => 'post']],
        'sentinels' => [],
    ]]],
    'sentinels must be a non-empty object keyed by a declared json_refs path',
    'B10: and an empty sentinel MAP is refused too — a declared capability the adapter does not use'
);

// B11 — the other direction, and the one that would otherwise fail SILENTLY on
// a live site rather than at load.
$modeNoPaths = $wpforms;
unset($modeNoPaths['body_refs']);
wprism_check_throws(
    static fn(): Policy => $load(['wpforms' => $modeNoPaths]),
    RuntimeException::class,
    'B11: body=json with no declared paths is refused — that is `verbatim` with an extra refusal surface',
    'so a json body with no paths is `verbatim` with an extra refusal surface'
);

// ===========================================================================
// C. The codec: identity round trip, sentinels, and the declared JSON type
// ===========================================================================

WpStore::reset()->seedOptions(['home' => 'https://source.example']);
$wpdb = FakeWpdb::install();
$pageUuid = '019200cc-0000-7000-8000-0000000000a4';
$seedMap = static function (int $localId) use ($wpdb, $pageUuid): void {
    $wpdb->seedTable('wp_wprism_map', [[
        'id' => 1,
        'uuid' => $pageUuid,
        'entity_type' => 'post',
        'id_kind' => 'post',
        'local_id' => $localId,
    ]]);
};
$tokensFor = static function (): Tokens {
    $tokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
    return $tokens;
};
$rule = $policy->body_ref_rule('wpforms');
$idToToken = static fn(Tokens $t): callable => static fn(int $id, string $kind): ?string => $t->id_to_token($id, $kind);
$tokenToId = static fn(Tokens $t): callable => static fn(string $token): int => $t->token_to_id($token);

// C1 — THE IDENTITY ROUND-TRIP PRECONDITION on all four real captures. Nothing
// resolves (the ledger is empty), so every reference drops to null and comes
// back null; what is asserted is that the four documents decode and re-encode
// to their exact input bytes, which is the property the whole mode rests on.
foreach (['form-a', 'form-b', 'form-pathb', 'form-pathc'] as $name) {
    $raw = $capture($name);
    wprism_check_same(
        json_decode($raw, true),
        json_decode(json_encode(json_decode($raw, true), 0) ?: '', true),
        "C1: the real $name capture decodes and re-encodes to an identical document"
    );
    wprism_check_same(
        $raw,
        json_encode(json_decode($raw, true), 0),
        'C1: and to identical BYTES — the precondition BodyRefGrammar::decode() asserts before substitution'
    );
}

$seedMap(4);
$formB = $capture('form-b');
$warnings = [];
$capturedB = BodyRefGrammar::capture(
    $formB,
    $rule,
    $idToToken($tokensFor()),
    static function (string $w) use (&$warnings): void { $warnings[] = $w; },
    "wpforms 'recon-signup-form'"
);
wprism_check_same([], $warnings, 'C2: the page reference resolves, so nothing is dropped and nothing is warned about');
wprism_check(
    str_contains($capturedB, '"page":"{{post:' . $pageUuid . '}}"'),
    'C2: the STRING page id "4" becomes a STRING-typed token'
);
wprism_check(
    !str_contains($capturedB, '"page":"4"'),
    'C2: and no environment-local page id survives into canonical state'
);
wprism_check(
    str_contains($capturedB, '"page":"previous_page"'),
    'C2: the declared SENTINEL on the SAME key is passed through untouched'
);

$secretDocument = json_decode($formB, true, 512, JSON_THROW_ON_ERROR);
$secretDocument['settings']['integration'] = ['Authorization' => 'GeneratedValue-2026-Blocked'];
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        json_encode($secretDocument, JSON_THROW_ON_ERROR),
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'secret-clearance'"
    ),
    RuntimeException::class,
    'C2: decoded JSON key/value pairs receive full credential clearance rather than only hard-token scanning',
    'credential-shaped value'
);
$secretContainerDocument = json_decode($formB, true, 512, JSON_THROW_ON_ERROR);
$secretContainerDocument['settings']['integration'] = [
    'password' => ['primary' => 'GeneratedValue-2026-Blocked'],
];
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        json_encode($secretContainerDocument, JSON_THROW_ON_ERROR),
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'secret-container-clearance'"
    ),
    RuntimeException::class,
    'C2: a credential-bearing JSON container retains its role through generic child keys',
    'credential-shaped value'
);
$secretKeyDocument = json_decode($formB, true, 512, JSON_THROW_ON_ERROR);
$secretKeyDocument['settings']['integration'] = ['sk_live_JSONKEY1234567890' => 'enabled'];
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        json_encode($secretKeyDocument, JSON_THROW_ON_ERROR),
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'secret-key-clearance'"
    ),
    RuntimeException::class,
    'C2: a hard secret used as a decoded JSON map key cannot evade clearance',
    'stripe key'
);
$piiDocument = json_decode($formB, true, 512, JSON_THROW_ON_ERROR);
$piiDocument['settings']['customerProfile'] = ['firstName' => 'Private Customer'];
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        json_encode($piiDocument, JSON_THROW_ON_ERROR),
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'pii-clearance'"
    ),
    RuntimeException::class,
    'C2: decoded JSON personal-data keys refuse before canonical publication',
    'personal name'
);
$piiKeyDocument = json_decode($formB, true, 512, JSON_THROW_ON_ERROR);
$piiKeyDocument['settings']['audience'] = ['alice@example.test' => 'enabled'];
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        json_encode($piiKeyDocument, JSON_THROW_ON_ERROR),
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'pii-key-clearance'"
    ),
    RuntimeException::class,
    'C2: an email used as a decoded JSON map key cannot evade clearance',
    'email address'
);

wprism_check_same(
    $formB,
    BodyRefGrammar::apply($capturedB, $rule, $tokenToId($tokensFor()), "wpforms 'recon-signup-form'"),
    'C3: apply on the SAME environment reproduces the post body byte for byte'
);

// C4 — a substitution that CHANGES BYTE LENGTH, and keeps the declared type.
$seedMap(3456);
$onTarget = BodyRefGrammar::apply($capturedB, $rule, $tokenToId($tokensFor()), "wpforms 'recon-signup-form'");
wprism_check(
    str_contains($onTarget, '"page":"3456"'),
    'C4: a target-local id of a different length is written back as a STRING of that length'
);
wprism_check(
    !str_contains($onTarget, '"page":3456'),
    'C4: and never as the bare integer the generic json_refs default would have written'
);
wprism_check(
    str_contains($onTarget, '"page":"previous_page"'),
    'C4: the sentinel is still the sentinel on the target'
);

// C5 — the undeclared-literal refusal, which is what makes the sentinel a
// DECLARATION rather than a guess.
$undeclared = str_replace('"page":"previous_page"', '"page":"next_page"', $formB);
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        $undeclared,
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'recon-signup-form'"
    ),
    RuntimeException::class,
    'C5: an undeclared non-numeric literal at a declared reference path REFUSES rather than being guessed at',
    'which is neither a positive id nor a declared sentinel for this path'
);
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        $undeclared,
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'recon-signup-form'"
    ),
    RuntimeException::class,
    'C5: and the refusal hands the author the exact declaration that resolves it',
    "add the literal to body_refs.<type>.sentinels['\$.settings.confirmations.*.page']"
);

// C6 — the type precondition, in BOTH directions, because apply writes the
// declared type either way.
$asInt = str_replace('"page":"4"', '"page":4', $formB);
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        $asInt,
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'recon-signup-form'"
    ),
    RuntimeException::class,
    'C6: an INT source under a cast=string declaration is refused BEFORE substitution',
    'declares cast=string, but this source stores it as int'
);
$intRule = ['json_refs' => [['path' => '$.settings.confirmations.*.page', 'kind' => 'post']], 'sentinels' => []];
wprism_check_throws(
    static fn(): string => BodyRefGrammar::capture(
        $formB,
        $intRule,
        $idToToken($tokensFor()),
        static function (): void {},
        "wpforms 'recon-signup-form'"
    ),
    RuntimeException::class,
    'C6: and a STRING source under the integer default is refused too — the rule is symmetric',
    'declares no cast (the integer default), but this source stores it as string'
);

// C7 — the two round-trip hazards the precondition exists for, measured rather
// than asserted from the docblock.
wprism_check_throws(
    static fn(): array => BodyRefGrammar::decode('{"0":"a","1":"b"}', 'fixture'),
    RuntimeException::class,
    'C7: an object whose keys are "0","1" decodes to a PHP list and re-encodes as a JSON ARRAY — refused',
    'does not survive a decode/re-encode round trip unchanged'
);
wprism_check_throws(
    static fn(): array => BodyRefGrammar::decode('{"a":{}}', 'fixture'),
    RuntimeException::class,
    'C7: an empty JSON object re-encodes as [] — refused',
    'Refusing before substitution'
);
wprism_check_throws(
    static fn(): array => BodyRefGrammar::decode('{"a":"http://x/y"}', 'fixture'),
    RuntimeException::class,
    'C7: an encoder that did not escape slashes the way wp_json_encode() does is refused, not accommodated',
    // Matched without the parentheses because the refusal is written without
    // them: BodyRefGrammar is in `wprism manifest-validate`'s boot() load set, and
    // that command's WordPress-free guard scans non-comment lines for `wp_*(`.
    'an encoder that did not use `wp_json_encode`\'s defaults'
);
wprism_check_throws(
    static fn(): array => BodyRefGrammar::decode('not json at all', 'fixture'),
    RuntimeException::class,
    'C7: a body the mode cannot decode names the mode rather than failing somewhere downstream',
    'but its post_content is not a JSON document'
);

// C8 — the unset convention, decided before type and before sentinels.
wprism_check_same(
    '{"settings":{"confirmations":{"1":{"page":"0"},"2":{"page":""}}}}',
    BodyRefGrammar::capture(
        '{"settings":{"confirmations":{"1":{"page":"0"},"2":{"page":""}}}}',
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        'fixture'
    ),
    'C8: "0" and "" are WordPress\'s unset convention — untouched, and no type refusal'
);

// C9 — a dangling reference is a null canonical value, never a raw id, and it
// says so out loud.
$wpdb->seedTable('wp_wprism_map', []);
$warnings = [];
$dangling = BodyRefGrammar::capture(
    $formB,
    $rule,
    $idToToken($tokensFor()),
    static function (string $w) use (&$warnings): void { $warnings[] = $w; },
    "wpforms 'recon-signup-form'"
);
wprism_check(str_contains($dangling, '"page":null'), 'C9: an unmapped id becomes null in canonical state, never a raw id');
wprism_check_same(1, count($warnings), 'C9: and exactly one warning is emitted for it');
wprism_check(
    str_contains($warnings[0] ?? '', 'unmapped post id 4 dropped (dangling reference)'),
    'C9: naming the path, the keyspace and the id'
);
wprism_check_same(
    $dangling,
    BodyRefGrammar::apply($dangling, $rule, $tokenToId($tokensFor()), 'fixture'),
    'C9: and apply leaves the null exactly where capture put it — there was never a valid id to restore'
);

// C10 — canonical state that still holds a raw id at a declared path is
// REFUSED by apply rather than cast, because casting it would bind the body to
// whatever entity happens to hold that id on the target.
wprism_check_throws(
    static fn(): string => BodyRefGrammar::apply($formB, $rule, $tokenToId($tokensFor()), "wpforms 'x'"),
    RuntimeException::class,
    'C10: apply refuses a repository body whose declared path still carries a source-local id',
    'where a {{...}} reference token was declared'
);

// ===========================================================================
// D. THE SUFFICIENCY PROOF — the rejected candidate through the REAL
//    PostCapture product seam, on the real captured bytes
// ===========================================================================

$seedMap(4);
// PostCapture reaches postmeta and the term tables on its way to the body;
// declaring them empty is what makes this the REAL seam rather than a
// re-implementation of the one branch under test.
$wpdb->seedTable('wp_postmeta', [])->seedTable('wp_term_relationships', [])
    ->seedTable('wp_term_taxonomy', [])->seedTable('wp_terms', [])->seedTable('wp_users', []);
$captureTokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
$metaCapture = new EntityMetaCapture(
    $policy,
    $captureTokens,
    static function (): void {},
    static function (): void {},
    static function (): void {}
);
$postCapture = new PostCapture($policy, $captureTokens, $metaCapture, new MediaCapture());
$formPost = static function (string $body, string $slug): object {
    return (object) [
        'ID' => 6,
        'post_type' => 'wpforms',
        'post_password' => '',
        'post_parent' => 0,
        'post_author' => 0,
        'post_name' => $slug,
        'post_title' => 'Recon Signup Form',
        'post_status' => 'publish',
        'post_date' => '2026-08-25 15:50:00',
        'post_date_gmt' => '2026-08-25 15:50:00',
        'post_modified' => '2026-08-25 15:50:00',
        'post_modified_gmt' => '2026-08-25 15:50:00',
        'menu_order' => 0,
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_excerpt' => '',
        'post_mime_type' => '',
        'post_content' => $body,
    ];
};

$entity = $postCapture->capture($formPost($formB, 'recon-signup-form'), '019200cc-0000-7000-8000-0000000000b6', []);
[, $productBody] = Canon::parse_post_file($entity['entity']['content']);
wprism_check_same(
    $capturedB,
    $productBody,
    'D1: the REAL PostCapture seam dispatches body=json to this codec — same bytes as the codec called directly'
);
wprism_check(
    !str_contains($productBody, '"page":"4"') && str_contains($productBody, '{{post:' . $pageUuid . '}}'),
    'D1: no environment-local page id reaches canonical state through the product path'
);
$protectedPost = $formPost($formB, 'protected-recon-form');
$protectedPost->post_password = 'source-password-never-canonical';
$protectedUuid = '019200cc-0000-7000-8000-0000000000c7';
$protected = $postCapture->capture($protectedPost, $protectedUuid, []);
[$protectedFront] = Canon::parse_post_file($protected['entity']['content']);
wprism_check_same(
    'post_password:' . $protectedUuid,
    $protectedFront['password_binding'] ?? null,
    'D1: a protected post captures a stable environment binding instead of refusing'
);
wprism_check(
    !str_contains($protected['entity']['content'], 'source-password-never-canonical'),
    'D1: the actual post password never enters canonical bytes'
);

// D2 — the three id-shaped values the manifest deliberately does NOT declare
// come through byte-identical. This is the "PRESERVE the variant, rewrite only
// declared paths" rule, measured on the values the recon classified as
// form-internal.
wprism_check(
    str_contains($productBody, '"field_id":4'),
    'D2: `$.field_id` — an allocator, not a reference — is preserved as the integer it was'
);
wprism_check(
    str_contains($productBody, '"id":"1"') && str_contains($productBody, '"id":"3"'),
    'D2: `$.fields.<n>.id` — form-local field ids — are preserved as the strings they were'
);
wprism_check(
    str_contains($productBody, '"replyto":"{field_id=\\"2\\"}"'),
    'D2: a field id inside smart-tag prose is untouched, escaping included'
);

// D3 — THE LEDGER'S WRONG SENTENCE, measured three ways. `$.id` is undeclared
// here, so all three shapes survive as themselves: this is the primitive's
// answer to an optional, type-variant key.
foreach ([
    ['form-a', 'ABSENT on the template path', static fn(array $d): bool => !array_key_exists('id', $d)],
    ['form-pathb', 'INT on the builder=false path', static fn(array $d): bool => ($d['id'] ?? null) === 12],
    ['form-pathc', 'STRING on the real builder save', static fn(array $d): bool => ($d['id'] ?? null) === '14'],
] as [$name, $shape, $assert]) {
    $raw = $capture($name);
    $round = BodyRefGrammar::apply(
        BodyRefGrammar::capture($raw, $rule, $idToToken($tokensFor()), static function (): void {}, 'fixture'),
        $rule,
        $tokenToId($tokensFor()),
        'fixture'
    );
    wprism_check_same($raw, $round, "D3: $name round-trips byte for byte — `\$.id` is $shape and is preserved as found");
    wprism_check($assert(json_decode($round, true)), "D3: and the shape survives as itself ($shape)");
}

// D4 — capture is a fixed point.
$seedMap(4);
wprism_check_same(
    $capturedB,
    BodyRefGrammar::capture(
        BodyRefGrammar::apply($capturedB, $rule, $tokenToId($tokensFor()), 'fixture'),
        $rule,
        $idToToken($tokensFor()),
        static function (): void {},
        'fixture'
    ),
    'D4: apply-then-capture on the source environment is a fixed point'
);

// D5 — the environment-bound value this primitive does NOT claim, warned about
// rather than implied away. Driven on the REAL form-b bytes with the recon
// pair's OWN home URL, because that is the measurement: form-b carries
// `"redirect":"http:\/\/localhost:9620\/recon-thank-you\/"`, and
// `settings.confirmations.<n>.redirect` is not a reference path this mode
// rewrites. It is also the case that catches a plain str_contains(): the URL is
// on disk in wp_json_encode()'s ESCAPED form, so the verbatim arm's scan would
// see nothing at all.
wprism_check(
    str_contains($formB, 'http:\/\/localhost:9620\/recon-thank-you\/')
        && !str_contains($formB, 'http://localhost:9620'),
    'D5: measured — the real capture carries the source site\'s absolute home URL, and ONLY in JSON-escaped form'
);
$homeTokens = new Tokens('http://localhost:9620', 'http://localhost:9620/wp-content/uploads');
$homeCapture = new PostCapture(
    $policy,
    $homeTokens,
    new EntityMetaCapture($policy, $homeTokens, static function (): void {}, static function (): void {}, static function (): void {}),
    new MediaCapture()
);
$homeCapture->capture($formPost($formB, 'recon-home-url'), '019200cc-0000-7000-8000-0000000000b7', []);
wprism_check(
    str_contains(implode(' | ', $homeTokens->warnings), 'outside any declared reference path — it will NOT be re-bound on apply'),
    'D5: and capture says so — WPForms bakes get_home_url() into settings.confirmations.<n>.redirect and this mode does not rebind it'
);

// D6 — a secret in authored json configuration REFUSES, matching the
// `serialized` arm rather than the prose-body `blocks` arm.
wprism_check_throws(
    static fn(): array => $postCapture->capture(
        $formPost('{"settings":{"key":"sk_live_1234567890ABCDEFGHIJ"}}', 'recon-secret'),
        '019200cc-0000-7000-8000-0000000000b8',
        []
    ),
    RuntimeException::class,
    'D6: a secret-shaped leaf in json authored configuration refuses capture rather than warning',
    'refusing to capture json authored configuration'
);
wprism_check_throws(
    static fn(): array => $postCapture->capture(
        $formPost('{"settings":{"sk_live_PRODUCTKEY1234567890":"enabled"}}', 'recon-secret-key'),
        '019200cc-0000-7000-8000-0000000000d1',
        []
    ),
    RuntimeException::class,
    'D6: the PostCapture JSON product path refuses a hard secret in an associative key',
    'refusing to capture json authored configuration'
);
wprism_check_throws(
    static fn(): array => $postCapture->capture(
        $formPost(
            '{"settings":{"password":{"primary":"GeneratedValue-2026-Blocked"}}}',
            'recon-secret-container'
        ),
        '019200cc-0000-7000-8000-0000000000d5',
        []
    ),
    RuntimeException::class,
    'D6: the PostCapture JSON product path retains a credential container role at its scalar leaf',
    'refusing to capture json authored configuration'
);
wprism_check_throws(
    static fn(): array => $postCapture->capture(
        $formPost('{"settings":{"alice@example.test":"enabled"}}', 'recon-pii-key'),
        '019200cc-0000-7000-8000-0000000000d2',
        []
    ),
    RuntimeException::class,
    'D6: the PostCapture JSON product path refuses PII in an associative key',
    'refusing to capture json authored configuration'
);

$serializedPolicy = $load(['serialized-key-clearance' => [
    'name' => 'serialized-key-clearance',
    'spec_version' => WPRISM_SPEC_VERSION,
    'post_types' => ['serialized_config' => ['class' => 'authored', 'body' => 'serialized']],
]]);
$serializedTokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
$serializedCapture = new PostCapture(
    $serializedPolicy,
    $serializedTokens,
    new EntityMetaCapture(
        $serializedPolicy,
        $serializedTokens,
        static function (): void {},
        static function (): void {},
        static function (): void {}
    ),
    new MediaCapture()
);
$serializedPost = static function (string $body, string $slug) use ($formPost): object {
    $post = $formPost($body, $slug);
    $post->post_type = 'serialized_config';
    return $post;
};
wprism_check_throws(
    static fn(): array => $serializedCapture->capture(
        $serializedPost(serialize(['sk_live_SERIALIZEDKEY1234567890' => 'enabled']), 'serialized-secret-key'),
        '019200cc-0000-7000-8000-0000000000d3',
        []
    ),
    RuntimeException::class,
    'D6: the PostCapture serialized product path refuses a hard secret in an associative key',
    'refusing to capture serialized authored configuration'
);
wprism_check_throws(
    static fn(): array => $serializedCapture->capture(
        $serializedPost(
            serialize(['smtp_pass' => ['primary' => 'GeneratedValue-2026-Blocked']]),
            'serialized-secret-container'
        ),
        '019200cc-0000-7000-8000-0000000000d6',
        []
    ),
    RuntimeException::class,
    'D6: the PostCapture serialized product path retains a credential container role at its scalar leaf',
    'refusing to capture serialized authored configuration'
);
wprism_check_throws(
    static fn(): array => $serializedCapture->capture(
        $serializedPost(serialize(['alice@example.test' => 'enabled']), 'serialized-pii-key'),
        '019200cc-0000-7000-8000-0000000000d4',
        []
    ),
    RuntimeException::class,
    'D6: the PostCapture serialized product path refuses PII in an associative key',
    'refusing to capture serialized authored configuration'
);

// D7 — the coordinates that stay OPEN. This fixture claims neither the
// taxonomy nor a deletion selector.
wprism_check(
    !array_key_exists('taxonomies', $wpforms) && !array_key_exists('deletions', $wpforms),
    'D7: the fixture adapter claims no taxonomy and no deletion — WPForms\' other coordinates stay open'
);

// ===========================================================================
// E. THE LINT GAP THE RECON MEASURED
// ===========================================================================

$positions = BodyRefGrammar::reference_positions(json_decode($formB, true), $rule);
wprism_check_same(1, count($positions), 'E1: exactly one declared position in form-b is a rewrite candidate');
wprism_check_same('4', $positions[0]['value'], 'E1: and it is the page id "4" — not the sentinel, not the absent key');
wprism_check_same(
    '.settings.confirmations.1.page',
    $positions[0]['locator'],
    'E1: reported at a locator that names the exact JSON position, which is what a lint finding prints'
);

wprism_check_same(
    0,
    count(BodyRefGrammar::reference_positions(json_decode($capturedB, true), $rule)) - 1,
    'E2: the captured body still has the position — a token is a candidate the linter then judges as rewritten'
);
wprism_check(
    str_starts_with(
        (string) BodyRefGrammar::reference_positions(json_decode($capturedB, true), $rule)[0]['value'],
        '{{post:'
    ),
    'E2: and its value is the token, which is how the linter tells "rewritten" from "never ran"'
);

// E3 — the false findings this scan deliberately does NOT produce. Every one of
// these was measured on the recon site as a small number that collides with a
// real post id, and none is a reference.
$formA = json_decode($capture('form-a'), true);
$aPositions = BodyRefGrammar::reference_positions($formA, $rule);
wprism_check_same(
    0,
    count($aPositions),
    'E3: form-a has no confirmations.page at all, so the declared scan reports nothing — no field_id, no fields.<n>.id, no smart tags'
);

wprism_check_summary('regress_body_ref_grammar');
