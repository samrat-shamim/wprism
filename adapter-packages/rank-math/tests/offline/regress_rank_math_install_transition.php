<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$current = file_get_contents(__DIR__ . '/../certify/version-matrix.sh');
$selected = file_get_contents(($argv[1] ?? $root) . '/adapter-packages/rank-math/tests/certify/version-matrix.sh');
$definitions = '';
foreach (['rank_math_assert_release', 'rank_math_install_active_release'] as $name) {
    if (preg_match('/^' . $name . '\(\).*?^}/ms', $current, $match) !== 1) {
        throw new LogicException('the actual native transition helper is unavailable');
    }
    $definitions .= $match[0] . "\n";
}
$blocks = [];
foreach ([
    ['source upgrade', 'UPGRADE_ARTIFACT_1', 'UPGRADE_SOURCE_BASELINE_BEFORE=', ['wp1'], '1.0.277', '1.0.277.2'],
    ['target upgrade', 'UPGRADE_ARTIFACT_2', 'UPGRADE_TARGET_BASELINE_BEFORE=', ['wp2'], '1.0.277', '1.0.277.2'],
    ['source downgrade', 'DOWNGRADE_ARTIFACT_1', 'DOWNGRADE_BEFORE=', ['wp1', 'wp2'], '1.0.277.2', '1.0.277.1'],
] as [$label, $artifact, $endToken, $sites, $from, $to]) {
    $start = strpos($selected, "    rank_math_install_active_release 'Rank Math $label'");
    if ($start === false) $start = strpos($selected, '    ' . $sites[0] . ' plugin install "$' . $artifact . '"');
    $end = $start === false ? false : strpos($selected, '    ' . $endToken, $start);
    if ($start === false || $end === false) throw new LogicException('the actual upgrade/downgrade install block is unavailable');
    $blocks[] = [$label, substr($selected, $start, $end - $start), $sites, $from, $to];
}
$setup = <<<'SH'
set -euo pipefail
ROOT="$1" fixture_mode="$2" fault_site="$3" before_version="$4" after_version="$5"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
PAIR=rmtransitionprobe
scratch=$(mktemp -d "${TMPDIR:-/tmp}/rank-install-transition.XXXXXX")
trap 'test ! -f "$scratch/$fault_site" || printf "FAULT_SITE_INSTALL_ATTEMPTED\n"; rm -rf -- "$scratch"' EXIT
UPGRADE_ARTIFACT_1='/fixture/source release.zip' UPGRADE_ARTIFACT_2='/fixture/target release.zip'
DOWNGRADE_ARTIFACT_1="$UPGRADE_ARTIFACT_1" DOWNGRADE_ARTIFACT_2="$UPGRADE_ARTIFACT_2"
wp1() { fixture_wp wp1 "$@"; }
wp2() { fixture_wp wp2 "$@"; }
fixture_wp() {
  local site="$1" service version="$before_version" status=active artifact phase=before out
  shift
  case "$site" in wp1) service=cli1; artifact="$UPGRADE_ARTIFACT_1" ;; wp2) service=cli2; artifact="$UPGRADE_ARTIFACT_2" ;; *) return 81 ;; esac
  [ ! -f "$scratch/$site" ] || { version="$after_version"; phase=after; }
  if [ "$1 $2" = 'plugin install' ]; then
    [ "$3" = "$artifact" ] && [ "$4" = --force ] && { [ "$#" -eq 4 ] || { [ "$#" -eq 5 ] && [ "$5" = --activate ]; }; } || return 82
    : >"$scratch/$site"
    if [ "$site" = "$fault_site" ]; then
      case "$fixture_mode" in
        install-nonzero) printf 'Success: Installed 1 of 1 plugins.\n'; return 7 ;;
        install-empty) return 0 ;;
        install-stdout-warning) printf 'Warning: private-release-canary\n' ;;
        install-stderr-warning) printf 'Warning: private-release-canary\n' >&2 ;;
        install-php-warning) printf 'PHP Warning: private-release-canary in Unknown on line 0\n' >&2 ;;
        install-extra-success) printf 'Success: Installed 1 of 1 plugins.\n' ;;
      esac
    fi
    printf 'Unpacking the package...\nInstalling the plugin...\nRemoving the old version of the plugin...\nPlugin updated successfully.\nSuccess: Installed 1 of 1 plugins.\n'
    # Model WP-CLI's actual zero-exit --activate-on-active warning, rather
    # than making the old argv syntactically invalid in the counterfactual.
    [ "$#" -eq 4 ] || printf "Warning: Plugin 'seo-by-rank-math' is already active.\n" >&2
    return 0
  fi
  [ "$1 $2 $3" = 'plugin get seo-by-rank-math' ] || return 83
  if [ "$#" -eq 4 ] && [ "$4" = --field=version ]; then printf '%s\n' "$version"; return 0; fi
  [ "$#" -eq 5 ] && [ "$4" = --fields=name,status,version ] && [ "$5" = --format=json ] || return 84
  if [ "$site" = "$fault_site" ]; then
    case "$fixture_mode" in
      "$phase-inactive") status=inactive ;;
      "$phase-wrong-version") version=1.0.275 ;;
      "$phase-empty") return 0 ;;
      "$phase-nonzero") return 7 ;;
      "$phase-stdout-warning") printf 'PHP Warning: private-release-canary in Unknown on line 0\n' ;;
      "$phase-stderr-warning") printf 'PHP Warning: private-release-canary in Unknown on line 0\n' >&2 ;;
      "$phase-wrong-site") service=cli3 ;;
      "$phase-extra-json") printf '{}\n' ;;
    esac
  fi
  printf ' Container wprism-%s-%s-run-abcd Creating \n Container wprism-%s-%s-run-abcd Created \n' "$PAIR" "$service" "$PAIR" "$service"
  printf '{"name":"seo-by-rank-math","status":"%s","version":"%s"}\n' "$status" "$version"
}
SH;
foreach ($blocks as [$label, $block, $sites, $from, $to]) {
    foreach ($sites as $site) {
        foreach (['ready', 'before-inactive', 'before-wrong-version', 'before-empty', 'before-nonzero',
            'before-stdout-warning', 'before-stderr-warning', 'before-wrong-site', 'before-extra-json',
            'after-inactive', 'after-wrong-version', 'after-empty', 'after-nonzero',
            'after-stdout-warning', 'after-stderr-warning', 'after-wrong-site', 'after-extra-json',
            'install-nonzero', 'install-empty', 'install-stdout-warning', 'install-stderr-warning',
            'install-php-warning', 'install-extra-success'] as $mode) {
            [$code, $out, $err] = ShellProbe::run($setup . "\n" . $definitions . $block . "\nprintf 'TRANSITION_INSTALLED\\n'\n",
                [$root, $mode, $site, $from, $to], $root);
            $ready = $mode === 'ready';
            wprism_check($ready ? $code === 0 && $err === '' && str_contains($out, 'TRANSITION_INSTALLED')
                : $code !== 0 && !str_contains($out, 'TRANSITION_INSTALLED'), "$label $site $mode requires warning-free active/version transition evidence");
            wprism_check_same(!str_starts_with($mode, 'before-'), str_contains($out, 'FAULT_SITE_INSTALL_ATTEMPTED'),
                "$label $site $mode cannot install before proving the active source release");
            wprism_check(!str_contains($out . $err, 'private-release-canary'), "$label $site $mode does not publish rejected native observation bytes");
        }
    }
}
wprism_check_summary('Rank Math native active-release transitions');
