# Native location reconstruction — candidate contract and scoped evidence

WPForms Lite remains experimental. This document records the reviewed design
for the experimental `wpforms_form_locations` provider; it grants no complete Apply, deploy,
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
   post content. Gather selected placement IDs, then use the engine-owned
   `checked_native_permalinks()` batch for current native home and links.
   The capsule supplies neither partial posts nor a native callback; integer
   core reads exercise the admitted full-post and ancestor caches themselves.
2. Use the public standalone builder over bounded decoded form data. Validate
   the actual form ID and `form_data.id` agree before invoking it. Its internal
   cached `get_post_type()` dependency uses the independently admitted
   `checked_native_post_types()` immediately before the audited public builder.
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
7. Store a single relative suffix under the **admitted current target home**.
   This is an explicit WPrism derived-cache policy, not byte-identical replay
   of the public writer's historical private home snapshot. Locator caches
   home at `:177-186`, strips it at `:1108-1129`, and renders home plus the
   stored URL at `:612-630`. A stale instance can therefore write an absolute
   new-home URL which its renderer concatenates into a broken double-home
   link. The provider targets a fresh consumer using current home semantics.
   Strip one exact leading home only; retain identical text inside a query or
   fragment. Refuse external URLs, authority/base-path prefix traps, malformed
   homes and browser-resolved dot segments/encoded path separators. Core home
   options are untrailed before filtering; a filtered trailing-slash home is
   outside this canonical representation. Do not silently normalize different
   authority spellings or default ports into equality.

   The public renderer has a second boundary: `Locator.php:647` applies
   `urldecode()` to the whole escaped link markup before KSES. Actual native
   reconnaissance reproduced `%3F` becoming a query delimiter, `%23` a
   fragment, `%26` a query separator, `+` a space, and an encoded quote
   truncating the link. Therefore both the current home and permalink refuse
   literal plus and encoded ASCII other than RFC unreserved characters;
   decoded non-ASCII must be valid UTF-8. Encoded unreserved characters and
   Unicode may render decoded but preserve the same target. Stored suffix
   bytes remain unchanged. Do not silently double-encode derived storage to
   work around a native renderer whose other consumers are not yet proven.
   This is a WPForms consumer limitation, not a missing engine URL primitive.

## Boundaries that must close before a provider can succeed

The merged native-option witness proves the widget inputs, not an arbitrary
native reconstruction. The candidate now uses the shared native permalink
reader rather than calling `home_url()` and `get_permalink()` itself. Its
engine mechanism tests cover post/page/registered-CPT routes, stock statuses
under the anonymous private-link context, ancestors, options, rewrite state
and reviewed stock hooks. Category/author dependencies and extension-specific
permalink participants remain explicitly unclosed and refuse. The focused
native provider lane below now exercises the admitted positive subset through
the real SDK and process protocol. Six native pre-write refusal cases are
recorded below; native recovery evidence through the fresh-child path and
complete WPForms lifecycle evidence are still owed. The candidate is not registered in
the shipped package, advertised or counted as ready.
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
- ordinary and base-path homes, historical-home/current-home divergence with
  an intentional byte difference and a correct fresh UI link, external URLs,
  prefix traps, repeated-home query text, coherent domain mapping and volatile
  filters. A later durable-home change must still render correctly in a fresh
  consumer and recapture to a fixed point;
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

These are requirements, not a completed readiness run. The provider now lives
at `package/runtime/providers/wpforms-form-locations.php` and is declared by
the experimental manifest. Earlier native records below exercised an unshipped
private copy; moving it into the package does not upgrade those records into
installed-package or Apply evidence. Its capsule-local offline
suite checks target-ID normalization, current-home URL policy and complete
stable-ID mutation planning;
that suite is mechanism evidence, separate from the focused native invocation
below and from readiness evidence.
Promotion and package re-pinning happen only with the implemented, reviewed
and exercised package bytes.

Implementation review exposed three source-semantic edges: widget target IDs
may contain leading zeros while physical row identities stay canonical;
public metadata writers recursively unslash values before serialization; and
raw metadata DML requires a correct cache-coherence boundary. The candidate
implements target normalization and unslashing. Subsequent architecture review
found that the existing fresh-process protocol already supplies the
cross-process boundary: `ProviderOperationProcess::run_operation()` flushes
before each mutation/observer boot, `child_main()` flushes before replay, and
`RebuildActionDispatcher` flushes before and after its action loop. Its existing
process regression verifies both launches against independently repopulated
cache state. The eventual capability must use that protocol; no new
WPForms-shaped cache effect is justified. Same-child native callback reads of
post metadata after pass-one DML remain part of the native-input proof.

The current-home policy above removes any need to prove equality with
Locator's historical private home field. It does **not** remove the need to
admit the actual current native home/permalink/filter inputs, or to prove the
two-pass fixed point and fresh-consumer behavior. These remain open before
the provider can be registered or advertised.

## Focused native current-home proof

`tests/live/regress_location_current_home.sh` exercises the actual public
normal writer and `Locator::column_value()` on one owned exact-artifact pair.
Separate WordPress processes observe initial, changed and later durable homes.
Its native fixture deliberately supplies the candidate's canonical metadata
through the public metadata API, then separately renders pre-fix unsafe bytes
to distinguish the seven refusal cases from the four admitted examples.
The host independently checks all three complete private records, identities,
stored location arrays and actual link targets; its admission regressions use
synthetic inputs and make no native claim.

Run after the unconditional offline gate, from a clean committed checkout:

```sh
WPFORMS_LOCATION_PAIR=<owned-name> WPFORMS_LOCATION_PORT1=<even-port> \
WPFORMS_LOCATION_PORT2=<successor-port> \
WPFORMS_LOCATION_ZIP=<absolute-locked-2.0.1.1-zip> \
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
bash adapter-packages/wpforms-lite/tests/live/regress_location_current_home.sh
```

This is scoped native writer/rendering evidence, not a provider invocation,
HTTP routing proof, capture/apply/recapture fixed point or native-input scope.
Those requirements remain open. The script retains private command streams
under `sandbox/tmp/` and delegates leases, teardown and PASS-after-cleanup to
the shared pair ownership helper; it owns no persistent pair.

## Focused native provider proof

`tests/live/regress_location_provider.sh` passed against source
`bb88baac0ece5b50ecf870f8ec3b5cc4951f933e` on owned MariaDB pair `wpfprov03`,
which was destroyed before the script reported PASS. It exercised the locked
2.0.1.1 artifact above through ordinary Policy loading, repository compilation,
persisted-artifact reopening, provider negotiation and invocation. A private,
explicitly excluded library contains the candidate; no shipped manifest or
disposition was edited and no readiness authorization was borrowed.

Both actual invocations succeeded (3.648 s and 3.665 s). Each used distinct
engine-owned mutation and physical-observer processes. Independent fresh
WordPress consumers then rendered all 19 locations for the embedded form and
the five standalone cases; the no-location form retained no owned key. The
host compared complete native writer values, exact serialized storage, all
eight input tables and nonowned metadata, removal of stale/duplicate/orphan
owned rows, preservation of the oldest owned identity, and an exact physical
and rendered-markup fixed point across retry.

The discriminator roster includes all five selected post statuses, pages and
ancestors, the public/queryable CPT union and its hidden exclusion, templates
and parts, parser/duplicate cases, all three widget families with inactive and
orphan widgets, standalone precedence/null defaults/Unicode, and template
standalone exclusion. Attachment creation is honestly recorded as native
`inherit`, followed by one explicit dirty physical `publish` fixture write to
isolate type exclusion. Writer observations contain no warm-process UI claim;
complete UI is required only from fresh observer boots.

The five original private records remain under
`sandbox/tmp/wpforms-provider-native.udvFJK/`; their stdout SHA-256 values are:

- `seed`: `c333793d93dc3a5c10744ace455b2c55fa29908543b8b82426801ce1d838fa59`
- `invoke`: `fb71e4c736b7526b33b28ad89d19ccac8675de2853e93b14b0b543b1969143f3`
- `observe`: `192983107e7bb15f0d5201ac74c95ac1762a5ef77c18acbf19d10e2317d8dafb`
- `repeat`: `fbe477e7efaf51f8b63136aa0dcaa98e5d6c0ae909ba78d153dbcfb6f7f6d325`
- `stable`: `ac364b9414c5a01450382fcce2f004b6ac4a7308d78fc03bda5080b38c78bd51`

These are private provenance pointers, not a sealed bundle or a portable
certificate. The original positive verifier's 40 synthetic admission and
privacy controls now sit inside the combined 268-assertion host suite; those
controls do not substitute for the native run. Earlier
failed fixture runs were retained separately and were not edited or re-admitted.

The repository compiled in this lane is deliberately empty: this proves a
provider executing against existing target state, not authored Apply or
deployment. Broader hostile-input frontiers, failure injection and uncertain
commit, locale changes, target Apply/deploy/recapture, lifecycle, deletion,
submissions and participant-declared combinations remain open.

## Focused native refusal preservation

The same provider lane passed again on 2026-09-08 against clean source
`a6f2a5024c89c699a63b6ea82b7db113362f3eae` with the locked 2.0.1.1 artifact.
Owned MariaDB pair `wpfprov04` was destroyed before PASS. Both positive
invocations and the complete five-record positive sequence passed, followed
by six isolated dirty-input cases: missing, non-form and template embedded
references; exact malformed form JSON `{`; a raw-null widget title; and an
unsafe standalone URI slug `a%3Fb`.

The host re-admits the complete positive baseline for every case and selects
the intended physical cell independently of the mutation report. It requires
one failed engine mutation child, no success observer, a null receipt, the
complete seven-node outer transport graph and the exact complete private inner
cause. Malformed JSON carries the actual `RuntimeException` then previous
`JsonException("Syntax error")`; other cases have the exact single native
refusal root. Child reports are 1,470–2,209 bytes, all untruncated. The reusable
graph admission is test-only `PrivateRefusalReceipt::assertGraph()`; it produces
no receipt and does not invent CLI-command or invocation provenance.

All eight input tables and every owned/nonowned postmeta row match the exact
independently reconstructed dirty state before and after invocation and in a
fresh WordPress readback. Restoring only the deliberately mutated fixture cell
then restores the complete positive physical baseline. These malformed bytes
are not claimed to come from native public writers. Fixture restoration is not
product rollback, uncertain-commit, Apply or lifecycle evidence.

The 29 positive/refusal phase records remain privately retained under
`sandbox/tmp/wpforms-provider-native.NvcQjt/`. The five positive stems are
`seed`, `invoke`, `observe`, `repeat`, `stable`; each refusal case has
`<case>-seed`, `<case>-invoke`, `<case>-observe`, `<case>-restore`. Luna and Terra
independently re-admitted all seven sequences from exact-source detached
checkouts with no findings. Capture commands exit zero because the fixture
serializes observations; the host separately requires the real failed-child
graph and null receipt. The sink is 0700 and streams/status files are 0600.

Refusal invocation stdout SHA-256 retention pointers, not sealed certificates:

| Case | SHA-256 |
| --- | --- |
| missing-embed | `0820714f0cc0c0ff67c26f647af952e789f219da72291e131bb18f48e58bbe5e` |
| nonform-embed | `38eea6a61cc0186385e4505ea91cae0f3a4ffa888a68fac256f3a5d812be8ecf` |
| template-embed | `d9ef672aae7f441d7c17eac93a57729e6e5f0abb38e4b89eaa69a8c9ea6d7bf5` |
| malformed-body | `2a383c307d69329174c8cc577407a51ed5387b4f518402659124d0de833f105a` |
| null-widget-title | `875836b3820ad5da45982ad283c5a5befdb31e61cb8e5ecacd7bb52286f68775` |
| unsafe-standalone-uri | `9bbb3300846250c8e2ed977ce0f7c094ac0977bd428e5246f1698824f2223577` |

## Candidate recovery mechanism coverage

The SDK-model suite `tests/offline/regress_location_native_inputs.php` now has
224 assertions. Three failure-recovery cases use the existing bound candidate
runtime and shared `FakeWpdb` seams, not a new launcher or runtime fault hook:

- fail a typed DELETE only after observing that the preceding typed UPDATE
  actually changed the complete physical state; require the typed mutation
  exception, complete preimage rollback and settled isolation;
- lose the COMMIT acknowledgement after durable application; require the
  candidate's complete physical classifier to admit it in one extra read-only
  snapshot, then require explicit retries to preserve the same owned identity
  and perform no DML;
- lose the COMMIT acknowledgement without durable application; require the
  retry-safe typed not-applied exception, complete preimage preservation and
  an explicit retry that converges, followed by a mutation-free fixed point.

Every case preserves all complete input tables, collational aliases and
unrelated metadata. These are supplied-native-response/driver-model tests of
the actual candidate's DML and classifier integration. They are not real-plugin,
Policy-loaded, fresh-child or native server-fault evidence, and do not close a
production-readiness scenario family by themselves.

## Ordinary Apply requires the actual trusted package

The first content-only lane ran against clean source
`4756ad35fefbf0f07a8853ed40fdb677222f15cd` on owned MariaDB pair `wpfprov05`.
Both native seeds, explicit core environment bindings, Capture and Plan
succeeded. The target plan had nine explicit adoptions, two updates, one
selected provider and the two expected experimental promotion blockers.
Apply refused at fresh canonical verification: the parent-only private library
could not re-prove its frozen manifest against the verifier's trusted library.
This is the provenance guard working, not an invalid platform architecture.
The refusal is after authored writes/rebuild; it is not rollback evidence.

The complete outer streams are retained at
`sandbox/tmp/wpforms-apply-native.bPW8kK/`; the shared diagnostic lifecycle
retained one complete private Apply refusal record at
`sandbox/tmp/wprism-conformance-apply.wpfprov05.UDRCIy/` before exact pair,
database and disposable repository teardown. No private cause is published.
The run exited 1 and is not positive Apply evidence.

The corrected lane uses ordinary `wp wprism capture/plan/apply` and the actual
experimental package in every process. The source repository is new and has
never declared managed code; both sides already have the exact plugin active.
No code baseline is removed, no certificate is manufactured and no verifier
library override is introduced. Full package, lifecycle, recovery, data-boundary
and cross-plugin readiness remain unfinished. Recompile/re-pin is required
for the new package identity; old compiled pins do not silently follow it.

The second run used actual package source
`c42c43d35e16d314873685bb00a13656f9e5bdd5` on owned pair `wpfprov06`.
Its baseline ordinary Apply returned eleven authored operations, one verified
provider action and passing fresh canonical verification; repeat Apply
returned zero writes/actions. Both recaptures and complete repository trees
were retained. The next embeds-only Plan refused the environment-binding
mode guard after shared `repo-host` recursively broadened the private file's
permissions. Complete streams remain at
`sandbox/tmp/wpforms-apply-native.dlXS0g/`, with one complete private Plan
diagnostic at `sandbox/tmp/wprism-conformance-plan.wpfprov06.GWE7gs/`.
Exact pair, database and disposable repository teardown completed; the run
exited 1, with no full-lane PASS or readiness claim.

Independent Luna review also rejected the old verifier's whole-render
fixed-point assumption (`Token.php:169` emits request-time anti-spam data)
and its assumption that every core block widget contains a WPForms reference.
The next run requires exactly one numeric `data-token-time` attribute per
render and compares every other rendered byte. Fresh observations now carry
the complete native Locator form-ID roster for block widgets, including empty
rosters, and a deliberately unassigned target-local core block proves
preservation independently of managed sidebar changes. The c42 observations
are not retroactively promoted into this stronger evidence format.

### Native authored-state Apply evidence

Source `078c440b35633c61399e66f0e578b78d4c4bfab0`, locked WPForms Lite
2.0.1.1, owned MariaDB pair `wpfprov07`: all four ordinary command cycles
completed, including fresh native observations and both complete recaptures.
The source repository never declared managed code; both plugins were already
active. Complete phase streams and all twelve recapture/source trees remain
at `sandbox/tmp/wpforms-apply-native.DjPJZZ/`.

| Native case | Authored Apply operations | Verified provider actions | Repeat writes/actions | Fresh canonical verification |
| --- | ---: | ---: | --- | --- |
| Baseline adoption | 11 | 1 | 0 / 0 | pass |
| Embed-only removal | 1 | 1 | 0 / 0 | pass |
| Sidebar/widget-only change | 1 | 1 | 0 / 0 | pass |
| Page title/slug-only change | 1 | 1 | 0 / 0 | pass |

The producer's final host verifier incorrectly required pre-Apply numeric
slots for managed core block widgets. The engine correctly allocated new
target-local slots; the complete five source setting values were unchanged.
The producer therefore exited 255 after successful native commands and exact
pair/database/repository teardown. No producer PASS is fabricated.

The corrected read-only verifier admits these same retained records without
modifying them: complete zero-form-ID source block settings, with multiplicity,
plus the exact unassigned target-local block99 equal the target multiset.
Target/stable option arrays still compare strictly, while full compiler-backed
convergence independently binds the managed sidebar identities. Offline
controls now exercise source/target reindexing and missing, duplicate or
changed complete values. Source WPForms rosters must match the complete
controlled selector settings and the exact case count; empty rosters cannot
hide selector bytes. Coordinated forged-source/dropped-target controls pin
both directions. This is slot-independent comparison of complete authored
settings, not a content normalizer or an omitted native row.

Root re-admission passed all four cases with empty stderr at
`sandbox/tmp/wpforms-apply-native-v3-readmit-v2.{stdout,stderr}`. It checks all
public phase transports, exact ScopeContract/action/artifact association,
native consumers, mapped IDs, both local widget sentinels, all target-only
trash rows, physical metadata and full source/target/source-repeat compiler
convergence. Readiness remains experimental: this is native content-only
Apply evidence, not managed-code installation/deploy, lifecycle, native
server-fault recovery, deletion, submission/mail isolation or plugin-combination
certification.

### Empty-content target creation and preflight refusal

Source `d49152a0f9e3b9b6aace91df0f73dd3d1c84f0cb`, locked WPForms Lite
2.0.1.1, owned MariaDB pair `wpfempty02`: the same ordinary Apply driver passed
with `WPFORMS_APPLY_TARGET_KIND=empty`. The plugin was already installed and
active. The target never ran the source wizard/form writers; an all-status
physical census proved both WPForms post types and the named fixture pages
absent, alongside absent location rows and WPForms widget placements.
This is empty **authored content**, not a blank WordPress installation or
managed-code installation/activation evidence.

The initial public plan contained five creates derived from the real source
compiler, four Core default adoptions, and two updates. Admission requires the
complete CREATE bucket to equal those five source UUID/type/path rows; every
other plan row must also name a real compiled entity. Fresh native target IDs
were distinct from source IDs, mapped to those exact UUIDs, and consumed by
the native integer/string self-ID documents, confirmation pages/redirects,
shortcode/block embeds, renderer and Locator. The template retained no self-ID
and received no derived location row.

| Native case | Authored operations | Verified provider actions | Repeat writes/actions | Fresh canonical verification |
| --- | ---: | ---: | --- | --- |
| Empty-content creation | 11 | 1 | 0 / 0 | pass |
| Last integer-form embed removed | 1 | 1 | 0 / 0 | pass |
| Sidebar/widget-only change | 1 | 1 | 0 / 0 | pass |
| Page title/slug-only change | 1 | 1 | 0 / 0 | pass |

The second case leaves the integer form genuinely unlocated: its physical
location row is absent, `get_post_meta(..., true)` returns `''`, and the native
unscanned Locator column is `—` (`Locator.php:261-270` in the locked artifact).
An empty serialized array is not equivalent evidence. Every post-creation
case checks the complete physical post census, native type/status/title/body
binding and repeat equality, not only the initial creation case.

Target-local preservation means the seven complete trash rows and the
**unassigned** core block99 sentinel. Managed sidebar assignments are
source-authored: their numeric slots may be reallocated, and the widgets case
deliberately replaces the source's old managed core widgets. Full compiler
convergence binds those assignments; raw before/after sidebar equality would
incorrectly reject that intended change. The complete resulting native sidebar
option and physical rows must be identical on repeat.

Before initial creation, the driver changed exactly one source-derived
shortcode reference to an absent UUID. The complete retained negative tree
differs in only those bytes. Ordinary `wp wprism apply` exited 1 with
`repository_compilation_failed` / `semantic_delete_reference`; independent
compilation reproduces the exact diagnostic. Complete native absence/local
witnesses were byte-identical before and after. Restoring the original source
recompiled to the exact baseline artifact before the successful Apply.

All 59 retained phase commands have their expected status (58 successes and
one deliberate refusal). The producer exited 0 and emitted PASS only after
its exact pair, databases and disposable repositories were destroyed and its
lease released. Streams plus twelve positive source/recapture trees and the
negative source tree remain under the producer worktree at
`sandbox/tmp/wpforms-apply-native.tIQJAN/`; the outer run is
`sandbox/tmp/empty-native-v2.{stdout,stderr,exit}`. Read-only re-admission passed
all four cases with empty stderr at `sandbox/tmp/empty-native-v2-readmit.*`.
An earlier launch with a wrong caller umask was intentionally interrupted
(exit 130) during pair bootstrap and fully cleaned up; it is not native
evidence. No shipped package bytes changed in this follow-up. WPForms remains
experimental, with the broader readiness gaps listed in
`production-readiness.json`.
