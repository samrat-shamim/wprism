<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Onboarding/Init.php';
require_once __DIR__ . '/../Code/WpOrgReleases.php';

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
        callable $readLine
    ): int {
        $yes = false;
        $allowUnmanagedPlugins = false;
        // DUO-3499. `split` is the default because a repository that carries
        // every third-party byte in Git for a site whose plugins are published,
        // versioned and byte-verifiable is carrying them for no reason; `full`
        // is the explicit way back to that shape, and both say so out loud
        // before the proposal is rendered.
        $codeMode = 'split';
        $offline = false;
        $cacheDir = null;
        foreach ($extra as $arg) {
            if ($arg === '--yes') {
                $yes = true;
                continue;
            }
            if ($arg === Init::ALLOW_UNMANAGED_PLUGINS) {
                $allowUnmanagedPlugins = true;
                continue;
            }
            if ($arg === '--code=split' || $arg === '--code=full') {
                $codeMode = substr($arg, strlen('--code='));
                continue;
            }
            if ($arg === '--offline') {
                $offline = true;
                continue;
            }
            if (is_string($arg) && str_starts_with($arg, '--cache-dir=')) {
                $cacheDir = substr($arg, strlen('--cache-dir='));
                if ($cacheDir === '' || !str_starts_with($cacheDir, '/')) {
                    fwrite(STDERR, "duo: init --cache-dir requires an absolute path\n");
                    return 1;
                }
                continue;
            }
            fwrite(
                STDERR,
                'duo: init accepts only --yes, ' . Init::ALLOW_UNMANAGED_PLUGINS
                    . ", --code=split|full, --offline and --cache-dir=<path>; unsupported argument '$arg'\n"
            );
            return 1;
        }

        try {
            $proposal = Init::proposal($transport, $allowUnmanagedPlugins);
        } catch (InitRefusalException $e) {
            fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
            $renderRefusal($e->refusal);
            return 1;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'not a registered wp command')
                || str_contains(strtolower($message), 'duo is not a registered')) {
                $message .= "; install the Duo agent first (run 'duo adopt {$transport->name()}' for an SSH or explicitly authorized local environment)";
            }
            fwrite(STDERR, "duo: $message\n");
            return 1;
        }

        $lockPlan = null;
        if (!empty($proposal['ready'])) {
            try {
                [$proposal, $lockPlan] = self::classify(
                    $transport,
                    $proposal,
                    $allowUnmanagedPlugins,
                    $codeMode,
                    $offline,
                    $cacheDir
                );
            } catch (InitRefusalException $e) {
                fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
                $renderRefusal($e->refusal);
                return 1;
            } catch (\Throwable $e) {
                fwrite(STDERR, 'duo: init code classification failed: ' . $e->getMessage() . "\n");
                return 1;
            }
        }

        foreach (Init::render($proposal) as $line) {
            echo $line . "\n";
        }
        if (empty($proposal['ready'])) {
            return 2;
        }
        $digest = (string) ($proposal['digest'] ?? '');
        if (!$yes) {
            fwrite(STDOUT, "Initialize '{$transport->name()}' from proposal $digest? [y/N] ");
            $answer = $readLine();
            if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                echo "Initialization cancelled; no configuration, state, identity, or ledger mutation was made.\n";
                return 1;
            }
        }

        try {
            // The same flag on the confirmation. The proposal digest binds
            // the plan the flag produced, so the target has to re-plan under
            // the same rules or the bind fails — see Init::ALLOW_UNMANAGED_PLUGINS.
            $result = Init::confirm($transport, $digest, $allowUnmanagedPlugins, $lockPlan);
        } catch (InitRefusalException $e) {
            fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
            $renderRefusal($e->refusal);
            return 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
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
            fwrite(STDERR, "duo: initialization captured a baseline, but the selected managed scope is not clean\n");
            return $status;
        }
        foreach (Init::nextSteps(
            $transport->name(),
            (string) ($result['state']['repository'] ?? $transport->repoPath()),
            $lockPlan !== null
        ) as $line) {
            echo $line . "\n";
        }
        return 0;
    }

    /**
     * Classify each active component on the HOST, then re-propose so the
     * classification is inside the digest the operator confirms.
     *
     * Three target calls instead of two, deliberately: the first proposal is
     * what tells the host which components exist and what they hash to, the
     * classification needs a package registry the target must never reach, and
     * the reviewed proposal has to be the one that carries the decision. When
     * nothing locks, there is no second call and the repository is exactly the
     * fully vendored shape every pre-DUO-3499 init produced.
     *
     * @param array<string,mixed> $proposal
     * @return array{0:array<string,mixed>,1:?list<array<string,mixed>>}
     */
    private static function classify(
        EnvironmentDriver $transport,
        array $proposal,
        bool $allowUnmanagedPlugins,
        string $codeMode,
        bool $offline,
        ?string $cacheDir
    ): array {
        if ($codeMode === 'full') {
            echo "duo: --code=full: every active component is vendored into Git (code format 1).\n";
            return [$proposal, null];
        }
        $inventory = (array) ($proposal['code']['component_inventory'] ?? []);
        if ($inventory === []) {
            echo "duo: this site has no lockable plugin or theme component; the code half stays fully vendored.\n";
            return [$proposal, null];
        }
        if ($offline) {
            echo 'duo: --offline: no release registry was contacted, so no component could be verified against a '
                . "published archive and every one is vendored into Git (code format 1).\n";
            return [$proposal, null];
        }
        $releases = new WpOrgReleases($cacheDir ?? WpOrgReleases::defaultCacheDir(), false);
        $plan = $releases->classify(array_map(
            static fn(array $row): array => [
                'root' => (string) $row['root'],
                'component' => (string) $row['component'],
                'version' => (string) $row['version'],
                'tree_sha256' => (string) $row['tree_sha256'],
            ],
            $inventory
        ));
        $locked = array_values(array_filter(
            $plan,
            static fn(array $row): bool => ($row['classification'] ?? null) === 'locked'
        ));
        if ($locked === []) {
            echo 'duo: no active component hash-matched a published release archive, so the code half stays fully '
                . "vendored (code format 1); each component's reason is printed with the proposal below.\n";
            foreach ($plan as $row) {
                echo '  VENDORED ' . $row['root'] . '/' . $row['component'] . ' — ' . $row['reason'] . "\n";
            }
            return [$proposal, null];
        }
        return [Init::proposal($transport, $allowUnmanagedPlugins, $plan), $plan];
    }
}
