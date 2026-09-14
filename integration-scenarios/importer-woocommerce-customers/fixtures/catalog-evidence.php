<?php
declare(strict_types=1);

/** Independent native getter premise complements the complete SQL preservation oracle. */
final class ImporterWooCatalogEvidence {
    public static function preserved(array $before, array $after): void {
        $fail = static function (bool $ok): void {
            if (!$ok) throw new RuntimeException('Importer/Woo catalog evidence: missing or changed native catalog witness');
        };
        $products = $before['products'] ?? [];
        $fail(array_keys($before) === ['products', 'lookup'] && array_keys($products) === ['combo-simple', 'combo-variable', 'combo-variation']);
        $ids = [];
        foreach ($products as $sku => $product) {
            $fail(is_array($product) && is_int($product['id'] ?? null) && $product['id'] > 0
                && ($product['sku'] ?? null) === $sku && ($product['status'] ?? null) === 'publish'
                && ($product['stock_status'] ?? null) === 'instock' && is_array($product['attributes'] ?? null)
                && is_array($product['categories'] ?? null) && is_array($product['defaults'] ?? null));
            $ids[] = $product['id'];
        }
        $fail(count(array_unique($ids)) === 3);
        $simple = $products['combo-simple']; $variable = $products['combo-variable']; $variation = $products['combo-variation'];
        $fail($simple['type'] === 'simple' && $simple['parent'] === 0 && $simple['name'] === 'Combined simple'
            && $simple['regular_price'] === '19.95' && $simple['price'] === '19.95'
            && $simple['manage_stock'] === true && $simple['stock'] === 37 && count($simple['categories']) === 1);
        $fail($variable['type'] === 'variable' && $variable['parent'] === 0 && $variable['name'] === 'Combined variable'
            && $variable['price'] === '29.95' && $variable['categories'] === $simple['categories']
            && $variable['defaults'] === ['size' => 'Small'] && isset($variable['attributes']['size'])
            && $variable['attributes']['size']['options'] === ['Small'] && $variable['attributes']['size']['variation'] === true);
        $fail($variation['type'] === 'variation' && $variation['parent'] === $variable['id']
            && $variation['regular_price'] === '29.95' && $variation['price'] === '29.95'
            && $variation['manage_stock'] === true && $variation['stock'] === 41 && $variation['attributes'] === ['size' => 'Small']);
        $lookup = $before['lookup'];
        $fail(is_array($lookup) && array_is_list($lookup) && count($lookup) === 3);
        $seen = [];
        foreach ($lookup as $row) {
            $sku = $row['sku'] ?? '';
            $fail(isset($products[$sku]) && !isset($seen[$sku])
                && (string) $products[$sku]['id'] === ($row['product_id'] ?? null)
                && ($row['stock_status'] ?? null) === 'instock');
            $seen[$sku] = true;
            if ($sku !== 'combo-variable') $fail((string) $products[$sku]['stock'] === ($row['stock_quantity'] ?? null));
        }
        $fail($before === $after);
    }
}
