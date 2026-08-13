# Duo — Branchable WordPress

<!-- BEGIN GENERATED CAPABILITY SUMMARY -->
- **Certified authored-state adapters:** acf, contact-form-7, core, elementor, ninja-forms, polylang, woocommerce, yoast.
- **Experimental and promotion-blocking:** paid-memberships-pro, the-events-calendar.
- **Evidence:** bundle `0defd038c00ccf81720c9c6c9481af5719ea2d7948a274cbb808ec7ffd6a6e5c` (candidate); exact versions, operations, surfaces, and unsupported boundaries are in [the generated capability document](docs/capabilities.md). Plugins run unmodified; only registry-named authored state is branchable.
<!-- END GENERATED CAPABILITY SUMMARY -->

Duo makes the registry-certified authored surfaces of a WordPress site **branchable like code** — branch, edit, merge, promote — without modifying plugin or theme source. Authored content and configuration live canonically in a git repository; runtime data (orders, comments, sessions, caches) stays environment-local and untouched. Anything Duo cannot classify or certify is refused loudly, never guessed — missing registry data is unsupported.

**Status:** the correctness core is complete (H1 exit, 2026-08-08 — no known path by which authored data silently fails to propagate), and the running horizon is adoption on real hosts: a real SSH adoption run, certified crash-rollback machinery, and automatic verified-rollback promotion are in; a first third-party production site is next. Direction and the honest boundary live in [docs/roadmap.md](docs/roadmap.md).

## How it works

WordPress state is classified along three axes — *who authors it*, *env-portability*, *source-vs-derived*. The branchable partition (`human-authored ∧ portable ∧ source`) is represented as entity-per-file canonical text (posts are canonical-JSON front matter over a raw Gutenberg body) keyed by UUIDs, with environment-bound values tokenized (`{{home}}`, `{{uploads}}`, `{{post:<uuid>}}`, `{{term:<uuid>}}`, `{{tt:<uuid>}}`, `user:<login>`), so git provides history, branching, and three-way merge. A per-environment ledger maps UUIDs to local auto-increment ids across typed keyspaces; apply is a terraform-style plan → two-phase hook-free write → derived-state rebuild pass, so applying never re-fires emails or webhooks and never leaves declared derived state stale. Classification comes from exact core rules, version-pinned plugin manifests, site policy, and a provenance journal that only ever *proposes* — everything unknown is **loudly blocked, never silently guessed**.

- **Normative contract:** [spec/repo-format.md](spec/repo-format.md) — entity file formats, identity, tokens, ledger, apply semantics.
- **Design rationale:** [DESIGN.md](DESIGN.md) — the first-principles state partition and the adversarial review that hardened it.

## Getting started

The [guides](docs/guides/README.md) are narrative and task-shaped, and every command in them is mechanically checked against the shipped CLI. Reading order for someone new: [quickstart](docs/guides/quickstart.md) → [daily-workflow](docs/guides/daily-workflow.md) → [capabilities-and-limits](docs/guides/capabilities-and-limits.md), then [code-updates](docs/guides/code-updates.md) and [adapter-authoring](docs/guides/adapter-authoring.md) as the need arises. Installing onto an existing SSH WordPress host is [docs/adoption.md](docs/adoption.md) — it bootstraps the agent, manifests, and seed repo without requiring Git or shared volumes on the host.

## The `duo` CLI

Host-agnostic, dependency-free PHP orchestration ([cli/](cli/); full reference in [cli/README.md](cli/README.md)) over **local**, **Docker**, and **SSH** transports, driven from a committable environment registry.

- **Environment-bound:** `adopt`, `init`, `status`, `doctor`, `capture`, `plan`, `apply`, `deploy`, `promote`, `pending`, `classify`, `capabilities`, `explain`, `coverage`, `scope`, `env-set`, `refresh`, `rebase`, `adapter-observe`, `driver-capabilities`.
- **Repo-local (no environment):** `envs`, `env materialize|reap`, `manifest-validate`, `adapter-draft`, `adapter`.

`duo capabilities` reports the same external disposition, exact scope, unsupported surfaces, and evidence consumed by readiness and promotion. `duo promote` composes deploy-before-apply under promotion locks and, when the target proves every rollback capability, automatically selects the **verified-rollback profile** (DUO-3310); otherwise it remains operator-directed with an explicit warning.

The core loop for unclassified writes: loud block → `duo pending <env>` (journal-evidenced proposals, ref hints, secret flags) → `duo classify <env>` triage (interactive or `--accept-proposals`; secrets can never be authored silently) → clean capture → `wp duo policy-to-manifest` export. `duo adapter-draft` turns captured state into inert `_draft` manifest candidates for human ratification.

## Evidence, not assertions

Certified capability claims are generated, never written by hand. Reviewed dispositions ([manifests/dispositions.json](manifests/dispositions.json)) are kept separate from the manifests so no manifest can certify itself; clean-room conformance runs ([sandbox/conformance/](sandbox/conformance/)) emit content-addressed evidence; the generated [capability registry](manifests/capabilities/registry.json) binds the two to exact adapter digests and is the single source behind the summary at the top of this file, [docs/capabilities.md](docs/capabilities.md), `duo capabilities`, readiness, and promotion gates. `make release-gate` byte-compares all of it and fails on drift. Certification can also *reduce* capability: working but unprovable behavior is removed and refused, not shipped under-proven.

## Layout

| Path | What |
|---|---|
| [spec/repo-format.md](spec/repo-format.md) | The normative site-repo contract: entity formats, tokens, ledger, apply semantics |
| [DESIGN.md](DESIGN.md) | Founding design record: state partition, classification policy, identity model, GitOps semantics |
| [docs/guides/](docs/guides/README.md) | Operator guides: quickstart, daily workflow, code updates, adapter authoring, limits |
| [docs/adoption.md](docs/adoption.md) | Installing/updating Duo on an existing SSH WordPress host |
| [docs/roadmap.md](docs/roadmap.md) | Owner roadmap: thesis, horizons, standing decisions |
| [agent/](agent/) | The Duo agent — drop-in mu-plugin + `wp duo …` engine commands |
| [cli/](cli/) | The `duo` orchestrator CLI + transports |
| [manifests/](manifests/) | Classification manifests, reviewed dispositions, and the generated evidence-bound capability registry |
| [recovery/](recovery/) | WordPress-independent rollback runtime: checkpoints, code releases, upload and effect bundles |
| [sandbox/](sandbox/) | Dockerized disposable environment pairs + acceptance, conformance, and certification suites |

## Development & verification

Requires Docker. Disposable WordPress environment pairs run against one shared MariaDB ([docs/sandbox.md](docs/sandbox.md)):

```sh
make pair-up PAIR=<name>       # bring up an env pair
make pair-list                 # list pairs
make pair-reset PAIR=<name>    # wipe a pair back to baseline
make pair-destroy PAIR=<name>  # tear it down
```

The acceptance suite that proved the engine's load-bearing claims runs as permanent regression:

```sh
make setup            # boot the legacy two-env stack (A: :8801, B: :8802) and init the site repo
make spike-a          # round-trip: capture → apply → re-capture, byte-identical, runtime untouched, canary clean
make spike-b          # merge: divergent edits, real git conflict, drift preserved, converged environments
make spike-c          # provenance: admin vs anonymous writes vs manifest ground truth
make spike-d          # WooCommerce catalog round-trip + fail-closed product deletion
make spike-e          # ACF interpreter round-trip
make spike-f          # the core loop: block → pending → classify → capture → manifest export
make conformance-<m>  # per-manifest clean-room gate (core, woocommerce, acf, yoast, …)
make cli-smoke        # duo CLI end-to-end over the docker transport
make cli-triage-smoke # interactive duo classify triage end-to-end
make release-gate     # regenerate-and-compare: evidence, registry, and product prose must agree
make down             # stop; `make clean` also deletes volumes
```

Beyond these, the Makefile carries the full certification and regression surface — `certify-*` (including `certify-ssh-rollback` and `certify-ssh-adoption-roundtrip`), `regress-*`, and the grind rounds (`grind-r1a` … `grind-r3b`): realistic multi-plugin stacks round-tripped end-to-end, with narrative reports in [docs/grind/](docs/grind/).

## Working an issue (agents)

Engineering work is tracked in Linear ("Duo WP Branchability — Correctness Closure"). Agents dispatched with a `LINEAR-LOOP` prompt follow [docs/agents/linear-loop.md](docs/agents/linear-loop.md) — claim gate with Linear readback, branch-per-issue → PR → squash-merge, verified close gate ([scripts/close-gate-check.sh](scripts/close-gate-check.sh)). Fresh host setup is one command:

```sh
git clone https://github.com/duotronic-ai/duo-wp && cd duo-wp
bash scripts/agent-bootstrap.sh   # verifies host prereqs, pre-pulls sandbox images
```
