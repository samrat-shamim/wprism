# WPrism documentation

Start with the task that matches your work. The
[repository specification](../spec/repo-format.md) is the normative format;
[DESIGN.md](../DESIGN.md) explains the architecture.

## Use WPrism

| Task | Start here |
|---|---|
| Evaluate a page change on disposable sites | [Try WPrism](guides/try-wprism.md) |
| Check a real site's host and supported scope | [Site eligibility](guides/site-eligibility.md) |
| Connect and create the first baseline | [Quickstart](guides/quickstart.md) |
| Decide and record a site's managed boundary | [Assessment](guides/assess.md) |
| Capture, review, and reconcile daily work | [Daily workflow](guides/daily-workflow.md) |
| Prepare, authorize, execute, and verify a release | [Release](guides/release.md) |
| Rehearse recovery and understand its limits | [Recovery](guides/recovery.md) |
| Change plugin/theme code or upgrade a fleet | [Code updates](guides/code-updates.md), [flag day](guides/flag-day.md) |

[All task guides](guides/README.md) · [Detailed references](reference/README.md)
· [CLI help and configuration](../cli/README.md)

## Contribute and maintain

| Work | Starting point |
|---|---|
| First contribution and public review | [CONTRIBUTING.md](../CONTRIBUTING.md) |
| Plugin adapter contribution or site-specific authoring | [Adapter authoring](guides/adapter-authoring.md) |
| Developer environment and local gates | [Developer setup](dev-setup.md) |
| Test ownership, live pairs, and scratch cleanup | [Sandbox](sandbox.md) |
| Publish or support a release | [Release procedure](maintainers/releases.md) |
| Agency pilots and community priorities | [Community roadmap](community-roadmap.md) |

[Governance](../GOVERNANCE.md), [conduct](../CODE_OF_CONDUCT.md), and
[security reporting](../SECURITY.md) apply to project spaces. The
[agent dispatch protocol](agents/linear-loop.md) is an internal workflow for
explicitly dispatched tasks; contributors do not need an internal tracker.

## Support and architecture references

- [Capabilities](capabilities.md) explains declarations, review, and evidence.
  Render the current matrix with `php tools/capability-doc.php render`.
- [Adapter grades](adapter-grades.md) explains computed evidence grades.
  Render current rows with `php tools/adapter-grade.php render`.
- [Product specification](product-spec.md) governs operations and launch claims;
  [owner roadmap](roadmap.md) retains standing decisions and engineering history.
- [Module map](modules/README.md), [adapter packages](architecture/adapter-packages.md),
  [code design rationale](code-half.md), and [adapter boundary](adapter-boundary.md)
  explain source ownership and extension contracts.
- [Adoption](adoption.md), [readiness vocabulary](assess-vocabulary.md), and
  [recovery runtime](recovery-runtime.md) explain the installed control plane.
- [Checkpoint](checkpoint-bundle.md), [code](code-release-runtime.md),
  [upload](upload-bundle.md), [effect](effect-bundle.md), and
  [SSH rollback certification](ssh-rollback-certification.md) describe recovery
  provider slices and their evidence.

## Contracts and runtime data

The first three pages are generated: change their source and regenerate them.
`make release-gate` checks those projections and the compatibility baseline.
The certification bundle page is a hand-authored wire reference.

| Artifact | Source or role |
|---|---|
| [Branch-environment provider contract](branch-environment-provider.md) | `tools/provider-protocol-doc.php` |
| [Wire-surface register](wire-surface.md) | `tools/wire-surface.php` |
| [Authoring limitations](guides/adapter-authoring-limitations.md) | `tools/adapter-gap-doc.php` |
| [Site-adapter certification bundle](adapter-walk-bundle.md) | Wire reference used by the certification runtime. |
| [Compatibility baseline](compatibility-baseline.json) | Runtime data read by the host doctor. |

[Active grind specifications](grind/README.md) stay beside runnable harnesses.
[Historical reviews](history/README.md) preserve earlier reasoning and measurements.
The [database dialect audit](mysql-dialect-audit.md) explains the retained live
probe groups; it does not maintain a separate support matrix.
