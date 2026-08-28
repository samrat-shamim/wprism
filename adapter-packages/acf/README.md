# ACF adapter package

`package/` is the complete shipped ACF adapter. Its manifest, reviewed
disposition, and interpreter move together and keep their original bytes.

Run the package-owned deterministic suites with:

```bash
php tools/adapter-package-tests.php --adapter=acf
```

The package owns these offline suites and their existing logical IDs:

- `regress-acf-meta-interpreter`
- `regress-acf-production-readiness`

The fixed `regress-adapter-packages` aggregate discovers both from
`tests/offline/`; it does not generate per-suite Make rules. Adding another
`regress_*.php` or `regress_*.sh` here therefore requires no global Makefile or
corpus-list edit. Package live and spike suites are discoverable through the
same package runner and can be listed without running them:

```bash
php tools/adapter-package-tests.php --adapter=acf --class=live --list
php tools/adapter-package-tests.php --adapter=acf --class=spike --list
```

The remaining ACF-owned evidence and fixture assets now live here:

- `tests/conformance/{entry,seed,check,postdeploy}.*` — the existing
  `conformance-acf` entry and its phase hooks
- `tests/certify/version-matrix.sh` — the ACF functions exercised by the
  shared `exact-artifact-version-matrix` driver
- `fixtures/boundary/{releases,site.duo}.json` — reviewed boundary-probe inputs
- `tests/live/regress_acf_term_options_fields.sh` —
  `regress-acf-term-options-fields`
- `tests/spike/spike_e_acf.sh` — the historical `spike-e` target

## Shared cross-adapter infrastructure

No ACF-only test, configuration, or fixture file remains in the global
conformance/test trees. These shared files still mention ACF because they
coordinate or report across adapters:

- `sandbox/conformance/run.sh` resolves the package entry and hooks from this
  capsule.
- `evidence/production-readiness.json` is ACF's readiness ledger; shared
  readiness tooling aggregates capsule-owned ledgers when a project-wide view
  is requested.
- `sandbox/tests/certify/certify_version_matrix.sh` discovers package
  certification hooks and remains the shared matrix driver.
- `Makefile` keeps the historical `spike-e` compatibility alias and the human
  live-suite inventory; the offline corpus keeps one fixed dynamic package
  aggregate rather than generated package leaf rules.

Engine suites that include ACF as one fixture or policy participant remain in
their engine domains; they are not adapter-owned merely because they name ACF.
