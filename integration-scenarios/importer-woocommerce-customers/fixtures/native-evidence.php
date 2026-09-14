<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once __DIR__ . '/database-evidence.php';

final class ImporterWooNativeEvidence {
    public static function consumers(array $before, array $after): void {
        $check = static function (bool $ok, string $why): void {
            if (!$ok) throw new RuntimeException('Importer/Woo native consumer evidence: ' . $why);
        };
        $logins = ['template-reader', 'template-editor', 'import-template-reader'];
        $billing = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'email', 'phone'];
        $shipping = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'phone'];
        // Woo customer and order getters expose the same address fields in
        // different insertion orders; completeness concerns the closed roster.
        $hasFields = static fn(array $address, array $fields): bool => count($address) === count($fields)
            && array_diff($fields, array_keys($address)) === [];
        $check(array_keys($before) === ['phase', 'customers'] && $before['phase'] === 'seed-target'
            && array_keys($after) === ['phase', 'customers'] && $after['phase'] === 'observe', 'exact native phases');
        $check(is_array($before['customers']) && is_array($after['customers']) && array_keys($before['customers']) === $logins, 'all three target customer witnesses');
        $ids = []; $orderIds = [];
        foreach ($before['customers'] as $login => $customer) {
            $check(is_array($customer) && array_keys($customer) === ['id', 'email', 'role', 'billing', 'shipping', 'orders']
                && is_int($customer['id']) && $customer['id'] > 1 && !isset($ids[$customer['id']])
                && $customer['role'] === 'customer' && $customer['email'] === $login . '-target@example.test', 'native customer identity');
            $ids[$customer['id']] = true;
            $check(is_array($customer['billing']) && is_array($customer['shipping']) && $hasFields($customer['billing'], $billing) && $hasFields($customer['shipping'], $shipping)
                && $customer['billing']['city'] === 'Dhaka' && $customer['billing']['country'] === 'BD'
                && $customer['shipping']['city'] === 'Tokyo' && $customer['shipping']['country'] === 'JP', 'complete independent target addresses');
            $check(is_array($customer['orders']) && array_is_list($customer['orders']) && count($customer['orders']) === 3, 'three paid and pending orders per customer');
            foreach ($customer['orders'] as $index => $order) {
                $check(is_array($order) && array_keys($order) === ['id', 'customer_id', 'status', 'total', 'currency', 'billing', 'shipping']
                    && is_int($order['id']) && $order['id'] > 0 && !isset($orderIds[$order['id']])
                    && $order['customer_id'] === $customer['id']
                    && $order['status'] === ['processing', 'completed', 'pending'][$index]
                    && $order['total'] === ['12.50', '7.50', '99.00'][$index] && $order['currency'] === 'USD'
                    && is_array($order['billing']) && is_array($order['shipping']) && $hasFields($order['billing'], $billing) && $hasFields($order['shipping'], $shipping),
                    'complete independent native order witness');
                $orderIds[$order['id']] = true;
            }
        }
        $expected = $before['customers'];
        $expected['import-template-reader']['billing']['city'] = 'Chattogram';
        $expected['import-template-reader']['shipping']['city'] = 'Osaka';
        $check($after['customers'] === $expected, 'fresh Woo readback changes only the two mapped customer addresses');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if ($argc !== 3) throw new RuntimeException('native consumer evidence requires sink and pair');
$read = static fn(string $label): array => json_decode(WPrismTest\PrivateCommandOutput::readObject(
    $argv[1] . '/' . $label, ImporterWooDatabaseEvidence::transport($argv[2], 2)), true, flags: JSON_THROW_ON_ERROR);
ImporterWooNativeEvidence::consumers($read('customers2'), $read('customers-after'));
echo "PASS: fresh native customer readback preserves every observed order and unmapped address field\n";
