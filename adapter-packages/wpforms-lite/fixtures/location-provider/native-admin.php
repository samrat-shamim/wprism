<?php
declare(strict_types=1);

/** Capsule-local native admin transport; callers own every route, payload and response contract. */
final class WPFormsNativeAdminSession {
    public array $requests = [];
    public ?array $transportError = null;
    private object $sessions;
    private string $token;
    private string $cookies;
    private string $origin;
    private string $host;

    public function __construct(string $pair, string $side) {
        self::check(preg_match('/^[a-z][a-z0-9]+$/D', $pair) === 1
            && in_array($side, ['source', 'target'], true), 'exact owned native site');
        self::check(current_user_can('manage_options') && wp_get_current_user()->user_login === 'admin', 'native administrative actor');
        self::check(defined('WP_DEBUG') && defined('WP_DEBUG_LOG') && defined('WP_DEBUG_DISPLAY')
            && [WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY] === [true, true, false], 'native server diagnostics enabled');
        $number = $side === 'source' ? 1 : 2;
        $this->host = $pair . $number . '.invalid';
        $this->origin = 'http://wprism-' . $pair . '-wp' . $number . '-1';
        self::check(home_url() === 'http://' . $this->host, 'native HTTP routing and home agree');
        $this->sessions = WP_Session_Tokens::get_instance(get_current_user_id());
        $expires = time() + 300;
        $this->token = $this->sessions->create($expires);
        try {
            $this->cookies = AUTH_COOKIE . '=' . wp_generate_auth_cookie(get_current_user_id(), $expires, 'auth', $this->token)
                . '; ' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie(get_current_user_id(), $expires, 'logged_in', $this->token);
        } catch (Throwable $failure) {
            $this->retire();
            throw $failure;
        }
    }

    public function request(string $route, string $method, array $post = []): array {
        self::check(in_array($route, ['/wp-admin/admin.php?page=wpforms-settings&view=general',
            '/wp-admin/admin.php?page=wpforms-settings&view=validation', '/wp-admin/admin.php?page=wpforms-overview',
            '/wp-admin/admin-ajax.php'], true) && in_array($method, ['GET', 'POST'], true)
            && ($method !== 'GET' || $post === []), 'closed WPForms admin route and method');
        $url = $this->origin . $route;
        $reply = wp_remote_request($url, ['method' => $method, 'redirection' => 0, 'timeout' => 60,
            'headers' => ['Host' => $this->host, 'Cookie' => $this->cookies], 'body' => $post, 'limit_response_size' => 1048577]);
        if (is_wp_error($reply)) {
            $errors = [];
            foreach ($reply->get_error_codes() as $code) $errors[] = ['code' => $code,
                'messages' => $reply->get_error_messages($code), 'data' => $reply->get_all_error_data($code)];
            $this->transportError = ['url' => $url, 'host' => $this->host, 'method' => $method, 'post' => $post, 'errors' => $errors];
        }
        self::check(!is_wp_error($reply), 'native HTTP transport succeeded');
        $headers = wp_remote_retrieve_headers($reply);
        $record = ['url' => $url, 'host' => $this->host, 'method' => $method, 'post' => $post,
            'status' => wp_remote_retrieve_response_code($reply),
            'headers' => is_array($headers) ? $headers : $headers->getAll(), 'body' => wp_remote_retrieve_body($reply)];
        // Retain the complete exchanged reply before any status/body assertion.
        $this->requests[] = $record;
        self::check($record['status'] === 200 && is_string($record['body']) && strlen($record['body']) <= 1048576,
            'bounded native HTTP 200 response');
        return $record;
    }

    public function retire(): bool {
        $this->sessions->destroy($this->token);
        return !$this->sessions->verify($this->token);
    }

    public static function diagnostics(): array {
        $path = WP_CONTENT_DIR . '/debug.log';
        clearstatcache(true, $path);
        if (!file_exists($path)) return ['present' => false, 'bytes' => ''];
        self::check(is_file($path) && !is_link($path) && filesize($path) <= 1048576, 'bounded native server diagnostic file');
        $bytes = file_get_contents($path);
        self::check(is_string($bytes), 'native server diagnostic read');
        return ['present' => true, 'bytes' => $bytes];
    }

    private static function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException('WPForms native admin fixture: ' . $message);
    }
}
