#!/usr/bin/env bash
# Source-only conformance seed. The plugin's own WP-CLI settings command is
# used so this fixture does not prove the manifest by directly writing rows.
set -euo pipefail

SEED_OUT=$(wp_conf1 disable-comments settings --types=all --xmlrpc --rest-api)
require_observed_nonempty "Disable Comments native source seed" "$SEED_OUT"
printf '%s\n' "$SEED_OUT"
