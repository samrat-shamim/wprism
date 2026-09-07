# Dev setup: from a fresh checkout to a green gate

This is the setup-and-loop guide for people (and coding agents) working *on*
wprism. It is not about running WPrism against a site — that is
[docs/guides/quickstart.md](guides/quickstart.md). Everything below assumes you
will produce local evidence, because **there is no CI: local evidence is the
merge gate** (owner decision, issue #3320).

## The short version

```bash
git clone https://github.com/duotronic-ai/wprism     # NOT --depth 1; see below
cd wprism
bash tools/doctor.sh --fix                            # diagnose + apply the safe remedies
composer install                                      # dev toolchain only; nothing here ships
composer check                                        # lint + phpstan + php-cs-fixer + phpunit
make regress-offline-all                              # THE gate: dynamic global + package aggregate
```

If `tools/doctor.sh` exits 0 with only docker/`gh`/PHP-version warnings, the
checkout is ready. Every FAIL line it prints carries the exact remedy command.

## The one gotcha that costs hours if you meet it cold: a shallow clone

`git clone --depth 1` produces a working tree that is byte-identical to a full
clone, so nothing looks wrong until something asks git a question about
history. `scripts/close-gate-check.sh:43` runs `git merge-base --is-ancestor`
against `origin/main`; without the ancestry in the local object database it
reports a merged head as un-merged, and `regress-close-gate-parent-count` reads
the same history. The message you get names branch state; the cause is the
missing objects.

```bash
git fetch --unshallow
```

`tools/doctor.sh` reports this as a FAIL (`tools/doctor.sh:162-172`, which
states the same cause) but does **not** auto-fix it: an unshallow can transfer
the entire history and that is a decision, not a repair. `--fix` applies
exactly two remedies, `composer install` and `mkdir -p sandbox/tmp`, and
nothing else, ever.

## Shipped bytes — the one rule that bites everybody

`cli/src/Onboarding/Adopt.php` assembles `adapter-packages/*/package/` and
`platform/adapter-library/` into staging's `agent/adapter-library/`, then tars
exactly `agent recovery`. That is the whole list of what reaches a managed
site. Package tests, fixtures, evidence, and integration scenarios never ship.

Inside the assembled agent, adapter package bytes **are** adapter identity.
`ArtifactPolicyIdentity::manifest_rows()` folds each manifest's JSON, its
disposition entry, and `hash_file('sha256', …)` of every interpreter, provider
and regenerator file that manifest names
(`agent/src/Policy/ArtifactPolicyIdentity.php:60-115`). `manifest_hash()`
(`:127`) and each adapter's own `digest` (`:147`) are that same row hashed, and
the class comment at `:53-56` spells out the consequence: a site repo's
per-manifest content pin, `adapter_digest`, `wprism assess`'s reported digest and
the contract that pins it are all one row hashed.

So a one-byte edit to `adapter-packages/<slug>/package/` is a fleet-visible
change. The same rule applies to identity-bearing platform-library inputs. A
deployed site holding a compiled artifact refuses with
`compiled_artifact_manifest_mismatch` — "compiled manifest/interpreter set does
not match active pins" (`agent/src/Repository/CompiledArtifactReader.php:39-42`)
— and every `site.wprism.json` content pin stops matching. The remedy is to
recompile and re-pin (`wp wprism manifest-pin` prints the copy-pasteable object),
never a fallback.

`agent/src`, `cli/src` and `recovery/` carry no such adapter identity: a
namespace rename or a class-file move there moves no digest. Everything else —
`docs/`, `spec/`, `sandbox/`, `scripts/`, `Makefile`, `tools/`, `tests/`,
`vendor/`, `composer.*`, `phpstan*`, `phpunit.xml.dist`,
`.php-cs-fixer.dist.php` — never reaches a site at all.

There is no source closure and no certification round any more: no set of files
whose bytes expire a sealed claim, and no `Makefile`-target tax. What a
capability claim rests on now is [dispositions and live
conformance](guides/capabilities-and-limits.md). The capsule validator checks
the evidence wiring and `make release-gate` validates the complete source set.
`php tools/capability-doc.php render` produces an aggregate projection on
demand without making central prose part of an adapter edit.

### Tooling stays outside the shipped tree — deliberately

`tools/` and `tests/` are dev-only entry points and cannot be reached from a
managed site, which is what makes it safe for the toolchain to depend on
composer while the drop-in itself stays dependency-free
(`tests/Tooling/PhpstanBaselineRatchetTest.php` holds the ratchet). Prefer a
script under `tools/` to a `Makefile` target for anything that is not a suite —
not because a target costs anything now, but because `tools/` entry points take
flags, print diagnostics and have self-tests, and `Makefile` recipes do not.

## The daily loop

| command | what it is for |
| --- | --- |
| `bash tools/doctor.sh` | environment diagnosis; run it whenever anything looks structurally wrong |
| `composer check` | `php -l` + phpstan + php-cs-fixer (changed files) + phpunit |
| `php tools/offline.php -j8` | the whole offline corpus in parallel, one log per suite |
| `php tools/offline.php --changed` | only the suites your diff can affect — **iteration only** |
| `php tools/affected.php --explain` | why each suite was selected |
| `php tools/adapter-package-validate.php --adapter=<slug>` | validate one capsule's closed library, syntax, identity, disposition, and evidence wiring |
| `php tools/adapter-package-tests.php --adapter=<slug>` | run one capsule's package-local offline suites during iteration |
| `make regress-offline-all` | **the canonical merge gate**: shared offline leaves plus dynamic package aggregate |
| `make release-gate` | Capability and grade source validation plus byte-checks for generated protocol/gap/wire documents, classmaps, corpus, and adapter kit |

`tools/offline.php` is strictly stronger than a raw `make -j`: it gives every
suite its own log, applies `offline_diagnostics_guard.sh`'s exact diagnostic
regex *per suite* so the offender is named rather than merely detected, and
keeps the suites that hard-code fixed `/tmp/...` paths in a mutually exclusive
serial group. It is a faster way to run the same work — it is **not** a
substitute for `make regress-offline-all` in a PR: quote the canonical gate.
The fixed `regress-adapter-packages` leaf discovers every capsule and runs its
complete offline gate; adding a package-local test does not add a Makefile row
or change `tools/offline-corpus.mk`.

On stock macOS, `make --version` is GNU Make 3.81, which has no
`--output-sync`; a raw `make -j` there interleaves ~500 KB of suite output into
one unreadable stream. Use `tools/offline.php`.

### Measured wall times

Measured on a full clone (macOS 26.0.1, 10 cores, PHP 8.5.6), `php
tools/offline.php -j8`, zero parallel flakes in either run:

| date | corpus | wall | suite-seconds | effective parallelism |
| --- | --- | --- | --- | --- |
| 2026-08-19 | 251/251 green | 108.65 s | 499.68 s | 4.6x on 8 workers |
| 2026-08-20 | 252/252 green | 112.54 s | 516.47 s | 4.6x on 8 workers |

The second run is the same host after the `sandbox/tests` restructure: moving
a suite into `offline/<domain>/` changes no runtime, and the one extra suite
is `regress-suite-wiring`, which W0 added with the nested layout.

The schedule is bounded below by its single longest suite, so the makespan
tracks that suite rather than the workers' theoretical 8x. The 2026-08-19 top
tier is `regress-pair-bootstrap-unit` 75.17 s, `regress-adopt-rollback`
55.91 s, `regress-scoped-promote-unit` 34.19 s — all three genuinely serial
work (container bootstrap, rollback ordering), not accidental cost, and the
same three in the same order on 2026-08-20 (80.09 s, 57.59 s, 37.02 s).

The certification teardown is visible in this table and worth knowing about if
you are comparing against an older run. The previous measurement on this same
host was 296.05 s wall against 966.68 s of suite time, bounded by
`regress-plugin-adapter-source` at 296.05 s: that suite spawns 53 sequential
PHP children, and each child's `Policy::load()` re-verified all nine
certification closures at roughly 5 s apiece. With the closures gone that suite
runs in **9.75 s** and `regress-adapter-catalog` in **7.09 s**, down from
126.7 s. Roughly two thirds of the old offline corpus's wall time was closure
re-verification, so any pre-teardown timing you find in an old PR is not
comparable to a run today.

## Writing a new offline suite

Put an adapter-owned suite in
`adapter-packages/<slug>/tests/offline/regress_*.{php,sh}`. Validate and run it
with the two adapter-package commands above. Package discovery is the wiring:
the adapter change stays inside its capsule, and the dynamic global aggregate
picks it up through `regress-adapter-packages`.

The rest of this section is for shared engine/product suites.

Use the shared harness — `sandbox/tests/lib/{check.php,wp_stubs.php,FakeWpdb.php}`
— and read [sandbox/tests/lib/README.md](../sandbox/tests/lib/README.md) for the
complete skeleton. Three files, no composer, no WordPress: every offline
`regress_*.php` runs as plain `php sandbox/tests/offline/<domain>/X.php`, so the
harness must too. The corpus root holds no suites — pick the `offline/` domain
whose subject matches yours (`tools/suite-layout.review.md` says what each one
means) and put the file there.

One `Makefile` edit goes with a shared suite — its own leaf target — and then
`php tools/offline-corpus.php`. Neither the corpus list nor the count is typed
by hand any more: that generator reads the suite files under the five
execution-class directories, maps each to the target whose recipe runs it, and
writes `regress-offline-corpus`'s prerequisite list plus the
`regress-offline-all: N offline suites green` line into
`tools/offline-corpus.mk`, which the `Makefile` `include`s and
`make release-gate` byte-compares. Package-local suites are deliberately not
individual rows in this include. `regress_bundle_coverage.sh` still expands
the whole prerequisite graph and compares its size against the declared number
(`:201-213`), so an unwired suite, a stale count and an attempted exclusion are
three separate refusals — each with its own self-test inside the suite, so none
can rot unnoticed.

Tooling self-tests are different: they go in `tests/` as PHPUnit 11
(`WPrism\Tests\…`, PSR-4) and need no Makefile wiring at all.

## Live evidence

Everything above is offline. When a change genuinely needs a real WordPress:

- [docs/sandbox.md](sandbox.md) — the pair model (`sandbox/bin/pair.sh`, one
  shared MariaDB, one compose project per pair) and how to bring one up.
- [docs/agents/linear-loop.md](agents/linear-loop.md) §Evidence scoping — how to
  compute the **minimal reasonably-safe** live set from your diff. The default
  is often zero sweeps: if every changed code path executes offline, the
  offline corpus *is* the gate. Never run the manifest matrix by habit.
- Pair budget is host-wide and enforced (`pair.sh up` refuses over budget).
  `bash sandbox/bin/pair.sh list` before every `up`; stop idle pairs; destroy
  them when the issue's verification is done. `tools/doctor.sh` prints the
  current inventory when a docker daemon is reachable.
- Bind every live run to its commit: `WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse
  HEAD)`. A pair's bind mounts resolve to the canonical checkout, not to
  whichever worktree ran `pair.sh`, so an unbound live verdict can be about code
  you did not write.

For the *live* half's host prerequisites (images pre-pulled, compose v2, `gh`
authenticated), `bash scripts/agent-bootstrap.sh` is the fail-loud one-shot;
`tools/doctor.sh` is its read-only, offline-first counterpart.

## The classmap autoloader

`agent/wprism-classmap.php` and `cli/wprism-classmap.php` are **generated** files
(`php tools/classmap-generate.php`). Each maps every fully-qualified type
declared under `agent/src` / `cli/src` to its path, and `agent/wprism.php` and
`cli/wprism` register a small `spl_autoload_register()` fallback over them.

This does not make the drop-in "an autoloader project" in the sense AGENTS.md
non-negotiable 1 forbids: nothing is vendored or fetched, the map is a
first-party source file that ships inside `agent/`, and **every existing
`require_once` stays** (owner ruling D4). The fallback is additive — an
autoloader is only consulted for a class that is *still undeclared* when it is
referenced — so on the production path it resolves nothing at all. Measured:
after `agent/wprism.php` finishes, 292 of the map's 297 names are already
declared, and the five exceptions (`WPrism\AdapterCertification` and its four
withdrawal/supersession signals —
`WPrism\SupersededSiteAdapterCertificate`,
`WPrism\StalePlatformSiteAdapterCertificate`,
`WPrism\SupersededWireSiteAdapterCertificate`,
`WPrism\WithdrawnAuthoritySiteAdapterCertificate`) are
`require_once`d at each of that file's three use sites in `AdapterSources.php`
before any of them is ever named. What the map buys is the partially-loaded case —
an offline suite that includes three `agent/src` files by hand, or a new file
whose hand-written require chain missed a dependency — where the alternative is
a fatal `Class not found`.

Two properties are load-bearing and must survive any edit:

- **`class_exists(X::class, false)` is untouched by construction.** The `false`
  argument suppresses autoloading, which is what keeps the guarded top-level
  require blocks in `agent/src` and every sandbox shadow-block test (one that
  pre-declares a stub so the guard skips the real file) behaving as before.
- **A missing file stays a missing class, not a fatal.** The closure tests
  `is_file()` before `require_once`. Without that, a stale entry would turn a
  graceful "support is not loaded" diagnostic into an uncatchable require
  failure — `cli/src/Refresh/RefreshPlan.php` is required under an `is_file()` guard
  for exactly that reason.

Two types are deliberately excluded, each documented in `CM_EXCLUSIONS` in the
generator: `WPrism\Cli` (its file's last line is `WP_CLI::add_command()`, a
top-level side effect that must stay behind `wprism.php`'s WP-CLI require) and
`WPrism\InitialStateBoundaryException` (declared three times behind
`class_exists(…, false)` guards, so no single file is its home).

The output is deterministic by construction — FQCN-sorted with `strcmp()`, LF,
no timestamp, no host path — because both gates over it are byte-compares, and
a map that differed per host would turn each of them into a coin flip and put a
spurious diff in every unrelated PR. `tests/Tooling/ClassmapTest.php`
regenerates both in memory and byte-compares, so a stale map fails `composer
check` rather than silently going wrong; `php tools/classmap-generate.php
--check`, which `make release-gate` runs, is the same question from the command
line.

Adding a class to `agent/src` or `cli/src` requires explicit dependency loads,
its owning module's `files` entry and `file_count` in `tools/modules.json`,
and `php tools/classmap-generate.php`. Refresh the public signature fixture
with `php tools/api-surface.php --write` when that surface changes. Commit
those generated projections alongside the class. `MoveModulesTest` requires
the module map to cover every source file; a current classmap alone does not
prove that ownership. `php tools/codemod/move-modules.php --plan` must remain
a no-op; do not run the historical move with `--apply` for a new class.

## `wprism assess` / `wprism contract` (round-3 MUP)

Two environment-bound host verbs land with the round-3 minimum usable platform;
the six product words they report are defined in
[docs/assess-vocabulary.md](assess-vocabulary.md). Both take an `<env>` and
neither writes to a target.

```bash
wprism assess <env> [--operation=<csv>] [--limit=<1..200>] [--cursor=<token>] [--format=json]
wprism contract <env> show|propose|accept|attest [--format=json]
```

`wprism assess` composes, in this order and with each step gating the next:
`Doctor::run()`, the read-only adoption and `wp wprism init` probes,
`wp wprism assess-inventory --format=json` (which already carries `coverage`,
`pending` and the target's adapter survey), one
`wp wprism capabilities --operation=<op>` per *distinct registry operation* — four
calls for all six product operations — and the host-side `wprism adapter list`.
It prints the stack, the authority WPrism actually has, one row per
WordPress-language surface with the six spec dimensions, the unknown queue
counted and named, and one next action per gap from a closed set. It writes
`.wprism/contract/<env>/proposed.json` into the **local** site repository (the
directory holding `site.wprism.json`, not the target's `repo_path`), and
regenerates `.wprism/contract/projection.json` when a contract has already been
accepted. The proposal is per environment and the contract and projection are
per site, so assessing one environment leaves another's review in flight
alone.

Three things about it are easy to get wrong when reading the output:

- **Exit 0 means green readiness.** Exit 3 is a complete assessment containing
  red readiness; exit 1 means the assessment itself refused — unreachable
  target, multisite, an unresolvable environment — and carries a
  `wprism-command-refusal/v1` envelope under `--format=json`.
- **`containment: unknown — not enforced in this profile` is the honest assess
  projection, not a bug.** Only apply's hook-free window is structurally
  provable from contract facts; `sandboxed` and `compensatable` are never
  emitted by that projection. Rehearse separately requires a provider-bound
  runtime containment receipt before restore; it does not rewrite the row. The
  contract itself carries an `unsigned` attestation until someone runs `wprism
  contract <env> attest` under a key they provisioned in
  `.wprism/contract/authorities.json` — the signer ships, the trust root ships
  empty — but adapter claims are not stuck at `Uncertified`: since round-3 T6
  an operator-signed adapter reads `Site-certified` and a shipped reviewed one
  reads `Platform-certified` (`cli/src/Contract/ProjectionVocabulary.php:805`,
  `:824`). A site-signed adapter that is not exactly pinned still reads
  `Uncertified`, with the reason stated.
- **The human view is bounded** (50 rows per section, `--limit=1..200`,
  `N more (use --format=json)`), and a malformed `--limit` refuses rather than
  falling back to the default. The counts printed beside a truncated list are
  always the true totals.
- **The machine view is bounded when requested.** Bare `--format=json` remains
  the complete contract-bound report. Add `--limit=1..200` to receive
  `wprism-assess-view/v1`; follow `page.next_cursor` to enumerate all rows.

`wprism contract` is the reviewed half. `propose` regenerates the proposal from a
fresh assessment; `show` reads the two committed documents from disk and
contacts nothing; `accept` re-runs the assessment, refuses a stale proposal
(`contract_proposal_stale`) rather than reconciling it, writes `contract.json`
and `projection.json` canonically under compare-and-swap, and **stages** them
without committing. A generated proposal cannot be accepted unread: it carries
an `external_effects[]` entry with `decided_by: "unresolved"` that the schema
refuses, so the human review step is enforced rather than requested.

Offline coverage: `sandbox/tests/offline/assess-contract/regress_assess_composition.sh`,
`regress_assess_bounds.sh`, `regress_contract_accept.sh` (all three drive the
real `php cli/wprism` over a `local` transport with a fake `wp` on `PATH`, built by
`sandbox/tests/fixtures/assess/make-fixture.php`), plus the Contract module's
own `regress_assess_projection.php`, `regress_contract_shape.php` and
`regress_contract_projection.php`.

### `wprism release` / `wprism verify` / `wprism recover` / `wprism rehearse` (round-3 MUP §2.2–§2.5)

Four more host verbs, all environment-bound, all with a `--format=json`
document of their own. Four things about them are easy to get wrong:

- **`release` composes `promote`; it does not fork it.** `cli/wprism`'s
  `cmd_release()` injects `cmd_promote()` — the same entry point `wprism promote`
  itself calls — so deploy-before-apply ordering, the lease, the fence, the
  checkpoint, issue #3310's verified/scoped rollback selection and every
  `promote phase:` output byte come from one implementation. `ReleaseCommand`
  adds the frozen authorization in front of it and the verification behind it.
  `regress_release_next_action.sh` asserts the exact phase sequence, so a fork
  would fail that suite rather than drift quietly.
- **The two closed sets are not interchangeable.** A refusal *before* the
  authorization plan is frozen is an assessment gap and carries a §2.1 gap
  action (`declare in contract`, `classify`, `exclude`, …). Only a failure
  *after* the freeze carries a release next action from
  `resume|reconcile|retry|recover|requalify|escalate`. `wprism release` observes
  the failure class from a read-only re-read of the target rather than from
  promote's exit code, and falls back to `nothing_safe` → `escalate` rather
  than guessing.
- **`--from <ref>` is a binding assertion, never a git transport.** It resolves
  the ref locally with `git rev-parse`, reads the target repository `HEAD`
  through the driver, and refuses a mismatch with `reconcile`. Nothing is
  fetched, pushed or checked out — `regress_release_ref_binding.sh` proves that
  against a recorded `git` shim, not against the source.
- **`wprism verify`'s convergence half is a read-only plan re-read, and says so.**
  `wp wprism verify-canonical` needs a `--compiled` artifact and a
  `wprism-policy-snapshot/v6` that only a mutating apply produces
  (`agent/src/Apply/ConvergenceVerifier.php:91-114`), and MUP §2.4 forbids
  adding an agent command to export one. So the report carries
  `verifier: "plan-reconciliation/v1"` plus a disclosure naming where the
  byte-level recapture actually ran — inside the release's own apply, where it
  fails closed. It is never labelled `canonical-recapture/v1`.

`wprism recover` replaces typing `recovery/rollback-control.php` by hand. It
refuses without `--writers-excluded` (the checkpoint contains its own promotion
lease row, so a lock inside the database being imported cannot protect the
window), enforces code-first ordering by name, and runs the fourth ordered step
— the lease-releasing abort — in a `finally`, so a failed import cannot skip
it. Offline coverage: `sandbox/tests/offline/assess-contract/regress_release_next_action.sh`,
`regress_release_ref_binding.sh` and `regress_recover_ordering.sh`, built by
`sandbox/tests/fixtures/release/make-release-site.php` (which extends the
assess fixture) and `make-recover-site.php` (which adds a fake `ssh` and a stub
rollback runtime).
