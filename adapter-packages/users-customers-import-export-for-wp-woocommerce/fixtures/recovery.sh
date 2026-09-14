#!/usr/bin/env bash
. "$(dirname "${BASH_SOURCE[0]}")/dirty-target.sh"

importer_recovery_fault() { # <exact shared context> <wp argv...>
  local context="$1"
  shift
  case "$context" in 'apply transaction commit'|'ledger transaction commit') ;; *) fail 'unknown recovery fault context';; esac
  "${PAIR_COMPOSE[@]}" run --rm -T -e WPRISM_TEST_MODE=1 -e "WPRISM_TEST_FAIL_DB_CONTEXT=$context" cli2 wp "$@"
}

importer_recovery_run() { # <phase> <before label> <after label> <command label> <protected argv...>
  local phase="$1" before="$2" after="$3" command="$4" started finished expected=0
  shift 4
  case "$phase" in authored-failure|ledger-failure|retry|repeat) ;; *) fail 'unknown recovery phase';; esac
  [[ "$phase" != *-failure ]] || expected=1
  started=$(date +%s)
  importer_dirty_capture "$command" "$expected" conformance_private_command cli2 apply "$@"
  finished=$(date +%s)
  importer_roundtrip_capture "$command-window" php -r 'echo json_encode(["before"=>(int)$argv[1],"after"=>(int)$argv[2]], JSON_THROW_ON_ERROR), "\n";' "$started" "$finished"
  if [ "$expected" -eq 0 ]; then
    php "$IMPORTER_PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$IMPORTER_EVIDENCE/$command" "$CONF_PAIR" apply 0
    assert_wprism_apply_ready "Importer recovery $phase" "$(cat "$IMPORTER_EVIDENCE/$command.stdout")"
  fi
  importer_dirty_snapshot "$after"
  php "$IMPORTER_PACKAGE_ROOT/fixtures/recovery-check-evidence.php" "$phase" "$IMPORTER_EVIDENCE" "$CONF_PAIR" "$before" "$after" "$command" "$recovery_revision"
}
