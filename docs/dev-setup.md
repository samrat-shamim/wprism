# Dev setup: from a fresh checkout to a green gate

This is the setup-and-loop guide for people (and coding agents) working *on*
duo-wp. It is not about running Duo against a site — that is
[docs/guides/quickstart.md](guides/quickstart.md). Everything below assumes you
will produce local evidence, because **there is no CI: local evidence is the
merge gate** (owner decision, DUO-3320).

## The short version

```bash
git clone https://github.com/duotronic-ai/duo-wp     # NOT --depth 1; see below
cd duo-wp
bash tools/doctor.sh --fix                            # diagnose + apply the safe remedies
composer install                                      # dev toolchain only; nothing here ships
composer check                                        # lint + phpstan + php-cs-fixer + phpunit
make regress-offline-all                              # THE gate: the whole offline corpus
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

`cli/src/Onboarding/Adopt.php` tars exactly `agent manifests recovery`. That is
the whole list of what reaches a managed site, and it is the only place in this
repo where an edit has consequences beyond review.

Inside that list, `manifests/` is special: its bytes **are** adapter identity.
`ArtifactPolicyIdentity::manifest_rows()` folds each manifest's JSON, its
disposition entry, and `hash_file('sha256', …)` of every interpreter, provider
and regenerator file that manifest names
(`agent/src/Policy/ArtifactPolicyIdentity.php:60-115`). `manifest_hash()`
(`:127`) and each adapter's own `digest` (`:147`) are that same row hashed, and
the class comment at `:53-56` spells out the consequence: a site repo's
per-manifest content pin, `adapter_digest`, `duo assess`'s reported digest and
the contract that pins it are all one row hashed.

So a one-byte edit under `manifests/` is a fleet-visible change. A deployed
site holding a compiled artifact refuses with
`compiled_artifact_manifest_mismatch` — "compiled manifest/interpreter set does
not match active pins" (`agent/src/Repository/CompiledArtifactReader.php:39-42`)
— and every `site.duo.json` content pin stops matching. The remedy is to
recompile and re-pin (`wp duo manifest-pin` prints the copy-pasteable object),
never a fallback.

`agent/src`, `cli/src` and `recovery/` ship but carry no such identity: a
namespace rename or a class-file move there moves no digest. Everything else —
`docs/`, `spec/`, `sandbox/`, `scripts/`, `Makefile`, `tools/`, `tests/`,
`vendor/`, `composer.*`, `phpstan*`, `phpunit.xml.dist`,
`.php-cs-fixer.dist.php` — never reaches a site at all.

There is no source closure and no certification round any more: no set of files
whose bytes expire a sealed claim, and no `Makefile`-target tax. What a
capability claim rests on now is [dispositions and live
conformance](guides/capabilities-and-limits.md), and the only mechanical gate
over it is `make release-gate`.

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
| `make regress-offline-all` | **the canonical merge gate** (DUO-3285), unconditional |
| `make release-gate` | `capability-doc.php --check` + `classmap-generate.php --check`: the generated capability document, the README summary block and both classmaps still match their sources |

`tools/offline.php` is strictly stronger than a raw `make -j`: it gives every
suite its own log, applies `offline_diagnostics_guard.sh`'s exact diagnostic
regex *per suite* so the offender is named rather than merely detected, and
keeps the suites that hard-code fixed `/tmp/...` paths in a mutually exclusive
serial group. It is a faster way to run the same work — it is **not** a
substitute for `make regress-offline-all` in a PR: quote the canonical gate.

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

Use the shared harness — `sandbox/tests/lib/{check.php,wp_stubs.php,FakeWpdb.php}`
— and read [sandbox/tests/lib/README.md](../sandbox/tests/lib/README.md) for the
complete skeleton. Three files, no composer, no WordPress: every offline
`regress_*.php` runs as plain `php sandbox/tests/offline/<domain>/X.php`, so the
harness must too. The corpus root holds no suites — pick the `offline/` domain
whose subject matches yours (`tools/suite-layout.review.md` says what each one
means) and put the file there.

One `Makefile` edit goes with it — the suite's own leaf target — and then
`php tools/offline-corpus.php`. Neither the corpus list nor the count is typed
by hand any more: that generator reads the suite files under the five
execution-class directories, maps each to the target whose recipe runs it, and
writes `regress-offline-corpus`'s prerequisite list plus the
`regress-offline-all: N offline suites green` line into
`tools/offline-corpus.mk`, which the `Makefile` `include`s and
`make release-gate` byte-compares. `regress_bundle_coverage.sh` still expands
the whole prerequisite graph and compares its size against the declared number
(`:201-213`), so an unwired suite, a stale count and an attempted exclusion are
three separate refusals — each with its own self-test inside the suite, so none
can rot unnoticed.

Tooling self-tests are different: they go in `tests/` as PHPUnit 11
(`Duo\Tests\…`, PSR-4) and need no Makefile wiring at all.

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
- Bind every live run to its commit: `DUO_EXPECTED_SOURCE_SHA=$(git rev-parse
  HEAD)`. A pair's bind mounts resolve to the canonical checkout, not to
  whichever worktree ran `pair.sh`, so an unbound live verdict can be about code
  you did not write.

For the *live* half's host prerequisites (images pre-pulled, compose v2, `gh`
authenticated), `bash scripts/agent-bootstrap.sh` is the fail-loud one-shot;
`tools/doctor.sh` is its read-only, offline-first counterpart.

## The classmap autoloader

`agent/duo-classmap.php` and `cli/duo-classmap.php` are **generated** files
(`php tools/classmap-generate.php`). Each maps every fully-qualified type
declared under `agent/src` / `cli/src` to its path, and `agent/duo.php` and
`cli/duo` register a small `spl_autoload_register()` fallback over them.

This does not make the drop-in "an autoloader project" in the sense AGENTS.md
non-negotiable 1 forbids: nothing is vendored or fetched, the map is a
first-party source file that ships inside `agent/`, and **every existing
`require_once` stays** (owner ruling D4). The fallback is additive — an
autoloader is only consulted for a class that is *still undeclared* when it is
referenced — so on the production path it resolves nothing at all. Measured:
after `agent/duo.php` finishes, 247 of the map's 251 names are already
declared, and the four exceptions (`Duo\AdapterCertification` and the three
withdrawal/supersession signals declared in the same file —
`Duo\SupersededSiteAdapterCertificate`,
`Duo\StalePlatformSiteAdapterCertificate`,
`Duo\SupersededWireSiteAdapterCertificate`) are
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
generator: `Duo\Cli` (its file's last line is `WP_CLI::add_command()`, a
top-level side effect that must stay behind `duo.php`'s WP-CLI require) and
`Duo\InitialStateBoundaryException` (declared three times behind
`class_exists(…, false)` guards, so no single file is its home).

The output is deterministic by construction — FQCN-sorted with `strcmp()`, LF,
no timestamp, no host path — because both gates over it are byte-compares, and
a map that differed per host would turn each of them into a coin flip and put a
spurious diff in every unrelated PR. `tests/Tooling/ClassmapTest.php`
regenerates both in memory and byte-compares, so a stale map fails `composer
check` rather than silently going wrong; `php tools/classmap-generate.php
--check`, which `make release-gate` runs, is the same question from the command
line.

Adding a class to `agent/src` or `cli/src` therefore has one extra step: run
`php tools/classmap-generate.php` and commit the regenerated map alongside it.

## `duo assess` / `duo contract` (round-3 MUP)

Two environment-bound host verbs land with the round-3 minimum usable platform;
the six product words they report are defined in
[docs/assess-vocabulary.md](assess-vocabulary.md). Both take an `<env>` and
neither writes to a target.

```bash
duo assess <env> [--operation=<csv>] [--limit=<1..200>] [--format=json]
duo contract <env> show|propose|accept|attest [--format=json]
```

`duo assess` composes, in this order and with each step gating the next:
`Doctor::run()`, the read-only adoption and `wp duo init` probes,
`wp duo assess-inventory --format=json` (which already carries `coverage`,
`pending` and the target's adapter survey), one
`wp duo capabilities --operation=<op>` per *distinct registry operation* — four
calls for all six product operations — and the host-side `duo adapter list`.
It prints the stack, the authority Duo actually has, one row per
WordPress-language surface with the six spec dimensions, the unknown queue
counted and named, and one next action per gap from a closed set. It writes
`.duo/contract/<env>/proposed.json` into the **local** site repository (the
directory holding `site.duo.json`, not the target's `repo_path`), and
regenerates `.duo/contract/projection.json` when a contract has already been
accepted. The proposal is per environment and the contract and projection are
per site, so assessing one environment leaves another's review in flight
alone.

Three things about it are easy to get wrong when reading the output:

- **Exit 0 is normal even when every surface is blocked.** Assessment is not a
  completeness claim. Exit 1 means the assessment itself refused — unreachable
  target, multisite, an unresolvable environment — and always carries a
  `duo-command-refusal/v1` envelope under `--format=json`.
- **`containment: unknown — not enforced in this profile` is the honest value,
  not a bug.** MUP ships no egress control, so only apply's hook-free window is
  structurally provable. `sandboxed` and `compensatable` are never emitted. The
  contract itself carries an `unsigned` attestation until someone runs `duo
  contract <env> attest` under a key they provisioned in
  `.duo/contract/authorities.json` — the signer ships, the trust root ships
  empty — but adapter claims are not stuck at `Uncertified`: since round-3 T6
  an operator-signed adapter reads `Site-certified` and a shipped reviewed one
  reads `Platform-certified` (`cli/src/Contract/ProjectionVocabulary.php:805`,
  `:824`). A site-signed adapter that is not exactly pinned still reads
  `Uncertified`, with the reason stated.
- **The human view is bounded** (50 rows per section, `--limit=1..200`,
  `N more (use --format=json)`), and a malformed `--limit` refuses rather than
  falling back to the default. The counts printed beside a truncated list are
  always the true totals.

`duo contract` is the reviewed half. `propose` regenerates the proposal from a
fresh assessment; `show` reads the two committed documents from disk and
contacts nothing; `accept` re-runs the assessment, refuses a stale proposal
(`contract_proposal_stale`) rather than reconciling it, writes `contract.json`
and `projection.json` canonically under compare-and-swap, and **stages** them
without committing. A generated proposal cannot be accepted unread: it carries
an `external_effects[]` entry with `decided_by: "unresolved"` that the schema
refuses, so the human review step is enforced rather than requested.

Offline coverage: `sandbox/tests/offline/assess-contract/regress_assess_composition.sh`,
`regress_assess_bounds.sh`, `regress_contract_accept.sh` (all three drive the
real `php cli/duo` over a `local` transport with a fake `wp` on `PATH`, built by
`sandbox/tests/fixtures/assess/make-fixture.php`), plus the Contract module's
own `regress_assess_projection.php`, `regress_contract_shape.php` and
`regress_contract_projection.php`.

### `duo release` / `duo verify` / `duo recover` / `duo rehearse` (round-3 MUP §2.2–§2.5)

Four more host verbs, all environment-bound, all with a `--format=json`
document of their own. Four things about them are easy to get wrong:

- **`release` composes `promote`; it does not fork it.** `cli/duo`'s
  `cmd_release()` injects `cmd_promote()` — the same entry point `duo promote`
  itself calls — so deploy-before-apply ordering, the lease, the fence, the
  checkpoint, DUO-3310's verified/scoped rollback selection and every
  `promote phase:` output byte come from one implementation. `ReleaseCommand`
  adds the frozen authorization in front of it and the verification behind it.
  `regress_release_next_action.sh` asserts the exact phase sequence, so a fork
  would fail that suite rather than drift quietly.
- **The two closed sets are not interchangeable.** A refusal *before* the
  authorization plan is frozen is an assessment gap and carries a §2.1 gap
  action (`declare in contract`, `classify`, `exclude`, …). Only a failure
  *after* the freeze carries a release next action from
  `resume|reconcile|retry|recover|requalify|escalate`. `duo release` observes
  the failure class from a read-only re-read of the target rather than from
  promote's exit code, and falls back to `nothing_safe` → `escalate` rather
  than guessing.
- **`--from <ref>` is a binding assertion, never a git transport.** It resolves
  the ref locally with `git rev-parse`, reads the target repository `HEAD`
  through the driver, and refuses a mismatch with `reconcile`. Nothing is
  fetched, pushed or checked out — `regress_release_ref_binding.sh` proves that
  against a recorded `git` shim, not against the source.
- **`duo verify`'s convergence half is a read-only plan re-read, and says so.**
  `wp duo verify-canonical` needs a `--compiled` artifact and a
  `duo-policy-snapshot/v6` that only a mutating apply produces
  (`agent/src/Review/ConvergenceVerifier.php:87-110`), and MUP §2.4 forbids
  adding an agent command to export one. So the report carries
  `verifier: "plan-reconciliation/v1"` plus a disclosure naming where the
  byte-level recapture actually ran — inside the release's own apply, where it
  fails closed. It is never labelled `canonical-recapture/v1`.

`duo recover` replaces typing `recovery/rollback-control.php` by hand. It
refuses without `--writers-excluded` (the checkpoint contains its own promotion
lease row, so a lock inside the database being imported cannot protect the
window), enforces code-first ordering by name, and runs the fourth ordered step
— the lease-releasing abort — in a `finally`, so a failed import cannot skip
it. Offline coverage: `sandbox/tests/offline/assess-contract/regress_release_next_action.sh`,
`regress_release_ref_binding.sh` and `regress_recover_ordering.sh`, built by
`sandbox/tests/fixtures/release/make-release-site.php` (which extends the
assess fixture) and `make-recover-site.php` (which adds a fake `ssh` and a stub
rollback runtime).
