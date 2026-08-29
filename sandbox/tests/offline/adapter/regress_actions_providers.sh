#!/usr/bin/env bash
# Regression — issue #3338: structured native actions and the plugin-owned
# provider contract that replaced the free-form `rebuilders` command channel.
#
# Two harnesses run under this one target because the contract has two halves
# that need different fixtures, and splitting them keeps each honest:
#
#   regress_actions_providers.php  LOAD TIME. Never stubs a WordPress function.
#     The `rebuilders` refusal (the regression: that exact manifest shape
#     loaded and was executed verbatim before this change), the closed
#     actions/providers grammar, effects_inventory()'s rebuild sources, the
#     REAL shipped manifests and the manifest-owned provider files they carry, and
#     the digest that binds manifest-shipped provider bytes to their adapter.
#
#   regress_provider_contract.php  RUNTIME. Stubs the four WordPress lifecycle
#     primitives Deploy::plugin_runtime_state() reads plus apply_filters(),
#     and the exact transient/cache + checked option-read primitives
#     NativeActions::execute() reads. It exercises negotiation refusals,
#     plugin-sourced `wprism_providers` discovery, invocation receipts,
#     value-level verification, persistent-cache false-value handling, and
#     the post-hoc timeout budget.
#
# Both are pure PHP against real engine files under an explicit scratch
# AdapterLibrary — no docker, no sandbox pair, no WordPress bootstrap. Same
# idiom as sandbox/tests/offline/adapter/regress_adapter_contract.php (issue #3222/issue #3243).
#
# What this does NOT cover, because it genuinely needs a live target: Apply's
# placement of the negotiation gate ahead of the first mutation and the
# post-commit fatality of a failing action (sandbox/tests/live/regress_fatal_mutations_live.sh),
# the per-declaration confirmation lines (../../live/regress_option_subkeys.sh for a
# provider capability, ../../live/regress_woo_attribute_deletion.sh for a native action),
# and the shipped providers' own invoke() bodies against real plugins (the
# conformance sweeps; the WooCommerce one is additionally exercised against a
# fake public API by ../ecommerce/regress_woocommerce_deletion_authority.php).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "live failure fixture selects executable adapter code without a process-global library"
LIVE=../../live/regress_fatal_mutations_live.sh
! grep -Fq 'WPRISM_''MANIFESTS_DIR' "$LIVE" \
  || fail "live provider failure fixture reintroduced process-global manifest selection"
grep -Fq 'AdapterLibrary::fromSourcePackage' "$LIVE" \
  || fail "live provider failure fixture no longer selects a closed source package explicitly"
grep -Fq '$SITEREPO/adapters/provider-probe-missing-provider.json' "$LIVE" \
  || fail "missing plugin-provider declaration no longer travels as a repository-owned site adapter"
pass "live provider failure fixture has explicit source selection"

say "php -l syntax check (both harnesses, every engine file they exercise, and every shipped provider)"
php -l regress_actions_providers.php >/dev/null || fail "regress_actions_providers.php has a syntax error"
php -l regress_provider_contract.php >/dev/null || fail "regress_provider_contract.php has a syntax error"
php -l ../../../../agent/src/Policy/Policy.php >/dev/null || fail "agent/src/Policy/Policy.php has a syntax error"
php -l ../../../../agent/src/Rebuild/NativeActions.php >/dev/null || fail "agent/src/Rebuild/NativeActions.php has a syntax error"
php -l ../../../../agent/src/Adapter/ProviderSdk.php >/dev/null || fail "agent/src/Adapter/ProviderSdk.php has a syntax error"
php -l ../../../../agent/src/Adapter/Providers.php >/dev/null || fail "agent/src/Adapter/Providers.php has a syntax error"
php -l ../../../../agent/src/Apply/Apply.php >/dev/null || fail "agent/src/Apply/Apply.php has a syntax error"
php -l ../../../../agent/src/Repository/RepositoryCompiler.php >/dev/null || fail "agent/src/Repository/RepositoryCompiler.php has a syntax error"
php -l ../../../../agent/src/Adapter/AdapterRegistry.php >/dev/null || fail "agent/src/Adapter/AdapterRegistry.php has a syntax error"
php -l ../../../../agent/src/Adapter/TargetProbe.php >/dev/null || fail "agent/src/Adapter/TargetProbe.php has a syntax error"
php -l ../../../../agent/src/Promotion/Deploy.php >/dev/null || fail "agent/src/Promotion/Deploy.php has a syntax error"
for provider in ../../../../adapter-packages/*/package/runtime/providers/*.php; do
  php -l "$provider" >/dev/null || fail "$provider has a syntax error"
done
pass "no syntax errors"

say "load-time contract: rebuilders refusal, actions/providers grammar, effect inventory, shipped adapters, digest binding"
php regress_actions_providers.php || fail "regress_actions_providers.php reported failing checks (see output above)"

say "runtime contract: negotiation, plugin-sourced discovery, receipts, verification, timeout budget"
php regress_provider_contract.php || fail "regress_provider_contract.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_ACTIONS_PROVIDERS PASSED\033[0m\n'
