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
 * those four adapters' digests move BY DESIGN, and
 * they post-date the flag day, so their frozen numbers are no longer shipped.
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
 *   - the one pin set that contains none of `core`, `polylang`,
 *     `the-events-calendar`, or `woocommerce` (`duo-agency-cpt-only`) still
 *     holds its untouched frozen manifest_hash,
 *     which is the flip-neutrality control a reviewed manifest edit cannot
 *     reach;
 *   - and each PART additionally asserts that the moved set is EXACTLY the
 *     reviewed one. A fifth adapter moving is a failure, not a re-pin.
 *
 * For those four adapters the across-the-flip measurement is genuinely gone —
 * stated plainly rather than papered over. What still covers them is PART 1's
 * second check, which holds for all 16: every shipped manifest still declares
 * `spec_version` 2. That no-restamp rule is the MECHANISM the flip's
 * neutrality rests on, and `manifest_rows()` folds no `define()` into a digest
 * row, so a re-stamp is the only way the flip could have moved one.
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
 *   PART 5 — the hand-mixed bundle. `agent` and `manifests` travel in one
 *   archive (`Adopt.php:147-150`), so a v3 agent over a v2 manifest library is
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
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Policy;

$repo = dirname(__DIR__, 4);
$manifestDir = $repo . '/manifests';
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
 * freeze: #561's core/TEC work and the Polylang/WooCommerce production-readiness
 * reviews, measured on this tree, adapter by adapter.
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
    'adapters' => ['core', 'polylang', 'the-events-calendar', 'woocommerce'],
    'adapter_digests' => [
        // manifests/core.json: the native rewrite action's declared effect set
        // widened to cover TEC's rewrite-listener option writes and the
        // autoload filters around them.
        'core' => '2d72608ff976c3b050062c126128549f0711a84203ef28f17d594728afb18858',
        // The reviewed Polylang production-readiness port pins its 3.8.x
        // range, expanded authored surface, interpreter, manifest provider,
        // and per-subject disposition.
        'polylang' => '99d82ecc6402fda3a8d651d56ca07ae4a73836947ed5f2ecca11c0df4472e9a4',
        // manifests/the-events-calendar.json rewritten (block_attrs, widgets,
        // interpreter, option_autoload) AND dispositions/the-events-calendar
        // .json promoted experimental -> certified. Both halves are inside the
        // digest row, so one number carries both.
        'the-events-calendar' => 'ae74bedeab559531758ac7a9268cad15ef37568471ceae5cab9353b93519cd78',
        // WooCommerce production readiness extends reviewed evidence, closes
        // exact attachment callback isolation and provider receipt semantics,
        // and binds the canonical mixed-option contract.
        'woocommerce' => 'c36138cad07eeda8f843d3a92fc898d924165b9cd00f724dbc0f033421556e92',
    ],
    'manifest_bytes_sha256' => [
        'core' => 'a2f673cd4107e7b32cc6cfff6e84e7f6aca68b789234cbc8c686458fee4de5b3',
        'polylang' => 'db7130aecf89217cdca8f6591f4481391f61ae84632478f89191bdda74b488d3',
        'the-events-calendar' => '0c72e83a62ba5d461d975fd5380f49eab139d5413edaade07f7f47e396d80729',
        'woocommerce' => 'dccc013287f344f8dd9ee50a7a3f8a50ca08cdcbe8d40f3366320cdd5776a4af',
    ],
    'pin_sets' => [
        'all-16' => 'e31a3a766b8460bffb73af9a98ad68504840a22940ea164ded46ca86363e6783',
        'core+elementor+yoast+contact-form-7' => '52200323db9516a2eb7b5738540534aa58e9115c04fb3ddf0b182ea4089d27f9',
        'core+paid-memberships-pro+code-snippets' => '9d89e59d838e145cbd64c6b172ad5634886bdae6ea027eb8f41e57bcd1b048dc',
        'core+polylang+the-events-calendar' => '1d26c4687a24e143c8a24045ba85b370bd6d3aad11b16898b4f13966ba76e6e6',
        'core+woocommerce+acf' => 'd054fc4ada2b150e020a61d3e519af822efd5d1e8d04e5377bb03a4fb87076a1',
        'core-only' => 'c2a658f6d9f3fa73fc7e74a483aa0476a8909a01d59f3cd07103daefcdb78e6d',
    ],
    // The reviewed claim source is one document per subject, so promoting TEC
    // and certifying Polylang plus WooCommerce move the whole-registry address
    // every host pins.
    'registry_sha256' => '49c84c27e199e198ac4452d52177fd732d7b1c33110e75a3d74a6a463be1a3f8',
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

putenv('DUO_MANIFESTS_DIR=' . $manifestDir);

// ---------------------------------------------------------------------------
echo "\nPART 1 — all 16 adapter digests, across the flip\n";
// ---------------------------------------------------------------------------
$names = array_keys((array) $frozen['adapter_digests']);
sort($names, SORT_STRING);
$policyAll = Policy::load(null, $names);
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
// fourth adapter drifting would satisfy nothing here — it would be a red run
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

// The declared versions, because the digest equality above is a CONSEQUENCE of
// this and not independent evidence for it: had the library been re-stamped,
// every digest would have moved and PART 1 would say so without saying why.
$declared = [];
foreach ($names as $name) {
    $decoded = Canon::decode(Canon::read_file($manifestDir . '/' . $name . '.json'));
    $declared[$decoded['spec_version'] ?? 'absent'] = true;
}
duo_check_same(
    [DUO_SPEC_VERSION - 1 => true],
    $declared,
    'and the REASON, measured: every shipped manifest still declares spec_version ' . (DUO_SPEC_VERSION - 1)
        . ' — the no-restamp rule (§ v3.12), which the acceptance window is what makes possible'
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
    $policy = Policy::load(null, $pins);
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
    ManifestDispositions::load($manifestDir)->sha256(),
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
    $fileHashes[$name] = hash_file('sha256', $manifestDir . '/' . $name . '.json');
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
// are exactly the adapters whose file bytes moved. If those sets ever
// disagreed, a digest would have moved for a reason NOT visible in
// manifests/*.json -- a changed disposition, interpreter, provider or
// regenerator -- and PART 1 alone could not tell the reader which.
duo_check_same(
    $movedNames,
    $movedFiles,
    'and the two sets coincide: every adapter whose digest moved is one whose manifest file moved, so the reviewed '
        . 'edits explain the whole delta with no unexplained interpreter or disposition byte behind it'
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
// `Adopt::install()` tars `agent manifests recovery` as ONE archive and swaps
// it through four atomic journal surfaces, so a site can never observe half of
// the pair. This part assembles the impossible state BY HAND and proves the
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
$platform = Canon::decode(Canon::read_file($manifestDir . '/capabilities/platform.json'));
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
$agentOnly = Canon::decode(Canon::read_file($manifestDir . '/capabilities/platform.json'));
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
    Canon::read_file($manifestDir . '/capabilities/platform.json')
);
$restored = ManifestDispositions::platform_boundary($scratch);
duo_check_same(
    [DUO_AGENT_VERSION, DUO_SPEC_VERSION],
    [$restored['agent_version'] ?? null, $restored['spec_version'] ?? null],
    'THE CONTROL: the identical copy with both members restored loads and reports this agent — so the two '
        . 'refusals above are the mutation and not the scratch tree'
);

duo_check_summary('spec v3 digest neutrality');
