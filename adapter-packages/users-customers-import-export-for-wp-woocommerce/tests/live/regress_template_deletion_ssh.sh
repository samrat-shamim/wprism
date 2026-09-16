#!/usr/bin/env bash
set -euo pipefail

# The parent owns SSH, signed rollback authority and verified teardown. This
# lane derives tombstones from native Delete, proves direct Apply stays closed,
# then exercises automatic signed promotion through exclusion-loss rollback,
# retry and a no-action fixed point.
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"

importer_delete_record() { # <unique-stage> <argv...>; intentional refusals are admitted separately
  local stage="importer-delete-$1" suffix
  shift
  [[ "$stage" =~ ^[a-z][a-z0-9-]{0,80}$ ]] || fail 'unsafe Importer deletion capture label'
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$DIAG_DIR/$stage.$suffix") || fail 'Importer deletion capture collision'
  done
  wprism_private_capture_stage "$DIAG_DIR" "$stage" "$@" || :
}

importer_delete_admit() { # <stage> <expected-output-kind>
  php "$PACKAGE_ROOT/fixtures/template-deletion-evidence.php" admit "$DIAG_DIR/importer-delete-$1" "$2" \
    || fail "Importer deletion $1 has unexpected output; inspect its private capture"
  pass "importer-delete-$1"
}

importer_delete_capture() { # <unique-stage> <expected-output-kind> <argv...>
  local label="$1" kind="$2"
  shift 2
  importer_delete_record "$label" "$@"
  importer_delete_admit "$label" "$kind"
}

importer_delete_native() { # <fixture-name> <phase> [args...]
  local fixture="$1"
  shift
  wp_ssh_fixture --require=/home/wprism/recovery-fixture/admin-context.php --user=admin \
    eval-file "/home/wprism/recovery-fixture/$fixture.php" "$@" --use-include
}

importer_delete_observe() {
  wp_ssh_fixture --skip-plugins --user=admin eval-file \
    /home/wprism/recovery-fixture/template-deletion-native.php observe --use-include
}

importer_delete_assert() { # <semantic-mode> <capture-label>...
  local mode="$1" label
  shift
  local inputs=()
  for label in "$@"; do inputs+=("$DIAG_DIR/importer-delete-$label"); done
  php "$PACKAGE_ROOT/fixtures/template-deletion-evidence.php" "$mode" "${inputs[@]}"
}

importer_delete_assert_converged_plan() { # <capture-label>
  local label="$1" plan="$DIAG_DIR/importer-delete-$1.stdout"
  assert_wprism_required_environment 'Importer deletion fixed point' json "$(cat "$plan")"
  jq -e --slurpfile repo "$DIAG_DIR/importer-delete-deletion-repository.stdout" '
    .create == [] and .update == [] and .adopt == [] and .drift == [] and .conflict == []
    and .collision == [] and .delete == [] and .delete_conflict == []
    and .code_mismatch == [] and .code_drift == [] and .provider_problems == [] and .warnings == []
    and .incomplete_apply == [] and .incomplete_lifecycle == [] and .missing_user == []
    and .skipped_user_meta == [] and .adapter_dispositions == [] and .selected_actions == []
    and .regen_pending == [] and .regen_context == []
    and (.env_missing | type == "array" and all(.[]; .required == false))
    and ([.deleted[].uuid] | sort) == ($repo[0].deletions | keys | sort)
    and all(.deleted[]; . as $row |
      $row.type == "wt_iew_mapping_template" and $row.deletion_kind == "table"
      and $row.deletion_type == "wt_iew_mapping_template"
      and $row.receipt_hash == ($repo[0].deletions[$row.uuid].hash // ""))
  ' "$plan" >/dev/null || fail 'Importer signed deletion did not converge to an exact no-action plan'
}

wprism_ssh_adopt_extension() {
  local slug=users-customers-import-export-for-wp-woocommerce fixture=/home/wprism/recovery-fixture
  local label binding index=0 history_id failure_code retry_code repeat_code status_json
  local failure_stdout="$DIAG_DIR/importer-delete-signed-failure.stdout"
  local failure_stderr="$DIAG_DIR/importer-delete-signed-failure.stderr"
  local retry_stdout="$DIAG_DIR/importer-delete-signed-retry.stdout"
  local retry_stderr="$DIAG_DIR/importer-delete-signed-retry.stderr"
  local repeat_stdout="$DIAG_DIR/importer-delete-signed-repeat.stdout"
  local repeat_stderr="$DIAG_DIR/importer-delete-signed-repeat.stderr"
  for diagnostic_file in "$failure_stdout" "$failure_stderr" "$retry_stdout" "$retry_stderr" "$repeat_stdout" "$repeat_stderr"; do
    (umask 077; : >"$diagnostic_file")
    chmod 0600 "$diagnostic_file"
  done
  wprism_ssh_enroll_full_recovery importer-deletion
  importer_delete_capture upload empty scp -F "$TMP/ssh_config" \
    "$PACKAGE_ROOT/fixtures/admin-context.php" "$PACKAGE_ROOT/fixtures/settings-native.php" \
    "$PACKAGE_ROOT/fixtures/native-settings.json" "$PACKAGE_ROOT/fixtures/templates-native.php" \
    "$PACKAGE_ROOT/fixtures/native-export-templates.json" "$PACKAGE_ROOT/fixtures/import-templates-native.php" \
    "$PACKAGE_ROOT/fixtures/native-import-templates.json" "$PACKAGE_ROOT/fixtures/template-deletion-native.php" \
    "wprism-adopt-fixture:$fixture/"
  importer_delete_capture cron empty wp_ssh_fixture config set DISABLE_WP_CRON true --raw --quiet
  importer_delete_capture install empty wprism_ssh_install_locked_plugin "$slug" 2.7.5 certified-boundary inactive
  importer_delete_capture activate empty wp_ssh_fixture --require="$fixture/admin-context.php" --user=admin plugin activate "$slug" --quiet
  importer_delete_capture export-setup json importer_delete_native templates-native setup-source
  importer_delete_capture import-setup json importer_delete_native import-templates-native setup-source
  importer_delete_capture seed json importer_delete_native template-deletion-native seed
  importer_delete_capture history-seed json importer_delete_native templates-native export 'Selected users'
  history_id="$(jq -er '.job.history_id | select(type == "number" and . > 0 and . == floor)' "$DIAG_DIR/importer-delete-history-seed.stdout")"
  importer_delete_capture pin json wp_ssh_fixture wprism manifest-pin --repo=/home/wprism/site --name="$slug"
  importer_delete_capture policy json importer_delete_native template-deletion-native policy <"$DIAG_DIR/importer-delete-pin.stdout"
  importer_delete_capture baseline-agent-diagnostic json wp_ssh_fixture wprism capture --repo=/home/wprism/site --format=json
  importer_delete_capture baseline-capture json "$WPRISM" --envs-file="$TMP/envs.json" \
    capture target --target-branch="$TARGET_REPOSITORY_BRANCH" --format=json
  importer_delete_assert initial-capture baseline-agent-diagnostic baseline-capture
  importer_delete_capture baseline-commit empty ssh_fixture \
    'set -eu; git -C /home/wprism/site add -- site.wprism.json state; git -C /home/wprism/site commit -m "Importer native deletion baseline" >/dev/null; test -z "$(git -C /home/wprism/site status --porcelain)"'
  importer_delete_capture baseline-deploy json wp_ssh_fixture wprism deploy --repo=/home/wprism/site --force-code-drift --format=json
  importer_delete_assert reconcile baseline-agent-diagnostic baseline-deploy
  importer_delete_capture settled-capture json "$WPRISM" --envs-file="$TMP/envs.json" \
    capture target --target-branch="$TARGET_REPOSITORY_BRANCH" --format=json
  assert_wprism_host_capture_ready 'Importer reconciled baseline' target "$TARGET_REPOSITORY_BRANCH" "$(cat "$DIAG_DIR/importer-delete-settled-capture.stdout")"
  importer_delete_capture baseline-repository json importer_delete_native template-deletion-native repository
  jq -e '.bindings | length == 2' "$DIAG_DIR/importer-delete-baseline-repository.stdout" >/dev/null \
    || fail 'Importer native original and copy must require exactly two local inputs'
  while IFS= read -r binding; do
    index=$((index + 1))
    importer_delete_capture "binding-$index" json wp_ssh_fixture wprism env-set --repo=/home/wprism/site \
      --name="$binding" --stdin --format=json <<<source-input.csv
  done < <(jq -r '.bindings | keys[]' "$DIAG_DIR/importer-delete-baseline-repository.stdout")
  wprism_ssh_stage_code_inventory "$slug"
  importer_delete_capture code-commit empty ssh_fixture '
    set -eu
    php -r '\''
      $path = "/home/wprism/site/site.wprism.json";
      $site = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
      $site["code"] = ["format" => 1, "layout" => "wp-content", "source" => "code/wp-content"];
      file_put_contents($path, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    '\''
    git -C /home/wprism/site add -- site.wprism.json code
    git -C /home/wprism/site commit -m "Bind exact Importer deletion code release" >/dev/null
    test -z "$(git -C /home/wprism/site status --porcelain)"
  '
  wprism_ssh_stage_generation_releases 3
  importer_delete_record code-baseline "$WPRISM" --envs-file="$TMP/envs.json" deploy target
  [ "$(cat "$DIAG_DIR/importer-delete-code-baseline.exit")" = 0 ] \
    || fail 'Importer signed deletion did not establish its exact host code baseline'
  assert_ssh_fixture_positive_diagnostics 'Importer signed deletion code baseline' \
    "$DIAG_DIR/importer-delete-code-baseline.stdout" "$DIAG_DIR/importer-delete-code-baseline.stderr"
  grep -Fq 'deploy complete:' "$DIAG_DIR/importer-delete-code-baseline.stdout" \
    || fail 'Importer signed deletion host deploy lacked its terminal product result'
  importer_delete_capture baseline json importer_delete_observe
  importer_delete_capture baseline-ledger json importer_delete_native template-deletion-native identity-ledger
  for label in export-original import-original import-draft; do
    importer_delete_capture "native-$label" json importer_delete_native template-deletion-native delete "$label"
    jq -e --arg label "$label" --slurpfile seed "$DIAG_DIR/importer-delete-seed.stdout" \
      '.status == true and (.template_id | tostring) == $seed[0].selected[$label].id' \
      "$DIAG_DIR/importer-delete-native-$label.stdout" >/dev/null || fail 'native Delete returned the wrong identity'
  done
  importer_delete_capture native-deleted json importer_delete_observe
  importer_delete_assert removed baseline native-deleted
  importer_delete_capture export-copy json importer_delete_native templates-native reopen 'Selected users copy'
  importer_delete_capture import-copy json importer_delete_native import-templates-native reopen 'Reusable input copy'
  importer_delete_capture history-copy json importer_delete_native template-deletion-native history-reopen "$history_id"
  php "$PACKAGE_ROOT/fixtures/template-deletion-evidence.php" reopened "$DIAG_DIR/importer-delete-baseline" \
    "$DIAG_DIR/importer-delete-export-copy" "$DIAG_DIR/importer-delete-import-copy" "$DIAG_DIR/importer-delete-history-copy" "$history_id"
  importer_delete_capture deletion-capture json "$WPRISM" --envs-file="$TMP/envs.json" \
    capture target --target-branch="$TARGET_REPOSITORY_BRANCH" --format=json
  assert_wprism_host_capture_ready 'Importer native deletion capture' target "$TARGET_REPOSITORY_BRANCH" "$(cat "$DIAG_DIR/importer-delete-deletion-capture.stdout")"
  importer_delete_capture deletion-repository json importer_delete_native template-deletion-native repository
  importer_delete_assert tombstones baseline-repository deletion-repository
  importer_delete_capture repeated-capture json "$WPRISM" --envs-file="$TMP/envs.json" \
    capture target --target-branch="$TARGET_REPOSITORY_BRANCH" --format=json
  importer_delete_capture repeated-repository json importer_delete_native template-deletion-native repository
  importer_delete_assert same deletion-repository repeated-repository
  jq -cn --slurpfile native "$DIAG_DIR/importer-delete-baseline.stdout" \
    --slurpfile ledger "$DIAG_DIR/importer-delete-baseline-ledger.stdout" \
    '{native:$native[0],ledger:$ledger[0]}' \
    | importer_delete_capture restore-fixture json importer_delete_native template-deletion-native restore-fixture
  importer_delete_capture restored json importer_delete_observe
  importer_delete_assert same baseline restored
  importer_delete_capture restored-ledger json importer_delete_native template-deletion-native observe-ledger \
    <"$DIAG_DIR/importer-delete-baseline-ledger.stdout"
  importer_delete_assert ledger-preimage baseline-ledger restored-ledger baseline
  importer_delete_capture intent-commit empty ssh_fixture \
    'set -eu; git -C /home/wprism/site add -- state; git -C /home/wprism/site commit -m "Capture native Importer template deletions" >/dev/null; test -z "$(git -C /home/wprism/site status --porcelain)"'
  importer_delete_capture plan json "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json
  assert_wprism_required_environment 'Importer deletion plan' json "$(cat "$DIAG_DIR/importer-delete-plan.stdout")"
  jq -e --slurpfile repo "$DIAG_DIR/importer-delete-deletion-repository.stdout" '
    .create == [] and .update == [] and .adopt == [] and .drift == [] and .conflict == [] and .delete_conflict == []
    and .code_mismatch == [] and .code_drift == [] and .provider_problems == [] and .warnings == []
    and .collision == [] and .incomplete_apply == [] and .incomplete_lifecycle == [] and .missing_user == []
    and .adapter_dispositions == []
    and ([.delete[].uuid] | sort) == ($repo[0].deletions | keys | sort)
    and all(.delete[]; .type == "wt_iew_mapping_template" and .deletion_kind == "table" and .deletion_type == "wt_iew_mapping_template" and ((.blocked // "") == ""))
  ' "$DIAG_DIR/importer-delete-plan.stdout" >/dev/null || fail 'Importer deletion plan has unexpected entities or blockers'
  importer_delete_capture direct-baseline list private_refusal_diagnostic snapshot apply
  importer_delete_record direct wp_ssh_fixture wprism apply --repo=/home/wprism/site --with-deletes --format=json
  importer_delete_capture direct-diagnostic json private_refusal_diagnostic capture apply "$(cat "$DIAG_DIR/importer-delete-direct-baseline.stdout")"
  importer_delete_admit direct direct
  php "$PACKAGE_ROOT/fixtures/template-deletion-evidence.php" private-refusal "$DIAG_DIR/importer-delete-direct-diagnostic" direct
  importer_delete_capture direct-preserved json importer_delete_observe
  importer_delete_assert same baseline direct-preserved

  importer_delete_capture direct-repository json importer_delete_native template-deletion-native repository
  importer_delete_assert same deletion-repository direct-repository
  importer_delete_capture direct-ledger json importer_delete_native template-deletion-native observe-ledger \
    <"$DIAG_DIR/importer-delete-baseline-ledger.stdout"
  importer_delete_assert same baseline-ledger direct-ledger
  [ -z "$(target_ledger_value promotion_lock)" ] || fail 'Importer refused direct deletion retained its target lock'

  # Recovery preparation/claim, lifecycle, upload and Apply preflight consume
  # through verify 20 at executable-owner binding. The three rows then consume
  # verifies 21-23 in authorize_next_delete(); verify 24 is therefore the final
  # external-exclusion operation before this transaction's database COMMIT.
  ssh_fixture 'printf "24\n" > /home/wprism/recovery-fixture/provider-state.json.fail-verify-after && chmod 600 /home/wprism/recovery-fixture/provider-state.json.fail-verify-after'
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes >"$failure_stdout" 2>"$failure_stderr"; then
    failure_code=0
  else
    failure_code=$?
  fi
  [ "$failure_code" -ne 0 ] || fail 'Importer signed deletion committed after external exclusion disappeared'
  assert_ssh_fixture_positive_diagnostics 'Importer signed deletion rollback' "$failure_stdout" "$failure_stderr"
  grep -Fq 'delete commit boundary' "$failure_stdout" "$failure_stderr" \
    || fail 'Importer signed deletion did not fail at the final pre-COMMIT exclusion frontier'
  grep -Eq 'prior world verified; rollback generation [0-9]+ is rolled_back and exclusion is released' \
    "$failure_stdout" "$failure_stderr" \
    || fail 'Importer signed deletion did not report complete checkpoint rollback'
  ssh_fixture 'test ! -e /home/wprism/recovery-fixture/provider-state.json.fail-verify-after' \
    || fail 'Importer signed deletion did not consume its one-use exclusion fault'
  status_json="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php active-evidence --root=/home/wprism/site/.wprism/control')"
  jq -e '.receipt.format == "wprism-rollback-receipt/v3" and .receipt.allow_deletes == true
    and .status.state == "rolled_back" and .status.terminal == true' <<<"$status_json" >/dev/null \
    || fail 'Importer signed deletion rollback lost its exact deletion authority'
  importer_delete_capture rollback-preserved json importer_delete_observe
  importer_delete_assert same baseline rollback-preserved
  importer_delete_capture rollback-repository json importer_delete_native template-deletion-native repository
  importer_delete_assert same deletion-repository rollback-repository
  importer_delete_capture rollback-ledger json importer_delete_native template-deletion-native observe-ledger \
    <"$DIAG_DIR/importer-delete-baseline-ledger.stdout"
  importer_delete_assert same baseline-ledger rollback-ledger
  [ -z "$(target_ledger_value promotion_lock)" ] || fail 'Importer signed deletion rollback retained its target lock'
  jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
    || fail 'Importer signed deletion rollback retained external writer exclusion'

  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes >"$retry_stdout" 2>"$retry_stderr"; then
    retry_code=0
  else
    retry_code=$?
  fi
  [ "$retry_code" -eq 0 ] || fail 'Importer signed deletion retry failed after verified rollback'
  assert_ssh_fixture_positive_diagnostics 'Importer signed deletion retry' "$retry_stdout" "$retry_stderr"
  grep -Fq 'promote complete: verified committed receipt; traffic exclusion released' "$retry_stdout" \
    || fail 'Importer signed deletion retry lacked its committed recovery receipt'
  importer_delete_capture signed-removed json importer_delete_observe
  importer_delete_assert removed baseline signed-removed
  importer_delete_capture signed-ledger json importer_delete_native template-deletion-native observe-ledger \
    <"$DIAG_DIR/importer-delete-baseline-ledger.stdout"
  importer_delete_assert ledger-terminal baseline-ledger signed-ledger plan
  importer_delete_capture signed-export-copy json importer_delete_native templates-native reopen 'Selected users copy'
  importer_delete_capture signed-import-copy json importer_delete_native import-templates-native reopen 'Reusable input copy'
  importer_delete_capture signed-history-copy json importer_delete_native template-deletion-native history-reopen "$history_id"
  php "$PACKAGE_ROOT/fixtures/template-deletion-evidence.php" reopened "$DIAG_DIR/importer-delete-baseline" \
    "$DIAG_DIR/importer-delete-signed-export-copy" "$DIAG_DIR/importer-delete-signed-import-copy" \
    "$DIAG_DIR/importer-delete-signed-history-copy" "$history_id"
  importer_delete_capture converged-plan json "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json
  importer_delete_assert_converged_plan converged-plan
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes >"$repeat_stdout" 2>"$repeat_stderr"; then
    repeat_code=0
  else
    repeat_code=$?
  fi
  [ "$repeat_code" -eq 0 ] || fail 'Importer signed deletion repeat failed at its fixed point'
  assert_ssh_fixture_positive_diagnostics 'Importer signed deletion fixed point' "$repeat_stdout" "$repeat_stderr"
  importer_delete_capture repeat-stable json importer_delete_observe
  importer_delete_assert same signed-removed repeat-stable
  importer_delete_capture repeat-ledger json importer_delete_native template-deletion-native observe-ledger \
    <"$DIAG_DIR/importer-delete-baseline-ledger.stdout"
  importer_delete_assert same signed-ledger repeat-ledger
  [ -z "$(target_ledger_value promotion_lock)" ] || fail 'Importer signed deletion fixed point retained its target lock'
  jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
    || fail 'Importer signed deletion fixed point retained external writer exclusion'
  pass 'native tombstones, direct refusal, signed rollback, successful retry, surviving consumers and fixed point'

}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
  export WPRISM_SSH_ADOPT_EXTENSION="${BASH_SOURCE[0]}"
  export WPRISM_SSH_SUITE_LABEL=importer-template-deletion
  export WPRISM_SSH_FINAL_LABEL=REGRESS_IMPORTER_TEMPLATE_DELETION
  exec bash "$ROOT/sandbox/tests/live/regress_ssh_adopt.sh"
fi
