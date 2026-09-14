<?php
declare(strict_types=1);

require_once __DIR__ . '/settings-evidence.php';
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/SqlDumpEvidence.php';

final class ImporterDependencyEvidence {
    public const PLUGIN = 'users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php';

    public static function profile(string $case): array {
        $plugin = self::PLUGIN;
        if ($case === 'deactivated') {
            return ['command' => 'apply', 'reason_code' => 'apply_refused', 'nodes' => [[
                'class' => WPrism\CommandRefusalException::class, 'parent_index' => null, 'relation' => 'root',
                'message' => "wprism: ordinary target drift requires capture/reconciliation before apply; no target mutation attempted:\n  - options/core.json",
            ]]];
        }
        if ($case === 'inactive') {
            $finding = "active_plugins in state/options/core.json declares '$plugin', and its code is installed, but it is not active in this environment. Run 'wprism deploy <env>' before apply so activation hooks and schema migrations complete first.";
            $message = "wprism: apply refused — code_mismatch:\n\n  - $finding\n\n"
                . "Run 'wprism deploy <env>' first for lifecycle reconciliation, or pass --force-code-mismatch to proceed despite those lifecycle mismatches.";
        } else {
            $finding = match ($case) {
                'missing' => "active_plugins in state/options/core.json declares '$plugin' but $plugin does not exist in this environment (checked against this environment's wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' means 'installed on the env'). Install/vendor the plugin here, or this branch's code/ changes haven't reached this environment yet.",
                'prior' => "$plugin 2.7.4 is active in this environment, outside the 'users-customers-import-export-for-wp-woocommerce' manifest's declared version_range (>=2.7.5 <2.7.6, pinned by site.wprism.json). Classification guarantees for this plugin are NOT validated against this version — apply may silently misclassify fields. Update the plugin, pin an older manifest, or pass --force-code-mismatch to proceed at your own risk.",
                default => throw new RuntimeException('unknown Importer dependency refusal'),
            };
            $message = "wprism: deploy refused — code_mismatch:\n\n  - $finding\n\n"
                . 'Install/vendor whatever is missing (or update code/) in this environment first, or pass --force-code-mismatch to proceed anyway.';
        }
        $command = $case === 'inactive' ? 'apply' : 'deploy';
        return ['command' => $command, 'reason_code' => $command . '_failed', 'nodes' => [[
            'class' => RuntimeException::class, 'parent_index' => null, 'relation' => 'root', 'message' => $message,
        ]]];
    }

    public static function retained(array $before, array $after): bool {
        $roster = ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
            'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'];
        foreach ([$before, $after] as $state) {
            if (($state['format'] ?? null) !== 'wprism-importer-native-settings/v1'
                || !is_array($state['tables'] ?? null) || array_keys($state['tables']) !== $roster
                || !is_array($state['files'] ?? null) || !is_array($state['settings'] ?? null)) return false;
        }
        if (array_keys($before['tables']) !== array_keys($after['tables']) || $before['files'] !== $after['files']
            || $before['settings'] !== $after['settings']) return false;
        foreach ($before['tables'] as $table => $rows) {
            if ($table !== 'options' && $rows !== $after['tables'][$table]) return false;
        }
        $option = static fn(array $state): array => array_values(array_filter($state['tables']['options'],
            static fn(array $row): bool => $row['option_name'] === 'wt_iew_advanced_settings'));
        return count($option($before)) === 1 && $option($before) === $option($after);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (($argv[1] ?? '') === 'snapshot') {
    echo json_encode(['state' => WPrismTest\FilesystemTreeEvidence::capture($argv[2], 'state'),
        'policy' => WPrismTest\FilesystemTreeEvidence::capture($argv[2], 'site.wprism.json')], JSON_THROW_ON_ERROR), "\n";
    return;
}
[$sink, $pair] = array_slice($argv, 1);
$transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
$read = static function (string $name, string $verb = '', int $exit = 0) use ($sink, $pair, $root, $transport): array {
    $stem = "$sink/$name";
    ImporterSettingsEvidence::command($root, $stem, $pair, $verb, $exit);
    if ($verb !== '') {
        $stem = substr(explode("\n", (string) file_get_contents($stem . '.stderr'), 2)[0], strlen('private command diagnostics (unverified): ')) . '/command';
    }
    return json_decode(WPrismTest\PrivateCommandOutput::readObject($stem, $transport, expectedExit: $exit), true, 512, JSON_THROW_ON_ERROR);
};
$beforeActivation = $read('installed-inactive');
wprism_check($beforeActivation['version'] === '2.7.5' && !$beforeActivation['active'] && !$beforeActivation['loaded']
    && $beforeActivation['tables'] === ['wt_iew_mapping_template' => false, 'wt_iew_action_history' => false],
    'fresh target has exact inactive code and neither native plugin table before deployment');
foreach (['initial', 'prepared', 'reactivated', 'reinstalled'] as $case) {
    $deploy = $read("$case-deploy", 'deploy');
    $state = $read("$case-status");
    wprism_check($deploy['activated'] === [ImporterDependencyEvidence::PLUGIN] && $deploy['warnings'] === [],
        'public deployment performs exactly the expected native activation: ' . $case);
    wprism_check($state['version'] === '2.7.5' && $state['active'] && !$state['loaded'] && $state['marker'] === '1'
        && $state['tables'] === ['wt_iew_mapping_template' => true, 'wt_iew_action_history' => true],
        'raw observation before plugin loading proves persisted activation marker and both native tables: ' . $case);
    $admin = $read("$case-admin-status");
    wprism_check($admin === array_replace($state, ['loaded' => true]),
        'subsequent native admin bootstrap exposes the same existing lifecycle postconditions: ' . $case);
}
$repeat = $read('repeat-deploy', 'deploy');
wprism_check($repeat['activated'] === [] && $repeat['deactivated'] === [] && $repeat['warnings'] === [],
    'settled deployment repeats without activation hooks');
foreach (['initial', 'reinstalled'] as $case) {
    $apply = $read("$case-apply", 'apply');
    $warnings = [];
    if ($case === 'initial') {
        $tree = $read('inactive-before-state')['state'];
        WPrismTest\FilesystemTreeEvidence::assertRecord($tree, 'state');
        $terms = array_values(array_filter(array_column($tree['files'], 'path'),
            static fn(string $path): bool => preg_match('~^terms/category/[0-9a-f-]{36}--uncategorized\.json$~D', $path) === 1));
        if (count($terms) !== 1) throw new RuntimeException('one captured default-term identity is required for explicit adoption');
        $uuid = substr(basename($terms[0]), 0, 36);
        $warnings = ["adopted env term 1 as $uuid ({$terms[0]})"];
    }
    wprism_check($apply['canary'] === 'clean' && $apply['warnings'] === $warnings && $apply['verification']['result'] === 'pass',
        'public Apply passes verification with only the explicitly requested initial default-term adoption: ' . $case);
}
$baseline = $read('retained-before');
wprism_check(count($baseline['tables']['wt_iew_action_history']) === 4 && count($baseline['tables']['wt_iew_mapping_template']) === 4
    && count($baseline['files']) === 6
    && isset($baseline['files']['webtoffee_export/.htaccess'], $baseline['files']['webtoffee_export/index.php'])
    && count(array_filter(array_keys($baseline['files']), static fn(string $path): bool => str_ends_with($path, '.csv'))) === 4,
    'retention has four saved templates/jobs, four CSVs and both native directory protection files');
foreach (['deactivated', 'reactivated', 'uninstalled', 'reinstalled'] as $case) {
    wprism_check(ImporterDependencyEvidence::retained($baseline, $read("$case-native")),
        'native lifecycle retains complete template/history/core data rows, authored settings and operational files: ' . $case);
}
foreach (['inactive' => ['2.7.5', 'apply'], 'deactivated' => ['2.7.5', 'apply'], 'missing' => [null, 'deploy'], 'prior' => ['2.7.4', 'deploy']] as $case => [$version, $verb]) {
    $status = $read("$case-status");
    wprism_check($status['version'] === $version && !$status['active'] && !$status['loaded'] && $status['marker'] === null,
        'exact native dependency refusal premise: ' . $case);
    $public = $read("$case-refusal", $verb, 1);
    wprism_check(($public['format'] ?? null) === 'wprism-command-refusal/v1' && ($public['ok'] ?? null) === false
        && ($public['command'] ?? null) === $verb && ($public['reason_code'] ?? null) === ImporterDependencyEvidence::profile($case)['reason_code']
        && ($case === 'deactivated'
            ? !array_key_exists('details_redacted', $public)
                && $public['message'] === 'the target changed after the repository baseline, so this plan is stale and cannot be partially applied'
            : ($public['details_redacted'] ?? null) === true), 'public dependency refusal preserves the private-cause boundary: ' . $case);
    $private = substr(explode("\n", (string) file_get_contents("$sink/$case-refusal.stderr"), 2)[0], strlen('private command diagnostics (unverified): '));
    $diagnostic = json_decode(WPrismTest\PrivateCommandOutput::readObject($private . '/private', $transport), true, 512, JSON_THROW_ON_ERROR);
    WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($diagnostic, ImporterDependencyEvidence::profile($case));
    $images = [];
    foreach (['before', 'after'] as $when) {
        $tables = WPrismTest\PrivateCommandOutput::readBytes("$sink/$case-$when-tables", $transport);
        $dump = WPrismTest\PrivateCommandOutput::readBytes("$sink/$case-$when-database", $transport, WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
        // Before the first authored Apply, deploy has populated the KV lifecycle
        // baseline but map/state contain no authored records. All tables remain
        // covered by the independent roster and the byte-exact dump comparison.
        WPrismTest\SqlDumpEvidence::assertComplete($dump, WPrismTest\SqlDumpEvidence::tables($tables),
            array_merge(['wp_options', 'wp_users', 'wp_wt_iew_mapping_template', 'wp_wt_iew_action_history', 'wp_wprism_kv'],
                $case === 'inactive' ? [] : ['wp_wprism_map', 'wp_wprism_state']));
        $tree = $read("$case-$when-state");
        WPrismTest\FilesystemTreeEvidence::assertRecord($tree['state'], 'state');
        WPrismTest\FilesystemTreeEvidence::assertRecord($tree['policy'], 'site.wprism.json');
        wprism_check(count($tree['state']['files']) > 0, 'canonical dependency snapshot is nonempty: ' . $case . '-' . $when);
        $images[$when] = [$tables, $dump, $tree];
    }
    wprism_check($images['before'] === $images['after'], 'refusal preserves the complete native database, canonical files and policy: ' . $case);
}
wprism_check_summary('Importer dependency and lifecycle boundaries');
