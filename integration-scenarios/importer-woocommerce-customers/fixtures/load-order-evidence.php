<?php
declare(strict_types=1);

final class ImporterWooLoadOrderEvidence
{
    public const IMPORTER = 'users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php';
    public const WOO = 'woocommerce/woocommerce.php';

    public static function expected(string $side): array
    {
        return match ($side) {
            'source' => [self::IMPORTER, self::WOO],
            'target' => [self::WOO, self::IMPORTER],
            default => throw new RuntimeException('Importer/Woo load order: unknown side'),
        };
    }

    public static function verify(array $record, string $side): void
    {
        $expected = self::expected($side);
        if (($record['side'] ?? null) !== $side
            || ($record['active'] ?? null) !== $expected
            || ($record['loaded'] ?? null) !== $expected
            || ($record['versions'] ?? null) !== ['importer' => '2.7.5', 'woocommerce' => '11.0.1']) {
            throw new RuntimeException('Importer/Woo load order: exact fresh native premise required');
        }
    }
}
