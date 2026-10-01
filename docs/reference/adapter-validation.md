# Adapter validation and adversarial preflight

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

## Checking the grammar offline

Almost everything above is refusable without a WordPress anywhere: a manifest is
data, and the validators that read it are the pure half of policy load, which
runs before any target is contacted. `wprism manifest-validate` is that half,
exposed on its own so you can iterate on a declaration in seconds instead of
reinstalling an agent to find out you transposed a letter.

```sh
wprism manifest-validate . --manifest=contact-form-7 --pins=core,contact-form-7
wprism manifest-validate . --pins=core,woocommerce --format=json
wprism manifest-validate . --site=/path/to/site-repo
wprism manifest-validate ./untrusted-adapter-package --no-code
```

It needs no environment, no database, and no docker. The `.` above is the source
tree containing `adapter-packages/` and `platform/adapter-library/`. Every
manifest is loaded on its own first — so one broken file does not hide the
verdict on the other manifests — and then the requested pin set is co-loaded, which
is the only way the cross-manifest guards run at all (one owner per declared
name, overlapping option namespaces, conflicting plugin claims, duplicate
provider ids, duplicate table `id_kind`s). `--manifest` narrows what is checked
individually; `--pins`/`--all` choose the co-loaded set. Name the adapter's
intended composition explicitly during iteration, as the first command does.
With neither flag, or with `--all`, the command deliberately asks whether every
adapter in the library can share one policy; that broader question may refuse
when two adapters declare a supported incompatibility rather than reporting a
catalog defect. A declared
`interpreter` or `regen_dependency.regenerator` is resolved too: the named file
must exist under the declaring package's `runtime/interpreters/` or
`runtime/regenerators/` directory and must
define the contract class.

Refusals are the engine's own, printed verbatim with their exact coordinates
(`manifest 'x' actions[0].args.name …`, `table 'y' … identity.columns …`). A
per-manifest row carries that manifest's file path; the pin-set row carries the
paths of everything co-loaded, because a cross-manifest refusal names manifests
rather than one file. Exit status is `0` when everything is valid, `1` when
anything is not, and `2` for a usage or IO problem.

### Point it only at an adapter library you trust

Resolving a declared interpreter or regenerator means **loading that PHP**: the
file's top level runs when it is `require`d, and its constructor runs when the
class contract is checked. There is no way to answer "does this file define
`\WPrism\Interpreters\Acme` with the right method" without doing that. So this
command is exactly as safe as the library you point it at — which is the same
trust decision running the agent itself makes about its embedded adapter
library, no more and no less. Treat a source library as code, not as
data, and do not run this against a package you would not install.

For the one case that boundary does not cover — a **first look at an unfamiliar
out-of-tree package** — `--no-code` validates every declaration and skips the
code half entirely:

```sh
wprism manifest-validate ./untrusted-adapter-package --no-code
```

Nothing is loaded and nothing is instantiated, so a hostile `interpreters/*.php`
never runs. The trade is real and the report states it rather than implying a
clean bill: the run prints `manifest code: --no-code …` in its header and carries
an explicit not-performed row in the deferred list. A `--no-code` pass therefore
means "the declarations are well-formed", never "this package is fine" — read
the code, then re-run without the flag from a directory you trust.

### `--site`, and why leaving it off can refuse a valid manifest

Three of the guards above are not functions of the manifests alone. They read
the SITE half of policy as input:

- a table declared in `site.wprism.json`'s `policy.tables` extends the legal
  ref/token/ledger **kind vocabulary** exactly as a manifest-declared one does,
  so `"ref": "my_site_thing"` is legal on that site and nowhere else;
- a `policy.options.<name>` rule is the ratified **resolution** when two
  manifests declare one option name differently — the guard skips a name the
  site has already decided;
- a `policy.adapter_claims` row is the operator's **resolution** when two
  pinned manifests claim the same `plugin` (or the same `theme`) with different
  ranges (spec/repo-format.md § v3.13). It names which claim is IN FORCE; the
  displaced claimant's manifest still loads with every other declaration it
  makes intact, and `wprism plan` warns which claim was displaced on every run.
  Without such a row the collision still refuses, exactly as it always did —
  the resolution is opt-in, and it resolves the claim it names and nothing
  else.

Run without `--site`, this command loads with no site policy at all, so any of
the three can refuse a manifest its real site accepts — and the option one's
remediation ("add an explicit `site.wprism.json` policy.options override") is
advice to add something you may already have. Point `--site` at your wprism site
repo (the directory holding `site.wprism.json`) and all three guards get their
real input:

```sh
wprism manifest-validate . --site=/path/to/site-repo
```

Without it, a refusal from any of the three is **annotated**, never rewritten — the
engine's message is printed exactly as it stands, followed by a note saying the
refusal may be resolvable by a `site.wprism.json` this run was not given. The
missing site half is also a permanent entry in the deferred list below, so it is
stated on every run rather than only when it happens to bite.

Two things it is deliberately not. It is **not a gate** — nothing runs it for
you, and passing it is not a certification, a disposition, or permission to
promote. And it is **not complete coverage**: every run, passing or failing,
ends with the list of what it did not check — the site-policy half just
described, plus the checks that genuinely need a live target (live table schema,
`taxonomy_patterns` expansion, installed plugin/theme versions, provider
negotiation, native-action execution, capability evaluation, and lint's live id
cross-reference) — each marked `deferred` and each naming the engine function
that owns it. Read that list as the honest boundary of what just happened.

### The JSON report, for editors

`--format=json` emits the same verdict as a document an editor or language
server can consume directly:

```json
{
  "format": "wprism-manifest-validation/v1",
  "spec_version": 2,
  "manifests_dir": "/path/to/wprism",
  "site": null,
  "code": "resolved",
  "status": "ok",
  "manifests": [{"name": "core", "file": "/path/to/wprism/platform/adapter-library/core/manifest.json",
                 "status": "ok", "message": null}],
  "pinned_set": {"names": ["core"],
                 "files": {"core": "/path/to/wprism/platform/adapter-library/core/manifest.json"},
                 "status": "ok", "message": null},
  "deferred": [{"status": "deferred", "surface": "tables",
                "check": "Snapshot::assert_row_schema() …", "why": "…"}],
  "summary": {"checked": 1, "ok": 1, "error": 0}
}
```

`site` is the resolved `--site` repo, or `null` when the run had none; `code` is
`"resolved"` or `"skipped"` (`--no-code`). A
`manifests[]` row whose `status` is `error` carries the engine message in
`message` and the file it belongs to in `file`; the `pinned_set` row carries
`files`, the path of every co-loaded manifest. A row may additionally carry:

- `pinned_set_note` when the manifest failed *in isolation* but is valid inside
  the requested pin set — the legitimate case of an adapter naming another
  adapter's declared `id_kind`, where the repair is a pin rather than an edit;
- `site_policy_note` (on a manifest row or on `pinned_set`) when the refusal came
  from one of the two site-sensitive guards and the run had no `--site`.

### The grammar document

```sh
wprism manifest-validate --emit-schema
```

prints the closed vocabularies, bounded patterns, and native-action argument
schemas as one versioned JSON document (`wprism-manifest-grammar/v2`) — the raw
material for editor completion, a schema-aware linter, or a review checklist.

v2 adds two blocks v1 could not answer, both derived the same way as the rest.
`spec_window` is which `spec_version` integers this engine ACCEPTS, measured by
handing each candidate to the real `validate_adapter_contract()` rather than by
restating its condition, so a widened or narrowed window shows up here with no
edit to the emitter. Read it before you pick a `spec_version`: the engine
accepts N and N-1 (`spec/repo-format.md` § v3.1), an integer outside that window
refuses wholesale and names the window, and an ABSENT or non-integer
`spec_version` gets its own separate refusal — it is not a version, so it is not
outside anything. Declaring a section this engine implements only at a HIGHER
version refuses by SECTION NAME, which is how a format change stages one adapter
at a time instead of arriving as a flag day; the top-level `engine_features`
list (§ v3.2) is the first such section, and a name in it that no engine
implements is refused as unimplemented rather than admitted as forward-looking.
The document's own `engine_features` block is the authoritative, live list —
read `engine_features.implemented` rather than trusting a count written on this
page, because a feature ships by adding an `IMPLEMENTED_FEATURES` row, not by
editing this paragraph. The current entries include `spec-window/v1`, which
claims `engine_features` itself, so declaring it is what lets you declare the
list at all; `attr-id-codecs/v1` claims `attr_id_codecs`, the byte-exact
block-attribute codec; `typed-column-codecs/v1` claims `column_codecs`;
`mixed-column-codecs/v1` admits the measured plain/serialized/NULL container
inside that section without claiming a second top-level key;
`structured-body-refs/v1` claims `body_refs`, a declared path grammar into JSON
post bodies; `body-ref-preserve-type/v1` admits `cast: "preserve"` on those paths
when a native writer stores the same reference as either an integer or a string.
It requires the structured-body feature and leaves ordinary meta/options and
block-attribute casts unchanged. Missing self IDs stay missing; present IDs
must be declared and rebound, not preserved as source-local numbers. The
canonical typed-reference envelope retains the native type beside an ordinary
identity token; exact compiler, lint and Apply validation reject malformed
envelopes without a plugin-owned rewrite. `body-url-rebinding/v1` separately
admits `"url_rebinding": true` on that post type's `body_refs` record and
requires the structured-body feature. It reuses the existing home/uploads/query
text codec, not block/shortcode parsing. Declared reference positions and their
literal sentinels are protected; keys and non-string types stay unchanged.
An omitted flag preserves the reference-only behavior and warnings. A remaining
source URL is still a scoped limitation, not portable support. See
`spec/repo-format.md` § v3.20.
`body-pii-paths/v1` separately admits `pii_paths` on that same body record.
It is a reviewed list of exact scalar-field paths, not whole-body `allow_pii`.
Named first/terminal segments and no recursive descent keep the scope explicit;
intermediate wildcards and list mapping use the existing reference dialect.
Only matched scalar values and their field roles are cleared. Keys, containers,
unreviewed siblings and all secrets remain protected. The same authority is
checked during body capture, final candidate clearance and immutable compilation;
merely declaring the feature does not clear anything. A component-only
`PostCapture` positive is not publication evidence: exercise the complete
candidate and `wp wprism capture`, including an unreviewed sibling and a secret
inside the reviewed path, and prove refusal preserves prior state and identity.
Decide whether each destination is genuinely authored
and portable before reviewing it: an environment-specific address needs an
environment contract, not a privacy exception that copies it to every target.

One post body's complete `body_refs.<type>` record has one owner. Distinct
manifests may repeat a canonically identical declaration, but cannot select
different reference paths, casts, sentinels, URL handling or privacy paths by
pin order—even if their `post_types.<type>.body=json` declarations agree.
Both live policy loading and immutable snapshot loading enforce this before
capture or compilation. Extend the owning capsule; there is no site-policy
body-grammar override or cross-adapter composition rule. Exercise conflicting
and identical declarations in both pin orders when adding a grammar extension.

Mixed option blobs use their existing per-subkey `allow_pii` review, not body
paths. Exercise native values through the real capture guard before ratifying
them: WPForms' `validation-email` is operator-authored validation copy, but its
name triggers the semantic email-field guard even when the value is only a
message and placeholder. Review that exact authored subkey with a source-backed
reason; do not clear the parent blob or reclassify credentials as authored.
Keep unreviewed subkeys target-local and test that secrets still refuse inside
the reviewed field. The capsule's `regress_settings_and_embeds.php` exercises
native settings with hostile excluded siblings and independent secret/PII
controls through `OptionsCapture` and `CaptureSafetyGates`.

A persisted default is not proof that the installed edition can author it.
WPForms Lite 2.0.1.1 registers `gdpr-disable-uuid` and
`gdpr-disable-details` as disabled Pro-only education controls. Its ordinary
General save still writes the absent toggle inputs as `false`. The initial
declaration wrongly treated those observed defaults and existing privacy
readers as portable authority: shared Apply then replaced target-local Pro
residue. The correction is two exact `env` subkey declarations, not a special
case in the option engine. `regress_settings_ownership.php` proves actual
Capture exclusion, mixed-option Apply preservation, recapture, and refusal of
forged authored subkeys. Inspect edition, visibility, enabled state and save
semantics together. Separately identify automatic initialization and native UI
saves: the conditional modern-markup control has both, with different stored
scalar types. Do not unhide a control or spoof a disabled POST field merely to
claim native authoring coverage. Audit environment gates too: on a local
`.invalid` HTTP host, Lite Connect is not registered at all. Pin that actual
form shape instead of forcing the cloud integration to load for the test.

`structured-evidence/v1` claims `declaration_evidence`
(`spec/repo-format.md` § v3.14) — an object keyed by TARGET, each record
`{"evidence": [{source, locator, observation}, …]}` and optionally
`{"answered": [{question, answer}, …]}`, with every member a non-empty string
and every target's HEAD a top-level key the same manifest declares; it is where
a ratified `wprism adapter-draft` proposal's evidence goes instead of being
deleted with the `_draft` sidecar, and `notes` is unaffected and keeps whatever
it already carries; `manifest-provider-runtime/v1` claims no key and moves
manifest-owned identity, capability advertising, dispatch, scoped receipts,
and recovery routing into the engine-owned provider runtime; and
`invalidate-vocabulary/v1` claims no key at all — it
widens a value vocabulary inside a section that already exists, legitimate
under § v3.3's growth rule. A record whose addressed `declaration_evidence` is
later deleted refuses at load, which is the point.

**Before declaring any feature**, verify that the adapter actually uses
the primitive and know that the declaration moves this adapter's manifest bytes,
digest, and pins. Declare `spec-window/v1` with it because that feature admits
the `engine_features` channel itself. The signer classifies feature-claimed keys
from the same roster row that admits them (§ v3.21), so a recognised claimed
section is certifiable and an unknown feature, missing gate, or key with no arm
refuses by name. Value-vocabulary features add no certificate surface of their
own: `mixed-column-codecs/v1` leaves the surface owned by
`typed-column-codecs/v1`, while `manifest-provider-runtime/v1` claims no state.

When adding an engine feature, update the independent literal vocabulary
expectations in `regress_spec_v3_document.php` and `regress_spec_v3_dry_run.php`
alongside its owner and generated API/wire projections. Keep those expectations
literal: deriving them from the new roster would stop checking its exact set.
Run `make regress-spec-v3-document regress-spec-v3-dry-run regress-spec-window`
and `make release-gate` before the full aggregate. New runtime APIs also need
host-load admission/refusal controls; an existing provider-protocol feature
does not automatically promise later SDK methods.

`top_level_keys` is the signer's own closed partition of
manifest top-level keys — the set that decides whether an adapter can be
certified at all — published with the one fact an author most needs about it:
it refuses at signing (`enforced_by`), for every `spec_version`. Since WP-4.3
the manifest **loader** refuses an unrecognised top-level key too, by name,
before any value in it is read, for every accepted manifest version
(spec v3's V3-KEYS rule, `spec/repo-format.md` § v3.3). The recognised v2
`_draft` sidecar is the sole authoring-only load exception and remains
unsignable. Check `top_level_keys.all`
against a new section name regardless of which `spec_version` you are writing:
it is the fastest offline answer, whichever validator would eventually catch
the mistake.

Every set in it is read out of the engine at emission time, never written down
in the emitter. That is the only property that makes it worth trusting: a
hand-maintained copy would keep offering `verbatim` for a release after the
engine stopped accepting it. Vocabularies whose legal values depend on which
manifests are pinned — ref, token, and ledger kinds, which extend by *declaring
a table* — publish the engine-owned base only, named as such; the declared half
belongs to a pin set plus one `site.wprism.json`, not to the engine.

The document also carries a `coverage` field stating what it does **not**
publish, so a consumer never has to infer the boundary: `vocabularies` is VALUE
vocabularies only (the closed sets of legal KEYS are not there — several depend
on a sibling value, so there is no flat set to publish), the sets are
unconditional (`mode: "prevented"` is legal only for mail/http/queue effects,
and no set can say that), `patterns` is the named subset (roughly twenty further
inline PCREs in the engine have no published name), and the pin-dependent
vocabularies publish only their base. Build on it, but build knowing that a
document-clean manifest can still be refused by a rule the document does not
describe — which is what running the validator itself is for.
## Adversarial preflight

Run this before ratifying any draft. Grammar-valid JSON is only proof that the
engine understands the declaration, not that the declaration describes the
plugin faithfully.

1. Create the same semantic fixture on two environments whose post, term, and
   custom-table ids deliberately differ. A symmetric pair with matching ids
   cannot expose a missing reference codec.
2. Inspect every persisted scalar and every nested JSON, PHP-serialized, block,
   shortcode, option-name, and custom-table payload. Search for self ids as
   well as foreign ids; plugins often copy their own row id into a body blob.
   For duplicators/importers, repeat the native action **after** ordinary
   Capture has established the source object's `_wprism_uuid` and ledger
   history. Cloning only before first capture misses copied reserved identity.
   Duplicate Page 4.5.9 copies every metadata key: a native managed-post clone
   reproduced two live owners of one UUID and the next Capture refused. The
   complete native database and canonical tree stayed unchanged by that
   refusal; this is safety evidence, not successful clone support. Retain the
   exact native writer/actor, old and new physical owners, original ledger,
   fresh private refusal cause and complete before/after witnesses. Do not
   delete metadata in the fixture or infer which object deserves a new UUID.
   The shared row-backed guard regression is `regress-snapshot-identity`;
   [the native investigation](../agents/managed-clone-identity.md) records the
   missing authoring sequence and the still-open explicit-repair boundary.
3. Preserve the stored type. A reference written as JSON `"12"` is not
   equivalent to JSON `12`; reject the adapter when the available codec changes
   that distinction.
4. Put a source-home URL inside every portable string-bearing container. Use
   `body: serialized` for serialized post bodies and `plain_data: true` for a
   decoded scalar/array rule with nested strings but no id positions. Both
   re-serialize after tokenization so PHP length prefixes remain correct;
   opaque `verbatim` bytes deliberately do not re-bind. For declared JSON bodies,
   negotiate `body-url-rebinding/v1` and set `body_refs.<type>.url_rebinding`
   to `true`. Prove it composes with the body's declared reference casts and
   literal sentinels through the real compiler, post materializer and recapture;
   an isolated string-codec pass is not evidence of database-boundary correctness.
   Exercise literal operational settings as well as smart tags. WPForms'
   `{admin_email}` fixture does not prove a literal notification destination
   can be captured. When a genuine authored scalar needs reviewed PII authority,
   declare its exact `pii_paths` entry with `body-pii-paths/v1`; retain a hostile
   form default, sibling, map key, container and credential beside the positive
   settings. Both Capture and immutable compilation must refuse those hostile
   cases without altering canonical or native state. Never infer clearance in
   a draft, suppress a detector, or add a plugin-name exception to the engine.
5. Exercise activation, complete the plugin's documented onboarding, then make
   one real admin save, one front-end read, an update, and a deletion before
   declaring the option/table inventory complete. Activation is not proof of
   readiness: some plugins intentionally defer table creation or schema
   upgrades to an admin/API/CLI setup step. Verify the plugin's expected tables
   and readiness marker through its public lifecycle before observation,
   coverage or probe; never manufacture them with raw SQL. Compare the plugin's
   own import/export allowlist when it has one; it is strong evidence for
   portable subkeys, not proof of every other surface. If a reusable harness
   clears uploads with `wp site empty --uploads`, recreate and verify the
   ordinary WordPress uploads root through `wp_mkdir_p` before apply; apply is
   right to refuse a missing or symlinked production root.
   For admin-only writers, prove the authenticated actor, native request
   lifecycle, registered writer and persisted readback together. A loaded class
   or manually fired `admin_init` is insufficient. If a simulated CLI admin
   request warns or fails, retain that failure and exercise an authenticated
   HTTP admin request with its nonce and server diagnostics; do not suppress
   warnings or manually load plugin internals to manufacture readiness.
6. Trace the plugin hooks skipped by WPrism's direct writes. Cache invalidation,
   generated files, rewrite flushes, index tables, and type registration need a
   bounded provider with value-level verification or an explicit unsupported
   disposition.
   A native delta handler is not necessarily a rebuild API: WPForms' widget
   locator appends duplicate locations when the same nonempty settings are
   replayed with an empty old value, and its post handler can write location
   metadata for a nonexistent form. Validate the complete bounded input and
   target roster before mutation, declare the exact recoverable derived
   keyspace, and prove a complete value-level fixed point. Neither invoking
   that handler twice nor scheduling its asynchronous scan establishes repair.
7. Mutate the proposed manifest in tests: remove a ref, broaden a namespace,
   switch a runtime field to authored, and create a conflicting second owner.
   Each false claim must fail for the reason the production path would fail.
8. Co-load the adapter with common adjacent manifests and exercise the plugins
   together, not merely as isolated installs. Enable optional modules through
   the plugin's native lifecycle before probing their tables: writing an option
   can select a module without running its installer. Then read both the raw
   persisted selection and the plugin's registered/active-module API in a fresh
   process. A lifecycle helper may accept an unknown identifier and persist a
   convincing option value even though no module exists. Include a hostile
   schema-driven field whose physical key matches another adapter's static
   declaration; capture must refuse multiple owners independent of pin order.
   Preserve target-only queue jobs and plugin state through apply, then prove a
   repeat plan is unchanged and inspect real front-end output.

   Prove the hostile-state premise after the seeding request has shut down and
   before attributing a later delta to WPrism. A plugin may cache an option
   during bootstrap and rewrite it at `shutdown`, so an in-request `get_option`
   is not a durability witness. WP-CLI 2.12.0 also matches `--skip-plugins`
   against directory slugs: Rank Math's slug is `seo-by-rank-math`, not its
   `seo-by-rank-math/rank-math.php` basename. That wrong argument left the
   plugin active in the original fixture; the already-instantiated notification
   center then rewrote the option after the apparently successful echo. Seed
   through the plugin's native API where one exists, read the premise back in
   an independent process with an exact, proved skip selector, and run an
   ordinary control boot. Rank Math 1.0.277.2's valid persistent notification
   survives that control; if another plugin legitimately advances its state,
   record the native lifecycle outcome rather than weakening a real
   stable-runtime preservation assertion. A `runtime` classification protects
   target-owned state from canonical capture and apply; it does not override
   the plugin's own activation semantics. Keep those claims separate in
   evidence: prove the pre-lifecycle value survives recovery, assert the exact
   post-activation outcome and carry that outcome through state-only apply.
   Then seed a new runtime witness to prove retirement, uninstall, missing code
   and inactive reinstall leave it untouched before reactivation reproduces —
   and the following apply preserves — the plugin's native outcome.

   Keep native storage shape distinct from canonical artifact grammar.
   WordPress's [deactivate_plugins()](https://developer.wordpress.org/reference/functions/deactivate_plugins/)
   removes numeric slots without reindexing `active_plugins`; deactivating the
   first or middle plugin therefore leaves a legitimate sparse array. Exercise
   that raw postimage through host lifecycle preflight and the locked baseline
   writer. Do not reindex the fixture's option to make the engine accept it.
   The native observation boundary projects plugin iteration order without
   changing stored bytes; desired artifact rosters remain canonical lists.

   Native names are not necessarily adapter identifiers either. WordPress's
   [WP_Rewrite::add_endpoint()](https://developer.wordpress.org/reference/classes/wp_rewrite/add_endpoint/)
   registers the endpoint name as a query variable; WooCommerce 11.0.1 uses
   `wc/file/transient`. A provider's serialized route-dependency witness must
   retain those opaque string bytes, order and duplicates, with explicit count
   and per-name byte bounds. Do not sanitize, skip or impose a manifest-ID
   regex on native names merely being observed. Pin the registered example,
   exact limits, malformed types and byte-sensitive drift through the provider
   path. Keep this semantic observation with its adapter; process execution,
   transaction authority and snapshot machinery remain engine-owned.

   Derive each phase's isolation oracle from its declared effects, not from
   the assumption that all pre-Apply state is immutable. Rank Math declares
   `rebuild_all_link_state` during `lifecycle_settle`: with its link counter
   disabled, the correct postimage has no link rows, counts or processed
   markers. Compare the complete native observation after changing only those
   exact expected fields; never mask whole products, plugins or metadata
   prefixes. If a later Apply also claims stale-state repair, re-seed checked
   stale projections after the lifecycle assertion and independently verify
   that preimage before Apply. A prior phase's successful cleanup otherwise
   makes the later cleanup assertion vacuous.

   Establish local-identity divergence explicitly for every mapped table. Two
   fresh plugin tables commonly allocate primary key `1` on both sites, so
   equal ids prove no rebinding at all and prior scratch history can make a
   weak fixture pass accidentally. Put the hostile side in a disjoint sequence
   range before inserting its row, then assert both the range and unequal ids.
   Once a natural-key row is mapped, a duplicate can refuse through the generic
   typed-ledger guard rather than an adapter-specific table diagnostic. Pin the
   `identity contradiction`, entity kind, existing local id, and rejected local
   id; a loose search for “duplicate” misses the actual fail-closed boundary.
   For an expected `--format=json` refusal, use the shared
   `capture_wprism_json_refusal` helper before `jq`. It proves that WPrism
   answered, requires the non-zero exit, and returns only the final JSON line;
   preceding diagnostics remain visible on stderr. Its output variable may use
   any shell identifier except the reserved `__wprism_capture_` prefix.
   `require_wprism_answered` validates a mixed stream but does not remove
   Compose's preceding `Container ... Creating` diagnostics from that variable.
   If the public envelope sets `details_redacted: true`, assert the public
   redaction and its `.wprism/refusals/` pointer separately from the cause.
   Retain private diagnostics for native `wp wprism apply` as well as host
   deploy: the latter's successful lifecycle capture does not cover a later
   Apply failure. Use `sandbox/tests/lib/private_command_capture.sh` to collect
   the bounded fresh delta before caller assertions or disposable teardown,
   with the owner binding its exact native transport and command inventory.
   The shared conformance driver's initial Apply and all three driver-owned
   Captures use
   `conformance_private_command` (`sandbox/tests/lib/conformance_private_command.sh`):
   its native reader runs outside WordPress as the site's CLI uid, and its
   host decoder retains complete records in a private, non-disposable sink.
   A capsule hook runs in a fresh Bash child: the driver exports scalar
   `COMPOSE`, but Bash cannot export `PAIR_COMPOSE` arrays. Before calling this
   collector from a hook, restore its required caller context with
   `read -r -a PAIR_COMPOSE <<<"${COMPOSE:?exact conformance transport required}"`.
   This preserves the driver's pair and all selected overlays, including offline
   artifacts; do not use `eval` or reconstruct a partial Compose command.
   This closes the `ee27e3b9` Polylang run's lost-cause gap: successful source
   Capture and target deploy did not explain the later redacted Apply refusal.
   The receipt-intent boundary also retains the original missing/ID/phase/record
   decision, bounded intent fingerprints and filesystem metadata in that private
   graph. These are observations, not recovery authority or proof of a database
   rollback. The native `regress_capture_receipt_diagnostics_live.sh` probe forces
   a missing read while retaining the actual committed inode, independently
   checks its commit proof and tree, then verifies retained diagnostics after
   owned teardown. This does not establish the cause of the intermittent PR #651
   refusal; it closes the evidence-loss gap needed to investigate another one.
   Reuse that binding for driver-owned native commands; do not wrap every
   `wp_env` invocation and interfere with capsule-owned exact-cause collectors.
   A diagnostic-only record remains unverified; expected-cause acceptance
   still requires the separate exact profile below. Never expose the private
   cause publicly or call a diagnostic failure a product rollback.
   A canonical fixed-point failure also needs the complete files, not merely
   `diff -rq` names or hashes. The combined commerce scenario at `9952f144`
   lost both trees during teardown, leaving managed product differences
   indistinguishable from target-only authored entities. Use the shared
   `FilesystemTreeEvidence` reader with `private_command_capture.sh` to retain
   source-before, source-after and recapture bytes before comparing or deleting
   them. Its engine-backed confinement and closed byte/topology decoder remain
   generic test machinery; the caller owns the selected roots, phase binding
   and equality assertion. Preserve target-only files and empty directories in
   this diagnostic. Retain the compiler's selected policy and media inputs too:
   `rmcombofinal01` kept the complete state trees but could not recompile them
   after teardown until its missing media was recovered from the hash-verified
   upstream artifact. The scenario's v2 diagnostic now retains both sites'
   policy/media trees before and after, and requires them unchanged before
   compiler-backed acceptance. A native scalar may still have a `plain_data`
   declaration (ACF text fields do); distinguish a codec from the stored value's
   shape, and reproduce the real interpreter rule in the offline oracle.
   Do not normalize away differences to make the test pass,
   and do not treat a successfully retained record as proof of equality.
   Inspect private records from a standalone, non-WordPress process running as
   the target CLI identity: the store is intentionally `0700`/`0600`, so host
   traversal that happens to work through Docker Desktop is not portable to a
   native-Linux bind mount. Snapshot the command-scoped record names immediately
   before the invocation, set-difference them against the names afterward,
   require exactly one appended record, and validate its
   `wprism-private-refusal-evidence/v2` completeness witnesses plus the exact
   root cause. Reuse `sandbox/tests/lib/PrivateRefusalReceipt.php` with a
   caller-declared graph profile; do not grow a capsule-owned private-store
   parser. Bind the source or target CLI service explicitly for each call.
   `PrivateRefusalReceipt::collect()` verifies the same raw bytes it retains,
   preserving a diagnostic and null receipt when the expected cause is wrong.
   Its `assertCollection()` re-verifies those bytes after transport instead of
   trusting a copied digest receipt. Admit the exact command exit separately:
   `PrivateCommandOutput::readObject(..., expectedExit: 1)` keeps the same
   bounded single-object and private-file checks as its default zero-status
   path. A failed post-command observer must not prevent private collection.
   A host deploy is a mixed phase stream, not a standalone agent JSON answer:
   its transport-detail renderer sends the refusal to stderr and may append
   the private-evidence hint after it. Test the complete phase/envelope trace
   through the real renderer instead of assuming that the last line is JSON.
   Explicit force flags may add a typed override envelope and an outer private
   warning node before the original cause. Derive that graph through actual
   preparation/reporting, and bind its native identities to checked fixture
   context; the override category alone is not evidence of the inner blocker.
   Before any assertion can trigger disposable-target teardown, retain the
   shared reader's bounded diagnostic delta in a private sink. Its explicit
   `verified:false` marker must never satisfy the separate exact-cause check.
   Do not clear prior evidence or select by timestamp/mtime: both
   can make a failed invocation appear proved by a stale record. The private
   sentence is deliberately absent from host output; grepping that stream for
   it tests against the disclosure boundary rather than the refusal that
   occurred. Apply this split to every redacted host preflight, including
   lifecycle and missing-code refusals: pin the public command/reason/redaction
   envelope and phase prefix, then prove the exact cause only through the new
   command-scoped private record. A generic public refusal by itself does not
   establish which private safety gate fired.
9. When a hostile target needs local mapped identities before first apply,
   never mint them by capturing against the source repository: its canonical
   UUIDs have no target ledger yet. Use a disposable policy root narrowed to
   the adapter plus the core manifest grammar required for platform options;
   omitting core makes capture correctly refuse to guess `active_plugins`
   storage semantics. Keep core entity scope empty and explicitly leave both
   the default category and its `default_category` reference runtime. Remember
   that capture still audits global WordPress state; either give every other
   unrelated live surface an explicit runtime disposition, or quiesce it
   through native APIs and restore it exactly on both success and failure. A
   default category or widget must not acquire an identity merely because the
   fixture needed a target-only custom-table row. Run this minting capture with
   `--out=<disposable-state>`: output-only capture commits new identity maps but
   skips canonical state-hash and media publication. Without `--out`, the
   disposable projection becomes the target's three-way base and the real
   first apply can correctly report a false-for-the-scenario conflict as soon
   as quiesced global state is restored.

   Distinguish minting identity from restoring an existing embedded UUID's
   ledger tuple. Ordinary `plan` uses the maintenance-aware snapshot: it may
   repair those tuples and prune stale maps, but it does not mint missing
   post/term UUID metadata or a last-synced state hash. Strict explain/export
   observations have a different, zero-write contract. A test that clears a
   map must freeze the exact recoverable tuples beforehand, prove only that
   repair occurred, and repeat the plan with unchanged native and ledger
   witnesses. Keep ledger-only widget identities out of the recoverable set;
   their lost UUIDs cannot be reconstructed from native metadata. See the
   final fresh-target check in `sandbox/conformance/checks/core.sh`.

   A table hash can reject a mutation but cannot explain it after the pair is
   destroyed. Retain bounded native rows and their complete transport privately
   before equality assertions, then recompute the published count/hash witness
   from those retained rows. Reuse the shared private capture transport; keep
   the table set and semantic acceptance with the fixture owner. Core's final
   plan check retains four snapshots in a `0700` directory with `0600` streams
   under the checkout's host-only `sandbox/tmp/`. Never put private host
   evidence in a site-repository bind tree: pair handback intentionally broadens
   that tree's permissions and reset removes its contents. Verify retention
   after cleanup, not just before it. Its diagnostic-only record is not a passing certificate;
   a malformed, warning-bearing, oversized or mismatched observation refuses.

   Native workers and host verifiers have different physical dependency roots:
   the pair mounts `mu-plugins/adapter-packages/` beside the drop-in's `src/`,
   not a host `agent/` directory. Keep shared native assertions independent of
   host-only compiler loads; require those dependencies at the host comparison
   boundary. Exercise the actual helper in a fresh capsule-only process where
   host classes are not already declared. WPForms' settings regression pins
   this layout after an eager host require failed before the first native save;
   alternate-path guesses or an autoload fallback would hide the boundary.

   Put reusable capsule shell helpers in `fixtures/`, not beside class-named
   test entrypoints. Source them through the explicit
   `"$(dirname "${BASH_SOURCE[0]}")/../../fixtures/<name>.sh"` form.
   From the live harness's `sandbox/` working directory,
   `. tests/lib/private_command_capture.sh` is the reviewed shared transport
   dependency; this admits that exact helper, not arbitrary neighboring shell
   files. For complete canonical trees exceeding the compact 256 KiB content
   budget, select `EvidenceSizeProfile::CONFORMANCE_TREE` explicitly in both
   capture and retained admission. It permits one 1 MiB tree inside a 2 MiB
   private command stream; roster and metadata bounds do not grow. Keep
   before/after records separate and never truncate a tree to fit a budget.

   Whole native databases have a different size envelope: merely opening the
   WPForms template picker produced a 5,574,808-byte dump, including one full
   prepared-template cache value. For such a fixture, explicitly select
   `EvidenceSizeProfile::NATIVE_DATABASE` in `PrivateCommandOutput::readBytes()`
   and in `SqlDumpEvidence::assertComplete()`, `structures()` and
   `projectColumns()`. This admits at most 16 MiB of complete native SQL;
   filesystem budgets, row/cell rosters and 64-KiB schema sections do not grow.
   The old 2-MiB SQL budget remains default. Do not omit an operational cache
   row or strip table options to fit the transport. The fixture still owns
   every allowed physical change and must refuse beyond the selected bound.

   Match a command's output contract to its role. A browser fill can validly
   return `{}`; that is not a native-save evidence object. Keep ordinary UI
   preparation separate from the strict nonempty collector record, and reject
   explicit tool error envelopes even if a command reports exit zero. Bind
   the actual collector argv and complete input bytes, not just a source hash
   for code that may never have executed. A noninteractive collection command
   inside a roster loop must receive closed stdin: otherwise `compose run`
   can consume subsequent roster entries before the next `read`.

   A complete native Save can contain multiple writers and non-database
   effects. WPForms' first collected Builder Save posted the form and then
   custom themes, writing an uploads JSON file. Declare and admit that exact
   request sequence; do not filter extra requests to preserve a one-XHR
   assumption. Pair full SQL with the existing filesystem-tree witness for
   file effects. Preserve ordered serialized controls, including legitimate
   duplicate widget search names under an explicit multiplicity/value bound.
   Prime first-save rich-text synchronization through the actual UI before
   claiming later QR-only control preservation.

   Read diagnostics in complete HTTP headers too. The same Save's theme
   response was HTTP200 with a successful JSON body and empty browser/server
   logs, but carried `X-WP-DoingItWrong`. Such a response is not diagnostic-free.
   Refuse the native gate and record the upstream limitation; do not turn off
   debug mode, allowlist the warning or patch a locked vendor artifact to make
   evidence green.

   Execute a PHP fixture with `wp eval-file --use-include` when it declares
   `strict_types` or resolves sibling files through `__DIR__`. WP-CLI's default
   evaluation mode is not an ordinary file include: the first native Polylang
   biography seed at `8ed15717` failed before its premise check with
   `strict_types declaration must be the very first statement`. The documented
   [include mode](https://developer.wordpress.org/cli/commands/eval-file/)
   preserves normal file semantics and the normal WordPress bootstrap. Do not
   strip the declaration, add a loader shim, or skip WordPress to mask that
   invocation error; pin the actual caller's arguments offline as well.

### Getting the harness those tests need

Adoption assembles package and platform sources into
`agent/adapter-library/`, then sends exactly `agent recovery`; none of WPrism's
test estate reaches the site. Rather than reinvent it, assemble
the adapter test kit out of a WPrism checkout:

```sh
php tools/adapter-kit.php --assemble=/path/to/kit --adapter=my-forms
php /path/to/kit/skeleton/regress_my_forms_kit.php
```

That second command's whole dependency list is `php` — no composer, no
WordPress, no database. The kit carries `check.php` (assertions, the summary
line, the suite exit code), `wp_stubs.php` (seedable WordPress function stubs),
`FakeWpdb.php`, `frozen_policy.php`, and the manifest-agnostic conformance
harness `run.sh`/`asserts.sh`, plus a generated skeleton to edit into your own
suite. `MANIFEST.json` records the sha256 of every file so you can tell which
revision of the harness you received.

Take `FakeWpdb` in particular even if you keep nothing else. It holds rows and
interprets your SQL against them, and any statement it cannot interpret throws
`\LogicException` naming that statement. A hand-rolled fake answers `null`
instead, which pushes your suite down a "no row" branch your real database
never takes — every assertion after that point is green for the wrong reason.

Seed storage engines, columns and indexes explicitly when a product gate needs
those facts: row existence does not prove transactional storage. Every opt-in
read projection, including metadata, must traverse the same query authority,
error injection and logging as an ordinary read. A fixture shortcut before that
boundary can hide a real profile refusal. Pair a refused-read callback spy with
a healthy case that invokes the same spy; a counter that never runs proves
nothing about where the refusal happened.

The kit is assembled from the live files on every run and never stored as a
second copy, so re-assemble from a newer checkout rather than patching a file
inside a kit you already have.

For shortcode identities, test the callback's actual lookup rather than the
shape of its example markup. Ordinary numeric attributes use a named
`shortcode_attrs` ref. A callback that consumes a positional post-meta
alternate uses the closed `{kind, position, lookup}` rule. A callback that
consumes a fixed lowercase-hex prefix of authored post meta uses the closed
`{kind, path, required: true, lookup: {codec: "hex-prefix", post_meta,
post_type, prefix_length, stored_length}}` rule. The latter requires one exact
attribute, validates and collision-checks every owner, and refuses title or
numeric fallbacks. Do not declare it from example shortcode output alone:
trace the lookup query, its collation/case behavior, excluded post statuses,
the stored-value generator at every admitted release, upgrade retention, and
duplicate-row behavior, then reproduce a
foreign target collision live. Also run the plugin's destructive native
uninstall/reinstall path: when owner rows are deleted but embedding pages
survive, target observation may recover only from the sealed canonical reverse
witness. Prove the unforced run remains atomic, any activation-default slug
collision still needs explicit adoption, a live owner mapped to another entity
still refuses, and the successful recovery restores the repository's authored
alternate rather than inventing a target-local one.

When adjacent supported releases generate different exact widths for the same
fixed-prefix callback, use `stored_lengths` instead of `stored_length`. It must
be a strictly increasing list of at least two unique widths. Prove every width
from the plugin's source and a real saved row, prove the in-place upgrade shape
(including a retained old-width value), and reject every undeclared width; a
min/max range would silently admit identities no native release generates.

When any step cannot be represented, keep the adapter experimental or reject
it. Do not disguise a missing codec as `verbatim`, `runtime`, a broad option
pattern, or a compatibility fallback. Record the required generic primitive in
[the limitation ledger](../guides/adapter-authoring-limitations.md).
