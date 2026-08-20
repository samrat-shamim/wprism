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
| [compatibility-baseline.json](compatibility-baseline.json) | **Data, not prose** — read at runtime by `cli/src/Onboarding/Doctor.php`. Do not treat it as a document. |

## Working in this repo

| Path | What it is |
|---|---|
| [dev-setup.md](dev-setup.md) | Fresh checkout to a green gate: prerequisites, gotchas, measured wall times. |
| [sandbox.md](sandbox.md) | The test estate — the pair model, the five execution classes under `sandbox/tests/`, and which gate runs what. |
| [modules/](modules/README.md) | The module map for `agent/src` and `cli/src`: the index, the layer ladder and its ratchet, and a one-page charter per module. Projected from [`tools/modules.json`](../tools/modules.json), which is the machine-readable authority. |
| [agents/](agents/linear-loop.md) | This repo's own dispatch protocol for coding agents: claim gate, evidence scoping, close gate. Internal process, not product. |

## Engineering history

These are records, not instructions. They are kept because deleting the reasoning
would leave only the conclusions.

| Path | What it is |
|---|---|
| [proposals/](proposals/) | Design proposals as written, at the date written — the boundary doctrine, the code half, verified SSH rollback, and the round-3 program (MUP, the adapter walk, adoption situations). Several are cited from shipped source and their paths are load-bearing. Where a proposal's mechanism has since been retired or renamed, it carries a dated framing note saying so rather than being quietly rewritten. |
| [frontier/](frontier/) | Gap reports for plugins at the edge of what Duo supports (Elementor, Polylang, FSE): what was tried, what refused, and why. |
| [grind/](grind/) | Narrative reports from grind runs — realistic multi-plugin stacks walked end to end. **Mixed by design:** ten of them still have a live `make grind-*` target and are re-runnable specs (`r1a`, `r1b`, `r1c`, `r3a`, `r3b`, `mup`, `adapter-walk`, `adoption`, `ecommerce-developer`, `code-half-first-sync`); the rest (`r2-mitigation`, `r3-round`, `code-half`, `code-half-ecosystem`, `duo-3340-adapter-authoring`) are the discovery trail that produced them, with no target. Several are cited by path from shipped manifest note strings, so none of these files may move. |
| [design-review-v0.md](design-review-v0.md) | The independent adversarial review of the v0 design, before any code was written. Findings 1–27 are folded into `DESIGN.md`; this preserves the register verbatim. |
