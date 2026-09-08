COMPOSE = docker compose -f sandbox/docker-compose.yml

.PHONY: regress-recovery-protocol
.PHONY: regress-recovery-preparation
.PHONY: regress-canonical-json-parity
.PHONY: regress-mup-leak-audit grind-mup regress-adapter-certify grind-adapter-walk grind-adoption
.PHONY: regress-authorization-plan regress-release-containment-gate regress-recover-claim regress-verify-oracles regress-rehearse-provider regress-release-next-action regress-release-condition-gate regress-release-ref-binding regress-recover-ordering
.PHONY: regress-assess-projection regress-assess-inventory regress-contract-shape regress-contract-attestation regress-contract-projection regress-assess-composition regress-assess-bounds regress-contract-accept regress-contract-multi-env
.PHONY: regress-offline-all regress-offline-corpus regress-offline-diagnostics
.PHONY: regress-lifecycle-options-snapshot
.PHONY: regress-core-lifecycle regress-core-data-boundary regress-core-scope-platform regress-core-scope-database regress-database-boundary-live
.PHONY: regress-platform-compatibility regress-topology-gate regress-spec-v3-dry-run regress-spec-v3-document regress-spec-window regress-closed-top-level-keys regress-structured-evidence
.PHONY: regress-spec-v3-digest-neutrality regress-spec-migration-verbs

.PHONY: regress-platform-compatibility regress-topology-gate regress-spec-v3-dry-run regress-spec-v3-document regress-spec-window regress-disposition-split
.PHONY: regress-cli-json-refusals regress-fleet-census regress-cohort-rebaseline regress-typed-refusal-envelopes regress-agent-subcommand-names regress-command-output regress-environment-command-preflight regress-environment-command regress-passthrough-command regress-environment-command-options regress-driver-capabilities-command regress-environment-list-command regress-doctor-command regress-migration-preflight regress-adopt-command regress-ideal-onboarding regress-pending-command regress-classify-command regress-capture-command regress-deploy-command regress-deploy-checkpoint regress-promote-command regress-status-command regress-scope-command regress-refresh-command regress-rebase-command
.PHONY: regress-unadopt
.PHONY: regress-plan-explain
.PHONY: regress-plan-category-summary regress-plan-category-summary-live regress-plugin-adapter-source regress-scoped-apply-live regress-scoped-apply-live-cleanup regress-scope-chain-stability
.PHONY: regress-init-command regress-init-contract regress-wprism-init regress-bound-helper
.PHONY: regress-plan-view regress-local-bootstrap regress-local-bootstrap-live regress-local-verified-rollback-live
.PHONY: regress-identity-token-codec
.PHONY: regress-live-pair-ownership regress-pair-budget-lock regress-pair-compose-unit regress-pair-db-engine regress-proof-legacy-pair
.PHONY: regress-text-tokenizer
.PHONY: regress-structured-reference-codec
.PHONY: regress-url-query-reference-codec
.PHONY: regress-rank-math-commerce-multilingual regress-rank-math-yoast-incompatibility
.PHONY: regress-lint-primitives
.PHONY: regress-pending-queue-ownership
.PHONY: regress-block-reference-scanner
.PHONY: regress-menu-reference-scanner
.PHONY: regress-serialized-term-description-scanner
.PHONY: regress-shortcode-reference-scanner
.PHONY: regress-promotion-abort-reason regress-promotion-begin-atomicity
.PHONY: regress-control-plane-seams regress-code-descriptor-compiler regress-agent-src-requires regress-wp-cli-child-process regress-target-observation-premises regress-live-exit-code-contract
.PHONY: regress-option-reference-grammar regress-post-type-grammar regress-discovery-grammar regress-reference-keyspace-grammar regress-reference-kind-grammar regress-code-config-grammar regress-site-policy-validator regress-policy-load-finalizer regress-artifact-policy-identity regress-compiled-artifact-reader regress-repository-media-catalog regress-repository-schema-validator regress-repository-deletion-parser regress-repository-entity-parser regress-repository-identity-registry regress-repository-reference-graph-validator regress-repository-portable-shape-validator regress-repository-scalar-reference-intersection regress-repository-menu-location-validator regress-repository-state-file-catalog regress-post-type-relation-resolver regress-option-name-reference-resolver regress-deletion-capability-resolver regress-taxonomy-pattern-resolver regress-taxonomy-keyspace-resolver regress-taxonomy-description-reference-resolver regress-taxonomy-object-type-option-resolver regress-widget-type-resolver regress-table-declaration-resolver regress-content-attribute-rule-resolver regress-policy-rule-resolver regress-exact-option-resolver regress-option-namespace-resolver
.PHONY: regress-delete-guard-value-codec
.PHONY: regress-delete-guard-evaluator
.PHONY: regress-executable-tree-identity
.PHONY: regress-php-literal-data
.PHONY: regress-db-transaction-authority regress-provider-database-session regress-provider-operation-process
.PHONY: regress-scope-discovery regress-user-meta-capture regress-entity-meta-capture regress-menu-capture regress-media-capture regress-options-capture regress-table-graph regress-table-schema regress-snapshot-identity regress-typed-table-capture regress-typed-table-materializer regress-snapshot-pruner
.PHONY: regress-full-apply-attachment-recovery
.PHONY: regress-plugin-incompatibility
.PHONY: regress-ssh-adopt-extension

.PHONY: up down clean setup seed spike-a spike-b spike-c spike-d spike-e spikes conformance-% cli-smoke cli-triage-smoke lint-smoke grind-r1c grind-r1a grind-r3a grind-r3b grind-code-half-first-sync grind-ecommerce-developer-live pair-up pair-reset pair-destroy pair-list regress-natural-key-rename certify-merge certify-version-skew-merge certify-adversarial-matrix certify-deletion-matrix certify-version-matrix certify-ssh-adoption-roundtrip certify-ssh-rollback regress-capture-publish regress-code-drift regress-option-subkeys regress-option-reconciliation regress-fatal-mutations-unit regress-fatal-mutations-live regress-adapter-contract regress-adapter-sources regress-fetch-artifact regress-adapter-theme-range regress-adapter-plugin-range regress-discovery-completeness regress-core-semantics regress-attachment-portability regress-repository-compiler regress-promotion-unit regress-promotion regress-promotion-lock regress-capture-secret-scan regress-order-preserving regress-capture-concurrency regress-menu-item-meta-gate regress-widgets regress-code-revision-enforcement regress-code-descriptor-unit regress-code-materializer-unit regress-code-completed-unit regress-code-stage-lock-unit regress-code-stage-transaction-unit regress-code-stage-unchanged-skip regress-code-ledger-transaction-unit regress-plan-summary-code-drift regress-plan-title-render regress-conflict-view regress-template-mismatch regress-code-deploy-unit regress-lifecycle-state-handoff regress-lifecycle-phase-handoff-unit regress-plugin-dependency-order regress-rollback-authority regress-recovery-transport regress-local-verified-rollback regress-recovery-executor regress-checkpoint-bundle regress-code-release regress-code-source-lock regress-code-lock-compile-gate regress-init-code-split regress-code-classify regress-code-resolve regress-code-import code-half-unit \
	regress-adopt-rollback regress-block-refs regress-composite-ref regress-doctor-env-values regress-dynamic-options-policy regress-taxonomy-object-keyspace \
	regress-env-options-policy regress-shipped-option-declarations regress-export-manifest-roundtrip regress-manifest-reclassification-policy regress-ecommerce-developer-matrix \
	regress-menu-field-reclassification-policy regress-regen-dependency-policy regress-shortcode-refs \
	regress-woocommerce-hierarchy-lookups regress-woocommerce-regen-engine regress-action-scope regress-provider-contract regress-actions-providers regress-core-rewrite-native-action regress-provider-contract-live regress-ecommerce-developer-static regress-ecommerce-extension-migration regress-capture-atomicity \
	regress-term-meta regress-url-query-refs regress-collision regress-env-set regress-option-ref-scope \
	regress-repository-authorization regress-repository-compiler-integration regress-scope-gate \
	regress-snapshot-meta regress-generic-reference-shapes regress-ssh-adopt regress-user-meta \
	regress-option-name-refs-wiring regress-offline-all regress-live-list regress-woocommerce-rewrite-coinstall regress-code-compatibility regress-upload-bundle \
	regress-effect-bundle regress-ssh-rollback-certification \
	regress-coverage-offline regress-coverage regress-classification-batch \
	regress-refresh-orchestration \
	regress-refresh-compile-refs \
	regress-refresh-rebase \
	regress-refresh-field-diff \
	regress-merge-check \
	regress-environment-driver \
	regress-environment-lifecycle \
	regress-environment-materializer \
	regress-environment-materializer-ssh \
	regress-environment-materializer-recovery \
	regress-environment-materializer-live \
	regress-env-provider-conformance-live \
	regress-rehearsal-containment-live \
	regress-frozen-materialization-promotion \
	regress-bundle-coverage regress-suite-wiring regress-platform-move-gates \
	regress-adapter-package-current-paths \
	regress-multisite-refusal regress-polylang-tec-rewrite-coinstall regress-polylang-live-fixtures regress-journal-bootstrap regress-pair-bootstrap-unit regress-manifest-dispositions regress-site-adapter-certification regress-certificate-axis-binding regress-cross-root-replay \
	regress-post-field-classification regress-init-contract regress-wprism-init regress-reference-contract \
	regress-refresh-export-unit regress-ledger-read-only-schema regress-vocabulary-ownership regress-parent-scoped-natural-key regress-close-gate-parent-count \
	regress-lint-host-verb regress-lint-type-exemptions \
	regress-pair-candidate-source regress-manifest-validate regress-scope-closure regress-adapter-catalog regress-adapter-observation regress-scope-contract regress-scoped-apply-session regress-scoped-apply-live-cleanup regress-scoped-apply-recovery regress-scoped-effect-reconciliation regress-scoped-promotion-target regress-scoped-promote-unit regress-scope-wire regress-conformance-asserts \
	release-gate

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
	bash sandbox/tests/spike/spike_a_round_trip.sh

spike-b:
	bash sandbox/tests/spike/spike_b_merge.sh

spike-c:
	bash sandbox/tests/spike/spike_c_provenance.sh

spike-d:
	bash sandbox/tests/spike/spike_d_woo.sh

spike-e: spike-e-acf

spikes: spike-a spike-b spike-c spike-d spike-e

conformance-%:
	bash sandbox/conformance/run.sh $*

cli-smoke:
	bash sandbox/tests/spike/cli_smoke.sh

cli-triage-smoke:
	bash sandbox/tests/spike/cli_triage_smoke.sh

lint-smoke:
	bash sandbox/tests/spike/lint_smoke.sh

# Grind round R1-C (task #48): the agency stack — Elementor + ACF active
# together, plus a custom CPT plugin dogfooded through code/ — tested for
# INTERPLAY. Own pair (r1c1 :8818 / r1c2 :8819, profile r1c). The script is
# the spec: its header names each interplay point and the finding it guards.
grind-r1c:
	bash sandbox/tests/grind/grind_r1c_agency.sh

# Grind round R1-A (task #46): a forms-driven business site — Contact Form 7
# + Ninja Forms on twentytwentyone. Own pair (r1a1 :8814 / r1a2 :8815,
# profile r1a). The script is the spec: its header names the fixture and the
# findings each assertion guards.
grind-r1a:
	bash sandbox/tests/grind/grind_r1a_forms.sh

# Grind round R1-B (task #47): a full WooCommerce shop — Storefront theme,
# VARIABLE products with GLOBAL attributes (pa_* dynamic taxonomies),
# shipping zones, tax rates, a grouped product. Own pair (r1b1 :8816 /
# r1b2 :8817, profile r1b). The script is the spec: its header names the
# fixture and the findings each assertion guards.
grind-r1b:
	bash sandbox/tests/grind/grind_r1b_shop.sh

# issue #3337: the full ecommerce developer proof is live-only and deliberately
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
	ECOMMERCE_PAIR="$(ECOMMERCE_PAIR)" ECOMMERCE_PORT1="$(PORT1)" ECOMMERCE_PORT2="$(PORT2)" bash sandbox/tests/grind/grind_ecommerce_developer.sh

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
# on) — NOT the legacy sandbox/docker-compose.yml. The script is the spec;
# the engine gaps this round escalated are recorded in the note strings of
# adapter-packages/the-events-calendar/package/manifest.json and
# adapter-packages/paid-memberships-pro/package/manifest.json.
grind-r3b:
	bash sandbox/tests/grind/grind_r3b_events.sh

# Grind round R3-A (task #90): a multilingual WooCommerce shop — Polylang
# (free) + WooCommerce + Storefront, two languages (en/de), translated
# pages/category/pa_color terms/one product, per-language menus, an
# UNTRANSLATED variable product (pa_size x pa_color, 4 variations). Own
# sandbox/bin/pair.sh pair (r3a1 :8850 / r3a2 :8851) — NOT the legacy
# sandbox/docker-compose.yml. The script is the spec;
# adapter-packages/polylang/package/manifest.json's own notes carry what this
# round confirmed live.
grind-r3a:
	bash sandbox/tests/grind/grind_r3a_multilingual.sh

regress-natural-key-rename:
	php sandbox/tests/offline/repository/regress_natural_key_rename.php

# Certify merge (issue #3228): permanentizes spike_b_merge.sh's divergent-edit
# + conflict + resolve + converge flow as a re-runnable LOCAL regression
# fixture (own sandbox/bin/pair.sh pair, "mergecert" 8860/8861, headless) —
# extended with a typed-snapshot table-entity conflict
# (woocommerce_attribute_taxonomies) and a negative test against the real
# repository semantic compiler's conflict_marker diagnostic (issue #3208).
# Gate is local evidence, not CI, per commit 1efb6df (the conformance CI
# workflow is disabled by owner decision). See sandbox/tests/
# certify_merge.sh's header for full scope: what's proven here vs.
# explicitly routed elsewhere (add/add same-slug rejection is unblocked now
# that issue #3208 landed, but stays out of this fixture per the issue's own
# routing to issue #3223's matrix).
certify-merge:
	bash sandbox/tests/certify/certify_merge.sh

# issue #3228, rebuilt at issue #3487: the cross-branch plugin-version-skew merge —
# the scenario certify-merge above deliberately routes away from itself, and
# the one #478 left UNCOVERED when it deleted the wprism-loop-demo fixture this
# was originally built on. Rebuilt on the retained wprism-agency-cpt fixture
# plugin (code-bound through the site repo's own code/ tree) with the
# migrated option classified by site policy, so no shipped manifest byte
# moves. Own pair (a3487sk 8986/8987 by default, parameterized:
# MERGESKEW_PAIR/MERGESKEW_PORT1/MERGESKEW_PORT2).
certify-version-skew-merge:
	bash sandbox/tests/certify/certify_version_skew_merge.sh

# issue #3223 (adversarial certification matrix): cases that don't belong to
# any single capability's own certification fixture. PART 1 (add/add
# same-slug -> RepositoryCompiler's duplicate_natural_identity) was routed
# here explicitly by certify_merge.sh/issue #3228's own scope note. PART 2
# (restored-target disaster recovery: fail closed on lost ledger history,
# then identity-export/identity-import to a clean, byte-identical
# continuation) proves issue #3223's own "fresh/mapped/restored target" axis.
# See the script's own header for full detail.
certify-adversarial-matrix:
	bash sandbox/tests/certify/certify_adversarial_matrix.sh

# issue #3223 slice 4 (--with-deletes scenarios): the three manifests with real
# deletion boundaries on plugin-owned typed-snapshot tables, beyond core's
# own (checks/core.sh). WooCommerce and Ninja Forms prove explicit fail-closed
# parent boundaries, Ninja Forms also proves its independently safe child-row
# cascades, and Paid Memberships Pro proves an empty-guards composite_ref
# delete. See the script's own header for the exact contracts.
certify-deletion-matrix:
	bash sandbox/tests/certify/certify_deletion_matrix.sh

# issue #3223's own last remaining piece (version-boundary matrix), unblocked
# by the owner ruling on artifact sourcing (issue comment 0ec1d2e3): installs
# ACF from a sha256-verified wp.org artifact (never a bare slug, never
# "latest") at BOTH its manifest-declared version_range boundaries, proving
# the range is backed by real evidence at its own edges, not just whatever
# version every other fixture happens to have installed. First real plugin
# only -- see the script's own header for why, and issue #3223 for the
# remaining 6 pinned manifests as their own next slice.
certify-version-matrix:
	bash sandbox/tests/certify/certify_version_matrix.sh

# issue #3213 (atomic capture publication): offline, no docker -- exercises
# agent/src/Publication/Publish.php's capture lock, staging dir, atomic swap, and crash
# recovery directly, including a real SIGKILL of a child process mid-
# publish. See the script's own header for what is/isn't covered here vs.
# by live sandbox evidence (the InnoDB engine check + real transaction
# retry need a live MySQL and aren't repeated in this offline target).
regress-capture-publish:
	php sandbox/tests/offline/capture/regress_capture_publish.php

# issue #3231: code_drift detection (Deploy::code_drift(), a narrower question
# than code_mismatch — did an installed plugin/theme version change since
# the last successful 'wprism deploy'/'wprism capture', regardless of whether the
# new version is still within a pinned version_range) + the advisory
# DISALLOW_FILE_MODS `wprism doctor` check. Own pair.sh pair ("codedrift"
# 8862/8863, headless). Uses WordPress core's own bundled Hello Dolly
# plugin — zero network installs.
regress-code-drift:
	bash sandbox/tests/live/regress_code_drift.sh

# issue #3233 (sub-key option classification): a fresh, from-scratch pair
# (sandbox/bin/pair.sh, asub3233 8910/8911) — Polylang's `post_types`/
# `taxonomies`/`nav_menus` and Yoast's `disableadvanced_meta` sub-keys of
# their respective env-classified option blobs now capture/apply
# independently, merging into the live blob without clobbering excluded
# sibling keys. See adapter-packages/polylang/package/manifest.json's and
# adapter-packages/yoast/package/manifest.json's own notes for the full
# empirical trail.
regress-option-subkeys:
	bash sandbox/tests/live/regress_option_subkeys.sh

# issue #3211: explicit absent/present/deleted option records, exact autoload,
# conflict/delete safety, database assertions, recapture, and retry.
regress-option-reconciliation:
	bash sandbox/tests/live/regress_option_reconciliation.sh

regress-discovery-completeness:
	bash sandbox/tests/live/regress_discovery_completeness.sh

regress-core-semantics:
	bash sandbox/tests/live/regress_core_semantics.sh

# Core is host-integrated rather than an activatable plugin. Two exact
# WordPress image digests exercise upgrade, rollback/restore, same-version
# reinstall, residue pruning, preserved operator content/config/ledger state,
# real apply/recapture, and HTTP behavior. Requires an exact clean candidate.
regress-core-lifecycle:
	bash sandbox/tests/live/regress_core_lifecycle.sh

# Exact WordPress core entity/value matrix plus secret, malformed serialized
# state, scalar-zero ref, and source/target core-schema fail-closed boundaries.
regress-core-data-boundary:
	bash sandbox/tests/live/regress_core_data_boundary.sh

# Exact core AND PHP matrix for the host-integrated core adapter: one full
# MariaDB 11 round trip per cell (6.9.2, 7.0.2, 7.0.3 and 7.1 on PHP 8.3, plus
# 7.1 on PHP 8.4 — both cell sets are cross-checked against
# platform/adapter-library/capabilities/platform.json's exercised-series maps, and each
# cell asserts the booted PHP_VERSION EQUALS that series' proof patch) plus a
# below-range WordPress and a past-the-exclusive-maximum PHP refusal. Multisite
# retains its dedicated live suite; the engine axis is regress-core-scope-database.
regress-core-scope-platform:
	bash sandbox/tests/live/regress_core_scope_platform.sh

# Exact database engine matrix: one full round trip per CLAIMED engine on that
# engine's own shared server (sandbox/db.yml's MariaDB 11 and
# sandbox/db.mysql.yml's MySQL 8.4, selected with WPRISM_DB_ENGINE), carrying the
# five probe groups docs/mysql-dialect-audit.md derived from the shipped SQL in
# its stated order — §5 authentication FIRST, then GET_LOCK bounds, the
# VALUES(col) upserts through the real lease CAS, the planted non-JSON lease
# row on both engines, and the schema/collation record. The cell set is
# cross-checked against platform.json's engines map, so an engine widened into
# the claim without live evidence fails before any pair boots. Brings the MySQL
# server down on exit (db.mysql.yml:43-47 — a second 2g/2.0-cpu container is
# what wedged OrbStack).
regress-core-scope-database:
	bash sandbox/tests/live/regress_core_scope_database.sh

# Exact-source generic database boundary proof on MariaDB and MySQL: the
# product read snapshot retains/releases its LIMIT-0 metadata lock, rejects an
# executable SQL SECURITY DEFINER view without invoking its function, and on
# MariaDB refuses NEXT/PREVIOUS VALUE FOR without moving the sequence.
regress-database-boundary-live:
	@test -n "$(DATABASE_BOUNDARY_PAIR)" || { echo 'DATABASE_BOUNDARY_PAIR is required; choose an unused collision-resistant lowercase pair name' >&2; exit 2; }
	@test -n "$(DATABASE_BOUNDARY_PORT1)" || { echo 'DATABASE_BOUNDARY_PORT1 is required; choose an unused even port >=8900' >&2; exit 2; }
	@test -n "$(DATABASE_BOUNDARY_PORT2)" || { echo 'DATABASE_BOUNDARY_PORT2 is required; choose DATABASE_BOUNDARY_PORT1 + 1' >&2; exit 2; }
	@test -n "$(WPRISM_EXPECTED_SOURCE_SHA)" || { echo 'WPRISM_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	DATABASE_BOUNDARY_PAIR="$(DATABASE_BOUNDARY_PAIR)" DATABASE_BOUNDARY_PORT1="$(DATABASE_BOUNDARY_PORT1)" DATABASE_BOUNDARY_PORT2="$(DATABASE_BOUNDARY_PORT2)" WPRISM_EXPECTED_SOURCE_SHA="$(WPRISM_EXPECTED_SOURCE_SHA)" bash sandbox/tests/live/regress_database_boundary_live.sh

regress-attachment-portability:
	bash sandbox/tests/live/regress_attachment_portability.sh

# issue #3206: offline wpdb return semantics plus a live, isolated failure-
# injection matrix for insert/update/delete/transactions/rebuild-actions/ledger.
regress-fatal-mutations-unit:
	bash sandbox/tests/offline/apply/regress_fatal_mutations_unit.sh

regress-fatal-mutations-live:
	bash sandbox/tests/live/regress_fatal_mutations_live.sh

# issue #3222 (version-pinned adapter compatibility contract): Policy::load()'s
# new validators, Policy::theme_ranges(), and RepositoryCompiler's
# per-manifest digest/resolved_adapters() — pure PHP, offline, no docker
# (same idiom as regress-capture-publish above). See the script's own
# header for exactly what is/isn't covered here vs. the live theme-range
# leg below.
regress-adapter-contract:
	php sandbox/tests/offline/adapter/regress_adapter_contract.php

# One stable corpus row discovers every capsule and runs its complete offline
# gate. Package test additions therefore change only adapter-packages/<slug>/;
# this target, the Makefile and tools/offline-corpus.mk stay byte-identical.
# Only tools/offline.php's checked changed-run command line may delegate an
# explicit scenario task. An ambient variable cannot weaken the canonical gate.
ifneq ($(origin WPRISM_OFFLINE_DELEGATED_SCENARIOS),command line)
unexport WPRISM_OFFLINE_DELEGATED_SCENARIOS
endif
regress-adapter-packages:
	php sandbox/tests/offline/adapter/regress_adapter_packages.php

# Compatibility targets are derived from suite basenames: a discovered
# `regress_example.php` or `.sh` is invoked as `make regress-example`. This
# pattern preserves existing commands while package suite additions and renames
# require no central Makefile edit. Explicit engine/scenario targets below win
# normally; an unknown compatibility name refuses in the resolver.
.PHONY: adapter-package-make-force
regress-%: export WPRISM_ADAPTER_PACKAGE_MAKE_TARGET = $@
regress-%: adapter-package-make-force
	php tools/adapter-package-make-target.php --target-from-make

# Package-owned spikes use the same basename-derived compatibility dispatch as
# package regressions. Explicit root spikes above win for the shared historical
# scenarios; `spike-e` deliberately aggregates ACF's capsule-owned
# spike_e_acf.sh through this rule.
spike-%: export WPRISM_ADAPTER_PACKAGE_MAKE_TARGET = $@
spike-%: adapter-package-make-force
	php tools/adapter-package-make-target.php --target-from-make

adapter-package-make-force:

# issue #3223/recertification: pinned artifact downloads retry transient curl
# failures at most three times, while digest mismatches and exhausted
# failures remain fail-closed with only the partial temp file removed.
regress-fetch-artifact:
	bash sandbox/tests/offline/guards/regress_fetch_artifact.sh

regress-manifest-dispositions:
	php sandbox/tests/offline/policy/regress_manifest_dispositions.php

# WP-4.4 (spec § v3.4): the reviewed claim source moved from one
# manifests/dispositions.json to one document per subject under
# manifests/dispositions/, and NOT ONE ADAPTER DIGEST MOVED. The historical
# pre-split tree had 16 subjects; the current WPrism baseline has 19 and is
# pinned through two compatible worlds. The suite pins the historical 16-subject digests,
# manifest_hash and registry_sha256 as literals captured from that tree;
# measures each enumerated Canon-encoding hazard (a nested list re-ordered and
# a UTF-8 reason re-composed MOVE a digest; map key order does not; int/float is
# the one a digest cannot catch, so the census asserts the reviewed source
# holds no number at all); and re-runs every per-entry, root, profile and
# coverage refusal from the split form in its existing wording.
regress-disposition-split:
	php sandbox/tests/offline/policy/regress_disposition_split.php

# WP-4.6 (spec § v3.5): a spec_version 3 adapter narrows its capability claim to
# the boundary cells it was exercised on; a WIDER cell refuses by name; the key
# is inert at v2, so all currently shipped claims stay byte-identical; and every
# load-time refusal in PlatformCompatibility::assert_supported() -- all five
# axes, #560's process axis included -- still fires with a narrowing adapter
# projected, because narrowing scopes the CLAIM and not the runtime.
regress-adapter-environment-narrowing:
	php sandbox/tests/offline/policy/regress_adapter_environment_narrowing.php

# WP-1.2: Policy::load() costs one manifest decode per PIN, never one per
# manifest in the library. Counted (not timed) against synthetic 100/1,000/
# 10,000-manifest libraries under sandbox/tmp, through the real engine.
regress-policy-load-scale:
	php sandbox/tests/offline/policy/regress_policy_load_scale.php

# The one agent-side platform pre-policy gate: every inclusive/exclusive
# PHP/MariaDB edge, both halves of the WordPress range-plus-exercised-series
# predicate (including a boundary with a deliberate series hole) and every
# malformed-axis invariant, topology/engine mismatch, checked probe failure,
# aggregate diagnostic, and pre-repository ordering.
regress-platform-compatibility:
	php sandbox/tests/offline/policy/regress_platform_compatibility.php

# The single topology gate: typed reason code, byte-identical human sentence,
# and the Policy-free doors (lease verbs, journal-reset, classify, journal boot).
regress-topology-gate:
	php sandbox/tests/offline/policy/regress_topology_gate.php

# The v3 static dry run: what each candidate spec-v3 rule would refuse today,
# measured against every shipped manifest plus the synthetic estate, with
# every rule input read from the shipped constants the v3 code will consult.
regress-spec-v3-dry-run:
	php sandbox/tests/offline/policy/regress_spec_v3_dry_run.php

# WP-4.1: spec/repo-format.md's v3 section, held against the tree it describes
# and held to describing nothing this engine enforces. A v3 section cannot be
# GENERATED the way the capability document and the wire-surface register are
# -- it is a design argument -- so every measurable claim in it (the 31-key
# partition, the five compatibility axes, the disposition monolith's size, the
# 44-identity namespace census, the reserved refusal texts) is re-measured from
# the shipped tree, and each rule is separately asserted UNENFORCED, so a rider
# cannot land enforcement without moving this suite's expectations.
regress-spec-v3-document:
	php sandbox/tests/offline/policy/regress_spec_v3_document.php

# WP-4.12 -- THE FLIP's current WPrism identity baseline. The current 18
# subject digests, manifest_hash values for eight representative pin sets,
# registry address and two compatible-world snapshots are compared against the
# explicit greenfield fixture (the fixture and its sha256 are literals in the suite).
# The suite also proves the platform-only mutation refusals: a hand-mixed bundle
# (v3 agent, v2 platform.json) refuses at the shipped platform_boundary sentence.
regress-spec-v3-digest-neutrality:
	php sandbox/tests/offline/policy/regress_spec_v3_digest_neutrality.php

# WP-4.12: the two flag-day migration verbs, end to end against real
# certificates minted by a child process at the PRIOR spec era -- recertify's
# idempotence and all-or-nothing restore, and release --spec-v3's prior-pin
# journaling and its journal-before-emit ordering contract.
regress-spec-migration-verbs:
	php sandbox/tests/offline/cli/regress_spec_migration_verbs.php

# WP-4.2: the spec_version acceptance window (§ v3.1) and the engine_features
# channel (§ v3.2). Probes ONE set of manifests against two engines -- this
# process at the shipped WPRISM_SPEC_VERSION, and a child process that defines N+1
# -- because a spec version is a define() and a PHP process holds one of those,
# and because the channel's admitting half is only reachable at N+1 before the
# flip. Also runs the release-gate floor check against a mutated copy, so the
# gate that forbids an N-2 window is proven to bite.
regress-spec-window:
	php sandbox/tests/offline/policy/regress_spec_window.php

# WP-4.3 (spec § v3.5's sibling, § v3.3): the top-level manifest key set is
# CLOSED at spec_version 3 and open at v2, from ONE definition shared with the
# signer. Two engines and two trees -- a child process at N+1 for the verdict
# matrix, and a copied tree whose two defines move with platform.json for the
# empirical baseline through `wprism manifest-validate`: [ok] at v2 and [error]
# naming the key at v3, on identical fixture bytes. Also runs the release-gate
# same-set check against a mutated copy, so the gate is proven to bite.
regress-closed-top-level-keys:
	php sandbox/tests/offline/policy/regress_closed_top_level_keys.php

# WP-6.4: `declaration_evidence` (spec/repo-format.md § v3.13), the FIRST
# grammar section shipped after v3 -- through the engine_features channel, with
# WPRISM_SPEC_VERSION left at 3 and asserted in the same run. Walks the three
# verdicts on a section v3 did not have (admitted / refused by FEATURE name /
# refused as a typo), the section's own closed grammar, and both halves of the
# deferral: the shipped library cannot adopt it without moving 18 digests, so
# the prose grep in regress_shipped_option_declarations.php stays and the schema
# check covers fixtures and out-of-tree adapters.
regress-structured-evidence:
	php sandbox/tests/offline/policy/regress_structured_evidence.php

regress-adapter-sources:
	bash sandbox/tests/offline/adapter/regress_adapter_sources.sh

# WP-1.3: the survey resolves the adapter library ONCE and refuses when it
# moves underneath. Counted rather than timed (constant whole-library work at
# 125/250/500 adapters, two decodes per row), plus the two mid-survey
# mutations -- a manifest appearing, and one rewritten in place -- that each
# have to refuse through a different half of the memo's witness.
regress-adapter-survey-scale:
	php sandbox/tests/offline/adapter/regress_adapter_survey_scale.php

regress-site-adapter-certification:
	php sandbox/tests/offline/adapter/regress_site_adapter_certification.php

# WP-4.7 / spec/repo-format.md § v3.6: a certificate binds the compatibility
# CELLS it was exercised against, not the platform document. The case the
# rider exists for -- an agent PATCH release that moves agent_version, an axis
# note and one already-exercised PHP patch leaves the certificate VALID -- is
# driven through a CHILD process, because one PHP process holds one
# WPRISM_AGENT_VERSION and "the agent released" is not otherwise expressible.
regress-certificate-axis-binding:
	php sandbox/tests/offline/adapter/regress_certificate_axis_binding.php

# WP-4.11 / spec/repo-format.md § v3.10: the reserved-but-refusing slots. Four
# attachment points -- a manifest `package` key, two certification statement
# members, a certification word and an evidence member -- each refusing with
# the sentence the spec publishes, read out of spec/repo-format.md rather than
# restated. It measures the three claims the reservation rests on: no verdict
# moves (same exception class, same consequence, as the ordinary refusal it
# replaces), the six-member statement's canonical bytes, preimage and Ed25519
# signature are fixed vectors so no certificate in the field moves, and opening
# the lane later is a POLICY flip -- which is register row R-28 and gate G5's
# condition 7 (spec/repo-format.md § v3.11). The manifest slot is driven in a
# CHILD process at WPRISM_SPEC_VERSION N+1: the closed key set is gated at
# spec_version 3 and a spec version is a define().
regress-v3-reservations:
	php sandbox/tests/offline/adapter/regress_v3_reservations.php

# WP-5.3 / spec/repo-format.md § v3.17: the signing profile that accepts a
# disposition the AUTHOR wrote. `wprism adapter certify --ratification-file`
# replaces sign_site()'s derivation and NOTHING else -- every semantic rule is
# still ManifestDispositions::validate_external_entry(), reached through the
# same chain the derived floor goes through, so the refusal matrix here is
# asserted on the sentences the SHIPPED validator already raised for a reviewed
# registry row (blank refusal prose, absent section, an unmarked intent-only
# table, a widened version range, a cited test the bundle does not hold, an
# entry that refuses nothing). The two refusals the profile adds are about
# SCOPE only. The floor still signs with no file supplied, and `adapter
# recertify` -- which derives -- reports an authored certificate as blocked
# rather than replacing the site's own argument with the canned one.
regress-authored-ratification:
	php sandbox/tests/offline/adapter/regress_authored_ratification.php

# WP-4.8 / spec/repo-format.md § v3.7: the authority record v2 grammar --
# fingerprint-derived key ids, the mandatory validity window and its named
# clock, the <vendor>-* namespace, and the self-signed envelope. Gated on the
# authorities DOCUMENT format, so the v1 control at the head of the suite is
# what proves the four rules did not leak out of their gate.
regress-authority-record-v2:
	php sandbox/tests/offline/adapter/regress_authority_record_v2.php

# WP-4.9 / spec/repo-format.md § v3.8: depth-1 delegated authorities. The
# refusal matrix IS the acceptance criterion -- a two-level chain refused BY
# NAME, a grant that widens its delegator's namespace/tier/window, a delegation
# claiming or rooted in a site key, and a revoked delegator invalidating its
# delegates. Every case is driven through authority(), the one selector every
# consumer reaches.
regress-authority-delegation:
	php sandbox/tests/offline/adapter/regress_authority_delegation.php

# WP-4.9 / § v3.8: the typed revocation record and its out-of-band channel.
# Closes the frozen-path gap -- a revoked VENDOR key held in a site root stops
# verifying frozen -- while asserting the operator-own-key asymmetry is
# unchanged and that the refusal text states the distinction between the two.
regress-revocation-reachability:
	php sandbox/tests/offline/adapter/regress_revocation_reachability.php

# WP-5.1 / spec/repo-format.md SS v3.7 + v3.8: POPULATING the platform trust
# root -- the enrollment CEREMONY end to end over fixture keys, in a scratch
# library, while the shipped adapter-authorities.json stays the empty v1
# registry byte for byte (issuing a real key is gate G4's decision, and
# docs/guides/trust-enrollment.md carries that checklist). Mint, enroll, sign
# the envelope, delegate, certify, and project BOTH operator-facing words;
# then the second-enrollment invariant, the grant's refusal matrix, and the
# REVOCATION DRILL on WP-1.4's rehearsal fleet with propagation latency
# measured. The drill runs in a child process (revocation_drill.php) because
# the estate's certificates are minted at the prior state and a state is a
# pair of define()s.
regress-platform-authority-population:
	php sandbox/tests/offline/adapter/regress_platform_authority_population.php

# WP-5.2 / spec/repo-format.md SS v3.16: the REVIEWER TIER -- admitting
# `evidence.reviewer` and minting `reviewer_signed`, the flip of two of the
# four slots SS v3.10 reserved as refusals. The acceptance is a trust claim, so
# it is driven end to end: a bundle produced in a FOREIGN evidence repository,
# over a test this repository holds no file and no target for, signed through
# the shipped `sign --evidence-repo=` verb, then re-verified after that
# repository is DELETED from disk. All three signed words are minted in one
# estate so the tier is measured against its neighbours; the reviewer word is
# refused to an unexercised bundle, to one whose reviewer is its own signer,
# and to free text; the cited-test rule is driven in all three arms (absent,
# manifest-fail, result-asset-fail); and a bundle naming no reviewer still
# produces the exact seven-member proof, which is what keeps every pin in the
# field binding (AGENTS.md rule 2).
regress-reviewer-evidence-tier:
	php sandbox/tests/offline/adapter/regress_reviewer_evidence_tier.php

# WP-4.12 / spec/repo-format.md § v3.8: the four adapter-side signature domains
# and the cross-ROOT clause, measured through the VERIFIERS. The corpus already
# asserted the four domain strings are distinct, NUL-terminated and pairwise
# prefix-free (regress_spec_v3_document.php:517-536, tools/wire-surface.php:
# 1869-1894) -- a sound argument that replay is impossible, and not a test of
# any verifier: a prefix-free set of strings nobody prepends separates nothing.
# So all 16 placements of 4 domains into 4 slots are driven here with ONE key,
# forged through the SHIPPED private framers by reflection: 12 refuse, the 4
# diagonals accept, and the 3 cells authoritiesSignatureBytes()'s (format, keys)
# signature cannot express are NAMED GAPS printed on every run and ratcheted
# both ways. Plus the two trust_root refusals nothing reached before --
# AdapterCertification.php:3480 via `trust_root` itself, and :1587-1593's
# vocabulary sentence, which occurred in no test anywhere.
regress-cross-root-replay:
	php sandbox/tests/offline/adapter/regress_cross_root_replay.php

# WP-5.4 / spec/repo-format.md SS v3.18: the COMPUTED evidence grade that sits
# beside the reviewed word. Three axes that already existed and projected into
# nothing -- coverage breadth (production-readiness.json's 12 scenario
# families), exercise depth (the bundle's per-test pass map at
# provenance.proof.bundle) and platform reach (platform.json's per-axis
# `verified` cells after SS v3.5 narrowing). The acceptance is that the number
# is DERIVED: it moves when any of the three inputs move, an authored `grade`
# member is refused BY NAME in every input, a subject with no exercise evidence
# carries no grade at all, and `certified` still means exactly what it meant --
# so the suite re-measures the shipped word through
# ManifestDispositions::report() and proves the whole grade model reaches no
# shipped byte at all.
regress-graded-claim:
	php sandbox/tests/offline/adapter/regress_graded_claim.php

# Product release gate: every source validator and generated artifact must
# still agree with its authority -- capability/disposition and package-owned
# evidence inputs are validated without a checked-in aggregate adapter
# inventory; the published branch-environment
# provider protocol with the boundary that enforces it, the adapter-authoring
# limitation ledger with tools/engine-gaps.json (whose closed-gap rows also
# assert the implementations they cite are still on disk), the irreversibility
# register with the signature domains, closed key sets and grammars the
# refusals consult, the public API snapshot and classmap with agent/src, the
# offline corpus include with the suite files on disk, and the computed evidence grades with
# the three evidence records they are arithmetic over (WP-5.4: a grade is
# re-derived on every run and never stored as a verdict somebody edits).
release-gate:
	php tools/capability-doc.php --check
	php tools/provider-protocol-doc.php --check
	php tools/engine-gap-doc.php --check
	php tools/wire-surface.php --check
	php tools/api-surface.php --check
	php tools/shipped-identity-inventory.php --check
	php tools/classmap-generate.php --check
	php tools/offline-corpus.php --check
	php tools/adapter-kit.php --check
	php tools/adapter-grade.php --check

regress-multisite-refusal:
	bash sandbox/tests/live/regress_multisite_refusal.sh

# Bounded Polylang + TEC co-install rewrite topology, including the shared
# fresh-process native action and provider-before-effect retry proof.
regress-polylang-tec-rewrite-coinstall:
	bash integration-scenarios/polylang-tec-rewrite-coinstall/tests/live/regress_polylang_tec_rewrite_coinstall.sh

# issue #3262: optional term/user interpreter hooks plus static-policy fallback;
# pure PHP adapter-package runtime/interpreter fixtures, no WordPress or
# docker.
regress-interpreter-policy:
	php sandbox/tests/offline/policy/regress_interpreter_policy.php

# issue #3222's one genuinely live leg: Deploy::code_mismatch()'s new THEME
# version_range check, called directly against a real bundled WordPress
# theme (twentytwentyfour, zero network installs) via `wp eval` — no
# site-repo/plan/apply pipeline needed, since code_mismatch() is a plain
# static function. Own sandbox/bin/pair.sh pair (asub3222tr 8918/8919).
regress-adapter-theme-range:
	bash sandbox/tests/live/regress_adapter_theme_range.sh

# issue #3487: the PLUGIN twin of the theme leg above, rebuilding the live proof
# #478 removed with spike_g_code.sh. Asserts code_mismatch()'s plugin row
# field by field in both directions against bundled plugins (akismet and the
# legacy single-file hello.php, zero network installs), including the
# min-inclusive/max-exclusive endpoints and the unreadable-version branch
# against a real get_plugins() read. Own pair (a3487pr 8988/8989 by default,
# parameterized: PLUGIN_RANGE_PAIR/PORT1/PORT2).
regress-adapter-plugin-range:
	bash sandbox/tests/live/regress_adapter_plugin_range.sh

# issue #3266/issue #3275: menu-item meta capture used to read a fixed 8-key
# allowlist and silently drop everything else, never reaching the
# unclassified-meta gate ordinary post_meta already has. Live, own pair
# (asub3275 8954/8955): runs the FULL canonical loud-gate -> pending ->
# classify -> clean-capture cycle (not just capture-time refusal) — a fake
# mega-menu plugin's meta key refuses capture, surfaces in `wp wprism pending`
# under section=post_meta with nav_menu_item in post_types (issue #3275: not a
# dead-end 'menu_item_meta' section), classifies via the exact `wp wprism
# classify --set` syntax pending suggests, then captures/applies/round-trips
# across two independent environments with real token resolution.
regress-menu-item-meta-gate:
	bash sandbox/tests/live/regress_menu_item_meta_gate.sh

regress-widgets:
	bash sandbox/tests/live/regress_widgets.sh

# issue #3216: offline host-orchestrator state-machine contract — one compiled
# artifact, pre-checkpoint lease, retire -> activate -> apply ordering, stop-on-first-
# failure, exact cleanup, and serialized transport-shaped restore instructions.
regress-promotion-unit:
	bash sandbox/tests/offline/recovery/regress_promotion_unit.sh

# Clean-install lifecycle snapshot boundary: captures options/core without
# entering plugin-owned typed-table validation before activation has created
# those tables. Kept in code-half-unit because this is the host lifecycle
# bridge, not a WooCommerce lookup/ecommerce harness.
regress-lifecycle-options-snapshot:
	php sandbox/tests/offline/code-half/regress_lifecycle_options_snapshot.php

regress-lifecycle-identity-preservation:
	php sandbox/tests/offline/capture/regress_lifecycle_identity_preservation.php

# Fatal-safe control-plane bootstrap: WPRISM_JOURNAL must not call WordPress
# option/filter APIs before after_wp_config_load has loaded the normal runtime.
regress-journal-bootstrap:
	php sandbox/tests/offline/recovery/regress_journal_bootstrap.php

# First functional code-half's fast, offline boundary suite: descriptor
# revision enforcement, truthful status rendering, template reconciliation
# detection, and both public host orchestration paths. Keep this separate
# from the Docker/live promotion regression below so it is cheap to run while
# iterating on the safety gates.
code-half-unit: regress-repository-compiler regress-code-revision-enforcement regress-code-descriptor-compiler regress-code-descriptor-unit regress-code-materializer-unit regress-code-ownership-pruner regress-code-completed-unit regress-code-stage-lock-unit regress-code-stage-transaction-unit regress-code-stage-unchanged-skip regress-code-ledger-transaction-unit regress-plan-summary-code-drift regress-template-mismatch regress-code-deploy-unit regress-deploy-command regress-promote-command regress-promotion-unit regress-lifecycle-state-handoff regress-lifecycle-phase-handoff-unit regress-plugin-dependency-order regress-lifecycle-options-snapshot regress-journal-bootstrap regress-code-compatibility

regress-repository-compiler:
	bash sandbox/tests/offline/repository/regress_repository_compiler.sh

# issue #3316: generalized taxonomy object keyspaces, full description
# json_refs/key_refs, attached structured-meta sidecar variants/two-sidecar
# refusal, load-time refusal matrices, frozen-policy parity, and compiler
# raw-id portability gates.
regress-reference-contract:
	php sandbox/tests/offline/grammar/regress_reference_contract.php

regress-coverage-offline:
	php sandbox/tests/offline/assess-contract/regress_coverage_offline.php

# Live: needs an already-up pair with WooCommerce active, e.g.
#   WPRISM_PAIR=mypair make regress-coverage
regress-coverage:
	bash sandbox/tests/live/regress_coverage.sh

regress-post-field-classification:
	php sandbox/tests/offline/grammar/regress_post_field_classification.php

regress-ecommerce-developer-static:
	bash sandbox/tests/offline/guards/regress_ecommerce_developer_static.sh

regress-ecommerce-developer-matrix:
	bash sandbox/tests/offline/guards/regress_ecommerce_developer_matrix.sh

regress-ecommerce-extension-migration:
	php sandbox/tests/offline/ecommerce/regress_ecommerce_extension_migration.php

regress-capture-atomicity:
	php sandbox/tests/offline/capture/regress_capture_atomicity.php

regress-capture-record-readback:
	php sandbox/tests/offline/capture/regress_capture_record_readback.php

# WP-2.7: replay a recorded wprism-conformance-vector/v1 through the real engine
# (FakeWpdb seeded from the recorded rows, FrozenPolicy loading the adapter),
# and prove the replay verdict is a DISTINCT, WEAKER word than the live
# conformance verdict the vector was recorded under. Recording is a flag on
# sandbox/conformance/run.sh (CONF_RECORD_VECTOR); this leaf needs no pair.
regress-conformance-vector-replay:
	php sandbox/tests/offline/capture/regress_conformance_vector_replay.php

regress-action-scope:
	php sandbox/tests/offline/adapter/regress_action_scope.php

regress-provider-contract:
	php sandbox/tests/offline/adapter/regress_provider_contract.php

regress-provider-option-surfaces:
	php sandbox/tests/offline/adapter/regress_provider_option_surfaces.php

# issue #3338: the structured native-action vocabulary and the plugin-owned
# provider contract. One target, two harnesses (see the wrapper's header):
# the load-time half never stubs a WordPress function, the runtime half stubs
# exactly the lifecycle primitives negotiation reads. regress-provider-contract
# above stays addressable on its own for iterating on that half alone; the
# bundle entry is this wrapper, so neither harness runs twice.
regress-actions-providers:
	bash sandbox/tests/offline/adapter/regress_actions_providers.sh

regress-core-rewrite-native-action:
	php sandbox/tests/offline/adapter/regress_core_rewrite_native_action.php

regress-wp-cli-child-process:
	php sandbox/tests/offline/guards/regress_wp_cli_child_process.php

# issue #3338 live counterpart: a custom sandbox plugin advertising its OWN
# provider through the `wprism_providers` filter, negotiated and invoked against a
# real target. Own pair, so it is live-list material, never offline-all.
regress-provider-contract-live:
	bash sandbox/tests/live/regress_provider_contract_live.sh

# issue #3340 live adapter-authoring exercise: controlled plugin source, journal /
# pending review, inert host draft, shipped-manifest graduation, and provider /
# native-action target convergence. Own disposable pair; never part of the
# offline count.
.PHONY: regress-adapter-authoring-live adapter-authoring-exercise
regress-adapter-authoring-live:
	bash sandbox/tests/live/regress_adapter_authoring_live.sh

adapter-authoring-exercise: regress-adapter-authoring-live

# issue #3348 first extraction slice: ManifestGrammar (table/widget declaration
# grammar out of Policy.php), OFFLINE (no WordPress, DB, providers, or docker)
# — belongs in regress-offline-all and its count.
regress-manifest-grammar:
	php sandbox/tests/offline/policy/regress_manifest_grammar.php

# issue #3345 (#189): value-free plan category summaries, OFFLINE (no WordPress,
# DB, providers, or docker) — belongs in regress-offline-all and its count.
regress-plan-category-summary:
	php sandbox/tests/offline/cli/regress_plan_category_summary.php

# issue #3345 (#189): the LIVE counterpart. Its own pair +
# PLAN_CATEGORY_SUMMARY_PAIR/PORT1/PORT2 — belongs in regress-live-list, NEVER
# the offline count.
regress-plan-category-summary-live:
	bash sandbox/tests/live/regress_plan_category_summary_live.sh

# issue #3345 (bounded plan-view slice): offline proof of the explicit
# same-snapshot filtered projection, canonical AND/OR request grammar,
# UUID ordering/cap, value-free references, full safety/readiness evidence,
# host validation/legacy refusal, and control-byte-safe new itemization.
regress-plan-view:
	php sandbox/tests/offline/cli/regress_plan_view.php

# issue #3317 live counterpart: a provider whose declared `requires` names an
# environment this target does not have refuses before the first mutation, then
# the identical apply against the shipped adapter (which declares no such
# requirement) converges. Bundle-free — the requirement is supplied through a
# test-manifests overlay, the shipped manifest bytes stay byte-identical. Own
# pair, so it is live-list material, never offline-all.
regress-provider-requirements-live:
	bash sandbox/tests/live/regress_provider_requirements_live.sh

# issue #3318: ownership of the engine's closed manifest vocabularies, the safe
# extension points around them, and the parent-scoped multi-column natural key
# they were written down for. A second adapter is the fixture: every negative
# is a well-formed manifest reaching into another manifest's entities or
# minting a value the engine owns.
regress-vocabulary-ownership:
	php sandbox/tests/offline/policy/regress_vocabulary_ownership.php

# issue #3374: the close gate's squash-parent count, header-scoped — proven
# against scratch commits including the message-body shape that false-failed
# a live close (see the suite's own header).
regress-close-gate-parent-count:
	bash sandbox/tests/offline/guards/regress_close_gate_parent_count.sh

# issue #3327: `wprism manifest-validate`, the adapter author's offline grammar check,
# and the machine-readable grammar document it emits from the engine's own
# closed vocabularies. Drives the real host CLI as a subprocess against scratch
# manifest fixtures (shared with the issue #3318 ownership suite), against scratch
# site repos (the two guards that read site.wprism.json as input, asserted both
# ways), and against the shipped adapter-package and platform-library sources
# with and without --site.
# Authoring aid, not a gate on anything.
regress-manifest-validate:
	bash sandbox/tests/offline/policy/regress_manifest_validate.sh

# WP-2.4(a): `wprism lint-tree`, the linter as a WordPress-free host verb. Drives
# the REAL `wp wprism lint` handler (through a WP_CLI stub) and the REAL host
# binary as a subprocess over one recorded wprism-lint-environment/v1 transcript,
# and compares raw stdout bytes both ways. One child run poisons get_option()/
# untrailingslashit()/$wpdb to throw, so "WordPress-free" is asserted
# positively. The one class a host process cannot perform (WordPress's block
# parser) is asserted to be DEFERRED and named, never silently dropped.
regress-lint-host-verb:
	php sandbox/tests/offline/cli/regress_lint_host_verb.php

# WP-2.4(b): a bare_id on a custom-table column whose live MySQL type bounds it
# to {0,1} is emitted as a PROPOSED lint_ok carrying that type as its premise.
# Reproduces the measured Ninja Forms 6-of-8 split over the shipped manifest
# with its reviewed lint_ok declarations stripped: six BIT(1) columns proposed,
# nf3_actions.active and nf3_fields.order left on the reviewer's desk. Asserts
# the finding COUNT never drops — a proposal is a re-class, not a silence.
regress-lint-type-exemptions:
	php sandbox/tests/offline/policy/regress_lint_type_exemptions.php

# issue #3325: `wprism adapter-draft`, the safe adapter-DRAFT generator (offline slice).
# Reuses policy-to-manifest's facts core (Policy::export_manifest) and adds OFFLINE
# proposers over a site repo's captured state/**, emitting inert `_draft` candidates
# a human ratifies by hand. Drives the real host CLI as a subprocess against scratch
# site-repos, feeds the draft to the REAL manifest-validate, and proves the
# load-bearing INERTNESS property (an undeclared id_kind under _draft stays ok; the
# same fragment with one trigger key un-renamed FAILS the closed-vocabulary refusal).
# Offline: pure PHP/file-I/O, no docker, no WordPress.
regress-adapter-draft:
	bash sandbox/tests/offline/adapter/regress_adapter_draft.sh

# WP-2.1: `wp wprism adapter-probe`, the live half of adapter-draft's `--evidence=`
# seam. Emits wprism-adapter-probe/v1 -- real PK, column types/nullability, unique
# keys, per-column index coverage in DeleteGuardEvaluator::lock_index()'s own
# terms, FOREIGN KEY presence, EAV twin shape and one COUNT vs COUNT DISTINCT --
# and proposes nothing. Offline: the shared FakeWpdb for the SHOW TABLES/SHOW
# COLUMNS half, recorded fixtures for the three statements it deliberately
# declines (SHOW INDEX, information_schema, COUNT(DISTINCT col)), and the real
# AdapterDraft consumer for the seam.
regress-adapter-probe:
	php sandbox/tests/offline/adapter/regress_adapter_probe.php

# WP-2.5: `wp wprism adapter-deletion-feasibility`, DeleteGuardEvaluator::
# lock_index() run at AUTHORING time. Emits wprism-deletion-feasibility/v1 -- per
# proposed guard, the covering index or a null with the reason ('no index leads
# with this column' / 'prefix index of N bytes cannot cover a declared key of
# M'). The verdict is lock_index()'s own return value and an explanation that
# disagrees with it refuses, so the report cannot drift into a second
# implementation of the rule. It answers FEASIBILITY only: no capability, no
# cascade set, no ratification -- the note at
# adapter-packages/ninja-forms/package/manifest.json:17, "does not advertise
# table:nf3_forms deletion", stays a human's sentence, and the suite
# asserts the tool cannot reach it. Offline: the shared FakeWpdb for SHOW
# TABLES LIKE, recorded fixtures for the SHOW INDEX it declines.
regress-deletion-feasibility:
	php sandbox/tests/offline/adapter/regress_deletion_feasibility.php

# WP-2.2: `wprism adapter boundary`, version-range bisection that emits EVIDENCE
# and can never emit a manifest edit. Drives the real AdapterBoundary::search()
# over recorded outcome tables: the 13 bisection-shaped blocks of
# the convention-discovered artifact library each reproduce from their own rows,
# 64 synthetic releases resolve in 11 probes against a 12-probe bound, a
# failing release is never proposed as a boundary in any of 30 windows, a
# recorded contradiction inside the settled window BLOCKS rather than
# narrowing, an unresolvable artifact is reported rather than counted as a
# failing release, and every shipped adapter-package/platform-library source
# file is byte-identical before and after -- including across the real
# subprocess run that reads acf's
# declared range. Offline: pure PHP plus the shipped artifact-fragment parser.
regress-adapter-boundary-search:
	php sandbox/tests/offline/adapter/regress_adapter_boundary_search.php

# WP-2.9: `wprism adapter proposals` -- the scheduled job around that planner.
# Re-bisects every pinned plugin from its own recorded ledger and emits the
# range bump as BOTH edits (manifest version_range + disposition
# supported_versions), proven acceptable by the REAL
# ManifestDispositions::assert_entry() with both applied and refused by it with
# either one alone -- the Canon-byte-equal shape :632-637 demands. A bisection
# that never reached green is refused rather than proposed, as is a range that
# would contain a recorded failing release. Freshness (last_verified = the
# newest green probe, platform.json's own per-axis shape) is DERIVED: a ledger
# asserting its own is refused, and the census ranks the rows by sites pinning
# x releases behind while keeping its names-and-counts redaction. Every file
# under the shipped adapter-package/platform-library sources is byte-identical
# before and after. Offline: pure PHP plus
# the real wprism executable.
regress-boundary-proposals:
	php sandbox/tests/offline/adapter/regress_boundary_proposals.php

# issue #3408: the shared conformance assertion fragment -- every require_*
# helper the seeds/postdeploy hooks call must be defined in ONE fragment both
# sourcing harnesses load, or bundle leg 12 dies at `command not found`.
# Offline: pure grep over the harness sources.
regress-conformance-asserts:
	bash sandbox/tests/offline/guards/regress_conformance_asserts.sh

# issue #3409: the core sweep's `wprism explain` envs registry allocates a per-run
# private directory (portable across GNU/BSD mktemp); prove it is collision-safe
# offline. Pure shell/file-I/O, no docker -- belongs in regress-offline-all.
regress-explain-registry:
	bash sandbox/tests/offline/guards/regress_explain_registry.sh

# issue #3413: the core sweep's strict-explain post-conditions must name
# infrastructure, not the engine, on an empty-at-exit-0 db export; prove the
# premise guard and the evidence-pasting offline. Pure shell, no docker.
regress-explain-export-premise:
	bash sandbox/tests/offline/guards/regress_explain_export_premise.sh

# issue #3401: the core sweep's non-wprism wp-cli OBSERVATION reads (post get/list,
# comment get, db query on target state) must name infrastructure, not the
# engine, on an empty-at-exit-0 compose death; prove require_observed_nonempty's
# domain behavior and that each guarded call site captures+guards before
# comparing (reverting a guard fails the pins). Pure shell, no docker.
regress-observation-guards:
	bash sandbox/tests/offline/guards/regress_observation_guards.sh

# issue #3400: the three older live regressions do not source the shared
# conformance assertion fragment, so their compose-death boundary is a small
# explicit source contract: preserve the real refusal status (1), capture the
# complete transport output, and print both when the transport misroutes.
# Offline: static plus mutation-proven shell source checks; no Docker.
regress-live-exit-code-contract:
	bash sandbox/tests/offline/guards/regress_live_exit_code_contract.sh

# issue #3423: family-wide inventory of live target reads used by conformance
# post-conditions. Every non-empty observation is premise-guarded before its
# comparison/accusation; expected-empty absence and clean-git predicates are
# explicitly inventoried instead of being misclassified as fixture failures.
# Pure source/static contract, no docker.
regress-target-observation-premises:
	bash sandbox/tests/offline/guards/regress_target_observation_premises.sh

regress-polylang-live-fixtures:
	bash sandbox/tests/offline/guards/regress_polylang_live_fixtures.sh

# issue #3366: certify_version_matrix.sh must delete Elementor's active-kit
# reference before site empty removes its post, and must fail on the exact
# null-post warning paths at the upper Elementor boundary. Static, offline.
regress-elementor-matrix-reset:
	bash sandbox/tests/offline/guards/regress_elementor_matrix_reset.sh

# issue #3362: grind_r1c_agency.sh must not regenerate the committed
# adapter-packages/wprism-agency-cpt/package/manifest.json wholesale (that would
# delete its hand-authored providers/actions); it exports to a scratch path and
# verifies instead. Static.
regress-grind-r1c-manifest-preserve:
	bash sandbox/tests/offline/guards/regress_grind_r1c_manifest_preserve.sh

# issue #3339: the installed-adapter catalog -- `wprism adapter list|inspect|doctor`
# over both adapter sources the engine has, plus AdapterSources::survey(), the
# reporting half of the source scan. Drives the real host CLI as a subprocess
# against the REAL shipped library and scratch site repositories, and pins the
# architectural claim of the split by comparing each reported refusal against
# the message AdapterSources::discover() throws for the same fixture, byte for
# byte. Offline: file I/O and pure PHP only.
# issue #3339 slice B2 (#187): the plugin adapter source. Its Makefile target was
# dropped in a #151 Makefile conflict resolution (issue #3417); the suite is
# offline and belongs in regress-offline-all.
regress-plugin-adapter-source:
	bash sandbox/tests/offline/adapter/regress_plugin_adapter_source.sh

regress-adapter-catalog:
	bash sandbox/tests/offline/adapter/regress_adapter_catalog.sh

# Exact shipped inventories plus hostile loader mutations for the five
# 2026-08-22 ecosystem drafts; this is product-path evidence, not a fixture.
regress-ecosystem-adapter-batch:
	php sandbox/tests/offline/adapter/regress_ecosystem_adapter_batch.php

# The hand-reviewed production-readiness work ledger: exact shipped-adapter
# coverage, all twelve hostile scenario families, grounded evidence paths, and
# a hard refusal to spell `ready` while an applicable family is still open.
regress-adapter-production-readiness:
	php sandbox/tests/offline/adapter/regress_adapter_production_readiness.php

# issue #3340: one target-owned, value-redacted AdapterSources/pending/journal
# observation plus strict host transport/hash/create-only validation. Offline:
# fake wpdb/WP hooks only; this intentionally does not claim the separate
# two-environment live exercise harness.
regress-adapter-observation:
	bash sandbox/tests/offline/adapter/regress_adapter_observation.sh

# issue #3318 live counterpart: the parent-scoped natural key through capture,
# deploy, apply, rename, and independent recapture across two environments
# whose local ids genuinely differ. Own pair, so it is live-list material,
# never offline-all.
regress-parent-scoped-natural-key:
	bash sandbox/tests/live/regress_parent_scoped_natural_key.sh

regress-code-revision-enforcement:
	php sandbox/tests/offline/code-half/regress_code_revision_enforcement.php

regress-code-descriptor-compiler:
	php sandbox/tests/offline/code-half/regress_code_descriptor_compiler.php

# issue #3499, the code-half split. Four suites for four separable claims: the
# lock grammar refuses every malformed declaration by name; the compile gate
# refuses an unresolved, drifted or Git-excluded-but-undeclared component and
# cannot be forced; `wprism init --code=split` classifies on the HOST against real
# archive bytes and the agent verifies rather than trusts; and `wprism code-classify`
# migrates an existing repository to the identical code_revision. None contacts
# the network -- the release registry is a local file:// fixture.
regress-code-source-lock:
	php sandbox/tests/offline/code-half/regress_code_source_lock.php

regress-code-lock-compile-gate:
	php sandbox/tests/offline/code-half/regress_code_lock_compile_gate.php

regress-init-code-split:
	php sandbox/tests/offline/code-half/regress_init_code_split.php

regress-code-classify:
	php sandbox/tests/offline/code-half/regress_code_classify.php

# issue #3500, the resolver issue #3499 deliberately did not ship. One suite for one
# claim: the bytes a lock declares can be put back on the HOST, verified twice
# (archive digest before unpack, tree digest after), materialized atomically,
# and never silently -- a corrupt cache, a re-released archive, a drifted
# component and an ssh target each refuse by name. Contacts no network: the
# registry is a local file:// fixture whose archives the suite builds itself.
regress-code-resolve:
	php sandbox/tests/offline/code-half/regress_code_resolve.php

# issue #3514, the host->target half `wprism code-resolve` refused before: an ssh
# environment resolves on the HOST into a throwaway staging worktree, ships one
# tar, and verifies the trees TARGET-SIDE in .wprism/code-push against the lock's
# tree_sha256 before anything is renamed into code/wp-content. The fixture
# transport runs the target-side scripts through sh and answers code-inventory
# with the agent's own component_inventory(), so the ordering, the tar members
# and the digests are the real ones. Against the prior build the ssh arm
# refuses code_resolve_transport_unsupported and nothing is pushed at all.
regress-code-resolve-push:
	php sandbox/tests/offline/code-half/regress_code_resolve_push.php

# The host verb that makes a component with no wp.org release lockable instead
# of carried in Git: `wprism code-import <archive.zip>` puts the operator's
# archive into the host's content-addressed cache and prints the digests the
# lock records. The store semantics are pinned beside the classification in
# regress_init_code_split.php; this suite pins the command surface and its
# refusals. Contacts no network: the verb never fetches anything.
regress-code-import:
	php sandbox/tests/offline/code-half/regress_code_import.php

# issue #3348 slice 25: the optional site.wprism.json code envelope grammar moved
# out of Policy.php into CodeConfigGrammar.php. Code's descriptor compiler and
# materialization paths remain the runtime owners; this direct suite proves
# the exact wrapper diagnostics and both Policy loader entry points.
regress-code-config-grammar:
	php sandbox/tests/offline/policy/regress_code_config_grammar.php

regress-agent-src-requires:
	php sandbox/tests/offline/guards/regress_agent_src_requires.php

# The shared database boundary must distinguish physical session generations,
# settle replayed data-free controls, and retain the original transaction's
# savepoint witness across ambiguous responses on both supported SQL engines.
regress-db-transaction-authority:
	php sandbox/tests/offline/guards/regress_db_transaction_authority.php

.PHONY: regress-db-repeatable-read-authority
regress-db-repeatable-read-authority:
	php sandbox/tests/offline/guards/regress_db_repeatable_read_authority.php

.PHONY: regress-locked-embedded-uuid-owners
regress-locked-embedded-uuid-owners:
	php sandbox/tests/offline/repository/regress_locked_embedded_uuid_owners.php

.PHONY: regress-ledger-large-values
regress-ledger-large-values:
	php sandbox/tests/offline/repository/regress_ledger_large_values.php

# Provider callbacks use the engine-owned database and process boundaries;
# these suites pin settlement and the fixed digest-bound child protocol rather
# than allowing adapter capsules to grow their own transaction/process loops.
regress-provider-database-session:
	php sandbox/tests/offline/guards/regress_provider_database_session.php

.PHONY: regress-physical-table-rows
regress-physical-table-rows:
	php sandbox/tests/offline/guards/regress_physical_table_rows.php

.PHONY: regress-native-option-inputs
regress-native-option-inputs:
	php sandbox/tests/offline/guards/regress_native_option_inputs.php

.PHONY: regress-native-post-types
regress-native-post-types:
	php sandbox/tests/offline/guards/regress_native_post_types.php

.PHONY: regress-native-permalinks
regress-native-permalinks:
	php sandbox/tests/offline/guards/regress_native_permalinks.php

.PHONY: regress-native-permalinks-live
regress-native-permalinks-live:
	bash sandbox/tests/live/regress_native_permalinks_live.sh

regress-provider-operation-process:
	php sandbox/tests/offline/guards/regress_provider_operation_process.php

regress-code-descriptor-unit:
	bash sandbox/tests/offline/code-half/regress_code_descriptor_unit.sh

regress-code-materializer-unit:
	bash sandbox/tests/offline/code-half/regress_code_materializer_unit.sh

# issue #3350 slice 5: removal authority (remove_old_owned_files/
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
	php sandbox/tests/offline/code-half/regress_code_ownership_pruner.php

regress-code-completed-unit:
	bash sandbox/tests/offline/code-half/regress_code_completed_unit.sh

regress-code-stage-lock-unit:
	bash sandbox/tests/offline/code-half/regress_code_stage_lock_unit.sh

regress-code-stage-transaction-unit:
	bash sandbox/tests/offline/code-half/regress_code_stage_transaction_unit.sh

# issue #3501: code-stage leaves a target row alone when it already holds the
# descriptor's exact bytes and mode, instead of temp+renaming all 8,918 files
# of a real payload on every deploy. Every per-row refusal in the suite is set
# up with a target that already matches, so a skip decided before those checks
# fails here instead of shipping.
regress-code-stage-unchanged-skip:
	php sandbox/tests/offline/code-half/regress_code_stage_unchanged_skip.php

regress-code-ledger-transaction-unit:
	bash sandbox/tests/offline/code-half/regress_code_ledger_transaction_unit.sh

regress-plan-summary-code-drift:
	php sandbox/tests/offline/cli/regress_plan_summary_code_drift.php

# issue #3345 (plan naming slice): offline, no docker — plan rows carrying an
# authored WordPress name (post title, term/menu name) render it beside the
# repository path in wprism status; rows without one render exactly as before.
# The agent-side twin renderer is proven live by the core conformance
# check's plan-naming scenario.
regress-plan-title-render:
	php sandbox/tests/offline/cli/regress_plan_title_render.php

# issue #3345 (three-way conflict slice): the stable JSON evidence and host
# summary distinguish target last-synced base, repository intent, target
# intent, non-destructive reconciliation, and the loud destructive override.
# The agent-side renderer and real Apply plan rows are proven by core
# conformance's branch-vs-target and deletion-conflict scenarios.
regress-conflict-view:
	php sandbox/tests/offline/apply/regress_conflict_view.php

# issue #3347 slice 1: ConvergenceVerifier was extracted from Apply's post-apply
# convergence gate; retain its offline characterization in the release bundle.
regress-convergence-verifier:
	php sandbox/tests/offline/apply/regress_convergence_verifier.php

# issue #3489: what an apply over a drifted target tells the operator (the gate's
# diagnosis crossing the verify-canonical subprocess boundary, the "was the
# target mutated" answer, the preserved-drift cause and remedy) and what its
# retry does to the drift it preserved.
regress-apply-drift-convergence:
	php sandbox/tests/offline/apply/regress_apply_drift_convergence.php

# WP-2.8: the graduated outside_version_range verdict — the third state between
# "inside the certified window" and "deploy blocked", and the four ways it must
# refuse to fire. Most of the suite is the refusals: a verdict that assumed
# benignity when nothing was recorded would be the silent fallback rule 9
# forbids, so absent, partial and contradicted evidence each still block with
# the pre-existing message byte-for-byte.
regress-graduated-version-range:
	php sandbox/tests/offline/apply/regress_graduated_version_range.php

# issue #3502: a FULL apply without --with-deletes performs no planned deletion at
# all — ApplyPreparationCoordinator refused only the scoped case, the executor
# skipped its delete block, and the revision was still recorded as applied — so
# the operator's only signal was "delete":1 beside "deleted":0 in the receipt
# JSON. The suite pins the exact warning, the branch that emits it, the scoped
# refusal beside it, and what the ledger records either way.
regress-delete-authorization-receipt:
	php sandbox/tests/offline/apply/regress_delete_authorization_receipt.php

# issue #3347 slice 2: ApplyPlanner was extracted from Apply's plan/conflict
# production (conflict_view, forced/incomplete override evidence, display
# titles, lifecycle comparison, nested-delete counts); direct-API proof
# complementing regress_conflict_view.php's/regress_plan_title_render.php's/
# regress_lifecycle_state_handoff.php's/regress_plan_category_summary.php's
# existing reflection-based coverage of the same methods through Apply.
regress-apply-planner:
	php sandbox/tests/offline/apply/regress_apply_planner.php

.PHONY: regress-plan-reference-adoption
regress-plan-reference-adoption:
	php sandbox/tests/offline/apply/regress_plan_reference_adoption.php

regress-table-graph:
	php sandbox/tests/offline/repository/regress_table_graph.php

regress-table-schema:
	php sandbox/tests/offline/repository/regress_table_schema.php

# issue #3349: direct certification for Capture's extracted read-only live-scope
# boundary: gaps, post/term rosters, taxonomy ownership, and observation checks.
regress-scope-discovery:
	php sandbox/tests/offline/reference-scope/regress_scope_discovery.php

# issue #3349: direct certification for Snapshot's extracted mapped, natural,
# and composite typed-row identity state machine plus its compatibility facade.
regress-snapshot-identity:
	php sandbox/tests/offline/repository/regress_snapshot_identity.php

# issue #3349: direct certification for the extracted typed-table read pipeline:
# ordinary/composite rows, attached meta, secret refusal, and canonical bytes.
regress-typed-table-capture:
	php sandbox/tests/offline/capture/regress_typed_table_capture.php

# issue #3349: direct certification for the extracted typed-table write pipeline:
# phase ordering, refs/meta, invalidation, reparenting, deletes, and composite upsert.
regress-typed-table-materializer:
	php sandbox/tests/offline/apply/regress_typed_table_materializer.php

regress-snapshot-pruner:
	php sandbox/tests/offline/repository/regress_snapshot_pruner.php

# issue #3347 slice 3: the shared policy-owned authored-field materializer
# delegates post/term reconciliation and checked meta/option writes while
# retaining Apply's compatibility facades. Existing termmeta coverage drives
# the policy-aware path; this direct suite pins the moved low-level behavior.
regress-apply-field-materializer:
	php sandbox/tests/offline/apply/regress_apply_field_materializer.php

# The locked target context an authored meta roster is rechecked against once
# the owner range lock is held. #556 (18f32d13) added that recheck against the
# PRE-WRITE rows, which rejects every sibling-classified interpreter key on an
# owner an apply is about to create -- `VMATRIX_MANIFEST=acf bash
# sandbox/tests/certify/certify_version_matrix.sh` died at the acf 6.0.0 target
# apply with "wprism: authored post meta '_wprism_related' disagrees with the locked
# target context". Drives the real
# `adapter-packages/acf/package/runtime/interpreters/acf.php` through the post,
# term and user materializers, and keeps #556's two protections pinned.
regress-authored-meta-context:
	php sandbox/tests/offline/apply/regress_authored_meta_context.php

# issue #3347 slice 4: MenuMaterializer was extracted from Apply's menu entity
# reconciliation (finalize_menu/assign_locations), on top of slice 3's shared
# ApplyFieldMaterializer. Deliberately a wiring/shape proof only -- full
# behavioral coverage stays in regress_lifecycle_options_snapshot.php (still
# green unchanged through Apply's facade) and live conformance.
regress-menu-materializer:
	php sandbox/tests/offline/apply/regress_menu_materializer.php

# issue #3347 slice 5: UserMetaMaterializer was extracted from Apply's user
# entity reconciliation (finalize_user_meta, plus its exact-login resolver),
# on top of slice 3's shared ApplyFieldMaterializer. Deliberately a
# wiring/shape proof only -- full behavioral coverage (exact-login boundary
# across divergent numeric ids, authored/runtime classification, ref
# decoding) stays in regress_user_meta.sh's live conformance run.
regress-user-meta-materializer:
	php sandbox/tests/offline/apply/regress_user_meta_materializer.php

# issue #3347 slice 6: TermMaterializer was extracted from Apply's term entity
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
	php sandbox/tests/offline/apply/regress_term_materializer.php

# issue #3347 slice 7: apply_options()/option_apply_target()/
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
	php sandbox/tests/offline/apply/regress_options_materializer.php

# issue #3347 slice 8: reconcile_relationships()/delete_post_relationships()/
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
	php sandbox/tests/offline/apply/regress_relationship_materializer.php

# issue #3347 slice 9: place_attachment() moved from Apply.php into a new
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
	php sandbox/tests/offline/apply/regress_attachment_materializer.php

# Full public ApplyRequestCoordinator recovery: authored commit, the
# post-authored lease refusal, durable attachment journal handoff, and the
# second request's native metadata pass before cross-process convergence.
regress-full-apply-attachment-recovery:
	php sandbox/tests/offline/apply/regress_full_apply_attachment_recovery.php

.PHONY: regress-authored-work-units
regress-authored-work-units:
	php sandbox/tests/offline/apply/regress_authored_work_units.php

# issue #3347 slice 10: ensure_post_row() moved from Apply.php into a new
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
	php sandbox/tests/offline/apply/regress_post_materializer.php

# issue #3347 slice 12: delete_entity()/assert_zero() moved from Apply.php into
# a new DeleteExecutor.php (the executor half of the "DeleteGuardEvaluator/
# DeleteExecutor" target seam -- guard evaluation, i.e. build_plan()'s own
# collision detection that decides whether a delete is authorized at all,
# stays out: it is entangled with ApplyPlanner, a materially larger and
# riskier cut than this already-decided, already-authorized row deletion,
# and issue #3347's own guardrail against changing conflict semantics or
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
	php sandbox/tests/offline/apply/regress_delete_executor.php

# issue #3347 slice 18: DeleteGuardValueCodec owns the fail-closed decoding of
# target-controlled metadata and canonical repair tokens. The broader
# manifest/lock/witness product path remains in regress-woocommerce-deletion-authority.
regress-delete-guard-value-codec:
	php sandbox/tests/offline/apply/regress_delete_guard_value_codec.php

# issue #3347 guard seams: DeleteGuardEvaluator owns the index-prefix proof that
# makes a guard's locking read a real gap boundary, the generic per-guard
# findings contract, plan-time delete-bucket annotation, final recheck
# refusal/findings, and locked witness revalidation. The broader
# manifest/reference product path remains in the Woo deletion suite; Apply
# retains transaction lifecycle, target-fact callbacks, warning formatting,
# and mutation authority.
regress-delete-guard-evaluator:
	php sandbox/tests/offline/apply/regress_delete_guard_evaluator.php

regress-executable-tree-identity:
	php sandbox/tests/offline/apply/regress_executable_tree_identity.php

regress-php-literal-data:
	php sandbox/tests/offline/guards/regress_php_literal_data.php

# issue #3348 slice 2: CompiledRepository/RepositoryCompilationException moved
# out of RepositoryCompiler.php into their own CompiledArtifact.php (the
# "CompiledArtifact value object" target seam); proves the file loads and
# round-trips standalone, complementing regress_repository_compiler.sh's/
# regress_plan_explain.php's/etc. existing in-depth coverage through the
# real compiler.
regress-compiled-artifact:
	php sandbox/tests/offline/repository/regress_compiled_artifact.php

# issue #3350 slice 1: PathSafety was extracted from Code's path-traversal/
# symlink-crossing guards. Proves real filesystem symlink detection (not
# just string shape) survived the move and that Code's ten kept facades
# delegate rather than duplicate the logic.
regress-path-safety:
	php sandbox/tests/offline/code-half/regress_path_safety.php

# issue #3348 slice 4: adapter provenance / capability-readiness resolution
# (manifest_disposition, capability_claim, certification_readiness_blockers,
# adapter_readiness_blockers, provider_readiness_blockers, capability_report)
# moved out of Policy.php into AdapterRegistry.php (the "AdapterRegistry and
# capability resolver" target seam). Proves the extraction: Policy's facades
# genuinely delegate rather than duplicate, and AdapterRegistry.php requires
# its own dependencies standalone; existing suites
# (regress_actions_providers.php, regress_manifest_dispositions.php, etc.)
# already cover the underlying business logic in depth through real fixtures.
regress-adapter-registry:
	php sandbox/tests/offline/adapter/regress_adapter_registry.php

# issue #3348 slice 5: manifest-pin normalization/validation (normalize_manifest_
# pins, validate_manifest_sources, validate_manifest_pins) moved out of
# Policy.php into PinResolver.php (the "PinResolver" half of the "ManifestLoader
# / PinResolver" target seam; load()/from_snapshot() themselves stay on
# Policy). All three were pure, so this suite drives them directly rather than
# through a full Policy::load() cycle; existing suites (regress_adapter_sources.php,
# regress_adapter_contract.php, regress_site_adapter_certification.php, etc.)
# already cover the same logic in depth through the real pin flow, including
# the digest-mismatch branch this file deliberately leaves untouched.
regress-pin-resolver:
	php sandbox/tests/offline/policy/regress_pin_resolver.php

# issue #3348 slice 6: the action/provider/effect declaration grammar
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
	php sandbox/tests/offline/adapter/regress_action_provider_grammar.php

# issue #3348 slice 7: the cross-manifest "one owner, no contradiction" guard
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
	php sandbox/tests/offline/policy/regress_cross_manifest_guards.php

# issue #3348 slice 8: the "named sub-key of an otherwise-atomic manifest
# value" declaration grammar (validate_sub_keys() for options.*.sub_keys,
# validate_dynamic_options() for the top-level dynamic_options key, plus
# their shared private assert_sub_key_parent_has_no_value_fields() helper
# and each method's own constant) moved out of Policy.php into
# SubKeyGrammar.php. The two methods share one inner shape and the same
# private helper -- the real coupling that justifies moving both together,
# not an assumption. Policy::CLASSES stayed on Policy (11 call sites across
# the file, not exclusive to this cluster), visibility widened private ->
# public. regress_dynamic_options_policy.php and regress_reference_contract.php
# already cover most of this cluster's real refusal behavior through
# Policy::load()/from_snapshot(), unchanged by the move; this suite is
# validate_sub_keys()'s own first direct behavioral proof (no dedicated test
# matched its exact refusal text anywhere in the repo before this file) plus
# structural/no-facade confirmation.
regress-sub-key-grammar:
	php sandbox/tests/offline/grammar/regress_sub_key_grammar.php

# issue #3348 slice 9: exact and taxonomy-pattern object_keyspace declaration
# grammar moved out of Policy.php into TaxonomyGrammar.php. The live
# taxonomy_object_keyspace() resolver remains on Policy because Capture, Apply,
# Lint, and RepositoryCompiler call it at runtime; this suite proves the pure
# load-time validator's closed vocabulary, indexed diagnostics, and no-facade
# extraction directly. regress_taxonomy_object_keyspace.php continues to cover
# the complete Policy::load()/from_snapshot() and runtime product paths.
regress-taxonomy-grammar:
	php sandbox/tests/offline/grammar/regress_taxonomy_grammar.php

# issue #3348 slice 11: option-name reference declaration grammar and the
# cross-manifest identical-pattern guard moved out of Policy.php into
# OptionReferenceGrammar.php. Runtime option-name matching/discovery remains
# on Policy/Capture/OptionsMaterializer; this direct suite proves the pure
# grammar plus both Policy loader entry points and the no-facade extraction.
regress-option-reference-grammar:
	php sandbox/tests/offline/grammar/regress_option_reference_grammar.php

# issue #3348 slice 12: the closed post-type body/phase declaration grammar
# moved out of Policy.php into PostTypeGrammar.php. The runtime lookup stays
# on Policy as the compatibility facade; this direct suite proves the
# collaborator's refusal text, defaults, vocabulary publication, and wiring.
regress-post-type-grammar:
	php sandbox/tests/offline/grammar/regress_post_type_grammar.php

# issue #3348 slice 13: the pure option-namespace and authored-meta keyspace
# discovery grammar moved out of Policy.php into DiscoveryGrammar.php. Live
# discovery remains on Capture; this direct suite proves the manifest-only
# validator and both Policy loader entry points.
regress-discovery-grammar:
	php sandbox/tests/offline/policy/regress_discovery_grammar.php

# issue #3348 slice 23: cross-source reference-keyspace closure and attached-meta
# ownership grammar moved out of Policy.php into ReferenceKeyspaceGrammar.php.
# ReferenceShapeGrammar retains the local declaration-shape pass; runtime
# reference resolution remains on Policy and its consumers. This direct suite
# proves every source enumeration, recursive sub-key path, sidecar ambiguity,
# and both Policy loader entry points.
regress-reference-keyspace-grammar:
	php sandbox/tests/offline/grammar/regress_reference_keyspace_grammar.php

# issue #3348 slice 24: ref/token/ledger kind vocabulary grammar moved out of
# Policy.php into ReferenceKindGrammar.php. Runtime reference resolution stays
# on Policy and its consumers; this direct suite proves the shared declared
# id_kind extension path, all claim surfaces, and both Policy loader paths.
regress-reference-kind-grammar:
	php sandbox/tests/offline/grammar/regress_reference_kind_grammar.php

# WP-6.2: the `invalidate[]` verb vocabulary and the rule that decides what
# enters it. Two or more INDEPENDENT demands admit a verb; a single-demand shape
# stays refused and stays recorded in tools/engine-gaps.json. Proves the third
# verb {cache_group, cache_key} on both spellings, its engine_features staging
# (a grammar change post-v3 with no version bump), the apply-time drop and its
# readback, and -- through the real `wprism manifest-validate` -- a synthetic
# Paid Memberships Pro whose whole executable surface is replaced by one
# declarative line, dropping it out of `compatibility_shim`.
regress-invalidate-vocabulary:
	php sandbox/tests/offline/grammar/regress_invalidate_vocabulary.php

# issue #3350 slice 2: the lifecycle dependency graph planner is independent of
# WordPress side effects; Deploy retains compatibility facades for its reads
# and execution path.
regress-deploy-planner:
	php sandbox/tests/offline/code-half/regress_deploy_planner.php

# issue #3350 slice 6: code_mismatch()/code_revision_mismatch()/code_drift()/
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
	php sandbox/tests/offline/code-half/regress_lifecycle_planner.php

# issue #3507: `wprism capture` observes the code-version baseline and never
# accepts one. Pins LifecyclePlanner::observe_code_versions() (writes only
# when there is nothing to accept; freezes wprism_kv['code_versions']
# byte-identical and returns the rows when there is), that
# record_code_versions() still clobbers unconditionally so deploy's
# --force-code-drift accept path keeps working, and that the capture call
# site takes the first and reports every row it gets back.
regress-capture-code-baseline:
	php sandbox/tests/offline/code-half/regress_capture_code_baseline.php

# issue #3350 slice 7: options_snapshot()/bind_lifecycle_missing_options()/
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
	php sandbox/tests/offline/code-half/regress_state_handoff_verifier.php

# issue #3350 slice 8: the WP-mutation body of Deploy::run() -- deactivate,
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
# proof only -- full behavioral coverage already exists across the
# end-to-end suites this class's mutation logic feeds (regress_promotion_
# lock.sh, regress_code_drift.sh, certify_version_matrix.sh,
# the grind_r*.sh scripts, etc.), unchanged by this extraction.
regress-lifecycle-executor:
	php sandbox/tests/offline/code-half/regress_lifecycle_executor.php

# issue #3345 (structured-refusal slice): the real agent command handlers emit
# one versioned, actionable, credential-redacted JSON refusal for every
# primary compile/capture/plan/apply/deploy failure while human mode and the
# existing typed compiler diagnostics remain compatible.
regress-cli-json-refusals:
	php sandbox/tests/offline/cli/regress_cli_json_refusals.php

# Standalone test evidence: one fresh private graph, never an inferred cause.
.PHONY: regress-private-refusal-receipt
regress-private-refusal-receipt:
	php sandbox/tests/offline/guards/regress_private_refusal_receipt.php

.PHONY: regress-private-command-capture
regress-private-command-capture:
	php sandbox/tests/offline/guards/regress_private_command_capture.php

.PHONY: regress-private-tree-evidence
regress-private-tree-evidence:
	php sandbox/tests/offline/guards/regress_private_tree_evidence.php

.PHONY: regress-sql-dump-evidence
regress-sql-dump-evidence:
	php sandbox/tests/offline/guards/regress_sql_dump_evidence.php

.PHONY: regress-repository-convergence
regress-repository-convergence:
	php sandbox/tests/offline/repository/regress_repository_convergence.php

.PHONY: regress-native-value-validation
regress-native-value-validation:
	php sandbox/tests/offline/apply/regress_native_value_validation.php

# One scalar must satisfy every declared local-id consumer without guessing.
.PHONY: regress-scalar-reference-intersection
regress-scalar-reference-intersection:
	php sandbox/tests/offline/reference-scope/regress_scalar_reference_intersection.php

.PHONY: regress-core-conformance-evidence
regress-core-conformance-evidence:
	php sandbox/tests/offline/guards/regress_core_conformance_evidence.php

regress-wordpress-cron-window:
	php sandbox/tests/offline/guards/regress_wordpress_cron_window.php

# The lock/concurrency and repository-state refusals an orchestrator meets on
# capture/plan/apply/deploy each reach --format=json as their own reason code
# instead of `<command>_failed` + details_redacted, produced by the real
# ProcessFence/PromotionLease/Policy/CompiledArtifactReader gates and carried
# across the real Cli catch boundary. Sibling of regress-cli-json-refusals,
# which owns the envelope itself.
regress-typed-refusal-envelopes:
	php sandbox/tests/offline/cli/regress_typed_refusal_envelopes.php

# issue #3517: the `wp wprism` surface the host invokes must be the surface the agent
# registers, resolved the way WP-CLI resolves it (@subcommand tag, else the raw
# method name -- never an implicit hyphenation). `code_inventory` shipped
# unreachable as `wp wprism code-inventory` because nothing compared the two.
regress-agent-subcommand-names:
	php sandbox/tests/offline/cli/regress_agent_subcommand_names.php

# issue #3351 slice 1: host command output owns the shared JSON refusal and
# argument-format contract; cli/wprism retains only compatibility facades.
regress-command-output:
	php sandbox/tests/offline/cli/regress_command_output.php

regress-environment-command-preflight:
	php sandbox/tests/offline/environment/regress_environment_command_preflight.php

# issue #3351 slice 4: target-free typed option grammar for `wprism env`.
regress-environment-command-options:
	php sandbox/tests/offline/environment/regress_environment_command_options.php

regress-driver-capabilities-command:
	php sandbox/tests/offline/environment/regress_driver_capabilities_command.php

regress-environment-list-command:
	php sandbox/tests/offline/environment/regress_environment_list_command.php

regress-doctor-command:
	php sandbox/tests/offline/cli/regress_doctor_command.php

# WP-1.5: `wprism adapter doctor --migration`, the blast-radius preflight. Its
# acceptance is a CROSS-CHECK, not an expectation table: it drives the verb
# against WP-1.4's own nine-site estate at the post-bump state and asserts, per
# site, that the invalidation set it PREDICTED equals the set that rehearsal
# OBSERVED between its two passes. Includes the two movements a digest-neutral
# bump still causes -- artifact_hash fleet-wide, and manifest/revision identity
# on every certificate-holding site -- plus fixtures for a certificate and a pin
# shape the verb refuses to classify. Builds and observes the estate once
# (~5s) and then runs the preflight per site.
regress-migration-preflight:
	php sandbox/tests/offline/cli/regress_migration_preflight.php

regress-adopt-command:
	php sandbox/tests/offline/cli/regress_adopt_command.php

regress-unadopt:
	php sandbox/tests/offline/cli/regress_unadopt.php

regress-ideal-onboarding:
	php sandbox/tests/offline/cli/regress_ideal_onboarding.php

regress-init-command:
	php sandbox/tests/offline/cli/regress_init_command.php

regress-pending-command:
	php sandbox/tests/offline/cli/regress_pending_command.php

regress-classify-command:
	php sandbox/tests/offline/cli/regress_classify_command.php

regress-capture-command:
	php sandbox/tests/offline/cli/regress_capture_command.php

regress-status-command:
	php sandbox/tests/offline/cli/regress_status_command.php

# issue #3351 slice 10: the scope host handler owns only protected control-plane
# forwarding and transport exit propagation; scope semantics remain in agent.
regress-scope-command:
	php sandbox/tests/offline/cli/regress_scope_command.php

# issue #3351 slice 11: refresh owns host parsing/refusal/output while Refresh
# retains the semantic planning and target workflow.
regress-refresh-command:
	php sandbox/tests/offline/refresh/regress_refresh_command.php

# issue #3351 slice 12: rebase owns parser/refusal/output semantics while
# Refresh retains the semantic candidate/ref workflow and durable abort.
regress-rebase-command:
	php sandbox/tests/offline/refresh/regress_rebase_command.php

# issue #3351 slice 3: ordinary and scoped agent forwarding share one typed,
# target-free host boundary; malformed scope wire input refuses before contact.
regress-passthrough-command:
	php sandbox/tests/offline/cli/regress_passthrough_command.php

# issue #3345 (explain slice): one freshly-observed entity row projects a
# deterministic, value-free source -> policy -> reference -> structured
# action -> verification chain. No WordPress, target mutation, or provider
# code is used by this pure contract fixture.
regress-plan-explain:
	php sandbox/tests/offline/cli/regress_plan_explain.php

regress-template-mismatch:
	php sandbox/tests/offline/code-half/regress_template_mismatch.php

regress-code-deploy-unit:
	bash sandbox/tests/offline/code-half/regress_code_deploy_unit.sh

regress-deploy-command:
	php sandbox/tests/offline/cli/regress_deploy_command.php

# The deploy checkpoint and its recoverability: the export sits under the
# lease exactly where promote's does (cli/wprism:2385-2388), and the
# deploy-<runId>.sql it writes is the file RetainedCheckpoints globs and
# RecoverCommand rebuilds. Against the prior build the round trip throws
# checkpoint_listing_malformed. Pure fakes; no target contact.
regress-deploy-checkpoint:
	php sandbox/tests/offline/recovery/regress_deploy_checkpoint.php

# The external settlement-debt check precedes every durable write under the
# target advisory fence, and the new lease/session pair commits atomically.
# FakeWpdb injects a failure between those two writes and proves full rollback.
regress-promotion-begin-atomicity:
	php sandbox/tests/offline/recovery/regress_promotion_begin_atomicity.php

# issue #3514's retention half: promote and deploy each retain an unbounded
# whole-DB dump under .wprism/checkpoints and nothing ever removed one.
# `wprism recover <env> --prune-retained=<keep-n>` is the only verb that does,
# it plans unless --confirm-prune is given, it keeps the newest N of EACH verb
# so the last before-image is never deletable, it never considers a signed
# receipt, and it refuses while a generation is nonterminal. Pure fakes; the
# removal script and its parse are byte-pinned.
regress-checkpoint-prune:
	php sandbox/tests/offline/recovery/regress_checkpoint_prune.php

# issue #3351 slice 19: the public promotion router owns only scoped-versus-
# ordinary selection; the target promotion state machines remain unchanged.
regress-promote-command:
	php sandbox/tests/offline/cli/regress_promote_command.php

regress-lifecycle-state-handoff:
	php sandbox/tests/offline/code-half/regress_lifecycle_state_handoff.php

regress-lifecycle-phase-handoff-unit:
	php sandbox/tests/offline/code-half/regress_lifecycle_phase_handoff_unit.php

regress-plugin-dependency-order:
	php sandbox/tests/offline/code-half/regress_plugin_dependency_order.php

regress-code-compatibility:
	bash sandbox/tests/offline/code-half/regress_code_compatibility.sh

# First-ever sync safety: a hook writes authored state and then throws before
# plugin membership persists. A durable pre-hook receipt must block a different
# artifact/owner until exact code + checkpoint recovery, after which the fixed
# artifact may establish the first three-way base.
grind-code-half-first-sync:
	bash sandbox/tests/grind/grind_first_sync_hook_recovery.sh

# ROUND 3 T4 (spec: docs/grind/mup.md): the end-to-end minimum-usable-platform
# grind on a dedicated docker pair — assess → contract → rehearse → capture → merge →
# release (authorization plan, deploy-before-apply) → verify → recover → assess
# → reap. Live-only (docker); NOT auto-bundled (regress-live-list). Step 11
# (recover) needs an SSH-adopted target: on a plain pair it fails by default
# (MUP_STEP11=required) or records the gap (MUP_STEP11=record-gap).
# Usage: make grind-mup [MUP_PAIR=mup MUP_PORT1=9400 MUP_PORT2=9401 MUP_STEP11=…]
grind-mup:
	MUP_PAIR="$(MUP_PAIR)" MUP_PORT1="$(MUP_PORT1)" MUP_PORT2="$(MUP_PORT2)" MUP_STEP11="$(MUP_STEP11)" \
	MUP_THEME_SLUG="$(MUP_THEME_SLUG)" MUP_THEME_VERSION="$(MUP_THEME_VERSION)" \
	bash sandbox/tests/grind/grind_mup.sh

# ROUND 3 T6 (spec: docs/grind/adapter-walk.md; wire contract:
# docs/adapter-walk-bundle.md): the operator-authored-adapter walk on a dedicated
# docker pair — four scenarios (S1 published plugin kept unmanaged, S2 a site
# adapter the operator drafts/certifies, S3 an in-house plugin with a bundled
# adapter promoted and certified, S4 a site override of a shipped adapter), each
# through init → assess → contract → rehearse → capture → merge → release →
# verify → recover → reap. Live-only (docker); NOT auto-bundled (regress-live-list).
# Usage: make grind-adapter-walk [WALK_PAIR=awalk WALK_PORT1=9500 WALK_PORT2=9501 WALK_SCENARIOS=S1,S2,S3,S4]
grind-adapter-walk:
	WALK_PAIR="$(WALK_PAIR)" WALK_PORT1="$(WALK_PORT1)" WALK_PORT2="$(WALK_PORT2)" WALK_SCENARIOS="$(WALK_SCENARIOS)" \
	WALK_THEME_SLUG="$(WALK_THEME_SLUG)" WALK_THEME_VERSION="$(WALK_THEME_VERSION)" \
	bash sandbox/tests/grind/grind_adapter_walk.sh

# ROUND 3 T7 (spec: docs/grind/adoption.md): ten progressive-adoption
# situations on a dedicated docker pair — brochure, FSE, shop with orders,
# shop+SEO+forms, multilingual
# shop, builder site, late adapter adoption, plugin+adapter code release,
# version edges, edge cases — each from doctor/first-look through the loop.
# Same substrate as the walk (sandbox/tests/lib/grind_lib.sh). Live-only
# (docker); NOT auto-bundled (regress-live-list).
# Usage: make grind-adoption [ADOPT_PAIR=adopt ADOPT_PORT1=9600 ADOPT_PORT2=9601 ADOPT_SITUATIONS=A1,…,A10]
grind-adoption:
	ADOPT_PAIR="$(ADOPT_PAIR)" ADOPT_PORT1="$(ADOPT_PORT1)" ADOPT_PORT2="$(ADOPT_PORT2)" ADOPT_SITUATIONS="$(ADOPT_SITUATIONS)" \
	bash sandbox/tests/grind/grind_adoption.sh

# issue #3216: live activation/deactivation/order gate, deploy-window mail/HTTP
# observations, and the composed host promote path with a retained DB dump.
regress-promotion:
	bash sandbox/tests/live/regress_promotion.sh

# issue #3214(a): offline, no docker -- Capture::guard_secret()'s two
# is_string()-gated call sites (post_meta, options) now deep-scan via
# Secrets::hard_match_deep() unconditionally, so an authored value that
# decodes to an array (a serialized settings blob) can no longer skip
# secret scanning entirely. See the script's own header for what's proven
# here (the widened method, via Reflection) vs. by live sandbox evidence
# (the real call-site wiring through Capture::build()).
regress-capture-secret-scan:
	bash sandbox/tests/offline/capture/regress_capture_secret_scan.sh

# issue #3349: direct login-keyed user-meta entity capture boundary. Pure PHP
# with a fake wpdb/policy/token fixture; no Docker or WordPress bootstrap.
regress-user-meta-capture:
	php sandbox/tests/offline/capture/regress_user_meta_capture.php

.PHONY: regress-term-rows
regress-term-rows:
	php sandbox/tests/offline/capture/regress_term_rows.php

# issue #3349: direct post/term metadata discovery + classification boundary.
# Pure PHP with fake wpdb/policy/token fixtures; no Docker or WordPress.
regress-entity-meta-capture:
	php sandbox/tests/offline/capture/regress_entity_meta_capture.php

regress-menu-capture:
	php sandbox/tests/offline/capture/regress_menu_capture.php

regress-media-capture:
	php sandbox/tests/offline/capture/regress_media_capture.php

# issue #3349: direct option discovery, encoding, side-channel, and lifecycle
# boundary. Pure PHP with fake wpdb/policy/token fixtures; no Docker.
regress-options-capture:
	php sandbox/tests/offline/capture/regress_options_capture.php

regress-reference-scope-classifier:
	php sandbox/tests/offline/reference-scope/regress_reference_scope_classifier.php

regress-capture-safety-gates:
	php sandbox/tests/offline/capture/regress_capture_safety_gates.php

# WP-3.1: a capture-time lint finding is ADVISORY for a shipped adapter's state
# and BLOCKING for an uncertified out-of-tree adapter's. Every case is a PAIR
# over the same state bytes and the same findings, so the shipped half pins the
# unchanged warning (rule 8) while the out-of-tree half pins the refusal, its
# named locators and the adapter record's own reason. Covers the WITHDRAWN
# certification (WP-1.1) and asserts a PROPOSED lint_ok (WP-2.4) still refuses.
regress-lint-trust-tier-gate:
	php sandbox/tests/offline/capture/regress_lint_trust_tier_gate.php

regress-capture-gate-scanner:
	php sandbox/tests/offline/capture/regress_capture_gate_scanner.php

regress-capture-refactor-boundaries:
	php sandbox/tests/offline/capture/regress_capture_refactor_boundaries.php

# issue #3285: closes a real aliveness gap this issue's own bundle uncovered.
# OptionsCapture's option_name_refs (task #93) consumer loop
# shipped a real defect (issue #3286: undefined $liveOptionNames, a warning
# not a fatal, so capture kept exiting 0 while silently skipping every
# option_name_refs rule) that no offline suite would have caught -- proven
# live by reverting the fix locally and watching regress-offline-all stay
# green. See the script's own header for the full account, including its
# own self-test (a synthetic corrupted copy must fail this check before
# the real-file result is trusted, same discipline regress_capture_secret_
# scan.sh already established).
regress-option-name-refs-wiring:
	bash sandbox/tests/offline/capture/regress_option_name_refs_wiring.sh

# issue #3214(b) / task #123: offline, no docker -- Canon::normalize()'s new
# OrderPreserved-aware branch, which stops alphabetically resorting a meta
# value a manifest rule declares "order_preserving": true
# (adapter-packages/woocommerce/package/manifest.json's `_product_attributes`
# closes the causation-proven
# WooCommerce variation-title word-reordering bug). See the script's own
# header for what's proven here (the Canon.php mechanism) vs. by live
# sandbox evidence (the real WooCommerce variation title converging
# byte-for-byte, not just as a same-words anagram).
regress-order-preserving:
	php sandbox/tests/offline/grammar/regress_order_preserving.php

# issue #3481 (dev-loop round 2): duplication conformance vectors. The three
# deployables (agent/, cli/, recovery/) keep deliberately separate copies of
# canonical-JSON encoding, the wprism-command-refusal/v1 envelope, plan-row
# labels, version-range and safe-relative-path helpers, plus the five
# PlanSummary<->agent Cli "keep lockstep" renderers. Until now the only thing
# holding those copies byte-identical was a comment. This suite drives every
# implementation over shared fixture vectors (sandbox/tests/fixtures/parity/)
# and fails on the first differing byte, per family; intentional divergences
# are pinned as differences, not hidden. Pure PHP, offline, no docker.
regress-canonical-json-parity:
	php sandbox/tests/offline/guards/regress_canonical_json_parity.php

# ROUND 3 T2 (vocabulary: docs/assess-vocabulary.md): the assess + contract
# product surface. Pure PHP / bash offline suites over the
# fixture site repos and a fake `wp`; no docker.
# the §1 vocabulary projection, table-driven: every cell of state class × handling × readiness × certification provenance × containment × recovery semantics, plus the no-plugin-slug gate over the Assess/Contract modules
regress-assess-projection:
	php sandbox/tests/offline/assess-contract/regress_assess_projection.php

# wp wprism assess-inventory: the wprism-assess-inventory/v1 document over a fake site — names and counts only, byte-stable, no option value ever leaks
regress-assess-inventory:
	php sandbox/tests/offline/assess-contract/regress_assess_inventory.php

# wprism-application-contract/v1: canonical bytes, closed keys, digest stability, attestation enum, compare-and-swap store
regress-contract-shape:
	php sandbox/tests/offline/assess-contract/regress_contract_shape.php

# contract attestation: the trust root ships absent so nothing mints; a provisioned key round-trips, a tampered byte/expiry/moved platform boundary each refuse by name
regress-contract-attestation:
	php sandbox/tests/offline/assess-contract/regress_contract_attestation.php

# projection.json regenerates identically from identical inputs; an evidence-pin mismatch flips affected surfaces to Requalification required
regress-contract-projection:
	php sandbox/tests/offline/assess-contract/regress_contract_projection.php

# wprism assess composes doctor → probes → inventory → capabilities → catalog in order and refuses (never partially succeeds) on missing access; proposed.json written locally
regress-assess-composition:
	bash sandbox/tests/offline/assess-contract/regress_assess_composition.sh

# bounded human output: 50-row default, --limit=1..200 grammar, the "N more (use --format=json)" tail
regress-assess-bounds:
	bash sandbox/tests/offline/assess-contract/regress_assess_bounds.sh

# wprism contract propose → accept → files written canonical and staged; stale proposal refused; newer-digest overwrite refused
regress-contract-accept:
	bash sandbox/tests/offline/assess-contract/regress_contract_accept.sh

# issue #3503: the proposal is per environment (.wprism/contract/<env>/proposed.json) while contract.json/projection.json stay per site; assessing another env leaves a reviewed proposal untouched; a proposal stamped for another env refuses before the target is contacted; an illegal env path segment refuses
regress-contract-multi-env:
	bash sandbox/tests/offline/assess-contract/regress_contract_multi_env.sh

# ROUND 3 T3 (docs/assess-vocabulary.md §1.6 is the containment rule the
# release gate below enforces): release, verify, recover and rehearse.
# Offline over fixture site repos, fake drivers
# and a fake `wp`/`ssh`; no docker.
# wprism-authorization-plan/v1: durable freeze before any mutation, digest stability, plan_changed invalidation on any fact drift, --plan-only mutates nothing, weaker --profile needs the explicit flag
regress-authorization-plan:
	php sandbox/tests/offline/assess-contract/regress_authorization_plan.php

# actor-bound Ed25519 authority: exact subject/target/operation/expiry binding, target-private one-time consumption, and byte-identical same-operation replay
regress-operation-authorization:
	php sandbox/tests/offline/assess-contract/regress_operation_authorization.php

# WPB-003 foundation: prepare is a closed read-only target observation; the
# frozen plan rejects generation/checkpoint/head/claim/lease drift before any
# step, and target-private status distinguishes exact completion replay from a
# consumed operation that requires reconciliation.
regress-recovery-preparation:
	php sandbox/tests/offline/recovery/regress_recovery_preparation.php

# the §1.6 consequence as a gate: an undeclared live lifecycle window refuses with "declare in contract"; a declared entry yields the declared_live_effect authority row; Experimental / Not qualified / Unsupported / Requalification required in scope refuse pre-freeze with a gap action, never a release next action
regress-release-containment-gate:
	php sandbox/tests/offline/assess-contract/regress_release_containment_gate.php

# wprism-recovery-claim/v1 is literal: byte-identical between the frozen plan and recovery; does_not_restore non-empty for every profile including verified-automatic
regress-recover-claim:
	php sandbox/tests/offline/assess-contract/regress_recover_claim.php

# ROUND 3 T6 (wire contract: docs/adapter-walk-bundle.md): `wprism adapter keygen|certify|pin` — the
# operator's own trust root under adapters/authorities.json, the unexercised site bundle, the
# exact {name,source,digest} pin, and the shipped-name override; offline against a scratch site repo
regress-adapter-certify:
	php sandbox/tests/offline/adapter/regress_adapter_certify.php

# journey grammar, undeclared-journey disclosure, convergence + journeys both required for a pass
regress-verify-oracles:
	php sandbox/tests/offline/assess-contract/regress_verify_oracles.php

# provider capability negotiation (missing capability → refusal naming it, never emulation), --reap idempotence, the containment disclosure banner
regress-rehearse-provider:
	bash sandbox/tests/offline/assess-contract/regress_rehearse_provider.sh

# every documented failure class maps to exactly one of resume|reconcile|retry|recover|requalify|escalate; incomplete lifecycle → recover; ambiguous commitment → reconcile, never retry; the release drives the real promote sequence
regress-release-next-action:
	bash sandbox/tests/offline/assess-contract/regress_release_next_action.sh

# the mutation gate re-observes the conditions the frozen plan names: a plugin downgraded, deactivated, withdrawn, gone or unreadable during the confirmation window refuses capability_expired → requalify with the target untouched
regress-release-condition-gate:
	bash sandbox/tests/offline/assess-contract/regress_release_condition_gate.sh

# --from <ref> is a binding assertion against the target HEAD (mismatch → reconcile) and invents no git transport
regress-release-ref-binding:
	bash sandbox/tests/offline/assess-contract/regress_release_ref_binding.sh

regress-release-stage-prepare:
	bash sandbox/tests/offline/assess-contract/regress_release_stage_prepare.sh

# code-first refusal, the mandatory final abort even on import failure, --writers-excluded required
regress-recover-ordering:
	bash sandbox/tests/offline/assess-contract/regress_recover_ordering.sh

# ROUND 3 T4 (the internal-ID leak closure; docs/guides/internals.md is the
# canonical list of what a human may see): the MUP leak audit — no internal
# identifier in the human view of assess / release
# --plan-only / verify / recover / rehearse unless a documented command consumes
# it; every public `wp wprism` command is host-driven or named in
# docs/guides/internals.md; the retired four-command raw-recovery recipe cites
# nowhere but internals.md. Offline over the T2/T3 fixture sites.
regress-mup-leak-audit:
	bash sandbox/tests/offline/assess-contract/regress_mup_leak_audit.sh



regress-promotion-lock:
	bash sandbox/tests/live/regress_promotion_lock.sh

# issue #3353: responsibility-focused publication/lease control-plane seams.
# Offline and intentionally independent of WordPress/$wpdb; the existing
# capture and promotion suites remain the behavioral characterization of the
# compatibility facades.
regress-control-plane-seams:
	php sandbox/tests/offline/recovery/regress_control_plane_seams.php

# issue #3506: the two `promotion-abort` refusals carry a stable reason code, and
# their operator sentence does not move. Without the code, a superseded
# checkpoint reached `wprism recover` as one constant, reasonless line; with a
# changed sentence, the live pin regress_promotion_lock.sh:99-107 breaks.
regress-promotion-abort-reason:
	php sandbox/tests/offline/recovery/regress_promotion_abort_reason.php

# issue #3352: shared canonical JSON, durable publication, exclusive locking,
# and bounded provider transport used by the rollback/resource bundles.
regress-recovery-protocol:
	php sandbox/tests/offline/recovery/regress_recovery_protocol.php

# issue #3223: real target-wide capture/apply/delete contention. Same-destination
# publishers refuse at the filesystem lock; different destinations and apply
# cross-races refuse at the shared target-writer fence. One winning apply
# verifies rewrite regeneration, one winning delete removes the mapping once,
# and both product paths prove zero-action retries.
regress-capture-concurrency:
	bash sandbox/tests/live/regress_capture_concurrency.sh

# issue #3285: the 25 regress_*.{sh,php} scripts below existed in sandbox/tests/
# with NO Makefile target at all before this issue -- unreachable via `make`,
# discoverable only by grepping the directory listing by hand. Surfaced by
# the same survey that built regress-offline-all/regress-live-list below:
# every regress_* file was read (not name-guessed) to confirm it and
# classify it offline vs live. Wired here on the same terms as every other
# suite in this file, whether or not it ends up folded into a bundle.

# --- offline (no docker/pair.sh -- pure PHP/file-I/O), now in regress-offline-all ---
regress-adopt-rollback:
	php sandbox/tests/offline/cli/regress_adopt_rollback.php

regress-local-bootstrap:
	php sandbox/tests/offline/cli/regress_local_bootstrap.php

# issue #3365: one disposable pair supplies a real installed WordPress volume,
# then an out-of-band controller container proves local adopt -> init from a
# target with no pre-mounted WPrism control plane. Live-only; never offline-all.
regress-local-bootstrap-live:
	bash sandbox/tests/live/regress_local_bootstrap_live.sh

# Automatic verified rollback on a `local` target, on the same disposable-pair
# estate issue #3365 established: the controller container IS the target, which is
# what makes the local transport real. Live-only; never offline-all.
regress-local-verified-rollback-live:
	bash sandbox/tests/live/regress_local_verified_rollback_live.sh

regress-block-refs:
	php sandbox/tests/offline/reference-scope/regress_block_refs.php

regress-identity-token-codec:
	php sandbox/tests/offline/grammar/regress_identity_token_codec.php

regress-text-tokenizer:
	php sandbox/tests/offline/grammar/regress_text_tokenizer.php

regress-structured-reference-codec:
	php sandbox/tests/offline/grammar/regress_structured_reference_codec.php

regress-url-query-reference-codec:
	php sandbox/tests/offline/grammar/regress_url_query_reference_codec.php

# WP-6.1's two declarative primitives, each with the previously-rejected
# engine-gap candidate authored end to end as its sufficiency proof:
# `column_codecs` (Redirection 5.9.0's PHP-serialized `action_data`) and
# `attr_id_codecs` (WPForms Lite's string-typed `formId`). Both sections ship
# post-v3 through `engine_features` with NO version bump, which each suite
# asserts by pinning WPRISM_SPEC_VERSION at 3.
regress-column-codec-grammar:
	php sandbox/tests/offline/grammar/regress_column_codec_grammar.php

regress-attr-id-codec-grammar:
	php sandbox/tests/offline/grammar/regress_attr_id_codec_grammar.php

# WP-6.5's primitive, and the third to ship through the same channel: the `json`
# post-type body mode plus the `body_refs` reference paths inside it. Its
# sufficiency proof is the SAME previously-rejected candidate as
# regress-attr-id-codec-grammar, one coordinate over — WPForms Lite's JSON
# post_content — driven on four post_content values captured from a live
# 2.0.0.5 pair through the plugin's own write paths, so the optional,
# type-variant `$.id` and the `previous_page` sentinel are measured rather than
# imagined.
regress-body-ref-grammar:
	php sandbox/tests/offline/grammar/regress_body_ref_grammar.php

# The two primitives above, plus the rest of one adapter's declared surfaces, as
# ONE INSTALLED FILE rather than as a manifest built in PHP:
# sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json is the tree's first
# spec_version 3 adapter, authored through the decentralized path a third party
# takes and loaded here as a SITE adapter — so the out-of-tree contract and the
# vendor-namespace rule run, which a library-directory fixture skips. Drives
# capture -> apply -> recapture over the five measured 2.0.0.5 captures, and
# asserts the honest half too: the open form-locations coordinate, the
# unadvertised post:wpforms deletion, and the certification the feature channel
# costs.
regress-wpforms-lite-adapter:
	php sandbox/tests/offline/adapter/regress_wpforms_lite_adapter.php

# The `taxonomy_delete_scope_exercise` primitive, run rather than asserted: the
# adversarial matrix tools/engine-gaps.json demands before a post-type adapter
# carrying an authored taxonomy may claim a deletion selector. Four cases over
# the same committed wpforms-lite fixture the target above loads — attached
# state (the declared guard locks), cascade scope (the exact eight-table row
# delta of a real DeleteExecutor run), residue on a dirty target, and the
# unadvertised post:wpforms selector — each with a mutation proof that removes
# its outcome. The first offline suite to execute a term cascade at all; the
# FakeWpdb LEFT JOIN and the LockingFakeWpdb SHOW KEYS filter are what it cost.
regress-wpforms-lite-term-deletion:
	php sandbox/tests/offline/adapter/regress_wpforms_lite_term_deletion.php

regress-lint-primitives:
	php sandbox/tests/offline/reference-scope/regress_lint_primitives.php

regress-pending-queue-ownership:
	php sandbox/tests/offline/reference-scope/regress_pending_queue_ownership.php

regress-block-reference-scanner:
	php sandbox/tests/offline/reference-scope/regress_block_reference_scanner.php

regress-menu-reference-scanner:
	php sandbox/tests/offline/reference-scope/regress_menu_reference_scanner.php

regress-serialized-term-description-scanner:
	php sandbox/tests/offline/reference-scope/regress_serialized_term_description_scanner.php

regress-shortcode-reference-scanner:
	php sandbox/tests/offline/reference-scope/regress_shortcode_reference_scanner.php

regress-composite-ref:
	php sandbox/tests/offline/repository/regress_composite_ref.php

regress-doctor-env-values:
	php sandbox/tests/offline/cli/regress_doctor_env_values.php

regress-environment-driver:
	php sandbox/tests/offline/environment/regress_environment_driver.php

regress-environment-lifecycle:
	php sandbox/tests/offline/environment/regress_environment_lifecycle.php

regress-environment-command:
	php sandbox/tests/offline/environment/regress_environment_command.php

regress-environment-materializer:
	php sandbox/tests/offline/environment/regress_environment_materializer.php

# The published provider protocol (docs/branch-environment-provider.md) and
# the `wprism env provider-check` harness, proven against the LIVE
# CommandEnvironmentProvider so neither can drift from what the orchestrator
# actually sends. No docker, no pair, no source environment is contacted.
regress-env-provider-conformance:
	php sandbox/tests/offline/environment/regress_env_provider_conformance.php

# issue #3324: public attach/materialize over the real SSH driver with an
# offline SSH wrapper and generic machine-local provider; no provisioning is
# claimed and reap is verified as exact detach.
regress-environment-materializer-ssh:
	php sandbox/tests/offline/environment/regress_environment_materializer_ssh.php

# issue #3324: phase-exact recovery under provider response loss. This is
# deliberately offline: its command provider persists each fixture mutation
# before withholding the response, then proves the public journal resumes
# only with the exact operation owner and idempotency tuple.
regress-environment-materializer-recovery:
	php sandbox/tests/offline/environment/regress_environment_materializer_recovery.php

# issue #3324: full public-CLI proof against one isolated pair.  This is live
# deliberately: it owns source/target DB/media/repository resources and its
# machine-local provider independently proves snapshot/fence/TTL cleanup.
regress-environment-materializer-live:
	bash sandbox/tests/live/regress_environment_materializer_live.sh

# The `wprism env provider-check` harness against tools/reference-env-provider.php
# on a real pair. Live deliberately: the offline suite proves the harness
# agrees with the ORCHESTRATOR using stub providers this repo writes, and only
# a real, independent provider can prove the synthetic cycle is one such a
# provider can actually serve -- including that its teardown unfreezes the
# source and releases the target.
regress-env-provider-conformance-live:
	bash sandbox/tests/live/regress_env_provider_conformance_live.sh

# WPB-011: real Docker topology/probes, with `--topology-only` available when
# the sandbox shared-DB port is owned by another worktree. The full lane owns
# source + independent release target + standalone contained preview.
regress-rehearsal-containment-live:
	bash sandbox/tests/live/regress_rehearsal_containment_live.sh

regress-frozen-materialization-promotion:
	php sandbox/tests/offline/environment/regress_frozen_materialization_promotion.php

# issue #3513: DockerTransport's opt-in `mode: "exec"` against a resident
# compose service (vs. today's default `run --rm`, a fresh container per
# call). Entirely offline: the not-running precondition's docker probe is
# injected through DockerTransport's constructor seam, so this suite never
# shells out to a real `docker`.
regress-docker-exec-mode:
	php sandbox/tests/offline/environment/regress_docker_exec_mode.php

# issue #3384: PlanSummary::render() tolerates partial fixtures by design, so a
# valid `{}` renders clean. This drives an empty, a missing-bucket, and a
# complete plan through both promotion reconciliation boundaries, and pins the
# validator's required buckets to what agent/src/Apply/Apply.php actually emits.
# Offline: the ssh/wp pair it needs are fixture scripts on PATH.
regress-plan-contract-trust:
	php sandbox/tests/offline/cli/regress_plan_contract_trust.php

regress-dynamic-options-policy:
	php sandbox/tests/offline/policy/regress_dynamic_options_policy.php

regress-option-name-reference-resolver:
	php sandbox/tests/offline/grammar/regress_option_name_reference_resolver.php

regress-deletion-capability-resolver:
	php sandbox/tests/offline/policy/regress_deletion_capability_resolver.php

regress-taxonomy-pattern-resolver:
	php sandbox/tests/offline/grammar/regress_taxonomy_pattern_resolver.php

regress-taxonomy-keyspace-resolver:
	php sandbox/tests/offline/grammar/regress_taxonomy_keyspace_resolver.php

regress-taxonomy-description-reference-resolver:
	php sandbox/tests/offline/grammar/regress_taxonomy_description_reference_resolver.php

regress-taxonomy-object-type-option-resolver:
	php sandbox/tests/offline/grammar/regress_taxonomy_object_type_option_resolver.php

regress-widget-type-resolver:
	php sandbox/tests/offline/grammar/regress_widget_type_resolver.php

regress-table-declaration-resolver:
	php sandbox/tests/offline/grammar/regress_table_declaration_resolver.php

regress-policy-rule-resolver:
	php sandbox/tests/offline/policy/regress_policy_rule_resolver.php

regress-exact-option-resolver:
	php sandbox/tests/offline/grammar/regress_exact_option_resolver.php

regress-option-namespace-resolver:
	php sandbox/tests/offline/grammar/regress_option_namespace_resolver.php

regress-content-attribute-rule-resolver:
	php sandbox/tests/offline/grammar/regress_content_attribute_rule_resolver.php

regress-taxonomy-object-keyspace:
	php sandbox/tests/offline/grammar/regress_taxonomy_object_keyspace.php

regress-env-options-policy:
	php sandbox/tests/offline/policy/regress_env_options_policy.php

# issue #3509: the option names a fresh `wprism init` on WP 7.0.3 + WooCommerce
# 11.0.1 + Yoast 28.3 + CF7 6.1.7 demanded a decision for, asserted against
# the REAL shipped manifests through Policy::option_rule_details() -- plus
# the one name (wp_user_roles) deliberately left undeclared.
regress-shipped-option-declarations:
	php sandbox/tests/offline/policy/regress_shipped_option_declarations.php

regress-export-manifest-roundtrip:
	php sandbox/tests/offline/policy/regress_export_manifest_roundtrip.php

# issue #3348 slice 27: the pure PolicyWriter projection and its stable
# Policy::export_manifest() facade, including the export/load round trip.
regress-policy-writer:
	php sandbox/tests/offline/policy/regress_policy_writer.php

# issue #3348 slice 28: the shared pure per-manifest grammar pipeline used by
# live Policy::load() and frozen Policy::from_snapshot() validation.
regress-manifest-validator:
	php sandbox/tests/offline/policy/regress_manifest_validator.php

# issue #3348 slice 29: the shared pure site.wprism.json policy validation
# sequence used by live Policy::load() and frozen Policy::from_snapshot().
regress-site-policy-validator:
	php sandbox/tests/offline/policy/regress_site_policy_validator.php

# issue #3348 slice 36: final cross-manifest closure/pin binding shared by live
# and frozen Policy loading.
regress-policy-load-finalizer:
	php sandbox/tests/offline/policy/regress_policy_load_finalizer.php

# issue #3348 slice 30: the pure policy/manifest identity projection moved out of
# RepositoryCompiler into ArtifactPolicyIdentity; checks direct loading, its
# historical compiler facades, and the independent registry-row digest proof.
regress-artifact-policy-identity:
	php sandbox/tests/offline/policy/regress_artifact_policy_identity.php

# issue #3348 slice 35: persisted compiled-artifact validation is independent of
# repository tree building while RepositoryCompiler retains its public facade.
regress-compiled-artifact-reader:
	php sandbox/tests/offline/repository/regress_compiled_artifact_reader.php

# issue #3348 slice 31: pure attachment/media partition validation moved out of
# RepositoryCompiler while the compiler retains tree orchestration and its
# aggregate diagnostic refusal.
regress-repository-media-catalog:
	php sandbox/tests/offline/repository/regress_repository_media_catalog.php

regress-repository-schema-validator:
	php sandbox/tests/offline/repository/regress_repository_schema_validator.php

regress-repository-deletion-parser:
	php sandbox/tests/offline/repository/regress_repository_deletion_parser.php

regress-repository-entity-parser:
	php sandbox/tests/offline/repository/regress_repository_entity_parser.php

regress-repository-identity-registry:
	php sandbox/tests/offline/repository/regress_repository_identity_registry.php

regress-repository-reference-graph-validator:
	php sandbox/tests/offline/repository/regress_repository_reference_graph_validator.php

regress-repository-portable-shape-validator:
	php sandbox/tests/offline/repository/regress_repository_portable_shape_validator.php

regress-repository-scalar-reference-intersection:
	php sandbox/tests/offline/repository/regress_repository_scalar_reference_intersection.php

regress-repository-menu-location-validator:
	php sandbox/tests/offline/repository/regress_repository_menu_location_validator.php

regress-repository-state-file-catalog:
	php sandbox/tests/offline/repository/regress_repository_state_file_catalog.php

regress-post-type-relation-resolver:
	php sandbox/tests/offline/grammar/regress_post_type_relation_resolver.php

regress-manifest-reclassification-policy:
	php sandbox/tests/offline/policy/regress_manifest_reclassification_policy.php

regress-menu-field-reclassification-policy:
	php sandbox/tests/offline/policy/regress_menu_field_reclassification_policy.php

regress-regen-dependency-policy:
	php sandbox/tests/offline/policy/regress_regen_dependency_policy.php

regress-woocommerce-hierarchy-lookups:
	php integration-scenarios/woocommerce-rewrite-coinstall/tests/offline/regress_woocommerce_hierarchy_lookups.php

regress-woocommerce-regen-engine:
	php sandbox/tests/offline/ecommerce/regress_woocommerce_regen_engine.php

regress-shortcode-refs:
	php sandbox/tests/offline/reference-scope/regress_shortcode_refs.php

regress-term-meta:
	php sandbox/tests/offline/capture/regress_term_meta.php

regress-url-query-refs:
	php sandbox/tests/offline/reference-scope/regress_url_query_refs.php

regress-classification-batch:
	php sandbox/tests/offline/cli/regress_classification_batch.php

regress-refresh-orchestration:
	php sandbox/tests/offline/refresh/regress_refresh_orchestration.php

regress-refresh-compile-refs:
	php sandbox/tests/offline/refresh/regress_refresh_compile_refs.php

regress-refresh-rebase:
	php sandbox/tests/offline/refresh/regress_refresh_rebase.php

regress-refresh-field-diff:
	php sandbox/tests/offline/refresh/regress_refresh_field_diff.php

# WPRISM merge-check: the env-free half of the merge story. Drives the real
# `wprism merge-check` executable against real Git fixtures, so the exit-code
# contract a customer's CI binds to (0/1/2/3) is asserted as a process
# result rather than as a return value.
regress-merge-check:
	php sandbox/tests/offline/refresh/regress_merge_check.php

# The fleet census: `wprism census` folds N wprism-assess-inventory/v1 documents into
# wprism-fleet-census/v1 with no environment, no target and no WordPress. Drives
# the real executable against synthetic manifest libraries and inventories
# under sandbox/tmp, because the deliverable includes the dispatch entry, the
# usage line, the exit-code contract and the redaction property — none of which
# an in-process call to FleetCensus::project() would see.
regress-fleet-census:
	php sandbox/tests/offline/cli/regress_fleet_census.php

# The cohort re-baseline: `wprism census --baseline=` folds two wprism-fleet-census/v1
# documents into wprism-cohort-rebaseline/v1 — the coverage-ratio delta, funnel
# movement and per-adapter surface attribution WP-6.3's exit criterion reads.
# Deterministic over fixture censuses, including the case the program is most
# likely to hit: a cohort that ships every adapter and moves no ratio, rendered
# as the FINDING it is rather than counted as a success. Case 5 re-measures the
# committed core-estate baseline (captured by the pre-flag-day engine at
# f99f6712) against the library this checkout ships.
regress-cohort-rebaseline:
	php sandbox/tests/offline/cli/regress_cohort_rebaseline.php

# WP-5.6, adapter discovery and distribution: `wprism adapter discover|install|
# update` over RECORDED wprism-adapter-index/v1 fixtures, with no live network on
# any path (the one https:// entry exists to prove it is discoverable and
# refuses at install). Every refusing install is asserted twice — the typed
# code, and that the repository's adapters/ tree is byte-for-byte unchanged —
# because a refusal that wrote first and rolled back is a different product.
# Includes a real platform-signed revocation and a genuinely lapsed v2
# authority window, both refusing through AdapterCertification::verifyFile()
# rather than through a second opinion in the distribution command.
regress-adapter-distribution:
	php sandbox/tests/offline/cli/regress_adapter_distribution.php

regress-rollback-authority:
	php sandbox/tests/offline/recovery/regress_rollback_authority.php

regress-recovery-transport:
	php sandbox/tests/offline/recovery/regress_recovery_transport.php

regress-local-verified-rollback:
	php sandbox/tests/offline/recovery/regress_local_verified_rollback.php

regress-recovery-executor:
	php sandbox/tests/offline/recovery/regress_recovery_executor.php

regress-checkpoint-bundle:
	php sandbox/tests/offline/recovery/regress_checkpoint_bundle.php

regress-code-release:
	php sandbox/tests/offline/recovery/regress_code_release.php

regress-upload-bundle:
	php sandbox/tests/offline/recovery/regress_upload_bundle.php

regress-effect-bundle:
	php sandbox/tests/offline/recovery/regress_effect_bundle.php

# WP-3.2: declared effects[] scored against observed journal writes, report-only.
# Beside regress-effect-bundle because it scores the same inventory
# EffectBundle.php:9-13 calls "the entire authority".
regress-effect-declaration-coverage:
	php sandbox/tests/offline/recovery/regress_effect_declaration_coverage.php

regress-ssh-rollback-certification:
	php sandbox/tests/offline/recovery/regress_ssh_rollback_certification.php

regress-pair-bootstrap-unit:
	bash sandbox/tests/offline/guards/regress_pair_bootstrap_unit.sh

# Direct live suites share one engine/root-bound lease and cleanup state
# machine. This fake-launcher leaf proves failure/partial-up/sequential-engine
# transitions without allocating Docker resources.
regress-live-pair-ownership:
	bash sandbox/tests/offline/guards/regress_live_pair_ownership.sh

# issue #3355: direct offline characterization of the extracted pair-budget
# resource lock. The lifecycle/bootstrap suite remains the broader facade
# proof; this target keeps the crash-safe lock boundary independently loaded.
regress-pair-budget-lock:
	bash sandbox/tests/offline/guards/regress_pair_budget_lock.sh

# issue #3355: direct offline characterization of the extracted pair-compose
# discovery filter (the `docker compose ls` jq query deciding which rows are
# a WPrism pair at all) -- fed synthetic compose ls JSON via a fake docker, no
# real docker or pair lifecycle involved. pair_compose_configure() itself
# (the docker-compose-argv/canonical-source half of this library) is already
# exercised for real by the bootstrap/candidate-source suites, which run the
# shipped pair.sh's own subcommands against a fake
# docker; this target covers the filter edge cases those runs don't reach
# (the legacy wprism-sandbox project's exclusion, malformed/empty compose ls
# output refusing rather than reporting zero pairs, and the Status/
# ConfigFiles/Name guards).
regress-pair-compose-unit:
	bash sandbox/tests/offline/guards/regress_pair_compose_unit.sh

# The MySQL 8.x evidence lane's engine selection (WPRISM_DB_ENGINE ->
# DB_CONTAINER/DB_CLIENT/DB_COMPOSE/DB_LABEL, pair.yml's WPRISM_DB_HOST default,
# and the .env line that carries it across the subprocess boundary every
# conformance/regress caller crosses). Offline like its siblings above: a fake
# docker records every invocation, so "the unknown-engine refusal precedes any
# container work" is asserted, not assumed. The load-bearing case is the
# DEFAULT one -- an unset WPRISM_DB_ENGINE must reproduce the four values pair.sh
# hard-coded and pair_db_sql's argv byte-for-byte.
regress-pair-db-engine:
	bash sandbox/tests/offline/guards/regress_pair_db_engine.sh

# issue #3355: offline contract for the narrow shared primitives used by the
# legacy R1 proof harnesses. A fake docker captures the public compose/wp
# commands and rewrite-file bytes; it does not need Docker or WordPress.
regress-proof-legacy-pair:
	bash sandbox/tests/offline/guards/regress_proof_legacy_pair.sh

# issue #3377: the exact-source gate. Offline like its bootstrap sibling above --
# a real scratch canonical checkout plus a real linked worktree (the trap's
# own shape) and a fake docker; genuine git is the mechanism under test, so it
# is deliberately NOT faked here.
regress-pair-candidate-source:
	bash sandbox/tests/offline/guards/regress_pair_candidate_source.sh

# issue #3343: a production refresh is an observation boundary, not a capture
# variant. This focused no-WordPress harness proves the exporter's
# read-only ledger validation, mutation prohibition, and semantic-record
# envelope without needing a sandbox database.
regress-refresh-export-unit:
	php sandbox/tests/offline/refresh/regress_refresh_export_unit.php

# The actual refresh snapshot/table gate, explicit SHOW schema facts and
# failure/shape/session controls; no information_schema authority is added.
regress-ledger-read-only-schema:
	php sandbox/tests/offline/refresh/regress_ledger_read_only_schema.php

.PHONY: regress-refresh-reference-projection
regress-refresh-reference-projection:
	php sandbox/tests/offline/refresh/regress_refresh_reference_projection.php

# --- live (docker/pair.sh-dependent), now in regress-live-list ---
regress-collision:
	bash sandbox/tests/live/regress_collision.sh

regress-env-set:
	@test -n "$(ENV_SET_PAIR)" || { echo 'ENV_SET_PAIR is required; choose an unused collision-resistant lowercase pair name' >&2; exit 2; }
	@test -n "$(ENV_SET_PORT1)" || { echo 'ENV_SET_PORT1 is required; choose an unused even port >=8900' >&2; exit 2; }
	@test -n "$(ENV_SET_PORT2)" || { echo 'ENV_SET_PORT2 is required; choose ENV_SET_PORT1 + 1' >&2; exit 2; }
	@test -n "$(WPRISM_EXPECTED_SOURCE_SHA)" || { echo 'WPRISM_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	ENV_SET_PAIR="$(ENV_SET_PAIR)" ENV_SET_PORT1="$(ENV_SET_PORT1)" ENV_SET_PORT2="$(ENV_SET_PORT2)" WPRISM_EXPECTED_SOURCE_SHA="$(WPRISM_EXPECTED_SOURCE_SHA)" bash sandbox/tests/live/regress_env_set.sh

regress-rank-math-commerce-multilingual:
	@test -n "$(RANK_MATH_COMBO_PAIR)" || { echo 'RANK_MATH_COMBO_PAIR is required; choose an unused collision-resistant lowercase pair name' >&2; exit 2; }
	@test -n "$(RANK_MATH_COMBO_PORT1)" || { echo 'RANK_MATH_COMBO_PORT1 is required; choose an unused even port >=8900' >&2; exit 2; }
	@test -n "$(RANK_MATH_COMBO_PORT2)" || { echo 'RANK_MATH_COMBO_PORT2 is required; choose RANK_MATH_COMBO_PORT1 + 1' >&2; exit 2; }
	@test -n "$(RANK_MATH_COMBO_EXPECTED_SOURCE_SHA)" || { echo 'RANK_MATH_COMBO_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	RANK_MATH_COMBO_PAIR="$(RANK_MATH_COMBO_PAIR)" RANK_MATH_COMBO_PORT1="$(RANK_MATH_COMBO_PORT1)" RANK_MATH_COMBO_PORT2="$(RANK_MATH_COMBO_PORT2)" RANK_MATH_COMBO_EXPECTED_SOURCE_SHA="$(RANK_MATH_COMBO_EXPECTED_SOURCE_SHA)" bash integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh

regress-rank-math-yoast-incompatibility:
	@test -n "$(RANK_MATH_YOAST_PAIR)" || { echo 'RANK_MATH_YOAST_PAIR is required; choose an unused collision-resistant lowercase pair name' >&2; exit 2; }
	@test -n "$(RANK_MATH_YOAST_PORT1)" || { echo 'RANK_MATH_YOAST_PORT1 is required; choose an unused even port >=8900' >&2; exit 2; }
	@test -n "$(RANK_MATH_YOAST_PORT2)" || { echo 'RANK_MATH_YOAST_PORT2 is required; choose RANK_MATH_YOAST_PORT1 + 1' >&2; exit 2; }
	@test -n "$(RANK_MATH_YOAST_EXPECTED_SOURCE_SHA)" || { echo 'RANK_MATH_YOAST_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	RANK_MATH_YOAST_PAIR="$(RANK_MATH_YOAST_PAIR)" RANK_MATH_YOAST_PORT1="$(RANK_MATH_YOAST_PORT1)" RANK_MATH_YOAST_PORT2="$(RANK_MATH_YOAST_PORT2)" RANK_MATH_YOAST_EXPECTED_SOURCE_SHA="$(RANK_MATH_YOAST_EXPECTED_SOURCE_SHA)" bash integration-scenarios/rank-math-yoast-incompatibility/tests/live/regress_rank_math_yoast_incompatibility.sh

regress-option-ref-scope:
	bash sandbox/tests/live/regress_option_ref_scope.sh

# Candidate-bound production leg for WooCommerce product permalink rebuilds
# under the exact supported Yoast, Polylang, and TEC co-install topology.
# Docker/pair.sh-dependent, so it is discoverable in regress-live-list rather
# than folded into the offline corpus.
regress-woocommerce-rewrite-coinstall:
	bash integration-scenarios/woocommerce-rewrite-coinstall/tests/live/regress_woocommerce_rewrite_coinstall.sh

regress-repository-authorization:
	bash sandbox/tests/live/regress_repository_authorization.sh

regress-repository-compiler-integration:
	bash sandbox/tests/live/regress_repository_compiler_integration.sh

regress-scope-gate:
	bash sandbox/tests/live/regress_scope_gate.sh

# issue #3344: offline, no docker — a scope resolved from explicit roots closes
# over declared edges only, every inclusion names the edge that pulled it in,
# and an unresolvable root is refused. Also pins the two defects that folding
# the compiler's reference walk and the closure walk into one enumeration
# retired (a parent cycle going undetected once its posts carried a term, and
# term locators reporting "terms.c"). Read-only: nothing here captures,
# promotes, or deletes.
regress-scope-closure:
	php sandbox/tests/offline/reference-scope/regress_scope_closure.php

# issue #3344: immutable, self-verifying scope evidence. This is distinct from
# the legacy closure preview suite: it covers normalized tombstone selectors,
# artifact/policy association, scoped upload/media/action/provider/effect
# filtering, and the no-target-contact contract.
regress-scope-contract:
	bash sandbox/tests/offline/reference-scope/regress_scope_contract.sh

# issue #3344 slice 4: target-bound scoped apply authority/session protocol.
# Pure PHP with an injected byte-CAS store; no WordPress or target contact.
regress-scoped-apply-session:
	php sandbox/tests/offline/reference-scope/regress_scoped_apply_session.php

# issue #3344: offline source contract for the scoped live harness itself. It
# proves a failed pair teardown retains its exact evidence rather than
# deleting roots and printing a pre-cleanup green verdict.
regress-scoped-apply-live-cleanup:
	php sandbox/tests/offline/guards/regress_scoped_apply_live_cleanup.php

# issue #3344: offline scoped authored-boundary and response-loss recovery matrix.
# The harness drives the public scoped session/observation/effect seams with
# injected CAS and target stubs; no Docker or WordPress target is required.
regress-scoped-apply-recovery:
	php sandbox/tests/offline/reference-scope/regress_scoped_apply_recovery.php

# issue #3344/issue #3338: offline operation-bound provider/native effect recovery.
regress-scoped-effect-reconciliation:
	php sandbox/tests/offline/reference-scope/regress_scoped_effect_reconciliation.php

# issue #3344 slice 5: target session/profile/receipt binding and the bounded
# checkpoint-only selection gate. Pure fake-ledger/DB PHP; no target contact.
regress-scoped-promotion-target:
	php sandbox/tests/offline/reference-scope/regress_scoped_promotion_target.php

# issue #3344 slice 5: public SSH host sequencing against fake SSH/SCP/WP plus a
# real isolated rollback-control root. No Docker, pair, or live target.
regress-scoped-promote-unit:
	bash sandbox/tests/offline/reference-scope/regress_scoped_promote_unit.sh

# issue #3344: the SSH live harness keeps its exact controlled-promotion failure
# evidence in a private non-secret directory, while unconditionally erasing
# the SSH/config/credential scratch tree. Source-only: no Docker or SSH host.
regress-ssh-adopt-evidence-retention:
	php sandbox/tests/offline/guards/regress_ssh_adopt_evidence_retention.php

# Shared extension primitives are exercised against a translated private
# remote tree: exact code/theme staging, certified-artifact digest refusal,
# bounded generation publication, and collision-safe tombstone cleanup.
regress-ssh-adopt-extension:
	bash sandbox/tests/offline/guards/regress_ssh_adopt_extension.sh

.PHONY: regress-core-ssh-deletion-contract
regress-core-ssh-deletion-contract:
	php sandbox/tests/offline/guards/regress_core_ssh_deletion_contract.php

# issue #3344: host/agent scope transport boundary — canonical compact request
# forwarding, refusal before target contact, and ordinary unscoped passthrough.
regress-scope-wire:
	php sandbox/tests/offline/reference-scope/regress_scope_wire.php

# issue #3344 slice 4 live proof: public host CLI -> DockerTransport -> scoped
# target plan/apply/verification.  It requires an explicitly allocated,
# disposable pair and a clean exact-source SHA; unlike offline regressions it
# is intentionally absent from regress-offline-all.
#
#   make regress-scoped-apply-live \
#     SCOPED_APPLY_LIVE_PAIR=wprismscopedapply \
#     SCOPED_APPLY_LIVE_PORT1=8900 SCOPED_APPLY_LIVE_PORT2=8901 \
#     WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
regress-scoped-apply-live:
	@test -n "$(SCOPED_APPLY_LIVE_PAIR)" || { echo 'SCOPED_APPLY_LIVE_PAIR is required; use an unused disposable pair name' >&2; exit 2; }
	@test -n "$(SCOPED_APPLY_LIVE_PORT1)" || { echo 'SCOPED_APPLY_LIVE_PORT1 is required; choose a free even port >= 8900' >&2; exit 2; }
	@test -n "$(SCOPED_APPLY_LIVE_PORT2)" || { echo 'SCOPED_APPLY_LIVE_PORT2 is required; use PORT1 + 1' >&2; exit 2; }
	@test -n "$(WPRISM_EXPECTED_SOURCE_SHA)" || { echo 'WPRISM_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	SCOPED_APPLY_LIVE_PAIR="$(SCOPED_APPLY_LIVE_PAIR)" SCOPED_APPLY_LIVE_PORT1="$(SCOPED_APPLY_LIVE_PORT1)" SCOPED_APPLY_LIVE_PORT2="$(SCOPED_APPLY_LIVE_PORT2)" WPRISM_EXPECTED_SOURCE_SHA="$(WPRISM_EXPECTED_SOURCE_SHA)" bash sandbox/tests/live/regress_scoped_apply_live.sh

# issue #3344 slice 6 live proof: the SAME scope contract's selected-identity
# set flows unchanged through scope -> capture -> refresh-export -> plan ->
# apply, then an independently-recomputed scope on the TARGET after
# mutation reproduces the SOURCE's original closure byte-for-byte. Explicit
# disposable-pair inputs, absent from regress-offline-all, same rationale as
# regress-scoped-apply-live above. Deliberately does not cover scoped
# promote/rollback: cli/wprism refuses scoped promotion outright over anything
# but SshTransport, an entirely different live harness than this one.
#
#   make regress-scope-chain-stability \
#     SCOPE_CHAIN_PAIR=wprismscopechain \
#     SCOPE_CHAIN_PORT1=8900 SCOPE_CHAIN_PORT2=8901 \
#     WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
regress-scope-chain-stability:
	@test -n "$(SCOPE_CHAIN_PAIR)" || { echo 'SCOPE_CHAIN_PAIR is required; use an unused disposable pair name' >&2; exit 2; }
	@test -n "$(SCOPE_CHAIN_PORT1)" || { echo 'SCOPE_CHAIN_PORT1 is required; choose a free even port >= 8900' >&2; exit 2; }
	@test -n "$(SCOPE_CHAIN_PORT2)" || { echo 'SCOPE_CHAIN_PORT2 is required; use PORT1 + 1' >&2; exit 2; }
	@test -n "$(WPRISM_EXPECTED_SOURCE_SHA)" || { echo 'WPRISM_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD' >&2; exit 2; }
	SCOPE_CHAIN_PAIR="$(SCOPE_CHAIN_PAIR)" SCOPE_CHAIN_PORT1="$(SCOPE_CHAIN_PORT1)" SCOPE_CHAIN_PORT2="$(SCOPE_CHAIN_PORT2)" WPRISM_EXPECTED_SOURCE_SHA="$(WPRISM_EXPECTED_SOURCE_SHA)" bash sandbox/tests/live/regress_scope_chain_stability.sh

regress-snapshot-meta:
	bash sandbox/tests/live/regress_snapshot_meta.sh

regress-generic-reference-shapes:
	bash sandbox/tests/live/regress_generic_reference_shapes.sh

# issue #3344 live SSH adoption/scoped-promotion evidence must be allocated by
# its operator and bind a clean standalone clone's exact candidate commit:
#   make regress-ssh-adopt ADOPT_FIXTURE=<unique-name> ADOPT_SSH_PORT=<free-port> \
#     WPRISM_EXPECTED_SOURCE_SHA=$$(git rev-parse HEAD)
regress-ssh-adopt:
	@test -n "$(ADOPT_FIXTURE)" || { echo 'ADOPT_FIXTURE is required; choose an unused lowercase fixture name' >&2; exit 2; }
	@test -n "$(ADOPT_SSH_PORT)" || { echo 'ADOPT_SSH_PORT is required; choose an unused port in 8900..65535' >&2; exit 2; }
	@test -n "$(WPRISM_EXPECTED_SOURCE_SHA)" || { echo 'WPRISM_EXPECTED_SOURCE_SHA is required; bind the run to git rev-parse HEAD' >&2; exit 2; }
	ADOPT_FIXTURE="$(ADOPT_FIXTURE)" ADOPT_SSH_PORT="$(ADOPT_SSH_PORT)" WPRISM_EXPECTED_SOURCE_SHA="$(WPRISM_EXPECTED_SOURCE_SHA)" bash sandbox/tests/live/regress_ssh_adopt.sh

.PHONY: regress-core-ssh-deletion
regress-core-ssh-deletion:
	@test -n "$(ADOPT_FIXTURE)" || { echo 'ADOPT_FIXTURE is required; choose an unused lowercase fixture name' >&2; exit 2; }
	@test -n "$(ADOPT_SSH_PORT)" || { echo 'ADOPT_SSH_PORT is required; choose an unused port in 8900..65535' >&2; exit 2; }
	@test -n "$(WPRISM_EXPECTED_SOURCE_SHA)" || { echo 'WPRISM_EXPECTED_SOURCE_SHA is required; bind the run to git rev-parse HEAD' >&2; exit 2; }
	ADOPT_FIXTURE="$(ADOPT_FIXTURE)" ADOPT_SSH_PORT="$(ADOPT_SSH_PORT)" WPRISM_EXPECTED_SOURCE_SHA="$(WPRISM_EXPECTED_SOURCE_SHA)" bash sandbox/tests/live/regress_core_ssh_deletion.sh

certify-ssh-adoption-roundtrip:
	bash sandbox/tests/certify/certify_ssh_adoption_roundtrip.sh

certify-ssh-rollback:
	bash sandbox/tests/certify/certify_ssh_rollback.sh

regress-user-meta:
	bash sandbox/tests/live/regress_user_meta.sh

# issue #3285: one target bundling every offline (no-docker) regress suite --
# cheap enough to run at every local close-gate. Hosted CI is intentionally
# disabled for this repository, so this local bundle plus independent review
# is the merge gate. Live suites are deliberately NOT here -- see
# regress-live-list. make's default (non -j) prerequisite order is the listed
# order and it stops at the first failure, exactly the fail-fast behaviour a
# local close-gate wants (no point burning minutes on suite 21 when suite 3
# already broke).
#
# issue #3285 fast-follow and recovery closure: regress-coverage-offline
# (issue #3290's own suite, asub's PR #82) had a real Makefile target the whole
# time but landed after this bundle's own survey was authored, so it slipped
# in unbundled exactly the way this target exists to prevent -- team-lead
# caught it by inspection. Re-running that survey the same day turned up two
# more orphans of the same shape. regress-bundle-coverage made that class of
# drift DETECTABLE; deriving the list makes it IMPOSSIBLE.
#
# So the prerequisite list and both status counts are no longer written here.
# tools/offline-corpus.php derives them from the tree -- every suite file
# under sandbox/tests/offline/ that no other suite runs, mapped to the target
# whose recipe runs it -- and emits tools/offline-corpus.mk, which
# `make release-gate` byte-compares exactly the way it already byte-compares
# agent/wprism-classmap.php and the capability document. The two integers that
# used to live on the two echo lines had drifted apart (294 against 293,
# though both were 207 when they were introduced together in 1ef9577c) --
# which is what a number nothing computes eventually does. Adding a suite is
# now its file, its own leaf target in this Makefile, and
# `php tools/offline-corpus.php`.
include tools/offline-corpus.mk

regress-offline-diagnostics:
	bash sandbox/tests/offline/guards/regress_offline_diagnostics.sh

# issue #3285: NOT auto-bundled (docker/pair.sh budget -- this project runs many
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
	@echo "  regress-core-lifecycle                    own disposable pair (parameterized: CORE_LIFECYCLE_PAIR/PORT1/PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; exact offline WordPress 7.0.3 -> 7.1 -> rollback/reinstall)"
	@echo "  regress-core-data-boundary                own disposable pair (parameterized: CORE_DATA_BOUNDARY_PAIR/PORT1/PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; exact offline core per run: CORE_DATA_BOUNDARY_WORDPRESS/_IMAGE, default 7.1; re-run per exercised series)"
	@echo "  regress-core-scope-platform               own disposable pair (parameterized: CORE_SCOPE_PLATFORM_PAIR/PORT1/PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; exact claimed WordPress 6.9.2/7.0.2/7.0.3/7.1 x PHP 8.3/8.4 matrix, a below-range 6.8.3 refusal and a past-the-maximum PHP 8.5 refusal)"
	@echo "  regress-core-scope-database               own disposable pair (parameterized: CORE_SCOPE_DATABASE_PAIR/PORT1/PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; one round trip per CLAIMED engine on sandbox/db.yml + sandbox/db.mysql.yml, carrying docs/mysql-dialect-audit.md's five probe groups)"
	@echo "  regress-database-boundary-live            own disposable pair (parameterized: DATABASE_BOUNDARY_PAIR/PORT1/PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; MariaDB/MySQL LIMIT-0 metadata lock + no-definer-invocation view proof + MariaDB sequence refusal)"
	@echo "  regress-native-permalinks-live            own disposable MariaDB pair (NATIVE_PERMALINK_PAIR/PORT1/PORT2; exact source; native SDK URL families, hostile cache/hook refusal and complete row preservation)"
	@echo "  regress-attachment-portability            pair codexmac3265 8964/8965"
	@echo "  regress-fatal-mutations-live              pair codexmaca3206 9210/..."
	@echo "  regress-multisite-refusal                 own disposable pair (parameterized: MULTISITE_PAIR/PORT1/PORT2)"
	@echo "  regress-polylang-multisite-refusal        own disposable pair (parameterized: POLYLANG_MULTISITE_PAIR/PORT1/PORT2; exact Polylang 3.8.6 populated multisite refusal)"
	@echo "  regress-woocommerce-multisite-refusal     own disposable pair (parameterized: WOO_MULTISITE_PAIR/PORT1/PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; exact WooCommerce 11.0.1 HPOS populated multisite refusal)"
	@echo "  regress-polylang-tec-rewrite-coinstall    own disposable pair (parameterized: POLYLANG_TEC_REWRITE_PAIR/PORT1/PORT2; exact Polylang 3.8.6 + TEC 6.17.2 child-process/topology sweep)"
	@echo "  regress-the-events-calendar-multisite-refusal own disposable pair (parameterized: TEC_MULTISITE_PAIR/TEC_MULTISITE_PORT1/TEC_MULTISITE_PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; exact TEC 6.17.2/6.17.3 populated multisite refusal)"
	@echo "  regress-adapter-theme-range               pair asub3222tr 8918/8919"
	@echo "  regress-adapter-plugin-range              pair a3487pr 8988/8989 (parameterized: PLUGIN_RANGE_PAIR/PLUGIN_RANGE_PORT1/PLUGIN_RANGE_PORT2)"
	@echo "  regress-provider-contract-live            pair claudemacb3338 8930/8931"
	@echo "  regress-adapter-authoring-live            own disposable pair (required: ADAPTER_AUTHORING_PAIR/ADAPTER_AUTHORING_PORT1/ADAPTER_AUTHORING_PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate)"
	@echo "  regress-local-bootstrap-live              own disposable pair (parameterized: LOCAL_BOOTSTRAP_PAIR/LOCAL_BOOTSTRAP_PORT1/LOCAL_BOOTSTRAP_PORT2; exact candidate gate)"
	@echo "  regress-local-verified-rollback-live      own disposable pair (parameterized: LOCAL_VERIFIED_PAIR/LOCAL_VERIFIED_PORT1/LOCAL_VERIFIED_PORT2; exact candidate gate; signed verified rollback on a local target)"
	@echo "  regress-plan-category-summary-live        pair codexsma3345 9060/9061 (parameterized: PLAN_CATEGORY_SUMMARY_PAIR/PLAN_CATEGORY_SUMMARY_PORT1/PLAN_CATEGORY_SUMMARY_PORT2)"
	@echo "  regress-provider-requirements-live        pair claudemacb3317 8930/8931 (parameterized: PROVIDER_REQUIREMENTS_PAIR/PROVIDER_REQUIREMENTS_PORT1/PROVIDER_REQUIREMENTS_PORT2)"
	@echo "  regress-scoped-apply-live                 explicit SCOPED_APPLY_LIVE_PAIR/PORT1/PORT2 + WPRISM_EXPECTED_SOURCE_SHA (public scoped plan/apply exact-source proof)"
	@echo "  regress-scope-chain-stability              explicit SCOPE_CHAIN_PAIR/PORT1/PORT2 + WPRISM_EXPECTED_SOURCE_SHA (scope->capture->refresh-export->plan->apply identity-set stability)"
	@echo "  regress-parent-scoped-natural-key         pair claudemacb3318 8930/8931 (parameterized: PARENT_KEY_PAIR/PARENT_KEY_PORT1/PARENT_KEY_PORT2)"
	@echo "  regress-menu-item-meta-gate               pair asub3275 8954/8955"
	@echo "  regress-widgets                           pair awid3278 8960/..."
	@echo "  regress-promotion                         pair codexmaca3216 8920/... (also runs in CI as code-half-grind's sibling)"
	@echo "  regress-promotion-lock                    pair codexmac3217 8900/... (runs in CI: code-half-live-lock)"
	@echo "  regress-capture-concurrency               own disposable pair (required: CONCURRENCY_PAIR/CONCURRENCY_PORT1/CONCURRENCY_PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate)"
	@echo "  regress-acf-term-options-fields           pair asub3263 (parameterized: PAIR/PORT1/PORT2)"
	@echo "  regress-collision                         legacy docker-compose.yml --profile fx"
	@echo "  regress-entity-type-width                 pair amergety"
	@echo "  regress-env-set                           explicit ENV_SET_PAIR/ENV_SET_PORT1/ENV_SET_PORT2 + WPRISM_EXPECTED_SOURCE_SHA (leased complete namespace; verified teardown/release before PASS)"
	@echo "  regress-rank-math-commerce-multilingual   explicit RANK_MATH_COMBO_PAIR/RANK_MATH_COMBO_PORT1/RANK_MATH_COMBO_PORT2/RANK_MATH_COMBO_EXPECTED_SOURCE_SHA (leased namespace; two complete plugin-order legs; one owned reset; verified release)"
	@echo "  regress-rank-math-yoast-incompatibility   explicit RANK_MATH_YOAST_PAIR/RANK_MATH_YOAST_PORT1/RANK_MATH_YOAST_PORT2/RANK_MATH_YOAST_EXPECTED_SOURCE_SHA (leased namespace; both refusal orders; verified release)"
	@echo "  regress-option-ref-scope                  legacy docker-compose.yml --profile r1b"
	@echo "  regress-pmpro-composite-ref               pair asnaprt"
	@echo "  regress-woocommerce-rewrite-coinstall     own disposable pair (parameterized: WOO_REWRITE_COINSTALL_PAIR/PORT1/PORT2; WPRISM_EXPECTED_SOURCE_SHA exact candidate gate; Woo 11.0.1 + Yoast 28.3 + Polylang 3.8.6 + TEC 6.17.2 product routes and refusal/retry)"
	@echo "  regress-repository-authorization          pair conf 8806/8807"
	@echo "  regress-repository-compiler-integration   pair conf 8806/8807"
	@echo "  regress-scope-gate                        pair codexmac3229 8900/8901"
	@echo "  regress-snapshot-meta                     pair w1a"
	@echo "  regress-generic-reference-shapes         neutral taxonomy/sidecar fixture pair"
	@echo "  regress-ssh-adopt                         explicit ADOPT_FIXTURE/ADOPT_SSH_PORT/WPRISM_EXPECTED_SOURCE_SHA standalone SSH scoped-promotion path"
	@echo "  regress-core-ssh-deletion                 explicit ADOPT_FIXTURE/ADOPT_SSH_PORT/WPRISM_EXPECTED_SOURCE_SHA signed core deletion, FK refusal and retry"
	@echo "  certify-ssh-rollback                     four disposable containers: two SSH hosts + two MariaDB servers"
	@echo "  regress-tec-regen                         pair asnaptec"
	@echo "  regress-user-meta                         pair umeta3268 9301/9302"
	@echo "  regress-environment-materializer-live     pair wprismenvmaterialize 9100/9101 (public env materialize/reap; user-authorized)"
	@echo "  regress-env-provider-conformance-live     pair envprovcheck 9200/9201 (wprism env provider-check vs tools/reference-env-provider.php)"
	@echo "  regress-rehearsal-containment-live        pair containlive 9340/9341 + standalone preview 9342 (three environments; real route/mail/credential probes)"
	@echo "  regress-wprism-init                         pair codexmaca3336 9300/9301 (parameterized: WPRISM_INIT_PAIR/WPRISM_INIT_PORT1/WPRISM_INIT_PORT2)"
	@echo "  regress-coverage                         needs an already-up pair with WooCommerce active (parameterized: WPRISM_PAIR)"
	@echo "  regress-woo-attribute-deletion            pair wooattrdel 8996/8997 (parameterized: WOOATTRDEL_PAIR/WOOATTRDEL_PORT1/WOOATTRDEL_PORT2)"
	@echo "  grind-ecommerce-developer-live            explicit ECOMMERCE_PAIR/PORT1/PORT2; run only with owner authorization"
	@echo "  grind-mup                                 pair mup 9400/9401 (MUP_PAIR/PORT1/PORT2; MUP_STEP11=required|record-gap) -- the MUP end-to-end loop, docs/grind/mup.md"
	@echo "  grind-adapter-walk                        pair awalk 9500/9501 (WALK_PAIR/PORT1/PORT2; WALK_SCENARIOS=S1,S2,S3,S4) -- the operator-authored-adapter walk, docs/grind/adapter-walk.md"
	@echo "  grind-adoption                            pair adopt 9600/9601 (ADOPT_PAIR/PORT1/PORT2; ADOPT_SITUATIONS=A1..A10) -- ten progressive-adoption situations, docs/grind/adoption.md"
	@echo ""
	@echo "Other grind-*/certify-* targets are a separate, already-governed category (see this target's comment)."

# issue #3285 fast-follow: the drift guard. Runs the same "every regress_*
# file needs a bundle or live-list entry" survey that built regress-
# offline-all/regress-live-list in the first place, every time this runs --
# see the suite's own header for why the one-off fix wasn't enough.
regress-bundle-coverage:
	bash sandbox/tests/offline/guards/regress_bundle_coverage.sh

# The other direction of the same drift class. regress-bundle-coverage asks
# whether every suite FILE has a Makefile entry; this asks whether every
# Makefile entry names a file that exists, under a target spelled the way
# tools/affected.php maps names, in a recipe shape tools/offline.php's
# serial-group scan can actually read (it matches literal paths against
# UNEXPANDED `make -p` recipes, so a `$(VAR)` in a path silently disables
# collision detection for that suite). See the suite header.
regress-suite-wiring:
	php sandbox/tests/offline/guards/regress_suite_wiring.php

# issue #3483: a third drift direction the two above cannot see. The generated
# fixtures under sandbox/tests/fixtures/{adapter-walk,mup}/ are wired and their
# files exist, and both makers had still stopped reproducing them -- mup's died
# partway on a builder signature change, adapter-walk's produced four documents
# its own grind rejected -- because nothing ran a maker and compared. This does.
regress-fixture-makers:
	bash sandbox/tests/offline/guards/regress_fixture_makers.sh

# The one guard in this directory aimed at the PRODUCT rather than the estate.
# AGENTS.md rule 2 names one refusal a shipped package byte trips
# (compiled_artifact_manifest_mismatch) and rule 8 names one more; the tree
# actually carries 44 gates across 76 sites on the platform/manifest/authority
# axes. This re-derives that candidate set from the tree on every run and
# refuses a site the reviewed register in tools/platform-move-gates.json does
# not carry, so "the gate nobody enumerated" fails here instead of on a
# customer site. The register's 6 exclusions are the same discipline pointed
# the other way -- a deliberate drop is a reviewed line, and widening the
# predicate until it absorbs one fails too. See the suite header for the
# predicate, its two arms, and the measured reason they stay narrow.
regress-platform-move-gates:
	php sandbox/tests/offline/guards/regress_platform_move_gates.php

regress-adapter-package-current-paths:
	php sandbox/tests/offline/guards/regress_adapter_package_current_paths.php

# WP-4.10 / spec/repo-format.md § v3.9: the namespace grammar for the three
# flat identity spaces, and the CLOSED grandfather list under it. At
# spec_version 3 an out-of-tree adapter name is <vendor>-<name> and its provider
# ids sit in that same namespace, which is what gives an authority's
# `adapter_names: ["<vendor>-*"]` scope (WP-4.8) something to bind; the shipped
# names and permanent id_kind floor are enumerated in agent/src, never under an
# adapter package or platform-library package where rule 2 would make them an
# adapter-digest input. The suite
# proves the rule is inert below v3 while the current spec-3 engine enforces it, that
# `acme-cache` and `zeta-cache` coexist in one pin set while all shipped
# names load unchanged, that the uniqueness and case-folding refusals did not
# move, and -- by driving `tools/wire-surface.php --check` against a fixture
# library carrying an additional unprefixed name -- that the release gate
# refuses one. `id_kind` gets no rule at all: R-17 forbids it.
regress-identity-namespaces:
	php sandbox/tests/offline/guards/regress_identity_namespaces.php

# WP-5.5: two pinned manifests claiming one plugin (or one theme) stop refusing
# when site.wprism.json's `policy.adapter_claims` says which claim is in force,
# and the displaced claimant is REPORTED as `displaced_by_resolution` instead
# of vanishing. Measures the far larger unchanged half at the same time: the
# unresolved refusal asserted against a LITERAL sentence in both arms, the
# redundant identical-range pair still tolerated, the displaced manifest still
# pinned and still loaded with every other declaration intact, one range in
# force and nothing merged, an unrelated cross-manifest conflict in the same
# set still refusing, and a resolution that decides nothing -- the drift case,
# after one of its manifests is unpinned -- refusing by name.
regress-plugin-claim-resolution:
	php sandbox/tests/offline/guards/regress_plugin_claim_resolution.php

# A manifest-owned exact-plugin incompatibility is grammar, not plugin logic:
# malformed declarations refuse locally and a conflicting pin set refuses in
# the shared policy finalizer with the same verdict in both pin orders, before
# any transaction or recovery state can be created.
regress-plugin-incompatibility:
	php sandbox/tests/offline/policy/regress_plugin_incompatibility.php

# WP-2.6: the adapter test kit. Adopt assembles the package library into the
# staged agent and tars exactly `agent recovery`, so sandbox/ -- where the ability to PROVE
# an adapter lives -- reaches nobody, and a third party reinvents the harness.
# sandbox/tests/lib/README.md counts what that already costs in-tree: 42
# bespoke $wpdb fakes that answer null where FakeWpdb::unsupported() throws by
# name. tools/adapter-kit.php packages the generic half; this holds up the four
# properties that make the packaging worth anything -- assembled not copied, it
# runs where it lands, the refusal survives the copy, and it is still no part
# of the adoption archive. See the suite header.
regress-adapter-test-kit:
	php sandbox/tests/offline/guards/regress_adapter_test_kit.php

# The flag-day rehearsal. Nine synthetic site repositories -- bare-name pins,
# content pins, `source:"site"` overrides, certified site adapters under two
# operator keys, compiled artifacts, frozen snapshots, a scope contract, an
# identity sidecar and a recovery checkpoint -- driven against two agent states
# in child processes (a state is a pair of `define()`s, so it must be a
# process). It measures what the bump moves rather than arguing it: shipped
# digests, manifest_hash and site_hash hold; artifact_hash moves fleet-wide;
# a certified site adapter is withdrawn and its site still loads. It ENUMERATES
# every `gate` row of tools/platform-move-gates.json, so a gate the estate does
# not drive is a named gap with a reviewed reason rather than silence. Runs the
# whole estate four times (build, A, B, A) in about 5s.
regress-spec-migration-rehearsal:
	php sandbox/tests/offline/guards/regress_spec_migration_rehearsal.php

regress-init-contract:
	php sandbox/tests/offline/cli/regress_init_contract.php

.PHONY: regress-init-widgets
regress-init-widgets:
	php sandbox/tests/offline/cli/regress_init_widgets.php

regress-bound-helper:
	php sandbox/tests/offline/capture/regress_bound_helper.php

regress-wprism-init:
	bash sandbox/tests/live/regress_wprism_init.sh
