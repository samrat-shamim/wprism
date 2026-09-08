<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';

/** The native Builder owns its writer; this capsule owns one untagged QR Save contract. */
final class WPFormsBuilderSaveEvidence {
    public const QR_KEYS = ['qr_code', 'qr_code_page_id', 'qr_code_url', 'qr_code_logo', 'qr_code_generated'];

    public static function invocation(string $home, int $formId, bool $observeOnly = false): string {
        self::identity($home, $formId);
        $code = file_get_contents(__DIR__ . '/browser-save.js');
        self::check(is_string($code) && $code !== '' && strlen($code) <= 16384, 'exact capsule collector source');
        return 'async (page) => await (' . "\n" . $code . "\n" . ')(page, '
            . json_encode(['home' => $home, 'form_id' => $formId, 'mode' => $observeOnly ? 'observe' : 'save'], JSON_THROW_ON_ERROR) . ')' . "\n";
    }

    public static function read(string $stem): array {
        $bytes = WPrismTest\PrivateCommandOutput::readObject($stem);
        $outer = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        self::keys($outer, ['result']);
        self::check(is_string($outer['result']), 'one explicit CLI JSON result, not a success exit or text listing');
        $record = json_decode($outer['result'], true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($record) && !array_is_list($record), 'complete browser collector object');
        return $record;
    }

    /** Caller-selected identities and values cannot be supplied by the observed record itself. */
    public static function admit(array $record, string $home, int $formId, array $qr, array $pages, array $baseline): array {
        self::identity($home, $formId);
        self::keys($qr, self::QR_KEYS);
        self::check(in_array($qr['qr_code'], ['none', 'page', 'url'], true) && $qr['qr_code_logo'] === 'wpforms', 'declared submitted destination and fixed Lite logo');
        foreach ($qr as $value) self::check(is_string($value), 'native submitted QR scalar types');
        // The native picker submits an empty placeholder, not stored integer0.
        // None's persisted normalization belongs to the separate SQL checker;
        // its still-mounted inactive inputs must not be silently cleared here.
        $page = $qr['qr_code_page_id'];
        self::check($page === '' || (preg_match('/^[1-9][0-9]*$/D', $page) === 1
            && array_key_exists($page, $pages)), 'submitted page placeholder or independently selectable identity');
        self::keys($baseline, ['format', 'observed_ms', 'snapshot']);
        self::check($baseline['format'] === 'wprism-wpforms-builder-baseline/v1' && is_int($baseline['observed_ms']), 'separate pre-click DOM baseline');
        $baselineControls = self::snapshot($baseline['snapshot'], $home, $formId, $qr, $pages);
        self::keys($record, ['format', 'started_ms', 'ended_ms', 'before', 'after', 'events', 'exchanges', 'console', 'page_errors', 'errors', 'drained']);
        self::check($record['format'] === 'wprism-wpforms-builder-save/v1' && $record['drained'] === true
            && $record['console'] === [] && $record['page_errors'] === [] && $record['errors'] === [], 'complete diagnostic-free drained collector');
        $start = $record['started_ms'];
        $end = $record['ended_ms'];
        self::check(is_int($start) && is_int($end) && $start > 0 && $end >= $start && $end - $start <= 30000, 'bounded native Save clock');
        self::check($baseline['observed_ms'] <= $start && $start - $baseline['observed_ms'] <= 60000, 'baseline belongs to the immediate pre-click interval');
        $before = self::snapshot($record['before'], $home, $formId, $qr, $pages);
        $after = self::snapshot($record['after'], $home, $formId, $qr, $pages);
        self::check($record['after']['saved'] === true, 'native complete form state is saved');
        foreach (['url', 'home', 'form_id', 'nonce', 'ajax_url', 'page_maps'] as $key) {
            self::check($record['before'][$key] === $record['after'][$key], 'same native Builder context after Save');
        }
        self::check($before === $after, 'entire observed form-control list preserved by this QR-only Save');
        self::check($before === $baselineControls, 'complete controls match the independently retained pre-click baseline');
        self::keys($record['events'], ['before_save', 'saved', 'closed_ms']);
        foreach (['before_save', 'saved'] as $name) self::check(is_array($record['events'][$name])
            && array_is_list($record['events'][$name]) && count($record['events'][$name]) === 1, 'exactly one native ' . $name . ' event');
        $beforeSave = $record['events']['before_save'][0];
        $saved = $record['events']['saved'][0];
        self::keys($beforeSave, ['at_ms', 'controls']);
        self::keys($saved, ['at_ms', 'data']);
        self::check(is_int($saved['at_ms']) && is_int($record['events']['closed_ms'])
            && $record['events']['closed_ms'] >= $saved['at_ms'] + 250 && $record['events']['closed_ms'] <= $end,
            'explicit bounded post-saved observation window, not indefinite quiescence');
        $submitted = self::controls($beforeSave['controls']);
        self::check($submitted === $before, 'post-rich-text-sync native serialization matches the complete observed controls');
        self::check(is_array($record['exchanges']) && array_is_list($record['exchanges']) && count($record['exchanges']) === 2,
            'exact native form and custom-theme exchanges, without ignored recorded requests');
        $exchange = $record['exchanges'][0];
        self::keys($exchange, ['ordinal', 'started_ms', 'method', 'url', 'resource_type', 'redirected', 'request_headers', 'request_body', 'response', 'failure']);
        self::check($exchange['ordinal'] === 1 && $exchange['method'] === 'POST' && $exchange['url'] === $home . '/wp-admin/admin-ajax.php'
            && $exchange['resource_type'] === 'xhr' && $exchange['redirected'] === false && $exchange['failure'] === null, 'single native Builder XHR without redirect or failure');
        $requestHeaders = self::headers($exchange['request_headers']);
        self::check(($requestHeaders['content-type'] ?? null) === 'application/x-www-form-urlencoded; charset=UTF-8'
            && ($requestHeaders['origin'] ?? null) === $home && ($requestHeaders['referer'] ?? null) === $record['before']['url']
            && ($requestHeaders['x-requested-with'] ?? null) === 'XMLHttpRequest', 'actual native XHR request context');
        $requestBytes = self::bytes($exchange['request_body']);
        $post = self::post($requestBytes);
        self::keys($post, ['action', 'data', 'id', 'nonce']);
        self::check($post['action'] === 'wpforms_save_form' && $post['id'] === (string) $formId
            && $post['nonce'] === $record['before']['nonce'], 'actual whole-form writer, native identity and nonce, without AI revision flags');
        $actualControls = json_decode($post['data'], true, 32, JSON_THROW_ON_ERROR);
        self::check(self::controls($actualControls) === $submitted, 'complete network POST equals the native before-save observation');
        $response = $exchange['response'];
        self::keys($response, ['status', 'received_ms', 'headers', 'body', 'finished_ms']);
        self::check($response['status'] === 200, 'native Save HTTP status');
        $responseHeaders = self::responseHeaders($response['headers']);
        self::check(($responseHeaders['content-type'] ?? null) === 'application/json; charset=UTF-8', 'native Save JSON response type');
        $responseBytes = self::bytes($response['body']);
        $reply = json_decode($responseBytes, true, 32, JSON_THROW_ON_ERROR);
        $data = ['form_name' => 'WPrism QR Destination Proof', 'form_desc' => '', 'redirect' => $home . '/wp-admin/admin.php?page=wpforms-overview'];
        self::check($reply === ['success' => true, 'data' => $data] && $saved['data'] === $data, 'complete native success response and matching saved event');
        // One owned machine supplies both browser and collector clocks. Body
        // retrieval may finish after the native callback, so only header receipt
        // must precede Saved; both complete observations must precede the end.
        foreach ([$beforeSave['at_ms'], $exchange['started_ms'], $response['received_ms'], $saved['at_ms'], $response['finished_ms']] as $clock) {
            self::check(is_int($clock) && $clock >= $start && $clock <= $end, 'all native events belong to the captured window');
        }
        self::check($beforeSave['at_ms'] <= $exchange['started_ms'] && $exchange['started_ms'] <= $response['received_ms']
            && $response['received_ms'] <= $saved['at_ms'] && $response['received_ms'] <= $response['finished_ms'], 'native before-save/request/response/saved ordering');
        $theme = self::themeExchange($record['exchanges'][1], $home, $record['before']['url'], $start, $end,
            $response['received_ms'], $record['events']['closed_ms']);
        return ['request_bytes' => strlen($requestBytes), 'request_sha256' => hash('sha256', $requestBytes),
            'response_bytes' => strlen($responseBytes), 'response_sha256' => hash('sha256', $responseBytes)] + $theme;
    }

    /** Themes.saveCustomThemes runs on wpformsSaved even for empty admin custom themes. */
    private static function themeExchange(mixed $exchange, string $home, string $url, int $start, int $end, int $formResponseAt, int $closedAt): array {
        self::keys($exchange, ['ordinal', 'started_ms', 'method', 'url', 'resource_type', 'redirected', 'request_headers', 'request_body', 'response', 'failure']);
        self::check($exchange['ordinal'] === 2 && $exchange['method'] === 'POST'
            && $exchange['url'] === $home . '/wp-json/wpforms/v1/themes/custom/?_locale=user'
            && $exchange['resource_type'] === 'fetch' && $exchange['redirected'] === false && $exchange['failure'] === null,
            'one native custom-theme fetch without redirect or failure');
        $requestHeaders = self::headers($exchange['request_headers']);
        self::check(($requestHeaders['content-type'] ?? null) === 'application/json'
            && ($requestHeaders['origin'] ?? null) === $home && ($requestHeaders['referer'] ?? null) === $url
            && is_string($requestHeaders['x-wp-nonce'] ?? null)
            && preg_match('/^[a-f0-9]{10}$/D', $requestHeaders['x-wp-nonce']) === 1, 'native REST request context and nonce framing');
        $requestBytes = self::bytes($exchange['request_body']);
        self::check($requestBytes === '{"customThemes":{}}', 'complete empty native custom-theme payload, not arbitrary theme writes');
        $response = $exchange['response'];
        self::keys($response, ['status', 'received_ms', 'headers', 'body', 'finished_ms']);
        self::check($response['status'] === 200, 'native custom-theme HTTP status');
        $responseHeaders = self::responseHeaders($response['headers']);
        self::check(($responseHeaders['content-type'] ?? null) === 'application/json; charset=UTF-8'
            && ($responseHeaders['x-wp-nonce'] ?? null) === $requestHeaders['x-wp-nonce'], 'native REST response type and echoed nonce');
        $responseBytes = self::bytes($response['body']);
        self::check(json_decode($responseBytes, true, 32, JSON_THROW_ON_ERROR) === ['result' => true], 'complete native theme success response');
        foreach ([$exchange['started_ms'], $response['received_ms'], $response['finished_ms']] as $clock) {
            self::check(is_int($clock) && $clock >= $start && $clock <= $end, 'theme exchange belongs to the captured window');
        }
        // The native theme listener can run before our Saved listener in the
        // same event dispatch. Header receipt, not listener registration order,
        // bounds the earliest legitimate secondary request.
        self::check($exchange['started_ms'] >= $formResponseAt && $exchange['started_ms'] <= $closedAt
            && $exchange['started_ms'] <= $response['received_ms'] && $response['received_ms'] <= $response['finished_ms'],
            'native form-response/theme ordering and complete bounded response');
        return ['theme_request_bytes' => strlen($requestBytes), 'theme_request_sha256' => hash('sha256', $requestBytes),
            'theme_response_bytes' => strlen($responseBytes), 'theme_response_sha256' => hash('sha256', $responseBytes)];
    }

    private static function snapshot(mixed $snapshot, string $home, int $formId, array $qr, array $pages): array {
        self::keys($snapshot, ['url', 'home', 'form_id', 'nonce', 'ajax_url', 'controls', 'page_maps', 'saved']);
        self::check($snapshot['home'] === $home && $snapshot['form_id'] === $formId && $snapshot['ajax_url'] === $home . '/wp-admin/admin-ajax.php'
            && is_string($snapshot['url']) && str_starts_with($snapshot['url'], $home . '/wp-admin/admin.php?')
            && is_string($snapshot['nonce']) && preg_match('/^[a-f0-9]{10}$/D', $snapshot['nonce']) === 1 && is_bool($snapshot['saved']), 'complete owned native Builder observation');
        $query = self::post(substr($snapshot['url'], strlen($home . '/wp-admin/admin.php?')));
        self::check(($query['page'] ?? null) === 'wpforms-builder' && ($query['form_id'] ?? null) === (string) $formId, 'actual Builder URL identity');
        $controls = self::controls($snapshot['controls']);
        $values = [];
        $searchControls = 0;
        foreach ($controls as $control) {
            // Choices owns three empty in-form searches (tags, QR page and
            // confirmation page) in native v5. Keep every ordered control in
            // the full-list comparisons; only the scalar lookup excludes them.
            if ($control['name'] === 'search_terms') {
                self::check($control['value'] === '', 'native Choices searches are idle in the QR Save lane');
                $searchControls++;
                continue;
            }
            self::check(!array_key_exists($control['name'], $values), 'no duplicate native form control name in this fixture');
            $values[$control['name']] = $control['value'];
        }
        self::check($searchControls === 3, 'exact three native in-form Choices search controls');
        self::check(($values['id'] ?? null) === (string) $formId && ($values['settings[form_title]'] ?? null) === 'WPrism QR Destination Proof'
            && ($values['settings[form_desc]'] ?? null) === '' && ($values['settings[form_tags_json]'] ?? null) === '[]', 'exact untagged native fixture form');
        foreach ($qr as $key => $value) self::check(($values['settings[' . $key . ']'] ?? null) === $value, 'independent native QR control: ' . $key);
        self::check(!array_key_exists('settings[qr_code_logo_id]', $values), 'Lite fixed logo has no invented attachment input');
        // The measured untouched Payments panel emits no payment controls.
        // PayPal subscription settings can add response fields and external
        // calls; no payment configuration belongs to this one-text-field lane.
        $fieldTypes = [];
        foreach ($values as $name => $value) {
            self::check(!str_starts_with($name, 'payments['), 'no payment contract in the QR fixture');
            if (str_starts_with($name, 'fields[')) self::check(str_starts_with($name, 'fields[1]['), 'every native field control belongs to field1');
            if (preg_match('/^fields\[[^]]+\]\[type\]$/D', $name) === 1) $fieldTypes[$name] = $value;
        }
        self::check($fieldTypes === ['fields[1][type]' => 'text'] && ($values['fields[1][id]'] ?? null) === '1'
            && ($values['settings[confirmations][1][type]'] ?? null) === 'message', 'one native text field and message confirmation');
        self::check(is_array($snapshot['page_maps']) && array_is_list($snapshot['page_maps']) && count($snapshot['page_maps']) === 1, 'one complete native page map');
        $map = $snapshot['page_maps'][0];
        self::keys($map, ['id', 'value']);
        self::check($map['id'] === 'wpforms-panel-field-settings-qr_code-content' && is_string($map['value']), 'native QR page-map control');
        $actualPages = json_decode($map['value'], true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($actualPages) && $pages !== [], 'independently declared selectable page inventory');
        ksort($actualPages, SORT_STRING);
        ksort($pages, SORT_STRING);
        self::check($actualPages === $pages, 'complete capability-filtered native page map');
        return $controls;
    }

    private static function controls(mixed $controls): array {
        self::check(is_array($controls) && array_is_list($controls) && count($controls) > 0 && count($controls) <= 512, 'bounded complete native serialized controls');
        foreach ($controls as $control) {
            self::keys($control, ['name', 'value']);
            self::check(is_string($control['name']) && $control['name'] !== '' && strlen($control['name']) <= 256
                && is_string($control['value']) && strlen($control['value']) <= 32768, 'exact serialized control scalar types');
        }
        self::check(strlen(json_encode($controls, JSON_THROW_ON_ERROR)) <= 65536, 'bounded full serialized form');
        return $controls;
    }

    private static function post(string $bytes): array {
        self::check($bytes !== '' && strlen($bytes) <= 65536, 'complete bounded native form encoding');
        $out = [];
        foreach (explode('&', $bytes) as $part) {
            self::check(substr_count($part, '=') === 1 && preg_match('/%(?![a-fA-F0-9]{2})/', $part) !== 1, 'strict form encoding');
            [$name, $value] = array_map('urldecode', explode('=', $part, 2));
            self::check($name !== '' && !array_key_exists($name, $out), 'no duplicate or empty outer form field');
            $out[$name] = $value;
        }
        return $out;
    }

    private static function headers(mixed $headers): array {
        self::check(is_array($headers) && array_is_list($headers) && count($headers) > 0 && count($headers) <= 64, 'complete bounded HTTP headers');
        $out = [];
        foreach ($headers as $header) {
            self::keys($header, ['name', 'value']);
            self::check(is_string($header['name']) && preg_match('/^[a-zA-Z0-9-]{1,128}$/D', $header['name']) === 1
                && is_string($header['value']) && strlen($header['value']) <= 16384, 'HTTP header scalar types and bounds');
            $name = strtolower($header['name']);
            // Preserve duplicate physical headers in the transcript; semantic
            // headers used below must each have exactly one observed value.
            $out[$name] = array_key_exists($name, $out) ? null : $header['value'];
        }
        return $out;
    }

    private static function responseHeaders(mixed $headers): array {
        $out = self::headers($headers);
        // Native v5 returned HTTP200, empty browser/server logs and this REST
        // diagnostic header. Presence refuses even when empty or duplicated;
        // a successful JSON body must not hide the upstream namespace warning.
        self::check(!array_key_exists('x-wp-doingitwrong', $out), 'native HTTP response contains a WordPress diagnostic header');
        return $out;
    }

    private static function bytes(mixed $record): string {
        self::keys($record, ['length', 'base64']);
        self::check(is_int($record['length']) && $record['length'] > 0 && $record['length'] <= 65536 && is_string($record['base64']), 'complete bounded HTTP body present');
        $bytes = base64_decode($record['base64'], true);
        self::check(is_string($bytes) && strlen($bytes) === $record['length'] && base64_encode($bytes) === $record['base64'], 'exact canonical body-byte framing');
        return $bytes;
    }

    private static function identity(string $home, int $formId): void {
        self::check(preg_match('#^http://localhost:([0-9]{4,5})$#D', $home, $match) === 1
            && (int) $match[1] >= 8900 && (int) $match[1] <= 65535 && $formId > 0, 'explicit owned browser origin and form ID');
    }

    private static function keys(mixed $value, array $keys): void {
        self::check(is_array($value) && !array_is_list($value), 'complete typed evidence object');
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        self::check($actual === $keys, 'closed evidence object fields');
    }

    private static function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException('WPForms Builder Save evidence: ' . $message);
    }
}
