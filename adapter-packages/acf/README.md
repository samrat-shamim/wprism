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

The full offline gate discovers both from `tests/offline/` and generates their
Make leaf rules. Adding another `regress_*.php` or `regress_*.sh` here therefore
does not require a global Makefile or corpus-list edit.

## Inventory still on shared infrastructure

These ACF-only assets remain global until the live/conformance runner can
discover package-local entries without its shared registries:

- `sandbox/conformance/{entries,seeds,checks,postdeploy}/acf.*`
- `sandbox/conformance/boundary/acf.site.duo.json`
- `sandbox/conformance/boundary/advanced-custom-fields.releases.json`
- `sandbox/tests/certify/matrix.d/acf.sh`
- `sandbox/tests/live/regress_acf_term_options_fields.sh`
- `sandbox/tests/spike/spike_e_acf.sh`

`sandbox/conformance/manifests.json`,
`sandbox/conformance/production-readiness.json`, and
`sandbox/tests/certify/certify_version_matrix.sh` contain ACF rows but are
shared cross-adapter registries/drivers rather than ACF-owned files. Engine
suites that include ACF as one fixture or policy participant also remain in
their engine domains; they are not adapter-owned merely because they name ACF.
