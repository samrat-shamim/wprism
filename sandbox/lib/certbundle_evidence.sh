#!/usr/bin/env bash
# Machine-evidence writers for the reference certification runner.
#
# Source-only library. The caller owns strict-shell mode and provides jq plus
# the TEST_FRAGMENTS array. This boundary only shapes result/diff fragments;
# source preflight, scenario execution, pair lifecycle, and bundle publication
# remain visible in certify_reference_bundle.sh.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  printf 'FAIL: certbundle_evidence.sh is a source-only library; source it from the certification runner or its offline regression\n' >&2
  exit 1
fi

certbundle_evidence_write_result() { # <id> <rc> <reason> <assertions-json> <path>
  local id="$1" rc="$2" reason="$3" assertions="$4" path="$5" verdict=fail
  [ "$rc" -eq 0 ] && verdict=pass
  jq -n \
    --arg test "$id" --arg verdict "$verdict" --arg reason "$reason" \
    --argjson exit_code "$rc" --argjson assertions "$assertions" \
    '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,assertions:$assertions}' > "$path"
}

certbundle_evidence_write_scoped_result() { # <id> <rc> <reason> <assertions-json> <scope> <exclusions-json> <path>
  local id="$1" rc="$2" reason="$3" assertions="$4" scope="$5" exclusions="$6" path="$7" tmp
  certbundle_evidence_write_result "$id" "$rc" "$reason" "$assertions" "$path"
  tmp="$path.tmp"
  jq --arg scope "$scope" --argjson exclusions "$exclusions" \
    '. + {scope:$scope,exclusions:$exclusions}' "$path" > "$tmp"
  mv "$tmp" "$path"
}

certbundle_evidence_write_fragment() { # <id> <manifest> <result> <diff> <fragment>
  jq -n --arg id "$1" --arg manifest "$2" --arg result "$3" --arg diff "$4" \
    '{id:$id,manifest:$manifest,result:$result,diff:$diff}' > "$5"
}

certbundle_evidence_write_skipped() { # <id> <manifest> <log> <result> <diff> <fragment>
  local id="$1" manifest="$2" log="$3" result="$4" diff="$5" fragment="$6"
  printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$log"
  certbundle_evidence_write_result "$id" 99 blocked_by_prior_failure '[]' "$result"
  jq -n --arg manifest "$manifest" \
    '{status:"unknown",manifest:$manifest,reason:"blocked_by_prior_failure"}' > "$diff"
  certbundle_evidence_write_fragment "$id" "$manifest" "$result" "$diff" "$fragment"
}

certbundle_evidence_append_fragment() { # <fragment> <log>
  local fragment="$1" log="$2"
  TEST_FRAGMENTS+=("$(jq -c --arg log "$log" '. + {log:$log} | del(.manifest)' "$fragment")")
}
