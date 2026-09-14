#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/ConformanceHooks.php';

try {
    if ($argc !== 3) {
        throw new RuntimeException('usage: conformance-hooks.php ENTRY_JSON CAPSULE');
    }
    $entry = json_decode($argv[1], false, 64, JSON_THROW_ON_ERROR);
    if (!$entry instanceof stdClass) {
        throw new RuntimeException('conformance entry must be an object');
    }
    $entry = (array) $entry;
    if (($entry['hooks'] ?? null) instanceof stdClass) {
        $entry['hooks'] = (array) $entry['hooks'];
    }
    echo json_encode(WPrism\Tooling\ConformanceHooks::resolve($entry, $argv[2]), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'conformance-hooks: ' . $failure->getMessage() . "\n");
    exit(1);
}
