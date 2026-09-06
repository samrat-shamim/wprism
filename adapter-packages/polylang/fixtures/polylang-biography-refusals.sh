#!/usr/bin/env bash
# Deliberate fixture faults use normal product commands. The shared reader
# owns freshness and complete cause graphs; the capsule declares exact intent.

polylang_biography_refusal_stage() { # <private sink> <stage> <argv...>
  local sink="$1" stage="$2" suffix
  shift 2
  # Evidence is private, but the protected command keeps the caller's umask.
  # A host-side repository edit must remain readable by the native CLI uid.
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : >"$sink/$stage.$suffix") || return 1
  done
  wprism_private_capture_stage "$sink" "$stage" "$@"
}

polylang_biography_refusal_private() { # <service> <snapshot|collect> <profile JSON> [baseline stdout]
  local service="$1" mode="$2" profile="$3" root
  local -a compose_argv
  root=$(cd "${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}" && pwd) || return 1
  read -r -a compose_argv <<<"${COMPOSE:?}"
  # This reader runs as the site's CLI uid outside WordPress, not as host
  # root or through an eval that could mutate the site during observation.
  "${compose_argv[@]}" run --rm -T \
    --volume "$root/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
    --entrypoint php "$service" -r '
require "/wprism-test/PrivateRefusalReceipt.php";
$profile = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
if ($argv[1] === "snapshot") {
    echo json_encode(["baseline" => \WPrismTest\PrivateRefusalReceipt::snapshot("/siterepo/.wprism/refusals", $profile)], JSON_THROW_ON_ERROR), "\n";
} elseif ($argv[1] === "collect") {
    $input = stream_get_contents(STDIN, 1048577);
    if (!is_string($input) || strlen($input) > 1048576) throw new RuntimeException("biography private baseline exceeds its transport bound");
    $baseline = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($baseline) || array_keys($baseline) !== ["baseline"] || !is_string($baseline["baseline"])) {
        throw new RuntimeException("biography private baseline is not its exact envelope");
    }
    echo \WPrismTest\PrivateRefusalReceipt::collect("/siterepo/.wprism/refusals", $baseline["baseline"], $profile), "\n";
} else throw new RuntimeException("biography private operation is unsupported");
' "$mode" "$profile" <"${4:-/dev/null}"
}

polylang_biography_refusal_observe() { # <sink> <stage> <repo> <wp> <service> <pair> <boundary> <control>
  local sink="$1" stage="$2" repo="$3" wp="$4" service="$5" pair="$6" boundary="$7" control="$8" helper
  helper="${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}/adapter-packages/polylang/fixtures/polylang_biography_refusals.php"
  polylang_biography_refusal_stage "$sink" "$stage-native" "$wp" eval-file \
    /siterepo/.tmp-polylang-biography/polylang_biography_native.php observe \
    && polylang_biography_refusal_stage "$sink" "$stage" php "$helper" snapshot \
      "$repo" "$sink/$stage-native" "$pair" "$service" "$boundary" "$control" \
    && [ ! -s "$sink/$stage.stderr" ] \
    && polylang_biography_refusal_stage "$sink" "$stage-admit" php "$helper" admit-snapshot "$sink/$stage" "$boundary" "$control" \
    && [ ! -s "$sink/$stage-admit.stdout" ] && [ ! -s "$sink/$stage-admit.stderr" ]
}

polylang_biography_refusal_command() { # <sink> <repo> <wp> <service> <pair> <boundary> <command> <control>
  local sink="$1" repo="$2" wp="$3" service="$4" pair="$5" boundary="$6" command="$7" control="$8"
  local helper profile after_status=0 private_status=0
  helper="${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}/adapter-packages/polylang/fixtures/polylang_biography_refusals.php"
  profile=$(php "$helper" profile "$boundary" "$command") || return 1
  polylang_biography_refusal_observe "$sink" before "$repo" "$wp" "$service" "$pair" "$boundary" "$control" \
    || return 1
  polylang_biography_refusal_stage "$sink" baseline polylang_biography_refusal_private "$service" snapshot "$profile" \
    && polylang_biography_refusal_stage "$sink" baseline-check php "$helper" baseline \
      "$sink/baseline" "$pair" "$service" "$boundary" "$command" \
    && [ ! -s "$sink/baseline-check.stdout" ] && [ ! -s "$sink/baseline-check.stderr" ] || return 1
  # Always retain both post-command observations and the exact private cause,
  # even if the command unexpectedly succeeds or the first observer fails.
  polylang_biography_refusal_stage "$sink" command "$wp" wprism "$command" --repo=/siterepo --format=json || :
  polylang_biography_refusal_observe "$sink" after "$repo" "$wp" "$service" "$pair" "$boundary" "$control" || after_status=$?
  polylang_biography_refusal_stage "$sink" private polylang_biography_refusal_private \
    "$service" collect "$profile" "$sink/baseline.stdout" || private_status=$?
  [ "$after_status" -eq 0 ] && [ "$private_status" -eq 0 ] || return 1
  polylang_biography_refusal_stage "$sink" verify php "$helper" verify "$sink" "$pair" "$service" "$boundary" "$command" "$control" \
    && [ ! -s "$sink/verify.stdout" ] && [ ! -s "$sink/verify.stderr" ]
}

polylang_biography_refusal_control() { # <sink> <mode> <wp> <service> <pair> <control>
  local sink="$1" mode="$2" wp="$3" service="$4" pair="$5" control="$6" helper
  helper="${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}/adapter-packages/polylang/fixtures/polylang_biography_refusals.php"
  polylang_biography_refusal_stage "$sink" "$mode" "$wp" eval-file \
    /siterepo/.tmp-polylang-biography/polylang_biography_native.php "$mode" "$control" \
    && polylang_biography_refusal_stage "$sink" "$mode-check" php "$helper" native-control \
      "$sink/$mode" "$pair" "$service" "$mode" "$control" \
    && [ ! -s "$sink/$mode-check.stdout" ] && [ ! -s "$sink/$mode-check.stderr" ]
}

polylang_biography_refusals_check() {
  local root pair helper control lane repo wp service boundary commands sink command
  root=$(cd "${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}" && pwd) || return 1
  pair="${CONF_PAIR:-${PAIR:?}}"
  helper="$root/adapter-packages/polylang/fixtures/polylang_biography_refusals.php"
  . tests/lib/private_command_capture.sh
  for control in script event-handler javascript-uri data-uri unknown-entity; do
    for lane in source-existing target-existing target-desired; do
      case "$lane" in
        source-existing) repo="${CONF_REPO1:-siterepo/conf1}"; wp=wp_conf1; service=cli1; boundary=existing; commands=capture ;;
        target-existing) repo="${CONF_REPO2:-siterepo/conf2}"; wp=wp_conf2; service=cli2; boundary=existing; commands='plan apply' ;;
        target-desired) repo="${CONF_REPO2:-siterepo/conf2}"; wp=wp_conf2; service=cli2; boundary=desired; commands='plan apply' ;;
      esac
      sink=$(umask 077; mktemp -d "$root/sandbox/tmp/polylang-biography-refusal.$pair.$lane.$control.XXXXXX") || return 1
      polylang_biography_refusal_observe "$sink" safe-before "$repo" "$wp" "$service" "$pair" safe safe \
        || fail "Polylang biography safe preimage failed; private evidence: $sink"
      if [ "$boundary" = existing ]; then
        polylang_biography_refusal_control "$sink" corrupt "$wp" "$service" "$pair" "$control" \
          || fail "Polylang biography native fault injection failed; private evidence: $sink"
      else
        polylang_biography_refusal_stage "$sink" corrupt php "$helper" corrupt "$repo" "$control" \
          && [ ! -s "$sink/corrupt.stderr" ] || fail "Polylang biography canonical fault injection failed; private evidence: $sink"
      fi
      for command in $commands; do
        mkdir -m 700 "$sink/$command" || return 1
        polylang_biography_refusal_command "$sink/$command" "$repo" "$wp" "$service" "$pair" "$boundary" "$command" "$control" \
          || fail "Polylang biography $lane $control $command refusal or exact preservation failed; private evidence: $sink"
      done
      if [ "$boundary" = existing ]; then
        polylang_biography_refusal_control "$sink" restore "$wp" "$service" "$pair" "$control" \
          || fail "Polylang biography native fixture restoration failed; private evidence: $sink"
      else
        polylang_biography_refusal_stage "$sink" restore php "$helper" restore "$repo" "$control" "$sink/corrupt" \
          && [ ! -s "$sink/restore.stderr" ] || fail "Polylang biography canonical fixture restoration failed; private evidence: $sink"
      fi
      polylang_biography_refusal_observe "$sink" safe-after "$repo" "$wp" "$service" "$pair" safe safe \
        && polylang_biography_refusal_stage "$sink" restored php "$helper" same "$sink/safe-before" "$sink/safe-after" safe safe \
        && [ ! -s "$sink/restored.stdout" ] && [ ! -s "$sink/restored.stderr" ] \
        || fail "Polylang biography complete fixture restoration was not exact; private evidence: $sink"
      pass "Polylang biography $lane $control: $commands refused with exact causes and complete state/user preservation; private evidence: $sink"
    done
  done
}
