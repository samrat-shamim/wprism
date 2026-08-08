# Lifecycle and rebuild effect rollback contracts

The effect bundle is the lifecycle/rebuilder/regenerator slice of verified
SSH rollback. It compiles every possible mutation into an immutable inventory,
prepares prior evidence while maintenance exclusion is held, binds that
evidence to the signed rollback receipt, and refuses undeclared runtime effects.
It is a substrate for the integrated automatic profile owned by DUO-3299; the
existing standalone `duo promote` path does not yet use it end to end.

## Manifest grammar

Plugin/theme lifecycle effects use top-level `lifecycle_effects`. A rebuilder
uses `rebuilders[].effects`; a row-keyed regenerator uses
`post_types.<type>.regen_dependency.effects`. Each is a non-empty list. Every
effect has exact `id`, `kind`, `mode`, and `selector` fields:

```json
{
  "id": "probe-http",
  "kind": "http",
  "mode": "prevented",
  "prevention": "receipt_outbox",
  "selector": {
    "scope": "external",
    "type": "url_prefix",
    "value": "https://example.test/hooks/"
  }
}
```

Modes have intentionally different proof obligations:

- `restorable` is limited to `database_checkpoint` selectors naming an exact
  table or option. The encrypted checkpoint is its before-image.
- `reversible` requires a version-pinned `adapter` object with exact `id`,
  `version`, `inverse`, `inverse_inputs`, `verifier`, and `verifier_inputs`.
  Inverse and verifier input lists are non-empty receipt field names.
- `prevented` is limited to mail, HTTP, and queue effects with
  `prevention: receipt_outbox`. A reporting observer is not prevention.
- `irreversible` is an explicit automatic-profile blocker. A generic
  `plugin_lifecycle` selector must use this mode because arbitrary third-party
  hooks cannot truthfully claim a bounded inverse.

Selectors use exact `{scope,type,value}` objects. Wildcards, control bytes,
traversal, unbounded or query-bearing URLs, secret-shaped values, missing
fields, and unknown fields are rejected during policy compilation. Missing
lifecycle/rebuilder/regenerator declarations compile to explicit
`irreversible` rows; they never silently disappear from the inventory.

The compiled artifact and plan carry a deterministically sorted
`effects_inventory`. Engine-owned future-post scheduling and taxonomy counts
are checkpoint-restorable. The external object cache is reversible only
through its pinned inverse/readback contract.

## Target preparation and receipt binding

Configure an absolute provider argv vector:

```json
{
  "rollback_recovery": {
    "effect_provider": ["/opt/duo/bin/effect-provider", "production"]
  }
}
```

`recovery-probe` requires canonical evidence that the provider supports
database-checkpoint mapping, external resources, receipt outbox prevention,
and inverse readback without exposing credentials. After maintenance exclusion
is held, the controller sends a signed `duo-effect-bundle-request/v1` containing
the compiled inventory. Any irreversible, malformed, incompatible, secret-
shaped, undeclared, or unbounded effect blocks before the provider and before
code mutation.

For accepted rows the provider writes immutable prior evidence. Restorable
rows name their checkpoint coverage, reversible rows bind exact inverse and
fresh-verifier input hashes plus prior state, and prevented rows reserve a
receipt-specific outbox identity. Duo independently validates and hashes both
the compile inventory and prior evidence. The metadata digest becomes the
receipt's provider-owned `lifecycle_receipts_sha256`; callers cannot supply it.

## Runtime reconciliation and rollback

Every actual lifecycle, rebuilder, or regenerator effect must reconcile by
manifest, phase, effect id, kind, and exact selector to the prepared inventory.
An undeclared or broadened effect is a hard refusal and does not add authority.
For prevented effects, the provider must return a receipt-bound outbox digest
and attest that the external call was prevented. Observation alone is rejected.

`effects_inverse` runs only in `rolling_back` with an exact signed prepared
operation and input digest. The provider executes idempotent inverses using the
frozen receipt inputs. Duo then starts a second provider process for
`verify-prior`; its readback digest must exactly equal the inverse result.
Database rows remain the checkpoint executor's responsibility. External
caches, search indexes, cron services, queues, and files remain distinct
provider resources and cannot hide behind database coverage.

The offline certification is `make regress-effect-bundle` and is included in
`make regress-offline-all`.
