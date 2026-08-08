# Duo — Branchable WordPress

Duo makes a WordPress site **branchable like code** — branch, edit, merge, promote — while every plugin and theme keeps working unmodified. Authored content and configuration live canonically in a git repository; runtime data (orders, comments, sessions, caches) stays environment-local and untouched.

**Status: v0.5.** The design is settled ([DESIGN.md](DESIGN.md), hardened by an [adversarial review](docs/design-review-v0.md)); the spikes prove the load-bearing claims:

- **Spike A — round-trip**: capture a site's authored state into canonical files, apply it into a second environment with typed id remapping, zero side effects, runtime rows untouched.
- **Spike B — merge**: divergent edits in two environments merge through plain `git merge` on canonical files; real conflicts surface like code conflicts.
- **Spike C — provenance**: the journal classifies live DB writes (admin-authored vs runtime) with WooCommerce as the stress test.
- **Spike D — WooCommerce catalog**: full product-meta manifest (field-granular: `_stock` is runtime, `_regular_price` is authored), plus the referential delete guard — deleting a product that this environment's orders reference blocks at plan time.
- **Spike E — ACF interpreter**: schema-driven classification — field-group definitions type the meta values (image/relationship ids, string-cast serialized arrays), with verbatim byte-preservation for serialized field-config bodies.
- **Conformance harness** ([sandbox/conformance/](sandbox/conformance/)): the manifest-treadmill answer — per-manifest clean-room round-trip gates (capture-twice determinism, hard lint gate, real `wp duo deploy` on the target, apply, re-capture = byte-identical, render checks), run in CI ([.github/workflows/conformance.yml](.github/workflows/conformance.yml)) for all nine manifests: core, woocommerce, acf, yoast, fse, elementor, polylang, contact-form-7, ninja-forms. (This list is hand-maintained and has drifted before — the CI matrix is authoritative until DUO-3227's capability registry generates it.)
- **`duo` CLI** ([cli/](cli/)): host-agnostic orchestration — `duo adopt|capture|plan|deploy|apply|promote|status|doctor|pending|classify <env>` over local/docker/ssh transports from a committable env registry. The [adoption path](docs/adoption.md) bootstraps the agent, manifests, and seed repo onto a pre-existing SSH host without Git or shared volumes. The opt-in code-half skeleton materializes a descriptor-verified `code/wp-content` payload, reconciles real plugin/theme lifecycle hooks, and prunes only paths Duo previously owned.
- **The core loop (Spike F)**: unclassified write → loud block → `wp duo pending` (journal-evidenced proposals, ref hints, secret flags) → `duo classify` triage (interactive or `--accept-proposals`; secrets can never be authored silently) → clean capture → `policy-to-manifest` export that reproduces identical state when pinned. The secret guard aborts capture on key-pattern hits in authored values.
- **Frontier maps** ([docs/frontier/](docs/frontier/)): empirically-grounded gap reports for FSE block themes, Polylang, and Elementor — including the engine work each needs before it's safe. Design proposals cover the [`code/` half](docs/proposals/code-half.md) and the future [verified production SSH rollback contract](docs/proposals/verified-ssh-rollback.md).

## Layout

| Path | What |
|---|---|
| [DESIGN.md](DESIGN.md) | The design: first-principles state partition, classification policy, identity model, GitOps semantics |
| [docs/design-review-v0.md](docs/design-review-v0.md) | 27-finding adversarial review (verified prior art: VersionPress, Mergebot, Pantheon) |
| [docs/adoption.md](docs/adoption.md) | Operator procedure for installing/updating Duo on an existing SSH WordPress host |
| [spec/repo-format.md](spec/repo-format.md) | The site-repo contract: entity file formats, tokens, ledger, apply semantics |
| [agent/](agent/) | The Duo agent — drop-in mu-plugin + `wp duo …` commands (capture, plan, apply, journal) |
| [manifests/](manifests/) | Classification manifests: core rules + per-plugin (WooCommerce skeleton) |
| [sandbox/](sandbox/) | Dockerized two-environment WordPress sandbox + spike acceptance tests |

## Running the spikes

Requires Docker.

```sh
make setup            # boot two WP envs (A: :8801, B: :8802) and init the site repo
make spike-a          # round-trip: capture A → apply B → re-capture B == capture A, runtime untouched, canary clean
make spike-b          # merge: divergent edits, git conflict, drift preserved, converged environments
make spike-c          # provenance: Woo env (:8803), admin vs anonymous writes vs manifest ground truth
make spike-d          # WooCommerce catalog round-trip + referential delete guard (needs spike-a/b first)
make spike-e          # ACF interpreter round-trip (own env pair :8804/:8805)
make spike-f          # the core loop: block → pending → classify → capture → manifest export (:8808/:8809)
make conformance-yoast  # per-manifest clean-room gate (:8806/:8807); also core/woocommerce/acf
make cli-smoke        # duo CLI end-to-end over the docker transport
make cli-triage-smoke # interactive duo classify triage end-to-end
make down             # stop; `make clean` also deletes volumes
```

## Working an issue (agents)

Engineering work is tracked in Linear ("Duo WP Branchability — Correctness Closure"). Agents dispatched with a `LINEAR-LOOP` prompt follow [docs/agents/linear-loop.md](docs/agents/linear-loop.md) — claim gate with Linear readback, branch-per-issue → PR → squash-merge, verified close gate ([scripts/close-gate-check.sh](scripts/close-gate-check.sh)). Fresh host setup is one command:

```sh
git clone https://github.com/duotronic-ai/duo-wp && cd duo-wp
bash scripts/agent-bootstrap.sh   # verifies host prereqs, pre-pulls sandbox images
```

## The elevator pitch, technically

WordPress state is classified along three axes — *who authors it*, *env-portability*, *source-vs-derived*. The branchable partition (`human-authored ∧ portable ∧ source`) is represented as entity-per-file canonical text keyed by UUIDs with environment-bound values tokenized, so git provides history, branching, and three-way merge. A per-environment ledger maps UUIDs to local auto-increment ids across typed keyspaces; apply is a terraform-style plan → two-phase hook-free write → rebuild pass. Everything unknown is **loudly blocked, never silently guessed** — classification comes from exact core rules, versioned plugin manifests, site policy, and a provenance journal that only ever *proposes*.
