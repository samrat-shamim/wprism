#!/usr/bin/env bash
# Regression — DUO-3325: `duo adapter-draft`, the safe adapter-DRAFT generator
# (offline slice). It reuses policy-to-manifest's facts core (Policy::export_manifest)
# and adds OFFLINE proposers over a site repo's captured state/**, emitting inert
# `_draft` candidates a human ratifies by hand.
#
# The whole point is that a draft is buildable and refusable with nothing installed,
# so this suite is the same shape as regress_manifest_validate.sh: it runs the REAL
# host CLI as a subprocess against REAL fixture site-repos under a scratch directory,
# and reads the real exit codes and stdout/stderr. No docker, no sandbox pair, no
# WordPress bootstrap, nothing mocked.
#
# Covered (design suite plan 1–8): the facts/proposals/unsupported envelope and its
# acceptance through the REAL manifest-validate; the load-bearing INERTNESS proof
# (an undeclared id_kind under _draft stays ok, and un-renaming the trigger key
# makes it FAIL with the closed-vocabulary refusal); per-proposer evidence/confidence/
# questions; facts vs proposals vs unsupported; deletion required-cascade sets pinned
# against Deletion::capability; human-edit preservation (edited/ratified/drift);
# --check-proposals throwaway lift; and the guardrails (no PHP stubs, secret values
# dropped to a question, no reserved top-level key).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness, the verb, and everything it exercises)"
php -l regress_adapter_draft.php >/dev/null || fail "regress_adapter_draft.php has a syntax error"
php -l ../../cli/duo >/dev/null || fail "cli/duo has a syntax error"
php -l ../../cli/src/AdapterDraft.php >/dev/null || fail "cli/src/AdapterDraft.php has a syntax error"
php -l ../../cli/src/ManifestValidate.php >/dev/null || fail "cli/src/ManifestValidate.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/Secrets.php >/dev/null || fail "agent/src/Secrets.php has a syntax error"
pass "no syntax errors"

say "the verb is WordPress-free by construction — assert no WordPress reach in the handler or the files it adds to boot()'s load set"
# AdapterDraft::boot() loads the same engine set manifest-validate already proves
# WordPress-free (Canon/OptionState/ManifestDispositions/CapabilityRegistry/Policy),
# plus Secrets (which pulls CommandRefusal). Those two are the only additions, so the
# scan below covers the handler itself and exactly those additions. A WordPress
# function on an unguarded line in a process where it does not exist is a fatal error;
# the harness asserts clean exit-0 runs, so any real reach would already be caught,
# and this makes the intent explicit.
scan_wp() {
  awk '
    /^[ \t]*(\*|\/\/|\/\*)/ { next }   # a full-line comment is prose, not a call
    {
      if ($0 ~ /(get_bloginfo|delete_transient|get_taxonomies|get_option|is_multisite|apply_filters|add_action|add_filter|untrailingslashit|wp_[a-z_]+)[ \t]*\(|[$]wpdb|ABSPATH/) {
        if (((prev $0) ~ /function_exists\(|defined\(|is_object\([$]wpdb\)/) == 0) {
          base = FILENAME; sub(/.*\//, "", base)
          printf "  UNGUARDED %s line %d: %s\n", base, FNR, $0; bad++
        }
      }
      prev = $0
    }
    END { exit (bad > 0 ? 1 : 0) }
  ' "$@"
}
scan_wp ../../cli/src/AdapterDraft.php \
  || fail "cli/src/AdapterDraft.php reaches WordPress — the handler must be free of it"
scan_wp ../../agent/src/Secrets.php ../../agent/src/CommandRefusal.php \
  || fail "a boot()-load-set addition (Secrets/CommandRefusal) reaches WordPress"
pass "no WordPress reach in the handler or the files it adds to the load set"

say "running the offline harness (envelope, INERTNESS + mutation proof, proposers, guardrails, --check-proposals)"
php regress_adapter_draft.php || fail "regress_adapter_draft.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_ADAPTER_DRAFT PASSED\033[0m\n'
