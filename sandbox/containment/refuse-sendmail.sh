#!/bin/sh
# A local capture witness is useful to a rehearsal operator, but success would
# tell WordPress that a message was delivered. Retain only a digest in the
# lease-owned container and return EX_TEMPFAIL so every caller sees refusal.
set -eu

capture="${TMPDIR:-/tmp}/wprism-mail-capture.ndjson"
message="$(mktemp "${TMPDIR:-/tmp}/wprism-mail.XXXXXX")"
trap 'rm -f "$message"' EXIT INT TERM
cat > "$message"
digest="$(sha256sum "$message" | awk '{print $1}')"
printf '{"message_sha256":"%s","status":"refused"}\n' "$digest" >> "$capture"
exit 75
