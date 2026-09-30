# Adapter dispositions and reviewed claims

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

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
one, read [the package identity warning](adapter-library.md#editing-a-shipped-manifest-moves-its-identity):
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
[Site-installed adapters and external certification](adapter-trust.md#site-installed-adapters-and-external-certification)
below.
