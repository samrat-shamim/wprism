<?php
declare(strict_types=1);

final class ImporterWooLoadOrderEvidence
{
    public const IMPORTER = 'users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php';
    public const WOO = 'woocommerce/woocommerce.php';

    public static function expected(string $order): array
    {
        return match ($order) {
            'importer-first' => [self::IMPORTER, self::WOO],
            'woo-first' => [self::WOO, self::IMPORTER],
            default => throw new RuntimeException('Importer/Woo load order: unknown order'),
        };
    }

    public static function verify(array $record, string $side, string $order): void
    {
        $expected = self::expected($order);
        if (!in_array($side, ['source', 'target'], true)
            || ($record['order'] ?? null) !== $order
            || ($record['side'] ?? null) !== $side
            || ($record['active'] ?? null) !== $expected
            || ($record['loaded'] ?? null) !== $expected
            || ($record['versions'] ?? null) !== ['importer' => '2.7.5', 'woocommerce' => '11.0.1']) {
            throw new RuntimeException('Importer/Woo load order: exact fresh native premise required');
        }
    }
}
