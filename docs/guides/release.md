# Releasing a change, and proving it landed

This is the whole customer loop for one change, from a disposable preview to a
verified production release:

**rehearse → change on the preview → capture → merge → release --plan-only →
authorize → verify.**

It assumes the site has an accepted application contract. If it does not,
`duo release` refuses immediately with the gap action `declare in contract`,
and [assess.md](assess.md) is the fifteen minutes that fixes it.

`duo release` **composes** `duo promote` rather than replacing it.
Deploy-before-apply ordering, the promotion lease, the target fence, the
database checkpoint and the rollback-profile selection all remain promote's,
byte for byte. Release adds authorization in front and verification behind.
`duo promote` remains documented and supported as the lower-level verb; see
[daily-workflow.md](daily-workflow.md).

## Rehearse

```sh
duo rehearse preview --from production --branch feature/pricing-page --ttl 86400
```

`duo rehearse` is `duo env materialize` plus a preview: the same option
grammar, the same machine-local provider registry, the same capability
negotiation, the same journal, the same exact resource/lease/ownership
compare. A missing provider capability is a refusal naming that capability id;
nothing is ever emulated. `--branch` defaults to the branch this working tree
is on. `--create` is explicit and requires a provider that advertises both
create and receipt-backed destroy. `--ttl` publishes observable expiry
metadata and authorizes no deletion.

After convergence it prints what a release would touch: the plan's own
value-free category counts, and the assessed surface rows restricted to that
scope, each saying why it is in scope.

Clean up explicitly, always:

```sh
duo rehearse preview --reap
```

That is `duo env reap` with the same compare-and-reap. Created targets are
destroyed, attached targets are detached, a repeated reap is idempotent, and a
stale identity refuses.

### Rehearse is a preview, not a sandbox

Every run prints this first, before the provider is contacted:

```text
containment: unknown — not enforced in this profile; do not point this environment at live payment or mail credentials.
consequence: this rehearsal cannot authorize an Experimental or Uncertified capability — the spec permits that only after containment is proven. Rehearsal in this profile is a preview and evidence-gathering environment, not a qualification environment.
```

Read it literally. Duo does not strip or rebind production credentials, does
not default-deny outbound HTTP, mail, payment, webhook or queue traffic, and
does not verify containment before you exercise a workflow. A plugin in your
preview can and will send real mail and call a real payment API if you give it
real credentials.

The consequence is the half people skip: because containment is unproven, a
rehearsal here **cannot** qualify anything. "I rehearsed it" is not "I
qualified it", and a surface reading `Experimental` or `Uncertified` still
reads that way afterwards.

## Change, capture, merge

Author the change on the preview environment, in WordPress, the way it is
meant to be authored. Then:

```sh
duo capture preview
```

Capture is the only command that mints identity and publishes canonical state.
Review the result as an ordinary diff, merge it as an ordinary merge —
[daily-workflow.md](daily-workflow.md) covers `duo refresh` and `duo rebase`
when production moved underneath you. Duo adds no branching model; git stays
git.

## Read the plan before you authorize it

```sh
duo release production --from=main --plan-only
```

`--plan-only` prints the frozen-shape authorization plan and exits 0 having
mutated **nothing at all** — not the target, not the site repository. This is
the step to paste into a change ticket.

The page has six sections, in this order, and the order is an argument: scope
says what you asked for, capabilities say what it rests on, may-change says
what moves, recovery says what comes back, effects say what does not, and
authority says what is still missing.

```text
authorization plan for production — frozen 2026-08-17T09:14:02Z
  plan sha256:7b1c8e02a4d1…
  application contract: sha256:2f9047be51c3…
  releasing code revision e2f1a09

requested scope
  surface: products
  surface: pages
  entities: 2 to create, 7 to update, 0 to delete
  code: 0 plugin(s), 1 theme(s)
  lifecycle phases: retire → activate → finalize

capabilities and conditions
  woocommerce / promote: Ready with conditions (Platform-certified)
    condition: plugin_version_mismatch — woocommerce in 10.0.0–11.0.0 — 10.4.2 — mutation gate
  core / promote: Ready (Platform-certified)

what may change
  code:
    wp-content/themes/storefront-child
  authored state:
    products
    pages
  runtime-adjacent state:
    taxonomy counts (rebuild)
  external systems: none declared for this scope

recovery
  selected: verified-automatic
  because: target proved every rollback capability: …
  restores:
    database checkpoint
    code release
    upload bundle
    effect bundle
  does NOT restore:
    emails already sent
    payment captures or refunds already made
    webhooks already delivered
    third-party systems that observed the change
    orders and sessions written by live traffic after the checkpoint
  writer exclusion: held by Duo — the rollback authority reserves and releases the exclusion for the window itself; the operator asserts nothing
  maximum loss boundary: writes committed after checkpoint 2026-08-17T09:14:02Z

effects
  containment: prevented — no WordPress hooks fire in the apply window
  lifecycle window: live, declared in the contract (declarations.external_effects[0])
    reviewed reason: storefront-child activation runs no mail, payment or webhook code (reviewed 2026-08-17)
    recovery semantics: provider-state restorable, restored by code release
  known irreversible: none
  unknown and blocking: none

authority still required
  operator_confirmation: production-visible mutation
  business_owner: storefront pages visible to customers change
  declared_live_effect: lifecycle window declared live in the contract; plan-bound authority required

Authorize this release to production?
```

Four things are worth reading slowly.

**The `does NOT restore` list is non-empty for every profile,
`verified-automatic` included.** Every row on it names something that already
left this WordPress install. A recovery claim that said "everything is
restored" would be the failure the claim exists to prevent.

**The containment line is two different claims.** The state apply window is
`prevented`, with the literal basis `no WordPress hooks fire in the apply
window`. The code lifecycle window is not, and it is only allowed to proceed
because your contract *declared* it live with a reviewed reason.

**`authority still required` is a list of humans, not a checklist Duo ticks.**
It is what the plan says you still owe before the single question at the
bottom is honestly answerable.

**The plan digest is printed because a documented command consumes it.** That
is the standing rule for internal identifiers in a human view: `duo verify
--plan=<digest>` consumes this one, and the receipt ids `duo recover --list`
prints are consumed by `--restore`. Artifact hashes, lease owners and
operation ids stay in `--format=json`.

## `--from` is a binding assertion, not a git transport

```sh
duo release production --from=main
```

Release resolves that ref in your local site repository with `git rev-parse`,
reads the target repository's `HEAD`, and refuses on a mismatch with the next
action `reconcile` and the literal command to run. It fetches nothing, pushes
nothing and checks out nothing. Getting code onto the target is `duo deploy`
and the code-release provider's job, and release invents no second path to do
it — see [code-updates.md](code-updates.md).

## `--profile` and `--accept-weaker-recovery`

Duo selects the strongest recovery profile the target can actually prove:
`verified-automatic` when it proves the rollback signing key, the recovery
executor, and the checkpoint, code-release, upload and effect providers;
otherwise `operator-directed`. The plan states which one and *why*.

`--profile=<verified-automatic|operator-directed|none>` may only strengthen
silently. Asking for a profile the target cannot prove is refused outright —
the capability is absent, and pretending otherwise would put a restore
guarantee in a frozen plan no provider can honour. Asking for one *weaker*
than the target proves is a named human authority: it needs
`--accept-weaker-recovery` as well, prints a warning on stderr beside the
claim it weakens, and still ends in the plan's own typed confirmation.

```sh
duo release production --from=main --profile=operator-directed --accept-weaker-recovery
```

Whatever you select, the claim in the plan changes to match it. Under
`operator-directed`, `code releases`, `uploaded media bundles` and
`provider effect bundles` move into `does NOT restore` with their manual
remedy stated on the row. Under `none`, so does the database.

## Authorize

```sh
duo release production --from=main --yes
```

Answering `yes` to the question (or passing `--yes`, which confirms the plan
you were just shown) freezes the plan to
`.duo/releases/<plan_digest>.json` in your site repository **before any target
mutation**, then executes through promote. Immediately before the mutating
call the plan is re-verified against the target as it is at that instant; any
difference invalidates the authorization and refuses with `plan_changed`,
having written nothing. `--plan-only` and `--yes` contradict each other and
are refused together.

Commit the frozen plan. It is the document `duo recover` matches a checkpoint
against, which is what makes "the claim you saw at authorization is the claim
you see at recovery" checkable rather than aspirational.

## Verify

```sh
duo verify production --plan=sha256:7b1c…
```

Two independent parts, both required for a pass.

**Convergence** is a fresh read-only re-read of the target that must come back
clean. Be precise about what it is: this profile's convergence half is
`plan-reconciliation/v1`, a full read-only plan re-read, and the report says
so in its own disclosure line. The byte-level canonical recapture still runs —
inside the release's own apply, where it fails closed — and this command does
not re-run it. You are never told a verifier ran that did not run.

**Declared journeys** are HTTP probes of the URLs your contract's `journeys[]`
names, each with an expected status and an expected substring.

```text
verify production: pass
  convergence: pass (213 entities, 0 deletions)
  journeys: 2
    shop-index  pass  /shop/
    product-detail  pass  /product/ceramic-mug/
  verification is byte-level only for store settings; declare a journey in the contract to make this a business check
```

An undeclared journey is never silently skipped. With none declared at all the
report says `journeys: 0 declared`, and for every surface the release touched
with no journey covering it you get that byte-level-only line. Round-trip
equality is necessary and not sufficient, and this is where Duo says so out
loud.

`--plan=<digest>` binds the report to one frozen plan. If the contract moved
since that plan was authorized, verify refuses rather than substituting the
new journeys — verifying a release against journeys nobody authorized would be
a different document with the same name.

## When release refuses before it freezes anything

A refusal *before* the plan is frozen is an assessment problem, and it carries
one of the seven gap actions from [assess.md](assess.md#what-the-next-actions-mean)
— never a release next action. The two closed sets are not interchangeable,
and the reason is practical: nothing was written, so there is nothing to
resume, reconcile or recover.

| Refusal | What it means | Gap action |
|---|---|---|
| `contract_missing` | No accepted application contract for this site. | `declare in contract` |
| `release_surface_not_releasable` | A surface in scope is `Experimental`, `Not qualified`, `Unsupported` or `Requalification required`. Experimental cannot authorize production, including through a conditional path. | the surface's own action |
| `release_recovery_semantics_unknown` | A surface this release mutates has unknown effect recovery semantics. | `declare in contract` |
| `release_live_effect_undeclared` | The release enters the code lifecycle window, where hooks fire, and no reviewed live external effect is declared for it. | `declare in contract` |
| `release_deletes_not_authorized` | The plan deletes owned entities and `--with-deletes` was not given. | none — this is an authorization flag, not an assessment gap |
| `release_delete_unsupported` | A deletion lands on a surface whose deletion semantics are declared unsupported. | `exclude` |
| `release_ref_mismatch` | The target `HEAD` is not the ref `--from` asserted. | reconcile the code first |

The third and fourth rows are the same rule seen twice, and it is the hardest
line this profile holds: *unknown containment or recovery semantics never
reach live systems*. The declaration that unblocks it contains nothing and
changes nothing on the site — it converts an unknown into a known, bounded,
reviewed live effect, which is the only form that may proceed, and then only
under the frozen plan's own authority. The alternative is a silent unknown
reaching production.

## When release fails after the plan is frozen

Then, and only then, you get exactly one next action from a closed set of six.
Each is wired to a real receipt shape the promotion state machine already
produces.

| Next action | When | What it means |
|---|---|---|
| `resume` | a durable frozen promotion exists, or another generation holds the lease | Carry the existing operation forward from its own receipt. Do not start a new one. |
| `reconcile` | ambiguous commitment, uncertain receipt, drift after freeze, `--from` ref mismatch | The controller cannot prove what the target did, or the target moved. Establish the truth and merge it. **Never retry an ambiguous commitment** — replaying an unprovable commitment is how a release applies twice. |
| `retry` | transient transport failure, or `plan_changed` | The only two classes that earn a repeat, and a transport retry only after durable receipt reconciliation proves replay safe. Retry reuses the same operation identity. |
| `recover` | `incomplete_lifecycle` or `incomplete_apply` | The target is between two worlds and only the recovery profile can converge it. [recovery.md](recovery.md) is the whole procedure. |
| `requalify` | a gate-time condition no longer holds, or pinned evidence went stale | The fix is evidence, not repetition: re-certify or re-pin, then re-run `duo assess`. |
| `escalate` | checkpoint unavailable, authority required, nothing safe | The explicit terminal case. Duo refuses and names the human authority required rather than guessing. |

Each printed action comes with the one sentence explaining *why* it is that
action. Read that sentence before reaching for a flag.

## Where to go next

- [recovery.md](recovery.md) — `duo recover`, the literal claim, and the
  code-first ordering rule.
- [assess.md](assess.md) — where every pre-freeze gap action gets fixed.
- [capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy) —
  the bucket-by-bucket refusal table `duo status` reports against.
- [cli/README.md](../../cli/README.md) — flag-by-flag reference for every verb
  named here.
