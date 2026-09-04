<?php
/** Real-process boot for the ProviderOperationProcess stdin protocol suite. */
declare(strict_types=1);

$repo = isset($argv[1]) && is_string($argv[1]) ? realpath($argv[1]) : false;
$state = isset($argv[2]) && is_string($argv[2]) ? realpath($argv[2]) : false;
if ($repo === false || !is_dir($repo) || $state === false || !is_file($state)) {
    fwrite(STDERR, "provider-operation fixture boot refused\n");
    exit(126);
}
$mode = fileperms($state);
if (!is_int($mode) || ($mode & 0777) !== 0600) {
    fwrite(STDERR, "provider-operation fixture state is unsafe\n");
    exit(126);
}
define('WPRISM_PROVIDER_PROCESS_FIXTURE_STATE', $state);

require_once $repo . '/sandbox/tests/lib/wp_stubs.php';
require_once $repo . '/sandbox/tests/lib/FakeWpdb.php';

$bootstrap = (string) file_get_contents($repo . '/agent/wprism.php');
preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $bootstrap, $specMatch);
preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $bootstrap, $agentMatch);
define('WPRISM_SPEC_VERSION', (int) ($specMatch[1] ?? 0));
define('WPRISM_AGENT_VERSION', (string) ($agentMatch[1] ?? '0.0.0'));

$GLOBALS['fixture_plugin_installed'] = true;
$GLOBALS['fixture_plugin_version'] = '1.0.0';
if (!function_exists('validate_plugin')) {
    function validate_plugin(string $plugin): mixed {
        return $plugin === 'fixture/fixture.php' && $GLOBALS['fixture_plugin_installed'] === true
            ? null
            : new WP_Error('invalid_plugin', 'fixture plugin is absent');
    }
}
if (!function_exists('get_plugins')) {
    function get_plugins(): array {
        return $GLOBALS['fixture_plugin_installed'] === true
            ? ['fixture/fixture.php' => ['Version' => $GLOBALS['fixture_plugin_version']]]
            : [];
    }
}

$store = \WPrismTest\WpStore::reset();
$store->options['active_plugins'] = ['fixture/fixture.php'];
\WPrismTest\FakeWpdb::install()
    ->seedTable('wp_options', [
        [
            'option_id' => 1,
            'option_name' => 'fixture',
            'option_value' => 'durable',
            'autoload' => 'yes',
        ],
        [
            'option_id' => 2,
            'option_name' => 'active_plugins',
            'option_value' => serialize(['fixture/fixture.php']),
            'autoload' => 'yes',
        ],
    ])
    ->setTableEngine('wp_options', 'InnoDB')
    ->enableInformationSchema();

require_once $repo . '/agent/src/Promotion/Deploy.php';
require_once $repo . '/agent/src/Adapter/ProviderOperationProcess.php';
require_once $repo . '/agent/src/Policy/ArtifactPolicyIdentity.php';

\WPrism\ProviderOperationProcess::child_main();
