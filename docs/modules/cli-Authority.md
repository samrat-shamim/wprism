# cli: Authority

**Purpose.** External human and policy execution authority shared by release
and recovery: an actor-bound Ed25519 trust root, an explicitly synchronized
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
The same store owns the target-authoritative operation policy. Only the
explicit compare-and-swap sync path creates `authority.lock` or publishes the
canonical policy; status and prepare open an existing regular lock read-only
and refuse absence without creating a byte. Consumption holds a shared policy
lock, validates the complete policy bounds, subject digest, grants, actor,
signature and target-clock lifetime, then elects consumption before releasing
that lock. A concurrent sync/revocation therefore linearizes either before
consumption (and refuses it) or after it; there is no controller-only trust
window.
An atomic per-authorization directory elects exactly one first consumer. Exact
same-operation replay returns the stored record; an incomplete publication is
ambiguous and never retried as a new mutation. A terminal completion is also
write-once and exact-replay idempotent.

**Sub-namespace plan.** Target `WPrism\Orchestrator\Authority\`. Not in this
round; the repository still deliberately keeps one orchestrator namespace.
