#!/usr/bin/env bash
# Runs LAST: native delete intentionally leaves unsupported authored absence.
# The pair owner tears down the fixture; no raw-row reinsertion or lossy re-add
# is allowed to masquerade as restoration of the original native identity.

polylang_language_deletion_mu() {
  wordpress_cron_window_compose_transport cli1 "$@"
}

polylang_language_deletion_check() (
  set -euo pipefail
  root="${WPRISM_ARTIFACT_LIBRARY_ROOT:?}"
  repo="${CONF_REPO1:?}" pair="${CONF_PAIR:?}"
  helper="$root/adapter-packages/polylang/fixtures/polylang_language_deletion_evidence.php"
  native="$root/adapter-packages/polylang/fixtures/polylang_language_deletion_native.php"
  fixture="$repo/.tmp-polylang-language-deletion.php"
  [ ! -e "$fixture" ] && [ ! -L "$fixture" ] || fail 'Polylang deletion fixture path is occupied'
  cp "$native" "$fixture"
  chmod 644 "$fixture"
  cmp "$native" "$fixture"
  read -r -a PAIR_COMPOSE <<<"${COMPOSE:?}"
  . tests/lib/private_command_capture.sh
  . tests/lib/conformance_private_command.sh
  . tests/lib/wordpress_cron_window.sh
  sink=$(umask 077; mktemp -d "$root/sandbox/tmp/polylang-language-deletion.$pair.XXXXXX")
  printf 'Polylang language-deletion private evidence: %s\n' "$sink"
  for stage in add add-check capture capture-check delete delete-check before before-tables before-database before-check \
      baseline baseline-check command after after-tables after-database private verify; do
    for suffix in stdout stderr exit; do
      (umask 077; set -C; : >"$sink/$stage.$suffix")
    done
  done
  trap 'wordpress_cron_window_exit "$?"' EXIT
  trap 'exit 130' INT TERM
  wordpress_cron_window_begin wp_conf1 polylang_language_deletion_mu
  wprism_private_capture_stage "$sink" add wp_conf1 eval-file --use-include /siterepo/.tmp-polylang-language-deletion.php add
  wprism_private_capture_stage "$sink" add-check php "$helper" control "$sink/add" "$pair" add
  [ ! -s "$sink/add-check.stdout" ] && [ ! -s "$sink/add-check.stderr" ]
  wprism_private_capture_stage "$sink" capture wp_conf1 wprism capture --repo=/siterepo --format=json
  wprism_private_capture_stage "$sink" capture-check php "$helper" capture "$sink/capture" "$pair"
  [ ! -s "$sink/capture-check.stdout" ] && [ ! -s "$sink/capture-check.stderr" ]
  wprism_private_capture_stage "$sink" delete wp_conf1 eval-file --use-include /siterepo/.tmp-polylang-language-deletion.php delete
  wprism_private_capture_stage "$sink" delete-check php "$helper" control "$sink/delete" "$pair" delete
  [ ! -s "$sink/delete-check.stdout" ] && [ ! -s "$sink/delete-check.stderr" ]
  wprism_private_capture_stage "$sink" before php "$helper" snapshot "$repo" "$sink/delete" "$pair"
  wprism_private_capture_stage "$sink" before-tables wp_conf1 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  wprism_private_capture_stage "$sink" before-database wp_conf1 db export - --single-transaction --skip-lock-tables \
    --skip-add-locks --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet
  wprism_private_capture_stage "$sink" before-check php "$helper" admit "$sink" "$pair"
  [ ! -s "$sink/before-check.stdout" ] && [ ! -s "$sink/before-check.stderr" ]
  wprism_private_capture_stage "$sink" baseline conformance_private_command_native cli1 capture snapshot
  wprism_private_capture_stage "$sink" baseline-check php "$root/sandbox/tests/lib/conformance_private_command.php" \
    validate capture "$pair" cli1 "$sink/baseline"
  [ ! -s "$sink/baseline-check.stdout" ] && [ ! -s "$sink/baseline-check.stderr" ]
  # Protected command retains the normal caller mask. Always collect every
  # postimage and private cause, even if Capture unexpectedly succeeds.
  wprism_private_capture_stage "$sink" command wp_conf1 wprism capture --repo=/siterepo --format=json || :
  observation_status=0
  wprism_private_capture_stage "$sink" after php "$helper" snapshot "$repo" "$sink/delete" "$pair" || observation_status=1
  wprism_private_capture_stage "$sink" after-tables wp_conf1 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet || observation_status=1
  wprism_private_capture_stage "$sink" after-database wp_conf1 db export - --single-transaction --skip-lock-tables \
    --skip-add-locks --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet || observation_status=1
  wprism_private_capture_stage "$sink" private conformance_private_command_native cli1 capture collect "$sink/baseline.stdout" || observation_status=1
  [ "$observation_status" -eq 0 ] || fail "Polylang deletion postimage collection failed; private evidence: $sink"
  wprism_private_capture_stage "$sink" verify php "$helper" verify "$sink" "$pair"
  [ ! -s "$sink/verify.stdout" ] && [ ! -s "$sink/verify.stderr" ]
  pass "Polylang native unused-language deletion: exact unsupported_deletion cause and complete database/state/media/policy preservation; private evidence: $sink"
)
