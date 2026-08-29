#!/usr/bin/env bash
# Regression — issue #3314: out-of-tree adapter sources. A site repository may
# carry `adapters/<name>.json`, which OVERLAYS the shipped manifest library:
# per-source identity through policy and compilation, loud refusal on
# shadowing/ambiguous identity before anything loads, no executable
# privileges for a data-only out-of-tree manifest, and uncertified-by-
# construction support that stays conspicuous in `wprism capabilities`, the plan
# adapter_dispositions rows, and `wprism status`.
#
# All pure PHP — AdapterSources::discover() is filesystem + JSON, and
# Policy::load()/RepositoryCompiler::resolved_adapters() were already offline
# by design (RepositoryCompiler's own docblock: the tree becomes a validated
# IR "before Tokens, Ledger, Capture, or a target query can be constructed").
# No docker, no sandbox pair, no WordPress bootstrap. Same idiom as
# sandbox/tests/offline/adapter/regress_adapter_contract.php (issue #3222/issue #3243).
#
# Note for future readers: unlike most manifest suites here, this one runs
# against the REAL shipped manifest bytes rather than a synthetic manifest
# directory — see the harness header for why (the claim under test is that
# real certified adapters stay certified, which a synthetic manifest directory
# cannot demonstrate). It serves those bytes from a hermetic scratch COPY,
# because several of its groups mutate a manifest library — deleting its
# dispositions, breaking its platform boundary — and the shipped one is not
# theirs to break. It writes only to scratch directories. The copy lives in
# sandbox/tests/offline/adapter/certification_fixture.php since issue #3421, shared with the live
# init suite, which mounts the identical library into its Docker pair.
#
# That fixture used to re-seal certification evidence against the working tree
# (issue #3379), because the checked-in attestation was expired on any branch that
# edited a bound input. There is no attestation now, so there is nothing to
# re-seal and no branch state the verdict can depend on.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + every engine file it exercises)"
php -l regress_adapter_sources.php >/dev/null || fail "regress_adapter_sources.php has a syntax error"
php -l certification_fixture.php >/dev/null || fail "certification_fixture.php has a syntax error"
php -l ../../../../agent/src/Adapter/AdapterSources.php >/dev/null || fail "agent/src/Adapter/AdapterSources.php has a syntax error"
php -l ../../../../agent/src/Policy/Policy.php >/dev/null || fail "agent/src/Policy/Policy.php has a syntax error"
php -l ../../../../agent/src/Adapter/AdapterRegistry.php >/dev/null || fail "agent/src/Adapter/AdapterRegistry.php has a syntax error"
php -l ../../../../agent/src/Policy/ManifestDispositions.php >/dev/null || fail "agent/src/Policy/ManifestDispositions.php has a syntax error"
php -l ../../../../agent/src/Policy/ArtifactPolicyIdentity.php >/dev/null || fail "agent/src/Policy/ArtifactPolicyIdentity.php has a syntax error"
php -l ../../../../agent/src/Repository/RepositoryCompiler.php >/dev/null || fail "agent/src/Repository/RepositoryCompiler.php has a syntax error"
php -l ../../../../agent/src/Command/Cli.php >/dev/null || fail "agent/src/Command/Cli.php has a syntax error"
php -l ../../../../cli/src/Plan/PlanSummary.php >/dev/null || fail "cli/src/Plan/PlanSummary.php has a syntax error"
pass "no syntax errors"

say "the shipped adapter package library must be untouched by this suite"
tree_hash() {
  (
    cd ../../../..
    {
      find adapter-packages -path '*/package/*' -type f -print0
      find platform/adapter-library -type f -print0
    } | sort -z | xargs -0 shasum -a 256 | shasum -a 256
  )
}
before="$(tree_hash)"

say "running the offline harness (overlay, identity refusals, privilege boundary, uncertified diagnostics, frozen provenance)"
# `php ... || fail` would exit before the after-hash below, so the containment
# assertion would be skipped on exactly the run where a fixture escaped its
# scratch directory. Capture the status, hash unconditionally, report both.
harness_rc=0
php regress_adapter_sources.php || harness_rc=$?
after="$(tree_hash)"

if [ "$before" = "$after" ]; then
  pass "shipped adapter package library is byte-identical after the run"
else
  printf '\033[1;31mFAIL: the suite mutated the shipped adapter package library — fixtures must stay in scratch directories\033[0m\n'
fi
[ "$harness_rc" -eq 0 ] || fail "regress_adapter_sources.php reported failing checks (exit $harness_rc; see output above)"
[ "$before" = "$after" ] || fail "shipped adapter package library containment check failed (see above)"

printf '\n\033[1;32m✔ REGRESS_ADAPTER_SOURCES PASSED\033[0m\n'
