# Enrolling a key in the platform trust root

**Read this when** you are about to put a key into
`manifests/capabilities/adapter-authorities.json` — or rotate one, or burn one
after a compromise — and you need to know what that key will be able to do, what
your signature on it actually asserts, and what it costs to take it back.

This is the operator-and-reviewer half of
[spec/repo-format.md § v3.7 and § v3.8](../../spec/repo-format.md). The spec says
what the wire is; this page says what you run, what you must establish before you
run it, and what happens to a fleet when you do.

---

## Where this stands today

`manifests/capabilities/adapter-authorities.json` is
`{"format":"duo-adapter-authorities/v1","keys":{}}` and **no key has ever been
enrolled**. Issuing the first one is gate **G4**'s decision, and G4's conditions
are enumerated with their current truth values [at the bottom of this
page](#the-g4-checklist-what-is-still-missing) — two of them are not met, and one
of those two cannot be met by anybody inside this repository.

Everything below is therefore a **ceremony that has been rehearsed, not
performed**. It is rehearsed end to end on every run of
`sandbox/tests/offline/adapter/regress_platform_authority_population.php`, over
deterministic fixture keys, in a scratch manifest library — through the shipped
producers (`scripts/adapter-certification.php`) and the shipped reader, never a
second implementation. That suite re-asserts the shipped file is still the empty
registry, byte for byte, before it does anything else.

**No external vendor has been vetted.** `acme-*` in the rehearsal is a fixture
namespace and its key is a seed checked into the suite.

---

## The vetting posture

### What the engine binds, and what it does not

The machinery is strong about the things it can check and says nothing at all
about the one thing operators most often assume it checks.

**Bound, cryptographically, by code a site runs:**

| Binding | Where |
|---|---|
| A key id to its own key material — `<label>-<first 12 hex of sha256(public key)>` | § v3.7 change (1); a squatted or misleading id is unrepresentable |
| A record to what it may certify — namespace pattern, tier set, validity window | § v3.7 changes (2)(3) |
| The whole registry to a key inside it | § v3.7 change (4): the envelope signature; an unsigned or partially-edited populated root is refused by the reader **and** by `make release-gate` |
| A certificate to the *identity* of the authority record it was signed over — everything but `adapter_names`, `trust_tiers`, `not_before`, `not_after` | § v3.7 change (5); the record itself rides inside the signature, which is what makes a site-rooted certificate re-verifiable on the frozen path |
| The two scope lists, judged **live** against the record installed now rather than through the signature | `assertAuthorityScope()`; narrowing a key out of an adapter still refuses by name |
| A delegation to its delegator's live record | § v3.8: narrow-only namespace, tiers **and** window; depth exactly 1 |

**Not bound, by anything, ever:** a key to a real-world party. There is no field
in the record for a legal entity, a domain, a wp.org account or a human, and
adding one would not help — a self-asserted string inside a document the same
party signs proves nothing it did not already assert.

> **The honest answer: the only thing that binds a key to a real-world party is
> the reviewer who enrolled it.** The record carries their decision, not their
> evidence. Every downstream refusal is exact and mechanical; the one judgement
> at the root of all of them is a human one made once, out of band, and not
> written into the file.

That is a deliberate posture and not an oversight, for the reason § v3.7 gives
about scope: a trust root that pretended to attest identity would be asserting
something no verifier could check, and an unverifiable assertion inside a signed
document is worse than an absent one because it reads as verified.

### What a reviewer must establish before enrolling

Because the file records the decision and not the evidence, the evidence lives in
the review that precedes it. At minimum, and recorded wherever that review is
kept:

1. **Who the party is**, well enough that revoking them is a conversation you can
   have and not an email into a void. A named responsible human and a security
   contact who can be reached during an incident.
2. **That the party controls the namespace they are claiming.** The grant is
   `<vendor>-*`, so enrolling `acme-*` hands out every adapter name under that
   prefix, including names nobody has thought of yet.
   Two guards exist downstream of you, and **neither one protects this step**.
   A *delegation* may not grant a namespace reaching an adapter name the shipped
   library reserves — refused by name, because out of tree that name is the
   reviewed OVERRIDE and inherits the shipped adapter's interpreter, regenerator
   and provider grants — and a delegation may only ever narrow its delegator.
   **The reserved-name guard deliberately does not apply to the record you are
   writing**: the exemption belongs to the reviewed root, precisely because a
   reviewer is expected to have made this judgement, and it is not inherited by
   what that root delegates. So whether `acme-*` is theirs is your call and
   nothing downstream will second-guess it.
3. **That the party controls the key**, demonstrated by a signature over a
   reviewer-chosen challenge and not by them saying so.
4. **A rotation cadence, agreed in advance**, and a window that expresses it. The
   window is mandatory at record v2 precisely so that an abandoned key stops on a
   date rather than on somebody noticing.
5. **What tiers they get.** `trust_tiers` is the narrowest list that lets them do
   the job. A tier is easy to add later (adding one is invisible to every issued
   certificate — see the rotation section) and expensive to take back.

### What a certificate under an enrolled key does and does not claim

| Word an operator sees | What it means | What it does not mean |
|---|---|---|
| `site_signed` | The operator's own root, **or a vendor key delegated into it**, vouched for this adapter in this one repository | Not a Duo endorsement, and not an exercise: `sign-site`'s evidence is the loader's grammar verdict plus a stated reason |
| `third_party_signed` | A key in the **platform** root signed a reviewed-exercise bundle: a conformance run happened and its results are inside the signature | Not that Duo reviewed the adapter's behaviour, and not that the platform vetted the plugin |
| `signed_unpinned` | A valid signature the repository has not reviewed into its pin | Certification is an elevation the pin gates; an unpinned signature is never elevated |

A **delegated** key resolves under trust root `site` — § v3.8 deliberately spends
none of R-13's reserved third `trust_root` value — so a vendor certifying under a
delegation projects `site_signed`, not `third_party_signed`. That is the grammar
working. `third_party_signed` requires a certificate minted directly under an
enrolled platform key from an exercised bundle, which is why G4 keeps "a third
party has produced an exercised bundle" as its own separate condition. Both words
are driven from one enrolled root in the rehearsal suite, so the difference is
measured rather than argued.

---

## The enrollment ceremony

Rehearsed end to end by `regress_platform_authority_population.php` steps 1–6.

### 1. Mint the platform root key

An Ed25519 keypair, offline, on hardware you control. The key id is not free
text: at record v2 it must end in the first 12 hex characters of
`sha256(public key)`, which is the same default `duo adapter keygen` already
emits. The label in front of it is yours.

### 2. Write the record

```json
{
  "adapter_names": ["acme-*"],
  "algorithm": "ed25519",
  "not_after": "2028-01-01T00:00:00Z",
  "not_before": "2026-01-01T00:00:00Z",
  "public_key": "<base64 ed25519 public key>",
  "record_version": 2,
  "scope": "site_adapter_certification",
  "status": "trusted",
  "trust_tiers": ["declarative_manifest"]
}
```

Into `keys` in a `duo-adapter-authorities/v2` document. Both window ends are
mandatory at v2, they parse strictly as `Y-m-d\TH:i:s\Z`, and `not_before` must
be strictly before `not_after`.

### 3. Sign the envelope

```
php scripts/adapter-certification.php authorities-sign \
  --authorities=manifests/capabilities/adapter-authorities.json \
  --authority=<key-id> --secret-key-file=<0600 file>
```

**A populated root that is not signed is not a trust root.** The shipped reader
refuses it — *"adapter certification authorities must contain exactly format,
keys, signature"* — and `make release-gate` admits exactly two states for this
file: the empty v1 registry byte for byte, or a v2 document that verifies through
the shipped reader. The producer validates every record before it touches the
private key, so a registry that could not be read can never be signed.

### 4. Delegate to the vendor

The platform root moves on an agent release, so enrolling every vendor there
makes review the bottleneck for the whole ecosystem. A **delegation** moves that
decision once: a platform key grants a narrower namespace, tier set and window to
a vendor key, and the vendor installs it site-side.

```
php scripts/adapter-certification.php delegation-sign \
  --statement=<statement.json> \
  --authority=<platform-key-id> --secret-key-file=<0600 file>
```

The output object goes into the site repository's `adapters/delegations.json`.
Verification chains **exactly one level**: a delegate may not delegate, and that
refuses by name rather than by failing to resolve a key.

### 5. The vendor certifies, in one repository

```
duo adapter certify <site-repo> --name=<adapter> --key-id=<vendor-key-id> \
  --secret-key-file=<0600 file> --reason=<what was verified> --pin
```

`--pin` matters: certification is an elevation the repository pin gates, so
without a pin binding both `source: "site"` and the certificate-derived digest
the adapter reports `signed_unpinned`.

### 6. Confirm what an operator will see

```
duo adapter doctor <site-repo>
```

It prints the word from the table above, the principal that vouched, and — if a
revocation document is installed and inert — the row saying so.

---

## The rotation ceremony

Rotation is two different operations with two very different blast radii, and
conflating them is the mistake this section exists to prevent.

### Renewing a window is free

`authorityIdentity()` — the value a certificate binds — drops exactly
`adapter_names`, `trust_tiers`, `not_after` and `not_before`. So **extending a
window, widening a namespace or adding a tier is invisible to every certificate
already issued.** Edit the record, re-sign the envelope, ship. No re-certify, no
re-pin, no recompile. This is measured, not assumed: the rehearsal suite's
second-enrollment step widens the enrolling key's namespace, adds a tier **and**
renews its window in the same change, and asserts that a live certificate under
it keeps verifying with its pinned `record_sha256` unmoved.

**Narrowing is not free, and that asymmetry is the point.** The two scope lists
are dropped from the *identity* binding but still enforced **live** against the
record installed now, so removing an adapter name or a tier stops that key
certifying it immediately, by name. Widening is a decision nobody downstream has
to re-approve; narrowing is a decision that takes effect at once. Shrinking a
window is the same shape: an issued certificate keeps verifying until the clock
crosses the new `not_after`, and then it withdraws.

### Rotating key material is not

New key material is a new identity — the id is fingerprint-derived — so it is a
new record, and the old one has to be retired deliberately.

1. **Mint the new key** and enroll it **beside** the old one, both `trusted`.
   Adding an unrelated key re-signs the whole document (the envelope signature
   covers `{format, keys}`) and still moves nothing for anyone: that is the
   second-enrollment invariant, and it is what makes enrollment cadence
   independent of release cadence.
2. **Re-issue** under the new key everything that must outlive the old one:
   `duo adapter recertify <site-repo> --secret-key-file=<f>` for site-rooted
   certificates, re-signed delegations for vendors.
3. **Let the old key expire.** Prefer expiry to revocation for a planned
   rotation: expiry is scheduled, dated and understood, and it withdraws claims
   on its own date through exactly the same typed signal.
4. **Remove the record only after step 2 is complete.** Deleting a key is an
   identity move: certificates signed under it stop verifying immediately, and
   *"authority key '<id>' is not installed"* is a whole-source refusal rather
   than a withdrawal.

Flipping `status` to `revoked` inside the record is the third option and the
weakest one: it works, but it moves on the **agent release** cadence, because
that file is byte-checked by `make release-gate` and travels inside the adoption
archive. Under compromise that is the wrong latency, which is what the next
section is for.

---

## The compromise ceremony

**Run this when key material is disclosed, not when a grant merely needs to end.**

The typed revocation document is a different thing from the `status` word in
three checkable ways: it ships **absent** and its absence means "nothing is
revoked"; it is **not** byte-checked by `make release-gate`, so updating it moves
no gated byte and needs no agent release; and it is **self-authenticating**, so
the same bytes produce the same verdict from any path a courier put them on — a
cron over plain HTTP, a configuration run, an incident responder's paste. The
trust comes from the signature, not from the channel.

### The steps

1. **Name the right subject.** An entry binds `fingerprint` = `sha256(public
   key)`, never the key id, because an id can be re-minted over new material and
   material cannot be re-minted under an old id.
   **Name the DELEGATE's own fingerprint, not only its delegator's.** Revoking a
   delegator reaches every live scan at once, but a frozen snapshot holds no
   repository, so it reads no `adapters/delegations.json` and the delegate's
   record is the one inside the signature. An incident response that must reach
   **promoted** sites names the delegate.
2. **Sign it with a platform key that is not the compromised one.**
   ```
   php scripts/adapter-certification.php revocations-sign \
     --statement=<statement.json> \
     --authority=<platform-key-id> --secret-key-file=<0600 file>
   ```
3. **Courier it** to `capabilities/adapter-revocations.json` inside each site's
   manifest library. Any channel; the signature is the integrity.
4. **Expect the measured consequences below**, and plan the remedy before you
   send it rather than after.
5. **Stand down** by removing the document once the affected keys have been
   re-issued and every certificate under the burnt fingerprint is gone. Absence
   means "nothing is revoked", so leaving it installed forever is legitimate and
   removing it is a decision.

### What the drill measured

`regress_platform_authority_population.php` step 9 runs this end to end on
WP-1.4's nine-site rehearsal fleet — three certified sites, one of them a
promoted site verifying from a frozen snapshot — and re-measures on every run.
The numbers below are one run on developer hardware; the suite prints its own and
asserts only the ordering, the reachability and a loose order-of-magnitude bound,
because pinning a millisecond would be pinning the machine.

| Measurement | Result |
|---|---|
| Document install | ~0.2 ms |
| Live-path site, landing → claim withdrawn | ~1 ms |
| Frozen-path (promoted) site, landing → claim withdrawn | ~3 ms for the whole fleet walk |
| Agent releases required | 0 |
| Restarts, cache expiries, or second documents required | 0 |

**What that number is not.** It is the *agent-side* cost: one document write plus
one signature verification at the next resolution. **The courier interval is not
measured and is not claimed** — how long your cron, configuration run or incident
paste takes to land the document on every host is your SLO, and until it lands,
the compromised key is still trusted there. That interval, not the engine, is
what your incident plan has to shorten.

### What a revocation costs the sites it reaches

Also measured by the drill, and the reason the ceremony does not end at step 3:

- **The claim is withdrawn, the site is not bricked.** The affected adapter falls
  to `uncertified` — exactly as unvouched-for as one nobody ever signed — and the
  repository still loads. That is a typed signal (§ v3.8, G2-FIXES C3), and it
  matters: untyped, revoking a key to protect the fleet took down every promoted
  site holding a certificate under it, which is the shape that makes an operator
  hesitate to revoke.
- **The blast radius is the fingerprint, not the fleet.** A site certified under
  a different key does not move one identity byte.
- **But it is not free.** The withdrawn adapter's digest moves, and the site's
  `manifest_hash` with it — the certificate is folded into the row
  `manifest_rows()` hashes, so removing it is a content change by AGENTS.md rule
  2's own definition. The measured consequence: the **compiled artifact the site
  was holding refuses** with `compiled_artifact_manifest_mismatch`.

So the remedy is the flag day's own step 5/6 arriving in the middle of a security
incident, per affected site: **re-key → re-certify → re-pin → recompile**. The
repository keeps loading on a stale content pin while you do it — a `source:
"site"` pin on an adapter that is no longer certified is the documented
edit-then-uncertified concession — which is precisely what lets the remedy
command run on the site that needs it.

### Residuals, stated rather than softened

- **`duo adopt` overwrites the revocation document, and the erasure is silent.**
  The adoption archive is `agent manifests recovery`, so re-adopting an agent
  replaces the manifest library and takes this file with it. Absence means
  "nothing is revoked", so a site that lost the document reads exactly like one
  that never had it: the burnt keys quietly trusted again, with no refusal and no
  row anywhere. Re-install it after every adopt, or point `DUO_MANIFESTS_DIR` at
  a library the adoption tar does not overwrite.
- **A revocation signed by a key the site's platform root does not carry is
  inert** — reported by `duo adapter doctor` and not obeyed. Before any
  enrollment, that is the only state a correctly-signed revocation can be in, and
  the drill records it first: revocation capability is something enrollment buys,
  not something an agent has.
- **Approaching-expiry is not yet a fleet-health row.** § v3.7 deferred it to the
  first enrollment as "the first moment it has a subject". Real enrollment is
  still gated at G4, so the subject set is still empty and the row is still
  deferred — it is named here so it is not lost, not claimed here as delivered.

---

## The G4 checklist: what is still missing

G4 is the external-author admission gate — the trust point of no return. It opens
only when **all eight** conditions hold. Truth values as of this page's commit:

| # | Condition | Status | Evidence / what is missing |
|---|---|---|---|
| 1 | Phase 4 shipped; certificate and authority wire formats frozen per the irreversibility register | **Partial** | WP-4.1/4.2/4.3/4.4/4.6/4.7/4.8/4.9/4.10/4.11/4.12 merged; the register's 28 rows (R-01…R-28) are byte-checked by `make release-gate` via `tools/wire-surface.php`. **WP-4.5's per-subject registry addressing is specified and not implemented** — `evidence_pins.registry_sha256` is still the whole document (spec § v3.4: "the ADDRESSING, no") |
| 2 | WP-4.8's identity-only platform binding proven by the second-enrollment regression | **Met** | `regress_site_adapter_certification.php` (a second adapter on one key) and `regress_platform_authority_population.php` step 7 (a second, unrelated vendor, with the enrolling key's namespace, tier set and window all moving in the same change) |
| 3 | Out-of-band revocation channel exists, and a revocation executed end to end on WP-1.4's fleet with propagation latency measured, including the frozen-path vendor-key case | **Met** | `regress_revocation_reachability.php` (the channel and the frozen-path vendor key) and `regress_platform_authority_population.php` step 9 (the drill on the fleet, latency measured and printed on every run) |
| 4 | Expiry exercised against an abandoned-key fixture, with its clock source and implausible-clock posture written and tested | **Met** | `regress_authority_record_v2.php` — refusal *at* `not_after` with `>=`, no skew allowance, the host's own wall clock named and printed, and the implausible-clock test ordered *first* so a backwards clock cannot resurrect a retired record |
| 5 | A written enrollment vetting posture and a platform-root rotation/compromise ceremony | **Met** | This page |
| 6 | Phase 3's closures live: lint refuses for uncertified out-of-tree adapters and is type-aware; effect scoring reporting with a published false-positive rate on the reviewed 16; receipts independently checked | **Met** | WP-3.1 (`LintTrustGate`), WP-2.4 (type-aware, recorded `LintEnvironment`), WP-3.2 — published baseline **0.0000 over 1595 scored (adapter, surface) judgements**, pinned by `regress_effect_declaration_coverage.php` — and WP-3.3 (independently checkable provider receipts) |
| 7 | A third party has produced an exercised bundle that verifies at distance from an evidence repository that is not duo-wp | **NOT MET, and not meetable here** | The adapter test kit ships (WP-2.6), so the kit half exists. The condition is about a *third party* actually doing it, and no third party exists in this repository. WP-5.2 (reviewer-tier evidence) has not shipped. **No fixture can satisfy this one, and none in this program pretends to** |
| 8 | WP-5.4's graded quality axis exists, so competing third-party adapters are comparable at the point of selection | **NOT MET** | WP-5.4 has not shipped. WP-2.8's graduated `outside_version_range` verdict is a different axis (version-range evidence, not adapter quality) and does not satisfy this |

**Two conditions are unmet, and the ordering inside the gate is not negotiable.**
Condition 7 is the one that cannot be closed from inside this repository at all —
it is the whole point of the gate, since a trust root that has never been used by
anyone outside the project has not been tested by the thing it exists to
withstand. Until then this file stays `{"keys":{}}`, and every mechanism above
stays a rehearsal.

---

## The evidence behind this page

| Claim | Where it is proven |
|---|---|
| The shipped trust root is still the empty v1 registry, byte for byte | `regress_platform_authority_population.php`, first assertion on every run |
| An unsigned populated registry is refused by the shipped reader | same suite, step 1 |
| The reviewer verbs sign a registry, a delegation and a revocation the shipped reader then accepts | same suite, steps 2–4; `regress_authority_delegation.php`; `regress_revocation_reachability.php` |
| A delegated key projects `site_signed`, an enrolled platform key projects `third_party_signed` | same suite, steps 5–6 |
| Growing the trust root moves nothing for an issued certificate; moving a key's identity still refuses | same suite, step 7 |
| A grant refuses outside its namespace, after expiry, and after its delegator is revoked | same suite, step 8 |
| The revocation drill on the rehearsal fleet, with latency and blast radius measured | same suite, step 9, and `sandbox/tests/offline/adapter/revocation_drill.php` |
| The v2 record grammar: fingerprint ids, the mandatory window, the namespace pattern, the envelope | `regress_authority_record_v2.php` |
| The delegation refusal matrix in full — chain depth, narrow-only, site-key delegator, id collisions | `regress_authority_delegation.php` |
| The typed revocation channel's three installed states, and the preserved operator-own-key asymmetry | `regress_revocation_reachability.php` |
| `make release-gate` admits exactly two states for the authorities file | `tools/wire-surface.php` gate 6; `tests/Tooling/WireSurfaceTest.php` |
