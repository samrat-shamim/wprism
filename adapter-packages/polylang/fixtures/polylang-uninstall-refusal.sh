#!/usr/bin/env bash
# Native complete-uninstall precedes this window. Database recovery follows
# only after the ordinary Plan proves its exact refusal and preservation.

polylang_uninstall_mu() {
  "${PAIR_COMPOSE[@]}" run --rm -T --workdir /var/www/html/wp-content/mu-plugins --entrypoint sh cli2 "$@"
}

polylang_uninstall_refusal_check() (
  set -euo pipefail
  root="${WPRISM_ARTIFACT_LIBRARY_ROOT:?}" repo="${CONF_REPO2:?}" pair="${CONF_PAIR:?}"
  helper="$root/adapter-packages/polylang/fixtures/polylang_uninstall_evidence.php"
  read -r -a PAIR_COMPOSE <<<"${COMPOSE:?}"
  . tests/lib/private_command_capture.sh
  . tests/lib/conformance_private_command.sh
  . tests/lib/wordpress_cron_window.sh
  sink=$(umask 077; mktemp -d "$root/sandbox/tmp/polylang-uninstall-refusal.$pair.XXXXXX")
  printf 'Polylang uninstall private evidence: %s\n' "$sink"
  for stage in before before-tables before-database before-check baseline baseline-check command \
      after after-tables after-database private verify; do
    for suffix in stdout stderr exit; do
      (umask 077; set -C; : >"$sink/$stage.$suffix")
    done
  done
  trap 'wordpress_cron_window_exit "$?"' EXIT
  trap 'exit 130' INT TERM
  wordpress_cron_window_begin wp_conf2 polylang_uninstall_mu
  wprism_private_capture_stage "$sink" before php "$helper" snapshot "$repo"
  wprism_private_capture_stage "$sink" before-tables wp_conf2 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  wprism_private_capture_stage "$sink" before-database wp_conf2 db export - --single-transaction --skip-lock-tables \
    --skip-add-locks --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet
  wprism_private_capture_stage "$sink" before-check php "$helper" admit "$sink" "$pair"
  [ ! -s "$sink/before-check.stdout" ] && [ ! -s "$sink/before-check.stderr" ]
  wprism_private_capture_stage "$sink" baseline conformance_private_command_native cli2 plan snapshot
  wprism_private_capture_stage "$sink" baseline-check php "$root/sandbox/tests/lib/conformance_private_command.php" \
    validate plan "$pair" cli2 "$sink/baseline"
  [ ! -s "$sink/baseline-check.stdout" ] && [ ! -s "$sink/baseline-check.stderr" ]
  # Always retain postimages and the fresh private cause before interpreting
  # the command's verdict. An unexpected success cannot discard its mutation.
  wprism_private_capture_stage "$sink" command wp_conf2 wprism plan --repo=/siterepo --format=json || :
  observation_status=0
  wprism_private_capture_stage "$sink" after php "$helper" snapshot "$repo" || observation_status=1
  wprism_private_capture_stage "$sink" after-tables wp_conf2 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet || observation_status=1
  wprism_private_capture_stage "$sink" after-database wp_conf2 db export - --single-transaction --skip-lock-tables \
    --skip-add-locks --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet || observation_status=1
  wprism_private_capture_stage "$sink" private conformance_private_command_native cli2 plan collect "$sink/baseline.stdout" || observation_status=1
  [ "$observation_status" -eq 0 ] || fail "Polylang uninstall postimage collection failed; private evidence: $sink"
  wprism_private_capture_stage "$sink" verify php "$helper" verify "$sink" "$pair"
  [ ! -s "$sink/verify.stdout" ] && [ ! -s "$sink/verify.stderr" ]
  pass "Polylang destructive uninstall: exact canonical identity recovery refusal and complete database/state/media/policy preservation; private evidence: $sink"
)
