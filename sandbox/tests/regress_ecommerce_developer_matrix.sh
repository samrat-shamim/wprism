#!/usr/bin/env bash
# Offline DUO-3337 contract for the executable ecommerce developer move
# matrix.  The matrix describes the public command, live harness evidence,
# and bounded Linear owner for every required move; this check intentionally
# does not start Docker or mutate a repository pair.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MATRIX="sandbox/tests/grind_ecommerce_developer.matrix.json"
HARNESS="sandbox/tests/grind_ecommerce_developer.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok: $*"; }

command -v jq >/dev/null 2>&1 || fail 'jq is required to validate the DUO-3337 move matrix'
[ -f "$MATRIX" ] || fail "matrix is missing: $MATRIX"
[ -x "$HARNESS" ] || fail "live harness is missing or not executable: $HARNESS"

REQUIRED_FIELDS_JSON='["id","move","intent","status","public_command","code_delta","authored_state_delta","generated_state_policy","required_capabilities","expected_semantic_plan","expected_write_set","failure_semantics","convergence_rule","rollback_rule","harness","evidence_anchors","acceptance","gap","linear_routing"]'
STATUSES_JSON='["exercised","planned_gap","reproduced_gap"]'
ROUTES_JSON='["DUO-3324","DUO-3326","DUO-3336","DUO-3338","DUO-3343","DUO-3344","DUO-3357","DUO-3358","DUO-3359"]'
REQUIRED_IDS_JSON='[
  "initialize-adopt-golden-path",
  "branch-from-production",
  "state-only-capture",
  "code-materialization-and-deploy",
  "state-apply-runtime-sovereignty",
  "catalog-taxonomy-media-options",
  "navigation-menu-round-trip",
  "authored-users",
  "extension-install-upgrade",
  "extension-schema-lifecycle",
  "extension-removal",
  "theme-upgrade-downgrade-removal",
  "generated-state-action-scheduler",
  "wordpress-cron",
  "explicit-plugin-theme-replacement",
  "semantic-preview",
  "refresh-rebase",
  "state-conflict-and-code-drift",
  "complete-promotion",
  "scoped-promotion",
  "unsupported-woo-deletion",
  "compatibility-downgrade-refusal",
  "dependency-refusal",
  "failure-recovery-retry",
  "exact-rollback-restore",
  "final-recapture-cleanup"
]'

check_matrix() {
  local candidate="$1" row id status harness anchor issue
  [ -f "$candidate" ] || return 1

  jq -e \
    --argjson expected_fields "$REQUIRED_FIELDS_JSON" \
    --argjson expected_statuses "$STATUSES_JSON" \
    --argjson expected_ids "$REQUIRED_IDS_JSON" \
    '
      . as $root |
      ($root.moves | map(select(.id == "explicit-plugin-theme-replacement")) | .[0]) as $replacement |
      ($root | type == "object") and
      ($root.schema == "duo/ecommerce-developer-move-matrix/v1") and
      ($root.issue == "DUO-3337") and
      (($root.statuses | type == "array") and (($root.statuses | sort) == $expected_statuses)) and
      (($root.required_move_fields | type == "array") and (($root.required_move_fields | sort) == ($expected_fields | sort))) and
      (($root.required_move_ids | type == "array") and (($root.required_move_ids | sort) == ($expected_ids | sort))) and
      (($root.moves | type == "array") and (($root.moves | length) == ($expected_ids | length))) and
      (($root.moves | map(.id) | sort) == ($expected_ids | sort)) and
      (($root.moves | map(.status) | unique | sort) == $expected_statuses) and
      ($root.coverage_summary == (reduce $root.moves[] as $move
        ({exercised: 0, planned_gap: 0, reproduced_gap: 0}; .[$move.status] += 1))) and
      all($root.moves[]; ((keys | sort) == ($expected_fields | sort))) and
      all($root.moves[]; (.id | type == "string" and test("^[a-z0-9-]+$"))) and
      all($root.moves[]; (.move | type == "string" and length > 0)) and
      all($root.moves[]; (.intent | type == "string" and length > 0)) and
      all($root.moves[]; (.status | type == "string" and (["exercised", "planned_gap", "reproduced_gap"] | index(.)) != null)) and
      all($root.moves[]; (.public_command | type == "string" and length > 0)) and
      all($root.moves[]; (.code_delta | type == "array" and length > 0 and all(.[]; type == "string" and length > 0))) and
      all($root.moves[]; (.authored_state_delta | type == "array" and length > 0 and all(.[]; type == "string" and length > 0))) and
      all($root.moves[]; (.generated_state_policy | type == "string" and length > 0)) and
      all($root.moves[]; (.required_capabilities | type == "array" and length > 0 and all(.[]; type == "string" and length > 0))) and
      all($root.moves[]; (.expected_semantic_plan | type == "array" and length > 0 and all(.[]; type == "string" and length > 0))) and
      all($root.moves[]; (.expected_write_set | type == "array" and length > 0 and all(.[]; type == "string" and length > 0))) and
      all($root.moves[]; (.failure_semantics | type == "string" and length > 0)) and
      all($root.moves[]; (.convergence_rule | type == "string" and length > 0)) and
      all($root.moves[]; (.rollback_rule | type == "string" and length > 0)) and
      all($root.moves[]; (.evidence_anchors | type == "array" and all(.[]; type == "string"))) and
      all($root.moves[]; (.acceptance | type == "array" and length > 0 and all(.[]; type == "string" and length > 0))) and
      ($replacement.status == "exercised") and
      ($replacement.public_command == "php cli/duo --envs-file=<pair-envs> promote target --with-deletes") and
      ($replacement.harness == "sandbox/tests/grind_ecommerce_developer.sh") and
      (([($replacement | .. | strings)] | join("\n")) | contains("--replace-extension") | not) and
      (($replacement.code_delta | join("\n")) | contains("duo-commerce-extension") and contains("duo-commerce-replacement")) and
      (($replacement.authored_state_delta | join("\n")) | contains("active_plugins") and contains("expected-hash")) and
      (($replacement.expected_semantic_plan | join("\n")) |
        contains("unexpected_active_plugin") and contains("missing_in_code") and
        contains("code_revision_stale") and contains("options/core") and
        contains("provider") and contains("effects")) and
      (($replacement.expected_write_set | join("\n")) |
        contains("Outgoing/incoming owned code roots") and contains("active_plugins") and contains("setting")) and
      ($replacement.failure_semantics |
        contains("Woo dependency") and contains("before promotion-begin") and
        contains("without target cleanup or a code bind")) and
      ($replacement.convergence_rule |
        contains("old root") and contains("replacement is active") and contains("status is clean")) and
      ($replacement.rollback_rule |
        contains("immediate-prior v2") and contains("checkpoint") and contains("Public reverse promotion") and
        contains("not imported") and contains("supersedes")) and
      (($replacement.evidence_anchors | index("REPLACEMENT_PLAN=\"$(plan_json)\"")) != null) and
      (($replacement.evidence_anchors | index("code_plugin_dependency_inactive")) != null) and
      (($replacement.evidence_anchors | index("REPLACEMENT_ROLLBACK_STATUS=\"$(status 2>&1)\"")) != null) and
      ($replacement.gap == null) and ($replacement.linear_routing == null)
    ' "$candidate" >/dev/null || return 1

  while IFS= read -r row; do
    id="$(jq -r '.id' <<<"$row")"
    status="$(jq -r '.status' <<<"$row")"
    public_command="$(jq -r '.public_command' <<<"$row")"
    harness="$(jq -r '.harness // ""' <<<"$row")"

    case "$status" in
      exercised|reproduced_gap)
        case "$public_command" in
          'wp duo '*|'php cli/duo '*) ;;
          'wp cron '*)
            [[ "$public_command" =~ ^wp[[:space:]]cron[[:space:]]event[[:space:]]run[[:space:]][A-Za-z0-9_.:-]+[[:space:]]--due-now$ ]] || {
              echo "matrix row '$id' names an unbounded or malformed WP-Cron command: $public_command" >&2
              return 1
            }
            ;;
          *) echo "matrix row '$id' does not name an existing public entrypoint: $public_command" >&2; return 1 ;;
        esac
        ;;
      planned_gap)
        case "$public_command" in
          PROPOSED/UNAVAILABLE:*|'wp duo '*|'php cli/duo '*) ;;
          'wp cron '*)
            [[ "$public_command" =~ ^wp[[:space:]]cron[[:space:]]event[[:space:]]run[[:space:]][A-Za-z0-9_.:-]+[[:space:]]--due-now$ ]] || {
              echo "matrix gap row '$id' names an unbounded or malformed WP-Cron command: $public_command" >&2
              return 1
            }
            ;;
          *) echo "matrix gap row '$id' names neither a proposed nor existing public entrypoint: $public_command" >&2; return 1 ;;
        esac
        ;;
    esac

    if [ "$status" = exercised ] || [ "$status" = reproduced_gap ]; then
      [ "$harness" = "$HARNESS" ] || return 1
      [ "$(jq '.evidence_anchors | length' <<<"$row")" -gt 0 ] || return 1
      while IFS= read -r anchor; do
        [ -n "$anchor" ] || return 1
        grep -Fq -- "$anchor" "$HARNESS" || {
          echo "matrix row '$id' has no harness evidence anchor: $anchor" >&2
          return 1
        }
      done < <(jq -r '.evidence_anchors[]' <<<"$row")
    else
      [ "$harness" = "" ] || return 1
      [ "$(jq '.evidence_anchors | length' <<<"$row")" -eq 0 ] || return 1
    fi

    if [ "$status" = planned_gap ] || [ "$status" = reproduced_gap ]; then
      jq -e '.gap | type == "object" and (.kind | type == "string" and length > 0) and (.reason | type == "string" and length > 0)' <<<"$row" >/dev/null || return 1
      jq -e '.linear_routing | type == "object" and (.issue | type == "string") and (.reason | type == "string" and length > 0) and (.bounded == true)' <<<"$row" >/dev/null || return 1
      issue="$(jq -r '.linear_routing.issue' <<<"$row")"
      jq -e --arg issue "$issue" --argjson routes "$ROUTES_JSON" '$routes | index($issue) != null' <<<"{}" >/dev/null || {
        echo "matrix row '$id' has unbounded/unknown Linear route: $issue" >&2
        return 1
      }
    else
      jq -e '.gap == null and .linear_routing == null' <<<"$row" >/dev/null || return 1
    fi
  done < <(jq -c '.moves[]' "$candidate")

  return 0
}

check_public_entrypoint() {
  local target
  grep -Fq 'grind-ecommerce-developer-live:' Makefile || return 1
  target="$(sed -n '/^grind-ecommerce-developer-live:/,/^[^[:space:]]/p' Makefile)"
  grep -Fq '$(ECOMMERCE_PAIR)' <<<"$target" || return 1
  grep -Fq '$(PORT1)' <<<"$target" || return 1
  grep -Fq '$(PORT2)' <<<"$target" || return 1
  grep -Fq 'ECOMMERCE_PORT1="$(PORT1)"' <<<"$target" || return 1
  grep -Fq 'ECOMMERCE_PORT2="$(PORT2)"' <<<"$target" || return 1
  grep -Fq 'sandbox/tests/grind_ecommerce_developer.sh' <<<"$target" || return 1
  grep -Fq 'regress-ecommerce-developer-matrix' Makefile || return 1
  grep -Fq 'regress-ecommerce-developer-matrix' <(sed -n '/^regress-offline-corpus:/,/^[[:space:]]*@echo/p' Makefile) || return 1
  return 0
}

check_matrix "$MATRIX" || fail 'DUO-3337 matrix schema, IDs, commands, anchors, or bounded routes are invalid'
check_public_entrypoint || fail 'DUO-3337 public live target is missing, not explicit, or not offline-wired'
pass 'DUO-3337 matrix schema, required coverage IDs, harness anchors, and bounded routes are valid'

MUTATED_ANCHOR="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-matrix-anchor.XXXXXX")"
MUTATED_ROUTE="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-matrix-route.XXXXXX")"
MUTATED_CONTRACT="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-matrix-contract.XXXXXX")"
MUTATED_CRON_COMMAND="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-matrix-cron.XXXXXX")"
MUTATED_CRON_FLAGS="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-matrix-cron-flags.XXXXXX")"
MUTATED_REPLACEMENT="$(mktemp "${TMPDIR:-/tmp}/duo-ecommerce-matrix-replacement.XXXXXX")"
cleanup_matrix() {
  rm -f -- "$MUTATED_ANCHOR" "$MUTATED_ROUTE" "$MUTATED_CONTRACT" "$MUTATED_CRON_COMMAND" "$MUTATED_CRON_FLAGS" "$MUTATED_REPLACEMENT"
}
trap cleanup_matrix EXIT

# Self-mutation proof: a missing live evidence anchor must fail the same
# checker used for the real matrix.  This prevents the regression from
# becoming a schema-only check that can silently lose executable proof.
jq '(.moves[] | select(.status == "exercised") | .evidence_anchors[0]) = "DUO_3337_SELF_MUTATION_MISSING_ANCHOR"' \
  "$MATRIX" >"$MUTATED_ANCHOR"
if check_matrix "$MUTATED_ANCHOR" >/dev/null 2>&1; then
  fail 'self-mutation proof: missing exercised harness anchor unexpectedly passed'
fi
pass 'self-mutation proof: missing exercised harness anchor is rejected'

# Self-mutation proof: a gap cannot route to an unbounded or unknown issue.
jq '(.moves[] | select(.status == "planned_gap") | .linear_routing.issue) = "UNBOUNDED_LINEAR_ROUTE"' \
  "$MATRIX" >"$MUTATED_ROUTE"
if check_matrix "$MUTATED_ROUTE" >/dev/null 2>&1; then
  fail 'self-mutation proof: unbounded Linear route unexpectedly passed'
fi
pass 'self-mutation proof: unknown Linear route is rejected'

# Self-mutation proof: a required contract cannot be erased while the row
# remains otherwise well-shaped.  This guards against the regression drifting
# into a presence-only matrix check.
jq '(.moves[] | select(.id == "exact-rollback-restore") | .rollback_rule) = ""' \
  "$MATRIX" >"$MUTATED_CONTRACT"
if check_matrix "$MUTATED_CONTRACT" >/dev/null 2>&1; then
  fail 'self-mutation proof: empty rollback_rule unexpectedly passed'
fi
pass 'self-mutation proof: empty required rollback contract is rejected'

# Self-mutation proof: the cron row may not silently widen from one named hook
# to WP-CLI's global --all/--due-now drain.
jq '(.moves[] | select(.id == "wordpress-cron") | .public_command) = "wp cron event run --all"' \
  "$MATRIX" >"$MUTATED_CRON_COMMAND"
if check_matrix "$MUTATED_CRON_COMMAND" >/dev/null 2>&1; then
  fail 'self-mutation proof: unbounded WP-Cron command unexpectedly passed'
fi
pass 'self-mutation proof: unbounded WP-Cron command is rejected'

# A named hook must not be made unbounded by appending a global-drain flag.
jq '(.moves[] | select(.id == "wordpress-cron") | .public_command) = "wp cron event run publish_future_post --due-now --all"' \
  "$MATRIX" >"$MUTATED_CRON_FLAGS"
if check_matrix "$MUTATED_CRON_FLAGS" >/dev/null 2>&1; then
  fail 'self-mutation proof: trailing WP-Cron drain flag unexpectedly passed'
fi
pass 'self-mutation proof: trailing WP-Cron drain flag is rejected'

# Self-mutation proof: the replacement row cannot drift back to a fictional
# late apply flag while retaining its live anchors and otherwise-valid shape.
jq '(.moves[] | select(.id == "explicit-plugin-theme-replacement") | .public_command) = "php cli/duo --envs-file=<pair-envs> promote target --replace-extension=duo-commerce-extension"' \
  "$MATRIX" >"$MUTATED_REPLACEMENT"
if check_matrix "$MUTATED_REPLACEMENT" >/dev/null 2>&1; then
  fail 'self-mutation proof: fictional replacement command unexpectedly passed'
fi
pass 'self-mutation proof: the exercised replacement stays on generic public promote'

pass 'DUO-3337 ecommerce developer move matrix regression passed offline'
