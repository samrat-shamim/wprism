# agent: Kernel

**Purpose.** Dependency-free primitives — canonical JSON, database access, reference codecs, durable filesystem, identifiers, secrets and PII redaction — that everything else is built on.

**Directory** `agent/src/Kernel/` &middot; **layer** `kernel` &middot; **files** 28 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Canon`, `Db`, `OptionState`, `CommandRefusal`, `Secrets`, `PlainData`, `StructuredValue`, `Uuid`, `ReferenceRules`, `DurableFilesystem`, `ReferenceScopeClassifier`, `PathSafety`, `UserMetaState`, `OrderPreserved`, `PersonalData`, `JsonRefs`, `ReferenceKindGrammar`, `ReferenceShapeGrammar`, `TableGraph`, `TableSchema`, `IdentityTokenCodec`, `ProcessFence`, `ReferenceKeyspaceGrammar`, `StructuredReferenceCodec`, `TextTokenizer`, `TransientDbException`, `UrlQueryReferenceCodec`.

**May depend on:** `Kernel`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Policy` (upward, 1 edge)
  `Canon.php -> Policy.php`
- `Publication` (upward, 2 edges)
  `DurableFilesystem.php -> PublicationJournal.php`; `DurableFilesystem.php -> Publish.php`

**Must not depend on.** Anything. Kernel is the bottom of the ladder; its only two upward edges (Canon->Policy, DurableFilesystem->Publish/PublicationJournal) are ratified debts, not licence.

**Known debts.**

- Canon::encode() reaches into Policy for ordering rules (`Canon.php -> Policy.php`) — the single kernel->policy inversion; it is why Kernel is inside the 15-module agent SCC.
- DurableFilesystem requires Publish/PublicationJournal, dragging an engine module under the kernel.

**Sub-namespace plan.** Target `Duo\Kernel\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those manifest bytes are digest-bound and renaming them is a certification round of its own. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a manifest-bytes change.
