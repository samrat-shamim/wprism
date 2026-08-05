# Duo — Branchable WordPress

Duo makes a WordPress site **branchable like code** — branch, edit, merge, promote — while every plugin and theme keeps working unmodified. Authored content and configuration live canonically in a git repository; runtime data (orders, comments, sessions, caches) stays environment-local and untouched.

**Status: v0 spike phase.** The design is settled ([DESIGN.md](DESIGN.md), hardened by an [adversarial review](docs/design-review-v0.md)); the spikes prove the three load-bearing claims:

- **Spike A — round-trip**: capture a site's authored state into canonical files, apply it into a second environment with typed id remapping, zero side effects, runtime rows untouched.
- **Spike B — merge**: divergent edits in two environments merge through plain `git merge` on canonical files; real conflicts surface like code conflicts.
- **Spike C — provenance**: the journal classifies live DB writes (admin-authored vs runtime) with WooCommerce as the stress test.

## Layout

| Path | What |
|---|---|
| [DESIGN.md](DESIGN.md) | The design: first-principles state partition, classification policy, identity model, GitOps semantics |
| [docs/design-review-v0.md](docs/design-review-v0.md) | 27-finding adversarial review (verified prior art: VersionPress, Mergebot, Pantheon) |
| [spec/repo-format.md](spec/repo-format.md) | The site-repo contract: entity file formats, tokens, ledger, apply semantics |
| [agent/](agent/) | The Duo agent — drop-in mu-plugin + `wp duo …` commands (capture, plan, apply, journal) |
| [manifests/](manifests/) | Classification manifests: core rules + per-plugin (WooCommerce skeleton) |
| [sandbox/](sandbox/) | Dockerized two-environment WordPress sandbox + spike acceptance tests |

## Running the spikes

Requires Docker.

```sh
make setup     # boot two WP envs (A: :8801, B: :8802), install, seed A with content, seed B with runtime noise
make spike-a   # round-trip acceptance: capture A → apply B → re-capture B == capture A, runtime untouched, canary clean
make spike-b   # merge acceptance: divergent edits, git merge, converged environments
make spike-c   # provenance acceptance: Woo env, admin vs anonymous writes, proposals vs manifest ground truth
make down      # stop; `make clean` also deletes volumes
```

## The elevator pitch, technically

WordPress state is classified along three axes — *who authors it*, *env-portability*, *source-vs-derived*. The branchable partition (`human-authored ∧ portable ∧ source`) is represented as entity-per-file canonical text keyed by UUIDs with environment-bound values tokenized, so git provides history, branching, and three-way merge. A per-environment ledger maps UUIDs to local auto-increment ids across typed keyspaces; apply is a terraform-style plan → two-phase hook-free write → rebuild pass. Everything unknown is **loudly blocked, never silently guessed** — classification comes from exact core rules, versioned plugin manifests, site policy, and a provenance journal that only ever *proposes*.
