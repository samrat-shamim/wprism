<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$script = <<<'SH'
set -euo pipefail
root="$1" mutation="$2"
scratch=$(mktemp -d "${TMPDIR:-/tmp}/wprism-cron-window.XXXXXX")
trap 'find "$scratch" -depth -delete' EXIT
mkdir "$scratch/mu"
mu="$scratch/mu" guard="$scratch/mu/wprism-native-read-window.php"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$root/sandbox/conformance/asserts.sh"
. "$root/sandbox/tests/lib/wordpress_cron_window.sh"
case "$mutation" in
  occupied) printf 'foreign guard\n' > "$guard" ;;
  symlink) printf 'foreign guard\n' > "$scratch/foreign"; ln -s "$scratch/foreign" "$guard" ;;
esac
transport() {
  [ "$mutation:$3" != prepare-before:prepare ] || return 71
  [ "$mutation:$3" != release-fail:release ] || return 72
  (cd "$mu" && sh "$@") || return $?
  [ "$mutation:$3" != prepare-after:prepare ] || return 73
  [ "$mutation:$3" != prepare-garbage:prepare ] || printf 'unexpected answer\n'
}
wp_runner() {
  [ "$mutation" != wp-fail ] || return 74
  [ "$mutation" != wp-warning ] || printf 'PHP Warning: test in /fixture.php on line 1\n' >&2
  [ "$mutation" != wp-warning-stdout ] || printf 'Warning: incomplete native premise\n'
  [ "$mutation" != wp-warning-stderr ] || printf 'Warning: incomplete native premise\n' >&2
  case "$mutation" in
    wp-false) php -r 'define("DISABLE_WP_CRON",false); require $argv[1]; eval($argv[2]);' "$guard" "$2" ;;
    wp-wrong) printf '{"disabled":true,"owner":"wrong"}\n' ;;
    *) php -r 'require $argv[1]; eval($argv[2]);' "$guard" "$2" ;;
  esac
}
set +e
(
  set -e
  trap 'wordpress_cron_window_exit "$?"' EXIT
  trap 'exit 130' INT TERM
  wordpress_cron_window_begin wp_runner transport
  touch "$scratch/body"
  [ "$(find "$mu" -type f | wc -l | tr -d ' ')" = 1 ] || exit 75
  [ "$mutation" != nested ] || wordpress_cron_window_begin wp_runner transport
  [ "$mutation" != body-fail ] || exit 7
  [ "$mutation" != body-signal ] || sh -c 'kill -TERM "$PPID"'
  [ "$mutation" != replacement ] || printf 'foreign replacement\n' > "$guard"
  exit 0
)
status=$?
set -e
present=0 body=0
[ ! -e "$guard" ] && [ ! -L "$guard" ] || present=1
[ ! -f "$scratch/body" ] || body=1
foreign=0
case "$mutation" in
  occupied|symlink) [ "$(cat "$guard")" != 'foreign guard' ] || foreign=1 ;;
  replacement) [ "$(cat "$guard")" != 'foreign replacement' ] || foreign=1 ;;
esac
printf 'RESULT status=%s present=%s body=%s foreign=%s\n' "$status" "$present" "$body" "$foreign"
SH;
foreach ([
    'ready' => [0, 0, 1, 0], 'body-fail' => [7, 0, 1, 0],
    'body-signal' => [130, 0, 1, 0], 'nested' => [1, 0, 1, 0],
    'prepare-before' => [1, 0, 0, 0], 'prepare-after' => [1, 0, 0, 0],
    'prepare-garbage' => [1, 0, 0, 0], 'wp-fail' => [1, 0, 0, 0],
    'wp-warning' => [1, 0, 0, 0], 'wp-warning-stdout' => [1, 0, 0, 0],
    'wp-warning-stderr' => [1, 0, 0, 0], 'wp-false' => [1, 0, 0, 0], 'wp-wrong' => [1, 0, 0, 0],
    'occupied' => [1, 1, 0, 1], 'symlink' => [1, 1, 0, 1],
    'replacement' => [1, 1, 1, 1], 'release-fail' => [1, 1, 1, 0],
] as $mutation => [$expectedStatus, $present, $body, $foreign]) {
    // /bin/bash is still 3.2 on macOS even when PATH selects a current Bash.
    // Execute both; its nested-heredoc parser differs on actual remote bytes.
    foreach (['path', 'system'] as $shell) {
        [$status, $stdout, $stderr] = $shell === 'path'
            ? ShellProbe::run($script, [$root, $mutation], $root)
            : ShellProbe::run('exec /bin/bash -c "$1" shell-probe "${@:2}"', [$script, $root, $mutation], $root);
        $passed = $status === 0 && str_contains($stdout,
            "RESULT status=$expectedStatus present=$present body=$body foreign=$foreign");
        if (!$passed) {
            fwrite(STDERR, "cron-window fixture $shell/$mutation exited $status: " . substr($stdout . $stderr, 0, 2048) . "\n");
        }
        wprism_check($passed,
            "actual cron-window transport, native PHP guard, and cleanup classify $shell/$mutation");
    }
}
// The pair's CLI uid cannot create a guard in its root-owned MU parent
// (polybiok07). Reject that exact transport before any body can run; owner
// suites separately execute their callbacks through this shared transport.
$composeProbe = <<<'SH'
set -euo pipefail
root="$1" service="$2" mutation="$3"
scratch=$(mktemp -d "${TMPDIR:-/tmp}/wprism-cron-compose.XXXXXX")
trap 'find "$scratch" -depth -delete' EXIT
mkdir "$scratch/mu"
COMPOSE='cron_fixture_compose -p fixture'
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$root/sandbox/conformance/asserts.sh"
. "$root/sandbox/tests/lib/wordpress_cron_window.sh"
cron_fixture_compose() {
  local expected
  for expected in -p fixture run --rm -T --no-deps --user root \
      --workdir /var/www/html/wp-content/mu-plugins --entrypoint sh "$service"; do
    [ "${1:-}" = "$expected" ] || return 77
    shift
  done
  [ "$#" -eq 4 ] && [ "$1" = -s ] && [ "$2" = -- ] || return 78
  printf '%s\n' "$3" >>"$scratch/transport"
  (cd "$scratch/mu" && sh "$@")
}
wp_runner() {
  printf 'wp-native\n' >>"$scratch/transport"
  php -r 'require $argv[1]; eval($argv[2]);' "$scratch/mu/wprism-native-read-window.php" "$2"
}
SH;
$composeBody = <<<'SH'
set +e
(
  set -e
  trap 'wordpress_cron_window_exit "$?"' EXIT
  wordpress_cron_window_begin wp_runner owner_transport
  touch "$scratch/body"
)
status=$?
set -e
[ ! -e "$scratch/mu/wprism-native-read-window.php" ] || exit 79
if [ "$mutation" = ready ]; then
  [ "$status" -eq 0 ] && [ -f "$scratch/body" ] \
    && [ "$(cat "$scratch/transport")" = $'prepare\nwp-native\nrelease' ]
else
  [ "$status" -ne 0 ] && [ ! -e "$scratch/body" ] && [ ! -e "$scratch/transport" ]
fi
SH;
$shared = file_get_contents($root . '/sandbox/tests/lib/wordpress_cron_window.sh');
if (preg_match('/^wordpress_cron_window_compose_transport\(\).*?^\}/ms', $shared, $match) !== 1) {
    throw new RuntimeException('shared cron Compose callback is missing');
}
$unprivileged = str_replace('--user root ', '', $match[0], $removed);
if ($removed !== 1) throw new RuntimeException('cron Compose counterfactual lost its exact uid boundary');
foreach (['cli1', 'cli2'] as $service) {
    foreach (['ready', 'unprivileged'] as $mutation) {
        $script = $composeProbe . "\nowner_transport() { wordpress_cron_window_compose_transport \"\$service\" \"\$@\"; }\n"
            . ($mutation === 'ready' ? '' : $unprivileged . "\n") . $composeBody;
        foreach (['path', 'system'] as $shell) {
            [$status, $stdout, $stderr] = $shell === 'path'
                ? ShellProbe::run($script, [$root, $service, $mutation], $root)
                : ShellProbe::run('exec /bin/bash -c "$1" shell-probe "${@:2}"', [$script, $root, $service, $mutation], $root);
            wprism_check($status === 0, "actual $service Compose transport and guard lifecycle classify $shell/$mutation");
            if ($status !== 0) fwrite(STDERR, substr($stdout . $stderr, 0, 2048) . "\n");
        }
    }
}
wprism_check_summary('WordPress cron window');
