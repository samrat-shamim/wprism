# Assessing a site, and writing down what you decided

Before Duo manages a site, somebody has to answer two questions: *what is on
this site*, and *which parts of it can Duo honestly be trusted with*. This
guide is that conversation. It has three movements — connect read-only, read
the assessment, then record the decision as a reviewed contract — and it ends
with the parts of the answer Duo deliberately refuses to give you.

Nothing here mutates a target. `duo assess` is read-only by construction, and
the only file it writes is a proposal in your own repository.

## Connect read-only first

```sh
duo doctor production
duo driver-capabilities production --operation=promote
```

`duo doctor` runs its gated checks in order, each one after a failure
reported as failed rather than run: transport reachable, WordPress installed,
Duo agent present, the configured `repo_path` holding a `site.duo.json`, and
the environment/compatibility checks behind them. `duo assess` runs the
same battery as its first step and turns a blocking failure into a refusal
rather than an assessment full of blank rows — an assessment that could not
reach the site is not a bounded assessment, it is no assessment at all.

`duo driver-capabilities` answers "would this workflow be attempted here" and
contacts nothing at all. Neither command writes.

If the agent is not there yet, that is an adoption question, not an assessment
question: [quickstart.md](quickstart.md) has both paths.

## Assess

```sh
duo assess production
duo assess production --operation=release,verify --limit=100
duo assess production --format=json > production-assessment.json
```

One run composes six read-only steps in a fixed order — doctor, the adoption
probe, the initialization probe, `wp duo assess-inventory` on the target,
`wp duo capabilities` once per distinct registry operation, and the host's own
adapter catalog. `--operation` narrows the projected product operations
(`capture`, `merge`, `release`, `verify`, `delete`, `recover`; all six by
default). `--limit=<1..200>` bounds every listing, exactly as it does for
`duo status`, and a cut section ends in `N more (use --format=json)`.

The human view is a projection of the same document `--format=json` emits.
Read the JSON when you want every operation at once; read the table when you
want a decision.

```text
stack: WordPress 7.0.3 · PHP 8.3.33 · MariaDB 11.8.8 · single-site
authority: ssh · read-only for this command · repo /srv/site
installed: 24 plugin(s) (19 active), 3 theme(s) (1 active), 812 attachment(s)
checks: 9/9 passed
showing the release projection; every operation is in --format=json

surface          class              handling        readiness              certification       containment  recovery
Products         authored           manage          Ready                  Platform-certified  prevented    provider-state restorable
  meaning: authored product content travels between environments
Payment keys     environment-bound  rebind          Ready with conditions  Platform-certified  unknown      not applicable
  condition: env_missing woocommerce_stripe_secret
  next action: provision env value (release)
Orders           runtime            preserve local  Unsupported            Platform-certified  prevented    not applicable
  meaning: live operational state is never copied
Custom catalog   unclassified       block           Not qualified          Uncertified         unknown      unknown
  reason: an unclassified surface has no registry entry that could make it Ready
  next action: classify (capture, merge, release, verify, delete, recover)

unknown: 41 option name(s) invisible to every installed adapter
         3 pending classification(s) (duo pending production)

next actions:
     45  classify
      0  declare in contract
      0  qualify in rehearsal
      0  exclude
      1  provision env value
      0  install adapter
      2  nothing — supported
evidence: 4 certification subject(s) pinned
proposed contract written: .duo/contract/proposed.json (accept with duo contract production accept)
```

**Exit 0 is not a green light, and it is not meant to be one.** A bounded
assessment exits 0 *including* one where every surface is blocked, because
assessment is not a completeness claim. Exit 1 means the assessment itself
refused — an unreachable target, an unsupported topology. If you want a
readiness gate, that is `duo status`, whose exit code answers a different
question.

## The six columns

Every row carries the same six dimensions, and each one is drawn from a closed
vocabulary. The full table of source facts to projected words is in
[capabilities-and-limits.md](capabilities-and-limits.md#the-six-projected-dimensions);
what follows is how to read a row.

**class** — where the state comes from: `authored`, `runtime`, `derived`,
`environment-bound`, `external`, `unclassified`. It is the five classification
classes you already know, projected into product language, plus `external` for
a surface a manifest *declares* reaches another system. Duo never infers
`external` from observation; an unmodelled integration lands in
`unclassified`, which blocks.

**handling** — what Duo will do with it: `manage`, `preserve local`,
`rebuild`, `rebind`, `re-synchronize`, `block`. `re-synchronize` needs a
declared action and this profile ships no generic one, so in practice an
`external` surface reads `block` until a manifest declares otherwise. The row
says so rather than hiding it.

**readiness** — what the evidence supports right now: `Ready`, `Ready with
conditions`, `Requalification required`, `Experimental`, `Not qualified`,
`Unsupported`. It is recomputed from the generated capability registry and a
live probe on every run. A condition under a `Ready with conditions` row is
re-checked at the mutation gate, so it is a live promise, not a footnote.

**certification** — where the claim comes from: `Platform-certified` or
`Uncertified`. There is no third value in this profile; see *What this profile
does not claim* below.

**containment** — whether an effect can escape: `prevented`, `live`, or
`unknown`. `prevented` is emitted only where the mutation happens entirely
inside apply's hook-free window, and it carries the literal basis string
`no WordPress hooks fire in the apply window`. Everything else — the code
lifecycle window, any declared provider action, any regenerator — reads
`unknown — not enforced in this profile`.

**recovery** — what comes back if this goes wrong: `not applicable`,
`provider-state restorable`, `irreversible`, or `unknown`. A surface whose
containment is `unknown` and whose effect may exist reads `unknown`, and that
combination *blocks* any operation able to reach a live system. That is the
one place this profile's honesty costs you real work, and it is deliberate:
see [release.md](release.md#when-release-refuses-before-it-freezes-anything).

Under a row you may also see `condition:` lines (bounded to two, the rest in
JSON), a `reason:` line quoting the registry's own words, a one-line
`meaning:`, and a `next action:`.

## What the next actions mean

The assessment prints a count per action. There are exactly seven, the set is
closed — a gap Duo cannot express as one of these is a bug, not a judgement
call — and every one of the seven is printed with its count *including the
zeroes*. A section that showed a line only when it had rows would teach you to
read the presence of the line as the signal. The count is the signal.

| Next action | What you actually do |
|---|---|
| `classify` | The surface has no disposition. `duo pending <env>` lists it, `duo classify <env>` decides it. Cheapest remedy on the list, which is why it sorts first. |
| `declare in contract` | Duo can see the effect but cannot bound it. Add the declaration to `.duo/contract/proposed.json` and accept it — see below, and the containment rule in [release.md](release.md#when-release-refuses-before-it-freezes-anything). |
| `qualify in rehearsal` | Nothing on this site proves the surface behaves. Build a preview with `duo rehearse <env> --from <production-env>` and gather evidence there. Read the limits in [release.md](release.md#rehearse-is-a-preview-not-a-sandbox) first. |
| `install adapter` | No installed adapter has a manifest for it. Write or install one; [adapter-authoring.md](adapter-authoring.md) is the whole path. |
| `provision env value` | A manifest-declared `class: "env"` option is unset here: `duo env-set <env> --name=<name> --stdin`. |
| `exclude` | The boundary is stated, not broken. Record the decision in the contract's `unsupported[]` and stop trying to release it. |
| `nothing — supported` | Every projected operation agrees. This is last in the ordering so it wins only when nothing else applies. |

A row can read `Ready` in the columns and still carry a next action, because
the columns show one operation and the action reduces over all six. That is
why the action names its operations: `install adapter (delete)` is a complete
sentence.

The unknown section gets actions of its own. Pending items and option names
invisible to every installed adapter are both `classify`; an undeclared table
is `qualify in rehearsal`. Those are counted and *named*, never valued —
`duo assess` prints names and counts only, exactly as `duo coverage` does.

## Record the decision: the application contract

An assessment is a reading. A contract is a decision, and it is the thing the
rest of the loop consumes: `duo release` cites its digest in the frozen
authorization plan, `duo verify` reads its declared journeys, and `duo recover`
reads its declared external effects for the does-not-restore list.

```sh
duo contract production propose
$EDITOR .duo/contract/proposed.json
duo contract production accept
duo contract production show
```

`propose` re-runs the assessment and writes `.duo/contract/proposed.json`. A
proposal is never authority — a declaration cannot certify itself — and the
generated document deliberately carries an external-effects entry marked
`decided_by: "unresolved"` that validation *refuses*. The only way to accept a
proposal is to have edited it: the human review step is enforced by the
schema, not requested by a guide.

What you edit, in practice: each surface's `handling`, the `journeys[]` your
release should be verified against, the `unsupported[]` boundaries you accept,
and the `external_effects[]` entry for the code lifecycle window — replacing
`decided_by: "unresolved"` with `"operator"` and writing the reviewed reason
in your own words.

`accept` re-runs the assessment, refuses a stale proposal rather than
reconciling it (the site moved between propose and accept: re-propose), writes
`contract.json` and `projection.json` canonically under compare-and-swap, and
**stages** them. It never commits. The commit is your signature on the review,
and a tool that made it would be signing on your behalf.

`show` renders what is on disk and contacts nothing, which is the point:
`contract.json` and `projection.json` are committed review artifacts, and
answering "what did my colleagues actually review" with a fresh probe would
make that question unanswerable.

Readiness is never read out of the contract. It is recomputed from the
registry and a live probe every time, so a declaration can widen what Duo is
*allowed* to touch and can never widen what Duo *claims*.

## What this profile does not claim

Three statements belong in the same breath as any assessment you act on.

**Site certification does not exist yet.** The contract's attestation is
always written `unsigned`, and every site-scoped claim therefore projects
`Uncertified`. `Site-certified` is never emitted by this profile — not for a
site adapter with local evidence, not for one you wrote yourself. No amount of
local evidence changes that in this release.

**Containment is unknown wherever it is not structurally prevented.** The word
`sandboxed` is never emitted, because there is no egress control to
interrogate. Where a row says `unknown — not enforced in this profile`, Duo is
telling you it did not measure anything, not that it measured and found
nothing.

**`compensatable` is never emitted.** It would need a declared compensation
action, and none exists. A surface either restores from a bundle, does not
apply, is irreversible, or is unknown.

Those three absences are checked in code on every projection, not merely
documented here: a future edit that softened one of them fails on the machine
that produced the report.

## Where to go next

- [release.md](release.md) — rehearse, release, verify: what the assessment
  you just accepted is actually for.
- [recovery.md](recovery.md) — what comes back when a release goes wrong, and
  the literal list of what does not.
- [capabilities-and-limits.md](capabilities-and-limits.md) — the full
  projection table, the five classes underneath it, and every refusal's one
  remedy.
- [adapter-authoring.md](adapter-authoring.md) — for every row that said
  `install adapter`.
