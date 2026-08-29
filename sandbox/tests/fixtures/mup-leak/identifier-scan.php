<?php
/**
 * The mechanical half of round-3 MUP §5.2: compare one command's HUMAN view
 * against its own `--format=json` document and report every internal
 * identifier the human view leaks.
 *
 * §5.2's rule is one sentence — *a human view may print an internal
 * identifier only when a documented command consumes it* — with an allowlist
 * of exactly three: `wprism explain`'s `<bucket>:<uuid>` selector, the receipt
 * ids `wprism recover --list` prints for `--restore=<id>`, and the `plan_digest`
 * `wprism release` prints for `wprism verify --plan=<digest>`. Everything else
 * (artifact hashes, lease owners, operation ids, session ids) is
 * `--format=json` only.
 *
 * Two independent scans run, because either one alone has a hole a real
 * regression walks through:
 *
 *  1. **The VALUE scan.** Every string in the JSON document that sits at an
 *     internal-identifier key (below) must not appear verbatim in the human
 *     view. This is the scan that catches an identifier whose *shape* happens
 *     to be friendly — a fixture owner spelled `recover-fixture-owner`, a
 *     provider resource named `resource-identity-0001`. A shape-only audit
 *     passes on those and then ships a build that prints
 *     `20260817-120321-9f3c…` to a production operator.
 *
 *  2. **The SHAPE scan.** Every token in the human view matching an
 *     internal-identifier shape must be part of an allowlisted value. This is
 *     the scan that catches an identifier the JSON document does not carry at
 *     all — a renderer that interpolates a lease token into a sentence, or a
 *     diagnostic that quotes an operation id the report never published.
 *
 * ## What counts as an internal-identifier KEY
 *
 * A rule, not a list, so a new field cannot be added below the audit's
 * notice: the leaf key is exactly `owner` or `digest`, or ends in `_owner`,
 * `_digest`, `_hash`, `_sha256` or `_identity`, or is one of the closed
 * orchestrator id names (`operation_id`, `session_id`, `lease_id`,
 * `resource_id`, `mutation_id`, `snapshot_set_id`, `snapshot_session_id`).
 *
 * ## What counts as an internal-identifier SHAPE
 *
 *  - a canonical UUID, the 36-character form `agent/src/Kernel/Uuid.php`
 *    validates and `wprism explain <bucket>:<uuid>` selects with;
 *  - a 64-hex digest, with or without a `sha256:` prefix;
 *  - a bare 32-hex token — `cli/wprism`'s `orchestrator_run_id()` suffix and
 *    `PromotionLease::owner()`'s `direct-<32 hex>` fallback;
 *  - an operation id, `<8 digits>-<6 digits>-<32 hex>`.
 *
 * ## Two shapes deliberately NOT flagged, stated so the silence is not read
 * ## as an oversight
 *
 *  - **A 40-hex git revision.** `wprism release` prints
 *    `releasing code revision <sha>` in full, and that is correct: a git
 *    revision is the operator's own vocabulary, it is what `--from=<ref>`
 *    takes, and every git command they already run consumes it. The 32- and
 *    64-hex patterns are boundary-anchored so a 40-hex run matches neither.
 *  - **An elided digest.** `sha256:a6328e44f7e6…` is a display prefix, not a
 *    value; nothing can be fed it. The scans compare full values only, which
 *    is why eliding is the shipped renderers' correct answer and shows here
 *    as a pass rather than as a special case.
 *
 * usage:
 *   php identifier-scan.php <label> <human-file> <json-file> [--allow-key=<leaf>]...
 *
 * `--allow-key` names a leaf key whose values are the §5.2 allowlist for this
 * command. Its values are exempt from scan 1 and are the only tokens scan 2
 * accepts. Passing none means "this human view may print no internal
 * identifier at all", which is the correct setting for assess, verify and
 * rehearse.
 *
 * Exit 0 clean, 1 any leak, 2 usage.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';

/** The leaf-key rule. @see the docblock above. */
const MUP_LEAK_KEY_RE =
    '/^(owner|digest)$|(_owner|_digest|_hash|_sha256|_identity)$'
    . '|^(operation_id|session_id|lease_id|resource_id|mutation_id|snapshot_set_id|snapshot_session_id)$/';

/**
 * The shape rule, one named pattern per class so a failure says WHICH kind of
 * identifier leaked rather than only that one did.
 *
 * @var array<string,string>
 */
const MUP_LEAK_SHAPE_RE = [
    'UUID' => '/(?<![0-9a-f-])[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(?![0-9a-f-])/',
    'operation id' => '/(?<![0-9-])[0-9]{8}-[0-9]{6}-[0-9a-f]{32}(?![0-9a-f])/',
    '64-hex digest' => '/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/',
    '32-hex token' => '/(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])/',
];

/**
 * Every string value in the document, keyed by its dotted path.
 *
 * @param mixed $node
 * @param array<string,string> $into
 */
function mup_leak_walk(mixed $node, string $path, array &$into): void {
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            mup_leak_walk($value, $path === '' ? (string) $key : $path . '.' . $key, $into);
        }

        return;
    }
    if (is_string($node) && $node !== '') {
        $into[$path] = $node;
    }
}

/**
 * Whether `$value` occurs in the human view anywhere OUTSIDE an occurrence
 * of an allowlisted value.
 *
 * The same rule scan 2 already applies to shapes, applied to values: a token
 * that is *part of* an allowlisted identifier is that identifier being
 * printed, not a second one leaking. The case that needs it is a consumable
 * id that embeds an internal one by construction — `wprism recover --list`'s
 * retained checkpoint id is `promote-<lease owner>`, because that is the
 * file name promote wrote and the name `--restore=<id>` takes; the owner
 * itself is never printed on its own. An occurrence of the owner anywhere
 * else in the page (a sentence that quotes it, a second column) is still a
 * leak, and this returns true for it.
 *
 * @param list<string> $allowedValues
 */
function mup_leak_appears_outside_allowed(string $human, string $value, array $allowedValues): bool {
    $offset = 0;
    while (($at = strpos($human, $value, $offset)) !== false) {
        $covered = false;
        foreach ($allowedValues as $allowedValue) {
            if ($allowedValue === $value || !str_contains($allowedValue, $value)) {
                continue;
            }
            // Every occurrence of the allowlisted value that would contain
            // this occurrence of $value.
            $inner = strpos($allowedValue, $value);
            while ($inner !== false) {
                $start = $at - $inner;
                if ($start >= 0 && substr($human, $start, strlen($allowedValue)) === $allowedValue) {
                    $covered = true;
                    break 2;
                }
                $inner = strpos($allowedValue, $value, $inner + 1);
            }
        }
        if (!$covered) {
            return true;
        }
        $offset = $at + 1;
    }

    return false;
}

/** The leaf of a dotted path: `rows.0.owner` -> `owner`. */
function mup_leak_leaf(string $path): string {
    $parts = explode('.', $path);

    return (string) end($parts);
}

$argvList = $_SERVER['argv'] ?? [];
array_shift($argvList);
$label = array_shift($argvList);
$humanPath = array_shift($argvList);
$jsonPath = array_shift($argvList);
$allowKeys = [];
foreach ($argvList as $arg) {
    if (!is_string($arg) || !str_starts_with($arg, '--allow-key=')) {
        fwrite(STDERR, "identifier-scan: unexpected argument '" . (string) $arg . "'\n");
        exit(2);
    }
    $allowKeys[] = substr($arg, strlen('--allow-key='));
}
if (!is_string($label) || $label === '' || !is_string($humanPath) || !is_string($jsonPath)) {
    fwrite(STDERR, "usage: identifier-scan.php <label> <human-file> <json-file> [--allow-key=<leaf>]...\n");
    exit(2);
}
foreach ([$humanPath, $jsonPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "identifier-scan: missing input '$required'\n");
        exit(2);
    }
}

$human = (string) file_get_contents($humanPath);
$document = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($document)) {
    fwrite(STDERR, "identifier-scan: $jsonPath is not a JSON document\n");
    exit(2);
}

/** @var array<string,string> $values */
$values = [];
mup_leak_walk($document, '', $values);

// The allowlist: every value sitting at an allowed leaf key. These are the
// identifiers a documented command consumes, so they are the only ones the
// human view may carry.
$allowed = [];
foreach ($values as $path => $value) {
    if (in_array(mup_leak_leaf($path), $allowKeys, true)) {
        $allowed[$value] = $path;
    }
}

// ------------------------------------------------------------- 1. the values
$leaked = [];
foreach ($values as $path => $value) {
    $leaf = mup_leak_leaf($path);
    if (preg_match(MUP_LEAK_KEY_RE, $leaf) !== 1) {
        continue;
    }
    if (isset($allowed[$value])) {
        continue;
    }
    // A one- or two-character value cannot be an identifier and would make
    // this a substring lottery; the shipped ones are all >= 8 characters.
    if (strlen($value) < 8) {
        continue;
    }
    if (mup_leak_appears_outside_allowed($human, $value, array_keys($allowed))) {
        $leaked[] = "$path = $value";
    }
}
wprism_check(
    $leaked === [],
    "$label: no internal-identifier field value reaches the human view"
);
foreach ($leaked as $line) {
    wprism_check_detail("leaked verbatim: $line");
}

// ------------------------------------------------------------- 2. the shapes
$shaped = [];
foreach (MUP_LEAK_SHAPE_RE as $kind => $pattern) {
    if (preg_match_all($pattern, $human, $matches) < 1) {
        continue;
    }
    foreach (array_unique($matches[0]) as $token) {
        foreach (array_keys($allowed) as $allowedValue) {
            if (str_contains($allowedValue, (string) $token)) {
                continue 2;
            }
        }
        $shaped[] = "$kind '$token'";
    }
}
wprism_check(
    $shaped === [],
    "$label: the human view carries no internal-identifier shape a documented command does not consume"
);
foreach ($shaped as $line) {
    wprism_check_detail("unconsumed identifier in the human view: $line");
}

// The allowlist has to be REACHED, not merely declared: a suite that allowed
// `plan_digest` against a document that never carried one would prove nothing
// and would keep passing after the field was renamed.
foreach ($allowKeys as $key) {
    $found = false;
    foreach ($values as $path => $value) {
        if (mup_leak_leaf($path) === $key) {
            $found = true;
            break;
        }
    }
    wprism_check($found, "$label: the allowlisted key '$key' exists in this document");
}

wprism_check_summary('mup_leak_identifier_scan');
