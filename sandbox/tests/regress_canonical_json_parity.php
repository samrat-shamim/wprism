<?php
/**
 * WP-9 duplication conformance: the canonical-JSON, refusal-envelope, plan-row
 * and predicate families that MUST agree byte-for-byte, and the ones that
 * deliberately do not.
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * "Canonical JSON" is implemented at least eleven times in this tree and
 * `duo-command-refusal/v1` is produced at three sites and parsed at three
 * more. Every one of those is a wire/hash format under AGENTS.md §9 ("keep
 * byte-identical"), and until now each was pinned only through whatever
 * end-to-end suite happened to exercise its caller. A refactor that "unifies
 * the canonicalizers" therefore had no single place to fail. This suite is
 * that place: it drives every implementation over one shared vector set and
 * asserts identity where identity is the contract — and asserts the exact
 * DIFFERENCE where the difference is the contract, so a well-meaning
 * unification cannot silently change a hash basis or a stored document.
 *
 * Every golden byte string lives in sandbox/tests/fixtures/parity/, so an
 * implementation drifting AND its twin drifting with it still fails.
 *
 * THE FAMILIES
 * ------------
 * (Line numbers below are navigation aids captured while writing this suite.
 * Every pin is anchored on CONTENT — a class/method call, a fixture golden,
 * or a literal searched for in the token stream — so an unrelated edit that
 * moves a fragment does not fail anything here, and moving a fragment's
 * BYTES still does.)
 *
 * F1 — canonical pretty repository/file bytes.
 *   JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE over a
 *   recursively ksort(SORT_STRING)ed structure, plus exactly one trailing "\n".
 *   REFERENCE: \Duo\Canon::encode() (agent/src/Kernel/Canon.php).
 *   Members, all required byte-identical to the reference:
 *     - \Duo\ScopedApplySession::canonical_encode()  agent/src/Scope/ScopedApplySession.php:1660
 *     - \Duo\ScopedApplySession::canonical_copy()    …:1664 (decode∘encode round trip)
 *     - \Duo\ScopedApplySession::digest()            …:1656 (sha256 of the reference bytes)
 *     - \Duo\AdapterCertification::bundlePretty()    agent/src/Adapter/AdapterCertification.php:523
 *     - \Duo\AdapterCertification::canonicalHash()   …:572 (sha256 of the reference bytes)
 *
 * F2 — canonical compact hashing/wire bytes.
 *   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE over the same recursive
 *   ksort, no pretty-printing, no trailing newline.
 *   REFERENCE: \Duo\Orchestrator\EnvironmentLifecycleCanon::encode()
 *   (cli/src/Environment/EnvironmentLifecycle.php:2207) — chosen because it is the widest
 *   domain (public, `mixed`, no value-class restriction), so it can encode
 *   every vector the narrower members accept.
 *   Members:
 *     - \Duo\Orchestrator\BootstrapEligibilityReport::canonicalJson()  cli/src/Onboarding/BootstrapEligibility.php:480
 *     - \Duo\Orchestrator\DriverCapabilityReport::canonicalJson()      cli/src/Transport/EnvironmentDriver.php:249
 *     - \Duo\Orchestrator\ClassificationBatch::queueHash()             cli/src/Onboarding/ClassificationBatch.php:48 (sha256 of the reference bytes)
 *     - \Duo\Recovery\CanonicalJson::encode()                          recovery/CanonicalJson.php:47, on its narrowed domain
 *     - \Duo\AdapterCertification::bundleDigest()                      agent/src/Adapter/AdapterCertification.php:1041 (sha256 of reference bytes . "\n")
 *
 * F3 — the `duo-command-refusal/v1` envelope.
 *   Producers: \Duo\Cli::halt_json_failure() (agent/src/Command/Cli.php:28),
 *   \Duo\Orchestrator\CommandOutput::renderRefusalJson() (cli/src/Command/CommandOutput.php:44),
 *   \Duo\Orchestrator\AdapterObservation::refusal() (cli/src/Adapter/AdapterObservation.php:576).
 *   Parsers: cli/src/Command/StatusCommand.php:42, cli/src/Command/PendingCommand.php:17,
 *   cli/src/Onboarding/Init.php:159 — all three the same `is_array() && format === …` test.
 *
 * F4 — the plan-row human label.
 *   REFERENCE: \Duo\PlanView::humanLabel() (agent/src/Review/PlanView.php:444).
 *   Members: \Duo\Orchestrator\PlanView::humanLabel() (cli/src/Plan/PlanView.php:676),
 *   \Duo\Orchestrator\PlanSummary::label() (cli/src/Plan/PlanSummary.php:590).
 *
 * F5 — cli/src/Plan/PlanSummary.php's five "keep lockstep with agent/src/Command/Cli.php"
 *   comments (lines 211, 280, 388, 406, 598). See the section body for the
 *   agent fragment each one names and how it is pinned.
 *
 * F6 — the version-range predicate: \Duo\Deploy::in_range()
 *   (agent/src/Promotion/Deploy.php:819) vs \Duo\Orchestrator\Doctor::in_range()
 *   (cli/src/Onboarding/Doctor.php:289), an intentional independent copy.
 *
 * F7 — the relative-path safety predicate: \Duo\PathSafety::safe_relative()
 *   (agent/src/Kernel/PathSafety.php:68) vs its one verbatim copy,
 *   \Duo\CodeCompatibility::safe_relative() (agent/src/Code/CodeCompatibility.php:806).
 *
 * DELIBERATELY NOT PINNED AS EQUAL (asserted as DIFFERENT, so a unification
 * has to come here and say so on purpose)
 * -------------------------------------------------------------------------
 *  1. \Duo\PromotionSessionJournal::canonical() (agent/src/Promotion/PromotionSessionJournal.php:250)
 *     is a hand-rolled compact serializer that omits JSON_UNESCAPED_UNICODE
 *     and runs object KEYS through a bare json_encode() (so a key's "/" comes
 *     back "\/"). It is an equality basis for self::same() only — never
 *     persisted, never hashed — so it is pinned as byte-equal to F2 on ASCII,
 *     slash-free-key vectors and pinned as DIFFERENT on the unicode and
 *     slash-key vectors.
 *  2. \Duo\Orchestrator\AdapterCatalog::encode() (cli/src/Adapter/AdapterCatalog.php:981),
 *     ::inline() (…:969) and \Duo\Orchestrator\ManifestValidate::encode()
 *     (cli/src/Adapter/ManifestValidate.php:710) pretty-print or compact WITHOUT any
 *     key sort: their own docblocks state that declared/engine key order is
 *     load-bearing in the refusal messages that print the same sets. They are
 *     pinned as F1/F2 minus the sort (equal on pre-sorted input, different on
 *     unsorted input) and minus F1's trailing newline.
 *  3. \Duo\Orchestrator\ClassificationBatch::encode() (cli/src/Onboarding/ClassificationBatch.php:56)
 *     is F1's flags plus the trailing newline but no normalize() — the batch
 *     document's key order is built by ::build(). Pinned the same way.
 *  4. \Duo\Recovery\CanonicalJson::encode() narrows the domain: floats,
 *     objects and resources are refused outright and object keys must be
 *     strings (so a PHP array whose keys the language coerced to int is
 *     refused too). Those refusals are pinned as refusals.
 *  5. Every cli-side canonicalize()/normalize() descends only into PHP
 *     ARRAYS; \Duo\Canon::normalize() also sorts \stdClass properties. The
 *     `stdclass-vs-array` and `empty-array-vs-empty-object` vectors pin that
 *     split (it is the same PHP-type-vs-JSON-shape asymmetry Canon's own
 *     post_hash_basis() docblock documents).
 *  6. agent/src/Command/Cli.php:1038-1043's UNFILTERED plan line renderer collapses
 *     /\s+/ but does NOT strip C0/DEL — its own comment says the unfiltered
 *     renderer stays byte-compatible with its established behaviour, and the
 *     C0/DEL strip lives in the FILTERED path (PlanView::humanLabel). F4
 *     pins the three humanLabel/label copies against each other and pins the
 *     absence of the C0/DEL literal from agent/src/Command/Cli.php as source text.
 *  7. The host-side producers have no sensitivity screen: given a
 *     secret-looking tuple, CommandOutput::renderRefusalJson() and
 *     AdapterObservation::refusal() publish it verbatim while the agent
 *     redacts. Their call sites pass engine-authored constants (or an
 *     already-screened agent envelope), so this is posture, not a defect —
 *     but it is pinned so that stops being an accident.
 *
 * RECORDED GAP, DELIBERATELY NOT ASSERTED
 * ---------------------------------------
 * cli/src/Adapter/AdapterObservation.php:588 encodes its envelope WITHOUT
 * JSON_INVALID_UTF8_SUBSTITUTE, which both other producers set. Given a
 * message carrying invalid UTF-8, json_encode() returns false and the site
 * echoes a bare "\n" — no envelope, no fallback — while agent/src/Command/Cli.php and
 * cli/src/Command/CommandOutput.php both substitute and, failing that, emit their
 * constant fallback document (both of which ARE pinned below). All four
 * self::refusal() call sites in that file pass constant ASCII, so nothing
 * reaches it today; the gap is recorded here rather than asserted, because
 * pinning the current behaviour would cement it.
 *
 * NOT MECHANICALLY PINNED (no callable entry; recorded here as the migration
 * target)
 * -------------------------------------------------------------------------
 *  - agent/src/Promotion/PromotionSessionJournal.php:175 — `json_encode($payload,
 *    JSON_UNESCAPED_SLASHES)` is an inline expression inside write(), which
 *    needs a durable journal directory; only ::canonical() below it is
 *    reachable without one.
 *  - agent/src/Adapter/AdapterCertification.php:527 — bundlePretty()'s sibling
 *    four-space pretty asset writer is reached only through a signed bundle
 *    import; bundlePretty() itself IS pinned and is the byte authority.
 *  - The 20 open-coded relative-path checks that \Duo\PathSafety should
 *    eventually own. They are not callable uniformly (most are inline `if`
 *    guards inside larger validators), so this suite pins PathSafety's own
 *    vectors and its one verbatim copy, and records the migration target:
 *
 *      grep -rn "'\.\.'" agent/src cli/src recovery | grep -v PathSafety.php
 *
 *    agent/src/Repository/RepositoryMediaCatalog.php:65      agent/src/Publication/PublicationJournal.php:2553
 *    agent/src/Code/CodeCompatibility.php:812          agent/src/Review/RefreshExport.php:429
 *    agent/src/Adapter/AdapterSources.php:1284            agent/src/Init/InitCodeBaseline.php:300
 *    agent/src/Adapter/AdapterSources.php:2686            agent/src/Init/InitCodeInventory.php:141
 *    agent/src/Adapter/ScopedCertificationBundle.php:171  agent/src/Adapter/AdapterCertification.php:439
 *    agent/src/Adapter/ScopedCertificationBundle.php:717  agent/src/Adapter/AdapterContractGrammar.php:62
 *    agent/src/Adapter/ScopedCertificationBundle.php:826  agent/src/Code/CodeDescriptorCompiler.php:115
 *    agent/src/Adapter/ActionProviderGrammar.php:670      cli/src/Refresh/RefreshPlan.php:1082
 *    agent/src/Publication/PublicationJournal.php:1124        recovery/UploadBundle.php:620
 *    agent/src/Publication/PublicationJournal.php:1163        recovery/EffectBundle.php:561
 *
 *    Two of them are known-narrower on purpose and must NOT be migrated
 *    blindly: cli/src/Refresh/RefreshPlan.php:1082 accepts "\\" and C0 bytes that
 *    PathSafety::safe_relative() refuses, and agent/src/Adapter/AdapterSources.php:2686
 *    additionally bounds depth and requires a ".php" tail.
 *
 * REGENERATING THE GOLDENS
 * ------------------------
 * There is deliberately no generator script to keep in sync: every golden in
 * sandbox/tests/fixtures/parity/ was captured from the same entry point this
 * suite drives, and duo_check_same() prints both sides on failure. A
 * DELIBERATE format change is therefore "read the reported `actual:` and
 * paste it into the fixture" — which forces whoever makes it to look at the
 * exact bytes they are changing, one implementation at a time.
 *
 * MECHANICS
 * ---------
 * Private/protected statics are reached with \Closure::bind() — NOT
 * ReflectionMethod::setAccessible(), which is deprecated as of PHP 8.5 (the
 * local interpreter) and would print a "Deprecated:" line that
 * sandbox/tests/offline_diagnostics_guard.sh refuses. Nothing here writes to
 * the filesystem, opens a socket, or needs WordPress; the minimal WP_CLI stub
 * below is declared BEFORE agent/src/Command/Cli.php is required because that file's
 * last statement is WP_CLI::add_command('duo', Cli::class).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';

/**
 * The whole WP-CLI surface agent/src/Command/Cli.php touches at require time and
 * inside halt_json_failure(): line() collects, halt() unwinds without
 * exiting, add_command() absorbs the file's last statement. __callStatic()
 * swallows the rest so a future renderer call cannot fatal this suite.
 */
final class WP_CLI {
    /** @var list<string> */
    public static array $lines = [];

    public static function line(string $message = ''): void {
        self::$lines[] = $message;
    }

    public static function warning(string $message): void {
        self::$lines[] = $message;
    }

    public static function success(string $message): void {
        self::$lines[] = $message;
    }

    public static function error(string $message, bool $exit = true): void {
        self::$lines[] = $message;
    }

    /** @return never */
    public static function halt(int $code): void {
        throw new DuoParityHalt('WP_CLI::halt(' . $code . ')');
    }

    public static function add_command(string $name, mixed $handler, array $args = []): void {
    }

    /** @param list<mixed> $arguments */
    public static function __callStatic(string $name, array $arguments): void {
    }
}

/** Distinguishes the stub's halt() from a genuine failure in the code under test. */
final class DuoParityHalt extends RuntimeException {
}

$duoRoot = dirname(__DIR__, 2);

require_once $duoRoot . '/agent/src/Kernel/Canon.php';
require_once $duoRoot . '/agent/src/Kernel/OrderPreserved.php';
require_once $duoRoot . '/agent/src/Command/Cli.php';
require_once $duoRoot . '/agent/src/Review/PlanView.php';
require_once $duoRoot . '/agent/src/Kernel/PathSafety.php';
require_once $duoRoot . '/agent/src/Code/CodeCompatibility.php';
require_once $duoRoot . '/agent/src/Promotion/Deploy.php';
require_once $duoRoot . '/agent/src/Scope/ScopedApplySession.php';
require_once $duoRoot . '/agent/src/Promotion/PromotionSessionJournal.php';
require_once $duoRoot . '/agent/src/Adapter/AdapterCertification.php';
require_once $duoRoot . '/recovery/CanonicalJson.php';
require_once $duoRoot . '/cli/src/Environment/EnvironmentLifecycle.php';
require_once $duoRoot . '/cli/src/Onboarding/BootstrapEligibility.php';
require_once $duoRoot . '/cli/src/Transport/EnvironmentDriver.php';
require_once $duoRoot . '/cli/src/Onboarding/ClassificationBatch.php';
require_once $duoRoot . '/cli/src/Adapter/AdapterCatalog.php';
require_once $duoRoot . '/cli/src/Adapter/ManifestValidate.php';
require_once $duoRoot . '/cli/src/Command/CommandOutput.php';
require_once $duoRoot . '/cli/src/Adapter/AdapterObservation.php';
require_once $duoRoot . '/cli/src/Plan/PlanSummary.php';
require_once $duoRoot . '/cli/src/Plan/PlanView.php';
require_once $duoRoot . '/cli/src/Onboarding/Doctor.php';
require_once $duoRoot . '/cli/src/Command/StatusCommand.php';
require_once $duoRoot . '/cli/src/Command/PendingCommand.php';

use Duo\AdapterCertification;
use Duo\Canon;
use Duo\Cli;
use Duo\CodeCompatibility;
use Duo\CommandRefusalException;
use Duo\Deploy;
use Duo\OrderPreserved;
use Duo\PathSafety;
use Duo\PromotionSessionJournal;
use Duo\ScopedApplySession;
use Duo\Orchestrator\AdapterCatalog;
use Duo\Orchestrator\AdapterObservation;
use Duo\Orchestrator\BootstrapEligibilityReport;
use Duo\Orchestrator\ClassificationBatch;
use Duo\Orchestrator\CommandOutput;
use Duo\Orchestrator\Doctor;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\EnvironmentLifecycleCanon;
use Duo\Orchestrator\ManifestValidate;
use Duo\Orchestrator\PendingCommand;
use Duo\Orchestrator\PlanContract;
use Duo\Orchestrator\PlanSummary;
use Duo\Orchestrator\StatusCommand;
use Duo\Recovery\CanonicalJson;

// ---------------------------------------------------------------------------
// fixtures and shared helpers
// ---------------------------------------------------------------------------

/**
 * @return array<string,mixed>
 */
function parity_fixture(string $name): array {
    $path = __DIR__ . '/fixtures/parity/' . $name . '.json';
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        fwrite(STDERR, "FAIL: cannot read parity fixture $path\n");
        exit(1);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "FAIL: parity fixture $path is not a JSON object\n");
        exit(1);
    }
    return $decoded;
}

/**
 * Hydrate the fixture's two-marker value grammar into live PHP values:
 * `{"$op": v}` becomes \Duo\OrderPreserved, `{"$obj": {…}}` becomes
 * \stdClass. Everything else is the plain json_decode(assoc) shape, which is
 * exactly what Apply::load_tree() hands Canon in production.
 */
function parity_hydrate(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (count($value) === 1 && array_key_exists('$op', $value)) {
        return new OrderPreserved(parity_hydrate($value['$op']));
    }
    if (count($value) === 1 && array_key_exists('$obj', $value)) {
        $object = new stdClass();
        foreach ((array) $value['$obj'] as $key => $child) {
            $object->$key = parity_hydrate($child);
        }
        return $object;
    }
    $out = [];
    foreach ($value as $key => $child) {
        $out[$key] = parity_hydrate($child);
    }
    return $out;
}

/** True when the hydrated value contains a \stdClass at any depth. */
function parity_has_object(mixed $value): bool {
    if (is_object($value)) {
        return true;
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $child) {
        if (parity_has_object($child)) {
            return true;
        }
    }
    return false;
}

/**
 * Every single-quoted string literal in $file, decoded.
 *
 * Used only for presence/absence pins on fragments that no offline entry
 * point can reach. Single-quoted only: a fragment rewritten into a
 * double-quoted or interpolated string is a change to the rendering code and
 * should come through this suite deliberately.
 *
 * @return list<string>
 */
function parity_literals(string $file): array {
    $out = [];
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $text = $token[1];
        if ($text === '' || $text[0] !== "'") {
            continue;
        }
        $out[] = str_replace(['\\\\', "\\'"], ['\\', "'"], substr($text, 1, -1));
    }
    return $out;
}

/**
 * The source text of the first `WP_CLI::line(…)` argument in $file that
 * contains $needle, with the outer parentheses stripped.
 *
 * agent/src/Command/Cli.php's plan() renderer is not callable offline — it needs a
 * repository, a Policy, a $wpdb and an Apply run before it reaches a single
 * line. Its LINE-BUILDING EXPRESSIONS are pure string concatenation over one
 * $r row, though, so lifting the expression out of the shipped file and
 * evaluating it against the same fixture row exercises the agent's own bytes
 * rather than a copy of them. Anchored on a literal inside the expression,
 * not on a line number.
 */
function parity_wp_cli_line_expression(string $file, string $needle): string {
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][1] !== 'WP_CLI') {
            continue;
        }
        $open = $i + 3;
        if (($tokens[$i + 2][1] ?? null) !== 'line' || ($tokens[$open] ?? null) !== '(') {
            continue;
        }
        $depth = 0;
        $text = '';
        for ($j = $open; $j < $count; $j++) {
            $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if ($piece === '(') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($piece === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $text .= $piece;
        }
        if (str_contains($text, $needle)) {
            return trim($text);
        }
    }
    return '';
}

/**
 * Evaluate one lifted renderer expression against $row.
 *
 * eval() is confined to text this suite just read out of the shipped tree and
 * matched against a known fragment; the expressions concerned are pure `.`
 * concatenation of `$r[...] ?? '…'` reads and strtoupper(), so evaluating one
 * has no effect beyond producing the line the agent would have printed.
 * sandbox/tests/regress_plan_contract_trust.php uses the same idiom to reach
 * cli/duo's functions without running its main().
 */
function parity_eval_row_expression(string $expression, array $row): string {
    if ($expression === '') {
        return '(expression not found)';
    }
    $render = static function (array $r) use ($expression): string {
        return (string) eval('return ' . $expression . ';');
    };
    return $render($row);
}

/** Describe a Throwable as "Class: message" for a refusal-shape assertion. */
function parity_thrown(callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return get_class($e);
    }
    return '(nothing thrown)';
}

// Private/protected statics, reached read-only through \Closure::bind().
$canonicalEncode = \Closure::bind(
    static fn(mixed $v): string => ScopedApplySession::canonical_encode($v),
    null,
    ScopedApplySession::class
);
$canonicalCopy = \Closure::bind(
    static fn(mixed $v): mixed => ScopedApplySession::canonical_copy($v),
    null,
    ScopedApplySession::class
);
$sessionDigest = \Closure::bind(
    static fn(array $v): string => ScopedApplySession::digest($v),
    null,
    ScopedApplySession::class
);
$bundlePretty = \Closure::bind(
    static fn(mixed $v): string => AdapterCertification::bundlePretty($v),
    null,
    AdapterCertification::class
);
$certCanonicalHash = \Closure::bind(
    static fn(mixed $v): string => AdapterCertification::canonicalHash($v),
    null,
    AdapterCertification::class
);
$bundleDigest = \Closure::bind(
    static fn(array $v): string => AdapterCertification::bundleDigest($v),
    null,
    AdapterCertification::class
);
$bootstrapCanonical = \Closure::bind(
    static fn(array $v): string => BootstrapEligibilityReport::canonicalJson($v),
    null,
    BootstrapEligibilityReport::class
);
$driverCanonical = \Closure::bind(
    static fn(array $v): string => DriverCapabilityReport::canonicalJson($v),
    null,
    DriverCapabilityReport::class
);
$catalogEncode = \Closure::bind(
    static fn(array $v): string => AdapterCatalog::encode($v),
    null,
    AdapterCatalog::class
);
$catalogInline = \Closure::bind(
    static fn(mixed $v): string => AdapterCatalog::inline($v),
    null,
    AdapterCatalog::class
);
$manifestValidateEncode = \Closure::bind(
    static fn(array $v): string => ManifestValidate::encode($v),
    null,
    ManifestValidate::class
);
$journalCanonical = \Closure::bind(
    static fn(mixed $v): string => PromotionSessionJournal::canonical($v),
    null,
    PromotionSessionJournal::class
);
$agentRefusal = \Closure::bind(
    static function (CommandRefusalException $e, string $command): string {
        WP_CLI::$lines = [];
        try {
            Cli::halt_json_failure($e, ['format' => 'json'], $command);
        } catch (DuoParityHalt $halt) {
            // halt_json_failure() always ends in WP_CLI::halt(1).
        }
        return WP_CLI::$lines[0] ?? '';
    },
    null,
    Cli::class
);
$observationRefusal = \Closure::bind(
    static function (string $reason, string $message, string $remediation): string {
        ob_start();
        AdapterObservation::refusal(true, $reason, $message, $remediation);
        return rtrim((string) ob_get_clean(), "\n");
    },
    null,
    AdapterObservation::class
);
$cliPlanViewLabel = \Closure::bind(
    static fn(array $row): string => \Duo\Orchestrator\PlanView::humanLabel($row),
    null,
    \Duo\Orchestrator\PlanView::class
);
$planSummaryLabel = \Closure::bind(
    static fn(array $row): string => PlanSummary::label($row),
    null,
    PlanSummary::class
);
$doctorInRange = \Closure::bind(
    static fn(string $installed, string $min, string $max): bool => Doctor::in_range($installed, $min, $max),
    null,
    Doctor::class
);
$codeCompatibilitySafeRelative = \Closure::bind(
    static fn(string $path): bool => CodeCompatibility::safe_relative($path),
    null,
    CodeCompatibility::class
);

// ---------------------------------------------------------------------------
// F1 — canonical pretty repository/file bytes
// ---------------------------------------------------------------------------

$canonicalVectors = parity_fixture('canonical-json-vectors');
duo_check_same(
    'duo-parity-canonical-json/v1',
    $canonicalVectors['format'] ?? null,
    'the canonical-JSON vector fixture declares its versioned format'
);
duo_check(count($canonicalVectors['vectors']) >= 12, 'the fixture carries at least twelve canonical-JSON vectors');

foreach ($canonicalVectors['vectors'] as $vector) {
    $name = (string) $vector['name'];
    $value = parity_hydrate($vector['value']);
    $golden = (string) $vector['canon'];

    duo_check_same($golden, Canon::encode($value), "F1 reference Canon::encode is byte-stable [$name]");
    duo_check_same($golden, $canonicalEncode($value), "F1 ScopedApplySession::canonical_encode [$name]");
    duo_check_same($golden, $bundlePretty($value), "F1 AdapterCertification::bundlePretty [$name]");
    duo_check_same(
        hash('sha256', $golden),
        $certCanonicalHash($value),
        "F1 AdapterCertification::canonicalHash hashes exactly the reference bytes [$name]"
    );
    duo_check_same(
        Canon::decode($golden),
        $canonicalCopy($value),
        "F1 ScopedApplySession::canonical_copy round-trips through the reference bytes [$name]"
    );
    if (is_array($value)) {
        duo_check_same(
            hash('sha256', $golden),
            $sessionDigest($value),
            "F1 ScopedApplySession::digest hashes exactly the reference bytes [$name]"
        );
    }
    duo_check(str_ends_with($golden, "\n"), "F1 bytes end with exactly one newline [$name]");
    duo_check(!str_ends_with($golden, "\n\n"), "F1 bytes end with exactly one newline, not two [$name]");
}

// ---------------------------------------------------------------------------
// F2 — canonical compact hashing/wire bytes
// ---------------------------------------------------------------------------

foreach ($canonicalVectors['vectors'] as $vector) {
    $name = (string) $vector['name'];
    $value = parity_hydrate($vector['value']);
    if (!($vector['f2'] ?? false)) {
        duo_check(
            isset($vector['f2_skip']),
            "F2 does not apply to [$name] and the fixture says why"
        );
        continue;
    }
    $compact = (string) $vector['compact'];

    duo_check_same($compact, EnvironmentLifecycleCanon::encode($value), "F2 reference EnvironmentLifecycleCanon::encode is byte-stable [$name]");
    duo_check(!str_contains($compact, "\n"), "F2 bytes are one line with no trailing newline [$name]");

    if (is_array($value)) {
        duo_check_same($compact, $bootstrapCanonical($value), "F2 BootstrapEligibilityReport::canonicalJson [$name]");
        duo_check_same($compact, $driverCanonical($value), "F2 DriverCapabilityReport::canonicalJson [$name]");
        duo_check_same(
            hash('sha256', $compact),
            ClassificationBatch::queueHash($value),
            "F2 ClassificationBatch::queueHash hashes exactly the reference bytes [$name]"
        );
    }

    // AdapterCertification::bundleDigest() is F2 bytes plus one newline, but
    // it normalizes through Canon::normalize(), which also sorts stdClass
    // properties — so the two agree only where no object is present.
    if (is_array($value) && !parity_has_object($value)) {
        duo_check_same(
            hash('sha256', $compact . "\n"),
            $bundleDigest($value),
            "F2 AdapterCertification::bundleDigest hashes the reference bytes plus one newline [$name]"
        );
    }

    // recovery/CanonicalJson::encode() on its narrowed domain.
    $recovery = (string) ($vector['recovery'] ?? 'ok');
    if ($recovery === 'ok') {
        duo_check_same($compact, CanonicalJson::encode($value), "F2 recovery CanonicalJson::encode [$name]");
        duo_check_same(
            json_decode($compact, true),
            CanonicalJson::decode($compact . "\n", 'vector', 'duo parity'),
            "F2 recovery CanonicalJson::decode accepts its own bytes plus one newline [$name]"
        );
        duo_check_same(
            'RuntimeException',
            parity_thrown(static fn() => CanonicalJson::decode($compact, 'vector', 'duo parity')),
            "F2 recovery CanonicalJson::decode refuses the same bytes WITHOUT the trailing newline [$name]"
        );
    } elseif ($recovery === 'refuses') {
        duo_check_same(
            'RuntimeException',
            parity_thrown(static fn() => CanonicalJson::encode($value)),
            "F2 recovery CanonicalJson::encode refuses its out-of-domain vector [$name]"
        );
    } else {
        duo_check_same(
            'TypeError',
            parity_thrown(static fn() => CanonicalJson::encode($value)),
            "F2 recovery CanonicalJson::encode rejects a non-array root [$name]"
        );
    }
}

// The one recovery refusal worth naming: a PHP array whose keys the language
// coerced to int is not a canonical JSON object to the recovery codec, even
// though every other family encodes it happily.
duo_check_same(
    'RuntimeException',
    parity_thrown(static fn() => CanonicalJson::encode(['10' => 'ten', '2' => 'two'])),
    'F2 recovery CanonicalJson refuses integer-coerced object keys that F1 and the cli sites accept'
);

// ---------------------------------------------------------------------------
// F1 <-> F2 — same normalization, two renderings
// ---------------------------------------------------------------------------

foreach ($canonicalVectors['vectors'] as $vector) {
    if (!($vector['f2'] ?? false)) {
        continue;
    }
    $name = (string) $vector['name'];
    duo_check_json_equal(
        (string) $vector['canon'],
        (string) $vector['compact'],
        "F1 and F2 decode to the same structure [$name]"
    );
    $reencoded = json_encode(
        json_decode((string) $vector['compact'], true),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . "\n";
    if ($vector['reencode'] ?? false) {
        duo_check_same(
            (string) $vector['canon'],
            $reencoded,
            "F2 bytes re-pretty-printed are F1 bytes — the two differ only in whitespace [$name]"
        );
    } else {
        duo_check(
            $reencoded !== (string) $vector['canon'],
            "F2 bytes do NOT survive a PHP decode/re-encode round trip, and the fixture says so [$name]"
        );
    }
}

// The two reasons a canonical document can stop being byte-stable across a
// PHP round trip, both pinned so a "just re-encode it" refactor cannot land
// quietly: JSON -0 decodes to the integer 0, and an empty JSON object decodes
// to a PHP array, which re-encodes as [].
duo_check_same('{"n":-0}', EnvironmentLifecycleCanon::encode(['n' => -0.0]), 'a negative zero encodes as -0');
duo_check_same('{"n":0}', EnvironmentLifecycleCanon::encode(json_decode('{"n":-0}', true)), 'and decodes back as the integer 0');

// The documented split: a cli-side canonicalize() descends only into PHP
// arrays, so a \stdClass keeps its authored property order while Canon sorts
// it. Pinned as a DIFFERENCE, not as a defect.
$objectVector = new stdClass();
$objectVector->zeta = 1;
$objectVector->alpha = 2;
duo_check_same(
    '{"zeta":1,"alpha":2}',
    EnvironmentLifecycleCanon::encode($objectVector),
    'F2 leaves stdClass property order exactly as authored'
);
duo_check_same(
    "{\n    \"alpha\": 2,\n    \"zeta\": 1\n}\n",
    Canon::encode($objectVector),
    'F1 sorts stdClass properties — the deliberate F1/F2 split for objects'
);

// The empty-{} vs empty-[] asymmetry Canon::post_hash_basis()'s own docblock
// names as the reason it round-trips through encode()/decode() first.
$emptyObject = new stdClass();
duo_check_same("{}\n", Canon::encode($emptyObject), 'F1 renders an empty stdClass as {}');
duo_check_same("[]\n", Canon::encode([]), 'F1 renders an empty PHP array as [] — array_is_list() calls it a list');
duo_check_same(
    [],
    Canon::decode(Canon::encode($emptyObject)),
    'F1 decode() collapses {} back to a PHP array, which is why a hash basis must round-trip first'
);

// ---------------------------------------------------------------------------
// Deliberate divergence 1 — PromotionSessionJournal::canonical()
// ---------------------------------------------------------------------------

duo_check_same(
    EnvironmentLifecycleCanon::encode(['b' => 1, 'a' => ['d' => 2, 'c' => [3, 4]]]),
    $journalCanonical(['b' => 1, 'a' => ['d' => 2, 'c' => [3, 4]]]),
    'PromotionSessionJournal::canonical equals F2 on ASCII, slash-free-key values'
);
duo_check_same(
    '{"emoji":"\\ud83d\\ude80"}',
    $journalCanonical(['emoji' => '🚀']),
    'PromotionSessionJournal::canonical escapes unicode — it omits JSON_UNESCAPED_UNICODE'
);
duo_check_same(
    '{"emoji":"🚀"}',
    EnvironmentLifecycleCanon::encode(['emoji' => '🚀']),
    'F2 does not escape unicode — the pinned difference from the journal basis'
);
duo_check_same(
    '{"a\\/b":"x/y"}',
    $journalCanonical(['a/b' => 'x/y']),
    'PromotionSessionJournal::canonical escapes a slash in a KEY but not in a value — its keys go through a bare json_encode()'
);
duo_check_same(
    '{"a/b":"x/y"}',
    EnvironmentLifecycleCanon::encode(['a/b' => 'x/y']),
    'F2 leaves slashes raw in keys and values alike'
);

// ---------------------------------------------------------------------------
// Deliberate divergence 2 — the unsorted pretty/compact reporters
// ---------------------------------------------------------------------------

$unsorted = ['zeta' => 1, 'alpha' => ['nested_z' => true, 'nested_a' => 2]];
$presorted = ['alpha' => ['nested_a' => 2, 'nested_z' => true], 'zeta' => 1];

duo_check_same(
    rtrim(Canon::encode($presorted), "\n"),
    $catalogEncode($presorted),
    'AdapterCatalog::encode is F1 minus the trailing newline when the document is already in canonical order'
);
duo_check_same(
    rtrim(Canon::encode($presorted), "\n"),
    $manifestValidateEncode($presorted),
    'ManifestValidate::encode is F1 minus the trailing newline when the document is already in canonical order'
);
duo_check(
    $catalogEncode($unsorted) !== rtrim(Canon::encode($unsorted), "\n"),
    'AdapterCatalog::encode does NOT sort — engine key order is load-bearing in its own refusal messages'
);
duo_check(
    $manifestValidateEncode($unsorted) !== rtrim(Canon::encode($unsorted), "\n"),
    'ManifestValidate::encode does NOT sort — declared key order is load-bearing in its own refusal messages'
);
duo_check_same(
    EnvironmentLifecycleCanon::encode($presorted),
    $catalogInline($presorted),
    'AdapterCatalog::inline is F2 when the value is already in canonical order'
);
duo_check(
    $catalogInline($unsorted) !== EnvironmentLifecycleCanon::encode($unsorted),
    'AdapterCatalog::inline does NOT sort either'
);
duo_check_same(
    Canon::encode($presorted),
    ClassificationBatch::encode($presorted),
    'ClassificationBatch::encode is F1 including the trailing newline when the batch is already in canonical order'
);
duo_check(
    ClassificationBatch::encode($unsorted) !== Canon::encode($unsorted),
    'ClassificationBatch::encode does NOT sort — ::build() owns the batch document key order'
);

// ---------------------------------------------------------------------------
// F3 — the duo-command-refusal/v1 envelope
// ---------------------------------------------------------------------------

$refusalVectors = parity_fixture('refusal-envelope-vectors');
duo_check_same(
    'duo-parity-refusal-envelope/v1',
    $refusalVectors['format'] ?? null,
    'the refusal-envelope vector fixture declares its versioned format'
);
duo_check(count($refusalVectors['tuples']) >= 6, 'the fixture carries at least six refusal tuples');

foreach ($refusalVectors['tuples'] as $tuple) {
    $name = (string) $tuple['name'];
    $command = (string) $tuple['command'];
    $reasonCode = (string) $tuple['reason_code'];
    $message = (string) $tuple['message'];
    $remediation = (string) $tuple['remediation'];
    $diagnostics = (array) $tuple['diagnostics'];
    $redacted = (bool) $tuple['expect_redacted'];

    $exception = new CommandRefusalException($reasonCode, $message, $remediation, $diagnostics);
    $agentBytes = $agentRefusal($exception, $command);

    ob_start();
    $hostExit = CommandOutput::renderRefusalJson($command, $reasonCode, $message, $remediation, $diagnostics);
    $hostBytes = (string) ob_get_clean();

    duo_check_same((string) $tuple['agent'], $agentBytes, "F3 agent envelope is byte-stable [$name]");
    duo_check_same((string) $tuple['host'] . "\n", $hostBytes, "F3 host envelope is byte-stable [$name]");
    duo_check_same(1, $hostExit, "F3 the host envelope reports the failure exit code [$name]");
    duo_check(!str_contains($agentBytes, "\n"), "F3 the agent envelope is exactly one line [$name]");

    $decoded = json_decode($agentBytes, true);
    duo_check_same('duo-command-refusal/v1', $decoded['format'] ?? null, "F3 the agent envelope names the versioned format [$name]");
    duo_check_same(false, $decoded['ok'] ?? null, "F3 ok is the JSON literal false [$name]");
    duo_check_same($command, $decoded['command'] ?? null, "F3 the envelope carries the command [$name]");
    duo_check_same($reasonCode, $decoded['error'] ?? null, "F3 error keeps the reason code [$name]");
    duo_check_same($reasonCode, $decoded['reason_code'] ?? null, "F3 reason_code keeps the reason code [$name]");
    duo_check_same(
        ['format', 'ok', 'command', 'error', 'reason_code', 'message', 'remediation'],
        array_slice(array_keys((array) $decoded), 0, 7),
        "F3 the seven leading envelope keys keep their exact order [$name]"
    );

    if ($redacted) {
        duo_check_same(true, $decoded['details_redacted'] ?? null, "F3 a sensitive tuple is marked details_redacted [$name]");
        duo_check_same(
            'structured refusal details were redacted',
            $decoded['message'] ?? null,
            "F3 a sensitive tuple's message is replaced, not rewritten [$name]"
        );
        duo_check(!isset($decoded['diagnostics']), "F3 a sensitive tuple drops the whole diagnostics batch [$name]");
        duo_check(
            $agentBytes . "\n" !== $hostBytes,
            "F3 the host envelope has no sensitivity screen and therefore differs from the agent's on a sensitive tuple [$name]"
        );
    } else {
        duo_check_same(
            $agentBytes . "\n",
            $hostBytes,
            "F3 agent and host producers emit identical bytes for a publishable tuple [$name]"
        );
        duo_check_same($message, $decoded['message'] ?? null, "F3 a publishable message survives verbatim [$name]");
        duo_check_same($remediation, $decoded['remediation'] ?? null, "F3 a publishable remediation survives verbatim [$name]");
        if ($diagnostics !== []) {
            duo_check_same($diagnostics, $decoded['diagnostics'] ?? null, "F3 diagnostics survive in order [$name]");
        }
    }

    // AdapterObservation::refusal() is the same envelope with a constant
    // details_redacted tail and its own hard-coded command.
    ob_start();
    CommandOutput::renderRefusalJson('adapter-observe', $reasonCode, $message, $remediation);
    $hostAsObserve = rtrim((string) ob_get_clean(), "\n");
    duo_check_same(
        substr($hostAsObserve, 0, -1) . ',"details_redacted":true}',
        $observationRefusal($reasonCode, $message, $remediation),
        "F3 AdapterObservation::refusal is the host envelope plus a constant details_redacted tail [$name]"
    );
    duo_check_same(
        (string) $tuple['adapter_observe'],
        $observationRefusal($reasonCode, $message, $remediation),
        "F3 the adapter-observe envelope is byte-stable [$name]"
    );
}

// Both producers' unserializable-payload fallbacks. INF survives
// containsSensitivePublicDetail() (it screens strings, arrays and objects),
// then defeats json_encode() even with JSON_INVALID_UTF8_SUBSTITUTE.
$infRefusal = new CommandRefusalException(
    'apply_refused',
    'apply refused before target mutation',
    'resolve the reported findings and rerun plan',
    [['code' => 'unencodable', 'value' => INF]]
);
duo_check_same(
    '{"format":"duo-command-refusal/v1","ok":false,"command":"apply","error":"refusal_serialization_failed",'
        . '"reason_code":"refusal_serialization_failed","message":"structured refusal serialization failed",'
        . '"remediation":"inspect private operator evidence before another attempt","details_redacted":true}',
    $agentRefusal($infRefusal, 'apply'),
    'F3 the agent fallback envelope for an unserializable payload is a constant, secret-free document'
);
ob_start();
CommandOutput::renderRefusalJson('apply', 'apply_refused', 'm', 'r', [['value' => INF]]);
duo_check_same(
    '{"format":"duo-command-refusal/v1","ok":false,"command":"host",'
        . '"error":"refusal_serialization_failed","reason_code":"refusal_serialization_failed",'
        . '"message":"structured refusal serialization failed",'
        . '"remediation":"inspect private operator evidence before another attempt","details_redacted":true}' . "\n",
    (string) ob_get_clean(),
    'F3 the host fallback envelope reports command "host" and is otherwise the same constant document'
);

// ---------------------------------------------------------------------------
// F3 — the cli parsers accept what the agent produces
// ---------------------------------------------------------------------------

/**
 * Replays a recorded agent refusal as a failing `wp duo …` call, which is
 * exactly what StatusCommand/PendingCommand see across the transport: the
 * envelope on STDOUT, transport chatter on STDERR, non-zero exit.
 */
final class ParityRefusalDriver implements EnvironmentDriver {
    public function __construct(private string $envelope) {
    }

    public function name(): string {
        return 'parity-fixture';
    }

    public function driverId(): string {
        return 'parity-fixture';
    }

    public function repoPath(): string {
        return '/fixture/repo';
    }

    public function describe(): string {
        return 'canonical json parity fixture';
    }

    public function captureRaw(string $script): array {
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function captureWp(array $wpArgs): array {
        return [
            'exit' => 1,
            'stdout' => $this->envelope . "\n",
            'stderr' => "Container duo-parity-cli1-run Creating\n",
        ];
    }

    public function streamWp(array $wpArgs): int {
        return 1;
    }

    public function wpInstruction(array $wpArgs): string {
        return implode(' ', $wpArgs);
    }

    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('parity-fixture', 'parity-fixture', $operation, []);
    }
}

// Both parsers write their own operator diagnostic to STDERR before routing
// the envelope. That is the product behaviour under test, not this suite's
// output; it cannot be captured with ob_start() and it does not carry PHP's
// diagnostic framing, so offline_diagnostics_guard.sh is unaffected.
echo "note: the next lines on STDERR are the two parsers' own transport diagnostics\n";

foreach ($refusalVectors['tuples'] as $tuple) {
    $name = (string) $tuple['name'];
    $driver = new ParityRefusalDriver((string) $tuple['agent']);

    $statusSeen = null;
    ob_start();
    $statusExit = StatusCommand::run(
        $driver,
        [],
        static function (string $code, string $message, string $remediation): void {
        },
        static function (array $refusal) use (&$statusSeen): void {
            $statusSeen = $refusal;
        },
        static fn(EnvironmentDriver $d): bool => true
    );
    ob_end_clean();
    duo_check_same(1, $statusExit, "F3 status preserves the agent's refusal exit code [$name]");
    duo_check_same(
        json_decode((string) $tuple['agent'], true),
        $statusSeen,
        "F3 cli/src/Command/StatusCommand.php routes the agent envelope to the refusal renderer, not the stderr fallback [$name]"
    );

    $pendingSeen = null;
    ob_start();
    $pendingResult = PendingCommand::fetch($driver, static function (array $refusal) use (&$pendingSeen): void {
        $pendingSeen = $refusal;
    });
    ob_end_clean();
    duo_check_same(false, $pendingResult['ok'], "F3 pending reports the fetch as failed [$name]");
    duo_check_same(
        json_decode((string) $tuple['agent'], true),
        $pendingSeen,
        "F3 cli/src/Command/PendingCommand.php routes the agent envelope to the refusal renderer [$name]"
    );
}

// The third parser, cli/src/Onboarding/Init.php, applies the identical predicate but
// only inside a transport phase that needs a repository on disk. Pin its
// predicate as source text: all three parsers must keep testing the same
// literal, and none of them may drift to a different format string.
foreach (['Command/StatusCommand', 'Command/PendingCommand', 'Onboarding/Init'] as $parser) {
    duo_check(
        in_array('duo-command-refusal/v1', parity_literals($duoRoot . '/cli/src/' . $parser . '.php'), true),
        "F3 cli/src/$parser.php still tests for the literal duo-command-refusal/v1 (source-text pin)"
    );
}

// ---------------------------------------------------------------------------
// F4 — the plan-row human label
// ---------------------------------------------------------------------------

$planVectors = parity_fixture('plan-summary-vectors');
duo_check_same(
    'duo-parity-plan-summary/v1',
    $planVectors['format'] ?? null,
    'the plan-summary vector fixture declares its versioned format'
);

foreach ($planVectors['label_vectors'] as $index => $row) {
    $reference = \Duo\PlanView::humanLabel((array) $row);
    duo_check_same($reference, $cliPlanViewLabel((array) $row), "F4 cli PlanView::humanLabel matches the agent reference [row $index]");
    duo_check_same($reference, $planSummaryLabel((array) $row), "F4 cli PlanSummary::label matches the agent reference [row $index]");
    duo_check(
        preg_match('/[\x00-\x1F\x7F]/', $reference) !== 1,
        "F4 no C0 or DEL byte survives into a rendered plan row [row $index]"
    );
}

// Divergence 6: the UNFILTERED renderer in agent/src/Command/Cli.php collapses
// whitespace but does not strip C0/DEL, by its own comment. Pinned as the
// absence of the strip literal there and its presence in all three
// oneLine() copies.
duo_check(
    !in_array('/[\x00-\x1F\x7F]/', parity_literals($duoRoot . '/agent/src/Command/Cli.php'), true),
    'F4 agent/src/Command/Cli.php has no C0/DEL strip — its unfiltered plan renderer is deliberately unchanged (source-text pin)'
);
foreach ([
    'agent/src/Review/PlanView.php',
    'cli/src/Plan/PlanView.php',
    'cli/src/Plan/PlanSummary.php',
] as $oneLineOwner) {
    duo_check(
        in_array('/[\x00-\x1F\x7F]/', parity_literals($duoRoot . '/' . $oneLineOwner), true),
        "F4 $oneLineOwner still strips C0/DEL in oneLine() (source-text pin)"
    );
}

// ---------------------------------------------------------------------------
// F5 — the five cli/src/Plan/PlanSummary.php lockstep comments
// ---------------------------------------------------------------------------

$plan = [];
foreach (PlanContract::requiredBuckets() as $bucket) {
    $plan[$bucket] = [];
}
foreach ((array) $planVectors['plan'] as $bucket => $rows) {
    $plan[$bucket] = $rows;
}
$rendered = PlanSummary::render($plan);
duo_check_same($planVectors['expected_lines'], $rendered['lines'], 'F5 PlanSummary::render is byte-stable over the plan fixture');
duo_check_same($planVectors['expected_ok'], $rendered['ok'], 'F5 the plan fixture keeps readiness blocked');
$renderedText = implode("\n", $rendered['lines']);

$agentCli = $duoRoot . '/agent/src/Command/Cli.php';
$planSummary = $duoRoot . '/cli/src/Plan/PlanSummary.php';
$agentLiterals = parity_literals($agentCli);

// Comment 1 (cli/src/Plan/PlanSummary.php:211) — "Verbatim match of
// agent/src/Command/Cli.php's plan() warning for the identical condition".
// Agent fragment: agent/src/Command/Cli.php:1228, WP_CLI::warning(...) under $plan['drift'].
$driftWarning = 'environment drift detected — capture-first workflow recommended';
duo_check(in_array($driftWarning, $agentLiterals, true), 'F5.1 the agent drift warning literal is still in agent/src/Command/Cli.php');
duo_check(in_array($driftWarning, $rendered['lines'], true), 'F5.1 cli/src/Plan/PlanSummary.php emits that exact drift warning line');

// Comment 2 (cli/src/Plan/PlanSummary.php:280) — "Verbatim match of
// agent/src/Command/Cli.php's plan() warning". Agent fragment: agent/src/Command/Cli.php:1234.
$codeMismatchWarning = 'code_mismatch findings — duo apply will refuse until resolved (or run with --force-code-mismatch)';
duo_check(in_array($codeMismatchWarning, $agentLiterals, true), 'F5.2 the agent code_mismatch warning literal is still in agent/src/Command/Cli.php');
duo_check(in_array($codeMismatchWarning, $rendered['lines'], true), 'F5.2 cli/src/Plan/PlanSummary.php emits that exact code_mismatch warning line');

// Comment 3 (cli/src/Plan/PlanSummary.php:388, DUO-3314) — the adapter-disposition
// row. Agent fragment: the WP_CLI::line() call at agent/src/Command/Cli.php:1134-1140.
// The two rows differ only in their leading label — the agent puts the status
// in a 'CAPABILITY_<STATUS>' prefix, the host renders '  - <name> [<status>]'
// — so the lockstep claim is precisely that everything from ' [source=' on is
// the same bytes. Both sides are produced here, neither is transcribed.
$dispositionRow = (array) $planVectors['plan']['adapter_dispositions'][0];
$agentDispositionExpression = parity_wp_cli_line_expression($agentCli, ' [source=');
$agentDispositionLine = parity_eval_row_expression($agentDispositionExpression, $dispositionRow);
$cliDispositionLine = '';
foreach ($rendered['lines'] as $line) {
    if (str_contains($line, ' [source=')) {
        $cliDispositionLine = $line;
    }
}
duo_check(
    $agentDispositionExpression !== '',
    'F5.3 the agent adapter-disposition renderer is still a WP_CLI::line() over a $r row'
);
duo_check_same(
    strstr($agentDispositionLine, ' [source='),
    strstr($cliDispositionLine, ' [source='),
    'F5.3 host and agent adapter-disposition rows are byte-identical from " [source=" onwards'
);
duo_check_same(
    'CAPABILITY_UNSUPPORTED outfitters [source=out_of_tree tier=community certification=registry] '
        . '[not_certified]: no certification evidence for this revision',
    $agentDispositionLine,
    'F5.3 the agent adapter-disposition row is byte-stable'
);
duo_check_same(
    '  - outfitters [unsupported] [source=out_of_tree tier=community certification=registry] '
        . '[not_certified]: no certification evidence for this revision',
    $cliDispositionLine,
    'F5.3 the host adapter-disposition row is byte-stable'
);
duo_check(
    in_array('    remediation: certify the adapter or unpin it', $rendered['lines'], true),
    'F5.3 the adapter-disposition remediation gets its own line (host indents four spaces, the agent two — both are nested one level deeper than their row)'
);

// Comment 4 (cli/src/Plan/PlanSummary.php:406, DUO-3339) — the provider-problem
// row. Agent fragment: the WP_CLI::line() call at agent/src/Command/Cli.php:1153-1158.
$providerRow = (array) $planVectors['plan']['provider_problems'][0];
$agentProviderExpression = parity_wp_cli_line_expression($agentCli, ' [manifest=');
$agentProviderLine = parity_eval_row_expression($agentProviderExpression, $providerRow);
$cliProviderLine = '';
foreach ($rendered['lines'] as $line) {
    if (str_contains($line, ' [manifest=')) {
        $cliProviderLine = $line;
    }
}
duo_check(
    $agentProviderExpression !== '',
    'F5.4 the agent provider-problem renderer is still a WP_CLI::line() over a $r row'
);
duo_check_same(
    strstr($agentProviderLine, ' [manifest='),
    strstr($cliProviderLine, ' [manifest='),
    'F5.4 host and agent provider-problem rows are byte-identical from " [manifest=" onwards'
);
duo_check_same(
    'PROVIDER_PROBLEM woocommerce_regen [manifest=woocommerce plugin=woocommerce/woocommerce.php] '
        . '[provider_version_unsupported]: expected >=9.0.0, found 8.4.0',
    $agentProviderLine,
    'F5.4 the agent provider-problem row is byte-stable'
);
duo_check_same(
    '  - woocommerce_regen [manifest=woocommerce plugin=woocommerce/woocommerce.php] '
        . '[provider_version_unsupported]: expected >=9.0.0, found 8.4.0',
    $cliProviderLine,
    'F5.4 the host provider-problem row is byte-stable'
);

// Comment 5 (cli/src/Plan/PlanSummary.php:598) — the plan LINE renderer. Its
// byte-compatible agent partner is PlanView::humanLabel() (the filtered path
// agent/src/Command/Cli.php:1037 delegates to), which F4 above pins directly; here we
// only pin that PlanSummary's own rows go through that same label.
foreach ((array) $planVectors['plan']['drift'] as $driftRow) {
    duo_check(
        in_array('  - ' . \Duo\PlanView::humanLabel((array) $driftRow), $rendered['lines'], true),
        'F5.5 a rendered plan row is exactly "  - " plus the agent PlanView::humanLabel of that row'
    );
}

// ---------------------------------------------------------------------------
// F6 — Deploy::in_range vs Doctor::in_range
// ---------------------------------------------------------------------------

foreach ([
    ['8.3.0', '8.3', '9.0'],
    ['8.3', '8.3', '9.0'],
    ['8.2.99', '8.3', '9.0'],
    ['9.0.0', '8.3', '9.0'],
    ['8.9.99', '8.3', '9.0'],
    ['8.3.0-dev', '8.3', '9.0'],
    ['8.3.0-RC1', '8.3', '9.0'],
    ['10.0', '9.0', '11.0'],
    ['1.0.0', '1.0.0', '1.0.0'],
    ['', '8.3', '9.0'],
    ['8.03', '8.3', '9.0'],
    ['5.7.44-log', '5.7', '9.0'],
    ['10.11.6-MariaDB', '10.4', '11.0'],
] as $vector) {
    [$installed, $min, $max] = $vector;
    $label = "$installed in [$min, $max)";
    duo_check_same(
        Deploy::in_range($installed, $min, $max),
        $doctorInRange($installed, $min, $max),
        "F6 Doctor::in_range agrees with Deploy::in_range [$label]"
    );
}

// ---------------------------------------------------------------------------
// F7 — the relative-path safety predicate
// ---------------------------------------------------------------------------

foreach ([
    'plugins/woocommerce/woocommerce.php',
    'themes/storefront',
    'mu-plugins/duo-loader.php',
    '',
    '/absolute/path',
    '../escape',
    'plugins/../escape',
    'plugins/./here',
    'plugins//double',
    'plugins/trailing/',
    'plugins\\windows.php',
    "plugins/nul\x00byte.php",
    "plugins/ctl\x01byte.php",
    "plugins/del\x7fbyte.php",
    "plugins/new\nline.php",
    'plugins/🚀/emoji.php',
    '.',
    '..',
    './',
] as $candidate) {
    $label = addcslashes($candidate, "\0..\37\177");
    $reference = PathSafety::safe_relative($candidate);
    duo_check_same(
        $reference,
        $codeCompatibilitySafeRelative($candidate),
        "F7 CodeCompatibility::safe_relative agrees with PathSafety::safe_relative ['$label']"
    );
}

duo_check_same(
    [true, true, false, false, false, false],
    [
        PathSafety::safe_component('woocommerce'),
        PathSafety::safe_component_root('plugins/woocommerce', ['mu-plugins', 'plugins', 'themes']),
        PathSafety::safe_component('woo/commerce'),
        PathSafety::safe_component_root('plugins/woo/commerce', ['mu-plugins', 'plugins', 'themes']),
        PathSafety::safe_component_root('uploads/thing', ['mu-plugins', 'plugins', 'themes']),
        PathSafety::safe_component(''),
    ],
    'F7 the component and component-root predicates keep their established answers'
);
duo_check_same(
    [true, true, true, false],
    [
        PathSafety::reserved_path('mu-plugins/duo'),
        PathSafety::reserved_path('mu-plugins/duo/x.php'),
        PathSafety::reserved_path('mu-plugins/duo-loader.php'),
        PathSafety::reserved_path('mu-plugins/duotone.php'),
    ],
    'F7 reserved_path still matches only the two Duo roots, prefix-safely'
);

// The two open-coded checks that are deliberately NARROWER than PathSafety —
// migrating them without widening the callers would be a behaviour change.
duo_check(
    !PathSafety::safe_relative('a\\b') && !PathSafety::safe_relative("a\x01b"),
    'F7 PathSafety::safe_relative refuses a backslash and a control byte, which cli/src/Refresh/RefreshPlan.php:1082 accepts'
);

duo_check_summary('canonical json parity');
