# Capabilities and limits

This guide answers three questions an operator asks constantly: what does Duo
consider *mine* to manage, what will it refuse and why, and where is the line
past which it does not claim to work.

The certified matrix itself — which adapters are certified for which plugin
versions and which operations, and every explicitly unsupported boundary — is
**generated** and lives in [docs/capabilities.md](../capabilities.md). It is
produced by `scripts/capability-registry.php` and byte-compared by
`make release-gate`. Nothing here restates a row of it, deliberately: a
hand-copied certification claim in a guide is a claim that goes stale silently,
and the whole disposition/registry split exists to prevent exactly that.

To ask the question for *your* repository rather than the shipped library:

```sh
duo capabilities production --operation=promote --format=json
```

It resolves your exact manifest pins against the generated registry and
evaluates them for the requested platform revision, target versions, operation,
and state surface. It reports plugin execution separately from branchable
authored state, and gives structured blocker codes rather than a bare no.

## The five classes

Every value Duo sees gets exactly one classification. This vocabulary is the
core of the system, and getting it right at classification time is what makes
everything downstream work.

- **`authored`** — portable intent. A human decided this and it should travel
  between environments. Page titles, form definitions, SEO metadata, theme
  settings. This is the only class that enters `state/`.
- **`runtime`** — environment-local operational state. Disposable, or
  meaningful only here: caches, session data, transient markers, order rows.
  Excluded because versioning it is pointless.
- **`derived`** — state that a declared regeneration path rebuilds from
  authored truth. Search indexes, lookup tables, computed attachment metadata.
  Excluded because it is reproducible, and where a repair path is declared,
  `derived` additionally means "regenerate this on apply". Those repairs are
  declared as **data**, never as commands: the engine executes no
  manifest-supplied command string. A manifest names either an action from the
  engine's closed native vocabulary or a capability of a provider that apply
  negotiates and verifies by value-level readback before it mutates anything.
- **`env`** — genuinely per-environment values that are provisioned
  separately: a payment gateway key, `siteurl`, `admin_email`. Excluded for
  the *opposite* reason to `runtime` — not because it is disposable, but
  because replaying one environment's value onto another would be actively
  wrong.
- **`managed`** — lifecycle-managed options. Captured and reconciled by
  dedicated code (`active_plugins`, `template`, `stylesheet`), never by the
  generic state path, because activating a plugin must fire hooks and the
  state apply window must not.

Anything a manifest and site policy both fail to match is **unclassified**,
which is a loud abort rather than a default. That posture is the reason a Duo
site repo can be trusted: nothing lands in `state/` because nobody thought to
exclude it.

### Plan category summaries (DUO-3345)

`wp duo plan --format=json` adds an additive `category_summary` object with format
`duo-plan-category-summary/v1`; `duo status` and human `wp duo plan` render the
same projection. The existing action buckets and their detailed rows are
unchanged, so consumers may ignore `category_summary` when they need the older
envelope. The projection has nine stable, ordered categories:
`code`, `lifecycle`, `authored_state`, `generated_effects`, `media`,
`secrets`, `environment_state`, `capabilities`, and `deletions`. Each category
uses count-only `metrics`, `entity_actions`, and `contained_entities` facets;
facets intentionally overlap (for example, an attachment can be media and a
deletion at once). Counts include all WordPress surfaces represented by the
plan: posts, terms, menus, options, sidebars, user-meta sidecars, typed tables,
deletion buckets, and plugin/theme code findings.

The product vocabulary calls rebuilds and other reproducible projections
**generated effects**. The shipped manifest and wire vocabulary remains
`derived`; the summary records this explicitly as
`public_label: "generated"`, `wire_class: "derived"`. No new `generated`
manifest class is introduced. Capability, code, authored-state, and generated
counts stay separate, and capability blockers retain their source/selection
provenance. The secrets category emits only
`visibility: "redacted"`—it never scans or counts warning text or environment
names—and all other summary data is bounded counts, states, phases, and kinds.

### Bounded large-plan views

No-flag `wp duo plan` and `duo status` retain their existing full-plan JSON
and normal behavior. Filtered direct-plan row labels and host status plan-row
labels safely normalize C0/DEL controls. An explicit `--category=<csv>`, `--action=<csv>`,
`--entity=<csv>`, or canonical `--limit=<1..200>` requests the bounded
`duo-plan-view/v1` display projection from the same complete plan snapshot.
The detailed buckets remain present and authoritative; the projection declares
`authoritative: false` and is never consumed by apply, promotion, or
convergence.

Categories use the nine ordered ids above; actions are the ten normal plan
buckets; entities are `post`, `attachment`, `term`, `menu`, `sidebar`,
`options`, `user_meta`, and `typed_table`. Values are exact comma-separated
closed tokens, canonicalized/deduped in vocabulary order. Values within one
dimension are ORed, supplied dimensions are ANDed. A view defaults to and
hard-caps ordinary rows at 200; v1 has no cursor, so full unfiltered JSON is
the complete escape hatch. Rows are value-free refs only (bucket, hash
selector, closed entity/category facets, safety bit); the selector uniquely
resolves a UUID inside the complete bucket without exposing a source position.
They are sorted by fixed action rank then bytewise UUID. The view never copies paths, titles, values,
secrets, PII, target ids, or plugin-specific engine facts into JSON.

The view reports full/matching/shown/omitted/forced-safety evidence and full
readiness/global counters. Drift, conflict, collision, delete-conflict, and
blocked-delete rows bypass every filter and cap; global diagnostics, including
`regen_context`, remain full-plan facts. Category requests require the
same-snapshot valid `category_summary`; action/entity/limit-only requests do
not. `duo status` forwards one normalized request and typed-refuses
`plan_view_unavailable` if a requested agent view is missing, malformed, or
does not bind that full JSON plan. Its filtered human output itemizes only
matching ordinary rows while retaining existing complete safety/global blocks;
newly itemized path/title labels are one-line C0/DEL-safe. Text/path/title/
value searching, raw-value views, cursors, and interactive plan-view or
raw-value diffs remain out of scope. The separate host refresh workflow may
offer only its bounded value-free/redacted field resolver; it is not a plan-view
or literal-value interaction surface.

### `env` values in practice

`class: "env"` rules carry a mandatory boolean `required` — a manifest omitting
it fails to load. `true` means a human must provision this on every fresh
environment; `false` means the owning plugin self-populates it and it is not
worth checklisting.

Because env values are never captured, there is no repo-side record of what any
environment's value *should* be — only whether this environment currently has
something non-empty in each declared slot. `duo plan` and `duo status` surface
that as the `env_missing` bucket, and `duo env-set` is the only sanctioned way
to write one:

```sh
duo env-set production --name=woocommerce_stripe_key --stdin
```

Prefer `--stdin` for anything actually secret: interactive host use masks the
local terminal for one newline-terminated host-side read, restores it, then
starts the target and sends only that line through detached Docker or SSH
stdin. A target startup failure therefore happens with visible terminal state,
and an incomplete pipe write is refused rather than stored. Direct
target use masks at the agent, and piped input never acquires a terminal echo.
The host restores
echo on normal, exceptional, HUP, INT, QUIT, TERM, and TSTP exits/suspension,
re-masks after resume, and refuses interactive use when PHP signal support
cannot guarantee that restoration. After the complete value is handed off,
termination signals are deferred until the target returns so Duo reports the
real mutation outcome rather than a false cancellation. The post-handoff wait
has no local timeout because timeout cannot prove a remote write stopped;
diagnose a stuck target from another session. Ctrl-Z suspends the wrapper and
foreground child/transport together, but a non-PTY Docker/SSH target may
continue remotely; `fg` resumes the local outcome wait. Nonblocking pipe
handoff keeps local signal handling responsive while a slow target applies
backpressure.
SSH explicitly uses
`-T`, overriding any `RequestTTY=force` user configuration. The value is never
logged or placed in argv, whereas `--value` lands in shell history and process
listings like any other flag. `env-set` refuses any name the loaded
policy did not declare `class: "env"`, refuses an option declaring `sub_keys`
(a structured plugin-managed blob a bare string write would corrupt), and
refuses an empty value.

Scope note: `class: "env"`, `env_missing`, and `env-set` operate on **options
only** today — never post or term meta, and never a `sub_keys` carve-out's
individual keys, which stay governed by their own class. The reasoning is in
[cli/README.md § Env-bound value provisioning](../../cli/README.md#env-bound-value-provisioning).

## The six projected dimensions

The five classes above are a *stored* fact: every value Duo sees carries one.
The six dimensions on this page are not stored anywhere. They are **projected**
onto shipped facts — `Policy::CLASSES`, the generated capability registry, the
provider negotiation result, your reviewed contract's declarations, and the
selected recovery profile's covered inventory — every time you run
`duo assess`, `duo contract show`, `duo rehearse` or `duo release`. One
implementation produces them, in
[cli/src/Contract/ProjectionVocabulary.php](../../cli/src/Contract/ProjectionVocabulary.php),
and a projected word is never written back into a manifest, the registry or
`dispositions.json`. A declaration cannot certify itself, so readiness is
recomputed from evidence on every run rather than read from a file.

Each dimension is a **closed set**. Anything outside it is a defect, not a new
case, and three words in these sets can never be printed at all in this
release — they are listed with their reason because their absence is the
honest part.

Every surface is projected for six operations: `capture`, `merge`, `release`,
`verify`, `delete`, `recover`. `duo assess`'s table shows one of them at a
time and `--format=json` carries all six.

**1. State class** — who owns this state.

| Value | Projected from |
|---|---|
| `authored` | a policy/manifest `authored` rule — or a `managed` lifecycle option, which is authored intent reconciled by dedicated code |
| `runtime` | a `runtime` rule |
| `derived` | a `derived` rule |
| `environment-bound` | an `env` rule |
| `external` | a **declared** provider action whose declared effects reach a system outside this WordPress install |
| `unclassified` | no rule matched: the review queue, or a name `duo coverage` reports as invisible to every installed adapter |

`external` is only ever emitted from a declaration. Duo never infers from
observation that a surface is externally owned; an unmodelled integration
lands in `unclassified`, and therefore in `block`.

**2. Handling** — the consequence of the class.

| Value | When |
|---|---|
| `manage` | `authored` inside the captured scope, and the `managed` lifecycle options (annotated: reconciled by the code half, never by the generic state path) |
| `preserve local` | `runtime` |
| `rebuild` | `derived`. With no declared repair path the row keeps `rebuild` but readiness is forced to `Not qualified`, quoting the registry's own reason |
| `rebind` | `environment-bound` |
| `re-synchronize` | `external` **with a declared re-sync action**. Duo ships no generic one, so in practice an `external` surface blocks until a manifest declares otherwise — and the row says so |
| `block` | `unclassified`, or any surface whose containment is unknown for the operation being projected |

**3. Technical readiness** — computed from one capability-registry evaluation
for this exact operation × surface × revision × target probe.

| Value | Registry evidence behind it |
|---|---|
| `Ready` | certified, with no condition that is re-checked at the mutation gate |
| `Ready with conditions` | certified, with at least one condition re-evaluated against the live target at the mutation gate (`plugin_version_mismatch`, `plugin_not_active`, `wordpress_version_mismatch`, `php_version_mismatch`, `database_version_mismatch`, `theme_version_mismatch`, any `env_missing` row) — or a provider negotiation problem, which is an **unmet** condition and therefore blocks, naming its code |
| `Requalification required` | `evidence_not_current`, `revision_not_certified`, or a recovery profile's own evidence gone stale |
| `Experimental` | an experimental claim, or candidate evidence |
| `Not qualified` | `adapter_source_uncertified`, `missing_registry_entry`, `surface_not_registered` |
| `Unsupported` | an excluded or unsupported claim, `surface_explicitly_unsupported`, `multisite_unsupported`, or a delete on a surface named in the adapter's own unsupported deletion semantics |

Readiness is a *technical* answer, never a permission. Four of these six words
block a release outright before it freezes anything —
[release.md](release.md#when-release-refuses-before-it-freezes-anything) has
the gate.

**4. Certification provenance** — where the claim comes from.

| Value | Meaning |
|---|---|
| `Platform-certified` | shipped adapter, certified claim, current evidence. The generated matrix in [docs/capabilities.md](../capabilities.md) is the authority |
| `Site-certified` | a site adapter whose certificate verified: an Ed25519 signature over that adapter's exact bytes, under a key in a trust root the repository or the agent owns, with an exact `{name,source,digest}` pin. `duo adapter certify` produces one |
| `Uncertified` | everything else — no certificate, or a certificate whose pin does not bind it (`signed_unpinned`, which the row names) |

**What `Site-certified` does and does not mean.** It is
customer-organization approval through Duo's certification protocol,
**explicitly not a Duo endorsement**. The signature is real and it is checked
on every load: tamper with the adapter, the certificate, the authority record
or the pin and the claim drops rather than degrading quietly.

What it attests to is narrow, and the certificate records that rather than
leaving it to be assumed. `duo adapter certify` signs
`{grammar: <validator verdict>, exercised: false, reason: <yours>}`, and
`exercised: false` rides onto the **claim** — so nothing downstream can read
`status: certified` as "somebody ran it". The claim also carries no named
tests, because `evidence.tests: ["something"]` is indistinguishable from a
reviewed conformance run. Deletion semantics are declared **unsupported**: a
validator run reviews none. So `Site-certified` says *this organization
approves these exact bytes, and the engine's validators accept the manifest's
grammar*. It does not say the adapter was exercised against a live site, and
the certificate itself is what says so.

Three things it does not do:

- It does not sign your **contract**. `attestation.state` is still written
  `unsigned`, which is why assess prints `certified by <principal>
  (<root> trust root); contract attestation unsigned`. A certified adapter and
  a signed contract are different documents and this release ships the first.
- It does not become `Platform-certified` under the agent-owned trust root. A
  certificate about a *site adapter* reads `Site-certified` whichever root
  signed it; only the named root changes. A signed override of a shipped
  adapter is `Site-certified` too — the platform did not review your copy.
- It does not replace the resumable 12-step qualification workflow, which stays
  deferred along with production-grade key custody.

**5. Effect containment** — whether an operation can reach a live external
system.

| Value | Meaning |
|---|---|
| `prevented` | the surface is mutated exclusively inside apply's hook-free window and the plan touches no declared provider action for it. Always printed with its literal basis: *no WordPress hooks fire in the apply window* |
| `unknown` | everything else, printed as *unknown — not enforced in this profile*: the whole code lifecycle window (deploy → retire → activate → finalize, where hooks *do* fire), every declared provider action, every regenerator |
| `live` | your reviewed contract declares a live external effect for this surface |
| `sandboxed` | **never emitted.** Duo ships no egress control, so the value is not structurally provable |

**6. Effect recovery semantics** — what a rollback would give back.

| Value | Meaning |
|---|---|
| `provider-state restorable` | the bytes are inside the selected recovery profile's covered inventory, and the row names the bundle |
| `not applicable` | the operation creates no external effect |
| `irreversible` | a delete or tombstone with no restore coverage, or a surface whose deletion semantics are declared unsupported |
| `unknown` | containment is unknown and an effect may exist — and the operation blocks |
| `compensatable` | **never emitted.** It requires a declared compensation action, which does not exist in this release |

### Containment is unknown, and that is a statement rather than a gap

Duo does not default-deny outbound HTTP, mail, payment, webhook or queue
traffic; it does not strip or rebind production credentials when it
materializes a rehearsal environment; and it does not verify containment
before a workflow is exercised. The only containment it can *prove* is
`prevented`, and the proof is narrow and exact: the state apply window fires
no WordPress hooks, so nothing in it can re-send a mail or re-charge a card.

Three consequences follow, and each one is visible in the output rather than
buried here.

- **A rehearsal is a preview, not a sandbox.** `duo rehearse` prints the
  disclosure before it contacts the provider, every run, and states the
  consequence in the same breath: a rehearsal in this profile cannot authorize
  an `Experimental` or `Uncertified` capability, because the spec permits that
  only once containment is proven. Point a preview at test credentials.
- **The lifecycle window has to be declared before it can be released
  through.** `retire` and `activate` run on every promotion, code or not, and
  hooks fire there. Unknown containment must never reach a live system, so
  `duo release` refuses until the application contract carries a reviewed
  `external_effects[]` entry naming that window `live` with an explicit
  recovery semantics and a human reason. The declaration does not contain the
  effect; it converts an unknown into a known, bounded, reviewed one.
- **Rollback restores bytes, not consequences.** Every recovery profile —
  including the strongest — prints a non-empty list of what it does not
  restore, and it is the literal truth: see
  [recovery.md](recovery.md#restores-versus-does_not_restore).

## Secrets and personal data

### The secret gate has two tiers

**`hard_match`** is a high-confidence vendor token shape. A hit is a fact, not
a heuristic: it **aborts capture** outright, and `wp duo classify` refuses to
set that key `authored`.

**`suspicious`** is a key-name-plus-shape heuristic. It is a weak signal that
blocks nothing by itself; it exists solely to put a prominent `[SECRET: …]`
flag on the item in `duo pending` so a human looks twice.

The escape hatches are explicit and narrow:

- Per rule, `allow_secret: true` on that exact rule permits the authored
  classification. It is a reviewed exception, not a recommendation to keep
  secrets in git.
- In interactive `duo classify`, an `authored` decision on a secret-flagged
  item requires typing the literal word **`allow`**. Enter alone can never
  author a secret — not even by accepting a proposal.
- In `duo classify --accept-proposals`, secret-flagged items proposed
  `authored` are skipped loudly (their `section:key` and secret label printed
  to stderr) and the command exits **2**, so CI can tell "nothing to do" apart
  from "a human needs to look at this".

### The PII gate

Personal-data scanning is scoped to the user-meta sidecar only, because user
meta is credential- and PII-dense by default while the secret patterns are
globally useful. A hit requires an explicit `allow_pii: true` on that exact
`user_meta` rule. Unknown keys never reach the scanner at all — they stay
target-local unless an adapter or operator classified them `authored` first.

Note the related structural fact: **users are not repository entities.** They
are environment-local by design, never captured, never auto-created. `ref:
"user"` serializes as a `user:<login>` token, and the two resolution paths are
not equally forgiving: a post author resolves by login and *may* fall back to
the configured default author with a warning, while a user-meta sidecar
resolves the owning login by exact bytes and case and **never** falls back — a
required login that is absent is a hard refusal before any target mutation (see
`missing_user` below). No tracked issue plans authored-user synchronization;
this is a design position, not a backlog item.

## Refusal to remedy

`duo status <env>` answers "safe to promote?" and encodes the answer in its
exit code. Non-zero means no. The table below covers every condition in
`PlanSummary::render()`'s `ok` expression — thirteen of them — and the one
remedy for each. `code_mismatch` and `code_revision_stale` are split into two
rows because they demand different actions, though the exit code reads them
from the same list:

| Bucket | What it means | Remedy |
|---|---|---|
| `conflict` | Repo and environment both changed the same entity. Plan JSON and human output identify the last-synced base, repository intent, and target intent without exposing raw values. | The recommended choice is to capture/reconcile both intents in the repository and re-plan. `duo apply --force-theirs` selects the explicitly destructive alternative and reports every override; when that intent includes declared option deletion, the view also requires `--with-deletes`. Supplying deletion authority alone does not select the conflict override. |
| `delete_conflict` | The target no longer matches the base a deletion tombstone expected — someone changed the entity after the tombstone was written. Distinct from a blocked delete: nothing is referencing it, the *base* moved. The view includes the tombstone's expected-base and receipt evidence. | Capture/reconcile first, or knowingly use `duo apply --with-deletes --force-theirs`; both flags are mandatory. Once `--force-theirs` selects the override, a missing companion flag refuses before mutation and reports required versus supplied flags without calling the override authorized. `--with-deletes` alone retains the ordinary conflict refusal. |
| `collision` | An unmanaged environment entity already holds this slug. | `duo apply --adopt-by-slug=<kinds>`, or rename. Inspect every collision first. |
| blocked `delete` | A referential guard found live rows pointing at the deletion target. | Repair the referencing owner, or `duo apply --with-deletes --force-delete-referenced`. Forced execution stays loud. |
| `missing_user` | An authored user-meta sidecar names an exact login that does not exist here. Apply refuses before mutation. | Create or reconcile the user outside Duo, or declare `missing_user: "warn"` on every authored key in that sidecar to warn-and-skip it. |
| `code_mismatch` | Installed code disagrees with what state declares active. | Install/vendor the code, deploy first, or `--force-code-mismatch`. |
| `code_drift` | Managed code changed here since Duo's last trusted observation. | Re-deploy to accept the new baseline, restore the recorded version yourself, or `--force-code-drift`. |
| `code_revision_stale` | The artifact's code payload never completed stage → lifecycle → finalize. | `duo deploy <env>`. **Non-forceable** — this is the ordering invariant, not a judgment call. |
| `incomplete_apply` | A prior promotion failed before required rebuild/convergence finished. | Re-run apply; the retry clears the marker. |
| `incomplete_lifecycle` | A hook window failed after its durable pre-hook boundary, so a hook may already have committed state. | Restore the exact pre-lifecycle database checkpoint. **Non-forceable.** |
| `regen_pending` | A derived table with a hard per-entity availability dependency failed post-apply verification. | Nothing: the *next* `duo apply` retries it and either clears it or fails loudly. |
| `env_missing` (required) | A manifest-declared `class: "env"` option is unset here. | `duo env-set <env> --name=<name> --stdin`. |
| ordinary `drift` | The environment changed outside Duo. | `duo capture` first — this plan's comparison is already stale. |
| `adapter_dispositions` | A pinned manifest is experimental, unsupported, version-mismatched, or its evidence expired. | Pin a certified manifest and version, or accept the boundary and do not promote. |

If an apply fails after you explicitly authorized a conflict override, its
JSON refusal includes `forced_overrides`: hash-only, versioned evidence of the
choice that was authorized. It does not claim that the mutation committed;
inspect the private failure and apply recovery state before retrying.

Two of those rows are the ones that surprise people. `regen_pending` and
ordinary `drift` are cases `duo apply` does **not** refuse on — but `duo status`
still reports them as not clean, because it is a readiness probe rather than a
prediction of apply's preconditions. An *optional* (`required: false`)
`env_missing` entry is the mirror image: it is listed for visibility and never
flips the exit code by itself, because it is plugin-internal bookkeeping the
plugin populates on its own.

Plain warnings are rendered but never flip the exit code. The authoritative
decision matrix is the comment on `PlanSummary::render()` in
[cli/src/Plan/PlanSummary.php](../../cli/src/Plan/PlanSummary.php); the prose contract is
in [cli/README.md](../../cli/README.md).

### On force flags

Where a force flag exists at all, two standing rules apply: a forced override
must disclose its consequences, and it must ship an exit path through `duo` —
never through operator SQL. Where no force flag exists (`code_revision_stale`,
`incomplete_lifecycle`, every `code_source_*` and `code_plugin_dependency_*`
diagnostic), that absence is the design. Do not go looking for one.

## Which adapters are installed, and what may they do?

Three offline verbs answer that, with no environment and no WordPress:

```
duo adapter list    [--repo=<site-repo>] [--format=json]
duo adapter inspect <name> [--repo=<site-repo>] [--format=json]
duo adapter doctor  [--repo=<site-repo>] [--format=json]
wp duo adapter-survey [--repo=<path>] [--format=json]     # on the target
duo adapter-observe <env> [--out=<local-file>|--format=json]
wp duo adapter-observe --repo=<target-site-repo> --format=json # on the target
```

There are **three adapter sources**: the agent's own manifest library, a site
repository's `adapters/` overlay (with `--repo`), and one `duo-adapter.json` at
the root of each ACTIVE plugin that bundles one. Nothing else is discovered,
and pinning any other source is refused.

The host commands run WordPress-free, so they cannot see the plugin source at
all — it lives in `WP_PLUGIN_DIR`, which only the target has. They say so on
every run in a `sources` block that marks each source scanned or not scanned
and why; `wp duo adapter-survey` is the same survey running ON the target and
is where the plugin source is reported. An empty result never means "no adapter
is installed", only "none in the sources this process could reach".

`duo adapter-observe` is different from the offline catalog: it asks the
configured target once for a closed, canonical
`duo-adapter-observation/v1` proposal-evidence projection. The host has no
local `--repo` override and validates the target document, including its hash,
before it prints or create-only writes `--out`. The projection omits values,
target-local IDs, titles, paths, messages, SQL, and credentials. Its nested
`catalog` is a bounded projection of the target's
`duo-adapter-sources/v2` survey, not a claim to preserve the complete
`duo-adapter-catalog/v2` contract. It never makes AdapterDraft evidence
authoritative and never changes certification or registry claims.

The observer deliberately keeps normal plugin/provider registration and
capability negotiation enabled, because those facts are part of the live
report. Third-party callbacks can therefore have side effects before or during
evidence collection; Duo invokes no provider action and performs no explicit
mutation after observer entry. The projection is proposal evidence only: it
does not prove table semantics, apply, rollback, version lifecycle,
publication, or certification.

Adapter sources rank `shipped > site > plugin`. The two you author refuse
outright if both could answer one name. A plugin-bundled name that a shipped or
site definition already answers to is resolved instead: the reviewed definition
wins, and the bundled one prints on every run as an installed-but-not-loaded
row naming its winner. That row is deliberately not an error — nothing is
broken, the plugin stays active, and a permanently red doctor on every site
running a colliding plugin would make the exit code meaningless. A plugin
bundles at most one adapter, must name the plugin that owns it, and can never
be certified in place; certifying one means installing it as a repository
package (`adapters/<name>.json` plus a signed
`adapters/certifications/<name>.json`), which the precedence rule makes safe to
do with the bundling plugin still active.

Every adapter carries a **derived trust tier**, computed from the privileges
its own declarations actually reach, never self-declared:

| Tier | Reached by declaring | What it means |
|---|---|---|
| `declarative_manifest` | nothing executable | data only; classification, refs, guards |
| `native_action` | `actions[].kind: "native"` | a closed operation implemented by reviewed engine code |
| `plugin_provider` | `providers[].source: "plugin"` | executable semantics trusted as part of the installed plugin |
| `compatibility_shim` | `interpreter`, a `regen_dependency.regenerator`, or `providers[].source: "manifest"` | Duo-owned executable code shipped with the manifest — the exceptional, quarantined case |

The tier is the **highest** one a manifest reaches, not the first declaration
you happen to notice, and the table's rows are in ascending order. Declaring a
native action does not *get* you `native_action`: an adapter that also declares
a `providers[].source: "plugin"` reports `plugin_provider`, and one that
declares an interpreter, a regenerator, or a manifest-sourced provider reports
`compatibility_shim` regardless of everything else. `native_action` is what a
manifest reports when a native action is the *only* executable thing it
declares. That is the whole point of deriving the tier instead of accepting a
declared one — a manifest cannot report less authority than it asks for.

`list` prints the tier next to `tier_basis`, the exact declaration that
produced it, so a row reading `compatibility_shim` can be checked rather than
believed. `inspect` adds the reviewed disposition entry, the generated registry
claim, the providers the manifest requires with the capabilities each must
advertise, and the verification facts that already exist — `evidence.status`,
`plugin_execution.status`, and each cited test resolved against the bundle's
own verdict. There is no verification *score*; the certification separation
exists precisely so a new word cannot be minted next to reviewed evidence.

`doctor` adds this repository's readiness blockers and, more importantly, every
installed file the engine refuses to load — a shadowed adapter, an ambiguous
identity, a case-confusable name, a symlink, a nested or near-miss `.json`, a
reserved name — as ROWS with the engine's own message, a stable code, and a
remediation. Those conditions make every other command refuse outright, which
is why `duo adapter doctor` reports them instead of dying on them. Exit 0
healthy, 1 anything surfaced, 2 usage. Each run ends with what it did *not*
check; it never claims a live verdict.

For the live half — is the plugin installed, active, and in range? does the
provider answer? — `duo plan <env>` and `duo status <env>` now carry
`provider_problems` rows, one per declared provider capability this environment
cannot supply, each naming the declaring manifest, the owning plugin, and a
remediation. They are reported and counted but do not by themselves flip
`duo status`'s exit code: the diagnosis covers every *declared* provider
action, which is wider than the set any one apply negotiates, and apply's own
refusal stays where it belongs — immediately before the first mutation.

Plan-time diagnosis constructs the same provider objects apply does — a
manifest-sourced provider's file is required and its class constructed, and
plugin-sourced providers come off the `duo_providers` filter — so plan/status
now execute provider constructors and `identity()`/`capabilities()`. No
capability is invoked.

## Where the line is

Duo's boundaries fall into three kinds.

**Structural.** Multisite is refused before policy load or mutation. The
control plane accepts only the standard `wp-content/mu-plugins` layout with no
explicit `WPMU_PLUGIN_DIR` and no `SUNRISE`; other configurations fail during
compile, before any checkpoint or target write. Bedrock and custom content
roots need an explicit layout contract, not path guessing.

**Version-bound.** The certified WordPress, PHP, and database windows are
evidence-bound and move with each certification run, so they live in the
generated [docs/capabilities.md](../capabilities.md) rather than here.
`duo doctor` checks the installed PHP and database versions against
`docs/compatibility-baseline.json` for you.

**Per-adapter.** The generated page's *Explicit unsupported boundaries*
section enumerates every one of them with its reason — the shapes are worth
recognizing even though the list is not reproduced here: entity kinds whose
deletion has no closed guard grammar, derived tables a plugin exposes no
bounded repair for, runtime-sovereign data (orders, sessions, submissions) that
is excluded on purpose, and intent-only table declarations that are marked
unsupported rather than half-implemented.

That last pattern is doctrine, not accident: capability *reduction* is a
legitimate certification outcome. Working-but-unprovable behavior gets removed
and refused rather than shipped under-proven.

**Per-host environment lifecycle.** `duo env materialize` requires two
independent truths: a local/Docker/SSH environment driver that can run the
ordinary refresh and promotion workflows, and a privileged machine-local
provider that explicitly advertises coherent snapshot, attach or create,
mutation-fence, URL, receipt, and matching detach or destroy capabilities.
Checked-in `site.duo.json` cannot grant that authority. Unsupported create,
destroy, detach, snapshot, or TTL operations refuse before target mutation.
TTL is observable expiry metadata only; it never authorizes automatic deletion.
`duo env reap` is the sole cleanup path and compares the exact resource,
ownership lease, mutation fence, and optional TTL generation before acting.

## Planned capabilities

Everything below is unshipped at this commit, except where a bullet names a
slice that has already landed and says so. It is listed so you can tell
"Duo cannot do this" apart from "Duo will not do this", and route the former
rather than working around it.

- Bounded first-run initialization of an existing site — **Shipped (DUO-3336)**.
  `duo init <env>` proposes and, after explicit confirmation, captures separate
  code and state/media baselines once the agent is reachable and the target has
  Git plus a pre-existing ordinary `repo_path` reached without symbolic-link
  ancestors. It does not create that control directory, install WordPress, or
  deliver the agent; SSH and explicitly opted-in machine-local delivery use
  `duo adopt`, while Docker delivery is a separate capability.
- Local control-plane delivery — **Shipped (DUO-3365)** for a machine-local
  environment carrying the exact `duo-local-control-plane/v1` opt-in. Static
  driver capability reporting stays target-free; adoption separately proves a
  read-only safe target, atomically swaps the out-of-band agent/manifests/
  rollback authority plus an absent-only minimal seed, and runs doctor before
  commit. Docker delivery remains **Planned** and is not inferred from mounts
  or generic shell access.
- Discovery of adapters from a REMOTE source — a registry, an index, a URL you
  do not already have a copy of — **Planned** — not yet shipped. Every adapter
  Duo runs is a file already on the machine, in one of three local sources: the
  agent's own manifest library, a site repository's `adapters/` overlay, and one
  `duo-adapter.json` bundled by an active plugin. Pinning any other source is
  refused, and nothing fetches, resolves, or updates an adapter for you.
  An independently distributed adapter PACKAGE is not a missing source: it
  installs into the site source as `adapters/<name>.json` plus a signed
  `adapters/certifications/<name>.json`, and that path is shipped today —
  see "Which adapters are installed, and what may they do?" above, plus
  [adapter-authoring.md](adapter-authoring.md#declaring-repair-work-actions-and-providers).
  What is absent is the step BEFORE installation: finding out that such a
  package exists.
- Scoped promotion and synchronization with dependency closure — **Partially shipped (DUO-3344)**.
  Resolving and previewing a scope has shipped: `duo scope <env> --roots=<selectors>`
  names the roots you asked for, everything pulled in by a declared dependency
  edge (each row naming the edge responsible), references pointing into the
  scope from outside, and how much unrelated state is excluded. The preview is
  read-only and a root that does not resolve is refused rather than silently
  dropped. The immutable evidence slice is also shipped:
  `duo scope <env> --roots=<selectors> --contract` emits immutable
  `duo-scope-contract/v1` evidence bound to the outer artifact hash, separate
  state revision hash, and manifest hash. It records only static, potential
  actions/providers/effects and static deletion obligations; it does not
  negotiate providers or collect target guard witnesses. `all` includes compiled tombstones;
  an individual immutable tombstone is named `tombstone:<uuid>`, never a
  mutation-sounding delete selector. The host isolates this read-only workflow
  from ordinary plugin/theme/MU bootstrap before compiling. Capture and
  refresh/rebase now consume that evidence as a target-recomputed, state-only
  overlay: selected whole records may move while excluded state, tombstones,
  media, and branch code are preserved exactly. Scoped deletion is limited to
  a selected live identity with normal deletion capability and no excluded
  inbound referrer. Scoped plan/apply/verification now consume the same
  evidence only through a separate target-observation and lease-bound mutation
  authority. The durable session retains hash-safe original work/action
  identities plus an opaque sealed membership set for nested widget/menu-item
  map rows owned by selected sidebar/menu files. Selected map rows must be
  backed by the exact strict target observation before authority and on every
  recovery/verifier read; stale selected mappings refuse without pruning
  unselected rows. The session journals authored/effect phases, reconciles
  provider/native response loss by exact operation id, verifies selected
  intent plus protected out-of-scope roots in a fresh process, and advances
  selected ledger rows without claiming a global applied revision. A
  nonterminal scoped session interlocks full plan/apply. Triggerless actions,
  legacy regenerators, and attachment metadata generation refuse rather than
  widen authority. A narrow SSH-only scoped promotion profile is also shipped:
  `duo promote <ssh-env> --scope-contract=<path>` accepts only selected
  options, declared snapshot tables, sidebars, user meta, and option/table
  tombstones. It first holds a v2 exclusion covering every database writer,
  prepares an encrypted whole-database checkpoint, and binds target apply to
  an adoption-pinned signed recovery witness. That receipt/session/terminal
  binding includes the exact delete capability and external generation, so
  target-local retries cannot add `--with-deletes` or reuse an older terminal.
  It invokes no code, upload,
  lifecycle, native/provider-action, or ordinary release path. A failure
  before the durable `scoped_fresh_verification` seal restores and verifies
  the checkpoint; after that forward-only seal the host may only finish the
  exact signed commit, target handoff, and exclusion release—there is no later
  scoped rollback.
  Per-option capture, refresh, plan/apply, and SSH promotion now preserve the
  selected virtual option record while keeping carrier siblings out of the
  write set (DUO-3465). Code dependency movement, scoped code lifecycle, and
  user-invoked or post-seal scoped rollback remain planned rather than
  inferred; the shipped promotion profile still restores only before its
  durable fresh-world seal, while later retries finish forward.
- Redacted field-level refresh diff and interactive conflict resolver —
  **Bounded slice shipped (DUO-3345)**. `duo refresh --field-diff` emits a
  separate immutable, display-only/value-free `duo-refresh-field-diff/v1`
  projection for ordinary plan entries already classified as `conflicting`;
  branch-only, production-only, and compatible rows remain in the ordinary
  private plan/counts because they need no field choice. `duo rebase
  --interactive` or a canonical local `--field-resolution` consumes the
  matching value-free resolution. It never serializes literals, paths, stable
  IDs, bodies, metadata, options, user records, or per-value hashes; it reports
  only closed B/P/W presence/equality relations. `--interactive` is the narrow
  TTY-only local reveal exception: a bounded C0/DEL-safe authored title/name or
  path fallback may be shown beside its selector in memory only, never in a
  machine artifact. The only field-eligible engine
  surface is ordinary post scalar groups and term name/description/parent; body,
  attachment/media, menus, sidebars, options, user-meta, typed tables,
  tombstones, scoped plans, and opaque containers remain atomic. A live B
  record with P or W absent refuses field mode before a selectable diff;
  absence stays with the legacy whole-record resolver. Scalar relation evidence
  is canonical but exact source token bytes stay private, and containers are
  never normalized. Automatic `production-only` keeps production, while
  `branch-only` and `compatible` keep branch bytes. It refuses incomplete or
  skewed policy evidence and rechecks candidate policy after code replay. It is
  not a general JSON object merge, a WordPress-target mutation path, or a
  literal value-diff UI.
- Representative descriptor-driven plugin identity replacement — **Exercised (DUO-3357)** through the ordinary compositional plan and public `duo promote --with-deletes`; there is no special replacement command or general plugin/theme replacement claim.
- Theme upgrade, downgrade refusal, and removal as a managed lifecycle — **Exercised (DUO-3358)** by the ecommerce proof through the generic preflight, staged lifecycle, and finalization path.
- Bounded native WordPress-cron proof — **Exercised (DUO-3359)** in the ecommerce
  grind: one classified `publish_future_post` event is listed and run through
  public WP-CLI, while unrelated cron and Action Scheduler inventories remain
  stable. This is proof of the existing native scheduling contract, not a new
  Duo-managed scheduler or an unbounded queue-drain capability.
- Retiring the last Duo-authored WooCommerce business logic — **Partially shipped (DUO-3342)** — the
  dispatch migration has landed; the WooCommerce-authored semantics have not.
  The lookup rebuild lives in
  `manifests/providers/woocommerce-product-lookups.php` and runs through the
  provider contract — negotiated identity, a declared version window, engine
  batch channels, and a receipt whose `verified` is refused unless the adapter
  proved the values it wrote — instead of the engine's regenerator channel. What
  remains unshipped is the WooCommerce *semantics* inside that file — price
  synchronization that preserves authored meta, expected-attribute-row
  derivation, and raw-SQL verification queries — logic Duo maintains in an
  adapter package (`source: manifest`) that should belong to a provider the
  plugin itself advertises (`source: plugin`).
