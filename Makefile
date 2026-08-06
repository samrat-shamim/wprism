COMPOSE = docker compose -f sandbox/docker-compose.yml

.PHONY: up down clean setup seed spike-a spike-b spike-c spike-d spike-e spike-f spike-g spikes conformance-% cli-smoke cli-triage-smoke lint-smoke grind-r1c grind-r1a pair-up pair-reset pair-destroy pair-list

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
