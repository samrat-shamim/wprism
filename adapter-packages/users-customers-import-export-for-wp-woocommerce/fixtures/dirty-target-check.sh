#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/dirty-target.sh"
importer_roundtrip_capture dirty-target-before importer_roundtrip_observe 2
for kind in settings import export; do
  importer_roundtrip_capture "dirty-target-$kind" importer_roundtrip_native 2 dirty-target-native "$kind" target
done
importer_roundtrip_capture dirty-target-after importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/dirty-target-evidence.php" edited "$IMPORTER_EVIDENCE" "$CONF_PAIR" dirty-target-before dirty-target-after target
importer_dirty_refusal drift
importer_roundtrip_capture dirty-source-before importer_roundtrip_observe 1
for kind in settings import export; do
  importer_roundtrip_capture "dirty-source-$kind" importer_roundtrip_native 1 dirty-target-native "$kind" source
done
importer_roundtrip_capture dirty-source-after importer_roundtrip_observe 1
php "$IMPORTER_PACKAGE_ROOT/fixtures/dirty-target-evidence.php" edited "$IMPORTER_EVIDENCE" "$CONF_PAIR" dirty-source-before dirty-source-after source
importer_dirty_capture dirty-source-capture 0 conformance_private_command cli1 capture wp_conf1 wprism capture --repo=/siterepo --format=json
php "$IMPORTER_PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$IMPORTER_EVIDENCE/dirty-source-capture" "$CONF_PAIR" capture 0
git -C "$CONF_REPO1" add -- state
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Independent source settings and template changes'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" fetch -q origin main
git -C "$CONF_REPO2" merge -q --ff-only origin/main
importer_dirty_refusal conflict
dirty_revision=$(git -C "$CONF_REPO2" rev-parse HEAD)
importer_roundtrip_capture resolved-apply wp_conf2 wprism apply --repo=/siterepo --revision="$dirty_revision" --force-theirs --default-author=admin --format=json
assert_wprism_apply_ready 'explicit three-way conflict resolution' "$(cat "$IMPORTER_EVIDENCE/resolved-apply.stdout")"
importer_roundtrip_capture resolved-native importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/dirty-target-evidence.php" resolved "$IMPORTER_EVIDENCE" "$CONF_PAIR"
importer_roundtrip_capture resolved-repeat wp_conf2 wprism apply --repo=/siterepo --revision="$dirty_revision" --default-author=admin --format=json
assert_wprism_apply_ready 'resolved target repeat' "$(cat "$IMPORTER_EVIDENCE/resolved-repeat.stdout")"
importer_roundtrip_capture resolved-stable importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" repeat "$IMPORTER_EVIDENCE" "$CONF_PAIR" resolved-native resolved-stable
. "$(dirname "${BASH_SOURCE[0]}")/../tests/conformance/check.sh"
pass 'dirty target collision, drift, conflict, explicit resolution, native consumers and exact recapture'
