<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 2) . '/lib/ContainerProcessEvidence.php';

use WPrismTest\ContainerProcessEvidence;

$name = 'wprism-crashproof-fault'; $project = 'wprism-crashproof'; $service = 'cli2';
$command = ['wp', 'wprism', 'apply', '--format=json'];
$environment = ['WPRISM_TEST_MODE' => '1', 'WPRISM_TEST_FAIL_DB_CONTEXT' => 'apply transaction commit', 'WPRISM_TEST_DB_FAULT_MODE' => 'kill'];
$window = ['before' => 1700000000, 'after' => 1700000010];
$record = ['Id' => str_repeat('a', 64), 'Name' => '/' . $name, 'RestartCount' => 0, 'HostConfig' => ['Init' => true],
    'Config' => ['Cmd' => $command, 'Labels' => ['com.docker.compose.project' => $project,
        'com.docker.compose.service' => $service, 'com.docker.compose.oneoff' => 'True'],
        'Env' => ['PATH=/bin', 'WPRISM_TEST_MODE=1', 'WPRISM_TEST_FAIL_DB_CONTEXT=apply transaction commit', 'WPRISM_TEST_DB_FAULT_MODE=kill']],
    'State' => ['Status' => 'exited', 'ExitCode' => 137, 'OOMKilled' => false, 'Running' => false,
        'Restarting' => false, 'Dead' => false, 'Error' => '', 'Pid' => 0,
        'StartedAt' => '2023-11-14T22:13:21.123456789Z', 'FinishedAt' => '2023-11-14T22:13:24Z']];
$verify = static fn(array $value) => ContainerProcessEvidence::assertKilled($value, $name, $project, $service, $command, $environment, $window);
$verify($record); wprism_check(true, 'owned stopped command retains exact fault binding without OOM');
foreach (['id', 'name', 'restarted', 'missing-init', 'no-init', 'command', 'project', 'service', 'oneoff', 'missing-env', 'duplicate-env', 'changed-context',
    'changed-mode', 'environment-shape', 'status', 'exit', 'oom', 'running', 'restarting', 'dead', 'error', 'pid',
    'missing-time', 'timestamp-shape', 'outside-window', 'reversed-time'] as $fault) {
    $bad = $record;
    if ($fault === 'missing-init') unset($bad['HostConfig']['Init']);
    if ($fault === 'no-init') $bad['HostConfig']['Init'] = false;
    if ($fault === 'id') $bad['Id'] = 'short';
    if ($fault === 'name') $bad['Name'] = '/other';
    if ($fault === 'restarted') $bad['RestartCount'] = 1;
    if ($fault === 'command') $bad['Config']['Cmd'][2] = 'capture';
    if ($fault === 'project') $bad['Config']['Labels']['com.docker.compose.project'] = 'foreign';
    if ($fault === 'service') $bad['Config']['Labels']['com.docker.compose.service'] = 'cli1';
    if ($fault === 'oneoff') $bad['Config']['Labels']['com.docker.compose.oneoff'] = 'False';
    if ($fault === 'missing-env') array_pop($bad['Config']['Env']);
    if ($fault === 'duplicate-env') $bad['Config']['Env'][] = 'WPRISM_TEST_DB_FAULT_MODE=kill';
    if ($fault === 'changed-context') $bad['Config']['Env'][2] = 'WPRISM_TEST_FAIL_DB_CONTEXT=ledger transaction commit';
    if ($fault === 'changed-mode') $bad['Config']['Env'][3] = 'WPRISM_TEST_DB_FAULT_MODE=throw';
    if ($fault === 'environment-shape') $bad['Config']['Env'] = ['named' => 'value'];
    if ($fault === 'status') $bad['State']['Status'] = 'running';
    if ($fault === 'exit') $bad['State']['ExitCode'] = 1;
    if ($fault === 'oom') $bad['State']['OOMKilled'] = true;
    if ($fault === 'running') $bad['State']['Running'] = true;
    if ($fault === 'restarting') $bad['State']['Restarting'] = true;
    if ($fault === 'dead') $bad['State']['Dead'] = true;
    if ($fault === 'error') $bad['State']['Error'] = 'runtime failure';
    if ($fault === 'pid') $bad['State']['Pid'] = 123;
    if ($fault === 'missing-time') unset($bad['State']['StartedAt']);
    if ($fault === 'timestamp-shape') $bad['State']['StartedAt'] = 'yesterday';
    if ($fault === 'outside-window') $bad['State']['StartedAt'] = '2023-11-14T22:13:19Z';
    if ($fault === 'reversed-time') $bad['State']['FinishedAt'] = '2023-11-14T22:13:20Z';
    wprism_check_throws(static fn() => $verify($bad), RuntimeException::class, 'process receipt rejects ' . $fault);
}
$bad = $record;
$bad['State']['StartedAt'] = '2023-11-00T22:13:21Z'; $bad['State']['FinishedAt'] = '2023-10-31T22:13:24Z';
wprism_check_throws(static fn() => ContainerProcessEvidence::assertKilled($bad, $name, $project, $service, $command,
    $environment, ['before' => strtotime('2023-10-31T22:13:20Z'), 'after' => strtotime('2023-10-31T22:13:30Z')]),
    RuntimeException::class, 'timestamp parsing cannot normalize an invalid date into this invocation');
$pair = file_get_contents(dirname(__DIR__, 3) . '/pair.yml');
foreach (['cli1', 'cli2'] as $service) {
    preg_match('/^  ' . $service . ':\n((?:    [^\n]*\n|\n)+)/m', $pair, $section);
    wprism_check(preg_match('/^    init: true$/m', $section[1] ?? '') === 1,
        'shared ' . $service . ' runs beneath a real init for signal semantics');
}
wprism_check_summary('container process evidence');
