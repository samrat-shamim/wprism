# The irreversibility register

**Generated — never hand-edited.** Written by `tools/wire-surface.php` from the shipped
constants, the shipped closed-key-set call sites, and the shipped refusals themselves;
`make release-gate` runs `php tools/wire-surface.php --check` and byte-compares it. Edit the
code, then run `php tools/wire-surface.php generate`.

A signature is a promise about bytes. The moment one certificate, one attestation or one
rollback receipt leaves this repository, every decision inside the bytes it covers is fixed
for as long as anyone holds it — not until the next agent version, and not until the next
release. This register is the list of those decisions, each with the shipped value, what a
change would cost a party that already holds an artifact, and what the decision deliberately
reserves for a future format.

Nothing here is a second copy of the engine. Every value is read out of it: constants by
reflection (private ones included), closed key sets from the `assertExactKeys()` and
`closedKeys()` call sites that decide them, and grammars, statuses and signature framing by
running the shipped refusals and printing what they answer. §5 lists what that mechanically
proves, and what it does not.

## 1. The cross-root replay table

Every signature this product mints, its domain, and the exact bytes it covers. Read it as
the answer to one question: can a statement minted for one surface be made to verify as a
statement of another?

| surface | domain prefix | signed bytes | source |
|---|---|---|---|
| site adapter certification (`wprism-adapter-certification/v1`) | `wprism-site-adapter-certification-signature/v2\0` | domain &#124;&#124; `Canon::encode(statement)` — the whole six-member statement | `AdapterCertification::SIGNATURE_DOMAIN` |
| authorities envelope (`wprism-adapter-authorities/v2`) | `wprism-adapter-authorities-signature/v1\0` | domain &#124;&#124; `Canon::encode({format, keys})` — the document minus its own signature | `AdapterCertification::SIGNATURE_DOMAIN_AUTHORITIES` |
| `wprism-adapter-authority-delegations/v1` | `wprism-adapter-authority-delegation-signature/v1\0` | domain &#124;&#124; `Canon::encode(statement)` — one delegation statement — the grant, both key identities and the window | `AdapterCertification::SIGNATURE_DOMAIN_DELEGATION` |
| `wprism-adapter-authority-revocations/v1` | `wprism-adapter-authority-revocation-signature/v1\0` | domain &#124;&#124; `Canon::encode(statement)` — the whole revocation statement — every entry at once, so no row can be dropped | `AdapterCertification::SIGNATURE_DOMAIN_REVOCATION` |
| contract attestation (`wprism-contract-attestation/v1`) | `wprism-contract-attestation-signature/v1\0` | domain &#124;&#124; `Canon::encode({attested_digest, format})` | `ContractAttestation::SIGNATURE_DOMAIN` |
| operation authorization (`wprism-operation-authorization/v1`) | `wprism-operation-authorization-signature/v1\0` | domain &#124;&#124; `Canon::encode(statement)` — the whole ten-member actor/operation/target subject | `OperationAuthorization::SIGNATURE_DOMAIN` |
| rollback receipt / event (`wprism-rollback-receipt/v2`, `wprism-rollback-event/v1`) | **none** — proved at generation time | `CanonicalJson::encode(payload)` with nothing prepended | `RollbackControl::sign()` |

Every domain-prefixed surface is separated by construction, and the checker refuses the run if
any one is a prefix of another. Rollback alone is not separated, and R-03 is where that decision
and its cost are written down.

## 2. The register

### R-01 — The adapter-certification signature domain

**Shipped now.** `wprism-site-adapter-certification-signature/v2\0`, prepended to `Canon::encode(statement)`.

**Why it cannot change.** A holder verifies by recomputing these bytes. Changing the string does not invalidate old certificates — it makes them unverifiable by the agent that changed, which is the same outcome as revoking every one of them at once, with no message saying so. The domain is also the only thing standing between this statement and a verifier for another statement type: it is "kept independent from JSON framing so this signature cannot verify elsewhere" (AdapterCertification.php:161).

**Reserved.** The `/vN` suffix is the whole change channel, and WP-4.7 spent it once: `/v2` is a NEW statement type verified alongside `/v1`, never an edit of it. A certificate says which generation it is by the bytes it was signed over — and, because a signature cannot be checked until the domain is chosen, by the statement MEMBER SET this agent reads first (R-24). A third domain works the same way.

### R-02 — The contract-attestation signature domain and its two-member statement

**Shipped now.** `wprism-contract-attestation-signature/v1\0`, prepended to a canonical two-member statement (`wprism-contract-attestation-statement/v1`). The signature binds `attested_digest` — the document minus `contract_digest`, with `attestation.signature` forced to null — never the document bytes.

**Why it cannot change.** The statement is rebuilt from the document at verify time, so a third member would change the bytes for every attestation already signed. The two exclusions are forced and cannot be revisited either: `contract_digest` is the digest of everything else, and a signature is never inside its own input.

**Reserved.** Facts an operator wants signed go in `attestation`, which is already inside `attested_digest`'s input — that is the additive channel, and it is why the statement stays two members forever.

### R-03 — Rollback receipts and events are signed with NO domain separation

**Shipped now.** Proved at generation time: a signature minted by `RollbackControl::sign()` verifies against `CanonicalJson::encode(payload)` with nothing prepended. Three payload kinds share one key and are told apart only by their own `format` member (`wprism-rollback-receipt/v2`, `wprism-rollback-event/v1`, `wprism-scoped-promotion-receipt/v1`) and by the key file at `<root>/public-keys/<key_id>.pub`.

**Why it cannot change.** Prepending a domain later invalidates every receipt and every event already written into a site's recovery root — the artifacts a rollback reads precisely when everything else is broken, and the one class of artifact that cannot be re-minted after the fact. The three payload key sets (below) are what keep the kinds apart, so they carry the whole burden a domain would otherwise share.

**Reserved.** A v3 payload may carry a domain member INSIDE the payload: additive, still canonical, and old signatures keep verifying. The prefix channel is spent.

### R-04 — Every separated domain ends in NUL, and none is a prefix of another

**Shipped now.** Checked at generation time against every projected domain constant.

**Why it cannot change.** The trailing NUL is inside the signed bytes; it is what stops one domain from being a prefix of a longer one and turning a statement of one kind into a valid statement of another. Removing it from either domain is a domain change (R-01).

**Reserved.** Any third domain must keep the terminator and must not be a prefix of an existing one — this checker refuses the register otherwise.

### R-05 — The certificate root and the frozen envelope key sets

**Shipped now.** Certificate root: `{format, signature, statement}`. Frozen envelope: `{certificate_json, certificate_sha256, format}`.

**Why it cannot change.** Both are checked with `assertExactKeys()`, which refuses a MISSING key and an UNKNOWN key with one message. A v1 agent therefore refuses a certificate carrying a field it does not know rather than ignoring it, so no field can ever be added to this envelope for holders of today's agent.

**Reserved.** Extension is by a new `format` value — a new envelope verified beside this one, never a widened one.

### R-06 — The signed statement member set

**Shipped now.** `{adapter, authority, bundle, platform, ratification, version}`.

**Why it cannot change.** Same closure as R-05, and covered by the signature: a seventh member changes the signed bytes AND is refused by every deployed verifier. This is the row that makes every other adapter-certification decision permanent, because none of them can be revisited without adding or moving a member here — which is exactly what WP-4.7 had to do, and why it could only be done in the same change that moved the domain (R-01, R-24). The v1 five-member set (`adapter`, `authority`, `bundle`, `platform`, `ratification`) is still read, for one purpose: it is how a v1-generation statement is RECOGNISED, before any signature, so it can be withdrawn by name instead of refused as corruption.

**Reserved.** Nothing, again, and WP-4.11 kept it that way on purpose: the reserved slots spec/repo-format.md § v3.10 names (`code_digest`, `delegated_authority`) are REFUSALS that name the member and its gate, never admitted members, so this set is the same six and every statement already signed keeps its exact bytes (R-28). Admitting either is still a wire generation. Facts that need signing meanwhile go inside an existing member — `bundle` and `ratification` are whole objects the signature already covers.

### R-07 — The adapter binding, and what a certificate names

**Shipped now.** `{canonical_sha256, name, path, raw_sha256, raw_size, source, trust_tier}`. `path` is always `adapters/<name>.json` and `source` is always `site`.

**Why it cannot change.** The path string is inside the signed statement, so the certificate directory layout (`adapters/`, certificates under `adapters/certifications/`) is signed data, not a local convention: moving the directory orphans every certificate in the field.

**Reserved.** A second bindable source (a plugin-bundled adapter, `plugin`) would be a new `source` VALUE, which the key set already admits — the value vocabulary is the extension point, the key set is not.

### R-08 — The authority binding inside the statement: BOTH roots bind the key identity

**Shipped now.** `{fingerprint, key_id, record, record_sha256, trust_root}`. Both trust roots bind the key IDENTITY — everything but `adapter_names`, `trust_tiers` and (at record v2) `not_before`/`not_after` — self-consistently by digest, and re-check all four LIVE against the current record, revocation with them. The WINDOW left the binding in G2-FIXES M1: a window is a scope, and with it inside the identity a v2 key had NO renewal path — extending `not_after` invalidated every certificate ever signed under that key, and not extending it expired them.

**Why it cannot change.** A trust root is a LIVING registry: it grows every time another adapter is certified under a key, so whole-record binding invalidates every earlier certificate under that key the moment a second one is signed. The site root has bound identity since T6 for that reason; the platform root bound the whole record until WP-4.8, justified by a premise ENROLLMENT FALSIFIES — that the shipped file never grows under an operator's hand. It was changeable only because the platform root has never signed a certificate: `platform/adapter-library/capabilities/adapter-authorities.json` is `{"keys": {}}`, and gate 6 below refuses a populated one that does not verify. Going the other way — widening either root back to whole-record binding — would invalidate every certificate in the field at the next enrollment, silently.

**Reserved.** A future root chooses one of these two bindings at the moment its first certificate is signed, and never after. There is no third choice, because the scope lists are either inside the signature or enforced live, and doing both is the first option. The same one-way rule is why dropping the window was decidable NOW and never again: a NARROWING admits certificates the wider binding refused, so it may only be made while the root has signed nothing — and this one has not.

### R-09 — The authority record key sets stay in decision-specific files

**Shipped now.** Adapter root (`wprism-adapter-authorities/v1`): `{adapter_names, algorithm, public_key, scope, status, trust_tiers}`, and at `wprism-adapter-authorities/v2` the nine of R-18. Contract root (`wprism-contract-attestation-authorities/v1`, at `.wprism/contract/authorities.json`): the four members derived by probe below, scope `contract_attestation`. External operation root (`wprism-operation-authorities/v1`, at `.wprism/authority/authorities.json`): `{actor, algorithm, grants, operations, public_key, status}`.

**Why it cannot change.** The adapter root requires `adapter_names` and `trust_tiers` on EVERY record and validates the whole file the moment it exists, so a contract- or operation-scoped record in that file breaks every adapter certificate in the repository. These roots can never be merged; each record set can never gain a member, because all are closed in both directions.

**Reserved.** Another scope word means another file. That is the shape, and it is already load-bearing.

### R-10 — The authorities envelope, and `keys` as a JSON object

**Shipped now.** Envelope: `{format, keys}` (adapter root), and the same `{format, keys}` shape for the contract root. `keys` is a JSON OBJECT even when it holds one member. A numeric-only key id cannot appear in either file at all, even though the contract grammar accepts one as a string (see the matrix): both roots refuse a non-string map key by name.

**Why it cannot change.** PHP decodes a numeric JSON object-map key as an integer, so a numeric identity would compare unequal to the string the signed document carries — refusing it is what makes the map key and the `key_id` inside the statement the same value. An empty PHP array canonically encodes as `[]`, which both readers refuse, so the object cast at write time is part of the wire, not a nicety.

**Reserved.** A revocation list (R-15) cannot be added to this envelope: its key set is closed. It needs a new `format` value — which is exactly the channel `wprism-adapter-authorities/v2` used to add the envelope signature (R-18).

### R-11 — Four key-id grammars, and they disagree

**Shipped now.** Adapter root: `AdapterSources::assert_name()` — lowercase slugs only, must start and end alphanumeric, at least one lowercase letter. Contract root: `/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D`. Recovery root: `[A-Za-z0-9._-]{1,64}`, which admits a leading separator and carries a length bound the adapter root does not have at all. Operation root: `/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D`. The matrix below is the shipped verdict of each, probe by probe.

**Why it cannot change.** A key id is an object-map key in signed documents, the `key_id` member inside a signed statement, and — in the recovery root — a FILENAME (`public-keys/<key_id>.pub`). Narrowing any of the four orphans installed keys and every artifact they signed; widening one makes an id legal in one root and unreadable in another. The divergence itself is now permanent: unifying them would narrow at least two of the four.

**Reserved.** A future root may narrow at MINT time (refusing to register a key id) without touching what verification accepts. That is the only safe direction.

### R-12 — Adapter names and authority key ids share one grammar

**Shipped now.** `AdapterCertification::keyId()` and `::adapterName()` are both nothing but `AdapterSources::assert_name()`; the matrix below is checked probe-by-probe for agreement and the generator refuses if they ever diverge.

**Why it cannot change.** The adapter name is inside the signed statement twice (as `name` and inside `path`), and it is a filename on disk. One grammar for both is what lets a certificate name a key and an adapter with the same rules; splitting them later would make some existing certificate's `key_id` or `name` illegal.

**Reserved.** Nothing. A wider name grammar is a wider filename grammar.

### R-13 — Trust roots are separated by decision, and the shipped library wins its namespace

**Shipped now.** Adapter certification: `platform` and `site`. Contract attestation: `site` only — a platform-rooted contract attestation refuses by name (`contract_attestation_trust_root_unsupported`). External operation authority: `.wprism/authority/authorities.json` only, with its own actor, operation and grant scope. A site certificate may never claim a key id the shipped file reviews.

**Why it cannot change.** The `trust_root` word is inside the signed statement and is what a host prints beside a certified claim. The shipped-wins rule needs no repository to evaluate, which is what lets frozen verification enforce it; relaxing it would let a site key answer for a reviewed identity in an artifact already frozen.

**Reserved.** Another adapter-certificate root is a new VALUE in a member that already exists — admitted without a schema change, which is why the refusal for an unsupported one is by name. It does not widen the separate contract or operation authority files.

### R-14 — Expiry semantics are signed and specific to each authority surface

**Shipped now.** Contract attestation: `expires_at` is mandatory, grammar `Y-m-d\TH:i:s\Z` (UTC seconds, checked at both ends so "expired" is never a parse accident), compared against `$now ?? time()` and refused at `>=`. Adapter certification: the expiry vocabulary is EXACTLY `not_after`/`not_before`, mandatory on a `wprism-adapter-authorities/v2` authority record and absent from a v1 one, the identical grammar string, compared against the identical `$now ?? time()` and refused at `>=` — all four facts checked by grep, not asserted. Operation authorization: signed `issued_at` and `expires_at` UTC seconds are checked against an explicit verification clock, with the trust policy bounding TTL and future-clock skew; expiry itself has no grace. Recovery: `claim_expires_at` bounds a claimant epoch, never a signature.

**Why it cannot change.** An expired attestation or authority REFUSES; it never silently becomes an unsigned one, because a silent downgrade would make a stale claim indistinguishable from a fresh one at every consumer. There is no skew tolerance in either direction: a wrong operator clock refuses rather than accepts, which is the safe failure and is now the behaviour holders depend on. The adapter root additionally refuses an IMPLAUSIBLE clock — one reading before the record's own `not_before` — BEFORE it tests expiry, because a backwards clock would otherwise find every retired record inside its window; G2-FIXES C1 gave the typed revocation channel the same test against its own `issued_at`, where the failure was OPEN (a backwards clock read an in-force revocation as merely scheduled). WHAT NO CLOCK TEST CAN CLOSE, recorded rather than left to be discovered: a host clock set INSIDE a lapsed window resurrects what that window retired, because both tests read the same wall clock and no clock can witness its own wrongness. Closing it needs a monotonic anchor this product does not have — a signed time beacon, or persisted state the agent refuses to move backwards — and both are new permanent decisions rather than fixes. What IS bounded is the blast radius: since G2-FIXES C3 an expired authority WITHDRAWS the adapters it certified to uncertified instead of refusing the whole site source. Operation authorization never downgrades to an interactive prompt: stale or future-dated authority refuses.

**Reserved.** Expiry on the CERTIFICATE itself, as opposed to the authority that signed it, is still a statement member (R-06) and therefore a new format, not a field. A future skew allowance would have to be a REFUSAL widening, which no deployed verifier would apply to an artifact it already holds.

### R-15 — Revocation is per KEY, never per certificate, on both of its two channels

**Shipped now.** Channel 1, in the authority file: `status` is `trusted` or `revoked`, per key, in a document that ships inside the agent archive. Channel 2, added by WP-4.9 and detailed in R-26: a platform-signed `wprism-adapter-authority-revocations/v1` document installed out of band. Neither has a serial number and neither can revoke ONE certificate — every entry names key material. A revoked site key still verifies inside an already-frozen snapshot through channel 1, because frozen verification reopens no mutable site file (`verifyCertificate()`'s site branch, AdapterCertification.php:1338-1379); channel 2 and a revoked platform key both DO reach a frozen snapshot. WHAT DOES NOT REACH ONE, recorded here because it looks like it should: revoking a DELEGATOR. A frozen snapshot holds no repository, so it reads no `adapters/delegations.json` (`delegatedKeys()` returns `[]` without one) and the delegate's record is the one inside the signature. Burning a vendor's grant therefore reaches every LIVE scan at once and no promoted site: an incident response must name the DELEGATE's own fingerprint to reach those, and the operator guide says so in the same words.

**Why it cannot change.** Revoking a key revokes EVERY artifact it ever signed, retroactively and all at once — there is no way to revoke one certificate, on either channel. Operators sign under per-adapter keys or accept that blast radius; that trade is fixed the moment a second adapter is signed under one key. What WP-4.9 changed is REACHABILITY, not granularity: holders now have a channel that does not wait for an agent release and that a frozen snapshot can read. Going back — removing channel 2 — would silently restore the frozen-path gap for every vendor key already federated by copy.

**Reserved.** Per-certificate revocation still needs an identifier the statement does not carry (R-06), so it remains a v3 statement type rather than an addition. A third channel is not reserved: two are already the maximum an operator can reason about, and R-26 states which one answers where.

### R-16 — One algorithm, no negotiation, and canonical base64

**Shipped now.** Both roots require `algorithm: ed25519` literally; key material is 32 bytes, a signature is 64. Base64 is checked by round-trip equality (`base64_encode(decode(x)) === x`), never merely by decodability.

**Why it cannot change.** The `algorithm` word is inside a signed record, so it is already covered by a signature that only Ed25519 can produce. A v1 agent has no second verifier to negotiate with, so agility could only ever be forward-looking: today's artifacts stay Ed25519 for their whole life. Round-trip base64 exists because a non-canonical encoding of the same key bytes is a second spelling of one identity, and the map key would not match.

**Reserved.** A second algorithm is a second record shape and therefore a new authorities format (R-09/R-10), verified beside this one.

### R-17 — `id_kind` is a flat, unprefixed namespace

**Shipped now.** The engine owns exactly `post`, `term`, `tt`, `user` (ref), `post`, `term`, `tt` (token) and `post`, `term`, `term_taxonomy` (ledger); every other legal value is an `id_kind` a pinned manifest declared for a table it owns. There is no vendor prefix and no reservation mechanism.

**Why it cannot change.** Captured state and `wprism_map` rows embed the BARE kind, so a prefix rule introduced later would have to rewrite every token in every branch of every site — the one migration this product cannot perform, because the branches are the customer's data. Two adapters that pick one name collide with no arbiter.

**Reserved.** A convention (a vendor-shaped name) can be recommended to authors at any time; a RULE cannot be introduced without invalidating existing captures.

### R-18 — The `spec_version` acceptance window is exactly {N-1, N}

**Shipped now.** Measured by handing candidate integers to the shipped `AdapterContractGrammar::validate_adapter_contract()`: this engine accepts `2`, `3` and refuses every other integer wholesale, naming the window. An absent or non-integer `spec_version` keeps the older refusal, because it is not a version and so is not outside anything. A manifest inside the window that declares a section this engine implements only at a HIGHER version refuses naming the section (`attr_id_codecs`, `block_content`, `block_media_derivatives`, `block_values`, `body_refs`, `column_codecs`, `declaration_evidence`, `engine_features`, `incompatible_plugins` today).

**Why it cannot change.** The floor is WPRISM_SPEC_VERSION - 1 and never deeper, checked at generation time. Narrowing the window later refuses every adapter in the field that took it at its word, which is a flag day of exactly the kind the window exists to end; widening it to N-2 costs nothing on the day it is done and converts a staging channel with an expiry into permanent tolerance that no refusal, document or suite would report. So the equality is the gate, not the intention.

**Reserved.** Closing the window is its own dated decision (spec/repo-format.md § v3.12), gated on no v2-declaring pinned manifests in the fleet plus at least one grammar section shipped post-v3 through `engine_features` with no version bump — the replacement proven before the thing it replaces is retired.

### R-19 — Engine feature names are engine-owned, and permanent once declared

**Shipped now.** This engine implements `attr-id-codecs/v1`, `block-attribute-groups/v1`, `block-attribute-values/v1`, `block-content-codecs/v1`, `block-media-derivatives/v1`, `block-record-fields/v1`, `block-value-contracts/v1`, `body-pii-paths/v1`, `body-ref-preserve-type/v1`, `body-url-rebinding/v1`, `conditional-json-refs/v1`, `encoded-text-values/v1`, `invalidate-vocabulary/v1`, `json-column-codecs/v1`, `key-bound-strings/v1`, `manifest-provider-fresh-process/v1`, `manifest-provider-runtime/v1`, `mixed-column-codecs/v1`, `native-value-validation/v1`, `php-container-values/v1`, `plugin-incompatibility/v1`, `post-kind-action-trigger/v1`, `post-meta-invalidation/v1`, `provider-filesystem-file-snapshot/v1`, `provider-native-option-inputs/v1`, `provider-native-permalinks/v1`, `provider-native-post-types/v1`, `provider-physical-table-rows/v1`, `provider-typed-row-mutations/v1`, `scalar-reference-intersection/v1`, `schema-settlement/v1`, `spec-window/v1`, `structured-body-refs/v1`, `structured-evidence/v1`, `table-row-scopes/v1`, `typed-column-codecs/v1`, `typed-column-values/v1`. A manifest declares names through the top-level `engine_features` list; an engine lacking a listed name refuses THAT ADAPTER, naming the feature. An adapter declares a name and never mints one: a name nothing implements is refused as unimplemented rather than admitted as forward-looking.

**Why it cannot change.** A declared feature name is inside the manifest bytes `ArtifactPolicyIdentity::manifest_rows()` folds into that adapter's `digest`, which every `site.wprism.json` content pin and every certificate's `adapter.canonical_sha256` binds. Renaming or re-spelling a feature therefore moves the digest of every manifest that declares it and invalidates their pins and certificates at once — the same irreversibility R-17 records for `id_kind`, reached through a different door.

**Reserved.** The `/vN` suffix is the change channel: a feature whose meaning moves is a NEW name implemented beside the old one, never an edit of it, so a manifest that declared the old name keeps its bytes and its digest.

### R-20 — The v2 authority record, and the four decisions it fixes at once

**Shipped now.** `wprism-adapter-authorities/v2` records are `{adapter_names, algorithm, not_after, not_before, public_key, record_version, scope, status, trust_tiers}`, inside the envelope `{format, keys, signature}` whose `signature` is `{key_id, value}`. A key id must END in the first 12 hex characters of `sha256(public_key)`; an `adapter_names` entry is an exact name or a `<vendor>-*` namespace; the window is judged as R-14 states; and the envelope signature is made by a key the document itself carries. `record_version: 2` restates the envelope format inside every record, and a disagreement between the two refuses. v1 records and v1 documents keep today's behaviour byte for byte — the four rules read only a record that declared `record_version`.

**Why it cannot change.** All four land together because they are one document: a holder who accepts a v2 record accepts all of them, and shipping any one later would be a second flag day for whoever already holds a v2 file. The fingerprint rule cannot be relaxed afterwards without admitting ids that were unrepresentable when the trust decision was made; it cannot be tightened (a longer fingerprint) without orphaning every id already issued. The window is mandatory because `assertExactKeys()` refuses missing and unknown alike, so an optional member has no honest home in the set — a holder wanting no expiry stays at v1, where there is none. An EMPTY v2 registry is unrepresentable by construction: the envelope signature names a key inside the document, so a registry with no keys has nothing that could sign it. That is what lets the shipped empty root stay v1 and byte-identical.

**Reserved.** The envelope signature proves the document was assembled WHOLE by a holder of a key it carries — nobody else can append a key, widen a scope list, move a window or flip a status in it. It is deliberately NOT a chain to an off-document root: delegation is its own signed statement type with its own domain (spec/repo-format.md § v3.8), never a member or an arm inside this one.

### R-21 — The top-level manifest key set is closed from `spec_version: 2`, from one definition

**Shipped now.** Every accepted manifest version may declare 33 top-level keys — the signer's three-arm partition, 5 entity + 14 field + 14 non-surface — plus whatever keys its own declared, IMPLEMENTED `engine_features` values claim (today `attr_id_codecs` via `attr-id-codecs/v1`, `block_content` via `block-content-codecs/v1`, `block_media_derivatives` via `block-media-derivatives/v1`, `block_values` via `block-attribute-values/v1`, `body_refs` via `structured-body-refs/v1`, `column_codecs` via `typed-column-codecs/v1`, `declaration_evidence` via `structured-evidence/v1`, `engine_features` via `spec-window/v1`, `incompatible_plugins` via `plugin-incompatibility/v1`). A key in none of those refuses at load BY NAME, and `_draft` — the sidecar `wprism adapter-draft` writes — is a recognised authoring-only exception at v2 and refuses from v3 with its own remedy, to strip it. Arbitrary keys refuse at both versions. Measured here by asking the shipped validator and the shipped signer for their sets and comparing them in both directions.

**Why it cannot change.** A key REMOVED from the set later refuses every manifest in the field that declared it, and takes its adapter digest with it: the key is inside the manifest bytes `ArtifactPolicyIdentity::manifest_rows()` folds, so the remedy is an edit that moves every `site.wprism.json` content pin and invalidates every certificate over that adapter (R-19 records the same irreversibility for a feature name). Closing the set is therefore a one-way door: it can be opened wider through the growth rule and can never be narrowed. The one definition is load-bearing for the same reason — two lists that agree today diverge silently, and the symptom is an adapter that loads everywhere and cannot be certified.

**Reserved.** Growth is § v3.2's channel and nothing else: a post-v3 primitive ships as an engine feature name, the top-level keys that feature claims, and a refusal for the engine that lacks it — so no key is ever added by widening this set for everyone. What a feature-claimed key is SIGNABLE as is no longer reserved and no longer this row's business: since WP-6.6 the arm rides in the feature's own roster row and R-31 records it. This row reserves the base partition, which the roster may never name a key in.

### R-22 — The shipped library wins the adapter-NAME namespace, against a pattern

**Shipped now.** A non-platform authority record's `adapter_names` entry may not be a `<vendor>-*` namespace that COVERS one of the 27 names the shipped library reserves (R-27's list). Judged at authority-record validation time, so it fires on both roots' registration and verification paths — the site trust root, a delegated grant, and the record embedded in a certificate the frozen path re-validates. The refusal names the covered member. The platform root is exempt, and an EXACT reserved name stays legal everywhere: out of tree a shipped name is reachable only as the reviewed `{name, source:"site"}` override (T6 §3.3), which `wprism adapter certify` records by exact name.

**Why it cannot change.** Without it, enrolling a vendor with the namespace its own products live in silently handed that vendor the SHIPPED adapter of the same name — 20 of the 27 sit inside a legal one (`ninja-*`, `yoast-*`, `wprism-*`, `code-*` …) — and an out-of-tree adapter answering a shipped name is the override, which INHERITS that adapter's interpreter, regenerator and provider declarations. That is executable privilege reached through a name nobody meant to grant. The window closes at the first vendor key: narrowing a namespace after one is issued orphans whatever was certified under it, so this is decidable only while the platform root is empty.

**Reserved.** Nothing for the EXACT form, deliberately. A record that names a reserved adapter exactly is a decision someone wrote down — the operator overriding their own site, or a platform grant that names the adapter on purpose — and refusing it would delete a shipped capability to close a hole the pattern form is the whole of. What is not reserved either is a way to make the list itself grow at a site: R-27 keeps it a closed enumeration in agent code.

### R-25 — Delegation is depth-1, and the bound is in the verifier rather than in a policy

**Shipped now.** A `wprism-adapter-authority-delegations/v1` document at `adapters/delegations.json` holds a map of `{signature, statement}` objects. The signed statement is `{adapter_names, delegate, delegator, format, not_after, not_before, trust_tiers, version}`, with `delegate` `{algorithm, key_id, public_key}` and `delegator` `{fingerprint, key_id, trust_root}`, under domain `wprism-adapter-authority-delegation-signature/v1\0`. Verification chains exactly 1 level: a delegator that is itself a delegate is refused BY NAME before it is looked up. A delegator is resolved ONLY in `wprism-adapter-authorities/v1`'s shipped file, so a site key cannot delegate; the grant may only narrow the delegator's `adapter_names`, `trust_tiers` and window; and a delegated key resolves under trust root `site`, not a third word.

**Why it cannot change.** The depth bound is inside the VERIFIER, not a configurable maximum, and that is what makes it a property rather than a setting: every holder of this agent enforces it identically and no document can ask for more. Raising it later would admit paths that were unrepresentable when the trust decision was made — a delegate that could not delegate yesterday could hand on a grant tomorrow, retroactively, with nothing in the field re-reviewed. Lowering it to zero orphans every delegation already issued. The narrow-only rule is the same shape: it is a grammar restriction, so a widening grant is unrepresentable rather than merely refused by review, and relaxing it would silently widen every grant in the field at the next verification.

**Reserved.** Nothing about depth. A vendor that must hand on authority enrolls its sub-vendor with the platform root directly, which is one review rather than an unbounded path. The extension channel for the statement itself is `version`, inside the signature: a grammar this engine does not implement is refused BY VERSION rather than read as corruption.

### R-26 — Typed revocation, and the one channel that reaches a frozen snapshot

**Shipped now.** A `wprism-adapter-authority-revocations/v1` document, envelope `{format, signature, statement}`, statement `{format, issued_at, revocations, version}`, each entry `{effective_at, fingerprint, key_id, reason}`, under domain `wprism-adapter-authority-revocation-signature/v1\0`. It is installed at `WPMU_PLUGIN_DIR/wprism-control/adapter-revocations.json`, outside the replaceable agent and its embedded adapter library, is signed by a key the shipped platform root carries, and ships ABSENT. An entry binds `fingerprint` = `sha256(public_key)`, never the key id. The signer's own window is deliberately not applied; its `issued_at` IS, as the anchor that stops a backwards clock reading an in-force revocation as merely scheduled (R-14, G2-FIXES C1). THREE STATES, not two (G2-FIXES C2): absent means nothing is revoked; a document signed by an ENROLLED key applies; and a document whose signer this root does not carry is INERT — reported as a library-scoped row by `AdapterSources::survey()`, entries not applied, site not refused. That third state is what makes the channel installable at all: the shipped root is `{"keys":{}}`, so before enrollment a hard refusal was the ONLY outcome a correctly-signed revocation could produce. Tampering is unchanged and still fatal. What the channel takes away is the certified claim of the adapters that key signed, not the site: a revoked authority is the third typed withdrawal (C3), on the live path and inside a frozen snapshot alike.

**Why it cannot change.** The FINGERPRINT binding cannot be exchanged for an id binding afterwards: an id can be re-minted over new key material, so an id-bound revocation would be escapable by rotating a name. Absence meaning "nothing is revoked" is equally fixed — every deployed agent already reads it that way, so a future "absent means refuse" would brick every site that never installed one. And the reachability itself is one-way: this is the only channel that reaches an already-frozen snapshot for a site-rooted key, so removing it restores a gap for every vendor key already federated by copy, silently. Its LOCATION is equally permanent: the operator-owned `wprism-control/` sibling survives replacement of `wprism/`, so absence cannot be manufactured by an adoption. The flat-library cutover retires an old `manifests/capabilities/adapter-revocations.json` only after a byte-identical durable copy already exists; otherwise bootstrap and adoption refuse before moving either library.

**Reserved.** The signer's window is unapplied ON PURPOSE and that is not an oversight to fix later: applying it would let a lapsed window RESURRECT the exact identities this document exists to burn. A future per-certificate revocation still needs R-06's missing identifier. What this document deliberately does NOT do is revoke the operator's own self-minted site key through a channel the operator does not control — that asymmetry is preserved, and every refusal it raises says so in its own sentence.

### R-27 — The reserved `<vendor>-` form, and the closed grandfather list under it

**Shipped now.** At `spec_version 3` an out-of-tree adapter name is `<vendor>-<name>` and every `providers[].id` it declares sits in that same vendor namespace (`IdentityNamespaces::assert_out_of_tree_identity()`, reached from `AdapterSources::assert_out_of_tree_contract()`, the one boundary all four out-of-tree entry points share). The rule returns before reading a member below that version, so this engine, at `WPRISM_SPEC_VERSION 3`, enforces it on every out-of-tree adapter. The unprefixed space is reserved to the shipped library as a CLOSED ENUMERATION of 27 adapter names (`acf`, `advanced-editor-tools`, `block-visibility`, `change-wp-admin-login`, `classic-editor`, `code-snippets`, `contact-form-7`, `core`, `download-manager`, `elementor`, `map-block-gutenberg`, `ninja-forms`, `paid-memberships-pro`, `polylang`, `qi-blocks`, `rank-math`, `redirection`, `speculation-rules`, `the-events-calendar`, `visual-portfolio`, `woocommerce`, `wordpress-popup`, `wpforms-lite`, `wprism-agency-cpt`, `wps-hide-login`, `yoast`, `yoast-duplicate-post`) and 22 `id_kind`s (`attr_taxonomy`, `code_snippet`, `hustle_module`, `nf3_action`, `nf3_field`, `nf3_form`, `pmpro_category_restrict`, `pmpro_discount`, `pmpro_discount_level`, `pmpro_group`, `pmpro_level`, `pmpro_level_group`, `pmpro_restrict`, `rank_math_redirection`, `red_group`, `red_item`, `wc_tax_class`, `wc_tax_loc`, `wc_tax_rate`, `wc_zone`, `wc_zone_loc`, `wc_zone_method`), living in `agent/src` and never inside an adapter package. Gate 9 below asserts both halves. Ownership of a namespace is the authority record's, not this list's: `adapter_names: ["<vendor>-*"]` (R-20) is what decides which names a key may certify — except that no non-platform grant may reach a name on this list through a pattern (R-22). The grandfather exemption is the NAME's alone: a grandfathered name that has a vendor half is still held to the provider-id rule (G2-FIXES M2), and one with no vendor half — `acf`, `core`, `elementor`, `polylang`, `redirection`, `woocommerce`, `yoast` — has no namespace for a provider id to be bound to, so that rule has nothing to say about it.

**Why it cannot change.** The separator forecloses every other scheme: `<vendor>-<name>` cannot later become `<vendor>/<name>` or `<vendor>.<name>` without re-spelling every out-of-tree identity already authored, and an adapter name is inside the manifest bytes `ArtifactPolicyIdentity::manifest_rows()` folds into that adapter's digest — so a re-spelling invalidates every pin and certificate that named it (the same door R-19 reaches). The ENUMERATION cannot be replaced by a shape test afterwards either: 20 of the 27 shipped names are hyphen-shaped without being vendor-prefixed (`the-events-calendar` is not vendor `the`), so a shape test admits precisely the rows a reviewer would want to see. And the list can never simply grow: each addition hands one more unprefixed identity to the shipped library permanently, which is why the release gate refuses any membership but equality with the library itself.

**Reserved.** ONE HYPHEN DEEP, and no deeper — stated because the binding reads stronger than it is (G2-FIXES M3). `IdentityNamespaces::vendor()` splits on the FIRST hyphen, so a sub-vendor delegated `acme-forms-*` may name its adapter `acme-forms-widget`, whose vendor half is `acme`, and mint provider ids across the PARENT's whole `acme-` space rather than inside the scope its own certificate was checked against. A provider id is bound to the first segment of the declaring adapter's name — the top of the namespace its scope lies within — and not to the narrowest scope entry that certified it. Binding it to the matched scope entry means carrying a certificate into a loader that runs with no certificate in hand, which is a new permanent decision rather than a correction. This row deliberately reserves NOTHING for `tables.<t>.id_kind`. R-17 rules the prefix RULE out permanently — captured state and `wprism_map` rows embed the bare kind — so the 22 shipped kinds are recorded here as a permanent floor and a CONVENTION for authors, never as a break list. A future scheme for that space is a new `id_kind`-carrying wire, not an edit of this one.

### R-23 — What a certificate binds about the platform: exercised cells, not the boundary document

**Shipped now.** `statement.platform` is `{agent_version, axes, site_mode, spec_version}`, where each member of `axes` is `{cells, sha256}`. Bound: `spec_version`, `site_mode`, and per compatibility axis the exercised CELL names plus a digest of what each cell admits — series names for a `verified` map, the min/max line for each `engines` entry, the whole profile object minus its `note` for an axis with neither. Recorded and NOT bound: `agent_version`. Outside the member entirely: `branchable_state`, `plugin_execution`, every `note`, every `min`/`max`, and `wordpress`'s derived `last_verified`.

**Why it cannot change.** The v1 statement bound `Canon::encode()` of the WHOLE platform record, so every agent release withdrew every certificate in the field — `platform/adapter-library/capabilities/platform.json` restates both `define()`s (AGENTS.md rule 8), so a patch release that moved no axis anyone exercised still moved those bytes. Undoing this — widening back to the whole record, or binding `min`/`max` — restores that behaviour silently, because it is not a refusal anyone sees until the next release. Narrowing further is equally one-way: a cell dropped from the binding stops being a thing a certificate can be shown to have covered, and no artifact already signed records what it would have said.

**Reserved.** A SIXTH compatibility axis needs nothing here: an axis the boundary GAINS is coverage no existing certificate claimed, and gaining one refuses nothing. Binding a fact this member does not carry is the other direction and needs a new statement generation (R-24), because `assertExactKeys()` closes this member in both directions too.

### R-24 — The statement generation: `version` inside the signature, and how a generation is recognised without one

**Shipped now.** Statements carry `version: 2`, signed under `wprism-site-adapter-certification-signature/v2\0`. A statement whose member set is exactly the v1 five (`adapter`, `authority`, `bundle`, `platform`, `ratification`) is recognised as the previous generation and WITHDRAWN by name through `SupersededWireSiteAdapterCertificate`; a `version` this engine does not implement is withdrawn the same way, by VERSION. Both tests run behind the closed root key set, the canonical base64 Ed25519-length signature check and the statement's own member-shape proofs, and both degrade ONE adapter — on the live scan and inside a frozen snapshot alike — never the whole source.

**Why it cannot change.** The generation must be decidable BEFORE a signature, because the signature domain is what the generation names (R-01): a verifier that needed the signature first could only ever guess. That forces the discriminator to be the member set, which is why `version` could not be added additively and had to arrive in the same change that moved the domain (R-06). What `version` buys is that the NEXT such change is a version question instead: a grammar this engine does not implement refuses by number rather than reading as corruption, and a tamperer cannot downgrade a statement by DELETING bytes without hitting a named refusal. Removing it later would spend that property for every holder at once.

**Reserved.** Nothing about this signal is authenticated, and that is accepted rather than argued away: anyone who can write the companion file can delete `version` and reach `uncertified`, which is strictly weaker than the certificate and is the same state deleting the file reaches. A future generation may make the discriminator cheaper — a generation member OUTSIDE the statement, beside `format` — but it cannot make it authenticated, for the same reason the first sentence gives.

### R-28 — The reserved slots are REFUSALS, never admitted members

**Shipped now.** THREE attachment points refuse by name, each naming the gate that would open it (spec/repo-format.md § v3.10): the manifest key `package` inside the closed key set (§ v3.3, so at `spec_version 2` and therefore live at `WPRISM_SPEC_VERSION 3`); and the statement members `code_digest`, `delegated_authority`. Neither is in any closed set: the statement is still six members (R-06). TWO MORE WERE OPENED at gate G4 by WP-5.2 (§ v3.16), which is what this row was written to make possible: the bundle evidence member `reviewer` is now ADMITTED as an optional member beside `{exercised, grammar, reason}`, and the certification word `reviewer_signed` is MINTED. Run, not restated — a bundle naming a reviewing party is accepted, while one naming its own signer answers: "wprism: site certification bundle manifest.evidence names reviewer 'acme-ops', which is the signing authority itself — the reviewer tier names a party OTHER than the key that vouches for it, or it says nothing the trust root did not already say". A bundle declaring no reviewer produces the identical disposition it produced before that rider, which is why opening the slot moved no certificate.

**Why it cannot change.** A reservation on a SIGNED surface can only be a refusal, and that is a property of signatures rather than a style choice: the statement member set is closed in both directions AND is the generation discriminator a verifier reads before it has a domain to check a signature with (R-06, R-24), and the evidence object sits inside the bundle digest the statement binds. Admitting either member "for later" would therefore change the bytes every holder recomputes on the day it was admitted, for a capability that does not exist yet — the flag day this program exists to avoid, paid early and for nothing. What cannot be undone is the OPPOSITE direction: once one of these words is minted by a shipped engine, every deployed verifier that refuses it is refusing a live document, so the refusal has to exist in the field BEFORE the policy that mints it — which is why these ride v3 rather than the change that opens them. WP-5.2 spent that credit exactly as described: the reviewer word's refusal shipped one release ahead of the first engine able to mint it, so a host that is behind answers with a version fact instead of `invalid enum`. That ordering is the part that cannot be redone later.

**Reserved.** What is deliberately NOT reserved: any SCHEMA for what eventually rides on these points. A reservation that guessed the shape would have to be right about a design nobody has reviewed; § v3.2's `engine_features` channel carries the detail later, so a slot need only be right about WHERE an extension attaches. Also not reserved, and recorded so it is not re-taken: the graduated `outside_version_range` verdict, which is a SHIPPED word (`version_range_graduated`, WP-2.8) and not a slot at all. Opening a slot is a policy flip proven by `sandbox/tests/offline/adapter/regress_v3_reservations.php`, which pins each surviving sentence, measures each opened one as opened, and holds the statement's exact canonical bytes and signature — gate G5's condition 7 (spec/repo-format.md § v3.11). WP-5.2 has now done it once, for the two reviewer-tier slots (`sandbox/tests/offline/adapter/regress_reviewer_evidence_tier.php`), so the mechanism is a worked example rather than an intention.

### R-29 — Structured declaration evidence: closed rows, and a target that must address a declaration

**Shipped now.** A manifest declaring the engine feature `structured-evidence/v1` may carry the top-level `declaration_evidence` section: an object keyed by TARGET, each record `{evidence}` or `{evidence, answered}`, each evidence row exactly `{locator, observation, source}` and each answered row exactly `{answer, question}`, every member a non-empty string (spec/repo-format.md § v3.13). A target's HEAD must be a top-level key the same manifest declares, which is the whole difference between this and a note that mentions a section: a record for a deleted section refuses at load. Redirection is the first shipped declarer; adding it moved only that new adapter's own digest, while the pre-existing 16 stayed byte-identical. Rank Math later adopted the section in its first identity, without restamping an existing adapter (AGENTS.md rule 2). `notes` keeps everything it carries and this remains a sibling, never a migration.

**Why it cannot change.** The section name and every row member are inside the manifest bytes `ArtifactPolicyIdentity::manifest_rows()` folds into the adapter `digest`, reached through the same door R-19 records for the feature name that admits them: renaming a member moves the digest of every manifest carrying one and invalidates their pins and certificates at once. The rows are closed in BOTH directions for the reason R-21 gives one level up — a member no checker reads is indistinguishable from a deliberate one, and a section whose whole purpose is machine-checkable rationale cannot admit one.

**Reserved.** An arm in `AdapterCertification::topLevelKeyPartition()` is STILL deliberately not taken, and WP-6.6 is why that reservation now costs nothing. This row used to record the consequence as accepted — "the signer refuses this section by name, an adapter that adopts it loads everywhere and is not certifiable" — which was measured on a real adapter and found to be a wall rather than a posture. The arm rides in the FEATURE's roster row instead (R-31): `declaration_evidence` classifies as `non_surface`, because provenance rows are not state and a certificate's surface list may not carry evidence prose. Taking a partition arm would still be the wrong fix — it would admit the key with NO feature declared, deleting the no-bump demonstration the section exists to be. Also reserved: resolving a target's TAIL against the addressed section's own sub-grammar — fourteen field sections have fourteen of those, and a resolver here would be a second, drifting copy of all of them.

### R-30 — The adapter index is UNSIGNED by design, and its entry grammar is closed

**Shipped now.** `wprism-adapter-index/v1` is `{adapters, format}`, where `adapters` maps an adapter NAME to a non-empty list of entries, each exactly `{adapter_sha256, agent_versions, authority_fingerprint, certificate_sha256, certificate_url, url, version}` and each `agent_versions` exactly `{max, min}` (spec/repo-format.md § v3.19). There is no signature member and no signing domain: an index is a POINTER document. Every trust decision is re-derived at install from the FETCHED bytes by `AdapterCertification::verifyFile()` — the same call the live policy path makes — against the trust root the installing repository already holds, so the complete blast radius of a tampered index is DENIAL: a moved digest, a moved URL, a moved fingerprint and a deleted entry each refuse or withhold, and none of them can put an unverified byte on disk. Exactly one transport ships (`file://`); an `https://` entry is discoverable and refuses at install by name.

**Why it cannot change.** The closed entry key set is the part that cannot move quietly. It is closed in BOTH directions for R-21's reason one level up — a member no checker reads is indistinguishable from a deliberate one — and the absent-member direction is the one that matters here: deleting `certificate_sha256` and `certificate_url` is precisely how a tamperer would express "this package is unsigned", so an entry missing them must be refused by the DOCUMENT grammar rather than discovered at verify time. Adding a member later would make every index already published unreadable by the agent that added it, which is why `/v1` is the whole change channel, exactly as R-01 records for the certification domain. The digests are full sha256 in one spelling for the same reason a certificate binds bytes rather than a path: a prefix, or a second accepted case, would let two different packages resolve under one pin.

**Reserved.** A SIGNATURE over the index is deliberately not reserved, and this is the decision the row exists to hold. Signing it would create a second trust root — with its own custody, enrollment and revocation story — in front of a decision already taken by a root that has all three, and it would buy nothing the digests do not already buy: an index signature can only assert which packages EXIST, which is a denial-of-service surface, never an authorization one. Reversing this needs a new `format` value read beside v1, a reviewed answer to whose key signs it and how it is revoked, and a reason the answer is not simply "the certificate the entry already points at". Also not reserved: an ORDER over `version`, which is an opaque publisher label this format never parses (§ v3.19 — the two verbs refuse an ambiguity rather than rank it), and an HTTPS transport, whose absence is a stated boundary with its own refusal rather than a gap to be filled in.

### R-31 — A feature-claimed key carries its certificate ARM in the feature's own roster row

**Shipped now.** Every top-level key an implemented `engine_features` value claims is classified into exactly one of `entity`, `field`, `non_surface` by the row that claims it (`AdapterContractGrammar::IMPLEMENTED_FEATURES`, spec/repo-format.md § v3.21) — today `attr_id_codecs` -> `field`, `block_content` -> `field`, `block_media_derivatives` -> `field`, `block_values` -> `field`, `body_refs` -> `field`, `column_codecs` -> `field`, `declaration_evidence` -> `non_surface`, `engine_features` -> `non_surface`, `incompatible_plugins` -> `non_surface`. `entity` and `field` become the two section lists a disposition names, and therefore members of the signed claim's `surfaces`; `non_surface` covers no state. `AdapterCertification::siteSurfaceSections()` asks the three-arm partition first and this roster second, for the keys the DECLARING manifest brought through the channel, so a section present without its feature keeps the unclassifiable refusal. Both signing profiles read the one method, so `--ratification-file` cannot certify a section the derivation cannot. Measured here by asking the shipped roster and the shipped signer, not by listing.

**Why it cannot change.** An arm is inside the certificate. `claim_from_disposition()` turns the entity and field lists into the claim's `surfaces`, and that claim is inside the signed statement — so MOVING a key between arms, or from an arm to none, changes the surfaces every holder of that certificate already verified, and re-signing is the only remedy. `body_refs` and `attr_id_codecs` as `field`, `declaration_evidence` and `engine_features` as `non_surface`, are therefore as permanent as the section names themselves (R-29 records that door for the names, R-19 for the feature that admits them). The MAP is the other permanent half: `keys` is a key => arm map rather than a list, so a feature cannot claim a key without classifying it, and the failure this row exists to close — a section that loads on every site and is unsignable by every profile — is unrepresentable rather than remembered.

**Reserved.** A FOURTH arm is refused, not reserved: a disposition entry has exactly two section lists (`entity_sections`, `field_sections`) and `non_surface` is the honest name for "in neither", so a fourth would need a disposition member that does not exist and a claim projection that does not read one. Also refused rather than reserved: a roster row naming a key the signer's own partition already carries — that is two spellings of one arm and `feature_key_arms()` throws on it. What IS reserved is the per-manifest scope: the arm is read for the keys a manifest's own declared, implemented features claim, never engine-wide, because a certificate may not claim coverage of a section this engine reads nothing from.

### R-32 — External mutation authority binds one actor, operation, target and immutable subject

**Shipped now.** `wprism-operation-authorization/v1` signs `{actor, expires_at, issued_at, key_id, nonce, operation, operation_id, presentation_digest, subject_digest, target_id}` under `wprism-operation-authorization-signature/v1\0`. Its envelope is `{format, signature, statement}`; its separate `wprism-operation-authorities/v1` policy is `{format, keys, max_clock_skew_seconds, max_ttl_seconds}`. The signed statement binds the presentation digest as well as the machine subject digest, so an actor authorizes the exact plan they saw. Only `recovery`, `release` are admitted.

**Why it cannot change.** Changing any statement member changes the bytes a holder signed. Dropping actor, operation id, target id, nonce, subject digest, presentation digest, issuance or expiry would turn an authorization for one human-visible mutation into authority for another. The trust file is deliberately disjoint from adapter, contract and rollback keys, so reusing one of those keys cannot silently grant external mutation authority.

**Reserved.** A new statement shape or signature framing uses a new `/vN` format and domain, verified beside v1. New operation kinds require both a validator release and an explicit enrolled operation scope; an unknown value is refused. One-time consumption and terminal outcome records are target state outside the signature, so their evolution cannot broaden what these signed bytes authorize.

## 3. The grammars, as the shipped validators answer them

### 3.1 Key ids, four roots

Each cell is the verdict of the named validator on that exact probe, obtained by calling it.

| probe | adapter root | contract root | operation root | recovery root |
|---|---|---|---|---|
| `wpforms` | accepted | accepted | accepted | accepted |
| `acme.key_1` | accepted | accepted | accepted | accepted |
| `Acme-Key` | refused | accepted | accepted | accepted |
| `2026` | refused | accepted | accepted | accepted |
| `a` | accepted | accepted | accepted | accepted |
| `-leading` | refused | refused | refused | accepted |
| `trailing-` | refused | accepted | accepted | accepted |
| `kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk` | accepted | refused | refused | refused |
| `has space` | refused | refused | refused | refused |
| (empty) | refused | refused | refused | refused |

### 3.2 The contract authority record, derived from its own refusals

`ContractAttestation::validateRecord()` keeps its vocabulary in a local variable, so the
closed set is derived the only honest way: by asking it.

| record | verdict | refusal |
|---|---|---|
| the four members | accepted | — |
| plus `expires_at` | refused | contract attestation key 'k1' carries the unrecognised field 'expires_at' |
| minus `algorithm` | refused | contract attestation key 'k1' must declare algorithm ed25519 and scope contract_attestation |
| minus `public_key` | refused | contract attestation key 'k1' public_key is not a base64 Ed25519 public key |
| minus `scope` | refused | contract attestation key 'k1' must declare algorithm ed25519 and scope contract_attestation |
| minus `status` | refused | contract attestation key 'k1' status must be trusted or revoked |
| `status: suspended` | refused | contract attestation key 'k1' status must be trusted or revoked |

## 4. Every closed key set the signing runtimes decide

Projected from the call sites that decide them: the four signing files, plus the contract
attestation object itself. Not every row is inside a signature — the recovery runtime's
request envelope and target record are store shapes — but every one of them refuses a
MISSING key and an UNKNOWN key with the same failure, so each row is a set that cannot gain a
member for anyone holding today's agent. A row appearing or moving here is the alarm: a wire
surface changed, and the byte-compare in `make release-gate` will not pass until someone
regenerates this document and, in doing so, reads the change.

| file | validator | document | keys |
|---|---|---|---|
| `agent/src/Adapter/AdapterCertification.php` | `verifyFrozen()` | `frozen certification envelope` | `certificate_json`, `certificate_sha256`, `format` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyCertificate()` | `certification authority binding` | `fingerprint`, `key_id`, `record`, `record_sha256`, `trust_root` |
| `agent/src/Adapter/AdapterCertification.php` | `delegatedKeys()` | `$label` (`DELEGATIONS_ENVELOPE_KEYS`) | `delegations`, `format` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyDelegation()` | `$label` (`DELEGATION_KEYS`) | `signature`, `statement` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyDelegation()` | `$label statement` (`DELEGATION_STATEMENT_KEYS`) | `adapter_names`, `delegate`, `delegator`, `format`, `not_after`, `not_before`, `trust_tiers`, `version` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyDelegation()` | `$label delegate` (`DELEGATION_DELEGATE_KEYS`) | `algorithm`, `key_id`, `public_key` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyDelegation()` | `$label delegator` (`DELEGATION_DELEGATOR_KEYS`) | `fingerprint`, `key_id`, `trust_root` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyDelegation()` | `$label signature` (`DELEGATION_SIGNATURE_KEYS`) | `key_id`, `value` |
| `agent/src/Adapter/AdapterCertification.php` | `revocations()` | `$label` (`REVOCATIONS_ENVELOPE_KEYS`) | `format`, `signature`, `statement` |
| `agent/src/Adapter/AdapterCertification.php` | `revocations()` | `$label statement` (`REVOCATION_STATEMENT_KEYS`) | `format`, `issued_at`, `revocations`, `version` |
| `agent/src/Adapter/AdapterCertification.php` | `revocations()` | `$label signature` (`AUTHORITIES_SIGNATURE_KEYS`) | `key_id`, `value` |
| `agent/src/Adapter/AdapterCertification.php` | `revocations()` | `$label entry $index` (`REVOCATION_ENTRY_KEYS`) | `effective_at`, `fingerprint`, `key_id`, `reason` |
| `agent/src/Adapter/AdapterCertification.php` | `signDelegation()` | `adapter certification delegation statement` (`DELEGATION_STATEMENT_KEYS`) | `adapter_names`, `delegate`, `delegator`, `format`, `not_after`, `not_before`, `trust_tiers`, `version` |
| `agent/src/Adapter/AdapterCertification.php` | `signRevocations()` | `adapter certification revocation statement` (`REVOCATION_STATEMENT_KEYS`) | `format`, `issued_at`, `revocations`, `version` |
| `agent/src/Adapter/AdapterCertification.php` | `authorityKeys()` | `$label` (`AUTHORITIES_ENVELOPE_KEYS`) | `format`, `keys` |
| `agent/src/Adapter/AdapterCertification.php` | `authorityKeys()` | `$label` (`AUTHORITIES_ENVELOPE_V2_KEYS`) | `format`, `keys`, `signature` |
| `agent/src/Adapter/AdapterCertification.php` | `validateAuthorityRecord()` | `$label` (`AUTHORITY_RECORD_KEYS`) | `adapter_names`, `algorithm`, `public_key`, `scope`, `status`, `trust_tiers` |
| `agent/src/Adapter/AdapterCertification.php` | `validateAuthorityRecord()` | `$label` (`AUTHORITY_RECORD_V2_KEYS`) | `adapter_names`, `algorithm`, `not_after`, `not_before`, `public_key`, `record_version`, `scope`, `status`, `trust_tiers` |
| `agent/src/Adapter/AdapterCertification.php` | `assertAuthoritiesEnvelope()` | `$label envelope signature` (`AUTHORITIES_SIGNATURE_KEYS`) | `key_id`, `value` |
| `agent/src/Adapter/AdapterCertification.php` | `currentPlatform()` | `agent capability platform boundary` | `agent_version`, `branchable_state`, `compatibility`, `plugin_execution`, `site_mode`, `spec_version` |
| `agent/src/Adapter/AdapterCertification.php` | `assertPlatformShape()` | `site adapter '$name' certification platform` (`STATEMENT_PLATFORM_KEYS`) | `agent_version`, `axes`, `site_mode`, `spec_version` |
| `agent/src/Adapter/AdapterCertification.php` | `assertPlatformShape()` | `$label` (`STATEMENT_AXIS_KEYS`) | `cells`, `sha256` |
| `agent/src/Adapter/AdapterCertification.php` | `assertCertificateShape()` | `site adapter certification` | `format`, `signature`, `statement` |
| `agent/src/Adapter/AdapterCertification.php` | `assertStatementShape()` | `site adapter certification statement` (`STATEMENT_KEYS`) | `adapter`, `authority`, `bundle`, `platform`, `ratification`, `version` |
| `agent/src/Adapter/AdapterCertification.php` | `assertAdapterBinding()` | `site adapter certification adapter binding` | `canonical_sha256`, `name`, `path`, `raw_sha256`, `raw_size`, `source`, `trust_tier` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyBundleManifest()` | `$label` | `artifacts`, `bound_inputs`, `bundle_digest`, `created_at`, `environment`, `environment_summary`, `evidence`, `force_hatches`, `git_revision`, `harness`, `ratification`, `ratification_summary`, `schema_version`, `subject`, `tests`, `verdict` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyBundleManifest()` | `$label.subject` | `kind`, `name` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyBundleManifest()` | `$label.harness` | `name`, `version` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyBundleManifest()` | `$label.ratification_summary` | `certified_claims`, `manifest_count`, `profile_count` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyBundleManifest()` | `$label.artifacts[$i]` | `name`, `role`, `sha256`, `url`, `version` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyBundleManifest()` | `$label.tests[$i]` | `diff`, `id`, `log`, `result`, `verdict` |
| `agent/src/Adapter/AdapterCertification.php` | `bundleEvidence()` | `$label.evidence` | `exercised`, `grammar`, `reason` |
| `agent/src/Adapter/AdapterCertification.php` | `assetDescriptor()` | `$label` | `path`, `sha256`, `size` |
| `agent/src/Adapter/AdapterCertification.php` | `verifyRatification()` | `site adapter ratification` | `format`, `manifests`, `profiles` |
| `agent/src/Adapter/AdapterCertification.php` | `validateDisposition()` | `site adapter disposition '$name'` | `capabilities`, `default_authored_keyspaces`, `evidence`, `reason`, `status`, `supported_versions`, `unsupported` |
| `agent/src/Adapter/AdapterCertification.php` | `validateDisposition()` | `site adapter disposition '$name'.capabilities` | `deletion_semantics`, `entity_sections`, `field_sections`, `lifecycle_phases`, `operations` |
| `agent/src/Adapter/AdapterCertification.php` | `validateDisposition()` | `site adapter disposition '$name'.capabilities.deletion_semantics` | `supported`, `unsupported` |
| `agent/src/Adapter/AdapterCertification.php` | `validateDisposition()` | `site adapter disposition '$name'.evidence` | `bundle_schema`, `tests` |
| `agent/src/Adapter/AdapterCertification.php` | `validateDisposition()` | `site adapter disposition '$name'.unsupported[$i]` | `operation`, `reason`, `surface` |
| `agent/src/Adapter/AdapterCertification.php` | `validateDisposition()` | `site adapter disposition '$name'.default_authored_keyspaces[$i]` | `reason`, `status`, `table` |
| `cli/src/Authority/OperationAuthorization.php` | `validateTrust()` | `operation authority policy` | `format`, `keys`, `max_clock_skew_seconds`, `max_ttl_seconds` |
| `cli/src/Authority/OperationAuthorization.php` | `validateTrust()` | `operation authority key '$keyId'` | `actor`, `algorithm`, `grants`, `operations`, `public_key`, `status` |
| `cli/src/Authority/OperationAuthorization.php` | `validateEnvelopeShape()` | `operation authorization` | `format`, `signature`, `statement` |
| `cli/src/Authority/OperationAuthorization.php` | `validateStatement()` | `operation authorization statement` | `actor`, `expires_at`, `issued_at`, `key_id`, `nonce`, `operation`, `operation_id`, `presentation_digest`, `subject_digest`, `target_id` |
| `cli/src/Authority/OperationAuthorization.php` | `validateSubjectProjection()` | `authorization subject projection` | `authority_policy_digest`, `operation`, `operation_id`, `presentation_digest`, `required_grants`, `subject_digest`, `target_id` |
| `recovery/rollback-control.php` | `handleRequest()` | `request` | `action`, `event`, `receipt` |
| `recovery/rollback-control.php` | `verifySigned()` | `signed $label` | `key_id`, `payload`, `signature` |
| `recovery/rollback-control.php` | `validateReceipt()` | `receipt payload` (`RECEIPT_KEYS`) | `adapter_versions_sha256`, `artifact_hash`, `checkpoint_sha256`, `claim_ttl_seconds`, `code_release_metadata_sha256`, `created_at`, `encryption_key_id`, `exclusion_token_sha256`, `format`, `generation`, `ledger_session_sha256`, `lifecycle_receipts_sha256`, `owner`, `prior_code_descriptor_sha256`, `prior_verifier_inputs_sha256`, `receipt_id`, `resources_inventory_sha256`, `retention_until`, `runtime_fingerprints_sha256`, `signing_key_id`, `target_id`, `uploads_inventory_sha256` |
| `recovery/rollback-control.php` | `validateReceipt()` | `receipt payload` (`VERIFIED_PROMOTION_RECEIPT_KEYS`) | `adapter_versions_sha256`, `allow_deletes`, `artifact_hash`, `checkpoint_sha256`, `claim_ttl_seconds`, `code_release_metadata_sha256`, `created_at`, `encryption_key_id`, `exclusion_token_sha256`, `format`, `generation`, `ledger_session_sha256`, `lifecycle_receipts_sha256`, `owner`, `prior_code_descriptor_sha256`, `prior_verifier_inputs_sha256`, `receipt_id`, `resources_inventory_sha256`, `retention_until`, `runtime_fingerprints_sha256`, `signing_key_id`, `target_id`, `uploads_inventory_sha256` |
| `recovery/rollback-control.php` | `validateReceipt()` | `receipt payload` (`SCOPED_PROMOTION_RECEIPT_KEYS`) | `adapter_versions_sha256`, `allow_deletes`, `artifact_hash`, `checkpoint_sha256`, `claim_ttl_seconds`, `created_at`, `encryption_key_id`, `exclusion_token_sha256`, `format`, `generation`, `ledger_session_sha256`, `owner`, `prior_verifier_inputs_sha256`, `receipt_id`, `resources_inventory_sha256`, `retention_until`, `runtime_fingerprints_sha256`, `scope_hash`, `signing_key_id`, `target_id` |
| `recovery/rollback-control.php` | `validateEvent()` | `event payload` (`EVENT_KEYS`) | `artifact_hash`, `attempt`, `claim_epoch`, `claim_expires_at`, `claimant`, `format`, `generation`, `input_sha256`, `operation_id`, `operation_status`, `owner`, `previous_event_sha256`, `receipt_id`, `result_sha256`, `sequence`, `signing_key_id`, `state`, `target_id`, `timestamp` |
| `recovery/rollback-control.php` | `validateTarget()` | `target record` | `active_receipt`, `artifact_hash`, `claim_epoch`, `claim_expires_at`, `claimant`, `format`, `generation`, `head_event_sha256`, `owner`, `sequence`, `state`, `target_id`, `updated_at` |
| `cli/src/Contract/ApplicationContract.php` | `validateAttestation()` | `$path` | `format`, `state`, `reason`, `approving_principal`, `policy_version`, `signature`, `expires_at` — plus, only when signed: `key_id`, `platform_sha256`, `trust_root` |

## 5. What the checker proves, and what it does not

`php tools/wire-surface.php --check` proves eleven things and refuses the run rather than
printing a register it cannot stand behind:

1. **Every value above is the shipped value.** The document is rebuilt from the code and
   byte-compared; a moved constant, a renamed key, a widened grammar or a reworded refusal
   fails with the first differing line named.
2. **No signed surface is missing.** Every `sodium_crypto_sign_detached()` call site in
   `agent/`, `cli/` and `recovery/` is in a file this register covers — 4 today.
3. **No unregistered domain exists.** Every `wprism-…-signature/vN` literal in those trees is
   one of the domains in §1, and none is a prefix of another.
4. **The bounded vocabularies are still bounded.** `AdapterCertification`'s expiry
   vocabulary is exactly `not_after`/`not_before` judged against `$now ?? time()`; the typed
   revocation entry is exactly `{effective_at, fingerprint, key_id, reason}` and lives in
   exactly one signing file; `revoked_at` and CRL vocabulary appear in none (R-14, R-15, R-26).
5. **The rollback signature really is domain-free.** A signature is minted and verified
   against the unprefixed canonical payload at generation time (R-03).
6. **The spec-version window has not accumulated.** The shipped validator is probed over
   N-3 … N+2 and must accept exactly {N-1, N} — floor `WPRISM_SPEC_VERSION - 1`, never deeper (R-18).

7. **The shipped platform trust root is one of its two legal states.** It is the empty
   `wprism-adapter-authorities/v1` registry byte for byte, or a
   `wprism-adapter-authorities/v2` document that VERIFIES through the
   shipped reader — envelope signature, fingerprint-bound ids, windows and namespaces all
   checked by the code a site runs (R-08, R-18).
8. **The closed top-level manifest key set has one definition.** The set the shipped
   validator admits at every accepted `spec_version` and the partition the shipped signer
   classifies against are compared in both directions, and the only excess admitted is what
   an implemented engine feature claims (R-21).

9. **Every key that excess admits is signable.** Each top-level key an implemented engine
   feature claims carries a certificate arm in the feature's own roster row — today `attr_id_codecs` -> `field`, `block_content` -> `field`, `block_media_derivatives` -> `field`, `block_values` -> `field`, `body_refs` -> `field`, `column_codecs` -> `field`, `declaration_evidence` -> `non_surface`, `engine_features` -> `non_surface`, `incompatible_plugins` -> `non_surface` —
   each arm is one of `entity`, `field`, `non_surface`, no roster row names a key the signer's partition
   already carries, and every claimed section publishes its value grammar (R-31). This is the
   one gate whose failure mode is an OMISSION rather than a disagreement: a feature-claimed
   key with no arm loads on every site and is unsignable by both profiles.

10. **The § v3.9 grandfather list is in its place and is still closed.** Its constants are
    declared under `agent/src` — never inside an adapter-owned package payload — and their
    membership equals the shipped library exactly: 27 adapter
    names and 22 `id_kind`s, in both directions, so an additional unprefixed name requires a reviewed
    generated inventory diff rather than merely a directory appearing (R-27).
11. **The register has no gaps and no duplicates.** Row ids run R-01 … R-32 with every integer
    present exactly once. Ids are ordinal bookkeeping — nothing on disk or in a certificate
    embeds one — but an id nobody can account for reads as a row somebody deleted, and this
    document is the only place a deleted decision would be missed.

What it does not prove: that the decisions are *right*, that any artifact in the field was
signed under these exact rules, or that a holder's verifier implements them. The rationale
halves of §2 are prose, reviewed by a human, and the register is only as good as the review
that put them there. What the checker guarantees is narrower and is the thing that rots
first: the register cannot go quietly out of date.
