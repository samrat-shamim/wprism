<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
$source = (string) file_get_contents($root . '/adapter-packages/woocommerce/fixtures/woocommerce-destructive-lifecycle.sh');
$start = strpos($source, '  reinstall_activation_rc=0');
$end = strpos($source, '  refusal_identity=', $start === false ? 0 : $start);
if ($start === false || $end === false) throw new RuntimeException('missing destructive native activation block');
$block = substr($source, $start, $end - $start);
$directory = sys_get_temp_dir() . '/woo-destructive-reinstall-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$script = <<<'SH'
set -euo pipefail
mode="$1"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
wp_conf2() {
  case "$*" in
    'plugin activate woocommerce')
      if [ "$mode" = failed ]; then printf 'native activation failure\n' >&2; return 7; fi
      printf 'Success: Plugin activated.\n';;
    'plugin is-active woocommerce') [ "$mode" != inactive ];;
    *) printf 'unexpected command: %s\n' "$*" >&2; return 98;;
  esac
}
SH;
file_put_contents($directory . '/probe.sh', $script . "\n" . $block);
try {
    foreach (['success', 'failed', 'inactive'] as $case) {
        $process = proc_open(['/bin/bash', $directory . '/probe.sh', $case],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('cannot execute destructive reinstall probe');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);
        wprism_check(($rc === 0) === ($case === 'success'), "destructive reinstall requires native activation and active readback: $case");
        if ($case === 'failed') {
            wprism_check(str_contains($err, 'exited 7:') && str_contains($err, 'native activation failure'),
                'failed native activation reports original status and diagnostic');
        }
    }
} finally {
    unlink($directory . '/probe.sh');
    rmdir($directory);
}
wprism_check_summary('WooCommerce destructive native reinstall');
