<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bin/ssh-rollback-certification.php';

function src_ok(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "ok: $message\n";
}
function src_refuses(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $e) { echo "ok: $message\n"; return; }
    fwrite(STDERR, "FAIL: $message\n"); exit(1);
}
function src_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) src_remove($item->getPathname());
    rmdir($path);
}
/** @return array{exit:int,stdout:string,stderr:string} */
function src_run(array $command): array {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('could not start child');
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    return ['exit'=>$exit,'stdout'=>(string)$stdout,'stderr'=>(string)$stderr];
}
function src_hash(string $value): string { return hash('sha256', $value); }

/** @return array<string,mixed> */
function src_spec(): array {
    $matrix = ssh_cert_matrix();
    $cases = [];
    foreach ($matrix['case_ids'] as $index => $caseId) {
        [$boundary,$failure,$edge] = explode('--', $caseId, 3);
        $terminal = $index % 11 === 0 ? 'committed' : 'rolled_back';
        $prior = src_hash("prior-$caseId"); $new = src_hash("new-$caseId");
        $cases[] = [
            'boundary'=>$boundary, 'case_id'=>$caseId, 'edge'=>$edge,
            'event_chain_sha256'=>src_hash("events-$caseId"), 'failure_mode'=>$failure,
            'injection_process_id'=>'inject-' . $index, 'inputs_sha256'=>src_hash("inputs-$caseId"),
            'intermediate'=>['authority_state'=>'prepared','exclusion_held'=>true,'green'=>false,'traffic_closed'=>true],
            'new_artifact_sha256'=>$new, 'prior_artifact_sha256'=>$prior,
            'receipt_sha256'=>src_hash("receipt-$caseId"),
            'resource_fingerprints'=>[
                'code'=>src_hash("code-$caseId"), 'database'=>src_hash("database-$caseId"),
                'effects'=>src_hash("effects-$caseId"), 'runtime'=>src_hash("runtime-$caseId"),
                'target'=>src_hash("target-$caseId"), 'uploads'=>src_hash("uploads-$caseId"),
            ],
            'terminal'=>['exclusion_released'=>true,'fresh_process_verified'=>true,'green'=>true,'state'=>$terminal],
            'verifier'=>[
                'adapter_invariants_sha256'=>src_hash("adapters-$caseId"),
                'artifact_sha256'=>$terminal==='committed'?$new:$prior,
                'canonical_first_sha256'=>src_hash("canonical-$caseId"),
                'canonical_second_sha256'=>src_hash("canonical-$caseId"),
                'code_inventory_sha256'=>src_hash("code-inventory-$caseId"),
                'database_schema_sha256'=>src_hash("schema-$caseId"),
                'fresh_processes'=>true, 'ledger_session_sha256'=>src_hash("ledger-$caseId"),
                'mutable_checkout'=>false, 'process_ids'=>['verify-a-'.$index,'verify-b-'.$index],
                'report_sha256'=>src_hash("report-$caseId"),
                'runtime_fingerprints_sha256'=>src_hash("runtime-verify-$caseId"),
                'upload_inventory_sha256'=>src_hash("upload-inventory-$caseId"),
                'world'=>$terminal==='committed'?'new':'prior',
            ],
            'versions'=>[
                'checkpoint'=>'checkpoint:1.0.0', 'code_release'=>'code:1.0.0',
                'database'=>'mariadb:11.8', 'effects'=>'effects:1.0.0',
                'exclusion'=>'exclusion:1.0.0', 'harness'=>'harness:1.0.0',
                'runtime'=>'runtime:1.0.0', 'uploads'=>'uploads:1.0.0',
            ],
        ];
    }
    $negative = [];
    foreach (ssh_cert_negative_ids() as $id) $negative[] = [
        'case_id'=>$id, 'error_sha256'=>src_hash("error-$id"),
        'evidence_sha256'=>src_hash("evidence-$id"), 'exclusion_held'=>true,
        'mutation_observed'=>false, 'refused'=>true, 'traffic_closed'=>true,
    ];
    return [
        'cases'=>$cases,
        'cleanup'=>[
            'active_receipt_absent'=>true, 'maintenance_lock_absent'=>true,
            'owned_ssh_fixture_absent'=>true, 'plaintext_checkpoint_absent'=>true,
        ],
        'created_at'=>'2026-08-08T00:00:00Z', 'format'=>SSH_ROLLBACK_INPUT_FORMAT,
        'harness_revision'=>src_hash('harness'), 'key_id'=>'duo-3299-fixture',
        'negative_cases'=>$negative,
        'source'=>['database_sha256'=>src_hash('source-db'),'host_sha256'=>src_hash('source-host')],
        'target'=>['database_sha256'=>src_hash('target-db'),'host_sha256'=>src_hash('target-host')],
    ];
}

$tmp = sys_get_temp_dir() . '/duo-ssh-rollback-cert-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$secret = sodium_crypto_sign_keypair();
$secretBytes = sodium_crypto_sign_secretkey($secret);
$publicBytes = sodium_crypto_sign_publickey($secret);
try {
    $spec = src_spec();
    $validated = ssh_cert_validate_spec($spec);
    src_ok($validated['case_count'] === count(ssh_cert_boundaries()) * count(ssh_cert_faults()) * count(ssh_cert_edges()), 'closed crash matrix validates every boundary/fault/edge case');
    src_ok($validated['case_count'] === 198, 'matrix case count is explicit and stable');

    $missing = $spec; array_pop($missing['cases']);
    src_refuses(fn()=>ssh_cert_validate_spec($missing), 'missing crash boundary case is rejected');
    $duplicate = $spec; $duplicate['cases'][1] = $duplicate['cases'][0];
    src_refuses(fn()=>ssh_cert_validate_spec($duplicate), 'duplicate or reordered crash case is rejected');
    $negativeMissing = $spec;
    $negativeMissing['negative_cases'] = array_values(array_filter(
        $negativeMissing['negative_cases'],
        static fn(array $case): bool => ($case['case_id'] ?? '') !== 'terminal-without-prior-verify'
    ));
    src_refuses(
        fn()=>ssh_cert_validate_spec($negativeMissing),
        'removing the terminal-without-prior-verify negative case invalidates certification'
    );
    $sameHost = $spec; $sameHost['target']['host_sha256'] = $sameHost['source']['host_sha256'];
    src_refuses(fn()=>ssh_cert_validate_spec($sameHost), 'shared SSH host identity is rejected');
    $sameDb = $spec; $sameDb['target']['database_sha256'] = $sameDb['source']['database_sha256'];
    src_refuses(fn()=>ssh_cert_validate_spec($sameDb), 'shared database identity is rejected');

    foreach (ssh_cert_verifier_fields() as $field) {
        $broken = $spec; unset($broken['cases'][0]['verifier'][$field]);
        src_refuses(fn()=>ssh_cert_validate_spec($broken), "missing verifier field $field is rejected");
    }
    $recapture = $spec; $recapture['cases'][0]['verifier']['canonical_second_sha256'] = src_hash('changed');
    src_refuses(fn()=>ssh_cert_validate_spec($recapture), 'non-identical second canonical recapture is rejected');
    $reused = $spec; $reused['cases'][0]['verifier']['process_ids'][0] = $reused['cases'][0]['injection_process_id'];
    src_refuses(fn()=>ssh_cert_validate_spec($reused), 'verifier reuse of the injected process is rejected');
    $mutable = $spec; $mutable['cases'][0]['verifier']['mutable_checkout'] = true;
    src_refuses(fn()=>ssh_cert_validate_spec($mutable), 'new verification from a mutable checkout is rejected');
    $wrongWorld = $spec; $wrongWorld['cases'][0]['verifier']['artifact_sha256'] = src_hash('foreign-artifact');
    src_refuses(fn()=>ssh_cert_validate_spec($wrongWorld), 'terminal verifier for the wrong artifact is rejected');
    $greenIntermediate = $spec; $greenIntermediate['cases'][0]['intermediate']['green'] = true;
    src_refuses(fn()=>ssh_cert_validate_spec($greenIntermediate), 'green intermediate authority is rejected');
    $openTraffic = $spec; $openTraffic['cases'][0]['intermediate']['traffic_closed'] = false;
    src_refuses(fn()=>ssh_cert_validate_spec($openTraffic), 'open traffic during a crash is rejected');
    $badTerminal = $spec; $badTerminal['cases'][0]['terminal']['state'] = 'failed';
    src_refuses(fn()=>ssh_cert_validate_spec($badTerminal), 'non-committed/non-rolled-back terminal is rejected');
    $badNegative = $spec; $badNegative['negative_cases'][0]['mutation_observed'] = true;
    src_refuses(fn()=>ssh_cert_validate_spec($badNegative), 'negative case with observed mutation is rejected');
    foreach (array_keys($spec['cleanup']) as $field) {
        $broken = $spec; $broken['cleanup'][$field] = false;
        src_refuses(fn()=>ssh_cert_validate_spec($broken), "missing cleanup proof $field is rejected");
    }

    $specPath=$tmp.'/spec.json';$secretPath=$tmp.'/secret.key';$publicPath=$tmp.'/public.key';$bundlePath=$tmp.'/bundle.json';
    file_put_contents($specPath, json_encode($spec, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    file_put_contents($secretPath, base64_encode($secretBytes)."\n");chmod($secretPath,0600);
    file_put_contents($publicPath, base64_encode($publicBytes)."\n");chmod($publicPath,0644);
    $tool=dirname(__DIR__).'/bin/ssh-rollback-certification.php';
    $built=src_run([PHP_BINARY,$tool,'build',$specPath,$secretPath,$bundlePath]);
    src_ok($built['exit']===0&&is_file($bundlePath),'valid closed matrix builds a signed canonical bundle');
    $verified=src_run([PHP_BINARY,$tool,'verify',$bundlePath,$publicPath]);
    $verdict=json_decode($verified['stdout'],true);
    src_ok($verified['exit']===0&&($verdict['verdict']??'')==='valid'&&($verdict['case_count']??0)===198,'signature and every bound verifier validate with human/machine/exit agreement');
    $bundle=json_decode((string)file_get_contents($bundlePath),true,512,JSON_THROW_ON_ERROR);
    $bundle['payload']['cases'][0]['terminal']['state']='rolled_back';
    file_put_contents($bundlePath,ssh_cert_json($bundle));
    $tampered=src_run([PHP_BINARY,$tool,'verify',$bundlePath,$publicPath]);
    src_ok($tampered['exit']!==0&&str_contains($tampered['stdout'],'signature is invalid'),'signed payload tamper is rejected');
    echo "PASS: signed SSH rollback certification bundle closes and verifies the complete crash matrix\n";
} finally {
    sodium_memzero($secretBytes);
    src_remove($tmp);
}
