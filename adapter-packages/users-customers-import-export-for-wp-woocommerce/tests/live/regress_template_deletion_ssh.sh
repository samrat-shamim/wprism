#!/usr/bin/env bash
set -euo pipefail

# The parent owns SSH, signed recovery and verified teardown. This capsule
# supplies native template operations and full before/after semantic evidence.
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"

importer_delete_capture() { # <unique-stage> <empty|json|deploy> <argv...>
  local stage="importer-delete-$1" kind="$2" suffix status=0
  shift 2
  [[ "$stage" =~ ^[a-z][a-z0-9-]{0,80}$ ]] || fail 'unsafe Importer deletion capture label'
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$DIAG_DIR/$stage.$suffix") || fail 'Importer deletion capture collision'
  done
  wprism_private_capture_stage "$DIAG_DIR" "$stage" "$@" || status=$?
  [ "$status" -eq 0 ] || fail "Importer deletion $stage failed; inspect its private capture"
  php "$PACKAGE_ROOT/fixtures/template-deletion-evidence.php" admit "$DIAG_DIR/$stage" "$kind" \
    || fail "Importer deletion $stage has unexpected output; inspect its private capture"
  pass "$stage"
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

wprism_ssh_adopt_extension() {
  local slug=users-customers-import-export-for-wp-woocommerce fixture=/home/wprism/recovery-fixture
  local label binding index=0 history_id
  importer_delete_capture upload empty scp -F "$TMP/ssh_config" \
    "$PACKAGE_ROOT/fixtures/admin-context.php" "$PACKAGE_ROOT/fixtures/settings-native.php" \
    "$PACKAGE_ROOT/fixtures/native-settings.json" "$PACKAGE_ROOT/fixtures/templates-native.php" \
    "$PACKAGE_ROOT/fixtures/native-export-templates.json" "$PACKAGE_ROOT/fixtures/import-templates-native.php" \
    "$PACKAGE_ROOT/fixtures/native-import-templates.json" "$PACKAGE_ROOT/fixtures/template-deletion-native.php" \
    "wprism-adopt-fixture:$fixture/"
  importer_delete_capture cron empty wp_ssh_fixture config set DISABLE_WP_CRON true --raw --quiet
  importer_delete_capture install empty wprism_ssh_install_locked_plugin "$slug" 2.7.5 exercise-fixture inactive
  importer_delete_capture activate empty wp_ssh_fixture --require="$fixture/admin-context.php" --user=admin plugin activate "$slug" --quiet
  importer_delete_capture export-setup json importer_delete_native templates-native setup-source
  importer_delete_capture import-setup json importer_delete_native import-templates-native setup-source
  importer_delete_capture seed json importer_delete_native template-deletion-native seed
  importer_delete_capture history-seed json importer_delete_native templates-native export 'Selected users'
  history_id="$(jq -er '.job.history_id | select(type == "number" and . > 0 and . == floor)' "$DIAG_DIR/importer-delete-history-seed.stdout")"
  importer_delete_capture enroll empty wprism_ssh_enroll_full_recovery importer-delete
  importer_delete_capture inventory empty wprism_ssh_stage_code_inventory "$slug"
  importer_delete_capture pin json wp_ssh_fixture wprism manifest-pin --repo=/home/wprism/site --name="$slug"
  importer_delete_capture policy json importer_delete_native template-deletion-native policy <"$DIAG_DIR/importer-delete-pin.stdout"
  importer_delete_capture baseline-capture json "$WPRISM" --envs-file="$TMP/envs.json" \
    capture target --target-branch="$TARGET_REPOSITORY_BRANCH" --format=json
  assert_wprism_host_capture_ready 'Importer deletion baseline' target "$TARGET_REPOSITORY_BRANCH" "$(cat "$DIAG_DIR/importer-delete-baseline-capture.stdout")"
  importer_delete_capture baseline-commit empty ssh_fixture \
    'set -eu; git -C /home/wprism/site add -- site.wprism.json state code; git -C /home/wprism/site commit -m "Importer native deletion baseline" >/dev/null; test -z "$(git -C /home/wprism/site status --porcelain)"'
  importer_delete_capture releases empty wprism_ssh_stage_generation_releases 2
  importer_delete_capture baseline-deploy deploy "$WPRISM" --envs-file="$TMP/envs.json" deploy target
  importer_delete_capture baseline-repository json importer_delete_native template-deletion-native repository
  jq -e '.bindings | length == 2' "$DIAG_DIR/importer-delete-baseline-repository.stdout" >/dev/null \
    || fail 'Importer native original and copy must require exactly two local inputs'
  while IFS= read -r binding; do
    index=$((index + 1))
    importer_delete_capture "binding-$index" json wp_ssh_fixture wprism env-set --repo=/home/wprism/site \
      --name="$binding" --stdin --format=json <<<source-input.csv
  done < <(jq -r '.bindings | keys[]' "$DIAG_DIR/importer-delete-baseline-repository.stdout")
  importer_delete_capture baseline json importer_delete_observe
  for label in export-original import-original import-draft; do
    importer_delete_capture "native-$label" json importer_delete_native template-deletion-native delete "$label"
    jq -e --arg label "$label" --slurpfile seed "$DIAG_DIR/importer-delete-seed.stdout" \
      '.status == true and (.id | tostring) == $seed[0].selected[$label].id' \
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
  importer_delete_capture restore-fixture json importer_delete_native template-deletion-native restore-fixture <"$DIAG_DIR/importer-delete-baseline.stdout"
  importer_delete_capture restored json importer_delete_observe
  importer_delete_assert same baseline restored
  importer_delete_capture intent-commit empty ssh_fixture \
    'set -eu; git -C /home/wprism/site add -- state; git -C /home/wprism/site commit -m "Capture native Importer template deletions" >/dev/null; test -z "$(git -C /home/wprism/site status --porcelain)"'
  importer_delete_capture plan json "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json
  assert_wprism_required_environment 'Importer deletion plan' json "$(cat "$DIAG_DIR/importer-delete-plan.stdout")"
  jq -e --slurpfile repo "$DIAG_DIR/importer-delete-deletion-repository.stdout" '
    .create == [] and .update == [] and .adopt == [] and .drift == [] and .conflict == [] and .delete_conflict == []
    and .code_mismatch == [] and .provider_problems == []
    and ([.delete[].uuid] | sort) == ($repo[0].deletions | keys | sort)
    and all(.delete[]; .type == "table" and .deletion_type == "wt_iew_mapping_template" and ((.blocked // "") == ""))
  ' "$DIAG_DIR/importer-delete-plan.stdout" >/dev/null || fail 'Importer deletion plan is not exact and unblocked'
  fail 'Importer deletion signed recovery qualification is not yet complete'
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
  export WPRISM_SSH_ADOPT_EXTENSION="${BASH_SOURCE[0]}"
  export WPRISM_SSH_SUITE_LABEL=importer-template-deletion
  export WPRISM_SSH_FINAL_LABEL=REGRESS_IMPORTER_TEMPLATE_DELETION
  exec bash "$ROOT/sandbox/tests/live/regress_ssh_adopt.sh"
fi
