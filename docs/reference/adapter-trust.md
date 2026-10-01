# Site adapters, certification, and trust

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

## Site-installed adapters and external certification

A site may install an additional, data-only adapter at
`adapters/<name>.json`. Names are canonical lowercase ASCII slugs; the file
basename and manifest `name` must be identical, refused at load with the same
ambiguous-identity sentence the shipped library gets. This source overlays the
shipped library, and shadows a shipped name only when the repository says so
explicitly (see [Overriding a shipped adapter](#overriding-a-shipped-adapter)).
It cannot supply an interpreter,
regenerator, manifest-owned provider, trust root, disposition, or other PHP.
Plugin-owned providers remain valid because their executable identity is the
installed, active, version-bounded plugin and the ordinary provider
negotiation/receipt contract—not the site manifest.

Without a certificate the adapter loads, captures and plans, and is visibly
`uncertified`; readiness and host promotion remain blocked. **Apply is blocked
with them on any repository `wprism init` created**, and that is worth reading
twice because the uncertified row's remediation used to say otherwise: `wprism
init` writes a managed-baseline code revision into the compiled artifact, so
`wp wprism apply` on a target refuses `code_revision_stale` until `wprism deploy
<env>` has run — and `wprism deploy` is host promotion, which the same
`uncertified` state blocks. So an uncertified adapter is a **capture-and-plan**
adapter, not a deployable one.

For bounded adapter-authoring evidence, a **new content-only repository that
has never declared `code`** can exercise ordinary Apply with the exact plugin
already installed and active on both sites. It does not prove code deployment,
activation lifecycle, promotion or production readiness. Never strip the code
descriptor from an initialized repository to manufacture this premise.

Use the actual experimental package for that lane. An explicit library object
passed to a parent handler is not inherited authority for its fresh verifier:
`ApplyRequestCoordinator::verify_canonical()` re-proves the frozen policy
against the trusted shipped library. The WPForms `4756ad35` native run reached
that guard after authored writes/rebuild and correctly refused its private
candidate provenance. Place the reviewed provider in its capsule's `package/`,
retain experimental/non-readiness status, recompile/re-pin the changed identity
and use ordinary commands. Do not add a path selector or weaken provenance
verification for a fixture. Retain private failure diagnostics before teardown;
a post-mutation refusal is not rollback or successful Apply evidence.

There are two ways to certify one, and which you want depends on **whose
approval the certificate represents**.

> **Which trust root do you need?** Everything on this page — including the
> section right below — is the **site trust root**: `adapters/authorities.json`
> lives inside *your own* site repository, `wprism adapter certify` populates it,
> and it needs no gate, no vendor review and no key beyond one you mint
> yourself. It is fully shipped and is almost certainly what you want if you
> are authoring an adapter for your own site's plugin.
> [trust-enrollment.md](../guides/trust-enrollment.md) is a *different* file:
> `platform/adapter-library/capabilities/adapter-authorities.json`, the **platform** trust
> root this project alone can populate, gated on G4 and, as of this page,
> still `{"keys":{}}` — nobody has been enrolled there yet. Read
> trust-enrollment.md only if you are the platform reviewer vetting a *third
> party* to sign under the agent's own key; skip it entirely for your own
> site's certificate, which the section below covers completely.

### Your organization's own approval (`wprism adapter certify`)

This is the path for an adapter you authored for your own site. The product
spec calls the result *Site-certified*: "customer-organization approval through
WPrism's certification protocol, explicitly not a WPrism endorsement". You hold the
key, you sign your own adapters, and the projection names you.

```sh
# 1. Mint the organization key. ONCE, and never inside a site repository —
#    a site repo is committed and published, so a key in one is a published key.
wprism adapter keygen --out=~/.wprism-keys/acme-org.key
#    key-id:     site-1a2b3c4d5e6f
#    public-key: <base64>

# 2. Certify the installed adapter and write the pin in one step.
wprism adapter certify <site-repo> --name=<name> \
  --secret-key-file=~/.wprism-keys/acme-org.key \
  --reason='Acme reviewed this adapter against its own catalog schema.' --pin
```

`certify` does five things and prints what each one produced:

1. Registers the public key in the site's own
   `adapters/authorities.json` — the **site trust root**, in exactly the
   shipped `wprism-adapter-authorities/v1` grammar. Keys there are trusted only
   for adapters in that repository. A key present in both the shipped file and
   the site file: the shipped record wins.
2. Runs the engine's real manifest validators over the adapter. A manifest the
   engine will not load is never signed.
3. Signs `adapters/certifications/<name>.json` — the bundle is derived and
   built by the engine, never by hand, and never reaches disk. What it may
   claim is [What a site-rooted certificate may
   prove](#what-a-site-rooted-certificate-may-prove).
4. Immediately verifies what it just wrote, through the live verifier.
5. Prints the `{digest, name, source}` pin object; `--pin` writes it into
   `site.wprism.json`.
6. With `--pin`, opts the site into the post types and taxonomies the adapter
   declares authored — the same reading `wprism init` applies to an adapter
   selected during init — and prints every rule it wrote:

   ```
   scope: wrote 1 authored scope rule(s) for surface(s) this adapter declares and site.wprism.json had not decided
     + policy.scope.post_type.acme_item = {"class": "authored"}
   ```

   It writes only where `site.wprism.json` had decided nothing. A surface your
   `policy.scope` already records — including the `{"class": "runtime"}` that
   `wprism init --allow-unmanaged-plugins` writes for an unmanaged plugin's
   rowful types — is a **site decision, and site policy always wins**, so the
   pin leaves it exactly as it is and says what that costs:

   ```
   scope: 1 surface(s) this adapter declares stay LOCAL — site.wprism.json already decided them, and a recorded site rule outranks every manifest
     ! policy.scope.post_type.acme_item = {"class": "runtime"} — capture will skip post_type acme_item
     to adopt them anyway: wprism adapter pin <site-repo> --name=<name> --adopt-scope
     to decide one on the site: wp wprism classify --repo=<repo> --set='scope:post_type:acme_item=authored'
   ```

   `--adopt-scope` is deliberately a second, explicit act. `wprism classify` and
   `wprism init --allow-unmanaged-plugins` write byte-identical rules and the
   scope grammar carries no provenance key, so nothing can tell your own
   decision from init's record — and a command that guessed would silently
   re-manage a type you meant to keep local.

**What this certificate says, and what it does not.** It says: this
organization's key approves *these exact bytes*, and the engine's validators
accept the manifest's grammar. It does not say the adapter was exercised
against a live site — the signed bundle records `exercised: false` and carries
it onto the claim, so nobody downstream can read `certified` as "somebody ran
it" — and it declares deletion semantics **unsupported**, because a validator
run reviews none. Assess reads `Site-certified` for the surfaces it governs,
prints `certified by <key-id> (site trust root); contract attestation unsigned`
once, and `wprism release`/`wprism promote` admit the adapter through their existing
certified-and-exactly-pinned gate: a valid signature without the exact
`{name, source: "site", digest}` pin stays `signed_unpinned` and blocked.

That `Site-certified` reading does not depend on how a type reached authored
scope. The `policy.scope.<kind>.<name>` rule `--pin` writes is the site's own
classification decision and it wins classification, exactly as "site policy
always wins" says — but deciding a type's class does not un-declare it, so the
adapter remains the surface's declarant. A type adopted by `--pin` and a type
already on the site's flat `policy.post_types` list therefore assess
identically (issue #3504; before it, the first read `Platform-certified`).

**The certificate binds bytes, so an edit breaks it.** Any change to
`adapters/<name>.json` moves the digest; the pin then refuses and the claim
drops back to uncertified. Re-run `wprism adapter certify … --pin` after every
edit. That is the mechanism working, not a bug to route around.

**An agent upgrade can withdraw the claim, and only the claim.** The signed
statement also binds the agent's own platform boundary
(`platform/adapter-library/capabilities/platform.json`) and the certificate wire version, and
both move on an ordinary agent upgrade. When either no longer matches, that one
adapter drops back to uncertified with a reason naming what moved — "its signed
certification binds an agent platform boundary this agent no longer publishes"
— and every other adapter the site pins, shipped ones included, keeps loading
untouched. The remedy is the same one line: re-run `wprism adapter certify …
--pin`, which is runnable in exactly that state. A companion that fails for any
other reason — a bad signature, an authority this agent does not trust, a wrong
binding, a file that is not a certificate — still refuses the whole
`adapters/` source, because none of those is the agent having moved.

Note the asymmetry between those two withdrawals, because it is a real one. The
platform-boundary withdrawal happens only after the agent has verified the
signature, the authority and the binding, so only a genuine certificate can
reach it. The wire-version withdrawal cannot: the root `format` field sits
outside the bytes the signature covers, and a statement written on a wire this
agent cannot parse is a statement it cannot verify a signature over. So anyone
who can write `adapters/certification/<name>.json` can put that adapter into the
uncertified state by editing the `format` of an otherwise valid certificate. It
takes nothing away that deleting the companion file would not — the destination
is uncertified support either way, and no certified claim is ever granted by it
— but it means a wire-version withdrawal is a report about a file, not a proof
about a signer. If you see one on a site you did not upgrade, treat the
companion as edited and look at it, rather than assuming the agent moved.

**A third withdrawal: the authority itself.** The same one-adapter degradation
covers the key that signed. If that key's validity window has lapsed — or if the
platform-signed revocation document names its key material — the adapter drops
back to uncertified with a reason naming the authority ("the authority that
signed its certification is revoked", or "…is outside its own validity window on
this host"), and every other adapter the site pins keeps loading. It is
deliberately not a refusal: an expiry date arriving, or an incident response
burning a key, must not be the thing that takes a site's commands away. Re-sign
under a key that may still certify. This withdrawal sits between the other two
on the authenticity scale: the agent has proved the named key, its fingerprint
and its identity record against the current trust root before it can be raised,
but it has not yet checked the signature — a key that may not certify is not
asked to sign. Treat it the way you treat the wire-version case: a report about
a file, and worth looking at the file.

**The revocation channel is inert until a key is enrolled, and it says so.** The
agent ships `platform/adapter-library/capabilities/adapter-authorities.json` as an empty
registry, so on a stock agent no key exists that could have signed a revocation
document. Installing one anyway is not fatal: the document is REPORTED — `wprism
adapter doctor` and `wp wprism adapter-survey` print "the document is installed and
its entries do NOT apply: this channel is inert until a key that signs it is
enrolled in the shipped trust root" and exit 1 — and nothing is revoked by it. A
document whose signer IS enrolled and does not verify is a different thing
entirely and still refuses. If you are running an incident response through this
channel, check for that row first: an inert document looks exactly like a
working one from the outside.

**Revoking a delegator does not reach promoted sites.** A revocation of the
PLATFORM key that delegated to a vendor invalidates that vendor's grant on every
live scan at once — but a promoted site verifies its certificates from a frozen
snapshot, which holds no repository and therefore reads no
`adapters/delegations.json`. To reach those, revoke the DELEGATE's own
fingerprint. Revoking only the delegator will look like it worked everywhere you
can see and will not have.

**Re-adopting an agent preserves installed revocations.** Operator revocations
live at `WPMU_PLUGIN_DIR/wprism-control/adapter-revocations.json`, outside the
replaceable agent and its embedded adapter library. A legacy flat-library
document must first be copied there byte-for-byte; adoption refuses the cutover
when both paths do not prove equal. There is no runtime library override or
fallback.

**Key custody is yours.** A lost key cannot re-sign. A leaked key can certify
any adapter in a repository whose `adapters/authorities.json` names it. Back it
up where you back up deploy keys; production-grade custody (HSMs, rotation,
revocation workflow) is out of scope for this profile.

### Adopting an adapter's scope across a fleet

The opt-in above rides on the pin, which is right for the site that *authored*
the adapter and wrong for the fleet that consumes it: adding one adapter to N
sites meant N hand edits of `site.wprism.json`. `adopt-scope` is that same opt-in
over a repository **set**:

```sh
wprism adapter adopt-scope ~/sites/acme ~/sites/beta ~/sites/gamma --name=acme-catalog --dry-run
wprism adapter adopt-scope ~/sites/acme ~/sites/beta ~/sites/gamma --name=acme-catalog
```

```
adapter:    acme-catalog
repos:      3

/Users/you/sites/acme
  + policy.scope.post_type.acme_item = {"class": "authored"}

/Users/you/sites/beta
  ! policy.scope.post_type.acme_item = {"class": "runtime"} — capture will skip post_type acme_item

/Users/you/sites/gamma
  = every surface this adapter declares is already in site.wprism.json's authored scope

adopted:    1 repo(s), 1 authored scope rule(s)
settled:    1 repo(s) had already decided every surface
shadowed:   1 repo(s) record a decision this command never overwrites — a recorded site rule outranks every manifest
  to override one, per site: wprism adapter pin <site-repo> --name=acme-catalog --adopt-scope
```

It writes the same `{"class":"authored"}` node through the same writer the pin
uses, so a repository it touches is byte-identical to one the single-repo verb
adopted. Four properties are worth knowing before you point it at a fleet:

- **Scope follows the pin.** Every repository must already resolve the
  adapter; one that does not refuses the *whole* set, unwritten, naming the
  repositories and the `wprism adapter pin` that fixes each. Writing scope for an
  adapter a site never pinned would opt it into types nothing can classify.
- **Write-only-where-absent is unchanged.** A class a site recorded is
  printed and left alone. `--adopt-scope` is refused here on purpose:
  overriding a recorded decision is a per-site reviewed act, and one flag that
  flipped it across a fleet is exactly the multiplied consequence this verb
  exists to avoid.
- **Per-repository atomic.** Each `site.wprism.json` is written whole through the
  same `tempnam`+`rename` the certificate and the pin use. A failure stops the
  walk and reports which repositories were adopted and which were untouched —
  every one of them is one or the other, never half-written.
- **Idempotent.** Re-running is the remedy for any partial run, and a second
  run over an adopted set writes no rule and no byte.

`--dry-run` reports the same plan and writes nothing.

### Promoting a plugin-bundled adapter

An adapter a plugin bundles (`<plugin>/wprism-adapter.json`) can never be
certified where it lives: certification binds `adapters/<name>.json` inside the
signed statement, so no certificate can name a bundled file at all. The
promotion path is to install it as a repository package first — copy it to
`adapters/<name>.json`, run `wprism adapter pin <site-repo> --name=<n>`, then
`wprism adapter certify`. The site copy wins by precedence and the bundled copy
reports as not installed; the plugin stays active throughout and nothing breaks
in between. (Replacing a *shipped* name is a different act with its own rules —
see [Overriding a shipped adapter](#overriding-a-shipped-adapter).)

### A reviewer's approval under the agent-owned trust root

This is the original path and it is unchanged. It is for a reviewer who
exercised the adapter and holds a key the *agent* trusts, and it produces a
richer bundle — real tests, real artifacts, a real evidence repository:

1. Produce a passing `wprism-site-adapter-certification-bundle/v1` scoped exactly
   to `{"kind":"site_adapter","name":"<name>"}` whose bound inputs contain
   exactly the raw `adapters/<name>.json` bytes and whose ratification contains
   exactly one certified disposition for that name.
2. Install the signing public-key record under **one of the two trust roots**.
   Each `keys.<key-id>` record fixes Ed25519, the
   `site_adapter_certification` scope, `trusted` or `revoked` status, exact
   `adapter_names` and permitted `trust_tiers`, and the canonical public key —
   the same six-key grammar in both files:

   - the platform-owned
     `platform/adapter-library/capabilities/adapter-authorities.json`, which
     only this project can fill. A certificate under one of its keys is
     `third_party_signed`, trust root `platform`.
   - **the site's own `adapters/authorities.json`**, held by the customer
     organization. A certificate under one of its keys is `site_signed`, trust
     root `site` — the product's *Site-certified*, which is customer-
     organization approval and explicitly **not** a WPrism endorsement.

   The site record is a living registry: `wprism adapter certify` appends each
   newly certified name (and its tier) to the key's record. A certificate under
   the site root binds the key's *identity* — id, algorithm, public key,
   scope, status, fingerprint, trust root — and the record it was signed over;
   the record's `adapter_names`/`trust_tiers` and its `revoked` status are
   enforced live against the current file on every verification. So certifying
   a second adapter under the same key leaves the first certificate (and the
   digest its pin binds) intact, while rotating the public key under the same
   id or revoking the key invalidates every certificate under it at once. The
   platform record binds whole: that file is reviewed and shipped, and never
   grows under an operator's hand.

   A key id present in both files resolves to the shipped record, always: a
   certificate that claimed the site root for such an id is refused by name.
   `adapters/authorities.json` is reserved inside `adapters/` — it is never an
   adapter, and a malformed one refuses the whole site source, because every
   certificate in the repository is judged against it. Private keys never live
   in the repository.
3. With a mode-0600 private-key file, sign and immediately verify the evidence:

   ```sh
   php scripts/adapter-certification.php sign \
     --manifest-dir=. --repo=<site-repo> --name=<name> \
     --bundle=<bundle-dir> --evidence-repo=<reviewed-checkout> \
     --authority=<key-id> --secret-key-file=<private-key>

   php scripts/adapter-certification.php verify \
     --manifest-dir=. --repo=<site-repo> --name=<name>
   ```

   The only site output is the canonical, path-derived
   `adapters/certifications/<name>.json` envelope. The tool prints a non-secret
   summary, never the key or certificate body.
4. Generate and commit the final source-and-digest pin:

   ```sh
   wp wprism manifest-pin --repo=<site-repo> --name=<name>
   ```

### Certifying under your own root

Steps 1 and 3 above are the REVIEWER's path: a real conformance bundle on disk,
signed with an agent-owned key. Under a site root there is no bundle to
produce, and no step 1 — one command does the whole thing:

```sh
php scripts/adapter-certification.php sign-site \
  --manifest-dir=. --repo=<site-repo> --name=<name> \
  --authority=<key-id> --secret-key-file=<private-key> \
  --reason='grammar verified by the site operator; not exercised'
```

(`wprism adapter certify` is the host verb over the same entry point.) It derives
the ratification from your manifest, runs the **real loader** for the grammar
verdict — an adapter that does not load is refused with the loader's own
message, because a certificate for bytes no command can use is the emptiest
possible claim — builds the bundle in memory, and writes only
`adapters/certifications/<name>.json`. There is no bundle directory to keep:
an unexercised bundle's assets are already inside the signed statement.

### What a site-rooted certificate may prove

A reviewer's bundle states a passing exercise. An operator certifying their own
adapter usually cannot produce one, so the bundle declares what it proves:
`evidence` is `{"exercised": <bool>, "grammar": "ok", "reason": "<text>"}`.
`exercised: false` requires empty `tests` and `artifacts`, is accepted **only**
under a site trust root, and yields an experimental approval-only claim. A
Site-certified claim requires verified exercised evidence. `exercised: true` is the reviewer's shape and
the only one an agent-owned key may sign; `sign-site` refuses an agent-owned
key by name.

The derived ratification claims nothing an unexercised check cannot support:
no `delete` operation, no lifecycle phases, every intent-only table marked
unsupported, and every open-ended `default_class: authored` keyspace recorded
`unsupported` rather than justified. The full wire contract is
[docs/adapter-walk-bundle.md](../adapter-walk-bundle.md).

**If you reviewed more than that, say so in your own words:
`--ratification-file`.** The derivation is a floor, not a ceiling. It writes one
canned sentence on every refusal and leaves `--reason` as your only input, so a
site that genuinely reviewed its adapter's deletion semantics signed the same
document as one that reviewed nothing. Pass `--ratification-file=<file>` and
`certify` signs the disposition **you** wrote — one entry, in the exact shape
`adapter-packages/<name>/package/disposition.json` carries.

> **The file is the BARE entry, not the `wprism-manifest-dispositions/v1`
> envelope.** Write the object below at the file's top level — no `format`, no
> `manifests`, no `profiles`. Those three are the signer's: it owns them so that
> an authored document cannot ratify a second adapter or smuggle a profile
> ([§ v3.17](../../spec/repo-format.md)), which is the same posture as the
> certificate's path being derived rather than declared. Hand it an envelope and
> `certify` says so by name and tells you to pass the value at
> `manifests.<name>` instead. A capsule's `package/disposition.json` is itself a
> bare entry, so a shipped disposition is a copyable starting point as-is.

```json
{
  "capabilities": {
    "deletion_semantics": {"supported": ["post_types.acme_entry"], "unsupported": ["tables.acme_ledger_index"]},
    "entity_sections": ["post_types", "tables"],
    "field_sections": ["option_namespaces", "options", "post_meta"],
    "lifecycle_phases": ["activate", "retire"],
    "operations": ["apply", "capture", "compile", "delete", "deploy", "plan", "recapture"]
  },
  "default_authored_keyspaces": [
    {"table": "acme_ledger_index", "status": "justified", "reason": "<what your review checked, and against which versions>"}
  ],
  "evidence": {"bundle_schema": "wprism-site-adapter-certification-bundle/v1", "tests": []},
  "reason": "<what this organization reviewed, and how>",
  "status": "certified",
  "supported_versions": {"plugin": "acme-ledger/acme-ledger.php", "range": {"max": "3.0.0", "min": "1.0.0"}},
  "unsupported": [
    {"surface": "tables.acme_ledger_intent", "operation": "apply", "reason": "<why this one is not covered>"}
  ]
}
```

The engine judges it with the **same validator it applies to its own reviewed
library** — nothing on that path knows or asks who wrote the bytes. So it wants
a separate non-empty reason on every `unsupported[]` row and every
`default_authored_keyspaces[]` row; it refuses a section your manifest does not
declare, an intent-only table you did not mark unsupported, a version range your
manifest does not carry, a cited test the bundle does not hold, and an entry
that refuses nothing at all. One further rule belongs to this profile: name
**every** surface your manifest declares, under the arm the engine classifies it
in. Narrow a claim with an `unsupported[]` row and its reason, which a reader can
weigh — never by leaving a surface out, which no reader can see.

What does not change: the bundle still records `exercised: false`, `tests` is
still empty, and the claim remains experimental with certification-only gates
blocked. An authored entry is a stronger *argument*, never evidence of a run. And because `wprism adapter recertify`
DERIVES, it reports an authored certificate as a `blocked` row rather than
replacing your claim with the floor — re-sign that one with
`wprism adapter certify … --ratification-file=<your file>`, so keep the file beside
the repository. The rider is
[spec/repo-format.md § v3.17](../../spec/repo-format.md).

Every catalog and diagnostic row carries `trust_root` (`platform` for a shipped
row, `site` or `platform` for a signed out-of-tree one, `null` when nothing
signed) and `principal` (the authority key id), so "certified" always comes
with the name of whoever said so.

A valid signature without that exact `{name,source:"site",digest}` pin is
reported as `signed_unpinned` and remains blocked. A present malformed,
tampered, stale-platform, unknown-key, or revoked-key certificate is a policy
load refusal; it never falls back to unsigned support. The final adapter digest
binds the source manifest plus authority, signed statement, envelope, bundle,
ratification, and platform proof facts, while unrelated shipped adapter
digests and `wprism capabilities --all` remain unchanged.
## Overriding a shipped adapter

A site adapter whose name collides with a shipped one is refused — silently
replacing a reviewed definition is never on offer. The repository can *state*
the replacement instead: pin the name with its source.

```json
{"manifests": ["core", {"name": "woocommerce", "source": "site"}]}
```

That pin selects `adapters/woocommerce.json` for the name. The shipped copy
leaves the loaded set and is reported on every run as `not_installed` with
reason code `shadowed_by_site`, naming the site copy that won; exactly one
definition answers to the name, so the cross-manifest guards see no conflict.

The host verb does both halves in one command: `wprism adapter pin <site-repo>
--name=woocommerce --source=site` writes the `{name, source:"site"}` statement
first (printing `override: site.wprism.json now names the site copy of shipped
adapter 'woocommerce'`), loads the repository with the site copy in force, and
completes the pin with the digest — the same `{name,source:"site",digest}`
object `wp wprism manifest-pin --repo=<site-repo> --name=woocommerce` prints once
the override statement exists. Commit the object it writes.

**An override inherits exactly the shipped executable grants.** The usual
out-of-tree rule refuses an `interpreter`, a `regen_dependency.regenerator`
or a `providers[]` row with `source: "manifest"` in a site adapter, because
that code lives in the agent's own tree. A copy of a shipped adapter carries
those declarations already, and they are the shipped grant repeated: an
override keeps every one that is byte-for-byte what
`adapter-packages/<name>/package/manifest.json`
declares, and may add or edit none. Widening (a second manifest-sourced
provider, one more capability on the inherited one, another adapter's
interpreter) is refused with the override's own remediation — repeat the
shipped declaration verbatim or drop the change. The override therefore
carries the shipped tier its inherited code implies (a `compatibility_shim`
adapter stays `compatibility_shim`; it is not laundered into declarative), and
a site key may certify that tier.

Three things the override deliberately is not:

- A **name-only** pin is not an override. Precedence stays
  `shipped > site > plugin` for every one of them, and the refusal stands.
- An **unreadable** `site.wprism.json` yields no overrides, so a broken policy
  file can never silently swap which definition is in force.
- The site copy never inherits the shipped adapter's reviewed claim. It carries
  the site's own certification words; a signed override reads `Site-certified`,
  never `Platform-certified`.
## Adapters a plugin bundles

A plugin may ship an adapter of its own: exactly one `wprism-adapter.json`, at the
root of its own directory. Only ACTIVE plugins are scanned — activating the
plugin is the operator consent that installs the adapter — and a single-file
plugin, having no directory, cannot bundle one.

Two rules are specific to this source, and both differ from the site source on
purpose.

**Declare the plugin that owns you.** The file name is a constant here, so it
carries no identity: the manifest's own `name` is the identity, and the
manifest MUST also declare `plugin` equal to the exact basename of the plugin
bundling it — the file, not just its directory, since a directory can hold
more than one plugin and only the one you name is what version and activation
checks will ask about. That claim anchors the manifest to the code it ships with, exactly
as a plugin-owned provider's class is anchored to its plugin directory, and it
is what lets a frozen policy rebuild `plugins/<plugin-dir>/wprism-adapter.json`
without reopening the plugin. Because `plugin` is mandatory, the compatibility
contract applies transitively: declare `version_range` too, or the adapter is
refused as unbounded support.

**A name collision with a reviewed adapter is reported, not fatal.** Adapter
sources rank `shipped > site > plugin`. If a shipped or site adapter already
answers to your name, your bundle is not loaded, and it prints on every run as
an installed-but-not-loaded row naming the winner — nothing breaks and nothing
is deactivated. (Two active plugins bundling one name have no such rule
available: both are dropped and the pair draws one `source_collision`
refusal.) Everything else in this source is refused per adapter rather than
whole-directory: a malformed bundle, a bad name, an anchor mismatch, a
`wprism-adapter.json` that is a symlink or a directory instead of a real file, a
plugin directory wprism cannot list (make it readable, or the near-miss check
cannot run and the adapter is refused rather than guessed at), a near-miss
inside the reserved `wprism-adapter*` namespace, or a reach for executable
privilege drops that one adapter and leaves every other plugin's alone. It becomes fatal only if a repository pins
that name, which fails with the refusal's own message.

**A bundled adapter cannot be certified in place**, and no field or companion
file changes that: certification hashes `adapters/<name>.json` and binds
`source: "site"` and that exact path inside the signed statement. So a bundled
adapter is `uncertified` by construction — capture and plan available,
readiness and host promotion blocked (and apply with them, for the reason the
certification section above gives), identical to an unsigned site adapter. To
certify one, promote it:

1. Install the same adapter as a repository package at `adapters/<name>.json`.
2. Obtain a signed `adapters/certifications/<name>.json` (the section above).
3. `wp wprism manifest-pin --repo=<site-repo> --name=<name>`, and commit the
   emitted `{name,source:"site",digest}` pin.

The site copy then wins by precedence and the bundled copy reports as not
installed. The plugin stays active throughout; nothing has to be deactivated
and no command breaks in between. Run `wp wprism adapter-survey [--repo=<path>]`
on the target to see all three sources, since the host-side `wprism adapter`
commands are WordPress-free and cannot reach the plugin directory.

For redacted live proposal evidence, use `wprism adapter-observe <env>
[--out=<local-file>|--format=json]`; its target half is `wp wprism
adapter-observe --repo=<target-site-repo> --format=json`. The host calls the
configured target repository once and accepts only the canonical,
hash-validated `wprism-adapter-observation/v1` projection. It never exposes
target values, IDs, titles, paths, messages, SQL, or credentials; `--out` is
create-only. The embedded adapter-source rows are a bounded projection of
`wprism-adapter-sources/v2`, not a claim to preserve the full
`wprism-adapter-catalog/v2` catalog. This is proposal evidence, not authoritative
`adapter-draft --evidence` input, and it does not certify an adapter or alter
a capability claim.

Normal plugin/provider registration and capability negotiation remain enabled
so the target can report installed runtime facts. Third-party callbacks may
have side effects before or during collection; WPrism itself invokes no provider
action and makes no explicit mutation after observer entry. The document
defers table semantics, apply/rollback, version lifecycle, publication, and
certification.
