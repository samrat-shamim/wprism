# agent: Kernel

`ScalarReferenceIntersection` owns the opt-in exact scalar term/TT value
constraint and its authoring grammar; `TermCoordinateWitness` proves the
physical tuple, using read-only observation or the caller's exact authored
transaction and term-before-TT row locks. Taxonomy names remain manifest data.
Neither class loads adapter executables or chooses transaction policy.

**Purpose.** Dependency-free primitives — canonical JSON, database access, reference codecs, durable filesystem, side-effect guards, identifiers, secrets and PII redaction — that everything else is built on.

**Directory** `agent/src/Kernel/` &middot; **layer** `kernel` &middot; **files** 84 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Canon`, `Db`, `OptionState`, `CommandRefusal`, `SiteTopology`, `Secrets`, `PlainData`, `StructuredValue`, `Uuid`, `ReferenceRules`, `DurableFilesystem`, `ReferenceScopeClassifier`, `PathSafety`, `UserMetaState`, `OrderPreserved`, `PersonalData`, `PostPasswordBinding`, `JsonRefs`, `KeyBoundStrings`, `ReferenceKindGrammar`, `ReferenceShapeGrammar`, `TableGraph`, `TableSchema`, `IdentityTokenCodec`, `ProcessFence`, `ReferenceKeyspaceGrammar`, `StructuredReferenceCodec`, `TextTokenizer`, `TransientDbException`, `UrlQueryReferenceCodec`, `MetaRows`, `MediaPayloadAuthority`, `WpCliChildProcess`, `Canary`.

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

**Sub-namespace plan.** Target `WPrism\Kernel\`. Not in this round: the move keeps `namespace WPrism;` flat so that manifest interpreters/providers can keep naming `\WPrism\Policy`, `\WPrism\ProviderSdk`, `\WPrism\Providers` and `\WPrism\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.

`HtmlAttributeReader` and `HtmlMediaReferences` own the bounded, byte-preserving
HTML class grammar. Grammar supplies identity lookup and capture scope handling;
Repository validates canonical classes without WordPress or database access;
Review uses the same recognition for lint. A block name or adapter executable
never grants authority to rewrite arbitrary text containing `wp-image-`.

`KeyBoundStrings` validates literal strings that duplicate a typed map key.
It reuses `JsonRefs` paths and `IdentityTokenCodec`; `PhpContainerValue` owns
atomic key/order/value rebuilding. Capture, immutable reference validation and
apply share this pure boundary, with no plugin, policy or database lookup.

`EncodedText` is the pure scalar framing entry point for negotiated
URI-component persistence. It proves UTF-8, spelling, literal reversibility
and expansion bounds; existing text/reference and privacy owners consume its
decoded strings. `BlockAttributeReader::clearance_value()` exposes native
JSON-escaped attributes alongside original body bytes to those same privacy
owners, without depending on WordPress or a database.
