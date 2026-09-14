#!/usr/bin/env bash
# Author a core post and the endpoint switches through Disable Comments' own
# WP-CLI writer, not by directly writing the option the manifest declares.
set -euo pipefail

POST_ID=$(wp_conf1 post create \
  --post_type=post \
  --post_status=publish \
  --post_title='Disable Comments endpoint fixture' \
  --post_name=disable-comments-endpoint-fixture \
  --post_content='Endpoint behavior fixture.' \
  --porcelain)
require_observed_nonempty "Disable Comments native post seed" "$POST_ID"
SEED_OUT=$(wp_conf1 disable-comments settings --xmlrpc --rest-api)
require_observed_nonempty "Disable Comments native source seed" "$SEED_OUT"
OPTIONS=$(wp_conf1 option get disable_comments_options --format=json)
require_observed_nonempty "Disable Comments native option readback" "$OPTIONS"
printf '%s\n' "$OPTIONS" | jq -e '
  ((.remove_xmlrpc_comments == true) or (.remove_xmlrpc_comments == 1) or (.remove_xmlrpc_comments == "1")) and
  ((.remove_rest_API_comments == true) or (.remove_rest_API_comments == 1) or (.remove_rest_API_comments == "1"))
' >/dev/null || fail "Disable Comments native endpoint settings did not persist: $OPTIONS"
printf '%s\n' "$OPTIONS"
