# cli: Recovery

**Purpose.** Rollback authority and the verified/scoped rollback profiles that prove a target can be returned to a known state, plus (round 3) profile selection, the checkpoint catalog and the recovery claim the authorization plan embeds.

**Directory** `cli/src/Recovery/` &middot; **layer** `engine` &middot; **files** 3 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `RollbackAuthority`.

**May depend on:** `Recovery`, `Transport`, `agent:Kernel`.

**Must not depend on.** Command, Environment, Onboarding, Refresh and Adapter.

**Known debts.**

- There is no operator verb for recovery: `recovery/rollback-control.php` is reachable only as a raw entrypoint. Closing that leak is `RecoverCommand`'s job (surface, `cli/src/Command/`), over this module's profiles and claim.

**Sub-namespace plan.** Target `Duo\Orchestrator\Recovery\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
