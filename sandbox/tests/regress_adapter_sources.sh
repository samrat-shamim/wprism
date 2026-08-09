#!/usr/bin/env bash
# Regression — DUO-3314: out-of-tree adapter sources. A site repository may
# carry `adapters/<name>.json`, which OVERLAYS the shipped manifest library:
# per-source identity through policy and compilation, loud refusal on
# shadowing/ambiguous identity before anything loads, no executable
# privileges for a data-only out-of-tree manifest, and uncertified-by-
# construction support that stays conspicuous in `duo capabilities`, the plan
# adapter_dispositions rows, and `duo status`.
#
# All pure PHP — AdapterSources::discover() is filesystem + JSON, and
# Policy::load()/RepositoryCompiler::resolved_adapters() were already offline
# by design (RepositoryCompiler's own docblock: the tree becomes a validated
# IR "before Tokens, Ledger, Capture, or a target query can be constructed").
# No docker, no sandbox pair, no WordPress bootstrap. Same idiom as
# sandbox/tests/regress_adapter_contract.sh (DUO-3222/DUO-3243).
#
# Note for future readers: unlike most manifest suites here, this one runs
# against the REAL shipped manifest bytes rather than a synthetic manifest
# directory — see the harness header for why (the claim under test is that
# real certified adapters stay certified, which a synthetic manifest directory
# cannot demonstrate). It serves those bytes from a scratch copy whose
# certification evidence is re-sealed against the working tree (DUO-3379), so
# the suite's verdict does not depend on where the branch sits in the
# certification cycle. It writes only to scratch directories.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + every engine file it exercises)"
php -l regress_adapter_sources.php >/dev/null || fail "regress_adapter_sources.php has a syntax error"
php -l ../../agent/src/AdapterSources.php >/dev/null || fail "agent/src/AdapterSources.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/CapabilityRegistry.php >/dev/null || fail "agent/src/CapabilityRegistry.php has a syntax error"
php -l ../../agent/src/ManifestDispositions.php >/dev/null || fail "agent/src/ManifestDispositions.php has a syntax error"
php -l ../../agent/src/RepositoryCompiler.php >/dev/null || fail "agent/src/RepositoryCompiler.php has a syntax error"
php -l ../../agent/src/Cli.php >/dev/null || fail "agent/src/Cli.php has a syntax error"
php -l ../../cli/src/PlanSummary.php >/dev/null || fail "cli/src/PlanSummary.php has a syntax error"
pass "no syntax errors"

say "the shipped manifest library must be untouched by this suite"
tree_hash() { (cd ../.. && find manifests -type f -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256); }
before="$(tree_hash)"

say "running the offline harness (overlay, identity refusals, privilege boundary, uncertified diagnostics, frozen provenance)"
# `php ... || fail` would exit before the after-hash below, so the containment
# assertion would be skipped on exactly the run where a fixture escaped its
# scratch directory. Capture the status, hash unconditionally, report both.
harness_rc=0
php regress_adapter_sources.php || harness_rc=$?
after="$(tree_hash)"

if [ "$before" = "$after" ]; then
  pass "shipped manifest library is byte-identical after the run"
else
  printf '\033[1;31mFAIL: the suite mutated the shipped manifest library — fixtures must stay in scratch directories\033[0m\n'
fi
[ "$harness_rc" -eq 0 ] || fail "regress_adapter_sources.php reported failing checks (exit $harness_rc; see output above)"
[ "$before" = "$after" ] || fail "shipped manifest library containment check failed (see above)"

printf '\n\033[1;32m✔ REGRESS_ADAPTER_SOURCES PASSED\033[0m\n'
