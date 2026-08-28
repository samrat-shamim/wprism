# AGENTS.md — operating notes for coding agents in duo-wp

Read this before your first edit. It is the short, factual version; the setup
walkthrough is [docs/dev-setup.md](docs/dev-setup.md) and the dispatch/evidence
protocol is [docs/agents/linear-loop.md](docs/agents/linear-loop.md).

**CI is disabled by owner decision; local evidence is the gate.** Nothing else
about CI belongs in a PR, an issue, or this file.

## Repo map

| path | what it is | ships? |
| --- | --- | --- |
| `agent/` | the WordPress drop-in. Source files keep explicit dependency loads; the generated additive `agent/duo-classmap.php` is only a fallback (rule 1). Adoption assembles the selected platform and adapter packages into `agent/adapter-library/`; that embedded directory is an output, not a second checked-in source. | yes |
| `cli/` | the `duo` orchestrator (`cli/duo` is an extensionless `#!/usr/bin/env php` executable) | yes |
| `recovery/` | the recovery runtime (canonical JSON, atomic store, Ed25519 rollback control) | yes |
| `adapter-packages/` | one self-contained capsule per plugin adapter: `package/manifest.json`, `package/disposition.json`, named runtime hooks, and its own `tests/`, `fixtures/`, and `evidence/`. Only `package/` is assembled into the installed library; authoring or testing one adapter stays inside its capsule. | package bytes only |
| `platform/adapter-library/` | the non-plugin library source: core manifest/disposition, profiles, platform compatibility, and adapter-authority trust roots. The assembler combines this with adapter packages. | yes, embedded |
| `integration-scenarios/` | explicitly participant-declared cross-adapter evidence. A scenario is not owned by any one capsule and therefore selects the global aggregate gate. | no |
| `sandbox/` | shared engine/test infrastructure: pair management, global offline/live/grind/certify/spike suites, shared libraries, site repositories, and gitignored scratch. Adapter-owned tests and conformance assets live in their capsules. | no |
| `tools/` | dev entry points, package assembly/validation/testing, generated-document checks, affected-test analysis, and the generated global offline-corpus include. `regress-adapter-packages` dynamically discovers capsule-local offline tests, so a new package test adds no Makefile row. | no |
| `tests/` | PHPUnit 11 self-tests for `tools/` (`Duo\Tests\…`, PSR-4) | no |
| `scripts/` | `adapter-certification.php` (reviewer-facing adapter certificate sign/verify), `agent-bootstrap.sh`, `close-gate-check.sh` | mixed |

`cli/src/Onboarding/Adopt.php` assembles `adapter-packages/*/package/` and
`platform/adapter-library/` into staging's `agent/adapter-library/`, then tars
exactly `agent recovery`. Tests, fixtures, evidence, and source capsules never
reach a managed site.

## Non-negotiables

1. **The drop-in is dependency-free.** No composer, no vendored packages,
   nothing fetched at runtime inside `agent/`, `cli/`, `recovery/`. A new file
   in `agent/src` requires its own dependencies, exactly like its 246 siblings.
   `agent/duo-classmap.php` does not change that contract: it is a *generated
   additive fallback* that only ever fires for a class still undeclared at the
   moment it is referenced, so it resolves nothing on the production path and
   exists for partially-loaded contexts (`agent/duo.php:106-138` states the
   three properties that keep it behaviour-neutral). Regenerate it with
   `php tools/classmap-generate.php`; `make release-gate` byte-checks it.
   `vendor/` is dev-only and structurally unreachable from a site.
2. **Shipped package and platform-library bytes are adapter identity.**
   `ArtifactPolicyIdentity::manifest_rows()` folds each manifest's JSON, its
   disposition, and `hash_file('sha256', …)` of every interpreter, provider and
   regenerator file that manifest names
   (`agent/src/Policy/ArtifactPolicyIdentity.php:60-115`); `manifest_hash()`
   and each adapter's `digest` are that one row hashed (`:127`, `:147`). So a
   one-byte edit to a capsule's `package/` identity inputs, or to the platform
   library inputs that participate in an identity, is fleet-visible: a deployed
   site
   holding a compiled artifact refuses with
   `compiled_artifact_manifest_mismatch` — "compiled manifest/interpreter set
   does not match active pins"
   (`agent/src/Repository/CompiledArtifactReader.php:39-42`) — and every
   `site.duo.json` content pin stops matching. The remedy is recompile and
   re-pin (`wp duo manifest-pin` emits the copy-pasteable object), never a
   fallback. Nothing under `agent/src` or `cli/src` has this property: a
   namespace or class-file move there moves no digest and costs nothing.
3. **Never leave scratch under `agent/`, `adapter-packages/`, or `platform/`.**
   `sandbox/bin/pair.sh` gates on those exact candidate source roots and refuses
   before any pair mutation — "candidate source is DIRTY" — because they are
   the bytes its Compose configuration bind-mounts and the assembler consumes.
   An untracked file counts. Scratch goes in `sandbox/tmp/` or a `mktemp -d`.
4. **Put tests with their owner.** An adapter-owned suite goes under
   `adapter-packages/<slug>/tests/<class>/`; the fixed
   `regress-adapter-packages` row discovers every capsule and runs its complete
   offline gate, so adding or changing that adapter requires no Makefile or
   generated-corpus edit. A shared engine/product suite still goes in
   `sandbox/tests/offline/<domain>/`, needs its own Makefile leaf, and then
   `php tools/offline-corpus.php`. Cross-adapter evidence belongs under a named
   `integration-scenarios/<name>/` with a versioned participant record. Never
   hand-edit `tools/offline-corpus.mk`: it is the derived global aggregate,
   and `make release-gate` byte-compares it.
5. **New shared suites use `sandbox/tests/lib/`** (`check.php`, `wp_stubs.php`,
   `FakeWpdb.php`) — see `sandbox/tests/lib/README.md` for the skeleton. Don't
   write an eleventh bespoke `$wpdb` fake.
6. **No mass reformat.** `php-cs-fixer` runs on changed files only, by design.
   A whole-tree fix buries the reviewable change in thousands of lines nobody
   read, and under an adapter capsule's `package/` it would move that
   `adapter_digest` (rule 2).
7. **`declare(strict_types=1)` in new files only.** Adding it to an existing
   file changes that file's bytes and its coercion behaviour.
8. **Keep byte-identical unless the issue is explicitly about changing them:**
   canonical JSON output, refusal envelopes and their messages, WP-CLI output,
   lock acquisition order, adapter `package/` bytes (rule 2 — they *are*
   adapter identity), and the `define('DUO_AGENT_VERSION', …)` /
   `define('DUO_SPEC_VERSION', …)` lines in `agent/duo.php`.
   `platform/adapter-library/capabilities/platform.json` restates both defines,
   and
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
php tools/adapter-package-validate.php --adapter=<slug> # one capsule's structure/identity/evidence
php tools/adapter-package-tests.php --adapter=<slug>    # one capsule's offline tests
make regress-offline-all          # dynamic global aggregate merge gate — quote it in the PR
make release-gate                 # capability/grade source validation plus generated
                                  # protocol/gap/wire docs, classmaps, corpus and adapter kit
```

The gate runs every global offline leaf plus the one fixed
`regress-adapter-packages` aggregate, which discovers every capsule and its
package-local offline suites dynamically. The global row count is derived from
the shared suite files by `php tools/offline-corpus.php`; package test additions
do not change the Makefile or `tools/offline-corpus.mk`.
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
**declared** by `adapter-packages/<slug>/package/manifest.json`, **reviewed**
in its sibling `package/disposition.json` by a human who wrote down why, and
**exercised** by the named package-local conformance/live evidence (or an
explicit participant-declared integration scenario). It does not
mean a digest binds that claim to an artifact set or a specific run.

`tools/capability-doc.php render` projects the current matrix from the adapter
packages, `platform/adapter-library/`, and `agent/duo.php` to stdout. `make
release-gate` runs its source check without checking in an aggregate adapter
inventory, so an ordinary adapter edit remains capsule-local. Its four
cross-checks mirror rules `ManifestDispositions` enforces at agent load time;
the header comment in `tools/capability-doc.php` is the authority and the first
thing to read before touching a claim.

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
