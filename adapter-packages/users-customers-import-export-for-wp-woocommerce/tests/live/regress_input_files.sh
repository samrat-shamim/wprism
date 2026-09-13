#!/usr/bin/env bash
# Actual Importer 2.7.5 local-file consumption after public Capture/env-set/Apply.
# This bounded lane checks the native file consumer, not import jobs or template qualification.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${IMPORTER_INPUTS_PAIR:?unique owned pair required}"
PORT1="${IMPORTER_INPUTS_PORT1:?even port required}"
PORT2="${IMPORTER_INPUTS_PORT2:?successor port required}"
SOURCE_SHA="$(git rev-parse HEAD)"
[ "${WPRISM_EXPECTED_SOURCE_SHA:-}" = "$SOURCE_SHA" ] || fail 'exact expected source SHA is required'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native input source must be clean'
zip="${IMPORTER_INPUTS_ZIP:?absolute locked Importer 2.7.5 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 6b7bd053960bee782e900688dac0cfed9df2519a2a0cdf72f65cf47d4b77e4a2 ] || fail 'wrong locked Importer artifact'
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_CODEBIND_PLUGIN=''
export WPRISM_WP_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
export WPRISM_CLI_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
ARTIFACTS="tmp/importer-input-transport/$PAIR/$SOURCE_SHA"
mkdir -p "$ARTIFACTS"
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'native importer input transport' 'wprism-importer-inputs'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
printf 'Source: %s; plugin: 2.7.5; zip: %s\n' "$SOURCE_SHA" "$zip" > "$ARTIFACTS/result.log"
bash tests/offline_diagnostics_guard.sh "${COMPOSE[@]}" run --rm -T -v "$zip:/importer.zip:ro" cli1 \
  wp plugin install /importer.zip --activate >> "$ARTIFACTS/result.log" 2>&1 \
  || { cat "$ARTIFACTS/result.log"; fail 'locked native plugin installation failed'; }
fixture=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
bash tests/offline_diagnostics_guard.sh "${COMPOSE[@]}" run --rm -T -v "$ROOT/sandbox/tests:/wprism-tests:ro" cli1 \
  wp --require="$fixture/admin-context.php" eval-file "$fixture/input-files-native.php" --use-include --user=admin \
  >> "$ARTIFACTS/result.log" 2>&1 || { cat "$ARTIFACTS/result.log"; fail 'native input transport regression failed'; }
cat "$ARTIFACTS/result.log"
pair_live_ownership_complete 'NATIVE_IMPORTER_INPUT_TRANSPORT_PASSED'
