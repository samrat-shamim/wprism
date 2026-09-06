<?php
declare(strict_types=1);

namespace WPrism;

/** Stable, credential-free identity for the database selected by wp-config. */
final class DatabaseTargetIdentity {
    public const FORMAT = 'wprism-database-target/v1';
    private const DOMAIN = "wprism-database-target/v1\0";

    public static function fromWordPressConfig(): string {
        if (!defined('DB_HOST') || !is_string(constant('DB_HOST'))
            || !defined('DB_NAME') || !is_string(constant('DB_NAME'))) {
            throw new \RuntimeException('wprism: cannot identify the configured database host and name');
        }
        global $table_prefix;
        if (!isset($table_prefix) || !is_string($table_prefix)) {
            throw new \RuntimeException('wprism: cannot identify the configured database table prefix');
        }
        return self::hash(
            (string) constant('DB_HOST'),
            (string) constant('DB_NAME'),
            $table_prefix
        );
    }

    public static function hash(string $host, string $name, string $prefix): string {
        foreach (['host' => $host, 'name' => $name] as $field => $value) {
            $limit = $field === 'host' ? 1024 : 64;
            if ($value === ''
                || strlen($value) > $limit
                || preg_match('//u', $value) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \RuntimeException("wprism: configured database $field is malformed");
            }
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $prefix) !== 1) {
            throw new \RuntimeException('wprism: configured database table prefix is malformed');
        }
        try {
            $canonical = json_encode(
                ['host' => $host, 'name' => $name, 'prefix' => $prefix],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $failure) {
            throw new \RuntimeException('wprism: configured database target cannot be encoded', 0, $failure);
        }
        return hash('sha256', self::DOMAIN . $canonical);
    }

    public static function assertDigest(string $databaseTargetSha256): string {
        if (preg_match('/^[a-f0-9]{64}$/D', $databaseTargetSha256) !== 1) {
            throw new \RuntimeException('wprism: configured database target identity is malformed');
        }
        return $databaseTargetSha256;
    }

    public static function assertWordPressConfig(string $expectedSha256): void {
        $expectedSha256 = self::assertDigest($expectedSha256);
        if (!hash_equals($expectedSha256, self::fromWordPressConfig())) {
            throw new \RuntimeException('wprism: configured database target differs from the retained checkpoint');
        }
    }

    /** @return array{database_target_sha256:string,format:string} */
    public static function summary(): array {
        return [
            'database_target_sha256' => self::fromWordPressConfig(),
            'format' => self::FORMAT,
        ];
    }
}
