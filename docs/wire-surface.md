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
| contract attestation (`duo-contract-attestation/v1`) | `duo-contract-attestation-signature/v1\0` | domain &#124;&#124; `Canon::encode({attested_digest, format})` | `ContractAttestation::SIGNATURE_DOMAIN` |
| rollback receipt / event (`duo-rollback-receipt/v2`, `duo-rollback-event/v1`) | **none** — proved at generation time | `CanonicalJson::encode(payload)` with nothing prepended | `RollbackControl::sign()` |

The first two are separated by construction. The third is not separated at all, and R-03
is where that decision and its cost are written down.

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

### R-08 — The authority binding inside the statement, and the site/platform asymmetry

**Shipped now.** `{fingerprint, key_id, record, record_sha256, trust_root}`. Platform-rooted certificates bind the WHOLE authority record; site-rooted ones bind the key IDENTITY (everything but `adapter_names`/`trust_tiers`) and re-check the scope lists live.

**Why it cannot change.** The asymmetry is forced by where each root lives: the site root is a living registry that grows every time an operator certifies another adapter, so whole-record binding would invalidate every earlier certificate under that key the moment a second one is signed (AdapterCertification.php:1511-1525). Narrowing the platform binding to identity later would silently widen what a shipped key's old certificates cover.

**Reserved.** Any future root chooses one of these two bindings at the moment its first certificate is signed, and never after.

### R-09 — The two authority record key sets, and why they are two files

**Shipped now.** Adapter root (`duo-adapter-authorities/v1`): `{adapter_names, algorithm, public_key, scope, status, trust_tiers}`. Contract root (`duo-contract-attestation-authorities/v1`, at `.duo/contract/authorities.json`): the four members derived by probe below, scope `contract_attestation`.

**Why it cannot change.** The adapter root requires `adapter_names` and `trust_tiers` on EVERY record and validates the whole file the moment it exists, so a single contract-scoped record in that file breaks every adapter certificate in the repository. The two roots can never be merged; each record set can never gain a member, because both are closed in both directions.

**Reserved.** A third scope word means a third file. That is the shape, and it is already load-bearing.

### R-10 — The authorities envelope, and `keys` as a JSON object

**Shipped now.** Envelope: `{format, keys}` (adapter root), and the same `{format, keys}` shape for the contract root. `keys` is a JSON OBJECT even when it holds one member. A numeric-only key id cannot appear in either file at all, even though the contract grammar accepts one as a string (see the matrix): both roots refuse a non-string map key by name.

**Why it cannot change.** PHP decodes a numeric JSON object-map key as an integer, so a numeric identity would compare unequal to the string the signed document carries — refusing it is what makes the map key and the `key_id` inside the statement the same value. An empty PHP array canonically encodes as `[]`, which both readers refuse, so the object cast at write time is part of the wire, not a nicety.

**Reserved.** A revocation list (R-15) cannot be added to this envelope: its key set is closed. It needs a new `format` value.

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

### R-14 — Expiry exists on exactly one surface, and there is no clock skew allowance

**Shipped now.** Contract attestation: `expires_at` is mandatory, grammar `Y-m-d\TH:i:s\Z` (UTC seconds, checked at both ends so "expired" is never a parse accident), compared against `$now ?? time()` and refused at `>=`. Adapter certification: NO expiry vocabulary exists at all — checked by grep, not asserted. Recovery: `claim_expires_at` bounds a claimant epoch, never a signature.

**Why it cannot change.** An expired attestation REFUSES; it never silently becomes an unsigned one, because a silent downgrade would make a stale claim indistinguishable from a fresh one at every consumer. There is no skew tolerance in either direction: a wrong operator clock refuses rather than accepts, which is the safe failure and is now the behaviour holders depend on.

**Reserved.** Adding expiry to an adapter certificate is a statement member (R-06) and therefore a new format, not a field. A future skew allowance would have to be a REFUSAL widening, which no deployed verifier would apply to an artifact it already holds.

### R-15 — Revocation is one status word per key, and nothing else

**Shipped now.** Both roots: `status` is `trusted` or `revoked`, per KEY, in the authority file. There is no per-certificate revocation, no serial number, no revocation list, no timestamp — checked by grep across all three signing files. A revoked site key still verifies inside an already-frozen snapshot, because frozen verification reopens no mutable site file (AdapterCertification.php:976-999); a revoked platform key stops verifying frozen snapshots immediately.

**Why it cannot change.** Revoking a key revokes EVERY artifact it ever signed, retroactively and all at once — there is no way to revoke one certificate, and holders have no channel to learn that a key moved except by re-reading the root. Operators sign under per-adapter keys or accept that blast radius; that trade is fixed the moment a second adapter is signed under one key.

**Reserved.** A revocation list needs a new authorities `format` (R-10). Per-certificate revocation needs an identifier the statement does not carry (R-06), so it is a v3 statement type, not an addition.

### R-16 — One algorithm, no negotiation, and canonical base64

**Shipped now.** Both roots require `algorithm: ed25519` literally; key material is 32 bytes, a signature is 64. Base64 is checked by round-trip equality (`base64_encode(decode(x)) === x`), never merely by decodability.

**Why it cannot change.** The `algorithm` word is inside a signed record, so it is already covered by a signature that only Ed25519 can produce. A v1 agent has no second verifier to negotiate with, so agility could only ever be forward-looking: today's artifacts stay Ed25519 for their whole life. Round-trip base64 exists because a non-canonical encoding of the same key bytes is a second spelling of one identity, and the map key would not match.

**Reserved.** A second algorithm is a second record shape and therefore a new authorities format (R-09/R-10), verified beside this one.

### R-17 — `id_kind` is a flat, unprefixed namespace

**Shipped now.** The engine owns exactly `post`, `term`, `tt`, `user` (ref), `post`, `term`, `tt` (token) and `post`, `term`, `term_taxonomy` (ledger); every other legal value is an `id_kind` a pinned manifest declared for a table it owns. There is no vendor prefix and no reservation mechanism.

**Why it cannot change.** Captured state and `duo_map` rows embed the BARE kind, so a prefix rule introduced later would have to rewrite every token in every branch of every site — the one migration this product cannot perform, because the branches are the customer's data. Two adapters that pick one name collide with no arbiter.

**Reserved.** A convention (a vendor-shaped name) can be recommended to authors at any time; a RULE cannot be introduced without invalidating existing captures.

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
| `agent/src/Adapter/AdapterCertification.php` | `authorityKeys()` | `$label` | `format`, `keys` |
| `agent/src/Adapter/AdapterCertification.php` | `validateAuthorityRecord()` | `$label` | `adapter_names`, `algorithm`, `public_key`, `scope`, `status`, `trust_tiers` |
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

`php tools/wire-surface.php --check` proves five things and refuses the run rather than
printing a register it cannot stand behind:

1. **Every value above is the shipped value.** The document is rebuilt from the code and
   byte-compared; a moved constant, a renamed key, a widened grammar or a reworded refusal
   fails with the first differing line named.
2. **No signed surface is missing.** Every `sodium_crypto_sign_detached()` call site in
   `agent/`, `cli/` and `recovery/` is in a file this register covers — 3 today.
3. **No unregistered domain exists.** Every `duo-…-signature/vN` literal in those trees is
   one of the domains in §1, and neither is a prefix of the other.
4. **The two reserved absences are still absences.** `AdapterCertification` carries no
   expiry vocabulary and no signing file carries revocation-list vocabulary (R-14, R-15).
5. **The rollback signature really is domain-free.** A signature is minted and verified
   against the unprefixed canonical payload at generation time (R-03).

What it does not prove: that the decisions are *right*, that any artifact in the field was
signed under these exact rules, or that a holder's verifier implements them. The rationale
halves of §2 are prose, reviewed by a human, and the register is only as good as the review
that put them there. What the checker guarantees is narrower and is the thing that rots
first: the register cannot go quietly out of date.
