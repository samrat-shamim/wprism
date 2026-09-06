<?php
declare(strict_types=1);

namespace WPrismTest;

require_once __DIR__ . '/check.php';

/** Execute fixture-owned shell blocks without pipe-buffer deadlocks or inherited app credentials. */
final class ShellProbe {
    /** @param list<string> $arguments @return array{int,string,string} */
    public static function run(string $script, array $arguments, string $root): array {
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }
            throw new \RuntimeException('could not allocate private shell-probe captures');
        }
        try {
            $process = proc_open(
                ['bash', '-c', $script, 'shell-probe', ...$arguments],
                [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr],
                $pipes,
                $root,
                ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('could not start shell probe');
            }
            fclose($pipes[0]);
            $status = proc_close($process);
            rewind($stdout);
            rewind($stderr);
            return [$status, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
    }

    /** Extract the real command and its acceptance, keeping old last-line captures mutation-testable. */
    public static function captureBlock(string $source, string $variable, string $endToken, int $offset = 0): string {
        $name = preg_quote($variable, '/');
        if (preg_match('/^[\t ]*(?:' . $name . '=\$\(|capture_wprism_json_(?:success|checked) ' . $name . ' )/m',
            $source, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            throw new \RuntimeException('missing actual capture block: ' . $variable);
        }
        $start = $match[0][1];
        $end = strpos($source, $endToken, $start);
        if ($end === false) {
            throw new \RuntimeException('missing actual capture acceptance boundary: ' . $variable);
        }
        return substr($source, $start, $end - $start);
    }

    /** @param array<string,mixed> $answer */
    public static function positiveApply(string $root, string $block, array $answer, string $label, string $setup = '', bool $replay = false): void {
        $script = <<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
fixture_answer="$2" fixture_stderr="$3" fixture_exit="$4"
fixture_command() {
  printf '%s\n' "$fixture_answer"
  [ -z "$fixture_stderr" ] || printf '%s\n' "$fixture_stderr" >&2
  return "$fixture_exit"
}
wp_conf2() { fixture_command "$@"; }
wp2() { fixture_command "$@"; }
REV=fixture UPGRADE_REV=fixture DOWNGRADE_REV=fixture
VMATRIX_APPLY_LOG=$(mktemp "${TMPDIR:-/tmp}/wprism-apply-probe.XXXXXX")
trap 'rm -f -- "$VMATRIX_APPLY_LOG"' EXIT
SH;
        $mutations = ['ready', 'php-stdout', 'php-stderr', 'startup', 'required-stdout', 'required-stderr',
            'required-json', 'missing-warnings', 'bad-canary', 'bad-verification', 'nonzero'];
        foreach ($mutations as $mutation) {
            $candidate = $answer;
            if ($mutation === 'required-json') {
                $candidate['warnings'][] = 'env_missing: fixture intent';
            } elseif ($mutation === 'missing-warnings') {
                unset($candidate['warnings']);
            } elseif ($mutation === 'bad-canary') {
                $candidate['canary'] = 'dirty';
            } elseif ($mutation === 'bad-verification') {
                $candidate['verification'] = $replay ? ['result' => 'pass'] : ['result' => 'fail'];
            }
            $stdout = json_encode($candidate, JSON_THROW_ON_ERROR);
            $stderr = '';
            if ($mutation === 'php-stdout') {
                $stdout = "PHP Warning: fixture in /fixture.php on line 1\n" . $stdout;
            } elseif ($mutation === 'startup') {
                $stdout = "PHP Warning: PHP Startup: fixture in Unknown on line 0\n" . $stdout;
            } elseif ($mutation === 'php-stderr') {
                $stderr = 'PHP Parse error: fixture';
            } elseif ($mutation === 'required-stdout') {
                $stdout = "Warning: env_missing: fixture intent\n" . $stdout;
            } elseif ($mutation === 'required-stderr') {
                $stderr = 'Warning: env_missing: fixture intent';
            }
            [$status, $observed, $diagnostic] = self::run(
                $script . "\n" . $setup . "\n" . $block . "\nprintf 'BLOCK_READY\\n'\n",
                [$root, $stdout, $stderr, $mutation === 'nonzero' ? '7' : '0'],
                $root
            );
            \wprism_check($mutation === 'ready'
                ? $status === 0 && str_contains($observed, "BLOCK_READY\n")
                : $status !== 0 && !str_contains($observed, 'BLOCK_READY'),
                "$label: actual command/acceptance classifies $mutation (exit $status)");
        }
    }
}
