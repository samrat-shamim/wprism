# Native location reconstruction — implementation contract, not passing evidence

WPForms Lite remains experimental. This document records the reviewed design
for the missing `wpforms_form_locations` provider; it grants no Apply, deploy,
lifecycle or production-readiness claim. The unfinished scenario families in
`production-readiness.json` remain unfinished.

## Native authority

Source references below are to the locked WPForms Lite **2.0.1.1** artifact in
`artifacts.lock.json` (SHA-256
`6245074790df01a6e24a42587e024132b4a28fac499d1a8fa12ebf5580e4852b`).
The supported public authoring path, not the background task's implementation,
defines reconstruction semantics:

- `src/Forms/Locator.php:696-709,1067-1130`: normal post authoring obtains IDs
  with public `get_form_ids()`, deduplicates them before storage, and resolves
  the full current post with core `get_permalink($post_id)`.
- `Locator.php:1291-1341,1354-1379`: standalone authoring delegates to public
  `build_standalone_location()` and deduplicates its result. The builder uses
  decoded settings, gives form pages precedence over conversational forms,
  excludes templates, and internally calls `get_post_type($form_id)`.
- `Locator.php:779-920`: public `search_in_widgets()` reads exactly
  `widget_wpforms-widget`, `widget_text` and `widget_block`, each once with
  explicit default `[]`. It includes inactive and orphan widget assignments;
  `sidebars_widgets` is not an input to this scanner.
- `Locator.php:1140-1202`: public post-type/status eligibility and the native
  content parser remain native calls. Do not copy the regex or recursively
  expand reusable blocks beyond the parser's actual behavior.

The private task is not an interchangeable oracle. In
`src/Tasks/Actions/FormsLocatorScanTask.php:407-420,474-502` it supplies only six
post fields to permalink generation; normal authoring resolves the full post.
Its standalone query at `:512-545` first searches for literal compact JSON
fragments containing string `"1"`, whereas the public builder tests decoded
truthiness. Whitespace, booleans and integer values can therefore distinguish
the paths. Its unordered ID query and chunked `WP_Query` also do not promise a
stable row order. Do not reproduce these private selectors or invoke the task
through reflection, construction, scheduling, `scan()`, `rescan()` or `delete()`.

## Canonical derived-cache policy

Use the already initialized `wpforms()->obj('locator')`. Reinitializing it
would change request state and install hooks; `Locator.php:177-210` is not a
pure factory. The intended provider will:

1. Read complete bounded physical inputs through the SDK inside the existing
   contract profile. Use native eligibility and public parsing over physical
   post content. For permalink generation, supply a full physical `WP_Post`
   with raw filtering, matching normal authoring rather than the task's
   partial-object optimization.
2. Use the public standalone builder over bounded decoded form data. Validate
   the actual form ID and `form_data.id` agree before invoking it. Its internal
   cached `get_post_type()` dependency still requires an independently proven
   premise; a constructed post cannot be supplied to that integer-only API.
3. Wrap the public widget scanner in `ProviderSdk::native_option_inputs()`
   using the three exact names, defaults, passed-default flags and read counts.
   The engine supplies the physical expectations; a capsule does not assert
   the values it wants the callback to consume.
4. Validate every native location before mutation. A discovered form ID that
   is missing, malformed or resolves to a non-`wpforms` post is a hard refusal,
   not a skipped location. This deliberately tightens the task's nonempty-ID
   admission (`FormsLocatorScanTask.php:560-575`).
5. Canonicalize duplicate same-form/same-post placements and retain distinct
   posts and widgets. Stable physical post-ID ordering is a WPrism policy for
   this derived cache, not preservation of historical insertion order.
   WPForms renders, deduplicates and sorts rows (`Locator.php:660-682`), and its
   mutation paths remove by location ID then append (`:950-990`); no reviewed
   WPForms consumer gives an entry index semantic meaning. This is not a claim
   about unreviewed external consumers of the raw array.
6. Reconcile only the byte-exact `wpforms_form_locations` meta key. Keep the
   lowest existing `meta_id`, update changed bytes only, delete duplicate or
   obsolete owned rows, and insert only missing rows. No locations means no
   key. Preserve collational aliases and every nonowned row; never delete and
   reinsert the whole index to manufacture a fixed point.

## Boundaries that must close before a provider can succeed

The merged native-option witness proves the widget inputs, not an arbitrary
native reconstruction. Permalink, home, registry, current-user and standalone
post-type/cache dependencies still need source-backed admission and hostile
tests. Passing a full physical post closes only the primary permalink input;
it does not close parent-post, term, author, option or filter dependencies.
Do not warm or repopulate a cache to manufacture a premise, and do not reject
all native permalink filters simply to avoid reviewing their semantics.

Keep reusable physical reads, cache-input admission, transactions, typed DML,
process ownership and receipt transport in the engine. Keep WPForms parsing
selection, eligibility, location shape and exact-key reconciliation in this
capsule. The existing SDK and fresh-process protocol are the starting point;
any missing shared operation needs its own root-cause regression before use.

Initial native computation and complete budget admission must precede the
first derived write. Later passes may observe current transactional inputs
again. Pure physical preimages, uncertain-commit classifiers and the second
fresh observer must not call native cache-backed getters. Mutation-time native
correctness and later durable input/output equality are separate claims.
Declare request-local cache and native callback effects honestly: SQL rollback
cannot undo them. Never grant queue, scan-status, logging or global-cache
mutation merely because the background task performs it.

## Required discriminating evidence

Before implementation can become a supported capability, exercise through its
actual provider path:

- `"01"`/`"1"` and repeated embeds, distinct placements, all three widget
  families, inactive widgets, dynamic CPTs, FSE templates/parts and native
  reusable-block behavior;
- date-token, front-page, category, author, hierarchical and private-post
  permalinks, including cold, stale, warm and hostile cache/filter premises;
- standalone string, boolean, integer and whitespace spellings, malformed
  bodies, template exclusion, precedence, mismatched IDs and missing/non-form
  references, with complete pre-write preservation on refusal;
- stale/duplicate/orphan owned metadata, collational aliases, unrelated
  metadata, no-location forms, removed embeds and pre-admitted output bounds;
- partial-write failure, swallowed refusal, uncertain commit, complete row
  rollback, stable `meta_id`s across two fixed points and independent fresh
  postimage observation;
- native target Apply/deploy/recapture and UI consumers, plus lifecycle,
  deletion/refusal, real submissions/mail isolation, adjacent artifacts and
  explicit participant-owned plugin combinations.

These are requirements, not a completed run or an assertion that the current
package ships the provider. The executable candidate in
`fixtures/location-provider/wpforms-form-locations.php` is deliberately not
registered in the manifest or assembled onto sites. Its capsule-local offline
suite checks target-ID normalization and complete stable-ID mutation planning;
that is mechanism evidence, not native invocation or readiness evidence.
Promotion and package re-pinning happen only with the implemented, reviewed
and exercised package bytes.

Implementation review exposed three source-semantic edges: widget target IDs
may contain leading zeros while physical row identities stay canonical;
public metadata writers recursively unslash values before serialization; and
raw metadata DML must invalidate affected native `post_meta` caches through a
bounded engine-owned effect boundary. The candidate implements the first two.
Cache coherence remains unclosed, as does proving equivalence with Locator's
init-time cached home URL; a fresh `home_url()` call cannot prove that history.
