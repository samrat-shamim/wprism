# Developer setup

This guide is for contributors working on WPrism. To operate it on a site,
start with [the demo](guides/try-wprism.md) or
[quickstart](guides/quickstart.md). Read [AGENTS.md](../AGENTS.md) before editing;
it owns the dependency, identity, source-cleanliness, and evidence rules.

## Prerequisites

Use PHP 8.3, Composer, a full Git clone, Bash, GNU Make, `jq`, and Python 3.9+.
The development dependencies are pinned by `composer.lock`. Docker with
Compose is needed for the demo and live evidence; the offline gate does not
need a running Docker daemon. `gh` is useful for hosted repository work.

```sh
git clone https://github.com/duotronic-ai/wprism
cd wprism
composer install
bash tools/doctor.sh
```

Use a full clone. Some checks need commit ancestry; a shallow checkout can
look correct while ancestry checks fail. Repair an existing shallow clone with
`git fetch --unshallow`.

The doctor names each failure and its remedy. `bash tools/doctor.sh --fix`
can install the pinned development dependencies and create `sandbox/tmp/`;
it does not repair other failures automatically.

## Make a focused change

Keep the WPrism source checkout separate from managed site repositories.
Use a branch or isolated worktree and preserve other contributors' edits.
Scratch and local logs belong in `sandbox/tmp/` or a temporary directory.

Only the assembled agent and recovery runtime reach a managed site. Composer
and `vendor/` remain development-only. Shipped adapter package and platform
identity inputs are fleet-visible pins: review any edit to them deliberately
and document recompile/re-pin consequences. The exact contract is in
[AGENTS.md](../AGENTS.md#non-negotiables).

## Iterate and validate

| Command | Purpose |
|---|---|
| `composer check` | PHP syntax, PHPStan, changed-file formatting, and PHPUnit. |
| `php tools/offline.php --changed` | Affected suites during iteration. |
| `php tools/affected.php --explain` | Why a suite was selected. |
| `php tools/adapter-package-validate.php --adapter=<slug>` | One capsule's structure, identity, disposition, and evidence wiring. |
| `php tools/adapter-package-tests.php --adapter=<slug>` | One capsule's offline suites. |
| `bash tools/check-docs.sh` | Current documentation commands, local links, and anchors. |
| `make regress-offline-all` | The complete shared offline and dynamic package/scenario gate. |
| `make release-gate` | Source validation, generated artifacts, and documentation checks. |

Syntax-check every touched PHP and shell file. Run the complete required gates
before submitting a change:

```sh
composer check
make regress-offline-all
make release-gate
```

Warnings are not green. Quote the literal canonical gate and record incomplete
checks plainly. `php tools/offline.php -j8` runs the same offline work with
per-suite logs and diagnostic attribution, and isolates suites with fixed
temporary paths. Changed-suite runs are for iteration only.

Stock macOS Make lacks `--output-sync`; use the offline driver for readable
parallel diagnostics. The current corpus count comes from
`php tools/offline-corpus.php --check`. Historical timings are in
[the test-layout record](history/test-layout-migration.md#historical-offline-run-timings).

## Put tests with their owner

- Adapter suites belong in `adapter-packages/<slug>/tests/<class>/` with their
  fixtures and evidence in the same capsule. Offline suites are discovered
  automatically; add no per-adapter Makefile row.
- Shared engine/product suites belong in `sandbox/tests/offline/<domain>/`.
  Add a matching Makefile leaf, then run `php tools/offline-corpus.php`.
- Tooling self-tests belong in `tests/`.
- Cross-adapter evidence belongs in a participant-declared
  `integration-scenarios/<name>/`.

Use [the shared test helpers](../sandbox/tests/lib/README.md) rather than
creating another WordPress database fake. Run native evidence only after an
offline regression pins the mechanism, and scope it to the changed boundary.
[The sandbox guide](sandbox.md) covers pair ownership, budgets, exact source
binding, and cleanup.

## Generated artifacts and architecture

Regenerate a classmap after adding or moving a class. Existing explicit
`require_once` loads remain required; the additive classmap fallback does not
replace them. `make release-gate` byte-checks the generated maps and the other
source projections. Generate limitation and protocol documents through their
tools. Capability claims remain package-owned; render current rows with
`php tools/capability-doc.php render` rather than maintaining an aggregate table.

[The module map](modules/README.md) explains source layers.
[Adapter package architecture](architecture/adapter-packages.md) explains
ownership and assembly. [CONTRIBUTING.md](../CONTRIBUTING.md) covers review,
sign-off, and the public contribution workflow. The internal tracker protocol
applies only to an explicitly dispatched agent task.
