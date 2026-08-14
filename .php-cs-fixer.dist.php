<?php
declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__ . '/sandbox/catalog/fragments/engineering-platform')
    ->exclude('generated');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
