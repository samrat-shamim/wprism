#!/usr/bin/env bash
# Start the target dirty: every one of the three owned sub-keys disagrees with
# the source, and an adjacent undeclared option must survive every
# reconciliation untouched.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

// Opposite on all three keys, so convergence cannot pass by coincidence on
// any single one, and authentication=any keeps the plugin enabled here too —
// the target's ENABLED state must change only because mode/eagerness did.
update_option('plsr_speculation_rules', [
    'mode' => 'prerender',
    'eagerness' => 'eager',
    'authentication' => 'any',
]);
update_option('plsr_speculation_rules_target_probe', 'target-only-neighbor');

echo wp_json_encode([
    'config' => wp_get_speculation_rules_configuration(),
    'neighbor' => get_option('plsr_speculation_rules_target_probe'),
    'stored' => plsr_get_stored_setting_value(),
]);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-speculation-rules-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-speculation-rules-hostile.php)
require_observed_nonempty "Speculative Loading hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .stored.mode == "prerender" and .stored.eagerness == "eager" and
  .stored.authentication == "any" and
  .config.mode == "prerender" and .config.eagerness == "eager" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Speculative Loading hostile target premise did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"
pass "Speculative Loading target starts with all three owned sub-keys opposed to the source plus an undeclared neighbouring option"
