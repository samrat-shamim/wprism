# Minimum-Usable-Platform end-to-end grind

Driver: [`sandbox/tests/grind/grind_mup.sh`](../../sandbox/tests/grind/grind_mup.sh).
Fixtures: [`sandbox/tests/fixtures/mup/`](../../sandbox/tests/fixtures/mup/).
This document is the specification the driver implements (originally round-3
MUP §6.1); `make grind-mup` runs it. The six product words the loop reports are
defined in [docs/assess-vocabulary.md](../assess-vocabulary.md).

This is the only test in the estate that runs the whole customer loop the round
exists to ship — `assess → contract → rehearse → capture/merge → release +
verify → recover` — once, in order, against one dedicated pair. Everything else
proves a mechanism; this proves the product.

**Step 11 is why it exists.** A runtime row written *after* the release's
checkpoint either survives recovery or does not, and whichever it does must be
exactly what the printed `maximum_loss_boundary` sentence said. That is thesis
test #3 (live-writer recovery) and thesis test #10 (recovery claims are
literal), executed as a gate rather than asserted in prose. The claim is
literal or this test fails.

---

## How the orchestrator runs it

```sh
make grind-mup MUP_PORT1=9400 MUP_PORT2=9401
```

`make grind-mup` is wired by the orchestrator; the driver itself is
`bash sandbox/tests/grind/grind_mup.sh` and takes every input from the environment,
so the target is one line:

```make
grind-mup:
	bash sandbox/tests/grind/grind_mup.sh
```

A candidate-bound run — the form that produces evidence — adds the source gate
that `sandbox/bin/pair.sh` enforces *before* it drops a database:

```sh
DUO_SOURCE_ROOT=$(pwd -P) DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
  make grind-mup MUP_PORT1=9400 MUP_PORT2=9401
```

### Inputs

| Variable | Default | What it is |
|---|---|---|
| `MUP_PAIR` | `mup` | `pair.sh` pair name. Grammar `[a-z][a-z0-9]*`. A custom name **requires** explicit ports, for the reason `conformance/run.sh` requires them: two sweeps on one port pair collide at bind time, loudly but confusingly late. |
| `MUP_PORT1` / `MUP_PORT2` | `9400` / `9401` | Published host ports for side 1 and side 2. |
| `MUP_KEEP` | unset | `1` leaves the pair and both site repos in place for inspection. |
| `MUP_WOO_VERSION` | `11.0.0` | The pinned WooCommerce artifact. Must exist in `adapter-packages/woocommerce/evidence/artifacts.lock.json`. |
| `MUP_THEME_SLUG` / `MUP_THEME_VERSION` | `storefront` / resolved from the lock | The pinned storefront theme. See *Deliberate deviations* — Storefront is not pinned in this tree yet, so this is the one knob a first run has to set. |
| `MUP_BOOTSTRAP` | `init` | `init` uses the product path (`duo init <env> --yes`); `manual` writes `site.duo.json` by hand and captures, the way `conformance/run.sh` does. |
| `MUP_STEP11` | `required` | See *The step-11 transport gate*. |
| `MUP_JOURNEY_SHOP_URL` | `/shop/` | The path the shop journey probes. |
| `DUO_EXPECTED_SOURCE_SHA` | unset | Forwarded to `pair.sh`'s candidate-source gate. Unset means this run is **not** bound to a commit, and the driver says so. |
| `DUO_WORDPRESS_ORG_OFFLINE` | `0` | Forwarded to `pair.sh` and `fetch-artifact.sh`. |

### Offline modes (no docker, no pair, no network)

```sh
bash sandbox/tests/grind/grind_mup.sh --self-check   # every pure helper vs recorded documents
bash sandbox/tests/grind/grind_mup.sh --dry-run      # --self-check, then the resolved plan
```

`--self-check` runs each jq/bash helper in the driver against the PASS document
in `sandbox/tests/fixtures/mup/` **and** against a hand-mutated FAIL document,
because a helper that cannot fail proves nothing about the run that trusts it.
It also writes the exact `.duo-envs.json` the run writes and pushes it through
`\Duo\Orchestrator\CommandEnvironmentProvider::fromEnvironment()` — the real
`EnvironmentLifecycle` provider-config schema — so a malformed provider block
refuses on a laptop instead of at materialization time with a snapshot already
taken.

`--dry-run` walks all thirteen steps and prints the argv of every external
command, shell-quoted, with its arguments already resolved. It is a plan, not a
replay: there is one copy of each step body, and every external call goes
through `run`/`run_in`, which print instead of executing. A dry run creates
nothing and its cleanup trap removes nothing.

### Where the evidence lands

Everything the run reads is kept under a `mktemp -d` inside `sandbox/tmp/`:

```
<scratch>/.duo-envs.json                 the machine-local registry
<scratch>/reference-env-provider.json    duo-reference-env-provider-config/v1
<scratch>/evidence/assess-mup1.json      duo-assess-report/v1
<scratch>/evidence/assess-mup1.txt       the human view the leak gate reads
<scratch>/evidence/rehearse.txt          banner + "what a release would touch"
<scratch>/evidence/authorization-plan.json   duo-authorization-plan/v1
<scratch>/evidence/release.txt           promote's own `promote phase:` receipts
<scratch>/evidence/verify.json           duo-verify-report/v1
<scratch>/evidence/checkpoint-catalog.json   duo-checkpoint-catalog/v1
<scratch>/evidence/recover.txt           the claim, printed before acting
<scratch>/evidence/assess-mup2-{pre,post}-recovery.json
<scratch>/evidence/reap-{1,2}.txt
```

The scratch directory is removed by the exit trap unless `MUP_KEEP=1`. The path
is printed on the last line of a successful run.

### What cleanup guarantees

The exit trap destroys **exactly this pair** and removes exactly
`sandbox/siterepo/<pair>{1,2}` and `sandbox/siterepo/origin-<pair>.git`, then
*verifies* the removal: any surviving container, volume, network or
`wp_<pair>{1,2}` schema turns a passing run into a failing one. It never
touches another agent's pair. Under `--dry-run` it exits immediately, having
created nothing to remove.

---

## The thirteen steps, and what each one proves

| # | Command | Assertion, and why it is the assertion |
|---|---|---|
| 1 | `pair.sh reset` + `up <pair> <p1> <p2> --http --artifacts`; pinned WooCommerce + theme on both sides; three products and a page on side 1 | The pair is healthy and both sides carry the **exact** pinned artifacts. Side 1 is authored (activated, set up); side 2 gets extension **files only**, so the release's own deploy phase is what reconciles activation — the same split `conformance/run.sh` makes, and the reason step 9's ordering assertion is not free. |
| 2 | `duo adopt mup1` (refusal asserted), `duo init mup1 --yes`, `duo status mup1` | The baseline is clean. `adopt` is asserted to refuse *by name* on the pair's docker transport (`AdoptCommand` accepts only an `AdoptionTransport`, and a pair side already carries the agent): a verb skipped for taste is invisible, a verb whose refusal is asserted is documented. |
| 3 | `duo assess mup1 --format=json`, then `duo assess mup1` | The document is a `duo-assess-report/v1`; `post_type:product` projects `authored / manage / Ready / Platform-certified / prevented`; `post_type:shop_order` projects `runtime / preserve local / Unsupported`; **every** unclassified row carries a gap action that is not `nothing — supported`; and the **human** view contains no UUID and no 32-or-more-hex identifier. That last one is MUP §5.2 mechanised: a human view may print an internal identifier only when a documented command consumes it, and nothing consumes an operation id, session id, lease owner or artifact hash from `assess`. |
| 4 | `duo contract mup1 propose` → jq review → `accept` → `show` twice | `contract.json` + `projection.json` exist; `contract_digest` is identical across two `show` runs; `attestation.state == "unsigned"`. The review step is the real one: the generated `code-lifecycle-window` entry arrives `decided_by: unresolved`, and `ApplicationContract::validate()` refuses to accept it that way (`external_effect_unreviewed`). The grind performs §3.2's reviewed edit — `containment: live`, `effect_recovery_semantics: provider-state restorable`, `restored_by: code release`, a reviewed reason, `decided_by: operator` — and declares the two journeys step 10 verifies. Without the declaration step 9 must refuse; that is §1.6's consequence, and it is load-bearing here rather than decorative. |
| 5 | `duo rehearse mup2 --from mup1 --branch main` | The containment banner is the **first** line and appears **once**, byte for byte; the report states that a rehearsal cannot authorize an Experimental or Uncertified capability; "what a release would touch" is present; side 2 carries a materialized site repository. The preview is driven by `tools/reference-env-provider.php` through the machine-local `environment_provider` block — the same `CommandEnvironmentProvider` negotiation a customer's own provider gets. |
| 6 | Edit one product price and one page body on the preview; `duo capture preview` twice | Capture is deterministic: two captures of the same converged environment differ by zero bytes. |
| 7 | `git commit` in the preview's clone; `git push origin HEAD:main` | Ordinary git. The origin's `main` now carries the authored edit. |
| 7b | Revert the two **live** values on side 2 | Not in §6.1's table, and deliberate — see *Deliberate deviations* #3. Without it the release has nothing to apply and steps 9–12 prove nothing. |
| 8 | `duo release mup2 --from=<main sha> --plan-only --format=json` | The plan validates as `duo-authorization-plan/v1`; it cites the **accepted** `contract_digest`; its embedded recovery claim's `does_not_restore` is non-empty; the recovery profile is named **with the reason it was selected**; `effects.unknown_blocking` is empty; the plan authorizes at least one entity change; and `--plan-only` wrote nothing to `.duo/releases/`. A profile named without a reason is an assertion, not evidence, which is why the reason is asserted separately. |
| 9 | `duo release mup2 --from=<main sha> --yes` | Exit 0; the run printed `authorization frozen: <path>` (the plan was durably bound before any mutation); and promote's own `promote phase:` receipts appear in the order `promotion-begin → checkpoint → lifecycle-retire → lifecycle-activate → apply`. When the artifact declares code, `code-stage` and `code-finalize` are additionally asserted to bracket the lifecycle *before* apply. Deploy-before-apply is read from the receipts, not from a comment. |
| 10 | `duo verify mup2 --format=json` | `verdict: pass`, `convergence.status: pass`, both declared journeys `pass`, and `uncovered_surfaces` **present** as a list (an empty list is a report; a missing key is a silence). The grind then reads the target directly to confirm the release actually wrote the authored price and page body, and records the pre-recovery projection. |
| 11 | Write a `post_type:shop_order` row on mup2 **after** the checkpoint; `duo recover mup2 --list` → `--restore=<id> --writers-excluded` | **The gate.** (a) The claim is printed *before* the first driven step — asserted by line number, because a claim printed after recovery started was read too late to stop. (b) The `maximum_loss_boundary` printed at recovery is byte-identical to the one the frozen plan printed. (c) The product price and page body are back at their pre-release values. (d) The post-checkpoint runtime row's fate matches the boundary **exactly**: a boundary of `writes committed after checkpoint <ts>` means the row must be **gone**, and a surviving row fails the test with that sentence quoted back. The runtime surface is chosen by the pinned manifest (`shop_order` is `class: runtime` in `manifests/woocommerce.json`), not by this script. |
| 12 | `duo assess mup2 --format=json` | For exactly the surfaces the frozen plan named in scope, the post-recovery projection is byte-identical to the pre-release one. Only the projected words are compared — `state_class`, `handling`, and the release projection's readiness / provenance / containment / recovery semantics — because a digest or timestamp differing between two assessments of an unchanged site is the clock moving, not the site moving. |
| 13 | `duo rehearse mup2 --reap`, twice | The first receipt says `destroyed` or `detached`; the second says the same and exits 0. Repeated reap is idempotent, which is the property that makes a reap safe to retry. |

---

## How to read a run

A passing run ends with:

```
✔ GRIND_MUP PASSED (13/13, step 11 executed)
evidence: /…/sandbox/tmp/grind-mup.XXXXXX/evidence
```

Anything else is a failure, with two shapes worth knowing:

- **`FAIL: step N: …`** — one named assertion, on stderr, with the file to read.
  The driver stops at the first one; the exit trap still destroys the pair and
  still verifies the destruction, so a failed run leaves no resources behind
  (use `MUP_KEEP=1` when you want the corpse).
- **`✔ GRIND_MUP steps 1-10, 12-13 PASSED — STEP 11 (THESIS GATE) NOT EXECUTED`**
  — the run was invoked with `MUP_STEP11=record-gap`. It is *not* a pass of the
  grind. See below.

`--self-check` fails with `GRIND_MUP SELF-CHECK FAILED (n)` and one `FAIL:` line
per helper. That failure means the driver's own readers drifted from the shipped
document formats; regenerate the fixtures with

```sh
php sandbox/tests/fixtures/mup/make-fixtures.php
```

and read the diff — every PASS fixture is produced by the shipped builder
(`AssessReport::build()`, `AuthorizationPlan::build()`, `RecoveryClaim::build()`,
`JourneyOracle::report()`, `CheckpointCatalog::fromStatus()`) and validated by
the shipped validator before it is written, so a diff there is a format change,
not fixture rot.

---

## The step-11 transport gate

`duo recover` is a front end over the adopted rollback-authority runtime, and
`RecoverCommand::authorityTransport()` accepts an **SSH** transport and nothing
else — every other transport receives the typed refusal
`recovery_authority_unavailable`. `sandbox/bin/pair.sh` publishes no sshd, so on
this pair `duo recover mup2 --list` refuses **by construction**.

That is a real gap between MUP §6.1's grind (a docker pair) and MUP §2.5's verb
(SSH only), and it is not worked around:

- `MUP_STEP11=required` (default) — step 11 runs exactly as §6.1 writes it. If
  `--list` refuses, the run **fails**, quoting the refusal and naming the cause.
- `MUP_STEP11=record-gap` — steps 1–10 and 12–13 still produce evidence; step 11
  asserts the refusal is the typed, named one and that the frozen plan's claim
  is still literal, then prints `THESIS GATE NOT EXECUTED` on stdout, on stderr,
  and in the final summary line. It exits 0 so the other twelve steps' evidence
  is collectable, and it says three times that the gate did not run.

Closing the gap needs one of: an SSH-reachable side in the pair model, or a
rollback-authority path `RecoverCommand` accepts on a non-SSH transport. Both
are outside this file.

---

## Deliberate deviations from §6.1

1. **Storefront is not pinned in this tree.** §6.1 names Storefront;
   `platform/artifact-library/artifacts.lock.json` pins only `twentytwentyone 2.8` and
   `twentytwentyfive 1.5`, and `sandbox/bin/fetch-artifact.sh` refuses an
   unpinned artifact rather than falling through to the wordpress.org catalog.
   The driver therefore refuses **at preflight, before any pair is created**,
   naming both remedies: add the lock entry, or run with
   `MUP_THEME_SLUG=twentytwentyone MUP_THEME_VERSION=2.8`. A silent substitution
   would make the run claim a Storefront it never installed.

2. **`duo adopt` is asserted as a refusal, not performed.** A `pair.sh` side is
   a docker transport and already carries the agent, so there is no control
   plane to transfer. Step 2 asserts the refusal names the transport, then uses
   `duo init` — which is the half of "adopt/init" a pair side actually has.

3. **Step 7b reverts two live values before the release.** §6.1 makes side 2
   both the rehearsal preview *and* the release target, so an edit authored on
   the preview at step 6 is already live on the target the moment it is
   captured — and a release with nothing to apply cannot prove that step 11
   restored anything. Step 7b puts exactly the two values the release will write
   back to their pre-release state, so the repository carries the change and the
   site does not yet, which is what a release target actually looks like.
   Nothing else on the target is touched and the revert is asserted afterwards
   so it cannot silently no-op. Step 8 additionally refuses to continue if the
   frozen plan authorizes zero entity changes, so this cannot rot into a
   vacuous run.

4. **The journeys are declared against surface ids, not labels.**
   `affected_surfaces` carries `post_type:product` / `post_type:page` so that
   `uncovered_surfaces` is computed against the same identifiers the projection
   and the plan's `scope.surfaces` use.

---

## Risks a machine without docker cannot retire

Everything below is reachable only on a live pair. Each is stated so the first
live run knows what to look at rather than rediscovering it.

1. **Readiness may not be `Ready`.** Step 3 asserts the §6.1 words literally.
   The WordPress/PHP/MariaDB route into a non-`Ready` row is gone with the
   generated capability registry — the global pre-policy gate owns those
   versions instead of projecting them as per-surface blockers. What survives
   in the report is the *plugin* window: `plugin_version_mismatch` and
   `plugin_not_active` are condition codes, not blockers
   (`cli/src/Assess/SurfaceCatalog.php:137-139`), so a WooCommerce outside
   `manifests/woocommerce.json`'s `version_range` (`11.0.0` ≤ v < `12.0.0`)
   projects `Ready with conditions` and step 3 fails for an adapter-window
   reason rather than a product one. Check `MUP_WOO_VERSION` against that range,
   and that `manifests/dispositions/woocommerce.json` still reads
   `certified`, first.
2. **`duo init` is preflight-gated.** `MUP_BOOTSTRAP=init` runs the product path;
   its proposal refuses when the target has no Git, and an installed but
   uncertified adapter blocks it too (`cli/duo:509-510`). `MUP_BOOTSTRAP=manual`
   is the escape hatch and reproduces `conformance/run.sh`'s hand-written
   `site.duo.json` + capture.
3. **The provider's `attach` re-runs `pair.sh up` without `--artifacts`.**
   `ref_pair_up_command()` builds `pair.sh up <pair> <p1> <p2>` and no flags, so
   a re-converge during step 5 may bring the pair up without the artifact-cache
   mount. The driver installs every pinned artifact in step 1, before the first
   provider call, so a later re-converge should not need it — but whether
   `pair.sh up` on a live pair reconfigures its compose file set is unverified
   here.
4. **`/shop/` depends on permalinks and on the theme's archive template.** The
   shop journey expects HTTP 200 containing a product name. If the pair's
   permalink structure or the substituted theme does not render product titles
   on the shop archive, step 10 fails on the journey rather than on convergence.
   `MUP_JOURNEY_SHOP_URL` exists for exactly that.
5. **HPOS.** The grind does not enable High-Performance Order Storage. The
   `post_type:shop_order` row step 11 writes is a `wp_posts` row that the pinned
   manifest classes `runtime` either way, so the assertion holds — but if a
   future WooCommerce turns HPOS on by default and coverage then reports the
   `wc_orders` tables as undeclared, extra `unclassified` rows appear in step 3.
   They are required to carry a gap action, which is asserted, so that should
   read as a pass; it is unverified.
6. **File ownership across the bind mount.** Capture publishes as uid 33 inside
   the container while the host runs `git add`. The cleanup trap chmods both
   site repos before removing them (the idiom the since-removed
   `grind_code_half.sh` established), but whether host-side `git commit`
   succeeds after a container-side capture is platform-dependent and unverified
   here.
7. **The `preview` and `mup2` registry entries name the same physical side.**
   *(retired by construction: since the reusable preview slot,
   `tools/reference-env-provider.php` requires every configured environment to
   use pair.sh's canonical logical name, so side 2 is `mup2` for both the
   rehearsal and the release — one registry entry, nothing to keep aligned.)*
8. **Wall time and pair budget.** Unmeasured. It is one pair, one reset, one
   `up`, two artifact installs per side, one materialization, one promotion, one
   recovery and two reaps.
