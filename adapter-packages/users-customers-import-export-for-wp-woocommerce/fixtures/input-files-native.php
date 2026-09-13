<?php
declare(strict_types=1);

// The site-owned probe enrolls only this mechanism; it cannot qualify saved import templates.
require_once '/wprism-tests/lib/check.php';
$agent = WPMU_PLUGIN_DIR . '/wprism';
require_once $agent . '/src/Capture/Capture.php';
require_once $agent . '/src/Apply/Apply.php';
require_once $agent . '/src/Apply/ColumnInputFiles.php';
require_once $agent . '/src/Repository/RepositoryCompiler.php';

use WPrism\Apply;
use WPrism\Canon;
use WPrism\Capture;
use WPrism\ColumnInputFiles;
use WPrism\InputFileBinding;
use WPrism\Policy;
use WPrism\RepositoryCompiler;

if (!is_admin() || !current_user_can('manage_options') || !defined('WT_U_IEW_VERSION') || WT_U_IEW_VERSION !== '2.7.5') {
    throw new RuntimeException('native input evidence requires the locked plugin and administrator context');
}
global $wpdb, $wp_filter;
$owners = [];
foreach ($wp_filter['wp_ajax_iew_import_ajax_basic']->callbacks ?? [] as $group) foreach ($group as $entry) {
    $callback = $entry['function'];
    if (is_array($callback) && is_object($callback[0]) && $callback[1] === 'ajax_main') $owners[] = $callback[0];
}
if (count($owners) !== 1) throw new RuntimeException('native input evidence requires the actual registered importer');
$import = $owners[0];
wprism_check_same(['csv'], array_keys($import->allowed_import_file_type), 'actual native consumer admits only CSV inputs');
$repo = '/siterepo/input-files';
if (file_exists($repo)) throw new RuntimeException('native input evidence requires a fresh repository');
mkdir($repo . '/adapters', 0755, true);
$table = 'wprism_input_probe';
$physical = $wpdb->prefix . $table;
$query = static function (string $sql) use ($wpdb): void {
    if ($wpdb->query($sql) === false) throw new RuntimeException('native input fixture SQL failed: ' . $wpdb->last_error);
};
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($physical))) !== null) {
    throw new RuntimeException('native input fixture refuses to replace an existing table');
}
$query("CREATE TABLE `$physical` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name varchar(64) NOT NULL, data longtext NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$manifest = ['name' => 'wprism-input-native', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['column-input-files/v1', 'column-record-fields/v1', 'json-column-codecs/v1', 'object-record-fields/v1',
        'spec-window/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
    'tables' => [$table => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'input_probe',
        'identity' => ['mode' => 'mapped'], 'slug_column' => 'name',
        'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]],
    'column_codecs' => [$table => ['data' => ['container' => 'json', 'value' => ['class' => 'authored', 'object_fields' => [
        'method_import_form_data' => ['class' => 'authored',
            'record_fields' => ['container' => 'object', 'fields' => ['wt_iew_file_from', 'wt_iew_local_file']], 'object_fields' => [
            'wt_iew_file_from' => ['class' => 'authored', 'enum' => ['local']],
            'wt_iew_local_file' => ['class' => 'authored', 'input_file' => ['directory' => 'webtoffee_import', 'extensions' => ['csv']]]]],
        'label' => ['class' => 'authored', 'plain_data' => true]]]]]]];
Canon::write_file($repo . '/adapters/wprism-input-native.json', Canon::encode($manifest));
Canon::write_file($repo . '/site.wprism.json', Canon::encode(['spec_version' => WPRISM_SPEC_VERSION,
    'manifests' => ['core', 'wprism-input-native'],
    'policy' => ['post_types' => ['post', 'page', 'attachment'], 'taxonomies' => ['category', 'post_tag']]]));
$directory = $import->get_file_path();
$files = ['input-source.csv' => "user_login\nsource_synthetic\n", 'input-target.csv' => "user_login\ntarget_synthetic\n",
    'input-rotated.csv' => "user_login\nrotated_synthetic\n"];
foreach ($files as $name => $bytes) {
    $path = rtrim($directory, '/') . '/' . $name;
    if (file_exists($path)) throw new RuntimeException('native input fixture refuses an existing file');
    file_put_contents($path, $bytes);
}
$native = ['method_import_form_data' => ['selected_template' => '17', 'wt_iew_file_from' => 'local', 'wt_iew_local_file' => $import->get_file_url('input-source.csv')],
    'label' => 'Public template'];
$query($wpdb->prepare("INSERT INTO `$physical` (id,name,data) VALUES (2,'Input',%s)", wp_json_encode($native)));
$native['method_import_form_data']['wt_iew_local_file'] = '';
$query($wpdb->prepare("INSERT INTO `$physical` (id,name,data) VALUES (3,'Draft',%s)", wp_json_encode($native)));
$read = static fn(): array => json_decode($wpdb->get_var("SELECT data FROM `$physical` WHERE id=2"), true, flags: JSON_THROW_ON_ERROR);
$consume = static function (array $form, string $expected) use ($import): void {
    $result = $import->download_remote_file($form);
    wprism_check_same(true, $result['response'], 'registered importer consumes the saved native pointer');
    $temporary = $import->get_file_path($result['file_name']);
    wprism_check_same($expected, file_get_contents($temporary), 'native importer reads exactly the selected target bytes');
    unlink($temporary);
};
$consume($read(), $files['input-source.csv']);
$restored = $read();
$restored['method_import_form_data']['wt_iew_local_file'] = 'https://source.example.test/restored/content/webtoffee_import/input-source.csv';
$query($wpdb->prepare("UPDATE `$physical` SET data=%s WHERE id=2", wp_json_encode($restored)));
$consume($read(), $files['input-source.csv']);
$captured = Capture::run($repo);
wprism_check_same([], $captured['warnings'], 'public Capture emits no warnings');
$policy = Policy::load($repo);
// Core readiness is an independent prerequisite: a successful Apply may still
// report unbound home/siteurl/admin_email, which this native gate must not hide.
foreach (['admin_email', 'home', 'siteurl'] as $name) Apply::set_env_option($repo, $name, (string) get_option($name));
$compiled = RepositoryCompiler::compile($repo, $policy);
$declarations = ColumnInputFiles::declarations($policy, $compiled->tree());
wprism_check_same(1, count($declarations), 'Capture emits one dependency and leaves the blank draft unbound');
$binding = array_key_first($declarations);
$uuid = $declarations[$binding]['uuid'];
$canonical = json_decode($compiled->tree()[$uuid]['data']['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
wprism_check_same(InputFileBinding::MARKER, $canonical['method_import_form_data']['wt_iew_local_file'], 'immutable compilation contains only dependency presence');
wprism_check(!array_key_exists('selected_template', $canonical['method_import_form_data']), 'typed object projection excludes the source wizard cursor');
wprism_check(!str_contains(Canon::encode($compiled->tree()), 'input-source.csv'), 'source filename is absent from the entire canonical tree');
$plan = Apply::plan($repo);
wprism_check(in_array($binding, array_column($plan['env_missing'], 'name'), true), 'public Plan names the unprovisioned file coordinate');
$before = $read();
$receipt = Apply::set_env_option($repo, $binding, 'input-target.csv');
wprism_check_same($binding, $receipt['name'], 'public env-set accepts the exact compiled coordinate');
wprism_check_same($before, $read(), 'provisioning leaves authored rows unchanged');
$plan = Apply::plan($repo);
wprism_check(in_array($uuid, array_column($plan['update'], 'uuid'), true), 'public Plan selects a canonically equal row for file rebinding');
$result = Apply::apply($repo);
wprism_check_detail(implode("\n", $result['warnings']));
wprism_check_same('pass', $result['verification']['result'] ?? null, 'public Apply passes independent fresh-process canonical verification');
wprism_check_same([], $result['warnings'], 'public Apply emits no warnings');
wprism_check_same($import->get_file_url('input-target.csv'), $read()['method_import_form_data']['wt_iew_local_file'], 'Apply writes the exact native target URL spelling');
wprism_check(!array_key_exists('selected_template', $read()['method_import_form_data']), 'public Apply materializes the complete projected object without the source cursor');
$consume($read(), $files['input-target.csv']);
$settled = $read();
$plan = Apply::plan($repo);
wprism_check(!in_array($uuid, array_column($plan['update'], 'uuid'), true), 'settled Plan clears the file rebind');
$repeat = Apply::apply($repo);
wprism_check_same('pass', $repeat['verification']['result'] ?? null, 'repeat Apply passes independent verification');
wprism_check_same([], $repeat['warnings'], 'repeat Apply emits no warnings');
wprism_check_same($settled, $read(), 'repeat Apply preserves the complete native form');
Apply::set_env_option($repo, $binding, 'input-rotated.csv');
$rotated = Apply::apply($repo);
wprism_check_same('pass', $rotated['verification']['result'] ?? null, 'changed target intent passes the full Apply request');
wprism_check_same([], $rotated['warnings'], 'rotated Apply emits no warnings');
$consume($read(), $files['input-rotated.csv']);

$scope = WPrism\ScopeContract::resolve($compiled, $policy, ['table:' . $table . ':' . $uuid]);
$scopedOptions = ['scope_request' => $scope, 'request_id' => 'native-input-rotation-one'];
$draftBefore = $wpdb->get_row("SELECT * FROM `$physical` WHERE id=3", ARRAY_A);
Apply::set_env_option($repo, $binding, 'input-target.csv');
$scopedPlan = Apply::plan($repo, ['scope_request' => $scope]);
wprism_check_same([$uuid], array_column($scopedPlan['update'], 'uuid'), 'scoped Plan selects only the input owner despite canonical equality');
$scoped = Apply::apply($repo, $scopedOptions);
wprism_check_same('pass', $scoped['verification']['result'] ?? null, 'scoped rebind passes independent native-input convergence');
wprism_check_same([], $scoped['warnings'], 'scoped rebind emits no warnings');
wprism_check_same($import->get_file_url('input-target.csv'), $read()['method_import_form_data']['wt_iew_local_file'], 'scoped Apply writes the newly intended native pointer');
$consume($read(), $files['input-target.csv']);
$nativeRows = static fn(): array => $wpdb->get_results("SELECT * FROM `$physical` ORDER BY id", ARRAY_A);
$receiptState = static fn(): ?string => WPrism\Ledger::kv_get(WPrism\ScopedApplySession::STORAGE_KEY);
$scopedRows = $nativeRows();
$scopedRepeat = Apply::apply($repo, $scopedOptions);
wprism_check_same($scoped['scoped_receipt'], $scopedRepeat['scoped_receipt'], 'lost scoped response replays the exact sealed receipt');
wprism_check_same($scopedRows, $nativeRows(), 'scoped replay preserves every typed row');
$assertReplayRefused = static function (string $why) use ($repo, $scopedOptions, $nativeRows, $receiptState): void {
    $rowsBefore = $nativeRows(); $receiptBefore = $receiptState();
    wprism_check_throws(static fn() => Apply::apply($repo, $scopedOptions), WPrism\CommandRefusalException::class,
        $why, 'terminal scoped receipt no longer describes');
    wprism_check_same($rowsBefore, $nativeRows(), 'refused replay preserves every typed row');
    wprism_check_same($receiptBefore, $receiptState(), 'refused replay preserves the durable scoped receipt');
};
$nativeBeforeDrift = $read();
$nativeDrift = $nativeBeforeDrift;
$nativeDrift['method_import_form_data']['wt_iew_local_file'] = $import->get_file_url('input-source.csv');
$query($wpdb->prepare("UPDATE `$physical` SET data=%s WHERE id=2", wp_json_encode($nativeDrift)));
$assertReplayRefused('terminal replay refuses native pointer drift hidden by a canonical marker');
$query($wpdb->prepare("UPDATE `$physical` SET data=%s WHERE id=2", wp_json_encode($nativeBeforeDrift)));
unlink(rtrim($directory, '/') . '/input-target.csv');
$assertReplayRefused('terminal replay refuses unavailable local input');
file_put_contents(rtrim($directory, '/') . '/input-target.csv', $files['input-target.csv']);
Apply::set_env_option($repo, $binding, 'input-rotated.csv');
$assertReplayRefused('a changed binding cannot reuse an old scoped request');
$scopedOptions['request_id'] = 'native-input-rotation-two';
$secondScoped = Apply::apply($repo, $scopedOptions);
wprism_check_same('pass', $secondScoped['verification']['result'] ?? null, 'a new scoped request binds the changed input intent');
wprism_check_same([], $secondScoped['warnings'], 'second scoped rebind emits no warnings');
$consume($read(), $files['input-rotated.csv']);
$scopedOptions['request_id'] = 'native-input-already-desired';
$alreadyDesired = Apply::apply($repo, $scopedOptions);
wprism_check_same(0, $alreadyDesired['applied'], 'new scoped request with settled bindings has no authored row writes');
wprism_check_same('pass', $alreadyDesired['verification']['result'] ?? null, 'settled bindings still receive locked atomic input readback');
wprism_check_same([], $alreadyDesired['warnings'], 'settled scoped request emits no warnings');
wprism_check_same($draftBefore, $wpdb->get_row("SELECT * FROM `$physical` WHERE id=3", ARRAY_A), 'scoped rotation preserves the entire unselected native draft');

foreach ($files as $name => $bytes) wprism_check_same($bytes, file_get_contents(rtrim($directory, '/') . '/' . $name), 'transport leaves local input bytes untouched');
unlink(rtrim($directory, '/') . '/input-rotated.csv');
$plan = Apply::plan($repo);
wprism_check(in_array($binding, array_column($plan['env_missing'], 'name'), true), 'deleting the local input restores the public missing checklist');
file_put_contents(rtrim($directory, '/') . '/input-rotated.csv', $files['input-rotated.csv']);
$recapture = Capture::run($repo);
wprism_check_same([], $recapture['warnings'], 'public recapture emits no warnings');
$recaptured = RepositoryCompiler::compile($repo, Policy::load($repo));
wprism_check_same($compiled->tree()[$uuid]['hash'], $recaptured->tree()[$uuid]['hash'], 'native public recapture preserves canonical input identity after rotation');
wprism_check_summary('native importer input transport');
