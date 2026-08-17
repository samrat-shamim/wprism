# AGENTS.md — operating notes for coding agents in duo-wp

Read this before your first edit. It is the short, factual version; the setup
walkthrough is [docs/dev-setup.md](docs/dev-setup.md) and the dispatch/evidence
protocol is [docs/agents/linear-loop.md](docs/agents/linear-loop.md).

**CI is disabled by owner decision; local evidence is the gate.** Nothing else
about CI belongs in a PR, an issue, or this file.

## Repo map

| path | what it is | ships? |
| --- | --- | --- |
| `agent/` | the WordPress drop-in. `duo.php` `require_once`s 92 files; `agent/src` is 224 flat `namespace Duo;` files that require their own dependencies. No autoloader. | yes |
| `cli/` | the `duo` orchestrator (`cli/duo` is an extensionless `#!/usr/bin/env php` executable) | yes |
| `recovery/` | the recovery runtime (canonical JSON, atomic store, Ed25519 rollback control) | yes |
| `manifests/` | adapter manifests + `providers/`, `interpreters/`, `regenerators/` hooks + `capabilities/` evidence & generated registry | yes |
| `sandbox/` | the test estate: `bin/pair.sh`, `tests/` (204 `regress_*.php` + 111 `regress_*.sh`), `conformance/`, `certification/`, `lib/`, `tmp/` (gitignored scratch) | no |
| `tools/` | dev entry points: `doctor.sh`, `offline.php`, `affected.php`, `cert-impact.php`, `classmap-generate.php`, `api-surface.php`; data: `layers.json` (+ `layers-exceptions.json` ratchet) | no |
| `tests/` | PHPUnit 11 self-tests for `tools/` (`Duo\Tests\…`, PSR-4) | no |
| `scripts/` | `capability-registry.php` (the release gate), `agent-bootstrap.sh`, `close-gate-check.sh` | mixed |

`cli/src/Adopt.php` tars exactly `agent manifests recovery` — that is the whole
list of what reaches a managed site.

## Non-negotiables

1. **The drop-in is dependency-free.** No composer, no vendored packages, no
   autoloader inside `agent/`, `cli/`, `recovery/`. A new file in `agent/src`
   requires its own dependencies, exactly like its 224 siblings. `vendor/` is
   dev-only and structurally unreachable from a site.
2. **Never edit a certification-closure file casually.** The closure is
   `agent/`, `cli/`, `sandbox/bin/` walked *whole on the filesystem* (untracked
   and ignored files included), plus `Makefile`,
   `docs/compatibility-baseline.json`, `sandbox/conformance/{asserts,run}.sh`,
   `sandbox/db.yml`, `sandbox/init-cli.Dockerfile`,
   `sandbox/tests/certify_subject_bundle.sh`, `scripts/capability-registry.php`,
   `sandbox/lib/pair_*.sh`, `sandbox/pair*.yml`, and each subject's own
   manifest / conformance / certification-test files. One byte expires up to
   nine certifications and makes deployed agents refuse commands. The authority
   is `\Duo\ScopedCertificationBundle::subjectInputPaths()`; ask
   `php tools/cert-impact.php` rather than reasoning about it.
3. **Never leave scratch under `agent/`, `cli/`, `sandbox/bin/`** — the closure
   walk has no gitignore awareness. Scratch goes in `sandbox/tmp/` or a
   `mktemp -d`.
4. **Never add a `Makefile` target for tooling.** `Makefile` is a closure
   member; a convenience alias costs a full certification round. New tooling is
   a new entry point under `tools/`.
5. **A new offline product suite goes in `sandbox/tests/` and MUST be wired
   into `regress-offline-corpus`** — an unwired suite is caught by
   `regress_bundle_coverage.sh`. That wiring is a `Makefile` edit, therefore a
   certification-train change: batch such PRs and pay for one round.
   Tooling self-tests go in `tests/` instead and cost nothing.
6. **New suites use `sandbox/tests/lib/`** (`check.php`, `wp_stubs.php`,
   `FakeWpdb.php`) — see `sandbox/tests/lib/README.md` for the skeleton. Don't
   write an eleventh bespoke `$wpdb` fake.
7. **No mass reformat.** `php-cs-fixer` runs on changed files only, by design.
   A whole-tree fix would rewrite closure files and expire everything.
8. **`declare(strict_types=1)` in new files only.** Adding it to an existing
   file changes that file's bytes and its coercion behaviour.
9. **Keep byte-identical unless the issue is explicitly about changing them:**
   canonical JSON output, refusal envelopes and their messages, WP-CLI output,
   lock acquisition order, `manifests/*.json` bytes, and the
   `define('DUO_AGENT_VERSION', …)` / `define('DUO_SPEC_VERSION', …)` lines in
   `agent/duo.php` (the registry and every bundle bind them).
10. **Fix the root cause** within the pinned architecture — no silent
    fallbacks, no compat shims. Every fix ships with regression coverage that
    fails against the prior defect, through the product path.
11. **Comment style is rationale-dense**: state the constraint and the evidence
    (file:line, measured number, error string), not the mechanics.

## The loop

```bash
bash tools/doctor.sh              # environment diagnosis; --fix applies the 3 safe remedies
composer check                    # php -l + phpstan + php-cs-fixer(changed) + phpunit
php tools/offline.php -j8         # whole offline corpus in parallel, one log per suite
php tools/offline.php --changed   # only affected suites — iteration only, never the gate
php tools/affected.php --explain  # why each suite was selected
php tools/cert-impact.php         # which certifications this diff expires
make regress-offline-all          # THE merge gate (DUO-3285) — unconditional, quote it in the PR
make release-gate                 # evidence + generated registry + product prose agree
```

the offline leaf targets (239 today; the Makefile's own `N offline suites green` line is the count of record). `tools/offline.php` runs the same work as
`make regress-offline-all` with per-suite logs and the guard's exact
diagnostic regex applied per suite, so a failure is named rather than merely
detected — but the PR quotes the canonical gate, not the fast path. Stock macOS
ships GNU Make 3.81 (no `--output-sync`); use `tools/offline.php` there.

Mechanically, every change: `php -l` each touched PHP file, `bash -n` each
touched script, then `make regress-offline-all` green. Warnings are not green —
human output, machine output and exit status must agree.

## Live evidence

Offline first, always: reproduce the mechanism with a deterministic offline pin
before reaching for a live pair. Then scope the live set to the **minimal
reasonably-safe** one per
[docs/agents/linear-loop.md](docs/agents/linear-loop.md) §Evidence scoping —
often zero sweeps, never the manifest matrix by habit. Pair discipline (budget,
release-when-idle, destroy-when-done, `DUO_EXPECTED_SOURCE_SHA`) is in the same
document and in [docs/sandbox.md](docs/sandbox.md); the pair model itself is
`sandbox/bin/pair.sh`.

## Where things live

- Setup, gotchas, closure rules, certification trains, measured wall times →
  [docs/dev-setup.md](docs/dev-setup.md)
- Dispatch loop, claim/close gates, evidence scoping, ordering →
  [docs/agents/linear-loop.md](docs/agents/linear-loop.md)
- Architecture posture (loud, blocking, scoped; honest refusal over hollow
  coverage) → `DESIGN.md`; wire format → `spec/repo-format.md`
- Product-facing guides → `docs/guides/`; capability claims → `docs/capabilities.md`

Out-of-scope discoveries are reported on the issue or filed as a new one —
never fixed silently.
