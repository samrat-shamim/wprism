<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/Db.php';
}
require_once __DIR__ . '/TransactionAuthority.php';

/** Exact, bounded wp_options row witnesses under one authored transaction. */
final class LockedOptionRows {
    private const MAX_OPTION_NAME_BYTES = 764;
    private const MAX_OPTION_NAME_CHARACTERS = 191;
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_AUTOLOAD_BYTES = 64;
    private const MAX_ROWS = 64;

    /**
     * @param list<string> $names
     * @return array<string,array{option_name:string,option_value:string,autoload:string}>
     */
    public static function read_required(
        array $names,
        string $lockIndex,
        TransactionAuthority $authority,
        string $purpose
    ): array {
        self::assert_request($names, $lockIndex);
        if (count(array_unique($names, SORT_STRING)) !== count($names)) {
            throw new \InvalidArgumentException('locked option-row request contains duplicate names');
        }
        sort($names, SORT_STRING);
        self::assert_authority($authority, $purpose . ' option roster preflight');
        $rows = [];
        foreach ($names as $name) {
            $row = self::read_one($name, $lockIndex, $authority, $purpose, true);
            if ($row === null) {
                throw new \LogicException('required locked option row resolved to absence');
            }
            $rows[$name] = $row;
        }
        self::assert_authority($authority, $purpose . ' option roster postflight');
        return $rows;
    }

    /**
     * Lock one exact row or its insertion gap through the caller-proven unique
     * option_name index.
     *
     * @return ?array{option_name:string,option_value:string,autoload:string}
     */
    public static function read_optional(
        string $name,
        string $lockIndex,
        TransactionAuthority $authority,
        string $purpose
    ): ?array {
        self::assert_request([$name], $lockIndex);
        self::assert_authority($authority, $purpose . ' optional option preflight');
        $row = self::read_one($name, $lockIndex, $authority, $purpose, false);
        self::assert_authority($authority, $purpose . ' optional option postflight');
        return $row;
    }

    /** @return ?array{option_name:string,option_value:string,autoload:string} */
    private static function read_one(
        string $name,
        string $lockIndex,
        TransactionAuthority $authority,
        string $purpose,
        bool $required
    ): ?array {
        global $wpdb;
        $predicate = "FROM {$wpdb->options} FORCE INDEX (`$lockIndex`) WHERE option_name = %s "
            . 'AND CONNECTION_ID() = %s '
            . 'AND BINARY @wprism_tx_session = BINARY %s '
            . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE';
        $args = [$name, $authority->connection_id(), $authority->session_nonce()];

        self::assert_authority($authority, $purpose . ' compact option lock preflight');
        $wpdb->last_error = '';
        $sizes = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
                . "OCTET_LENGTH(autoload) AS autoload_bytes $predicate",
            ...$args
        ), ARRAY_A);
        $sizeError = trim((string) ($wpdb->last_error ?? ''));
        self::assert_authority($authority, $purpose . ' compact option lock postflight');
        if (!is_array($sizes) || !array_is_list($sizes) || $sizeError !== '') {
            throw new \RuntimeException("wprism: $purpose compact option lock read failed");
        }
        if (count($sizes) > 1) {
            throw new \RuntimeException(
                "wprism: $purpose found ambiguous collation-equal option rows"
            );
        }
        if ($sizes === []) {
            if (!$required) {
                return null;
            }
            throw new \RuntimeException(
                "wprism: $purpose requires one exact option row"
            );
        }
        $size = $sizes[0];
        $valueBytes = is_array($size)
            ? self::canonical_size($size['option_value_bytes'] ?? null)
            : null;
        $autoloadBytes = is_array($size)
            ? self::canonical_size($size['autoload_bytes'] ?? null)
            : null;
        if (!is_array($size)
            || array_keys($size) !== ['option_name', 'option_value_bytes', 'autoload_bytes']
            || !is_string($size['option_name'] ?? null)
            || !hash_equals($name, $size['option_name'])
            || $valueBytes === null
            || $autoloadBytes === null
            || $valueBytes > self::MAX_OPTION_VALUE_BYTES
            || $autoloadBytes > self::MAX_AUTOLOAD_BYTES) {
            throw new \RuntimeException(
                "wprism: $purpose compact option lock row is malformed, aliased, NULL, or oversized"
            );
        }

        self::assert_authority($authority, $purpose . ' option hash preflight');
        $wpdb->last_error = '';
        $hashRows = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, SHA2(option_value, 256) AS option_value_sha256, '
                . "SHA2(autoload, 256) AS autoload_sha256 $predicate",
            ...$args
        ), ARRAY_A);
        $hashError = trim((string) ($wpdb->last_error ?? ''));
        self::assert_authority($authority, $purpose . ' option hash postflight');
        if (!is_array($hashRows)
            || !array_is_list($hashRows)
            || count($hashRows) !== 1
            || $hashError !== '') {
            throw new \RuntimeException("wprism: $purpose bounded option hash witness failed or changed");
        }
        $hashRow = $hashRows[0];
        $valueHash = is_array($hashRow)
            ? self::canonical_sha256($hashRow['option_value_sha256'] ?? null)
            : null;
        $autoloadHash = is_array($hashRow)
            ? self::canonical_sha256($hashRow['autoload_sha256'] ?? null)
            : null;
        if (!is_array($hashRow)
            || array_keys($hashRow) !== ['option_name', 'option_value_sha256', 'autoload_sha256']
            || !is_string($hashRow['option_name'] ?? null)
            || !hash_equals($name, $hashRow['option_name'])
            || $valueHash === null
            || $autoloadHash === null) {
            throw new \RuntimeException(
                "wprism: $purpose bounded option hash witness is malformed, aliased, or NULL"
            );
        }

        self::assert_authority($authority, $purpose . ' option payload preflight');
        $wpdb->last_error = '';
        $payloadRows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value, autoload $predicate",
            ...$args
        ), ARRAY_A);
        $payloadError = trim((string) ($wpdb->last_error ?? ''));
        self::assert_authority($authority, $purpose . ' option payload postflight');
        if (!is_array($payloadRows)
            || !array_is_list($payloadRows)
            || count($payloadRows) !== 1
            || $payloadError !== '') {
            throw new \RuntimeException("wprism: $purpose bounded option payload read failed or changed");
        }
        $row = $payloadRows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value', 'autoload']
            || !is_string($row['option_name'] ?? null)
            || !is_string($row['option_value'] ?? null)
            || !is_string($row['autoload'] ?? null)
            || !hash_equals($name, $row['option_name'])
            || strlen($row['option_value']) !== $valueBytes
            || strlen($row['autoload']) !== $autoloadBytes
            || !hash_equals($valueHash, hash('sha256', $row['option_value']))
            || !hash_equals($autoloadHash, hash('sha256', $row['autoload']))) {
            throw new \RuntimeException(
                "wprism: $purpose option payload disagrees with its locked compact witness"
            );
        }
        return $row;
    }

    /** @param list<string> $names */
    private static function assert_request(array $names, string $lockIndex): void {
        if (!array_is_list($names)
            || $names === []
            || count($names) > self::MAX_ROWS
            || preg_match('/^[A-Za-z0-9_$]{1,64}$/D', $lockIndex) !== 1) {
            throw new \InvalidArgumentException('locked option-row request is malformed');
        }
        foreach ($names as $name) {
            $characters = is_string($name) && preg_match('//u', $name) === 1
                ? preg_match_all('/./us', $name)
                : false;
            if (!is_string($name)
                || $name === ''
                || strlen($name) > self::MAX_OPTION_NAME_BYTES
                || !is_int($characters)
                || $characters > self::MAX_OPTION_NAME_CHARACTERS
                || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                throw new \InvalidArgumentException('locked option-row request is malformed');
            }
        }
    }

    private static function assert_authority(
        TransactionAuthority $authority,
        string $context
    ): void {
        if (!$authority->equals(Db::transaction_authority($context))) {
            throw new DatabaseTransactionOutcomeException($context . ' changed database session authority');
        }
    }

    private static function canonical_size(mixed $value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($integer) && $integer >= 0 ? $integer : null;
    }

    private static function canonical_sha256(mixed $value): ?string {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1
            ? $value
            : null;
    }
}
