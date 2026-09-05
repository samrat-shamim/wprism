#!/usr/bin/env bash
set -euo pipefail

# Core deletion success belongs to signed full promotion, not direct Apply.
# The shared parent owns the disposable SSH host, authority and final cleanup.
# This extension owns only its native witnesses and private command captures.

core_ssh_record() { # <unique-label> <command> [args...]
  local core_label="$1" core_code=0
  shift
  [[ "$core_label" =~ ^[a-z][a-z0-9-]{0,63}$ ]] || fail 'core SSH capture label is malformed'
  local core_stdout="$DIAG_DIR/core-delete-$core_label.stdout"
  local core_stderr="$DIAG_DIR/core-delete-$core_label.stderr"
  local core_exit="$DIAG_DIR/core-delete-$core_label.exit"
  local core_file
  for core_file in "$core_stdout" "$core_stderr" "$core_exit"; do
    [ ! -e "$core_file" ] && [ ! -L "$core_file" ] || fail 'core SSH capture would replace prior evidence'
    ( umask 077; set -o noclobber; : >"$core_file" ) || fail 'core SSH capture allocation failed'
  done
  # A helper may call the parent's fail()/exit. Isolate that exit so its raw
  # status is retained and the parent's cleanup trap is not redirected into
  # a command diagnostic instead of remaining the visible resource owner.
  ( "$@" ) >"$core_stdout" 2>"$core_stderr" || core_code=$?
  printf '%s\n' "$core_code" >"$core_exit"
}

core_ssh_accept() { # <OUT> <unique-label> <json|apply|refusal|human>
  local core_out="$1" core_label="$2" core_mode="$3" core_code core_json=''
  [[ "$core_out" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$core_out" != core_* ]] \
    || fail 'core SSH capture has an invalid output binding'
  [[ "$core_label" =~ ^[a-z][a-z0-9-]{0,63}$ ]] || fail 'core SSH capture label is malformed'
  local core_stdout="$DIAG_DIR/core-delete-$core_label.stdout"
  local core_stderr="$DIAG_DIR/core-delete-$core_label.stderr"
  core_code=$(<"$DIAG_DIR/core-delete-$core_label.exit")
  [[ "$core_code" =~ ^(0|[1-9][0-9]{0,2})$ ]] && [ "$core_code" -le 255 ] \
    || fail 'core SSH capture lost its exact transport exit'
  # Check both COMPLETE streams before selecting any JSON. A zero-exit PHP
  # startup warning or stderr-only env_missing must not become a clean Apply.
  assert_ssh_fixture_positive_diagnostics "core deletion $core_label" "$core_stdout" "$core_stderr"
  case "$core_mode" in
    refusal) [ "$core_code" -ne 0 ] || fail 'core FK preflight unexpectedly succeeded' ;;
    json|apply|human) [ "$core_code" -eq 0 ] || fail "core deletion $core_label failed; inspect its private capture" ;;
    *) fail 'core SSH capture mode is unknown' ;;
  esac
  if [ "$core_mode" != human ]; then
    if [ "$core_mode" = apply ] || [ "$core_mode" = refusal ]; then
      # Ordinary host promote forwards one Apply JSON object between its
      # phase receipts and terminal human line. Do not discard arbitrary
      # prefixes, additional JSON answers, or unknown action output.
      core_json="$(sed -E '/^promote (phase|profile|complete):/d' "$core_stdout" \
        | jq -ce -s 'select(length == 1 and (.[0] | type == "object")) | .[0]' 2>>"$core_stderr")" \
        || fail 'core promotion did not return one exact Apply object; inspect its private capture'
    else
      core_json="$(jq -ce -s 'select(length == 1 and (.[0] | type == "object")) | .[0]' \
        "$core_stdout" 2>>"$core_stderr")" \
        || fail "core deletion $core_label did not return one JSON object; inspect its private capture"
    fi
    if [ "$core_mode" = apply ]; then
      assert_wprism_apply_ready 'signed core deletion' "$core_json"
      [ "$(grep -Fxc 'promote complete: verified committed receipt; traffic exclusion released' "$core_stdout")" -eq 1 ] \
        || fail 'core deletion did not retain exactly one committed signed host receipt'
    elif [ "$core_mode" = refusal ]; then
      jq -e '.format == "wprism-command-refusal/v1" and .ok == false
        and .command == "apply" and .error == "apply_forced_override_failed" and .reason_code == "apply_forced_override_failed"
        and (has("details_redacted") | not)
        and .message == "apply failed after explicit plan conflict overrides were authorized"' \
        <<<"$core_json" >/dev/null 2>>"$core_stderr" \
        || fail 'core FK preflight returned the wrong public refusal category'
      ! grep -Fq 'promote complete:' "$core_stdout" \
        || fail 'core FK refusal also claimed a successful promotion'
    fi
  fi
  printf -v "$core_out" '%s' "$core_json"
}

core_ssh_capture() { # <OUT> <unique-label> <json|apply|refusal|human> <command> [args...]
  local core_binding="$1" core_capture_label="$2" core_capture_mode="$3"
  shift 3
  [[ "$core_binding" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$core_binding" != core_* ]] \
    || fail 'core SSH capture has an invalid output binding'
  case "$core_capture_mode" in json|apply|refusal|human) ;; *) fail 'core SSH capture mode is unknown' ;; esac
  core_ssh_record "$core_capture_label" "$@"
  core_ssh_accept "$core_binding" "$core_capture_label" "$core_capture_mode"
}

core_ssh_native() { # <seed|drift|observe|installForeignKey|removeForeignKey> [context JSON]
  local method="$1" context_encoded native_context="${2:-}"
  case "$method" in seed|drift|observe|installForeignKey|removeForeignKey) ;; *) fail 'core native fixture mode is unknown' ;; esac
  [ -n "$native_context" ] || native_context='{}'
  context_encoded="$(printf '%s' "$native_context" | base64 | tr -d '\r\n')"
  wp_ssh_fixture eval "require '/home/wprism/recovery-fixture/core-ssh-deletion.php'; \
    \$context=json_decode(base64_decode('$context_encoded',true),true,32,JSON_THROW_ON_ERROR); \
    \$result=\\WPrismTest\\CoreSshDeletionFixture::$method(\$context); \
    echo json_encode(\$result ?? ['complete'=>true],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);"
}

core_ssh_private_refusal() { # <snapshot|verify> <context JSON> [canonical baseline JSON]
  [ "$#" -ge 2 ] || fail 'core private receipt requires its explicit context'
  local mode="$1" baseline context_encoded code
  case "$mode" in snapshot|verify) ;; *) fail 'core private receipt mode is unknown' ;; esac
  if [ "$mode" = snapshot ]; then
    [ "$#" -eq 2 ] || fail 'core private receipt snapshot takes no baseline'
  else
    [ "$#" -eq 3 ] && [ -n "$3" ] || fail 'core private receipt verify requires its explicit baseline'
  fi
  baseline="$(printf '%s' "${3-[]}" | base64 | tr -d '\r\n')"
  context_encoded="$(printf '%s' "$2" | base64 | tr -d '\r\n')"
  code='require "/home/wprism/recovery-fixture/PrivateRefusalReceipt.php";
require "/home/wprism/recovery-fixture/core-ssh-deletion.php";
$context=json_decode(base64_decode($argv[3],true),true,32,JSON_THROW_ON_ERROR);
$profile=\WPrismTest\CoreSshDeletionFixture::refusalProfile($context);
$dir="/home/wprism/site/.wprism/refusals";
echo $argv[1] === "snapshot"
  ? \WPrismTest\PrivateRefusalReceipt::snapshot($dir,$profile)
  : \WPrismTest\PrivateRefusalReceipt::verify($dir,base64_decode($argv[2],true),$profile);'
  # No WordPress bootstrap while reading private evidence as the target uid.
  ssh_fixture "php -r '$code' '$mode' '$baseline' '$context_encoded'"
}

core_ssh_assert_fk_refusal() { # <context JSON> <public refusal JSON>
  local identity post_uuid
  post_uuid=$(jq -er '.uuids[1] | select(type == "string" and test("^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$"))' <<<"$1") \
    || fail 'core FK refusal has no explicit conflict identity'
  # Hash the UUID bytes, not jq's trailing newline, exactly as ApplyPlanner.
  identity=$(printf '%s' "$post_uuid" | shasum -a 256 | awk '{print $1}')
  jq -e --arg identity "$identity" '
    . == {
      format:"wprism-command-refusal/v1",ok:false,command:"apply",
      error:"apply_forced_override_failed",reason_code:"apply_forced_override_failed",
      message:"apply failed after explicit plan conflict overrides were authorized",
      remediation:"inspect private operator evidence and apply recovery state; reconcile the failed gate before another attempt and do not assume the authorized override committed",
      forced_overrides:[{
        format:"wprism-forced-plan-override/v1",plan_bucket:"delete_conflict",
        entity_identity_sha256:$identity,conflict_kind:"tombstone_conflict",
        reason_code:"target_changed_since_delete_base",choice:"apply_repository",
        effect:"delete_target_authored_state",required_flags:["--with-deletes","--force-theirs"],
        supplied_flags:["--with-deletes","--force-theirs"],status:"authorized"
      }]
    }
  ' <<<"$2" >/dev/null 2>&1 || fail 'core FK refusal did not name exactly the authorized local-post deletion conflict'
}

core_ssh_assert_preimage() { # <context JSON> <observed JSON>
  jq -en --argjson c "$1" --argjson before "$2" '
    def rows_for($rows; $key; $id): [$rows[] | select(.[$key] == ($id | tostring))];
    all($c.ids | to_entries[];
      . as $id | rows_for($before.posts; "ID"; $id.value) | length == 1 and .[0].post_type == $id.key)
    and ($c.revisions | length) > 0
    and all($c.revisions[]; . as $id |
      (rows_for($before.posts; "ID"; $id) | length == 1
        and .[0].post_type == "revision" and (.[0].post_parent as $parent | any($c.ids[]; tostring == $parent)))
      and (rows_for($before.postmeta; "post_id"; $id) | any(.meta_key == "_core_ssh_delete_note")))
    and any($before.children[]; .post_type == "revision" and .post_parent == ($c.ids.post | tostring))
    and ($before.children | map(.ID | tonumber) | sort) == ($c.revisions | sort)
    and all($c.ids[]; . as $id | rows_for($before.postmeta; "post_id"; $id) | length > 0)
    and (rows_for($before.relationships; "object_id"; $c.ids.post) | length) > 0
    and ($before.comments | length) == 1 and $before.comments[0].comment_ID == ($c.comment | tostring)
    and $before.comments[0].comment_post_ID == ($c.ids.page | tostring)
    and $before.comments[0].comment_content == "Runtime comment survives explicit page deletion."
    and ($before.commentmeta | length) == 1
    and $before.commentmeta[0].comment_id == ($c.comment | tostring)
    and ($before.uploads | keys) == ($c.uploads | sort)
    and ($before.uploads | length) >= 2
    and ($before.uploads | all(.[]; test("^[a-f0-9]{64}$")))
    and ($before.options | map(.option_name) | sort) == ["admin_email","home","scoped-apply_scoped_option","siteurl"]
    and ($before.map | length) == 3 and ($before.state | length) == 3
    and $before.fk == null
  ' >/dev/null 2>&1 || fail 'core deletion native preimage is incomplete or vacuous'
}

core_ssh_assert_postimage() { # <context JSON> <before JSON> <after JSON>
  jq -en --argjson c "$1" --argjson before "$2" --argjson after "$3" '
    $after.posts == [] and $after.postmeta == [] and $after.relationships == [] and $after.children == []
    and $after.comments == $before.comments and $after.commentmeta == $before.commentmeta
    and $after.options == $before.options and $after.uploads == $before.uploads
    and $after.map == [] and ($after.state | map(.uuid) | sort) == ($c.uuids | sort)
    and all($after.state[]; .entity_type == "deletion" and (.content_hash | test("^[a-f0-9]{64}$")))
    and $after.fk == null
  ' >/dev/null 2>&1 || fail 'core deletion crossed its exact native row or preserved-upload boundary'
}

core_ssh_assert_plan() { # <context JSON> <plan JSON>
  assert_wprism_required_environment 'core deletion plan' json "$2"
  jq -e --argjson c "$1" '
    (.delete | length) == 2 and (.delete_conflict | length) == 1
    and any(.delete[]; .uuid == $c.uuids[0] and .deletion_type == "page" and (.blocked | contains("comments reference")))
    and any(.delete[]; .uuid == $c.uuids[2] and .deletion_type == "attachment" and ((.blocked // "") == ""))
    and .delete_conflict[0].uuid == $c.uuids[1]
    and .delete_conflict[0].conflict_view.reason_code == "target_changed_since_delete_base"
    and .code_mismatch == [] and .provider_problems == []
    and (.env_missing | type == "array" and all(.[]; .required == false))
  ' <<<"$2" >/dev/null 2>&1 || fail 'core plan did not distinguish comment guard, local edit and attachment intent'
}

core_ssh_assert_forced() { # <context JSON> <Apply JSON>
  local post_uuid
  post_uuid="$(jq -er '.uuids[1]' <<<"$1")" || fail 'core forced-override identity is absent'
  jq -e --argjson c "$1" \
    --arg identity "$(printf '%s' "$post_uuid" | shasum -a 256 | awk '{print $1}')" '
    any(.warnings[]; contains("FORCED delete") and contains($c.uuids[0]) and contains("comments"))
    and any(.warnings[]; contains("FORCED deletion conflict " + $c.uuids[1]))
    and any(.forced_overrides[]; .format == "wprism-forced-plan-override/v1"
      and .plan_bucket == "delete_conflict" and .entity_identity_sha256 == $identity
      and .reason_code == "target_changed_since_delete_base" and .status == "authorized"
      and .required_flags == ["--with-deletes","--force-theirs"]
      and .supplied_flags == ["--with-deletes","--force-theirs"])
  ' <<<"$2" >/dev/null 2>&1 || fail 'core deletion did not name the page guard and exact authorized local-edit override'
}

core_ssh_assert_fixed_point() { # <context JSON> <plan JSON>
  assert_wprism_required_environment 'core deletion fixed point' json "$2"
  jq -e --argjson c "$1" '
    .create == [] and .update == [] and .adopt == [] and .drift == [] and .conflict == []
    and .delete == [] and .delete_conflict == [] and .code_mismatch == [] and .code_drift == []
    and .selected_actions == [] and .provider_problems == []
    and ([.deleted[] | .uuid] | sort) == ($c.uuids | sort)
    and (.env_missing | type == "array" and all(.[]; .required == false))
  ' <<<"$2" >/dev/null 2>&1 || fail 'core deletion did not reach an exact no-action fixed point'
}

core_ssh_assert_terminal() { # <committed|rolled_back> <unique-label>
  local expected="$1" label="$2" evidence exclusion ignored
  core_ssh_capture evidence "$label-authority" json ssh_fixture \
    'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php active-evidence --root=/home/wprism/site/.wprism/control'
  jq -e --arg expected "$expected" '.receipt.format == "wprism-rollback-receipt/v3"
    and .receipt.allow_deletes == true and .status.state == $expected and .status.terminal == true' \
    <<<"$evidence" >/dev/null 2>&1 || fail 'core deletion lost its exact signed terminal authority'
  core_ssh_capture exclusion "$label-exclusion" json ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json'
  jq -e '.state == "released"' <<<"$exclusion" >/dev/null 2>&1 || fail 'core deletion retained external writer exclusion'
  core_ssh_capture ignored "$label-lock" human ssh_fixture \
    'cd /var/www/html && value=$(wp db query "SELECT v FROM wp_wprism_kv WHERE k = '\''promotion_lock'\''" --skip-column-names) && test -z "$value"'
}

wprism_ssh_adopt_extension() {
  local context ignored before after plan result page_uuid post_uuid attachment_uuid
  local baseline receipt fk_before fk_after converged retry_state diagnostic

  say 'prepare exact core-only code and bounded native deletion witnesses'
  core_ssh_capture ignored enrollment human wprism_ssh_enroll_full_recovery core-delete
  core_ssh_capture ignored code-inventory human wprism_ssh_stage_code_inventory
  core_ssh_capture ignored fixture-upload human scp -F "$TMP/ssh_config" \
    "$ROOT/sandbox/tests/fixtures/core-ssh-deletion.php" \
    "$ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/
  core_ssh_capture ignored fixture-mode human ssh_fixture \
    'chmod 0600 /home/wprism/recovery-fixture/core-ssh-deletion.php /home/wprism/recovery-fixture/PrivateRefusalReceipt.php'
  core_ssh_capture ignored policy human wp_ssh_fixture eval '
    $path="/home/wprism/site/site.wprism.json";
    $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if ($data["manifests"] !== ["core"]) throw new RuntimeException("core fixture found an unexpected adapter roster");
    $data["code"]=["format"=>1,"layout"=>"wp-content","source"=>"code/wp-content"];
    $data["policy"]["post_meta"]["_core_ssh_delete_note"]=["class"=>"authored"];
    $encoded=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($path,$encoded) !== strlen($encoded)) throw new RuntimeException("core fixture policy publication failed");'
  core_ssh_capture context seed json core_ssh_native seed
  core_ssh_capture result baseline-capture json "$WPRISM" --envs-file="$TMP/envs.json" \
    capture target --target-branch="$TARGET_REPOSITORY_BRANCH" --format=json
  assert_wprism_host_capture_ready 'core baseline capture' target "$TARGET_REPOSITORY_BRANCH" "$result"
  core_ssh_capture ignored baseline-commit human ssh_fixture \
    'set -eu; git -C /home/wprism/site add -A; git -C /home/wprism/site commit -m "Core signed deletion baseline" >/dev/null; test -z "$(git -C /home/wprism/site status --porcelain)"'
  core_ssh_capture ignored release-inventory human wprism_ssh_stage_generation_releases 3
  core_ssh_capture ignored code-baseline human "$WPRISM" --envs-file="$TMP/envs.json" deploy target
  grep -q '^deploy complete:' "$DIAG_DIR/core-delete-code-baseline.stdout" \
    || fail 'core code baseline did not complete the public deploy lifecycle'
  core_ssh_capture context local-edit json core_ssh_native drift "$context"
  core_ssh_capture ignored page-tombstone human wprism_ssh_publish_post_tombstone page core-ssh-guarded-page
  page_uuid="$(cat "$DIAG_DIR/core-delete-page-tombstone.stdout")"
  core_ssh_capture ignored post-tombstone human wprism_ssh_publish_post_tombstone post core-ssh-local-post
  post_uuid="$(cat "$DIAG_DIR/core-delete-post-tombstone.stdout")"
  core_ssh_capture ignored attachment-tombstone human wprism_ssh_publish_post_tombstone attachment core-ssh-delete-image
  attachment_uuid="$(cat "$DIAG_DIR/core-delete-attachment-tombstone.stdout")"
  context="$(jq -c --arg page "$page_uuid" --arg post "$post_uuid" --arg attachment "$attachment_uuid" \
    '.uuids=[$page,$post,$attachment]' <<<"$context")"
  core_ssh_capture before preimage json core_ssh_native observe "$context"
  core_ssh_assert_preimage "$context" "$before"
  core_ssh_capture plan deletion-plan json "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json
  core_ssh_assert_plan "$context" "$plan"
  pass 'native page/comment, locally edited post/revision and attachment/upload preimages are non-vacuous'

  say 'prove real CASCADE metadata refuses before authored mutation'
  core_ssh_capture ignored fk-install json core_ssh_native installForeignKey "$context"
  core_ssh_capture fk_before fk-preimage json core_ssh_native observe "$context"
  # snapshot is a list, not a command answer; keep its raw bytes and transport
  # status private, then select that one canonical list explicitly.
  core_ssh_capture ignored refusal-baseline human core_ssh_private_refusal snapshot "$context"
  baseline="$(jq -ce -s 'select(length == 1 and (.[0] | type == "array")) | .[0]' \
    "$DIAG_DIR/core-delete-refusal-baseline.stdout" 2>>"$DIAG_DIR/core-delete-refusal-baseline.stderr")" \
    || fail 'core FK preflight could not retain its exact refusal baseline'
  core_ssh_record fk-refusal "$WPRISM" --envs-file="$TMP/envs.json" promote target \
    --with-deletes --force-theirs --force-delete-referenced --default-author=admin --format=json
  # 6a8d stopped on the public wrapper before preserving the inner graph, then
  # the parent destroyed the SSH host. Retain bounded unverified diagnostics
  # before any acceptance can exit; only the separate verifier proves cause.
  core_ssh_capture diagnostic fk-private-diagnostic json private_refusal_diagnostic capture apply "$baseline"
  jq -e '.format == "wprism-private-refusal-diagnostic/v1" and .command == "apply"
    and .purpose == "diagnostic_only" and .verified == false' <<<"$diagnostic" >/dev/null 2>&1 \
    || fail 'core FK refusal diagnostic did not retain its explicitly unverified boundary'
  core_ssh_accept result fk-refusal refusal
  core_ssh_assert_fk_refusal "$context" "$result"
  core_ssh_capture receipt fk-private-receipt json core_ssh_private_refusal verify "$context" "$baseline"
  jq -e '.format == "wprism-private-refusal-check/v1" and .command == "apply" and .new_records == 1 and .verified == true' \
    <<<"$receipt" >/dev/null 2>&1 || fail 'core FK preflight did not prove its exact fresh private cause'
  core_ssh_capture fk_after fk-postimage json core_ssh_native observe "$context"
  jq -en --argjson before "$fk_before" --argjson after "$fk_after" '$before == $after' >/dev/null 2>&1 \
    || fail 'core FK preflight changed native rows, ledgers, comments or upload bytes'
  core_ssh_assert_terminal rolled_back fk
  core_ssh_capture ignored fk-remove json core_ssh_native removeForeignKey "$context"
  core_ssh_capture after fk-removed-image json core_ssh_native observe "$context"
  jq -en --argjson before "$before" --argjson after "$after" '$before == $after' >/dev/null 2>&1 \
    || fail 'removing the owned FK fixture crossed its exact table boundary'
  pass 'exact signed FK preflight refusal preserves native state; this is not a partial-delete rollback claim'

  say 'commit exact core deletions through signed full promotion'
  core_ssh_capture result success apply "$WPRISM" --envs-file="$TMP/envs.json" promote target \
    --with-deletes --force-theirs --force-delete-referenced --default-author=admin --format=json
  core_ssh_assert_forced "$context" "$result"
  core_ssh_assert_terminal committed success
  core_ssh_capture after postimage json core_ssh_native observe "$context"
  core_ssh_assert_postimage "$context" "$before" "$after"
  core_ssh_capture converged converged-plan json "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json
  core_ssh_assert_fixed_point "$context" "$converged"
  core_ssh_capture result retry apply "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes --default-author=admin --format=json
  core_ssh_assert_terminal committed retry
  core_ssh_capture retry_state retry-postimage json core_ssh_native observe "$context"
  jq -en --argjson before "$after" --argjson after "$retry_state" '$before == $after' >/dev/null 2>&1 \
    || fail 'core deletion retry changed its fixed-point native state'
  core_ssh_capture converged retry-plan json "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json
  core_ssh_assert_fixed_point "$context" "$converged"
  pass 'signed core deletion removes exact page/post/attachment and revision-owned rows, preserves the runtime comment and upload bytes, and retries at a fixed point'
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd -P)"
  ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd -P)"
  : "${ADOPT_FIXTURE:?ADOPT_FIXTURE is required for the core SSH deletion gate}"
  : "${ADOPT_SSH_PORT:?ADOPT_SSH_PORT is required for the core SSH deletion gate}"
  : "${WPRISM_EXPECTED_SOURCE_SHA:?WPRISM_EXPECTED_SOURCE_SHA is required for the core SSH deletion gate}"
  export WPRISM_SSH_ADOPT_EXTENSION="$SCRIPT_DIR/$(basename "$0")"
  export WPRISM_SSH_SUITE_LABEL='core-ssh-deletion'
  export WPRISM_SSH_FINAL_LABEL='REGRESS_CORE_SSH_DELETION'
  exec bash "$ROOT/sandbox/tests/live/regress_ssh_adopt.sh"
fi
