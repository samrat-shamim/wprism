<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
$source = (string) file_get_contents($root . '/adapter-packages/woocommerce/fixtures/woocommerce-destructive-lifecycle.sh');
$start = strpos($source, '  reinstall_deploy_rc=0');
$end = strpos($source, '  wp_conf2 plugin is-active', $start === false ? 0 : $start);
if ($start === false || $end === false) throw new RuntimeException('missing destructive deploy evidence block');
$block = substr($source, $start, $end - $start);
$directory = sys_get_temp_dir() . '/woo-destructive-evidence-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$script = <<<'SH'
set -euo pipefail
root="$1"; mode="$2"; probe="$3"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$root/sandbox/conformance/asserts.sh"
. "$root/sandbox/tests/lib/private_command_capture.sh"
snapshot() { printf '{}\n'; }
collect() { printf '{"retained":"private cause"}\n'; }
validate() { [ "$mode" != collector-failed ] || [[ "$1" = */baseline ]]; }
conformance_private_command() {
  [ "$1 $2" = 'cli2 deploy' ] || return 97
  shift 2
  local -a before=(snapshot) after=(collect) check=(validate)
  wprism_private_command_capture "$probe/capture" before after check -- "$@"
}
wp_conf2() {
  [ "$*" = 'wprism deploy --repo=/siterepo --format=json' ] || return 98
  printf 'original diagnostic\n' >&2
  if [ "$mode" = refused ]; then
    printf '{"ok":false,"reason_code":"deploy_failed"}\n'; return 7
  fi
  if [ "$mode" = malformed ]; then printf 'not json\n'; return 0; fi
  printf '{"ok":true}\n'
}
SH;
file_put_contents($directory . '/probe.sh', $script . "\n" . $block);
try {
    foreach (['success', 'refused', 'malformed', 'collector-failed'] as $case) {
        mkdir($directory . '/' . $case, 0700);
        $process = proc_open(['/bin/bash', $directory . '/probe.sh', $root, $case, $directory . '/' . $case],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('cannot execute recovery evidence probe');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);
        wprism_check(($rc === 0) === ($case === 'success'), "destructive deploy admission checks status and response: $case");
        $captures = glob($directory . '/' . $case . '/capture.*') ?: [];
        wprism_check(count($captures) === 1 && is_file($captures[0] . '/command.stdout')
            && is_file($captures[0] . '/private.stdout'), "destructive deploy retains original and private streams: $case");
        if ($case === 'refused') {
            wprism_check(str_contains($err, 'exited 7:') && str_contains($err, 'deploy_failed')
                && str_contains($err, 'private command diagnostics (unverified):'),
                'failed deploy reports original exit, public refusal and retained evidence location');
        }
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
wprism_check_summary('WooCommerce destructive deploy evidence');
