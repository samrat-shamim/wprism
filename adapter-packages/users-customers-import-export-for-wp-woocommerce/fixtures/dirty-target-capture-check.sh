#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/dirty-target.sh"
importer_roundtrip_capture identity-before-native importer_roundtrip_observe 1
importer_roundtrip_capture identity-before-map importer_roundtrip_native 1 dirty-target-native identities source
for kind in import export; do
  importer_roundtrip_capture "current-$kind" importer_roundtrip_native 1 dirty-target-native "$kind" current
done
importer_dirty_capture renamed-capture 0 conformance_private_command cli1 capture wp_conf1 wprism capture --repo=/siterepo --format=json
php "$IMPORTER_PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$IMPORTER_EVIDENCE/renamed-capture" "$CONF_PAIR" capture 0
importer_roundtrip_capture identity-after-native importer_roundtrip_observe 1
importer_roundtrip_capture identity-after-map importer_roundtrip_native 1 dirty-target-native identities source
importer_dirty_capture identity-after-state 0 php "$IMPORTER_PACKAGE_ROOT/fixtures/dependency-evidence.php" snapshot "$IMPORTER_ROOT/sandbox/$CONF_REPO1"
php "$IMPORTER_PACKAGE_ROOT/fixtures/dirty-target-evidence.php" retained "$IMPORTER_EVIDENCE" "$CONF_PAIR"
git -C "$CONF_REPO1" add -- state
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Rename captured templates while retaining their identities'
git -C "$CONF_REPO1" push -q origin main
. "$(dirname "${BASH_SOURCE[0]}")/../tests/conformance/capture-check.sh"
