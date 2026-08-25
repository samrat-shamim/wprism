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
 * A FIXTURE, NOT A PRODUCT CLAIM. `manifests/` carries no wpforms entry,
 * `manifests/dispositions/` carries no reviewed entry for it, docs/capabilities.md
 * says nothing about WPForms, and no adapter digest moves (AGENTS.md rule 2).
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
require_once $root . '/agent/src/Grammar/BodyRefGrammar.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Grammar/Shortcodes.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Adapter/AdapterCertification.php';

use Duo\AdapterCertification;
use Duo\Blocks;
use Duo\BodyRefGrammar;
use Duo\Canon;
use Duo\Policy;
use Duo\Tokens;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

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
$loadSite = static function (array $manifest) use ($root): Policy {
    $dir = sys_get_temp_dir() . '/duo_wpforms_site_' . bin2hex(random_bytes(8));
    if (!mkdir($dir . '/adapters', 0700, true) && !is_dir($dir . '/adapters')) {
        throw new RuntimeException("could not create scratch site repository $dir");
    }
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/adapters/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($dir . '/site.duo.json');
        @rmdir($dir . '/adapters');
        @rmdir($dir);
    });
    Canon::write_file($dir . '/adapters/wpforms-lite.json', Canon::encode($manifest));
    Canon::write_file($dir . '/site.duo.json', Canon::encode([
        'manifests' => [['name' => 'wpforms-lite', 'source' => 'site']],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    // The shipped library stays the real one: a site adapter loads BESIDE it,
    // and pointing DUO_MANIFESTS_DIR at the scratch repo would make the same
    // file its own shipped namesake ("shadows the shipped adapter").
    putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');

    return Policy::load($dir, ['wpforms-lite']);
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

duo_check_same(
    $adapterBytes,
    Canon::encode($adapter),
    'A1: the committed adapter is already CANONICAL on disk — `duo adapter certify` rewrites a non-canonical '
        . 'file before signing it, so a file it would rewrite is one the author has not finished'
);
duo_check_same(
    ['wpforms-lite', 3, 'wpforms-lite/wpforms.php'],
    [$adapter['name'], $adapter['spec_version'], $adapter['plugin']],
    'A1: and it declares the identity the fixture package is named for, at spec_version 3'
);
duo_check_same(
    ['attr-id-codecs/v1', 'spec-window/v1', 'structured-body-refs/v1', 'structured-evidence/v1'],
    $adapter['engine_features'],
    'A1: with exactly the four engine features every section below rides on'
);

$policy = $loadSite($adapter);
duo_check_same(
    ['wpforms-lite'],
    array_column($policy->manifests, 'name'),
    'A2: the real loader accepts it through the SITE source, with no shipped namesake and no certificate'
);
duo_check_same(
    'site',
    $policy->adapter_sources()->source('wpforms-lite'),
    'A2: and resolves it as source=site — the out-of-tree contract and the vendor-namespace rule both ran'
);

// A3 — the projections every consumer below reads. Asserted here, once, so a
// later group's failure is about the CODEC and never about the declaration
// having quietly stopped reaching the engine.
duo_check_same('json', $policy->body_mode('wpforms'), 'A3: post_types.wpforms.body reaches the policy as the json mode');
duo_check_same(
    [
        'json_refs' => [['cast' => 'string', 'kind' => 'post', 'path' => $confirmationPath]],
        'sentinels' => [$confirmationPath => ['previous_page']],
    ],
    duo_check_ksort_recursive($policy->body_ref_rule('wpforms')),
    'A3: and the one declared body reference path, with the one sentinel over it'
);
duo_check_same(
    ['wpforms/form-selector' => [['kind' => 'post', 'path' => 'formId', 'type' => 'int']]],
    duo_check_ksort_recursive($policy->block_attr_rules()),
    'A3: the block attribute rule is the one the recon measured unadapted'
);
duo_check_same(
    ['wpforms/form-selector' => ['formId' => ['id_type' => 'string']]],
    duo_check_ksort_recursive($policy->attr_id_codec_rules()),
    'A3: refined by the string id codec, so a resolved id is written back quoted'
);
duo_check_same(
    'derived',
    $policy->post_meta_rule('wpforms_form_locations')['class'] ?? null,
    'A3: wpforms_form_locations is derived — the plugin regenerates it, so capture must not carry its page id'
);
duo_check_same(
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
duo_check_same(
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
duo_check_throws(
    static fn(): Policy => $loadSite($variant(['engine_features' => $withoutFeature('structured-body-refs/v1')])),
    RuntimeException::class,
    'A4: drop structured-body-refs/v1 and the manifest does not degrade to `verbatim` — it refuses BY KEY',
    "the top-level key 'body_refs', which this engine does not recognise"
);
duo_check_throws(
    static fn(): Policy => $loadSite($variant(['engine_features' => $withoutFeature('attr-id-codecs/v1')])),
    RuntimeException::class,
    'A5: drop attr-id-codecs/v1 and the string-id codec is refused BY KEY, not silently ignored',
    "the top-level key 'attr_id_codecs', which this engine does not recognise"
);
duo_check_throws(
    static fn(): Policy => $loadSite($variant(['engine_features' => $withoutFeature('structured-evidence/v1')])),
    RuntimeException::class,
    'A6: drop structured-evidence/v1 and the measured-evidence section is refused BY KEY — evidence a checker '
        . 'cannot read is what this section exists to replace',
    "the top-level key 'declaration_evidence', which this engine does not recognise"
);
duo_check_throws(
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
 * Seed duo_map with one row per entity this environment holds.
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
    $wpdb->seedTable('wp_duo_map', $seeded);
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
    duo_check_same(
        $raw,
        $captureBody($raw, $warnings),
        "B1: $name has no declared reference in it, and capture reproduces its bytes exactly"
    );
    duo_check_same([], $warnings, 'B1: and warns about nothing, because nothing was dropped');
}

// B2 — THE TYPE-VARIANT `$.id` THIS ADAPTER DELIBERATELY DOES NOT DECLARE.
// Three write paths, three shapes, and the ledger row's original one-sentence
// claim was true for exactly one of them. Preservation rather than
// normalisation is what makes leaving it undeclared safe.
$decodedA = json_decode($capture('form-a.post_content.raw.json'), true);
$decodedB = json_decode($capture('form-pathb.post_content.raw.json'), true);
$decodedC = json_decode($capture('form-pathc.post_content.raw.json'), true);
duo_check_same(
    [false, true, true],
    [array_key_exists('id', $decodedA), array_key_exists('id', $decodedB), array_key_exists('id', $decodedC)],
    'B2: the template create path writes no top-level `id` key at all; the other two do'
);
duo_check_same(
    ['int', 'string'],
    [get_debug_type($decodedB['id']), get_debug_type($decodedC['id'])],
    'B2: and they disagree about its TYPE — int on the builder=false path, string on the real builder save'
);

// B3 — THE CROSS-ENTITY REFERENCE, THE SENTINEL, AND THE TWO VALUES THIS
// ADAPTER CANNOT REBIND. One form carries all four facts.
$formB = $capture('form-b.post_content.raw.json');
$warnings = [];
$capturedB = $captureBody($formB, $warnings);
duo_check_same([], $warnings, 'B3: the page reference resolves in this environment, so nothing is dropped');
duo_check(
    str_contains($capturedB, '"page":"{{post:' . $pageUuid . '}}"'),
    'B3: the confirmation page id — a JSON STRING on the wire — tokenises and stays a STRING'
);
duo_check(
    !str_contains($capturedB, '"page":"4"'),
    'B3: and no source-local page id survives into canonical state'
);
duo_check(
    str_contains($capturedB, '"page":"previous_page"'),
    'B3: the declared sentinel on the SAME key passes through untouched — coercing it would repoint the '
        . 'confirmation at post 0 (includes/class-process.php:1553-1562 compares it identically)'
);
duo_check(
    str_contains($capturedB, '"redirect":"http:\/\/localhost:9620\/recon-thank-you\/"'),
    'B3: and the sibling `redirect` — the source site\'s absolute home URL — crosses UNCHANGED, because this '
        . 'mode rewrites declared paths and nothing else. The adapter states that in notes rather than implying '
        . 'the body is portable'
);

// B4/B5 — APPLY, on this environment and on one where the page moved.
duo_check_same(
    $formB,
    $applyBody($capturedB),
    'B4: apply on the SAME environment reproduces the post body byte for byte'
);
$seedMap([$pageUuid => 3456, $formBUuid => 6, $formCUuid => 14]);
$onTarget = $applyBody($capturedB);
duo_check(
    str_contains($onTarget, '"page":"3456"'),
    'B5: on a target where the page is row 3456 the reference is rewritten — as a STRING of the new length'
);
duo_check(
    !str_contains($onTarget, '"page":3456'),
    'B5: and never as the bare integer the generic json_refs default would have written'
);
duo_check(
    str_contains($onTarget, '"page":"previous_page"'),
    'B5: the sentinel is still the sentinel on the target'
);

// B6 — RECAPTURE. The property the whole classification rests on
// (docs/guides/adapter-authoring.md § 6): capture -> apply -> recapture returns
// the same canonical bytes, so the repository does not churn per environment.
$warnings = [];
duo_check_same(
    $capturedB,
    $captureBody($onTarget, $warnings),
    'B6: recapturing the applied body on the TARGET yields the same canonical bytes as the source capture'
);
duo_check_same([], $warnings, 'B6: with nothing dropped on the target either');

// ===========================================================================
// C. THE BLOCK — the measured embedding page, through the same adapter
// ===========================================================================

$seedMap([$pageUuid => 4, $formBUuid => 6, $formCUuid => 14]);
$page = $capture('contact-page.post_content.html');
$capturedPage = Blocks::capture_rewrite($page, $policy, $tokensFor(), false, "page 'recon-contact-page'");

duo_check(
    str_contains($capturedPage, '"formId":"{{post:' . $formBUuid . '}}"')
        && str_contains($capturedPage, '"formId":"{{post:' . $formCUuid . '}}"'),
    'C1: both embedded form ids tokenise, and both stay STRINGS — the recon measured `wp duo lint` reporting '
        . 'exactly these two as unregistered_block_attr before this declaration existed'
);
duo_check(
    str_contains($capturedPage, '"copyPasteJsonValue":"{u0022fieldSizeu0022:u0022mediumu0022}"'),
    'C1: and the one non-id attribute beside them survives byte for byte, backslash damage included — a codec '
        . 'that tidied it would be rewriting content it was never declared over'
);
duo_check(
    str_contains($capturedPage, '<p>Get in touch.</p>'),
    'C1: the page\'s ordinary block content is untouched as well'
);

duo_check_same(
    $page,
    Blocks::apply_rewrite($capturedPage, $policy, $tokensFor()),
    'C2: apply on the SAME environment reproduces the page byte for byte — the round trip that wrote '
        . '{"formId":6} before WP-6.1'
);

$seedMap([$pageUuid => 4, $formBUuid => 91, $formCUuid => 92]);
$pageOnTarget = Blocks::apply_rewrite($capturedPage, $policy, $tokensFor());
duo_check(
    str_contains($pageOnTarget, '{"formId":"91"}'),
    'C3: on a target whose forms are rows 91 and 92 the first embed renders the TARGET\'s form id, quoted'
);
duo_check(
    str_contains($pageOnTarget, '"formId":"92"') && !str_contains($pageOnTarget, '"formId":92'),
    'C3: and so does the second — neither is written as a bare integer'
);
duo_check_same(
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

duo_check(
    str_contains($bodyOnTarget, '"page":"77"'),
    'D1: after apply the form\'s confirmation points at the TARGET\'s own page id, not the source\'s 4'
);
duo_check(
    str_contains($pageOnTarget, '"formId":"91"') && str_contains($pageOnTarget, '"formId":"92"'),
    'D2: and the embedding page renders the TARGET\'s own form ids, not the source\'s 6 and 14'
);
foreach (['"page":"4"' => 'the source page id', '"formId":"6"' => 'the source form id'] as $needle => $what) {
    duo_check(
        !str_contains($bodyOnTarget . $pageOnTarget, $needle),
        "D3: no trace of $what survives anywhere in the applied pair"
    );
}
duo_check(
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
duo_check(
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
duo_check_same(
    // Sorted, because the resolver sorts: the cascade set is a SET, and the
    // manifest's own spelling order is not a declaration about anything.
    ['term_relationships', 'term_taxonomy', 'termmeta'],
    $termDeletion['cascades'] ?? null,
    'E2: term:wpforms_form_tag declares the complete term cascade set the engine requires'
);
duo_check_same(
    [['column' => 'term_taxonomy_id', 'table' => 'term_relationships']],
    array_map(
        static fn(array $g): array => ['column' => $g['column'], 'table' => $g['table']],
        $termDeletion['guards'] ?? []
    ),
    'E2: guarded on the ONE reverse reference whose index leads — the deletion-feasibility report returned '
        . 'reason:null for it, which is the necessary condition for the guard to lock'
);
duo_check_same(
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
duo_check_same(
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
duo_check(
    !str_contains(Canon::encode($adapter['tables']), 'actionscheduler'),
    'E3: and the four live Action Scheduler tables WPForms Lite also creates are declared by NOTHING here — '
        . 'they belong to a bundled shared library, so a per-plugin adapter claiming them would be the first of '
        . 'N adapters fighting over one namespace'
);

// E4 — THE PRICE OF THE FEATURE CHANNEL, in the signer's own words. Recorded as
// an assertion rather than a note because it is the one consequence an operator
// meets: this adapter loads everywhere and cannot be certified today.
$ratify = (new ReflectionClass(AdapterCertification::class))->getMethod('siteRatification');
$signerVerdict = null;
try {
    $ratify->invoke(null, 'wpforms-lite', $adapter, 'wpforms-lite fixture');
} catch (\Throwable $e) {
    $signerVerdict = $e->getMessage();
}
duo_check(
    is_string($signerVerdict)
    && str_contains($signerVerdict, 'which this signer cannot classify as an entity or field surface'),
    'E4: `duo adapter certify` refuses this manifest — every feature-claimed key is in no arm of the signer\'s '
        . 'top-level partition, and siteSurfaceSections() is shared by the derived AND the --ratification-file '
        . 'authored profile, so an author cannot certify such a section by naming it in a file either'
);
duo_check(
    is_string($signerVerdict)
    && (str_contains($signerVerdict, "'attr_id_codecs'")
        || str_contains($signerVerdict, "'body_refs'")
        || str_contains($signerVerdict, "'declaration_evidence'")
        || str_contains($signerVerdict, "'engine_features'")),
    'E4: and the refusal NAMES the offending section, so the trade-off is legible rather than a missing surface'
);

// E5 — the evidence section is not decoration: every record addresses a
// declaration this manifest still makes. `StructuredEvidence::assert_target()`
// enforces the head at load; this asserts the count did not quietly shrink to
// the one row that would satisfy the grammar.
$targets = array_keys($adapter['declaration_evidence']);
sort($targets, SORT_STRING);
duo_check_same(
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
duo_check(
    $rowCount >= 20,
    "E5: carrying $rowCount {source, locator, observation} rows — the measured half of the authoring loop, "
        . 'surviving the ratification that used to delete it with `_draft`'
);

// E6 — THE RANGE IS BOUND TO THE RECORDED RELEASES, read through the shipped
// reader rather than by re-parsing the file here. `version_range` is the one
// declaration in this manifest that nothing else in the corpus can falsify:
// a grammar check proves it well-formed and says nothing about whether the
// evidence covers it. The release list beside the adapter is that evidence —
// four fetched-and-hashed releases, the document `duo adapter boundary`
// accepts — so the two are compared, in the only two directions that can be
// wrong.
require_once $root . '/cli/src/Adapter/AdapterBoundary.php';
$releases = \Duo\Orchestrator\AdapterBoundary::readReleaseList(
    $root . '/sandbox/fixtures/wpforms-lite/wpforms-lite.releases.json'
);
$versions = array_column($releases['releases'], 'version');
duo_check_same(
    ['1.9.9.4', '2.0.0.3', '2.0.0.4', '2.0.0.5'],
    $versions,
    'E6: the committed release list is the four digest-pinned releases the boundary planner reads, in release order'
);
duo_check(
    in_array($adapter['version_range']['min'], $versions, true),
    'E6: the range FLOOR is a release the list actually records — 2.0.0.4, the older of the two the engine-gap '
        . 'ledger probed. A floor no recorded release names would be a claim with nothing behind it'
);
$aboveCeiling = array_values(array_filter(
    $versions,
    static fn(string $v): bool => version_compare($v, (string) $adapter['version_range']['max'], '>=')
));
duo_check_same(
    [],
    $aboveCeiling,
    'E6: and the CEILING is exclusive of every recorded release, so the window admits exactly the measured '
        . '2.0.0.x series and stops at the next minor rather than at the next major'
);

// ===========================================================================
// F. FRICTION 7 — THE BOOLEAN THAT ONCE BLOCKED CAPTURE, NOW WITHOUT lint_ok
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
// suite and the fixture's lint_ok removal), rerun this file, and F1 fails —
// `scan_tree()` returns a `bare_id` finding at
// `options/core.json options.wpforms_settings[modern-markup]`, the same
// locator `LintTrustGate` quoted live before this fix. `git stash pop`
// restores the fix and F1 passes again. That sequence is the demonstration;
// it is not re-run here because a suite cannot stash its own dependency out
// from under itself mid-run.
// ===========================================================================

require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Review/Lint.php';

use Duo\Lint;
use Duo\LintEnvironment;
use Duo\OptionState;

$lintTmp = sys_get_temp_dir() . '/duo_wpforms_lint_' . bin2hex(random_bytes(8));
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
// pending hint against. Without it $env->resolve_id(1) answers null and F1
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
duo_check_same(
    [],
    $bareIdOnModernMarkup,
    'F1: the boolean\'s own "1" produces no bare_id finding — Lint::scan_options_file()\'s bare_id loop now '
        . 'carries the same wholly-0/1 suppression Pending::ref_hint() has had since DUO-3508, so capture no '
        . 'longer needs `lint_ok: true` on this declaration to get past LintTrustGate'
);

// F2 — the suppression is SCOPED to the {0,1} value class, not to "this
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
duo_check_same(
    ['options.wpforms_settings[unrelated_authored_post_ref]'],
    $locators,
    'F2: a sibling element holding a genuine non-{0,1} id (7) still flags as bare_id, and modern-markup\'s own '
        . '"1" still does not — the suppression is per-candidate value, not "any option shaped like this escapes '
        . 'lint entirely"'
);

// F3 — Pending::ref_hint()'s OWN documented cost (Pending.php:315-329, "small
// ids coincide … applied twice") is carried over rather than narrowed: a
// genuine reference that happens to be stored as the bare value 1 is STILL
// swallowed by this suppression, on either boolean-flag position. Asserting
// this is what keeps the fix from silently drifting into a key-name
// heuristic ("only suppress a key literally called modern-markup") that
// CAREFUL SCOPE never asked for and DUO-3508 does not do either.
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
duo_check_same(
    [],
    $costFindings,
    'F3: a second element that genuinely IS post #1 stored as bare 1 is also swallowed — the same cost '
        . 'Pending::ref_hint() already accepts for a whole-value \'1\' option, not a new, narrower one'
);

duo_check_summary('regress_wpforms_lite_adapter');
