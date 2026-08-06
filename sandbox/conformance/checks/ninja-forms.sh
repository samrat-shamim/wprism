#!/usr/bin/env bash
# Ninja Forms render-level acceptance (task #75): byte-identical canonical
# state alone doesn't prove the form actually resolves on the target — the
# nf3_forms row is a completely NEW row on conf2 (typed-snapshot create, not
# a copy), so this check confirms conf2's own rendered page carries the real
# 23-field form content (not a crash, not empty) using WHATEVER local id
# conf2 assigned it, and that Ninja Forms' own model API — the exact code
# path the front end and the submission handler both use — resolves it
# correctly server-side too.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; conf1/conf2's ports are set by run.sh via CONF1_PORT/CONF2_PORT
# (defaults below match the legacy docker-compose.yml conf1/conf2 ports for
# any standalone invocation).
set -euo pipefail
CONF2_PORT="${CONF2_PORT:-8807}"

FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/conformance-careers/") || fail "conf2 conformance-careers page did not return 200"

if grep -qi 'fatal error\|uncaught' <<<"$FRONT"; then
    fail "conf2's rendered careers page contains a PHP fatal error marker"
fi

grep -q 'Job Application\|nf-form-' <<<"$FRONT" \
  || fail "conf2's rendered careers page shows no Ninja Forms markup at all (empty/broken form block)"

grep -q 'First Name' <<<"$FRONT" \
  || fail "conf2's rendered form is missing its own field content (First Name) — form definition did not round-trip"

# The real proof: Ninja Forms' OWN model API (the exact path the front end
# and submission handler both use) resolves the form server-side on conf2,
# using CONF2's OWN local form id (never conf1's) — read back from conf2's
# own nf3_forms table directly rather than assumed.
CONF2_FORM_ID=$($COMPOSE run --rm -T cli2 wp db query \
  "SELECT id FROM wp_nf3_forms WHERE title='Job Application'" --skip-column-names)
[ -n "$CONF2_FORM_ID" ] || fail "conf2 has no 'Job Application' row in nf3_forms at all"

API_OUT=$($COMPOSE run --rm -T cli2 wp eval "
\$form = Ninja_Forms()->form($CONF2_FORM_ID)->get();
echo \$form->get_setting('title') . \"|\" . count(Ninja_Forms()->form($CONF2_FORM_ID)->get_fields()) . \"|\" . count(Ninja_Forms()->form($CONF2_FORM_ID)->get_actions());
")
[ "$API_OUT" = "Job Application|23|3" ] \
  || fail "Ninja_Forms()->form($CONF2_FORM_ID) on conf2 did not resolve correctly (got: $API_OUT, expected: Job Application|23|3)"
pass "conf2 renders its own 'Job Application' form (id=$CONF2_FORM_ID) with correct content, and Ninja Forms' own model API resolves it server-side (23 fields, 3 actions)"
