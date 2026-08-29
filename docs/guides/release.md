# Releasing a change, and proving it landed

This is the whole customer loop for one change, from a disposable preview to a
verified production release:

**preview → change on the preview → capture → merge → release --plan-only →
authorize → verify.**

It assumes the site has an accepted application contract. If it does not,
`wprism release` refuses immediately with the gap action `declare in contract`,
and [assess.md](assess.md) is the fifteen minutes that fixes it.

The quickstart keeps an absolute `$WPRISM_CLI` for a source checkout. An installed
CLI needs no special path; initialize the same command variable once:

```sh
WPRISM_CLI="${WPRISM_CLI:-wprism}"
```

`wprism release … execute` **composes** `wprism promote` rather than replacing it.
Deploy-before-apply ordering, the promotion lease and the target fence are
promote's, byte for byte, and so are the rollback-profile selection and the
trailing state `apply`. The database checkpoint is not exclusive to promote any
more: `wprism deploy` takes and retains its own under its own lease. Release adds
authorization in front and verification behind.
`wprism promote` remains documented and supported as the lower-level verb; see
[daily-workflow.md](daily-workflow.md).

## Create a preview

Preview is not available from the one-entry registry `wprism connect` creates.
WPrism orchestrates hosting; it does not supply it. Configure both the source and
target transports and both `environment_provider` blocks in the machine-local
`.wprism-envs.json`. This complete schematic is loadable after replacing its
host/path values with real ones:

```json
{
  "envs": {
    "production": {
      "transport": "ssh",
      "host": "deploy@production.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/srv/wprism-production",
      "environment_provider": {
        "command": ["/opt/acme/wprism-env-provider", "/etc/wprism/production.json"],
        "timeout_seconds": 30
      }
    },
    "preview": {
      "transport": "ssh",
      "host": "deploy@preview-control.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/srv/wprism-preview",
      "environment_provider": {
        "command": ["/opt/acme/wprism-env-provider", "/etc/wprism/preview.json"],
        "timeout_seconds": 30
      }
    }
  }
}
```

The production provider must supply the snapshot-set capabilities; the
preview provider must supply inspect/create/destroy, snapshot restore,
repository materialization, mutation-fence, URL, receipt, and (for `--ttl`)
TTL capabilities. Check both configurations without mutation before creating
anything:

```sh
"$WPRISM_CLI" env provider-check production --role=source
"$WPRISM_CLI" env provider-check preview --role=target
```

The exact capability sets and refusal contract are in the
[branch-environment provider protocol](../branch-environment-provider.md).

```sh
BRANCH=$(git branch --show-current)
test -n "$BRANCH"
"$WPRISM_CLI" preview create preview --from production --branch "$BRANCH" --create --ttl 86400
```

`wprism preview create` is the first-contact spelling of `wprism rehearse`, which is
`wprism env materialize` plus a preview: the same option
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

### Reusing one physical preview slot

A provider may satisfy `environment.create` with one reusable preview slot
instead of allocating another VM or container for every branch. Reuse does not
weaken the ownership contract: each absent-to-present acquisition increments
the slot generation and rotates its lease and ownership receipt, while retrying
the active acquisition must not clear the populated slot. Exact terminal
destroy/detach evidence remains replayable after a later generation takes the
same physical resource; any new request carrying an older lease must refuse
before physical mutation. A held mutation fence is exclusive across the whole
active lease. Acquisition and reap intent must be durable before their first
physical mutation, and only the exact interrupted request may resume them.
All controllers for that physical slot must share one provider-owned state
authority; two independent state roots cannot coordinate ownership.

`tools/reference-env-provider.php` exercises two fixed-slot shapes. Ordinary
pair mode is tied to the shared sandbox pair and explicitly withholds
`environment.containment.verify`; it cannot drive `wprism rehearse`. Opt-in
`contained_preview` mode creates a standalone target with lease-owned database
and WordPress volumes, a dedicated database principal and credentials, an
internal-only app network, and a credential-free proxy as its sole loopback
ingress. It withholds attach/detach because an already-running target cannot
prove that the controls existed before boot. The exact provider object,
machine-local Docker transport, placeholder environment file and prerequisites
are in [the generated provider guide](../branch-environment-provider.md#enabling-the-contained-preview).

Both modes are development references, not general hosting providers. Their
local state lock serializes provider processes that share one state root and is
inherited by synchronous Docker/Git children, including when the PHP parent is
forcibly killed. That lock cannot cover a child that closes the descriptor or a
daemon-side job that continues after its CLI returns. A production slot service
must bind those jobs to the lease generation or cancel and await them before
making the slot reusable.

### Rehearse requires a sandbox receipt

Every run states the requirement first, before the provider is contacted:

```text
containment: required — production-derived bytes will not enter the rehearsal until its machine-local provider proves credential isolation and default-denied HTTP, mail, payment, webhook, and queue destinations.
```

Materialization then requires `environment.containment.verify` before snapshot
restore. The receipt binds the target identity, resource/lease ownership and
held mutation fence to live topology evidence. Missing capability, malformed
evidence or drift refuses. Only after that proof does the command print
`containment: sandboxed — provider verified agency-rehearsal-v1; receipt=…`.

The bundled contained preview proves server-side HTTP/payment/webhook denial
through the app containers' internal-only network; queue denial through the
same network, an isolated lease database, disabled WordPress cron/updaters and
no worker; and mail denial through the enforced refusal/capture shim. The
loopback proxy carries no runtime, repository or database credentials. Host or
Docker administrators, browser-side effects and secret sanitization inside
restored opaque application data are outside that boundary.

Containment permits evidence gathering; it does not manufacture certification.
"I rehearsed it" is not "I qualified it", and a surface reading `Experimental`
or `Uncertified` still needs its applicable reviewed disposition and evidence.

`wprism assess` now says the same thing in its next actions rather than
contradicting it: `qualify in rehearsal` is never printed, and the rows that
used to carry it name what actually closes them — `certify adapter` for
evidence that expired or was never obtained, `install adapter` or `classify`
for a surface nothing models. See
[assess.md](assess.md#what-the-next-actions-mean).

## Change, capture, merge

Author the change on the preview environment, in WordPress, the way it is
meant to be authored. Then:

```sh
"$WPRISM_CLI" capture preview
```

Capture is the only command that mints identity and publishes canonical state.
Review the result as an ordinary diff, merge it as an ordinary merge —
[daily-workflow.md](daily-workflow.md) covers `wprism refresh` and `wprism rebase`
when production moved underneath you. WPrism adds no branching model; git stays
git.

After the captured revision is committed and pushed or merged somewhere you
intend to keep it, clean up the disposable preview explicitly:

```sh
"$WPRISM_CLI" preview remove preview
```

That is `wprism env reap` with the same compare-and-reap. Created targets are
destroyed, attached targets are detached, a repeated reap is idempotent, and a
stale identity refuses.

## Read the plan before you authorize it

```sh
"$WPRISM_CLI" release production --from=main --plan-only
```

`--plan-only` prints the frozen-shape authorization plan and exits 0 having
mutated **nothing at all** — not the target, not the site repository. This is
useful as a local preview, but it is not an executable authorization subject.
The canonical `release prepare` document below is the artifact to persist,
present and sign.

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
    condition: plugin_version_mismatch — woocommerce in 10.0.0-11.0.0 — 11.4.2 — mutation gate
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
  writer exclusion: held by WPrism — the rollback authority reserves and releases the exclusion for the window itself; the operator asserts nothing
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

**`authority still required` is a list of humans, not a checklist WPrism ticks.**
It is what the plan says you still owe before the single question at the
bottom is honestly answerable.

**The plan digest is printed because a documented command consumes it.** That
is the standing rule for internal identifiers in a human view: `wprism verify
--plan=<digest>` consumes this one, and the receipt ids `wprism recover --list`
prints are consumed by `--restore`. Artifact hashes, lease owners and
operation ids stay in `--format=json`.

## Legacy `--from` is a read-only binding assertion

```sh
"$WPRISM_CLI" release production --from=main --plan-only
```

This retained preview resolves the ref and commit locally and compares it with
the target. It never performs delivery: when the target is behind it reports
`release_ref_mismatch`. Every legacy invocation without `--plan-only`, whether
interactive or `--yes`, refuses `release_external_authorization_required`
before planning or promote. Use `stage-source` below for inert delivery and
signed `release execute` for the only public release mutation boundary.

## Control-plane release: stage, prepare, sign, execute

These three public seams are the only mutating release path. The retired
interactive/`--yes` branch refuses before planning or promote. The separation
is strict: staging may add private Git-control bytes, preparation is
byte-read-only, and execution cannot move the canonical target until a signed
authorization has been durably consumed there.

Provision the site-owned operation-authority policy first at
`.wprism/authority/authorities.json`. It is a canonical
`wprism-operation-authorities/v1` document. Each trusted Ed25519 key names its
actor, the `release` operation, and every grant it may authorize. This trust
root is intentionally separate from contract-attestation, adapter-certificate
and rollback keys. Commit it with the source revision being staged;
preparation binds its exact digest.

Choose one immutable operation id, stage an advertised branch or tag, and save
the exact stdout bytes:

```sh
OPERATION_ID=change-1842-production
"$WPRISM_CLI" stage-source production --from=main \
  --operation="$OPERATION_ID" --format=json > stage-receipt.json
RECEIPT_SHA=$(jq -r .receipt_sha256 stage-receipt.json)
```

`stage-source` resolves the source commit and tree locally, fetches that exact
advertised commit through the target's `origin`, and requires it to be a
fast-forward of a clean named target base. It does **not** move target `HEAD`,
the index or the canonical worktree. It retains an inert ref and detached
worktree under the target's private Git directory, establishes the stable
target operation identity, and fsyncs one canonical
`wprism-source-stage-receipt/v1`. An exact retry with the same operation id and
inputs returns the same receipt bytes; reusing the id for another source
ref/commit refuses.

Prepare from the saved receipt and save this output too:

```sh
"$WPRISM_CLI" release production prepare \
  --stage-receipt=stage-receipt.json \
  --expected-stage-receipt-sha256="$RECEIPT_SHA" \
  --format=json > release-prepare.json
```

Preparation validates the target identity, base, retained receipt, stage ref,
staged commit/tree/worktree and the local source checkout before and after its
planning reads. Plan, compile, inventory and capability questions all name the
detached staged repository. Neither the canonical target nor the local site
repository is written: no target fast-forward, projection write or plan freeze
occurs.

The output is one canonical `wprism-release-prepare/v1`. It contains the
unchanged `wprism-authorization-plan/v1`, its semantic `plan_digest`, the hash
of the exact plan bytes presented to the actor, the complete
`subject_sha256`, required grants, authority-policy digest, capability-library
digest, stage receipt and every request bind. Preparation uses the current
trusted clock; it never backdates evidence evaluation to the stage receipt's
`created_at`. Therefore a later preparation can preserve `plan_digest` while
correctly producing a new presentation and subject. Persist and sign the
specific prepare document you reviewed rather than rerunning preparation after
approval.

The external authority signs this exact statement:

```json
{
  "actor": "release-manager@example.com",
  "expires_at": "2026-08-29T17:10:00Z",
  "issued_at": "2026-08-29T17:00:00Z",
  "key_id": "production-release-1",
  "nonce": "change-1842-production-0001",
  "operation": "release",
  "operation_id": "change-1842-production",
  "presentation_digest": "<release-prepare.presented_plan_sha256>",
  "subject_digest": "<release-prepare.subject_sha256>",
  "target_id": "<release-prepare.target_id>"
}
```

The signed bytes are
`"wprism-operation-authorization-signature/v1\0" || Canon::encode(statement)`.
The returned canonical envelope is
`wprism-operation-authorization/v1` with exactly `format`, `signature` and
`statement`. Its TTL must fit the site's authority policy. Have the controller
return its `sha256:` digest alongside `authorization.json`; equivalently, for
an already canonical saved envelope, prefix the SHA-256 of its exact file
bytes.

Execute only with every digest copied from the two saved documents:

```sh
AUTHORIZATION_SHA=sha256:…
SUBJECT_SHA=$(jq -r .subject_sha256 release-prepare.json)
PRESENTATION_SHA=$(jq -r .presented_plan_sha256 release-prepare.json)
PLAN_SHA=$(jq -r .plan_digest release-prepare.json)

"$WPRISM_CLI" release production execute \
  --prepare=release-prepare.json \
  --authorization=authorization.json \
  --expected-authorization-sha256="$AUTHORIZATION_SHA" \
  --expected-subject-sha256="$SUBJECT_SHA" \
  --expected-presentation-sha256="$PRESENTATION_SHA" \
  --expected-plan-digest="$PLAN_SHA" \
  --expected-stage-receipt-sha256="$RECEIPT_SHA" \
  --format=json > release-outcome.json
```

Execution emits exactly one JSON success/refusal document on stdout. Before
consumption it revalidates all explicit digests, target identity and `HEAD`,
the source stage, local checkout, current authority policy, semantic plan,
artifact, reviewed capability library and every plan condition. It then writes
the local projection/frozen plan, rechecks the stage once more, durably consumes
the one-time authorization under the target's private Git control directory,
materializes the exact staged commit, composes the existing promote path, runs
verification, and publishes the terminal outcome beside that consumption.
Promotion phase text is sent to stderr so it cannot corrupt the single stdout
document. Immediately before consumption it re-reads the authority policy and
verifies the signature, subject, grants and lifetime again; a policy change or
revocation after the earlier admission check leaves no consumption and no
target mutation. The target-side consumption election holds the identity lock
and re-reads `target-id`, closing the equivalent target-identity race.

### Crash, retry and status semantics

The exact same `release … execute` command is also the status/replay operation;
there is no separate command that could acquire a second mutation authority.
Its first target read looks up the authorization-envelope digest **before**
checking signature expiry or current stage state.

| Observed state | Exact retry result |
|---|---|
| no consumption exists | revalidate everything; consume and execute only if it still matches |
| consumption exists, no complete outcome | refuse `release_operation_reconciliation_required`; never retry mutation |
| consumption and completion exist | return the byte-identical stored outcome, even after authority expiry/revocation or later target/stage movement |
| different bytes under the same operation/authorization identity | refuse the named conflict; reconcile manually |

The staging lock is a kernel-released `flock`, so process death or power loss
cannot leave a permanent lock directory. A crash before receipt publication
leaves either a same-operation ref/worktree that the next exact request can
reconcile, or named ambiguous stage state that refuses. Receipt, target
identity, consumption and completion publication all use file fsync, atomic
rename, parent-directory fsync and exact readback before claiming durability.
Never delete retained control evidence to make a refusal disappear: inspect
the target's Git-private `wprism-release/` and `wprism-control/` records and
reconcile the exact operation lineage.

## `--profile` and `--accept-weaker-recovery`

WPrism selects the strongest recovery profile the target can actually prove:
`verified-automatic` when it proves the rollback signing key, the recovery
executor, and the checkpoint, code-release, upload and effect providers;
otherwise `operator-directed`. The plan states which one and *why*.

`--profile=<verified-automatic|operator-directed|none>` may only strengthen
silently. Asking for a profile the target cannot prove is refused outright —
the capability is absent, and pretending otherwise would put a restore
guarantee in a frozen plan no provider can honour. Asking for one *weaker*
than the target proves is a named human authority: it needs
`--accept-weaker-recovery` as well, prints a warning on stderr beside the
claim it weakens, and changes the exact subject an external actor reviews.

```sh
"$WPRISM_CLI" release production prepare \
  --stage-receipt=stage-receipt.json \
  --expected-stage-receipt-sha256="$RECEIPT_SHA" \
  --profile=operator-directed --accept-weaker-recovery --format=json
```

Whatever you select, the claim in the plan changes to match it. Under
`operator-directed`, `code releases`, `uploaded media bundles` and
`provider effect bundles` move into `does NOT restore` with their manual
remedy stated on the row. Under `none`, so does the database.

## Authorize

Authorization is the external signature step in the control-plane sequence
above. There is no interactive or `--yes` substitute: those legacy forms
refuse with the exact stage → prepare → sign → execute remediation. The signed
execute path freezes the already-presented authorization plan locally only
after signature verification and consumes the authority on the target before
canonical target mutation.

### The mutation gate

Immediately before signed execution consumes authority, the prepared plan is
re-verified against the target as it is at that instant. Four things are
re-observed:

| re-observed | refuses with | next action |
|---|---|---|
| the agent plan envelope, the target `HEAD`, the target artifact hash | `plan_changed` | `retry` |
| the reviewed capability library's content address | `release_evidence_not_current` | `requalify` |
| every machine-checkable condition the plan names, per adapter claim | `release_condition_changed` | `requalify` |
| … and whether each of them can be re-observed at all | `release_condition_uncheckable` | `requalify` |

Every one of these refuses having written **nothing**: they are raised before
the first production-visible call.

The condition rows are the ones printed under *capabilities and conditions*
above. `release_condition_changed` covers all three ways an authorization can
stop being true during the window — the observation moved (a plugin was
downgraded), a condition appeared (a plugin was deactivated), or one was
withdrawn (a plugin updated into its certified window, so the readiness word
you authorized is no longer the true one). `release_condition_uncheckable`
covers the case the product spec names separately: an adapter claim that is
absent from the report the target answers with now, or a condition carrying no
subject to re-probe. **An unmet or uncheckable condition blocks** — it is never
skipped.

Neither of these is a `retry`. Retrying after a plugin was deactivated walks
into the identical refusal; the fix is evidence, so both answer `requalify`:
re-run `wprism assess`, read the fresh plan, and confirm that one.

A release that succeeds records what it re-observed:

```text
released to production
  conditions rechecked at 2026-08-17T09:15:40Z: 1 across 4 adapter claim(s)
```

`--format=json` carries the same record as `conditions_rechecked`
`{at, checked, conditions, manifests}`, and a released outcome without one is
refused rather than written.

Commit the frozen plan. It is the document `wprism recover` matches a checkpoint
against, which is what makes "the claim you saw at authorization is the claim
you see at recovery" checkable rather than aspirational.

## Verify

```sh
"$WPRISM_CLI" verify production --plan=sha256:7b1c…
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
equality is necessary and not sufficient, and this is where WPrism says so out
loud.

`--plan=<digest>` binds the report to one frozen plan. If the contract moved
since that plan was authorized, verify refuses rather than substituting the
new journeys — verifying a release against journeys nobody authorized would be
a different document with the same name.

## When release refuses before it freezes anything

A refusal *before* the plan is frozen is an assessment problem, and it carries
one of the eight gap actions from [assess.md](assess.md#what-the-next-actions-mean)
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
| `release_ref_mismatch` | Read-only `--plan-only` found a target `HEAD` different from `--from`. | run the executing release or deliver first |
| `release_delivery_failed` | The target is dirty/detached/divergent, cannot fetch the ref, or fetched a different commit. | repair the named delivery phase and retry |

The third and fourth rows are the same rule seen twice, and it is the hardest
line this profile holds: *unknown containment or recovery semantics never
reach live systems*. The declaration that unblocks it contains nothing and
changes nothing on the site — it converts an unknown into a known, bounded,
reviewed live effect, which is the only form that may proceed, and then only
under the frozen plan's own authority. The alternative is a silent unknown
reaching production.

`release_deletes_not_authorized` is the one row with a counterpart outside
release, and the two postures differ deliberately. An ordinary `wprism promote
<env>` does not refuse on an unauthorized deletion: it warns that it performed
none of the planned deletions, applies the rest, and records the revision as
applied — the tombstone stays pending and `wprism status <env>` stays non-zero
until you rerun with `--with-deletes`. Release does not inherit that posture,
because its whole job in front of promotion is to freeze what is authorized
before a byte moves. Its own "is this target clean enough to authorize
anything?" check leaves the pending deletion to this row on purpose, so what
you get back is the flag remedy above and not a capture-first one — every other
unclean condition, a guard-blocked or conflicted deletion included, still
refuses as `release_target_not_clean` first.

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
| `requalify` | a gate-time condition no longer holds, or the reviewed dispositions moved since the contract was accepted | The fix is the reviewed input, not repetition: re-propose and re-accept the contract (or re-pin), then re-run `wprism assess`. |
| `escalate` | checkpoint unavailable, authority required, nothing safe | The explicit terminal case. WPrism refuses and names the human authority required rather than guessing. |

Each printed action comes with the one sentence explaining *why* it is that
action. Read that sentence before reaching for a flag.

## Where to go next

- [recovery.md](recovery.md) — `wprism recover`, the literal claim, and the
  code-first ordering rule.
- [assess.md](assess.md) — where every pre-freeze gap action gets fixed.
- [capabilities-and-limits.md](capabilities-and-limits.md#refusal-to-remedy) —
  the bucket-by-bucket refusal table `wprism status` reports against.
- [cli/README.md](../../cli/README.md) — flag-by-flag reference for every verb
  named here.
