# Adapter production-readiness contract

This is the work contract for taking every shipped product adapter beyond its
current bounded capability disposition. It is deliberately separate from each
capsule's `package/disposition.json`: a disposition records a reviewed product claim,
while this matrix records the additional hostile evidence required by the
production-readiness goal. It is neither a generated registry nor an
attestation that a test ran.

Each capsule owns its hand-authored matrix at
`adapter-packages/<slug>/evidence/production-readiness.json`. Package-local
validation checks that every scenario family is accounted for, every cited
evidence file stays inside the capsule and exists, and no adapter can be marked
`ready` while an applicable family is a gap or blocked. A `covered` row still
requires human review of the named evidence; the guard proves coverage of the
work ledger, not the truth of a product claim.

## Required scenario families

| family | production assertion |
| --- | --- |
| `contract-dependency` | Exact manifest/spec/artifact identity is pinned. Missing, inactive, wrong-basename, unreadable, below-min, and max-exclusive plugins refuse before mutation. |
| `clean-target` | Exact artifacts on fresh source and target installations pass deploy, create, update, repeated apply, and byte-identical recapture. |
| `dirty-target` | Unmanaged same-key rows, managed divergence, activation defaults, target-only runtime state, adoption, and conflict paths converge or refuse without accidental overwrite. |
| `identity-references` | Source and target IDs deliberately differ for every declared scalar, serialized, structured, shortcode/block, mapped-table, and composite reference kind. |
| `native-behavior` | The target passes plugin-owned API plus frontend, request, render, or admin behavior checks; canonical byte equality alone is insufficient. |
| `derived-state` | Cache, index, CSS, rewrite, lookup, occurrence, or flat-file effects use bounded idempotent repair and a value-level postcondition. |
| `deletion` | Supported deletes prove guards, cascades, mid-delete rollback, and retry. Unsupported deletes prove a loud refusal with no partial publication or target mutation. |
| `failure-recovery` | Provider/action timeout, throw, crash-after-intent, bad receipt, retry, recovery, sidecar/map persistence, and tamper refusal are exercised where applicable. |
| `concurrency-idempotence` | Competing capture/apply/repair operations respect locks; repeated apply and recovery retry yield one correct result. |
| `lifecycle` | Every structurally applicable activation, deactivation, reactivation, same-range upgrade, downgrade handling or out-of-range refusal, uninstall residue, and reinstall path is bounded. A host-integrated adapter with no activatable or uninstallable package must ground that fact in its manifest and exercise the equivalent host replacement/residue boundary. |
| `data-boundary` | Empty/null, long UTF-8, delimiter/serialization hazards, zero/negative/large IDs, malformed payloads, secrets, environment paths/URLs, and schema drift are exercised. |
| `scope-platform` | Single-site/multisite, PHP/database/WordPress boundaries, optional plugin features, unavailable APIs, and every unsupported surface remain explicit and fail closed. |

`not_applicable` is permitted only with a concrete structural reason. A missing
primitive is `blocked`, not `not_applicable`. A missing test for an implemented
path is a `gap`. Adapter work moves a row to `covered` only by naming the exact
test and product files that establish it.

## Evidence order

For each adapter, work proceeds in this order:

1. Pin exact supported and refusal artifacts and close the manifest inventory.
2. Add an independently diagnosable clean-target conformance entry.
3. Force divergent identities and hostile target state.
4. Verify plugin-visible behavior and every declared derived-state effect.
5. Exercise delete/refusal, injected failures, retry/recovery, and concurrency.
6. Exercise lifecycle and difficult-value boundaries.
7. Mark the adapter ready only when every applicable family is covered, then
   update its reviewed disposition and regenerate capability prose.

Shared engine tests can establish a primitive, but they cannot substitute for
the real plugin artifact consuming the resulting state. Conversely, an
adapter-specific happy path cannot substitute for the shared primitive's fault
and atomicity tests. Production readiness requires both.
