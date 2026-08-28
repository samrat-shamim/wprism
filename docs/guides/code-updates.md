# Code updates: the runbook

Updating a plugin or theme is where the two halves of a WordPress site stop
being independent. New code expects a schema; migrations run inside the
plugin's own code on its own trigger; and state written into the wrong schema
is the failure mode nobody notices until a week later. This guide is the
ordering rules, the refusals you will actually hit, and what rollback really
costs.

The commands are documented in
[cli/README.md](../../cli/README.md); the design reasoning is in
[docs/code-half.md](../code-half.md).

## The lifecycle order, and why it is that order

`duo deploy <env>` runs these phases, stopping at the first non-zero one:

1. **flag validation** — `duo deploy` accepts exactly `--force-code-mismatch`,
   `--force-code-drift` and `--no-checkpoint`; anything else, including the
   orchestrator's own internal artifact and lease flags, is refused by name.
   `--no-checkpoint` is consumed here and never forwarded to the lifecycle
   phases, which own no checkpoint.
2. **rollback-authority fence** — target mutation is refused while the external
   rollback authority is invalid or in a nonterminal state.
3. **artifact and checkpoint directories** — created under the target's
   operational `.duo/` (the checkpoint directory only when one will be taken).
4. **code-resolve** — host-side, and only for a repository that declares
   `code/duo-code.lock.json`; silent for every other repository. See
   [`duo deploy` and `duo promote` resolve for you](#duo-deploy-and-duo-promote-resolve-for-you)
   below.
5. **compile** — the repository becomes one immutable, content-addressed
   artifact whose `artifact_hash` binds the state revision and, when present,
   a separate opaque code descriptor and revision.
6. **adapter disposition gate** — an experimental or unsupported adapter
   claim refuses here, *before* any lease exists.
7. **promotion-begin** — an exact owner/artifact session on the target.
8. **checkpoint** — a whole-database export under that same lease, retained at
   `<repo>/.duo/checkpoints/deploy-<owner>.sql` beside the
   `deploy-<owner>.json` artifact of step 5. It sits here, not earlier, because
   the dump has to contain the promotion lease row it was taken under — that is
   what makes `duo recover`'s abort → begin → import → final abort sequence
   valid for it. An export failure aborts the lease and stops before any code
   or lifecycle mutation. `duo deploy --no-checkpoint` skips this step: the
   export is a full dump written to the target's disk, inside the
   write-exclusion window and with no retention policy, so on a large database
   the cost has to be refusable — at the price of having nothing to restore
   from if a later phase fails.
9. **code-stage** — the new bytes land beside the live tree.
10. **lifecycle retire** — deactivation hooks fire.
11. **lifecycle activate** — in a *fresh process*, so the new code is what
    boots and its own updater notices the version change deterministically.
12. **code-finalize** — requires both lifecycle receipts, so a merely staged
    payload can never be promoted into a completed `code_revision` by skipping
    the WordPress lifecycle.

`duo promote <env>` is this same sequence with a state `apply` appended at the
end. That trailing position is the entire point: apply is hook-free and
canary-armed, and it must write into a schema the running code already
understands. Deploy is the window where hooks fire; apply is the window where
they must not. On a production SSH target with the complete `verified_rollback`
capability promote also selects a signed rollback profile; deploy never does.
Those two — the trailing apply and the profile selection — are what remain
promote's alone. The database checkpoint is now taken by both verbs.

The host sequences the halves. It does not interpret the code descriptor's
fields or mutate files itself — those checks and mutations belong to the target
agent, which is why the descriptor stays opaque to the orchestrator.

One operational caveat, stated plainly: the target database lease serializes
*Duo's* writers only. You must exclude package managers, self-updaters, and
anything else that writes `WP_CONTENT_DIR` during stage and finalize. Stable
symlinks are refused, but this materializer is not an adversarial filesystem-
race sandbox and does not claim to be.

Stage writes only what the target does not already hold. Every descriptor row
is re-checked under the lease — the source must be present, not a symlink, and
still hash to the compiled descriptor; the target must be absent or a regular
non-symlink file — and only then is a row whose target already carries those
exact bytes and that exact mode left in place instead of being republished.
A second `duo deploy` of the same artifact therefore rewrites nothing, and the
stage receipt says so on its own line, above the unchanged success line:

```
code payload: 0 written, 8918 unchanged of 8918 file(s)
Success: staged code revision <rev> (8918 file(s)); promotion lease retained for finalize
```

Verification is untouched: `code-stage` still re-hashes the entire
staged payload before lifecycle may run, and `code-finalize` re-verifies it
again, so a row wrongly left in place fails closed instead of being promoted.
A `written` count that stays high across two identical deploys is itself a
signal worth reading — either something outside Duo is rewriting
`WP_CONTENT_DIR` between runs, or the target filesystem is not preserving the
source's file modes.

## The five refusal families

### 1. `code_mismatch` — the environment's code is not what state declares

Raised by `Deploy::code_mismatch()` when the environment's installed code
disagrees with what `state/options/core.json` declares. The issue names you
will see are `missing_in_code`, `outside_version_range`,
`inactive_in_environment`, `unexpected_active_plugin`,
`active_plugin_order_mismatch`, and `template_mismatch`. Theme findings carry
the same shape with `kind: "theme"`.

**Remedy**: install or vendor the missing plugin, deploy the code first, or —
knowing exactly what you are overriding — pass `--force-code-mismatch`.

#### The third state: `version_range_graduated`

`outside_version_range` is the finding an ordinary WordPress auto-update
produces, and its only two exits are heavy: widen the manifest's range (a
reviewed edit across a capsule's `package/manifest.json` and `package/disposition.json`
that every deployed site then re-pins against) or force past it with no
evidence at all. There is a third, and it is evidence-bound.

Record what `duo adapter boundary` probed into your `site.duo.json`:

```json
"adapter_version_evidence": {
  "advanced-custom-fields/acf.php": {
    "manifest": "acf",
    "slug": "advanced-custom-fields",
    "releases": ["6.8.7", "6.9.0", "6.9.1"],
    "outcomes": [
      {"version": "6.9.0", "outcome": "green", "signature": "recapture 3f2a…"},
      {"version": "6.9.1", "outcome": "green", "signature": "recapture 3f2a…"}
    ]
  }
}
```

A probe is `green` only when that exact release installed, seeded, and
recaptured byte-identically under this adapter's declared surfaces — the same
round trip a certification run performs. When **every** recorded release
between the declared window and the installed one probed green, the finding
becomes `version_range_graduated`: deploy and apply stop refusing over it, and
both report it on every run with each release and its recorded signature named.
`wp duo plan` prints it under its own `VERSION_RANGE_GRADUATED` heading, and
`duo status` and `duo release` stop counting it against "safe to promote" —
the row is still in the `code_mismatch` bucket and still in its count, because
it is the same finding, answered.

Everything else still blocks, with the `outside_version_range` message
unchanged: no evidence at all, evidence that stops short of the installed
version, a release the recorded list does not name, a version header WordPress
could not read, evidence recorded against a different adapter, and any
`boot-fatal`, `round-trip-diverges` or `artifact-unresolved` row in the
interval. Silence is never treated as a pass — that is the whole design.

Two things this does **not** do. It does not widen the range: the manifest
still says what it was certified for, and the verdict says so in its own
message. And it is not `--force-code-mismatch` by another name — that flag is
unchanged, is still the only way past a real `outside_version_range`, and the
graduated verdict is never reported as forced.

This answers the compatibility question and only that one. A version that
changed here without Duo doing it is still `code_drift` (§2 below), a separate
provenance question with its own consent gate — accepting an installed version
as the new baseline stays a deliberate act, exactly as it was.

Adding the block moves your site's `site_hash`, so recompile and re-pin after
editing it. It moves no state revision: evidence names no option, meta key,
post type or table.

### 2. `code_drift` — code changed here, outside Duo

Raised when a plugin or theme's installed version differs from the baseline
Duo recorded at its last successful `duo deploy` or `duo capture`. The message
names the two versions and points at the usual culprit: a wp-admin or host
auto-update. `DISALLOW_FILE_MODS` (checked by `duo doctor`) is the policy fix
for the class of problem, not just its detection.

**Remedy**: re-deploy to accept the installed version as the new baseline,
restore the recorded version, or pass `--force-code-drift`. Note that a
`duo capture` is **not** an accept: capture observes reality, warns once per
finding, and leaves the recorded baseline alone. Accepting a code change is
`duo deploy`'s decision, and it has `duo deploy`'s consent gate in front of it.

Drift is scoped to exactly the plugins and theme slots the target state names.
Duo has no opinion about a plugin it was never told to manage.

### 3. `code_revision_stale` — the ordering witness is missing

The compiled artifact carries a code payload that has not completed the
stage → lifecycle → finalize sequence on this environment. This is **not
forceable**. Neither `--force-code-mismatch` nor `--force-code-drift` crosses
it, because it is not a compatibility judgment — it is the code-before-state
ordering invariant itself.

**Remedy**: run the host `duo deploy <env>` workflow. That is the only thing
that clears it. `duo promote` clears it inherently, by running the code phases
before state apply.

### 4. Source compatibility — `code_source_*` and `code_plugin_dependency_*`

These come from `CodeCompatibility::diagnostics()`, which runs offline against
the compiled descriptor and is repeated by `code-stage` under the target lease.
They are pure source-side checks — no WordPress, no database, no target call.

Version findings: `code_source_version_missing`,
`code_source_version_range_invalid`, `code_source_outside_version_range`, and
the three theme equivalents (`code_source_theme_version_missing`,
`code_source_theme_version_range_invalid`,
`code_source_theme_outside_version_range`). Dependency-graph findings:
`code_plugin_dependency_missing`, `code_plugin_dependency_inactive`,
`code_plugin_dependency_cycle`, `code_plugin_dependency_duplicate_slug`.

**Remedy**: there is no force flag for any of them, deliberately. Fix the
plugin headers, fix `active_plugins`, or fix the manifest's declared range,
then recompile. A malformed or absent version range is not a thing to proceed
past — it means the compatibility claim you are relying on does not exist.

### 5. The code lock — `code_component_*`

Only for a repository whose `site.duo.json` declares `code` format 2 (see
[The code half: Git never carries third-party code](#the-code-half-git-never-carries-third-party-code)
below). The lock names the components the repository deliberately does not
carry in Git and, in `first_party`, the ones it carries by declaration;
compilation refuses when the bytes on disk do not match what it declares. All
four are **blocking and non-forceable**: there is no argument on any code path
that relaxes them, by design — a payload that does not match its own
declaration is not a compatibility judgment.

- `code_component_unresolved` — the lock declares a component and this
  repository carries none of its bytes. This is the normal state of a FRESH
  CLONE of a split repository, and the expected remedy is
  [`duo code-resolve`](#resolving-a-split-repository) — which `duo deploy` and
  `duo promote` now run for you, before compiling.
- `code_component_digest_mismatch` — the component is present but hashes to
  something other than its declared `tree_sha256`. Either re-materialize the
  locked release, or, if these bytes are the intended ones, re-lock the
  component with `duo code-classify`.
- `code_component_unlocked` — the repository-root `.gitignore` excludes a
  component under `code/wp-content` that the lock does not declare. A clone
  would carry neither its bytes nor any way to obtain them. Declare it in the
  lock, or remove the ignore line.
- `code_component_undeclared` — the repository carries a plugin or theme
  component that the lock neither declares as locked nor as first-party. Git
  must not carry third-party code, so "vendored by omission" is refused by
  name: declare it with `duo code-classify --first-party=<root>/<slug>` if it
  is the site's own code, otherwise `duo code-import` its release archive on
  the host and re-lock it with `duo code-classify`. A legacy `duo-code-lock/v1`
  declares no first-party list and reaches this refusal for every component it
  carries; `duo code-classify` is how it gains the declaration.

**Remedy**: named per diagnostic above. The refusal always names the component,
its locked version, and the step.

## Version baselines: what actually gets recorded

The `code_versions` baseline is **overwritten, never merged** — never a
partial write that keeps the drifted entries and moves the rest. Which verb
writes it, and when, is the part that matters:

- **`duo deploy` writes it unconditionally**, at the end of a successful run.
  By then deploy has already refused on `code_drift` or been explicitly forced
  past it with `--force-code-drift`, warning once per overridden finding — the
  decision was taken, so the write is that decision's consequence.
- **`duo capture` writes it only when there is nothing to accept**: no
  baseline recorded yet, or zero drift. Across an unaccepted drift it leaves
  the recorded bytes exactly as they were and emits one warning per finding
  instead. Capture is the observe-reality verb; it does not reconcile code and
  has no `--force-code-drift` of its own, so it never quietly consumes a
  decision you have not made.

Whichever verb writes it, it records the installed version of **every
plugin currently active in the environment** — not only the ones target state
names — plus the template and stylesheet slots and their versions. Drift
detection then reads back a narrower slice: it compares only the plugins the
target state declares active, because Duo has no opinion about a plugin it was
never told to manage. Recording wider than you read is deliberate, so a plugin
activated today already has a baseline the next time it matters.

Four consequences follow, and the third is the one teams get wrong:

1. A plugin with no recorded baseline is not drift — it is simply unminted,
   and Duo skips it rather than inventing a comparison. In practice this means
   a plugin that was inactive at the last deploy or capture, since anything
   active then was recorded whether or not target state named it.
2. A theme slot whose *slug* changed is not drift either; that is a
   `code_mismatch`/plan concern, not a version comparison on one theme.
3. **A downgrade is the same `code_drift` as an upgrade.** There is no separate
   downgrade detection, no special-cased warning, and — the part worth saying
   out loud — **no Duo command performs the restore**. `code_drift`'s own
   message offers "restore the recorded version" as one of three remedies, and
   that step is yours: your package manager, your release directory, your
   backup. Duo detects the divergence and refuses to write state across it. It
   does not put the old bytes back.
4. **Capturing across a `code_drift` does not clear it.** The finding is still
   there on the next `duo status`, `duo plan` and `duo apply`, because capture
   left the baseline untouched. That is deliberate: the two ways forward stay
   the ones the finding's own message names — restore the recorded version, or
   accept the installed one with `duo deploy` (`--force-code-drift` if deploy
   is still refusing). Capture will tell you, once per finding, that this is
   what it did.

Theme upgrade, incompatible-downgrade refusal, unsafe parent-removal refusal,
and dependency-safe removal are exercised by the ecommerce developer proof.
The safe removal uses `promote --force-theirs` after a public source theme
switch so the captured standalone-parent intent explicitly wins over the
target option drift caused by the corresponding lifecycle switch.
They use the same generic source/runtime preflight, code-state relationship,
staged lifecycle, and finalization boundaries described above: no theme name
or theme-specific branch exists in the engine. Provider-backed plugin/theme
replacement is not a separate product or command.

The ecommerce proof exercises one representative descriptor-driven plugin
identity replacement through the ordinary compositional plan and public
`duo promote --with-deletes` workflow. It does not add a special
`--replace-extension` command or claim a general plugin/theme replacement
product. Its no-incompatible-migration rollback is another public promotion
of the exact prior descriptors. The forward checkpoint is retained and
byte-verified as evidence, but is not imported after the reverse promotion
supersedes its recovery session. This bounded proof composes with the exercised
theme lifecycle; it does not claim a general plugin/theme replacement product.

## Rollback

### The rule that matters most

**Roll back code before any migration has run against production data, or roll
back code and restore the pre-migration database snapshot together. Never roll
back code alone against an already-migrated database.**

This is a real limit, not a solved problem. Duo does not control whether plugin
authors ship down-migrations, and most do not. A site running WooCommerce's
HPOS tables under HPOS-unaware code is not a state Duo can reason its way out
of after the fact.

**A locked component's old bytes are not in Git history.** For a first-party
component, `git checkout <old-rev> -- code/` always works, because the bytes
are in the repository. For a component declared in `code/duo-code.lock.json`,
they are not: rolling back to a wp.org version that has aged out of your host
cache and off the registry, or to an imported archive no host still holds, is a
rollback you cannot perform without a copy of the archive. Keep the release
archives you may need to roll back to in your own archive store — a vendor's
download, a wp.org release ZIP — and `duo code-import` them on the host that
resolves; the repository deliberately never carries them. This is an explicit,
accepted consequence of the invariant, not an oversight.

### The promotion-recovery sequence

When a promotion fails, one verb performs the recovery:

```sh
duo recover production --list
duo recover production --restore=<receipt-id> --writers-excluded
```

`--list` names the checkpoint and what its profile restores; `--restore`
drives the sequence. Two preconditions are yours, not Duo's, and `--restore`
enforces both rather than advising them:

- **Code first.** If code may have been staged or partially finalized,
  reconcile or restore code to its known pre-promotion revision **before** the
  database import. `duo recover` refuses the import until you have, and names
  the exact revision — a database describing one code revision underneath
  another is the state nobody can reason about afterwards.
- **External writer exclusion.** Every successful checkpoint contains the
  temporary promotion lease row, so a lock stored inside the database being
  imported cannot protect the recovery window. Establish real
  maintenance/exclusion preventing every Duo writer for the whole window, then
  assert it with `--writers-excluded`. Without that flag nothing runs.

Underneath, the profile drives the same ordered steps a human used to type —
abort the exact owner/artifact pair, re-begin it, perform the fatal-safe
isolated database import that skips plugins, themes and user MU code, then
abort once more to clear the lease row the import restored. The final abort
runs even when the import fails, which is precisely the step people skipped by
hand. Those raw actions are named, as internals, in
[internals.md](internals.md); running them yourself is outside the supported
workflow.

The first abort refuses if a newer session has superseded this checkpoint,
rather than presenting an obsolete dump as a safe recovery source. The whole
path, including what a profile does *not* restore, is
[recovery.md](recovery.md).

### Automatic code rollback

Automatic, atomic code rollback exists only under the SSH verified-rollback
profile, and only with a configured `code_release_provider`. The in-place
`wp duo code-stage` / `code-finalize` materializer is explicitly a
**manual-recovery** profile — the code-release capability does not retroactively
make it recoverable. See
[docs/code-release-runtime.md](../code-release-runtime.md) for the contract and
[docs/recovery-runtime.md](../recovery-runtime.md) for the provider protocol.

## The code half: Git never carries third-party code

A site's `code/` tree holds every component's bytes on disk. What the
declaration decides, per plugin and theme, is whether **Git carries those
bytes** — and there are exactly two answers, recorded in
`code/duo-code.lock.json`:

- **Locked** — Git does not carry the component. The lock declares where its
  bytes come from and the digest its unpacked tree must have, and a
  root-anchored `/code/wp-content/<root>/<component>/` line in the
  repository-root `.gitignore` keeps it out of Git. Two origins exist:
  `wp-org-release`, the canonical downloads.wordpress.org archive for a
  published version; and `imported-archive`, an archive you imported on the
  host with `duo code-import` — the shape for a premium plugin, a private
  theme, a vendor build, anything with no wp.org release. The lock records an
  imported archive by its `archive_sha256` alone: no URL (a vendor download
  link is usually license-keyed) and no path (the repository carries no copy).
- **First-party** — Git carries the component because you declared it the
  site's own code, with `--first-party=<root>/<slug>` on `duo init` or
  `duo code-classify`. The declaration is recorded in the lock's `first_party`
  list, which is what lets the compile gate tell "carried by declaration" from
  "carried by omission".

There is no third shape. A component that neither locks nor is declared
first-party is **unsourced**: `duo init` blocks the proposal on it
(`code_component_unsourced`, naming both remedies), `duo code-classify`
refuses the whole run, and a component that somehow reaches Git undeclared
refuses every compile (`code_component_undeclared`). The `--code=full`
"vendor everything" mode DUO-3499 shipped no longer exists, and neither does
the `vendored-archive` lock origin (a ZIP committed inside the repository is
third-party bytes in Git by another name): `duo init` refuses the flag by
name, and a lock naming the retired origin is refused at the reader with the
remedy.

**What the commands do.** `duo init` classifies each active component and
always produces the format-2 declaration for a site with components — even
when nothing locks, because the `first_party` list has to be on disk;
`duo code-classify` (re)declares an initialized repository, format 1 or 2;
`duo code-import` puts an archive you hold into the host's code-artifact cache
so the classifier can lock against it; and compilation gates every component
against the declaration (refusal family 5 above). What has NOT changed: version
checks still read the plugin's own `Version:` header from what is physically on
disk, there is still no composer integration inside the agent or the
orchestrator, and both remain dependency-free PHP.

A component locks only when Duo fetched its published wp.org release (or read
your imported archive), unpacked it, and found it hash-identical to what is
installed. A version that was re-packaged upstream, or a component with one
local patch, is unsourced **with the reason printed** — never silently locked,
never silently vendored. Import the exact archive you have, or declare the
component first-party if it is genuinely yours.

Classification runs on the ORCHESTRATOR HOST and never on the target. That is a
correctness constraint, not a preference: `code_release_provider`'s probe
attests "off-target build and dependency resolution … no target Git history or
registry credentials"
([docs/code-release-runtime.md](../code-release-runtime.md)), and a fetcher
inside the agent would make that attestation false. Importing is the same:
Duo never downloads from a vendor; you move the archive to each host that
resolves, with `duo code-import`, exactly as you would move it to each server
by hand today.

### Runbook: a new site

```sh
duo code-import ~/Downloads/acme-premium-1.4.2.zip        # a premium plugin, once per host
duo code-import ~/Downloads/agency-theme.zip --root=themes # a vendor theme
duo init production --first-party=plugins/acme-site,themes/acme-child
duo init production --offline                              # contact no registry: wp.org components lock only from the host cache
```

The proposal prints the classification for every component — LOCKED,
FIRST-PARTY, or UNSOURCED — with its reason, before you confirm, and the
classification is inside the digest, so a confirmation carrying a different one
is refused rather than silently applied. An UNSOURCED row blocks the proposal
and names both remedies; nothing is written until every component is one of
the other two.

### Runbook: an existing repository

Run it from inside the site repository, like `duo assess` and `duo contract`:

```sh
duo code-classify production --dry-run --first-party=plugins/acme-site   # print the plan, write nothing
duo code-classify production --first-party=plugins/acme-site
git add .gitignore site.duo.json code/duo-code.lock.json
git commit -m 'duo: declare the code half in duo-code.lock.json'
```

It refuses if `code/` has uncommitted changes, if the target's repository
describes different components than your checkout, or if any component is
unsourced. A format-2 repository is re-classified rather than refused — run it
again after importing an archive, to add a declaration, or to upgrade a
`duo-code-lock/v1` lock to v2; the declarations it already holds carry forward.
**The bytes never leave the working tree** — only Git stops tracking the newly
locked ones — so the next compile produces the identical `code_revision` and the
identical `artifact_hash`. No re-pin, no deploy, nothing fleet-visible.

### Resolving a split repository

A fresh clone of a split repository does not contain the locked components'
bytes, so it refuses to compile with `code_component_unresolved` naming each
one. `duo code-resolve` is the step that answers that refusal:

Note what this is *not* needed for: `duo init` leaves the locked
components on disk, so the repository it produces is deployable immediately.
Run `duo code-resolve` there and every component is reported **unchanged** —
nothing is fetched and nothing is rewritten. The split's win at init is the
repository shape (a lock plus `.gitignore` lines instead of third-party bytes in
Git history); the resolver is what makes the *next* clone of that repository
deployable too.


```sh
duo code-resolve production --dry-run   # print what it would fetch, write nothing
duo code-resolve production
```

It reads `code/duo-code.lock.json`, and for every entry:

1. reports the component **unchanged** if it is already on disk at its locked
   `tree_sha256`, and does not rewrite it;
2. **refuses** if it is on disk at any *other* digest
   (`code_resolve_component_drifted`). That tree is `.gitignore`d, so its bytes
   exist in exactly one place — remove the directory and rerun to
   re-materialize the release, or `duo code-classify` to re-lock the bytes you
   actually have. Nothing overwrites a tree Git does not carry;
3. otherwise resolves it: a `wp-org-release` comes from the same
   content-addressed host cache `duo init` uses (`$XDG_CACHE_HOME/duo/
   code-artifacts`, else `~/.cache/duo/code-artifacts`, or `--cache-dir=`), and
   is fetched **only on a cache miss**; an `imported-archive` is read from the
   `imported/` store inside that same cache, where `duo code-import` put it —
   and refuses (`code_resolve_archive_missing`) on a host where it was never
   imported, because Duo never fetches from a vendor. Either way
   `archive_sha256` is verified before anything is unpacked and `tree_sha256`
   after, in a staging directory under `.duo/`, and only a verified tree is
   renamed into `code/wp-content/<root>/<component>/`. A component is never
   half-written.

There is no latest-fallback anywhere and nothing is ever skipped on a miss. The
refusals you can hit, each naming its own remedy:

| reason code | what happened |
| --- | --- |
| `code_resolve_cache_corrupt` | a cached archive no longer hashes to the digest recorded beside it. It is **not** re-fetched: a byte that changed under a digest is evidence. Inspect and delete that cache file by hand, then rerun. |
| `code_resolve_archive_digest_mismatch` | a downloaded archive does not hash to the lock's `archive_sha256`. Only the partial download is deleted and nothing is cached. |
| `code_resolve_archive_missing` | the lock names an `imported-archive` this host's cache does not hold. Obtain the vendor's archive and `duo code-import` it on this host, then rerun; the repository deliberately carries no copy. |
| `code_resolve_tree_digest_mismatch` | the archive digest matched but the unpacked tree does not equal `tree_sha256`. That is an upstream re-package under a reused version; re-lock with `duo code-classify` if it is legitimate. |
| `code_resolve_offline_miss` | `--offline` was passed and the cache has no entry. `--offline` forbids the network and nothing else: a warm cache still resolves. |
| `code_resolve_transport_unsupported` | see the transport rule below. |

`composer install` into `code/wp-content/plugins/` remains equally valid where
you already use it; the compile gate verifies whatever ends up on disk either
way.

### Where resolution can run: local and docker, never ssh

Resolution is **host** work, always. The production target never fetches from a
registry — that is what `code_release_provider`'s probe attests
([docs/code-release-runtime.md](../code-release-runtime.md)) — so the machine
running `duo` must be able to write the repository the compile will hash:

- **`local`** — `repo_path` is a host path by definition. Resolved in place.
- **`docker`** — `repo_path` is the path *inside* the container, so it says
  nothing about where the bytes are on this machine: both sides of a pair
  mount their own repository at the same container path. The host side is
  derived from the environment's own compose service (`docker compose config`,
  the `bind` mount whose target is `repo_path`), so resolution writes **that
  environment's** repository wherever you run the command from. A service
  whose `repo_path` is a named volume, or a read-only mount, exposes no
  writable host directory and refuses with
  `code_resolve_transport_unsupported` rather than guessing.

  Before DUO-3526 the host side was inferred from the working directory
  instead. That answered for whichever repository you happened to be standing
  in, which is the wrong one whenever the command targets another environment
  — a rehearse resolved the *source* repository and reported success while the
  target the compile reads stayed empty.
- **`ssh`** — the repository is on the far side of the network boundary, so
  the host resolves and then **pushes** (DUO-3514). Nothing about the egress
  rule moves: the fetching and both digest checks still happen on the host,
  and the target still never reaches a registry.

  The order, which is the whole safety argument:

  1. The lock is read from the **target**, never from the directory you are
     standing in. There is no host checkout of the site to be wrong about.
  2. The target's own `wp duo code-inventory` says which components it is
     missing. Only those are pushed.
  3. The missing components are resolved into a throwaway staging worktree on
     this host — `archive_sha256` verified before unpacking, `tree_sha256`
     after, exactly as on `local`.
  4. The verified trees travel as **one** tar (scp) and are unpacked into
     `<repo_path>/.duo/code-push/<token>/code/wp-content` — a staging
     directory, not the live tree.
  5. `wp duo code-inventory` is run against that staging directory. Every
     pushed tree must hash to the `tree_sha256` the lock declares **before a
     single byte reaches `code/wp-content`.** A mismatch refuses with
     `code_resolve_tree_digest_mismatch`, removes the staging directory, and
     leaves the target exactly as it was.
  6. Each verified tree is renamed into place, and the target's inventory is
     read once more as the post-condition.

  The refusals: a component the target holds at a **different** digest refuses
  `code_resolve_component_drifted` before anything is fetched or transferred —
  the same "nothing overwrites a tree Git does not carry" rule as on the host,
  and nothing is pushed, not even the absent siblings. A transfer or a
  target-side command that fails refuses `code_resolve_push_failed` with the
  target unchanged. A transport that exposes neither a writable host path nor
  a push mechanism still refuses `code_resolve_transport_unsupported`.

  What the target has to provide: an ssh account that can write its
  `repo_path` and `/tmp`, plus `tar` and `wp`. No Git, no composer, no
  registry egress, and no build toolchain.

### `duo deploy` and `duo promote` resolve for you

Both verbs run the identical resolver as an automatic host-side phase,
`<verb> phase: code-resolve`, immediately **before `compile`** and therefore
before `promotion-begin` — outside every promotion lease, with no checkpoint
taken and nothing to compensate if it refuses. It is completely silent for a
repository that declares no lock (a legacy format-1 repository, or a state-only
one), so such a deploy prints exactly the phase lines it always did.

On **ssh with a lock present**, the phase reads the target's inventory first
and then does the least it can: every component already at its declared
`tree_sha256` is reported `UNCHANGED` and nothing is transferred at all; the
missing ones are resolved on this host and pushed through the six steps above,
with the target-side digest check in front of every rename. A drifted
component refuses before compile, naming it. Because the phase runs before
`compile` and therefore before `promotion-begin`, a refusal here is a deploy
that never started: no lease, no checkpoint, nothing to compensate.

The practical consequence, restated for the split: **resolution still happens
on the host and never on the target.** What changed is that you no longer have
to remember to run it — `duo deploy` does, from the same lock, through the same
cache, with the same two digests verified.

### What the split does NOT change

The resolved tree is still the same size, and `duo deploy` still stages every
descriptor file on every deploy. The split addresses repository size and Git
history, not deploy wall time.

## Worked example: WooCommerce 8.x → 9.x

1. **Branch and bump.** Change the constraint in `code/composer.json`, run
   `composer update` locally, commit the lockfile.
2. **Check the manifest.** If the new major crosses the pinned
   `woocommerce` manifest's `version_range`, update that range in the same pull
   request or land it as a prerequisite. A manifest for Woo 8 must not claim
   Woo 9 — and `Policy::load()` refuses a declared plugin without a
   well-formed range before it contacts any target.
3. **Merge**, so `main` carries both the new lockfile and the manifest that
   matches it.
4. **`duo deploy production`.** The new code materializes, and the fresh-process
   activation in phase 9 forces a full WordPress bootstrap immediately — so
   WooCommerce's own installer notices the version change on *Duo's* schedule,
   not whenever the next stray visitor or cron tick happens to hit the site.
5. **Migrations run inside WooCommerce's own code**, self-triggered, exactly as
   they do after a manual admin-panel update: it compares
   `get_option('woocommerce_version')` against the new code's constant and runs
   its upgrade routines, some synchronously and some through Action Scheduler.
   Duo guarantees the update process *starts* deterministically. It does not
   guarantee instant completion on a large catalog.
6. **`woocommerce_db_version` and `woocommerce_version` update themselves** as
   a side effect. Duo never writes either, and never should: both are
   `class: "env"` in
   `adapter-packages/woocommerce/package/manifest.json`, so they are excluded from
   `state/` entirely. That is what makes "migrations re-run per environment"
   fall out for free rather than needing a shared migration ledger — every
   environment deploys the same git revision, notices its own staleness, and
   self-heals locally.
7. **`duo apply production`** now runs safely, because the schema it writes
   into already matches the code that has been running since step 4.
8. **Every other environment repeats 4–7 independently**, on its own schedule,
   from the same revision. There is no cross-environment coordination to get
   wrong.

The only thing Duo adds to this story is the ordering guarantee. That turns out
to be the thing that was missing.
