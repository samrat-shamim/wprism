#!/usr/bin/env bash
# Shared premise/answer assertion helpers for every harness that sources the
# conformance seeds and postdeploy hooks (issue #3408). Two harnesses run those
# hooks — conformance/run.sh and sandbox/tests/certify/certify_version_matrix.sh — and
# the helpers lived only in the first, so bundle leg 12 died at the first
# premise assertion with `command not found` (exit 127) on pristine main. One
# implementation, sourced by both; run.sh additionally `export -f`s them for
# the hook scripts it runs as child bash processes (docker containers do not
# inherit bash functions — the export is for the host-side children that then
# invoke compose). Callers must define fail() before sourcing, and that is
# enforced, not requested: a third harness sourcing this without fail() would
# otherwise run with a silent no-op safety net that dies `command not found`
# exit 127 on the day a fixture actually fails — the signature this fragment
# exists to eliminate.
command -v fail >/dev/null \
  || { printf 'conformance/asserts.sh: caller must define fail() before sourcing\n' >&2; exit 1; }

# issue #3381: assert the premise before the behavior. A seed/postdeploy hook
# manufactures its fixture through `docker compose run` (the sourcing harness's wp_env), and
# under multi-agent host load that can hand back an EMPTY or noise-polluted
# --porcelain capture without a non-zero exit — `set -e` never fires, the
# fixture silently never lands, and the engine assertion that depends on it
# then fails for a reason that has nothing to do with the engine. Observed
# live 2026-08-09 (issue #3380's first certification bundle, main 7938476):
# postdeploy/core.sh's ambiguous-adoption-key refusal legitimately did not
# fire and the sweep reported "duplicate full hierarchical adoption key was
# not rejected" — a false engine-regression scare plus a ~1h bundle restart;
# the identical sweep standalone passed. These three helpers are what a hook
# calls BETWEEN its manufacture and its engine assertion, so a fixture
# failure names ITSELF: every message they emit carries the grep-able
# "fixture manufacture failed:" prefix, which is an infrastructure signal,
# never an accusation against WPrism. They only ever move a failure from the
# wrong domain into the right one — no engine assertion is weakened, and a
# hook whose fixture landed sees no behavior change at all.
require_fixture_ids() { # require_fixture_ids <VAR_NAME>... — each named var must hold a numeric id
  local name value
  for name in "$@"; do
    value="${!name-}"
    [[ "$value" =~ ^[0-9]+$ ]] \
      || fail "fixture manufacture failed: $name is not a numeric id (got: '${value:-<empty>}') — this hook's own fixture never landed, so nothing after it is testing the engine"
  done
}
require_fixture_values() { # require_fixture_values <VAR_NAME>... — each named var must be non-empty (ids that aren't numeric: uuids, slugs, hashes)
  local name
  for name in "$@"; do
    [ -n "${!name-}" ] \
      || fail "fixture manufacture failed: $name is empty — this hook's own fixture never landed, so nothing after it is testing the engine"
  done
}
require_fixture_state() { # require_fixture_state <what> <expected> <actual>
  [ "$2" = "$3" ] \
    || fail "fixture manufacture failed: $1 — expected '$2', got '${3:-<empty>}'"
}
# issue #3413: the observation-stream sibling of require_wprism_answered. A hook that
# HASHES a live observation (e.g. `wp db export - | shasum`) and then asserts on
# the digest is exposed to the issue #3381 signature: a load-starved `docker
# compose run` that returns EMPTY at exit 0 hashes to the empty-string digest
# `e3b0c442…`, and an equality check against a real before-digest then reports a
# spurious engine mutation ("… changed the target database") printing neither
# hash nor diff. Premise-assert the observation carried bytes BEFORE hashing, so
# an empty stream is named as infrastructure — the "infrastructure failure:"
# domain of require_wprism_answered, never an accusation against WPrism. Call this at
# TOP LEVEL (not inside $(...)) so `fail`'s message reaches the log; pass the
# already-captured value, not a pipe.
require_observed_nonempty() { # require_observed_nonempty <what> <captured value>
  [ -n "$2" ] \
    || fail "infrastructure failure: $1 returned no bytes — a load-starved docker compose run can exit 0 with empty stdout, which hashes to the empty-string digest e3b0c442… and would falsely accuse the engine of mutating the target; nothing here is measuring the engine"
}

# Installed WordPress values are not intended-value authority. The first
# Rank Math/core database round trips at f1c9a6fb reported env_missing for all
# three required core options despite their live values being nonempty. These
# positive fixtures assert the driver's chosen install values before using the
# public provisioning path; pair bootstrap must not do this, because missing-
# binding suites deliberately need an unprovisioned environment. Plugin and
# protected-post bindings remain the owning fixture's explicit choice.
establish_core_environment_bindings() { # <wp command/function> <repo> <email> <home> <siteurl> [command prefix arguments...]
  local wp_command="$1" repo="$2" expected values name value receipt
  expected=$(jq -nc --arg email "$3" --arg home "$4" --arg siteurl "$5" \
    '{admin_email:$email,home:$home,siteurl:$siteurl}')
  shift 5
  command -v "$wp_command" >/dev/null \
    || fail "establish_core_environment_bindings: unknown wp command '$wp_command'"
  capture_wprism_json_success values 'core environment fixture observation' \
    "$wp_command" "$@" eval '
$values = [];
foreach (["admin_email", "home", "siteurl"] as $name) {
    $values[$name] = get_option($name);
}
echo wp_json_encode($values);
'
  jq -e '
    type == "object" and keys == ["admin_email","home","siteurl"] and
    all(.[]; type == "string" and length > 0 and (explode | all(.[]; . != 0 and . != 10 and . != 13)))
  ' <<<"$values" >/dev/null \
    || fail 'fixture manufacture failed: core environment values are not three nonempty single-line strings'
  jq -e --argjson expected "$expected" '. == $expected' <<<"$values" >/dev/null \
    || fail 'fixture manufacture failed: core environment values disagree with the driver-owned install premise; refusing to adopt drift as intent'
  for name in admin_email home siteurl; do
    value=$(jq -er --arg name "$name" '.[$name]' <<<"$expected")
    capture_wprism_json_success receipt "core environment fixture binding $name" \
      "$wp_command" "$@" wprism env-set --repo="$repo" --name="$name" --stdin --format=json \
      <<<"$value"
    jq -e --arg name "$name" '
      keys == ["name","previously_set"] and .name == $name and
      (.previously_set | type) == "boolean"
    ' <<<"$receipt" >/dev/null \
      || fail "fixture manufacture failed: core environment binding $name did not return its exact provisioning receipt"
  done
}

# Exact WooCommerce 11.0.0/11.0.1 new-shop setup. Its `wc hpos enable` CLI
# deliberately emits "Orders table does not exist. Creating..." on a fresh
# database, which makes an otherwise successful evidence run non-green. Drive
# Woo's public install lifecycle instead, then independently require both the
# selected order store and the physical HPOS table before any order fixture.
establish_woocommerce_hpos() { # <wp command/function> [arguments before eval]
  local wp_command="$1"
  shift
  command -v "$wp_command" >/dev/null \
    || fail "establish_woocommerce_hpos: unknown wp command '$wp_command'"
  "$wp_command" "$@" eval '
WC_Install::maybe_enable_hpos();
WC_Install::create_tables();
if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException("WooCommerce native new-shop lifecycle did not enable HPOS");
}
$synchronizer = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
if (!$synchronizer->check_orders_table_exists()) {
    throw new RuntimeException("WooCommerce native new-shop lifecycle did not create the HPOS orders table");
}
'
}

# conformance/run.sh and certify_version_matrix.sh deliberately wrap wp-cli in
# `umask 000` so uid-33 capture output remains removable from native host bind
# mounts. Woo activation runs inside that same process and copies its exact
# placeholder attachment as 0666; AttachmentFilesystemTransaction correctly
# refuses that existing file as world-writable before apply. Narrow the test
# artifact only: both reviewed 11.0.x archives carry source hash 019e9bee…, and
# any other bytes or unsafe mode still refuse instead of being repaired.
normalize_woocommerce_harness_placeholder_mode() { # <wp command/function> [arguments before eval]
  local wp_command="$1"
  shift
  command -v "$wp_command" >/dev/null \
    || fail "normalize_woocommerce_harness_placeholder_mode: unknown wp command '$wp_command'"
  "$wp_command" "$@" eval '
$uploads = wp_upload_dir();
$path = trailingslashit((string) ($uploads["basedir"] ?? "")) . "woocommerce-placeholder.webp";
$expected = "019e9beec61c9ee5b6009335c7846816452e1e3b420d2bb9e50327681dfade19";
$stat = @lstat($path);
if (!is_array($stat) || !is_file($path) || is_link($path)) {
    throw new RuntimeException("WooCommerce harness placeholder is absent or not a regular file");
}
$sha = @hash_file("sha256", $path);
if (!is_string($sha) || !hash_equals($expected, $sha)) {
    throw new RuntimeException("WooCommerce harness placeholder differs from the exact 11.0.x artifact");
}
$mode = ((int) ($stat["mode"] ?? 0)) & 0777;
if (($mode & 0002) !== 0) {
    if ($mode !== 0666 || !@chmod($path, 0644)) {
        throw new RuntimeException("WooCommerce harness placeholder has an unexpected unsafe mode");
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    $mode = is_array($stat) ? (((int) ($stat["mode"] ?? 0)) & 0777) : 0;
}
if (($mode & 0400) === 0 || ($mode & 0002) !== 0) {
    throw new RuntimeException("WooCommerce harness placeholder did not reach a safe publication mode");
}
'
}

# issue #3391: the sibling failure domain, and the residual path issue #3381
# deliberately did not cover. The three helpers above assert that a hook's own
# FIXTURE landed; this one asserts that the wprism INVOCATION the hook then makes
# its assertion about actually happened. Every refusal assertion in this suite
# neutralizes that invocation's exit status on purpose — `|| RC=$?` so it can
# inspect the output, or `|| fail` so it can name the engine — which is
# exactly what disables `set -e` for it. So when `docker compose run` dies at
# the DOCKER layer (container creation refused, daemon saturated by parallel
# agents, image racing another pull), the hook still runs its assertion, over
# a capture that holds nothing but compose's own container-creation chatter:
# the refusal grep legitimately does not match and the sweep reports the
# ENGINE ("... was not rejected", with that chatter pasted in from $OUT) for a
# command that never reached the engine. That is what issue #3380's archived $OUT
# pollution shows, at the same price issue #3381 paid: a false engine-regression
# scare plus a ~1h certification-bundle restart.
#
# A hook calls this BETWEEN its wprism invocation and its assertion about that
# invocation's output. Messages carry the grep-able "infrastructure failure:"
# prefix — a SIBLING of "fixture manufacture failed:" above, deliberately
# distinct because they name different domains (that one: this hook never
# built its premise; this one: this hook never got an answer). Neither is ever
# an accusation against WPrism. No engine assertion is reworded or weakened, and
# an invocation that was answered sees no behavior change at all.
#
# The marker of "answered" is deliberately BROAD in both modes: human accepts
# wp-cli's own framing of any answer it gives (Success:/Error:/Warning:), wprism's
# own `wprism:` message prefix, or PHP's own fatal framing; json accepts any JSON
# value wprism's machine contract can legitimately be (an object or an array — see
# the mode itself). A NARROW marker would be the dangerous one — it could demote
# a real but differently-worded engine failure into an infrastructure signal,
# i.e. weaken an engine assertion. Broad, the helper can only ever fire on the
# case it exists for: nothing came back from the containerized process at all.
require_wprism_answered() { # require_wprism_answered <what> <human|json> <captured output>
  local what="$1" mode="$2" out="$3" last
  case "$mode" in
    human)
      # 2>&1-merged human capture: wp-cli frames every answer it gives.
      grep -Eq '^(Success|Error|Warning): |(^|[[:space:]])wprism:|^PHP [A-Z]|^Fatal error' <<<"$out" \
        || fail "infrastructure failure: $what was never answered — the capture carries no wp-cli Success:/Error:/Warning: line, no 'wprism:' message, no PHP error, so this invocation died at the docker/compose layer and nothing after it is testing the engine: ${out:-<empty>}"
      ;;
    json)
      # --format=json capture: one JSON value on stdout, success summary or
      # wprism-command-refusal/v1 alike, read exactly as the assertions do (last
      # non-empty line). Object OR array: `wprism pending --format=json`
      # legitimately answers `[]`, and pinning this to objects would make the
      # NEXT array-answering site report a healthy engine as an infrastructure
      # failure — the same narrowness the human marker is written to avoid.
      # Widening cannot weaken an existing site: every json caller asserts a
      # top-level object field downstream, so an array still fails there, as
      # the accusation it belongs to.
      last=$(awk 'NF { line=$0 } END { print line }' <<<"$out")
      # `jq -e` without slurp succeeds when it receives zero JSON inputs, so
      # prove the selected line supplied exactly one answer before accepting
      # its envelope shape. This remains intentionally scoped to `last`: any
      # preceding compose chatter is not engine data.
      jq -e -s 'length == 1 and (.[0] | type == "object" or type == "array")' >/dev/null 2>&1 <<<"$last" \
        || fail "infrastructure failure: $what was never answered — the capture's last non-empty line is not a JSON envelope, so this invocation died at the docker/compose layer and nothing after it is testing the engine: ${out:-<empty>}"
      ;;
    *)
      fail "require_wprism_answered: unknown mode '$mode' (expected human|json)"
      ;;
  esac
}

# A native warning can precede an otherwise valid JSON success with exit zero.
# Inspect the complete captured stream before publishing a positive answer;
# Startup failures can name Unknown on line 0, and parse failures need not
# print a filename at all. PHP-prefixed severities therefore need no location;
# bare display_errors severities still require a location frame to distinguish
# them from WP-CLI action receipts. The predicate never prints private bytes.
has_php_runtime_diagnostics() { # <captured output>
  grep -Eq '(^|[[:space:]])PHP (Warning|Notice|Deprecated|Fatal error|Parse error|Startup|Strict Standards|Recoverable fatal error):|(^|[[:space:]])(Warning|Notice|Deprecated|Fatal error|Parse error|Strict Standards|Recoverable fatal error): .* in .*( on line [0-9]+|:[0-9]+)' <<<"$1"
}

assert_no_php_runtime_diagnostics() { # <label> <captured output>
  if has_php_runtime_diagnostics "$2"; then
    fail "$1 emitted a PHP runtime diagnostic; inspect its captured output"
  fi
}

# Execute one machine-output WPrism command without letting `set -e`, a command
# substitution, or `tail` discard its refusal envelope. The command's complete
# capture is retained through exit classification; only a successful
# command publishes its last JSON line into the caller-named variable. Prefix
# diagnostics remain visible on stderr; PHP runtime diagnostics also refuse a
# zero-exit answer before publication. Internal locals reserve
# __wprism_capture_*; rejecting that prefix keeps
# every other valid caller variable safe from Bash's dynamic local scope.
capture_wprism_json_success() { # <OUT_VAR> <what> <command> [args...]
  local __wprism_capture_out_var="$1" __wprism_capture_what="$2"
  local __wprism_capture_stream='' __wprism_capture_rc=0 __wprism_capture_last=''
  shift 2
  [[ "$__wprism_capture_out_var" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] \
    || fail "capture_wprism_json_success: malformed output variable"
  [[ "$__wprism_capture_out_var" != __wprism_capture_* ]] \
    || fail "capture_wprism_json_success: reserved output variable prefix __wprism_capture_"
  __wprism_capture_stream=$("$@" 2>&1) || __wprism_capture_rc=$?
  require_wprism_answered "$__wprism_capture_what" json "$__wprism_capture_stream"
  __wprism_capture_last=$(awk 'NF { line=$0 } END { print line }' <<<"$__wprism_capture_stream")
  awk 'NF { last=NR } { lines[NR]=$0 } END { for (i=1; i<last; i++) print lines[i] }' \
    <<<"$__wprism_capture_stream" >&2
  if [ "$__wprism_capture_rc" -ne 0 ]; then
    printf '%s\n' "$__wprism_capture_last" >&2
    fail "$__wprism_capture_what failed with exit $__wprism_capture_rc"
  fi
  assert_no_php_runtime_diagnostics "$__wprism_capture_what" "$__wprism_capture_stream"
  printf -v "$__wprism_capture_out_var" '%s' "$__wprism_capture_last"
}

# Expected refusal sibling of capture_wprism_json_success. Compose writes its
# container lifecycle to the merged stream before WP-CLI's JSON answer; callers
# must assert on the selected answer, not feed that transport prelude to jq.
capture_wprism_json_refusal() { # <OUT_VAR> <what> <command> [args...]
  local __wprism_capture_out_var="$1" __wprism_capture_what="$2"
  local __wprism_capture_stream='' __wprism_capture_rc=0 __wprism_capture_last=''
  shift 2
  [[ "$__wprism_capture_out_var" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] \
    || fail "capture_wprism_json_refusal: malformed output variable"
  [[ "$__wprism_capture_out_var" != __wprism_capture_* ]] \
    || fail "capture_wprism_json_refusal: reserved output variable prefix __wprism_capture_"
  __wprism_capture_stream=$("$@" 2>&1) || __wprism_capture_rc=$?
  require_wprism_answered "$__wprism_capture_what" json "$__wprism_capture_stream"
  __wprism_capture_last=$(awk 'NF { line=$0 } END { print line }' <<<"$__wprism_capture_stream")
  awk 'NF { last=NR } { lines[NR]=$0 } END { for (i=1; i<last; i++) print lines[i] }' \
    <<<"$__wprism_capture_stream" >&2
  if [ "$__wprism_capture_rc" -eq 0 ]; then
    fail "$__wprism_capture_what unexpectedly succeeded: $__wprism_capture_last"
  fi
  printf -v "$__wprism_capture_out_var" '%s' "$__wprism_capture_last"
}

# Apply's env_missing summary counts optional rows too (Code Snippets leaves
# an optional plugin option absent). Only required rows produce env_missing:
# diagnostics in ApplyPlanner::env_missing_projection(). Its warnings field
# also carries adoption/provider/native-action receipts, so an empty-array
# requirement would reject the very lifecycle work conformance must exercise.
# Keep those existing wire bytes visible while refusing unprovisioned evidence.
assert_wprism_required_environment() { # <what> <human|json> <captured output>
  local what="$1" mode="$2" out="$3" last
  require_wprism_answered "$what" "$mode" "$out"
  if grep -Eq '(^|[[:space:]])env_missing:' <<<"$out"; then
    fail "$what did not prove all required environment bindings; inspect its env_missing diagnostics"
  fi
  case "$mode" in
    json)
      last=$(awk 'NF { line=$0 } END { print line }' <<<"$out")
      jq -e '.warnings | type == "array" and all(.[]; type == "string" and (startswith("env_missing:") | not))' \
        <<<"$last" >/dev/null \
        || fail "$what did not prove all required environment bindings; inspect its env_missing diagnostics"
      ;;
  esac
}

assert_wprism_apply_ready() { # <what> <JSON apply capture>
  local last
  assert_no_php_runtime_diagnostics "$1" "$2"
  assert_wprism_required_environment "$1" json "$2"
  last=$(awk 'NF { line=$0 } END { print line }' <<<"$2")
  jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$last" >/dev/null \
    || fail "$1 did not return a clean canary and passed canonical verification"
}
