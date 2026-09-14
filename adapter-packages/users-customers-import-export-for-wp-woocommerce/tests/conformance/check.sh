#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/roundtrip.sh"
for name in 'Selected users' 'Selected users copy'; do
  stem=export-original; [ "$name" = 'Selected users' ] || stem=export-copy
  importer_roundtrip_capture "$stem-reopen" importer_roundtrip_native 2 templates-native reopen "$name"
  importer_roundtrip_capture "$stem-consume" importer_roundtrip_native 2 templates-native export "$name"
done
for name in 'Reusable input mapping' 'Reusable input copy' 'Draft input mapping'; do
  stem=import-original
  [ "$name" != 'Reusable input copy' ] || stem=import-copy
  [ "$name" != 'Draft input mapping' ] || stem=import-draft
  importer_roundtrip_capture "$stem-reopen" importer_roundtrip_native 2 import-templates-native reopen "$name"
  if [ "$stem" != import-draft ]; then
    importer_roundtrip_capture "$stem-consume" importer_roundtrip_native 2 import-templates-native consume "$name"
  fi
done
importer_roundtrip_capture consumed-capture wp_conf2 wprism capture --repo=/siterepo --format=json
php "$IMPORTER_PACKAGE_ROOT/fixtures/roundtrip-evidence.php" consumers "$IMPORTER_EVIDENCE" "$CONF_PAIR"
diff -r "$CONF_REPO1/state" "$CONF_REPO2/state" || fail 'native Importer consumption changed canonical intent'
pass 'five native templates reopen, real CSV consumers use target data, and canonical recapture remains exact'
