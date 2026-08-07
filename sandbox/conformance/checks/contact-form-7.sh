#!/usr/bin/env bash
# Contact Form 7 render-level acceptance (Grind R1-A, docs/grind/r1a-forms.md):
# byte-identical canonical state alone doesn't prove the shortcode actually
# resolves on the target environment — CF7 embeds a persisted _hash, not the
# numeric post id, so this check confirms conf2's OWN rendered form carries
# conf2's OWN numeric _wpcf7 id (different from conf1's) while the captured
# shortcode text (the hash) needed no rewriting at all. Also the generic
# host-leak check every render-acceptance script in this repo runs.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; conf1/conf2's ports are set by run.sh via CONF1_PORT/CONF2_PORT
# (defaults below match the legacy docker-compose.yml conf1/conf2 ports for
# any standalone invocation).
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/conformance-contact/") || fail "conf2 conformance-contact page did not return 200"

if grep -q "localhost:${CONF1_PORT}" <<<"$FRONT"; then
    fail "conf2's rendered contact page links back to conf1 (localhost:${CONF1_PORT})"
fi

grep -q '<form action="/conformance-contact/#wpcf7-f[0-9]\+-p[0-9]\+-o1"' <<<"$FRONT" \
  || fail "CF7 form did not render its own action/unit-tag on conf2"

mapfile -t CONF2_WPCF7_IDS < <(
  grep -o '_wpcf7" value="[0-9]\+"' <<<"$FRONT" \
    | sed 's/.*value="//; s/"$//'
)
[ "${#CONF2_WPCF7_IDS[@]}" -eq 1 ] \
  || fail "expected exactly one rendered _wpcf7 numeric id, got ${#CONF2_WPCF7_IDS[@]} (${CONF2_WPCF7_IDS[*]:-none})"
CONF2_WPCF7_ID="${CONF2_WPCF7_IDS[0]}"

CONF1_WPCF7_ID=$($COMPOSE run --rm -T cli1 wp post list --post_type=wpcf7_contact_form --field=ID | head -1)
if [ "$CONF2_WPCF7_ID" = "$CONF1_WPCF7_ID" ]; then
    fail "conf2's rendered form uses conf1's numeric post id ($CONF1_WPCF7_ID) — ids should differ across environments"
fi
pass "conf2 renders its own numeric CF7 id ($CONF2_WPCF7_ID, conf1's was $CONF1_WPCF7_ID) — the captured shortcode's hash resolved correctly without any id rewriting"

# A real anonymous submission on conf2 must not touch conf1 in any way (mail-only, zero DB footprint on either side).
CONF1_POSTS_BEFORE=$($COMPOSE run --rm -T cli1 wp post list --post_type=any --format=count)
UNIT_TAG=$(grep -o '_wpcf7_unit_tag" value="[^"]*"' <<<"$FRONT" | sed 's/.*value="//;s/"//')
curl -fs -X POST "http://localhost:${CONF2_PORT}/conformance-contact/" \
  -d "_wpcf7=$CONF2_WPCF7_ID" -d "_wpcf7_version=6.1.6" -d "_wpcf7_locale=en_US" \
  --data-urlencode "_wpcf7_unit_tag=$UNIT_TAG" -d "_wpcf7_container_post=$CONF2_WPCF7_ID" -d "_wpcf7_posted_data_hash=" \
  --data-urlencode "your-name=Conformance Visitor" --data-urlencode "your-email=visitor@example.test" \
  --data-urlencode "your-subject=Conformance check" --data-urlencode "your-message=Automated conformance submission." \
  -o /dev/null || fail "anonymous CF7 submission on conf2 failed"
CONF1_POSTS_AFTER=$($COMPOSE run --rm -T cli1 wp post list --post_type=any --format=count)
[ "$CONF1_POSTS_BEFORE" = "$CONF1_POSTS_AFTER" ] \
  || fail "conf1's post count changed ($CONF1_POSTS_BEFORE -> $CONF1_POSTS_AFTER) after an anonymous submission on conf2 — runtime isolation violated"
pass "anonymous submission on conf2 succeeded and left conf1 untouched (CF7 is mail-only, zero DB footprint on either side)"
