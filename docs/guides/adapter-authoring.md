# Authoring an adapter manifest

A manifest is how WPrism learns what one plugin's state *means*: which keys are
portable authored intent, which are environment-local noise, which hold entity
references that must be rewritten across environments, and which tables it may
touch at all. The engine holds no plugin-specific storage or repair branch —
every behavioral fact lives in a capsule. It does carry one generated list of
the names shipped in the trusted library, used only to reserve those identities
from out-of-tree namespace squatting. That list is derived from the package
tree by `tools/shipped-identity-inventory.php`; it is not plugin logic or a
second catalog an author maintains.

This guide is the authoring loop. The normative format is
[spec/repo-format.md § Adapter manifests (package format)](../../spec/repo-format.md#adapter-manifests-package-format),
and everything a manifest may declare is enumerated there. Read it alongside
this page rather than instead of it.

## What a manifest is, and what it is not

A manifest is `adapter-packages/<name>/package/manifest.json`, inside the
adapter's source capsule, and is pinned by name from a site's
[`site.wprism.json`](../../spec/repo-format.md#sitewprismjson). It declares
classification rules, reference shapes, deletion capability, derived-state
repair actions, and a compatibility window.

It is **not** a certification. A manifest cannot certify itself merely by
existing beside the agent — that separation is deliberate, and the agent says
so in the exact words it refuses with: `no reviewed disposition entry — a
manifest cannot certify itself merely by existing beside the agent`. See
[Dispositions](#dispositions-the-reviewed-claim-source) below.

## Directory conventions

```
adapter-packages/<name>/
  package/
    manifest.json                    # declared adapter policy
    disposition.json                 # reviewed support boundary; NOT a manifest
    runtime/
      interpreters/<name>.php        # \WPrism\Interpreters\<Name>
      regenerators/<name>.php        # \WPrism\Regenerators\<Name>
      providers/<id>.php             # \WPrism\Providers\<Id>
  tests/                             # offline/live/certify/conformance evidence
    offline/regress_<name>_*.php
    conformance/{entry.json,seed.sh,check.sh}
    certify/version-matrix.sh
  fixtures/                          # capsule-owned historical/probe inputs
    <workflow>/                     # native PHP, shell helpers and host readers
  evidence/
    artifacts.lock.json              # exact official artifact URLs + sha256
    production-readiness.json        # all 12 hostile scenario families
    target-observation-premises.tsv  # ratchet for live observations/fixtures
    external-tests.json              # optional integration-scenario citations

platform/adapter-library/
  core/{manifest,disposition}.json
  profiles.json
  capabilities/platform.json         # the reviewed platform boundary
  capabilities/adapter-authorities.json # platform trust root; ships {"keys":{}}
```

Only `package/` is assembled into the installed agent. Tests, fixtures, and
evidence stay in the capsule but do not ship. Both platform capability files
are hand-authored and reviewed, not generated.
Every product capsule also owns `tests/conformance/entry.json`, `seed.sh`, and
`check.sh`; the global `regress-conformance-asserts` gate enforces their presence
even when the focused package validator passes. A source-only experimental
entry uses `mode: "capture-plan"`, puts its source assertions in
`capture-check.sh`, and makes `check.sh` explicitly refuse accidental target
promotion. A bounded native Apply suite can qualify individual settings while
the broader deployment entry remains source-only.

Once the experimental disposition declares both `deploy` and `apply`, use
`mode: "agent-roundtrip"` to exercise target activation, Apply, plugin consumers
and byte-identical recapture. The harness first proves that host deployment
still refuses the experimental claim, then runs the public agent verbs. This
mode cannot emit a certified conformance vector and does not grant production
promotion. Certification also requires the owned version matrix and ready
evidence across all twelve scenario families; see the package validator.

If a schema or lifecycle provider makes standalone target deploy correctly
refuse for lack of the host checkpoint/session ordering, do not keep a hollow
experimental `deploy` claim just to reuse that profile. Use
`mode: "agent-apply-roundtrip"`: it requires experimental
capture/compile/plan/apply/recapture claims with deploy absent, proves both the
host certification refusal and the direct provider-boundary refusal, then lets
the disposable harness establish exact native plugin/theme lifecycle state.
Package hooks still exercise the real provider, Apply, native consumers and
recapture paths. Deployment becomes claimable only when certified host evidence
can exercise it; fixture lifecycle setup is explicitly not deploy evidence.

An entry may declare `adopt_by_slug` as a unique array drawn from `terms`,
`posts`, `menus`, and `tables`. The default is `["terms", "posts"]`; an empty
array explicitly selects no adoption. This is fixture intent for an existing
target, validated before pair mutation, not a shipped adapter permission.
For example, a template roundtrip that seeds existing target rows declares
`["terms", "posts", "tables"]`. Keep target-local preservation assertions in
the capsule's post-Apply check. Reusable shell helpers belong under `fixtures/`;
premise contracts may name active statements there as well as under `tests/`.
Both roots are scanned for required evidence contracts.

`platform.json` is the object a site-adapter certificate reads its bound
compatibility cells out of (§ v3.6), so it has exactly one on-disk
representation; the agent refuses at load time if its
`agent_version`/`spec_version` disagree with the running
`WPRISM_AGENT_VERSION`/`WPRISM_SPEC_VERSION`.

A capsule's `tests/live/*.sh` starts with these exact three active statements;
`regress-fetch-artifact` checks their order so artifact resolution always has
one capsule owner before any wrapper or conditional code runs:

```bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
```

Put path normalization and other setup after this preamble. Create the ignored
`sandbox/tmp` parent before allocating evidence below it; a fresh worktree does
not contain that directory. Source shared helpers through the reviewed paths
and capsule hooks through their `BASH_SOURCE`-relative paths, as enforced by
`AdapterPackageValidator`.

Four rules that will bite you if you learn them the hard way:

- **The capsule name is the pin name, and it is enforced.** `Policy::load()`
  resolves a pin through the installed `AdapterLibrary`, while package
  discovery, the reviewed disposition, and `AdapterRegistry` all key the
  loaded library by the manifest's own `"name"` field. A disagreement would be one adapter
  under two identities, so it is refused the moment the manifest is read — by
  name, with both values:

  ```
  wprism: shipped adapter '<path>' declares name 'y' but its file name is 'x' — a pin
  names the file while every downstream identity (dispositions, digests,
  diagnostics) keys off the declared name, so the two disagreeing is ambiguous
  identity. Make the declared name match the file name
  ```

  The same sentence refuses a site-installed adapter (see below), and
  `wprism manifest-validate <dir>` reports it per manifest offline, before any
  target is contacted.
- **`package/disposition.json` is not part of the manifest.** It is reviewed
  data *about* that one declaration. Package validation requires exactly one
  manifest/disposition pair; profiles remain platform-owned at
  `platform/adapter-library/profiles.json`.
- **A regression fixture is marked by its disposition, not by its name.** Give
  it `"status": "excluded"` and the generated document prints it as shipping
  "for regression use only" and carrying no product claim; the projected claim
  reports `authored_state.status: unsupported` and
  `plugin_execution.status: not-a-product-claim`. `wprism-agency-cpt` is the one
  shipped example. A name prefix decides nothing — that rule is gone with the
  generator that read it.
- **Interpreter, regenerator, and provider code ships with the manifest, not
  the engine.** A declared interpreter name resolves to
  `package/runtime/interpreters/<name>.php` inside the same capsule and must define
  `\WPrism\Interpreters\<CamelCase(name)>` with
  `post_meta_rule(string $key, array $allMeta): ?array`; it may additionally
  define `term_meta_rule()` and `user_meta_rule()` with the same signature and
  nullable-defer semantics. A regenerator, declared under a post type's
  `regen_dependency`, resolves to `package/runtime/regenerators/<name>.php` and must
  define `\WPrism\Regenerators\<CamelCase(name)>` with
  `regenerate(int $localId): void`. A manifest-sourced provider resolves to
  `package/runtime/providers/<id>.php` and must define
  `\WPrism\Providers\<CamelCase(id)>` with `identity()`, `capabilities()`, and
  `invoke()`. A missing file is a loud load-time error naming the exact path.

That last one is worth stating without euphemism: **WPrism loads PHP shipped from
an adapter capsule's `package/runtime/`.** Adoption embeds that allowlisted
package projection in `agent/adapter-library/`, so loading it is the same trust
decision as running the installed agent. Content digests strengthen that: all three files —
interpreter, manifest-sourced provider, and (since issue #3360) regenerator — join
the per-adapter content digest, so editing any of them is a *changed adapter*
rather than invisible drift behind a stable manifest digest. Two
implementations can no longer share one manifest revision's identity.

<a id="editing-a-shipped-manifest-moves-its-identity"></a>

> **Editing a shipped manifest's bytes, or a hook file it names, moves that
> adapter's identity — and deployed sites refuse until you re-pin.**
> `ArtifactPolicyIdentity::manifest_rows()` builds one row per manifest
> carrying the manifest array, its disposition, and `hash_file('sha256', …)`
> of each named interpreter, provider and regenerator file.
> `manifest_hash()` is sha256 over the canonical encoding of all those rows;
> `resolved_adapters()` hashes each row *individually* into that adapter's
> `digest`. It is the **same row** folded both ways, so a site repo's
> per-manifest content pin, `adapter_digest`, the digest `wprism assess` reports,
> and the contract that pins it are all this one row hashed. Change a byte and
> a deployed site with a compiled artifact refuses with
> `compiled_artifact_manifest_mismatch` — *compiled manifest/interpreter set
> does not match active pins* — and the `site.wprism.json` content pin stops
> matching too.
>
> The remedy is recompile and re-pin: rebuild the artifact and update the
> reviewed pin, which `wp wprism manifest-pin --repo=<site-repo> --name=<name>`
> emits as a copy-pasteable object. Updating a pin is an explicit review act
> and is never automatic.
>
> What does **not** move identity: renaming a PHP namespace, or moving an
> `agent/src` class file. `manifest_rows()` folds manifest JSON bytes,
> disposition bytes, and the sha256 of the named hook files — nothing else.

After an intentional shipped-byte edit, measure the changed identities before
updating the current literal baselines in `regress_disposition_split.php`,
`regress_spec_v3_digest_neutrality.php` and their
`sandbox/tests/fixtures/spec-v3/wprism-greenfield-identity.json` fixture.
Re-pin only measured changed addresses, including the fixture's own byte hash;
leave historical transitions and frozen executable-debt hashes untouched.
A provider-only edit can move its adapter and compatible manifest hashes while
leaving manifest JSON, the disposition registry and policy snapshots unchanged.
Run `make regress-disposition-split regress-spec-v3-digest-neutrality` before
the aggregate: capsule validation does not replace those cross-library
identity checks. Preserve prior transition literals; when reviewed disposition
fields change, reconstruct the historical claim before checking its old hash.

When a reviewed plugin version range changes, also run `make
regress-manifest-dispositions`. Its literal ranges must agree with the capsule's
manifest and disposition after the corresponding native evidence is accepted.
Refreshing identity hashes alone does not check those version expectations.

A new capsule also extends the source census: run `make
regress-spec-v3-digest-neutrality regress-spec-v3-document
regress-spec-v3-dry-run regress-spec-window` before the full gate. The literal
identity fixture now preserves its historical 21-subject cohort. Do not extend
or re-pin that historical fixture merely because a capsule joins the library:
`regress_spec_v3_digest_neutrality.php` separately loads every later capsule
with core and round-trips its frozen policy against the complete current
registry. A conflict in that core-plus-capsule load needs a reviewed compatible
test world, not removal of the census check. Existing capsule digests and
historical pin sets remain unchanged unless their identity inputs changed.
Regenerate `tools/shipped-identity-inventory.php`, `tools/api-surface.php
--write`, and `tools/wire-surface.php generate` for the actual library census.
The current whole-registry address and frozen snapshots still move when an
unpinned disposition is added; § v3.4's WP-4.5 addressing proposal is explicitly
unimplemented. Preserving the historical fixture does not close this
architectural limitation.

## The minimal worked example

[`adapter-packages/classic-editor/package/manifest.json`](../../adapter-packages/classic-editor/package/manifest.json)
is the current honest minimal product adapter. Stripped of its evidence notes,
it is a plugin boundary plus two authored options:

```json
{
  "spec_version": 2,
  "name": "classic-editor",
  "option_autoload": "preserve",
  "plugin": "classic-editor/classic-editor.php",
  "version_range": {"min": "1.7.0", "max": "1.7.1"},
  "notes": ["…"],
  "options": {
    "classic-editor-allow-users": {"class": "authored"},
    "classic-editor-replace": {"class": "authored"}
  }
}
```

- `spec_version` must be inside the engine's acceptance window — its own
  `WPRISM_SPEC_VERSION` (**N**) or the one before it (**N-1**), and nothing deeper
  (`spec/repo-format.md` § v3.1). An integer outside the window refuses at load
  and names the window; an ABSENT or non-integer value is a different failure
  with its own message, because it is not a version at all. Declare N unless you
  are deliberately staging an older manifest across an engine move. Ask the
  engine rather than guessing: `wprism manifest-validate --emit-schema` prints the
  accepted set in `spec_window`, measured from the shipped refusal.
- **Two defaults apply, and they are not the same number.** Existing shipped
  manifests legitimately span `2` (`N-1`) and `3` (`N`) because AGENTS.md rule
  2 makes changing a working manifest's version an adapter-identity move:
  nobody bumps the integer without using a new primitive. A **new out-of-tree
  manifest** should declare `3` if and only
  if it wants a post-v3 primitive (`engine_features` and the sections it
  claims — [see below](#the-grammar-document)). Every feature is load-bearing:
  removing it must make the section it admits refuse. Site certification uses
  the same per-feature surface roster, so a recognised feature and its reviewed
  section can be signed; an unknown feature, an unrecognised top-level key, or
  a section no declared feature admits refuses by name. Run
  `wprism adapter inspect` before signing and `wprism adapter certify … --pin`
  immediately after the final byte edit rather than treating version 3 as an
  automatically stronger manifest.
- `plugin` is the plugin basename; `version_range` is `{min, max}` with min
  inclusive and max exclusive, checked with two `version_compare()` calls. One
  plugin per manifest. Declaring a plugin without a well-formed range is
  refused at load, before any target contact — there is no "latest" or
  unbounded form, because an unbounded claim is not certifiable. Two pinned
  manifests naming the same plugin with different ranges is also refused
  outright: manifest precedence must never depend on pin order.
- `notes` is where the *reasoning* for every rule lives. Read Classic Editor's:
  each entry
  names what was verified live, against which version, through which code
  path. Executable evidence lives in the capsule's tests and evidence records;
  the note connects those observations to the declaration. A rule without that
  connection is a guess with better formatting.

### Environment intent must have a provisioning path

For a top-level `options.<name>.class: "env"` rule, `required: true` promises
an operator-supplied intended value through public `wprism env-set --stdin`.
That command accepts a whole scalar string, not a structured plugin settings
blob: overwriting the parent would destroy its authored and target-local
siblings. `OptionGrammar` therefore refuses `required: true` together with
nonempty `sub_keys` before target contact.

A plugin-managed structured parent needs `required: false` and evidence that
its native activation or installation path populates it; each sub-key keeps
its own classification. Redirection's native `red_set_options()` /
`Red_Options::save()` lifecycle is one measured example. Do not lower a required
flag merely to silence a live warning, auto-bind ambient values as intent, or
teach the scalar provisioning command to replace a structured object. If a
genuinely operator-required structured value has no safe provisioning path,
that is a product-boundary gap to design and test before claiming support.

### Deferred native migrations

Trace migrations beyond activation: an admin or frontend request may run an
upgrade that WP-CLI activation never reaches. A runtime migration cursor is
not authored intent, but it can be a prerequisite for safely interpreting and
replaying authored state. Declare `storage-prerequisites/v1` and a
`storage_prerequisites` list of exact runtime option/string pairs when the
native storage contract requires them. The engine reads durable rows, refuses
unmet prerequisites before authored work, and never writes the cursor.

This admission feature does not execute migrations or certify storage history.
Automatic migration completion belongs in a separately audited, checkpointed
lifecycle settlement provider. Declare that provider as a `lifecycle_settle`
action whose complete effect set is restorable `database_checkpoint` authority,
including an effect for the exact prerequisite option or the `options` table.
The compiler derives their same-manifest relationship; do not repeat a provider
id or callback in `storage_prerequisites`. Without that effect-covered action,
host deploy keeps the prerequisite manual and refuses before taking a lease.
When storage debt is the only deployment trigger, the engine runs only those
storage-safe covering action declarations; an external or irreversible effect
on the same action keeps it manual, and other adapters' lifecycle providers are
not selected. Qualify missing/stale cursor refusal, the supported native
migration procedure, current authored settings, and consumers after replay.
Recapture before the first HTTP/editor request cannot prove that later native
migrations preserve the replayed state. Do not preseed a current cursor to make
a fixture pass or classify the affected authored value as runtime.

### Finding the two versions the range names

The refusal above is permanent, so the cost it creates recurs forever: somebody
has to establish which releases actually work. `wprism adapter boundary` bisects
that in O(log releases) instead of by trying versions until one sticks.

```bash
wprism adapter boundary \
  --releases=adapter-packages/<slug>/fixtures/boundary/releases.json \
  --outcomes=adapter-packages/<slug>/fixtures/boundary/outcomes.json \
  --anchor=<a version you already believe works> \
  --manifest=<name> --format=json
```

The candidate set is a **recorded** `wprism-adapter-release-list/v1` document
carrying every release's exact URL and sha256 (see
[`sandbox/conformance/boundary/README.md`](../../sandbox/conformance/boundary/README.md)).
Nothing on this path reaches the network, and an unpinned candidate is refused
rather than fetched — the same discipline package-owned artifact fragments already hold.

One probe is a full pair round-trip, so the command is a planner: exit 3 names
the one release to probe next, exit 0 emits the finished document, and
`sandbox/bin/adapter-boundary.sh` is the loop that runs the probes in between,
using the capsule's own `adapter-packages/<slug>/tests/certify/version-matrix.sh`
seed hook. Outcomes are `green`, `boot-fatal`,
`round-trip-diverges` or `artifact-unresolved`; the last blocks the search
instead of counting as a failing release, because a mirror outage is not
evidence about a plugin.

**It never edits a manifest, and it is not trying to.** What it produces is the
sentence a reviewer needs — "6.0.0 installs, round-trips and recaptures
byte-identically; 5.12.6 fatals with this signature" — plus
`evidence/artifacts.lock.json` rows in that fragment's three-role vocabulary.
Writing the range, and restating it in the same capsule's
`package/disposition.json` so the two stay
Canon-byte-equal, remains one reviewed human edit; every proposed endpoint is a
release that probed green, and a recorded failure inside the proposed window
blocks the proposal rather than narrowing it by guess.

### Keeping the range true after upstream ships

The range you found is a claim with an expiry date nobody writes down. `wprism
adapter proposals` is the scheduled job that reads it out of the evidence
instead:

```bash
wprism adapter proposals --ledger=adapter-packages/<slug>/fixtures/boundary --format=json > health.json
wprism census --dir=<inventories> --health=health.json
```

It re-runs the bisection above for **every** pinned plugin that has a recorded
ledger, deriving each adapter's anchor from its own newest green probe rather
than from a flag, and emits two things.

The first is a proposed range bump as **both** edits — `version_range` in the
manifest and `supported_versions` in the capsule's `package/disposition.json` — built from
one value, so they agree on the canonical bytes
`ManifestDispositions::validate_entry()` compares. It is a review packet, never
a commit: a byte under `adapter-packages/<slug>/package/` is adapter identity,
so a job that widened a range on a schedule would refuse every deployed site holding a compiled
artifact. `max` moves only as far as the next **recorded** release after the
evidenced ceiling — exclusive, so it admits nothing unprobed — and a bisection
that never reached green is refused rather than proposed, as is a range that
would contain a release the record says fails.

The second is a derived `last_verified` per adapter: the newest release that
probed green, the same shape
`platform/adapter-library/capabilities/platform.json` uses per axis. It lives
**outside** `package/` on purpose — stored beside a manifest it
would move every adapter digest on every re-verification — and it cannot be
hand-asserted: a ledger document carrying its own `last_verified` is refused.
`wprism census --health=` ranks those rows beside the demand rank, by sites pinning
an adapter times releases it is behind.

### The caveat that catches everyone

CF7's own notes carry it: `wpcf7_contact_form` **must** be in the site's
`policy.post_types` in `site.wprism.json` for any of these rules to take effect.
**A manifest classifies; the site scopes.** A manifest classifies keys within
entities that are already in scope; the scope list itself is site-local policy.
`wprism init` proposes that scope for you — every `post_types`/`taxonomies` entry
of class `authored` in a selected adapter goes into the proposal — so on an
init-owned repository a manifest with `"post_types": {"wpcf7_contact_form":
{"class": "authored"}}` does carry its own scope. A hand-authored
`site.wprism.json`, or a scope you narrowed afterwards, still has to name the
entity, and `wprism classify` is how a type left local is re-decided later.

For an interpreter-shaped adapter, where meta semantics live in data rather
than in a static key list,
[`adapter-packages/acf/package/manifest.json`](../../adapter-packages/acf/package/manifest.json) is
the reference.

### One stored number, multiple native keyspaces

Trace every native writer and reader of a reference option. One value may be
consumed as both `term_id` and `term_taxonomy_id`; observing that those numbers
happen to coincide on a fresh site is not evidence that they share a keyspace.
WooCommerce's `default_product_cat` is the concrete case: its installer and
batch assignment use TT ids, while its admin and default-term APIs use term ids.

For this modeled coordinate pair, declare `scalar-reference-intersection/v1`
and one exact authored option with `ref: "term"`,
`ref_same_local_id_as: ["tt"]`, and required `ref_taxonomy: "product_cat"`.
The engine checks physical rows, canonical taxonomy, and both identity bindings;
canonical state still contains the ordinary term token. A default whose two
native interpretations disagree refuses. A force flag cannot make that value
portable. Neither an interpreter nor an authored site override may replace this
static value contract; a whole-option runtime/derived exclusion relinquishes
propagation, and an env exclusion makes provisioning operator-owned instead.

Test an actually changed default, equal default coordinates with *other* terms
divergent, wrong-category collisions, missing/partial maps, physical-table drift,
and fresh activation before identity minting. The last case has a narrow
options-only lifecycle projection for a physically valid tuple with both mappings
absent, bound to the desired-state handoff. Ordinary capture, production export,
and read-only explain must not use that projection; test an excluded taxonomy
too, where the full entity walk cannot supply the identity guard.

Also exercise first Apply against the installer's unmapped default, without
seeding a synthetic identity map. Ordinary planning can compare that reference
using the unique desired taxonomy/slug/parent identity witnessed during its full
target snapshot. This comparison writes no identity and omits no authored
option: the plan still reports a collision unless term adoption was explicitly
requested. The authored transaction must install both coordinates and verify
the physical tuple again before materializing the option. Partial maps, a UUID
already live elsewhere, ambiguous natural keys, and failed reads still refuse.
Production export and read-only explain continue to require durable identities.
The shared product regression is
`sandbox/tests/offline/apply/regress_plan_reference_adoption.php`; adapter live
evidence must also prove native behavior after adoption and a fixed-point retry.
Do not align all fixture ids or filter warnings to turn the unsupported domain
into a positive case. See
[the wire contract](../../spec/repo-format.md#v325-scalar-reference-intersectionv1--one-value-multiple-native-coordinates).

### One native action, separate identity and label storage

Trace every write made by a native authoring action, not only its primary
table. WPForms Lite 2.0.1.1's Tags AJAX writer assigns core term relationships
and separately saves submitted text labels in `settings.form_tags`
(`src/Admin/Forms/Ajax/Tags.php:154-179,255-270`). Existing choices submit a
term ID as `value`; a newly typed choice submits its label. The stored label
is not an identity even when it is numeric-looking.

Use both UTF-8 and numeric-looking labels, with source/target term and TT
coordinates deliberately different and no native ID equal to the numeric
label. Prove the complete body values, types and order separately from the
relationship identity set and native term order. A successful AJAX response
is insufficient when the native handler ignores its writers' return values:
retain the actual request and check complete physical rows plus fresh native
consumers. Test changed assignments and preserve unassigned local terms;
do not drop their entire taxonomy from recapture to manufacture convergence.
The WPForms capsule's `regress_form_tags.php` and
`regress_native_tag_evidence.php` are deterministic mechanism/admission
examples, not substitutes for executing its native producer.

Trace the native loader before treating a valid nonce and payload as a
complete request. WPForms registers its Tags AJAX handler only when its
admin-AJAX predicate sees an admin-page referrer. Bind the actual preceding
page read, route, host and `Referer` in the retained exchange; a header
invented without that read or a direct class bootstrap is not the native
authoring flow. Keep this plugin-specific loader contract in its capsule.

A CLI-authored native seed can be a fixed point before admin-only save
filters load. WPForms' first Tags AJAX save also adds six disabled QR
defaults through `admin_init` and `wpforms_save_form_args`. Require that
exact native delta and its absent preimage; do not preseed the defaults or
suppress the filter to make a tag-only expectation pass. Automatic defaults
do not establish UI authoring or a license-gated feature's support.

A generated browser preview may preserve an older snapshot independently of
the currently selected destination. Exercise Generate, change without
regeneration, Save and fresh reload through the actual complete form. Bind
the native choices, inactive inputs and stored scalar types; do not derive the
snapshot from the selected identity in a provider. WPForms QR destination
reconnaissance is documented in its capsule's `native-qr-destination-recon.md`;
its measured body fixtures are not admitted browser or Apply evidence.

Verify evidence artifacts themselves before retiring a browser session.
Capture configuration, a request list or an exit-zero tool command does not
establish retained request/response bodies. Require complete private bytes,
bounded framing and diagnostic admission; fail on missing artifacts or tool
error envelopes even when the process exits zero. Decode a downloaded QR
independently before claiming its encoded destination; a preview label alone
proves neither the downloaded bytes nor their content.

Disabling WP-Cron does not quiesce every native shutdown callback. Trace any
operational transition to its source, bind its exact phase, row identity and
clock window, and prove no work was dispatched before permitting its measured
value change. Keep all other rows and logical schema constrained, including
the exact table-counter advancement authorized by new revisions. Never turn
that authoring-only allowance into an Apply/observer waiver or disable a
native hook to make a preservation comparison pass.

### A reference whose type depends on a sibling

Exercise every variant of a native settings record before assigning a path a
reference kind. AIO Login 2.4.1's REST `login-redirection/save-rule` writes a
decimal string to `login_target_value` for `login_target_type: page`, and a
URL at the same coordinate for `custom`. An unconditional `json_refs` rule
captures the page identity but changes the URL to `"0"` during apply.

For ordinary option and metadata structures, declare
`conditional-json-refs/v1` and use the existing path with a closed sibling
condition: `"when": {"key":"login_target_type", "equals":"page",
"otherwise":["custom"]}`. The named branch uses the existing reference and
cast; the listed alternative retains ordinary text/URL semantics. Missing or
new variants refuse. The condition is not a general JSONPath filter, a
privacy exception, or support for a native feature that was not exercised.
The exact grammar and admitted surfaces are in
[the format specification](../../spec/repo-format.md#v327-conditional-json-refsv1--sibling-discriminated-structural-references).

Prove both branches through native authoring, Capture, complete repository
compilation, SQL Apply with divergent IDs, and recapture. Include a changed
or missing discriminator, wrong-kind token, malformed native ID, and a secret
beside the positive data. `regress-conditional-json-refs` is the shared engine
pin; each plugin still needs its own native writer and behavior evidence.

### Structured block attributes and derived editor caches

Use the plugin's editor and inspect the stored block comments. `block_attrs`
paths are exact top-level attribute names; `image.id` does not traverse an
`image` object. A query selector stored as CSV also needs its native string
shape restored after identity rebinding.

When an attribute has no safe declared transport, use an explicit boundary:

```json
"block_attrs": {
  "example/gallery": [
    {"path":"customQuery", "unsupported":"Custom query references have no declared transport."}
  ]
}
```

This refuses whenever the exact attribute is present, including `null`, an
empty string, or an empty container. Only absence is admitted. Capture,
offline compilation of post bodies and block widgets, lint, and Apply enforce
the boundary. The compiler reports `repository_block_attr_unsupported` with
the state file, comment offset, attribute, and reviewed reason; it does not
echo the value. A repository edit cannot bypass the declaration. This rule
does not inspect nested fields or make a custom query portable: do not label
embedded reference syntax `plain_data` merely to admit it. Declare the codec
when its transport is supported, with native and divergent-ID evidence.

Declare `block-attribute-values/v1` and a `block_values` map keyed by block,
then exact attribute name. Each authored attribute uses the existing value
rules: `ref` with optional `cast`, `json_refs`/`key_refs`, or `plain_data:true`.
For example:

```json
"block_values": {
  "example/gallery": {
    "image": {"class":"authored", "json_refs":[{"path":"$.id", "kind":"post"}]},
    "slides": {"class":"authored", "json_refs":[{"path":"$.image.id", "kind":"post"}]},
    "postIds": {"class":"authored", "ref":"post[]", "cast":"csv"},
    "queriedPostsData": {"class":"derived"}
  }
}
```

The shared JSON path walker transparently handles repeater lists. Native IDs
must match their declared integer/string type; CSV uses positive decimal IDs
separated by single commas, with no whitespace, empty members or leading
zeros. Empty CSV is allowed. Canonical CSV is a token list, restored to a
string on apply. Order and duplicate selections are preserved. Missing targets
use the existing dangling-reference and unscoped-reference gates.

Use `derived` only after proving the attribute is a reproducible native cache.
Capture removes that attribute; compilation and apply refuse it if it appears
in canonical content. The plugin must rebuild it from authored inputs when
needed. A preview's presence in saved JSON alone is not evidence of ownership.
Qi Blocks' query previews and contact-form HTML motivated this distinction;
its adapter still needs native frontend and editor reopen evidence.

One manifest owns a block using these rules. Disjoint legacy `block_attrs`
can coexist in that manifest; overlapping attributes and whole-block codecs
refuse. Site policy cannot introduce or replace this grammar. `plain_data`
rewrites URL-bearing strings without interpreting arbitrary numbers as IDs;
it grants no executable decoder or privacy exemption. Native PHP container
wrapping belongs to options, not Gutenberg JSON.

Prove full PostCapture, immutable compilation without WordPress/database
contact, checked PostMaterializer SQL with different IDs/URLs, rollback after
an earlier write, retry, repeat and complete recapture. Include widget content
when the same block can be stored there. `regress-block-attribute-values` is
the engine pin; native plugin render and editor validation remain capsule work.

### Closing a native block attribute roster

Before treating a complete native block inventory as a closed contract,
negotiate `block-attribute-closure/v1` alongside `block-attribute-values/v1`
and declare its exact owned blocks:

```json
"block_attribute_closure": ["example/card", "example/gallery"]
```

The list is sorted, unique and bounded to 4,096 exact names of at most 128
bytes. Each name must have a `block_values` map owned by this manifest. Its
normalized value names plus disjoint legacy `block_attrs` paths form the
complete allowed roster. Unknown attributes refuse by presence across Capture,
immutable post/widget compilation, lint and Apply, including empty values.
The diagnostic displays no unknown field name or value. Site policy cannot
replace the roster; this feature refines the existing block-values capability.

Before opting in, reconcile the complete exact-artifact schema with native
Save outputs in every claimed content context. Numeric and boolean defaults
need explicit rules too; WordPress can inject supported attributes beyond the
plugin's own registration. An extension may introduce a valid native field
whose meaning the adapter has not reviewed; declare its correct transport or
keep that extension unsupported. Qualification of editor controls remains a
separate native-behavior obligation; a schema roster is not that evidence.
Do not use a generic plain-data catch-all or infer reference semantics from
field spelling. Qi's unknown nested `extension.entity` reproduced successful
Capture, empty lint findings and immutable compilation with a raw source ID.
The shared closure regression pins this boundary and preserves existing open
declarations. Nested fields still need their own value contract.

Closed rosters also validate original comment bytes through the pure block
attribute reader before WordPress parses them. Its parser can silently discard
malformed JSON. Capture and Apply must refuse that input instead; parser-free
post/widget Lint exercises the same boundary while retaining existing parser
deferrals for other reference findings.

### Objects that mix selectors, flags and query text

A query object is not one reference leaf. Visual Portfolio's native `postsQuery`
stores ordered string-ID lists beside flags, search text and a custom query.
Switching away from the custom source retains its hidden query text. Deleting
the last selected post retains its missing ID; dropping that reference would
turn the plugin's empty `post__in` into an unrestricted query.

Negotiate `block-value-contracts/v1` alongside `block-attribute-values/v1` and
compose the existing codecs under exact `object_fields`:

```json
"postsQuery": {"class":"authored", "object_fields": {
  "ids": {"class":"authored", "ref":"post[]", "cast":"string", "on_unmapped":"refuse"},
  "keyword": {"class":"authored", "plain_data":true},
  "customQuery": {"class":"authored", "enum":[""]}
}}
```

Every present field must be declared; absence stays absent. Unknown fields,
empty objects and non-object containers refuse. Each member reuses one authored
codec, including nested objects; `derived` members are not admitted. Objects are
bounded to 256 fields, four nesting levels and 65,536 expanded contract rules per
manifest, including group expansion. A literal `enum` admits 1–64 distinct
integers, booleans, nulls or ASCII codes `[A-Za-z0-9_-]` of at most 128 bytes.
Types are strict; literals do not undergo text rewriting. URLs, prose and token
envelopes require their ordinary codecs.

Structured `json_refs` and `key_refs` resolve durable identity tokens. User
login identities use `ref: user` or `ref: user[]`, including on nested
`object_fields` leaves. A structured rule with `kind: user` refuses at both
manifest validation and the immutable value boundary.

Use `on_unmapped: refuse` on reference leaves when losing one identity changes
meaning. It refuses instead of dropping a missing source identity and forbids
target user fallback; `--force-unresolved-refs` does not waive this contract.
Declared empty selectors retain their native meaning. Rules without this policy
keep their existing behavior. These extensions belong to manifest block values,
not options, metadata or site-policy overrides.

Exercise partial objects, duplicate/order-sensitive IDs, hidden values, missing
IDs, unknown native fields, direct Git edits and privacy clearance through the
existing post and widget compiler/materializer paths. Native Save/reopen and
rendering remain separate capsule evidence; an unexposed control is a recorded
gap, not permission to invent a UI observation.

### Records that mix authored fields and response caches

Use actual media picker selections when investigating a structured attribute.
An empty default or hand-built `{id,url}` example does not reveal whether the
native writer saves its entire API response. Qi's three gallery controls save
nonces, user details and admin links beside their authored image selection.
Classifying the entire record as authored would publish those caches.

After proving which fields the native saver consumes and that its picker can
rehydrate the rest, negotiate `block-record-fields/v1` and add a bounded
`record_fields` declaration to the existing value rule:

```json
"gallery": {
  "class": "authored",
  "record_fields": {"container": "list", "fields": ["id", "url", "alt", "caption"]},
  "json_refs": [{"path": "$.id", "kind": "post"}]
}
```

`container` is explicitly `object` or `list` of records. Capture removes only
unlisted immediate record fields before the ordinary reference, URL and privacy
pipeline. Field order, absence, types, list order and duplicate selections stay
unchanged. A record must contain at least one retained field; an empty list is
valid. This avoids turning an empty object into an array under associative JSON
decoding. The declaration has no recursive selector or implicit defaults.

Each `json_refs` or nested `key_refs` path must start with a retained exact
field. A root key reference, wildcard or recursive first edge cannot silently
lose declared identities. Canonical input containing excluded fields refuses
in compilation, lint and Apply; it is never silently repaired. Existing
repositories need recapture, compilation and new identity pins after this
intentional semantic change. Retained fields keep all ordinary privacy gates.

Preserve real picker output as a regression fixture, replacing temporary
credentials with documented fixture literals. Prove native saved HTML,
fresh editor validation and media-dialog reopening, then repeat those checks
after cross-environment Apply. A source-only projection probe is evidence for
the declaration, not target qualification. Keep differently shaped native
controls on their own rules even when their UI labels look similar.

### Content-selected image crops

Use the plugin's actual editor Save path and inspect both saved content and
the uploads directory. A custom crop may exist outside WordPress's attachment
size metadata. A historical crop cache can include abandoned requests, so it
cannot define authored work or stale-file ownership.

For a crop determined by saved attachment, URL and dimension fields, negotiate
`block-media-derivatives/v1` alongside `block-attribute-values/v1`:

```json
"block_media_derivatives": {
  "example/image": [{
    "attachment": "$.image.id", "url": "$.image.url",
    "width": "$.customWidth", "height": "$.customHeight",
    "crop": true, "filename": "requested-dimensions",
    "dimension_cast": "integer",
    "when": {"key": "size", "equals": "custom"}
  }]
}
```

The same manifest must declare `image.id` as a post reference in this block's
`block_values`. Use `path` for repeated object contexts, exact child paths for
fields and `truncate` only when the native writer demonstrably truncates
fractional numeric dimensions. Inspect both top-level and nested repeater
controls: Qi's Parallax item saves a custom image inside `items[].itemImage`,
which requires `"path": "$.items.itemImage"` with the shared list traversal.
Declare a responsive selection only after proving its editor writer and saved
render consumer. Shared registration helpers can create Tablet/Mobile defaults
even when a particular block disables those controls and never renders them;
those placeholders must not authorize file generation.
The [recipe contract](../../spec/repo-format.md#v330-block-media-derivativesv1--content-selected-image-recipes)
defines the closed shapes and bounds.

Use the shared attachment transaction for these effects. It derives recipes
from saved content, preserves excluded consumers, regenerates content-only
changes and records generated sizes for durable ownership. Existing native
crops need a declared target-content selection, native attachment ownership
and the exact observed original blob before their files can be adopted.
Unselected files, including equal-byte neighbors, remain outside that grant.
Do not add a plugin executable to copy arbitrary cache paths into metadata.

Prove same-size repeat, resized content with an unchanged attachment, multiple
consumers, scoped preservation, removal of the last consumer, original path
and blob changes, existing native crops, unrelated file collisions, native
failures and interrupted metadata publication. Exercise first Apply on a fresh
target with uncaptured stock categories and pages.
The locked media reader observes its block/original/widget inputs without
minting identities or requiring a full export; unmanaged crop consumers must
remain visible to the guard. Compare real output dimensions
and filenames separately, including oversized and zero-dimension requests if
the editor can produce them. Request every selected output image on the fresh
target and check its decoded dimensions. Canonical recapture alone cannot
detect an omitted recipe: Qi's nested item converged while its image returned
404. Test native Save, frontend rendering, editor
reopen and fresh-process convergence; offline image doubles cannot qualify
those outcomes. Qi Blocks motivated this mechanism, but its native adapter
qualification remains pending.

### Keep repeated block declarations reviewable

Keep current identity evidence with its owner. A capsule's digest changes when
its declaration or named executable bytes change; pin that intentional
transition in the capsule's regression. Shared identity-mechanism tests use
controlled declarations. A historical product-image proof must retain the
actual historical package/platform input bytes, verify their complete file
hashes, and load the ordinary library and policy readers against those inputs.
Do not rebuild a historical digest or snapshot fixture from today's capsules:
Qi's closed-roster edit exposed fourteen unrelated disposition-split failures
from exactly that coupling. Do not add each later adapter edit to a global
overlay of historical digests.

A native block schema is authoring evidence, not a reason to copy an identical
value rule thousands of times into a manifest. Qi's first draft expanded
7,497 rules across 49 blocks into 31,397 lines; 7,269 rules were identical
plain-data declarations. The field names remain useful evidence, but the
repetition hides media and query references during review.

Negotiate `block-attribute-groups/v1` alongside `block-attribute-values/v1` and
use `block_values.groups` for exact fields sharing a value rule:

```json
{
  "block_values": {
    "groups": [
      {
        "blocks": ["example/card", "example/banner"],
        "attributes": ["title", "description"],
        "value": {"class": "authored", "plain_data": true}
      }
    ]
  }
}
```

Each group declares precisely the Cartesian product of its block and attribute
lists. Keep reference and derived-value groups easy to identify. Exact maps
for other fields can coexist beside `groups`; declaring a field twice refuses,
even when both rules are equal. Wildcards, implicit defaults and precedence
are absent. The existing block grammar normalizes this syntax for capture,
compilation, lint, apply, reference-keyspace and derivative-ownership checks;
manifest identity continues to bind the authored declaration.

When an exact schema repeats a finite suffix family, also negotiate
`block-attribute-name-products/v1`. A group may replace repeated names with
paired `attribute_bases` and `attribute_suffixes` lists:

```json
{
  "blocks": ["example/card"],
  "attribute_bases": ["paddingUnit", "marginUnit"],
  "attribute_suffixes": ["", "Mobile", "Tablet"],
  "value": {"class": "authored", "plain_data": true}
}
```

This declares six exact names. Either product list without the other refuses;
duplicate bases, suffixes or expanded names refuse, including a collision with
an `attributes` member in the same group. The empty suffix is explicit. There
is no pattern match, runtime schema lookup or default rule. Use another group
when a second suffix family needs the same value rule rather than hiding
multiple products in one declaration.

When compacting an existing declaration, pin the canonical expanded-map digest
and verify it through `BlockValueGrammar::attribute_maps()`, then rerun the
native fixture and hostile-reference tests. Store the compact capsule and the
inventory digest. The Qi capsule reduces its declaration to 177 groups, with
98 exact name products covering 485 bases and 1,455 responsive attribute
names. Its manifest falls from 120,737 to 93,299 bytes and from 2,092 to 1,901
lines while preserving the exact original 7,497 field rules.

Exercise the complete candidate's publication clearance as well as the block
codec. Qi's native corpus exposed phone false positives in hyphenated numeric
identifiers, SVG viewport coordinates and UUIDs cut by overlapping scan windows.
These belong in the shared personal-data detector: SVG's
[four-number viewport grammar](https://www.w3.org/TR/SVG2/coords.html#ViewBoxAttribute)
uses the existing HTML reader, and complete UUIDs are recognized before phone
windows are formed. Keep named contact fields, neighboring private prose and
secrets protected. Generated markup is not a reason to grant an entire CSS
option or post body a privacy exception.

### Encoded authored text

Inspect the actual writer before choosing a text declaration. Visual
Portfolio 3.8.1's editor uses JavaScript `encodeURIComponent` after a literal
escape for `--`; its similarly named PHP helper uses form encoding, where
spaces become `+`. Treating either stored string as ordinary text hides URLs
from rebinding. A successful canonical round trip alone does not prove that
the plugin will render target-local URLs.

For a measured URI-component scalar, negotiate `encoded-text-values/v1` and
declare its native framing:

```json
{
  "class": "authored",
  "text_encoding": {
    "codec": "uri-component",
    "escape": {"text": "--", "wire": "_u002d__u002d_"}
  }
}
```

Omit `escape` when the writer has no literal replacement. The shared codec
decodes to canonical UTF-8 text before ordinary URL/query-reference rewriting
and privacy checks, and encodes after target rebinding. It admits exact
options, option patterns, one-level static option subkeys, ordinary
post/term/user metadata and its existing pattern surfaces. Block attributes
also require `block-attribute-values/v1`. Dynamic options, option-name
references and custom-table values have no transport under this feature.

Do not add executable decoding or a fake reference path. The declaration is
closed and exclusive of other value codecs. Malformed percent spelling,
form encoding, non-string values, unsafe controls, ambiguous escape markers
and excess expansion refuse. Site policy may exclude the whole value but
cannot replace its framing; an interpreter cannot introduce or override it.
The exact limits and compatibility contract are in
[the specification](../../spec/repo-format.md#v334-encoded-text-valuesv1--native-scalar-text-framing).

Retain independent native writer vectors. Exercise decoded URLs, query IDs,
Unicode, empty text, quotes, literal plus/percent and escape collisions through
capture, pure compilation, checked SQL, rollback/retry and full recapture.
Include secrets embedded inside quoted CSS strings: WordPress escapes inner
quotes as `\u0022` in block comments. Shared publication clearance opens that
JSON framing for ordinary and unknown blocks, and for block widgets, without
changing saved bytes. `regress-encoded-text-values` exercises these boundaries;
native editor Save/reopen and rendering still belong to the adapter's evidence.

### Native PHP container types and insertion order

Inspect raw option bytes after using the plugin's real writer. Qi Blocks 1.5.2
persists `qi_blocks_global_styles` as an outer PHP array containing nested
`stdClass` values; its `posts` map uses post IDs as keys. Ordinary JSON cannot
retain array versus object types or the insertion order canonical sorting
removes. `plain_data` alone deliberately refuses PHP objects.

For a whole authored option with this measured requirement, negotiate
`php-container-values/v1` and declare `php_containers: true`. Choose
`plain_data: true` when there are no identity positions, or use existing
`json_refs` paths into the portable representation. For an ID-keyed container,
declare `key_refs: {"path":"$.root.items.posts","kind":"post",
"container":"php"}`. This path selects the complete typed node. The shared
codec rewrites its keys and ordering records together, including a dangling
entry's removal; a second reference path over ordering metadata would break
that invariant. Pattern-owned options also need their normal namespace
discovery declaration. The closed representation and bounds are in
[the format specification](../../spec/repo-format.md#v328-php-container-valuesv1--ordered-native-php-option-containers).

Do not add an interpreter or a provider just to deserialize this data. Only
builtin `stdClass` is admitted; arbitrary classes, enums, references, cycles,
shared objects, noncanonical serialization, binary text and nonfinite floats
refuse. Existing scalar/array rules keep their storage contract. Site policy
can exclude the complete option but cannot replace its codec; interpreter
classification cannot introduce or override it. Metadata, subkeys, dynamic
option resolvers and custom-table columns are outside this feature.

Prove actual native writer bytes, empty arrays and objects, mixed scalar types,
map order, source-home URLs and divergent IDs through capture, complete
compilation, SQL apply and recapture. Include malformed native and canonical
data, colliding keys, a late write failure, rollback and retry. The shared
`regress-php-container-values` suite proves the engine mechanism; each adapter
still needs native render and lifecycle evidence for its own settings.

### Identity repeated inside a stored string

Inspect native consumers as well as writers. Qi Blocks stores a page ID as a
`qi_blocks_global_styles.posts` map key and repeats that ID in saved selectors
such as `body[class*="-13"]`. The frontend emits the selector verbatim, so
rebinding only the owning key leaves the target page unstyled.

For this storage shape, additionally negotiate `key-bound-strings/v1` and add
`bound_strings` to the existing typed `key_refs` declaration. Each entry has
`path`, `prefix` and `suffix`; the path is relative to each owning map value,
and the strings framing the identity are literal, stable native syntax. Qi's
selector declaration is:

```json
{"path":"$.items.*.items.values.items.items.selector","prefix":"body[class*=\"-","suffix":"\"]"}
```

The shared codec requires every selected string to contain its owning key
inside every matching frame. It handles repeated occurrences and refuses a
missing frame, a different ID/token, a malformed string or overlapping
reference ownership. Absent optional paths remain valid. Canonical frames
contain the same `{{kind:uuid}}` token as their map key, so immutable compilation
can check the relationship without a database. Typed key, order and value
rewrites remain atomic, including dangling-entry removal. URL rewriting stays
in the existing text codec; apply binds frames before that codec can consume
an embedded query-reference token.

Use `regress-key-bound-strings` for engine coverage and the capsule's real
saved values for plugin coverage. Prove native rendering with different target
IDs; exact recapture alone can miss a consistently preserved stale selector.
This feature belongs to static whole PHP-container options and option patterns.
Do not implement a plugin-specific CSS search/replace executable.

### Portable validation versus WordPress-native predicates

Run your real adapter through the standalone `RepositoryCompiler`, with no
WordPress bootstrap or sanitizer stubs. Classification and repository diagnostics
are portable code: they cannot call plugin runtime or WordPress APIs. A test
double for `wp_kses` masked this boundary in Polylang until the four-plugin
recapture comparison compiled its complete user-meta sidecars on the host.

Exercise the actual host command with an attachment-bearing repository, using
both absolute and caller-relative paths. `RepositoryMediaCatalog` anchors its
media directory to the caller's working directory once, for ordinary, historical
and staged compilation. Individual immutable media files still require absolute
physical paths and full link/hash checks; path admission belongs in that shared
repository boundary, not in each adapter fixture or the raw media-file authority.
Sidecar-only evidence missed this in Polylang, while conformance's
`siterepo/<pair>` argument failed on all three media blobs. Retain the shared
confined tree observer and never omit attachments to make host evidence pass.

When authored metadata must already satisfy native KSES, a v3 manifest declares
`native-value-validation/v1` and adds the following field to its authored rule:

```json
"native_value_validation": {
  "profile": "wordpress-kses/v1",
  "context": "pre_user_description"
}
```

This is a closed engine profile, not an arbitrary sanitizer callback. Exact
post/term/user metadata, metadata patterns, and a feature-enrolled interpreter's
metadata answer can use it. The engine retains bounded UTF-8 string checks during
compilation; Capture validates native input and the canonical candidate; target
Plan validates before snapshot work; Apply validates resolved values, locked
classification, and all owned preimages before metadata writes. Repeated
post/term rows are checked individually. A missing native API or changed output
refuses; the engine never sanitizes and silently changes authored bytes.

Test host compilation separately from real WordPress validation. Cover safe HTML
and Unicode, size/control-byte refusals, missing APIs, unsafe desired and existing
rows, repeated/duplicate values, URL rebinding, site overrides, and conflicting
adapter ownership. Then run native conformance and relevant combinations. Adding
this profile moves package identity: recompile and re-pin, never bypass a stale
artifact. New native profiles require an engine contract and evidence first.

Prove each hostile value through ordinary Capture, Plan and Apply, not merely
by observing that native KSES would change it. Separate unsafe existing rows
from unsafe desired repository values: the latter must still pass the portable
compiler before reaching the native gate. A profile editor may sanitize before
storage, so fixture-only raw fault injection must compare-and-swap the exact
owned row and restore only that preimage. Retain complete canonical files,
policy and native owner/neighbor rows before and after each refusal; compare
them before undoing the fixture's own edit. Polylang's biography fixture follows
this split with source Capture and target Plan/Apply controls.

### Repairing a derived post cache

`class: derived` excludes a value from Git and preserves existing target rows.
It does not refresh a cache maintained by a native `save_post` hook: Apply writes
posts directly without invoking arbitrary plugin callbacks. Prove the native
reader after an actual body change; canonical convergence alone can miss a
stale target cache.

If the native reader computes a correct value when an exact post-meta key is
absent, a v3 manifest can declare `post-meta-invalidation/v1` and:

```json
"post_meta": {
  "_vp_words_count": {"class": "derived", "on_post_write": "delete"}
}
```

The shared writer deletes every physical row with this exact key only on the
post or menu item it already materializes. It uses the existing bounded owner
lock, checked SQL, cache invalidation queue and authored transaction, and proves
absence before returning. Other owners, case/space aliases and unrelated
derived/runtime/environment metadata retain their bytes. A post outside the
selected work receives no repair; this declaration is not a stale-cache sweep.

Only an exact static rule may grant this operation. Patterns, site overrides,
interpreter answers, other storage surfaces, callbacks and cache population
refuse. Another adapter or core claim to the same key also refuses, including
matching patterns. The rule permits only `class`, `on_post_write` and an optional
string `note`. Adopting it changes package identity and requires recompilation
and new pins.

Before using it, prove the upstream invalidation semantics, duplicate and absent
rows, rollback/retry, unrelated target preservation, scoped and repeated Apply,
and native reader correctness after complete canonical recapture. Visual
Portfolio 3.8.1's word-count reader motivated the primitive; this declaration
example does not qualify that plugin's other state or lifecycle.

### Deleting what you author

Authoring a post type does not make its rows deletable through WPrism. A capture
that finds an authored row gone mints a *deletion intent*, and the engine
refuses that intent — loudly, at capture — unless a pinned adapter declares the
destructive effects the kind needs: `wprism: deletion intent for post:<type> is
unsupported — no pinned adapter declares its reverse-reference checks and
cascade effects`. WordPress's own cascade behaviour is never inferred. Declare
it:

```json
"deletions": {
  "post:acme_record": {
    "cascades": ["postmeta", "post_revisions", "term_relationships"],
    "guards": []
  }
}
```

`cascades` is the closed required set per kind (`post`: postmeta,
post_revisions, term_relationships; `term`: termmeta, term_taxonomy,
term_relationships; `menu`: those plus menu_items); `guards` lists the
reverse references that must be empty before a delete is allowed (`{table,
column, id_kind, reason}` — `platform/adapter-library/core/manifest.json`'s
`post:attachment` shows a
comments guard and a child-posts guard). An empty guard list is a claim that
nothing references the row: make it only when it is true. The T6 walk in
[docs/grind/adapter-walk.md](../grind/adapter-walk.md) still exercises this
grammar with a site-local WPForms fixture, but its manifest is an authoring-flow
fixture, not a reusable WPForms capability claim. The adversarial review in
[the limitation ledger](adapter-authoring-limitations.md#wpforms-lite-2004--2005)
found local ids inside the form body and block attributes that the fixture does
not migrate. Do not copy that deletion declaration into a product adapter.

#### Can each guard actually lock?

A guard is only worth what its lock boundary is worth. Before the delete, the
engine re-reads every guard under `SELECT … FOR UPDATE` behind
`FORCE INDEX (<index>)`, and it resolves that index from the guard's first
equality column against live `SHOW INDEX`. When no index leads with that
column — or a prefix index is too narrow to cover a declared metadata key —
there is no index to force, InnoDB cannot take the next-key/gap locks that
close concurrent reverse-reference insertion, and the whole deletion refuses:
`guard table 'X' has no complete indexed lock boundary for Y`. Plugin schemas
routinely ship the reverse-reference column unindexed, so this is not a corner
case; it is the first thing to check about a deletion contract you are about to
write.

`wp wprism adapter-deletion-feasibility` runs that identical computation on the
target, over a proposal nothing has declared yet:

```sh
cat > proposal.json <<'JSON'
{"table:nf3_forms": {"guards": [
  {"table": "nf3_actions", "column": "parent_id", "id_kind": "nf3_form", "reason": "actions reference this form"},
  {"table": "nf3_fields",  "column": "parent_id", "id_kind": "nf3_form", "reason": "fields reference this form"}
]}}
JSON
wp wprism adapter-deletion-feasibility --proposal=proposal.json
```

Each guard answers with the covering index name, or `null` plus the reason —
`no index leads with this column`, or `prefix index of N bytes cannot cover a
declared key of M`. That null is the engine's own verdict, not a second
opinion: the report publishes `DeleteGuardEvaluator::lock_index()`'s return
value and refuses to print an explanation that disagrees with it.

A table absent on some supported versions is not a generic optional guard.
After reviewing that topology, declare `"table_absence": "empty"` only on a
guard whose table is also owned by the same manifest. The feasibility report
then returns `table_present: false`, `absence_means_empty: true`, and no index
reason: there is no table to lock. At apply time exact absence has its own
witness, distinct from a present empty table, and is re-censused immediately
before commit under the signed writer exclusion. A present table still needs
the ordinary InnoDB, covering-index, row-lock, and reference-clearance proof.
Required absence, mixed modes for one table, malformed declarations, census
errors, and near matches all refuse.

#### Active executable owners and successful deletion evidence

A complete database guard list still says nothing about code that can create a
new reverse reference through a delete hook. For a plugin-owned selector whose
safety depends on the exact active executable set, declare
`"executable_owner_boundary": "all_active_owners"` and bind the declaring
plugin or theme to its reviewed `wprism-executable-tree/v1` identities in
`executable_owner_identities`. The WooCommerce `post:product` declaration is
the reference implementation.

This boundary is intentionally conjunctive. Every active plugin must have a
pinned adapter declaration for that same selector, and every active plugin,
theme, MU plugin, and drop-in must have an exact
`wprism-deletion-owner-agreements/v2` site agreement for its live tree. A site
agreement cannot manufacture adapter authority for a plugin. Do not copy a
selector into another adapter merely to make a combination test pass: that
declaration requires its own source audit of reverse references, hook effects,
and exact executable identities, and it changes the adapter's fleet-visible
digest.

Generate each reviewed identity through the target's bounded engine observer,
not a package-local filesystem walker:

```sh
wp wprism executable-owner-observe --owner=plugin:woocommerce/woocommerce.php
wp wprism executable-owner-observe --owner=theme:storefront
```

The command returns only canonical `{owner, code_identity}` JSON for that one
caller-selected owner. It cannot enumerate required owners, add a rationale,
write site policy, satisfy an adapter declaration, or grant deletion authority.
The author must still derive the complete active roster, review every tree,
and construct the closed v2 agreement explicitly.

Direct `wp wprism apply --with-deletes` cannot mint the signed external-writer
exclusion required for destructive work. It is a refusal-only test surface:
assert `deletion_writer_exclusion_required` and byte-for-byte target
preservation there. A successful deletion proof must use `wprism promote
<env> --with-deletes` on an adopted target with the complete checkpoint,
code-release, upload, effect, and exclusion recovery profile. The shared SSH
adoption harness admits one tracked adapter or participant-declared integration
scenario extension through `WPRISM_SSH_ADOPT_EXTENSION`; source
`sandbox/tests/lib/ssh_adopt_extension.sh` and reuse its closed helpers:
`wprism_ssh_install_locked_plugin`, `wprism_ssh_stage_code_inventory`,
`wprism_ssh_stage_generation_releases`,
`wprism_ssh_publish_post_tombstone`, and
`wprism_ssh_enroll_full_recovery`. They resolve package- or
scenario-participant artifact locks, reject partial active-code inventories,
derive generations from signed authority, and ask `Deletion` to author the
canonical tombstone; they never mint policy, owner agreements, or promotion
authority. Keep exact plugin initialization, pins/policy, semantic preimages,
and postconditions in the extension. The installer takes the artifact slug,
version, exact lock role (`certified-boundary`, `exercise-fixture` or
`refusal-fixture`) and an explicit `activate` or `inactive` choice. For example,
`wprism_ssh_install_locked_plugin example 1.0 exercise-fixture inactive`
installs the pinned authoring artifact without running activation. A role
mismatch refuses before downloading; digest, native version and active state
must match the request. Use inactive installation when the capsule must first
establish the plugin's native activation context. Artifact roles describe test
provenance and do not promote the adapter's readiness or runtime authority.
Docker scenarios, whose transport has no
recovery handoff, should prove opposed load order, round trip, and no-mutation
refusal rather than pretending a direct destructive apply can succeed.

That transaction proof includes the *incoming* foreign-key graph, not only
keys visible inside the WordPress schema. A child table in another schema can
receive `CASCADE` or `SET NULL` writes when WPrism changes its visible parent,
even when ordinary `REFERENTIAL_CONSTRAINTS` rows are hidden from the WordPress
account. The platform therefore declares the scoped
`complete-innodb-foreign-key-census/v1` profile: MySQL 8.4 uses
`INNODB_FOREIGN`, MariaDB 11 uses `INNODB_SYS_FOREIGN`, and both require a
direct global `PROCESS` grant. `wprism doctor` warns when that mutation
authority is absent; read-only authoring/capture remains available, while an
actual transactional database mutation refuses before its first write. Do not
work around the warning with a same-schema `SELECT` grant or a partial
constraint query; neither can prove that the hidden child does not exist.

The proposal is deliberately *not* a manifest fragment. It carries no
`cascades` — the report refuses one by name — proposes nothing, and declares
`authority: false`, because a covering index is a necessary condition for a
deletion contract and never a sufficient one. The example above is Ninja Forms,
and its shipped `parent_id` columns are unindexed: the honest conclusion is the
one `adapter-packages/ninja-forms/package/manifest.json` records, that WPrism does not advertise
`table:nf3_forms` deletion. Deciding that is your job. The report only makes
sure you are deciding it before an operator meets it.

## Precedence, in one sentence each

- **Site policy always wins.** A rule in `site.wprism.json`'s `policy` outranks
  every manifest, for every section.
- **A non-core manifest outranks `core`.** The loader keeps scanning past a
  `core` match specifically so a plugin's own declaration takes it — a
  reclassification of a core option by a plugin manifest is legal and loud.
- **Patterns are the last resort.** `option_patterns`, `post_meta_patterns`,
  `meta_patterns`, and their kin are consulted only after every exact rule has
  missed. Prefer `post_meta_patterns` for a family proved only in
  `wp_postmeta`; legacy `meta_patterns` deliberately reaches term metadata too.
- **Anything still unmatched is unclassified**, which is a loud abort, not a
  default.

## Declaring repair work: actions and providers

Apply writes rows directly and fires no hooks — and the hooks it skips are also
what maintain a plugin's derived state: indexables, lookup tables, generated
CSS, blanket caches. A manifest declares that repair **as data**, in a top-level
`"actions"` list. The full grammar is the "Structured rebuild actions and
providers" bullet in
[spec/repo-format.md § Adapter manifests (package format)](../../spec/repo-format.md#adapter-manifests-package-format);
below is the shape of the decision, not the schema.

Every entry declares a `kind`, and choosing between the two is the whole design:

- **`"kind": "native"`** names an action from the engine's **closed
  vocabulary** — operations whose meaning belongs to WordPress core and is
  identical no matter which plugin declared them. v1 is exactly one action,
  `transient.delete`, taking a bounded-string `name`. Reviewed engine code
  executes it and confirms the result by reading the value back.
- **`"kind": "provider"`** names a capability of a provider declared in the
  *same* manifest's top-level `"providers"` list — anything plugin-specific.

A manifest cannot mint a native action: an unknown name, an unknown argument
key, or a mistyped argument is refused at manifest load, before any target
contact. Arguments are typed scalars checked against a per-action schema, never
command strings handed to a shell, `eval`, or WP-CLI — which is exactly what
keeps executable text out of the channel. **The free-form `rebuilders` channel
is gone**: a manifest declaring it, even as an empty list and even inside a
frozen policy snapshot, is refused at load — porting an older manifest starts
there.

Both kinds may declare `triggers` and `effects`. `triggers` normally uses the
same exact canonical-surface grammar apply projects from authored work
(`(post|term|table|option|entity):<name>`). A manifest declaring
`post-kind-action-trigger/v1` may additionally use `post:*`, which matches only
concrete `post:<type>` surfaces and gives an entity-scoped provider the concrete
kind/id rows, never the wildcard. Use that bounded trigger when the plugin owns
the same derived behavior for every registered CPT and an enumerated list would
silently miss future or site-defined types. It grants no term, table, option or
`entity:` authority; a provider declaring durable batch-context channels must
still use exact post-type triggers because marker ownership is per concrete
type. Omit `triggers` and the action is unscoped, firing for any non-empty
surface set, while a read-only apply fires nothing.
`effects` feeds the bounded-reversibility inventory; omitting it records an
explicit irreversible fallback row rather than silently claiming reversibility.

Treat a trigger list as a dependency-closure claim, not a performance hint.
List triggers only when every input capable of changing the derived output is
inside that closed surface set. Native permalink or routing work commonly
depends on options, terms, authors, every registered post type, and plugin
callbacks; a partial `post:*` list is then false. Omit `triggers` so every
non-empty authored apply repairs the projection, and make the provider's
bounded readback witness those effective inputs. Scoped apply admits that
global action only when it is `kind:provider`, its declaration and effects are
already hashed by the scope contract, and the exact capability successfully
negotiates an operation-bound invoke/reconcile contract. Untriggered native
actions and legacy providers still refuse before mutation.

Assert that boundary at its two public projections. The scope contract's
`potential_actions[].declaration` carries the provider, capability and effects;
the scoped plan intentionally carries only
`selected_actions[].{manifest,index,declaration_hash}`. Derive that hash with
`Canon::encode()` over the contract declaration and compare the complete
three-field plan row. Requiring private provider fields in the plan both tests
a shape the product does not publish and misses the actual declaration hash.
When a live harness loads `Canon`, resolve it from the runner-exported
`PAIR_SOURCE_ROOT`; `WPRISM_SOURCE_ROOT` is only an optional caller override and
is not part of the conformance-hook environment contract.

### Providers

A provider declares `{"id", "version", "source", "plugin", "capabilities"}`.
Ids are globally unique across pinned manifests — a conflict is a refusal,
because pin order must never decide which code runs — and its `plugin` must
match the manifest's own `plugin` claim, so the executable half stays inside
the version window the declarative half was certified for.

`"source": "manifest"` is code you ship:
`package/runtime/providers/<id>.php`, under
the same trust boundary as interpreters, digest-bound into the adapter
identity. `"source": "plugin"` is advertised by the installed plugin itself
through the `wprism_providers` filter and trusted as part of it; that file is
deliberately *not* digest-bound, because the installed plugin — checked against
`version_range` — is its identity anchor.

Every manifest-shipped provider, interpreter, and regenerator crosses the same
engine loader. It derives path, expected class, component hash, and adapter hash
from the already validated artifact-identity row; refuses symlinks, byte/stat
drift, preloaded or autoload-substituted symbols, and normalized PHP class-name
collisions; and invalidates an enabled opcode cache before the one permitted
load. Do not `require` another package executable or recreate these checks in
adapter code. Put shared loading mechanics in that engine boundary and keep the
runtime file to its declared plugin semantics.

A provider may additionally declare `"requires"`, a closed object naming the
environment its executable half needs before the engine will load it:

- `functions` / `classes` — non-empty lists of PHP symbol names (a leading
  backslash and namespace separators are allowed), checked with
  `function_exists()` / `class_exists()`.
- `plugin_version` / `wordpress_version` / `php_version` — each a `{min, max}`
  window, min inclusive and max exclusive, the same arithmetic `version_range`
  uses. `plugin_version` bounds the owning plugin *independently* of the
  manifest's own `version_range`, so a provider may require a tighter window
  than its adapter is certified across; `wordpress_version` reads
  `get_bloginfo('version')`; `php_version` reads `PHP_VERSION`.

The object must be non-empty and every key comes from that closed set — an
unknown key or an empty `{}` is refused at load. `requires` is manifest bytes,
so it folds into the certified adapter identity: adding it to a shipped manifest
is a bundle event, not a free edit. When any declared requirement is unmet,
negotiation refuses **before the provider file is loaded or its plugin registry
is consulted** — strictly earlier than any check the provider code could run
itself — with a single `provider_requirement_unmet` problem naming the provider,
its owning plugin, the declaring manifest, every unmet requirement at once, and
a remediation. Nothing provider- or value-controlled reaches that message: only
load-validated symbol names and version strings.

Before the first target mutation, apply **negotiates** every provider its
selected actions reach: contract shape, exact identity match, owning plugin
installed and active and in range, every declared `requires` satisfied, a
well-formed capability declaration
(argument schema, read/write surface summary, site or entity scope,
`idempotent` — required `true`, since apply's retry machinery re-fires the
rebuild pass — and a `timeout_seconds` budget), and the manifest's arguments
valid against it. Any miss refuses with per-problem remediation, so an
incompatible capability fails *before* destructive writes, never after commit.

Invocation returns a receipt whose `verified` must be exactly `true` on the
strength of a **value-level readback**. Command-success-only verification is
refused — an exit code is not evidence that derived state was repaired. A
successful native action surfaces in apply's output as
`native action fired: <action> (verified)`.

`verified: true` is not taken on your word where the engine can check it. Around
your call it reads, itself, every surface you declared that it has a complete
bounded reader for — `option:<name>` today, because that is the only one of the
five surface kinds naming an extent that can be witnessed both completely and
without scanning a core table twice per apply — and compares the two readings.
Two things then refuse: a receipt whose `before !== after` when **every**
declared `writes` surface is observable and **none** of them moved, and a
surface you declared under `reads` that moved across the call (declare it under
`writes` if your capability writes it). Neither can displace the malformed,
unverified, or over-budget refusals; all three are decided first. A capability
whose declared surfaces the engine cannot read — anything `table:`, `post:`,
`term:` or `entity:` — is never refused for this: the gap is the reader's, and
negotiation publishes it under `surface_observation` before apply mutates
anything, rather than discovering it mid-write. The cost is one checked read per
observable surface per pass, two passes per invocation, and exactly zero for a
capability that declared none.

Your `before`/`after` are **public output** — they reach `wp wprism apply
--format=json` — so the engine publishes a bounded projection of them rather
than your bytes. A string over 512 bytes, one carrying control bytes or invalid
UTF-8, one matching the shared secret grammar, a map key breaking the same
rules, a container nested past 4 levels or holding over 128 entries, and a
whole value still over 8 KiB after all of that are each replaced by
`<wprism:receipt-witness/v1:<reason>:sha256:<digest>>`. The digest is taken over
the raw value at every level, so **equal values still publish equal bytes and
unequal ones still publish unequal bytes**: `before === after` stays decidable
from the published receipt, which is the whole point of returning both. A
receipt carrying an object, a resource, or a non-finite number is refused
outright — the engine will not summarize what it cannot read. Nothing retains
the raw values anywhere; if you want a specific fact to survive publication,
summarize it yourself (a count, a hash, a short outcome string) instead of
returning raw state and hoping it fits. The secrecy and control screens are
per-leaf and pattern-based (C0/DEL control bytes; the shared refusal screen's
secret grammar): a credential split across entries, base64/hex-encoded, or
carried in C1/zero-width/bidi codepoints is NOT detected — do not put
credentials in receipts at all.

An argument may be a scalar, a `list<string>`, or a `list<object>` — the last
declaring its own closed `fields` vocabulary of `bool`/`int`/`string`, exactly
one level deep, so a row is structured without becoming a free-form payload. An
entity-scoped capability may additionally declare
`"context": ["deletions", "reparents", "retry", "always_on_write"]` (any subset,
no duplicates, refused on `scope: "site"`). Declared channels arrive alongside
the entity batch under the reserved `entities` argument; declare none and the
argument stays exactly the bare row list it has always been.

What each channel delivers — and, where it is narrower than the batch
regenerator channel an adapter may be migrating from, what it does *not*:

- `deletions` — `{kind, uuid, id, post_type, parent_id, child_ids}` per
  tombstone on your own triggering surfaces, for deletes this apply actually
  executed (`--with-deletes`), that a previous incomplete apply had already made
  absent, or that an earlier incomplete apply left a durable receipt for. A
  tombstone this run only planned is not delivered. `id` is `0` when the ledger
  mapping is already gone. `parent_id`/`child_ids` are the engine's pre-delete
  inventory, taken because your capability declared this channel; all six keys
  are always present, so an empty `child_ids` means "no declared children were
  tombstoned", never "the engine did not look".
- `reparents` — `{kind, uuid, id, root_id, old_parent_id, new_parent_id}`, one
  row per derived root, unioned with any durable reparent marker an earlier
  incomplete apply left outstanding. **Bounded by capture**: the engine records a
  reparent receipt for post types with a batch `regen_dependency`, or whose
  surface a `reparents`-declaring capability in the same run triggers on — a
  move on any other post type produces no row. One row per root is how a chained
  A→B→C move survives a scalar row grammar; regroup them by `uuid` if your repair
  wants the accumulated root set, and do not collapse to the old/new pair, which
  silently strands the first root.
- `retry` — whether this apply is retrying an incomplete one.
- `always_on_write` — a flag stating you fired on an always-on basis. It mirrors
  `regen_dependency`'s flag, which suppresses a per-candidate existence check
  and never creates candidates: **it does not make your capability fire on an
  empty run.** A capability fires when its entity batch or an evidence channel
  carries something; otherwise apply records an explicit skip receipt.

Declaring a channel also makes you the OWNER of the durable bookkeeping behind
it, which is what makes the channels a retry queue rather than a one-shot read.
A `regen_delete_context:`/`regen_reparent_context:` marker on a surface your
action triggers on is deleted only after your receipt says `verified: true` —
and only on your own triggering surfaces, so your receipt never retires another
adapter's outstanding evidence. Ownership is decided from the PINNED manifest,
not from what a given run happened to select: an apply that touched nothing on
your surface leaves your markers alone rather than reading "no work here" as "no
consumer exists". They are swept, with a warning naming the marker and the
channel nobody consumed, when a run does reach the surface and no negotiated
capability wants the channel, or when no pinned action claims it at all. Two
consequences to plan for: a marker for a plugin you have pinned but deactivated
persists rather than decaying, and every outstanding receipt is listed in
`wprism plan` / `wprism status` (`regen_context`, which reports not-ok while one
stands) — visible debt rather than silent debt. Exactly one capability may
consume a given channel on a given surface; a second one is refused at
negotiation, because the clear is per-marker and the second consumer would lose
the evidence its own retry needs. The entity batch works the same
way through `regen_pending:<uuid>`: armed before the call for every post-kind
entity delivered, cleared on a verified receipt, and unioned back into a later
run's batch when it was not — which is the only path by which a failed repair
retries at all, since a plan's content hash never reflects derived state. Every
entity row is also filtered against that run's deletions (ids and `child_ids`),
so a deleted id never reaches you as live work. This is where `idempotent: true`
earns its keep: a retry re-delivers exactly the batch that failed.

Those three marker prefixes are SHARED with the batch `regen_dependency`
channel on purpose — one retry vocabulary, one `wprism plan` / `wprism status`
projection — so a post type may be claimed by only one of them. A capability
declaring any channel while triggering on a post type that also declares an
enabled batch `regen_dependency` is refused at negotiation, before any target
mutation, naming both claimants: that refusal is what a half-finished migration
looks like, and the fix is to finish it (drop the `regen_dependency`; its
`batch`, `verify`, and `effects` belong on the action now).

One thing the contract does not give you: a heartbeat. `invoke()` receives a
capability name and typed arguments and nothing else, so apply renews the
promotion lease immediately before and after your call and cannot renew during
it. That does not make a long call self-abort — the lease heartbeat tolerates an
expired lease while the promotion's process fence is continuous, so the renewal
on the far side still succeeds unless another writer actually took the lock in
the meantime. What a long call really costs is that window in which the lock is
acquirable by someone else, so declare `timeout_seconds` at the lease TTL and an
overrun is reported once, with its measured duration, instead of later as a lock
loss nobody can attribute.

A capability's own database reads are the read twin of the engine's mutation
path (`\WPrism\Db`), and share its discipline. WordPress's `wpdb` read methods
return an empty-looking value on a failed query rather than throwing:
`get_var()`/`get_row()` return `null`, while core `get_col()`/`get_results()`
return an empty array after `query()` reset `last_result`; compatible drivers
may additionally return a non-array failure shape. A capability that trusted
only the bare value could therefore clear a `verified: true` receipt on a query
that never ran. Route every
decision-making read through
`\WPrism\ProviderSdk::checked_get_var|checked_get_col|checked_get_row|checked_get_results($sql, $context)`,
which first applies the engine's closed SQL lexer and refuses mutation verbs,
multiple statements, comments, output/file functions, variable assignment, and
unreviewed stored/UDF calls before `wpdb` transport. It then clears
`last_error`, runs the read, and fails on both the read's own failure shape
*and* any driver error — never trust an empty result as convergence. Like `Db`,
the `$context` is operation-level, never value-level:
`last_error` and the rendered SQL can echo option/meta payloads, so the SDK
keeps the SQL and the driver text out of its failure and names only your
context. Keep values out of your own messages the same way.

Put related reads inside
`ProviderSdk::database_read_contract_snapshot($context, $read)`. The engine
derives its physical read profile from the capability's already-validated
`reads` and `writes` surfaces, starts one server-enforced read-only consistent
snapshot, and refuses a query outside that profile. If the projection needs
only part of the declared profile, use
`database_read_snapshot($context, $physicalTables, $read)`; the explicit list
must be a subset of the active contract and is a narrowing, never adapter-minted
authority. Use the checked read methods *inside* either callback. Bare checked
reads retain failure and non-mutation hygiene for identity-pinned existing
providers, but do not establish a snapshot or a table boundary and are not
sufficient for new provider evidence. Construction is not authority: only the
exact runtime object returned by the engine's digest/provenance loader can
activate either database scope; a direct, cloned, or unserialized runtime cannot
reuse its declaration to mint a profile.

One native database callback is limited to 1,024 statements, 16 MiB of total
rendered SQL, and 1 MiB per statement. Engine transaction controls do not
consume that callback budget. Capture partitions its separately bounded core
entity/option/chunk work inside the same snapshot. Authored Apply uses the same
engine machinery per adoption, entity phase, option record, widget allocation,
and regeneration-context record inside its one transaction. An options document
is a carrier, not one callback: its records retain independent budgets. Shared
admission, final certification, COMMIT and recovery keep their aggregate limits;
work partitioning grants no extra table, transport or transaction authority.
The opaque partition authority is returned only to the transaction owner.
Providers do not receive that authority
and must not use the engine's work-partition APIs. Calling or reentering a core
helper from native code does not replenish the callback's quota; an oversized
operation needs a genuinely bounded semantic design, not a counter reset.

Large engine-owned keyed strings use `Db::upsert_keyed_strings()`: at most
256 distinct UTF-8 key/value pairs and 7 MiB of aggregate value bytes, split
at complete UTF-8 boundaries into at most 256-KiB data fragments. Whole-field
WordPress validation and a full-width unique-key proof precede the first
write. The original physical transaction owns every append and the complete
key/length/hash readback; an interrupted batch poisons continuation so catching
an exception cannot publish a prefix. WordPress itself finishes placeholder
escaping before the generated fragments enter the unchanged SQL gate. Neither
chunking nor reentry replenishes the enclosing statement or byte quota.
This is shared engine storage, not a new provider permission or a general
large authored-row capability: providers still use their declared SDK boundary.
WPForms Lite 2.0.1.1 exposed the need through a 1,044,395-byte code descriptor,
not through plugin-specific storage semantics.

Audit native API reads with cold caches as well as warm ones before narrowing
that table list. For example, WordPress's `url_to_postid()` creates a `WP_Query`
that can prime post metadata: an id-only answer still needs `postmeta` read
authority. A native lookup can also create, update or delete transient rows;
its name does not make it read-only. Declare those physical dependencies in
the adapter; never widen the engine's table gate to accommodate an incomplete
profile. Exact `option:` surfaces admit leading underscores (private options,
transients and shadow keys), with the same 128-byte bound and no wildcards;
the other four surface namespaces keep their existing first-byte grammar.

Capture and Plan observations cannot borrow a provider's cache-write authority.
If a native getter rebuilds a transient on a cold or expired cache, select a
side-effect-free native interpretation API over bounded physical inputs, or
report the unsupported observation. Do not warm the cache, toggle a permanent
plugin constant, bypass native validation, or widen the capture table profile.
For term inputs, `ProviderSdk::term_rows($taxonomy, $maxRows, $maxBytes, $context)`
reads one exact taxonomy from the caller's already-established snapshot. Both
physical term tables must already be readable. The engine admits a size roster
before bounded hash/value batches, preserves raw driver bytes, and refuses an
orphan, duplicate identity, failed read, changed roster or exceeded budget.
It does not apply WordPress filters or interpret language, flag, locale or other
plugin semantics. Polylang's capture hook uses this split with its public native
language factory, retaining custom-flag refusals without calling its cache-backed
language-list getter. Exercise empty, cold, warm-but-stale and hostile native
callback cases through the actual protected capture hook; pure helper tests
cannot establish that the adapter chose the safe native API.

When declared plugin tables may legitimately be absent, use
`ProviderSdk::database_schema_snapshot($context, $physicalTables, $read)`.
The engine first discovers exact presence inside an empty read-only profile,
then proves every present table is InnoDB and brackets the callback with the
same complete presence map. The callback receives that map and gains read
authority only for tables proved present. Inside a fresh observer the API
reuses the already-established complete read-only contract profile; it never
nests a transaction or reuses a writable/narrower profile. Keep column and
index expectations in the adapter—the engine owns topology consistency, not a
third party's schema semantics.

For complete physical input and preservation witnesses, use
`ProviderSdk::physical_table_rows($descriptor, $context)` inside an already
active contract snapshot or transaction. A consuming manifest must declare
`provider-physical-table-rows/v1` alongside `spec-window/v1`; older v3 engines
then refuse the named dependency before loading executable behavior. The
provider-protocol feature alone does not version SDK methods.
Its closed descriptor names `table`,
ordered unique `columns`, an `identity` list of one to four distinct selected
positive-integer columns (for example `['ID']` or
`['object_id', 'term_taxonomy_id']`),
`max_rows`, `max_raw_bytes`, and `mode` (`rows` or `digest`). It observes the
**whole table** in ascending numeric lexicographic identity-tuple order. No SQL fragments, predicates,
callbacks, native parsing or schema assumptions enter this API. Exact driver
strings and SQL nulls remain distinct; `rows` adds bounded payloads to the same
`row_count`, `raw_bytes` and versioned `rows_sha256` witness returned by `digest`.

The hard frontiers are 16,384 rows, 32 columns, 262,144 observed cells, 32 MiB
of aggregate raw bytes, 1 MiB per cell and 4 MiB per transferred batch. Caller
budgets may only narrow them. The initial size query is limited by both the
row and cell frontier, plus one overflow witness. Size admission precedes payload hashing; batches
are tied to exact identities, lengths and hashes, and final readback rejects a
changed roster. The active profile still grants table access and owns the
transaction and statement budget. A descriptor cannot create or widen it.

When a supported public native API reads options internally and accepts no
input array, `ProviderSdk::native_option_inputs($inputs, $native, $context)`
witnesses its actual selected **current transactional** inputs. Declare `provider-native-option-inputs/v1`
with `spec-window/v1`. Each input names exactly `name`, `default`,
`passed_default` and `reads`; the engine reads its own physical raw/plain
expectation. At most 8 names / 32 total reads / 1 MiB per selected value are
admitted; names are canonical ASCII option identities and defaults are bounded
plain data. Special core-transformed/cache-reserved keys are outside this API.
Use it only inside the authorized mutation callback. The capsule must prove
that its initial reconstruction computation precedes its first write; this
API does not certify that ordering or that its rows predate the transaction.
Later fixed-point passes may witness current transactional inputs again.
Fresh observers and uncertain-commit classifiers stay callback-free.

A before/after getter equality cannot prove an intermediate native input.
The scope refuses pre-existing selected/generic option and alloptions hooks,
and checks the **saved pre-isolation** catch-all presence, not the database
gate's masked empty view. It refuses installation/config mode and substituted
or external caches. The standard core cache's public read view permits raw
entry admission without calling a getter that may clone an object. Stale,
decoded, object-shaped or presence-inconsistent selected entries refuse; no
cache clearing or repopulation manufactures a passing premise. Exact terminal
values, defaults, counts, topology and cleanup are verified. A bounded,
argument-free call trace additionally proves the observer was reached directly
from core `get_option()`: a synthetic matching filter pair cannot count as a
native read. `passed_default` is observed on the absent-row path, where core
actually exposes it. A caught refusal
poisons the database boundary so the caller cannot continue to commit.
Native request-local cache warming remains a non-rollback effect. This scope
does not sandbox arbitrary native PHP, bound all its allocations, or prove
unselected dependencies: the capsule must still declare and physically witness
the complete native computation and its unchanged remainder.

For a public native API whose integer-only dependency is `get_post_type()`,
`ProviderSdk::checked_native_post_types($ids, $context)` admits and invokes that
exact getter itself. Declare `provider-native-post-types/v1` with
`spec-window/v1`. It accepts at most 128 unique positive native integer IDs and
returns types (or exact absence `false`) in caller order. It requires the same
authorized mutation callback and existing posts-table read authority. Zero is
not an absence probe: core can substitute the global post.

Cold core reads use `SELECT *`, so admission proves the standard 23-column
posts schema and sizes every field before native allocation: 1 MiB per cell,
8 MiB total. Added/missing columns refuse; the exact bounded column-name
inventory grants no arbitrary schema access. Selected warm cache entries must
be exact inert `stdClass`/final `WP_Post` objects with matching physical
ID/type/raw filter, closed scalar properties and the same allocation limits.
Other bounded cached fields need not equal physical values because the getter
does not consume them. No cache clearing or replacement manufactures a
premise. Standard core getter/sanitizer/cache provenance, current routing and
post-call physical/cache state are checked. The call may warm request-local
cache and a caught refusal poisons the transaction.

This is a **type-only current read**, not a callback witness or full post,
permalink, metadata, registry or historical-cache proof. If an audited native
consumer repeats the getter immediately afterward, the capsule must prove no
intervening callback can change its admitted premise; observers/classifiers
remain physical-only.

Keep plugin eligibility, subset/remainder selection, native reconstruction and
postcondition meaning in the capsule. Do not copy a SQL pager into a provider
or add a permissive SQL/visitor language merely to migrate a legacy helper.
When extraction introduces a shared engine class, follow the
[new-class ownership and generated-file checklist](../dev-setup.md#the-classmap-autoloader):
explicit dependencies and the module assignment are required alongside the
classmap and public API fixture. Package validation alone does not check that
engine/tooling boundary.

For current native links, `ProviderSdk::checked_native_permalinks($ids,
$context)` owns **both** `home_url()` and the complete `get_permalink($id)`
batch. Declare `provider-native-permalinks/v1` with `spec-window/v1`. It returns
`home` and ordered `permalinks` (including exact absence `false`), admits at
most 128 distinct positive integer IDs, and accepts an empty batch for a
home-only read. Both posts/options table authority and the authorized mutation
callback are required; fresh observers and classifiers remain physical-only.

Its current closed native API families are the builtin post-link branch
(including templates/template parts), pages and ordinarily registered custom
types: plain/pretty/index.php links, front pages, hierarchical ancestors,
and the five stock publish/draft/pending/future/private statuses. Private links
require the already initialized anonymous core user, never a user swap or a
capability grant. Category and author tokens, attachments, revisions, custom
statuses and unreviewed relevant hook participants refuse. This frontier is
not a claim that every plugin or permalink extension composes with the reader.

The engine uses the bounded whole-table physical reader for the standard
23-column post roster (8,192 rows / 16 MiB), then admits complete selected and
ancestor cache fields, exact selected physical option/cache bytes, native
registry and request-scheme inputs, and the relevant hook topology. The stock
constant-home filter and, for private links, the three stock capability filters
are reviewed participants. A defined `WP_HOME` is separate immutable
configuration authority and may intentionally override the durable `home`
row; the returned batch uses that effective native home. WP-CLI's stock
`home_url` closure is separately admitted at its exact priority/arity, Runner
source provenance and normalized token identity, without captures, bound
objects or namespace-shadowed functions. Its intentional scheme override
retains the effective option's scheme even under an HTTPS request. This is
not permission to remove the CLI hook, admit other Runner callbacks or accept
foreign URL filters. The complete physical
options table and warm alloptions cache are separately allocation-bounded
(8,192 entries / 16 MiB, 1 MiB per value), including unrelated entries, because
the native loader can load that whole map. Native functions/classes retain core provenance.
An independent native `WP_Rewrite` instance checks current initialization and
selected custom permastructs; it is never installed into globals. The target's
lazy page structure may populate normally but cannot already contain a stale
route. No target cache or rewrite state is cleared, seeded or repaired. The
reader rechecks native inputs and physical rows after the batch and poisons the
transaction on refusal. These are current-input checks, not historical replay,
HTTP routing evidence or a sandbox for arbitrary extension PHP. Each consuming
capsule still owes actual provider, native-consumer and lifecycle evidence.

The native reader's focused harness is
`make regress-native-permalinks-live`, with `NATIVE_PERMALINK_PAIR`,
`NATIVE_PERMALINK_PORT1`, `NATIVE_PERMALINK_PORT2` and exact
`WPRISM_EXPECTED_SOURCE_SHA` required. It owns one MariaDB pair and retains
private concrete URL/preservation records for host admission after cleanup.
Its fixture-only loader join exercises the real SDK and WordPress, not a
Policy-loaded adapter or a capability/readiness claim.

Do not spell an exact presence probe as raw `SHOW TABLES LIKE '$table'` (or
`SHOW TABLE STATUS LIKE '$table'`): `_` and `%` are LIKE wildcards. Bind
`$wpdb->esc_like($table)` through `%s`; the profile gate decodes only that
exact wpdb spelling back to a physical identifier. The closed SHOW grammar
admits only the reviewed table-presence and table-metadata forms (`CREATE
TABLE`, `COLUMNS`, `FULL COLUMNS`, `INDEX`, and the primary-key `KEYS` probe).
A database operand in `SHOW ... FROM database` is never table authority. The
engine also proves a byte-safe `character_set_client` from the server's
`CHARACTER_SETS.MAXLEN` metadata (single-byte sets plus the exact UTF-8
families) and a compatible `sql_mode` before every profile, including a
zero-table profile, so provider code must not issue `SET` or try to establish
its own lexer premises.

Provider DML follows the same rule. Wrap one atomic native operation in
`ProviderSdk::database_write_contract_transaction($context, $write,
$classifyPhysicalPostimage)`. The engine derives the complete read/write
profile, owns the session identity, isolation, transaction controls, rollback,
and ambiguous-commit settlement, and invokes the classifier in a fresh
read-only snapshot only when the commit outcome needs physical proof. The
classifier returns exactly `DATABASE_POSTIMAGE_APPLIED`,
`DATABASE_POSTIMAGE_NOT_APPLIED`, or `DATABASE_POSTIMAGE_UNKNOWN`; partial or
unreadable state is recovery debt. Inside `$write`, use only the SDK's typed
mutation methods (`database_insert()`, `database_update()`, `database_delete()`
or `database_delete_all()`), each
of which rechecks active transaction authority and writable-table membership.
If the plugin operation needs another mutation shape, add that generic typed
operation to the SDK and its engine tests first. Never send raw DML, call
`Db::start*()`, or author `START`/`COMMIT`/`ROLLBACK` in a package executable.
`database_insert($table, $data, $context, $format = null)` and
`database_update($table, $data, $where, $context, $format = null,
$whereFormat = null)` reuse the engine's existing wpdb field codec and return
affected-row counts. A manifest consuming either must declare
`provider-typed-row-mutations/v1` alongside `spec-window/v1`. This requirement
is independent of physical observation: declare only the APIs the capsule
actually uses, before publishing its new digest. Neither feature grants a
table, transaction, predicate or native hook effect.
Update/delete predicates must be nonempty. The capsule
still owns semantic row selection and before/after preservation proofs; these
methods do not infer an upsert key, regenerate IDs, run native hooks or clear
WordPress caches. Reconcile existing derived rows in place when physical
identity must survive retries, then prove the resulting fixed point.

#### Fresh-process capabilities

Use `manifest-provider-fresh-process/v1` only when a site-wide, idempotent
plugin operation depends on a newly bootstrapped WordPress runtime and cannot
be proved in the applying process. The provider remains the owner of the
plugin-specific call and its complete value-level projection. The engine owns
everything reusable: the WP-CLI command, process/session lifetime, canonical
stdin and receipt transport, one absolute deadline, frozen policy authority,
identity revalidation, cache fencing, database isolation, recovery posture,
and retry boundary. Adapter code must not create a process, construct a command,
parse transport, or issue transaction-control SQL.

Failed child transport is private evidence, not an empty generic error. The
shared bounded process lifecycle retains its status and independently named
stdout/stderr through `PrivateEvidenceException`; the provider dispatcher
emits a distinct request-hashed failure document using the existing bounded
Throwable graph. Neither a zero exit nor a failure document can satisfy the
success receipt grammar. Public command refusals and recovery requirements
remain unchanged. The private recorder still owns its field/graph limits,
binary encoding, original byte counts/hashes and explicit truncation markers:
an incomplete diagnostic is not an exact-cause certificate. Do not add a
plugin-owned subprocess logger or publish opaque child output to recover a
missing cause.

When two successful fresh children disagree, the engine retains a private
`wprism-provider-postimage-comparison/v1` record alongside the unchanged
recovery refusal. Each compared value carries its exact PHP-serialized byte
count and SHA-256, preserving the types and key order the strict comparison
used. Up to 1,024 bytes per value are retained as base64; larger values carry
`retained_complete: false` and no byte payload. These are inert diagnostic
bytes, never runtime unserialization input. The bounded record fits the private
Throwable recorder without field truncation. A hash-only omission is not an
exact postimage reconstruction, and the comparison grants no retry bypass.
This belongs in the shared process boundary, not a plugin-owned logger.

The same rule applies to engine-owned native children. `rewrite.flush` uses
the shared rejected-capture helper for unknown exit statuses, warnings and
malformed or invalid receipts, and retains a launch exception privately. Its
reviewed native-error whitelist, receipt grammar and public sentences remain
separate from transport diagnostics. Test raw whitespace and binary streams,
field truncation with original hashes, parser causes and the real CLI private
writer; also prove a rejected post-mutation receipt does not trigger another
native invocation or masquerade as a rollback. Reuse this engine mechanism
instead of putting another child logger in a plugin executable.

Typed engine refusals can retain private causes without changing their class,
code, standard previous chain or operator sentence. The callback-free
`PrivateEvidenceCarrierException` base binds those causes once through final
engine methods; the private recorder must never duck-call a similarly named
method on an arbitrary plugin throwable. The native database boundary retains
its exact rejected SQL and physical-table profile through this channel before
rethrowing the same isolation refusal. Use that evidence to identify a missing
semantic read, not to widen the profile speculatively or publish SQL publicly.
The existing query refusal, poisoned state and rollback authority remain the
gate; diagnostic retention grants no additional table access.

Native route reads include cold-cache dependencies. Rank Math's four-plugin
lane retained WordPress's exact `update_meta_cache('term')` query, exposing an
omitted `termmeta` read. The capsule declares that table and folds all four
metadata columns into its existing bounded dependency projection; that read
admission alone grants no new write authority.
Test both cold and warm cache paths, duplicate and unrelated rows, failed and
oversized reads, and dependency changes during mutation and between independent
boots. A cache-priming read never authorizes a metadata or option write. A
post-commit observer mismatch is recovery debt, not evidence that the completed
mutation or a competing writer rolled back.

Separate native computation from durable evidence. The measured Rank/Woo
lookup also writes two exact transient options, so its native source/edge
expectation and complete edge/count/marker verification run inside the
existing authorized write transaction. The mutation receipt retains its full
native-computation hash and two-pass idempotence proof. Read-only preimages,
ambiguous-commit classification, post-commit readback and the second fresh boot
use only complete bounded database projections; they do not call route,
eligibility, cache-backed option or plugin settings APIs. Rank's factual source
witness includes every post, even inaccessible types, so the observer never
borrows a writer-selected identity list. This explicitly separates two claims:
native correctness was proved at mutation time, while the later observer
proves durable input/output equality. It does not claim to recompute arbitrary
filter behavior in a future request.

Named option effects are still mapped to physical table authority, not SQL
row-key grants. A provider needing a native cache write must declare its exact
cache effects and prove all unrelated option bytes remain unchanged in the
transaction. Do not exclude a cache prefix or use a collation-sensitive SQL
predicate to hide nearby names. The Rank provider binds every option and
non-marker post-meta row and excludes only its two exact reviewed cache names
and one exact marker key. SQL checkpoints cover the database effects; external
object-cache/filter effects retain their explicit non-rollback declaration.
Test cold, warm, partial, expired, repeatedly evicted and external-cache paths,
hostile callbacks in every read-only phase, unrelated-row drift, ambiguous
commits and cross-boot drift. Warming a fixture is not a cache authority model.

`adapter-package-validate` is a static regression guard for that boundary in
package runtime PHP. It refuses known direct process, transaction, raw-DML and
include spellings, but it is not a hostile-PHP sandbox; digest review and
trusted package provenance are the executable trust boundary. A small
path-and-SHA-pinned registry lets unchanged shipped legacy adapters keep
running until their owner is recertified. The sole runtime exception is also
loader-object-, capability-, digest-, API- and exact-statement-bound; it exists
only for WooCommerce's frozen named mutex calls. Debt rows are not authoring
examples or reusable API. If you touch one, migrate it to the named engine
boundary and remove its row. Never refresh the recorded hash.

The manifest must declare both `manifest-provider-runtime/v1` and
`manifest-provider-fresh-process/v1`. On the manifest-sourced provider, add a
sorted, unique `fresh_process_capabilities` list. Every named capability must
exist in `capabilities` and `contracts`, have `scope: "site"`, declare
`idempotent: true`, and fit the engine's fixed timeout ceiling. Its runtime
implements the normal `invoke_<capability>()` mutation plus
`observe_fresh_postimage_<capability>()` and
`project_fresh_postimage_<capability>()`. Keep all three hooks narrowly about
plugin semantics; if another adapter could reuse a line without knowing the
plugin, that line belongs in the engine or SDK.

The engine runs two independent WordPress boots under one deadline. The first
invokes the provider mutation and projects the claimed complete postimage. The
second invokes no provider mutation: it runs the observer callback under the
canonical `$wpdb` read-only boundary and independently projects durable state.
The parent accepts success only when those projections are exactly equal.
Before both boots it flushes the parent's persistent object cache, and each
child revalidates the compiled artifact, adapter digest, shipped disposition,
provider source, plugin lifecycle/version, capability, and authored arguments.
A timeout, parent death, warning on stderr, malformed or noncanonical envelope,
identity drift, or projection mismatch is recovery debt rather than a retryable
success.

That equality proves the durable postimage, not an independent native
recomputation. For reviewed target-local derived display state, such as a
localized label retained by a normally initialized plugin service, keep the
public native consumer and validate its bounded output before writing. A
same-child reconstruction fixed point and the separate physical observer do
not promise identical labels after a later locale/catalog change. Declare that
environmental meaning and test the actual native consumer under the relevant
locales; do not label option rows as physical provenance for an initialization-
time translation. Nor should a provider read private properties, reinitialize
the service or invent a source-literal-only restriction to prove a cross-boot
invariant its contract never promised. Authored/portable values and other
undeclared environment dependencies gain no exemption from this distinction.

The observer boundary is database-specific, not a general PHP sandbox. It
prevents writes only through the canonical `$wpdb` transport and grants only
the tables implied by declared `option:*` and `table:*` surfaces. Filesystem,
object-cache, network, alternate-database, and plugin-bootstrap effects remain
the adapter's declared recovery obligations; never use them as proof. Do not
use cache-backed helpers such as `get_option()` for durable evidence. Read an
option with `ProviderSdk::checked_durable_option()`, which performs an exact,
bounded size/hash preflight, rejects duplicate and collation-alias rows, and
decodes serialized plain data without constructing classes. Put related table
reads inside one SDK-managed consistent snapshot.

For generated files, use `ProviderSdk::filesystem_tree_snapshot()` for a
confined, entry/depth/byte-bounded observation with full second-pass byte
verification. Parse a digest-witnessed PHP return-literal through
`ProviderSdk::php_literal_data()`; never include a target-generated index
to inspect it. A plugin's public reconstruction/deletion API remains plugin
semantics, covered by its declared irreversible filesystem effect and exact
postcondition tests. The observer is not a mutation lock or a rollback
promise. A future generic filesystem mutation API needs declarative path
authority and engine-discoverable durable recovery, not just an opaque
`provider_resource` token.

For one optional regular file, use
`ProviderSdk::filesystem_file_snapshot($containmentRoot, $canonicalPath)` and
declare `provider-filesystem-file-snapshot/v1` with `spec-window/v1`. The path
is relative to an existing directory root; every parent must exist without
symlinks below that root. The result contains `path`, `state` (`present` or
`absent`), and `file` (null when absent; otherwise `bytes`, `mtime`, permission
`mode`, and `sha256`). An empty file is present. The engine reads the parent
roster twice, checks exact spelling and path identities, and reuses the tree
reader's two byte passes. Missing or unreadable parents, aliases, nonregular
leaves, symlinks, observed races, and files over 16 MiB refuse. Paths are bounded
to 4,096 bytes and 128 components; parent rosters retain the existing
100,000-entry limit. Sibling file contents are not read. Observation grants no
file write, lock, restoration or native-callback authority.

Visual Portfolio 3.8.1 exposed this distinction: its deferred rewrite method
calls WordPress's default hard flush, which may create an absent `.htaccess`.
The existing tree reader can witness a present file but refuses an absent
root. Do not catch that refusal and invent an empty hash. An optional file
witness is evidence for the adapter's separately declared filesystem effect;
it does not make that effect reversible or qualify archive/settings Apply.

A fresh-process package test must cover more than the happy child exit. Exercise
distinct mutation and observer process identities, idempotent replay, exact
postimage mismatch, a poisoned parent cache before each boot, and cache-flush
`false`, non-boolean, and throw outcomes before both phases. Also cover DML and
transaction-control refusal in the observer, malformed/noncanonical transport,
deadline expiry, parent death with descendants, source/digest/disposition/plugin
identity drift between negotiation and execution, and the recovery-required
result when mutation completed but observation did not. The real-process suite
must prove these through separate PHP boots; fakes alone cannot establish the
fresh-runtime claim. Include at least one participant-declared composition
scenario when another adapter can share the same trigger, table, lifecycle, or
plugin-incompatibility boundary.

### Schema settlement is a host deploy phase

Strict target observation never invents a table that the target does not
already have. If a supported plugin creates an authored table only after a
module is enabled, declare that prerequisite with `schema-settlement/v1`; do
not make observation fabricate an empty table, hide the absence in an apply
provider, or overload lifecycle settlement with DDL.

A schema action is a provider action with the exact phase-specific members
below. Rank Math's declaration is the worked example (abridged to one table):

```json
{
  "args": [],
  "capability": "prepare_schema",
  "effects": [
    {
      "id": "rank-math-schema-redirections",
      "kind": "database",
      "mode": "restorable",
      "selector": {
        "scope": "database_checkpoint",
        "type": "table",
        "value": "rank_math_redirections"
      }
    }
  ],
  "kind": "provider",
  "phase": "schema_settle",
  "prepares": ["rank_math_redirections"],
  "provider": "rank-math-state",
  "readiness": "inspect_schema"
}
```

The manifest must declare `schema-settlement/v1`. `args` is exactly `[]`;
`triggers` is forbidden; `prepares` is a non-empty, sorted, duplicate-free list
of tables declared by that manifest; and `readiness` names a second capability
on the same provider. When the provider declares manifest-owned contracts, the
readiness contract has `args: []`, `idempotent: true`, `scope: "site"`, reads
exactly `table:<name>` for every prepared table, and writes nothing. The
preparation contract is also argument-free, idempotent, and site-scoped; both
its `reads` and `writes` lists equal those same prepared table surfaces. These
manifest-owned facts are rejected offline rather than deferred to a live
provider. Plugin-sourced providers advertise independently, so the identical
contract is enforced during live negotiation. Every prepared table has exactly
one matching effect: `kind: "database"`,
`mode: "restorable"`, and a `database_checkpoint` table selector. Two pinned
manifests cannot both acquire schema-settlement authority over the same table.

This phase runs only through host `wprism deploy`; direct `wp wprism deploy`
refuses it. The host checks plugin lifecycle and schema readiness without
mutating, then takes and authenticates the exact database checkpoint. Before
the first lifecycle/provider mutation, the host publishes an external ordered
provider intent covering every applicable phase (for Rank Math,
`lifecycle-retire`, `lifecycle-activate`, `schema-settle`, and
`lifecycle-settle`). When code staging is required, it precedes that intent and
uses its own checkpoint/session receipt. Each fresh lifecycle/provider phase
then runs under the existing durable promotion session, advancing the intent
atomically after successful completion. An
interrupted or failed phase leaves visible recovery debt that fences ordinary
policy loads and host mutation (including adopt, unadopt, and checkpoint prune)
until recovery restores the bound checkpoint. Recovery admits only the exact
retained checkpoint named by that debt; a retry cannot silently continue from
an unrecorded phase or select a different signed/retained row.

Exercise that recovery path, not only the failing provider call. Inject one
preparation failure after the checkpoint and durable schema intent exist, run
host `wprism recover` for the exact retained id, and prove the pre-checkpoint
plugin state plus both database-local and external debt are restored exactly.
If that fixture changes an authored value to manufacture its pre-checkpoint
state, establish the change through capture, commit, and apply. A direct target
edit is ordinary drift after recovery, and the product must refuse to overwrite
it rather than letting the fixture disguise that refusal as failed recovery.
An offline boundary fixture must feed recovery the bytes the agent actually
writes: `SchemaSettlementIntent` persists `Canon::encode()` output (sorted,
pretty JSON with one trailing LF), while recovery's own control records use a
different compact canonical codec. Constructing the fixture with the consumer
codec can make an impossible byte shape pass offline while every real recovery
refuses before database reset.

The checkpoint is bound to the database selected before mutation. The host
asks the isolated control plane for a credential-free digest of `DB_HOST`,
`DB_NAME`, and `$table_prefix`, makes the isolated `db export -` process recheck
that digest, and authenticates it as the first encrypted checkpoint record.
Recovery authenticates the complete ciphertext and compares the current
wp-config target before aborting or acquiring any lease; recovery begin,
reset/import, schema settlement, and lifecycle settlement recheck the same
digest in their own processes. External intents retain only the digest, never
database coordinates or credentials. A checkpoint created before this target
binding existed refuses honestly instead of restoring through a compatibility
fallback.

The readiness and prepare capabilities return `before` and `after` maps keyed
exactly by `prepares`. A readiness row is `{present, schema_hash}` and must be
unchanged across the read-only call. A prepare row is
`{present, schema_hash, row_count, rows_sha256}`. An existing table must remain
byte/content/structure identical. A previously absent table must become present
with `row_count: 0`: schema settlement is create-only and may not seed authored
rows. If durable plugin identity or state proves that a missing table once
existed, readiness refuses before the lease and checkpoint instead of treating
loss as an installation opportunity.

`rows_sha256` is a complete witness, not a sample. A multi-query witness must be
one coherent repeatable-read snapshot and must refuse a storage engine that
cannot provide it. Providers for tables with unbounded payload columns must
first enforce database-side row, per-row-byte, and total-byte limits, reapply
the per-row bound inside every value-bearing query, then hash deterministic
fixed-size primary-key keyset chunks whose worst-case page size is intentional.
Do not materialize the whole table to count or hash it, and do not cap a query
in a way that makes rows beyond the cap invisible. The
[`rank-math-state.php`](../../adapter-packages/rank-math/package/runtime/providers/rank-math-state.php)
provider demonstrates the bounded implementation and its package-local suite
proves mutation beyond the first chunk changes the witness.

### Declaring a plugin incompatibility

When two plugin adapters describe independently valid state models that cannot
safely coexist—such as competing SEO suites claiming the same conceptual site
authority—declare the boundary rather than choosing a winner by pin or plugin
load order. The declaring manifest opts into `plugin-incompatibility/v1` and
adds `incompatible_plugins`, a non-empty, sorted, duplicate-free list of exact
WordPress plugin basenames:

```json
{
  "engine_features": ["plugin-incompatibility/v1", "spec-window/v1"],
  "incompatible_plugins": ["wordpress-seo/wp-seo.php"],
  "plugin": "seo-by-rank-math/rank-math.php"
}
```

Only a plugin-owning manifest may declare the section, and it cannot name its
own basename. One side's declaration is sufficient: if any pinned manifest
claims the named plugin, the shared policy finalizer emits the same refusal in
either pin order before compilation, capture publication, promotion leases,
lifecycle hooks, or providers. This is a non-surface compatibility constraint,
not an operator composition override; the remedy is to pin only one adapter.
Canonical `active_plugins` is checked during repository authorization as well:
omitting the competing adapter pin cannot authorize its active plugin. Include
that one-pin case in the refusal evidence. The guard reads desired state, so a
compatible repository can still deactivate a conflicting target plugin.
Use a participant-declared integration scenario to prove both orders against
the exact supported plugin artifacts.

An adapter needing no executable semantics declares neither key and stays purely
declarative. Most should. For worked examples,
[`adapter-packages/woocommerce/package/manifest.json`](../../adapter-packages/woocommerce/package/manifest.json) pairs a
manifest-sourced provider with a triggered, effect-declaring native
`transient.delete`, and the
[`adapter-packages/wprism-agency-cpt/package/manifest.json`](../../adapter-packages/wprism-agency-cpt/package/manifest.json) fixture
shows a plugin-advertised one.

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
[the limitation ledger](adapter-authoring-limitations.md).

## The authoring loop

### The one-prompt coding-agent contract

A normal user prompt can be as short as: “Author and production-harden an
adapter for `<plugin>` from the exact installed release.” The coding agent
should complete the loop without asking the user to classify individual keys.
It owns the reversible evidence work: inspect the official artifact, complete
and verify the plugin's public onboarding lifecycle, exercise every reachable
native writer, run observation/coverage/probe/draft, ratify the smallest
defensible manifest, add adversarial offline and live tests, and run the
repository gates. It records commands, visible outputs, corrections and
assumptions; it does not record private chain-of-thought.

Verify every plugin-owned writer triggered by the visible Save action. A
successful core REST post update or `core/editor.savePost()` does not prove
that a plugin's editor state was flushed. Qi Blocks 1.5.2 attaches a debounced
style save to the editor Save-button click: the core save API persisted all
block comments while leaving `qi_blocks_global_styles` empty. Clicking the
actual Save button persisted the styles and changed frontend rendering. Trace
such event handlers, wait for their responses, and retain raw owned-row
readback plus frontend behavior. If a native request fails while a sibling
request succeeds, record both outcomes; do not invent the missing state or
claim the whole settings form saved successfully.

Continue autonomously when a command already supplies a typed recovery. In
particular, narrow an over-broad or under-broad `--match`, use `--force` after
the create-only draft output names that remedy, seed representative rows when
a probe reports an empty keyspace, and keep an unsupported surface
outside the disposition until its missing primitive exists. Use runtime/env
only when the native storage semantics justify that classification. Escalate only when progress needs new
authority or information the repository and target cannot supply: a paid
artifact or credential, permission to exercise an external server/CDN/service,
destructive deletion authority, a production-only mutation, or two plausible
product meanings whose choice changes what WPrism will overwrite. “I have not
read enough plugin code yet” is not an escalation reason.

**The target needs Git before step 1 runs.** `wp wprism init` refuses
`unsupported: repository git — Git is unavailable on the target that owns the
site repository` on a stock `wordpress:cli` image; that image has no Git
installed, and nothing below tells you so until init already refused. Install
Git on the target first (`sandbox/tests/lib/grind_lib.sh:744`'s
`init-cli.Dockerfile` build is a working reference for what the image needs).

**These six steps are not the whole command sequence** — two verbs run
*inside* the loop without a numbered step of their own. `wp wprism adapter-probe`
answers the live schema questions a draft's `questions` field names (see
[Answering the draft's live questions](#answering-the-drafts-live-questions)
below); it runs between Draft (§3/§4b) and Pin (§5), because a probe reads
what a drafted table looks like on the live target. `wprism adapter boundary`
bisects the `version_range` these steps write at Export/§4b (see [Finding the
two versions the range names](#finding-the-two-versions-the-range-names)
above); it has to run before that range is ratified, not after. Before
authoring from nothing, it is also worth asking whether an adapter for this
plugin already exists somewhere you can install from: `wprism adapter discover`
reads a published index and tells you, without writing anything (see
[Planned: what an adapter cannot express yet](#planned-what-an-adapter-cannot-express-yet)
for `discover`/`install`/`update`). Running this loop across many adapters
at once — ranking which plugin to write next, re-measuring whether it moved
the fleet's coverage — is [coverage-cohort.md](coverage-cohort.md)'s job, not
this page's; its 9-step loop is where probe, boundary, the kit and `wprism
census` all sit inside one ordered sequence.

**A sequencing trap between init and Propose.** If you already ran `wprism init
--allow-unmanaged-plugins` (the S1 unmanaged-plugin posture — see [the caveat
that catches everyone](#the-caveat-that-catches-everyone) above) before
starting this loop, it already wrote
`policy.scope.<kind>.<name> = {"class": "runtime"}` into `site.wprism.json` for
every unmanaged plugin's rowful post types and taxonomies. By the time you
reach §2 below, `wprism pending` shows **nothing** for those types: a surface
with a recorded scope decision is no longer "no scope disposition", which is
exactly the condition `wprism pending` looks for. This is not a bug to work
around — it is init doing its job — but it means the review queue will not
hand you the CPT or taxonomy you are writing the adapter *for*. Read `wprism init
--format=json`'s `unmanaged_scope_left_local` advisories (or the host
renderer's `UNMANAGED SCOPE …` lines) for what init already decided instead.

The same onboarding boundary applies to stored widget families. A populated
family with no selected `widgets{}` grammar and no active sidebar assignment
receives an exact `policy.options.widget_<type> = {"class":"runtime"}` rule,
shown as `UNMANAGED WIDGET` before confirmation. Marker-only families get no
decision. Native settings and inactive assignments remain local; init does not
invent widget identities, remove instances, or infer references from their
values. A selected non-local classification without widget grammar still
blocks instead of being downgraded. Once the adapter declares the widget type,
the structural widget grammar owns portable instances. Repeat that scope review
after native settings writers: WordPress's Customizer can turn an empty legacy
widget option into a numbered, unassigned instance. A conformance seed that
creates its site configuration directly must prove the inactive/unassigned
premise and record the same exact local decision through `wprism classify`.
Retain native before/after bytes; do not put another component's widget scope in
the plugin manifest or erase the instance to bypass capture.

An **active** assignment is different: its complete sidebar is an authored
layout, and excluding one widget option cannot make a partial layout safe to
publish. Init therefore reports `undeclared_active_widget` before confirmation,
even with `--allow-unmanaged-plugins`. Install the reviewed widget declaration,
or deliberately remove its active assignments in WordPress before requesting a
fresh proposal. Do not remove native widgets merely to turn an evidence run
green; when testing this boundary, retain the refused layout and prove both
readiness and Capture preserve it. WPForms reconnaissance exposed the previous
false-ready proposal; `regress-init-widgets` exercises the actual planner,
bounded native reader and unchanged Capture guards.

A completed baseline is not yet a clean environment. Host `init` can report
both canonical and separate code baseline commits, then exit 1 because required
`admin_email`, `home` or `siteurl` bindings have not been provisioned. Preserve
those baselines; inspect the complete plan, provision the driver's deliberately
chosen values through public `env-set --stdin`, and require a fresh host
`status` to be clean before proceeding. Do not rerun initialization, infer
intent from whatever values happen to be installed, or accept the nonzero
handoff as a successful status check. Disposable conformance uses
`establish_core_environment_bindings` as described below. WPForms reconnaissance
exercised this exact baseline → explicit provisioning → clean-status sequence;
it is distinct from a failed baseline publication or uncertain recovery.

For a failed **fresh** initialization, `.wprism/refusals/` is intentionally
absent: creating that directory would violate first-init compensation. Preserve
any sealed `.wprism-init-attempt` evidence and use a reviewed human-mode target
rerun to inspect the diagnostic; the host's redacted envelope is not the cause.
An interrupted or uncertain publication is a recovery decision, not permission
to retry blindly. See [the CLI refusal contract](../../cli/README.md).

### 1. Observe

Exercise the plugin on a real environment — create the entities through the
plugin's *own* admin code path, not by hand-writing postmeta, because the
whole point is to learn what the plugin actually writes. The provenance journal
records those writes; `wp wprism journal-report --manifests=<names>` aggregates
them and scores proposals against the manifests you already have.
`wp wprism journal-reset` truncates the journal when you want a clean observation
window for one specific interaction. It warns with the number of rows it is
about to destroy first: options no adapter declares are recorded nowhere else,
so those rows leave `wprism pending` permanently. It is not a prerequisite for
`wprism init` — observations are not ledger identity, and a site journalling from
first boot initializes with them intact.

Note what the journal will *not* do: a bare authenticated write never proposes
`authored`. Proposals come from evidence and are deliberately conservative.

Journal rows are provenance, not live schema — they tell you what the plugin
*wrote*, not what its tables *look like*. For the latter, `wp wprism adapter-probe`
(below) is the Observe-phase counterpart once you know which tables to ask
about: it needs no `--repo` and reads the target's live `SHOW COLUMNS`/`SHOW
INDEX` directly, so it is usable this early even though its answers are most
useful once a draft's `questions` name exactly what to probe.

### 2. Propose

```sh
wprism pending dev
```

The review queue shows unclassified meta on in-scope entities, entity types
with live rows and no scope disposition, and journal-observed unclassified
options. Each item carries whatever evidence exists — entity counts, journal
surfaces, a ref-hint, a secret flag — and **never a guessed classification**.
An item with no evidence for a proposal prints `-`. (If a type you expected
here is missing, re-read the sequencing trap above — it may already be
decided.)

### 3. Draft

```sh
wprism classify dev
```

Interactive triage reads decisions from stdin, so it is pipe-testable. Under
the hood every decision batches into a single agent call, and that call has two
wp-cli parsing traps that were confirmed empirically rather than assumed:

- **Use the `=` form.** `--set=post_meta:foo=runtime` works;
  `--set post_meta:foo=runtime` does not — wp-cli parses the space form as a
  bare boolean flag and the value lands in positional arguments, silently.
- **Repeating the flag does not accumulate.** `--set=a --set=b` keeps only
  `b`. Pass multiple rules as one semicolon-joined value:
  `--set='post_meta:foo=runtime;options:bar=authored,ref=post'`.
- **Clearance is per row.** Put `allow_secret=true` or `allow_pii=true` inside
  only the reviewed row of a joined value. The legacy `--allow-secret` and
  `--allow-pii` flags remain single-row shorthand and refuse a multi-row set.

Both apply whenever you drive `wp wprism classify` directly. `wprism classify` builds
the joined value for you.

### 4. Export the site-local rules into a manifest

```sh
wp wprism policy-to-manifest --repo=<path> --match='^wpcf7_' --name=contact-form-7
```

`--match` is a PCRE body without delimiters, tested against each key; `--name`
becomes the manifest's `name` field. This exports **only site-local policy
rules** — the ones you just classified into `site.wprism.json`. It is the
promotion path from "one site decided this" to "the library declares this",
and it is deliberately one-directional: nothing reads a manifest back into site
policy.

Move the emitted JSON into `adapter-packages/<name>/package/manifest.json`, add `plugin`,
`version_range`, and the evidence notes by hand, and drop the now-redundant
site-local rules from `site.wprism.json`. Run
`php tools/adapter-package-validate.php --adapter=<name>` and
`php cli/wprism manifest-validate . --manifest=<name> --pins=core,<name>` on the result before going
further — see [Checking the grammar offline](#checking-the-grammar-offline);
the hand-added parts are exactly the ones no export path checked.

### 4b. Or start from a draft, when there are no site-local rules yet

`policy-to-manifest` promotes rules you have *already classified*. For a plugin
nothing knows about yet, there are none — so `wprism adapter-draft` proposes them
instead, from the repository's captured `state/**` plus, with `--seed`, from
what `wprism coverage` saw on the live site:

```sh
wprism coverage prod --format=json > coverage.json
mkdir -p <site-repo>/adapters
wprism adapter-draft <site-repo> --name=wpforms \
  --match='^_?wpforms_' \
  --seed=coverage.json --out=<site-repo>/adapters/wpforms.json
```

`adapter-draft` will not create the output directory: create `adapters/`
before the first `--out` run. Scope a coverage seed with `--match` whenever
you are authoring one plugin. Coverage intentionally reports every invisible
option family and undeclared table on the site; without that filter, unrelated
WordPress and plugin surfaces are valid candidates and can bury the adapter's
own review questions in a very large draft. A later scoped `--force` drops
unchanged, unratified machine proposals that no longer match while preserving
ratified or edited candidates.

The filter is applied to the names the input document actually contains. For
an undeclared table that is the unprefixed logical table name. For invisible
options, coverage intentionally publishes an ownership **prefix** such as
`redirection`, not every option name beneath it. Therefore an exact-looking
filter such as `^(redirection_options|redirection_(groups|items))$` can select
the tables and honestly select no option proposal. Inspect `_draft.seed` and
the candidate counts; if the plugin option family is missing, rerun against
the reported prefix (for example `^redirection`) with `--force`. A pre-existing
output refuses with `draft_output_exists` instead of guessing whether to
overwrite human edits; that named refusal is sufficient authority for the
coding agent to retry with `--force`, because regeneration preserves edited or
ratified candidates.

Why `--seed` earns its place: the offline proposers read `state/**`, so they
can only see surfaces WPrism **already captures** — and the surfaces you are
writing an adapter *for* are exactly the ones it does not. An option prefix
invisible to every installed adapter, and a live table no manifest declares,
are invisible to the draft generator and plainly visible to `wprism coverage`.
`--seed` turns each into a candidate with coverage's own observation quoted:

| coverage finding | proposed as |
|---|---|
| `options.invisible_groups[].prefix` | an `option_namespaces` match **and** an `option_patterns` rule |
| `tables.undeclared[]` | a `tables.<logical_name>` declaration |
| a scope-gate-refused post type | a `post_types.<name>` declaration |

The third one needs the richer seed: `wprism coverage` reports options and tables
and nothing else, so pass a `wp wprism assess-inventory --format=json` document
instead when you want post types too. The draft records which families its seed
actually supplied in `_draft.seed`, so a short draft is never a silent one.

`options.invisible_groups[].prefix` means **genuinely undeclared** — no rule
from any source, exact or namespace or pattern or dynamic prefix. A family your
adapter (or any pinned adapter) already declares by name is no longer seeded,
so `--seed` stops proposing an `option_namespaces` claim plus a blanket
`runtime` `option_patterns` rule over options half of which are already
`authored`. What it proposes is what nothing models yet, which is the only
thing a new adapter is for.

**Every seeded candidate is `runtime`, and that is a default, not an
observation.** An undeclared table is one WPrism has never read a row of; calling
it `authored` on that evidence would put live operational rows into your
repository. What `runtime` buys is honest: the surface becomes *declared and
excluded* instead of reading `unclassified / block` in assess. Promote the
parts that really are authored configuration by hand — and then their columns,
primary key and identity are live facts an offline draft cannot supply, which
is what each candidate's named `questions` say — and what `wp wprism
adapter-probe` answers, below.

#### Answering the draft's live questions

The questions a candidate carries are **named** — `[table_schema]`,
`[natural_key_uniqueness]`, `[lock_index]`, `[foreign_keys]`, `[eav_twin]` —
because one command can answer them. `wp wprism adapter-probe` runs on the target
and reports, per table, the real PRIMARY KEY, every column's MySQL type and
nullability, unique keys, per-column index coverage in the deletion guard's own
terms, declared foreign keys, an EAV twin, and natural-key uniqueness as one
`COUNT(*)` vs `COUNT(DISTINCT …)`:

```sh
wp wprism adapter-probe --tables=wpforms_tasks_meta,wpforms_payments \
  --natural-keys=wpforms_payments.transaction_id --format=json > probe.json
wprism adapter-draft <site-repo> --name=wpforms --evidence=probe.json \
  --out=<site-repo>/adapters/wpforms.json --force
```

`adapter-probe --format=json` emits one formatted JSON document. In a live
shell harness, use the shared `capture_wprism_json_document` helper: it keeps
stdout separate from transport stderr, accepts compact or formatted objects
and arrays, and refuses runtime diagnostics, extra stdout or a nonzero exit
before publishing the value. The compact-envelope helper is for commands that
emit their answer on one line after action receipts. AIO Login authoring reached
the probe successfully but its former last-line reader rejected the closing
brace; flattening or discarding that output would have hidden the actual schema.

Note what that example gives you on a **freshly activated** plugin, because it
is the state you are most likely to run it in. A plugin creates its tables at
activation and fills them only through use, so every one of the six
`wp_wpforms_*` tables exists and holds **zero rows** — and a natural key over an
empty keyspace measures nothing. `natural_key` comes back
`{"rows": 0, "distinct": 0, "unique": false}`, and that `false` is the absence
of an answer rather than a duplicate. The human summary says so in as many
words (`natural key wpforms_payments.transaction_id: 0 row(s) — nothing to
measure yet`); exercise the plugin until the table holds real rows before you
ratify anything from that line. `--tables=` never takes the site's table
prefix, and a table reported `absent on this target` is a name, prefix or
not-yet-activated question — never a "no rows yet" one. This verb also takes no
`--repo`: unlike `wp wprism coverage`, `pending`, `lint` and `adapter-observe`, it
reads the target's live schema and owns no repository.

Each fact lands as an `evidence[]` row at confidence 1.0 naming the question it
closes, and **nothing else moves**. If the live PRIMARY KEY is not the column
the offline proposer guessed, the guess still stands in the fragment and the
disagreement is stated beside it — the probe declares `authority: false`, and
`adapter-draft` refuses any document carrying a word outside the closed probe
vocabulary, so it cannot classify anything on your behalf. Read the rows, then
ratify by hand. The document never carries a row value: enum/set member lists
are reduced to their base type word for the same reason.

The probe is intentionally rowless, so it cannot answer a different question:
how values inside a text/blob column are framed. `SHOW COLUMNS` may say
`mediumtext` while native writers store a plain string for one action, a PHP
serialized map for another, and SQL `NULL` for a third. Exercise every native
writer variant in the admitted release and compare the raw stored bytes with
the plugin API's readback. Declare strict `php_serialized` only when every
authored value is one canonical serialized container. When one column is the
measured plain/serialized/NULL union, declare
`{"container":"php_serialized_or_text","leaves":"text"}` and the explicit
`mixed-column-codecs/v1` feature; malformed serialized-looking bytes and
non-string/non-NULL values must refuse. Redirection 5.9.0 is the reference
manifest and `regress_column_codec_grammar.php` is the mutation proof. Never
infer a codec from the first populated row or from the SQL type.

For a stored JSON container, declare `{"container":"json","leaves":"text"}`
and `json-column-codecs/v1` alongside `typed-column-codecs/v1`. Compare native
Save bytes with a decode/default-encode round trip first: this strict codec
refuses any shape or spelling the shared associative decoder cannot preserve,
including empty objects, duplicate keys, numeric-key objects that become lists,
and alternate whitespace or escaping. It admits empty lists. Capture, compiler
and Apply must agree on framing; decoded keys also participate in clearance.
A container codec grants no clearance exception. Any existing exact reviewed
`allow_pii` or `allow_secret` rule still needs independent semantic justification.

This framing only reaches ordinary text leaves. A JSON field holding a native
user ID still needs an explicit reference contract; a CSV URL still needs a
verified target-local input. The user/customer importer 2.7.5 native template
Saves demonstrate both demands, so JSON framing alone does not qualify its
templates. Run `make regress-json-column-codecs-native` with exact
`WPRISM_EXPECTED_SOURCE_SHA`; the harness owns its disposable pair and checks
native Capture, Apply, repeat, recapture, rollback and foreign-row preservation.

Use `typed-column-values/v1` for semantic values inside a strict JSON or PHP
serialized column. Declare `{container, value}` instead of `{container, leaves}`;
`value` reuses authored `object_fields`, `enum`, scalar/list `ref`, structured
`json_refs`/`key_refs`, and `plain_data`. Every reference leaf must declare
`on_unmapped: "refuse"`. Prove the native scalar type and use `cast: "string"`
when the plugin stores ID strings. Record projection requires its separate
column feature below; encoded text and mixed scalar framing remain unsupported.

For example, importer 2.7.5 stores selected users under
`filter_form_data.wt_iew_email` as string IDs. Declare exact enclosing objects
and a `ref: "user[]"` leaf, then prove different source/target IDs with the same
logins and a missing target login. Capture and Apply use the existing user
binding codec; this never grants user migration. The compiler checks canonical
reference shape without resolving environment bindings. Unknown object members
refuse, so adding a native field requires a reviewed declaration change.

Validated scalar/list reference leaves are excluded only from the PII subject;
secret clearance still inspects every original decoded key and value. All
remaining fields and ancestor key roles retain ordinary PII checks. Do not
classify contact data as a reference to obtain clearance: native values must be
strict IDs and canonical values must be tokens in that declared keyspace.
Run `make regress-column-value-contracts-native` with the exact source SHA;
it proves native typed rows and shared Gutenberg values at divergent user IDs.
A template's local CSV input still needs a separate target-binding contract.

Field definitions need their own semantic evidence. Importer 2.7.5 Saves
`user_email: ["user_email", 1]` in its field map and
`user_email: "user_email"` in its selected-column labels. These are labels,
but ordinary PII key-role scanning interprets them as contact values. With
`column-field-labels/v1`, declare the exact metadata leaf as
`{"class": "authored", "field_labels": "label_enabled"}` or use `"label"`
for a field-to-string map. Compose these leaves under `object_fields`; do not
repeat a rule for every plugin field or grant whole-column privacy clearance.

Prove that native producers and consumers use every map entry as a field code
and label, including custom fields. This declaration supplies that semantic
authority: shape validation alone cannot distinguish a person's name from a
display label. It admits only nonempty string keys, string labels and, for
`label_enabled`, a two-element tuple with integer 0 or 1. Empty maps are allowed;
unknown formats, extra tuple entries and coerced flags refuse. All stored key
and value bytes still undergo value-based privacy checks; enclosing roles and
full original secret checks remain. Options, metadata and block values cannot
borrow this column feature. Run the column-value offline suite and its native
lane; template enrollment and input-file availability need separate evidence.

Use `column-record-fields/v1` when a stored record mixes authored fields with
proven regenerable context. Add `record_fields` to the existing value leaf:

```json
{"class":"authored","plain_data":true,"record_fields":{
  "container":"object","fields":["method_export","mapping_enabled_fields"]
}}
```

Importer 2.7.5 Save As retains the previous template's `selected_template` cursor.
Normal reopen selects the requested row; the wizard rebuilds the cursor on its
next step. Native Save/reopen and identical CSV output after projection establish
that it is regenerable context. Prove this with the plugin consumer before
excluding any field. Projection does not merge target-local preimages or grant
file transport, and this declaration alone does not qualify saved templates.

The existing `RecordFields` machinery handles one object or a list of records,
including beneath `object_fields`. It retains immediate field order, absence,
types and list duplicates. Every record must retain at least one declared field;
an empty list is valid. Canonical excluded fields refuse. Structured reference
paths must start with a retained exact field; scalar references cannot compose
with projection. Column clearance still scans the original decoded data,
including excluded fields. Run the column-value offline suite and native lane.

When retained members need different codecs, compose `record_fields` with
`object_fields` using `object-record-fields/v1` and the existing surface value
and record features (`typed-column-values/v1` + `column-record-fields/v1`, or
`block-value-contracts/v1` + `block-record-fields/v1`). For example:

```json
{"class":"authored",
 "record_fields":{"container":"object","fields":["mode","input"]},
 "object_fields":{
   "mode":{"class":"authored","enum":["local"]},
   "input":{"class":"authored","input_file":{"directory":"imports","extensions":["csv"]}}
 }}
```

The input leaf additionally requires `column-input-files/v1`; it does not gain
block authority from this composition. Retained names must equal the typed
field set. Lists cannot compose with exact object fields. This keeps exclusion
and typing explicit while reusing the existing projection and child codecs.
Native extra fields still undergo whole-value JSON bounds and privacy checks;
canonical extra fields refuse before any target binding. Missing children stay
absent. A functional input field must be rebound, not dropped together with a
temporary wizard cursor. Run `regress-object-record-fields` and the relevant
native consumer lane before enrolling the adapter's complete saved form.

When one physical column has different meanings in different owned row types,
use `column-value-cases/v1`. Keep one strict container and replace `value` with
`value_cases: {column, cases}`. The selector names a declared `row_scope` set;
each `{equals, value}` case supplies its existing authored contract. Enumerate
every set member exactly once in byte order. Case lists preserve numeric string
values through JSON loading. There is no default, and every arm receives full
shape, feature, keyspace and shared-budget validation.

For importer 2.7.5, export field maps contain labels while import maps contain
expressions and literal values. They share `data.mapping_form_data` but cannot
share a field-label privacy declaration. First prove the native row selector;
then give each variant a truthful contract. This mechanism selects contracts;
import-expression semantics and target-local CSV availability still need their
own support. Test wrong-variant payloads, attempts to borrow a privacy role,
unknown selectors, both framing types, and owned variant transitions through
Capture, immutable compilation, lint, checked Apply and recapture. The shared
column-value native lane exercises selection on real typed-table writes.

Use `column-field-templates/v1` for a proven brace-expression map. Declare
`{"class":"authored","field_templates":"brace"}` for selected strings or
`"brace_enabled"` for exact `[expression, integer 0 or 1]` definitions. This
avoids repeating rules for every native field while keeping header references
separate from literal destination values. It composes beneath `object_fields`
and the appropriate import `value_cases` arm; export labels retain their own
contract. Do not classify import expressions as field labels.

When the native consumer requires a functional member, also negotiate
`column-field-template-requirements/v1` and use the compact object form. A
`brace` map declares `{"format":"brace","required_nonempty":["field"]}`;
a `brace_enabled` map declares
`{"format":"brace_enabled","required_enabled":["field"]}`. The lists are
bounded, sorted and exact. Capture, compilation and Apply all refuse a missing
or empty required expression; `required_enabled` also refuses a native flag
other than integer `1`. This asserts only the member contract established by
the native producer and consumer. It does not add a default or synthesize an
expression. Importer 2.7.5 uses it for `user_pass`: the plugin otherwise saves
a disabled mapping and later warns twice while merging an existing user.

Prove the producer's persisted tuple shape and consumer's exact brace dialect.
Importer 2.7.5 Save sanitizes definitions to two slots; its consumer substitutes
nonempty brace bodies, trims header names and interprets date/arithmetic syntax.
The engine preserves those bytes and leaves interpretation to the plugin.
Canonical text/field fragments disambiguate native braces from WPrism tokens.
Only literals receive URL transport; a URL-shaped CSV header stays unchanged.
Every stored byte still receives value-based clearance, and joined literals keep
the destination's PII and credential roles, including disabled definitions.

Test empty, unmatched and nested braces, adjacent references, whitespace and date
annotations, source/target URLs, disabled definitions, literal private values,
malformed canonical edits, graph references and whole-value bounds. A numeric
post-query prefix followed by a field, such as `?p=4{Suffix}`, refuses: the
native result could name post 41, so post 4 cannot be bound independently. The
same refusal covers intervening literal bytes such as `?p=4%{Suffix}`: a field
value of `31` completes an encoded digit.
Complete delimiter-terminated static query values retain ordinary rebinding. Run
`regress-column-field-templates` plus the capsule's native expression transport
lane, which calls the actual registered consumer after real typed-table writes.
That bounded evidence does not exercise an import job or provide its input file.
A remembered CSV path is functional state: do not project it away to make an
otherwise unsupported import template fit the declaration.

Use `column-input-files/v1` for a functional file pointer that each environment
must provide independently. Declare the exact nested field with
`{"class":"authored","input_file":{"directory":"webtoffee_import","extensions":["csv"]}}`
inside its typed column value contract. Capture records only dependency presence;
an empty draft remains empty. Plan derives the binding name from the canonical
row and field, and the operator provisions one filename in the declared content
directory through `env-set`. Apply binds that target file through the ordinary
typed writer. This feature supports ordinary mapped and natural-key rows, not
composite identities or a whole-column input leaf.

Prove the native producer's URL spelling and the actual consumer's allowed
extensions; an upload dialog's list can be broader than the import consumer's.
Do not treat remembered input as regenerable noise, transport customer CSV bytes,
or add a plugin executable to choose paths. Test missing and replaced files,
unsafe paths, empty drafts, canonical edits, rebinding after intent changes,
drift, scope, repeat Apply and rollback. Run `regress-column-input-files` and
`regress-column-input-requests`, plus the capsule's native input lane. The private
intent lock protects Apply from cooperating provisioning; point-in-time file
availability does not promise a later import job will still find identical bytes.

For scoped evidence, rotate the binding while canonical content stays identical,
then assert the complete native pointer and consume the replacement file. Scoped
authority seals hash-only native intent and preimage witnesses; locked authored
readback, recovery, and terminal replay must agree with them. Exercise unavailable
inputs and changed intent against a completed request, and prove that refusal
preserves its receipt and native rows. Canonical recapture by itself cannot detect
an old pointer because both filenames become the same dependency marker.

For a natural key, uniqueness is a **source and hostile-target invariant**, not
one source-side probe result. Populate the candidate key, probe it, then create
an independent target row with the same key. A fresh target derives the same
UUID; this exercises first binding, including equal-content rows, rather than
an identity collision. To prove explicit adoption, capture the source under an
old key, rename it through the native API, and recapture. Assert the source ID
and durable UUID remain unchanged while the current key would derive another
UUID. Then create that current key on an unmapped target: Plan must report the
collision without persisting its derived identity, unapproved Apply must refuse,
and explicit table adoption must retain the target ID and reach convergence.
Never clear or rebind a durable map to manufacture this premise. Also create
multiple matching local rows and prove refusal; capture, plan and apply reject
two rows with the same full canonical identity tuple. Do this even when the
table has a primary key: the primary key is local
storage identity, not portable identity. An identity column also cannot carry a
column codec — rewriting it during capture/materialization would make portable
UUID derivation and target lookup disagree.

Everything under `_draft` is inert: `Policy::load()` never applies a proposal,
and the trigger keys are renamed so no validator mis-collects one. Ratify by
hand, delete the rest, then `wprism adapter inspect <name> --repo=<site-repo>`.
Deleting it is not tidiness. `_draft` is in no arm of the signer's top-level key
partition, so a manifest still carrying it cannot be certified at any spec
version, and at `spec_version: 3` it is refused at load by name with "strip the
`_draft` key before install" (spec/repo-format.md § v3.3). Nothing is lost by
removing it — `wprism manifest-validate` reports the sidecar's facts, proposals and
unsupported counts on every run.
`--out` refuses to overwrite an existing draft without `--force`, because that
file holds your ratifications; re-running with `--force` is safe, since human
edits in the prior draft are carried forward.

### 5. Certify and pin the exact bytes

```sh
# Shipped adapter:
wp wprism manifest-pin --name=contact-form-7

# Site adapter, after keygen (sign, verify and pin atomically):
wprism adapter certify <site-repo> --name=<name> \
  --secret-key-file=<organization-key> \
  --reason='<what was reviewed>' --pin
wprism adapter inspect <name> --repo=<site-repo>
```

This prints the exact canonical `{"digest": …, "name": …, "source": …}` object
to paste into `site.wprism.json`'s `manifests` array (keys are canonical, so they
print in alphabetical order). `source` is always present and always the source
the adapter actually resolved from — `shipped` here, `site` for one installed
at `adapters/<name>.json`, `plugin` for one a plugin bundles — because a pin
that named only a name and a digest could not say WHICH definition it
reviewed, and a pin that names a source refuses loudly the day a different
source starts answering to that name. The digest is content-addressed against
the same per-manifest digest compiled artifacts record in `resolved_adapters`,
including a declared interpreter's name and bytes, and load refuses a mismatch
before any policy consumer or target contact.

Without `--repo`, `manifest-pin` deliberately does **not** load
`site.wprism.json`; that is the shipped-library path. With `--repo=<site>`, it
loads the repository's policy and site adapter source but overrides only the
selected manifest pin, so a stale digest for that adapter cannot prevent you
from computing its reviewed replacement. Unrelated repository errors still
refuse. Updating a pin is an explicit review act; it is never automatic.

For a site adapter, prefer `certify … --pin` over copying a separately emitted
digest: it signs the canonical adapter, verifies the certificate it wrote and
updates the exact site-source pin in one operation. The final `inspect` must
report the site-rooted certificate as pinned/certified. If any adapter byte is
edited afterwards, rerun the same command; retaining an old signature or
relaxing the digest would turn approval of one program into approval of a
different one.

### 6. Exercise it

A capsule-owned live driver starts with these exact first three active shell
statements, before changing directory, constructing flags, or acquiring a pair:

```bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
```

`regress-fetch-artifact` checks this preamble across discovered capsule callers.
The early physical package scope keeps later shell branches and child commands
from selecting the aggregate artifact library. A late literal package slug can
fetch the right artifact in one run while violating that caller contract.

Independent live lanes require a fresh **whole pair**, not just fresh database
rows. `pair.sh reset` deliberately clears databases and repository contents
while retaining webroot volumes. Reusing those volumes after deleting native
attachment metadata leaves generated files without ownership; the engine must
refuse to overwrite them. Between independent lanes, use the existing
`pair_live_ownership_finish_leg()`, then `pair_live_ownership_acquire()` and
`pair_live_ownership_up()`. This removes owned resources and releases the lease
before re-proving the complete namespace, while retaining private host-registry
scratch. Capsule drivers can source `tests/lib/pair_live_ownership.sh` from
`sandbox/`; it is an explicitly reviewed shared test dependency. The package
validator still requires the exact ordinary `.sh` file and refuses unreviewed
neighbors. Normalize a driver's own invocation path before changing directory
when sourcing capsule-relative hooks through `BASH_SOURCE`. The four-plugin scenario's `regress_pair_lane_isolation.php` executes
its actual dispatcher and shared ownership helper: DB-only reset reproduces
the lost-ownership mechanism, and body, creation, acquisition and teardown
faults cannot start a later lane or publish PASS. A reset is still appropriate
when retained native files are an intentional premise of the same test.

Re-run the loop on a clean environment: capture, apply to a second environment,
recapture, and compare every managed entity through the engine's declared
semantic hash basis. Retain both raw captures: byte equality is a useful
stronger check where it applies, but is not the universal contract. As
`spec/repo-format.md` §Adapter manifests states, Capture includes
observed derived fields while update preserves the target's plugin-owned
values; `Canon::post_hash_basis()` excludes only manifest-declared derived
fields. Do not copy a fixture-specific list of ignored fields or discard
unexpected files. Any target-only entities need an exact native preimage and
preservation proof, separately from managed-entity convergence. `wp wprism lint`
is the companion check — it flags id-shaped values at undeclared paths, which is
exactly the shape a missing `ref`/`json_refs` declaration takes. Its findings
are plan-time signals, not proof of corruption; each carries its own caveat
note, because small ids legitimately coincide with counts, versions, and
ordering indexes.

For host-side evidence, compile each complete staged capture with
`RepositoryCompiler::compile_staged()` and its own real repository/media root,
then use `sandbox/tests/lib/RepositoryConvergence.php`. It compares semantic
entity identities plus policy, code, effects, deletions and the complete media
catalog. An explicitly named target-only signature is admissible only after
the scenario proves its native preimage survived Apply and that Capture minted
the exact corresponding UUID. Keep before-Apply, before-Capture and
after-Capture observations separate: a declared provider may rebuild a derived
marker row, while Capture may add identity metadata; neither permits omitting
other metadata rows or accepting changes to authored fields. The four-plugin
scenario also checks each extra canonical entity against its complete preserved
authored preimage, including detached translation-group descriptions and
memberships. Retained raw bytes remain diagnostic evidence; they are not a
substitute for full compiler validation, nor permission to delete target content.

**A finding on state your out-of-tree adapter declared blocks capture.** For a
shipped or certified adapter every finding stays the advisory warning it always
was; for an adapter installed out-of-tree that nothing has certified — including
one whose certification was withdrawn, and one whose valid signature the
repository has not yet pinned — `wprism capture` refuses with
`uncertified_adapter_lint_findings` and names each locator and the adapter that
declared it. Two ways forward, and no third: declare the reference so capture
tokenizes it, or write the reviewed `lint_ok: true` on that declaration. A
`proposed_lint_ok` finding (the type-derived proposal `wp wprism lint
--evidence=<probe.json>` emits) does **not** clear the gate on its own — it is
the evidence for the review, and `lint_ok` is the review. Certifying the adapter
returns its findings to advisory.

Test reviewed non-reference fields against actual colliding small IDs. The
WPForms native writer stores `modern-markup` as string `"0"`/`"1"`; a fresh
form with post ID 1 exposed the missing review in its named option subkey.
Review the owning flag/enum declaration from the pinned native writer and
consumer, then use the existing per-subkey `lint_ok`, not a blanket parent
exception or a linter change that hides every small integer. The capsule's
`regress_settings_lint.php` drives real option capture and lint with boolean,
integer and string flag representations, an unreviewed numeric sibling, and
secret/PII refusal controls. A non-reference review grants no privacy exception.

An experimental adapter that deliberately excludes `apply` uses the narrower
`mode: "capture-plan"` conformance profile instead. It still boots a fresh
exact-artifact pair, authors state through the plugin's own APIs, runs capture,
lint, deterministic recapture, capability reporting, and the real structured
plan path, plus a convention-named `tests/conformance/capture-check.sh`.
It then stops before deploy/apply. This is evidence only for the operations the
disposition lists; it is not a partial round-trip and cannot justify adding
`apply`, `deploy`, or `promote` to that list.

Experimental capability reports deliberately exit 3 with `ready:false`, even
when capture is declared. The shared profile preserves the complete stream
and status, compares the report to independently projected shipped declarations
and their engine-derived trust tiers,
and admits only each experimental subject's `authored_state_not_certified`
blocker. Certified capture subjects instead require `ready:true` and exit 0.
Source/target/provider failures, PHP diagnostics and inconsistent answers are
not expected experimental outcomes. The real read-only plan must succeed as a
command while retaining its exact promotion blockers: experimental status and,
where promotion is undeclared, `operation_not_certified`. The engine's existing
claim projection owns the `apply + deploy` to `promote` rule. Do not discard
exit 3 through `|| true`, accept arbitrary refusals, use `--all` to lose the
live target, or treat a returned plan as production authorization.

Real worked examples, with the empirical grounding for each decision, are the
grind rounds themselves: `make grind-r1a` (forms — Contact Form 7 + Ninja
Forms) and `make grind-r1c` (the agency stack — Elementor + ACF + a
dogfooded CPT plugin). Each script's header states the fixture and the
finding behind every assertion, and the manifests those rounds produced
(`adapter-packages/contact-form-7/package/manifest.json`,
`adapter-packages/ninja-forms/package/manifest.json`, and
`adapter-packages/elementor/package/manifest.json`) carry the reasoning in
their own note strings.

### Shared tables require row ownership

Inspect native writers and readers before declaring a whole table authored.
The user/customer importer 2.7.5 saves import and export templates in
`wt_iew_mapping_template` with `item_type=user`; other WebToffee modules use
that physical table for other item types. Its native saved-template query
partitions by item type and template type. A table name alone does not grant
ownership of every row.

Use `table-row-scopes/v1` and an explicit `tables.<table>.row_scope`, such as
`{"item_type":"user"}`, for a supported ordinary row table. The scope is an
AND of byte-exact text discriminators. Discriminators must be authored text
columns and participate in a natural key when one is declared. The current
feature refuses structural refs on that table and attached-meta sidecars;
see [the complete contract](../../spec/repo-format.md#v338-table-row-scopesv1--ownership-within-shared-physical-tables).
Do not replace these unsupported structures with an unbounded table claim.

For several explicitly owned variants, also declare `table-row-scope-sets/v1`
and use a sorted set such as `"template_type": ["export", "import"]` alongside
`"item_type": "user"`. Every discriminator still has to match. Sets admit
2–16 exact strings; a singleton keeps the scalar form. Do not remove a
secondary discriminator merely to capture another row type: that would claim
all its unknown variants too. Payload semantics and local input bindings need
their own declarations and evidence.

Prove foreign-row preservation through Capture, immutable compilation, Apply,
repeat Apply, deletion and rollback. Include case-only and trailing-space
owner collisions, retained ids whose owner changed, failed ownership reads,
and sidecar export/recovery. A native ownership change must refuse before it
can become a tombstone or erase the ledger evidence. Keep exact native payload
observations separate from adapter qualification: a template Save/reopen alone
does not establish that its JSON references or local input files can migrate.

The shared native SQL probe checks byte-exact Capture, materialization,
rollback, deletion and a concurrent ownership change after a repeatable-read
snapshot. On a clean committed checkout, run it with an owned pair on each
database engine affected by a SQL change:

```sh
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) WPRISM_DB_ENGINE=mariadb \
  bash sandbox/tests/live/regress_table_row_scopes_native.sh
```

Use `WPRISM_DB_ENGINE=mysql` for the MySQL leg. The harness destroys its pair
after each leg; `ROW_SCOPE_PAIR`, `ROW_SCOPE_PORT1` and `ROW_SCOPE_PORT2` select
a distinct name and even/adjacent ports when sharing a host.

## Dispositions: the reviewed claim source

Each capsule's `package/disposition.json` (`wprism-manifest-dispositions/v1`) is
separate from `package/manifest.json` **so that declaration cannot imply
certification**. The document holds that adapter's entry verbatim;
`platform/adapter-library/profiles.json` holds the profiles map. It is
hand-authored and reviewed, and it is the *only* authored source of a product
capability claim: `ManifestDispositions::claim_from_disposition()` projects the
claim, `AdapterRegistry` evaluates that projection against a live target, and
`tools/capability-doc.php` renders it into
[docs/capabilities.md](../capabilities.md). There is no second, generated
document for it to agree with.

Coverage is an **exact one-for-one set**, in both directions, and it is proved
in two places for two different questions.

At runtime the question is about the adapters a repository actually **pins**:
every pinned shipped manifest must have a reviewed entry, or the load refuses,
naming the pin:

```
wprism: manifest disposition coverage mismatch; missing=[<pinned manifest with no entry>], extra=[]
```

At authoring time the question is about the **library**: `make release-gate`
(`capability-doc.php --check`) compares the shipped manifest set against the
reviewed set both ways and refuses either difference, `extra` included, before
it renders a line of prose.

```
manifest disposition coverage mismatch; missing=[<manifest with no entry>], extra=[<entry with no manifest>]
```

So shipping a capsule manifest without its sibling disposition does not produce
an unreviewed adapter. It produces a red `make release-gate`, and an agent that
refuses the moment anything pins that name — while every adapter beside it
keeps loading, which is the point of scoping the runtime half: one unreviewed
file in the library is not grounds for refusing an unrelated, reviewed pin.

### What each status means now

| Status | What it says |
|---|---|
| `certified` | Declared by the manifest, reviewed by a human who wrote the `reason` down, and exercised by the conformance suites the entry's `evidence.tests` name. It does **not** mean a bundle digest seals the claim to a run or an artifact set. |
| `experimental` | Reviewed, exercised only to the boundary its cited suite names, and deliberately not production-authorizing. A capture-plan suite may support capture/compile/plan/recapture while apply stays explicitly unsupported. The projection reads `Experimental`, which `wprism release` refuses on before it freezes anything — including through a conditional path. |
| `excluded` | Reviewed as carrying no product claim. The generated document prints these as shipping "for regression use only"; the claim reports `authored_state.status: unsupported`. `wprism-agency-cpt` is the one shipped example. |
| `uncovered` | **Runtime-synthesized only.** A disposition may never declare it — `validate_entry()` refuses that — and the agent emits it for a manifest with no reviewed entry, with the reason `no reviewed disposition entry — a manifest cannot certify itself merely by existing beside the agent`. It is a blocker, never a skip. |

An entry names supported versions, entity and field sections, operations,
lifecycle phases, deletion semantics, explicit unsupported behavior, every
table whose default keyspace is authored, and its `evidence` citation — the
suite names a reviewer wrote down. That citation is reported verbatim wherever
it surfaces; nothing re-derives a status from it, which is exactly why
`wprism adapter inspect` prints the cited test ids and no per-test verdict.

Three cross-checks bind an entry to the manifest it describes, refusing rather
than papering over: a disposition naming a plugin must agree with that
manifest's own `plugin`/`version_range` bytes; its declared sections must exist
in the manifest; and its supported deletion selectors must be exactly the
manifest's declared `deletions` keys. Otherwise the document would advertise an
operation no manifest implements, or hide one no reviewer blessed.

Capability *reduction* is a legitimate outcome of this review. Behavior that
works but cannot be proven is removed and refused rather than shipped
under-proven — and the reviewer writes the reason into the entry so the
generated page can print it.

### Adding a shipped adapter

There is no hand-maintained adapter-name allowlist. The runtime reservation
list is generated from the same package tree and byte-checked at release, so a
new capsule does require regenerating that projection but never copying its
slug into engine policy by hand. The permanent `id_kind` floor is different:
if the manifest introduces a new `tables.*.id_kind`, add it to
`IdentityNamespaces::GRANDFATHERED_ID_KINDS` in review because old customer
branches can retain that bare value even after a future adapter leaves the
library.

1. **Create the capsule and write its manifest** at
   `adapter-packages/<name>/package/manifest.json`, with a provisional
   `experimental` disposition beside it. State the untested boundaries and
   claim only operations the current evidence exercises. The library loader
   requires the manifest/disposition pair even for grammar validation; a
   missing disposition refuses before the grammar runs. While the capsule is
   incomplete, iterate with `php cli/wprism manifest-validate .
   --manifest=<name> --pins=core,<name>`; the complete package validator also
   requires the capsule's test and evidence boundary.
2. **Add the reviewed entry** at
   `adapter-packages/<name>/package/disposition.json`, with a
   `reason` a human wrote. Coverage is exact, so this is not optional
   bookkeeping — see the refusal above.
3. **Add deterministic offline coverage** under `tests/offline/`, then run
   `php tools/adapter-package-tests.php --adapter=<name>`. Package discovery is
   the wiring; do not add a Makefile leaf or edit the generated corpus.
4. **Add the mandatory exact-artifact evidence boundary.** Pin every exercised
   admitted and refusal artifact URL/version/SHA-256 in
   `evidence/artifacts.lock.json`, and
   own the fresh-install, adjacent-version, in-range upgrade, and out-of-range
   refusal workflow at `tests/certify/version-matrix.sh`.

   A plugin may already have an exercise pin in the shared platform fragment
   before it has a capsule. Move that subject's complete version map into its
   new owner, preserving existing URL/digest/role values; do not leave two
   owners or weaken the aggregate reader's duplicate-subject refusal. An old
   exercise pin is not automatically certification or refusal evidence. The
   normal driver is:

   ```sh
   candidate_sha=$(git rev-parse HEAD)
   WPRISM_SOURCE_ROOT=$(pwd) \
   VMATRIX_EXPECTED_SOURCE_SHA=$candidate_sha VMATRIX_MANIFEST=<name> \
   VMATRIX_PAIR=<private-pair> VMATRIX_PORT1=<even-port> VMATRIX_PORT2=<next-port> \
   bash sandbox/tests/certify/certify_version_matrix.sh
   ```

   A matrix may reuse its capsule's conformance hooks, so its wrapper must
   supply the same role-based hook ABI: `wp_conf1`/`wp_conf2` for WordPress
   commands; both drivers centrally supply `host_wprism conf1|conf2 <verb> ...`
   for orchestrator commands. Keep reusable hooks on those role names;
   concrete pair names, environment ids, registry paths, and Docker transport
   belong to the driver. A driver-private host wrapper can make standalone
   conformance green and then die with `command not found` when the
   exact-version driver sources the identical hook.

   The driver also owns caller-local infrastructure context. Before starting
   a pair, pin source mounts with `pair_identity_export_source_mounts()` and
   select the database with `pair_db_select_engine()` from `lib/pair_db.sh`.
   Both standard drivers do this. A new direct-Compose harness must do so in
   its parent shell; a `pair.sh` child cannot export back to it. Shared `.env`
   never carries database authority, and multi-engine drivers finish each
   cell's owned teardown before changing the selected engine. Plugin hooks
   inherit this context and must not choose infrastructure themselves.

   A direct live driver must work in a pristine worktree, not only one where
   another sweep already created `sandbox/siterepo`. Shared lease acquisition
   prepares that worktree's empty site parent before resolving its physical
   address; this creates no pair child or database and grants no cleanup
   authority until the complete lease census passes. Observation and release
   remain read-only with respect to missing context. Exercise an absent root,
   a distinct canonical lease store, and a non-directory root in shared harness
   tests; never add adapter-specific directory creation to hide a lease bug.

   Exercise fixture media through `MediaCapture` offline before a live round
   trip. AIO Login's initial 1px PNG passed WordPress metadata inspection but
   failed the engine's complete container check because its IDAT CRC was
   invalid. Share the exact image file between the native upload seed and
   the offline check; synthetic attachment identities do not establish that
   the plugin's image bytes can cross the media boundary. Keep the malformed
   file as a refusal control, without relaxing the production validator.

   Positive round trips must also provision the three required core bindings.
   `establish_core_environment_bindings` in `sandbox/conformance/asserts.sh`
   takes a WP runner, repository, and the driver's expected `admin_email`,
   `home`, and `siteurl` literals. It refuses a mismatching live fixture before
   binding those values through public `env-set --stdin`; it never adopts
   arbitrary nonempty values as intent. Conformance binds after source seed
   and target clone; `clone_case_target` reestablishes both matrix roles after
   every repository reset. Missing-binding negative tests and plugin-specific
   or protected-post environment choices remain explicitly fixture-owned.

   Immediately after each positive matrix apply writes `VMATRIX_APPLY_LOG`,
   call `assert_version_matrix_apply_ready` before another command can reuse
   that log. For a separately captured JSON apply, call
   `capture_wprism_json_checked APPLY_JSON <label> assert_wprism_apply_ready <command...>`.
   The shared assertion examines the complete stdout/stderr capture before
   the helper publishes JSON; checking only its last line loses stderr-only
   required-environment warnings. Use `assert_wprism_json_required_environment`
   for a terminal scoped replay whose verification is deliberately null, and
   retain its separate no-work/terminal-receipt assertions. Plain
   `capture_wprism_json_success` remains transport-only. The capture helper retains
   refusal envelopes and diagnostic bytes, but rejects a zero-exit answer
   carrying a PHP runtime diagnostic, including startup warnings and parse
   errors, before publishing the JSON value. Printing that diagnostic and then
   returning PASS is not positive evidence. These checks reject
   missing **required** bindings and failed canonical verification; the human
   matrix path retains the engine's post-verification clean-canary result.
   Do not require the aggregate `plan.env_missing` count to be zero across
   adapters: optional plugin environment rows legitimately contribute to it.
   Nor is an empty `warnings` array a universal first-apply invariant: that
   existing wire field also carries adoption and verified action receipts.
   Actual `env_missing:` diagnostics must not be accepted as green evidence.

   For a deliberate return to earlier settings (A → B → A), give the new direct
   scoped Apply an explicit `--request-id` and retain that ID for its retries.
   The engine binds it to one scope, artifact and deletion capability; the scope
   contract remains immutable read-only evidence. Prove both the new transition
   and its exact terminal replay. Retrying the old request against changed target
   state must still refuse. Do not invent an unrelated canonical setting change
   or delete engine receipts to manufacture a different content identity.

   Apply the same full-stream rule to native JSON observations, seed receipts,
   route probes and successful host deploy/settlement. Validate diagnostics and
   exit status before selecting JSON or trusting phase/native-state comparisons.
   Reuse the shared JSON capture or diagnostic assertion, not a last-line pipe.
   Exercise the actual helper or acceptance block with valid output plus a
   zero-exit PHP warning on each stream, retaining a healthy control. Correct
   payloads do not make ignored diagnostics into valid evidence.

   Separate **empty-content creation** from seeded adoption and managed-code
   installation. Prove the declared target coordinates absent across native
   statuses before any identity lookup or writer; do not pre-create them to
   make reference resolution pass. Derive the complete expected CREATE set
   from the real source compiler, bind all plan rows to that tree, and allow
   only the independently declared Core/default adoptions outside that set.
   Reject extra creates as well as missing ones. Carry complete physical
   censuses through every later mutation and retry, not just initial creation;
   bind native IDs, types, statuses and payloads back to those rows. An absent
   derived row can have different native behavior from a stored empty value.
   State target-local witnesses separately from source-managed state: managed
   sidebar assignments may be replaced or reallocated by Apply, whereas an
   explicitly unassigned local widget can be a preservation witness.

   Prove a coherent **source** at the actual Capture boundary, after all native
   authoring lifecycle work. In the Rank Math/ACF/Polylang/Woo combination,
   fresh raw metadata and API readbacks showed that Polylang post-meta sync
   already copied the English ACF value to German before Capture. Disabling
   sync preserved distinct values; re-enabling it and saving through Woo
   propagated a common value. Exercise both valid intents through the round
   trip, including a different target setting. A successful setter or one
   Action Scheduler assertion does not prove the rest of the source graph.

   Native authoring has ordering too: new post types and language rewrite
   configuration must be prepared before a native link index is treated as
   ready. The same source-only diagnostic had three correct internal URLs
   with unresolved targets. Soft rewrite preparation, a fresh request and
   native Rank processing resolved every edge, changing only targets/counts
   in the complete source observation. This is fixture manufacture, not an
   engine cache warm-up or a recovery-observer workaround. Retain bounded
   private source observations across pair cleanup, verify raw rows alongside
   plugin APIs, and refuse incoherence before capture. Derive incoming counts
   from the entire explicitly checked fixture graph: a third CPT linking to
   the English product contributes just as the German product does. Apply and
   retry must use that same complete graph oracle.

   Retain complete **target** observations at phase boundaries too. The
   combined retry at db96 had a successful command receipt but failed native
   isolation after its pre-retry Bash value was lost during teardown. The
   final value alone cannot identify the changed field. Reuse the same private
   capture transport and bounded reader for initial state, post-HTTP runtime,
   failed Apply, retry and fixed point, binding the reader to the exact target
   service. Prove retained evidence remains readable after site cleanup and
   does not alter or waive the full native comparison. Diagnose the actual
   before/after difference before expanding a mutation allowance.

   Include the pre-adoption target, not just the first successful Apply. The
   c837 combined recapture retained seven target-only files, but current
   Polylang API mappings could not identify older detached translation groups.
   Preserve the complete bounded term, term-taxonomy and relationship rosters,
   with raw serialized descriptions and distinct term/TT coordinates. Count
   before bounded transfer and reject short, malformed or oversized reads.
   Keep the post-recapture native observation before the comparison can fail.
   A matching taxonomy name, generated slug prefix or empty membership is not
   authority to ignore or delete an entity; establish its exact lineage first.

   A declared cache invalidation is not a runtime-preservation defect. The
   combined c76 retry removed only the applied redirect's derived cache, as
   its existing `redirection_id` invalidation requires. Assert the complete
   expected state, including that precise removal; do not drop the cache
   field from comparison. Exercise the generic materializer with foreign rows
   sharing a URL or colliding cache primary key, then prove native HTTP
   reconstructs the owned cache and advances exactly one hit. Fixed-point
   comparisons start from that post-HTTP state, not the earlier receipt.

   Post-type authorship alone does not grant deletion. For an unsupported
   custom CPT, prove missing-source Capture refuses before publishing a
   tombstone, then use its real compiled hash/revision to construct an
   otherwise valid synthetic intent and prove Plan/Apply compilation refuses
   before target mutation. Compare complete canonical trees even when files
   were already dirty, and restore only the test-owned synthetic target
   intent. Do not reinterpret this as an external-exclusion refusal or a
   successful signed deletion; those are separate capability boundaries.

   An unsupported-deletion fixture must still be valid native storage.
   Removing only a language's `terms` row leaves a `term_taxonomy` orphan;
   bounded term observation correctly refuses that malformed input before
   deletion policy runs. For Polylang 3.8.6, add an unused language with the
   native `no_default_cat` option, establish its identities with ordinary
   Capture, and delete it through the native language API. Observe the
   refusal preimage **after** that fixture mutation. Native deletion also
   clears runtime metadata, so its effects must not be attributed to Capture.
   If exact restoration is not available, run this control last and destroy
   the owned pair; re-adding a language mints different native identities.

   Complete native preservation can use unfiltered `wp db export -` outside
   WordPress, a separate `SHOW FULL TABLES` inventory, and the shared
   `SqlDumpEvidence`/`PrivateCommandOutput` readers. Bind deterministic dump
   flags at the producer, retain every table's schema and row bytes, assert
   nonempty fixture tables and stale ledger identities, and compare exact
   streams. Never normalize runtime values or accept equal empty dumps.
   Keep database credentials and dump bytes in the private evidence sink.

   If an absence premise needs selected native IDs or option values, use the
   shared `SqlDumpEvidence::projectColumns()` after complete-roster admission.
   Bind `--complete-insert --skip-extended-insert` at the producer; the bounded
   projection lexes every field without importing SQL or copying unrelated
   bodies into a plugin-specific value walker. Decode serialized plain values
   with the engine's `PlainData` codec, never an executable unserializer.

   A complete-row recovery comparison requires `SqlDumpEvidence::fullRows()`
   with an independently observed `SHOW FULL COLUMNS` roster; a selected
   projection cannot prove that omitted fields survived. For tables containing
   DECIMAL totals, floating-point literals or unsigned integers beyond PHP's
   range, use `SqlDumpEvidence::fullLiteralRows()` with that same complete
   roster. It validates every scalar and retains its original SQL literal:
   numeric `20.00`, quoted text `'20.00'`, `NULL` and binary hex remain distinct.
   Compare these literals as evidence; never cast them to floats, execute them,
   or use them as decoded identities. The stricter `fullRows()` and selected
   identity projection keep their existing integer bounds. Retain and compare
   the opaque schema sections too. Predict the exact allowed retry-marker and
   promotion-session transitions instead of excluding their tables. An authored
   commit failure must preserve native data; a later ledger-finalization failure
   may leave committed data with a stale baseline. Prove both states separately,
   then require retry to settle the baseline and repeat to leave it settled.
   Derive each marker from that attempt's planned work: an incomplete retry can
   replay unchanged entities and record a larger write set than the first attempt.
   A thrown exception does not prove process-death recovery. The shared database
   seam supports `WPRISM_TEST_DB_FAULT_MODE=kill` with `WPRISM_TEST_MODE=1` and
   an exact `WPRISM_TEST_FAIL_DB_CONTEXT`; the default remains `throw`. Kill mode
   requires real POSIX `SIGKILL`, so PHP cleanup cannot run. The shared pair's
   CLI services use Docker `init: true`: Linux namespace PID 1 otherwise ignores
   self-SIGKILL. Inspect the stopped container with `ContainerProcessEvidence`
   to bind its init, exact command, environment and invocation window and exclude
   OOM/runtime failure, then remove it before diagnostic readers run; retained
   stopped oneoffs trigger Compose orphan warnings. Preserve the durable lease
   left by the crash. An early retry must refuse with its exact public/private
   cause and preserve the entire database, canonical tree and native state,
   including the crashed session and marker. Prove the captured lease remains
   live throughout that invocation; give the bounded test TTL enough headroom
   for complete observations. Wait for verified natural expiry before a
   successful retry; never delete the lease or change timestamps to proceed.

   Complete uninstall and isolated missing-widget history are different
   premises. Full Plan/Apply checks retained canonical maps before dead-map
   pruning; missing backing data must reach `canonical_identity_recovery_required`,
   not a later planner's widget-history check. Require the exact public
   envelope and complete fresh private cause, with database/repository preimages
   taken after native uninstall and exact-code reinstall. Keep the stale map
   as evidence and prove it survives the refusal. Database-matched restoration
   is a separate subsequent control; neither pruning history nor accepting any
   nonzero exit proves safe recovery.

   Prebind the expected first cause from the genuinely compiled preimage and
   the native uninstall's removed families, not from the observed exception.
   Compiler order is top-level identity order with menu items visited inline
   under their owner; globally sorting item UUIDs selects the wrong cause.
   Prove every removed family's complete retained map and absent backing IDs
   before the command. Reinstallation can recreate a marker-only
   `widget_polylang` option: prove the mapped instances are gone, not that the
   option name is absent. Exercise term-first, menu-first and widget-only
   product paths, including items whose UUID sorts on either side of a term.

   Exercise options-only lifecycle observation before that full identity gate,
   too. It must retain canonical core and declared typed mappings after native
   deletion, even when an option read fails. Its capture-only lookup checks
   physical presence without reconciling the durable map: dangling option
   references still drop, while the subsequent full Plan can still refuse
   the missing backing rows. This is not an embedded-UUID ownership proof and
   must not broaden lifecycle observation into all plugin tables. Use the
   shared row-backed prune harness; a canned successful DELETE cannot prove
   preservation. The core product regression is
   `sandbox/tests/offline/capture/regress_lifecycle_identity_preservation.php`.

   For scalar typed kinds intersecting `option_name_refs`, merely removing a
   prune is insufficient: another scalar, list, structured-value or key
   reference may otherwise tokenize a deleted row. `LifecycleReferenceView`
   reuses the existing bounded option namespace and exact physical-key reader,
   retaining the same live/canonical option-name witnesses without deleting
   maps. An absent owner table keeps the lifecycle projection needed before
   activation; metadata permission must not grant row reads if it appears
   after the transaction profile was fixed. Exercise read failures, presence
   changes, stale authority and retry cache reset, and prove every native row
   and canonical byte survives. Canonical option names are separate bounded
   work items, like live options: exercise more than 1,024 retained names so
   preparing the roster cannot accidentally impose one callback's SQL quota
   on the whole namespace. Keep the per-item and enclosing callback limits
   unchanged. Full-state identity reconciliation remains a
   separate guarded boundary; do not recreate it inside a plugin executable.

   Rendered language identifiers are not interchangeable with WordPress
   locales. Pinned Polylang shortens unique-language hreflangs to `en`/`de`
   while Open Graph retains `en_US`/`de_DE`; regional variants change that
   native rule. Assert the exact fixture's complete alternate map, and refuse
   duplicate or malformed language tags before projection can discard them.

   Fixture repair must obey the pinned native API contract too. ACF 6.8.7
   deletes by field ID/key/name, not its returned field array, and reports
   `true` without checking `wp_delete_post` success. Before removal, prove
   the exact owned field and physical row; afterward, check bounded physical
   absence before restoring metadata. A fresh Capture must still reproduce
   the complete original canonical tree. Test failed deletion and failed
   readback separately so a successful-looking helper cannot hide either.

   A complete-row read window must control its test-owned background writers,
   not hide their rows. Core's retained native evidence localized one
   unexpected difference to `_transient_doing_cron.option_value`; its fresh-map
   comparison now uses `sandbox/tests/lib/wordpress_cron_window.sh` before the
   first baseline boot through the repeated read. The shared helper proves an
   owner-scoped `DISABLE_WP_CRON` MU guard in the selected site's native PHP and
   removes it outside WordPress on every exit. This is test infrastructure,
   not engine behavior or a generic promise of database quiescence: an already
   running worker, another writer or lazy expiration can still change rows and
   must still fail the comparison. Keep every option row in the witness, and
   retain negative controls for an actual cron-row write through the guard and
   a durable option change. Do not add transient exclusions, cache warm-ups or
   production special cases to make a read-only assertion pass.

   For observations spanning conformance child hooks and Apply, set
   `entry.disable_target_cron` or `entry.disable_source_cron` to `true` for each
   observed site. The shared runner establishes independently owned guards
   after pair bootstrap and removes them on success, failure or signal; child
   hooks need no independent transport or cleanup. Qi's Visual Portfolio
   combination exposed a source `_transient_doing_cron` timestamp change while
   its complete canonical state stayed identical. Declare the source window
   too when complete native source rows span repeated Capture.
   Omission or `false` preserves ordinary fixture cron behavior. The entry
   accepts only JSON booleans and is validated before pair mutation. A cleanup
   failure on one site must still release the other owned guard. This
   controls new fixture cron launches, not existing workers or other writers;
   complete-row comparisons must still retain cron rows. A version-matrix
   parent opens its own window for observations after the conformance child
   has returned and released its guard.

   A package can select a different complete fixture workflow through
   `CONFORMANCE_ENTRY_FILE` and an explicit `entry.hooks` map. Name all five
   phases: `seed`, `capture-check`, `postdeploy`, `postapply`, and `check`.
   Values are ordinary `.sh` files under that capsule's `fixtures/` or
   `tests/conformance/`; symlink paths and escapes refuse. `seed` and `check`
   are required files; the other three may explicitly be `null`. An explicit
   map never falls back to convention hooks. Omit `hooks` to retain the existing
   package/platform convention. Package validation and the runner use the same
   resolver, before pair mutation. The Importer clean-target entry reuses its
   source seed and capture checks while selecting target setup that creates no
   templates or jobs. Keep native consumer assertions and complete empty-table
   premises in the selected workflow; an alternate entry is not weaker evidence.

   The source-SHA binding is part of the evidence. A green run against another
   checkout is not evidence for the candidate. The package validator requires
   this matrix for every certified plugin adapter, requires every active pin to
   appear in its executable source, and checks that `certified-boundary` pins
   are inside `version_range` while `refusal-fixture` pins are outside it.

   This is test provenance, not a runtime zip allowlist. Runtime compatibility
   is the manifest's half-open version interval; WordPress exposes a plugin
   version, not the original archive bytes. Keep the interval no wider than the
   behavior the pinned artifacts justify, and never claim that a live plugin
   directory is byte-identical to a WordPress.org zip. If byte identity ever
   becomes a product requirement, it needs a separately designed shipped code
   identity contract rather than reading authoring evidence at runtime.
5. **Add the conformance checks inside the capsule.** Add
   `tests/conformance/entry.json` (the entry declares the pin set, artifacts,
   and state the round trip must preserve), and the mandatory `seed.sh` and
   `check.sh`. Add `postdeploy.sh`/`postapply.sh` only at the lifecycle seam
   their names describe. Test directories admit class-named executable suites
   and these closed hook names, not arbitrary helper filenames. Put reusable
   native PHP, shell transport and host readers under `fixtures/<workflow>/`
   and reference them from the hooks; do not hide them in `tests/lib` or label
   them as independent regressions. The package validator uses the runner's
   same closed discovery boundary before reporting success.
   A direct capsule live regression can reuse the reviewed
   `tests/lib/pair_live_ownership.sh` helper for exact-source leases, partial
   startup cleanup, teardown and PASS-after-cleanup. After changing directory
   to the repository's physical `sandbox/`, source it with the literal
   `. tests/lib/pair_live_ownership.sh` spelling the capsule validator admits.
   Pair lifecycle remains shared test machinery, not a capsule-owned copy.
   Direct `tests/live/` callers must first establish package artifact authority,
   even when they consume an already downloaded exact ZIP. Their first three
   active statements are the standard preamble enforced by
   `regress-fetch-artifact`:

   ```sh
   set -euo pipefail
   PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
   export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
   ```

   Run the candidate-bound gate from its exact commit:

   ```sh
   candidate_sha=$(git rev-parse HEAD)
   WPRISM_SOURCE_ROOT=$(pwd) \
   CONF_EXPECTED_SOURCE_SHA=$candidate_sha CONF_PAIR=<private-pair> \
   CONF1_PORT=<even-port> CONF2_PORT=<next-port> \
   bash sandbox/conformance/run.sh <name>
   ```

   Conformance hooks run in child Bash processes after the runner has fetched,
   digest-verified, and installed every `entry.json` artifact. That child ABI
   intentionally exposes the scoped, read-only `artifact_library_jq` helpers,
   not `fetch_artifact`: fetching owns the runner's array-safe Compose topology,
   which cannot cross the child boundary. A lifecycle reinstall resolves its
   exact SHA-256 from `artifact_library_jq`, constructs the corresponding
   `/artifacts-cache/<kind>-<slug>-<version>-<sha256>.zip` path, verifies that
   cached file through the target process, and only then installs it. Never
   fetch again from a hook; doing so can both fail with `command not found` and
   hide loss of the cache premise established during setup.

   A `certified` entry whose manifest declares a `plugin` must cite
   `conformance-<name>` in its `evidence.tests`, and that citation is only
   discoverable if
   `adapter-packages/<name>/tests/conformance/entry.json` exists —
   `sandbox/tests/offline/policy/regress_manifest_dispositions.php` proves both offline.
6. **Ratchet live premises and production readiness.** Every non-empty target
   observation or fixture-id assertion in conformance/version-matrix sources
   belongs in `evidence/target-observation-premises.tsv`; package validation
   checks both directions and its exact count header. Account for all twelve
   scenario families in `evidence/production-readiness.json`, citing the exact
   package, shared-engine, or participant-owned scenario gates. Shared Capture
   atomicity and platform-policy suites belong to the allowed shared evidence
   roots alongside Apply, reference and repository primitives. Cite their actual
   tests when they establish the shared half of a readiness claim. A missing
   primitive is `blocked`; missing coverage is `gaps`; neither may be hidden as
   `not_applicable`. See
   [the production-readiness contract](../agents/adapter-production-readiness.md).
   An experimental preview keeps these unfinished families explicitly
   `unready` and does not count as production-ready coverage. Isolated package
   validation refuses `certified` while any family remains a gap or blocked;
   a disposition edit cannot substitute for closing its evidence.
7. **Own combinations at the participant boundary.** When two or more adapters
   interact, create `integration-scenarios/<scenario>/scenario.json` with a
   sorted `participants` list and convention-named gates under
   `tests/{offline,live,certify,spike}/`. Cite those gates from the adapter's
   `evidence/external-tests.json`; the key must equal the gate basename with
   underscores changed to hyphens. This keeps a cross-adapter assertion out of
   every participant capsule while making changes to any named participant
   select the scenario automatically. Exercise both pin orders and every
   structurally distinct plugin load order needed by the claim, target-only
   neighbor state, ownership collisions, native frontend/API behavior, provider
   failure/retry, recapture, and the final no-op where they are structurally
   relevant. Two opposing orders are pairwise-complete for a set of movable
   plugins; test every permutation only when higher-order sequence behavior is
   part of the claim or plugin implementation. Install or activation order is
   not proof of load order: WordPress and plugins may rewrite
   `active_plugins` (Polylang deliberately forces itself first). After all exact
   artifacts are active, persist each claimed test-owned sequence and read it
   back in a new request before exercising product behavior. Preserve and state
   any plugin-enforced precedence instead of claiming a permutation the native
   runtime makes impossible.
8. **Close and generate the package boundary.** Run the full validator only
   after the files above exist, then refresh the runtime name projection:

   ```sh
   php tools/adapter-package-validate.php --adapter=<name>
   php tools/adapter-package-tests.php --adapter=<name>
   php tools/shipped-identity-inventory.php
   php tools/classmap-generate.php
   php tools/offline-corpus.php
   ```

   A new `id_kind` also moves the hand-reviewed permanent floor described at
   the start of this section. Re-measure explicit aggregate baselines such as
   effect-declaration coverage; do not replace behavioral numbers with a
   directory count merely to make the gate green.

   For a deliberate package identity change, check the complete re-pin set
   before the aggregate: `regress-disposition-split`,
   `regress-spec-v3-digest-neutrality` (including its byte-pinned
   `sandbox/tests/fixtures/spec-v3/wprism-greenfield-identity.json`),
   `regress-effect-declaration-coverage`, and `regress-spec-v3-document`.
   The latter pins measured disposition sizes and the provider identity census
   in `spec/repo-format.md`. Change only affected literal addresses/counts,
   retain unrelated pin sets and historical witnesses, and bind a new effect
   count to its exact phase/mode/selector authority. Never derive the expected
   identity from the same live value under test. During diagnosis,
   `make -k regress-offline-all` keeps independent leaves running after a
   failure; ordinary Make fail-fast can otherwise hide later stale baselines.
9. **Render and validate the aggregate** without checking it in:

   ```sh
   php tools/capability-doc.php render > sandbox/tmp/capabilities.md
   composer check
   make regress-offline-all
   make release-gate
   ```

   Review the scratch projection, but do not add it outside the capsule. The
   checked-in capability and grade pages define the model without duplicating
   adapter rows; this is what keeps ordinary adapter authoring package-local.

A profile (`fse` is the shipped one) follows the same shape inside
`platform/adapter-library/profiles.json`, with its own conformance entry.

**A shipped manifest is a shipped byte sequence.** Before you edit an existing
one, read [the identity warning above](#editing-a-shipped-manifest-moves-its-identity):
the edit moves `manifest_hash`/`adapter_digest`, and deployed sites refuse with
`compiled_artifact_manifest_mismatch` until the artifact is recompiled and the
pin re-issued.

### Authoring a SITE adapter instead

If the adapter is for your own site rather than the shipped library, none of
the above applies: an out-of-tree adapter has no reviewed disposition **by
construction** and never acquires one. It is data-only, it lives at
`adapters/<name>.json` in your site repository, and you certify it under your
own key:

```sh
wprism adapter keygen --out=<secret-key-file> [--key-id=<id>]
wprism adapter certify <site-repo> --name=<n> --secret-key-file=<f> [--key-id=<id>] [--reason=<text>] [--ratification-file=<f>] [--pin [--adopt-scope]]
wprism adapter pin <site-repo> --name=<n> [--source=site|plugin] [--adopt-scope]
wprism adapter adopt-scope <site-repo>... --name=<n> [--dry-run]
```

`certify` binds the signed statement to the compatibility CELLS
`platform/adapter-library/capabilities/platform.json` states — `spec_version`, `site_mode`, and
per axis the exercised cell names plus a digest of what each admits (§ v3.6). It
also RECORDS the shipped `agent_version` inside the signature without binding
it, so an agent release that moves no exercised cell leaves the certificate
valid. That binding is re-checked on every load, so a certificate whose cells
the boundary has dropped or now states differently refuses by name: `wprism: site
adapter '<name>' certification was exercised against '<axis>' cell '<cell>',
which the agent-owned platform boundary no longer carries`. Re-sign with
`wprism adapter certify … --pin`, which mints the current wire generation.

**Be exact about what a site certificate attests.** Without an exercised bundle,
`certify` signs approval of the exact adapter bytes and the real loader's grammar
verdict. Its bundle records `exercised: false` with empty tests and artifacts;
the resulting claim is **experimental**, and certification-only gates remain
blocked. Neither `--pin` nor an authored `--ratification-file` upgrades approval
into evidence. To obtain a Site-certified claim, supply `--bundle=<directory>`
and `--evidence-repo=<reviewed-checkout>` with named passing tests, artifacts and
bound inputs. That is site-rooted exercised evidence, not WPrism endorsement.
The full mechanics are in
[Site-installed adapters and external certification](#site-installed-adapters-and-external-certification)
below.

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
> [trust-enrollment.md](trust-enrollment.md) is a *different* file:
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

## Planned: what an adapter cannot express yet

Four shipped channels cover what a plugin needs to *do*: **native actions** for
core-owned operations, **providers** for plugin-owned ones, **regenerators**
for per-entity derived rebuild, **interpreters** for schema-driven
classification. What is still missing sits above them.

**Executable adapter packages, compatibility shims, and a public capability
catalog** are **Planned**. Site-repository discovery, plugin-bundled discovery,
packaged installation (into the site source, with a signed certificate),
derived trust tiers, loud unsigned support, agent-authority signed evidence,
and — since WP-5.6 — **remote discovery and distribution** all ship now. What
remains absent is any way for an out-of-tree adapter to introduce executable
code outside an installed plugin; do not work around that boundary with
manifest fields or copied PHP.

**Remote discovery and distribution: what shipped, and what did not.** `wprism
adapter discover|install|update` reads a `wprism-adapter-index/v1` document —
`{adapters, format}`, each entry `{adapter_sha256, agent_versions,
authority_fingerprint, certificate_sha256, certificate_url, url, version}` —
and that is the mechanism that tells you an adapter you do not already have
EXISTS. Installing one writes exactly `adapters/<name>.json` plus
`adapters/certifications/<name>.json`, so a distributed package is not a fourth
source: it is the site source, and everything above about pins, certificates
and claims applies to it unchanged. Resolution never falls through — an
unpinned version, a digest that does not match the fetched bytes, an
unreachable URL, an out-of-window package, an unsigned or unverifiable one, or
a signer your repository has not enrolled each refuses, and verification runs
in a staging root so a refusal leaves your repository byte-for-byte as it found
it. Nothing under `agent/` reads the format; installation is an operator act on
the host, never a runtime fetch.

Two boundaries inside that, stated rather than implied. **The index carries no
signature** and confers no trust: it is a pointer document, every entry is
digest-pinned, and every trust decision is re-derived from the fetched bytes
against your own `adapters/authorities.json`. A tampered index can deny you a
package; it can never install one. That means an index cannot enroll its own
signer either — you enroll a vendor key deliberately, or the install refuses
with `[authority_not_enrolled]`. **Exactly one transport ships, `file://`.** An
`https://` entry is discoverable and refuses at install naming the mirror step:
a network fetcher no offline suite can exercise is an unevidenced supply-chain
surface in the one command whose job is to refuse unevidenced bytes. Mirror the
two files into a directory you control and point an index at them. Adding an
HTTPS transport is a separate reviewed decision with its own evidence, not a
gap to be filled in. `spec/repo-format.md` § v3.19 and `docs/wire-surface.md`
R-30 carry both decisions and what would have to be true to reverse them.

What ships for the adapters you already have is the **installed-adapter
catalog**: `wprism adapter list|inspect|doctor` reports the two host-reachable
sources offline (and `wp wprism adapter-survey` all three, on the target),
printing each adapter's derived trust tier — `declarative_manifest`,
`native_action`, `plugin_provider`, or `compatibility_shim`, computed from the
privileges its own declarations actually reach and never self-declared — next
to a `tier_basis` naming the exact declaration that produced it. `doctor`
reports the discovery conditions that make every other command refuse
(shadowing, ambiguous identity, case-confusable names, an invalid identity
slug, a certificate that does not verify) as rows rather than dying on them,
each row naming which source it is about and whether it refused that whole
source or just one adapter.
Separately, `wprism plan` and `wprism status` now carry `provider_problems` rows for
every declared provider capability an environment cannot supply, with
remediation.

### One naming trap

The word **provider** carries four unrelated meanings here, all shipped. Three
are not the adapter provider this guide is about: bounded provider-resource
*selectors* in the spec, the target-owned *recovery* providers of the SSH
verified-rollback profile (exclusion, checkpoint, code-release, upload,
effect), and one attachment filter. Check which one an error means before
hunting for the wrong contract.


### Scalar settings inside a shared option

Use `scalar-option-constraints/v1` when a native consumer requires a strict
scalar type or a finite choice in an authored option subkey. For example, PHP's
`set_time_limit()` refuses a nonnumeric string even though an untyped option
value can compile and round trip. Declare the consumer contract at the value
boundary:

```json
"engine_features": ["scalar-option-constraints/v1", "spec-window/v1"],
"options": {
  "example_settings": {
    "class": "env",
    "required": false,
    "sub_keys": {
      "seconds": {"class": "authored", "value_constraint": {"type": "integer", "minimum": 0}},
      "method": {"class": "authored", "value_constraint": {"enum": ["quick", "template"]}},
      "flag": {"class": "authored", "value_constraint": {"enum": [0, "1"]}}
    }
  }
}
```

This is a manifest fragment; retain the ordinary explicit option-autoload
contract. Derive each type, choice and bound from the native producer and
consumer, including unchecked checkbox behavior and defaults. Do not invent
ranges or normalize integers and numeric strings into one type. A predicate
validates already-native data; it never sanitizes or generates defaults.
`lint_ok` still requires its normal reviewed rationale and cannot waive an
invalid value.

A sanitizer's output range is not the consumer's valid range. For example,
`absint(0)` survives the importer plugin's settings Save, but a zero export
batch cannot advance its offset and a zero import batch misses the CSV
reader's break condition. The declaration therefore requires batches of at
least one. Trace bounds through the consuming operation and exercise boundary
progress or termination; a getter round trip alone cannot establish validity.
Keep unrelated UI limits out unless their producer/consumer contract supports
them too.

Exercise capture, host compilation, public Plan/Apply, locked target preimages,
repeat, recapture, omission, and rollback. Keep foreign module keys and runtime
state in the fixture. A damaged constrained target value refuses before write;
repair it through an independently justified native recovery path. Whole-option
SQL strings, dynamic/pattern rules and executable classification require their
own representation evidence and are outside this feature. Re-pin any capsule
whose manifest gains this declaration; the predicate is part of its identity.

### Lifecycle observations must precede native self-repair

Observe lifecycle postconditions through raw database reads or an ordinary CLI
process before opening the plugin's admin/API surface. Some plugins both skip
hook registration outside `is_admin()` and run activation again on the next
admin bootstrap when an option is absent (Importer 2.7.5, main file lines 20 and
119). An admin observer can therefore create the very tables or marker it
claims deployment created. Record the target before that bootstrap, then test
the native admin behavior separately. Observe deactivation through an actual
admin-context native request when testing the plugin's own UI-equivalent path;
plain CLI deactivation may never have registered its callbacks.
