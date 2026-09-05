<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';

$root = dirname(__DIR__, 4);
$check = (string) file_get_contents(dirname(__DIR__) . '/conformance/check.sh');
$matrix = (string) file_get_contents(dirname(__DIR__) . '/certify/version-matrix.sh');
$driver = (string) file_get_contents($root . '/sandbox/tests/certify/certify_version_matrix.sh');
$matrixHelpers = [];
foreach (['assert_no_php_diagnostics', 'assert_version_matrix_apply_ready'] as $name) {
    preg_match('/^' . $name . '\(\).*?^\}/ms', $driver, $match);
    wprism_check(isset($match[0]), 'the matrix provides its real ' . $name . ' boundary');
    $matrixHelpers[] = $match[0] ?? '';
}

// The 900b3e52 forced-conflict block accepted both a discarded PHP stdout
// prelude and a serialized required-env warning. Its native provider receipt
// was genuine-looking but was not a complete positive Apply result. Execute
// every owning command-to-acceptance block, not a copied readiness predicate.
$cases = [
    'forced conflict' => [$check, '/^(?:capture_wprism_json_checked FORCED\b|FORCED=\$\()/m', 'CONVERGED=$(observe_code_snippets', false],
    'clean retry' => [$check, '/^(?:capture_wprism_json_checked ZERO_APPLY\b|ZERO_APPLY=\$\()/m', 'pass "same-name target rows', true],
    'database-restored no-op' => [$check, '/^capture_wprism_json_(?:checked|success) COMPLETE_RESTORED_APPLY\b/m', 'rm -f "$COMPLETE_BACKUP"', true],
    'post-recovery intent' => [$check, '/^capture_wprism_json_(?:checked|success) COMPLETE_APPLY\b/m', 'COMPLETE_RECOVERED=$(observe_code_snippets', false],
    'version boundary' => [$matrix, '/^[ \t]*wp2 wprism apply /m', '  check_code_snippets_boundary_content ', false],
];
preg_match_all('/\bwp(?:_conf2|2) wprism apply\b/', $check . "\n" . $matrix, $applySites);
wprism_check(count($applySites[0]) === count($cases) + 2,
    'all five positive Apply sites are enumerated separately from the two expected refusals');
wprism_check(
    str_contains($check, 'CONFLICT_OUT=$(wp_conf2 wprism apply')
        && str_contains($check, 'capture_wprism_json_refusal COMPLETE_LOST_APPLY '),
    'competing-intent and destroyed-identity Apply refusals retain their negative ownership'
);

$probe = static function (string $block, array $answer, string $prefix, string $stderr, int $exit) use ($root, $matrixHelpers): array {
    $script = <<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
umask 077
probe_root=$(mktemp -d "${TMPDIR:-/tmp}/wprism-code-snippets-apply.XXXXXX")
trap 'rm -rf -- "$probe_root"' EXIT
VMATRIX_APPLY_LOG="$probe_root/apply.log"
REV=fixture-revision CODE_SNIPPETS_VERSION=3.9.5
fixture_json="$2" fixture_prefix="$3" fixture_stderr="$4" fixture_exit="$5"
fixture_apply() {
  [ "$1" = wprism ] && [ "$2" = apply ] || return 81
  printf '%s' "$fixture_prefix"
  [ -z "$fixture_stderr" ] || printf '%s\n' "$fixture_stderr" >&2
  if printf '%s\n' "$@" | grep -qx -- '--format=json'; then
    printf '%s\n' "$fixture_json"
  else
    jq -r '.warnings[] | select(startswith("provider capability fired:")) | "Warning: " + .' <<<"$fixture_json"
    if [ "$(jq -r .canary <<<"$fixture_json")" = clean ]; then
      printf 'Success: applied 3 entities (canary clean) — plan was: {"env_missing":1}\n'
    else
      printf 'Success: applied 3 entities (canary dirty)\n'
    fi
    jq -r '.warnings[] | select(startswith("env_missing:")) | "Warning: " + .' <<<"$fixture_json" >&2
  fi
  return "$fixture_exit"
}
wp_conf2() { fixture_apply "$@"; }
wp2() { fixture_apply "$@"; }
SH;
    $process = proc_open(
        ['bash', '-c', $script . "\n" . implode("\n", $matrixHelpers) . "\n" . $block . "\nprintf 'APPLY_ACCEPTED\\n'\n",
            'code-snippets-apply-probe', $root, json_encode($answer, JSON_THROW_ON_ERROR), $prefix, $stderr, (string) $exit],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
    );
    if (!is_resource($process)) {
        return [127, '', 'could not launch the capsule Apply probe'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};

foreach ($cases as $label => [$source, $startPattern, $endToken, $noOp]) {
    preg_match($startPattern, $source, $start, PREG_OFFSET_CAPTURE);
    $offset = $start[0][1] ?? false;
    $end = $offset === false ? false : strpos($source, $endToken, $offset);
    $block = $offset === false || $end === false ? '' : substr($source, $offset, $end - $offset);
    wprism_check($block !== '' && substr_count($block, 'wprism apply') === 1,
        $label . ' exposes exactly one actual command-to-acceptance block');
    $answer = [
        'canary' => 'clean',
        'verification' => ['result' => 'pass'],
        'plan' => ['env_missing' => 1],
        'warnings' => ['FORCED conflict fixture', 'provider capability fired: code-snippets-state@1.0.0 rebuild_snippet_state (0.03s, verified)'],
        'actions' => $noOp ? [] : [[
            'kind' => 'provider', 'source' => 'provider:code-snippets-state/rebuild_snippet_state',
            'provider_version' => '1.0.0', 'verified' => true,
            'after' => [
                'row_count' => 3, 'database_hash' => str_repeat('a', 64), 'api_hash' => str_repeat('a', 64),
                'flat_files_enabled' => false, 'flat_file_count' => 0, 'flat_tree_hash' => str_repeat('b', 64),
            ],
        ]],
    ];
    $mutations = ['ready', 'php-stdout', 'php-stderr', 'php-startup', 'php-parse', 'required-warning', 'required-prefix', 'required-stderr', 'canary', 'verification', 'missing-verification', 'native-receipt', 'nonzero'];
    foreach ($mutations as $mutation) {
        $candidate = $answer;
        $prefix = $stderr = '';
        $exit = 0;
        $category = '';
        switch ($mutation) {
            case 'php-stdout':
                $prefix = "PHP Warning: fixture diagnostic in /fixture.php on line 12\n";
                $category = 'PHP runtime diagnostic';
                break;
            case 'php-stderr':
                $stderr = 'PHP Notice: fixture diagnostic in /fixture.php on line 12';
                $category = 'PHP runtime diagnostic';
                break;
            case 'php-startup':
                $prefix = "PHP Warning: PHP Startup: fixture extension in Unknown on line 0\n";
                $category = 'PHP runtime diagnostic';
                break;
            case 'php-parse':
                $stderr = 'PHP Parse error: fixture syntax';
                $category = 'PHP runtime diagnostic';
                break;
            case 'required-warning':
                $candidate['warnings'][] = 'env_missing: option home is required';
                $category = 'required environment bindings';
                break;
            case 'required-prefix':
                $prefix = "Warning: env_missing: option home is required\n";
                $category = 'required environment bindings';
                break;
            case 'required-stderr':
                $stderr = 'Warning: env_missing: option home is required';
                $category = 'required environment bindings';
                break;
            case 'canary':
                $candidate['canary'] = 'dirty';
                break;
            case 'verification':
                $candidate['verification']['result'] = 'fail';
                break;
            case 'missing-verification':
                unset($candidate['verification']);
                break;
            case 'native-receipt':
                $candidate['actions'] = $noOp ? [['kind' => 'provider']] : $candidate['actions'];
                if (!$noOp) {
                    $candidate['actions'][0]['verified'] = false;
                }
                $candidate['warnings'] = ['FORCED conflict fixture'];
                break;
            case 'nonzero':
                $exit = 7;
                break;
        }
        [$status, $stdout, $stderr] = $probe($block, $candidate, $prefix, $stderr, $exit);
        wprism_check(
            $mutation === 'ready'
                ? $status === 0 && str_contains($stdout, "APPLY_ACCEPTED\n") && $stderr === ''
                : $status !== 0 && !str_contains($stdout, 'APPLY_ACCEPTED')
                    && ($category === '' || str_contains($stdout . $stderr, $category)),
            $label . ' classifies ' . $mutation . ' before accepting its native receipt (optional rows remain valid)'
        );
    }
}
wprism_check_summary('Code Snippets complete positive Apply evidence');
