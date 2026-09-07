#!/usr/bin/env bash
# Capsule intent and native writers stay here; complete private transport and
# filesystem observation use the shared evidence machinery.

polylang_biography_stage() { # <repo>
  local root fixture_dir="$1/.tmp-polylang-biography" file
  root=$(cd "${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}" && pwd) || return 1
  if [ ! -e "$fixture_dir" ] && [ ! -L "$fixture_dir" ]; then
    mkdir -m 755 "$fixture_dir"
    for file in polylang_biography_values.php polylang_biography_native.php; do
      cp "$root/adapter-packages/polylang/fixtures/$file" "$fixture_dir/"
      chmod 644 "$fixture_dir/$file"
    done
  fi
  [ -d "$fixture_dir" ] && [ ! -L "$fixture_dir" ] || fail 'Polylang biography staging directory is unsafe'
  for file in polylang_biography_values.php polylang_biography_native.php; do
    [ -f "$fixture_dir/$file" ] && [ ! -L "$fixture_dir/$file" ] \
      && cmp "$root/adapter-packages/polylang/fixtures/$file" "$fixture_dir/$file" \
      || fail 'Polylang biography staged fixture differs from the candidate source'
  done
}

polylang_biography_seed() { # <source|target>
  local side="$1" root repo wp mode fixture_dir sink service pair
  root=$(cd "${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}" && pwd) || return 1
  pair="${CONF_PAIR:-${PAIR:?}}"
  case "$side" in
    source) repo="${CONF_REPO1:-siterepo/conf1}"; wp=wp_conf1; mode=seed-source; service=cli1 ;;
    target) repo="${CONF_REPO2:-siterepo/conf2}"; wp=wp_conf2; mode=seed-target; service=cli2 ;;
    *) fail 'Polylang biography seed side is invalid' ;;
  esac
  fixture_dir="$repo/.tmp-polylang-biography"
  [ ! -e "$fixture_dir" ] && [ ! -L "$fixture_dir" ] \
    || fail 'Polylang biography staging path is already occupied'
  polylang_biography_stage "$repo"
  sink=$(umask 077; mktemp -d "$root/sandbox/tmp/polylang-biography-seed.$pair.XXXXXX") || return 1
  . tests/lib/private_command_capture.sh
  (umask 077; wprism_private_capture_stage "$sink" native \
    "$wp" eval-file --use-include /siterepo/.tmp-polylang-biography/polylang_biography_native.php "$mode") \
    || fail "Polylang biography native writer failed; private evidence: $sink"
  (umask 077; wprism_private_capture_stage "$sink" admission \
    php "$root/adapter-packages/polylang/fixtures/polylang_biography_evidence.php" seed "$sink/native" "$pair" "$service" "$mode") \
    || fail "Polylang biography writer did not prove its exact single receipt; private evidence: $sink"
  [ ! -s "$sink/admission.stderr" ] || fail "Polylang biography receipt admission emitted a diagnostic; private evidence: $sink"
}

polylang_biography_check() {
  local root sink side repo wp service pair
  root=$(cd "${WPRISM_ARTIFACT_LIBRARY_ROOT:-..}" && pwd) || return 1
  pair="${CONF_PAIR:-${PAIR:?}}"
  sink=$(umask 077; mktemp -d "$root/sandbox/tmp/polylang-biography.$pair.XXXXXX") || return 1
  . tests/lib/private_command_capture.sh
  for side in source target; do
    case "$side" in
      source) repo="${CONF_REPO1:-siterepo/conf1}"; wp=wp_conf1; service=cli1 ;;
      target) repo="${CONF_REPO2:-siterepo/conf2}"; wp=wp_conf2; service=cli2 ;;
    esac
    # Version-matrix targets skip postdeploy: stage only read-only observation
    # code here, never reseed target values after the Apply being tested.
    polylang_biography_stage "$repo"
    (umask 077; wprism_private_capture_stage "$sink" "$side-native" \
      "$wp" eval-file --use-include /siterepo/.tmp-polylang-biography/polylang_biography_native.php observe) \
      || fail "Polylang biography native observation failed; private evidence: $sink"
    (umask 077; wprism_private_capture_stage "$sink" "$side" \
      php "$root/adapter-packages/polylang/fixtures/polylang_biography_evidence.php" capture \
      "$repo" "$sink/$side-native" "$pair" "$service") \
      || fail "Polylang biography host compilation or complete evidence admission failed; private evidence: $sink"
  done
  (umask 077; wprism_private_capture_stage "$sink" compare \
    php "$root/adapter-packages/polylang/fixtures/polylang_biography_evidence.php" compare "$sink/source" "$sink/target") \
    || fail "Polylang biography target-local URL rebinding failed; private evidence: $sink"
  [ ! -s "$sink/compare.stdout" ] && [ ! -s "$sink/compare.stderr" ] \
    || fail "Polylang biography comparison emitted unexpected output; private evidence: $sink"
  pass "Polylang native multilingual HTML biographies, exact URL rebinding and WordPress-free compilation; complete private evidence: $sink"
}
