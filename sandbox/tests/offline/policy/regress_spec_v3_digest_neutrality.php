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
 * WHAT IS MEASURED
 * ----------------
 *   PART 1 — all 16 adapter digests, read exactly as a repository pin reads
 *   them (`ArtifactPolicyIdentity::resolved_adapters()`), compared to the
 *   frozen list.
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
 * A literal, and the only literal in this file: it is the tripwire that makes
 * editing the fixture a visible act rather than a quiet one.
 */
const PRE_FLAG_FIXTURE_SHA256 = '05fcb8368979c6e270ecc71cb651680be317a6921d432d677668fed494523239';

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
duo_check_same(
    (array) $frozen['adapter_digests'],
    $observed,
    'ALL ' . count($names) . ' SHIPPED ADAPTER DIGESTS ARE BYTE-IDENTICAL to the pre-flag tree — the number in '
        . 'every site.duo.json content pin and every certificate, unmoved by DUO_SPEC_VERSION 2 -> 3'
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
foreach ((array) $frozen['pin_sets'] as $label => $expected) {
    $pins = (array) $expected['pins'];
    $policy = Policy::load(null, $pins);
    duo_check_same(
        (string) $expected['manifest_hash'],
        ArtifactPolicyIdentity::manifest_hash($policy),
        "manifest_hash for pin set '$label' (" . count($pins) . ' pinned) is unmoved — the number every '
            . 'compiled artifact and every recovery checkpoint on such a site binds'
    );
}

// ---------------------------------------------------------------------------
echo "\nPART 3 — the reviewed registry, and the manifest file bytes upstream of every digest\n";
// ---------------------------------------------------------------------------
duo_check_same(
    (string) $frozen['registry_sha256'],
    ManifestDispositions::load($manifestDir)->sha256(),
    'registry_sha256 is unmoved: the whole-document hash every host contract pins (ContractProjection) '
        . 'reads the reviewed claim source, which the flip does not touch'
);
$fileHashes = [];
foreach ((array) $frozen['manifest_bytes_sha256'] as $name => $_) {
    $fileHashes[$name] = hash_file('sha256', $manifestDir . '/' . $name . '.json');
}
ksort($fileHashes, SORT_STRING);
duo_check_same(
    (array) $frozen['manifest_bytes_sha256'],
    $fileHashes,
    'and not one shipped manifest FILE moved a byte — the upstream fact, so a moved digest above could never '
        . 'be mistaken for an edit nobody meant to make (AGENTS.md rule 2)'
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
