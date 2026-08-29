<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the WPrism dev toolchain's self-tests.
 *
 * The only autoloader in this repository lives here. agent/, cli/ and
 * recovery/ deliberately have none — adoption assembles the adapter library
 * into the staged agent and ships only `agent recovery`, so a managed site
 * never receives vendor/ and the drop-in must resolve every symbol with its
 * own require_once chains. Composer's autoload-dev therefore maps ONLY
 * WPrism\Tests\ => tests/; tests that need product code require the specific file,
 * exactly the way the product does. PhpstanBaselineRatchetTest asserts that
 * separation still holds by grepping the drop-in for vendor/autoload.php.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);

if (!defined('WPRISM_REPO_ROOT')) {
    define('WPRISM_REPO_ROOT', dirname(__DIR__));
}
