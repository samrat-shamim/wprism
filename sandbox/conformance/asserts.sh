#!/usr/bin/env bash
# Shared premise/answer assertion helpers for every harness that sources the
# conformance seeds and postdeploy hooks (DUO-3408). Two harnesses run those
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

# DUO-3381: assert the premise before the behavior. A seed/postdeploy hook
# manufactures its fixture through `docker compose run` (the sourcing harness's wp_env), and
# under multi-agent host load that can hand back an EMPTY or noise-polluted
# --porcelain capture without a non-zero exit — `set -e` never fires, the
# fixture silently never lands, and the engine assertion that depends on it
# then fails for a reason that has nothing to do with the engine. Observed
# live 2026-08-09 (DUO-3380's first certification bundle, main 7938476):
# postdeploy/core.sh's ambiguous-adoption-key refusal legitimately did not
# fire and the sweep reported "duplicate full hierarchical adoption key was
# not rejected" — a false engine-regression scare plus a ~1h bundle restart;
# the identical sweep standalone passed. These three helpers are what a hook
# calls BETWEEN its manufacture and its engine assertion, so a fixture
# failure names ITSELF: every message they emit carries the grep-able
# "fixture manufacture failed:" prefix, which is an infrastructure signal,
# never an accusation against Duo. They only ever move a failure from the
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
# DUO-3413: the observation-stream sibling of require_duo_answered. A hook that
# HASHES a live observation (e.g. `wp db export - | shasum`) and then asserts on
# the digest is exposed to the DUO-3381 signature: a load-starved `docker
# compose run` that returns EMPTY at exit 0 hashes to the empty-string digest
# `e3b0c442…`, and an equality check against a real before-digest then reports a
# spurious engine mutation ("… changed the target database") printing neither
# hash nor diff. Premise-assert the observation carried bytes BEFORE hashing, so
# an empty stream is named as infrastructure — the "infrastructure failure:"
# domain of require_duo_answered, never an accusation against Duo. Call this at
# TOP LEVEL (not inside $(...)) so `fail`'s message reaches the log; pass the
# already-captured value, not a pipe.
require_observed_nonempty() { # require_observed_nonempty <what> <captured value>
  [ -n "$2" ] \
    || fail "infrastructure failure: $1 returned no bytes — a load-starved docker compose run can exit 0 with empty stdout, which hashes to the empty-string digest e3b0c442… and would falsely accuse the engine of mutating the target; nothing here is measuring the engine"
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

# DUO-3391: the sibling failure domain, and the residual path DUO-3381
# deliberately did not cover. The three helpers above assert that a hook's own
# FIXTURE landed; this one asserts that the duo INVOCATION the hook then makes
# its assertion about actually happened. Every refusal assertion in this suite
# neutralizes that invocation's exit status on purpose — `|| RC=$?` so it can
# inspect the output, or `|| fail` so it can name the engine — which is
# exactly what disables `set -e` for it. So when `docker compose run` dies at
# the DOCKER layer (container creation refused, daemon saturated by parallel
# agents, image racing another pull), the hook still runs its assertion, over
# a capture that holds nothing but compose's own container-creation chatter:
# the refusal grep legitimately does not match and the sweep reports the
# ENGINE ("... was not rejected", with that chatter pasted in from $OUT) for a
# command that never reached the engine. That is what DUO-3380's archived $OUT
# pollution shows, at the same price DUO-3381 paid: a false engine-regression
# scare plus a ~1h certification-bundle restart.
#
# A hook calls this BETWEEN its duo invocation and its assertion about that
# invocation's output. Messages carry the grep-able "infrastructure failure:"
# prefix — a SIBLING of "fixture manufacture failed:" above, deliberately
# distinct because they name different domains (that one: this hook never
# built its premise; this one: this hook never got an answer). Neither is ever
# an accusation against Duo. No engine assertion is reworded or weakened, and
# an invocation that was answered sees no behavior change at all.
#
# The marker of "answered" is deliberately BROAD in both modes: human accepts
# wp-cli's own framing of any answer it gives (Success:/Error:/Warning:), duo's
# own `duo:` message prefix, or PHP's own fatal framing; json accepts any JSON
# value duo's machine contract can legitimately be (an object or an array — see
# the mode itself). A NARROW marker would be the dangerous one — it could demote
# a real but differently-worded engine failure into an infrastructure signal,
# i.e. weaken an engine assertion. Broad, the helper can only ever fire on the
# case it exists for: nothing came back from the containerized process at all.
require_duo_answered() { # require_duo_answered <what> <human|json> <captured output>
  local what="$1" mode="$2" out="$3" last
  case "$mode" in
    human)
      # 2>&1-merged human capture: wp-cli frames every answer it gives.
      grep -Eq '^(Success|Error|Warning): |(^|[[:space:]])duo:|^PHP [A-Z]|^Fatal error' <<<"$out" \
        || fail "infrastructure failure: $what was never answered — the capture carries no wp-cli Success:/Error:/Warning: line, no 'duo:' message, no PHP error, so this invocation died at the docker/compose layer and nothing after it is testing the engine: ${out:-<empty>}"
      ;;
    json)
      # --format=json capture: one JSON value on stdout, success summary or
      # duo-command-refusal/v1 alike, read exactly as the assertions do (last
      # non-empty line). Object OR array: `duo pending --format=json`
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
      fail "require_duo_answered: unknown mode '$mode' (expected human|json)"
      ;;
  esac
}

# Execute one machine-output Duo command without letting `set -e`, a command
# substitution, or `tail` discard its refusal envelope. The command's complete
# capture is retained through exit classification; only a successful
# command publishes its last JSON line into the caller-named variable.
capture_duo_json_success() { # <OUT_VAR> <what> <command> [args...]
  local out_var="$1" what="$2" capture rc=0 last
  shift 2
  [[ "$out_var" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] \
    || fail "capture_duo_json_success: malformed output variable"
  capture=$("$@" 2>&1) || rc=$?
  require_duo_answered "$what" json "$capture"
  if [ "$rc" -ne 0 ]; then
    printf '%s\n' "$capture" >&2
    fail "$what failed with exit $rc"
  fi
  last=$(awk 'NF { line=$0 } END { print line }' <<<"$capture")
  printf -v "$out_var" '%s' "$last"
}
