<?php
declare(strict_types=1);

// Test-only predicates shared by native evidence and hostile offline controls.
// No fixture value is included in a failure: even disposable observation
// failures must not teach the production path to print credential-bearing rows.
final class MapLifecycleEvidence {
    public const PLUGIN = 'map-block-gutenberg/map-block-gutenberg.php';
    public const TABLES = ['posts', 'postmeta', 'map', 'state', 'options'];
    public const ENTRY_SHA = '9722a500959c60d0261d14f43439a9c74b563ca47a7993066853d1b000ea31ed';

    public static function witness(array $tables): array {
        if (array_keys($tables) !== self::TABLES) throw new RuntimeException('native table inventory differs');
        $result = [];
        foreach ($tables as $name => $rows) {
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > 4096) throw new RuntimeException('native row bound failed');
            foreach ($rows as $row) {
                if (!is_array($row) || $row === [] || array_is_list($row)) throw new RuntimeException('native row shape failed');
            }
            if ($name !== 'postmeta' && count($rows) < 2) throw new RuntimeException('native fixture rows missing');
            if ($name === 'options' && count($rows) !== 2) throw new RuntimeException('native option inventory differs');
            $bytes = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($bytes) > 8388608) throw new RuntimeException('native byte bound failed');
            $result[$name] = ['count' => count($rows), 'sha256' => hash('sha256', $bytes)];
        }
        return $result;
    }

    public static function assertObservation(array $observation): void {
        if (($observation['format'] ?? null) !== 'wprism-map-lifecycle-observation/v1'
            || ($observation['post'] ?? null) !== 7001 || !is_int($observation['created'] ?? null) || $observation['created'] <= 7001
            || ($observation['key_preserved'] ?? null) !== true || ($observation['runtime_preserved'] ?? null) !== true
            || ($observation['maps_bound'] ?? null) !== true
            || !is_array($observation['state'] ?? null) || array_keys($observation['state']) !== self::TABLES) {
            throw new RuntimeException('native lifecycle premise failed');
        }
        foreach ($observation['state'] as $name => $table) {
            if (!is_array($table) || array_keys($table) !== ['count', 'sha256']
                || !is_int($table['count']) || $table['count'] < 0 || $table['count'] > 4096
                || ($name !== 'postmeta' && $table['count'] < 2)
                || ($name === 'options' && $table['count'] !== 2)
                || !is_string($table['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $table['sha256']) !== 1) {
                throw new RuntimeException('native lifecycle witness malformed');
            }
        }
    }

    public static function assertCapability(array $report, bool $active, ?string $version): void {
        foreach (['manifests', 'blockers'] as $key) {
            if (!is_array($report[$key] ?? null) || !array_is_list($report[$key])) throw new RuntimeException('native capability inventory malformed');
            foreach ($report[$key] as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) throw new RuntimeException('native capability row malformed');
            }
        }
        $rows = array_values(array_filter($report['manifests'], static fn(array $row): bool => $row['name'] === 'map-block-gutenberg'));
        if (($report['schema_version'] ?? null) !== 'wprism-capability-report/v1'
            || ($report['query'] ?? null) !== ['operation' => 'apply', 'surface' => null]
            || ($report['ready'] ?? null) !== false || count($rows) !== 1
            || ($rows[0]['status'] ?? null) !== 'experimental'
            || ($rows[0]['verdict']['status'] ?? null) !== 'blocked'
            || !is_array($report['target']['active_plugins'] ?? null)
            || in_array(self::PLUGIN, $report['target']['active_plugins'], true) !== $active
            || !is_array($rows[0]['verdict']['reasons'] ?? null) || !array_is_list($rows[0]['verdict']['reasons'])) {
            throw new RuntimeException('native capability envelope differs');
        }
        $expected = ['authored_state_not_certified'];
        if (!$active) $expected[] = 'plugin_not_active';
        if ($version !== '1.35') $expected[] = 'plugin_version_mismatch';
        $reasons = $rows[0]['verdict']['reasons'];
        foreach ($reasons as $reason) {
            if (!is_array($reason) || !is_string($reason['code'] ?? null)) throw new RuntimeException('native capability reason malformed');
        }
        $codes = array_column($reasons, 'code');
        sort($codes, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($codes !== $expected) throw new RuntimeException('native capability reasons differ');
        $blockers = array_values(array_filter($report['blockers'], static fn(array $row): bool => $row['name'] === 'map-block-gutenberg'));
        $blockerCodes = array_column($blockers, 'code');
        sort($blockerCodes, SORT_STRING);
        if ($blockerCodes !== $expected || count($blockers) !== count($report['blockers'])) throw new RuntimeException('native capability blockers differ');
        foreach ($reasons as $reason) {
            if ($reason['code'] === 'plugin_version_mismatch'
                && (($reason['subject'] ?? null) !== self::PLUGIN || ($reason['observed'] ?? null) !== ($version ?? ''))) {
                throw new RuntimeException('native version refusal lost its observed subject');
            }
        }
    }

    // These closed fixture messages independently name the lifecycle gate;
    // apply_failed alone also describes unrelated transport/compiler failures.
    public static function refusalProfile(string $case): array {
        $plugin = self::PLUGIN;
        $messages = [];
        if ($case === 'inactive') {
            $messages[] = "active_plugins in state/options/core.json declares '$plugin', and its code "
                . 'is installed, but it is not active in this environment. Run \'wprism deploy <env>\' '
                . 'before apply so activation hooks and schema migrations complete first.';
        } elseif (in_array($case, ['absent', 'wrong-basename'], true)) {
            $messages[] = "active_plugins in state/options/core.json declares '$plugin' but "
                . "$plugin does not exist in this environment (checked against this environment's "
                . "wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' means "
                . "'installed on the env'). Install/vendor the plugin here, or this branch's code/ "
                . "changes haven't reached this environment yet.";
            if ($case === 'wrong-basename') {
                $messages[] = "plugin 'map-block-gutenberg/map-fixture-wrong.php' is active in this environment but absent from canonical "
                    . "active_plugins. Run 'wprism deploy <env>' before apply so its deactivation hooks complete first.";
            }
        } elseif (in_array($case, ['1.34', '1.35.1', 'missing'], true)) {
            $version = $case === 'missing' ? '(unknown version)' : $case;
            $messages[] = "$plugin $version is active in this environment, outside the 'map-block-gutenberg' manifest's "
                . 'declared version_range (>=1.35 <1.35.1, pinned by site.wprism.json). '
                . 'Classification guarantees for this plugin are NOT validated against this '
                . 'version — apply may silently misclassify fields. Update the plugin, pin an '
                . 'older manifest, or pass --force-code-mismatch to proceed at your own risk.';
        } else {
            throw new RuntimeException('unknown lifecycle refusal control');
        }
        return ['command' => 'apply', 'reason_code' => 'apply_failed', 'nodes' => [[
            'parent_index' => null, 'relation' => 'root', 'class' => 'RuntimeException',
            'message' => "wprism: apply refused — code_mismatch:\n\n"
                . implode("\n\n", array_map(static fn(string $message): string => '  - ' . $message, $messages))
                . "\n\nRun 'wprism deploy <env>' first for lifecycle reconciliation, "
                . 'or pass --force-code-mismatch to proceed despite those lifecycle mismatches.',
        ]]];
    }

    public static function publicRefusal(): array {
        $message = 'apply refused at an unclassified safety gate';
        $remediation = 'inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase';
        return ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'apply',
            'error' => 'apply_failed', 'reason_code' => 'apply_failed', 'message' => $message,
            'remediation' => $remediation, 'details_redacted' => true,
            'diagnostics' => [['code' => 'apply_failed', 'message' => $message, 'remediation' => $remediation]]];
    }

    public static function assertPrivate(string $case, string $pair, string $stem): void {
        require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
        require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
        if (preg_match('/^[a-z][a-z0-9]*$/D', $pair) !== 1 || basename($stem) !== 'private') throw new RuntimeException('private lifecycle transport binding differs');
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        $read = static fn(string $path, int $exit): array => json_decode(\WPrismTest\PrivateCommandOutput::readObject(
            $path, $prelude, \WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE, $exit
        ), true, 32, JSON_THROW_ON_ERROR);
        $public = $read(dirname($stem) . '/command', 1);
        // The public command bytes are replayed only after this validator.
        // Extra diagnostics, remediation or fields could otherwise disclose
        // target values while the few identifying fields still looked safe.
        if ($public !== self::publicRefusal()) throw new RuntimeException('native lifecycle public refusal differs');
        \WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($read($stem, 0), self::refusalProfile($case));
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (($argv[1] ?? '') === 'private' && count($argv) === 5) {
            MapLifecycleEvidence::assertPrivate($argv[2], $argv[3], $argv[4]);
            exit(0);
        }
        $input = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new RuntimeException('evidence document missing');
        if (($argv[1] ?? '') === 'observation' && count($argv) === 2) {
            MapLifecycleEvidence::assertObservation($input);
        } elseif (($argv[1] ?? '') === 'capability' && count($argv) === 4 && in_array($argv[2], ['active', 'inactive'], true)) {
            MapLifecycleEvidence::assertCapability($input, $argv[2] === 'active', $argv[3] === 'missing' ? null : $argv[3]);
        } else {
            throw new RuntimeException('unknown evidence invocation');
        }
    } catch (Throwable $error) {
        fwrite(STDERR, "Map Block lifecycle evidence refused\n");
        exit(1);
    }
}
