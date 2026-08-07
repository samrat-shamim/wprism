# Code-half ecosystem grind

Driver: [`sandbox/tests/grind_code_half_ecosystem.sh`](../../sandbox/tests/grind_code_half_ecosystem.sh).

Run it from the repository root:

```sh
make grind-code-half-ecosystem
```

This is the adversarial extension of the base clean-room grind. It creates one
unique headless pair without `--codebind`, starts with every managed executable
target absent, and uses only the public host `duo promote` workflow. Its exact
pair and repository roots are removed by the exit trap, including partial
startup and failure paths.

## Matrix exercised

- A directory plugin provider plus a `Requires Plugins` dependent.
- A root-level single-file plugin.
- A top-level user MU plugin beside the protected out-of-band Duo agent.
- A parent/child theme whose canonical `template`/`stylesheet` must match the
  child's bounded `Template:` header.
- Replacement of two retiring plugins by a plugin that reuses their PHP symbol.
  The replacement can load only in the fresh activation process.
- A deliberate replacement activation-hook failure, followed by a reviewed
  source fix only after exact pre-promotion code and database-checkpoint
  recovery. A different artifact/owner cannot replace the failed hook session.
- A staged top-level MU fatal that prevents normal WordPress bootstrap,
  followed by a reviewed source removal and a second ordinary public promote.
- An unmanaged sibling component which must survive every stage, failure,
  prune, recovery, and exact-revision restoration.

The lifecycle trace proves dependent-before-provider retirement while both old
roots still exist. The failed replacement hook proves retirement persisted but
activation membership did not. Finalization is absent from that failure path.
The grind verifies the durable pre-hook receipt, restores the exact v1 code tree
and retained checkpoint using the printed owner/artifact recovery sequence, and
only then permits the fixed retry to prune the retired roots.

The MU recovery proof is intentionally narrow. The staged receipt atomically
records which paths Duo observed absent before writing. A retry may remove only
an unchanged, abandoned, staged-only MU file from that list. Completed code,
regular plugins/themes, changed files, and a pre-existing same-path MU file are
not eligible. Control-plane compile/stage/finalize/abort remain reachable while
normal WordPress bootstrap is broken; lifecycle and apply still run under the
ordinary bootstrap they are meant to exercise.

## Harvested gaps closed

1. Lifecycle removal/addition now spans fresh processes, preventing outgoing
   PHP symbols from colliding with replacement code.
2. Activation uses a provider-first topological dependency graph and retirement
   uses its dependent-first reverse. The resulting lifecycle order is
   independent of the authored/native `active_plugins` option order; cycles
   fail closed.
3. Retire→activate state handoff is pending-only, survives an exact crash retry
   without losing its original comparison base, and becomes Apply-visible only
   after activation starts from the exact retirement hash.
4. Code-enabled artifacts require explicit present lifecycle records; ambiguous
   `absent`/`deleted` records cannot authorize prune without WordPress lifecycle.
5. Child-theme parent linkage is descriptor-bound and checked at compile and
   again before stage writes. Exact legacy descriptors remain deletion history,
   but cannot authorize a new child-theme relation they never recorded.
6. A staged descriptor/history/revision/artifact/provenance receipt publishes in
   one database transaction. A no-write/preflight or receipt failure grants no
   future ownership authority.
7. Fatal-safe control commands isolate the protected Duo agent from user
   plugins, themes, and MU code. Checkpoint-import recovery uses the same safe
   bootstrap.
8. Every mutating lifecycle phase publishes a pre-hook boundary inside the
   exact owner/artifact session before calling WordPress. An exception/fatal
   leaves that receipt durable even when no three-way state base exists; a new
   promotion cannot overwrite it, and apply cannot cross it. Exact checkpoint
   recovery removes the ambiguity.

The companion
[`code-half-first-sync.md`](code-half-first-sync.md) isolates item 8 at the
hardest boundary: the target has never applied any state, an activation hook
commits an authored option and then throws, and a reviewed fixed artifact is
refused until code plus database recovery restores the pre-hook world.

At the end, the script restores the exact v1 repository bytes and asserts the
original `code_revision`, state `revision_hash`, and outer `artifact_hash` all
return; the unmanaged sibling remains byte-identical and public status is clean.

An already-completed fatal component which must be removed still needs an
explicit, recoverable quarantine/restore workflow; it is not conflated with the
much narrower staged-only MU cleanup above.
