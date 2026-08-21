<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CloudHttpResponse.php';
require_once dirname(__DIR__) . '/src/CloudHttpRouter.php';

/** Exact PHP-SAPI adapter; all protocol semantics remain in CloudHttpRouter. */
final class ExactHttpFrontController {
    private const INPUT_LIMIT = 2097152;
    private const REFUSAL_BODY = "{\"error\":\"request_refused\"}\n";
    private const UNAVAILABLE_BODY = "{\"error\":\"service_unavailable\"}\n";

    public function __construct(private CloudHttpRouter $router) {}

    /** @param array<string,mixed> $server */
    public function handle(array $server, string $body): CloudHttpResponse {
        $method = $server['REQUEST_METHOD'] ?? null;
        $target = $server['REQUEST_URI'] ?? null;
        $contentType = $server['CONTENT_TYPE'] ?? null;
        $contentLength = $server['CONTENT_LENGTH'] ?? null;
        if (!is_string($method) || !is_string($target)
            || $contentType !== null && !is_string($contentType)
            || strlen($body) > self::INPUT_LIMIT
            || !$this->contentLengthMatches($contentLength, strlen($body))) {
            return self::response(400, self::REFUSAL_BODY);
        }
        return $this->router->handle($method, $target, $contentType, $body);
    }

    public function serve(): void {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        $expectedLength = self::canonicalContentLength($contentLength);
        if ($expectedLength === null) {
            self::emit(self::response(400, self::REFUSAL_BODY));
            return;
        }
        $handle = @fopen('php://input', 'rb');
        if (!is_resource($handle)) {
            self::emit(self::unavailable());
            return;
        }
        $body = stream_get_contents($handle, $expectedLength + 1);
        $closed = fclose($handle);
        if (!is_string($body) || !$closed || strlen($body) !== $expectedLength) {
            self::emit(self::response(400, self::REFUSAL_BODY));
            return;
        }
        /** @var array<string,mixed> $server */
        $server = $_SERVER;
        self::emit($this->handle($server, $body));
    }

    public static function unavailable(): CloudHttpResponse {
        return self::response(503, self::UNAVAILABLE_BODY);
    }

    public static function emit(CloudHttpResponse $response): void {
        if (headers_sent()) {
            return;
        }
        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $response->body;
    }

    private function contentLengthMatches(mixed $value, int $actual): bool {
        return self::canonicalContentLength($value) === $actual;
    }

    private static function canonicalContentLength(mixed $value): ?int {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,7})\z/D', $value) !== 1) {
            return null;
        }
        $length = (int) $value;
        return $length <= self::INPUT_LIMIT ? $length : null;
    }

    private static function response(int $status, string $body): CloudHttpResponse {
        return new CloudHttpResponse($status, [
            'cache-control' => 'no-store',
            'content-length' => (string) strlen($body),
            'content-type' => CloudHttpRouter::CONTENT_TYPE,
            'x-content-type-options' => 'nosniff',
        ], $body);
    }
}
