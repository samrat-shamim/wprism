#!/usr/bin/env bash
# Regression — DUO-3327: `duo manifest-validate`, the adapter author's offline
# grammar check, plus the machine-readable grammar document it emits.
#
# The whole point of the command is that a manifest is refusable with nothing
# installed, so this suite is the same shape: it runs the REAL host CLI as a
# subprocess against REAL manifest fixture files under a scratch directory, and
# reads the real exit codes and the real stdout/stderr. No docker, no sandbox
# pair, no WordPress bootstrap, no $wpdb stub, nothing mocked. Same idiom as
# sandbox/tests/regress_adapter_contract.sh (DUO-3222/DUO-3243) and
# sandbox/tests/regress_vocabulary_ownership.sh (DUO-3318), whose two-adapter
# fixtures this suite imports rather than copies
# (sandbox/tests/manifest_fixtures.php).
#
# Covered: every acceptance-1 category (invalid keys, shapes, ranges,
# exclusivity rules, action names, provider declarations) producing its precise
# path; the deferred/limitations list appearing on passing runs, failing runs,
# and in the schema document; the emitted grammar matching the engine's own
# accessors field for field, plus the `coverage` note stating what it does NOT
# publish; EVERY published vocabulary checked in BOTH directions against the
# runtime validator, with a ratchet asserting the covered set is exactly the
# published set; `--site` changing the verdict on both site-sensitive guards
# (a site-declared id_kind ref, and a site-resolved option conflict) plus the
# annotation a no-site run attaches instead; the interpreter/regenerator files a
# manifest NAMES being resolved rather than left lazy; exit codes; the command's
# own fail-closed IO paths; and every SHIPPED manifest validating through the
# command as the real-world smoke.
#
# What this does NOT cover, deliberately: everything the command itself reports
# as deferred — live table schema, taxonomy_patterns expansion, installed
# plugin/theme versions, provider negotiation, native-action execution,
# capability evaluation against a target, and lint's live id cross-reference.
# Those have their own live suites; this one proves the command SAYS it did not
# do them.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness, fixtures, and every file it exercises)"
php -l regress_manifest_validate.php >/dev/null || fail "regress_manifest_validate.php has a syntax error"
php -l manifest_fixtures.php >/dev/null || fail "manifest_fixtures.php has a syntax error"
php -l ../../cli/duo >/dev/null || fail "cli/duo has a syntax error"
php -l ../../cli/src/ManifestValidate.php >/dev/null || fail "cli/src/ManifestValidate.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/NativeActions.php >/dev/null || fail "agent/src/NativeActions.php has a syntax error"
pass "no syntax errors"

say "the command is WordPress-free by construction — assert it over everything boot() loads"
# The handler having no WordPress call in it proves almost nothing: the handler
# is thirty lines of argument parsing around five ENGINE files it requires, and
# those are where WordPress would be reached from. So the scan below runs over
# boot()'s real load set, enumerated from the handler's own require statements
# and then closed over those files' own `require_once __DIR__` lines (Policy.php
# pulls NativeActions.php and AdapterSources.php) — a new require cannot slip
# past this check by being added in one place only.
engine_files=$(php <<'PHP'
<?php
// cwd is sandbox/tests; the suite cd'd there.
$repo = dirname(getcwd(), 2);
$src = (string) file_get_contents($repo . '/cli/src/ManifestValidate.php');
$queue = [];
// boot() requires "<repo>/agent/src/$class.php" for each name in the list its
// own foreach walks. Both halves are read out of the handler, never restated.
if (preg_match_all('/require_once \$repo \. "\/agent\/src\/\$(\w+)\.php"/', $src, $m) > 0) {
    foreach ($m[1] as $var) {
        if (preg_match('/foreach \(\[([^\]]*)\] as \$' . preg_quote($var, '/') . '\)/', $src, $list) === 1) {
            foreach (explode(',', $list[1]) as $class) {
                $queue[] = $repo . '/agent/src/' . trim($class, " \t'\"") . '.php';
            }
        }
    }
}
// A literal single-file require, should boot() ever grow one.
if (preg_match_all("/require(?:_once)? \\\$repo \\. '\\/agent\\/src\\/(\\w+)\\.php'/", $src, $m2) > 0) {
    foreach ($m2[1] as $class) {
        $queue[] = $repo . '/agent/src/' . $class . '.php';
    }
}
$seen = [];
while ($queue !== []) {
    $file = array_shift($queue);
    if (isset($seen[$file]) || !is_file($file)) {
        continue;
    }
    $seen[$file] = true;
    if (preg_match_all("/require_once __DIR__ \\. '\\/(\\w+)\\.php'/", (string) file_get_contents($file), $r) > 0) {
        foreach ($r[1] as $class) {
            $queue[] = $repo . '/agent/src/' . $class . '.php';
        }
    }
}
$files = array_keys($seen);
sort($files);
echo implode("\n", $files), "\n";
PHP
)
[ -n "$engine_files" ] || fail "could not enumerate boot()'s require list from cli/src/ManifestValidate.php"
for required in Policy.php NativeActions.php CapabilityRegistry.php Canon.php; do
  grep -q "/$required\$" <<<"$engine_files" \
    || fail "boot() enumeration missed $required — the scan below would be checking the wrong files"
done
printf 'boot() loads: %s\n' "$(tr '\n' ' ' <<<"$engine_files" | sed 's#[^ ]*/##g')"

# Every reach at any of those files must be accounted for. Two ways to account
# for one:
#
#   1. a function_exists()/defined()/is_object($wpdb) guard on the same or the
#      immediately preceding line, so the call degrades instead of fataling;
#   2. membership in the explicit allowlist below — a named FUNCTION that this
#      command never calls. That is not taken on trust: the command runs in a
#      process where those WordPress functions genuinely do not exist and $wpdb
#      is null, so an unguarded reach on an executed path is a fatal error, and
#      the harness asserts clean exit-0 runs (including "a clean run writes
#      nothing to stderr"). Each entry additionally has to be covered by the
#      command's own deferred list — you may only allowlist a WordPress reach
#      the tool already TELLS the author it is not performing.
#
# Both directions are ratcheted: an unlisted unguarded reach fails, and so does
# a stale allowlist entry that no longer matches anything.
wp_allow='CapabilityRegistry.php:probe_target,Policy.php:taxonomies,NativeActions.php:delete_transient_action,NativeActions.php:transient_state,NativeActions.php:option_row_present'
wp_allow_via='CapabilityRegistry::report() Policy::taxonomies() NativeActions::execute()'

scan_wp() {
  # $1 = allowlist (may be empty), remaining args = files
  local allow="$1"; shift
  awk -v allow="$allow" '
    BEGIN {
      if (allow != "") { n = split(allow, tmp, ","); for (i = 1; i <= n; i++) allowed[tmp[i]] = 0 }
    }
    FNR == 1 { base = FILENAME; sub(/.*\//, "", base); fn = "(top level)"; prev = "" }
    # Full-line comments are stripped: prose naming a function is not a call to
    # it (the same false-positive shape regress_bundle_coverage.sh documents).
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
          key = base ":" fn
          if (key in allowed) { allowed[key]++ }
          else { printf "  UNGUARDED %s:%s line %d: %s\n", base, fn, FNR, $0; bad++ }
        }
      }
      prev = $0
    }
    END {
      for (k in allowed) if (allowed[k] == 0) { printf "  STALE allowlist entry (nothing matches it any more): %s\n", k; bad++ }
      exit (bad > 0 ? 1 : 0)
    }
  ' "$@"
}

# shellcheck disable=SC2086
scan_wp "" ../../cli/src/ManifestValidate.php \
  || fail "cli/src/ManifestValidate.php reaches WordPress — the handler itself must be free of it"
scan_wp "$wp_allow" $engine_files \
  || fail "an unguarded WordPress reach in boot()'s load set is neither guarded nor allowlisted (see above)"
pass "every WordPress reach in the handler and its whole load set is guarded or explicitly allowlisted"

schema_doc=$(php ../../cli/duo manifest-validate --emit-schema) \
  || fail "could not emit the grammar document to cross-check the allowlist"
for via in $wp_allow_via; do
  grep -qF "$via" <<<"$schema_doc" \
    || fail "the allowlist excuses an unguarded WordPress reach under $via, but the command's deferred list never names it"
done
pass "every allowlisted reach belongs to a surface the command reports as deferred"

say "running the offline harness (precise paths, deferred list, schema derivation, exit codes, shipped manifests)"
php regress_manifest_validate.php || fail "regress_manifest_validate.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_MANIFEST_VALIDATE PASSED\033[0m\n'
