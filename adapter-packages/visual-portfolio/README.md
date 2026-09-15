# Visual Portfolio

Experimental declarations for the official free **3.8.1** artifact. This capsule
exercises source capture, immutable compilation, codec fixed points and bounded
native settings, storage-migration and post-query Apply lanes. Deploy remains
unclaimed until certified host qualification; this does not authorize production release.

The manifest uses 41 exact block groups and one composed query object for 470
value declarations, plus four explicit unsupported attributes. Separate manifest
providers repair native settings and settle the exact 3.8.1 migration cursor;
there is no interpreter or regenerator.
The native source registry accounts for all 22 block types in its observed context;
registry coverage is not evidence that every control was edited and saved.

The fixtures retain actual settings Saves and the saved modern image gallery and
legacy masonry archive. Source browser evidence includes gallery filtering and
lightbox behavior and ordered archive pagination. The offline tests exercise the
saved bytes, source URL removal, cross-ID codecs, exact recapture, complete compiler
reference graphs, closed settings inventories, privacy refusals and unsupported
attribute presence. Nine additional UI Saves retain default, manual, duplicate-title,
filter/reset and visible/hidden custom-query states, including a deleted selection.
The query object declares exact fields and reuses ordinary reference codecs;
missing modern and legacy selector IDs refuse because dropping the last ID can
broaden the query. Nonempty custom-query text refuses even when its source is hidden.
The exact 3.8.1/WordPress 7.1 editor did not expose its optional Settings menu;
positive exclusion and taxonomy values use explicit REST replay, not picker evidence.

`vp_general.portfolio_archive_page` and `no_image` are post references.
`_vp_post_type_mapped` is derived: independently authored markers leave an old
archive marker outside the new option's outbound scope closure. The settings
provider calls the plugin's public archive, role and deferred **hard** rewrite
helpers through the engine's fresh-process runtime. SDK transactions, complete
physical row witnesses, native getter provenance and an independent fresh observer
verify the derived database projection while preserving unrelated state.
Reading-time metadata uses the
shared `on_post_write: delete` mechanism; view counters remain runtime state.

The settings lane uses a new content-only repository and already installed plugins:
an adoption baseline, scoped archive move/clear, disabling and re-enabling portfolio
content, and terminal replay. It checks divergent native IDs, the old archive outside authored
scope, all nine native dependency tables and retained engine state. Its semantic
offline doubles cover native no-ops, deferred rewrites, unrelated writes and aliases;
they do not establish native injected-failure recovery.

Returning to an earlier artifact uses a new explicit `--request-id` on the direct
scoped Apply. Keep that ID for retries. The settings lane first proves the existing
implicit historical receipt refuses the changed target, then re-enables through a
new explicit request and verifies its exact terminal replay. The engine owns that
request identity; the adapter uses its ordinary provider operation machinery.

This first lane requires one WordPress document root and bounds each dependency
table at 8,192 rows / 16 MiB. Server-file snapshots bind bytes or absence; they do
not prove rewrite-file semantics or provide file rollback. Server files and native
external hooks remain explicitly irreversible effects, blocking automatic promotion.

The migration provider is a separate `lifecycle_settle` action. Its complete
durable write set is the `options`, `posts` and `postmeta` tables covered by the
host database checkpoint, so storage-only deploy can infer it from the existing
`vpf_db_version=3.8.1` prerequisite without another callback declaration. The
provider invokes `Visual_Portfolio_Migrations::init()` inside the SDK transaction,
proves an immediate no-op pass, and exposes only a bounded physical postimage to
the engine's independent fresh observer. The observed migration surface is limited
to 4,096 rows / 4 MiB per options, page/saved-layout plus legacy-option archive
post, and Visual Portfolio postmeta projection. Exceeding any bound refuses before
a successful receipt. A legacy `vp_general.portfolio_slug` takes the plugin's hard
rewrite path, whose server-file and hook effects are not checkpoint-restorable; the
provider refuses that state before native mutation and leaves it to explicit native
maintenance.

Saved Layout authoring, nonempty custom queries, extension sources and modern custom CSS refuse
where declared. Premium settings, including hidden premium fields submitted by the
free popup form, remain target-local. The exact artifact lock retains 3.8.0 as an
explicit stale-cursor exercise fixture. Certified host promotion, adjacent version
transitions, retirement/reactivation and participant-declared combinations
and all twelve production readiness families remain open in
[evidence/production-readiness.json](evidence/production-readiness.json).

Run the capsule's current gate:

```sh
php tools/adapter-package-validate.php --adapter=visual-portfolio
php tools/adapter-package-tests.php --adapter=visual-portfolio
```

The package-owned native workflow uses the shared conformance driver in
`capture-plan` mode. From a clean, committed candidate, select an unused pair and
ports, then run:

```sh
CONF_PAIR=vpcap01 CONF1_PORT=9186 CONF2_PORT=9187 \
  CONF_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
  bash sandbox/conformance/run.sh visual-portfolio
bash sandbox/bin/pair.sh destroy vpcap01
```

This workflow rebases the retained block bodies onto fresh native media IDs,
saves through WordPress REST and plugin source writers, then exercises public
Capture and immutable compilation. It compares complete canonical files, seven
native tables, the block registry and upload hashes across repeated Capture.
It stops before target deploy or Apply.

The separate settings lane owns and destroys its pair automatically:

```sh
VP_SETTINGS_PAIR=vpset01 VP_SETTINGS_PORT1=9186 VP_SETTINGS_PORT2=9187 \
  VP_SETTINGS_ZIP=/absolute/path/to/visual-portfolio.3.8.1.zip \
  WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
  bash adapter-packages/visual-portfolio/tests/live/regress_settings_apply.sh
```

It requires the exact artifact hash in `evidence/artifacts.lock.json`, retains
complete private stdout/stderr/status files, and admits the original command
streams behind the shared diagnostic collector's pointer. An unexpected diagnostic
or process status fails the run. It is API-authored evidence, not another UI Save.

The source fixture hashes and native writer receipt are in
[fixtures/native/provenance.json](fixtures/native/provenance.json). HTTP request
and complete database evidence are retained privately; source discovery is
explicitly marked as separate from adapter qualification.

The bounded query lane uses the same pair ownership and private-command helpers:

```sh
VP_QUERY_PAIR=vpquery02 VP_QUERY_PORT1=9196 VP_QUERY_PORT2=9197 \
  VP_QUERY_ZIP=/absolute/path/to/visual-portfolio.3.8.1.zip \
  WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
  bash adapter-packages/visual-portfolio/tests/live/regress_native_queries.sh
```

It replays retained native bodies through REST, applies onto empty target query
pages with divergent post/term IDs, checks no-op repetition and complete canonical
recapture, and compares seven ordered frontend results. Three public Capture
refusals cover visible and hidden custom text and deletion of the last selected
post, retaining complete native table, identity-map and repository-state witnesses.
This does not qualify target editor Save/reopen, unavailable optional controls,
multi-gallery exclusion, current/page queries or the broader lifecycle matrix.
