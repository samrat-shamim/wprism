# Native QR destinations — follow-up evidence slice

This follows the bounded native-tag checkpoint in PR #600. It is a design
and initial offline mechanism checkpoint, not a native-run record. WPForms
Lite remains experimental/unready; no readiness family or shipped package
declaration changes here.

## Exact native authoring contract

The source is the locked Lite **2.0.1.1** artifact in `artifacts.lock.json`.
`src/Admin/Builder/Settings/QrCode.php:147-298` renders None, Page, and Custom
URL. Initial page choices come from the capability-checked, capped and ordered
`wpforms_search_posts()` query (`includes/functions/access.php:341-439`):
readability alone is insufficient because that query checks delete-page
capabilities. Bind the actual Page A/B controls and `data-pages` permalink map.
The saved-page read-capability fallback (`QrCode.php:384-394`) does not prove
both pages are selectable. The hidden `settings[qr_code_generated]` value is a
saved URL snapshot, not an attachment ID, PNG file or server-rendered image.

In `assets/js/admin/builder/modules/settings-qr-code.min.js`, only successful
`onGenerate()` writes the snapshot. `onDestinationChange()` and
`refreshStale()` retain it and mark the preview stale. A form can therefore
legitimately select Page B while keeping the last-generated URL for Page A.
Switching between Page and URL also retains inactive input values; switching
to None discards the generated code. The normal server save filter
(`QrCode.php:403-461`) sanitizes the submitted values and clears page/logo
IDs, URL and snapshot only when destination is None.

The save is the **whole Builder form**, not six independent option writes.
`includes/admin/ajax-actions.php:19-124,219-265` validates the emitted builder
nonce and edit capability, prepares the serialized name/value list,
sanitizes fields, runs field filters, replaces tag labels, calls the normal
form handler, fires save hooks and assigns term relationships. The handler
may also create revisions. A direct `Form_Handler::update()`, copied writer
or fabricated six-key POST cannot prove this native path.

Lite's logo control is fixed to `wpforms`:
`src/Admin/Education/Builder/QrCode.php:102-141` renders the upgrade-only
control; `Settings/QrCode.php:43-45,119-122` requires a paid license for logo
choice. Keep logo ID 0 and do not manufacture custom/no-logo UI authoring.
The declared logo reference and possible downgraded residue need a separate
provenance/ownership review before readiness, not silent normalization in
this destination slice.

## Mechanism and exploratory checkpoints

`tests/offline/regress_native_bodies.php` now models two explicitly synthetic
stale Page/URL states from the retained native body shape. It drives the
shipped policy, PostCapture and BodyRefGrammar with distinct source/target
IDs. The complete body retains the old generated URL, newly selected page
identity, inactive input and an ordinary numeric-looking query label; only
declared identity/URL rebinding occurs. Recapture must be byte-identical and
diagnostic-free. This proves the body codec, not full Apply, a new complete
compiler comparison or browser generation. Existing native fixture bytes
are unchanged.

The later [browser reconnaissance](native-qr-destination-recon.md) retains
five complete SQL body postimages in `fixtures/native-builder-qr-authoring.json`.
The same product-codec harness now checks those measured transitions, complete
non-QR preservation, nested theme JSON, native ID types and divergent-ID
recapture: **155 assertions** total. The archive has no complete retained HTTP
exchange and no Apply run; these are offline body fixtures, not a native gate.

The first checked-in `fixtures/qr-destination/browser-save.js` collector now
binds an explicit owned origin/form before an actual Save click. Its read-only
mode retains a separate immediate pre-click DOM baseline; the host admission
equates that complete control list, the post-rich-text-sync `wpformsBeforeSave`
observation and the actual XHR `data`. The complete native success response must
match one `wpformsSaved` event. A 250-ms suffix starts at that event, followed
by bounded body-task draining. This is an explicit observation interval, not
proof that no work can ever run later. Missing bytes, failed lifecycle events,
extra observed requests, tool errors or diagnostics refuse. The deterministic
host-admission suite has **77 assertions**; native execution and delayed-request
timing evidence are still required before this collector can support a gate.

The measured Lite UI submits **five** QR controls. It does not submit
`qr_code_logo_id`; the server supplies the sixth stored key. Its page placeholder
is `''`, and switching to None can leave inactive inputs in the mounted DOM.
`browser-evidence.php` deliberately does not turn that submitted state into a
persisted-body verdict. The complete SQL witness must independently bind
None's stored clearing and all six native postimage keys. A separately observed
DOM baseline is also not reconstructed from stored JSON: doing so would conflate
submitted empty strings with stored integer defaults.

No engine primitive is missing in this modeled domain. Existing structured
body references map the selected page, while ordinary body URL/reference
rebinding maps the independent snapshot. Do not add a provider that derives
one from the other or make a plugin-specific engine branch. The immutable
compiler and full RepositoryConvergence comparison remain the host evidence
boundary; native UI/form/physical observations remain capsule-owned.

## Required native sequence and admission

1. After offline admission controls and the full aggregate pass, allocate
   one fresh owned exact-source, exact-artifact pair with unique ports and
   private evidence outside disposable roots. Use distinct page/form IDs on
   source and target and two distinguishable destination pages. Native
   builder creation/save must establish the complete form baseline.
2. Use an authenticated real browser to open the Builder. Retain complete
   legal controls, selected form identity, native nonce and request context.
   Select Page A, click Generate and wait for the real successful result;
   retain the generated snapshot and the actual browser/render evidence.
3. Select Page B without regeneration, save with the actual complete
   serialized Builder request, and prove the native stale snapshot survives.
   Exercise Custom URL and then None as separate saved transitions. Retain
   submitted inactive inputs rather than assuming they disappear. A stale
   snapshot must not be silently replaced by the current page permalink.
4. Bind the entire physical form row, children/revisions, metadata and term
   relationship inventories before and after authoring. Either use an
   isolated untagged QR form or bind its complete tag payload and identities.
   Explain every native side effect from the locked writer; do not waive all
   non-QR settings, all revisions or unrelated target rows. Preserve complete
   diagnostic and request/response records and retire only the owned session.
5. Run ordinary Capture/Plan/Apply, fresh native Builder consumers, repeated
   Apply and complete source/target/source-repeat compiler comparisons for
   every transition. Prove selected Page B maps to target B while the stale
   Page A URL undergoes only existing declared URL/reference rebinding. An
   HTTP success or empty plan alone is not native correctness.
6. Mutation-test the actual admission for missing/full-row drift, wrong page
   identity, snapshot recomputation, dropped inactive values, wrong scalar
   types, warnings, dead browser/transport and incomplete artifacts. Distinguish
   snapshot/render observations from actual downloadable image evidence;
   only claim encoded/downloaded output when independently inspected.
7. Destroy the owned pair, databases, roots and lease before PASS; retain
   complete evidence. Obtain final Luna/Terra review and run the full merge
   gates before merging. Other readiness families stay explicitly open.

## Quiescence and evidence-capture corrections

Builder GET can initialize `wpforms_builder_opened_date`, and first native
creation can initialize `wpforms_forms_first_created`. Establish both before
the QR-save baseline. A saved text field also materializes theme, notification,
confirmation and provider controls; preserve their complete values, not just
the field roster. Revisions require full row/metadata checks, and a legitimate
insert advances the native dump's `AUTO_INCREMENT` table option. Bind that
exact progression separately from column/index equality.

`DISABLE_WP_CRON` is not a shutdown-hook barrier. The locked bundled Action
Scheduler's
`vendor/woocommerce/action-scheduler/classes/ActionScheduler_QueueRunner.php:96,109-145`
acquires its async-runner option lock before checking for due work. Its sibling
`ActionScheduler_OptionLock.php` uses an opaque token and a 60-second default
expiry. For exactly one
Builder Save, require a captured clock and no due pending actions, unchanged
complete scheduler action/group/claim/log rows, and unchanged options except
possibly that one existing lock row's value. Bind its ID/name/autoload, native
value grammar and expiry window; reject insert/delete or another changed row.
Do not apply this exception to WPrism Apply or observer phases. Collect with
non-admin readers immediately after the one save; another admin read can
renew the lock. No scheduler filter, locked-plugin edit or engine branch is
needed to hide this native effect.

The exploratory browser's configured HAR was absent after close. Retain and
admit actual request **and response bodies** before retiring the session;
configuration, request listings and successful CLI exit codes are not proof
that those bytes exist. Fail closed on missing artifacts, tool error envelopes,
console warnings or page errors. The exploratory tour's two native deprecation
warnings and logout's `Transition was skipped` page error remain recorded;
neither is approved as a blanket diagnostic allowance. Observe the native
tour-skip choice before Builder creation and test the producer's diagnostic
collection/retirement path independently before a final live gate.
