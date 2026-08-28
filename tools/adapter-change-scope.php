#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\Tooling\AdapterChangeScopeCommand;

require_once __DIR__ . '/src/AdapterChangeScopeCommand.php';

$serverArguments = $_SERVER['argv'] ?? [];
$arguments = [];
if (is_array($serverArguments)) {
    foreach (array_slice($serverArguments, 1) as $argument) {
        if (is_string($argument)) {
            $arguments[] = $argument;
        }
    }
}
$result = AdapterChangeScopeCommand::execute($arguments);
fwrite(STDOUT, $result['stdout']);
fwrite(STDERR, $result['stderr']);
exit($result['status']);
