<?php
/**
 * The WPForms Lite site adapter, end to end — the ADAPTER as subject, not the
 * primitives it uses.
 *
 * WHAT THIS SUITE IS, AND WHY IT IS NOT A FIFTH GRAMMAR SUITE.
 * `regress_body_ref_grammar.php` and `regress_attr_id_codec_grammar.php` each
 * prove ONE primitive sufficient, and each builds its own synthetic manifest in
 * PHP to do it. Neither of them ships a file anybody could install. This suite's
 * subject is the installed artifact: `sandbox/fixtures/wpforms-lite/adapters/
 * wpforms-lite.json`, the tree's first `spec_version: 3` adapter, authored end
 * to end through the decentralized path a third party takes
 * (docs/guides/adapter-authoring.md § "The authoring loop") and read from disk
 * BYTE FOR BYTE here. Nothing below constructs a manifest: every assertion is
 * about what that one file makes the shipped engine do.
 *
 * A HISTORICAL SITE FIXTURE, NOT THE SHIPPED CAPSULE'S PRODUCT CLAIM. The
 * newer experimental capsule has a different version range and declarations.
 * This fixture remains selected through an explicit source=site override;
 * its certificate cannot certify or silently replace the shipped namesake.
 * `tools/engine-gaps.json` still records the candidate as REJECTED; two of its
 * four coordinates closed (WP-6.1's `attr_id_codecs`, WP-6.5's `body_refs` + the
 * `json` body mode) and the other two are stated as unclaimed in group E.
 *
 * THE FIVE CAPTURES ARE MEASURED, NOT WRITTEN. Every byte in
 * `sandbox/tests/fixtures/wpforms-body/` came off a live WPForms Lite 2.0.0.5
 * pair on 2026-08-25, authored through the plugin's OWN write paths
 * (`wpforms()->obj('form')->add()/update()`, includes/class-form.php:535/:671)
 * and the block embed wizard's own template
 * (src/Admin/FormEmbedWizard.php:393), never by `wp post create --post_content=`.
 * That discipline is the reason three of this adapter's declarations exist at
 * all: a hand-written fixture produces no `settings.confirmations.<n>.page`, no
 * `previous_page` sentinel and no `wpforms_form_locations` row.
 *
 * ONE FIXTURE BYTE NEEDS ITS OWN SENTENCE, because it looks like a defect and
 * is one — just not the plugin's. `contact-page.post_content.html`'s second
 * block carries `"copyPasteJsonValue":"{u0022fieldSizeu0022:u0022mediumu0022}"`,
 * with the backslashes eaten. That is the measured result of the recon's own
 * writer omitting `wp_slash()` before `wp_update_post()`, which runs
 * `wp_unslash()` over its input; the same recon's slashed pass stored the
 * correct `{"fieldSize":"medium"}`. It is kept verbatim rather than repaired
 * because it is exactly the control this suite wants beside the two rewritten
 * attributes: a NON-id attribute whose bytes must survive a capture/apply round
 * trip untouched, whatever they happen to be.
 *
 * FAILING-BEFORE. Group A drops one engine feature at a time from the real
 * file's own `engine_features` list and shows the loader refusing BY KEY, and
 * group A7 drops the whole document to `spec_version: 2` and shows it refusing
 * BY SECTION. Without those declarations this manifest does not degrade — it
 * does not load — which is what makes the four declared features load-bearing
 * rather than decorative.
 *
 * GROUP F IS THE HALF THIS SUITE COULD NOT MEASURE WHEN IT WAS WRITTEN. E4's
 * two original assertions recorded the exercise's headline find: every
 * feature-claimed key was in no arm of `AdapterCertification`'s three-arm
 * partition, `siteSurfaceSections()` refuses what it cannot classify, both
 * signing profiles call it, and so this adapter loaded on every site and could
 * not be certified by anybody — which closed deploy and apply behind it. WP-6.6
 * (spec/repo-format.md § v3.21) moved the arm into each feature's own
 * `IMPLEMENTED_FEATURES` row, so E4 now asserts the four reviewed arms and group
 * F drives `wprism adapter certify` in a child process through BOTH profiles —
 * derived and `--ratification-file` — over these exact committed bytes, pinning
 * the certificate's `surfaces` list whole. The failing-before is not
 * hypothetical: every invocation in group F exited non-zero on the prior engine,
 * naming the first key it could not place.
 *
 * GROUP G IS THE OTHER DEFERRED HALF, and it is the one an offline suite can
 * only ever CARRY rather than produce: what the chain did against a real pair.
 * `sandbox/fixtures/wpforms-lite/wpforms-lite.outcomes.json` is this adapter's
 * first recorded outcome — 2.0.0.5 green, certify -> capture -> deploy -> apply
 * -> byte-identical recapture — and it exists at all because § v3.21 unblocked
 * the step the chain used to stop at. Group G reads it through the shipped
 * `AdapterBoundary::readOutcomeTable()` and drives the real `wprism adapter
 * boundary` over it, so the record is an input the product consumes rather than
 * a claim in a commit message. Three of the four recorded releases remain
 * unprobed and the command still exits `probe-required`; G1 and G4 pin that,
 * because the honest shape of this evidence is one measured release, not four.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Grammar/BodyRefGrammar.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Grammar/Shortcodes.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Adapter/AdapterCertification.php';
require_once $root . '/agent/src/Adapter/AdapterContractGrammar.php';

use WPrism\AdapterCertification;
use WPrism\AdapterContractGrammar;
use WPrism\AdapterLibrary;
use WPrism\Blocks;
use WPrism\BodyRefGrammar;
use WPrism\Canon;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

// ---------------------------------------------------------------------------
// The artifact under test, and the captures it was authored from.
// ---------------------------------------------------------------------------

$adapterFile = $root . '/sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json';
$adapterBytes = (string) file_get_contents($adapterFile);
$adapter = Canon::decode($adapterBytes);

$fixtures = $root . '/sandbox/tests/fixtures/wpforms-body';
/** One measured post_content, with only the file's trailing newline removed. */
$capture = static fn(string $file): string => rtrim(
    (string) file_get_contents($fixtures . '/' . $file),
    "\n"
);

$confirmationPath = '$.settings.confirmations.*.page';

/**
 * Install a manifest as a SITE adapter and load it the way a target does.
 *
 * A scratch site repository rather than a scratch manifest LIBRARY, and the
 * difference is the whole point of this suite: `adapters/<name>.json` is the
 * source a third-party adapter actually arrives through, so this path runs
 * `AdapterSources::assert_out_of_tree_contract()` and
 * `IdentityNamespaces::assert_out_of_tree_identity()` — the two rules a
 * library-directory fixture skips, and the two an author meets first.
 *
 * @param array<string,mixed> $manifest
 */
$sourceLibrary = AdapterLibrary::fromSourceTree($root);
$loadSite = static function (array $manifest) use ($sourceLibrary): Policy {
    $dir = sys_get_temp_dir() . '/wprism_wpforms_site_' . bin2hex(random_bytes(8));
    if (!mkdir($dir . '/adapters', 0700, true) && !is_dir($dir . '/adapters')) {
        throw new RuntimeException("could not create scratch site repository $dir");
    }
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/adapters/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($dir . '/site.wprism.json');
        @rmdir($dir . '/adapters');
        @rmdir($dir);
    });
    Canon::write_file($dir . '/adapters/wpforms-lite.json', Canon::encode($manifest));
    Canon::write_file($dir . '/site.wprism.json', Canon::encode([
        'manifests' => [['name' => 'wpforms-lite', 'source' => 'site']],
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));
    // The shipped package inventory stays explicit: a site adapter loads
    // beside it, never through process-global directory selection.
    return Policy::load($dir, ['wpforms-lite'], adapterLibrary: $sourceLibrary);
};

/** @param array<string,mixed> $overlay */
$variant = static function (array $overlay) use ($adapter): array {
    $out = array_replace($adapter, $overlay);
    foreach ($overlay as $key => $value) {
        if ($value === null) {
            unset($out[$key]);
        }
    }

    return $out;
};

/** The declared feature list minus one name — group A's failing-before lever. */
$withoutFeature = static function (string $feature) use ($adapter): array {
    return array_values(array_filter(
        $adapter['engine_features'],
        static fn(string $f): bool => $f !== $feature
    ));
};

// ===========================================================================
// A. INSTALLATION — the committed file, loaded as a site adapter
// ===========================================================================

wprism_check_same(
    $adapterBytes,
    Canon::encode($adapter),
    'A1: the committed adapter is already CANONICAL on disk — `wprism adapter certify` rewrites a non-canonical '
        . 'file before signing it, so a file it would rewrite is one the author has not finished'
);
wprism_check_same(
    ['wpforms-lite', 3, 'wpforms-lite/wpforms.php'],
    [$adapter['name'], $adapter['spec_version'], $adapter['plugin']],
    'A1: and it declares the identity the fixture package is named for, at spec_version 3'
);
wprism_check_same(
    ['attr-id-codecs/v1', 'spec-window/v1', 'structured-body-refs/v1', 'structured-evidence/v1'],
    $adapter['engine_features'],
    'A1: with exactly the four engine features every section below rides on'
);

$policy = $loadSite($adapter);
wprism_check_same(
    ['wpforms-lite'],
    array_column($policy->manifests, 'name'),
    'A2: the real loader accepts the explicit SITE override without borrowing the shipped namesake or its disposition'
);
wprism_check_same(
    'site',
    $policy->adapter_sources()->source('wpforms-lite'),
    'A2: and resolves it as source=site — the out-of-tree contract and the vendor-namespace rule both ran'
);

// A3 — the projections every consumer below reads. Asserted here, once, so a
// later group's failure is about the CODEC and never about the declaration
// having quietly stopped reaching the engine.
wprism_check_same('json', $policy->body_mode('wpforms'), 'A3: post_types.wpforms.body reaches the policy as the json mode');
wprism_check_same(
    [
        'json_refs' => [['cast' => 'string', 'kind' => 'post', 'path' => $confirmationPath]],
        'sentinels' => [$confirmationPath => ['previous_page']],
    ],
    wprism_check_ksort_recursive($policy->body_ref_rule('wpforms')),
    'A3: and the one declared body reference path, with the one sentinel over it'
);
wprism_check_same(
    ['wpforms/form-selector' => [['kind' => 'post', 'path' => 'formId', 'type' => 'int']]],
    wprism_check_ksort_recursive($policy->block_attr_rules()),
    'A3: the block attribute rule is the one the recon measured unadapted'
);
wprism_check_same(
    ['wpforms/form-selector' => ['formId' => ['id_type' => 'string']]],
    wprism_check_ksort_recursive($policy->attr_id_codec_rules()),
    'A3: refined by the string id codec, so a resolved id is written back quoted'
);
wprism_check_same(
    'derived',
    $policy->post_meta_rule('wpforms_form_locations')['class'] ?? null,
    'A3: wpforms_form_locations is derived — the plugin regenerates it, so capture must not carry its page id'
);
wprism_check_same(
    ['authored', null],
    [
        $policy->option_rule('wpforms_settings')['class'] ?? null,
        $policy->option_rule('wpforms_settings')['lint_ok'] ?? null,
    ],
    'A3: the ONE operator-authored option of the fourteen the recon censused is authored, and carries NO '
        . 'lint_ok exemption — the live capture REFUSAL it originally earned (`uncertified_adapter_lint_findings` '
        . 'on options.wpforms_settings[modern-markup] (bare_id), where the "1" is a boolean flag and not an id) '
        . 'is now closed at the engine (group F, FRICTION 7) rather than papered over on the declaration'
);
wprism_check_same(
    ['runtime', 'runtime', 'runtime', 'runtime', 'runtime', 'runtime', 'runtime', 'runtime'],
    array_map(
        static fn(string $name): ?string => $policy->option_rule($name)['class'] ?? null,
        [
            '_wpforms_transient_existing_tables',
            '_wpforms_transient_timeout_existing_tables',
            'wpforms_activated',
            'wpforms_constant_contact_version',
            'wpforms_forms_first_created',
            'wpforms_version',
            'wpforms_version_lite',
            'wpforms_versions_lite',
        ]
    ),
    'A3: and the eight bookkeeping names are runtime BY NAME — no option_namespaces claim, so nothing a future '
        . 'release adds is swallowed unclassified'
);

// A4/A5/A6 — THE FAILING-BEFORE PROOFS, one per feature-claimed section. Each
// removes ONE name from the real file's own list and shows the § v3.3 closed
// key set refusing the section that name admits. § v3.2's three verdicts in
// their third form: at v3 an undeclared feature makes its key a misspelling.
wprism_check_throws(
    static fn(): Policy => $loadSite($variant(['engine_features' => $withoutFeature('structured-body-refs/v1')])),
    RuntimeException::class,
    'A4: drop structured-body-refs/v1 and the manifest does not degrade to `verbatim` — it refuses BY KEY',
    "the top-level key 'body_refs', which this engine does not recognise"
);
wprism_check_throws(
    static fn(): Policy => $loadSite($variant(['engine_features' => $withoutFeature('attr-id-codecs/v1')])),
    RuntimeException::class,
    'A5: drop attr-id-codecs/v1 and the string-id codec is refused BY KEY, not silently ignored',
    "the top-level key 'attr_id_codecs', which this engine does not recognise"
);
wprism_check_throws(
    static fn(): Policy => $loadSite($variant(['engine_features' => $withoutFeature('structured-evidence/v1')])),
    RuntimeException::class,
    'A6: drop structured-evidence/v1 and the measured-evidence section is refused BY KEY — evidence a checker '
        . 'cannot read is what this section exists to replace',
    "the top-level key 'declaration_evidence', which this engine does not recognise"
);
wprism_check_throws(
    static fn(): Policy => $loadSite($variant(['engine_features' => null, 'spec_version' => 2])),
    RuntimeException::class,
    'A7: and at spec_version 2 the same document refuses BY SECTION, naming the version that has it — the first '
        . 'of § v3.2\'s three verdicts, which is what lets a v2 engine reject this file cleanly',
    'which this engine implements only at spec_version 3'
);

// ===========================================================================
// B. THE BODY — four measured captures through this adapter's own rule
// ===========================================================================

WpStore::reset()->seedOptions(['home' => 'https://source.example']);
$wpdb = FakeWpdb::install();

$pageUuid = '019200cc-0000-7000-8000-0000000000a4';
$formBUuid = '019200cc-0000-7000-8000-0000000000b6';
$formCUuid = '019200cc-0000-7000-8000-0000000000ce';

/**
 * Seed wprism_map with one row per entity this environment holds.
 *
 * @param array<string,int> $rows uuid => local id
 */
$seedMap = static function (array $rows) use ($wpdb): void {
    $seeded = [];
    $i = 0;
    foreach ($rows as $uuid => $localId) {
        $seeded[] = [
            'id' => ++$i,
            'uuid' => $uuid,
            'entity_type' => 'post',
            'id_kind' => 'post',
            'local_id' => $localId,
        ];
    }
    $wpdb->seedTable('wp_wprism_map', $seeded);
};
$tokensFor = static function () use ($policy): Tokens {
    $tokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
    $tokens->policy = $policy;
    return $tokens;
};
$rule = $policy->body_ref_rule('wpforms');
$captureBody = static function (string $body, array &$warnings) use ($rule): string {
    $tokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
    return BodyRefGrammar::capture(
        $body,
        $rule,
        static fn(int $id, string $kind): ?string => $tokens->id_to_token($id, $kind),
        static function (string $w) use (&$warnings): void { $warnings[] = $w; },
        "wpforms 'fixture'"
    );
};
$applyBody = static function (string $body) use ($rule): string {
    $tokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
    return BodyRefGrammar::apply(
        $body,
        $rule,
        static fn(string $token): int => $tokens->token_to_id($token),
        "wpforms 'fixture'"
    );
};

$seedMap([$pageUuid => 4, $formBUuid => 6, $formCUuid => 14]);

// B1 — BYTE IDENTITY WHERE NOTHING MOVES. Three of the four measured bodies
// carry no `settings.confirmations.<n>.page` at all, so this adapter's one
// declared path resolves nowhere in them. The mode still decodes and re-encodes
// the whole document, which is precisely the risk the round-trip precondition
// exists for: an adapter that declared `json` over a body it could not
// reproduce would show every adopted form as changed forever.
foreach (['form-a', 'form-pathb', 'form-pathc'] as $name) {
    $raw = $capture($name . '.post_content.raw.json');
    $warnings = [];
    wprism_check_same(
        $raw,
        $captureBody($raw, $warnings),
        "B1: $name has no declared reference in it, and capture reproduces its bytes exactly"
    );
    wprism_check_same([], $warnings, 'B1: and warns about nothing, because nothing was dropped');
}

// B2 — THE TYPE-VARIANT `$.id` THIS ADAPTER DELIBERATELY DOES NOT DECLARE.
// Three write paths, three shapes, and the ledger row's original one-sentence
// claim was true for exactly one of them. Preservation rather than
// normalisation is what makes leaving it undeclared safe.
$decodedA = json_decode($capture('form-a.post_content.raw.json'), true);
$decodedB = json_decode($capture('form-pathb.post_content.raw.json'), true);
$decodedC = json_decode($capture('form-pathc.post_content.raw.json'), true);
wprism_check_same(
    [false, true, true],
    [array_key_exists('id', $decodedA), array_key_exists('id', $decodedB), array_key_exists('id', $decodedC)],
    'B2: the template create path writes no top-level `id` key at all; the other two do'
);
wprism_check_same(
    ['int', 'string'],
    [get_debug_type($decodedB['id']), get_debug_type($decodedC['id'])],
    'B2: and they disagree about its TYPE — int on the builder=false path, string on the real builder save'
);

// B3 — THE CROSS-ENTITY REFERENCE, THE SENTINEL, AND THE TWO VALUES THIS
// ADAPTER CANNOT REBIND. One form carries all four facts.
$formB = $capture('form-b.post_content.raw.json');
$warnings = [];
$capturedB = $captureBody($formB, $warnings);
wprism_check_same([], $warnings, 'B3: the page reference resolves in this environment, so nothing is dropped');
wprism_check(
    str_contains($capturedB, '"page":"{{post:' . $pageUuid . '}}"'),
    'B3: the confirmation page id — a JSON STRING on the wire — tokenises and stays a STRING'
);
wprism_check(
    !str_contains($capturedB, '"page":"4"'),
    'B3: and no source-local page id survives into canonical state'
);
wprism_check(
    str_contains($capturedB, '"page":"previous_page"'),
    'B3: the declared sentinel on the SAME key passes through untouched — coercing it would repoint the '
        . 'confirmation at post 0 (includes/class-process.php:1553-1562 compares it identically)'
);
wprism_check(
    str_contains($capturedB, '"redirect":"http:\/\/localhost:9620\/recon-thank-you\/"'),
    'B3: and the sibling `redirect` — the source site\'s absolute home URL — crosses UNCHANGED, because this '
        . 'mode rewrites declared paths and nothing else. The adapter states that in notes rather than implying '
        . 'the body is portable'
);

// B4/B5 — APPLY, on this environment and on one where the page moved.
wprism_check_same(
    $formB,
    $applyBody($capturedB),
    'B4: apply on the SAME environment reproduces the post body byte for byte'
);
$seedMap([$pageUuid => 3456, $formBUuid => 6, $formCUuid => 14]);
$onTarget = $applyBody($capturedB);
wprism_check(
    str_contains($onTarget, '"page":"3456"'),
    'B5: on a target where the page is row 3456 the reference is rewritten — as a STRING of the new length'
);
wprism_check(
    !str_contains($onTarget, '"page":3456'),
    'B5: and never as the bare integer the generic json_refs default would have written'
);
wprism_check(
    str_contains($onTarget, '"page":"previous_page"'),
    'B5: the sentinel is still the sentinel on the target'
);

// B6 — RECAPTURE. The property the whole classification rests on
// (docs/guides/adapter-authoring.md § 6): capture -> apply -> recapture returns
// the same canonical bytes, so the repository does not churn per environment.
$warnings = [];
wprism_check_same(
    $capturedB,
    $captureBody($onTarget, $warnings),
    'B6: recapturing the applied body on the TARGET yields the same canonical bytes as the source capture'
);
wprism_check_same([], $warnings, 'B6: with nothing dropped on the target either');

// ===========================================================================
// C. THE BLOCK — the measured embedding page, through the same adapter
// ===========================================================================

$seedMap([$pageUuid => 4, $formBUuid => 6, $formCUuid => 14]);
$page = $capture('contact-page.post_content.html');
$capturedPage = Blocks::capture_rewrite($page, $policy, $tokensFor(), false, "page 'recon-contact-page'");

wprism_check(
    str_contains($capturedPage, '"formId":"{{post:' . $formBUuid . '}}"')
        && str_contains($capturedPage, '"formId":"{{post:' . $formCUuid . '}}"'),
    'C1: both embedded form ids tokenise, and both stay STRINGS — the recon measured `wp wprism lint` reporting '
        . 'exactly these two as unregistered_block_attr before this declaration existed'
);
wprism_check(
    str_contains($capturedPage, '"copyPasteJsonValue":"{u0022fieldSizeu0022:u0022mediumu0022}"'),
    'C1: and the one non-id attribute beside them survives byte for byte, backslash damage included — a codec '
        . 'that tidied it would be rewriting content it was never declared over'
);
wprism_check(
    str_contains($capturedPage, '<p>Get in touch.</p>'),
    'C1: the page\'s ordinary block content is untouched as well'
);

wprism_check_same(
    $page,
    Blocks::apply_rewrite($capturedPage, $policy, $tokensFor()),
    'C2: apply on the SAME environment reproduces the page byte for byte — the round trip that wrote '
        . '{"formId":6} before WP-6.1'
);

$seedMap([$pageUuid => 4, $formBUuid => 91, $formCUuid => 92]);
$pageOnTarget = Blocks::apply_rewrite($capturedPage, $policy, $tokensFor());
wprism_check(
    str_contains($pageOnTarget, '{"formId":"91"}'),
    'C3: on a target whose forms are rows 91 and 92 the first embed renders the TARGET\'s form id, quoted'
);
wprism_check(
    str_contains($pageOnTarget, '"formId":"92"') && !str_contains($pageOnTarget, '"formId":92'),
    'C3: and so does the second — neither is written as a bare integer'
);
wprism_check_same(
    $capturedPage,
    Blocks::capture_rewrite($pageOnTarget, $policy, $tokensFor(), false, "page 'recon-contact-page'"),
    'C4: recapturing the applied page on the target yields the same canonical bytes'
);

// ===========================================================================
// D. THE CROSS-ENTITY PROOF — one target, three entities, one ledger
//
// The offline twin of the live leg's assertion, and the only group where the
// two codecs are exercised TOGETHER against one id ledger. The target is
// deliberately built so every id differs from the source: the confirmation
// page is row 4 on the source and row 77 here, the two forms are 6/14 there
// and 91/92 here. A capture that leaked ANY source id would show up as a
// source-local number surviving into the applied bytes.
// ===========================================================================

$seedMap([$pageUuid => 77, $formBUuid => 91, $formCUuid => 92]);
$bodyOnTarget = $applyBody($capturedB);
$pageOnTarget = Blocks::apply_rewrite($capturedPage, $policy, $tokensFor());

wprism_check(
    str_contains($bodyOnTarget, '"page":"77"'),
    'D1: after apply the form\'s confirmation points at the TARGET\'s own page id, not the source\'s 4'
);
wprism_check(
    str_contains($pageOnTarget, '"formId":"91"') && str_contains($pageOnTarget, '"formId":"92"'),
    'D2: and the embedding page renders the TARGET\'s own form ids, not the source\'s 6 and 14'
);
foreach (['"page":"4"' => 'the source page id', '"formId":"6"' => 'the source form id'] as $needle => $what) {
    wprism_check(
        !str_contains($bodyOnTarget . $pageOnTarget, $needle),
        "D3: no trace of $what survives anywhere in the applied pair"
    );
}
wprism_check(
    !str_contains($capturedB . $capturedPage, '"page":"4"')
    && !str_contains($capturedB . $capturedPage, '"formId":"6"'),
    'D3: and canonical state carries no source-local id either — which is what makes the repository portable '
        . 'rather than merely re-applicable to its own source'
);

// ===========================================================================
// E. WHAT THIS ADAPTER DOES NOT CLAIM — the honest half, asserted
// ===========================================================================

// E1 — the OPEN ledger coordinate. `derived` represents the capture side
// completely; the apply side is a bounded postcondition this engine has no
// primitive for, so the adapter declares no regenerator and no provider.
wprism_check(
    !array_key_exists('regenerators', $adapter)
    && !array_key_exists('providers', $adapter)
    && !array_key_exists('actions', $adapter)
    && !array_key_exists('interpreter', $adapter),
    'E1: the adapter declares NO executable lane at all — an out-of-tree manifest may not carry one '
        . '(AdapterSources::assert_out_of_tree_contract()), and the form-locations rebuild it would want is the '
        . 'open `verified_provider_postcondition` coordinate rather than something a regenerator could prove'
);

// E2 — deletion: one selector advertised, one deliberately absent, and the
// feasibility measurement behind each is in declaration_evidence.
$termDeletion = $policy->deletion_capability('term:wpforms_form_tag');
wprism_check_same(
    // Sorted, because the resolver sorts: the cascade set is a SET, and the
    // manifest's own spelling order is not a declaration about anything.
    ['term_relationships', 'term_taxonomy', 'termmeta'],
    $termDeletion['cascades'] ?? null,
    'E2: term:wpforms_form_tag declares the complete term cascade set the engine requires'
);
wprism_check_same(
    [['column' => 'term_taxonomy_id', 'table' => 'term_relationships']],
    array_map(
        static fn(array $g): array => ['column' => $g['column'], 'table' => $g['table']],
        $termDeletion['guards'] ?? []
    ),
    'E2: guarded on the ONE reverse reference whose index leads — the deletion-feasibility report returned '
        . 'reason:null for it, which is the necessary condition for the guard to lock'
);
wprism_check_same(
    null,
    $policy->deletion_capability('post:wpforms'),
    'E2: and post:wpforms is NOT advertised — both of its reverse-reference guards (postmeta.meta_value for '
        . 'wpforms_form_locations, posts.post_content for the block embed) measured index:null, so neither can '
        . 'ever lock on core\'s schema. Same posture as manifests/ninja-forms.json for table:nf3_forms'
);

// E3 — the six tables, every one runtime, none of them an authored claim an
// empty table could not have supported.
$tableClasses = [];
foreach (array_keys($adapter['tables']) as $table) {
    $tableClasses[$table] = $policy->table_rule((string) $table)['class'] ?? null;
}
wprism_check_same(
    [
        'wpforms_analytics_forms' => 'runtime',
        'wpforms_analytics_snapshots' => 'runtime',
        'wpforms_logs' => 'runtime',
        'wpforms_payment_meta' => 'runtime',
        'wpforms_payments' => 'runtime',
        'wpforms_tasks_meta' => 'runtime',
    ],
    $tableClasses,
    'E3: all six wp_wpforms_* tables are runtime — payments and analytics are visitor-generated by their own '
        . 'nature, and every one held 0 rows on the measured install, so no identity or natural-key claim could '
        . 'have been measured and none is made'
);
wprism_check(
    !str_contains(Canon::encode($adapter['tables']), 'actionscheduler'),
    'E3: and the four live Action Scheduler tables WPForms Lite also creates are declared by NOTHING here — '
        . 'they belong to a bundled shared library, so a per-plugin adapter claiming them would be the first of '
        . 'N adapters fighting over one namespace'
);

// E4 — THE PRICE OF THE FEATURE CHANNEL, PAID OFF. This group's two assertions
// used to measure the wall: "`wprism adapter certify` refuses this manifest — every
// feature-claimed key is in no arm of the signer's top-level partition". That
// was the headline find of the exercise, and WP-6.6 (spec/repo-format.md
// § v3.21) closed it by construction — each `IMPLEMENTED_FEATURES` row now
// classifies every key it claims into a certificate arm, so the partition can
// never again be incomplete against the shipped grammar for a feature-admitted
// key. What is asserted here is the ARM each of this adapter's four keys gets;
// group F drives the real verb end to end.
$ratify = (new ReflectionClass(AdapterCertification::class))->getMethod('siteRatification');
$signerVerdict = null;
try {
    $ratify->invoke(null, 'wpforms-lite', $adapter, 'wpforms-lite fixture');
} catch (\Throwable $e) {
    $signerVerdict = $e->getMessage();
}
wprism_check_same(
    null,
    $signerVerdict,
    'E4: the signer classifies every key this adapter declares — the four feature-claimed sections included, '
        . 'through the roster rather than through a partition patch per key'
);
wprism_check_same(
    [
        'attr_id_codecs' => 'field',
        'body_refs' => 'field',
        'declaration_evidence' => 'non_surface',
        'engine_features' => 'non_surface',
    ],
    AdapterContractGrammar::admitted_feature_key_arms($adapter),
    'E4: with the four REVIEWED arms — the two id-bearing sections are surfaces a certificate covers '
        . '(`attr_id_codecs` refines `block_attrs`, `body_refs` is `block_attrs` one container deeper), and '
        . 'the claim channel and the evidence records cover no state at all'
);
// The refusal did not go away — it went where it belongs. A section this
// engine reads nothing from may not enter a certificate, so the arm is read
// from the DECLARING manifest's own features and a key present without them
// keeps the sentence verbatim. This is the control for the whole mechanism.
$strippedFeature = $variant(['engine_features' => $withoutFeature('structured-body-refs/v1')]);
$strippedVerdict = null;
try {
    $ratify->invoke(null, 'wpforms-lite', $strippedFeature, 'wpforms-lite fixture');
} catch (\Throwable $e) {
    $strippedVerdict = $e->getMessage();
}
wprism_check(
    is_string($strippedVerdict)
    && str_contains($strippedVerdict, "declares 'body_refs'")
    && str_contains($strippedVerdict, 'which this signer cannot classify as an entity or field surface')
    && str_contains($strippedVerdict, 'teach the signer this section'),
    'E4: and `body_refs` WITHOUT its feature declared still refuses with the same sentence — the arm is a '
        . 'property of the declaration, not of the key\'s spelling'
);
// The genuinely unknown key, which is what that sentence is for. Asked of the
// signer directly because the closed key set (§ v3.3) refuses an invented
// section one gate EARLIER at spec_version 3, so the product path never lets
// this manifest reach the signer — F6 measures that gate.
$invented = $adapter;
$invented['acme_invented_section'] = ['x' => 1];
$inventedVerdict = null;
try {
    $ratify->invoke(null, 'wpforms-lite', $invented, 'wpforms-lite fixture');
} catch (\Throwable $e) {
    $inventedVerdict = $e->getMessage();
}
wprism_check(
    is_string($inventedVerdict)
    && str_contains($inventedVerdict, "declares 'acme_invented_section'")
    && str_contains($inventedVerdict, 'which this signer cannot classify as an entity or field surface')
    && str_contains($inventedVerdict, 'teach the signer this section'),
    'E4: a section NO implemented feature claims meets the unclassifiable verdict verbatim — the roster '
        . 'classified the four that came through the channel, not everything'
);
wprism_check_detail('E4 control refusal: ' . (string) $inventedVerdict);

// E5 — the evidence section is not decoration: every record addresses a
// declaration this manifest still makes. `StructuredEvidence::assert_target()`
// enforces the head at load; this asserts the count did not quietly shrink to
// the one row that would satisfy the grammar.
$targets = array_keys($adapter['declaration_evidence']);
sort($targets, SORT_STRING);
wprism_check_same(
    [
        'attr_id_codecs.wpforms/form-selector',
        'body_refs.wpforms',
        'deletions.term:wpforms_form_tag',
        'engine_features',
        'options.wpforms_settings',
        'post_meta.wpforms_form_locations',
        'post_types.wpforms.body',
        'tables.wpforms_analytics_forms',
        'taxonomies.wpforms_form_tag',
        'version_range',
    ],
    $targets,
    'E5: ten declaration_evidence records, one per decision a reader would otherwise have to take on trust — '
        . 'including one addressed at `engine_features` itself, which is where the measured cost of the channel '
        . 'is recorded'
);
$rowCount = 0;
foreach ($adapter['declaration_evidence'] as $record) {
    $rowCount += count($record['evidence']);
}
wprism_check(
    $rowCount >= 20,
    "E5: carrying $rowCount {source, locator, observation} rows — the measured half of the authoring loop, "
        . 'surviving the ratification that used to delete it with `_draft`'
);

// E6 — THE RANGE IS BOUND TO THE RECORDED RELEASES, read through the shipped
// reader rather than by re-parsing the file here. `version_range` is the one
// declaration in this manifest that nothing else in the corpus can falsify:
// a grammar check proves it well-formed and says nothing about whether the
// evidence covers it. The release list beside the adapter is that evidence —
// four fetched-and-hashed releases, the document `wprism adapter boundary`
// accepts — so the two are compared, in the only two directions that can be
// wrong.
require_once $root . '/cli/src/Adapter/AdapterBoundary.php';
$releases = \WPrism\Orchestrator\AdapterBoundary::readReleaseList(
    $root . '/sandbox/fixtures/wpforms-lite/wpforms-lite.releases.json'
);
$versions = array_column($releases['releases'], 'version');
wprism_check_same(
    ['1.9.9.4', '2.0.0.3', '2.0.0.4', '2.0.0.5'],
    $versions,
    'E6: the committed release list is the four digest-pinned releases the boundary planner reads, in release order'
);
wprism_check(
    in_array($adapter['version_range']['min'], $versions, true),
    'E6: the range FLOOR is a release the list actually records — 2.0.0.4, the older of the two the engine-gap '
        . 'ledger probed. A floor no recorded release names would be a claim with nothing behind it'
);
$aboveCeiling = array_values(array_filter(
    $versions,
    static fn(string $v): bool => version_compare($v, (string) $adapter['version_range']['max'], '>=')
));
wprism_check_same(
    [],
    $aboveCeiling,
    'E6: and the CEILING is exclusive of every recorded release, so the window admits exactly the measured '
        . '2.0.0.x series and stops at the next minor rather than at the next major'
);

// ===========================================================================
// F. CERTIFICATION — the real verb, both profiles, over the committed bytes
//
// THE DEFERRED HALF OF THIS ADAPTER'S PROOF. Groups A-E measure what the engine
// does with the declarations; this one measures what a CERTIFICATE says about
// them, through `wprism adapter certify` in a child process rather than through
// `siteRatification()` in this one — because the sentence an operator meets and
// the plumbing that writes the file are both part of the claim. It exists at all
// because § v3.21 made it possible: before WP-6.6 every invocation below exited
// non-zero on the first feature-claimed key.
// ===========================================================================

/** @param list<string> $args @return array{exit:int,out:string,err:string} */
$certifyCli = static function (array $args) use ($root): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $root . '/cli/wprism', 'adapter'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return ['exit' => -1, 'out' => '', 'err' => 'cannot start wprism'];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
};

$rmtree = static function (string $path) use (&$rmtree): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach ((array) scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $rmtree($path . '/' . $entry);
        }
    }
    @rmdir($path);
};
$certRoot = sys_get_temp_dir() . '/wprism_wpforms_certify_' . bin2hex(random_bytes(6));
mkdir($certRoot, 0755, true);
register_shutdown_function(static fn() => $rmtree($certRoot));

/**
 * A scratch site repository holding ONE adapter, at the derived path.
 *
 * @param array<string,mixed> $manifest
 */
$certRepo = static function (string $label, array $manifest) use ($certRoot): string {
    $repo = $certRoot . '/' . $label;
    mkdir($repo . '/adapters', 0755, true);
    Canon::write_file($repo . '/adapters/wpforms-lite.json', Canon::encode($manifest));
    Canon::write_file($repo . '/site.wprism.json', Canon::encode([
        // The library now ships a different WPForms capsule. Certification
        // must validate this historical site fixture under explicit override
        // authority, never weaken AdapterSources' shadowing refusal.
        'manifests' => ['core', ['name' => 'wpforms-lite', 'source' => 'site']],
        // An empty JSON OBJECT: PHP erases {} vs [] on an associative round
        // trip and the engine refuses the list form.
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));

    return $repo;
};

$keypair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($keypair);
$keyId = 'site-' . substr(hash('sha256', $publicKey), 0, 12);
$keyPath = $certRoot . '/site.key';
file_put_contents($keyPath, base64_encode(sodium_crypto_sign_secretkey($keypair)) . "\n");
chmod($keyPath, 0600);
$certReason = 'The exercise site reviewed these exact wpforms-lite adapter bytes against 2.0.0.5.';

$unselectedRepo = $certRepo('unselected', $adapter);
$unselectedSite = ['manifests' => ['core'], 'policy' => new stdClass(), 'spec_version' => WPRISM_SPEC_VERSION];
Canon::write_file($unselectedRepo . '/site.wprism.json', Canon::encode($unselectedSite));
$unselectedRun = $certifyCli([
    'certify', $unselectedRepo, '--name=wpforms-lite', '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $certReason,
]);
wprism_check_same(2, $unselectedRun['exit'], 'F0: the old site fixture cannot silently shadow the new shipped capsule');
wprism_check(str_contains($unselectedRun['err'], "shadows the shipped adapter 'wpforms-lite'"),
    'F0: the real certification command names the missing explicit site override');
wprism_check(!file_exists($unselectedRepo . '/adapters/certifications/wpforms-lite.json')
    && !file_exists($unselectedRepo . '/adapters/authorities.json'), 'F0: refused shadowing publishes neither certificate nor authority');
wprism_check_same(Canon::encode($unselectedSite), file_get_contents($unselectedRepo . '/site.wprism.json'),
    'F0: shadowing refusal preserves the complete site policy');
wprism_check_same($adapterBytes, file_get_contents($unselectedRepo . '/adapters/wpforms-lite.json'),
    'F0: shadowing refusal preserves the historical manifest bytes');

// F1 — THE DERIVED PROFILE, end to end.
$derivedRepo = $certRepo('derived', $adapter);
$derivedRun = $certifyCli([
    'certify', $derivedRepo, '--name=wpforms-lite', '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $certReason,
]);
wprism_check_same(
    0,
    $derivedRun['exit'],
    'F1: `wprism adapter certify` signs the committed adapter — the verb that exited non-zero on '
        . '"declares \'attr_id_codecs\', which this signer cannot classify" before § v3.21'
        . ' (stderr: ' . trim($derivedRun['err']) . ')'
);
wprism_check(
    str_contains($derivedRun['out'], 'claim basis: DERIVED'),
    'F1: through the DERIVED profile, which is the floor an author gets without writing a document'
);
$derivedVerified = AdapterCertification::verifyFile(
    $sourceLibrary,
    $derivedRepo,
    'wpforms-lite',
    $adapter,
    AdapterCertification::certificatePath($derivedRepo, 'wpforms-lite')
);
wprism_check_same(
    'experimental',
    $derivedVerified['claim']['status'] ?? null,
    'F1: the LIVE verifier accepts the signature and bindings without treating grammar approval as certification'
);

// F2 — THE SURFACES LIST, EXACTLY. This is the byte-level consequence of the
// two `field` arms and the two `non_surface` ones: `claim_from_disposition()`
// builds `surfaces` from the entity and field lists alone, so `body_refs` and
// `attr_id_codecs` are covered WITH their declared members, while
// `engine_features` and `declaration_evidence` appear nowhere. Pinned whole
// rather than probed for membership, because a certificate that covered one
// section more or one less would still pass a `str_contains` check.
$derivedRatification = json_decode(
    (string) file_get_contents(AdapterCertification::certificatePath($derivedRepo, 'wpforms-lite')),
    true
);
$derivedEntry = $derivedRatification['statement']['ratification']['manifests']['wpforms-lite'] ?? [];
wprism_check_same(
    ['post_types', 'tables', 'taxonomies'],
    $derivedEntry['capabilities']['entity_sections'] ?? null,
    'F2: the certificate\'s entity arm is the three state-bearing sections, unchanged by the feature channel'
);
wprism_check_same(
    ['attr_id_codecs', 'block_attrs', 'body_refs', 'options', 'post_meta'],
    $derivedEntry['capabilities']['field_sections'] ?? null,
    'F2: and the FIELD arm now carries `attr_id_codecs` and `body_refs` beside `block_attrs` — the whole point '
        . 'of § v3.21, in the one place it is observable to a certificate holder'
);
wprism_check_same(
    [
        'attr_id_codecs',
        'attr_id_codecs.wpforms/form-selector',
        'block_attrs',
        'block_attrs.wpforms/form-selector',
        'body_refs',
        'body_refs.wpforms',
        'options',
        'options._wpforms_transient_existing_tables',
        'options._wpforms_transient_timeout_existing_tables',
        'options.wpforms_activated',
        'options.wpforms_constant_contact_version',
        'options.wpforms_forms_first_created',
        'options.wpforms_settings',
        'options.wpforms_version',
        'options.wpforms_version_lite',
        'options.wpforms_versions_lite',
        'post_meta',
        'post_meta.wpforms_form_locations',
        'post_types',
        'post_types.wpforms',
        'tables',
        'tables.wpforms_analytics_forms',
        'tables.wpforms_analytics_snapshots',
        'tables.wpforms_logs',
        'tables.wpforms_payment_meta',
        'tables.wpforms_payments',
        'tables.wpforms_tasks_meta',
        'taxonomies',
        'taxonomies.wpforms_form_tag',
    ],
    $derivedVerified['claim']['surfaces'] ?? null,
    'F2: the projected claim covers exactly these 29 surfaces — the two feature-claimed field sections WITH '
        . 'their declared members, and neither `engine_features` nor `declaration_evidence` anywhere in it'
);

// F3 — THE AUTHORED PROFILE (WP-5.3's `--ratification-file`, § v3.17). The same
// arms, written by a human, judged by the same shipped validator. It is driven
// here because both profiles call `siteSurfaceSections()` and the ADAPTER
// agent's find was that BOTH refused: proving one signs proves half.
$authoredEntry = [
    'capabilities' => [
        'deletion_semantics' => [
            'supported' => ['term:wpforms_form_tag'],
            'unsupported' => ['post:wpforms'],
        ],
        'entity_sections' => ['post_types', 'tables', 'taxonomies'],
        'field_sections' => ['attr_id_codecs', 'block_attrs', 'body_refs', 'options', 'post_meta'],
        'lifecycle_phases' => [],
        'operations' => ['apply', 'capture', 'compile', 'delete', 'deploy', 'plan', 'recapture'],
    ],
    'default_authored_keyspaces' => [],
    'evidence' => ['bundle_schema' => AdapterCertification::BUNDLE_FORMAT, 'tests' => []],
    'reason' => 'The exercise site reviewed this adapter against WPForms Lite 2.0.0.5 on a live pair: capture, '
        . 'apply and recapture over four measured form bodies and one embedding page, with the term cascade '
        . 'and its one reverse-reference guard measured through the deletion-feasibility report.',
    'status' => 'certified',
    'supported_versions' => [
        'plugin' => 'wpforms-lite/wpforms.php',
        'range' => $adapter['version_range'],
    ],
    'unsupported' => [
        [
            'operation' => 'delete',
            'reason' => 'Both reverse-reference guards for a wpforms post measured index:null — postmeta.'
                . 'meta_value for wpforms_form_locations and posts.post_content for the block embed — so no '
                . 'guard can ever lock on core\'s schema and a deletion cannot be proven safe.',
            'surface' => 'post:wpforms',
        ],
        [
            'operation' => 'apply',
            'reason' => 'wpforms_form_locations is declared derived: the plugin regenerates it from the pages '
                . 'that embed a form, so the repository carries no page id for it and apply converges it only '
                . 'as a consequence of the bodies it does converge.',
            'surface' => 'post_meta.wpforms_form_locations',
        ],
    ],
];
$authoredPath = $certRoot . '/authored-entry.json';
file_put_contents($authoredPath, (string) json_encode($authoredEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$authoredRepo = $certRepo('authored', $adapter);
$authoredRun = $certifyCli([
    'certify', $authoredRepo, '--name=wpforms-lite', '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $certReason, '--ratification-file=' . $authoredPath,
]);
wprism_check_same(
    0,
    $authoredRun['exit'],
    'F3: `--ratification-file` signs it too — the profile that shares siteSurfaceSections() with the '
        . 'derivation, so an author could not have named these sections into a certificate before § v3.21'
        . ' (stderr: ' . trim($authoredRun['err']) . ')'
);
wprism_check(
    str_contains($authoredRun['out'], 'claim basis: AUTHORED (--ratification-file)'),
    'F3: and reports the AUTHORED basis, which `wprism adapter recertify` reads to refuse re-deriving over it'
);
$authoredVerified = AdapterCertification::verifyFile(
    $sourceLibrary,
    $authoredRepo,
    'wpforms-lite',
    $adapter,
    AdapterCertification::certificatePath($authoredRepo, 'wpforms-lite')
);
// The SECTION coverage is identical and must be: an author may narrow with an
// unsupported[] row and its reason, never by leaving a surface out, so
// assertAuthoredSurfaceCoverage() holds both profiles to the same arms. The one
// legitimate difference is the reviewed DELETION selector, which is a claim the
// derivation structurally cannot make (`deletion_semantics.supported: []`,
// because a validator run reviews no deletion semantics) — so the authored
// certificate covers exactly one surface more, and that surface is the review.
wprism_check_same(
    array_values(array_diff(
        (array) ($authoredVerified['claim']['surfaces'] ?? []),
        (array) ($derivedVerified['claim']['surfaces'] ?? [])
    )),
    ['deletions.term:wpforms_form_tag'],
    'F3: over the derivation\'s surfaces plus exactly one — the reviewed term cascade, which a grammar verdict '
        . 'cannot claim. Every SECTION surface is identical, because both profiles read siteSurfaceSections()'
);
wprism_check_same(
    [],
    array_values(array_diff(
        (array) ($derivedVerified['claim']['surfaces'] ?? []),
        (array) ($authoredVerified['claim']['surfaces'] ?? [])
    )),
    'F3: and the authored claim drops nothing the derivation covered — a certificate naming fewer surfaces '
        . 'than the adapter declares is the silent narrowing the coverage rule refuses'
);

// F4 — THE ARM IS HELD AGAINST THE AUTHOR TOO. Naming a feature-claimed section
// under the wrong arm is the one rule this profile adds, and it now has
// something to say about a roster-classified key rather than refusing it
// outright.
$wrongArm = $authoredEntry;
$wrongArm['capabilities']['entity_sections'] = ['body_refs', 'post_types', 'tables', 'taxonomies'];
$wrongArm['capabilities']['field_sections'] = ['attr_id_codecs', 'block_attrs', 'options', 'post_meta'];
$wrongArmPath = $certRoot . '/wrong-arm.json';
file_put_contents($wrongArmPath, (string) json_encode($wrongArm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$wrongArmRun = $certifyCli([
    'certify', $certRepo('wrongarm', $adapter), '--name=wpforms-lite', '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $certReason, '--ratification-file=' . $wrongArmPath,
]);
wprism_check_same(2, $wrongArmRun['exit'], 'F4: naming `body_refs` as an ENTITY section refuses');
wprism_check(
    str_contains($wrongArmRun['err'], "names 'body_refs' as an entity section")
        && str_contains($wrongArmRun['err'], "this manifest's vocabulary classifies as a field section"),
    'F4: ...naming the arm the roster gives it, so the author is told which of the two lists it belongs in'
);

// F5 — THE ENVELOPE, NAMED. Handing `--ratification-file` the
// `wprism-manifest-dispositions/v1` document instead of the bare entry used to
// meet "has a malformed required field", which is true of the envelope and says
// nothing about the two documents being confused.
$envelopePath = $certRoot . '/envelope.json';
file_put_contents($envelopePath, (string) json_encode([
    'format' => AdapterCertification::RATIFICATION_FORMAT,
    'manifests' => ['wpforms-lite' => $authoredEntry],
    'profiles' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$envelopeRun = $certifyCli([
    'certify', $certRepo('envelope', $adapter), '--name=wpforms-lite', '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $certReason, '--ratification-file=' . $envelopePath,
]);
wprism_check_same(2, $envelopeRun['exit'], 'F5: an ENVELOPE-shaped ratification file refuses');
wprism_check(
    str_contains($envelopeRun['err'], 'ENVELOPE — --ratification-file takes the BARE entry')
        && str_contains($envelopeRun['err'], 'pass the value at manifests.wpforms-lite from that file instead'),
    'F5: ...saying which document it wanted and where in this one to find it, instead of reporting a missing '
        . 'field of a document the author never meant to write'
);

// F6 — THE CLOSED KEY SET IS STILL THE FIRST GATE. An invented section never
// reaches the signer at spec_version 3: § v3.3 refuses it at LOAD, one gate
// earlier, which is why E4's control had to ask the signer directly.
$inventedRun = $certifyCli([
    'certify', $certRepo('invented', $invented), '--name=wpforms-lite', '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $certReason,
]);
wprism_check_same(2, $inventedRun['exit'], 'F6: an adapter carrying an invented top-level section is not certified');
wprism_check(
    str_contains($inventedRun['err'], "the top-level key 'acme_invented_section', which this engine does not recognise"),
    'F6: ...and the refusal is the closed key set\'s, at LOAD — the roster widened what may be SIGNED, not '
        . 'what may be declared'
);

// ===========================================================================
// G. THE LIVE LEG'S RECORDED OUTCOME — the other deferred half
//
// Group F proves the certificate can be SIGNED over these bytes. That is a
// statement about the engine. This group's subject is the one thing an offline
// suite cannot produce and must therefore carry as a recorded input: what
// happened when the whole chain ran against a real WordPress pair.
//
// `sandbox/fixtures/wpforms-lite/wpforms-lite.outcomes.json` is that record —
// WPForms Lite 2.0.0.5 installed from the digest-verified ZIP on both sides,
// side one seeded through the plugin's own write paths, then certify -> capture
// -> deploy -> apply -> recapture. It is the FIRST row this adapter has ever
// had: before § v3.21 the chain stopped at `certify`, so there was no outcome
// to record and the file did not exist.
//
// The point of asserting it here is that a recorded outcome is an INPUT to a
// shipped reader, not a note in a commit message. It is read below through
// `AdapterBoundary::readOutcomeTable()` and driven through the real
// `wprism adapter boundary`, so a row that drifted out of the grammar, or out of
// agreement with the release list beside it, fails this suite rather than
// failing an operator months later.
// ===========================================================================

$outcomesPath = $root . '/sandbox/fixtures/wpforms-lite/wpforms-lite.outcomes.json';
$outcomeTable = \WPrism\Orchestrator\AdapterBoundary::readOutcomeTable($outcomesPath, 'wpforms-lite');

// G1 — ONE ROW, AND IT IS THE ANCHOR. The release list records four releases
// (E6); exactly one of them was actually probed. Asserting the count is not
// pedantry — an outcomes file that grew rows nobody ran is the precise failure
// the boundary command's own `evidence_limits` warn about, and the honest state
// of this evidence is "one release measured, three unprobed".
wprism_check_same(
    ['2.0.0.5'],
    array_keys($outcomeTable),
    'G1: exactly ONE release has a recorded outcome — the anchor. Three of the four releases in the '
        . 'committed list are unprobed, and silence from them is not evidence for them'
);
wprism_check_same(
    \WPrism\Orchestrator\AdapterBoundary::OUTCOME_GREEN,
    $outcomeTable['2.0.0.5']['outcome'],
    'G1: ...and that outcome is green — the full chain ran, it did not merely fail to refuse'
);
wprism_check_same(
    '2.0.0.5',
    $versions[count($versions) - 1],
    'G1: the probed release is the NEWEST in the committed release list, so the evidence anchors at the '
        . 'ceiling of what was recorded rather than somewhere in its interior'
);

// G2 — THE SIGNATURE IS THE EVIDENCE, AND IT IS QUOTED VERBATIM. Per
// sandbox/conformance/boundary/README.md § "The second reader", a signature is
// not prose a reviewer skims: `site.wprism.json` may carry these same rows under
// `adapter_version_evidence` and `LifecyclePlanner::code_mismatch()` quotes the
// signature into the operator-facing `version_range_graduated` verdict. So it
// must say what was OBSERVED. These three assertions pin the observations this
// package specifically unblocked — a signature rewritten into a summary that
// dropped them would still be well-formed and would still fail here.
$signature = $outcomeTable['2.0.0.5']['signature'];
wprism_check(
    str_contains($signature, 'attr_id_codecs')
        && str_contains($signature, 'body_refs')
        && str_contains($signature, 'field arm'),
    'G2: the signature records that the CERTIFICATE carried the two feature-claimed field-arm surfaces — '
        . 'the § v3.21 roster observed on a live signing, not just in group F\'s child process'
);
wprism_check(
    str_contains($signature, 'previous_page') && str_contains($signature, 'STRING'),
    'G2: ...that the cross-entity confirmation ref rewrote AS A STRING with the `previous_page` sentinel '
        . 'left alone — the two halves of this adapter\'s `body_refs` claim, measured on a target'
);
wprism_check(
    str_contains($signature, 'byte-identical'),
    'G2: ...and that the recapture matched, which is the only observation that makes the row `green` rather '
        . 'than "apply exited zero"'
);

// G3 — NO FILE HERE MAY STATE ITS OWN FRESHNESS. The boundary README's one
// prohibition: `last_verified`, `stale`, `releases_behind` and `freshness` are
// DERIVED by `wprism adapter proposals` across the whole directory. A recorded
// input allowed to declare itself current would let the adapter nobody probed
// claim to be the freshest thing in the tree.
$outcomesRaw = (array) json_decode((string) file_get_contents($outcomesPath), true);
$forbidden = array_values(array_intersect(
    ['last_verified', 'stale', 'releases_behind', 'freshness'],
    array_keys($outcomesRaw)
));
wprism_check_same(
    [],
    $forbidden,
    'G3: the recorded outcome states what was observed and nothing about its own freshness — that is '
        . '`wprism adapter proposals`\' to derive across the directory'
);

// G4 — THE RECORDED ROW MOVES THE REAL SEARCH, through the operator's command
// rather than through `search()` in this process. This is the whole reason the
// file is committed instead of being narrated in a commit message: with no
// outcomes the planner's next move is to probe the ANCHOR; with this row it
// already knows the anchor is green and moves to the floor arm. Both runs still
// exit 3 (`probe-required`), which is the honest verdict — one measured release
// does not certify a four-release window.
$boundaryArgs = [
    'boundary',
    '--releases=' . $root . '/sandbox/fixtures/wpforms-lite/wpforms-lite.releases.json',
    '--anchor=2.0.0.5',
    '--format=json',
];
$withoutOutcomes = $certifyCli($boundaryArgs);
$withOutcomes = $certifyCli(array_merge($boundaryArgs, ['--outcomes=' . $outcomesPath]));
wprism_check_same(
    [3, 3],
    [$withoutOutcomes['exit'], $withOutcomes['exit']],
    'G4: both runs exit 3 (probe-required) — recording one green release does not let a four-release '
        . 'window claim itself complete'
);
$before = (array) json_decode($withoutOutcomes['out'], true);
$after = (array) json_decode($withOutcomes['out'], true);
wprism_check_same(
    ['arm' => 'anchor', 'version' => '2.0.0.5'],
    $before['next_probe'] ?? null,
    'G4: with no outcomes recorded, the planner\'s next move is to probe the anchor — which is exactly '
        . 'where this adapter stood before the live leg ran'
);
wprism_check_same(
    ['arm' => 'floor', 'version' => '2.0.0.3'],
    $after['next_probe'] ?? null,
    'G4: ...and with the row recorded it moves ON, to the floor arm — the recorded input is consumed by '
        . 'the shipped planner, not just stored beside it'
);
wprism_check_same(
    [0, 1],
    [$before['probe_count'] ?? null, $after['probe_count'] ?? null],
    'G4: the probe count is the number of releases actually run, and it went 0 -> 1 on the strength of '
        . 'this one committed row'
);

// H. FRICTION 7 — THE BOOLEAN THAT ONCE BLOCKED CAPTURE, NOW WITHOUT lint_ok
//
// A3 already pins that `wpforms_settings` carries no `lint_ok` exemption on
// the CURRENT fixture. This group runs the actual capture-time scanner
// (`Lint::scan_tree()`) over the exact bytes that fired live —
// `a:3:{s:13:"modern-markup";s:1:"1";...}` — and asserts no `bare_id` finding
// comes back for it, on the environment that made the false hint possible:
// post #1 exists and is titled "Hello world!", WordPress's own seed row,
// exactly as the manifest's own declaration_evidence for options.
// wpforms_settings records (dogfood WP-6.4/WP-6.6, 2026-08-25).
//
// FAILING-BEFORE for this group lives outside the file, because the fix is
// the ENGINE, not this suite: `git stash` the Lint.php edit (keeping this
// suite and the fixture's lint_ok removal), rerun this file, and H1 fails —
// `scan_tree()` returns a `bare_id` finding at
// `options/core.json options.wpforms_settings[modern-markup]`, the same
// locator `LintTrustGate` quoted live before this fix. `git stash pop`
// restores the fix and H1 passes again. That sequence is the demonstration;
// it is not re-run here because a suite cannot stash its own dependency out
// from under itself mid-run.
// ===========================================================================

require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Review/Lint.php';

use WPrism\Lint;
use WPrism\LintEnvironment;
use WPrism\OptionState;

$lintTmp = sys_get_temp_dir() . '/wprism_wpforms_lint_' . bin2hex(random_bytes(8));
mkdir($lintTmp . '/options', 0700, true);
register_shutdown_function(static function () use ($lintTmp): void {
    @unlink($lintTmp . '/options/core.json');
    @rmdir($lintTmp . '/options');
    @rmdir($lintTmp);
});

// The measured bytes, decoded as PHP's own unserialize() would hand them to
// OptionsCapture: a:3:{s:13:"modern-markup";s:1:"1";s:20:"modern-markup-is-
// set";b:1;s:26:"modern-markup-hide-setting";b:1;} — one boolean-shaped '1'
// beside two real PHP booleans, which numeric_candidates() never treats as
// numeric (is_numeric(true) === false), so only 'modern-markup' is a
// candidate at all.
Canon::write_file($lintTmp . '/options/core.json', Canon::encode(OptionState::document([
    'wpforms_settings' => [
        'autoload' => 'yes',
        'state' => 'present',
        'value' => ['modern-markup' => '1', 'modern-markup-is-set' => true, 'modern-markup-hide-setting' => true],
    ],
])));

// Post #1 "Hello world!" is WordPress's own default seed row — the exact
// resolution target the manifest's declaration_evidence records the false
// pending hint against. Without it $env->resolve_id(1) answers null and H1
// would pass VACUOUSLY (no candidate ever resolves), which would prove
// nothing about the suppression this group exists to pin.
$wpdb->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello world!', 'post_status' => 'publish'],
]);

$lintFindings = Lint::scan_tree($lintTmp, $policy, LintEnvironment::live());
$bareIdOnModernMarkup = array_values(array_filter(
    $lintFindings,
    static fn(array $f): bool => $f['class'] === 'bare_id' && $f['locator'] === 'options.wpforms_settings[modern-markup]'
));
wprism_check_same(
    [],
    $bareIdOnModernMarkup,
    'H1: the boolean\'s own "1" produces no bare_id finding — Lint::scan_options_file()\'s bare_id loop now '
        . 'carries the same wholly-0/1 suppression Pending::ref_hint() has had since issue #3508, so capture no '
        . 'longer needs `lint_ok: true` on this declaration to get past LintTrustGate'
);

// H2 — the suppression is SCOPED to the {0,1} value class, not to "this
// option is boolean-shaped so stop looking at it". A sibling array element
// holding a real, non-boolean-shaped id (7, resolving to an existing page)
// in the SAME option still flags — the fix reads the CANDIDATE's own value,
// not the option or the key name.
$wpdb->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello world!', 'post_status' => 'publish'],
    ['ID' => 7, 'post_type' => 'page', 'post_title' => 'Recon Contact Page', 'post_status' => 'publish'],
]);
Canon::write_file($lintTmp . '/options/core.json', Canon::encode(OptionState::document([
    'wpforms_settings' => [
        'autoload' => 'yes',
        'state' => 'present',
        'value' => ['modern-markup' => '1', 'unrelated_authored_post_ref' => 7],
    ],
])));
$scopedFindings = Lint::scan_tree($lintTmp, $policy, LintEnvironment::live());
$locators = array_values(array_map(
    static fn(array $f): string => $f['locator'],
    array_filter($scopedFindings, static fn(array $f): bool => $f['class'] === 'bare_id')
));
wprism_check_same(
    ['options.wpforms_settings[unrelated_authored_post_ref]'],
    $locators,
    'H2: a sibling element holding a genuine non-{0,1} id (7) still flags as bare_id, and modern-markup\'s own '
        . '"1" still does not — the suppression is per-candidate value, not "any option shaped like this escapes '
        . 'lint entirely"'
);

// H3 — Pending::ref_hint()'s OWN documented cost (Pending.php:315-329, "small
// ids coincide … applied twice") is carried over rather than narrowed: a
// genuine reference that happens to be stored as the bare value 1 is STILL
// swallowed by this suppression, on either boolean-flag position. Asserting
// this is what keeps the fix from silently drifting into a key-name
// heuristic ("only suppress a key literally called modern-markup") that
// CAREFUL SCOPE never asked for and issue #3508 does not do either.
Canon::write_file($lintTmp . '/options/core.json', Canon::encode(OptionState::document([
    'wpforms_settings' => [
        'autoload' => 'yes',
        'state' => 'present',
        'value' => ['modern-markup' => '1', 'coincidentally_one' => 1],
    ],
])));
$costFindings = array_values(array_filter(
    Lint::scan_tree($lintTmp, $policy, LintEnvironment::live()),
    static fn(array $f): bool => $f['class'] === 'bare_id'
));
wprism_check_same(
    [],
    $costFindings,
    'H3: a second element that genuinely IS post #1 stored as bare 1 is also swallowed — the same cost '
        . 'Pending::ref_hint() already accepts for a whole-value \'1\' option, not a new, narrower one'
);

wprism_check_summary('regress_wpforms_lite_adapter');
