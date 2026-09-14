<?php
declare(strict_types=1);

// Scenario fixture only: native customer/order state belongs to the target,
// whereas the saved mapping transferred by Apply belongs to repository intent.
$phase = $args[0] ?? '';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Importer/Woo native customers: ' . $why);
};
$check(defined('WC_VERSION') && WC_VERSION === '11.0.1'
    && defined('WT_U_IEW_VERSION') && WT_U_IEW_VERSION === '2.7.5'
    && is_admin() && current_user_can('manage_options'), 'exact native administrator context');
add_filter('pre_wp_mail', static fn() => true);
$check(Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'native HPOS authority');
if (in_array($phase, ['inputs-source', 'inputs-target'], true)) {
    global $wp_filter;
    $owners = [];
    foreach ($wp_filter['wp_ajax_iew_import_ajax_basic']->callbacks ?? [] as $group) foreach ($group as $entry) {
        $callback = $entry['function'];
        if (is_array($callback) && is_object($callback[0]) && $callback[1] === 'ajax_main') $owners[] = $callback[0];
    }
    $check(count($owners) === 1, 'one native import input owner');
    $files = $phase === 'inputs-source' ? ['source-input.csv'] : ['target-input.csv', 'rotated-input.csv'];
    foreach ($files as $name) {
        $file = $owners[0]->get_file_path($name);
        $check(is_file($file) && filesize($file) < 8192, 'existing bounded capsule input');
        $handle = fopen($file, 'rb');
        $check(is_resource($handle), 'input readable');
        $header = fgetcsv($handle); $row = fgetcsv($handle); $end = fgetcsv($handle);
        fclose($handle);
        $check($header === ['Login', 'Email', 'Display', 'Password', 'LocalDisplay']
            && is_array($row) && count($row) === 5 && $end === false, 'one unmodified capsule CSV input');
        $header = [...$header, 'billing_city', 'shipping_city'];
        $row = [...$row, $phase === 'inputs-source' ? 'Marseille' : 'Chattogram',
            $phase === 'inputs-source' ? 'Nice' : 'Osaka'];
        $handle = fopen($file, 'wb');
        $check(is_resource($handle), 'fixture input writable');
        $check(fputcsv($handle, $header) !== false && fputcsv($handle, $row) !== false, 'complete mapped customer CSV');
        $check(fclose($handle), 'input flushed');
    }
    echo json_encode(['phase' => $phase, 'files' => $files], JSON_THROW_ON_ERROR), "\n";
    return;
}
$logins = ['template-reader', 'template-editor', 'import-template-reader'];
$observe = static function () use ($logins, $check): array {
    $out = [];
    foreach ($logins as $login) {
        $user = get_user_by('login', $login);
        $check($user instanceof WP_User, 'existing fixture login');
        $customer = new WC_Customer($user->ID);
        $orders = wc_get_orders(['customer_id' => $user->ID, 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
        $out[$login] = [
            'id' => $customer->get_id(), 'email' => $customer->get_email(),
            'role' => $customer->get_role(), 'billing' => $customer->get_billing('edit'),
            'shipping' => $customer->get_shipping('edit'),
            'orders' => array_map(static fn(WC_Order $order): array => [
                'id' => $order->get_id(), 'customer_id' => $order->get_customer_id(),
                'status' => $order->get_status(), 'total' => $order->get_total(),
                'currency' => $order->get_currency(), 'billing' => $order->get_address('billing'),
                'shipping' => $order->get_address('shipping'),
            ], $orders),
        ];
    }
    return $out;
};
if (in_array($phase, ['seed-source', 'seed-target'], true)) {
    $side = substr($phase, 5);
    foreach ($logins as $login) {
        $user = get_user_by('login', $login);
        $check($user instanceof WP_User, 'capsule native setup precedes customer seed');
        // Woo 11.0.1's customer data-store update() writes email/display name,
        // not role (class-wc-customer-data-store.php:198-212). Establish it
        // through WordPress before loading Woo's customer representation.
        $user->set_role('customer');
        $customer = new WC_Customer($user->ID);
        $check($customer->get_billing_city() === '' && $customer->get_shipping_city() === '', 'seed never replaces addresses');
        $check($customer->get_role() === 'customer', 'native WordPress customer role is persisted');
        $customer->set_billing_first_name('Local');
        $customer->set_billing_last_name($side);
        $customer->set_billing_city($side === 'source' ? 'Paris' : 'Dhaka');
        $customer->set_billing_country($side === 'source' ? 'FR' : 'BD');
        $customer->set_billing_email($user->user_email);
        $customer->set_shipping_city($side === 'source' ? 'Lyon' : 'Tokyo');
        $customer->set_shipping_country($side === 'source' ? 'FR' : 'JP');
        $check($customer->save() === $user->ID, 'native customer Save retains WordPress identity');
        $check(wc_get_orders(['customer_id' => $user->ID, 'limit' => 1]) === [], 'seed never adds to prior orders');
        // Native export counts pending orders too, while its spend sum excludes
        // them (locked Importer export.php:277-335); all-paid seeds hide this.
        foreach (['processing' => '12.50', 'completed' => '7.50', 'pending' => '99.00'] as $status => $total) {
            $order = wc_create_order(['customer_id' => $user->ID]);
            $check($order instanceof WC_Order, 'native order creation');
            $order->set_currency('USD');
            $order->set_billing_email($user->user_email);
            $order->set_billing_city($customer->get_billing_city());
            $order->set_shipping_city($customer->get_shipping_city());
            $order->set_total($side === 'source' ? '1.00' : $total);
            $order->set_status($status);
            $check($order->save() > 0, 'native order Save');
        }
    }
} else {
    $check($phase === 'observe', 'known read-only observation phase');
}
echo json_encode(['phase' => $phase, 'customers' => $observe()], JSON_THROW_ON_ERROR), "\n";
