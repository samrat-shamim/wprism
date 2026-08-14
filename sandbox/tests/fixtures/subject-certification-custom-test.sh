#!/usr/bin/env bash
# Executable fixture for the convention-discovered subject-certification ABI.
set -euo pipefail

: "${DUO_CERT_SUBJECT:?}"
: "${DUO_CERT_TEST_ID:?}"
: "${DUO_CERT_MANIFEST:?}"
: "${DUO_CERT_SOURCE_SHA:?}"
: "${DUO_CERT_PAIR:?}"
: "${DUO_CERT_PORT1:?}"
: "${DUO_CERT_PORT2:?}"
: "${DUO_CERT_RESULT:?}"
: "${DUO_CERT_DIFF:?}"

TEST_ID="$DUO_CERT_TEST_ID"
[ "$TEST_ID" = exact-artifact-version-matrix ]
[ "$DUO_CERT_SUBJECT" = manifests.synthetic-extension ]
[ "$DUO_CERT_MANIFEST" = synthetic-extension ]
[[ "$DUO_CERT_SOURCE_SHA" =~ ^[0-9a-f]{40}$ ]]
ARTIFACT_SLUG=$(jq -er '.plugin | split("/")[0]' "../manifests/$DUO_CERT_MANIFEST.json")
jq -e --arg slug "$ARTIFACT_SLUG" '
  .plugins[$slug]
  | ([to_entries[].value.role] | sort) == ["certified-boundary", "refusal-fixture"]
' conformance/artifacts.lock.json >/dev/null

jq -n --arg test "$TEST_ID" --arg subject "$DUO_CERT_SUBJECT" \
  '{schema_version:1,test:$test,verdict:"pass",exit_code:0,subject:$subject,
    assertions:["custom_driver_discovered","subject_artifacts_projected"]}' >"$DUO_CERT_RESULT"
jq -n --arg manifest "$DUO_CERT_MANIFEST" \
  '{status:"clean",manifest:$manifest,diffs:[],negative_controls:["typed-artifact-roles"]}' >"$DUO_CERT_DIFF"
printf '%s passed for %s on pair %s (%s/%s)\n' \
  "$TEST_ID" "$DUO_CERT_SUBJECT" "$DUO_CERT_PAIR" "$DUO_CERT_PORT1" "$DUO_CERT_PORT2"
