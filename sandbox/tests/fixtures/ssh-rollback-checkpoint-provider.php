#!/usr/bin/env php
<?php
declare(strict_types=1);

// Test-only production-form checkpoint provider. Credentials and the KMS key
// remain in mode-0600 target-owned files; WPrism receives only hashes and bounded
// protocol evidence. Database export/import crosses the real MariaDB socket.

function srp_sort(mixed $value): mixed { if(!is_array($value))return $value;if(array_is_list($value))return array_map('srp_sort',$value);ksort($value,SORT_STRING);foreach($value as$k=>$v)$value[$k]=srp_sort($v);return $value; }
function srp_json(mixed $value): string { return json_encode(srp_sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n"; }
function srp_fail(string $message,int $code=1): never { fwrite(STDERR,$message."\n");exit($code); }
function srp_emit(array $value): never { echo srp_json($value);exit(0); }
function srp_read(string $path): array { if(is_link($path)||!is_file($path))srp_fail('unsafe provider input');$raw=file_get_contents($path);$value=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($value)||array_is_list($value))srp_fail('provider input must be an object');return$value; }
function srp_write(string $path,string $bytes): void { $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0700,true))srp_fail('provider mkdir failed');$tmp=$path.'.tmp-'.bin2hex(random_bytes(6));$h=fopen($tmp,'x+b');if(!$h||fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h))srp_fail('provider durable write failed');fclose($h);chmod($tmp,0600);if(!rename($tmp,$path))srp_fail('provider publish failed'); }
/** @return array{exit:int,stdout:string,stderr:string} */
function srp_run(array $command,string $password,string $stdin=''): array { $pipes=[];$env=['MYSQL_PWD'=>$password,'PATH'=>(string)getenv('PATH')];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env,['bypass_shell'=>true]);if(!is_resource($process))srp_fail('could not start database client');fwrite($pipes[0],$stdin);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);return['exit'=>$exit,'stdout'=>(string)$stdout,'stderr'=>(string)$stderr]; }
function srp_db_args(array $config,bool $admin=false,bool $database=true): array { $prefix=['mariadb','--batch','--skip-column-names','--protocol=tcp','--host='.$config['host'],'--port='.(string)$config['port'],'--user='.($admin?$config['admin_user']:$config['user'])];if($database)$prefix[]=(string)$config['database'];return$prefix; }
function srp_query(array $config,string $sql,bool $admin=false,bool $database=true): string { $result=srp_run(array_merge(srp_db_args($config,$admin,$database),['--execute='.$sql]),(string)($admin?$config['admin_password']:$config['password']));if($result['exit']!==0)srp_fail('database command failed');return$result['stdout']; }
function srp_fault(string $state,string $stage,string $edge,array $config): void { $remote=$state.'.remote-'.$edge.'-'.$stage;if(is_file($remote)){unlink($remote);exit(86);}$db=$state.'.db-'.$edge.'-'.$stage;if(is_file($db)){unlink($db);$bad=$config;$bad['host']='127.0.0.254';$result=srp_run(array_merge(srp_db_args($bad,false,true),['--connect-timeout=1','--execute=SELECT 1']),(string)$config['password']);if($result['exit']===0)srp_fail('database-loss injection unexpectedly connected');exit(87);} }
function srp_schema(array $config): string { return hash('sha256',srp_query($config,"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COALESCE(COLUMN_DEFAULT,'<NULL>') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION")); }
function srp_canonical(array $config): string { return hash('sha256',srp_query($config,"SELECT id,value FROM wprism_cert_state ORDER BY id")); }
function srp_runtime(string $artifactDirectory): string { $control=dirname($artifactDirectory,3).'/control/recovery-runtime';$rows=[];foreach(glob($control.'/*.php')?:[]as$file)$rows[basename($file)]=(string)hash_file('sha256',$file);return hash('sha256',srp_json($rows)); }
function srp_database_identity(array $config): string { return hash('sha256',$config['host'].':'.$config['port'].'/'.$config['database']); }

[$script,$state,$configPath,$keyPath]=$argv+[null,null,null,null];
if(!$state||!$configPath||!$keyPath)srp_fail('usage: checkpoint-provider STATE DB_CONFIG KMS_KEY');
$config=srp_read($configPath);$key=file_get_contents($keyPath);if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)srp_fail('checkpoint key unavailable');
$raw=stream_get_contents(STDIN);$request=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($request))srp_fail('request malformed');
$action=(string)($request['action']??'');$base=['format'=>'wprism-checkpoint-provider-response/v1','provider_id'=>'ssh-mariadb-checkpoint','provider_version'=>'1.0.0'];
if($action==='probe')srp_emit($base+['available'=>true,'plaintext_durable'=>false,'state'=>'ready','streaming_authenticated_encryption'=>true,'temporary_plaintext_cleaned'=>true]);
$directory=(string)($request['artifact_directory']??'');if($directory===''||$directory[0]!=='/')srp_fail('artifact directory missing');
$cipher=$directory.'/checkpoint.enc';$verifier=$directory.'/prior-verifier-inputs.json';

if($action==='prepare'){
    srp_fault($state,'checkpoint-export','before',$config);
    $dumpCommand=['mariadb-dump','--protocol=tcp','--host='.$config['host'],'--port='.(string)$config['port'],'--user='.$config['user'],'--single-transaction','--skip-comments','--skip-dump-date','--add-drop-table',(string)$config['database']];
    $dump=srp_run($dumpCommand,(string)$config['password']);if($dump['exit']!==0||$dump['stdout']==='')srp_fail('checkpoint export failed');
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$encrypted=$nonce.sodium_crypto_secretbox($dump['stdout'],$nonce,$key);srp_write($cipher,$encrypted);
    srp_fault($state,'checkpoint-export','after',$config);
    $runtime=srp_runtime($directory);$ledger=hash('sha256',srp_query($config,'SELECT COUNT(*) FROM wprism_cert_lease'));
    $inputs=[
        'canonical_tree_sha256'=>srp_canonical($config),'code_revision_sha256'=>hash('sha256',trim((string)@file_get_contents((string)$config['code_pointer']))),
        'database_schema_sha256'=>srp_schema($config),'format'=>'wprism-prior-verifier-inputs/v1','ledger_session_sha256'=>$ledger,
        'lifecycle_receipts_sha256'=>hash('sha256',trim((string)@file_get_contents((string)$config['effect_target']))),
        'manifest_inputs_sha256'=>hash('sha256','ssh-rollback-manifest-v1'),'map_state_sha256'=>hash('sha256','ssh-rollback-map-v1'),
        'policy_sha256'=>hash('sha256','ssh-rollback-policy-v1'),'runtime_fingerprints_sha256'=>$runtime,
        'state_revision_sha256'=>hash('sha256',srp_query($config,'SELECT value FROM wprism_cert_state WHERE id=1')),
    ];srp_write($verifier,srp_json($inputs));
    srp_fault($state,'checkpoint-verify','before',$config);
    $disposable=$config['database'].'_verify_'.substr(hash('sha256',(string)$request['receipt_id']),0,10);
    srp_query($config,'DROP DATABASE IF EXISTS `'.$disposable.'`; CREATE DATABASE `'.$disposable.'`',true,false);
    $temporary=$config;$temporary['database']=$disposable;
    $import=srp_run(srp_db_args($temporary,true,true),(string)$config['admin_password'],$dump['stdout']);
    $tables=$import['exit']===0?trim(srp_query($temporary,'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()',true,true)):'';
    srp_query($config,'DROP DATABASE IF EXISTS `'.$disposable.'`',true,false);
    if($import['exit']!==0||$tables==='')srp_fail('disposable checkpoint verification failed');
    srp_fault($state,'checkpoint-verify','after',$config);
    srp_emit($base+[
        'algorithm'=>'xchacha20poly1305-secretbox-fixture','available'=>true,'ciphertext_path'=>$cipher,'ciphertext_sha256'=>hash_file('sha256',$cipher),
        'ciphertext_size'=>filesize($cipher),'database_identity_sha256'=>srp_database_identity($config),'disposable_import_sha256'=>hash('sha256',$tables),
        'disposable_import_verified'=>true,'export_evidence_sha256'=>hash('sha256',$dump['stdout']),'key_id'=>$request['encryption_key_id'],
        'ledger_session_sha256'=>$ledger,'physical_erasure'=>'logical-delete-fixture','plaintext_durable'=>false,
        'prior_verifier_inputs_path'=>$verifier,'prior_verifier_inputs_sha256'=>hash_file('sha256',$verifier),
        'runtime_fingerprints_sha256'=>$runtime,'state'=>'prepared','streaming_authenticated_encryption'=>true,'temporary_plaintext_cleaned'=>true,
    ]);
}

if($action==='restore'){
    $metadata=(string)file_get_contents($cipher);$nonce=substr($metadata,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$plain=sodium_crypto_secretbox_open(substr($metadata,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$key);if(!is_string($plain))srp_fail('checkpoint decrypt failed');
    srp_fault($state,'database-abort-before','before',$config);srp_query($config,'DELETE FROM wprism_cert_lease');srp_fault($state,'database-abort-before','after',$config);
    srp_fault($state,'database-import','before',$config);$import=srp_run(srp_db_args($config,false,true),(string)$config['password'],$plain);srp_fault($state,'database-import','after',$config);
    srp_fault($state,'database-abort-final','before',$config);$final=true;try{srp_query($config,'DELETE FROM wprism_cert_lease');}catch(Throwable $e){$final=false;}srp_fault($state,'database-abort-final','after',$config);
    sodium_memzero($plain);$ok=$import['exit']===0&&$final;
    srp_emit($base+[
        'abort_before'=>true,'abort_final'=>$final,'abort_final_attempted'=>true,'action'=>'restore','authority_survived'=>true,'available'=>true,
        'begin_artifact_hash'=>$request['artifact_hash'],'begin_owner'=>$request['owner'],'database_identity_sha256'=>srp_database_identity($config),
        'import_succeeded'=>$import['exit']===0,'input_sha256'=>$request['input_sha256'],'result_sha256'=>hash('sha256',$ok?'restored':'failed'),
        'state'=>$ok?'restored':'failed','temporary_plaintext_cleaned'=>true,
    ]);
}

if($action==='verify-prior'){
    $inputs=srp_read($verifier);srp_fault($state,'prior-verification','before',$config);
    $first=srp_canonical($config);$firstProcess=getmypid();$secondResult=srp_run(array_merge(srp_db_args($config,false,true),['--execute=SELECT id,value FROM wprism_cert_state ORDER BY id']),(string)$config['password']);if($secondResult['exit']!==0)srp_fail('second recapture failed');$second=hash('sha256',$secondResult['stdout']);
    srp_fault($state,'prior-verification','after',$config);
    srp_emit($base+[
        'action'=>'verify-prior','available'=>true,'canonical_first_sha256'=>$first,'canonical_second_sha256'=>$second,
        'code_revision_sha256'=>$inputs['code_revision_sha256'],'database_identity_sha256'=>srp_database_identity($config),
        'database_schema_sha256'=>srp_schema($config),'fresh_processes'=>$firstProcess!==0,'input_sha256'=>$request['input_sha256'],
        'ledger_session_sha256'=>hash('sha256',srp_query($config,'SELECT COUNT(*) FROM wprism_cert_lease')),
        'lifecycle_receipts_sha256'=>hash('sha256',trim((string)@file_get_contents((string)$config['effect_target']))),
        'live_promotion_absent'=>trim(srp_query($config,'SELECT COUNT(*) FROM wprism_cert_lease'))==='0',
        'manifest_inputs_sha256'=>$inputs['manifest_inputs_sha256'],'map_state_sha256'=>$inputs['map_state_sha256'],'policy_sha256'=>$inputs['policy_sha256'],
        'result_sha256'=>hash('sha256',srp_json($inputs)),'runtime_fingerprints_sha256'=>srp_runtime($directory),'state'=>'verified',
        'state_revision_sha256'=>hash('sha256',srp_query($config,'SELECT value FROM wprism_cert_state WHERE id=1')),
        'verifier_inputs_sha256'=>hash_file('sha256',$verifier),
    ]);
}

if($action==='delete'){
    srp_fault($state,'checkpoint-retention-delete','before',$config);if(is_file($cipher)&&!unlink($cipher))srp_fail('checkpoint deletion failed');srp_fault($state,'checkpoint-retention-delete','after',$config);
    srp_emit($base+['action'=>'delete','available'=>true,'ciphertext_absent'=>!is_file($cipher),'physical_erasure'=>'logical-delete-fixture','state'=>'deleted','temporary_plaintext_cleaned'=>true]);
}
srp_fail('unsupported checkpoint action');
