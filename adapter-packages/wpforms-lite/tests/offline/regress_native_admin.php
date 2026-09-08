<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/native-admin.php';

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('AUTH_COOKIE', 'auth');
define('LOGGED_IN_COOKIE', 'logged_in');
$tagHttp = ['home' => 'http://wpftags1.invalid', 'actor' => 'admin', 'cookie_failure' => false,
    'reply' => ['status' => 200, 'headers' => ['x-fixture' => 'one'], 'body' => 'complete reply'], 'calls' => []];
function current_user_can(string $cap): bool { return $cap === 'manage_options' && $GLOBALS['tagHttp']['actor'] === 'admin'; }
function wp_get_current_user(): object { return (object) ['user_login' => $GLOBALS['tagHttp']['actor']]; }
function get_current_user_id(): int { return 12; }
function home_url(): string { return $GLOBALS['tagHttp']['home']; }
function wp_generate_auth_cookie(int $id, int $expires, string $kind, string $token): string {
    if ($GLOBALS['tagHttp']['cookie_failure']) throw new RuntimeException('injected cookie failure');
    return $kind . '-' . $token;
}
function wp_remote_request(string $url, array $args): mixed {
    $GLOBALS['tagHttp']['calls'][] = [$url, $args];
    return $GLOBALS['tagHttp']['reply'];
}
function wp_remote_retrieve_headers(array $reply): array { return $reply['headers']; }
function wp_remote_retrieve_response_code(array $reply): int { return $reply['status']; }
function wp_remote_retrieve_body(array $reply): string { return $reply['body']; }
final class WP_Session_Tokens {
    public static ?self $instance = null;
    public array $tokens = [];
    public bool $destroyFailure = false;
    public static function get_instance(int $id): self { return self::$instance ??= new self(); }
    public function create(int $expires): string { $token = 'session-' . count($this->tokens); $this->tokens[$token] = $expires; return $token; }
    public function destroy(string $token): void { if (!$this->destroyFailure) unset($this->tokens[$token]); }
    public function verify(string $token): bool { return isset($this->tokens[$token]); }
}
// Extend the shared error stub only for APIs this transport actually consumes.
final class WPFormsTransportFailure extends WP_Error {
    public function get_error_messages(string $code): array { return $this->errors[$code]; }
    public function get_all_error_data(string $code): array { return $this->error_data[$code]; }
}
$manager = WP_Session_Tokens::get_instance(12);
$manager->tokens['foreign-session'] = time() + 600;
$session = new WPFormsNativeAdminSession('wpftags', 'source');
$reply = $session->request('/wp-admin/admin.php?page=wpforms-overview', 'GET');
wprism_check_same([$reply], $session->requests, 'actual session retains the complete HTTP reply');
[$url, $args] = $tagHttp['calls'][0];
wprism_check_same('http://wprism-wpftags-wp1-1/wp-admin/admin.php?page=wpforms-overview', $url, 'actual transport uses the owned Compose origin');
wprism_check_same(['method' => 'GET', 'redirection' => 0, 'timeout' => 60,
    'headers' => ['Host' => 'wpftags1.invalid', 'Cookie' => 'auth=auth-session-1; logged_in=logged_in-session-1'],
    'body' => [], 'limit_response_size' => 1048577], $args, 'actual transport binds both native cookies, host and bounded no-redirect request');
foreach ([['/wp-admin/admin.php?page=foreign', 'GET', []], ['/wp-admin/admin-ajax.php', 'DELETE', []],
    ['/wp-admin/admin-ajax.php', 'GET', ['hidden' => 'payload']]] as [$route, $method, $post]) {
    wprism_check_throws(static fn() => $session->request($route, $method, $post), RuntimeException::class, 'closed native request refuses before transport', 'closed WPForms');
}
wprism_check_same(1, count($tagHttp['calls']), 'invalid requests never contact the server');
foreach ([['status' => 302, 'headers' => ['location' => 'foreign'], 'body' => 'redirect reply'],
    ['status' => 200, 'headers' => [], 'body' => str_repeat('x', 1048577)]] as $failure) {
    $tagHttp['reply'] = $failure;
    wprism_check_throws(static fn() => $session->request('/wp-admin/admin-ajax.php', 'POST', ['fixture' => 'body']), RuntimeException::class,
        'non-200 and overflow HTTP replies refuse', 'bounded native HTTP');
    $retained = end($session->requests);
    wprism_check_same($failure, ['status' => $retained['status'], 'headers' => $retained['headers'], 'body' => $retained['body']], 'failure reply bytes are retained before refusal');
}
$error = new WPFormsTransportFailure('first', 'first failure');
$error->errors['second'] = ['second failure', 'second detail'];
$error->error_data = ['first' => [['attempt' => 1]], 'second' => [['attempt' => 2], ['attempt' => 3]]];
$tagHttp['reply'] = $error;
wprism_check_throws(static fn() => $session->request('/wp-admin/admin-ajax.php', 'POST', ['selected' => '701']), RuntimeException::class,
    'actual WP_Error path refuses', 'native HTTP transport');
wprism_check_same(['url' => 'http://wprism-wpftags-wp1-1/wp-admin/admin-ajax.php', 'host' => 'wpftags1.invalid',
    'method' => 'POST', 'post' => ['selected' => '701'], 'errors' => [
        ['code' => 'first', 'messages' => ['first failure'], 'data' => [['attempt' => 1]]],
        ['code' => 'second', 'messages' => ['second failure', 'second detail'], 'data' => [['attempt' => 2], ['attempt' => 3]]],
    ]], $session->transportError, 'every native error code, message, data-history and attempted payload is retained privately');
$manager->destroyFailure = true;
wprism_check_same(false, $session->retire(), 'failed session retirement never reports success');
$manager->destroyFailure = false;
wprism_check($session->retire() && array_keys($manager->tokens) === ['foreign-session'], 'retirement destroys only its own native session');
$tagHttp['cookie_failure'] = true;
wprism_check_throws(static fn() => new WPFormsNativeAdminSession('wpftags', 'source'), RuntimeException::class, 'cookie construction failure propagates', 'injected cookie failure');
wprism_check_same(['foreign-session'], array_keys($manager->tokens), 'constructor failure retires the newly created session');
$tagHttp['cookie_failure'] = false;
foreach ([['wpftags', 'foreign'], ['../foreign', 'source']] as [$pair, $side]) {
    wprism_check_throws(static fn() => new WPFormsNativeAdminSession($pair, $side), RuntimeException::class, 'undeclared native target refuses', 'exact owned native site');
}
$tagHttp['home'] = 'http://foreign.invalid';
wprism_check_throws(static fn() => new WPFormsNativeAdminSession('wpftags', 'source'), RuntimeException::class, 'wrong native home refuses', 'routing and home agree');
$tagHttp['home'] = 'http://wpftags1.invalid';
$tagHttp['actor'] = 'subscriber';
wprism_check_throws(static fn() => new WPFormsNativeAdminSession('wpftags', 'source'), RuntimeException::class, 'wrong native actor refuses', 'administrative actor');
wprism_check_same(['foreign-session'], array_keys($manager->tokens), 'all target preflight refusals precede session creation');
wprism_check_summary('regress_wpforms_lite_native_admin');
