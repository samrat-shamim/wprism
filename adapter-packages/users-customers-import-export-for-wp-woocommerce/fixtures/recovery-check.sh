#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/recovery.sh"
importer_dirty_source_window
importer_roundtrip_capture recovery-source-before importer_roundtrip_observe 1
for kind in settings import export; do
  importer_roundtrip_capture "recovery-source-$kind" importer_roundtrip_native 1 dirty-target-native "$kind" source
done
importer_roundtrip_capture recovery-source-after importer_roundtrip_observe 1
php "$IMPORTER_PACKAGE_ROOT/fixtures/dirty-target-evidence.php" edited "$IMPORTER_EVIDENCE" "$CONF_PAIR" recovery-source-before recovery-source-after source
importer_dirty_capture recovery-source-capture 0 conformance_private_command cli1 capture wp_conf1 wprism capture --repo=/siterepo --format=json
php "$IMPORTER_PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$IMPORTER_EVIDENCE/recovery-source-capture" "$CONF_PAIR" capture 0
git -C "$CONF_REPO1" add -- state
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Author settings and template updates for recovery'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" fetch -q origin main
git -C "$CONF_REPO2" merge -q --ff-only origin/main
recovery_revision=$(git -C "$CONF_REPO2" rev-parse HEAD)
importer_dirty_snapshot recovery-before-plan
while IFS=$'\t' read -r table kind; do
  [ -n "$table" ] || continue
  [[ "$table" =~ ^wp_[a-z0-9_]+$ && "$kind" = 'BASE TABLE' ]] || fail 'unsafe recovery table inventory'
  importer_dirty_capture "recovery-columns-${table//_/-}" 0 wp_conf2 db query "SHOW FULL COLUMNS FROM $table" --batch --raw --skip-column-names --quiet </dev/null
done <"$IMPORTER_EVIDENCE/recovery-before-plan-tables.stdout"
importer_roundtrip_capture recovery-plan wp_conf2 wprism plan --repo=/siterepo --format=json
importer_dirty_snapshot recovery-authored-before
php "$IMPORTER_PACKAGE_ROOT/fixtures/recovery-check-evidence.php" plan "$IMPORTER_EVIDENCE" "$CONF_PAIR" recovery-before-plan recovery-authored-before recovery-plan "$recovery_revision"
importer_recovery_run authored-failure recovery-authored-before recovery-authored-after recovery-authored \
  importer_recovery_fault 'apply transaction commit' wprism apply --repo=/siterepo --revision="$recovery_revision" --default-author=admin --format=json
importer_recovery_run ledger-failure recovery-authored-after recovery-ledger-after recovery-ledger \
  importer_recovery_fault 'ledger transaction commit' wprism apply --repo=/siterepo --revision="$recovery_revision" --default-author=admin --format=json
importer_recovery_run retry recovery-ledger-after recovery-retry-after recovery-retry \
  wp_conf2 wprism apply --repo=/siterepo --revision="$recovery_revision" --default-author=admin --format=json
importer_recovery_run repeat recovery-retry-after recovery-repeat-after recovery-repeat \
  wp_conf2 wprism apply --repo=/siterepo --revision="$recovery_revision" --default-author=admin --format=json
. "$(dirname "${BASH_SOURCE[0]}")/../tests/conformance/check.sh"
pass 'Importer authored rollback, committed-state recovery, normal retry and native consumers'
