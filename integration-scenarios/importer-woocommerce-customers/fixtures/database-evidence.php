<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/SqlDumpEvidence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';

use WPrismTest\EvidenceSizeProfile;
use WPrismTest\SqlDumpEvidence;
use WPrismTest\PrivateCommandOutput;

final class ImporterWooDatabaseEvidence {
    public static function transport(string $pair, int $side): string {
        if (preg_match('/^[a-z][a-z0-9]*$/D', $pair) !== 1 || !in_array($side, [1, 2], true)) {
            throw new RuntimeException('Importer/Woo observation requires an exact owned pair side');
        }
        return '/^ ?Container wprism-' . $pair . '-cli' . $side . '-run-[a-f0-9]{12} (Creating|Created) *$/D';
    }

    public static function fromSink(string $sink, string $pair, int $side, string $label): array {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $label) !== 1) {
            throw new RuntimeException('Importer/Woo observation label is unsafe');
        }
        $transport = self::transport($pair, $side);
        $read = static fn(string $suffix): string => PrivateCommandOutput::readBytes(
            $sink . '/' . $label . '-' . $suffix, $transport, EvidenceSizeProfile::NATIVE_DATABASE);
        $inventory = $read('tables');
        $tables = SqlDumpEvidence::tables($inventory);
        if (SqlDumpEvidence::tables($read('tables-after')) !== $tables) {
            throw new RuntimeException('Importer/Woo table inventory changed during observation');
        }
        $columns = [];
        foreach ($tables as $table) {
            $columns[$table] = $read('columns-' . $table);
            if ($columns[$table] !== $read('columns-after-' . $table)) {
                throw new RuntimeException('Importer/Woo column inventory changed during observation');
            }
        }
        return self::read($read('database'), $inventory, $columns);
    }

    /**
     * SHOW FULL TABLES and every SHOW FULL COLUMNS result are separate native
     * commands. Keep all discovered tables, including empty/unknown neighbors;
     * the seeded fixture premises prevent a stable empty database passing.
     */
    public static function read(string $dump, string $inventory, array $columns): array {
        $tables = SqlDumpEvidence::tables($inventory);
        $required = ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta',
            'wp_wt_iew_mapping_template', 'wp_wt_iew_action_history', 'wp_wc_customer_lookup',
            'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data'];
        if (array_diff($required, $tables) !== [] || array_keys($columns) !== $tables) {
            throw new RuntimeException('Importer/Woo database requires its complete native table and column inventories');
        }
        $nonempty = ['wp_options', 'wp_users', 'wp_usermeta', 'wp_wt_iew_mapping_template',
            'wp_wc_orders', 'wp_wc_order_addresses'];
        $schemas = SqlDumpEvidence::structures($dump, $tables, $nonempty, EvidenceSizeProfile::NATIVE_DATABASE);
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = SqlDumpEvidence::fullLiteralRows($dump, $table,
                SqlDumpEvidence::columnRoster($columns[$table]), EvidenceSizeProfile::NATIVE_DATABASE);
        }
        return ['schemas' => $schemas, 'columns' => $columns, 'rows' => $rows];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if ($argc !== 6) throw new RuntimeException('database observation requires mode, sink, pair, side and label');
[$mode, $sink, $pair, $side, $label] = array_slice($argv, 1);
if (!in_array($side, ['1', '2'], true)) throw new RuntimeException('database observation side must be 1 or 2');
if ($mode === 'tables') {
    if (preg_match('/^[a-z][a-z0-9-]*$/D', $label) !== 1) throw new RuntimeException('unsafe table observation label');
    $tables = SqlDumpEvidence::tables(PrivateCommandOutput::readBytes($sink . '/' . $label . '-tables',
        ImporterWooDatabaseEvidence::transport($pair, (int) $side)));
    echo implode("\n", $tables), "\n";
} elseif ($mode === 'image') {
    echo json_encode(ImporterWooDatabaseEvidence::fromSink($sink, $pair, (int) $side, $label), JSON_THROW_ON_ERROR), "\n";
} else {
    throw new RuntimeException('unknown Importer/Woo database observation operation');
}
