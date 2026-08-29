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

**Before `duo init`, the assessment is a preview of adoption.** On an adoption
seed (a `site.duo.json` init has not yet owned) the seed's own pin set is
`core` alone, and an assessment against it would read every active plugin as
`plugin:<slug> — install adapter` and every WooCommerce table as unclassified
on a shop the library ships a certified adapter for. So the agent projects the
surfaces against the policy `duo init` would propose — the adapters it selects
for the active plugins and theme, the scope it proposes, the types it leaves
local — and says so on its own line, before any surface row:

```text
adoption: this repository is an adoption seed — assessed as duo init would propose it: adapters core, woocommerce · left local: post_type:elementor_library · init advisories: 1 · init would refuse: 0 · init is ready
```

`--format=json` carries the same facts under `authority.adoption` (`preview`,
`adapters`, `scope.post_types/taxonomies/left_local`, the proposal's
`advisories` and `unsupported` rows, `ready`); an init-owned repository
carries `authority.adoption: null` and no such line. Nothing is written: the
proposal is the same read-only one the initialization probe already runs.

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
Catalog index    authored           manage          Ready                  Site-certified      prevented    provider-state restorable
  meaning: authored catalog content travels between environments
plugin:wpforms-lite  unclassified   block           Not qualified          Uncertified         unknown      unknown
  reason: install or author an adapter that models this surface, classify it, or declare it out of scope in the contract
  next action: install adapter (capture, merge, release, verify, delete, recover)
Custom catalog   unclassified       block           Not qualified          Uncertified         unknown      unknown
  reason: install or author an adapter that models this surface, classify it, or declare it out of scope in the contract
  next action: classify (capture, merge, release, verify, delete, recover)

unknown: 41 option name(s) invisible to every installed adapter
         3 pending classification(s) (duo pending production)
         2 undeclared table(s) (no installed adapter declares them)

next actions:
     44  classify
      0  declare in contract
      0  qualify in rehearsal
      0  exclude
      1  provision env value
      3  install adapter
      0  certify adapter
      0  attest contract
      2  nothing — supported
evidence: 4 certification subject(s) pinned
          certified by site-1a2b3c4d5e6f (site trust root); contract attestation unsigned
proposed contract written: .duo/contract/production/proposed.json (accept with duo contract production accept)
```

Exit 0 means every requested projection is `Ready` or `Ready with conditions`.
Exit 3 is still a complete, bounded assessment, but at least one readiness row
is red. Exit 1 means the assessment itself refused — for example an unreachable
target or unsupported topology.

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
`Unsupported`. It is recomputed on every run from the capability report the
target projects out of the reviewed dispositions, plus a live probe — never
read from a file. A condition under a `Ready with conditions` row is re-checked
at the mutation gate, so it is a live promise, not a footnote.
`Requalification required` has exactly one entrance, and it is not one the
agent can produce: the CLI raises it when an *accepted contract*'s pinned
evidence no longer matches what the target answers from — either its
`registry_sha256` or one of its `manifest_pins[].adapter_digest` rows. When the
moved adapter can be named, only the surfaces it governs flip and
`projection.json` says so in `evidence_pins.invalidation: exact`; when it
cannot, every surface flips (`whole-contract`)
(see [capabilities-and-limits.md](capabilities-and-limits.md#the-six-projected-dimensions)).

**certification** — where the claim comes from: `Platform-certified`,
`Site-certified`, or `Uncertified`. `Site-certified` means a certificate
verified under a trust root your organization or the agent owns, signed over
that adapter's exact bytes — customer-organization approval, explicitly **not**
a Duo endorsement. It is what `duo adapter certify` produces; see
[adapter-authoring.md](adapter-authoring.md#your-organizations-own-approval-duo-adapter-certify).
The evidence block prints `certified by <principal> (<root> trust root);
contract attestation unsigned` once — the second half matters, because a
certified *adapter* does not make your application *contract* a signed
document. Once you run `duo contract <env> attest`, that half reads `contract
attested by <principal> (site trust root, expires <when>)` instead, and it says
so only because the signature verified on this read. See *What this profile does not claim* below.

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

The assessment prints a count per action. There are exactly eight, the set is
closed — a gap Duo cannot express as one of these is a bug, not a judgement
call — and every one of the nine is printed with its count *including the
zeroes*. A section that showed a line only when it had rows would teach you to
read the presence of the line as the signal. The count is the signal.

| Next action | What you actually do |
|---|---|
| `classify` | The surface has no disposition and is already in the review queue. `duo pending <env>` lists it; `duo classify <env>` decides it. Cheapest remedy on the list, which is why it sorts first. |
| `declare in contract` | Duo can see the effect but cannot bound it. Add the declaration to `.duo/contract/<env>/proposed.json` and accept it — see below, and the containment rule in [release.md](release.md#when-release-refuses-before-it-freezes-anything). |
| `qualify in rehearsal` | **Never printed by this profile.** It stays in the closed set so a projection written by an older build still validates, but nothing emits it: rehearsal says in its own output that it cannot qualify anything, so naming it as your next step was sending you to prove that. |
| `install adapter` | Nothing models this surface, and something probably owns it — an active plugin, or a table with a plugin's name on it. Write or install an adapter; [adapter-authoring.md](adapter-authoring.md) is the whole path, and `duo adapter-draft --seed` will propose the surface for you. |
| `certify adapter` | The adapter **is** installed and is one signature or one pin short: `duo adapter certify <site-repo> --name=<n> --secret-key-file=<key> --pin`. It is also the word for the other two rows that need a reviewed, current claim rather than a repeat run — a contract whose pinned dispositions moved (`Requalification required`) and an authored `experimental` status — neither of which a rehearsal can produce. |
| `provision env value` | A manifest-declared `class: "env"` option is unset here: `duo env-set <env> --name=<name> --stdin`. |
| `exclude` | The boundary is stated, not broken. Record the decision in the contract's `unsupported[]` and stop trying to release it. Also the answer when an installed, certified adapter's certification simply does not cover one operation (`operation_not_certified` alone — typically `delete`): nothing to install or sign; keep that operation off the surface. |
| `attest contract` | **Never printed by this profile**, for a different reason than `qualify in rehearsal` above. The signer exists — `duo contract <env> attest` signs your contract under an Ed25519 key in `.duo/contract/authorities.json` — but that trust root ships with no key, so attesting is a decision to hold an organizational signing key rather than a next step Duo can hand you. It is in the closed set so that a projection written by a build that does emit it still validates. |
| `nothing — supported` | Every projected operation agrees. This is last in the ordering so it wins only when nothing else applies. |

A row can read `Ready` in the columns and still carry a next action, because
the columns show one operation and the action reduces over all six. That is
why the action names its operations: `exclude (delete)` is a complete
sentence.

The unknown section gets actions of its own. A pending item is `classify`
because `duo pending <env>` and `duo classify <env>` read that exact queue.
An option name invisible to every installed adapter and an **undeclared
table** are both `install adapter`: neither has an installed model that could
put it in the queue, and `duo adapter-draft --seed` is the executable path to
one. Those are counted and *named*, never valued — `duo assess` prints names
and counts only, exactly as `duo coverage` does.

## Record the decision: the application contract

An assessment is a reading. A contract is a decision, and it is the thing the
rest of the loop consumes: `duo release` cites its digest in the frozen
authorization plan, `duo verify` reads its declared journeys, and `duo recover`
reads its declared external effects for the does-not-restore list.

```sh
duo contract production propose
$EDITOR .duo/contract/production/proposed.json
duo contract production accept
duo contract production show
```

`propose` re-runs the assessment and writes `.duo/contract/<env>/proposed.json`.
A proposal is never authority — a declaration cannot certify itself — and the
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

**Two tiers, and the paths say which.** The contract is **per site** — one
`.duo/contract/contract.json`, with `environment_bindings.required[]` naming
what varies between environments — while the proposal and the review you write
into it are **per environment**, at `.duo/contract/<env>/proposed.json`. So
`duo assess stage` never touches a `production` proposal you are halfway
through reviewing, and `duo contract production accept` refuses a proposal
stamped for another environment
(`contract_proposal_environment_mismatch`) instead of reporting it as a site
that moved.

`show` renders what is on disk and contacts nothing, which is the point:
`contract.json` and `projection.json` are committed review artifacts, and
answering "what did my colleagues actually review" with a fresh probe would
make that question unanswerable.

Readiness is never read out of the contract. It is recomputed from the
registry and a live probe every time, so a declaration can widen what Duo is
*allowed* to touch and can never widen what Duo *claims*.

## What this profile does not claim

Three statements belong in the same breath as any assessment you act on.

**`Site-certified` is your organization's word, not Duo's — and it does not
sign your contract.** It is emitted now, and only on a fact: a certificate
verified under a trust root your repository or the agent owns, over that
adapter's exact bytes. What it attests to is narrow and the certificate says
so out loud: `exercised: false` rides onto the claim itself, beside the
grammar verdict and the reason you stated, so `certified` can never be read as
"somebody ran it". It is not a claim that the adapter was tested against a
live site, and it is not a Duo endorsement of anything.

The contract's own `attestation.state` reads `unsigned` until you attest it,
which is why the evidence line ends `contract attestation unsigned`. A
certified *adapter* and a signed *contract* are different documents signed
under different trust roots: `duo contract <env> attest` signs the second,
under a key you provision in `.duo/contract/authorities.json`, and that file
ships absent — so the line above is what every site says until an organization
decides to hold a signing key. Once attested, the same line names who attested
and until when. The resumable 12-step qualification workflow that would justify
a stronger word still remains deferred, as does any ruling on whether a
platform-owned root may attest a contract.

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
