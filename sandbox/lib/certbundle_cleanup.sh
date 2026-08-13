#!/usr/bin/env bash
# Cleanup and owned-pair teardown helpers for reference-bundle certification.
# This file is intentionally source-only: it depends on runner-owned variables
# and on certbundle_lock_release from certbundle_lock.sh.

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  printf 'FAIL: certbundle_cleanup.sh is a source-only library; source it from a certification runner or its offline regression\n' >&2
  exit 1
fi

certbundle_cleanup_run() {
  case "$WORK_ROOT" in
    /tmp/duo-certbundle.*)
      rm -rf -- "$WORK_ROOT" \
        || printf 'WARNING: could not remove the work root %s; remove it by hand\n' "$WORK_ROOT" >&2
      ;;
    *)
      printf 'refusing unsafe work cleanup path: %s\n' "$WORK_ROOT" >&2
      ;;
  esac
  certbundle_lock_release
}

certbundle_destroy_own_pair() {
  local rc
  set +e
  bash bin/pair.sh destroy "$PAIR"
  rc=$?
  set -e
  return "$rc"
}
