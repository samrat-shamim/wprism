# Suite layout and review

`tools/suite-layout.json` records the surviving flat-to-nested suite moves.
`tools/codemod/move-suites.php` plans and applies that map without deciding
ownership. The complete ratification, amendments, and measurements are retained
in [the historical record](../docs/history/test-layout-migration.md).

## Current authorities

- [AGENTS.md](../AGENTS.md) owns test placement and evidence rules.
- [The sandbox guide](../docs/sandbox.md#execution-classes-and-ownership)
  describes execution classes and ownership.
- `php tools/offline-corpus.php --check` derives the current shared aggregate.
  Its count is not maintained in this document.
- Capsule-local offline suites are discovered dynamically through
  `regress-adapter-packages`; they are not added to this historical move map.
- `sandbox/tests/offline/guards/regress_suite_wiring.php` checks current
  recipes, target/basename agreement, file existence, and class placement.

## Review a layout change

Put tests with their owner. Shared product/engine suites remain in
`sandbox/tests/`; adapter-owned tests, fixtures, and evidence remain in their
capsule; cross-adapter evidence declares its participants in an integration
scenario. A script named as a spike may still be required evidence. Check its
callers and source citations before removing it.

For a shared suite, the execution class is the directory and actual Makefile
wiring, not a judgment based on its name alone. A helper stays with its consumer
and need not have its own target. Corpus membership determines whether a
`regress-*` target is offline or live.

The codemod rewrites executable paths and structural references. It protects
historical prose, scratch, vendor bytes, and its own tests/map/review record.
Those exclusions are deliberate: replaying a move must not restore a retired
suite or reinterpret old measurements as current topology.

## Validation

```sh
php tools/codemod/move-suites.php --plan
php tools/codemod/move-suites.php --prove
php tools/offline-corpus.php --check
make regress-suite-wiring
make regress-offline-all
make release-gate
```

Apply only a reviewed plan in a clean isolated checkout. A class move must
preserve the suite's working directory, owned scratch, fixtures, target, and
product-path assertions. Retiring superseded prototypes removes their map
entries and active commands together; Git retains the earlier implementation.
