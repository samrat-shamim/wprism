<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/fixtures/version-matrix-evidence.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PrivateRefusalEvidence.php';

$pair = 'importermatrixoffline';
$sink = $root . '/sandbox/tmp/importer-matrix-offline-' . bin2hex(random_bytes(6));
mkdir($sink, 0700, true);
$privateRoots = [];
$stream = static function (string $stem, string $stdout, string $stderr = '', int $exit = 0): void {
    foreach (['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit . "\n"] as $suffix => $bytes) {
        file_put_contents($stem . '.' . $suffix, $bytes);
        chmod($stem . '.' . $suffix, 0600);
    }
};
$json = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR) . "\n";
$remove = static function (string $directory): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($directory);
};
try {
    $terminal = '✔ CONFORMANCE PASSED (users-customers-import-export-for-wp-woocommerce)';
    $stream($sink . '/positive', "native checks passed\n$terminal\n");
    ImporterVersionMatrixEvidence::transcript('positive', $sink . '/positive');
    wprism_check(true, 'complete certified roundtrip is admitted through the ordinary host workflow');
    foreach ([['', '', 0], [$terminal, '', 1], [$terminal, 'Warning: hidden stderr', 0],
        ["Warning: native diagnostic\n$terminal", '', 0], ["$terminal\n$terminal", '', 0],
        ["$terminal\nfailed child", '', 0], ['✔ AGENT ROUNDTRIP PASSED (users-customers-import-export-for-wp-woocommerce; production promotion withheld)', '', 0]] as [$out, $err, $exit]) {
        $stream($sink . '/positive', $out . "\n", $err, $exit);
        wprism_check_throws(static fn() => ImporterVersionMatrixEvidence::transcript('positive', $sink . '/positive'), RuntimeException::class,
            'empty, failed, diagnostic, duplicated, trailing or wrongly graded roundtrip cannot pass');
    }
    $install = "Unpacking the package...\nInstalling the plugin...\nRemoving the old version of the plugin...\nPlugin updated successfully.\nSuccess: Installed 1 of 1 plugins.\n";
    $stream($sink . '/install', $install);
    $status = ['version' => '2.7.5', 'active' => true, 'loaded' => false, 'marker' => '1',
        'tables' => ['wt_iew_mapping_template' => true, 'wt_iew_action_history' => true]];
    $stream($sink . '/supported', $json($status));
    $status['version'] = '2.7.4';
    $stream($sink . '/prior', $json($status));
    ImporterVersionMatrixEvidence::installed($sink, $pair);
    wprism_check(true, 'official prior replacement retains activation and is observed before plugin bootstrap');
    foreach (['version' => '2.7.5', 'active' => false, 'loaded' => true, 'marker' => null, 'tables' => []] as $key => $value) {
        $stream($sink . '/prior', $json(array_replace($status, [$key => $value])));
        wprism_check_throws(static fn() => ImporterVersionMatrixEvidence::installed($sink, $pair), RuntimeException::class,
            'wrong artifact or self-repaired lifecycle premise refuses: ' . $key);
    }
    $stream($sink . '/prior', $json($status));
    $tables = ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
        'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'];
    $native = ['format' => 'wprism-importer-native-settings/v1', 'tables' => array_fill_keys($tables, []), 'settings' => ['fixture' => 1],
        'files' => array_fill_keys(['input/a.csv', 'input/b.csv', 'export/a.csv', 'export/b.csv', 'export/c.csv', 'export/d.csv'], str_repeat('a', 64))];
    $native['tables']['options'] = [['option_name' => 'wt_iew_advanced_settings', 'option_value' => serialize($native['settings']), 'autoload' => 'off']];
    $native['tables']['options'][] = ['option_name' => '_transient_doing_cron', 'option_value' => '1789367921.1494529247283935546875', 'autoload' => 'on'];
    foreach (['users' => 8, 'wt_iew_mapping_template' => 7, 'wt_iew_action_history' => 4] as $table => $count) {
        $native['tables'][$table] = array_map(static fn(int $id): array => ['id' => (string) $id], range(1, $count));
    }
    $roster = array_map(static fn(string $table): string => 'wp_' . $table, array_merge($tables, ['wprism_map', 'wprism_state', 'wprism_kv']));
    sort($roster, SORT_STRING);
    $tableBytes = implode("\n", array_map(static fn(string $table): string => "$table\tBASE TABLE", $roster)) . "\n";
    $dump = "-- MariaDB dump fixture\n";
    foreach ($roster as $table) $dump .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n  `id` bigint NOT NULL\n);\n-- Dumping data for table `$table`\nINSERT INTO `$table` (`id`) VALUES (1);\n";
    $dump .= "-- Dump completed\n";
    mkdir($sink . '/site/state', 0700, true);
    foreach (range(1, 6) as $id) file_put_contents($sink . '/site/state/fixture-' . $id . '.json', '{}');
    file_put_contents($sink . '/site/site.wprism.json', '{}');
    $tree = ['state' => WPrismTest\FilesystemTreeEvidence::capture($sink . '/site', 'state'),
        'policy' => WPrismTest\FilesystemTreeEvidence::capture($sink . '/site', 'site.wprism.json')];
    foreach (['deploy', 'apply'] as $verb) {
        foreach (['before', 'after'] as $when) {
            $stem = "$sink/$verb-$when";
            foreach (['tables' => $tableBytes, 'database' => $dump, 'state' => $json($tree), 'native' => $json($native)] as $suffix => $bytes) {
                $stream($stem . '-' . $suffix, $bytes);
            }
        }
        $private = $root . '/sandbox/tmp/wprism-conformance-' . $verb . '.' . $pair . '.' . bin2hex(random_bytes(3));
        mkdir($private, 0700);
        $privateRoots[] = $private;
        $public = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => $verb,
            'reason_code' => $verb . '_failed', 'details_redacted' => true];
        $stream($private . '/command', $json($public), '', 1);
        $stream("$sink/$verb-refusal", $json($public), 'private command diagnostics (unverified): ' . $private . "\n", 1);
        $baseline = ['command' => $verb, 'baseline' => '[]'];
        $stream($private . '/baseline', $json($baseline));
        $profile = ImporterDependencyEvidence::profile('prior', $verb);
        $diagnostic = static function (Throwable $failure) use ($verb, $json): array {
            $bytes = $json(['format' => 'wprism-private-refusal-evidence/v2', 'command' => $verb, 'reason_code' => $verb . '_failed']
                + WPrism\PrivateRefusalEvidence::graph($failure));
            return ['format' => 'wprism-private-refusal-diagnostic/v1', 'command' => $verb, 'new_records' => 1,
                'purpose' => 'diagnostic_only', 'verified' => false, 'records' => [[
                    'name' => '20260914-010203-' . $verb . '-0123456789abcdef01234567.json', 'bytes' => strlen($bytes),
                    'contents_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes),
                ]]];
        };
        $good = $diagnostic(new RuntimeException($profile['nodes'][0]['message']));
        $stream($private . '/private', $json($good));
        ImporterVersionMatrixEvidence::refusal($sink, $pair, $verb);
        wprism_check(true, 'fresh exact version cause with complete native and canonical preservation: ' . $verb);
        foreach ([new RuntimeException('unrelated failure'), new LogicException($profile['nodes'][0]['message']),
            new RuntimeException($profile['nodes'][0]['message'], 0, new RuntimeException('extra cause'))] as $fault) {
            $stream($private . '/private', $json($diagnostic($fault)));
            wprism_check_throws(static fn() => ImporterVersionMatrixEvidence::refusal($sink, $pair, $verb), RuntimeException::class,
                'generic public failure cannot hide wrong private cause, class or extra edge: ' . $verb);
        }
        $stream($private . '/private', $json($good));
        $stream($private . '/baseline', $json(['command' => $verb, 'baseline' => json_encode([$good['records'][0]['name']], JSON_THROW_ON_ERROR)]));
        wprism_check_throws(static fn() => ImporterVersionMatrixEvidence::refusal($sink, $pair, $verb), RuntimeException::class, 'preexisting exact receipt is not fresh evidence');
        $stream($private . '/baseline', $json($baseline));
        foreach (['database' => str_replace('VALUES (1)', 'VALUES (2)', $dump), 'tables' => '',
            'state' => $json(array_replace($tree, ['state' => []])), 'native' => $json(array_replace($native, ['files' => []]))] as $suffix => $bytes) {
            $stem = "$sink/$verb-after-$suffix";
            $original = file_get_contents($stem . '.stdout');
            $stream($stem, $bytes);
            wprism_check_throws(static fn() => ImporterVersionMatrixEvidence::refusal($sink, $pair, $verb), RuntimeException::class,
                'changed or empty full preservation image cannot pass: ' . $verb . '-' . $suffix);
            $stream($stem, $original);
        }
        foreach (['wt_iew_mapping_template', 'options', 'users', 'wt_iew_action_history'] as $table) {
            $bad = $native;
            $bad['tables'][$table][0]['unexpected'] = 'mutation';
            $stream("$sink/$verb-after-native", $json($bad));
            wprism_check_throws(static fn() => ImporterVersionMatrixEvidence::refusal($sink, $pair, $verb), RuntimeException::class,
                'complete native comparison retains every row field: ' . $table);
        }
        $bad = $native;
        $bad['tables']['options'][1]['option_value'] = '1789367982.1633059978485107421875';
        $stream("$sink/$verb-after-native", $json($bad));
        wprism_check_throws(static fn() => ImporterVersionMatrixEvidence::refusal($sink, $pair, $verb), RuntimeException::class,
            'cron-lock timestamp changes remain visible under the controlled read window');
        $stream("$sink/$verb-after-native", $json($native));
        ImporterVersionMatrixEvidence::refusal($sink, $pair, $verb);
    }
} finally {
    foreach ($privateRoots as $directory) $remove($directory);
    $remove($sink);
}
wprism_check_summary('Importer version matrix evidence');
