#!/usr/bin/env bash
# Regression — DUO-3339/B2: the THIRD adapter source, `<plugin-dir>/duo-adapter
# .json`, bundled by an ACTIVE plugin. Precedence (shipped > site > plugin) and
# a per-adapter refusal scope, both of which are decisions AGAINST the obvious
# implementation and both of which are load-bearing for sites that will never
# read this file: a plugin update that starts bundling a colliding name must
# cost an operator a reported row, not every command they have.
#
# All pure PHP and file I/O. WP_PLUGIN_DIR is a define(), so the harness runs
# each fixture in a clean PHP CHILD against the REAL agent/src/*.php and the
# REAL shipped manifest library — the same child-process idiom
# sandbox/tests/regress_site_adapter_certification.php:807-830 established, and
# for the same reason. No docker, no sandbox pair, no WordPress bootstrap, no
# $wpdb stub, nothing mocked; the only WordPress surfaces involved are the two
# the scan itself keys on (WP_PLUGIN_DIR and get_option('active_plugins')),
# supplied as the narrow stubs a real target would satisfy.
#
# The real shipped library is used rather than a synthetic one because the
# collision that matters in practice is a plugin bundling a name this project
# ALREADY ships (`woocommerce`), and a synthetic directory cannot demonstrate
# that at all. This suite writes only to scratch directories, and the
# containment check below proves it.
#
# What this does NOT cover, deliberately: the certification promotion path with
# the bundling plugin active (that needs the signing apparatus, so it lives
# beside it in regress_site_adapter_certification.sh), the frozen-record tamper
# matrix (regress_adapter_sources.sh), and anything about a live target — a
# bundled adapter's providers, its plugin's version window, or a capability
# claim evaluated against one WordPress. Those have their own suites; a
# bundled adapter cannot be certified at all, which is asserted here as a
# property of the source rather than measured against a target.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + every file it exercises)"
php -l regress_plugin_adapter_source.php >/dev/null || fail "regress_plugin_adapter_source.php has a syntax error"
php -l ../../agent/src/AdapterSources.php >/dev/null || fail "agent/src/AdapterSources.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/Cli.php >/dev/null || fail "agent/src/Cli.php has a syntax error"
php -l ../../agent/src/RepositoryCompiler.php >/dev/null || fail "agent/src/RepositoryCompiler.php has a syntax error"
php -l ../../cli/src/AdapterCatalog.php >/dev/null || fail "cli/src/AdapterCatalog.php has a syntax error"
php -l ../../cli/src/PlanSummary.php >/dev/null || fail "cli/src/PlanSummary.php has a syntax error"
pass "no syntax errors"

say "the plugin source's two WordPress surfaces must be the only ones it reaches"
# AdapterSources is on the PURE loader path — Policy::load(), every offline
# entry point, and the host-side WordPress-free catalog all walk it. The plugin
# source is the first thing in that file to look at WordPress at all, so the
# guard is that it looks at exactly two things and looks at both through
# defined()/function_exists(). Anything else (a Deploy call, an admin include,
# a wp_* helper) would make the pure half order-sensitive and would break the
# host catalog, which has no WordPress to reach.
reach="$(awk '
  /^[ \t]*(\*|\/\/|\/\*)/ { next }
  { if ($0 ~ /(get_bloginfo|delete_transient|get_taxonomies|get_option|is_multisite|apply_filters|add_action|add_filter|wp_[a-z_]+|validate_plugin|get_plugins)[ \t]*\(|[$]wpdb|WP_PLUGIN_DIR|WP_CONTENT_DIR|ABSPATH/) print FNR": "$0 }
' ../../agent/src/AdapterSources.php)"
[ -n "$reach" ] || fail "expected AdapterSources.php to reach WP_PLUGIN_DIR/get_option — the plugin source is missing"
if printf '%s\n' "$reach" | grep -Ev "WP_PLUGIN_DIR|get_option" >/dev/null; then
  printf '%s\n' "$reach" | grep -Ev "WP_PLUGIN_DIR|get_option"
  fail "AdapterSources.php reaches a WordPress surface beyond WP_PLUGIN_DIR/get_option — the pure loader path must not acquire more"
fi
if ! printf '%s\n' "$reach" | grep -q "defined('WP_PLUGIN_DIR')"; then
  fail "AdapterSources.php reads WP_PLUGIN_DIR without a defined() guard — off-WordPress callers would fatal"
fi
if ! printf '%s\n' "$reach" | grep -q "function_exists('get_option')"; then
  fail "AdapterSources.php reads get_option() without a function_exists() guard — off-WordPress callers would fatal"
fi
if grep -nE '^[^*/]*Deploy::' ../../agent/src/AdapterSources.php; then
  fail "AdapterSources.php calls into Deploy — that pulls the lifecycle half into the pure loader graph (see plugin_source()'s own comment)"
fi
pass "the plugin source reaches WP_PLUGIN_DIR and get_option only, both guarded, and pulls in no Deploy"

say "the shipped manifest library must be untouched by this suite"
tree_hash() { (cd ../.. && find manifests -type f -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256); }
before="$(tree_hash)"

say "running the offline harness (precedence, per-adapter refusals, anchor, identity, frozen reconstruction)"
# `php ... || fail` would exit before the after-hash below, so the containment
# assertion would be skipped on exactly the run where a fixture escaped its
# scratch directory. Capture the status, hash unconditionally, report both.
harness_rc=0
php regress_plugin_adapter_source.php || harness_rc=$?
after="$(tree_hash)"

if [ "$before" = "$after" ]; then
  pass "shipped manifest library is byte-identical after the run"
else
  printf '\033[1;31mFAIL: the suite mutated the shipped manifest library — fixtures must stay in scratch directories\033[0m\n'
fi
[ "$harness_rc" -eq 0 ] || fail "regress_plugin_adapter_source.php reported failing checks (exit $harness_rc; see output above)"
[ "$before" = "$after" ] || fail "shipped manifest library containment check failed (see above)"

printf '\n\033[1;32m✔ REGRESS_PLUGIN_ADAPTER_SOURCE PASSED\033[0m\n'
