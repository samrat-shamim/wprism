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

1. **flag validation** — `duo deploy` accepts exactly `--force-code-mismatch`
   and `--force-code-drift`; anything else, including the orchestrator's own
   internal artifact and lease flags, is refused by name.
2. **rollback-authority fence** — target mutation is refused while the external
   rollback authority is invalid or in a nonterminal state.
3. **artifact directory** — created under the target's operational `.duo/`.
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
8. **code-stage** — the new bytes land beside the live tree.
9. **lifecycle retire** — deactivation hooks fire.
10. **lifecycle activate** — in a *fresh process*, so the new code is what
    boots and its own updater notices the version change deterministically.
11. **code-finalize** — requires both lifecycle receipts, so a merely staged
    payload can never be promoted into a completed `code_revision` by skipping
    the WordPress lifecycle.

`duo promote <env>` is this same sequence with a database checkpoint taken
after the lease and before code staging, and a state `apply` appended at the
end. That trailing position is the entire point: apply is hook-free and
canary-armed, and it must write into a schema the running code already
understands. Deploy is the window where hooks fire; apply is the window where
they must not.

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
[Splitting the code half](#splitting-the-code-half-vendored-versus-locked)
below). The lock names components the repository deliberately does not carry in
Git, and compilation refuses when the bytes on disk do not match what it
declares. All three are **blocking and non-forceable**: there is no argument on
any code path that relaxes them, by design — a payload that does not match its
own declaration is not a compatibility judgment.

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

**A locked component's old bytes are no longer guaranteed to be in Git
history.** For a vendored component, `git checkout <old-rev> -- code/` always
works, because the bytes are in the repository. For a component declared in
`code/duo-code.lock.json`, they are not: rolling back to a version that has
aged out of your host cache and off the registry is a rollback you cannot
perform without a copy of the archive. If a component must be restorable
independently of a third party, keep it **vendored** — or keep a
`vendored-archive` copy of its release inside the repository and lock against
that instead. This is an explicit, accepted consequence of the split, not an
oversight.

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

## Splitting the code half: vendored versus locked

A site's `code/` tree holds every component's bytes on disk either way. What
the split decides, per component, is whether **Git carries those bytes**:

- **Vendored** — the bytes are committed. This is the only shape that existed
  before 2026-08-21 and remains the default for anything that cannot be
  byte-verified against a published release: premium plugins, first-party code,
  anything patched locally.
- **Locked** — the component is declared in `code/duo-code.lock.json` with the
  release it came from and the digest its unpacked tree must have, and a
  root-anchored `/code/wp-content/<root>/<component>/` line in the
  repository-root `.gitignore` keeps it out of Git.

**Duo commands do behave differently between the two, since DUO-3499.**
`duo init` classifies each active component and defaults to `--code=split`;
`duo code-classify` migrates an already-initialized repository; and compilation
gates every locked component against its declared digest (refusal family 5
above). What has NOT changed: version checks still read the plugin's own
`Version:` header from what is physically on disk, there is still no composer
integration inside the agent or the orchestrator, and both remain
dependency-free PHP.

A component is locked only when Duo fetched its published wp.org release,
unpacked it, and found it hash-identical to what is installed. A version that
was re-packaged upstream, or a component with one local patch, classifies as
**vendored with the reason printed** — never silently locked. Expect the
practical split to be smaller than a slug list suggests.

Classification runs on the ORCHESTRATOR HOST and never on the target. That is a
correctness constraint, not a preference: `code_release_provider`'s probe
attests "off-target build and dependency resolution … no target Git history or
registry credentials"
([docs/code-release-runtime.md](../code-release-runtime.md)), and a fetcher
inside the agent would make that attestation false.

### Runbook: splitting a new site

```sh
duo init production                 # --code=split is the default
duo init production --code=full     # keep the pre-DUO-3499 fully vendored shape
duo init production --offline       # contact no registry; equivalent to --code=full
```

The proposal prints the classification for every component, with its reason,
before you confirm — and the classification is inside the digest, so a
confirmation carrying a different one is refused rather than silently applied.

### Runbook: splitting an existing repository

Run it from inside the site repository, like `duo assess` and `duo contract`:

```sh
duo code-classify production --dry-run   # print the plan, write nothing
duo code-classify production
git add .gitignore site.duo.json code/duo-code.lock.json
git commit -m 'duo: declare third-party code in duo-code.lock.json'
```

It refuses if `code/` has uncommitted changes, and refuses if the target's
repository describes different components than your checkout. **The bytes never
leave the working tree** — only Git stops tracking them — so the next compile
produces the identical `code_revision` and the identical `artifact_hash`. No
re-pin, no deploy, nothing fleet-visible.

### Resolving a split repository

A fresh clone of a split repository does not contain the locked components'
bytes, so it refuses to compile with `code_component_unresolved` naming each
one. `duo code-resolve` is the step that answers that refusal:

Note what this is *not* needed for: `duo init --code=split` leaves the locked
components on disk, so the repository it produces is deployable immediately.
Run `duo code-resolve` there and every component is reported **unchanged** —
nothing is fetched and nothing is rewritten. The split's win at init is the
repository shape (a lock plus `.gitignore` lines instead of vendored bytes in
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
   is fetched **only on a cache miss**; a `vendored-archive` is read from the
   repository-relative path the lock names. Either way `archive_sha256` is
   verified before anything is unpacked and `tree_sha256` after, in a staging
   directory under `.duo/`, and only a verified tree is renamed into
   `code/wp-content/<root>/<component>/`. A component is never half-written.

There is no latest-fallback anywhere and nothing is ever skipped on a miss. The
refusals you can hit, each naming its own remedy:

| reason code | what happened |
| --- | --- |
| `code_resolve_cache_corrupt` | a cached archive no longer hashes to the digest recorded beside it. It is **not** re-fetched: a byte that changed under a digest is evidence. Inspect and delete that cache file by hand, then rerun. |
| `code_resolve_archive_digest_mismatch` | the archive — downloaded, or vendored in the repository — does not hash to the lock's `archive_sha256`. Only the partial download is deleted and nothing is cached. |
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
- **`ssh`** — the repository is on the far side of the network boundary and
  this host cannot write it. The verb refuses with
  `code_resolve_transport_unsupported`. Host-to-target push is tracked as
  **DUO-3514** and is not implemented; until it lands, materialize the locked
  components on the target itself (the same three verification steps, by hand
  or from your own build) and deploy from there.

### `duo deploy` and `duo promote` resolve for you

Both verbs run the identical resolver as an automatic host-side phase,
`<verb> phase: code-resolve`, immediately **before `compile`** and therefore
before `promotion-begin` — outside every promotion lease, with no checkpoint
taken and nothing to compensate if it refuses. It is completely silent for a
repository that declares no lock, so a fully vendored deploy prints exactly the
phase lines it always did.

On **ssh with a lock present**, deploy cannot resolve, so it verifies instead:
it asks the target for its own `wp duo code-inventory` and proceeds only when
**every** locked component already hashes to its declared `tree_sha256` there.
If any does not, it refuses with `code_resolve_transport_unsupported` before
compile, naming the components that are missing or drifted.

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
   `class: "env"` in `manifests/woocommerce.json`, so they are excluded from
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
