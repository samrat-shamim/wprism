#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/roundtrip.sh"
clean_revision=$(git -C "$CONF_REPO2" rev-parse HEAD)
importer_roundtrip_capture create-repeat wp_conf2 wprism apply --repo=/siterepo --revision="$clean_revision" --default-author=admin --format=json
assert_wprism_apply_ready 'clean creation repeat' "$(cat "$IMPORTER_EVIDENCE/create-repeat.stdout")"
importer_roundtrip_capture create-stable importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" repeat "$IMPORTER_EVIDENCE" "$CONF_PAIR" after create-stable
. "$(dirname "${BASH_SOURCE[0]}")/../tests/conformance/check.sh"
importer_roundtrip_capture source-rename importer_roundtrip_native 1 templates-native rename 'Selected users'
importer_roundtrip_capture update-capture wp_conf1 wprism capture --repo=/siterepo --format=json
importer_roundtrip_capture updated-source importer_roundtrip_observe 1
git -C "$CONF_REPO1" add -- state
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Native update after clean Importer creation'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" fetch -q origin main
git -C "$CONF_REPO2" merge -q --ff-only origin/main
clean_revision=$(git -C "$CONF_REPO2" rev-parse HEAD)
importer_roundtrip_capture update-before importer_roundtrip_observe 2
importer_roundtrip_capture update-apply wp_conf2 wprism apply --repo=/siterepo --revision="$clean_revision" --default-author=admin --format=json
assert_wprism_apply_ready 'clean target native update' "$(cat "$IMPORTER_EVIDENCE/update-apply.stdout")"
importer_roundtrip_capture update-after importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" updated "$IMPORTER_EVIDENCE" "$CONF_PAIR"
importer_roundtrip_capture update-repeat wp_conf2 wprism apply --repo=/siterepo --revision="$clean_revision" --default-author=admin --format=json
assert_wprism_apply_ready 'clean update repeat' "$(cat "$IMPORTER_EVIDENCE/update-repeat.stdout")"
importer_roundtrip_capture update-stable importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" repeat "$IMPORTER_EVIDENCE" "$CONF_PAIR" update-after update-stable
importer_roundtrip_capture updated-reopen importer_roundtrip_native 2 templates-native reopen 'Renamed selection'
importer_roundtrip_capture updated-consume importer_roundtrip_native 2 templates-native export 'Renamed selection'
importer_roundtrip_capture updated-capture wp_conf2 wprism capture --repo=/siterepo --format=json
diff -r "$CONF_REPO1/state" "$CONF_REPO2/state" || fail 'clean target update/consumption changed canonical intent'
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" finished "$IMPORTER_EVIDENCE" "$CONF_PAIR"
pass 'fresh creation, native consumers, update, repeated Apply and exact recapture'
