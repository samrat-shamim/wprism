<?php
/**
 * Offline characterization for `wprism verify`'s two oracles (round-3 MUP §2.4;
 * product spec *Release and verify*: "Success requires post-release
 * verification of affected journeys, not only successful commands").
 *
 * The suite pins three things a passing release depends on:
 *
 *  - **Both parts are required.** Convergence proves the bytes landed;
 *    a journey proves a customer-visible page still answers. The spec says
 *    round-trip equality is necessary but not sufficient, so a converged site
 *    with a broken shop page must not verdict `pass`, and neither must a
 *    green shop page over a half-applied tree. Every combination is asserted.
 *  - **An undeclared journey fails, and is disclosed.** A site whose
 *    contract declares nothing gets `journeys: 0 declared` plus the §2.4
 *    sentence naming each surface the release touched. Verification that is
 *    byte-level only says so; otherwise a site accumulates releases whose
 *    only evidence is that WPrism agreed with itself.
 *  - **The oracle grammar refuses what it cannot probe.** `ApplicationContract`
 *    owns the document grammar at accept time; this is the probe grammar, and
 *    it is deliberately stricter — a URL that is not a site-root path or an
 *    absolute http(s) URL, a status outside 100-599, or a duplicate journey id
 *    would each produce a meaningless probe rather than a refusal.
 *
 * The HTTP fetcher is injected, so the whole of this runs with no network.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../../../cli/src/Release/JourneyOracle.php';

use WPrism\Canon;
use WPrism\Orchestrator\ApplicationContract;
use WPrism\Orchestrator\JourneyOracle;

$fixtures = __DIR__ . '/../../fixtures/release';

/** @return array<string,mixed> */
function oracle_fixture(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("fixture is not a JSON document: $path");
    }

    return $decoded;
}

/**
 * A fetcher that answers from a fixed table and records what it was asked
 * for. Anything not in the table is a hard failure rather than a silent
 * default: a fetcher that invented a 200 would let a suite pass against a URL
 * the oracle never actually built.
 *
 * @param array<string,array<string,mixed>> $responses
 */
function oracle_fetcher(array $responses, array &$asked): callable {
    return static function (string $url, int $timeout) use ($responses, &$asked): array {
        $asked[] = ['timeout' => $timeout, 'url' => $url];
        if (!isset($responses[$url])) {
            throw new RuntimeException("the oracle probed an unexpected URL: $url");
        }

        return $responses[$url];
    };
}

$contract = ApplicationContract::withDigest(oracle_fixture("$fixtures/contract-declared-unbound.json"));
$journeys = $contract['declarations']['journeys'];
$base = 'https://shop.example.test';

// ------------------------------------------------------------- the grammar
JourneyOracle::validateJourneys($journeys);
wprism_check(true, 'the contract fixture journeys pass the oracle grammar');
wprism_check_same([], JourneyOracle::run([], $base), 'no declared journeys means no probes');

foreach ([
    'a relative url' => [['id' => 'a', 'url' => 'shop', 'expect_status' => 200, 'expect_contains' => 'x', 'affected_surfaces' => []]],
    'a protocol-relative url' => [['id' => 'a', 'url' => '//evil.test/', 'expect_status' => 200, 'expect_contains' => 'x', 'affected_surfaces' => []]],
    'a non-http scheme' => [['id' => 'a', 'url' => 'file:///etc/passwd', 'expect_status' => 200, 'expect_contains' => 'x', 'affected_surfaces' => []]],
    'a status outside 100-599' => [['id' => 'a', 'url' => '/shop/', 'expect_status' => 7, 'expect_contains' => 'x', 'affected_surfaces' => []]],
    'an empty expect_contains' => [['id' => 'a', 'url' => '/shop/', 'expect_status' => 200, 'expect_contains' => '', 'affected_surfaces' => []]],
    'an empty render_contains' => [['id' => 'a', 'url' => '/shop/', 'expect_status' => 200, 'expect_contains' => 'x', 'render_contains' => '', 'affected_surfaces' => []]],
    'a duplicate journey id' => [
        ['id' => 'a', 'url' => '/shop/', 'expect_status' => 200, 'expect_contains' => 'x', 'affected_surfaces' => []],
        ['id' => 'a', 'url' => '/cart/', 'expect_status' => 200, 'expect_contains' => 'y', 'affected_surfaces' => []],
    ],
] as $what => $bad) {
    wprism_check_refuses(
        static fn () => JourneyOracle::validateJourneys($bad),
        'journey_grammar_invalid',
        "the oracle grammar refuses $what"
    );
}

wprism_check_refuses(
    static fn () => JourneyOracle::run($journeys, ''),
    'journey_base_url_missing',
    'a site-root journey refuses when the environment published no site URL, rather than probing a bare path'
);

// ------------------------------------------------------------- the probes
$asked = [];
$pass = JourneyOracle::run($journeys, $base, oracle_fetcher([
    "$base/shop/" => ['body' => '<h1>Shop</h1><p>Ceramic Mug</p>', 'error' => null, 'status' => 200, 'truncated' => false],
    "$base/product/ceramic-mug/" => ['body' => 'Ceramic Mug — 24.00', 'error' => null, 'status' => 200, 'truncated' => false],
], $asked), 5);
wprism_check_same(2, count($pass), 'each declared journey produces exactly one row');
wprism_check_same(
    ["$base/shop/", "$base/product/ceramic-mug/"],
    array_column($asked, 'url'),
    'the oracle joins the base URL to the declared site-root path'
);
wprism_check_same([5, 5], array_column($asked, 'timeout'), 'the probe timeout reaches the fetcher');
wprism_check_same([true, true], array_column($pass, 'ok'), 'a matching status and substring is a pass');
wprism_check_same([JourneyOracle::PASS, JourneyOracle::PASS], array_column($pass, 'status'), 'the row status word is pass');
wprism_check_same([200, 200], array_column($pass, 'http_status'), 'the observed HTTP status is reported beside the verdict');

// A transactional journey exercises the business boundary the legacy GET
// form cannot: ordered mutation, request metadata, an authenticated session,
// and semantic response fields all have to agree.
$transaction = [[
    'affected_surfaces' => ['orders'],
    'id' => 'authenticated-checkout',
    'steps' => [
        [
            'body' => '{"email":"operator@example.test"}',
            'expect_headers' => ['X-Flow' => 'authenticated'],
            'expect_json' => ['/user/id' => 42],
            'expect_status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'id' => 'login',
            'method' => 'POST',
            'url' => '/api/login',
        ],
        [
            'body' => '{"product":17}',
            'expect_json' => ['/order/status' => 'created', '/order/total' => 24],
            'expect_status' => 201,
            'headers' => ['Content-Type' => 'application/json', 'X-CSRF' => 'reviewed-token'],
            'id' => 'checkout',
            'method' => 'POST',
            'url' => '/api/orders',
        ],
    ],
]];
$transactionContract = oracle_fixture("$fixtures/contract-declared-unbound.json");
$transactionContract['declarations']['journeys'] = $transaction;
ApplicationContract::withDigest($transactionContract);
wprism_check(true, 'the accepted contract grammar carries stateful transactional journeys');

$requests = [];
$transactionRows = JourneyOracle::run(
    $transaction,
    $base,
    static function (string $url, int $timeout, array $request) use (&$requests, $base): array {
        $requests[] = ['request' => $request, 'timeout' => $timeout, 'url' => $url];
        if ($url === "$base/api/login") {
            return [
                'body' => '{"user":{"id":42}}',
                'error' => null,
                'headers' => ['HTTP/1.1 200 OK', 'Set-Cookie: wprism_session=opaque-session; HttpOnly', 'X-Flow: authenticated'],
                'status' => 200,
                'truncated' => false,
            ];
        }

        return [
            'body' => '{"order":{"status":"created","total":24}}',
            'error' => null,
            'headers' => ['HTTP/1.1 201 Created'],
            'status' => 201,
            'truncated' => false,
        ];
    }
);
wprism_check_same(true, $transactionRows[0]['ok'], 'every ordered step and semantic assertion passes the transaction');
wprism_check_same(2, count($transactionRows[0]['steps']), 'the transaction report preserves per-step evidence');
wprism_check_same('POST', $requests[0]['request']['method'], 'the declared HTTP method reaches the probe');
wprism_check_same(
    '{"email":"operator@example.test"}',
    $requests[0]['request']['body'],
    'the declared request body reaches the probe'
);
wprism_check_same(
    ['Content-Type' => 'application/json'],
    $requests[0]['request']['headers'],
    'the declared request headers reach the probe'
);
wprism_check_same(
    ['wprism_session' => 'opaque-session'],
    $requests[1]['request']['cookies'],
    'a response cookie is carried only in memory to the next ordered step'
);

$stoppedRequests = [];
$brokenTransaction = JourneyOracle::run(
    $transaction,
    $base,
    static function (string $url, int $timeout, array $request) use (&$stoppedRequests): array {
        $stoppedRequests[] = $request;

        return [
            'body' => '{"user":{"id":99}}',
            'error' => null,
            'headers' => ['X-Flow: authenticated'],
            'status' => 200,
            'truncated' => false,
        ];
    }
);
wprism_check_same(JourneyOracle::FAIL, $brokenTransaction[0]['status'], 'an exact JSON mismatch fails the transaction');
wprism_check_same(1, count($stoppedRequests), 'a failed step prevents later state-changing requests from running');

$asked = [];
$statusMismatch = JourneyOracle::run([$journeys[0]], $base, oracle_fetcher([
    "$base/shop/" => ['body' => 'Ceramic Mug', 'error' => null, 'status' => 500, 'truncated' => false],
], $asked));
wprism_check_same(false, $statusMismatch[0]['ok'], 'a wrong HTTP status fails the journey');
wprism_check_same(JourneyOracle::FAIL, $statusMismatch[0]['status'], 'a wrong status is a fail, not an error');
wprism_check(
    str_contains((string) $statusMismatch[0]['detail'], 'expected HTTP 200, got 500'),
    'the row says exactly what was expected and what arrived'
);

$asked = [];
$missingText = JourneyOracle::run([$journeys[0]], $base, oracle_fetcher([
    "$base/shop/" => ['body' => '<h1>Shop</h1>', 'error' => null, 'status' => 200, 'truncated' => false],
], $asked));
wprism_check_same(JourneyOracle::FAIL, $missingText[0]['status'], 'a 200 without the expected text is still a failure');

$asked = [];
$unreachable = JourneyOracle::run([$journeys[0]], $base, oracle_fetcher([
    "$base/shop/" => ['body' => '', 'error' => 'the journey URL could not be reached', 'status' => 0, 'truncated' => false],
], $asked));
wprism_check_same(JourneyOracle::ERROR, $unreachable[0]['status'], 'a transport failure is an error, distinct from a fail');
wprism_check_same(false, $unreachable[0]['ok'], 'an error is never ok');

$asked = [];
$truncated = JourneyOracle::run([$journeys[0]], $base, oracle_fetcher([
    "$base/shop/" => ['body' => 'no mug here', 'error' => null, 'status' => 200, 'truncated' => true],
], $asked));
wprism_check_same(
    JourneyOracle::ERROR,
    $truncated[0]['status'],
    'a substring missing from a truncated body is an incomplete check, not a proven absence'
);

// --------------------------------------------------------- render_contains
$rendered = [array_replace($journeys[0], ['render_contains' => 'data-product-id'])];
$asked = [];
$noRenderPath = JourneyOracle::run($rendered, $base, oracle_fetcher([
    "$base/shop/" => ['body' => 'Ceramic Mug', 'error' => null, 'status' => 200, 'truncated' => false],
], $asked));
wprism_check_same(
    JourneyOracle::ERROR,
    $noRenderPath[0]['status'],
    'render_contains with no manifest-declared render path is an error, never a silent pass'
);
wprism_check_same(
    JourneyOracle::RENDER_PATH_UNAVAILABLE,
    $noRenderPath[0]['detail'],
    'the row names the deferral rather than the site'
);

$asked = [];
$withRenderPath = JourneyOracle::run($rendered, $base, oracle_fetcher([
    "$base/shop/" => ['body' => 'Ceramic Mug', 'error' => null, 'status' => 200, 'truncated' => false],
], $asked), JourneyOracle::DEFAULT_TIMEOUT_SECONDS, ['shop-index' => 'templates/archive-product.php']);
wprism_check_same(true, $withRenderPath[0]['ok'], 'a supplied render path lets the journey pass');
wprism_check_same(
    'templates/archive-product.php',
    $withRenderPath[0]['render_path'],
    'the row records which render path was used'
);

// ------------------------------------------------------------ the verdict
$converged = JourneyOracle::convergence(
    ['verifier' => 'canonical-recapture/v1', 'result' => 'pass', 'live_entities' => 213, 'deletions' => 0]
);
wprism_check_same(JourneyOracle::PASS, $converged['status'], 'the agent verifier result maps into the report');
wprism_check_same(213, $converged['entities'], 'the entity count survives the projection');
wprism_check_same(
    JourneyOracle::FAIL,
    JourneyOracle::convergence(null)['status'],
    'a verifier that did not answer is a fail: absence of an error is never the proof'
);
wprism_check_same(
    JourneyOracle::FAIL,
    JourneyOracle::convergence(['verifier' => 'canonical-recapture/v1', 'result' => 'partial'])['status'],
    'anything other than the verifier\'s own pass word is a fail'
);
wprism_check(
    !array_key_exists('mismatched', $converged),
    'the report emits no fabricated mismatch count: the shipped verifier fails closed rather than counting'
);

$failedConvergence = JourneyOracle::convergence(null);
wprism_check_same(JourneyOracle::PASS, JourneyOracle::verdict($converged, $pass), 'both parts green is a pass');
wprism_check_same(
    JourneyOracle::FAIL,
    JourneyOracle::verdict($converged, []),
    'a converged tree with no business journey is not a verified release'
);
wprism_check_same(
    JourneyOracle::FAIL,
    JourneyOracle::verdict($failedConvergence, $pass),
    'green journeys over a non-converged tree is not a pass'
);
wprism_check_same(
    JourneyOracle::FAIL,
    JourneyOracle::verdict($converged, $statusMismatch),
    'a converged tree with a broken journey is not a pass'
);
wprism_check_same(
    JourneyOracle::FAIL,
    JourneyOracle::verdict($converged, $unreachable),
    'a journey the oracle could not complete is not a pass either'
);
wprism_check_same(
    JourneyOracle::FAIL,
    JourneyOracle::verdict([], $pass),
    'a missing convergence block is a fail, not an assumed pass'
);

// -------------------------------------------------------- the disclosures
$scope = ['products', 'pages'];
$report = JourneyOracle::report($converged, $pass, $journeys, $scope, 'production', 'sha256:' . str_repeat('7', 64));
wprism_check_same(JourneyOracle::FAIL, $report['verdict'], 'an uncovered affected surface makes the release verdict fail');
wprism_check_same(['pages'], $report['uncovered_surfaces'], 'a surface with no declared journey is reported as uncovered');
wprism_check_same(
    [JourneyOracle::UNDECLARED_PREFIX . 'pages' . JourneyOracle::UNDECLARED_SUFFIX],
    $report['disclosures'],
    'the uncovered surface gets §2.4\'s sentence naming it'
);

$bare = JourneyOracle::report($converged, [], [], $scope, 'production', null);
wprism_check_same(JourneyOracle::NO_JOURNEYS, $bare['disclosures'][0], 'a site with no declared journey says so first');
wprism_check_same(3, count($bare['disclosures']), 'and then names every surface the release touched');
wprism_check_same(
    JourneyOracle::FAIL,
    $bare['verdict'],
    'no declared journey fails: convergence alone is not a business verification'
);
wprism_check_same($scope, $bare['uncovered_surfaces'], 'every scope surface is uncovered when nothing is declared');

$covered = JourneyOracle::report($converged, $pass, $journeys, ['products'], 'production', null);
wprism_check_same(JourneyOracle::PASS, $covered['verdict'], 'green journeys covering every affected surface pass');

wprism_check_same(
    Canon::encode($report),
    JourneyOracle::encode($report),
    'the verify report is canonical JSON through \WPrism\Canon'
);
wprism_check_same(JourneyOracle::FORMAT, $report['format'], 'the report names its own format');

$lines = JourneyOracle::humanLines($report);
wprism_check(
    str_contains(implode("\n", $lines), JourneyOracle::UNDECLARED_PREFIX . 'pages'),
    'the human view prints the disclosure, not only the JSON view'
);
$many = [];
for ($i = 0; $i < 60; $i++) {
    $many[] = ['detail' => 'ok', 'id' => "j-$i", 'ok' => true, 'status' => JourneyOracle::PASS, 'url' => "/p/$i"];
}
$boundedLines = JourneyOracle::humanLines(
    JourneyOracle::report($converged, $many, [], [], 'production', null),
    50
);
wprism_check(
    in_array('    10 more (use --format=json)', $boundedLines, true),
    'the journey listing is bounded and says how many rows were withheld'
);

wprism_check_summary('regress_verify_oracles');
