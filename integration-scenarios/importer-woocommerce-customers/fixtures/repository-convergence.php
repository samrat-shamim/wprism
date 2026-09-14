<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Code/Code.php';
require_once $root . '/agent/src/Code/CodeStateContract.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();
if ($argc !== 3) throw new RuntimeException('combined convergence requires two repository roots');
$library = WPrism\AdapterLibrary::fromSourceTree($root);
$compile = static fn(string $repo): WPrism\CompiledRepository => WPrism\RepositoryCompiler::compile_staged(
    $repo . '/state', $repo, WPrism\Policy::load($repo, adapterLibrary: $library));
// Woo timestamps are declared derived; the compiler alone owns their semantics.
// No target-only exception is granted. Exact SQL preservation is checked separately.
WPrismTest\RepositoryConvergence::assertSame($compile($argv[1]), $compile($argv[2]));
echo "PASS: complete compiled intent and media converge\n";
