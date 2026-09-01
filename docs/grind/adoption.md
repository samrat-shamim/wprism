# Progressive adoption — the ten-situation grind

Driver: [`sandbox/tests/grind/grind_adoption.sh`](../../sandbox/tests/grind/grind_adoption.sh).
Substrate: [`sandbox/tests/lib/grind_lib.sh`](../../sandbox/tests/lib/grind_lib.sh)
(shared with the adapter walk). This document is the specification the driver
implements (originally round-3 T7); `make grind-adoption` runs it.

The adapter walk proves that an operator can author and override adapters. This
grind proves the thing every real user does before that: **adopting WPrism
progressively on a site that already exists** — a brochure site, a shop with
orders, a multilingual shop, a builder site — starting with the smallest thing
WPrism can do (`wprism doctor`, a read-only `wprism assess` on the adoption seed) and
adding management one decision at a time, never a big-bang init. Every
situation ends with the full loop where the site allows it (contract →
rehearse → edit → capture → merge → release → verify → recover) or with the
typed refusal that says why not.

Ten situations, each on a fresh `pair.sh reset` of one dedicated pair:

| # | situation | what it proves / where it stops |
|---|---|---|
| A1 | brochure site: classic theme, core only, pages + menu + widget | the smallest honest loop; every readiness `Ready / Platform-certified` |
| A2 | block theme (FSE): a customised template part | the FSE profile end to end; site-editor customisations round-trip |
| A3 | shop with live orders | orders `runtime / preserve local`; an order placed on the target after the release checkpoint survives the release and is lost only on the explicit recover — the printed boundary, literally |
| A4 | shop + Yoast + CF7, all certified | three adapters composed through one contract and one release |
| A5 | multilingual shop (WooCommerce + Polylang) | translations travel; the non-public `polylang_mo` type is named, never silent |
| A6 | builder site (Elementor + ACF + block theme) | `elementor_library` opted in as an operator would; design edits round-trip |
| A7 | WPForms Lite on A4's shop: kept unmanaged, then adopted with a site adapter AFTER init | the late-adapter path: certify → pin → capture, no second init |
| A8 | acme-catalog: bundled adapter promoted and certified, then plugin AND adapter change in one code release | code and state evolve together; the re-certified digest is the operator's explicit act |
| A9 | version edges: WooCommerce and Yoast below their windows, upgraded mid-way | refused by name; the loop completes once inside the windows |
| A10 | edge cases on one site: second init, secret-shaped option, plugin deactivated after adoption, theme switch, override installed then removed | each stops or proceeds by its documented rule; the loop still completes |

## Running it

```sh
bash sandbox/tests/grind/grind_adoption.sh --self-check      # helpers vs fixtures, no docker
bash sandbox/tests/grind/grind_adoption.sh --dry-run         # every argv, nothing executed
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) bash sandbox/tests/grind/grind_adoption.sh
ADOPT_SITUATIONS=A1,A3 ADOPT_KEEP=1 … bash sandbox/tests/grind/grind_adoption.sh
```

Knobs: `ADOPT_PAIR` (default `adopt`), `ADOPT_PORT1/2` (9600/9601),
`ADOPT_SITUATIONS`, `ADOPT_KEEP=1`, `ADOPT_KEY_ID`, and the pinned versions
`ADOPT_WOO_VERSION`, `ADOPT_WOO_OLD_VERSION`, `ADOPT_YOAST_VERSION`,
`ADOPT_YOAST_OLD_VERSION`, `ADOPT_CF7_VERSION`, `ADOPT_POLYLANG_VERSION`,
`ADOPT_ELEMENTOR_VERSION`, `ADOPT_ACF_VERSION`, `ADOPT_WPFORMS_VERSION` (all
must be pins in the owning adapter's `evidence/artifacts.lock.json`). From a linked
worktree add `WPRISM_SOURCE_ROOT=$(pwd -P)`. Evidence lands under
`sandbox/tmp/grind-adoption.*/evidence/<situation>/`; the exact PASS string is
`✔ GRIND_ADOPTION PASSED (<situations>)`.

## Rounds

Round 1 builds and runs A1–A5, fixing product gaps as they are found; round 2
adds A6–A10; round 3 reruns all ten on the final certified tip as the
acceptance. Each product gap a situation finds is fixed at the root with
offline regression coverage, then re-certified (core + the pinned subjects)
before the next live run.

## Findings log

Kept as the grind runs; each entry names the situation, the stop, and the fix.

- A1 (first look): `wprism doctor` before a site repository exists reports a
  `[FAIL] repo path has site.wprism.json` row — honest, so the grind seeds the
  adoption repository first, then runs doctor and assess.
- A1 (first look): `wprism assess` on an adoption seed refused
  `adapter_observation_pending_unreadable` on every fresh site — the strict
  pending observer read the journal table nothing had created yet. Fixed:
  `Pending::journal_installed()` proves presence with a clean `SHOW TABLES`;
  an absent journal is a known state and the queue is the live gate walk's
  findings (regress_adapter_observation).
- A1 (release): the lib's verify assertion required two declared journeys
  (the walk's shop pair); a brochure site declares one — relaxed to at least
  one.
- A2 (init): a block theme's site-editor customisations live in core's
  non-public `_builtin` FSE types, which the scope gate never names; init now
  proposes the certified core FSE profile's scope for a block theme and says
  so (`[fse_profile_scope_selected]`), or names the gap
  (`fse_profile_not_certified`).
- A6 (anticipated from A2/S1): every plugin-registered rowful type outside
  the proposed scope that no selected adapter declares is left local (runtime)
  and printed — for adapter-owned plugins too (Elementor's `elementor_library`
  is a type the adapter deliberately leaves to the site).
- A3 (first look): on an adoption seed the seed's pin set is `core` alone, so
  the first `wprism assess` read `plugin:woocommerce — install adapter` and every
  WooCommerce table as unclassified on a shop the library certifies. The
  assessment now projects a seed against the policy `wprism init` would propose
  and says so (`adoption:` line; `authority.adoption` in JSON).
- All grinds: `tools/reference-env-provider.php` (reusable preview slot,
  merged during T6) requires every configured environment to use pair.sh's
  canonical logical name — the `preview` alias for side 2 is gone from
  `grind_mup.sh`, the walk and this grind; side 2 is `<pair>2` for rehearsal
  and release alike.
- A3 (loop, historical): this run predated guarded Woo deletion, so a product
  deleted from the target made capture refuse. The current adapter admits
  standalone-product tombstones only under its all-executable-owner, signed
  writer-exclusion, locked reverse-reference, and deletion-only cleanup contract;
  variation tombstones still refuse. The grind's old refusal remains evidence of the
  earlier supported-subset decision, not the current capability.
- A4 (first look, host side): `wprism assess`'s per-operation `wp wprism
  capabilities` reads answered the seed's core-only pin set while the
  inventory had been projected against the init proposal, so the catalog
  joined preview surfaces to missing claims (`missing_registry_entry`). The
  assess composition now passes `--adoption-preview`, and the agent answers
  those reads against the same policy.
- A4 (init): Yoast SEO ships a JOSE bundle whose format check carries the
  bare string `-----BEGIN PRIVATE KEY-----` and an OIDC software statement
  (a complete, public JWT) as a PHP constant; init refused
  `credential_bearing_code_file` twice on every Yoast site. A private key is
  now the PEM marker followed by key material, and a JWT inside shipped code
  is an advisory (`jwt_in_code_file`, named and redacted), never a blocker;
  private keys, cloud/API tokens and environment-owned config files stay
  blocking.
- A4 (init): Contact Form 7 declares `wpcf7_contact_form: {}` and Polylang
  its four taxonomies structurally (no class — which the policy reads as
  authored, site opts in); init proposed neither and left neither local (a
  declared type is not "unmanaged"), so its own baseline capture refused
  `incomplete_policy_scope`. Init now proposes structural declarations into
  scope like explicit authored ones (`InitPlanner::adapter_scope`).
- A4 (init compensation): a failed confirmation on an adoption seed restores
  the seed's bytes, then re-enters recovery to prove the rollback — which
  refused "preserved a replacement site.wprism.json instead of deleting external
  bytes" because the proof pass only recognised the no-prior-version shape,
  turning every failed init on a seed into a retained journal and lock.
  `owned_file_already_compensated()` now recognises the restored prior
  version.
- Debugging aid: `ADOPT_INIT_HUMAN=1` runs the agent's init proposal and
  confirmation in human mode — the host's JSON-mode init redacts the
  confirmation's primary sentence to `init refused at an unclassified safety
  gate` and writes it nowhere else.
- A4 (release): Yoast SEO 28.x creates `wp_yoast_expiring_store` (a TTL
  key/value cache) that `adapter-packages/yoast/package/manifest.json` did not
  declare; the release
  refused `release_surface_not_releasable` for the unclassified table.
  Declared `runtime` (an adapter gap fixed at the root, like WooCommerce's
  operational post types in T6).
- A5 (rehearse): `wprism rehearse` materializes the preview through
  refresh-export under the isolated control bootstrap, where no plugin is
  loaded; Polylang's four taxonomies were in scope (init proposes them now)
  but `adapter-packages/polylang/package/manifest.json` declared no static
  `object_type` for them
  (only the additive option-derived one), so the export refused. The manifest
  now declares the plugin's own registration object types (verified live:
  language/post_translations on post, page, wp_block; term_language/
  term_translations on term).
- A6 (init): Elementor's kit lives in the non-public `elementor_library`
  post type and `elementor_active_kit` is a `ref:post` into it; init left the
  type local (no adapter declared it) and its own baseline capture then
  refused `unresolved_option_reference_scope`.
  `adapter-packages/elementor/package/manifest.json` now
  declares `elementor_library` structurally, so init proposes it into scope.

- A6 (rehearse, sandbox tooling): the rehearsal target's `wprism apply` failed
  its required `provider:elementor-css/regenerate_css` action with
  `file_put_contents(…/uploads/elementor/css/post-1.css): Permission denied`.
  The reference provider publishes the immutable media snapshot 0555 and
  host-owned, and `docker cp` carried owner and mode into the target whose
  runtime is 33:33 — the site could not write inside its own uploads after
  every restore (Woo/Yoast/CF7 never write there during apply, so A1–A5 never
  noticed). `tools/reference-env-provider.php` now hands the restored tree
  back (`chown -R 33:33`, `chmod -R u+rwX,go+rX`) after each restore; the
  restore stays bound to `media_sha256`, a byte/tree digest. Product code
  untouched; the redacted JSON `apply_failed` was read in human mode on the
  kept pair.
- A6 (rehearse, product): after the uploads-permission fix, the rehearsal
  still failed post-apply convergence — `apply_failed` wrapping a
  `wp_navigation` canonical hash mismatch. A target materialized from the
  source's snapshot holds the source's LITERAL `home`/uploads URLs in both
  its database and its restored ledger, so plan read every `{{home}}`-bearing
  entity as drift ("env ahead, untouched") and the promotion apply, which
  includes that drift, wrote content that then did not converge under the
  target's own binding. Root cause: nothing told apply that the restored
  bytes belong to the source's URL binding, not the target's. Fix: the
  materializer reads the source's exact `home`+uploads binding while the
  source is frozen (`EnvironmentLifecycle::readSourceBinding`) and threads it
  to the target apply as `--rebind-from-home`/`--rebind-from-uploads`; plan
  observes the target once more AS that binding and converges a foreign-bound
  entity that equals the repository or the base as an `update` marked
  `rebind`, never as target authorship (agent `ApplyPlanner::rebind_binding` /
  `rebind_comparison_hash`, `Tokens` optional binding). After apply the
  content is target-bound, so apply's own writes and its post-apply verifier
  are unchanged. This is why A1–A5 never hit it: their sources and targets
  shared a URL host, or their authored content carried no home-relative URL.
- A6 (tooling, operator evidence): the two A6 failures above were both
  JSON-mode `apply_failed` envelopes with the primary sentence redacted
  (issue #3404) — the rehearsal runs the target's apply in `--format=json`, so
  the operator never had a human-mode run to reread. `agent/src/Command/
  Cli.php` now writes the redacted throwable chain privately under the target
  repository's `.wprism/refusals/` and the host names that place on a redacted
  transport envelope; that is how the `wp_navigation` cause was read without
  guesswork.
- A6 (release, product — Elementor render cache): once the rebind fix let A6
  reach the release, the builder page still failed to show the edited heading.
  WPrism's apply writes `_elementor_data` with raw SQL
  (`ApplyFieldMaterializer::upsert_meta`), so Elementor's own save hooks —
  which normally invalidate its per-document render caches — never fire, and
  the `elementor-css` provider's `flush-css --regenerate` re-renders documents
  (repopulating `_elementor_element_cache`), so the target served its
  PRE-apply rendered HTML for the full cache TTL (~2 min, measured across two
  instrumented runs).
  `adapter-packages/elementor/package/runtime/providers/elementor-css.php` now clears
  `_elementor_element_cache` and `_elementor_page_assets` (both `derived` in
  the manifest) after regenerating CSS, and the receipt proves the count is
  zero — the next front-end render rebuilds them from the applied
  `_elementor_data`. The stale-cache masking, the raw-SQL bypass, and the
  clear-fixes-it behaviour were each reproduced directly on the kept pair
  before the change.
- A6 (grind assertion — pipefail/EPIPE, corrected diagnosis): after the rebind
  and Elementor-cache fixes, the builder-page render assertion still failed
  intermittently. Deep instrumentation (front-end + `_elementor_data` +
  `_elementor_element_cache` sampled per attempt) proved the target ALWAYS
  ended a release with the applied heading rendered (`data=released`,
  `cache=cNEW`, `fe=released`) — the assertion itself was the flake: under
  `set -o pipefail`, `curl -fs <73KB page> | grep -Fq` returns curl's
  EPIPE/SIGPIPE (rc 141) whenever grep matches and closes the pipe before curl
  finishes writing the body, a load-dependent FALSE failure the codebase
  already documents and avoids (sandbox/conformance/checks/elementor.sh,
  checks/fse.sh, spike_a_round_trip.sh). The grind now buffers the page (and
  the ACF read) into a variable before grepping, the same fix those checks
  use. The Elementor-cache provider change stands on its own merit — WPrism's
  apply writes `_elementor_data` with raw SQL, bypassing Elementor's
  cache-invalidation hooks, so explicitly invalidating the derived render
  caches with receipt proof is the honest derived-state contract — it was not
  what the render assertion was tripping on.
- A8 (re-certify an evolved adapter — product, high blast radius): editing an
  already-certified+pinned site adapter (acme-catalog 1.0.0 → 1.1.0) made the
  WHOLE site unusable — `wprism assess` failed with an opaque "unclassified safety
  gate", `manifest-validate` refused `certificate_invalid`, and `wprism adapter
  certify --pin` could not even run its pre-flight. Three distinct hard-fails
  on an edited adapter, all contradicting docs/guides/adapter-authoring.md
  ("an edit moves the digest and the claim drops back to uncertified; re-run
  `wprism adapter certify … --pin`"): (1) the companion certificate binds
  superseded bytes and `AdapterCertification::assertAdapterBinding` hard-threw
  during `Policy::load`; (2) the `site.wprism.json` pin's digest no longer matched
  and `PinResolver::validate_manifest_pins` hard-threw; (3) `manifest-validate`
  scanned `adapters/authorities.json` (the site trust root) as if it were an
  adapter manifest. Fixes: a valid-but-superseded companion now throws a typed
  `SupersededSiteAdapterCertificate` that `AdapterSources::scan` routes to the
  same uncertified state a companion-absent adapter reaches (genuine anomalies
  — bad signature, wrong authority, malformed/misplaced companion — still hard-
  throw); a `source:site` pin whose digest no longer matches on an *uncertified*
  adapter skips the throw (a still-certified adapter with a mistyped pin and
  every non-site pin still refuse); and `manifest-validate` excludes
  `authorities.json` like the real loader already does. The fix is safe — an
  edited/tampered adapter loses its certified grants and release/promote still
  refuse uncertified adapters — and `certify --pin` now canonicalizes,
  re-signs, and re-pins an edited adapter in one step (verified end-to-end:
  edit → assess reads Uncertified → certify --pin → Site-certified). The grind
  re-certifies before validating, modelling the documented edit→certify flow.
- A8 (code release to production — grind flow): once re-certification worked,
  A8's code half (acme-catalog 1.1.0) was exercised end-to-end for the first
  time and `wprism rehearse`'s refresh-export refused: "completed code
  descriptor/revision does not match the requested repository artifact". The
  grind had updated adopt1's LIVE plugin with a raw `sed` (as an operator
  might touch a file) but never recorded the new completed code on WPrism's
  finalized-code ledger, so production still reported 1.0.0 while the repo
  requested 1.1.0 — refresh-export correctly refuses to observe a production
  whose completed code differs from the artifact it would rehearse. Fix
  (grind): edit the repository's code half, then `wprism deploy adopt1`, which
  stages, activates, and finalizes the 1.1.0 code on production (updating the
  ledger descriptor refresh-export reads). Deploy runs AFTER `certify --pin`
  because `wprism compile` requires the site adapter in canonical bytes, which
  certify restores (verified: deploy on a jq-edited, non-canonical adapter
  refuses `compile_failed / must use WPrism canonical JSON bytes`). Product path
  unchanged — the refusal was correct; the grind was modelling a code change
  that never reached WPrism's ledger.
- A8 (classmap currency): the new `SupersededSiteAdapterCertificate` class
  needed `agent/wprism-classmap.php` regenerated (`php tools/classmap-generate.php`)
  — runtime is unaffected (the class loads via the explicit require in
  AdapterSources), but `make release-gate`'s classmap-currency gate flags a
  missing entry.
- A8 (release to a materialize-based target — grind flow): after the code
  deploy worked, `wprism release --plan-only` refused `release_target_not_clean`.
  The grind had reverted adopt2's live acme-catalog to 1.0.0 "so the release
  has the code to deliver", but `wprism rehearse` had already materialized adopt2
  from the source at 1.1.0 — so the revert drifted the target's live code from
  its own 1.1.0 baseline (code_drift + code_revision_stale), which the release
  readiness correctly refuses. A materialize-based release propagates the
  SOURCE's finalized state to the target; it does not deploy over a hand-
  reverted target. Fix (grind): drop the target-side revert — the release
  re-materializes the source's 1.1.0 code and the acme_catalog_banner state
  onto adopt2, and the assertions confirm both landed (verified end-to-end on
  the kept pair: release --yes applied cleanly, adopt2 = 1.1.0 + banner). This
  and the two A8 grind-flow fixes above (deploy to production, capture before
  deploy) are free-zone; the product path was refusing correctly each time.
- A9 (doctor before seed — grind flow): `wprism doctor` fails "repo path has
  site.wprism.json" when the path carries none, and A9 ran doctor before
  seed_repository created it. Reordered to seed the adoption repository first
  (the order doctor_and_first_look already uses); only the plugin VERSIONS are
  out of range in A9, which is what its assess surfaces.
- A9 (WooCommerce attribute-lookup regeneration options — manifest gap):
  after upgrading WooCommerce 10.9.4 → 11.0.0 and running `wp wc update`,
  WooCommerce kicked off its product attribute-lookup table regeneration,
  which writes three transient bookkeeping options
  (woocommerce_attribute_lookup_last_product_id_to_process / _processed_count /
  _regeneration_in_progress). The manifest's attribute_lookup pattern only
  covered the config members (_direct_updates/_enabled/_optimized_updates), so
  those three were unclassified and `wprism init` refused incomplete_state_
  discovery. A4/A5's fresh installs never triggered a regeneration, so they
  never surfaced them. `adapter-packages/woocommerce/package/manifest.json`
  now classes the three as
  runtime (transient regeneration progress the plugin clears when done). The
  init's jwt_in_code_file line was an ADVISORY (the Yoast OIDC software
  statement, correctly non-blocking); the grind's error extraction mislabeled
  the refusal.
- A9 (WooCommerce 11.0.0 feature flags — manifest gap): the same `wp wc update`
  migration also set two 11.0.0 feature toggles the manifest's explicit env
  feature list did not name (woocommerce_feature_point_of_sale_enabled,
  _site_visibility_badge_enabled); added both env (a feature toggle is
  per-environment config). Kept the explicit list rather than a catch-all
  because one feature flag (wc_visual_attribute) is exact-classed authored.
- A10 (second init over an init-owned repo — grind expectation): a re-init is a
  typed stop that names EVERY payload init would have to own — code, media,
  state, and site.wprism.json. The primary reason code is whichever sorts first
  (existing_code_payload, since A10's published stack gives the repo a code/
  half), not existing_configuration as the grind assumed. Assert instead that
  existing_configuration is NAMED among them — init recognising the existing
  adoption is the fact this edge is about. Product path unchanged.
- A10 (theme switch outside the adopted code baseline — documented stop): init's
  code baseline is the ACTIVE code at adoption — active plugins + the active
  theme (verified: code/wp-content carries contact-form-7, woocommerce,
  twentytwentyone, not the inactive-at-init block theme). Switching the live
  theme to one init never captured makes the state name a template
  code/wp-content/themes does not carry, and `wprism capture` refuses
  code_state_mismatch — correctly: a release would deploy only the managed theme
  onto a target whose state says the unmanaged one is active. The edge now
  documents the stop (and that switching back to the adopted theme captures
  cleanly); managing a newly-activated theme is `wprism deploy`'s job. Product path
  unchanged — the earlier grind expectation (capture succeeds) was wrong.
