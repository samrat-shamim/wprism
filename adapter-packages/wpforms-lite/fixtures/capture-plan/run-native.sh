#!/usr/bin/env bash
# Shared transport admission owns stdout/stderr/status. This capsule owns the
# native writer/consumer and its independently checked observation schema.
wpforms_capture_native() (
  set -euo pipefail
  local phase="$1" hook_root repo scratch executable status=0
  case "$phase" in seed|observe) ;; *) return 64 ;; esac
  hook_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
  repo="$(cd "${CONF_REPO1:?}" && pwd -P)"
  scratch=$(umask 077; mktemp -d "${WPRISM_ARTIFACT_LIBRARY_ROOT:?}/sandbox/tmp/wprism-wpforms-${CONF_PAIR:?}-$phase.XXXXXX")
  executable=$(mktemp "$repo/.tmp-wpforms-native.XXXXXX.php")
  trap 'status=$?; rm -f "$executable" || exit 1; exit "$status"' EXIT
  cp "$hook_root/native.php" "$executable"
  chmod 0644 "$executable"
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$scratch/native.$suffix")
  done
  wp_conf1 eval-file "/siterepo/${executable##*/}" "$phase" --use-include --user=admin \
    >"$scratch/native.stdout" 2>"$scratch/native.stderr" || status=$?
  printf '%s\n' "$status" >"$scratch/native.exit"
  printf 'WPForms private native observation: %s\n' "$scratch/native" >&2
  php "$hook_root/verify.php" "$scratch/native" "$CONF_PAIR" "$phase" "$repo" "${CONF1_PORT:?}"
)
