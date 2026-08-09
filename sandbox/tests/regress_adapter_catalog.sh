#!/usr/bin/env bash
# Regression — DUO-3339: the installed-adapter catalog. `duo adapter
# list|inspect|doctor` reports what is installed across the two adapter sources
# the engine has, where each piece came from, the executable authority its own
# declarations reach (with the declaration that produced it), its reviewed
# certification state, and — crucially — the conditions
# `AdapterSources::discover()` REFUSES, as rows rather than as an exception
# that takes the command down.
#
# All pure PHP and file I/O. The command is WordPress-free by construction (it
# is dependency-free host PHP over the pure half of Policy::load(), the same
# half `duo manifest-validate` drives), so the harness runs the REAL command as
# a subprocess against the REAL shipped library and REAL scratch site
# repositories. No docker, no sandbox pair, no WordPress bootstrap, no $wpdb
# stub, nothing mocked. Same idiom as sandbox/tests/regress_manifest_validate.sh
# (DUO-3327) and sandbox/tests/regress_adapter_sources.sh (DUO-3314).
#
# Like regress_adapter_sources.sh, this one runs against the REAL shipped
# manifest bytes rather than a synthetic library: the claim under test is that
# the catalog reports the adapters this project actually ships, with the tiers
# their own declarations actually reach, which a synthetic directory cannot
# demonstrate. It writes only to scratch directories, and the containment check
# below proves it.
#
# What this does NOT cover, deliberately: everything the command itself reports
# as deferred — live plugin/theme state, provider negotiation against installed
# code, certification evaluated against one target's WordPress/PHP/database,
# and the class contract of the manifest-shipped PHP the catalog names but
# never loads. Those have their own suites; this one proves the command SAYS
# it did not do them, on every run.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + every file it exercises)"
php -l regress_adapter_catalog.php >/dev/null || fail "regress_adapter_catalog.php has a syntax error"
php -l ../../cli/duo >/dev/null || fail "cli/duo has a syntax error"
php -l ../../cli/src/AdapterCatalog.php >/dev/null || fail "cli/src/AdapterCatalog.php has a syntax error"
php -l ../../cli/src/PlanSummary.php >/dev/null || fail "cli/src/PlanSummary.php has a syntax error"
php -l ../../agent/src/AdapterSources.php >/dev/null || fail "agent/src/AdapterSources.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/Providers.php >/dev/null || fail "agent/src/Providers.php has a syntax error"
php -l ../../agent/src/CapabilityRegistry.php >/dev/null || fail "agent/src/CapabilityRegistry.php has a syntax error"
php -l ../../agent/src/ManifestDispositions.php >/dev/null || fail "agent/src/ManifestDispositions.php has a syntax error"
pass "no syntax errors"

say "the handler itself must be WordPress-free, and its load set must be one already scanned"
# Two halves, because scanning the handler alone proves almost nothing: the
# handler is argument parsing around five ENGINE files it requires, and those
# are where WordPress would be reached from.
#
#   1. the handler: no unguarded WordPress reach at all, no allowlist.
#   2. the engine half: rather than repeat regress_manifest_validate.sh's
#      enumerate-and-scan (which closes over the engine files' own
#      `require_once __DIR__` lines, so it already covers AdapterSources.php
#      and CapabilityRegistry.php), assert that this command's boot() loads
#      EXACTLY the same set that suite scans. Then its result covers this
#      command too, provably, instead of by assumption — and a require added
#      here and not there fails this check rather than escaping both.
scan_wp() {
  awk '
    FNR == 1 { base = FILENAME; sub(/.*\//, "", base); fn = "(top level)"; prev = "" }
    # Full-line comments are stripped: prose naming a function is not a call to
    # it (the false-positive shape regress_bundle_coverage.sh documents).
    /^[ \t]*(\*|\/\/|\/\*)/ { next }
    {
      if (match($0, /function[ \t]+[A-Za-z_][A-Za-z0-9_]*[ \t]*\(/)) {
        frag = substr($0, RSTART, RLENGTH)
        sub(/^function[ \t]+/, "", frag)
        sub(/[ \t]*\($/, "", frag)
        fn = frag
      }
      if ($0 ~ /(get_bloginfo|delete_transient|get_taxonomies|get_option|is_multisite|apply_filters|add_action|add_filter|wp_[a-z_]+)[ \t]*\(|[$]wpdb|ABSPATH/) {
        if (((prev $0) ~ /function_exists\(|defined\(|is_object\([$]wpdb\)/) == 0) {
          printf "  UNGUARDED %s:%s line %d: %s\n", base, fn, FNR, $0; bad++
        }
      }
      prev = $0
    }
    END { exit (bad > 0 ? 1 : 0) }
  ' "$@"
}
scan_wp ../../cli/src/AdapterCatalog.php \
  || fail "cli/src/AdapterCatalog.php reaches WordPress — the handler itself must be free of it"

boot_set() {
  # The class list boot()'s own foreach walks, read out of the file rather than
  # restated here.
  php -r '
    $src = (string) file_get_contents($argv[1]);
    if (preg_match("/foreach \(\[([^\]]*)\] as \\\$class\)/", $src, $m) !== 1) {
        fwrite(STDERR, "could not read boot()'"'"'s require list from {$argv[1]}\n");
        exit(1);
    }
    $names = array_map(fn($c) => trim($c, " \t\n\r\"'"'"'"), explode(",", $m[1]));
    sort($names);
    echo implode(",", $names), "\n";
  ' "$1"
}
catalog_set="$(boot_set ../../cli/src/AdapterCatalog.php)" || fail "could not enumerate AdapterCatalog::boot()"
validate_set="$(boot_set ../../cli/src/ManifestValidate.php)" || fail "could not enumerate ManifestValidate::boot()"
[ -n "$catalog_set" ] || fail "AdapterCatalog::boot() enumerated an empty require list"
if [ "$catalog_set" != "$validate_set" ]; then
  fail "AdapterCatalog::boot() loads [$catalog_set] but ManifestValidate::boot() loads [$validate_set] — regress_manifest_validate.sh's engine-side WordPress scan no longer covers this command's load set, so either align them or scan this one independently"
fi
pass "handler is WordPress-free, and its engine load set [$catalog_set] is the one regress_manifest_validate.sh scans"

say "the shipped manifest library must be untouched by this suite"
tree_hash() { (cd ../.. && find manifests -type f -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256); }
before="$(tree_hash)"

say "running the offline harness (catalog rows, tier basis, merges, refusal rows, doctor, exit codes)"
# `php ... || fail` would exit before the after-hash below, so the containment
# assertion would be skipped on exactly the run where a fixture escaped its
# scratch directory. Capture the status, hash unconditionally, report both.
harness_rc=0
php regress_adapter_catalog.php || harness_rc=$?
after="$(tree_hash)"

if [ "$before" = "$after" ]; then
  pass "shipped manifest library is byte-identical after the run"
else
  printf '\033[1;31mFAIL: the suite mutated the shipped manifest library — fixtures must stay in scratch directories\033[0m\n'
fi
[ "$harness_rc" -eq 0 ] || fail "regress_adapter_catalog.php reported failing checks (exit $harness_rc; see output above)"
[ "$before" = "$after" ] || fail "shipped manifest library containment check failed (see above)"

printf '\n\033[1;32m✔ REGRESS_ADAPTER_CATALOG PASSED\033[0m\n'
