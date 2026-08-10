COMPOSE = docker compose -f sandbox/docker-compose.yml

.PHONY: regress-lifecycle-options-snapshot
.PHONY: regress-cli-json-refusals
.PHONY: regress-plan-explain
.PHONY: regress-plan-category-summary
.PHONY: regress-plan-category-summary-live

.PHONY: up down clean setup seed spike-a spike-b spike-c spike-d spike-e spike-f spike-g spikes conformance-% cli-smoke cli-triage-smoke lint-smoke grind-r1c grind-r1a grind-r3a grind-r3b grind-code-half grind-code-half-ecosystem grind-code-half-first-sync grind-ecommerce-developer-live pair-up pair-reset pair-destroy pair-list regress-pa-attributes regress-shipping-zones regress-natural-key-rename certify-merge certify-version-skew-merge certify-adversarial-matrix certify-deletion-matrix certify-version-matrix certify-reference-bundle certify-ssh-adoption-roundtrip certify-ssh-rollback regress-capture-publish regress-code-drift regress-option-subkeys regress-option-reconciliation regress-fatal-mutations-unit regress-fatal-mutations regress-adapter-contract regress-adapter-sources regress-fetch-artifact regress-adapter-theme-range regress-discovery-completeness regress-core-semantics regress-attachment-portability regress-repository-compiler regress-promotion-unit regress-promotion regress-promotion-lock regress-capture-secret-scan regress-order-preserving regress-capture-concurrency regress-menu-item-meta-gate regress-acf-meta-interpreter regress-widgets regress-code-revision-enforcement regress-code-descriptor-unit regress-code-materializer-unit regress-code-completed-unit regress-code-stage-lock-unit regress-code-stage-transaction-unit regress-code-ledger-transaction-unit regress-plan-summary-code-drift regress-plan-title-render regress-conflict-view regress-template-mismatch regress-code-deploy-unit regress-lifecycle-state-handoff regress-lifecycle-phase-handoff-unit regress-plugin-dependency-order regress-rollback-authority regress-recovery-executor regress-checkpoint-bundle regress-code-release code-half-unit \
	regress-adopt-rollback regress-block-refs regress-composite-ref regress-doctor-env-values regress-dynamic-options-policy regress-taxonomy-object-keyspace \
	regress-env-options-policy regress-export-manifest-roundtrip regress-manifest-reclassification-policy regress-ecommerce-developer-matrix \
	regress-menu-field-reclassification-policy regress-regen-dependency-policy regress-shortcode-refs \
	regress-woocommerce-product-lookups regress-woocommerce-product-lookups-fake regress-woocommerce-deletion-authority \
	regress-woocommerce-regen-engine regress-action-scope regress-provider-contract regress-actions-providers regress-provider-contract-live regress-ecommerce-developer-static regress-ecommerce-extension-migration regress-capture-atomicity \
	regress-term-meta regress-url-query-refs regress-acf-term-options-fields regress-collision \
	regress-entity-type-width regress-env-set regress-option-ref-scope regress-pmpro-composite-ref \
	regress-repository-authorization regress-repository-compiler-integration regress-scope-gate \
	regress-snapshot-meta regress-generic-reference-shapes regress-ssh-adopt regress-tec-regen regress-user-meta \
	regress-option-name-refs-wiring regress-offline-all regress-live-list regress-code-compatibility regress-upload-bundle \
	regress-effect-bundle regress-woocommerce-effect-contract regress-ssh-rollback-certification \
	regress-coverage-offline regress-coverage regress-classification-batch \
	regress-refresh-orchestration \
	regress-refresh-compile-refs \
	regress-refresh-rebase \
	regress-environment-driver \
	regress-environment-lifecycle \
	regress-environment-materializer \
	regress-environment-materializer-ssh \
	regress-environment-materializer-recovery \
	regress-environment-materializer-live \
	regress-frozen-materialization-promotion \
	regress-woo-attribute-deletion regress-bundle-coverage regress-certification-bundle \
	regress-multisite-refusal regress-journal-bootstrap regress-pair-bootstrap-unit regress-manifest-dispositions regress-site-adapter-certification \
	regress-post-field-classification regress-capability-registry regress-woocommerce-contract regress-duo3316-contract \
	regress-refresh-export-unit regress-vocabulary-ownership regress-parent-scoped-natural-key regress-close-gate-parent-count \
	regress-pair-candidate-source regress-manifest-validate regress-scope-closure regress-certbundle-lock regress-adapter-catalog regress-scope-contract regress-conformance-asserts regress-plugin-adapter-source \
	capability-registry-generate release-gate

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

clean:
	$(COMPOSE) down -v
	rm -rf sandbox/siterepo sandbox/tmp

setup:
	bash sandbox/setup.sh

spike-a:
	bash sandbox/tests/spike_a_round_trip.sh

spike-b:
	bash sandbox/tests/spike_b_merge.sh

spike-c:
	bash sandbox/tests/spike_c_provenance.sh

spike-d:
	bash sandbox/tests/spike_d_woo.sh

spike-e:
	bash sandbox/tests/spike_e_acf.sh

spike-f:
	bash sandbox/tests/spike_f_core_loop.sh

spike-g:
	bash sandbox/tests/spike_g_code.sh

spikes: spike-a spike-b spike-c spike-d spike-e spike-f spike-g

conformance-%:
	bash sandbox/conformance/run.sh $*

cli-smoke:
	bash sandbox/tests/cli_smoke.sh

cli-triage-smoke:
	bash sandbox/tests/cli_triage_smoke.sh

lint-smoke:
	bash sandbox/tests/lint_smoke.sh

# Grind round R1-C (task #48): the agency stack — Elementor + ACF active
# together, plus a custom CPT plugin dogfooded through code/ — tested for
# INTERPLAY. Own pair (r1c1 :8818 / r1c2 :8819, profile r1c). See
# docs/grind/r1c-agency.md for the full report.
grind-r1c:
	bash sandbox/tests/grind_r1c_agency.sh

# Grind round R1-A (task #46): a forms-driven business site — Contact Form 7
# + Ninja Forms on twentytwentyone. Own pair (r1a1 :8814 / r1a2 :8815,
# profile r1a). See docs/grind/r1a-forms.md for the full report.
grind-r1a:
	bash sandbox/tests/grind_r1a_forms.sh

# Grind round R1-B (task #47): a full WooCommerce shop — Storefront theme,
# VARIABLE products with GLOBAL attributes (pa_* dynamic taxonomies),
# shipping zones, tax rates, a grouped product. Own pair (r1b1 :8816 /
# r1b2 :8817, profile r1b). See docs/grind/r1b-shop.md for the full report.
grind-r1b:
	bash sandbox/tests/grind_r1b_shop.sh

# DUO-3337: the full ecommerce developer proof is live-only and deliberately
# outside regress-offline-all.  Require an explicit disposable pair and free
# ports so an accidental `make grind-ecommerce-developer-live` cannot consume
# the shared Docker/MariaDB budget or collide with another agent.
#
#   make grind-ecommerce-developer-live \
#     ECOMMERCE_PAIR=ecom3337 PORT1=9100 PORT2=9101
grind-ecommerce-developer-live:
	@test -n "$(ECOMMERCE_PAIR)" || { echo 'ECOMMERCE_PAIR is required; use a unique disposable pair name' >&2; exit 2; }
	@test -n "$(PORT1)" || { echo 'PORT1 is required; choose a free host port' >&2; exit 2; }
	@test -n "$(PORT2)" || { echo 'PORT2 is required; choose a free host port' >&2; exit 2; }
	ECOMMERCE_PAIR="$(ECOMMERCE_PAIR)" ECOMMERCE_PORT1="$(PORT1)" ECOMMERCE_PORT2="$(PORT2)" bash sandbox/tests/grind_ecommerce_developer.sh

# Sandbox redesign (task #74): one parameterized pair (sandbox/pair.yml)
# against one shared MariaDB (sandbox/db.yml), driven by sandbox/bin/pair.sh
# — additive to, and independent of, every target above (which all still
# operate the legacy sandbox/docker-compose.yml mega-file untouched this
# round). See docs/sandbox.md for the full model.
#
#   make pair-up NAME=sbx1 PORT1=8830 PORT2=8831 [FLAGS="--journal"]
#   make pair-reset NAME=sbx1
#   make pair-destroy NAME=sbx1
#   make pair-list
pair-up:
	bash sandbox/bin/pair.sh up $(NAME) $(PORT1) $(PORT2) $(FLAGS)

pair-reset:
	bash sandbox/bin/pair.sh reset $(NAME)

pair-destroy:
	bash sandbox/bin/pair.sh destroy $(NAME)

pair-list:
	bash sandbox/bin/pair.sh list

# Grind round R3-B (task #91): an events + memberships site — The Events
# Calendar + Paid Memberships Pro — stress-testing the typed-snapshot
# custom-table grammar (task #75) against schemas it wasn't designed
# around. Own sandbox/bin/pair.sh pair (r3b1 :8852 / r3b2 :8853, journal
# on) — NOT the legacy sandbox/docker-compose.yml. See
# docs/grind/r3b-events-memberships.md for the full report.
grind-r3b:
	bash sandbox/tests/grind_r3b_events.sh

# Grind round R3-A (task #90): a multilingual WooCommerce shop — Polylang
# (free) + WooCommerce + Storefront, two languages (en/de), translated
# pages/category/pa_color terms/one product, per-language menus, an
# UNTRANSLATED variable product (pa_size x pa_color, 4 variations). Own
# sandbox/bin/pair.sh pair (r3a1 :8850 / r3a2 :8851) — NOT the legacy
# sandbox/docker-compose.yml. See docs/grind/r3a-multilingual-shop.md for
# the full report.
grind-r3a:
	bash sandbox/tests/grind_r3a_multilingual.sh

# Engine tasks #92/#93 (taxonomy_patterns + shipping-zone stack /
# option_name_refs): regressions against the r3e pair (sandbox/bin/pair.sh,
# 8854/8855) — see docs/grind's r3-eng-woo report for the full live
# acceptance narrative these regressions guard on an ongoing basis.
regress-pa-attributes:
	bash sandbox/tests/regress_pa_attributes.sh

regress-woo-attribute-deletion:
	bash sandbox/tests/regress_woo_attribute_deletion.sh

regress-shipping-zones:
	bash sandbox/tests/regress_shipping_zones.sh

regress-natural-key-rename:
	php sandbox/tests/regress_natural_key_rename.php

# Certify merge (DUO-3228): permanentizes spike_b_merge.sh's divergent-edit
# + conflict + resolve + converge flow as a re-runnable LOCAL regression
# fixture (own sandbox/bin/pair.sh pair, "mergecert" 8860/8861, headless) —
# extended with a typed-snapshot table-entity conflict
# (woocommerce_attribute_taxonomies) and a negative test against the real
# repository semantic compiler's conflict_marker diagnostic (DUO-3208).
# Gate is local evidence, not CI, per commit 1efb6df (the conformance CI
# workflow is disabled by owner decision). See sandbox/tests/
# certify_merge.sh's header for full scope: what's proven here vs.
# explicitly routed elsewhere (add/add same-slug rejection is unblocked now
# that DUO-3208 landed, but stays out of this fixture per the issue's own
# routing to DUO-3223's matrix). A companion fixture certifies DESIGN.md
# §3.4's code-first -> migrate -> re-capture -> merge-state ordering.
certify-merge:
	bash sandbox/tests/certify_merge.sh
	bash sandbox/tests/certify_version_skew_merge.sh

certify-version-skew-merge:
	bash sandbox/tests/certify_version_skew_merge.sh

# DUO-3223 (adversarial certification matrix): cases that don't belong to
# any single capability's own certification fixture. PART 1 (add/add
# same-slug -> RepositoryCompiler's duplicate_natural_identity) was routed
# here explicitly by certify_merge.sh/DUO-3228's own scope note. PART 2
# (restored-target disaster recovery: fail closed on lost ledger history,
# then identity-export/identity-import to a clean, byte-identical
# continuation) proves DUO-3223's own "fresh/mapped/restored target" axis.
# See the script's own header for full detail.
certify-adversarial-matrix:
	bash sandbox/tests/certify_adversarial_matrix.sh

# DUO-3223 slice 4 (--with-deletes scenarios): the three manifests with real
# deletion boundaries on plugin-owned typed-snapshot tables, beyond core's
# own (checks/core.sh). WooCommerce and Ninja Forms prove explicit fail-closed
# parent boundaries, Ninja Forms also proves its independently safe child-row
# cascades, and Paid Memberships Pro proves an empty-guards composite_ref
# delete. See the script's own header for the exact contracts.
certify-deletion-matrix:
	bash sandbox/tests/certify_deletion_matrix.sh

# DUO-3223's own last remaining piece (version-boundary matrix), unblocked
# by the owner ruling on artifact sourcing (issue comment 0ec1d2e3): installs
# ACF from a sha256-verified wp.org artifact (never a bare slug, never
# "latest") at BOTH its manifest-declared version_range boundaries, proving
# the range is backed by real evidence at its own edges, not just whatever
# version every other fixture happens to have installed. First real plugin
# only -- see the script's own header for why, and DUO-3223 for the
# remaining 6 pinned manifests as their own next slice.
certify-version-matrix:
	bash sandbox/tests/certify_version_matrix.sh

certify-reference-bundle:
	bash sandbox/tests/certify_reference_bundle.sh

# DUO-3213 (atomic capture publication): offline, no docker -- exercises
# agent/src/Publish.php's capture lock, staging dir, atomic swap, and crash
# recovery directly, including a real SIGKILL of a child process mid-
# publish. See the script's own header for what is/isn't covered here vs.
# by live sandbox evidence (the InnoDB engine check + real transaction
# retry need a live MySQL and aren't repeated in this offline target).
regress-capture-publish:
	bash sandbox/tests/regress_capture_publish.sh

# DUO-3231: code_drift detection (Deploy::code_drift(), a narrower question
# than code_mismatch — did an installed plugin/theme version change since
# the last successful 'duo deploy'/'duo capture', regardless of whether the
# new version is still within a pinned version_range) + the advisory
# DISALLOW_FILE_MODS `duo doctor` check. Own pair.sh pair ("codedrift"
# 8862/8863, headless). Uses WordPress core's own bundled Hello Dolly
# plugin — zero network installs.
regress-code-drift:
	bash sandbox/tests/regress_code_drift.sh

# DUO-3233 (sub-key option classification): a fresh, from-scratch pair
# (sandbox/bin/pair.sh, asub3233 8910/8911) — Polylang's `post_types`/
# `taxonomies`/`nav_menus` and Yoast's `disableadvanced_meta` sub-keys of
# their respective env-classified option blobs now capture/apply
# independently, merging into the live blob without clobbering excluded
# sibling keys. See manifests/polylang.json's and manifests/yoast.json's
# own notes for the full empirical trail.
regress-option-subkeys:
	bash sandbox/tests/regress_option_subkeys.sh

# DUO-3211: explicit absent/present/deleted option records, exact autoload,
# conflict/delete safety, database assertions, recapture, and retry.
regress-option-reconciliation:
	bash sandbox/tests/regress_option_reconciliation.sh

regress-discovery-completeness:
	bash sandbox/tests/regress_discovery_completeness.sh

regress-core-semantics:
	bash sandbox/tests/regress_core_semantics.sh

regress-attachment-portability:
	bash sandbox/tests/regress_attachment_portability.sh

# DUO-3206: offline wpdb return semantics plus a live, isolated failure-
# injection matrix for insert/update/delete/transactions/rebuild-actions/ledger.
regress-fatal-mutations-unit:
	bash sandbox/tests/regress_fatal_mutations_unit.sh

regress-fatal-mutations:
	bash sandbox/tests/regress_fatal_mutations.sh

# DUO-3222 (version-pinned adapter compatibility contract): Policy::load()'s
# new validators, Policy::theme_ranges(), and RepositoryCompiler's
# per-manifest digest/resolved_adapters() — pure PHP, offline, no docker
# (same idiom as regress-capture-publish above). See the script's own
# header for exactly what is/isn't covered here vs. the live theme-range
# leg below.
regress-adapter-contract:
	bash sandbox/tests/regress_adapter_contract.sh

# DUO-3223/recertification: pinned artifact downloads retry transient curl
# failures at most three times, while digest mismatches and exhausted
# failures remain fail-closed with only the partial temp file removed.
regress-fetch-artifact:
	bash sandbox/tests/regress_fetch_artifact.sh

regress-certification-bundle:
	bash sandbox/tests/regress_certification_bundle.sh

regress-manifest-dispositions:
	bash sandbox/tests/regress_manifest_dispositions.sh

regress-capability-registry:
	bash sandbox/tests/regress_capability_registry.sh

regress-adapter-sources:
	bash sandbox/tests/regress_adapter_sources.sh

regress-site-adapter-certification:
	bash sandbox/tests/regress_site_adapter_certification.sh

capability-registry-generate:
	php scripts/capability-registry.php generate

# Product release gate: current content-addressed evidence, exact generated
# registry, and every generated public compatibility claim must agree.
release-gate:
	php scripts/capability-registry.php check

regress-multisite-refusal:
	bash sandbox/tests/regress_multisite_refusal.sh

# DUO-3262: optional term/user interpreter hooks plus static-policy fallback;
# pure PHP fixture manifests/interpreters, no WordPress or docker.
regress-interpreter-policy:
	bash sandbox/tests/regress_interpreter_policy.sh

# DUO-3263: real Acf interpreter term_meta_rule()/option_rule() classification
# (term-attached fields reuse post_meta_rule()'s shadow-key machinery
# unchanged; options-page fields use the options_/_options_ prefix
# convention empirically confirmed against fresh ACF 6.8.7 free), plus one
# end-to-end pass through the real manifests/acf.json + Policy dispatch/
# ownership wiring. Pure PHP, no WordPress or docker.
regress-acf-meta-interpreter:
	bash sandbox/tests/regress_acf_meta_interpreter.sh

# DUO-3222's one genuinely live leg: Deploy::code_mismatch()'s new THEME
# version_range check, called directly against a real bundled WordPress
# theme (twentytwentyfour, zero network installs) via `wp eval` — no
# site-repo/plan/apply pipeline needed, since code_mismatch() is a plain
# static function. Own sandbox/bin/pair.sh pair (asub3222tr 8918/8919).
regress-adapter-theme-range:
	bash sandbox/tests/regress_adapter_theme_range.sh

# DUO-3266/DUO-3275: menu-item meta capture used to read a fixed 8-key
# allowlist and silently drop everything else, never reaching the
# unclassified-meta gate ordinary post_meta already has. Live, own pair
# (asub3275 8954/8955): runs the FULL canonical loud-gate -> pending ->
# classify -> clean-capture cycle (not just capture-time refusal) — a fake
# mega-menu plugin's meta key refuses capture, surfaces in `wp duo pending`
# under section=post_meta with nav_menu_item in post_types (DUO-3275: not a
# dead-end 'menu_item_meta' section), classifies via the exact `wp duo
# classify --set` syntax pending suggests, then captures/applies/round-trips
# across two independent environments with real token resolution.
regress-menu-item-meta-gate:
	bash sandbox/tests/regress_menu_item_meta_gate.sh

regress-widgets:
	bash sandbox/tests/regress_widgets.sh

# DUO-3216: offline host-orchestrator state-machine contract — one compiled
# artifact, pre-checkpoint lease, retire -> activate -> apply ordering, stop-on-first-
# failure, exact cleanup, and serialized transport-shaped restore instructions.
regress-promotion-unit:
	bash sandbox/tests/regress_promotion_unit.sh

# Clean-install lifecycle snapshot boundary: captures options/core without
# entering plugin-owned typed-table validation before activation has created
# those tables. Kept in code-half-unit because this is the host lifecycle
# bridge, not a WooCommerce lookup/ecommerce harness.
regress-lifecycle-options-snapshot:
	php sandbox/tests/regress_lifecycle_options_snapshot.php

# Fatal-safe control-plane bootstrap: DUO_JOURNAL must not call WordPress
# option/filter APIs before after_wp_config_load has loaded the normal runtime.
regress-journal-bootstrap:
	php sandbox/tests/regress_journal_bootstrap.php

# First functional code-half's fast, offline boundary suite: descriptor
# revision enforcement, truthful status rendering, template reconciliation
# detection, and both public host orchestration paths. Keep this separate
# from the Docker/live promotion regression below so it is cheap to run while
# iterating on the safety gates.
code-half-unit: regress-repository-compiler regress-code-revision-enforcement regress-code-descriptor-unit regress-code-materializer-unit regress-code-completed-unit regress-code-stage-lock-unit regress-code-stage-transaction-unit regress-code-ledger-transaction-unit regress-plan-summary-code-drift regress-template-mismatch regress-code-deploy-unit regress-promotion-unit regress-lifecycle-state-handoff regress-lifecycle-phase-handoff-unit regress-plugin-dependency-order regress-lifecycle-options-snapshot regress-journal-bootstrap regress-code-compatibility

regress-repository-compiler:
	bash sandbox/tests/regress_repository_compiler.sh

# DUO-3316: generalized taxonomy object keyspaces, full description
# json_refs/key_refs, attached structured-meta sidecar variants/two-sidecar
# refusal, load-time refusal matrices, frozen-policy parity, and compiler
# raw-id portability gates.
regress-duo3316-contract:
	bash sandbox/tests/regress_duo3316_contract.sh

regress-coverage-offline:
	php sandbox/tests/regress_coverage_offline.php

# Live: needs an already-up pair with WooCommerce active, e.g.
#   DUO_PAIR=mypair make regress-coverage
regress-coverage:
	bash sandbox/tests/regress_coverage.sh

regress-post-field-classification:
	php sandbox/tests/regress_post_field_classification.php

regress-ecommerce-developer-static:
	bash sandbox/tests/regress_ecommerce_developer_static.sh

regress-ecommerce-developer-matrix:
	bash sandbox/tests/regress_ecommerce_developer_matrix.sh

regress-ecommerce-extension-migration:
	php sandbox/tests/regress_ecommerce_extension_migration.php

regress-capture-atomicity:
	php sandbox/tests/regress_capture_atomicity.php

regress-action-scope:
	php sandbox/tests/regress_action_scope.php

regress-provider-contract:
	php sandbox/tests/regress_provider_contract.php

# DUO-3338: the structured native-action vocabulary and the plugin-owned
# provider contract. One target, two harnesses (see the wrapper's header):
# the load-time half never stubs a WordPress function, the runtime half stubs
# exactly the lifecycle primitives negotiation reads. regress-provider-contract
# above stays addressable on its own for iterating on that half alone; the
# bundle entry is this wrapper, so neither harness runs twice.
regress-actions-providers:
	bash sandbox/tests/regress_actions_providers.sh

# DUO-3338 live counterpart: a custom sandbox plugin advertising its OWN
# provider through the `duo_providers` filter, negotiated and invoked against a
# real target. Own pair, so it is live-list material, never offline-all.
regress-provider-contract-live:
	bash sandbox/tests/regress_provider_contract_live.sh

# DUO-3318: ownership of the engine's closed manifest vocabularies, the safe
# extension points around them, and the parent-scoped multi-column natural key
# they were written down for. A second adapter is the fixture: every negative
# is a well-formed manifest reaching into another manifest's entities or
# minting a value the engine owns.
regress-vocabulary-ownership:
	bash sandbox/tests/regress_vocabulary_ownership.sh

# DUO-3374: the close gate's squash-parent count, header-scoped — proven
# against scratch commits including the message-body shape that false-failed
# a live close (see the suite's own header).
regress-close-gate-parent-count:
	bash sandbox/tests/regress_close_gate_parent_count.sh

# DUO-3327: `duo manifest-validate`, the adapter author's offline grammar check,
# and the machine-readable grammar document it emits from the engine's own
# closed vocabularies. Drives the real host CLI as a subprocess against scratch
# manifest fixtures (shared with the DUO-3318 ownership suite), against scratch
# site repos (the two guards that read site.duo.json as input, asserted both
# ways), and against the shipped manifests/ directory with and without --site.
# Authoring aid, not a gate on anything.
regress-manifest-validate:
	bash sandbox/tests/regress_manifest_validate.sh

# DUO-3382: the certification bundle's own per-host flock(2) — real racing
# processes against a private rendezvous, so the mutual exclusion, the named
# refusal, bounded waiting, and kernel reclaim of a killed holder are proven
# on both lock backends without docker or a 50-minute bundle run.
regress-certbundle-lock:
	bash sandbox/tests/regress_certbundle_lock.sh

# DUO-3408: the shared conformance assertion fragment -- every require_*
# helper the seeds/postdeploy hooks call must be defined in ONE fragment both
# sourcing harnesses load, or bundle leg 12 dies at `command not found`.
# Offline: pure grep over the harness sources.
regress-conformance-asserts:
	bash sandbox/tests/regress_conformance_asserts.sh

# DUO-3339: the installed-adapter catalog -- `duo adapter list|inspect|doctor`
# over both adapter sources the engine has, plus AdapterSources::survey(), the
# reporting half of the source scan. Drives the real host CLI as a subprocess
# against the REAL shipped library and scratch site repositories, and pins the
# architectural claim of the split by comparing each reported refusal against
# the message AdapterSources::discover() throws for the same fixture, byte for
# byte. Offline: file I/O and pure PHP only.
regress-adapter-catalog:
	bash sandbox/tests/regress_adapter_catalog.sh

# DUO-3339 slice B2: the third adapter source -- <plugin-dir>/duo-adapter.json,
# bundled by an ACTIVE plugin. Proves the two decisions that went AGAINST the
# obvious implementation: precedence (shipped > site > plugin) reports a
# plugin-side name collision instead of refusing the scan, and every
# plugin-source condition is refused per-adapter rather than whole-directory,
# becoming fatal only when a pin names it. WP_PLUGIN_DIR is a define(), so each
# fixture runs in a clean PHP child. Offline: file I/O and pure PHP only.
regress-plugin-adapter-source:
	bash sandbox/tests/regress_plugin_adapter_source.sh

# DUO-3318 live counterpart: the parent-scoped natural key through capture,
# deploy, apply, rename, and independent recapture across two environments
# whose local ids genuinely differ. Own pair, so it is live-list material,
# never offline-all.
regress-parent-scoped-natural-key:
	bash sandbox/tests/regress_parent_scoped_natural_key.sh

regress-code-revision-enforcement:
	php sandbox/tests/regress_code_revision_enforcement.php

regress-code-descriptor-unit:
	bash sandbox/tests/regress_code_descriptor_unit.sh

regress-code-materializer-unit:
	bash sandbox/tests/regress_code_materializer_unit.sh

regress-code-completed-unit:
	bash sandbox/tests/regress_code_completed_unit.sh

regress-code-stage-lock-unit:
	bash sandbox/tests/regress_code_stage_lock_unit.sh

regress-code-stage-transaction-unit:
	bash sandbox/tests/regress_code_stage_transaction_unit.sh

regress-code-ledger-transaction-unit:
	bash sandbox/tests/regress_code_ledger_transaction_unit.sh

regress-plan-summary-code-drift:
	php sandbox/tests/regress_plan_summary_code_drift.php

# DUO-3345 (plan naming slice): offline, no docker — plan rows carrying an
# authored WordPress name (post title, term/menu name) render it beside the
# repository path in duo status; rows without one render exactly as before.
# The agent-side twin renderer is proven live by the core conformance
# check's plan-naming scenario.
regress-plan-title-render:
	php sandbox/tests/regress_plan_title_render.php

# DUO-3345 (category-summary slice): offline proof of the ordered, count-only
# projection, compiled attachment provenance, deletion/capability facets,
# generated/derived vocabulary, strict optional validation, and redacted
# secrets visibility. No WordPress or Docker.
regress-plan-category-summary:
	php sandbox/tests/regress_plan_category_summary.php

# DUO-3345 (category-summary menu evidence): live core-only companion to the
# offline projection contract. It proves same-snapshot menu observations for
# published-vs-draft capture, managed reconciliation, slug adoption, and
# tombstones. The pair and exact mounted candidate bytes are intentionally
# caller-supplied; do not spend the shared Docker budget by accident.
regress-plan-category-summary-live:
	@test -n "$(PLAN_CATEGORY_SUMMARY_PAIR)" || { echo 'PLAN_CATEGORY_SUMMARY_PAIR is required; choose an owned unique disposable pair' >&2; exit 2; }
	@test -n "$(PLAN_CATEGORY_SUMMARY_PORT1)" || { echo 'PLAN_CATEGORY_SUMMARY_PORT1 is required; choose an even port at or above 8900' >&2; exit 2; }
	@test -n "$(PLAN_CATEGORY_SUMMARY_PORT2)" || { echo 'PLAN_CATEGORY_SUMMARY_PORT2 is required; it must be PORT1 + 1' >&2; exit 2; }
	@test -n "$(DUO_EXPECTED_SOURCE_SHA)" || { echo 'DUO_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	PLAN_CATEGORY_SUMMARY_PAIR="$(PLAN_CATEGORY_SUMMARY_PAIR)" PLAN_CATEGORY_SUMMARY_PORT1="$(PLAN_CATEGORY_SUMMARY_PORT1)" PLAN_CATEGORY_SUMMARY_PORT2="$(PLAN_CATEGORY_SUMMARY_PORT2)" DUO_EXPECTED_SOURCE_SHA="$(DUO_EXPECTED_SOURCE_SHA)" bash sandbox/tests/regress_plan_category_summary_live.sh

# DUO-3345 (three-way conflict slice): the stable JSON evidence and host
# summary distinguish target last-synced base, repository intent, target
# intent, non-destructive reconciliation, and the loud destructive override.
# The agent-side renderer and real Apply plan rows are proven by core
# conformance's branch-vs-target and deletion-conflict scenarios.
regress-conflict-view:
	php sandbox/tests/regress_conflict_view.php

# DUO-3345 (structured-refusal slice): the real agent command handlers emit
# one versioned, actionable, credential-redacted JSON refusal for every
# primary compile/capture/plan/apply/deploy failure while human mode and the
# existing typed compiler diagnostics remain compatible.
regress-cli-json-refusals:
	php sandbox/tests/regress_cli_json_refusals.php

# DUO-3345 (explain slice): one freshly-observed entity row projects a
# deterministic, value-free source -> policy -> reference -> structured
# action -> verification chain. No WordPress, target mutation, or provider
# code is used by this pure contract fixture.
regress-plan-explain:
	php sandbox/tests/regress_plan_explain.php

regress-template-mismatch:
	php sandbox/tests/regress_template_mismatch.php

regress-code-deploy-unit:
	bash sandbox/tests/regress_code_deploy_unit.sh

regress-lifecycle-state-handoff:
	php sandbox/tests/regress_lifecycle_state_handoff.php

regress-lifecycle-phase-handoff-unit:
	php sandbox/tests/regress_lifecycle_phase_handoff_unit.php

regress-plugin-dependency-order:
	php sandbox/tests/regress_plugin_dependency_order.php

regress-code-compatibility:
	bash sandbox/tests/regress_code_compatibility.sh

# Clean-room code-half grind: no code bind mount. Public promotion must
# materialize, migrate, heal drift, fail atomically, deactivate before prune,
# preserve unrelated components, and converge both independent revision
# receipts. See docs/grind/code-half.md.
grind-code-half:
	bash sandbox/tests/grind_code_half.sh

# Ecosystem-grade extension: dependency-aware retirement, same-symbol
# replacement in fresh processes, single-file plugins, user MU code, child
# themes, controlled hook failure plus exact checkpoint recovery, bounded
# fatal-MU recovery, and exact return to the original code/state/artifact
# identities.
grind-code-half-ecosystem:
	bash sandbox/tests/grind_code_half_ecosystem.sh

# First-ever sync safety: a hook writes authored state and then throws before
# plugin membership persists. A durable pre-hook receipt must block a different
# artifact/owner until exact code + checkpoint recovery, after which the fixed
# artifact may establish the first three-way base.
grind-code-half-first-sync:
	bash sandbox/tests/grind_first_sync_hook_recovery.sh

# DUO-3216: live activation/deactivation/order gate, deploy-window mail/HTTP
# observations, and the composed host promote path with a retained DB dump.
regress-promotion:
	bash sandbox/tests/regress_promotion.sh

# DUO-3214(a): offline, no docker -- Capture::guard_secret()'s two
# is_string()-gated call sites (post_meta, options) now deep-scan via
# Secrets::hard_match_deep() unconditionally, so an authored value that
# decodes to an array (a serialized settings blob) can no longer skip
# secret scanning entirely. See the script's own header for what's proven
# here (the widened method, via Reflection) vs. by live sandbox evidence
# (the real call-site wiring through Capture::build()).
regress-capture-secret-scan:
	bash sandbox/tests/regress_capture_secret_scan.sh

# DUO-3285: closes a real aliveness gap this issue's own bundle uncovered.
# Capture::build_options()'s option_name_refs (task #93) consumer loop
# shipped a real defect (DUO-3286: undefined $liveOptionNames, a warning
# not a fatal, so capture kept exiting 0 while silently skipping every
# option_name_refs rule) that no offline suite would have caught -- proven
# live by reverting the fix locally and watching regress-offline-all stay
# green. See the script's own header for the full account, including its
# own self-test (a synthetic corrupted copy must fail this check before
# the real-file result is trusted, same discipline regress_capture_secret_
# scan.sh already established).
regress-option-name-refs-wiring:
	bash sandbox/tests/regress_option_name_refs_wiring.sh

# DUO-3214(b) / task #123: offline, no docker -- Canon::normalize()'s new
# OrderPreserved-aware branch, which stops alphabetically resorting a meta
# value a manifest rule declares "order_preserving": true (manifests/
# woocommerce.json's `_product_attributes` closes the causation-proven
# WooCommerce variation-title word-reordering bug). See the script's own
# header for what's proven here (the Canon.php mechanism) vs. by live
# sandbox evidence (the real WooCommerce variation title converging
# byte-for-byte, not just as a same-words anagram).
regress-order-preserving:
	bash sandbox/tests/regress_order_preserving.sh

regress-promotion-lock:
	bash sandbox/tests/regress_promotion_lock.sh

# DUO-3223 (concurrency-scenario arm): two real `wp duo capture` processes
# racing for the same destination -- the SAME deterministic-pause idiom
# DUO-3217 established for live PromotionLock races, applied to the capture
# lock instead. Complements regress_promotion_lock.sh (concurrent
# PROMOTION, already comprehensive) with the still-uncovered concurrent
# CAPTURE axis.
regress-capture-concurrency:
	bash sandbox/tests/regress_capture_concurrency.sh

# DUO-3285: the 25 regress_*.{sh,php} scripts below existed in sandbox/tests/
# with NO Makefile target at all before this issue -- unreachable via `make`,
# discoverable only by grepping the directory listing by hand. Surfaced by
# the same survey that built regress-offline-all/regress-live-list below:
# every regress_* file was read (not name-guessed) to confirm it and
# classify it offline vs live. Wired here on the same terms as every other
# suite in this file, whether or not it ends up folded into a bundle.

# --- offline (no docker/pair.sh -- pure PHP/file-I/O), now in regress-offline-all ---
regress-adopt-rollback:
	php sandbox/tests/regress_adopt_rollback.php

regress-block-refs:
	bash sandbox/tests/regress_block_refs.sh

regress-composite-ref:
	bash sandbox/tests/regress_composite_ref.sh

regress-doctor-env-values:
	php sandbox/tests/regress_doctor_env_values.php

regress-environment-driver:
	php sandbox/tests/regress_environment_driver.php

regress-environment-lifecycle:
	php sandbox/tests/regress_environment_lifecycle.php

regress-environment-materializer:
	php sandbox/tests/regress_environment_materializer.php

# DUO-3324: public attach/materialize over the real SSH driver with an
# offline SSH wrapper and generic machine-local provider; no provisioning is
# claimed and reap is verified as exact detach.
regress-environment-materializer-ssh:
	php sandbox/tests/regress_environment_materializer_ssh.php

# DUO-3324: phase-exact recovery under provider response loss. This is
# deliberately offline: its command provider persists each fixture mutation
# before withholding the response, then proves the public journal resumes
# only with the exact operation owner and idempotency tuple.
regress-environment-materializer-recovery:
	php sandbox/tests/regress_environment_materializer_recovery.php

# DUO-3324: full public-CLI proof against one isolated pair.  This is live
# deliberately: it owns source/target DB/media/repository resources and its
# machine-local provider independently proves snapshot/fence/TTL cleanup.
regress-environment-materializer-live:
	bash sandbox/tests/regress_environment_materializer_live.sh

regress-frozen-materialization-promotion:
	php sandbox/tests/regress_frozen_materialization_promotion.php

# DUO-3384: PlanSummary::render() tolerates partial fixtures by design, so a
# valid `{}` renders clean. This drives an empty, a missing-bucket, and a
# complete plan through both promotion reconciliation boundaries, and pins the
# validator's required buckets to what agent/src/Apply.php actually emits.
# Offline: the ssh/wp pair it needs are fixture scripts on PATH.
regress-plan-contract-trust:
	php sandbox/tests/regress_plan_contract_trust.php

regress-dynamic-options-policy:
	bash sandbox/tests/regress_dynamic_options_policy.sh

regress-taxonomy-object-keyspace:
	bash sandbox/tests/regress_taxonomy_object_keyspace.sh

regress-env-options-policy:
	bash sandbox/tests/regress_env_options_policy.sh

regress-export-manifest-roundtrip:
	bash sandbox/tests/regress_export_manifest_roundtrip.sh

regress-manifest-reclassification-policy:
	bash sandbox/tests/regress_manifest_reclassification_policy.sh

regress-menu-field-reclassification-policy:
	bash sandbox/tests/regress_menu_field_reclassification_policy.sh

regress-regen-dependency-policy:
	bash sandbox/tests/regress_regen_dependency_policy.sh

regress-woocommerce-product-lookups:
	php sandbox/tests/regress_woocommerce_product_lookups.php

regress-woocommerce-product-lookups-fake:
	php sandbox/tests/regress_woocommerce_product_lookups_fake.php

regress-woocommerce-deletion-authority:
	php sandbox/tests/regress_woocommerce_deletion_authority.php

regress-woocommerce-regen-engine:
	php sandbox/tests/regress_woocommerce_regen_engine.php

regress-shortcode-refs:
	bash sandbox/tests/regress_shortcode_refs.sh

regress-term-meta:
	php sandbox/tests/regress_term_meta.php

regress-url-query-refs:
	bash sandbox/tests/regress_url_query_refs.sh

regress-classification-batch:
	php sandbox/tests/regress_classification_batch.php

regress-refresh-orchestration:
	php sandbox/tests/regress_refresh_orchestration.php

regress-refresh-compile-refs:
	php sandbox/tests/regress_refresh_compile_refs.php

regress-refresh-rebase:
	php sandbox/tests/regress_refresh_rebase.php

regress-rollback-authority:
	php sandbox/tests/regress_rollback_authority.php

regress-recovery-executor:
	php sandbox/tests/regress_recovery_executor.php

regress-checkpoint-bundle:
	php sandbox/tests/regress_checkpoint_bundle.php

regress-code-release:
	php sandbox/tests/regress_code_release.php

regress-upload-bundle:
	php sandbox/tests/regress_upload_bundle.php

regress-effect-bundle:
	php sandbox/tests/regress_effect_bundle.php

regress-woocommerce-effect-contract:
	php sandbox/tests/regress_woocommerce_effect_contract.php

regress-ssh-rollback-certification:
	php sandbox/tests/regress_ssh_rollback_certification.php

regress-pair-bootstrap-unit:
	bash sandbox/tests/regress_pair_bootstrap_unit.sh

# DUO-3377: the exact-source gate. Offline like its bootstrap sibling above --
# a real scratch canonical checkout plus a real linked worktree (the trap's
# own shape) and a fake docker; genuine git is the mechanism under test, so it
# is deliberately NOT faked here.
regress-pair-candidate-source:
	bash sandbox/tests/regress_pair_candidate_source.sh

regress-woocommerce-contract:
	php sandbox/tests/regress_woocommerce_contract.php

# DUO-3343: a production refresh is an observation boundary, not a capture
# variant. This focused no-WordPress harness proves the exporter's
# SELECT-only ledger validation, mutation prohibition, and semantic-record
# envelope without needing a sandbox database.
regress-refresh-export-unit:
	php sandbox/tests/regress_refresh_export_unit.php

# --- live (docker/pair.sh-dependent), now in regress-live-list ---
regress-acf-term-options-fields:
	bash sandbox/tests/regress_acf_term_options_fields.sh

regress-collision:
	bash sandbox/tests/regress_collision.sh

regress-entity-type-width:
	bash sandbox/tests/regress_entity_type_width.sh

regress-env-set:
	bash sandbox/tests/regress_env_set.sh

regress-option-ref-scope:
	bash sandbox/tests/regress_option_ref_scope.sh

regress-pmpro-composite-ref:
	bash sandbox/tests/regress_pmpro_composite_ref.sh

regress-repository-authorization:
	bash sandbox/tests/regress_repository_authorization.sh

regress-repository-compiler-integration:
	bash sandbox/tests/regress_repository_compiler_integration.sh

regress-scope-gate:
	bash sandbox/tests/regress_scope_gate.sh

# DUO-3344: offline, no docker — a scope resolved from explicit roots closes
# over declared edges only, every inclusion names the edge that pulled it in,
# and an unresolvable root is refused. Also pins the two defects that folding
# the compiler's reference walk and the closure walk into one enumeration
# retired (a parent cycle going undetected once its posts carried a term, and
# term locators reporting "terms.c"). Read-only: nothing here captures,
# promotes, or deletes.
regress-scope-closure:
	bash sandbox/tests/regress_scope_closure.sh

# DUO-3344: immutable, self-verifying scope evidence. This is distinct from
# the legacy closure preview suite: it covers normalized tombstone selectors,
# artifact/policy association, scoped upload/media/action/provider/effect
# filtering, and the no-target-contact contract.
regress-scope-contract:
	bash sandbox/tests/regress_scope_contract.sh

regress-snapshot-meta:
	bash sandbox/tests/regress_snapshot_meta.sh

regress-generic-reference-shapes:
	bash sandbox/tests/regress_generic_reference_shapes.sh

regress-ssh-adopt:
	bash sandbox/tests/regress_ssh_adopt.sh

certify-ssh-adoption-roundtrip:
	bash sandbox/tests/certify_ssh_adoption_roundtrip.sh

certify-ssh-rollback:
	bash sandbox/tests/certify_ssh_rollback.sh

regress-tec-regen:
	bash sandbox/tests/regress_tec_regen.sh

regress-user-meta:
	bash sandbox/tests/regress_user_meta.sh

# DUO-3285: one target bundling every offline (no-docker) regress suite --
# cheap enough to run at every local close-gate. Hosted CI is intentionally
# disabled for this repository, so this local bundle plus independent review
# is the merge gate. 94 suites: code-half-unit's prerequisites folded in once,
# plus the direct offline prerequisites below, including the SSH rollback,
# adoption rollback, WooCommerce adapter/lookup/deletion/effect, post-field classification, and
# ecommerce static contracts. regress-bundle-coverage independently computes
# this transitive count and rejects a stale number in the status line.
# Plain prerequisite list, same idiom
# as code-half-unit itself -- make's default (non -j) prerequisite order
# is the listed order, and it stops at the first failure, exactly the
# fail-fast behavior a local close-gate wants (no point burning minutes
# on suite 21 when suite 3 already broke). Live suites are deliberately
# NOT here -- see regress-live-list.
#
# DUO-3285 fast-follow and recovery closure: regress-coverage-offline
# (DUO-3290's own suite,
# asub's PR #82) had a real Makefile target the whole time but landed after
# this bundle's own survey was authored, so it slipped in unbundled exactly
# the way this target exists to prevent -- team-lead caught it by
# inspection. Re-running this issue's own survey logic (not just adding the
# one flagged name) turned up a second orphan of the same shape:
# regress-woo-attribute-deletion.sh (DUO-3288) had a real target too but no
# bundle/live-list entry either -- it's LIVE (docker/pair.sh body scan, own
# pair "wooattrdel"), so it's added to regress-live-list instead, not here
# (see that target's own comment). regress-bundle-coverage (new, below) is
# what makes this class of drift impossible to reintroduce silently going
# forward: it runs this exact survey and fails loud the moment a
# regress_*.{sh,php} file exists with neither a bundle nor a live-list
# entry, so a suite must declare itself at birth or CI goes red.
# DUO-3293/3294/3295/3296/3297/3298 add the external authority, exclusion executor,
# encrypted checkpoint, immutable atomic code-release, and upload/media bundle
# suites, followed by lifecycle/rebuild effect contracts and DUO-3299's
# closed signed SSH crash-matrix evidence verifier.
regress-offline-all: code-half-unit \
	regress-adopt-rollback regress-capture-publish regress-adapter-contract regress-adapter-sources regress-site-adapter-certification regress-manifest-dispositions regress-capability-registry regress-certification-bundle regress-interpreter-policy \
	regress-acf-meta-interpreter regress-fatal-mutations-unit regress-capture-secret-scan \
	regress-order-preserving \
	regress-block-refs regress-composite-ref regress-doctor-env-values regress-environment-driver regress-environment-lifecycle regress-environment-materializer regress-environment-materializer-ssh regress-environment-materializer-recovery regress-frozen-materialization-promotion \
	regress-dynamic-options-policy regress-taxonomy-object-keyspace regress-env-options-policy regress-export-manifest-roundtrip \
	regress-manifest-reclassification-policy regress-menu-field-reclassification-policy \
	regress-regen-dependency-policy regress-shortcode-refs regress-term-meta regress-url-query-refs \
	regress-option-name-refs-wiring regress-natural-key-rename regress-classification-batch regress-refresh-orchestration regress-refresh-compile-refs regress-refresh-rebase \
	regress-coverage-offline regress-bundle-coverage regress-rollback-authority \
	regress-recovery-executor regress-checkpoint-bundle regress-code-release regress-upload-bundle \
	regress-effect-bundle regress-woocommerce-effect-contract regress-woocommerce-product-lookups \
	regress-woocommerce-product-lookups-fake regress-woocommerce-deletion-authority \
	regress-woocommerce-regen-engine regress-action-scope regress-actions-providers regress-pair-bootstrap-unit regress-pair-candidate-source \
	regress-post-field-classification regress-ecommerce-developer-static regress-ecommerce-developer-matrix regress-ecommerce-extension-migration regress-capture-atomicity regress-fetch-artifact \
	regress-ssh-rollback-certification regress-woocommerce-contract regress-refresh-export-unit regress-plan-title-render regress-plan-category-summary regress-conflict-view regress-cli-json-refusals regress-plan-explain regress-vocabulary-ownership regress-duo3316-contract regress-close-gate-parent-count \
	regress-manifest-validate regress-scope-closure regress-certbundle-lock regress-adapter-catalog regress-plan-contract-trust regress-scope-contract regress-conformance-asserts regress-plugin-adapter-source
	@echo "regress-offline-all: 98 offline suites green"

# DUO-3285: NOT auto-bundled (docker/pair.sh budget -- this project runs many
# agents concurrently against a shared docker host, see sandbox/bin/pair.sh's
# own "1 docker core per RUNNING pair" discipline) -- enumerable instead, so
# a claim touching a mechanism can find its own suite without grepping this
# file by hand. Prints name + pair/environment requirement per suite; runs
# nothing. Most grind-*/certify-* targets remain a separate category, but the
# production SSH rollback certification is the live merge gate for the
# automatic promote profile and is therefore discoverable here.
regress-live-list:
	@echo "regress-* live suites (docker/pair.sh-dependent) -- run individually, own pair each:"
	@echo ""
	@echo "  regress-pa-attributes                     pair r3e"
	@echo "  regress-shipping-zones                    pair r3e"
	@echo "  regress-code-drift                        pair codedrift 8862/8863"
	@echo "  regress-option-subkeys                    pair asub3233 8910/8911 (parameterized: PAIR/PORT1/PORT2)"
	@echo "  regress-option-reconciliation             pair codexmac3211"
	@echo "  regress-discovery-completeness            pair codexmac3205 8900/8901"
	@echo "  regress-core-semantics                    pair codexmac3207 8900/8901"
	@echo "  regress-attachment-portability            pair codexmac3265 8964/8965"
	@echo "  regress-fatal-mutations                   pair codexmaca3206 9210/..."
	@echo "  regress-multisite-refusal                 own disposable pair (parameterized: MULTISITE_PAIR/PORT1/PORT2)"
	@echo "  regress-adapter-theme-range               pair asub3222tr 8918/8919"
	@echo "  regress-provider-contract-live            pair claudemacb3338 8930/8931"
	@echo "  regress-parent-scoped-natural-key         pair claudemacb3318 8930/8931 (parameterized: PARENT_KEY_PAIR/PARENT_KEY_PORT1/PARENT_KEY_PORT2)"
	@echo "  regress-menu-item-meta-gate               pair asub3275 8954/8955"
	@echo "  regress-plan-category-summary-live        explicit PLAN_CATEGORY_SUMMARY_PAIR/PORT1/PORT2 + DUO_EXPECTED_SOURCE_SHA (candidate-bound disposable pair)"
	@echo "  regress-widgets                           pair awid3278 8960/..."
	@echo "  regress-promotion                         pair codexmaca3216 8920/... (also runs in CI as code-half-grind's sibling)"
	@echo "  regress-promotion-lock                    pair codexmac3217 8900/... (runs in CI: code-half-live-lock)"
	@echo "  regress-capture-concurrency               pair concurrency 8934/... (parameterized: CONCURRENCY_PORT1)"
	@echo "  regress-acf-term-options-fields           pair asub3263 (parameterized: PAIR/PORT1/PORT2)"
	@echo "  regress-collision                         legacy docker-compose.yml --profile fx"
	@echo "  regress-entity-type-width                 pair amergety"
	@echo "  regress-env-set                           pair asnapenvset"
	@echo "  regress-option-ref-scope                  legacy docker-compose.yml --profile r1b"
	@echo "  regress-pmpro-composite-ref               pair asnaprt"
	@echo "  regress-repository-authorization          pair conf 8806/8807"
	@echo "  regress-repository-compiler-integration   pair conf 8806/8807"
	@echo "  regress-scope-gate                        pair codexmac3229 8900/8901"
	@echo "  regress-snapshot-meta                     pair w1a"
	@echo "  regress-generic-reference-shapes         neutral taxonomy/sidecar fixture pair"
	@echo "  regress-ssh-adopt                         standalone SSH host, own docker image (NOT pair.sh) -- DUO-3257/DUO-3281"
	@echo "  certify-ssh-rollback                     four disposable containers: two SSH hosts + two MariaDB servers"
	@echo "  regress-tec-regen                         pair asnaptec"
	@echo "  regress-user-meta                         pair umeta3268 9301/9302"
	@echo "  regress-environment-materializer-live     pair codexmacb3324 9100/9101 (public env materialize/reap; user-authorized)"
	@echo "  regress-coverage                         needs an already-up pair with WooCommerce active (parameterized: DUO_PAIR)"
	@echo "  regress-woo-attribute-deletion            pair wooattrdel 8996/8997 (parameterized: WOOATTRDEL_PAIR/WOOATTRDEL_PORT1/WOOATTRDEL_PORT2)"
	@echo "  grind-ecommerce-developer-live            explicit ECOMMERCE_PAIR/PORT1/PORT2; run only with owner authorization"
	@echo ""
	@echo "Other grind-*/certify-* targets are a separate, already-governed category (see this target's comment)."

# DUO-3285 fast-follow: the drift guard. Runs the same "every regress_*
# file needs a bundle or live-list entry" survey that built regress-
# offline-all/regress-live-list in the first place, every time this runs --
# see the suite's own header for why the one-off fix wasn't enough.
regress-bundle-coverage:
	bash sandbox/tests/regress_bundle_coverage.sh
