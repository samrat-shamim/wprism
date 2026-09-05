#!/usr/bin/env bash
# Regression — issue #3327: `wprism manifest-validate`, the adapter author's offline
# grammar check, plus the machine-readable grammar document it emits.
#
# The whole point of the command is that a manifest is refusable with nothing
# installed, so this suite is the same shape: it runs the REAL host CLI as a
# subprocess against REAL manifest fixture files under a scratch directory, and
# reads the real exit codes and the real stdout/stderr. No docker, no sandbox
# pair, no WordPress bootstrap, no $wpdb stub, nothing mocked. Same idiom as
# sandbox/tests/offline/adapter/regress_adapter_contract.php (issue #3222/issue #3243) and
# sandbox/tests/offline/policy/regress_vocabulary_ownership.php (issue #3318), whose two-adapter
# fixtures this suite imports rather than copies
# (sandbox/tests/offline/policy/manifest_fixtures.php).
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
php -l ../../../../cli/wprism >/dev/null || fail "cli/wprism has a syntax error"
php -l ../../../../cli/src/Adapter/ManifestValidate.php >/dev/null || fail "cli/src/Adapter/ManifestValidate.php has a syntax error"
php -l ../../../../agent/src/Policy/Policy.php >/dev/null || fail "agent/src/Policy/Policy.php has a syntax error"
php -l ../../../../agent/src/Rebuild/NativeActions.php >/dev/null || fail "agent/src/Rebuild/NativeActions.php has a syntax error"
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
$repo = dirname(getcwd(), 4);
$src = (string) file_get_contents($repo . '/cli/src/Adapter/ManifestValidate.php');
$queue = [];
// Since the module move (ROUND 3 TRAIN 1) boot() resolves every name through
// agent/wprism-classmap.php. Both halves are still read out of the handler —
// the classmap lookup that opens the loop body proves the shape, the foreach
// list supplies the names. Anchoring on that lookup rather than on the first
// foreach in the file matters: ManifestValidate.php has earlier list loops
// ('options', 'post_meta', ...) that a looser pattern would match instead.
$classFiles = [];
foreach ((array) (require $repo . '/agent/wprism-classmap.php') as $mappedPath) {
    $classFiles[basename((string) $mappedPath, '.php')] = (string) $mappedPath;
}
if (preg_match('/foreach \(\[([^\]]*)\] as \$\w+\) \{\s*\$wprismAgentFile = \$wprismAgentFiles/', $src, $list) === 1) {
    foreach (explode(',', $list[1]) as $class) {
        $name = trim($class, " \t'\"");
        if (isset($classFiles[$name])) {
            $queue[] = $repo . '/agent/' . $classFiles[$name];
        }
    }
}
// A literal single-file require, should boot() ever grow one.
if (preg_match_all("/require(?:_once)? \\\$repo \\. '\\/agent\\/src\\/((?:\\w+\\/)?\\w+)\\.php'/", $src, $m2) > 0) {
    foreach ($m2[1] as $rel) {
        $queue[] = $repo . '/agent/src/' . $rel . '.php';
    }
}
$seen = [];
while ($queue !== []) {
    $file = array_shift($queue);
    if (isset($seen[$file]) || !is_file($file)) {
        continue;
    }
    $seen[$file] = true;
    // A cross-module require is `__DIR__ . '/../<Module>/X.php'` since the
    // move; resolving it against the requiring file's own directory keeps this
    // closure module-agnostic.
    if (preg_match_all("/require_once __DIR__ \\. '\\/((?:\\.\\.\\/\\w+\\/)?\\w+)\\.php'/", (string) file_get_contents($file), $r) > 0) {
        foreach ($r[1] as $rel) {
            $resolved = realpath(dirname($file) . '/' . $rel . '.php');
            $queue[] = is_string($resolved) ? $resolved : dirname($file) . '/' . $rel . '.php';
        }
    }
}
$files = array_keys($seen);
sort($files);
echo implode("\n", $files), "\n";
PHP
)
[ -n "$engine_files" ] || fail "could not enumerate boot()'s require list from cli/src/Adapter/ManifestValidate.php"
for required in Policy.php NativeActions.php AdapterRegistry.php Canon.php; do
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
# Signed site-adapter validation makes AdapterCertification part of the static
# load closure. Its capability/disposition validators in turn load Deploy and
# Providers, but manifest-validate never calls their target-facing methods:
# those are exactly the Deploy::code_mismatch()/code_drift() and
# Providers::negotiate() surfaces named in the deferred document below. Keep
# the private helpers explicit here so a new WordPress reach cannot hide behind
# the wider dependency graph.
#
# issue #3350 slice 6 moved lifecycle/code observation into
# LifecyclePlanner.php. The later exact-baseline transaction moved live option
# reads again, into CodeLifecycleObservation/CodeBaselineTransaction, while
# baseline_bytes() remains the planner's WordPress JSON boundary. Exact stale
# entries are refused below, so this registry follows those owners rather than
# retaining the planner methods that no longer reach WordPress.
# current_active_plugins/plugin_runtime_state stay on Deploy.php. The
# checkpoint-authenticated provider wrapper now owns public run(), while the
# prior WordPress-reading body is run_authorized(), so this exact-name
# allowlist follows the body rather than excusing the new wrapper by accident.
#
# issue #3350 slice 8 moved the WP-mutation body (activate/
# deactivate/order-correct/switch_theme) into LifecycleExecutor::execute();
# its is_wp_error()/get_option() calls move with it. Deploy.php:run_authorized keeps its
# own entry unchanged -- it still calls get_option('stylesheet'/'template')
# directly, earlier in the method, to compute $stylesheetMismatch/
# $templateMismatch before the moved call.
#
# issue #3507's capture observer and the host baseline status both consume the
# same code_version_observation(); neither duplicates live option/plugin/theme
# reads at its call site. baseline_bytes() is likewise the one WordPress JSON
# encoder used by capture, lifecycle publication, and isolated acceptance.
# PlatformCompatibility::current_facts() is similarly inert during this
# command: Policy.php loads its class, but only live Policy::load() paths call
# the method. The command's deferred document names that exact target boundary.
# NativeActions::flush_rewrite_action() reads the option-filtered runtime only
# after execute('rewrite.flush') has launched its live WordPress path; keeping
# it under the same deferred NativeActions::execute() boundary prevents the
# offline manifest validator from pretending get_option() exists.
# WP-3.3 added ProviderSurfaces.php — the engine's own reading of the surfaces
# a capability declared, which Providers::invoke() compares either side of the
# call so `verified: true` is a check rather than a self-attestation. It reaches
# $wpdb in exactly two places (observe()'s handle test, option_witness()'s
# bounded SHA2 read), and it pulls ProviderSdk.php into this closure for the
# first time because it performs those reads through the sanctioned checked-read
# path instead of restating the twin predicate. Both files sit under the SAME
# deferred boundary the allowlist already excuses Providers.php:
# plugin_supplied_providers under — `providers / actions[].kind=provider`,
# checked by Providers::negotiate() — because manifest-validate negotiates no
# provider and invokes none, so neither reach is on any path this command runs.
# #561 added NativeActions::rewrite_evidence() -- 'rewrite.flush''s read-only
# postcondition read, which calls get_option('rewrite_rules') at :200 behind an
# early function_exists()/is_object($wp_rewrite) throw rather than a same-line
# guard, so the scanner sees it unguarded. It sits under the SAME deferred
# NativeActions::execute() boundary its flush_rewrite_action/rewrite_state
# neighbours already carry: manifest-validate executes no native action, so the
# reach is off every path this command runs.
# The generic exact-table boundary moved the physical-table probe out of
# DatabaseLockBoundary::assert_plain_physical_table() and made presence plus its
# sole-server-error diagnostic reusable by provider schema settlement and the
# ledger's virgin-install observation. Manifest validation loads those classes
# through the engine closure but invokes none of those target-database paths.
# ProcessFence's checked scalar transport is now a private collaborator of
# its existing acquire/continuity methods. It remains behind the same deferred
# target-fence entry points; manifest validation acquires no database fence.
#
# ONE assignment, deliberately: the #561 merge left two consecutive `wp_allow=`
# lines and the second silently won, dropping WP-3.3's four ProviderSdk and two
# ProviderSurfaces entries and re-failing the scan. This is their union. The
# concatenated quoted segments remain one shell assignment while keeping the
# exact function registry reviewable by its deferred owner: platform/native,
# deploy/baseline, provider runtime, database transaction, and ledger.
wp_allow='TargetProbe.php:probe_target,PlatformCompatibility.php:current_facts,PlatformCompatibility.php:wp_cli_opcache_enabled,Policy.php:taxonomies,'\
'NativeActions.php:delete_transient_action,NativeActions.php:transient_state,NativeActions.php:option_row_present,NativeActions.php:flush_rewrite_action,NativeActions.php:rewrite_evidence,NativeActions.php:rewrite_state,NativeActions.php:raw_option_state,'\
'LifecyclePlanner.php:baseline_bytes,LifecycleExecutor.php:execute,Deploy.php:run_authorized,Deploy.php:current_active_plugins,Deploy.php:plugin_runtime_state,CodeLifecycleObservation.php:read_unlocked_options,CodeBaselinePublication.php:publish_terminal,CodeBaselineTransaction.php:lock_current,'\
'PromotionLease.php:abort,PromotionLease.php:acquire_internal,PromotionLease.php:assert_transactional_promotion_storage,PromotionLease.php:begin_recovery_with_external_fence,PromotionLease.php:begin_with_external_fence,PromotionLease.php:complete_scoped,PromotionLease.php:heartbeat,PromotionLease.php:recover_session,PromotionLease.php:release,PromotionLease.php:release_after_failure,'\
'Providers.php:plugin_supplied_providers,ProviderSurfaces.php:observe,ProviderSurfaces.php:option_witness,ProviderSdk.php:checked_read_transport,ProviderSdk.php:checked_get_var,ProviderSdk.php:checked_get_col,ProviderSdk.php:checked_get_row,ProviderSdk.php:checked_get_results,ProviderSdk.php:checked_durable_option,ProviderSdk.php:database_table_presence,ProviderSdk.php:physical_tables_for_surfaces,ExactOptionWriter.php:persist,ExactOptionWriter.php:invalidate_cache_attempt,LockedOptionRows.php:read_one,'\
'DatabaseLockBoundary.php:acquire_table_metadata_lock,DatabaseLockBoundary.php:assert_closed_foreign_key_destinations,DatabaseLockBoundary.php:assert_foreign_key_metadata_authority,DatabaseLockBoundary.php:assert_innodb_tables,DatabaseLockBoundary.php:assert_no_triggers,DatabaseLockBoundary.php:assert_trigger_metadata_visibility,DatabaseLockBoundary.php:foreign_key_internal_schema_name,DatabaseLockBoundary.php:foreign_key_metadata_source,DatabaseLockBoundary.php:locking_index,DatabaseServerDiagnostics.php:sole_error_code,DatabaseTablePresence.php:exact_session_presence,DatabaseTablePresence.php:assert_plain_base_table,'\
'DatabaseQueryIsolation.php:add_filter,DatabaseQueryIsolation.php:apply_filters,DatabaseQueryIsolation.php:begin,DatabaseTransportBoundary.php:begin,ProcessFence.php:acquire,ProcessFence.php:databaseScalar,ProcessFence.php:isContinuous,ProcessFence.php:name,ProcessFence.php:release,'\
'Db.php:assemble_mutation,Db.php:bind_session_authority,Db.php:checked,Db.php:classified_savepoint_control,Db.php:delete,Db.php:driver_errno,Db.php:ensure_varchar_column_width,Db.php:finish_transaction,Db.php:idle_schema_statement,Db.php:idle_schema_transport,Db.php:insert,Db.php:insert_id,Db.php:native_table_identifier,Db.php:permitted_control_query,Db.php:read_session_identity,Db.php:require_savepoint_control,Db.php:set_next_transaction_repeatable_read,Db.php:start_control,Db.php:table_label,Db.php:transactional_mutation,Db.php:update,Db.php:where_fields,Db.php:write_fields,'\
'Ledger.php:all_map,Ledger.php:all_state,Ledger.php:assert_read_only_schema,Ledger.php:checked_get_results,Ledger.php:checked_get_row,Ledger.php:checked_get_var,Ledger.php:ensure,Ledger.php:forget,Ledger.php:id_for,Ledger.php:kv_delete,Ledger.php:kv_delete_transactional,Ledger.php:kv_get,Ledger.php:kv_get_for_update,Ledger.php:kv_prefix,Ledger.php:kv_set,Ledger.php:kv_set_transactional,Ledger.php:kv_table_installed,Ledger.php:migrate_widen_entity_type,Ledger.php:migrate_widen_id_kind,Ledger.php:prune_dead_composite_table_map,Ledger.php:prune_dead_map,Ledger.php:prune_dead_table_map,Ledger.php:prune_state,Ledger.php:require_read_only_mapping,Ledger.php:set,Ledger.php:set_state_hash,Ledger.php:state_hash,Ledger.php:uuid_for'
wp_allow_via='AdapterRegistry::report() PlatformCompatibility::current_facts() Policy::taxonomies() NativeActions::execute() Deploy::code_mismatch() Deploy::code_drift() Providers::negotiate()'

scan_wp() {
  # $1 = allowlist (may be empty), remaining args = files
  local allow="$1"; shift
  awk -v allow="$allow" '
    BEGIN {
      if (allow != "") { n = split(allow, tmp, ","); for (i = 1; i <= n; i++) allowed[tmp[i]] = 0 }
    }
    FNR == 1 { base = FILENAME; sub(/.*\//, "", base); fn = "(top level)"; prev = "" }
    # Full-line comments are stripped: prose naming a function is not a call to
    # it (the same false-positive shape ../guards/regress_bundle_coverage.sh documents).
    /^[ \t]*(\*|\/\/|\/\*)/ { next }
    {
      if (match($0, /function[ \t]+[A-Za-z_][A-Za-z0-9_]*[ \t]*\(/)) {
        frag = substr($0, RSTART, RLENGTH)
        sub(/^function[ \t]+/, "", frag)
        sub(/[ \t]*\($/, "", frag)
        fn = frag
      }
      # A parser comparing the literal "ABSPATH" does not read the WordPress
      # constant. Preserve constant("ABSPATH"), which really does read it.
      reach = $0
      if (reach !~ /constant[ \t]*\(/) {
        quote = sprintf("%c", 39)
        gsub(quote "ABSPATH" quote, "", reach)
        gsub(/"ABSPATH"/, "", reach)
      }
      if (reach ~ /(get_bloginfo|delete_transient|get_taxonomies|get_option|is_multisite|apply_filters|add_action|add_filter|wp_[a-z_]+)[ \t]*\(|[$]wpdb|ABSPATH/) {
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
scan_wp "" ../../../../cli/src/Adapter/ManifestValidate.php \
  || fail "cli/src/Adapter/ManifestValidate.php reaches WordPress — the handler itself must be free of it"
scan_wp "$wp_allow" $engine_files \
  || fail "an unguarded WordPress reach in boot()'s load set is neither guarded nor allowlisted (see above)"
pass "every WordPress reach in the handler and its whole load set is guarded or explicitly allowlisted"

schema_doc=$(php ../../../../cli/wprism manifest-validate --emit-schema) \
  || fail "could not emit the grammar document to cross-check the allowlist"
for via in $wp_allow_via; do
  grep -qF "$via" <<<"$schema_doc" \
    || fail "the allowlist excuses an unguarded WordPress reach under $via, but the command's deferred list never names it"
done
pass "every allowlisted reach belongs to a surface the command reports as deferred"

say "running the offline harness (precise paths, deferred list, schema derivation, exit codes, shipped manifests)"
php regress_manifest_validate.php || fail "regress_manifest_validate.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_MANIFEST_VALIDATE PASSED\033[0m\n'
