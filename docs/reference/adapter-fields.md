# Adapter field and reference recipes

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

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
  claims — [see below](adapter-validation.md#the-grammar-document)). Every feature is load-bearing:
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

Page DOM visibility and focus do not establish that native browser input is
available. Qi's disposable `admin/admin` login triggered Chrome's Password
Manager notice outside the page DOM: links, toolbar controls and keyboard
activation appeared inert while the page reported visible and focused. The
same empty bare WordPress editor opened List View after that informational
notice was acknowledged. Inspect the native browser surface and verify a
simple control's actual postcondition before attributing an input failure to
an adapter. Keep the incomplete attempt and the isolated premise correction;
handling a test-environment notice grants no native Save/reopen evidence.

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

### Literal flags, closed objects and strict scalar fields

Some native attributes alternate between a literal flag and a closed object.
WordPress 7.1 visibility controls save `metadata.blockVisibility:false` for an
omitted block and a nonempty viewport object for responsive hiding. Its rename
control writes a string name and removes the metadata object when the final
member is cleared. The exact Qi native saves are retained in
`adapter-packages/qi-blocks/fixtures/native-global-controls/`; an empty-container
exception is not needed for those resets.

Negotiate `block-value-shapes/v1` alongside the block value and contract
features. An authored `one_of` contains exactly an `enum` rule and an
`object_fields` rule. For example:

```json
{"class":"authored", "one_of":[
  {"class":"authored", "enum":[false]},
  {"class":"authored", "object_fields":{
    "viewport":{"class":"authored", "object_fields":{
      "mobile":{"class":"authored", "enum":[false]}
    }}
  }}
]}
```

Selection follows JSON shape; invalid active values refuse without trying the
other rule. Both alternatives receive complete recursive validation and count
toward the existing contract budget. Alternative order grants no precedence.
An authored `plain_data:true` rule may also declare `scalar_type` as `string`,
`number` or `boolean`, admitting that exact JSON type without coercion. Use this
for a reviewed text field such as the native block name instead of allowing an
arbitrary object to occupy its string coordinate. Unknown object members still
refuse, absence stays absent, and privacy clearance is unchanged.

Original shape-bearing comment bytes and public post/widget Lint share pure
validation of every present value contract on the selected owner, including
ordinary enum/object siblings, even without WordPress parsing. Native Save/reopen, frontend
visibility and other injected metadata remain separate evidence obligations;
do not use these contracts to admit unreviewed bindings, notes or pattern IDs.

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
[the limitation ledger](../guides/adapter-authoring-limitations.md#wpforms-lite-2004--2005)
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
