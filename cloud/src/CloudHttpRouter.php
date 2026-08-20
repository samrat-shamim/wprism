<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/CloudHttpResponse.php';
require_once __DIR__ . '/ControlAuthority.php';
require_once __DIR__ . '/ControlRefusal.php';
require_once __DIR__ . '/OriginAuthority.php';
require_once __DIR__ . '/OriginControllerGateway.php';
require_once __DIR__ . '/OriginProtocol.php';
require_once __DIR__ . '/PreviewLifecycleGateway.php';

/**
 * Closed, framework-neutral HTTP boundary for Duo Cloud's signed protocols.
 *
 * The caller supplies the already-decoded method, request target, Content-Type,
 * and exact body bytes. No header, query, redirect, path normalization, or
 * framework behavior participates in routing or signature verification.
 */
final class CloudHttpRouter {
    public const CONTROL_PATH = '/v1/preview/control';
    public const LIFECYCLE_PATH = '/v1/preview/lifecycle';
    public const CONTENT_TYPE = 'application/json';

    private const PREVIEW_REQUEST_LIMIT = 1048576;
    private const ORIGIN_CONTROLLER_REQUEST_LIMIT = 1048576;
    private const ORIGIN_REQUEST_LIMIT = 2097152;
    private const REFUSAL_BODY = "{\"error\":\"request_refused\"}\n";
    private ?\Closure $diagnostics;

    public function __construct(
        private PreviewLifecycleGateway $lifecycle,
        private ControlAuthority $control,
        private OriginAuthority $origin,
        private OriginControllerGateway $originController,
        ?callable $diagnostics = null
    ) {
        $this->diagnostics = $diagnostics === null
            ? null
            : \Closure::fromCallable($diagnostics);
    }

    public function handle(
        string $method,
        string $requestTarget,
        ?string $contentType,
        string $body
    ): CloudHttpResponse {
        $route = self::route($requestTarget);
        if ($method !== 'POST' || $contentType !== self::CONTENT_TYPE || $route === null
            || $body === '' || strlen($body) > $route['request_limit']) {
            return self::response(400, self::REFUSAL_BODY);
        }

        try {
            $response = match ($route['authority']) {
                'control' => $this->control->handle($body),
                'lifecycle' => $this->lifecycle->handle($body),
                'origin' => $this->origin->handle($requestTarget, $body),
                'origin-controller' => $this->originController->handle($body),
            };
        } catch (ControlRefusal $error) {
            // ControlRefusal messages are operator diagnostics. Returning one
            // fixed body prevents key, identity, state, and parser oracles.
            $this->diagnose('cloud-http-router-refusal', $error);
            return self::response(403, self::REFUSAL_BODY);
        } catch (\Throwable $error) {
            // A listener may log an uncaught service fault out of band; the
            // public boundary must never serialize exception text or traces.
            $this->diagnose('cloud-http-router-fault', $error);
            return self::response(500, self::REFUSAL_BODY);
        }

        return self::response(200, $response);
    }

    /**
     * @return ?array{authority:'control'|'lifecycle'|'origin'|'origin-controller',request_limit:int}
     */
    private static function route(string $requestTarget): ?array {
        if ($requestTarget === '' || str_contains($requestTarget, '?')
            || str_contains($requestTarget, '#')) {
            return null;
        }
        if ($requestTarget === self::CONTROL_PATH) {
            return ['authority' => 'control', 'request_limit' => self::PREVIEW_REQUEST_LIMIT];
        }
        if ($requestTarget === self::LIFECYCLE_PATH) {
            return ['authority' => 'lifecycle', 'request_limit' => self::PREVIEW_REQUEST_LIMIT];
        }
        if ($requestTarget === OriginControllerGateway::PATH) {
            return [
                'authority' => 'origin-controller',
                'request_limit' => self::ORIGIN_CONTROLLER_REQUEST_LIMIT,
            ];
        }
        if (in_array($requestTarget, self::originPaths(), true)) {
            return ['authority' => 'origin', 'request_limit' => self::ORIGIN_REQUEST_LIMIT];
        }
        return null;
    }

    /** @return list<string> */
    private static function originPaths(): array {
        return [
            OriginProtocol::PAIR_BEGIN_PATH,
            OriginProtocol::PAIR_POLL_PATH,
            OriginProtocol::DEMAND_POLL_PATH,
            OriginProtocol::ANNOUNCE_PATH,
            OriginProtocol::MISSING_PATH,
            OriginProtocol::CHUNK_PATH,
            OriginProtocol::COMMIT_PATH,
            OriginProtocol::ROTATE_PATH,
            OriginProtocol::REVOKE_PATH,
        ];
    }

    private static function response(int $status, string $body): CloudHttpResponse {
        return new CloudHttpResponse($status, [
            'cache-control' => 'no-store',
            'content-length' => (string) strlen($body),
            'content-type' => self::CONTENT_TYPE,
            'x-content-type-options' => 'nosniff',
        ], $body);
    }

    private function diagnose(string $boundary, \Throwable $error): void {
        if ($this->diagnostics === null) {
            return;
        }
        try {
            ($this->diagnostics)($boundary, $error);
        } catch (\Throwable) {
            // The diagnostic sink is outside the signed protocol authority.
        }
    }
}
