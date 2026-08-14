# Thread 0 foundation decisions

*Status: approved foundation authority record, 2026-08-14.*

This record makes the owner decisions required by the merged Thread 0 charter
executable. It does not ship any deferred product workflow or create a current
capability claim. The compatibility freeze and merge train in
`thread-0-foundation.md` are approved subject to every gate below remaining
green.

## Safety disposition

| Candidate | Disposition | Enforced boundary | Proof / lifting condition |
|---|---|---|---|
| Authoritative portable Capture on experimental or incompletely classified surfaces | **Loud quarantine** | `wp duo capture` and confirmed Init refuse with `portable_capture_safety_unqualified` before repository, scope-file, lock, ledger, identity, filesystem, or database access. Read-only plan/explain/pending/observation and Init proposal remain available. | `scripts/contracts-check` freezes the refusal. Thread 3 may lift it only after the enhancement round supplies reviewed per-surface portability, sensitivity, provenance, and authorization proof. |
| PII discovery across forms, membership, post/media/user metadata, options, and custom tables | **Loud quarantine by the portable Capture gate** | Unknown or incomplete sensitivity cannot enter an authoritative repository; existing characterization helpers do not become authority. | Lift only with comprehensive surface fixtures and reviewed sensitivity decisions, not a broader allow flag. |
| Apply and Delete outside an independently proven qualification harness | **Loud quarantine** | `wp duo apply`, including `--with-deletes`, refuses with `qualification_harness_required` before repository compilation, lease, canary, provider, or target mutation. | Thread 1 must verify a fresh separately issued record conforming to `docs/contracts/harness-approval.schema.json`; behavior fragments may carry references only. |
| Recovery provider/profile drift before promotion mutation or forward resume | **Loud quarantine** | `duo promote` refuses with `promotion_recovery_decision_unbound` before scope routing, target contact, lease, checkpoint, Deploy, or Apply. Legacy records remain readable for recovery only. | Thread 5 must add a versioned durable decision/session binding and exact reselection comparison before initial mutation and every forward resume. |
| `env materialize` from production-derived or third-party snapshots | **Loud quarantine** | After complete option parsing, `duo env materialize` refuses with `environment_materialization_containment_unproved` before registry load, provider construction, journal creation, source/target contact, or snapshot access. `env reap` remains available. | Lift only through independently approved synthetic/minimized data, non-production credential references, default-deny egress/effects, encryption, retention, and secure deletion proof. |
| Platform version claims broader than evidence | **Disproven as a live defect by executable/current registry evidence** | The current registry admits WordPress exactly at `7.0.3`; PHP is `>=8.3.0,<8.4.0`; MariaDB is `>=11,<12`, and target evaluation refuses mismatches. Plugin claims retain their separately reviewed ranges. | `make release-gate` passed on the clean base; the compatibility baseline and generated registry agree. Any widened range requires a real matrix and new evidence. |
| Authoritative portable single-file writes | **Minimally fixed** | `Policy::set_rule()` and `CompiledRepository::write()` use `AtomicFilePublisher`: same-directory exclusive staging, full write, flush/fsync, atomic rename, and directory-sync attempt. | The injected pre-rename failure fixture proves old bytes remain visible. Other direct `Canon::write_file()` sites are classified as staging/temp, target mutation, backup/compensation, or atomic-tree protocol participants in the execution record. |
| Recovery runtime omitted from subject source closure | **Minimally fixed** | `ScopedCertificationBundle::subjectInputPaths()` now includes `recovery/**`. | Existing v1 subject bundles are expected to become stale. They are not regenerated in this refactor branch; the branch remains explicitly non-releasable until the final frozen-candidate recertification. |

The quarantines are intentional post-disposition behavior and therefore the
only behavior frozen by the foundation command fixtures. A test-only
environment variable or mutable `disposable` field is not an authorization
bypass.

## Portable-write classification

- `CapturePublicationWorkflow` writes candidate media/staging under the
  existing lock, intent, atomic-tree swap, and compensation protocol.
- `AttachmentMaterializer` is an authorized target-mutation implementation,
  not repository publication.
- `ConvergenceVerifier` and `ScopedStateOverlay` write bounded temporary views.
- `AdapterDraft` writes non-authorizing draft output.
- `InitOwnedArtifacts` and `PublicationJournal` are compensating protocols with
  independently verified ownership/identity.
- `Cli::identity_export` writes an explicit operator-selected backup/export.
- `Policy::set_rule` and compiled-artifact publication were authoritative
  single-file gaps and are the two sites moved to `AtomicFilePublisher`.

## Runtime and artifact contracts

Three versions are distinct:

- Loader compatibility may inspect/refuse on PHP 8.0/8.1 without loading the
  engine.
- The engine source syntax floor is **PHP 8.2**. New pure foundation modules
  may use typed/readonly PHP 8.2 syntax; no production Composer dependency is
  introduced.
- The only current certified runtime profile is **PHP >=8.3,<8.4**, WordPress
  **7.0.3 exactly**, and MariaDB **>=11,<12**, as recorded in
  `docs/compatibility-baseline.json`. Parsing on 8.2 does not imply Ready or
  certification there.

The approved build layout separates:

1. content-addressed host CLI payload;
2. content-addressed target runtime payload (agent plus recovery runtime);
3. immutable declaration payload;
4. detached reviewed authority bundle;
5. deterministic generated projection pack;
6. detached target release-set manifest; and
7. detached release-family manifest binding the target set, exact host
   artifact, and compatible host/agent/recovery protocol tuple.

No payload contains the evidence that reviews it, and no manifest participates
in its own digest. The target-install composite is the atomic adoption unit.
The caller supplies `ReleaseSelection` from a non-co-located authority source;
the artifact producer cannot choose its expected family digest or trust roots.
`ArtifactTrustVerifier` recomputes agreement from exact bytes without loading
WordPress or agent implementation code. Closure v2 is approved by
`docs/contracts/closure-v2.schema.json`; its redacted identity fields follow
`docs/contracts/evidence-identity.schema.json`.

## Exact revision and evidence merge ruling

This refactor round uses the charter's explicit non-rewriting merge-policy
exception. Rebase is permitted only before the candidate freeze. After freeze:

1. the candidate commit object is immutable and reachable;
2. its evidence-only child has that candidate as its **direct parent**;
3. the child diff is restricted to the approved detached review/projection
   source allowlist and changes no runtime, declaration, build definition, or
   certified payload byte;
4. imported bundle revisions/digests name the candidate, not a squash result;
5. the exact retained candidate artifacts are assembled and tested—nothing is
   rebuilt from the evidence-child checkout; and
6. both commit objects remain reachable from fresh `main` through an approved
   fast-forward/non-rewriting integration. A squash or post-freeze rebase is
   not an equivalent identity.

The evidence-child profile is non-mutating. A separate independently
authorized post-evidence `release-validation` profile uses the exact retained
family/set/composite for adoption and recovery/rollback. Failure restarts the
freeze/evidence cycle.

## Hot and generated files

The hot-file protocol is accepted:

- Thread 1 alone writes agent loader/bootstrap regions.
- Thread 2 alone writes `cli/duo`.
- Thread 3 alone writes `agent/src/Cli.php`.
- Thread 4 alone writes the capability generator and regenerated capability
  outputs.
- Thread 5 alone writes `recovery/rollback-control.php`.

A consumer exports a tested class and integration request; the hot-file owner
lands wiring. Generated outputs are regenerated by their owner after rebase and
reviewed semantically, never hand-merged.

## Approval

The owner-approved, merged Thread 0 charter is the authority for this record.
This record approves the compatibility freeze, exact-revision exception,
artifact separation, hot/generated-file protocol, and reader-before-writer
merge train. It does not approve production work by Threads 2–5 until Platform
P0 and their initial owner-authored test fragments have completed the mini-train.
