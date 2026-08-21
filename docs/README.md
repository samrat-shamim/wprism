# Map of `docs/`

One line per top-level document and directory: what it is, and who reads it.
The normative wire contract is not here — it is
[`spec/repo-format.md`](../spec/repo-format.md) — and the founding rationale is
[`DESIGN.md`](../DESIGN.md).

## Start here

| Path | What it is | Who reads it |
|---|---|---|
| [guides/](guides/README.md) | Ten task-shaped operator guides (quickstart → assess → daily-workflow → release → limits; recovery, code-updates, adapter-authoring, internals). Every command in them is mechanically checked against the shipped CLI. | Operators, first |
| [capabilities.md](capabilities.md) | **Generated** — the certified capability matrix. Written by `tools/capability-doc.php` from the manifests + `manifests/dispositions.json`; `make release-gate` byte-compares it. Never hand-edited. | Anyone asking "will Duo manage this?" |
| [adoption.md](adoption.md) | The SSH adoption contract in full: installing and upgrading Duo on an existing WordPress host. | Operators adopting a real host |
| [product-spec.md](product-spec.md) | The product specification — customer operations, the versionability contract, the vocabularies, safety invariants, non-goals. Carries its own amendment record. | Anyone arguing about what Duo *is* |
| [roadmap.md](roadmap.md) | Owner roadmap: thesis, horizons, standing decisions. Changes only by owner commit. | Direction, not detail |

## Runtime and format references

| Path | What it is |
|---|---|
| [recovery-runtime.md](recovery-runtime.md) | The WordPress-independent PHP recovery runtime and its maintenance-exclusion provider contract. |
| [checkpoint-bundle.md](checkpoint-bundle.md) · [code-release-runtime.md](code-release-runtime.md) · [upload-bundle.md](upload-bundle.md) · [effect-bundle.md](effect-bundle.md) | The four provider slices of verified SSH rollback: database before-image, code before-image, uploads/media, lifecycle-and-rebuild effects. |
| [ssh-rollback-certification.md](ssh-rollback-certification.md) | The local certification harness that gates that design. |
| [assess-vocabulary.md](assess-vocabulary.md) | The six product words `duo assess`, `.duo/contract/projection.json`, the frozen authorization plan and a release refusal all speak. `sandbox/tests/offline/assess-contract/regress_assess_projection.php` is the gate on every cell. |
| [adapter-walk-bundle.md](adapter-walk-bundle.md) | The wire contract for a site-adapter certification bundle: what `duo adapter certify` must produce and `\Duo\AdapterCertification` verifies, rule by rule. |
| [compatibility-baseline.json](compatibility-baseline.json) | **Data, not prose** — read at runtime by `cli/src/Onboarding/Doctor.php`. Do not treat it as a document. |

## Working in this repo

| Path | What it is |
|---|---|
| [dev-setup.md](dev-setup.md) | Fresh checkout to a green gate: prerequisites, gotchas, measured wall times. |
| [sandbox.md](sandbox.md) | The test estate — the pair model, the five execution classes under `sandbox/tests/`, and which gate runs what. |
| [grind/](grind/README.md) | **Live specifications, not history.** Six documents, each specifying a runnable harness and naming its `make` target (`grind-mup`, `grind-adapter-walk`, `grind-adoption`, `grind-code-half-first-sync`, `grind-ecommerce-developer-live`, `regress-adapter-authoring-live`). All live-only; none runs in `make regress-offline-all`. |
| [modules/](modules/README.md) | The module map for `agent/src` and `cli/src`: the index, the layer ladder and its ratchet, and a one-page charter per module. Projected from [`tools/modules.json`](../tools/modules.json), which is the machine-readable authority. |
| [agents/](agents/linear-loop.md) | This repo's own dispatch protocol for coding agents: claim gate, evidence scoping, close gate. Internal process, not product. |

## Design doctrine

Living rulings, not history. Both are cited by `§` number from shipped source,
so their section numbering is load-bearing: extend it, never renumber it.

| Path | What it is |
|---|---|
| [code-half.md](code-half.md) | The `code/` half in full: layout and dependency modes, deploy semantics per transport, the cross-partition invariant (`active_plugins ⊆ plugins in code/`), the plugin-upgrade workflow, engine touchpoints, and the risk register. Nine shipped source citation sites across eight files, `manifests/core.json`'s own note and a `duo doctor` warning string all cite its § numbers. |
| [adapter-boundary.md](adapter-boundary.md) | The owner ruling on where engine core stops and an adapter package starts: the four extension surfaces, the provider contract, the trust tiers. Six shipped source sites cite it by name. |

## Engineering history

These are records, not instructions. They are kept because deleting the reasoning
would leave only the conclusions.

| Path | What it is |
|---|---|
| [design-review-v0.md](design-review-v0.md) | The independent adversarial review of the v0 design, before any code was written. Findings 1–27 are folded into `DESIGN.md`; this preserves the register verbatim. |

The round diaries that used to sit under `proposals/`, `frontier/` and
`grind/` were dissolved on 2026-08-21: every finding they carried was promoted
into the document, manifest note or code comment that needed it, and the
narrative shells were deleted rather than left as paths that outlive their
content. `git log` still has them.
