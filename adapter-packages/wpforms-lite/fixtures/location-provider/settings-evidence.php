<?php
declare(strict_types=1);

/** Native UI and value semantics only; no alternative capture/apply implementation. */
final class WPFormsSettingsEvidence {
    public const VALIDATION = [
        'validation-required' => 'val_required', 'validation-email' => 'val_email',
        'validation-email-suggestion' => 'val_email_suggestion', 'validation-email-restricted' => 'val_email_restricted',
        'validation-number' => 'val_number', 'validation-number-positive' => 'val_number_positive',
        'validation-minimum-price' => 'val_minimum_price', 'validation-confirm' => 'val_confirm',
        'validation-inputmask-incomplete' => 'val_inputmask_incomplete', 'validation-check-limit' => 'val_checklimit',
        'validation-character-limit' => 'val_limit_characters', 'validation-word-limit' => 'val_limit_words',
        'validation-requiredpayment' => 'val_requiredpayment', 'validation-creditcard' => 'val_creditcard',
        'validation-min' => 'val_min', 'validation-max' => 'val_max',
    ];

    public static function values(string $side, string $view): array {
        self::check(in_array($side, ['source', 'target'], true), 'exact native side');
        if ($view === 'general') return ['disable-css' => $side === 'source' ? '2' : '3',
            'global-assets' => $side === 'source', 'gdpr' => $side === 'source'];
        self::check($view === 'validation', 'exact native settings view');
        $values = [];
        foreach (self::VALIDATION as $key => $_) $values[$key] = ucfirst($side) . ' 日本語 Ω ' . $key . ' {field_id}';
        return $values;
    }

    /** Read the actual protected form, never manufacture its nonce or enabled controls. */
    public static function form(string $html, string $view): string {
        self::check(strlen($html) > 100 && strlen($html) <= 1048576
            && preg_match('/PHP (?:Warning|Notice|Fatal error|Parse error|Deprecated)|<b>(?:Warning|Notice|Fatal error|Parse error|Deprecated)<\/b>/i', $html) === 0,
            'bounded native admin HTML without PHP diagnostics');
        $dom = new DOMDocument();
        // libxml's HTML4 parser reports valid HTML5 tags as syntax errors.
        // Disable only those parser messages and network access, not PHP diagnostics.
        self::check($dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'native HTML parses');
        $xpath = new DOMXPath($dom);
        $forms = $xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " wpforms-admin-settings-form ")]');
        self::check($forms !== false && $forms->length === 1, 'exactly one native settings form');
        $form = $forms->item(0);
        self::check($form instanceof DOMElement && strtolower($form->getAttribute('method')) === 'post', 'native POST form');
        $one = static function (string $name) use ($xpath, $form): DOMElement {
            $nodes = $xpath->query('.//*[@name="' . $name . '"]', $form);
            self::check($nodes !== false && $nodes->length === 1 && $nodes->item(0) instanceof DOMElement,
                'one native control: ' . $name);
            return $nodes->item(0);
        };
        foreach (['action' => 'update-settings', 'view' => $view] as $name => $value) {
            self::check($one($name)->getAttribute('type') === 'hidden' && $one($name)->getAttribute('value') === $value,
                'native hidden request control: ' . $name);
        }
        $nonce = $one('nonce');
        self::check($nonce->getAttribute('type') === 'hidden'
            && preg_match('/^[a-f0-9]{10}$/D', $nonce->getAttribute('value')) === 1, 'native settings nonce');
        self::check($one('wpforms-settings-submit')->tagName === 'button', 'native submit control');
        foreach (self::values('source', $view) as $key => $_) {
            $control = $one($key);
            self::check(!$control->hasAttribute('disabled') && $control->getAttribute('id') === 'wpforms-setting-' . $key,
                'enabled native authored control: ' . $key);
            if ($key === 'disable-css') {
                self::check($control->tagName === 'select', 'stylesheet is the native select');
                $choices = [];
                foreach ($control->getElementsByTagName('option') as $option) $choices[] = $option->getAttribute('value');
                self::check($choices === ['1', '2', '3'], 'exact native stylesheet enum');
            } else self::check($control->tagName === 'input'
                && $control->getAttribute('type') === ($view === 'general' ? 'checkbox' : 'text'), 'native control type: ' . $key);
        }
        if ($view === 'general') {
            foreach (['gdpr-disable-uuid', 'gdpr-disable-details'] as $key) {
                self::check($one($key)->hasAttribute('disabled'), 'Pro-only education control stays disabled');
            }
            self::check($one('lite-connect-enabled')->hasAttribute('disabled'), 'native Lite Connect cloud-enrollment control stays disabled');
            self::check($xpath->query('.//*[@name="modern-markup"]', $form)->length === 0,
                'fresh-site conditional markup UI is not silently enabled');
        }
        return $nonce->getAttribute('value');
    }

    public static function post(string $side, string $view, string $nonce): array {
        $post = ['action' => 'update-settings', 'view' => $view, 'nonce' => $nonce, 'wpforms-settings-submit' => ''];
        foreach (self::values($side, $view) as $key => $value) {
            if ($value === false) continue; // Native unchecked/disabled controls are not submitted.
            $post[$key] = $value === true ? '1' : ($view === 'validation' ? '  <b>' . $value . '</b>  ' : $value);
        }
        return $post;
    }

    public static function author(array $record, string $pair, string $side, string $view): void {
        self::keys($record, ['format', 'version', 'side', 'view', 'home', 'actor', 'session_retired', 'debug_config',
            'diagnostics_before', 'diagnostics_after', 'requests', 'before', 'after']);
        $number = $side === 'source' ? 1 : 2;
        self::check(($record['format'] ?? null) === 'wprism-wpforms-native-settings-author/v1'
            && ($record['version'] ?? null) === '2.0.1.1' && ($record['side'] ?? null) === $side
            && ($record['view'] ?? null) === $view && ($record['home'] ?? null) === 'http://' . $pair . $number . '.invalid'
            && ($record['actor'] ?? null) === 'admin' && ($record['session_retired'] ?? null) === true,
            'native settings author identity and exact session retirement');
        self::check(($record['debug_config'] ?? null) === [true, true, false]
            && is_array($record['diagnostics_before'] ?? null) && $record['diagnostics_before'] === ($record['diagnostics_after'] ?? null),
            'native HTTP server diagnostics are enabled and unchanged');
        self::diagnostics($record['diagnostics_before']);
        $requests = $record['requests'] ?? null;
        self::check(is_array($requests) && array_is_list($requests) && count($requests) === 2, 'complete GET and POST native exchange');
        $path = '/wp-admin/admin.php?page=wpforms-settings&view=' . $view;
        foreach ($requests as $index => $request) {
            self::keys($request, ['url', 'host', 'method', 'post', 'status', 'headers', 'body']);
            self::check(($request['url'] ?? null) === 'http://wprism-' . $pair . '-wp' . $number . '-1' . $path
                && ($request['host'] ?? null) === $pair . $number . '.invalid'
                && ($request['method'] ?? null) === ($index === 0 ? 'GET' : 'POST')
                && ($request['status'] ?? null) === 200 && is_array($request['headers'] ?? null)
                && is_string($request['body'] ?? null), 'exact owned authenticated HTTP exchange');
            self::form($request['body'], $view);
        }
        $nonce = self::form($requests[0]['body'], $view);
        self::check(($requests[0]['post'] ?? null) === [] && ($requests[1]['post'] ?? null) === self::post($side, $view, $nonce)
            && str_contains($requests[1]['body'], 'Settings were successfully saved.'), 'actual native nonce, exact legal submitted fields and save notice');
        $before = self::stored($record['before'] ?? []);
        $after = self::stored($record['after'] ?? []);
        $expected = array_replace($before, self::values($side, $view));
        if ($view === 'general') $expected = array_replace($expected, ['gdpr-disable-uuid' => false, 'gdpr-disable-details' => false]);
        self::check($before !== $after && $expected === $after, 'native save changed exactly its registered settings, with native sanitization and scalar types');
        self::check($record['before']['rows'][0]['option_id'] === $record['after']['rows'][0]['option_id'], 'native save retains the existing settings row identity');
        self::check(($record['before']['rows'][1] ?? null) === ($record['after']['rows'][1] ?? null), 'native settings save preserves the separate crypto row');
    }

    public static function stored(array $record): array {
        $rows = $record['rows'] ?? null;
        self::check(is_array($rows) && array_is_list($rows) && count($rows) === 2
            && ($rows[0]['option_name'] ?? null) === 'wpforms_settings'
            && ($rows[1]['option_name'] ?? null) === 'wpforms_crypto_secret_key'
            && is_string($rows[1]['option_value'] ?? null) && strlen((string) base64_decode($rows[1]['option_value'], true)) === 32
            && base64_encode((string) base64_decode($rows[1]['option_value'], true)) === $rows[1]['option_value'],
            'complete native settings and valid native crypto rows');
        foreach ($rows as $row) {
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            self::check($keys === ['autoload', 'option_id', 'option_name', 'option_value']
                && is_string($row['option_id']) && preg_match('/^[1-9][0-9]{0,19}$/D', $row['option_id']) === 1
                && is_string($row['autoload']) && strlen($row['autoload']) > 0 && strlen($row['autoload']) <= 20,
                'complete typed native option columns');
        }
        self::check($rows[0]['option_id'] !== $rows[1]['option_id'], 'distinct native option identities');
        $settings = $record['values'] ?? null;
        self::check(is_array($settings) && !array_is_list($settings)
            && ($rows[0]['option_value'] ?? null) === serialize($settings), 'native decoded settings agree with complete raw storage');
        return $settings;
    }

    public static function native(array $source, array $before, array $after, array $stable, array $compiledOptions): void {
        // Only this host-side compiler comparison needs source-tree classes.
        // The native HTTP worker mounts the capsule beside src/, without agent/.
        require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
        require_once dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
        foreach ([$source, $before, $after, $stable] as $record) self::diagnostics($record['settings_diagnostics'] ?? []);
        $sourceValues = self::stored($source['settings'] ?? []);
        $beforeValues = self::stored($before['settings'] ?? []);
        $afterValues = self::stored($after['settings'] ?? []);
        self::stored($stable['settings'] ?? []);
        $expected = self::values('source', 'general') + self::values('source', 'validation');
        foreach ($expected as $key => $value) self::check(($sourceValues[$key] ?? null) === $value, 'actual native source owns the authored value: ' . $key);
        self::check(($sourceValues['modern-markup'] ?? null) === '1', 'automatic markup initialization is retained, not claimed as a UI save');
        $expected['modern-markup'] = '1';
        $compiled = WPrism\OptionState::values($compiledOptions);
        self::check(WPrism\Canon::encode($compiled['wpforms_settings'] ?? null) === WPrism\Canon::encode($expected),
            'real compiler binds exactly the 19 natively authored controls and the separate automatic value');
        self::check($afterValues === array_replace($beforeValues, $expected), 'Apply preserves every unselected native settings member and type');
        self::check($after['settings'] === $stable['settings'], 'repeat Apply leaves the complete native option rows unchanged');
        self::check($before['settings']['rows'][1] === $after['settings']['rows'][1]
            && $source['settings']['rows'][1]['option_value'] !== $after['settings']['rows'][1]['option_value'], 'target native encryption material survives and is not copied from source');
        self::check(array_diff_key($before['settings']['rows'][0], ['option_value' => true])
            === array_diff_key($after['settings']['rows'][0], ['option_value' => true]), 'native settings row identity and autoload remain unchanged');
        foreach ([$after, $stable] as $record) {
            foreach (self::VALIDATION as $key => $consumer) {
                self::check(($record['settings_consumers']['strings'][$consumer] ?? null) === $expected[$key],
                    'fresh native frontend validation consumer: ' . $key);
            }
            self::check(($record['settings_consumers']['global_assets'] ?? null) === true
                && ($record['settings_consumers']['render_engine'] ?? null) === 'modern'
                && ($record['settings_consumers']['styles'] ?? null) === ['wpforms-modern-base'], 'fresh native general presentation consumers');
            self::check(($afterValues['gdpr-disable-uuid'] ?? null) === true && ($afterValues['gdpr-disable-details'] ?? null) === true
                && ($record['settings_consumers']['ip_allowed'] ?? null) === false
                && ($record['settings_consumers']['cookies_allowed'] ?? null) === false, 'portable GDPR toggle composes with preserved target-local privacy residue');
        }
    }

    public static function initial(array $sourceAuthor, array $targetAuthor, array $local, array $source, array $target): void {
        self::check(($local['format'] ?? null) === 'wprism-wpforms-native-local-settings/v1'
            && ($local['before'] ?? null) === ($targetAuthor['after'] ?? null)
            && ($local['after'] ?? null) === ($target['settings'] ?? null)
            && ($sourceAuthor['after'] ?? null) === ($source['settings'] ?? null), 'actual HTTP saves and explicit local setup bind the initial native observations');
        $sourceValues = self::stored($source['settings'] ?? []);
        $targetValues = self::stored($target['settings'] ?? []);
        foreach (self::values('target', 'general') + self::values('target', 'validation') as $key => $value) {
            self::check(($targetValues[$key] ?? null) === $value && ($sourceValues[$key] ?? null) !== $value,
                'every supported authored setting begins with a discriminating target value: ' . $key);
        }
        $residue = ['gdpr-disable-uuid' => true, 'gdpr-disable-details' => true,
            'modern-markup-is-set' => 'target-initialized', 'modern-markup-hide-setting' => false,
            'lite-connect-enabled' => '0', 'license-key' => 'sk_live_LOCALFIXTURE123456789012',
            'integrations-providers' => ['customer_email' => 'private@example.test'],
            'future-setting' => ['nested' => [null, false, 0, '0', '日本語 Ω']]];
        self::check($targetValues === array_replace(self::stored($local['before']), $residue), 'local setup adds only the declared synthetic hostile witnesses');
        self::check($local['before']['rows'][1] === $local['after']['rows'][1], 'local setup retains the native crypto key');
        self::check(($target['settings_consumers']['global_assets'] ?? null) === false
            && ($target['settings_consumers']['styles'] ?? null) === ['wpforms-no-styles']
            && ($target['settings_consumers']['ip_allowed'] ?? null) === true
            && ($target['settings_consumers']['cookies_allowed'] ?? null) === true, 'initial native consumers distinguish the target general settings');
    }

    public static function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException('WPForms settings evidence: ' . $message);
    }

    public static function diagnostics(array $record): void {
        self::check(in_array($record, [['present' => false, 'bytes' => ''], ['present' => true, 'bytes' => '']], true),
            'native server diagnostic witness is complete and empty, not a preserved warning');
    }

    private static function keys(array $record, array $expected): void {
        $actual = array_keys($record);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        self::check($actual === $expected, 'native author record has exactly its declared members');
    }
}
