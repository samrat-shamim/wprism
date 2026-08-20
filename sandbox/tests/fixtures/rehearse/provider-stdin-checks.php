<?php
/**
 * DUO-3492: the reference provider's external-command boundary, on the write
 * side.
 *
 * `ref_run()` feeds a child's stdin over a non-blocking pipe. A child that
 * stops reading — `docker exec -i … mariadb` rejecting the first statement and
 * exiting, `ref_clear_side()`'s DROP/CREATE against a container that is gone,
 * or any child that never reads at all — closes the read end, and the next
 * write(2) fails with EPIPE (errno=32). Treating that as the provider's own
 * failure had two costs, both observed:
 *
 *  1. It is a race, so it made the provider non-deterministic. Whether the
 *     parent's write lands before the child exits is pure scheduling: 16
 *     concurrent copies of regress_rehearse_provider.sh on a 10-core host
 *     produced 3 spurious `create`/`destroy` refusals in 32 runs, all reading
 *     "could not send reference provider command input", none reproducible
 *     standalone. That is the flake DUO-3492 was filed for.
 *  2. It destroyed the diagnosis. The child's exit status and stderr — the
 *     only evidence that says WHY docker refused — were discarded in favour of
 *     a message about this provider's own pipe.
 *
 * So the child's outcome is the verdict, and these checks pin both halves of
 * that: a stdin-refusing child that succeeds is not a provider failure, and a
 * stdin-refusing child that FAILS still refuses, naming its own exit and
 * stderr through ref_checked().
 *
 * `ref_run()` and `ref_checked()` are lifted out of the provider source rather
 * than required, because tools/reference-env-provider.php executes its main
 * block at include time; regress_rehearse_provider.sh already lifts
 * `ref_freeze_witness()` out the same way (:276-289).
 *
 * usage: php provider-stdin-checks.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';

$providerSource = (string) file_get_contents(dirname(__DIR__, 4) . '/tools/reference-env-provider.php');
foreach (['ref_run', 'ref_checked'] as $name) {
    if (preg_match('/^function ' . $name . '\(.*?\n\}/ms', $providerSource, $match) !== 1) {
        fwrite(STDERR, "FAIL: could not lift $name() out of the reference provider\n");
        exit(1);
    }
    eval($match[0]);
}

// One pipe buffer is 64 KiB at most on both supported hosts (macOS grows a
// pipe to 65536 bytes, Linux starts there), so a payload past that cannot be
// handed over in a single write and the child's exit is guaranteed to beat the
// remainder. That is what makes the EPIPE deterministic here instead of
// scheduling-dependent the way the product path is.
$dump = str_repeat("INSERT INTO `wp_options` VALUES (1,'siteurl','http://source.example:9600','on');\n", 4096);
duo_check(strlen($dump) > 65536, 'the fixture dump is larger than one pipe buffer, so it cannot be written in one go');

$diagnostics = [];
set_error_handler(static function (int $number, string $message) use (&$diagnostics): bool {
    // Honour `@` the PHP 8 way: the suppressed expression clears the
    // diagnostic's bit from error_reporting(). Anything still reported here
    // would land in the suite's output as a stray PHP diagnostic. (The offline
    // guard itself polices warnings and fatals, not the notice fwrite emits —
    // sandbox/tests/offline_diagnostics_guard.sh:28-29 — so this fixture, not
    // the guard, is what keeps the suppression honest.)
    if ((error_reporting() & $number) !== 0) {
        $diagnostics[] = $message;
    }

    return true;
});

$ignoresStdin = ['/bin/sh', '-c', 'exec 0<&-; exit 0'];
$ignoresStdinAndFails = ['/bin/sh', '-c', 'exec 0<&-; printf %s\\\\n "mariadb: refusing the statement" >&2; exit 7'];
$drainsStdin = ['/bin/sh', '-c', 'wc -c'];

/**
 * Report the boundary's outcome instead of aborting on it: against the defect
 * every call below throws, and a fixture that died on the first one would
 * publish one fatal error rather than the four failures that say which
 * property broke.
 *
 * @param list<string> $argv
 * @return array{exit:int,stdout:string,stderr:string}
 */
function stdin_run(array $argv, string $input): array {
    try {
        return ref_run($argv, $input);
    } catch (Throwable $error) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => $error->getMessage()];
    }
}

$ignored = stdin_run($ignoresStdin, $dump);
duo_check_same(0, $ignored['exit'], 'a child that refuses the dump on stdin and exits 0 is not a provider failure');
duo_check_same([], $diagnostics, 'the broken-pipe write emits no PHP diagnostic for the offline guard to fail on');

$drained = stdin_run($drainsStdin, $dump);
duo_check_same(0, $drained['exit'], 'a child that drains the dump still exits through the same boundary');
duo_check_same(
    strlen($dump),
    (int) trim($drained['stdout']),
    'a reading child receives every byte: the write side stops only when the reader is gone, never on its own'
);

$refusal = null;
try {
    ref_checked($ignoresStdinAndFails, $dump);
} catch (Throwable $error) {
    $refusal = $error->getMessage();
}
duo_check(is_string($refusal), 'a stdin-refusing child that fails still refuses');
duo_check(
    is_string($refusal) && str_contains($refusal, 'mariadb: refusing the statement'),
    'the refusal carries the child\'s own stderr instead of a message about this provider\'s pipe'
);
duo_check(
    is_string($refusal) && !str_contains($refusal, 'could not send reference provider command input'),
    'a broken pipe is never reported as the provider failing to send its input'
);

restore_error_handler();
duo_check_summary('reference provider stdin boundary');
