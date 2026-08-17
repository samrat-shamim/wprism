<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Secrets.php';
require_once __DIR__ . '/../Kernel/PersonalData.php';

/**
 * Read-only target probes used by first-run discovery.
 *
 * This service owns no repository paths, publication locks, or init journal
 * state. Its reports are deliberately count-only so proposal construction
 * never retains target option or user-meta values.
 */
final class InitSiteProbe {
    private const RISK_ROW_LIMIT = 5000;
    private const RISK_BYTE_LIMIT = 8388608;
    private const RISK_BATCH_SIZE = 100;

    /** @return array{tables:int,rows:int} */
    public static function ledger(): array {
        global $wpdb;
        $pattern = $wpdb->esc_like((string) $wpdb->prefix . 'duo_') . '%';
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('duo: init could not inspect the existing Duo ledger boundary');
        }
        sort($tables, SORT_STRING);
        $expected = [
            (string) $wpdb->prefix . 'duo_journal',
            (string) $wpdb->prefix . 'duo_kv',
            (string) $wpdb->prefix . 'duo_map',
            (string) $wpdb->prefix . 'duo_state',
        ];
        $rows = 0;
        foreach ($tables as $table) {
            if (!in_array($table, $expected, true)) {
                // An unknown duo_* table is itself non-pristine evidence;
                // never interpolate its target-controlled name into SQL.
                $rows++;
                continue;
            }
            $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
            if (!empty($wpdb->last_error) || !is_numeric($count)) {
                throw new \RuntimeException('duo: init could not verify that the existing Duo ledger is pristine');
            }
            $rows += (int) $count;
        }
        return ['tables' => count($tables), 'rows' => $rows];
    }

    /** @return array{attachments:int,local:int,provider:int,unavailable:int,strategy:string} */
    public static function media(): array {
        $ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'numberposts' => -1]);
        $local = 0;
        $provider = 0;
        $unavailable = 0;
        foreach ($ids as $id) {
            $path = get_attached_file((int) $id, true);
            if (is_string($path) && is_file($path) && is_readable($path)) {
                $local++;
                continue;
            }
            $source = apply_filters('duo_attachment_capture_source', null, (int) $id, $path);
            if (is_array($source)
                && ((is_string($source['path'] ?? null) && is_file($source['path']) && is_readable($source['path']))
                    || is_string($source['bytes'] ?? null))) {
                $provider++;
            } else {
                $unavailable++;
            }
        }
        $strategy = $unavailable > 0 ? 'incomplete' : ($provider > 0 ? 'local+provider' : 'local');
        return ['attachments' => count($ids), 'local' => $local, 'provider' => $provider, 'unavailable' => $unavailable, 'strategy' => $strategy];
    }

    /** @return array{rows_per_surface:int,bytes_per_surface:int} */
    public static function riskLimits(): array {
        return [
            'rows_per_surface' => self::RISK_ROW_LIMIT,
            'bytes_per_surface' => self::RISK_BYTE_LIMIT,
        ];
    }

    /** @return array{options:array<string,int>,user_meta:array<string,int>,oversized:array{options:int,user_meta:int},scanned:array{options:int,user_meta:int},limits:array{rows_per_surface:int,bytes_per_surface:int},truncated:bool} */
    public static function risk(): array {
        global $wpdb;
        $oversizedOptions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE LENGTH(option_value) > 65536"
        );
        if (!is_numeric($oversizedOptions) || trim((string) $wpdb->last_error) !== '') {
            throw new \RuntimeException('duo: init risk probe could not bound oversized option values safely');
        }
        $oversizedOptions = (int) $oversizedOptions;
        $oversizedUserMeta = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE LENGTH(meta_value) > 65536"
        );
        if (!is_numeric($oversizedUserMeta) || trim((string) $wpdb->last_error) !== '') {
            throw new \RuntimeException('duo: init risk probe could not bound oversized user-meta values safely');
        }
        $oversizedUserMeta = (int) $oversizedUserMeta;

        $options = self::boundedRiskCounts(
            (string) $wpdb->options,
            'option_id',
            'option_name',
            'option_value',
            'option values',
            static function (string $name, string $raw): ?string {
                $label = Secrets::hard_match($raw);
                return $label ?? (Secrets::suspicious($name, $raw) ? 'suspicious-name-and-shape' : null);
            }
        );
        // $options now retains counts only. No row payload survives while the
        // second surface is scanned, so the byte bound is real in PHP memory.
        $userMeta = self::boundedRiskCounts(
            (string) $wpdb->usermeta,
            'umeta_id',
            'meta_key',
            'meta_value',
            'user-meta values',
            static fn(string $name, string $raw): ?string =>
                PersonalData::match_deep($name, self::safeRiskValue($raw))
        );
        $secretCounts = $options['counts'];
        $piiCounts = $userMeta['counts'];
        ksort($secretCounts, SORT_STRING);
        ksort($piiCounts, SORT_STRING);
        return [
            'options' => $secretCounts,
            'user_meta' => $piiCounts,
            'oversized' => ['options' => $oversizedOptions, 'user_meta' => $oversizedUserMeta],
            'scanned' => ['options' => $options['rows'], 'user_meta' => $userMeta['rows']],
            'limits' => self::riskLimits(),
            'truncated' => $options['truncated'] || $userMeta['truncated']
                || $oversizedOptions > 0 || $oversizedUserMeta > 0,
        ];
    }

    /**
     * Deterministic keyset scan with both row and byte ceilings. Only one
     * small batch is resident at a time; the returned structure contains no
     * target values, only redacted labels and counts.
     *
     * @param callable(string,string):?string $classify
     * @return array{counts:array<string,int>,rows:int,bytes:int,truncated:bool}
     */
    private static function boundedRiskCounts(
        string $table,
        string $idColumn,
        string $nameColumn,
        string $valueColumn,
        string $failureLabel,
        callable $classify
    ): array {
        global $wpdb;
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        foreach ([$idColumn, $nameColumn, $valueColumn] as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
                throw new \RuntimeException('duo: init risk probe received an unsafe column boundary');
            }
        }
        $counts = [];
        $rows = 0;
        $bytes = 0;
        $lastId = 0;
        $truncated = false;

        while ($rows < self::RISK_ROW_LIMIT && $bytes < self::RISK_BYTE_LIMIT) {
            $processLimit = min(self::RISK_BATCH_SIZE, self::RISK_ROW_LIMIT - $rows);
            $fetchLimit = $processLimit + 1;
            $batch = $wpdb->get_results(
                "SELECT $idColumn, $nameColumn, $valueColumn FROM $quotedTable "
                . "WHERE $idColumn > $lastId AND LENGTH($valueColumn) <= 65536 "
                . "ORDER BY $idColumn ASC LIMIT $fetchLimit",
                ARRAY_A
            );
            if (!is_array($batch) || trim((string) $wpdb->last_error) !== '') {
                throw new \RuntimeException("duo: init risk probe could not read $failureLabel safely");
            }
            if ($batch === []) {
                break;
            }

            $processed = 0;
            $stoppedForBytes = false;
            foreach ($batch as $row) {
                if ($processed >= $processLimit) {
                    break;
                }
                $raw = (string) ($row[$valueColumn] ?? '');
                $size = strlen($raw);
                if ($bytes + $size > self::RISK_BYTE_LIMIT) {
                    $truncated = true;
                    $stoppedForBytes = true;
                    break;
                }
                $label = $classify((string) ($row[$nameColumn] ?? ''), $raw);
                if ($label !== null) {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
                $lastId = (int) ($row[$idColumn] ?? 0);
                $rows++;
                $bytes += $size;
                $processed++;
            }

            $hasUnprocessed = count($batch) > $processed;
            unset($batch);
            if ($stoppedForBytes) {
                break;
            }
            if ($rows >= self::RISK_ROW_LIMIT || $bytes >= self::RISK_BYTE_LIMIT) {
                $truncated = $truncated || $hasUnprocessed;
                break;
            }
            if (!$hasUnprocessed) {
                break;
            }
        }

        return ['counts' => $counts, 'rows' => $rows, 'bytes' => $bytes, 'truncated' => $truncated];
    }

    /** Decode scalar/array metadata without ever instantiating stored PHP objects. */
    private static function safeRiskValue(string $raw) {
        if (!function_exists('is_serialized') || !is_serialized($raw)) {
            return $raw;
        }
        $decoded = @unserialize(trim($raw), ['allowed_classes' => false]);
        // A disallowed object becomes __PHP_Incomplete_Class. Keep the raw
        // bytes opaque; the outer meta key can still produce a redacted PII
        // label, while no wakeup/unserialize/destructor code can execute.
        return is_object($decoded) ? $raw : $decoded;
    }
}
