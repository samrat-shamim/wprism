<?php
declare(strict_types=1);

require_once __DIR__ . '/deletion-evidence.php';
$mode = $args[0] ?? '';
$path = '/siterepo/state/options/core.json';
$backup = __DIR__ . '/core.original';
$read = static function (string $file): string {
    if (!is_file($file) || is_link($file) || filesize($file) < 1 || filesize($file) > 8388608) throw new RuntimeException('native deletion fixture file is missing or unbounded');
    $bytes = file_get_contents($file);
    if (!is_string($bytes) || $bytes === '') throw new RuntimeException('native deletion fixture read failed');
    return $bytes;
};
$original = $read($path);
$document = \WPrism\Canon::decode($original);
global $wpdb;
$wpdb->last_error = '';
$row = $wpdb->get_row("SELECT option_value,autoload FROM {$wpdb->options} WHERE BINARY option_name = BINARY 'gmw-map-block-key'", ARRAY_A);
if ($wpdb->last_error !== '' || !is_array($row) || $row['option_value'] !== 'map-fixture-target-key') throw new RuntimeException('native credential deletion target premise failed');
$previous = \WPrism\OptionState::present($row['option_value'], $row['autoload']);
if ($mode === 'prepare') {
    if (file_exists($backup) || is_link($backup)) throw new RuntimeException('native deletion backup already exists');
    $next = \WPrism\Canon::encode(MapDeletionEvidence::inject($document, $previous));
    if (file_put_contents($backup, $original, LOCK_EX) !== strlen($original)) throw new RuntimeException('native deletion backup failed');
    if (file_put_contents($path, $next, LOCK_EX) !== strlen($next)) throw new RuntimeException('native deletion intent write failed');
} elseif ($mode === 'restore') {
    $saved = $read($backup);
    $expected = \WPrism\Canon::encode(MapDeletionEvidence::inject(\WPrism\Canon::decode($saved), $previous));
    if ($original !== $expected) throw new RuntimeException('refusing to overwrite an unexpected source intent');
    if (file_put_contents($path, $saved, LOCK_EX) !== strlen($saved)) throw new RuntimeException('native deletion restoration failed');
} elseif ($mode !== 'observe') {
    throw new RuntimeException('unknown native deletion operation');
}
$records = \WPrism\OptionState::records(\WPrism\Canon::decode($read($path)));
$intent = \WPrism\EnvironmentValues::read('/siterepo');
echo json_encode([
    'format' => 'wprism-map-deletion-observation/v1',
    'key_preserved' => get_option('gmw-map-block-key') === 'map-fixture-target-key',
    'intent_preserved' => ($intent['gmw-map-block-key'] ?? null) === 'map-fixture-target-key',
    'deleted' => ($records['gmw-map-block-key']['state'] ?? null) === 'deleted',
    'artifact_sha256' => hash('sha256', $read(__DIR__ . '/artifact.json')),
    'intent_sha256' => hash('sha256', $read('/siterepo/.wprism-env-values.json')),
    'options_sha256' => hash('sha256', $read($path)),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
