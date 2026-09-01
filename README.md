# WPrism — Branchable WordPress

Adapter capability claims live with their owners: each
`adapter-packages/<slug>/package/manifest.json` declares a surface and its
sibling `package/disposition.json` records the reviewed status and reason.
Package-local evidence names the exercised boundary. Render the exact aggregate
for the current checkout without creating a central adapter edit point:

```sh
php tools/capability-doc.php render
php tools/adapter-grade.php render
```

`make release-gate` validates the same complete source set and its cross-checks.
The stable explanation is [docs/capabilities.md](docs/capabilities.md); plugins
always run unmodified.

WPrism makes the reviewed, certified authored surfaces of a WordPress site **branchable like code** — branch, edit, merge, promote — without modifying plugin or theme source. Authored content and configuration live canonically in a git repository; runtime data (orders, comments, sessions, caches) stays environment-local and untouched. Anything WPrism cannot classify or certify is refused loudly, never guessed — a surface no manifest declares and no reviewer dispositioned is unsupported.

**Status:** the correctness core is complete (H1 exit, 2026-08-08 — no known path by which authored data silently fails to propagate), and the running horizon is adoption on real hosts: a real SSH adoption run, certified crash-rollback machinery, and automatic verified-rollback promotion are in; a first third-party production site is next. Direction and the honest boundary live in [docs/roadmap.md](docs/roadmap.md).

## How it works

WordPress state is classified along three axes — *who authors it*, *env-portability*, *source-vs-derived*. The branchable partition (`human-authored ∧ portable ∧ source`) is represented as entity-per-file canonical text (posts are canonical-JSON front matter over a raw Gutenberg body) keyed by UUIDs, with environment-bound values tokenized (`{{home}}`, `{{uploads}}`, `{{post:<uuid>}}`, `{{term:<uuid>}}`, `{{tt:<uuid>}}`, `user:<login>`), so git provides history, branching, and three-way merge. A per-environment ledger maps UUIDs to local auto-increment ids across typed keyspaces; apply is a terraform-style plan → two-phase hook-free write → derived-state rebuild pass, so applying never re-fires emails or webhooks and never leaves declared derived state stale. Classification comes from exact core rules, version-pinned plugin manifests, site policy, and a provenance journal that only ever *proposes* — everything unknown is **loudly blocked, never silently guessed**.

- **Normative contract:** [spec/repo-format.md](spec/repo-format.md) — entity file formats, identity, tokens, ledger, apply semantics.
- **Design rationale:** [DESIGN.md](DESIGN.md) — the first-principles state partition and the adversarial review that hardened it.

## Getting started

From a source checkout, the shortest honest evaluation is a disposable
WordPress-core pair. It requires PHP 8+, Docker with Compose, Git, and `jq`;
no extension installation is performed:

```sh
cli/wprism demo start
cli/wprism demo review --accept-page-only
# edit “WPrism Demo Page” at the printed source wp-admin URL
cli/wprism demo capture
git -C sandbox/siterepo/wprismdemo1 diff
cli/wprism demo apply
cli/wprism demo refusal
cli/wprism demo stop
```

The command verifies the exact digest-pinned WordPress 7.1 image, publishes only
after the managed core capability set qualifies and the bounded whole-site
release assessment is ready, then stops for explicit page-only contract review.
That review accepts and commits only the real contract/projection artifacts; a
red assessment blocks the journey. Before its lower-level evaluation apply,
`apply` runs the real read-only release authorization preview for the exact Git
revision and accepted contract. It then proves both that the target page equals
the captured artifact and that a target-only comment survived;
`refusal` proves a caller cannot replace the registry's trusted target binding.
Production execution still requires stage-source, release prepare, signed
authorization, and release execute.
Use `--scenario=woocommerce` for the advanced product/order adapter journey. For a
real site, `wprism connect` creates the local repository/registry only after
native reachability, WordPress and topology inspection probes, and `wprism
onboard` composes adopt → assess → init without a handwritten seed:

```sh
WPRISM_CLI="$PWD/cli/wprism"
"$WPRISM_CLI" connect production --workspace=../my-site \
  --transport=ssh --host=deploy@wp.example.com \
  --wp-path=/var/www/html --repo-path=/home/deploy/site-repo
cd ../my-site
"$WPRISM_CLI" onboard production --git-url=git@github.com:you/my-site.git
```

The Git remote must be empty and reachable with configured credentials from
both this controller and the WordPress target. WPrism verifies that before
adoption or initialization changes the site.

The [quickstart](docs/guides/quickstart.md) explains both paths. Continue with
[assess](docs/guides/assess.md) → [daily-workflow](docs/guides/daily-workflow.md)
→ [release](docs/guides/release.md) →
[capabilities-and-limits](docs/guides/capabilities-and-limits.md), and read
[recovery](docs/guides/recovery.md) before the first production release.

## The `wprism` CLI

Host-agnostic, dependency-free PHP orchestration ([cli/](cli/); full reference in [cli/README.md](cli/README.md)) over **local**, **Docker**, and **SSH** transports, driven from a committable environment registry.

- **Environment-bound:** `onboard`, `adopt`, `init`, `assess`, `contract`, `status`, `doctor`, `capture`, `plan`, `apply`, `deploy`, `preview`/`rehearse`, `release`, `verify`, `recover`, `promote`, `pending`, `classify`, `capabilities`, `explain`, `coverage`, `scope`, `env-set`, `refresh`, `rebase`, `adapter-observe`, `driver-capabilities`.
- **Repo-local (no environment):** `connect`, `demo`, `envs`, `env materialize|reap`, `manifest-validate`, `adapter-draft`, `adapter`.

`wprism capabilities` reports the same reviewed disposition, exact scope, and explicit unsupported surfaces that readiness and promotion consume, evaluated against the live target (`wprism-capability-report/v1`). `wprism promote` composes deploy-before-apply under promotion locks and, when the target proves every rollback capability, automatically selects the **verified-rollback profile** (issue #3310); otherwise it remains operator-directed with an explicit warning.

The composed customer loop sits on top of those: `wprism assess` is a read-only, decision-first projection of every WordPress surface into the product's own vocabulary, and `wprism contract` records the reviewed result as a per-site application contract. `wprism rehearse` materializes a disposable preview and states plainly that it is a preview and not a sandbox. `wprism release` freezes and prints an authorization plan — scope, conditions, what may change, the literal recovery claim, effects, remaining authority — before any mutation, then composes `wprism promote` unchanged and runs `wprism verify` (convergence plus contract-declared journey oracles) behind it. `wprism recover` is the operator verb over the recovery runtime, printing the same restores/does-not-restore claim the plan carried and enforcing writer exclusion and code-first ordering.

The core loop for unclassified writes: loud block → `wprism pending <env>` (journal-evidenced proposals, ref hints, secret flags) → `wprism classify <env>` triage (interactive or `--accept-proposals`; secrets can never be authored silently) → clean capture → `wp wprism policy-to-manifest` export. `wprism adapter-draft` turns captured state into inert `_draft` manifest candidates for human ratification.

## Agent skill

The portable [WPrism skill package](skills/wprism/) teaches a skill-capable
agent to operate the public CLI and its versioned machine contracts. Install or
load the complete directory through the agent harness's normal skill mechanism;
the package is usable with a matching installed `wprism` executable and does
not require this source checkout for its core workflows. It is guidance, not an
authority source: it supplies no credentials, signatures, or permission to
mutate a WordPress environment.

## Reviewed and tested, not asserted

Capability claims are derived, never duplicated by hand. A claim passes three gates in order: `adapter-packages/<slug>/package/manifest.json` **declares** the surface; its sibling `package/disposition.json`, kept outside the manifest document so no adapter can certify itself, records a human's **reviewed** status and reason; and the capsule's named conformance/live tests (or an explicit participant-declared [integration scenario](integration-scenarios/)) **exercise** it against a live WordPress pair. Core and shared compatibility live separately in [platform/adapter-library/](platform/adapter-library/). `tools/capability-doc.php render` projects the current source set to stdout, while `make release-gate` validates it without requiring an adapter edit outside its capsule. `wprism capabilities` answers the same question against a live target. Review can also *reduce* capability: working but unreviewable behavior is removed and refused, not shipped under-proven.

What this deliberately is not: a claim is not sealed to a content-addressed evidence bundle, and no digest binds it to a particular run. The honest reading of a `certified` row is *declared, reviewed by a named human, and exercised by the named live suites* — nothing stronger.

## Layout

| Path | What |
|---|---|
| [spec/repo-format.md](spec/repo-format.md) | The normative site-repo contract: entity formats, tokens, ledger, apply semantics |
| [DESIGN.md](DESIGN.md) | Founding design record: state partition, classification policy, identity model, GitOps semantics |
| [docs/](docs/README.md) | Map of the documentation tree: guides, runtime references, module map, engineering history |
| [docs/guides/](docs/guides/README.md) | Operator guides: quickstart, daily workflow, code updates, adapter authoring, coverage cohorts, limits |
| [docs/adoption.md](docs/adoption.md) | Installing/updating WPrism on an existing SSH WordPress host |
| [docs/roadmap.md](docs/roadmap.md) | Owner roadmap: thesis, horizons, standing decisions |
| [agent/](agent/) | The WPrism agent — drop-in mu-plugin + `wp wprism …` engine commands |
| [cli/](cli/) | The `wprism` orchestrator CLI + transports |
| [skills/wprism/](skills/wprism/) | Portable agent skill for the public CLI and machine-contract workflows |
| [adapter-packages/](adapter-packages/) | One capsule per plugin adapter: shipped package bytes plus package-local tests, fixtures, and evidence |
| [platform/adapter-library/](platform/adapter-library/) | Core classification, profiles, platform compatibility, and adapter authority roots |
| [integration-scenarios/](integration-scenarios/) | Explicitly participant-declared cross-adapter evidence |
| [recovery/](recovery/) | WordPress-independent rollback runtime: checkpoints, code releases, upload and effect bundles |
| [sandbox/](sandbox/) | Dockerized disposable environment pairs plus shared engine and integration test infrastructure |

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
make spike-d          # WooCommerce catalog round-trip + guarded product deletion
make spike-e          # ACF interpreter round-trip
make conformance-<m>  # per-manifest clean-room gate (core, woocommerce, acf, yoast, …)
make cli-smoke        # wprism CLI end-to-end over the docker transport
make cli-triage-smoke # interactive wprism classify triage end-to-end
make release-gate     # regenerate-and-compare: the capability document and the classmaps must match their sources
make down             # stop; `make clean` also deletes volumes
```

Beyond these, the Makefile carries the full live and offline regression surface — the seven `certify-*` targets (the merge, version-skew-merge, adversarial, deletion and version matrices, plus `certify-ssh-rollback` and `certify-ssh-adoption-roundtrip`), `regress-*`, and the grind rounds (`grind-r1a` … `grind-r3b`, plus `grind-mup`, `grind-adapter-walk`, `grind-adoption`): realistic multi-plugin stacks round-tripped end-to-end. Each grind script is its own spec; [docs/grind/](docs/grind/) carries the written specification for the harnesses that have one.

## Working an issue (agents)

Engineering work is tracked in Linear ("WPrism WP Branchability — Correctness Closure"). Agents dispatched with a `LINEAR-LOOP` prompt follow [docs/agents/linear-loop.md](docs/agents/linear-loop.md) — claim gate with Linear readback, branch-per-issue → PR → squash-merge, verified close gate ([scripts/close-gate-check.sh](scripts/close-gate-check.sh)). Fresh host setup is one command:

```sh
git clone https://github.com/duotronic-ai/wprism && cd wprism
bash scripts/agent-bootstrap.sh   # verifies host prereqs, pre-pulls sandbox images
```

## License

WPrism is free software, licensed under the [GNU General Public License, version 2
or later](LICENSE) — the WordPress ecosystem's own license. The `agent/` drop-in
runs inside WordPress, so GPL compatibility is not just a choice here but the
shipped half's natural obligation; the whole repository carries one license
rather than splitting hairs at the tarball boundary
(`cli/src/Onboarding/Adopt.php` assembles the selected package and platform
sources into `agent/adapter-library/`, then ships exactly `agent recovery`).
Contributions are accepted under the same terms — see
[CONTRIBUTING.md](CONTRIBUTING.md).
