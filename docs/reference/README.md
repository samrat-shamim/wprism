# Detailed references

Use the [task guides](../guides/README.md) for an ordered workflow. These pages
hold configuration, command contracts, and authoring recipes. Built-in
`cli/wprism help <command>` owns command and flag syntax; the
[repository specification](../../spec/repo-format.md) owns the normative format.

## Operating a site

| Reference | Use it for |
|---|---|
| [Onboarding](onboarding.md) | Explicit bootstrap, lower-level adopt/init, updates, manual seeds, and second environments. |
| [Environment registry and transports](environments.md) | Registry provenance, local/Docker/SSH configuration, and branch providers. |
| [Environment-bound values](environment-values.md) | Provisioning and the target-local intended-value authority. |
| [CLI command contracts](cli-commands.md) | Detailed behavior, exit codes, machine receipts, private evidence, and semantic planning. |
| [Adoption protocol](../adoption.md) | Installation, compensation, update, and transport contracts. |
| [Readiness vocabulary](../assess-vocabulary.md) | The words used by assessment, contracts, and authorization plans. |
| [Capabilities](../capabilities.md) and [adapter grades](../adapter-grades.md) | Meaning and source of reviewed support and evidence grades. |

## Authoring an adapter

Start with [the contribution and authoring guide](../guides/adapter-authoring.md).

| Reference | Use it for |
|---|---|
| [Library ownership](adapter-library.md) | Capsule structure, shipped files, identity, and precedence. |
| [Fields and references](adapter-fields.md) | Minimal examples, native keyspaces, block attributes, encoded data, caches, and deletion. |
| [Actions and providers](adapter-actions.md) | Repair work, lifecycle capabilities, schema settlement, and incompatibilities. |
| [Validation and preflight](adapter-validation.md) | Offline grammar, schema documents, hostile cases, and harness setup. |
| [Detailed workflow](adapter-workflow.md) | Observation through draft, probe, certification, pinning, and exercise. |
| [Dispositions](adapter-claims.md) | Reviewed status, package-owned evidence, and scope amendments. |
| [Certification and trust](adapter-trust.md) | Site adapters, signing roots, overrides, and plugin-bundled adapters. |
| [Constraints](adapter-constraints.md) | Extension channels, scalar constraints, and remaining limits. |

[Generated authoring limitations](../guides/adapter-authoring-limitations.md)
come from the current grammar. Historical reviews belong in
[the history index](../history/README.md).
