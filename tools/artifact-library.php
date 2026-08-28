#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\Tooling\ArtifactLibrary;

require_once __DIR__ . '/src/ArtifactLibrary.php';

$repoRoot = dirname(__DIR__);
$package = null;
$participants = null;
$check = false;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if ($argument === '--check' && !$check) {
        $check = true;
        continue;
    }
    if (is_string($argument) && str_starts_with($argument, '--root=') && $repoRoot === dirname(__DIR__)) {
        $repoRoot = substr($argument, strlen('--root='));
        continue;
    }
    if (is_string($argument) && str_starts_with($argument, '--adapter=') && $package === null) {
        $package = substr($argument, strlen('--adapter='));
        continue;
    }
    if (is_string($argument) && str_starts_with($argument, '--participants=') && $participants === null) {
        $participants = explode(',', substr($argument, strlen('--participants=')));
        continue;
    }
    fwrite(STDERR, "usage: php tools/artifact-library.php [--root=DIR] [--adapter=PACKAGE|--participants=A,B] [--check]\n");
    exit(2);
}
if ($package !== null && $participants !== null) {
    fwrite(STDERR, "usage: php tools/artifact-library.php [--root=DIR] [--adapter=PACKAGE|--participants=A,B] [--check]\n");
    exit(2);
}

try {
    if ($package !== null) {
        $library = ArtifactLibrary::loadPackage($repoRoot, $package);
    } elseif ($participants !== null) {
        $library = ArtifactLibrary::loadParticipants($repoRoot, $participants);
    } else {
        $library = ArtifactLibrary::load($repoRoot);
    }
    if ($check) {
        $subjects = count($library['plugins']) + count($library['themes']);
        $versions = array_sum(array_map('count', $library['plugins']))
            + array_sum(array_map('count', $library['themes']));
        fwrite(STDOUT, "artifact-library: $subjects subjects, $versions versions green\n");
    } else {
        fwrite(STDOUT, json_encode($library, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
} catch (Throwable $failure) {
    fwrite(STDERR, 'artifact-library: ' . $failure->getMessage() . "\n");
    exit(1);
}
