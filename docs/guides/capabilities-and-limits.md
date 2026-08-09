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

Prefer `--stdin` for anything actually secret: it reads with terminal echo
disabled and is never logged, whereas `--value` lands in shell history and
process listings like any other flag. `env-set` refuses any name the loaded
policy did not declare `class: "env"`, refuses an option declaring `sub_keys`
(a structured plugin-managed blob a bare string write would corrupt), and
refuses an empty value.

Scope note: `class: "env"`, `env_missing`, and `env-set` operate on **options
only** today — never post or term meta, and never a `sub_keys` carve-out's
individual keys, which stay governed by their own class. The reasoning is in
[cli/README.md § Env-bound value provisioning](../../cli/README.md#env-bound-value-provisioning).

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
[cli/src/PlanSummary.php](../../cli/src/PlanSummary.php); the prose contract is
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
```

There are exactly **two adapter sources**: the agent's own manifest library,
and — with `--repo` — that site repository's `adapters/` overlay. Nothing else
is discovered, and pinning any other source is refused.

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

Everything below is unshipped at this commit. It is listed so you can tell
"Duo cannot do this" apart from "Duo will not do this", and route the former
rather than working around it.

- One-command bootstrap of a fresh site — **Planned (DUO-3336)** — not yet shipped.
  Today: adopt over SSH, or hand-write `site.duo.json`.
- Discovery of adapters you do not already have — **Planned (DUO-3339)** — not yet shipped.
  The engine has exactly two adapter sources: its own manifest library, and a
  site repository's `adapters/` overlay. A manifest a plugin ships inside its
  own directory, or one installed as a versioned package, is discovered by
  nothing, and pinning any other source is refused.
  Trust tiers and the installed-adapter catalog over those two sources HAVE
  shipped — see "Which adapters are installed, and what may they do?" above,
  plus
  [adapter-authoring.md](adapter-authoring.md#declaring-repair-work-actions-and-providers).
- Scoped promotion and synchronization with dependency closure — **Planned (DUO-3344)** — not yet shipped.
  Resolving and previewing a scope has shipped: `duo scope <env> --roots=<selectors>`
  names the roots you asked for, everything pulled in by a declared dependency
  edge (each row naming the edge responsible), references pointing into the
  scope from outside, and how much unrelated state is excluded. It is read-only
  — it captures, promotes, and deletes nothing, and no capture/promote/rollback
  command accepts a scope yet. A root that does not resolve is refused rather
  than silently dropped.
- Field-level value diffs and an interactive conflict resolver — **Planned (DUO-3345)** — not yet shipped.
  Plan rows already carry authored WordPress display names and a stable,
  hash-only three-way conflict view with bounded safe choices; those slices
  shipped without serializing secret/PII-bearing entity values.
- Provider-backed plugin and theme replacement — **Planned (DUO-3357)** — not yet shipped.
- Theme upgrade, downgrade refusal, and removal as a managed lifecycle — **Planned (DUO-3358)** — not yet shipped.
- Moving WordPress cron as managed state — **Planned (DUO-3359)** — not yet shipped.
- Retiring the last Duo-authored WooCommerce business logic — **Planned (DUO-3342)** — not yet shipped.
  The file boundary is already there: the lookup rebuild lives in
  `manifests/regenerators/woocommerce-product-lookups.php` and is dispatched
  generically by its manifest-declared name, never by a plugin check in the
  engine. What remains Duo-native is the WooCommerce *semantics* inside it —
  price synchronization that preserves authored meta, expected-attribute-row
  derivation, and raw-SQL verification queries — logic Duo maintains that
  should belong to a plugin-owned provider.
