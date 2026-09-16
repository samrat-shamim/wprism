#!/usr/bin/env bash
# One exact-source pair; reuse the standalone baseline rather than unrelated
# global/picker/crop sweeps. Every refusal retains complete pre/postimages.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
prior_zip="${QI_DEPENDENCY_PRIOR_ZIP:?locked local Qi 1.5.1 archive required}"
[[ "$prior_zip" = /* ]] && [ -f "$prior_zip" ] || { printf 'absolute prior Qi archive required\n' >&2; exit 1; }
[ "$(shasum -a 256 "$prior_zip" | cut -d ' ' -f 1)" = 9a9971ae6328cbcbdecb4beaabc90c61de6df5e7ba16bc523ee1dd20a1176ca2 ] \
  || { printf 'wrong locked prior Qi artifact\n' >&2; exit 1; }
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/native-apply/setup.sh"
cp "$PACKAGE_ROOT/fixtures/native-dependencies/native.php" "$R2/.tmp-qi-native/dependency-native.php"
dependency_native() { wp_side 2 --skip-plugins eval-file /siterepo/.tmp-qi-native/dependency-native.php "$@" --use-include --user=admin; }
capture_dependency() {
  local name="$1" verb="$2" expected="$3" result=0 suffix; shift 3
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq "$expected" ] || fail "native $name exited $result (expected $expected); retained $sink/$name"
  php "$PACKAGE_ROOT/fixtures/native-dependencies/evidence.php" --command "$sink/$name" "$PAIR" "$verb" "$expected"
  printf 'ok: %s\n' "$name"
}
dependency_snapshot() {
  local name="$1"
  capture_dependency "$name-tables" '' 0 wp_side 2 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  capture_dependency "$name-database" '' 0 wp_side 2 db export - --single-transaction --skip-lock-tables --skip-add-locks \
    --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet
  capture_dependency "$name-repository" '' 0 php "$PACKAGE_ROOT/fixtures/native-dependencies/evidence.php" --snapshot "$R2"
  capture_dependency "$name-files" '' 0 dependency_native snapshot
  capture_dependency "$name-code" '' 0 dependency_native observe
}
dependency_refusal() {
  local name="$1" result=0 suffix
  capture_dependency "$name-status" '' 0 dependency_native observe
  dependency_snapshot "$name-before"
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name-refusal.$suffix"); done
  wprism_private_capture_stage "$sink" "$name-refusal" candidate 2 apply --repo=/siterepo --format=json || result=$?
  # Retain the postimage even when an unexpected exit/cause invalidates admission.
  dependency_snapshot "$name-after"
  [ "$result" -eq 1 ] || fail "native $name-refusal exited $result (expected 1); retained both images in $sink"
  php "$PACKAGE_ROOT/fixtures/native-dependencies/evidence.php" --command "$sink/$name-refusal" "$PAIR" apply 1
  printf 'ok: %s-refusal\n' "$name"
}
capture deactivate '' wp_side 2 plugin deactivate qi-blocks
dependency_refusal inactive
capture reactivate '' dependency_native activate-exact
capture reactivated-apply apply candidate 2 apply --repo=/siterepo --format=json
capture delete '' wp_side 2 plugin uninstall qi-blocks --deactivate
dependency_refusal missing
capture install-prior '' "${COMPOSE[@]}" run --rm -T -v "$prior_zip:/qi-prior.zip:ro" cli2 wp plugin install /qi-prior.zip --activate
dependency_refusal prior
capture reinstall '' "${COMPOSE[@]}" run --rm -T -v "$zip:/qi-exact.zip:ro" cli2 wp plugin install /qi-exact.zip --force
capture reinstalled-status '' dependency_native observe
capture reinstalled-apply apply candidate 2 apply --repo=/siterepo --format=json
capture backup '' dependency_native backup
capture maximum-header '' dependency_native maximum-header
dependency_refusal maximum
capture maximum-restore '' dependency_native restore-header
capture unreadable-header '' dependency_native unreadable-header
dependency_refusal unreadable
capture unreadable-restore '' dependency_native restore-header
capture wrong-basename '' dependency_native wrong-basename
capture activate-wrong '' dependency_native activate-wrong
dependency_refusal wrong-basename
capture restore-basename '' dependency_native restore-basename
capture activate-exact '' dependency_native activate-exact
capture backup-cleanup '' dependency_native cleanup
capture restored-status '' dependency_native observe
capture restored-apply apply candidate 2 apply --repo=/siterepo --format=json
capture restored-repeat apply candidate 2 apply --repo=/siterepo --format=json
capture restored-native '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture restored-http '' curl --fail-with-body --silent --show-error --max-time 60 "http://localhost:$PORT2/qi-native-corpus/"
capture restored-stable '' wp_side 2 eval-file /siterepo/.tmp-qi-native/apply-native.php observe --use-include --user=admin
capture restored-capture capture candidate 2 capture --repo=/siterepo --out=/siterepo/.tmp-qi-dependency-recapture --format=json
snapshot dependency-target "$R2" "$R2/.tmp-qi-dependency-recapture"
php "$PACKAGE_ROOT/fixtures/native-dependencies/evidence.php" "$sink" "$PAIR"
php "$PACKAGE_ROOT/fixtures/native-apply/evidence.php" --admit "$sink" "$PAIR"
diff -r "$sink/source/state" "$sink/dependency-target/state" || fail 'restored dependency changed complete canonical intent'
pair_live_ownership_complete 'REGRESS_QI_NATIVE_DEPENDENCIES PASSED'
