#!/usr/bin/env bash
# Build a hostile target after deploy: both owned settings disagree with the
# source, and an adjacent undeclared option must survive every reconciliation.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
update_option('classic-editor-replace', Classic_Editor::validate_option_editor('block'));
update_option('classic-editor-allow-users', Classic_Editor::validate_option_allow_users('disallow'));
update_option('classic-editor-target-runtime-probe', 'target-only-neighbor');
echo wp_json_encode([
    'allow' => get_option('classic-editor-allow-users'),
    'neighbor' => get_option('classic-editor-target-runtime-probe'),
    'replace' => get_option('classic-editor-replace'),
]);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-classic-editor-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-classic-editor-hostile.php)
require_observed_nonempty "Classic Editor hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .replace == "block" and .allow == "disallow" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Classic Editor hostile target premise did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"
pass "Classic Editor target starts with opposite owned settings plus undeclared neighboring state"
