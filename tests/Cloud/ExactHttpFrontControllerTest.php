<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CloudHttpRouter;
use Duo\Cloud\ExactHttpFrontController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ExactHttpFrontController.php';

#[CoversNothing]
final class ExactHttpFrontControllerTest extends TestCase {
    private ExactHttpFrontController $front;

    protected function setUp(): void {
        $router = (new \ReflectionClass(CloudHttpRouter::class))->newInstanceWithoutConstructor();
        $this->front = new ExactHttpFrontController($router);
    }

    public function testRejectsMismatchedOrNoncanonicalContentLengthBeforeRouting(): void {
        foreach (['2', '01', '-1', 'not-a-number', '2097153'] as $length) {
            $response = $this->front->handle([
                'CONTENT_LENGTH' => $length,
                'CONTENT_TYPE' => 'application/json',
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/unknown',
            ], '{}');
            self::assertSame(400, $response->status);
            self::assertSame("{\"error\":\"request_refused\"}\n", $response->body);
            self::assertSame('no-store', $response->headers['cache-control']);
        }
        $missing = $this->front->handle([
            'CONTENT_TYPE' => 'application/json',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/unknown',
        ], '{}');
        self::assertSame(400, $missing->status);
    }

    public function testPreservesExactRequestTargetWithoutQueryNormalization(): void {
        $response = $this->front->handle([
            'CONTENT_LENGTH' => '2',
            'CONTENT_TYPE' => 'application/json',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/v1/preview/lifecycle?ignored=1',
        ], '{}');

        self::assertSame(400, $response->status);
        self::assertSame("{\"error\":\"request_refused\"}\n", $response->body);
    }

    public function testUnavailableResponseIsGenericAndSelfLengthBound(): void {
        $response = ExactHttpFrontController::unavailable();

        self::assertSame(503, $response->status);
        self::assertSame("{\"error\":\"service_unavailable\"}\n", $response->body);
        self::assertSame((string) strlen($response->body), $response->headers['content-length']);
        self::assertSame('application/json', $response->headers['content-type']);
    }
}
