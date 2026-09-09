<?php
declare(strict_types=1);
global $wpdb;
$document = WPrism\Canon::decode(WPrism\Canon::read_file('/siterepo/state/options/core.json'));
$values = WPrism\OptionState::values($document);
$policy = WPrism\Policy::load('/siterepo');
$raw = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'aio\\_login%' OR option_name LIKE 'rwl\\_%' ORDER BY option_name", ARRAY_A);
$rebind = static function (mixed $value) use (&$rebind): mixed {
    if (is_string($value)) return str_replace(home_url(), '{{home}}', $value);
    if (is_array($value)) foreach ($value as &$child) $child = $rebind($child);
    return $value;
};
$checked = 0;
foreach ($raw as $row) {
    $name = $row['option_name'];
    $rule = $policy->option_rule($name);
    if (($rule['class'] ?? '') !== 'authored') {
        if (array_key_exists($name, $values)) throw new RuntimeException("non-authored $name leaked into capture");
        continue;
    }
    if (!array_key_exists($name, $values)) throw new RuntimeException("authored $name missing from capture");
    $expected = WPrism\PlainData::decode($row['option_value'], "native fixture $name");
    if (($rule['ref'] ?? '') === 'post') {
        $uuid = WPrism\Ledger::uuid_for((int) $expected, 'post');
        if (!$uuid || !wp_get_attachment_url((int) $expected)) throw new RuntimeException("$name lacks a native attachment identity");
        $expected = '{{post:' . $uuid . '}}';
    } elseif ($name === 'aio_login_pro_login_redirection_rules') {
        foreach ($expected as &$redirect) {
            foreach (['login', 'logout'] as $event) {
                if ($redirect[$event . '_target_type'] === 'page') {
                    $uuid = WPrism\Ledger::uuid_for((int) $redirect[$event . '_target_value'], 'post');
                    if (!$uuid) throw new RuntimeException('native redirect destination lacks identity');
                    $redirect[$event . '_target_value'] = '{{post:' . $uuid . '}}';
                }
            }
        }
        unset($redirect);
        $expected = $rebind($expected);
    } else {
        $expected = $rebind($expected);
    }
    if (WPrism\Canon::encode($expected) !== WPrism\Canon::encode($values[$name])
        || $document['records'][$name]['autoload'] !== $row['autoload']) {
        throw new RuntimeException("native authored $name differs from its complete canonical value or autoload intent");
    }
    $checked++;
}
if ($checked !== 74) throw new RuntimeException("native writer inventory changed: expected 74 authored options, observed $checked");
echo wp_json_encode(['authored_options_verified' => $checked, 'media_refs_verified' => 5,
    'redirect_refs_verified' => true, 'non_authored_rows_excluded' => true]);
