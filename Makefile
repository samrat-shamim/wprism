COMPOSE = docker compose -f sandbox/docker-compose.yml

.PHONY: regress-recovery-protocol
.PHONY: regress-offline-all regress-offline-corpus regress-offline-diagnostics
.PHONY: regress-lifecycle-options-snapshot
.PHONY: regress-cli-json-refusals regress-command-output regress-environment-command-preflight regress-environment-command regress-passthrough-command regress-environment-command-options regress-driver-capabilities-command regress-environment-list-command regress-doctor-command regress-adopt-command regress-pending-command regress-classify-command regress-capture-command regress-deploy-command regress-promote-command regress-status-command regress-scope-command regress-refresh-command regress-rebase-command
.PHONY: regress-plan-explain
.PHONY: regress-plan-category-summary regress-plan-category-summary-live regress-plugin-adapter-source regress-scoped-apply-live regress-scoped-apply-live-cleanup regress-scope-chain-stability
.PHONY: regress-init-command regress-init-contract regress-duo-init regress-bound-helper
.PHONY: regress-plan-view regress-local-bootstrap regress-local-bootstrap-live
.PHONY: regress-identity-token-codec
.PHONY: regress-pair-budget-lock regress-pair-compose-unit regress-proof-legacy-pair regress-certbundle-source regress-certbundle-evidence
.PHONY: regress-text-tokenizer
.PHONY: regress-structured-reference-codec
.PHONY: regress-url-query-reference-codec
.PHONY: regress-lint-primitives
.PHONY: regress-block-reference-scanner
.PHONY: regress-menu-reference-scanner
.PHONY: regress-serialized-term-description-scanner
.PHONY: regress-shortcode-reference-scanner
.PHONY: regress-control-plane-seams regress-code-descriptor-compiler regress-agent-src-requires regress-target-observation-premises regress-live-exit-code-contract
.PHONY: regress-linear-loop-freeze
.PHONY: regress-option-reference-grammar regress-post-type-grammar regress-discovery-grammar regress-reference-keyspace-grammar regress-reference-kind-grammar regress-code-config-grammar regress-site-policy-validator regress-policy-load-finalizer regress-artifact-policy-identity regress-compiled-artifact-reader regress-repository-media-catalog regress-repository-schema-validator regress-repository-deletion-parser regress-repository-entity-parser regress-repository-identity-registry regress-repository-reference-graph-validator regress-repository-portable-shape-validator regress-repository-menu-location-validator regress-post-type-relation-resolver
.PHONY: regress-delete-guard-value-codec
.PHONY: regress-delete-guard-evaluator
.PHONY: regress-table-graph regress-table-schema regress-snapshot-identity regress-typed-table-capture regress-snapshot-pruner

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
	regress-refresh-field-diff \
	regress-environment-driver \
	regress-environment-lifecycle \
	regress-environment-materializer \
	regress-environment-materializer-ssh \
	regress-environment-materializer-recovery \
	regress-environment-materializer-live \
	regress-frozen-materialization-promotion \
	regress-woo-attribute-deletion regress-bundle-coverage regress-certification-bundle \
	regress-scoped-certification-bundle regress-adapter-certification-bundle certify-adapter-bundle \
	regress-multisite-refusal regress-journal-bootstrap regress-pair-bootstrap-unit regress-manifest-dispositions regress-site-adapter-certification \
	regress-post-field-classification regress-capability-registry regress-capability-registry-import regress-woocommerce-contract regress-init-contract regress-duo-init regress-duo3316-contract \
	regress-refresh-export-unit regress-vocabulary-ownership regress-parent-scoped-natural-key regress-close-gate-parent-count \
	regress-pair-candidate-source regress-manifest-validate regress-scope-closure regress-certbundle-lock regress-adapter-catalog regress-adapter-observation regress-scope-contract regress-scoped-apply-session regress-scoped-apply-live-cleanup regress-scoped-apply-recovery regress-scoped-effect-reconciliation regress-scoped-promotion-target regress-scoped-promote-unit regress-scope-wire regress-conformance-asserts \
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

regress-scoped-certification-bundle:
	bash sandbox/tests/regress_scoped_certification_bundle.sh

regress-adapter-certification-bundle:
	bash sandbox/tests/regress_adapter_certification_bundle.sh

# Usage: make certify-adapter-bundle MANIFEST=woocommerce
certify-adapter-bundle:
	bash sandbox/tests/certify_adapter_bundle.sh "$(MANIFEST)"

regress-manifest-dispositions:
	bash sandbox/tests/regress_manifest_dispositions.sh

regress-capability-registry:
	bash sandbox/tests/regress_capability_registry.sh

regress-capability-registry-import:
	bash sandbox/tests/regress_capability_registry_import.sh

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
code-half-unit: regress-repository-compiler regress-code-revision-enforcement regress-code-descriptor-compiler regress-code-descriptor-unit regress-code-materializer-unit regress-code-ownership-pruner regress-code-completed-unit regress-code-stage-lock-unit regress-code-stage-transaction-unit regress-code-ledger-transaction-unit regress-plan-summary-code-drift regress-template-mismatch regress-code-deploy-unit regress-deploy-command regress-promote-command regress-promotion-unit regress-lifecycle-state-handoff regress-lifecycle-phase-handoff-unit regress-plugin-dependency-order regress-lifecycle-options-snapshot regress-journal-bootstrap regress-code-compatibility

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

regress-capture-record-readback:
	php sandbox/tests/regress_capture_record_readback.php

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

# DUO-3340 live adapter-authoring exercise: controlled plugin source, journal /
# pending review, inert host draft, shipped-manifest graduation, and provider /
# native-action target convergence. Own disposable pair; never part of the
# offline count.
.PHONY: regress-adapter-authoring-live adapter-authoring-exercise
regress-adapter-authoring-live:
	bash sandbox/tests/regress_adapter_authoring_live.sh

adapter-authoring-exercise: regress-adapter-authoring-live

# DUO-3348 first extraction slice: ManifestGrammar (table/widget declaration
# grammar out of Policy.php), OFFLINE (no WordPress, DB, providers, or docker)
# — belongs in regress-offline-all and its count.
regress-manifest-grammar:
	php sandbox/tests/regress_manifest_grammar.php

# DUO-3345 (#189): value-free plan category summaries, OFFLINE (no WordPress,
# DB, providers, or docker) — belongs in regress-offline-all and its count.
regress-plan-category-summary:
	php sandbox/tests/regress_plan_category_summary.php

# DUO-3345 (#189): the LIVE counterpart. Its own pair +
# PLAN_CATEGORY_SUMMARY_PAIR/PORT1/PORT2 — belongs in regress-live-list, NEVER
# the offline count.
regress-plan-category-summary-live:
	bash sandbox/tests/regress_plan_category_summary_live.sh

# DUO-3345 (bounded plan-view slice): offline proof of the explicit
# same-snapshot filtered projection, canonical AND/OR request grammar,
# UUID ordering/cap, value-free references, full safety/readiness evidence,
# host validation/legacy refusal, and control-byte-safe new itemization.
regress-plan-view:
	php sandbox/tests/regress_plan_view.php

# DUO-3317 live counterpart: a provider whose declared `requires` names an
# environment this target does not have refuses before the first mutation, then
# the identical apply against the shipped adapter (which declares no such
# requirement) converges. Bundle-free — the requirement is supplied through a
# test-manifests overlay, the shipped manifest bytes stay byte-identical. Own
# pair, so it is live-list material, never offline-all.
regress-provider-requirements-live:
	bash sandbox/tests/regress_provider_requirements_live.sh

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

# DUO-3325: `duo adapter-draft`, the safe adapter-DRAFT generator (offline slice).
# Reuses policy-to-manifest's facts core (Policy::export_manifest) and adds OFFLINE
# proposers over a site repo's captured state/**, emitting inert `_draft` candidates
# a human ratifies by hand. Drives the real host CLI as a subprocess against scratch
# site-repos, feeds the draft to the REAL manifest-validate, and proves the
# load-bearing INERTNESS property (an undeclared id_kind under _draft stays ok; the
# same fragment with one trigger key un-renamed FAILS the closed-vocabulary refusal).
# Offline: pure PHP/file-I/O, no docker, no WordPress.
regress-adapter-draft:
	bash sandbox/tests/regress_adapter_draft.sh

# DUO-3382: the certification bundle's own per-host flock(2) — real racing
# processes against a private rendezvous, so the mutual exclusion, the named
# refusal, bounded waiting, and kernel reclaim of a killed holder are proven
# on both lock backends without docker or a 50-minute bundle run.
regress-certbundle-lock:
	bash sandbox/tests/regress_certbundle_lock.sh

# DUO-3355: direct exact-source checkout/freeze boundary. Real temporary Git
# repositories, no docker -- belongs in regress-offline-all.
regress-certbundle-source:
	bash sandbox/tests/regress_certbundle_source.sh

# DUO-3355: direct machine-evidence writer contract for the reference bundle.
# Real jq and file I/O, no docker -- belongs in regress-offline-all.
regress-certbundle-evidence:
	bash sandbox/tests/regress_certbundle_evidence.sh

# DUO-3408: the shared conformance assertion fragment -- every require_*
# helper the seeds/postdeploy hooks call must be defined in ONE fragment both
# sourcing harnesses load, or bundle leg 12 dies at `command not found`.
# Offline: pure grep over the harness sources.
regress-conformance-asserts:
	bash sandbox/tests/regress_conformance_asserts.sh

# DUO-3409: the core sweep's `duo explain` envs registry allocates a per-run
# private directory (portable across GNU/BSD mktemp); prove it is collision-safe
# offline. Pure shell/file-I/O, no docker -- belongs in regress-offline-all.
regress-explain-registry:
	bash sandbox/tests/regress_explain_registry.sh

# DUO-3413: the core sweep's strict-explain post-conditions must name
# infrastructure, not the engine, on an empty-at-exit-0 db export; prove the
# premise guard and the evidence-pasting offline. Pure shell, no docker.
regress-explain-export-premise:
	bash sandbox/tests/regress_explain_export_premise.sh

# DUO-3401: the core sweep's non-duo wp-cli OBSERVATION reads (post get/list,
# comment get, db query on target state) must name infrastructure, not the
# engine, on an empty-at-exit-0 compose death; prove require_observed_nonempty's
# domain behavior and that each guarded call site captures+guards before
# comparing (reverting a guard fails the pins). Pure shell, no docker.
regress-observation-guards:
	bash sandbox/tests/regress_observation_guards.sh

# DUO-3400: the three older live regressions do not source the shared
# conformance assertion fragment, so their compose-death boundary is a small
# explicit source contract: preserve the real refusal status (1), capture the
# complete transport output, and print both when the transport misroutes.
# Offline: static plus mutation-proven shell source checks; no Docker.
regress-live-exit-code-contract:
	bash sandbox/tests/regress_live_exit_code_contract.sh

# DUO-3423: family-wide inventory of live target reads used by conformance
# post-conditions. Every non-empty observation is premise-guarded before its
# comparison/accusation; expected-empty absence and clean-git predicates are
# explicitly inventoried instead of being misclassified as fixture failures.
# Pure source/static contract, no docker.
regress-target-observation-premises:
	bash sandbox/tests/regress_target_observation_premises.sh

# DUO-3394: the polylang conformance seed + checks must route failures through
# the exported `fail` helper (the FAIL: line the sweep keys on), not a bare
# echo+exit. Static, offline.
regress-polylang-fail-helper:
	bash sandbox/tests/regress_polylang_fail_helper.sh

# DUO-3393: checks/elementor.sh's seeded-page id read must be guarded by
# require_fixture_ids, not a dead `$(wp post list) || fail` (empty-at-exit-0
# never fires). Static, offline.
regress-elementor-dead-guard:
	bash sandbox/tests/regress_elementor_dead_guard.sh

# DUO-3366: certify_version_matrix.sh must delete Elementor's active-kit
# reference before site empty removes its post, and must fail on the exact
# null-post warning paths at the 4.2.2 boundary. Static, offline.
regress-elementor-matrix-reset:
	bash sandbox/tests/regress_elementor_matrix_reset.sh

# DUO-3362: grind_r1c_agency.sh must not regenerate the committed
# manifests/duo-agency-cpt.json wholesale (that would delete its hand-authored
# providers/actions); it exports to a scratch path and verifies instead. Static.
regress-grind-r1c-manifest-preserve:
	bash sandbox/tests/regress_grind_r1c_manifest_preserve.sh

# DUO-3339: the installed-adapter catalog -- `duo adapter list|inspect|doctor`
# over both adapter sources the engine has, plus AdapterSources::survey(), the
# reporting half of the source scan. Drives the real host CLI as a subprocess
# against the REAL shipped library and scratch site repositories, and pins the
# architectural claim of the split by comparing each reported refusal against
# the message AdapterSources::discover() throws for the same fixture, byte for
# byte. Offline: file I/O and pure PHP only.
# DUO-3339 slice B2 (#187): the plugin adapter source. Its Makefile target was
# dropped in a #151 Makefile conflict resolution (DUO-3417); the suite is
# offline and belongs in regress-offline-all.
regress-plugin-adapter-source:
	bash sandbox/tests/regress_plugin_adapter_source.sh

regress-adapter-catalog:
	bash sandbox/tests/regress_adapter_catalog.sh

# DUO-3340: one target-owned, value-redacted AdapterSources/pending/journal
# observation plus strict host transport/hash/create-only validation. Offline:
# fake wpdb/WP hooks only; this intentionally does not claim the separate
# two-environment live exercise harness.
regress-adapter-observation:
	bash sandbox/tests/regress_adapter_observation.sh

# DUO-3318 live counterpart: the parent-scoped natural key through capture,
# deploy, apply, rename, and independent recapture across two environments
# whose local ids genuinely differ. Own pair, so it is live-list material,
# never offline-all.
regress-parent-scoped-natural-key:
	bash sandbox/tests/regress_parent_scoped_natural_key.sh

regress-code-revision-enforcement:
	php sandbox/tests/regress_code_revision_enforcement.php

regress-code-descriptor-compiler:
	php sandbox/tests/regress_code_descriptor_compiler.php

# DUO-3348 slice 25: the optional site.duo.json code envelope grammar moved
# out of Policy.php into CodeConfigGrammar.php. Code's descriptor compiler and
# materialization paths remain the runtime owners; this direct suite proves
# the exact wrapper diagnostics and both Policy loader entry points.
regress-code-config-grammar:
	php sandbox/tests/regress_code_config_grammar.php

regress-agent-src-requires:
	php sandbox/tests/regress_agent_src_requires.php

regress-code-descriptor-unit:
	bash sandbox/tests/regress_code_descriptor_unit.sh

regress-code-materializer-unit:
	bash sandbox/tests/regress_code_materializer_unit.sh

# DUO-3350 slice 5: removal authority (remove_old_owned_files/
# assert_removal_safe/owned_extra_files and their eight internal-only
# helpers) moved from Code.php into a new CodeOwnershipPruner.php -- the
# seam CodeMaterializer.php's own docblock already named as its declared
# successor. Code keeps thin facades over all three public entry points,
# matching every prior slice in this issue. Deliberately a wiring/shape
# proof plus a minimal standalone-reachability smoke test only -- full
# prune/preflight/type-conflict/foreign-candidate behavioral coverage
# already exists in regress-code-materializer-unit/regress-code-stage-lock-unit
# (both reflect into Code's kept facade, unchanged by this extraction).
regress-code-ownership-pruner:
	php sandbox/tests/regress_code_ownership_pruner.php

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

# DUO-3345 (three-way conflict slice): the stable JSON evidence and host
# summary distinguish target last-synced base, repository intent, target
# intent, non-destructive reconciliation, and the loud destructive override.
# The agent-side renderer and real Apply plan rows are proven by core
# conformance's branch-vs-target and deletion-conflict scenarios.
regress-conflict-view:
	php sandbox/tests/regress_conflict_view.php

# DUO-3347 slice 1: ConvergenceVerifier was extracted from Apply's post-apply
# convergence gate; retain its offline characterization in the release bundle.
regress-convergence-verifier:
	php sandbox/tests/regress_convergence_verifier.php

# DUO-3347 slice 2: ApplyPlanner was extracted from Apply's plan/conflict
# production (conflict_view, forced/incomplete override evidence, display
# titles, lifecycle comparison, nested-delete counts); direct-API proof
# complementing regress_conflict_view.php's/regress_plan_title_render.php's/
# regress_lifecycle_state_handoff.php's/regress_plan_category_summary.php's
# existing reflection-based coverage of the same methods through Apply.
regress-apply-planner:
	php sandbox/tests/regress_apply_planner.php

regress-table-graph:
	php sandbox/tests/regress_table_graph.php

regress-table-schema:
	php sandbox/tests/regress_table_schema.php

# DUO-3349: direct certification for Snapshot's extracted mapped, natural,
# and composite typed-row identity state machine plus its compatibility facade.
regress-snapshot-identity:
	php sandbox/tests/regress_snapshot_identity.php

# DUO-3349: direct certification for the extracted typed-table read pipeline:
# ordinary/composite rows, attached meta, secret refusal, and canonical bytes.
regress-typed-table-capture:
	php sandbox/tests/regress_typed_table_capture.php

regress-snapshot-pruner:
	php sandbox/tests/regress_snapshot_pruner.php

# DUO-3347 slice 3: the shared policy-owned authored-field materializer
# delegates post/term reconciliation and checked meta/option writes while
# retaining Apply's compatibility facades. Existing termmeta coverage drives
# the policy-aware path; this direct suite pins the moved low-level behavior.
regress-apply-field-materializer:
	php sandbox/tests/regress_apply_field_materializer.php

# DUO-3347 slice 4: MenuMaterializer was extracted from Apply's menu entity
# reconciliation (finalize_menu/assign_locations), on top of slice 3's shared
# ApplyFieldMaterializer. Deliberately a wiring/shape proof only -- full
# behavioral coverage stays in regress_lifecycle_options_snapshot.php (still
# green unchanged through Apply's facade) and live conformance.
regress-menu-materializer:
	php sandbox/tests/regress_menu_materializer.php

# DUO-3347 slice 5: UserMetaMaterializer was extracted from Apply's user
# entity reconciliation (finalize_user_meta, plus its exact-login resolver),
# on top of slice 3's shared ApplyFieldMaterializer. Deliberately a
# wiring/shape proof only -- full behavioral coverage (exact-login boundary
# across divergent numeric ids, authored/runtime classification, ref
# decoding) stays in regress_user_meta.sh's live conformance run.
regress-user-meta-materializer:
	php sandbox/tests/regress_user_meta_materializer.php

# DUO-3347 slice 6: TermMaterializer was extracted from Apply's term entity
# reconciliation (finalize_term, encode_description, reconcile_term_
# relationships), on top of slice 3's shared ApplyFieldMaterializer. The one
# dependency outside that narrow contract -- Apply's memoized, WordPress-
# registry-reading taxes_by_object_type() roster, also consumed by the
# still-Apply-resident post-relationship reconciler -- travels as an explicit
# method parameter instead of a hidden Apply back-reference. Deliberately a
# wiring/shape proof only -- terms are core WordPress content exercised
# broadly by essentially every conformance manifest sweep, not one narrow
# scenario, so there is no single dedicated live test file to point to the
# way menu/user-meta each have.
regress-term-materializer:
	php sandbox/tests/regress_term_materializer.php

# DUO-3347 slice 7: apply_options()/option_apply_target()/
# dynamic_option_rule_for_name()/dynamic_option_resolver_values()/
# apply_value()/apply_option_sub_keys() moved from Apply.php into a new
# OptionsMaterializer.php (the "options/meta" entity materializer target
# seam). apply_options() stays a thin Apply facade; the other five had no
# caller outside the cluster and moved with no facade needed. The one
# non-narrow dependency (Apply's own $warnings collection) travels as an
# explicit by-reference parameter instead of a hidden Apply back-reference.
# Deliberately a wiring/shape proof only -- full behavioral coverage already
# exists in regress_lifecycle_options_snapshot.php and every live
# conformance manifest sweep, unchanged by this extraction.
regress-options-materializer:
	php sandbox/tests/regress_options_materializer.php

# DUO-3347 slice 8: reconcile_relationships()/delete_post_relationships()/
# delete_term_relationships() moved from Apply.php into a new
# RelationshipMaterializer.php (the "relationships" entity materializer
# target seam), narrower than its siblings' (Policy, Tokens,
# ApplyFieldMaterializer) contract -- just (Policy). All three stay thin
# Apply facades since delete_entity() and finalize_post() (neither
# extracted this slice) still call them internally. The one non-narrow
# dependency (Apply's own taxes_by_object_type() roster, scoped to one
# post type) travels as an explicit parameter, matching TermMaterializer's
# $termObjectTaxes precedent. Deliberately a wiring/shape proof only --
# full behavioral coverage already exists in every live conformance
# manifest sweep, unchanged by this extraction.
regress-relationship-materializer:
	php sandbox/tests/regress_relationship_materializer.php

# DUO-3347 slice 9: place_attachment() moved from Apply.php into a new
# AttachmentMaterializer.php (the "attachments" entity materializer target
# seam), constructed from (ApplyFieldMaterializer, CompiledRepository) --
# no Policy, no Tokens, even narrower than RelationshipMaterializer's
# (Policy)-only contract. Stays a thin Apply facade since its only caller
# (finalize_post(), not extracted this slice) wasn't touched.
# CompiledRepository is genuinely constructor-injected as the immutable
# value object it is, unlike the shared/memoized per-call state earlier
# materializers had to take as explicit method parameters. Deliberately a
# wiring/shape proof only -- full behavioral coverage already exists in
# every live conformance manifest sweep, unchanged by this extraction.
regress-attachment-materializer:
	php sandbox/tests/regress_attachment_materializer.php

# DUO-3347 slice 10: ensure_post_row() moved from Apply.php into a new
# PostMaterializer.php (the "posts" entity materializer target seam),
# constructed from (Tokens) -- no Policy, matching AttachmentMaterializer's
# even-narrower-than-usual precedent. Stays a thin Apply facade since its
# only caller (run()'s phase-1 loop, not extracted this slice) wasn't
# touched. Slice 11 moved finalize_post() too, along with resolve_login()/
# its $userIds memoization cache (the shared-state coupling that blocked
# slice 10 from moving it), widening the constructor to (Policy, Tokens,
# ApplyFieldMaterializer, RelationshipMaterializer, AttachmentMaterializer)
# -- the three additional collaborators finalize_post() itself calls
# directly now rather than through Apply's own now-removed facades over
# them, which would be circular. Deliberately a wiring/shape proof only --
# full behavioral coverage already exists in
# regress_post_field_classification.php and every live conformance
# manifest sweep, unchanged by this extraction.
regress-post-materializer:
	php sandbox/tests/regress_post_materializer.php

# DUO-3347 slice 12: delete_entity()/assert_zero() moved from Apply.php into
# a new DeleteExecutor.php (the executor half of the "DeleteGuardEvaluator/
# DeleteExecutor" target seam -- guard evaluation, i.e. build_plan()'s own
# collision detection that decides whether a delete is authorized at all,
# stays out: it is entangled with ApplyPlanner, a materially larger and
# riskier cut than this already-decided, already-authorized row deletion,
# and DUO-3347's own guardrail against changing conflict semantics or
# deletion authority in extraction PRs applies directly). Constructed from
# (Policy, RelationshipMaterializer, MenuMaterializer) -- no $scopeContract:
# the only place delete_entity() read it was the trailing
# REGEN_PENDING_PREFIX marker cleanup, reconciliation bookkeeping that
# stayed on Apply's own facade rather than moving here, so this class never
# needed it as a dependency at all. assign_locations()'s own Apply facade
# is kept (not deleted) despite losing its last production caller here,
# because regress_lifecycle_options_snapshot.php invokes it via
# ReflectionMethod against Apply::class for a genuine behavioral test --
# caught by grepping for reflection-based callers specifically, not just
# bare method-name mentions, before this slice's code was written.
# delete_post_relationships()/delete_term_relationships() had no such
# caller and were removed entirely, covered by
# regress_relationship_materializer.php. Deliberately a wiring/shape proof
# only -- full behavioral coverage already exists in
# regress_lifecycle_options_snapshot.php and every live conformance
# manifest sweep's own entity-delete paths, unchanged by this extraction.
regress-delete-executor:
	php sandbox/tests/regress_delete_executor.php

# DUO-3347 slice 18: DeleteGuardValueCodec owns the fail-closed decoding of
# target-controlled metadata and canonical repair tokens. The broader
# manifest/lock/witness product path remains in regress-woocommerce-deletion-authority.
regress-delete-guard-value-codec:
	php sandbox/tests/regress_delete_guard_value_codec.php

# DUO-3347 slice 19: DeleteGuardEvaluator owns the index-prefix proof that
# makes a guard's locking read a real gap boundary. The broader manifest,
# witness, and transaction product path remains in the Woo deletion suite.
regress-delete-guard-evaluator:
	php sandbox/tests/regress_delete_guard_evaluator.php

# DUO-3348 slice 2: CompiledRepository/RepositoryCompilationException moved
# out of RepositoryCompiler.php into their own CompiledArtifact.php (the
# "CompiledArtifact value object" target seam); proves the file loads and
# round-trips standalone, complementing regress_repository_compiler.sh's/
# regress_plan_explain.php's/etc. existing in-depth coverage through the
# real compiler.
regress-compiled-artifact:
	php sandbox/tests/regress_compiled_artifact.php

# DUO-3350 slice 1: PathSafety was extracted from Code's path-traversal/
# symlink-crossing guards. Proves real filesystem symlink detection (not
# just string shape) survived the move and that Code's ten kept facades
# delegate rather than duplicate the logic.
regress-path-safety:
	php sandbox/tests/regress_path_safety.php

# DUO-3348 slice 4: adapter provenance / capability-readiness resolution
# (manifest_disposition, capability_claim, certification_readiness_blockers,
# adapter_readiness_blockers, provider_readiness_blockers, capability_report)
# moved out of Policy.php into AdapterRegistry.php (the "AdapterRegistry and
# capability resolver" target seam). Proves the extraction: Policy's facades
# genuinely delegate rather than duplicate, and requiring AdapterRegistry.php
# transitively supplies CapabilityRegistry.php on its own; existing suites
# (regress_actions_providers.php, regress_manifest_dispositions.php, etc.)
# already cover the underlying business logic in depth through real fixtures.
regress-adapter-registry:
	php sandbox/tests/regress_adapter_registry.php

# DUO-3348 slice 5: manifest-pin normalization/validation (normalize_manifest_
# pins, validate_manifest_sources, validate_manifest_pins) moved out of
# Policy.php into PinResolver.php (the "PinResolver" half of the "ManifestLoader
# / PinResolver" target seam; load()/from_snapshot() themselves stay on
# Policy). All three were pure, so this suite drives them directly rather than
# through a full Policy::load() cycle; existing suites (regress_adapter_sources.php,
# regress_adapter_contract.php, regress_site_adapter_certification.php, etc.)
# already cover the same logic in depth through the real pin flow, including
# the digest-mismatch branch this file deliberately leaves untouched.
regress-pin-resolver:
	php sandbox/tests/regress_pin_resolver.php

# DUO-3348 slice 6: the action/provider/effect declaration grammar
# (validate_actions, validate_providers, validate_no_conflicting_provider_ids,
# validate_effect_contracts, plus their private helpers and closed-vocabulary
# constants) moved out of Policy.php into ActionProviderGrammar.php. All four
# public entry points had zero external callers beyond Policy's own load()/
# from_snapshot(), so -- like PinResolver -- no compatibility facade exists;
# the call sites go directly to the new class. assert_min_max_range() stayed
# on Policy: it is genuinely shared by the discovery-contract and
# adapter-contract validators too, not exclusive to this cluster (visibility
# widened private -> public so the new class can still reach it).
# regress_actions_providers.php/regress_manifest_validate.php/
# regress_vocabulary_ownership.php already cover this grammar's actual
# refusal behavior in depth through the real Policy::load() flow; this suite
# is new direct-API characterization plus an exact byte-for-byte check that
# closed_vocabularies()/grammar_patterns() publish unchanged values.
regress-action-provider-grammar:
	php sandbox/tests/regress_action_provider_grammar.php

# DUO-3348 slice 7: the cross-manifest "one owner, no contradiction" guard
# family (validate_no_conflicting_taxonomy_object_keyspaces,
# validate_no_conflicting_description_reference_rules,
# validate_no_conflicting_option_rules, validate_no_conflicting_post_type_
# contracts, validate_one_owner_per_declared_name, validate_unique_table_
# id_kinds, plus the private throw_conflicting_taxonomy_object_keyspace
# helper) moved out of Policy.php into CrossManifestGuards.php. All six
# public entry points had zero external callers beyond Policy's own load()/
# from_snapshot(), so -- like PinResolver/ActionProviderGrammar -- no
# compatibility facade exists. taxonomy_pattern_matches()/
# with_option_autoload() looked cluster-exclusive at first but are each used
# well beyond this cluster elsewhere in Policy.php; both stayed, visibility
# widened private -> public. Only two of the six methods
# (validate_no_conflicting_option_rules, validate_no_conflicting_taxonomy_
# object_keyspaces) had prior dedicated behavioral coverage
# (regress_env_options_policy.php/regress_manifest_validate.php,
# regress_taxonomy_object_keyspace.php); this suite is the first genuine
# behavioral proof for the other four, not just wiring confirmation.
regress-cross-manifest-guards:
	php sandbox/tests/regress_cross_manifest_guards.php

# DUO-3348 slice 8: the "named sub-key of an otherwise-atomic manifest
# value" declaration grammar (validate_sub_keys() for options.*.sub_keys,
# validate_dynamic_options() for the top-level dynamic_options key, plus
# their shared private assert_sub_key_parent_has_no_value_fields() helper
# and each method's own constant) moved out of Policy.php into
# SubKeyGrammar.php. The two methods share one inner shape and the same
# private helper -- the real coupling that justifies moving both together,
# not an assumption. Policy::CLASSES stayed on Policy (11 call sites across
# the file, not exclusive to this cluster), visibility widened private ->
# public. regress_dynamic_options_policy.php and regress_duo3316_contract.php
# already cover most of this cluster's real refusal behavior through
# Policy::load()/from_snapshot(), unchanged by the move; this suite is
# validate_sub_keys()'s own first direct behavioral proof (no dedicated test
# matched its exact refusal text anywhere in the repo before this file) plus
# structural/no-facade confirmation.
regress-sub-key-grammar:
	php sandbox/tests/regress_sub_key_grammar.php

# DUO-3348 slice 9: exact and taxonomy-pattern object_keyspace declaration
# grammar moved out of Policy.php into TaxonomyGrammar.php. The live
# taxonomy_object_keyspace() resolver remains on Policy because Capture, Apply,
# Lint, and RepositoryCompiler call it at runtime; this suite proves the pure
# load-time validator's closed vocabulary, indexed diagnostics, and no-facade
# extraction directly. regress_taxonomy_object_keyspace.php continues to cover
# the complete Policy::load()/from_snapshot() and runtime product paths.
regress-taxonomy-grammar:
	php sandbox/tests/regress_taxonomy_grammar.php

# DUO-3348 slice 11: option-name reference declaration grammar and the
# cross-manifest identical-pattern guard moved out of Policy.php into
# OptionReferenceGrammar.php. Runtime option-name matching/discovery remains
# on Policy/Capture/OptionsMaterializer; this direct suite proves the pure
# grammar plus both Policy loader entry points and the no-facade extraction.
regress-option-reference-grammar:
	php sandbox/tests/regress_option_reference_grammar.php

# DUO-3348 slice 12: the closed post-type body/phase declaration grammar
# moved out of Policy.php into PostTypeGrammar.php. The runtime lookup stays
# on Policy as the compatibility facade; this direct suite proves the
# collaborator's refusal text, defaults, vocabulary publication, and wiring.
regress-post-type-grammar:
	php sandbox/tests/regress_post_type_grammar.php

# DUO-3348 slice 13: the pure option-namespace and authored-meta keyspace
# discovery grammar moved out of Policy.php into DiscoveryGrammar.php. Live
# discovery remains on Capture; this direct suite proves the manifest-only
# validator and both Policy loader entry points.
regress-discovery-grammar:
	php sandbox/tests/regress_discovery_grammar.php

# DUO-3348 slice 23: cross-source reference-keyspace closure and attached-meta
# ownership grammar moved out of Policy.php into ReferenceKeyspaceGrammar.php.
# ReferenceShapeGrammar retains the local declaration-shape pass; runtime
# reference resolution remains on Policy and its consumers. This direct suite
# proves every source enumeration, recursive sub-key path, sidecar ambiguity,
# and both Policy loader entry points.
regress-reference-keyspace-grammar:
	php sandbox/tests/regress_reference_keyspace_grammar.php

# DUO-3348 slice 24: ref/token/ledger kind vocabulary grammar moved out of
# Policy.php into ReferenceKindGrammar.php. Runtime reference resolution stays
# on Policy and its consumers; this direct suite proves the shared declared
# id_kind extension path, all claim surfaces, and both Policy loader paths.
regress-reference-kind-grammar:
	php sandbox/tests/regress_reference_kind_grammar.php

# DUO-3350 slice 2: the lifecycle dependency graph planner is independent of
# WordPress side effects; Deploy retains compatibility facades for its reads
# and execution path.
regress-deploy-planner:
	php sandbox/tests/regress_deploy_planner.php

# DUO-3350 slice 6: code_mismatch()/code_revision_mismatch()/code_drift()/
# record_code_versions() and their two internal-only helpers
# (compiled_code_revision(), check_theme_range()) moved from Deploy.php into
# a new LifecyclePlanner.php -- the detection/reporting half of the
# "LifecyclePlanner / LifecycleExecutor" target seam. Deploy::run() itself
# (the ~500-line execution half, "LifecycleExecutor") is deliberately
# deferred to a later slice: unlike every cluster cut so far, it has
# promotion-lock/canary/state-handoff/hook-firing concerns tightly
# interleaved in a required order, and this issue's own guardrail against
# breaking lifecycle order/failure recovery makes it a materially larger,
# riskier cut than this narrower, purely-detective one. Deploy keeps thin
# facades over all four public entry points, matching every prior slice in
# this issue; require_plugin_admin_functions()/current_active_plugins()/
# in_range() stay on Deploy (the first two widened to public) since all
# three are shared beyond this moved cluster. Deliberately a wiring/shape
# proof only -- full behavioral coverage already exists in
# regress_code_revision_enforcement.php/regress_template_mismatch.php/
# regress_adapter_theme_range.sh/regress_code_drift.sh, unchanged by this
# extraction, exercised through Deploy's own kept facades.
regress-lifecycle-planner:
	php sandbox/tests/regress_lifecycle_planner.php

# DUO-3350 slice 7: options_snapshot()/bind_lifecycle_missing_options()/
# unexpected_lifecycle_state_changes() moved from Deploy.php into a new
# StateHandoffVerifier.php -- the before/after canonical options snapshot
# comparison Deploy::run() itself calls at specific points in its own
# required mutation sequence, never any part of that sequencing itself.
# Deploy keeps thin facades over all three (options_snapshot()/
# unexpected_lifecycle_state_changes() because run() still calls them
# directly; bind_lifecycle_missing_options() solely because
# regress_lifecycle_state_handoff.php reaches it via ReflectionMethod
# against Deploy::class, even though its only production caller,
# options_snapshot(), moved away with it). Requires nothing: every external
# class these three methods touch (Capture, Canon, OptionState, Policy,
# CompiledRepository) was already a pre-existing Deploy.php require-hygiene
# gap this move simply inherits, matching slice 6's own established
# "preserve a pre-existing gap rather than introduce a new one" precedent.
# Deliberately a wiring/shape proof only -- full behavioral coverage
# already exists in regress_lifecycle_state_handoff.php/
# regress_lifecycle_options_snapshot.php (both reflection-based against
# Deploy::class), unchanged by this extraction.
regress-state-handoff-verifier:
	php sandbox/tests/regress_state_handoff_verifier.php

# DUO-3350 slice 8: the WP-mutation body of Deploy::run() -- deactivate,
# dependency-ordered activate, active_plugins order correction, and
# switch_theme() -- moved into a new LifecycleExecutor.php, the execution
# half of the "LifecyclePlanner / LifecycleExecutor" target seam slice 6's
# LifecyclePlanner.php already named as its still-unextracted counterpart.
# run() itself keeps the PromotionLock/Canary sequencing around the call and
# the catch-block failure augmentation -- both its own orchestration, not
# lifecycle mutation -- and now makes one delegating call instead of
# inlining the ~80-line block. The plugin dependency-ordering cluster
# deliberately stays on Deploy (a stateless, WordPress-header-reading
# concern, not lifecycle mutation): dependency_ordered_activations()/
# dependency_ordered_deactivations() widen from private to public so
# LifecycleExecutor, their only production caller now, can reach them
# directly; their own private helpers (plugin_dependency_requirements()/
# plugin_dependency_slug()/order_deactivations()/order_activations()) stay
# untouched, exactly as regress_deploy_planner.php's own source-text
# assertions and regress_plugin_dependency_order.php's ReflectionMethod
# calls against Deploy::class both require. Deliberately a wiring/shape
# proof only -- full behavioral coverage already exists across the 14
# end-to-end suites this class's mutation logic feeds (regress_promotion_
# lock.sh, spike_g_code.sh, regress_code_drift.sh, certify_version_matrix.sh,
# the grind_r*.sh scripts, etc.), unchanged by this extraction.
regress-lifecycle-executor:
	php sandbox/tests/regress_lifecycle_executor.php

# DUO-3345 (structured-refusal slice): the real agent command handlers emit
# one versioned, actionable, credential-redacted JSON refusal for every
# primary compile/capture/plan/apply/deploy failure while human mode and the
# existing typed compiler diagnostics remain compatible.
regress-cli-json-refusals:
	php sandbox/tests/regress_cli_json_refusals.php

# DUO-3351 slice 1: host command output owns the shared JSON refusal and
# argument-format contract; cli/duo retains only compatibility facades.
regress-command-output:
	php sandbox/tests/regress_command_output.php

regress-environment-command-preflight:
	php sandbox/tests/regress_environment_command_preflight.php

# DUO-3351 slice 4: target-free typed option grammar for `duo env`.
regress-environment-command-options:
	php sandbox/tests/regress_environment_command_options.php

regress-driver-capabilities-command:
	php sandbox/tests/regress_driver_capabilities_command.php

regress-environment-list-command:
	php sandbox/tests/regress_environment_list_command.php

regress-doctor-command:
	php sandbox/tests/regress_doctor_command.php

regress-adopt-command:
	php sandbox/tests/regress_adopt_command.php

regress-init-command:
	php sandbox/tests/regress_init_command.php

regress-pending-command:
	php sandbox/tests/regress_pending_command.php

regress-classify-command:
	php sandbox/tests/regress_classify_command.php

regress-capture-command:
	php sandbox/tests/regress_capture_command.php

regress-status-command:
	php sandbox/tests/regress_status_command.php

# DUO-3351 slice 10: the scope host handler owns only protected control-plane
# forwarding and transport exit propagation; scope semantics remain in agent.
regress-scope-command:
	php sandbox/tests/regress_scope_command.php

# DUO-3351 slice 11: refresh owns host parsing/refusal/output while Refresh
# retains the semantic planning and target workflow.
regress-refresh-command:
	php sandbox/tests/regress_refresh_command.php

# DUO-3351 slice 12: rebase owns parser/refusal/output semantics while
# Refresh retains the semantic candidate/ref workflow and durable abort.
regress-rebase-command:
	php sandbox/tests/regress_rebase_command.php

# DUO-3351 slice 3: ordinary and scoped agent forwarding share one typed,
# target-free host boundary; malformed scope wire input refuses before contact.
regress-passthrough-command:
	php sandbox/tests/regress_passthrough_command.php

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

regress-deploy-command:
	php sandbox/tests/regress_deploy_command.php

# DUO-3351 slice 19: the public promotion router owns only scoped-versus-
# ordinary selection; the target promotion state machines remain unchanged.
regress-promote-command:
	php sandbox/tests/regress_promote_command.php

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

# DUO-3353: responsibility-focused publication/lease control-plane seams.
# Offline and intentionally independent of WordPress/$wpdb; the existing
# capture and promotion suites remain the behavioral characterization of the
# compatibility facades.
regress-control-plane-seams:
	php sandbox/tests/regress_control_plane_seams.php

# DUO-3352: shared canonical JSON, durable publication, exclusive locking,
# and bounded provider transport used by the rollback/resource bundles.
regress-recovery-protocol:
	php sandbox/tests/regress_recovery_protocol.php

# DUO-3223 (concurrency-scenario arm): a real `wp duo capture` lock holder
# plus simultaneous contenders racing for that destination -- the SAME release-gate idiom
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

regress-local-bootstrap:
	php sandbox/tests/regress_local_bootstrap.php

# DUO-3365: one disposable pair supplies a real installed WordPress volume,
# then an out-of-band controller container proves local adopt -> init from a
# target with no pre-mounted Duo control plane. Live-only; never offline-all.
regress-local-bootstrap-live:
	bash sandbox/tests/regress_local_bootstrap_live.sh

regress-block-refs:
	bash sandbox/tests/regress_block_refs.sh

regress-identity-token-codec:
	php sandbox/tests/regress_identity_token_codec.php

regress-text-tokenizer:
	php sandbox/tests/regress_text_tokenizer.php

regress-structured-reference-codec:
	php sandbox/tests/regress_structured_reference_codec.php

regress-url-query-reference-codec:
	php sandbox/tests/regress_url_query_reference_codec.php

regress-lint-primitives:
	php sandbox/tests/regress_lint_primitives.php

regress-block-reference-scanner:
	php sandbox/tests/regress_block_reference_scanner.php

regress-menu-reference-scanner:
	php sandbox/tests/regress_menu_reference_scanner.php

regress-serialized-term-description-scanner:
	php sandbox/tests/regress_serialized_term_description_scanner.php

regress-shortcode-reference-scanner:
	php sandbox/tests/regress_shortcode_reference_scanner.php

regress-composite-ref:
	bash sandbox/tests/regress_composite_ref.sh

regress-doctor-env-values:
	php sandbox/tests/regress_doctor_env_values.php

regress-environment-driver:
	php sandbox/tests/regress_environment_driver.php

regress-environment-lifecycle:
	php sandbox/tests/regress_environment_lifecycle.php

regress-environment-command:
	php sandbox/tests/regress_environment_command.php

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

# DUO-3348 slice 27: the pure PolicyWriter projection and its stable
# Policy::export_manifest() facade, including the export/load round trip.
regress-policy-writer:
	php sandbox/tests/regress_policy_writer.php

# DUO-3348 slice 28: the shared pure per-manifest grammar pipeline used by
# live Policy::load() and frozen Policy::from_snapshot() validation.
regress-manifest-validator:
	php sandbox/tests/regress_manifest_validator.php

# DUO-3348 slice 29: the shared pure site.duo.json policy validation
# sequence used by live Policy::load() and frozen Policy::from_snapshot().
regress-site-policy-validator:
	php sandbox/tests/regress_site_policy_validator.php

# DUO-3348 slice 36: final cross-manifest closure/pin binding shared by live
# and frozen Policy loading.
regress-policy-load-finalizer:
	php sandbox/tests/regress_policy_load_finalizer.php

# DUO-3348 slice 30: the pure policy/manifest identity projection moved out of
# RepositoryCompiler into ArtifactPolicyIdentity; checks direct loading, its
# historical compiler facades, and the independent registry-row digest proof.
regress-artifact-policy-identity:
	php sandbox/tests/regress_artifact_policy_identity.php

# DUO-3348 slice 35: persisted compiled-artifact validation is independent of
# repository tree building while RepositoryCompiler retains its public facade.
regress-compiled-artifact-reader:
	php sandbox/tests/regress_compiled_artifact_reader.php

# DUO-3348 slice 31: pure attachment/media partition validation moved out of
# RepositoryCompiler while the compiler retains tree orchestration and its
# aggregate diagnostic refusal.
regress-repository-media-catalog:
	php sandbox/tests/regress_repository_media_catalog.php

regress-repository-schema-validator:
	php sandbox/tests/regress_repository_schema_validator.php

regress-repository-deletion-parser:
	php sandbox/tests/regress_repository_deletion_parser.php

regress-repository-entity-parser:
	php sandbox/tests/regress_repository_entity_parser.php

regress-repository-identity-registry:
	php sandbox/tests/regress_repository_identity_registry.php

regress-repository-reference-graph-validator:
	php sandbox/tests/regress_repository_reference_graph_validator.php

regress-repository-portable-shape-validator:
	php sandbox/tests/regress_repository_portable_shape_validator.php

regress-repository-menu-location-validator:
	php sandbox/tests/regress_repository_menu_location_validator.php

regress-post-type-relation-resolver:
	php sandbox/tests/regress_post_type_relation_resolver.php

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

regress-refresh-field-diff:
	php sandbox/tests/regress_refresh_field_diff.php

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

# DUO-3355: direct offline characterization of the extracted pair-budget
# resource lock. The lifecycle/bootstrap suite remains the broader facade
# proof; this target keeps the crash-safe lock boundary independently loaded.
regress-pair-budget-lock:
	bash sandbox/tests/regress_pair_budget_lock.sh

# DUO-3355: direct offline characterization of the extracted pair-compose
# discovery filter (the `docker compose ls` jq query deciding which rows are
# a Duo pair at all) -- fed synthetic compose ls JSON via a fake docker, no
# real docker or pair lifecycle involved. pair_compose_configure() itself
# (the docker-compose-argv/canonical-source half of this library) is already
# exercised for real by the bootstrap/candidate-source/certbundle-lock
# suites, which run the shipped pair.sh's own subcommands against a fake
# docker; this target covers the filter edge cases those runs don't reach
# (the legacy duo-sandbox project's exclusion, malformed/empty compose ls
# output refusing rather than reporting zero pairs, and the Status/
# ConfigFiles/Name guards).
regress-pair-compose-unit:
	bash sandbox/tests/regress_pair_compose_unit.sh

# DUO-3355: offline contract for the narrow shared primitives used by the
# legacy R1 proof harnesses. A fake docker captures the public compose/wp
# commands and rewrite-file bytes; it does not need Docker or WordPress.
regress-proof-legacy-pair:
	bash sandbox/tests/regress_proof_legacy_pair.sh

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

# DUO-3344 slice 4: target-bound scoped apply authority/session protocol.
# Pure PHP with an injected byte-CAS store; no WordPress or target contact.
regress-scoped-apply-session:
	php sandbox/tests/regress_scoped_apply_session.php

# DUO-3344: offline source contract for the scoped live harness itself. It
# proves a failed pair teardown retains its exact evidence rather than
# deleting roots and printing a pre-cleanup green verdict.
regress-scoped-apply-live-cleanup:
	php sandbox/tests/regress_scoped_apply_live_cleanup.php

# DUO-3344: offline scoped authored-boundary and response-loss recovery matrix.
# The harness drives the public scoped session/observation/effect seams with
# injected CAS and target stubs; no Docker or WordPress target is required.
regress-scoped-apply-recovery:
	php sandbox/tests/regress_scoped_apply_recovery.php

# DUO-3344/DUO-3338: offline operation-bound provider/native effect recovery.
regress-scoped-effect-reconciliation:
	php sandbox/tests/regress_scoped_effect_reconciliation.php

# DUO-3344 slice 5: target session/profile/receipt binding and the bounded
# checkpoint-only selection gate. Pure fake-ledger/DB PHP; no target contact.
regress-scoped-promotion-target:
	php sandbox/tests/regress_scoped_promotion_target.php

# DUO-3344 slice 5: public SSH host sequencing against fake SSH/SCP/WP plus a
# real isolated rollback-control root. No Docker, pair, or live target.
regress-scoped-promote-unit:
	bash sandbox/tests/regress_scoped_promote_unit.sh

# DUO-3344: the SSH live harness keeps its exact controlled-promotion failure
# evidence in a private non-secret directory, while unconditionally erasing
# the SSH/config/credential scratch tree. Source-only: no Docker or SSH host.
regress-ssh-adopt-evidence-retention:
	php sandbox/tests/regress_ssh_adopt_evidence_retention.php

# DUO-3344: host/agent scope transport boundary — canonical compact request
# forwarding, refusal before target contact, and ordinary unscoped passthrough.
regress-scope-wire:
	php sandbox/tests/regress_scope_wire.php

# DUO-3344 slice 4 live proof: public host CLI -> DockerTransport -> scoped
# target plan/apply/verification.  It requires an explicitly allocated,
# disposable pair and a clean exact-source SHA; unlike offline regressions it
# is intentionally absent from regress-offline-all.
#
#   make regress-scoped-apply-live \
#     SCOPED_APPLY_LIVE_PAIR=codexmacb3344 \
#     SCOPED_APPLY_LIVE_PORT1=8900 SCOPED_APPLY_LIVE_PORT2=8901 \
#     DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
regress-scoped-apply-live:
	@test -n "$(SCOPED_APPLY_LIVE_PAIR)" || { echo 'SCOPED_APPLY_LIVE_PAIR is required; use an unused disposable pair name' >&2; exit 2; }
	@test -n "$(SCOPED_APPLY_LIVE_PORT1)" || { echo 'SCOPED_APPLY_LIVE_PORT1 is required; choose a free even port >= 8900' >&2; exit 2; }
	@test -n "$(SCOPED_APPLY_LIVE_PORT2)" || { echo 'SCOPED_APPLY_LIVE_PORT2 is required; use PORT1 + 1' >&2; exit 2; }
	@test -n "$(DUO_EXPECTED_SOURCE_SHA)" || { echo 'DUO_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	SCOPED_APPLY_LIVE_PAIR="$(SCOPED_APPLY_LIVE_PAIR)" SCOPED_APPLY_LIVE_PORT1="$(SCOPED_APPLY_LIVE_PORT1)" SCOPED_APPLY_LIVE_PORT2="$(SCOPED_APPLY_LIVE_PORT2)" DUO_EXPECTED_SOURCE_SHA="$(DUO_EXPECTED_SOURCE_SHA)" bash sandbox/tests/regress_scoped_apply_live.sh

# DUO-3344 slice 6 live proof: the SAME scope contract's selected-identity
# set flows unchanged through scope -> capture -> refresh-export -> plan ->
# apply, then an independently-recomputed scope on the TARGET after
# mutation reproduces the SOURCE's original closure byte-for-byte. Explicit
# disposable-pair inputs, absent from regress-offline-all, same rationale as
# regress-scoped-apply-live above. Deliberately does not cover scoped
# promote/rollback: cli/duo refuses scoped promotion outright over anything
# but SshTransport, an entirely different live harness than this one.
#
#   make regress-scope-chain-stability \
#     SCOPE_CHAIN_PAIR=claudemaca3344 \
#     SCOPE_CHAIN_PORT1=8900 SCOPE_CHAIN_PORT2=8901 \
#     DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
regress-scope-chain-stability:
	@test -n "$(SCOPE_CHAIN_PAIR)" || { echo 'SCOPE_CHAIN_PAIR is required; use an unused disposable pair name' >&2; exit 2; }
	@test -n "$(SCOPE_CHAIN_PORT1)" || { echo 'SCOPE_CHAIN_PORT1 is required; choose a free even port >= 8900' >&2; exit 2; }
	@test -n "$(SCOPE_CHAIN_PORT2)" || { echo 'SCOPE_CHAIN_PORT2 is required; use PORT1 + 1' >&2; exit 2; }
	@test -n "$(DUO_EXPECTED_SOURCE_SHA)" || { echo 'DUO_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	SCOPE_CHAIN_PAIR="$(SCOPE_CHAIN_PAIR)" SCOPE_CHAIN_PORT1="$(SCOPE_CHAIN_PORT1)" SCOPE_CHAIN_PORT2="$(SCOPE_CHAIN_PORT2)" DUO_EXPECTED_SOURCE_SHA="$(DUO_EXPECTED_SOURCE_SHA)" bash sandbox/tests/regress_scope_chain_stability.sh

regress-snapshot-meta:
	bash sandbox/tests/regress_snapshot_meta.sh

regress-generic-reference-shapes:
	bash sandbox/tests/regress_generic_reference_shapes.sh

# DUO-3344 live SSH adoption/scoped-promotion evidence must be allocated by
# its operator and bind a clean standalone clone's exact candidate commit:
#   make regress-ssh-adopt ADOPT_FIXTURE=<unique-name> ADOPT_SSH_PORT=<free-port> \
#     DUO_EXPECTED_SOURCE_SHA=$$(git rev-parse HEAD)
regress-ssh-adopt:
	@test -n "$(ADOPT_FIXTURE)" || { echo 'ADOPT_FIXTURE is required; choose an unused lowercase fixture name' >&2; exit 2; }
	@test -n "$(ADOPT_SSH_PORT)" || { echo 'ADOPT_SSH_PORT is required; choose an unused port in 8900..65535' >&2; exit 2; }
	@test -n "$(DUO_EXPECTED_SOURCE_SHA)" || { echo 'DUO_EXPECTED_SOURCE_SHA is required; bind the run to git rev-parse HEAD' >&2; exit 2; }
	ADOPT_FIXTURE="$(ADOPT_FIXTURE)" ADOPT_SSH_PORT="$(ADOPT_SSH_PORT)" DUO_EXPECTED_SOURCE_SHA="$(DUO_EXPECTED_SOURCE_SHA)" bash sandbox/tests/regress_ssh_adopt.sh

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
regress-offline-all:
	@bash sandbox/tests/offline_diagnostics_guard.sh "$(MAKE)" --no-print-directory regress-offline-corpus
	@echo "regress-offline-all: 218 offline suites green"

regress-offline-corpus: code-half-unit \
	regress-adopt-rollback regress-local-bootstrap regress-capture-publish regress-adapter-contract regress-adapter-sources regress-site-adapter-certification regress-manifest-dispositions regress-capability-registry regress-capability-registry-import regress-certification-bundle regress-scoped-certification-bundle regress-adapter-certification-bundle regress-interpreter-policy regress-proof-legacy-pair \
	regress-acf-meta-interpreter regress-fatal-mutations-unit regress-capture-secret-scan \
	regress-order-preserving \
	regress-block-refs regress-identity-token-codec regress-text-tokenizer regress-structured-reference-codec regress-url-query-reference-codec regress-lint-primitives regress-block-reference-scanner regress-menu-reference-scanner regress-serialized-term-description-scanner regress-shortcode-reference-scanner regress-composite-ref regress-doctor-env-values regress-environment-driver regress-environment-lifecycle regress-environment-command regress-environment-materializer regress-environment-materializer-ssh regress-environment-materializer-recovery regress-frozen-materialization-promotion \
	regress-dynamic-options-policy regress-taxonomy-object-keyspace regress-env-options-policy regress-export-manifest-roundtrip regress-policy-writer regress-manifest-validator regress-site-policy-validator regress-policy-load-finalizer regress-artifact-policy-identity regress-compiled-artifact-reader regress-repository-media-catalog regress-repository-schema-validator regress-repository-deletion-parser regress-repository-entity-parser regress-repository-identity-registry regress-repository-reference-graph-validator regress-repository-portable-shape-validator regress-repository-menu-location-validator regress-post-type-relation-resolver \
	regress-manifest-reclassification-policy regress-menu-field-reclassification-policy \
	regress-regen-dependency-policy regress-shortcode-refs regress-term-meta regress-url-query-refs \
	regress-option-name-refs-wiring regress-natural-key-rename regress-classification-batch regress-refresh-orchestration regress-refresh-compile-refs regress-refresh-rebase regress-refresh-field-diff \
	regress-coverage-offline regress-bundle-coverage regress-rollback-authority \
	regress-recovery-executor regress-checkpoint-bundle regress-code-release regress-upload-bundle \
	regress-effect-bundle regress-woocommerce-effect-contract regress-woocommerce-product-lookups \
	regress-woocommerce-product-lookups-fake regress-woocommerce-deletion-authority \
	regress-woocommerce-regen-engine regress-action-scope regress-actions-providers regress-pair-budget-lock regress-pair-compose-unit regress-pair-bootstrap-unit regress-pair-candidate-source \
	regress-post-field-classification regress-ecommerce-developer-static regress-ecommerce-developer-matrix regress-ecommerce-extension-migration regress-capture-atomicity regress-capture-record-readback regress-fetch-artifact \
	regress-ssh-rollback-certification regress-woocommerce-contract regress-init-contract regress-refresh-export-unit regress-plan-title-render regress-conflict-view regress-convergence-verifier regress-apply-planner regress-apply-field-materializer regress-path-safety regress-deploy-planner regress-lifecycle-planner regress-state-handoff-verifier regress-lifecycle-executor regress-cli-json-refusals regress-command-output regress-environment-command-preflight regress-passthrough-command regress-environment-command-options regress-driver-capabilities-command regress-environment-list-command regress-doctor-command regress-pending-command regress-classify-command regress-capture-command regress-status-command regress-plan-explain regress-vocabulary-ownership regress-duo3316-contract regress-close-gate-parent-count \
	regress-manifest-validate regress-adapter-draft regress-scope-closure regress-certbundle-lock regress-certbundle-source regress-certbundle-evidence regress-adapter-catalog regress-adapter-observation regress-plan-contract-trust regress-scope-contract regress-conformance-asserts regress-linear-loop-freeze regress-scope-command regress-refresh-command regress-rebase-command regress-adopt-command regress-init-command \
	regress-plugin-adapter-source regress-plan-category-summary regress-plan-view regress-explain-registry regress-explain-export-premise regress-polylang-fail-helper regress-elementor-dead-guard regress-elementor-matrix-reset regress-grind-r1c-manifest-preserve regress-observation-guards regress-live-exit-code-contract regress-target-observation-premises regress-bound-helper regress-control-plane-seams regress-recovery-protocol regress-scoped-apply-session regress-scoped-apply-live-cleanup regress-scoped-apply-recovery regress-scoped-effect-reconciliation regress-scoped-promotion-target regress-scoped-promote-unit regress-ssh-adopt-evidence-retention regress-scope-wire regress-manifest-grammar regress-compiled-artifact regress-code-descriptor-compiler regress-code-config-grammar regress-menu-materializer regress-adapter-registry regress-agent-src-requires regress-user-meta-materializer regress-pin-resolver regress-term-materializer regress-action-provider-grammar regress-options-materializer regress-cross-manifest-guards regress-relationship-materializer regress-attachment-materializer regress-post-materializer regress-sub-key-grammar regress-delete-executor regress-delete-guard-value-codec regress-delete-guard-evaluator regress-table-graph regress-table-schema regress-snapshot-identity regress-typed-table-capture regress-snapshot-pruner regress-taxonomy-grammar regress-option-reference-grammar regress-post-type-grammar regress-discovery-grammar regress-reference-keyspace-grammar regress-reference-kind-grammar regress-offline-diagnostics
	@echo "regress-offline-corpus: 218 offline suites green"

regress-offline-diagnostics:
	bash sandbox/tests/regress_offline_diagnostics.sh

# DUO-3285: NOT auto-bundled (docker/pair.sh budget -- this project runs many
# agents concurrently against a shared docker host, see sandbox/bin/pair.sh's
# own "2 pairs per docker core" budget discipline) -- enumerable instead, so
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
	@echo "  regress-adapter-authoring-live            own disposable pair (required: ADAPTER_AUTHORING_PAIR/ADAPTER_AUTHORING_PORT1/ADAPTER_AUTHORING_PORT2; DUO_EXPECTED_SOURCE_SHA exact candidate gate)"
	@echo "  regress-local-bootstrap-live              own disposable pair (parameterized: LOCAL_BOOTSTRAP_PAIR/LOCAL_BOOTSTRAP_PORT1/LOCAL_BOOTSTRAP_PORT2; exact candidate gate)"
	@echo "  regress-plan-category-summary-live        pair codexsma3345 9060/9061 (parameterized: PLAN_CATEGORY_SUMMARY_PAIR/PLAN_CATEGORY_SUMMARY_PORT1/PLAN_CATEGORY_SUMMARY_PORT2)"
	@echo "  regress-provider-requirements-live        pair claudemacb3317 8930/8931 (parameterized: PROVIDER_REQUIREMENTS_PAIR/PROVIDER_REQUIREMENTS_PORT1/PROVIDER_REQUIREMENTS_PORT2)"
	@echo "  regress-scoped-apply-live                 explicit SCOPED_APPLY_LIVE_PAIR/PORT1/PORT2 + DUO_EXPECTED_SOURCE_SHA (public scoped plan/apply exact-source proof)"
	@echo "  regress-scope-chain-stability              explicit SCOPE_CHAIN_PAIR/PORT1/PORT2 + DUO_EXPECTED_SOURCE_SHA (scope->capture->refresh-export->plan->apply identity-set stability)"
	@echo "  regress-parent-scoped-natural-key         pair claudemacb3318 8930/8931 (parameterized: PARENT_KEY_PAIR/PARENT_KEY_PORT1/PARENT_KEY_PORT2)"
	@echo "  regress-menu-item-meta-gate               pair asub3275 8954/8955"
	@echo "  regress-widgets                           pair awid3278 8960/..."
	@echo "  regress-promotion                         pair codexmaca3216 8920/... (also runs in CI as code-half-grind's sibling)"
	@echo "  regress-promotion-lock                    pair codexmac3217 8900/... (runs in CI: code-half-live-lock)"
	@echo "  regress-capture-concurrency               own disposable pair (required: CONCURRENCY_PAIR/CONCURRENCY_PORT1/CONCURRENCY_PORT2; DUO_EXPECTED_SOURCE_SHA exact candidate gate)"
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
	@echo "  regress-ssh-adopt                         explicit ADOPT_FIXTURE/ADOPT_SSH_PORT/DUO_EXPECTED_SOURCE_SHA standalone SSH scoped-promotion path"
	@echo "  certify-ssh-rollback                     four disposable containers: two SSH hosts + two MariaDB servers"
	@echo "  regress-tec-regen                         pair asnaptec"
	@echo "  regress-user-meta                         pair umeta3268 9301/9302"
	@echo "  regress-environment-materializer-live     pair codexmacb3324 9100/9101 (public env materialize/reap; user-authorized)"
	@echo "  regress-duo-init                         pair codexmaca3336 9300/9301 (parameterized: DUO_INIT_PAIR/DUO_INIT_PORT1/DUO_INIT_PORT2)"
	@echo "  regress-coverage                         needs an already-up pair with WooCommerce active (parameterized: DUO_PAIR)"
	@echo "  regress-woo-attribute-deletion            pair wooattrdel 8996/8997 (parameterized: WOOATTRDEL_PAIR/WOOATTRDEL_PORT1/WOOATTRDEL_PORT2)"
	@echo "  certify-adapter-bundle MANIFEST=woocommerce own disposable pair (CERT_ADAPTER_PAIR/CERT_ADAPTER_PORT1/CERT_ADAPTER_PORT2; exact candidate gate)"
	@echo "  grind-ecommerce-developer-live            explicit ECOMMERCE_PAIR/PORT1/PORT2; run only with owner authorization"
	@echo ""
	@echo "Other grind-*/certify-* targets are a separate, already-governed category (see this target's comment)."

# DUO-3285 fast-follow: the drift guard. Runs the same "every regress_*
# file needs a bundle or live-list entry" survey that built regress-
# offline-all/regress-live-list in the first place, every time this runs --
# see the suite's own header for why the one-off fix wasn't enough.
regress-bundle-coverage:
	bash sandbox/tests/regress_bundle_coverage.sh

regress-linear-loop-freeze:
	bash sandbox/tests/regress_linear_loop_freeze.sh

regress-init-contract:
	php sandbox/tests/regress_init_contract.php

regress-bound-helper:
	php sandbox/tests/regress_bound_helper.php

regress-duo-init:
	bash sandbox/tests/regress_duo_init.sh
