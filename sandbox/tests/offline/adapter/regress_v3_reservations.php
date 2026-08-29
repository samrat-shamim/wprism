<?php
/**
 * WP-4.11 — the reserved-but-refusing slots (spec/repo-format.md § v3.10),
 * and WP-5.2's flip of two of them (§ v3.16).
 *
 * THE CONTRACT THIS FILE MADE WITH ITS OWN FUTURE, now honoured once. WP-4.11's
 * PART 5 wrote down that opening a slot would be "an edit to the pins in this
 * file — visible in a diff, and failing here until someone makes it
 * deliberately". WP-5.2 made that edit for the two reviewer-tier slots: PART 2
 * lost two rows, PART 2b gained them as ADMISSIONS, and the sentences they used
 * to raise moved into § v3.10's second table as what a v3-era reader still
 * says. Nothing about the reservation mechanism was relaxed to allow it, and
 * the three slots that belong to gate G5 are untouched.
 *
 * WHAT A RESERVATION IS HERE, AND WHY IT IS NOT AN ADMITTED KEY
 * -------------------------------------------------------------
 * Every slot § v3.10 reserves lands inside a set that is ALREADY closed and
 * already refuses it: the manifest key set at `spec_version: 3` (§ v3.3), the
 * signed statement's six members (R-06), the bundle evidence object, and the
 * certification vocabulary. Admitting the member instead would move a wire —
 * the statement's member set is also the generation discriminator, decidable
 * before a signature (R-24), and the evidence object is inside the digest a
 * certificate binds. So the reservation is the REFUSAL: the member is named,
 * the gate that decides is named, and not one closed set changes.
 *
 * That makes the rider's whole claim measurable, and this suite measures it in
 * five parts:
 *
 *   PART 1 — NOTHING THE ENGINE ACCEPTS TODAY MOVED. All 17 shipped manifests
 *   still validate, every word the certification vocabulary knows still passes
 *   the host validator, and a well-formed six-member statement and a
 *   well-formed evidence object still pass the two functions this rider edited.
 *
 *   PART 2 — THE PINNED REFUSALS THAT REMAIN, each driven at its shipped
 *   attachment point and compared against the exact sentence
 *   spec/repo-format.md § v3.10 publishes. A refusal that drifts from the
 *   published sentence is a reservation an author was promised and the engine
 *   does not keep; regress_spec_v3_document.php owns the other direction.
 *
 *   PART 2b — THE TWO SLOTS WP-5.2 OPENED, measured as opened (§ v3.16). This
 *   is the part the whole reservation was bought for, and it is why the header
 *   above says a flip is an edit to the pins in THIS file: the assertions that
 *   used to demand two refusals now demand two admissions, in one visible diff.
 *   The retired sentences are still asserted to be PUBLISHED, because they are
 *   live bytes on every host that has not taken WP-5.2's release.
 *
 *   PART 3 — NO VERDICT MOVES THAT WAS NOT DELIBERATELY MOVED. Each input that
 *   is still reserved was refused BEFORE WP-4.11 and is refused after it, by
 *   the same exception class and with the same consequence. The sharp case is
 *   the certificate: `RuntimeException` is a hard refusal while
 *   `SupersededWireSiteAdapterCertificate` degrades ONE adapter to uncertified
 *   and leaves the site's others alone, so a reservation that moved an input
 *   between those two arms would have changed a fleet behaviour while claiming
 *   to change a message. Both arms are asserted. The two OPENED slots are
 *   asserted to raise nothing, which is the only shape "opened" can take on a
 *   closed set.
 *
 *   PART 4 — THE FLAG-DAY INVARIANT, on the one surface a stranger already
 *   holds bytes of. A pinned six-member statement's canonical bytes, their
 *   digest, the signature domain and the Ed25519 signature over the exact
 *   preimage a holder recomputes are all fixed vectors here. If any of them
 *   moved, every certificate in the field would have stopped verifying.
 *
 *   PART 5 — G5's CONDITION 7 (§ v3.11): opening the lane is a POLICY FLIP
 *   proven by suite, not a format break. Three mechanisms are measured rather
 *   than asserted: § v3.2's growth channel already admits a top-level key an
 *   implemented feature claims, so `package` needs a decision and not a format;
 *   a statement written in a generation this engine does not implement is
 *   withdrawn BY NUMBER and per adapter, so a later generation carrying
 *   `code_digest` degrades one adapter instead of reading as corruption; and
 *   every reserved word is refused BY NAME, so the flip is an edit to the pins
 *   in this file — visible in a diff, and failing here until it is made.
 *
 * WHY THE MANIFEST SLOT IS DRIVEN IN A CHILD PROCESS
 * --------------------------------------------------
 * `package` refuses inside the closed key set, which is gated at
 * `spec_version: 3`, and § v3.1 refuses a v3 manifest wholesale at this
 * engine's `WPRISM_SPEC_VERSION` 2 one step earlier — so the rule is unreachable
 * through the product path by construction, exactly as § v3.3 and § v3.5 are.
 * A spec version is a `define()` and a PHP process holds one, so the N+1 engine
 * is a child of this file: the same technique, and the same argument,
 * regress_closed_top_level_keys.php and regress_spec_window.php state.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 4);

/**
 * The verdict matrix for the manifest slot, run against whichever
 * `WPRISM_SPEC_VERSION` the process holds.
 *
 * @return array<string,mixed>
 */
function v3_reservations_manifest_report(int $supported): array {
    $verdict = static function (array $manifest): ?string {
        try {
            \WPrism\AdapterContractGrammar::validate_adapter_contract($manifest);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    };
    $named = static fn(array $extra = []): array
        => ['name' => 'acme-widget', 'spec_version' => $supported] + $extra;
    // The feature that claims `engine_features` itself, derived rather than
    // taken as `[0]`: WP-6.1's two additions sort ahead of it and claim other
    // keys, and this probe declares `engine_features` — so the wrong feature
    // would measure the closed key set instead of the growth channel.
    // The feature that CLAIMS `engine_features`, not the roster's first entry:
    // the growth probe below declares that key, so it needs the feature that
    // ADMITS it. WP-6.2's `invalidate-vocabulary/v1` claims no key at all and
    // sorts first, so a positional pick started measuring an unrecognised
    // section instead of the growth rule this part is about.
    $feature = 'none/v0';
    foreach (\WPrism\AdapterContractGrammar::implemented_features() as $candidate) {
        $claims = \WPrism\AdapterContractGrammar::admitted_feature_keys(['engine_features' => [$candidate]]);
        if (in_array('engine_features', $claims, true)) {
            $feature = $candidate;
            break;
        }
    }

    return [
        'engine_supported' => $supported,
        // The reserved key, alone: the case § v3.10 publishes a sentence for.
        'package' => $verdict($named(['package' => ['url' => 'https://example.test/acme.tar']])),
        // The reserved key beside an ordinary unknown one. `_draft` aside, the
        // general refusal names every offending key at once, so this asks which
        // sentence wins when both are available.
        'package_and_unknown' => $verdict($named([
            'package' => [],
            'totally_made_up_section' => ['acme_thing' => ['class' => 'authored']],
        ])),
        // `_draft` still wins over `package`: a draft sidecar is a fact about
        // the WHOLE document and has to be stripped before anything else in it
        // is worth reading (AdapterContractGrammar's own note on that case).
        'draft_and_package' => $verdict($named([
            '_draft' => ['proposals' => [], 'evidence' => []],
            'package' => [],
        ])),
        // The control: an ordinary unknown key still gets the ordinary refusal,
        // which is how this suite knows the reserved sentence is not simply
        // replacing the general one.
        'unknown_only' => $verdict($named([
            'totally_made_up_section' => ['acme_thing' => ['class' => 'authored']],
        ])),
        // § v3.2's growth channel, the mechanism a later opening would use: a
        // key claimed by a feature this engine IMPLEMENTS is admitted with no
        // format change at all.
        'feature_claimed' => $verdict($named(['engine_features' => [$feature]])),
        'bare' => $verdict($named()),
    ];
}

// ---------------------------------------------------------------------------
// CHILD MODE. `php <this file> --probe <N>` loads the SHIPPED grammar under a
// synthetic WPRISM_SPEC_VERSION and prints the matrix as JSON. It runs before
// check.php is required and exits before any assertion, so the child
// contributes no counted checks and no output the corpus diagnostics guard
// reads.
// ---------------------------------------------------------------------------
$reservationArgv = is_array($_SERVER['argv'] ?? null) ? array_map('strval', $_SERVER['argv']) : [];
if (($reservationArgv[1] ?? '') === '--probe') {
    define('WPRISM_SPEC_VERSION', (int) ($reservationArgv[2] ?? 0));
    if (!defined('WPRISM_AGENT_VERSION')) {
        define('WPRISM_AGENT_VERSION', '0.0.0');
    }
    require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
    require_once $repo . '/agent/src/Adapter/AdapterCertification.php';
    echo json_encode(v3_reservations_manifest_report(WPRISM_SPEC_VERSION), JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

require_once __DIR__ . '/../../lib/check.php';

// The engine's own defines, read out of agent/wprism.php rather than typed, so
// this file says nothing about which integer N happens to be (AGENTS.md rule 8
// binds those two lines and platform.json together).
$wprismSource = (string) file_get_contents($repo . '/agent/wprism.php');
if (preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $wprismSource, $specMatch) !== 1) {
    fwrite(STDERR, "could not resolve WPRISM_SPEC_VERSION from agent/wprism.php\n");
    exit(1);
}
define('WPRISM_SPEC_VERSION', (int) $specMatch[1]);
preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $wprismSource, $agentMatch);
define('WPRISM_AGENT_VERSION', (string) ($agentMatch[1] ?? '0.0.0'));

require_once $repo . '/agent/src/Kernel/Canon.php';
require_once $repo . '/agent/src/Kernel/OptionState.php';
require_once $repo . '/agent/src/Kernel/Db.php';
require_once $repo . '/agent/src/Adapter/AdapterSources.php';
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
require_once $repo . '/agent/src/Adapter/AdapterCertification.php';
require_once $repo . '/agent/src/Policy/AdapterLibrary.php';
require_once $repo . '/cli/src/Adapter/AdapterObservation.php';

use WPrism\AdapterCertification;
use WPrism\AdapterContractGrammar;
use WPrism\AdapterLibrary;
use WPrism\AdapterSources;
use WPrism\Canon;
use WPrism\SupersededWireSiteAdapterCertificate;
use WPrism\Orchestrator\AdapterObservation;

/** One indented report row, indented so the diagnostics guard cannot read it as a PHP notice. */
$report = static function (string $line): void {
    echo '  ' . $line . "\n";
};

/**
 * Invoke one of the two private shape proofs this rider edited.
 *
 * Reflection and not the product path, for the reason
 * regress_certificate_axis_binding.php gives about `platformStatement()`: these
 * are the functions that OWN the member sets, a full end-to-end certificate
 * would prove the same member set through four unrelated bindings, and the
 * generation/verdict distinction PART 3 measures is only visible at the
 * function that raises it.
 *
 * @param list<mixed> $args
 */
$invoke = static function (string $method, array $args) {
    return (new ReflectionMethod(AdapterCertification::class, $method))->invoke(null, ...$args);
};

/** @return array{class:?string, message:?string} the refusal one call raised, if any. */
$refusal = static function (callable $fn): array {
    try {
        $fn();

        return ['class' => null, 'message' => null];
    } catch (\Throwable $e) {
        return ['class' => $e::class, 'message' => $e->getMessage()];
    }
};

/** A statement's typed twin: `assertStatementShape()` reads both shapes of the same bytes. */
$typed = static fn(array $statement): object
    => (object) json_decode((string) json_encode($statement), false, 512, JSON_THROW_ON_ERROR);

/** The six-member statement this suite drives, well formed unless a case says otherwise. */
$goodStatement = [
    'adapter' => [
        'canonical_sha256' => str_repeat('a', 64),
        'name' => 'acme-widget',
        'path' => 'adapters/acme-widget.json',
        'raw_sha256' => str_repeat('b', 64),
        'raw_size' => 512,
        'source' => 'site',
        'trust_tier' => 'declarative_manifest',
    ],
    'authority' => ['key_id' => 'acme-ops', 'trust_root' => 'site'],
    'bundle' => ['bundle_digest' => str_repeat('c', 64), 'exercised' => true],
    'platform' => [
        'agent_version' => '0.5.0',
        'axes' => ['php' => ['cells' => ['8.3'], 'sha256' => str_repeat('d', 64)]],
        'site_mode' => 'single-site',
        'spec_version' => 2,
    ],
    'ratification' => ['manifests' => 16, 'profiles' => 1],
    'version' => AdapterCertification::STATEMENT_VERSION,
];

/**
 * A minimal catalog the host observer's own validator accepts, so the reserved
 * WORD can be driven at the boundary where a foreign word actually arrives.
 *
 * @param ?string $certification the word under test
 */
$catalog = static function (?string $certification): array {
    return [
        'format' => 'wprism-adapter-sources/v2',
        'installed' => [[
            'certification' => $certification,
            'grammar' => 'ok',
            'interpreter' => false,
            'manifest_provider_count' => 0,
            'name' => 'acme-widget',
            'regenerator_count' => 0,
            'sha256' => 'sha256:' . str_repeat('e', 64),
            'source' => 'site',
            'trust_tier' => 'declarative_manifest',
        ]],
        'not_installed' => [],
        'refusals' => [],
        'sources' => [
            ['scanned' => true, 'source' => 'plugin'],
            ['scanned' => true, 'source' => 'shipped'],
            ['scanned' => true, 'source' => 'site'],
        ],
        'summary' => ['installed' => 1, 'not_installed' => 0, 'refusals' => 0],
    ];
};
$validateCatalog = static function (array $catalog): void {
    (new ReflectionMethod(AdapterObservation::class, 'validate_catalog'))->invoke(null, $catalog);
};

// THE PINNED SENTENCES, read out of spec/repo-format.md § v3.10 rather than
// typed twice. The spec table is the publication; a suite that restated it
// would be the second copy that rots, and the whole point of a pinned refusal
// is that the sentence an author is shown and the sentence the engine raises
// are one string.
$spec = (string) file_get_contents($repo . '/spec/repo-format.md');
$reservationSection = preg_match('/^### v3\.10 .*?(?=^### )/ms', $spec, $sectionMatch) === 1 ? $sectionMatch[0] : '';
$pinned = [];
foreach (explode("\n", $reservationSection) as $line) {
    if (preg_match('/^\| [^|]+ \| [^|]+ \| [^|]+ \| `(wprism: [^`]+)` \|$/', $line, $rowMatch) === 1) {
        $pinned[] = $rowMatch[1];
    }
}
wprism_check_same(
    3,
    count($pinned),
    'the three STILL-RESERVED refusal sentences are read out of spec/repo-format.md § v3.10\'s first table, so '
    . 'this suite and the published table cannot disagree about what the engine promises to say'
);
[$pinPackage, $pinCodeDigest, $pinDelegated] = $pinned + array_fill(0, 3, '');

// THE TWO SENTENCES THE FLIP RETIRED, read out of § v3.10's SECOND table — the
// five-column one, which is why the four-column regex above does not see them.
// They are not dead text: those bytes are live on every host that has not taken
// WP-5.2's release, and the paragraph beside them says so. This suite therefore
// keeps asserting they are published, and asserts BELOW that this engine no
// longer says them.
$opened = [];
foreach (explode("\n", $reservationSection) as $line) {
    if (preg_match('/^\| [^|]+ \| [^|]+ \| [^|]+ \| `(wprism: [^`]+)` \| [^|]+ \|$/', $line, $rowMatch) === 1) {
        $opened[] = $rowMatch[1];
    }
}
wprism_check_same(
    2,
    count($opened),
    'and § v3.10 records the two sentences WP-5.2 retired rather than deleting them, because a v3-era host '
    . 'still answers with those exact bytes and an operator meeting one has to recognise them'
);
[$retiredReviewerWord, $retiredReviewerEvidence] = $opened + array_fill(0, 2, '');

// ===========================================================================
echo "\nPART 1 — nothing the engine accepts today moved\n";
// ===========================================================================

$shippedManifests = [];
$manifestRefusals = [];
foreach (AdapterLibrary::fromSourceTree($repo)->packages() as $package) {
    $file = $package->manifestPath();
    $manifest = json_decode((string) file_get_contents($file), true);
    if (!is_array($manifest) || !is_string($manifest['name'] ?? null)) {
        continue;
    }
    $shippedManifests[] = $manifest['name'];
    try {
        AdapterContractGrammar::validate_adapter_contract($manifest);
    } catch (\Throwable $e) {
        $manifestRefusals[$manifest['name']] = $e->getMessage();
    }
}
$report(count($shippedManifests) . ' shipped manifests validated against the edited grammar');
wprism_check_same(
    [],
    $manifestRefusals,
    'every shipped manifest still loads through the grammar this rider edited — the flag-day invariant, and '
    . 'the reason no manifest byte and no adapter digest moved (AGENTS.md rule 2)'
);
wprism_check(
    count($shippedManifests) >= 16,
    'and the library it was measured over is the whole shipped one (' . count($shippedManifests) . ' manifests), '
    . 'not a fixture that would pass whatever the grammar did'
);

$vocabularyRefusals = [];
foreach (
    ['certification_unjudged', 'registry', 'signed_unpinned', 'site_signed', 'third_party_signed', 'uncertified', null]
    as $word
) {
    $answer = $refusal(static fn() => $validateCatalog($catalog($word)));
    if ($answer['class'] !== null) {
        $vocabularyRefusals[(string) $word] = (string) $answer['message'];
    }
}
wprism_check_same(
    [],
    $vocabularyRefusals,
    'every word the certification vocabulary already knows still passes the host observer, and so does the '
    . 'null a shipped adapter carries — a reservation that narrowed the vocabulary would refuse observations '
    . 'of targets that never changed'
);

$goodShape = $refusal(static fn() => $invoke('assertStatementShape', ['acme-widget', $typed($goodStatement), $goodStatement]));
wprism_check_same(
    null,
    $goodShape['message'],
    'a well-formed six-member statement still passes assertStatementShape() unchanged — the reserved-member '
    . 'test runs first and answers nothing about a statement that declares neither'
);
// The fourth argument is the SIGNING key id, threaded in by WP-5.2 so that
// § v3.16's reviewer-is-not-the-signer rule is judged at the one function that
// owns the evidence grammar. It answers nothing about an object declaring no
// reviewer, which is what this assertion measures.
$goodEvidence = $refusal(static fn() => $invoke(
    'bundleEvidence',
    [
        ['exercised' => true, 'grammar' => AdapterSources::GRAMMAR_OK, 'reason' => 'exercised offline'],
        'bundle',
        'platform',
        'acme-ops',
    ]
));
wprism_check_same(
    null,
    $goodEvidence['message'],
    'and a well-formed evidence object still passes bundleEvidence() unchanged, on the platform root, which is '
    . 'the arm every shipped certificate takes'
);

// ===========================================================================
echo "\nPART 2 — the four pinned refusals, at their shipped attachment points\n";
// ===========================================================================

$probe = static function (array $args): array {
    $cmd = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, __FILE__], $args)));
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new \RuntimeException("could not run: $cmd");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) {
        throw new \RuntimeException("probe exited $exit: $stderr");
    }
    $decoded = json_decode(trim($stdout), true);

    return is_array($decoded) ? $decoded : [];
};

$nextEngine = $probe(['--probe', (string) (WPRISM_SPEC_VERSION + 1)]);
// `??` cannot read these: a PASSING case is JSON `null` — the verdict function
// returns null when the manifest LOADS — and `??` would report an admitted
// manifest and a missing probe as the same thing, which is the one distinction
// PART 3 and PART 5 turn on.
$verdictOf = static function (string $case) use ($nextEngine): string {
    if (!array_key_exists($case, $nextEngine)) {
        return "probe did not answer for '$case'";
    }

    return $nextEngine[$case] === null ? '' : (string) $nextEngine[$case];
};
$report('N+1 engine probed at WPRISM_SPEC_VERSION ' . ($nextEngine['engine_supported'] ?? '?'));
wprism_check_same(
    $pinPackage,
    // The published sentence carries the `<name>` placeholder the table has to
    // print; the engine carries the manifest's own name. Comparing the two
    // after that one substitution is what makes this an EXACT match rather
    // than a substring that would pass on a reworded sentence.
    str_replace("'acme-widget'", "'<name>'", (string) ($nextEngine['package'] ?? '')),
    'the manifest slot refuses at spec_version ' . (WPRISM_SPEC_VERSION + 1) . ' with § v3.10\'s exact sentence, '
    . 'naming gate G5 rather than telling the author to correct a spelling that is not misspelled'
);
wprism_check_same(
    $pinPackage,
    str_replace("'acme-widget'", "'<name>'", (string) ($nextEngine['package_and_unknown'] ?? '')),
    'and it wins over the general unrecognised-key refusal when both apply: the reserved key says something '
    . 'about the LANE, and no remedy the general sentence offers is true of it'
);

$codeDigest = $refusal(static function () use ($invoke, $typed, $goodStatement) {
    $statement = $goodStatement + ['code_digest' => str_repeat('f', 64)];
    $invoke('assertStatementShape', ['acme-widget', $typed($statement), $statement]);
});
wprism_check_same(
    $pinCodeDigest,
    (string) $codeDigest['message'],
    'the statement `code_digest` slot refuses with § v3.10\'s exact sentence, naming the executable lane and '
    . 'gate G5'
);
$delegated = $refusal(static function () use ($invoke, $typed, $goodStatement) {
    $statement = $goodStatement + ['delegated_authority' => ['key_id' => 'acme-delegate']];
    $invoke('assertStatementShape', ['acme-widget', $typed($statement), $statement]);
});
wprism_check_same(
    $pinDelegated,
    (string) $delegated['message'],
    'and the `delegated_authority` slot refuses with its own sentence — a different reason from a different '
    . 'gate: a delegation is verified through its own signed document (§ v3.8), not through a statement member'
);

// ===========================================================================
echo "\nPART 2b — the TWO SLOTS WP-5.2 OPENED, measured as opened (§ v3.16)\n";
// ===========================================================================

// THE POLICY FLIP, and this is what a reservation was bought for. The pins in
// this block used to assert the two sentences above; editing them here is the
// visible, single-diff act § v3.11 condition 7 describes, and it fails until
// somebody makes it deliberately — which is exactly what happened.
$reviewerWord = $refusal(static fn() => $validateCatalog($catalog(AdapterSources::CERTIFICATION_REVIEWER_SIGNED)));
wprism_check_same(
    null,
    $reviewerWord['message'],
    'the certification word `reviewer_signed` is ADMITTED at the host boundary: the observation validator that '
    . 'refused it by name now reads it, because this engine can mint it and a closed enum the target emits and '
    . 'the host cannot read refuses the whole document'
);
wprism_check_same(
    [
        'certification_unjudged', 'registry', 'reviewer_signed', 'signed_unexercised',
        'signed_unpinned', 'site_signed', 'third_party_signed', 'uncertified',
    ],
    (static function (array $words): array {
        sort($words, SORT_STRING);
        return $words;
    })((array) (new ReflectionClass(AdapterObservation::class))->getConstant('CERTIFICATIONS')),
    'and the host vocabulary admits both the reviewer tier and the distinct unexercised-signature state'
);
wprism_check(
    !method_exists(AdapterSources::class, 'reserved_certification_refusal'),
    'and the agent-side reserved-word channel is GONE rather than left answering null for everything: a '
    . 'reservation mechanism with no members is the dead code a reader would mistake for a live rule'
);
wprism_check(
    str_contains($retiredReviewerWord, "is reserved") && str_contains($retiredReviewerWord, 'gate G4'),
    'the sentence it used to raise is still PUBLISHED in § v3.10 as what a v3-era host says, so the two '
    . 'releases can be told apart from the message alone'
);

$reviewerEvidence = $refusal(static fn() => $invoke(
    'bundleEvidence',
    [
        [
            'exercised' => true,
            'grammar' => AdapterSources::GRAMMAR_OK,
            'reason' => 'reviewed',
            'reviewer' => 'acme-conformance-lab',
        ],
        'bundle',
        'platform',
        'acme-ops',
    ]
));
wprism_check_same(
    null,
    $reviewerEvidence['message'],
    'and `evidence.reviewer` is ADMITTED by the evidence grammar rather than refused by name (§ v3.16)'
);
wprism_check_same(
    ['exercised' => true, 'reviewer' => 'acme-conformance-lab'],
    (array) $invoke('bundleEvidence', [
        [
            'exercised' => true,
            'grammar' => AdapterSources::GRAMMAR_OK,
            'reason' => 'reviewed',
            'reviewer' => 'acme-conformance-lab',
        ],
        'bundle',
        'platform',
        'acme-ops',
    ]),
    'returning the party it read, which is the fact the claim then projects the reviewer word from'
);
wprism_check_same(
    ['exercised' => true, 'reviewer' => null],
    (array) $invoke('bundleEvidence', [
        ['exercised' => true, 'grammar' => AdapterSources::GRAMMAR_OK, 'reason' => 'reviewed'],
        'bundle',
        'platform',
        'acme-ops',
    ]),
    'while the three-member object — every bundle ever minted before this rider — is accepted unchanged and '
    . 'names no reviewer, which is what keeps the flip free for the fleet'
);
$stillClosed = $refusal(static fn() => $invoke(
    'bundleEvidence',
    [
        [
            'exercised' => true,
            'grammar' => AdapterSources::GRAMMAR_OK,
            'reason' => 'reviewed',
            'reviewer' => 'acme-conformance-lab',
            'totally_made_up_member' => true,
        ],
        'bundle',
        'platform',
        'acme-ops',
    ]
));
wprism_check(
    str_contains((string) $stillClosed['message'], 'must contain exactly exercised, grammar, reason'),
    'and the evidence object is STILL CLOSED around the admitted member: WP-5.2 opened one named slot, not the '
    . 'key set, so an ordinary unknown member gets the sentence it always got'
);
wprism_check(
    str_contains($retiredReviewerEvidence, 'is reserved') && str_contains($retiredReviewerEvidence, 'gate G4'),
    'and § v3.10 still publishes the sentence this slot used to raise, for the same field reason as the word'
);

// ===========================================================================
echo "\nPART 3 — no verdict moves: same class, same consequence, better sentence\n";
// ===========================================================================

// The control the whole part rests on. An ordinary unknown statement member is
// refused by assertExactKeys() as a hard RuntimeException; the two reserved
// members must reach the SAME class, because the alternative class here is a
// per-adapter withdrawal that leaves the site loading.
$unknownMember = $refusal(static function () use ($invoke, $typed, $goodStatement) {
    $statement = $goodStatement + ['totally_made_up_member' => true];
    $invoke('assertStatementShape', ['acme-widget', $typed($statement), $statement]);
});
wprism_check_same(
    \RuntimeException::class,
    (string) $unknownMember['class'],
    'the control: an ordinary unknown statement member is a hard RuntimeException, which is the verdict a '
    . 'reserved member has to match if this rider changed no behaviour'
);
wprism_check_same(
    [\RuntimeException::class, \RuntimeException::class],
    [(string) $codeDigest['class'], (string) $delegated['class']],
    'and both reserved members reach exactly that class — NOT SupersededWireSiteAdapterCertificate, which '
    . 'degrades one adapter to uncertified and leaves the site loading. A reservation that moved an input '
    . 'between those two arms would have changed a fleet behaviour while claiming to change a message'
);
wprism_check(
    $unknownMember['message'] !== $codeDigest['message']
        && str_contains((string) $unknownMember['message'], 'must contain exactly'),
    'the control also still gets the ORDINARY sentence, so the reservation named two members rather than '
    . 'replacing the closed-set refusal for every member at once'
);
wprism_check_same(
    \RuntimeException::class,
    (string) $stillClosed['class'],
    'the evidence slot is the same story for what it still refuses: an unknown member is a hard refusal of the '
    . 'bundle, exactly as it has always been — WP-5.2 admitted one name and moved no verdict beside it'
);
wprism_check_same(
    [null, null],
    [$reviewerEvidence['class'], $reviewerWord['class']],
    'and the two OPENED slots raise nothing at all now, which is the only shape "opened" can take on a closed '
    . 'set: not a softer refusal, not a warning — an accepted document'
);
wprism_check(
    (string) ($nextEngine['unknown_only'] ?? '') !== ''
        && str_contains((string) $nextEngine['unknown_only'], 'the top-level key set is CLOSED'),
    'and at the N+1 engine an ordinary unknown key still gets § v3.3\'s own refusal, untouched: the manifest '
    . 'reservation added a sentence for one key and took nothing away from the rule it sits inside'
);
wprism_check(
    str_contains((string) ($nextEngine['draft_and_package'] ?? ''), '_draft'),
    'and `_draft` still wins over `package` when a manifest carries both — a draft sidecar is a fact about the '
    . 'whole document, so it is what an author has to act on first'
);
wprism_check_same(
    '',
    $verdictOf('bare'),
    'a v' . (WPRISM_SPEC_VERSION + 1) . ' manifest declaring neither still loads at the N+1 engine, so the '
    . 'reservation refuses a declaration rather than a version'
);

// ===========================================================================
echo "\nPART 4 — the flag-day invariant: no certificate in the field moves\n";
// ===========================================================================

wprism_check_same(
    ['adapter', 'authority', 'bundle', 'platform', 'ratification', 'version'],
    (array) (new ReflectionClass(AdapterCertification::class))->getConstant('STATEMENT_KEYS'),
    'the signed statement is still exactly the six members R-06 closes: reserving two members WITHOUT '
    . 'admitting them is the only way to reserve anything on a signed statement without moving its wire'
);
wprism_check_same(
    "wprism-site-adapter-certification-signature/v2\0",
    AdapterCertification::SIGNATURE_DOMAIN,
    'and the signature domain is untouched — WP-4.7 spent the /vN channel once, and this rider did not spend '
    . 'it again (R-01)'
);
wprism_check_same(
    AdapterCertification::STATEMENT_VERSION,
    2,
    'and the in-statement generation is still 2, so nothing here asks a holder to re-mint'
);

// THE FIXED VECTOR. Canonical bytes, their digest, and the Ed25519 signature
// over the exact preimage a holder recomputes — `SIGNATURE_DOMAIN .
// Canon::encode($statement)` (AdapterCertification::signatureBytes()). The key
// is derived from a fixed seed so the signature is reproducible; the point is
// not secrecy but that these four numbers are the ones every deployed verifier
// is checking, and any of them moving would have silently withdrawn every
// certificate in the field.
$canonical = Canon::encode($goodStatement);
$preimage = AdapterCertification::SIGNATURE_DOMAIN . $canonical;
$pair = sodium_crypto_sign_seed_keypair(str_repeat("\x11", 32));
$signature = sodium_crypto_sign_detached($preimage, sodium_crypto_sign_secretkey($pair));
$report('pinned statement: ' . strlen($canonical) . ' canonical bytes, sha256 ' . substr(hash('sha256', $canonical), 0, 16) . '…');
wprism_check_same(
    '91906e3cad6f4d8cd8a0a2de27d88c52b757b85f0d0c291be3f3432db53a5f99',
    hash('sha256', $canonical),
    'the canonical bytes of a six-member statement are byte-identical to the vector recorded when this rider '
    . 'landed — the preimage half of what every holder recomputes'
);
wprism_check_same(
    '8191bc1ec3ebfbee352ed14665bbcb3e2b9cc76b8293051a23735cdba032fcb5',
    hash('sha256', $preimage),
    'and so is the domain-prefixed preimage, which is the other half: a moved domain and a moved statement '
    . 'are the same failure to a party holding a certificate'
);
wprism_check_same(
    'vi/Hx5Rtnr6N0JMjk4Bhx9W3SUDFREVkY8zv7H1xyRdEBgYmBnzNblkOtCrf+Lm7UzQ9ew2Y7VG7diNSQL8JAQ==',
    base64_encode($signature),
    'and the Ed25519 signature over that preimage is the same signature, so a certificate minted before this '
    . 'rider verifies after it — measured, not argued'
);
wprism_check(
    sodium_crypto_sign_verify_detached($signature, $preimage, sodium_crypto_sign_publickey($pair)),
    'and it verifies, so the vector above is a live signature rather than three digests that happen to agree'
);

// ===========================================================================
echo "\nPART 5 — G5 condition 7: opening the lane is a POLICY FLIP, not a break\n";
// ===========================================================================

wprism_check_same(
    '',
    $verdictOf('feature_claimed'),
    '§ v3.2\'s growth channel already works at the N+1 engine: a top-level key claimed by a feature the engine '
    . 'IMPLEMENTS is admitted. So opening `package` needs a decision and an `engine_features` value — no '
    . 'format change, no second flag day (§ v3.11 condition 7)'
);
$futureGeneration = $refusal(static function () use ($invoke, $typed, $goodStatement) {
    $statement = $goodStatement;
    $statement['version'] = AdapterCertification::STATEMENT_VERSION + 1;
    $invoke('assertStatementShape', ['acme-widget', $typed($statement), $statement]);
});
wprism_check_same(
    SupersededWireSiteAdapterCertificate::class,
    (string) $futureGeneration['class'],
    'and a statement written in a generation this engine does not implement is withdrawn BY NUMBER and per '
    . 'adapter — so the wire generation that eventually carries `code_digest` degrades one adapter rather '
    . 'than reading as corruption. That is what `version` bought (R-24), and it is why the statement slots '
    . 'can be reserved as refusals today'
);
wprism_check(
    str_contains((string) $futureGeneration['message'], 'wire generation ' . (AdapterCertification::STATEMENT_VERSION + 1)),
    'and the withdrawal names the generation it could not read, which is the difference between a version '
    . 'answer and a corruption answer'
);
$laneSection = preg_match('/^### v3\.11 .*?(?=^### )/ms', $spec, $laneMatch) === 1 ? $laneMatch[0] : '';
wprism_check(
    str_contains($laneSection, 'still refuses with its pinned messages'),
    'G5 condition 7 is still written down in § v3.11 as the thing this suite answers — a gate whose evidence '
    . 'list stopped naming a suite would be a gate opened on faith'
);
wprism_check(
    str_contains($reservationSection, 'regress_v3_reservations.php'),
    'and § v3.10 names THIS file as that evidence, so the flip WP-7.1 makes is an edit to the pins above: '
    . 'visible in a diff, and failing here until someone makes it deliberately'
);
wprism_check(
    str_contains($reservationSection, 'OPENED at gate G4 by WP-5.2')
        && str_contains($laneSection, 'WP-5.2 has now DONE this once'),
    'and the mechanism has been redeemed once already: WP-5.2 opened two of the five slots by editing PART 2b '
    . 'above, which is what turns condition 7 from an argument into a worked example'
);

wprism_check_summary('v3 reserved slots');
