<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** Emits value-screened private diagnostics without changing public envelopes. */
final class OperatorDiagnostics {
    public const FORMAT = 'duo-cloud-operator-diagnostic/v1';

    private \Closure $sink;

    /** @param callable(string):void|null $sink */
    public function __construct(?callable $sink = null) {
        $this->sink = $sink === null
            ? static function (string $line): void {
                error_log(rtrim($line, "\n"));
            }
            : \Closure::fromCallable($sink);
    }

    public function record(string $boundary, \Throwable $error): string {
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $boundary) !== 1) {
            $boundary = 'invalid-boundary';
        }
        $correlation = bin2hex(random_bytes(16));
        $class = get_class($error);
        $diagnostic = [
            'boundary' => $boundary,
            'correlation_id' => $correlation,
            'error_class_sha256' => hash(
                'sha256',
                "duo-cloud-operator-error-class/v1\0" . $class
            ),
            'format' => self::FORMAT,
            'reason_sha256' => hash(
                'sha256',
                "duo-cloud-operator-error-reason/v1\0$class\0"
                    . $error->getCode() . "\0" . $error->getMessage()
            ),
        ];
        try {
            ($this->sink)(CanonicalJson::encode($diagnostic) . "\n");
        } catch (\Throwable) {
            // Diagnostics can never turn a fixed public refusal into a new fault.
        }
        return $correlation;
    }
}
