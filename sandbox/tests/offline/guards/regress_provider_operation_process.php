<?php
/** Offline proof for the artifact-bound manifest-provider child protocol. */
declare(strict_types=1);

$repo = dirname(__DIR__, 4);
require_once $repo . '/sandbox/tests/lib/check.php';
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

require_once $repo . '/agent/src/Adapter/ProviderOperationProcess.php';
require_once $repo . '/agent/src/Policy/ArtifactPolicyIdentity.php';
require_once $repo . '/agent/src/Promotion/Deploy.php';
require_once $repo . '/sandbox/tests/lib/frozen_policy.php';

use WPrism\ArtifactPolicyIdentity;
use WPrism\Canon;
use WPrism\Deploy;
use WPrism\ManifestProviderRuntime;
use WPrism\ProviderOperationProcess;
use WPrism\Providers;
use WPrism\Providers\FixtureFresh;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$contract = [
    'args' => [
        'value' => ['required' => true, 'type' => 'string'],
    ],
    'idempotent' => true,
    'reads' => ['option:fixture'],
    'scope' => 'site',
    'scoped' => [
        'operation_envelope' => 'wprism-scoped-effect-operation/v1',
        'receipt_projection' => 'handler',
        'reconcile' => true,
    ],
    'timeout_seconds' => 1,
    'writes' => ['option:fixture'],
];
$actions = [];
foreach (['after', 'dml', 'large', 'recurse'] as $value) {
    $actions[] = [
        'args' => ['value' => $value],
        'capability' => 'execute',
        'effects' => [[
            'id' => 'fixture-option-' . $value,
            'kind' => 'database',
            'mode' => 'restorable',
            'selector' => [
                'scope' => 'database_checkpoint',
                'type' => 'option',
                'value' => 'fixture',
            ],
        ]],
        'kind' => 'provider',
        'provider' => 'fixture-fresh',
        'triggers' => ['option:fixture'],
    ];
}
$manifest = [
    'actions' => $actions,
    'engine_features' => [
        'manifest-provider-fresh-process/v1',
        'manifest-provider-runtime/v1',
        'spec-window/v1',
    ],
    'name' => 'fixture-adapter',
    'option_autoload' => 'preserve',
    'options' => [],
    'plugin' => 'fixture/fixture.php',
    'providers' => [[
        'capabilities' => ['execute'],
        'contracts' => ['execute' => $contract],
        'fresh_process_capabilities' => ['execute'],
        'id' => 'fixture-fresh',
        'plugin' => 'fixture/fixture.php',
        'source' => 'manifest',
        'version' => '1.0.0',
    ]],
    'spec_version' => WPRISM_SPEC_VERSION,
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$libraryRoot = FrozenPolicy::library();
$providerDirectory = $libraryRoot . '/providers';
if (!is_dir($providerDirectory) && !mkdir($providerDirectory, 0700, true) && !is_dir($providerDirectory)) {
    throw new RuntimeException('cannot create fixture provider directory');
}
$providerSource = $repo . '/sandbox/tests/fixtures/providers/fixture-fresh.php';
$providerFile = $providerDirectory . '/fixture-fresh.php';
if (!copy($providerSource, $providerFile)) {
    throw new RuntimeException('cannot publish fixture provider source');
}
$snapshot = FrozenPolicy::envelope([$manifest], $site, $libraryRoot);
$library = FrozenPolicy::adapterLibrary($libraryRoot);
$libraryRoot = $library->root();
$snapshot['dispositions'] = \WPrism\ManifestDispositions::load_library($library)->data();
$providerFile = (string) realpath($providerFile);
$policy = WPrism\Policy::from_snapshot($snapshot, $library);
$resolvedAdapters = ArtifactPolicyIdentity::resolved_adapters($policy);
$artifactHash = hash('sha256', 'fixture compiled artifact');
$policy->bind_execution_artifact_identity(
    $artifactHash,
    ArtifactPolicyIdentity::site_hash($policy),
    ArtifactPolicyIdentity::manifest_hash($policy),
    $resolvedAdapters
);
$executionIdentity = $policy->execution_artifact_identity();
if (!is_array($executionIdentity)) {
    throw new RuntimeException('fixture execution identity did not bind');
}
$store = WpStore::reset();
$store->options['active_plugins'] = ['fixture/fixture.php'];
$optionRows = [
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
];
$wpdb = FakeWpdb::install()
    ->seedTable('wp_options', $optionRows)
    ->setTableEngine('wp_options', 'InnoDB')
    ->enableInformationSchema();

$store->options['active_plugins'] = [];
$durableActiveDiagnosis = Providers::diagnose($policy, $policy->actions_for(['option:fixture']));
$durableActiveAccepted = ($durableActiveDiagnosis['problems'] ?? null) === []
    && isset($durableActiveDiagnosis['providers']['fixture-fresh']);
wprism_check(
    $durableActiveAccepted,
    'fresh-capability negotiation trusts the exact physical lifecycle row over a stale inactive cache'
);
if (!$durableActiveAccepted) {
    wprism_check_detail('durable-active diagnosis=' . json_encode($durableActiveDiagnosis, JSON_UNESCAPED_SLASHES));
}

$store->options['active_plugins'] = ['fixture/fixture.php'];
$inactiveOptionRows = $optionRows;
$inactiveOptionRows[1]['option_value'] = serialize([]);
$wpdb->seedTable('wp_options', $inactiveOptionRows);
$durableInactiveDiagnosis = Providers::diagnose($policy, $policy->actions_for(['option:fixture']));
wprism_check(
    ($durableInactiveDiagnosis['providers'] ?? null) === []
        && ($durableInactiveDiagnosis['problems'][0]['code'] ?? null) === 'inactive_plugin',
    'fresh-capability negotiation refuses an exact inactive lifecycle row despite a stale active cache'
);
$wpdb->seedTable('wp_options', $optionRows);
$store->options['active_plugins'] = ['fixture/fixture.php'];

$runtimeState = Deploy::plugin_runtime_state('fixture/fixture.php');
$adapterDigest = (string) ($resolvedAdapters[0]['digest'] ?? '');
$declaration = $policy->provider_declarations()['fixture-fresh'] + [
    '_wprism_adapter_digest' => $adapterDigest,
    '_wprism_adapter_library_root' => $libraryRoot,
    '_wprism_execution_bound' => true,
    '_wprism_execution_identity' => $executionIdentity,
    '_wprism_plugin_runtime' => $runtimeState,
    '_wprism_policy_snapshot' => $policy->execution_policy_snapshot(),
    '_wprism_provider_file' => $providerFile,
    '_wprism_provider_sha256' => hash_file('sha256', $providerFile),
];

$missingPostimageFailure = null;
try {
    new class($declaration) extends ManifestProviderRuntime {
        protected function invoke_execute(array $args): array {
            return ['before' => [], 'after' => [], 'verified' => true];
        }

        protected function reconcile_execute(array $args): array {
            return [];
        }

        protected function project_execute(array $value): array {
            return $value;
        }
    };
} catch (Throwable $failure) {
    $missingPostimageFailure = $failure;
}
wprism_check(
    $missingPostimageFailure instanceof RuntimeException
        && str_contains($missingPostimageFailure->getMessage(), 'observe_fresh_postimage_execute'),
    'fresh execution requires a distinct complete durable-postimage observer'
);

$provider = Providers::fresh_process_provider(
    $policy,
    'fixture-adapter',
    'fixture-fresh',
    'execute',
    $adapterDigest,
    $executionIdentity,
    $runtimeState,
    ['value' => 'after']
);
$directFailure = null;
try {
    $provider->execute_in_fresh_process('invoke', 'execute', ['value' => 'after']);
} catch (Throwable $failure) {
    $directFailure = $failure;
}
wprism_check(
    $directFailure instanceof RuntimeException
        && str_contains($directFailure->getMessage(), 'no engine child authority'),
    'a provider cannot enter its child handler outside the one-shot engine dispatch'
);

$dispatch = new ReflectionMethod(ProviderOperationProcess::class, 'dispatch');
wprism_check($dispatch->isPrivate(), 'only child_main can mint provider-operation child authority');
$snapshotBytes = Canon::encode($snapshot);
$snapshotPath = tempnam(sys_get_temp_dir(), 'wprism-provider-test-');
if (!is_string($snapshotPath)
    || !chmod($snapshotPath, 0600)
    || file_put_contents($snapshotPath, $snapshotBytes) !== strlen($snapshotBytes)) {
    throw new RuntimeException('cannot freeze fixture policy');
}
$snapshotPath = (string) realpath($snapshotPath);
$request = [
    'adapter' => 'fixture-adapter',
    'adapter_digest' => $adapterDigest,
    'adapter_library_root' => $libraryRoot,
    'args' => ['value' => 'after'],
    'capability' => 'execute',
    'execution_identity' => $executionIdentity,
    'format' => ProviderOperationProcess::REQUEST_FORMAT,
    'operation' => 'invoke',
    'plugin_runtime' => $runtimeState,
    'policy_snapshot_path' => $snapshotPath,
    'policy_snapshot_sha256' => hash('sha256', $snapshotBytes),
    'provider' => 'fixture-fresh',
];
$invokeEncoded = Canon::encode($request);

$wrongFormat = $request;
$wrongFormat['format'] = null;
$providerStateBeforeWrongFormat = FixtureFresh::$state;
$wrongFormatFailure = null;
try {
    $dispatch->invoke(null, Canon::encode($wrongFormat));
} catch (Throwable $failure) {
    $wrongFormatFailure = $failure;
}
wprism_check(
    $wrongFormatFailure instanceof RuntimeException
        && str_contains($wrongFormatFailure->getMessage(), 'malformed fields')
        && FixtureFresh::$state === $providerStateBeforeWrongFormat,
    'a canonical envelope carrying the wrong protocol format refuses before provider behavior'
);

foreach ([
    'false' => false,
    'non-boolean' => null,
    'throw' => new RuntimeException('fixture cache backend exception'),
] as $cacheOutcome => $result) {
    $GLOBALS['wprism_wp_cache_flush_results'] = [$result];
    $providerStateBeforeCacheFailure = FixtureFresh::$state;
    $childCacheFailure = null;
    try {
        $dispatch->invoke(null, $invokeEncoded);
    } catch (Throwable $failure) {
        $childCacheFailure = $failure;
    } finally {
        unset($GLOBALS['wprism_wp_cache_flush_results']);
    }
    wprism_check(
        $childCacheFailure instanceof RuntimeException
            && str_contains($childCacheFailure->getMessage(), 'fresh WordPress cache view')
            && FixtureFresh::$state === $providerStateBeforeCacheFailure,
        "a child cache flush $cacheOutcome outcome refuses before policy or provider behavior"
    );
}

try {
    // Model a process-external object cache that still says the owner is
    // inactive while the physical option row carries the negotiated truth.
    $store->options['active_plugins'] = [];
    $invokeResponse = $dispatch->invoke(null, $invokeEncoded);
    wprism_check_same(
        Canon::encode([
            'adapter' => 'fixture-adapter',
            'adapter_digest' => $adapterDigest,
            'capability' => 'execute',
            'format' => ProviderOperationProcess::RECEIPT_FORMAT,
            'operation' => 'invoke',
            'provider' => 'fixture-fresh',
            'request_sha256' => hash('sha256', $invokeEncoded),
            'result' => [
                'postimage' => ['value' => 'after'],
                'receipt' => [
                    'before' => ['value' => 'before'],
                    'after' => ['value' => 'after'],
                    'verified' => true,
                ],
            ],
        ]),
        Canon::encode($invokeResponse),
        'mutation dispatch binds artifact, adapter, action args, operation, and semantic receipt'
    );
    wprism_check(
        in_array(['op' => 'flush', 'group' => '', 'key' => ''], $store->cacheEvents, true),
        'every child dispatch establishes a checked fresh WordPress cache view before owner and provider replay'
    );
    wprism_check_same(
        [],
        $store->options['active_plugins'],
        'fresh owner replay reads the physical active_plugins row instead of accepting cache-backed lifecycle state'
    );

    $request['operation'] = 'observe';
    $observeEncoded = Canon::encode($request);
    $observeResponse = $dispatch->invoke(null, $observeEncoded);
    wprism_check_same(
        ['postimage' => ['value' => 'after']],
        $observeResponse['result'] ?? null,
        'a separately authorized observe phase projects the complete durable postimage'
    );

    $initialDurableState = Canon::encode([
        'format' => 'wprism-provider-process-fixture/v1',
        'mutation_pid' => 0,
        'value' => 'before',
    ]);
    $durableStatePath = tempnam(sys_get_temp_dir(), 'wprism-provider-state-');
    if (!is_string($durableStatePath)
        || !chmod($durableStatePath, 0600)
        || file_put_contents($durableStatePath, $initialDurableState) !== strlen($initialDurableState)) {
        throw new RuntimeException('cannot create durable provider-process fixture state');
    }
    $durableStatePath = (string) realpath($durableStatePath);
    $childDriver = $repo . '/sandbox/tests/fixtures/providers/provider-operation-child.php';
    $runRealChild = static function (string $input) use ($repo, $durableStatePath, $childDriver): array {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $childDriver, $repo, $durableStatePath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repo,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)
            || !isset($pipes[0], $pipes[1], $pipes[2])
            || !is_resource($pipes[0])
            || !is_resource($pipes[1])
            || !is_resource($pipes[2])) {
            throw new RuntimeException('cannot launch real provider-operation child');
        }
        $offset = 0;
        while ($offset < strlen($input)) {
            $written = fwrite($pipes[0], substr($input, $offset));
            if (!is_int($written) || $written < 1) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_terminate($process, 9);
                proc_close($process);
                throw new RuntimeException('cannot write real provider-operation child request');
            }
            $offset += $written;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [
            'exit' => $exit,
            'stderr' => is_string($stderr) ? $stderr : '',
            'stdout' => is_string($stdout) ? $stdout : '',
        ];
    };

    try {
        $realInvoke = $runRealChild($invokeEncoded);
        $realInvokeResponse = json_decode($realInvoke['stdout'], true, 64, JSON_THROW_ON_ERROR);
        wprism_check(
            $realInvoke['exit'] === 0
                && $realInvoke['stderr'] === ''
                && is_array($realInvokeResponse)
                && Canon::encode($realInvokeResponse) === $realInvoke['stdout']
                && ($realInvokeResponse['result']['receipt']['before']['value'] ?? null) === 'before'
                && ($realInvokeResponse['result']['receipt']['after']['value'] ?? null) === 'after',
            'child_main executes the canonical mutation protocol in an actual separate PHP boot'
        );

        $durableRecord = json_decode(
            (string) file_get_contents($durableStatePath),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        wprism_check(
            is_array($durableRecord)
                && ($durableRecord['format'] ?? null) === 'wprism-provider-process-fixture/v1'
                && is_int($durableRecord['mutation_pid'] ?? null)
                && $durableRecord['mutation_pid'] > 1
                && $durableRecord['mutation_pid'] !== getmypid()
                && ($durableRecord['value'] ?? null) === 'after',
            'the mutation child fsyncs its postimage to durable state under a non-parent process identity'
        );

        $realObserve = $runRealChild($observeEncoded);
        $realObserveResponse = json_decode($realObserve['stdout'], true, 64, JSON_THROW_ON_ERROR);
        wprism_check(
            $realObserve['exit'] === 0
                && $realObserve['stderr'] === ''
                && is_array($realObserveResponse)
                && Canon::encode($realObserveResponse) === $realObserve['stdout']
                && ($realObserveResponse['operation'] ?? null) === 'observe'
                && ($realObserveResponse['result'] ?? null) === ['postimage' => ['value' => 'after']],
            'the observer accepts durable state only from a distinct clean PHP boot'
        );

        if (file_put_contents($durableStatePath, $initialDurableState) !== strlen($initialDurableState)) {
            throw new RuntimeException('cannot reset durable provider-process fixture state');
        }
        require_once $repo . '/sandbox/tests/fixtures/providers/provider-operation-wp-cli-runtime.php';
        $GLOBALS['wprism_provider_operation_child_driver'] = (string) realpath($childDriver);
        $GLOBALS['wprism_provider_operation_repo'] = (string) realpath($repo);
        $GLOBALS['wprism_provider_operation_state'] = $durableStatePath;
        $productReceipt = $provider->invoke_with_deadline(
            'execute',
            ['value' => 'after'],
            hrtime(true) + 30000000000
        );
        $productReceiptMatches = Canon::encode($productReceipt) === Canon::encode([
                'before' => ['value' => 'before'],
                'after' => ['value' => 'after'],
                'verified' => true,
            ]);
        wprism_check(
            $productReceiptMatches,
            'the product launcher validates real mutation and observer children against one absolute deadline'
        );
        if (!$productReceiptMatches) {
            wprism_check_detail('product receipt=' . json_encode($productReceipt, JSON_UNESCAPED_SLASHES));
        }
        wprism_check_same(
            [true, true],
            $GLOBALS['wprism_provider_operation_prelaunch_cache_views'] ?? null,
            'mutation and observer launches each see the parent-flushed cache before WordPress bootstraps'
        );

        if (file_put_contents($durableStatePath, $initialDurableState) !== strlen($initialDurableState)) {
            throw new RuntimeException('cannot reset exact-stderr provider-process fixture state');
        }
        $GLOBALS['wprism_provider_operation_injected_stderr'] = " \n";
        $whitespaceStderrFailure = null;
        try {
            $provider->invoke_with_deadline(
                'execute',
                ['value' => 'after'],
                hrtime(true) + 30000000000
            );
        } catch (Throwable $failure) {
            $whitespaceStderrFailure = $failure;
        } finally {
            unset($GLOBALS['wprism_provider_operation_injected_stderr']);
        }
        wprism_check(
            $whitespaceStderrFailure instanceof RuntimeException
                && str_contains($whitespaceStderrFailure->getMessage(), 'recovery_required'),
            'any child stderr byte, including whitespace, refuses as ambiguous recovery debt'
        );

        if (file_put_contents($durableStatePath, $initialDurableState) !== strlen($initialDurableState)) {
            throw new RuntimeException('cannot reset one-second provider-process fixture state');
        }
        $oneSecondLaunchesBefore = count(
            $GLOBALS['wprism_provider_operation_prelaunch_cache_views'] ?? []
        );
        $oneSecondFailure = null;
        try {
            $provider->invoke('execute', ['value' => 'after']);
        } catch (Throwable $failure) {
            $oneSecondFailure = $failure;
        }
        $oneSecondLaunchesAfter = count(
            $GLOBALS['wprism_provider_operation_prelaunch_cache_views'] ?? []
        );
        wprism_check(
            $oneSecondLaunchesAfter > $oneSecondLaunchesBefore,
            'a one-second fresh capability reaches the launcher without integer-second flooring'
        );
        if ($oneSecondLaunchesAfter === $oneSecondLaunchesBefore) {
            wprism_check_detail(
                'one-second failure=' . ($oneSecondFailure?->getMessage() ?? 'none')
            );
        }

        foreach ([
            'false' => false,
            'non-boolean' => null,
            'throw' => new RuntimeException('fixture parent cache backend exception'),
        ] as $cacheOutcome => $result) {
            if (file_put_contents($durableStatePath, $initialDurableState) !== strlen($initialDurableState)) {
                throw new RuntimeException('cannot reset pre-invoke cache-refusal fixture state');
            }
            $launchesBeforeCacheRefusal = count(
                $GLOBALS['wprism_provider_operation_prelaunch_cache_views'] ?? []
            );
            $GLOBALS['wprism_wp_cache_flush_results'] = [$result];
            $preInvokeCacheFailure = null;
            try {
                $provider->invoke_with_deadline(
                    'execute',
                    ['value' => 'after'],
                    hrtime(true) + 30000000000
                );
            } catch (Throwable $failure) {
                $preInvokeCacheFailure = $failure;
            } finally {
                unset($GLOBALS['wprism_wp_cache_flush_results']);
            }
            $preInvokeRecord = json_decode(
                (string) file_get_contents($durableStatePath),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
            wprism_check(
                $preInvokeCacheFailure instanceof RuntimeException
                    && str_contains($preInvokeCacheFailure->getMessage(), 'pre-boot cache view')
                    && count($GLOBALS['wprism_provider_operation_prelaunch_cache_views'] ?? [])
                        === $launchesBeforeCacheRefusal
                    && ($preInvokeRecord['value'] ?? null) === 'before',
                "a pre-mutation cache flush $cacheOutcome outcome refuses before child/provider execution"
            );
        }

        if (file_put_contents($durableStatePath, $initialDurableState) !== strlen($initialDurableState)) {
            throw new RuntimeException('cannot reset pre-observer cache-refusal fixture state');
        }
        $launchesBeforeObserverRefusal = count(
            $GLOBALS['wprism_provider_operation_prelaunch_cache_views'] ?? []
        );
        $GLOBALS['wprism_wp_cache_flush_results'] = [true, false];
        $preObserverCacheFailure = null;
        try {
            $provider->invoke_with_deadline(
                'execute',
                ['value' => 'after'],
                hrtime(true) + 30000000000
            );
        } catch (Throwable $failure) {
            $preObserverCacheFailure = $failure;
        } finally {
            unset($GLOBALS['wprism_wp_cache_flush_results']);
        }
        $preObserverRecord = json_decode(
            (string) file_get_contents($durableStatePath),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        wprism_check(
            $preObserverCacheFailure instanceof RuntimeException
                && str_contains($preObserverCacheFailure->getMessage(), 'recovery_required')
                && count($GLOBALS['wprism_provider_operation_prelaunch_cache_views'] ?? [])
                    === $launchesBeforeObserverRefusal + 1
                && ($preObserverRecord['value'] ?? null) === 'after',
            'a failed observer pre-boot flush leaves the completed mutation as explicit recovery debt'
        );

        $dmlFailure = null;
        try {
            $provider->invoke_with_deadline(
                'execute',
                ['value' => 'dml'],
                hrtime(true) + 30000000000
            );
        } catch (Throwable $failure) {
            $dmlFailure = $failure;
        }
        wprism_check(
            $dmlFailure instanceof RuntimeException
                && str_contains($dmlFailure->getMessage(), 'recovery_required'),
            'an authored fresh observer cannot issue DML through the engine-owned read-only database boundary'
        );

        $malformedRefusal = $runRealChild('{}\n');
        $malformedWasRefused =
            $malformedRefusal['exit'] !== 0
                && !str_contains($malformedRefusal['stdout'], ProviderOperationProcess::RECEIPT_FORMAT)
                && str_contains($malformedRefusal['stderr'], 'wprism-provider-operation-failed');
        wprism_check(
            $malformedWasRefused,
            'the public child_main entry point refuses a noncanonical envelope in a real child'
        );
        if (!$malformedWasRefused) {
            wprism_check_detail('malformed child=' . json_encode($malformedRefusal, JSON_UNESCAPED_SLASHES));
        }
    } finally {
        @unlink($durableStatePath);
    }

    $unauthorized = $request;
    $unauthorized['operation'] = 'invoke';
    $unauthorized['args'] = ['value' => 'not-authored'];
    $unauthorizedFailure = null;
    try {
        $dispatch->invoke(null, Canon::encode($unauthorized));
    } catch (Throwable $failure) {
        $unauthorizedFailure = $failure;
    }
    wprism_check(
        $unauthorizedFailure instanceof RuntimeException
            && str_contains($unauthorizedFailure->getMessage(), 'frozen action policy'),
        'a typed but unauthored argument value cannot mint child mutation authority'
    );

    $runtimeDrift = $request;
    $runtimeDrift['operation'] = 'invoke';
    $GLOBALS['fixture_plugin_version'] = '1.1.0';
    $runtimeFailure = null;
    try {
        $dispatch->invoke(null, Canon::encode($runtimeDrift));
    } catch (Throwable $failure) {
        $runtimeFailure = $failure;
    } finally {
        $GLOBALS['fixture_plugin_version'] = '1.0.0';
    }
    wprism_check(
        $runtimeFailure instanceof RuntimeException
            && str_contains($runtimeFailure->getMessage(), 'changed after parent negotiation'),
        'plugin activation/version identity is re-read exactly in every child'
    );

    $tamperedSnapshot = $snapshot;
    $tamperedSnapshot['site']['manifests'][0] = ['name' => 'fixture-adapter'];
    $tamperedBytes = Canon::encode($tamperedSnapshot);
    file_put_contents($snapshotPath, $tamperedBytes);
    $siteDrift = $request;
    $siteDrift['operation'] = 'invoke';
    $siteDrift['policy_snapshot_sha256'] = hash('sha256', $tamperedBytes);
    $siteDriftFailure = null;
    try {
        $dispatch->invoke(null, Canon::encode($siteDrift));
    } catch (Throwable $failure) {
        $siteDriftFailure = $failure;
    } finally {
        file_put_contents($snapshotPath, $snapshotBytes);
    }
    wprism_check(
        $siteDriftFailure instanceof RuntimeException
            && str_contains($siteDriftFailure->getMessage(), 'does not match its frozen policy'),
        'a valid rehashed site policy still cannot detach execution from the compiled artifact'
    );

    $dispositionFile = $library->package('fixture-adapter')->dispositionPath();
    $originalDispositionBytes = (string) file_get_contents($dispositionFile);
    $changedDisposition = Canon::decode($originalDispositionBytes);
    $changedDisposition['reason'] .= ' changed after parent validation';
    file_put_contents($dispositionFile, Canon::encode($changedDisposition));
    $dispositionDrift = $request;
    $dispositionDrift['operation'] = 'invoke';
    $dispositionDrift['args'] = ['value' => 'dml'];
    $providerStateBeforeDispositionDrift = FixtureFresh::$state;
    $dispositionFailure = null;
    try {
        $dispatch->invoke(null, Canon::encode($dispositionDrift));
    } catch (Throwable $failure) {
        $dispositionFailure = $failure;
    } finally {
        file_put_contents($dispositionFile, $originalDispositionBytes);
    }
    wprism_check(
        $dispositionFailure instanceof RuntimeException
            && str_contains($dispositionFailure->getMessage(), 'current shipped disposition bytes')
            && FixtureFresh::$state === $providerStateBeforeDispositionDrift,
        'reviewed disposition drift after parent validation refuses before provider behavior executes'
    );

    $originalProviderBytes = (string) file_get_contents($providerFile);
    file_put_contents($providerFile, $originalProviderBytes . "\n// identity drift\n");
    $sourceFailure = null;
    try {
        $dispatch->invoke(null, Canon::encode($request));
    } catch (Throwable $failure) {
        $sourceFailure = $failure;
    } finally {
        file_put_contents($providerFile, $originalProviderBytes);
    }
    wprism_check(
        $sourceFailure instanceof RuntimeException
            && str_contains($sourceFailure->getMessage(), 'does not match its frozen policy'),
        'provider package bytes changed after artifact validation are refused before behavior'
    );

    $recursive = $request;
    $recursive['operation'] = 'invoke';
    $recursive['args'] = ['value' => 'recurse'];
    $recursiveFailure = null;
    try {
        $dispatch->invoke(null, Canon::encode($recursive));
    } catch (Throwable $failure) {
        $recursiveFailure = $failure;
    }
    wprism_check(
        $recursiveFailure instanceof RuntimeException
            && str_contains($recursiveFailure->getMessage(), 'cannot launch recursively'),
        'one-shot child authority remains armed while provider behavior runs and blocks nested fresh children'
    );
    wprism_check_same('after', FixtureFresh::$state, 'identity and recursion refusals leave durable state unchanged');

    $large = $request;
    $large['operation'] = 'invoke';
    $large['args'] = ['value' => 'large'];
    $largeFailure = null;
    try {
        $dispatch->invoke(null, Canon::encode($large));
    } catch (Throwable $failure) {
        $largeFailure = $failure;
    }
    wprism_check(
        $largeFailure instanceof RuntimeException
            && str_contains($largeFailure->getMessage(), 'fixed output boundary'),
        'raw child semantic evidence is bounded before stdout transport'
    );
    FixtureFresh::$state = 'after';

    $oversizedDeclaration = $declaration;
    $oversizedAction = $actions[0] + ['manifest' => 'fixture-adapter', 'index' => 0];
    $oversizedAction['args']['value'] = str_repeat('x', 524289);
    $preflightFailure = null;
    try {
        ProviderOperationProcess::preflight($oversizedDeclaration, [$oversizedAction]);
    } catch (Throwable $failure) {
        $preflightFailure = $failure;
    }
    wprism_check(
        $preflightFailure instanceof RuntimeException
            && str_contains($preflightFailure->getMessage(), 'action arguments exceed'),
        'oversized authored arguments refuse in negotiation preflight, before target mutation'
    );

    $nonCanonical = str_replace("\n    \"adapter\"", "\n  \"adapter\"", $invokeEncoded);
    $canonicalFailure = null;
    try {
        $dispatch->invoke(null, $nonCanonical);
    } catch (Throwable $failure) {
        $canonicalFailure = $failure;
    }
    wprism_check(
        $canonicalFailure instanceof RuntimeException,
        'the child accepts only the engine canonical request encoding'
    );
} finally {
    @unlink($snapshotPath);
}

wprism_check_summary('provider operation process');
