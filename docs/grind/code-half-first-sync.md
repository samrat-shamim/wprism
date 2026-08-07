# Code-half first-sync hook recovery grind

Driver:
[`sandbox/tests/grind_first_sync_hook_recovery.sh`](../../sandbox/tests/grind_first_sync_hook_recovery.sh).

Run it from the repository root:

```sh
make grind-code-half-first-sync
```

This clean-room regression targets the unsafe edge that ordinary three-way
tests cannot cover: `options/core` has no target base because this is the first
sync. The repository declares an absent plugin and standalone theme. The
plugin's real WordPress activation hook writes the authored `blogname` option,
writes a runtime trace, and then throws before WordPress records the plugin as
active.

The accepted run proves all of the following:

1. `wp duo capture --out=...` can seed canonical repository state without
   minting a target `duo_state` base.
2. Code stage materializes the frozen plugin/theme payload, but failed
   activation runs neither finalize nor apply and advances neither inner
   revision receipt.
3. The authored hook write is genuinely committed despite the exception. Duo
   publishes a `promotion_session.lifecycle_attempt` boundary before entering
   that hook, so the absence of a three-way base cannot turn the mutation into
   implicit adoption. WordPress has not persisted plugin membership, and public
   status is non-zero with an `INCOMPLETE_LIFECYCLE` recovery diagnostic.
4. Replacing the source with a reviewed fix produces a different outer
   artifact and owner. `promotion-begin` refuses it before checkpoint or code
   stage and preserves the original session byte-for-byte.
5. Under external writer exclusion, the operator restores the exact known
   pre-promotion code tree, then runs the host's printed recovery order:
   idempotent old-owner abort, exact old-owner begin, isolated checkpoint
   import, and final abort of the restored lease row. The checkpoint removes
   the authored mutation and ambiguity receipt without inventing a base.
6. The fixed public promotion can then stage, run lifecycle, finalize code,
   apply state, establish the first base, and finish with clean public status.

The same pair also changes `wp-config.php` twice before the failure scenario.
An explicit custom `WPMU_PLUGIN_DIR` and a configured `SUNRISE` are both refused
from the fatal-safe control bootstrap during compile, before a lease,
checkpoint, stage receipt, or target code path exists. This is deliberate
fail-closed scope, not support for those layouts: the current v0 payload and
protected-agent bootstrap require standard `wp-content/mu-plugins`. A future
layout/agent-locator abstraction must preserve the same after-config proof and
no-user-code control boundary before widening that ecosystem surface.
