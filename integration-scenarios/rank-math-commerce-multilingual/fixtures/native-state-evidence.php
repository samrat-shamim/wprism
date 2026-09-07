<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';

// File admission is not native acceptance. The live caller checks its declared
// native intent only after complete transport and private ownership survive.
try {
    if (count($argv) !== 4 || !str_starts_with($argv[1], '/') || preg_match('/^[a-z][a-z0-9]{2,23}$/D', $argv[2]) !== 1 || !in_array($argv[3], ['cli1', 'cli2'], true)) {
        throw new RuntimeException('native diagnostic arguments are invalid');
    }
    $stderrPattern = '/^ ?Container wprism-' . preg_quote($argv[2], '/') . '-' . $argv[3] . '-run-[a-f0-9]+ (Creating|Created) *$/D';
    echo \WPrismTest\PrivateCommandOutput::readObject($argv[1], $stderrPattern);
} catch (Throwable) {
    fwrite(STDERR, "combined native diagnostic admission failed; inspect private capture\n");
    exit(1);
}
