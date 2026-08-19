# cli: Contract

**Purpose.** The per-site application contract as one object — manifests, site policy, evidence pins, bindings and capability report composed and addressable — plus `ProjectionVocabulary`, the single implementation of the spec-word projection (state class, handling, readiness, certification provenance, effect containment, effect recovery semantics) that Assess, Release and Rehearse all read.

**Directory** `cli/src/Contract/` &middot; **layer** `policy` &middot; **files** 5 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `ApplicationContract`, `ContractStore`, `ContractProposal`, `ContractProjection`, `ProjectionVocabulary`.

`cli:Assess` reads `ProjectionVocabulary` (the §1 projection) and `ContractProposal` (the `duo-assess-report/v1` schema and its digest rule); `cli:Command` reads all five from `AssessCommand`/`ContractCommand`. Those are the only inbound references, and they are the reason this charter stopped saying *reserved* in the same train that created them.

**May depend on:** `Contract`, `Plan`, `agent:Adapter`, `agent:Kernel`, `agent:Policy`.

**Must not depend on.** Transport, Environment and every engine module. The contract is a document, not a session. That is also why `ProjectionVocabulary` lives here rather than in Assess: policy is the lowest layer all three round-3 engine modules can read, so the projection is implemented once without a single intra-layer edge.

**Known debts.**

- `attestation.state` is only ever written as `unsigned`: the contract-signing gate is deferred (MUP §7), and `ProjectionVocabulary` asserts that rather than documenting it. `Site-certified` no longer rides on it — round-3 T6 §3.2 earned that word, and `projectProvenance()` (`ProjectionVocabulary.php:805`) emits it on the one fact that makes it true, a `certified` claim whose `certification.source` is `site`, annotated on the row as customer-organization approval and explicitly not a Duo endorsement. A shipped, platform-reviewed `certified` claim reads `Platform-certified` (`:822`); everything else reads `Uncertified`.
- `sandboxed` and `compensatable` *are* never emitted: no egress control and no declared compensation action exist to earn them, so the values are not structurally provable (MUP §1.5, §1.6).
- `assess_digest` covers the whole assess report including its `generated_at`. The staleness comparison that matters is over the site's *facts*, so the caller restamps a fresh report with the proposal's timestamp before comparing (`AssessReport::rebind()`) — the choice of which two documents to compare is the assessing command's, not this module's.

**Sub-namespace plan.** Target `Duo\Orchestrator\Contract\`. Not in this round. cli sub-namespaces are cheaper than agent ones (no manifest binds them) but still wait for the agent Kernel migration to prove the classmap round-trip.
