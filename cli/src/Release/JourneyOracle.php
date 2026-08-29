<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * The declared affected-journey oracles, and the `wprism-verify-report/v1`
 * document that pairs them with convergence (round-3 MUP §2.4; product spec
 * *Release and verify*: "Success requires post-release verification of
 * affected journeys, not only successful commands").
 *
 * ## Why two independent parts, both required
 *
 * Convergence proves the bytes landed: a fresh re-read shows every entity in
 * the immutable compiled tree matches. That is necessary and it is not
 * sufficient — the spec says so directly ("round-trip equality is necessary
 * but not sufficient"). A journey oracle proves a customer-visible page still
 * answers. `verdict()` therefore requires BOTH: a converged site whose shop
 * page 500s is not a successful release, and a green shop page over a
 * half-applied tree is not one either.
 *
 * ## The three honest absences
 *
 * This class prints what it cannot check, rather than passing quietly:
 *
 *  1. **No journeys declared.** The report carries `NO_JOURNEYS` and, for
 *     every surface the release scope touched, the §2.4 sentence naming that
 *     surface. The verdict is `fail`: product-spec *Release and verify* makes
 *     an affected business journey part of success, so byte convergence alone
 *     cannot authorize the word `pass`.
 *  2. **`render_contains` with no manifest-declared render path.** MUP §2.4
 *     scopes `render_contains` to "a manifest-declared render path", and this
 *     profile ships no render-path declaration (§7). A journey that asks for
 *     it and has no render path supplied is an `error` row, not a pass and
 *     not a silent skip: the operator declared a check this build cannot
 *     perform, and pretending otherwise would be the exact "hollow coverage"
 *     DESIGN.md refuses.
 *  3. **A truncated body.** The fetcher reads at most `MAX_BODY_BYTES`. When
 *     a response is longer, a missing substring is reported as `error` with
 *     the truncation named, because "not found in the first 2 MiB" is not the
 *     same claim as "not present".
 *
 * ## Host-side only
 *
 * MUP §2.4: "Host-side only; no new agent code." The probe is an ordinary
 * HTTP GET through PHP streams with a timeout, and the fetcher is injectable
 * so the whole grammar, the disclosures and the verdict are exercised offline
 * with no network. The base URL is a string argument — the verb boundary
 * resolves it from the environment registry / driver, which is what keeps
 * this class free of a transport dependency.
 *
 * ## What is deliberately not emitted
 *
 * §2.4's illustrative JSON shows `convergence.mismatched`. The shipped
 * verifier (`\WPrism\ConvergenceVerifier`) fails closed on a mismatch rather
 * than counting them — it throws, so a completed run has nothing to count.
 * Emitting `mismatched: 0` would therefore be a fabricated zero. The
 * convergence block carries the verifier's own fields instead: `verifier`,
 * `entities`, `deletions` and a `status` derived from its `result`.
 */
final class JourneyOracle {
    public const FORMAT = 'wprism-verify-report/v1';

    /** Row and verdict vocabulary. */
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const ERROR = 'error';

    /** @var list<string> */
    public const ROW_STATUSES = [self::PASS, self::FAIL, self::ERROR];

    /** @var list<string> */
    public const VERDICTS = [self::PASS, self::FAIL];

    public const DEFAULT_TIMEOUT_SECONDS = 10;

    /** One journey is a transaction, not an unbounded load-test script. */
    public const MAX_STEPS = 20;

    public const MAX_REQUEST_BODY_BYTES = 1048576;

    /** @var list<string> */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** The bound on a probe body. See the class docblock's third absence. */
    public const MAX_BODY_BYTES = 2097152;

    /** MUP §2.4's literal line when the contract declares no journey. */
    public const NO_JOURNEYS = 'journeys: 0 declared';

    /** MUP §2.4's literal per-surface disclosure, split around the surface name. */
    public const UNDECLARED_PREFIX = 'verification is byte-level only for ';
    public const UNDECLARED_SUFFIX =
        '; declare a journey in the contract to make this a business check';

    public const RENDER_PATH_UNAVAILABLE =
        'render_contains is declared but this profile ships no manifest-declared render path for the journey';

    /** The `result` value `\WPrism\ConvergenceVerifier` publishes on success. */
    public const CONVERGENCE_PASS = 'pass';

    /**
     * Refuse a journey list this oracle cannot probe.
     *
     * `ApplicationContract` already owns the DOCUMENT grammar (closed keys,
     * non-empty strings, integer status) at accept time. This is the ORACLE
     * grammar and is deliberately additive: a URL must be probe-able (a
     * site-root path or an absolute http/https URL) and the expected status
     * must be a real HTTP status. A contract can satisfy the first and still
     * declare `url: "shop"` or `expect_status: 7`, which would produce a
     * meaningless probe rather than a refusal.
     *
     * @param list<mixed> $journeys
     */
    public static function validateJourneys(array $journeys): void {
        if (!array_is_list($journeys)) {
            throw self::refuse('journey_grammar_invalid', 'the declared journeys are not a list');
        }
        $seen = [];
        foreach ($journeys as $index => $journey) {
            $where = "journeys[$index]";
            if (!is_array($journey)) {
                throw self::refuse('journey_grammar_invalid', "$where is not an object");
            }
            $id = $journey['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw self::refuse('journey_grammar_invalid', "$where has no id");
            }
            if (isset($seen[$id])) {
                throw self::refuse('journey_grammar_invalid', "two declared journeys share the id '$id'");
            }
            $seen[$id] = true;
            $hasSteps = array_key_exists('steps', $journey);
            $hasSingleRequest = array_key_exists('url', $journey);
            if ($hasSteps === $hasSingleRequest) {
                throw self::refuse('journey_grammar_invalid', "$where must declare exactly one of url or steps");
            }
            if ($hasSteps) {
                $steps = $journey['steps'];
                if (!is_array($steps) || !array_is_list($steps) || $steps === [] || count($steps) > self::MAX_STEPS) {
                    throw self::refuse(
                        'journey_grammar_invalid',
                        "$where.steps must contain between 1 and " . self::MAX_STEPS . ' requests'
                    );
                }
                $stepIds = [];
                foreach ($steps as $stepIndex => $step) {
                    $stepWhere = "$where.steps[$stepIndex]";
                    if (!is_array($step)) {
                        throw self::refuse('journey_grammar_invalid', "$stepWhere is not an object");
                    }
                    $stepId = $step['id'] ?? null;
                    if (!is_string($stepId) || $stepId === '' || isset($stepIds[$stepId])) {
                        throw self::refuse(
                            'journey_grammar_invalid',
                            "$stepWhere.id must be non-empty and unique within the journey"
                        );
                    }
                    $stepIds[$stepId] = true;
                    self::validateRequest($step, $stepWhere);
                }
                continue;
            }
            self::validateRequest($journey, $where);
        }
    }

    /** @param array<string,mixed> $request */
    private static function validateRequest(array $request, string $where): void {
            $url = $request['url'] ?? null;
            if (!is_string($url) || !self::probeableUrl($url)) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where.url must be a site-root path such as /shop/ or an absolute http(s) URL"
                );
            }
            $status = $request['expect_status'] ?? null;
            if (!is_int($status) || $status < 100 || $status > 599) {
                throw self::refuse('journey_grammar_invalid', "$where.expect_status must be an HTTP status 100-599");
            }
            $method = strtoupper((string) ($request['method'] ?? 'GET'));
            if (!in_array($method, self::METHODS, true)) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where.method must be one of " . implode(', ', self::METHODS)
                );
            }
            if (isset($request['body'])
                && (!is_string($request['body']) || strlen($request['body']) > self::MAX_REQUEST_BODY_BYTES)) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where.body must be a string no larger than " . self::MAX_REQUEST_BODY_BYTES . ' bytes'
                );
            }
            foreach (['headers', 'cookies', 'expect_headers'] as $key) {
                if (array_key_exists($key, $request)) {
                    self::validateStringMap($request[$key], "$where.$key");
                }
            }
            if (array_key_exists('expect_json', $request)) {
                if (!is_array($request['expect_json']) || array_is_list($request['expect_json'])) {
                    throw self::refuse('journey_grammar_invalid', "$where.expect_json must be an object");
                }
                foreach ($request['expect_json'] as $pointer => $expected) {
                    if (!is_string($pointer) || ($pointer !== '' && !str_starts_with($pointer, '/'))
                        || (!is_scalar($expected) && $expected !== null)) {
                        throw self::refuse(
                            'journey_grammar_invalid',
                            "$where.expect_json must map JSON Pointers to scalar or null values"
                        );
                    }
                }
            }
            if (array_key_exists('expect_contains', $request)
                && (!is_string($request['expect_contains']) || $request['expect_contains'] === '')) {
                throw self::refuse('journey_grammar_invalid', "$where.expect_contains must be a non-empty string");
            }
            if (!array_key_exists('expect_contains', $request)
                && !array_key_exists('expect_headers', $request)
                && !array_key_exists('expect_json', $request)) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where must declare at least one semantic response expectation"
                );
            }
            if (array_key_exists('render_contains', $request)
                && (!is_string($request['render_contains']) || $request['render_contains'] === '')) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where.render_contains must be a non-empty string when declared"
                );
            }
    }

    /** @param mixed $values */
    private static function validateStringMap($values, string $where): void {
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw self::refuse('journey_grammar_invalid', "$where must be an object");
        }
        foreach ($values as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)
                || str_contains($name, "\r") || str_contains($name, "\n")
                || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where must map non-empty names to single-line string values"
                );
            }
        }
    }

    /**
     * Probe every declared journey.
     *
     * @param list<array<string,mixed>> $journeys the contract's `journeys[]`
     * @param string $baseUrl the target's site URL, resolved by the caller
     *        from the environment registry / driver `describe()`
     * @param ?callable $fetch `fn(string $url, int $timeout, array $request): array`;
     *        null uses this class's own PHP-streams fetcher
     * @param array<string,string> $renderPaths journey id -> manifest-declared
     *        render path, for `render_contains`
     * @return list<array<string,mixed>> rows `{id, url, expect_status,
     *         expect_contains, http_status, status, ok, detail}`
     */
    public static function run(
        array $journeys,
        string $baseUrl,
        ?callable $fetch = null,
        int $timeout = self::DEFAULT_TIMEOUT_SECONDS,
        array $renderPaths = []
    ): array {
        self::validateJourneys($journeys);
        if ($timeout < 1) {
            throw new \InvalidArgumentException('journey probe timeout must be at least one second');
        }
        $fetch ??= static fn (string $url, int $seconds, array $request): array => self::fetch(
            $url,
            $seconds,
            $request
        );

        $rows = [];
        foreach ($journeys as $journey) {
            $id = (string) $journey['id'];
            if (isset($journey['steps']) && is_array($journey['steps'])) {
                $rows[] = self::runTransaction($journey, $baseUrl, $fetch, $timeout, $renderPaths);
                continue;
            }
            $url = self::absolute($baseUrl, (string) $journey['url']);
            $response = $fetch($url, $timeout, self::request($journey, []));
            $rows[] = self::evaluate($journey, $url, self::normalizeResponse($response), $renderPaths);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $journey
     * @param callable $fetch
     * @param array<string,string> $renderPaths
     * @return array<string,mixed>
     */
    private static function runTransaction(
        array $journey,
        string $baseUrl,
        callable $fetch,
        int $timeout,
        array $renderPaths
    ): array {
        $cookies = [];
        $stepRows = [];
        foreach ($journey['steps'] as $step) {
            $url = self::absolute($baseUrl, (string) $step['url']);
            $response = self::normalizeResponse($fetch($url, $timeout, self::request($step, $cookies)));
            self::absorbCookies($cookies, $response['headers']);
            $stepRows[] = self::evaluate($step, $url, $response, $renderPaths);
            if (end($stepRows)['ok'] !== true) {
                break;
            }
        }

        $completed = count($stepRows);
        $declared = count($journey['steps']);
        $last = $completed > 0 ? $stepRows[$completed - 1] : [];
        $ok = $completed === $declared && ($last['ok'] ?? false) === true;
        $status = $ok ? self::PASS : (string) ($last['status'] ?? self::ERROR);
        $detail = $ok
            ? "$completed/$declared ordered steps passed"
            : 'step ' . (string) ($last['id'] ?? 'unknown') . ': ' . (string) ($last['detail'] ?? 'did not run');

        return [
            'detail' => $detail,
            'id' => (string) $journey['id'],
            'ok' => $ok,
            'status' => $status,
            'steps' => $stepRows,
            'url' => (string) ($last['url'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $step
     * @param array<string,string> $sessionCookies
     * @return array{body:string,cookies:array<string,string>,headers:array<string,string>,method:string}
     */
    private static function request(array $step, array $sessionCookies): array {
        $headers = [];
        foreach ((array) ($step['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        $cookies = $sessionCookies;
        foreach ((array) ($step['cookies'] ?? []) as $name => $value) {
            $cookies[(string) $name] = (string) $value;
        }

        return [
            'body' => (string) ($step['body'] ?? ''),
            'cookies' => $cookies,
            'headers' => $headers,
            'method' => strtoupper((string) ($step['method'] ?? 'GET')),
        ];
    }

    /**
     * The §2.4 verdict: both parts, or it is not a pass.
     *
     * @param array<string,mixed> $convergence a normalized convergence block
     * @param list<array<string,mixed>> $journeyRows
     */
    public static function verdict(array $convergence, array $journeyRows): string {
        // Absence of an error is never the proof: the convergence block must
        // positively say `pass`, and a missing block is a fail.
        if (($convergence['status'] ?? null) !== self::PASS) {
            return self::FAIL;
        }
        if ($journeyRows === []) {
            return self::FAIL;
        }
        foreach ($journeyRows as $row) {
            if (!is_array($row) || ($row['ok'] ?? false) !== true) {
                return self::FAIL;
            }
        }

        return self::PASS;
    }

    /**
     * Normalize `wp wprism verify-canonical --format=json` into the report's
     * convergence block.
     *
     * @param ?array<string,mixed> $summary the agent verifier's own document,
     *        or null when the verifier did not run or did not answer
     * @return array<string,mixed>
     */
    public static function convergence(?array $summary): array {
        if ($summary === null) {
            return [
                'deletions' => null,
                'entities' => null,
                'status' => self::FAIL,
                'verifier' => null,
            ];
        }

        return [
            'deletions' => is_int($summary['deletions'] ?? null) ? $summary['deletions'] : null,
            'entities' => is_int($summary['live_entities'] ?? null) ? $summary['live_entities'] : null,
            'status' => ($summary['result'] ?? null) === self::CONVERGENCE_PASS ? self::PASS : self::FAIL,
            'verifier' => is_string($summary['verifier'] ?? null) ? $summary['verifier'] : null,
        ];
    }

    /**
     * The disclosures a report must carry when the contract declared no
     * business check for something this release touched.
     *
     * @param list<array<string,mixed>> $journeys
     * @param list<string> $scopeSurfaces the surfaces the release scope touched
     * @return list<string>
     */
    public static function disclosures(array $journeys, array $scopeSurfaces): array {
        $covered = [];
        foreach ($journeys as $journey) {
            foreach (is_array($journey['affected_surfaces'] ?? null) ? $journey['affected_surfaces'] : [] as $surface) {
                $covered[(string) $surface] = true;
            }
        }
        $lines = [];
        if ($journeys === []) {
            $lines[] = self::NO_JOURNEYS;
        }
        foreach ($scopeSurfaces as $surface) {
            $surface = (string) $surface;
            if (!isset($covered[$surface])) {
                $lines[] = self::UNDECLARED_PREFIX . $surface . self::UNDECLARED_SUFFIX;
            }
        }

        return $lines;
    }

    /**
     * Assemble the `wprism-verify-report/v1` document.
     *
     * @param array<string,mixed> $convergence from convergence()
     * @param list<array<string,mixed>> $journeyRows from run()
     * @param list<array<string,mixed>> $journeys the declarations the rows came from
     * @param list<string> $scopeSurfaces
     * @return array<string,mixed>
     */
    public static function report(
        array $convergence,
        array $journeyRows,
        array $journeys,
        array $scopeSurfaces,
        string $environment,
        ?string $planDigest = null
    ): array {
        $covered = [];
        foreach ($journeys as $journey) {
            foreach (is_array($journey['affected_surfaces'] ?? null) ? $journey['affected_surfaces'] : [] as $surface) {
                $covered[(string) $surface] = true;
            }
        }
        $uncovered = [];
        foreach ($scopeSurfaces as $surface) {
            if (!isset($covered[(string) $surface])) {
                $uncovered[] = (string) $surface;
            }
        }

        $verdict = self::verdict($convergence, $journeyRows);
        // A green row proves only the surfaces that declaration names. A
        // missing declaration, an uncovered affected surface, or a row count
        // that does not correspond exactly to the declarations is incomplete
        // verification, never a successful business outcome.
        if ($journeys === [] || $uncovered !== [] || count($journeyRows) !== count($journeys)) {
            $verdict = self::FAIL;
        }

        return [
            'convergence' => $convergence,
            'disclosures' => self::disclosures($journeys, $scopeSurfaces),
            'environment' => $environment,
            'format' => self::FORMAT,
            'journeys' => array_values($journeyRows),
            'plan_digest' => $planDigest,
            'uncovered_surfaces' => $uncovered,
            'verdict' => $verdict,
        ];
    }

    /** Canonical bytes for `wprism verify --format=json`. */
    public static function encode(array $report): string {
        return Canon::encode($report);
    }

    /**
     * Bounded human projection (MUP §4.6).
     *
     * @param array<string,mixed> $report
     * @return list<string>
     */
    public static function humanLines(array $report, int $limit = 50): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('verify report listing limit must be at least 1');
        }
        /** @var array<string,mixed> $convergence */
        $convergence = is_array($report['convergence'] ?? null) ? $report['convergence'] : [];
        $lines = [
            'verify ' . (string) ($report['environment'] ?? '') . ': ' . (string) ($report['verdict'] ?? ''),
            '  convergence: ' . (string) ($convergence['status'] ?? 'fail')
                . ' (' . (string) ($convergence['entities'] ?? 'no') . ' entities, '
                . (string) ($convergence['deletions'] ?? 'no') . ' deletions)',
        ];
        $rows = is_array($report['journeys'] ?? null) ? array_values($report['journeys']) : [];
        $lines[] = '  journeys: ' . count($rows);
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '    ' . (string) ($row['id'] ?? '') . '  ' . (string) ($row['status'] ?? '')
                . '  ' . (string) ($row['url'] ?? '') . '  ' . (string) ($row['detail'] ?? '');
        }
        if (count($rows) > $limit) {
            $lines[] = '    ' . (count($rows) - $limit) . ' more (use --format=json)';
        }
        foreach (is_array($report['disclosures'] ?? null) ? $report['disclosures'] : [] as $disclosure) {
            $lines[] = '  ' . (string) $disclosure;
        }

        return $lines;
    }

    /**
     * The default probe: one bounded request through PHP streams. Redirects
     * are NOT followed — a journey that 301s is a
     * changed journey and the operator should see the 301.
     *
     * @param array{body?:string,cookies?:array<string,string>,headers?:array<string,string>,method?:string} $request
     * @return array{status:int,body:string,error:?string,truncated:bool,headers:list<string>}
     */
    public static function fetch(string $url, int $timeout, array $request = []): array {
        $method = (string) ($request['method'] ?? 'GET');
        $headers = [
            'Accept: text/html, application/json',
            'User-Agent: wprism-verify/1',
        ];
        foreach ((array) ($request['headers'] ?? []) as $name => $value) {
            $headers[] = (string) $name . ': ' . (string) $value;
        }
        $cookies = [];
        foreach ((array) ($request['cookies'] ?? []) as $name => $value) {
            $cookies[] = (string) $name . '=' . (string) $value;
        }
        if ($cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', $cookies);
        }
        $context = stream_context_create(['http' => [
            'content' => (string) ($request['body'] ?? ''),
            'follow_location' => 0,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'method' => $method,
            'timeout' => $timeout,
        ]]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return [
                'body' => '',
                'error' => 'the journey URL could not be reached',
                'headers' => [],
                'status' => 0,
                'truncated' => false,
            ];
        }
        try {
            $body = (string) stream_get_contents($handle, self::MAX_BODY_BYTES);
            $truncated = strlen($body) >= self::MAX_BODY_BYTES;
            $meta = stream_get_meta_data($handle);
            $status = 0;
            $responseHeaders = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            foreach ($responseHeaders as $header) {
                if (is_string($header) && preg_match('~^HTTP/[\d.]+\s+(\d{3})~', $header, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'body' => $body,
            'error' => null,
            'headers' => array_values(array_filter($responseHeaders, 'is_string')),
            'status' => $status,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string,mixed> $journey
     * @param array{status:int,body:string,error:?string,truncated:bool,headers:list<string>} $response
     * @param array<string,string> $renderPaths
     * @return array<string,mixed>
     */
    private static function evaluate(array $journey, string $url, array $response, array $renderPaths): array {
        $id = (string) $journey['id'];
        $expectStatus = (int) $journey['expect_status'];
        $expectContains = isset($journey['expect_contains']) ? (string) $journey['expect_contains'] : null;
        $row = [
            'detail' => '',
            'expect_contains' => $expectContains,
            'expect_status' => $expectStatus,
            'http_status' => $response['status'],
            'id' => $id,
            'ok' => false,
            'status' => self::FAIL,
            'url' => $url,
        ];

        if ($response['error'] !== null) {
            $row['detail'] = (string) $response['error'];
            $row['status'] = self::ERROR;

            return $row;
        }
        if ($response['status'] !== $expectStatus) {
            $row['detail'] = "expected HTTP $expectStatus, got {$response['status']}";

            return $row;
        }
        if ($expectContains !== null && !str_contains($response['body'], $expectContains)) {
            // A truncated body cannot distinguish "absent" from "beyond the
            // bound", so it is an error rather than a failure: the operator
            // is told the check did not complete, not that the site is wrong.
            $row['status'] = $response['truncated'] ? self::ERROR : self::FAIL;
            $row['detail'] = $response['truncated']
                ? 'the expected text was not found in the first ' . self::MAX_BODY_BYTES
                    . ' bytes and the response was longer'
                : 'the expected text is not in the response';

            return $row;
        }
        $headers = self::responseHeaderMap($response['headers']);
        foreach ((array) ($journey['expect_headers'] ?? []) as $name => $expected) {
            $actual = $headers[strtolower((string) $name)] ?? null;
            if ($actual !== (string) $expected) {
                $row['detail'] = "expected response header $name to equal " . (string) $expected;

                return $row;
            }
        }
        if (array_key_exists('expect_json', $journey)) {
            try {
                $document = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $row['detail'] = 'the response is not valid JSON: ' . $e->getMessage();

                return $row;
            }
            foreach ((array) $journey['expect_json'] as $pointer => $expected) {
                [$found, $actual] = self::jsonPointer($document, (string) $pointer);
                if (!$found || $actual !== $expected) {
                    $row['detail'] = "JSON Pointer $pointer did not equal the declared value";

                    return $row;
                }
            }
        }
        if (array_key_exists('render_contains', $journey)) {
            if (!isset($renderPaths[$id])) {
                $row['detail'] = self::RENDER_PATH_UNAVAILABLE;
                $row['status'] = self::ERROR;

                return $row;
            }
            $row['render_path'] = $renderPaths[$id];
        }

        $row['detail'] = "HTTP $expectStatus and every declared response expectation were observed";
        $row['ok'] = true;
        $row['status'] = self::PASS;

        return $row;
    }

    /**
     * @param mixed $response
     * @return array{status:int,body:string,error:?string,truncated:bool,headers:list<string>}
     */
    private static function normalizeResponse($response): array {
        if (!is_array($response) || !is_int($response['status'] ?? null)) {
            throw new \InvalidArgumentException(
                'a journey fetcher must return {status:int, body:string, error:?string, truncated:bool}'
            );
        }

        return [
            'body' => is_string($response['body'] ?? null) ? $response['body'] : '',
            'error' => is_string($response['error'] ?? null) ? $response['error'] : null,
            'headers' => is_array($response['headers'] ?? null)
                ? array_values(array_filter($response['headers'], 'is_string'))
                : [],
            'status' => $response['status'],
            'truncated' => ($response['truncated'] ?? false) === true,
        ];
    }

    /**
     * @param array<string,string> $cookies
     * @param list<string> $headers
     */
    private static function absorbCookies(array &$cookies, array $headers): void {
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $header, $match) !== 1) {
                continue;
            }
            if ($match[2] === '') {
                unset($cookies[$match[1]]);
            } else {
                $cookies[$match[1]] = $match[2];
            }
        }
    }

    /** @param list<string> $headers @return array<string,string> */
    private static function responseHeaderMap(array $headers): array {
        $mapped = [];
        foreach ($headers as $header) {
            $colon = strpos($header, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($header, 0, $colon)));
            $value = trim(substr($header, $colon + 1));
            $mapped[$name] = isset($mapped[$name]) ? $mapped[$name] . ', ' . $value : $value;
        }

        return $mapped;
    }

    /** @return array{bool,mixed} */
    private static function jsonPointer(mixed $document, string $pointer): array {
        if ($pointer === '') {
            return [true, $document];
        }
        $current = $document;
        foreach (explode('/', substr($pointer, 1)) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (!is_array($current) || !array_key_exists($token, $current)) {
                return [false, null];
            }
            $current = $current[$token];
        }

        return [true, $current];
    }

    private static function probeableUrl(string $url): bool {
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        return preg_match('~^https?://[^\s/@]+~i', $url) === 1;
    }

    private static function absolute(string $baseUrl, string $url): string {
        if (!str_starts_with($url, '/')) {
            return $url;
        }
        if ($baseUrl === '') {
            throw self::refuse(
                'journey_base_url_missing',
                'a declared journey uses a site-root path and this environment published no site URL'
            );
        }

        return rtrim($baseUrl, '/') . $url;
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'correct the contract declarations.journeys[] entry, accept the reviewed contract, then run wprism verify again'
        );
    }
}
