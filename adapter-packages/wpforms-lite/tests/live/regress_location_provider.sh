#!/usr/bin/env bash
# One owned MariaDB pair: actual lower-level provider path, not Apply readiness.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
REPO_ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${WPFORMS_PROVIDER_PAIR:?unique owned pair required}"
PORT1="${WPFORMS_PROVIDER_PORT1:?even port required}"
PORT2="${WPFORMS_PROVIDER_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[[ "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]] && [ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] || fail 'wrong native evidence source'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'dirty native evidence source'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'WPForms Policy-loaded provider evidence' 'wprism-wpf-provider'
compose() { docker compose -p "wprism-$PAIR" -f pair.yml "$@"; }
capture() {
  local name="$1" status=0 suffix
  shift
  for suffix in stdout stderr exit; do (umask 077; set -C; : >"$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || status=$?
  [ "$status" -eq 0 ] || fail "native $name exited $status; retained $sink/$name"
  php -r 'require $argv[1]; WPrismTest\PrivateCommandOutput::readBytes($argv[2], "/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D");' \
    "$REPO_ROOT/sandbox/tests/lib/PrivateCommandOutput.php" "$sink/$name"
}
zip="${WPFORMS_PROVIDER_ZIP:?local locked WPForms 2.0.1.1 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 6245074790df01a6e24a42587e024132b4a28fac499d1a8fa12ebf5580e4852b ] \
  || fail 'wrong locked native plugin artifact'
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/wpforms-provider-native.XXXXXX")
printf 'Retained source-bound %s native streams: %s\n' "$EXPECTED_SHA" "$sink"
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --headless
capture cron compose run --rm -T cli1 wp config set DISABLE_WP_CRON true --raw
capture install compose run --rm -T -v "$zip:/wpforms-lite.zip:ro" cli1 wp plugin install /wpforms-lite.zip --activate
fixture=/var/www/html/wp-content/mu-plugins/adapter-packages/wpforms-lite/fixtures/location-provider/native-provider.php
capture setup compose run --rm -T cli1 wp eval-file "$fixture" setup --use-include --user=admin
capture seed compose run --rm -T cli1 wp eval-file "$fixture" seed --use-include
capture invoke compose run --rm -T cli1 wp eval-file "$fixture" invoke --use-include
capture observe compose run --rm -T cli1 wp eval-file "$fixture" observe --use-include
capture repeat compose run --rm -T cli1 wp eval-file "$fixture" invoke repeat --use-include
capture stable compose run --rm -T cli1 wp eval-file "$fixture" observe repeat --use-include
php "$PACKAGE_ROOT/fixtures/location-provider/provider-evidence.php" \
  --admit "$sink/seed" "$sink/invoke" "$sink/observe" "$sink/repeat" "$sink/stable"
pair_live_ownership_complete 'REGRESS_WPFORMS_LOCATION_PROVIDER PASSED'
