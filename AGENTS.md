# AGENTS.md — operating notes for coding agents in duo-wp

Read this before your first edit. It is the short, factual version; the setup
walkthrough is [docs/dev-setup.md](docs/dev-setup.md) and the dispatch/evidence
protocol is [docs/agents/linear-loop.md](docs/agents/linear-loop.md).

**CI is disabled by owner decision; local evidence is the gate.** Nothing else
about CI belongs in a PR, an issue, or this file.

## Repo map

| path | what it is | ships? |
| --- | --- | --- |
| `agent/` | the WordPress drop-in. `duo.php` `require_once`s 95 files at load; `agent/src` is 226 `namespace Duo;` files across 17 directories, each requiring its own dependencies. The only autoload is the generated additive fallback `agent/duo-classmap.php` (rule 1). | yes |
| `cli/` | the `duo` orchestrator (`cli/duo` is an extensionless `#!/usr/bin/env php` executable) over 83 `cli/src` files | yes |
| `recovery/` | the recovery runtime (canonical JSON, atomic store, Ed25519 rollback control) | yes |
| `manifests/` | core + 9 plugin manifests + `duo-agency-cpt` (the one `excluded` regression fixture, no product claim); `providers/`, `interpreters/`, `regenerators/` hook code; `dispositions.json`, the hand-authored reviewed claim source; `capabilities/platform.json` (the platform boundary certificates sign against) and `capabilities/adapter-authorities.json` | yes |
| `sandbox/` | the test estate: `bin/pair.sh`, `tests/` (214 `regress_*.php` + 95 `regress_*.sh`, counted recursively — every suite is under one of the five execution-class directories `offline/<domain>/`, `live/`, `grind/`, `certify/`, `spike/`, and the corpus root holds only `fixtures/`, `lib/`, `support/` and `offline_diagnostics_guard.sh`), `conformance/`, `siterepo/`, `tmp/` (gitignored scratch) | no |
| `tools/` | dev entry points: `doctor.sh`, `offline.php`, `affected.php`, `capability-doc.php`, `classmap-generate.php`, `api-surface.php`; data: `modules.json`, the single per-path `{module, layer}` source for `agent/src` and `cli/src` (+ `layers-exceptions.json`, the upward-reference ratchet against it — `tools/layers.json`'s old duplicate file-level `path => layer` map was retired in DUO-3493) | no |
| `tests/` | PHPUnit 11 self-tests for `tools/` (`Duo\Tests\…`, PSR-4) | no |
| `scripts/` | `adapter-certification.php` (reviewer-facing adapter certificate sign/verify), `agent-bootstrap.sh`, `close-gate-check.sh` | mixed |

`cli/src/Onboarding/Adopt.php` tars exactly `agent manifests recovery` — that is the whole
list of what reaches a managed site.

## Non-negotiables

1. **The drop-in is dependency-free.** No composer, no vendored packages,
   nothing fetched at runtime inside `agent/`, `cli/`, `recovery/`. A new file
   in `agent/src` requires its own dependencies, exactly like its 226 siblings.
   `agent/duo-classmap.php` does not change that contract: it is a *generated
   additive fallback* that only ever fires for a class still undeclared at the
   moment it is referenced, so it resolves nothing on the production path and
   exists for partially-loaded contexts (`agent/duo.php:106-138` states the
   three properties that keep it behaviour-neutral). Regenerate it with
   `php tools/classmap-generate.php`; `make release-gate` byte-checks it.
   `vendor/` is dev-only and structurally unreachable from a site.
2. **Shipped manifest and hook bytes are adapter identity.**
   `ArtifactPolicyIdentity::manifest_rows()` folds each manifest's JSON, its
   disposition, and `hash_file('sha256', …)` of every interpreter, provider and
   regenerator file that manifest names
   (`agent/src/Policy/ArtifactPolicyIdentity.php:60-115`); `manifest_hash()`
   and each adapter's `digest` are that one row hashed (`:127`, `:147`). So a
   one-byte edit under `manifests/` is a fleet-visible change: a deployed site
   holding a compiled artifact refuses with
   `compiled_artifact_manifest_mismatch` — "compiled manifest/interpreter set
   does not match active pins"
   (`agent/src/Repository/CompiledArtifactReader.php:39-42`) — and every
   `site.duo.json` content pin stops matching. The remedy is recompile and
   re-pin (`wp duo manifest-pin` emits the copy-pasteable object), never a
   fallback. Nothing under `agent/src` or `cli/src` has this property: a
   namespace or class-file move there moves no digest and costs nothing.
3. **Never leave scratch under `agent/` or `manifests/`.** `sandbox/bin/pair.sh`
   gates on `git status --porcelain=v1 --untracked-files=all -- agent manifests`
   (`:355`) and refuses before any pair mutation — "candidate source is DIRTY"
   (`:369`) — because those two directories are exactly what it bind-mounts.
   An untracked file counts. Scratch goes in `sandbox/tmp/` or a `mktemp -d`.
4. **A new offline product suite goes in `sandbox/tests/offline/<domain>/`,
   MUST be wired into `regress-offline-corpus`, and MUST bump the count line.**
   The corpus root holds no suites; `regress_suite_wiring.php` refuses one that
   sits outside a class directory, and `tools/suite-layout.review.md` says what
   each `offline/` domain means.
   `regress_bundle_coverage.sh` expands the prerequisite graph and compares it
   against the `Makefile`'s own `regress-offline-all: N offline suites green`
   line (`:201-213`), so an unwired suite and a stale count each fail the gate —
   both refusals have their own self-test in that suite. Tooling self-tests go
   in `tests/` instead. Adding a `Makefile` target is otherwise ordinary work.
5. **New suites use `sandbox/tests/lib/`** (`check.php`, `wp_stubs.php`,
   `FakeWpdb.php`) — see `sandbox/tests/lib/README.md` for the skeleton. Don't
   write an eleventh bespoke `$wpdb` fake.
6. **No mass reformat.** `php-cs-fixer` runs on changed files only, by design.
   A whole-tree fix buries the reviewable change in thousands of lines nobody
   read, and under `manifests/` it would move every `adapter_digest` (rule 2).
7. **`declare(strict_types=1)` in new files only.** Adding it to an existing
   file changes that file's bytes and its coercion behaviour.
8. **Keep byte-identical unless the issue is explicitly about changing them:**
   canonical JSON output, refusal envelopes and their messages, WP-CLI output,
   lock acquisition order, `manifests/*.json` bytes (rule 2 — they *are*
   adapter identity), and the `define('DUO_AGENT_VERSION', …)` /
   `define('DUO_SPEC_VERSION', …)` lines in `agent/duo.php`.
   `manifests/capabilities/platform.json` restates both defines, and
   `ManifestDispositions::platform_boundary()` throws "platform version
   disagrees with the loaded agent" the moment they diverge
   (`agent/src/Policy/ManifestDispositions.php:145-146`); `make release-gate`
   checks the same equality, so those two files move together or not at all.
9. **Fix the root cause** within the pinned architecture — no silent
   fallbacks, no compat shims. Every fix ships with regression coverage that
   fails against the prior defect, through the product path.
10. **Comment style is rationale-dense**: state the constraint and the evidence
    (file:line, measured number, error string), not the mechanics.

## The loop

```bash
bash tools/doctor.sh              # environment diagnosis; --fix applies the 2 safe remedies
composer check                    # php -l + phpstan + php-cs-fixer(changed) + phpunit
php tools/offline.php -j8         # whole offline corpus in parallel, one log per suite
php tools/offline.php --changed   # only affected suites — iteration only, never the gate
php tools/affected.php --explain  # why each suite was selected
make regress-offline-all          # THE merge gate (DUO-3285) — unconditional, quote it in the PR
make release-gate                 # capability-doc --check + classmap --check: the generated
                                  # capability document and the classmaps match their sources
```

The gate runs every offline leaf target — 254 today, and the `Makefile`'s own
`regress-offline-all: N offline suites green` line is the count of record.
`tools/offline.php` runs the same work as `make regress-offline-all` with
per-suite logs and the guard's exact diagnostic regex applied per suite, so a
failure is named rather than merely detected — but the PR quotes the canonical
gate, not the fast path. Stock macOS ships GNU Make 3.81 (no `--output-sync`);
use `tools/offline.php` there.

Mechanically, every change: `php -l` each touched PHP file, `bash -n` each
touched script, then `make regress-offline-all` green. Warnings are not green —
human output, machine output and exit status must agree.

## What stands behind a capability claim

There is no sealed evidence record and no generated registry. A status in
[docs/capabilities.md](docs/capabilities.md) means exactly three things:
**declared** by the adapter's own manifest, **reviewed** into
`manifests/dispositions.json` by a human who wrote down why, and **exercised**
by the named live conformance suites in `sandbox/conformance/`. It does not
mean a digest binds that claim to an artifact set or a specific run.

`tools/capability-doc.php` projects the document and the README summary from
exactly four files — `manifests/*.json`, `manifests/dispositions.json`,
`manifests/capabilities/platform.json`, `agent/duo.php` — and `make
release-gate` byte-compares its output, so the prose cannot drift from the
library. Its four cross-checks each mirror a rule `ManifestDispositions`
enforces at agent load time, which is what keeps the document from describing a
library the agent would reject; the header comment at
`tools/capability-doc.php:12-58` is the authority and the first thing to read
before touching a claim.

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

- Setup, gotchas, measured wall times → [docs/dev-setup.md](docs/dev-setup.md)
- Dispatch loop, claim/close gates, evidence scoping, ordering →
  [docs/agents/linear-loop.md](docs/agents/linear-loop.md)
- Architecture posture (loud, blocking, scoped; honest refusal over hollow
  coverage) → `DESIGN.md`; wire format → `spec/repo-format.md`
- Product-facing guides → `docs/guides/`; capability claims → `docs/capabilities.md`

Out-of-scope discoveries are reported on the issue or filed as a new one —
never fixed silently.
