<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';

use WPrismTest\PrivateCommandOutput;

final class ImporterOmittedPasswordEvidence {
    public static function result(array $record, bool $existing): void {
        self::check(array_keys($record) === ['phase', 'history_id', 'run', 'display_name', 'existing_user',
            'password_hash_nonempty', 'password_preserved'], 'exact native result members');
        $run = $record['run'];
        self::check($record['phase'] === 'consume' && is_int($record['history_id']) && $record['history_id'] > 0
            && is_array($run) && ($run['response'] ?? null) === true && ($run['finished'] ?? null) === 1
            && ($run['total_success'] ?? null) === 1 && $record['display_name'] === 'Generated target'
            && $record['existing_user'] === $existing && $record['password_hash_nonempty'] === true
            && $record['password_preserved'] === ($existing ? true : null), 'exact generated-password native outcome');
    }

    public static function admittedLimitation(string $stem, string $pair): array {
        self::check(preg_match('/^[a-z][a-z0-9]*$/D', $pair) === 1, 'exact owned pair');
        $container = ' ?Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (Creating|Created) *';
        $path = '/var/www/html/wp-content/plugins/users-customers-import-export-for-wp-woocommerce/admin/modules/user/import/import.php';
        $logged = '\[[0-9]{2}-[A-Z][a-z]{2}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} UTC\] PHP Warning:  Undefined array key "user_pass" in '
            . preg_quote($path, '/') . ' on line 640';
        $displayed = 'Warning: Undefined array key "user_pass" in ' . preg_quote($path, '/') . ' on line 640';
        $allowed = '/^(?:' . $container . '|' . $logged . '|' . $displayed . ')$/D';
        $record = json_decode(PrivateCommandOutput::readObject($stem, $allowed), true, 32, JSON_THROW_ON_ERROR);
        $diagnostics = array_values(array_filter(explode("\n", (string) file_get_contents($stem . '.stderr')),
            static fn(string $line): bool => $line !== '' && preg_match('/^' . $container . '$/D', $line) !== 1));
        self::check(count($diagnostics) === 4
            && preg_match('/^' . $logged . '$/D', $diagnostics[0]) === 1
            && preg_match('/^' . $displayed . '$/D', $diagnostics[1]) === 1
            && preg_match('/^' . $logged . '$/D', $diagnostics[2]) === 1
            && preg_match('/^' . $displayed . '$/D', $diagnostics[3]) === 1,
            'exact two native undefined-key warnings and their CLI display copies');
        self::result($record, true);
        return $record;
    }

    private static function check(bool $ok, string $reason): void {
        if (!$ok) throw new RuntimeException('Importer omitted-password admission: ' . $reason);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (($argv[1] ?? '') !== 'admit-limitation') throw new RuntimeException('unknown omitted-password evidence mode');
ImporterOmittedPasswordEvidence::admittedLimitation($argv[2], $argv[3]);
