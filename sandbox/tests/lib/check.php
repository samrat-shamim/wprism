<?php
/**
 * Shared assertion helpers for the offline regress_*.php suites.
 *
 * WHY THIS EXISTS
 * ---------------
 * 96 of the 204 offline suites hand-roll the identical closure
 *
 *     $check = static function (bool $ok, string $message) use (&$failures): void { ... };
 *
 * and then hand-roll one of ~12 different end-of-suite summaries. That is
 * 2,114 call sites whose *output* the offline runner and
 * sandbox/tests/offline_diagnostics_guard.sh already depend on: the guard
 * greps the combined stream for PHP diagnostics and refuses a green result if
 * it finds any, while pass/fail itself is carried by the process exit code.
 * So the contract this file must preserve is narrow but real:
 *
 *   - never emit anything that looks like a PHP diagnostic ("Warning:",
 *     "Deprecated:", "Fatal error:", "Parse error:" followed by " in "/" on
 *     line "), because the guard would fail an otherwise-passing suite;
 *   - exit 0 only when every assertion passed;
 *   - keep the per-assertion "ok: <message>" line, which is what a human
 *     reads when a leaf target fails inside `make -k -j8`.
 *
 * DELIBERATE DIVERGENCE FROM THE MAJORITY OF EXISTING SUITES: failures are
 * written to STDERR, not STDOUT. Most existing suites echo "FAIL: ..." to
 * STDOUT. Under `make -j8` (the measured 97 s gate) STDOUT of ~217 leaves is
 * interleaved, and the diagnostics guard concatenates both streams anyway, so
 * routing failures to STDERR loses nothing and makes a failure visible in a
 * terminal that is dropping STDOUT. Passing lines stay on STDOUT so the
 * existing "ok: " greps keep working.
 *
 * COLLISION SAFETY: every function below is function_exists()-guarded and
 * prefixed duo_check*. A suite that already declares its own $check closure,
 * or that is included after another lib consumer in the same process (the
 * PHPUnit tooling tests do exactly this), must never fatal on redeclaration.
 *
 * NO DEPENDENCIES: this file must run under a plain `php sandbox/tests/X.php`
 * with no composer autoloader and no WordPress. The only lazy require is
 * agent/src/Kernel/CommandRefusal.php, and only inside duo_check_refuses().
 */
declare(strict_types=1);

if (!function_exists('duo_check_state')) {
    /**
     * The single mutable counter shared by every helper in this file.
     *
     * Returned by reference so callers (and duo_check_closure()) mutate the
     * one array rather than a copy. Stored in $GLOBALS rather than a static
     * so a suite can inspect or reset it across included files.
     *
     * @return array{passed:int,failed:int,failures:list<string>}
     */
    function &duo_check_state(): array {
        if (!isset($GLOBALS['duo_check_state']) || !is_array($GLOBALS['duo_check_state'])) {
            $GLOBALS['duo_check_state'] = ['passed' => 0, 'failed' => 0, 'failures' => []];
        }
        $state = & $GLOBALS['duo_check_state'];
        return $state;
    }
}

if (!function_exists('duo_check_reset')) {
    /** Drop every recorded assertion. Used by the PHPUnit tooling tests. */
    function duo_check_reset(): void {
        $GLOBALS['duo_check_state'] = ['passed' => 0, 'failed' => 0, 'failures' => []];
    }
}

if (!function_exists('duo_check_stats')) {
    /**
     * Read-only snapshot of the counters.
     *
     * @return array{passed:int,failed:int,failures:list<string>}
     */
    function duo_check_stats(): array {
        $state = & duo_check_state();
        return $state;
    }
}

if (!function_exists('duo_check_failed')) {
    /** Number of failed assertions so far. */
    function duo_check_failed(): int {
        $state = & duo_check_state();
        return $state['failed'];
    }
}

if (!function_exists('duo_check')) {
    /**
     * The primitive every other helper funnels through.
     *
     * Prints "ok: <message>" to STDOUT on success and "FAIL: <message>" to
     * STDERR on failure, then records the outcome. Never throws: a suite is
     * expected to keep going and report every failing assertion in one run,
     * which is what makes a single `make -k -j8` pass actionable.
     */
    function duo_check(bool $ok, string $message): void {
        $state = & duo_check_state();
        if ($ok) {
            $state['passed']++;
            echo 'ok: ' . $message . "\n";
            return;
        }
        $state['failed']++;
        $state['failures'][] = $message;
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
    }
}

if (!function_exists('duo_check_detail')) {
    /**
     * Emit an indented evidence line attached to the assertion just reported.
     *
     * Goes to STDERR alongside the FAIL line so the two stay adjacent even
     * when STDOUT is being dropped. Kept separate from duo_check() so the
     * "ok:" path stays a single line per assertion (2,114 of them).
     *
     * EVERY line is indented, not just the first. Details carry payloads this
     * file did not author -- an exception message from duo_check_throws(), a
     * captured option value from duo_check_repr() -- and a continuation line
     * starting at column 0 with "Warning: ... in foo.php on line 12" is an
     * exact match for offline_diagnostics_guard.sh's regex. The suite is
     * already failing at that point, so the guard cannot turn green into red;
     * what it does is retitle a real assertion failure as "offline command
     * emitted PHP diagnostics" and send the reader hunting for a PHP error
     * that does not exist. Four leading spaces make the match impossible.
     *
     * The split is on PCRE \R, not just "\n", so a lone CR or a form feed is
     * normalised too: the guard greps LF-delimited records but the offline
     * runner splits the same bytes on \R, and a continuation that only ONE of
     * them sees at column 0 is the worst version of this bug.
     */
    function duo_check_detail(string $line): void {
        fwrite(STDERR, '    ' . preg_replace('/\R/', "\n    ", $line) . "\n");
    }
}

if (!function_exists('duo_check_repr')) {
    /**
     * Compact single-line rendering of any value for failure evidence.
     *
     * var_export() is used rather than json_encode() because the values under
     * test here routinely include PHP nulls, booleans, and serialized option
     * payloads whose type distinction is the whole point of the assertion.
     * Truncated because a captured option value can be tens of kilobytes and
     * an unbounded dump would bury the diff -- and, in this repo, could carry
     * payload bytes into a log.
     *
     * SINGLE-LINE means single-line for strings too. var_export() of a string
     * keeps its newlines verbatim, and a captured payload whose second line
     * reads "Warning: ... in foo.php on line 12" is byte-for-byte what
     * offline_diagnostics_guard.sh refuses. Line breaks and tabs are escaped
     * rather than collapsed to spaces so that "a\nb" and "a b" still render
     * differently -- the whole job of this function is to show WHY two values
     * differ, and whitespace is exactly the difference a collapse would hide.
     */
    function duo_check_repr(mixed $value, int $limit = 400): string {
        $text = (string) var_export($value, true);
        $text = is_string($value)
            ? str_replace(["\r\n", "\n", "\r", "\t", "\v", "\f"], ['\r\n', '\n', '\r', '\t', '\v', '\f'], $text)
            : (string) preg_replace('/\s+/', ' ', $text);
        return strlen($text) > $limit
            ? substr($text, 0, $limit) . '... (' . strlen($text) . ' bytes)'
            : $text;
    }
}

if (!function_exists('duo_check_diff_path')) {
    /**
     * First differing path between two values, as "a.b.0", or null if the two
     * are strictly identical.
     *
     * Only the FIRST difference is reported: a full structural diff of a
     * captured entity document is unreadable in a terminal, while the first
     * divergence is nearly always the actual defect.
     */
    function duo_check_diff_path(mixed $expected, mixed $actual, string $path = ''): ?string {
        if ($expected === $actual) {
            return null;
        }
        if (!is_array($expected) || !is_array($actual)) {
            return $path;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                return $path === '' ? (string) $key : $path . '.' . $key;
            }
            $child = duo_check_diff_path($value, $actual[$key], $path === '' ? (string) $key : $path . '.' . $key);
            if ($child !== null) {
                return $child;
            }
        }
        foreach ($actual as $key => $_) {
            if (!array_key_exists($key, $expected)) {
                return $path === '' ? (string) $key : $path . '.' . $key;
            }
        }
        // Same keys, same recursive values, still not identical: array key
        // ORDER differs. That is a real difference for this codebase, whose
        // canonical documents are order-sensitive on the wire.
        return $path === '' ? '(key order)' : $path . ' (key order)';
    }
}

if (!function_exists('duo_check_same')) {
    /**
     * Strict identity assertion with a compact diff on failure.
     *
     * === is deliberate. Loose comparison is what lets a captured '0' pass for
     * a real 0 and a missing meta row pass for an empty string, which is
     * exactly the class of drift these suites exist to catch.
     */
    function duo_check_same(mixed $expected, mixed $actual, string $message): void {
        if ($expected === $actual) {
            duo_check(true, $message);
            return;
        }
        duo_check(false, $message);
        $path = duo_check_diff_path($expected, $actual);
        if ($path !== null && $path !== '') {
            duo_check_detail('first difference at: ' . $path);
        }
        duo_check_detail('expected: ' . duo_check_repr($expected));
        duo_check_detail('actual:   ' . duo_check_repr($actual));
    }
}

if (!function_exists('duo_check_canonical_json')) {
    /**
     * Decode to a recursively key-sorted structure for order-insensitive
     * comparison. Lists (sequential integer keys) keep their order: element
     * order in a JSON array is meaningful, key order in a JSON object is not.
     */
    function duo_check_canonical_json(string|array $value): mixed {
        $decoded = is_array($value) ? $value : json_decode($value, true);
        if (is_string($value) && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'duo_check_json_equal: not valid JSON (' . json_last_error_msg() . ')'
            );
        }
        return duo_check_ksort_recursive($decoded);
    }
}

if (!function_exists('duo_check_ksort_recursive')) {
    /** Recursively ksort() every associative array; leave lists untouched. */
    function duo_check_ksort_recursive(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = duo_check_ksort_recursive($item);
        }
        if (!$isList) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }
}

if (!function_exists('duo_check_json_equal')) {
    /**
     * Compare two JSON documents (as encoded strings or decoded arrays)
     * ignoring object key order but preserving array element order.
     *
     * Object key order is NOT part of the JSON data model, so a suite that
     * compares raw encoded bytes is asserting an implementation detail of
     * whatever built the document. Use duo_check_same() on the raw string
     * when byte-exact output IS the contract (canonical export bytes).
     */
    function duo_check_json_equal(string|array $expected, string|array $actual, string $message): void {
        try {
            $expectedCanonical = duo_check_canonical_json($expected);
            $actualCanonical = duo_check_canonical_json($actual);
        } catch (InvalidArgumentException $e) {
            duo_check(false, $message);
            duo_check_detail($e->getMessage());
            return;
        }
        if ($expectedCanonical === $actualCanonical) {
            duo_check(true, $message);
            return;
        }
        duo_check(false, $message);
        $path = duo_check_diff_path($expectedCanonical, $actualCanonical);
        if ($path !== null && $path !== '') {
            duo_check_detail('first difference at: ' . $path);
        }
        duo_check_detail('expected: ' . duo_check_repr($expectedCanonical));
        duo_check_detail('actual:   ' . duo_check_repr($actualCanonical));
    }
}

if (!function_exists('duo_check_throws')) {
    /**
     * Assert $fn throws $exceptionClass, optionally with $messageContains in
     * the message.
     *
     * The class is checked with instanceof, so asserting \RuntimeException
     * accepts the typed subclasses (DatabaseMutationException,
     * TransientDbException, CommandRefusalException) -- name the tightest
     * class the contract actually promises. A throw of the WRONG type is
     * reported as a failure rather than propagating, so one bad path does not
     * abort the remaining assertions in the suite.
     */
    function duo_check_throws(
        callable $fn,
        string $exceptionClass,
        string $message,
        ?string $messageContains = null
    ): void {
        try {
            $fn();
        } catch (Throwable $e) {
            if (!($e instanceof $exceptionClass)) {
                duo_check(false, $message);
                duo_check_detail('expected ' . $exceptionClass . ', got ' . get_class($e));
                duo_check_detail('message:  ' . $e->getMessage());
                return;
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                duo_check(false, $message);
                duo_check_detail('message does not contain: ' . $messageContains);
                duo_check_detail('message:  ' . $e->getMessage());
                return;
            }
            duo_check(true, $message);
            return;
        }
        duo_check(false, $message);
        duo_check_detail('expected ' . $exceptionClass . ', nothing was thrown');
    }
}

if (!function_exists('duo_check_refuses')) {
    /**
     * Assert $fn throws \Duo\CommandRefusalException with exactly $reasonCode.
     *
     * The reason code -- not the message -- is the machine-readable public
     * contract (see agent/src/Kernel/CommandRefusal.php). Asserting on the message
     * would pin operator-only text that the class is explicitly allowed to
     * redact, so this helper reports the message only as failure evidence.
     *
     * CommandRefusal.php is required lazily and only when the class is not
     * already declared: a suite that never asserts a refusal must not pull
     * agent/src/Kernel/Secrets.php (CommandRefusal's own dependency) into its
     * process, because several suites assert exactly which agent classes a
     * boundary loads.
     */
    function duo_check_refuses(callable $fn, string $reasonCode, string $message): void {
        if (!class_exists('\\Duo\\CommandRefusalException', false)) {
            require_once __DIR__ . '/../../../agent/src/Kernel/CommandRefusal.php';
        }
        try {
            $fn();
        } catch (\Duo\CommandRefusalException $e) {
            if ($e->reasonCode !== $reasonCode) {
                duo_check(false, $message);
                duo_check_detail('expected reason code ' . $reasonCode . ', got ' . $e->reasonCode);
                return;
            }
            duo_check(true, $message);
            return;
        } catch (Throwable $e) {
            duo_check(false, $message);
            duo_check_detail('expected a CommandRefusalException, got ' . get_class($e));
            duo_check_detail('message:  ' . $e->getMessage());
            return;
        }
        duo_check(false, $message);
        duo_check_detail('expected refusal ' . $reasonCode . ', nothing was thrown');
    }
}

if (!function_exists('duo_check_closure')) {
    /**
     * A drop-in replacement for the hand-rolled
     *
     *     $check = static function (bool $ok, string $message) use (&$failures): void { ... };
     *
     * so a suite can adopt the shared counter and summary WITHOUT rewriting
     * its 30-plus existing $check(...) call sites in the same edit. This is
     * the migration seam: swap the closure definition and the tail, leave the
     * body alone, then convert individual assertions to duo_check_same() etc.
     * as they are touched for other reasons.
     */
    function duo_check_closure(): \Closure {
        return static function (bool $ok, string $message): void {
            duo_check($ok, $message);
        };
    }
}

if (!function_exists('duo_check_summary')) {
    /**
     * Terminate the suite with the conventional summary and exit status.
     *
     * Prints "PASS: <suite>" to STDOUT, or "FAIL: <n> assertions" plus the
     * failing messages to STDERR. Neither form can be mistaken for a PHP
     * diagnostic by offline_diagnostics_guard.sh, whose pattern requires the
     * literal " in " / " on line " source suffix.
     *
     * Exits 1 when nothing was asserted at all: a suite that silently stops
     * asserting (an early `return`, a guard that skipped the whole body) is a
     * regression, and exit 0 would hide it behind a green gate.
     */
    function duo_check_summary(string $suite): never {
        $state = & duo_check_state();
        if ($state['failed'] === 0 && $state['passed'] === 0) {
            fwrite(STDERR, "FAIL: $suite ran no assertions\n");
            exit(1);
        }
        if ($state['failed'] === 0) {
            $plural = $state['passed'] === 1 ? 'assertion' : 'assertions';
            echo "PASS: $suite ({$state['passed']} $plural)\n";
            exit(0);
        }
        $plural = $state['failed'] === 1 ? 'assertion' : 'assertions';
        fwrite(STDERR, "FAIL: {$state['failed']} $plural\n");
        foreach ($state['failures'] as $failure) {
            fwrite(STDERR, ' - ' . $failure . "\n");
        }
        exit(1);
    }
}
