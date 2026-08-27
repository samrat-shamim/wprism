#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\Tooling\AdapterPackageValidator;

require_once __DIR__ . '/src/AdapterPackageValidator.php';

$arguments = array_values(array_filter(
    array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1),
    static fn(mixed $argument): bool => is_string($argument)
));
$slug = null;
foreach ($arguments as $argument) {
    if (!str_starts_with($argument, '--adapter=') || $slug !== null) {
        fwrite(STDERR, "usage: php tools/adapter-package-validate.php --adapter=SLUG\n");
        exit(2);
    }
    $slug = substr($argument, strlen('--adapter='));
}
if ($slug === null || $slug === '') {
    fwrite(STDERR, "usage: php tools/adapter-package-validate.php --adapter=SLUG\n");
    exit(2);
}

try {
    $result = AdapterPackageValidator::validate(dirname(__DIR__), $slug);
    fwrite(
        STDOUT,
        'adapter-package-validate: ' . $result['adapter'] . ' ' . count($result['checks'])
        . ' checks green; digest ' . $result['digest'] . "\n"
    );
    exit(0);
} catch (Throwable $failure) {
    fwrite(STDERR, 'adapter-package-validate: ' . $failure->getMessage() . "\n");
    exit(1);
}
