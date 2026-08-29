#!/usr/bin/env bash
# Actual-use ledger for pair-admission force hatches: the accountability
# record for WPRISM_PAIR_BUDGET_OVERRIDE, and nothing beyond that.
#
# The operator environment says that a hatch is AVAILABLE; it does not prove
# the hatch was USED.  A pair already carrying a lease reservation
# (pair_lease_reserved_pairs, pair.sh:484) is admitted on that reservation,
# which pair.sh answers before it ever consults WPRISM_PAIR_BUDGET_OVERRIDE — so
# "variable exported" and "budget actually forced" are different facts, and
# pair.sh records only the branch it actually takes (pair.sh:524).
#
# What this ledger no longer is: the certification wrappers that used to
# pair_force_hatch_init one private copy and project it into a bundle went
# with the rest of the evidence apparatus.  Nothing in the tree reads these
# bytes into another document, so _init and pair_force_hatch_json exist for a
# caller that wants a per-run audit trail (export WPRISM_PAIR_FORCE_HATCH_LOG),
# not for a gate.  With none configured, recording is a no-op and pair.sh's
# loud over-budget warning is the whole record.
#
# Source-only library.  Callers own strict-shell mode and diagnostics.  The
# JSON reader requires jq and checks for it rather than assuming a caller did.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  printf 'FAIL: pair_force_hatch.sh is a source-only library; source it from pair.sh or its offline regression\n' >&2
  exit 1
fi

pair_force_hatch_init() { # <new-absolute-ledger-path>
  local ledger="$1"
  [ -n "$ledger" ] || return 1
  case "$ledger" in
    /*) ;;
    *) return 1 ;;
  esac
  [ ! -e "$ledger" ] && [ ! -L "$ledger" ] || return 1
  (umask 077 && : > "$ledger") || return 1
  WPRISM_PAIR_FORCE_HATCH_LOG="$ledger"
  export WPRISM_PAIR_FORCE_HATCH_LOG
}

pair_force_hatch_record() { # <reviewed-hatch-name>
  local hatch="$1" ledger="${WPRISM_PAIR_FORCE_HATCH_LOG:-}"
  case "$hatch" in
    WPRISM_PAIR_BUDGET_OVERRIDE) ;;
    *) return 1 ;;
  esac
  # Ordinary pair users configure no ledger at all.  With none named, the
  # existing loud over-budget warning is the record, so absence is success
  # rather than a refusal that would block every unaudited pair.
  [ -n "$ledger" ] || return 0
  [ -f "$ledger" ] && [ ! -L "$ledger" ] || return 1
  printf '%s\n' "$hatch" >> "$ledger"
}

pair_force_hatch_json() { # [ledger-path]
  local ledger="${1:-${WPRISM_PAIR_FORCE_HATCH_LOG:-}}"
  [ -n "$ledger" ] || return 1
  [ -f "$ledger" ] && [ ! -L "$ledger" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  # Unknown bytes fail closed instead of becoming an implicitly approved new
  # hatch.  Repeated actual uses within one run collapse to one stable entry,
  # so the answer is "which hatches were forced", not a use count.
  if grep -vFx 'WPRISM_PAIR_BUDGET_OVERRIDE' "$ledger" >/dev/null; then
    return 1
  fi
  jq -Rcn '[inputs] | unique' < "$ledger"
}
