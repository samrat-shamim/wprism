# cli: Assess

**Purpose.** The assess mechanism — stack inventory and the authority block, the per-surface catalog carrying the six spec dimensions, the closed gap-action set, the `duo-assess-report/v1` document and its bounded human renderer. The verb boundary (`AssessCommand`) lives in `cli/src/Command/`; `Doctor`, the environment registry and the adapter catalog are called there and passed in as data.

**Directory** `cli/src/Assess/` &middot; **layer** `engine` &middot; **files** 5 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `StackInventory`, `SurfaceCatalog`, `GapActions`, `AssessReport`, `AssessRenderer`.

**May depend on:** `Assess`, `Contract`, `Plan`, `Transport`, `agent:Adapter`, `agent:Assess`, `agent:Kernel`.

**Ratified exceptions.** None, and none designed. `Doctor`/`BootstrapEligibilityReport`/`Init` (Onboarding), the environment registry (Environment) and `AdapterCatalog` (Adapter) are all reached from the surface, not from here — that is what keeps this module free of intra-layer and upward edges. Every class in it takes arrays and returns arrays; none holds a driver.

**Must not depend on.** Anything that writes to a target.

**What each file is** (round-3 MUP §4.1):

| file | responsibility |
| --- | --- |
| `StackInventory.php` | the target stack block, proved and passed through verbatim, plus the `authority` block: transport, doctor verdict, the read-only adoption/init probes, installed code identity and the adapter sources this run could reach |
| `SurfaceCatalog.php` | one row per WordPress-language surface, each carrying the six §1 dimensions per operation, derived from `policy.surface_groups`, `Coverage`'s undeclared tables, the registry claim's `surfaces[]` and the contract's `surface_labels` |
| `GapActions.php` | the closed gap-action set: the per-surface reduction across operations, the unknown-section mapping, and the summary counts |
| `AssessReport.php` | the `duo-assess-report/v1` document, its `assess_digest`, the `unknown` and `evidence` blocks, and the contract-proposal seed |
| `AssessRenderer.php` | the human projection of that one document, bounded per §4.6 (50 rows, `--limit=1..200`, `N more (use --format=json)`) |

**Known debts.**

- **No plugin slug may appear in this directory**, and `sandbox/tests/offline/assess-contract/regress_assess_composition.sh` greps it against a forbidden set derived from the shipped manifest library. Surfaces are named by data; the catalog knows only the *grammar* of those names.
- `provider_negotiation` is a declared seam that this profile always fills with an empty list: MUP §1.3 lists `Providers::diagnose()` problems as a readiness input, and §2.1's composition has no source for them (diagnose() runs inside plan/apply). `duo status` remains the surface that reports them.
- `env_missing` conditions likewise never reach a row: they are plan rows, and assess deliberately runs no plan. `provision env value` is therefore reachable in `GapActions` but unexercised by `duo assess` today.
- Containment and recovery are derived from the policy class alone (§1.5/§1.6) because MUP has no egress control. Each inference is stated in `SurfaceCatalog`'s docblock rather than measured.

**Sub-namespace plan.** Target `Duo\Orchestrator\Assess\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
