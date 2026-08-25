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
| site adapter certification (`duo-adapter-certification/v1`) | `duo-site-adapter-certification-signature/v1\0` | domain &#124;&#124; `Canon::encode(statement)` — the whole five-member statement | `AdapterCertification::SIGNATURE_DOMAIN` |
| authorities envelope (`duo-adapter-authorities/v2`) | `duo-adapter-authorities-signature/v1\0` | domain &#124;&#124; `Canon::encode({format, keys})` — the document minus its own signature | `AdapterCertification::SIGNATURE_DOMAIN_AUTHORITIES` |
| `duo-adapter-authority-delegations/v1` | `duo-adapter-authority-delegation-signature/v1\0` | domain &#124;&#124; `Canon::encode(statement)` — one delegation statement — the grant, both key identities and the window | `AdapterCertification::SIGNATURE_DOMAIN_DELEGATION` |
| `duo-adapter-authority-revocations/v1` | `duo-adapter-authority-revocation-signature/v1\0` | domain &#124;&#124; `Canon::encode(statement)` — the whole revocation statement — every entry at once, so no row can be dropped | `AdapterCertification::SIGNATURE_DOMAIN_REVOCATION` |
| contract attestation (`duo-contract-attestation/v1`) | `duo-contract-attestation-signature/v1\0` | domain &#124;&#124; `Canon::encode({attested_digest, format})` | `ContractAttestation::SIGNATURE_DOMAIN` |
| rollback receipt / event (`duo-rollback-receipt/v2`, `duo-rollback-event/v1`) | **none** — proved at generation time | `CanonicalJson::encode(payload)` with nothing prepended | `RollbackControl::sign()` |

The first three are separated by construction, and the checker refuses the run if any one of
them is a prefix of another. The last is not separated at all, and R-03 is where that decision
and its cost are written down.

## 2. The register

### R-01 — The adapter-certification signature domain

**Shipped now.** `duo-site-adapter-certification-signature/v1\0`, prepended to `Canon::encode(statement)`.

**Why it cannot change.** A holder verifies by recomputing these bytes. Changing the string does not invalidate old certificates — it makes them unverifiable by the agent that changed, which is the same outcome as revoking every one of them at once, with no message saying so. The domain is also the only thing standing between this statement and a verifier for another statement type: it is "kept independent from JSON framing so this signature cannot verify elsewhere" (AdapterCertification.php:52).

**Reserved.** The `/v1` suffix is the whole change channel. A v2 domain is a NEW statement type verified alongside this one, never an edit of it; an agent may verify both, and a certificate says which it is by the bytes it was signed over.

### R-02 — The contract-attestation signature domain and its two-member statement

**Shipped now.** `duo-contract-attestation-signature/v1\0`, prepended to a canonical two-member statement (`duo-contract-attestation-statement/v1`). The signature binds `attested_digest` — the document minus `contract_digest`, with `attestation.signature` forced to null — never the document bytes.

**Why it cannot change.** The statement is rebuilt from the document at verify time, so a third member would change the bytes for every attestation already signed. The two exclusions are forced and cannot be revisited either: `contract_digest` is the digest of everything else, and a signature is never inside its own input.

**Reserved.** Facts an operator wants signed go in `attestation`, which is already inside `attested_digest`'s input — that is the additive channel, and it is why the statement stays two members forever.

### R-03 — Rollback receipts and events are signed with NO domain separation

**Shipped now.** Proved at generation time: a signature minted by `RollbackControl::sign()` verifies against `CanonicalJson::encode(payload)` with nothing prepended. Three payload kinds share one key and are told apart only by their own `format` member (`duo-rollback-receipt/v2`, `duo-rollback-event/v1`, `duo-scoped-promotion-receipt/v1`) and by the key file at `<root>/public-keys/<key_id>.pub`.

**Why it cannot change.** Prepending a domain later invalidates every receipt and every event already written into a site's recovery root — the artifacts a rollback reads precisely when everything else is broken, and the one class of artifact that cannot be re-minted after the fact. The three payload key sets (below) are what keep the kinds apart, so they carry the whole burden a domain would otherwise share.

**Reserved.** A v3 payload may carry a domain member INSIDE the payload: additive, still canonical, and old signatures keep verifying. The prefix channel is spent.

### R-04 — Both domains end in NUL, and neither is a prefix of the other

**Shipped now.** Checked at generation time against the two projected constants.

**Why it cannot change.** The trailing NUL is inside the signed bytes; it is what stops one domain from being a prefix of a longer one and turning a statement of one kind into a valid statement of another. Removing it from either domain is a domain change (R-01).

**Reserved.** Any third domain must keep the terminator and must not be a prefix of an existing one — this checker refuses the register otherwise.

### R-05 — The certificate root and the frozen envelope key sets

**Shipped now.** Certificate root: `{format, signature, statement}`. Frozen envelope: `{certificate_json, certificate_sha256, format}`.

**Why it cannot change.** Both are checked with `assertExactKeys()`, which refuses a MISSING key and an UNKNOWN key with one message. A v1 agent therefore refuses a certificate carrying a field it does not know rather than ignoring it, so no field can ever be added to this envelope for holders of today's agent.

**Reserved.** Extension is by a new `format` value — a new envelope verified beside this one, never a widened one.

### R-06 — The signed statement member set

**Shipped now.** `{adapter, authority, bundle, platform, ratification}`.

**Why it cannot change.** Same closure as R-05, and covered by the signature: a sixth member changes the signed bytes AND is refused by every deployed verifier. This is the row that makes every other adapter-certification decision permanent, because none of them can be revisited without adding or moving a member here.

**Reserved.** Nothing. Facts that need signing go inside an existing member — `bundle` and `ratification` are whole objects the signature already covers.

### R-07 — The adapter binding, and what a certificate names

**Shipped now.** `{canonical_sha256, name, path, raw_sha256, raw_size, source, trust_tier}`. `path` is always `adapters/<name>.json` and `source` is always `site`.

**Why it cannot change.** The path string is inside the signed statement, so the certificate directory layout (`adapters/`, certificates under `adapters/certifications/`) is signed data, not a local convention: moving the directory orphans every certificate in the field.

**Reserved.** A second bindable source (a plugin-bundled adapter, `plugin`) would be a new `source` VALUE, which the key set already admits — the value vocabulary is the extension point, the key set is not.

### R-08 — The authority binding inside the statement: BOTH roots bind the key identity

**Shipped now.** `{fingerprint, key_id, record, record_sha256, trust_root}`. Both trust roots bind the key IDENTITY — everything but `adapter_names`/`trust_tiers` — self-consistently by digest, and re-check the two scope lists LIVE against the current record, revocation and (at record v2) the validity window with them.

**Why it cannot change.** A trust root is a LIVING registry: it grows every time another adapter is certified under a key, so whole-record binding invalidates every earlier certificate under that key the moment a second one is signed. The site root has bound identity since T6 for that reason; the platform root bound the whole record until WP-4.8, justified by a premise ENROLLMENT FALSIFIES — that the shipped file never grows under an operator's hand. It was changeable only because the platform root has never signed a certificate: `manifests/capabilities/adapter-authorities.json` is `{"keys": {}}`, and gate 6 below refuses a populated one that does not verify. Going the other way — widening either root back to whole-record binding — would invalidate every certificate in the field at the next enrollment, silently.

**Reserved.** A future root chooses one of these two bindings at the moment its first certificate is signed, and never after. There is no third choice, because the scope lists are either inside the signature or enforced live, and doing both is the first option.

### R-09 — The two authority record key sets, and why they are two files

**Shipped now.** Adapter root (`duo-adapter-authorities/v1`): `{adapter_names, algorithm, public_key, scope, status, trust_tiers}`, and at `duo-adapter-authorities/v2` the nine of R-18. Contract root (`duo-contract-attestation-authorities/v1`, at `.duo/contract/authorities.json`): the four members derived by probe below, scope `contract_attestation`.

**Why it cannot change.** The adapter root requires `adapter_names` and `trust_tiers` on EVERY record and validates the whole file the moment it exists, so a single contract-scoped record in that file breaks every adapter certificate in the repository. The two roots can never be merged; each record set can never gain a member, because both are closed in both directions.

**Reserved.** A third scope word means a third file. That is the shape, and it is already load-bearing.

### R-10 — The authorities envelope, and `keys` as a JSON object

**Shipped now.** Envelope: `{format, keys}` (adapter root), and the same `{format, keys}` shape for the contract root. `keys` is a JSON OBJECT even when it holds one member. A numeric-only key id cannot appear in either file at all, even though the contract grammar accepts one as a string (see the matrix): both roots refuse a non-string map key by name.

**Why it cannot change.** PHP decodes a numeric JSON object-map key as an integer, so a numeric identity would compare unequal to the string the signed document carries — refusing it is what makes the map key and the `key_id` inside the statement the same value. An empty PHP array canonically encodes as `[]`, which both readers refuse, so the object cast at write time is part of the wire, not a nicety.

**Reserved.** A revocation list (R-15) cannot be added to this envelope: its key set is closed. It needs a new `format` value — which is exactly the channel `duo-adapter-authorities/v2` used to add the envelope signature (R-18).

### R-11 — Three key-id grammars, and they disagree

**Shipped now.** Adapter root: `AdapterSources::assert_name()` — lowercase slugs only, must start and end alphanumeric, at least one lowercase letter. Contract root: `/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D`. Recovery root: `[A-Za-z0-9._-]{1,64}`, which admits a leading separator and carries a length bound the adapter root does not have at all. The matrix below is the shipped verdict of each, probe by probe.

**Why it cannot change.** A key id is an object-map key in signed documents, the `key_id` member inside a signed statement, and — in the recovery root — a FILENAME (`public-keys/<key_id>.pub`). Narrowing any of the three orphans installed keys and every artifact they signed; widening one makes an id legal in one root and unreadable in another. The divergence itself is now permanent: unifying them would narrow at least two of the three.

**Reserved.** A future root may narrow at MINT time (refusing to register a key id) without touching what verification accepts. That is the only safe direction.

### R-12 — Adapter names and authority key ids share one grammar

**Shipped now.** `AdapterCertification::keyId()` and `::adapterName()` are both nothing but `AdapterSources::assert_name()`; the matrix below is checked probe-by-probe for agreement and the generator refuses if they ever diverge.

**Why it cannot change.** The adapter name is inside the signed statement twice (as `name` and inside `path`), and it is a filename on disk. One grammar for both is what lets a certificate name a key and an adapter with the same rules; splitting them later would make some existing certificate's `key_id` or `name` illegal.

**Reserved.** Nothing. A wider name grammar is a wider filename grammar.

### R-13 — Two trust roots, and the shipped library wins the key-id namespace

**Shipped now.** Adapter certification: `platform` and `site`. Contract attestation: `site` only — a platform-rooted contract attestation refuses by name (`contract_attestation_trust_root_unsupported`). A site certificate may never claim a key id the shipped file reviews.

**Why it cannot change.** The `trust_root` word is inside the signed statement and is what a host prints beside a certified claim. The shipped-wins rule needs no repository to evaluate, which is what lets frozen verification enforce it; relaxing it would let a site key answer for a reviewed identity in an artifact already frozen.

**Reserved.** A third root is a new VALUE in a member that already exists — admitted without a schema change, which is why the refusal for an unsupported one is by name.

### R-14 — Expiry exists on two surfaces, reads one clock, and allows no skew

**Shipped now.** Contract attestation: `expires_at` is mandatory, grammar `Y-m-d\TH:i:s\Z` (UTC seconds, checked at both ends so "expired" is never a parse accident), compared against `$now ?? time()` and refused at `>=`. Adapter certification: the expiry vocabulary is EXACTLY `not_after`/`not_before`, mandatory on a `duo-adapter-authorities/v2` authority record and absent from a v1 one, the identical grammar string, compared against the identical `$now ?? time()` and refused at `>=` — all four facts checked by grep, not asserted. Recovery: `claim_expires_at` bounds a claimant epoch, never a signature.

**Why it cannot change.** An expired attestation or authority REFUSES; it never silently becomes an unsigned one, because a silent downgrade would make a stale claim indistinguishable from a fresh one at every consumer. There is no skew tolerance in either direction: a wrong operator clock refuses rather than accepts, which is the safe failure and is now the behaviour holders depend on. The adapter root additionally refuses an IMPLAUSIBLE clock — one reading before the record's own `not_before` — BEFORE it tests expiry, because a backwards clock would otherwise find every retired record inside its window.

**Reserved.** Expiry on the CERTIFICATE itself, as opposed to the authority that signed it, is still a statement member (R-06) and therefore a new format, not a field. A future skew allowance would have to be a REFUSAL widening, which no deployed verifier would apply to an artifact it already holds.

### R-15 — Revocation is per KEY, never per certificate, on both of its two channels

**Shipped now.** Channel 1, in the authority file: `status` is `trusted` or `revoked`, per key, in a document that ships inside the agent archive. Channel 2, added by WP-4.9 and detailed in R-26: a platform-signed `duo-adapter-authority-revocations/v1` document installed out of band. Neither has a serial number and neither can revoke ONE certificate — every entry names key material. A revoked site key still verifies inside an already-frozen snapshot through channel 1, because frozen verification reopens no mutable site file (`verifyCertificate()`'s site branch, AdapterCertification.php:1338-1379); channel 2 and a revoked platform key both DO reach a frozen snapshot.

**Why it cannot change.** Revoking a key revokes EVERY artifact it ever signed, retroactively and all at once — there is no way to revoke one certificate, on either channel. Operators sign under per-adapter keys or accept that blast radius; that trade is fixed the moment a second adapter is signed under one key. What WP-4.9 changed is REACHABILITY, not granularity: holders now have a channel that does not wait for an agent release and that a frozen snapshot can read. Going back — removing channel 2 — would silently restore the frozen-path gap for every vendor key already federated by copy.

**Reserved.** Per-certificate revocation still needs an identifier the statement does not carry (R-06), so it remains a v3 statement type rather than an addition. A third channel is not reserved: two are already the maximum an operator can reason about, and R-26 states which one answers where.

### R-16 — One algorithm, no negotiation, and canonical base64

**Shipped now.** Both roots require `algorithm: ed25519` literally; key material is 32 bytes, a signature is 64. Base64 is checked by round-trip equality (`base64_encode(decode(x)) === x`), never merely by decodability.

**Why it cannot change.** The `algorithm` word is inside a signed record, so it is already covered by a signature that only Ed25519 can produce. A v1 agent has no second verifier to negotiate with, so agility could only ever be forward-looking: today's artifacts stay Ed25519 for their whole life. Round-trip base64 exists because a non-canonical encoding of the same key bytes is a second spelling of one identity, and the map key would not match.

**Reserved.** A second algorithm is a second record shape and therefore a new authorities format (R-09/R-10), verified beside this one.

### R-17 — `id_kind` is a flat, unprefixed namespace

**Shipped now.** The engine owns exactly `post`, `term`, `tt`, `user` (ref), `post`, `term`, `tt` (token) and `post`, `term`, `term_taxonomy` (ledger); every other legal value is an `id_kind` a pinned manifest declared for a table it owns. There is no vendor prefix and no reservation mechanism.

**Why it cannot change.** Captured state and `duo_map` rows embed the BARE kind, so a prefix rule introduced later would have to rewrite every token in every branch of every site — the one migration this product cannot perform, because the branches are the customer's data. Two adapters that pick one name collide with no arbiter.

**Reserved.** A convention (a vendor-shaped name) can be recommended to authors at any time; a RULE cannot be introduced without invalidating existing captures.

### R-18 — The `spec_version` acceptance window is exactly {N-1, N}

**Shipped now.** Measured by handing candidate integers to the shipped `AdapterContractGrammar::validate_adapter_contract()`: this engine accepts `1`, `2` and refuses every other integer wholesale, naming the window. An absent or non-integer `spec_version` keeps the older refusal, because it is not a version and so is not outside anything. A manifest inside the window that declares a section this engine implements only at a HIGHER version refuses naming the section (`engine_features` today).

**Why it cannot change.** The floor is DUO_SPEC_VERSION - 1 and never deeper, checked at generation time. Narrowing the window later refuses every adapter in the field that took it at its word, which is a flag day of exactly the kind the window exists to end; widening it to N-2 costs nothing on the day it is done and converts a staging channel with an expiry into permanent tolerance that no refusal, document or suite would report. So the equality is the gate, not the intention.

**Reserved.** Closing the window is its own dated decision (spec/repo-format.md § v3.12), gated on no v2-declaring pinned manifests in the fleet plus at least one grammar section shipped post-v3 through `engine_features` with no version bump — the replacement proven before the thing it replaces is retired.

### R-19 — Engine feature names are engine-owned, and permanent once declared

**Shipped now.** This engine implements `spec-window/v1`. A manifest declares names through the top-level `engine_features` list; an engine lacking a listed name refuses THAT ADAPTER, naming the feature. An adapter declares a name and never mints one: a name nothing implements is refused as unimplemented rather than admitted as forward-looking.

**Why it cannot change.** A declared feature name is inside the manifest bytes `ArtifactPolicyIdentity::manifest_rows()` folds into that adapter's `digest`, which every `site.duo.json` content pin and every certificate's `adapter.canonical_sha256` binds. Renaming or re-spelling a feature therefore moves the digest of every manifest that declares it and invalidates their pins and certificates at once — the same irreversibility R-17 records for `id_kind`, reached through a different door.

**Reserved.** The `/vN` suffix is the change channel: a feature whose meaning moves is a NEW name implemented beside the old one, never an edit of it, so a manifest that declared the old name keeps its bytes and its digest.

### R-20 — The v2 authority record, and the four decisions it fixes at once

**Shipped now.** `duo-adapter-authorities/v2` records are `{adapter_names, algorithm, not_after, not_before, public_key, record_version, scope, status, trust_tiers}`, inside the envelope `{format, keys, signature}` whose `signature` is `{key_id, value}`. A key id must END in the first 12 hex characters of `sha256(public_key)`; an `adapter_names` entry is an exact name or a `<vendor>-*` namespace; the window is judged as R-14 states; and the envelope signature is made by a key the document itself carries. `record_version: 2` restates the envelope format inside every record, and a disagreement between the two refuses. v1 records and v1 documents keep today's behaviour byte for byte — the four rules read only a record that declared `record_version`.

**Why it cannot change.** All four land together because they are one document: a holder who accepts a v2 record accepts all of them, and shipping any one later would be a second flag day for whoever already holds a v2 file. The fingerprint rule cannot be relaxed afterwards without admitting ids that were unrepresentable when the trust decision was made; it cannot be tightened (a longer fingerprint) without orphaning every id already issued. The window is mandatory because `assertExactKeys()` refuses missing and unknown alike, so an optional member has no honest home in the set — a holder wanting no expiry stays at v1, where there is none. An EMPTY v2 registry is unrepresentable by construction: the envelope signature names a key inside the document, so a registry with no keys has nothing that could sign it. That is what lets the shipped empty root stay v1 and byte-identical.

**Reserved.** The envelope signature proves the document was assembled WHOLE by a holder of a key it carries — nobody else can append a key, widen a scope list, move a window or flip a status in it. It is deliberately NOT a chain to an off-document root: delegation is its own signed statement type with its own domain (spec/repo-format.md § v3.8), never a member or an arm inside this one.

### R-21 — The top-level manifest key set is closed at `spec_version: 3`, from one definition

**Shipped now.** A `spec_version: 3` manifest may declare 33 top-level keys — the signer's three-arm partition, 5 entity + 14 field + 14 non-surface — plus whatever keys its own declared, IMPLEMENTED `engine_features` values claim (`engine_features` itself, via `spec-window/v1`). A key in none of those refuses at load BY NAME, and `_draft` — the sidecar `duo adapter-draft` writes — refuses with its own remedy, to strip it. v2 manifests keep the open behaviour byte for byte, so none of the shipped library changes. Measured here by asking the shipped validator and the shipped signer for their sets and comparing them in both directions.

**Why it cannot change.** A key REMOVED from the set later refuses every manifest in the field that declared it, and takes its adapter digest with it: the key is inside the manifest bytes `ArtifactPolicyIdentity::manifest_rows()` folds, so the remedy is an edit that moves every `site.duo.json` content pin and invalidates every certificate over that adapter (R-19 records the same irreversibility for a feature name). Closing the set is therefore a one-way door: it can be opened wider through the growth rule and can never be narrowed. The one definition is load-bearing for the same reason — two lists that agree today diverge silently, and the symptom is an adapter that loads everywhere and cannot be certified.

**Reserved.** Growth is § v3.2's channel and nothing else: a post-v3 primitive ships as an engine feature name, the top-level keys that feature claims, and a refusal for the engine that lacks it — so no key is ever added by widening this set for everyone. A feature whose key must also be SIGNABLE gives it an arm in the partition in the same change, because a feature record carries `{since, keys}` and no arm, and an arm is what decides whether a certificate covers the key as a surface.

### R-25 — Delegation is depth-1, and the bound is in the verifier rather than in a policy

**Shipped now.** A `duo-adapter-authority-delegations/v1` document at `adapters/delegations.json` holds a map of `{signature, statement}` objects. The signed statement is `{adapter_names, delegate, delegator, format, not_after, not_before, trust_tiers, version}`, with `delegate` `{algorithm, key_id, public_key}` and `delegator` `{fingerprint, key_id, trust_root}`, under domain `duo-adapter-authority-delegation-signature/v1\0`. Verification chains exactly 1 level: a delegator that is itself a delegate is refused BY NAME before it is looked up. A delegator is resolved ONLY in `duo-adapter-authorities/v1`'s shipped file, so a site key cannot delegate; the grant may only narrow the delegator's `adapter_names`, `trust_tiers` and window; and a delegated key resolves under trust root `site`, not a third word.

**Why it cannot change.** The depth bound is inside the VERIFIER, not a configurable maximum, and that is what makes it a property rather than a setting: every holder of this agent enforces it identically and no document can ask for more. Raising it later would admit paths that were unrepresentable when the trust decision was made — a delegate that could not delegate yesterday could hand on a grant tomorrow, retroactively, with nothing in the field re-reviewed. Lowering it to zero orphans every delegation already issued. The narrow-only rule is the same shape: it is a grammar restriction, so a widening grant is unrepresentable rather than merely refused by review, and relaxing it would silently widen every grant in the field at the next verification.

**Reserved.** Nothing about depth. A vendor that must hand on authority enrolls its sub-vendor with the platform root directly, which is one review rather than an unbounded path. The extension channel for the statement itself is `version`, inside the signature: a grammar this engine does not implement is refused BY VERSION rather than read as corruption.

### R-26 — Typed revocation, and the one channel that reaches a frozen snapshot

**Shipped now.** A `duo-adapter-authority-revocations/v1` document, envelope `{format, signature, statement}`, statement `{format, issued_at, revocations, version}`, each entry `{effective_at, fingerprint, key_id, reason}`, under domain `duo-adapter-authority-revocation-signature/v1\0`. It is installed at `capabilities/adapter-revocations.json` in the agent's MANIFEST LIBRARY — the only path frozen verification holds — is signed by a key the shipped platform root carries, and ships ABSENT. An entry binds `fingerprint` = `sha256(public_key)`, never the key id. The signer's own window is deliberately not applied.

**Why it cannot change.** The FINGERPRINT binding cannot be exchanged for an id binding afterwards: an id can be re-minted over new key material, so an id-bound revocation would be escapable by rotating a name. Absence meaning "nothing is revoked" is equally fixed — every deployed agent already reads it that way, so a future "absent means refuse" would brick every site that never installed one. And the reachability itself is one-way: this is the only channel that reaches an already-frozen snapshot for a site-rooted key, so removing it restores a gap for every vendor key already federated by copy, silently.

**Reserved.** The signer's window is unapplied ON PURPOSE and that is not an oversight to fix later: applying it would let a lapsed window RESURRECT the exact identities this document exists to burn. A future per-certificate revocation still needs R-06's missing identifier. What this document deliberately does NOT do is revoke the operator's own self-minted site key through a channel the operator does not control — that asymmetry is preserved, and every refusal it raises says so in its own sentence.

### R-27 — The reserved `<vendor>-` form, and the closed grandfather list under it

**Shipped now.** At `spec_version 3` an out-of-tree adapter name is `<vendor>-<name>` and every `providers[].id` it declares sits in that same vendor namespace (`IdentityNamespaces::assert_out_of_tree_identity()`, reached from `AdapterSources::assert_out_of_tree_contract()`, the one boundary all four out-of-tree entry points share). Below that version the rule returns before reading a member, so at `DUO_SPEC_VERSION 2` it refuses nothing. The unprefixed space is reserved to the shipped library as a CLOSED ENUMERATION of 16 adapter names (`acf`, `advanced-editor-tools`, `classic-editor`, `code-snippets`, `contact-form-7`, `core`, `duo-agency-cpt`, `elementor`, `ninja-forms`, `paid-memberships-pro`, `polylang`, `the-events-calendar`, `woocommerce`, `wps-hide-login`, `yoast`, `yoast-duplicate-post`) and 18 `id_kind`s (`attr_taxonomy`, `code_snippet`, `nf3_action`, `nf3_field`, `nf3_form`, `pmpro_category_restrict`, `pmpro_discount`, `pmpro_discount_level`, `pmpro_group`, `pmpro_level`, `pmpro_level_group`, `pmpro_restrict`, `wc_tax_class`, `wc_tax_loc`, `wc_tax_rate`, `wc_zone`, `wc_zone_loc`, `wc_zone_method`), living in `agent/src` and never under `manifests/`. Gate 7 below asserts both halves. Ownership of a namespace is the authority record's, not this list's: `adapter_names: ["<vendor>-*"]` (R-20) is what decides which names a key may certify.

**Why it cannot change.** The separator forecloses every other scheme: `<vendor>-<name>` cannot later become `<vendor>/<name>` or `<vendor>.<name>` without re-spelling every out-of-tree identity already authored, and an adapter name is inside the manifest bytes `ArtifactPolicyIdentity::manifest_rows()` folds into that adapter's digest — so a re-spelling invalidates every pin and certificate that named it (the same door R-19 reaches). The ENUMERATION cannot be replaced by a shape test afterwards either: 10 of the 16 shipped names are hyphen-shaped without being vendor-prefixed (`the-events-calendar` is not vendor `the`), so a shape test admits precisely the rows a reviewer would want to see. And the list can never simply grow: each addition hands one more unprefixed identity to the shipped library permanently, which is why the release gate refuses any membership but equality with the library itself.

**Reserved.** This row deliberately reserves NOTHING for `tables.<t>.id_kind`. R-17 rules the prefix RULE out permanently — captured state and `duo_map` rows embed the bare kind — so the 18 shipped kinds are recorded here as a permanent floor and a CONVENTION for authors, never as a break list. A future scheme for that space is a new `id_kind`-carrying wire, not an edit of this one.

## 3. The grammars, as the shipped validators answer them

### 3.1 Key ids, three roots

Each cell is the verdict of the named validator on that exact probe, obtained by calling it.

| probe | adapter root | contract root | recovery root |
|---|---|---|---|
| `wpforms` | accepted | accepted | accepted |
| `acme.key_1` | accepted | accepted | accepted |
| `Acme-Key` | refused | accepted | accepted |
| `2026` | refused | accepted | accepted |
| `a` | accepted | accepted | accepted |
| `-leading` | refused | refused | accepted |
| `trailing-` | refused | accepted | accepted |
| `kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk` | accepted | refused | refused |
| `has space` | refused | refused | refused |
| (empty) | refused | refused | refused |

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

Projected from the call sites that decide them: the three signing files, plus the contract
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
| `agent/src/Adapter/AdapterCertification.php` | `assertCertificateShape()` | `site adapter certification` | `format`, `signature`, `statement` |
| `agent/src/Adapter/AdapterCertification.php` | `assertStatementShape()` | `site adapter certification statement` | `adapter`, `authority`, `bundle`, `platform`, `ratification` |
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
| `recovery/rollback-control.php` | `handleRequest()` | `request` | `action`, `event`, `receipt` |
| `recovery/rollback-control.php` | `verifySigned()` | `signed $label` | `key_id`, `payload`, `signature` |
| `recovery/rollback-control.php` | `validateReceipt()` | `receipt payload` (`RECEIPT_KEYS`) | `adapter_versions_sha256`, `artifact_hash`, `checkpoint_sha256`, `claim_ttl_seconds`, `code_release_metadata_sha256`, `created_at`, `encryption_key_id`, `exclusion_token_sha256`, `format`, `generation`, `ledger_session_sha256`, `lifecycle_receipts_sha256`, `owner`, `prior_code_descriptor_sha256`, `prior_verifier_inputs_sha256`, `receipt_id`, `resources_inventory_sha256`, `retention_until`, `runtime_fingerprints_sha256`, `signing_key_id`, `target_id`, `uploads_inventory_sha256` |
| `recovery/rollback-control.php` | `validateReceipt()` | `receipt payload` (`LEGACY_RECEIPT_KEYS`) | `adapter_versions_sha256`, `artifact_hash`, `checkpoint_sha256`, `claim_ttl_seconds`, `created_at`, `encryption_key_id`, `exclusion_token_sha256`, `format`, `generation`, `ledger_session_sha256`, `lifecycle_receipts_sha256`, `owner`, `prior_code_descriptor_sha256`, `prior_verifier_inputs_sha256`, `receipt_id`, `resources_inventory_sha256`, `retention_until`, `runtime_fingerprints_sha256`, `signing_key_id`, `target_id`, `uploads_inventory_sha256` |
| `recovery/rollback-control.php` | `validateReceipt()` | `receipt payload` (`SCOPED_PROMOTION_RECEIPT_KEYS`) | `adapter_versions_sha256`, `allow_deletes`, `artifact_hash`, `checkpoint_sha256`, `claim_ttl_seconds`, `created_at`, `encryption_key_id`, `exclusion_token_sha256`, `format`, `generation`, `ledger_session_sha256`, `owner`, `prior_verifier_inputs_sha256`, `receipt_id`, `resources_inventory_sha256`, `retention_until`, `runtime_fingerprints_sha256`, `scope_hash`, `signing_key_id`, `target_id` |
| `recovery/rollback-control.php` | `validateEvent()` | `event payload` (`EVENT_KEYS`) | `artifact_hash`, `attempt`, `claim_epoch`, `claim_expires_at`, `claimant`, `format`, `generation`, `input_sha256`, `operation_id`, `operation_status`, `owner`, `previous_event_sha256`, `receipt_id`, `result_sha256`, `sequence`, `signing_key_id`, `state`, `target_id`, `timestamp` |
| `recovery/rollback-control.php` | `validateTarget()` | `target record` | `active_receipt`, `artifact_hash`, `claim_epoch`, `claim_expires_at`, `claimant`, `format`, `generation`, `head_event_sha256`, `owner`, `sequence`, `state`, `target_id`, `updated_at` |
| `cli/src/Contract/ApplicationContract.php` | `validateAttestation()` | `$path` | `format`, `state`, `reason`, `approving_principal`, `policy_version`, `signature`, `expires_at` — plus, only when signed: `key_id`, `platform_sha256`, `trust_root` |

## 5. What the checker proves, and what it does not

`php tools/wire-surface.php --check` proves eight things and refuses the run rather than
printing a register it cannot stand behind:

1. **Every value above is the shipped value.** The document is rebuilt from the code and
   byte-compared; a moved constant, a renamed key, a widened grammar or a reworded refusal
   fails with the first differing line named.
2. **No signed surface is missing.** Every `sodium_crypto_sign_detached()` call site in
   `agent/`, `cli/` and `recovery/` is in a file this register covers — 3 today.
3. **No unregistered domain exists.** Every `duo-…-signature/vN` literal in those trees is
   one of the domains in §1, and none is a prefix of another.
4. **The bounded vocabularies are still bounded.** `AdapterCertification`'s expiry
   vocabulary is exactly `not_after`/`not_before` judged against `$now ?? time()`; the typed
   revocation entry is exactly `{effective_at, fingerprint, key_id, reason}` and lives in
   exactly one signing file; `revoked_at` and CRL vocabulary appear in none (R-14, R-15, R-26).
5. **The rollback signature really is domain-free.** A signature is minted and verified
   against the unprefixed canonical payload at generation time (R-03).
6. **The spec-version window has not accumulated.** The shipped validator is probed over
   N-3 … N+2 and must accept exactly {N-1, N} — floor `DUO_SPEC_VERSION - 1`, never deeper (R-18).

7. **The shipped platform trust root is one of its two legal states.** It is the empty
   `duo-adapter-authorities/v1` registry byte for byte, or a
   `duo-adapter-authorities/v2` document that VERIFIES through the
   shipped reader — envelope signature, fingerprint-bound ids, windows and namespaces all
   checked by the code a site runs (R-08, R-18).
8. **The closed top-level manifest key set has one definition.** The set the shipped
   validator admits at `spec_version: 3` and the partition the shipped signer
   classifies against are compared in both directions, and the only excess admitted is what
   an implemented engine feature claims (R-21).

8. **The § v3.9 grandfather list is in its place and is still closed.** Its constants are
   declared under `agent/src` — never under `manifests/`, where AGENTS.md rule 2 would fold
   them into every adapter digest — and their membership equals the shipped library exactly: 16 adapter
   names and 18 `id_kind`s, in both directions, so a seventeenth unprefixed name is a reviewed
   edit rather than a file appearing in a directory (R-27).

What it does not prove: that the decisions are *right*, that any artifact in the field was
signed under these exact rules, or that a holder's verifier implements them. The rationale
halves of §2 are prose, reviewed by a human, and the register is only as good as the review
that put them there. What the checker guarantees is narrower and is the thing that rots
first: the register cannot go quietly out of date.
