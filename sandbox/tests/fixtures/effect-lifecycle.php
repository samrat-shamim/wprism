<?php
declare(strict_types=1);

/**
 * Real, isolated lifecycle fixture: one external HTTP intent must be
 * prevented before escape, then one non-database file mutation is reported
 * after its bounded write. The callback is the product EffectBundle observer
 * in regression; no WordPress bootstrap or network client is involved.
 *
 * @param callable(array<string,mixed>):array<string,mixed> $observe
 */
function duo_effect_fixture_activate(string $targetFile, callable $observe): void {
    $observe([
        'effect_id' => 'probe-http', 'kind' => 'http', 'manifest' => 'effect-probe',
        'phase' => 'lifecycle',
        'selector' => ['scope' => 'external', 'type' => 'url_prefix', 'value' => 'https://duo-promotion-probe.invalid/'],
    ]);
    $directory = dirname($targetFile);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('fixture could not create lifecycle output directory');
    }
    if (file_put_contents($targetFile, "mutated lifecycle bytes\n") !== strlen("mutated lifecycle bytes\n")) {
        throw new RuntimeException('fixture lifecycle file mutation failed');
    }
    $observe([
        'effect_id' => 'probe-file', 'kind' => 'filesystem', 'manifest' => 'effect-probe',
        'phase' => 'lifecycle',
        'selector' => ['scope' => 'external', 'type' => 'path', 'value' => 'wp-content/uploads/duo-promotion-probe.txt'],
    ]);
}
