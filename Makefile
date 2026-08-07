COMPOSE = docker compose -f sandbox/docker-compose.yml

.PHONY: up down clean setup seed spike-a spike-b spike-c spike-d spike-e spike-f spike-g spikes conformance-% cli-smoke cli-triage-smoke lint-smoke grind-r1c grind-r1a grind-r3a grind-r3b grind-code-half pair-up pair-reset pair-destroy pair-list regress-pa-attributes regress-shipping-zones regress-natural-key-rename certify-merge certify-version-skew-merge certify-adversarial-matrix certify-deletion-matrix certify-version-matrix regress-capture-publish regress-code-drift regress-option-subkeys regress-option-reconciliation regress-fatal-mutations-unit regress-fatal-mutations regress-adapter-contract regress-adapter-theme-range regress-discovery-completeness regress-core-semantics regress-attachment-portability regress-repository-compiler regress-promotion-unit regress-promotion regress-promotion-lock regress-capture-secret-scan regress-order-preserving regress-capture-concurrency regress-menu-item-meta-gate regress-acf-meta-interpreter regress-widgets regress-code-revision-enforcement regress-code-descriptor-unit regress-code-materializer-unit regress-code-completed-unit regress-code-stage-lock-unit regress-code-ledger-transaction-unit regress-plan-summary-code-drift regress-template-mismatch regress-code-deploy-unit regress-lifecycle-state-handoff code-half-unit \
	regress-block-refs regress-composite-ref regress-doctor-env-values regress-dynamic-options-policy \
	regress-env-options-policy regress-export-manifest-roundtrip regress-manifest-reclassification-policy \
	regress-menu-field-reclassification-policy regress-regen-dependency-policy regress-shortcode-refs \
	regress-term-meta regress-url-query-refs regress-acf-term-options-fields regress-collision \
	regress-entity-type-width regress-env-set regress-option-ref-scope regress-pmpro-composite-ref \
	regress-repository-authorization regress-repository-compiler-integration regress-scope-gate \
	regress-snapshot-meta regress-ssh-adopt regress-tec-regen regress-user-meta \
	regress-option-name-refs-wiring regress-offline-all regress-live-list

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
# deletions/guards blocks on plugin-owned typed-snapshot tables, beyond
# core's own (checks/core.sh). woocommerce's wc_order_product_lookup guard,
# ninja-forms' three-guard shape (two cross-table + one postmeta) with the
# cascade-vs-guard distinction proven live, and paid-memberships-pro's
# empty-guards composite_ref table. See the script's own header for the
# multiple failed designs it took to get the ninja-forms leg right.
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
# injection matrix for insert/update/delete/transactions/rebuilders/ledger.
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
# artifact, pre-checkpoint lease, deploy -> apply ordering, stop-on-first-
# failure, exact cleanup, and serialized transport-shaped restore instructions.
regress-promotion-unit:
	bash sandbox/tests/regress_promotion_unit.sh

# First functional code-half's fast, offline boundary suite: descriptor
# revision enforcement, truthful status rendering, template reconciliation
# detection, and both public host orchestration paths. Keep this separate
# from the Docker/live promotion regression below so it is cheap to run while
# iterating on the safety gates.
code-half-unit: regress-repository-compiler regress-code-revision-enforcement regress-code-descriptor-unit regress-code-materializer-unit regress-code-completed-unit regress-code-stage-lock-unit regress-code-ledger-transaction-unit regress-plan-summary-code-drift regress-template-mismatch regress-code-deploy-unit regress-promotion-unit regress-lifecycle-state-handoff

regress-repository-compiler:
	bash sandbox/tests/regress_repository_compiler.sh

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

regress-code-ledger-transaction-unit:
	bash sandbox/tests/regress_code_ledger_transaction_unit.sh

regress-plan-summary-code-drift:
	php sandbox/tests/regress_plan_summary_code_drift.php

regress-template-mismatch:
	php sandbox/tests/regress_template_mismatch.php

regress-code-deploy-unit:
	bash sandbox/tests/regress_code_deploy_unit.sh

regress-lifecycle-state-handoff:
	php sandbox/tests/regress_lifecycle_state_handoff.php

# Clean-room code-half grind: no code bind mount. Public promotion must
# materialize, migrate, heal drift, fail atomically, deactivate before prune,
# preserve unrelated components, and converge both independent revision
# receipts. See docs/grind/code-half.md.
grind-code-half:
	bash sandbox/tests/grind_code_half.sh

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
regress-block-refs:
	bash sandbox/tests/regress_block_refs.sh

regress-composite-ref:
	bash sandbox/tests/regress_composite_ref.sh

regress-doctor-env-values:
	php sandbox/tests/regress_doctor_env_values.php

regress-dynamic-options-policy:
	bash sandbox/tests/regress_dynamic_options_policy.sh

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

regress-shortcode-refs:
	bash sandbox/tests/regress_shortcode_refs.sh

regress-term-meta:
	php sandbox/tests/regress_term_meta.php

regress-url-query-refs:
	bash sandbox/tests/regress_url_query_refs.sh

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

regress-snapshot-meta:
	bash sandbox/tests/regress_snapshot_meta.sh

regress-ssh-adopt:
	bash sandbox/tests/regress_ssh_adopt.sh

regress-tec-regen:
	bash sandbox/tests/regress_tec_regen.sh

regress-user-meta:
	bash sandbox/tests/regress_user_meta.sh

# DUO-3285: one target bundling every offline (no-docker) regress suite --
# cheap enough to run at every close-gate, wired into CI as a required check
# (.github/workflows/conformance.yml). 33 suites: code-half-unit's own 12
# (already bundled, referenced not repeated), 7 that had a Makefile target
# but were in no bundle CI ever ran (regress-capture-publish through
# regress-order-preserving below), the 12 newly-wired offline scripts,
# regress-option-name-refs-wiring -- a genuinely NEW suite, not merely
# newly-wired, written closing the real gap this bundle's own first live
# run surfaced (see that suite's own header) -- and regress-natural-key-
# rename, DUO-3237's own offline suite, landed on main concurrently with
# this issue's own survey and folded in here rather than left as a 34th
# orphan the moment this PR merges. Plain prerequisite list, same idiom
# as code-half-unit itself -- make's default (non -j) prerequisite order
# is the listed order, and it stops at the first failure, exactly the
# fail-fast behavior a required CI gate wants (no point burning minutes
# on suite 21 when suite 3 already broke). Live suites are deliberately
# NOT here -- see regress-live-list.
regress-offline-all: code-half-unit \
	regress-capture-publish regress-adapter-contract regress-interpreter-policy \
	regress-acf-meta-interpreter regress-fatal-mutations-unit regress-capture-secret-scan \
	regress-order-preserving \
	regress-block-refs regress-composite-ref regress-doctor-env-values \
	regress-dynamic-options-policy regress-env-options-policy regress-export-manifest-roundtrip \
	regress-manifest-reclassification-policy regress-menu-field-reclassification-policy \
	regress-regen-dependency-policy regress-shortcode-refs regress-term-meta regress-url-query-refs \
	regress-option-name-refs-wiring regress-natural-key-rename
	@echo "regress-offline-all: 33 offline suites green"

# DUO-3285: NOT auto-bundled (docker/pair.sh budget -- this project runs many
# agents concurrently against a shared docker host, see sandbox/bin/pair.sh's
# own "1 docker core per RUNNING pair" discipline) -- enumerable instead, so
# a claim touching a mechanism can find its own suite without grepping this
# file by hand. Prints name + pair/environment requirement per suite; runs
# nothing. grind-*/certify-* targets are a DIFFERENT, already-governed
# category (grind-* are whole-scenario exercises with their own docs/grind/
# reports; certify-* are explicitly excluded from CI by owner decision,
# commit 1efb6df) and are intentionally not listed here.
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
	@echo "  regress-adapter-theme-range               pair asub3222tr 8918/8919"
	@echo "  regress-menu-item-meta-gate               pair asub3275 8954/8955"
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
	@echo "  regress-ssh-adopt                         standalone SSH host, own docker image (NOT pair.sh) -- DUO-3257/DUO-3281"
	@echo "  regress-tec-regen                         pair asnaptec"
	@echo "  regress-user-meta                         pair umeta3268 9301/9302"
	@echo ""
	@echo "grind-*/certify-* targets are a separate, already-governed category -- not listed here (see this target's own comment in the Makefile)."
