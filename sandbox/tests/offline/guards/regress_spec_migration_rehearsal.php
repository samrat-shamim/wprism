<?php
/**
 * The flag-day rehearsal: a synthetic fleet driven across an agent transition,
 * offline, with every enumerated platform-move gate accounted for by name.
 *
 * WHAT THIS SUITE ANSWERS
 * -----------------------
 * The migration this program was building toward moves two files together — the
 * two `define()` lines in `agent/wprism.php` and
 * `manifests/capabilities/platform.json`, which restates them (AGENTS.md rule
 * 8). WP-4.12 PERFORMED IT: `WPRISM_SPEC_VERSION` 2 -> 3, `WPRISM_AGENT_VERSION`
 * 0.6.0 -> 0.7.0. So this estate no longer rehearses a hypothesis one minor
 * ahead of the tree — the tree IS the far end now, and state A is the release
 * the fleet is coming FROM (`spec_migration_estate.php:122-141` states why the
 * derivation can only run downward).
 *
 * The plan's central engineering claim is that such a bump is DIGEST-NEUTRAL:
 * no adapter digest, no `manifest_hash`, no content pin moves, so pins,
 * compiled artifacts, scope contracts, identity sidecars and recovery
 * checkpoints all survive and rollback is a bundle swap rather than a
 * migration. That claim was an argument. This suite measures it, on nine
 * synthetic site repositories whose pin shapes are the ones real sites carry,
 * driving one estate through four child processes of
 * `spec_migration_estate.php` — build at state A, observe at A, observe at B,
 * observe at A again — because a state is a pair of `define()`s and a PHP
 * process can hold one of those pairs (that file's header states why).
 *
 * MEASURED, THE CLAIM HOLDS AT A NARROWER SCOPE THAN IT WAS WRITTEN, and this
 * suite is where that was found. Digest neutrality is complete for the four
 * sites pinning only shipped adapters. It is PARTIAL for the three holding a
 * certified site adapter: their `site_hash` and every shipped digest hold, but
 * the flip withdraws their certificates, and the certificate-derived row folded
 * into `manifest_rows()` takes `manifest_hash` and `revision_hash` with it, so
 * a held compiled artifact refuses. The per-site transition report this suite
 * prints is the evidence, and `docs/guides/flag-day.md` step 6 is the remedy.
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
 * down. Same shape as `wprism manifest-validate` stamping `deferred` rows on every
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
 *   - A site holding a CERTIFIED SITE ADAPTER moves that adapter's digest, and
 *     therefore its own `manifest_hash` and `revision_hash`, because the
 *     withdrawal changes the certificate-derived digest; its held compiled
 *     artifact then refuses with `compiled_artifact_manifest_mismatch` and must
 *     be recompiled. WP-4.7 (spec/repo-format.md § v3.6) ended that for an
 *     ORDINARY release — a certificate binds the exercised compatibility CELLS
 *     rather than the whole platform record, and moving `agent_version` alone
 *     moves no cell — but it deliberately does not spare a SPEC bump: R7 put a
 *     version INSIDE the signed statement so a wire change would read as a
 *     named refusal rather than as corruption, and `assertPlatformBinding()`
 *     compares it BEFORE any cell (AdapterCertification.php:3721-3727 vs
 *     :3753). The state-A pass measures the § v3.6 behaviour and the state-B
 *     pass measures the pre-emption, on the same fixture, so neither can be
 *     mistaken for the other. WP-1.1's `source:"site"` + uncertified pin
 *     concession (PinResolver.php:206) is what keeps such a site off the rocks
 *     while it is withdrawn: it degrades to `uncertified` and keeps loading,
 *     which is what makes `wprism adapter recertify` reachable on it at all.
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
 * THE SCOPE LIMIT IS GONE, AND WHAT REPLACED IT. This header used to record
 * that state B moved `WPRISM_AGENT_VERSION` and `platform.json` but NOT
 * `WPRISM_SPEC_VERSION` — first because `AdapterContractGrammar` was exact
 * equality (a v3 agent refused every v2 manifest by name, so every site here
 * would have been unloadable, which is why the acceptance window was the one
 * rider that could not be cut), and afterwards as a scope choice deferred to
 * WP-4.12. WP-4.12 landed. State B now carries both halves of the pair rule 8
 * binds, and the same estate answers the same question about the real
 * transition.
 *
 * What remains is a genuinely different limit, stated so it is not mistaken for
 * coverage: this estate can only ever look BACKWARDS from the tree. An engine
 * at N accepts {N-1, N} (`SpecVersionWindow::accepted()`), so a synthetic state
 * one spec AHEAD would put the window at {N, N+1} while every manifest in the
 * estate declares N-1 — all nine sites unloadable for a reason about the
 * fixture rather than about any migration. Forward-looking window evidence
 * belongs to `sandbox/tests/offline/policy/regress_spec_window.php`, which
 * probes one manifest set against a child engine at N+1 for exactly that
 * reason.
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
 * including the `wprism adapter certify --pin` that repairs it.
 *
 * ASSERTED AT STATE A SINCE WP-4.12, and the move is not a demotion. This is a
 * refusal about a moved CELL, so it is only reachable where the certificate's
 * spec era and the engine's agree — at state B the flip's own spec comparison
 * answers first (:3721-3727, ahead of :3753) and the cell is never read. Both
 * sentences are asserted, one per state, which is how the pre-emption itself
 * became a measurement instead of a lost row. Captured from
 * WP-1.1's own suite, which drives the identical condition
 * (`sandbox/tests/offline/adapter/regress_site_adapter_certification.php:1863`
 * and the case (d) assertions below it); `<name>` is this estate's adapter.
 */
const REHEARSAL_PREFIX_REFUSAL =
    "wprism: site adapter '<name>' certification binds compatibility axis 'database', whose exercised cells the "
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
    // WP-1.5's `wprism adapter doctor --migration`. These four gates CONSUME this
    // estate rather than living inside it: the preflight predicts what a bump
    // will move for one site, and the suite that drives it —
    // sandbox/tests/offline/cli/regress_migration_preflight.php — builds THIS
    // estate with THIS driver, observes it at both states, and then asserts
    // that the preflight's predicted invalidation set equals the movement set
    // measured here, per site. Driving them from inside the estate as well
    // would be the same command exercised twice under two names, and would put
    // the cross-check's own subject inside the fixture it is checked against.
    // WP-4.12's remedy verb, for the same reason and with the same shape: it
    // CONSUMES this transition rather than living inside it. What this estate
    // owns is the re-mint SYMMETRY — that a certificate minted at B is
    // withdrawn again after the rollback and restored by the same command —
    // and it measures that through `AdapterCertify::certify` in the remedy
    // block below. `recertify`'s own subjects are different questions
    // (idempotence by created_at reuse, the all-or-nothing restore of
    // adapters/authorities.json, a blocked row for a certificate under another
    // key), none of which is about crossing a version boundary, and its
    // refusals are written to STDERR — reachable only through a child process.
    'cli/src/Adapter/SpecMigration.php::recertify' =>
        'the flag day\'s remedy verb. Exercised end to end — idempotence, prior-pin journaling, and the '
        . 'all-or-nothing restore on a signing failure — by sandbox/tests/offline/cli/'
        . 'regress_spec_migration_verbs.php, against certificates a child process mints at the PRIOR spec era. '
        . 'This estate measures the property that suite cannot: that re-minting is symmetric across a rollback.',
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
        . 'WPRISM_AGENT_VERSION and platform.json, never WPRISM_SPEC_VERSION (the scope limit in this file\'s header), '
        . 'and no shipped or fixture manifest here declares the channel. Its inert half IS driven by every pass: '
        . 'the estate\'s claims are projected through this function at both states and their environment_assumptions '
        . 'are part of the digests compared across the transition. The refusal itself is exercised by '
        . 'sandbox/tests/offline/policy/regress_adapter_environment_narrowing.php, on all four narrowable axes. '
        . 'When WP-4.2\'s acceptance window ships and this estate gains the spec half, this gap should close.',
    'agent/src/Policy/AdapterLibrary.php::assertCapabilityEntries' =>
        'the archive reader\'s closed capability-directory refusals require a deliberately malformed library. '
        . 'This estate constructs two strict historical-flat libraries successfully and must keep their bytes '
        . 'valid across both states. The malformed capability cases are exercised by tests/Adapter/'
        . 'AdapterLibraryTest.php.',
    'agent/src/Policy/AdapterLibrary.php::fromLegacyFlatDirectory' =>
        'this estate drives the explicit archive constructor for every state, but does not refuse through it: '
        . 'a malformed archive would prevent the transition fixture from existing. Missing, orphaned and '
        . 'non-regular members are exercised by tests/Adapter/AdapterLibraryTest.php and '
        . 'sandbox/tests/offline/policy/regress_regen_dependency_policy.php.',
    'agent/src/Policy/AdapterLibrary.php::fromLogicalLayout' =>
        'the production source/deployed constructor is outside this historical-flat transition fixture, whose '
        . 'two version states deliberately use the explicit archive boundary. Its closed-layout refusals are '
        . 'exercised by tests/Adapter/AdapterLibraryTest.php and sandbox/tests/offline/adapter/'
        . 'regress_adapter_sources.php.',
    'agent/src/Promotion/Deploy.php::run_authorized' =>
        'promotion needs a live target: a promotion lease, the ledger and an apply session. The manifest-identity '
        . 'refusal it surfaces is CompiledArtifactReader::read_artifact()\'s, which this estate drives directly '
        . 'through the artifact-drift control.',
    'agent/src/Apply/AttachmentNativeMetadataGenerator.php::consume_polylang_no_language_handoff' =>
        'requires a finished attachment materialization: a WordPress image editor, attachment database ids and a '
        . 'sealed post-commit filesystem attempt. This state-only estate carries no media or target; its '
        . 'artifact-drift control reaches CompiledArtifactReader::read_artifact() before a native attachment '
        . 'callback could be permitted. regress_attachment_materializer.php covers the no-language handoff '
        . 'fixture; a transition-specific A/B attachment path remains outside this estate.',
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
    'cli/src/Adapter/AdapterDraft.php::make_tmp_library' =>
        'adapter draft builds a private source-layout library around one proposed package; this estate observes '
        . 'already-installed adapters and must not add a draft during the transition. The draft workflow is '
        . 'exercised by sandbox/tests/offline/cli/regress_cohort_rebaseline.php.',
    'cli/src/Onboarding/BootstrapEligibility.php::sourceComplete' =>
        'bootstrap eligibility examines a staged adoption source before any site in this estate exists. This '
        . 'fixture starts from already-adopted repositories, while the source-completeness gate is exercised by '
        . 'sandbox/tests/offline/cli/regress_local_bootstrap.php.',
    'cli/src/Refresh/RefreshPlan.php::assertProductionCodeMatches' =>
        'needs a git production ref and a refresh plan built against it. Exercised by '
        . 'sandbox/tests/offline/refresh/regress_refresh_orchestration.php.',
    // WP-4.8's four authority-record-v2 gates. Every authorities document this
    // estate builds is a well-formed `wprism-adapter-authorities/v1` one — which
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
    'agent/src/Adapter/AdapterSources.php::survey_from' =>
        'the REPORTING half of the same document (G2-FIXES C2): survey_from() raises a library-scoped row when an '
        . 'installed revocation document is inert or unreadable. Unreachable from this estate for the reason '
        . 'the two entries above give — no manifest library it copies carries one, because the shipped library '
        . 'does not — and the estate deliberately installs none, since § v3.8\'s gate is that absence. Both rows '
        . 'and the LIBRARY scope that keeps them from blocking a grammar verdict are exercised by '
        . 'sandbox/tests/offline/adapter/regress_revocation_reachability.php.',
    'recovery/CheckpointBundle.php::validatePriorVerification' =>
        'reached only through a receipt-authorized recovery provider call (RecoveryExecutor::configuration plus a '
        . 'signed rollback-control request), and manifest_inputs_sha256 is MINTED BY THE PROVIDER rather than '
        . 'derived by the agent — so the estate can bind and re-check the value but cannot drive the validator. '
        . 'Exercised by sandbox/tests/offline/recovery/regress_checkpoint_bundle.php.',
    'recovery/CheckpointBundle.php::validateVerifierInputs' =>
        'same provider-backed path as validatePriorVerification, which calls it.',
    // WP-5.6's two distribution gates. This estate is a fleet of sites that
    // ALREADY hold their adapters — that is what makes it a rehearsal of a
    // version transition rather than of an installation — and both of these
    // gates are reached only by resolving a wprism-adapter-index/v1 document the
    // estate does not have and must not acquire: an estate that installed a
    // package mid-transition would be measuring two moves at once and could
    // not attribute a digest change to the bump.
    'cli/src/Adapter/AdapterDistribution.php::enrolledFingerprints' =>
        'reads the installing repository\'s adapters/authorities.json to answer "is this package\'s signer '
        . 'enrolled here", a question only `wprism adapter install` asks. The estate\'s sites build and edit that '
        . 'exact file — the authority probes below drive it — but never through this seam. Its whole-root '
        . 'refusal and its unenrolled-signer answer are exercised by '
        . 'sandbox/tests/offline/cli/regress_adapter_distribution.php.',
    'cli/src/Adapter/AdapterDistribution.php::resolveVerified' =>
        'the install ladder (§ v3.19). Reachable only with an index document, a transport and a package to '
        . 'fetch, none of which this state-only estate has. What it surfaces on the authority and platform axes '
        . 'is AdapterCertification::verifyFile()\'s own refusals — an anchor gate this estate DOES exercise '
        . 'directly, at both states — so the transition property is measured here and the ladder that '
        . 'classifies it is exercised by sandbox/tests/offline/cli/regress_adapter_distribution.php, including '
        . 'a real platform-signed revocation and a genuinely lapsed v2 authority window.',
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
$estate = sys_get_temp_dir() . '/wprism-spec-migration-rehearsal-' . bin2hex(random_bytes(6));
register_shutdown_function(static fn() => rehearsal_remove_tree($estate));

echo "\n== the estate, built by the pre-flag agent ==\n";
$materialized = rehearsal_pass($driver, $estate, 'A', 'materialize');

// Post-flag per-adapter restamps are a different migration from the flag day
// this estate isolates. Both digest-pinned cohorts therefore use only the
// seven manifests still at v2; otherwise state A would be asked to load bytes
// that were deliberately authored after it ceased to be current.
$multilingualSite = json_decode(
    (string) file_get_contents($estate . '/sites/multilingual/site.wprism.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$multilingualPins = array_map(
    static fn(array $pin): string => (string) ($pin['name'] ?? ''),
    (array) ($multilingualSite['manifests'] ?? [])
);
wprism_check_same(
    ['core', 'contact-form-7', 'wps-hide-login'],
    $multilingualPins,
    'the historical flag-day cohort pins only retained-v2 adapters, keeping later per-adapter restamps out of this drill'
);
$observedA = rehearsal_pass($driver, $estate, 'A', 'observe');
$observedB = rehearsal_pass($driver, $estate, 'B', 'observe');
$rolledBack = rehearsal_pass($driver, $estate, 'A', 'observe');

$libraryA = (array) $materialized['libraries']['A'];
$libraryB = (array) $materialized['libraries']['B'];
$shipped = (array) $materialized['libraries']['shipped'];
// WP-4.12 inverted which end of the transition the tree is. The flip HAPPENED,
// so state B is the shipped state and state A is the release the fleet is
// coming from; `spec_migration_estate.php:122-141` states why the derivation
// can only ever run downward from the tree. The premise this assertion carries
// is unchanged: one end of the rehearsal must be the real library, or the whole
// estate is a differently-built tree that happens to load.
wprism_check_same(
    $shipped,
    $libraryB,
    'the POST-flag library IS the shipped library, file for file and byte for byte — state B is not a '
    . 'differently-built tree that happens to load, it is this repository'
);
$movedPaths = [];
foreach ($libraryA as $path => $digest) {
    if (($libraryB[$path] ?? null) !== $digest) {
        $movedPaths[] = $path;
    }
}
wprism_check_same(
    ['capabilities/platform.json'],
    $movedPaths,
    'the flag day moves exactly ONE file in the library: the platform boundary. Every manifest, every hook file '
    . 'and the reviewed dispositions are untouched, which is the premise digest neutrality rests on (rule 2)'
);
wprism_check(
    $observedA['agent_version'] !== $observedB['agent_version']
    && $observedB['spec_version'] === $observedA['spec_version'] + 1
    && $observedA['platform_sha256'] !== $observedB['platform_sha256'],
    'STATE B IS THE FLIP, not a release bump: BOTH defines moved, one minor and exactly one spec version, and '
    . 'the platform digest with them ('
    . $observedA['agent_version'] . '/' . $observedA['spec_version'] . ' -> '
    . $observedB['agent_version'] . '/' . $observedB['spec_version'] . '). AGENTS.md rule 8 binds the pair, so a '
    . 'transition that moved only one of them would be rehearsing a state the product cannot ship'
);
// The window is what makes this transition survivable at all, and it is
// asymmetric: an engine at N accepts {N-1, N} (SpecVersionWindow::accepted()).
// Every manifest in this estate declares state A's version, so B accepts them
// and A accepts them — which is the no-restamp rule's whole payoff, measured
// here rather than argued. Had the estate been driven the other way, a state
// one spec AHEAD of the tree would put the window at {3, 4} and every site
// would be unloadable for a reason about the fixture, not about the migration.
wprism_check(
    in_array($observedA['spec_version'], [$observedB['spec_version'] - 1, $observedB['spec_version']], true),
    'and state A is INSIDE state B\'s acceptance window {' . ($observedB['spec_version'] - 1) . ', '
    . $observedB['spec_version'] . '} — the one rider that could not be cut (§ v3.1), without which a v'
    . $observedB['spec_version'] . ' agent would refuse every manifest in this estate by name and there would be '
    . 'no flag day to rehearse, only a brick'
);
$authorities = json_decode((string) file_get_contents($root . '/platform/adapter-library/capabilities/adapter-authorities.json'), true);
wprism_check_same(
    [],
    (array) ($authorities['keys'] ?? ['unreadable']),
    'the SHIPPED platform trust root is still empty, so every certificate this rehearsal invalidates is an '
    . 'operator\'s own site-rooted signature — G2\'s scoping precondition, re-checked here rather than assumed'
);

wprism_check_same(
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
wprism_check(count($cohort) >= 7 && count($controls) === 2, 'the estate is ' . count($cohort) . ' cohort sites and '
    . count($controls) . ' deliberate controls');
foreach ($cohort as $id) {
    $row = $observedA['sites'][$id];
    wprism_check_same('ok', $row['load'], "state A: $id loads (" . $materialized['sites'][$id]['about'] . ')');
}
wprism_check_same(
    'verified',
    $observedA['sites']['pinned-shop']['artifact'] ?? null,
    'state A: the digest-pinned site\'s compiled artifact verifies against its own policy'
);
wprism_check_same(
    'associated',
    $observedA['sites']['pinned-shop']['scope_contract'] ?? null,
    'state A: its scope contract is associated with that exact artifact'
);
wprism_check_same(
    'binds',
    $observedA['sites']['pinned-shop']['identity_sidecar'] ?? null,
    'state A: its identity sidecar binds the active repository identity'
);
wprism_check_same(
    'binds',
    $observedA['sites']['pinned-shop']['checkpoint'] ?? null,
    'state A: its recovery checkpoint binds the same manifest inputs'
);
wprism_check_same(
    'rehydrated',
    $observedA['sites']['promoted-frozen']['snapshot'] ?? null,
    'state A: the promoted site rehydrates from its frozen snapshot'
);
foreach (['certified-alpha' => 'estate-forms', 'certified-beta' => 'estate-shop', 'promoted-frozen' => 'estate-catalog'] as $id => $adapter) {
    $signedApproval = false;
    foreach ($observedA['sites'][$id]['adapters'] as $row) {
        $signedApproval = $signedApproval || ($row['name'] === $adapter
            && $row['certified'] === false
            && $row['capability'] === 'experimental');
    }
    wprism_check($signedApproval, "state A: $id's site adapter '$adapter' is signed but remains experimental without exercise evidence");
}
wprism_check(
    str_contains((string) $observedA['sites']['drifted-pin']['refusal'], 'digest mismatch'),
    'CONTROL state A: a drifted content pin refuses with a digest mismatch, so a moved digest anywhere in this '
    . 'estate would be visible as a refusal rather than as a silent difference'
);
wprism_check_same(
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
wprism_check_same(
    [],
    $unloadable,
    'ZERO UNLOADABLE SITES: every site that loaded before the bump loads after it — the frozen-snapshot site and '
    . 'both certified-site-adapter sites included'
);

// THE ESTATE HAS TWO POPULATIONS ON A SPEC FLIP, AND SAYING SO IS THE POINT.
//
// The certified sites joined the neutral list in WP-4.7, when § v3.6 made a
// certificate bind the exercised compatibility CELLS instead of the whole
// platform record — an ordinary `agent_version` release moves no cell, so it
// withdraws nothing and moves nothing. WP-4.12 is not an ordinary release.
// `spec_version` sits INSIDE the signed `statement.platform` and
// assertPlatformBinding() compares it first (AdapterCertification.php:3721-
// 3727, ahead of the cell comparison at :3753), so on the flag day every
// certificate withdraws, the certificate-derived digest folded into
// ArtifactPolicyIdentity::manifest_rows() moves with it, and the holder's own
// manifest_hash and revision_hash move with THAT.
//
// So the three certificate-holding sites leave this list again — measured, not
// assumed: the per-site transition report below prints `MOVED` for exactly
// those three and `held` for the other four. Splitting them is not a weakening
// of the neutrality claim; it is the claim stated at its true scope. What the
// no-restamp rule buys is that the SHIPPED half never moves for anybody, which
// is asserted for the holders too, one block down.
$digestNeutral = ['core-only', 'editorial', 'multilingual', 'pinned-shop'];
foreach ($digestNeutral as $id) {
    wprism_check_same(
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
    wprism_check_same(
        'verified',
        $observedB['sites'][$id]['artifact'] ?? null,
        "state B: $id's compiled artifact still verifies — read_artifact() compares site_hash, manifest_hash, "
        . 'effects and code, none of which moved'
    );
}

// THE SECOND POPULATION, MEASURED IN BOTH DIRECTIONS AT ONCE.
//
// A site holding a certified site adapter is where the flag day is NOT
// digest-neutral, and the runbook's step 6 exists for exactly these sites. The
// block asserts both halves on the same fixture, because either half alone is
// misleading: the SHIPPED digests and `site_hash` hold (the no-restamp rule
// covers every site, certificate or not), while `manifest_hash`,
// `revision_hash` and the certificate-derived adapter digest MOVE and the held
// compiled artifact refuses. A suite that reported only the neutral half would
// be describing the flag day the plan hoped for rather than the one it ships.
$certificateHolders = ['certified-alpha' => 'estate-forms', 'certified-beta' => 'estate-shop', 'promoted-frozen' => 'estate-catalog'];
foreach ($certificateHolders as $id => $adapter) {
    $shippedDigests = static function (array $rows) use ($adapter): array {
        $out = [];
        foreach ($rows as $row) {
            if ($row['name'] !== $adapter) {
                $out[(string) $row['name']] = (string) $row['digest'];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    };
    wprism_check_same(
        [
            'site_hash' => $observedA['sites'][$id]['site_hash'],
            'shipped_digests' => $shippedDigests($observedA['sites'][$id]['adapters']),
        ],
        [
            'site_hash' => $observedB['sites'][$id]['site_hash'],
            'shipped_digests' => $shippedDigests($observedB['sites'][$id]['adapters']),
        ],
        "PARTIAL NEUTRALITY: $id keeps its site_hash and every SHIPPED adapter digest byte-identical — the "
        . 'no-restamp rule covers a certificate-holding site exactly as it covers any other, so nothing it pins '
        . 'from the shipped library moves'
    );
    wprism_check(
        $observedA['sites'][$id]['manifest_hash'] !== $observedB['sites'][$id]['manifest_hash']
        && $observedA['sites'][$id]['revision_hash'] !== $observedB['sites'][$id]['revision_hash'],
        "...AND $id's manifest_hash and revision_hash BOTH MOVE, because the withdrawn certificate moves the "
        . 'certificate-derived row manifest_rows() folds (ArtifactPolicyIdentity.php:75-140). This is the flag '
        . "day's one non-neutral cohort, and `wprism adapter doctor --migration` predicts it per site before the bump"
    );
    if ($id === 'promoted-frozen') {
        // Its artifact was removed at materialization on purpose — that site
        // exists to exercise the frozen path with nothing held — so there is no
        // artifact to refuse and asserting one would be asserting the fixture.
        continue;
    }
    wprism_check_same(
        'compiled_artifact_manifest_mismatch',
        $observedB['sites'][$id]['artifact_reason'] ?? null,
        "...and $id's held compiled artifact therefore REFUSES with rule 2's own refusal "
        . '(CompiledArtifactReader.php:56) — the same sentence the artifact-drift control raises at state A, '
        . 'reached here by a moved certificate rather than a moved manifest. Recompile-and-re-pin is the remedy '
        . 'the runbook schedules at step 6; there is no fallback and rule 9 would refuse one'
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
wprism_check_same(
    $cohort,
    $artifactMoved,
    'MEASURED CONSEQUENCE: artifact_hash moves for EVERY site — including sites pinning nothing but shipped '
    . 'adapters — because resolved_adapters[*].capability.platform.agent_version is inside the compiled document. '
    . 'Anything that PINS artifact_hash (scope contracts, scoped mutation authorities, scoped rollback claims) '
    . 'needs re-projection on the flag day; anything that pins manifest_hash does not'
);
wprism_check_same(
    'refused',
    $observedB['sites']['pinned-shop']['scope_contract'] ?? null,
    'and that is exactly what a held scope contract reports: assert_associated() refuses after the bump on a site '
    . 'whose manifest identity never moved (' . (string) ($observedB['sites']['pinned-shop']['scope_contract_reason'] ?? '') . ')'
);
wprism_check_same(
    'binds',
    $observedB['sites']['pinned-shop']['checkpoint'] ?? null,
    'while the recovery checkpoint, which binds MANIFEST inputs rather than the artifact document, still binds — '
    . 'the two are not the same claim and the flag day separates them'
);

// THE M6 CONSEQUENCE, DRIVEN THROUGH THE PRODUCT PATH ON ALL THREE HOLDERS.
//
// This is the flag day's central cost and the block that has to be hardest to
// misread. § v3.6 (WP-4.7) rebound a certificate to the exercised compatibility
// CELLS instead of the whole platform record, which is why an ordinary
// `agent_version` release now withdraws nothing — still true, still measured,
// by the state-A pass of the estate's verifyCertificate probe and by
// regress_migration_preflight.php's 0.5.1-only contrast.
//
// The spec half is deliberately not covered by that. R7 put a version INSIDE
// the signed statement precisely so a future wire change would read as a named
// refusal rather than as corruption, and assertPlatformBinding() honours that by
// comparing `spec_version` before any cell (:3721-3727 vs :3753). So the flip
// withdraws all three claims by name. What it does NOT do is take the sites
// down: WP-1.1's typed signal degrades the adapter to `uncertified` and
// PinResolver skips the resulting mismatch for a `source:"site"` pin, so the
// adapter keeps loading and every command stays available. That distinction —
// withdrawn, not bricked — is the whole reason this bump is shippable, and it
// is asserted rather than described.
foreach ($certificateHolders as $id => $adapter) {
    $held = null;
    foreach ($observedB['sites'][$id]['adapters'] as $row) {
        if ($row['name'] === $adapter) {
            $held = $row;
        }
    }
    wprism_check(
        is_array($held) && $held['certified'] === false
        && str_contains((string) ($held['reason'] ?? ''), 'no longer publishes'),
        "THE M6 CONSEQUENCE: $id's certified adapter '$adapter' WITHDRAWS to uncertified across the flip, by name "
        . 'and with the reason attached (' . (string) ($held['reason'] ?? 'no row') . ')'
    );
    wprism_check(
        is_array($held) && ($observedB['sites'][$id]['load'] ?? '') === 'ok',
        "...and $id STILL LOADS with it: the withdrawal is a degradation to `uncertified`, never the whole-source "
        . 'refusal WP-1.1 removed, so `plan`, `apply` and the `wprism adapter recertify` that repairs it all stay '
        . 'reachable on the degraded site — the precondition G2 condition (1) gates the flag day on'
    );
    wprism_check(
        (static function (array $rows, string $name): string {
            foreach ($rows as $row) {
                if ($row['name'] === $name) {
                    return (string) $row['digest'];
                }
            }
            return '(absent)';
        })($observedA['sites'][$id]['adapters'], $adapter) !== (is_array($held) ? (string) $held['digest'] : '(absent)'),
        "...and '$adapter''s own digest MOVES with the withdrawal, which is the mechanism — not a second "
        . 'independent effect — by which this site\'s manifest_hash and revision_hash moved two blocks above'
    );
}
wprism_check_same(
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
wprism_check(
    ($frozenWithdrawn['certified'] ?? null) === false,
    'and the frozen record is RE-DERIVED rather than trusted: verifyFrozen() re-binds the certificate to the '
    . 'boundary installed NOW on every rehydration, so a snapshot taken before the flip does not carry a stale '
    . '`certified` into a v' . $observedB['spec_version'] . ' engine — it re-derives `uncertified`, the same answer '
    . 'the live path gives. G2 condition (2) names this path explicitly because it is the one an operator cannot '
    . 'repair in place: a promoted site may not reopen its mutable repository'
);

echo "\n== WP-1.1's brick, as a recorded expectation ==\n";
$prefixA = $observedA['prefix_reproduction'];
$prefixB = $observedB['prefix_reproduction'];
// The recorded artifact is asserted AT STATE A, where the certificate's era and
// the engine's agree and the moved cell is therefore the only variable. That is
// the condition WP-1.1 bricked on, reproduced rather than described.
wprism_check_same(
    str_replace('<name>', 'estate-forms', REHEARSAL_PREFIX_REFUSAL),
    (string) $prefixA['verify'],
    'the platform comparison still raises the RECORDED pre-fix refusal, byte for byte: the condition the pre-fix '
    . 'engine bricked on is reproduced here, not merely described'
);
// ...and at state B the SAME probe, against the SAME moved cell, answers with a
// different sentence — because the flip moved the spec half too and
// assertPlatformBinding() compares that first (:3721-3727, ahead of the cell
// comparison at :3753). Recording the pre-emption is the point: it is why
// § v3.6's axis binding does not spare a spec bump, and a reviewer who only saw
// the state-A row would conclude the opposite.
wprism_check_same(
    "wprism: site adapter 'estate-forms' certification was signed under spec version "
    . $observedA['spec_version'] . ', which is not the spec version ' . $observedB['spec_version']
    . ' this agent publishes',
    (string) $prefixB['verify'],
    'and ACROSS THE FLIP the identical probe raises the SPEC sentence instead: the spec comparison PRE-EMPTS the '
    . 'cell comparison, so a certificate cannot survive a spec bump by having been exercised against cells that '
    . 'did not move — the deliberate consequence of putting a version inside the signed statement (R7)'
);
wprism_check_same(
    'WPrism\\StalePlatformSiteAdapterCertificate',
    (string) $prefixA['verify_class'],
    'both sentences are the SAME TYPED SIGNAL — state A\'s cell refusal is a StalePlatformSiteAdapterCertificate '
    . 'too, so WP-1.1\'s degradation covers the flag day\'s refusal without a new arm being added for it'
);
wprism_check_same(
    false,
    $prefixB['caught_by_prefix_catch_set'],
    'and the PRE-FIX catch set (one class, SupersededSiteAdapterCertificate) does not cover it — so before WP-1.1 '
    . 'this escaped scan_site_source() (:1115-1122), refuse() re-threw at SCOPE_SOURCE (:2028-2036) and '
    . 'Policy::load() propagated it uncaught (Policy.php:400): the whole site source refused'
);
wprism_check_same(
    'WPrism\\StalePlatformSiteAdapterCertificate',
    (string) $prefixB['verify_class'],
    'today the identical condition raises the TYPED withdrawal signal instead of a bare RuntimeException'
);
wprism_check(
    $prefixB['load'] === 'ok' && $prefixB['certified'] === false
    && str_contains((string) $prefixB['reason'], 'no longer publishes'),
    'so the site loads with one claim withdrawn where it used to lose every command it had'
);
wprism_check_same(
    'refused',
    (string) $prefixB['forged_source'],
    'CONTROL: a FORGED companion under the identical stale boundary still refuses the whole source ('
    . (string) ($prefixB['forged_reason'] ?? '') . ') — the withdrawal is a line drawn at two typed signals, not a '
    . 'relaxation, and the refusal channel the pre-fix exception took is still live'
);

echo "\n== forward, then back ==\n";
wprism_check_same(
    $observedA['sites'],
    $rolledBack['sites'],
    'ROLLBACK: the estate observed at state A after a full pass at state B is byte-identical to the estate '
    . 'observed before it — every digest, pin, certificate verification, compiled artifact, frozen snapshot, '
    . 'scope contract, identity sidecar and checkpoint binding returns to its exact pre-flag value'
);
wprism_check(
    ($observedB['remedy']['certify_exit'] ?? 1) === 0
    && ($observedB['remedy']['certified'] ?? true) === false
    && ($observedB['remedy']['capability'] ?? null) === 'experimental',
    'the remedy is invocable ON the post-bump fleet: `wprism adapter certify --pin` re-signs the approval against '
    . 'the new boundary while keeping the unexercised claim experimental'
);
wprism_check(
    ($rolledBack['remedy']['certified'] ?? true) === false
    && str_contains((string) ($rolledBack['remedy']['reason'] ?? ''), 'no longer publishes'),
    'and the symmetry the rollback plan names is real: a certificate minted WHILE at state B is withdrawn again '
    . 'after the rollback, for the same reason and by the same mechanism'
);
wprism_check_same(
    'experimental',
    $rolledBack['remedy']['re_capability'] ?? null,
    'with the same command restoring the signed experimental approval: certificates are RE-MINTED symmetrically, '
    . 'never restored, and exercise evidence remains a separate requirement'
);
wprism_check_same(
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
wprism_check_same(
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

wprism_check_same(
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
wprism_check_same(
    [],
    $strayGaps,
    'and every declared gap names a gate the register actually carries, so a renamed entry point cannot leave a '
    . 'reason attached to nothing'
);
wprism_check_same(
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
wprism_check_same(
    [],
    $strayProbes,
    'and no probe claims a gate the register does not carry as a gate — the enumeration is a join, not two lists '
    . 'that agree by hand'
);
wprism_check(
    count($registerGates) - $gaps >= 37,
    'the estate exercises ' . (count($registerGates) - $gaps) . ' of ' . count($registerGates)
    . ' register gates end to end'
);

wprism_check_summary('flag-day spec migration rehearsal');
