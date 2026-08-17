<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Duo dev toolchain's self-tests.
 *
 * The only autoloader in this repository lives here. agent/, cli/ and
 * recovery/ deliberately have none — they are a WordPress drop-in installed by
 * `tar -cf … agent manifests recovery` (cli/src/Adopt.php:120), so a managed
 * site never receives vendor/ and the drop-in must resolve every symbol with
 * its own require_once chains. Composer's autoload-dev therefore maps ONLY
 * Duo\Tests\ => tests/; tests that need product code require the specific file,
 * exactly the way the product does. PhpstanBaselineRatchetTest asserts that
 * separation still holds by grepping the drop-in for vendor/autoload.php.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);

if (!defined('DUO_REPO_ROOT')) {
    define('DUO_REPO_ROOT', dirname(__DIR__));
}
