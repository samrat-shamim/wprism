#!/usr/bin/env bash
# Regression — issue #3285: closes a real, historical aliveness gap discovered
# building this issue's own regress-offline-all bundle, not invented ahead
# of a need. OptionsCapture's option_name_refs (task #93)
# discovery loop shipped a real defect twice: issue #3205's refactor
# (0f28ef8) introduced `$allOptionValues = $this->all_options_map()` as
# the new complete option-name/value source but left the option_name_refs
# consumer loop iterating the removed/never-defined `$liveOptionNames` --
# an undefined-variable PHP WARNING, not a fatal error, so `wp wprism capture`
# kept exiting 0 while silently skipping every option_name_refs rule (see
# manifests/woocommerce.json's own woocommerce_<method>_<instance>_settings
# rows, which this exact mechanism exists to discover). Filed as issue #3286,
# fixed incidentally as a one-line change inside 713fc56 (issue #3223,
# unrelated WooCommerce-adapter-boundary work) -- but issue #3286 itself was
# never closed, and no offline test exists that would have caught either
# the original break OR a future regression of the same shape, because
# OptionsCapture needs live WordPress/$wpdb to actually RUN
# end-to-end -- the same "PHP harness can prove the method's OWN logic but
# not that the real call site is wired correctly" gap regress_capture_
# secret_scan.sh's own header already documents and closes for
# guard_secret(). This is that same closure for option_name_ref_rules().
#
# Verified LIVE, not assumed: reverted 713fc56's own one-line fix locally
# (foreach (array_keys($allOptionValues) ...) -> foreach ($liveOptionNames
# ...)) and confirmed regress-offline-all's full 31-suite bundle stayed
# GREEN -- nothing in the existing offline corpus notices this class of
# bug. This check is what makes that no longer true, verified against a
# synthetic corrupted copy below before being trusted for a real run
# (never against the real source file -- this script only ever reads it).
#
# Pure PHP source-text scan, no docker, no WordPress bootstrap, no PHP
# execution of Capture.php at all: does not parse PHP or match brace
# structure, just fixed small line-windows around named call sites,
# located by literal text -- the same discipline and the same limitation
# regress_capture_secret_scan.sh's own header states for itself.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

CAPTURE_SRC=../../../../agent/src/Capture/OptionsCapture.php
[ -f "$CAPTURE_SRC" ] || fail "expected $CAPTURE_SRC to exist"

check_wiring() { # check_wiring <path> <label> -- exits 0 (wired correctly) or 1 (broken), never fail()s itself
  local src="$1" label="$2"
  local start end
  start=$(grep -n '^    public function capture(' "$src" | head -1 | cut -d: -f1) || return 2
  end=$(awk -v s="$start" 'NR>s && /^    (private|public) function/ {print NR; exit}' "$src")
  [ -n "$start" ] && [ -n "$end" ] || return 2
  local body
  body=$(sed -n "${start},${end}p" "$src")
  grep -qE '\$allOptionValues\s*=\s*\$this->all_options_map\(\);' <<<"$body" \
    || { echo "  [$label] no \$allOptionValues = \$this->all_options_map() assignment found in OptionsCapture" >&2; return 1; }
  local loop_line
  loop_line=$(echo "$body" | grep -n 'option_name_ref_match_details' | head -1 | cut -d: -f1)
  [ -n "$loop_line" ] || { echo "  [$label] option_name_ref_match_details() call site not found in OptionsCapture" >&2; return 1; }
  local window
  # The engine-owned work-unit closure sits between this exact consumer loop
  # and its classifier; the nearby authored-options loop remains out of range.
  window=$(echo "$body" | sed -n "$((loop_line - 4)),$((loop_line + 5))p")
  grep -qE 'foreach\s*\(\s*array_keys\(\$allOptionValues\)\s*as\s*\$name\s*\)' <<<"$window" \
    || { echo "  [$label] option_name_ref_match_details() consumer loop does not iterate array_keys(\$allOptionValues) -- got:
$window" >&2; return 1; }
  return 0
}

say "self-test: a synthetic copy carrying the EXACT historical defect (issue #3286's own \$liveOptionNames) must fail this check"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
# Corrupts ONLY the specific option_name_refs consumer loop this check
# guards -- found the identical way check_wiring() itself locates it
# (relative to the option_name_ref_match_details() call site, never by a bare
# text substitution that could also hit OptionsCapture's OTHER,
# unrelated foreach (array_keys($allOptionValues) ...) loop a few dozen
# lines earlier for the ordinary authored-options scan). Matches 713fc56's
# own real, isolated one-line diff exactly -- Python for precise,
# line-number-targeted surgery rather than a fragile occurrence-counting
# sed/awk chain.
python3 - "$CAPTURE_SRC" "$TMP/Capture_broken.php" <<'PYEOF'
import re, sys
src, dst = sys.argv[1], sys.argv[2]
lines = open(src, encoding='utf-8').readlines()
call_idx = next(i for i, l in enumerate(lines) if 'option_name_ref_match_details' in l)
for i in range(max(0, call_idx - 4), min(call_idx + 2, len(lines))):
    if 'foreach (array_keys($allOptionValues) as $name) {' in lines[i]:
        lines[i] = lines[i].replace(
            'foreach (array_keys($allOptionValues) as $name) {',
            'foreach ($liveOptionNames as $name) {',
        )
        break
else:
    sys.exit('could not locate the option_name_refs consumer loop to corrupt')
open(dst, 'w', encoding='utf-8').writelines(lines)
PYEOF
if check_wiring "$TMP/Capture_broken.php" "synthetic-broken"; then
  fail "self-test failed: the synthetic copy carrying issue #3286's exact historical defect (\$liveOptionNames) was NOT flagged -- this check's own detection logic is broken, do not trust the real-file result below"
fi
pass "self-test: synthetic copy carrying the historical \$liveOptionNames defect correctly FAILS this check"

say "real check: agent/src/Capture/OptionsCapture.php's option_name_refs (task #93) discovery loop"
if check_wiring "$CAPTURE_SRC" "real"; then
  pass "OptionsCapture correctly iterates array_keys(\$allOptionValues) for option_name_ref_match_details() -- the issue #3286 class of defect is not present"
else
  fail "agent/src/Capture/OptionsCapture.php's option_name_refs consumer loop does not iterate array_keys(\$allOptionValues) -- see output above; this is the exact issue #3286/713fc56 defect shape, real this time"
fi

printf '\n\033[1;32m✔ REGRESS_OPTION_NAME_REFS_WIRING PASSED\033[0m\n'
