<?php
declare(strict_types=1);

require_once __DIR__ . '/settings-evidence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/check.php';
$sink = $argv[1];
$pair = $argv[2];
$root = dirname(__DIR__, 3);
$read = static function (string $name, string $verb = '') use ($sink, $pair, $root): array {
    $stem = "$sink/$name";
    ImporterSettingsEvidence::command($root, $stem, $pair, $verb);
    $stderr = file_get_contents($stem . '.stderr');
    if (str_starts_with($stderr, 'private command diagnostics (unverified): ')) {
        $stem = substr(explode("\n", $stderr, 2)[0], strlen('private command diagnostics (unverified): ')) . '/command';
    }
    return json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
        '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D'), true, 64, JSON_THROW_ON_ERROR);
};
$template = static function (array $observation, string $name): array {
    $rows = array_values(array_filter($observation['tables']['wt_iew_mapping_template'], static fn(array $row): bool =>
        $row['template_type'] === 'export' && $row['item_type'] === 'user' && $row['name'] === $name));
    if (count($rows) !== 1) throw new RuntimeException('native evidence must contain exactly one template ' . $name);
    return $rows[0];
};
$portable = static function (array $row, array $users): array {
    $form = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
    $logins = array_column($users, 'user_login', 'ID');
    $form['filter_form_data']['wt_iew_email'] = array_map(static function (string $id) use ($logins): string {
        if (!isset($logins[$id])) throw new RuntimeException('native evidence contains an unbound selected user');
        return $logins[$id];
    }, $form['filter_form_data']['wt_iew_email']);
    unset($form['method_export_form_data']['selected_template']);
    return $form;
};
$preserved = static function (array $before, array $after, array $changedIds): void {
    wprism_check_same(array_keys($before['tables']), array_keys($after['tables']), 'complete native table roster retained');
    foreach ($before['tables'] as $name => $rows) {
        if ($name === 'wt_iew_mapping_template') {
            $unchanged = static fn(array $input): array => array_values(array_filter($input,
                static fn(array $row): bool => !in_array((int) $row['id'], $changedIds, true)));
            wprism_check_same($unchanged($rows), $unchanged($after['tables'][$name]), 'all excluded template rows preserve exact native bytes');
        } else {
            wprism_check_same($rows, $after['tables'][$name], 'template Apply preserves every row in ' . $name);
        }
    }
    wprism_check_same($before['settings'], $after['settings'], 'native settings retain exact scalar values');
    wprism_check_same($before['files'], $after['files'], 'template Apply preserves every operational file');
};
$sourceSetup = $read('templates-setup1');
$targetSetup = $read('templates-setup2');
wprism_check($sourceSetup['users'] !== $targetSetup['users'], 'selected source and target users have different native IDs');
wprism_check($sourceSetup['original'] !== $targetSetup['original'] && $sourceSetup['copy'] !== null && $targetSetup['copy'] === null,
    'source Save As and independent preexisting target establish distinct row identities');
$source = $read('templates-source');
$before = $read('templates-before');
$after = $read('templates-after');
$changedIds = [];
foreach (['Selected users', 'Selected users copy'] as $name) {
    $from = $template($source, $name);
    $to = $template($after, $name);
    $changedIds[] = (int) $to['id'];
    wprism_check($from['id'] !== $to['id'], 'native template ID divergence: ' . $name);
    wprism_check_same($portable($from, $source['tables']['users']), $portable($to, $after['tables']['users']),
        'every portable template field reaches target: ' . $name);
    wprism_check(!isset(json_decode($to['data'], true)['method_export_form_data']['selected_template']),
        'materialized form excludes the obsolete wizard cursor: ' . $name);
}
wprism_check_same($template($before, 'Selected users')['id'], $template($after, 'Selected users')['id'], 'explicit table adoption retains the existing target row ID');
wprism_check_same(count($before['tables']['wt_iew_mapping_template']) + 1, count($after['tables']['wt_iew_mapping_template']), 'only the missing Save As template is created');
$preserved($before, $after, $changedIds);
foreach (['original', 'copy'] as $stem) {
    $opened = $read($stem . '-reopen');
    wprism_check(in_array((string) $opened['id'], $opened['selected'], true), 'native reopen selects its actual target row: ' . $stem);
    wprism_check_same($targetSetup['users'], $opened['form']['filter_form_data']['wt_iew_email'], 'native reopened filter contains target string user IDs: ' . $stem);
    $job = $read($stem . '-export')['job'];
    wprism_check_same(['user_login', 'user_email', 'Display_Name'], $job['records'][0], 'native exporter retains the authored field headers');
    foreach (array_slice($job['records'], 1) as $record) {
        wprism_check(str_ends_with($record[1], '-target@example.test') && str_starts_with($record[2], 'Target '),
            'export consumes target-local email and display name');
    }
}
$renamedSource = $read('renamed-source');
$renameBefore = $read('renamed-before');
$renameAfter = $read('renamed-after');
$renamed = $template($renameAfter, 'Renamed selection');
wprism_check_same($template($before, 'Selected users')['id'], $renamed['id'], 'scoped native rename retains the original target row ID');
wprism_check_same($portable($template($renamedSource, 'Renamed selection'), $renamedSource['tables']['users']),
    $portable($renamed, $renameAfter['tables']['users']), 'scoped rename moves the complete changed form');
$preserved($renameBefore, $renameAfter, [(int) $renamed['id']]);
wprism_check_same($renameAfter, $read('renamed-stable'), 'terminal replay preserves the complete native observation');
wprism_check_same('Renamed_Display', $read('renamed-reopen')['form']['mapping_form_data']['mapping_selected_fields']['display_name'], 'native wizard reopens the renamed header');
wprism_check_same(['user_login', 'user_email', 'Renamed_Display'], $read('renamed-export')['job']['records'][0], 'native CSV uses the changed header after scoped Apply');
foreach (['templates', 'renamed'] as $phase) {
    $apply = $read($phase . '-apply', 'apply');
    wprism_check($apply['applied'] > 0 && $apply['canary'] === 'clean' && $apply['drift'] === [] && $apply['warnings'] === [] && $apply['actions'] === [],
        'public template Apply completes without warnings or plugin executable actions: ' . $phase);
}
$read('renamed-repeat', 'apply');
foreach (['templates-capture', 'templates-recapture', 'resave-recapture', 'renamed-capture', 'renamed-recapture'] as $name) {
    wprism_check_same([], $read($name, 'capture')['warnings'], 'public template capture completes without warnings: ' . $name);
}
wprism_check_summary('native importer templates');
