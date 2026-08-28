<?php
/**
 * WP-4.12 — THE FLIP's central invariant: `DUO_SPEC_VERSION` 2 → 3 moved
 * NOTHING that a deployed site holds (spec/repo-format.md § v3.12).
 *
 * WHY THIS SUITE EXISTS, AND WHY IT IS NOT A TAUTOLOGY
 * ---------------------------------------------------
 * The flag day's whole survivability argument is one sentence: because no
 * shipped manifest is re-stamped, `ArtifactPolicyIdentity::manifest_rows()`
 * folds the same bytes it folded yesterday, so every adapter `digest`, every
 * `manifest_hash`, every `site.duo.json` content pin and every compiled
 * artifact still match. A suite that recomputed both sides of that equality
 * would prove nothing — it would hold whatever the bump did to the bytes.
 *
 * So the expected values are FROZEN. `sandbox/tests/fixtures/spec-v3/
 * pre-flag-identity.json` was produced by loading the shipped library through
 * the product path on the PRE-BUMP tree, inside this same change, before the
 * two `define()` lines moved — and it has not been regenerated since. There is
 * deliberately no generator committed beside it: a regenerate button is a way
 * to make this suite green without making the claim true, and the claim is
 * that a specific set of 256-bit numbers did not move on a specific day.
 *
 * THE FIXTURE'S OWN DIGEST IS PINNED HERE AS A LITERAL for the same reason.
 * Editing the fixture to "fix" a red run would then also have to edit a
 * constant in this file, which is a deliberate act a reviewer can see, rather
 * than a file update that looks like housekeeping.
 *
 * WHAT HAPPENS WHEN A REVIEWED CHANGE *DOES* MOVE A DIGEST
 * -------------------------------------------------------
 * The fixture is not regenerated — not even then. #561 (The Events Calendar
 * production-ready) deliberately rewrote `manifests/the-events-calendar.json`
 * and its disposition (experimental -> certified), and widened
 * `manifests/core.json`'s native rewrite action to declare TEC's rewrite
 * listener effects. The reviewed post-flag Polylang production-readiness port
 * likewise rewrote `manifests/polylang.json` and its per-subject disposition;
 * WooCommerce's final review moves its manifest lint/compile declarations,
 * interpreter, and per-subject disposition reason. Under AGENTS.md rule 2
 * those four adapters' digests move BY DESIGN. The adapter-package flag day
 * later preserved every executable byte during the physical move, then
 * deliberately corrected six runtime dependency paths that could no longer
 * resolve from either the source-package or embedded-agent layout. Those five
 * affected adapter digests also move by design, and all of these changes
 * post-date the flag day, so their frozen numbers are no longer shipped.
 *
 * Re-freezing the whole fixture at today's tree was the obvious repair and is
 * the wrong one: it would recompute both sides of the equality, which is the
 * tautology the paragraph above exists to forbid, and it would do it for all
 * 16 adapters to account for 4. So the moves are QUARANTINED instead, as
 * REVIEWED_MOVES below — literals, in this file, next to the fixture's own
 * pinned digest and edited under the same rule. What that buys, measured:
 *
 *   - 12 of the 16 adapter digests are still compared against untouched
 *     pre-flag numbers, so the flag day's claim is still evidenced, not
 *     asserted;
 *   - the one pin set that contains none of `core`, `paid-memberships-pro`,
 *     `polylang`, or `the-events-calendar` (`duo-agency-cpt-only`) still holds its untouched
 *     frozen manifest_hash,
 *     which is the flip-neutrality control a reviewed manifest edit cannot
 *     reach;
 *   - and each PART additionally asserts that the moved set is EXACTLY the
 *     reviewed one. An additional adapter moving is a failure, not a re-pin.
 *
 * For those four adapters the across-the-flip measurement is genuinely gone —
 * stated plainly rather than papered over. What still covers them is PART 1's
 * second check: fifteen manifests still declare `spec_version` 2, while the
 * one deliberate v3 consumer is named. That no-bulk-restamp rule is the
 * MECHANISM the flip's neutrality rests on, and `manifest_rows()` folds no
 * `define()` into a digest row; PMPro's later restamp is therefore isolated
 * from the flag-day move rather than attributed to it.
 *
 * WHAT IS MEASURED
 * ----------------
 *   PART 1 — all 16 adapter digests, read exactly as a repository pin reads
 *   them (`ArtifactPolicyIdentity::resolved_adapters()`), compared to the
 *   frozen list overlaid with REVIEWED_MOVES — and, separately, the set that
 *   moved, which must be the reviewed set exactly.
 *
 *   PART 2 — `manifest_hash` for seven representative pin sets: the whole
 *   library, core alone, the two commonest commercial stacks, an
 *   interpreter-bearing set, a provider/regenerator-bearing set, and the one
 *   excluded regression fixture alone. Per-set rather than only over all 16,
 *   because a compiled artifact binds the hash of THAT SITE's pin set — a
 *   library-wide equality can hold while one subset moves.
 *
 *   PART 3 — the reviewed registry hash, and the shipped manifest FILE bytes.
 *   The file hashes are the upstream half: if a manifest's bytes moved, its
 *   digest moving would be a consequence rather than a mystery, and PART 1
 *   alone could not tell those apart.
 *
 *   PART 4 — what the flip DID move, asserted as loudly as what it did not. A
 *   capability claim embeds the platform boundary, so it MUST have moved; a
 *   suite that only reported the unchanged half would be describing a bump
 *   that did nothing. This is the measurement `duo adapter doctor --migration`
 *   reports per site and the runbook's post-verify step compares.
 *
 *   PART 5 — the hand-mixed bundle. The adapter library travels inside the
 *   atomic `agent` archive, so a v3 agent over a v2 adapter library is
 *   unreachable through the supported path. This part builds one BY HAND and
 *   proves the shipped refusal fires, rather than adding a mechanism to
 *   survive it (AGENTS.md rule 9).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/agent_version.php';
duo_test_define_agent_versions();
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterRegistry.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';

use Duo\ArtifactPolicyIdentity;
use Duo\AdapterLibrary;
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Policy;

$repo = dirname(__DIR__, 4);
$adapterLibrary = AdapterLibrary::fromSourceTree($repo);
$manifestPath = static function (string $name) use ($adapterLibrary): string {
    return $adapterLibrary->package($name)?->manifestPath()
        ?? throw new RuntimeException("shipped adapter package '$name' is absent");
};
$fixturePath = $repo . '/sandbox/tests/fixtures/spec-v3/pre-flag-identity.json';

/**
 * The frozen fixture's own sha256, captured on the pre-bump tree.
 *
 * A literal: it is the tripwire that makes editing the fixture a visible act
 * rather than a quiet one. REVIEWED_MOVES below is the only other literal
 * here, and it exists so that a reviewed change to a shipped manifest is
 * recorded beside this one instead of being absorbed by regenerating that
 * fixture.
 */
const PRE_FLAG_FIXTURE_SHA256 = '05fcb8368979c6e270ecc71cb651680be317a6921d432d677668fed494523239';

/**
 * The reviewed post-flag overlays that have moved shipped identities since the
 * freeze: #561's core/TEC work, the Polylang and WooCommerce production-
 * readiness ports, Yoast's Woo permalink trigger, PMPro's declarative
 * invalidation migration, the manifest-provider runtime migration, and the
 * adapter-package dependency-path correction for the five adapters whose
 * executables invoke WpCliChildProcess. The WooCommerce scheduler provider's
 * stock-WordPress local-cache correction is pinned beside those runtime moves,
 * measured on this tree adapter by adapter.
 *
 * Every number here is an overlay ON TOP OF the frozen fixture, never a
 * replacement for it — the fixture keeps its pre-flag bytes and its pinned
 * digest above. `adapters` is the closure of those reviewed manifest edits,
 * and each PART below asserts that closure is exact, so this constant cannot
 * grow by accident: another name appearing in the observed delta fails the
 * run rather than being absorbed.
 *
 * `pin_sets` carries only the sets whose pins intersect `adapters`.
 * `duo-agency-cpt-only` is deliberately absent — it is the untouched control.
 */
const REVIEWED_MOVES = [
    'adapters' => [
        'code-snippets',
        'core',
        'elementor',
        'ninja-forms',
        'paid-memberships-pro',
        'polylang',
        'the-events-calendar',
        'woocommerce',
        'yoast',
        'yoast-duplicate-post',
    ],
    'adapter_digests' => [
        'code-snippets' => 'f4f235fcbb7349c3254fd91bebb0cc7922e02e113c898611963099ee2a60cb6b',
        // manifests/core.json: the native rewrite action's declared effect set
        // widened to cover TEC's rewrite-listener option writes and the
        // autoload filters around them.
        'core' => '2d72608ff976c3b050062c126128549f0711a84203ef28f17d594728afb18858',
        'elementor' => 'c13aceb84019223e88f292168655d7f435ae2ab92e68cc84ca38602f86b94383',
        'ninja-forms' => '8100c32a2f46e91ad4daae9c80ab61d33092b54d7810e533aedd265d4ae166c9',
        // PMPro deliberately moves to spec v3 to negotiate
        // invalidate-vocabulary/v1, retires its manifest provider, and becomes
        // a declarative adapter. This is a reviewed post-flip restamp, not a
        // cost attributed to the flag-day define change.
        'paid-memberships-pro' => '59e95f6f2089cb7b37787920ae62a9adbc83f6f7b4c673f611aa62fdb8fe2880',
        // The reviewed Polylang production-readiness port pins its 3.8.x
        // range, expanded authored surface, interpreter, manifest provider,
        // and per-subject disposition.
        'polylang' => 'ec697d7e5baa1b2847e16e7aceeacf3942d8a3d73297313f9d50d7128b068f75',
        // manifests/the-events-calendar.json rewritten (block_attrs, widgets,
        // interpreter, option_autoload) AND dispositions/the-events-calendar
        // .json promoted experimental -> certified. Both halves are inside the
        // digest row, so one number carries both.
        'the-events-calendar' => '0a6d67877140db53304d041842f29c7707feab0248ded5cca8a5df992ef2148b',
        'woocommerce' => '8274ba1c78171149bda79b57afa2fa882053a2327f8ed89d81b03e336bc7703b',
        'yoast' => '3edb81748cf3e84923779a74a913f339889a8f9cd430342dabdcfa0dbd3ceae2',
        'yoast-duplicate-post' => '9c17439fc670eebbe216133abbe57dd0e9add20ccf8f1897c2f4445013c65e75',
    ],
    'manifest_bytes_sha256' => [
        'code-snippets' => '563533c51950cf3fb373acd484c5eb0fd54d8f1362169d84bac34fdb51feabb0',
        'core' => 'a2f673cd4107e7b32cc6cfff6e84e7f6aca68b789234cbc8c686458fee4de5b3',
        'elementor' => 'e046946e10607b4ce64bb12904a33eccf45fb0ad8c0e58214f0064f1bdaaf17e',
        'ninja-forms' => 'b56bc0c867350c437198cd5c1de3437f4949fd72b737f77a7ad62dca0acb4e67',
        'paid-memberships-pro' => 'b2a27f37b6f27ad46a8e361144a5a45026b87b6393cb869711f4e455619ecb12',
        'polylang' => 'ada90a0fffd9748c860fd38c8ea475ffe3c09d06baf71b00700f9dd6e029d39a',
        'the-events-calendar' => '1312ca9ee33663535a7dcde57a2cdfbadc616a9798a6d1e7f20a1fea59b87bde',
        'woocommerce' => '2119395decc9953298cafe44e5396cfef09a1c2ce08ed64b9db1cefacbfcd1fb',
        'yoast' => 'd9bbe421a0608aef835d849bd2af460df4c3492728e8da923ce4537ce5e280c4',
        'yoast-duplicate-post' => '33989cb589aa411e2a440ebd2778366f01e3cb3b0ff7ebfb5414b8799c008071',
    ],
    // The physical manifests/ -> adapter-packages/ move preserved these
    // executable bytes first. Runtime corrections are pinned independently
    // here instead of being attributed to relocation or hidden in a digest.
    'runtime_bytes_sha256' => [
        'adapter-packages/elementor/package/runtime/providers/elementor-css.php' => 'f1fa9fddc0c9cefa8088678f80b08f1a2b8b5b58c48c6d12dda49bb236e71901',
        'adapter-packages/ninja-forms/package/runtime/providers/ninja-forms-form-cache.php' => 'c863e9c32a96e4e8f0858fb3be4fb1e092c2faec1bd9b0a7163b5417ade6d09c',
        'adapter-packages/polylang/package/runtime/providers/polylang-nav-menus.php' => '628e845f3c1f4e121a16afcdfdddff05bf95833f3a2b35ae859675b90f5c5cff',
        'adapter-packages/woocommerce/package/runtime/interpreters/woocommerce.php' => 'f2ba92178f6fa83b0eccdd376c466c8b9a6c919859184aac33deea375f40d343',
        'adapter-packages/woocommerce/package/runtime/providers/woocommerce-hierarchy-lookups.php' => '680d4e9b084e684b0046b7487ca320cabb7087624c5c93034e40fdeed4d5efaf',
        'adapter-packages/woocommerce/package/runtime/providers/woocommerce-scheduler-settings.php' => '7eb08c32c8c2624e67a3088f1bea3714b2f8eadf23954acd82f1ad73403cca95',
        'adapter-packages/yoast/package/runtime/providers/yoast-index.php' => 'ba60957827b02fe38db7655b7c3f42ddb88079541e7049b878a39107a37aada7',
    ],
    'pin_sets' => [
        'all-16' => '0f2225d6d765789375e9a134479447727236c7fdaeadcd8d10df12ed7186093f',
        'core+elementor+yoast+contact-form-7' => 'baa5497388a9fcdd129c5fadb4bf372227002c1df3f22023a6d0723bdfeafee0',
        'core+paid-memberships-pro+code-snippets' => '722687250167adba48337eca3487adf156381086eaffc3fb3b564c2860b4ee28',
        'core+polylang+the-events-calendar' => 'd109bdccf8d0e9d8cc14c77b860d417b54380c3c402dbe002ada36f68a3072a5',
        'core+woocommerce+acf' => '2a1ef2f523c068706051af0abeb3746427340eb485b7a4d54039316f3674ea19',
        'core-only' => 'c2a658f6d9f3fa73fc7e74a483aa0476a8909a01d59f3cd07103daefcdb78e6d',
    ],
    // The reviewed claim source is one document per subject, so promoting TEC
    // and certifying Polylang plus WooCommerce move the whole-registry address
    // every host pins.
    'registry_sha256' => 'a9b7fdbb8d7c62e78ac8ca1c10a395aa0dc54079fb54cef2809c71babf395f2e',
];

duo_check(is_file($fixturePath), 'the frozen pre-flag identity fixture is in the tree');
duo_check_same(
    PRE_FLAG_FIXTURE_SHA256,
    hash_file('sha256', $fixturePath),
    'and it is the document captured before the defines moved — its own digest is pinned here, so editing it '
        . 'to make this suite green requires editing this file too'
);
$frozen = Canon::decode(Canon::read_file($fixturePath));
duo_check_same('duo-pre-flag-identity/v1', $frozen['format'] ?? null, 'the fixture declares its own format');
duo_check_same(
    DUO_SPEC_VERSION - 1,
    $frozen['captured_at_spec_version'] ?? null,
    'and it records the spec version it was captured at — ' . (DUO_SPEC_VERSION - 1) . ', one below this '
        . 'engine, which is what makes the comparison below a measurement across the flip and not within it'
);

// ---------------------------------------------------------------------------
echo "\nPART 1 — all 16 adapter digests, across the flip\n";
// ---------------------------------------------------------------------------
$names = array_keys((array) $frozen['adapter_digests']);
sort($names, SORT_STRING);
$policyAll = Policy::load(null, $names, adapterLibrary: $adapterLibrary);
$observed = [];
foreach (ArtifactPolicyIdentity::resolved_adapters($policyAll) as $row) {
    $observed[(string) $row['name']] = (string) $row['digest'];
}
ksort($observed, SORT_STRING);
$expectedDigests = (array) $frozen['adapter_digests'];
foreach (REVIEWED_MOVES['adapter_digests'] as $movedName => $movedDigest) {
    $expectedDigests[$movedName] = $movedDigest;
}
ksort($expectedDigests, SORT_STRING);
$unmovedCount = count($names) - count(REVIEWED_MOVES['adapters']);
duo_check_same(
    $expectedDigests,
    $observed,
    $unmovedCount . ' OF THE ' . count($names) . ' SHIPPED ADAPTER DIGESTS ARE BYTE-IDENTICAL to the pre-flag '
        . 'tree — the number in every site.duo.json content pin and every certificate, unmoved by '
        . 'DUO_SPEC_VERSION 2 -> 3; the other ' . count(REVIEWED_MOVES['adapters']) . ' carry reviewed post-flag '
        . 'manifest edits and are pinned as literals in this file'
);
// The other half of the same claim, and the one that keeps REVIEWED_MOVES
// honest: WHICH digests moved, not merely that the overlay reproduces them. A
// sixth adapter drifting would satisfy nothing here — it would be a red run
// naming itself, which is what an accidental edit under manifests/ has to be.
$movedNames = [];
foreach ($observed as $name => $digest) {
    if ((string) (((array) $frozen['adapter_digests'])[$name] ?? '') !== $digest) {
        $movedNames[] = $name;
    }
}
duo_check_same(
    REVIEWED_MOVES['adapters'],
    $movedNames,
    'and the moved set is EXACTLY the reviewed one (' . implode(', ', REVIEWED_MOVES['adapters']) . ') — every '
        . 'other adapter still holds a number captured before the defines moved, so the flag day\'s claim is '
        . 'still evidence rather than an assertion about a re-frozen file'
);

// The flag-day rule is preserved as history while every deliberate post-flag
// migration is named exactly. Each consumer pays its own digest/pin change;
// the remaining seven prove the bump itself did not bulk-restamp the library.
$declared = [];
foreach ($names as $name) {
    $decoded = Canon::decode(Canon::read_file($manifestPath($name)));
    $declared[$name] = $decoded['spec_version'] ?? 'absent';
}
duo_check_same(
    [
        'code-snippets',
        'elementor',
        'ninja-forms',
        'paid-memberships-pro',
        'polylang',
        'the-events-calendar',
        'woocommerce',
        'yoast',
        'yoast-duplicate-post',
    ],
    array_keys(array_filter($declared, static fn($version): bool => $version === DUO_SPEC_VERSION)),
    'and exactly the feature-migrated manifests are deliberately stamped to the current spec'
);
duo_check_same(
    7,
    count(array_filter($declared, static fn($version): bool => $version === DUO_SPEC_VERSION - 1)),
    'while the other seven manifests retain the pre-flag version, preserving the no-bulk-restamp evidence'
);

// ---------------------------------------------------------------------------
echo "\nPART 2 — manifest_hash for seven representative pin sets\n";
// ---------------------------------------------------------------------------
// A compiled artifact binds the hash of ONE SITE's pin set. Library-wide
// equality can hold while a subset moves — a row appearing, disappearing or
// re-ordering inside `manifest_rows()` for some pin shapes and not others — so
// each shape is compared on its own.
$disjointSets = [];
foreach ((array) $frozen['pin_sets'] as $label => $expected) {
    $pins = (array) $expected['pins'];
    $policy = Policy::load(null, $pins, adapterLibrary: $adapterLibrary);
    $touched = array_values(array_intersect($pins, REVIEWED_MOVES['adapters']));
    if ($touched === []) {
        $disjointSets[] = $label;
        duo_check_same(
            (string) $expected['manifest_hash'],
            ArtifactPolicyIdentity::manifest_hash($policy),
            "manifest_hash for pin set '$label' (" . count($pins) . ' pinned) is unmoved — the number every '
                . 'compiled artifact and every recovery checkpoint on such a site binds. THE CONTROL: this set '
                . 'pins no adapter the reviewed overlays touch, so its pre-flag number is still the shipped one'
        );
        continue;
    }
    // A reviewed edit to any pinned manifest re-hashes the whole row list, so
    // a set is expected to move if and only if it pins a moved adapter. Both
    // directions matter: an untouched set moving, or a touched set NOT moving,
    // would each mean manifest_rows() no longer folds what rule 2 says it does.
    duo_check(
        isset(REVIEWED_MOVES['pin_sets'][$label]),
        "pin set '$label' pins " . implode('/', $touched) . ', so the reviewed overlay must have moved its manifest_hash and '
            . 'this file must carry the re-pin'
    );
    duo_check_same(
        (string) (REVIEWED_MOVES['pin_sets'][$label] ?? ''),
        ArtifactPolicyIdentity::manifest_hash($policy),
        "manifest_hash for pin set '$label' (" . count($pins) . ' pinned, ' . implode('/', $touched)
            . ' reviewed-moved) is the re-pinned number every compiled artifact and every recovery checkpoint '
            . 'on such a site now binds — and every site holding the old one refuses with '
            . 'compiled_artifact_manifest_mismatch until it is recompiled, which is the reviewed change\'s intended cost'
    );
}
duo_check_same(
    ['duo-agency-cpt-only'],
    $disjointSets,
    'and exactly one of the seven representative pin sets is disjoint from the reviewed overlays — so the control above is a '
        . 'real measurement of a real pin shape, not an empty loop that would pass if every set had moved'
);

// ---------------------------------------------------------------------------
echo "\nPART 3 — the reviewed registry, and the manifest file bytes upstream of every digest\n";
// ---------------------------------------------------------------------------
// The flip does not touch the reviewed claim source; #561 promotes TEC and
// the post-flag ports certify Polylang and WooCommerce. The number moved for
// reasons written in their per-subject documents, and re-pinning it here is
// the reviewed act — not evidence that the flag day disturbed a host contract.
duo_check_same(
    REVIEWED_MOVES['registry_sha256'],
    ManifestDispositions::load_library($adapterLibrary)->sha256(),
    'registry_sha256 — the whole-document hash every host contract pins (ContractProjection) — carries #561\'s '
        . 'experimental -> certified promotion of the-events-calendar plus Polylang and WooCommerce review; it '
        . 'is pinned here as a literal, so the next claim edit is a visible re-pin rather than a silent one'
);
duo_check(
    REVIEWED_MOVES['registry_sha256'] !== (string) $frozen['registry_sha256'],
    '...and it is genuinely a different number from the pre-flag one, which is what makes the line above a '
        . 're-pin worth reviewing rather than a restatement of the frozen fixture'
);
$fileHashes = [];
$movedFiles = [];
foreach ((array) $frozen['manifest_bytes_sha256'] as $name => $frozenBytes) {
    $fileHashes[$name] = hash_file('sha256', $manifestPath($name));
    if ($fileHashes[$name] !== (string) $frozenBytes) {
        $movedFiles[] = $name;
    }
}
ksort($fileHashes, SORT_STRING);
$expectedBytes = (array) $frozen['manifest_bytes_sha256'];
foreach (REVIEWED_MOVES['manifest_bytes_sha256'] as $movedName => $movedBytes) {
    $expectedBytes[$movedName] = $movedBytes;
}
ksort($expectedBytes, SORT_STRING);
duo_check_same(
    $expectedBytes,
    $fileHashes,
    'and the shipped manifest FILE bytes are the upstream fact behind every digest above: the same reviewed set moved, '
        . 'the other ' . $unmovedCount . ' did not move a byte, so a moved digest could never be mistaken for '
        . 'an edit nobody meant to make (AGENTS.md rule 2)'
);
// The causal link stated as its own assertion: the adapters whose digest moved
// are exactly the adapters whose manifest bytes moved. Runtime changes within
// that same set are pinned independently below; a runtime change for any other
// adapter would still widen $movedNames and fail this equality.
duo_check_same(
    $movedNames,
    $movedFiles,
    'and the two sets coincide: every adapter whose digest moved is one whose manifest file moved; executable changes '
        . 'inside that reviewed set are pinned separately below rather than hiding an additional adapter delta'
);
$runtimeHashes = [];
foreach (REVIEWED_MOVES['runtime_bytes_sha256'] as $path => $expectedHash) {
    $runtimeHashes[$path] = hash_file('sha256', $repo . '/' . $path);
}
duo_check_same(
    REVIEWED_MOVES['runtime_bytes_sha256'],
    $runtimeHashes,
    'and the seven reviewed package-runtime corrections are individually byte-pinned — their adapter digest moves '
        . 'are an explicit re-pin cost, not a side effect attributed to the physical directory move'
);

// ---------------------------------------------------------------------------
echo "\nPART 4 — what the flip DID move, stated as loudly as what it did not\n";
// ---------------------------------------------------------------------------
// A capability claim carries the platform boundary verbatim
// (ManifestDispositions::claim_from_disposition()), and the boundary restates
// both defines. So this MUST have moved, on every adapter, on every site —
// including a site under full digest neutrality. It is the finding
// `duo adapter doctor --migration` reports as (a) and the reason a compiled
// artifact's `artifact_hash` re-projects even where `manifest_hash` does not.
$claimAgents = [];
foreach (ArtifactPolicyIdentity::resolved_adapters($policyAll) as $row) {
    $claimAgents[(string) ($row['capability']['platform']['agent_version'] ?? 'absent')] = true;
}
duo_check_same(
    [DUO_AGENT_VERSION => true],
    $claimAgents,
    'every resolved adapter row now carries agent_version ' . DUO_AGENT_VERSION . ' inside its capability '
        . 'claim — so artifact_hash moves fleet-wide even though manifest_hash does not, which is exactly '
        . 'what the preflight predicts and the runbook post-verifies'
);
$claimSpecs = [];
foreach (ArtifactPolicyIdentity::resolved_adapters($policyAll) as $row) {
    $claimSpecs[(string) ($row['capability']['platform']['spec_version'] ?? 'absent')] = true;
}
duo_check_same(
    [(string) DUO_SPEC_VERSION => true],
    $claimSpecs,
    '...and spec_version ' . DUO_SPEC_VERSION . ' beside it, which is the member inside every signed '
        . 'statement.platform and therefore the reason every pre-flag certificate withdraws (§ v3.12)'
);

// ---------------------------------------------------------------------------
echo "\nPART 5 — the hand-mixed bundle: a v3 agent over a v2 manifest library\n";
// ---------------------------------------------------------------------------
// `Adopt::install()` embeds the adapter library under `agent` and swaps the
// release through four atomic journal surfaces, so a site can never observe
// half of the pair. This part assembles the impossible state BY HAND and proves the
// shipped refusal is what an operator meets — the alternative would be a
// compat shim for a state the product cannot produce (AGENTS.md rule 9).
$scratch = $repo . '/sandbox/tmp/spec-v3-mixed';
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeTree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
};
$removeTree($scratch);
register_shutdown_function(static function () use ($removeTree, $scratch): void {
    if (duo_check_failed() === 0) {
        $removeTree($scratch);
    }
});
if (!mkdir($scratch . '/capabilities', 0777, true) && !is_dir($scratch . '/capabilities')) {
    throw new RuntimeException("cannot create $scratch");
}

// The library is the SHIPPED one with exactly two members reverted: the pair
// AGENTS.md rule 8 binds to the defines. Everything else — every compatibility
// axis, every note — is copied byte for byte, so the refusal below can only be
// about the version disagreement.
$platform = Canon::decode(Canon::read_file($adapterLibrary->platformBoundaryPath()));
$platform['platform']['spec_version'] = DUO_SPEC_VERSION - 1;
$platform['platform']['agent_version'] = '0.5.0';
Canon::write_file($scratch . '/capabilities/platform.json', Canon::encode($platform));

$mixed = null;
try {
    ManifestDispositions::platform_boundary($scratch);
} catch (\Throwable $t) {
    $mixed = $t->getMessage();
}
duo_check(
    is_string($mixed) && str_contains($mixed, 'platform version disagrees with the loaded agent'),
    'A HAND-MIXED BUNDLE REFUSES, in the shipped sentence: a v' . DUO_SPEC_VERSION . ' agent reading a '
        . 'v' . (DUO_SPEC_VERSION - 1) . ' platform.json throws "platform version disagrees with the loaded '
        . 'agent" before any claim is projected from it'
);
duo_check_detail('mixed-bundle refusal: ' . (string) $mixed);
duo_check(
    is_string($mixed) && str_contains($mixed, $scratch . '/capabilities/platform.json'),
    '...naming the exact document that disagrees, because an operator holding a half-swapped bundle needs to '
        . 'know which half'
);

// The same refusal from the OTHER direction of the same equality: a library
// whose spec_version agrees but whose agent_version does not. Both members are
// checked, and a suite that only moved one would leave half the gate unproven.
$agentOnly = Canon::decode(Canon::read_file($adapterLibrary->platformBoundaryPath()));
$agentOnly['platform']['agent_version'] = '0.5.0';
Canon::write_file($scratch . '/capabilities/platform.json', Canon::encode($agentOnly));
$agentMixed = null;
try {
    ManifestDispositions::platform_boundary($scratch);
} catch (\Throwable $t) {
    $agentMixed = $t->getMessage();
}
duo_check(
    is_string($agentMixed) && str_contains($agentMixed, 'platform version disagrees with the loaded agent'),
    '...and the agent_version half of the pair refuses identically, so rule 8 is enforced on BOTH members '
        . 'rather than on the one a reader happens to check'
);

// And the control that makes the two refusals above evidence rather than a
// property of the scratch directory: the same copy, unmutated, LOADS.
Canon::write_file(
    $scratch . '/capabilities/platform.json',
    Canon::read_file($adapterLibrary->platformBoundaryPath())
);
$restored = ManifestDispositions::platform_boundary($scratch);
duo_check_same(
    [DUO_AGENT_VERSION, DUO_SPEC_VERSION],
    [$restored['agent_version'] ?? null, $restored['spec_version'] ?? null],
    'THE CONTROL: the identical copy with both members restored loads and reports this agent — so the two '
        . 'refusals above are the mutation and not the scratch tree'
);

duo_check_summary('spec v3 digest neutrality');
