#!/usr/bin/env bash
. "$(dirname "${BASH_SOURCE[0]}")/dirty-target.sh"

importer_recovery_fault() { # <exact shared context> <wp argv...>
  local context="$1"
  shift
  case "$context" in 'apply transaction commit'|'ledger transaction commit') ;; *) fail 'unknown recovery fault context';; esac
  if [ "${IMPORTER_RECOVERY_FAULT_MODE:-throw}" = kill ]; then
    local result=0
    "${PAIR_COMPOSE[@]}" run --name "${IMPORTER_RECOVERY_CONTAINER:?owned crash container required}" -T \
      -e WPRISM_TEST_MODE=1 -e "WPRISM_TEST_FAIL_DB_CONTEXT=$context" -e WPRISM_TEST_DB_FAULT_MODE=kill -e WPRISM_TEST_PROMOTION_TTL=20 cli2 wp "$@" || result=$?
    # A retained stopped oneoff triggers Compose orphan warnings in the next
    # diagnostic reader. Capture its identity, then remove it before that reader.
    importer_dirty_capture "${IMPORTER_RECOVERY_STAGE:?}-process" 0 docker inspect --type container --format '{{json .}}' "$IMPORTER_RECOVERY_CONTAINER"
    importer_dirty_capture "$IMPORTER_RECOVERY_STAGE-removed" 0 docker rm "$IMPORTER_RECOVERY_CONTAINER"
    return "$result"
  else
    "${PAIR_COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e "WPRISM_TEST_FAIL_DB_CONTEXT=$context" cli2 wp "$@"
  fi
}

importer_recovery_run() { # <phase> <before label> <after label> <command label> <protected argv...>
  local phase="$1" before="$2" after="$3" command="$4" started finished expected=0
  shift 4
  case "$phase" in authored-failure|ledger-failure|retry|repeat) ;; *) fail 'unknown recovery phase';; esac
  local mode="${IMPORTER_RECOVERY_FAULT_MODE:-throw}" IMPORTER_RECOVERY_CONTAINER='' IMPORTER_RECOVERY_STAGE="$command"
  case "$mode" in throw|kill) ;; *) fail 'unknown recovery fault mode';; esac
  if [[ "$phase" = *-failure ]]; then
    expected=1
    if [ "$mode" = kill ]; then
      expected=137
      IMPORTER_RECOVERY_CONTAINER="wprism-$CONF_PAIR-cli2-run-$(php -r 'echo bin2hex(random_bytes(6));')"
      importer_roundtrip_capture "$command-container" php -r 'echo json_encode(["name"=>$argv[1]], JSON_THROW_ON_ERROR), "\n";' "$IMPORTER_RECOVERY_CONTAINER"
    fi
  fi
  started=$(date +%s)
  importer_dirty_capture "$command" "$expected" conformance_private_command cli2 apply "$@"
  finished=$(date +%s)
  importer_roundtrip_capture "$command-window" php -r 'echo json_encode(["before"=>(int)$argv[1],"after"=>(int)$argv[2]], JSON_THROW_ON_ERROR), "\n";' "$started" "$finished"
  if [ "$expected" -eq 0 ]; then
    php "$IMPORTER_PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$IMPORTER_EVIDENCE/$command" "$CONF_PAIR" apply 0
    assert_wprism_apply_ready "Importer recovery $phase" "$(cat "$IMPORTER_EVIDENCE/$command.stdout")"
  fi
  importer_dirty_snapshot "$after"
  php "$IMPORTER_PACKAGE_ROOT/fixtures/recovery-check-evidence.php" "$phase" "$IMPORTER_EVIDENCE" "$CONF_PAIR" "$before" "$after" "$command" "$recovery_revision" "$mode"
  if [ "$expected" -eq 137 ]; then
    php "$IMPORTER_PACKAGE_ROOT/fixtures/recovery-crash-evidence.php" removed "$IMPORTER_EVIDENCE" "$command"
    local deadline
    deadline=$(php "$IMPORTER_PACKAGE_ROOT/fixtures/recovery-crash-evidence.php" deadline "$IMPORTER_EVIDENCE" "$after" "$CONF_PAIR")
    while [ "$(date +%s)" -le "$deadline" ]; do sleep 1; done
  fi
}
