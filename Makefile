COMPOSE = docker compose -f sandbox/docker-compose.yml

.PHONY: up down clean setup seed spike-a spike-b spike-c spike-d spike-e spike-f spike-g spikes conformance-% cli-smoke cli-triage-smoke lint-smoke grind-r1c grind-r1a grind-r3a grind-r3b pair-up pair-reset pair-destroy pair-list regress-pa-attributes regress-shipping-zones certify-merge certify-version-skew-merge certify-adversarial-matrix certify-deletion-matrix regress-capture-publish regress-code-drift regress-option-subkeys regress-option-reconciliation regress-fatal-mutations-unit regress-fatal-mutations regress-adapter-contract regress-adapter-theme-range regress-discovery-completeness regress-core-semantics regress-promotion-unit regress-promotion regress-promotion-lock regress-capture-secret-scan regress-order-preserving

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

# DUO-3222's one genuinely live leg: Deploy::code_mismatch()'s new THEME
# version_range check, called directly against a real bundled WordPress
# theme (twentytwentyfour, zero network installs) via `wp eval` — no
# site-repo/plan/apply pipeline needed, since code_mismatch() is a plain
# static function. Own sandbox/bin/pair.sh pair (asub3222tr 8918/8919).
regress-adapter-theme-range:
	bash sandbox/tests/regress_adapter_theme_range.sh

# DUO-3216: offline host-orchestrator state-machine contract — one compiled
# artifact, durable pre-mutation DB checkpoint, deploy -> apply ordering,
# stop-on-first-failure, and exact transport-shaped restore instructions.
regress-promotion-unit:
	bash sandbox/tests/regress_promotion_unit.sh

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
