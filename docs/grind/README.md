# `docs/grind/` — live specifications of runnable harnesses

Every file here specifies a harness you can run today. None of them is history:
if a document in this directory describes a step, an assertion, or a refusal,
some script in `sandbox/tests/grind/` implements it and some `make` target
executes it. A grind whose harness is retired leaves this directory with the
harness.

| Document | Harness | Target |
|---|---|---|
| [mup.md](mup.md) | `sandbox/tests/grind/grind_mup.sh` | `make grind-mup` |
| [adapter-walk.md](adapter-walk.md) | `sandbox/tests/grind/grind_adapter_walk.sh` | `make grind-adapter-walk` |
| [adoption.md](adoption.md) | `sandbox/tests/grind/grind_adoption.sh` | `make grind-adoption` |
| [code-half-first-sync.md](code-half-first-sync.md) | `sandbox/tests/grind/grind_first_sync_hook_recovery.sh` | `make grind-code-half-first-sync` |
| [ecommerce-developer.md](ecommerce-developer.md) | `sandbox/tests/grind/grind_ecommerce_developer.sh` | `make grind-ecommerce-developer-live` |
| [duo-3340-adapter-authoring.md](duo-3340-adapter-authoring.md) | `sandbox/tests/live/regress_adapter_authoring_live.sh` | `make regress-adapter-authoring-live` |

Every one of these is **live-only** (docker, a real pair) and deliberately
outside `make regress-offline-all`. Read
[docs/agents/linear-loop.md](../agents/linear-loop.md) §Evidence scoping before
booting a pair, and [docs/sandbox.md](../sandbox.md) for the pair model itself.

The `grind-r1a` / `grind-r1b` / `grind-r1c` / `grind-r3a` / `grind-r3b` rounds
have no document here on purpose: each script *is* its spec, its header states
the fixture and the finding behind every assertion, and the durable conclusions
those rounds reached live in the note strings of the manifests they produced
(`adapter-packages/contact-form-7/package/manifest.json`,
`adapter-packages/ninja-forms/package/manifest.json`,
`adapter-packages/woocommerce/package/manifest.json`,
`adapter-packages/polylang/package/manifest.json`,
`adapter-packages/the-events-calendar/package/manifest.json`,
`adapter-packages/paid-memberships-pro/package/manifest.json`) —
which is where a reader with a real question actually needs them.
