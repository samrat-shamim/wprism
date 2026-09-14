<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer/Woo catalog fixture: ' . $why);
};
$check(is_admin() && current_user_can('manage_options') && defined('WC_VERSION') && WC_VERSION === '11.0.1'
    && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5', 'locked native administrator context');
$skus = ['combo-simple', 'combo-variable', 'combo-variation'];
if ($phase === 'attribute-source') {
    $check(wc_attribute_taxonomy_id_by_name('combosize') === 0, 'new global attribute');
    $id = wc_create_attribute(['name' => 'Combined size', 'slug' => 'combosize', 'type' => 'select',
        'order_by' => 'menu_order', 'has_archives' => false]);
    $check(!is_wp_error($id) && $id > 0, 'native attribute Save');
    echo json_encode(['attribute' => $id], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'seed-source') {
    foreach ($skus as $sku) $check(wc_get_product_id_by_sku($sku) === 0, 'seed never replaces a product');
    $category = wp_insert_term('Combined catalog', 'product_cat', ['slug' => 'combined-catalog']);
    $check(!is_wp_error($category), 'native category creation');
    $simple = new WC_Product_Simple();
    $simple->set_name('Combined simple'); $simple->set_slug('combined-simple');
    $simple->set_sku($skus[0]); $simple->set_status('publish');
    $simple->set_regular_price('19.95'); $simple->set_manage_stock(true); $simple->set_stock_quantity(19);
    $simple->set_category_ids([(int) $category['term_id']]);
    $check($simple->save() > 0, 'native simple Save');
    $attributeId = wc_attribute_taxonomy_id_by_name('combosize');
    $check($attributeId > 0 && taxonomy_exists('pa_combosize'), 'global taxonomy registered on a fresh request');
    $term = wp_insert_term('Small', 'pa_combosize', ['slug' => 'small']);
    $check(!is_wp_error($term), 'native attribute term');
    $attribute = new WC_Product_Attribute();
    $attribute->set_id($attributeId); $attribute->set_name('pa_combosize');
    $attribute->set_options([(int) $term['term_id']]);
    $attribute->set_visible(true); $attribute->set_variation(true);
    $variable = new WC_Product_Variable();
    $variable->set_name('Combined variable'); $variable->set_slug('combined-variable');
    $variable->set_sku($skus[1]); $variable->set_status('publish');
    $variable->set_category_ids([(int) $category['term_id']]);
    $variable->set_attributes([$attribute]); $variable->set_default_attributes(['pa_combosize' => 'small']);
    $check($variable->save() > 0, 'native variable Save');
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($variable->get_id()); $variation->set_sku($skus[2]);
    $variation->set_status('publish'); $variation->set_attributes(['pa_combosize' => 'small']);
    $variation->set_regular_price('29.95'); $variation->set_manage_stock(true); $variation->set_stock_quantity(23);
    $check($variation->save() > 0, 'native variation Save');
    WC_Product_Variable::sync($variable->get_id());
} elseif ($phase === 'stock-target') {
    // Runtime stock is deliberately different from the source's 19/23. It is
    // established after baseline adoption, before the template-only window.
    foreach ([$skus[0] => 37, $skus[2] => 41] as $sku => $stock) {
        $product = wc_get_product(wc_get_product_id_by_sku($sku));
        $check($product instanceof WC_Product, 'baselined target product exists');
        $check(wc_update_product_stock($product, $stock, 'set') === $stock, 'native local stock update');
    }
} else $check($phase === 'observe', 'known phase');
$out = [];
foreach ($skus as $sku) {
    $product = wc_get_product(wc_get_product_id_by_sku($sku));
    $check($product instanceof WC_Product, 'native SKU resolves');
    $attributes = [];
    foreach ($product->get_attributes('edit') as $key => $attribute) {
        $attributes[$key] = $attribute instanceof WC_Product_Attribute ? $attribute->get_data() : $attribute;
    }
    $out[$sku] = [
        'id' => $product->get_id(), 'type' => $product->get_type(), 'parent' => $product->get_parent_id('edit'),
        'sku' => $product->get_sku('edit'), 'name' => $product->get_name('edit'), 'status' => $product->get_status('edit'),
        'regular_price' => $product->get_regular_price('edit'), 'price' => $product->get_price('edit'),
        'manage_stock' => $product->get_manage_stock('edit'), 'stock' => $product->get_stock_quantity('edit'),
        'stock_status' => $product->get_stock_status('edit'), 'categories' => $product->get_category_ids('edit'),
        'attributes' => $attributes, 'defaults' => $product->get_default_attributes('edit'),
    ];
}
global $wpdb;
$ids = array_column($out, 'id');
$lookup = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN (" . implode(',', $ids) . ") ORDER BY product_id", ARRAY_A);
$check($wpdb->last_error === '' && count($lookup) === 3, 'three native product lookup rows');
echo json_encode(['products' => $out, 'lookup' => $lookup], JSON_THROW_ON_ERROR), "\n";
