# Duo capability boundary

The exact adapter matrix is rendered from the current checkout rather than
checked in as a second adapter inventory:

```sh
php tools/capability-doc.php render
```

This keeps an ordinary adapter change inside
`adapter-packages/<slug>/`. `make release-gate` still loads the complete
library and refuses incomplete packages, manifest/disposition disagreement,
unsupported disposition vocabulary, invalid evidence citations, platform
boundary drift, or a mismatch with the agent version. It validates the sources;
it does not require a central generated-document edit.

## What a capability claim means

A status means exactly three things:

1. The capsule's `package/manifest.json` declares the managed surface.
2. A human reviewed the claim into the sibling
   `package/disposition.json` and recorded a reason.
3. The disposition names package-local conformance/live evidence, or an
   explicit participant-declared integration scenario, that exercises the
   boundary against WordPress.

It does not mean that a digest seals the claim to a particular test run or
artifact bundle. Outside a declared version, surface, operation, or lifecycle
boundary, Duo refuses instead of guessing.

| Status | Meaning |
|---|---|
| `certified` | Declared, reviewed with a written reason, and exercised by the named evidence. |
| `experimental` | Reviewed and exercised only to the limited boundary its reason and evidence name; production authorization remains blocked. |
| `excluded` | Ships only as a regression fixture and carries no product claim. |
| `uncovered` | Runtime-only result for a manifest with no reviewed disposition; it is never a valid authored status. |

## Sources of authority

- `adapter-packages/<slug>/package/manifest.json` — declared adapter behavior
- `adapter-packages/<slug>/package/disposition.json` — reviewed claim
- `adapter-packages/<slug>/tests/` and `evidence/` — exercised boundary
- `integration-scenarios/<name>/scenario.json` — checked cross-adapter participants
- `platform/adapter-library/` — WordPress core policy, profile vocabulary,
  platform compatibility, and adapter trust roots
- `agent/duo.php` — loaded agent/spec versions

For a particular managed repository, use `duo capabilities <environment>`.
That command evaluates the repository's exact pins and, when available, the
live target rather than the whole source library.

## Computed evidence grades

`php tools/adapter-grade.php render` prints the current evidence grade beside
each reviewed status. A grade is arithmetic over package-owned readiness
records, passing bundle tests when present, and exercised platform cells. It
never widens, narrows, or replaces the reviewed status. See
[adapter-grades.md](adapter-grades.md) for the stable definition.
