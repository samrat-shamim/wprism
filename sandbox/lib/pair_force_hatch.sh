#!/usr/bin/env bash
# Actual-use ledger for pair-admission force hatches.
#
# The operator environment says that a hatch is AVAILABLE; it does not prove
# the hatch was USED.  In particular, a pair named by the held reference-
# certification lock is already reserved and pair.sh deliberately answers that
# reservation before consulting DUO_PAIR_BUDGET_OVERRIDE.  Certification
# wrappers initialize one private ledger, pair.sh records only the branch it
# actually takes, and the wrappers validate/project that ledger into bundle
# evidence after every live leg has finished.
#
# Source-only library.  Callers own strict-shell mode and diagnostics.  The
# JSON projection requires jq, which both certification wrappers already
# require before initializing the ledger.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  printf 'FAIL: pair_force_hatch.sh is a source-only library; source it from pair.sh, a certification wrapper, or its offline regression\n' >&2
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
  DUO_PAIR_FORCE_HATCH_LOG="$ledger"
  export DUO_PAIR_FORCE_HATCH_LOG
}

pair_force_hatch_record() { # <reviewed-hatch-name>
  local hatch="$1" ledger="${DUO_PAIR_FORCE_HATCH_LOG:-}"
  case "$hatch" in
    DUO_PAIR_BUDGET_OVERRIDE) ;;
    *) return 1 ;;
  esac
  # Ordinary pair users do not produce certification evidence.  With no
  # wrapper-owned ledger configured, the existing loud warning is the record.
  [ -n "$ledger" ] || return 0
  [ -f "$ledger" ] && [ ! -L "$ledger" ] || return 1
  printf '%s\n' "$hatch" >> "$ledger"
}

pair_force_hatch_json() { # [ledger-path]
  local ledger="${1:-${DUO_PAIR_FORCE_HATCH_LOG:-}}"
  [ -n "$ledger" ] || return 1
  [ -f "$ledger" ] && [ ! -L "$ledger" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  # Unknown bytes fail closed instead of becoming an implicitly approved new
  # hatch.  Repeated actual uses across certification legs collapse to one
  # stable manifest entry.
  if grep -vFx 'DUO_PAIR_BUDGET_OVERRIDE' "$ledger" >/dev/null; then
    return 1
  fi
  jq -Rcn '[inputs] | unique' < "$ledger"
}
