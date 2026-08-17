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

## Two gotchas that cost hours if you meet them cold

### 1. A shallow clone

`git clone --depth 1` produces a working tree that is byte-identical to a full
clone. Nothing looks wrong until:

```
$ make release-gate
duo: certification Git revision is not a commit: c30c1976342e7bf9e5aea0b7711986beb0108410
```

The message names a certification revision, so it reads like an evidence
problem. It is not — the commit object is simply absent from the local object
database. Measured on a `--depth 1` clone of this repo, `make release-gate`,
`make regress-capability-registry` and `make regress-manifest-dispositions` all
die with that exact string; every path that replays a certification bundle
does.

```bash
git fetch --unshallow
```

`tools/doctor.sh` reports this as a FAIL but does **not** auto-fix it: an
unshallow can transfer the entire history and that is a decision, not a repair.

### 2. Missing certification commits (even in a full clone)

Each record in `manifests/capabilities/evidence.json` carries a
`bundle.git_revision`. `ScopedCertificationBundle::assertGitRevisionInputs()`
proves the recorded closure bytes existed at that commit by running
`git cat-file -t <sha>` and `git ls-tree -r <sha>` **locally**
(`agent/src/ScopedCertificationBundle.php:419`).

Those revisions are PR heads, and this project merges with `gh pr merge
--squash`, which discards them: no branch or tag points at them, so a plain
`git fetch` — which negotiates by ref — never brings them down, not even into a
full clone. GitHub still serves them by SHA:

```bash
git fetch origin <sha> && git update-ref refs/duo-cert/<sha> <sha>
```

The local ref keeps the object from being garbage-collected. `bash
tools/doctor.sh --fix` does exactly this for every revision in `evidence.json`
that does not resolve.

## The certification closure — the one rule that bites everybody

Nine subjects (eight certified manifests plus the `fse` profile) are sealed
against a **source closure**. Change one byte of any closure member and every
subject that binds it expires: `make release-gate` goes red, and a *deployed*
agent refuses every command out of `assertRuntimeInputsCurrent()` during
`Policy::load()`.

Membership is defined in exactly one place —
`\Duo\ScopedCertificationBundle::subjectInputPaths()`
(`agent/src/ScopedCertificationBundle.php:144`). Never re-derive it by hand;
run `php tools/cert-impact.php`, which calls that function. For orientation:

**Walked whole, as directory trees, with no gitignore awareness:**

| tree | note |
| --- | --- |
| `agent/` | every file |
| `cli/` | every file |
| `sandbox/bin/` | every file |

Because the walk enumerates real directory entries, an **untracked or ignored**
file inside those trees is a closure input. A `.DS_Store`, an editor swap file,
or a scratch script under `sandbox/bin/` expires all nine certifications.
`tools/doctor.sh` checks `git status --porcelain --ignored -- agent cli
sandbox/bin` for exactly this reason. Put scratch in `sandbox/tmp/`
(gitignored) or a `mktemp -d`.

**Named files, always bound:**

`Makefile`, `docs/compatibility-baseline.json`, `sandbox/conformance/asserts.sh`,
`sandbox/conformance/run.sh`, `sandbox/db.yml`, `sandbox/init-cli.Dockerfile`,
`sandbox/tests/certify_subject_bundle.sh`, `scripts/capability-registry.php`,
plus the globs `sandbox/lib/pair_*.sh` and `sandbox/pair*.yml`.

**Bound per subject:**

- `manifests/<manifest>.json`
- `sandbox/conformance/entries/<name>.json`, and
  `sandbox/conformance/{seeds,postdeploy,checks}/<name>.sh` when present
- the manifest's own `interpreter` → `manifests/interpreters/<id>.php`, its
  `source: manifest` providers → `manifests/providers/<id>.php`, and each
  `post_types[].regen_dependency.regenerator` → `manifests/regenerators/<id>.php`
- per declared test: `sandbox/certification/tests/<test-id>.sh` (plus an
  optional same-named directory); `exact-artifact-version-matrix` binds
  `sandbox/certification/version-matrix/<name>.sh` and its directory when that
  driver exists, otherwise `sandbox/tests/certify_version_matrix.sh`;
  `multisite-refusal` on `core` binds `sandbox/tests/regress_multisite_refusal.sh`.

**Outside the closure** (edit freely, no certification cost): `docs/`,
`sandbox/tests/` other than the three named files above
(`certify_subject_bundle.sh`, `certify_version_matrix.sh`,
`regress_multisite_refusal.sh`), `sandbox/conformance/` fixtures not named
above, `scripts/` other than `capability-registry.php`,
`recovery/`, `spec/`, and everything the dev toolchain owns —
`tools/`, `tests/`, `vendor/`, `composer.*`, `phpstan*`, `phpunit.xml.dist`,
`.php-cs-fixer.dist.php`.

### Tooling lives outside the closure — deliberately

`tools/` and `tests/` are dev-only entry points, and `cli/src/Adopt.php` tars
exactly `agent manifests recovery`, so nothing at the repo root can reach a
managed site. That is what makes it safe for the toolchain to depend on
composer while the drop-in itself stays dependency-free (asserted by
`sandbox/tests/regress_scoped_certification_bundle.php` and
`tests/Tooling/PhpstanBaselineRatchetTest.php`).

The practical consequence: **new tooling goes in `tools/` as a script, never as
a new `Makefile` target.** The `Makefile` is a closure member, so adding a
target to it — even a one-line convenience alias — expires all nine
certifications and costs a full certification round.

## Certification trains

A change that *does* touch the closure needs a certification round. A round is
not cheap, so **batch closure-touching PRs into a train** and pay for one round
instead of N. One round is:

```bash
make certify-subjects-parallel            # independent lanes on leased pairs; emits index.json
php scripts/capability-registry.php import-subject-bundle <bundle-dir>   # once per subject
make capability-registry-generate
make release-gate                         # must go green
```

Rules learned the hard way:

- **Import nothing until every lane finishes.** The lanes share one exact-source
  checkout; importing mid-run makes it dirty underneath the remaining lanes.
- **The driver host needs a full clone with the certification commits present**
  (`bash tools/doctor.sh` green on checks 1 and 2), because each lane replays
  `assertGitRevisionInputs()`.
- **Freeze the reviewed candidate first.** Land every review finding, record
  candidate `C` and base `B`, then certify from a clean checkout of exactly
  `C`. A later change to a subject's bound inputs expires only that subject —
  repeat that subject's run; never relabel old evidence current. Full ordering:
  [docs/agents/linear-loop.md](agents/linear-loop.md) §Ordering.
- **A suite-count echo line is a train too.** `php tools/cert-impact.php
  --range=A..B` classifies commit `dd57e88` — whose entire diff is
  `-"regress-offline-all: 208 offline suites green"` /
  `+… 209 …` — as `suite-declaration-only`, all nine subjects expired. Wiring a
  new offline suite into `regress-offline-corpus` costs a round; batch it.

Before you push, ask the tool rather than guessing:

```bash
php tools/cert-impact.php               # working tree
php tools/cert-impact.php --range=origin/main...HEAD --json
```

## The daily loop

| command | what it is for |
| --- | --- |
| `bash tools/doctor.sh` | environment diagnosis; run it whenever anything looks structurally wrong |
| `composer check` | `php -l` + phpstan + php-cs-fixer (changed files) + phpunit |
| `php tools/offline.php -j8` | the whole offline corpus in parallel, one log per suite |
| `php tools/offline.php --changed` | only the suites your diff can affect — **iteration only** |
| `php tools/affected.php --explain` | why each suite was selected |
| `php tools/cert-impact.php` | which of the nine certifications your diff expires |
| `make regress-offline-all` | **the canonical merge gate** (DUO-3285), unconditional |
| `make release-gate` | evidence, generated registry and product prose agree |

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

Two hosts, both real, and the difference matters when you plan a session —
but they are not directly comparable, and neither is claimed to be:

| run | host A (shallow-clone baseline) | host B (this macOS clone, full clone) |
| --- | --- | --- |
| serial `make regress-offline-corpus` | 509 s | — |
| `make -k -j8 regress-offline-corpus` | 97 s | — |
| `php tools/offline.php -j8` | — | 296.05 s wall, 966.68 s of suite time |

Host A's 509 s / 97 s figures were measured on a **shallow clone**, where
`regress-plugin-adapter-source` fails fast instead of running to completion —
so that column is not a full 238/238 run and is not makespan-comparable to
host B; it is kept only because a serial-vs-`-j8` speedup is still visible on
it and no fuller measurement from that host exists in-repo.

Host B is a full clone, 238/238 offline suites green. `php tools/offline.php
-j8` (8 workers) finished in 296.05 s wall against 966.68 s of total
suite-seconds — about 3.3x effective parallelism, not the workers' full 8x,
because the schedule is bounded below by its single longest suite:
`regress-plugin-adapter-source` at 296.05 s, which is why wall time tracks it
closely rather than the theoretical 8-way speedup. That suite spawns 53
sequential PHP children, each performing a full `Policy::load()` that
re-verifies all nine certification closures (~5 s apiece) — a per-child cost,
not a macOS-specific artifact, so treat it as inherent rather than as
something a faster scheduler could hide. Next tier: `regress-adapter-catalog`
126.7 s, `regress-pair-bootstrap-unit` 87.3 s. `regress_bundle_coverage`
itself — a member of this same 238-suite gate — dropped from ~37 s to ~0.8 s
after the single-pass rewrite above. 238 leaf targets at that measurement (239 since DUO-3481 added the parity suite); zero
parallel flakes on either host.

## Writing a new offline suite

Use the shared harness — `sandbox/tests/lib/{check.php,wp_stubs.php,FakeWpdb.php}`
— and read [sandbox/tests/lib/README.md](../sandbox/tests/lib/README.md) for the
complete skeleton. Three files, no composer, no WordPress: every offline
`regress_*.php` runs as plain `php sandbox/tests/X.php`, so the harness must
too. Wiring the new leaf into `regress-offline-corpus` is a `Makefile` edit and
therefore a certification-train change (above).

Tooling self-tests are different: they go in `tests/` as PHPUnit 11
(`Duo\Tests\…`, PSR-4) and cost nothing — `tests/` is outside the closure.

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
first-party source file inside the certification closure, and **every existing
`require_once` stays** (owner ruling D4). The fallback is additive — an
autoloader is only consulted for a class that is *still undeclared* when it is
referenced — so on the production path it resolves nothing at all. Measured:
after `agent/duo.php` finishes, 237 of the map's 238 names are already
declared; the single exception, `Duo\AdapterCertification`, is `require_once`d
at both of its use sites before it is ever named. What the map buys is the
partially-loaded case — an offline suite that includes three `agent/src` files
by hand, or a new file whose hand-written require chain missed a dependency —
where the alternative is a fatal `Class not found`.

Two properties are load-bearing and must survive any edit:

- **`class_exists(X::class, false)` is untouched by construction.** The `false`
  argument suppresses autoloading, which is what keeps the guarded top-level
  require blocks in `agent/src` and every sandbox shadow-block test (one that
  pre-declares a stub so the guard skips the real file) behaving as before.
- **A missing file stays a missing class, not a fatal.** The closure tests
  `is_file()` before `require_once`. Without that, a stale entry would turn a
  graceful "support is not loaded" diagnostic into an uncatchable require
  failure — `cli/src/RefreshPlan.php` is required under an `is_file()` guard
  for exactly that reason.

Two types are deliberately excluded, each documented in `CM_EXCLUSIONS` in the
generator: `Duo\Cli` (its file's last line is `WP_CLI::add_command()`, a
top-level side effect that must stay behind `duo.php`'s WP-CLI require) and
`Duo\InitialStateBoundaryException` (declared three times behind
`class_exists(…, false)` guards, so no single file is its home).

The maps are certification-closure members — `agent/` and `cli/` are walked
whole — so regenerating one costs a certification round. That is affordable
only because the output is deterministic: FQCN-sorted with `strcmp()`, LF, no
timestamp, no host path. `tests/Tooling/ClassmapTest.php` regenerates both in
memory and byte-compares, so a stale map fails `composer check` rather than
silently going wrong; `php tools/classmap-generate.php --check` is the same
question from the command line.

Adding a class to `agent/src` or `cli/src` therefore has one extra step: run
`php tools/classmap-generate.php` and commit the regenerated map alongside it.
