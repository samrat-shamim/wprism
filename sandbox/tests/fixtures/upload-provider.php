#!/usr/bin/env php
<?php
declare(strict_types=1);

// Test-only local/offload provider for DUO-3297. It deliberately executes in
// its own process, maintains a durable per-path prepared/completed journal,
// encrypts every before-image, and applies exact absence guards on restore.

function up_canon(array $v): string { $sort=function(&$x)use(&$sort){if(!is_array($x))return;if(array_is_list($x)){foreach($x as &$y)$sort($y);unset($y);}else{ksort($x,SORT_STRING);foreach($x as &$y)$sort($y);unset($y);}};$sort($v);return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
function up_fail(string $m): never { fwrite(STDERR,$m."\n");exit(1); }
function up_write(string $p,string $b,bool $canonical=false): void { if(is_link($p)||(file_exists($p)&&!is_file($p)))up_fail('unsafe output');$d=dirname($p);if(!is_dir($d)&&!mkdir($d,0700,true))up_fail('mkdir');$tmp=$p.'.tmp-'.bin2hex(random_bytes(4));$h=fopen($tmp,'x+b');if(!$h)up_fail('open');$bytes=$canonical?up_canon(json_decode($b,true,512,JSON_THROW_ON_ERROR))."\n":$b;if(fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h))up_fail('write');fclose($h);if(!rename($tmp,$p))up_fail('rename');}
function up_read_json(string $p): array { $raw=file_get_contents($p);$v=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($v)||up_canon($v)."\n"!==$raw)up_fail('noncanonical input');return $v; }
function up_boundary(string $state,string $name): void { $p=$state.'.'.$name;if(is_file($p)){unlink($p);exit(86);} }
function up_safe(string $p): void { if($p===''||str_starts_with($p,'/')||str_contains($p,'\\')||array_filter(explode('/',$p),fn($x)=>$x===''||$x==='.'||$x==='..'))up_fail('unsafe path'); }
function up_kind_path(string $logical,string $uploads,string $offload): array { up_safe($logical);$remote=str_starts_with($logical,'remote/');$relative=$remote?substr($logical,7):$logical;up_safe($relative);return [$remote?'offload':'local',($remote?$offload:$uploads).'/'.$relative]; }
function up_atomic(string $p,string $bytes): void { if(is_link($p)||(file_exists($p)&&!is_file($p)))up_fail('unsafe mutation target');$d=dirname($p);if(!is_dir($d)&&!mkdir($d,0755,true))up_fail('mkdir target');$tmp=$p.'.duo-'.bin2hex(random_bytes(4));$h=fopen($tmp,'x+b');if(!$h||fwrite($h,$bytes)!==strlen($bytes)||!fflush($h)||!fsync($h))up_fail('atomic write');fclose($h);if(!rename($tmp,$p))up_fail('atomic rename');}
function up_response(array $v): never { echo up_canon($v)."\n";exit(0); }
function up_provider_base(string $state): array { return ['format'=>'duo-upload-provider-response/v1','provider_id'=>'fixture-storage','provider_version'=>'1.0.0']; }
function up_current_hash(string $p): ?string { if(is_link($p)||(file_exists($p)&&!is_file($p)))up_fail('unsafe observed target');return is_file($p)?hash_file('sha256',$p):null; }

[$script,$state,$uploads,$offload,$media,$keyPath]=$argv+[null,null,null,null,null,null];
if(!$state||!$uploads||!$offload||!$media||!$keyPath)up_fail('usage');
$request=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);if(!is_array($request))up_fail('request');
$base=up_provider_base($state);$action=(string)($request['action']??'');
if($action==='probe'){if(is_file($state.'.leak'))up_response($base+['access_token'=>'fixture-secret','authenticated_encryption'=>true,'available'=>true,'credentials_exposed'=>false,'encrypted_before_images'=>true,'plaintext_durable'=>false,'read_after_restore'=>true,'state'=>'ready','supports_local'=>true,'supports_offload'=>true]);up_response($base+['authenticated_encryption'=>true,'available'=>true,'credentials_exposed'=>false,'encrypted_before_images'=>true,'plaintext_durable'=>false,'read_after_restore'=>true,'state'=>'ready','supports_local'=>true,'supports_offload'=>true]);}
$dir=(string)($request['artifact_directory']??'');if($dir===''||$dir[0]!=='/')up_fail('artifact directory');
$desired=up_read_json((string)$request['desired_inventory_path']);
$priorPath=$dir.'/prior-inventory.json';$cipherPath=$dir.'/before-images.enc';$journalPath=$dir.'/provider-journal.json';$reportPath=$dir.'/mutation-report.json';
$key=file_get_contents($keyPath);if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)up_fail('key');

if($action==='prepare'){
  up_boundary($state,'kill-before-prepare');$entries=[];$images=[];
  foreach($desired['uploads'] as $row){
    $paths=[(string)$row['original_path']];$directory=(string)$row['derivative_directory'];$prefix=(string)$row['derivative_basename_prefix'];
    [, $originalFs]=up_kind_path((string)$row['original_path'],$uploads,$offload);$scan=dirname($originalFs);
    if(is_dir($scan)){foreach(scandir($scan)?:[] as $name){if($name==='.'||$name==='..'||!str_starts_with($name,$prefix))continue;$logical=($directory!==''?$directory.'/':'').$name;if(!in_array($logical,$paths,true))$paths[]=$logical;}}
    foreach($paths as $logical){[$kind,$fs]=up_kind_path($logical,$uploads,$offload);$hash=up_current_hash($fs);if($hash===null){$entries[]=['ciphertext_ref'=>null,'kind'=>$kind,'path'=>$logical,'sha256'=>null,'state'=>'absent','version_id'=>null];continue;}$bytes=file_get_contents($fs);$ref='record-'.hash('sha256',$logical);$images[$ref]=base64_encode((string)$bytes);$entries[]=['ciphertext_ref'=>$ref,'kind'=>$kind,'path'=>$logical,'sha256'=>$hash,'state'=>'present','version_id'=>$kind==='offload'?'version-'.$hash:null];}
  }
  usort($entries,fn($a,$b)=>strcmp($a['path'],$b['path']));$prior=['entries'=>$entries,'format'=>'duo-upload-prior-inventory/v1'];up_write($priorPath,up_canon($prior)."\n");up_boundary($state,'kill-after-inventory');
  $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$plain=up_canon($images)."\n";$cipher=$nonce.sodium_crypto_secretbox($plain,$nonce,$key);up_write($cipherPath,$cipher);up_boundary($state,'kill-after-cipher');
  $unsupported=is_file($state.'.unsupported')?['remote/provider-object']:[];if($unsupported)unlink($state.'.unsupported');up_response($base+['authenticated_encryption'=>true,'before_images_sha256'=>hash_file('sha256',$cipherPath),'before_images_size'=>filesize($cipherPath),'credentials_exposed'=>false,'desired_inventory_sha256'=>hash_file('sha256',(string)$request['desired_inventory_path']),'encrypted_before_images'=>true,'plaintext_durable'=>false,'prior_inventory_sha256'=>hash_file('sha256',$priorPath),'read_after_restore'=>true,'state'=>'prepared','unsupported_paths'=>$unsupported]);
}

function up_images(string $cipherPath,string $key): array { $raw=file_get_contents($cipherPath);$nonce=substr((string)$raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$plain=sodium_crypto_secretbox_open(substr((string)$raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$key);if($plain===false)up_fail('decrypt');$v=json_decode($plain,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[]; }
function up_journal(string $path): array { return is_file($path)?up_read_json($path):['format'=>'duo-upload-provider-journal/v1','operations'=>[]]; }
function up_save_journal(string $path,array $j): void { up_write($path,up_canon($j)."\n"); }
function up_mutate(string $state,string $journalPath,string $logical,string $action,?string $after,string $uploads,string $offload): void { $j=up_journal($journalPath);$found=null;foreach($j['operations'] as $index=>$old)if($old['path']===$logical){if($old['completed']===true)return;$found=$index;break;}[$kind,$fs]=up_kind_path($logical,$uploads,$offload);$afterHash=$after===null?null:hash('sha256',$after);if($found===null){$before=up_current_hash($fs);$j['operations'][]=['action'=>$action,'after_sha256'=>$afterHash,'before_sha256'=>$before,'completed'=>false,'path'=>$logical,'prepared'=>true];$found=count($j['operations'])-1;up_save_journal($journalPath,$j);}elseif($j['operations'][$found]['after_sha256']!==$afterHash){up_fail('retry changed prepared mutation');}$tag=hash('sha256',$logical);up_boundary($state,'kill-before-write-'.$tag);$current=up_current_hash($fs);if($after===null){if($current!==null&&is_file($fs)&&!unlink($fs))up_fail('remove');}elseif($current===null||!hash_equals($afterHash,(string)$current))up_atomic($fs,$after);up_boundary($state,'kill-after-write-'.$tag);$j=up_journal($journalPath);$j['operations'][$found]['completed']=true;up_save_journal($journalPath,$j); }

if($action==='apply_desired'){
  foreach($desired['uploads'] as $row){$blob=$media.'/'.basename((string)$row['media_blob']);$bytes=file_get_contents($blob);if(!is_string($bytes)||hash('sha256',$bytes)!==(string)$row['original_sha256'])up_fail('desired media mismatch');$logical=(string)$row['original_path'];up_mutate($state,$journalPath,$logical,up_current_hash(up_kind_path($logical,$uploads,$offload)[1])===null?'publish':'replace',$bytes,$uploads,$offload);$ext=pathinfo($logical,PATHINFO_EXTENSION);$derivative=(($row['derivative_directory']??'')!==''?$row['derivative_directory'].'/':'').$row['derivative_basename_prefix'].'150x150'.($ext!==''?'.'.$ext:'');up_boundary($state,'kill-before-derivative');up_mutate($state,$journalPath,$derivative,up_current_hash(up_kind_path($derivative,$uploads,$offload)[1])===null?'publish':'replace',"DERIVATIVE:".hash('sha256',$bytes)."\n",$uploads,$offload);up_boundary($state,'kill-after-derivative');}
  if(is_file($state.'.undeclared')){$bad='undeclared/escape.bin';up_mutate($state,$journalPath,$bad,'publish','bad',$uploads,$offload);unlink($state.'.undeclared');}
  $journal=up_journal($journalPath);$report=['format'=>'duo-upload-mutation-report/v1','operations'=>$journal['operations']];up_write($reportPath,up_canon($report)."\n");$result=hash('sha256',up_canon($report));up_response($base+['credentials_exposed'=>false,'journal_sha256'=>hash_file('sha256',$reportPath),'report_path'=>'mutation-report.json','result_sha256'=>$result,'state'=>'applied','undeclared_paths'=>is_file($state.'.report-undeclared')?['escape.bin']:[]]);
}

$prior=up_read_json($priorPath);
if($action==='restore_prior'){
  $images=up_images($cipherPath,$key);$journal=up_journal($journalPath);
  foreach(array_reverse($journal['operations']) as $op){[$kind,$fs]=up_kind_path($op['path'],$uploads,$offload);if($op['before_sha256']===null){$current=up_current_hash($fs);if($current!==null&&!hash_equals((string)$op['after_sha256'],$current))up_fail('created path changed; exact absence guard refused deletion');up_boundary($state,'kill-before-delete-'.hash('sha256',$op['path']));if(is_file($fs)&&!unlink($fs))up_fail('delete');up_boundary($state,'kill-after-delete-'.hash('sha256',$op['path']));continue;}$entry=null;foreach($prior['entries'] as $candidate)if($candidate['path']===$op['path'])$entry=$candidate;if(!is_array($entry)||!is_string($entry['ciphertext_ref']))up_fail('missing before image');$bytes=base64_decode((string)$images[$entry['ciphertext_ref']],true);if($bytes===false||hash('sha256',$bytes)!==$entry['sha256'])up_fail('before image mismatch');up_boundary($state,'kill-before-restore-'.hash('sha256',$op['path']));up_atomic($fs,$bytes);up_boundary($state,'kill-after-restore-'.hash('sha256',$op['path']));}
}
if(in_array($action,['restore_prior','verify-prior'],true)){
  foreach($prior['entries'] as $entry){[, $fs]=up_kind_path($entry['path'],$uploads,$offload);$actual=up_current_hash($fs);if($entry['state']==='absent'?$actual!==null:!is_string($actual)||!hash_equals((string)$entry['sha256'],$actual))up_fail('prior inventory verification failed');}
  $hash=hash_file('sha256',$priorPath);up_response($base+['credentials_exposed'=>false,'exact_absence_guard'=>true,'prior_inventory_sha256'=>$hash,'read_after_restore'=>true,'result_sha256'=>$hash,'state'=>$action==='restore_prior'?'restored':'verified']);
}
if($action==='delete'){foreach([$cipherPath,$priorPath,$journalPath,$reportPath] as $p)if(is_file($p))unlink($p);up_response($base+['credentials_exposed'=>false,'state'=>'deleted']);}
up_fail('unsupported action');
