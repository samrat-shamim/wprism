<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/TableRowScope.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/Db.php';
}

/** Live ownership uses database authority; manifest validation remains database-free. */
final class TableRowOwnership {
    /** A retained ledger id cannot seize a row that changed owner after capture. */
    public static function assert_live_row(string $table, array $decl, int $localId, object $db, bool $lock = false): void {
        if (!array_key_exists('row_scope', $decl)) {
            return;
        }
        TableRowScope::validate($table, $decl);
        $prefixed = $db->prefix . $table;
        $pk = $decl['pk'];
        $suffix = $lock ? ' FOR UPDATE' : '';
        $sql = $db->prepare("SELECT * FROM `$prefixed` WHERE `$pk` = %d LIMIT 1$suffix", $localId);
        if ($lock) {
            if ($db !== ($GLOBALS['wpdb'] ?? null)) {
                throw new \RuntimeException('wprism: row ownership lock requires the bound database connection');
            }
            $context = "table '$table' ownership lock";
            $rows = Db::transactional_rows($sql, Db::transaction_authority($context), $context);
        } else {
            $db->last_error = '';
            $rows = $db->get_results($sql, ARRAY_A);
            if (!is_array($rows) || (string) ($db->last_error ?? '') !== '') {
                throw new \RuntimeException("wprism: cannot verify row ownership for table '$table'");
            }
        }
        if ($rows !== []) {
            TableRowScope::assert_matches($table, $decl, $rows[0]);
        }
    }
}
