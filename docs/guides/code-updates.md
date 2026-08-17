# Code updates: the runbook

Updating a plugin or theme is where the two halves of a WordPress site stop
being independent. New code expects a schema; migrations run inside the
plugin's own code on its own trigger; and state written into the wrong schema
is the failure mode nobody notices until a week later. This guide is the
ordering rules, the refusals you will actually hit, and what rollback really
costs.

The commands are documented in
[cli/README.md](../../cli/README.md); the design reasoning is in
[docs/proposals/code-half.md](../proposals/code-half.md).

## The lifecycle order, and why it is that order

`duo deploy <env>` runs these phases, stopping at the first non-zero one:

1. **flag validation** — `duo deploy` accepts exactly `--force-code-mismatch`
   and `--force-code-drift`; anything else, including the orchestrator's own
   internal artifact and lease flags, is refused by name.
2. **rollback-authority fence** — target mutation is refused while the external
   rollback authority is invalid or in a nonterminal state.
3. **artifact directory** — created under the target's operational `.duo/`.
4. **compile** — the repository becomes one immutable, content-addressed
   artifact whose `artifact_hash` binds the state revision and, when present,
   a separate opaque code descriptor and revision.
5. **adapter disposition gate** — an experimental, unsupported, or
   expired-evidence adapter claim refuses here, *before* any lease exists.
6. **promotion-begin** — an exact owner/artifact session on the target.
7. **code-stage** — the new bytes land beside the live tree.
8. **lifecycle retire** — deactivation hooks fire.
9. **lifecycle activate** — in a *fresh process*, so the new code is what
   boots and its own updater notices the version change deterministically.
10. **code-finalize** — requires both lifecycle receipts, so a merely staged
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

## The four refusal families

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
restore the recorded version, or pass `--force-code-drift`.

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

## Version baselines: what actually gets recorded

The `code_versions` baseline is **overwritten, never merged**, on a successful
`duo deploy` and on `duo capture`. It records the installed version of **every
plugin currently active in the environment** — not only the ones target state
names — plus the template and stylesheet slots and their versions. Drift
detection then reads back a narrower slice: it compares only the plugins the
target state declares active, because Duo has no opinion about a plugin it was
never told to manage. Recording wider than you read is deliberate, so a plugin
activated today already has a baseline the next time it matters.

Three consequences follow, and the third is the one teams get wrong:

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

## Vendored versus composer-managed

A site's `code/` tree can hold plugins either way, and the decision is
per-package, not site-wide: a `require` entry in `code/composer.json` means
composer-managed; physical presence under `wp-content/plugins/` with no
`require` entry means vendored wholesale. Public plugins are the natural
composer case (the lockfile diff is small and reviewable); premium or
unregistered plugins are the natural vendored case.

**No Duo command behaves differently between the two today.** There is no
`--vendored` flag, no mode switch, and no composer integration inside the
agent or the orchestrator — both are deliberately dependency-free PHP. Version
checks read the plugin's own `Version:` header from what is physically on
disk, which is correct for both modes and needs no lockfile at all.

The practical consequence: **`composer install` runs before `duo deploy`, never
inside it.** Resolution happens wherever you have git, composer, and registry
access — a developer machine or CI — and only the resolved tree reaches the
target. Duo's contract begins at "these bytes are the code half of this
artifact".

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
