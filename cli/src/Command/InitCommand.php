<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';
require_once __DIR__ . '/../Onboarding/Init.php';
require_once __DIR__ . '/../Onboarding/DockerDatabaseSetup.php';
require_once __DIR__ . '/../Code/CodeClassifier.php';

/** Host command handler for the digest-bound initialization workflow. */
final class InitCommand {
    /**
     * Run init's host orchestration while leaving proposal/confirmation
     * validation and target protocol ownership in Init.
     *
     * @param list<string> $extra
     * @param callable(array):void $renderRefusal
     * @param callable(EnvironmentDriver):int $statusRunner
     * @param callable():mixed $readLine
     */
    public static function run(
        EnvironmentDriver $transport,
        array $extra,
        callable $renderRefusal,
        callable $statusRunner,
        callable $readLine,
        bool $renderNextSteps = true
    ): int {
        $yes = false;
        $allowUnmanagedPlugins = false;
        $firstPartyValues = [];
        $offline = false;
        $cacheDir = null;
        $archiveInterruptedTo = null;
        $configureDatabase = false;
        $databaseService = null;
        foreach ($extra as $arg) {
            if ($arg === '--yes') {
                $yes = true;
                continue;
            }
            if ($arg === Init::ALLOW_UNMANAGED_PLUGINS) {
                $allowUnmanagedPlugins = true;
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, CodeClassifier::FIRST_PARTY_FLAG)) {
                $firstPartyValues[] = substr($arg, strlen(CodeClassifier::FIRST_PARTY_FLAG));
                continue;
            }
            if ($arg === '--offline') {
                $offline = true;
                continue;
            }
            if ($arg === DockerDatabaseSetup::CONFIGURE_FLAG) {
                if ($configureDatabase) {
                    fwrite(STDERR, "wprism: init --configure-database was supplied more than once\n");
                    return 1;
                }
                $configureDatabase = true;
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, DockerDatabaseSetup::SERVICE_FLAG)) {
                if ($databaseService !== null) {
                    fwrite(STDERR, "wprism: init --database-service was supplied more than once\n");
                    return 1;
                }
                $databaseService = substr($arg, strlen(DockerDatabaseSetup::SERVICE_FLAG));
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, '--cache-dir=')) {
                $cacheDir = substr($arg, strlen('--cache-dir='));
                if ($cacheDir === '' || !str_starts_with($cacheDir, '/')) {
                    fwrite(STDERR, "wprism: init --cache-dir requires an absolute path\n");
                    return 1;
                }
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, '--archive-interrupted-to=')) {
                if ($archiveInterruptedTo !== null) {
                    fwrite(STDERR, "wprism: init accepts --archive-interrupted-to exactly once\n");
                    return 1;
                }
                $archiveInterruptedTo = substr($arg, strlen('--archive-interrupted-to='));
                if ($archiveInterruptedTo === '' || !str_starts_with($archiveInterruptedTo, '/')) {
                    fwrite(STDERR, "wprism: init --archive-interrupted-to requires an absolute sibling path\n");
                    return 1;
                }
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, '--code=')) {
                // Named, because the flag existed (issue #3499: `--code=split|full`)
                // and an operator with it in a script deserves the reason
                // rather than "unsupported argument". There is no mode: Git
                // never carries third-party code, so "vendor everything" is
                // not a choice the command offers.
                fwrite(
                    STDERR,
                    'wprism: init no longer takes --code: Git never carries third-party code, so every component either '
                    . "locks (wp.org release or `wprism code-import`ed archive) or is declared the site's own with "
                    . CodeClassifier::FIRST_PARTY_FLAG . "<root>/<slug>\n"
                );
                return 1;
            }
            fwrite(
                STDERR,
                'wprism: init accepts only --yes, ' . Init::ALLOW_UNMANAGED_PLUGINS . ', '
                    . CodeClassifier::FIRST_PARTY_FLAG . '<root>/<slug>, --offline and --cache-dir=<path>; '
                    . '--configure-database with --database-service=<name>; '
                    . '--archive-interrupted-to=<absolute-sibling>; '
                    . "unsupported argument '$arg'\n"
            );
            return 1;
        }
        if ($configureDatabase !== ($databaseService !== null)) {
            fwrite(STDERR, "wprism: init --configure-database and --database-service=<name> must be supplied together\n");
            return 1;
        }
        if ($databaseService !== null) {
            try {
                DockerDatabaseSetup::assertServiceName($databaseService);
            } catch (\Throwable $e) {
                fwrite(STDERR, 'wprism: init: ' . $e->getMessage() . "\n");
                return 1;
            }
        }
        if ($archiveInterruptedTo !== null) {
            if ($allowUnmanagedPlugins || $firstPartyValues !== [] || $offline || $cacheDir !== null
                || $configureDatabase) {
                fwrite(STDERR, "wprism: init --archive-interrupted-to is exclusive with discovery and classification flags\n");
                return 1;
            }
            if (!$yes) {
                fwrite(
                    STDOUT,
                    "Archive the complete interrupted repository for '{$transport->name()}' to $archiveInterruptedTo and recreate its configured path? [y/N] "
                );
                $answer = $readLine();
                if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                    echo "Interrupted-init archive cancelled; no path was moved.\n";
                    return 1;
                }
            }
            try {
                $receipt = Init::archiveInterrupted($transport, $archiveInterruptedTo);
            } catch (InitRefusalException $e) {
                fwrite(STDERR, 'wprism: ' . $e->getMessage() . "\n");
                $renderRefusal($e->refusal);
                return 1;
            } catch (\Throwable $e) {
                fwrite(STDERR, 'wprism: init interrupted archive failed: ' . $e->getMessage() . "\n");
                return 1;
            }
            echo 'Archived the complete interrupted repository at ' . $receipt['archive'] . ".\n";
            echo 'Recreated the configured empty path at ' . $receipt['repository'] . ".\n";
            echo 'Archive receipt ' . $receipt['receipt_sha256'] . "; rerun wprism init for a fresh proposal.\n";
            return 0;
        }
        try {
            $firstParty = CodeClassifier::parseFirstParty($firstPartyValues);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'wprism: init: ' . $e->getMessage() . "\n");
            return 1;
        }

        try {
            $proposal = Init::proposal($transport, $allowUnmanagedPlugins);
        } catch (InitRefusalException $e) {
            fwrite(STDERR, 'wprism: ' . $e->getMessage() . "\n");
            $renderRefusal($e->refusal);
            return 1;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'not a registered wp command')
                || str_contains(strtolower($message), 'wprism is not a registered')) {
                $message .= "; install the WPrism agent first (run 'wprism adopt {$transport->name()}' for an SSH or explicitly authorized local environment)";
            }
            fwrite(STDERR, "wprism: $message\n");
            return 1;
        }

        $databaseGrantChanged = false;
        $databaseGrantUncertain = false;
        $databaseGrantAccount = null;
        if ($configureDatabase) {
            if (!$transport instanceof DockerTransport) {
                fwrite(STDERR, "wprism: --configure-database is supported only for a machine-local Docker environment\n");
                return 1;
            }
            try {
                $databasePlan = DockerDatabaseSetup::plan(
                    $transport,
                    (string) $databaseService,
                    $proposal
                );
                $databaseGrantAccount = $databasePlan['account'];
                if ($databasePlan['required'] === true) {
                    echo "Database setup will grant only direct PROCESS on *.* to the exact WordPress account.\n";
                    echo 'PROCESS is server-wide because the complete InnoDB foreign-key census is server-wide; '
                        . "no user, password, schema, table privilege, ALL, or GRANT OPTION will change.\n";
                    echo 'Target: Compose project ' . $databasePlan['compose_project']
                        . ', service ' . $databasePlan['database_service']
                        . ', schema ' . $databasePlan['schema']
                        . ', account ' . $databasePlan['account']['user'] . '@' . $databasePlan['account']['host']
                        . ', server ' . substr((string) $databasePlan['server_identity'], 0, 12) . "...\n";
                    if (!$yes) {
                        $account = (string) $databasePlan['account']['user'] . '@'
                            . (string) $databasePlan['account']['host'];
                        fwrite(
                            STDOUT,
                            "Grant server-wide PROCESS to $account through Docker Compose service "
                                . $databasePlan['database_service'] . '? [y/N] '
                        );
                        $answer = $readLine();
                        if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                            echo "Database setup cancelled; no privilege was changed.\n";
                            return 1;
                        }
                    }
                    $databaseReceipt = DockerDatabaseSetup::apply($transport, $databasePlan, $proposal);
                    $databaseGrantChanged = $databaseReceipt['changed'];
                    if ($databaseGrantChanged) {
                        echo "Granted direct server-wide PROCESS; the grant is durable and is not transactionally reversible.\n";
                    }
                } else {
                    echo "Database setup: the exact WordPress account already has direct global PROCESS; no privilege changed.\n";
                }
                // This is the product's authoritative capture boundary through
                // the actual WordPress account, not the admin connection's view.
                $proposal = Init::proposal($transport, $allowUnmanagedPlugins);
            } catch (\Throwable $e) {
                fwrite(STDERR, 'wprism: Docker database setup failed: ' . $e->getMessage() . "\n");
                $databaseGrantUncertain = $e instanceof DockerDatabaseSetupException
                    && $e->grantMayHaveSucceeded();
                if ($databaseGrantChanged || $databaseGrantUncertain) {
                    self::renderDurableDatabaseGrantNotice($databaseGrantAccount, $databaseGrantUncertain);
                }
                return 1;
            }
        }

        $lockPlan = null;
        if (!empty($proposal['ready'])) {
            try {
                [$proposal, $lockPlan] = self::classify(
                    $transport,
                    $proposal,
                    $allowUnmanagedPlugins,
                    $firstParty,
                    $offline,
                    $cacheDir
                );
            } catch (InitRefusalException $e) {
                fwrite(STDERR, 'wprism: ' . $e->getMessage() . "\n");
                $renderRefusal($e->refusal);
                if ($databaseGrantChanged) {
                    self::renderDurableDatabaseGrantNotice($databaseGrantAccount);
                }
                return 1;
            } catch (\Throwable $e) {
                fwrite(STDERR, 'wprism: init code classification failed: ' . $e->getMessage() . "\n");
                if ($databaseGrantChanged) {
                    self::renderDurableDatabaseGrantNotice($databaseGrantAccount);
                }
                return 1;
            }
        } elseif ($firstParty !== []) {
            // The first proposal is blocked on something else entirely, so the
            // classification never ran; say so, or the operator re-reads their
            // --first-party spelling for a refusal that has nothing to do with it.
            echo "wprism: the proposal is blocked before code classification; --first-party was not evaluated.\n";
        }

        foreach (Init::render($proposal) as $line) {
            echo $line . "\n";
        }
        if (empty($proposal['ready'])) {
            if ($databaseGrantChanged) {
                self::renderDurableDatabaseGrantNotice($databaseGrantAccount);
            }
            return 2;
        }
        $digest = (string) ($proposal['digest'] ?? '');
        if (!$yes) {
            fwrite(STDOUT, "Initialize '{$transport->name()}' from proposal $digest? [y/N] ");
            $answer = $readLine();
            if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                if ($databaseGrantChanged) {
                    echo "Initialization cancelled; no WPrism configuration, state, identity, or ledger mutation was made.\n";
                    self::renderDurableDatabaseGrantNotice($databaseGrantAccount);
                } else {
                    echo "Initialization cancelled; no configuration, state, identity, or ledger mutation was made.\n";
                }
                return 1;
            }
        }

        try {
            // The same flag on the confirmation. The proposal digest binds
            // the plan the flag produced, so the target has to re-plan under
            // the same rules or the bind fails — see Init::ALLOW_UNMANAGED_PLUGINS.
            $result = Init::confirm($transport, $digest, $allowUnmanagedPlugins, $lockPlan);
        } catch (InitRefusalException $e) {
            fwrite(STDERR, 'wprism: ' . $e->getMessage() . "\n");
            $renderRefusal($e->refusal);
            if ($databaseGrantChanged) {
                self::renderDurableDatabaseGrantNotice($databaseGrantAccount);
            }
            return 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'wprism: ' . $e->getMessage() . "\n");
            if ($databaseGrantChanged) {
                self::renderDurableDatabaseGrantNotice($databaseGrantAccount);
            }
            return 1;
        }

        $baseline = $result['baseline'] ?? [];
        if (($result['recovery'] ?? null) === 'committed-finalized') {
            echo "Verified the interrupted committed init and cleared its sealed recovery journal.\n";
            echo 'Recovered canonical state baseline ' . ($baseline['revision_hash'] ?? '(unknown)') . ".\n";
            echo 'Recovered separate code baseline ' . ($result['code']['revision_hash'] ?? '(unknown)') . ".\n";
        } else {
            echo 'Initialized canonical state baseline ' . ($baseline['revision_hash'] ?? '(unknown)') . ".\n";
            echo 'Initialized separate code baseline ' . ($result['code']['revision_hash'] ?? '(unknown)') . ".\n";
        }
        echo ($baseline['rollback_note'] ?? 'This is a state baseline, not a code-and-database rollback checkpoint.') . "\n";
        echo "Verifying selected managed scope:\n";
        $status = $statusRunner($transport);
        if ($status !== 0) {
            fwrite(STDERR, "wprism: initialization captured a baseline, but the selected managed scope is not clean\n");
            return $status;
        }
        if ($renderNextSteps) {
            foreach (Init::nextSteps(
                $transport->name(),
                (string) ($result['state']['repository'] ?? $transport->repoPath()),
                $lockPlan !== null && CodeClassifier::lockRows($lockPlan) !== []
            ) as $line) {
                echo $line . "\n";
            }
        }
        return 0;
    }

    private static function renderDurableDatabaseGrantNotice(mixed $account, bool $uncertain = false): void {
        $identity = is_array($account)
            ? (string) ($account['user'] ?? '?') . '@' . (string) ($account['host'] ?? '?')
            : 'the WordPress account';
        fwrite(
            STDERR,
            'wprism: direct PROCESS ' . ($uncertain ? 'may remain' : 'remains') . " granted to $identity; "
                . 'database grants are not transactionally reversible. '
                . 'Resolve the reported init blocker and rerun init. Revoke PROCESS only after verifying no WPrism '
                . "transactional operation still requires the complete foreign-key census.\n"
        );
    }

    /**
     * Classify each active component on the HOST, then re-propose so the
     * classification is inside the digest the operator confirms.
     *
     * Three target calls instead of two, deliberately: the first proposal is
     * what tells the host which components exist and what they hash to, the
     * classification needs a package registry and a host cache the target must
     * never reach, and the reviewed proposal has to be the one that carries
     * the decision. The only site that skips the second call is one with no
     * lockable component at all: there is nothing Git could carry by omission,
     * so there is nothing to declare.
     *
     * An unsourced component is NOT decided here. The plan carries it as
     * `unsourced` with the reason, and the agent turns that into a blocking
     * `code_component_unsourced` row on the re-proposal, so the operator reads
     * the component, the reason, and both remedies in the proposal itself.
     *
     * @param array<string,mixed> $proposal
     * @param list<string> $firstParty sorted `{root}/{component}` identities
     * @return array{0:array<string,mixed>,1:?list<array<string,mixed>>}
     */
    private static function classify(
        EnvironmentDriver $transport,
        array $proposal,
        bool $allowUnmanagedPlugins,
        array $firstParty,
        bool $offline,
        ?string $cacheDir
    ): array {
        $inventory = array_map(
            static fn(array $row): array => [
                'root' => (string) $row['root'],
                'component' => (string) $row['component'],
                'version' => (string) $row['version'],
                'tree_sha256' => (string) $row['tree_sha256'],
            ],
            (array) ($proposal['code']['component_inventory'] ?? [])
        );
        if ($inventory === []) {
            if ($firstParty !== []) {
                throw new \RuntimeException(
                    '--first-party names ' . implode(', ', $firstParty) . ', but this site has no lockable plugin '
                    . 'or theme component'
                );
            }
            echo "wprism: this site has no lockable plugin or theme component; there is no code classification to make.\n";
            return [$proposal, null];
        }
        CodeClassifier::assertFirstPartyKnown($inventory, $firstParty);
        if ($offline) {
            echo 'wprism: --offline: no release registry is contacted; wp.org components lock only from the host cache, '
                . "imported archives as usual.\n";
        }
        $wordpressVersion = $proposal['environment']['wordpress'] ?? null;
        if (!is_string($wordpressVersion)) {
            throw new \RuntimeException('the init proposal carries no WordPress core version for code classification');
        }
        $plan = CodeClassifier::make($cacheDir, $offline)->classify($inventory, $firstParty, $wordpressVersion);
        $unsourced = CodeClassifier::unsourced($plan);
        if ($unsourced !== []) {
            echo 'wprism: ' . count($unsourced) . ' component(s) could not be sourced; the proposal below is blocked on each '
                . 'of them, with the reason and both remedies (import its archive with `wprism code-import`, or declare it '
                . 'the site\'s own code with ' . CodeClassifier::FIRST_PARTY_FLAG . "<root>/<slug>).\n";
        }
        return [Init::proposal($transport, $allowUnmanagedPlugins, $plan), $plan];
    }
}
