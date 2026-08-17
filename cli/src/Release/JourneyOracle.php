<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * The declared affected-journey oracles, and the `duo-verify-report/v1`
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
 *     surface. `verdict` may still be `pass` — the contract declared no
 *     business check, so there is none to fail — but the report says out
 *     loud that verification was byte-level only. Silence here would let a
 *     site accumulate releases whose only evidence is that Duo agreed with
 *     itself.
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
 * verifier (`\Duo\ConvergenceVerifier`) fails closed on a mismatch rather
 * than counting them — it throws, so a completed run has nothing to count.
 * Emitting `mismatched: 0` would therefore be a fabricated zero. The
 * convergence block carries the verifier's own fields instead: `verifier`,
 * `entities`, `deletions` and a `status` derived from its `result`.
 */
final class JourneyOracle {
    public const FORMAT = 'duo-verify-report/v1';

    /** Row and verdict vocabulary. */
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const ERROR = 'error';

    /** @var list<string> */
    public const ROW_STATUSES = [self::PASS, self::FAIL, self::ERROR];

    /** @var list<string> */
    public const VERDICTS = [self::PASS, self::FAIL];

    public const DEFAULT_TIMEOUT_SECONDS = 10;

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

    /** The `result` value `\Duo\ConvergenceVerifier` publishes on success. */
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
            $url = $journey['url'] ?? null;
            if (!is_string($url) || !self::probeableUrl($url)) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where.url must be a site-root path such as /shop/ or an absolute http(s) URL"
                );
            }
            $status = $journey['expect_status'] ?? null;
            if (!is_int($status) || $status < 100 || $status > 599) {
                throw self::refuse('journey_grammar_invalid', "$where.expect_status must be an HTTP status 100-599");
            }
            $contains = $journey['expect_contains'] ?? null;
            if (!is_string($contains) || $contains === '') {
                throw self::refuse('journey_grammar_invalid', "$where.expect_contains must be a non-empty string");
            }
            if (array_key_exists('render_contains', $journey)
                && (!is_string($journey['render_contains']) || $journey['render_contains'] === '')) {
                throw self::refuse(
                    'journey_grammar_invalid',
                    "$where.render_contains must be a non-empty string when declared"
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
     * @param ?callable $fetch `fn(string $url, int $timeout): array{status:int,body:string,error:?string,truncated:bool}`;
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
        $fetch ??= static fn (string $url, int $seconds): array => self::fetch($url, $seconds);

        $rows = [];
        foreach ($journeys as $journey) {
            $id = (string) $journey['id'];
            $url = self::absolute($baseUrl, (string) $journey['url']);
            $response = $fetch($url, $timeout);
            $rows[] = self::evaluate($journey, $url, self::normalizeResponse($response), $renderPaths);
        }

        return $rows;
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
        foreach ($journeyRows as $row) {
            if (!is_array($row) || ($row['ok'] ?? false) !== true) {
                return self::FAIL;
            }
        }

        return self::PASS;
    }

    /**
     * Normalize `wp duo verify-canonical --format=json` into the report's
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
     * Assemble the `duo-verify-report/v1` document.
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

        return [
            'convergence' => $convergence,
            'disclosures' => self::disclosures($journeys, $scopeSurfaces),
            'environment' => $environment,
            'format' => self::FORMAT,
            'journeys' => array_values($journeyRows),
            'plan_digest' => $planDigest,
            'uncovered_surfaces' => $uncovered,
            'verdict' => self::verdict($convergence, $journeyRows),
        ];
    }

    /** Canonical bytes for `duo verify --format=json`. */
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
     * The default probe: one GET through PHP streams, with a timeout and a
     * bounded read. Redirects are NOT followed — a journey that 301s is a
     * changed journey and the operator should see the 301.
     *
     * @return array{status:int,body:string,error:?string,truncated:bool}
     */
    public static function fetch(string $url, int $timeout): array {
        $context = stream_context_create(['http' => [
            'follow_location' => 0,
            'header' => "Accept: text/html\r\nUser-Agent: duo-verify/1\r\n",
            'ignore_errors' => true,
            'method' => 'GET',
            'timeout' => $timeout,
        ]]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return ['body' => '', 'error' => 'the journey URL could not be reached', 'status' => 0, 'truncated' => false];
        }
        try {
            $body = (string) stream_get_contents($handle, self::MAX_BODY_BYTES);
            $truncated = strlen($body) >= self::MAX_BODY_BYTES;
            $meta = stream_get_meta_data($handle);
            $status = 0;
            foreach (is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [] as $header) {
                if (is_string($header) && preg_match('~^HTTP/[\d.]+\s+(\d{3})~', $header, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return ['body' => $body, 'error' => null, 'status' => $status, 'truncated' => $truncated];
    }

    /**
     * @param array<string,mixed> $journey
     * @param array{status:int,body:string,error:?string,truncated:bool} $response
     * @param array<string,string> $renderPaths
     * @return array<string,mixed>
     */
    private static function evaluate(array $journey, string $url, array $response, array $renderPaths): array {
        $id = (string) $journey['id'];
        $expectStatus = (int) $journey['expect_status'];
        $expectContains = (string) $journey['expect_contains'];
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
        if (!str_contains($response['body'], $expectContains)) {
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
        if (array_key_exists('render_contains', $journey)) {
            if (!isset($renderPaths[$id])) {
                $row['detail'] = self::RENDER_PATH_UNAVAILABLE;
                $row['status'] = self::ERROR;

                return $row;
            }
            $row['render_path'] = $renderPaths[$id];
        }

        $row['detail'] = "HTTP $expectStatus and the expected text were both observed";
        $row['ok'] = true;
        $row['status'] = self::PASS;

        return $row;
    }

    /**
     * @param mixed $response
     * @return array{status:int,body:string,error:?string,truncated:bool}
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
            'status' => $response['status'],
            'truncated' => ($response['truncated'] ?? false) === true,
        ];
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
            'correct the contract declarations.journeys[] entry, accept the reviewed contract, then run duo verify again'
        );
    }
}
