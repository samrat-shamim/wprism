<?php
declare(strict_types=1);

// DUO-3295: offline certification for encrypted checkpoint preparation,
// exact database restore ordering, prior verification, and retention.

require dirname(__DIR__, 2) . '/recovery/rollback-control.php';
require dirname(__DIR__, 2) . '/agent/src/Canon.php';
require dirname(__DIR__, 2) . '/cli/src/Transport.php';
require dirname(__DIR__, 2) . '/cli/src/SshTransport.php';
require dirname(__DIR__, 2) . '/cli/src/RollbackAuthority.php';
require dirname(__DIR__, 2) . '/cli/src/ScopedRollbackProfile.php';

use Duo\Orchestrator\RollbackAuthority;
use Duo\Orchestrator\ScopedRollbackProfile;
use Duo\Orchestrator\SshTransport;
use Duo\Recovery\CheckpointBundle;
use Duo\Recovery\RecoveryExecutor;
use Duo\Recovery\RollbackControl;

$tmp = sys_get_temp_dir() . '/duo-checkpoint-regress-' . bin2hex(random_bytes(8));
$keyId = 'checkpoint-test-key';
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);

function checkpoint_fail(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function checkpoint_ok(bool $condition, string $message): void { if (!$condition) checkpoint_fail($message); echo "ok: $message\n"; }
function checkpoint_refuses(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $e) { echo "ok: $message\n"; return; }
    checkpoint_fail($message);
}
function checkpoint_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') checkpoint_remove_tree($path . '/' . $name);
    @rmdir($path);
}
function checkpoint_write(string $path, string $bytes, int $mode = 0600): void {
    if (file_put_contents($path, $bytes) !== strlen($bytes)) checkpoint_fail("could not write $path");
    chmod($path, $mode);
}
/** @return array<string,mixed> */
function checkpoint_signed_submit(string $root, array $payload, string $keyId, string $secret): array {
    $path = tempnam(sys_get_temp_dir(), 'duo-checkpoint-request-');
    if ($path === false) checkpoint_fail('could not allocate checkpoint request');
    checkpoint_write($path, RollbackControl::canonical(RollbackControl::sign($payload, $keyId, $secret)) . "\n");
    try { return CheckpointBundle::handleRequest($root, $path); } finally { @unlink($path); }
}
/** @return array<string,mixed> */
function checkpoint_exclusion_submit(string $root, array $payload, string $keyId, string $secret): array {
    $path = tempnam(sys_get_temp_dir(), 'duo-exclusion-request-');
    if ($path === false) checkpoint_fail('could not allocate exclusion request');
    checkpoint_write($path, RollbackControl::canonical(RollbackControl::sign($payload, $keyId, $secret)) . "\n");
    try { return RecoveryExecutor::handleExclusionRequest($root, $path); } finally { @unlink($path); }
}
/** @return array<string,mixed> */
function checkpoint_authority_submit(string $root, array $event, ?array $receipt, string $keyId, string $secret): array {
    $request = ['action' => $receipt === null ? 'append' : 'claim', 'event' => RollbackControl::sign($event, $keyId, $secret), 'receipt' => $receipt === null ? null : RollbackControl::sign($receipt, $keyId, $secret)];
    $path = tempnam(sys_get_temp_dir(), 'duo-authority-request-');
    if ($path === false) checkpoint_fail('could not allocate authority request');
    checkpoint_write($path, RollbackControl::canonical($request) . "\n");
    try { return RollbackControl::handleRequest($root, $path); } finally { @unlink($path); }
}
/** @return array<string,mixed> */
function checkpoint_event(array $receipt, array $status, string $state, string $operationStatus, string $operationId, string $claimant, string $timestamp, string $input, string $result, int $attempt = 1): array {
    $seconds = strtotime($timestamp); if ($seconds === false) checkpoint_fail('bad timestamp');
    return [
        'artifact_hash' => $receipt['artifact_hash'], 'attempt' => $attempt,
        'claim_epoch' => 1, 'claim_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $seconds + 30),
        'claimant' => $claimant, 'format' => RollbackControl::EVENT_FORMAT,
        'generation' => $receipt['generation'], 'input_sha256' => $input,
        'operation_id' => $operationId, 'operation_status' => $operationStatus,
        'owner' => $receipt['owner'], 'previous_event_sha256' => $status['head_event_sha256'] ?? str_repeat('0', 64),
        'receipt_id' => $receipt['receipt_id'], 'result_sha256' => $result,
        'sequence' => isset($status['sequence']) ? (int) $status['sequence'] + 1 : 1,
        'signing_key_id' => $receipt['signing_key_id'], 'state' => $state,
        'target_id' => $receipt['target_id'], 'timestamp' => $timestamp,
    ];
}
/** @return array<string,mixed> */
function checkpoint_request(array $identity, string $action, string $timestamp): array {
    return [
        'action' => $action, 'artifact_hash' => $identity['artifact_hash'],
        'claim_epoch' => 1, 'claimant' => 'worker-a',
        'encryption_key_id' => 'kms-fixture-key', 'format' => 'duo-checkpoint-request/v1',
        'generation' => 1, 'owner' => 'controller:test',
        'receipt_id' => str_repeat('c', 48), 'retention_until' => '2020-01-02T00:00:00Z',
        'target_id' => $identity['target_id'], 'timestamp' => $timestamp,
    ];
}
/** @return array<string,mixed> */
function checkpoint_scoped_plan(string $artifactHash, string $scopeHash, string $seed): array {
    $hash = static fn(string $value): string => hash('sha256', $seed . ':' . $value);
    return [
        'artifact_hash' => $artifactHash,
        'format' => 'duo-scoped-plan/v1',
        'resolved_adapters' => [['name' => 'fixture-db', 'version' => '1.0.0']],
        'scope' => [
            'format' => 'duo-scope-contract/v1',
            'scope_hash' => $scopeHash,
            'source_artifact_hash' => $artifactHash,
        ],
        'selected_actions' => [],
        'selected_surfaces' => ['option:fixture-' . $seed],
        'target' => [
            'ledger_map_root' => $hash('ledger-map'),
            'protected_ledger_map_root' => $hash('protected-ledger-map'),
            'protected_out_of_scope_root' => $hash('protected-root'),
            'selected_before_root' => $hash('selected-before'),
            'selected_ledger_map_root' => $hash('selected-ledger-map'),
            'target_observation_hash' => $hash('target-observation'),
        ],
    ];
}
/** @return array<string,mixed> */
function checkpoint_scoped_terminal(string $seed): array {
    $hash = static fn(string $value): string => hash('sha256', $seed . ':' . $value);
    $terminal = [
        'authority_hash' => $hash('authority'),
        'convergence_hash' => $hash('convergence'),
        'intents_hash' => $hash('intents'),
        'lease_hash' => $hash('lease'),
        'phase' => 'complete',
        'protected_ledger_map_hash' => $hash('protected-map'),
        'receipts_hash' => $hash('receipts'),
        'selected_ledger_map_hash' => $hash('selected-map'),
        'session_id' => 'scoped-session-' . $seed,
    ];
    $terminal['terminal_hash'] = hash('sha256', \Duo\Canon::encode($terminal));
    return $terminal;
}

$exclusionSource = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
function ec(array $v): string { ksort($v,SORT_STRING); foreach($v as $k=>$x) if(is_array($x)) $v[$k]=json_decode(ec($x),true); return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
$r=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); if(($r['format']??'')!=='duo-exclusion-provider-request/v2')exit(42); $p=$argv[1]; $a=$r['action']; $s=is_file($p)?json_decode((string)file_get_contents($p),true):null;
if($a==='probe'){ $token=null;$state='ready'; } elseif($a==='acquire'){ $token=$s['token']??('held-'.hash('sha256',ec($r)));$state='held';file_put_contents($p,ec(['state'=>'held','token'=>$token])."\n"); } else { if(!is_array($s)||$s['state']!=='held'||$s['token']!==$r['token'])exit(41);$token=$s['token'];$state=$a==='release'?'released':'held'; }
echo ec(['available'=>true,'disconnect_behavior'=>'remain_excluded','format'=>'duo-exclusion-provider-response/v2','provider_id'=>'checkpoint-exclusion','provider_version'=>'1.0.0','scopes'=>['background_jobs'=>true,'database_writers'=>true,'filesystem_writers'=>true,'package_updates'=>true,'public_traffic'=>true],'state'=>$state,'target_id'=>$r['target_id'],'token'=>$token])."\n";
PHP;

$adapterSource = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$r=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$o=['adapter'=>$r['adapter'],'adapter_version'=>'1.0.0','available'=>true,'format'=>'duo-recovery-adapter-response/v1','input_sha256'=>$r['input_sha256'],'loads_site_code'=>false,'result_sha256'=>null,'status'=>'ready'];ksort($o);echo json_encode($o,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
PHP;

$checkpointProviderSource = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
function cc(array $v): string { ksort($v,SORT_STRING); foreach($v as $k=>$x) if(is_array($x)) $v[$k]=array_is_list($x)?array_map(fn($i)=>is_array($i)?json_decode(cc($i),true):$i,$x):json_decode(cc($x),true); return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function decrypt_fixture(string $path,string $key): string { $raw=(string)file_get_contents($path);$header=substr($raw,0,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);$state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);$offset=SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;$out='';while($offset<strlen($raw)){ $length=unpack('N',substr($raw,$offset,4))[1];$offset+=4;$chunk=substr($raw,$offset,$length);$offset+=$length;$pulled=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$chunk);if($pulled===false)throw new RuntimeException('authentication failed');$out.=$pulled[0]; }return $out; }
$r=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$statePath=$argv[1];$dbPath=$argv[2];$keyPath=$argv[3];$key=base64_decode(trim((string)file_get_contents($keyPath)),true);$a=$r['action'];
if($a==='probe'){ $o=['available'=>true,'format'=>'duo-checkpoint-provider-response/v1','plaintext_durable'=>false,'provider_id'=>'fixture-checkpoint','provider_version'=>'1.0.0','state'=>'ready','streaming_authenticated_encryption'=>true,'temporary_plaintext_cleaned'=>true];echo cc($o)."\n";exit; }
$dir=$r['artifact_directory'];$cipher=$dir.'/checkpoint.enc';$verifier=$dir.'/prior-verifier-inputs.json';$runtime=hash('sha256','runtime-protected');$ledger=hash('sha256','ledger-session');
if($a==='prepare'){ $plain=(string)file_get_contents($dbPath);[$push,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);$fh=fopen($cipher,'wb');fwrite($fh,$header);foreach(str_split($plain,31) as $i=>$chunk){$tag=$i===count(str_split($plain,31))-1?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:0;$encrypted=sodium_crypto_secretstream_xchacha20poly1305_push($push,$chunk,'',$tag);fwrite($fh,pack('N',strlen($encrypted)).$encrypted);}fflush($fh);fsync($fh);fclose($fh);chmod($cipher,0600);
 $inputs=['canonical_tree_sha256'=>hash('sha256',$plain),'code_revision_sha256'=>hash('sha256','code-revision'),'database_schema_sha256'=>hash('sha256','schema'),'format'=>'duo-prior-verifier-inputs/v1','ledger_session_sha256'=>$ledger,'lifecycle_receipts_sha256'=>hash('sha256','lifecycle-receipts'),'manifest_inputs_sha256'=>hash('sha256','manifest-inputs'),'map_state_sha256'=>hash('sha256','map-state'),'policy_sha256'=>hash('sha256','policy'),'runtime_fingerprints_sha256'=>$runtime,'state_revision_sha256'=>hash('sha256','state-revision')];file_put_contents($verifier,cc($inputs)."\n");chmod($verifier,0600);$decrypted=decrypt_fixture($cipher,$key);
 $o=['algorithm'=>'xchacha20poly1305-secretstream','available'=>true,'ciphertext_path'=>$cipher,'ciphertext_sha256'=>hash_file('sha256',$cipher),'ciphertext_size'=>filesize($cipher),'database_identity_sha256'=>hash('sha256','fixture-database'),'disposable_import_sha256'=>hash('sha256',$decrypted),'disposable_import_verified'=>hash_equals($plain,$decrypted),'export_evidence_sha256'=>hash('sha256',$plain),'format'=>'duo-checkpoint-provider-response/v1','key_id'=>$r['encryption_key_id'],'ledger_session_sha256'=>$ledger,'physical_erasure'=>'logical-delete-provider-limited','plaintext_durable'=>false,'prior_verifier_inputs_path'=>$verifier,'prior_verifier_inputs_sha256'=>hash_file('sha256',$verifier),'provider_id'=>'fixture-checkpoint','provider_version'=>'1.0.0','runtime_fingerprints_sha256'=>$runtime,'state'=>'prepared','streaming_authenticated_encryption'=>true,'temporary_plaintext_cleaned'=>true];echo cc($o)."\n";exit; }
if($a==='restore'){ $log=$statePath.'.restore.log';file_put_contents($log,"abort-before\nbegin-original\n",FILE_APPEND);$ok=true;try{if(is_file($statePath.'.wrong-key'))$key=random_bytes(32);$plain=decrypt_fixture($cipher,$key);if(is_file($statePath.'.fail-import'))$ok=false;if($ok)file_put_contents($statePath.'.restored',$plain);}catch(Throwable $e){$ok=false;}file_put_contents($log,"import:".($ok?'ok':'failed')."\nabort-final\n",FILE_APPEND);$databaseIdentity=is_file($statePath.'.wrong-database')?hash('sha256','wrong-database'):$r['database_identity_sha256'];$o=['abort_before'=>true,'abort_final'=>true,'abort_final_attempted'=>true,'action'=>'restore','authority_survived'=>true,'available'=>true,'begin_artifact_hash'=>$r['artifact_hash'],'begin_owner'=>$r['owner'],'database_identity_sha256'=>$databaseIdentity,'format'=>'duo-checkpoint-provider-response/v1','import_succeeded'=>$ok,'input_sha256'=>$r['input_sha256'],'provider_id'=>'fixture-checkpoint','provider_version'=>'1.0.0','result_sha256'=>hash('sha256',$ok?'restored':'failed'),'state'=>$ok?'restored':'failed','temporary_plaintext_cleaned'=>true];echo cc($o)."\n";exit; }
if($a==='verify-prior'){ $v=json_decode((string)file_get_contents($verifier),true);$first=$v['canonical_tree_sha256'];$second=is_file($statePath.'.bad-verifier')?hash('sha256','changed'):$first;$o=['action'=>'verify-prior','available'=>true,'canonical_first_sha256'=>$first,'canonical_second_sha256'=>$second,'code_revision_sha256'=>$v['code_revision_sha256'],'database_identity_sha256'=>$r['database_identity_sha256'],'database_schema_sha256'=>$v['database_schema_sha256'],'format'=>'duo-checkpoint-provider-response/v1','fresh_processes'=>true,'input_sha256'=>$r['input_sha256'],'ledger_session_sha256'=>$v['ledger_session_sha256'],'lifecycle_receipts_sha256'=>$v['lifecycle_receipts_sha256'],'live_promotion_absent'=>true,'manifest_inputs_sha256'=>$v['manifest_inputs_sha256'],'map_state_sha256'=>$v['map_state_sha256'],'policy_sha256'=>$v['policy_sha256'],'provider_id'=>'fixture-checkpoint','provider_version'=>'1.0.0','result_sha256'=>hash('sha256',cc($v)),'runtime_fingerprints_sha256'=>$v['runtime_fingerprints_sha256'],'state'=>'verified','state_revision_sha256'=>$v['state_revision_sha256'],'verifier_inputs_sha256'=>hash_file('sha256',$verifier)];echo cc($o)."\n";exit; }
if($a==='delete'){@unlink($cipher);if(is_file($statePath.'.delete-disconnect')){@unlink($statePath.'.delete-disconnect');exit(43);}$o=['action'=>'delete','available'=>true,'ciphertext_absent'=>!is_file($cipher),'format'=>'duo-checkpoint-provider-response/v1','physical_erasure'=>'logical-delete-provider-limited','provider_id'=>'fixture-checkpoint','provider_version'=>'1.0.0','state'=>'deleted','temporary_plaintext_cleaned'=>true];echo cc($o)."\n";exit;}
exit(42);
PHP;

try {
    mkdir($tmp, 0700, true);
    $exclusion = $tmp . '/exclusion.php'; $adapter = $tmp . '/adapter.php'; $provider = $tmp . '/checkpoint.php';
    $providerState = $tmp . '/provider-state'; $dbSource = $tmp . '/database-export-source.sql'; $kmsKey = $tmp . '/kms.key';
    checkpoint_write($exclusion, $exclusionSource . "\n", 0700); checkpoint_write($adapter, $adapterSource . "\n", 0700); checkpoint_write($provider, $checkpointProviderSource . "\n", 0700);
    checkpoint_write($dbSource, "CREATE TABLE prior_state (id INT);\nINSERT INTO prior_state VALUES (7);\n");
    checkpoint_write($kmsKey, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)) . "\n");
    $root = $tmp . '/host/.duo/control'; $initial = RollbackControl::initialize($root); RollbackControl::installPublicKey($root, $keyId, base64_encode($public));
    $commands=[]; foreach(['code_restore','database_restore','prior_verify','storage_restore'] as $name)$commands[$name]=[PHP_BINARY,$adapter];
    $config=['adapters'=>$commands,'checkpoint_provider'=>[PHP_BINARY,$provider,$providerState,$dbSource,$kmsKey],'exclusion_provider'=>[PHP_BINARY,$exclusion,$tmp.'/exclusion-state.json'],'format'=>'duo-recovery-config/v1','timeout_seconds'=>3];
    $configPath=$tmp.'/config.json';checkpoint_write($configPath,RollbackControl::canonical($config)."\n");RecoveryExecutor::configureFromFile($root,$configPath);
    $probe=RecoveryExecutor::probe($root);checkpoint_ok(($probe['checkpoint']['streaming_authenticated_encryption']??false)===true,'preflight requires streaming authenticated encryption and no durable plaintext');

    $artifactHash=hash('sha256','new-artifact');$baseIdentity=['artifact_hash'=>$artifactHash,'target_id'=>$initial['target_id']];
    $exclusionPayload=['action'=>'acquire','artifact_hash'=>$artifactHash,'claim_epoch'=>1,'claimant'=>'worker-a','format'=>'duo-exclusion-request/v1','generation'=>1,'owner'=>'controller:test','receipt_id'=>str_repeat('c',48),'target_id'=>$initial['target_id'],'timestamp'=>'2020-01-01T00:00:00Z'];
    checkpoint_exclusion_submit($root,$exclusionPayload,$keyId,$secret);
    $wrong=checkpoint_request($baseIdentity,'prepare','2020-01-01T00:00:00Z');$wrong['target_id']=str_repeat('f',32);
    checkpoint_refuses(fn()=>checkpoint_signed_submit($root,$wrong,$keyId,$secret),'wrong target checkpoint preparation refuses before export');
    $orphanDir=dirname($root).'/rollback/'.str_repeat('c',48).'/artifacts';mkdir($orphanDir,0700,true);checkpoint_write($orphanDir.'/checkpoint.enc','interrupted');checkpoint_write($orphanDir.'/prior-verifier-inputs.json','interrupted');
    $prepared=checkpoint_signed_submit($root,checkpoint_request($baseIdentity,'prepare','2020-01-01T00:00:00Z'),$keyId,$secret);
    checkpoint_ok($prepared['ok']===true&&is_file(dirname($root).'/rollback/'.str_repeat('c',48).'/artifacts/checkpoint.enc'),'prepare recovers interrupted outputs and publishes ciphertext with immutable verifier inputs');
    $rollbackTree='';foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($root).'/rollback',FilesystemIterator::SKIP_DOTS)) as $file)$rollbackTree.=(string)file_get_contents($file->getPathname());
    checkpoint_ok(!str_contains($rollbackTree,'CREATE TABLE prior_state'),'rollback bundle contains no durable plaintext database export');

    $hash=static fn(string $v):string=>hash('sha256',$v);
    $receipt=['adapter_versions_sha256'=>$hash('adapters'),'artifact_hash'=>$artifactHash,'checkpoint_sha256'=>$prepared['checkpoint_sha256'],'claim_ttl_seconds'=>30,'code_release_metadata_sha256'=>$hash('manual-code-release'),'created_at'=>'2020-01-01T00:00:00Z','encryption_key_id'=>$prepared['encryption_key_id'],'exclusion_token_sha256'=>RecoveryExecutor::decorateStatus($root,RollbackControl::status($root))['exclusion_reservation']['token_sha256'],'format'=>RollbackControl::RECEIPT_FORMAT,'generation'=>1,'ledger_session_sha256'=>$prepared['ledger_session_sha256'],'lifecycle_receipts_sha256'=>$hash('lifecycle'),'owner'=>'controller:test','prior_code_descriptor_sha256'=>$hash('code'),'prior_verifier_inputs_sha256'=>$prepared['prior_verifier_inputs_sha256'],'receipt_id'=>str_repeat('c',48),'resources_inventory_sha256'=>$hash('resources'),'retention_until'=>'2020-01-02T00:00:00Z','runtime_fingerprints_sha256'=>$prepared['runtime_fingerprints_sha256'],'signing_key_id'=>$keyId,'target_id'=>$initial['target_id'],'uploads_inventory_sha256'=>$hash('uploads')];
    $badReceipt=$receipt;$badReceipt['checkpoint_sha256']=$hash('substituted-checkpoint');$first=checkpoint_event($badReceipt,[],'prepared','state_transition','promotion-claim','worker-a','2020-01-01T00:00:00Z',$hash('claim'),str_repeat('0',64));
    checkpoint_refuses(fn()=>checkpoint_authority_submit($root,$first,$badReceipt,$keyId,$secret),'receipt refuses substituted checkpoint metadata hash');
    $first=checkpoint_event($receipt,[],'prepared','state_transition','promotion-claim','worker-a','2020-01-01T00:00:00Z',$hash('claim'),str_repeat('0',64));$status=checkpoint_authority_submit($root,$first,$receipt,$keyId,$secret);
    checkpoint_ok($status['checkpoint_sha256']===$prepared['checkpoint_sha256'],'external receipt binds checkpoint metadata and survives outside the database');
    foreach(['rollback_pending','rolling_back'] as $i=>$state){$event=checkpoint_event($receipt,$status,$state,'state_transition','to-'.$state,'worker-a',sprintf('2020-01-01T00:00:0%dZ',$i+1),$hash($state),str_repeat('0',64));$status=checkpoint_authority_submit($root,$event,null,$keyId,$secret);}

    $restoreInput=$tmp.'/restore-input.json';checkpoint_write($restoreInput,RollbackControl::canonical(['checkpoint_sha256'=>$prepared['checkpoint_sha256'],'operation'=>'database_restore'])."\n");$restoreHash=(string)hash_file('sha256',$restoreInput);
    $op=checkpoint_event($receipt,$status,'rolling_back','prepared','database_restore','worker-a','2020-01-01T00:00:03Z',$restoreHash,str_repeat('0',64));$status=checkpoint_authority_submit($root,$op,null,$keyId,$secret);
    $cipher=dirname($root).'/rollback/'.str_repeat('c',48).'/artifacts/checkpoint.enc';$cipherBytes=(string)file_get_contents($cipher);file_put_contents($cipher,substr($cipherBytes,0,-3));
    checkpoint_refuses(fn()=>RecoveryExecutor::execute($root,'database_restore','database_restore',1,'worker-a',1,$restoreInput),'truncated ciphertext remains non-green before provider import');checkpoint_write($cipher,$cipherBytes);
    touch($providerState.'.wrong-key');checkpoint_refuses(fn()=>RecoveryExecutor::execute($root,'database_restore','database_restore',1,'worker-a',1,$restoreInput),'wrong external key fails restore while final abort evidence is retained');unlink($providerState.'.wrong-key');
    touch($providerState.'.fail-import');checkpoint_refuses(fn()=>RecoveryExecutor::execute($root,'database_restore','database_restore',1,'worker-a',1,$restoreInput),'failed import remains non-green');unlink($providerState.'.fail-import');
    touch($providerState.'.wrong-database');checkpoint_refuses(fn()=>RecoveryExecutor::execute($root,'database_restore','database_restore',1,'worker-a',1,$restoreInput),'wrong target database remains non-green');unlink($providerState.'.wrong-database');
    $restoreLog=(string)file_get_contents($providerState.'.restore.log');checkpoint_ok(substr_count($restoreLog,'abort-final')>=2,'final abort runs after wrong-key and failed-import attempts');
    checkpoint_ok(RecoveryExecutor::decorateStatus($root,$status)['exclusion_state']==='held','restore failures keep traffic/background exclusion held');
    $authorityBefore=RollbackControl::status($root);$restored=RecoveryExecutor::execute($root,'database_restore','database_restore',1,'worker-a',1,$restoreInput);$authorityAfter=RollbackControl::status($root);
    checkpoint_ok($authorityBefore['target_id']===$authorityAfter['target_id']&&$authorityBefore['generation']===$authorityAfter['generation'],'database replacement cannot replace external target/generation authority');
    $done=checkpoint_event($receipt,$status,'rolling_back','completed','database_restore','worker-a','2020-01-01T00:00:04Z',$restoreHash,$restored['result_sha256']);$status=checkpoint_authority_submit($root,$done,null,$keyId,$secret);
    $deletePayload=checkpoint_request($baseIdentity,'delete','2020-01-03T00:00:00Z');checkpoint_refuses(fn()=>checkpoint_signed_submit($root,$deletePayload,$keyId,$secret),'retention cannot delete material for a nonterminal generation');

    $transition=checkpoint_event($receipt,$status,'verifying_prior','state_transition','verify-prior-state','worker-a','2020-01-01T00:00:05Z',$hash('verify'),str_repeat('0',64));$status=checkpoint_authority_submit($root,$transition,null,$keyId,$secret);
    $verifyInput=$tmp.'/verify-input.json';checkpoint_write($verifyInput,RollbackControl::canonical(['checkpoint_sha256'=>$prepared['checkpoint_sha256'],'operation'=>'prior_verify'])."\n");$verifyHash=(string)hash_file('sha256',$verifyInput);
    $verifyOp=checkpoint_event($receipt,$status,'verifying_prior','prepared','prior_verify','worker-a','2020-01-01T00:00:06Z',$verifyHash,str_repeat('0',64));$status=checkpoint_authority_submit($root,$verifyOp,null,$keyId,$secret);
    touch($providerState.'.bad-verifier');checkpoint_refuses(fn()=>RecoveryExecutor::execute($root,'prior_verify','prior_verify',1,'worker-a',1,$verifyInput),'prior verifier mismatch remains non-green');unlink($providerState.'.bad-verifier');
    $verified=RecoveryExecutor::execute($root,'prior_verify','prior_verify',1,'worker-a',1,$verifyInput);$verifyDone=checkpoint_event($receipt,$status,'verifying_prior','completed','prior_verify','worker-a','2020-01-01T00:00:07Z',$verifyHash,$verified['result_sha256']);$status=checkpoint_authority_submit($root,$verifyDone,null,$keyId,$secret);
    $terminal=checkpoint_event($receipt,$status,'rolled_back','state_transition','rollback-complete','worker-a','2020-01-01T00:00:08Z',$hash('terminal'),str_repeat('0',64));$status=checkpoint_authority_submit($root,$terminal,null,$keyId,$secret);
    touch($providerState.'.delete-disconnect');checkpoint_refuses(fn()=>checkpoint_signed_submit($root,$deletePayload,$keyId,$secret),'delete disconnect after ciphertext removal remains retryable');
    $deleted=checkpoint_signed_submit($root,$deletePayload,$keyId,$secret);
    checkpoint_ok($deleted['ok']===true&&!is_file($cipher)&&!is_file(dirname($root).'/rollback/'.str_repeat('c',48).'/checkpoint-metadata.json'),'terminal retention deletion verifies checkpoint-material absence');
    checkpoint_ok(checkpoint_signed_submit($root,$deletePayload,$keyId,$secret)===$deleted,'terminal deletion retry returns the exact tombstone');
    $tombstone=(string)file_get_contents(dirname($root).'/rollback/'.str_repeat('c',48).'/checkpoint-tombstone.json');checkpoint_ok(!str_contains($tombstone,'CREATE TABLE')&&!str_contains($tombstone,trim((string)file_get_contents($kmsKey))),'audit tombstone is hash-only and contains no plaintext/key material');
    checkpoint_ok(str_contains($tombstone,'logical-delete-provider-limited'),'tombstone reports provider physical-erasure limits honestly');

    $controllerRoot=$tmp.'/controller-host/.duo/control';RollbackControl::initialize($controllerRoot);RollbackControl::installPublicKey($controllerRoot,$keyId,base64_encode($public));$installedRuntime=$controllerRoot.'/recovery-runtime';mkdir($installedRuntime,0700);foreach(['CanonicalJson.php','AtomicStore.php','ProtocolLock.php','ProviderClient.php','rollback-control.php','RecoveryExecutor.php','CheckpointBundle.php','CodeRelease.php','UploadBundle.php','EffectBundle.php'] as $runtimeFile)copy(dirname(__DIR__,2).'/recovery/'.$runtimeFile,$installedRuntime.'/'.$runtimeFile);
    $controllerConfig=$config;$controllerConfig['checkpoint_provider']=[PHP_BINARY,$provider,$tmp.'/controller-provider-state',$dbSource,$kmsKey];$controllerConfig['exclusion_provider']=[PHP_BINARY,$exclusion,$tmp.'/controller-exclusion.json'];$controllerConfigPath=$tmp.'/controller-config.json';checkpoint_write($controllerConfigPath,RollbackControl::canonical($controllerConfig)."\n");RecoveryExecutor::configureFromFile($controllerRoot,$controllerConfigPath);
    $signingPath=$tmp.'/controller-signing.key';checkpoint_write($signingPath,base64_encode($secret)."\n");$fakeBin=$tmp.'/fake-bin';mkdir($fakeBin,0700);checkpoint_write($fakeBin.'/ssh',"#!/bin/sh\n[ \"\$1\" = -T ] && shift\nshift\nexec /bin/sh -c \"\$1\"\n",0700);checkpoint_write($fakeBin.'/scp',"#!/bin/sh\nsrc=\$1\ndest=\$2\ntarget=\${dest#*:}\ncp \"\$src\" \"\$target\"\n",0700);putenv('PATH='.$fakeBin.':'.getenv('PATH'));
    $transport=new SshTransport('checkpoint-controller',['transport'=>'ssh','host'=>'fixture-host','wp_path'=>$tmp.'/unused-wordpress','repo_path'=>$tmp.'/controller-host','rollback_key_id'=>$keyId,'rollback_signing_key'=>$signingPath,'rollback_recovery'=>['adapters'=>$commands,'checkpoint_provider'=>[PHP_BINARY,$provider,$tmp.'/controller-provider-state',$dbSource,$kmsKey],'exclusion_provider'=>[PHP_BINARY,$exclusion,$tmp.'/controller-exclusion.json'],'timeout_seconds'=>3]]);
    $authority=new RollbackAuthority($transport);$controllerHash=$hash('controller-artifact');$claim=$authority->claim(['adapter_versions_sha256'=>$hash('controller-adapters'),'artifact_hash'=>$controllerHash,'claim_ttl_seconds'=>30,'code_release_metadata_sha256'=>$hash('controller-manual-code-release'),'encryption_key_id'=>'kms-fixture-key','lifecycle_receipts_sha256'=>$hash('controller-lifecycle'),'owner'=>'controller:api-test','prior_code_descriptor_sha256'=>$hash('controller-code'),'resources_inventory_sha256'=>$hash('controller-resources'),'retention_until'=>'2020-02-02T00:00:00Z','uploads_inventory_sha256'=>$hash('controller-uploads')],'controller-worker','2020-02-01T00:00:00Z');
    checkpoint_ok(($claim['status']['state']??'')==='prepared'&&($claim['receipt']['checkpoint_sha256']??'')!==$hash(''),'controller claim prepares and binds checkpoint before receipt publication');
    $authority->append('rollback_pending','state_transition','controller-rollback-pending',1,'controller-worker',$hash('controller-rollback-pending'),str_repeat('0',64),'2020-02-01T00:00:01Z');
    $authority->append('rolling_back','state_transition','controller-rolling-back',1,'controller-worker',$hash('controller-rolling-back'),str_repeat('0',64),'2020-02-01T00:00:02Z');
    $controllerRestore=['checkpoint_sha256'=>$claim['receipt']['checkpoint_sha256'],'operation'=>'database_restore'];
    $authority->prepareOperation('rolling_back','database_restore',1,$controllerRestore,'2020-02-01T00:00:03Z');
    touch($tmp.'/controller-provider-state.wrong-key');
    checkpoint_refuses(fn()=>$authority->executeOperation('database_restore',1,$controllerRestore),'SSH controller leaves a failed target operation open for exact fresh-process retry');
    unlink($tmp.'/controller-provider-state.wrong-key');
    $openStatus=RollbackAuthority::status($transport);checkpoint_ok(($openStatus['state']??'')==='rolling_back'&&($openStatus['open_operations']??0)===1&&($openStatus['exclusion_state']??'')==='held','failed SSH execution is nonterminal and keeps complete exclusion held');
    unset($authority);$authority=new RollbackAuthority($transport);
    $controllerRestoreResult=$authority->executeOperation('database_restore',1,$controllerRestore);
    $authority->completeOperation('rolling_back','database_restore',1,$controllerRestore,$controllerRestoreResult,'2020-02-01T00:00:04Z');
    $authority->append('verifying_prior','state_transition','controller-verifying-prior',1,'controller-worker',$hash('controller-verifying-prior'),str_repeat('0',64),'2020-02-01T00:00:05Z');
    $controllerVerify=['checkpoint_sha256'=>$claim['receipt']['checkpoint_sha256'],'operation'=>'prior_verify'];
    $verifiedController=$authority->runOperation('verifying_prior','prior_verify',1,$controllerVerify,'2020-02-01T00:00:06Z','2020-02-01T00:00:07Z');
    checkpoint_ok(($verifiedController['execution']['ok']??false)===true,'fresh SSH controller executes and completes exact prior-world verification');
    $authority->append('rolled_back','state_transition','controller-rolled-back',1,'controller-worker',$hash('controller-rolled-back'),str_repeat('0',64),'2020-02-01T00:00:08Z');
    $controllerDeleted=$authority->deleteCheckpoint('2020-02-03T00:00:00Z');checkpoint_ok(($controllerDeleted['ok']??false)===true&&($controllerDeleted['format']??'')==='duo-checkpoint-tombstone/v1','controller signs terminal retention deletion and receives the exact tombstone');

    // DUO-3344: checkpoint-only scoped promotion deliberately configures no
    // code/upload/effect provider.  The independent receipt remains fully
    // recoverable across controller response loss and target-plan drift.
    $scopedHost = $tmp . '/scoped-controller-host';
    $scopedRoot = $scopedHost . '/.duo/control';
    RollbackControl::initialize($scopedRoot);
    RollbackControl::installPublicKey($scopedRoot, $keyId, base64_encode($public));
    $scopedRuntime = $scopedRoot . '/recovery-runtime';
    mkdir($scopedRuntime, 0700);
    foreach (['CanonicalJson.php','AtomicStore.php','ProtocolLock.php','ProviderClient.php','rollback-control.php','RecoveryExecutor.php','CheckpointBundle.php','CodeRelease.php','UploadBundle.php','EffectBundle.php'] as $runtimeFile) {
        copy(dirname(__DIR__, 2) . '/recovery/' . $runtimeFile, $scopedRuntime . '/' . $runtimeFile);
    }
    $scopedProviderState = $tmp . '/scoped-provider-state';
    $scopedExclusionState = $tmp . '/scoped-exclusion.json';
    $scopedConfig = $config;
    $scopedConfig['checkpoint_provider'] = [PHP_BINARY, $provider, $scopedProviderState, $dbSource, $kmsKey];
    $scopedConfig['exclusion_provider'] = [PHP_BINARY, $exclusion, $scopedExclusionState];
    $scopedConfigPath = $tmp . '/scoped-config.json';
    checkpoint_write($scopedConfigPath, RollbackControl::canonical($scopedConfig) . "\n");
    RecoveryExecutor::configureFromFile($scopedRoot, $scopedConfigPath);
    $scopedTransport = new SshTransport('scoped-checkpoint-controller', [
        'transport' => 'ssh',
        'host' => 'fixture-host',
        'wp_path' => $tmp . '/unused-wordpress',
        'repo_path' => $scopedHost,
        'rollback_key_id' => $keyId,
        'rollback_signing_key' => $signingPath,
        'rollback_recovery' => [
            'adapters' => $commands,
            'checkpoint_provider' => [PHP_BINARY, $provider, $scopedProviderState, $dbSource, $kmsKey],
            'exclusion_provider' => [PHP_BINARY, $exclusion, $scopedExclusionState],
            'timeout_seconds' => 3,
        ],
        'verified_rollback' => [
            'claim_ttl_seconds' => 90,
            'encryption_key_id' => 'kms-scoped-fixture',
            'retention_seconds' => 3600,
        ],
    ]);
    $scopeHash = $hash('scoped-contract');
    $scopedArtifact = $hash('scoped-artifact');
    $scopedPlan = checkpoint_scoped_plan($scopedArtifact, $scopeHash, 'original');
    $scopedSelection = ScopedRollbackProfile::select($scopedTransport, $scopedPlan, $scopeHash);
    checkpoint_ok(($scopedSelection['automatic'] ?? false) === true,
        'checkpoint-only scoped profile is ready without code/upload/effect providers');
    $scopedProfile = new ScopedRollbackProfile($scopedTransport);
    $scopedOwner = 'scoped:fixture-owner';
    $scopedClaimant = 'scoped-fixture-worker';
    $scopedClaim = $scopedProfile->claim(
        $scopedPlan, $scopeHash, $scopedOwner, $scopedClaimant, '2020-03-01T00:00:00Z'
    );
    $scopedReceipt = (array) $scopedClaim['receipt'];
    checkpoint_ok(($scopedReceipt['format'] ?? '') === RollbackControl::SCOPED_PROMOTION_RECEIPT_FORMAT
        && ($scopedReceipt['allow_deletes'] ?? null) === false
        && !array_key_exists('code_release_metadata_sha256', $scopedReceipt)
        && !array_key_exists('uploads_inventory_sha256', $scopedReceipt)
        && !array_key_exists('lifecycle_receipts_sha256', $scopedReceipt)
        && hash_equals(
            hash('sha256', RollbackControl::canonical($scopedReceipt)),
            (string) ($scopedClaim['receipt_payload_sha256'] ?? '')
        ), 'scoped claim publishes only the hash-bound checkpoint receipt and canonical payload hash');

    $changedPlan = checkpoint_scoped_plan($scopedArtifact, $scopeHash, 'after-apply');
    $changedPlan['selected_surfaces'] = ['option:after-apply'];
    $resumedClaim = $scopedProfile->claim($changedPlan, $scopeHash, $scopedOwner, $scopedClaimant);
    checkpoint_ok(($resumedClaim['receipt_payload_sha256'] ?? '') === ($scopedClaim['receipt_payload_sha256'] ?? '')
        && ($resumedClaim['status']['generation'] ?? 0) === 1,
        'active scoped retry ignores changed mutable plan witnesses and resumes the exact receipt generation');
    $resumeOnlyPlan = $changedPlan;
    $resumeOnlyPlan['selected_actions'] = [['type' => 'post-apply-plan-drift']];
    checkpoint_ok((ScopedRollbackProfile::select($scopedTransport, $resumeOnlyPlan, $scopeHash)['automatic'] ?? false) === true,
        'active scoped profile selection bypasses fresh-plan-only witness validation during recovery');
    checkpoint_refuses(fn() => $scopedProfile->claim($changedPlan, $hash('different-scope'), $scopedOwner, $scopedClaimant),
        'active scoped retry refuses a different scope intent');
    $wrongArtifactPlan = $changedPlan;
    $wrongArtifactPlan['artifact_hash'] = $hash('different-artifact');
    checkpoint_refuses(fn() => $scopedProfile->claim($wrongArtifactPlan, $scopeHash, $scopedOwner, $scopedClaimant),
        'active scoped retry refuses a different artifact intent');
    checkpoint_refuses(fn() => $scopedProfile->claim($changedPlan, $scopeHash, 'scoped:other-owner', $scopedClaimant),
        'active scoped retry refuses a different owner intent');
    checkpoint_refuses(fn() => $scopedProfile->claim($changedPlan, $scopeHash, $scopedOwner, 'scoped-other-worker'),
        'active scoped retry refuses a different claimant intent');
    checkpoint_refuses(
        fn() => $scopedProfile->claim(
            $changedPlan,
            $scopeHash,
            $scopedOwner,
            $scopedClaimant,
            null,
            true
        ),
        'active no-delete scoped receipt refuses a widened delete capability'
    );

    $scopedProfile->startPromotion();
    checkpoint_ok(($scopedProfile->startPromotion()['state'] ?? '') === 'promoting',
        'scoped promotion start is state-idempotent');
    $promotingWitness = RecoveryExecutor::scopedPromotionWitness($scopedRoot);
    checkpoint_ok(($promotingWitness['format'] ?? '') === 'duo-scoped-promotion-witness/v1'
        && ($promotingWitness['state'] ?? '') === 'promoting'
        && ($promotingWitness['terminal'] ?? null) === false
        && ($promotingWitness['allow_deletes'] ?? null) === false
        && ($promotingWitness['exclusion_state'] ?? '') === 'held'
        && ($promotingWitness['receipt_payload_sha256'] ?? '') === ($scopedClaim['receipt_payload_sha256'] ?? '')
        && !array_key_exists('claimant', $promotingWitness)
        && !array_key_exists('token', $promotingWitness)
        && !array_key_exists('control_root', $promotingWitness),
        'target witness re-verifies the signed scoped generation and live database-writer exclusion without publishing secrets');
    $scopedAuthority = new RollbackAuthority($scopedTransport);
    $beforeScopedGate = RollbackAuthority::scopedStatus($scopedTransport);
    checkpoint_refuses(fn() => $scopedAuthority->appendScoped(
        'verifying_new', 'state_transition', 'scoped_fresh_verification', 1, $scopedClaimant,
        $hash('missing-scoped-apply-input'), $hash('missing-scoped-apply-result')
    ), 'target scoped receipt refuses verification/commit transition without completed scoped_apply evidence');
    checkpoint_refuses(fn() => $scopedAuthority->prepareScopedOperation(
        'promoting', 'code_restore', 1, ['operation' => 'code_restore']
    ), 'target scoped receipt refuses an ordinary code operation before executor authorization');
    checkpoint_ok(RollbackControl::canonical($beforeScopedGate) === RollbackControl::canonical(RollbackAuthority::scopedStatus($scopedTransport)),
        'scoped target-gate refusals preserve the exact signed authority head');
    checkpoint_refuses(fn() => $scopedProfile->sealCommit(),
        'profile commit seal refuses absent scoped_apply evidence without state mutation');
    checkpoint_ok(($scopedProfile->status()['state'] ?? '') === 'promoting',
        'absent scoped_apply leaves scoped promotion in its retryable promoting state');

    $scopedTerminal = checkpoint_scoped_terminal('first');
    $scopedProfile->recordScopedApply($scopedTerminal);
    checkpoint_ok(($scopedProfile->recordScopedApply($scopedTerminal)['state'] ?? '') === 'promoting',
        'exact scoped apply receipt recording is operation-idempotent');
    checkpoint_refuses(fn() => $scopedProfile->recordScopedApply(checkpoint_scoped_terminal('different')),
        'completed scoped apply evidence refuses a mismatched terminal receipt');

    // Model a controller loss after the first durable seal event: the target
    // has accepted fresh-world verification, but the controller has not yet
    // observed the following committed transition.  This boundary resumes
    // only forward; checkpoint restoration would undo a verified Apply.
    $firstSeal = $scopedAuthority->appendScoped(
        'verifying_new', 'state_transition', 'scoped_fresh_verification', 1, $scopedClaimant,
        $hash('scoped_fresh_verification:input'), $hash('scoped_fresh_verification:result')
    );
    checkpoint_ok(($firstSeal['state'] ?? '') === 'verifying_new' && empty($firstSeal['terminal']),
        'durable first scoped seal transition leaves a resumable forward-only verification boundary');
    checkpoint_refuses(fn() => $scopedProfile->rollback(),
        'fresh-verified scoped promotion refuses automatic checkpoint rollback after controller response loss');
    $firstSealHead = RollbackAuthority::scopedStatus($scopedTransport);
    $rawScopedRollback = checkpoint_event(
        $scopedReceipt,
        $firstSealHead,
        'rollback_pending',
        'state_transition',
        'scoped_promotion_failed',
        $scopedClaimant,
        gmdate('Y-m-d\\TH:i:s\\Z', time() + 60),
        $hash('scoped-forward-only-rollback-input'),
        str_repeat('0', 64)
    );
    checkpoint_refuses(
        fn() => checkpoint_authority_submit($scopedRoot, $rawScopedRollback, null, $keyId, $secret),
        'raw signed scoped rollback transition is refused after durable fresh verification'
    );
    $afterRawRefusal = RollbackAuthority::scopedStatus($scopedTransport);
    checkpoint_ok(
        RollbackControl::canonical($firstSealHead) === RollbackControl::canonical($afterRawRefusal)
            && !is_file($scopedProviderState . '.restore.log')
            && (RollbackAuthority::status($scopedTransport)['exclusion_state'] ?? '') === 'held',
        'forward-only raw refusal preserves the signed head and performs no restore or exclusion release'
    );
    $sealed = $scopedProfile->sealCommit();
    checkpoint_ok(($sealed['state'] ?? '') === 'committed'
        && (RollbackAuthority::status($scopedTransport)['exclusion_state'] ?? '') === 'held',
        'sealCommit commits only after exact scoped_apply while the independent exclusion remains held');
    checkpoint_ok(($scopedProfile->sealCommit()['state'] ?? '') === 'committed',
        'scoped commit sealing is idempotent after a lost response');
    $committedWitness = RecoveryExecutor::scopedPromotionWitness($scopedRoot);
    checkpoint_ok(($committedWitness['state'] ?? '') === 'committed'
        && ($committedWitness['terminal'] ?? null) === true
        && ($committedWitness['exclusion_state'] ?? '') === 'held'
        && ($committedWitness['receipt_payload_sha256'] ?? '') === ($scopedClaim['receipt_payload_sha256'] ?? ''),
        'delayed terminal completion retains an exact signed held-exclusion witness after the original claim TTL');
    $heldTerminalResume = $scopedProfile->claim($changedPlan, $scopeHash, $scopedOwner, $scopedClaimant);
    checkpoint_ok(($heldTerminalResume['receipt_payload_sha256'] ?? '') === ($scopedClaim['receipt_payload_sha256'] ?? '')
        && ($heldTerminalResume['status']['exclusion_state'] ?? '') === 'held',
        'terminal scoped receipt with held exclusion resumes instead of minting a second generation');
    checkpoint_refuses(fn() => $scopedProfile->claim($changedPlan, $scopeHash, 'scoped:other-owner', $scopedClaimant),
        'terminal held scoped receipt refuses a mismatched immutable intent');

    // Simulate a release that reached the target but whose controller response
    // was lost.  The subsequent profile release must observe the durable
    // released record and converge idempotently.
    $sealedTarget = RollbackControl::status($scopedRoot);
    checkpoint_exclusion_submit($scopedRoot, [
        'action' => 'release',
        'artifact_hash' => (string) $sealedTarget['artifact_hash'],
        'claim_epoch' => (int) $sealedTarget['claim_epoch'],
        'claimant' => (string) $sealedTarget['claimant'],
        'format' => 'duo-exclusion-request/v1',
        'generation' => (int) $sealedTarget['generation'],
        'owner' => (string) $sealedTarget['owner'],
        'receipt_id' => (string) $sealedTarget['receipt_id'],
        'target_id' => (string) $sealedTarget['target_id'],
        'timestamp' => '2020-03-01T00:00:10Z',
    ], $keyId, $secret);
    checkpoint_ok(($scopedProfile->release()['state'] ?? '') === 'committed',
        'lost terminal release response retries against the durable released exclusion record');
    $nextScopedClaim = $scopedProfile->claim(
        $scopedPlan, $scopeHash, $scopedOwner, $scopedClaimant, '2020-03-02T00:00:00Z', true
    );
    checkpoint_ok(($nextScopedClaim['status']['generation'] ?? 0) === 2
        && ($nextScopedClaim['receipt']['allow_deletes'] ?? null) === true
        && ($nextScopedClaim['receipt_payload_sha256'] ?? '') !== ($scopedClaim['receipt_payload_sha256'] ?? ''),
        'terminal scoped receipt with released exclusion advances to a newly signed delete capability');
    $scopedProfile->startPromotion();
    $scopedRolledBack = $scopedProfile->rollback();
    checkpoint_ok(($scopedRolledBack['state'] ?? '') === 'rolled_back'
        && (RollbackAuthority::status($scopedTransport)['exclusion_state'] ?? '') === 'released',
        'checkpoint-only scoped rollback restores and prior-verifies through no code/upload/effect provider');
    checkpoint_ok(($scopedProfile->rollback()['state'] ?? '') === 'rolled_back',
        'terminal scoped rollback release is idempotent after a lost response');

    // An independently prepared operation is never enough to seal success.
    // This separate root remains intentionally nonterminal after the refusal.
    $openHost = $tmp . '/scoped-open-controller-host';
    $openRoot = $openHost . '/.duo/control';
    RollbackControl::initialize($openRoot);
    RollbackControl::installPublicKey($openRoot, $keyId, base64_encode($public));
    $openRuntime = $openRoot . '/recovery-runtime';
    mkdir($openRuntime, 0700);
    foreach (['CanonicalJson.php','AtomicStore.php','ProtocolLock.php','ProviderClient.php','rollback-control.php','RecoveryExecutor.php','CheckpointBundle.php','CodeRelease.php','UploadBundle.php','EffectBundle.php'] as $runtimeFile) {
        copy(dirname(__DIR__, 2) . '/recovery/' . $runtimeFile, $openRuntime . '/' . $runtimeFile);
    }
    $openProviderState = $tmp . '/scoped-open-provider-state';
    $openExclusionState = $tmp . '/scoped-open-exclusion.json';
    $openConfig = $config;
    $openConfig['checkpoint_provider'] = [PHP_BINARY, $provider, $openProviderState, $dbSource, $kmsKey];
    $openConfig['exclusion_provider'] = [PHP_BINARY, $exclusion, $openExclusionState];
    $openConfigPath = $tmp . '/scoped-open-config.json';
    checkpoint_write($openConfigPath, RollbackControl::canonical($openConfig) . "\n");
    RecoveryExecutor::configureFromFile($openRoot, $openConfigPath);
    $openTransport = new SshTransport('scoped-open-controller', [
        'transport' => 'ssh', 'host' => 'fixture-host', 'wp_path' => $tmp . '/unused-wordpress',
        'repo_path' => $openHost, 'rollback_key_id' => $keyId, 'rollback_signing_key' => $signingPath,
        'rollback_recovery' => [
            'adapters' => $commands,
            'checkpoint_provider' => [PHP_BINARY, $provider, $openProviderState, $dbSource, $kmsKey],
            'exclusion_provider' => [PHP_BINARY, $exclusion, $openExclusionState], 'timeout_seconds' => 3,
        ],
        'verified_rollback' => [
            'claim_ttl_seconds' => 90, 'encryption_key_id' => 'kms-scoped-fixture', 'retention_seconds' => 3600,
        ],
    ]);
    $openProfile = new ScopedRollbackProfile($openTransport);
    $openProfile->claim($scopedPlan, $scopeHash, $scopedOwner, $scopedClaimant, '2020-03-03T00:00:00Z');
    $openProfile->startPromotion();
    $openAuthority = new RollbackAuthority($openTransport);
    $openAuthority->prepareScopedOperation('promoting', 'scoped_apply', 1, ['format' => 'duo-open-scoped-apply/v1']);
    checkpoint_refuses(fn() => $openProfile->sealCommit(),
        'profile commit seal refuses an open scoped_apply operation without state mutation');
    checkpoint_ok(($openProfile->status()['state'] ?? '') === 'promoting'
        && (RollbackAuthority::scopedStatus($openTransport)['open_operations'] ?? 0) === 1,
        'open scoped_apply remains retryable and cannot be mistaken for a completed terminal receipt');

    echo "PASS: encrypted checkpoint bundle + verified restore regression\n";
} finally { sodium_memzero($secret); checkpoint_remove_tree($tmp); }
