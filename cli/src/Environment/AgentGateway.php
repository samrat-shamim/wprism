<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__) . '/EnvironmentDriver.php';
require_once dirname(__DIR__) . '/HostContracts/TransportResult.php';
require_once dirname(__DIR__) . '/HostContracts/TargetInvocation.php';

/**
 * Host-side boundary for the existing `wp duo …` protocol.
 *
 * The gateway deliberately does not introduce an envelope or reinterpret an
 * agent decision. It owns only target argv construction, process result
 * typing, and the one strict JSON decode used by host workflows. This keeps
 * transport selection and protocol parsing out of command presenters while
 * preserving the current wire bytes.
 */
final class AgentGateway
{
    public function __construct(private readonly TargetInvocation $driver) {}

    public function driver(): TargetInvocation
    {
        return $this->driver;
    }

    /** @param list<string> $arguments */
    public function command(string $verb, array $arguments = []): array
    {
        self::assertVerb($verb);
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('agent command arguments must be strings without NUL bytes');
            }
        }
        return array_merge(['duo', $verb, '--repo=' . $this->driver->repoPath()], $arguments);
    }

    /** @param list<string> $arguments */
    public function capture(string $verb, array $arguments = []): TransportResult
    {
        return TransportResult::fromArray($this->driver->captureWp($this->command($verb, $arguments)));
    }

    /** @param list<string> $arguments */
    public function stream(string $verb, array $arguments = []): int
    {
        return $this->driver->streamWp($this->command($verb, $arguments));
    }

    /**
     * Run an already-composed control command through the same typed process
     * boundary. This is for internal agent verbs (`db`, lifecycle, and
     * recovery) whose argv is intentionally not a public `duo` command.
     *
     * @param list<string> $arguments
     */
    public function captureArgs(array $arguments): TransportResult
    {
        self::assertArguments($arguments);
        return TransportResult::fromArray($this->driver->captureWp($arguments));
    }

    /** @param list<string> $arguments */
    public function streamArgs(array $arguments): int
    {
        self::assertArguments($arguments);
        return $this->driver->streamWp($arguments);
    }

    /**
     * Run one target-side raw control script through the same typed boundary
     * as WordPress-agent invocations. Raw control is retained for the
     * existing bootstrap/adoption protocol; it is not interpreted here.
     */
    public function captureRaw(string $script): TransportResult
    {
        if (str_contains($script, "\0")) {
            throw new \InvalidArgumentException('raw control scripts must not contain NUL bytes');
        }
        return TransportResult::fromArray($this->driver->captureRaw($script));
    }

    /** Render an argv-native target command for an interactive handoff. */
    public function instruction(array $arguments): string
    {
        self::assertArguments($arguments);
        return $this->driver->wpInstruction($arguments);
    }

    /**
     * Decode the current agent JSON value without converting malformed output
     * or a non-zero target exit into success. Refusal envelopes remain intact
     * for callers that need to render the agent's existing fields.
     *
     * @return array<string,mixed>
     */
    public static function decodeJson(TransportResult $result, string $command): array
    {
        $bytes = trim($result->stdout);
        if ($bytes === '') {
            throw new \RuntimeException("agent command '$command' returned empty JSON output");
        }
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException("agent command '$command' returned malformed JSON", 0, $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("agent command '$command' returned a non-object JSON value");
        }
        return $value;
    }

    /** @return ?string */
    public static function observedVersion(array $response): ?string
    {
        foreach (['agent_version', 'version'] as $key) {
            $value = $response[$key] ?? null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1) {
                return $value;
            }
        }
        return null;
    }

    private static function assertVerb(string $verb): void
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $verb) !== 1) {
            throw new \InvalidArgumentException('agent command verb is outside the closed host grammar');
        }
    }

    /** @param list<string> $arguments */
    private static function assertArguments(array $arguments): void
    {
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('agent command arguments must be strings without NUL bytes');
            }
        }
    }
}
