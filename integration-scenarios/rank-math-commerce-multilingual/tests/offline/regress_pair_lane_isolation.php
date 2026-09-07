<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$live = (string) file_get_contents(dirname(__DIR__) . '/live/regress_rank_math_commerce_multilingual.sh');
$start = strpos($live, 'pair_live_ownership_acquire mariadb');
if ($start === false) throw new RuntimeException('actual four-lane dispatcher is absent');
$dispatch = substr($live, $start);
$tmp = sys_get_temp_dir() . '/wprism-combination-lanes-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
$remove = static function (string $target) use (&$remove): void {
    if (!is_dir($target) || is_link($target)) {
        if (file_exists($target) || is_link($target)) unlink($target);
        return;
    }
    foreach (new FilesystemIterator($target, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($target);
};
register_shutdown_function(static fn() => $remove($tmp));

// Only pair.sh's external resource boundary is simulated. The actual caller
// and shared ownership state machine run unmodified, including EXIT cleanup.
// reset's documented DB/repository-only semantics deliberately leave webroot
// artifacts behind: native rmcombofinal02 lost their metadata before lane two.
$boundary = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
command_name="$1"
shift
printf '%s\n' "$command_name" >> "$TEST_CASE/events"
occurrence=$(awk -v command="$command_name" '$0 == command {n++} END {print n+0}' "$TEST_CASE/events")
fault() {
  [ "$TEST_FAULT" != "$command_name:$occurrence" ] || { printf 'injected %s\n' "$TEST_FAULT" >&2; exit 71; }
}
owned() {
  [ -s "$TEST_CASE/lease" ] && [ "$(<"$TEST_CASE/lease")" = "${WPRISM_PAIR_LEASE_TOKEN:-}" ]
}
absent() {
  [ ! -e "$TEST_CASE/resources" ] && [ ! -e siterepo/laneprobe1 ] \
    && [ ! -e siterepo/laneprobe2 ] && [ ! -e siterepo/origin-laneprobe.git ]
}
case "$command_name" in
  lease-batch-acquire)
    fault
    [ "$#" -eq 6 ] && [ "$4" = laneprobe ] && [ "$5" = 9520 ] && [ "$6" = 9521 ]
    [ ! -e "$TEST_CASE/lease" ] && absent
    printf '%s\n' "$1" > "$TEST_CASE/lease"
    ;;
  up)
    owned
    [ "$*" = 'laneprobe 9520 9521 --artifacts --headless' ]
    mkdir -p "$TEST_CASE/resources/webroot" "$TEST_CASE/resources/database" \
      siterepo/laneprobe1 siterepo/laneprobe2 siterepo/origin-laneprobe.git
    # A failure here models resources created before WordPress bootstrap.
    fault
    ;;
  reset)
    owned
    find "$TEST_CASE/resources/database" siterepo/laneprobe1 siterepo/laneprobe2 \
      -mindepth 1 -depth -delete
    find siterepo/origin-laneprobe.git -depth -delete
    ;;
  destroy)
    owned
    fault
    if [ "$TEST_FAULT" = 'survivor:1' ]; then
      find "$TEST_CASE/resources/database" -depth -delete
    else
      [ ! -e "$TEST_CASE/resources" ] || find "$TEST_CASE/resources" -depth -delete
    fi
    ;;
  lease-batch-release)
    owned
    fault
    absent
    rm "$TEST_CASE/lease"
    ;;
  *) exit 64 ;;
esac
SH;
$probe = <<<'SH'
set -euo pipefail
export TEST_CASE="$1" TEST_FAULT="$2" TMPDIR="$1/scratch"
cd "$TEST_CASE/repo/sandbox"
export WPRISM_SOURCE_ROOT="$(cd .. && pwd -P)"
HEAD=$(git rev-parse HEAD)
export WPRISM_EXPECTED_SOURCE_SHA="$HEAD"
say() { :; }
fail() { printf '%s\n' "$*" >&2; exit 1; }
. tests/lib/pair_live_ownership.sh
# OS process census, like Docker, is outside this deterministic scenario
# boundary; its live/dead/ambiguous cases belong to the shared lease suite.
pair_lease_owner_start() { printf 'fixture owner start\n'; }
pair_live_ownership_prepare laneprobe 9520 9521 'combination lane probe' 'wprism-combination-lanes'
printf 'private host registry\n' > "$PAIR_LIVE_OWNERSHIP_TMP_ROOT/host-envs.json"
printf '%s\n' "$PAIR_LIVE_OWNERSHIP_TMP_ROOT" > "$TEST_CASE/scratch-path"
leg=0
run_leg() {
  leg=$((leg + 1))
  printf 'leg:%s:%s:%s\n' "$1" "$2" "$3" >> "$TEST_CASE/events"
  [ "$(<"$PAIR_LIVE_OWNERSHIP_TMP_ROOT/host-envs.json")" = 'private host registry' ] \
    || fail 'between-lane cleanup lost the shared private registry'
  [ -d "$TEST_CASE/resources/database" ] && [ -d "$TEST_CASE/resources/webroot" ]
  if [ -e "$TEST_CASE/resources/webroot/derivative" ] \
      && [ ! -e "$TEST_CASE/resources/database/attachment-id" ]; then
    fail 'lane starts with an unowned derivative after database-only reset'
  fi
  [ -z "$(find "$TEST_CASE/resources" -type f -print)" ] \
    || fail 'lane inherited native files or database identities'
  [ -z "$(find siterepo/laneprobe1 siterepo/laneprobe2 siterepo/origin-laneprobe.git -type f -print)" ] \
    || fail 'lane inherited repository or origin state'
  printf 'original\n' > "$TEST_CASE/resources/webroot/original"
  printf 'generated derivative\n' > "$TEST_CASE/resources/webroot/derivative"
  printf '%s\n' "$leg" > "$TEST_CASE/resources/database/attachment-id"
  printf 'source code\n' > siterepo/laneprobe1/code
  printf 'target canonical state\n' > siterepo/laneprobe2/state
  printf 'origin commit\n' > siterepo/origin-laneprobe.git/commit
  if [ "$TEST_FAULT" = 'lost-lease:1' ]; then rm "$TEST_CASE/lease"; fi
  [ "$TEST_FAULT" != "body:$leg" ] || fail "injected body:$leg"
}
SH;
$orders = ['leg:forward:reverse:independent', 'leg:reverse:forward:independent',
    'leg:forward:reverse:synchronized', 'leg:reverse:forward:synchronized'];
$run = static function (string $fault, string $caller) use ($tmp, $root, $boundary, $probe): array {
    $case = $tmp . '/' . bin2hex(random_bytes(6));
    foreach (['repo/sandbox/bin', 'repo/sandbox/tests/lib', 'repo/sandbox/lib', 'repo/sandbox/siterepo/foreign',
        'repo/agent', 'repo/adapter-packages', 'repo/platform', 'scratch', 'foreign-resources'] as $directory) {
        mkdir($case . '/' . $directory, 0700, true);
    }
    foreach (['sandbox/tests/lib/pair_live_ownership.sh', 'sandbox/lib/pair_identity.sh',
        'sandbox/lib/pair_db.sh', 'sandbox/lib/pair_lease.sh'] as $library) {
        copy($root . '/' . $library, $case . '/repo/' . $library);
    }
    file_put_contents($case . '/repo/sandbox/bin/pair.sh', $boundary);
    file_put_contents($case . '/repo/sandbox/siterepo/foreign/marker', 'foreign repository');
    file_put_contents($case . '/foreign-resources/marker', 'foreign resource');
    [$setupExit, , $setupError] = ShellProbe::run('set -euo pipefail' . "\n"
        . 'cd "$1/repo"; git init -q; git add -A; git -c user.name=fixture -c user.email=fixture@example.test commit -qm fixture', [$case], $root);
    if ($setupExit !== 0 || $setupError !== '') throw new RuntimeException('private pair fixture checkout failed');
    [$exit, $stdout, $stderr] = ShellProbe::run($probe . "\n" . $caller, [$case, $fault], $root);
    $events = file_exists($case . '/events') ? file($case . '/events', FILE_IGNORE_NEW_LINES) : [];
    $scratch = is_file($case . '/scratch-path') ? trim((string) file_get_contents($case . '/scratch-path')) : '';
    wprism_check_same('foreign repository', file_get_contents($case . '/repo/sandbox/siterepo/foreign/marker'), "$fault: unrelated repository is preserved");
    wprism_check_same('foreign resource', file_get_contents($case . '/foreign-resources/marker'), "$fault: unrelated resources are preserved");
    wprism_check($scratch !== '' && !file_exists($scratch), "$fault: real EXIT cleanup removes private scratch");
    return [$exit, $stdout, $stderr, $events, $case];
};

[$exit, $stdout, $stderr, $events, $case] = $run('none', $dispatch);
$expected = [];
foreach ($orders as $order) array_push($expected, 'lease-batch-acquire', 'up', $order, 'destroy', 'lease-batch-release');
wprism_check($exit === 0 && $stderr === '' && substr_count($stdout, '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED') === 1,
    'actual dispatcher completes four isolated lanes with its sole post-cleanup PASS');
wprism_check_same($expected, $events, 'each exact order has a complete namespace lifetime, not a database reset');
wprism_check(!file_exists($case . '/lease') && !file_exists($case . '/resources'), 'final PASS follows resource removal and lease release');

foreach (['body:1'=>1, 'body:2'=>2, 'body:3'=>3, 'body:4'=>4,
    'lease-batch-acquire:1'=>0, 'lease-batch-acquire:2'=>1, 'lease-batch-acquire:4'=>3,
    'up:1'=>0, 'up:3'=>2, 'destroy:1'=>1, 'destroy:4'=>4,
    'lease-batch-release:1'=>1, 'lease-batch-release:4'=>4, 'survivor:1'=>1, 'lost-lease:1'=>1] as $fault => $entered) {
    [$exit, $stdout, $stderr, $events, $case] = $run($fault, $dispatch);
    $legs = array_values(array_filter($events, static fn(string $event): bool => str_starts_with($event, 'leg:')));
    wprism_check($exit !== 0 && !str_contains($stdout, '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED'),
        "$fault: failed body, freshness or teardown cannot publish PASS");
    $cause = match ($fault) {
        'survivor:1' => 'lease release or final namespace census failed',
        'lost-lease:1' => 'pair destroy failed',
        default => 'injected ' . $fault,
    };
    wprism_check(str_contains($stderr, $cause), "$fault: the selected fault, not an unrelated startup failure, owns the refusal");
    wprism_check_same(array_slice($orders, 0, $entered), $legs,
        "$fault: no later lane executes after the failed boundary" . ($legs === [] && $entered > 0 ? ' (' . trim($stderr) . ')' : ''));
    // A one-shot between-lane failure is retried by EXIT cleanup, which may
    // release the lease but must never turn the original body into success.
    $retained = $fault === 'destroy:4' || $fault === 'lease-batch-release:4' || $fault === 'survivor:1';
    wprism_check(file_exists($case . '/lease') === $retained, "$fault: ownership is retained only when its release is unproved");
    if (str_starts_with($fault, 'body:') || str_starts_with($fault, 'up:') || str_starts_with($fault, 'lease-batch-acquire:')) {
        wprism_check(!file_exists($case . '/resources'), "$fault: partial or complete owned resources are cleaned");
    }
}

// Keep the original root cause executable after the caller is repaired. A
// freshly acquired lease on lane one alone cannot isolate later webroot state.
$resetCaller = str_replace("pair_live_ownership_finish_leg\npair_live_ownership_acquire mariadb", 'pair_live_ownership_reset', $dispatch);
$resetCaller = str_replace("pair_live_ownership_finish_leg\n  pair_live_ownership_acquire mariadb", 'pair_live_ownership_reset', $resetCaller);
[$exit, $stdout, $stderr, $events] = $run('reset-counterfactual', $resetCaller);
wprism_check($exit !== 0 && str_contains($stderr, 'unowned derivative after database-only reset')
    && !str_contains($stdout, '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED'),
    'database-only reset reproduces the next-lane lost-ownership mechanism without weakening the engine guard');
wprism_check_same(array_slice($orders, 0, 2), array_values(array_filter($events,
    static fn(string $event): bool => str_starts_with($event, 'leg:'))), 'reset counterfactual reaches and refuses exactly the second lane');
wprism_check_summary('combination pair lane isolation');
