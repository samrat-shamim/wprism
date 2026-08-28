# Adapter evidence grades

Render the exact grades for the current checkout without creating a central
adapter edit point:

```sh
php tools/adapter-grade.php render
```

`make release-gate` validates every package-owned input and recomputes the
model in memory. Adding or updating one adapter therefore does not require an
edit to this document.

## A grade is not a status

The reviewed status remains the claim authority described in
[capabilities.md](capabilities.md). The grade is arithmetic over evidence that
already exists and cannot authorize an adapter, widen a version range, or turn
an experimental disposition into a certified one.

The three axes are:

| Axis | Unit | Source |
|---|---|---|
| Coverage breadth | One reviewed scenario family | `adapter-packages/<slug>/evidence/production-readiness.json`, or the platform-owned core record |
| Exercise depth | One named passing bundle test | A verified certification bundle's per-test pass map |
| Platform reach | One exercised-series cell | `platform/adapter-library/capabilities/platform.json` |

An absent evidence document is **silent** and leaves the arithmetic. A present
document recording no exercised units is **none** and counts against the grade.
The combined grade is the weakest present axis: `none`, `partial`, or
`complete`. Platform reach alone cannot mint a grade because it describes the
claim's environment rather than adapter evidence.

Every rendered row names the source paths and counted units so reviewers can
inspect the package-owned records directly.
