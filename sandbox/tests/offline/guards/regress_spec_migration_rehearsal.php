<?php
/**
 * The flag-day rehearsal: a synthetic fleet driven across an agent transition,
 * offline, with every enumerated platform-move gate accounted for by name.
 *
 * WHAT THIS SUITE ANSWERS
 * -----------------------
 * The migration this program is building toward moves two files together — the
 * two `define()` lines in `agent/duo.php` and
 * `manifests/capabilities/platform.json`, which restates them (AGENTS.md rule
 * 8). The plan's central engineering claim is that such a bump is DIGEST-
 * NEUTRAL: no adapter digest, no `manifest_hash`, no content pin moves, so
 * pins, compiled artifacts, scope contracts, identity sidecars and recovery
 * checkpoints all survive and rollback is a bundle swap rather than a
 * migration. That claim was an argument. This suite measures it, on nine
 * synthetic site repositories whose pin shapes are the ones real sites carry,
 * driving one estate through four child processes of
 * `spec_migration_estate.php` — build at state A, observe at A, observe at B,
 * observe at A again — because a state is a pair of `define()`s and a PHP
 * process can hold one of those pairs (that file's header states why).
 *
 * THE ANTI-FALSE-GREEN DISCIPLINE, WHICH IS THE POINT
 * --------------------------------------------------
 * A rehearsal that quietly exercises six gates and reports green is worse than
 * no rehearsal, because it converts an untested path into a claim. So this
 * suite ENUMERATES `tools/platform-move-gates.json` — WP-0.6's reviewed
 * register of every site a platform, manifest or authority move can trip — and
 * prints one row per gate with verdict `gate`. A gate the estate drove is
 * EXERCISED and carries the entry point and the engine's own sentence. A gate
 * it did not drive is a NAMED GAP: it must appear in REHEARSAL_GAPS below with
 * a reason a reviewer can check, and the ratchet runs both ways — a gap that
 * starts being exercised fails the suite until it is removed from the list, and
 * a gate that stops being exercised fails until it is either fixed or written
 * down. Same shape as `duo manifest-validate` stamping `deferred` rows on every
 * run: the unproven half is visible, not absent.
 *
 * WP-1.1's BRICK, REPRODUCED AS A RECORDED EXPECTATION
 * ---------------------------------------------------
 * WP-1.1 is merged, so the pre-fix engine cannot be observed — and checking out
 * the commit that had it is not a test, it is archaeology. What the estate can
 * observe on the identical fixture is every input the pre-fix routing consumed:
 * the exception the platform comparison raises, and whether the pre-fix catch
 * set (one class, `SupersededSiteAdapterCertificate`, AdapterSources.php:1122)
 * would have caught it. Anything else escaped that closure, `refuse()` re-threw
 * at SCOPE_SOURCE (:2028-2036), and `Policy::load()` propagated it uncaught
 * (Policy.php:400) — the whole site source refused. REHEARSAL_PREFIX_REFUSAL
 * below is the recorded artifact; the suite asserts the message still matches
 * it byte for byte, that the pre-fix catch set still would not have caught it,
 * and that the site nevertheless loads today with one claim withdrawn. The
 * forged-companion control proves the refusal channel is still live rather than
 * assumed.
 *
 * WHAT THE REHEARSAL MEASURED, AND WHICH PART OF THE PLAN IT CORRECTS
 * ------------------------------------------------------------------
 * Recorded as assertions below, not as prose:
 *   - `manifest_hash`, `site_hash` and every SHIPPED adapter digest are
 *     byte-identical across the transition. Content pins hold; the plan's
 *     "zero pin movements" is confirmed for shipped adapters.
 *   - `artifact_hash` moves for EVERY site, including sites that pin nothing
 *     but shipped adapters, because each resolved adapter row carries its
 *     capability claim and that claim embeds the platform boundary. The
 *     compiled artifact still VERIFIES (`read_artifact()` compares site_hash,
 *     manifest_hash, effects and code — never artifact_hash), but everything
 *     that PINS artifact_hash does not: the scope contract's
 *     `source.artifact_hash` refuses re-association. The plan's deployed-sites
 *     section says scope contracts do not move. On this measurement they do,
 *     and the correct remedy is a re-projection verb, not a widened comparison.
 *   - A site holding a CERTIFIED SITE ADAPTER used to move that adapter's
 *     digest, and therefore its own `manifest_hash` and `revision_hash`,
 *     because the withdrawal changed the certificate-derived digest; its held
 *     compiled artifact then refused with `compiled_artifact_manifest_mismatch`
 *     and had to be recompiled. WP-4.7 (spec/repo-format.md § v3.6) ended that:
 *     a certificate binds the exercised compatibility CELLS rather than the
 *     whole platform record, and this release moves none of them, so all three
 *     certificate-holding sites are now inside the digest-neutral cohort and
 *     their artifacts still verify. WP-1.1's `source:"site"` + uncertified pin
 *     concession (PinResolver.php:206) is still what keeps such a site off the
 *     rocks WHEN a withdrawal does happen, and the withdrawal is rehearsed
 *     against a boundary that moved a bound cell rather than assumed away.
 *   - Zero unloadable sites: every site that loaded before the bump loads after
 *     it, including the promoted site that verifies only from its frozen
 *     snapshot.
 *   - Forward-then-back: the estate observed at state A after a full pass at
 *     state B is byte-identical to the estate observed before it — digests,
 *     pins, certificate verification, compiled artifact, frozen snapshot. The
 *     one thing that is re-minted rather than restored is a certificate signed
 *     WHILE at B, which is withdrawn again on rollback and re-certified by the
 *     same command that minted it.
 *
 * SCOPE LIMIT, STATED RATHER THAN IMPLIED. State B moves `DUO_AGENT_VERSION`
 * and `platform.json`; it does NOT move `DUO_SPEC_VERSION`. Until WP-4.2 that
 * limit was forced: `AdapterContractGrammar` was exact equality, so a v3 agent
 * refused every v2 manifest by name and EVERY site in this estate would have
 * been unloadable — which was not a rehearsal finding but the reason the
 * acceptance window was the one rider that could not be cut. The window has
 * since shipped ({N-1, N}, spec/repo-format.md § v3.1; pinned at both spec eras
 * by sandbox/tests/offline/policy/regress_spec_window.php), so the blocker is
 * gone and the limit is now a SCOPE choice: moving the spec half of state B is
 * the flip itself and belongs to WP-4.12, together with the `platform.json`
 * restatement that AGENTS.md rule 8 binds to it. When that rider lands, state B
 * gains the spec half and the same estate answers the same question.
 */
declare(strict_types=1);

// From offline/guards/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
$driver = __DIR__ . '/spec_migration_estate.php';

/**
 * THE RECORDED PRE-FIX ARTIFACT.
 *
 * The refusal an operator saw on a site holding a certified site adapter when
 * the agent under it moved, before WP-1.1: `verifyCertificate()` threw this
 * message as a bare RuntimeException, `scan_site_source()` did not catch it,
 * and the whole site source refused — taking every command on the site with it,
 * including the `duo adapter certify --pin` that repairs it. Captured from
 * WP-1.1's own suite, which drives the identical condition
 * (`sandbox/tests/offline/adapter/regress_site_adapter_certification.php:1863`
 * and the case (d) assertions below it); `<name>` is this estate's adapter.
 */
const REHEARSAL_PREFIX_REFUSAL =
    "duo: site adapter '<name>' certification binds compatibility axis 'database', whose exercised cells the "
    . 'agent-owned platform boundary now states differently';

/**
 * The reviewed gaps: register gates this estate does NOT exercise, each with
 * the mechanism that puts it out of reach and where it IS exercised instead.
 *
 * This list is a ratchet, not a mute button. The suite refuses a gap that turns
 * out to be exercised (delete the row), a gap naming something the register
 * does not carry as a gate (fix the name), and a register gate that is neither
 * exercised nor listed (drive it, or write down why it cannot be driven).
 */
const REHEARSAL_GAPS = [
    // WP-1.5's `duo adapter doctor --migration`. These four gates CONSUME this
    // estate rather than living inside it: the preflight predicts what a bump
    // will move for one site, and the suite that drives it —
    // sandbox/tests/offline/cli/regress_migration_preflight.php — builds THIS
    // estate with THIS driver, observes it at both states, and then asserts
    // that the preflight's predicted invalidation set equals the movement set
    // measured here, per site. Driving them from inside the estate as well
    // would be the same command exercised twice under two names, and would put
    // the cross-check's own subject inside the fixture it is checked against.
    'cli/src/Adapter/MigrationPreflight.php::certificates' =>
        'the preflight\'s certificate half, which classifies the refusal AdapterCertification::verifyCertificate '
        . 'raises (an anchor gate this estate DOES exercise) into predicted invalidation rows. Exercised by '
        . 'sandbox/tests/offline/cli/regress_migration_preflight.php, which drives it against this estate.',
    'cli/src/Adapter/MigrationPreflight.php::identity' =>
        'the held-versus-target comparison of the four identity values. Its inputs are this estate\'s own '
        . 'holdings; exercised by sandbox/tests/offline/cli/regress_migration_preflight.php.',
    'cli/src/Adapter/MigrationPreflight.php::report' =>
        'where the preflight resolves its own status (refused/unclassified/moves/ok). Exercised by '
        . 'sandbox/tests/offline/cli/regress_migration_preflight.php, including the two refuse-to-classify '
        . 'fixtures that prove `ok` is unreachable while a shape is unclassified.',
    'cli/src/Adapter/MigrationPreflight.php::snapshot' =>
        'the preflight\'s frozen half. This estate drives the frozen path through Policy::from_snapshot() '
        . 'directly (the promoted-frozen site above); the preflight\'s reading of it is exercised by '
        . 'sandbox/tests/offline/cli/regress_migration_preflight.php.',
    'agent/src/Policy/Policy.php::assert_supported_platform' =>
        'runs PlatformCompatibility::assert_supported() only against a REAL loaded target (ABSPATH + WPINC + '
        . 'get_bloginfo all defined), so an offline estate cannot reach it by construction — that guard is the '
        . 'register\'s own note. Its shape half, PlatformCompatibility::assert_boundary_shape, IS exercised here '
        . 'through assert_supported()\'s injected-facts argument.',
    'agent/src/Policy/ManifestDispositions.php::narrowed_environment' =>
        'WP-4.6\'s narrowing refusal is reachable only from a manifest declaring BOTH `spec_version: 3` and an '
        . '`environment` block, and this estate is stamped v2 end to end by construction — state B moves '
        . 'DUO_AGENT_VERSION and platform.json, never DUO_SPEC_VERSION (the scope limit in this file\'s header), '
        . 'and no shipped or fixture manifest here declares the channel. Its inert half IS driven by every pass: '
        . 'the estate\'s claims are projected through this function at both states and their environment_assumptions '
        . 'are part of the digests compared across the transition. The refusal itself is exercised by '
        . 'sandbox/tests/offline/policy/regress_adapter_environment_narrowing.php, on all four narrowable axes. '
        . 'When WP-4.2\'s acceptance window ships and this estate gains the spec half, this gap should close.',
    'agent/src/Promotion/Deploy.php::run' =>
        'promotion needs a live target: a promotion lease, the ledger and an apply session. The manifest-identity '
        . 'refusal it surfaces is CompiledArtifactReader::read_artifact()\'s, which this estate drives directly '
        . 'through the artifact-drift control.',
    'agent/src/Repository/IdentityBackup.php::restore' =>
        'its first statement is Ledger::ensure(), which needs $wpdb, so the manifest_hash comparison is '
        . 'unreachable without a database. The estate holds the three values that comparison makes '
        . '(manifest_hash, site_hash, repository_revision) and re-checks them at both states instead. NOTE: no '
        . 'offline suite drives IdentityBackup::restore() at all today — regress_repository_identity_registry.php '
        . 'reaches only assert_embedded_uuid(), by reflection — so this ANCHOR gate has no offline coverage, '
        . 'which is a finding this enumeration exists to surface rather than a fact about the estate.',
    'agent/src/Code/CodeCompatibility.php::assert_source' =>
        'the refusal fires from the code STAGING path (Code.php:811) and needs a code-half repository — a code '
        . 'lock plus staged components — which this state-only estate does not have. Its diagnostics half is '
        . 'exercised by sandbox/tests/offline/code-half/regress_code_compatibility.sh; the refusal sentence '
        . 'itself ("no target code was staged") appears in no suite in the corpus, which is a second finding of '
        . 'this enumeration rather than a fact about the estate.',
    'cli/src/Adapter/AdapterProposals.php::declaredPair' =>
        'host-side, and its subject is a manifest LIBRARY whose version_range and dispositions restatement '
        . 'DISAGREE — a library ManifestDispositions::validate_entry() refuses, so it can never be an estate any '
        . 'Policy::load() here drives. Exercised by sandbox/tests/offline/adapter/regress_boundary_proposals.php '
        . '(the declared_pair_disagrees case).',
    'cli/src/Adapter/AdapterProposals.php::proposedEdits' =>
        'reached only after a bisection over a recorded release list and probe-outcome ledger runs to completion, '
        . 'and this estate holds no ledger — the state tree is sites and manifest libraries. Exercised by '
        . 'sandbox/tests/offline/adapter/regress_boundary_proposals.php, which additionally proves the pair it '
        . 'emits against the real ManifestDispositions::assert_entry().',
    'cli/src/Refresh/RefreshPlan.php::assertProductionCodeMatches' =>
        'needs a git production ref and a refresh plan built against it. Exercised by '
        . 'sandbox/tests/offline/refresh/regress_refresh_orchestration.php.',
    // WP-4.8's four authority-record-v2 gates. Every authorities document this
    // estate builds is a well-formed `duo-adapter-authorities/v1` one — which
    // is the point of the estate, since the flag day must not move a v1 byte —
    // so none of the four v2 rules has a subject here. They are exercised, rule
    // by rule and against a v1 CONTROL that proves each one is gated, by
    // sandbox/tests/offline/adapter/regress_authority_record_v2.php.
    'agent/src/Adapter/AdapterCertification.php::validateAuthorityRecord' =>
        'the record grammar\'s own refusals. This estate drives it on every load (authorityKeys() calls it for '
        . 'every key in every root it builds) but never REFUSES through it: its authority probes corrupt the '
        . 'document format and the key map, which authorityKeys() answers first. Its two closed key sets and '
        . 'the by-version refusal are exercised by '
        . 'sandbox/tests/offline/adapter/regress_authority_record_v2.php.',
    'agent/src/Adapter/AdapterCertification.php::assertKeyIdBindsKeyMaterial' =>
        'reads only a record that declared `record_version: 2`, and this estate has none by construction. '
        . 'Exercised — squatted id, swapped key material, and the producer-side refusal — by '
        . 'sandbox/tests/offline/adapter/regress_authority_record_v2.php.',
    'agent/src/Adapter/AdapterCertification.php::assertAuthoritiesEnvelope' =>
        'unreachable for a v1 document, which carries no envelope signature at all. Exercised — unsigned, '
        . 'tampered, appended-to, foreign-signer and revoked-signer — by '
        . 'sandbox/tests/offline/adapter/regress_authority_record_v2.php.',
    'agent/src/Adapter/AdapterCertification.php::signAuthorities' =>
        'the v2 registry PRODUCER, and this estate mints no registry: it copies the shipped root and edits it. '
        . 'Exercised by sandbox/tests/offline/adapter/regress_authority_record_v2.php, which signs through it '
        . 'and then reads every document back through the shipped reader.',
    // WP-4.9's three § v3.8 gates. Every one of them is gated on a document
    // this estate does not build and must not: `adapters/delegations.json` and
    // `capabilities/adapter-revocations.json` are both ABSENT everywhere in the
    // field, which is the property that makes the flag day move no byte — so an
    // estate that installed one would stop being a rehearsal of the transition
    // and start being a rehearsal of a feature nobody has enabled.
    'agent/src/Adapter/AdapterCertification.php::verifyDelegation' =>
        'the depth-1 delegation verifier, reachable only from an installed adapters/delegations.json. This '
        . 'estate builds none, deliberately: § v3.8\'s gate is the absence of that document, and the estate\'s '
        . 'job is to prove the bump is digest-neutral for the sites that exist. The whole refusal matrix — '
        . 'chain depth, narrow-only namespace/tier/window, site-key delegator, revoked delegator, id '
        . 'collisions — is exercised by sandbox/tests/offline/adapter/regress_authority_delegation.php.',
    'agent/src/Adapter/AdapterCertification.php::revocations' =>
        'the reader for capabilities/adapter-revocations.json, which is absent from every manifest library this '
        . 'estate copies (it is absent from the shipped one, asserted on every run by '
        . 'regress_revocation_reachability.php). An absent document returns [] before any refusal is reachable. '
        . 'Its refusals — unsupported root, unimplemented version, foreign or revoked signer, bad signature, '
        . 'unreadable bytes — are exercised by '
        . 'sandbox/tests/offline/adapter/regress_revocation_reachability.php.',
    'agent/src/Adapter/AdapterCertification.php::assertNotRevoked' =>
        'the seat that consults that document. This estate DRIVES it on every certificate resolution — '
        . 'authority() and the frozen site branch both call it — but never REFUSES through it, because with no '
        . 'revocation document installed it returns before it can. The refusal, on the frozen path and the live '
        . 'path both, plus the preserved operator-own-key asymmetry it must not have closed, is exercised by '
        . 'sandbox/tests/offline/adapter/regress_revocation_reachability.php.',
    'recovery/CheckpointBundle.php::validatePriorVerification' =>
        'reached only through a receipt-authorized recovery provider call (RecoveryExecutor::configuration plus a '
        . 'signed rollback-control request), and manifest_inputs_sha256 is MINTED BY THE PROVIDER rather than '
        . 'derived by the agent — so the estate can bind and re-check the value but cannot drive the validator. '
        . 'Exercised by sandbox/tests/offline/recovery/regress_checkpoint_bundle.php.',
    'recovery/CheckpointBundle.php::validateVerifierInputs' =>
        'same provider-backed path as validatePriorVerification, which calls it.',
];

/** Run one driver pass and return its decoded document. */
function rehearsal_pass(string $driver, string $estate, string $state, string $mode): array {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $driver, $estate, $state, $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: cannot start the rehearsal driver\n");
        exit(1);
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($stdout, true);
    if ($exit !== 0 || !is_array($decoded)) {
        // A driver that cannot build or read the estate is a broken instrument,
        // not a failing assertion: report its own words and stop rather than
        // asserting over an empty document.
        fwrite(STDERR, "FAIL: rehearsal driver $mode/$state exited $exit\n" . $stderr . "\n");
        exit(1);
    }
    return $decoded;
}

function rehearsal_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        rehearsal_remove_tree($item->getPathname());
    }
    rmdir($path);
}

if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "FAIL: the PHP sodium extension is required for the flag-day rehearsal\n");
    exit(1);
}

// Rule 3: the estate never lives under agent/ or manifests/ —
// `sandbox/bin/pair.sh:355` gates on `git status --untracked-files=all -- agent
// manifests` and an untracked file there blocks every concurrent live package.
// A unique root per run, because `make -j8` runs this corpus concurrently.
$estate = sys_get_temp_dir() . '/duo-spec-migration-rehearsal-' . bin2hex(random_bytes(6));
register_shutdown_function(static fn() => rehearsal_remove_tree($estate));

echo "\n== the estate, built by the pre-flag agent ==\n";
$materialized = rehearsal_pass($driver, $estate, 'A', 'materialize');
$observedA = rehearsal_pass($driver, $estate, 'A', 'observe');
$observedB = rehearsal_pass($driver, $estate, 'B', 'observe');
$rolledBack = rehearsal_pass($driver, $estate, 'A', 'observe');

$libraryA = (array) $materialized['libraries']['A'];
$libraryB = (array) $materialized['libraries']['B'];
$shipped = (array) $materialized['libraries']['shipped'];
duo_check_same(
    $shipped,
    $libraryA,
    'the pre-flag library IS the shipped library, file for file and byte for byte — the estate is not a '
    . 'differently-built tree that happens to load'
);
$movedPaths = [];
foreach ($libraryA as $path => $digest) {
    if (($libraryB[$path] ?? null) !== $digest) {
        $movedPaths[] = $path;
    }
}
duo_check_same(
    ['capabilities/platform.json'],
    $movedPaths,
    'the flag day moves exactly ONE file in the library: the platform boundary. Every manifest, every hook file '
    . 'and the reviewed dispositions are untouched, which is the premise digest neutrality rests on (rule 2)'
);
duo_check(
    $observedA['agent_version'] !== $observedB['agent_version']
    && $observedA['spec_version'] === $observedB['spec_version']
    && $observedA['platform_sha256'] !== $observedB['platform_sha256'],
    'state B is a real release bump — a different agent version and a different platform digest under the same '
    . 'spec version (' . $observedA['agent_version'] . ' -> ' . $observedB['agent_version'] . ')'
);
$authorities = json_decode((string) file_get_contents($root . '/manifests/capabilities/adapter-authorities.json'), true);
duo_check_same(
    [],
    (array) ($authorities['keys'] ?? ['unreadable']),
    'the SHIPPED platform trust root is still empty, so every certificate this rehearsal invalidates is an '
    . 'operator\'s own site-rooted signature — G2\'s scoping precondition, re-checked here rather than assumed'
);

duo_check_same(
    (string) $observedA['platform_sha256'],
    (string) $materialized['contract']['attested_platform_sha256'],
    'the application contract attested before the bump binds state A\'s platform digest byte for byte — the '
    . 'reason contract_attestation_platform_moved is the most operator-visible refusal the flag day produces'
);

echo "\n== state A: what the fleet holds before the bump ==\n";
$cohort = [];
$controls = [];
foreach ($materialized['sites'] as $id => $plan) {
    if ($plan['kind'] === 'cohort') {
        $cohort[] = (string) $id;
    } else {
        $controls[] = (string) $id;
    }
}
duo_check(count($cohort) >= 7 && count($controls) === 2, 'the estate is ' . count($cohort) . ' cohort sites and '
    . count($controls) . ' deliberate controls');
foreach ($cohort as $id) {
    $row = $observedA['sites'][$id];
    duo_check_same('ok', $row['load'], "state A: $id loads (" . $materialized['sites'][$id]['about'] . ')');
}
duo_check_same(
    'verified',
    $observedA['sites']['pinned-shop']['artifact'] ?? null,
    'state A: the digest-pinned site\'s compiled artifact verifies against its own policy'
);
duo_check_same(
    'associated',
    $observedA['sites']['pinned-shop']['scope_contract'] ?? null,
    'state A: its scope contract is associated with that exact artifact'
);
duo_check_same(
    'binds',
    $observedA['sites']['pinned-shop']['identity_sidecar'] ?? null,
    'state A: its identity sidecar binds the active repository identity'
);
duo_check_same(
    'binds',
    $observedA['sites']['pinned-shop']['checkpoint'] ?? null,
    'state A: its recovery checkpoint binds the same manifest inputs'
);
duo_check_same(
    'rehydrated',
    $observedA['sites']['promoted-frozen']['snapshot'] ?? null,
    'state A: the promoted site rehydrates from its frozen snapshot'
);
foreach (['certified-alpha' => 'estate-forms', 'certified-beta' => 'estate-shop', 'promoted-frozen' => 'estate-catalog'] as $id => $adapter) {
    $certified = false;
    foreach ($observedA['sites'][$id]['adapters'] as $row) {
        $certified = $certified || ($row['name'] === $adapter && $row['certified'] === true);
    }
    duo_check($certified, "state A: $id's site adapter '$adapter' is certified under its operator key");
}
duo_check(
    str_contains((string) $observedA['sites']['drifted-pin']['refusal'], 'digest mismatch'),
    'CONTROL state A: a drifted content pin refuses with a digest mismatch, so a moved digest anywhere in this '
    . 'estate would be visible as a refusal rather than as a silent difference'
);
duo_check_same(
    'compiled_artifact_manifest_mismatch',
    $observedA['sites']['artifact-drift']['artifact_reason'] ?? null,
    'CONTROL state A: an artifact compiled against a library whose reviewed disposition moved by one sentence '
    . 'refuses with rule 2\'s refusal — the negative control for every "verified" below'
);

echo "\n== state B: the flag day ==\n";
$unloadable = [];
foreach ($cohort as $id) {
    if (($observedB['sites'][$id]['load'] ?? 'refused') !== 'ok') {
        $unloadable[] = $id . ': ' . (string) ($observedB['sites'][$id]['refusal'] ?? '');
    }
}
duo_check_same(
    [],
    $unloadable,
    'ZERO UNLOADABLE SITES: every site that loaded before the bump loads after it — the frozen-snapshot site and '
    . 'both certified-site-adapter sites included'
);

// The certified sites JOINED this list in WP-4.7. They were excluded because
// the bump withdrew their certificates and moved the certificate-derived digest
// folded into manifest_rows(); § v3.6 stopped that happening for a release that
// moves no exercised cell, so digest neutrality now covers every loadable site
// in the cohort rather than the shipped-only half of it.
$digestNeutral = ['certified-alpha', 'certified-beta', 'core-only', 'editorial', 'multilingual', 'pinned-shop', 'promoted-frozen'];
foreach ($digestNeutral as $id) {
    duo_check_same(
        [
            'manifest_hash' => $observedA['sites'][$id]['manifest_hash'],
            'site_hash' => $observedA['sites'][$id]['site_hash'],
            'revision_hash' => $observedA['sites'][$id]['revision_hash'],
            'adapters' => array_map(
                static fn(array $row): array => ['name' => $row['name'], 'digest' => $row['digest']],
                $observedA['sites'][$id]['adapters']
            ),
        ],
        [
            'manifest_hash' => $observedB['sites'][$id]['manifest_hash'],
            'site_hash' => $observedB['sites'][$id]['site_hash'],
            'revision_hash' => $observedB['sites'][$id]['revision_hash'],
            'adapters' => array_map(
                static fn(array $row): array => ['name' => $row['name'], 'digest' => $row['digest']],
                $observedB['sites'][$id]['adapters']
            ),
        ],
        "DIGEST NEUTRALITY: $id keeps every shipped adapter digest, its manifest_hash, its site_hash and its "
        . 'revision_hash byte-identical across the bump'
    );
    if ($id === 'promoted-frozen') {
        // Its artifact was removed at materialization on purpose — that site
        // exists to exercise the frozen path with nothing held — so there is no
        // artifact to verify and asserting one would be asserting the fixture.
        continue;
    }
    duo_check_same(
        'verified',
        $observedB['sites'][$id]['artifact'] ?? null,
        "state B: $id's compiled artifact still verifies — read_artifact() compares site_hash, manifest_hash, "
        . 'effects and code, none of which moved'
    );
}

// The measured correction to the plan's deployed-sites section, pinned as an
// assertion so a rider that changes it fails here instead of on a customer
// site: the artifact DOCUMENT hash moves for every site, because each resolved
// adapter row carries its capability claim and the claim embeds the boundary.
$artifactMoved = [];
foreach ($cohort as $id) {
    if (($observedA['sites'][$id]['artifact_hash'] ?? '') !== ($observedB['sites'][$id]['artifact_hash'] ?? '')) {
        $artifactMoved[] = $id;
    }
}
duo_check_same(
    $cohort,
    $artifactMoved,
    'MEASURED CONSEQUENCE: artifact_hash moves for EVERY site — including sites pinning nothing but shipped '
    . 'adapters — because resolved_adapters[*].capability.platform.agent_version is inside the compiled document. '
    . 'Anything that PINS artifact_hash (scope contracts, scoped mutation authorities, scoped rollback claims) '
    . 'needs re-projection on the flag day; anything that pins manifest_hash does not'
);
duo_check_same(
    'refused',
    $observedB['sites']['pinned-shop']['scope_contract'] ?? null,
    'and that is exactly what a held scope contract reports: assert_associated() refuses after the bump on a site '
    . 'whose manifest identity never moved (' . (string) ($observedB['sites']['pinned-shop']['scope_contract_reason'] ?? '') . ')'
);
duo_check_same(
    'binds',
    $observedB['sites']['pinned-shop']['checkpoint'] ?? null,
    'while the recovery checkpoint, which binds MANIFEST inputs rather than the artifact document, still binds — '
    . 'the two are not the same claim and the flag day separates them'
);

// THE MEASUREMENT WP-4.7 CHANGED, and the reason this block reads as it does.
// Until spec/repo-format.md § v3.6 a certificate bound the whole platform
// record byte for byte, so this release — `agent_version` and the prose the
// boundary restates with it — withdrew all three of these claims, moved each
// site's own manifest_hash with them, and refused each held artifact with
// `compiled_artifact_manifest_mismatch`. A certificate now binds the exercised
// compatibility CELLS, and this release moves none of them, so the three sites
// join the digest-neutral cohort above instead of forming a second population.
// The withdrawal machinery is not retired and is not untested: the estate's own
// assertPlatformBinding probe drives it against a boundary that moved a bound
// cell, and the brick reproduction below drives it end to end.
foreach (['certified-alpha' => 'estate-forms', 'certified-beta' => 'estate-shop', 'promoted-frozen' => 'estate-catalog'] as $id => $adapter) {
    $held = null;
    foreach ($observedB['sites'][$id]['adapters'] as $row) {
        if ($row['name'] === $adapter) {
            $held = $row;
        }
    }
    duo_check(
        is_array($held) && $held['certified'] === true && $held['capability'] !== 'none',
        "state B: $id's certified adapter '$adapter' still HOLDS its claim across the release — the bump moved no "
        . 'cell it was exercised against (' . (string) ($held['reason'] ?? 'no row') . ')'
    );
    duo_check_same(
        (static function (array $rows, string $name): string {
            foreach ($rows as $row) {
                if ($row['name'] === $name) {
                    return (string) $row['digest'];
                }
            }
            return '(absent)';
        })($observedA['sites'][$id]['adapters'], $adapter),
        is_array($held) ? (string) $held['digest'] : '(absent)',
        "state B: and '$adapter' keeps its exact digest, so the certificate-derived half of this site's identity "
        . 'is as pin-neutral as the shipped half'
    );
}
duo_check_same(
    'verified',
    $observedB['sites']['certified-alpha']['artifact'] ?? null,
    'MEASURED CONSEQUENCE, INVERTED: a site holding a CERTIFIED site adapter no longer moves its own manifest_hash '
    . 'on an ordinary release, so its held compiled artifact still verifies. Before § v3.6 this row read '
    . '`compiled_artifact_manifest_mismatch` and the certificate-holding population was the one population the '
    . 'flag day forced to recompile'
);
duo_check_same(
    'rehydrated',
    $observedB['sites']['promoted-frozen']['snapshot'] ?? null,
    'THE FROZEN PATH (G2 names it explicitly): a promoted site that may not reopen its mutable repository still '
    . 'rehydrates from its frozen snapshot after the bump, with the same claim withdrawn'
);
$frozenWithdrawn = [];
foreach ((array) ($observedB['sites']['promoted-frozen']['snapshot_adapters'] ?? []) as $row) {
    if ($row['name'] === 'estate-catalog') {
        $frozenWithdrawn = $row;
    }
}
duo_check(
    ($frozenWithdrawn['certified'] ?? null) === true,
    'and the frozen record is still RE-DERIVED rather than trusted — verifyFrozen() re-binds the certificate to '
    . 'the boundary now installed on every rehydration — but the answer it re-derives is now `certified`, because '
    . 'the release moved no cell that certificate was exercised against'
);

echo "\n== WP-1.1's brick, as a recorded expectation ==\n";
$prefixB = $observedB['prefix_reproduction'];
duo_check_same(
    str_replace('<name>', 'estate-forms', REHEARSAL_PREFIX_REFUSAL),
    (string) $prefixB['verify'],
    'the platform comparison still raises the RECORDED pre-fix refusal, byte for byte: the condition the pre-fix '
    . 'engine bricked on is reproduced here, not merely described'
);
duo_check_same(
    false,
    $prefixB['caught_by_prefix_catch_set'],
    'and the PRE-FIX catch set (one class, SupersededSiteAdapterCertificate) does not cover it — so before WP-1.1 '
    . 'this escaped scan_site_source() (:1115-1122), refuse() re-threw at SCOPE_SOURCE (:2028-2036) and '
    . 'Policy::load() propagated it uncaught (Policy.php:400): the whole site source refused'
);
duo_check_same(
    'Duo\\StalePlatformSiteAdapterCertificate',
    (string) $prefixB['verify_class'],
    'today the identical condition raises the TYPED withdrawal signal instead of a bare RuntimeException'
);
duo_check(
    $prefixB['load'] === 'ok' && $prefixB['certified'] === false
    && str_contains((string) $prefixB['reason'], 'no longer publishes'),
    'so the site loads with one claim withdrawn where it used to lose every command it had'
);
duo_check_same(
    'refused',
    (string) $prefixB['forged_source'],
    'CONTROL: a FORGED companion under the identical stale boundary still refuses the whole source ('
    . (string) ($prefixB['forged_reason'] ?? '') . ') — the withdrawal is a line drawn at two typed signals, not a '
    . 'relaxation, and the refusal channel the pre-fix exception took is still live'
);

echo "\n== forward, then back ==\n";
duo_check_same(
    $observedA['sites'],
    $rolledBack['sites'],
    'ROLLBACK: the estate observed at state A after a full pass at state B is byte-identical to the estate '
    . 'observed before it — every digest, pin, certificate verification, compiled artifact, frozen snapshot, '
    . 'scope contract, identity sidecar and checkpoint binding returns to its exact pre-flag value'
);
duo_check(
    ($observedB['remedy']['certify_exit'] ?? 1) === 0 && ($observedB['remedy']['certified'] ?? false) === true,
    'the remedy is invocable ON the post-bump fleet: `duo adapter certify --pin` re-signs a withdrawn adapter '
    . 'against the new boundary and it is certified again'
);
duo_check(
    ($rolledBack['remedy']['certified'] ?? true) === false
    && str_contains((string) ($rolledBack['remedy']['reason'] ?? ''), 'no longer publishes'),
    'and the symmetry the rollback plan names is real: a certificate minted WHILE at state B is withdrawn again '
    . 'after the rollback, for the same reason and by the same mechanism'
);
duo_check_same(
    true,
    $rolledBack['remedy']['re_certified'] ?? false,
    'with the same command restoring it: certificates are RE-MINTED symmetrically, never restored, which is the '
    . 'one thing an operator must redo in each direction'
);
duo_check_same(
    'not yet remedied',
    $observedA['remedy']['observed'] ?? null,
    'and the first state-A pass says so rather than reporting a fixture that did not exist yet'
);

echo "\n== per-site transition report ==\n";
printf(
    "%-16s %-7s %-7s %-9s %-9s %-9s %-11s %-11s %-8s %s\n",
    'site', 'A load', 'B load', 'manifest', 'artifact#', 'artifact', 'snapshot', 'contract', 'sidecar', 'checkpoint'
);
foreach (array_keys((array) $materialized['sites']) as $id) {
    $before = $observedA['sites'][$id];
    $after = $observedB['sites'][$id];
    $held = static fn(string $key): string => ($before[$key] ?? null) === null
        ? '-'
        : (($before[$key] ?? null) === ($after[$key] ?? null) ? 'held' : 'MOVED');
    printf(
        "%-16s %-7s %-7s %-9s %-9s %-9s %-11s %-11s %-8s %s\n",
        $id,
        (string) $before['load'],
        (string) $after['load'],
        $held('manifest_hash'),
        $held('artifact_hash'),
        (string) ($after['artifact'] ?? '-'),
        (string) ($after['snapshot'] ?? '-'),
        (string) ($after['scope_contract'] ?? '-'),
        (string) ($after['identity_sidecar'] ?? '-'),
        (string) ($after['checkpoint'] ?? '-')
    );
}

echo "\n== the platform-move gate register, enumerated ==\n";
$register = json_decode((string) file_get_contents($root . '/tools/platform-move-gates.json'), true);
if (!is_array($register) || !is_array($register['sites'] ?? null)) {
    fwrite(STDERR, "FAIL: tools/platform-move-gates.json is unreadable\n");
    exit(1);
}
$registerGates = [];
foreach ($register['sites'] as $site) {
    if (($site['verdict'] ?? null) === 'gate') {
        $registerGates[(string) $site['site']] = (array) $site['axes'];
    }
}
$exercised = [];
foreach ([$observedA, $observedB, $rolledBack] as $pass) {
    foreach ((array) $pass['gates'] as $row) {
        if (($row['matched'] ?? false) !== true) {
            continue;
        }
        $gate = (string) $row['gate'];
        if (!isset($exercised[$gate])) {
            $exercised[$gate] = [
                'state' => (string) $pass['state'],
                'entry' => (string) $row['entry'],
                'observed' => (string) $row['observed'],
            ];
        }
    }
}

$unmatched = [];
foreach ([$observedA, $observedB, $rolledBack] as $pass) {
    foreach ((array) $pass['gates'] as $row) {
        if (($row['matched'] ?? false) !== true) {
            $unmatched[] = (string) $pass['state'] . ' ' . (string) $row['gate']
                . ' expected "' . (string) $row['expect'] . '" got "' . (string) $row['observed'] . '"';
        }
    }
}
duo_check_same(
    [],
    $unmatched,
    'every probe the estate ran observed what it declared it would: a probe whose refusal message or verdict moved '
    . 'fails here rather than degrading into a silent gap'
);

$gaps = 0;
$missing = [];
foreach ($registerGates as $gate => $axes) {
    $axis = '[' . implode(',', $axes) . ']';
    if (isset($exercised[$gate])) {
        $evidence = $exercised[$gate];
        $observed = strlen($evidence['observed']) > 110
            ? substr($evidence['observed'], 0, 107) . '...'
            : $evidence['observed'];
        echo "EXERCISED $axis $gate\n";
        echo "          state {$evidence['state']}: {$evidence['entry']}\n";
        echo "          -> $observed\n";
        continue;
    }
    $gaps++;
    $reason = REHEARSAL_GAPS[$gate] ?? '';
    echo "NAMED GAP $axis $gate\n";
    echo '          ' . ($reason === '' ? 'NO REVIEWED REASON — this gate is unaccounted for' : $reason) . "\n";
    if ($reason === '') {
        $missing[] = $gate;
    }
}
printf(
    "\n%d register gates: %d exercised by the estate, %d named gaps\n",
    count($registerGates),
    count($registerGates) - $gaps,
    $gaps
);

duo_check_same(
    [],
    $missing,
    'every register gate this estate does not exercise is a NAMED GAP with a reviewed reason — an unexercised '
    . 'gate is never silence'
);
$strayGaps = [];
$staleGaps = [];
foreach (REHEARSAL_GAPS as $gate => $_reason) {
    if (!isset($registerGates[$gate])) {
        $strayGaps[] = $gate;
    } elseif (isset($exercised[$gate])) {
        $staleGaps[] = $gate;
    }
}
duo_check_same(
    [],
    $strayGaps,
    'and every declared gap names a gate the register actually carries, so a renamed entry point cannot leave a '
    . 'reason attached to nothing'
);
duo_check_same(
    [],
    $staleGaps,
    'and the ratchet runs the other way too: a gap the estate started exercising must be deleted from the list, '
    . 'not left as a standing excuse'
);
$strayProbes = [];
foreach (array_keys($exercised) as $gate) {
    if (!isset($registerGates[$gate])) {
        $strayProbes[] = $gate;
    }
}
duo_check_same(
    [],
    $strayProbes,
    'and no probe claims a gate the register does not carry as a gate — the enumeration is a join, not two lists '
    . 'that agree by hand'
);
duo_check(
    count($registerGates) - $gaps >= 37,
    'the estate exercises ' . (count($registerGates) - $gaps) . ' of ' . count($registerGates)
    . ' register gates end to end'
);

duo_check_summary('flag-day spec migration rehearsal');
