# cli: Recovery

**Purpose.** Rollback authority and the verified/scoped rollback profiles that prove a target can be returned to a known state, plus profile selection, checkpoint inventory/retention, literal claims, and immutable preparation/outcome documents for actor-authorized asynchronous recovery.

**Directory** `cli/src/Recovery/` &middot; **layer** `engine` &middot; **files** 10 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `RollbackAuthority`, `RecoveryProfileSelection`, `RecoveryClaim`, `CheckpointCatalog`, `RetainedCheckpoints`, `CheckpointPrune`, `RecoveryPlan`, `RecoveryOutcome`.

**May depend on:** `Recovery`, `Transport`, `agent:Kernel`.

**Must not depend on.** Command, Environment, Onboarding, Refresh and Adapter.

**What landed** (the charter recorded these as round-3 plans; round 3's T3 shipped them):

- `RecoveryProfileSelection` — which profile a release runs under and why. It decides nothing new: `VerifiedRollbackProfile::select()` already owns the capability decision, and this class only enforces MUP §2.3's rule that `--profile` may *strengthen* silently and never weaken (`cli/src/Recovery/RecoveryProfileSelection.php:11-20`).
- `RecoveryClaim` — `wprism-recovery-claim/v1`, the literal claim. `does_not_restore` is non-empty for **every** profile, `verified-automatic` included, because a target that proved every rollback capability still cannot un-send an email (`cli/src/Recovery/RecoveryClaim.php:13-22`).
- `CheckpointCatalog` — the read-only inventory behind `wprism recover <env> --list`, built only from what `RollbackAuthority::status()`/`::scopedStatus()`/`::audit()` publish. It signs nothing and mutates nothing (`cli/src/Recovery/CheckpointCatalog.php:14-23`).
- `RetainedCheckpoints` — the second source of `--list` rows, and the only one a target with no rollback-authority runtime has: the `promote-<owner>.sql` export every operator-directed promotion writes (`cli/src/Recovery/RetainedCheckpoints.php:13-22`).
- `RecoveryPlan` — the immutable `wprism-recovery-plan/v1` subject emitted by
  read-only `recover prepare`. It binds the signed generation and receipt,
  actual encrypted checkpoint bytes, literal claim, complete scope, topology,
  target head, stable target identity and authority-policy digest. Its
  `currentFacts()`/`reverify()` pair is the controller drift gate; execute then
  gives the same head, target, signed-event chain, receipt, checkpoint, trust and lease facts to the
  target-side locked election as a final compare-and-consume precondition.
- `RecoveryOutcome` — the validated `wprism-recovery-outcome/v2` terminal
  record. It binds the consumed authorization, frozen plan, re-verification,
  ordered step hashes and either verified success or a classified
  fail/reconcile result.

**Known debts.**

- The recovery leak is closed. `RecoverCommand` (`cli/src/Command/RecoverCommand.php`) is the operator verb over this module's profiles and claim, and it explicitly replaces raw invocation of `recovery/rollback-control.php` (`:25`). It is deliberately a thin, literal front end — `RollbackAuthority`, `VerifiedRollbackProfile` and `ScopedRollbackProfile` still own every transition, and it drives exactly the four commands `cli/wprism`'s `print_promotion_recovery()` used to ask a human to type, in the same order (`:26-33`).
- What remains is the reverse pressure: the verb is thin only for as long as nobody adds a fifth step to it. New recovery mechanism belongs here, not in `cli/src/Command/`.
- The actor-authorized execute verb adds no rollback-target state. It composes
  the existing transitions with `TargetOperationStore`: status/exact replay
  first, current signature and plan re-verification, immediate pre-mutation
  tuple election and compare-and-consume, generation-bound resume from exact
  open/completed operation maps, terminal proof, then write-once
  `RecoveryOutcome` completion. A second authorization can neither repair a
  nonterminal tuple nor claim its winner's completed outcome.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Recovery\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
