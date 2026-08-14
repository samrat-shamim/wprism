# Thread 0 — Refactor foundation and integration contract

*Status: proposed for owner approval.*

*Round: behavior-preserving modularization before product enhancement.*

*This charter is binding on threads 1–5.*

## Decision

This round restructures the implementation without intentionally changing Duo's
product behavior, public claims, or persisted protocols. The finalized product
specification determines the direction of the seams, but it does not make
target-state behavior shipped. In particular, this round does not add the new
Assess, Rehearse, Release, Verify, Recover, or qualification product workflows.

The owner has deliberately chosen this modularization round before the product
specification's default field-adoption-first sequence. This is a temporary
sequencing ruling, not an amendment to the product specification: the round is
limited to prerequisite safety, characterization, modularity, and development
infrastructure, and field adoption resumes before broader product abstraction.

The current implementation is a valuable correctness and recovery kernel, not
a prototype to replace. Recent work has already moved Apply, Init, and parts of
Capture toward thin facades. This round continues that pattern across the
remaining large composition roots and mixed-responsibility modules.

Threads are divided by write ownership, not merely by theme. Each production
file has one writer. Other threads consume its existing facade or submit a
small integration request to its owner.

## Disposition of the earlier six drafts

The earlier proposal set supplied useful evidence and constraints, but its
execution threads were primarily product enhancements. This program retains:

- loud/fail-closed safety and the separation of declarations, evidence,
  dispositions, and generated claims;
- byte-format, digest, receipt, and generated-file discipline;
- explicit hot-file ownership and separate worktrees;
- the test catalog, streaming results, CI/static-analysis ratchet, artifact
  build, PHP-floor guard, and bootstrap-performance work;
- the discovered Assess, capability, rehearsal, release, recovery, PII, and
  experimental-authority gaps as input to the next round;
- the rule that evidence is regenerated once against a frozen integrated
  candidate, not independently by five branches.

It rejects for this round:

- mechanical legacy-to-target vocabulary mappings;
- registry v3, new public wire envelopes, `duo assess`, authorization plans,
  recovery-profile schema changes, and rehearsal containment declarations;
- a mutable `disposable: true` flag as proof of containment;
- provider booleans or existing receipt digests as stronger attestations;
- unsafe `(path, mtime, size)` authority caches and unaudited `make -j` test
  parallelism;
- a speculative shared kernel that becomes a dependency before distribution
  and certification closure are proven.

## Program map

| Thread | Charter | Owned conflict surface |
|---|---|---|
| 0 | Foundation and integration | Contracts, baselines, ownership, merge and evidence policy |
| 1 | [Engineering platform and runtime](thread-1-engineering-platform-runtime.md) | Build, boot, developer tooling, test runner, CI |
| 2 | [Host CLI and environment orchestration](thread-2-host-cli-environment.md) | Host composition root, generic commands, transports, environments, refresh/rebase |
| 3 | [WordPress agent and capture](thread-3-wordpress-agent-capture.md) | Agent command surface, observation, Init, Capture, repository projection |
| 4 | [Capability, policy, adapters, and evidence](thread-4-capability-policy-evidence.md) | Current policy/capability/provider/evidence implementation |
| 5 | [Mutation, promotion, and recovery](thread-5-mutation-promotion-recovery.md) | Agent mutation engine and standalone recovery runtime |

Thread 0 lands first. The five execution threads then fork from the exact same
foundation commit into separate Git worktrees. They may develop concurrently,
but they merge through the ordered integration process in this document.

## Architectural boundary

Duo currently has three runtime deployables and two build-time input systems:

```text
Host CLI (`cli/`)
    | versioned command/result bytes over local, Docker, or SSH transport
    v
WordPress agent (`agent/`)

Host CLI (`cli/`)
    | versioned recovery protocol and signed receipts
    v
Recovery runtime (`recovery/`)

Reviewed declarations (`manifests/`) --> agent policy/capability/evidence
Build/test/certification (`scripts/`, `sandbox/`, Makefile, CI) --> all artifacts
```

The target dependency rules are:

1. The host CLI communicates with the agent through versioned bytes, not new
   source imports.
2. The host CLI communicates with recovery through the recovery protocol, not
   recovery implementation internals.
3. The WordPress agent owns WordPress-dependent behavior. WordPress globals
   are allowed only in its composition and infrastructure boundaries.
4. Recovery remains standalone: it does not depend on WordPress, agent, or CLI
   implementation code.
5. Manifests and generated evidence are inputs. They do not depend on runtime
   implementations and cannot certify themselves.
6. Test and build tooling may depend on production modules; production modules
   never depend on test or development tooling.
7. Existing CLI-to-agent source imports are compatibility debt. This round
   centralizes them behind one documented bridge and prevents new breaches;
   it does not duplicate those classes into a second implementation.

Cross-deployable sharing is not assumed to require a common runtime library.
Thread 1 may introduce a small first-party shared support package only after
the built-artifact layout and certification closure are proven. Wire schemas,
golden bytes, and protocol fixtures are the primary cross-process contract.

## Compatibility freeze

After Thread 0's safety-disposition gate, the following remain compatible
throughout this round unless the owner approves a separate behavior-change
proposal:

- `duo` and `wp duo` command names, options, defaults, exit codes, human output,
  and JSON output.
- Repository format v2, including `site.duo.json`, canonical state/media bytes,
  stable identity rules, scope contracts, and compilation hashes.
- Environment registry and provider request/result formats.
- Capability registry format, generated capability prose, disposition meaning,
  and all current platform-certification claims that survive the safety review.
- Agent/host version discovery and current command transport behavior.
- Plan, apply, promotion, lifecycle, journal, and receipt formats.
- Checkpoint, code, upload, effect, and rollback-control protocols and
  signatures.
- Existing refusal codes, messages, redaction, and remediation semantics.
- Deployment ordering, locks, fencing, drift checks, recovery gates, and all
  fail-closed behavior.

Compatibility never protects a confirmed false claim, unsafe production
mutation, secret/PII exposure, or recovery-authority drift. Those are reduced
or quarantined before their fixtures become the refactor baseline.

Refactoring may add private/internal APIs. A public or persisted format change
requires its own versioned proposal, dual-reader or migration strategy, golden
fixtures, and explicit evidence-impact review. It is not hidden inside a move
or rename.

## Product-spec constraints inherited by every thread

The round preserves the following rules from the
[ratified product specification](../product-spec.md):

- Unknown or unproved behavior blocks rather than degrading to approximate
  success.
- A declaration, manifest, test run, refactor, or agent cannot certify itself.
- Readiness, certification provenance, handling, effects, recovery, and actor
  authority remain distinct concepts. Legacy fields may not be mechanically
  upgraded into the target model.
- Product terminology cannot create an alias claim. Low-level engine verbs keep
  their current names and meanings during this round.
- Plugins and themes remain unmodified.
- Secrets and environment bindings never become portable repository state.
- Deploy-before-apply, durable receipts, fencing, and literal recovery claims
  are not weakened.

The target product model should influence names and dependency direction. It
must not be emitted by current APIs until the enhancement round supplies the
required evidence, attestation, environment, effect, recovery, and authority
semantics.

## Foundation deliverables

Thread 0 produces a short, reviewable foundation series before the fan-out and
tags its final commit as the common base. It may coordinate minimal safety
fixes/quarantines required below, but it does not decompose application
behavior.

### F0. Public-contract inventory

Create one reviewed command-contract schema shared by host and agent, plus an
inventory mapping every public/persisted surface to:

- producing component;
- consuming component;
- schema or informal shape;
- canonicalization profile;
- current tests;
- evidence/certification inputs;
- compatibility owner.

The command schema includes arguments, flags/defaults, environment/repository
requirements, terminal width, locale, TTY/color profile, stdout/stderr, exit
codes, mutation boundary, and human/JSON fixture identities. Thread 0 owns the
schema. Thread 2 owns separate host-command entry files and Thread 3 owns
separate agent-command entry files; Thread 1 owns validation and a generated
read-only aggregate. No two threads populate one source file. Help, guide, and
golden checks consume the entries through that aggregate.

After F1a's safety disposition and before fan-out, capture critical golden
bytes for repository, registry, evidence, plan, receipt, and recovery authority
boundaries. No compatibility fixture is accepted before that disposition.
Each execution thread completes detailed characterization of its owned surface
before its first extraction. Different canonical JSON domains remain different
unless byte equivalence has been proven. Pretty versus compact JSON is not
sufficient to describe a signed or digested format.

### F1. Baseline and evidence-impact record

From a clean checkout, record an aggregate starting baseline:

- `make release-gate` result and elapsed time;
- the complete offline-suite result and total time if practical with the
  current runner, otherwise the interrupted duration and retained diagnosis;
- relevant live/certification commands and their prerequisites;
- current directional host/agent/bootstrap timings;
- shipped artifact contents and sizes;
- current certification subjects affected by each top-level path.

Thread 1 adds per-suite timing and controlled WordPress/FPM benchmarks before
setting performance budgets. The current CI timeout is five minutes while the
offline corpus has grown to
238 suites. A manually interrupted audit run was still active after roughly
fourteen minutes; Thread 1 must measure a clean completion before selecting a
new timeout or concurrency strategy.

`ScopedCertificationBundle::subjectInputPaths()` currently includes broad
agent, CLI, test, and build trees. Therefore “refactor only” is not an evidence
exemption: most runtime changes will invalidate every current subject bundle.
Thread 0 records that fact and owns the final impact decision.

It currently omits `recovery/**` even though current promotion claims consume
the recovery path. Before tagging the common base, the foundation either lands
a Thread 4-authored correction that binds the relevant recovery runtime or
conservatively withdraws the affected claim. Generated loaders, classmaps,
first-party shared packages, build manifests, and evidence-producing shared
test helpers likewise enter closure before a current claim can depend on them.

This program chooses an explicit current-platform evidence-closure migration
before production switches to built artifacts. Thread 0 ratifies the closure-v2
schema and required input categories; Thread 4 implements its source/recovery
slice first and its artifact-binding slice after Thread 1 freezes the dist
layout. Closure v2 binds the exact content-addressed runtime/declaration payload
components and normalized build definition, every deployed first-party runtime
dependency (including recovery and generated loaders), and the exact test plan,
harness, oracle, helper, PHP, WordPress, database, container-image, dependency,
provider/configuration, environment-fingerprint, and execution-receipt
identities on which the current evidence depends. The signed evidence and
generated claim projection form a detached authority pack that names those
payload digests; they are not embedded in the payload they certify. Archived v1
records remain parseable and cryptographically verifiable for audit, but
incomplete v1 closure cannot keep a claim current. New candidate claims use v2
and require full recertification. If that migration is not approved, the dist
cutover is deferred rather than shipping bytes outside evidence authority.

Closure identities use one canonical redacted schema. Evidence and run receipts
never serialize credentials, raw secrets/PII, machine-local paths, raw
low-entropy values, or unkeyed digests of those values. Where identity binding
is necessary, they store a reviewed secret-reference ID or domain-separated
keyed digest plus key ID, never the secret or key. Adversarial fixtures prove
redaction and stable comparison.

### F1a. Safety disposition before compatibility fixtures

The foundation thread must resolve each known safety candidate as one of:

1. disproven by executable evidence;
2. fixed by a minimal, separately reviewed safety patch; or
3. quarantined behind a loud refusal until the enhancement round supplies the
   missing proof.

The initial mandatory review covers:

- Experimental/unproved Capture may not publish an operator's authoritative
  portable repository, regardless of environment. Apply/Delete may run only in
  an independently proven isolated qualification/test harness whose outputs are
  explicitly non-authorizing and cannot be adopted or promoted. Do not infer
  safety from a mutable disposable flag.
- PII discovery coverage across every currently capturable certified or
  experimental surface, including forms, membership data, post/media/user
  metadata, options, and custom tables. Unknown sensitivity quarantines that
  surface from portable Capture; fixtures do not golden-lock incomplete
  detection as acceptable.
- Platform capability claims whose declared version range is broader than
  exact evidence, including unresolved generated evidence references. A
  conservative claim reduction is allowed without waiting for registry v3.
- Recovery-provider/profile drift between the reviewed current plan and the
  last pre-mutation check. Durably bind the selected current provider/profile
  and relevant configuration identity in a versioned promotion
  precondition/session record, then reselect and compare that exact decision
  before mutation and on forward resume. Drift refuses. Legacy unbound records
  remain readable for recovery/reconciliation but cannot silently authorize new
  forward mutation. This does not claim a product-level ReleasePlan.
- `env materialize` from third-party/production-derived snapshots. Until
  least-data handling, encryption, credential stripping, default-deny egress,
  retention, and secure deletion are proven, restrict it to approved synthetic
  fixtures or refuse.
- Portable repository writes outside the existing atomic publication protocol,
  including direct `Canon::write_file()` use. Prove the call is non-authoritative
  or route it through an old-or-new-visible atomic writer with failure tests.

These are safety corrections, not product enhancements. Their patches remain
small and do not introduce target readiness/provenance enums, site
attestations, public product operations, or broader authorization machinery.
The post-disposition behavior becomes the characterization baseline.

Thread 0 owns the disposition record and serial integration, not every touched
production file. Before the common-base tag, the prospective owner is the sole
writer of each safety slice: Thread 2 for environment materialization, Thread 3
for Capture/PII/publication, Thread 4 for claims/evidence closure, and Thread 5
for recovery-decision binding. Those reviewed slices land serially on the
foundation branch; they are not five concurrent edits to shared files.

### F2. Ownership ledger

Ratify an exhaustive path ledger before opening the worktrees. Initial
ownership is:

| Path or concern | Writer |
|---|---|
| `Makefile`, `.github/**`, root dev-tool configuration, test-runner mechanics, build scripts | Thread 1 |
| `agent/duo.php`, `agent/duo-loader.php`, generated runtime loaders | Thread 1 |
| `cli/duo`, generic host commands, transports, environments, refresh/rebase | Thread 2 |
| Host adapter/catalog authoring modules | Thread 4 |
| Host plan/deploy/promotion/rollback modules | Thread 5 |
| Agent command, Init, observation, Capture, canonical repository projection | Thread 3 |
| Agent policy, grammar, adapters, providers, capability registry, evidence | Thread 4 |
| Agent plan/apply/deploy/code/promotion/lifecycle and `recovery/**` | Thread 5 |
| Capability registry JSON, `docs/capabilities.md`, README marker, compatibility baseline | Thread 4 |
| `docs/adoption.md` and host operational docs | Thread 2 |
| Recovery/code-release docs and code-update guide | Thread 5 |
| Developer/sandbox workflow docs | Thread 1 |
| Command-contract schema | Thread 0 |
| Host command-contract entries | Thread 2 |
| Agent command-contract entries | Thread 3 |
| Generated command-contract aggregate and validation tooling | Thread 1 |
| `scripts/foundation-check`, `scripts/ownership-check`, `scripts/contracts-check` | Thread 0 |
| `scripts/evidence-impact`, `scripts/evidence-staleness-check` decision logic | Thread 4 |
| `spec/repo-format.md` and other product-contract prose | Thread 0 unless explicitly delegated |
| `sandbox/tests/**` semantic assertions and `sandbox/fixtures/**` semantic data | Thread owning the cataloged behavior |
| Test catalog, runner, sharding, shared harness, compose/workspace infrastructure | Thread 1 |

The execution charters provide initial allowlists for mixed directories.
Thread 0 turns those into one exhaustive file ledger and resolves any overlap
before fan-out. Cross-domain files such as `Canon.php`, `CommandRefusal.php`,
`Db.php`, `Ledger.php`, `ScopeContract.php`, `ScopeClosure.php`, `Deletion.php`,
and `CompiledArtifact.php` receive one writer plus named reader interfaces; no
thread treats shared use as shared write ownership.

The ledger also declares allowed prefix-to-facade dependency edges and the few
composition roots allowed to instantiate concrete adapters. `contracts-check`
rejects a concrete reverse edge or implementation import outside those roots.
An exception names exact source/target prefixes, owner, rationale, and removal
condition; a broad directory wildcard is not an architecture rule.

### F2a. Break current dependency cycles before fan-out

Thread 0 lands only the mechanical/interface extractions needed for the five
owners to be real:

- Split `NativeActions.php`: an inert catalog/grammar facade owned by Thread 4
  and a WordPress execution/receipt service owned by Thread 5. Policy loading
  must not depend on the mutation executor.
- Extract the pure code descriptor/config/state compatibility leaf under Thread
  3. Thread 4's `CodeConfigGrammar` consumes that leaf instead of Thread 5's
  target-mutation `Code` facade; staging and materialization remain in Thread 5.
- Route every pure repository caller, including `CompiledArtifact` and
  `RepositoryCompiler`, directly to that Thread 3 leaf rather than back through
  Thread 5's `Code` facade. Capture-time baseline/version effects use a Thread
  3-owned port implemented by Thread 5 at the agent composition root.
- Remove `PinResolver`'s repository-compiler dependency: it calls Thread 4's
  artifact-policy identity facade directly, so repository compilation may
  consume that facade without forming a reverse cycle.
- Extract pure version-range evaluation and a Thread 4-owned target-runtime
  inspection port so `AdapterRegistry`/`Providers` no longer call Thread
  5-owned `Deploy` internals. The agent composition root supplies the adapter.
- Establish stable host contract leaves for command output, target invocation,
  transport result, and environment access. Thread 2 owns the contracts and
  keeps compatibility adapters until Thread 5's host modules are integrated.
- Define and fixture a byte-compatible recovery protocol client boundary.
  Thread 5 owns its implementation; Thread 2 removes the direct
  `rollback-control.php` import during integration.
- Assign exclusive new-directory prefixes for platform, host, agent/repository,
  capability, mutation, and recovery modules. A new file belongs to its prefix
  owner, never to a catch-all rule.

The old facades remain while callers migrate. These slices pass the same
fixtures before and after and add no new product semantics.

### F3. Hot-file and generated-file protocol

Hot files have section owners:

- Thread 1 owns agent loader/bootstrap regions.
- Thread 2 exclusively owns `cli/duo` and host-side wiring.
- Thread 3 exclusively owns `agent/src/Cli.php` and agent-side wiring.
- Thread 4 exclusively owns the capability generator and generated capability
  outputs.
- Thread 5 exclusively owns `recovery/rollback-control.php`.

A consumer thread exports a tested class and submits an integration request;
the hot-file owner lands the wiring. Two branches do not independently edit
the same dispatch table.

Generated files are never hand-merged. Rebase on the integration branch,
regenerate with the owning generator, and review the semantic diff.

### F4. Refactor mechanics

Every thread follows these mechanics:

- Prefer an additive facade, delegation, and deletion sequence over a big-bang
  move.
- Preserve the old class/function entrypoint until all callers migrate.
- One class per file for new code; `declare(strict_types=1)` for new pure code.
- Validate and coerce WordPress/process/JSON values at boundaries; use typed
  objects internally where they reduce ambiguity.
- No new service locator, global mutable registry, static cross-component
  singleton, or copy-pasted codec.
- No arbitrary file-size targets. Extract around behavior, state ownership,
  side effects, and independently testable decisions.
- No repository-wide namespace, formatting, or `strict_types` sweep.
- No new behavioral tests that assert source text. Architecture/source checks
  belong in one explicit static-checking layer.
- A moved test must still fail when its original seeded defect is restored.

Foundation artifacts are machine-checked before fan-out. Thread 0 permanently
owns the foundation/ownership/contract policy scripts, Thread 4 owns evidence
impact/staleness decision logic, and Thread 1 owns only their stable Make/CI
wrappers and report presentation:

```text
scripts/foundation-check
scripts/ownership-check
scripts/contracts-check
scripts/evidence-impact --base-sha=<40hex> --head-sha=<40hex>
scripts/evidence-staleness-check --report=<path>
```

The ownership check compares the ledger with tracked production files and
fails on an unowned or multiply owned path. The evidence-impact decision logic
belongs to Thread 4's reviewed closure implementation; Thread 1 owns only its
runner/CI wrapper and report presentation. While an integration branch is
intentionally stale, `evidence-staleness-check` passes only when the actual
stale-subject set exactly equals the reviewed impact report and emits a
machine-readable `non_releasable` result. It never makes `release-gate` pass.

### F5. Branch and integration protocol

Use a long-lived `refactor/integration` branch based on the foundation commit.
Create one worktree per execution thread. Do not run five agents against the
same checkout. Thread 1 lands a minimal platform P0—test catalog/runner,
contract-check wrappers, and the new-file loading convention—immediately after
foundation. Threads 2–5 rebase onto that slice before changing production
code; all five then continue in parallel.

Each thread delivers small reviewable slices and maintains:

- a compatibility checklist;
- tests run and elapsed time;
- files/interfaces exported;
- foundation amendments requested;
- evidence-impact report;
- deferred cleanup.

Development can be parallel, but integration is reader-before-writer and
facade-first. The expected merge train is:

1. Thread 1's platform P0 and dev-only test/tooling foundation.
2. Thread 4's stable policy/capability facades and corrected source/recovery
   closure membership.
3. Thread 3's agent/capture core and root extraction.
4. Thread 5's mutation/recovery facades and modules.
5. Thread 3's narrow `Cli.php` integration of Thread 5's exported handlers.
6. Thread 1's artifact producer/layout, without claiming adoption proof yet.
7. Thread 4's closure-v2 artifact/build binding against that frozen layout.
8. Thread 2's host composition, artifact-consuming adoption, and cross-runtime
   wiring.
9. Thread 1's final reproducibility, dist, loader, and adoption-from-dist gates.
10. Thread 0's full verification and evidence recertification cutover.

If a branch needs another branch's new interface, it codes to the ratified
interface fixture or waits for that narrow slice. It does not copy the
implementation.

### F6. Evidence and release cutover

No thread regenerates or signs durable production evidence merely to make its
branch green. Each branch reports which evidence is stale. The integration
branch is explicitly non-releasable while impacted subjects are stale; a
release-gate failure is expected only when it matches the reviewed impact
report. Do not alter the current projection merely to conceal that staleness,
and never publish the integration branch or merge stale claims to main.

Current evidence binds the exact Git revision, while this repository's close
gate enforces squash-only PR merges. A branch candidate commit and its child
evidence commit would both disappear from fresh main history after squashing.
The owner must choose before fan-out:

1. a one-time fast-forward or other explicitly approved non-rewriting merge
   that preserves the exact frozen runtime commit and its evidence child as
   reachable ancestors, with a matching close-gate verifier; or
2. a separately reviewed certification-identity migration.

The preferred operational choice for this round is the explicit merge-policy
exception. Do not pretend a squash-produced SHA is the candidate that existing
evidence reviewed.

The evidence child must have the frozen candidate as its direct parent and may
change only an approved evidence/claim-projection allowlist. The close gate
verifies that relationship, the imported bundle revisions and certified
payload digests, and the absence of runtime, declaration, build-definition, or
other certified-payload changes. Both exact commit objects must remain
reachable from fresh `main`.

Evidence cannot recursively certify an archive containing itself. The frozen
candidate produces content-addressed host and target runtime/declaration
payloads. The evidence child adds a detached authority pack that binds those
payload digests. A detached release-set manifest then binds the agent, recovery,
reviewed declarations, and authority-pack components plus both source commits.
A composite target-install archive may carry that verified set for one atomic
install, but the release-set manifest is excluded from its own digest and is
distribution integrity metadata, not a self-certifying input.

After all runtime changes stabilize:

1. Freeze one reachable integration commit.
2. Build and retain the exact content-addressed runtime/declaration payloads
   from that commit.
3. Run the full offline, live, conformance, recovery, adoption, and failure
   suites required by the impact report.
4. Generate candidate evidence from that exact commit.
5. Have the current evidence/disposition review authority review and import it
   in the direct evidence-only child.
6. Assemble the detached release-set manifest and composite install archive
   from the retained payload digests and detached authority pack. Run
   release-gate and distribution smoke tests against those exact bytes; do not
   rebuild the runtime from the evidence-child HEAD.
7. Restart impact calculation if any runtime, declaration, build-definition, or
   certified payload byte changes after the freeze. Only the reviewed detached
   evidence/projection diff belongs in the child.

Longer term, evidence closure should become dependency-bound so an unrelated
tooling change does not invalidate every subject. Changing that authority is a
separate reviewed migration, not an incidental refactor.

## Stop-the-line exception

If a thread discovers behavior that can silently corrupt authored state,
mutate production without existing authority, expose secrets/PII, or make a
false current certification claim, it pauses that path and raises a separate
minimal safety-fix proposal. The fix may land before the refactor continues,
but it must not smuggle broader target-state behavior into this round.

Newly discovered candidates use the same disposition gate. The foundation does
not continue until the affected behavior is proven, minimally fixed, or loudly
quarantined.

## Foundation completion gate

Threads 1–5 may fork only when:

- the public-contract inventory and golden fixtures exist;
- a clean baseline and evidence-impact map are recorded;
- `docs/proposals/refactor-ownership.json` assigns every tracked production,
  semantic-test, fixture, generated-output, and new-module prefix exactly once,
  and `scripts/ownership-check` passes;
- the NativeActions, pure code-contract, target-runtime inspection,
  HostContracts, and recovery protocol cycle-breaking seams are
  fixture-compatible;
- the current recovery omission is corrected or its affected claims are
  withdrawn, and the closure-v2 schema/input set is approved for the later
  artifact-binding slice;
- artifact layout, PHP syntax floor, and certified runtime profile are
  separately documented;
- the exact-revision certification/merge-policy ruling is approved;
- hot-file and generated-file protocols are accepted;
- the integration branch and five worktrees are created from one commit;
- every known safety candidate has executable evidence, a minimal fix, or a
  loud quarantine, and only the resulting behavior is fixture-frozen;
- the owner approves the compatibility freeze and merge train.

Threads 2–5 may begin inventory/tests immediately after fork but do not edit
production files until Thread 1's platform P0 lands on the integration branch
and their worktrees rebase onto it.

## Explicitly deferred

- The six-dimensional target capability projection and registry v3.
- New product vocabulary mapped mechanically from legacy fields.
- `duo assess`, Rehearse, product Release/Verify, public Recover, and Qualify.
- Actor/org-policy authorization and signed site attestation.
- New recovery-profile or effect semantics on public/persisted formats.
- Repository-format v3 and application-contract grammar.
- A generalized framework, package ecosystem, web UI, or service layer.
- Fleet, hosting, marketplace, and public ecosystem work.

Those become the enhancement round built on the seams produced here.
