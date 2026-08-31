<?php
declare(strict_types=1);

/**
 * Hash-only observer for the finite WPRA-019 WooCommerce PII grant roster.
 *
 * The version matrix uses this against captured state before/after native
 * writes and after target recapture. It never prints a merchant field value.
 */

const WPRISM_WOO_ALLOW_PII_GRANTS = [
    'option:pickup_location_pickup_locations',
    'option:woocommerce_default_country',
    'option:woocommerce_email_from_name',
    'option:woocommerce_email_reply_to_name',
    'option:woocommerce_pos_store_address',
    'option:woocommerce_pos_store_email',
    'option:woocommerce_pos_store_phone',
    'option:woocommerce_store_address',
    'option:woocommerce_store_address_2',
    'option:woocommerce_store_city',
    'option:woocommerce_store_postcode',
    'post_meta:customer_email',
    'table:woocommerce_tax_rates.tax_rate_country',
    'table:woocommerce_tax_rates.tax_rate_state',
];

/** @return mixed */
function wprism_woo_canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('wprism_woo_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $entry) {
        $value[$key] = wprism_woo_canonicalize($entry);
    }
    return $value;
}

function wprism_woo_value_fingerprint(mixed $value): string {
    return hash('sha256', json_encode(
        wprism_woo_canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
}

/** @return array<string,mixed> */
function wprism_woo_read_json(string $path, string $label): array {
    $bytes = @file_get_contents($path);
    if (!is_string($bytes)) {
        throw new RuntimeException("$label is unreadable");
    }
    $decoded = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException("$label is not an object");
    }
    return $decoded;
}

// Capsule contract tests load the closed grant roster and hashing helpers;
// only direct CLI execution may inspect a captured state tree.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

if (($argv[1] ?? '') === '--grants') {
    echo json_encode(WPRISM_WOO_ALLOW_PII_GRANTS, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

$stateRoot = rtrim((string) ($argv[1] ?? ''), '/');
if ($stateRoot === '' || !is_dir($stateRoot)) {
    throw new RuntimeException('WPRA-019 observer requires a captured state directory');
}

$options = wprism_woo_read_json($stateRoot . '/options/core.json', 'captured option document');
$records = $options['records'] ?? null;
if (!is_array($records) || array_is_list($records)) {
    throw new RuntimeException('captured option records are malformed');
}

$couponPaths = glob($stateRoot . '/posts/shop_coupon/*--conf-welcome10.md');
if (!is_array($couponPaths) || count($couponPaths) !== 1) {
    throw new RuntimeException('WPRA-019 observer requires one named coupon state file');
}
$couponBytes = @file_get_contents($couponPaths[0]);
if (!is_string($couponBytes)
    || preg_match('/\A---\n(.*?)\n---\n/s', $couponBytes, $couponMatch) !== 1) {
    throw new RuntimeException('named coupon front matter is malformed');
}
$coupon = json_decode($couponMatch[1], true, 128, JSON_THROW_ON_ERROR);
if (!is_array($coupon) || array_is_list($coupon) || !is_array($coupon['meta'] ?? null)
    || !array_key_exists('customer_email', $coupon['meta'])) {
    throw new RuntimeException('named coupon lacks captured customer_email evidence');
}

$taxPaths = glob($stateRoot . '/tables/woocommerce_tax_rates/*.json');
if (!is_array($taxPaths)) {
    throw new RuntimeException('tax-rate state inventory is unreadable');
}
$tax = null;
foreach ($taxPaths as $taxPath) {
    $candidate = wprism_woo_read_json($taxPath, 'captured tax-rate row');
    if (($candidate['columns']['tax_rate_name'] ?? null) === 'Conformance CA Sales Tax') {
        if ($tax !== null) {
            throw new RuntimeException('named WPRA-019 tax-rate row is ambiguous');
        }
        $tax = $candidate;
    }
}
if (!is_array($tax)) {
    throw new RuntimeException('named WPRA-019 tax-rate row is absent');
}

$values = [];
foreach (array_slice(WPRISM_WOO_ALLOW_PII_GRANTS, 0, 11) as $grant) {
    $option = substr($grant, strlen('option:'));
    $record = $records[$option] ?? null;
    if (!is_array($record) || ($record['state'] ?? null) !== 'present'
        || !array_key_exists('value', $record)) {
        throw new RuntimeException("$grant lacks one present captured value");
    }
    $values[$grant] = $record['value'];
}
$values['post_meta:customer_email'] = $coupon['meta']['customer_email'];
foreach (['tax_rate_country', 'tax_rate_state'] as $column) {
    if (!array_key_exists($column, (array) ($tax['columns'] ?? []))) {
        throw new RuntimeException("table:woocommerce_tax_rates.$column is absent");
    }
    $values['table:woocommerce_tax_rates.' . $column] = $tax['columns'][$column];
}

$fingerprints = [];
foreach (WPRISM_WOO_ALLOW_PII_GRANTS as $grant) {
    if (!array_key_exists($grant, $values)) {
        throw new RuntimeException("WPRA-019 observer omitted $grant");
    }
    $fingerprints[$grant] = wprism_woo_value_fingerprint($values[$grant]);
}
echo json_encode($fingerprints, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
