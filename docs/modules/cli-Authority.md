# cli: Authority

**Purpose.** External human and policy execution authority shared by release
and recovery: an actor-bound Ed25519 trust root, explicitly synchronized
target-authoritative policy, exact expiring operation envelopes, stable target
identity, and target-private one-time consumption and completion evidence.

**Directory** `cli/src/Authority/` &middot; **layer** `policy` &middot;
**files** 2 &middot; **status** populated

**Entry points:** `OperationAuthorization`, `TargetOperationStore`.

**May depend on:** `Authority`, `Transport`, `agent:Kernel`.

**Must not depend on.** Release or Recovery. Those modules own the meaning and
validation of their complete prepared subjects; Authority consumes only the
closed projection both operations share: operation and lineage, exact subject
and presentation digests, target identity, authority-policy digest, and the
required grants.

`OperationAuthorization` reads the separately provisioned
`.wprism/authority/authorities.json`. Its signature domain is distinct from
adapter certification, contract attestation, and rollback control. A valid
technical rollback key therefore grants no business authority. The signed
statement binds one actor, operation, operation id, target id, subject digest,
presentation digest, nonce, issuance and expiry.

`TargetOperationStore` keeps identity and consumption below the target's
private Git directory, outside both the worktree and the database. Source
delivery cannot erase consumption and database recovery cannot resurrect it.
Only explicit compare-and-swap sync creates the canonical target policy;
prepare and status read it without creating a byte. Consumption holds that
policy shared-locked, verifies the exact canonical envelope, subject, grants,
signature and target-clock lifetime against it, then takes sorted optional
target precondition locks and the operation lock. A policy sync therefore
linearizes before consumption or after it, never through a controller-only
trust window.
A target-wide lock first elects the exact authorization for the closed
`(target, operation, operation-id, subject, presentation)` tuple, then publishes
its consumption. Exact authorization replay returns the stored record; a fresh
authorization for an elected nonterminal tuple is reconciliation, and a fresh
authorization for a completed tuple is an already-completed mismatch rather
than an alias for the winner's outcome. A partial election is ambiguous and
cannot be repaired by another envelope. The same lock also admits a caller's
target-owned file/head/clock precondition immediately before election. Terminal
completion is write-once under that lock and exact-authorization replay remains
idempotent.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Authority\`. Not in this
round; the repository still deliberately keeps one orchestrator namespace.
