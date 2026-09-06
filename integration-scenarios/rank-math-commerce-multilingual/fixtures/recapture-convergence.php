<?php
declare(strict_types=1);

require_once __DIR__ . '/RecaptureConvergence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/agent_version.php';
require_once dirname(__DIR__, 3) . '/agent/src/Policy/Policy.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/Code.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeStateContract.php';
require_once dirname(__DIR__, 3) . '/agent/src/Repository/RepositoryAuthorization.php';
require_once dirname(__DIR__, 3) . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\RankMathCombination\RecaptureConvergence;

try {
    if (count($argv) !== 9 || realpath($argv[1]) !== dirname(__DIR__, 3)
        || preg_match('/^[a-z][a-z0-9]{2,23}$/D', $argv[2]) !== 1) throw new RuntimeException('invalid convergence invocation');
    [, $root, $pair, $canonical] = $argv;
    $prefix = $root . '/sandbox/tmp/wprism-rmcombo-';
    if (preg_match('#^' . preg_quote($prefix . 'canonical.' . $pair . '.', '#') . '[A-Za-z0-9]{6}$#D', $canonical) !== 1) {
        throw new RuntimeException('canonical observation belongs to another invocation');
    }
    $observations = [];
    foreach (['target-hostile', 'target-final', 'target-preservation-before', 'target-preservation-precapture', 'target-preservation-after'] as $index => $phase) {
        $stem = $argv[$index + 4];
        if (preg_match('#^' . preg_quote($prefix . 'native.' . $pair . '.' . $phase . '.', '#') . '[A-Za-z0-9]{6}/native$#D', $stem) !== 1) {
            throw new RuntimeException('native preservation phase belongs to another invocation');
        }
        $observations[] = json_decode(PrivateCommandOutput::readObject($stem,
            '/^ ?Container wprism-' . $pair . '-cli2-run-[a-f0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    }
    $baselineBytes = PrivateCommandOutput::readObject($canonical . '/baseline');
    $baseline = json_decode($baselineBytes, true, 32, JSON_THROW_ON_ERROR);
    $retained = json_decode(PrivateCommandOutput::readObject($canonical . '/private'), true, 32, JSON_THROW_ON_ERROR);
    if (($baseline['pair'] ?? null) !== $pair || ($retained['pair'] ?? null) !== $pair
        || ($retained['baseline_sha256'] ?? null) !== hash('sha256', $baselineBytes)
        || $baseline['source'] !== $retained['source']) throw new RuntimeException('source changed across recapture');
    $r1 = $root . '/sandbox/siterepo/' . $pair . '1';
    $r2 = $root . '/sandbox/siterepo/' . $pair . '2';
    $assertTrees = static function () use ($r1, $r2, $retained): void {
        foreach ([[$r1, 'state', 'source'], [$r2, '.tmp-rmcombo-final', 'recapture']] as [$repo, $relative, $key]) {
            FilesystemTreeEvidence::assertRecord($retained[$key], $relative);
            if (FilesystemTreeEvidence::capture($repo, $relative) !== $retained[$key]) {
                throw new RuntimeException('compiled input differs from the retained complete tree');
            }
        }
    };
    $assertTrees();
    $library = AdapterLibrary::fromSourceTree($root);
    $sourcePolicy = Policy::load($r1, null, false, null, $library);
    $targetPolicy = Policy::load($r2, null, false, null, $library);
    $source = RepositoryCompiler::compile_staged($r1 . '/state', $r1, $sourcePolicy);
    $target = RepositoryCompiler::compile_staged($r2 . '/.tmp-rmcombo-final', $r2, $targetPolicy);
    $proof = RecaptureConvergence::verify($source, $target, $targetPolicy, ...$observations);
    $assertTrees();
    echo json_encode(['status'=>'ok', 'convergence'=>$proof], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'combined recapture convergence refused (' . get_class($error) . '): ' . $error->getMessage() . "\n");
    exit(1);
}
