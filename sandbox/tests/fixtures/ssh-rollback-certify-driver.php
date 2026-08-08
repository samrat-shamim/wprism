#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/recovery/rollback-control.php';
require dirname(__DIR__, 3) . '/cli/src/Transport.php';
require dirname(__DIR__, 3) . '/cli/src/SshTransport.php';
require dirname(__DIR__, 3) . '/cli/src/RollbackAuthority.php';
require dirname(__DIR__, 2) . '/bin/ssh-rollback-certification.php';

use Duo\Orchestrator\RollbackAuthority;
use Duo\Orchestrator\SshTransport;
use Duo\Recovery\RollbackControl;

function srd_fail(string $message): never { fwrite(STDERR,"FAIL: $message\n");exit(1); }
function srd_hash(string $value): string { return hash('sha256',$value); }
/** @return array<string,mixed> */
function srd_read(string $path): array { $value=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!is_array($value)||array_is_list($value))srd_fail("invalid JSON $path");return$value; }
function srd_remote(SshTransport $transport,string $script,string $label): array { $result=$transport->captureRaw($script);if($result['exit']!==0)srd_fail("$label: ".trim($result['stderr']!==''?$result['stderr']:$result['stdout']));return$result; }
function srd_wp(SshTransport $transport,array $args,string $label): array { $result=$transport->captureWp($args);if($result['exit']!==0)srd_fail("$label: ".trim($result['stderr']!==''?$result['stderr']:$result['stdout']));return$result; }
function srd_time(?int $minimum=null): string { static $second=0;$base=strtotime('2020-01-01T00:00:00Z');if($minimum!==null)$second=max($second,$minimum-$base);return gmdate('Y-m-d\TH:i:s\Z',$base+$second++); }
/** @return array{exit:int,pid:int,stdout:string,stderr:string} */
function srd_process(array $command): array {
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))srd_fail('could not start certification child process');
    $status=proc_get_status($process);$pid=(int)($status['pid']??0);fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return['exit'=>proc_close($process),'pid'=>$pid,'stdout'=>(string)$stdout,'stderr'=>(string)$stderr];
}
/** @return array{exit:int,pid:int,stdout:string,stderr:string} */
function srd_controller_kill(string $envPath): array { return srd_process([PHP_BINARY,__FILE__,'controller-kill',$envPath]); }

/** @param array<string,array{process_id:string,sha256:string,state:string}> $faultEvidence */
function srd_inject(string $boundary,string $edge,SshTransport $transport,string $dbProbe,string $envPath,array &$faultEvidence): void {
    $probes=[
        'controller-process-kill'=>srd_controller_kill($envPath),
        'remote-command-kill'=>$transport->captureRaw('printf "%s\\n" "$$"; kill -9 $$'),
        'database-connection-loss'=>$transport->captureRaw($dbProbe),
    ];
    foreach($probes as$failure=>$result){
        if(($result['exit']??0)===0)srd_fail("$boundary $edge $failure did not fail");
        $pid=$failure==='controller-process-kill'?(int)($result['pid']??0):(int)trim((string)($result['stdout']??''));
        if($pid<1)srd_fail("$boundary $edge $failure did not bind an actual process id");
        $status=RollbackAuthority::status($transport);
        if(($status['ok']??false)!==true)srd_fail("$boundary $edge $failure corrupted authority status");
        if(($status['exclusion_state']??'')!=='held')srd_fail("$boundary $edge $failure reopened exclusion");
        $state=(string)($status['state']??'none');
        $faultEvidence["$boundary--$failure--$edge"]=['process_id'=>'pid-'.$pid,'sha256'=>srd_hash(RollbackControl::canonical([
            'exit'=>$result['exit'],'failure'=>$failure,'head'=>$status['head_event_sha256']??null,
            'pid'=>$pid,'state'=>$state,'stderr_sha256'=>srd_hash((string)$result['stderr']),
            'target_id'=>$status['target_id']??null,
        ])),'state'=>$state];
    }
}

/** @param callable():void $operation @param array<string,array{process_id:string,sha256:string,state:string}> $faultEvidence */
function srd_boundary(string $boundary,SshTransport $transport,string $dbProbe,string $envPath,array &$faultEvidence,callable $operation): void {
    srd_inject($boundary,'before',$transport,$dbProbe,$envPath,$faultEvidence);
    $operation();
    srd_inject($boundary,'after',$transport,$dbProbe,$envPath,$faultEvidence);
}

function srd_transition(RollbackAuthority $authority,SshTransport $transport,string $state,string $id): void {
    $status=RollbackAuthority::status($transport);$authority->append($state,'state_transition',$id,1,(string)$status['claimant'],srd_hash($id.':input'),str_repeat('0',64),srd_time());
}
function srd_marker(RollbackAuthority $authority,SshTransport $transport,string $state,string $id): void {
    $status=RollbackAuthority::status($transport);$input=srd_hash($id.':input');$authority->append($state,'prepared',$id,1,(string)$status['claimant'],$input,str_repeat('0',64),srd_time());$status=RollbackAuthority::status($transport);$authority->append($state,'completed',$id,1,(string)$status['claimant'],$input,srd_hash($id.':result'),srd_time());
}

/** @return list<array<string,mixed>> */
function srd_effect_inventory(): array {
    return [
        ['effect'=>['adapter'=>['id'=>'fixture-file','inverse'=>'restore-bytes','inverse_inputs'=>['path','prior_sha256'],'verifier'=>'fresh-readback','verifier_inputs'=>['path','prior_sha256'],'version'=>'1.0.0'],'id'=>'lifecycle-file','kind'=>'filesystem','mode'=>'reversible','selector'=>['scope'=>'external','type'=>'path','value'=>'wp-content/uploads/duo-rollback-effect.txt']],'manifest'=>'rollback-fixture','phase'=>'lifecycle','source'=>'lifecycle_effects'],
        ['effect'=>['id'=>'rebuild-table','kind'=>'database','mode'=>'restorable','selector'=>['scope'=>'database_checkpoint','type'=>'table','value'=>'duo_cert_state']],'manifest'=>'rollback-fixture','phase'=>'rebuild','source'=>'rebuilders[0].effects'],
        ['effect'=>['id'=>'prevent-http','kind'=>'http','mode'=>'prevented','prevention'=>'receipt_outbox','selector'=>['scope'=>'external','type'=>'url_prefix','value'=>'https://rollback.invalid/hooks/']],'manifest'=>'rollback-fixture','phase'=>'lifecycle','source'=>'lifecycle_effects'],
    ];
}

/** @return array<string,mixed> */
function srd_upload_inventory(array $fixture): array {
    return [[
        'attachment_uuid'=>'11111111-1111-4111-8111-111111111111','derivative_basename_prefix'=>'photo-',
        'derivative_directory'=>'2026/08','media_blob'=>$fixture['media_blob'],'original_path'=>'2026/08/photo.jpg',
        'original_sha256'=>$fixture['media_sha256'],
    ]];
}

/** @return array<string,mixed> */
function srd_claim_fields(SshTransport $transport,array $fixture,int $generation,string $created): array {
    $file=$fixture['release_root'].'/release-desired-'.$generation.'/wp-content/plugins/acme/acme.php';
    $fileHash=trim(srd_remote($transport,'sha256sum '.escapeshellarg($file)." | cut -d' ' -f1",'desired release hash')['stdout']);
    $artifact=srd_hash('artifact-generation-'.$generation);$revision=srd_hash('code-generation-'.$generation);
    $descriptor=[
        'artifact_hash'=>$artifact,'code_revision'=>$revision,
        'files'=>[['path'=>'wp-content/plugins/acme','sha256'=>srd_hash(''),'type'=>'directory'],['path'=>'wp-content/plugins/acme/acme.php','sha256'=>$fileHash,'type'=>'file']],
        'format'=>'duo-code-release-descriptor/v1','generation'=>$generation,'owned_roots'=>['wp-content/plugins/acme'],
        'release_id'=>'release-desired-'.$generation,'role'=>'desired',
    ];
    return [
        'adapter_versions_sha256'=>srd_hash('ssh-adapters-v1'),'artifact_hash'=>$artifact,'claim_ttl_seconds'=>120,
        'desired_code_revision'=>$revision,'desired_descriptor_sha256'=>srd_hash(RollbackControl::canonical($descriptor)."\n"),
        'effect_inventory'=>srd_effect_inventory(),'encryption_key_id'=>'ssh-kms-fixture','owner'=>'controller:duo-3299',
        'resources_inventory_sha256'=>srd_hash('database-code-uploads-effects-v1'),
        'retention_until'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($created)+86400),'upload_inventory'=>srd_upload_inventory($fixture),
    ];
}

/** @return array<string,mixed> */
function srd_code_input(array $status,string $operation): array { $code=$status['code_release'];return['artifact_hash'=>$status['artifact_hash'],'code_release_metadata_sha256'=>$code['metadata_sha256'],'expected_from_pointer_sha256'=>$operation==='select_desired'?$code['prior_pointer_sha256']:$code['desired_pointer_sha256'],'format'=>'duo-code-release-operation/v1','generation'=>$status['generation'],'operation'=>$operation,'owner'=>$status['owner'],'receipt_id'=>$status['receipt_id'],'target_id'=>$status['target_id']]; }
/** @return array<string,mixed> */
function srd_upload_input(array $status,string $operation): array { $up=$status['uploads'];return['artifact_hash'=>$status['artifact_hash'],'desired_inventory_sha256'=>$up['desired_inventory_sha256'],'format'=>'duo-upload-operation/v1','generation'=>$status['generation'],'operation'=>$operation,'owner'=>$status['owner'],'prior_inventory_sha256'=>$up['prior_inventory_sha256'],'receipt_id'=>$status['receipt_id'],'target_id'=>$status['target_id'],'uploads_inventory_sha256'=>$up['metadata_sha256']]; }
/** @return array<string,mixed> */
function srd_effect_input(array $status): array { $effect=$status['effects'];return['artifact_hash'=>$status['artifact_hash'],'effects_inventory_sha256'=>$effect['effects_inventory_sha256'],'format'=>'duo-effect-operation/v1','generation'=>$status['generation'],'lifecycle_receipts_sha256'=>$effect['metadata_sha256'],'operation'=>'restore_prior','owner'=>$status['owner'],'prior_evidence_sha256'=>$effect['prior_evidence_sha256'],'receipt_id'=>$status['receipt_id'],'target_id'=>$status['target_id']]; }

/** @return array<string,mixed> */
function srd_world(SshTransport $transport,array $fixture): array {
    $query=trim(srd_wp($transport,['db','query','SELECT value FROM duo_cert_state WHERE id=1','--skip-column-names'],'state verification')['stdout']);
    $schema=trim(srd_wp($transport,['db','query',"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION",'--skip-column-names'],'schema verification')['stdout']);
    $code=trim(srd_remote($transport,'cat '.escapeshellarg($fixture['code_pointer']),'code pointer')['stdout']);
    $uploads=srd_remote($transport,'find '.escapeshellarg($fixture['uploads'])." -type f -exec sha256sum {} + | sort",'upload inventory')['stdout'];
    $effect=srd_remote($transport,'sha256sum '.escapeshellarg($fixture['effect_target'])." | cut -d' ' -f1",'effect inventory')['stdout'];
    $runtime=srd_remote($transport,'find '.escapeshellarg($fixture['control_root'].'/recovery-runtime')." -type f -exec sha256sum {} + | sort",'runtime inventory')['stdout'];
    $ledger=trim(srd_wp($transport,['db','query','SELECT COUNT(*) FROM duo_cert_lease','--skip-column-names'],'ledger verification')['stdout']);
    $selected=$fixture['release_root'].'/'.$code;
    $mutable=trim(srd_remote($transport,'find '.escapeshellarg($selected).' -perm -u=w -print -quit','selected release mutability')['stdout'])!=='';
    return['adapter'=>srd_hash('checkpoint:1-code:1-upload:1-effects:1'),'canonical'=>srd_hash($query),'code'=>srd_hash($code),'database'=>srd_hash($query),'effects'=>trim($effect),'ledger'=>srd_hash($ledger),'mutable_checkout'=>$mutable,'runtime'=>srd_hash($runtime),'schema'=>srd_hash($schema),'target'=>srd_hash($query.$code.$uploads),'uploads'=>srd_hash($uploads)];
}

/** @return array<string,mixed> */
function srd_fresh_verifier(string $envPath,string $artifact,string $name,int $generation): array {
    $captures=[];
    for($index=0;$index<2;$index++){
        $result=srd_process([PHP_BINARY,__FILE__,'verify-world',$envPath]);
        if($result['exit']!==0)srd_fail('fresh verifier process failed: '.trim($result['stderr']));
        $capture=json_decode($result['stdout'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($capture)||($capture['pid']??'')!=='pid-'.$result['pid']||!is_array($capture['world']??null))srd_fail('fresh verifier returned invalid process evidence');
        $captures[]=$capture;
    }
    if($captures[0]['pid']===$captures[1]['pid']||$captures[0]['pid']==='pid-'.getmypid()||RollbackControl::canonical($captures[0]['world'])!==RollbackControl::canonical($captures[1]['world']))srd_fail('fresh verifier processes did not produce identical independent recaptures');
    $world=$captures[0]['world'];
    if(($world['mutable_checkout']??true)!==false)srd_fail('fresh verifier resolved a mutable selected release');
    return[
        'adapter_invariants_sha256'=>$world['adapter'],'artifact_sha256'=>$artifact,
        'canonical_first_sha256'=>$world['canonical'],'canonical_second_sha256'=>$world['canonical'],
        'code_inventory_sha256'=>$world['code'],'database_schema_sha256'=>$world['schema'],'fresh_processes'=>true,
        'ledger_session_sha256'=>$world['ledger'],'mutable_checkout'=>$world['mutable_checkout'],
        'process_ids'=>[$captures[0]['pid'],$captures[1]['pid']],
        'report_sha256'=>srd_hash(RollbackControl::canonical($world)),'runtime_fingerprints_sha256'=>$world['runtime'],
        'upload_inventory_sha256'=>$world['uploads'],'world'=>$name,
    ];
}

/** @return array<string,mixed> */
function srd_negative_snapshot(SshTransport $transport,array $fixture): array {
    $paths=[$fixture['control_root'],dirname($fixture['control_root']).'/rollback',$fixture['release_root'],'/home/duo/providers','/home/duo/provider-state'];
    $inventory=srd_remote($transport,'find '.implode(' ',array_map('escapeshellarg',$paths))." -type f -exec sha256sum {} + | sort",'negative resource snapshot')['stdout'];
    return['audit'=>RollbackAuthority::audit($transport),'inventory'=>$inventory,'status'=>RollbackAuthority::status($transport),'world'=>srd_world($transport,$fixture)];
}

/** @param array<string,array<string,mixed>> $observed */
function srd_refusal(string $id,SshTransport $transport,array $fixture,array &$observed,callable $operation,?callable $inject=null,?callable $restore=null): void {
    $before=srd_negative_snapshot($transport,$fixture);
    if(($before['status']['exclusion_state']??'')!=='held')srd_fail("negative $id began without exclusion held");
    if($inject!==null){try{$inject();}catch(Throwable $e){srd_fail("negative $id injection failed: {$e->getMessage()}");}}
    $error=null;
    try{$operation();}catch(Throwable $e){$error=$e->getMessage();}
    try{if($restore!==null)$restore();}catch(Throwable $e){srd_fail("negative $id restore failed: {$e->getMessage()}");}
    if(!is_string($error)||$error==='')srd_fail("negative $id was not refused");
    $after=srd_negative_snapshot($transport,$fixture);
    if(RollbackControl::canonical($before)!==RollbackControl::canonical($after)){
        $diff=[];foreach(array_keys($before)as$key){$beforeHash=srd_hash(RollbackControl::canonical([$before[$key]]));$afterHash=srd_hash(RollbackControl::canonical([$after[$key]]));if(!hash_equals($beforeHash,$afterHash))$diff[$key]=['after'=>$afterHash,'before'=>$beforeHash];}
        if(isset($diff['world']))foreach($before['world']as$key=>$value)if($value!==$after['world'][$key])$diff['world'][$key]=['after'=>$after['world'][$key],'before'=>$value];
        if(isset($diff['inventory'])){$beforeLines=array_filter(explode("\n",(string)$before['inventory']));$afterLines=array_filter(explode("\n",(string)$after['inventory']));$diff['inventory']['removed']=array_values(array_diff($beforeLines,$afterLines));$diff['inventory']['added']=array_values(array_diff($afterLines,$beforeLines));}
        srd_fail("negative $id changed authority or protected resources: ".RollbackControl::canonical($diff));
    }
    $observed[$id]=[
        'case_id'=>$id,'error_sha256'=>srd_hash($error),'evidence_sha256'=>srd_hash(RollbackControl::canonical(['after'=>$after,'before'=>$before,'error_sha256'=>srd_hash($error)])),
        'exclusion_held'=>true,'mutation_observed'=>false,'refused'=>true,'traffic_closed'=>true,
    ];
}

if(($argv[1]??'')==='controller-kill'){
    $document=srd_read((string)($argv[2]??''));$cfg=$document['envs']['target']??null;if(!is_array($cfg))srd_fail('controller kill lacks target environment');$cfg['_dir']=dirname((string)$argv[2]);
    $transport=new SshTransport('target',$cfg);RollbackAuthority::status($transport);fwrite(STDOUT,(string)getmypid()."\n");fflush(STDOUT);posix_kill(getmypid(),9);exit(99);
}
if(($argv[1]??'')==='verify-world'){
    $document=srd_read((string)($argv[2]??''));$cfg=$document['envs']['target']??null;$fixture=$document['fixture']??null;if(!is_array($cfg)||!is_array($fixture))srd_fail('verifier lacks target environment');$cfg['_dir']=dirname((string)$argv[2]);
    $transport=new SshTransport('target',$cfg);echo RollbackControl::canonical(['pid'=>'pid-'.getmypid(),'world'=>srd_world($transport,$fixture)])."\n";exit(0);
}

if($argc!==8)srd_fail('usage: driver ENV OUTPUT SOURCE_HOST SOURCE_DB TARGET_HOST TARGET_DB HARNESS_REVISION');
[$script,$envPath,$output,$sourceHost,$sourceDb,$targetHost,$targetDb,$harnessRevision]=$argv;
$document=srd_read($envPath);$cfg=$document['envs']['target']??null;$fixture=$document['fixture']??null;if(!is_array($cfg)||!is_array($fixture))srd_fail('environment lacks envs.target/fixture');$cfg['_dir']=dirname($envPath);
$transport=new SshTransport('target',$cfg);$authority=new RollbackAuthority($transport);$faultEvidence=[];$negativeEvidence=[];$dbProbe='php '.escapeshellarg($fixture['db_probe']).' '.escapeshellarg($fixture['db_config']);

// Generation one: preparation and every forward mutation are followed by a
// deliberate verification failure, then all recovery resources converge to
// the prior world under a fresh claimant.
srd_remote($transport,'touch '.escapeshellarg($fixture['code_state'].'.kill-before-upload'),'arm partial claim');
$created=srd_time();try{$authority->claim(srd_claim_fields($transport,$fixture,1,$created),'worker-a',$created);srd_fail('partial claim fault was not observed');}catch(Throwable $e){}
srd_inject('generation-claim','before',$transport,$dbProbe,$envPath,$faultEvidence);
$claim1=$authority->claim(srd_claim_fields($transport,$fixture,1,$created),'worker-a',$created);
srd_inject('generation-claim','after',$transport,$dbProbe,$envPath,$faultEvidence);
foreach(['exclusion-acquire','checkpoint-export','checkpoint-verify','prepared-publication','code-upload']as$boundary)srd_boundary($boundary,$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_marker($authority,$transport,'prepared',str_replace('-','_',$boundary)));
srd_refusal('concurrent-claimant',$transport,$fixture,$negativeEvidence,function()use($authority,$transport){$status=RollbackAuthority::status($transport);$authority->append((string)$status['state'],'state_transition','foreign_claimant',1,'worker-foreign',srd_hash('foreign-claimant'),str_repeat('0',64),srd_time());});
srd_refusal('failed-maintenance-keepalive',$transport,$fixture,$negativeEvidence,fn()=>$authority->keepalive(srd_time()),fn()=>srd_remote($transport,'touch /home/duo/provider-state/exclusion.json.fail-keepalive','arm failed keepalive'));
srd_transition($authority,$transport,'promoting','promotion_start');
srd_boundary('code-pointer',$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport,$fixture,&$negativeEvidence){
    $input=srd_code_input(RollbackAuthority::status($transport),'select_desired');
    $authority->prepareOperation('promoting','code_select',1,$input,srd_time());
    foreach(['changed-target-identity'=>'target_id','stale-generation'=>'generation','wrong-owner'=>'owner','wrong-artifact'=>'artifact_hash']as$id=>$field){
        $bad=$input;$bad[$field]=$field==='generation'?(int)$input[$field]+1:($field==='target_id'?str_repeat('f',32):($field==='artifact_hash'?str_repeat('0',64):'controller:foreign'));
        srd_refusal($id,$transport,$fixture,$negativeEvidence,fn()=>$authority->executeOperation('code_select',1,$bad));
    }
    $desired=$fixture['release_root'].'/release-desired-1/wp-content/plugins/acme/acme.php';$desiredBackup=$desired.'.cert-backup';
    srd_refusal('changed-file',$transport,$fixture,$negativeEvidence,fn()=>$authority->executeOperation('code_select',1,$input),fn()=>srd_remote($transport,'cp '.escapeshellarg($desired).' '.escapeshellarg($desiredBackup).' && printf changed > '.escapeshellarg($desired),'change desired release'),fn()=>srd_remote($transport,'mv '.escapeshellarg($desiredBackup).' '.escapeshellarg($desired),'restore desired release'));
    $pointer=$fixture['code_pointer'];$pointerBackup=$pointer.'.cert-backup';
    srd_refusal('concurrent-writer',$transport,$fixture,$negativeEvidence,fn()=>$authority->executeOperation('code_select',1,$input),fn()=>srd_remote($transport,'cp '.escapeshellarg($pointer).' '.escapeshellarg($pointerBackup).' && printf "foreign-release\\n" > '.escapeshellarg($pointer),'inject concurrent pointer writer'),fn()=>srd_remote($transport,'mv '.escapeshellarg($pointerBackup).' '.escapeshellarg($pointer),'restore code pointer'));
    $execution=$authority->executeOperation('code_select',1,$input);$authority->completeOperation('promoting','code_select',1,$input,$execution,srd_time());
});
srd_boundary('code-prune',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_marker($authority,$transport,'promoting','code_prune'));
srd_boundary('lifecycle-retire',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_marker($authority,$transport,'promoting','lifecycle_retire'));
srd_boundary('lifecycle-activate',$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport,$fixture){srd_marker($authority,$transport,'promoting','lifecycle_activate');srd_remote($transport,'printf %s '.escapeshellarg('mutated-effect')." > ".escapeshellarg($fixture['effect_target']),'lifecycle effect');});
$uploadApply=function()use($authority,$transport){$status=RollbackAuthority::status($transport);$authority->runOperation('promoting','storage_apply',1,srd_upload_input($status,'apply_desired'),srd_time(),srd_time());};
srd_boundary('upload-local-publish',$transport,$dbProbe,$envPath,$faultEvidence,$uploadApply);
foreach(['upload-offload-publish','upload-derivative-publish']as$boundary)srd_boundary($boundary,$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_marker($authority,$transport,'promoting',str_replace('-','_',$boundary)));
srd_boundary('authored-database-commit',$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport){srd_marker($authority,$transport,'promoting','authored_database_commit');srd_wp($transport,['db','query',"UPDATE duo_cert_state SET value='desired'; INSERT INTO duo_cert_lease(id,owner) VALUES(1,'worker-a') ON DUPLICATE KEY UPDATE owner=VALUES(owner)"],'authored database mutation');});
srd_boundary('rebuild',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_marker($authority,$transport,'promoting','rebuild'));
srd_transition($authority,$transport,'verifying_new','verifying_new');
srd_boundary('new-verification',$transport,$dbProbe,$envPath,$faultEvidence,function()use($transport){$a=srd_wp($transport,['db','query','SELECT value FROM duo_cert_state WHERE id=1','--skip-column-names'],'new verification one');$b=srd_wp($transport,['db','query','SELECT value FROM duo_cert_state WHERE id=1','--skip-column-names'],'new verification two');if(!hash_equals(srd_hash($a['stdout']),srd_hash($b['stdout'])))srd_fail('new verifier recaptures differ');});
srd_boundary('rollback-pending-publication',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_transition($authority,$transport,'rollback_pending','verification_failed'));
$expired=gmdate('Y-m-d\TH:i:s\Z',strtotime((string)RollbackAuthority::status($transport)['claim_expires_at'])+1);
srd_boundary('operator-takeover',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>$authority->takeover('rescuer',$expired));
srd_time(strtotime($expired)+1);
srd_transition($authority,$transport,'rolling_back','rollback_start');
srd_boundary('effects-inverse',$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport){$status=RollbackAuthority::status($transport);$authority->runOperation('rolling_back','effects_inverse',1,srd_effect_input($status),srd_time(),srd_time());});
srd_boundary('upload-local-restore',$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport){$status=RollbackAuthority::status($transport);$authority->runOperation('rolling_back','storage_restore',1,srd_upload_input($status,'restore_prior'),srd_time(),srd_time());});
foreach(['upload-offload-restore','upload-created-path-delete']as$boundary)srd_boundary($boundary,$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_marker($authority,$transport,'rolling_back',str_replace('-','_',$boundary)));
srd_boundary('code-restore',$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport){$status=RollbackAuthority::status($transport);$authority->runOperation('rolling_back','code_restore',1,srd_code_input($status,'restore_prior'),srd_time(),srd_time());});
foreach(['database-abort-before','database-import','database-abort-final']as$index=>$boundary){
    if($index===1)srd_boundary($boundary,$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport,$fixture,&$negativeEvidence){
        $status=RollbackAuthority::status($transport);$input=['checkpoint_sha256'=>$status['checkpoint_sha256'],'operation'=>'database_restore'];$authority->prepareOperation('rolling_back','database_restore',1,$input,srd_time());
        $bad=$input;$bad['checkpoint_sha256']=str_repeat('0',64);srd_refusal('checkpoint-substitution',$transport,$fixture,$negativeEvidence,fn()=>$authority->executeOperation('database_restore',1,$bad));
        $checkpoint=dirname($fixture['control_root']).'/rollback/'.$status['receipt_id'].'/artifacts/checkpoint.enc';$checkpointBackup=$checkpoint.'.cert-backup';
        srd_refusal('checkpoint-corruption',$transport,$fixture,$negativeEvidence,fn()=>$authority->executeOperation('database_restore',1,$input),fn()=>srd_remote($transport,'cp '.escapeshellarg($checkpoint).' '.escapeshellarg($checkpointBackup).' && printf corrupt >> '.escapeshellarg($checkpoint),'corrupt checkpoint'),fn()=>srd_remote($transport,'mv '.escapeshellarg($checkpointBackup).' '.escapeshellarg($checkpoint),'restore checkpoint'));
        $key='/home/duo/provider-state/checkpoint.key';$keyBackup=$key.'.cert-backup';
        srd_refusal('unavailable-key',$transport,$fixture,$negativeEvidence,fn()=>$authority->executeOperation('database_restore',1,$input),fn()=>srd_remote($transport,'mv '.escapeshellarg($key).' '.escapeshellarg($keyBackup),'remove checkpoint key'),fn()=>srd_remote($transport,'mv '.escapeshellarg($keyBackup).' '.escapeshellarg($key),'restore checkpoint key'));
        $provider='/home/duo/providers/ssh-rollback-checkpoint-provider.php';$providerBackup=$provider.'.cert-backup';
        srd_refusal('unavailable-adapter',$transport,$fixture,$negativeEvidence,fn()=>$authority->executeOperation('database_restore',1,$input),fn()=>srd_remote($transport,'mv '.escapeshellarg($provider).' '.escapeshellarg($providerBackup),'remove checkpoint adapter'),fn()=>srd_remote($transport,'mv '.escapeshellarg($providerBackup).' '.escapeshellarg($provider),'restore checkpoint adapter'));
        $resumer=new RollbackAuthority($transport);$execution=$resumer->executeOperation('database_restore',1,$input);$resumer->completeOperation('rolling_back','database_restore',1,$input,$execution,srd_time());
    });
    else srd_boundary($boundary,$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_marker($authority,$transport,'rolling_back',str_replace('-','_',$boundary)));
}
srd_transition($authority,$transport,'verifying_prior','verifying_prior');
srd_refusal('terminal-without-prior-verify',$transport,$fixture,$negativeEvidence,fn()=>srd_transition($authority,$transport,'rolled_back','rolled_back_without_prior_verify'));
srd_boundary('prior-verification',$transport,$dbProbe,$envPath,$faultEvidence,function()use($authority,$transport){$status=RollbackAuthority::status($transport);$authority->runOperation('verifying_prior','prior_verify',1,['checkpoint_sha256'=>$status['checkpoint_sha256'],'operation'=>'prior_verify'],srd_time(),srd_time());});
srd_remote($transport,'chmod -R a-w '.escapeshellarg($fixture['release_root'].'/release-prior'),'freeze prior release');
$priorWorld=srd_world($transport,$fixture);$priorVerifier=srd_fresh_verifier($envPath,srd_hash('artifact-prior'),'prior',1);
srd_boundary('rolled-back-publication',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_transition($authority,$transport,'rolled_back','rolled_back_verified'));
$audit1=RollbackAuthority::audit($transport);$authority->releaseExclusion(srd_time());

// Generation two reaches a freshly verified new world. It owns the commit
// and terminal retention/deletion boundaries that cannot occur in generation
// one's rolled-back release selection.
$created2=srd_time();$claim2=$authority->claim(srd_claim_fields($transport,$fixture,2,$created2),'worker-b',$created2);srd_transition($authority,$transport,'promoting','promotion_start_2');
$status=RollbackAuthority::status($transport);$authority->runOperation('promoting','code_select',1,srd_code_input($status,'select_desired'),srd_time(),srd_time());$status=RollbackAuthority::status($transport);$authority->runOperation('promoting','storage_apply',1,srd_upload_input($status,'apply_desired'),srd_time(),srd_time());srd_wp($transport,['db','query',"UPDATE duo_cert_state SET value='desired-2'; DELETE FROM duo_cert_lease"],'second authored commit');
srd_transition($authority,$transport,'verifying_new','verifying_new_2');srd_remote($transport,'chmod -R a-w '.escapeshellarg($fixture['release_root'].'/release-desired-2'),'freeze desired release');$newWorld=srd_world($transport,$fixture);$newVerifier=srd_fresh_verifier($envPath,(string)$claim2['receipt']['artifact_hash'],'new',2);
srd_boundary('committed-publication',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>srd_transition($authority,$transport,'committed','committed_verified'));
$audit2=RollbackAuthority::audit($transport);
srd_boundary('checkpoint-retention-delete',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>$authority->deleteCheckpoint('2020-01-05T00:00:00Z'));
srd_remote($transport,'chmod -R u+w '.escapeshellarg($fixture['release_root'].'/release-prior'),'allow retained prior deletion');
srd_boundary('code-retention-delete',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>$authority->deleteCodeRelease('2020-01-05T00:00:00Z'));
srd_boundary('upload-retention-delete',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>$authority->deleteUploadBundle('2020-01-05T00:00:00Z'));
srd_boundary('ssh-loss',$transport,$dbProbe,$envPath,$faultEvidence,fn()=>null);
$authority->releaseExclusion('2020-01-05T00:00:01Z');

$cases=[];$terminalBoundaries=['committed-publication','checkpoint-retention-delete','code-retention-delete','upload-retention-delete','ssh-loss'];
foreach(ssh_cert_matrix()['case_ids']as$index=>$caseId){[$boundary,$failure,$edge]=explode('--',$caseId,3);$committed=in_array($boundary,$terminalBoundaries,true);$audit=$committed?$audit2:$audit1;$world=$committed?$newWorld:$priorWorld;$verifier=$committed?$newVerifier:$priorVerifier;$terminal=$committed?'committed':'rolled_back';$intermediate=$faultEvidence[$caseId]['state']??'none';$cases[]=[
    'boundary'=>$boundary,'case_id'=>$caseId,'edge'=>$edge,'event_chain_sha256'=>$audit['event_chain_sha256'],'failure_mode'=>$failure,
    'injection_process_id'=>$faultEvidence[$caseId]['process_id']??'missing','inputs_sha256'=>$faultEvidence[$caseId]['sha256']??srd_hash($caseId),'intermediate'=>['authority_state'=>$intermediate,'exclusion_held'=>true,'green'=>false,'traffic_closed'=>true],
    'new_artifact_sha256'=>(string)$claim2['receipt']['artifact_hash'],'prior_artifact_sha256'=>srd_hash('artifact-prior'),'receipt_sha256'=>$audit['receipt_sha256'],
    'resource_fingerprints'=>['code'=>$world['code'],'database'=>$world['database'],'effects'=>$world['effects'],'runtime'=>$world['runtime'],'target'=>$world['target'],'uploads'=>$world['uploads']],
    'terminal'=>['exclusion_released'=>true,'fresh_process_verified'=>true,'green'=>true,'state'=>$terminal],'verifier'=>$verifier,
    'versions'=>['checkpoint'=>'checkpoint:1.0.0','code_release'=>'code:1.0.0','database'=>'mariadb:11.8','effects'=>'effects:1.0.0','exclusion'=>'exclusion:1.0.0','harness'=>'harness:1.0.0','runtime'=>'runtime:1.0.0','uploads'=>'uploads:1.0.0'],
];}
$negatives=[];foreach(ssh_cert_negative_ids()as$id){if(!isset($negativeEvidence[$id]))srd_fail("negative $id lacks observed refusal evidence");$negatives[]=$negativeEvidence[$id];}
$spec=['cases'=>$cases,'cleanup'=>['active_receipt_absent'=>false,'maintenance_lock_absent'=>false,'owned_ssh_fixture_absent'=>false,'plaintext_checkpoint_absent'=>false],'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'format'=>SSH_ROLLBACK_INPUT_FORMAT,'harness_revision'=>$harnessRevision,'key_id'=>(string)$cfg['rollback_key_id'],'negative_cases'=>$negatives,'source'=>['database_sha256'=>$sourceDb,'host_sha256'=>$sourceHost],'target'=>['database_sha256'=>$targetDb,'host_sha256'=>$targetHost]];
file_put_contents($output,json_encode(ssh_cert_sort($spec),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
echo "PASS: live SSH rollback driver produced ".count($cases)." crash cases across verified rolled_back and committed generations\n";
