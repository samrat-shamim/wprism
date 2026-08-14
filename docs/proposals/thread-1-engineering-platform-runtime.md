# Thread 1 — Engineering platform and runtime composition

*Status: proposed for owner approval.*

*Depends on: [Thread 0](thread-0-foundation.md).*

*Primary rule: improve the development and loading system without changing application behavior.*

## Goal

Give every development thread a fast, reproducible, trustworthy feedback loop
and replace hand-maintained production loading with deterministic build
artifacts and bounded boot paths.

The shipped host CLI, WordPress agent, and recovery runtime remain
self-contained and third-party-runtime-free. Copied first-party support code is
still a declared dependency and enters the artifact/evidence manifest. Composer
and quality tools are development/build inputs, not host requirements.

## Why this thread exists

- The agent bootstrap has 91 direct requires and a 223-file transitive load
  graph before deciding whether the request needs journal or CLI behavior. A
  directional cold-process benchmark showed roughly 50 ms and 12 MB additional
  RSS; a real FPM/OPcache benchmark is required before setting the production
  budget.
- The host CLI has another manually ordered load graph, and several files add
  their own conditional or nested requires.
- Only 46 of 282 PHP module files under `agent/src`, `cli/src`, and `recovery`
  currently declare strict types.
- The Makefile is approximately 1,950 lines and manually indexes a 238-suite
  offline corpus.
- The offline aggregate currently runs longer than the workflow's five-minute
  timeout. The diagnostic wrapper buffers output and discards it when an
  interrupted run exits.
- The guide-command checker passes independently but has no Make or CI target.
- There is no Composer project, conventional unit runner, static-analysis
  ratchet, architecture gate, test metadata catalog, or deterministic release
  build.
- Public docs say “PHP 8+”, source now contains PHP 8.2-only syntax, and the
  certified profile remains PHP 8.3.x. Those are three different contracts.

## Owned surface

Thread 1 owns:

- `Makefile` and new developer-facing task wrappers;
- `.github/**`;
- root Composer, PHPUnit, PHPStan, formatter, architecture, ShellCheck,
  shfmt, actionlint, and editor configuration;
- test catalog, runner, sharding, log retention, and JUnit/TAP mechanics;
- `sandbox/lib/**` and test-infrastructure code, but not another thread's
  semantic assertions;
- build/distribution scripts and artifact manifests;
- `agent/duo.php`, `agent/duo-loader.php`, and generated agent loaders;
- `docs/sandbox.md` and developer documentation for setup, testing, building,
  and CI;
- performance benchmark harnesses.

Thread 1 does not own `cli/duo`, `recovery/rollback-control.php`,
`cli/src/Adopt.php`, or application command behavior. It exports boot/build
entrypoints and asks Threads 2 or 5 to wire their owned files.

Thread 0 permanently owns the foundation/ownership/contract policy scripts and
Thread 4 owns evidence impact/staleness decision scripts. Thread 1 owns their
Make/CI wrappers and presentation, not the underlying policy logic.

The Thread 0 ledger assigns future build/tooling, generated-loader, test-runner,
and shared test-harness prefixes exclusively to this thread. It does not grant
ownership of semantic production modules merely because the build consumes
them.

## Deliverables

The opening Platform P0 slice contains the reproducible dev bootstrap, catalog
schema/serial runner, conservative import/classification of every existing
suite, foundation/contract/ownership/staleness check wrappers, validated
nonempty thread gate profiles, and the new-file loading convention. Its
compatibility check proves the imported suite set matches the legacy aggregate
and live lists exactly; unknown suites and empty selections fail. It lands on
`refactor/integration` before Threads 2–5 edit production code.
Parallel/resource-aware execution, CI redesign, and the production loader/dist
cutover follow without blocking their inventory and characterization work.

P0 discovery inventories every executable or test-shaped file under the current
test trees plus every test/certification Make target. Helpers and self-tests are
explicitly classified and linked to their calling suites; nothing is silently
excluded merely because its filename does not start with `regress_`.

### 1. Reproducible developer bootstrap

Add a dev-only Composer project and committed lock file. Select tool versions
that can exercise both the PHP 8.2 syntax floor and the PHP 8.3 certified
profile. Provide non-interactive commands for:

```text
make doctor
make bootstrap-dev
make foundation-check
make ownership-check
make contracts-check
make guide-check
make canonical-contract-check
make recovery-transition-check
make evidence-impact BASE_SHA=<40hex> HEAD_SHA=<40hex>
make evidence-staleness-check REPORT=<path>
make format-check
make lint
make test-unit
make test-offline
make test-changed BASE_SHA=<40hex> HEAD_SHA=<40hex>
make test-component COMPONENT=<name>
make test-integration SUITES=<catalog-ids>
make test-conformance SUBJECTS=<names>
make verify-generated
make build
make dist-check
make reproducibility-check
make loader-check
make perf-smoke
make perf-budget
make check
make audit
```

`make doctor` is read-only. It reports missing tools and version mismatches; it
does not install packages, pull containers, or mutate the workspace.

`make check` is offline after bootstrap. Network-aware advisory and supply-chain
checks run through explicit `make audit` and their visible CI lane. Composer
packages never appear in deployed runtime artifacts unless a future proposal
explicitly changes the dependency policy.

The command DAG is explicit rather than encoded through surprising Make side
effects:

| Command/profile | Network or durable mutation | Composition and result |
|---|---|---|
| `bootstrap-dev` | Network and workspace write are explicit | Install only lock-pinned dev tools; emit tool/lock receipt |
| `doctor` | None; read-only | Report prerequisites and versions; never bootstrap |
| `check` | None after bootstrap; temp roots only | Format/lint, unit/offline, foundation/ownership/contracts, guide/canonical/recovery-transition, generated drift, and loader checks |
| component profiles | Declared by catalog | Nonempty owned suite set plus relevant static/contract checks |
| integration/conformance profiles | Exact catalog declaration | Isolated live/destructive suites only when their environment and authority class permits |
| `build`/`dist-check`/`reproducibility-check` | Dist/temp writes; no network after locked builder acquisition | Clean-tree payload production, trust/contents verification, and two-root byte comparison |
| `perf-smoke`/`perf-budget` | Temp output; budget profile uses pinned service resources | Harness health locally; ratified median/p95 gate only in controlled infrastructure |
| PR profile | None unless a separately visible live job is selected | `check`, resolved-SHA change selection, build/dist smoke, and exact evidence-staleness result |
| frozen-candidate profile | Explicit live/destructive authority jobs allowed | Full impact-selected corpus, reproducible build, dist/adoption/recovery/performance gates, and network audit |
| evidence-child profile | No new runtime build or target mutation | Parent/diff/authority checks, generated agreement, `release-gate`, and smoke of retained exact release-set bytes |
| `audit` | Network, read-only | Advisory/supply-chain policy result and canonical audit receipt; never part of offline `check` |

Every command declares prerequisites, permitted writes/network, structured
outputs, and exit semantics in the developer-command contract. Gate-profile
files list their exact commands and catalog IDs; Make dependencies do not add
hidden work.

### 2. Explicit test catalog and trustworthy runner

Replace naming/list inference as the authority with a checked-in catalog. Each
suite declares at least:

```text
id
command/argv
layer
owner
timeout
parallel safety
resource locks
temporary-directory policy
workspace mode: read_only | isolated_copy | exclusive
required tools/services
covered paths or contracts
evidence inputs
evidence role
required review gate
```

The catalog contains checked-in `platform-p0`, `component-engineering-platform`,
`component-host-cli`, `component-wordpress-agent`,
`component-capability-policy-evidence`, `component-mutation-recovery`, `pr`,
`frozen-candidate`, and `evidence-child` gate profiles.
A profile declares its suite IDs, environment class, blocking semantics,
expected result/artifacts, and whether evidence staleness is permitted. Unknown
IDs, an empty required profile, a live suite in an offline profile, or a missing
expected output fails validation. `make test-component` resolves only through
these profiles; ad hoc suite lists remain explicit integration diagnostics, not
an authority gate.

A discovered suite absent from the catalog fails validation and does not run.
Imported legacy suites may explicitly declare `parallel_safe: false` and an
exclusive workspace/resource lock. Live, destructive, or credentialed suites
never enter the offline default.

The runner provides:

- streamed, suite-prefixed output;
- unique temporary directories;
- separate checkouts for CI shards and isolated copies or exclusive locks for
  local suites that touch fixed repository or `/tmp` paths;
- process-group timeouts and cleanup;
- retained logs on success, failure, or interruption according to policy;
- per-suite and aggregate timing;
- JUnit or TAP output;
- a canonical run receipt binding candidate SHA and dirty state, catalog and
  gate-profile digests, suite IDs and normalized argv, shard plan,
  tool/lock/image and platform digests, a redacted environment fingerprint,
  result/timeout/signal/cleanup state, and log/artifact digests;
- deterministic, duration-balanced shards;
- resource-aware concurrency rather than raw `make -j`;
- a conservative resolved-SHA changed-file selection command whose output is
  advisory and falls back to a broader gate when mapping is incomplete. It
  accepts only full existing commit IDs, rejects missing/shallow/non-ancestor
  pairs, never fetches implicitly, and has CI pass the event's exact base/head
  SHAs. Any explicit path input uses a response file or NUL-safe mechanism
  rather than a shell-split Make variable.

Existing Make targets remain compatibility wrappers during migration. Existing
suite assertions do not change merely to fit the runner.

Run-receipt normalization follows Thread 0's redacted identity schema.
Secret-bearing arguments or environment values are represented only by reviewed
reference IDs or domain-separated keyed bindings; raw credentials, content/PII,
host paths, and low-entropy hashes never enter receipts or logs.

`guide-check` validates command names, flags, defaults, and cited examples
against the shared command-contract aggregate and retains the existing
checker self-test. `canonical-contract-check` owns canonical profile/call-site
coverage; `recovery-transition-check` owns transition/fault-matrix coverage.
All three are stable wrappers with catalog entries and structured results.

### 3. Unit and static-analysis layers

Introduce PHPUnit for pure, WordPress-free behavior. Start with new modules,
canonical/protocol fixtures, recovery value logic, parsers, and state-machine
decisions. Preserve the existing regression corpus as characterization and
acceptance evidence; do not rewrite it wholesale.

Adopt a ratchet:

- new pure code: `strict_types`, one class per file, highest configured PHPStan
  level, and no baseline entries;
- legacy trees: separate reviewed baselines with unmatched-ignore detection;
- baseline counts may only shrink;
- formatting is enforced for new/touched governed code;
- no repository-wide style commit during the parallel round;
- source-text assertions are permitted only in centralized architecture/static
  checks, not new behavioral tests.

Add syntax, JSON/schema, ShellCheck, shfmt, actionlint, dependency-boundary,
generated-drift, and secret/artifact hygiene checks.

### 4. Truthful CI lanes

Refactor the current workflow into independently visible lanes:

**Always-run pull-request policy lanes**

- quality: format, syntax, static analysis, shell/action checks, schemas,
  boundaries, generated drift, Composer validation and lock integrity;
- guide-command validation whenever guides or cited command surfaces change;
- unit on PHP 8.2 and 8.3;
- catalog-driven offline shards;
- deterministic distribution build and smoke load;
- impact-selected conformance, promotion, receipt, and recovery gates whenever
  changed paths intersect those contracts;
- an evidence-staleness gate that passes only when the actual stale-subject set
  exactly matches the reviewed impact report and marks the branch
  non-releasable; ordinary `release-gate` remains failing until the evidence
  child, where it becomes a blocking profile step;
- an aggregate PR verdict that cannot hide a skipped/failed prerequisite.

**Nightly or scheduled lanes**

- full live integration;
- conformance matrix;
- recovery/failure/grind suites;
- full guide-command validation as a scheduled backstop;
- performance history and flake reporting.

**Explicit authority/on-demand lanes**

- SSH and evidence-producing certification workflows against an exact
  candidate commit;
- candidate artifacts uploaded for the current evidence/disposition review
  process;
- no automatic conversion of ordinary CI success into certification.

**Explicit network-aware audit lane**

- Composer/security advisory checks and controlled dependency/image update
  checks, with network access visible in the job definition.

`make audit` has three distinct structured outcomes: clean (`0`), vulnerability
or supply-chain policy failure (`1`), and audit unavailable/network failure
(`2`). Unavailable is never reported as clean and blocks a frozen candidate or
release. The receipt records advisory database/provider identity, retrieval
time, tool/lock digests, and redacted findings; ordinary PR policy shows the
network lane separately from offline correctness.

Begin with timeouts based on measured completion, then reduce them when p95
history supports it. Add least-privilege permissions, bounded artifact
retention/redaction, and immutable action references. Cancellation applies only
to superseded PR policy jobs keyed by workflow and PR; it never cancels main,
nightly, frozen-candidate, evidence-producing, or evidence-review runs. Cache
only immutable digest/lock-keyed dependencies, verify every hit, forbid fork-PR
cache writes, and never treat a cached dist artifact, evidence bundle, or run
receipt as authority. Pin WordPress, PHP, database, and other sandbox images by
multi-arch manifest digest and update them through a controlled scheduled
process. PR jobs must not require secrets.

The current repository tier cannot bind required status checks. Document the
manual merge policy honestly; call a lane “required” only if repository
enforcement later exists.

Implement the Thread 0-approved final integration merge exception with a
separate close-gate verifier. It must prove that the evidence child's direct
parent is the frozen runtime candidate; its diff is confined to the approved
evidence/claim-projection allowlist; imported bundle revisions and payload
digests name that candidate; no runtime, declaration, or build input changed;
and both exact commits are reachable from fresh `main`. Ordinary PRs continue
using the existing squash close gate.

### 5. Deterministic artifacts

Ratify a source-to-distribution layout before adding shared runtime code. The
build uses explicit allowlists and produces a host CLI artifact plus separately
content-addressed agent, recovery, and reviewed-declaration payload components.
After evidence review, a detached authority pack contains signed evidence and
generated claims that name those payload digests. A detached release-set
manifest binds every target component, the authority pack, and both the frozen
candidate and direct evidence-child commits. A composite target-install archive
may carry that verified set so adoption remains an atomic, version-consistent
operation. Each component records file paths, content digests, source commit,
format/build version, and required PHP/runtime extensions; the release-set
manifest is excluded from its own digest.

Two clean builds from the same source and locked inputs must produce identical
bytes. The builder itself is a digest-pinned image/toolchain. Rebuild in two
different absolute roots with fixed locale, timezone, umask, and normalized
file ordering, modes, timestamps, uid/gid, and archive metadata; compare every
payload, component manifest, authority-pack, release-set, and composite byte.
Refuse release builds from a dirty tree. The manifest is detached or excluded
from its own digest. Dist metadata distinguishes required extensions such as
JSON/Sodium/fsync from optional acceleration.

The artifact is content-addressed. At every trust boundary, consumers verify
the actual artifact bytes against its manifest; a locally generated manifest
alone is metadata, not authority. `make dist-check` proves:

- no `vendor/`, dev tools, tests, caches, local paths, credentials, or
  undeclared files ship;
- no unsafe symlink escapes exist;
- every manifest digest matches bytes;
- the configured trusted authority-pack/signing key validates the expected
  payload digest, and that digest validates the actual component bytes;
- a caller-supplied expected release-set digest matches the detached release-set
  manifest—co-located archive and manifest consistency alone is insufficient;
- artifacts load from outside the source checkout;
- agent adoption and recovery installation accept an explicit artifact and
  fail rather than falling back to the source checkout;
- a shared first-party support package, if introduced, is copied into every
  consuming artifact and included in evidence closure before use.

The detached authority pack avoids a recursive artifact digest: certification
binds exact runtime/declaration payloads, while the detached release-set
manifest binds the authority pack for transfer integrity. The authority pack
cannot certify itself or the composite archive merely by naming it.
Reproducibility checks rebuild the runtime payloads from the frozen candidate
and the projection pack from its direct evidence child, then compare every
component, release-set, and composite digest.

Runtime code must never assume a sibling repository checkout.

### 6. Loader and boot-path refactor

Build a generated legacy classmap plus PSR-4 loading for new namespaced code.
Do not assume existing multi-class files are PSR-4-compatible. Longest namespace
prefixes resolve before broader ones.

The WordPress agent gets three explicit paths:

1. normal web request with journal disabled: loader guard and no engine graph;
2. journal-enabled web request: only journal dependencies;
3. WP-CLI/control plane: command-selected lazy engine loading.

Before changing those paths, characterize class availability, hook
registration and order, journal enablement, activation behavior, control-plane
behavior, and loader failures on ordinary and WP-CLI requests. “No engine
graph” is accepted only when those observable behaviors remain compatible.
Register the generated lazy autoloader on ordinary requests before removing the
eager graph so an explicit supported Duo class reference does not regress.
Test unsupported PHP with no partial hooks, journal disabled/enabled, WP-CLI,
control-plane bootstrap, explicit lazy class loading, and both source/dist
layouts.

The exact loader matrix is:

- PHP 8.0 and 8.1: the top-level loader parses and returns the documented
  unsupported-runtime refusal before registering partial hooks or loading
  PHP 8.2-only source;
- PHP 8.2: the source syntax floor parses and loads, but no Ready/certified
  support claim follows from syntax compatibility;
- PHP 8.3.x: the current certified profile receives the complete loader and
  application matrix;
- PHP 8.4.x: the loader remains parse-safe, while Doctor/capability evaluation
  reports the current max-exclusive certified-profile mismatch.

`loader-check` exercises every row in both source and dist layouts. Changing a
row requires the compatibility-baseline/evidence process, not an incidental
loader edit.

CLI and recovery receive comparable composition roots, wired by their owning
threads. New application files do not add manual `require_once` chains.

### 7. Performance budgets

Add repeatable benchmarks for:

- ordinary WordPress requests with Duo disabled/enabled;
- journal-enabled requests;
- agent command cold/warm startup;
- host CLI cold/warm startup;
- capability/evidence closure hashing;
- targeted and complete test feedback.

`perf-smoke` is a local harness-health test only: it proves scenarios execute,
measurements parse, and results contain the required environment identity. It
does not enforce a latency/RSS claim. `perf-budget` runs on digest-pinned
WordPress/PHP/FPM/OPcache infrastructure with controlled extensions and
resources. Its checked-in profile sets warmup count, sample count, statistic,
median/p95 absolute budgets, and maximum regression from a versioned ratified
baseline. It fails on either breached budget or an invalid/noisy run; the
initial directional CLI benchmark is never used as that baseline.

Performance caches must not become authorization or certification authority.
A `(path, mtime, size)` tuple is insufficient for byte authority. Mutation,
release, evidence, and certification paths calculate exact content digests or
consume a content-addressed artifact whose bytes are independently verified
against its immutable build manifest. Any cache used for read-only presentation
is labeled as a hint and tested against same-size edits, restored timestamps,
symlink replacement, membership changes, corruption, and concurrent mutation.

## Interfaces exported

- Stable developer command contract and test catalog schema.
- Canonical run-receipt schema and gate-profile result contract; Thread 4 owns
  which receipts and fields enter evidence authority.
- Build artifact manifest and distribution layout.
- Generated loader/classmap interface for each deployable.
- Boundary rules and machine-readable exception inventory.
- Performance result format.
- Evidence-impact command wrapper/report consumed by Thread 0 at integration;
  Thread 4 exclusively owns dependency enumeration and subject-impact logic.

## Constraints

- No application command, JSON, repository, provider, receipt, or evidence
  semantic changes beyond Thread 0's approved pre-freeze safety,
  closure-v2, and artifact-authority migrations.
- No raw parallelism until a suite declares isolation and resource locks.
- No skipping hashes, signatures, conformance, or recovery checks for speed.
- No hidden network access in the fast local gate.
- No new production dependency on Composer or development packages.
- Makefile consolidation preserves useful design commentary, either in place
  or in reviewed developer documentation.
- Thread-specific semantic tests remain owned by their behavior thread.

## Required verification

At minimum:

```text
make doctor
make bootstrap-dev
make foundation-check
make ownership-check
make contracts-check
make guide-check
make canonical-contract-check
make recovery-transition-check
make build
make dist-check
make reproducibility-check
make perf-smoke
make check
```

Loader changes additionally require real WordPress request benchmarks and
journal/WP-CLI/adoption smoke tests. Thread 0 determines the certification
matrix from the evidence-impact report. At the frozen candidate, extend and run
`certify-ssh-adoption-roundtrip` with an explicit dist artifact; it must fail if
it falls back to source-checkout bytes. The frozen-candidate profile additionally
requires `make perf-budget` and `make audit`; branch profiles require the exact
evidence-staleness check, and the evidence-child profile alone requires the
ordinary `release-gate` to pass.

Thread 1's own component gate is
`make test-component COMPONENT=engineering-platform`.

## Done means

- A clean checkout can bootstrap, lint, test, build, and inspect artifacts with
  documented commands.
- Every existing suite is classified and produces retained structured results.
- CI timeouts reflect measured reality and every PR gets a trustworthy verdict.
- New pure code is fully statically analyzed without adding baseline debt.
- Built artifacts are deterministic, self-contained,
  third-party-runtime-free, and evidence-accounted.
- Normal WordPress requests no longer load the full engine graph.
- Existing application behavior and persisted bytes remain compatible.
- Build archives may change only through the approved deterministic artifact
  migration; this line does not require byte identity with legacy source tarballs.

## Explicitly deferred

- Product command/wire redesign.
- New response envelopes or refusal taxonomies.
- Product capability enums or registry v3.
- Repository-format changes.
- Whole-repository formatting or namespace conversion.
- Replacing the complete regression corpus with PHPUnit.
- Automatically minting/importing certification evidence in ordinary CI.
